<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * The events catalogue: one action, `unysonplus_crm_event`, for every change in the CRM, each
 * with the same payload, so an integration listens to one stream instead of thirty hooks:
 *
 *     add_action( 'unysonplus_crm_event', function ( array $e ) {
 *         // $e = array(
 *         //   'event'       => 'contact.tagged',      // see FW_CRM_Events::catalogue()
 *         //   'contact_id'  => 42,                    // 0 when the event is not about one contact
 *         //   'object_type' => 'contact',             // contact|company|deal|task|case|campaign|…
 *         //   'object_id'   => 42,
 *         //   'data'        => array( 'tag' => 'vip' ), // plain values, documented per event
 *         //   'occurred_at' => '2026-10-23 10:00:00', // site time
 *         // );
 *     } );
 *
 * The older, specific hooks (unysonplus_crm_contact_created and so on) keep firing; this is
 * built on them. Slugs match the webhook events where both exist.
 */
class FW_CRM_Events {

	/** @return array slug => { label, group, data: field => description } */
	public static function catalogue() {
		$c = __( 'Contacts', 'fw' );
		$list = array(
			'contact.created'       => array( 'label' => __( 'Contact created', 'fw' ), 'group' => $c, 'data' => array( 'email' => 'string', 'source' => 'string' ) ),
			'contact.updated'       => array( 'label' => __( 'Contact updated', 'fw' ), 'group' => $c, 'data' => array( 'changes' => 'field => { old, new }' ) ),
			'contact.deleted'       => array( 'label' => __( 'Contact deleted for good', 'fw' ), 'group' => $c, 'data' => array() ),
			'contact.merged'        => array( 'label' => __( 'Contacts merged', 'fw' ), 'group' => $c, 'data' => array( 'merged_id' => 'int (the contact that no longer exists)' ) ),
			'contact.tagged'        => array( 'label' => __( 'Tag added', 'fw' ), 'group' => $c, 'data' => array( 'tag' => 'slug', 'source' => 'string' ) ),
			'contact.untagged'      => array( 'label' => __( 'Tag removed', 'fw' ), 'group' => $c, 'data' => array( 'tag' => 'slug', 'source' => 'string' ) ),
			'contact.list_joined'   => array( 'label' => __( 'Joined a list', 'fw' ), 'group' => $c, 'data' => array( 'list' => 'slug', 'from' => 'status|null' ) ),
			'contact.list_left'     => array( 'label' => __( 'Left a list', 'fw' ), 'group' => $c, 'data' => array( 'list' => 'slug', 'to' => 'status' ) ),
			'contact.subscribed'    => array( 'label' => __( 'Subscribed to email', 'fw' ), 'group' => $c, 'data' => array() ),
			'contact.unsubscribed'  => array( 'label' => __( 'Unsubscribed', 'fw' ), 'group' => $c, 'data' => array() ),
			'contact.user_linked'   => array( 'label' => __( 'Linked to a site account', 'fw' ), 'group' => $c, 'data' => array( 'user_id' => 'int' ) ),
			'contact.user_unlinked' => array( 'label' => __( 'Unlinked from a site account', 'fw' ), 'group' => $c, 'data' => array( 'user_id' => 'int' ) ),
			'contact.score_changed' => array( 'label' => __( 'Score changed', 'fw' ), 'group' => $c, 'data' => array( 'score' => 'key', 'from' => 'int', 'to' => 'int' ) ),
			'contact.did'           => array( 'label' => __( 'Contact event recorded', 'fw' ), 'group' => $c, 'data' => array( 'event' => 'slug', 'value' => 'string', 'url' => 'string' ) ),
			'form.submitted'        => array( 'label' => __( 'Form sent to the CRM', 'fw' ), 'group' => $c, 'data' => array( 'form_id' => 'string', 'entry_id' => 'int', 'created' => 'bool' ) ),
			'company.created'       => array( 'label' => __( 'Company created', 'fw' ), 'group' => __( 'Companies', 'fw' ), 'data' => array( 'name' => 'string' ) ),
			'company.updated'       => array( 'label' => __( 'Company updated', 'fw' ), 'group' => __( 'Companies', 'fw' ), 'data' => array() ),
			'task.created'          => array( 'label' => __( 'Task created', 'fw' ), 'group' => __( 'Tasks', 'fw' ), 'data' => array( 'type' => 'string', 'title' => 'string' ) ),
			'task.completed'        => array( 'label' => __( 'Task completed', 'fw' ), 'group' => __( 'Tasks', 'fw' ), 'data' => array( 'type' => 'string', 'outcome' => 'string' ) ),
			'deal.created'          => array( 'label' => __( 'Deal created', 'fw' ), 'group' => __( 'Deals', 'fw' ), 'data' => array( 'title' => 'string', 'value' => 'float', 'stage_id' => 'int' ) ),
			'deal.stage_changed'    => array( 'label' => __( 'Deal moved', 'fw' ), 'group' => __( 'Deals', 'fw' ), 'data' => array( 'from' => 'stage id', 'to' => 'stage id' ) ),
			'deal.won'              => array( 'label' => __( 'Deal won', 'fw' ), 'group' => __( 'Deals', 'fw' ), 'data' => array( 'value' => 'float' ) ),
			'deal.lost'             => array( 'label' => __( 'Deal lost', 'fw' ), 'group' => __( 'Deals', 'fw' ), 'data' => array( 'reason' => 'string' ) ),
			'case.created'          => array( 'label' => __( 'Case opened', 'fw' ), 'group' => __( 'Support', 'fw' ), 'data' => array( 'title' => 'string', 'source' => 'manual|form|email' ) ),
			'case.status_changed'   => array( 'label' => __( 'Case status changed', 'fw' ), 'group' => __( 'Support', 'fw' ), 'data' => array( 'from' => 'status', 'to' => 'status' ) ),
			'email.opened'          => array( 'label' => __( 'Campaign email opened', 'fw' ), 'group' => __( 'Email', 'fw' ), 'data' => array( 'campaign_id' => 'int' ) ),
			'email.clicked'         => array( 'label' => __( 'Campaign link clicked', 'fw' ), 'group' => __( 'Email', 'fw' ), 'data' => array( 'campaign_id' => 'int', 'url' => 'string' ) ),
			'email.bounced'         => array( 'label' => __( 'Campaign email bounced', 'fw' ), 'group' => __( 'Email', 'fw' ), 'data' => array( 'campaign_id' => 'int' ) ),
			'email.complained'      => array( 'label' => __( 'Marked as spam', 'fw' ), 'group' => __( 'Email', 'fw' ), 'data' => array( 'campaign_id' => 'int' ) ),
			'email.received'        => array( 'label' => __( 'Email reply received', 'fw' ), 'group' => __( 'Email', 'fw' ), 'data' => array( 'subject' => 'string', 'deal_id' => 'int' ) ),
			'automation.started'    => array( 'label' => __( 'Entered an automation', 'fw' ), 'group' => __( 'Automations', 'fw' ), 'data' => array( 'automation_id' => 'int', 'run_id' => 'int' ) ),
			'automation.finished'   => array( 'label' => __( 'Finished an automation', 'fw' ), 'group' => __( 'Automations', 'fw' ), 'data' => array( 'automation_id' => 'int', 'run_id' => 'int' ) ),
			'commerce.updated'      => array( 'label' => __( 'Purchase summary updated', 'fw' ), 'group' => __( 'Commerce', 'fw' ), 'data' => array( 'provider' => 'string', 'orders' => 'int', 'total' => 'float' ) ),
			'object.created'        => array( 'label' => __( 'Custom record created', 'fw' ), 'group' => __( 'Custom objects', 'fw' ), 'data' => array( 'type' => 'string', 'title' => 'string' ) ),
			'object.updated'        => array( 'label' => __( 'Custom record updated', 'fw' ), 'group' => __( 'Custom objects', 'fw' ), 'data' => array( 'type' => 'string' ) ),
		);
		/** Filters the events catalogue. Extensions add their own `<extension>.<event>` slugs here and fire them with FW_CRM_Events::emit(). */
		return (array) apply_filters( 'unysonplus_crm_events_catalogue', $list );
	}

