<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The Subscribers table.
 *
 * Subclasses core's WP_List_Table (as the Update extension's own list table
 * does) rather than the vendored FW_WP_List_Table helper — this screen is
 * admin-only, so core's current implementation is available and is the one that
 * keeps up with WordPress's markup and a11y.
 *
 * Presentation only: every row comes from the repository, and every action posts
 * back to the admin page, which calls the service on its `load-` hook.
 */
class FW_CRM_List_Table extends WP_List_Table {

	/** @var array The query args this render used — reused by the CSV export. */
	private $query_args = array();

	public function __construct() {
		parent::__construct( array(
			'singular' => 'subscriber',
			'plural'   => 'subscribers',
			'ajax'     => false,
			'screen'   => 'fw-crm',
		) );
	}

	/**
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'               => '<input type="checkbox" />',
			'name'             => __( 'Contact', 'fw' ),
			'stage'            => __( 'Stage', 'fw' ),
			'company'          => __( 'Company', 'fw' ),
			'status'           => __( 'Email status', 'fw' ),
			'lists'            => __( 'Lists', 'fw' ),
			'tags'             => __( 'Tags', 'fw' ),
			'last_activity_at' => __( 'Last activity', 'fw' ),
			'created_at'       => __( 'Added', 'fw' ),
		);
		if ( ! FW_CRM_Modules::enabled( 'companies' ) ) {
			unset( $columns['company'] );
		}
		return $columns;
	}

	/**
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'name'             => array( 'first_name', false ),
			'stage'            => array( 'lifecycle_stage', false ),
			'status'           => array( 'status', false ),
			'last_activity_at' => array( 'last_activity_at', true ),
			'created_at'       => array( 'created_at', true ),
		);
	}

	/**
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'unsubscribe' => __( 'Mark unsubscribed', 'fw' ),
			'subscribe'   => __( 'Mark subscribed', 'fw' ),
		);

		// Tagging in bulk is the main reason this screen exists, but it needs a
		// tag to act on — so only offer it once at least one tag exists, and pair
		// it with the picker rendered in extra_tablenav().
		if ( FW_CRM_Lists::all( 'tag' ) ) {
			$actions['add_tag']    = __( 'Add tag →', 'fw' );
			$actions['remove_tag'] = __( 'Remove tag →', 'fw' );
		}

		$actions['trash'] = __( 'Move to bin', 'fw' );

		if ( ! FW_CRM_Capabilities::can_manage() ) {
			unset( $actions['subscribe'] ); // Subscribing in bulk needs the people's consent: managers only.
		}

		return $actions;
	}

	/**
	 * The status filter links above the table, with counts.
	 *
	 * @return array
	 */
	protected function get_views() {
		$counts  = FW_CRM_Subscribers::counts_by_status( FW_CRM_Capabilities::owner_scope() );
		$current = isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$base    = FW_CRM_Admin_Page::get_page_url( 'subscribers' );

		$labels = array(
			'all'          => __( 'All', 'fw' ),
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'pending'      => __( 'Pending', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Complained', 'fw' ),
			'none'         => __( 'Not subscribed', 'fw' ),
		);

		$views = array();

		foreach ( $labels as $status => $label ) {
			$count = isset( $counts[ $status ] ) ? (int) $counts[ $status ] : 0;

			// Hide the rare states until they actually have rows.
			if ( 0 === $count && in_array( $status, array( 'bounced', 'complained', 'pending' ), true ) ) {
				continue;
			}

			$url     = 'all' === $status ? $base : add_query_arg( 'status', $status, $base );
			$is_here = ( 'all' === $status && '' === $current ) || $status === $current;

			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%s)</span></a>',
				esc_url( $url ),
				$is_here ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}

		return $views;
	}

