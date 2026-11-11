# Developers

[Documentation](README.md) › Developers

How the plugin is built, how to extend it and how to contribute.

## Architecture

No build step, no runtime dependencies, no JavaScript. PHP 7.4+, WordPress 6.5+. Composer is only used for development tools (PHPCS, PHPUnit).

```
ai-visibility-dashboard/
├── ai-visibility-dashboard.php   Bootstrap: constants, activation and deactivation, action link
├── includes/
│   ├── class-aivd-settings.php   Stored settings, key resolution, capability, cache flush
│   ├── class-aivd-api.php        Read-only HTTP client for the Mencoro API
│   ├── class-aivd-data.php       Calls the API, shapes the payload, keeps the last valid one, states
│   ├── class-aivd-lock.php       Atomic lock so only one update runs at a time
│   ├── class-aivd-widget.php     Renders the Dashboard widget (server-side HTML + inline SVG)
│   └── class-aivd-admin.php      Settings screen and admin-post handlers
├── assets/admin.css              Loaded only on the Dashboard and the settings screen
├── languages/                    .pot, es_ES .po/.mo/.l10n.php
└── uninstall.php                 Removes options, transients, locks and cron events, multisite aware
```

Prefixes: `aivd_` for functions, options, transients, hooks and actions; `AIVD_` for classes and constants.

### Request flow

```
Dashboard load
  └─ AIVD_Widget::render()
       └─ AIVD_Data::state( $range )
            ├─ fresh snapshot           → render it (status fresh, partial or empty)
            ├─ out-of-date snapshot     → render it with its age (status stale)
            │                             and schedule one WP-Cron event: aivd_background_refresh
            ├─ nothing yet, lock free   → refresh now, 12 s budget (the only wait)
            ├─ nothing yet, lock held   → status pending
            └─ nothing, backing off     → status error, with the next attempt time

refresh (under AIVD_Lock, per context)
  └─ AIVD_Data::build()
       ├─ GET  projects/{id}                 name, domains, brand, competitor names   ┐
       ├─ GET  projects/{id}/available-filters   engines the project tracks           ├ essential
       ├─ GET  projects/{id}/metrics         headline figures, sentiment, competitors ┘
       ├─ GET  projects/{id}/timeseries      Share of Voice per day or week           ┐
       ├─ GET  projects/{id}/cited-sources   top 6 domains, and the total             │ secondary
       ├─ GET  projects/{id}/metrics/movers  top 3 gains  (sortOrder=desc)            │
       └─ GET  projects/{id}/metrics/movers  top 3 losses (sortOrder=asc)             ┘
```

All paths are under `https://api.mencoro.com/api/v1/organizations/{organizationId}/`. Window parameters: `dateFrom`, `dateTo` (UTC, `Y-m-d`), `engines` (comma-separated). The time series uses `granularity=daily`, or `weekly` for 90 days; movers use `sortBy=trend_share_of_voice&limit=3`; sources use `groupBy=domain&limit=6`.

If an essential request fails, the update fails: the previous payload is kept and the error recorded. If a secondary one fails, or does not fit in the time budget, only that section shows a message and the payload is marked partial (`failed_sections`); partial payloads are refreshed again after 15 minutes.

Each request waits 10 s at most (`aivd_http_timeout`), follows no redirects and reads 1 MB at most. Error messages never include the response body.

The settings screen also uses `GET /api/v1/me` (key check on save; the identity it returns is not stored), `GET /api/v1/organizations` and `GET /api/v1/organizations/{id}/projects` (project picker).

