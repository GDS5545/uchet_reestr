<?php
if (!defined('ABSPATH')) { exit; }

final class ZAU_Union_Elementor {
    public static function init() {
        add_action('elementor/elements/categories_registered', [__CLASS__, 'register_category']);
        add_action('elementor/widgets/register', [__CLASS__, 'register_widgets']);
    }

    public static function register_category($elements_manager) {
        $elements_manager->add_category('zau-union', [
            'title' => 'ZAU Профсоюз',
            'icon' => 'eicon-users-circle-o',
        ]);
    }

    public static function register_widgets($widgets_manager) {
        if (!class_exists('Elementor\\Widget_Base')) { return; }
        require_once __DIR__ . '/elementor-widgets.php';
        $widgets_manager->register(new ZAU_Union_Elementor_Auth_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_Form_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_Portal_Widget());
        $widgets_manager->register(new ZAU_Union_Cabinet_Section_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_Org_Members_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_Org_Registry_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_Verify_Widget());
        $widgets_manager->register(new ZAU_Union_Elementor_My_Documents_Widget());
    }
}
