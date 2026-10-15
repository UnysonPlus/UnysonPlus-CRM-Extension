<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Compiles an email-builder block tree into sendable HTML.
 *
 * This class is the whole reason an email builder is not just a page builder
 * with different blocks. Its job is not "render nicely" — it is "emit the least
 * surprising markup that survives the worst client in the matrix":
 *
 *  - Outlook (Windows) renders with the WORD engine: no reliable flexbox, grid
 *    or float, and divs expand to 100%. Layout is therefore nested tables with
 *    role="presentation".
 *  - Gmail strips <style> in clipped and forwarded views, so all styling is
 *    INLINE. The <style> block we emit carries progressive enhancement only
 *    (mobile stacking) and is never load-bearing.
 *  - Gmail clips the message body over ~102 KB, so output size is a real budget
 *    — see estimate_size() and the admin meter.
 *  - Images are blocked by default in many clients, which is why the Image block
 *    insists on alt text.
 *
 * The compiler owns global style resolution: block value → campaign default →
 * built-in. That priority order is MJML's, and it exists so changing the brand
 * font is one edit rather than forty.
 */
class FW_CRM_Email_Compiler {

	/** Gmail clips beyond roughly this many bytes. */
	const CLIP_LIMIT = 102400;

	/**
	 * Built-in global styles. A campaign may override any of these; a block may
	 * override some of them again.
	 *
	 * @return array
	 */
	public static function defaults() {
		/** Filters the built-in global email styles (width, colors, font, line height) used as the compile context. */
		return apply_filters( 'unysonplus_crm_email_defaults', array(
			'content_width' => 600,
			'background'    => '#f6f7f7',
			'canvas'        => '#ffffff',
			'font_family'   => "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif",
			'font_size'     => 16,
			'line_height'   => '1.6',
			'text_color'    => '#1d2327',
			'link_color'    => '#2271b1',
		) );
	}

	/**
	 * @param array $overrides
	 *
	 * @return array
	 */
	public static function context( array $overrides = array() ) {
		return array_merge( self::defaults(), array_filter( $overrides, function ( $v ) {
			return '' !== $v && null !== $v;
		} ) );
	}

	/**
	 * Compile a block tree to a complete email document.
	 *
	 * @param array|string $blocks Builder JSON (decoded array, or the raw string).
	 * @param array        $ctx    Global style overrides.
	 *
	 * @return string
	 */
	public static function compile( $blocks, array $ctx = array() ) {
		return self::document( self::compile_rows( $blocks, $ctx ), self::context( $ctx ) );
	}

	/**
	 * Compile just the block rows, without the document shell. Useful for
	 * previews and for tests that assert on one block at a time.
	 *
	 * @param array|string $blocks
	 * @param array        $ctx
	 *
	 * @return string
	 */
	public static function compile_rows( $blocks, array $ctx = array() ) {
		$ctx    = self::context( $ctx );
		$blocks = self::normalize( $blocks );
		$types  = self::item_types();
		$out    = '';

		foreach ( self::pack_rows( $blocks, $types ) as $row ) {
			$compiled = array();

			foreach ( $row as $entry ) {
				$block = $entry['block'];
				// render(), not compile(): it stashes the block's values so shared
					// helpers (wrap_block) can reach them, then clears them.
					$html  = $types[ $block['type'] ]->render( self::block_atts( $block ), $ctx );

				/**
				 * Filters the HTML of a single compiled email block during Email Builder document compilation.
				 *
				 * Filter one compiled block.
				 *
				 * @param string $html
				 * @param array  $block
				 * @param array  $ctx
				 */
				$html = (string) apply_filters( 'unysonplus_crm_email_block', $html, $block, $ctx );

				if ( '' === $html ) {
					continue;
				}

				$compiled[] = array( 'html' => $html, 'fraction' => $entry['fraction'] );
			}

			if ( ! $compiled ) {
				continue;
			}

			$out .= 1 === count( $compiled ) && 1.0 === (float) $compiled[0]['fraction']
				? '<tr><td>' . $compiled[0]['html'] . '</td></tr>'
				: self::columns_row( $compiled, $ctx );
		}

		return $out;
	}

