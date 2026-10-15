<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Schema owner. The ONLY class in the extension that emits DDL.
 *
 * This is the plugin's first custom-table extension, so the discipline is set
 * here and documented in AGENTS.md:
 *
 *  - `dbDelta()` is picky by contract — two spaces after PRIMARY KEY, one field
 *    per line, lowercase types, `KEY` (never `INDEX`), and the collation string
 *    from `$wpdb->get_charset_collate()`. Break any of those and it silently
 *    re-runs ALTERs on every single load.
 *  - The installer is idempotent and guarded by an option (`DB_VERSION_OPTION`)
 *    so it costs one autoloaded `get_option()` per request when up to date.
 *  - `dbDelta()` ADDS columns and indexes; it never drops or renames. Anything
 *    destructive must be a numbered migration step in `migrate()`, guarded by
 *    the stored version.
 *  - Tables use `$wpdb->prefix` (per-site), NOT `base_prefix` — on multisite
 *    each site keeps its own subscribers, which is what you want for the demos
 *    network and for real networks alike.
 *  - Deactivating NEVER drops a table. Data removal is an explicit, opt-in
 *    action on the settings screen (see the extension class).
 */
class FW_CRM_Installer {

	/** Bump when the schema below changes. */
	const DB_VERSION = '2.10.0';

	const DB_VERSION_OPTION = 'fw_ext_crm_db_version';

	/**
	 * Fully-qualified table name for a logical table.
	 *
	 * @param string $table subscribers (the contacts)|subscriber_meta|lists|subscriber_pivot|segments|campaigns|
	 *                      campaign_queue|companies|relations|activity|consent|field_values
	 *
	 * @return string
	 */
	public static function table( $table ) {
		global $wpdb;

		return $wpdb->prefix . 'fw_crm_' . $table;
	}

	/**
	 * Install or upgrade if the stored version differs. Cheap no-op otherwise.
	 */
	public static function maybe_install() {
		if ( get_option( self::DB_VERSION_OPTION ) === self::DB_VERSION ) {
			return;
		}

		self::install();
	}

	/**
	 * Create/upgrade every table, then run migrations and stamp the version.
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$collate = $wpdb->get_charset_collate();
		$from    = (string) get_option( self::DB_VERSION_OPTION, '' );

		foreach ( self::schema( $collate ) as $sql ) {
			dbDelta( $sql );
		}

		self::migrate( $from );
		self::seed_default_list();

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
	}

	/**
	 * The schema. One CREATE TABLE per logical table, dbDelta-formatted.
	 *
	 * Design notes (see the Phase 0 report):
	 *  - `email` is varchar(190) so a UNIQUE index fits inside utf8mb4's key
	 *    limit. That unique index — not application code — is what makes dedupe
	 *    atomic and race-free.
	 *  - `status` is varchar, not ENUM, so adding `bounced`/`complained` later is
	 *    a PHP whitelist change, not a schema migration.
	 *  - Lists AND tags live in one `lists` table discriminated by `type`, with a
	 *    single polymorphic pivot carrying membership. One structure, one set of
	 *    queries, and any future object type joins the same pivot.
	 *  - A segment stores its FILTER JSON, never denormalised membership — it is
	 *    a saved query, evaluated on read.
	 *  - 2.0.0 grows the subscriber row into a CONTACT. The table keeps its internal
	 *    name (`subscribers`, and `subscriber_id` in the pivot and queue): code outside
	 *    the CRM goes through FW_CRM_Contacts and never sees it. The busiest values
	 *    (owner, stage, primary company, score, lifetime value, last activity) are real
	 *    columns so segments stay simple indexed queries; custom fields live in
	 *    `field_values`. `email` is NULLable because a phone-only lead is a contact too;
	 *    the UNIQUE index still dedupes every real address (MySQL allows many NULLs).
	 *    `deleted_at` is the bin: set = in the bin, NULL = live.
	 *
	 * @param string $collate
	 *
	 * @return array
	 */
	private static function schema( $collate ) {
		$subscribers = self::table( 'subscribers' );
		$meta        = self::table( 'subscriber_meta' );
		$lists       = self::table( 'lists' );
		$pivot       = self::table( 'subscriber_pivot' );
		$segments    = self::table( 'segments' );

		$sql = array();

		$sql[] = "CREATE TABLE {$subscribers} (
	id bigint(20) unsigned NOT NULL auto_increment,
	email varchar(190) default NULL,
	first_name varchar(100) NOT NULL default '',
	last_name varchar(100) NOT NULL default '',
	phone varchar(50) NOT NULL default '',
	job_title varchar(150) NOT NULL default '',
	status varchar(20) NOT NULL default 'subscribed',
	lifecycle_stage varchar(30) NOT NULL default 'subscriber',
	owner_id bigint(20) unsigned NOT NULL default 0,
	company_id bigint(20) unsigned NOT NULL default 0,
	user_id bigint(20) unsigned NOT NULL default 0,
	timezone varchar(50) NOT NULL default '',
	address_line1 varchar(190) NOT NULL default '',
	address_line2 varchar(190) NOT NULL default '',
	city varchar(100) NOT NULL default '',
	region varchar(100) NOT NULL default '',
	postcode varchar(20) NOT NULL default '',
	country varchar(2) NOT NULL default '',
	score int(11) NOT NULL default 0,
	lifetime_value decimal(14,2) NOT NULL default 0,
	last_activity_at datetime default NULL,
	deleted_at datetime default NULL,
	source varchar(50) NOT NULL default '',
	source_url varchar(255) NOT NULL default '',
	confirm_token varchar(64) NOT NULL default '',
	confirm_token_at datetime default NULL,
	unsubscribe_token varchar(64) NOT NULL default '',
	ip varchar(45) NOT NULL default '',
	consent_at datetime default NULL,
	confirmed_at datetime default NULL,
	unsubscribed_at datetime default NULL,
	meta longtext,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY email (email),
	KEY status (status),
	KEY lifecycle_stage (lifecycle_stage),
	KEY owner_id (owner_id),
	KEY company_id (company_id),
	KEY user_id (user_id),
	KEY phone (phone),
	KEY last_activity_at (last_activity_at),
	KEY deleted_at (deleted_at),
	KEY created_at (created_at),
	KEY confirm_token (confirm_token),
	KEY unsubscribe_token (unsubscribe_token)
) {$collate};";

