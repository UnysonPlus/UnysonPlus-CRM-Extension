<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * CSV import and export.
 *
 * Two details that are easy to get wrong and expensive to fix later:
 *
 *  - EXPORT streams to php://output from the page's `load-` hook (before any
 *    HTML), and every cell that begins with =, +, - or @ is prefixed with an
 *    apostrophe. Without that, an exported address like `=cmd|…` is a formula
 *    that Excel will happily execute — CSV injection.
 *  - IMPORT reads with fgetcsv in a stream, never file() into memory, and routes
 *    every row through the service so the "don't resurrect an opt-out" rule can
 *    only exist in one place.
 */
class FW_Newsletter_CRM_CSV {

	/**
	 * The columns we export, and the ones an import can map onto.
	 *
	 * @return array [ key => label ]
	 */
	public static function fields() {
		$fields = array(
			'email'           => __( 'Email', 'fw' ),
			'first_name'      => __( 'First name', 'fw' ),
			'last_name'       => __( 'Last name', 'fw' ),
			'status'          => __( 'Status', 'fw' ),
			'lists'           => __( 'Lists', 'fw' ),
			'tags'            => __( 'Tags', 'fw' ),
			'source'          => __( 'Source', 'fw' ),
			'source_url'      => __( 'Source URL', 'fw' ),
			'ip'              => __( 'IP', 'fw' ),
			'created_at'      => __( 'Created', 'fw' ),
			'confirmed_at'    => __( 'Confirmed', 'fw' ),
			'unsubscribed_at' => __( 'Unsubscribed', 'fw' ),
		);

		/** Filters the column map used when exporting subscribers to CSV. */
		return apply_filters( 'unysonplus_newsletter_crm_csv_fields', $fields );
	}

	/* ---------------------------------------------------------------------- *
	 * Export
	 * ---------------------------------------------------------------------- */

	/**
	 * Stream every matching subscriber as a CSV download, then exit.
	 *
	 * MUST be called before any output — i.e. from the admin page's `load-` hook.
	 *
	 * @param array $args Same query args the list table uses (status, list, search, ids…).
	 */
	public static function export( array $args = array() ) {
		$args['per_page'] = 0; // everything
		$rows             = FW_Newsletter_CRM_Subscribers::query( $args );
		$fields           = self::fields();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . self::filename() );

		$out = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens accented names correctly.
		fwrite( $out, "\xEF\xBB\xBF" );

		fputcsv( $out, array_values( $fields ) );

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( array_keys( $fields ) as $key ) {
				$line[] = self::escape_cell( self::cell( $row, $key ) );
			}

