<?php

if (!defined('ABSPATH')) {
    exit;
}

class Nerkh_Chand_Widget_Cards extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'nerkhchand_cards';
    }

    public function get_title()
    {
        return 'نرخ چند؟ - کارت ها';
    }

    public function get_icon()
    {
        return 'eicon-posts-grid';
    }

    public function get_categories()
    {
        return array('nerkhchand');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content_section', array('label' => 'تنظیمات'));

        $this->add_control('source', array('label' => 'کلید منبع', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'ice_havaleh'));
        $this->add_control('title', array('label' => 'عنوان', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'نرخ چند؟'));
        $this->add_control('symbols', array('label' => 'فیلتر کد ارز', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => ''));
        $this->add_control('date', array('label' => 'تاریخ', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'latest'));
        $this->add_control('limit', array('label' => 'تعداد کارت', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 6, 'min' => 0));

        $this->end_controls_section();
    }

    protected function render()
    {
        $s = $this->get_settings_for_display();
        $shortcode = sprintf(
            '[exchange_rate source="%s" title="%s" symbols="%s" date="%s" limit="%d" view="cards"]',
            esc_attr($s['source']),
            esc_attr($s['title']),
            esc_attr($s['symbols']),
            esc_attr($s['date']),
            (int) $s['limit']
        );

        echo do_shortcode($shortcode);
    }
}
