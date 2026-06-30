=== Internal Link Builder ===
Contributors: hellogekko
Tags: internal links, seo, automatic linking, interlinking, keywords
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.6.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.

== Description ==

Internal Link Builder lets you assign keywords to the posts, pages and terms you
want to *receive* internal links. Whenever those keywords appear in the content
of other posts, the plugin turns them into links to the configured target — on
the fly, without modifying your stored content.

This release ships the settings screen, the keyword index storage, the per-post
keyword configuration and the front-end linking engine.

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
