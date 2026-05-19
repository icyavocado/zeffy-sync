<?php
/**
 * Plugin Name: Zeffy Sync
 * Description: Sync Zeffy campaigns to WordPress posts.
 * Version: 1.1.0
 * Update URI: https://github.com/icyavocado/zeffy-sync
 */

if (!defined('ABSPATH')) {
    exit;
}

const ZEFFY_SYNC_CRON_HOOK = 'zeffy_sync_run';
const ZEFFY_SYNC_SETTINGS_OPTION = 'zeffy_sync_settings';
const ZEFFY_SYNC_MENU_SLUG = 'zeffy-sync';
const ZEFFY_SYNC_DEFAULT_ENDPOINT = 'https://www.zeffy.com/api/v1/campaigns';
const ZEFFY_SYNC_UPDATE_TRANSIENT = 'zeffy_sync_latest_release';
const ZEFFY_SYNC_GITHUB_REPO = 'icyavocado/zeffy-sync';
const ZEFFY_SYNC_GITHUB_RELEASES_API = 'https://api.github.com/repos/' . ZEFFY_SYNC_GITHUB_REPO . '/releases/latest';

register_activation_hook(__FILE__, 'zeffy_sync_activate');
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
});

add_action(ZEFFY_SYNC_CRON_HOOK, 'zeffy_sync_run');
add_action('admin_menu', 'zeffy_sync_register_admin_menu');
add_action('admin_init', 'zeffy_sync_register_settings');
add_action('admin_post_zeffy_sync_run_now', 'zeffy_sync_handle_run_now');
add_filter('pre_set_site_transient_update_plugins', 'zeffy_sync_maybe_set_plugin_update');
add_filter('plugins_api', 'zeffy_sync_plugin_information', 10, 3);

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
 * @param mixed $transient
 * @return mixed
 */
function zeffy_sync_maybe_set_plugin_update($transient)
{
    if (!is_object($transient)) {
        return $transient;
    }

    $release = zeffy_sync_get_latest_release();
    if (!is_array($release)) {
        return $transient;
    }

    $plugin_file = plugin_basename(__FILE__);
    $current_version = zeffy_sync_get_installed_version();
    if (!version_compare($release['version'], $current_version, '>')) {
        return $transient;
    }

    $transient->response[$plugin_file] = (object) [
        'slug' => 'zeffy-sync',
        'plugin' => $plugin_file,
        'new_version' => $release['version'],
        'package' => $release['download_url'],
        'url' => $release['html_url'],
    ];

    return $transient;
}

/**
 * @param mixed $result
 * @param string $action
 * @param object $args
 * @return mixed
 */
function zeffy_sync_plugin_information($result, string $action, object $args)
{
    if ($action !== 'plugin_information' || !isset($args->slug) || $args->slug !== 'zeffy-sync') {
        return $result;
    }

    $release = zeffy_sync_get_latest_release();
    if (!is_array($release)) {
        return $result;
    }

    return (object) [
        'name' => 'Zeffy Sync',
        'slug' => 'zeffy-sync',
        'version' => $release['version'],
        'requires' => '6.0',
        'tested' => get_bloginfo('version'),
        'download_link' => $release['download_url'],
        'homepage' => $release['html_url'],
        'sections' => [
            'description' => 'Sync Zeffy campaigns to WordPress posts/events.',
        ],
    ];
}

/**
 * @return array<string, string>|null
 */
function zeffy_sync_get_latest_release(): ?array
{
    $cached = get_transient(ZEFFY_SYNC_UPDATE_TRANSIENT);
    if (is_array($cached) && isset($cached['version'], $cached['download_url'], $cached['html_url'])) {
        return $cached;
    }

    $response = wp_remote_get(
        ZEFFY_SYNC_GITHUB_RELEASES_API,
        [
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
            ],
            'timeout' => 20,
        ]
    );
    if (is_wp_error($response)) {
        return null;
    }

    $status = (int) wp_remote_retrieve_response_code($response);
    if ($status < 200 || $status >= 300) {
        return null;
    }

    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    if (!is_array($data) || !isset($data['tag_name'], $data['html_url'])) {
        return null;
    }

    $download_url = '';
    if (isset($data['assets']) && is_array($data['assets'])) {
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $asset_name = (string) ($asset['name'] ?? '');
            $asset_url = (string) ($asset['browser_download_url'] ?? '');
            if ($asset_name === 'zeffy-sync.zip' && $asset_url !== '') {
                $download_url = $asset_url;
                break;
            }
        }
    }

    if ($download_url === '' && isset($data['zipball_url'])) {
        $download_url = (string) $data['zipball_url'];
    }
    if ($download_url === '') {
        return null;
    }

    $version = ltrim((string) $data['tag_name'], 'v');
    if ($version === '') {
        return null;
    }

    $release = [
        'version' => $version,
        'download_url' => esc_url_raw($download_url),
        'html_url' => esc_url_raw((string) $data['html_url']),
    ];

    set_transient(ZEFFY_SYNC_UPDATE_TRANSIENT, $release, HOUR_IN_SECONDS);
    return $release;
}

