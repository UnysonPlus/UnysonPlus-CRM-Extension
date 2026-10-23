<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The CRM's own triggers, and the bridges that turn things already happening in the CRM into
 * automation events (`unysonplus_crm_automation_event`).
 *
 * A trigger's `fields` are the settings the editor shows under it; an empty field matches
 * anything. Unless a trigger has its own `match`, a set field must equal the event's value of
 * the same key. "Date reached" is not an event: the daily job looks for contacts whose date
 * field is due and starts them (scan_dates()).
 */
class FW_CRM_Trigger {

	public static function init() {
		add_action( 'unysonplus_crm_contact_created', function ( $c ) {
			$id = self::id_of( $c );
			self::fire( 'crm.contact_created', $id, array( 'dedupe' => 'created:' . $id ) );
		} );
		add_action( 'unysonplus_crm_pivot_changed', function ( $contact_id, $object_id, $type, $op ) {
			if ( 'tag' !== $type ) {
				return;
			}
			$tag = FW_CRM_Lists::find( (int) $object_id );
			self::fire( 'add' === $op ? 'crm.tag_added' : 'crm.tag_removed', (int) $contact_id, array( 'tag' => $tag ? (string) $tag->slug : '' ) );
		}, 10, 4 );
		add_action( 'unysonplus_crm_list_status_changed', function ( $contact_id, $list_id, $from, $to ) {
			$list = FW_CRM_Lists::find( (int) $list_id );
			$slug = $list ? (string) $list->slug : '';
			if ( 'subscribed' === $to && 'subscribed' !== $from ) {
				self::fire( 'crm.list_joined', (int) $contact_id, array( 'list' => $slug ) );
			} elseif ( 'subscribed' === $from && 'subscribed' !== $to ) {
				self::fire( 'crm.list_left', (int) $contact_id, array( 'list' => $slug ) );
			}
		}, 10, 4 );
		add_action( 'unysonplus_crm_contact_updated', function ( $contact, $before, $changes ) {
			foreach ( (array) $changes as $field => $c ) {
				self::fire( 'crm.field_changed', self::id_of( $contact ), array( 'field' => (string) $field, 'from' => (string) ( $c['old'] ?? '' ), 'to' => (string) ( $c['new'] ?? '' ) ) );
			}
		}, 10, 3 );
		add_action( 'unysonplus_crm_fields_updated', function ( $object_type, $object_id, $keys ) {
			if ( 'contact' !== $object_type ) {
				return;
			}
			$vals = FW_CRM_Fields::get_values( 'contact', (int) $object_id );
			foreach ( (array) $keys as $k ) {
				$v = $vals[ $k ] ?? '';
				self::fire( 'crm.field_changed', (int) $object_id, array( 'field' => 'cf:' . $k, 'from' => '', 'to' => is_array( $v ) ? implode( ', ', $v ) : (string) $v, 'from_unknown' => 1 ) );
			}
		}, 10, 3 );
		foreach ( array( 'unysonplus_crm_subscriber_confirmed', 'unysonplus_crm_subscriber_opted_in' ) as $hook ) {
			add_action( $hook, function ( $s ) {
				$id = self::id_of( $s );
				if ( $id && 'subscribed' === ( FW_CRM_Subscribers::find( $id )->status ?? '' ) ) {
					self::fire( 'crm.subscribed', $id, array( 'dedupe' => 'subscribed:' . $id . ':' . gmdate( 'Ymd' ) ) );
				}
			} );
		}
		add_action( 'unysonplus_crm_deal_stage_changed', function ( $deal, $from, $to ) {
			foreach ( (array) ( $deal['contact_ids'] ?? array() ) as $cid ) {
				self::fire( 'crm.deal_stage_changed', (int) $cid, array( 'stage_id' => (string) $to, 'from_stage' => (string) $from, 'pipeline_id' => (string) ( $deal['pipeline_id'] ?? '' ), 'deal_id' => (int) $deal['id'] ) );
			}
		}, 10, 3 );
		foreach ( array( 'unysonplus_crm_deal_won' => 'crm.deal_won', 'unysonplus_crm_deal_lost' => 'crm.deal_lost', 'unysonplus_crm_deal_created' => 'crm.deal_created' ) as $hook => $slug ) {
			add_action( $hook, function ( $deal ) use ( $slug ) {
				foreach ( (array) ( $deal['contact_ids'] ?? array() ) as $cid ) {
					self::fire( $slug, (int) $cid, array( 'pipeline_id' => (string) ( $deal['pipeline_id'] ?? '' ), 'deal_id' => (int) $deal['id'], 'dedupe' => $slug . ':' . (int) $deal['id'] ) );
				}
			} );
		}
		add_action( 'unysonplus_crm_task_completed', function ( $task ) {
			if ( $task && 'contact' === $task->object_type ) {
				self::fire( 'crm.task_completed', (int) $task->object_id, array( 'task_type' => (string) $task->type, 'dedupe' => 'task:' . (int) $task->id ) );
			}
		} );
		// A purchase in WooCommerce, for the contact with the billing email.
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, '_on_order' ) );
	}

	/**
	 * @param string $slug
	 * @param int    $contact_id
	 * @param array  $context
	 */
	public static function fire( $slug, $contact_id, array $context = array() ) {
		if ( $contact_id ) {
			do_action( 'unysonplus_crm_automation_event', $slug, (int) $contact_id, $context );
		}
	}

	/**
	 * @param mixed $record An id, an array with id, or an object with id.
	 *
	 * @return int
	 */
	public static function id_of( $record ) {
		if ( is_array( $record ) ) {
			return (int) ( $record['id'] ?? 0 );
		}
		return is_object( $record ) ? (int) ( $record->id ?? 0 ) : (int) $record;
	}

	/**
	 * @internal
	 *
	 * @param int $order_id
	 */
	public static function _on_order( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		$email = $order ? strtolower( (string) $order->get_billing_email() ) : '';
		if ( ! is_email( $email ) ) {
			return;
		}
		$c = FW_CRM_Subscribers::find_by_email( $email );
		if ( ! $c ) {
			$id = FW_CRM_Contacts::create( array( 'email' => $email, 'first_name' => (string) $order->get_billing_first_name(), 'last_name' => (string) $order->get_billing_last_name(), 'source' => 'woocommerce' ) );
			$c  = is_wp_error( $id ) ? null : FW_CRM_Subscribers::find( $id );
		}
		if ( ! $c ) {
			return;
		}
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = (string) $item->get_product_id();
		}
		self::fire( 'crm.purchase_made', (int) $c->id, array( 'order_id' => (int) $order_id, 'total' => (float) $order->get_total(), 'products' => $products, 'dedupe' => 'order:' . (int) $order_id ) );
	}

	/**
	 * Daily: start "date reached" automations for contacts whose date is due today.
	 *
	 * @param int|null $today Site-local timestamp (for tests).
	 *
	 * @return int How many contacts were started.
	 */
	public static function scan_dates( $today = null ) {
		global $wpdb;

		$today = null === $today ? current_time( 'timestamp' ) : (int) $today; // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		$vals  = FW_CRM_Installer::table( 'field_values' );
		$n     = 0;
		foreach ( FW_CRM_Automations::all( array( 'status' => 'active', 'trigger' => 'crm.date_reached' ) ) as $a ) {
			$s     = $a->trigger_settings;
			$field = preg_replace( '/^cf:/', '', (string) ( $s['field'] ?? '' ) );
			if ( '' === $field ) {
				continue;
			}
			$days   = max( 0, (int) ( $s['days'] ?? 0 ) );
			$when   = (string) ( $s['when'] ?? 'on' );
			$offset = 'before' === $when ? $days : ( 'after' === $when ? -$days : 0 );
			$target = $today + $offset * DAY_IN_SECONDS; // The date that is "due" today.
			if ( ! empty( $s['yearly'] ) ) {
				$sql = $wpdb->prepare( "SELECT object_id FROM {$vals} WHERE object_type = 'contact' AND field_key = %s AND value_date IS NOT NULL AND DATE_FORMAT(value_date, '%%m-%%d') = %s", $field, gmdate( 'm-d', $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$key = gmdate( 'Y', $target );
			} else {
				$sql = $wpdb->prepare( "SELECT object_id FROM {$vals} WHERE object_type = 'contact' AND field_key = %s AND DATE(value_date) = %s", $field, gmdate( 'Y-m-d', $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL
				$key = gmdate( 'Y-m-d', $target );
			}
			foreach ( (array) $wpdb->get_col( $sql ) as $cid ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$res = FW_CRM_Automations::start( $a, (int) $cid, array( 'date_field' => $field, 'dedupe' => 'date:' . $field . ':' . $key . ':' . (int) $cid ) );
				if ( ! is_wp_error( $res ) ) {
					$n++;
				}
			}
		}
		return $n;
	}

	/**
	 * The CRM's own triggers.
	 *
	 * @return array slug => { label, group, fields, match? }
	 */
	public static function builtin() {
		$tags = array( '' => __( 'Any tag', 'fw' ) );
		foreach ( FW_CRM_Lists::all( 'tag' ) as $t ) {
			$tags[ $t->slug ] = $t->title;
		}
		$lists = array( '' => __( 'Any list', 'fw' ) );
		foreach ( FW_CRM_Lists::all( 'list' ) as $l ) {
			$lists[ $l->slug ] = $l->title;
		}
		$contacts = __( 'Contacts', 'fw' );
		$t        = array(
			'crm.contact_created' => array( 'label' => __( 'Contact added', 'fw' ), 'group' => $contacts, 'fields' => array() ),
			'crm.subscribed'      => array( 'label' => __( 'Subscribed to email', 'fw' ), 'group' => $contacts, 'fields' => array() ),
			'crm.form_submitted'  => array(
				'label'  => __( 'Form submitted', 'fw' ),
				'group'  => $contacts,
				'fields' => array( 'form_id' => array( 'label' => __( 'Form ID (empty = any form)', 'fw' ), 'type' => 'text' ) ),
			),
			'crm.tag_added'       => array( 'label' => __( 'Tag added', 'fw' ), 'group' => $contacts, 'fields' => array( 'tag' => array( 'label' => __( 'Tag', 'fw' ), 'type' => 'select', 'options' => $tags ) ) ),
			'crm.tag_removed'     => array( 'label' => __( 'Tag removed', 'fw' ), 'group' => $contacts, 'fields' => array( 'tag' => array( 'label' => __( 'Tag', 'fw' ), 'type' => 'select', 'options' => $tags ) ) ),
			'crm.list_joined'     => array( 'label' => __( 'Joined a list', 'fw' ), 'group' => $contacts, 'fields' => array( 'list' => array( 'label' => __( 'List', 'fw' ), 'type' => 'select', 'options' => $lists ) ) ),
			'crm.list_left'       => array( 'label' => __( 'Left a list', 'fw' ), 'group' => $contacts, 'fields' => array( 'list' => array( 'label' => __( 'List', 'fw' ), 'type' => 'select', 'options' => $lists ) ) ),
			'crm.field_changed'   => array(
				'label'  => __( 'Field changed', 'fw' ),
				'group'  => $contacts,
				'fields' => array(
					'field' => array( 'label' => __( 'Field', 'fw' ), 'type' => 'select', 'options' => self::field_options(), 'required' => true ),
					'from'  => array( 'label' => __( 'Changed from (empty = anything)', 'fw' ), 'type' => 'text' ),
					'to'    => array( 'label' => __( 'Changed to (empty = anything)', 'fw' ), 'type' => 'text' ),
				),
				'match'  => function ( $s, $ctx ) {
					if ( ( $s['field'] ?? '' ) !== ( $ctx['field'] ?? '' ) ) {
						return false;
					}
					if ( '' !== (string) ( $s['to'] ?? '' ) && 0 !== strcasecmp( trim( (string) $s['to'] ), trim( (string) ( $ctx['to'] ?? '' ) ) ) ) {
						return false;
					}
					// A custom field's old value is not known, so "changed from" only applies to the contact's own fields.
					if ( '' !== (string) ( $s['from'] ?? '' ) && ( ! empty( $ctx['from_unknown'] ) || 0 !== strcasecmp( trim( (string) $s['from'] ), trim( (string) ( $ctx['from'] ?? '' ) ) ) ) ) {
						return false;
					}
					return true;
				},
			),
			'crm.date_reached'    => array(
				'label'  => __( 'Date reached (birthday, renewal…)', 'fw' ),
				'group'  => $contacts,
				'fields' => array(
					'field'  => array( 'label' => __( 'Date field', 'fw' ), 'type' => 'select', 'options' => self::date_field_options(), 'required' => true ),
					'when'   => array( 'label' => __( 'When', 'fw' ), 'type' => 'select', 'options' => array( 'on' => __( 'On the day', 'fw' ), 'before' => __( 'Days before', 'fw' ), 'after' => __( 'Days after', 'fw' ) ) ),
					'days'   => array( 'label' => __( 'Days', 'fw' ), 'type' => 'number' ),
					'yearly' => array( 'label' => __( 'Every year (birthdays, anniversaries)', 'fw' ), 'type' => 'checkbox' ),
				),
				'match'  => '__return_false', // Started by the daily scan, never by an event.
			),
			'crm.task_completed'  => array( 'label' => __( 'Task completed', 'fw' ), 'group' => __( 'Tasks', 'fw' ), 'fields' => array() ),
		);
		if ( class_exists( 'FW_CRM_Pipelines' ) ) {
			$stages = array( '' => __( 'Any stage', 'fw' ) );
			foreach ( FW_CRM_Pipelines::all() as $p ) {
				foreach ( FW_CRM_Pipelines::stages( (int) $p->id ) as $s ) {
					$stages[ (string) $s->id ] = $p->name . ': ' . $s->name;
				}
			}
			$deals = __( 'Deals', 'fw' );
			$t['crm.deal_created']       = array( 'label' => __( 'Deal created', 'fw' ), 'group' => $deals, 'fields' => array() );
			$t['crm.deal_stage_changed'] = array( 'label' => __( 'Deal moved to a stage', 'fw' ), 'group' => $deals, 'fields' => array( 'stage_id' => array( 'label' => __( 'Stage', 'fw' ), 'type' => 'select', 'options' => $stages ) ) );
			$t['crm.deal_won']           = array( 'label' => __( 'Deal won', 'fw' ), 'group' => $deals, 'fields' => array() );
			$t['crm.deal_lost']          = array( 'label' => __( 'Deal lost', 'fw' ), 'group' => $deals, 'fields' => array() );
		}
		if ( FW_CRM_Modules::enabled( 'email' ) ) {
			$email = __( 'Email', 'fw' );
			$t['crm.email_opened']  = array( 'label' => __( 'Opened a campaign email', 'fw' ), 'group' => $email, 'fields' => array( 'campaign_id' => array( 'label' => __( 'Campaign ID (empty = any)', 'fw' ), 'type' => 'text' ) ) );
			$t['crm.email_clicked'] = array(
				'label'  => __( 'Clicked a link in a campaign', 'fw' ),
				'group'  => $email,
				'fields' => array(
					'campaign_id' => array( 'label' => __( 'Campaign ID (empty = any)', 'fw' ), 'type' => 'text' ),
					'url'         => array( 'label' => __( 'Link contains (empty = any link)', 'fw' ), 'type' => 'text' ),
				),
				'match'  => function ( $s, $ctx ) {
					if ( '' !== (string) ( $s['campaign_id'] ?? '' ) && (string) $s['campaign_id'] !== (string) ( $ctx['campaign_id'] ?? '' ) ) {
						return false;
					}
					return '' === (string) ( $s['url'] ?? '' ) || false !== stripos( (string) ( $ctx['url'] ?? '' ), (string) $s['url'] );
				},
			);
		}
		if ( FW_CRM_Modules::enabled( 'conversations' ) ) {
			$t['crm.email_replied'] = array( 'label' => __( 'Replied by email', 'fw' ), 'group' => __( 'Email', 'fw' ), 'fields' => array() );
		}
		if ( FW_CRM_Modules::enabled( 'cases' ) ) {
			$t['crm.case_created'] = array( 'label' => __( 'Support case opened', 'fw' ), 'group' => __( 'Support', 'fw' ), 'fields' => array() );
		}
		if ( class_exists( 'WooCommerce' ) ) {
			$t['crm.purchase_made'] = array(
				'label'  => __( 'Made a purchase', 'fw' ),
				'group'  => __( 'Shop', 'fw' ),
				'fields' => array(
					'product'   => array( 'label' => __( 'Product ID (empty = any)', 'fw' ), 'type' => 'text' ),
					'min_total' => array( 'label' => __( 'Order total at least', 'fw' ), 'type' => 'number' ),
				),
				'match'  => function ( $s, $ctx ) {
					if ( '' !== (string) ( $s['product'] ?? '' ) && ! in_array( (string) $s['product'], (array) ( $ctx['products'] ?? array() ), true ) ) {
						return false;
					}
					return '' === (string) ( $s['min_total'] ?? '' ) || (float) ( $ctx['total'] ?? 0 ) >= (float) $s['min_total'];
				},
			);
		}
		return $t;
	}

	/** @return array The contact fields a "field changed" trigger can watch. */
	public static function field_options() {
		$o = array(
			'lifecycle_stage' => __( 'Lifecycle stage', 'fw' ),
			'status'          => __( 'Email status', 'fw' ),
			'owner_id'        => __( 'Owner', 'fw' ),
			'email'           => __( 'Email', 'fw' ),
			'first_name'      => __( 'First name', 'fw' ),
			'last_name'       => __( 'Last name', 'fw' ),
			'phone'           => __( 'Phone', 'fw' ),
			'job_title'       => __( 'Job title', 'fw' ),
			'city'            => __( 'City', 'fw' ),
			'country'         => __( 'Country', 'fw' ),
			'score'           => __( 'Score', 'fw' ),
		);
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $d ) {
			$o[ 'cf:' . $d['key'] ] = (string) $d['label'];
		}
		return $o;
	}

	/** @return array Contact date fields. */
	public static function date_field_options() {
		$o = array( '' => __( '— Choose a date field —', 'fw' ) );
		foreach ( FW_CRM_Fields::definitions( 'contact' ) as $d ) {
			if ( in_array( $d['type'] ?? '', array( 'date', 'datetime' ), true ) ) {
				$o[ 'cf:' . $d['key'] ] = (string) $d['label'];
			}
		}
		return $o;
	}
}
