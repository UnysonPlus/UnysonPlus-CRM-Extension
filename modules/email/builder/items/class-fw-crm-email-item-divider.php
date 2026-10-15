<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Divider block — a horizontal rule, or plain vertical space.
 *
 * Built from a table cell with a border rather than <hr> or a margin: Outlook
 * ignores margins on block elements and styles <hr> inconsistently, whereas a
 * bordered cell renders identically everywhere.
 */
class FW_CRM_Email_Item_Divider extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'style'     => array(
						'type'    => 'radio',
						'label'   => __( 'Style', 'fw' ),
						'value'   => 'line',
						'choices' => array(
							'line'  => __( 'Line', 'fw' ),
							'space' => __( 'Space only', 'fw' ),
						),
					),
					'color'     => array(
						'type'  => 'color-picker',
						'label' => __( 'Line colour', 'fw' ),
						'value' => '#e0e0e0',
					),
					'thickness' => $this->px_option( __( 'Thickness', 'fw' ), '1' ),
					'width'     => array(
						'type'  => 'text',
						'label' => __( 'Width (%)', 'fw' ),
						'desc'  => __( 'Percentage of the content width.', 'fw' ),
						'value' => '100',
					),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'padding'   => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'divider';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'style' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<line x1="3" y1="12" x2="21" y2="12"/>',
			__( 'Divider', 'fw' )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function compile( array $atts, array $ctx ) {
		$padding   = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 );
		$style     = isset( $atts['style'] ) && 'space' === $atts['style'] ? 'space' : 'line';
		$thickness = max( 1, $this->px( isset( $atts['thickness'] ) ? $atts['thickness'] : '', 1 ) );
		$width     = min( 100, max( 1, $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 100 ) ) );
		$color     = ! empty( $atts['color'] ) ? $atts['color'] : '#e0e0e0';

		$zero = array( 'font-size' => '0', 'line-height' => '0' );

		if ( 'space' === $style ) {
			// A cell with no border still needs content, or some clients collapse
			// it to nothing — hence the non-breaking space.
			return $this->wrap_block( '&nbsp;', $padding, 'left', $zero );
		}

		$rule = '<table role="presentation" cellpadding="0" cellspacing="0" border="0"'
			. ' width="' . (int) $width . '%"'
			. ' style="' . esc_attr( $this->style( array(
				'width'           => $width . '%',
				'border-collapse' => 'collapse',
				'border-top'      => $thickness . 'px solid ' . $color,
				'font-size'       => '0',
				'line-height'     => '0',
			) ) ) . '">'
			. '<tr><td style="font-size:0;line-height:0">&nbsp;</td></tr>'
			. '</table>';

		return $this->wrap_block( $rule, $padding, 'center', $zero );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Divider' );
