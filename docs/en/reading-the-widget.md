# Reading the widget

[Documentation](README.md) › Reading the widget

<img src="../widget.png" alt="The AI visibility widget with sample data" width="420" align="right">

The widget answers one question: **when people ask AI assistants about your topics, does your brand show up, where, and how well?** This page explains every part of it, where each number comes from and what you can do about it.

All figures come from your Mencoro project, for the AI engines listed under the project name and the period you pick.

<br clear="right">

## Header

- **Project name** and, below it, **the AI engines included**: the ones ticked in the settings that the project actually tracks.
- **7 d · 28 d · 90 d** switches the period. The choice only changes your current view; the default lives in the settings.

**How the period works.** "28 days" means the last 28 UTC days, today included. Every trend compares that window with the 28 days just before it. Over 90 days the chart groups data by week; otherwise by day.

## The four headline figures

Each tile shows the value and its change against the previous period of the same length:

- **▲ green**: better. **▼ red**: worse. **= no change**.
- *no previous period*: Mencoro has no data for the earlier window yet (typical for new projects).
- `pp` means **percentage points**: from 30% to 33% is +3 pp, not +10%.

Hover a tile for a one-line definition.

### Share of Voice

Your share of the brand mentions in AI answers, against the competitors tracked in your Mencoro project. **Higher is better.** Mencoro weights each mention rather than just counting them; its documentation explains the formula. If only you and three competitors are tracked, Share of Voice is your slice of the mentions of those four brands — so it depends on which competitors you track.

**What to do with it:** it is the headline figure to report and to watch over time. A drop with a stable mention rate usually means competitors are being mentioned more, not that you are mentioned less — look at *You and your competitors*.

### Mention rate

Out of every AI answer Mencoro checked in the period, the percentage that mention your brand. **Higher is better.** Hover it to see how many of your tracked queries mention you at least once.

**What to do with it:** it tells you about *coverage*. A low mention rate with a good Share of Voice means you dominate a few queries and are absent from many. Look at which queries never mention you in Mencoro and decide whether you need content for them.

### Average position

When your brand is mentioned, where it appears inside the answer, on average. `#1` is the first brand mentioned. **Lower is better**, so the arrow goes ▲ green when the number goes *down*.

**What to do with it:** answers often list several options; being first matters. If your position slips while competitors stay put, check how answers describe them versus you (Mencoro keeps the actual mention texts).

### Positivity

Tone of the mentions, from 0 (all negative) to 100 (all positive). **Higher is better.**

**What to do with it:** a sudden fall is worth investigating early. Read the negative mentions in Mencoro: they often trace back to one outdated review, forum thread or article that the AI keeps citing — and that appears in *Sources the AI answers cite*.

## Share of Voice over time

A small area chart of your Share of Voice per day (per week over 90 days). Hover any point for its date and value. The last point is highlighted, and the dates and the last value are written under the chart.

A **gap in the line** means Mencoro has no checks for those days: nothing was measured, which is not the same as zero. *Not enough days with data to draw a trend yet* appears when there are fewer than two days with data.

## You and your competitors

Your brand (marked **you**) and up to five competitors, sorted by Share of Voice. Competitor names are the ones set up in Mencoro. This block is hidden when the project tracks no competitors.

## Tone of the mentions

How many of your mentions were positive, neutral or negative, as a bar and as numbers. Blue is positive, grey neutral, red negative; the legend always gives the percentage and the count, so colour is never the only cue.

## Sources the AI answers cite

The six domains the AI answers cited most in the period, with the number of citations. These are the sites the engines *trust on your topics*.

- If one of them is your site, it is marked **your site**. "Your site" means any domain configured in the Mencoro project, or this WordPress site's own domain (with or without `www.`).
- If your site is not in the list, a yellow tip says so.

**What to do with it:** this is often the most actionable block.

- **Your site is missing:** the engines are learning about your topic elsewhere. Getting your brand mentioned on those domains (an article, a listing, a comparison, an expert quote, an answer in a community) is usually faster than waiting for your own pages to be cited.
- **A forum or community is high:** people's questions there shape the answers. Being genuinely present matters more than links.
- **Your site is there:** find out which of your pages get cited (Mencoro can group sources by page) and keep them accurate and up to date.

## Queries that moved most

Up to three tracked queries on each side:

- **Gaining**: the biggest gains in Share of Voice against the previous period.
- **Losing**: the biggest losses.

Each row shows the query, the engine and country it was checked in, and the change in percentage points. Only real gains appear under *Gaining*, and only real losses under *Losing*: queries that did not move are left out, so a side can read *None in this period*.

**What to do with it:** a query that loses a lot in one engine but not the others usually points to a change in that engine (a new source it relies on, a model update). A query that loses everywhere usually points to a competitor's move.

## What do these figures mean?

A collapsible glossary at the bottom of the widget repeats the definitions above, for colleagues and clients who see the widget without this guide.

## Footer

- **Data from Mencoro · updated … ago**: when the data was last fetched, not when Mencoro last checked the answers.
- **Refresh**: fetches fresh data now, for everyone. Use it after adding queries or competitors in Mencoro.
- **Settings**: administrators only.

## Other states you may see

| Message | Meaning |
|---|---|
| *How do ChatGPT, Claude, Perplexity and Google AI talk about your brand?* + **Connect Mencoro** | The plugin is not set up yet. Editors see *Ask an administrator…* instead of the button. |
| *No AI answers were checked in this period yet…* | The project exists but Mencoro has no checks in this window. Common in the first days. Try 90 days or come back tomorrow. |
| *This Mencoro project does not track any AI engine yet…* | The project only tracks classic Google results or Shopping. Add AI engines to its queries in Mencoro. |
| *This project does not track any of the AI engines chosen in the settings…* | You ticked engines the project does not use. Tick others in the settings. |
| *Mencoro is recalculating part of this period…* | Mencoro is reprocessing data; figures may still change slightly. Nothing to do. |
| A red error message with **Try again** | See [Troubleshooting](troubleshooting.md). |

## How fresh is the data?

Two layers:

1. **Mencoro** checks your tracked queries on the schedule set in your Mencoro project; with the usual daily frequency, numbers move once a day.
2. **The plugin** keeps what it fetched for 6 hours, and starts fresh each UTC day. **Refresh** skips the wait.

So refreshing many times a day rarely shows anything new; it is useful right after you change something in Mencoro.
