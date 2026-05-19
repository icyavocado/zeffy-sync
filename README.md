# zeffy-sync

WordPress plugin that syncs Zeffy campaigns to WordPress posts/events.

## Zeffy source API

- `GET https://www.zeffy.com/api/v1/campaigns`

## WordPress UI setup

After activation, use the **Zeffy Sync** item in the WordPress admin sidebar.

![Zeffy Sync settings UI](docs/zeffy-sync-settings-ui.png)

The settings page includes pre-filled defaults that you can change:

- **Zeffy API Key** (credential)
- **Zeffy Campaigns Endpoint** (default: `https://www.zeffy.com/api/v1/campaigns`)
- **Target Post Type** (default: `post`)
- **Default Post Status** (default: `publish`)
- **Sync Interval** (default: `hourly`)

You can also trigger a manual run with **Run Sync Now**.

## Behavior

- Schedules a recurring sync on activation using the selected interval.
- Pulls Zeffy campaigns and normalizes key fields (`id`, `name/title`, `description/summary/details`, `status`).
- Finds existing content via `_zeffy_campaign_id` post meta.
- Creates new posts/events when no match exists; updates existing ones otherwise.
- Stores Zeffy campaign linkage in `_zeffy_campaign_id`.

## WP-CLI

```bash
wp zeffy-sync run
```

## CI zip artifact

GitHub Actions builds a plugin zip artifact on each push and pull request.

1. Open the workflow run for **Build plugin zip**.
2. Download artifact **zeffy-sync-plugin**.
3. Install in WordPress via **Plugins → Add New → Upload Plugin**.
