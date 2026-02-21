<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;
$table_name = $wpdb->prefix . 'exchange_rate_daily_snapshots';
$wpdb->query("DROP TABLE IF EXISTS {$table_name}");

delete_option('exchange_rate_source_url');
delete_option('exchange_rate_last_error');
delete_option('exchange_rate_sources');
delete_option('exchange_rate_last_errors');
delete_option('exchange_rate_db_version');
