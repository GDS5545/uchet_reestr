<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Common Elementor base for all public ZAU interfaces.
 * Every widget wraps the existing shortcode so business logic stays in one place,
 * while Elementor owns the visual layer through scoped selectors/CSS variables.
 */
abstract class ZAU_Union_Elementor_Widget_Base extends \Elementor\Widget_Base {
    public function get_categories() { return ['zau-union']; }
    public function get_keywords() { return ['ZAU', 'AQNIET', 'профсоюз', 'личный кабинет', 'форма', 'документы']; }

    protected function get_form_options() {
        global $wpdb;
        $table = $wpdb->prefix . 'zau_union_forms';
        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($exists !== $table) { return [0 => 'Основная активная форма']; }
        $rows = $wpdb->get_results("SELECT id,name,active FROM {$table} ORDER BY active DESC,id ASC");
        $options = [0 => 'Основная активная форма'];
        foreach ((array)$rows as $row) {
            $options[(int)$row->id] = '#' . (int)$row->id . ' — ' . $row->name . ((int)$row->active ? '' : ' (отключена)');
        }
        return $options;
    }

    protected function render_shortcode_in_wrapper($shortcode, $extra_classes = '') {
        $classes = trim('zau-elementor-interface zau-aqniet-blue zau-elementor-' . sanitize_html_class($this->get_name()) . ' ' . $extra_classes);
        echo '<div class="' . esc_attr($classes) . '">';
        echo do_shortcode($shortcode); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    protected function register_common_content_controls() {
        $this->start_controls_section('zau_content_display', ['label' => 'Отображение']);
        $this->add_control('hide_native_heading', [
            'label' => 'Скрыть встроенный заголовок',
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'label_on' => 'Да', 'label_off' => 'Нет', 'return_value' => 'yes', 'default' => '',
            'selectors' => [
                '{{WRAPPER}} .zau-elementor-interface > .zau-union-panel > h2' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-union-form-head' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-org-registry-head > div' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-public-head' => 'display:none;',
            ],
        ]);
        $this->add_control('hide_descriptions', [
            'label' => 'Скрыть поясняющие тексты',
            'type' => \Elementor\Controls_Manager::SWITCHER,
            'label_on' => 'Да', 'label_off' => 'Нет', 'return_value' => 'yes', 'default' => '',
            'selectors' => [
                '{{WRAPPER}} .zau-elementor-interface .zau-union-panel > p' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-union-form-head p' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-section-head p' => 'display:none;',
                '{{WRAPPER}} .zau-elementor-interface .zau-org-registry-head p' => 'display:none;',
            ],
        ]);
        $toggles = [
            'hide_icons' => ['Скрыть значки', '{{WRAPPER}} .zau-elementor-interface .zau-doc-icon, {{WRAPPER}} .zau-elementor-interface .zau-cabinet-avatar, {{WRAPPER}} .zau-elementor-interface .zau-public-icon'],
            'hide_statuses' => ['Скрыть статусы', '{{WRAPPER}} .zau-elementor-interface .zau-doc-state, {{WRAPPER}} .zau-elementor-interface .zau-member-status, {{WRAPPER}} .zau-elementor-interface .zau-submission-status, {{WRAPPER}} .zau-elementor-interface .zau-public-status'],
            'hide_metadata' => ['Скрыть номера, даты и метаданные', '{{WRAPPER}} .zau-elementor-interface .zau-doc-card dl, {{WRAPPER}} .zau-elementor-interface .zau-submission-row small, {{WRAPPER}} .zau-elementor-interface .zau-my-doc small'],
            'hide_primary_actions' => ['Скрыть основные кнопки', '{{WRAPPER}} .zau-elementor-interface .zau-union-button:not(.zau-secondary-button), {{WRAPPER}} .zau-elementor-interface .zau-bin-button'],
            'hide_secondary_actions' => ['Скрыть дополнительные кнопки', '{{WRAPPER}} .zau-elementor-interface .zau-secondary-button, {{WRAPPER}} .zau-elementor-interface .zau-link-button, {{WRAPPER}} .zau-elementor-interface .zau-registry-doc-button'],
            'hide_empty_states' => ['Скрыть пустые состояния', '{{WRAPPER}} .zau-elementor-interface .zau-empty-state'],
            'hide_required_marks' => ['Скрыть звёздочки обязательных полей', '{{WRAPPER}} .zau-elementor-interface .zau-required-mark'],
            'hide_branch_details' => ['Скрыть карточку реквизитов филиала', '{{WRAPPER}} .zau-elementor-interface .zau-branch-summary'],
            'hide_bin_suggestions' => ['Скрыть подсказки поиска по БИН', '{{WRAPPER}} .zau-elementor-interface .zau-bin-suggestions, {{WRAPPER}} .zau-elementor-interface .zau-bin-status'],
            'hide_signature_clear' => ['Скрыть кнопку очистки подписи', '{{WRAPPER}} .zau-elementor-interface [data-zau-clear-signature]'],
            'hide_profile_block' => ['Скрыть шапку профиля', '{{WRAPPER}} .zau-elementor-interface .zau-cabinet-hero'],
            'hide_navigation_block' => ['Скрыть навигацию кабинета', '{{WRAPPER}} .zau-elementor-interface .zau-cabinet-nav'],
            'hide_home_block' => ['Скрыть вкладку «Главная»', '{{WRAPPER}} .zau-elementor-interface #zau-home'],
            'hide_stats_block' => ['Скрыть статистику кабинета', '{{WRAPPER}} .zau-elementor-interface .zau-cabinet-stats'],
            'hide_documents_block' => ['Скрыть раздел документов', '{{WRAPPER}} .zau-elementor-interface #zau-documents'],
            'hide_submissions_block' => ['Скрыть раздел заявлений', '{{WRAPPER}} .zau-elementor-interface #zau-submissions'],
            'hide_card_block' => ['Скрыть личную карточку', '{{WRAPPER}} .zau-elementor-interface #zau-card'],
            'hide_benefits_block' => ['Скрыть акции и скидки', '{{WRAPPER}} .zau-elementor-interface #zau-benefits'],
            'hide_members_block' => ['Скрыть участников организации', '{{WRAPPER}} .zau-elementor-interface #zau-members'],
            'hide_registry_block' => ['Скрыть реестр организации', '{{WRAPPER}} .zau-elementor-interface #zau-registry'],
            'hide_info_block' => ['Скрыть материалы', '{{WRAPPER}} .zau-elementor-interface #zau-info'],
            'hide_logins_block' => ['Скрыть историю входов', '{{WRAPPER}} .zau-elementor-interface #zau-logins'],
            'hide_logout' => ['Скрыть выход', '{{WRAPPER}} .zau-elementor-interface .zau-cabinet-logout, {{WRAPPER}} .zau-elementor-interface .zau-cabinet-builder-logout'],
            'hide_registry_filters' => ['Скрыть фильтры реестра', '{{WRAPPER}} .zau-elementor-interface .zau-org-registry-filters'],
            'hide_registry_exports' => ['Скрыть панель экспорта', '{{WRAPPER}} .zau-elementor-interface .zau-org-registry-actions, {{WRAPPER}} .zau-elementor-interface .zau-registry-export-actions'],
            'hide_document_actions' => ['Скрыть просмотр и скачивание документов', '{{WRAPPER}} .zau-elementor-interface .zau-doc-actions, {{WRAPPER}} .zau-elementor-interface .zau-registry-doc-button, {{WRAPPER}} .zau-elementor-interface .zau-my-doc a'],
            'hide_modal' => ['Отключить всплывающий предпросмотр', '{{WRAPPER}} .zau-elementor-interface .zau-document-modal'],
        ];
        foreach ($toggles as $id=>$data) {
            $this->add_control($id, [
                'label'=>$data[0], 'type'=>\Elementor\Controls_Manager::SWITCHER,
                'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes','default'=>'',
                'selectors'=>[$data[1]=>'display:none!important;'],
            ]);
        }
        $this->end_controls_section();
    }

    protected function register_auth_method_controls() {
        $this->start_controls_section('zau_auth_methods', ['label'=>'Способы входа']);
        $this->add_control('enable_otp', ['label'=>'Одноразовый код','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('enable_pin', ['label'=>'Постоянный PIN','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('enable_password', ['label'=>'Логин и пароль','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('otp_email', ['label'=>'Код на email','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['enable_otp'=>'yes']]);
        $this->add_control('otp_phone', ['label'=>'Код по SMS','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['enable_otp'=>'yes']]);
        $this->add_control('default_method', ['label'=>'Открывать первым','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'otp','options'=>['otp'=>'Одноразовый код','pin'=>'Постоянный PIN','password'=>'Логин и пароль']]);
        $this->add_control('show_method_tabs', ['label'=>'Показывать вкладки','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('show_recovery', ['label'=>'Показывать восстановление','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('show_remember', ['label'=>'Показывать «Запомнить меня»','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('pin_device_only', ['label'=>'PIN без email на привязанном устройстве','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['enable_pin'=>'yes']]);
        $this->end_controls_section();
    }

    protected function auth_shortcode_attributes($settings) {
        $methods=[]; if(($settings['enable_otp']??'yes')==='yes')$methods[]='otp'; if(($settings['enable_pin']??'yes')==='yes')$methods[]='pin'; if(($settings['enable_password']??'yes')==='yes')$methods[]='password';
        $channels=[]; if(($settings['otp_email']??'yes')==='yes')$channels[]='email'; if(($settings['otp_phone']??'yes')==='yes')$channels[]='phone';
        return ' methods="'.esc_attr($methods?implode(',',$methods):'none').'" otp_channels="'.esc_attr($channels?implode(',',$channels):'none').'" default_method="'.esc_attr(sanitize_key($settings['default_method']??'otp')).'" show_tabs="'.((($settings['show_method_tabs']??'yes')==='yes')?'yes':'no').'" show_recovery="'.((($settings['show_recovery']??'yes')==='yes')?'yes':'no').'" show_remember="'.((($settings['show_remember']??'yes')==='yes')?'yes':'no').'" pin_device_only="'.((($settings['pin_device_only']??'yes')==='yes')?'yes':'no').'"';
    }

    protected function register_style_controls() {
        $scope = '{{WRAPPER}} .zau-elementor-interface';
        $containers = $scope . ' .zau-union-panel, ' . $scope . ' .zau-union-form, ' . $scope . ' .zau-cabinet, ' . $scope . ' .zau-org-registry, ' . $scope . ' .zau-public-card, ' . $scope . ' .zau-my-docs, ' . $scope . ' .zau-cabinet-builder-block';
        $cards = $scope . ' .zau-doc-card, ' . $scope . ' .zau-info-card, ' . $scope . ' .zau-submission-row, ' . $scope . ' .zau-org-member-card, ' . $scope . ' .zau-cabinet-stats>div, ' . $scope . ' .zau-registry-doc, ' . $scope . ' .zau-my-doc, ' . $scope . ' .zau-empty-state, ' . $scope . ' .zau-cabinet-section, ' . $scope . ' .zau-org-members';
        $inputs = $scope . ' input[type="text"], ' . $scope . ' input[type="email"], ' . $scope . ' input[type="password"], ' . $scope . ' input[type="tel"], ' . $scope . ' input[type="number"], ' . $scope . ' input[type="date"], ' . $scope . ' input[type="search"], ' . $scope . ' textarea, ' . $scope . ' select';
        $primary_buttons = $scope . ' .zau-union-button:not(.zau-secondary-button), ' . $scope . ' .zau-bin-button';
        $secondary_buttons = $scope . ' .zau-secondary-button, ' . $scope . ' .zau-registry-doc-button';
        $primary_hover = $scope . ' .zau-union-button:not(.zau-secondary-button):hover, ' . $scope . ' .zau-union-button:not(.zau-secondary-button):focus, ' . $scope . ' .zau-bin-button:hover, ' . $scope . ' .zau-bin-button:focus';
        $secondary_hover = $scope . ' .zau-secondary-button:hover, ' . $scope . ' .zau-secondary-button:focus, ' . $scope . ' .zau-registry-doc-button:hover, ' . $scope . ' .zau-registry-doc-button:focus';

        $this->start_controls_section('zau_style_layout', ['label' => 'Контейнер и ширина', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_responsive_control('zau_max_width', [
            'label' => 'Максимальная ширина', 'type' => \Elementor\Controls_Manager::SLIDER,
            'size_units' => ['px','%','vw'], 'range' => ['px'=>['min'=>280,'max'=>1800],'%'=>['min'=>20,'max'=>100],'vw'=>['min'=>20,'max'=>100]],
            'selectors' => [$containers => 'max-width:{{SIZE}}{{UNIT}}; width:100%;'],
        ]);
        $this->add_responsive_control('zau_outer_margin', [
            'label' => 'Внешние отступы', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px','em','%'],
            'selectors' => [$containers => 'margin:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('zau_container_padding', [
            'label' => 'Внутренние отступы', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px','em','%'],
            'selectors' => [$containers => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_control('zau_container_background', [
            'label' => 'Фон контейнера', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$containers => 'background:{{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), [
            'name' => 'zau_container_border', 'selector' => $containers,
        ]);
        $this->add_responsive_control('zau_container_radius', [
            'label' => 'Скругление', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px','%'],
            'selectors' => [$containers => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), [
            'name' => 'zau_container_shadow', 'selector' => $containers,
        ]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_palette', ['label' => 'Цветовая схема', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $palette = [
            'zau_accent' => ['Акцентный цвет','--zau-e-accent'],
            'zau_accent_hover' => ['Акцент при наведении','--zau-e-accent-hover'],
            'zau_accent_soft' => ['Светлый акцентный фон','--zau-e-accent-soft'],
            'zau_surface' => ['Фон карточек','--zau-e-surface'],
            'zau_surface_alt' => ['Дополнительный фон','--zau-e-surface-alt'],
            'zau_text' => ['Основной текст','--zau-e-text'],
            'zau_muted' => ['Вторичный текст','--zau-e-muted'],
            'zau_border_color' => ['Цвет границ','--zau-e-border'],
            'zau_success' => ['Успешный статус','--zau-e-success'],
            'zau_danger' => ['Ошибка / отозван','--zau-e-danger'],
        ];
        foreach ($palette as $id => $data) {
            $this->add_control($id, [
                'label' => $data[0], 'type' => \Elementor\Controls_Manager::COLOR,
                'selectors' => [$scope => $data[1] . ':{{VALUE}};'],
            ]);
        }
        $this->end_controls_section();

        $this->start_controls_section('zau_style_typography', ['label' => 'Тексты и заголовки', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_heading_color', [
            'label' => 'Цвет заголовков', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$scope . ' h1, ' . $scope . ' h2, ' . $scope . ' h3, ' . $scope . ' h4' => 'color:{{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'zau_heading_typography', 'label' => 'Типографика заголовков',
            'selector' => $scope . ' h1, ' . $scope . ' h2, ' . $scope . ' h3, ' . $scope . ' h4',
        ]);
        $this->add_control('zau_text_color', [
            'label' => 'Цвет текста', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$scope => 'color:{{VALUE}};', $scope . ' p, ' . $scope . ' dd, ' . $scope . ' td' => 'color:{{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'zau_text_typography', 'label' => 'Типографика текста', 'selector' => $scope,
        ]);
        $this->add_control('zau_muted_color', [
            'label' => 'Цвет пояснений', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$scope . ' small, ' . $scope . ' .zau-muted, ' . $scope . ' .zau-section-head p, ' . $scope . ' .zau-cabinet-person p, ' . $scope . ' dt' => 'color:{{VALUE}};'],
        ]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_forms', ['label' => 'Формы и поля', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_label_color', [
            'label' => 'Цвет подписей полей', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$scope . ' label, ' . $scope . ' .zau-org-registry-filters label' => 'color:{{VALUE}};'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'zau_label_typography', 'label' => 'Типографика подписей', 'selector' => $scope . ' label, ' . $scope . ' .zau-org-registry-filters label',
        ]);
        $this->add_control('zau_input_text', [
            'label' => 'Цвет текста в полях', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$inputs => 'color:{{VALUE}};'],
        ]);
        $this->add_control('zau_input_bg', [
            'label' => 'Фон полей', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$inputs => 'background-color:{{VALUE}};'],
        ]);
        $this->add_control('zau_input_border_color', [
            'label' => 'Граница полей', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$inputs => 'border-color:{{VALUE}};'],
        ]);
        $this->add_control('zau_input_focus_color', [
            'label' => 'Граница при фокусе', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$inputs . ':focus' => 'border-color:{{VALUE}}; outline:2px solid {{VALUE}}; outline-offset:1px;'],
        ]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'zau_input_typography', 'label' => 'Типографика полей', 'selector' => $inputs,
        ]);
        $this->add_responsive_control('zau_input_padding', [
            'label' => 'Отступы полей', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px','em'],
            'selectors' => [$inputs => 'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('zau_input_radius', [
            'label' => 'Скругление полей', 'type' => \Elementor\Controls_Manager::DIMENSIONS, 'size_units' => ['px','%'],
            'selectors' => [$inputs => 'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('zau_field_spacing', [
            'label' => 'Расстояние между полями', 'type' => \Elementor\Controls_Manager::SLIDER, 'size_units' => ['px','em'],
            'range' => ['px'=>['min'=>0,'max'=>80],'em'=>['min'=>0,'max'=>6,'step'=>0.1]],
            'selectors' => [$scope . ' .zau-form-field' => 'margin-bottom:{{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('zau_signature_bg', [
            'label' => 'Фон поля подписи', 'type' => \Elementor\Controls_Manager::COLOR,
            'selectors' => [$scope . ' .zau-signature-wrap, ' . $scope . ' .zau-signature-canvas' => 'background-color:{{VALUE}};'],
        ]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_primary_button', ['label' => 'Основные кнопки', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), [
            'name' => 'zau_button_typography', 'selector' => $primary_buttons,
        ]);
        $this->start_controls_tabs('zau_button_tabs');
        $this->start_controls_tab('zau_button_normal', ['label' => 'Обычная']);
        $this->add_control('zau_button_text_color', ['label'=>'Текст','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_buttons=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_button_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_buttons=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_button_border_color', ['label'=>'Граница','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_buttons=>'border-color:{{VALUE}}; border-style:solid;']]);
        $this->end_controls_tab();
        $this->start_controls_tab('zau_button_hover', ['label' => 'Наведение']);
        $this->add_control('zau_button_hover_text', ['label'=>'Текст','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_hover=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_button_hover_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_hover=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_button_hover_border', ['label'=>'Граница','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$primary_hover=>'border-color:{{VALUE}};']]);
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->add_responsive_control('zau_button_padding', [
            'label'=>'Отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],
            'selectors'=>[$primary_buttons=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('zau_button_radius', [
            'label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],
            'selectors'=>[$primary_buttons=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};'],
        ]);
        $this->add_responsive_control('zau_button_width', [
            'label'=>'Ширина кнопок','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>[''=>'Авто','100%'=>'На всю ширину'],
            'selectors'=>[$primary_buttons=>'width:{{VALUE}};'],
        ]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_secondary_button', ['label' => 'Дополнительные кнопки и ссылки', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_secondary_text', ['label'=>'Цвет текста','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_buttons=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_secondary_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_buttons=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_secondary_hover', ['label'=>'Фон при наведении','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_hover=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_link_color', ['label'=>'Цвет текстовых ссылок','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' a:not(.zau-union-button):not(.zau-registry-doc-button), '.$scope.' .zau-link-button'=>'color:{{VALUE}};']]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_cards', ['label' => 'Карточки и разделы', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_card_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$cards=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_card_border','selector'=>$cards]);
        $this->add_responsive_control('zau_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$cards=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('zau_card_padding', ['label'=>'Отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],'selectors'=>[$cards=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'zau_card_shadow','selector'=>$cards]);
        $this->add_responsive_control('zau_card_gap', ['label'=>'Расстояние между карточками','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>['px','em'],'range'=>['px'=>['min'=>0,'max'=>60],'em'=>['min'=>0,'max'=>5,'step'=>0.1]],'selectors'=>[$scope.' .zau-doc-grid, '.$scope.' .zau-info-list, '.$scope.' .zau-submission-list, '.$scope.' .zau-org-member-list'=>'gap:{{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('zau_docs_columns', ['label'=>'Колонок документов','type'=>\Elementor\Controls_Manager::SELECT,'options'=>['1'=>'1','2'=>'2','3'=>'3','4'=>'4'],'selectors'=>[$scope.' .zau-doc-grid'=>'grid-template-columns:repeat({{VALUE}},minmax(0,1fr));']]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_navigation', ['label' => 'Навигация кабинета', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_nav_bg', ['label'=>'Фон панели','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-cabinet-nav'=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_nav_border','selector'=>$scope.' .zau-cabinet-nav']);
        $this->add_responsive_control('zau_nav_radius', ['label'=>'Скругление панели','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$scope.' .zau-cabinet-nav'=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_control('zau_nav_text', ['label'=>'Цвет пунктов','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-cabinet-nav a'=>'color:{{VALUE}};']]);
        $this->add_control('zau_nav_hover_bg', ['label'=>'Фон пункта при наведении','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-cabinet-nav a:hover, '.$scope.' .zau-cabinet-nav a:focus'=>'background:{{VALUE}};']]);
        $this->add_control('zau_nav_hover_text', ['label'=>'Текст при наведении','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-cabinet-nav a:hover, '.$scope.' .zau-cabinet-nav a:focus'=>'color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_nav_typography','selector'=>$scope.' .zau-cabinet-nav a']);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_table', ['label' => 'Таблица реестра', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_table_header_bg', ['label'=>'Фон заголовка','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table th'=>'background:{{VALUE}};']]);
        $this->add_control('zau_table_header_text', ['label'=>'Текст заголовка','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table th'=>'color:{{VALUE}};']]);
        $this->add_control('zau_table_row_bg', ['label'=>'Фон строк','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table td'=>'background:{{VALUE}};']]);
        $this->add_control('zau_table_row_alt_bg', ['label'=>'Фон чётных строк','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table tr:nth-child(even) td'=>'background:{{VALUE}};']]);
        $this->add_control('zau_table_border', ['label'=>'Разделители','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table td, '.$scope.' .zau-org-registry-table-wrap'=>'border-color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_table_typography','selector'=>$scope.' .zau-org-registry-table']);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_status', ['label' => 'Статусы и значки', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_status_active_bg', ['label'=>'Фон активного статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-state.is-active, '.$scope.' .zau-public-status'=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_status_active_text', ['label'=>'Текст активного статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-state.is-active, '.$scope.' .zau-member-status strong, '.$scope.' .zau-public-status'=>'color:{{VALUE}};']]);
        $this->add_control('zau_status_danger_bg', ['label'=>'Фон отозванного статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-state.is-revoked'=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_status_danger_text', ['label'=>'Текст отозванного статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-state.is-revoked, '.$scope.' .zau-revoked .zau-public-status'=>'color:{{VALUE}};']]);
        $this->add_control('zau_icon_bg', ['label'=>'Фон значков','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-icon, '.$scope.' .zau-cabinet-avatar, '.$scope.' .zau-public-icon'=>'background:{{VALUE}};']]);
        $this->add_control('zau_icon_color', ['label'=>'Цвет значков','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-doc-icon, '.$scope.' .zau-cabinet-avatar, '.$scope.' .zau-public-icon'=>'color:{{VALUE}};']]);
        $this->end_controls_section();

        $this->start_controls_section('zau_style_modal', ['label' => 'Всплывающий просмотр PDF', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_modal_overlay', ['label'=>'Затемнение','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-document-modal-backdrop'=>'background:{{VALUE}};']]);
        $this->add_control('zau_modal_bg', ['label'=>'Фон окна','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-document-modal-dialog, '.$scope.' .zau-document-modal-head'=>'background:{{VALUE}};']]);
        $this->add_control('zau_modal_body_bg', ['label'=>'Фон области документа','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-document-modal-body'=>'background:{{VALUE}};']]);
        $this->add_responsive_control('zau_modal_width', ['label'=>'Ширина окна','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>['px','vw','%'],'range'=>['px'=>['min'=>320,'max'=>1600],'vw'=>['min'=>30,'max'=>100],'%'=>['min'=>30,'max'=>100]],'selectors'=>[$scope.' .zau-document-modal-dialog'=>'width:{{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('zau_modal_radius', ['label'=>'Скругление окна','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$scope.' .zau-document-modal-dialog'=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->end_controls_section();
    }

    protected function wrapper_classes_from_settings($settings) {
        $classes = [];
        if (($settings['hide_native_heading'] ?? '') === 'yes') { $classes[] = 'zau-e-hide-heading'; }
        if (($settings['hide_descriptions'] ?? '') === 'yes') { $classes[] = 'zau-e-hide-descriptions'; }
        $tab_classes=[
            'hide_home_block'=>'zau-e-hide-home','hide_documents_block'=>'zau-e-hide-documents',
            'hide_submissions_block'=>'zau-e-hide-submissions','hide_card_block'=>'zau-e-hide-card','hide_benefits_block'=>'zau-e-hide-benefits','hide_members_block'=>'zau-e-hide-members',
            'hide_registry_block'=>'zau-e-hide-registry','hide_info_block'=>'zau-e-hide-info',
            'hide_logins_block'=>'zau-e-hide-logins',
        ];
        foreach($tab_classes as $setting=>$class){if(($settings[$setting]??'')==='yes')$classes[]=$class;}
        return implode(' ', $classes);
    }
}

class ZAU_Union_Elementor_Auth_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-auth'; }
    public function get_title() { return 'ZAU — вход в кабинет'; }
    public function get_icon() { return 'eicon-lock-user'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Содержимое']);
        $this->add_control('return_url', ['label'=>'Куда перейти после входа','type'=>\Elementor\Controls_Manager::URL,'placeholder'=>home_url('/lk-profsoyuz/')]);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $url = !empty($s['return_url']['url']) ? esc_url_raw($s['return_url']['url']) : '';
        $shortcode = '[zau_union_auth' . ($url ? ' return_url="' . esc_attr($url) . '"' : '') . $this->auth_shortcode_attributes($s) . ']';
        $this->render_shortcode_in_wrapper($shortcode, $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_Form_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-form'; }
    public function get_title() { return 'ZAU — форма регистрации'; }
    public function get_icon() { return 'eicon-form-horizontal'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Форма']);
        $this->add_control('form_id', ['label'=>'Выберите форму','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$this->get_form_options(),'default'=>0]);
        $this->add_control('pin_setup', ['label'=>'Создание постоянного PIN','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'inherit','options'=>['inherit'=>'Как в общих настройках','off'=>'Не показывать','optional'=>'Предлагать','required'=>'Требовать']]);
        $this->add_control('login_return_url', ['label'=>'После входа перейти','type'=>\Elementor\Controls_Manager::URL,'placeholder'=>home_url('/lk-profsoyuz/'),'description'=>'Для уже зарегистрированного участника. Регистрация после кода останется на странице анкеты.']);
        $this->add_control('mobile_steps', ['label'=>'Пошаговая форма AQNIET','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','description'=>'Разбивает длинную анкету на удобные шаги с кнопками «Назад» и «Далее».']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $id = absint($s['form_id'] ?? 0);
        $pin=in_array(($s['pin_setup']??'inherit'),['inherit','off','optional','required'],true)?$s['pin_setup']:'inherit';
        $loginUrl=!empty($s['login_return_url']['url'])?esc_url_raw($s['login_return_url']['url']):'';
        $mobileSteps=(($s['mobile_steps']??'yes')==='yes')?'yes':'no';
        $this->render_shortcode_in_wrapper('[zau_union_form' . ($id ? ' id="'.$id.'"' : '') . ' pin_setup="'.esc_attr($pin).'" mobile_steps="'.$mobileSteps.'"' . ($loginUrl?' login_return_url="'.esc_attr($loginUrl).'"':'') . $this->auth_shortcode_attributes($s) . ']', $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_Portal_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-portal'; }
    public function get_title() { return 'ZAU — вход + регистрация'; }
    public function get_icon() { return 'eicon-sign-in'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Портал']);
        $this->add_control('form_id', ['label'=>'Форма после входа','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$this->get_form_options(),'default'=>0]);
        $this->add_control('pin_setup', ['label'=>'Создание постоянного PIN','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'inherit','options'=>['inherit'=>'Как в общих настройках','off'=>'Не показывать','optional'=>'Предлагать','required'=>'Требовать']]);
        $this->add_control('default_flow', ['label'=>'Что показывать первым','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'choice','options'=>['choice'=>'Сначала выбор «Войти / Зарегистрироваться»','register'=>'Сразу регистрация нового участника','login'=>'Сразу вход существующего участника']]);
        $this->add_control('show_start_choice', ['label'=>'Сначала показывать выбор «Войти / Зарегистрироваться»','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('show_flow_tabs', ['label'=>'Показывать выбор «Регистрация / Вход»','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('login_return_url', ['label'=>'После входа перейти','type'=>\Elementor\Controls_Manager::URL,'placeholder'=>home_url('/lk-profsoyuz/'),'description'=>'Переход только для существующего участника. После подтверждения новой регистрации откроется анкета на этой странице.']);
        $this->add_control('mobile_steps', ['label'=>'Пошаговая форма AQNIET','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $id = absint($s['form_id'] ?? 0);
        $pin=in_array(($s['pin_setup']??'inherit'),['inherit','off','optional','required'],true)?$s['pin_setup']:'inherit';
        $flow=in_array(($s['default_flow']??'choice'),['choice','register','login'],true)?$s['default_flow']:'choice';
        $showFlow=(($s['show_flow_tabs']??'yes')==='yes')?'yes':'no';
        $loginUrl=!empty($s['login_return_url']['url'])?esc_url_raw($s['login_return_url']['url']):'';
        $mobileSteps=(($s['mobile_steps']??'yes')==='yes')?'yes':'no';
        $startScreen=(($s['show_start_choice']??'yes')==='yes')?'yes':'no';
        if($flow==='choice')$startScreen='yes';
        $fallbackFlow=$flow==='choice'?'register':$flow;
        $this->render_shortcode_in_wrapper('[zau_union_portal' . ($id ? ' id="'.$id.'"' : '') . ' pin_setup="'.esc_attr($pin).'" mobile_steps="'.$mobileSteps.'" default_flow="'.esc_attr($fallbackFlow).'" start_screen="'.$startScreen.'" show_flow_tabs="'.$showFlow.'"' . ($loginUrl?' login_return_url="'.esc_attr($loginUrl).'"':'') . $this->auth_shortcode_attributes($s) . ']', $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Cabinet_Section_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-cabinet-section'; }
    public function get_title() { return 'ZAU — личный кабинет / раздел'; }
    public function get_icon() { return 'eicon-dashboard'; }
    protected function register_controls() {
        $builtInTabs=['home'=>'Главная','documents'=>'Документы','submissions'=>'Заявления','card'=>'Личная карточка','benefits'=>'Акции и скидки','members'=>'Участники','registry'=>'Реестр организации','info'=>'Материалы','logins'=>'История входов'];
        $customTabs=[];
        foreach(get_posts(['post_type'=>'zau_union_tab','post_status'=>'publish','numberposts'=>-1,'orderby'=>['menu_order'=>'ASC','date'=>'ASC']]) as $tabPost){
            if(get_post_meta($tabPost->ID,'_zau_tab_enabled',true)==='0')continue;
            $slug=sanitize_title((string)get_post_meta($tabPost->ID,'_zau_tab_slug',true));
            if(!$slug)$slug='custom-'.$tabPost->ID;
            $customTabs[$slug]='Своя вкладка: '.$tabPost->post_title;
        }
        $allTabs=$builtInTabs+$customTabs;
        $sectionOptions=['full'=>'Личный кабинет целиком','profile'=>'Шапка профиля и статус','navigation'=>'Навигация','stats'=>'Статистика','documents'=>'Мои документы','submissions'=>'Мои заявления','card'=>'Личная карточка','benefits'=>'Акции и скидки','members'=>'Участники организации','registry'=>'Реестр организации','info'=>'Материалы','logins'=>'История входов','logout'=>'Кнопка выхода'];
        foreach($customTabs as $slug=>$label)$sectionOptions[$slug]=$label;
        $this->start_controls_section('content_section', ['label'=>'Содержимое']);
        $this->add_control('section_type', [
            'label'=>'Что вывести','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'full',
            'options'=>$sectionOptions,
        ]);
        $this->add_control('cabinet_default_tab', ['label'=>'Открывать вкладку','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'home','options'=>$allTabs,'condition'=>['section_type'=>'full']]);
        $this->add_control('cabinet_tabs', ['label'=>'Стандартные вкладки','type'=>\Elementor\Controls_Manager::SELECT2,'multiple'=>true,'default'=>array_keys($builtInTabs),'options'=>$builtInTabs,'condition'=>['section_type'=>'full'],'description'=>'Для обычного участника вкладки ответственного автоматически скрываются. Собственные вкладки управляются отдельным переключателем ниже.']);
        $this->add_control('show_custom_tabs', ['label'=>'Показывать собственные вкладки','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>'full'],'description'=>'Создание и доступ: «Профсоюз → Вкладки кабинета».']);
        $this->add_control('show_quick_actions', ['label'=>'Быстрые действия на главной','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>'full']]);
        $this->add_control('show_document_search', ['label'=>'Поиск и фильтр документов','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>'full']]);
        $this->add_control('show_member_card', ['label'=>'Показывать личную карточку','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>'full'],'description'=>'Скрывает вкладку, быстрый переход и содержимое личной карточки в этом экземпляре кабинета.']);
        $this->add_control('mobile_bottom_nav', ['label'=>'Закреплённые вкладки на телефоне','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','prefix_class'=>'zau-nav-mobile-fixed-','condition'=>['section_type'=>'full']]);
        $this->add_control('custom_title', ['label'=>'Свой заголовок','type'=>\Elementor\Controls_Manager::TEXT,'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('custom_subtitle', ['label'=>'Своё описание','type'=>\Elementor\Controls_Manager::TEXTAREA,'rows'=>3,'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('show_heading', ['label'=>'Показывать заголовок раздела','type'=>\Elementor\Controls_Manager::SWITCHER,'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('nav_items', ['label'=>'Пункты навигации','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'home,documents,submissions,card,benefits,members,registry,info,logins','description'=>'Допустимо: home, documents, submissions, card, benefits, members, registry, info, logins.','condition'=>['section_type'=>'navigation']]);
        $this->end_controls_section();

        $this->start_controls_section('content_nav_style', ['label'=>'Пункты меню: иконки и подписи','condition'=>['section_type'=>['full','navigation']]]);
        $iconGlyphs=['' =>'По умолчанию','⌂'=>'⌂ Дом','▤'=>'▤ Документ','✓'=>'✓ Галочка','▣'=>'▣ Карточка','%'=>'% Процент','◎'=>'◎ Люди','☰'=>'☰ Список','ℹ'=>'ℹ Информация','⏱'=>'⏱ Часы','★'=>'★ Звезда','♥'=>'♥ Сердце','⚑'=>'⚑ Флаг','⚙'=>'⚙ Шестерня','✉'=>'✉ Конверт','custom'=>'Свой символ / эмодзи…'];
        $repeater = new \Elementor\Repeater();
        $repeater->add_control('tab_key', ['label'=>'Вкладка','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'home','options'=>$builtInTabs]);
        $repeater->add_control('icon_choice', ['label'=>'Иконка','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>$iconGlyphs]);
        $repeater->add_control('icon_custom', ['label'=>'Свой символ / эмодзи','type'=>\Elementor\Controls_Manager::TEXT,'condition'=>['icon_choice'=>'custom']]);
        $repeater->add_control('label_short', ['label'=>'Короткое название (телефон)','type'=>\Elementor\Controls_Manager::TEXT,'placeholder'=>'Например: Акции']);
        $repeater->add_control('label_full', ['label'=>'Полное название (компьютер)','type'=>\Elementor\Controls_Manager::TEXT,'placeholder'=>'Оставьте пустым для стандартного']);
        $this->add_control('nav_item_styles', [
            'label'=>'Пункты меню','type'=>\Elementor\Controls_Manager::REPEATER,
            'fields'=>$repeater->get_controls(),'title_field'=>'{{{ tab_key }}}',
            'description'=>'Переопределяет иконку и подписи выбранной вкладки. Короткая подпись используется на телефоне, полная — на компьютере. Вкладки, не добавленные сюда, используют стандартный вид.',
        ]);
        $this->end_controls_section();

        $this->start_controls_section('content_benefits', ['label'=>'Акции: содержимое карточек','condition'=>['section_type'=>'benefits']]);
        $this->add_responsive_control('benefits_columns', ['label'=>'Колонок в сетке','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'3','options'=>['1'=>'1','2'=>'2','3'=>'3','4'=>'4'],'selectors'=>['{{WRAPPER}} .zau-elementor-interface .zau-benefit-grid'=>'grid-template-columns:repeat({{VALUE}},minmax(0,1fr));']]);
        $this->add_control('benefits_order_by', ['label'=>'Сортировка','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'menu_order','options'=>['menu_order'=>'Порядок в списке акций','date'=>'Дата публикации','title'=>'Название']]);
        $this->add_control('benefits_order', ['label'=>'Направление','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'ASC','options'=>['ASC'=>'По возрастанию','DESC'=>'По убыванию']]);
        $this->add_control('benefits_limit', ['label'=>'Максимум карточек','type'=>\Elementor\Controls_Manager::NUMBER,'default'=>0,'min'=>0,'max'=>100,'description'=>'0 — показать все доступные (до 100).']);
        $this->add_control('benefits_image_ratio', ['label'=>'Пропорции изображения','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'','options'=>['' =>'Как в оригинале (180px)','1:1'=>'Квадрат 1:1','4:3'=>'Альбомное 4:3','16:9'=>'Широкое 16:9']]);
        $this->add_control('benefits_show_image', ['label'=>'Показывать изображение','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_title', ['label'=>'Показывать название','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_badge', ['label'=>'Показывать бейдж скидки','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_partner', ['label'=>'Показывать партнёра','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_description', ['label'=>'Показывать описание','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_promo', ['label'=>'Показывать промокод','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_expiry', ['label'=>'Показывать срок действия','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('benefits_show_button', ['label'=>'Показывать кнопку','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->end_controls_section();

        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
        $this->register_mobile_nav_style_controls();
        $this->register_benefits_style_controls();
    }

    protected function register_mobile_nav_style_controls() {
        $this->start_controls_section('style_nav_mobile', ['label'=>'Навигация на телефоне','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>'full']]);
        $this->add_control('nav_mobile_hint', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'Действует, когда включено «Закреплённые вкладки на телефоне» на вкладке «Содержимое».']);
        $this->add_responsive_control('nav_mobile_icon_size', ['label'=>'Размер иконки, px','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>12,'max'=>36]],'selectors'=>['{{WRAPPER}} .zau-cabinet-nav-icon'=>'font-size:{{SIZE}}{{UNIT}};']]);
        $this->add_control('nav_mobile_background', ['label'=>'Фон нижнего меню','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}}.zau-nav-mobile-fixed-yes .zau-cabinet-nav'=>'background-color:{{VALUE}};']]);
        $this->add_control('nav_mobile_text_color', ['label'=>'Цвет текста и иконок','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}}.zau-nav-mobile-fixed-yes .zau-cabinet-nav a'=>'color:{{VALUE}};']]);
        $this->add_control('nav_mobile_active_color', ['label'=>'Цвет активного пункта','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>['{{WRAPPER}}.zau-nav-mobile-fixed-yes .zau-cabinet-nav a.is-active'=>'color:{{VALUE}}!important;']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'nav_mobile_shadow','selector'=>'{{WRAPPER}}.zau-nav-mobile-fixed-yes .zau-cabinet-nav']);
        $this->end_controls_section();
    }

    protected function register_benefits_style_controls() {
        $scope = '{{WRAPPER}} .zau-elementor-interface';
        $this->start_controls_section('style_benefits', ['label'=>'Оформление акций (сетка и карточка)','tab'=>\Elementor\Controls_Manager::TAB_STYLE,'condition'=>['section_type'=>'benefits']]);
        $this->add_responsive_control('benefits_gap', ['label'=>'Промежуток между карточками, px','type'=>\Elementor\Controls_Manager::SLIDER,'range'=>['px'=>['min'=>0,'max'=>60]],'selectors'=>[$scope.' .zau-benefit-grid'=>'gap:{{SIZE}}{{UNIT}};']]);
        $this->add_control('benefit_card_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Карточка']);
        $this->add_control('benefit_card_background', ['label'=>'Фон карточки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-card'=>'background-color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'benefit_card_border','selector'=>$scope.' .zau-benefit-card']);
        $this->add_responsive_control('benefit_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$scope.' .zau-benefit-card'=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('benefit_card_padding', ['label'=>'Внутренние отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em','%'],'selectors'=>[$scope.' .zau-benefit-content'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'benefit_card_shadow','selector'=>$scope.' .zau-benefit-card']);
        $this->add_control('benefit_badge_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Бейдж скидки','separator'=>'before']);
        $this->add_control('benefit_badge_background', ['label'=>'Фон бейджа','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-badge'=>'background-color:{{VALUE}};']]);
        $this->add_control('benefit_badge_color', ['label'=>'Цвет текста бейджа','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-badge'=>'color:{{VALUE}};']]);
        $this->add_control('benefit_title_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Название и текст','separator'=>'before']);
        $this->add_control('benefit_title_color', ['label'=>'Цвет названия','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-card h4'=>'color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'benefit_title_typography','selector'=>$scope.' .zau-benefit-card h4']);
        $this->add_control('benefit_partner_color', ['label'=>'Цвет партнёра','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-partner'=>'color:{{VALUE}};']]);
        $this->add_control('benefit_description_color', ['label'=>'Цвет описания','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-description'=>'color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'benefit_description_typography','selector'=>$scope.' .zau-benefit-description']);
        $this->add_control('benefit_button_heading', ['type'=>\Elementor\Controls_Manager::HEADING,'label'=>'Кнопка','separator'=>'before']);
        $this->add_control('benefit_button_background', ['label'=>'Фон кнопки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-card .zau-union-button'=>'background-color:{{VALUE}}!important;']]);
        $this->add_control('benefit_button_color', ['label'=>'Цвет текста кнопки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-card .zau-union-button'=>'color:{{VALUE}}!important;']]);
        $this->add_responsive_control('benefit_button_radius', ['label'=>'Скругление кнопки','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$scope.' .zau-benefit-card .zau-union-button'=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->end_controls_section();
    }

    protected function nav_style_json($s) {
        $rows=is_array($s['nav_item_styles']??null)?$s['nav_item_styles']:[];
        if(!$rows)return '';
        $clean=[];
        foreach($rows as $row){
            if(empty($row['tab_key']))continue;
            $clean[]=['tab_key'=>$row['tab_key'],'icon_choice'=>$row['icon_choice']??'','icon_custom'=>$row['icon_custom']??'','label_short'=>$row['label_short']??'','label_full'=>$row['label_full']??''];
        }
        return $clean?wp_json_encode($clean,JSON_UNESCAPED_UNICODE):'';
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $auth = $this->auth_shortcode_attributes($s);
        $type = sanitize_key($s['section_type'] ?? 'full');
        $navStyle = $this->nav_style_json($s);
        if ($type === 'full') {
            $defaultTab=sanitize_key($s['cabinet_default_tab']??'home');
            $tabs=is_array($s['cabinet_tabs']??null)?array_values(array_filter(array_map('sanitize_key',$s['cabinet_tabs']))):['home','documents','submissions','members','registry','info','logins'];
            $showCustom=(($s['show_custom_tabs']??'yes')==='yes')?'yes':'no';
            $quick=(($s['show_quick_actions']??'yes')==='yes')?'yes':'no';
            $docSearch=(($s['show_document_search']??'yes')==='yes')?'yes':'no';
            $memberCard=(($s['show_member_card']??'yes')==='yes')?'yes':'no';
            if($memberCard==='no')$tabs=array_values(array_diff($tabs,['card']));
            $bottomNav=(($s['mobile_bottom_nav']??'yes')==='yes')?'yes':'no';
            $shortcode = '[zau_union_cabinet default_tab="'.esc_attr($defaultTab).'" tabs="'.esc_attr(implode(',',$tabs)).'" custom_tabs="'.$showCustom.'" quick_actions="'.$quick.'" document_search="'.$docSearch.'" member_card="'.$memberCard.'" mobile_bottom_nav="'.$bottomNav.'" nav_style="'.esc_attr($navStyle).'"' . $auth . ']';
        }
        elseif ($type === 'registry') { $shortcode = '[zau_union_org_registry' . $auth . ']'; }
        elseif (get_page_by_path($type,OBJECT,'zau_union_tab') || get_posts(['post_type'=>'zau_union_tab','post_status'=>'publish','numberposts'=>1,'meta_key'=>'_zau_tab_slug','meta_value'=>$type])) { $shortcode = '[zau_union_custom_tab slug="'.esc_attr($type).'"' . $auth . ']'; }
        elseif ($type === 'benefits') {
            $shortcode = '[zau_union_benefits title="'.esc_attr(sanitize_text_field($s['custom_title'] ?? '')).'" subtitle="'.esc_attr(sanitize_text_field($s['custom_subtitle'] ?? '')).'" show_heading="'.((($s['show_heading'] ?? 'yes')==='yes')?'yes':'no').'" order_by="'.esc_attr(sanitize_key($s['benefits_order_by']??'menu_order')).'" order="'.esc_attr(sanitize_key($s['benefits_order']??'ASC')).'" limit="'.(int)($s['benefits_limit']??0).'" image_ratio="'.esc_attr(sanitize_text_field($s['benefits_image_ratio']??'')).'" show_image="'.((($s['benefits_show_image']??'yes')==='yes')?'yes':'no').'" show_title="'.((($s['benefits_show_title']??'yes')==='yes')?'yes':'no').'" show_badge="'.((($s['benefits_show_badge']??'yes')==='yes')?'yes':'no').'" show_partner="'.((($s['benefits_show_partner']??'yes')==='yes')?'yes':'no').'" show_description="'.((($s['benefits_show_description']??'yes')==='yes')?'yes':'no').'" show_promo="'.((($s['benefits_show_promo']??'yes')==='yes')?'yes':'no').'" show_expiry="'.((($s['benefits_show_expiry']??'yes')==='yes')?'yes':'no').'" show_button="'.((($s['benefits_show_button']??'yes')==='yes')?'yes':'no').'"' . $auth . ']';
        }
        else {
            $shortcode = '[zau_union_cabinet_section section="'.esc_attr($type).'" title="'.esc_attr(sanitize_text_field($s['custom_title'] ?? '')).'" subtitle="'.esc_attr(sanitize_text_field($s['custom_subtitle'] ?? '')).'" show_heading="'.((($s['show_heading'] ?? 'yes')==='yes')?'yes':'no').'" nav_items="'.esc_attr(sanitize_text_field($s['nav_items'] ?? '')).'" nav_style="'.esc_attr($navStyle).'"' . $auth . ']';
        }
        $this->render_shortcode_in_wrapper($shortcode, $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_Org_Members_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-org-members'; }
    public function get_title() { return 'ZAU — участники организации'; }
    public function get_icon() { return 'eicon-users'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Содержимое']);
        $this->add_control('show_heading', ['label'=>'Показывать заголовок','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zau_union_org_members show_heading="'.((($s['show_heading'] ?? 'yes')==='yes')?'yes':'no').'"' . $this->auth_shortcode_attributes($s) . ']', $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_Org_Registry_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-org-registry'; }
    public function get_title() { return 'ZAU — реестр организации'; }
    public function get_icon() { return 'eicon-table'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Реестр']);
        $this->add_control('editor_note', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'На сайте реестр увидят только администратор или назначенный ответственный организации.','content_classes'=>'elementor-panel-alert elementor-panel-alert-info']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zau_union_org_registry' . $this->auth_shortcode_attributes($s) . ']', $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_Verify_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-verify'; }
    public function get_title() { return 'ZAU — проверка QR'; }
    public function get_icon() { return 'eicon-check-circle'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Проверка']);
        $this->add_control('editor_note', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'В редакторе показывается состояние без QR-токена. На реальной ссылке данные документа подставятся автоматически.','content_classes'=>'elementor-panel-alert elementor-panel-alert-info']);
        $this->end_controls_section();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zau_certificate_verify]', $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Elementor_My_Documents_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-my-documents'; }
    public function get_title() { return 'ZAU — мои документы'; }
    public function get_icon() { return 'eicon-document-file'; }
    protected function register_controls() {
        $this->start_controls_section('content', ['label'=>'Документы']);
        $this->add_control('editor_note', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'Компактный список документов текущего пользователя. Для карточек и всплывающего просмотра используйте раздел «Мои документы» виджета личного кабинета.','content_classes'=>'elementor-panel-alert elementor-panel-alert-info']);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper('[zau_my_certificates' . $this->auth_shortcode_attributes($s) . ']', $this->wrapper_classes_from_settings($s));
    }
}