	/**
	 * The width vocabulary offered in the builder, as id => fraction of the row.
	 *
	 * Deliberately coarse. The page builder's twelfths are far too granular for a
	 * 600px email — a 1/12 column is 50px, which cannot hold anything.
	 *
	 * @return array
	 */
	public static function widths() {
		/** Filters the coarse column-width vocabulary (id to row fraction) the email builder offers. */
		return apply_filters( 'unysonplus_crm_email_widths', array(
			'1_1' => 1,
			'1_2' => 1 / 2,
			'1_3' => 1 / 3,
			'2_3' => 2 / 3,
			'1_4' => 1 / 4,
			'3_4' => 3 / 4,
		) );
	}

	/**
	 * A block's width as a fraction of the row, defaulting to full width.
	 *
	 * @param array $block
	 *
	 * @return float
	 */
	public static function block_fraction( array $block ) {
		$widths = self::widths();
		$id     = isset( $block['width'] ) ? (string) $block['width'] : '1_1';

		return isset( $widths[ $id ] ) ? (float) $widths[ $id ] : 1.0;
	}

	/**
	 * Group consecutive blocks into rows.
	 *
	 * A block is added to the current row while the widths still fit; the moment
	 * one would overflow, the row is flushed and a new one begins. That is what
	 * turns a flat list of blocks with widths into columns, without needing a
	 * nested container type in the canvas.
	 *
	 * @param array $blocks
	 * @param array $types Registered block instances, for validity checks.
	 *
	 * @return array Rows, each a list of [ 'block' => array, 'fraction' => float ].
	 */
	public static function pack_rows( array $blocks, array $types ) {
		$rows    = array();
		$current = array();
		$filled  = 0.0;

		foreach ( $blocks as $block ) {
			if ( empty( $block['type'] ) || ! isset( $types[ $block['type'] ] ) ) {
				// Unknown block — most likely saved by a newer version. Skipping is
				// the only safe option; guessing would email somebody garbage.
				continue;
			}

			$fraction = self::block_fraction( $block );

			// A full-width block always stands alone.
			if ( 1.0 === $fraction ) {
				if ( $current ) {
					$rows[]  = $current;
					$current = array();
					$filled  = 0.0;
				}

				$rows[] = array( array( 'block' => $block, 'fraction' => 1.0 ) );
				continue;
			}

			// Float tolerance: three 1/3 columns sum to 0.999…, which must still fit.
			if ( $current && ( $filled + $fraction ) > 1.0001 ) {
				$rows[]  = $current;
				$current = array();
				$filled  = 0.0;
			}

			$current[] = array( 'block' => $block, 'fraction' => $fraction );
			$filled   += $fraction;
		}

		if ( $current ) {
			$rows[] = $current;
		}

		return $rows;
	}

	/**
	 * Render a multi-column row.
	 *
	 * The hybrid pattern every mature email builder uses, and the only one that
	 * works across the matrix:
	 *
	 *  - An MSO ghost table with a REAL <td> per column, because Outlook renders
	 *    with the Word engine and understands nothing else.
	 *  - An inline-block <div> per column for every other client. Yes, a div — this
	 *    is the one place email needs them, and MJML's own output does the same.
	 *    What stays forbidden is flex, grid and float.
	 *  - A class the <style> block flips to width:100% on small screens, so
	 *    columns stack on mobile. Outlook ignores media queries and therefore will
	 *    not stack — which is fine, since the Word engine is desktop-only.
	 *  - font-size:0 on the container: HTML whitespace BETWEEN inline-block
	 *    elements renders as a visible gap and would break the column maths.
	 *
	 * @param array $columns [ [ 'html' => string, 'fraction' => float ], … ]
	 * @param array $ctx
	 *
	 * @return string
	 */
	public static function columns_row( array $columns, array $ctx ) {
		$total = (int) $ctx['content_width'];
		$ghost = '<!--[if mso]><table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><![endif]-->';
		$out   = '';

		foreach ( $columns as $column ) {
			$percent = round( $column['fraction'] * 100, 4 );
			$px      = (int) floor( $total * $column['fraction'] );

			$out .= '<!--[if mso]><td width="' . $px . '" valign="top" style="padding:0"><![endif]-->'
				. '<div class="fw-crm-col" style="'
				. esc_attr( self::inline( array(
					'display'        => 'inline-block',
					'width'          => '100%',
					'max-width'      => $percent . '%',
					'vertical-align' => 'top',
					// Reset the container's font-size:0 for real content.
					'font-size'      => $ctx['font_size'] . 'px',
					'line-height'    => $ctx['line_height'],
				) ) ) . '">'
				. $column['html']
				. '</div>'
				. '<!--[if mso]></td><![endif]-->';
		}

		$ghost_close = '<!--[if mso]></tr></table><![endif]-->';

		return '<tr><td style="' . esc_attr( self::inline( array(
			'font-size'   => '0',
			'line-height' => '0',
			// Inline-block columns are laid out as text, so the cell must not
			// justify or centre them.
			'text-align'  => 'left',
		) ) ) . '">' . $ghost . $out . $ghost_close . '</td></tr>';
	}

