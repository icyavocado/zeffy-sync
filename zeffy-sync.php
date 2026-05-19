<?php
/**
 * Plugin Name: Zeffy Sync
 * Description: Sync Zeffy campaigns to WordPress posts.
 * Version: 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

const ZEFFY_SYNC_CRON_HOOK = 'zeffy_sync_run';
const ZEFFY_SYNC_SETTINGS_OPTION = 'zeffy_sync_settings';
const ZEFFY_SYNC_MENU_SLUG = 'zeffy-sync';
const ZEFFY_SYNC_DEFAULT_ENDPOINT = 'https://www.zeffy.com/api/v1/campaigns';

register_activation_hook(__FILE__, 'zeffy_sync_activate');
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
});

add_action(ZEFFY_SYNC_CRON_HOOK, 'zeffy_sync_run');
add_action('admin_menu', 'zeffy_sync_register_admin_menu');
add_action('admin_init', 'zeffy_sync_register_settings');
add_action('admin_post_zeffy_sync_run_now', 'zeffy_sync_handle_run_now');

function zeffy_sync_activate(): void
{
    $settings = zeffy_sync_get_settings();
    zeffy_sync_reschedule_event($settings['sync_interval']);
}

/**
 * @return array<string, string>
 */
function zeffy_sync_default_settings(): array
{
    return [
        'api_key' => zeffy_sync_fallback_api_key(),
        'api_endpoint' => ZEFFY_SYNC_DEFAULT_ENDPOINT,
        'post_type' => 'post',
        'default_status' => 'publish',
        'sync_interval' => 'hourly',
    ];
}

/**
 * @return array<string, string>
 */
function zeffy_sync_get_settings(): array
{
    $defaults = zeffy_sync_default_settings();
    $saved = get_option(ZEFFY_SYNC_SETTINGS_OPTION, []);
    $saved = is_array($saved) ? $saved : [];

    $settings = zeffy_sync_sanitize_settings(array_merge($defaults, $saved));

    if ($settings['api_key'] === '') {
        $settings['api_key'] = zeffy_sync_fallback_api_key();
    }

    return $settings;
}

function zeffy_sync_fallback_api_key(): string
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

function zeffy_sync_register_admin_menu(): void
{
    add_menu_page(
        'Zeffy Sync',
        'Zeffy Sync',
        'manage_options',
        ZEFFY_SYNC_MENU_SLUG,
        'zeffy_sync_render_settings_page',
        'dashicons-update'
    );
}

function zeffy_sync_register_settings(): void
{
    register_setting(
        'zeffy_sync_settings_group',
        ZEFFY_SYNC_SETTINGS_OPTION,
        [
            'type' => 'array',
            'sanitize_callback' => 'zeffy_sync_sanitize_settings',
            'default' => zeffy_sync_default_settings(),
        ]
    );
}

/**
 * @param mixed $input
 * @return array<string, string>
 */
function zeffy_sync_sanitize_settings($input): array
{
    $defaults = zeffy_sync_default_settings();
    $input = is_array($input) ? $input : [];

    $endpoint = esc_url_raw((string) ($input['api_endpoint'] ?? $defaults['api_endpoint']));
    if ($endpoint === '') {
        $endpoint = $defaults['api_endpoint'];
    }

    $post_type = sanitize_key((string) ($input['post_type'] ?? $defaults['post_type']));
    if ($post_type === '' || !post_type_exists($post_type)) {
        $post_type = 'post';
    }

    $status = sanitize_key((string) ($input['default_status'] ?? $defaults['default_status']));
    $allowed_statuses = ['publish', 'draft', 'private', 'pending'];
    if (!in_array($status, $allowed_statuses, true)) {
        $status = $defaults['default_status'];
    }

    $interval = sanitize_key((string) ($input['sync_interval'] ?? $defaults['sync_interval']));
    $schedules = wp_get_schedules();
    if (!isset($schedules[$interval])) {
        $interval = $defaults['sync_interval'];
    }

    $old_settings = get_option(ZEFFY_SYNC_SETTINGS_OPTION, zeffy_sync_default_settings());
    if (!is_array($old_settings) || ($old_settings['sync_interval'] ?? '') !== $interval) {
        zeffy_sync_reschedule_event($interval);
    }

    return [
        'api_key' => sanitize_text_field((string) ($input['api_key'] ?? '')),
        'api_endpoint' => $endpoint,
        'post_type' => $post_type,
        'default_status' => $status,
        'sync_interval' => $interval,
    ];
}

function zeffy_sync_reschedule_event(string $interval): void
{
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
    if (!wp_next_scheduled(ZEFFY_SYNC_CRON_HOOK)) {
        wp_schedule_event(time() + 60, $interval, ZEFFY_SYNC_CRON_HOOK);
    }
}

