<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Base class for every email-builder block.
 *
 * Modelled on the Learning extension's quiz-builder items, which are the closest
 * precedent in the codebase: a builder whose items render their OWN bespoke HTML
 * from the item class, rather than delegating to shortcodes the way the page
 * builder does. That is exactly what email needs, because shortcode output is
 * div/flex/CSS-class markup and email cannot use any of it.
 *
 * A block therefore implements one extra method beyond the framework's four:
 * compile(), which returns table-based HTML with INLINE styles.
 */
abstract class FW_CRM_Email_Item extends FW_Option_Type_Builder_Item {

	/** @var array Set by the subclass in _init() via set_options(). */
	private $options = array();

	/**
	 * The values of the block currently being compiled, set by `render()` so the
	 * shared helpers can reach them. Empty at every other moment.
	 *
	 * @var array
	 */
	private $current_atts = array();

	/** @var string Inline SVG path markup, recorded by thumbnail(). */
	protected $icon = '';

	/** @var string Human label, recorded by thumbnail(). */
	protected $title = '';

	/**
	 * {@inheritdoc}
	 */
	final public function get_builder_type() {
		return 'email-builder';
	}

	/**
	 * Compile this block to email HTML.
	 *
	 * MUST return a SELF-CONTAINED table — not a bare <tr> — because the compiler
	 * places a block either in a full-width row or inside a column, and only a
	 * self-contained table works in both. Use wrap_block() and you get that for
	 * free. (This is the same reason MJML makes every component a table.)
	 *
	 * Inline styles only: no <style> dependency, no classes, no flex, no float.
	 * Outlook renders with the Word engine and Gmail strips <style> in clipped
	 * views, so anything else is a coin toss.
	 *
	 * @param array $atts The block's saved values.
	 * @param array $ctx  Resolved global styles (font, colours, width…).
	 *
	 * @return string
	 */
	abstract public function compile( array $atts, array $ctx );

	/**
	 * Wrap a block's content in its own full-width table.
	 *
	 * @param string $content
	 * @param int    $padding
	 * @param string $align
	 * @param array  $cell_styles Extra declarations for the content cell.
	 *
	 * @return string
	 */
	protected function wrap_block( $content, $padding = 12, $align = 'left', array $cell_styles = array() ) {
		if ( '' === $content ) {
			return '';
		}

		$style = $this->style( array_merge( array(
			'padding'    => (int) $padding . 'px',
			'text-align' => $align,
		), $cell_styles ) );

		// The author's own declarations go LAST so they win over ours — an escape
		// hatch that loses to the defaults it is meant to escape is no use.
		$style = $this->merge_extra_styles( $style );

		return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"'
			. ' style="width:100%;border-collapse:collapse">'
			. '<tr><td align="' . esc_attr( $align ) . '" style="' . esc_attr( $style ) . '">'
			. $content
			. '</td></tr></table>';
	}

	/**
	 * Blocks need no front-end JS of their own — their settings render in the
	 * builder's shared options modal. Declared here so no subclass has to write
	 * an empty method.
	 *
	 * @internal
	 */
	public function enqueue_static() {
		/**
		 * Enqueue the STATIC OF THIS BLOCK'S OPTIONS, not just the block's own
		 * assets. This is load-bearing and easy to miss.
		 *
		 * A block's options modal is rendered by the `fw_backend_options_render`
		 * ajax action, which returns HTML **and nothing else** — no scripts, no
		 * styles. So an option type whose JS is not already on the page renders
		 * as inert markup: wp-editor comes up as a bare textarea with a dead
		 * Visual tab (TinyMCE never initialises because the option type's
		 * `fw:options:init` handler was never loaded), color-picker as an empty
		 * box, upload as a dead button.
		 *
		 * The Forms extension's builder items do exactly this for the same
		 * reason. Putting it on the base class means every block gets it, and no
		 * future block can forget.
		 */
		fw()->backend->enqueue_options_static( $this->get_options() );
	}

	/**
	 * @param array $options
	 */
	final protected function set_options( array $options ) {
		$this->options = $options;
	}

