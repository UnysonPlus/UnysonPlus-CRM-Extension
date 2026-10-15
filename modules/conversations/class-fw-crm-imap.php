<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * A minimal IMAP client over a socket (PHP's imap extension is gone from PHP 8.4
 * and missing on most hosts): log in, open a folder, list message UIDs above a
 * number, fetch a message whole, mark it read. Nothing else.
 */
class FW_CRM_Imap {

	/** @var resource|null */
	private $sock;

	/** @var int */
	private $tag = 0;

	/** @var string Last error. */
	public $error = '';

	/** @var array The open folder: { exists, uidvalidity, uidnext }. */
	public $box = array();

	/**
	 * @param string $host
	 * @param int    $port
	 * @param string $security ssl | tls (STARTTLS) | none
	 * @param int    $timeout
	 *
	 * @return bool
	 */
	public function connect( $host, $port, $security = 'ssl', $timeout = 15 ) {
		$target = ( 'ssl' === $security ? 'ssl://' : 'tcp://' ) . $host . ':' . (int) $port;
		$ctx    = stream_context_create( array( 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true, 'peer_name' => $host ) ) );
		$errno  = 0;
		$errstr = '';
		$sock   = @stream_socket_client( $target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- reported below.
		if ( ! $sock ) {
			$this->error = sprintf( __( 'Could not reach %1$s: %2$s', 'fw' ), $host . ':' . $port, $errstr ? $errstr : $errno );
			return false;
		}
		stream_set_timeout( $sock, $timeout );
		$this->sock = $sock;
		$greeting   = $this->line();
		if ( 0 !== strpos( (string) $greeting, '* OK' ) && 0 !== strpos( (string) $greeting, '* PREAUTH' ) ) {
			$this->error = __( 'The server did not answer like an IMAP server.', 'fw' );
			return false;
		}
		if ( 'tls' === $security ) {
			list( $ok ) = $this->command( 'STARTTLS' );
			if ( ! $ok || ! @stream_socket_enable_crypto( $this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$this->error = __( 'The server refused a secure (STARTTLS) connection.', 'fw' );
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $user
	 * @param string $pass
	 *
	 * @return bool
	 */
	public function login( $user, $pass ) {
		list( $ok, , $last ) = $this->command( 'LOGIN ' . $this->quote( $user ) . ' ' . $this->quote( $pass ) );
		if ( ! $ok ) {
			$this->error = __( 'The mailbox refused the user name or password.', 'fw' ) . ( $last ? ' (' . trim( preg_replace( '/^\S+\s+\S+\s*/', '', $last ) ) . ')' : '' );
		}
		return $ok;
	}

	/**
	 * @param string $folder
	 *
	 * @return array|false { exists, uidvalidity, uidnext }
	 */
	public function select( $folder = 'INBOX' ) {
		list( $ok, $lines ) = $this->command( 'SELECT ' . $this->quote( $folder ) );
		if ( ! $ok ) {
			$this->error = sprintf( __( 'The folder "%s" was not found.', 'fw' ), $folder );
			return false;
		}
		$out = array( 'exists' => 0, 'uidvalidity' => 0, 'uidnext' => 0 );
		foreach ( $lines as $l ) {
			if ( preg_match( '/^\* (\d+) EXISTS/', $l, $m ) ) {
				$out['exists'] = (int) $m[1];
			} elseif ( preg_match( '/UIDVALIDITY (\d+)/', $l, $m ) ) {
				$out['uidvalidity'] = (int) $m[1];
			} elseif ( preg_match( '/UIDNEXT (\d+)/', $l, $m ) ) {
				$out['uidnext'] = (int) $m[1];
			}
		}
		return $out;
	}

	/**
	 * UIDs of messages after a UID.
	 *
	 * @param int $after
	 *
	 * @return int[]|false
	 */
	public function uids_after( $after ) {
		list( $ok, $lines ) = $this->command( 'UID SEARCH UID ' . ( (int) $after + 1 ) . ':*' );
		if ( ! $ok ) {
			$this->error = __( 'The mailbox could not be searched.', 'fw' );
			return false;
		}
		$uids = array();
		foreach ( $lines as $l ) {
			if ( 0 === strpos( $l, '* SEARCH' ) ) {
				foreach ( preg_split( '/\s+/', trim( substr( $l, 8 ) ) ) as $u ) {
					if ( ctype_digit( $u ) && (int) $u > (int) $after ) {
						$uids[] = (int) $u; // "n:*" always returns the newest, even when older.
					}
				}
			}
		}
		sort( $uids );
		return $uids;
	}

	/**
	 * The whole message, without marking it read.
	 *
	 * @param int $uid
	 *
	 * @return string|false
	 */
	public function fetch( $uid ) {
		$tag = $this->send( 'UID FETCH ' . (int) $uid . ' BODY.PEEK[]' );
		$raw = false;
		while ( true ) {
			$line = $this->line();
			if ( false === $line ) {
				$this->error = __( 'The connection closed while reading a message.', 'fw' );
				return false;
			}
			if ( preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
				$raw = $this->read_bytes( (int) $m[1] );
				continue;
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				return 0 === strpos( $line, $tag . ' OK' ) ? $raw : false;
			}
		}
	}

	/**
	 * @param int $uid
	 *
	 * @return bool
	 */
	public function mark_read( $uid ) {
		list( $ok ) = $this->command( 'UID STORE ' . (int) $uid . ' +FLAGS (\\Seen)' );
		return $ok;
	}

	public function logout() {
		if ( $this->sock ) {
			$this->send( 'LOGOUT' );
			fclose( $this->sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$this->sock = null;
		}
	}

	/* ---------------------------------------------------------------------- */

	/**
	 * Send a command and read until its tagged answer.
	 *
	 * @param string $cmd
	 *
	 * @return array [ ok, untagged lines, the tagged line ]
	 */
	private function command( $cmd ) {
		$tag   = $this->send( $cmd );
		$lines = array();
		while ( true ) {
			$line = $this->line();
			if ( false === $line ) {
				return array( false, $lines, '' );
			}
			if ( 0 === strpos( $line, $tag . ' ' ) ) {
				return array( 0 === strpos( $line, $tag . ' OK' ), $lines, $line );
			}
			if ( preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
				$this->read_bytes( (int) $m[1] );
			}
			$lines[] = $line;
		}
	}

	/**
	 * @param string $cmd
	 *
	 * @return string The tag.
	 */
	private function send( $cmd ) {
		$tag = 'A' . str_pad( (string) ++$this->tag, 4, '0', STR_PAD_LEFT );
		if ( ! $this->sock ) {
			return $tag;
		}
		fwrite( $this->sock, $tag . ' ' . $cmd . "\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $tag;
	}

	/** @return string|false A line without its CRLF. */
	private function line() {
		if ( ! $this->sock ) {
			return false;
		}
		$line = fgets( $this->sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return false === $line ? false : rtrim( $line, "\r\n" );
	}

	/**
	 * @param int $n
	 *
	 * @return string
	 */
	private function read_bytes( $n ) {
		$out = '';
		while ( strlen( $out ) < $n && ! feof( $this->sock ) ) {
			$chunk = fread( $this->sock, min( 65536, $n - strlen( $out ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$out .= $chunk;
		}
		return $out;
	}

	/**
	 * An IMAP quoted string.
	 *
	 * @param string $s
	 *
	 * @return string
	 */
	private function quote( $s ) {
		return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $s ) . '"';
	}
}