			fputcsv( $out, $line );
		}

		fclose( $out );
		exit;
	}

	/**
	 * @return string
	 */
	private static function filename() {
		return 'subscribers-' . gmdate( 'Y-m-d-His' ) . '.csv';
	}

	/**
	 * @param object $row
	 * @param string $key
	 *
	 * @return string
	 */
	private static function cell( $row, $key ) {
		if ( 'lists' === $key || 'tags' === $key ) {
			$type  = 'tags' === $key ? 'tag' : 'list';
			$items = FW_Newsletter_CRM_Subscribers::get_lists( $row->id, $type );

			return implode( '|', wp_list_pluck( $items, 'slug' ) );
		}

		return isset( $row->$key ) ? (string) $row->$key : '';
	}

	/**
	 * Defuse CSV injection into Excel / Sheets.
	 *
	 * @param string $value
	 *
	 * @return string
	 */
	public static function escape_cell( $value ) {
		$value = (string) $value;

		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/* ---------------------------------------------------------------------- *
	 * Import
	 * ---------------------------------------------------------------------- */

	/**
	 * Read a CSV's header row plus a few sample rows, so the UI can offer column
	 * mapping before anything is written.
	 *
	 * @param string $file Absolute path.
	 * @param int    $samples
	 *
	 * @return array|WP_Error [ 'header' => array, 'rows' => array ]
	 */
	public static function peek( $file, $samples = 3 ) {
		$handle = self::open( $file );

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$header = fgetcsv( $handle );

		if ( ! $header ) {
			fclose( $handle );

			return new WP_Error( 'fw_crm_empty_csv', __( 'That file has no header row.', 'fw' ) );
		}

		$header = self::strip_bom( $header );
		$rows   = array();

		while ( count( $rows ) < $samples && ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore
			$rows[] = $row;
		}

		fclose( $handle );

		return array( 'header' => $header, 'rows' => $rows );
	}

	/**
	 * Guess which of our fields each CSV column is, so the mapping UI starts in
	 * the right place for the common exports.
	 *
	 * @param array $header
	 *
	 * @return array [ column index => our field key ]
	 */
	public static function guess_mapping( array $header ) {
		$aliases = array(
			'email'      => array( 'email', 'email address', 'e-mail', 'mail', 'email_address' ),
			'first_name' => array( 'first name', 'firstname', 'first', 'fname', 'given name' ),
			'last_name'  => array( 'last name', 'lastname', 'last', 'lname', 'surname' ),
			'status'     => array( 'status', 'state' ),
			'source'     => array( 'source', 'origin' ),
			'lists'      => array( 'lists', 'list', 'groups', 'audience' ),
			'tags'       => array( 'tags', 'tag' ),
		);

		$map = array();

		foreach ( $header as $index => $label ) {
			$needle = strtolower( trim( (string) $label ) );

			foreach ( $aliases as $field => $names ) {
				if ( in_array( $needle, $names, true ) ) {
					$map[ $index ] = $field;
					break;
				}
			}

			// A whole-name column becomes `name`; the service splits it.
			if ( ! isset( $map[ $index ] ) && in_array( $needle, array( 'name', 'full name', 'fullname' ), true ) ) {
				$map[ $index ] = 'name';
			}
		}

		return $map;
	}

	/**
	 * Import the file — all of it, or one resumable chunk.
	 *
	 * Chunking exists because a large list otherwise hits `max_execution_time`
	 * mid-import, and since every row commits as it goes that leaves a PARTIAL
	 * import with no way to resume. So the caller can hand back the byte offset
	 * it left off at and carry on from there.
	 *
	 * `max_rows` and `max_seconds` both default to 0 (= no limit), which keeps a
	 * plain `import( $file, $mapping )` behaving exactly as it always did — the
	 * admin screen opts into chunking, nothing else has to.
	 *
	 * @param string $file    Absolute path.
	 * @param array  $mapping [ column index => field key ]
	 * @param array  $opts    lists, tags, overwrite_unsubscribed,
	 *                        offset (resume from this byte),
	 *                        line (line number the offset corresponds to),
	 *                        max_rows, max_seconds
	 *
	 * @return array|WP_Error created, updated, skipped, failed, errors[],
	 *                        offset, line, done, bytes, size
	 */
	public static function import( $file, array $mapping, array $opts = array() ) {
		$handle = self::open( $file );

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$offset      = isset( $opts['offset'] ) ? (int) $opts['offset'] : 0;
		$max_rows    = isset( $opts['max_rows'] ) ? (int) $opts['max_rows'] : 0;
		$max_seconds = isset( $opts['max_seconds'] ) ? (int) $opts['max_seconds'] : 0;
		$size        = (int) filesize( $file );
		$started     = microtime( true );
		$processed   = 0;

		$stats = array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array() );

		if ( $offset > 0 ) {
			// Resuming: the header was consumed on the first pass, so seek
			// straight to where we stopped rather than re-reading it.
			fseek( $handle, $offset );
			$line = isset( $opts['line'] ) ? (int) $opts['line'] : 1;
		} else {
			fgetcsv( $handle ); // discard the header
			$line = 1;
		}

		while ( ( $row = fgetcsv( $handle ) ) !== false ) { // phpcs:ignore
			$line++;
			$processed++;

			$data = array();

			foreach ( $mapping as $index => $field ) {
				if ( '' === $field || ! isset( $row[ $index ] ) ) {
					continue;
				}

				$data[ $field ] = trim( (string) $row[ $index ] );
			}

			if ( empty( $data['email'] ) ) {
				$stats['skipped']++;
				continue;
			}

			// Per-row lists/tags in the CSV add to the ones chosen in the UI.
			$row_opts = $opts;

			foreach ( array( 'lists', 'tags' ) as $key ) {
				if ( ! empty( $data[ $key ] ) ) {
					$row_opts[ $key ] = array_merge(
						(array) ( isset( $opts[ $key ] ) ? $opts[ $key ] : array() ),
						array_filter( array_map( 'trim', preg_split( '/[|,;]/', $data[ $key ] ) ) )
					);
				}

				unset( $data[ $key ] );
			}

			$result = FW_Newsletter_CRM_Service::import_row( $data, $row_opts );

			if ( is_wp_error( $result ) ) {
				$stats['failed']++;

				if ( count( $stats['errors'] ) < 20 ) {
					$stats['errors'][] = sprintf(
						/* translators: 1: CSV line number, 2: error message */
						__( 'Line %1$d: %2$s', 'fw' ),
						$line,
						$result->get_error_message()
					);
				}
			} else {
				$stats[ $result ]++;
			}

			// Stop on whichever budget runs out first. The byte offset is taken
			// AFTER a completed row, so resuming can never re-import or skip one.
			if ( $max_rows > 0 && $processed >= $max_rows ) {
				break;
			}

			if ( $max_seconds > 0 && ( microtime( true ) - $started ) >= $max_seconds ) {
				break;
			}
		}

		$stats['offset'] = (int) ftell( $handle );
		$stats['line']   = $line;
		$stats['done']   = feof( $handle ) || $stats['offset'] >= $size;
		$stats['bytes']  = $stats['offset'];
		$stats['size']   = $size;

		fclose( $handle );

		return $stats;
	}

	/**
	 * Merge one chunk's counters into a running total.
	 *
	 * @param array $total
	 * @param array $chunk
	 *
	 * @return array
	 */
	public static function merge_stats( array $total, array $chunk ) {
		foreach ( array( 'created', 'updated', 'skipped', 'failed' ) as $key ) {
			$total[ $key ] = ( isset( $total[ $key ] ) ? (int) $total[ $key ] : 0 ) + (int) $chunk[ $key ];
		}

		$errors = array_merge(
			isset( $total['errors'] ) ? (array) $total['errors'] : array(),
			isset( $chunk['errors'] ) ? (array) $chunk['errors'] : array()
		);

		// Keep the error log bounded — a wholly malformed file must not blow up
		// the transient the job lives in.
		$total['errors'] = array_slice( $errors, 0, 20 );

		return $total;
	}

	/**
	 * @param string $file
	 *
	 * @return resource|WP_Error
	 */
	private static function open( $file ) {
		if ( ! $file || ! is_readable( $file ) ) {
			return new WP_Error( 'fw_crm_unreadable', __( 'Could not read the uploaded file.', 'fw' ) );
		}

		$handle = fopen( $file, 'r' );

		if ( ! $handle ) {
			return new WP_Error( 'fw_crm_unreadable', __( 'Could not open the uploaded file.', 'fw' ) );
		}

		return $handle;
	}

	/**
	 * Excel writes a UTF-8 BOM in front of the first header cell, which would
	 * otherwise make "email" never match anything.
	 *
	 * @param array $header
	 *
	 * @return array
	 */
	private static function strip_bom( array $header ) {
		if ( isset( $header[0] ) ) {
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		}

		return $header;
	}
}
