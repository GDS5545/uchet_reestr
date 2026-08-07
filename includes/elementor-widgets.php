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
        $this->add_control('zau_secondary_note', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'Сюда относится, например, кнопка «Предпросмотр» в документах и «Личная карточка» в списке участников организации.']);
        $this->add_control('zau_secondary_text', ['label'=>'Цвет текста','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_buttons=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_secondary_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_buttons=>'background-color:{{VALUE}}!important;']]);
        $this->add_control('zau_secondary_border', ['label'=>'Граница','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_buttons=>'border-color:{{VALUE}}!important;']]);
        $this->add_control('zau_secondary_hover', ['label'=>'Фон при наведении','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_hover=>'background-color:{{VALUE}}!important;']]);
        $this->add_control('zau_secondary_hover_text', ['label'=>'Текст при наведении','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$secondary_hover=>'color:{{VALUE}}!important;']]);
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
        $this->add_control('zau_table_header_bg', ['label'=>'Фон заголовка','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table th'=>'background:{{VALUE}}!important;']]);
        $this->add_control('zau_table_header_text', ['label'=>'Текст заголовка','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table th'=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_table_row_bg', ['label'=>'Фон строк','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table td'=>'background:{{VALUE}};']]);
        $this->add_control('zau_table_row_alt_bg', ['label'=>'Фон чётных строк','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table tr:nth-child(even) td'=>'background:{{VALUE}};']]);
        $this->add_control('zau_table_border', ['label'=>'Разделители','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-org-registry-table td, '.$scope.' .zau-org-registry-table-wrap'=>'border-color:{{VALUE}}!important;']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_table_typography','selector'=>$scope.' .zau-org-registry-table']);
        $this->add_responsive_control('zau_table_cell_padding', ['label'=>'Отступы в ячейках','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],'selectors'=>[$scope.' .zau-org-registry-table th, '.$scope.' .zau-org-registry-table td'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
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

        $this->register_auth_style_controls($scope);
        $this->register_document_card_style_controls($scope);
        $this->register_benefit_card_style_controls($scope);
        $this->register_member_card_style_controls($scope);
        $this->register_org_members_style_controls($scope);
    }

    /** Список участников организации/филиала (вкладка «Участники») — карточка каждого
     * участника, текст в ней и размеры строк раньше не были отдельно доступны в Elementor. */
    protected function register_org_members_style_controls($scope) {
        $card = $scope . ' .zau-org-member-card';
        $this->start_controls_section('zau_style_org_members', ['label' => 'Список участников организации', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_org_member_bg', ['label'=>'Фон карточки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_org_member_border','selector'=>$card]);
        $this->add_responsive_control('zau_org_member_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$card=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('zau_org_member_padding', ['label'=>'Отступы карточки','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],'selectors'=>[$card=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('zau_org_member_gap', ['label'=>'Расстояние между карточками','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>['px','em'],'range'=>['px'=>['min'=>0,'max'=>40],'em'=>['min'=>0,'max'=>3,'step'=>0.1]],'selectors'=>[$scope.' .zau-org-member-list'=>'gap:{{SIZE}}{{UNIT}};']]);
        $this->add_control('zau_org_member_name_heading', ['label'=>'ФИО участника','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_org_member_name_color', ['label'=>'Цвет','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-org-member-main strong'=>'color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_org_member_name_typography','selector'=>$card.' .zau-org-member-main strong']);
        $this->add_control('zau_org_member_meta_heading', ['label'=>'Контакты и служебные данные','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_org_member_meta_color', ['label'=>'Цвет','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-org-member-main span, '.$card.' .zau-org-member-main small'=>'color:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_org_member_meta_typography','selector'=>$card.' .zau-org-member-main span, '.$card.' .zau-org-member-main small']);
        $this->add_control('zau_org_member_control_heading', ['label'=>'Выбор статуса и кнопки','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_org_member_select_bg', ['label'=>'Фон списка статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-org-member-control select'=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_org_member_select_text', ['label'=>'Текст списка статуса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-org-member-control select'=>'color:{{VALUE}};']]);
        $this->add_responsive_control('zau_org_member_button_padding', ['label'=>'Отступы кнопок','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],'selectors'=>[$card.' .zau-small-button'=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('zau_org_member_button_font_size', ['label'=>'Размер текста кнопок','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>['px'],'range'=>['px'=>['min'=>10,'max'=>22]],'selectors'=>[$card.' .zau-small-button'=>'font-size:{{SIZE}}{{UNIT}};']]);
        $this->end_controls_section();
    }

    /** Кнопки и карточки выбора экрана «вход/регистрация» — отдельно от кнопок внутри
     * личного кабинета, так как обе живут в одном виджете «Кабинет или вход» и до этого
     * делили один и тот же стиль «Основные/дополнительные кнопки». */
    protected function register_auth_style_controls($scope) {
        $authScope = $scope . ' .zau-auth';
        $flowTabs = $authScope . ' .zau-auth-flow-tab';
        $submit = $authScope . ' .zau-auth-submit';
        $submitHover = $authScope . ' .zau-auth-submit:hover, ' . $authScope . ' .zau-auth-submit:focus';
        $links = $authScope . ' .zau-link-button, ' . $authScope . ' .zau-password-reset-link, ' . $authScope . ' .zau-auth-back';

        $this->start_controls_section('zau_style_auth', ['label' => 'Экран входа и регистрации (для гостей)', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_auth_note', ['type'=>\Elementor\Controls_Manager::RAW_HTML,'raw'=>'Применяется только к экрану для неавторизованных — вход, регистрация, выбор способа входа. Кнопки внутри самого кабинета настраиваются в разделах «Основные кнопки» и «Дополнительные кнопки и ссылки» выше.','content_classes'=>'elementor-descriptor']);
        $this->add_control('zau_flow_tab_heading', ['label'=>'Карточки «Войти / Зарегистрироваться»','type'=>\Elementor\Controls_Manager::HEADING]);
        $this->add_control('zau_flow_tab_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$flowTabs=>'background:{{VALUE}};']]);
        $this->add_control('zau_flow_tab_active_bg', ['label'=>'Фон выбранной','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$flowTabs.'.is-active'=>'background:{{VALUE}};']]);
        $this->add_control('zau_flow_tab_border', ['label'=>'Граница','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$flowTabs=>'border-color:{{VALUE}};']]);
        $this->add_control('zau_flow_tab_text', ['label'=>'Текст','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$flowTabs=>'color:{{VALUE}};']]);
        $this->add_control('zau_flow_tab_icon_bg', ['label'=>'Фон значка','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$authScope.' .zau-auth-flow-icon, '.$authScope.' .zau-auth-flow-number'=>'background:{{VALUE}};']]);
        $this->add_responsive_control('zau_flow_tab_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$flowTabs=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_control('zau_submit_heading', ['label'=>'Кнопка отправки (Войти / Получить код / Зарегистрироваться)','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->start_controls_tabs('zau_submit_tabs');
        $this->start_controls_tab('zau_submit_normal', ['label' => 'Обычная']);
        $this->add_control('zau_submit_text', ['label'=>'Текст','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$submit=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_submit_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$submit=>'background-color:{{VALUE}}!important;']]);
        $this->add_control('zau_submit_border', ['label'=>'Граница','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$submit=>'border-color:{{VALUE}}; border-style:solid;']]);
        $this->end_controls_tab();
        $this->start_controls_tab('zau_submit_hover', ['label' => 'Наведение']);
        $this->add_control('zau_submit_hover_text', ['label'=>'Текст','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$submitHover=>'color:{{VALUE}}!important;']]);
        $this->add_control('zau_submit_hover_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$submitHover=>'background-color:{{VALUE}};']]);
        $this->end_controls_tab();
        $this->end_controls_tabs();
        $this->add_group_control(\Elementor\Group_Control_Typography::get_type(), ['name'=>'zau_submit_typography','selector'=>$submit]);
        $this->add_responsive_control('zau_submit_radius', ['label'=>'Скругление кнопки','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$submit=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_control('zau_link_heading', ['label'=>'Ссылки («Не помню PIN», «Восстановить пароль», назад)','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_auth_link_color', ['label'=>'Цвет ссылок','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$links=>'color:{{VALUE}};']]);
        $this->add_control('zau_auth_input_border', ['label'=>'Граница полей ввода','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$authScope.' input, '.$authScope.' select'=>'border-color:{{VALUE}};']]);
        $this->end_controls_section();
    }

    /** Карточка документа во вкладке «Мои документы» — раньше делила общий стиль
     * «Карточки и разделы» с шестью другими, не связанными между собой блоками. */
    protected function register_document_card_style_controls($scope) {
        $card = $scope . ' .zau-doc-card';
        $this->start_controls_section('zau_style_doc_card', ['label' => 'Карточки документов', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_doc_card_bg', ['label'=>'Фон','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_doc_card_border','selector'=>$card]);
        $this->add_responsive_control('zau_doc_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$card=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_responsive_control('zau_doc_card_padding', ['label'=>'Отступы','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','em'],'selectors'=>[$card=>'padding:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'zau_doc_card_shadow','selector'=>$card]);
        $this->add_responsive_control('zau_doc_grid_gap', ['label'=>'Расстояние между карточками','type'=>\Elementor\Controls_Manager::SLIDER,'size_units'=>['px','em'],'range'=>['px'=>['min'=>0,'max'=>60],'em'=>['min'=>0,'max'=>5,'step'=>0.1]],'selectors'=>[$scope.' .zau-doc-grid'=>'gap:{{SIZE}}{{UNIT}};']]);
        $this->add_responsive_control('zau_doc_grid_columns', ['label'=>'Колонок','type'=>\Elementor\Controls_Manager::SELECT,'options'=>['1'=>'1','2'=>'2','3'=>'3','4'=>'4'],'selectors'=>[$scope.' .zau-doc-grid'=>'grid-template-columns:repeat({{VALUE}},minmax(0,1fr));']]);
        $this->add_control('zau_doc_title_color', ['label'=>'Цвет названия','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.'>strong'=>'color:{{VALUE}};']]);
        $this->add_control('zau_doc_dt_color', ['label'=>'Цвет подписей (номер, дата)','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' dt'=>'color:{{VALUE}};']]);
        $this->end_controls_section();
    }

    /** Карточка акции/скидки во вкладке «Акции и скидки» — отдельно от общих карточек,
     * плюс всплывающее окно с полными условиями акции. */
    protected function register_benefit_card_style_controls($scope) {
        $card = $scope . ' .zau-benefit-card';
        $modal = $scope . ' .zau-benefit-modal-body';
        $this->start_controls_section('zau_style_benefit_card', ['label' => 'Карточки акций и скидок', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_benefit_card_bg', ['label'=>'Фон карточки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_benefit_card_border','selector'=>$card]);
        $this->add_responsive_control('zau_benefit_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$card=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'zau_benefit_card_shadow','selector'=>$card]);
        $this->add_control('zau_benefit_badge_bg', ['label'=>'Фон значка скидки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-badge'=>'background:{{VALUE}};']]);
        $this->add_control('zau_benefit_badge_text', ['label'=>'Текст значка скидки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-badge'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_title_color', ['label'=>'Цвет названия','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' h4'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_partner_color', ['label'=>'Цвет партнёра','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-partner'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_teaser_color', ['label'=>'Цвет краткого анонса','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-teaser'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_more_color', ['label'=>'Цвет «Подробнее»','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$scope.' .zau-benefit-more'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_modal_heading', ['label'=>'Всплывающее окно с подробностями','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_benefit_description_color', ['label'=>'Цвет описания','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$modal.' .zau-benefit-description'=>'color:{{VALUE}};']]);
        $this->add_control('zau_benefit_promo_bg', ['label'=>'Фон промокода','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$modal.' .zau-benefit-promo'=>'background:{{VALUE}};']]);
        $this->add_control('zau_benefit_promo_text', ['label'=>'Текст промокода','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$modal.' .zau-benefit-promo'=>'color:{{VALUE}};']]);
        $this->end_controls_section();
    }

    /** Личная карточка участника — контейнер, поля ввода и плитки статистики отдельно
     * от общих стилей форм/карточек, так как раньше делили их с остальным кабинетом. */
    protected function register_member_card_style_controls($scope) {
        $card = $scope . ' .zau-member-card';
        $fields = $card . ' .zau-member-card-field input, ' . $card . ' .zau-member-card-field textarea, ' . $card . ' .zau-member-card-field select';
        $this->start_controls_section('zau_style_member_card', ['label' => 'Личная карточка', 'tab' => \Elementor\Controls_Manager::TAB_STYLE]);
        $this->add_control('zau_member_card_bg', ['label'=>'Фон карточки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card=>'background:{{VALUE}};']]);
        $this->add_group_control(\Elementor\Group_Control_Border::get_type(), ['name'=>'zau_member_card_border','selector'=>$card]);
        $this->add_responsive_control('zau_member_card_radius', ['label'=>'Скругление','type'=>\Elementor\Controls_Manager::DIMENSIONS,'size_units'=>['px','%'],'selectors'=>[$card=>'border-radius:{{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};']]);
        $this->add_group_control(\Elementor\Group_Control_Box_Shadow::get_type(), ['name'=>'zau_member_card_shadow','selector'=>$card]);
        $this->add_control('zau_member_field_heading', ['label'=>'Поля карточки','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_member_field_label_color', ['label'=>'Цвет подписи поля','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-member-card-field>span'=>'color:{{VALUE}};']]);
        $this->add_control('zau_member_field_bg', ['label'=>'Фон поля','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$fields=>'background-color:{{VALUE}};']]);
        $this->add_control('zau_member_field_border', ['label'=>'Граница поля','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$fields=>'border-color:{{VALUE}};']]);
        $this->add_control('zau_member_field_text', ['label'=>'Текст в поле','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$fields=>'color:{{VALUE}};']]);
        $this->add_control('zau_member_stat_heading', ['label'=>'Плитки сведений (статус, ID, дата)','type'=>\Elementor\Controls_Manager::HEADING,'separator'=>'before']);
        $this->add_control('zau_member_stat_bg', ['label'=>'Фон плитки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-member-card-system>div'=>'background:{{VALUE}};']]);
        $this->add_control('zau_member_stat_border', ['label'=>'Граница плитки','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-member-card-system>div'=>'border-color:{{VALUE}};']]);
        $this->add_control('zau_member_stat_label', ['label'=>'Цвет подписи','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-member-card-system small'=>'color:{{VALUE}};']]);
        $this->add_control('zau_member_stat_value', ['label'=>'Цвет значения','type'=>\Elementor\Controls_Manager::COLOR,'selectors'=>[$card.' .zau-member-card-system strong'=>'color:{{VALUE}};']]);
        $this->end_controls_section();
    }

    /** Список стандартных и собственных вкладок кабинета — общий для виджета «Личный кабинет / раздел»
     * и виджета «Личный кабинет с входом», чтобы оба предлагали одинаковый набор вкладок. */
    protected function get_cabinet_tabs_options() {
        $builtInTabs = ['home'=>'Главная','documents'=>'Документы','submissions'=>'Заявления','card'=>'Личная карточка','benefits'=>'Акции и скидки','members'=>'Участники','registry'=>'Реестр организации','info'=>'Материалы','logins'=>'История входов'];
        $customTabs = [];
        foreach (get_posts(['post_type'=>'zau_union_tab','post_status'=>'publish','numberposts'=>-1,'orderby'=>['menu_order'=>'ASC','date'=>'ASC']]) as $tabPost) {
            if (get_post_meta($tabPost->ID, '_zau_tab_enabled', true) === '0') { continue; }
            $slug = sanitize_title((string)get_post_meta($tabPost->ID, '_zau_tab_slug', true));
            if (!$slug) { $slug = 'custom-' . $tabPost->ID; }
            $customTabs[$slug] = 'Своя вкладка: ' . $tabPost->post_title;
        }
        return [$builtInTabs, $customTabs, $builtInTabs + $customTabs];
    }

    /** Контролы полного личного кабинета. $condition позволяет одному и тому же набору полей
     * показываться либо всегда (новый виджет «Кабинет или вход»), либо только при выборе
     * соответствующего варианта в другом контроле (существующий виджет «Личный кабинет / раздел»). */
    protected function register_full_cabinet_controls($condition = []) {
        [$builtInTabs, , $allTabs] = $this->get_cabinet_tabs_options();
        $withCondition = function($args) use ($condition) { return $condition ? ($args + ['condition'=>$condition]) : $args; };
        $this->add_control('cabinet_default_tab', $withCondition(['label'=>'Открывать вкладку','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'home','options'=>$allTabs]));
        $this->add_control('cabinet_tabs', $withCondition(['label'=>'Стандартные вкладки','type'=>\Elementor\Controls_Manager::SELECT2,'multiple'=>true,'default'=>array_keys($builtInTabs),'options'=>$builtInTabs,'description'=>'Для обычного участника вкладки ответственного автоматически скрываются. Собственные вкладки управляются отдельным переключателем ниже.']));
        $this->add_control('show_custom_tabs', $withCondition(['label'=>'Показывать собственные вкладки','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','description'=>'Создание и доступ: «Профсоюз → Вкладки кабинета».']));
        $this->add_control('show_quick_actions', $withCondition(['label'=>'Быстрые действия на главной','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']));
        $this->add_control('show_document_search', $withCondition(['label'=>'Поиск и фильтр документов','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']));
        $this->add_control('show_member_card', $withCondition(['label'=>'Показывать личную карточку','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes','description'=>'Скрывает вкладку, быстрый переход и содержимое личной карточки в этом экземпляре кабинета.']));
        $this->add_control('mobile_bottom_nav', $withCondition(['label'=>'Закреплённые вкладки на телефоне','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']));
    }

    /** Строит шорткод полного личного кабинета из тех же настроек, что задаёт register_full_cabinet_controls(). */
    protected function build_full_cabinet_shortcode($s) {
        $defaultTab = sanitize_key($s['cabinet_default_tab'] ?? 'home');
        $tabs = is_array($s['cabinet_tabs'] ?? null) ? array_values(array_filter(array_map('sanitize_key', $s['cabinet_tabs']))) : ['home','documents','submissions','members','registry','info','logins'];
        $showCustom = (($s['show_custom_tabs'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        $quick = (($s['show_quick_actions'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        $docSearch = (($s['show_document_search'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        $memberCard = (($s['show_member_card'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        if ($memberCard === 'no') { $tabs = array_values(array_diff($tabs, ['card'])); }
        $bottomNav = (($s['mobile_bottom_nav'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        return '[zau_union_cabinet default_tab="'.esc_attr($defaultTab).'" tabs="'.esc_attr(implode(',',$tabs)).'" custom_tabs="'.$showCustom.'" quick_actions="'.$quick.'" document_search="'.$docSearch.'" member_card="'.$memberCard.'" mobile_bottom_nav="'.$bottomNav.'"' . $this->auth_shortcode_attributes($s) . ']';
    }

    /** Контролы экрана «вход/регистрация» для гостя — те же поля, что у отдельного виджета «Вход + регистрация». */
    protected function register_portal_content_controls() {
        $this->add_control('form_id', ['label'=>'Форма после входа','type'=>\Elementor\Controls_Manager::SELECT,'options'=>$this->get_form_options(),'default'=>0]);
        $this->add_control('pin_setup', ['label'=>'Создание постоянного PIN','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'inherit','options'=>['inherit'=>'Как в общих настройках','off'=>'Не показывать','optional'=>'Предлагать','required'=>'Требовать']]);
        $this->add_control('default_flow', ['label'=>'Что показывать первым','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'choice','options'=>['choice'=>'Сначала выбор «Войти / Зарегистрироваться»','register'=>'Сразу регистрация нового участника','login'=>'Сразу вход существующего участника']]);
        $this->add_control('show_start_choice', ['label'=>'Сначала показывать выбор «Войти / Зарегистрироваться»','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('show_flow_tabs', ['label'=>'Показывать выбор «Регистрация / Вход»','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
        $this->add_control('login_return_url', ['label'=>'После входа перейти','type'=>\Elementor\Controls_Manager::URL,'placeholder'=>home_url('/lk-profsoyuz/'),'description'=>'Переход только для существующего участника. После подтверждения новой регистрации откроется анкета на этой странице.']);
        $this->add_control('mobile_steps', ['label'=>'Пошаговая форма AQNIET','type'=>\Elementor\Controls_Manager::SWITCHER,'return_value'=>'yes','default'=>'yes']);
    }

    /** Строит шорткод экрана «вход/регистрация» из тех же настроек, что задаёт register_portal_content_controls(). */
    protected function build_guest_portal_shortcode($s) {
        $id = absint($s['form_id'] ?? 0);
        $pin = in_array(($s['pin_setup'] ?? 'inherit'), ['inherit','off','optional','required'], true) ? $s['pin_setup'] : 'inherit';
        $flow = in_array(($s['default_flow'] ?? 'choice'), ['choice','register','login'], true) ? $s['default_flow'] : 'choice';
        $showFlow = (($s['show_flow_tabs'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        $loginUrl = !empty($s['login_return_url']['url']) ? esc_url_raw($s['login_return_url']['url']) : '';
        $mobileSteps = (($s['mobile_steps'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        $startScreen = (($s['show_start_choice'] ?? 'yes') === 'yes') ? 'yes' : 'no';
        if ($flow === 'choice') { $startScreen = 'yes'; }
        $fallbackFlow = $flow === 'choice' ? 'register' : $flow;
        return '[zau_union_portal' . ($id ? ' id="'.$id.'"' : '') . ' pin_setup="'.esc_attr($pin).'" mobile_steps="'.$mobileSteps.'" default_flow="'.esc_attr($fallbackFlow).'" start_screen="'.$startScreen.'" show_flow_tabs="'.$showFlow.'"' . ($loginUrl ? ' login_return_url="'.esc_attr($loginUrl).'"' : '') . $this->auth_shortcode_attributes($s) . ']';
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
        $this->register_portal_content_controls();
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $this->render_shortcode_in_wrapper($this->build_guest_portal_shortcode($s), $this->wrapper_classes_from_settings($s));
    }
}

class ZAU_Union_Cabinet_Section_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-cabinet-section'; }
    public function get_title() { return 'ZAU — личный кабинет / раздел'; }
    public function get_icon() { return 'eicon-dashboard'; }
    protected function register_controls() {
        [, $customTabs, ] = $this->get_cabinet_tabs_options();
        $sectionOptions=['full'=>'Личный кабинет целиком','profile'=>'Шапка профиля и статус','navigation'=>'Навигация','stats'=>'Статистика','documents'=>'Мои документы','submissions'=>'Мои заявления','card'=>'Личная карточка','benefits'=>'Акции и скидки','members'=>'Участники организации','registry'=>'Реестр организации','info'=>'Материалы','logins'=>'История входов','logout'=>'Кнопка выхода'];
        foreach($customTabs as $slug=>$label)$sectionOptions[$slug]=$label;
        $this->start_controls_section('content_section', ['label'=>'Содержимое']);
        $this->add_control('section_type', [
            'label'=>'Что вывести','type'=>\Elementor\Controls_Manager::SELECT,'default'=>'full',
            'options'=>$sectionOptions,
        ]);
        $this->register_full_cabinet_controls(['section_type'=>'full']);
        $this->add_control('custom_title', ['label'=>'Свой заголовок','type'=>\Elementor\Controls_Manager::TEXT,'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('custom_subtitle', ['label'=>'Своё описание','type'=>\Elementor\Controls_Manager::TEXTAREA,'rows'=>3,'condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('show_heading', ['label'=>'Показывать заголовок раздела','type'=>\Elementor\Controls_Manager::SWITCHER,'label_on'=>'Да','label_off'=>'Нет','return_value'=>'yes','default'=>'yes','condition'=>['section_type'=>['documents','submissions','card','benefits','members','info','logins']]]);
        $this->add_control('nav_items', ['label'=>'Пункты навигации','type'=>\Elementor\Controls_Manager::TEXT,'default'=>'home,documents,submissions,card,benefits,members,registry,info,logins','description'=>'Допустимо: home, documents, submissions, card, benefits, members, registry, info, logins.','condition'=>['section_type'=>'navigation']]);
        $this->end_controls_section();
        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $auth = $this->auth_shortcode_attributes($s);
        $type = sanitize_key($s['section_type'] ?? 'full');
        if ($type === 'full') {
            $shortcode = $this->build_full_cabinet_shortcode($s);
        }
        elseif ($type === 'registry') { $shortcode = '[zau_union_org_registry' . $auth . ']'; }
        elseif (get_page_by_path($type,OBJECT,'zau_union_tab') || get_posts(['post_type'=>'zau_union_tab','post_status'=>'publish','numberposts'=>1,'meta_key'=>'_zau_tab_slug','meta_value'=>$type])) { $shortcode = '[zau_union_custom_tab slug="'.esc_attr($type).'"' . $auth . ']'; }
        else {
            $shortcode = '[zau_union_cabinet_section section="'.esc_attr($type).'" title="'.esc_attr(sanitize_text_field($s['custom_title'] ?? '')).'" subtitle="'.esc_attr(sanitize_text_field($s['custom_subtitle'] ?? '')).'" show_heading="'.((($s['show_heading'] ?? 'yes')==='yes')?'yes':'no').'" nav_items="'.esc_attr(sanitize_text_field($s['nav_items'] ?? '')).'"' . $auth . ']';
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

/**
 * Один виджет для главной страницы: авторизованному участнику показывает личный кабинет,
 * гостю — экран «Войти / Зарегистрироваться». Переиспользует те же контролы и тот же
 * генератор шорткодов, что и отдельные виджеты «Личный кабинет / раздел» и «Вход + регистрация»,
 * поэтому ведёт себя идентично им и не меняет их поведение.
 */
class ZAU_Union_Elementor_Cabinet_Or_Auth_Widget extends ZAU_Union_Elementor_Widget_Base {
    public function get_name() { return 'zau-union-cabinet-or-auth'; }
    public function get_title() { return 'ZAU — Личный кабинет / Вход'; }
    public function get_icon() { return 'eicon-single-page'; }
    protected function register_controls() {
        $this->start_controls_section('content_member', ['label'=>'Если участник вошёл']);
        $this->register_full_cabinet_controls();
        $this->end_controls_section();

        $this->start_controls_section('content_guest', ['label'=>'Если гость (не вошёл)']);
        $this->register_portal_content_controls();
        $this->end_controls_section();

        $this->start_controls_section('content_editor_preview', ['label'=>'Предпросмотр в редакторе']);
        $this->add_control('preview_state', [
            'label'=>'Что показать в редакторе Elementor',
            'type'=>\Elementor\Controls_Manager::SELECT,
            'default'=>'auto',
            'options'=>['auto'=>'Как у реального посетителя (авто)','member'=>'Как для вошедшего участника','guest'=>'Как для гостя'],
            'description'=>'Действует только внутри редактора/предпросмотра Elementor — помогает увидеть оба состояния, не выходя из своего аккаунта. На опубликованной странице всегда используется реальный статус входа посетителя.',
        ]);
        $this->end_controls_section();

        $this->register_auth_method_controls();
        $this->register_common_content_controls();
        $this->register_style_controls();
    }
    protected function render() {
        $s = $this->get_settings_for_display();
        $preview = sanitize_key($s['preview_state'] ?? 'auto');
        $inEditor = class_exists('\\Elementor\\Plugin') && \Elementor\Plugin::$instance
            && ((\Elementor\Plugin::$instance->editor && \Elementor\Plugin::$instance->editor->is_edit_mode())
                || (\Elementor\Plugin::$instance->preview && \Elementor\Plugin::$instance->preview->is_preview_mode()));
        $showMember = is_user_logged_in();
        if ($inEditor && $preview !== 'auto') { $showMember = ($preview === 'member'); }
        $shortcode = $showMember ? $this->build_full_cabinet_shortcode($s) : $this->build_guest_portal_shortcode($s);
        $extraClass = 'zau-e-cabinet-or-auth ' . ($showMember ? 'zau-e-state-member' : 'zau-e-state-guest');
        $this->render_shortcode_in_wrapper($shortcode, $this->wrapper_classes_from_settings($s) . ' ' . $extraClass);
    }
}
