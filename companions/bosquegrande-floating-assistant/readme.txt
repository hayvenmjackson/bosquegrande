=== BosqueGrande Floating Assistant ===
Contributors: bosquegrande
Tags: assistant, chat, faq, nonprofit, crm
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: bosquegrande
Stable tag: 5.0.0
License: MIT
License URI: https://opensource.org/licenses/MIT

A question button on your public pages, answered by BosqueGrande's assistant from your own training info, with your own service provider key.

== Description ==

Visitors click the button and ask a question. BosqueGrande's assistant answers from your site's public facts (organization name, upcoming events) and from the training info you add. When those do not answer the question, it says so and forwards the question to your designated contact instead of guessing.

= Assistant Training =

Under BosqueGrande > Assistant Training, add your organization's own reference material: frequently asked questions, hours and locations, programs, prices, policies, how to register or volunteer.

* Upload Word (.docx), OpenDocument (.odt), PDF, plain text, Markdown, CSV, or HTML files, or paste text. Only the text is kept.
* Mark each entry "Visitors and staff" or "Staff only". Staff-only entries are used by the staff Assistant in the dashboard and never reach visitors.
* Mark short entries "Always include" so every answer knows them (hours, contact details).
* Edit the text after upload to fix anything the conversion got wrong.
* "Test a question" shows exactly which passages a question would send, without contacting your provider.

Only the passages that match a question are sent with it, within a fixed size, so a large library does not make every question expensive.

= Your own key =

Answers use the service provider key (Anthropic, OpenAI, or Google Gemini) you enter in BosqueGrande > Assisted Tools, billed to your own account. The Floating Assistant screen sets limits per visitor and per day. Turn the "Visitor assistant" feature on or off in Assisted Tools.

= Privacy =

The assistant never sees contact records. Training info is stored in your site's database like pages and posts, and matching passages are sent to your provider with each question, so keep personal information out of it. The screen warns you when an upload contains email addresses or phone numbers.

== Installation ==

1. Install and set up BosqueGrande 5.0 or later, including a service provider key in Assisted Tools.
2. Upload this plugin's ZIP from Plugins > Add New > Upload Plugin and activate it.
3. Add training info under BosqueGrande > Assistant Training.
4. Adjust the button under BosqueGrande > Floating Assistant, and its wording in Wording & Styling.

== Frequently Asked Questions ==

= The button does not appear =

The Floating Assistant screen says why: BosqueGrande inactive, Assisted Tools off, the Visitor assistant feature off, or no provider key saved.

= Why was my PDF refused? =

Scanned PDFs are pictures of text, and some PDFs use font encodings that cannot be turned back into letters. Open the PDF, copy the text, and paste it instead.

== Changelog ==

= 5.0.0 =
* New: Assistant Training, with uploads, audiences, always-included entries, editing, and a passage preview.
* The button follows BosqueGrande's main color or your own, with contrast-checked text, and its wording is editable in Wording & Styling.
* Per-visitor and daily limits to keep provider costs predictable.
* Visitor questions no longer receive the internal staff reference.
* Works for any organization: no built-in names, colors, or addresses.