	/**
	 * Fire one catalogue event.
	 *
	 * @param string $event
	 * @param int    $contact_id
	 * @param string $object_type
	 * @param int    $object_id
	 * @param array  $data
	 */
	public static function emit( $event, $contact_id, $object_type = 'contact', $object_id = 0, array $data = array() ) {
		$payload = array(
			'event'       => (string) $event,
			'contact_id'  => (int) $contact_id,
			'object_type' => (string) $object_type,
			'object_id'   => (int) ( $object_id ? $object_id : $contact_id ),
			'data'        => FW_CRM_Automations_Plain::plain( $data ),
			'occurred_at' => current_time( 'mysql' ),
		);
		/**
		 * Every change in the CRM, one shape. See FW_CRM_Events::catalogue() for the events and their data.
		 *
		 * @param array $payload { event, contact_id, object_type, object_id, data, occurred_at }
		 */
		do_action( 'unysonplus_crm_event', $payload );
		/** The same, for listeners interested in one event only: `unysonplus_crm_event_<event with dots as underscores>`. */
		do_action( 'unysonplus_crm_event_' . str_replace( '.', '_', (string) $event ), $payload );
	}

	public static function init() {
		$id = function ( $r ) {
			return is_array( $r ) ? (int) ( $r['id'] ?? 0 ) : ( is_object( $r ) ? (int) ( $r->id ?? 0 ) : (int) $r );
		};
		add_action( 'unysonplus_crm_contact_created', function ( $c ) use ( $id ) {
			self::emit( 'contact.created', $id( $c ), 'contact', 0, array( 'email' => is_array( $c ) ? ( $c['email'] ?? '' ) : '', 'source' => is_array( $c ) ? ( $c['source'] ?? '' ) : '' ) );
		} );
		add_action( 'unysonplus_crm_contact_updated', function ( $c, $before, $changes ) use ( $id ) {
			self::emit( 'contact.updated', $id( $c ), 'contact', 0, array( 'changes' => (array) $changes ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_contact_deleted', function ( $c ) use ( $id ) {
			self::emit( 'contact.deleted', $id( $c ), 'contact', 0 );
		} );
		add_action( 'unysonplus_crm_contacts_merged', function ( $keep, $other = 0 ) use ( $id ) {
			self::emit( 'contact.merged', $id( $keep ), 'contact', 0, array( 'merged_id' => $id( $other ) ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_pivot_changed', function ( $contact_id, $object_id, $type, $op, $source = '' ) {
			if ( 'tag' !== $type ) {
				return;
			}
			$tag = FW_CRM_Lists::find( (int) $object_id );
			self::emit( 'add' === $op ? 'contact.tagged' : 'contact.untagged', (int) $contact_id, 'contact', 0, array( 'tag' => $tag ? $tag->slug : '', 'source' => (string) $source ) );
		}, 10, 5 );
		add_action( 'unysonplus_crm_list_status_changed', function ( $contact_id, $list_id, $from, $to ) {
			$list = FW_CRM_Lists::find( (int) $list_id );
			if ( 'subscribed' === $to && 'subscribed' !== $from ) {
				self::emit( 'contact.list_joined', (int) $contact_id, 'contact', 0, array( 'list' => $list ? $list->slug : '', 'from' => $from ) );
			} elseif ( 'subscribed' === $from && 'subscribed' !== $to ) {
				self::emit( 'contact.list_left', (int) $contact_id, 'contact', 0, array( 'list' => $list ? $list->slug : '', 'to' => $to ) );
			}
		}, 10, 4 );
		add_action( 'unysonplus_crm_subscriber_confirmed', function ( $s ) use ( $id ) {
			self::emit( 'contact.subscribed', $id( $s ), 'contact', 0 );
		} );
		add_action( 'unysonplus_crm_subscriber_unsubscribed', function ( $s ) use ( $id ) {
			self::emit( 'contact.unsubscribed', $id( $s ), 'contact', 0 );
		} );
		add_action( 'unysonplus_crm_user_linked', function ( $contact_id, $user_id ) {
			self::emit( 'contact.user_linked', (int) $contact_id, 'contact', 0, array( 'user_id' => (int) $user_id ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_user_unlinked', function ( $contact_id, $user_id ) {
			self::emit( 'contact.user_unlinked', (int) $contact_id, 'contact', 0, array( 'user_id' => (int) $user_id ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_score_changed', function ( $contact_id, $score, $from, $to ) {
			self::emit( 'contact.score_changed', (int) $contact_id, 'contact', 0, array( 'score' => $score, 'from' => (int) $from, 'to' => (int) $to ) );
		}, 10, 4 );
		add_action( 'unysonplus_crm_event_recorded', function ( $row ) {
			self::emit( 'contact.did', (int) $row->contact_id, 'contact', 0, array( 'event' => $row->event, 'value' => $row->value, 'url' => $row->url ) );
		} );
		add_action( 'unysonplus_crm_form_submitted', function ( $contact_id, $ctx, $created = false ) {
			self::emit( 'form.submitted', (int) $contact_id, 'contact', 0, array( 'form_id' => (string) ( $ctx['form_id'] ?? '' ), 'entry_id' => (int) ( $ctx['entry_id'] ?? 0 ), 'created' => (bool) $created ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_company_created', function ( $co ) use ( $id ) {
			self::emit( 'company.created', 0, 'company', $id( $co ), array( 'name' => is_array( $co ) ? ( $co['name'] ?? '' ) : ( $co->name ?? '' ) ) );
		} );
		add_action( 'unysonplus_crm_company_updated', function ( $co ) use ( $id ) {
			self::emit( 'company.updated', 0, 'company', $id( $co ) );
		} );
		add_action( 'unysonplus_crm_task_created', function ( $t ) {
			if ( $t ) {
				self::emit( 'task.created', 'contact' === $t->object_type ? (int) $t->object_id : 0, 'task', (int) $t->id, array( 'type' => $t->type, 'title' => $t->title ) );
			}
		} );
		add_action( 'unysonplus_crm_task_completed', function ( $t, $outcome = '' ) {
			if ( $t ) {
				self::emit( 'task.completed', 'contact' === $t->object_type ? (int) $t->object_id : 0, 'task', (int) $t->id, array( 'type' => $t->type, 'outcome' => (string) $outcome ) );
			}
		}, 10, 2 );
		add_action( 'unysonplus_crm_deal_created', function ( $d ) {
			self::emit( 'deal.created', (int) ( $d['contact_id'] ?? 0 ), 'deal', (int) $d['id'], array( 'title' => $d['title'], 'value' => (float) $d['value'], 'stage_id' => (int) $d['stage_id'] ) );
		} );
		add_action( 'unysonplus_crm_deal_stage_changed', function ( $d, $from, $to ) {
			// The hook passes the stage rows (or ids from older callers).
			$id = function ( $s ) {
				return (int) ( is_object( $s ) ? $s->id : $s );
			};
			self::emit( 'deal.stage_changed', (int) ( $d['contact_id'] ?? 0 ), 'deal', (int) $d['id'], array( 'from' => $id( $from ), 'to' => $id( $to ) ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_deal_won', function ( $d ) {
			self::emit( 'deal.won', (int) ( $d['contact_id'] ?? 0 ), 'deal', (int) $d['id'], array( 'value' => (float) $d['value'] ) );
		} );
		add_action( 'unysonplus_crm_deal_lost', function ( $d, $reason = '' ) {
			self::emit( 'deal.lost', (int) ( $d['contact_id'] ?? 0 ), 'deal', (int) $d['id'], array( 'reason' => (string) $reason ) );
		}, 10, 2 );
		add_action( 'unysonplus_crm_case_created', function ( $c ) {
			if ( $c ) {
				self::emit( 'case.created', (int) $c->contact_id, 'case', (int) $c->id, array( 'title' => $c->title, 'source' => $c->source ) );
			}
		} );
		add_action( 'unysonplus_crm_case_status_changed', function ( $c, $from ) {
			if ( $c ) {
				self::emit( 'case.status_changed', (int) $c->contact_id, 'case', (int) $c->id, array( 'from' => (string) $from, 'to' => $c->status ) );
			}
		}, 10, 2 );
		add_action( 'unysonplus_crm_email_event', function ( $type, $row, $link_id = 0 ) {
			$map = array( 'open' => 'email.opened', 'click' => 'email.clicked', 'bounce' => 'email.bounced', 'complaint' => 'email.complained' );
			if ( isset( $map[ $type ] ) ) {
				$data = array( 'campaign_id' => (int) $row->campaign_id );
				if ( 'click' === $type && class_exists( 'FW_CRM_Tracking' ) ) {
					$data['url'] = FW_CRM_Tracking::link_url( (int) $link_id );
				}
				self::emit( $map[ $type ], (int) $row->subscriber_id, 'campaign', (int) $row->campaign_id, $data );
			}
		}, 40, 3 );
		add_action( 'unysonplus_crm_email_received', function ( $contact_id, $m, $deal_id = 0 ) {
			self::emit( 'email.received', (int) $contact_id, 'contact', 0, array( 'subject' => (string) ( $m['subject'] ?? '' ), 'deal_id' => (int) $deal_id ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_automation_started', function ( $run_id, $a, $contact_id ) {
			self::emit( 'automation.started', (int) $contact_id, 'automation', (int) $a->id, array( 'automation_id' => (int) $a->id, 'run_id' => (int) $run_id ) );
		}, 10, 3 );
		add_action( 'unysonplus_crm_automation_finished', function ( $run_id, $a, $contact_id ) {
			self::emit( 'automation.finished', (int) $contact_id, 'automation', (int) $a->id, array( 'automation_id' => (int) $a->id, 'run_id' => (int) $run_id ) );
		}, 10, 3 );
	}
}

/** Plain values only: payloads are stored by webhooks and queues. */
class FW_CRM_Automations_Plain {

	/**
	 * @param array $a
	 *
	 * @return array
	 */
	public static function plain( array $a ) {
		$out = array();
		foreach ( $a as $k => $v ) {
			if ( is_scalar( $v ) || null === $v ) {
				$out[ $k ] = $v;
			} elseif ( is_array( $v ) ) {
				$out[ $k ] = self::plain( $v );
			} elseif ( is_object( $v ) ) {
				$out[ $k ] = self::plain( get_object_vars( $v ) );
			}
		}
		return $out;
	}
}
