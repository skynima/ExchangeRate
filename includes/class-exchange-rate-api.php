<?php

if (!defined('ABSPATH')) {
    exit;
}

class Exchange_Rate_API
{
    const DEFAULT_SOURCE_URL = 'https://api.ice.ir/api/v1/markets/2/currencies/history/latest/?lang=fa';

    public function fetch_snapshot($source)
    {
        $source = is_array($source) ? $source : array();

        $source_key = isset($source['key']) ? sanitize_key($source['key']) : '';
        $source_url = isset($source['url']) ? $this->sanitize_url($source['url']) : '';
        $proxy_url = $this->resolve_proxy_url_template(
            isset($source['proxy_url']) ? (string) $source['proxy_url'] : '',
            $source_url,
            isset($source['key']) ? sanitize_key($source['key']) : '',
            isset($source['type']) ? sanitize_key($source['type']) : 'ice_api_latest'
        );
        $source_type = isset($source['type']) ? sanitize_key($source['type']) : 'ice_api_latest';

        if ($source_key === '') {
            return new WP_Error('exchange_rate_invalid_source', __('کلید منبع نامعتبر است.', 'exchange-rate'));
        }

        if ($source_url === '') {
            $source_url = self::DEFAULT_SOURCE_URL;
        }

        $headers = array(
            'Accept' => 'application/json, text/plain, */*',
            'User-Agent' => $this->browser_user_agent(),
            'Accept-Language' => 'fa-IR,fa;q=0.9,en;q=0.8',
        );

        if ('milli_gold_price_detail' === $source_type || strpos($source_url, 'milli.gold') !== false) {
            $headers['Origin'] = 'https://milli.gold';
            $headers['Referer'] = 'https://milli.gold/app/home';
            $headers['x-channel'] = 'MILLI';
            $headers['x-platform'] = 'PWA';
        }

        if (strpos($source_url, 'fxmarketrate.cbi.ir') !== false) {
            $headers['Referer'] = 'https://fxmarketrate.cbi.ir/';
            $headers['Origin'] = 'https://fxmarketrate.cbi.ir';
            $headers['X-Requested-With'] = 'XMLHttpRequest';
        }

        $custom_headers = $this->parse_custom_headers(isset($source['headers_raw']) ? (string) $source['headers_raw'] : '');
        if (!empty($custom_headers)) {
            $headers = array_merge($headers, $custom_headers);
        }

        $response = null;
        $status_code = 0;
        $body = '';
        $request_url = $source_url;
        $attempt_logs = array();
        $last_wp_error = null;

        $candidate_urls = $this->build_candidate_urls($source_url, $source_type);
        if ($proxy_url !== '') {
            array_unshift($candidate_urls, $proxy_url);
        }
        $max_attempts = $this->is_ice_source($source_url, $source_type) ? 2 : 1;
        $timeout = ($proxy_url !== '') ? 18 : ($this->is_ice_source($source_url, $source_type) ? 12 : 18);

        foreach ($candidate_urls as $candidate_url) {
            for ($attempt = 1; $attempt <= $max_attempts; $attempt++) {
                $started_at = microtime(true);
                $request_headers = $headers;
                if ($attempt > 1 && $this->is_ice_source($candidate_url, $source_type)) {
                    // Simplify retry headers for ICE in case edge firewall rejects some headers.
                    $request_headers = array(
                        'Accept' => 'application/json, text/plain, */*',
                        'User-Agent' => $this->browser_user_agent(),
                    );
                }

                $response = wp_remote_get($candidate_url, array(
                    'timeout' => $timeout,
                    'redirection' => 8,
                    'httpversion' => '1.1',
                    'headers' => $request_headers,
                ));

                if (is_wp_error($response)) {
                    $last_wp_error = $response;
                    $attempt_logs[] = array(
                        'url' => esc_url_raw($candidate_url),
                        'attempt' => $attempt,
                        'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
                        'result' => 'wp_error',
                        'error_code' => (string) $response->get_error_code(),
                        'error_message' => (string) $response->get_error_message(),
                    );
                    if ($attempt < $max_attempts) {
                        usleep(250000 * $attempt);
                    }
                    continue;
                }

                $status_code = (int) wp_remote_retrieve_response_code($response);
                $body = (string) wp_remote_retrieve_body($response);
                $attempt_logs[] = array(
                    'url' => esc_url_raw($candidate_url),
                    'attempt' => $attempt,
                    'duration_ms' => (int) round((microtime(true) - $started_at) * 1000),
                    'result' => 'http',
                    'http_code' => $status_code,
                    'body_size' => strlen($body),
                    'server' => (string) wp_remote_retrieve_header($response, 'server'),
                    'x_cache' => (string) wp_remote_retrieve_header($response, 'x-cache'),
                );

                if ($status_code >= 200 && $status_code < 300 && $body !== '') {
                    $request_url = $candidate_url;
                    break 2;
                }

                if ($attempt < $max_attempts) {
                    usleep(250000 * $attempt);
                }
            }
        }

        if ($status_code < 200 || $status_code >= 300 || empty($body)) {
            if ($status_code > 0) {
                $message = sprintf(__('پاسخ معتبر از منبع دریافت نشد (کد: %d).', 'exchange-rate'), $status_code);
            } elseif ($last_wp_error) {
                $message = sprintf(
                    __('اتصال به منبع انجام نشد. جزئیات: %s', 'exchange-rate'),
                    $last_wp_error->get_error_message()
                );
            } else {
                $message = __('اتصال به منبع انجام نشد.', 'exchange-rate');
            }
            $error = new WP_Error('exchange_rate_http_status', $message);
            $error->add_data(array(
                'debug' => array(
                    'source_url' => esc_url_raw($source_url),
                    'proxy_url' => esc_url_raw($proxy_url),
                    'source_type' => $source_type,
                    'timeout' => (int) $timeout,
                    'candidate_urls' => $candidate_urls,
                    'last_wp_error' => $last_wp_error ? $last_wp_error->get_error_message() : '',
                    'attempts' => $attempt_logs,
                ),
            ));
            return $error;
        }

        if ('ice_api_latest' === $source_type || 'ice_api_history_currency' === $source_type || 'milli_gold_price_detail' === $source_type || 'cbi_tspd' === $source_type) {
            $result = $this->parse_ice_currency_json($body, $request_url, $source_type);
            if (!is_wp_error($result)) {
                return $this->attach_source_meta($result, $source);
            }
        }

        $content_type = is_array($response) ? (string) wp_remote_retrieve_header($response, 'content-type') : '';
        $looks_like_json = stripos($content_type, 'application/json') !== false || $this->is_json($body);

        if ($looks_like_json) {
            $json_result = $this->parse_ice_currency_json($body, $request_url, $source_type);
            if (!is_wp_error($json_result)) {
                return $this->attach_source_meta($json_result, $source);
            }
        }

        $html_result = $this->parse_ice_currency_page($body, $request_url);
        if (!is_wp_error($html_result)) {
            return $this->attach_source_meta($html_result, $source);
        }

        $html_result->add_data(array(
            'debug' => array(
                'source_url' => esc_url_raw($source_url),
                'proxy_url' => esc_url_raw($proxy_url),
                'source_type' => $source_type,
                'request_url' => esc_url_raw($request_url),
                'attempts' => $attempt_logs,
            ),
        ));
        return $html_result;
    }

