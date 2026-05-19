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
const ZEFFY_SYNC_DEFAULT_ENDPOINT = 'https://api.zeffy.com/api/v1/campaigns';
const ZEFFY_SYNC_UPDATE_TRANSIENT = 'zeffy_sync_latest_release';
const ZEFFY_SYNC_GITHUB_REPO = 'icyavocado/zeffy-sync';
const ZEFFY_SYNC_GITHUB_RELEASES_API = 'https://api.github.com/repos/' . ZEFFY_SYNC_GITHUB_REPO . '/releases/latest';
const ZEFFY_SYNC_GITHUB_RELEASES_LIST_API = 'https://api.github.com/repos/' . ZEFFY_SYNC_GITHUB_REPO . '/releases?per_page=20';
const ZEFFY_SYNC_LOG_OPTION = 'zeffy_sync_detailed_log';
const ZEFFY_SYNC_LOG_MAX_ENTRIES = 200;
const ZEFFY_SYNC_LOG_MAX_RESPONSE_BODY = 4000;

register_activation_hook(__FILE__, 'zeffy_sync_activate');
register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
});

add_action(ZEFFY_SYNC_CRON_HOOK, 'zeffy_sync_run');
add_action('admin_menu', 'zeffy_sync_register_admin_menu');
add_action('admin_init', 'zeffy_sync_register_settings');
add_action('admin_post_zeffy_sync_run_now', 'zeffy_sync_handle_run_now');
add_filter('pre_set_site_transient_update_plugins', 'zeffy_sync_maybe_set_plugin_update');
add_filter('site_transient_update_plugins', 'zeffy_sync_maybe_set_plugin_update');
add_filter('plugins_api', 'zeffy_sync_plugin_information', 10, 3);

function zeffy_sync_activate(): void
{
    $settings = zeffy_sync_get_settings();
    zeffy_sync_reschedule_event($settings['sync_interval']);
}

/**
 * @return array<string, mixed>
 */
function zeffy_sync_default_settings(): array
{
    return [
        'api_key' => zeffy_sync_fallback_api_key(),
        'api_endpoint' => ZEFFY_SYNC_DEFAULT_ENDPOINT,
        'post_type' => 'post',
        'default_status' => 'publish',
        'sync_interval' => 'hourly',
        'campaign_categories' => array_keys(zeffy_sync_campaign_category_map()),
    ];
}

/**
 * @return array<string, array<string, string>>
 */
function zeffy_sync_campaign_category_map(): array
{
    return [
        'Event' => [
            'label' => 'Events',
            'tag' => 'Event',
            'category' => 'Event',
        ],
        'DonationForm' => [
            'label' => 'Donation',
            'tag' => 'Donation',
            'category' => 'Donation',
        ],
        'MembershipV2' => [
            'label' => 'Membership',
            'tag' => 'Membership',
            'category' => 'Membership',
        ],
    ];
}

/**
 * @param mixed $categories
 * @return array<int, string>
 */
function zeffy_sync_sanitize_campaign_categories($categories): array
{
    if (!is_array($categories)) {
        return [];
    }

    $allowed_categories = array_keys(zeffy_sync_campaign_category_map());
    $sanitized = [];
    foreach ($categories as $category) {
        if (!is_scalar($category)) {
            continue;
        }

        $value = trim((string) $category);
        if ($value === '') {
            continue;
        }

        if (in_array($value, $allowed_categories, true)) {
            $sanitized[] = $value;
        }
    }

    return array_values(array_unique($sanitized));
}

/**
 * @return array<string, mixed>
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
 * @return array<string, mixed>
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

    $campaign_categories = zeffy_sync_sanitize_campaign_categories($input['campaign_categories'] ?? []);

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
        'campaign_categories' => $campaign_categories,
    ];
}

function zeffy_sync_reschedule_event(string $interval): void
{
    wp_clear_scheduled_hook(ZEFFY_SYNC_CRON_HOOK);
    if (!wp_next_scheduled(ZEFFY_SYNC_CRON_HOOK)) {
        wp_schedule_event(time() + 60, $interval, ZEFFY_SYNC_CRON_HOOK);
    }
}

/**
 * @return array<int, array<string, mixed>>
 */
function zeffy_sync_get_detailed_log(): array
{
    $log = get_option(ZEFFY_SYNC_LOG_OPTION, []);
    return is_array($log) ? $log : [];
}

/**
 * @param mixed $value
 * @return mixed
 */
function zeffy_sync_prepare_log_value($value)
{
    if (is_array($value)) {
        $prepared = [];
        foreach ($value as $key => $item) {
            if (!is_scalar($key)) {
                continue;
            }
            $prepared[sanitize_key((string) $key)] = zeffy_sync_prepare_log_value($item);
        }
        return $prepared;
    }

    if (is_object($value)) {
        return zeffy_sync_prepare_log_value((array) $value);
    }

    if (is_string($value)) {
        return sanitize_textarea_field($value);
    }

    if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
        return $value;
    }

    return sanitize_text_field((string) $value);
}