function zeffy_sync_render_settings_page(): void
{
    if (!current_user_can('manage_options')) {
        return;
    }

    $settings = zeffy_sync_get_settings();
    $run_now_url = wp_nonce_url(
        admin_url('admin-post.php?action=zeffy_sync_run_now'),
        'zeffy_sync_run_now'
    );

    $schedules = wp_get_schedules();
    $statuses = ['publish', 'draft', 'pending', 'private'];
    ?>
    <div class="wrap">
        <h1>Zeffy Sync</h1>
        <p>Configure Zeffy credentials and synchronization behavior.</p>

        <?php if (isset($_GET['zeffy_sync_ran'])) : ?>
            <div class="notice notice-success is-dismissible"><p>Sync completed.</p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php settings_fields('zeffy_sync_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="zeffy-sync-api-key">Zeffy API Key</label></th>
                    <td>
                        <input id="zeffy-sync-api-key" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['api_key']); ?>" autocomplete="off" />
                        <p class="description">Used for the Zeffy campaigns API request.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zeffy-sync-endpoint">Zeffy Campaigns Endpoint</label></th>
                    <td>
                        <input id="zeffy-sync-endpoint" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[api_endpoint]" type="url" class="regular-text" value="<?php echo esc_attr($settings['api_endpoint']); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zeffy-sync-post-type">Target Post Type</label></th>
                    <td>
                        <input id="zeffy-sync-post-type" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[post_type]" type="text" class="regular-text" value="<?php echo esc_attr($settings['post_type']); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zeffy-sync-default-status">Default Post Status</label></th>
                    <td>
                        <select id="zeffy-sync-default-status" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[default_status]">
                            <?php foreach ($statuses as $status) : ?>
                                <option value="<?php echo esc_attr($status); ?>" <?php selected($settings['default_status'], $status); ?>><?php echo esc_html(ucfirst($status)); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="zeffy-sync-interval">Sync Interval</label></th>
                    <td>
                        <select id="zeffy-sync-interval" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[sync_interval]">
                            <?php foreach ($schedules as $key => $schedule) : ?>
                                <option value="<?php echo esc_attr($key); ?>" <?php selected($settings['sync_interval'], $key); ?>><?php echo esc_html($schedule['display']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <p>
            <a class="button button-secondary" href="<?php echo esc_url($run_now_url); ?>">Run Sync Now</a>
        </p>
    </div>
    <?php
}

function zeffy_sync_handle_run_now(): void
{
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized.');
    }

    check_admin_referer('zeffy_sync_run_now');
    zeffy_sync_run();

    wp_safe_redirect(admin_url('admin.php?page=' . ZEFFY_SYNC_MENU_SLUG . '&zeffy_sync_ran=1'));
    exit;
}

/**
 * Run one synchronization cycle from Zeffy to WordPress posts.
 *
 * @return array<string, int>
 */
function zeffy_sync_run(): array
{
    $settings = zeffy_sync_get_settings();
    if ($settings['api_key'] === '') {
        return ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
    }

    $campaigns = zeffy_sync_fetch_campaigns($settings['api_key'], $settings['api_endpoint']);
    if (is_wp_error($campaigns)) {
        return ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
    }

    $summary = ['total' => count($campaigns), 'created' => 0, 'updated' => 0, 'skipped' => 0];

    foreach ($campaigns as $campaign) {
        if (!is_array($campaign)) {
            $summary['skipped']++;
            continue;
        }

        $normalized = zeffy_sync_normalize_campaign($campaign, $settings['default_status']);
        if (is_wp_error($normalized)) {
            $summary['skipped']++;
            continue;
        }

        $result = zeffy_sync_upsert_post($normalized, $settings['post_type']);
        if ($result === 'created') {
            $summary['created']++;
        } elseif ($result === 'updated') {
            $summary['updated']++;
        } else {
            $summary['skipped']++;
        }
    }

    return $summary;
}

/**
 * @return array<int, array<string, mixed>>|WP_Error
 */
function zeffy_sync_fetch_campaigns(string $api_key, string $endpoint)
{
    $response = wp_remote_get(
        $endpoint,
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

    if (is_array($decoded) && array_values($decoded) === $decoded) {
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
function zeffy_sync_normalize_campaign(array $campaign, string $default_status)
{
    $campaign_id = zeffy_sync_first_non_empty($campaign['id'] ?? null, $campaign['campaign_id'] ?? null);
    if ($campaign_id === '') {
        return new WP_Error('zeffy_sync_missing_campaign_id', 'Campaign is missing an ID.');
    }

    $title = zeffy_sync_first_non_empty($campaign['name'] ?? null, $campaign['title'] ?? null, 'Zeffy campaign ' . $campaign_id);
    $content = zeffy_sync_first_non_empty($campaign['description'] ?? null, $campaign['summary'] ?? null, $campaign['details'] ?? null);

    $status = zeffy_sync_first_non_empty($campaign['status'] ?? null, $default_status);
    $status = in_array($status, ['publish', 'draft', 'private', 'pending'], true) ? $status : $default_status;

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
function zeffy_sync_upsert_post(array $normalized, string $post_type): string
{
    $existing = get_posts([
        'post_type' => $post_type,
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
        'post_type' => $post_type,
        'post_name' => $slug,
    ];

    if (!empty($existing)) {
        $postarr['ID'] = (int) $existing[0];
        $post_id = wp_update_post($postarr, true);
        if (is_wp_error($post_id)) {
            return 'skipped';
        }

        update_post_meta((int) $post_id, '_zeffy_campaign_id', $normalized['campaign_id']);
        return 'updated';
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        return 'skipped';
    }

    update_post_meta((int) $post_id, '_zeffy_campaign_id', $normalized['campaign_id']);
    return 'created';
}

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('zeffy-sync run', static function (): void {
        $summary = zeffy_sync_run();
        WP_CLI::success(sprintf(
            'Zeffy sync completed. Total: %d, created: %d, updated: %d, skipped: %d',
            $summary['total'],
            $summary['created'],
            $summary['updated'],
            $summary['skipped']
        ));
    });
}
