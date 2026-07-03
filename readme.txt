=== Internal Link Builder ===
Contributors: hellogekko
Tags: internal links, seo, automatic linking, interlinking, keywords
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.8.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.

== Description ==

Internal Link Builder lets you assign keywords to the posts, pages and terms you
want to *receive* internal links. Whenever those keywords appear in the content
of other posts, the plugin turns them into links to the configured target — on
the fly, without modifying your stored content.

The plugin includes the settings screen, keyword index, per-post and per-term
keyword configuration, the front-end linking engine, a batched index generator
with a progress indicator, and an optional advanced custom-field linking mode.

= Settings overview =

* **General** — data retention on uninstall, admin bar indicator, Action
  Scheduler batch size, minimum role for editing keywords, and index generation
  mode.
* **Content** — post type / taxonomy whitelists, post / term blacklists, keyword
  ordering, link limits (per post, per paragraph, incoming, frequency), case
  sensitivity, excluded HTML areas, limiting taxonomies and custom fields.
* **Links** — the output template (with {{url}}, {{anchor}}, {{excerpt}} and
  {{title}} placeholders) and the nofollow toggle.
* **Actions** — output cache, cancel pending schedules and a collation repair
  tool.

== How linking works ==

1. You configure keywords on a *target* (the post/page/term you want links to
   point to), via the post metabox or the term edit screen.
2. The plugin builds an index that maps each keyword to its target.
3. When any other post is rendered, its content is scanned for those keywords
   and matches are replaced with a link to the target, applying all configured
   limits. The original content in the database is never changed.

== Changelog ==

= 0.8.1 =
* Code quality: the full codebase now passes WordPress Coding Standards (PHPCS)
  with zero errors and warnings, and the PHPCS check in CI is blocking.
* Fix two phpcs:ignore comments that referenced a wrong sniff name, so the
  intended sanitization annotations are actually honoured.
* Rename a reserved-keyword parameter and merge unnecessary string concats.

= 0.8.0 =
* Add a schema-version upgrade routine so plugin updates create/upgrade tables
  without a manual reactivate; create tables per site on multisite.
* Custom-field linking is now an explicit opt-in ("Enable custom field linking")
  with a clear warning, off by default.
* Generator renders blocks (do_blocks) so the link graph matches the front end.
* Performance: the keyword candidate map is cached per index change instead of
  being rebuilt on every render and every source during generation.
* Add an ilb_should_link_content filter for theme/FSE overrides; validate
  collation identifiers; minor cleanup.
* Add a PHPUnit test suite, PHPCS (WordPress) config and a GitHub Actions CI
  workflow.

= 0.7.0 =
* Fix: saving one settings tab no longer wipes the others. Settings are now
  merged per tab, so token/array fields on other tabs keep their values.
* Add an "Index status" panel on the Actions tab with live keyword/link counts
  and a "Generate / rebuild index now" button that runs in the browser with a
  progress bar (works regardless of Action Scheduler or cron availability).

= 0.6.0 =
* Replace the post-type, taxonomy, post and term selection fields with a
  searchable token / chip control: type a name to find and add posts or terms
  (showing "Title (ID: n)"), and type-ahead chips for post types and taxonomies.
* Custom-field settings are now free-form tag inputs.
* Add admin-ajax search endpoints for posts and terms (capability and nonce
  protected).

= 0.5.0 =
* Add per-term keyword configuration on the add/edit screens of whitelisted
  taxonomies (keywords, overrides and content blacklist).
* Terms now act as link sources too: their descriptions are linked on the
  front end and processed by the generator.
* Link selected post and term custom fields on display (only the configured
  meta keys, front-end only, with an ilb_link_custom_fields filter to disable).
* Apply the per-target overrides "limit outgoing links" (source) and
  "limit links per paragraph" (target) in the engine.

= 0.4.0 =
* Add the link graph / statistics table (wp_ilb_links) and a batched generator
  that rebuilds it, using Action Scheduler when available and WP-Cron otherwise.
* The batch size and "Index generation mode" settings are now functional; in
  automatic mode content, keyword and settings changes schedule a debounced
  rebuild.
* Enforce the global incoming-link limit in the front-end engine, reading
  counts from the link graph.
* "Cancel schedules" now cancels pending generation work; "Fix collations"
  aligns the plugin tables to the database's default collation.

= 0.3.0 =
* Add the front-end linking engine (the_content filter, DOMDocument based) that
  turns keywords into links to their targets on the fly.
* Whole-word, optionally case-sensitive matching that uses the found keyword as
  the anchor text.
* Enforce link limits (per post, per paragraph, per-target frequency), keyword
  ordering, excluded HTML areas, existing-link awareness, limiting taxonomies
  and the "link as often as possible" override.
* Render links through the configurable template with optional nofollow.
* Cache generated output per post, invalidated on content or index changes.

= 0.2.0 =
* Add the keyword index table (built per target) and per-target keyword storage.
* Add the post edit-screen metabox with Keywords and Settings tabs, a
  "keywords not linked in this content" list and a live blacklist overview.
* Enforce the configured minimum role for editing keywords.

= 0.1.0 =
* Initial scaffold: plugin bootstrap, settings storage and the full settings
  screen (General, Content, Links and Actions tabs), admin bar indicator and
  uninstall handling.
