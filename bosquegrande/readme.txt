=== BosqueGrande ===
Contributors: bosquegrande
Tags: crm, events, newsletter, donations, nonprofit
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 5.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A CRM that lives in your WordPress: people, events, newsletters, and money in one plugin. Bring your own keys and contacts.

== Description ==

BosqueGrande is a constituent relationship manager for membership organizations, small nonprofits, and community businesses. It ships with no organization data, no preset regions, and no connected services. You add your organization's details, your own API keys, and your own contacts.

* **People**: encrypted constituent records with timelines, tags, custom fields, consents, duplicate review, CSV import and export, and one-click anonymization. Up to 250,000 records by default, with an adjustable ceiling.
* **Events**: a public calendar, built-in registration with configurable options and optional Stripe payment, reminders, follow-up surveys, and an organizer app with rosters, check-in, walk-ins, cash and check records, and closeouts.
* **Newsletters**: compose in the visual or HTML editor, subscriber groups with double opt-in, one-click unsubscribe with RFC 8058 headers, and a per-recipient send log.
* **Money**: donations or sales (your choice), one-time and monthly giving through Stripe, a typed payment ledger, refunds, and a QuickBooks Online Sales Receipt worksheet with a review queue.
* **Connectors**: optional Little Green Light sync (push, pull, or two-way review) and optional Eventbrite event and registration transfer.
* **Import Studio**: XLSX, CSV, and TSV field mapping with suggestions, encrypted staging, dry runs, and guarded rollback.
* **Grant Reporting**: funder-ready reports with small-group suppression, region breakdowns, and Grant Tags that you define, each with optional matching rules.
* **Assisted Tools**: optional writing, search, classification, and review help through your own provider key (Anthropic, OpenAI, or Gemini). Every feature can be turned off, and individual records never reach a provider.

Personal data is encrypted with XChaCha20-Poly1305 under a keyring file you hold. Lookups use keyed blind indexes, so nothing readable sits in the database.

== Installation ==

1. Upload the plugin ZIP from Plugins > Add New > Upload Plugin, then activate it.
2. Open BosqueGrande > Settings & System Health > Setup and follow the steps: organization details, email sender, data protection, keys, and contacts.
3. Download the keyring backup from System Status and store it somewhere safe and offline. Without it, encrypted records cannot be read.
4. Add your regions and interest tags under Events > Tags & Regions, and your registration options under Settings > Registration.
5. Put the `[bosquegrande]` shortcode on a page to show the calendar, or choose a public pages mode under Wording & Styling.

== Frequently Asked Questions ==

= Does BosqueGrande send data anywhere? =

Only to services you connect with your own keys: Stripe, your mail relay, Little Green Light, Eventbrite, and an assistant provider. Each stays off until you add its key.

= Where is the encryption key? =

In `wp-content/bosquegrande-keyring.php`, which refuses direct web requests. To keep it outside the web root, define `BG_KEYRING_PATH` in wp-config.php before activating the plugin.

= Is there a hosted version? =

A hosted edition runs the same plugin inside a locked-down WordPress with a separate encrypted keyring per organization. Its setup files ship separately.

== Changelog ==

= 5.0.0 =
* First organization-neutral release. All client-specific data, branding, regions, grant presets, and contacts are gone; organization details come from Settings.
* New Organization settings: name, legal name, payee, contact details, logo, and nonprofit or business type.
* Registration options and questions are configurable, including per-event options.
* Event regions and interest tags are an editable vocabulary; postal-code and place rules assign regions.
* Grant Tags carry their own date, tag, and keyword rules.
* QuickBooks Class by region is optional and configurable.
* Monthly giving levels are configurable.
* Neutral default colors and wording; public pages can follow the theme, use the app layout, or take over the whole site.
* Removed the legacy importer, the historical event catalog, and deployment-specific code.
* Choose one of 16 currencies in Setup. The currency locks once any payment is recorded.
* Sign-in, unsubscribe, survey, follow-up, and profile links are signed with separate keys from your keyring, never from WordPress salts.
* Optional sealed keyring format, used by the hosted edition to keep each organization's keys encrypted at rest.
* Multisite: signed links and the link between a WordPress account and a contact record belong to one site, even when sites share a keyring file.
* Deleting the plugin with "Delete all BosqueGrande data" turned on now also removes events, newsletters, and tags.
* Security: a sign-in link is confirmed with a button in the browser that opened it, so mail scanners and other sites cannot use it; organizer sign-in links work only for organizer-only accounts; forms submitted without signing in fill blank answers but never overwrite saved ones; organizer walk-ins attach to a saved person only on a name plus a matching email or phone; attendee lookups and walk-ins are rate-limited and recorded.
* The activity log is trimmed to the last year.
* Tested with WordPress 7.0 and PHP 8.4.

== Upgrade Notice ==

= 5.0.0 =
Finish the encryption migration in 4.1 before upgrading. Links in emails sent before the upgrade (sign-in, unsubscribe, survey) stop working, and everyone signs in again once.
