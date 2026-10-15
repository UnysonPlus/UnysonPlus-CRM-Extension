<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Tags: labels on contacts. The API other code uses to tag people by slug.
 *
 * Tags and lists share one table (`lists`, by `type`) and one pivot; a tag has no
 * subscription status, it is simply on a contact or not. Adding and removing land
 * on the timeline and fire `unysonplus_crm_tag_added` / `unysonplus_crm_tag_removed`,
 * which is what segments, automations and Membership's access rules build on.
 */
class FW_CRM_Tags {

	/**
	 * @return array Tag rows, by title.
	 */
	public static function all() {
		return FW_CRM_Lists::all( 'tag' );
	}

	/**
	 * @param string|int $tag A slug or an id.
	 *
	 * @return object|null
	 */
	public static function find( $tag ) {
		$row = is_numeric( $tag ) ? FW_CRM_Lists::find( (int) $tag ) : FW_CRM_Lists::find_by_slug( sanitize_key( (string) $tag ), 'tag' );

		return $row && 'tag' === $row->type ? $row : null;
	}

	/**
	 * Tag a contact. Unknown slugs are created (titled from the slug, or from the
	 * label given as `slug => title`).
	 *
	 * @param int          $contact_id
	 * @param string|array $tags   Slugs or ids, or slug => title.
	 * @param string       $source Who did it: admin, form, automation, membership…
	 *
	 * @return int How many tags were newly added.
	 */
	public static function add( $contact_id, $tags, $source = '' ) {
		$done = 0;
		foreach ( self::normalize( $tags ) as $ref => $title ) {
			$tag = self::find( $ref );
			if ( ! $tag && ! is_numeric( $ref ) ) {
				$tag = FW_CRM_Lists::get_or_create( $ref, 'tag', (string) $title );
			}
			if ( $tag && FW_CRM_Subscribers::add_to_list( (int) $contact_id, (int) $tag->id, 'tag', $source ) ) {
				$done++;
			}
		}
		return $done;
	}

	/**
	 * Remove tags from a contact.
	 *
	 * @param int          $contact_id
	 * @param string|array $tags
	 * @param string       $source
	 *
	 * @return int How many were removed.
	 */
	public static function remove( $contact_id, $tags, $source = '' ) {
		$done = 0;
		foreach ( array_keys( self::normalize( $tags ) ) as $ref ) {
			$tag = self::find( $ref );
			if ( $tag && FW_CRM_Subscribers::remove_from_list( (int) $contact_id, (int) $tag->id, 'tag', $source ) ) {
				$done++;
			}
		}
		return $done;
	}

	/**
	 * A contact's tag slugs.
	 *
	 * @param int $contact_id
	 *
	 * @return string[]
	 */
	public static function for_contact( $contact_id ) {
		return wp_list_pluck( FW_CRM_Subscribers::get_lists( (int) $contact_id, 'tag' ), 'slug' );
	}

	/**
	 * Does the contact have these tags? (FW_CRM_Subscribers::has_lists, for tags.)
	 *
	 * @param int    $contact_id
	 * @param array  $slugs
	 * @param string $match any | all | none
	 *
	 * @return bool
	 */
	public static function has( $contact_id, array $slugs, $match = 'any' ) {
		return FW_CRM_Subscribers::has_lists( (int) $contact_id, $slugs, $match, 'tag' );
	}

	/**
	 * Rename a tag (its title; the slug too when given and free).
	 *
	 * @param int    $tag_id
	 * @param string $title
	 * @param string $slug
	 *
	 * @return true|WP_Error
	 */
	public static function rename( $tag_id, $title, $slug = '' ) {
		global $wpdb;

		$tag = self::find( (int) $tag_id );
		if ( ! $tag ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Tag not found.', 'fw' ) );
		}
		if ( ! empty( $tag->managed_by ) ) {
			return new WP_Error( 'fw_crm_managed', sprintf( __( 'This tag is kept up to date by %s, so it cannot be renamed here.', 'fw' ), class_exists( 'FW_CRM_App' ) ? FW_CRM_App::managed_label( $tag->managed_by, false ) : ucfirst( (string) $tag->managed_by ) ) );
		}
		$title = sanitize_text_field( (string) $title );
		if ( '' === $title ) {
			return new WP_Error( 'fw_crm_no_title', __( 'Give the tag a name.', 'fw' ) );
		}
		$row  = array( 'title' => $title );
		$slug = sanitize_key( (string) $slug );
		if ( '' !== $slug && $slug !== $tag->slug ) {
			if ( self::find( $slug ) ) {
				return new WP_Error( 'fw_crm_duplicate', __( 'Another tag already uses that slug. Merge them instead.', 'fw' ) );
			}
			$row['slug'] = $slug;
		}
		$wpdb->update( FW_CRM_Installer::table( 'lists' ), $row, array( 'id' => (int) $tag->id ) );
		FW_CRM_Subscribers::flush_has_cache();

		/** Fires after a tag is renamed: the tag row before, and after. */
		do_action( 'unysonplus_crm_tag_renamed', $tag, self::find( (int) $tag->id ) );

		return true;
	}

