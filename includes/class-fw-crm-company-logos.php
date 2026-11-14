<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Company logos.
 *
 * A company's logo is its own website's icon (the apple-touch-icon, else the
 * largest favicon), fetched once on the server and kept in
 * uploads/unysonplus/crm-logos/, or a picture someone uploads on the company
 * page. The CRM never asks a third-party logo service: that would send the whole
 * company list to that service every time the CRM is opened.
 *
 * The `logo` column holds the logo's URL, '' for "not looked for yet / none
 * found", or 'none' when someone removed it on purpose (then it is never
 * fetched again automatically). File names say who set them: <id>-a-<hash>.<ext>
 * was fetched, <id>-m-<hash>.<ext> was uploaded; a fetched logo is replaced when
 * the website changes, an uploaded one never is.
 *
 * Fetching runs in the background, a few companies per WP-Cron run, so adding or
 * importing hundreds of companies never slows a request down.
 */
class FW_CRM_Company_Logos {

	const QUEUE_OPTION = 'fw_crm_logo_queue';
	const BATCH_HOOK   = 'fw_crm_company_logos_batch';
	const PER_RUN      = 5;
	const MAX_BYTES    = 1048576; // 1 MB per candidate download.
	const SIZE         = 128;     // Stored logos are at most 128×128.

