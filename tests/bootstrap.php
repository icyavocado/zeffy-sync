<?php
// Minimal test bootstrap to allow including plugin file without full WP.
// Define ABSPATH and no-op stubs for WordPress functions used at file load.

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

// No-op stubs for functions called at plugin include time.
function register_activation_hook(...$args) { }
function register_deactivation_hook(...$args) { }
function add_action(...$args) { }
function add_filter(...$args) { }
function add_menu_page(...$args) { }
function register_setting(...$args) { }
function plugin_basename(...$args) { return ''; }
function get_bloginfo(...$args) { return 'test'; }
function home_url(...$args) { return 'https://example.test'; }
function is_object_in_taxonomy(...$args) { return false; }
function taxonomy_exists(...$args) { return false; }

// Minimal sanitizer and helper stubs used by plugin functions under test.
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($value)
    {
        if (!is_scalar($value)) {
            return '';
        }
        return trim((string) $value);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post($value)
    {
        // For tests, keep value as-is. Real WP strips disallowed HTML.
        return is_scalar($value) ? (string) $value : '';
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url)
    {
        if (!is_scalar($url)) {
            return '';
        }
        return filter_var((string) $url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key($key)
    {
        $k = strtolower(trim((string) $key));
        // Allow a-z, 0-9, _ and -
        return preg_replace('/[^a-z0-9_\-]/', '', $k);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        private $errors = [];
        private $error_data = [];

        public function __construct($code = '', $message = '', $data = null)
        {
            if ($code !== '') {
                $this->errors[(string) $code] = [$message];
                $this->error_data[(string) $code] = $data;
            }
        }

        public function get_error_code()
        {
            $keys = array_keys($this->errors);
            return $keys[0] ?? '';
        }

        public function get_error_message()
        {
            $code = $this->get_error_code();
            if ($code === '') {
                return '';
            }
            $msgs = $this->errors[$code];
            return is_array($msgs) ? ($msgs[0] ?? '') : (string) $msgs;
        }

        public function get_error_data()
        {
            $code = $this->get_error_code();
            return $this->error_data[$code] ?? null;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }
}

// Simple in-memory option storage for tests
$__wp_options = [];

if (!function_exists('get_option')) {
    function get_option($option, $default = null)
    {
        global $__wp_options;
        return array_key_exists($option, $__wp_options) ? $__wp_options[$option] : $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = true)
    {
        global $__wp_options;
        $__wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option)
    {
        global $__wp_options;
        if (array_key_exists($option, $__wp_options)) {
            unset($__wp_options[$option]);
        }
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type = 'mysql', $gmt = 0)
    {
        if ($type === 'timestamp') {
            return $gmt ? time() : time();
        }
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($value)
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}

// Provide wp_parse_url wrapper used by plugin. Map to PHP parse_url for tests.
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url)
    {
        $parsed = parse_url($url);
        if ($parsed === false) {
            return null;
        }
        return $parsed;
    }
}

// Minimal HTTP stubs for tests. Return mock campaigns payload when available.
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = [])
    {
        $mock = __DIR__ . '/../__mocks__/list-campaigns.json';
        if (is_file($mock)) {
            $body = file_get_contents($mock);
            return [
                'response' => ['code' => 200],
                'body' => $body,
            ];
        }

        return new WP_Error('mock_missing', 'Mock response not found');
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response)
    {
        if (is_wp_error($response)) {
            return 0;
        }
        if (is_array($response) && isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }
        return 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response)
    {
        if (is_wp_error($response)) {
            return '';
        }
        return is_array($response) && isset($response['body']) ? (string) $response['body'] : '';
    }
}

// Include plugin file under test.
require_once __DIR__ . '/../zeffy-sync.php';
