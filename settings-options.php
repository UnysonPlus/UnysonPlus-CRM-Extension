<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

/**
 * Settings for the CRM extension.
 *
 * Rendered both on the extension's own Settings tab (Unyson+ → Subscribers) and
 * by the Extensions manager's settings form, so it must stay a plain options
 * array — no side effects, and safe to load on the front end (the options model
 * resolves defaults there too).
 */

$list_choices = array( '' => __( '— Default —', 'fw' ) );

// The lists table may not exist yet on the very first load after activation.
if ( class_exists( 'FW_CRM_Lists' ) && get_option( 'fw_ext_crm_db_version' ) ) {
	foreach ( FW_CRM_Lists::all( 'list' ) as $list ) {
		$list_choices[ $list->slug ] = $list->title;
	}
}

// Email defaults live with the mail class, so the settings screen and an unsaved
// send can never disagree about what the default template is.
$confirm_defaults = class_exists( 'FW_CRM_Mail' )
	? FW_CRM_Mail::defaults( 'confirm' )
	: array( 'subject' => '', 'body' => '' );
$welcome_defaults = class_exists( 'FW_CRM_Mail' )
	? FW_CRM_Mail::defaults( 'welcome' )
	: array( 'subject' => '', 'body' => '' );

$options = array(
	'box_capture' => array(
		'type'    => 'box',
		'title'   => __( 'Capture', 'fw' ),
		'options' => array(
			'default_list' => array(
				'type'    => 'select',
				'label'   => __( 'Default list', 'fw' ),
				'desc'    => __( 'Where a signup lands when the [newsletter] element has no List ID of its own.', 'fw' ),
				'value'   => '',
				'choices' => $list_choices,
			),
			'auto_create_lists' => array(
				'type'         => 'switch',
				'label'        => __( 'Create lists on demand', 'fw' ),
				'desc'         => __( 'The element\'s List ID is free text. With this on, an unrecognised ID creates the list automatically; with it off, the signup falls back to the default list.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'admin_notify' => array(
				'type'         => 'switch',
				'label'        => __( 'Email the admin on every signup', 'fw' ),
				'desc'         => __( 'The [newsletter] element notifies the site admin by email. Now that signups are stored, you may not want that any more — turn it off here.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'double_optin' => array(
				'type'         => 'switch',
				'label'        => __( 'Double opt-in', 'fw' ),
				'desc'         => __( 'New signups are stored as Pending and emailed a confirmation link; they only become Subscribed once they click it. Strongly recommended — it is what keeps a mistyped or maliciously-entered address off your list, and what most email regulations expect.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'confirm_on_visit' => array(
				'type'         => 'switch',
				'label'        => __( 'Confirm as soon as the link is opened', 'fw' ),
				'desc'         => __( 'By default the confirmation link opens a page with a "Confirm subscription" button, because corporate mail scanners automatically visit every link in an incoming email and would otherwise opt people in for them. Turn this on only if you would rather have no extra click and accept that risk.', 'fw' ),
				'value'        => 'no',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
		),
	),

	'box_emails' => array(
		'type'    => 'box',
		'title'   => __( 'Emails', 'fw' ),
		'desc'    => __( 'Sent through wp_mail(), so the Mailer extension\'s SMTP settings apply. Placeholders: {{name}}, {{first_name}}, {{last_name}}, {{email}}, {{site_name}}, {{site_url}}, {{confirm_url}}, {{unsubscribe_url}}.', 'fw' ),
		'options' => array(
			'confirm_subject' => array(
				'type'  => 'text',
				'label' => __( 'Confirmation subject', 'fw' ),
				'value' => $confirm_defaults['subject'],
			),
			'confirm_body' => array(
				'type'  => 'textarea',
				'label' => __( 'Confirmation email', 'fw' ),
				'desc'  => __( 'Must contain {{confirm_url}} — without it nobody can confirm.', 'fw' ),
				'value' => $confirm_defaults['body'],
			),
			'welcome_email' => array(
				'type'         => 'switch',
				'label'        => __( 'Send a welcome email', 'fw' ),
				'desc'         => __( 'Sent once, right after someone confirms.', 'fw' ),
				'value'        => 'no',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'welcome_subject' => array(
				'type'  => 'text',
				'label' => __( 'Welcome subject', 'fw' ),
				'value' => $welcome_defaults['subject'],
			),
			'welcome_body' => array(
				'type'  => 'textarea',
				'label' => __( 'Welcome email', 'fw' ),
				'value' => $welcome_defaults['body'],
			),
		),
	),

	'box_sending' => array(
		'type'    => 'box',
		'title'   => __( 'Campaign sending', 'fw' ),
		'options' => array(
			'send_rate' => array(
				'type'    => 'select',
				'label'   => __( 'How fast to send', 'fw' ),
				'desc'    => __( 'Shared hosting usually allows a few hundred emails an hour; going over gets mail refused or the account flagged. With an email service, sending can be much faster.', 'fw' ),
				'value'   => 'shared',
				'choices' => array(
					'shared'  => __( 'Shared hosting: up to 300 an hour', 'fw' ),
					'vps'     => __( 'VPS or managed hosting: up to 1,500 an hour', 'fw' ),
					'service' => __( 'An email service: up to 10 a second', 'fw' ),
					'custom'  => __( 'Custom (below)', 'fw' ),
				),
			),
			'send_rate_custom' => array(
				'type'  => 'text',
				'label' => __( 'Custom limit (emails an hour)', 'fw' ),
				'desc'  => __( 'Used when "How fast to send" is Custom. Ask your host or email service what they allow.', 'fw' ),
				'value' => '300',
			),
			'queue_retention_days' => array(
				'type'  => 'text',
				'label' => __( 'Keep the per-person send log for (days)', 'fw' ),
				'desc'  => __( 'After a campaign has finished, the list of who it was sent to is cleared after this many days to keep the database small. The campaign\'s totals are kept. 0 keeps it for ever.', 'fw' ),
				'value' => '180',
			),
			'batch_size' => array(
				'type'  => 'text',
				'label' => __( 'Recipients per batch', 'fw' ),
				'desc'  => __( 'How many emails go out each time the sender runs (about once a minute, traffic permitting). The real ceiling is your mail host\'s rate limit, not PHP — if messages start bouncing or getting throttled, lower this. Capped at 500.', 'fw' ),
				'value' => '50',
			),
		),
	),

	'box_tracking' => array(
		'type'    => 'box',
		'title'   => __( 'Campaign tracking', 'fw' ),
		'options' => array(
			'track_opens' => array(
				'type'         => 'switch',
				'label'        => __( 'Track opens', 'fw' ),
				'desc'         => __( 'Adds an invisible image to each campaign email. Opens are an estimate: some mail apps load images for every email, others block them.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'track_clicks' => array(
				'type'         => 'switch',
				'label'        => __( 'Track clicks', 'fw' ),
				'desc'         => __( 'Links in campaign emails pass through your site first, so you can see what people clicked. Clicks made by email security scanners are left out.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
		),
	),

	'box_privacy' => array(
		'type'    => 'box',
		'title'   => __( 'Privacy', 'fw' ),
		'options' => array(
			'store_ip' => array(
				'type'         => 'switch',
				'label'        => __( 'Store the signup IP', 'fw' ),
				'desc'         => __( 'Kept as consent evidence — who agreed, when, and from where. It is personal data, so it is included in the WordPress personal-data export and cleared on erasure.', 'fw' ),
				'value'        => 'yes',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
			'anonymize_ip' => array(
				'type'         => 'switch',
				'label'        => __( 'Anonymise the IP', 'fw' ),
				'desc'         => __( 'Masks the last octet before storing, using WordPress\'s own anonymiser.', 'fw' ),
				'value'        => 'no',
				'right-choice' => array( 'value' => 'yes', 'label' => __( 'Yes', 'fw' ) ),
				'left-choice'  => array( 'value' => 'no', 'label' => __( 'No', 'fw' ) ),
			),
		),
	),
);
