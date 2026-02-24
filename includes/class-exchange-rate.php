<?php

if (!defined('ABSPATH')) {
    exit;
}

class Exchange_Rate
{
    const OPTION_SOURCES = 'exchange_rate_sources';
    const OPTION_LAST_ERRORS = 'exchange_rate_last_errors';
    const OPTION_DB_VERSION = 'exchange_rate_db_version';
    const CRON_HOOK = 'exchange_rate_daily_fetch_event';
    const MANUAL_CRON_HOOK = 'exchange_rate_manual_fetch_event';
    const CRON_SCHEDULE = 'exchange_rate_10sec';
    const TABLE_SLUG = 'exchange_rate_daily_snapshots';
    const SYSTEM_URL_MASK = '*** محافظت‌شده ***';

    private $api;
    private $table_name;

    public function __construct()
    {
        global $wpdb;

        $this->api = new Exchange_Rate_API();
        $this->table_name = $wpdb->prefix . self::TABLE_SLUG;
    }

    public static function activate()
    {
        self::create_table();
        self::ensure_default_sources();

        update_option(self::OPTION_DB_VERSION, EXCHANGE_RATE_VERSION, false);
        self::reschedule_poll_event();
        wp_schedule_single_event(time() + 5, self::MANUAL_CRON_HOOK);
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::MANUAL_CRON_HOOK);
    }

    public function run()
    {
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_shortcode('exchange_rate', array($this, 'render_shortcode'));
        add_action('wp_ajax_exchange_rate_live_tick', array($this, 'handle_ajax_live_tick'));
        add_action('wp_ajax_exchange_rate_ingest_browser', array($this, 'handle_ajax_ingest_browser'));
        add_action('http_api_curl', array($this, 'tune_http_curl_for_sources'), 10, 3);

        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'maybe_upgrade_schema'));

        add_action('admin_post_exchange_rate_save_source', array($this, 'handle_save_source'));
        add_action('admin_post_exchange_rate_delete_source', array($this, 'handle_delete_source'));
        add_action('admin_post_exchange_rate_fetch_now', array($this, 'handle_fetch_now'));

        add_action(self::CRON_HOOK, array($this, 'cron_fetch_snapshot'));
        add_action(self::MANUAL_CRON_HOOK, array($this, 'manual_fetch_snapshot'));

        self::ensure_poll_event();
    }

    public function tune_http_curl_for_sources($handle, $parsed_args, $url)
    {
        if (!function_exists('curl_setopt')) {
            return;
        }

        if (!is_resource($handle) && !is_object($handle)) {
            return;
        }

        $host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
        if (!in_array($host, array('api.ice.ir', 'ice.ir'), true)) {
            return;
        }

        if (defined('CURLOPT_IPRESOLVE') && defined('CURL_IPRESOLVE_V4')) {
            curl_setopt($handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }

        if (defined('CURLOPT_CONNECTTIMEOUT')) {
            $timeout = isset($parsed_args['timeout']) ? (int) $parsed_args['timeout'] : 12;
            $connect_timeout = max(4, min(8, $timeout - 1));
            curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $connect_timeout);
        }
    }

    public function enqueue_assets()
    {
        wp_register_style(
            'exchange-rate-style',
            EXCHANGE_RATE_PLUGIN_URL . 'assets/css/nerkhchand-frontend.css',
            array(),
            EXCHANGE_RATE_VERSION
        );
    }

    public function enqueue_admin_assets($hook)
    {
        if (strpos((string) $hook, 'exchange-rate') === false) {
            return;
        }

        wp_enqueue_style(
            'nerkhchand-admin-style',
            EXCHANGE_RATE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EXCHANGE_RATE_VERSION
        );

        wp_enqueue_script(
            'nerkhchand-admin-ux',
            EXCHANGE_RATE_PLUGIN_URL . 'assets/js/admin-ux.js',
            array('jquery'),
            EXCHANGE_RATE_VERSION,
            true
        );

        if (strpos((string) $hook, 'toplevel_page_exchange-rate') !== false) {
            $sources = $this->get_sources();
            wp_enqueue_script(
                'nerkhchand-admin-live',
                EXCHANGE_RATE_PLUGIN_URL . 'assets/js/admin-live.js',
                array('jquery'),
                EXCHANGE_RATE_VERSION,
                true
            );

            wp_localize_script('nerkhchand-admin-live', 'NerkhLiveConfig', array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('exchange_rate_live_tick'),
                'sourceTimers' => $this->get_live_source_timers($sources),
                'i18nTicking' => __('واکشی خودکار فعال است.', 'exchange-rate'),
                'i18nIdle' => __('واکشی خودکار غیرفعال است.', 'exchange-rate'),
                'i18nError' => __('واکشی خودکار با خطا مواجه شد.', 'exchange-rate'),
            ));
        }
    }

    public function register_admin_menu()
    {
        add_menu_page(
            __('نرخ چند؟', 'exchange-rate'),
            __('نرخ چند؟', 'exchange-rate'),
            'manage_options',
            'exchange-rate',
            array($this, 'render_admin_page'),
            'dashicons-chart-area',
            60
        );

        add_submenu_page(
            'exchange-rate',
            __('داشبورد', 'exchange-rate'),
            __('داشبورد', 'exchange-rate'),
            'manage_options',
            'exchange-rate',
            array($this, 'render_admin_page')
        );

        add_submenu_page(
            'exchange-rate',
            __('منابع', 'exchange-rate'),
            __('منابع', 'exchange-rate'),
            'manage_options',
            'exchange-rate-sources',
            array($this, 'render_sources_page')
        );

        add_submenu_page(
            'exchange-rate',
            __('راهنمای شورت کد', 'exchange-rate'),
            __('راهنمای شورت کد', 'exchange-rate'),
            'manage_options',
            'exchange-rate-guide',
            array($this, 'render_guide_page')
        );
    }

    public function maybe_upgrade_schema()
    {
        $installed_version = (string) get_option(self::OPTION_DB_VERSION, '');
        if ($installed_version !== EXCHANGE_RATE_VERSION) {
            self::create_table();
            self::ensure_default_sources();
            update_option(self::OPTION_DB_VERSION, EXCHANGE_RATE_VERSION, false);
            self::reschedule_poll_event();
        } else {
            self::ensure_default_sources();
            self::ensure_poll_event();
        }
    }

    public function add_cron_schedules($schedules)
    {
        if (!isset($schedules[self::CRON_SCHEDULE])) {
            $schedules[self::CRON_SCHEDULE] = array(
                'interval' => 10,
                'display' => __('هر 10 ثانیه', 'exchange-rate'),
            );
        }

        return $schedules;
    }

    private static function ensure_poll_event()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 10, self::CRON_SCHEDULE, self::CRON_HOOK);
        }
    }

    private static function reschedule_poll_event()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_schedule_event(time() + 10, self::CRON_SCHEDULE, self::CRON_HOOK);
    }

    public static function get_table_name()
    {
        global $wpdb;

        return $wpdb->prefix . self::TABLE_SLUG;
    }

    private static function create_table()
    {
        global $wpdb;

        $table_name = self::get_table_name();
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            source_key varchar(100) NOT NULL,
            source_name varchar(190) NOT NULL,
            date_key varchar(20) NOT NULL,
            source_date varchar(32) NOT NULL DEFAULT '',
            source_url varchar(255) NOT NULL DEFAULT '',
            rows_count int(10) unsigned NOT NULL DEFAULT 0,
            rows_json longtext NOT NULL,
            fetched_at datetime NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY uniq_source_date (source_key, date_key),
            KEY idx_source_fetched (source_key, fetched_at)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    private static function ensure_default_sources()
    {
        $sources = get_option(self::OPTION_SOURCES, array());
        if (!is_array($sources)) {
            $sources = array();
        }
        $ice_proxy_template = self::get_default_ice_proxy_template();

        $defaults = array(
            array(
                'key' => 'ice_havaleh',
                'name' => 'حواله ICE',
                'is_system' => 1,
                'display_title' => 'نمای کلی بازار حواله',
                'header_text' => 'نرخ حواله بر اساس معاملات ثبت شده توسط بانک ها در سامانه های ارزی در روز کاری قبل محاسبه شده است.',
                'url' => Exchange_Rate_API::DEFAULT_SOURCE_URL,
                'proxy_url' => $ice_proxy_template,
                'type' => 'ice_api_latest',
                'enabled' => 1,
                'interval_seconds' => 86400,
                'notes' => 'منبع اولیه نرخ حواله از ice.ir',
            ),
            array(
                'key' => 'ice_usd_history',
                'name' => 'تاریخچه دلار ICE',
                'is_system' => 1,
                'display_title' => 'تاریخچه دلار آمریکا',
                'header_text' => 'نمای کلی روند روزهای اخیر دلار آمریکا در بازار حواله.',
                'url' => 'https://api.ice.ir/api/v1/markets/2/currencies/history/15/?offset=0&limit=20&lang=fa',
                'proxy_url' => $ice_proxy_template,
                'type' => 'ice_api_history_currency',
                'enabled' => 1,
                'interval_seconds' => 86400,
                'notes' => 'تاریخچه دلار آمریکا (حواله)',
            ),
            array(
                'key' => 'milli_price18',
                'name' => 'میلی - طلای 18 عیار',
                'is_system' => 1,
                'display_title' => 'طلای 18 عیار - کمینه/بیشینه روز',
                'header_text' => 'آخرین قیمت طلای 18 عیار همراه با کمینه و بیشینه روزانه.',
                'url' => 'https://milli.gold/api/v1/public/milli-price/detail',
                'type' => 'milli_gold_price_detail',
                'enabled' => 1,
                'interval_seconds' => 10,
                'notes' => 'ثبت لحظه ای با نگهداری کمترین/بیشترین روزانه',
            ),
            array(
                'key' => 'cbi_havaleh',
                'name' => 'حواله CBI',
                'is_system' => 1,
                'display_title' => 'نرخ حواله بازار ارز (CBI)',
                'header_text' => 'نرخ حواله بر اساس معاملات ثبت شده توسط بانک ها در سامانه های ارزی در روز کاری قبل محاسبه شده است.',
                'url' => 'https://fxmarketrate.cbi.ir/',
                'type' => 'html_table',
                'enabled' => 1,
                'interval_seconds' => 86400,
                'notes' => 'خواندن جدول نرخ حواله از CBI (در برخی سرورها ممکن است نیاز به هدر/کوکی داشته باشد).',
            ),
        );

        $indexed = array();
        foreach ($sources as $item) {
            if (is_array($item) && !empty($item['key'])) {
                $indexed[sanitize_key($item['key'])] = $item;
            }
        }

        foreach ($defaults as $default_source) {
            $key = sanitize_key($default_source['key']);
            if (!isset($indexed[$key])) {
                $sources[] = $default_source;
                continue;
            }

            if (
                $ice_proxy_template !== ''
                && empty($indexed[$key]['proxy_url'])
                && in_array($key, array('ice_havaleh', 'ice_usd_history'), true)
            ) {
                foreach ($sources as $idx => $source) {
                    if (is_array($source) && !empty($source['key']) && sanitize_key($source['key']) === $key) {
                        $source['proxy_url'] = $ice_proxy_template;
                        $sources[$idx] = $source;
                    }
                }
            }

            foreach ($sources as $idx => $source) {
                if (!is_array($source) || empty($source['key']) || sanitize_key($source['key']) !== $key) {
                    continue;
                }
                if (
                    $key === 'cbi_havaleh'
                    && !empty($source['url'])
                    && trim((string) $source['url']) === 'https://fxmarketrate.cbi.ir/TSPD/?type=20'
                ) {
                    $source['url'] = 'https://fxmarketrate.cbi.ir/';
                }
                if (empty($source['display_title']) && !empty($default_source['display_title'])) {
                    $source['display_title'] = $default_source['display_title'];
                }
                if (empty($source['header_text']) && !empty($default_source['header_text'])) {
                    $source['header_text'] = $default_source['header_text'];
                }
                if (!isset($source['is_system'])) {
                    $source['is_system'] = !empty($default_source['is_system']) ? 1 : 0;
                }
                $sources[$idx] = $source;
            }
        }

        $instance = new self();
        $instance->save_sources($sources);
    }

    private static function get_default_ice_proxy_template()
    {
        if (!defined('EXCHANGE_RATE_ICE_RELAY_URL')) {
            return '';
        }

        $relay = trim((string) EXCHANGE_RATE_ICE_RELAY_URL);
        if ($relay === '') {
            return '';
        }

        if (strpos($relay, '{url}') !== false) {
            return $relay;
        }

        $glue = strpos($relay, '?') === false ? '?' : '&';
        return $relay . $glue . 'url={url}';
    }

    private function get_sources()
    {
        $sources = get_option(self::OPTION_SOURCES, array());
        if (!is_array($sources)) {
            return array();
        }

        $result = array();
        foreach ($sources as $source) {
            $normalized = $this->normalize_source($source);
            if ($normalized['key'] !== '') {
                $result[$normalized['key']] = $normalized;
            }
        }

        return array_values($result);
    }

    private function normalize_source($source)
    {
        $source = is_array($source) ? $source : array();
        $interval_seconds = isset($source['interval_seconds']) ? (int) $source['interval_seconds'] : 86400;
        if ($interval_seconds < 10) {
            $interval_seconds = 10;
        }

        $url = $this->read_secret_field($source, 'url');
        $proxy_url = $this->read_secret_field($source, 'proxy_url');
        $headers_raw = $this->read_secret_field($source, 'headers_raw');

        return array(
            'key' => isset($source['key']) ? sanitize_key($source['key']) : '',
            'name' => isset($source['name']) ? sanitize_text_field($source['name']) : '',
            'display_title' => isset($source['display_title']) ? sanitize_text_field($source['display_title']) : '',
            'header_text' => isset($source['header_text']) ? sanitize_textarea_field($source['header_text']) : '',
            'url' => esc_url_raw(trim((string) $url)),
            'proxy_url' => sanitize_text_field(trim((string) $proxy_url)),
            'type' => isset($source['type']) ? sanitize_key($source['type']) : 'ice_api_latest',
            'enabled' => !empty($source['enabled']) ? 1 : 0,
            'interval_seconds' => $interval_seconds,
            'headers_raw' => sanitize_textarea_field($headers_raw),
            'notes' => isset($source['notes']) ? sanitize_textarea_field($source['notes']) : '',
            'is_system' => !empty($source['is_system']) ? 1 : 0,
        );
    }

    private function save_sources($sources)
    {
        $normalized = array();
        foreach ((array) $sources as $source) {
            $item = $this->normalize_source($source);
            if ($item['key'] === '' || $item['name'] === '' || $item['url'] === '') {
                continue;
            }
            $normalized[$item['key']] = $this->prepare_source_for_storage($item);
        }

        update_option(self::OPTION_SOURCES, array_values($normalized), false);
    }

    private function prepare_source_for_storage($item)
    {
        $item = is_array($item) ? $item : array();
        $item['url_enc'] = $this->encrypt_secret(isset($item['url']) ? (string) $item['url'] : '');
        $item['proxy_url_enc'] = $this->encrypt_secret(isset($item['proxy_url']) ? (string) $item['proxy_url'] : '');
        $item['headers_raw_enc'] = $this->encrypt_secret(isset($item['headers_raw']) ? (string) $item['headers_raw'] : '');
        unset($item['url'], $item['proxy_url'], $item['headers_raw']);
        return $item;
    }

    private function read_secret_field($source, $field)
    {
        if (isset($source[$field])) {
            return (string) $source[$field];
        }

        $enc_key = $field . '_enc';
        if (!empty($source[$enc_key])) {
            return (string) $this->decrypt_secret((string) $source[$enc_key]);
        }

        return '';
    }

    private function encrypt_secret($plain)
    {
        $plain = (string) $plain;
        if ($plain === '') {
            return '';
        }

        if (!function_exists('openssl_encrypt')) {
            return 'b64:' . base64_encode($plain);
        }

        $key = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|exchange-rate', true);
        try {
            $iv = function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16);
        } catch (Throwable $e) {
            $iv = openssl_random_pseudo_bytes(16);
        }
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return 'b64:' . base64_encode($plain);
        }

        return 'enc:' . base64_encode($iv . $cipher);
    }

    private function decrypt_secret($encoded)
    {
        $encoded = (string) $encoded;
        if ($encoded === '') {
            return '';
        }

        if (strpos($encoded, 'enc:') === 0 && function_exists('openssl_decrypt')) {
            $raw = base64_decode(substr($encoded, 4), true);
            if ($raw !== false && strlen($raw) > 16) {
                $iv = substr($raw, 0, 16);
                $cipher = substr($raw, 16);
                $key = hash('sha256', wp_salt('auth') . '|' . wp_salt('secure_auth') . '|exchange-rate', true);
                $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
                if ($plain !== false) {
                    return (string) $plain;
                }
            }
        }

        if (strpos($encoded, 'b64:') === 0) {
            $plain = base64_decode(substr($encoded, 4), true);
            return $plain !== false ? (string) $plain : '';
        }

        return $encoded;
    }

    private function get_source_by_key($source_key)
    {
        $source_key = sanitize_key((string) $source_key);
        if ($source_key === '') {
            return array();
        }

        foreach ($this->get_sources() as $source) {
            if ($source['key'] === $source_key) {
                return $source;
            }
        }

        return array();
    }

    private function should_poll_source($source)
    {
        if (empty($source['enabled'])) {
            return false;
        }

        $interval = isset($source['interval_seconds']) ? (int) $source['interval_seconds'] : 86400;
        if ($interval < 10) {
            $interval = 10;
        }

        $latest = $this->get_latest_snapshot_row($source['key']);
        if (empty($latest['fetched_at'])) {
            return true;
        }

        return (time() - (int) $latest['fetched_at']) >= $interval;
    }

    private function decode_rows_json($rows_json)
    {
        $rows = json_decode((string) $rows_json, true);
        if (!is_array($rows)) {
            return array();
        }

        return $rows;
    }

    private function build_milli_daily_rows($existing_rows, $incoming_rows, $date_key)
    {
        $current_price = 0;
        $current_at = '';

        if (!empty($incoming_rows[0])) {
            $current_price = isset($incoming_rows[0]['price']) ? (int) $incoming_rows[0]['price'] : 0;
            $current_at = isset($incoming_rows[0]['row_datetime']) ? (string) $incoming_rows[0]['row_datetime'] : '';
        }

        $day_low = $current_price;
        $day_high = $current_price;
        $samples = 1;

        if (!empty($existing_rows[0]) && is_array($existing_rows[0])) {
            $existing = $existing_rows[0];
            $old_low = isset($existing['day_low']) ? (int) $existing['day_low'] : $current_price;
            $old_high = isset($existing['day_high']) ? (int) $existing['day_high'] : $current_price;
            $old_samples = isset($existing['samples']) ? (int) $existing['samples'] : 0;

            $day_low = min($old_low, $current_price);
            $day_high = max($old_high, $current_price);
            $samples = $old_samples + 1;
        }

        return array(
            array(
                'row_date' => $date_key,
                'last_price' => $current_price,
                'day_low' => $day_low,
                'day_high' => $day_high,
                'last_at' => $current_at,
                'samples' => $samples,
            ),
        );
    }

    public function cron_fetch_snapshot()
    {
        $this->fetch_and_store_snapshot('', true);
    }

    public function manual_fetch_snapshot()
    {
        $this->fetch_and_store_snapshot('', false);
    }

    private function fetch_and_store_snapshot($target_source_key = '', $respect_interval = false)
    {
        global $wpdb;

        $sources = $this->get_sources();
        if (empty($sources)) {
            return new WP_Error('exchange_rate_no_sources', __('هیچ منبعی تنظیم نشده است.', 'exchange-rate'));
        }

        $last_errors = get_option(self::OPTION_LAST_ERRORS, array());
        if (!is_array($last_errors)) {
            $last_errors = array();
        }

        $done = 0;

        foreach ($sources as $source) {
            if (!$source['enabled']) {
                continue;
            }

            if ($target_source_key !== '' && $target_source_key !== $source['key']) {
                continue;
            }

            if ($respect_interval && !$this->should_poll_source($source)) {
                continue;
            }

            try {
                $snapshot = $this->api->fetch_snapshot($source);
            } catch (Throwable $e) {
                $last_errors[$source['key']] = array(
                    'message' => sprintf(__('خطای سیستمی هنگام واکشی: %s', 'exchange-rate'), $e->getMessage()),
                    'time' => time(),
                );
                continue;
            }

            if (is_wp_error($snapshot)) {
                $error_data = $snapshot->get_error_data();
                $debug_data = array();
                if (is_array($error_data) && isset($error_data['debug']) && is_array($error_data['debug'])) {
                    $debug_data = $error_data['debug'];
                }

                $last_errors[$source['key']] = array(
                    'message' => $snapshot->get_error_message(),
                    'time' => time(),
                    'debug' => $this->sanitize_error_debug($debug_data),
                );
                continue;
            }
            $persist_result = $this->persist_snapshot($source, $snapshot);
            if (is_wp_error($persist_result)) {
                $last_errors[$source['key']] = array(
                    'message' => $persist_result->get_error_message(),
                    'time' => time(),
                );
                continue;
            }

            unset($last_errors[$source['key']]);
            $done++;
        }

        update_option(self::OPTION_LAST_ERRORS, $last_errors, false);

        if ($done < 1 && $respect_interval) {
            return true;
        }

        if ($done < 1) {
            return new WP_Error('exchange_rate_fetch_failed', __('هیچ منبعی با موفقیت به‌روزرسانی نشد.', 'exchange-rate'));
        }

        return true;
    }

    private function normalize_date_key($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $this->get_tehran_gregorian_date();
        }

        $value = str_replace('/', '-', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return $this->get_tehran_gregorian_date();
    }

    private function get_tehran_gregorian_date()
    {
        $dt = new DateTime('now', $this->get_fixed_iran_timezone());
        return $dt->format('Y-m-d');
    }

    private function get_fixed_iran_timezone()
    {
        return new DateTimeZone('+03:30');
    }

    private function format_tehran_time($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '-';
        }

        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($this->get_fixed_iran_timezone());
        return $dt->format('H:i');
    }

    private function format_tehran_jalali_date($timestamp)
    {
        $timestamp = (int) $timestamp;
        if ($timestamp <= 0) {
            return '-';
        }

        $dt = new DateTime('@' . $timestamp);
        $dt->setTimezone($this->get_fixed_iran_timezone());
        $gYear = (int) $dt->format('Y');
        $gMonth = (int) $dt->format('n');
        $gDay = (int) $dt->format('j');
        $jalali = $this->gregorian_to_jalali($gYear, $gMonth, $gDay);

        return sprintf('%04d/%02d/%02d', $jalali[0], $jalali[1], $jalali[2]);
    }

    private function format_tehran_jalali_datetime($timestamp)
    {
        $date = $this->format_tehran_jalali_date($timestamp);
        if ($date === '-') {
            return '-';
        }

        return $date . ' ' . $this->format_tehran_time($timestamp);
    }

    private function format_source_date_jalali($source_date, $date_key, $fallback_timestamp = 0)
    {
        $candidate = trim((string) $source_date);
        if ($candidate === '') {
            $candidate = trim((string) $date_key);
        }

        $candidate = str_replace('-', '/', $candidate);
        if (preg_match('/^(14\d{2})\/(\d{1,2})\/(\d{1,2})$/', $candidate, $m)) {
            return sprintf('%04d/%02d/%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(20\d{2})\/(\d{1,2})\/(\d{1,2})$/', $candidate, $m)) {
            $jalali = $this->gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
            return sprintf('%04d/%02d/%02d', $jalali[0], $jalali[1], $jalali[2]);
        }

        if ((int) $fallback_timestamp > 0) {
            return $this->format_tehran_jalali_date((int) $fallback_timestamp);
        }

        return '-';
    }

    private function format_row_date_jalali($value)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '-';
        }

        $value = str_replace('-', '/', $value);
        if (preg_match('/^(14\d{2})\/(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            return sprintf('%04d/%02d/%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(20\d{2})\/(\d{1,2})\/(\d{1,2})$/', $value, $m)) {
            $jalali = $this->gregorian_to_jalali((int) $m[1], (int) $m[2], (int) $m[3]);
            return sprintf('%04d/%02d/%02d', $jalali[0], $jalali[1], $jalali[2]);
        }

        return $value;
    }

    private function gregorian_to_jalali($gy, $gm, $gd)
    {
        $g_days_in_month = array(31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31);
        $j_days_in_month = array(31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29);

        $gy2 = $gy - 1600;
        $gm2 = $gm - 1;
        $gd2 = $gd - 1;

        $g_day_no = 365 * $gy2 + (int) floor(($gy2 + 3) / 4) - (int) floor(($gy2 + 99) / 100) + (int) floor(($gy2 + 399) / 400);
        for ($i = 0; $i < $gm2; ++$i) {
            $g_day_no += $g_days_in_month[$i];
        }
        if ($gm2 > 1 && (($gy2 % 4 === 0 && $gy2 % 100 !== 0) || ($gy2 % 400 === 0))) {
            ++$g_day_no;
        }
        $g_day_no += $gd2;

        $j_day_no = $g_day_no - 79;
        $j_np = (int) floor($j_day_no / 12053);
        $j_day_no %= 12053;

        $jy = 979 + 33 * $j_np + 4 * (int) floor($j_day_no / 1461);
        $j_day_no %= 1461;

        if ($j_day_no >= 366) {
            $jy += (int) floor(($j_day_no - 1) / 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }

        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
            $j_day_no -= $j_days_in_month[$i];
        }
        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return array($jy, $jm, $jd);
    }

    private function sanitize_error_debug($debug)
    {
        $debug = is_array($debug) ? $debug : array();

        $result = array(
            'source_url' => isset($debug['source_url']) ? esc_url_raw((string) $debug['source_url']) : '',
            'proxy_url' => isset($debug['proxy_url']) ? esc_url_raw((string) $debug['proxy_url']) : '',
            'source_type' => isset($debug['source_type']) ? sanitize_key((string) $debug['source_type']) : '',
            'request_url' => isset($debug['request_url']) ? esc_url_raw((string) $debug['request_url']) : '',
            'timeout' => isset($debug['timeout']) ? (int) $debug['timeout'] : 0,
            'last_wp_error' => isset($debug['last_wp_error']) ? sanitize_text_field((string) $debug['last_wp_error']) : '',
            'attempts' => array(),
        );

        if (!empty($debug['attempts']) && is_array($debug['attempts'])) {
            foreach (array_slice($debug['attempts'], 0, 12) as $attempt) {
                if (!is_array($attempt)) {
                    continue;
                }
                $result['attempts'][] = array(
                    'url' => isset($attempt['url']) ? esc_url_raw((string) $attempt['url']) : '',
                    'attempt' => isset($attempt['attempt']) ? (int) $attempt['attempt'] : 0,
                    'result' => isset($attempt['result']) ? sanitize_key((string) $attempt['result']) : '',
                    'http_code' => isset($attempt['http_code']) ? (int) $attempt['http_code'] : 0,
                    'duration_ms' => isset($attempt['duration_ms']) ? (int) $attempt['duration_ms'] : 0,
                    'body_size' => isset($attempt['body_size']) ? (int) $attempt['body_size'] : 0,
                    'server' => isset($attempt['server']) ? sanitize_text_field((string) $attempt['server']) : '',
                    'x_cache' => isset($attempt['x_cache']) ? sanitize_text_field((string) $attempt['x_cache']) : '',
                    'error_code' => isset($attempt['error_code']) ? sanitize_key((string) $attempt['error_code']) : '',
                    'error_message' => isset($attempt['error_message']) ? sanitize_text_field((string) $attempt['error_message']) : '',
                );
            }
        }

        return $result;
    }

    private function format_error_debug_lines($entry)
    {
        if (empty($entry['debug']) || !is_array($entry['debug'])) {
            return array();
        }

        $debug = $entry['debug'];
        $lines = array();

        if (!empty($debug['source_type'])) {
            $lines[] = 'type: ' . $debug['source_type'];
        }
        if (!empty($debug['source_url'])) {
            $lines[] = 'source_url: ' . $debug['source_url'];
        }
        if (!empty($debug['proxy_url'])) {
            $lines[] = 'proxy_url: ' . $debug['proxy_url'];
        }
        if (!empty($debug['request_url'])) {
            $lines[] = 'last_success_url: ' . $debug['request_url'];
        }
        if (!empty($debug['timeout'])) {
            $lines[] = 'timeout: ' . (int) $debug['timeout'] . 's';
        }
        if (!empty($debug['last_wp_error'])) {
            $lines[] = 'last_wp_error: ' . $debug['last_wp_error'];
        }

        if (!empty($debug['attempts']) && is_array($debug['attempts'])) {
            foreach ($debug['attempts'] as $index => $attempt) {
                $parts = array();
                $parts[] = '#' . ($index + 1);
                if (!empty($attempt['url'])) {
                    $parts[] = $attempt['url'];
                }
                if (!empty($attempt['result'])) {
                    $parts[] = 'result=' . $attempt['result'];
                }
                if (!empty($attempt['http_code'])) {
                    $parts[] = 'http=' . (int) $attempt['http_code'];
                }
                if (!empty($attempt['duration_ms'])) {
                    $parts[] = 't=' . (int) $attempt['duration_ms'] . 'ms';
                }
                if (!empty($attempt['body_size'])) {
                    $parts[] = 'size=' . (int) $attempt['body_size'];
                }
                if (!empty($attempt['server'])) {
                    $parts[] = 'server=' . $attempt['server'];
                }
                if (!empty($attempt['x_cache'])) {
                    $parts[] = 'x-cache=' . $attempt['x_cache'];
                }
                if (!empty($attempt['error_code'])) {
                    $parts[] = 'err=' . $attempt['error_code'];
                }
                if (!empty($attempt['error_message'])) {
                    $parts[] = 'msg=' . $attempt['error_message'];
                }

                $lines[] = implode(' | ', $parts);
            }
        }

        return $lines;
    }

    private function get_latest_snapshot_row($source_key)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE source_key = %s ORDER BY fetched_at DESC, id DESC LIMIT 1",
            sanitize_key($source_key)
        ), ARRAY_A);

        return $this->hydrate_snapshot_row($row);
    }

    private function get_snapshot_row_by_date($source_key, $date_key)
    {
        global $wpdb;

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE source_key = %s AND date_key = %s LIMIT 1",
            sanitize_key($source_key),
            $this->normalize_date_key($date_key)
        ), ARRAY_A);

        return $this->hydrate_snapshot_row($row);
    }

    private function hydrate_snapshot_row($row)
    {
        if (!$row || empty($row['rows_json'])) {
            return array();
        }

        $rows = json_decode((string) $row['rows_json'], true);
        if (!is_array($rows)) {
            $rows = array();
        }

        $fetched_at = 0;
        if (!empty($row['fetched_at'])) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string) $row['fetched_at'], new DateTimeZone('UTC'));
            if ($dt instanceof DateTime) {
                $fetched_at = $dt->getTimestamp();
            } else {
                $fetched_at = strtotime((string) $row['fetched_at']);
            }
        }

        return array(
            'source_key' => (string) $row['source_key'],
            'source_name' => (string) $row['source_name'],
            'date_key' => (string) $row['date_key'],
            'source_date' => (string) $row['source_date'],
            'source_url' => (string) $row['source_url'],
            'fetched_at' => $fetched_at,
            'rows' => $rows,
            'rows_count' => (int) $row['rows_count'],
        );
    }

    private function get_dashboard_metrics($sources)
    {
        $total = count((array) $sources);
        $enabled = 0;
        $updated_today = 0;
        $latest_fetch = 0;

        $today = $this->get_tehran_gregorian_date();

        foreach ((array) $sources as $source) {
            if (!empty($source['enabled'])) {
                $enabled++;
            }

            $snap = $this->get_latest_snapshot_row($source['key']);
            if (!empty($snap['date_key']) && $snap['date_key'] === $today) {
                $updated_today++;
            }
            if (!empty($snap['fetched_at']) && (int) $snap['fetched_at'] > $latest_fetch) {
                $latest_fetch = (int) $snap['fetched_at'];
            }
        }

        return array(
            'total' => $total,
            'enabled' => $enabled,
            'updated_today' => $updated_today,
            'latest_fetch' => $latest_fetch,
        );
    }

    public function handle_save_source()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما مجوز انجام این عملیات را ندارید.', 'exchange-rate'));
        }

        check_admin_referer('exchange_rate_save_source');

        $sources = $this->get_sources();
        $map = array();
        foreach ($sources as $source) {
            $map[$source['key']] = $source;
        }

        $source = array(
            'key' => isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '',
            'name' => isset($_POST['source_name']) ? sanitize_text_field(wp_unslash($_POST['source_name'])) : '',
            'display_title' => isset($_POST['source_display_title']) ? sanitize_text_field(wp_unslash($_POST['source_display_title'])) : '',
            'header_text' => isset($_POST['source_header_text']) ? sanitize_textarea_field(wp_unslash($_POST['source_header_text'])) : '',
            'url' => isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '',
            'proxy_url' => isset($_POST['source_proxy_url']) ? sanitize_text_field(wp_unslash($_POST['source_proxy_url'])) : '',
            'type' => isset($_POST['source_type']) ? sanitize_key(wp_unslash($_POST['source_type'])) : 'ice_api_latest',
            'enabled' => !empty($_POST['source_enabled']) ? 1 : 0,
            'interval_seconds' => isset($_POST['source_interval']) ? (int) wp_unslash($_POST['source_interval']) : 86400,
            'headers_raw' => isset($_POST['source_headers']) ? sanitize_textarea_field(wp_unslash($_POST['source_headers'])) : '',
            'notes' => isset($_POST['source_notes']) ? sanitize_textarea_field(wp_unslash($_POST['source_notes'])) : '',
            'is_system' => 0,
        );

        if ($source['key'] !== '' && isset($map[$source['key']]['is_system']) && !empty($map[$source['key']]['is_system'])) {
            $existing_system = $map[$source['key']];
            $source['is_system'] = 1;

            // Lock system source core fields server-side: only interval and descriptive fields can change.
            $source['name'] = isset($existing_system['name']) ? (string) $existing_system['name'] : $source['name'];
            $source['url'] = isset($existing_system['url']) ? (string) $existing_system['url'] : $source['url'];
            $source['proxy_url'] = isset($existing_system['proxy_url']) ? (string) $existing_system['proxy_url'] : $source['proxy_url'];
            $source['type'] = isset($existing_system['type']) ? (string) $existing_system['type'] : $source['type'];
            $source['enabled'] = isset($existing_system['enabled']) ? (int) $existing_system['enabled'] : $source['enabled'];
            $source['headers_raw'] = isset($existing_system['headers_raw']) ? (string) $existing_system['headers_raw'] : $source['headers_raw'];
        }

        $source = $this->normalize_source($source);

        if ($source['key'] !== '' && $source['name'] !== '' && $source['url'] !== '') {
            $map[$source['key']] = $source;
        }

        $this->save_sources(array_values($map));

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'exchange-rate-sources',
                'exchange_rate_notice' => 'source_saved',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_delete_source()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما مجوز انجام این عملیات را ندارید.', 'exchange-rate'));
        }

        check_admin_referer('exchange_rate_delete_source');

        $key = isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '';
        $sources = $this->get_sources();
        $filtered = array();

        foreach ($sources as $source) {
            if ($source['key'] === $key) {
                if (!empty($source['is_system'])) {
                    wp_safe_redirect(add_query_arg(
                        array(
                            'page' => 'exchange-rate-sources',
                            'exchange_rate_notice' => 'source_locked',
                        ),
                        admin_url('admin.php')
                    ));
                    exit;
                }
                continue;
            }
            $filtered[] = $source;
        }

        $this->save_sources($filtered);

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'exchange-rate-sources',
                'exchange_rate_notice' => 'source_deleted',
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_fetch_now()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('شما مجوز انجام این عملیات را ندارید.', 'exchange-rate'));
        }

        check_admin_referer('exchange_rate_fetch_now');

        $source_key = isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '';
        $notice = 'fetch_success';

        if ($source_key === '') {
            // Queue full refresh in background to avoid admin request timeout on slower hosts.
            wp_clear_scheduled_hook(self::MANUAL_CRON_HOOK);
            wp_schedule_single_event(time() + 1, self::MANUAL_CRON_HOOK);
            if (function_exists('spawn_cron')) {
                spawn_cron(time());
            }
            $notice = 'fetch_queued';
        } else {
            $result = $this->fetch_and_store_snapshot($source_key);
            $notice = is_wp_error($result) ? 'fetch_error' : 'fetch_success';
        }

        wp_safe_redirect(add_query_arg(
            array(
                'page' => 'exchange-rate',
                'exchange_rate_notice' => $notice,
            ),
            admin_url('admin.php')
        ));
        exit;
    }

    public function handle_ajax_live_tick()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('شما مجوز انجام این عملیات را ندارید.', 'exchange-rate')), 403);
        }

        check_ajax_referer('exchange_rate_live_tick', 'nonce');
        $source_key = isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '';
        $result = $this->fetch_and_store_snapshot($source_key, true);
        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()), 500);
        }

        wp_send_json_success(array(
            'message' => __('واکشی خودکار انجام شد.', 'exchange-rate'),
            'time' => time(),
            'source_key' => $source_key,
        ));
    }

    public function handle_ajax_ingest_browser()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('شما مجوز انجام این عملیات را ندارید.', 'exchange-rate')), 403);
        }

        check_ajax_referer('exchange_rate_live_tick', 'nonce');

        $source_key = isset($_POST['source_key']) ? sanitize_key(wp_unslash($_POST['source_key'])) : '';
        $request_url = isset($_POST['request_url']) ? esc_url_raw(wp_unslash($_POST['request_url'])) : '';
        $payload = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';

        if ($source_key === '' || $payload === '') {
            wp_send_json_error(array('message' => __('درخواست نامعتبر است.', 'exchange-rate')), 400);
        }

        if (strlen($payload) > 1024 * 1024) {
            wp_send_json_error(array('message' => __('اندازه payload بیش از حد مجاز است.', 'exchange-rate')), 413);
        }

        $source = $this->get_source_by_key($source_key);
        if (empty($source) || empty($source['enabled'])) {
            wp_send_json_error(array('message' => __('منبع یافت نشد یا غیرفعال است.', 'exchange-rate')), 404);
        }

        $snapshot = $this->api->build_snapshot_from_body($source, $payload, $request_url);
        if (is_wp_error($snapshot)) {
            wp_send_json_error(array('message' => $snapshot->get_error_message()), 422);
        }

        $saved = $this->persist_snapshot($source, $snapshot);
        if (is_wp_error($saved)) {
            wp_send_json_error(array('message' => $saved->get_error_message()), 500);
        }

        $last_errors = get_option(self::OPTION_LAST_ERRORS, array());
        if (is_array($last_errors) && isset($last_errors[$source_key])) {
            unset($last_errors[$source_key]);
            update_option(self::OPTION_LAST_ERRORS, $last_errors, false);
        }

        wp_send_json_success(array('message' => __('ذخیره شد.', 'exchange-rate')));
    }

    private function get_live_source_timers($sources)
    {
        $result = array();
        $now = time();

        foreach ((array) $sources as $source) {
            if (empty($source['enabled'])) {
                continue;
            }
            $interval = isset($source['interval_seconds']) ? (int) $source['interval_seconds'] : 0;
            if ($interval < 10) {
                continue;
            }

            $latest = $this->get_latest_snapshot_row($source['key']);
            $last_fetched = !empty($latest['fetched_at']) ? (int) $latest['fetched_at'] : 0;
            $remaining = $last_fetched > 0 ? max(0, $interval - max(0, $now - $last_fetched)) : $interval;

            $browser_relay_url = '';
            if (
                in_array((string) $source['type'], array('ice_api_latest', 'ice_api_history_currency'), true)
                && !empty($source['url'])
            ) {
                $browser_relay_url = (string) $source['url'];
            }

            $result[] = array(
                'key' => (string) $source['key'],
                'interval' => $interval,
                'remaining' => $remaining,
                'browserRelayUrl' => $browser_relay_url,
            );
        }

        return $result;
    }

    private function persist_snapshot($source, $snapshot)
    {
        global $wpdb;

        $date_key = $this->normalize_date_key(isset($snapshot['source_date_key']) ? $snapshot['source_date_key'] : '');
        $source_date = isset($snapshot['source_date']) ? trim((string) $snapshot['source_date']) : '';
        $rows = isset($snapshot['rows']) && is_array($snapshot['rows']) ? $snapshot['rows'] : array();
        $rows_json = wp_json_encode($rows, JSON_UNESCAPED_UNICODE);

        if (false === $rows_json) {
            return new WP_Error('exchange_rate_store_error', __('خطا در ذخیره داده برای دیتابیس.', 'exchange-rate'));
        }

        $now_mysql = current_time('mysql', true);
        $existing_row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, rows_json FROM {$this->table_name} WHERE source_key = %s AND date_key = %s LIMIT 1",
            $source['key'],
            $date_key
        ), ARRAY_A);

        if ('milli_gold_price_detail' === $source['type']) {
            $existing_rows = !empty($existing_row['rows_json']) ? $this->decode_rows_json($existing_row['rows_json']) : array();
            $rows = $this->build_milli_daily_rows($existing_rows, $rows, $date_key);
            $rows_json = wp_json_encode($rows, JSON_UNESCAPED_UNICODE);
            if (false === $rows_json) {
                return new WP_Error('exchange_rate_store_error', __('خطا در ذخیره داده میلی برای دیتابیس.', 'exchange-rate'));
            }
        }

        $data = array(
            'source_key' => $source['key'],
            'source_name' => $source['name'],
            'date_key' => $date_key,
            'source_date' => $source_date,
            'source_url' => esc_url_raw(isset($snapshot['source_url']) ? $snapshot['source_url'] : $source['url']),
            'rows_count' => count($rows),
            'rows_json' => $rows_json,
            'fetched_at' => $now_mysql,
            'updated_at' => $now_mysql,
        );

        $format = array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s');
        if (!empty($existing_row['id'])) {
            $updated = $wpdb->update(
                $this->table_name,
                $data,
                array('id' => (int) $existing_row['id']),
                $format,
                array('%d')
            );
            if (false === $updated) {
                return new WP_Error('exchange_rate_store_error', __('به‌روزرسانی ردیف موجود ناموفق بود.', 'exchange-rate'));
            }
            return true;
        }

        $insert_data = $data;
        $insert_data['created_at'] = $now_mysql;
        $insert_format = array('%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s');
        $inserted = $wpdb->insert($this->table_name, $insert_data, $insert_format);
        if (false === $inserted) {
            return new WP_Error('exchange_rate_store_error', __('درج ردیف جدید ناموفق بود.', 'exchange-rate'));
        }

        return true;
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sources = $this->get_sources();
        $last_errors = get_option(self::OPTION_LAST_ERRORS, array());
        if (!is_array($last_errors)) {
            $last_errors = array();
        }

        $notice = isset($_GET['exchange_rate_notice']) ? sanitize_key(wp_unslash($_GET['exchange_rate_notice'])) : '';
        $metrics = $this->get_dashboard_metrics($sources);
        ?>
        <div class="wrap nerkh-admin">
            <div class="nerkh-admin-hero">
                <div>
                    <h1><?php esc_html_e('نرخ چند؟', 'exchange-rate'); ?></h1>
                    <p><?php esc_html_e('داشبورد هوشمند مدیریت نرخ ارز و طلا', 'exchange-rate'); ?></p>
                    <p class="nerkh-live-meta">
                        <span id="nerkh-live-status"><?php esc_html_e('واکشی خودکار فعال است.', 'exchange-rate'); ?></span>
                    </p>
                </div>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field('exchange_rate_fetch_now'); ?>
                    <input type="hidden" name="action" value="exchange_rate_fetch_now" />
                    <input type="hidden" name="source_key" value="" />
                    <button class="button button-primary nerkh-btn" type="submit"><?php esc_html_e('واکشی همه منابع فعال', 'exchange-rate'); ?></button>
                </form>
            </div>

            <?php if ('fetch_success' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-success"><p><?php esc_html_e('واکشی با موفقیت انجام شد.', 'exchange-rate'); ?></p></div>
            <?php elseif ('fetch_queued' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-success"><p><?php esc_html_e('واکشی همه منابع در صف اجرا قرار گرفت. چند ثانیه دیگر صفحه را تازه‌سازی کنید.', 'exchange-rate'); ?></p></div>
            <?php elseif ('fetch_error' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-error"><p><?php esc_html_e('واکشی برای یک یا چند منبع ناموفق بود.', 'exchange-rate'); ?></p></div>
            <?php endif; ?>

            <div class="nerkh-cards">
                <div class="nerkh-card"><span><?php esc_html_e('کل منابع', 'exchange-rate'); ?></span><strong><?php echo esc_html((string) $metrics['total']); ?></strong></div>
                <div class="nerkh-card"><span><?php esc_html_e('منابع فعال', 'exchange-rate'); ?></span><strong><?php echo esc_html((string) $metrics['enabled']); ?></strong></div>
                <div class="nerkh-card"><span><?php esc_html_e('آپدیت شده امروز', 'exchange-rate'); ?></span><strong><?php echo esc_html((string) $metrics['updated_today']); ?></strong></div>
                <div class="nerkh-card"><span><?php esc_html_e('آخرین واکشی', 'exchange-rate'); ?></span><strong><?php echo esc_html(!empty($metrics['latest_fetch']) ? $this->format_tehran_jalali_datetime((int) $metrics['latest_fetch']) : '-'); ?></strong></div>
            </div>

            <div class="nerkh-panel">
            <table class="widefat striped nerkh-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('منبع', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('کلید', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('تاریخ آخرین داده (شمسی)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('تعداد ردیف', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('بازه واکشی', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('تاریخ آخرین واکشی (تهران)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('ساعت آخرین واکشی (تهران)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('شمارش معکوس', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('اقدام سریع', 'exchange-rate'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($sources)) : ?>
                    <tr><td colspan="9"><?php esc_html_e('منبعی ثبت نشده است.', 'exchange-rate'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($sources as $source) : ?>
                        <?php $snapshot = $this->get_latest_snapshot_row($source['key']); ?>
                        <?php $source_date_jalali = $this->format_source_date_jalali(!empty($snapshot['source_date']) ? (string) $snapshot['source_date'] : '', !empty($snapshot['date_key']) ? (string) $snapshot['date_key'] : '', !empty($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0); ?>
                        <tr>
                            <td><?php echo esc_html($source['name']); ?><?php echo $source['enabled'] ? '' : ' (' . esc_html__('غیرفعال', 'exchange-rate') . ')'; ?></td>
                            <td><code><?php echo esc_html($source['key']); ?></code></td>
                            <td><?php echo esc_html($source_date_jalali); ?></td>
                            <td><?php echo esc_html(!empty($snapshot['rows_count']) ? (string) $snapshot['rows_count'] : '0'); ?></td>
                            <td><?php echo esc_html((string) (isset($source['interval_seconds']) ? (int) $source['interval_seconds'] : 86400)); ?> ثانیه</td>
                            <td><?php echo esc_html(!empty($snapshot['fetched_at']) ? $this->format_tehran_jalali_date((int) $snapshot['fetched_at']) : '-'); ?></td>
                            <td><?php echo esc_html(!empty($snapshot['fetched_at']) ? $this->format_tehran_time((int) $snapshot['fetched_at']) : '-'); ?></td>
                            <td><span class="nerkh-source-countdown" data-source-key="<?php echo esc_attr($source['key']); ?>">-</span></td>
                            <td>
                                <?php if (!empty($source['enabled'])) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('exchange_rate_fetch_now'); ?>
                                        <input type="hidden" name="action" value="exchange_rate_fetch_now" />
                                        <input type="hidden" name="source_key" value="<?php echo esc_attr($source['key']); ?>" />
                                        <?php submit_button(__('واکشی', 'exchange-rate'), 'small nerkh-mini-btn', 'submit', false); ?>
                                    </form>
                                <?php else : ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if (!empty($last_errors[$source['key']]['message'])) : ?>
                            <tr>
                                <td colspan="9" style="color:#b32d2e;">
                                    <?php
                                    echo esc_html($last_errors[$source['key']]['message']);
                                    if (!empty($last_errors[$source['key']]['time'])) {
                                        echo ' - ' . esc_html($this->format_tehran_jalali_datetime((int) $last_errors[$source['key']]['time']));
                                    }
                                    ?>
                                    <?php
                                    $debug_lines = $this->format_error_debug_lines($last_errors[$source['key']]);
                                    if (!empty($debug_lines)) :
                                    ?>
                                        <details class="nerkh-error-debug">
                                            <summary><?php esc_html_e('جزئیات فنی خطا', 'exchange-rate'); ?></summary>
                                            <pre><?php echo esc_html(implode("\n", $debug_lines)); ?></pre>
                                        </details>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    public function render_sources_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $sources = $this->get_sources();
        $notice = isset($_GET['exchange_rate_notice']) ? sanitize_key(wp_unslash($_GET['exchange_rate_notice'])) : '';
        $editing_key = isset($_GET['edit_source']) ? sanitize_key(wp_unslash($_GET['edit_source'])) : '';
        $editing_source = $editing_key !== '' ? $this->get_source_by_key($editing_key) : array();
        $is_editing = !empty($editing_source);
        $is_system_editing = $is_editing && !empty($editing_source['is_system']);
        ?>
        <div class="wrap nerkh-admin">
            <h1><?php esc_html_e('مدیریت منابع نرخ', 'exchange-rate'); ?></h1>

            <?php if ('source_saved' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-success"><p><?php esc_html_e('منبع با موفقیت ذخیره شد.', 'exchange-rate'); ?></p></div>
            <?php elseif ('source_deleted' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-success"><p><?php esc_html_e('منبع حذف شد.', 'exchange-rate'); ?></p></div>
            <?php elseif ('source_locked' === $notice) : ?>
                <div class="nerkh-alert nerkh-alert-error"><p><?php esc_html_e('منابع سیستمی قابل حذف نیستند و فقط بخش‌های مجاز قابل ویرایش هستند.', 'exchange-rate'); ?></p></div>
            <?php endif; ?>

            <div class="nerkh-panel">
            <h2><?php echo $is_editing ? esc_html__('ویرایش منبع', 'exchange-rate') : esc_html__('افزودن یا به‌روزرسانی منبع', 'exchange-rate'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('exchange_rate_save_source'); ?>
                <input type="hidden" name="action" value="exchange_rate_save_source" />
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="source-key"><?php esc_html_e('کلید منبع', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-key" type="text" class="regular-text" name="source_key" placeholder="example: ice_havaleh" value="<?php echo esc_attr($is_editing ? (string) $editing_source['key'] : ''); ?>" <?php echo $is_editing ? 'readonly' : ''; ?> required />
                            <p class="description"><?php esc_html_e('کلید یکتا برای شورت‌کد. مثال: ice_havaleh یا milli_price18', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-name"><?php esc_html_e('نام منبع', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-name" type="text" class="regular-text" name="source_name" placeholder="مثال: ICE - نرخ حواله" value="<?php echo esc_attr($is_editing ? (string) $editing_source['name'] : ''); ?>" <?php echo $is_system_editing ? 'readonly' : 'required'; ?> />
                            <?php if ($is_system_editing) : ?><p class="description"><?php esc_html_e('منبع سیستمی: نام قابل تغییر نیست.', 'exchange-rate'); ?></p><?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-display-title"><?php esc_html_e('عنوان نمایشی کارت', 'exchange-rate'); ?></label></th>
                        <td><input id="source-display-title" type="text" class="regular-text" name="source_display_title" placeholder="مثال: نمای کلی بازار حواله" value="<?php echo esc_attr($is_editing ? (string) $editing_source['display_title'] : ''); ?>" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-header-text"><?php esc_html_e('توضیح هدر کارت', 'exchange-rate'); ?></label></th>
                        <td><textarea id="source-header-text" name="source_header_text" rows="2" class="large-text" placeholder="مثال: نرخ حواله بر اساس معاملات ثبت شده توسط بانک ها ..."><?php echo esc_textarea($is_editing ? (string) $editing_source['header_text'] : ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-url"><?php esc_html_e('آدرس منبع', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-url" type="url" class="regular-text ltr" name="source_url" value="<?php echo esc_attr($is_editing && !empty($editing_source['is_system']) ? '' : ($is_editing ? (string) $editing_source['url'] : '')); ?>" <?php echo ($is_editing && !empty($editing_source['is_system'])) ? 'placeholder="' . esc_attr(self::SYSTEM_URL_MASK) . '" readonly' : 'required'; ?> />
                            <p class="description"><?php esc_html_e('آدرس API یا صفحه جدول داده. فقط مقصدهای عمومی (Public) امن پذیرفته می‌شوند؛ localhost/شبکه داخلی برای امنیت مسدود است.', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-proxy-url"><?php esc_html_e('آدرس رله/پروکسی (اختیاری)', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-proxy-url" type="url" class="regular-text ltr" name="source_proxy_url" placeholder="https://relay.example.com/ice-latest" value="<?php echo esc_attr($is_editing && !empty($editing_source['is_system']) ? '' : ($is_editing ? (string) $editing_source['proxy_url'] : '')); ?>" <?php echo $is_system_editing ? 'readonly' : ''; ?> />
                            <p class="description"><?php esc_html_e('اختیاری: اگر سرور شما به منبع مستقیم وصل نمی‌شود، آدرس رله را بگذارید. در حالت پیش‌فرض، افزونه هنگام باز بودن داشبورد تلاش می‌کند داده را از مرورگر ادمین دریافت و ذخیره کند.', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-type"><?php esc_html_e('نوع منبع', 'exchange-rate'); ?></label></th>
                        <td>
                            <select id="source-type" name="source_type" <?php echo $is_system_editing ? 'disabled' : ''; ?>>
                                <option value="ice_api_latest" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'ice_api_latest'); ?>><?php esc_html_e('API نرخ حواله ICE', 'exchange-rate'); ?></option>
                                <option value="ice_api_history_currency" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'ice_api_history_currency'); ?>><?php esc_html_e('API تاریخچه ارز ICE', 'exchange-rate'); ?></option>
                                <option value="milli_gold_price_detail" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'milli_gold_price_detail'); ?>><?php esc_html_e('قیمت طلای میلی (18 عیار)', 'exchange-rate'); ?></option>
                                <option value="navasan_aed_based" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'navasan_aed_based'); ?>><?php esc_html_e('JSON نوسان (aed_based_rates)', 'exchange-rate'); ?></option>
                                <option value="cbi_tspd" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'cbi_tspd'); ?>><?php esc_html_e('CBI محافظت‌شده (TSPD)', 'exchange-rate'); ?></option>
                                <option value="html_table" <?php selected($is_editing ? (string) $editing_source['type'] : '', 'html_table'); ?>><?php esc_html_e('خواندن جدول HTML', 'exchange-rate'); ?></option>
                            </select>
                            <?php if ($is_system_editing) : ?>
                                <input type="hidden" name="source_type" value="<?php echo esc_attr((string) $editing_source['type']); ?>" />
                                <p class="description"><?php esc_html_e('منبع سیستمی: نوع قابل تغییر نیست.', 'exchange-rate'); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e('براساس سایت مبدا انتخاب کنید: ICE (latest/history)، میلی (price18)، نوسان (aed_based_rates)، CBI (TSPD محافظت‌شده).', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-interval"><?php esc_html_e('بازه واکشی خودکار (ثانیه)', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-interval" type="number" min="10" step="1" class="small-text" name="source_interval" value="<?php echo esc_attr((string) ($is_editing ? (int) $editing_source['interval_seconds'] : 86400)); ?>" required />
                            <p class="description"><?php esc_html_e('مثال: 10 برای میلی (لحظه‌ای)، 86400 برای منابع روزانه مثل ICE.', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-enabled"><?php esc_html_e('فعال باشد', 'exchange-rate'); ?></label></th>
                        <td>
                            <input id="source-enabled" type="checkbox" name="source_enabled" value="1" <?php checked($is_editing ? (int) $editing_source['enabled'] : 1, 1); ?> <?php echo $is_system_editing ? 'disabled' : ''; ?> />
                            <?php if ($is_system_editing) : ?>
                                <input type="hidden" name="source_enabled" value="<?php echo !empty($editing_source['enabled']) ? '1' : '0'; ?>" />
                                <p class="description"><?php esc_html_e('منبع سیستمی: وضعیت فعال/غیرفعال از این بخش قابل تغییر نیست.', 'exchange-rate'); ?></p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-notes"><?php esc_html_e('یادداشت', 'exchange-rate'); ?></label></th>
                        <td><textarea id="source-notes" name="source_notes" rows="3" class="large-text"><?php echo esc_textarea($is_editing ? (string) $editing_source['notes'] : ''); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="source-headers"><?php esc_html_e('هدر خام (اختیاری)', 'exchange-rate'); ?></label></th>
                        <td>
                            <textarea id="source-headers" name="source_headers" rows="6" class="large-text code" placeholder="<?php echo esc_attr($is_system_editing ? self::SYSTEM_URL_MASK : 'Cookie: ...&#10;X-Security-CSRF-Token: ...&#10;X-TS-AJAX-Request: true'); ?>" <?php echo $is_system_editing ? 'readonly' : ''; ?>><?php echo esc_textarea($is_editing && !$is_system_editing ? (string) $editing_source['headers_raw'] : ''); ?></textarea>
                            <p class="description"><?php esc_html_e('هر خط با فرمت Header: value. بیشتر برای CBI لازم است. برای ICE و میلی معمولاً خالی بماند.', 'exchange-rate'); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button($is_editing ? __('به‌روزرسانی منبع', 'exchange-rate') : __('ذخیره منبع', 'exchange-rate')); ?>
                <?php if ($is_editing) : ?>
                    <a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'exchange-rate-sources'), admin_url('admin.php'))); ?>"><?php esc_html_e('لغو ویرایش', 'exchange-rate'); ?></a>
                <?php endif; ?>
            </form>
            </div>

            <h2><?php esc_html_e('منابع ثبت شده', 'exchange-rate'); ?></h2>
            <div class="nerkh-panel">
            <p>
                <input id="nerkh-source-search" type="search" class="regular-text" placeholder="<?php echo esc_attr__('جستجو بر اساس نام منبع، کلید یا نوع...', 'exchange-rate'); ?>" />
            </p>
            <table class="widefat striped nerkh-table">
                <thead>
                <tr>
                    <th><?php esc_html_e('نام', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('کلید', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('نوع', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('آدرس', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('رله', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('بازه', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('هدر', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('وضعیت', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('ویرایش', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('حذف', 'exchange-rate'); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($sources)) : ?>
                    <tr><td colspan="10"><?php esc_html_e('منبعی ثبت نشده است.', 'exchange-rate'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($sources as $source) : ?>
                        <tr class="nerkh-source-main-row" data-search="<?php echo esc_attr(strtolower(trim(($source['name'] ?? '') . ' ' . ($source['key'] ?? '') . ' ' . ($source['type'] ?? '')))); ?>">
                            <td><?php echo esc_html($source['name']); ?></td>
                            <td><code><?php echo esc_html($source['key']); ?></code></td>
                            <td><?php echo esc_html($source['type']); ?></td>
                            <td><code><?php echo esc_html(!empty($source['is_system']) ? self::SYSTEM_URL_MASK : (string) $source['url']); ?></code></td>
                            <td><?php echo !empty($source['is_system']) ? esc_html(self::SYSTEM_URL_MASK) : (!empty($source['proxy_url']) ? '<code>' . esc_html($source['proxy_url']) . '</code>' : '-'); ?></td>
                            <td><?php echo esc_html((string) (isset($source['interval_seconds']) ? (int) $source['interval_seconds'] : 86400)); ?> ثانیه</td>
                            <td><?php echo !empty($source['headers_raw']) ? esc_html__('دارد', 'exchange-rate') : esc_html__('ندارد', 'exchange-rate'); ?></td>
                            <td><?php echo !empty($source['is_system']) ? esc_html__('سیستمی', 'exchange-rate') : ($source['enabled'] ? esc_html__('فعال', 'exchange-rate') : esc_html__('غیرفعال', 'exchange-rate')); ?></td>
                            <td>
                                <a class="button button-secondary button-small" href="<?php echo esc_url(add_query_arg(array('page' => 'exchange-rate-sources', 'edit_source' => $source['key']), admin_url('admin.php'))); ?>">
                                    <?php esc_html_e('ویرایش', 'exchange-rate'); ?>
                                </a>
                            </td>
                            <td>
                                <?php if (!empty($source['is_system'])) : ?>
                                    <span>-</span>
                                <?php else : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('این منبع حذف شود؟', 'exchange-rate')); ?>');">
                                        <?php wp_nonce_field('exchange_rate_delete_source'); ?>
                                        <input type="hidden" name="action" value="exchange_rate_delete_source" />
                                        <input type="hidden" name="source_key" value="<?php echo esc_attr($source['key']); ?>" />
                                        <?php submit_button(__('حذف', 'exchange-rate'), 'delete small', 'submit', false); ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr class="nerkh-shortcode-row">
                            <td colspan="10">
                                <details>
                                    <summary><?php esc_html_e('شورت‌کدهای سریع این منبع', 'exchange-rate'); ?></summary>
                                    <p>
                                        <button type="button" class="button button-small nerkh-copy-btn" data-copy="<?php echo esc_attr($this->build_shortcodes_text_for_source($source['key'])); ?>">
                                            <?php esc_html_e('کپی همه 6 شورت‌کد', 'exchange-rate'); ?>
                                        </button>
                                    </p>
                                    <div class="nerkh-shortcode-grid">
                                        <?php foreach ($this->build_shortcodes_for_source($source['key']) as $item) : ?>
                                            <div class="nerkh-shortcode-item">
                                                <span class="nerkh-shortcode-label"><?php echo esc_html($item['label']); ?></span>
                                                <code><?php echo esc_html($item['code']); ?></code>
                                                <button type="button" class="button button-small nerkh-copy-btn" data-copy="<?php echo esc_attr($item['code']); ?>">
                                                    <?php esc_html_e('کپی', 'exchange-rate'); ?>
                                                </button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
        </div>
        <?php
    }

    public function render_guide_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        ?>
        <div class="wrap nerkh-admin">
            <h1><?php esc_html_e('راهنمای شورت کد و المنتور', 'exchange-rate'); ?></h1>
            <div class="nerkh-panel">
            <h2><?php esc_html_e('منابع پیش‌فرض فعلی', 'exchange-rate'); ?></h2>
            <p><?php esc_html_e('ICE (حواله و تاریخچه دلار) از API های api.ice.ir خوانده می‌شود.', 'exchange-rate'); ?></p>
            <p><?php esc_html_e('میلی (طلای 18 عیار) از API سایت milli.gold خوانده می‌شود.', 'exchange-rate'); ?></p>
            <p><?php esc_html_e('منبع CBI (حواله) هم به‌صورت پیش‌فرض اضافه شده و در صورت نیاز می‌توانید ویرایشش کنید.', 'exchange-rate'); ?></p>

            <h2><?php esc_html_e('نمونه شورت‌کد بر اساس منبع', 'exchange-rate'); ?></h2>
            <p><code>[exchange_rate]</code></p>
            <p><?php esc_html_e('نمایش منبع پیش‌فرض (معمولاً حواله ICE).', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="ice_havaleh"]</code></p>
            <p><?php esc_html_e('نمای کلی بازار حواله ICE.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="ice_havaleh" symbols="USD,EUR,AED"]</code></p>
            <p><?php esc_html_e('فقط چند ارز انتخابی از جدول حواله ICE.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="ice_havaleh" date="2026-02-21" limit="5"]</code></p>
            <p><?php esc_html_e('نمایش داده یک تاریخ مشخص با محدودیت ردیف.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="ice_havaleh" symbols="USD,EUR" date="latest" title="نرخ منتخب"]</code></p>
            <p><?php esc_html_e('عنوان سفارشی + فیلتر نماد.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="ice_usd_history" limit="20" title="تاریخچه دلار آمریکا"]</code></p>
            <p><?php esc_html_e('تاریخچه دلار ICE.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" title="طلای 18 عیار - کمینه/بیشینه روز"]</code></p>
            <p><?php esc_html_e('کمینه/بیشینه روزانه طلا از میلی.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="cbi_havaleh" title="نرخ حواله CBI"]</code></p>
            <p><?php esc_html_e('مثال برای منبعی که دستی از CBI ثبت کرده‌اید.', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="cbi_havaleh" view="cards" title="نرخ حواله CBI"]</code></p>
            <p><?php esc_html_e('نمای کارت برای جدول حواله CBI.', 'exchange-rate'); ?></p>

            <h2><?php esc_html_e('6 خروجی خرد برای هر منبع', 'exchange-rate'); ?></h2>
            <p><code>[exchange_rate source="milli_price18"]</code> - <?php esc_html_e('1) خروجی کامل فعلی', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" section="title"]</code> - <?php esc_html_e('2) فقط عنوان', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" section="description"]</code> - <?php esc_html_e('3) فقط توضیحات', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" section="source_meta"]</code> - <?php esc_html_e('4) فقط نام منبع + تاریخ منبع (بدون برچسب)', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" section="fetch_date"]</code> - <?php esc_html_e('5) فقط تاریخ واکشی', 'exchange-rate'); ?></p>
            <p><code>[exchange_rate source="milli_price18" section="table_only"]</code> - <?php esc_html_e('6) فقط جدول (بدون متن‌های بالا/پایین)', 'exchange-rate'); ?></p>

            <h2><?php esc_html_e('پارامترها', 'exchange-rate'); ?></h2>
            <ul>
                <li><code>source</code>: <?php esc_html_e('کلید منبع از صفحه منابع.', 'exchange-rate'); ?></li>
                <li><code>symbols</code>: <?php esc_html_e('کد ارزها با کاما. خالی = همه.', 'exchange-rate'); ?></li>
                <li><code>date</code>: <?php esc_html_e('latest یا تاریخ به فرم YYYY-MM-DD', 'exchange-rate'); ?></li>
                <li><code>limit</code>: <?php esc_html_e('0 یعنی بدون محدودیت.', 'exchange-rate'); ?></li>
                <li><code>title</code>: <?php esc_html_e('عنوان دلخواه جدول/کارت.', 'exchange-rate'); ?></li>
                <li><code>subtitle</code>: <?php esc_html_e('توضیح کوتاه زیر عنوان.', 'exchange-rate'); ?></li>
                <li><code>view</code>: <?php esc_html_e('table یا cards یا ticker', 'exchange-rate'); ?></li>
                <li><code>section</code>: <?php esc_html_e('full, title, description, source_meta, fetch_date, table_only', 'exchange-rate'); ?></li>
            </ul>

            <h2><?php esc_html_e('راهنمای CBI محافظت شده', 'exchange-rate'); ?></h2>
            <p><?php esc_html_e('برای منبع CBI نوع CBI TSPD را انتخاب کنید و هدرهای لازم (Cookie, X-Security-CSRF-Token و...) را در صفحه منابع وارد کنید.', 'exchange-rate'); ?></p>
            <h2><?php esc_html_e('راهنمای رله/پروکسی برای سرور خارجی', 'exchange-rate'); ?></h2>
            <p><?php esc_html_e('اگر هاست شما به منبع مستقیم وصل نمی‌شود، در صفحه منابع فیلد «آدرس رله/پروکسی» را پر کنید. افزونه ابتدا رله را تست می‌کند و در صورت موفقیت از همان داده می‌خواند.', 'exchange-rate'); ?></p>
            <p><?php esc_html_e('برای کارکرد 24/7، یک Cloudflare Worker بسازید (نمونه کد: docs/cloudflare-worker.js) و سپس این خط را در wp-config.php قرار دهید:', 'exchange-rate'); ?></p>
            <p><code>define('EXCHANGE_RATE_ICE_RELAY_URL', 'https://your-worker.your-subdomain.workers.dev/fetch');</code></p>
            <p><?php esc_html_e('بعد از ذخیره و یک بار غیرفعال/فعال کردن افزونه، برای منابع ICE رله به‌صورت پیش‌فرض اعمال می‌شود.', 'exchange-rate'); ?></p>

            <h2><?php esc_html_e('ویجت های المنتور', 'exchange-rate'); ?></h2>
            <p><?php esc_html_e('در Elementor دسته «نرخ چند؟» اضافه می‌شود با چهار ویجت: جدول، کارت‌ها، تیکر و «بخش خروجی» برای 6 خروجی خرد.', 'exchange-rate'); ?></p>
            </div>
        </div>
        <?php
    }

    public function render_shortcode($atts)
    {
        $atts = shortcode_atts(array(
            'source' => 'ice_havaleh',
            'title' => '',
            'subtitle' => '',
            'limit' => '0',
            'symbols' => '',
            'date' => 'latest',
            'view' => 'table',
            'section' => 'full',
        ), $atts, 'exchange_rate');

        $source_key = sanitize_key((string) $atts['source']);
        if ($source_key === '') {
            $source_key = 'ice_havaleh';
        }

        $source_config = $this->get_source_by_key($source_key);
        if (empty($source_config)) {
            return '<p>' . esc_html__('منبع انتخابی وجود ندارد.', 'exchange-rate') . '</p>';
        }

        if (empty($source_config['enabled'])) {
            return '<p>' . esc_html__('این منبع غیرفعال است.', 'exchange-rate') . '</p>';
        }

        $date = trim((string) $atts['date']);
        if ($date === '' || strtolower($date) === 'latest') {
            $snapshot = $this->get_latest_snapshot_row($source_key);
        } else {
            $snapshot = $this->get_snapshot_row_by_date($source_key, $date);
        }

        $rows = !empty($snapshot['rows']) && is_array($snapshot['rows']) ? $snapshot['rows'] : array();

        if (empty($rows)) {
            return '<p>' . esc_html__('برای این منبع یا تاریخ، داده‌ای موجود نیست.', 'exchange-rate') . '</p>';
        }

        $symbols = $this->parse_symbols((string) $atts['symbols']);
        if (!empty($symbols)) {
            $rows = array_values(array_filter($rows, function ($row) use ($symbols) {
                $code = isset($row['currency_code']) ? strtoupper(trim((string) $row['currency_code'])) : '';
                return $code !== '' && in_array($code, $symbols, true);
            }));
        }

        if (empty($rows)) {
            return '<p>' . esc_html__('با فیلتر انتخابی، داده‌ای پیدا نشد.', 'exchange-rate') . '</p>';
        }

        $limit = max(0, (int) $atts['limit']);
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $is_history_view = isset($rows[0]['row_date']) && trim((string) $rows[0]['row_date']) !== '';
        $is_milli_summary = isset($rows[0]['day_low']) || isset($rows[0]['day_high']);
        $view = strtolower(trim((string) $atts['view']));
        if (!in_array($view, array('table', 'cards', 'ticker'), true)) {
            $view = 'table';
        }
        $section = strtolower(trim((string) $atts['section']));
        if (!in_array($section, array('full', 'title', 'description', 'source_meta', 'fetch_date', 'table_only'), true)) {
            $section = 'full';
        }

        $default_title = !empty($source_config['display_title']) ? (string) $source_config['display_title'] : (string) $source_config['name'];
        if ($default_title === '') {
            $default_title = __('نرخ چند؟', 'exchange-rate');
        }

        $title = trim((string) $atts['title']);
        if ($title === '') {
            $title = $default_title;
        }

        $subtitle = trim((string) $atts['subtitle']);
        if ($subtitle === '') {
            $subtitle = !empty($source_config['header_text']) ? (string) $source_config['header_text'] : '';
        }

        $source_date_jalali = $this->format_source_date_jalali(
            !empty($snapshot['source_date']) ? (string) $snapshot['source_date'] : '',
            !empty($snapshot['date_key']) ? (string) $snapshot['date_key'] : '',
            !empty($snapshot['fetched_at']) ? (int) $snapshot['fetched_at'] : 0
        );
        $fetch_date_jalali = !empty($snapshot['fetched_at']) ? $this->format_tehran_jalali_date((int) $snapshot['fetched_at']) : '-';

        wp_enqueue_style('exchange-rate-style');

        if ($section === 'title') {
            return '<span class="nerkhchand-scope exchange-rate-title-only">' . esc_html($title) . '</span>';
        }

        if ($section === 'description') {
            return '<span class="nerkhchand-scope exchange-rate-description-only">' . esc_html($subtitle) . '</span>';
        }

        if ($section === 'source_meta') {
            $name_only = !empty($snapshot['source_name']) ? (string) $snapshot['source_name'] : '-';
            $date_only = $source_date_jalali !== '' ? $source_date_jalali : '-';
            return '<div class="nerkhchand-scope exchange-rate-source-meta-only"><div>' . esc_html($name_only) . '</div><div>' . esc_html($date_only) . '</div></div>';
        }

        if ($section === 'fetch_date') {
            return '<span class="nerkhchand-scope exchange-rate-fetch-date-only">' . esc_html($fetch_date_jalali) . '</span>';
        }

        if ($section === 'table_only') {
            return '<div class="nerkhchand-scope nerkhchand-table-only">' . $this->build_table_only_markup($rows, $is_milli_summary, $is_history_view) . '</div>';
        }

        $wrapper_class = 'nerkhchand-scope exchange-rate-box exchange-rate-view-' . $view;

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
            <div class="exchange-rate-head">
                <h3 class="exchange-rate-title"><?php echo esc_html($title); ?></h3>
                <?php if ($subtitle !== '') : ?>
                    <p class="exchange-rate-lead"><?php echo esc_html($subtitle); ?></p>
                <?php endif; ?>
            </div>
            <div class="exchange-rate-meta-list">
            <?php if (!empty($snapshot['source_name'])) : ?>
                <p class="exchange-rate-meta"><?php echo esc_html($snapshot['source_name']); ?></p>
            <?php endif; ?>
            <?php if ($source_date_jalali !== '-') : ?>
                <p class="exchange-rate-meta"><?php echo esc_html(sprintf(__('تاریخ منبع: %s', 'exchange-rate'), $source_date_jalali)); ?></p>
            <?php endif; ?>
            </div>

            <?php if ('cards' === $view) : ?>
                <div class="exchange-rate-cards">
                    <?php foreach ($rows as $row) : ?>
                        <div class="exchange-rate-rate-card">
                            <?php if ($is_milli_summary) : ?>
                                <h4><?php echo esc_html($this->format_row_date_jalali(isset($row['row_date']) ? (string) $row['row_date'] : '-')); ?></h4>
                                <p><span><?php esc_html_e('آخرین قیمت', 'exchange-rate'); ?></span><strong><?php echo esc_html(number_format_i18n((int) (isset($row['last_price']) ? $row['last_price'] : 0))); ?></strong></p>
                                <p><span><?php esc_html_e('کمترین', 'exchange-rate'); ?></span><strong><?php echo esc_html(number_format_i18n((int) (isset($row['day_low']) ? $row['day_low'] : 0))); ?></strong></p>
                                <p><span><?php esc_html_e('بیشترین', 'exchange-rate'); ?></span><strong><?php echo esc_html(number_format_i18n((int) (isset($row['day_high']) ? $row['day_high'] : 0))); ?></strong></p>
                            <?php else : ?>
                                <?php
                                $name = isset($row['currency_name']) ? (string) $row['currency_name'] : (isset($row['row_date']) ? (string) $row['row_date'] : '-');
                                $code = isset($row['currency_code']) ? (string) $row['currency_code'] : '';
                                ?>
                                <h4><?php echo esc_html(trim($name . ($code !== '' ? ' (' . $code . ')' : ''))); ?></h4>
                                <p><span><?php esc_html_e('خرید', 'exchange-rate'); ?></span><strong><?php echo esc_html(number_format_i18n((int) (isset($row['buy']) ? $row['buy'] : 0))); ?></strong></p>
                                <p><span><?php esc_html_e('فروش', 'exchange-rate'); ?></span><strong><?php echo esc_html(number_format_i18n((int) (isset($row['sell']) ? $row['sell'] : 0))); ?></strong></p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php elseif ('ticker' === $view) : ?>
                <div class="exchange-rate-ticker">
                    <?php foreach ($rows as $row) : ?>
                        <?php
                        $name = isset($row['currency_code']) && $row['currency_code'] !== '' ? (string) $row['currency_code'] : (isset($row['currency_name']) ? (string) $row['currency_name'] : (isset($row['row_date']) ? (string) $row['row_date'] : '-'));
                        $buy = isset($row['buy']) ? (int) $row['buy'] : (isset($row['last_price']) ? (int) $row['last_price'] : 0);
                        $sell = isset($row['sell']) ? (int) $row['sell'] : 0;
                        ?>
                        <div class="exchange-rate-pill">
                            <b><?php echo esc_html($name); ?></b>
                            <span><?php esc_html_e('خرید', 'exchange-rate'); ?>: <?php echo esc_html(number_format_i18n($buy)); ?></span>
                            <?php if ($sell > 0) : ?><span><?php esc_html_e('فروش', 'exchange-rate'); ?>: <?php echo esc_html(number_format_i18n($sell)); ?></span><?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
            <table class="exchange-rate-table">
                <thead>
                <tr>
                    <?php if ($is_milli_summary) : ?>
                        <th><?php esc_html_e('تاریخ', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('آخرین قیمت 18 عیار (ریال)', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('کمترین روز (ریال)', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('بیشترین روز (ریال)', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('تعداد نمونه', 'exchange-rate'); ?></th>
                    <?php elseif ($is_history_view) : ?>
                        <th><?php esc_html_e('تاریخ', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('خرید (ریال)', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('فروش (ریال)', 'exchange-rate'); ?></th>
                    <?php else : ?>
                        <th><?php esc_html_e('ارز', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('خرید (ریال)', 'exchange-rate'); ?></th>
                        <th><?php esc_html_e('فروش (ریال)', 'exchange-rate'); ?></th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row) : ?>
                    <tr>
                        <?php if ($is_milli_summary) : ?>
                            <td><?php echo esc_html($this->format_row_date_jalali(isset($row['row_date']) ? (string) $row['row_date'] : '-')); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['last_price']) ? $row['last_price'] : 0))); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['day_low']) ? $row['day_low'] : 0))); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['day_high']) ? $row['day_high'] : 0))); ?></td>
                            <td><?php echo esc_html((string) (isset($row['samples']) ? (int) $row['samples'] : 0)); ?></td>
                        <?php elseif ($is_history_view) : ?>
                            <td><?php echo esc_html($this->format_row_date_jalali(isset($row['row_date']) ? (string) $row['row_date'] : '-')); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['buy']) ? $row['buy'] : 0))); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['sell']) ? $row['sell'] : 0))); ?></td>
                        <?php else : ?>
                            <td>
                                <?php
                                $name = isset($row['currency_name']) ? (string) $row['currency_name'] : '';
                                $code = isset($row['currency_code']) ? (string) $row['currency_code'] : '';
                                echo esc_html(trim($name . ($code !== '' ? ' (' . $code . ')' : '')));
                                ?>
                            </td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['buy']) ? $row['buy'] : 0))); ?></td>
                            <td><?php echo esc_html(number_format_i18n((int) (isset($row['sell']) ? $row['sell'] : 0))); ?></td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php if (!empty($snapshot['fetched_at'])) : ?>
                <p class="exchange-rate-updated">
                    <?php echo esc_html(sprintf(__('آخرین واکشی: %s', 'exchange-rate'), $this->format_tehran_jalali_datetime((int) $snapshot['fetched_at']))); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php

        return ob_get_clean();
    }

    private function build_shortcodes_for_source($source_key)
    {
        $source_key = sanitize_key((string) $source_key);
        if ($source_key === '') {
            return array();
        }

        return array(
            array('label' => 'خروجی کامل', 'code' => '[exchange_rate source="' . $source_key . '"]'),
            array('label' => 'فقط عنوان', 'code' => '[exchange_rate source="' . $source_key . '" section="title"]'),
            array('label' => 'فقط توضیح', 'code' => '[exchange_rate source="' . $source_key . '" section="description"]'),
            array('label' => 'نام منبع + تاریخ منبع', 'code' => '[exchange_rate source="' . $source_key . '" section="source_meta"]'),
            array('label' => 'فقط تاریخ واکشی', 'code' => '[exchange_rate source="' . $source_key . '" section="fetch_date"]'),
            array('label' => 'فقط جدول', 'code' => '[exchange_rate source="' . $source_key . '" section="table_only"]'),
        );
    }

    private function build_shortcodes_text_for_source($source_key)
    {
        $items = $this->build_shortcodes_for_source($source_key);
        $codes = array();
        foreach ($items as $item) {
            if (!empty($item['code'])) {
                $codes[] = (string) $item['code'];
            }
        }

        return implode("\n", $codes);
    }

    private function build_table_only_markup($rows, $is_milli_summary, $is_history_view)
    {
        ob_start();
        ?>
        <table class="exchange-rate-table">
            <thead>
            <tr>
                <?php if ($is_milli_summary) : ?>
                    <th><?php esc_html_e('تاریخ', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('آخرین قیمت 18 عیار (ریال)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('کمترین روز (ریال)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('بیشترین روز (ریال)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('تعداد نمونه', 'exchange-rate'); ?></th>
                <?php elseif ($is_history_view) : ?>
                    <th><?php esc_html_e('تاریخ', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('خرید (ریال)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('فروش (ریال)', 'exchange-rate'); ?></th>
                <?php else : ?>
                    <th><?php esc_html_e('ارز', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('خرید (ریال)', 'exchange-rate'); ?></th>
                    <th><?php esc_html_e('فروش (ریال)', 'exchange-rate'); ?></th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ((array) $rows as $row) : ?>
                <tr>
                    <?php if ($is_milli_summary) : ?>
                        <td><?php echo esc_html($this->format_row_date_jalali(isset($row['row_date']) ? (string) $row['row_date'] : '-')); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['last_price']) ? $row['last_price'] : 0))); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['day_low']) ? $row['day_low'] : 0))); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['day_high']) ? $row['day_high'] : 0))); ?></td>
                        <td><?php echo esc_html((string) (isset($row['samples']) ? (int) $row['samples'] : 0)); ?></td>
                    <?php elseif ($is_history_view) : ?>
                        <td><?php echo esc_html($this->format_row_date_jalali(isset($row['row_date']) ? (string) $row['row_date'] : '-')); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['buy']) ? $row['buy'] : 0))); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['sell']) ? $row['sell'] : 0))); ?></td>
                    <?php else : ?>
                        <td>
                            <?php
                            $name = isset($row['currency_name']) ? (string) $row['currency_name'] : '';
                            $code = isset($row['currency_code']) ? (string) $row['currency_code'] : '';
                            echo esc_html(trim($name . ($code !== '' ? ' (' . $code . ')' : '')));
                            ?>
                        </td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['buy']) ? $row['buy'] : 0))); ?></td>
                        <td><?php echo esc_html(number_format_i18n((int) (isset($row['sell']) ? $row['sell'] : 0))); ?></td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    private function parse_symbols($raw_symbols)
    {
        $raw_symbols = trim((string) $raw_symbols);
        if ($raw_symbols === '') {
            return array();
        }

        $list = array_map('trim', explode(',', strtoupper($raw_symbols)));
        $list = array_filter($list, function ($item) {
            return $item !== '';
        });

        return array_values(array_unique($list));
    }
}
