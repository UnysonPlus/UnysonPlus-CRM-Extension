<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A small MIME reader for incoming email: headers (unfolded, encoded words decoded),
 * the text and HTML bodies of a multipart message (base64 and quoted-printable,
 * converted to UTF-8), and the reply without the quoted history below it.
 *
 * Enough for logging replies on a timeline; attachments are counted, not kept.
 */
class FW_CRM_Mime {

	/**
	 * Parse a raw RFC 822 message.
	 *
	 * @param string $raw
	 *
	 * @return array { from_email, from_name, to[], cc[], subject, text, html, message_id, in_reply_to, references[], date, attachments }
	 */
	public static function parse( $raw ) {
		$raw = str_replace( array( "\r\n", "\r" ), "\n", (string) $raw );
		list( $head, $body ) = self::split( $raw );
		$headers = self::headers( $head );
		$parts   = array( 'text' => '', 'html' => '', 'attachments' => 0, 'reports' => array() );
		self::walk( $headers, $body, $parts, 0 );
		$ctype   = self::param_header( $headers['content-type'] ?? '' );

		$from = self::addresses( $headers['from'] ?? '' );
		return array(
			'from_email'  => $from ? $from[0]['email'] : '',
			'from_name'   => $from ? $from[0]['name'] : '',
			'to'          => wp_list_pluck( self::addresses( $headers['to'] ?? '' ), 'email' ),
			'cc'          => wp_list_pluck( self::addresses( $headers['cc'] ?? '' ), 'email' ),
			'subject'     => self::decode_header( $headers['subject'] ?? '' ),
			'text'        => $parts['text'],
			'html'        => $parts['html'],
			'message_id'  => self::id( $headers['message-id'] ?? '' ),
			'in_reply_to' => self::id( $headers['in-reply-to'] ?? '' ),
			'references'  => self::ids( $headers['references'] ?? '' ),
			'date'        => isset( $headers['date'] ) ? (string) $headers['date'] : '',
			'attachments' => $parts['attachments'],
			// Bounce and complaint reports: report-type of a multipart/report, and the
			// machine-readable parts (message/delivery-status, message/feedback-report,
			// text/rfc822-headers or the headers of an attached message/rfc822).
			'report_type' => strtolower( (string) ( $ctype['params']['report-type'] ?? '' ) ),
			'reports'     => $parts['reports'],
			'auto'        => strtolower( (string) ( $headers['auto-submitted'] ?? '' ) ),
		);
	}

	/**
	 * @param string $raw
	 *
	 * @return array [ head, body ]
	 */
	private static function split( $raw ) {
		if ( 0 === strpos( $raw, "\n" ) ) {
			return array( '', substr( $raw, 1 ) ); // A part without headers.
		}
		$pos = strpos( $raw, "\n\n" );
		return false === $pos ? array( $raw, '' ) : array( substr( $raw, 0, $pos ), substr( $raw, $pos + 2 ) );
	}

	/**
	 * Unfolded headers, lower-case names (the first of repeated ones).
	 *
	 * @param string $head
	 *
	 * @return array
	 */
	public static function headers( $head ) {
		$out  = array();
		$head = preg_replace( "/\n[ \t]+/", ' ', (string) $head );
		foreach ( explode( "\n", $head ) as $line ) {
			$colon = strpos( $line, ':' );
			if ( false === $colon ) {
				continue;
			}
			$name = strtolower( trim( substr( $line, 0, $colon ) ) );
			if ( ! isset( $out[ $name ] ) ) {
				$out[ $name ] = trim( substr( $line, $colon + 1 ) );
			}
		}
		return $out;
	}