	/**
	 * The registered email-builder blocks, keyed by type.
	 *
	 * @return FW_CRM_Email_Item[]
	 */
	public static function item_types() {
		$builder = fw()->backend->option_type( 'email-builder' );

		if ( ! $builder instanceof FW_Option_Type_Email_Builder ) {
			return array();
		}

		return $builder->get_blocks();
	}

	/**
	 * Accept either a decoded array or a JSON string, and tolerate the builder's
	 * own storage shape (a list of items with `type` and `atts`).
	 *
	 * @param array|string $blocks
	 *
	 * @return array
	 */
	public static function normalize( $blocks ) {
		// The builder option type stores its value as array( 'json' => '<string>' ),
		// so unwrap that first — otherwise a saved tree looks like one anonymous
		// block and the canvas comes back empty.
		if ( is_array( $blocks ) && isset( $blocks['json'] ) ) {
			$blocks = $blocks['json'];
		}

		if ( is_string( $blocks ) ) {
			$decoded = json_decode( $blocks, true );
			$blocks  = is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $blocks ) ? $blocks : array();
	}

	/**
	 * A block's saved values, whichever shape they arrived in.
	 *
	 * @param array $block
	 *
	 * @return array
	 */
	public static function block_atts( array $block ) {
		if ( isset( $block['options'] ) && is_array( $block['options'] ) ) {
			return $block['options'];
		}

		if ( isset( $block['atts'] ) && is_array( $block['atts'] ) ) {
			return $block['atts'];
		}

		return array();
	}

