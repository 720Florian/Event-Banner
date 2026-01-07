<?php
/**
 * Plugin Name: Event Banner
 * Description: Display scheduled or manually activated event banners with optional links.
 * Version: 1.0.0
 * Author: 720Florian
 * License: GPL-2.0-or-later
 * Text Domain: event-banner
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

class CGPT_Event_Banner {
    const POST_TYPE = 'event_banner';
    const META_TEXT = '_event_banner_text';
    const META_LINK = '_event_banner_link';
    const META_START = '_event_banner_start';
    const META_END = '_event_banner_end';
    const META_MANUAL = '_event_banner_manual';
    const OPTION_NAME = 'event_banner_options';
    const OPTION_GROUP = 'event_banner_settings';
    const LOG_PREFIX = '[Event Banner] ';
    const OPTION_LOGS = 'event_banner_logs';
    const LOG_MAX = 20;
    const OPTION_ACTIVE = 'event_banner_active_id';

    public function __construct() {
        add_action('plugins_loaded', [$this, 'load_textdomain']);
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'register_metaboxes']);
        add_action('save_post', [$this, 'save_metabox'], 10, 2);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);

        add_action('admin_menu', [$this, 'register_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), [$this, 'add_settings_link']);
        add_action('admin_notices', [$this, 'render_admin_notices']);
        add_action('admin_post_event_banner_clear_logs', [$this, 'handle_clear_logs']);

        add_action('wp_body_open', [$this, 'render_active_banner']);
        add_action('wp_footer', [$this, 'render_active_banner']);

        add_shortcode('event_banner', [$this, 'shortcode']);
    }

    public function load_textdomain() {
        load_plugin_textdomain('event-banner', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_post_type() {
        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Event Banners', 'event-banner'),
                'singular_name' => __('Event Banner', 'event-banner'),
                'add_new_item' => __('Add New Event Banner', 'event-banner'),
                'edit_item' => __('Edit Event Banner', 'event-banner'),
            ],
            'public' => false,
            'show_ui' => true,
            'menu_icon' => 'dashicons-megaphone',
            'supports' => ['title'],
        ]);
    }

    public function register_metaboxes() {
        add_meta_box(
            'event_banner_details',
            __('Banner Details', 'event-banner'),
            [$this, 'render_metabox'],
            self::POST_TYPE,
            'normal',
            'default'
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'post-new.php' && $hook !== 'post.php') {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script(
            'event-banner-admin',
            plugin_dir_url(__FILE__) . 'admin.js',
            ['jquery', 'jquery-ui-datepicker'],
            '1.0.0',
            true
        );
        wp_enqueue_style(
            'event-banner-admin',
            plugin_dir_url(__FILE__) . 'admin.css',
            [],
            '1.0.0'
        );
    }

    public function register_settings_page() {
        add_options_page(
            __('Event Banner Settings', 'event-banner'),
            __('Event Banner', 'event-banner'),
            'manage_options',
            'event-banner-settings',
            [$this, 'render_settings_page']
        );
    }

    public function add_settings_link($links) {
        $url = admin_url('options-general.php?page=event-banner-settings');
        $links[] = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'event-banner') . '</a>';
        return $links;
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_options'],
            'default' => [
                'auto_render' => '1',
                'location' => 'body_open',
                'debug_logs' => '0',
            ],
        ]);

        add_settings_section(
            'event_banner_main',
            __('Display Options', 'event-banner'),
            '__return_false',
            'event-banner-settings'
        );

        add_settings_field(
            'event_banner_auto_render',
            __('Automatic Display', 'event-banner'),
            [$this, 'render_auto_render_field'],
            'event-banner-settings',
            'event_banner_main'
        );

        add_settings_field(
            'event_banner_location',
            __('Display Location', 'event-banner'),
            [$this, 'render_location_field'],
            'event-banner-settings',
            'event_banner_main'
        );

        add_settings_field(
            'event_banner_debug_logs',
            __('Debug Logs', 'event-banner'),
            [$this, 'render_debug_logs_field'],
            'event-banner-settings',
            'event_banner_main'
        );
    }

    public function sanitize_options($options) {
        $clean = [
            'auto_render' => '0',
            'location' => 'body_open',
            'debug_logs' => '0',
        ];

        if (isset($options['auto_render']) && $options['auto_render'] === '1') {
            $clean['auto_render'] = '1';
        }

        $allowed = ['body_open', 'footer', 'shortcode_only'];
        if (isset($options['location']) && in_array($options['location'], $allowed, true)) {
            $clean['location'] = $options['location'];
        }

        if (isset($options['debug_logs']) && $options['debug_logs'] === '1') {
            $clean['debug_logs'] = '1';
        }

        return $clean;
    }

    public function render_settings_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Event Banner Settings', 'event-banner') . '</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections('event-banner-settings');
        submit_button();
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px;">';
        echo '<input type="hidden" name="action" value="event_banner_clear_logs" />';
        wp_nonce_field('event_banner_clear_logs', 'event_banner_clear_logs_nonce');
        submit_button(__('Clear Debug Logs', 'event-banner'), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
    }

    public function render_auto_render_field() {
        $options = $this->get_options();
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[auto_render]" value="1" ' . checked($options['auto_render'], '1', false) . ' /> ' . esc_html__('Enable automatic banner display', 'event-banner') . '</label>';
    }

    public function render_location_field() {
        $options = $this->get_options();
        $name = esc_attr(self::OPTION_NAME) . '[location]';
        $value = esc_attr($options['location']);

        echo '<select name="' . $name . '">';
        echo '<option value="body_open"' . selected($value, 'body_open', false) . '>' . esc_html__('Top of page (wp_body_open)', 'event-banner') . '</option>';
        echo '<option value="footer"' . selected($value, 'footer', false) . '>' . esc_html__('Footer (wp_footer)', 'event-banner') . '</option>';
        echo '<option value="shortcode_only"' . selected($value, 'shortcode_only', false) . '>' . esc_html__('Shortcode only', 'event-banner') . '</option>';
        echo '</select>';
    }

    public function render_debug_logs_field() {
        $options = $this->get_options();
        echo '<label><input type="checkbox" name="' . esc_attr(self::OPTION_NAME) . '[debug_logs]" value="1" ' . checked($options['debug_logs'], '1', false) . ' /> ' . esc_html__('Enable debug logs (requires WP_DEBUG)', 'event-banner') . '</label>';
    }

    public function render_metabox($post) {
        wp_nonce_field('event_banner_save', 'event_banner_nonce');

        $text = get_post_meta($post->ID, self::META_TEXT, true);
        $link = get_post_meta($post->ID, self::META_LINK, true);
        $start = get_post_meta($post->ID, self::META_START, true);
        $end = get_post_meta($post->ID, self::META_END, true);
        $manual = get_post_meta($post->ID, self::META_MANUAL, true);
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');

        $start_date = '';
        $start_time = '';
        if ($start !== '') {
            try {
                $start_dt = new DateTime($start, $timezone);
                $start_date = $start_dt->format('Y-m-d');
                $start_time = $start_dt->format('H:i');
            } catch (Exception $e) {
                $start_date = '';
                $start_time = '';
            }
        }

        $end_date = '';
        $end_time = '';
        if ($end !== '') {
            try {
                $end_dt = new DateTime($end, $timezone);
                $end_date = $end_dt->format('Y-m-d');
                $end_time = $end_dt->format('H:i');
            } catch (Exception $e) {
                $end_date = '';
                $end_time = '';
            }
        }

        echo '<p><label for="event_banner_text"><strong>' . esc_html__('Banner Text', 'event-banner') . '</strong></label></p>';
        echo '<textarea id="event_banner_text" name="event_banner_text" rows="3" style="width:100%;">' . esc_textarea($text) . '</textarea>';

        echo '<p><label for="event_banner_link"><strong>' . esc_html__('Link (optional)', 'event-banner') . '</strong></label></p>';
        echo '<input type="url" id="event_banner_link" name="event_banner_link" value="' . esc_attr($link) . '" style="width:100%;" placeholder="https://example.com" />';

        echo '<p><label for="event_banner_start_date"><strong>' . esc_html__('Start Date/Time (optional)', 'event-banner') . '</strong></label></p>';
        echo '<div class="event-banner-picker">';
        echo '<input type="hidden" class="event-banner-datetime" id="event_banner_start" name="event_banner_start" value="' . esc_attr($start) . '" />';
        echo '<input type="text" class="event-banner-date" id="event_banner_start_date" value="' . esc_attr($start_date) . '" placeholder="YYYY-MM-DD" style="width:140px;margin-right:8px;" />';
        echo $this->render_time_select('event_banner_start_time', $start_time);
        echo '</div>';
        echo '<p style="color:#666;margin-top:4px;">' . esc_html__('24h format, uses the WordPress timezone.', 'event-banner') . '</p>';

        echo '<p><label for="event_banner_end_date"><strong>' . esc_html__('End Date/Time (optional)', 'event-banner') . '</strong></label></p>';
        echo '<div class="event-banner-picker">';
        echo '<input type="hidden" class="event-banner-datetime" id="event_banner_end" name="event_banner_end" value="' . esc_attr($end) . '" />';
        echo '<input type="text" class="event-banner-date" id="event_banner_end_date" value="' . esc_attr($end_date) . '" placeholder="YYYY-MM-DD" style="width:140px;margin-right:8px;" />';
        echo $this->render_time_select('event_banner_end_time', $end_time);
        echo '</div>';

        echo '<p><label><input type="checkbox" name="event_banner_manual" value="1" ' . checked($manual, '1', false) . ' /> ' . esc_html__('Activate manually', 'event-banner') . '</label></p>';
        echo '<p style="color:#666;">' . esc_html__('Manual activation overrides schedule. If not checked, the banner only shows between start/end dates.', 'event-banner') . '</p>';
    }

    public function add_admin_columns($columns) {
        $columns['event_banner_active'] = __('Active', 'event-banner');
        return $columns;
    }

    public function render_admin_columns($column, $post_id) {
        if ($column !== 'event_banner_active') {
            return;
        }

        $active = $this->get_active_banner();
        if ($active && (int) $active->ID === (int) $post_id) {
            echo esc_html__('Yes', 'event-banner');
        } else {
            echo esc_html__('No', 'event-banner');
        }
    }

    public function save_metabox($post_id, $post) {
        if ($post->post_type !== self::POST_TYPE) {
            return;
        }

        if (!isset($_POST['event_banner_nonce']) || !wp_verify_nonce($_POST['event_banner_nonce'], 'event_banner_save')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $text = isset($_POST['event_banner_text']) ? wp_kses_post(wp_unslash($_POST['event_banner_text'])) : '';
        $link = isset($_POST['event_banner_link']) ? esc_url_raw(wp_unslash($_POST['event_banner_link'])) : '';
        $start_raw = isset($_POST['event_banner_start']) ? sanitize_text_field(wp_unslash($_POST['event_banner_start'])) : '';
        $end_raw = isset($_POST['event_banner_end']) ? sanitize_text_field(wp_unslash($_POST['event_banner_end'])) : '';
        $start = $this->sanitize_datetime($start_raw);
        $end = $this->sanitize_datetime($end_raw);
        $manual = isset($_POST['event_banner_manual']) ? '1' : '';

        update_post_meta($post_id, self::META_TEXT, $text);
        update_post_meta($post_id, self::META_LINK, $link);
        update_post_meta($post_id, self::META_START, $start);
        update_post_meta($post_id, self::META_END, $end);
        update_post_meta($post_id, self::META_MANUAL, $manual);
    }

    private function is_active_raw($post_id) {
        $manual = get_post_meta($post_id, self::META_MANUAL, true);
        if ($manual === '1') {
            $this->log('Banner ' . $post_id . ' active (manual).');
            return true;
        }

        $start = get_post_meta($post_id, self::META_START, true);
        $end = get_post_meta($post_id, self::META_END, true);

        if ($start === '' && $end === '') {
            $this->log('Banner ' . $post_id . ' inactive (no schedule set).');
            return false;
        }

        $now = current_datetime()->getTimestamp();
        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        $start_ts = $this->parse_local_datetime($start, $timezone);
        $end_ts = $this->parse_local_datetime($end, $timezone);
        $this->log('Banner ' . $post_id . ' schedule check. now=' . $now . ' start=' . ($start_ts ?: 'none') . ' end=' . ($end_ts ?: 'none'));

        if ($start_ts && $now < $start_ts) {
            return false;
        }

        if ($end_ts && $now > $end_ts) {
            return false;
        }

        return true;
    }

    private function is_active($post_id) {
        $active_id = (int) get_option(self::OPTION_ACTIVE, 0);
        if ($active_id && $active_id !== (int) $post_id) {
            return false;
        }

        return $this->is_active_raw($post_id);
    }

    private function parse_local_datetime($value, DateTimeZone $timezone) {
        if ($value === '') {
            return null;
        }

        try {
            $date = new DateTime($value, $timezone);
            return $date->getTimestamp();
        } catch (Exception $e) {
            $this->log('Invalid datetime value: ' . $value);
            return null;
        }
    }

    private function sanitize_datetime($value) {
        if ($value === '') {
            return '';
        }

        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}$/', $value)) {
            return '';
        }

        $timezone = function_exists('wp_timezone') ? wp_timezone() : new DateTimeZone('UTC');
        try {
            $date = new DateTime($value, $timezone);
            return $date->format('Y-m-d H:i');
        } catch (Exception $e) {
            return '';
        }
    }

    private function render_time_select($name, $selected) {
        $options = '';
        for ($hour = 0; $hour < 24; $hour++) {
            for ($minute = 0; $minute < 60; $minute += 15) {
                $time = sprintf('%02d:%02d', $hour, $minute);
                $options .= '<option value="' . esc_attr($time) . '"' . selected($selected, $time, false) . '>' . esc_html($time) . '</option>';
            }
        }

        return '<select class="event-banner-time" name="' . esc_attr($name) . '">' . $options . '</select>';
    }

    private function get_active_banner() {
        $query = new WP_Query([
            'post_type' => self::POST_TYPE,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        if (!$query->have_posts()) {
            $this->log('No published event_banner posts found.');
            return null;
        }

        $active_candidates = [];
        foreach ($query->posts as $post) {
            if ($this->is_active_raw($post->ID)) {
                $active_candidates[$post->ID] = $post;
                $this->log('Active candidate found: ID ' . $post->ID);
            } else {
                $this->log('Banner not active: ID ' . $post->ID);
            }
        }

        if (!$active_candidates) {
            update_option(self::OPTION_ACTIVE, 0, false);
            return null;
        }

        $active_id = (int) get_option(self::OPTION_ACTIVE, 0);
        if ($active_id && isset($active_candidates[$active_id])) {
            return $active_candidates[$active_id];
        }

        $first = reset($active_candidates);
        if ($first) {
            update_option(self::OPTION_ACTIVE, (int) $first->ID, false);
            $this->log('Active banner set to ID ' . $first->ID);
            return $first;
        }

        return null;
    }

    private function render_banner_markup($post) {
        $text = get_post_meta($post->ID, self::META_TEXT, true);
        $link = get_post_meta($post->ID, self::META_LINK, true);

        if ($text === '') {
            return '';
        }

        $message = wp_kses_post($text);
        $inner = $link ? '<a class="event-banner__link" href="' . esc_url($link) . '">' . $message . '</a>' : $message;

        $styles = "\n<style>\n.event-banner{background:#101820;color:#fff;padding:12px 16px;text-align:center;font-size:16px;}\n.event-banner__link{color:#fff;text-decoration:underline;}\n@media(max-width:600px){.event-banner{font-size:14px;padding:10px 12px;}}\n</style>\n";

        return $styles . '<div class="event-banner" role="region" aria-label="Event banner">' . $inner . '</div>';
    }

    public function render_active_banner() {
        static $rendered = false;

        if ($rendered) {
            $this->log('Render skipped: already rendered.');
            return;
        }

        if (is_admin() || wp_doing_ajax()) {
            $this->log('Render skipped: admin or ajax context.');
            return;
        }

        $options = $this->get_options();
        if ($options['auto_render'] !== '1') {
            $this->log('Render skipped: auto_render disabled.');
            return;
        }

        if ($options['location'] === 'shortcode_only') {
            $this->log('Render skipped: shortcode_only.');
            return;
        }

        $current = current_filter();
        if ($options['location'] === 'body_open' && $current !== 'wp_body_open') {
            $this->log('Render skipped: waiting for wp_body_open. current=' . $current);
            return;
        }
        if ($options['location'] === 'footer' && $current !== 'wp_footer') {
            $this->log('Render skipped: waiting for wp_footer. current=' . $current);
            return;
        }

        $banner = $this->get_active_banner();
        if (!$banner) {
            $this->log('Render skipped: no active banner found.');
            return;
        }

        echo $this->render_banner_markup($banner);
        $rendered = true;
        $this->log('Rendered banner ID ' . $banner->ID . ' at ' . $current);
    }

    public function shortcode() {
        $banner = $this->get_active_banner();
        if (!$banner) {
            return '';
        }

        return $this->render_banner_markup($banner);
    }

    private function get_options() {
        $defaults = [
            'auto_render' => '1',
            'location' => 'body_open',
            'debug_logs' => '0',
        ];

        $options = get_option(self::OPTION_NAME, []);
        if (!is_array($options)) {
            return $defaults;
        }

        return array_merge($defaults, $options);
    }

    private function log($message) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        $options = $this->get_options();
        if ($options['debug_logs'] !== '1') {
            return;
        }

        error_log(self::LOG_PREFIX . $message);

        $logs = get_option(self::OPTION_LOGS, []);
        if (!is_array($logs)) {
            $logs = [];
        }

        $logs[] = [
            'time' => current_time('Y-m-d H:i:s'),
            'message' => $message,
        ];

        if (count($logs) > self::LOG_MAX) {
            $logs = array_slice($logs, -self::LOG_MAX);
        }

        update_option(self::OPTION_LOGS, $logs, false);
    }

    public function render_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) {
            return;
        }

        if ($screen->id !== 'settings_page_event-banner-settings') {
            return;
        }

        $logs = get_option(self::OPTION_LOGS, []);
        if (!is_array($logs) || $logs === []) {
            return;
        }

        echo '<div class="notice notice-info is-dismissible">';
        echo '<p><strong>Event Banner Debug Logs</strong></p>';
        echo '<div style="max-height:200px;overflow:auto;"><pre style="margin:0;">';
        foreach ($logs as $entry) {
            $time = isset($entry['time']) ? $entry['time'] : '';
            $msg = isset($entry['message']) ? $entry['message'] : '';
            echo esc_html($time . ' - ' . $msg) . "\n";
        }
        echo '</pre></div>';
        echo '</div>';
    }

    public function handle_clear_logs() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Unauthorized', 'event-banner'));
        }

        if (!isset($_POST['event_banner_clear_logs_nonce']) || !wp_verify_nonce($_POST['event_banner_clear_logs_nonce'], 'event_banner_clear_logs')) {
            wp_die(esc_html__('Invalid nonce', 'event-banner'));
        }

        update_option(self::OPTION_LOGS, [], false);
        wp_safe_redirect(admin_url('options-general.php?page=event-banner-settings'));
        exit;
    }
}

new CGPT_Event_Banner();