    public function build_snapshot_from_body($source, $body, $request_url = '')
    {
        $source = is_array($source) ? $source : array();
        $source_url = $request_url !== '' ? $this->sanitize_url($request_url) : '';
        if ($source_url === '') {
            $source_url = isset($source['url']) ? $this->sanitize_url($source['url']) : '';
        }
        if ($source_url === '') {
            $source_url = self::DEFAULT_SOURCE_URL;
        }

        $source_type = isset($source['type']) ? sanitize_key($source['type']) : 'ice_api_latest';
        $body = (string) $body;
        if ($body === '') {
            return new WP_Error('exchange_rate_empty_body', __('بدنه پاسخ خالی است.', 'exchange-rate'));
        }

        if ('ice_api_latest' === $source_type || 'ice_api_history_currency' === $source_type || 'milli_gold_price_detail' === $source_type || 'cbi_tspd' === $source_type) {
            $result = $this->parse_ice_currency_json($body, $source_url, $source_type);
            if (!is_wp_error($result)) {
                return $this->attach_source_meta($result, $source);
            }
        }

        if ($this->is_json($body)) {
            $json_result = $this->parse_ice_currency_json($body, $source_url, $source_type);
            if (!is_wp_error($json_result)) {
                return $this->attach_source_meta($json_result, $source);
            }
        }

        $html_result = $this->parse_ice_currency_page($body, $source_url);
        if (!is_wp_error($html_result)) {
            return $this->attach_source_meta($html_result, $source);
        }

        return $html_result;
    }

    private function attach_source_meta($snapshot, $source)
    {
        $snapshot['source_key'] = isset($source['key']) ? sanitize_key($source['key']) : '';
        $snapshot['source_name'] = isset($source['name']) ? sanitize_text_field($source['name']) : '';
        $snapshot['source_type'] = isset($source['type']) ? sanitize_key($source['type']) : 'ice_api_latest';

        return $snapshot;
    }

