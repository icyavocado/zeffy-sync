# zeffy-sync

Sync Zeffy campaigns (events) to WordPress posts using:

- Zeffy campaigns API: `GET https://www.zeffy.com/api/v1/campaigns`
- WordPress posts API: `POST/GET /wp-json/wp/v2/posts`

## Configuration

Set the required environment variables:

- `ZEFFY_API_KEY`: Zeffy API bearer token
- `WP_BASE_URL`: WordPress site URL (for example `https://example.org`)
- `WP_USERNAME`: WordPress username
- `WP_APP_PASSWORD`: WordPress application password

## Usage

Dry-run (no writes):

```bash
python zeffy_sync.py --dry-run
```

Live sync:

```bash
python zeffy_sync.py
```

## Behavior

- Fetches campaigns from Zeffy
- Normalizes campaign fields
- Maps each campaign to a deterministic slug: `zeffy-event-<campaign-id>`
- Creates a WordPress post when no matching slug exists
- Updates the existing WordPress post when the slug already exists