function zeffy_sync_get_installed_version(): string
{
    if (!function_exists('get_plugin_data')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }

    $data = get_plugin_data(__FILE__, false, false);
    $version = is_array($data) ? (string) ($data['Version'] ?? '') : '';
    return $version !== '' ? $version : '0.0.0';
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
        $summary = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        zeffy_sync_log_sync_completed($summary, 'Missing API key.');
        return $summary;
    }

    $campaigns = zeffy_sync_fetch_campaigns($settings['api_key'], $settings['api_endpoint']);
    if (is_wp_error($campaigns)) {
        $summary = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        zeffy_sync_log_sync_completed($summary, 'Event fetch failed: ' . $campaigns->get_error_message());
        return $summary;
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

    zeffy_sync_log_sync_completed($summary);
    return $summary;
}

/**
 * @param array<string, int> $summary
 */
function zeffy_sync_log_sync_completed(array $summary, string $context = ''): void
{
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }

    $message = sprintf(
        'Sync completed. Total: %d, created: %d, updated: %d, skipped: %d',
        (int) ($summary['total'] ?? 0),
        (int) ($summary['created'] ?? 0),
        (int) ($summary['updated'] ?? 0),
        (int) ($summary['skipped'] ?? 0)
    );

    if ($context !== '') {
        $message .= ' Context: ' . sanitize_text_field($context);
    }

    error_log('[Zeffy Sync] ' . $message);
}

/**
 * @return array<int, array<string, mixed>>|WP_Error
 */
function zeffy_sync_fetch_campaigns(string $api_key, string $endpoint)
{
    $all_campaigns = [];
    $cursor = null;

    do {
        $url = $endpoint;
        if ($cursor !== null) {
            $url = add_query_arg('starting_after', $cursor, $endpoint);
        }

        $response = wp_remote_get(
            $url,
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

        if (!is_array($decoded)) {
            return new WP_Error('zeffy_sync_invalid_response', 'Unexpected Zeffy campaigns response shape.');
        }

        if (array_values($decoded) === $decoded) {
            // Plain array response — no pagination support
            return zeffy_sync_extract_events($decoded);
        }

        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $all_campaigns = array_merge($all_campaigns, $decoded['data']);
            $has_more = !empty($decoded['has_more']);
            $cursor = ($has_more && isset($decoded['next_cursor']) && is_string($decoded['next_cursor']))
                ? $decoded['next_cursor']
                : null;
            if (!$has_more) {
                break;
            }
        } else {
            // Fallback: try other known keys (non-paginated APIs)
            foreach (['campaigns', 'results', 'events'] as $key) {
                if (isset($decoded[$key]) && is_array($decoded[$key])) {
                    return zeffy_sync_extract_events($decoded[$key]);
                }
            }
            return new WP_Error('zeffy_sync_invalid_response', 'Unexpected Zeffy campaigns response shape.');
        }
    } while ($cursor !== null);

    return zeffy_sync_extract_events($all_campaigns);
}

/**
 * @param array<int, mixed> $items
 * @return array<int, array<string, mixed>>
 */
function zeffy_sync_extract_events(array $items): array
{
    $events = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $events[] = $item;
    }

    return $events;
}

/**
 * @param array<string, mixed> $campaign
 * @return array<string, mixed>|WP_Error
 */
