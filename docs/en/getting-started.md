# Getting started

[Documentation](README.md) › Getting started

From zero to the widget on your Dashboard in about five minutes.

## What you need

- **WordPress 6.5 or later** and **PHP 7.4 or later**.
- **A Mencoro account with a project.** The plugin shows the data Mencoro collects; it does not query any AI engine by itself. The project must track at least one AI engine: ChatGPT, Claude, Perplexity, Google AI Overviews or Google AI Mode.
- **A Mencoro API key.** See step 1.
- An administrator account in WordPress.

## 1. Create a Mencoro API key

1. Sign in to Mencoro.
2. Open the user menu and go to **API keys**.
3. Create a key. The **`read`** capability is enough: the plugin never creates, changes or deletes anything in Mencoro. If you can limit the key to some organizations, give it access to the one that holds your project.
4. Copy the key and keep it somewhere safe. **Mencoro shows it only once**; if you lose it, revoke it and create another.

> 💡 One key per site is a good habit. If a site is compromised or a client leaves, you revoke that key and nothing else breaks.

## 2. Install the plugin

**From the release zip (recommended)**

1. Download `ai-visibility-dashboard-x.y.z.zip` from the [latest release](https://github.com/antoniodiazpi/AI-Visibility-Dashboard/releases/latest).
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the zip and click **Install Now**.
3. Click **Activate**.

**From the repository**

```bash
cd wp-content/plugins
git clone https://github.com/antoniodiazpi/AI-Visibility-Dashboard.git ai-visibility-dashboard
```

Then activate it under **Plugins**. The folder name does not matter to the plugin, but `ai-visibility-dashboard` keeps things tidy.

## 3. Connect your key

1. Go to **Settings → AI Visibility**. (There is also a **Settings** link next to the plugin on the Plugins screen.)
2. Paste the key into **API key**.
3. Click **Save and check connection**.

The plugin calls Mencoro straight away to check the key:

- ✅ *Key saved and working. Now choose the project to show.* — go on to step 4.
- ❌ An error message — see [Troubleshooting](troubleshooting.md#messages-on-the-settings-screen).

> 🔒 On client or shared sites, prefer putting the key in `wp-config.php` instead of the database. See [For agencies](agencies.md#keep-the-key-out-of-the-database).

## 4. Choose what to show

After a working key is saved, the second part of the screen fills in.

| Setting | What it does |
|---|---|
| **Project** | Every active project the key can read, grouped by organization. If there is only one, it is preselected. |
| **AI engines** | Which engines count. All ticked means "all the AI engines the project tracks", including ones added later. Engines the project does not track are ignored. Classic Google results and Shopping are always left out. |
| **Default period** | 7, 28 or 90 days. Anyone can switch period from the widget; this is what it opens with. |
| **Who sees the widget** | *Administrators*, or *Editors and administrators*. Only administrators ever see the settings and the key. |

Click **Save and check connection** again. You should see *Settings saved. The widget is ready on your Dashboard.*

## 5. Open the Dashboard

Go to **Dashboard**. The **AI visibility** widget is there; you can drag it to another column or collapse it like any other widget.

The first load makes a few requests to Mencoro (about a second); after that the data is cached for 6 hours. To understand each figure and what to do with it, read [Reading the widget](reading-the-widget.md).

## Updating

Install the new release zip over the old one (WordPress offers to replace it), or `git pull` in the plugin folder. Settings and the key are kept.

## Uninstalling

Deactivate and delete the plugin from **Plugins**. Deleting it removes the settings, the stored key and every cached result. If you defined the key in `wp-config.php`, remove that line yourself. Remember to revoke the key in Mencoro if you no longer need it.
