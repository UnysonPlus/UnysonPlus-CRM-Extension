<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Starter templates for the Email Builder.
 *
 * These are offered by the framework's own builder template library alongside
 * whatever the user has saved — we supply the starters, it owns the panel, the
 * saving, the loading and the export/import.
 *
 * Two decisions worth not re-litigating:
 *
 * 1. **They are authored as PHP, not frozen JSON.** A template stores option
 *    VALUES, so it goes stale the moment a block's options change — the same
 *    trap the theme Preset Library has. Expressed as code, a renamed option is
 *    a visible edit in a reviewed file; pasted JSON blobs rot silently and are
 *    only discovered by a user opening a broken template.
 *
 * 2. **They are deliberately plain.** A starter exists to save someone the
 *    assembly, not to impose a look — every colour left empty inherits the
 *    campaign default, so a site's own palette shows through instead of being
 *    overridden by whatever we picked here. Text is written to be replaced.
 */
class FW_CRM_Email_Templates {

	/**
	 * @internal
	 * @param array $templates
	 *
	 * @return array
	 */
	public static function _filter_predefined( $templates ) {
		foreach ( self::all() as $id => $template ) {
			// The framework's contract: array( 'title' => string, 'json' => string ).
			// The json is the INNER tree string, which is what the canvas loads —
			// `to_option_value()` wraps it as array( 'json' => … ) for an option
			// value, and a template stores only the string half of that.
			$value = FW_CRM_Email_Compiler::to_option_value( self::blocks( $template['blocks'] ) );

			$templates[ 'fw-crm-' . $id ] = array(
				'title' => $template['title'],
				'json'  => $value['json'],
			);
		}

		return $templates;
	}

	/**
	 * Every starter, keyed by id.
	 *
	 * @return array
	 */
	public static function all() {
		$templates = array(
			'announcement' => array(
				'title'  => __( 'Announcement', 'fw' ),
				'blocks' => self::announcement(),
			),
			'newsletter'   => array(
				'title'  => __( 'Newsletter digest', 'fw' ),
				'blocks' => self::newsletter(),
			),
			'promo'        => array(
				'title'  => __( 'Product promotion', 'fw' ),
				'blocks' => self::promo(),
			),
			'welcome'      => array(
				'title'  => __( 'Welcome', 'fw' ),
				'blocks' => self::welcome(),
			),
			'event'        => array(
				'title'  => __( 'Event invitation', 'fw' ),
				'blocks' => self::event(),
			),
			'letter'       => array(
				'title'  => __( 'Plain letter', 'fw' ),
				'blocks' => self::letter(),
			),
		);

		/**
		 * Filters the list of starter templates offered in the Email Builder.
		 *
		 * Add or remove Email Builder starter templates.
		 *
		 * @param array $templates
		 */
		return apply_filters( 'unysonplus_crm_email_templates', $templates );
	}

	/* ---------------------------------------------------------------------- *
	 * The starters
	 * ---------------------------------------------------------------------- */

	/**
	 * One thing to say, one thing to click.
	 */
	private static function announcement() {
		return array(
			self::logo(),
			self::heading( __( 'Something new', 'fw' ) ),
			self::text( __( '<p>Hi {{first_name}}, replace this with the one thing you want people to know. Keep it to a short paragraph — the button below is what you actually want them to press.</p>', 'fw' ) ),
			self::button( __( 'Read the announcement', 'fw' ) ),
			self::spacer( '8' ),
			self::footer(),
		);
	}

	/**
	 * Several items, each with a heading, a summary and a link.
	 */
	private static function newsletter() {
		$blocks = array(
			self::logo(),
			self::heading( __( 'This month', 'fw' ) ),
			self::text( __( '<p>Hi {{first_name}}, here is what we have been up to.</p>', 'fw' ) ),
			self::divider(),
		);

		// Three stubs rather than one: a digest with a single item does not look
		// like a digest, and deleting a block is quicker than building one.
		for ( $i = 1; $i <= 3; $i++ ) {
			$blocks[] = self::heading(
				sprintf(
					/* translators: %d: item number */
					__( 'Story %d', 'fw' ),
					$i
				),
				'h3'
			);
			$blocks[] = self::text( __( '<p>A sentence or two on what this is and why it is worth a click.</p>', 'fw' ) );
			$blocks[] = self::button( __( 'Read more', 'fw' ) );

			if ( 3 !== $i ) {
				$blocks[] = self::divider();
			}
		}

		$blocks[] = self::footer();

		return $blocks;
	}

	/**
	 * A hero, the pitch, the offer.
	 */
	private static function promo() {
		return array(
			self::logo(),
			array(
				'type'    => 'hero',
				'options' => array(
					'heading'      => __( 'The new collection', 'fw' ),
					'text'         => __( 'A line that makes the offer clear.', 'fw' ),
					'button_label' => __( 'Shop now', 'fw' ),
					'button_url'   => '',
					'bg_color'     => '#1d2327',
					'text_color'   => '#ffffff',
				),
			),
			self::text( __( '<p>Hi {{first_name}}, say what it is, who it suits and why now. Two or three sentences is plenty — the hero above already made the offer.</p>', 'fw' ) ),
			array(
				'type'    => 'table',
				'options' => array(
					'rows'   => __( "What | Details\nOffer | 20% off everything\nCode | EXAMPLE20\nEnds | Sunday", 'fw' ),
					'header' => 'yes',
				),
			),
			self::button( __( 'Shop the collection', 'fw' ) ),
			self::footer(),
		);
	}

