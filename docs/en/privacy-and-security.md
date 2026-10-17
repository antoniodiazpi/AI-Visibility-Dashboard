# Privacy and security

[Documentation](README.md) › Privacy and security

What the plugin sends, stores and shows — useful for your own peace of mind, for a client's DPO, or for a privacy policy.

## What leaves your site

The plugin talks to **one service: the Mencoro API (`https://api.mencoro.com`)**. Every request is a `GET` (read-only). It sends:

- your API key, in the `Authorization` header;
- the organization and project IDs you picked;
- the date window and the AI engines to report on;
- a `User-Agent` naming the plugin and its version.

It does **not** send your site's URL, content, users, visitors, IP addresses or analytics. Nothing is sent from the public side of your site: the plugin only runs in the admin.

### When requests happen

- When someone allowed to see the widget opens the Dashboard and the cached data has expired (every 6 hours, and at the start of each UTC day).
- When someone clicks **Refresh** or **Try again**.
- When an administrator saves or opens **Settings → AI Visibility** (to check the key and list projects; the project list is cached for 10 minutes).

A full refresh makes 7 requests; listing projects makes one per organization the key can read, plus one.

Mencoro's terms and privacy policy: <https://mencoro.com/legal/>.

## What is stored in WordPress

| Data | Where | Notes |
|---|---|---|
| Settings and the API key | `wp_options`, option `aivd_settings` | Not autoloaded, so it is not loaded on every page. When the key comes from `wp-config.php` or an environment variable, saving the settings deletes any key stored before. |
| Cached results | Transients named `aivd_…` | Aggregated metrics, competitor names, cited domains and tracked query texts. No personal data. Expire on their own. |
| Cache version | `wp_options`, option `aivd_cache_version` | A number bumped by **Refresh**. |

Deleting the plugin from **Plugins** removes all of it, on every site of a multisite network.

## What is shown, and to whom

- The **settings screen** requires `manage_options` (administrators).
- The **widget** requires `manage_options`, or `edit_others_posts` (editors) if you allow it; filterable.
- The **API key is never printed back**, not even in the HTML source: only its last four characters, to recognise it.
- Every value from Mencoro (project and competitor names, domains, query texts) is escaped before output.

## Front end and third-party code

- Nothing is added to the public side of your site: no scripts, styles, cookies or tracking.
- In the admin, the plugin loads one small stylesheet, only on the Dashboard and its own settings screen.
- No JavaScript, no external fonts, no CDN, no third-party libraries.

## Hardening checklist

- Use a **read-only key**, limited to the organization you need.
- **One key per site**, named after it in Mencoro.
- Prefer the **`wp-config.php` constant** on sites other people administer.
- **Revoke** the key in Mencoro when a site is retired or a client leaves.

## Reporting a vulnerability

Please do not open a public issue. Contact the author privately through [trailseo.com](https://trailseo.com/) with the details, and allow time for a fix before disclosure.
