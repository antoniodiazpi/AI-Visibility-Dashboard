# Developers

[Documentation](README.md) › Developers

How the plugin is built, how to extend it and how to contribute.

## Architecture

No build step, no Composer dependencies, no JavaScript. PHP 7.4+, WordPress 6.5+.

```
ai-visibility-dashboard/
├── ai-visibility-dashboard.php   Bootstrap: constants, text domain, activation, action link
├── includes/
│   ├── class-aivd-settings.php   Stored settings, key resolution, capability, cache versioning
│   ├── class-aivd-api.php        Read-only HTTP client for the Mencoro API
│   ├── class-aivd-data.php       Calls the API, shapes the payload, caches it
│   ├── class-aivd-widget.php     Renders the Dashboard widget (server-side HTML + inline SVG)
│   └── class-aivd-admin.php      Settings screen and admin-post handlers
├── assets/admin.css              Loaded only on the Dashboard and the settings screen
├── languages/                    .pot, es_ES .po/.mo/.l10n.php
└── uninstall.php                 Removes options and transients, multisite aware
```

Prefixes: `aivd_` for functions, options, transients, hooks and actions; `AIVD_` for classes and constants.

### Request flow

```
Dashboard load
  └─ AIVD_Widget::render()
       └─ AIVD_Data::get( $range )
            ├─ cache hit  → payload (or a remembered error)
            └─ cache miss → AIVD_Data::build()
                 ├─ GET  projects/{id}                 name, domains, brand, competitor names
                 ├─ GET  projects/{id}/available-filters   engines the project tracks
                 ├─ GET  projects/{id}/metrics         headline figures, sentiment, competitor SoV
                 ├─ GET  projects/{id}/timeseries      Share of Voice per day or week
                 ├─ GET  projects/{id}/cited-sources   top 6 domains
                 ├─ GET  projects/{id}/metrics/movers  top 3 gains  (sortOrder=desc)
                 └─ GET  projects/{id}/metrics/movers  top 3 losses (sortOrder=asc)
```

All paths are under `https://api.mencoro.com/api/v1/organizations/{organizationId}/`. Window parameters: `dateFrom`, `dateTo` (UTC, `Y-m-d`), `engines` (comma-separated). The time series uses `granularity=daily`, or `weekly` for 90 days; movers use `sortBy=trend_share_of_voice&limit=3`; sources use `groupBy=domain&limit=6`.

If `projects/{id}`, `available-filters` or `metrics` fail, the whole widget shows the error. If the time series, sources or movers fail, only that section shows it.

The settings screen also uses `GET /api/v1/me` (key check on save), `GET /api/v1/organizations` and `GET /api/v1/organizations/{id}/projects` (project picker).

Reference: [Mencoro API docs](https://mencoro.com/api-docs/) and the OpenAPI contract in [mencoro/mencoro-api-sdk](https://github.com/mencoro/mencoro-api-sdk).

### Engines

`AIVD_Settings::AI_ENGINES` = `chatgpt`, `claude`, `perplexity`, `google_ai_overview`, `google_ai_mode`. The engines sent are *(ticked in settings, or all)* ∩ *(project's available filters)* ∩ `AI_ENGINES`. `google_serp` and `google_shopping` are never sent.

### Caching

| What | Key | Lifetime |
|---|---|---|
| Widget payload | `aivd_` + md5(cache version, org, project, range, engines, md5(key), UTC date) | 6 h (`aivd_cache_ttl`) |
| Failed fetch | same key | 5 min |
| Project list (settings) | `aivd_projects_` + md5(cache version, key) | 10 min |

**Refresh** and saving the settings bump the `aivd_cache_version` option instead of deleting transients one by one, so invalidation also works with persistent object caches. Old entries simply expire.

## Hooks and constants

### Filters

```php
// Who sees the widget. Default: 'manage_options', or 'edit_others_posts'
// when "Editors and administrators" is chosen in the settings.
add_filter( 'aivd_widget_capability', function ( $capability ) {
	return 'view_ai_visibility'; // e.g. a custom capability given to a client role.
} );

// How long fetched data is cached, in seconds (minimum 60). Default: 6 hours.
add_filter( 'aivd_cache_ttl', function () {
	return 12 * HOUR_IN_SECONDS;
} );

// API base URL, without trailing slash. Default: https://api.mencoro.com
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

`admin-post.php?action=aivd_save` (settings form, nonce `aivd_save`, `manage_options`) and `admin-post.php?action=aivd_refresh` (nonce `aivd_refresh`, widget capability). The widget period is a read-only `?aivd_range=7|28|90` on the Dashboard URL.

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

Rules of the house: escape late, sanitize early, nonces and capability checks on every handler, no direct database queries outside `uninstall.php`, no JavaScript unless it earns its place.

## Translations

Source strings are in English; the text domain is `ai-visibility-dashboard`. With WP-CLI:

```bash
wp i18n make-pot . languages/ai-visibility-dashboard.pot --exclude=languages,docs
# translate languages/ai-visibility-dashboard-{locale}.po, then:
wp i18n make-mo languages
wp i18n make-php languages
```

Commit the `.po`, `.mo` and `.l10n.php` files. Spanish (Spain) uses the informal *tú*, like WordPress core's es_ES.

## Building a release

Development files (`docs/`, the READMEs, `phpcs.xml.dist`, git files) are marked `export-ignore` in `.gitattributes`, so the zip GitHub builds for a tag, and `git archive`, contain only the plugin:

```bash
git archive --format=zip --prefix=ai-visibility-dashboard/ -o ai-visibility-dashboard-1.0.0.zip v1.0.0
```

Before tagging: bump `Version` in the plugin header, `AIVD_VERSION`, `Stable tag` in `readme.txt`, and add a changelog entry.

## Contributing

Issues and pull requests are welcome. Please keep pull requests focused, follow the coding standards above, and describe how you tested. For anything that touches the API key or output escaping, explain the security reasoning in the pull request.
