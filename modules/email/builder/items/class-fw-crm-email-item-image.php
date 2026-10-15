<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Image block — a single, full-width-capable image, optionally linked.
 */
class FW_CRM_Email_Item_Image extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'image'   => array(
						'type'  => 'upload',
						'label' => __( 'Image', 'fw' ),
						'desc'  => __( 'Hosted in your Media Library, so it stays reachable after sending.', 'fw' ),
					),
					'alt'     => array(
						'type'  => 'text',
						'label' => __( 'Alt text', 'fw' ),
						'desc'  => __( 'Shown when images are blocked — which is the DEFAULT in many email clients, so write something that still makes sense on its own.', 'fw' ),
						'value' => '',
					),
					'link'    => array(
						'type'  => 'text',
						'label' => __( 'Links to', 'fw' ),
						'value' => '',
						'attr'  => array( 'placeholder' => 'https://' ),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'width'   => $this->px_option( __( 'Width', 'fw' ), '', __( 'Leave empty to fill the content width. Never wider than the email.', 'fw' ) ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'   => $this->align_option( 'center' ),
					'padding' => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'image';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'alt', 'image' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path d="M21 15l-5-5L5 19"/>',
			__( 'Image', 'fw' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function compile( array $atts, array $ctx ) {
		$src = '';

		// The upload option type stores an array( 'url' => …, 'attachment_id' => … ).
		if ( isset( $atts['image'] ) ) {
			$src = is_array( $atts['image'] )
				? ( isset( $atts['image']['url'] ) ? $atts['image']['url'] : '' )
				: (string) $atts['image'];
		}

		if ( '' === $src ) {
			return '';
		}

		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );

		// Cap at the usable content width — an image wider than the email breaks
		// the layout in clients that will not scale it down.
		$max   = max( 1, (int) $ctx['content_width'] - ( $padding * 2 ) );
		$width = $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 0 );
		$width = $width > 0 ? min( $width, $max ) : $max;

		$img = '<img src="' . esc_url( $src ) . '"'
			. ' alt="' . esc_attr( isset( $atts['alt'] ) ? $atts['alt'] : '' ) . '"'
			. ' width="' . (int) $width . '"'
			. ' style="' . esc_attr( $this->style( array(
				'display'   => 'block',
				'width'     => '100%',
				'max-width' => $width . 'px',
				'height'    => 'auto',
				'border'    => '0',
				// Belt and braces for Outlook, which otherwise adds its own spacing.
				'outline'   => 'none',
				'text-decoration' => 'none',
			) ) ) . '"'
			. ' border="0" />';

		if ( ! empty( $atts['link'] ) ) {
			$img = '<a href="' . esc_url( $atts['link'] ) . '" target="_blank" style="text-decoration:none">' . $img . '</a>';
		}

		// The inner table keeps the image at its own width so alignment works in
		// clients that ignore text-align on a block-level image.
		$inner = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
			. ' style="' . esc_attr( $this->style( array( 'border-collapse' => 'collapse' ) ) ) . '">'
			. '<tr><td style="padding:0">' . $img . '</td></tr>'
			. '</table>';

		return $this->wrap_block( $inner, $padding, $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Image' );
