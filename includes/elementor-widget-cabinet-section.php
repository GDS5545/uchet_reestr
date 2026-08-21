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
                    'home'=>'Главная (быстрые действия)',
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
                'condition'=>['section_type'=>['home','documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('custom_subtitle', [
                'label'=>'Своё описание',
                'type'=>\Elementor\Controls_Manager::TEXTAREA,
                'rows'=>3,
                'condition'=>['section_type'=>['home','documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('show_heading', [
                'label'=>'Показывать заголовок',
                'type'=>\Elementor\Controls_Manager::SWITCHER,
                'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes','default'=>'yes',
                'condition'=>['section_type'=>['home','documents','submissions','card','benefits','members','info','logins']],
            ]);
            $this->add_control('nav_items', [
                'label'=>'Пункты навигации',
                'type'=>\Elementor\Controls_Manager::SELECT2,
                'multiple'=>true,
                'default'=>['home','documents','submissions','card','benefits','members','info','logins'],
                'options'=>[
                    'home'=>'Главная','documents'=>'Документы','submissions'=>'Заявления','card'=>'Личная карточка',
                    'benefits'=>'Акции и скидки','members'=>'Участники','info'=>'Материалы','logins'=>'Входы',
                ],
                'label_block'=>true,
                'description'=>'Порядок пунктов — как выбираете. Пункт также скрывается автоматически, если запрещён для роли пользователя на странице «Оформление и данные → Вход, PIN и дизайн → Видимость вкладок кабинета по ролям».',
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

            $this->start_controls_section('style_nav', ['label'=>'Навигация','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>'navigation']]);
            $this->add_responsive_control('nav_gap', ['label'=>'Промежуток между пунктами, px','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>40]],'selectors'=>['{{WRAPPER}} .zau-cabinet-nav'=>'gap: {{SIZE}}{{UNIT}};']]);
            $this->add_control('nav_color', ['label'=>'Цвет текста','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-cabinet-nav a'=>'color: {{VALUE}};']]);
            $this->add_control('nav_active_color', ['label'=>'Цвет активного пункта','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-cabinet-nav a.is-active'=>'color: {{VALUE}} !important;']]);
            $this->add_control('nav_active_background', ['label'=>'Фон активного пункта','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-cabinet-nav a.is-active'=>'background-color: {{VALUE}};']]);
            $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'nav_typography','selector'=>'{{WRAPPER}} .zau-cabinet-nav a']);
            $this->end_controls_section();

            $this->start_controls_section('style_nav_mobile', ['label'=>'Навигация на телефоне','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>'navigation']]);
            $this->add_control('nav_mobile_fixed', [
                'label'=>'Закрепить внизу экрана на телефоне','type'=>\Elementor\Controls_Manager::SWITCHER,
                'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes',
                'prefix_class'=>'zau-nav-mobile-fixed-',
                'description'=>'На телефоне панель станет нижним меню, как в мобильных приложениях, с иконкой и коротким названием у каждого пункта.',
            ]);
            $this->add_control('nav_mobile_icon_size', ['label'=>'Размер иконки на телефоне, px','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>12,'max'=>32]],'selectors'=>['{{WRAPPER}} .zau-cabinet-nav-icon'=>'font-size: {{SIZE}}{{UNIT}};'],'condition'=>['nav_mobile_fixed'=>'yes']]);
            $this->add_control('nav_mobile_background', ['label'=>'Фон нижнего меню','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-cabinet-nav'=>'background-color: {{VALUE}};'],'condition'=>['nav_mobile_fixed'=>'yes']]);
            $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'nav_mobile_shadow','selector'=>'{{WRAPPER}} .zau-cabinet-nav','condition'=>['nav_mobile_fixed'=>'yes']]);
            $this->end_controls_section();

            $this->start_controls_section('style_benefits', ['label'=>'Оформление акций и скидок','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>'benefits']]);
            $this->add_responsive_control('benefit_columns', [
                'label'=>'Колонок в сетке','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'3',
                'options'=>['1'=>'1','2'=>'2','3'=>'3','4'=>'4'],
                'selectors'=>['{{WRAPPER}} .zau-benefit-grid'=>'grid-template-columns: repeat({{VALUE}}, minmax(0, 1fr));'],
            ]);
            $this->add_responsive_control('benefit_gap', ['label'=>'Промежуток между карточками, px','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>60]],'selectors'=>['{{WRAPPER}} .zau-benefit-grid'=>'gap: {{SIZE}}{{UNIT}};']]);
            $this->add_control('benefit_card_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Карточка']);
            $this->add_control('benefit_card_background', ['label'=>'Фон карточки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-card'=>'background-color: {{VALUE}};']]);
            $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'benefit_card_border','selector'=>'{{WRAPPER}} .zau-benefit-card']);
            $this->add_responsive_control('benefit_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>['{{WRAPPER}} .zau-benefit-card'=>'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
            $this->add_responsive_control('benefit_card_padding', ['label'=>'Внутренние отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em','%'],'selectors'=>['{{WRAPPER}} .zau-benefit-content'=>'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
            $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'benefit_card_shadow','selector'=>'{{WRAPPER}} .zau-benefit-card']);
            $this->add_control('benefit_badge_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Бейдж скидки','separator'=>'before']);
            $this->add_control('benefit_badge_background', ['label'=>'Фон бейджа','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-badge'=>'background-color: {{VALUE}};']]);
            $this->add_control('benefit_badge_color', ['label'=>'Цвет текста бейджа','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-badge'=>'color: {{VALUE}};']]);
            $this->add_control('benefit_title_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Название и текст','separator'=>'before']);
            $this->add_control('benefit_title_color', ['label'=>'Цвет названия','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-card h4'=>'color: {{VALUE}};']]);
            $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'benefit_title_typography','selector'=>'{{WRAPPER}} .zau-benefit-card h4']);
            $this->add_control('benefit_partner_color', ['label'=>'Цвет партнёра','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-partner'=>'color: {{VALUE}};']]);
            $this->add_control('benefit_description_color', ['label'=>'Цвет описания','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-description'=>'color: {{VALUE}};']]);
            $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'benefit_description_typography','selector'=>'{{WRAPPER}} .zau-benefit-description']);
            $this->add_control('benefit_button_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Кнопка','separator'=>'before']);
            $this->add_control('benefit_button_background', ['label'=>'Фон кнопки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-card .zau-union-button'=>'background-color: {{VALUE}} !important;']]);
            $this->add_control('benefit_button_color', ['label'=>'Цвет текста кнопки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}} .zau-benefit-card .zau-union-button'=>'color: {{VALUE}} !important;']]);
            $this->add_responsive_control('benefit_button_radius', ['label'=>'Скругление кнопки','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>['{{WRAPPER}} .zau-benefit-card .zau-union-button'=>'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
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
            $navItems = $s['nav_items'] ?? [];
            $navItems = is_array($navItems) ? implode(',', array_map('sanitize_key', $navItems)) : sanitize_text_field((string) $navItems);
            $attrs = [
                'section' => sanitize_key($s['section_type'] ?? 'profile'),
                'title' => sanitize_text_field($s['custom_title'] ?? ''),
                'subtitle' => sanitize_text_field($s['custom_subtitle'] ?? ''),
                'show_heading' => (($s['show_heading'] ?? 'yes') === 'yes') ? 'yes' : 'no',
                'nav_items' => $navItems,
            ];
            $shortcode = '[zau_union_cabinet_section';
            foreach ($attrs as $key=>$value) { $shortcode .= ' '.$key.'="'.esc_attr($value).'"'; }
            $shortcode .= ']';
            echo do_shortcode($shortcode);
        }
    }