	/**
	 * Walk a (possibly multipart) entity collecting the first text and HTML parts.
	 *
	 * @param array  $headers
	 * @param string $body
	 * @param array  $parts
	 * @param int    $depth
	 */
	private static function walk( array $headers, $body, array &$parts, $depth ) {
		if ( $depth > 8 ) {
			return;
		}
		$type     = self::param_header( $headers['content-type'] ?? 'text/plain' );
		$mime     = strtolower( $type['value'] );
		$disposed = self::param_header( $headers['content-disposition'] ?? '' );

		if ( 0 === strpos( $mime, 'multipart/' ) && ! empty( $type['params']['boundary'] ) ) {
			$boundary = '--' . $type['params']['boundary'];
			$chunks   = explode( "\n" . $boundary, "\n" . $body );
			array_shift( $chunks ); // The preamble.
			foreach ( $chunks as $chunk ) {
				if ( 0 === strpos( $chunk, '--' ) ) {
					break; // The closing boundary.
				}
				// Drop the rest of the boundary line (trailing spaces, the newline).
				$nl = strpos( $chunk, "\n" );
				if ( false === $nl ) {
					continue;
				}
				list( $h, $b ) = self::split( substr( $chunk, $nl + 1 ) );
				self::walk( self::headers( $h ), $b, $parts, $depth + 1 );
			}
			return;
		}
		if ( in_array( $mime, array( 'message/delivery-status', 'message/feedback-report', 'text/rfc822-headers', 'message/rfc822', 'message/rfc822-headers' ), true ) ) {
			if ( ! isset( $parts['reports'][ $mime ] ) ) {
				$decoded = self::decode_body( $body, $headers['content-transfer-encoding'] ?? '', '' );
				// An attached message: only its headers matter (the original Message-ID).
				$parts['reports'][ $mime ] = 'message/rfc822' === $mime ? self::split( str_replace( array( "\r\n", "\r" ), "\n", ltrim( $decoded ) ) )[0] : $decoded;
			}
			return;
		}
		if ( 'attachment' === strtolower( $disposed['value'] ) || 0 !== strpos( $mime, 'text/' ) ) {
			$parts['attachments']++;
			return;
		}
		$text = self::decode_body( $body, $headers['content-transfer-encoding'] ?? '', $type['params']['charset'] ?? '' );
		if ( 'text/html' === $mime && '' === $parts['html'] ) {
			$parts['html'] = $text;
		} elseif ( 'text/plain' === $mime && '' === $parts['text'] ) {
			$parts['text'] = $text;
		}
	}

