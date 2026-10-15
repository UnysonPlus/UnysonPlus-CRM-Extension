<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Hero block — a banner: background, heading, text, optional button.
 *
 * The fiddliest block in the set, because background images are the weakest
 * thing in email. Outlook's Word engine ignores CSS backgrounds entirely, so the
 * image is ALSO emitted as a VML rectangle inside an MSO conditional — the same
 * trick the Button block uses for its shape. Everything else reads the CSS.
 *
 * A background colour is therefore mandatory rather than optional: it is what
 * every client that blocks images (a large share of any list, by default) will
 * show instead, and what the text has to stay legible against.
 */
class FW_CRM_Email_Item_Hero extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'heading'       => array( 'type' => 'text', 'label' => __( 'Heading', 'fw' ), 'value' => '' ),
					'text'          => array( 'type' => 'textarea', 'label' => __( 'Text', 'fw' ), 'value' => '' ),
					'button_label'  => array( 'type' => 'text', 'label' => __( 'Button text', 'fw' ), 'value' => '' ),
					'button_url'    => array( 'type' => 'text', 'label' => __( 'Button URL', 'fw' ), 'value' => '', 'attr' => array( 'placeholder' => 'https://' ) ),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'background'    => array(
						'type'  => 'upload',
						'label' => __( 'Background image', 'fw' ),
						'desc'  => __( 'Optional. Outlook ignores CSS backgrounds, so a VML fallback is emitted automatically — but images are blocked by default in many clients, so the colour below still has to carry the design.', 'fw' ),
					),
					'bg_color'      => array(
						'type'  => 'color-picker',
						'label' => __( 'Background colour', 'fw' ),
						'desc'  => __( 'Shown wherever the image is blocked or unsupported. Pick something the text stays readable on.', 'fw' ),
						'value' => '#2271b1',
					),
					'text_color'    => array( 'type' => 'color-picker', 'label' => __( 'Text colour', 'fw' ), 'value' => '#ffffff' ),
					'button_bg'     => array( 'type' => 'color-picker', 'label' => __( 'Button background', 'fw' ), 'value' => '#ffffff' ),
					'button_color'  => array( 'type' => 'color-picker', 'label' => __( 'Button text colour', 'fw' ), 'value' => '#1d2327' ),
					'height'        => $this->px_option( __( 'Minimum height', 'fw' ), '220' ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'         => $this->align_option( 'center' ),
					'padding'       => $this->padding_option( '32' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'hero';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'heading', 'text' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 14h10M7 10h6"/>',
			__( 'Hero', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$heading = isset( $atts['heading'] ) ? trim( (string) $atts['heading'] ) : '';
		$text    = isset( $atts['text'] ) ? trim( (string) $atts['text'] ) : '';
		$label   = isset( $atts['button_label'] ) ? trim( (string) $atts['button_label'] ) : '';
		$bg_img  = isset( $atts['background'] ) ? $this->image_url( $atts['background'] ) : '';

		if ( '' === $heading && '' === $text && '' === $label && '' === $bg_img ) {
			return '';
		}

		$width   = (int) $ctx['content_width'];
		$height  = max( 40, $this->px( isset( $atts['height'] ) ? $atts['height'] : '', 220 ) );
		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 32 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );
		$bg      = ! empty( $atts['bg_color'] ) ? $atts['bg_color'] : '#2271b1';
		$fg      = ! empty( $atts['text_color'] ) ? $atts['text_color'] : '#ffffff';

		$inner = '';

		if ( '' !== $heading ) {
			$inner .= '<h2 style="' . esc_attr( $this->style( array(
				'margin'      => '0 0 10px',
				'font-family' => $ctx['font_family'],
				'font-size'   => '26px',
				'line-height' => '1.25',
				'font-weight' => '700',
				'color'       => $fg,
			) ) ) . '">' . esc_html( $heading ) . '</h2>';
		}

		if ( '' !== $text ) {
			$inner .= '<p style="' . esc_attr( $this->style( array(
				'margin'      => '0',
				'font-family' => $ctx['font_family'],
				'font-size'   => $ctx['font_size'] . 'px',
				'line-height' => $ctx['line_height'],
				'color'       => $fg,
			) ) ) . '">' . nl2br( esc_html( $text ) ) . '</p>';
		}

		if ( '' !== $label ) {
			$btn_bg = ! empty( $atts['button_bg'] ) ? $atts['button_bg'] : '#ffffff';
			$btn_fg = ! empty( $atts['button_color'] ) ? $atts['button_color'] : '#1d2327';

			$inner .= '<div style="margin-top:18px">'
				. '<a href="' . ( ! empty( $atts['button_url'] ) ? esc_url( $atts['button_url'] ) : '#' ) . '"'
				. ' target="_blank" style="' . esc_attr( $this->style( array(
					'display'          => 'inline-block',
					'padding'          => '12px 26px',
					'background-color' => $btn_bg,
					'color'            => $btn_fg,
					'font-family'      => $ctx['font_family'],
					'font-size'        => $ctx['font_size'] . 'px',
					'font-weight'      => '600',
					'text-decoration'  => 'none',
					'border-radius'    => '4px',
				) ) ) . '">' . esc_html( $label ) . '</a></div>';
		}

		$cell_style = $this->style( array(
			'padding'             => $padding . 'px',
			'text-align'          => $align,
			'background-color'    => $bg,
			'background-image'    => '' !== $bg_img ? 'url(' . $bg_img . ')' : '',
			'background-position' => '' !== $bg_img ? 'center' : '',
			'background-size'     => '' !== $bg_img ? 'cover' : '',
			'background-repeat'   => '' !== $bg_img ? 'no-repeat' : '',
		) );

		// This block builds its own wrapper rather than going through
		// wrap_block(), so it has to merge the author's declarations itself.
		$cell_style = $this->merge_extra_styles( $cell_style );

		$open  = '';
		$close = '';

		// Outlook: paint the image with VML, since it ignores CSS backgrounds.
		if ( '' !== $bg_img ) {
			$open = '<!--[if mso]>'
				. '<v:rect xmlns:v="urn:schemas-microsoft-com:vml" fill="true" stroke="false"'
				. ' style="width:' . $width . 'px;height:' . $height . 'px;">'
				. '<v:fill type="frame" src="' . esc_url( $bg_img ) . '" color="' . esc_attr( $bg ) . '" />'
				. '<v:textbox inset="0,0,0,0"><![endif]-->';

			$close = '<!--[if mso]></v:textbox></v:rect><![endif]-->';
		}

		$html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse">'
			. '<tr><td align="' . esc_attr( $align ) . '" height="' . (int) $height . '"'
			. ' style="' . esc_attr( $cell_style ) . '">'
			. $open . $inner . $close
			. '</td></tr></table>';

		return $html;
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Hero' );
