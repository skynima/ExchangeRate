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
        return 'Nerkh Chand - Section Output';
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
        $this->start_controls_section('content_section', array('label' => 'Settings'));

        $this->add_control('source', array(
            'label' => 'Source Key',
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'ice_havaleh',
        ));

        $this->add_control('section', array(
            'label' => 'Output Type',
            'type' => \Elementor\Controls_Manager::SELECT,
            'default' => 'full',
            'options' => array(
                'full' => 'Full',
                'title' => 'Title only',
                'description' => 'Description only',
                'source_meta' => 'Source name + source date',
                'fetch_date' => 'Fetch date only',
                'table_only' => 'Table only',
            ),
        ));

        $this->add_control('title', array(
            'label' => 'Title (optional)',
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ));

        $this->add_control('subtitle', array(
            'label' => 'Subtitle (optional)',
            'type' => \Elementor\Controls_Manager::TEXTAREA,
            'default' => '',
        ));

        $this->add_control('symbols', array(
            'label' => 'Symbol filter',
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => '',
        ));

        $this->add_control('date', array(
            'label' => 'Date',
            'type' => \Elementor\Controls_Manager::TEXT,
            'default' => 'latest',
        ));

        $this->add_control('limit', array(
            'label' => 'Row limit',
            'type' => \Elementor\Controls_Manager::NUMBER,
            'default' => 0,
            'min' => 0,
        ));

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