	/**
	 * The first email after someone confirms.
	 */
	private static function welcome() {
		return array(
			self::logo(),
			self::heading( __( 'Welcome, {{first_name}}', 'fw' ) ),
			self::text( __( '<p>Thanks for subscribing. Here is what to expect, and how often — setting that expectation now is the single best thing you can do for your unsubscribe rate.</p>', 'fw' ) ),
			self::divider(),
			self::heading( __( 'Start here', 'fw' ), 'h3' ),
			self::text( __( '<p>Point people at the one or two things worth seeing first.</p>', 'fw' ) ),
			self::button( __( 'Take a look', 'fw' ) ),
			self::spacer( '8' ),
			array(
				'type'    => 'social',
				'options' => array(
					'links' => array(
						array( 'label' => __( 'Facebook', 'fw' ), 'url' => '' ),
						array( 'label' => __( 'Instagram', 'fw' ), 'url' => '' ),
					),
				),
			),
			self::footer(),
		);
	}

	/**
	 * What, when, where, and a way to say yes.
	 */
	private static function event() {
		return array(
			self::logo(),
			self::heading( __( 'You are invited', 'fw' ) ),
			self::text( __( '<p>Hi {{first_name}}, a short line on what the event is and why it is worth an evening.</p>', 'fw' ) ),
			array(
				'type'    => 'table',
				'options' => array(
					'rows'    => __( "When | Thursday 7 March, 6:30pm\nWhere | Somewhere good\nCost | Free", 'fw' ),
					'header'  => 'no',
					'borders' => 'yes',
				),
			),
			self::button( __( 'Save me a place', 'fw' ) ),
			self::spacer( '8' ),
			self::text( __( '<p>Can\'t make it? Reply and let us know — we will send the recording.</p>', 'fw' ) ),
			self::footer(),
		);
	}

	/**
	 * No design at all. Sometimes the most effective email is the one that looks
	 * like a person typed it, so this is the deliberate absence of a template.
	 */
	private static function letter() {
		return array(
			self::text( __( '<p>Hi {{first_name}},</p>', 'fw' ) ),
			self::text( __( '<p>Write as you would to one person. No logo, no buttons, no columns — a plain letter often outperforms a designed one, and it renders identically everywhere.</p>', 'fw' ) ),
			self::text( __( '<p>Thanks,<br />Your name</p>', 'fw' ) ),
			self::footer(),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Block shorthands — keep the starters readable
	 * ---------------------------------------------------------------------- */

	private static function logo() {
		return array( 'type' => 'logo', 'options' => array( 'width' => '160' ) );
	}

	private static function heading( $text, $level = 'h2' ) {
		return array( 'type' => 'heading', 'options' => array( 'text' => $text, 'level' => $level ) );
	}

	private static function text( $content ) {
		return array( 'type' => 'text', 'options' => array( 'content' => $content ) );
	}

	private static function button( $label ) {
		return array( 'type' => 'button', 'options' => array( 'label' => $label, 'url' => '' ) );
	}

	private static function divider() {
		return array( 'type' => 'divider', 'options' => array() );
	}

	private static function spacer( $height ) {
		return array( 'type' => 'spacer', 'options' => array( 'height' => $height ) );
	}

	/**
	 * Every starter ends with one. The postal address is a legal requirement for
	 * bulk mail in most places, so a starter that omitted it would be teaching
	 * people to send non-compliant email.
	 */
	private static function footer() {
		return array(
			'type'    => 'footer',
			'options' => array(
				'note'    => __( 'You are receiving this because you subscribed at {{site_name}}.', 'fw' ),
				'address' => '',
			),
		);
	}

	/**
	 * Normalise a starter into the tree shape the canvas loads.
	 *
	 * Deliberately no ids: the builder is Backbone and mints its own `cid` per
	 * item on load, so an id baked in here would be dead weight that the canvas
	 * ignores. Only `width` needs filling in, because a block with no width is
	 * not full-width — it is undefined.
	 *
	 * Runs through the compiler's own `to_option_value()` so a starter is built
	 * by exactly the code that loads a saved campaign. If that shape ever
	 * changes, the starters follow it rather than quietly becoming the one tree
	 * shape nothing else produces.
	 *
	 * @param array $blocks
	 *
	 * @return array
	 */
	private static function blocks( array $blocks ) {
		foreach ( $blocks as &$block ) {
			if ( ! isset( $block['width'] ) ) {
				$block['width'] = '1_1';
			}
		}

		unset( $block );

		return $blocks;
	}
}
