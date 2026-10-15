<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Social links block.
 *
 * A deliberate design note on icons: this block does NOT ship an icon set.
 *
 *  - SVG is out — Gmail strips it and Outlook ignores it, so a linked .svg is a
 *    broken image for a large share of any list.
 *  - That leaves raster icons, which means shipping brand marks (Facebook, X,
 *    Instagram…) as bundled artwork — third-party trademarks we would be
 *    redistributing, and a maintenance burden every time a brand rebrands.
 *
 * So each link renders as a styled text link by default, which is 100% reliable
 * in every client, and takes an optional uploaded icon for sites that have their
 * own icon set. Nothing is faked and nothing can break.
 */
class FW_CRM_Email_Item_Social extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'links'     => array(
						'type'          => 'addable-popup',
						'label'         => __( 'Links', 'fw' ),
						'desc'          => __( 'Each one renders as a text link, or as your own icon if you upload one.', 'fw' ),
						'template'      => '{{- label }}',
						'popup-title'   => __( 'Social link', 'fw' ),
						'value'         => array(
							array( 'label' => __( 'Facebook', 'fw' ), 'url' => '' ),
							array( 'label' => __( 'Instagram', 'fw' ), 'url' => '' ),
						),
						'popup-options' => array(
							'label' => array(
								'type'  => 'text',
								'label' => __( 'Label', 'fw' ),
								'value' => '',
							),
							'url'   => array(
								'type'  => 'text',
								'label' => __( 'URL', 'fw' ),
								'value' => '',
								'attr'  => array( 'placeholder' => 'https://' ),
							),
							'icon'  => array(
								'type'  => 'upload',
								'label' => __( 'Icon (optional)', 'fw' ),
								'desc'  => __( 'PNG or JPG only — email clients do not render SVG. Square, about 64px, works best.', 'fw' ),
							),
						),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'icon_size' => $this->px_option( __( 'Icon size', 'fw' ), '32' ),
					'gap'       => $this->px_option( __( 'Space between', 'fw' ), '10' ),
					'color'     => array(
						'type'  => 'color-picker',
						'label' => __( 'Text link colour', 'fw' ),
						'value' => '',
					),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'align'     => $this->align_option( 'center' ),
					'padding'   => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'social';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'links' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/>',
			__( 'Social', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$links = isset( $atts['links'] ) && is_array( $atts['links'] ) ? $atts['links'] : array();
		$size  = max( 8, $this->px( isset( $atts['icon_size'] ) ? $atts['icon_size'] : '', 32 ) );
		$gap   = max( 0, $this->px( isset( $atts['gap'] ) ? $atts['gap'] : '', 10 ) );
		$align = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );
		$color = ! empty( $atts['color'] ) ? $atts['color'] : $ctx['link_color'];

		$cells = '';

		foreach ( $links as $link ) {
			$url   = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
			$label = isset( $link['label'] ) ? trim( (string) $link['label'] ) : '';
			$icon  = isset( $link['icon'] ) ? $this->image_url( $link['icon'] ) : '';

			if ( '' === $url && '' === $label ) {
				continue;
			}

			if ( '' !== $icon ) {
				$inner = '<img src="' . esc_url( $icon ) . '" alt="' . esc_attr( $label ) . '"'
					. ' width="' . (int) $size . '" height="' . (int) $size . '"'
					. ' style="display:block;border:0;width:' . (int) $size . 'px;height:' . (int) $size . 'px" />';
			} else {
				$inner = '<span style="' . esc_attr( $this->style( array(
					'font-family'     => $ctx['font_family'],
					'font-size'       => '14px',
					'color'           => $color,
					'text-decoration' => 'underline',
				) ) ) . '">' . esc_html( '' !== $label ? $label : $url ) . '</span>';
			}

			// Each link is its own cell in one row — the only layout email can be
			// trusted to keep on a single line without floats.
			$cells .= '<td style="padding:0 ' . (int) round( $gap / 2 ) . 'px">'
				. $this->maybe_link( $inner, $url, 'text-decoration:none' )
				. '</td>';
		}

		if ( '' === $cells ) {
			return '';
		}

		$row = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
			. ' style="border-collapse:collapse"><tr>' . $cells . '</tr></table>';

		return $this->wrap_block( $row, $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 ), $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Social' );
