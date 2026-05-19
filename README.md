# zeffy-sync

WordPress plugin that syncs Zeffy campaigns to WordPress posts.

## Zeffy source API

- `GET https://www.zeffy.com/api/v1/campaigns`

## WordPress target API

- Uses core post APIs equivalent to REST post operations (`/wp-json/wp/v2/posts`) by creating/updating posts in WordPress.

## Installation

1. Copy `zeffy-sync.php` into your WordPress plugins directory.
2. Activate **Zeffy Sync** in wp-admin.
3. Configure your Zeffy API key in `wp-config.php`:

```php
define('ZEFFY_SYNC_API_KEY', 'your-zeffy-api-key');
```

(Alternative: set `ZEFFY_API_KEY` as an environment variable.)

## Sync behavior

- On activation, schedules an hourly cron sync (`zeffy_sync_run`).
- For each Zeffy campaign:
  - Normalizes campaign fields (`id`, `name/title`, `description/summary/details`, `status`).
  - Finds an existing post by `_zeffy_campaign_id` meta.
  - Creates a new post if none exists, otherwise updates the existing post.
  - Stores campaign linkage in `_zeffy_campaign_id`.
- Supports manual run from WP-CLI:

```bash
wp zeffy-sync run
```
