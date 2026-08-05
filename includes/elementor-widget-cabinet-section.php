<?php
if (!defined('ABSPATH')) { exit; }

    class ZAU_Union_Cabinet_Section_Widget extends \Elementor\Widget_Base {
        public function get_name() { return 'zau-union-cabinet-section'; }
        public function get_title() { return 'ZAU — раздел кабинета'; }
        public function get_icon() { return 'eicon-person'; }
        public function get_categories() { return ['zau-union']; }
        public function get_keywords() { return ['профсоюз','личный кабинет','документы','заявления','участники']; }

        protected function register_controls() {
            $this->start_controls_section('content_section', ['label'=>'Содержимое']);
            $this->add_control('section_type', [
                'label'=>'Что вывести',
                'type'=>\Elementor\Controls_Manager::SELECT,
                'default'=>'profile',
                'options'=>[
                    'full'=>'Личный кабинет целиком',
                    'profile'=>'Шапка профиля и статус',
                    'navigation'=>'Навигация по разделам',
                    'stats'=>'Карточки статистики',
                    'documents'=>'Мои документы',
                    'submissions'=>'Мои заявления',
                    'card'=>'Личная карточка',
                    'benefits'=>'Акции и скидки',
                    'members'=>'Участники организации',
                    'registry'=>'Реестр организации с экспортом',
                    'info'=>'Материалы для ознакомления',
                    'logins'=>'История входов',
                    'logout'=>'Кнопка выхода',
                ],
            ]);
            $this->add_control('custom_title', [
                'label'=>'Свой заголовок',
                'type'=>\Elementor\Controls_Manager::TEXT,
                'placeholder'=>'Оставьте пустым для стандартного',
                'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('custom_subtitle', [
                'label'=>'Своё описание',
                'type'=>\Elementor\Controls_Manager::TEXTAREA,
                'rows'=>3,
                'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('show_heading', [
                'label'=>'Показывать заголовок',
                'type'=>\Elementor\Controls_Manager::SWITCHER,
                'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes','default'=>'yes',
                'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('nav_items', [
                'label'=>'Пункты навигации',
                'type'=>\Elementor\Controls_Manager::TEXT,
                'default'=>'documents,submissions,card,benefits,members,info,logins',
                'description'=>'Допустимо: documents, submissions, card, benefits, members, info, logins. Можно менять порядок и удалять пункты.',
                'condition'=>['section_type'=>'navigation'],
            ]);
            $this->end_controls_section();

            $this->start_controls_section('style_box', ['label'=>'Блок','tab'=>\Elementor\Controls_Manager::TAB_STYLE]);
            $this->add_control('box_background', [
                'label'=>'Фон', 'type'=>\Elementor\Controls_Manager::COLOR,
                'selectors'=>['{{WRAPPER}} .zau-cabinet-builder-block'=>'background-color: {{VALUE}};'],
            ]);
            $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
                'name'=>'box_border','selector'=>'{{WRAPPER}} .zau-cabinet-builder-block',
            ]);
            $this->add_responsive_control('box_radius', [
                'label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],
                'selectors'=>['{{WRAPPER}} .zau-cabinet-builder-block'=>'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
            ]);
            $this->add_responsive_control('box_padding', [
                'label'=>'Внутренние отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em','%'],
                'selectors'=>['{{WRAPPER}} .zau-cabinet-builder-block'=>'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
            ]);
            $this->end_controls_section();

            $this->start_controls_section('style_heading', ['label'=>'Заголовок','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
            $this->add_control('heading_color', ['label'=>'Цвет','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-section-head h3'=>'color: {{VALUE}};']]);
            $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'heading_typography','selector'=>'{{WRAPPER}} .zau-section-head h3']);
            $this->add_control('subtitle_color', ['label'=>'Цвет описания','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-section-head p'=>'color: {{VALUE}};']]);
            $this->end_controls_section();
        }

        protected function render() {
            $s = $this->get_settings_for_display();
            if (($s['section_type'] ?? '') === 'full') {
                echo do_shortcode('[zau_union_cabinet]');
                return;
            }
            if (($s['section_type'] ?? '') === 'registry') {
                echo do_shortcode('[zau_union_org_registry]');
                return;
            }
            $attrs = [
                'section' => sanitize_key($s['section_type'] ?? 'profile'),
                'title' => sanitize_text_field($s['custom_title'] ?? ''),
                'subtitle' => sanitize_text_field($s['custom_subtitle'] ?? ''),
                'show_heading' => (($s['show_heading'] ?? 'yes') === 'yes') ? 'yes' : 'no',
                'nav_items' => sanitize_text_field($s['nav_items'] ?? ''),
            ];
            $shortcode = '[zau_union_cabinet_section';
            foreach ($attrs as $key=>$value) { $shortcode .= ' '.$key.'="'.esc_attr($value).'"'; }
            $shortcode .= ']';
            echo do_shortcode($shortcode);
        }
    }