	/**
	 * @param string $body
	 * @param string $encoding
	 * @param string $charset
	 *
	 * @return string UTF-8
	 */
	public static function decode_body( $body, $encoding, $charset ) {
		switch ( strtolower( trim( (string) $encoding ) ) ) {
			case 'base64':
				$body = (string) base64_decode( preg_replace( '/\s+/', '', $body ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				break;
			case 'quoted-printable':
				$body = quoted_printable_decode( str_replace( "\n", "\r\n", $body ) );
				$body = str_replace( "\r\n", "\n", $body );
				break;
		}
		return self::to_utf8( $body, $charset );
	}

	/**
	 * @param string $s
	 * @param string $charset
	 *
	 * @return string
	 */
	private static function to_utf8( $s, $charset ) {
		$charset = strtoupper( trim( (string) $charset, " \"'" ) );
		if ( '' === $charset || 'UTF-8' === $charset || 'US-ASCII' === $charset ) {
			return function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $s, 'UTF-8' ) && function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $s, 'UTF-8', 'ISO-8859-1' ) : $s;
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$out = @mb_convert_encoding( $s, 'UTF-8', $charset ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unknown charset falls back below.
			if ( false !== $out && '' !== $out ) {
				return $out;
			}
		}
		$out = function_exists( 'iconv' ) ? @iconv( $charset, 'UTF-8//IGNORE', $s ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== $out ? $out : $s;
	}

	/**
	 * "=?UTF-8?B?...?=" words → UTF-8 text.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function decode_header( $value ) {
		$value = (string) $value;
		if ( false === strpos( $value, '=?' ) ) {
			return trim( $value );
		}
		// Whitespace between two encoded words is not part of the text.
		$value = preg_replace( '/\?=\s+=\?/', '?==?', $value );
		return trim( preg_replace_callback( '/=\?([^?]+)\?([BbQq])\?([^?]*)\?=/', function ( $m ) {
			$text = 'B' === strtoupper( $m[2] ) ? (string) base64_decode( $m[3] ) : quoted_printable_decode( str_replace( '_', ' ', $m[3] ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			return self::to_utf8( $text, $m[1] );
		}, $value ) );
	}

	/**
	 * "a; b=c; d="e"" → { value, params }.
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	private static function param_header( $value ) {
		$bits   = explode( ';', (string) $value );
		$out    = array( 'value' => trim( array_shift( $bits ) ), 'params' => array() );
		preg_match_all( '/([\w*-]+)\s*=\s*("([^"]*)"|[^;\s]+)/', (string) $value, $m, PREG_SET_ORDER );
		foreach ( $m as $p ) {
			$out['params'][ strtolower( $p[1] ) ] = isset( $p[3] ) && '' !== $p[3] ? $p[3] : trim( $p[2], '"' );
		}
		return $out;
	}

	/**
	 * "Name <a@b.c>, d@e.f" → [ { name, email } ].
	 *
	 * @param string $value
	 *
	 * @return array
	 */
	public static function addresses( $value ) {
		$out   = array();
		$value = self::decode_header( $value );
		// Split on commas outside quotes and angle brackets.
		$items = preg_split( '/,(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<]*>)/', $value );
		foreach ( (array) $items as $item ) {
			$item = trim( $item );
			if ( '' === $item ) {
				continue;
			}
			if ( preg_match( '/^(.*)<([^>]+)>\s*$/', $item, $m ) ) {
				$email = sanitize_email( $m[2] );
				$name  = trim( $m[1], " \t\"'" );
			} else {
				$email = sanitize_email( $item );
				$name  = '';
			}
			if ( is_email( $email ) ) {
				$out[] = array( 'name' => $name, 'email' => strtolower( $email ) );
			}
		}
		return $out;
	}

	/**
	 * @param string $value "<abc@host>"
	 *
	 * @return string abc@host
	 */
	private static function id( $value ) {
		return preg_match( '/<([^>]+)>/', (string) $value, $m ) ? trim( $m[1] ) : trim( (string) $value, " <>\t" );
	}

	/**
	 * @param string $value
	 *
	 * @return string[]
	 */
	private static function ids( $value ) {
		preg_match_all( '/<([^>]+)>/', (string) $value, $m );
		return array_values( array_unique( $m[1] ) );
	}

	/**
	 * The new part of a reply: the text above the quoted history ("On … wrote:",
	 * "-----Original Message-----", lines starting with ">") and above a signature
	 * marker ("-- ").
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public static function reply_part( $text ) {
		$lines = explode( "\n", str_replace( array( "\r\n", "\r" ), "\n", (string) $text ) );
		$out   = array();
		foreach ( $lines as $i => $line ) {
			$t = rtrim( $line );
			if ( '-- ' === $line || '--' === $t ) {
				break;
			}
			if ( preg_match( '/^-{2,}\s*(Original Message|Forwarded message)/i', trim( $t ) ) || preg_match( '/^_{5,}$/', trim( $t ) ) ) {
				break;
			}
			// "On Tue, 3 Nov 2026 at 10:12, Name <a@b.c> wrote:" (may wrap onto the next line).
			$joined = $t . ( isset( $lines[ $i + 1 ] ) ? ' ' . trim( $lines[ $i + 1 ] ) : '' );
			if ( preg_match( '/^On\b.+wrote:\s*$/i', $t ) || ( preg_match( '/^On\b/i', $t ) && preg_match( '/wrote:\s*$/i', $joined ) ) ) {
				break;
			}
			if ( preg_match( '/^(From|Von|De|Da):\s.+/i', $t ) && isset( $lines[ $i + 1 ] ) && preg_match( '/^(Sent|Date|Gesendet|Envoy|Inviato):/i', trim( $lines[ $i + 1 ] ) ) ) {
				break;
			}
			if ( 0 === strpos( ltrim( $t ), '>' ) ) {
				continue;
			}
			$out[] = $t;
		}
		return trim( implode( "\n", $out ) );
	}

	/**
	 * Readable text from HTML.
	 *
	 * @param string $html
	 *
	 * @return string
	 */
	public static function html_to_text( $html ) {
		$html = preg_replace( '#<(style|script|head)[^>]*>.*?</\1>#is', '', (string) $html );
		$html = preg_replace( '#<blockquote[^>]*>.*?</blockquote>#is', '', $html ); // Quoted history.
		$html = preg_replace( '#<br\s*/?>|</p>|</div>|</li>|</tr>|</h[1-6]>#i', "\n", $html );
		$text = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' );
		return trim( preg_replace( "/\n{3,}/", "\n\n", preg_replace( "/[ \t]+\n/", "\n", $text ) ) );
	}
}
