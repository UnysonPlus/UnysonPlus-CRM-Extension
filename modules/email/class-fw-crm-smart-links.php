<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Smart links: a link in a campaign that tags the contact who clicks it, so a click on
 * "See pricing" can put someone in a "pricing" segment or start a follow-up.
 *
 * The tags ride in the link's own address as a marker fragment, `#crm-tag=pricing,hot`,
 * set by the Button block's "Tag on click" field or typed onto any link. Click tracking
 * stores the full address once (FW_CRM_Tracking::link_id), so the marker needs no table of
 * its own. On a click by a person (scanners and prefetches are left out) the tags are added;
 * the marker is always removed before anyone is sent to the address, and from links in
 * emails that are not tracked, so a reader never sees it.
 */
class FW_CRM_Smart_Links {

	const MARK = 'crm-tag';

	public static function init() {
		add_action( 'unysonplus_crm_email_event', array( __CLASS__, '_on_event' ), 10, 4 );
	}

	/**
	 * A link with tags added on click.
	 *
	 * @param string          $url
	 * @param string|string[] $tags
	 *
	 * @return string
	 */
	public static function with_tags( $url, $tags ) {
		$tags = self::clean( $tags );
		$url  = self::strip( (string) $url );
		return $tags ? $url . '#' . self::MARK . '=' . implode( ',', $tags ) : $url;
	}

	/**
	 * The tags a link adds.
	 *
	 * @param string $url
	 *
	 * @return string[]
	 */
	public static function tags_in( $url ) {
		return preg_match( '/#' . self::MARK . '=([^"\'\s<>#]*)/', html_entity_decode( (string) $url, ENT_QUOTES, 'UTF-8' ), $m ) ? self::clean( rawurldecode( $m[1] ) ) : array();
	}

	/**
	 * Remove every tag marker from a link or a whole email.
	 *
	 * @param string $text
	 *
	 * @return string
	 */
	public static function strip( $text ) {
		return (string) preg_replace( '/#' . self::MARK . '=[^"\'\s<>#]*/', '', (string) $text );
	}

	/**
	 * @internal A person clicked a tracked link (the hook only fires for counted events, never scanners).
	 */
	public static function _on_event( $type, $row, $link_id, $ctx = array() ) {
		if ( 'click' !== $type || ! $link_id || empty( $row->subscriber_id ) ) {
			return;
		}
		$tags = self::tags_in( FW_CRM_Tracking::link_url( (int) $link_id ) );
		if ( ! $tags ) {
			return;
		}
		FW_CRM_Tags::add( (int) $row->subscriber_id, $tags, 'smart_link' );
		/** Fires after a smart link tagged the contact who clicked it. */
		do_action( 'unysonplus_crm_smart_link_clicked', (int) $row->subscriber_id, $tags, (int) $row->campaign_id );
	}

	/**
	 * @param string|string[] $tags
	 *
	 * @return string[] Slugs.
	 */
	private static function clean( $tags ) {
		$list = is_array( $tags ) ? $tags : explode( ',', (string) $tags );
		return array_values( array_unique( array_filter( array_map( 'sanitize_title', array_map( 'trim', $list ) ) ) ) );
	}
}