	/**
	 * {@inheritdoc}
	 */
	final public function get_options() {
		// Appended centrally rather than declared in fourteen `_init()` methods,
		// so no block can be built without it and none can drift.
		return array_merge( $this->options, array(
			'group_advanced' => array(
				'type'    => 'group',
				'options' => array(
					'extra_styles' => $this->extra_styles_option(),
				),
			),
		) );
	}

	/**
	 * A per-block escape hatch for CSS the block's own options don't expose.
	 *
	 * INLINE DECLARATIONS ONLY — `letter-spacing:1px; text-transform:uppercase`,
	 * not `.thing { … }`. That is not a simplification, it is the only honest
	 * option. A page builder's Custom CSS writes a scoped RULE into a stylesheet;
	 * an email has no stylesheet worth depending on. Gmail drops everything past
	 * ~102 KB (taking a `<style>` block with it) and styles are commonly stripped
	 * on forward, so a rule here would work while the author tested it and
	 * silently vanish for part of their list — with no way to tell. Inline styles
	 * are the one layer every client honours, so that is the layer this writes to.
	 *
	 * @return array
	 */
	protected function extra_styles_option() {
		return array(
			'type'  => 'textarea',
			'label' => __( 'Extra styles', 'fw' ),
			'desc'  => __( 'CSS declarations added to this block, e.g. letter-spacing:1px; text-transform:uppercase. Declarations only — selectors and { } are ignored, because email has no reliable stylesheet and a rule would silently do nothing for many readers.', 'fw' ),
			'value' => '',
			'attr'  => array( 'rows' => 2, 'placeholder' => 'letter-spacing:1px; text-transform:uppercase' ),
		);
	}

	/**
	 * Sanitise author-supplied declarations.
	 *
	 * Allow-list by shape rather than blocklist by keyword: every part must look
	 * like `property: value`, which rejects rules, at-rules and stray braces as a
	 * side effect of the format rather than as special cases to remember.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function sanitize_extra_styles( $value ) {
		$value = trim( (string) $value );

		if ( '' === $value ) {
			return '';
		}

		// Markup and CSS comments never belong in a style attribute; a comment can
		// also be used to smuggle the rest of a payload past a naive parser.
		$value = preg_replace( '#/\*.*?\*/#s', '', $value );
		$value = str_replace( array( '<', '>' ), '', $value );

		$out = array();

		foreach ( explode( ';', $value ) as $decl ) {
			if ( ! preg_match( '/^\s*([a-zA-Z-]+)\s*:\s*(.+?)\s*$/', $decl, $m ) ) {
				continue; // Not a declaration — a selector, a brace, or noise.
			}

			$prop = strtolower( $m[1] );
			$val  = $m[2];

			// Braces mean a rule was pasted in. Dropping the declaration is right:
			// applying half of somebody's rule would be worse than ignoring it.
			if ( false !== strpos( $decl, '{' ) || false !== strpos( $decl, '}' ) ) {
				continue;
			}

			// The genuinely dangerous CSS: legacy IE script execution, javascript:
			// URLs, and @import / behavior pulling in a remote resource.
			if ( preg_match( '/expression\s*\(|javascript\s*:|vbscript\s*:|behaviou?r\s*:|@import|data\s*:\s*text\/html/i', $prop . ':' . $val ) ) {
				continue;
			}

			$out[] = $prop . ':' . $val;
		}