	/**
	 * The list dropdown, next to the bulk actions.
	 *
	 * @param string $which
	 */
	protected function extra_tablenav( $which ) {
		// Managed tags are kept up to date by the extension that owns them.
		$tags = array_values( array_filter( FW_CRM_Lists::all( 'tag' ), function ( $t ) {
			return empty( $t->managed_by );
		} ) );

		// The tag picker the add_tag / remove_tag bulk actions act on. It lives in
		// the same <form> as the bulk-action select, on both nav rows so it is
		// always beside whichever bulk control the user reached for.
		if ( $tags ) {
			?>
			<div class="alignleft actions fw-crm-bulk-tag">
				<label class="screen-reader-text" for="fw-crm-bulk-tag-<?php echo esc_attr( $which ); ?>"><?php esc_html_e( 'Tag to add or remove', 'fw' ); ?></label>
				<select name="bulk_tag" id="fw-crm-bulk-tag-<?php echo esc_attr( $which ); ?>">
					<option value=""><?php esc_html_e( '— which tag? —', 'fw' ); ?></option>
					<?php foreach ( $tags as $tag ) : ?>
						<option value="<?php echo esc_attr( $tag->id ); ?>"><?php echo esc_html( $tag->title ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<?php
		}

		if ( 'top' !== $which ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification -- read-only filtering.
		$lists        = FW_CRM_Lists::all( 'list' );
		$segments     = FW_CRM_Lists::segments();
		$current_list = isset( $_REQUEST['list'] ) ? sanitize_key( wp_unslash( $_REQUEST['list'] ) ) : '';
		$current_tag  = isset( $_REQUEST['tag'] ) ? sanitize_key( wp_unslash( $_REQUEST['tag'] ) ) : '';
		$current_seg  = isset( $_REQUEST['segment'] ) ? (int) $_REQUEST['segment'] : 0;
		// phpcs:enable

		$current_stage = isset( $_REQUEST['stage'] ) ? sanitize_key( wp_unslash( $_REQUEST['stage'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="alignleft actions">
			<label class="screen-reader-text" for="fw-crm-stage"><?php esc_html_e( 'Filter by stage', 'fw' ); ?></label>
			<select name="stage" id="fw-crm-stage">
				<option value=""><?php esc_html_e( 'All stages', 'fw' ); ?></option>
				<?php foreach ( FW_CRM_Contacts::stages() as $slug => $label ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current_stage, $slug ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>

			<?php if ( $segments ) : ?>
				<label class="screen-reader-text" for="fw-crm-segment"><?php esc_html_e( 'Segment', 'fw' ); ?></label>
				<select name="segment" id="fw-crm-segment">
					<option value=""><?php esc_html_e( 'All contacts', 'fw' ); ?></option>
					<?php foreach ( $segments as $segment ) : ?>
						<option value="<?php echo esc_attr( $segment->id ); ?>" <?php selected( $current_seg, (int) $segment->id ); ?>>
							<?php echo esc_html( $segment->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( $lists ) : ?>
				<label class="screen-reader-text" for="fw-crm-list"><?php esc_html_e( 'Filter by list', 'fw' ); ?></label>
				<select name="list" id="fw-crm-list">
					<option value=""><?php esc_html_e( 'All lists', 'fw' ); ?></option>
					<?php foreach ( $lists as $list ) : ?>
						<option value="<?php echo esc_attr( $list->slug ); ?>" <?php selected( $current_list, $list->slug ); ?>>
							<?php echo esc_html( $list->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php if ( $tags ) : ?>
				<label class="screen-reader-text" for="fw-crm-tag"><?php esc_html_e( 'Filter by tag', 'fw' ); ?></label>
				<select name="tag" id="fw-crm-tag">
					<option value=""><?php esc_html_e( 'All tags', 'fw' ); ?></option>
					<?php foreach ( $tags as $tag ) : ?>
						<option value="<?php echo esc_attr( $tag->slug ); ?>" <?php selected( $current_tag, $tag->slug ); ?>>
							<?php echo esc_html( $tag->title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			<?php endif; ?>

			<?php submit_button( __( 'Filter', 'fw' ), '', 'filter_action', false ); ?>
		</div>
		<?php
	}

	/**
	 * The query args derived from the current request — shared with the exporter
	 * so "Export these results" really means these results.
	 *
	 * @return array
	 */
	public static function request_query_args() {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filtering.
		$args = array(
			'status'  => isset( $_REQUEST['status'] ) ? sanitize_key( wp_unslash( $_REQUEST['status'] ) ) : '',
			'list'    => isset( $_REQUEST['list'] ) ? sanitize_key( wp_unslash( $_REQUEST['list'] ) ) : '',
			'tag'     => isset( $_REQUEST['tag'] ) ? sanitize_key( wp_unslash( $_REQUEST['tag'] ) ) : '',
			'stage'   => isset( $_REQUEST['stage'] ) ? sanitize_key( wp_unslash( $_REQUEST['stage'] ) ) : '',
			'search'  => isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '',
			'orderby' => isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at',
			'order'   => isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc',
		);

		// A segment is a saved set of exactly these filters; the repository merges
		// it in, with any explicit filter still winning — so picking a segment and
		// then narrowing it further works the way you would expect.
		if ( ! empty( $_REQUEST['segment'] ) ) {
			$args['segment'] = (int) $_REQUEST['segment'];
		}
		// phpcs:enable

		return $args;
	}

	/**
	 * Fetch rows + set up pagination.
	 */
	public function prepare_items() {
		$per_page = $this->get_items_per_page( 'fw_crm_subscribers_per_page', 20 );
		$paged    = $this->get_pagenum();

		$args = FW_CRM_Capabilities::scope( array_merge( self::request_query_args(), array(
			'per_page' => $per_page,
			'paged'    => $paged,
		) ) );

		$this->query_args = $args;

		$this->items = FW_CRM_Subscribers::query( $args );

		$this->set_pagination_args( array(
			'total_items' => FW_CRM_Subscribers::count( $args ),
			'per_page'    => $per_page,
		) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'name' );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="subscribers[]" value="%d" />', (int) $item->id );
	}

	/**
	 * The contact: initials, name (or the email when there is no name), the email
	 * underneath, and the row actions. The whole name links to the profile.
	 *
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_name( $item ) {
		$url  = FW_CRM_Admin_Page::get_page_url( 'subscribers' );
		$url  = add_query_arg( array( 'subscriber' => (int) $item->id ), $url );
		$name = trim( $item->first_name . ' ' . $item->last_name );
		$sub  = (string) $item->email;
		if ( '' === $name ) {
			$name = '' !== $sub ? $sub : (string) $item->phone;
			$sub  = '' !== (string) $item->email ? (string) $item->phone : '';
		}

		$actions = array(
			'view' => sprintf( '<a href="%s">%s</a>', esc_url( $url ), esc_html__( 'Open', 'fw' ) ),
		);

		if ( 'pending' === $item->status ) {
			$actions['resend'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( FW_CRM_Admin_Page::action_url( 'resend', $item->id ) ),
				esc_html__( 'Resend confirmation', 'fw' )
			);
		}

		if ( in_array( $item->status, array( 'subscribed', 'pending' ), true ) ) {
			$actions['unsubscribe'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( FW_CRM_Admin_Page::action_url( 'unsubscribe', $item->id ) ),
				esc_html__( 'Unsubscribe', 'fw' )
			);
		}

		$actions['trash'] = sprintf(
			'<a href="%s" class="submitdelete">%s</a>',
			esc_url( FW_CRM_Admin_Page::action_url( 'trash', $item->id ) ),
			esc_html__( 'Move to bin', 'fw' )
		);

		return sprintf(
			'<div class="fw-crm-who">%s<div class="fw-crm-who__text"><a class="fw-crm-who__name row-title" href="%s">%s</a>%s</div></div>%s',
			FW_CRM_App::avatar( $name, 32 ),
			esc_url( $url ),
			esc_html( $name ),
			'' !== $sub ? '<span class="fw-crm-who__sub">' . esc_html( $sub ) . '</span>' : '',
			$this->row_actions( $actions )
		);
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_stage( $item ) {
		return FW_CRM_App::stage_pill( (string) $item->lifecycle_stage );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_company( $item ) {
		$company = (int) $item->company_id ? FW_CRM_Companies::find( (int) $item->company_id ) : null;
		if ( ! $company ) {
			return '<span aria-hidden="true">—</span>';
		}

		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( add_query_arg( 'company', (int) $company->id, FW_CRM_Admin_Page::get_page_url( 'companies' ) ) ),
			esc_html( $company->name )
		);
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_last_activity_at( $item ) {
		return FW_CRM_App::time_ago( $item->last_activity_at );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_status( $item ) {
		$labels = array(
			'subscribed'   => __( 'Subscribed', 'fw' ),
			'unsubscribed' => __( 'Unsubscribed', 'fw' ),
			'pending'      => __( 'Pending', 'fw' ),
			'bounced'      => __( 'Bounced', 'fw' ),
			'complained'   => __( 'Complained', 'fw' ),
			'none'         => __( 'Not subscribed', 'fw' ),
		);

		$label = isset( $labels[ $item->status ] ) ? $labels[ $item->status ] : $item->status;

		return sprintf(
			'<span class="fw-crm-status fw-crm-status--%s">%s</span>',
			esc_attr( $item->status ),
			esc_html( $label )
		);
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_lists( $item ) {
		return $this->membership_column( $item, 'list' );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_tags( $item ) {
		return $this->membership_column( $item, 'tag' );
	}

	/**
	 * @param object $item
	 * @param string $type
	 *
	 * @return string
	 */
	private function membership_column( $item, $type ) {
		$rows = FW_CRM_Subscribers::get_lists( $item->id, $type );

		if ( ! $rows ) {
			return '<span aria-hidden="true">—</span>';
		}

		$base  = FW_CRM_Admin_Page::get_page_url();
		$links = array();

		foreach ( $rows as $row ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( add_query_arg( $type, $row->slug, $base ) ),
				esc_html( $row->title )
			);
		}

		return implode( ', ', $links );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_source( $item ) {
		if ( '' === $item->source ) {
			return '<span aria-hidden="true">—</span>';
		}

		if ( '' !== $item->source_url ) {
			return sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $item->source_url ),
				esc_html( $item->source )
			);
		}

		return esc_html( $item->source );
	}

	/**
	 * @param object $item
	 *
	 * @return string
	 */
	protected function column_created_at( $item ) {
		$time = strtotime( $item->created_at );

		if ( ! $time ) {
			return '<span aria-hidden="true">—</span>';
		}

		return sprintf(
			'<abbr title="%s">%s</abbr>',
			esc_attr( date_i18n( 'Y-m-d H:i', $time ) ),
			esc_html( date_i18n( get_option( 'date_format' ), $time ) )
		);
	}

	/**
	 * @param object $item
	 * @param string $column_name
	 *
	 * @return string
	 */
	protected function column_default( $item, $column_name ) {
		return isset( $item->$column_name ) ? esc_html( $item->$column_name ) : '';
	}

	/**
	 * Shown when the store is empty or a filter matched nothing.
	 */
	public function no_items() {
		esc_html_e( 'No contacts match. Add one with New contact, import a CSV, or put a newsletter or contact form on a page and the people who send it will appear here.', 'fw' );
	}
}