function zeffy_sync_normalize_campaign(array $campaign, string $default_status)
{
    $campaign_id = zeffy_sync_first_non_empty(
        $campaign['event_id'] ?? null,
        $campaign['id'] ?? null,
        $campaign['campaign_id'] ?? null,
        $campaign['uuid'] ?? null
    );
    if ($campaign_id === '') {
        return new WP_Error('zeffy_sync_missing_campaign_id', 'Campaign is missing an ID.');
    }

    // Skip deleted campaigns
    if (isset($campaign['deleted_at']) && $campaign['deleted_at'] !== null) {
        return new WP_Error('zeffy_sync_deleted_campaign', 'Campaign is deleted.');
    }

    $title = zeffy_sync_first_non_empty(
        $campaign['event_name'] ?? null,
        $campaign['name'] ?? null,
        $campaign['title'] ?? null,
        'Zeffy event ' . $campaign_id
    );
    $content = zeffy_sync_first_non_empty(
        $campaign['details'] ?? null,
        $campaign['description'] ?? null,
        $campaign['summary'] ?? null
    );

    // Map API status to WordPress status
    $api_status = isset($campaign['status']) ? (string) $campaign['status'] : '';
    if ($api_status === 'active') {
        $status = $default_status;
    } else {
        $status = 'draft';
    }

    // Archived campaigns are set to draft
    if (!empty($campaign['is_archived'])) {
        $status = 'draft';
    }

    // Extra meta fields
    $zeffy_url = (isset($campaign['url']) && is_string($campaign['url'])) ? $campaign['url'] : '';
    $campaign_type = (isset($campaign['type']) && is_string($campaign['type'])) ? $campaign['type'] : '';

    // Compute start/end from occurrences
    $start_date = null;
    $end_date = null;
    if (isset($campaign['occurrences']) && is_array($campaign['occurrences'])) {
        foreach ($campaign['occurrences'] as $occurrence) {
            if (!is_array($occurrence)) {
                continue;
            }
            $occ_start = isset($occurrence['start_date']) && is_numeric($occurrence['start_date'])
                ? (int) $occurrence['start_date'] : null;
            $occ_end = isset($occurrence['end_date']) && is_numeric($occurrence['end_date'])
                ? (int) $occurrence['end_date'] : null;
            if ($occ_start !== null && ($start_date === null || $occ_start < $start_date)) {
                $start_date = $occ_start;
            }
            if ($occ_end !== null && ($end_date === null || $occ_end > $end_date)) {
                $end_date = $occ_end;
            }
        }
    }
    // Fall back to campaign-level start_date/end_date if no occurrences
    if ($start_date === null && isset($campaign['start_date']) && is_numeric($campaign['start_date'])) {
        $start_date = (int) $campaign['start_date'];
    }
    if ($end_date === null && isset($campaign['end_date']) && is_numeric($campaign['end_date'])) {
        $end_date = (int) $campaign['end_date'];
    }

    return [
        'campaign_id'   => sanitize_text_field($campaign_id),
        'title'         => sanitize_text_field($title),
        'content'       => wp_kses_post($content),
        'status'        => $status,
        'zeffy_url'     => esc_url_raw($zeffy_url),
        'campaign_type' => sanitize_text_field($campaign_type),
        'start_date'    => $start_date,
        'end_date'      => $end_date,
    ];
}

/**
 * @param mixed ...$values
 */
function zeffy_sync_first_non_empty(...$values): string
{
    foreach ($values as $value) {
        if ($value === null || is_array($value) || is_object($value)) {
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
 * @param array<string, mixed> $normalized
 */
function zeffy_sync_upsert_post(array $normalized, string $post_type): string
{
    $existing = get_posts([
        'post_type'        => $post_type,
        'post_status'      => 'any',
        'meta_query'       => [
            [
                'key'   => '_zeffy_campaign_id',
                'value' => $normalized['campaign_id'],
            ],
        ],
        'posts_per_page'   => 1,
        'fields'           => 'ids',
        'no_found_rows'    => true,
        'suppress_filters' => false,
    ]);

    $slug = sanitize_title('zeffy-event-' . $normalized['campaign_id']);

    $postarr = [
        'post_title'   => $normalized['title'],
        'post_content' => $normalized['content'],
        'post_status'  => $normalized['status'],
        'post_type'    => $post_type,
        'post_name'    => $slug,
    ];

    if (!empty($existing)) {
        $existing_id = (int) $existing[0];

        $actual_type = get_post_type($existing_id);
        if ($actual_type !== $post_type) {
            return 'skipped';
        }

        $postarr['ID'] = $existing_id;
        $post_id = wp_update_post($postarr, true);
        if (is_wp_error($post_id)) {
            return 'skipped';
        }

        zeffy_sync_write_post_meta((int) $post_id, $normalized);
        return 'updated';
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        return 'skipped';
    }

    zeffy_sync_write_post_meta((int) $post_id, $normalized);
    return 'created';
}

/**
 * @param array<string, mixed> $normalized
 */
function zeffy_sync_write_post_meta(int $post_id, array $normalized): void
{
    update_post_meta($post_id, '_zeffy_campaign_id', $normalized['campaign_id']);

    if (isset($normalized['zeffy_url']) && $normalized['zeffy_url'] !== '') {
        update_post_meta($post_id, '_zeffy_url', $normalized['zeffy_url']);
    }

    if (isset($normalized['campaign_type']) && $normalized['campaign_type'] !== '') {
        update_post_meta($post_id, '_zeffy_campaign_type', $normalized['campaign_type']);
    }

    if (isset($normalized['start_date']) && $normalized['start_date'] !== null) {
        update_post_meta($post_id, '_zeffy_start_date', (int) $normalized['start_date']);
    }

    if (isset($normalized['end_date']) && $normalized['end_date'] !== null) {
        update_post_meta($post_id, '_zeffy_end_date', (int) $normalized['end_date']);
    }
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