	/**
	 * Merge one tag into another: everyone tagged $from gets $into, then $from is
	 * deleted. Segments that used $from by slug are updated to $into.
	 *
	 * @param int $from_id
	 * @param int $into_id
	 *
	 * @return int|WP_Error Contacts moved.
	 */
	public static function merge( $from_id, $into_id ) {
		global $wpdb;

		$from = self::find( (int) $from_id );
		$into = self::find( (int) $into_id );
		if ( ! $from || ! $into ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Tag not found.', 'fw' ) );
		}
		if ( (int) $from->id === (int) $into->id ) {
			return new WP_Error( 'fw_crm_same', __( 'Pick two different tags.', 'fw' ) );
		}
		if ( ! empty( $from->managed_by ) || ! empty( $into->managed_by ) ) {
			return new WP_Error( 'fw_crm_managed', __( 'Tags kept up to date by an extension cannot be merged by hand.', 'fw' ) );
		}

		$pivot = FW_CRM_Installer::table( 'subscriber_pivot' );
		$moved = (int) $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO {$pivot} (subscriber_id, object_id, object_type, status, source, created_at)
			 SELECT subscriber_id, %d, 'tag', status, 'merge', created_at FROM {$pivot} WHERE object_id = %d AND object_type = 'tag'", // phpcs:ignore WordPress.DB.PreparedSQL
			(int) $into->id,
			(int) $from->id
		) );
		FW_CRM_Lists::delete( (int) $from->id );

		// Segments that matched the old tag by slug now match the new one.
		foreach ( FW_CRM_Lists::segments() as $segment ) {
			$raw = (string) $segment->filters;
			if ( false === strpos( $raw, '"' . $from->slug . '"' ) ) {
				continue;
			}
			$filters = json_decode( $raw, true );
			if ( ! is_array( $filters ) ) {
				continue;
			}
			if ( isset( $filters['tag'] ) && $filters['tag'] === $from->slug ) {
				$filters['tag'] = $into->slug;
			}
			if ( ! empty( $filters['rules'] ) ) {
				$filters['rules'] = self::swap_in_rules( $filters['rules'], $from->slug, $into->slug );
			}
			FW_CRM_Lists::update_segment( (int) $segment->id, array( 'filters' => $filters ) );
		}
		FW_CRM_Subscribers::flush_has_cache();

		/** Fires after one tag is merged into another (the removed tag's row, the kept tag's row). */
		do_action( 'unysonplus_crm_tags_merged', $from, $into );

		return $moved;
	}

	/**
	 * @param array  $tree
	 * @param string $from
	 * @param string $into
	 *
	 * @return array
	 */
	private static function swap_in_rules( array $tree, $from, $into ) {
		foreach ( (array) ( $tree['rules'] ?? array() ) as $i => $rule ) {
			if ( isset( $rule['rules'] ) ) {
				$tree['rules'][ $i ] = self::swap_in_rules( $rule, $from, $into );
			} elseif ( 'tag' === ( $rule['field'] ?? '' ) && is_array( $rule['value'] ?? null ) ) {
				$tree['rules'][ $i ]['value'] = array_values( array_unique( array_map( function ( $v ) use ( $from, $into ) {
					return $v === $from ? $into : $v;
				}, $rule['value'] ) ) );
			}
		}
		return $tree;
	}

	/**
	 * @param string|array $tags
	 *
	 * @return array ref => title
	 */
	private static function normalize( $tags ) {
		$out = array();
		foreach ( (array) $tags as $k => $v ) {
			if ( is_int( $k ) ) {
				$out[ is_numeric( $v ) ? (int) $v : sanitize_key( (string) $v ) ] = '';
			} else {
				$out[ sanitize_key( (string) $k ) ] = (string) $v;
			}
		}
		unset( $out[''] );
		return $out;
	}

	/**
	 * Timeline + events for every tag change, whichever path made it (the profile,
	 * bulk tagging, a form, the API).
	 *
	 * @internal
	 */
	public static function init() {
		add_action( 'unysonplus_crm_pivot_changed', function ( $contact_id, $object_id, $type, $op, $source ) {
			if ( 'tag' !== $type ) {
				return;
			}
			$tag = FW_CRM_Lists::find( (int) $object_id );
			if ( ! $tag ) {
				return;
			}
			FW_CRM_Activity::log( 'contact', (int) $contact_id, 'tag', sprintf( 'add' === $op ? __( 'Tagged %s', 'fw' ) : __( 'Removed the tag %s', 'fw' ), $tag->title ), array(
				'meta' => array( 'tag_id' => (int) $tag->id, 'op' => $op, 'source' => (string) $source ),
			) );
			/**
			 * Fires when a tag is added to or removed from a contact (contact id, tag id, source).
			 * unysonplus_crm_tag_added / unysonplus_crm_tag_removed
			 */
			do_action( 'add' === $op ? 'unysonplus_crm_tag_added' : 'unysonplus_crm_tag_removed', (int) $contact_id, (int) $tag->id, (string) $source );
		}, 10, 5 );
	}
}
