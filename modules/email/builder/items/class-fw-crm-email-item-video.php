<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Video block — which is to say, a linked thumbnail.
 *
 * No email client plays video reliably; embeds are stripped and HTML5 <video>
 * is honoured almost nowhere. Every mature builder ships this as a still image
 * that links out, and so does this one — the block is named Video because that
 * is what the author is thinking, but the options say plainly what it produces.
 *
 * A play badge is drawn as its own centred row rather than overlaid on the
 * image: absolute positioning does not survive Outlook, so a genuine overlay
 * would land on top of the thumbnail in some clients and beside it in others.
 * Authors who want a true overlay should bake it into the thumbnail itself,
 * which the description says.
 */
class FW_CRM_Email_Item_Video extends FW_CRM_Email_Item {

	/**
	 * @internal
	 */
	public function _init() {
		$this->set_options( array(
			'group_content' => array(
				'type'    => 'group',
				'options' => array(
					'thumbnail' => array(
						'type'  => 'upload',
						'label' => __( 'Thumbnail', 'fw' ),
						'desc'  => __( 'Email cannot play video, so this image is what recipients see. If you want a play button drawn over the frame, bake it into the image — overlays do not survive Outlook.', 'fw' ),
					),
					'url'       => array(
						'type'  => 'text',
						'label' => __( 'Video URL', 'fw' ),
						'desc'  => __( 'Where the thumbnail links to — YouTube, Vimeo, or a page on your site.', 'fw' ),
						'value' => '',
						'attr'  => array( 'placeholder' => 'https://' ),
					),
					'alt'       => array(
						'type'  => 'text',
						'label' => __( 'Alt text', 'fw' ),
						'value' => __( 'Watch the video', 'fw' ),
					),
					'caption'   => array(
						'type'  => 'text',
						'label' => __( 'Caption link', 'fw' ),
						'desc'  => __( 'Shown under the thumbnail. Images are blocked by default in many clients, so this is often the only thing a recipient can click.', 'fw' ),
						'value' => __( '▶ Watch the video', 'fw' ),
					),
				),
			),
			'group_style' => array(
				'type'    => 'group',
				'options' => array(
					'width'     => $this->px_option( __( 'Width', 'fw' ), '' ),
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
		return 'video';
	}

	/** {@inheritdoc} */
	public function get_preview_keys() {
		return array( 'url', 'caption' );
	}

	/** {@inheritdoc} */
	public function get_thumbnails() {
		return $this->thumbnail(
			'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M11 9.5l4 2.5-4 2.5z"/>',
			__( 'Video', 'fw' )
		);
	}

	/** {@inheritdoc} */
	public function compile( array $atts, array $ctx ) {
		$src     = isset( $atts['thumbnail'] ) ? $this->image_url( $atts['thumbnail'] ) : '';
		$url     = isset( $atts['url'] ) ? trim( (string) $atts['url'] ) : '';
		$caption = isset( $atts['caption'] ) ? trim( (string) $atts['caption'] ) : '';

		if ( '' === $src && '' === $caption ) {
			return '';
		}

		$padding = $this->px( isset( $atts['padding'] ) ? $atts['padding'] : '', 12 );
		$align   = $this->align( isset( $atts['align'] ) ? $atts['align'] : '', 'center' );
		$max     = max( 1, (int) $ctx['content_width'] - ( $padding * 2 ) );
		$width   = $this->px( isset( $atts['width'] ) ? $atts['width'] : '', 0 );
		$width   = $width > 0 ? min( $width, $max ) : $max;

		$out = '';

		if ( '' !== $src ) {
			$img = '<img src="' . esc_url( $src ) . '"'
				. ' alt="' . esc_attr( isset( $atts['alt'] ) ? $atts['alt'] : '' ) . '"'
				. ' width="' . (int) $width . '"'
				. ' style="display:block;width:100%;max-width:' . (int) $width . 'px;height:auto;border:0" border="0" />';

			$out .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="' . esc_attr( $align ) . '"'
				. ' style="border-collapse:collapse"><tr><td style="padding:0">'
				. $this->maybe_link( $img, $url, 'text-decoration:none' )
				. '</td></tr></table>';
		}

		if ( '' !== $caption ) {
			$style = $this->style( array(
				'margin'      => '8px 0 0',
				'font-family' => $ctx['font_family'],
				'font-size'   => '14px',
				'color'       => $ctx['link_color'],
			) );

			$out .= '<p style="' . esc_attr( $style ) . '">'
				. $this->maybe_link( esc_html( $caption ), $url, 'color:' . $ctx['link_color'] . ';text-decoration:underline' )
				. '</p>';
		}

		return $this->wrap_block( $out, $padding, $align );
	}
}

FW_Option_Type_Builder::register_item_type( 'FW_CRM_Email_Item_Video' );
