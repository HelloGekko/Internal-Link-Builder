# Releasing updates

Internal Link Builder is distributed outside wordpress.org, so it ships its own
updater (`includes/class-ilb-updater.php`). Installed sites check a JSON
**manifest** you host; when the manifest advertises a higher version, WordPress
shows the update in **Plugins → Installed Plugins** and updates it like any
other plugin. There is no license check — anyone with the plugin gets updates.

## One-time setup on your server

Pick a public URL for the manifest and a location for the zips, e.g.:

- Manifest: `https://hellogekko.nl/updates/internal-link-builder.json`
- Zips:     `https://hellogekko.nl/updates/internal-link-builder-<version>.zip`

The default manifest URL is `https://hellogekko.nl/updates/internal-link-builder.json`.
To use a different URL, either define it in `wp-config.php`:

```php
define( 'ILB_UPDATE_URL', 'https://example.com/path/internal-link-builder.json' );
```

or filter it:

```php
add_filter( 'ilb_update_manifest_url', function () {
    return 'https://example.com/path/internal-link-builder.json';
} );
```

## Releasing a new version

1. Make your changes and bump the version in **two** places:
   - the `Version:` header in `internal-link-builder.php`
   - `define( 'ILB_VERSION', '...' )`
   - (and `Stable tag:` in `readme.txt`)
2. Build the distributable zip:
   ```bash
   bin/build-zip.sh
   ```
   This creates `dist/internal-link-builder-<version>.zip` with only the runtime
   files (tests, CI config, composer, etc. are excluded).
3. Upload that zip to your update host.
4. Update the manifest JSON (below) with the new `version`, `download_url`,
   `last_updated` and changelog, and upload it.

That is all — sites will offer the update within ~12 hours (WordPress's normal
update-check interval), or immediately when an admin visits the Plugins/Updates
screen.

## Manifest format

```json
{
    "name": "Internal Link Builder",
    "version": "0.12.0",
    "author": "HelloGekko",
    "homepage": "https://hellogekko.nl/internal-link-builder",
    "requires": "5.8",
    "tested": "6.5",
    "requires_php": "7.4",
    "last_updated": "2026-07-04 12:00:00",
    "download_url": "https://hellogekko.nl/updates/internal-link-builder-0.12.0.zip",
    "sections": {
        "description": "Automatically generates internal links in the front-end.",
        "changelog": "<h4>0.12.0</h4><ul><li>Self-hosted updates.</li></ul>"
    }
}
```

Only `version` and `download_url` are strictly required; the rest populate the
plugin's "View details" popup. A ready-to-edit copy lives in
`dist/manifest.example.json`.
