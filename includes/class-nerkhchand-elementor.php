<?php

if (!defined('ABSPATH')) {
    exit;
}

class Nerkh_Chand_Elementor
{
    public static function init()
    {
        add_action('elementor/loaded', array(__CLASS__, 'register_hooks'));
    }

    public static function register_hooks()
    {
        add_action('elementor/elements/categories_registered', array(__CLASS__, 'register_category'));
        add_action('elementor/widgets/register', array(__CLASS__, 'register_widgets'));
    }

    public static function register_category($elements_manager)
    {
        $elements_manager->add_category(
            'nerkhchand',
            array(
                'title' => 'نرخ چند؟',
                'icon' => 'fa fa-plug',
            )
        );
    }

    public static function register_widgets($widgets_manager)
    {
        $widget_files = array(
            EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-table.php' => 'Nerkh_Chand_Widget_Table',
            EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-cards.php' => 'Nerkh_Chand_Widget_Cards',
            EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-ticker.php' => 'Nerkh_Chand_Widget_Ticker',
            EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-section.php' => 'Nerkh_Chand_Widget_Section',
        );

        foreach ($widget_files as $file => $class_name) {
            if (is_readable($file)) {
                require_once $file;
            }

            if (class_exists($class_name)) {
                $widgets_manager->register(new $class_name());
            }
        }
    }
}