/**
 * @param array<string, mixed> $context
 */
function zeffy_sync_append_detailed_log(string $message, string $level = 'info', array $context = []): void
{
    $log = zeffy_sync_get_detailed_log();
    $allowed_levels = ['info', 'warning', 'error'];
    if (!in_array($level, $allowed_levels, true)) {
        $level = 'info';
    }

    $entry = [
        'timestamp' => current_time('mysql'),
        'level' => $level,
        'message' => sanitize_text_field($message),
        'context' => zeffy_sync_prepare_log_value($context),
    ];

    $log[] = $entry;
    if (count($log) > ZEFFY_SYNC_LOG_MAX_ENTRIES) {
        $log = array_slice($log, -ZEFFY_SYNC_LOG_MAX_ENTRIES);
    }

    update_option(ZEFFY_SYNC_LOG_OPTION, $log, false);
}

function zeffy_sync_mask_secret(string $secret): string
{
    if ($secret === '') {
        return '';
    }

    $length = strlen($secret);
    if ($length <= 8) {
        return str_repeat('*', $length);
    }

    return str_repeat('*', $length - 4) . substr($secret, -4);
}

function zeffy_sync_truncate_log_body(string $body): string
{
    if (strlen($body) <= ZEFFY_SYNC_LOG_MAX_RESPONSE_BODY) {
        return $body;
    }

    return substr($body, 0, ZEFFY_SYNC_LOG_MAX_RESPONSE_BODY) . '...[truncated]';
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
    $campaign_category_map = zeffy_sync_campaign_category_map();
    $detailed_log = array_reverse(zeffy_sync_get_detailed_log());
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
                        <input id="zeffy-sync-api-key" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[api_key]" type="password" class="regular-text" value="<?php echo esc_attr($settings['api_key']); ?>" autocomplete="new-password" autocorrect="off" autocapitalize="off" spellcheck="false" />
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
                <tr>
                    <th scope="row">Campaigns to Import</th>
                    <td>
                        <input type="hidden" name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[campaign_categories][]" value="" />
                        <?php foreach ($campaign_category_map as $key => $campaign_category) : ?>
                            <label style="display: block; margin-bottom: 4px;">
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(ZEFFY_SYNC_SETTINGS_OPTION); ?>[campaign_categories][]"
                                    value="<?php echo esc_attr($key); ?>"
                                    <?php checked(in_array($key, $settings['campaign_categories'], true)); ?>
                                />
                                <?php echo esc_html($campaign_category['label']); ?>
                            </label>
                        <?php endforeach; ?>
                        <p class="description">Choose which Zeffy campaign categories should be imported.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Save Settings'); ?>
        </form>

        <p>
            <a class="button button-secondary" href="<?php echo esc_url($run_now_url); ?>">Run Sync Now</a>
        </p>

        <h2>Detailed Zeffy Sync Log</h2>
        <p>Shows Zeffy request/response details and each sync step for troubleshooting.</p>
        <?php if (empty($detailed_log)) : ?>
            <p>No sync logs available yet.</p>
        <?php else : ?>
            <table class="widefat striped" role="presentation">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Level</th>
                        <th>Step</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($detailed_log as $entry) : ?>
                        <?php
                        $timestamp = isset($entry['timestamp']) ? (string) $entry['timestamp'] : '';
                        $level = isset($entry['level']) ? (string) $entry['level'] : 'info';
                        $message = isset($entry['message']) ? (string) $entry['message'] : '';
                        $context = isset($entry['context']) ? $entry['context'] : [];
                        $context_json = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                        if (!is_string($context_json)) {
                            $context_json = '';
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($timestamp); ?></td>
                            <td><?php echo esc_html(strtoupper($level)); ?></td>
                            <td><?php echo esc_html($message); ?></td>
                            <td><pre style="white-space: pre-wrap; margin: 0;"><?php echo esc_html($context_json); ?></pre></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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
        'id' => 'https://github.com/' . ZEFFY_SYNC_GITHUB_REPO,
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
 * @param mixed $args
 * @return mixed
 */
function zeffy_sync_plugin_information($result, string $action, $args)
{
    if (!is_object($args) || $action !== 'plugin_information' || !isset($args->slug) || $args->slug !== 'zeffy-sync') {
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

    $release = zeffy_sync_fetch_release_from_github();
    if (!is_array($release)) {
        return null;
    }

    set_transient(ZEFFY_SYNC_UPDATE_TRANSIENT, $release, HOUR_IN_SECONDS);
    return $release;
}

/**
 * @return array<string, string>|null
 */
function zeffy_sync_fetch_release_from_github(): ?array
{
    $request_args = [
        'headers' => [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url('/'),
        ],
        'timeout' => 20,
    ];

    $latest_response = wp_remote_get(ZEFFY_SYNC_GITHUB_RELEASES_API, $request_args);
    if (!is_wp_error($latest_response)) {
        $status = (int) wp_remote_retrieve_response_code($latest_response);
        if ($status >= 200 && $status < 300) {
            $latest_data = json_decode((string) wp_remote_retrieve_body($latest_response), true);
            $latest_release = zeffy_sync_normalize_github_release($latest_data);
            if (is_array($latest_release)) {
                return $latest_release;
            }
        }
    }

    $list_response = wp_remote_get(ZEFFY_SYNC_GITHUB_RELEASES_LIST_API, $request_args);
    if (is_wp_error($list_response)) {
        return null;
    }

    $list_status = (int) wp_remote_retrieve_response_code($list_response);
    if ($list_status < 200 || $list_status >= 300) {
        return null;
    }

    $list_data = json_decode((string) wp_remote_retrieve_body($list_response), true);
    if (!is_array($list_data)) {
        return null;
    }

    $best_release = null;
    foreach ($list_data as $release_data) {
        $candidate = zeffy_sync_normalize_github_release($release_data);
        if (!is_array($candidate)) {
            continue;
        }

        if ($best_release === null || version_compare($candidate['version'], $best_release['version'], '>')) {
            $best_release = $candidate;
        }
    }

    return $best_release;
}

/**
 * @param mixed $data
 * @return array<string, string>|null
 */
function zeffy_sync_normalize_github_release($data): ?array
{
    if (!is_array($data) || !isset($data['tag_name'], $data['html_url'])) {
        return null;
    }

    if (!empty($data['draft']) || !empty($data['prerelease'])) {
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

    return [
        'version' => $version,
        'download_url' => esc_url_raw($download_url),
        'html_url' => esc_url_raw((string) $data['html_url']),
    ];
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
    zeffy_sync_append_detailed_log(
        'Sync started.',
        'info',
        [
            'post_type' => $settings['post_type'],
            'default_status' => $settings['default_status'],
            'sync_interval' => $settings['sync_interval'],
            'endpoint' => $settings['api_endpoint'],
            'campaign_categories' => $settings['campaign_categories'],
        ]
    );

    if ($settings['api_key'] === '') {
        $summary = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        zeffy_sync_append_detailed_log('Sync stopped because API key is missing.', 'error');
        zeffy_sync_log_sync_completed($summary, 'Missing API key.');
        return $summary;
    }

    $campaigns = zeffy_sync_fetch_campaigns($settings['api_key'], $settings['api_endpoint']);
    if (is_wp_error($campaigns)) {
        $summary = ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0];
        zeffy_sync_append_detailed_log(
            'Failed to fetch Zeffy campaigns.',
            'error',
            [
                'error_code' => $campaigns->get_error_code(),
                'error_message' => $campaigns->get_error_message(),
                'error_data' => $campaigns->get_error_data(),
            ]
        );
        zeffy_sync_log_sync_completed($summary, 'Event fetch failed: ' . $campaigns->get_error_message());
        return $summary;
    }

    $summary = ['total' => count($campaigns), 'created' => 0, 'updated' => 0, 'skipped' => 0];
    zeffy_sync_append_detailed_log('Fetched campaigns from Zeffy.', 'info', ['campaign_count' => $summary['total']]);

    foreach ($campaigns as $index => $campaign) {
        if (!is_array($campaign)) {
            $summary['skipped']++;
            zeffy_sync_append_detailed_log(
                'Skipped campaign because payload is not an array.',
                'warning',
                ['index' => (int) $index]
            );
            continue;
        }

        $normalized = zeffy_sync_normalize_campaign($campaign, $settings['default_status']);
        if (is_wp_error($normalized)) {
            $summary['skipped']++;
            zeffy_sync_append_detailed_log(
                'Skipped campaign because normalization failed.',
                'warning',
                [
                    'index' => (int) $index,
                    'campaign_id' => zeffy_sync_first_non_empty(
                        $campaign['event_id'] ?? null,
                        $campaign['id'] ?? null,
                        $campaign['campaign_id'] ?? null,
                        $campaign['uuid'] ?? null
                    ),
                    'error_code' => $normalized->get_error_code(),
                    'error_message' => $normalized->get_error_message(),
                ]
            );
            continue;
        }

        zeffy_sync_append_detailed_log(
            'Campaign normalized.',
            'info',
            [
                'index' => (int) $index,
                'campaign_id' => $normalized['campaign_id'],
                'post_status' => $normalized['status'],
                'campaign_category' => $normalized['campaign_category'],
            ]
        );

        if (!in_array($normalized['campaign_category'], $settings['campaign_categories'], true)) {
            $summary['skipped']++;
            zeffy_sync_append_detailed_log(
                'Skipped campaign because category is not selected for import.',
                'info',
                [
                    'index' => (int) $index,
                    'campaign_id' => $normalized['campaign_id'],
                    'campaign_category' => $normalized['campaign_category'],
                ]
            );
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

        zeffy_sync_append_detailed_log(
            'Campaign sync step completed.',
            'info',
            [
                'index' => (int) $index,
                'campaign_id' => $normalized['campaign_id'],
                'result' => $result,
            ]
        );
    }

    zeffy_sync_log_sync_completed($summary);
    return $summary;
}

/**
 * @param array<string, int> $summary
 */
function zeffy_sync_log_sync_completed(array $summary, string $context = ''): void
{
    zeffy_sync_append_detailed_log(
        'Sync completed.',
        'info',
        [
            'summary' => [
                'total' => (int) ($summary['total'] ?? 0),
                'created' => (int) ($summary['created'] ?? 0),
                'updated' => (int) ($summary['updated'] ?? 0),
                'skipped' => (int) ($summary['skipped'] ?? 0),
            ],
            'context' => $context !== '' ? sanitize_text_field($context) : '',
        ]
    );

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
function zeffy_sync_get_endpoint_candidates(string $endpoint): array
{
    $normalized = rtrim(trim($endpoint), '/');
    if ($normalized === '') {
        $normalized = rtrim(ZEFFY_SYNC_DEFAULT_ENDPOINT, '/');
    }

    $candidates = [
        $normalized,
        $normalized . '/',
    ];

    // Keep backward compatibility for known Zeffy host/path variants.
    $known_campaign_endpoints = [
        'https://api.zeffy.com/api/v1/campaigns',
        'https://api.zeffy.com/v1/campaigns',
        'https://www.zeffy.com/api/v1/campaigns',
    ];
    if (in_array($normalized, $known_campaign_endpoints, true)) {
        foreach ($known_campaign_endpoints as $known_endpoint) {
            $candidates[] = $known_endpoint;
            $candidates[] = $known_endpoint . '/';
        }
    }

    return array_values(array_unique($candidates));
}

/**
 * @return array<int, array<string, mixed>>|WP_Error
 */
function zeffy_sync_fetch_campaigns(string $api_key, string $endpoint)
{
    $endpoint_candidates = zeffy_sync_get_endpoint_candidates($endpoint);
    $masked_api_key = zeffy_sync_mask_secret($api_key);
    $last_http_error = null;

    foreach ($endpoint_candidates as $endpoint_index => $current_endpoint) {
        $all_campaigns = [];
        $cursor = null;
        $page = 1;

        do {
            $url = $current_endpoint;
            if ($cursor !== null) {
                $url = add_query_arg('starting_after', $cursor, $current_endpoint);
            }

            zeffy_sync_append_detailed_log(
                'Sending request to Zeffy campaigns endpoint.',
                'info',
                [
                    'page' => $page,
                    'request' => [
                        'method' => 'GET',
                        'url' => $url,
                        'headers' => [
                            'Authorization' => 'Bearer ' . $masked_api_key,
                            'Accept' => 'application/json',
                        ],
                        'timeout' => 20,
                    ],
                ]
            );

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
                zeffy_sync_append_detailed_log(
                    'Zeffy request failed with transport error.',
                    'error',
                    [
                        'page' => $page,
                        'error_code' => $response->get_error_code(),
                        'error_message' => $response->get_error_message(),
                    ]
                );
                return $response;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            zeffy_sync_append_detailed_log(
                'Received Zeffy API response.',
                ($status >= 200 && $status < 300) ? 'info' : 'error',
                [
                    'page' => $page,
                    'response' => [
                        'status' => $status,
                        'body' => zeffy_sync_truncate_log_body($body),
                    ],
                ]
            );

            if ($status < 200 || $status >= 300) {
                $last_http_error = new WP_Error(
                    'zeffy_sync_http_error',
                    'Failed to fetch Zeffy campaigns.',
                    ['status' => $status]
                );

                $has_another_candidate = ($endpoint_index + 1) < count($endpoint_candidates);
                if ($status === 404 && $page === 1 && $has_another_candidate) {
                    zeffy_sync_append_detailed_log(
                        'Zeffy endpoint returned 404; trying next endpoint candidate.',
                        'warning',
                        [
                            'failed_endpoint' => $current_endpoint,
                            'next_endpoint' => $endpoint_candidates[$endpoint_index + 1],
                        ]
                    );
                    continue 2;
                }

                return $last_http_error;
            }

            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                zeffy_sync_append_detailed_log(
                    'Zeffy response JSON decoding failed or returned non-array.',
                    'error',
                    ['page' => $page]
                );
                return new WP_Error('zeffy_sync_invalid_response', 'Unexpected Zeffy campaigns response shape.');
            }

            if (array_values($decoded) === $decoded) {
                // Plain array response — no pagination support
                zeffy_sync_append_detailed_log(
                    'Zeffy response is a plain array and will be processed directly.',
                    'info',
                    ['page' => $page, 'item_count' => count($decoded)]
                );
                return zeffy_sync_extract_events($decoded);
            }

            if (isset($decoded['data']) && is_array($decoded['data'])) {
                $all_campaigns = array_merge($all_campaigns, $decoded['data']);
                $has_more = !empty($decoded['has_more']);
                $cursor = ($has_more && isset($decoded['next_cursor']) && is_string($decoded['next_cursor']))
                    ? $decoded['next_cursor']
                    : null;
                zeffy_sync_append_detailed_log(
                    'Processed paginated Zeffy response page.',
                    'info',
                    [
                        'page' => $page,
                        'page_item_count' => count($decoded['data']),
                        'accumulated_item_count' => count($all_campaigns),
                        'has_more' => (bool) $has_more,
                        'next_cursor' => $cursor,
                    ]
                );
                if (!$has_more) {
                    break;
                }
            } else {
                // Fallback: try other known keys (non-paginated APIs)
                foreach (['campaigns', 'results', 'events'] as $key) {
                    if (isset($decoded[$key]) && is_array($decoded[$key])) {
                        zeffy_sync_append_detailed_log(
                            'Processed non-standard Zeffy response key.',
                            'info',
                            ['page' => $page, 'response_key' => $key, 'item_count' => count($decoded[$key])]
                        );
                        return zeffy_sync_extract_events($decoded[$key]);
                    }
                }
                zeffy_sync_append_detailed_log(
                    'Zeffy response shape is unsupported.',
                    'error',
                    ['page' => $page]
                );
                return new WP_Error('zeffy_sync_invalid_response', 'Unexpected Zeffy campaigns response shape.');
            }

            $page++;
        } while ($cursor !== null);

        zeffy_sync_append_detailed_log(
            'Finished collecting paginated Zeffy campaigns.',
            'info',
            ['total_items' => count($all_campaigns), 'endpoint' => $current_endpoint]
        );
        return zeffy_sync_extract_events($all_campaigns);
    }

    if ($last_http_error instanceof WP_Error) {
        return $last_http_error;
    }

    return new WP_Error('zeffy_sync_http_error', 'Failed to fetch Zeffy campaigns.', ['status' => 0]);
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
        $campaign['title'] ?? null,
        $campaign['event_name'] ?? null,
        $campaign['name'] ?? null,
        'Zeffy event ' . $campaign_id
    );
    $content = zeffy_sync_extract_campaign_content($campaign);

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
    $zeffy_url = zeffy_sync_normalize_campaign_url($campaign);
    $campaign_type = (isset($campaign['type']) && is_string($campaign['type'])) ? $campaign['type'] : '';
    $campaign_category = zeffy_sync_normalize_campaign_category($campaign);
    $banner_url = (isset($campaign['banner_url']) && is_string($campaign['banner_url'])) ? $campaign['banner_url'] : '';

    if (!isset(zeffy_sync_campaign_category_map()[$campaign_category])) {
        return new WP_Error(
            'zeffy_sync_unsupported_campaign_category',
            'Campaign category is not supported for import.',
            ['category' => $campaign_category]
        );
    }

    // Compute start/end from occurrences (prefer non-archived occurrences).
    $start_date = null;
    $end_date = null;
    if (isset($campaign['occurrences']) && is_array($campaign['occurrences'])) {
        $occurrences = $campaign['occurrences'];
        $active_occurrences = array_values(array_filter($occurrences, static function ($occurrence): bool {
            return is_array($occurrence) && empty($occurrence['is_archived']);
        }));
        if (!empty($active_occurrences)) {
            $occurrences = $active_occurrences;
        }

        foreach ($occurrences as $occurrence) {
            if (!is_array($occurrence)) {
                continue;
            }
            $occ_start = zeffy_sync_normalize_unix_timestamp($occurrence['start_date'] ?? null);
            $occ_end = zeffy_sync_normalize_unix_timestamp($occurrence['end_date'] ?? null);
            if ($occ_start !== null && ($start_date === null || $occ_start < $start_date)) {
                $start_date = $occ_start;
            }
            if ($occ_end !== null && ($end_date === null || $occ_end > $end_date)) {
                $end_date = $occ_end;
            }
        }
    }
    // Fall back to campaign-level start_date/end_date if no occurrences
    if ($start_date === null) {
        $start_date = zeffy_sync_normalize_unix_timestamp($campaign['start_date'] ?? null);
    }
    if ($end_date === null) {
        $end_date = zeffy_sync_normalize_unix_timestamp($campaign['end_date'] ?? null);
    }

    return [
        'campaign_id'   => sanitize_text_field($campaign_id),
        'title'         => sanitize_text_field($title),
        'content'       => wp_kses_post($content),
        'status'        => $status,
        'api_status'    => sanitize_key($api_status),
        'is_archived'   => !empty($campaign['is_archived']),
        'zeffy_url'     => esc_url_raw($zeffy_url),
        'campaign_type' => sanitize_text_field($campaign_type),
        'campaign_category' => sanitize_text_field($campaign_category),
        'banner_url'    => esc_url_raw($banner_url),
        'start_date'    => $start_date,
        'end_date'      => $end_date,
        'raw_campaign'  => $campaign,
    ];
}

/**
 * @param array<string, mixed> $campaign
 */
function zeffy_sync_normalize_campaign_category(array $campaign): string
{
    $campaign_map = zeffy_sync_campaign_category_map();
    $aliases = [
        'event' => 'Event',
        'events' => 'Event',
        'donation' => 'DonationForm',
        'donations' => 'DonationForm',
        'donationform' => 'DonationForm',
        'membership' => 'MembershipV2',
        'membershipv2' => 'MembershipV2',
    ];

    $candidates = [
        $campaign['category'] ?? null,
        $campaign['campaign_category'] ?? null,
        $campaign['type'] ?? null,
        $campaign['campaign_type'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        if (!is_scalar($candidate)) {
            continue;
        }
        $value = trim((string) $candidate);
        if ($value === '') {
            continue;
        }

        if (isset($campaign_map[$value])) {
            return $value;
        }

        $normalized = strtolower(str_replace([' ', '-', '_'], '', $value));
        if (isset($aliases[$normalized])) {
            return $aliases[$normalized];
        }
    }

    return '';
}

/**
 * @param array<string, mixed> $campaign
 */
function zeffy_sync_extract_campaign_content(array $campaign): string
{
    return zeffy_sync_first_non_empty(
        $campaign['description'] ?? null,
        $campaign['details'] ?? null,
        $campaign['summary'] ?? null,
        $campaign['content'] ?? null,
        $campaign['body'] ?? null,
        $campaign['long_description'] ?? null,
        $campaign['excerpt'] ?? null
    );
}

/**
 * @param array<string, mixed> $campaign
 */
function zeffy_sync_normalize_campaign_url(array $campaign): string
{
    $source_url = (isset($campaign['url']) && is_string($campaign['url'])) ? trim($campaign['url']) : '';
    if ($source_url === '') {
        return '';
    }

    $parsed = wp_parse_url($source_url);
    if (!is_array($parsed)) {
        return $source_url;
    }

    $host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
    if ($host === '') {
        return $source_url;
    }

    $path = isset($parsed['path']) ? trim((string) $parsed['path'], '/') : '';
    if ($path === '') {
        return $source_url;
    }

    $segments = array_values(array_filter(explode('/', $path), static function ($segment): bool {
        return $segment !== '';
    }));
    $slug = end($segments);
    if (!is_string($slug) || $slug === '') {
        return $source_url;
    }

    $path_type = 'ticketing';

    $locale = (isset($campaign['locale']) && is_string($campaign['locale'])) ? trim($campaign['locale']) : '';
    $currency = (isset($campaign['currency']) && is_string($campaign['currency'])) ? trim($campaign['currency']) : '';
    $locale_segment = zeffy_sync_map_locale_for_url($locale, $currency);

    return sprintf('https://www.zeffy.com/%s/%s/%s', $locale_segment, $path_type, rawurlencode($slug));
}

function zeffy_sync_map_locale_for_url(string $locale, string $currency): string
{
    $locale = strtoupper($locale);
    $currency = strtolower($currency);

    if ($currency === 'cad') {
        if ($locale === 'FR') {
            return 'fr-CA';
        }
        return 'en-CA';
    }

    if ($locale === 'FR') {
        return 'fr';
    }
    if ($locale === 'EN') {
        return 'en';
    }

    $normalized = trim(strtolower(str_replace('_', '-', $locale)));
    return ($normalized !== '') ? $normalized : 'en';
}

/**
 * @param mixed $value
 */
function zeffy_sync_normalize_unix_timestamp($value): ?int
{
    if ($value === null) {
        return null;
    }

    if (is_numeric($value)) {
        $timestamp = (int) $value;
        if ($timestamp > 9999999999) {
            $timestamp = (int) floor($timestamp / 1000);
        }

        return ($timestamp > 0) ? $timestamp : null;
    }

    if (is_string($value)) {
        $parsed = strtotime($value);
        if ($parsed !== false && $parsed > 0) {
            return $parsed;
        }
    }

    return null;
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

        if ($postarr['post_content'] === '') {
            $existing_post = get_post($existing_id);
            if ($existing_post instanceof WP_Post && is_string($existing_post->post_content)) {
                $postarr['post_content'] = $existing_post->post_content;
            }
        }

        $postarr['ID'] = $existing_id;
        $post_id = wp_update_post($postarr, true);
        if (is_wp_error($post_id)) {
            return 'skipped';
        }

        zeffy_sync_write_post_meta((int) $post_id, $normalized);
        zeffy_sync_sync_campaign_terms(
            (int) $post_id,
            (string) $normalized['campaign_category'],
            isset($normalized['start_date']) && is_int($normalized['start_date']) ? $normalized['start_date'] : null,
            isset($normalized['end_date']) && is_int($normalized['end_date']) ? $normalized['end_date'] : null,
            isset($normalized['api_status']) && is_string($normalized['api_status']) ? $normalized['api_status'] : '',
            !empty($normalized['is_archived'])
        );
        zeffy_sync_set_featured_image_from_url((int) $post_id, (string) $normalized['banner_url'], (string) $normalized['title']);
        return 'updated';
    }

    $post_id = wp_insert_post($postarr, true);
    if (is_wp_error($post_id)) {
        return 'skipped';
    }

    zeffy_sync_write_post_meta((int) $post_id, $normalized);
    zeffy_sync_sync_campaign_terms(
        (int) $post_id,
        (string) $normalized['campaign_category'],
        isset($normalized['start_date']) && is_int($normalized['start_date']) ? $normalized['start_date'] : null,
        isset($normalized['end_date']) && is_int($normalized['end_date']) ? $normalized['end_date'] : null,
        isset($normalized['api_status']) && is_string($normalized['api_status']) ? $normalized['api_status'] : '',
        !empty($normalized['is_archived'])
    );
    zeffy_sync_set_featured_image_from_url((int) $post_id, (string) $normalized['banner_url'], (string) $normalized['title']);
    return 'created';
}

/**
 * @param array<string, mixed> $normalized
 */
function zeffy_sync_write_post_meta(int $post_id, array $normalized): void
{
    update_post_meta($post_id, '_zeffy_campaign_id', $normalized['campaign_id']);

    if (isset($normalized['zeffy_url'])) {
        if ($normalized['zeffy_url'] !== '') {
            update_post_meta($post_id, '_zeffy_url', $normalized['zeffy_url']);
            update_post_meta($post_id, 'zeffy_url', $normalized['zeffy_url']);
        } else {
            delete_post_meta($post_id, '_zeffy_url');
            delete_post_meta($post_id, 'zeffy_url');
        }
    }

    if (isset($normalized['campaign_type']) && $normalized['campaign_type'] !== '') {
        update_post_meta($post_id, '_zeffy_campaign_type', $normalized['campaign_type']);
    }

    if (isset($normalized['api_status']) && $normalized['api_status'] !== '') {
        update_post_meta($post_id, '_zeffy_api_status', $normalized['api_status']);
    }

    if (array_key_exists('is_archived', $normalized)) {
        update_post_meta($post_id, '_zeffy_is_archived', !empty($normalized['is_archived']) ? 1 : 0);
    }

    if (isset($normalized['campaign_category']) && $normalized['campaign_category'] !== '') {
        update_post_meta($post_id, '_zeffy_campaign_category', $normalized['campaign_category']);
    }

    if (isset($normalized['banner_url'])) {
        if ($normalized['banner_url'] !== '') {
            update_post_meta($post_id, '_zeffy_banner_url', $normalized['banner_url']);
        } else {
            delete_post_meta($post_id, '_zeffy_banner_url');
        }
    }

    if (isset($normalized['start_date']) && $normalized['start_date'] !== null) {
        update_post_meta($post_id, '_zeffy_start_date', (int) $normalized['start_date']);
    }

    if (isset($normalized['end_date']) && $normalized['end_date'] !== null) {
        update_post_meta($post_id, '_zeffy_end_date', (int) $normalized['end_date']);
    }

    if (isset($normalized['raw_campaign']) && is_array($normalized['raw_campaign'])) {
        zeffy_sync_write_raw_campaign_meta($post_id, $normalized['raw_campaign']);
    }
}

/**
 * @param array<string, mixed> $campaign
 */
function zeffy_sync_write_raw_campaign_meta(int $post_id, array $campaign): void
{
    $campaign_json = wp_json_encode($campaign, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($campaign_json)) {
        update_post_meta($post_id, '_zeffy_campaign_json', $campaign_json);
    }

    foreach ($campaign as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }

        $meta_key = '_zeffy_raw_' . sanitize_key($key);
        if ($meta_key === '_zeffy_raw_') {
            continue;
        }

        if ($value === null) {
            delete_post_meta($post_id, $meta_key);
            continue;
        }

        if (is_scalar($value)) {
            update_post_meta($post_id, $meta_key, $value);
            continue;
        }

        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            update_post_meta($post_id, $meta_key, $encoded);
        }
    }
}

function zeffy_sync_sync_campaign_terms(
    int $post_id,
    string $campaign_category,
    ?int $start_date = null,
    ?int $end_date = null,
    string $api_status = '',
    bool $is_archived = false
): void
{
    $campaign_map = zeffy_sync_campaign_category_map();
    if (!isset($campaign_map[$campaign_category])) {
        return;
    }

    $post_type = get_post_type($post_id);
    if (!is_string($post_type) || $post_type === '') {
        return;
    }

    $known_category_terms = array_map(
        static function (array $item): string {
            return $item['category'];
        },
        $campaign_map
    );
    $known_tag_terms = array_map(
        static function (array $item): string {
            return $item['tag'];
        },
        $campaign_map
    );

    if (taxonomy_exists('category') && is_object_in_taxonomy($post_type, 'category')) {
        wp_remove_object_terms($post_id, $known_category_terms, 'category');
        wp_set_object_terms($post_id, [$campaign_map[$campaign_category]['category']], 'category', false);
    }

    if (taxonomy_exists('post_tag') && is_object_in_taxonomy($post_type, 'post_tag')) {
        $lifecycle_tags = zeffy_sync_calculate_lifecycle_tags($start_date, $end_date, $api_status, $is_archived);
        $known_lifecycle_tags = ['Active', 'Ongoing', 'Ended'];
        wp_remove_object_terms($post_id, $known_tag_terms, 'post_tag');
        wp_remove_object_terms($post_id, $known_lifecycle_tags, 'post_tag');
        wp_set_object_terms($post_id, [$campaign_map[$campaign_category]['tag']], 'post_tag', true);
        if (!empty($lifecycle_tags)) {
            wp_set_object_terms($post_id, $lifecycle_tags, 'post_tag', true);
        }
    }
}

/**
 * @return array<int, string>
 */
function zeffy_sync_calculate_lifecycle_tags(
    ?int $start_date,
    ?int $end_date,
    string $api_status = '',
    bool $is_archived = false
): array
{
    if ($start_date === null && $end_date === null) {
        if (!$is_archived && strtolower($api_status) === 'active') {
            return ['Active', 'Ongoing'];
        }
        return [];
    }

    $now = (int) current_time('timestamp', true);
    if ($end_date !== null && $now > $end_date) {
        if (!$is_archived && strtolower($api_status) === 'active') {
            return ['Active', 'Ongoing'];
        }
        return ['Ended'];
    }

    if ($start_date !== null && $now < $start_date) {
        return ['Active'];
    }

    return ['Active', 'Ongoing'];
}

function zeffy_sync_set_featured_image_from_url(int $post_id, string $banner_url, string $title = ''): void
{
    if ($banner_url === '' || !filter_var($banner_url, FILTER_VALIDATE_URL)) {
        return;
    }

    $current_banner_url = (string) get_post_meta($post_id, '_zeffy_banner_url', true);
    $current_thumbnail = get_post_thumbnail_id($post_id);
    if ($current_banner_url === $banner_url && !empty($current_thumbnail)) {
        return;
    }

    if (!function_exists('media_handle_sideload')) {
        require_once ABSPATH . 'wp-admin/includes/media.php';
    }
    if (!function_exists('wp_generate_attachment_metadata')) {
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
    if (!function_exists('download_url')) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
    }

    $temp_file = download_url($banner_url, 20);
    if (is_wp_error($temp_file)) {
        return;
    }

    $path = parse_url($banner_url, PHP_URL_PATH);
    $filename = is_string($path) ? basename($path) : '';
    if ($filename === '') {
        $filename = sanitize_title($title !== '' ? $title : 'zeffy-banner') . '.jpg';
    }

    $file_array = [
        'name' => sanitize_file_name($filename),
        'tmp_name' => $temp_file,
    ];

    $attachment_id = media_handle_sideload($file_array, $post_id, $title);
    if (is_wp_error($attachment_id)) {
        @unlink($temp_file);
        return;
    }

    set_post_thumbnail($post_id, (int) $attachment_id);
    update_post_meta($post_id, '_zeffy_banner_url', esc_url_raw($banner_url));
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
