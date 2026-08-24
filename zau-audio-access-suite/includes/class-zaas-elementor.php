<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Elementor bootstrap — fully optional and additive, exactly like the
 * reference union plugin's class-zau-elementor.php: no-ops if Elementor
 * isn't installed, registers one custom category, and registers thin
 * widget shells that wrap the existing shortcodes (see elementor-widgets.php)
 * so there is never a second copy of any business logic.
 */
final class ZAAS_Elementor {
    public static function init() {
        add_action('elementor/elements/categories_registered', [__CLASS__, 'register_category']);
        add_action('elementor/widgets/register', [__CLASS__, 'register_widgets']);
    }

    public static function register_category($elements_manager) {
        $elements_manager->add_category('zaas-audio', ['title' => 'ZAU Аудиодоступ', 'icon' => 'eicon-headphones']);
    }

    public static function register_widgets($widgets_manager) {
        if (!class_exists('Elementor\\Widget_Base')) { return; }
        require_once ZAAS_PLUGIN_DIR . 'includes/elementor-widgets.php';
        $widgets_manager->register(new ZAAS_Elementor_Library_Widget());
        $widgets_manager->register(new ZAAS_Elementor_Auth_Widget());
        $widgets_manager->register(new ZAAS_Elementor_Player_Widget());
        $widgets_manager->register(new ZAAS_Elementor_Protected_Widget());
        $widgets_manager->register(new ZAAS_Elementor_Buy_Button_Widget());
    }
}
