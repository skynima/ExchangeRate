<?php
/**
 * Plugin Name: نرخ چند؟
 * Plugin URI:  https://atomsoft.ir/
 * Description: نمایش نرخ ارز و طلا با پنل حرفه‌ای فارسی، منبع‌های چندگانه، و ویجت‌های المنتور.
 * Version:     1.3.3
 * Author:      علی فیروزی | اتم سافت - OpenAI
 * Author URI:  https://atomsoft.ir/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: exchange-rate
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('EXCHANGE_RATE_VERSION', '1.3.3');
define('EXCHANGE_RATE_PLUGIN_FILE', __FILE__);
define('EXCHANGE_RATE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EXCHANGE_RATE_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/class-exchange-rate-api.php';
require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/class-exchange-rate.php';
if (!defined('EXCHANGE_RATE_DISABLE_ELEMENTOR') || !EXCHANGE_RATE_DISABLE_ELEMENTOR) {
    require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/class-nerkhchand-elementor.php';
}

register_activation_hook(EXCHANGE_RATE_PLUGIN_FILE, array('Exchange_Rate', 'activate'));
register_deactivation_hook(EXCHANGE_RATE_PLUGIN_FILE, array('Exchange_Rate', 'deactivate'));

function exchange_rate_run()
{
    $plugin = new Exchange_Rate();
    $plugin->run();
    if (class_exists('Nerkh_Chand_Elementor')) {
        Nerkh_Chand_Elementor::init();
    }
}

exchange_rate_run();
