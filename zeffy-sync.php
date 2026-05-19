<?php
/**
 * Plugin Name: Zeffy Sync
 * Description: Sync Zeffy campaigns to WordPress posts.
 * Version: 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

const ZEFFY_SYNC_CRON_HOOK = 'zeffy_sync_run';
const ZEFFY_SYNC_ZEFFY_ENDPOINT = 'https://www.zeffy.com/api/v1/campaigns';

register_activation_hook(__FILE__, static function (): void {
    if (!wp_next_scheduled(ZEFFY_SYNC_CRON_HOOK)) {
        wp_schedule_event(time(), 'hourly', ZEFFY_SYNC_CRON_HOOK);
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
});

add_action(ZEFFY_SYNC_CRON_HOOK, 'zeffy_sync_run');

/**
 * Run one synchronization cycle from Zeffy to WordPress posts.
 */
function zeffy_sync_run(): void
{
    $api_key = zeffy_sync_get_api_key();
    if (!$api_key) {
        return;
    }

    $campaigns = zeffy_sync_fetch_campaigns($api_key);
    if (is_wp_error($campaigns)) {
        return;
    }

    foreach ($campaigns as $campaign) {
        $normalized = zeffy_sync_normalize_campaign($campaign);
        if (is_wp_error($normalized)) {
            continue;
        }

        zeffy_sync_upsert_post($normalized);
    }
}

/**
 * @return string
 */
function zeffy_sync_get_api_key(): string
{
    $constant_key = defined('ZEFFY_SYNC_API_KEY') ? (string) ZEFFY_SYNC_API_KEY : '';
    if ($constant_key !== '') {
        return $constant_key;
    }

    $env_key = getenv('ZEFFY_API_KEY');
    if (is_string($env_key) && $env_key !== '') {
        return $env_key;
    }

    return '';
}

/**
 * @return array<int, array<string, mixed>>|WP_Error
 */
function zeffy_sync_fetch_campaigns(string $api_key)
{
    $response = wp_remote_get(
        ZEFFY_SYNC_ZEFFY_ENDPOINT,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Accept' => 'application/json',
            ],
            'timeout' => 20,
        ]
    );

    if (is_wp_error($response)) {
        return $response;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return new WP_Error('zeffy_sync_http_error', 'Failed to fetch Zeffy campaigns.', ['status' => $status]);
    }

    $body = wp_remote_retrieve_body($response);
    $decoded = json_decode($body, true);

    if (is_array($decoded) && array_is_list($decoded)) {
        return $decoded;
    }

    if (is_array($decoded)) {
        foreach (['data', 'campaigns', 'results'] as $key) {
            if (isset($decoded[$key]) && is_array($decoded[$key])) {
                return $decoded[$key];
            }
        }
    }

    return new WP_Error('zeffy_sync_invalid_response', 'Unexpected Zeffy campaigns response shape.');
}

/**
 * @param array<string, mixed> $campaign
 * @return array<string, string>|WP_Error
 */
function zeffy_sync_normalize_campaign(array $campaign)
{
    $campaign_id = zeffy_sync_first_non_empty($campaign['id'] ?? null, $campaign['campaign_id'] ?? null);
    if ($campaign_id === '') {
        return new WP_Error('zeffy_sync_missing_campaign_id', 'Campaign is missing an ID.');
    }

    $title = zeffy_sync_first_non_empty($campaign['name'] ?? null, $campaign['title'] ?? null, 'Zeffy campaign ' . $campaign_id);
    $content = zeffy_sync_first_non_empty($campaign['description'] ?? null, $campaign['summary'] ?? null, $campaign['details'] ?? null);

    $status = zeffy_sync_first_non_empty($campaign['status'] ?? null, 'publish');
    $status = in_array($status, ['publish', 'draft', 'private', 'pending'], true) ? $status : 'publish';

    return [
        'campaign_id' => sanitize_text_field($campaign_id),
        'title' => sanitize_text_field($title),
        'content' => wp_kses_post($content),
        'status' => $status,
    ];
}

/**
 * @param mixed ...$values
 */
function zeffy_sync_first_non_empty(...$values): string
{
    foreach ($values as $value) {
        if ($value === null) {
            continue;
        }

        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    return '';
}

/**
 * @param array<string, string> $normalized
 */
function zeffy_sync_upsert_post(array $normalized): void
{
    $existing = get_posts([
        'post_type' => 'post',
        'post_status' => 'any',
        'meta_query' => [
            [
                'key' => '_zeffy_campaign_id',
                'value' => $normalized['campaign_id'],
            ],
        ],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'no_found_rows' => true,
    ]);

    $slug = sanitize_title('zeffy-event-' . $normalized['campaign_id']);

    $postarr = [
        'post_title' => $normalized['title'],
        'post_content' => $normalized['content'],
        'post_status' => $normalized['status'],
        'post_type' => 'post',
        'post_name' => $slug,
    ];

    $post_id = 0;
    if (!empty($existing)) {
        $postarr['ID'] = (int) $existing[0];
        $post_id = (int) wp_update_post($postarr, true);
    } else {
        $post_id = (int) wp_insert_post($postarr, true);
    }

    if ($post_id > 0) {
        update_post_meta($post_id, '_zeffy_campaign_id', $normalized['campaign_id']);
    }
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('zeffy-sync run', static function (): void {
        zeffy_sync_run();
        WP_CLI::success('Zeffy sync completed.');
    });
}
