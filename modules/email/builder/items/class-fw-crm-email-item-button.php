<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Button block — a "bulletproof" call to action.
 *
 * A CSS-styled <a> is not enough: classic Outlook renders with the Word engine
 * and ignores padding on inline elements, so the button collapses to a bare
 * link. The industry-standard answer is a table-based button PLUS an MSO
 * conditional VML fallback — and VML requires an explicit width, which is why
 * this block asks for one rather than sizing to its text.
 */
class FW_CRM_Email_Item_Button extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'label'      => array(
						'type'  => 'text',
						'label' => __( 'Button text', 'fw' ),
						'value' => __( 'Read more', 'fw' ),
					),
					'url'        => array(
						'type'  => 'text',
						'label' => __( 'Links to', 'fw' ),
						'value' => '',
						'attr'  => array( 'placeholder' => 'https://' ),
					),
					'tag_on_click' => array(
						'type'  => 'text',
						'label' => __( 'Tag on click', 'fw' ),
						'desc'  => __( 'Optional. Tags added to the contact who clicks, comma-separated, e.g. interested-in-pricing. Needs click tracking on.', 'fw' ),
						'value' => '',
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'bg_color'   => array(
						'type'  => 'color-picker',
						'label' => __( 'Background', 'fw' ),
						'value' => '#2271b1',
					),
					'text_color' => array(
						'type'  => 'color-picker',
						'label' => __( 'Text colour', 'fw' ),
						'value' => '#ffffff',
					),
					'width'      => $this->px_option( __( 'Width', 'fw' ), '200', __( 'Classic Outlook needs a fixed width to draw the button at all.', 'fw' ) ),
					'radius'     => $this->px_option( __( 'Corner radius', 'fw' ), '4', __( 'Ignored by Outlook, which always renders square corners.', 'fw' ) ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'      => $this->align_option( 'center' ),
					'padding'    => $this->padding_option( '16' ),
				),
			),
		) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'button';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'label' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="8" width="18" height="8" rx="4"/><line x1="8" y1="12" x2="16" y2="12"/>',
			__( 'Button', 'fw' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function compile( array $atts, array $ctx ) {
		$label = isset( $atts['label'] ) ? trim( (string) $atts['label'] ) : '';
		$url   = isset( $atts['url'] ) ? trim( (string) $atts['url'] ) : '';

		if ( '' === $label ) {
			return '';
		}

		$padding    = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 16 );
		$align      = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );
		$width      = max( 40, $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 200 ) );
		$radius     = $this->px( isset( $atts['radius'] ) ? $atts['radius'] : '', 4 );
		$bg         = ! empty( $atts['bg_color'] ) ? $atts['bg_color'] : '#2271b1';
		$fg         = ! empty( $atts['text_color'] ) ? $atts['text_color'] : '#ffffff';
		$height     = 44;
		if ( '' !== $url && ! empty( $atts['tag_on_click'] ) && class_exists( 'FW_CRM_Smart_Links' ) ) {
			$url = FW_CRM_Smart_Links::with_tags( $url, (string) $atts['tag_on_click'] );
		}
		$href       = '' !== $url ? esc_url( $url ) : '#';
		$safe_label = esc_html( $label );

		$anchor_style = $this->style( array(
			'display'         => 'inline-block',
			'width'           => $width . 'px',
			'line-height'     => $height . 'px',
			'background-color' => $bg,
			'color'           => $fg,
			'font-family'     => $ctx['font_family'],
			'font-size'       => $ctx['font_size'] . 'px',
			'font-weight'     => '600',
			'text-align'      => 'center',
			'text-decoration' => 'none',
			'border-radius'   => $radius . 'px',
			// Outlook draws the VML button; hide the anchor's own box there.
			'mso-hide'        => 'all',
		) );

		// VML fallback for classic Outlook (Word engine). Wrapped in an MSO
		// conditional so every other client ignores it entirely.
		$vml = '<!--[if mso]>'
			. '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word"'
			. ' href="' . $href . '" style="height:' . $height . 'px;v-text-anchor:middle;width:' . $width . 'px;"'
			. ' arcsize="' . ( $width > 0 ? (int) round( ( $radius / $height ) * 100 ) : 0 ) . '%"'
			. ' stroke="f" fillcolor="' . esc_attr( $bg ) . '">'
			. '<w:anchorlock/>'
			. '<center style="color:' . esc_attr( $fg ) . ';font-family:' . esc_attr( $ctx['font_family'] ) . ';font-size:' . (int) $ctx['font_size'] . 'px;font-weight:600;">'
			. $safe_label
			. '</center>'
			. '</v:roundrect>'
			. '<![endif]-->';

		$anchor = '<!--[if !mso]><!-- -->'
			. '<a href="' . $href . '" target="_blank" style="' . esc_attr( $anchor_style ) . '">' . $safe_label . '</a>'
			. '<!--<![endif]-->';

		return $this->wrap_block( $vml . $anchor, $padding, $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Button' );
