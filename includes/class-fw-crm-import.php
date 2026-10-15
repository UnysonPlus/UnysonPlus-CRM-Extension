<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CSV import as a batch you can check first and undo afterwards.
 *
 * An import is a row in `imports` (file, column mapping, options, progress, totals).
 * Every contact it creates or changes gets a row in `import_rows` with what it was
 * before and what the import added (lists, tags, a company), which is what undo()
 * reverses. dry_run() runs the same row logic without writing anything.
 *
 * Rows are processed in chunks with a time budget, so a big file never hits a host's
 * time limit: the progress screen asks for chunks while it is open, and a scheduled
 * job carries on if the tab is closed.
 *
 * Matching: by email, or by phone (last nine digits) when there is no email. Existing
 * contacts are updated, only filled where empty, or skipped, as chosen. Nobody is
 * subscribed to email unless the importer confirms everyone in the file agreed; and
 * nobody who unsubscribed or left a list is put back unless they say so explicitly.
 */
class FW_CRM_Import {

	const CRON_HOOK = 'fw_crm_import_tick';

	/** Core contact fields an import column can fill. */
	const CORE = array( 'email', 'first_name', 'last_name', 'phone', 'job_title', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country', 'source' );

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, '_cron' ) );
	}

	/** @return string */
	private static function table( $which = 'imports' ) {
		return FW_CRM_Installer::table( $which );
	}

	/* ---------------------------------------------------------------------- *
	 * Fields and mapping
	 * ---------------------------------------------------------------------- */

	/**
	 * Every target a column can map to, grouped for the mapping screen.
	 *
	 * @return array key => { label, group }
	 */
	public static function fields() {
		$contact = __( 'Contact', 'fw' );
		$address = __( 'Address', 'fw' );
		$crm     = __( 'Lists, tags and status', 'fw' );
		$fields  = array(
			'email'           => array( 'label' => __( 'Email', 'fw' ), 'group' => $contact ),
			'name'            => array( 'label' => __( 'Full name (split into first and last)', 'fw' ), 'group' => $contact ),
			'first_name'      => array( 'label' => __( 'First name', 'fw' ), 'group' => $contact ),
			'last_name'       => array( 'label' => __( 'Last name', 'fw' ), 'group' => $contact ),
			'phone'           => array( 'label' => __( 'Phone', 'fw' ), 'group' => $contact ),
			'job_title'       => array( 'label' => __( 'Job title', 'fw' ), 'group' => $contact ),
			'company'         => array( 'label' => __( 'Company name (linked, created if new)', 'fw' ), 'group' => $contact ),
			'lifecycle_stage' => array( 'label' => __( 'Lifecycle stage', 'fw' ), 'group' => $contact ),
			'address_line1'   => array( 'label' => __( 'Street', 'fw' ), 'group' => $address ),
			'address_line2'   => array( 'label' => __( 'Street, line 2', 'fw' ), 'group' => $address ),
			'city'            => array( 'label' => __( 'City', 'fw' ), 'group' => $address ),
			'region'          => array( 'label' => __( 'Region', 'fw' ), 'group' => $address ),
			'postcode'        => array( 'label' => __( 'Postcode', 'fw' ), 'group' => $address ),
			'country'         => array( 'label' => __( 'Country (2 letters)', 'fw' ), 'group' => $address ),
			'lists'           => array( 'label' => __( 'Lists (comma separated)', 'fw' ), 'group' => $crm ),
			'tags'            => array( 'label' => __( 'Tags (comma separated)', 'fw' ), 'group' => $crm ),
			'source'          => array( 'label' => __( 'Source', 'fw' ), 'group' => $crm ),
		);
		$custom = __( 'Your fields', 'fw' );
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $def ) {
			if ( ! empty( $def['managed_by'] ) || in_array( $def['type'], array( 'autonumber', 'address', 'lookup' ), true ) ) {
				continue;
			}
			$fields[ 'field:' . $def['key'] ] = array( 'label' => $def['label'], 'group' => $custom );
		}

		/** Filters the fields an import column can map to. */
		return (array) apply_filters( 'unysonplus_crm_import_fields', $fields );
	}

	/**
	 * A best guess at the mapping from the header row; a mapping saved for the
	 * same set of columns wins.
	 *
	 * @param array $header
	 *
	 * @return array [ column index => field key ]
	 */
	public static function guess_mapping( array $header ) {
		$saved = get_option( 'fw_crm_import_mappings', array() );
		$sig   = self::signature( $header );
		if ( is_array( $saved ) && isset( $saved[ $sig ] ) && is_array( $saved[ $sig ] ) ) {
			return $saved[ $sig ];
		}

		$aliases = array(
			'email'           => array( 'email', 'email address', 'e-mail', 'mail', 'email_address', 'e-mail address' ),
			'name'            => array( 'name', 'full name', 'fullname', 'contact', 'contact name' ),
			'first_name'      => array( 'first name', 'firstname', 'first', 'fname', 'given name', 'first_name' ),
			'last_name'       => array( 'last name', 'lastname', 'last', 'lname', 'surname', 'family name', 'last_name' ),
			'phone'           => array( 'phone', 'phone number', 'telephone', 'tel', 'mobile', 'cell', 'mobile phone' ),
			'job_title'       => array( 'job title', 'title', 'position', 'role', 'job' ),
			'company'         => array( 'company', 'company name', 'organisation', 'organization', 'account', 'business' ),
			'lifecycle_stage' => array( 'stage', 'lifecycle stage', 'lifecycle', 'status stage' ),
			'address_line1'   => array( 'address', 'street', 'address 1', 'address line 1', 'street address' ),
			'address_line2'   => array( 'address 2', 'address line 2' ),
			'city'            => array( 'city', 'town' ),
			'region'          => array( 'region', 'state', 'county', 'province' ),
			'postcode'        => array( 'postcode', 'post code', 'zip', 'zip code', 'postal code' ),
			'country'         => array( 'country', 'country code' ),
			'lists'           => array( 'lists', 'list', 'groups', 'audience' ),
			'tags'            => array( 'tags', 'tag', 'labels' ),
			'source'          => array( 'source', 'origin', 'lead source' ),
		);
		foreach ( self::fields() as $key => $f ) {
			if ( 0 === strpos( $key, 'field:' ) ) {
				$aliases[ $key ] = array( strtolower( $f['label'] ), substr( $key, 6 ) );
			}
		}

		$map  = array();
		$used = array();
		foreach ( $header as $index => $label ) {
			$needle = strtolower( trim( preg_replace( '/[_\-]+/', ' ', (string) $label ) ) );
			foreach ( $aliases as $field => $names ) {
				if ( ! isset( $used[ $field ] ) && in_array( $needle, $names, true ) ) {
					$map[ (int) $index ] = $field;
					$used[ $field ]      = true;
					break;
				}
			}
		}

		return $map;
	}

	/**
	 * @param array $header
	 *
	 * @return string
	 */
	private static function signature( array $header ) {
		return md5( strtolower( implode( '|', array_map( 'trim', $header ) ) ) );
	}

	/* ---------------------------------------------------------------------- *
	 * Batches
	 * ---------------------------------------------------------------------- */

	/**
	 * Register an uploaded file as a new import.
	 *
	 * @param string $file     Absolute path of the stored CSV.
	 * @param string $filename The name it was uploaded with.
	 *
	 * @return int|WP_Error
	 */
	public static function create( $file, $filename ) {
		global $wpdb;

		$peek = FW_CRM_CSV::peek( $file, 3 );
		if ( is_wp_error( $peek ) ) {
			return $peek;
		}
		$now = current_time( 'mysql' );
		$wpdb->insert( self::table(), array(
			'user_id'    => get_current_user_id(),
			'filename'   => sanitize_file_name( (string) $filename ),
			'file'       => (string) $file,
			'header'     => wp_json_encode( $peek['header'] ),
			'samples'    => wp_json_encode( $peek['rows'] ),
			'mapping'    => wp_json_encode( self::guess_mapping( $peek['header'] ) ),
			'options'    => wp_json_encode( array() ),
			'status'     => 'mapping',
			'total_rows' => self::count_rows( $file ),
			'stats'      => wp_json_encode( self::empty_stats() ),
			'created_at' => $now,
		) );

		return (int) $wpdb->insert_id ? (int) $wpdb->insert_id : new WP_Error( 'fw_crm_store_failed', __( 'Could not start the import.', 'fw' ) );
	}

	/**
	 * @param int $id
	 *
	 * @return object|null With header, samples, mapping, options and stats decoded.
	 */
	public static function find( $id ) {
		global $wpdb;

		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return null;
		}
		foreach ( array( 'header', 'samples', 'mapping', 'options', 'stats' ) as $k ) {
			$v       = json_decode( (string) $row->$k, true );
			$row->$k = is_array( $v ) ? $v : array();
		}
		$row->mapping = array_map( 'strval', $row->mapping );

		return $row;
	}

	/**
	 * Recent imports, newest first.
	 *
	 * @param int $limit
	 *
	 * @return array
	 */
	public static function history( $limit = 20 ) {
		global $wpdb;

		$table = self::table();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE started_at IS NOT NULL ORDER BY id DESC LIMIT %d", (int) $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		return array_filter( array_map( array( __CLASS__, 'find' ), $ids ) );
	}

	/**
	 * Save the mapping and options (and remember the mapping for this layout).
	 *
	 * @param int   $id
	 * @param array $mapping column index => field key
	 * @param array $options match (update|fill|skip), consent (bool), overwrite (bool), lists (ids), tags (slugs)
	 *
	 * @return true|WP_Error
	 */
	public static function configure( $id, array $mapping, array $options ) {
		global $wpdb;

		$import = self::find( $id );
		if ( ! $import || ! in_array( $import->status, array( 'mapping', 'checked' ), true ) ) {
			return new WP_Error( 'fw_crm_import_state', __( 'This import has already run.', 'fw' ) );
		}
		$fields = self::fields();
		$clean  = array();
		foreach ( $mapping as $index => $field ) {
			$field = (string) $field;
			if ( '' !== $field && isset( $fields[ $field ] ) && ! in_array( $field, $clean, true ) ) {
				$clean[ (int) $index ] = $field;
			}
		}
		if ( ! array_intersect( array( 'email', 'phone', 'name', 'first_name', 'last_name' ), $clean ) ) {
			return new WP_Error( 'fw_crm_import_mapping', __( 'Map at least one column to Email, Phone or a name, so the import knows who each row is.', 'fw' ) );
		}
		$opts = array(
			'match'     => in_array( $options['match'] ?? '', array( 'update', 'fill', 'skip' ), true ) ? $options['match'] : 'fill',
			'consent'   => ! empty( $options['consent'] ),
			'overwrite' => ! empty( $options['overwrite'] ),
			'lists'     => array_values( array_filter( array_map( 'intval', (array) ( $options['lists'] ?? array() ) ) ) ),
			'tags'      => array_values( array_filter( array_map( 'sanitize_title', (array) ( $options['tags'] ?? array() ) ) ) ),
		);
		$wpdb->update( self::table(), array( 'mapping' => wp_json_encode( $clean ), 'options' => wp_json_encode( $opts ) ), array( 'id' => (int) $id ) );

		$saved = get_option( 'fw_crm_import_mappings', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$saved[ self::signature( $import->header ) ] = $clean;
		update_option( 'fw_crm_import_mappings', array_slice( $saved, -50, null, true ), false );

		return true;
	}

	/**
	 * Run the import logic over the whole file without writing anything.
	 *
	 * @param int $id
	 * @param int $max_seconds
	 *
	 * @return array stats + rows_checked + complete (false when the time budget ran out)
	 */
	public static function dry_run( $id, $max_seconds = 20 ) {
		global $wpdb;

		$import = self::find( $id );
		$stats  = self::empty_stats();
		$handle = $import ? self::open( $import->file ) : null;
		if ( ! $handle ) {
			return $stats;
		}
		fgetcsv( $handle );
		$started = microtime( true );
		$line    = 1;
		$done    = true;
		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore
			$line++;
			$result = self::process_row( $import, $row, $line, true );
			self::count( $stats, $result, $line );
			if ( microtime( true ) - $started > $max_seconds ) {
				$done = feof( $handle );
				break;
			}
		}
		fclose( $handle );
		$stats['rows_checked'] = $line - 1;
		$stats['complete']     = $done;
		$wpdb->update( self::table(), array( 'status' => 'checked', 'dry_stats' => wp_json_encode( $stats ) ), array( 'id' => (int) $id ) );

		return $stats;
	}

	/**
	 * Start (or continue) the real import.
	 *
	 * @param int $id
	 *
	 * @return true|WP_Error
	 */
	public static function start( $id ) {
		global $wpdb;

		$import = self::find( $id );
		if ( ! $import ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Import not found.', 'fw' ) );
		}
		if ( ! in_array( $import->status, array( 'mapping', 'checked' ), true ) ) {
			return new WP_Error( 'fw_crm_import_state', __( 'This import has already run.', 'fw' ) );
		}
		$wpdb->update( self::table(), array( 'status' => 'running', 'started_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );

		/** Fires when an import starts: the file name, rows, mapped fields and target lists. */
		do_action( 'unysonplus_crm_import_started', array(
			'import_id' => (int) $id,
			'file'      => $import->filename,
			'rows'      => (int) $import->total_rows,
			'mapping'   => array_values( $import->mapping ),
			'lists'     => $import->options['lists'] ?? array(),
		) );
		self::schedule( $id );

		return true;
	}

	/**
	 * Process rows from where the import left off, within a time budget.
	 *
	 * @param int $id
	 * @param int $max_seconds
	 *
	 * @return object|null The import after the chunk.
	 */
	public static function run_chunk( $id, $max_seconds = 10 ) {
		global $wpdb;

		// One worker at a time per import (the progress screen and the cron job may overlap).
		$lock = 'fw_crm_import_lock_' . (int) $id;
		if ( get_transient( $lock ) ) {
			return self::find( $id );
		}
		set_transient( $lock, 1, 2 * $max_seconds + 30 );

		$import = self::find( $id );
		if ( ! $import || 'running' !== $import->status ) {
			delete_transient( $lock );
			return $import;
		}
		$handle = self::open( $import->file );
		if ( ! $handle ) {
			$wpdb->update( self::table(), array( 'status' => 'failed', 'finished_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );
			delete_transient( $lock );
			return self::find( $id );
		}

		$stats  = $import->stats ? $import->stats : self::empty_stats();
		$offset = (int) $import->byte_offset;
		$line   = max( 1, (int) $import->line );
		if ( $offset > 0 ) {
			fseek( $handle, $offset );
		} else {
			fgetcsv( $handle );
			$line = 1;
		}
		$started = microtime( true );
		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore
			$line++;
			self::count( $stats, self::process_row( $import, $row, $line, false ), $line );
			if ( microtime( true ) - $started >= $max_seconds ) {
				break;
			}
		}
		$offset = (int) ftell( $handle );
		$done   = feof( $handle ) || $offset >= (int) filesize( $import->file );
		fclose( $handle );

		$row = array( 'byte_offset' => $offset, 'line' => $line, 'stats' => wp_json_encode( $stats ) );
		if ( $done ) {
			$row['status']      = 'done';
			$row['finished_at'] = current_time( 'mysql' );
		}
		$wpdb->update( self::table(), $row, array( 'id' => (int) $id ) );
		delete_transient( $lock );

		if ( $done ) {
			/** Fires when an import finishes: the import (with totals). */
			do_action( 'unysonplus_crm_import_finished', self::find( $id ) );
		} else {
			self::schedule( $id );
		}

		return self::find( $id );
	}

	/** @internal The background worker. */
	public static function _cron( $id ) {
		self::run_chunk( (int) $id, 20 );
	}

	/**
	 * @param int $id
	 */
	private static function schedule( $id ) {
		if ( ! wp_next_scheduled( self::CRON_HOOK, array( (int) $id ) ) ) {
			wp_schedule_single_event( time() + 30, self::CRON_HOOK, array( (int) $id ) );
		}
	}

	/**
	 * Stop a running import (what it already did stays, and can still be undone).
	 *
	 * @param int $id
	 */
	public static function cancel( $id ) {
		global $wpdb;

		$wpdb->update( self::table(), array( 'status' => 'cancelled', 'finished_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id, 'status' => 'running' ) );
		$wpdb->update( self::table(), array( 'status' => 'cancelled' ), array( 'id' => (int) $id, 'status' => 'mapping' ) );
		$wpdb->update( self::table(), array( 'status' => 'cancelled' ), array( 'id' => (int) $id, 'status' => 'checked' ) );
		wp_clear_scheduled_hook( self::CRON_HOOK, array( (int) $id ) );
	}

	/* ---------------------------------------------------------------------- *
	 * One row
	 * ---------------------------------------------------------------------- */

	/**
	 * @param object $import
	 * @param array  $row
	 * @param int    $line
	 * @param bool   $dry  Work out what would happen, write nothing.
	 *
	 * @return string|WP_Error created | updated | unchanged | skipped:<reason>, or an error
	 */
	private static function process_row( $import, array $row, $line, $dry ) {
		$opts = $import->options;
		$data = array();
		foreach ( $import->mapping as $index => $field ) {
			if ( isset( $row[ $index ] ) && '' !== trim( (string) $row[ $index ] ) ) {
				$data[ $field ] = trim( (string) $row[ $index ] );
			}
		}
		if ( isset( $data['name'] ) ) {
			$parts = preg_split( '/\s+/', $data['name'], 2 );
			$data += array( 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '' );
			unset( $data['name'] );
		}
		if ( isset( $data['email'] ) ) {
			$data['email'] = FW_CRM_Subscribers::normalize_email( $data['email'] );
			if ( ! is_email( $data['email'] ) ) {
				return new WP_Error( 'fw_crm_invalid_email', sprintf( __( 'Not a valid email address: %s', 'fw' ), $data['email'] ) );
			}
		}
		if ( empty( $data['email'] ) && empty( $data['phone'] ) && empty( $data['first_name'] ) && empty( $data['last_name'] ) ) {
			return 'skipped:empty';
		}
		$stage = '';
		if ( isset( $data['lifecycle_stage'] ) ) {
			foreach ( FW_CRM_Contacts::stages() as $slug => $label ) {
				if ( 0 === strcasecmp( $slug, $data['lifecycle_stage'] ) || 0 === strcasecmp( $label, $data['lifecycle_stage'] ) ) {
					$stage = $slug;
				}
			}
			if ( '' === $stage ) {
				return new WP_Error( 'fw_crm_stage', sprintf( __( 'Unknown stage: %s', 'fw' ), $data['lifecycle_stage'] ) );
			}
		}

		$existing = self::match( $data );
		if ( $existing && $existing->deleted_at ) {
			return 'skipped:bin';
		}
		if ( $existing && 'skip' === $opts['match'] ) {
			return 'skipped:existing';
		}

		// Core fields to write.
		$core = array();
		foreach ( self::CORE as $f ) {
			if ( ! isset( $data[ $f ] ) || 'source' === $f && $existing ) {
				continue;
			}
			if ( $existing ) {
				$current = (string) $existing->$f;
				if ( $current === $data[ $f ] || ( 'fill' === $opts['match'] && '' !== $current ) ) {
					continue;
				}
			}
			$core[ $f ] = $data[ $f ];
		}
		if ( '' !== $stage && ( ! $existing || ( $existing->lifecycle_stage !== $stage && ( 'update' === $opts['match'] || 'subscriber' === $existing->lifecycle_stage ) ) ) ) {
			$core['lifecycle_stage'] = $stage;
		}
		if ( ! $existing && empty( $core['source'] ) ) {
			$core['source'] = 'import';
		}

		// Custom fields.
		$custom  = array();
		$current = $existing ? FW_CRM_Fields::get_values( 'contact', (int) $existing->id ) : array();
		foreach ( $data as $f => $v ) {
			if ( 0 !== strpos( $f, 'field:' ) ) {
				continue;
			}
			$key = substr( $f, 6 );
			$def = FW_CRM_Fields::definition( 'contact', $key );
			if ( ! $def ) {
				continue;
			}
			$had = $current[ $key ] ?? null;
			if ( $existing && ( (string) ( is_array( $had ) ? wp_json_encode( $had ) : $had ) === $v || ( 'fill' === $opts['match'] && null !== $had && '' !== $had ) ) ) {
				continue;
			}
			$custom[ $key ] = 'multichoice' === $def['type'] ? array_map( 'trim', preg_split( '/[|,;]/', $v ) ) : ( 'checkbox' === $def['type'] ? (int) in_array( strtolower( $v ), array( '1', 'yes', 'true', 'y', 'x' ), true ) : $v );
		}

		// Lists and tags: the ones chosen on screen plus any named in the row.
		$lists = $opts['lists'] ?? array();
		if ( ! empty( $data['lists'] ) ) {
			foreach ( array_filter( array_map( 'trim', preg_split( '/[|,;]/', $data['lists'] ) ) ) as $name ) {
				$l = FW_CRM_Lists::find_by_slug( sanitize_title( $name ), 'list' );
				if ( ! $l && ! $dry ) {
					$l = FW_CRM_Lists::get_or_create( sanitize_title( $name ), 'list', $name );
				}
				if ( $l ) {
					$lists[] = (int) $l->id;
				}
			}
		}
		$tags = $opts['tags'] ?? array();
		if ( ! empty( $data['tags'] ) ) {
			$tags = array_merge( $tags, array_map( 'sanitize_title', array_filter( array_map( 'trim', preg_split( '/[|,;]/', $data['tags'] ) ) ) ) );
		}
		$lists = array_values( array_unique( $lists ) );
		$tags  = array_values( array_unique( array_filter( $tags ) ) );

		if ( $dry ) {
			if ( ! $existing ) {
				return 'created';
			}
			return ( $core || $custom || $lists || $tags || ! empty( $data['company'] ) ) ? 'updated' : 'unchanged';
		}

		/* -------- write -------- */
		$record = array( 'before' => array(), 'added' => array( 'lists' => array(), 'tags' => array(), 'company' => 0, 'company_created' => 0 ) );
		$id     = $existing ? (int) $existing->id : 0;

		if ( ! $existing ) {
			$create = $core;
			if ( $custom ) {
				$create['fields'] = $custom;
			}
			$id = FW_CRM_Contacts::create( $create );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
			$action = 'created';
		} else {
			foreach ( array_keys( $core ) as $f ) {
				$record['before'][ $f ] = $existing->$f;
			}
			if ( $custom ) {
				foreach ( array_keys( $custom ) as $k ) {
					$record['before'][ 'field:' . $k ] = $current[ $k ] ?? null;
				}
			}
			if ( $core || $custom ) {
				$result = FW_CRM_Contacts::update( $id, $custom ? array_merge( $core, array( 'fields' => $custom ) ) : $core );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			$action = 'updated';
		}

		// Company by name.
		if ( ! empty( $data['company'] ) ) {
			$found = FW_CRM_Companies::query( array( 'search' => $data['company'], 'per_page' => 20 ) );
			$co    = 0;
			foreach ( $found as $c ) {
				if ( 0 === strcasecmp( $c->name, $data['company'] ) ) {
					$co = (int) $c->id;
					break;
				}
			}
			if ( ! $co ) {
				$co = FW_CRM_Companies::create( array( 'name' => $data['company'] ) );
				$co = is_wp_error( $co ) ? 0 : (int) $co;
				$record['added']['company_created'] = $co;
			}
			if ( $co && ! in_array( $co, FW_CRM_Relations::ids( 'contact', $id, 'company' ), true ) ) {
				FW_CRM_Contacts::add_company( $id, $co );
				$record['added']['company'] = $co;
			}
		}

		// Email permission: only when the importer confirmed consent; never over an opt-out unless told.
		$row_now = FW_CRM_Subscribers::find( $id );
		if ( ! empty( $opts['consent'] ) ) {
			$blocked = in_array( $row_now->status, array( 'unsubscribed', 'bounced', 'complained' ), true );
			if ( 'subscribed' !== $row_now->status && ( ! $blocked || ( ! empty( $opts['overwrite'] ) && 'unsubscribed' === $row_now->status ) ) ) {
				$record['before']['status'] = $row_now->status;
				FW_CRM_Subscribers::update( $id, array( 'status' => 'subscribed', 'consent_at' => current_time( 'mysql' ) ) );
				FW_CRM_Consent::record( $id, 'newsletter', sprintf( __( 'Imported from %1$s; the importer confirmed this person agreed to receive email.', 'fw' ), $import->filename ), array( 'source' => 'import_' . (int) $import->id ) );
			}
		}

		foreach ( $lists as $list_id ) {
			$prev = FW_CRM_List_Membership::status( $id, $list_id );
			if ( FW_CRM_List_Membership::UNSUBSCRIBED === $prev && empty( $opts['overwrite'] ) ) {
				continue; // They left this list: an import does not put them back.
			}
			if ( FW_CRM_List_Membership::subscribe( $id, $list_id, 'import' ) ) {
				$record['added']['lists'][] = array( (int) $list_id, $prev );
			}
		}
		foreach ( $tags as $slug ) {
			$tag = FW_CRM_Tags::find( $slug );
			if ( ! $tag ) {
				$tag = FW_CRM_Lists::get_or_create( $slug, 'tag' );
			}
			if ( $tag && FW_CRM_Subscribers::add_to_list( $id, (int) $tag->id, 'tag', 'import' ) ) {
				$record['added']['tags'][] = (int) $tag->id;
			}
		}

		if ( 'updated' === $action && ! $record['before'] && ! $record['added']['lists'] && ! $record['added']['tags'] && ! $record['added']['company'] ) {
			return 'unchanged';
		}

		FW_CRM_Activity::log( 'contact', $id, 'import', sprintf( 'created' === $action ? __( 'Added by the import of %s', 'fw' ) : __( 'Updated by the import of %s', 'fw' ), $import->filename ), array( 'meta' => array( 'import_id' => (int) $import->id, 'line' => (int) $line ) ) );

		global $wpdb;
		$wpdb->insert( self::table( 'import_rows' ), array(
			'import_id'  => (int) $import->id,
			'contact_id' => (int) $id,
			'action'     => $action,
			'before_values' => wp_json_encode( $record['before'] ),
			'added'      => wp_json_encode( $record['added'] ),
		) );

		return $action;
	}

	/**
	 * The existing contact a row refers to: by email, else by phone.
	 *
	 * @param array $data
	 *
	 * @return object|null
	 */
	private static function match( array $data ) {
		global $wpdb;

		if ( ! empty( $data['email'] ) ) {
			return FW_CRM_Subscribers::find_by_email( $data['email'] );
		}
		$digits = preg_replace( '/\D/', '', (string) ( $data['phone'] ?? '' ) );
		if ( strlen( $digits ) >= 6 ) {
			$tail  = substr( $digits, -9 );
			$table = FW_CRM_Installer::table( 'subscribers' );
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE phone LIKE %s LIMIT 50", '%' . $wpdb->esc_like( substr( $tail, -4 ) ) . '%' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			foreach ( (array) $rows as $r ) {
				if ( substr( preg_replace( '/\D/', '', (string) $r->phone ), -9 ) === $tail ) {
					return $r;
				}
			}
		}
		return null;
	}

	/* ---------------------------------------------------------------------- *
	 * Undo
	 * ---------------------------------------------------------------------- */

	/**
	 * Reverse an import: delete the contacts it created (and companies it created
	 * that nobody else uses), put back what it changed, and remove the lists, tags,
	 * consent records and company links it added.
	 *
	 * @param int $id
	 *
	 * @return array|WP_Error { deleted, restored }
	 */
	public static function undo( $id ) {
		global $wpdb;

		$import = self::find( $id );
		if ( ! $import ) {
			return new WP_Error( 'fw_crm_not_found', __( 'Import not found.', 'fw' ) );
		}
		if ( 'running' === $import->status ) {
			self::cancel( $id );
		}
		if ( 'undone' === $import->status ) {
			return new WP_Error( 'fw_crm_import_state', __( 'This import was already undone.', 'fw' ) );
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'import_rows' ) . ' WHERE import_id = %d ORDER BY id DESC', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out   = array( 'deleted' => 0, 'restored' => 0 );
		$made  = array();
		foreach ( (array) $rows as $r ) {
			$before = json_decode( (string) $r->before_values, true );
			$added  = json_decode( (string) $r->added, true );
			$cid    = (int) $r->contact_id;
			if ( ! FW_CRM_Subscribers::find( $cid ) ) {
				continue;
			}
			if ( ! empty( $added['company_created'] ) ) {
				$made[] = (int) $added['company_created'];
			}
			if ( 'created' === $r->action ) {
				FW_CRM_Contacts::purge( $cid );
				$out['deleted']++;
				continue;
			}
			$core   = array();
			$fields = array();
			foreach ( (array) $before as $f => $v ) {
				if ( 0 === strpos( $f, 'field:' ) ) {
					$fields[ substr( $f, 6 ) ] = $v;
				} elseif ( 'status' !== $f ) {
					$core[ $f ] = $v;
				}
			}
			if ( $core || $fields ) {
				FW_CRM_Contacts::update( $cid, $fields ? array_merge( $core, array( 'fields' => $fields ) ) : $core );
			}
			if ( isset( $before['status'] ) ) {
				FW_CRM_Subscribers::update( $cid, array( 'status' => $before['status'] ) );
			}
			foreach ( (array) ( $added['lists'] ?? array() ) as $pair ) {
				list( $list_id, $prev ) = $pair;
				if ( null === $prev ) {
					$wpdb->delete( FW_CRM_Installer::table( 'subscriber_pivot' ), array( 'subscriber_id' => $cid, 'object_id' => (int) $list_id, 'object_type' => 'list' ) );
				} else {
					FW_CRM_List_Membership::unsubscribe( $cid, (int) $list_id, 'import_undo' );
				}
			}
			foreach ( (array) ( $added['tags'] ?? array() ) as $tag_id ) {
				FW_CRM_Subscribers::remove_from_list( $cid, (int) $tag_id, 'tag', 'import_undo' );
			}
			if ( ! empty( $added['company'] ) ) {
				FW_CRM_Contacts::remove_company( $cid, (int) $added['company'] );
			}
			$wpdb->delete( FW_CRM_Installer::table( 'consent' ), array( 'contact_id' => $cid, 'source' => 'import_' . (int) $id ) );
			FW_CRM_Activity::log( 'contact', $cid, 'import', sprintf( __( 'The import of %s was undone', 'fw' ), $import->filename ) );
			$out['restored']++;
		}
		foreach ( array_unique( $made ) as $co ) {
			if ( ! FW_CRM_Companies::contact_ids( $co ) ) {
				FW_CRM_Companies::purge( $co );
			}
		}
		FW_CRM_Subscribers::flush_has_cache();
		$wpdb->update( self::table(), array( 'status' => 'undone', 'undone_at' => current_time( 'mysql' ) ), array( 'id' => (int) $id ) );

		/** Fires after an import was undone: the import, and { deleted, restored }. */
		do_action( 'unysonplus_crm_import_undone', $import, $out );

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Helpers
	 * ---------------------------------------------------------------------- */

	/**
	 * Data rows in a CSV (records, not lines: a quoted value may span lines).
	 *
	 * @param string $file
	 *
	 * @return int
	 */
	private static function count_rows( $file ) {
		$h = self::open( $file );
		if ( ! $h ) {
			return 0;
		}
		$n = -1; // The header.
		while ( false !== fgetcsv( $h ) ) {
			$n++;
		}
		fclose( $h );
		return max( 0, $n );
	}

	/** @return array */
	private static function empty_stats() {
		return array( 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0, 'failed' => 0, 'skip_reasons' => array(), 'errors' => array() );
	}

	/**
	 * @param array             $stats
	 * @param string|WP_Error   $result
	 * @param int               $line
	 */
	private static function count( array &$stats, $result, $line ) {
		if ( is_wp_error( $result ) ) {
			$stats['failed']++;
			if ( count( $stats['errors'] ) < 25 ) {
				$stats['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'fw' ), $line, $result->get_error_message() );
			}
			return;
		}
		if ( 0 === strpos( $result, 'skipped' ) ) {
			$stats['skipped']++;
			$why                            = substr( $result, 8 );
			$stats['skip_reasons'][ $why ] = ( $stats['skip_reasons'][ $why ] ?? 0 ) + 1;
			return;
		}
		$stats[ $result ] = ( $stats[ $result ] ?? 0 ) + 1;
	}

	/**
	 * @param string $file
	 *
	 * @return resource|null
	 */
	private static function open( $file ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return null;
		}
		$h = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $h ) {
			return null;
		}
		// Skip a UTF-8 byte-order mark.
		$bom = fread( $h, 3 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $h );
		}
		return $h;
	}

	/**
	 * A plain sentence for why rows were skipped.
	 *
	 * @param string $why
	 *
	 * @return string
	 */
	public static function skip_label( $why ) {
		$labels = array(
			'empty'    => __( 'no email, phone or name', 'fw' ),
			'existing' => __( 'already a contact', 'fw' ),
			'bin'      => __( 'the contact is in the bin', 'fw' ),
		);
		return $labels[ $why ] ?? $why;
	}
}
