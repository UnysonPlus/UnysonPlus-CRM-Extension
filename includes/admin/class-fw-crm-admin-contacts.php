<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The record screens of the CRM app: the contact profile and the new-contact
 * form, the company list, profile and form, and search across both.
 *
 * Every write goes through the public contact API (FW_CRM_Contacts,
 * FW_CRM_Companies, FW_CRM_Activity), never SQL, so the screens behave exactly
 * like the REST endpoints and the AI tools. handle() runs on the page's `load-`
 * hook, before output, and returns the redirect args for the admin page's PRG.
 */
class FW_CRM_Admin_Contacts {

	/* ---------------------------------------------------------------------- *
	 * Writes
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $action
	 *
	 * @return array|null Redirect args when the action was one of ours, else null.
	 */
	public static function handle( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification -- the admin page checked the nonce before dispatching.
		$id = isset( $_REQUEST['id'] ) ? (int) $_REQUEST['id'] : 0;

		switch ( $action ) {
			case 'create_contact':
				$data = self::posted_contact();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					$data['owner_id'] = get_current_user_id(); // A sales rep owns what they add.
				}
				$result = FW_CRM_Contacts::create( $data );
				if ( is_wp_error( $result ) ) {
					FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
					return array( 'tab' => 'subscribers', 'new' => 1 );
				}
				$list = isset( $_POST['list'] ) ? sanitize_key( wp_unslash( $_POST['list'] ) ) : '';
				if ( '' !== $list ) {
					$row = is_numeric( $list ) ? FW_CRM_Lists::find( (int) $list ) : FW_CRM_Lists::find_by_slug( $list, 'list' );
					if ( $row ) {
						FW_CRM_Subscribers::add_to_list( $result, (int) $row->id, 'list' );
					}
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Contact saved.', 'fw' ) );
				return array( 'tab' => 'subscribers', 'subscriber' => (int) $result );

			case 'save_contact':
				$data = self::posted_contact();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					unset( $data['owner_id'] ); // Only managers reassign records.
				}
				$result = FW_CRM_Contacts::update( $id, $data );
				FW_CRM_Admin_Page::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Contact saved.', 'fw' ) );
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'set_stage':
				$stage  = isset( $_POST['lifecycle_stage'] ) ? sanitize_key( wp_unslash( $_POST['lifecycle_stage'] ) ) : '';
				$result = FW_CRM_Contacts::update( $id, array( 'lifecycle_stage' => $stage ) );
				if ( is_wp_error( $result ) ) {
					FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
				}
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'add_note':
				$object = isset( $_POST['object'] ) && in_array( $_POST['object'], array( 'company', 'deal' ), true ) ? sanitize_key( wp_unslash( $_POST['object'] ) ) : 'contact';
				$body   = isset( $_POST['note'] ) ? trim( sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) ) : '';
				if ( '' === $body ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'Write something before saving the note.', 'fw' ) );
				} else {
					FW_CRM_Activity::log( $object, $id, 'note', __( 'Note', 'fw' ), array( 'body' => $body ) );
				}
				if ( 'deal' === $object ) {
					return array( 'tab' => 'deals', 'deal' => $id );
				}
				return 'company' === $object ? array( 'tab' => 'companies', 'company' => $id ) : array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'link_company':
				$company = isset( $_POST['company_id'] ) ? (int) $_POST['company_id'] : 0;
				$name    = isset( $_POST['company_name'] ) ? sanitize_text_field( wp_unslash( $_POST['company_name'] ) ) : '';
				if ( ! $company && '' !== $name ) {
					$company = FW_CRM_Companies::create( array( 'name' => $name, 'owner_id' => get_current_user_id() ) );
				} elseif ( $company && ! FW_CRM_Capabilities::can_edit_company( $company ) ) {
					$company = 0;
				}
				if ( is_wp_error( $company ) ) {
					FW_CRM_Admin_Page::flash( 'error', $company->get_error_message() );
				} elseif ( $company ) {
					$linked = FW_CRM_Contacts::add_company( $id, (int) $company );
					if ( is_wp_error( $linked ) ) {
						FW_CRM_Admin_Page::flash( 'error', $linked->get_error_message() );
					}
				}
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'unlink_company':
				FW_CRM_Contacts::remove_company( $id, isset( $_REQUEST['company'] ) ? (int) $_REQUEST['company'] : 0 );
				if ( ! empty( $_REQUEST['from'] ) && 'company' === $_REQUEST['from'] ) {
					return array( 'tab' => 'companies', 'company' => (int) $_REQUEST['company'] );
				}
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'create_company':
				$data = self::posted_company();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					$data['owner_id'] = get_current_user_id();
				}
				$result = FW_CRM_Companies::create( $data );
				if ( is_wp_error( $result ) ) {
					FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
					return array( 'tab' => 'companies', 'new' => 1 );
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Company saved.', 'fw' ) );
				return array( 'tab' => 'companies', 'company' => (int) $result );

			case 'save_company':
				$data = self::posted_company();
				if ( ! FW_CRM_Capabilities::sees_all() ) {
					unset( $data['owner_id'] );
				}
				$result = FW_CRM_Companies::update( $id, $data );
				FW_CRM_Admin_Page::flash( is_wp_error( $result ) ? 'error' : 'success', is_wp_error( $result ) ? $result->get_error_message() : __( 'Company saved.', 'fw' ) );
				return array( 'tab' => 'companies', 'company' => $id );

			case 'trash_company':
				FW_CRM_Companies::trash( $id );
				FW_CRM_Admin_Page::flash( 'success', sprintf(
					'%s <a href="%s">%s</a>',
					esc_html__( 'Company moved to the bin. Its people are still in your contacts.', 'fw' ),
					esc_url( self::action( 'restore_company', $id ) ),
					esc_html__( 'Undo', 'fw' )
				) );
				return array( 'tab' => 'companies' );

			case 'restore_contact':
				FW_CRM_Contacts::restore( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Contact restored.', 'fw' ) );
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'restore_company':
				FW_CRM_Companies::restore( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Company restored.', 'fw' ) );
				return array( 'tab' => 'companies', 'company' => $id );

			case 'purge_contact':
				FW_CRM_Contacts::purge( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Contact deleted for good.', 'fw' ) );
				return array( 'tab' => 'bin' );

			case 'purge_company':
				FW_CRM_Companies::purge( $id );
				FW_CRM_Admin_Page::flash( 'success', __( 'Company deleted for good.', 'fw' ) );
				return array( 'tab' => 'bin' );

			case 'merge_contacts':
				$other  = isset( $_POST['with'] ) ? (int) $_POST['with'] : 0;
				$prefer = array();
				foreach ( FW_CRM_Merge::CHOICE_FIELDS as $f ) {
					if ( isset( $_POST[ 'pick_' . $f ] ) && in_array( $_POST[ 'pick_' . $f ], array( 'keep', 'other' ), true ) ) {
						$prefer[ $f ] = sanitize_key( wp_unslash( $_POST[ 'pick_' . $f ] ) );
					}
				}
				$result = FW_CRM_Merge::merge( $id, $other, $prefer );
				if ( is_wp_error( $result ) ) {
					FW_CRM_Admin_Page::flash( 'error', $result->get_error_message() );
					return array( 'tab' => 'subscribers', 'merge' => $id, 'with' => $other );
				}
				FW_CRM_Admin_Page::flash( 'success', __( 'Contacts merged. Everything from the other record is now on this one.', 'fw' ) );
				return array( 'tab' => 'subscribers', 'subscriber' => $id );

			case 'empty_bin':
				$done = FW_CRM_Trash::purge();
				FW_CRM_Admin_Page::flash( 'success', sprintf( _n( 'The bin is empty: %s record deleted for good.', 'The bin is empty: %s records deleted for good.', $done, 'fw' ), number_format_i18n( $done ) ) );
				return array( 'tab' => 'bin' );

			case 'link_contact':
				$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
				$contact = '' !== $email ? FW_CRM_Subscribers::find_by_email( $email ) : null;
				if ( ! $contact || ! FW_CRM_Capabilities::can_edit_contact( $contact ) ) {
					FW_CRM_Admin_Page::flash( 'error', __( 'No contact of yours has that email address. Add them as a contact first.', 'fw' ) );
				} else {
					FW_CRM_Contacts::add_company( (int) $contact->id, $id );
				}
				return array( 'tab' => 'companies', 'company' => $id );
		}
		// phpcs:enable

		return null;
	}

	/** @return array The contact fields from the posted form. */
	private static function posted_contact() {
		// phpcs:disable WordPress.Security.NonceVerification
		$data = array();
		foreach ( array( 'first_name', 'last_name', 'email', 'phone', 'job_title', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		if ( isset( $_POST['lifecycle_stage'] ) ) {
			$data['lifecycle_stage'] = sanitize_key( wp_unslash( $_POST['lifecycle_stage'] ) );
		}
		if ( isset( $_POST['owner_id'] ) ) {
			$data['owner_id'] = (int) $_POST['owner_id'];
		}
		if ( isset( $_POST['company_id'] ) ) {
			$data['company_id'] = (int) $_POST['company_id'];
		}
		$fields = self::posted_fields( 'contact' );
		if ( $fields ) {
			$data['fields'] = $fields;
		}
		// phpcs:enable

		return $data;
	}

	/** @return array The company fields from the posted form. */
	private static function posted_company() {
		// phpcs:disable WordPress.Security.NonceVerification
		$data = array();
		foreach ( array( 'name', 'domain', 'website', 'phone', 'email', 'industry', 'size', 'address_line1', 'address_line2', 'city', 'region', 'postcode', 'country' ) as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		if ( isset( $_POST['owner_id'] ) ) {
			$data['owner_id'] = (int) $_POST['owner_id'];
		}
		$fields = self::posted_fields( 'company' );
		if ( $fields ) {
			$data['fields'] = $fields;
		}
		// phpcs:enable

		return $data;
	}

	/**
	 * Custom field values from the form. An emptied field removes its value.
	 *
	 * @param string $object
	 *
	 * @return array key => value|null
	 */
	private static function posted_fields( $object ) {
		// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		if ( ! isset( $_POST['fw_crm_fields_present'] ) ) {
			return array();
		}
		$in  = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
		$out = array();
		foreach ( FW_CRM_Fields::definitions( $object ) as $def ) {
			$key = $def['key'];
			if ( ! empty( $def['managed_by'] ) ) {
				continue; // Written by the extension that owns it, never from this form.
			}
			switch ( $def['type'] ) {
				case 'autonumber':
				case 'address':
					break;
				case 'multichoice':
					$out[ $key ] = array_values( array_map( 'sanitize_text_field', (array) ( $in[ $key ] ?? array() ) ) );
					break;
				case 'checkbox':
					$out[ $key ] = empty( $in[ $key ] ) ? 0 : 1;
					break;
				case 'textarea':
					$v           = sanitize_textarea_field( (string) ( $in[ $key ] ?? '' ) );
					$out[ $key ] = '' === trim( $v ) ? null : $v;
					break;
				default:
					$v           = sanitize_text_field( (string) ( $in[ $key ] ?? '' ) );
					$out[ $key ] = '' === $v ? null : $v;
			}
		}
		// phpcs:enable

		return $out;
	}

	/* ---------------------------------------------------------------------- *
	 * Shared bits
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $type An activity type.
	 *
	 * @return string A dashicons class.
	 */
	public static function activity_icon( $type ) {
		$types = FW_CRM_Activity::types();

		return isset( $types[ $type ]['icon'] ) ? (string) $types[ $type ]['icon'] : 'dashicons-marker';
	}

	/**
	 * A nonced record-action URL.
	 *
	 * @param string $action
	 * @param int    $id
	 *
	 * @return string
	 */
	private static function action( $action, $id ) {
		return wp_nonce_url( add_query_arg( array( 'fw_crm_action' => $action, 'id' => (int) $id ), FW_CRM_Admin_Page::get_page_url() ), FW_CRM_Admin_Page::NONCE );
	}

	/**
	 * The banner on a record that is in the bin.
	 *
	 * @param string $what    contact|company
	 * @param int    $id
	 * @param string $deleted When it went in.
	 */
	private static function render_binned( $what, $id, $deleted ) {
		$days = FW_CRM_Trash::retention_days();
		?>
		<div class="fw-crm-binned" role="status">
			<span class="dashicons dashicons-trash" aria-hidden="true"></span>
			<p>
				<strong><?php echo 'company' === $what ? esc_html__( 'This company is in the bin.', 'fw' ) : esc_html__( 'This contact is in the bin.', 'fw' ); ?></strong>
				<?php
				if ( $days ) {
					$left = max( 0, $days - (int) floor( ( current_time( 'timestamp' ) - strtotime( $deleted ) ) / DAY_IN_SECONDS ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
					echo esc_html( sprintf( _n( 'It is hidden everywhere and will be deleted for good in %s day.', 'It is hidden everywhere and will be deleted for good in %s days.', $left, 'fw' ), number_format_i18n( $left ) ) );
				} else {
					esc_html_e( 'It is hidden everywhere until you restore it or empty the bin.', 'fw' );
				}
				?>
			</p>
			<a class="button button-primary" href="<?php echo esc_url( self::action( 'restore_' . $what, $id ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a>
			<a class="button-link button-link-delete" href="<?php echo esc_url( self::action( 'purge_' . $what, $id ) ); ?>"
			   onclick="return confirm('<?php echo esc_js( __( 'Delete this for good? This cannot be undone.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete for good', 'fw' ); ?></a>
		</div>
		<?php
	}

	/** @return array id => display name, for the owner picker. */
	private static function owners() {
		$out = array();
		foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
			$out[ (int) $u->ID ] = $u->display_name;
		}
		return $out;
	}

	/**
	 * @param string $name
	 * @param int    $current
	 */
	private static function owner_select( $name, $current ) {
		if ( ! FW_CRM_Capabilities::sees_all() ) {
			$me = wp_get_current_user();
			$u  = (int) $current ? get_userdata( (int) $current ) : $me;
			echo '<span class="fw-crm-readonly">' . esc_html( $u ? $u->display_name : $me->display_name ) . '</span>';
			return;
		}
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="0"><?php esc_html_e( 'Nobody yet', 'fw' ); ?></option>
			<?php foreach ( self::owners() as $uid => $label ) : ?>
				<option value="<?php echo (int) $uid; ?>" <?php selected( (int) $current, $uid ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * @param string $name
	 * @param int    $current
	 * @param string $none_label
	 */
	private static function company_select( $name, $current, $none_label ) {
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<option value="0"><?php echo esc_html( $none_label ); ?></option>
			<?php foreach ( FW_CRM_Companies::query( FW_CRM_Capabilities::scope( array( 'per_page' => 500 ) ) ) as $c ) : ?>
				<option value="<?php echo (int) $c->id; ?>" <?php selected( (int) $current, (int) $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	/**
	 * A label + control row.
	 *
	 * @param string $label
	 * @param string $control Already-escaped HTML.
	 * @param bool   $wide    Span both columns.
	 */
	private static function field_row( $label, $control, $wide = false ) {
		printf(
			'<label class="fw-crm-form__row%s"><span>%s</span>%s</label>',
			$wide ? ' fw-crm-form__row--wide' : '',
			esc_html( $label ),
			$control // phpcs:ignore WordPress.Security.EscapeOutput
		);
	}

	/**
	 * @param string $name
	 * @param string $value
	 * @param string $type
	 * @param array  $attrs
	 *
	 * @return string
	 */
	private static function input( $name, $value, $type = 'text', array $attrs = array() ) {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . ( true === $v ? '' : '="' . esc_attr( $v ) . '"' );
		}
		return sprintf( '<input type="%s" name="%s" value="%s"%s />', esc_attr( $type ), esc_attr( $name ), esc_attr( (string) $value ), $extra );
	}

	/**
	 * The edit controls for an object's custom fields.
	 *
	 * @param string $object
	 * @param array  $values
	 */
	private static function render_field_inputs( $object, array $values ) {
		$defs = FW_CRM_Fields::definitions( $object );
		echo '<input type="hidden" name="fw_crm_fields_present" value="1" />';
		if ( ! $defs ) {
			return;
		}
		echo '<p class="fw-crm-form__group">' . esc_html__( 'More fields', 'fw' ) . '</p>';
		foreach ( $defs as $def ) {
			$key  = $def['key'];
			$name = 'fields[' . $key . ']';
			$v    = $values[ $key ] ?? '';
			if ( ! empty( $def['managed_by'] ) ) {
				$shown = self::field_display( $def, $v );
				self::field_row( $def['label'], '<span class="fw-crm-readonly">' . esc_html( '' !== $shown ? $shown : '—' ) . ' <span class="fw-crm-managed">' . esc_html( FW_CRM_App::managed_label( $def['managed_by'] ) ) . '</span></span>' );
				continue;
			}
			switch ( $def['type'] ) {
				case 'textarea':
					$control = sprintf( '<textarea name="%s" rows="3">%s</textarea>', esc_attr( $name ), esc_textarea( (string) $v ) );
					self::field_row( $def['label'], $control, true );
					break;
				case 'number':
				case 'money':
				case 'lookup':
					self::field_row( $def['label'], self::input( $name, null === $v ? '' : $v, 'number', array( 'step' => 'any' ) ) );
					break;
				case 'date':
					self::field_row( $def['label'], self::input( $name, $v ? substr( (string) $v, 0, 10 ) : '', 'date' ) );
					break;
				case 'datetime':
					self::field_row( $def['label'], self::input( $name, $v ? str_replace( ' ', 'T', substr( (string) $v, 0, 16 ) ) : '', 'datetime-local' ) );
					break;
				case 'choice':
					$opts = '<option value="">' . esc_html__( 'Not set', 'fw' ) . '</option>';
					foreach ( (array) $def['choices'] as $c ) {
						$opts .= sprintf( '<option value="%1$s"%2$s>%1$s</option>', esc_attr( $c ), selected( (string) $v, $c, false ) );
					}
					self::field_row( $def['label'], '<select name="' . esc_attr( $name ) . '">' . $opts . '</select>' );
					break;
				case 'multichoice':
					$boxes = '';
					foreach ( (array) $def['choices'] as $c ) {
						$boxes .= sprintf( '<label class="fw-crm-check"><input type="checkbox" name="%s[]" value="%s"%s /> %s</label>', esc_attr( $name ), esc_attr( $c ), checked( in_array( $c, (array) $v, true ), true, false ), esc_html( $c ) );
					}
					self::field_row( $def['label'], '<span class="fw-crm-checks">' . $boxes . '</span>', true );
					break;
				case 'checkbox':
					self::field_row( $def['label'], sprintf( '<span class="fw-crm-checks"><label class="fw-crm-check"><input type="checkbox" name="%s" value="1"%s /> %s</label></span>', esc_attr( $name ), checked( (bool) $v, true, false ), esc_html__( 'Yes', 'fw' ) ) );
					break;
				case 'autonumber':
				case 'address':
					self::field_row( $def['label'], '<span class="fw-crm-readonly">' . esc_html( self::field_display( $def, $v ) ) . '</span>' );
					break;
				default:
					$type = in_array( $def['type'], array( 'url', 'email' ), true ) ? $def['type'] : ( 'phone' === $def['type'] ? 'tel' : 'text' );
					self::field_row( $def['label'], self::input( $name, (string) $v, $type ) );
			}
		}
	}

	/**
	 * @param array $def
	 * @param mixed $v
	 *
	 * @return string Plain text.
	 */
	private static function field_display( array $def, $v ) {
		if ( null === $v || '' === $v || array() === $v ) {
			return '';
		}
		switch ( $def['type'] ) {
			case 'multichoice':
				return implode( ', ', (array) $v );
			case 'checkbox':
				return $v ? __( 'Yes', 'fw' ) : __( 'No', 'fw' );
			case 'money':
				return number_format_i18n( (float) $v, 2 );
			case 'address':
				return implode( ', ', array_filter( array_map( 'strval', (array) $v ) ) );
			case 'date':
				return date_i18n( get_option( 'date_format' ), strtotime( (string) $v ) );
			case 'datetime':
				return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $v ) );
		}
		return (string) $v;
	}

	/**
	 * A definition list of label → value, skipping empty values.
	 *
	 * @param array $pairs label => [ text, url? ]
	 */
	private static function render_details( array $pairs ) {
		$shown = 0;
		echo '<dl class="fw-crm-details">';
		foreach ( $pairs as $label => $pair ) {
			if ( '' === (string) $pair[0] ) {
				continue;
			}
			++$shown;
			echo '<dt>' . esc_html( $label ) . '</dt><dd>';
			if ( ! empty( $pair[1] ) ) {
				printf( '<a href="%s">%s</a>', esc_url( $pair[1] ), esc_html( $pair[0] ) );
			} else {
				echo esc_html( $pair[0] );
			}
			echo '</dd>';
		}
		echo '</dl>';
		if ( ! $shown ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'Nothing filled in yet. Use Edit to add details.', 'fw' ) . '</p>';
		}
	}

	/**
	 * The note box and the record's timeline.
	 *
	 * @param string $object contact|company
	 * @param int    $id
	 */
	public static function render_timeline( $object, $id ) {
		$rows = FW_CRM_Activity::for_record( $object, $id, 100 );
		?>
		<section class="fw-crm-panel" aria-labelledby="fw-crm-h-timeline">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-timeline"><?php esc_html_e( 'Timeline', 'fw' ); ?></h2>
			</header>

			<form method="post" action="" class="fw-crm-note">
				<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
				<input type="hidden" name="fw_crm_action" value="add_note" />
				<input type="hidden" name="object" value="<?php echo esc_attr( $object ); ?>" />
				<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
				<label class="screen-reader-text" for="fw-crm-note"><?php esc_html_e( 'Add a note', 'fw' ); ?></label>
				<textarea id="fw-crm-note" name="note" rows="2" placeholder="<?php esc_attr_e( 'Add a note: a call, a meeting, anything worth remembering', 'fw' ); ?>"></textarea>
				<button type="submit" class="button"><?php esc_html_e( 'Save note', 'fw' ); ?></button>
			</form>

			<?php if ( ! $rows ) : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Nothing on the timeline yet.', 'fw' ); ?></p>
			<?php else : ?>
				<ol class="fw-crm-timeline">
					<?php foreach ( $rows as $r ) : ?>
						<?php $actor = (int) $r->actor_id ? get_userdata( (int) $r->actor_id ) : null; ?>
						<li class="fw-crm-timeline__item fw-crm-timeline__item--<?php echo esc_attr( $r->type ); ?>">
							<span class="fw-crm-feed__icon fw-crm-feed__icon--<?php echo esc_attr( $r->type ); ?>" aria-hidden="true">
								<span class="dashicons <?php echo esc_attr( self::activity_icon( $r->type ) ); ?>"></span>
							</span>
							<div class="fw-crm-timeline__body">
								<p class="fw-crm-timeline__title">
									<strong><?php echo esc_html( $r->title ); ?></strong>
									<?php if ( $actor ) : ?>
										<span class="fw-crm-muted"><?php echo esc_html( sprintf( __( 'by %s', 'fw' ), $actor->display_name ) ); ?></span>
									<?php endif; ?>
									<?php echo FW_CRM_App::time_ago( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</p>
								<?php if ( '' !== (string) $r->body ) : ?>
									<div class="fw-crm-timeline__text"><?php echo wp_kses_post( wpautop( $r->body ) ); ?></div>
								<?php endif; ?>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</section>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Contacts
	 * ---------------------------------------------------------------------- */

	/**
	 * The new-contact form.
	 */
	public static function render_contact_new() {
		$company = isset( $_GET['company'] ) ? (int) $_GET['company'] : 0; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-form fw-crm-form--page">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="create_contact" />
			<p class="fw-crm-muted fw-crm-form__intro"><?php esc_html_e( 'A contact needs at least an email, a name or a phone number. If the email already belongs to a contact, that contact is updated instead of a duplicate being made.', 'fw' ); ?></p>
			<div class="fw-crm-form__grid">
				<?php
				self::field_row( __( 'First name', 'fw' ), self::input( 'first_name', '', 'text', array( 'autofocus' => true, 'autocomplete' => 'off' ) ) );
				self::field_row( __( 'Last name', 'fw' ), self::input( 'last_name', '', 'text', array( 'autocomplete' => 'off' ) ) );
				self::field_row( __( 'Email', 'fw' ), self::input( 'email', '', 'email', array( 'autocomplete' => 'off' ) ) );
				self::field_row( __( 'Phone', 'fw' ), self::input( 'phone', '', 'tel', array( 'autocomplete' => 'off' ) ) );
				self::field_row( __( 'Job title', 'fw' ), self::input( 'job_title', '' ) );
				if ( FW_CRM_Modules::enabled( 'companies' ) ) {
					ob_start();
					self::company_select( 'company_id', $company, __( 'No company', 'fw' ) );
					self::field_row( __( 'Company', 'fw' ), ob_get_clean() );
				}
				ob_start();
				?>
				<select name="lifecycle_stage">
					<?php foreach ( FW_CRM_Contacts::stages() as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( 'lead', $slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php
				self::field_row( __( 'Stage', 'fw' ), ob_get_clean() );
				ob_start();
				self::owner_select( 'owner_id', get_current_user_id() );
				self::field_row( __( 'Owner', 'fw' ), ob_get_clean() );
				$lists = FW_CRM_Lists::all( 'list' );
				if ( $lists ) {
					$opts = '<option value="">' . esc_html__( 'Not on a list', 'fw' ) . '</option>';
					foreach ( $lists as $l ) {
						$opts .= sprintf( '<option value="%d">%s</option>', (int) $l->id, esc_html( $l->title ) );
					}
					self::field_row( __( 'Add to list', 'fw' ), '<select name="list">' . $opts . '</select>' );
				}
				self::render_field_inputs( 'contact', array() );
				?>
			</div>
			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Save contact', 'fw' ); ?></button>
				<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'subscribers' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
			<p class="fw-crm-muted"><?php esc_html_e( 'Adding someone here does not subscribe them to your emails. Email consent comes from the person: a signup form, a confirmed opt-in, or an import of people who agreed.', 'fw' ); ?></p>
		</form>
		<?php
	}

	/**
	 * The contact profile: header, then About | Timeline | Related.
	 *
	 * @param int $id
	 */
	public static function render_contact( $id ) {
		$c   = FW_CRM_Contacts::get( $id );
		$row = FW_CRM_Subscribers::find( $id );
		if ( $row && ! FW_CRM_Capabilities::can_edit_contact( $row ) ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'This contact belongs to someone else', 'fw' ), __( 'Sales reps see the contacts they own. Ask a CRM manager if it should be yours.', 'fw' ), array( array( __( 'Back to contacts', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'subscribers' ) ) ) );
			return;
		}
		if ( ! $c || ! $row ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Contact not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to contacts', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'subscribers' ) ) ) );
			return;
		}

		$base      = FW_CRM_Admin_Page::get_page_url();
		$name      = '' !== $c['name'] ? $c['name'] : ( '' !== $c['email'] ? $c['email'] : $c['phone'] );
		$companies = array_filter( array_map( array( 'FW_CRM_Companies', 'find' ), $c['company_ids'] ) );
		$primary   = $c['company_id'] ? FW_CRM_Companies::find( $c['company_id'] ) : null;
		$owner     = $c['owner_id'] ? get_userdata( $c['owner_id'] ) : null;
		$statuses  = array(
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'pending'      => __( 'Waiting to confirm', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Marked as spam', 'fw' ),
			'none'         => __( 'Not subscribed', 'fw' ),
		);
		$defs      = FW_CRM_Fields::definitions( 'contact' );
		if ( $c['deleted_at'] ) {
			self::render_binned( 'contact', $id, $c['deleted_at'] );
		}
		?>
		<div class="fw-crm-record">
			<section class="fw-crm-panel fw-crm-record__head">
				<?php echo FW_CRM_App::avatar( $name, 56 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="fw-crm-record__id">
					<h2><?php echo esc_html( $name ); ?></h2>
					<p class="fw-crm-record__sub">
						<?php
						$bits = array();
						if ( '' !== $c['job_title'] ) {
							$bits[] = esc_html( $c['job_title'] );
						}
						if ( $primary && FW_CRM_Modules::enabled( 'companies' ) ) {
							$bits[] = sprintf( '<a href="%s">%s</a>', esc_url( add_query_arg( array( 'tab' => 'companies', 'company' => (int) $primary->id ), $base ) ), esc_html( $primary->name ) );
						}
						echo implode( ' <span aria-hidden="true">·</span> ', $bits ); // phpcs:ignore WordPress.Security.EscapeOutput
						?>
					</p>
					<ul class="fw-crm-record__chips">
						<?php if ( '' !== $c['email'] ) : ?>
							<li><a href="mailto:<?php echo esc_attr( $c['email'] ); ?>"><span class="dashicons dashicons-email" aria-hidden="true"></span><?php echo esc_html( $c['email'] ); ?></a></li>
						<?php endif; ?>
						<?php if ( '' !== $c['phone'] ) : ?>
							<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $c['phone'] ) ); ?>"><span class="dashicons dashicons-phone" aria-hidden="true"></span><?php echo esc_html( $c['phone'] ); ?></a></li>
						<?php endif; ?>
						<li><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo $owner ? esc_html( sprintf( __( 'Owner: %s', 'fw' ), $owner->display_name ) ) : esc_html__( 'No owner', 'fw' ); ?></li>
					</ul>
				</div>
				<div class="fw-crm-record__actions">
					<form method="post" action="" class="fw-crm-stagepick" data-autosubmit>
						<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
						<input type="hidden" name="fw_crm_action" value="set_stage" />
						<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
						<label for="fw-crm-stage-pick"><?php esc_html_e( 'Stage', 'fw' ); ?></label>
						<select id="fw-crm-stage-pick" name="lifecycle_stage" class="fw-crm-stage-select fw-crm-stage--<?php echo esc_attr( $c['lifecycle_stage'] ); ?>">
							<?php foreach ( FW_CRM_Contacts::stages() as $slug => $label ) : ?>
								<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $c['lifecycle_stage'], $slug ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<noscript><button type="submit" class="button"><?php esc_html_e( 'Set', 'fw' ); ?></button></noscript>
					</form>
					<?php
					/**
					 * Buttons in a record's header (contact, company or deal), before Edit.
					 *
					 * @param string       $object contact|company|deal
					 * @param array|object $record
					 */
					do_action( 'unysonplus_crm_record_actions', 'contact', $c );
					?>
					<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
				</div>
			</section>

			<div class="fw-crm-record__cols">
				<div class="fw-crm-record__col">
					<section class="fw-crm-panel" id="fw-crm-about" aria-labelledby="fw-crm-h-about">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-about"><?php esc_html_e( 'About', 'fw' ); ?></h2>
							<button type="button" class="button-link" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
						</header>
						<div data-view>
							<?php
							$address = implode( ', ', array_filter( array( $c['address']['line1'], $c['address']['line2'], $c['address']['city'], $c['address']['region'], $c['address']['postcode'], $c['address']['country'] ) ) );
							$pairs   = array(
								__( 'Email', 'fw' )     => array( $c['email'], '' !== $c['email'] ? 'mailto:' . $c['email'] : '' ),
								__( 'Phone', 'fw' )     => array( $c['phone'] ),
								__( 'Job title', 'fw' ) => array( $c['job_title'] ),
								__( 'Address', 'fw' )   => array( $address ),
								__( 'Source', 'fw' )    => array( $c['source'] ),
								__( 'Added', 'fw' )     => array( $c['created_at'] ? date_i18n( get_option( 'date_format' ), strtotime( $c['created_at'] ) ) : '' ),
							);
							if ( (float) $c['lifetime_value'] ) {
								$pairs[ __( 'Lifetime value', 'fw' ) ] = array( number_format_i18n( $c['lifetime_value'], 2 ) );
							}
							if ( $c['user_id'] && ( $u = get_userdata( $c['user_id'] ) ) ) {
								$pairs[ __( 'Site account', 'fw' ) ] = array( $u->user_login, get_edit_user_link( $u->ID ) );
							}
							foreach ( $defs as $def ) {
								$pairs[ $def['label'] ] = array( self::field_display( $def, $c['fields'][ $def['key'] ] ?? '' ) );
							}
							self::render_details( $pairs );
							?>
						</div>
						<form method="post" action="" class="fw-crm-form" data-edit hidden>
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="save_contact" />
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
							<div class="fw-crm-form__grid">
								<?php
								self::field_row( __( 'First name', 'fw' ), self::input( 'first_name', $c['first_name'] ) );
								self::field_row( __( 'Last name', 'fw' ), self::input( 'last_name', $c['last_name'] ) );
								self::field_row( __( 'Email', 'fw' ), self::input( 'email', $c['email'], 'email' ), true );
								self::field_row( __( 'Phone', 'fw' ), self::input( 'phone', $c['phone'], 'tel' ) );
								self::field_row( __( 'Job title', 'fw' ), self::input( 'job_title', $c['job_title'] ) );
								ob_start();
								self::owner_select( 'owner_id', $c['owner_id'] );
								self::field_row( __( 'Owner', 'fw' ), ob_get_clean(), true );
								echo '<p class="fw-crm-form__group">' . esc_html__( 'Address', 'fw' ) . '</p>';
								self::field_row( __( 'Street', 'fw' ), self::input( 'address_line1', $c['address']['line1'] ), true );
								self::field_row( __( 'Street, line 2', 'fw' ), self::input( 'address_line2', $c['address']['line2'] ), true );
								self::field_row( __( 'City', 'fw' ), self::input( 'city', $c['address']['city'] ) );
								self::field_row( __( 'Region', 'fw' ), self::input( 'region', $c['address']['region'] ) );
								self::field_row( __( 'Postcode', 'fw' ), self::input( 'postcode', $c['address']['postcode'] ) );
								self::field_row( __( 'Country (2 letters)', 'fw' ), self::input( 'country', $c['address']['country'], 'text', array( 'maxlength' => 2 ) ) );
								self::render_field_inputs( 'contact', $c['fields'] );
								?>
							</div>
							<p class="fw-crm-form__foot">
								<button type="submit" class="button button-primary"><?php esc_html_e( 'Save changes', 'fw' ); ?></button>
								<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
							</p>
						</form>
					</section>
				</div>

				<div class="fw-crm-record__col fw-crm-record__col--wide">
					<?php self::render_timeline( 'contact', $id ); ?>
					<?php
					/**
					 * Fires under the contact's timeline. The Forms bridge lists the person's
					 * form entries here.
					 *
					 * @param object $subscriber The contact row.
					 */
					do_action( 'unysonplus_crm_subscriber_view_after', $row );
					FW_CRM_App::render_panels( 'contact', $c, 'main' );
					?>
				</div>

				<div class="fw-crm-record__col">
					<?php if ( FW_CRM_Modules::enabled( 'companies' ) ) : ?>
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-companies">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-companies"><?php esc_html_e( 'Companies', 'fw' ); ?></h2>
						</header>
						<?php if ( $companies ) : ?>
							<ul class="fw-crm-people">
								<?php foreach ( $companies as $co ) : ?>
									<li>
										<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'companies', 'company' => (int) $co->id ), $base ) ); ?>">
											<?php echo FW_CRM_App::avatar( $co->name, 28, 'square' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<span class="fw-crm-people__text">
												<span class="fw-crm-people__name"><?php echo esc_html( $co->name ); ?></span>
												<span class="fw-crm-people__sub"><?php echo (int) $co->id === (int) $c['company_id'] ? esc_html__( 'Main company', 'fw' ) : esc_html( (string) $co->domain ); ?></span>
											</span>
										</a>
										<a class="fw-crm-unlink" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'unlink_company', 'id' => $id, 'company' => (int) $co->id, 'tab' => 'subscribers' ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"
										   aria-label="<?php echo esc_attr( sprintf( __( 'Unlink %s', 'fw' ), $co->name ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php endif; ?>
						<form method="post" action="" class="fw-crm-inline">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="link_company" />
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
							<label class="screen-reader-text" for="fw-crm-link-co"><?php esc_html_e( 'Company', 'fw' ); ?></label>
							<?php
							ob_start();
							self::company_select( 'company_id', 0, __( 'Link a company…', 'fw' ) );
							echo str_replace( '<select ', '<select id="fw-crm-link-co" ', ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput
							?>
							<label class="screen-reader-text" for="fw-crm-new-co"><?php esc_html_e( 'Or a new company name', 'fw' ); ?></label>
							<input type="text" id="fw-crm-new-co" name="company_name" placeholder="<?php esc_attr_e( 'or type a new company', 'fw' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Link', 'fw' ); ?></button>
						</form>
					</section>
					<?php endif; ?>

					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-email">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-email"><?php esc_html_e( 'Email', 'fw' ); ?></h2>
							<span class="fw-crm-status fw-crm-status--<?php echo esc_attr( $c['status'] ); ?>"><?php echo esc_html( $statuses[ $c['status'] ] ?? $c['status'] ); ?></span>
						</header>
						<?php self::render_membership( $id ); ?>
						<?php if ( in_array( $c['status'], array( 'subscribed', 'pending' ), true ) ) : ?>
							<p class="fw-crm-inline">
								<?php if ( 'pending' === $c['status'] ) : ?>
									<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'from', 'profile', FW_CRM_Admin_Page::action_url( 'resend', $id ) ) ); ?>"><?php esc_html_e( 'Resend confirmation', 'fw' ); ?></a>
								<?php endif; ?>
								<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'from', 'profile', FW_CRM_Admin_Page::action_url( 'unsubscribe', $id ) ) ); ?>"><?php esc_html_e( 'Unsubscribe', 'fw' ); ?></a>
							</p>
						<?php endif; ?>
					</section>

					<?php
					if ( FW_CRM_Capabilities::can_manage() ) {
						self::render_duplicates_panel( $id );
					}
					?>

					<?php FW_CRM_App::render_panels( 'contact', $c, 'side' ); ?>

					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-consent">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-consent"><?php esc_html_e( 'Consent', 'fw' ); ?></h2>
						</header>
						<?php self::render_consent( $id ); ?>
					</section>

					<p class="fw-crm-record__delete">
						<?php if ( ! $c['deleted_at'] ) : ?>
							<a class="button-link button-link-delete" href="<?php echo esc_url( FW_CRM_Admin_Page::action_url( 'trash', $id ) ); ?>"><?php esc_html_e( 'Move to bin', 'fw' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Lists and tags as checkboxes (the existing save_membership action).
	 *
	 * @param int $id
	 */
	private static function render_membership( $id ) {
		$all_lists = FW_CRM_Lists::all( 'list' );
		$all_tags  = FW_CRM_Lists::all( 'tag' );
		if ( ! $all_lists && ! $all_tags ) {
			printf( '<p class="fw-crm-muted">%s <a href="%s">%s</a></p>', esc_html__( 'No lists or tags yet.', 'fw' ), esc_url( FW_CRM_Admin_Page::get_page_url( 'lists' ) ), esc_html__( 'Create one', 'fw' ) );
			return;
		}
		$mine = array(
			'list' => array_map( 'intval', wp_list_pluck( FW_CRM_Subscribers::get_lists( $id, 'list' ), 'id' ) ),
			'tag'  => array_map( 'intval', wp_list_pluck( FW_CRM_Subscribers::get_lists( $id, 'tag' ), 'id' ) ),
		);
		?>
		<form method="post" action="" class="fw-crm-membership">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="save_membership" />
			<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
			<?php foreach ( array( 'list' => __( 'Lists', 'fw' ), 'tag' => __( 'Tags', 'fw' ) ) as $type => $label ) : ?>
				<?php $rows = 'list' === $type ? $all_lists : $all_tags; ?>
				<?php if ( $rows ) : ?>
					<fieldset class="fw-crm-membership__set">
						<legend><?php echo esc_html( $label ); ?></legend>
						<?php foreach ( $rows as $r ) : ?>
							<?php $managed = ! empty( $r->managed_by ); ?>
							<label class="fw-crm-check<?php echo $managed ? ' is-managed' : ''; ?>"<?php echo $managed ? ' title="' . esc_attr( FW_CRM_App::managed_label( $r->managed_by ) ) . '"' : ''; ?>>
								<input type="checkbox" <?php echo $managed ? 'disabled' : 'name="' . esc_attr( $type ) . 's[]"'; ?> value="<?php echo (int) $r->id; ?>" <?php checked( in_array( (int) $r->id, $mine[ $type ], true ) ); ?> />
								<?php echo esc_html( $r->title ); ?>
								<?php if ( $managed ) : ?>
									<span class="screen-reader-text"><?php echo esc_html( '(' . FW_CRM_App::managed_label( $r->managed_by ) . ')' ); ?></span>
									<span class="dashicons dashicons-lock" aria-hidden="true"></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
					</fieldset>
				<?php endif; ?>
			<?php endforeach; ?>
			<p><button type="submit" class="button button-small"><?php esc_html_e( 'Save lists & tags', 'fw' ); ?></button></p>
		</form>
		<?php
		$left = array_filter( FW_CRM_List_Membership::for_contact( $id ), function ( $m ) {
			return FW_CRM_List_Membership::UNSUBSCRIBED === $m->status;
		} );
		if ( $left ) :
			?>
			<p class="fw-crm-muted fw-crm-left-lists">
				<?php
				echo esc_html( implode( '; ', array_map( function ( $m ) {
					return sprintf(
						/* translators: 1: list, 2: date */
						__( 'Left %1$s on %2$s', 'fw' ),
						$m->title,
						$m->unsubscribed_at ? date_i18n( get_option( 'date_format' ), strtotime( $m->unsubscribed_at ) ) : '?'
					);
				}, $left ) ) );
				?>
			</p>
			<?php
		endif;
	}

	/**
	 * @param int $id
	 */
	private static function render_consent( $id ) {
		$rows = FW_CRM_Consent::for_contact( $id );
		if ( ! $rows ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'No consent recorded. When this person signs up or ticks a consent box, the exact wording they agreed to is kept here.', 'fw' ) . '</p>';
			return;
		}
		echo '<ol class="fw-crm-consent">';
		foreach ( $rows as $r ) {
			printf(
				'<li><p><span class="fw-crm-status fw-crm-status--%1$s">%2$s</span> <strong>%3$s</strong> %4$s</p><p class="fw-crm-consent__text">%5$s</p>%6$s</li>',
				(int) $r->granted ? 'subscribed' : 'unsubscribed',
				(int) $r->granted ? esc_html__( 'Agreed', 'fw' ) : esc_html__( 'Withdrawn', 'fw' ),
				esc_html( ucfirst( str_replace( '_', ' ', (string) $r->purpose ) ) ),
				FW_CRM_App::time_ago( $r->created_at ), // phpcs:ignore WordPress.Security.EscapeOutput
				esc_html( (string) $r->text ),
				'' !== (string) $r->source_url ? '<p class="fw-crm-muted">' . sprintf( esc_html__( 'On %s', 'fw' ), '<a href="' . esc_url( $r->source_url ) . '" target="_blank" rel="noopener">' . esc_html( wp_parse_url( $r->source_url, PHP_URL_PATH ) ?: $r->source_url ) . '</a>' ) . '</p>' : ''
			);
		}
		echo '</ol>';
	}

	/**
	 * "Possible duplicates" on a contact page (same phone or same full name).
	 *
	 * @param int $id
	 */
	private static function render_duplicates_panel( $id ) {
		$dupes = FW_CRM_Merge::duplicates_of( $id, 5 );
		if ( ! $dupes ) {
			return;
		}
		$base = FW_CRM_Admin_Page::get_page_url();
		?>
		<section class="fw-crm-panel fw-crm-dupes" aria-labelledby="fw-crm-h-dupes">
			<header class="fw-crm-panel__head">
				<h2 id="fw-crm-h-dupes"><?php esc_html_e( 'Possible duplicates', 'fw' ); ?></h2>
			</header>
			<ul class="fw-crm-people">
				<?php foreach ( $dupes as $d ) : ?>
					<?php $dn = trim( $d->first_name . ' ' . $d->last_name ); ?>
					<li>
						<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $d->id ), $base ) ); ?>">
							<?php echo FW_CRM_App::avatar( '' !== $dn ? $dn : (string) $d->email, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
							<span class="fw-crm-people__text">
								<span class="fw-crm-people__name"><?php echo esc_html( '' !== $dn ? $dn : (string) ( $d->email ?: $d->phone ) ); ?></span>
								<span class="fw-crm-people__sub"><?php echo 'phone' === $d->why ? esc_html__( 'Same phone number', 'fw' ) : esc_html__( 'Same name', 'fw' ); ?></span>
							</span>
						</a>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'merge' => $id, 'with' => (int) $d->id ), $base ) ); ?>"><?php esc_html_e( 'Merge…', 'fw' ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<?php
	}

	/**
	 * The merge screen: the two records side by side, a choice for every field
	 * where they differ, and what will move.
	 *
	 * @param int $keep_id
	 * @param int $other_id
	 */
	public static function render_merge( $keep_id, $other_id ) {
		$keep  = FW_CRM_Subscribers::find( $keep_id );
		$other = FW_CRM_Subscribers::find( $other_id );
		$base  = FW_CRM_Admin_Page::get_page_url();
		if ( ! $keep || ! $other || (int) $keep->id === (int) $other->id ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Nothing to merge', 'fw' ), __( 'One of the contacts no longer exists.', 'fw' ), array( array( __( 'Back to contacts', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'subscribers' ) ) ) );
			return;
		}
		$same = trim( $keep->first_name . ' ' . $keep->last_name ) === trim( $other->first_name . ' ' . $other->last_name );
		$name = function ( $r ) use ( $same ) {
			$n = trim( $r->first_name . ' ' . $r->last_name );
			$n = '' !== $n ? $n : (string) ( $r->email ?: $r->phone );
			// Two records with the same name: tell them apart by email or phone.
			$tag = (string) ( $r->email ?: $r->phone );
			return $same && '' !== $tag && $tag !== $n ? sprintf( '%s (%s)', $n, $tag ) : $n;
		};
		$labels = array(
			'email'         => __( 'Email', 'fw' ),
			'first_name'    => __( 'First name', 'fw' ),
			'last_name'     => __( 'Last name', 'fw' ),
			'phone'         => __( 'Phone', 'fw' ),
			'job_title'     => __( 'Job title', 'fw' ),
			'owner_id'      => __( 'Owner', 'fw' ),
			'company_id'    => __( 'Main company', 'fw' ),
			'timezone'      => __( 'Time zone', 'fw' ),
			'address_line1' => __( 'Street', 'fw' ),
			'address_line2' => __( 'Street, line 2', 'fw' ),
			'city'          => __( 'City', 'fw' ),
			'region'        => __( 'Region', 'fw' ),
			'postcode'      => __( 'Postcode', 'fw' ),
			'country'       => __( 'Country', 'fw' ),
			'source'        => __( 'Source', 'fw' ),
		);
		$show = function ( $f, $v ) {
			if ( 'owner_id' === $f ) {
				$u = (int) $v ? get_userdata( (int) $v ) : null;
				return $u ? $u->display_name : '';
			}
			if ( 'company_id' === $f ) {
				$co = (int) $v ? FW_CRM_Companies::find( (int) $v ) : null;
				return $co ? $co->name : '';
			}
			return (string) $v;
		};
		$rows = array();
		foreach ( FW_CRM_Merge::CHOICE_FIELDS as $f ) {
			$a = $show( $f, $keep->$f );
			$b = $show( $f, $other->$f );
			if ( $a !== $b ) {
				$rows[ $f ] = array( $a, $b );
			}
		}
		$counts = array(
			__( 'Timeline entries', 'fw' )      => count( FW_CRM_Activity::for_record( 'contact', (int) $other->id, 1000 ) ),
			__( 'Consent records', 'fw' )       => count( FW_CRM_Consent::for_contact( (int) $other->id ) ),
			__( 'Lists and tags', 'fw' )        => count( FW_CRM_Subscribers::get_lists( (int) $other->id, 'list' ) ) + count( FW_CRM_Subscribers::get_lists( (int) $other->id, 'tag' ) ),
			__( 'Linked companies', 'fw' )      => count( FW_CRM_Relations::ids( 'contact', (int) $other->id, 'company' ) ),
		);
		?>
		<form method="post" action="" class="fw-crm-panel fw-crm-merge">
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="merge_contacts" />
			<input type="hidden" name="id" value="<?php echo (int) $keep->id; ?>" />
			<input type="hidden" name="with" value="<?php echo (int) $other->id; ?>" />

			<p class="fw-crm-merge__intro">
				<?php
				printf(
					/* translators: 1: contact kept, 2: contact removed */
					esc_html__( '%1$s stays. Everything from %2$s moves onto it, then %2$s is deleted. This cannot be undone.', 'fw' ),
					'<strong>' . esc_html( $name( $keep ) ) . '</strong>',
					'<strong>' . esc_html( $name( $other ) ) . '</strong>'
				);
				?>
				<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'merge' => (int) $other->id, 'with' => (int) $keep->id ), $base ) ); ?>"><?php esc_html_e( 'Keep the other one instead', 'fw' ); ?></a>
			</p>

			<?php if ( $rows ) : ?>
				<table class="widefat fw-crm-merge__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Field', 'fw' ); ?></th>
							<th scope="col"><?php echo esc_html( sprintf( __( 'Keep (%s)', 'fw' ), $name( $keep ) ) ); ?></th>
							<th scope="col"><?php echo esc_html( sprintf( __( 'From %s', 'fw' ), $name( $other ) ) ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $rows as $f => $vals ) : ?>
							<?php $default = '' === $vals[0] ? 'other' : 'keep'; ?>
							<tr>
								<th scope="row"><?php echo esc_html( $labels[ $f ] ?? $f ); ?></th>
								<?php foreach ( array( 'keep' => $vals[0], 'other' => $vals[1] ) as $side => $v ) : ?>
									<td>
										<label class="fw-crm-check">
											<input type="radio" name="pick_<?php echo esc_attr( $f ); ?>" value="<?php echo esc_attr( $side ); ?>" <?php checked( $default, $side ); ?> />
											<?php echo '' !== $v ? esc_html( $v ) : '<span class="fw-crm-muted">' . esc_html__( 'empty', 'fw' ) . '</span>'; ?>
										</label>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="fw-crm-muted"><?php esc_html_e( 'Their details are the same, so there is nothing to choose.', 'fw' ); ?></p>
			<?php endif; ?>

			<h3><?php esc_html_e( 'What moves', 'fw' ); ?></h3>
			<ul class="fw-crm-merge__moves">
				<?php foreach ( $counts as $label => $n ) : ?>
					<li><?php echo esc_html( $label ); ?>: <strong><?php echo esc_html( number_format_i18n( $n ) ); ?></strong></li>
				<?php endforeach; ?>
				<li><?php esc_html_e( 'Custom field values the kept contact has no value for, campaign history and the site account link', 'fw' ); ?></li>
			</ul>
			<p class="fw-crm-muted"><?php esc_html_e( 'Email permission is kept on the safe side: if either record unsubscribed, bounced or marked your emails as spam, the merged contact keeps that. The more advanced lifecycle stage is kept, lifetime values are added together.', 'fw' ); ?></p>

			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php echo esc_html( sprintf( __( 'Merge into %s', 'fw' ), $name( $keep ) ) ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $keep->id ), $base ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
			</p>
		</form>
		<?php
	}

	/**
	 * Every group of likely duplicates.
	 */
	public static function render_duplicates() {
		$groups = FW_CRM_Merge::find_groups( 100 );
		$base   = FW_CRM_Admin_Page::get_page_url();
		if ( ! $groups ) {
			FW_CRM_App::empty_state( 'dashicons-yes-alt', __( 'No duplicates found', 'fw' ), __( 'No two contacts share a phone number or a full name. (Two contacts can never share an email address.)', 'fw' ), array( array( __( 'Back to contacts', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'subscribers' ) ) ) );
			return;
		}
		?>
		<p class="fw-crm-muted"><?php esc_html_e( 'Contacts that share a phone number or a full name. Open a group to compare two of them and merge.', 'fw' ); ?></p>
		<div class="fw-crm-dupe-groups">
			<?php foreach ( $groups as $g ) : ?>
				<section class="fw-crm-panel">
					<header class="fw-crm-panel__head">
						<h2>
							<?php
							if ( 'name_phone' === $g['why'] ) {
								esc_html_e( 'Same name and phone number', 'fw' );
							} elseif ( 'phone' === $g['why'] ) {
								esc_html_e( 'Same phone number', 'fw' );
							} else {
								echo esc_html( sprintf( __( 'Named %s', 'fw' ), $g['value'] ) );
							}
							?>
						</h2>
						<a class="button button-small" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'merge' => $g['ids'][0], 'with' => $g['ids'][1] ), $base ) ); ?>"><?php esc_html_e( 'Compare and merge', 'fw' ); ?></a>
					</header>
					<ul class="fw-crm-people">
						<?php foreach ( $g['ids'] as $cid ) : ?>
							<?php
							$r = FW_CRM_Subscribers::find( $cid );
							if ( ! $r ) {
								continue;
							}
							$rn = trim( $r->first_name . ' ' . $r->last_name );
							?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $r->id ), $base ) ); ?>">
									<?php echo FW_CRM_App::avatar( '' !== $rn ? $rn : (string) $r->email, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="fw-crm-people__text">
										<span class="fw-crm-people__name"><?php echo esc_html( '' !== $rn ? $rn : (string) ( $r->email ?: $r->phone ) ); ?></span>
										<span class="fw-crm-people__sub"><?php echo esc_html( implode( ' · ', array_filter( array( (string) $r->email, (string) $r->phone ) ) ) ); ?></span>
									</span>
									<?php echo FW_CRM_App::time_ago( $r->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Companies
	 * ---------------------------------------------------------------------- */

	/**
	 * The companies list.
	 */
	public static function render_companies() {
		// phpcs:disable WordPress.Security.NonceVerification
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		$per   = 25;
		$args  = FW_CRM_Capabilities::scope( array( 'search' => $search, 'per_page' => $per, 'paged' => $paged ) );
		$total = FW_CRM_Companies::count( $args );
		$rows  = FW_CRM_Companies::query( $args );
		$base  = FW_CRM_Admin_Page::get_page_url( 'companies' );

		if ( ! $total && '' === $search ) {
			FW_CRM_App::empty_state(
				'dashicons-building',
				__( 'No companies yet', 'fw' ),
				__( 'Companies group the people who work at the same organisation, so you see every contact, note and email for that account in one place.', 'fw' ),
				array( array( __( 'Add a company', 'fw' ), add_query_arg( 'new', 1, $base ), true ) )
			);
			return;
		}
		?>
		<div class="fw-crm-listbar">
			<form method="get" action="" class="fw-crm-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="companies" />
				<label class="screen-reader-text" for="fw-crm-co-s"><?php esc_html_e( 'Search companies', 'fw' ); ?></label>
				<input type="search" id="fw-crm-co-s" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Name, domain or email', 'fw' ); ?>" />
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'fw' ); ?></button>
			</form>
			<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'new', 1, $base ) ); ?>"><?php esc_html_e( 'New company', 'fw' ); ?></a>
		</div>

		<table class="wp-list-table widefat striped fw-crm-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Company', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'People', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Industry', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Owner', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last activity', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No company matches that search.', 'fw' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $co ) : ?>
					<?php $owner = (int) $co->owner_id ? get_userdata( (int) $co->owner_id ) : null; ?>
					<tr>
						<td>
							<div class="fw-crm-who">
								<?php echo FW_CRM_App::avatar( $co->name, 32, 'square' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<div class="fw-crm-who__text">
									<a class="fw-crm-who__name row-title" href="<?php echo esc_url( add_query_arg( 'company', (int) $co->id, $base ) ); ?>"><?php echo esc_html( $co->name ); ?></a>
									<?php if ( '' !== (string) $co->domain ) : ?>
										<span class="fw-crm-who__sub"><?php echo esc_html( $co->domain ); ?></span>
									<?php endif; ?>
								</div>
							</div>
						</td>
						<td><?php echo esc_html( number_format_i18n( count( FW_CRM_Companies::contact_ids( (int) $co->id ) ) ) ); ?></td>
						<td><?php echo '' !== (string) $co->industry ? esc_html( $co->industry ) : '<span aria-hidden="true">—</span>'; ?></td>
						<td><?php echo $owner ? esc_html( $owner->display_name ) : '<span aria-hidden="true">—</span>'; ?></td>
						<td><?php echo FW_CRM_App::time_ago( $co->last_activity_at ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'base'    => add_query_arg( 'paged', '%#%', add_query_arg( 's', $search, $base ) ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}
	}

	/**
	 * The company form, for both new and edit.
	 *
	 * @param object|null $co
	 * @param bool        $page Whether it stands alone (new) or sits in the About panel.
	 */
	private static function render_company_form( $co, $page ) {
		$v = function ( $k ) use ( $co ) {
			return $co ? (string) $co->$k : '';
		};
		?>
		<form method="post" action="" class="fw-crm-form<?php echo $page ? ' fw-crm-panel fw-crm-form--page' : ''; ?>"<?php echo $page ? '' : ' data-edit hidden'; ?>>
			<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
			<input type="hidden" name="fw_crm_action" value="<?php echo $co ? 'save_company' : 'create_company'; ?>" />
			<?php if ( $co ) : ?>
				<input type="hidden" name="id" value="<?php echo (int) $co->id; ?>" />
			<?php endif; ?>
			<div class="fw-crm-form__grid">
				<?php
				self::field_row( __( 'Name', 'fw' ), self::input( 'name', $v( 'name' ), 'text', $co ? array( 'required' => true ) : array( 'required' => true, 'autofocus' => true ) ), true );
				self::field_row( __( 'Website', 'fw' ), self::input( 'website', $v( 'website' ), 'url', array( 'placeholder' => 'https://' ) ), true );
				self::field_row( __( 'Phone', 'fw' ), self::input( 'phone', $v( 'phone' ), 'tel' ) );
				self::field_row( __( 'Email', 'fw' ), self::input( 'email', $v( 'email' ), 'email' ) );
				self::field_row( __( 'Industry', 'fw' ), self::input( 'industry', $v( 'industry' ) ) );
				self::field_row( __( 'Size', 'fw' ), self::input( 'size', $v( 'size' ), 'text', array( 'placeholder' => __( 'e.g. 11-50', 'fw' ) ) ) );
				ob_start();
				self::owner_select( 'owner_id', $co ? (int) $co->owner_id : get_current_user_id() );
				self::field_row( __( 'Owner', 'fw' ), ob_get_clean(), true );
				echo '<p class="fw-crm-form__group">' . esc_html__( 'Address', 'fw' ) . '</p>';
				self::field_row( __( 'Street', 'fw' ), self::input( 'address_line1', $v( 'address_line1' ) ), true );
				self::field_row( __( 'City', 'fw' ), self::input( 'city', $v( 'city' ) ) );
				self::field_row( __( 'Region', 'fw' ), self::input( 'region', $v( 'region' ) ) );
				self::field_row( __( 'Postcode', 'fw' ), self::input( 'postcode', $v( 'postcode' ) ) );
				self::field_row( __( 'Country (2 letters)', 'fw' ), self::input( 'country', $v( 'country' ), 'text', array( 'maxlength' => 2 ) ) );
				self::render_field_inputs( 'company', $co ? FW_CRM_Fields::get_values( 'company', (int) $co->id ) : array() );
				?>
			</div>
			<p class="fw-crm-form__foot">
				<button type="submit" class="button button-primary"><?php echo $co ? esc_html__( 'Save changes', 'fw' ) : esc_html__( 'Save company', 'fw' ); ?></button>
				<?php if ( $page ) : ?>
					<a class="button" href="<?php echo esc_url( FW_CRM_Admin_Page::get_page_url( 'companies' ) ); ?>"><?php esc_html_e( 'Cancel', 'fw' ); ?></a>
				<?php else : ?>
					<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Cancel', 'fw' ); ?></button>
				<?php endif; ?>
			</p>
		</form>
		<?php
	}

	/** The new-company form. */
	public static function render_company_new() {
		self::render_company_form( null, true );
	}

	/**
	 * The company profile.
	 *
	 * @param int $id
	 */
	public static function render_company( $id ) {
		$co = FW_CRM_Companies::find( $id );
		if ( $co && ! FW_CRM_Capabilities::can_edit_company( $co ) ) {
			FW_CRM_App::empty_state( 'dashicons-lock', __( 'This company belongs to someone else', 'fw' ), __( 'Sales reps see the companies they own. Ask a CRM manager if it should be yours.', 'fw' ), array( array( __( 'Back to companies', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'companies' ) ) ) );
			return;
		}
		if ( ! $co ) {
			FW_CRM_App::empty_state( 'dashicons-warning', __( 'Company not found', 'fw' ), __( 'It may have been deleted.', 'fw' ), array( array( __( 'Back to companies', 'fw' ), FW_CRM_Admin_Page::get_page_url( 'companies' ) ) ) );
			return;
		}
		$base   = FW_CRM_Admin_Page::get_page_url();
		$people = array_filter( array_map( array( 'FW_CRM_Subscribers', 'find' ), FW_CRM_Companies::contact_ids( $id ) ) );
		$owner  = (int) $co->owner_id ? get_userdata( (int) $co->owner_id ) : null;
		$values = FW_CRM_Fields::get_values( 'company', $id );
		$site   = '' !== (string) $co->website ? $co->website : ( '' !== (string) $co->domain ? 'https://' . $co->domain : '' );
		if ( $co->deleted_at ) {
			self::render_binned( 'company', $id, $co->deleted_at );
		}
		?>
		<div class="fw-crm-record">
			<section class="fw-crm-panel fw-crm-record__head">
				<?php echo FW_CRM_App::avatar( $co->name, 56, 'square' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<div class="fw-crm-record__id">
					<h2><?php echo esc_html( $co->name ); ?></h2>
					<p class="fw-crm-record__sub"><?php echo esc_html( implode( ' · ', array_filter( array( (string) $co->industry, (string) $co->size ? sprintf( __( '%s people', 'fw' ), $co->size ) : '' ) ) ) ); ?></p>
					<ul class="fw-crm-record__chips">
						<?php if ( $site ) : ?>
							<li><a href="<?php echo esc_url( $site ); ?>" target="_blank" rel="noopener"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><?php echo esc_html( '' !== (string) $co->domain ? $co->domain : $site ); ?></a></li>
						<?php endif; ?>
						<?php if ( '' !== (string) $co->phone ) : ?>
							<li><a href="tel:<?php echo esc_attr( preg_replace( '/[^0-9+]/', '', $co->phone ) ); ?>"><span class="dashicons dashicons-phone" aria-hidden="true"></span><?php echo esc_html( $co->phone ); ?></a></li>
						<?php endif; ?>
						<li><span class="dashicons dashicons-admin-users" aria-hidden="true"></span><?php echo $owner ? esc_html( sprintf( __( 'Owner: %s', 'fw' ), $owner->display_name ) ) : esc_html__( 'No owner', 'fw' ); ?></li>
					</ul>
				</div>
				<div class="fw-crm-record__actions">
					<?php do_action( 'unysonplus_crm_record_actions', 'company', $co ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented above. ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'new' => 1, 'company' => $id ), $base ) ); ?>"><?php esc_html_e( 'Add a person', 'fw' ); ?></a>
					<button type="button" class="button" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
				</div>
			</section>

			<div class="fw-crm-record__cols">
				<div class="fw-crm-record__col">
					<section class="fw-crm-panel" id="fw-crm-about" aria-labelledby="fw-crm-h-about">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-about"><?php esc_html_e( 'About', 'fw' ); ?></h2>
							<button type="button" class="button-link" data-edit-toggle="fw-crm-about"><?php esc_html_e( 'Edit', 'fw' ); ?></button>
						</header>
						<div data-view>
							<?php
							$address = implode( ', ', array_filter( array( (string) $co->address_line1, (string) $co->address_line2, (string) $co->city, (string) $co->region, (string) $co->postcode, (string) $co->country ) ) );
							$pairs   = array(
								__( 'Website', 'fw' )  => array( (string) $co->website, (string) $co->website ),
								__( 'Email', 'fw' )    => array( (string) $co->email, '' !== (string) $co->email ? 'mailto:' . $co->email : '' ),
								__( 'Phone', 'fw' )    => array( (string) $co->phone ),
								__( 'Industry', 'fw' ) => array( (string) $co->industry ),
								__( 'Size', 'fw' )     => array( (string) $co->size ),
								__( 'Address', 'fw' )  => array( $address ),
								__( 'Added', 'fw' )    => array( date_i18n( get_option( 'date_format' ), strtotime( $co->created_at ) ) ),
							);
							foreach ( FW_CRM_Fields::definitions( 'company' ) as $def ) {
								$pairs[ $def['label'] ] = array( self::field_display( $def, $values[ $def['key'] ] ?? '' ) );
							}
							self::render_details( $pairs );
							?>
						</div>
						<?php self::render_company_form( $co, false ); ?>
					</section>
				</div>

				<div class="fw-crm-record__col fw-crm-record__col--wide">
					<?php self::render_timeline( 'company', $id ); ?>
					<?php FW_CRM_App::render_panels( 'company', $co, 'main' ); ?>
				</div>

				<div class="fw-crm-record__col">
					<section class="fw-crm-panel" aria-labelledby="fw-crm-h-people">
						<header class="fw-crm-panel__head">
							<h2 id="fw-crm-h-people"><?php esc_html_e( 'People', 'fw' ); ?></h2>
							<span class="fw-crm-muted"><?php echo esc_html( number_format_i18n( count( $people ) ) ); ?></span>
						</header>
						<?php if ( $people ) : ?>
							<ul class="fw-crm-people">
								<?php foreach ( $people as $p ) : ?>
									<?php $pn = trim( $p->first_name . ' ' . $p->last_name ); ?>
									<li>
										<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $p->id ), $base ) ); ?>">
											<?php echo FW_CRM_App::avatar( '' !== $pn ? $pn : (string) $p->email, 28 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<span class="fw-crm-people__text">
												<span class="fw-crm-people__name"><?php echo esc_html( '' !== $pn ? $pn : (string) $p->email ); ?></span>
												<span class="fw-crm-people__sub"><?php echo esc_html( '' !== (string) $p->job_title ? $p->job_title : (string) $p->email ); ?></span>
											</span>
										</a>
										<a class="fw-crm-unlink" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'fw_crm_action' => 'unlink_company', 'id' => (int) $p->id, 'company' => $id, 'from' => 'company', 'tab' => 'companies' ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"
										   aria-label="<?php echo esc_attr( sprintf( __( 'Unlink %s', 'fw' ), '' !== $pn ? $pn : $p->email ) ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></a>
									</li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p class="fw-crm-muted"><?php esc_html_e( 'Nobody is linked to this company yet.', 'fw' ); ?></p>
						<?php endif; ?>
						<form method="post" action="" class="fw-crm-inline">
							<?php wp_nonce_field( FW_CRM_Admin_Page::NONCE ); ?>
							<input type="hidden" name="fw_crm_action" value="link_contact" />
							<input type="hidden" name="id" value="<?php echo (int) $id; ?>" />
							<label class="screen-reader-text" for="fw-crm-link-person"><?php esc_html_e( 'Email of an existing contact', 'fw' ); ?></label>
							<input type="email" id="fw-crm-link-person" name="email" required placeholder="<?php esc_attr_e( 'Email of an existing contact', 'fw' ); ?>" />
							<button type="submit" class="button"><?php esc_html_e( 'Link', 'fw' ); ?></button>
						</form>
					</section>

					<?php FW_CRM_App::render_panels( 'company', $co, 'side' ); ?>

					<p class="fw-crm-record__delete">
						<?php if ( ! $co->deleted_at ) : ?>
							<a class="button-link button-link-delete" href="<?php echo esc_url( self::action( 'trash_company', $id ) ); ?>"><?php esc_html_e( 'Move to bin', 'fw' ); ?></a>
						<?php endif; ?>
					</p>
				</div>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Bin
	 * ---------------------------------------------------------------------- */

	/**
	 * Contacts and companies in the bin, newest first.
	 */
	public static function render_bin() {
		$base      = FW_CRM_Admin_Page::get_page_url();
		$contacts  = FW_CRM_Subscribers::query( array( 'trashed' => 'only', 'orderby' => 'deleted_at', 'order' => 'DESC', 'per_page' => 200 ) );
		$companies = FW_CRM_Companies::query( array( 'trashed' => 'only', 'orderby' => 'deleted_at', 'order' => 'DESC', 'per_page' => 200 ) );
		$days      = FW_CRM_Trash::retention_days();
		$deals     = class_exists( 'FW_CRM_Deals' ) ? FW_CRM_Deals::query( array( 'trashed' => 'only', 'orderby' => 'updated_at', 'order' => 'DESC', 'per_page' => 200 ) ) : array();

		if ( ! $contacts && ! $companies && ! $deals ) {
			FW_CRM_App::empty_state(
				'dashicons-trash',
				__( 'The bin is empty', 'fw' ),
				$days
					? sprintf( __( 'Contacts and companies you delete come here first, so a mistake can be undone. They are deleted for good after %s days.', 'fw' ), number_format_i18n( $days ) )
					: __( 'Contacts and companies you delete come here first, so a mistake can be undone. They stay until you empty the bin.', 'fw' )
			);
			return;
		}
		?>
		<div class="fw-crm-listbar">
			<p class="fw-crm-muted">
				<?php
				echo $days
					? esc_html( sprintf( __( 'Records in the bin are hidden everywhere in the CRM and deleted for good after %s days.', 'fw' ), number_format_i18n( $days ) ) )
					: esc_html__( 'Records in the bin are hidden everywhere in the CRM until you restore them or empty the bin.', 'fw' );
				?>
			</p>
			<a class="button button-link-delete" href="<?php echo esc_url( self::action( 'empty_bin', 0 ) ); ?>"
			   onclick="return confirm('<?php echo esc_js( __( 'Delete everything in the bin for good? This cannot be undone.', 'fw' ) ); ?>');"><?php esc_html_e( 'Empty the bin', 'fw' ); ?></a>
		</div>

		<?php
		foreach ( array( 'contact' => $contacts, 'company' => $companies, 'deal' => $deals ) as $what => $rows ) :
			if ( ! $rows ) {
				continue;
			}
			?>
			<section class="fw-crm-panel fw-crm-bin" aria-labelledby="fw-crm-h-bin-<?php echo esc_attr( $what ); ?>">
				<header class="fw-crm-panel__head">
					<h2 id="fw-crm-h-bin-<?php echo esc_attr( $what ); ?>"><?php echo 'deal' === $what ? esc_html__( 'Deals', 'fw' ) : ( 'company' === $what ? esc_html__( 'Companies', 'fw' ) : esc_html__( 'Contacts', 'fw' ) ); ?></h2>
					<span class="fw-crm-muted"><?php echo esc_html( number_format_i18n( count( $rows ) ) ); ?></span>
				</header>
				<ul class="fw-crm-people">
					<?php foreach ( $rows as $r ) : ?>
						<?php
						if ( 'deal' === $what ) {
							$name = (string) $r->title;
							$sub  = FW_CRM_Deals::money( $r->value, $r->currency );
							$url  = add_query_arg( array( 'tab' => 'deals', 'deal' => (int) $r->id ), $base );
						} elseif ( 'company' === $what ) {
							$name = (string) $r->name;
							$sub  = (string) $r->domain;
							$url  = add_query_arg( array( 'tab' => 'companies', 'company' => (int) $r->id ), $base );
						} else {
							$name = trim( $r->first_name . ' ' . $r->last_name );
							$sub  = '' !== $name ? (string) $r->email : (string) $r->phone;
							$name = '' !== $name ? $name : ( '' !== (string) $r->email ? (string) $r->email : (string) $r->phone );
							$url  = add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $r->id ), $base );
						}
						?>
						<li>
							<a href="<?php echo esc_url( $url ); ?>">
								<?php echo FW_CRM_App::avatar( $name, 32, 'company' === $what ? 'square' : 'round' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span class="fw-crm-people__text">
									<span class="fw-crm-people__name"><?php echo esc_html( $name ); ?></span>
									<span class="fw-crm-people__sub"><?php echo esc_html( $sub ); ?></span>
								</span>
								<span class="fw-crm-muted fw-crm-bin__when"><?php echo esc_html__( 'Binned', 'fw' ) . ' ' . FW_CRM_App::time_ago( $r->deleted_at ); // phpcs:ignore WordPress.Security.EscapeOutput -- time_ago() escapes. ?></span>
							</a>
							<?php if ( 'deal' === $what ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'deal_restore', 'deal' => (int) $r->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a>
								<a class="button-link button-link-delete fw-crm-bin__purge" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'tab' => 'deals', 'fw_crm_action' => 'deal_purge', 'deal' => (int) $r->id ), $base ), FW_CRM_Admin_Page::NONCE ) ); ?>"
								   onclick="return confirm('<?php echo esc_js( __( 'Delete this for good? This cannot be undone.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete for good', 'fw' ); ?></a>
							<?php else : ?>
							<a class="button button-small" href="<?php echo esc_url( self::action( 'restore_' . $what, (int) $r->id ) ); ?>"><?php esc_html_e( 'Restore', 'fw' ); ?></a>
							<a class="button-link button-link-delete fw-crm-bin__purge" href="<?php echo esc_url( self::action( 'purge_' . $what, (int) $r->id ) ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this for good? This cannot be undone.', 'fw' ) ); ?>');"><?php esc_html_e( 'Delete for good', 'fw' ); ?></a>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</section>
		<?php endforeach; ?>
		<?php
	}

	/* ---------------------------------------------------------------------- *
	 * Audit log
	 * ---------------------------------------------------------------------- */

	/**
	 * Who changed what, newest first, filterable by person and kind of record.
	 */
	public static function render_audit() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$actor = isset( $_GET['actor'] ) && '' !== $_GET['actor'] ? (int) $_GET['actor'] : '';
		$type  = isset( $_GET['object'] ) ? sanitize_key( wp_unslash( $_GET['object'] ) ) : '';
		$paged = max( 1, isset( $_GET['paged'] ) ? (int) $_GET['paged'] : 1 );
		// phpcs:enable
		list( $rows, $total ) = FW_CRM_Audit::query( array( 'actor' => $actor, 'object_type' => $type, 'per_page' => 50, 'paged' => $paged ) );
		$base   = FW_CRM_Admin_Page::get_page_url();
		$types  = array(
			''         => __( 'Everything', 'fw' ),
			'contact'  => __( 'Contacts', 'fw' ),
			'company'  => __( 'Companies', 'fw' ),
			'segment'  => __( 'Segments', 'fw' ),
			'list'     => __( 'Lists and tags', 'fw' ),
			'campaign' => __( 'Campaigns', 'fw' ),
			'contacts' => __( 'Imports and exports', 'fw' ),
			'settings' => __( 'Settings', 'fw' ),
		);
		$people = array( '' => __( 'Anyone', 'fw' ), '0' => __( 'The system', 'fw' ) );
		foreach ( get_users( array( 'capability' => FW_CRM_Service::capability(), 'fields' => array( 'ID', 'display_name' ), 'number' => 200 ) ) as $u ) {
			$people[ (string) $u->ID ] = $u->display_name;
		}
		$days = (int) apply_filters( 'unysonplus_crm_audit_days', 365 );
		?>
		<div class="fw-crm-listbar">
			<form method="get" action="" class="fw-crm-inline">
				<input type="hidden" name="page" value="<?php echo esc_attr( FW_CRM_Admin_Page::PAGE_SLUG ); ?>" />
				<input type="hidden" name="tab" value="audit" />
				<label class="screen-reader-text" for="fw-crm-audit-actor"><?php esc_html_e( 'Person', 'fw' ); ?></label>
				<select id="fw-crm-audit-actor" name="actor">
					<?php foreach ( $people as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( (string) $actor, (string) $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="fw-crm-audit-object"><?php esc_html_e( 'Kind of record', 'fw' ); ?></label>
				<select id="fw-crm-audit-object" name="object">
					<?php foreach ( $types as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $type, $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'fw' ); ?></button>
			</form>
			<p class="fw-crm-muted">
				<?php
				echo $days
					? esc_html( sprintf( __( 'Entries are kept for %s days.', 'fw' ), number_format_i18n( $days ) ) )
					: esc_html__( 'Entries are kept until you remove them.', 'fw' );
				?>
			</p>
		</div>

		<?php if ( ! $rows ) : ?>
			<?php FW_CRM_App::empty_state( 'dashicons-shield', __( 'Nothing recorded yet', 'fw' ), __( 'Every change people make to contacts, companies, segments and settings, every export and import, and every merge and deletion is recorded here with who did it and when.', 'fw' ) ); ?>
			<?php return; ?>
		<?php endif; ?>

		<table class="wp-list-table widefat striped fw-crm-table fw-crm-audit">
			<thead>
				<tr>
					<th scope="col" style="width:160px"><?php esc_html_e( 'When', 'fw' ); ?></th>
					<th scope="col" style="width:180px"><?php esc_html_e( 'Who', 'fw' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What', 'fw' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$who    = (int) $r->actor_id ? get_userdata( (int) $r->actor_id ) : null;
					$target = '';
					$url    = '';
					if ( 'contact' === $r->object_type && $r->object_id ) {
						$c      = FW_CRM_Subscribers::find( (int) $r->object_id );
						$target = $c ? ( trim( $c->first_name . ' ' . $c->last_name ) ?: (string) ( $c->email ?: $c->phone ) ) : (string) ( $r->details['_label'] ?? $r->details['name'] ?? $r->details['email'] ?? '#' . $r->object_id );
						$url    = $c ? add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $c->id ), $base ) : '';
					} elseif ( 'company' === $r->object_type && $r->object_id ) {
						$co     = FW_CRM_Companies::find( (int) $r->object_id );
						$target = $co ? (string) $co->name : (string) ( $r->details['_label'] ?? $r->details['name'] ?? '#' . $r->object_id );
						$url    = $co ? add_query_arg( array( 'tab' => 'companies', 'company' => (int) $co->id ), $base ) : '';
					} elseif ( isset( $r->details['title'] ) ) {
						$target = (string) $r->details['title'];
					}
					$detail = '';
					if ( 'updated' === $r->action ) {
						$parts = array();
						foreach ( $r->details as $field => $chg ) {
							if ( is_array( $chg ) && array_key_exists( 'new', $chg ) ) {
								$old     = '' === (string) $chg['old'] ? '—' : (string) $chg['old'];
								$new     = '' === (string) $chg['new'] ? '—' : (string) $chg['new'];
								$parts[] = sprintf( '%s: %s → %s', str_replace( '_', ' ', $field ), $old, $new );
							}
						}
						$detail = implode( '; ', $parts );
					} elseif ( 'exported' === $r->action ) {
						$detail = sprintf( _n( '%s row', '%s rows', (int) ( $r->details['rows'] ?? 0 ), 'fw' ), number_format_i18n( (int) ( $r->details['rows'] ?? 0 ) ) );
					} elseif ( 'import_started' === $r->action ) {
						$detail = sprintf( __( '%1$s, %2$s rows', 'fw' ), (string) ( $r->details['file'] ?? '' ), number_format_i18n( (int) ( $r->details['rows'] ?? 0 ) ) );
					} elseif ( 'saved' === $r->action && 'settings' === $r->object_type ) {
						$detail = implode( ', ', (array) ( $r->details['changed'] ?? array() ) );
					} elseif ( 'sending' === $r->action ) {
						$detail = sprintf( _n( '%s recipient', '%s recipients', (int) ( $r->details['recipients'] ?? 0 ), 'fw' ), number_format_i18n( (int) ( $r->details['recipients'] ?? 0 ) ) );
					}
					?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->created_at ) ) ); ?></td>
						<td><?php echo $who ? esc_html( $who->display_name ) : '<span class="fw-crm-muted">' . esc_html__( 'The system', 'fw' ) . '</span>'; ?></td>
						<td>
							<?php echo esc_html( ucfirst( FW_CRM_Audit::describe( $r ) ) ); ?>
							<?php if ( '' !== $target ) : ?>
								<?php echo $url ? '<a href="' . esc_url( $url ) . '">' . esc_html( $target ) . '</a>' : '<strong>' . esc_html( $target ) . '</strong>'; ?>
							<?php endif; ?>
							<?php if ( '' !== $detail ) : ?>
								<div class="fw-crm-muted fw-crm-audit__detail"><?php echo esc_html( $detail ); ?></div>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / 50 );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links( array( // phpcs:ignore WordPress.Security.EscapeOutput
				'base'    => add_query_arg( array( 'tab' => 'audit', 'actor' => $actor, 'object' => $type, 'paged' => '%#%' ), $base ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			) ) . '</div></div>';
		}
	}

	/* ---------------------------------------------------------------------- *
	 * Search
	 * ---------------------------------------------------------------------- */

	/**
	 * @param string $q
	 */
	public static function render_search( $q ) {
		$base = FW_CRM_Admin_Page::get_page_url();
		if ( '' === $q ) {
			echo '<p class="fw-crm-muted">' . esc_html__( 'Type a name, email, phone number or company in the search box.', 'fw' ) . '</p>';
			return;
		}
		$people    = FW_CRM_Subscribers::query( FW_CRM_Capabilities::scope( array( 'search' => $q, 'per_page' => 25, 'orderby' => 'last_activity_at' ) ) );
		$companies = FW_CRM_Modules::enabled( 'companies' ) ? FW_CRM_Companies::query( FW_CRM_Capabilities::scope( array( 'search' => $q, 'per_page' => 25 ) ) ) : array();

		if ( ! $people && ! $companies ) {
			FW_CRM_App::empty_state( 'dashicons-search', sprintf( __( 'Nothing matches "%s"', 'fw' ), $q ), __( 'Search looks at names, email addresses and phone numbers of contacts, and at the names, domains and emails of companies.', 'fw' ), array( array( __( 'New contact', 'fw' ), add_query_arg( array( 'tab' => 'subscribers', 'new' => 1 ), $base ) ) ) );
			return;
		}
		?>
		<div class="fw-crm-search-results">
			<?php if ( $people ) : ?>
				<section class="fw-crm-panel" aria-labelledby="fw-crm-h-sr-people">
					<header class="fw-crm-panel__head"><h2 id="fw-crm-h-sr-people"><?php esc_html_e( 'Contacts', 'fw' ); ?></h2><span class="fw-crm-muted"><?php echo esc_html( number_format_i18n( count( $people ) ) ); ?></span></header>
					<ul class="fw-crm-people">
						<?php foreach ( $people as $p ) : ?>
							<?php $pn = trim( $p->first_name . ' ' . $p->last_name ); ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'subscribers', 'subscriber' => (int) $p->id ), $base ) ); ?>">
									<?php echo FW_CRM_App::avatar( '' !== $pn ? $pn : (string) $p->email, 32 ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="fw-crm-people__text">
										<span class="fw-crm-people__name"><?php echo esc_html( '' !== $pn ? $pn : (string) $p->email ); ?></span>
										<span class="fw-crm-people__sub"><?php echo esc_html( implode( ' · ', array_filter( array( '' !== $pn ? (string) $p->email : '', (string) $p->phone ) ) ) ); ?></span>
									</span>
									<?php echo FW_CRM_App::stage_pill( (string) $p->lifecycle_stage ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
			<?php if ( $companies ) : ?>
				<section class="fw-crm-panel" aria-labelledby="fw-crm-h-sr-co">
					<header class="fw-crm-panel__head"><h2 id="fw-crm-h-sr-co"><?php esc_html_e( 'Companies', 'fw' ); ?></h2><span class="fw-crm-muted"><?php echo esc_html( number_format_i18n( count( $companies ) ) ); ?></span></header>
					<ul class="fw-crm-people">
						<?php foreach ( $companies as $co ) : ?>
							<li>
								<a href="<?php echo esc_url( add_query_arg( array( 'tab' => 'companies', 'company' => (int) $co->id ), $base ) ); ?>">
									<?php echo FW_CRM_App::avatar( $co->name, 32, 'square' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="fw-crm-people__text">
										<span class="fw-crm-people__name"><?php echo esc_html( $co->name ); ?></span>
										<span class="fw-crm-people__sub"><?php echo esc_html( (string) $co->domain ); ?></span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>
		</div>
		<?php
	}
}
