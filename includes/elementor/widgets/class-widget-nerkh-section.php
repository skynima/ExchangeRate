<?php

if (!defined('ABSPATH')) {
    exit;
}

class Nerkh_Chand_Widget_Section extends \Elementor\Widget_Base
{
    public function get_name()
    {
        return 'nerkhchand_section';
    }

    public function get_title()
    {
        return 'نرخ چند؟ - بخش خروجی';
    }

    public function get_icon()
    {
        return 'eicon-editor-list-ul';
    }

    public function get_categories()
    {
        return array('nerkhchand');
    }

    protected function register_controls()
    {
        $this->start_controls_section('content_section', array('label' => 'تنظیمات'));

        $this->add_control('source', array('label' => 'کلید منبع', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'ice_havaleh'));
        $this->add_control('section', array(
            'label' => 'نوع خروجی',
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'full',
            'options' => array(
                'full' => 'کامل',
                'title' => 'فقط عنوان',
                'description' => 'فقط توضیحات',
                'source_meta' => 'نام منبع + تاریخ منبع',
                'fetch_date' => 'فقط تاریخ واکشی',
                'table_only' => 'فقط جدول',
            ),
        ));
        $this->add_control('title', array('label' => 'عنوان (اختیاری)', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => ''));
        $this->add_control('subtitle', array('label' => 'توضیح (اختیاری)', 'type' => \Elementor\Controls_Manager::TEXTAREA, 'default' => ''));
        $this->add_control('symbols', array('label' => 'فیلتر کد ارز', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => ''));
        $this->add_control('date', array('label' => 'تاریخ', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'latest'));
        $this->add_control('limit', array('label' => 'تعداد ردیف', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 0, 'min' => 0));

        $this->end_controls_section();
    }

    protected function render()
    {
        $s = $this->get_settings_for_display();
        $shortcode = sprintf(
            '[exchange_rate source="%s" section="%s" title="%s" subtitle="%s" symbols="%s" date="%s" limit="%d"]',
            esc_attr($s['source']),
            esc_attr($s['section']),
            esc_attr($s['title']),
            esc_attr($s['subtitle']),
            esc_attr($s['symbols']),
            esc_attr($s['date']),
            (int) $s['limit']
        );

        echo do_shortcode($shortcode);
    }
}
