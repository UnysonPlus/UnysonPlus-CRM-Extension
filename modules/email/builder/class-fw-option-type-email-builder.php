<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The Email Builder option type.
 *
 * The framework's builder is a genuine extension point — FW_Option_Type_Builder
 * is abstract, and the plugin already ships three subclasses (the Page Builder,
 * the Forms extension's Form Builder, and the Learning extension's Quiz
 * Builder). This is the fourth, and it inherits the whole drag-drop canvas: item
 * tray, add / reorder / clone / delete, JSON storage and the per-item options
 * modal.
 *
 * What is NOT inherited — and is the actual work — is rendering. The page
 * builder renders through shortcodes, which emit divs, CSS classes and enqueued
 * stylesheets: the exact three things email forbids. So blocks compile
 * themselves to nested tables with inline styles, the way the quiz builder
 * renders its own question markup.
 */
class FW_Option_Type_Email_Builder extends FW_Option_Type_Builder {

	/**
	 * {@inheritdoc}
	 */
	public function get_type() {
		return 'email-builder';
	}

	/**
	 * @internal
	 * {@inheritdoc}
	 *
	 * `template_saving` turns on the FRAMEWORK's own builder template library —
	 * the Templates panel, save-current-canvas-as-template, load, delete, and
	 * JSON export/import — the same way `template_saving` powers the page
	 * builder's. It is a flag, not a feature we write: reimplementing it would
	 * mean a second storage scheme and a worse version of a component that
	 * already works.
	 *
	 * Storage is scoped by builder type (`fw:bt:f:email-builder:…`), so an email
	 * template can never appear in the page builder's list, or vice versa.
	 */
	protected function _get_defaults() {
		return array(
			'value'           => array( 'json' => '[]' ),
			'template_saving' => true,
		);
	}

	/**
	 * @internal
	 * {@inheritdoc}
	 */
	protected function _init() {
		$dir = dirname( __FILE__ );

		// Starter templates, offered alongside whatever the user has saved.
		require_once $dir . '/../class-fw-crm-email-templates.php';

		add_filter(
			'fw_ext_builder:predefined_templates:' . $this->get_type() . ':full',
			array( 'FW_CRM_Email_Templates', '_filter_predefined' )
		);

		// Our own width vocabulary. The framework's default grid is twelfths,
		// which is far too granular for a 600px email — a 1/12 column is ~50px
		// and cannot hold anything. The width changer reads this list.
		add_filter( 'fw_builder_item_widths:' . $this->get_type(), array( $this, '_filter_item_widths' ) );

		require_once $dir . '/extends/class-fw-crm-email-item.php';

		// Order here is the order they appear in the tray, so it runs roughly
		// top-of-email to bottom-of-email rather than alphabetically.
		$items = array(
			'logo', 'heading', 'text', 'image', 'button',
			'divider', 'spacer', 'menu', 'social',
			'hero', 'video', 'table', 'footer', 'html',
		);

		foreach ( $items as $item ) {
			require_once $dir . '/items/class-fw-crm-email-item-' . $item . '.php';
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param FW_Option_Type_Builder_Item $item_type_instance
	 *
	 * @return bool
	 */
	protected function item_type_is_valid( $item_type_instance ) {
		return is_subclass_of( $item_type_instance, 'FW_CRM_Email_Item' );
	}

	/**
	 * @internal
	 *
	 * @param array $widths
	 *
	 * @return array
	 */
	public function _filter_item_widths( $widths ) {
		return array(
			'1_4' => array( 'title' => '1/4', 'backend_class' => 'fw-col-sm-3' ),
			'1_3' => array( 'title' => '1/3', 'backend_class' => 'fw-col-sm-4' ),
			'1_2' => array( 'title' => '1/2', 'backend_class' => 'fw-col-sm-6' ),
			'2_3' => array( 'title' => '2/3', 'backend_class' => 'fw-col-sm-8' ),
			'3_4' => array( 'title' => '3/4', 'backend_class' => 'fw-col-sm-9' ),
			'1_1' => array( 'title' => __( 'Full width', 'fw' ), 'backend_class' => 'fw-col-sm-12' ),
		);
	}

	/**
	 * The registered blocks, keyed by type.
	 *
	 * The base class keeps get_item_types() protected, so the compiler — which
	 * lives outside the option-type hierarchy — needs this public door.
	 *
	 * @return FW_CRM_Email_Item[]
	 */
	public function get_blocks() {
		$out = array();

		foreach ( $this->get_item_types() as $type => $instance ) {
			if ( $instance instanceof FW_CRM_Email_Item ) {
				$out[ $type ] = $instance;
			}
		}

		return $out;
	}

	/**
	 * @internal
	 * {@inheritdoc}
	 */
	protected function _enqueue_static( $id, $option, $data ) {
		parent::_enqueue_static( $id, $option, $data );

		$ext = fw()->extensions->get( 'crm' );

		if ( ! $ext ) {
			return;
		}

		$version = $ext->manifest->get_version();

		wp_enqueue_style(
			'fw-builder-email-builder',
			$ext->get_uri() . '/static/css/email-builder.css',
			array( 'fw' ),
			$version
		);

		wp_enqueue_script(
			'fw-builder-email-builder',
			$ext->get_uri() . '/static/js/email-builder.js',
			// 'backbone' dropped: email-builder.js contains no Backbone call.
			array( 'fw-events' ),
			$version,
			true
		);

		// Every block must be registered client-side too, or the canvas logs
		// "Cannot detect Item type" and renders nothing for a saved tree. One
		// generic JS registration is driven by this data rather than four
		// near-identical scripts.
		$types = array();

		foreach ( $this->get_blocks() as $type => $block ) {
			$types[ $type ] = array(
				'title'    => $block->get_title(),
				'icon'     => $block->get_icon_svg(),
				'options'  => $block->get_options(),
				// Which saved values the canvas summarises. Supplied per block so
				// the JS stays generic instead of carrying a switch that has to
				// grow with every new block type.
				'preview'  => $block->get_preview_keys(),
				'defaults' => array(
					'type'    => $type,
					// New blocks start full width; the width changer edits this.
					'width'   => '1_1',
					'options' => fw_get_options_values_from_input( $block->get_options(), array() ),
				),
			);
		}

		wp_localize_script( 'fw-builder-email-builder', 'fwCrmEmailBuilder', array(
			'types' => $types,
			'l10n'  => array(
				'edit'   => __( 'Edit block', 'fw' ),
				'remove' => __( 'Remove block', 'fw' ),
				'empty'  => __( 'Not set yet — click to edit', 'fw' ),
				'line'   => __( 'Horizontal line', 'fw' ),
				'space'  => __( 'Blank space', 'fw' ),
			),
		) );
	}
}

FW_Option_Type::register( 'FW_Option_Type_Email_Builder' );