	public static function init() {
		add_action( 'unysonplus_crm_company_created', array( __CLASS__, '_on_created' ) );
		add_action( 'unysonplus_crm_company_updated', array( __CLASS__, '_on_updated' ), 10, 2 );
		add_action( 'unysonplus_crm_company_deleted', array( __CLASS__, '_on_deleted' ) );
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ) );
	}

	/**
	 * The folder the logos live in.
	 *
	 * @return array { path, url } without a trailing slash.
	 */
	public static function dir() {
		if ( function_exists( 'fw_upw_uploads_dir' ) ) {
			return fw_upw_uploads_dir( 'crm-logos' );
		}
		$up = wp_upload_dir();
		return array(
			'path' => wp_normalize_path( trailingslashit( $up['basedir'] ) . 'unysonplus/crm-logos' ),
			'url'  => trailingslashit( $up['baseurl'] ) . 'unysonplus/crm-logos',
		);
	}

	/**
	 * The URL to show for a company, or '' when it has none.
	 *
	 * @param object|null $company
	 *
	 * @return string
	 */
	public static function url( $company ) {
		$logo = $company && isset( $company->logo ) ? (string) $company->logo : '';
		return ( '' === $logo || 'none' === $logo ) ? '' : $logo;
	}

	/**
	 * Was this logo uploaded by hand (so a website change must not replace it)?
	 *
	 * @param string $logo
	 *
	 * @return bool
	 */
	public static function is_manual( $logo ) {
		return (bool) preg_match( '#/\d+-m-[0-9a-f]+\.[a-z]+$#', (string) $logo );
	}

	/* ---------------------------------------------------------------------- *
	 * Hooks and the background queue
	 * ---------------------------------------------------------------------- */

	/** A new company with a website: queue its logo. */
	public static function _on_created( $company ) {
		if ( $company && '' === (string) $company->logo && '' !== (string) $company->domain ) {
			self::queue( array( (int) $company->id ) );
		}
	}

	/** The website changed: fetch again, unless the logo was uploaded or removed on purpose. */
	public static function _on_updated( $company, $before ) {
		if ( ! $company || ! $before || (string) $company->domain === (string) $before->domain || '' === (string) $company->domain ) {
			return;
		}
		$logo = (string) $company->logo;
		if ( 'none' === $logo || self::is_manual( $logo ) ) {
			return;
		}
		// The fetched logo belonged to the old website: drop it, then look again.
		if ( '' !== $logo ) {
			self::delete_files( (int) $company->id );
			FW_CRM_Companies::update( (int) $company->id, array( 'logo' => '' ) );
		}
		self::queue( array( (int) $company->id ) );
	}

	/** A company deleted for good takes its logo file with it. */
	public static function _on_deleted( $company ) {
		if ( $company ) {
			self::delete_files( (int) $company->id );
		}
	}

	/**
	 * Add companies to the background queue and make sure a batch is scheduled.
	 *
	 * @param int[] $ids
	 */
	public static function queue( array $ids ) {
		$queue = array_values( array_unique( array_merge( (array) get_option( self::QUEUE_OPTION, array() ), array_map( 'intval', $ids ) ) ) );
		update_option( self::QUEUE_OPTION, $queue, false );
		if ( $queue && ! wp_next_scheduled( self::BATCH_HOOK ) ) {
			wp_schedule_single_event( time() + 10, self::BATCH_HOOK );
		}
	}

	/**
	 * Queue every company that has a website and no logo yet.
	 *
	 * @return int How many were queued.
	 */
	public static function queue_missing() {
		global $wpdb;
		$ids = $wpdb->get_col( 'SELECT id FROM ' . FW_CRM_Companies::table() . " WHERE deleted_at IS NULL AND domain <> '' AND logo = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			self::queue( $ids );
		}
		return count( $ids );
	}

	/** How many companies are still waiting for their logo. */
	public static function pending() {
		return count( (array) get_option( self::QUEUE_OPTION, array() ) );
	}

	/** One WP-Cron run: a few companies, then the next run if any are left. */
	public static function run_batch() {
		$queue = (array) get_option( self::QUEUE_OPTION, array() );
		$now   = array_splice( $queue, 0, self::PER_RUN );
		update_option( self::QUEUE_OPTION, $queue, false );
		foreach ( $now as $id ) {
			$company = FW_CRM_Companies::find( (int) $id );
			// Only companies still without a logo: one may have been found, uploaded or removed since it was queued.
			if ( $company && ! $company->deleted_at && '' === (string) $company->logo ) {
				self::fetch( (int) $id );
			}
		}
		if ( $queue ) {
			wp_schedule_single_event( time() + 20, self::BATCH_HOOK );
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Fetching from the company's own website
	 * ---------------------------------------------------------------------- */

	/**
	 * Find the company's site icon and store it as its logo.
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function fetch( $id ) {
		$company = FW_CRM_Companies::find( (int) $id );
		if ( ! $company ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Company not found.', 'fw' ) );
		}
		$site = '' !== (string) $company->website ? (string) $company->website : ( '' !== (string) $company->domain ? 'https://' . $company->domain . '/' : '' );
		if ( '' === $site ) {
			return new WP_Error( 'fw_crm_logo_no_site', __( 'Add the company\'s website first: the logo comes from there.', 'fw' ) );
		}
		if ( ! preg_match( '#^https?://#i', $site ) ) {
			$site = 'https://' . $site;
		}

		foreach ( self::candidates( $site ) as $icon ) {
			$image = self::download( $icon );
			if ( $image ) {
				$url = self::store( (int) $id, $image['body'], $image['ext'], 'a' );
				if ( $url ) {
					FW_CRM_Companies::update( (int) $id, array( 'logo' => $url ) );
					return true;
				}
			}
		}
		return new WP_Error( 'fw_crm_logo_none', __( 'No usable logo was found on the company\'s website. You can upload one instead.', 'fw' ) );
	}

	/**
	 * Icon URLs to try, best first: the page's apple-touch-icon, its largest
	 * declared icon, then the usual default locations.
	 *
	 * @param string $site
	 *
	 * @return string[]
	 */
	private static function candidates( $site ) {
		$found = array();
		$res   = wp_safe_remote_get( $site, array( 'timeout' => 8, 'redirection' => 3, 'limit_response_size' => 524288, 'user-agent' => self::agent() ) );
		$base  = $site;
		if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
			$html = (string) wp_remote_retrieve_body( $res );
			if ( preg_match( '#<base\s[^>]*href=["\']([^"\']+)#i', $html, $b ) ) {
				$base = self::absolute( $b[1], $site );
			}
			if ( preg_match_all( '#<link\b[^>]*>#i', $html, $tags ) ) {
				foreach ( $tags[0] as $tag ) {
					$rel  = self::attr( $tag, 'rel' );
					$href = self::attr( $tag, 'href' );
					if ( '' === $href || ! preg_match( '/\b(apple-touch-icon|apple-touch-icon-precomposed|icon)\b/i', $rel ) ) {
						continue;
					}
					if ( preg_match( '/\.svg(\?|$)/i', $href ) || 'image/svg+xml' === strtolower( self::attr( $tag, 'type' ) ) ) {
						continue; // SVG files can carry scripts: never stored.
					}
					$size = 0;
					if ( preg_match( '/(\d+)x\d+/', self::attr( $tag, 'sizes' ), $m ) ) {
						$size = (int) $m[1];
					}
					if ( stripos( $rel, 'apple-touch-icon' ) !== false ) {
						$score = 1000 + ( $size ? $size : 180 );
					} elseif ( preg_match( '/\.ico(\?|$)/i', $href ) ) {
						$score = 10;
					} else {
						$score = $size ? $size : 48;
					}
					$found[ self::absolute( html_entity_decode( $href ), $base ) ] = $score;
				}
			}
		}
		$root = preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base );
		$found += array( $root . '/apple-touch-icon.png' => 900, $root . '/favicon.ico' => 5 );
		arsort( $found );
		return array_slice( array_keys( $found ), 0, 6 );
	}

	/**
	 * Download one candidate and check it is a real picture at least 32px wide.
	 *
	 * @param string $url
	 *
	 * @return array|null { body, ext }
	 */
	private static function download( $url ) {
		$res = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 3, 'limit_response_size' => self::MAX_BYTES, 'user-agent' => self::agent() ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( strlen( $body ) < 100 ) {
			return null;
		}
		// An .ico: keep it as it is when its largest image is 32px or more (byte 6 of each entry is the width, 0 = 256).
		if ( "\0\0\1\0" === substr( $body, 0, 4 ) ) {
			$count = unpack( 'v', substr( $body, 4, 2 ) );
			$best  = 0;
			for ( $i = 0; $i < min( 16, (int) $count[1] ); $i++ ) {
				$w    = ord( $body[ 6 + 16 * $i ] );
				$best = max( $best, 0 === $w ? 256 : $w );
			}
			return $best >= 32 ? array( 'body' => $body, 'ext' => 'ico' ) : null;
		}
		$info = @getimagesizefromstring( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a non-image is an expected miss.
		if ( ! $info || $info[0] < 32 ) {
			return null;
		}
		$ext = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
		return isset( $ext[ $info[2] ] ) ? array( 'body' => $body, 'ext' => $ext[ $info[2] ] ) : null;
	}

	/* ---------------------------------------------------------------------- *
	 * Storing, uploading, removing
	 * ---------------------------------------------------------------------- */

	/**
	 * Save picture bytes as a company's logo (scaled down to 128px) and return its URL.
	 *
	 * @param int    $id
	 * @param string $bytes
	 * @param string $ext   png|jpg|gif|webp|ico
	 * @param string $who   a = fetched, m = uploaded
	 *
	 * @return string '' on failure.
	 */
	public static function store( $id, $bytes, $ext, $who ) {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir['path'] ) ) {
			return '';
		}
		self::delete_files( (int) $id );
		$name = sprintf( '%d-%s-%s.%s', (int) $id, 'm' === $who ? 'm' : 'a', substr( md5( $bytes ), 0, 10 ), $ext );
		$file = $dir['path'] . '/' . $name;
		if ( false === file_put_contents( $file, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return '';
		}
		if ( 'ico' !== $ext ) {
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
				$size = $editor->get_size();
				if ( $size['width'] > self::SIZE || $size['height'] > self::SIZE ) {
					$editor->resize( self::SIZE, self::SIZE, false );
					$editor->save( $file );
				}
			}
		}
		return $dir['url'] . '/' . $name;
	}

	/**
	 * A picture uploaded on the company page.
	 *
	 * @param int   $id
	 * @param array $upload One entry of $_FILES.
	 *
	 * @return true|WP_Error
	 */
	public static function upload( $id, array $upload ) {
		if ( empty( $upload['tmp_name'] ) || ! is_uploaded_file( $upload['tmp_name'] ) || ! empty( $upload['error'] ) ) {
			return new WP_Error( 'fw_crm_logo_upload', __( 'Choose a picture to upload.', 'fw' ) );
		}
		if ( (int) $upload['size'] > 2 * self::MAX_BYTES ) {
			return new WP_Error( 'fw_crm_logo_upload', __( 'That picture is too big: use one under 2 MB.', 'fw' ) );
		}
		$bytes = (string) file_get_contents( $upload['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$info  = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$ext   = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
		if ( ! $info || ! isset( $ext[ $info[2] ] ) ) {
			return new WP_Error( 'fw_crm_logo_upload', __( 'Use a PNG, JPG, GIF or WebP picture.', 'fw' ) );
		}
		$url = self::store( (int) $id, $bytes, $ext[ $info[2] ], 'm' );
		if ( '' === $url ) {
			return new WP_Error( 'fw_crm_logo_upload', __( 'Could not save the picture. Check that the uploads folder can be written to.', 'fw' ) );
		}
		FW_CRM_Companies::update( (int) $id, array( 'logo' => $url ) );
		return true;
	}

	/**
	 * Remove a company's logo and stop fetching it automatically.
	 *
	 * @param int $id
	 */
	public static function remove( $id ) {
		self::delete_files( (int) $id );
		FW_CRM_Companies::update( (int) $id, array( 'logo' => 'none' ) );
	}

	/** Delete every stored file of a company. */
	private static function delete_files( $id ) {
		$dir = self::dir();
		foreach ( (array) glob( $dir['path'] . '/' . (int) $id . '-[am]-*' ) as $f ) {
			if ( is_file( $f ) ) {
				wp_delete_file( $f );
			}
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Small helpers
	 * ---------------------------------------------------------------------- */

	private static function agent() {
		return 'Mozilla/5.0 (compatible; Unyson+ CRM; +' . home_url( '/' ) . ')';
	}

	private static function attr( $tag, $name ) {
		return preg_match( '#\s' . $name . '\s*=\s*(["\'])(.*?)\1#is', $tag, $m ) ? trim( $m[2] ) : ( preg_match( '#\s' . $name . '\s*=\s*([^\s>]+)#i', $tag, $m ) ? trim( $m[1] ) : '' );
	}

	/** Resolve a link found on a page against that page's address. */
	private static function absolute( $href, $base ) {
		if ( preg_match( '#^https?://#i', $href ) ) {
			return $href;
		}
		if ( 0 === strpos( $href, '//' ) ) {
			return 'https:' . $href;
		}
		$root = preg_replace( '#^(https?://[^/]+).*$#i', '$1', $base );
		if ( 0 === strpos( $href, '/' ) ) {
			return $root . $href;
		}
		$path = preg_replace( '#[^/]*$#', '', (string) wp_parse_url( $base, PHP_URL_PATH ) );
		return $root . ( '' !== $path ? $path : '/' ) . $href;
	}
}
