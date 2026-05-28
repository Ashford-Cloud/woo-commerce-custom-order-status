<?php
/**
 * Plugin Name: Ashford Woo Custom Statuses
 * Description: Adds and manages custom WooCommerce order statuses from a simple admin screen.
 * Version: 1.2.0
 * Author: Jim Saunders
 * Author URI: https://ashford.cloud
 * Plugin URI: https://github.com/Ashford-Cloud/woo-commerce-custom-order-status
 * Requires Plugins: woocommerce
 * Text Domain: ashford-woo-custom-statuses
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Ashford_Woo_Custom_Statuses {
    const OPTION_KEY = 'ashford_woo_custom_statuses_settings';
    const DONATE_OPTION_KEY = 'ashford_woo_custom_statuses_donate_url';
    const VERSION = '1.2.0';
    const PLUGIN_SLUG = 'ashford-woo-custom-statuses';
    const NONCE_ACTION = 'ashford_woo_custom_statuses_action';
    const NONCE_NAME = 'ashford_woo_custom_statuses_nonce';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', [$this, 'boot']);
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_plugin_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_github_update_folder'], 10, 4);
    }

    public function activate() {
        $current = get_option(self::OPTION_KEY, []);
        if (!is_array($current)) {
            $current = [];
        }
        update_option(self::OPTION_KEY, $this->merge_with_defaults($current));
    }

    public function boot() {
        add_action('init', [$this, 'register_statuses']);
        add_filter('wc_order_statuses', [$this, 'add_statuses_to_woocommerce']);
        add_filter('bulk_actions-edit-shop_order', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle_bulk_actions'], 10, 3);
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_notices', [$this, 'admin_notices']);
    }

    private function default_statuses() {
        return [
            'awaiting-parts' => [
                'label'       => 'Awaiting Parts',
                'description' => 'Use when an order is waiting for parts or components before dispatch.',
                'enabled'     => 1,
                'insert_after' => 'wc-processing',
                'protected'   => 1,
            ],
            'pending-refund' => [
                'label'       => 'Pending Refund',
                'description' => 'Use when a refund is being checked, prepared, or awaiting approval.',
                'enabled'     => 1,
                'insert_after' => 'wc-refunded',
                'protected'   => 1,
            ],
        ];
    }

    private function core_statuses() {
        return [
            'wc-pending'    => ['label' => 'Pending payment', 'editable' => false],
            'wc-processing' => ['label' => 'Processing', 'editable' => false],
            'wc-on-hold'    => ['label' => 'On hold', 'editable' => false],
            'wc-completed'  => ['label' => 'Completed', 'editable' => false],
            'wc-cancelled'  => ['label' => 'Cancelled', 'editable' => false],
            'wc-refunded'   => ['label' => 'Refunded', 'editable' => false],
            'wc-failed'     => ['label' => 'Failed', 'editable' => false],
        ];
    }

    private function merge_with_defaults($saved) {
        $defaults = $this->default_statuses();
        $out = [];

        foreach ($defaults as $slug => $status) {
            $out[$slug] = array_merge($status, isset($saved[$slug]) && is_array($saved[$slug]) ? $saved[$slug] : []);
            $out[$slug]['protected'] = 1;
        }

        foreach ($saved as $slug => $status) {
            if (isset($out[$slug]) || !is_array($status)) {
                continue;
            }
            $clean_slug = $this->sanitize_slug($slug);
            if (!$clean_slug) {
                continue;
            }
            $out[$clean_slug] = [
                'label'       => sanitize_text_field($status['label'] ?? ucwords(str_replace('-', ' ', $clean_slug))),
                'description' => sanitize_text_field($status['description'] ?? ''),
                'enabled'     => !empty($status['enabled']) ? 1 : 0,
                'insert_after' => sanitize_key($status['insert_after'] ?? 'wc-processing'),
                'protected'   => !empty($status['protected']) ? 1 : 0,
            ];
        }

        return $out;
    }

    private function get_settings() {
        $saved = get_option(self::OPTION_KEY, []);
        return $this->merge_with_defaults(is_array($saved) ? $saved : []);
    }

    private function sanitize_slug($slug) {
        $slug = strtolower((string) $slug);
        $slug = preg_replace('/^wc-/', '', $slug);
        $slug = sanitize_title($slug);
        $slug = substr($slug, 0, 17); // wc- + slug must stay within WP's 20-char post_status limit.
        return trim($slug, '-');
    }

    private function sanitize_status_row($row, $existing_slug = '') {
        $slug = $existing_slug ? $this->sanitize_slug($existing_slug) : $this->sanitize_slug($row['slug'] ?? '');
        if (!$slug) {
            return false;
        }

        $label = sanitize_text_field($row['label'] ?? '');
        if ($label === '') {
            $label = ucwords(str_replace('-', ' ', $slug));
        }

        $insert_after = sanitize_key($row['insert_after'] ?? 'wc-processing');
        if (strpos($insert_after, 'wc-') !== 0) {
            $insert_after = 'wc-processing';
        }

        return [
            'slug'        => $slug,
            'label'       => $label,
            'description' => sanitize_text_field($row['description'] ?? ''),
            'enabled'     => !empty($row['enabled']) ? 1 : 0,
            'insert_after' => $insert_after,
            'protected'   => !empty($row['protected']) ? 1 : 0,
        ];
    }

    private function get_update_settings() {
        return [
            'enabled' => 1,
            'owner' => 'Ashford-Cloud',
            'repo' => 'woo-commerce-custom-order-status',
            'asset_name' => 'ashford-woo-custom-statuses.zip',
            'token' => '',
        ];
    }

    private function github_api_request($endpoint) {
        $settings = $this->get_update_settings();
        if (empty($settings['enabled']) || empty($settings['owner']) || empty($settings['repo'])) {
            return new WP_Error('ashford_no_repo', 'GitHub updater is not configured.');
        }

        $url = 'https://api.github.com/repos/' . rawurlencode($settings['owner']) . '/' . rawurlencode($settings['repo']) . $endpoint;
        $args = [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/vnd.github+json',
                'User-Agent' => 'Ashford-Woo-Custom-Statuses/' . self::VERSION,
            ],
        ];
        if (!empty($settings['token'])) {
            $args['headers']['Authorization'] = 'Bearer ' . $settings['token'];
        }

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('ashford_github_error', 'GitHub returned HTTP ' . $code . '.');
        }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('ashford_bad_json', 'GitHub returned an unreadable response.');
        }
        return $body;
    }

    private function latest_release() {
        $cache_key = 'ashford_wc_statuses_latest_release';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $release = $this->github_api_request('/releases/latest');
        if (is_wp_error($release)) {
            return $release;
        }
        set_transient($cache_key, $release, 30 * MINUTE_IN_SECONDS);
        return $release;
    }

    private function release_version($release) {
        $tag = isset($release['tag_name']) ? (string) $release['tag_name'] : '';
        $tag = ltrim(trim($tag), 'vV');
        return $tag;
    }

    private function release_download_url($release) {
        $settings = $this->get_update_settings();
        if (!empty($settings['asset_name']) && !empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['name']) && $asset['name'] === $settings['asset_name'] && !empty($asset['browser_download_url'])) {
                    return $asset['browser_download_url'];
                }
            }
        }
        return !empty($release['zipball_url']) ? $release['zipball_url'] : '';
    }

    public function check_for_plugin_update($transient) {
        if (empty($transient) || !is_object($transient) || empty($transient->checked)) {
            return $transient;
        }
        $basename = plugin_basename(__FILE__);
        $release = $this->latest_release();
        if (is_wp_error($release)) {
            return $transient;
        }
        $new_version = $this->release_version($release);
        $package = $this->release_download_url($release);
        if (!$new_version || !$package || !version_compare($new_version, self::VERSION, '>')) {
            return $transient;
        }
        $transient->response[$basename] = (object) [
            'slug' => self::PLUGIN_SLUG,
            'plugin' => $basename,
            'new_version' => $new_version,
            'url' => !empty($release['html_url']) ? $release['html_url'] : 'https://ashford.cloud',
            'package' => $package,
            'tested' => '6.8',
            'requires' => '5.8',
        ];
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== self::PLUGIN_SLUG) {
            return $result;
        }
        $release = $this->latest_release();
        if (is_wp_error($release)) {
            return $result;
        }
        $version = $this->release_version($release);
        $body = !empty($release['body']) ? wp_kses_post($release['body']) : 'Release details are available on GitHub.';
        return (object) [
            'name' => 'Ashford Woo Custom Statuses',
            'slug' => self::PLUGIN_SLUG,
            'version' => $version ?: self::VERSION,
            'author' => '<a href="https://ashford.cloud">Jim Saunders</a>',
            'homepage' => !empty($release['html_url']) ? $release['html_url'] : 'https://ashford.cloud',
            'download_link' => $this->release_download_url($release),
            'sections' => [
                'description' => 'Adds and manages custom WooCommerce order statuses.',
                'changelog' => $body,
            ],
        ];
    }

    public function fix_github_update_folder($source, $remote_source, $upgrader, $hook_extra) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== plugin_basename(__FILE__)) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . self::PLUGIN_SLUG;
        if (basename($source) !== self::PLUGIN_SLUG && !file_exists($desired)) {
            global $wp_filesystem;
            if ($wp_filesystem) {
                $wp_filesystem->move($source, $desired);
                return $desired;
            }
        }
        return $source;
    }

    public function handle_admin_actions() {
        if (!is_admin() || empty($_POST['ashford_status_action'])) {
            return;
        }
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have permission to manage order statuses.');
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $action = sanitize_key($_POST['ashford_status_action']);
        $settings = $this->get_settings();
        $message = 'saved';

        if ($action === 'save_statuses') {
            $delete_slug = $this->sanitize_slug($_POST['ashford_delete_status_slug'] ?? '');
            if ($delete_slug) {
                if (isset($settings[$delete_slug]) && empty($settings[$delete_slug]['protected'])) {
                    unset($settings[$delete_slug]);
                    update_option(self::OPTION_KEY, $this->merge_with_defaults($settings));
                    $message = 'deleted';
                } else {
                    $message = 'cannot_delete';
                }
            } else {
                $posted = isset($_POST[self::OPTION_KEY]) && is_array($_POST[self::OPTION_KEY]) ? wp_unslash($_POST[self::OPTION_KEY]) : [];
                $new_settings = [];
                foreach ($settings as $slug => $existing) {
                    $row = isset($posted[$slug]) && is_array($posted[$slug]) ? $posted[$slug] : [];
                    $row['protected'] = !empty($existing['protected']) ? 1 : 0;
                    $clean = $this->sanitize_status_row($row, $slug);
                    if ($clean) {
                        unset($clean['slug']);
                        $new_settings[$slug] = $clean;
                    }
                }
                update_option(self::OPTION_KEY, $this->merge_with_defaults($new_settings));
            }
        }

        if ($action === 'save_donate') {
            $donate_url = esc_url_raw(trim((string) wp_unslash($_POST[self::DONATE_OPTION_KEY] ?? '')));
            if ($donate_url && !preg_match('#^https://(www\.)?(paypal\.me|paypal\.com)/#i', $donate_url)) {
                $message = 'bad_donate_url';
            } else {
                update_option(self::DONATE_OPTION_KEY, $donate_url);
                $message = 'donate_saved';
            }
        }



        if ($action === 'add_status') {
            $row = isset($_POST['ashford_new_status']) && is_array($_POST['ashford_new_status']) ? wp_unslash($_POST['ashford_new_status']) : [];
            $clean = $this->sanitize_status_row($row);
            if (!$clean) {
                $message = 'bad_slug';
            } elseif (isset($settings[$clean['slug']]) || isset($this->core_statuses()['wc-' . $clean['slug']])) {
                $message = 'exists';
            } else {
                $slug = $clean['slug'];
                unset($clean['slug']);
                $clean['protected'] = 0;
                $settings[$slug] = $clean;
                update_option(self::OPTION_KEY, $this->merge_with_defaults($settings));
                $message = 'added';
            }
        }

        if ($action === 'delete_status') {
            $slug = $this->sanitize_slug($_POST['status_slug'] ?? '');
            if (isset($settings[$slug]) && empty($settings[$slug]['protected'])) {
                unset($settings[$slug]);
                update_option(self::OPTION_KEY, $this->merge_with_defaults($settings));
                $message = 'deleted';
            } else {
                $message = 'cannot_delete';
            }
        }

        wp_safe_redirect(add_query_arg([
            'page' => 'ashford-custom-statuses',
            'ashford_status_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    public function register_statuses() {
        foreach ($this->get_settings() as $slug => $status) {
            if (empty($status['enabled'])) {
                continue;
            }
            $label = sanitize_text_field($status['label']);
            register_post_status('wc-' . $slug, [
                'label'                     => $label,
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop(
                    $label . ' <span class="count">(%s)</span>',
                    $label . ' <span class="count">(%s)</span>',
                    'ashford-woo-custom-statuses'
                ),
            ]);
        }
    }

    public function add_statuses_to_woocommerce($statuses) {
        foreach ($this->get_settings() as $slug => $status) {
            if (empty($status['enabled'])) {
                continue;
            }
            $statuses = $this->insert_status_after(
                $statuses,
                sanitize_key($status['insert_after'] ?? 'wc-processing'),
                'wc-' . $slug,
                sanitize_text_field($status['label'])
            );
        }
        return $statuses;
    }

    private function insert_status_after($statuses, $after_key, $new_key, $new_label) {
        if (isset($statuses[$new_key])) {
            return $statuses;
        }
        $new_statuses = [];
        $inserted = false;
        foreach ($statuses as $key => $label) {
            $new_statuses[$key] = $label;
            if ($key === $after_key) {
                $new_statuses[$new_key] = $new_label;
                $inserted = true;
            }
        }
        if (!$inserted) {
            $new_statuses[$new_key] = $new_label;
        }
        return $new_statuses;
    }

    public function add_bulk_actions($actions) {
        foreach ($this->get_settings() as $slug => $status) {
            if (!empty($status['enabled'])) {
                $actions['mark_' . $slug] = 'Change status to ' . sanitize_text_field($status['label']);
            }
        }
        return $actions;
    }

    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        foreach ($this->get_settings() as $slug => $status) {
            if ($action !== 'mark_' . $slug || empty($status['enabled'])) {
                continue;
            }
            foreach ($post_ids as $post_id) {
                $order = wc_get_order($post_id);
                if ($order) {
                    $order->update_status($slug, 'Order status changed by bulk action.');
                }
            }
            $redirect_to = add_query_arg([
                'ashford_status_changed' => count($post_ids),
                'ashford_status' => rawurlencode(sanitize_text_field($status['label'])),
            ], $redirect_to);
        }
        return $redirect_to;
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Custom Order Statuses',
            'Custom Statuses',
            'manage_woocommerce',
            'ashford-custom-statuses',
            [$this, 'render_admin_page']
        );
    }

    public function admin_notices() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p><strong>Ashford Woo Custom Statuses</strong> needs WooCommerce to be active.</p></div>';
        }
        if (empty($_GET['ashford_status_message'])) {
            return;
        }
        $messages = [
            'saved' => 'Custom statuses saved.',
            'added' => 'New custom status added.',
            'deleted' => 'Custom status deleted.',
            'bad_slug' => 'Please enter a valid status key.',
            'exists' => 'That status key already exists.',
            'cannot_delete' => 'That status cannot be deleted.',
            'donate_saved' => 'Donation button settings saved.',
            'bad_donate_url' => 'Please enter a valid PayPal URL, such as a PayPal.me or PayPal.com donation link.',
        ];
        $key = sanitize_key($_GET['ashford_status_message']);
        if (isset($messages[$key])) {
            $class = in_array($key, ['bad_slug', 'exists', 'cannot_delete', 'bad_donate_url'], true) ? 'notice-error' : 'notice-success';
            echo '<div class="notice ' . esc_attr($class) . ' is-dismissible"><p>' . esc_html($messages[$key]) . '</p></div>';
        }
    }

    public function render_admin_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('You do not have permission to view this page.');
        }

        $settings = $this->get_settings();
        $donate_url = esc_url(get_option(self::DONATE_OPTION_KEY, 'https://paypal.me/jimmistiles'));
        if (!$donate_url) {
            $donate_url = 'https://paypal.me/jimmistiles';
        }
        $core_statuses = $this->core_statuses();
        $insert_options = array_merge($core_statuses, array_map(function ($row) {
            return ['label' => $row['label'], 'editable' => true];
        }, $settings));
        ?>
        <div class="wrap ashford-status-wrap">
            <style>
                .ashford-status-wrap { max-width: 1240px; }
                .ashford-status-header { display:flex; align-items:flex-start; justify-content:space-between; gap:18px; margin:18px 0 16px; }
                .ashford-status-header h1 { margin:0 0 6px; font-size:28px; }
                .ashford-status-header p { margin:0; color:#646970; max-width:760px; }
                .ashford-header-actions { display:flex; gap:8px; align-items:center; flex-shrink:0; padding-top:4px; }
                .ashford-cloud-link { text-decoration:none; font-weight:700; }
                .ashford-support-mini { border:1px solid #d0d7de; background:#fff; color:#1d2327; border-radius:999px; padding:7px 12px; font-weight:700; cursor:pointer; display:inline-flex; align-items:center; gap:7px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
                .ashford-support-mini:hover { border-color:#2271b1; color:#0a4b78; }
                .ashford-dot { width:8px; height:8px; border-radius:50%; background:#ffc439; display:inline-block; box-shadow:0 0 0 3px rgba(255,196,57,.18); }
                .ashford-card { background:#fff; border:1px solid #dcdcde; border-radius:12px; box-shadow:0 1px 2px rgba(0,0,0,.035); margin:0 0 18px; overflow:hidden; }
                .ashford-card-header { padding:16px 18px; border-bottom:1px solid #eef0f2; display:flex; justify-content:space-between; align-items:center; gap:12px; }
                .ashford-card-header h2 { margin:0; font-size:18px; }
                .ashford-card-header p { margin:4px 0 0; color:#646970; }
                .ashford-card-body { padding:18px; }
                .ashford-add-panel { background:linear-gradient(135deg,#ffffff,#f8fafc); }
                .ashford-add-grid { display:grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap:14px; align-items:start; max-width:920px; }
                .ashford-form-field label { display:block; font-weight:700; margin-bottom:6px; }
                .ashford-form-field input[type="text"], .ashford-form-field select { width:100%; max-width:100%; min-height:38px; }
                .ashford-form-field .description { margin-top:6px; color:#646970; font-size:12px; }
                .ashford-add-actions { display:flex; gap:14px; align-items:center; padding-top:0; }
                .ashford-toggle-line { white-space:nowrap; color:#1d2327; }
                .ashford-status-table { width:100%; border-collapse:separate; border-spacing:0; }
                .ashford-status-table th { background:#f6f7f7; font-weight:700; text-align:left; padding:11px 12px; border-bottom:1px solid #dcdcde; }
                .ashford-status-table td { padding:12px; border-bottom:1px solid #eef0f2; vertical-align:middle; }
                .ashford-status-table tr:last-child td { border-bottom:none; }
                .ashford-status-table input.regular-text { width:100%; max-width:290px; }
                .ashford-status-table select { max-width:210px; }
                .ashford-code { font-family:Consolas, Monaco, monospace; background:#f6f7f7; padding:3px 6px; border-radius:5px; white-space:nowrap; }
                .ashford-pill { display:inline-block; padding:4px 9px; border-radius:999px; font-size:12px; font-weight:700; white-space:nowrap; margin:2px 3px 2px 0; }
                .ashford-pill-editable { background:#e6f4ea; color:#166534; }
                .ashford-pill-locked { background:#f3f4f6; color:#374151; }
                .ashford-pill-default { background:#e0f2fe; color:#075985; }
                .ashford-small { color:#646970; font-size:12px; }
                .ashford-update-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:14px; max-width:820px; }
                .ashford-update-grid .full { grid-column:1 / -1; }
                .ashford-footer-credit { margin:20px 0; color:#646970; }
                .ashford-footer-credit a { font-weight:700; text-decoration:none; }
                .ashford-donate-modal-backdrop { display:none; position:fixed; inset:0; background:rgba(17,24,39,.62); z-index:100000; align-items:center; justify-content:center; padding:20px; }
                .ashford-donate-modal-backdrop.is-open { display:flex; }
                .ashford-donate-modal { width:min(500px,100%); background:#fff; border-radius:18px; box-shadow:0 24px 70px rgba(0,0,0,.25); overflow:hidden; }
                .ashford-donate-modal-header { padding:21px 24px; background:linear-gradient(135deg,#f8fafc,#e0f2fe); border-bottom:1px solid #e5e7eb; }
                .ashford-donate-modal-header h2 { margin:0 0 6px; font-size:22px; }
                .ashford-donate-modal-header p { margin:0; color:#4b5563; }
                .ashford-donate-modal-body { padding:22px 24px; }
                .ashford-donate-amounts { display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:14px 0; }
                .ashford-donate-amount { border:1px solid #d0d7de; border-radius:12px; background:#fff; padding:12px 8px; font-weight:800; cursor:pointer; }
                .ashford-donate-amount.is-selected { border-color:#0070ba; box-shadow:0 0 0 2px rgba(0,112,186,.15); background:#eff6ff; }
                .ashford-donate-custom { display:flex; gap:8px; align-items:center; margin-top:10px; }
                .ashford-donate-custom input { max-width:140px; font-size:18px; padding:8px 10px; }
                .ashford-donate-modal-actions { display:flex; gap:10px; justify-content:flex-end; padding:18px 24px; background:#f9fafb; border-top:1px solid #e5e7eb; }
                .ashford-donate-button { display:inline-flex; gap:8px; align-items:center; background:#ffc439; color:#111827; padding:10px 14px; border-radius:8px; font-weight:800; text-decoration:none; border:1px solid #d99d00; cursor:pointer; box-shadow:0 2px 0 rgba(0,0,0,.08); }
                .ashford-donate-button:hover { color:#111827; background:#f2b600; }
                .ashford-paypal-logo { display:inline-flex; align-items:center; font-weight:900; letter-spacing:-.5px; color:#003087; }
                @media (max-width: 1100px) { .ashford-add-grid { grid-template-columns:1fr 1fr; } .ashford-add-actions { padding-top:0; } }
                @media (max-width: 782px) { .ashford-status-header { flex-direction:column; } .ashford-add-grid { grid-template-columns:1fr; } .ashford-status-table { display:block; overflow-x:auto; } }
            </style>

            <div class="ashford-status-header">
                <div>
                    <h1>Custom Order Statuses</h1>
                    <p>Add and manage WooCommerce order statuses. Core WooCommerce statuses are shown for reference and remain locked.</p>
                </div>
                <div class="ashford-header-actions">
                    <a class="ashford-cloud-link" href="https://ashford.cloud" target="_blank" rel="noopener noreferrer">Ashford.cloud</a>
                    <button type="button" class="ashford-support-mini" data-ashford-donate-open data-paypal-base="<?php echo esc_attr($donate_url); ?>"><span class="ashford-dot"></span> Donate</button>
                </div>
            </div>

            <div class="ashford-card ashford-add-panel">
                <div class="ashford-card-header">
                    <div>
                        <h2>Add new custom status</h2>
                        <p>Create a clean WooCommerce status key and choose where it appears in the order status list.</p>
                    </div>
                </div>
                <div class="ashford-card-body">
                    <form method="post" action="">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                        <input type="hidden" name="ashford_status_action" value="add_status">
                        <div class="ashford-add-grid">
                            <div class="ashford-form-field">
                                <label for="ashford-new-label">Display label</label>
                                <input id="ashford-new-label" type="text" name="ashford_new_status[label]" placeholder="Awaiting Cable Cut" autocomplete="off">
                                <div class="description">This is what you see in WooCommerce.</div>
                            </div>
                            <div class="ashford-form-field">
                                <label for="ashford-new-slug">Status key</label>
                                <input id="ashford-new-slug" type="text" name="ashford_new_status[slug]" placeholder="awaiting-cable-cut" maxlength="17" autocomplete="off">
                                <div class="description">Use lowercase letters, numbers and dashes. The plugin adds <code>wc-</code>.</div>
                            </div>
                            <div class="ashford-form-field">
                                <label for="ashford-new-description">Description</label>
                                <input id="ashford-new-description" type="text" name="ashford_new_status[description]" placeholder="Use when an order is waiting for cable cutting." autocomplete="off">
                                <div class="description">Optional admin note, shown only on this settings page.</div>
                            </div>
                            <div class="ashford-form-field">
                                <label for="ashford-new-after">Show after</label>
                                <select id="ashford-new-after" name="ashford_new_status[insert_after]">
                                    <?php foreach ($insert_options as $key => $status): ?>
                                        <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($status['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="description">Controls its position in dropdowns.</div>
                            </div>
                            <div class="ashford-add-actions">
                                <label class="ashford-toggle-line"><input type="checkbox" name="ashford_new_status[enabled]" value="1" checked> Enabled immediately</label>
                                <?php submit_button('Add status', 'primary', 'submit', false); ?>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                <input type="hidden" name="ashford_status_action" value="save_statuses">
                <div class="ashford-card">
                    <div class="ashford-card-header">
                        <div>
                            <h2>Editable custom statuses</h2>
                            <p>Change labels, descriptions and ordering. Status keys are fixed to protect existing orders.</p>
                        </div>
                    </div>
                    <table class="ashford-status-table">
                        <thead>
                            <tr>
                                <th>Enabled</th>
                                <th>Status key</th>
                                <th>Label</th>
                                <th>Description</th>
                                <th>Show after</th>
                                <th>Type</th>
                                <th>Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($settings as $slug => $status): ?>
                                <tr>
                                    <td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_KEY . '[' . $slug . '][enabled]'); ?>" value="1" <?php checked(!empty($status['enabled'])); ?>> Enabled</label></td>
                                    <td><span class="ashford-code">wc-<?php echo esc_html($slug); ?></span></td>
                                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY . '[' . $slug . '][label]'); ?>" value="<?php echo esc_attr($status['label']); ?>"></td>
                                    <td><input class="regular-text" type="text" name="<?php echo esc_attr(self::OPTION_KEY . '[' . $slug . '][description]'); ?>" value="<?php echo esc_attr($status['description']); ?>"></td>
                                    <td>
                                        <select name="<?php echo esc_attr(self::OPTION_KEY . '[' . $slug . '][insert_after]'); ?>">
                                            <?php foreach ($insert_options as $key => $option): if ($key === 'wc-' . $slug) { continue; } ?>
                                                <option value="<?php echo esc_attr($key); ?>" <?php selected($status['insert_after'], $key); ?>><?php echo esc_html($option['label']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <span class="ashford-pill ashford-pill-editable">Editable</span>
                                        <?php if (!empty($status['protected'])): ?><span class="ashford-pill ashford-pill-default">Default</span><?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (empty($status['protected'])): ?>
                                            <button class="button-link-delete" type="submit" name="ashford_delete_status_slug" value="<?php echo esc_attr($slug); ?>" onclick="return confirm('Delete this custom status from the plugin? Existing orders with this status may need manual review.');">Delete</button>
                                        <?php else: ?>
                                            <span class="ashford-small">Locked default</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="ashford-card-body">
                        <?php submit_button('Save custom statuses'); ?>
                    </div>
                </div>
            </form>

            <div class="ashford-card">
                <div class="ashford-card-header">
                    <div>
                        <h2>WooCommerce core statuses</h2>
                        <p>These are built into WooCommerce and are shown here only so you can see what cannot be edited.</p>
                    </div>
                </div>
                <table class="ashford-status-table">
                    <thead><tr><th>Status key</th><th>Label</th><th>Editable?</th></tr></thead>
                    <tbody>
                        <?php foreach ($core_statuses as $key => $status): ?>
                            <tr>
                                <td><span class="ashford-code"><?php echo esc_html($key); ?></span></td>
                                <td><?php echo esc_html($status['label']); ?></td>
                                <td><span class="ashford-pill ashford-pill-locked">Locked core status</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="ashford-card">
                <div class="ashford-card-header">
                    <div>
                        <h2>GitHub plugin updates</h2>
                        <p>This build is wired to your public GitHub repo. Publish releases with version tags like <code>v1.2.1</code> and WordPress will show the normal “update now” link.</p>
                    </div>
                </div>
                <div class="ashford-card-body">
                    <p><strong>Repository:</strong> <a href="https://github.com/Ashford-Cloud/woo-commerce-custom-order-status" target="_blank" rel="noopener noreferrer">Ashford-Cloud/woo-commerce-custom-order-status</a></p>
                    <p><strong>Expected release asset:</strong> <span class="ashford-code">ashford-woo-custom-statuses.zip</span></p>
                    <p class="ashford-small">Attach the zip file to each GitHub Release. If no matching asset is found, the plugin falls back to GitHub’s source zip.</p>
                </div>
            </div>

            <p class="ashford-footer-credit">Ashford Woo Custom Statuses by <strong>Jim Saunders</strong>. Visit <a href="https://ashford.cloud" target="_blank" rel="noopener noreferrer">Ashford.cloud</a>.</p>

            <div class="ashford-donate-modal-backdrop" data-ashford-donate-modal aria-hidden="true">
                <div class="ashford-donate-modal" role="dialog" aria-modal="true" aria-labelledby="ashford-donate-title">
                    <div class="ashford-donate-modal-header">
                        <h2 id="ashford-donate-title">Support this freeware plugin</h2>
                        <p>This plugin is freeware. If it has been useful to you, please feel free to support the developer, Jim Saunders, with a voluntary donation.</p>
                    </div>
                    <div class="ashford-donate-modal-body">
                        <strong>Select an amount</strong>
                        <div class="ashford-donate-amounts" role="group" aria-label="Donation amount">
                            <button type="button" class="ashford-donate-amount is-selected" data-amount="3">£3</button>
                            <button type="button" class="ashford-donate-amount" data-amount="5">£5</button>
                            <button type="button" class="ashford-donate-amount" data-amount="10">£10</button>
                            <button type="button" class="ashford-donate-amount" data-amount="20">£20</button>
                        </div>
                        <label class="ashford-donate-custom"><span>Custom £</span><input type="number" min="1" step="1" inputmode="numeric" data-ashford-custom-amount placeholder="Other"></label>
                        <p class="ashford-small">You will be sent to PayPal.me to complete the donation securely.</p>
                    </div>
                    <div class="ashford-donate-modal-actions">
                        <button type="button" class="button" data-ashford-donate-close>Cancel</button>
                        <button type="button" class="ashford-donate-button" data-ashford-donate-go data-paypal-base="<?php echo esc_attr($donate_url); ?>"><span class="ashford-paypal-logo">PayPal</span> Donate</button>
                    </div>
                </div>
            </div>

            <script>
                (function(){
                    const modal = document.querySelector('[data-ashford-donate-modal]');
                    if (!modal) return;
                    let selectedAmount = '3';
                    const openButtons = document.querySelectorAll('[data-ashford-donate-open]');
                    const closeButtons = document.querySelectorAll('[data-ashford-donate-close]');
                    const amountButtons = modal.querySelectorAll('[data-amount]');
                    const customInput = modal.querySelector('[data-ashford-custom-amount]');
                    const goButton = modal.querySelector('[data-ashford-donate-go]');
                    const baseUrl = goButton ? goButton.getAttribute('data-paypal-base') : 'https://paypal.me/jimmistiles';

                    function openModal(){ modal.classList.add('is-open'); modal.setAttribute('aria-hidden','false'); }
                    function closeModal(){ modal.classList.remove('is-open'); modal.setAttribute('aria-hidden','true'); }
                    function cleanBase(url){ return (url || 'https://paypal.me/jimmistiles').replace(/\/+$/, ''); }
                    function cleanAmount(value){
                        const n = parseFloat(String(value || '').replace(/[^0-9.]/g, ''));
                        if (!isFinite(n) || n <= 0) return '3';
                        return String(Math.round(n * 100) / 100);
                    }

                    openButtons.forEach(btn => btn.addEventListener('click', openModal));
                    closeButtons.forEach(btn => btn.addEventListener('click', closeModal));
                    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
                    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

                    amountButtons.forEach(btn => btn.addEventListener('click', function(){
                        amountButtons.forEach(b => b.classList.remove('is-selected'));
                        this.classList.add('is-selected');
                        selectedAmount = this.getAttribute('data-amount') || '3';
                        if (customInput) customInput.value = '';
                    }));
                    if (customInput) {
                        customInput.addEventListener('input', function(){
                            amountButtons.forEach(b => b.classList.remove('is-selected'));
                            selectedAmount = this.value;
                        });
                    }
                    if (goButton) {
                        goButton.addEventListener('click', function(){
                            const amount = cleanAmount(customInput && customInput.value ? customInput.value : selectedAmount);
                            window.open(cleanBase(baseUrl) + '/' + encodeURIComponent(amount), '_blank', 'noopener');
                        });
                    }
                })();
            </script>
        </div>
        <?php
    }

}

Ashford_Woo_Custom_Statuses::instance();
