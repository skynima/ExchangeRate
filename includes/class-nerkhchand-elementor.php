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
        require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-table.php';
        require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-cards.php';
        require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-ticker.php';
        require_once EXCHANGE_RATE_PLUGIN_DIR . 'includes/elementor/widgets/class-widget-nerkh-section.php';

        $widgets_manager->register(new \Nerkh_Chand_Widget_Table());
        $widgets_manager->register(new \Nerkh_Chand_Widget_Cards());
        $widgets_manager->register(new \Nerkh_Chand_Widget_Ticker());
        $widgets_manager->register(new \Nerkh_Chand_Widget_Section());
    }
}
