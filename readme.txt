=== Internal Link Builder ===
Contributors: hellogekko
Tags: internal links, seo, automatic linking, interlinking, keywords
Requires at least: 5.8
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Automatically generates internal links in the front-end based on keywords configured on target posts, pages and terms.

== Description ==

Internal Link Builder lets you assign keywords to the posts, pages and terms you
want to *receive* internal links. Whenever those keywords appear in the content
of other posts, the plugin turns them into links to the configured target — on
the fly, without modifying your stored content.

The plugin processes the final rendered HTML of each page, so keywords are
linked no matter how the content is produced — the block editor, the classic
editor, page builders, ACF fields, shortcodes or widgets. Only the content
region is processed; navigation, header, footer and forms are never touched.

= Settings overview =

* **General** — data retention on uninstall, admin bar indicator, Action
  Scheduler batch size, minimum role for editing keywords, and index generation
  mode.
* **Content** — the content region, post type / taxonomy whitelists, post /
  term blacklists, keyword ordering, link limits (per post, per paragraph,
  incoming, frequency), case sensitivity, excluded HTML areas and limiting
  taxonomies.
* **Links** — the output template (with {{url}}, {{anchor}}, {{excerpt}} and
  {{title}} placeholders) and the nofollow toggle.
* **Actions** — index status with progress, cancel pending schedules and a
  collation repair tool.

== How linking works ==

1. You configure keywords on a *target* (the post/page/term you want links to
   point to), via the post metabox or the term edit screen.
2. The plugin builds an index that maps each keyword to its target.
3. When any other page is rendered, the plugin scans the content region of the
   finished page for those keywords and replaces matches with a link to the
   target, applying all configured limits. The original content in the
   database is never changed.

== Changelog ==

= 0.14.0 =
* New admin-only linking diagnostics. Append ?ilb-debug=1 to any front-end URL
  while logged in as an administrator and the page source ends with an
  "Internal Link Builder debug" HTML comment reporting which content region was
  matched (and whether it fell back to <body>), how many keywords are in the
  index, how many matched on the page, how many links were placed and the
  active link limits — so you can see exactly why keywords are or aren't linked.

= 0.13.1 =
* Recognise Elementor's post-excerpt widget (.elementor-widget-theme-post-excerpt)
  as an excerpt container, so excerpts built with Elementor are excluded from
  linking when the "Excerpts" area is turned off. Still filterable via
  ilb_excerpt_classes.

= 0.13.0 =
* New "Daily" index generation mode (now the default): keywords are still
  linked live the moment you save, but the heavy link-graph statistics rebuild
  runs just once a day (via WP-Cron) and whenever you press "Generate now" —
  no rebuild on every content or keyword change. This keeps busy sites fast
  while adding lots of pages. "Automatic" (rebuild on every change) and "None"
  remain available.
* The keyword index that drives live linking is now kept current on save in
  every mode except "None", so switching away from "Automatic" never stops
  links from working.

= 0.12.1 =
* Fix: keywords whose target page also appears in the navigation menu are now
  linked again. On themes without a dedicated content wrapper (no <main>,
  #content or #primary) the content region falls back to the whole <body>, so
  the "consider existing links" check was picking up menu, header and footer
  links and treating the target as "already linked". The existing-link scan now
  ignores anchors in page chrome, matching where links are actually placed.

= 0.12.0 =
* Self-hosted updates: the plugin now checks a JSON manifest you host and
  offers updates in wp-admin like any other plugin (no wordpress.org, no
  license check). Configure the manifest URL with the ILB_UPDATE_URL constant
  or the ilb_update_manifest_url filter; adds an Update URI header so a
  colliding wordpress.org slug can never hijack updates. See UPDATES.md.
* Add bin/build-zip.sh and .gitattributes to produce a clean distributable zip.

= 0.11.2 =
* Performance: pages are no longer buffered or parsed when no keywords are
  configured, so installing the plugin without setting keywords adds no
  front-end overhead.

= 0.11.1 =
* Add "Excerpts" to the excludable HTML areas. Because excerpts have no
  dedicated tag, they are matched by their container classes (.entry-summary,
  .excerpt, .post-excerpt, .entry-excerpt), filterable via ilb_excerpt_classes.

= 0.11.0 =
* Simplification: whole-page processing is now THE way the plugin works — no
  processing-mode choice, no separate ACF or custom-field options. Keywords are
  linked in the rendered content region of every page, whatever produced it.
* Removed: the Standard/Universal mode selector, the ACF integration settings,
  the custom-field meta linking settings and the output-cache toggle (page
  caching plugins cover caching). The "Content region" setting remains for
  themes where auto-detection needs a hint.
* Existing keyword configuration and all limits keep working unchanged.

= 0.10.0 =
* New "Universal" processing mode: the plugin buffers the final rendered HTML
  of each page and links keywords in the content region, regardless of how the
  content is produced — page builders, ACF, shortcodes, widgets or custom
  templates.
* The content region is auto-detected (main, [role=main], #main, #content,
  #primary, then body) and can be overridden with simple selectors; navigation,
  header, footer, forms and other page chrome are never linked.
* Works on singular pages and term archives; keeps the page's doctype and
  UTF-8 output intact; falls back to the untouched page on any parsing issue.
* Standard mode remains the default; in Universal mode the per-source content
  filters are disabled to avoid double processing.

= 0.9.0 =
* Native Advanced Custom Fields integration: link keywords inside ACF field
  values via ACF's own acf/format_value filters, selected by field TYPE (text,
  textarea, WYSIWYG) — fields inside repeaters, groups and flexible content
  work automatically, on posts and terms.
* New Content-tab settings: "Link keywords in ACF fields" toggle and the field
  types to link (default: textarea and WYSIWYG). Per-field opt-out via the
  ilb_acf_link_field filter.
* New public engine entry point (link_source_content) for integrations, with
  the same eligibility checks and limits as regular content.

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
