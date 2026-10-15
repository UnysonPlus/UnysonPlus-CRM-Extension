<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Logo block.
 *
 * Structurally an Image with better defaults, which is the whole point: with no
 * image chosen it falls back to the site's own custom logo and links to the home
 * page, so dropping it in produces the right thing without a trip to the Media
 * Library. Sites with no custom logo fall back again to the site title as text,
 * rather than rendering a broken image.
 */
class FW_CRM_Email_Item_Logo extends FW_CRM_Email_Item {

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
						'label' => __( 'Logo', 'fw' ),
						'desc'  => __( 'Leave empty to use the site logo from the Customizer, and if there is none, the text below. PNG or JPG — email clients do not render SVG.', 'fw' ),
					),
					// The block always had this fallback; it just had no field, so
					// the name appeared out of nowhere with nothing to change it.
					// Showing it makes the behaviour explainable AND overridable.
					'text'    => array(
						'type'  => 'text',
						'label' => __( 'Logo text', 'fw' ),
						'desc'  => __( 'Shown when there is no image, and used as the image\'s alt text.', 'fw' ),
						// Prefilled with the site title as a real, editable VALUE
						// rather than a greyed placeholder — so the text is there to
						// be selected and typed over, and what the block will render
						// is never left implicit.
						//
						// The consequence to know: this is now a stored value, so a
						// campaign keeps the name it was written with if the site is
						// renamed later. That is the right trade for a newsletter,
						// where an already-composed email should not silently change
						// underneath you. Clearing the field still falls back to the
						// live site title at compile time.
						'value' => self::site_name(),
					),
					'url'     => array(
						'type'  => 'text',
						'label' => __( 'Links to', 'fw' ),
						'desc'  => __( 'Leave empty to link to the site home page.', 'fw' ),
						'value' => '',
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'width'   => $this->px_option( __( 'Width', 'fw' ), '180' ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'   => $this->align_option( 'center' ),
					'padding' => $this->padding_option( '20' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'logo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'text', 'image' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<circle cx="12" cy="12" r="9"/><path d="M8 13.5l2.5-3 2 2.4 1.8-2.2L16.5 14"/>',
			__( 'Logo', 'fw' )
		);
	}

	/**
	 * The site title as plain text — see FW_CRM_Mail::site_name()
	 * for why tags are stripped rather than escaped.
	 *
	 * @return string
	 */
	public static function site_name() {
		return FW_CRM_Mail::site_name();
	}

	/**
	 * The site's Customizer logo, if one is set.
	 *
	 * @return string
	 */
	private function site_logo_url() {
		$id = (int) get_theme_mod( 'custom_logo' );

		if ( ! $id ) {
			return '';
		}

		$src = wp_get_attachment_image_src( $id, 'full' );

		return $src ? (string) $src[0] : '';
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$src = isset( $atts['image'] ) ? $this->image_url( $atts['image'] ) : '';

		if ( '' === $src ) {
			$src = $this->site_logo_url();
		}

		$url = ! empty( $atts['url'] ) ? $atts['url'] : home_url( '/' );

		// Per-campaign override first, the site title otherwise — so a newsletter
		// can say something other than the site's own name without anybody having
		// to change the site.
		$name = isset( $atts['text'] ) && '' !== trim( (string) $atts['text'] )
			? trim( wp_strip_all_tags( (string) $atts['text'] ) )
			: self::site_name();

		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 20 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );

		if ( '' === $src ) {
			// No logo anywhere — the site name as text beats a broken image.
			$inner = '<span style="' . esc_attr( $this->style( array(
				'font-family' => $ctx['font_family'],
				'font-size'   => '20px',
				'font-weight' => '700',
				'color'       => $ctx['text_color'],
			) ) ) . '">' . esc_html( $name ) . '</span>';

			return $this->wrap_block( $this->maybe_link( $inner, $url, 'text-decoration:none' ), $padding, $align );
		}

		$max   = max( 1, (int) $ctx['content_width'] - ( $padding * 2 ) );
		$width = min( $max, max( 1, $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 180 ) ) );

		$img = '<img src="' . esc_url( $src ) . '" alt="' . esc_attr( $name ) . '"'
			. ' width="' . (int) $width . '"'
			. ' style="display:block;width:100%;max-width:' . (int) $width . 'px;height:auto;border:0" border="0" />';

		$table = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
			. ' style="border-collapse:collapse"><tr><td style="padding:0">'
			. $this->maybe_link( $img, $url, 'text-decoration:none' )
			. '</td></tr></table>';

		return $this->wrap_block( $table, $padding, $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Logo' );
