<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Raw HTML block — the escape hatch every builder ships (MJML's mj-raw,
 * Unlayer's HTML tool).
 *
 * Filtering follows WordPress's own rule rather than inventing one: users with
 * `unfiltered_html` get their markup verbatim (that capability exists precisely
 * to say "this person may write raw HTML"), everyone else goes through
 * wp_kses_post. Without that split the block would either be useless to admins
 * — kses strips <style>, which is half the point of a raw block — or a stored
 * XSS hole for lower-privileged editors.
 *
 * The check sits in compile(), which today always runs in admin context because
 * the tree is compiled on save. WATCH THIS if send-time compilation ever lands
 * for dynamic blocks: cron has no current user, so the capability would fail and
 * silently kses the markup. At that point the author's capability needs
 * capturing at save rather than being re-read at compile.
 */
class FW_CRM_Email_Item_Html extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'html'    => array(
						'type'  => 'code-editor',
						'label' => __( 'HTML', 'fw' ),
						'desc'  => __( 'Pasted verbatim into the email. Use table markup and inline styles — everything else is a gamble outside a browser.', 'fw' ),
						'value' => '',
						'mode'  => 'text/html',
					),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'padding' => $this->padding_option( '0' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'html';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'html' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<path d="M9 6l-5 6 5 6M15 6l5 6-5 6"/>',
			__( 'HTML', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$html = isset( $atts['html'] ) ? (string) $atts['html'] : '';

		if ( '' === trim( $html ) ) {
			return '';
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = wp_kses_post( $html );
		}

		return $this->wrap_block( $html, $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 0 ), 'left' );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Html' );