		return implode( ';', $out );
	}

	/**
	 * The sanitised extra styles for the block currently being compiled.
	 *
	 * @return string
	 */
	protected function extra_styles() {
		return isset( $this->current_atts['extra_styles'] )
			? $this->sanitize_extra_styles( $this->current_atts['extra_styles'] )
			: '';
	}

	/**
	 * Append the author's declarations to a style string.
	 *
	 * @param string $style
	 *
	 * @return string
	 */
	protected function merge_extra_styles( $style ) {
		$extra = $this->extra_styles();

		if ( '' === $extra ) {
			return $style;
		}

		return '' === $style ? $extra : rtrim( $style, ';' ) . ';' . $extra;
	}

	/**
	 * Compile a block, remembering its values for the duration.
	 *
	 * The compiler calls this rather than `compile()` directly, so that shared
	 * helpers — `wrap_block()` above — can reach the block's own values without
	 * every one of the fourteen `compile()` methods having to thread them
	 * through by hand. Cleared afterwards so nothing leaks between blocks.
	 *
	 * @param array $atts
	 * @param array $ctx
	 *
	 * @return string
	 */
	final public function render( array $atts, array $ctx ) {
		$this->current_atts = $atts;

		try {
			return $this->compile( $atts, $ctx );
		} finally {
			$this->current_atts = array();
		}
	}

	/**
	 * Shared padding control — every block has one, so it lives here rather than
	 * being copy-pasted four times.
	 *
	 * @param string $default
	 *
	 * @return array
	 */
	protected function padding_option( $default = '12' ) {
		return $this->px_option(
			__( 'Padding', 'fw' ),
			$default,
			__( 'Space around this block. Email supports pixels only.', 'fw' )
		);
	}

	/**
	 * A pixel measurement control.
	 *
	 * `unit-input` with px as the ONLY unit — deliberately. Email has no
	 * viewport units, no rem (Outlook resolves neither) and percentages are
	 * unreliable inside table cells, so px is the only length the whole client
	 * matrix agrees on. The unit picker still earns its place: it labels the
	 * number, which a bare text field could only do by putting "(px)" in the
	 * option label.
	 *
	 * @param string $label
	 * @param string $default Empty string = "inherit / unset".
	 * @param string $desc
	 *
	 * @return array
	 */
	protected function px_option( $label, $default = '', $desc = '' ) {
		return array(
			'type'  => 'unit-input',
			'label' => $label,
			'desc'  => $desc,
			'units' => array( 'px' ),
			'value' => array( 'value' => $default, 'unit' => 'px' ),
			'min'   => 0,
		);
	}

	/**
	 * Which saved option(s) the canvas should show as this block's one-line
	 * summary, best first. Keeps the builder JS generic — it reads these rather
	 * than carrying a switch over every block type.
	 *
	 * @return array
	 */
	public function get_preview_keys() {
		return array();
	}

	/**
	 * Human label — used by the item tray, the canvas and the options modal, so
	 * a block names itself in exactly one place.
	 *
	 * @return string
	 */
	public function get_title() {
		$this->ensure_meta();

		return $this->title;
	}

	/**
	 * The block's icon, as inline SVG. Shown in the tray and on the canvas.
	 *
	 * @return string
	 */
	public function get_icon_svg() {
		$this->ensure_meta();

		return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" '
			. 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
			. $this->icon
			. '</svg>';
	}

	/**
	 * Build the thumbnail box shown in the builder's item tray. Also records the
	 * label and icon so get_title() / get_icon_svg() need no duplication.
	 *
	 * @param string $svg   Inline SVG path markup (24x24 viewBox).
	 * @param string $label
	 *
	 * @return array
	 */
	/**
	 * The label and icon are recorded as a side effect of get_thumbnails(), which
	 * the framework may not have called yet when we localize the JS — so make
	 * sure it has.
	 */
	private function ensure_meta() {
		if ( '' === $this->title ) {
			$this->get_thumbnails();
		}
	}

	protected function thumbnail( $svg, $label ) {
		$this->icon  = $svg;
		$this->title = $label;

		return array(
			array(
				'html' =>
					'<div class="fw-crm-eb-thumb" data-hover-tip="' . esc_attr( $label ) . '">'
					. '<span class="fw-crm-eb-thumb__icon">'
					. '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="22" height="22" '
					. 'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'
					. $svg
					. '</svg>'
					. '</span>'
					. '<span class="fw-crm-eb-thumb__label">' . esc_html( $label ) . '</span>'
					. '</div>',
			),
		);
	}

	/* ---------------------------------------------------------------------- *
	 * Compile helpers, shared by every block
	 * ---------------------------------------------------------------------- */

	/**
	 * Turn an array of CSS declarations into an inline style attribute value,
	 * dropping empties so blocks can pass conditionals without guarding.
	 *
	 * @param array $rules [ 'color' => '#333', 'font-size' => '' ]
	 *
	 * @return string
	 */
	protected function style( array $rules ) {
		$out = array();

		foreach ( $rules as $property => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			$out[] = $property . ':' . $value;
		}

		return implode( ';', $out );
	}

	/**
	 * @param mixed $value
	 * @param int   $default
	 *
	 * @return int
	 */
	protected function px( $value, $default = 0 ) {
		// A `unit-input` stores array( 'value' => …, 'unit' => 'px' ); campaigns
		// saved before those fields became unit inputs hold a bare string. Both
		// shapes must keep working — a stored tree is never migrated, it is just
		// read by whatever version is running.
		if ( is_array( $value ) ) {
			$value = isset( $value['value'] ) ? $value['value'] : '';
		}

		return '' === $value || null === $value ? (int) $default : (int) $value;
	}

	/**
	 * left|center|right, defaulting safely.
	 *
	 * @param mixed  $value
	 * @param string $default
	 *
	 * @return string
	 */
	protected function align( $value, $default = 'left' ) {
		return in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : $default;
	}

	/**
	 * The URL out of an `upload` option value, which stores an array.
	 *
	 * @param mixed $value
	 *
	 * @return string
	 */
	protected function image_url( $value ) {
		if ( is_array( $value ) ) {
			return isset( $value['url'] ) ? (string) $value['url'] : '';
		}

		return (string) $value;
	}

	/**
	 * A reusable left/center/right control.
	 *
	 * @param string $default
	 *
	 * @return array
	 */
	protected function align_option( $default = 'left' ) {
		// The same `image-picker` swatch control the shortcodes use, so alignment
		// looks and behaves identically wherever it appears in the plugin.
		//
		// The swatches are COPIED into this extension rather than borrowed from
		// the shortcodes extension via `sc_alignment_field()`. The CRM is
		// documented to work with the shortcodes extension inactive, and a
		// control that silently degrades from swatches to radios depending on
		// what else is switched on is worse than either one consistently. They
		// are three ~300-byte SVGs. The `image-picker` option type itself is
		// core framework, so nothing here is a cross-extension dependency.
		//
		// Unlike the shortcode version there is deliberately no "Default" swatch:
		// that one means "inherit from the theme/parent", and an email has no
		// such cascade — each block has a concrete default instead. Offering it
		// would be a control that does nothing.
		$base = $this->alignment_uri();

		$swatch = function ( $file, $title ) use ( $base ) {
			return array( 'small' => array( 'src' => $base . '/' . $file, 'height' => 40, 'title' => $title ) );
		};

		return array(
			'type'    => 'image-picker',
			'label'   => __( 'Alignment', 'fw' ),
			'value'   => $default,
			'choices' => array(
				'left'   => $swatch( 'left.svg', __( 'Left', 'fw' ) ),
				'center' => $swatch( 'center.svg', __( 'Center', 'fw' ) ),
				'right'  => $swatch( 'right.svg', __( 'Right', 'fw' ) ),
			),
		);
	}

	/**
	 * Base URI of the alignment swatches.
	 *
	 * @return string
	 */
	protected function alignment_uri() {
		$ext = fw()->extensions->get( 'crm' );

		return $ext ? $ext->get_uri( '/static/img/alignment' ) : '';
	}

	/**
	 * Wrap content in a link when a URL is set, otherwise return it untouched.
	 *
	 * @param string $content
	 * @param string $url
	 * @param string $style
	 *
	 * @return string
	 */
	protected function maybe_link( $content, $url, $style = '' ) {
		$url = trim( (string) $url );

		if ( '' === $url ) {
			return $content;
		}

		return '<a href="' . esc_url( $url ) . '" target="_blank"'
			. ( '' !== $style ? ' style="' . esc_attr( $style ) . '"' : '' )
			. '>' . $content . '</a>';
	}
}
