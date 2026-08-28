<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$manifest = array();

$manifest['name']        = __( 'Newsletter / Subscriber CRM', 'fw' );
$manifest['slug']        = 'unysonplus-newsletter-crm';
$manifest['description'] = __(
	'Stores and manages the people who sign up through the [newsletter] element. Adds a Subscribers screen (search, filter, sort, bulk actions, manual add, CSV import & export), lists and tags, and saved segments. Built on a repository + service + provider architecture, so email-service integrations (Mailchimp, MailerLite, Brevo) drop in later as add-ons without touching the core. Works even without the shortcodes extension — import a CSV or post to the REST endpoint.',
	'fw'
);

/**
 * Changelog ----------------------------------------------------------------
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

$manifest['version']    = '1.0.29';
$manifest['display']    = true;
$manifest['standalone'] = true;
$manifest['thumbnail']  = 'thumbnail.svg';

// No hard requirements on purpose: the capture hook simply never fires when the
// shortcodes extension is off, and the store is still useful for imports + REST.

// Repository Info
$manifest['github_update'] = 'UnysonPlus/UnysonPlus-Newsletter-CRM-Extension';
$manifest['github_repo']   = 'https://github.com/UnysonPlus/UnysonPlus-Newsletter-CRM-Extension';
$manifest['github_branch'] = 'master';

// Author Info
$manifest['author']     = 'UnysonPlus';
$manifest['author_uri'] = 'https://www.lastimosa.com.ph/unysonplus';

// Meta
$manifest['license']      = 'GPL-2.0-or-later';
$manifest['text_domain']  = 'fw';
$manifest['requires_php'] = '7.4';
$manifest['requires_wp']  = '5.8';
