# Troubleshooting

[Documentation](README.md) › Troubleshooting

Every message the plugin can show, what causes it and how to fix it. Messages appear on the settings screen after saving, or in red inside the widget.

## Messages on the settings screen

| Message | Cause | Fix |
|---|---|---|
| *Mencoro rejected the API key. Check that it is complete and has not been revoked.* | The key is wrong, incomplete or revoked (HTTP 401). | Paste it again. If in doubt, create a new key in Mencoro and revoke the old one. |
| *This API key cannot read this data. Give it the "read" capability and access to the organization.* | The key is valid but not allowed to read this organization or project (HTTP 403). | In Mencoro, edit the key's scope, or create a key with access to that organization. |
| *This key cannot see any active project yet. Create one in Mencoro and come back.* | The key works but sees no active project. | Create a project in Mencoro, or give the key access to the organization that has one. Archived projects are not listed. |
| *Save your API key first to list your projects.* | No key yet. | Paste and save the key. |
| *Could not reach Mencoro: …* | WordPress could not connect to `api.mencoro.com` (network, DNS, firewall, SSL). | See [Connection problems](#connection-problems). |

## Messages inside the widget

| Message | Cause | Fix |
|---|---|---|
| *Mencoro could not find this project with this key. Choose the project again in the settings.* | The project was archived or deleted, or the key changed to one from another account (HTTP 404). | Go to **Settings → AI Visibility** and pick the project again. |
| *Too many requests to Mencoro. Try again in … seconds.* | Mencoro is rate limiting (HTTP 429). | Wait the time shown and click **Try again**. If it repeats, check that nothing else is using the same key heavily. |
| *Mencoro answered with an error (HTTP …).* | An error on Mencoro's side (usually 5xx). | Try again later. If it persists, check Mencoro's status or contact their support. |
| *Mencoro sent a response the plugin could not read.* | The response was not valid JSON — often a proxy or firewall answering instead of Mencoro. | See [Connection problems](#connection-problems). |
| *Add your Mencoro API key in the settings.* | The key is missing (for example, a removed `wp-config.php` constant). | Add the key again. |

After fixing the cause, click **Try again** in the widget. Errors are remembered for **5 minutes** so a broken key does not hit Mencoro on every page load; **Try again** clears that.

## The widget does not appear

- **You are not allowed to see it.** By default only administrators see it. See [For agencies](agencies.md#show-the-widget-to-the-client-not-the-key).
- **It is hidden.** Open **Screen Options** at the top right of the Dashboard and tick **AI visibility**.
- **It is collapsed.** Click its title bar.

## The numbers look different from Mencoro

Check, in this order:

1. **Engines.** The widget only counts AI engines, never classic Google results or Shopping, and only the engines ticked in the settings. Mencoro's own views may include more.
2. **Period.** "28 days" is the last 28 UTC days, today included, compared with the 28 days before.
3. **Cache.** The widget may be up to 6 hours behind. Click **Refresh**.
4. **Recalculation.** If the widget says *Mencoro is recalculating part of this period*, the figures are still settling on both sides.

## The figures do not change from day to day

Mencoro checks queries on the schedule set in your project, usually once a day, and some days nothing moves much. Short periods (7 days) are noisier; long ones (90 days) show the trend.

## Connection problems

*Could not reach Mencoro* and *could not read* errors come from the server running WordPress, not from your browser:

- **Outgoing requests blocked.** Some hosts or security plugins block outgoing HTTP requests (`WP_HTTP_BLOCK_EXTERNAL`). Allow `api.mencoro.com` with `WP_ACCESSIBLE_HOSTS`, or ask your host.
- **Outdated SSL certificates or PHP cURL** on the server. **Tools → Site Health** usually flags this.
- **A proxy in front of the server** that needs configuring in WordPress (`WP_PROXY_HOST` and related constants).

## Still stuck?

[Open an issue](https://github.com/antoniodiazpi/AI-Visibility-Dashboard/issues) with your WordPress and PHP versions, the exact message, and what you already tried. **Never paste your API key.**