		$sql[] = "CREATE TABLE {$meta} (
	id bigint(20) unsigned NOT NULL auto_increment,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	meta_key varchar(100) NOT NULL default '',
	meta_value longtext,
	PRIMARY KEY  (id),
	UNIQUE KEY subscriber_key (subscriber_id,meta_key),
	KEY meta_key (meta_key)
) {$collate};";

		$sql[] = "CREATE TABLE {$lists} (
	id bigint(20) unsigned NOT NULL auto_increment,
	type varchar(20) NOT NULL default 'list',
	slug varchar(100) NOT NULL default '',
	title varchar(190) NOT NULL default '',
	description text,
	managed_by varchar(50) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY type_slug (type,slug)
) {$collate};";

		$sql[] = "CREATE TABLE {$pivot} (
	id bigint(20) unsigned NOT NULL auto_increment,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	object_id bigint(20) unsigned NOT NULL default 0,
	object_type varchar(20) NOT NULL default 'list',
	status varchar(20) NOT NULL default 'subscribed',
	subscribed_at datetime default NULL,
	unsubscribed_at datetime default NULL,
	source varchar(50) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY membership (subscriber_id,object_id,object_type),
	KEY object (object_id,object_type),
	KEY object_status (object_id,object_type,status)
) {$collate};";

		$campaigns = self::table( 'campaigns' );
		$queue     = self::table( 'campaign_queue' );

		$sql[] = "CREATE TABLE {$campaigns} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(190) NOT NULL default '',
	subject varchar(255) NOT NULL default '',
	body longtext,
	body_json longtext,
	audience longtext,
	status varchar(20) NOT NULL default 'draft',
	scheduled_at datetime default NULL,
	started_at datetime default NULL,
	finished_at datetime default NULL,
	total int unsigned NOT NULL default 0,
	sent int unsigned NOT NULL default 0,
	failed int unsigned NOT NULL default 0,
	skipped int unsigned NOT NULL default 0,
	max_per_hour int unsigned NOT NULL default 0,
	queue_purged_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY status (status),
	KEY scheduled_at (scheduled_at)
) {$collate};";

		// The per-recipient send queue. This table is the ENTIRE reason a send can
		// survive a PHP timeout: a campaign row alone cannot answer "who did we
		// already send to?", so a batch that dies mid-flight would either
		// double-send or silently drop people. One row per recipient, flipped as
		// it goes, makes the send resumable and auditable.
		//
		// `email` is snapshotted so the log still reads correctly after a
		// subscriber is deleted.
		$sql[] = "CREATE TABLE {$queue} (
	id bigint(20) unsigned NOT NULL auto_increment,
	campaign_id bigint(20) unsigned NOT NULL default 0,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	email varchar(190) NOT NULL default '',
	status varchar(20) NOT NULL default 'pending',
	error varchar(255) NOT NULL default '',
	claim varchar(32) NOT NULL default '',
	claimed_at datetime default NULL,
	sent_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY recipient (campaign_id,subscriber_id),
	KEY campaign_status (campaign_id,status),
	KEY claim (claim),
	KEY sent_at (sent_at)
) {$collate};";

		$sql[] = "CREATE TABLE {$segments} (
	id bigint(20) unsigned NOT NULL auto_increment,
	slug varchar(100) NOT NULL default '',
	title varchar(190) NOT NULL default '',
	filters longtext,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY slug (slug)
) {$collate};";

		$companies = self::table( 'companies' );
		$relations = self::table( 'relations' );
		$activity  = self::table( 'activity' );
		$consent   = self::table( 'consent' );
		$values    = self::table( 'field_values' );
		$audit     = self::table( 'audit' );
		$imports   = self::table( 'imports' );
		$irows     = self::table( 'import_rows' );
		$pipelines = self::table( 'pipelines' );
		$stages    = self::table( 'pipeline_stages' );
		$deals     = self::table( 'deals' );
		$tasks     = self::table( 'tasks' );
		$products  = self::table( 'products' );
		$links     = self::table( 'email_links' );
		$events    = self::table( 'email_events' );
		$items     = self::table( 'deal_items' );

		$sql[] = "CREATE TABLE {$companies} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	domain varchar(190) NOT NULL default '',
	website varchar(255) NOT NULL default '',
	phone varchar(50) NOT NULL default '',
	email varchar(190) NOT NULL default '',
	industry varchar(100) NOT NULL default '',
	size varchar(30) NOT NULL default '',
	owner_id bigint(20) unsigned NOT NULL default 0,
	address_line1 varchar(190) NOT NULL default '',
	address_line2 varchar(190) NOT NULL default '',
	city varchar(100) NOT NULL default '',
	region varchar(100) NOT NULL default '',
	postcode varchar(20) NOT NULL default '',
	country varchar(2) NOT NULL default '',
	lifetime_value decimal(14,2) NOT NULL default 0,
	last_activity_at datetime default NULL,
	deleted_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY name (name),
	KEY domain (domain),
	KEY owner_id (owner_id),
	KEY deleted_at (deleted_at)
) {$collate};";

		// Labelled two-way links between any two records. A link is stored once
		// (from -> to); reads look both ways, so new record types attach without a
		// schema change.
		$sql[] = "CREATE TABLE {$relations} (
	id bigint(20) unsigned NOT NULL auto_increment,
	from_type varchar(30) NOT NULL default '',
	from_id bigint(20) unsigned NOT NULL default 0,
	to_type varchar(30) NOT NULL default '',
	to_id bigint(20) unsigned NOT NULL default 0,
	label varchar(50) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY link (from_type,from_id,to_type,to_id,label),
	KEY reverse (to_type,to_id)
) {$collate};";

		// The timeline: one row per thing that happened to a record.
		$sql[] = "CREATE TABLE {$activity} (
	id bigint(20) unsigned NOT NULL auto_increment,
	object_type varchar(30) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	type varchar(50) NOT NULL default '',
	title varchar(255) NOT NULL default '',
	body longtext,
	meta longtext,
	actor_id bigint(20) unsigned NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY object_time (object_type,object_id,created_at),
	KEY type_time (type,created_at)
) {$collate};";

		// What a contact agreed to: the exact wording, when, where. Never updated,
		// only appended, so it stays a record.
		$sql[] = "CREATE TABLE {$consent} (
	id bigint(20) unsigned NOT NULL auto_increment,
	contact_id bigint(20) unsigned NOT NULL default 0,
	purpose varchar(50) NOT NULL default '',
	granted tinyint(1) NOT NULL default 1,
	text text,
	source varchar(50) NOT NULL default '',
	source_url varchar(255) NOT NULL default '',
	ip varchar(45) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY contact_purpose (contact_id,purpose)
) {$collate};";

		// Custom field values. value_num / value_date mirror the value for numbers,
		// money and dates so lists can sort and filter on them with an index.
		$sql[] = "CREATE TABLE {$values} (
	id bigint(20) unsigned NOT NULL auto_increment,
	object_type varchar(30) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	field_key varchar(64) NOT NULL default '',
	value longtext,
	value_num decimal(20,4) default NULL,
	value_date datetime default NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY object_field (object_type,object_id,field_key),
	KEY field_num (field_key,value_num),
	KEY field_date (field_key,value_date)
) {$collate};";

		// The audit log: who changed what (2.2.0).
		$sql[] = "CREATE TABLE {$audit} (
	id bigint(20) unsigned NOT NULL auto_increment,
	actor_id bigint(20) unsigned NOT NULL default 0,
	object_type varchar(30) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	action varchar(40) NOT NULL default '',
	details longtext,
	ip varchar(45) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY object (object_type,object_id),
	KEY actor_time (actor_id,created_at),
	KEY created_at (created_at)
) {$collate};";

		// CSV imports as undoable batches (2.4.0).
		$sql[] = "CREATE TABLE {$imports} (
	id bigint(20) unsigned NOT NULL auto_increment,
	user_id bigint(20) unsigned NOT NULL default 0,
	filename varchar(190) NOT NULL default '',
	file varchar(255) NOT NULL default '',
	header longtext,
	samples longtext,
	mapping longtext,
	options longtext,
	status varchar(20) NOT NULL default 'mapping',
	total_rows int unsigned NOT NULL default 0,
	byte_offset bigint(20) unsigned NOT NULL default 0,
	line int unsigned NOT NULL default 0,
	stats longtext,
	dry_stats longtext,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	started_at datetime default NULL,
	finished_at datetime default NULL,
	undone_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY status (status)
) {$collate};";

		$sql[] = "CREATE TABLE {$irows} (
	id bigint(20) unsigned NOT NULL auto_increment,
	import_id bigint(20) unsigned NOT NULL default 0,
	contact_id bigint(20) unsigned NOT NULL default 0,
	action varchar(10) NOT NULL default '',
	before_values longtext,
	added longtext,
	PRIMARY KEY  (id),
	KEY import_id (import_id),
	KEY contact_id (contact_id)
) {$collate};";

		// Sales pipeline (2.5.0). Pipelines with ordered stages; a stage is open, won or lost.
		$sql[] = "CREATE TABLE {$pipelines} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	currency char(3) NOT NULL default '',
	position int NOT NULL default 0,
	is_default tinyint(1) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id)
) {$collate};";

		$sql[] = "CREATE TABLE {$stages} (
	id bigint(20) unsigned NOT NULL auto_increment,
	pipeline_id bigint(20) unsigned NOT NULL default 0,
	name varchar(190) NOT NULL default '',
	kind varchar(10) NOT NULL default 'open',
	probability tinyint unsigned NOT NULL default 0,
	rot_days smallint unsigned NOT NULL default 0,
	position int NOT NULL default 0,
	PRIMARY KEY  (id),
	KEY pipeline (pipeline_id,position)
) {$collate};";

		$sql[] = "CREATE TABLE {$deals} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(190) NOT NULL default '',
	pipeline_id bigint(20) unsigned NOT NULL default 0,
	stage_id bigint(20) unsigned NOT NULL default 0,
	status varchar(10) NOT NULL default 'open',
	value decimal(14,2) NOT NULL default 0,
	currency char(3) NOT NULL default '',
	probability tinyint unsigned default NULL,
	owner_id bigint(20) unsigned NOT NULL default 0,
	contact_id bigint(20) unsigned NOT NULL default 0,
	company_id bigint(20) unsigned NOT NULL default 0,
	expected_close date default NULL,
	lost_reason varchar(190) NOT NULL default '',
	position int NOT NULL default 0,
	stage_changed_at datetime default NULL,
	closed_at datetime default NULL,
	last_activity_at datetime default NULL,
	deleted_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY pipeline_stage (pipeline_id,stage_id,status),
	KEY owner_id (owner_id),
	KEY contact_id (contact_id),
	KEY company_id (company_id),
	KEY expected_close (expected_close),
	KEY deleted_at (deleted_at)
) {$collate};";

		// Tasks: calls, meetings, emails and to-dos on any record (2.5.0). 2.6.0 adds the
		// duration (minutes) and location of a call or meeting; attendees are relations
		// task -> contact labelled `attendee`.
		$sql[] = "CREATE TABLE {$tasks} (
	id bigint(20) unsigned NOT NULL auto_increment,
	title varchar(190) NOT NULL default '',
	type varchar(20) NOT NULL default 'todo',
	object_type varchar(30) NOT NULL default '',
	object_id bigint(20) unsigned NOT NULL default 0,
	owner_id bigint(20) unsigned NOT NULL default 0,
	due_at datetime default NULL,
	done_at datetime default NULL,
	duration smallint(5) unsigned NOT NULL default 0,
	location varchar(190) NOT NULL default '',
	notes text,
	reminder_sent_at datetime default NULL,
	created_by bigint(20) unsigned NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY owner_due (owner_id,done_at,due_at),
	KEY object (object_type,object_id)
) {$collate};";

		// Products and deal line items (2.7.0). A line item COPIES the name and price, so
		// changing or deleting a product never rewrites an old deal. `source` is crm (this
		// table), woo (a WooCommerce product id) or custom (typed on the deal).
		$sql[] = "CREATE TABLE {$products} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	sku varchar(100) NOT NULL default '',
	description text,
	price decimal(15,2) NOT NULL default 0,
	unit varchar(30) NOT NULL default '',
	active tinyint(1) NOT NULL default 1,
	position int(11) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY active (active,position)
) {$collate};";

		$sql[] = "CREATE TABLE {$items} (
	id bigint(20) unsigned NOT NULL auto_increment,
	deal_id bigint(20) unsigned NOT NULL default 0,
	source varchar(10) NOT NULL default 'custom',
	product_id bigint(20) unsigned NOT NULL default 0,
	name varchar(190) NOT NULL default '',
	sku varchar(100) NOT NULL default '',
	unit_price decimal(15,2) NOT NULL default 0,
	quantity decimal(12,2) NOT NULL default 1,
	discount decimal(5,2) NOT NULL default 0,
	total decimal(15,2) NOT NULL default 0,
	position int(11) NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY deal (deal_id,position),
	KEY product (source,product_id)
) {$collate};";

		// Email tracking (2.9.0): each link address stored once, and one row per
		// open, click, bounce, complaint or unsubscribe. `bot` marks link scanners
		// (kept for the record, left out of every number).
		$sql[] = "CREATE TABLE {$links} (
	id bigint(20) unsigned NOT NULL auto_increment,
	hash char(32) NOT NULL default '',
	url text,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	UNIQUE KEY hash (hash)
) {$collate};";

		$sql[] = "CREATE TABLE {$events} (
	id bigint(20) unsigned NOT NULL auto_increment,
	campaign_id bigint(20) unsigned NOT NULL default 0,
	subscriber_id bigint(20) unsigned NOT NULL default 0,
	queue_id bigint(20) unsigned NOT NULL default 0,
	type varchar(20) NOT NULL default '',
	link_id bigint(20) unsigned NOT NULL default 0,
	bot tinyint(1) NOT NULL default 0,
	user_agent varchar(190) NOT NULL default '',
	detail varchar(255) NOT NULL default '',
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id),
	KEY campaign_type (campaign_id,type,bot),
	KEY queue (queue_id),
	KEY subscriber (subscriber_id)
) {$collate};";

		// Webhooks (2.10.0): the endpoints, and one row per delivery attempt chain.
		// A delivery keeps its payload as it was when the event happened, so a
		// retry sends exactly what the first try sent, under the same `uuid`.
		$hooks      = self::table( 'webhooks' );
		$deliveries = self::table( 'webhook_deliveries' );
		$sql[] = "CREATE TABLE {$hooks} (
	id bigint(20) unsigned NOT NULL auto_increment,
	name varchar(190) NOT NULL default '',
	url text,
	events text,
	secret varchar(100) NOT NULL default '',
	active tinyint(1) NOT NULL default 1,
	failures int(10) unsigned NOT NULL default 0,
	last_status smallint(5) NOT NULL default 0,
	last_delivery_at datetime default NULL,
	created_by bigint(20) unsigned NOT NULL default 0,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	updated_at datetime NOT NULL default '0000-00-00 00:00:00',
	PRIMARY KEY  (id)
) {$collate};";

		$sql[] = "CREATE TABLE {$deliveries} (
	id bigint(20) unsigned NOT NULL auto_increment,
	webhook_id bigint(20) unsigned NOT NULL default 0,
	uuid char(36) NOT NULL default '',
	event varchar(100) NOT NULL default '',
	payload longtext,
	status varchar(20) NOT NULL default 'pending',
	attempts smallint(5) unsigned NOT NULL default 0,
	response_code smallint(5) NOT NULL default 0,
	response_body text,
	duration_ms int(10) unsigned NOT NULL default 0,
	next_attempt_at datetime default NULL,
	created_at datetime NOT NULL default '0000-00-00 00:00:00',
	delivered_at datetime default NULL,
	PRIMARY KEY  (id),
	KEY due (status,next_attempt_at),
	KEY webhook (webhook_id,created_at)
) {$collate};";

		return $sql;
	}

	/**
	 * Destructive / data-shape migrations that dbDelta cannot express.
	 *
	 * Add a numbered, version-guarded block here when the schema changes in a way
	 * that needs data moved. Empty for 1.0.0 by definition.
	 *
	 * @param string $from Previously installed version ('' on a fresh install).
	 */
	private static function migrate( $from ) {
		if ( '' === $from ) {
			return; // Fresh install — the schema above is already current.
		}

		global $wpdb;

		// 2.0.0: a contact may have no email (a phone-only lead). Empty strings would
		// collide on the UNIQUE index, so they become NULL, which it allows many times.
		if ( version_compare( $from, '2.0.0', '<' ) ) {
			$contacts = self::table( 'subscribers' );
			$wpdb->query( "ALTER TABLE {$contacts} MODIFY email varchar(190) default NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
			$wpdb->query( "UPDATE {$contacts} SET email = NULL WHERE email = ''" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// 2.3.0: list memberships carry their own dates; existing ones joined when created.
		if ( version_compare( $from, '2.3.0', '<' ) ) {
			$pivot = self::table( 'subscriber_pivot' );
			$wpdb->query( "UPDATE {$pivot} SET subscribed_at = created_at WHERE object_type = 'list' AND subscribed_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/**
	 * Make sure the built-in "default" list exists, so a signup with no list ID
	 * still lands somewhere queryable.
	 */
	private static function seed_default_list() {
		FW_CRM_Lists::get_or_create( 'default', 'list', __( 'Default', 'fw' ) );
	}

	/**
	 * Drop everything. Only ever called from the explicit, opt-in "remove all
	 * data" action on the settings screen — never on deactivation.
	 */
	public static function uninstall() {
		wp_clear_scheduled_hook( 'fw_crm_inbound_poll' );
		wp_clear_scheduled_hook( 'fw_crm_webhooks_tick' );
		delete_option( 'fw_crm_inbound' );
		delete_option( 'fw_crm_modules' );
		delete_option( 'fw_crm_modules_v2' );
		delete_option( 'fw_crm_setup' );
		delete_option( 'fw_crm_inbound_seen' );
		global $wpdb;

		foreach ( array( 'campaign_queue', 'campaigns', 'subscriber_pivot', 'subscriber_meta', 'segments', 'subscribers', 'lists', 'companies', 'relations', 'activity', 'consent', 'field_values', 'audit', 'imports', 'import_rows', 'pipelines', 'pipeline_stages', 'deals', 'tasks', 'products', 'deal_items', 'email_links', 'email_events', 'webhooks', 'webhook_deliveries' ) as $table ) {
			$name = self::table( $table );
			$wpdb->query( "DROP TABLE IF EXISTS {$name}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		delete_option( self::DB_VERSION_OPTION );

		if ( class_exists( 'FW_CRM_Capabilities' ) ) {
			FW_CRM_Capabilities::remove_roles();
		}

		// The CRM's background jobs go with its data.
		foreach ( array( 'fw_crm_empty_bin', 'fw_crm_send_tick', 'fw_crm_import_tick', 'fw_crm_task_reminders' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
