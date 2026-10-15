<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Heading block.
 *
 * Kept separate from Text so heading typography is addressable in one place —
 * the same reason MJML/Unlayer split them. A heading typed into a Text block
 * looks identical but cannot be restyled campaign-wide.
 */
class FW_CRM_Email_Item_Heading extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'text'      => array(
						'type'  => 'text',
						'label' => __( 'Heading', 'fw' ),
						'value' => __( 'A heading worth reading', 'fw' ),
					),
					'level'     => array(
						'type'    => 'radio',
						'label'   => __( 'Level', 'fw' ),
						'value'   => 'h2',
						'choices' => array( 'h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3' ),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'color'     => array(
						'type'  => 'color-picker',
						'label' => __( 'Colour', 'fw' ),
						'value' => '',
					),
					'font_size' => $this->px_option( __( 'Font size', 'fw' ), '', __( 'Leave empty for the default size of the chosen level.', 'fw' ) ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'     => $this->align_option( 'left' ),
					'padding'   => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'heading';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'text' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<path d="M6 4v16M18 4v16M6 12h12"/>',
			__( 'Heading', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$text = isset( $atts['text'] ) ? trim( (string) $atts['text'] ) : '';

		if ( '' === $text ) {
			return '';
		}

		$level    = in_array( isset( $atts['level'] ) ? $atts['level'] : '', array( 'h1', 'h2', 'h3' ), true ) ? $atts['level'] : 'h2';
		$defaults = array( 'h1' => 30, 'h2' => 24, 'h3' => 19 );
		$size     = $this->px( isset( $atts['font_size'] ) ? $atts['font_size'] : '', 0 ) ?: $defaults[ $level ];
		$align    = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'left' );

		// Margin is zeroed and spacing comes from the cell's padding: Outlook
		// ignores margins on block elements, so a heading with margin would sit
		// tight there and roomy everywhere else.
		$style = $this->style( array(
			'margin'      => '0',
			'font-family' => $ctx['font_family'],
			'font-size'   => $size . 'px',
			'line-height' => '1.25',
			'font-weight' => '700',
			'color'       => ! empty( $atts['color'] ) ? $atts['color'] : $ctx['text_color'],
		) );

		$html = '<' . $level . ' style="' . esc_attr( $style ) . '">' . esc_html( $text ) . '</' . $level . '>';

		return $this->wrap_block( $html, $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 ), $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Heading' );
