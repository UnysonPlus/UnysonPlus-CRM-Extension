<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Menu block — a row of links, typically under a logo.
 *
 * One row, one cell per link, exactly like the Social block: it is the only
 * arrangement email keeps on a single line without floats. On narrow screens the
 * cells simply squeeze; they do not wrap, which is why the block is meant for a
 * handful of short labels rather than a full site menu.
 */
class FW_CRM_Email_Item_Menu extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'items'     => array(
						'type'          => 'addable-popup',
						'label'         => __( 'Links', 'fw' ),
						'template'      => '{{- label }}',
						'popup-title'   => __( 'Menu link', 'fw' ),
						'value'         => array(),
						'popup-options' => array(
							'label' => array( 'type' => 'text', 'label' => __( 'Label', 'fw' ), 'value' => '' ),
							'url'   => array( 'type' => 'text', 'label' => __( 'URL', 'fw' ), 'value' => '', 'attr' => array( 'placeholder' => 'https://' ) ),
						),
					),
					'separator' => array(
						'type'  => 'text',
						'label' => __( 'Separator', 'fw' ),
						'desc'  => __( 'Shown between links. Leave empty for none.', 'fw' ),
						'value' => '·',
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'color'     => array( 'type' => 'color-picker', 'label' => __( 'Link colour', 'fw' ), 'value' => '' ),
					'font_size' => $this->px_option( __( 'Font size', 'fw' ), '14' ),
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
		return 'menu';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_preview_keys() {
		return array( 'items' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<path d="M4 8h16M4 16h16"/><circle cx="9" cy="8" r="1.2"/><circle cx="15" cy="16" r="1.2"/>',
			__( 'Menu', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$items = isset( $atts['items'] ) && is_array( $atts['items'] ) ? $atts['items'] : array();
		$align = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );
		$size  = max( 8, $this->px( isset( $atts['font_size'] ) ? $atts['font_size'] : '', 14 ) );
		$color = ! empty( $atts['color'] ) ? $atts['color'] : $ctx['link_color'];
		$sep   = isset( $atts['separator'] ) ? trim( (string) $atts['separator'] ) : '';

		$link_style = $this->style( array(
			'font-family'     => $ctx['font_family'],
			'font-size'       => $size . 'px',
			'color'           => $color,
			'text-decoration' => 'none',
		) );

		$cells = array();

		foreach ( $items as $item ) {
			$label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';

			if ( '' === $label ) {
				continue;
			}

			$cells[] = '<td style="padding:0 8px">'
				. $this->maybe_link( '<span style="' . esc_attr( $link_style ) . '">' . esc_html( $label ) . '</span>',
					isset( $item['url'] ) ? $item['url'] : '', 'text-decoration:none' )
				. '</td>';
		}

		if ( ! $cells ) {
			return '';
		}

		if ( '' !== $sep ) {
			$sep_cell = '<td style="padding:0;font-family:' . esc_attr( $ctx['font_family'] )
				. ';font-size:' . (int) $size . 'px;color:' . esc_attr( $color ) . '">' . esc_html( $sep ) . '</td>';
			$cells    = implode( $sep_cell, $cells );
		} else {
			$cells = implode( '', $cells );
		}

		$row = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
			. ' style="border-collapse:collapse"><tr>' . $cells . '</tr></table>';

		return $this->wrap_block( $row, $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 ), $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Menu' );