Reference: [Mencoro API docs](https://mencoro.com/api-docs/) and the OpenAPI contract in [mencoro/mencoro-api-sdk](https://github.com/mencoro/mencoro-api-sdk).

### Engines

`AIVD_Settings::AI_ENGINES` = `chatgpt`, `claude`, `perplexity`, `google_ai_overview`, `google_ai_mode`. The engines sent are *(ticked in settings, or all)* ∩ *(project's available filters)* ∩ `AI_ENGINES`. `google_serp` and `google_shopping` are never sent.

### Storage and states

| What | Where | Lifetime |
|---|---|---|
| Last valid payload + last attempt (error, failures, next retry) | Option `aivd_snapshot_{md5(org, project, range, engines, wp_hash(key))}`, not autoloaded; index in `aivd_snapshots`; at most 6 | Fresh for 6 h (`aivd_cache_ttl`) and within the same UTC day; 15 min if partial. Kept until replaced or purged. |
| Update lock | Option `aivd_lock_refresh_{context}`, read and written with direct queries (`INSERT IGNORE`) so an object cache cannot hide it | 120 s, or until the update ends |
| Project list (settings) | Transient `aivd_projects_` + md5(cache version, key) | 10 min |

`AIVD_Data::state( $range )` returns `status` (`fresh`, `partial`, `empty`, `stale`, `pending`, `error`), `payload`, `partial`, `fetched_at`, `expires_at`, `error`, `retry_at`, `refreshing` and `cron_late`. `AIVD_Data::peek()` returns the same without fetching or scheduling. `AIVD_Data::get( $range )` is kept for compatibility and returns the last payload or a `WP_Error`.

Backoff after an error: `Retry-After` for 429 (manual refresh included), 15 min for 401/403/404, then 1, 2, 4… up to 30 min for other errors. A manual **Refresh** runs at most once every 30 s per view.

Saving the settings bumps `aivd_cache_version` (project list) and deletes every snapshot and scheduled update, so nothing from a previous key or project is ever shown.

## Hooks and constants

### Filters

```php
// Who sees the widget. Default: 'manage_options', or 'edit_others_posts'
// when "Editors and administrators" is chosen in the settings.
add_filter( 'aivd_widget_capability', function ( $capability ) {
	return 'view_ai_visibility'; // e.g. a custom capability given to a client role.
} );

// How long fetched data stays fresh, in seconds (minimum 60). Default: 6 hours.
add_filter( 'aivd_cache_ttl', function () {
	return 12 * HOUR_IN_SECONDS;
} );

// Seconds to wait for one response (2 to 30). Default: 10.
add_filter( 'aivd_http_timeout', function () {
	return 15;
} );

// Seconds one update may wait in total (minimum 5).
// Default: 12 while someone waits for the page ('interactive'), 25 in WP-Cron ('background').
add_filter( 'aivd_refresh_budget', function ( $budget, $mode ) {
	return 'background' === $mode ? 40 : $budget;
}, 10, 2 );

// API base URL, without trailing slash. Default: https://api.mencoro.com
// Must be HTTPS, or HTTP on localhost / 127.0.0.1 / ::1; anything else falls back to the default.
add_filter( 'aivd_api_base', function () {
	return 'http://127.0.0.1:8898'; // e.g. a local mock while developing.
} );
```

### Constants

| Constant | Effect |
|---|---|
| `MENCORO_API_KEY` | API key. Wins over the `MENCORO_API_KEY` environment variable and the stored key; hides the key field. |
| `AIVD_API_BASE` | API base URL; the `aivd_api_base` filter still runs on top. |
| `AIVD_VERSION`, `AIVD_FILE`, `AIVD_DIR`, `AIVD_URL` | Defined by the plugin. |

### Admin actions

`admin-post.php?action=aivd_save` (settings form, nonce `aivd_save`, `manage_options`) and `admin-post.php?action=aivd_refresh` (nonce `aivd_refresh`, widget capability, rate-limited). The widget period is a read-only `?aivd_range=7|28|90` on the Dashboard URL.

WP-Cron event: `aivd_background_refresh` with the period as its only argument. It is unscheduled on deactivation and uninstall.

## Developing locally

1. Clone the repository into `wp-content/plugins/` of any WordPress 6.5+ install and activate it.
2. Use a real read-only Mencoro key, or point the plugin at a mock with `define( 'AIVD_API_BASE', 'http://127.0.0.1:8898' );` and serve JSON shaped like the OpenAPI responses. The plugin only needs the nine `GET` endpoints listed above.
3. Set `WP_DEBUG` and `WP_DEBUG_LOG` to `true` and keep `wp-content/debug.log` empty of `aivd` notices.

Things worth testing by hand: a new project with no checks (null metrics, empty series), a project tracking only `google_serp`, a 401 key, a 404 project, a 5xx answer, a competitor name containing HTML, a day with no data in the series, and the widget at phone width.

## Coding standards

```bash
phpcs --standard=phpcs.xml.dist
```

The ruleset applies WordPress-Extra, WordPress-Docs, the text domain and the `aivd` prefix. The code is also checked with PHPCompatibility for PHP 7.4+:

```bash
phpcs --standard=PHPCompatibility --runtime-set testVersion 7.4- --extensions=php --ignore=languages .
```

Rules of the house: escape late, sanitize early, nonces and capability checks on every handler, no direct database queries outside `uninstall.php` and the lock (`AIVD_Lock`, which must bypass the options cache), no JavaScript unless it earns its place.

## Translations

Source strings are in English; the text domain is `ai-visibility-dashboard`. With WP-CLI:

```bash
wp i18n make-pot . languages/ai-visibility-dashboard.pot --exclude=languages,docs
# translate languages/ai-visibility-dashboard-{locale}.po, then:
wp i18n make-mo languages
wp i18n make-php languages
```

Commit the `.po`, `.mo` and `.l10n.php` files. Spanish (Spain) uses the informal *tú*, like WordPress core's es_ES.

The plugin does not call `load_plugin_textdomain()`: as recommended for plugins hosted on WordPress.org, WordPress loads the language packs of translate.wordpress.org just in time. The `.po` in `languages/` is the source to import there (Spanish (Spain), project *AI Visibility Dashboard for Mencoro*). On installs from GitHub, the `.mo` and `.l10n.php` files work when copied to `wp-content/languages/plugins/`.

## Tests

See [CONTRIBUTING.md](../../CONTRIBUTING.md): `bin/install-wp-tests.sh` prepares WordPress with the SQLite drop-in, and `composer test` runs PHPUnit against a fake Mencoro API (`pre_http_request`), single site or multisite (`WP_MULTISITE=1`).

## Building a release

Development files (`docs/`, `tests/`, `bin/`, `.github/`, `.wordpress-org/`, the READMEs, Composer and PHPUnit files) are marked `export-ignore` in `.gitattributes`, so the zip GitHub builds for a tag, and `git archive`, contain only the plugin:

```bash
git archive --format=zip --prefix=ai-visibility-dashboard/ -o ai-visibility-dashboard-1.0.1.zip v1.0.1
```

Before tagging: bump `Version` in the plugin header, `AIVD_VERSION`, `Stable tag` in `readme.txt`, add a changelog entry and an upgrade notice, regenerate the translations, and run Plugin Check on the zip.

WordPress.org images (icon, banner, screenshots) live in `.wordpress-org/`. They go in the top-level `assets/` folder of the SVN repository, next to `trunk/` and `tags/`, never inside the plugin.

## Contributing

Issues and pull requests are welcome. Please keep pull requests focused, follow the coding standards above, and describe how you tested. For anything that touches the API key or output escaping, explain the security reasoning in the pull request.