	/**
	 * Wrap a block tree back into the option type's storage shape, for rendering
	 * the builder with a saved value.
	 *
	 * @param array $blocks
	 *
	 * @return array
	 */
	public static function to_option_value( array $blocks ) {
		$out = array();

		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) || empty( $block['type'] ) ) {
				continue;
			}

			// The canvas only reads `options`. A tree hand-authored (or imported)
			// with `atts` compiles fine but would otherwise load as a row of
			// empty blocks, so normalise on the way in rather than making every
			// producer remember which key to use.
			$block['options'] = self::block_atts( $block );
			unset( $block['atts'] );

			$out[] = $block;
		}

		return array( 'json' => wp_json_encode( $out ) );
	}

	/**
	 * Wrap compiled rows in the email document shell.
	 *
	 * @param string $rows
	 * @param array  $ctx
	 *
	 * @return string
	 */
	public static function document( $rows, array $ctx ) {
		$width = (int) $ctx['content_width'];

		$body_style = self::inline( array(
			'margin'                  => '0',
			'padding'                 => '0',
			'width'                   => '100%',
			'background-color'        => $ctx['background'],
			// Stop iOS/Windows clients auto-inflating small text.
			'-webkit-text-size-adjust' => '100%',
			'-ms-text-size-adjust'    => '100%',
		) );

		$canvas_style = self::inline( array(
			'width'            => $width . 'px',
			'max-width'        => '100%',
			'background-color' => $ctx['canvas'],
			'border-collapse'  => 'collapse',
		) );

		// Outlook ignores max-width, so a "ghost table" in an MSO conditional
		// pins the width for it while every other client uses the fluid table.
		$ghost_open = '<!--[if mso]><table role="presentation" width="' . $width . '" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->';
		$ghost_close = '<!--[if mso]></td></tr></table><![endif]-->';

		$html = '<!doctype html>'
			. '<html xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
			. '<head>'
			. '<meta charset="utf-8" />'
			. '<meta name="viewport" content="width=device-width,initial-scale=1" />'
			. '<meta http-equiv="X-UA-Compatible" content="IE=edge" />'
			// Tells supporting clients we have considered dark mode rather than
			// leaving them to invert everything themselves.
			. '<meta name="color-scheme" content="light" />'
			. '<meta name="supported-color-schemes" content="light" />'
			. '<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->'
			. '<style type="text/css">'
			// Progressive enhancement ONLY — Gmail may strip this entirely.
			. 'a{color:' . esc_attr( $ctx['link_color'] ) . '}'
			. 'img{border:0;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic}'
			. 'table{border-collapse:collapse}'
			. '@media only screen and (max-width:620px){'
			. '.fw-crm-canvas{width:100%!important}'
			// Stack columns on narrow screens. Enhancement only: Outlook ignores
			// media queries entirely and keeps the ghost-table columns side by
			// side, which is correct — the Word engine is desktop.
			. '.fw-crm-col{width:100%!important;max-width:100%!important;display:block!important}'
			. '}'
			. '</style>'
			. '</head>'
			. '<body style="' . esc_attr( $body_style ) . '">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="' . esc_attr( self::inline( array(
				'width'            => '100%',
				'background-color' => $ctx['background'],
				'border-collapse'  => 'collapse',
			) ) ) . '">'
			. '<tr><td align="center" style="padding:24px 12px">'
			. $ghost_open
			. '<table role="presentation" class="fw-crm-canvas" width="' . $width . '" cellpadding="0" cellspacing="0" border="0"'
			. ' style="' . esc_attr( $canvas_style ) . '">'
			. $rows
			. '</table>'
			. $ghost_close
			. '</td></tr>'
			. '</table>'
			. '</body></html>';

		/**
		 * Filters the complete compiled email HTML document, with access to the row markup and style context.
		 *
		 * Filter the whole compiled document.
		 *
		 * @param string $html
		 * @param string $rows
		 * @param array  $ctx
		 */
		return apply_filters( 'unysonplus_crm_email_document', $html, $rows, $ctx );
	}

	/**
	 * @param array $rules
	 *
	 * @return string
	 */
	private static function inline( array $rules ) {
		$out = array();

		foreach ( $rules as $property => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$out[] = $property . ':' . $value;
		}

		return implode( ';', $out );
	}

	/* ---------------------------------------------------------------------- *
	 * Output budget + plain text
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $html
	 *
	 * @return array bytes, limit, percent, clipped
	 */
	public static function estimate_size( $html ) {
		$bytes = strlen( (string) $html );

		return array(
			'bytes'   => $bytes,
			'limit'   => self::CLIP_LIMIT,
			'percent' => (int) round( ( $bytes / self::CLIP_LIMIT ) * 100 ),
			'clipped' => $bytes > self::CLIP_LIMIT,
		);
	}

	/**
	 * A readable plain-text rendering of the block tree, for the text part of a
	 * multipart message. Built from the BLOCKS, not by stripping tags off the
	 * compiled HTML — the table scaffolding would turn into noise.
	 *
	 * @param array|string $blocks
	 *
	 * @return string
	 */
	public static function to_plain_text( $blocks ) {
		$blocks = self::normalize( $blocks );
		$out    = array();

		foreach ( $blocks as $block ) {
			$type = isset( $block['type'] ) ? $block['type'] : '';
			$atts = self::block_atts( $block );

			switch ( $type ) {
				case 'text':
				case 'html':
					$raw  = isset( $atts['content'] ) ? $atts['content'] : ( isset( $atts['html'] ) ? $atts['html'] : '' );
					$text = wp_strip_all_tags( str_replace( array( '</p>', '<br>', '<br/>', '<br />' ), "\n", $raw ) );
					$text = trim( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );

					if ( '' !== $text ) {
						$out[] = $text;
					}
					break;

				case 'heading':
					$text = isset( $atts['text'] ) ? trim( (string) $atts['text'] ) : '';

					if ( '' !== $text ) {
						// Underlined, so headings still read as headings in plain text.
						$out[] = $text . "\n" . str_repeat( '=', min( 60, max( 3, strlen( $text ) ) ) );
					}
					break;

				case 'button':
					$label = isset( $atts['label'] ) ? trim( (string) $atts['label'] ) : '';
					$url   = isset( $atts['url'] ) ? trim( (string) $atts['url'] ) : '';

					if ( '' !== $label ) {
						$out[] = '' !== $url ? $label . ': ' . $url : $label;
					}
					break;

				case 'image':
					$alt = isset( $atts['alt'] ) ? trim( (string) $atts['alt'] ) : '';

					if ( '' !== $alt ) {
						$out[] = '[' . $alt . ']';
					}
					break;

				case 'logo':
					// Mirror the block: its own text if set, the site name otherwise.
					$label = isset( $atts['text'] ) && '' !== trim( (string) $atts['text'] )
						? trim( wp_strip_all_tags( (string) $atts['text'] ) )
						: FW_CRM_Mail::site_name();

					$out[] = '[' . $label . ']';
					break;

				case 'video':
					$caption = isset( $atts['caption'] ) ? trim( (string) $atts['caption'] ) : '';
					$url     = isset( $atts['url'] ) ? trim( (string) $atts['url'] ) : '';

					if ( '' !== $url ) {
						$out[] = ( '' !== $caption ? $caption : __( 'Watch the video', 'fw' ) ) . ': ' . $url;
					}
					break;

				case 'hero':
					$bits = array();

					foreach ( array( 'heading', 'text' ) as $key ) {
						if ( ! empty( $atts[ $key ] ) ) {
							$bits[] = trim( (string) $atts[ $key ] );
						}
					}

					if ( ! empty( $atts['button_label'] ) ) {
						$bits[] = trim( (string) $atts['button_label'] )
							. ( ! empty( $atts['button_url'] ) ? ': ' . trim( (string) $atts['button_url'] ) : '' );
					}

					if ( $bits ) {
						$out[] = implode( "\n", $bits );
					}
					break;

				case 'menu':
				case 'social':
					$key   = 'menu' === $type ? 'items' : 'links';
					$links = isset( $atts[ $key ] ) && is_array( $atts[ $key ] ) ? $atts[ $key ] : array();
					$bits  = array();

					foreach ( $links as $link ) {
						$label = isset( $link['label'] ) ? trim( (string) $link['label'] ) : '';
						$url   = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';

						if ( '' !== $label || '' !== $url ) {
							$bits[] = trim( $label . ( '' !== $url ? ' ' . $url : '' ) );
						}
					}

					if ( $bits ) {
						$out[] = implode( ' · ', $bits );
					}
					break;

				case 'table':
					// The pipe-separated source is already a readable plain-text
					// table, so it needs no conversion at all.
					$rows = isset( $atts['rows'] ) ? trim( (string) $atts['rows'] ) : '';

					if ( '' !== $rows ) {
						$out[] = $rows;
					}
					break;

				case 'footer':
					$bits = array();

					foreach ( array( 'note', 'address' ) as $key ) {
						if ( ! empty( $atts[ $key ] ) ) {
							$bits[] = trim( (string) $atts[ $key ] );
						}
					}

					if ( ! isset( $atts['unsubscribe'] ) || 'no' !== $atts['unsubscribe'] ) {
						$bits[] = __( 'Unsubscribe', 'fw' ) . ': {{unsubscribe_url}}';
					}

					if ( $bits ) {
						$out[] = implode( "\n", $bits );
					}
					break;

				case 'divider':
					$out[] = '---';
					break;

				case 'spacer':
					// Nothing to say in plain text; the blank line between blocks
					// already carries it.
					break;
			}
		}

		return implode( "\n\n", $out );
	}
}
