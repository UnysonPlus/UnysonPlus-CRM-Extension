<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Spacer block — vertical space, nothing else.
 *
 * A cell with an explicit height rather than a margin: Outlook ignores margins
 * on block elements, and several clients collapse an empty cell entirely, hence
 * the non-breaking space and the zeroed font metrics.
 */
class FW_CRM_Email_Item_Spacer extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'height' => $this->px_option( __( 'Height', 'fw' ), '24' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'spacer';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'height' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<path d="M8 7l4-4 4 4M8 17l4 4 4-4M12 3v18"/>',
			__( 'Spacer', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$height = max( 1, $this->px( isset( $atts['height'] ) ? $atts['height'] : '', 24 ) );

		$style = $this->style( array(
			'height'      => $height . 'px',
			'line-height' => $height . 'px',
			'font-size'   => '0',
		) );

		// This block builds its own wrapper rather than going through
		// wrap_block(), so it has to merge the author's declarations itself.
		$style = $this->merge_extra_styles( $style );

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse">'
			. '<tr><td style="' . esc_attr( $style ) . '">&nbsp;</td></tr></table>';
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Spacer' );
