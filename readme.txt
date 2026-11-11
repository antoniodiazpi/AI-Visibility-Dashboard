=== AI Visibility Dashboard for Mencoro ===
Contributors: trailseo
Tags: ai, seo, geo, chatgpt, dashboard
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See how ChatGPT, Claude, Perplexity and Google AI talk about your brand, right in the WordPress dashboard.

== Description ==

AI Visibility Dashboard adds an **AI visibility** widget to the WordPress Dashboard, fed by your Mencoro project. Generative Engine Optimization (GEO) figures usually live in a separate tool nobody opens; this puts them where site owners, editors and clients already look every day.

**What the widget shows**

* **Share of Voice**: your share of brand mentions in AI answers, against the competitors you track.
* **Mention rate** (Coverage in Mencoro): out of every AI answer checked, how many mention your brand.
* **Average position**: where your brand appears inside the answer.
* **Positivity** (Favorability in Mencoro): tone of the mentions, 0 to 100.
* **Trend**: Share of Voice over the period.
* **You vs competitors**, side by side.
* **Tone of the mentions**: positive, neutral and negative.
* **Sources the AI answers cite**, with your own site flagged.
* **Queries that moved most**: tracked queries gaining and losing Share of Voice.

Every figure shows its change against the previous period of the same length. Switch between 7, 28 and 90 days from the widget. Engines: ChatGPT, Claude, Perplexity, Google AI Overviews and Google AI Mode, whichever your project tracks.

The figures describe how these engines answer the queries you track in Mencoro, in the countries you chose. They are a sample, not every search, and a mention is not a click or a sale. The widget says so next to the numbers.

**Built for agencies**

* Let the client's editors see the widget without access to the settings or the key.
* Define the key in wp-config.php with `define( 'MENCORO_API_KEY', '…' );` to keep it out of the database.

**Light, private and reliable**

* Read-only: the plugin never changes anything in your Mencoro account.
* No JavaScript, no external scripts, nothing added to the front end.
* The Dashboard never waits for Mencoro when it has something to show: out-of-date figures are shown with their age while they update in the background.
* If Mencoro is slow or down, the last figures stay on screen and the plugin waits before trying again.

Spanish (Spain) translation included.

This is an independent plugin built on Mencoro's public API; it is not an official Mencoro product. Source code and documentation: [github.com/antoniodiazpi/AI-Visibility-Dashboard](https://github.com/antoniodiazpi/AI-Visibility-Dashboard).

== External services ==

This plugin connects to the Mencoro API (https://api.mencoro.com), a third-party service, to read the AI visibility data of the project you choose. A Mencoro account and API key are required: [mencoro.com](https://mencoro.com/).

* **What is sent:** your Mencoro API key (in the Authorization header), the organization and project IDs you selected, the date window and the AI engines to report on. Nothing about your site, its content, its users or its visitors is sent.
* **When:** only from the WordPress admin: when an administrator saves or opens the settings screen (to check the key and list the projects), when someone allowed to see the widget opens the Dashboard and the data is out of date (every 6 hours by default), and when someone clicks "Refresh" in the widget.
* **What is stored in WordPress:** the settings, the key (unless it is defined in wp-config.php) and the last figures received. Deleting the plugin removes all of it.

Mencoro terms of use and privacy policy: https://mencoro.com/legal/

The plugin does not contact any other service and collects no usage data.

== Installation ==

1. Install the plugin from Plugins → Add New, or upload the zip, and activate it.
2. In Mencoro, create an API key under API keys in the user menu. The "read" capability is enough.
3. Go to Settings → AI Visibility, paste the key and click "Save and check connection".
4. Choose your project, save, and open the Dashboard.

== Frequently Asked Questions ==

= Do I need a Mencoro account? =

Yes. The plugin shows the data Mencoro collects for your project; it does not query AI engines by itself.

= Does it slow down my site? =

No. It only runs in the admin Dashboard, renders on the server without JavaScript, and keeps the data for 6 hours. When the data is out of date it is updated in the background, so the Dashboard does not wait.

= What happens if Mencoro is down or slow? =

The widget keeps showing the last figures, says how old they are, and tries again later: after the time Mencoro asks for when it limits requests, and after 1, 2, 4… up to 30 minutes on other errors.

= Can my clients see the widget? =

Yes. In Settings → AI Visibility, choose "Editors and administrators" under "Who sees the widget". Editors see the widget but not the settings or the key.

= Why don't I see classic Google results? =

The widget is about answers generated by AI. Classic Google results and Shopping are left out on purpose.

= Does it work on multisite? =

Yes. Each site has its own settings and data. Uninstalling removes the data of every site.

= How do I change how long data is kept fresh? =

Use the `aivd_cache_ttl` filter, in seconds.

== Screenshots ==

1. The AI visibility widget on the Dashboard (sample data).
2. Settings screen with the connection status.
3. The widget on a phone (sample data).

== Changelog ==

= 1.0.1 =
* Reliability: the last valid figures stay on screen when Mencoro is slow or down, with their age; out-of-date data updates in the background instead of making the Dashboard wait.
* Reliability: only one update runs at a time; Mencoro's Retry-After is respected and other errors back off from 1 to 30 minutes.
* Reliability: a slow API can no longer hold the Dashboard: 10-second timeout per request and a time budget per update.
* Security: error messages no longer include text sent by the provider; redirects are not followed; responses are size-limited and checked field by field.
* Security: the API key field is validated; a custom API address must use HTTPS.
* Clearer figures: the period, engines and number of tracked queries are shown next to the numbers, and the cited-sources note no longer overstates what the data shows.
* Settings: connection status and a full description of the external service.
* Uninstall also removes stored figures and scheduled updates on every site.

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.1 =
Keeps your last figures when Mencoro is slow or down, never makes the Dashboard wait when it has data, and hardens how errors and the API key are handled.
