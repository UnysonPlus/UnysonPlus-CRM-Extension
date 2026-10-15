<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Text block — a paragraph of rich text.
 */
class FW_CRM_Email_Item_Text extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'content' => array(
						'type'  => 'wp-editor',
						'label' => __( 'Text', 'fw' ),
						'desc'  => __( 'Placeholders such as {{first_name}} work here.', 'fw' ),
						'value' => __( 'Write something worth opening.', 'fw' ),
						'teeny' => true,
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'color'      => array(
						'type'  => 'color-picker',
						'label' => __( 'Text colour', 'fw' ),
						'desc'  => __( 'Leave empty to inherit the campaign default.', 'fw' ),
						'value' => '',
					),
					'font_size'  => $this->px_option( __( 'Font size', 'fw' ), '' ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'   => $this->align_option( 'left' ),
					'padding'    => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'content' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="14" y2="17"/>',
			__( 'Text', 'fw' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function compile( array $atts, array $ctx ) {
		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'left' );

		// wp_kses_post, not raw: the body is authored in an editor and must not be
		// able to carry script into somebody's inbox.
		$content = wp_kses_post( isset( $atts['content'] ) ? $atts['content'] : '' );

		return $this->wrap_block( $content, $padding, $align, array(
			'font-family' => $ctx['font_family'],
			'font-size'   => ( $this->px( isset( $atts['font_size'] ) ? $atts['font_size'] : '', 0 ) ?: $ctx['font_size'] ) . 'px',
			'line-height' => $ctx['line_height'],
			'color'       => ! empty( $atts['color'] ) ? $atts['color'] : $ctx['text_color'],
		) );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Text' );
