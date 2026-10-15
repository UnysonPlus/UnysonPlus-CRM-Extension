<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'CRM', 'fw' );
$manifest['slug']        = 'unysonplus-crm';
$manifest['description'] = __(
	'Contacts, lists, tags and segments, email campaigns with a drag-and-drop email builder, double opt-in and preferences, form and newsletter signups, CSV import and export, and a REST API. Grows into a full CRM: companies, a sales pipeline, automations and scoring.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
 *
 * 1.0.68 - Forms add the sender to the CRM (FW_CRM_Form_Map). A contact form's "Add to the CRM"
 *         settings gain "Add the sender to the CRM": every submission with an email address
 *         creates or updates the contact (status "none", so nobody is subscribed without
 *         consent). A "Save the answers" table in the Settings tab lists the form's fields and
 *         sends each to a contact field (name split into first and last, phone, job title,
 *         company, address), an existing custom field, a new custom field the CRM creates from
 *         the form field (type and choices copied, linked to the field by its id so a rename
 *         keeps it; created when the page is saved), or only the timeline. Name, phone and
 *         matching custom fields are pre-filled automatically. Existing contacts get empty
 *         fields filled (or every answer, if chosen), and kept differences are noted. Every
 *         submission lands on the timeline with all its answers and a link to its entry. A form
 *         can also create a deal (a chosen package that matches a product sets the line and
 *         value; the date field sets the close date) or a task, call or meeting on the chosen
 *         date. Newsletter subscription stays a separate switch that needs ticked consent.
 *         Action unysonplus_crm_form_submitted, automation event crm.form_submitted, webhook
 *         event form.submitted.
 *
 * 1.0.67 - Modules and webhooks (schema 2.10.0). The CRM is now a small core (contacts, notes,
 *         tags, lists and segments, tasks, files, import and export, custom fields, the bin,
 *         roles) plus modules switched on from a new Modules screen laid out as cards with a
 *         switch each: Companies, Sales pipeline, Calendar, Email conversations, Email
 *         marketing, Audit log and Webhooks, with Automations, Lead scoring and Support cases
 *         listed as planned. A module that is off loads no code, registers no REST routes and
 *         adds no menu; its data stays. A fresh install asks "What will you use the CRM for?"
 *         on Home and switches on only what the answers need; a site that already used the CRM
 *         keeps every module it has data for. Modules register through
 *         unysonplus_crm_modules (label, description, icon, group, default, requires,
 *         requires_text, available, soon). The new Webhooks module posts CRM events (contacts,
 *         tags, subscriptions, companies, deals, tasks, campaign opens, clicks, bounces and
 *         replies) as JSON to the addresses that asked for them, signed with an HMAC-SHA256
 *         per-endpoint secret, queued in the background, retried for about a day and logged,
 *         with a test button and REST routes under /webhooks. Other code adds events with
 *         unysonplus_crm_webhook_events and fires them with the unysonplus_crm_webhook action.
 *
 * 1.0.66 - Opens, clicks, bounces and campaign reports (schema 2.9.0, FW_CRM_Tracking). Campaign
 *         emails carry an open pixel and route their links through a signed redirect (each
 *         address stored once in email_links); unsubscribe, preference and confirmation links
 *         are left alone. Link scanners are recorded but not counted: known scanner user
 *         agents, HEAD and prefetch requests, clicks within seconds of sending and bursts of
 *         several links at once. Bounces and spam complaints that reach the inbound address or
 *         mailbox are read from their delivery-status and feedback reports (and plain
 *         mail-system bounces): a permanent failure marks the contact bounced, a complaint marks
 *         them complained, and each is matched to its campaign by the Message-ID campaign emails
 *         now carry. Unsubscribes from a campaign's link or List-Unsubscribe header are counted
 *         against it. First opens and clicks land on the contact's timeline. Each campaign gets
 *         a report (sent, opened, clicked, unsubscribed, bounced, opens and clicks by hour, the
 *         links clicked, latest activity) and the campaign list shows open and click rates.
 *         Settings to switch open and click tracking off. Action unysonplus_crm_email_event;
 *         automation events crm.email_opened and crm.email_clicked.
 *
 * 1.0.65 - Sending queue rework (schema 2.8.0). Each batch claims its recipients with one
 *         atomic UPDATE, so overlapping workers can never send to the same person, and a
 *         send interrupted mid-flight is marked failed rather than retried (no one gets an
 *         email twice). A sending-rate setting with presets (shared hosting 300 an hour,
 *         VPS 1,500, an email service 10 a second, or a custom hourly figure) caps sending
 *         across all campaigns over a rolling hour and paces it within each run; a run
 *         stops after 30 seconds and hands back what it did not reach. A campaign can have
 *         its own hourly speed limit, so a big list's opens and clicks arrive gradually.
 *         Campaigns now count skipped recipients, and a daily job clears the per-recipient
 *         send log of campaigns finished longer ago than the retention setting (180 days)
 *         while keeping their totals. Filters unysonplus_crm_send_presets,
 *         unysonplus_crm_send_rate and unysonplus_crm_tick_seconds.
 *
 * 1.0.61 - Custom fields screen (Data -> Custom fields, managers). Add, edit and delete the
 *         fields on contacts, companies and deals without code: label, key (filled in from
 *         the label), type, choices for choice fields, the record a link field points to,
 *         the prefix and digits of an auto-number, a group and whether it is required.
 *         Fields managed by another extension are listed and locked.
 *
 * 1.0.60 - Replies on the timeline (FW_CRM_Inbound_Email). Incoming email reaches the CRM two
 *         ways: an inbound address (a private REST URL an email service's inbound forwarding
 *         posts each message to, raw or as parsed fields) and a mailbox the CRM checks over
 *         IMAP every five minutes (its own small IMAP client and MIME reader; only mail that
 *         arrives after connecting is read, and the mailbox is never changed). A reply from a
 *         contact lands on their timeline as "Email received" without the quoted history,
 *         and on the deal when it answers an email written from that deal (one-to-one emails
 *         now carry a CRM Message-ID). Mail a team member sends with the CRM's address in Bcc
 *         is logged as sent to the contacts it went to. Unknown senders are skipped, or
 *         added as contacts when allowed. Each message is handled once. Settings: CRM ->
 *         Settings -> Replies on the timeline (the password is stored encrypted). Action
 *         unysonplus_crm_email_received and automation event crm.email_replied; settings hook
 *         unysonplus_crm_settings_sections.
 *
 * 1.0.59 - Customise Home. Each person arranges their own CRM Home: drag cards between and
 *         within the two columns, or use the buttons on each card (move up or down, move to
 *         the other column, hide); hidden cards wait in a tray to be shown again, and Reset
 *         restores the default. Saved per user as you go (user meta fw_crm_home_layout).
 *         Cards added with unysonplus_crm_dashboard_cards join the same layout, and a card
 *         someone has not placed yet appears in its own column.
 *
 * 1.0.58 - Products and line items (schema 2.7.0, FW_CRM_Products). A product list (Deals ->
 *         Products: name, SKU, price, per-unit, archive) and a Products panel on each deal:
 *         pick from the list, from the shop's products when the shop is active, or type a custom
 *         line, with quantity, price and a discount. A line copies the name and price, so
 *         later price changes or deleted products never rewrite an old deal. Once a deal has
 *         lines its value is their total (kept in step; the value field turns read-only).
 *         Adding and removing lines lands on the deal's timeline. REST /products and
 *         /deals/{id}/items; filter unysonplus_crm_woo_products.
 *
 * 1.0.57 - One-to-one email (FW_CRM_Direct_Email). An Email button on contact, company and
 *         deal pages opens a composer (To: the contact, or one of the company's people or
 *         the deal's contacts; Cc; subject; message with {{first_name}} and the other
 *         placeholders). It goes through wp_mail, so the Mailer decides delivery; the
 *         writer's name is the From name and their address the Reply-To, so answers come
 *         back to them. It is personal mail: no unsubscribe footer and no tracking, and it
 *         reaches people who unsubscribed from lists. Sent mail lands on the contact's
 *         timeline (and the deal's); a failed send shows the reason and reopens the draft.
 *         Action unysonplus_crm_direct_email_sent, filter unysonplus_crm_direct_email,
 *         REST POST /contacts/{id}/email. New header hook unysonplus_crm_record_actions
 *         for buttons on record pages.
 *
 * 1.0.56 - Files on records. A Files panel on every contact, company and deal page uploads
 *         files (several at once) or picks them from the media library; each opens in a new
 *         tab and can be removed from the record (it stays in the library). Adding and
 *         removing land on the timeline. Files are ordinary attachments linked to the record
 *         (relations record -> attachment `file`), so deleting one from the media library
 *         unlinks it everywhere. The CRM Manager and Sales Rep roles gain upload_files.
 *         FW_CRM_Files (attach, detach, upload, for_record), actions
 *         unysonplus_crm_file_attached / _detached, REST /{contacts|companies|deals}/{id}/files.
 *
 * 1.0.55 - Sales reports (CRM -> Reports, pipeline module; FW_CRM_Reports). Headline figures
 *         (open pipeline, weighted forecast, won, win rate, average deal won and days to
 *         close), open deals by stage, a forecast by expected close month weighted by stage
 *         probability (with rows for deals past their date and without one), won and lost
 *         per month, why deals were lost, and a per-owner table for managers. Filter by
 *         pipeline, period (presets or custom dates) and owner; sales reps see their own
 *         numbers. REST GET /reports/sales returns the same data.
 *
 * 1.0.54 - Calendar (CRM -> Calendar). A month or week of tasks by their due time, open
 *         deals on their expected close date and campaigns on the day they are scheduled
 *         or were sent (managers), each linking to its record. Overdue items show in red,
 *         done ones struck through; the "+" on a day adds a task for that day. Managers can
 *         switch to anyone's calendar or everyone's; sales reps see their own. On a phone
 *         the month becomes a list of the days that have something on. FW_CRM_Calendar::
 *         events( $from, $to, $owner ), filter unysonplus_crm_calendar_events for other
 *         extensions to add their own, and REST GET /calendar?from&to (up to 62 days).
 *         Calls and meetings (schema 2.6.0): a duration, a meeting's place and attendees
 *         (the record's people; relations task -> contact `attendee`). "Log a call or
 *         meeting" on every contact, company and deal records one that already happened,
 *         with its outcome. Ticking off a planned call or meeting asks how it went and how
 *         long it took; the entry lands on the record's timeline and each attendee's.
 *         FW_CRM_Tasks::log_call(), attendees(), set_attendees(); REST tasks carry
 *         duration, location and attendee_ids, and POST /tasks with done: true logs one.
 *
 * 1.0.53 - REST API for the sales pipeline, tasks and notes (fw-crm/v1). /pipelines (read for
 *         everyone, write for managers; GET /pipelines/{id} includes per-stage totals),
 *         /deals with the board's filters, PATCH (a stage_id moves the deal), /deals/{id}/move
 *         { stage_id } or { status: won|lost, lost_reason }, the bin, linked contacts and the
 *         deal timeline, /lost-reasons; /tasks with the open|done|overdue|today|upcoming
 *         filters, complete (with an outcome logged to the record) and reopen, /task-types;
 *         PATCH and DELETE /notes/{id}. Same rules as the screens: a sales rep reaches only
 *         their own deals and tasks, cannot hand them to someone else or link records they do
 *         not own, and only managers delete for good. The deal routes exist only while the
 *         pipeline module is on.
 *
 * 1.0.52 - Sales pipeline module (schema 2.5.0). Pipelines with ordered stages (each with a win
 *         probability and a "stalled after N days" limit) plus fixed Won and Lost stages; a
 *         default "Sales" pipeline is seeded. Deals (FW_CRM_Deals) carry a value, currency,
 *         owner, contact, company, expected close date and an optional probability override;
 *         extra contacts can be attached. Deals board (Deals tab): drag cards between stages or
 *         onto Won / Lost (Lost asks for a reason), with per-stage totals and stalled flags.
 *         The deal page has a stage path, timeline, notes, tasks and contacts; contact and
 *         company pages list their deals. Pipeline editor to add, rename, reorder and remove
 *         stages. Tasks (FW_CRM_Tasks: to-do, call, meeting, email) on any contact, company or
 *         deal, a My tasks view, completion with an outcome logged to the record, and an hourly
 *         reminder digest per owner. Notes can be edited by their author or a manager. Home
 *         gains "Your tasks" and a pipeline funnel. Deals go to the Bin, follow merges and
 *         survive their contact being deleted. Actions unysonplus_crm_deal_created, _updated,
 *         _stage_changed, _won, _lost, _trashed and _deleted; filters
 *         unysonplus_crm_default_currency and unysonplus_crm_task_reminders.
 *
 * 1.0.51 - Import rework (schema 2.4.0, FW_CRM_Import). Every CSV import is a batch with a
 *         history (Import / Export -> Past imports) and can be undone: contacts it created are
 *         deleted, contacts it changed are put back, and the lists, tags, consent records and
 *         company links it added are removed. Columns map to every contact field (full name,
 *         phone, job title, company by name, lifecycle stage by name, address, lists, tags and
 *         every custom field), guessed from the header and remembered per column layout. Rows
 *         match by email, or by phone when there is no email; existing contacts are filled in,
 *         updated or skipped as chosen. "Check first" runs the whole file without saving. Nobody
 *         is subscribed unless the importer confirms everyone agreed (recorded in each consent
 *         log), and people who unsubscribed or left a list are not put back unless asked.
 *         Imports run in chunks from the progress screen and carry on in the background
 *         (cron fw_crm_import_tick) if the tab is closed. Export can pick a segment and now
 *         includes phone, job title, company, stage, owner, address and custom fields. Actions
 *         unysonplus_crm_import_finished and unysonplus_crm_import_undone; filters
 *         unysonplus_crm_import_fields and unysonplus_crm_import_seconds.
 *
 * 1.0.50 - Tags API (FW_CRM_Tags). add( $contact_id, $slugs, $source ) creates unknown tags on
 *         demand (slug => title for a label), remove(), for_contact(), has( any|all|none ),
 *         rename( $tag_id, $title, $slug ) and merge( $from_id, $into_id ), which moves everyone
 *         to the kept tag, deletes the other and rewrites segments that used it. Tagging and
 *         untagging now land on the timeline ("Tagged VIP") with their source and fire
 *         unysonplus_crm_tag_added / unysonplus_crm_tag_removed; saving a contact's tags only
 *         records what actually changed. Lists & segments gains Rename or merge tags (managed
 *         tags are protected); tag counts include every contact wearing the tag.
 *
 * 1.0.49 - Subscription status per list (schema 2.3.0, FW_CRM_List_Membership). Each list
 *         membership keeps its own status (subscribed or unsubscribed), when the person joined,
 *         when they left and where from; the contact's own status stays the address-level
 *         permission. Someone is emailed for a list only while both say subscribed. Leaving a
 *         list (the preferences page, the profile, the API) now marks the membership
 *         unsubscribed instead of deleting it, so a CSV import no longer adds people back to
 *         lists they left (unless "overwrite" is ticked) and their own signup rejoins them.
 *         Joining and leaving land on the timeline; the contact page lists the lists they left;
 *         REST contacts carry list_status. Action unysonplus_crm_list_status_changed.
 *
 * 1.0.48 - Module switches (FW_CRM_Modules). CRM -> Settings -> Modules turns parts of the CRM
 *         on or off; contacts and companies are always on. Email marketing is the first
 *         module: off, its code does not load (campaigns, the email builder and the sender moved
 *         to modules/email/, outside the auto-loaded includes/ folder), its sending job is
 *         cleared and its menu, New menu entry, Home card and AI tool disappear; it refuses to
 *         turn off while a campaign is sending or scheduled. Sales pipeline, automations and
 *         lead scoring are listed as coming soon. Filters unysonplus_crm_modules and
 *         unysonplus_crm_module_can_disable; action unysonplus_crm_module_toggled; nav items can
 *         carry a `module`.
 *
 * 1.0.47 - Audit log (schema 2.2.0, FW_CRM_Audit). Who changed what and when: contacts and
 *         companies created, edited (each changed field with its old and new value), binned,
 *         restored, deleted and merged; site account links; segments and lists saved or
 *         deleted; campaigns started; exports, imports and settings changes. Built from the
 *         change events the CRM already fires, so anything going through the API is covered;
 *         background changes are recorded as "the system". New screen Data -> Audit log
 *         (managers), filterable by person and kind of record; REST GET /audit. Entries are kept
 *         365 days (filter unysonplus_crm_audit_days); unysonplus_crm_audit_record can skip
 *         one. New actions unysonplus_crm_exported, unysonplus_crm_import_started and
 *         unysonplus_crm_settings_saved.
 *
 * 1.0.46 - Team roles (FW_CRM_Capabilities). Capabilities fw_crm_access (use the CRM),
 *         fw_crm_view_all (every record, not only their own) and fw_crm_manage (campaigns,
 *         lists and segments, import and export, the bin, merging, custom fields, settings);
 *         roles "CRM Manager" (all three) and "Sales Rep" (access: they see and edit the
 *         contacts and companies they own, and own what they create); administrators hold all
 *         three. The CRM's capability is now fw_crm_access (filter unysonplus_crm_capability
 *         still overrides it), and people without the Unyson+ menu get the CRM as a top-level
 *         menu. Scoping applies where a person looks (screens, Home, search, REST); the
 *         repository, background work and other extensions' server-side checks still see every
 *         record. Linking a contact to a site account through REST needs the right to edit that
 *         user. Nav items can carry a `cap`.
 *
 * 1.0.45 - Merge duplicates (FW_CRM_Merge). Likely duplicates are contacts sharing a phone
 *         number (compared on the last nine digits) or a full name: a "Possible duplicates"
 *         panel on the contact page and a Find duplicates screen list them. The merge screen
 *         shows both records side by side with a choice for every differing field, then moves
 *         everything onto the kept contact (lists and tags, company links, timeline, consent
 *         log, custom field values, meta, campaign history, the site account link) and deletes
 *         the other. Email permission errs on the safe side (an opt-out, bounce or complaint on
 *         either record is kept), the more advanced lifecycle stage is kept and lifetime values
 *         are added. Actions unysonplus_crm_contacts_merging and unysonplus_crm_contacts_merged
 *         ( $kept_id, $removed_row ); REST POST /contacts/{id}/merge and GET /contacts/duplicates.
 *
 * 1.0.44 - Rule-based segments, the CRM's one filter language (FW_CRM_Segments). A segment is
 *         a tree of rules (match all or any, one level of nested groups) over contact fields,
 *         the company name, lifecycle stage, owner (including "me"), email status, tags, lists
 *         (subscribed), dates (before, after, on, in the last or next N days), timeline
 *         activity ("a form in the last 30 days") and every custom field, typed by its
 *         definition. Rules compile to one SQL condition inside the shared contact query, so
 *         the contacts list, campaign audiences, REST and access checks all use it. New: a
 *         segment builder (Lists & segments, New segment / Edit) with a live count and a plain
 *         sentence of what it matches; segment counts cached for five minutes;
 *         FW_CRM_Segments::contact_matches( $segment_id, $contact_id ) (one query per contact,
 *         cached per request); REST /segments (list, create, read, update, delete), /contacts,
 *         /preview and /fields. Filter unysonplus_crm_segment_fields adds rule fields. Segments
 *         saved from the contacts list's filters still work and open in the builder as rules.
 *
 * 1.0.43 - Extension integration API (schema 2.1.0), built for Membership. WordPress users:
 *         FW_CRM_Contacts::find_by_user(), id_for_user() (one cached query), link_user() and
 *         unlink_user() (one user per contact and one contact per user; WP_Error otherwise;
 *         actions unysonplus_crm_user_linked and unysonplus_crm_user_unlinked; create() and
 *         update() route user_id through the same checks). Access checks:
 *         FW_CRM_Subscribers::has_lists( $contact_id, $slugs, any|all|none, tag|list ) and
 *         FW_CRM_Contacts::user_has( $user_id, ... ), one indexed query cached per request;
 *         lists count only while subscribed and a binned contact holds nothing. Managed tags,
 *         lists and custom fields (managed_by, e.g. get_or_create( $slug, 'tag', array(
 *         'managed_by' => 'membership' ) )) show as locked in the CRM, are kept when staff save
 *         a contact's lists and tags, and warn before deletion. New filters:
 *         unysonplus_crm_contact_panels and unysonplus_crm_company_panels (record page panels),
 *         unysonplus_crm_dashboard_cards (Home cards), unysonplus_crm_activity_types (timeline
 *         labels and icons), unysonplus_crm_managed_by_label. unysonplus_crm_contact_updated now
 *         passes a third argument: the changed columns as field => { old, new }.
 *
 * 1.0.42 - The bin. Deleting a contact or company moves it to CRM -> Bin instead of erasing
 *         it: it is hidden everywhere (lists, search, segments, campaign audiences), its page
 *         shows a banner with Restore and Delete for good, and the move is undoable from the
 *         notice. The Bin screen lists both kinds with Restore, Delete for good and Empty the
 *         bin. A daily job (FW_CRM_Trash, cron fw_crm_empty_bin) deletes what has been in the
 *         bin longer than 30 days; filter unysonplus_crm_bin_days (0 keeps everything until
 *         emptied by hand). Bulk "Delete permanently" on the contacts list became "Move to bin".
 *
 * 1.0.41 - REST API for the contact model (fw-crm/v1): /contacts and /companies with list
 *         (search, stage, status, owner, company, list, tag, segment, bin filters; paged with
 *         X-WP-Total), create, read, update (PATCH/PUT, including custom fields and lists /
 *         tags by slug), DELETE to the bin (?force=true deletes for good) and /restore;
 *         /contacts/{id}/timeline (read, POST a note), /consent, /companies (link, unlink);
 *         /companies/{id}/contacts and /timeline; /fields (definitions, save, delete) and
 *         /stages. Same rules as the admin screens: creating or updating a contact never
 *         subscribes them, which still needs POST /subscribers (the original routes are kept).
 *
 * 1.0.40 - The CRM app. The CRM screen becomes an application inside wp-admin: its own sidebar
 *         (Home, Contacts, Companies, Deals, Tasks, Calendar, Campaigns, Lists & segments,
 *         Reports, Import / Export, Settings; planned areas marked Soon), a top bar with search
 *         across contacts and companies ("/" focuses it) and a New menu. The WordPress menu
 *         folds to icons on CRM screens, and focus mode (per user) hides it, with Back to
 *         WordPress in the sidebar. New screens: Home (status line, contacts by stage, the
 *         activity feed, newest contacts, campaigns, a first-run guide), the contact profile
 *         (stage picker, editable details and custom fields, notes and the timeline, linked
 *         companies, lists and tags, email status, the consent log), New contact, and the
 *         company list, profile and form. The contacts list shows stage, company and last
 *         activity. Other code adds areas with the unysonplus_crm_nav filter and renders them on
 *         unysonplus_crm_render_view; a contact who never subscribed and then signs up fires
 *         unysonplus_crm_subscriber_opted_in (consent and timeline record it).
 *
 * 1.0.39 -The contact model (schema 2.0.0). A subscriber grows into a contact: phone, job title,
 *         owner, lifecycle stage (subscriber, lead, opportunity, customer, inactive; filter
 *         unysonplus_crm_lifecycle_stages), primary company, linked WordPress user, timezone,
 *         address, score, lifetime value and last activity as real columns, and a bin
 *         (deleted_at). Email is optional, so a phone-only lead is a contact. New: companies,
 *         labelled two-way relations between any records, a timeline (FW_CRM_Activity, fed by
 *         signups, confirmations, unsubscribes and form submissions), an append-only consent
 *         log, and typed custom fields (FW_CRM_Fields: text, number, money, dates, choices,
 *         yes/no, URL, email, phone, record links, address, auto-number). FW_CRM_Contacts and
 *         FW_CRM_Companies are the API other code uses; staff-created contacts start as "Not
 *         subscribed" and only a signup with consent subscribes anyone.
 *
 * 1.0.38 - Renamed from Newsletter / Subscriber CRM to CRM, the first step of growing it into a
 *         full CRM. Folder newsletter-crm became crm, classes FW_Newsletter_CRM_* became FW_CRM_*,
 *         functions fw_ext_newsletter_crm_* became fw_ext_crm_*, hooks unysonplus_newsletter_crm_*
 *         became unysonplus_crm_*, the admin page fw-newsletter-crm became fw-crm and the settings
 *         live under fw_ext_settings_options:crm. The tables were already fw_crm_* and stay. The
 *         contact form action is now labelled "Add to the CRM"; its saved settings (action_subscribe*)
 *         are unchanged. The Newsletter element still signs people up through fw_newsletter_subscribe.
 *
 * 1.0.37 - AI Assistant abilities, so an AI app connected through the MCP server can
 *         set up the CRM: crm-describe (settings, lists, tags, segments, campaigns, with
 *         subscriber counts only), crm-save-lists, crm-save-segment, crm-settings (double
 *         opt-in, the confirmation and welcome emails; undoable) and crm-save-campaign
 *         (drafts only). No ability returns a subscriber's details or sends anything.
 *         See includes/ai-abilities.php.
 *
 * 1.0.33 - Preference centre: `{{preferences_url}}` opens a page where a
 *         subscriber ticks the lists they want, saved per list. Keyed by the
 *         unsubscribe token on purpose — it never expires and already sits in
 *         the footer of every email ever sent, so a separate token would be one
 *         more thing to expire and strand. Saving never unsubscribes: a person
 *         on zero lists is still a subscriber, so opting out of everything is
 *         the separate, explicit "Unsubscribe from all emails" link. An
 *         unsubscribed person sees a message rather than checkboxes, because
 *         coming back is a signup with consent, not a checkbox on a page
 *         reached from an old email.
 *
 * 1.0.32 - Forms bridge. A "Subscribe to the newsletter" action for the Forms
 *         extension (per-form list, tags and name field), plus a "Form entries"
 *         activity panel on the subscriber screen. The action is gated on ONE
 *         thing and one thing only: a ticked Consent field whose purpose is
 *         "newsletter". Not an email field, not a label that says newsletter,
 *         not the action being switched on — the consent field is a dedicated
 *         form-builder type precisely so this is checked by type, and a ticked
 *         terms box does not count. It follows the double opt-in setting, so
 *         when that is on the confirmation email is the consent record.
 *         Entries are submissions and subscribers are people: the panel reads
 *         entries by email, and the CRM never writes them.
 *
 * 1.0.26 - Every block gains an "Extra styles" escape hatch for CSS its own
 *         options don't expose.
 *         Deliberately INLINE DECLARATIONS, not a Custom CSS box. A page
 *         builder's Custom CSS writes a scoped RULE into a stylesheet; an email
 *         has no stylesheet worth depending on — Gmail drops everything past
 *         ~102 KB, taking a <style> block with it, and styles are commonly
 *         stripped on forward. A rule here would work while the author tested
 *         it and silently vanish for part of their list, with no way to tell.
 *         Inline is the one layer every client honours, so that is where these
 *         declarations go: appended to the block's own style attribute, last,
 *         so the author wins over our defaults.
 *         Sanitising allow-lists by SHAPE rather than blocklisting keywords —
 *         each part must look like `property: value` — so selectors, at-rules
 *         and stray braces are refused as a consequence of the format rather
 *         than as special cases somebody has to remember to add. On top of that
 *         the genuinely dangerous CSS is dropped: expression(), javascript: and
 *         vbscript: URLs, behavior:, @import, data:text/html, plus angle
 *         brackets and comments. Quotes are kept on purpose, since
 *         font-family:'Segoe UI' needs them and esc_attr already makes them
 *         safe.
 *         Wiring note: the option is appended centrally in get_options() so no
 *         block can be built without it, and the compiler now calls render()
 *         rather than compile() — it stashes the block's values so shared
 *         helpers can reach them, then clears them so nothing leaks between
 *         blocks.
 *
 * 1.0.25 - The admin menu entry and page heading are now "Newsletter" rather
 *         than "Subscribers". The screen outgrew that name: it holds campaigns,
 *         lists, tags, segments, import/export and settings, and the subscriber
 *         list is one tab within it — so the old label named the first tab
 *         rather than the screen, and buried the campaign sender where nobody
 *         would look for it. The TAB is still called Subscribers, which is
 *         where the word belongs. Menu ordering keys off the page slug, not the
 *         label, so nothing moved.
 *
 * 1.0.24 - Alignment is now the same image-picker swatch control the shortcodes
 *         use, on all nine blocks that have one — they already shared a single
 *         align_option() helper, so it was one change rather than nine.
 *         The three swatch SVGs are COPIED into this extension rather than
 *         borrowed from the shortcodes extension via sc_alignment_field(): the
 *         CRM is documented to work with that extension inactive, and a control
 *         that silently degrades from swatches to radios depending on what else
 *         is switched on is worse than either one consistently. They total
 *         under a kilobyte, and image-picker itself is core framework, so this
 *         adds no cross-extension dependency. There is deliberately no
 *         "Default" swatch as the shortcode version has — that means "inherit
 *         from the theme/parent" and an email has no such cascade, so it would
 *         be a control that does nothing.
 *         The Logo block's text field is now PREFILLED with the site title as a
 *         real, editable value rather than a greyed placeholder, so the text is
 *         there to be selected and typed over. The consequence worth knowing:
 *         it is a stored value now, so a campaign keeps the name it was written
 *         with if the site is later renamed — the right trade for a newsletter,
 *         where an already-composed email should not change underneath you.
 *         Clearing the field still falls back to the live site title.
 *
 * 1.0.22 - The Logo block gains a "Logo text" option, and the site title now
 *         reaches readers as plain text everywhere.
 *         The block always fell back image -> Customizer logo -> site title,
 *         but had no field for that last step, so on a converted site the
 *         wordmark appeared in the email from nowhere with nothing in the panel
 *         to change it. There is now a text option (empty = the site title,
 *         shown as the field's PLACEHOLDER rather than spliced into the
 *         description, since a placeholder is exactly the affordance for "what
 *         you get if you leave this blank" and it keeps the description a
 *         fixed, translatable sentence) which doubles as
 *         the image's alt text, so a newsletter can also say something other
 *         than the site's own name without editing the site.
 *         The bug that exposed: a site title may legitimately contain MARKUP —
 *         the Site Converter emits a two-tone wordmark like
 *         My<span class="accent">Company</span> and the theme prints it raw —
 *         and esc_html() on that mailed subscribers a literal
 *         &lt;span class="accent"&gt;. Keeping the tag was no better: there is
 *         no stylesheet in an inbox for the class to mean anything. One helper,
 *         Mail::site_name(), now strips tags for the {{site_name}} placeholder,
 *         the Logo block's text and alt, and the plain-text renderer alike —
 *         which also stops a theme setting injecting HTML into every email.
 *         Also adds the placeholder + layout help under the Email Builder,
 *         which previously only appeared under the visual editor. The layout
 *         half differs per editor on purpose: telling a builder user to avoid
 *         multi-column layouts would be wrong, since compiling columns that
 *         survive Outlook is exactly what the builder does for them.
 *
 * 1.0.20 - Email template library, plus six starter templates: Announcement,
 *         Newsletter digest, Product promotion, Welcome, Event invitation and
 *         Plain letter.
 *         The library itself is the FRAMEWORK's, not a new one — the builder
 *         extension already ships it behind a per-builder `template_saving`
 *         flag, so switching that on gives the Templates panel, save-canvas-as-
 *         template, load, delete and JSON export/import, with storage scoped by
 *         builder type so an email template can never appear in the page
 *         builder's list. Same call as reusing the framework's width changer,
 *         and for the same reason: writing our own would have meant a second
 *         storage scheme and a worse version of a component that works.
 *         The starters are authored as PHP rather than pasted JSON, because a
 *         template stores option VALUES and therefore goes stale the moment a
 *         block's options change — the trap the theme Preset Library has. As
 *         code a renamed option is a visible edit in a reviewed file; a frozen
 *         blob rots silently until a user opens a broken template. Each ends
 *         with a footer block, since bulk mail needs a visible opt-out and a
 *         postal address and a starter without one teaches people to send
 *         non-compliant email, and each hardcodes almost no colour so the
 *         site's own palette shows through instead of ours.
 *         Fixes a bug the starters exposed, which affected every builder
 *         campaign: Campaigns::sanitize() ran wp_kses_post() over the compiled
 *         body, stripping <style> and entity-escaping the Outlook conditional
 *         comments that carry the ghost tables and the VML button fallback —
 *         so a builder campaign was MANGLED ON SAVE, before the sender ever saw
 *         it. A complete document (Mail::is_document) is now stored verbatim;
 *         it is assembled by our own compiler from per-block values already
 *         sanitised by their option types, so no untrusted markup rides along.
 *         A body typed into the visual editor still goes through kses — that
 *         is where author markup actually arrives.
 *
 * 1.0.19 - Campaign previewer. A Preview box in the campaign editor renders the
 *         email as it would be sent, with desktop/mobile widths, a plain-text
 *         tab, a size meter against Gmail's ~102 KB clipping limit, and an
 *         "Open in new tab" route that serves the compiled document with no
 *         admin chrome around it.
 *         It previews UNSAVED editor state, which is the whole point — a
 *         preview you must save first is one nobody uses while iterating — and
 *         it persists nothing.
 *         The rule that makes it trustworthy: it calls the SAME functions
 *         Sender::send_one() calls, in the same order, and the test asserts the
 *         output is byte-identical to what the sender would build. A preview
 *         assembled by a lookalike code path would quietly drift from the real
 *         email, which is worse than having no preview.
 *         The stand-in recipient is synthetic with empty tokens, so every link
 *         in a preview is inert. Rendering against a real subscriber would make
 *         the unsubscribe link LIVE, and an admin, a link prefetcher or a
 *         corporate mail scanner could opt a real person out just by looking at
 *         a draft.
 *         The preview immediately earned its keep by exposing a bug in the SEND
 *         path: render_body() ran its fragment pipeline over compiled builder
 *         emails, so wp_kses_post() stripped the <style> block and dumped the
 *         mobile-stacking CSS into the message as VISIBLE TEXT, then nested the
 *         whole document inside a second doctype shell. A body that is already
 *         a complete document now passes through untouched (Mail::is_document),
 *         and with_unsubscribe() uses the same test so its opt-out line lands
 *         inside </body> rather than after </html>, where clients are free to
 *         drop it — which would have meant bulk mail with no working opt-out.
 *
 * 1.0.15 - Email Builder: ten more blocks, taking the tray from four to
 *         fourteen — Logo, Heading, Spacer, Menu, Social, Hero, Video, Table,
 *         Footer and Raw HTML, in a deliberate tray order that runs
 *         top-of-email → structure → chrome. Several are shaped by what email
 *         genuinely cannot do rather than by preference: Social ships NO icon
 *         set (Gmail strips SVG and Outlook ignores it, and the only working
 *         alternative would be redistributing bundled brand trademarks), so it
 *         renders styled text links with an optional uploaded icon; Video links
 *         a poster image with a play overlay because no client plays video
 *         inline; Hero emits a VML background fallback for the Word engine; and
 *         Raw HTML is filtered against the SAVING user's `unfiltered_html`
 *         capability, which works only because compilation happens on save.
 *         Canvas summaries are now data-driven from each block's new
 *         `get_preview_keys()`, so a new block needs no JavaScript at all, and
 *         repeater-backed blocks list their link labels instead of a bare
 *         count. `Compiler::to_plain_text()` covers every new type, so nothing
 *         silently disappears from the text part of a multipart send.
 *
 * 1.0.8 - Email Builder: columns. Each block now carries a width (1/4, 1/3,
 *         1/2, 2/3, 3/4, full) set with the FRAMEWORK's own width changer —
 *         the same component the Learning extension's quiz builder uses, from
 *         the builder extension's helpers.js — so the editor side was almost
 *         free. Our own coarse width vocabulary replaces the framework default
 *         via the `fw_builder_item_widths:email-builder` filter, because the
 *         page builder's twelfths are far too granular for a 600px email (a
 *         1/12 column is ~50px and cannot hold anything).
 *         The compiler packs CONSECUTIVE blocks into rows while the widths
 *         still fit, so columns need no nested container type: two halves sit
 *         side by side, a third half starts a new row, and a full-width block
 *         always stands alone. Rows render with the hybrid pattern every
 *         mature email builder uses — an MSO ghost table with a real <td> per
 *         column for Outlook's Word engine, inline-block <div>s for every
 *         other client, and a media query that stacks them on mobile.
 *         Two notes worth recording. First, this is the one place email needs
 *         divs: MJML's own output does the same, and what stays forbidden is
 *         flex, grid and float. Second, the column container sets font-size:0
 *         because HTML whitespace BETWEEN inline-block elements renders as a
 *         visible gap and would break the column maths; the real size is reset
 *         inside each column.
 *         Required a small refactor: blocks now return a SELF-CONTAINED table
 *         rather than a bare <tr>, since the compiler places a block either in
 *         a full-width row or inside a column and only a self-contained table
 *         works in both. Same reason MJML makes every component a table.
 *
 * 1.0.7 - Email Builder (phase one). A drag-drop block editor for campaign
 *         bodies, offered alongside the visual editor via an Editor switch.
 *         Four blocks to start: Text, Image, Button and Divider.
 *         It is the framework's FOURTH builder subclass — after the Page
 *         Builder, the Forms extension's Form Builder and the Learning
 *         extension's Quiz Builder — so the drag-drop canvas, item tray,
 *         reorder/clone/delete and the options modal all come for free.
 *         What could NOT be reused is rendering: the page builder renders
 *         through shortcodes, which emit divs, CSS classes and enqueued
 *         stylesheets — the exact three things email forbids. So blocks
 *         compile themselves, the way the quiz builder renders its own
 *         question markup, and a new compiler assembles the document:
 *         nested tables with role="presentation", inline styles only, an
 *         Outlook ghost table and PixelsPerInch fix, a VML fallback so
 *         buttons survive Outlook's Word engine, and a <style> block that
 *         carries mobile stacking as enhancement ONLY, because Gmail strips
 *         it in clipped views.
 *         Storage needed no migration risk: the block tree lives in a new
 *         `body_json` column and is compiled to HTML into the EXISTING
 *         `body` on save — so the queue, batching, test sends and
 *         render_body() are entirely unaware a builder exists, and campaigns
 *         written in the visual editor keep working because they simply have
 *         no block tree. Switching back to the visual editor clears the tree
 *         so the two can never disagree about what gets sent.
 *         Also ships an output-size estimator (Gmail clips beyond ~102 KB)
 *         and a plain-text renderer built from the BLOCKS rather than by
 *         stripping tags off compiled HTML, which would just yield table
 *         scaffolding. Global styles resolve block value → campaign default →
 *         built-in, the priority order MJML uses.
 *         Schema 1.2.0 adds campaigns.body_json.
 *
 * 1.0.6 - The campaign Message field is now the full WordPress visual editor
 *         (wp_editor) rather than a plain textarea — Add Media, Visual/Code
 *         tabs, and the familiar toolbar, trimmed to formatting email clients
 *         can actually render. A campaign that has started sending shows a
 *         read-only preview instead, since TinyMCE has no honest disabled
 *         state. Saving still runs wp_kses_post, so script cannot get in.
 *         The change that had to come with it: the send path called
 *         wpautop() unconditionally, which is correct for the plain-text
 *         confirmation templates but double-wraps editor HTML and wrecks its
 *         spacing. Sending now uses maybe_autop() — autop only when the body
 *         carries no block-level markup of its own — and campaigns share the
 *         one body pipeline (sanitise, linkify, autop-if-needed, email shell)
 *         with every other message rather than a near-copy that could drift.
 *         An auto-appended unsubscribe line now matches the body's format, so
 *         an HTML message no longer ends with plain text glued to its last
 *         paragraph.
 *         Note: email clients are not browsers — Outlook renders with the Word
 *         engine — so the toolbar deliberately omits anything that would
 *         invite unreliable multi-column or floated layouts.
 *
 * 1.0.5 - Campaigns. Compose a message, pick an audience (list, tag or saved
 *         segment), then send now or schedule it. Sending runs in small batches
 *         on WP-Cron, and the design is driven entirely by three facts about
 *         WP-Cron: it fires on page loads rather than on a clock, it can fire
 *         the same event twice concurrently, and a PHP timeout mid-batch is the
 *         normal case rather than an edge case. So: a new per-recipient queue
 *         table holds one row per recipient, flipped the instant it is handled,
 *         which makes a send resumable and auditable -- a killed request loses
 *         at most the row in flight. An `add_option()` lock (atomic, because
 *         option_name is UNIQUE -- a transient is NOT atomic under an external
 *         object cache) stops overlapping ticks from mailing everyone twice,
 *         and is stolen after 10 minutes so a died-mid-send worker cannot
 *         stall the queue for ever. Batch size is a setting because the real
 *         ceiling is the mail host's rate limit, not PHP. Eligibility is
 *         re-checked at SEND time, not just when the queue was built, so
 *         someone who unsubscribes mid-send is skipped rather than mailed.
 *         Only confirmed subscribers are ever queued -- pending, unsubscribed,
 *         bounced and complained addresses are excluded and cannot be
 *         overridden. A campaign becomes read-only once sending starts (half
 *         the list receiving a different email from the other half is not a
 *         thing we allow), pause keeps the queue so resuming continues rather
 *         than restarting, and a body without {{unsubscribe_url}} gets an
 *         unsubscribe line appended automatically. Adds test sends, a manual
 *         "Run sending now" (WP-Cron does nothing on a site with no traffic),
 *         and List-Unsubscribe headers on every campaign email. New tables:
 *         fw_crm_campaigns, fw_crm_campaign_queue (schema 1.1.0).
 *
 * 1.0.4 - Resumable CSV import. The importer read the whole file in one
 *         request, so a large list hit max_execution_time part-way -- and
 *         because every row commits as it goes, that left a partial import
 *         with no way to resume. It now works in chunks from a byte offset,
 *         bounded by both a row count and a seconds budget, with the offset
 *         taken only after a completed row so resuming can neither re-import
 *         nor skip one. The admin screen drives it from a progress bar and
 *         reports live counts; closing the tab stops it and keeps whatever was
 *         already imported. Line numbers in error messages stay true to the
 *         file rather than resetting per chunk. Both limits default to 0 (no
 *         limit), so a direct `import()` call behaves exactly as before.
 *
 * 1.0.3 - Tags, segments and the UI for both. A new "Lists, Tags & Segments"
 *         tab manages all three; lists and tags render from the same code
 *         because they are the same table, differing only by `type`. The
 *         Subscribers tab gains a tag filter, a segment selector, and bulk
 *         "Add tag" / "Remove tag" actions paired with a tag picker beside the
 *         bulk-action control, plus per-subscriber list/tag checkboxes on the
 *         single view. Filtering the screen and clicking "Save as segment"
 *         stores the current filters as a named segment -- a SAVED QUERY,
 *         re-evaluated on every open, so someone who newly matches is simply in
 *         it and someone who stops matching drops out. Paging, ordering and any
 *         explicit id list are stripped before storing, so a segment can never
 *         degrade into a frozen snapshot; a filterless segment is refused
 *         outright. Deleting a list or tag removes its membership rows and
 *         nothing else -- subscribers are never touched. The Subscribers screen
 *         is now a single GET form (the core edit.php pattern) so filters
 *         survive into the URL and pagination links keep them.
 *         Fixes: add_to_list() reported success for a membership that already
 *         existed, because INSERT IGNORE returns 0 affected rows on a duplicate
 *         and `0 !== false` is true -- which made "tagged N subscribers" count
 *         no-ops as changes.
 *
 * 1.0.2 - Double opt-in, end to end. New signups are stored as Pending, emailed
 *         a tokened confirmation link, and only become Subscribed once they
 *         click it; an optional welcome email follows. Two public endpoints
 *         (query args on the home URL, so there are no rewrite rules to flush
 *         and nothing to break on a permalink change) handle confirming and
 *         unsubscribing. The confirm link deliberately opens a page with a
 *         button rather than confirming on the GET itself — corporate mail
 *         scanners visit every link in an inbound email and would otherwise
 *         opt people in for them; `confirm_on_visit` turns that guard off.
 *         Unsubscribe implements RFC 8058 one-click (a bare POST unsubscribes
 *         with no confirmation step, which is what the List-Unsubscribe and
 *         List-Unsubscribe-Post headers now on every email promise, and what
 *         Gmail and Yahoo require of bulk senders); a GET still shows a normal
 *         page for clients without one-click support. Confirmation tokens are
 *         single-use and expire after 48h (filterable); the unsubscribe token
 *         never expires, because it lives in the footer of every email ever
 *         sent. Adds a "Resend confirmation" row action that rotates the token
 *         so a leaked link dies, editable email templates with placeholders,
 *         and refuses to send a fresh confirmation to someone who unsubscribed.
 *         All mail goes through wp_mail(), so the Mailer extension's SMTP
 *         settings govern transport and SPF/DKIM alignment.
 *
 * 1.0.1 - Thumbnail redrawn to the house icon convention.
 *
 * 1.0.0 - Initial release. Subscriber store (five custom tables), capture off
 *         the [newsletter] element's existing hooks, admin list table with CSV
 *         import/export, provider interface, lifecycle hooks, REST and GDPR.
 */

$manifest['version']    = '1.0.68';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// No hard requirements on purpose: the capture hook simply never fires when the
// shortcodes extension is off, and the store is still useful for imports + REST.

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-CRM-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-CRM-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
