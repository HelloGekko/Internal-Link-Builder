=== Internal Link Builder ===
Contributors: hellogekko
Tags: internal links, seo, automatic linking, interlinking, keywords
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.

== Description ==

Internal Link Builder lets you assign keywords to the posts, pages and terms you
want to *receive* internal links. Whenever those keywords appear in the content
of other posts, the plugin turns them into links to the configured target — on
the fly, without modifying your stored content.

This release ships the settings screen, the keyword index storage and the
per-post keyword configuration. The front-end linking engine follows in a
subsequent release.

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
   point to).
2. The plugin builds an index that maps each keyword to its target.
3. When any other post is rendered, its content is scanned for those keywords
   and matches are replaced with a link to the target, applying all configured
   limits. The original content in the database is never changed.

== Changelog ==

= 0.2.0 =
* Add the keyword index table (built per target) and per-target keyword storage.
* Add the post edit-screen metabox with Keywords and Settings tabs, a
  "keywords not linked in this content" list and a live blacklist overview.
* Enforce the configured minimum role for editing keywords.

= 0.1.0 =
* Initial scaffold: plugin bootstrap, settings storage and the full settings
  screen (General, Content, Links and Actions tabs), admin bar indicator and
  uninstall handling.
