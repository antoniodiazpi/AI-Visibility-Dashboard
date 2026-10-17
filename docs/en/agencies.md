# For agencies

[Documentation](README.md) › For agencies

The plugin was designed for a common agency situation: you track a client's AI visibility in Mencoro, and the client lives in their WordPress admin. Here is how to set it up cleanly.

## Show the widget to the client, not the key

In **Settings → AI Visibility → Who sees the widget**, choose **Editors and administrators**.

| Role | Sees the widget | Sees the settings and the key |
|---|---|---|
| Administrator | ✅ | ✅ |
| Editor | ✅ with this option | ❌ never |
| Author, Contributor, Subscriber | ❌ | ❌ |

Editors also get the **Refresh** link; the **Settings** link is only shown to administrators. If the client's main user is an administrator, they can see the settings screen, but never the key itself: only its last four characters are ever printed.

Need another rule (a custom role, a specific capability)? Use the [`aivd_widget_capability` filter](developers.md#filters).

## Keep the key out of the database

Define the key in `wp-config.php`, above the line that says *That's all, stop editing!*:

```php
define( 'MENCORO_API_KEY', 'mencoro_sk_…' );
```

When the constant exists:

- It wins over any key stored in the settings.
- The key field disappears from the settings screen, replaced by *Defined in wp-config.php with the MENCORO_API_KEY constant (ends in …)*.
- Nobody can change or remove it from the WordPress admin, and it does not travel with database exports or backups of the database.
- If a key had been saved in the settings before, click **Save and check connection** once: the stored copy is deleted.

A `MENCORO_API_KEY` **environment variable** works the same way, for hosts that manage secrets as environment variables. The constant takes precedence over the variable, and both over the stored key.

## One key or many?

A Mencoro key can read several organizations. In the plugin, the project picker groups projects by organization, so the same key could serve many sites. Still, **one key per client site** is safer:

- Revoking one site's key does not break the others.
- A key can be limited to the client's organization, so a compromised site exposes only that client's data.
- Mencoro's API key list tells you which site is which.

## Several sites, one Mencoro project

Nothing prevents several WordPress sites (a brand's blog and its shop, say) from showing the same project. Each site caches separately, so each refresh costs its own requests.

## Multisite

Settings are per site. On a network, each site connects its own key and project, or you define `MENCORO_API_KEY` once in `wp-config.php` and each site only picks its project. Uninstalling from the network removes the plugin data from every site.

## A routine that works

1. Create the client's project in Mencoro, with their competitors and the prompts that matter to them.
2. Create a read-only key limited to the client's organization, named after the site.
3. Install the plugin on the client's site, define the key in `wp-config.php`, pick the project, choose *Editors and administrators*.
4. Walk the client through [Reading the widget](reading-the-widget.md) once. The glossary inside the widget covers the rest.
5. In your monthly report, link to the same numbers they see every day.
