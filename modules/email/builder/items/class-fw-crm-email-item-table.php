<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Table block — a real data table.
 *
 * Worth being clear about the distinction, because "tables in email" usually
 * means the opposite thing: LAYOUT tables are the workaround email forces on us,
 * whereas this is a genuine data table — the one case where a <table> is also
 * the semantically correct element. It renders reliably everywhere.
 *
 * Rows are authored as plain text (one row per line, cells separated by |)
 * rather than as a repeater of repeaters, which is unusable in a modal and would
 * make pasting a small table from a spreadsheet impossible.
 */
class FW_CRM_Email_Item_Table extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'rows'    => array(
						'type'  => 'textarea',
						'label' => __( 'Rows', 'fw' ),
						'desc'  => __( 'One row per line, cells separated by a vertical bar: Item | Qty | Price', 'fw' ),
						'value' => "Item | Qty | Price\nExample | 2 | 19.00",
					),
					'header'  => array(
						'type'         => 'switch',
						'label'        => __( 'First row is a header', 'fw' ),
						'value'        => 'yes',
						'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
						'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'borders' => array(
						'type'         => 'switch',
						'label'        => __( 'Show borders', 'fw' ),
						'value'        => 'yes',
						'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
						'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
					),
					'border_color' => array( 'type' => 'color-picker', 'label' => __( 'Border colour', 'fw' ), 'value' => '#e0e0e0' ),
				),
			),
			'group_layout' => array(
				'type'    => 'group',
				'options' => array(
					'padding' => $this->padding_option( '12' ),
				),
			),
		) );
	}

	/** {@inheritdoc} */
	public function get_type() {
		return 'table';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'rows' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 10h18M9 10v9M15 10v9"/>',
			__( 'Table', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$raw = isset( $atts['rows'] ) ? trim( (string) $atts['rows'] ) : '';

		if ( '' === $raw ) {
			return '';
		}

		$has_header = ! isset( $atts['header'] ) || 'no' !== $atts['header'];
		$bordered   = ! isset( $atts['borders'] ) || 'no' !== $atts['borders'];
		$border     = ! empty( $atts['border_color'] ) ? $atts['border_color'] : '#e0e0e0';

		$cell_base = array(
			'padding'     => '8px 10px',
			'font-family' => $ctx['font_family'],
			'font-size'   => '14px',
			'line-height' => '1.5',
			'color'       => $ctx['text_color'],
			'text-align'  => 'left',
		);

		if ( $bordered ) {
			$cell_base['border-bottom'] = '1px solid ' . $border;
		}

		$body = '';
		$line_no = 0;

		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$line_no++;
			$is_head = $has_header && 1 === $line_no;
			$cells   = array_map( 'trim', explode( '|', $line ) );
			$row     = '';

			foreach ( $cells as $cell ) {
				$style = $cell_base;

				if ( $is_head ) {
					$style['font-weight'] = '700';
					$style['background-color'] = '#f6f7f7';
				}

				$row .= '<td style="' . esc_attr( $this->style( $style ) ) . '">' . esc_html( $cell ) . '</td>';
			}

			$body .= '<tr>' . $row . '</tr>';
		}

		if ( '' === $body ) {
			return '';
		}

		$table = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse'
			. ( $bordered ? ';border-top:1px solid ' . esc_attr( $border ) : '' ) . '">'
			. $body . '</table>';

		return $this->wrap_block( $table, $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 ), 'left' );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Table' );