    private function parse_ice_currency_json($body, $source_url, $source_type = 'ice_api_latest')
    {
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data)) {
            return new WP_Error('exchange_rate_invalid_json', __('ساختار JSON پاسخ منبع معتبر نیست.', 'exchange-rate'));
        }

        if ('milli_gold_price_detail' === $source_type || isset($data['data']['price18'])) {
            return $this->parse_milli_gold_detail_json($data, $source_url);
        }

        if (isset($data['results']) && is_array($data['results'])) {
            $first = reset($data['results']);
            if ('ice_api_history_currency' === $source_type || (is_array($first) && isset($first['currency_slug']))) {
                return $this->parse_ice_currency_history_json($data, $source_url);
            }
            if (is_array($first) && isset($first['slug'])) {
                $data = $data['results'];
            }
        } elseif ('ice_api_history_currency' === $source_type) {
            return $this->parse_ice_currency_history_json($data, $source_url);
        }

        $rows = array();
        $source_date = '';

        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }

            $currency_name = isset($item['name']) ? trim((string) $item['name']) : '';
            $currency_code = isset($item['slug']) ? strtoupper(trim((string) $item['slug'])) : '';

            $buy = $this->extract_integer(isset($item['buy_price']) ? $item['buy_price'] : '');
            $sell = $this->extract_integer(isset($item['sell_price']) ? $item['sell_price'] : '');

            if ($buy === null || $sell === null) {
                continue;
            }

            if ($source_date === '' && !empty($item['date'])) {
                $source_date = $this->normalize_digits((string) $item['date']);
            }

            $rows[] = array(
                'currency_code' => $currency_code,
                'currency_name' => $currency_name,
                'buy' => $buy,
                'sell' => $sell,
            );
        }

        if (empty($rows)) {
            return new WP_Error('exchange_rate_no_rows', __('هیچ ردیفی از داده ارزی در پاسخ منبع پیدا نشد.', 'exchange-rate'));
        }

        $source_date_key = $this->normalize_date_key($source_date);

        return array(
            'source_url' => esc_url_raw($source_url),
            'source_date' => $source_date,
            'source_date_key' => $source_date_key,
            'fetched_at' => time(),
            'rows' => $rows,
        );
    }

    private function parse_milli_gold_detail_json($data, $source_url)
    {
        if (empty($data['data']) || !is_array($data['data'])) {
            return new WP_Error('exchange_rate_invalid_json', __('پاسخ دریافتی از میلی معتبر نیست.', 'exchange-rate'));
        }

        $payload = $data['data'];
        $price = $this->extract_integer(isset($payload['price18']) ? $payload['price18'] : '');
        if ($price === null) {
            return new WP_Error('exchange_rate_no_rows', __('فیلد price18 در پاسخ میلی وجود ندارد.', 'exchange-rate'));
        }

        $row_datetime = isset($payload['date']) ? $this->normalize_digits((string) $payload['date']) : '';
        $source_date = '';
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $row_datetime, $match)) {
            $source_date = $match[0];
        }
        if ($source_date === '') {
            $source_date = wp_date('Y-m-d', time());
        }

        return array(
            'source_url' => esc_url_raw($source_url),
            'source_date' => $source_date,
            'source_date_key' => $this->normalize_date_key($source_date),
            'fetched_at' => time(),
            'rows' => array(
                array(
                    'row_datetime' => $row_datetime,
                    'price' => $price,
                ),
            ),
        );
    }

    private function parse_ice_currency_history_json($data, $source_url)
    {
        $items = isset($data['results']) && is_array($data['results']) ? $data['results'] : array();
        if (empty($items)) {
            return new WP_Error('exchange_rate_no_rows', __('هیچ ردیفی از تاریخچه در پاسخ منبع پیدا نشد.', 'exchange-rate'));
        }

        $rows = array();
        $source_date = '';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $buy = $this->extract_integer(isset($item['buy_price']) ? $item['buy_price'] : '');
            $sell = $this->extract_integer(isset($item['sell_price']) ? $item['sell_price'] : '');

            if ($buy === null || $sell === null) {
                continue;
            }

            $row_date = isset($item['date']) ? $this->normalize_digits((string) $item['date']) : '';
            if ($source_date === '' && $row_date !== '') {
                $source_date = $row_date;
            }

            $rows[] = array(
                'row_date' => $row_date,
                'currency_code' => isset($item['currency_slug']) ? strtoupper(trim((string) $item['currency_slug'])) : '',
                'currency_name' => isset($item['currency_name']) ? trim((string) $item['currency_name']) : '',
                'buy' => $buy,
                'sell' => $sell,
            );
        }

        if (empty($rows)) {
            return new WP_Error('exchange_rate_no_rows', __('هیچ ردیف معتبر تاریخچه در پاسخ منبع پیدا نشد.', 'exchange-rate'));
        }

        $source_date_key = $this->normalize_date_key($source_date);

        return array(
            'source_url' => esc_url_raw($source_url),
            'source_date' => $source_date,
            'source_date_key' => $source_date_key,
            'fetched_at' => time(),
            'rows' => $rows,
        );
    }

    private function parse_ice_currency_page($html, $source_url)
    {
        if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) {
            return new WP_Error('exchange_rate_dom_missing', __('افزونه DOM روی سرور فعال نیست و خواندن جدول HTML ممکن نیست.', 'exchange-rate'));
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        if (!$loaded) {
            return new WP_Error('exchange_rate_parse_error', __('تجزیه HTML منبع انجام نشد.', 'exchange-rate'));
        }

        $xpath = new DOMXPath($dom);
        $rows = $this->extract_rows_from_tables($xpath);
        if (empty($rows)) {
            if ($this->looks_like_challenge_page($html)) {
                $hint = '';
                if (strpos($source_url, 'fxmarketrate.cbi.ir') !== false) {
                    $hint = ' ' . __('(برای CBI آدرس منبع را روی https://fxmarketrate.cbi.ir/ بگذارید و در صورت نیاز هدر خام اضافه کنید.)', 'exchange-rate');
                }
                return new WP_Error(
                    'exchange_rate_bot_challenge',
                    __('منبع دسترسی خودکار را مسدود کرده است. از endpoint دیگر یا منبع جایگزین استفاده کنید.', 'exchange-rate') . $hint
                );
            }
            return new WP_Error('exchange_rate_no_rows', __('هیچ ردیف ارزی در جدول منبع پیدا نشد.', 'exchange-rate'));
        }

        $source_date = $this->extract_source_date($dom);
        $source_date_key = $this->normalize_date_key($source_date);

        return array(
            'source_url' => esc_url_raw($source_url),
            'source_date' => $source_date,
            'source_date_key' => $source_date_key,
            'fetched_at' => time(),
            'rows' => $rows,
        );
    }

    private function extract_rows_from_tables(DOMXPath $xpath)
    {
        $result = array();
        $table_rows = $xpath->query('//table//tr');
        if (!$table_rows || 0 === $table_rows->length) {
            return $result;
        }

        foreach ($table_rows as $tr) {
            $cells = $xpath->query('./th|./td', $tr);
            if (!$cells || $cells->length < 3) {
                continue;
            }

            $texts = array();
            foreach ($cells as $cell) {
                $text = trim(preg_replace('/\s+/u', ' ', (string) $cell->textContent));
                if ($text !== '') {
                    $texts[] = $text;
                }
            }

            if (count($texts) < 3) {
                continue;
            }

            $numbers = array();
            foreach ($texts as $text) {
                $numeric = $this->extract_integer($text);
                if (null !== $numeric) {
                    $numbers[] = $numeric;
                }
            }

            if (count($numbers) < 2) {
                continue;
            }

            $currency_code = '';
            $currency_name = '';

            foreach ($texts as $text) {
                if (preg_match('/\b([A-Z]{3})\b/u', strtoupper($text), $code_match)) {
                    $currency_code = $code_match[1];
                }
                $text_length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
                if (null === $this->extract_integer($text) && $text_length > 1) {
                    $currency_name = $text;
                }
            }

            if ($currency_name === '') {
                $currency_name = $texts[count($texts) - 1];
            }

            $result[] = array(
                'currency_code' => $currency_code,
                'currency_name' => $currency_name,
                'buy' => $numbers[0],
                'sell' => $numbers[1],
            );
        }

        return $result;
    }

    private function extract_source_date(DOMDocument $dom)
    {
        $text = (string) $dom->textContent;
        $text = $this->normalize_digits($text);

        if (preg_match('/\b(14\d{2}\/\d{2}\/\d{2})\b/u', $text, $match)) {
            return $match[1];
        }

        if (preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/u', $text, $match)) {
            return $match[1];
        }

        return '';
    }

    private function extract_integer($text)
    {
        $normalized = $this->normalize_digits((string) $text);
        $normalized = preg_replace('/[^\d]/', '', $normalized);
        if ($normalized === '') {
            return null;
        }

        return (int) $normalized;
    }

    private function normalize_digits($text)
    {
        $map = array(
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        );

        return strtr((string) $text, $map);
    }

    private function looks_like_challenge_page($html)
    {
        $patterns = array(
            'Transferring to the website',
            '__arcsjs',
            'error-section--waiting',
            'location.reload()',
            'Request Rejected',
            'support ID',
        );

        foreach ($patterns as $pattern) {
            if (stripos($html, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    private function browser_user_agent()
    {
        return 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';
    }

    private function is_json($text)
    {
        $text = ltrim((string) $text);
        if ($text === '') {
            return false;
        }

        if ($text[0] !== '{' && $text[0] !== '[') {
            return false;
        }

        json_decode($text, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function sanitize_url($url)
    {
        $url = esc_url_raw(trim((string) $url));
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, array('https', 'http'), true)) {
            return '';
        }

        $allow_insecure_http = defined('EXCHANGE_RATE_ALLOW_INSECURE_HTTP') && EXCHANGE_RATE_ALLOW_INSECURE_HTTP;
        if ($scheme === 'http' && !$allow_insecure_http) {
            return '';
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return '';
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], array(80, 443), true)) {
            return '';
        }

        if (!$this->is_safe_public_host((string) $parts['host'])) {
            return '';
        }

        return $url;
    }

    private function is_safe_public_host($host)
    {
        $host = strtolower(trim((string) $host));
        if ($host === '') {
            return false;
        }

        $blocked_hosts = array(
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
        );
        if (in_array($host, $blocked_hosts, true)) {
            return false;
        }

        foreach (array('.local', '.internal', '.lan', '.home', '.test') as $suffix) {
            if (substr($host, -strlen($suffix)) === $suffix) {
                return false;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->is_public_ip($host);
        }

        $records = @gethostbynamel($host);
        if (is_array($records) && !empty($records)) {
            $public_found = false;
            foreach ($records as $ip) {
                if ($this->is_public_ip($ip)) {
                    $public_found = true;
                    break;
                }
            }
            if (!$public_found) {
                return false;
            }
        }

        return true;
    }

    private function is_public_ip($ip)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($ip === '::1' || stripos($ip, 'fc') === 0 || stripos($ip, 'fd') === 0 || stripos($ip, 'fe80:') === 0) {
                return false;
            }
            return true;
        }

        return false;
    }

    private function normalize_date_key($source_date)
    {
        $source_date = trim((string) $source_date);
        if ($source_date === '') {
            return wp_date('Y-m-d', time());
        }

        $normalized = str_replace('/', '-', $this->normalize_digits($source_date));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $normalized)) {
            return $normalized;
        }

        return wp_date('Y-m-d', time());
    }

    private function parse_custom_headers($raw)
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return array();
        }

        $headers = array();
        $lines = preg_split('/\r\n|\r|\n/', $raw);
        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = array_map('trim', explode(':', $line, 2));
            if ($name === '' || $value === '') {
                continue;
            }

            $headers[$name] = $value;
        }

        return $headers;
    }

    private function resolve_proxy_url_template($template, $source_url, $source_key, $source_type)
    {
        $template = trim((string) $template);
        if ($template === '') {
            return '';
        }

        $resolved = str_replace(
            array('{url}', '{key}', '{type}'),
            array(rawurlencode((string) $source_url), rawurlencode((string) $source_key), rawurlencode((string) $source_type)),
            $template
        );

        return $this->sanitize_url($resolved);
    }

    private function is_ice_source($url, $source_type)
    {
        if ('ice_api_latest' === $source_type || 'ice_api_history_currency' === $source_type) {
            return true;
        }

        $host = (string) wp_parse_url((string) $url, PHP_URL_HOST);
        return in_array($host, array('api.ice.ir', 'ice.ir'), true);
    }

    private function build_candidate_urls($source_url, $source_type)
    {
        $source_url = (string) $source_url;
        $candidates = array($source_url);

        if (!$this->is_ice_source($source_url, $source_type)) {
            return $candidates;
        }

        $parts = wp_parse_url($source_url);
        if (!is_array($parts) || empty($parts['host'])) {
            return $candidates;
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $path = isset($parts['path']) ? $parts['path'] : '';
        $query = isset($parts['query']) ? $parts['query'] : '';
        $suffix = $query !== '' ? '?' . $query : '';
        $path_no_slash = rtrim($path, '/');

        foreach (array('ice.ir', 'api.ice.ir') as $host) {
            $candidates[] = $scheme . '://' . $host . $path . $suffix;
            $candidates[] = $scheme . '://' . $host . $path_no_slash . $suffix;
        }

        return array_slice(array_values(array_unique(array_filter($candidates))), 0, 4);
    }
}
