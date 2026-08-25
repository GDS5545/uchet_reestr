<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Elementor widget shells. Every widget renders an existing shortcode
 * inside a scoped wrapper (render_shortcode_in_wrapper) and exposes a
 * Style-tab palette that overrides the same --zaas-e-* CSS custom
 * properties assets/css/theme.css defines globally — same layering
 * pattern as the reference union plugin: global tokens set the baseline,
 * a per-widget Elementor override only changes that one placement.
 */
abstract class ZAAS_Elementor_Widget_Base extends \Elementor\Widget_Base {
    public function get_categories() { return ['zaas-audio']; }
    public function get_keywords() { return ['ZAU', 'аудиокнига', 'доступ', 'PIN', 'Kaspi']; }

    protected function render_shortcode_in_wrapper($shortcode, $extra_classes = '') {
        $classes = trim('zaas-interface zaas-elementor-' . sanitize_html_class($this->get_name()) . ' ' . $extra_classes);
        echo '<div class="' . esc_attr($classes) . '">';
        echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    /**
     * Shared "Способы входа" controls for any widget that can fall back
     * to the login gate (Auth, Player, Protected): lets this one widget
     * instance narrow which methods it offers. It can only *intersect*
     * with what's enabled in ZAU Аудиодоступ → PIN и вход — a widget can
     * never re-enable a method switched off site-wide.
     */
    protected function register_auth_method_controls() {
        $this->start_controls_section('zaas_auth_methods', ['label' => 'Способы входа']);
        $this->add_control('enable_recovery', ['label' => 'Ссылка на email', 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes']);
        $this->add_control('enable_pin', ['label' => 'Постоянный PIN', 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes']);
        $this->add_control('enable_password', ['label' => 'Логин и пароль', 'type' => \Elementor\Controls_Manager::SWITCHER, 'return_value' => 'yes', 'default' => 'yes']);
        $this->end_controls_section();
    }

    protected function auth_shortcode_attributes($settings) {
        $methods = [];
        if (($settings['enable_recovery'] ?? 'yes') === 'yes') { $methods[] = 'recovery'; }
        if (($settings['enable_pin'] ?? 'yes') === 'yes') { $methods[] = 'pin'; }
        if (($settings['enable_password'] ?? 'yes') === 'yes') { $methods[] = 'password'; }
        return ' methods="' . esc_attr($methods ? implode(',', $methods) : 'none') . '"';
    }

    /**
     * Shared "Стиль" controls: container box model + a small design-token
     * palette. Kept intentionally smaller than a full component library —
     * enough for real theming without hundreds of one-off toggles.
     */
    protected function register_style_controls() {
        $scope = '{{WRAPPER}} .zaas-interface';

        $this->start_controls_section('zaas_style_layout', ['label' => 'Контейнер', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_responsive_control('zaas_max_width', [
            'label' => 'Максимальная ширина', 'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px', '%'], 'range' => ['px' => ['min' => 240, 'max' => 1400], '%' => ['min' => 20, 'max' => 100]],
            'selectors' => [$scope => 'max-width:{{SIZE}}{{UNIT}}; width:100%;'],
        ]);
        $this->add_responsive_control('zaas_padding', [
            'label' => 'Внутренние отступы', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px', 'em', '%'],
            'selectors' => [$scope => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_control('zaas_radius', [
            'label' => 'Радиус скругления', 'type' => \Elementor\Controls_Manager::SLIDER,
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'selectors' => [$scope => '--zaas-e-radius:{{SIZE}}px;'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name' => 'zaas_shadow', 'selector' => $scope]);
        $this->end_controls_section();

        $this->start_controls_section('zaas_style_colors', ['label' => 'Цвета', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $palette = [
            'zaas_accent'      => ['Акцент', '--zaas-e-accent'],
            'zaas_accent_dark' => ['Акцент (наведение)', '--zaas-e-accent-dark'],
            'zaas_surface'     => ['Фон карточек', '--zaas-e-surface'],
            'zaas_text'        => ['Текст', '--zaas-e-text'],
            'zaas_muted'       => ['Второстепенный текст', '--zaas-e-muted'],
            'zaas_border'      => ['Границы', '--zaas-e-border'],
        ];
        foreach ($palette as $id => $data) {
            $this->add_control($id, ['label' => $data[0], 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => [$scope => $data[1] . ':{{VALUE}};']]);
        }
        $this->end_controls_section();

        $this->start_controls_section('zaas_style_typography', ['label' => 'Типографика', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name' => 'zaas_body_typography', 'selector' => $scope]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name' => 'zaas_heading_typography', 'label' => 'Заголовки', 'selector' => $scope . ' h1, ' . $scope . ' h2, ' . $scope . ' .zaas-player-title, ' . $scope . ' .zaas-library-head h2']);
        $this->end_controls_section();

        $this->start_controls_section('zaas_style_buttons', ['label' => 'Кнопки', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $primary = $scope . ' .zaas-btn-primary';
        $this->add_control('zaas_btn_text', ['label' => 'Текст', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => [$primary => 'color:{{VALUE}}!important;']]);
        $this->add_control('zaas_btn_bg', ['label' => 'Фон', 'type' => \Elementor\Controls_Manager::COLOR, 'selectors' => [$primary => 'background-color:{{VALUE}};']]);
        $this->add_control('zaas_btn_radius', ['label' => 'Радиус кнопки', 'type' => \Elementor\Controls_Manager::SLIDER, 'range' => ['px' => ['min' => 0, 'max' => 40]], 'selectors' => [$primary => 'border-radius:{{SIZE}}px;']]);
        $this->end_controls_section();
    }
}

/* -------------------------------------------------------------------- */

class ZAAS_Elementor_Library_Widget extends ZAAS_Elementor_Widget_Base {
    public function get_name() { return 'zaas-library'; }
    public function get_title() { return 'ZAU: Библиотека / Кабинет'; }
    public function get_icon() { return 'eicon-library-open'; }

    protected function register_controls() {
        $this->start_controls_section('zaas_content', ['label' => 'Контент']);
        $this->end_controls_section();
        $this->register_style_controls();
    }

    protected function render() {
        $this->render_shortcode_in_wrapper('[zaas_library]');
    }
}

class ZAAS_Elementor_Auth_Widget extends ZAAS_Elementor_Widget_Base {
    public function get_name() { return 'zaas-auth'; }
    public function get_title() { return 'ZAU: Вход (ссылка / PIN / пароль)'; }
    public function get_icon() { return 'eicon-lock-user'; }

    protected function register_controls() {
        $this->start_controls_section('zaas_content', ['label' => 'Контент']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_style_controls();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zaas_auth' . $this->auth_shortcode_attributes($settings) . ']');
    }
}

class ZAAS_Elementor_Player_Widget extends ZAAS_Elementor_Widget_Base {
    public function get_name() { return 'zaas-player'; }
    public function get_title() { return 'ZAU: Аудиоплеер (плеер + замок)'; }
    public function get_icon() { return 'eicon-play-o'; }

    protected function register_controls() {
        $this->start_controls_section('zaas_content', ['label' => 'Контент']);
        $this->add_control('audio_id', ['label' => 'ID аудиофайла', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 0]);
        $this->add_control('lock_note', [
            'type' => \Elementor\Controls_Manager::RAW_HTML, 'raw' => 'Этот виджет — сразу и плеер, и замок: незалогиненный посетитель увидит форму входа (по выбранным ниже способам) прямо на месте плеера, а не сразу кнопку «Купить».', 'content_classes' => 'elementor-descriptor',
        ]);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_style_controls();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zaas_audio id="' . absint($settings['audio_id']) . '"' . $this->auth_shortcode_attributes($settings) . ']');
    }
}

class ZAAS_Elementor_Protected_Widget extends ZAAS_Elementor_Widget_Base {
    public function get_name() { return 'zaas-protected'; }
    public function get_title() { return 'ZAU: Защищённый блок'; }
    public function get_icon() { return 'eicon-lock'; }

    protected function register_controls() {
        $this->start_controls_section('zaas_content', ['label' => 'Контент']);
        $this->add_control('product_id', ['label' => 'ID товара WooCommerce', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 0]);
        $this->add_control('inner_html', [
            'label' => 'Содержимое (виден только владельцам товара)', 'type' => \Elementor\Controls_Manager::WYSIWYG,
            'default' => '<p>Этот текст видят только покупатели.</p>',
        ]);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_style_controls();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $shortcode = '[zaas_protected product_id="' . absint($settings['product_id']) . '"' . $this->auth_shortcode_attributes($settings) . ']' . $settings['inner_html'] . '[/zaas_protected]';
        $this->render_shortcode_in_wrapper($shortcode);
    }
}

class ZAAS_Elementor_Buy_Button_Widget extends ZAAS_Elementor_Widget_Base {
    public function get_name() { return 'zaas-buy-button'; }
    public function get_title() { return 'ZAU: Кнопка покупки'; }
    public function get_icon() { return 'eicon-cart'; }

    protected function register_controls() {
        $this->start_controls_section('zaas_content', ['label' => 'Контент']);
        $this->add_control('product_id', ['label' => 'ID товара WooCommerce', 'type' => \Elementor\Controls_Manager::NUMBER, 'default' => 0]);
        $this->add_control('text', ['label' => 'Текст кнопки', 'type' => \Elementor\Controls_Manager::TEXT, 'default' => 'Купить доступ']);
        $this->end_controls_section();
        $this->register_style_controls();
    }

    protected function render() {
        $settings = $this->get_settings_for_display();
        $shortcode = '[zaas_buy_button product_id="' . absint($settings['product_id']) . '" text="' . esc_attr($settings['text']) . '"]';
        $this->render_shortcode_in_wrapper($shortcode);
    }
}
