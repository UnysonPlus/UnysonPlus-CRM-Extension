<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Footer block — the compliance block.
 *
 * Bulk email in most jurisdictions must carry a working opt-out AND a real
 * postal address (CAN-SPAM requires the address explicitly; GDPR/PECR expect a
 * clear identity and opt-out). The sender already guarantees the unsubscribe
 * link, but nothing until now prompted anyone for an address — so this block
 * exists mainly to put that field in front of the person writing the campaign.
 */
class FW_CRM_Email_Item_Footer extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'address'     => array(
						'type'  => 'textarea',
						'label' => __( 'Postal address', 'fw' ),
						'desc'  => __( 'A real mailing address is legally required in bulk email in several jurisdictions (CAN-SPAM requires it outright). Leave it empty only if you are certain none of your recipients are covered.', 'fw' ),
						'value' => get_option( 'blogname' ),
					),
					'note'        => array(
						'type'  => 'textarea',
						'label' => __( 'Extra note', 'fw' ),
						'desc'  => __( 'Optional line above the address — why they are receiving this, for example.', 'fw' ),
						'value' => __( 'You are receiving this because you subscribed on our website.', 'fw' ),
					),
					'unsubscribe' => array(
						'type'         => 'switch',
						'label'        => __( 'Unsubscribe link', 'fw' ),
						'desc'         => __( 'Leave this on. If every block omits it, the sender appends its own line anyway — this just lets you place and style it.', 'fw' ),
						'value'        => 'yes',
						'right-choice' => array( 'value' => 'yes', 'label' => __( 'Show', 'fw' ) ),
						'left-choice'  => array( 'value' => 'no', 'label' => __( 'Hide', 'fw' ) ),
					),
					'label'       => array(
						'type'  => 'text',
						'label' => __( 'Unsubscribe text', 'fw' ),
						'value' => __( 'Unsubscribe', 'fw' ),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'color'       => array(
						'type'  => 'color-picker',
						'label' => __( 'Text colour', 'fw' ),
						'value' => '#787c82',
					),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'padding'     => $this->padding_option( '20' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'footer';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'address', 'note' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 15h18"/>',
			__( 'Footer', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$color = ! empty( $atts['color'] ) ? $atts['color'] : '#787c82';

		$text_style = $this->style( array(
			'margin'      => '0 0 6px',
			'font-family' => $ctx['font_family'],
			'font-size'   => '12px',
			'line-height' => '1.6',
			'color'       => $color,
		) );

		$parts = array();

		$note = isset( $atts['note'] ) ? trim( (string) $atts['note'] ) : '';
		if ( '' !== $note ) {
			$parts[] = '<p style="' . esc_attr( $text_style ) . '">' . nl2br( esc_html( $note ) ) . '</p>';
		}

		$address = isset( $atts['address'] ) ? trim( (string) $atts['address'] ) : '';
		if ( '' !== $address ) {
			$parts[] = '<p style="' . esc_attr( $text_style ) . '">' . nl2br( esc_html( $address ) ) . '</p>';
		}

		$show_unsub = ! isset( $atts['unsubscribe'] ) || 'no' !== $atts['unsubscribe'];
		if ( $show_unsub ) {
			$label = isset( $atts['label'] ) && '' !== trim( (string) $atts['label'] )
				? trim( (string) $atts['label'] )
				: __( 'Unsubscribe', 'fw' );

			// The placeholder, not a resolved URL: the token is swapped per
			// recipient at send time, so each person gets their own opt-out link.
			$parts[] = '<p style="' . esc_attr( $text_style ) . '">'
				. '<a href="{{unsubscribe_url}}" style="color:' . esc_attr( $color ) . ';text-decoration:underline">'
				. esc_html( $label )
				. '</a></p>';
		}

		if ( ! $parts ) {
			return '';
		}

		return $this->wrap_block(
			implode( '', $parts ),
			$this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 20 ),
			'center'
		);
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Footer' );
