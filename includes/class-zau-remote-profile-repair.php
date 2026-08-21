<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Repairs profiles created by the remote legacy migration without creating
 * duplicate WordPress users. It restores status, organization, branch and
 * member-card fields from the persistent migration staging archive and, when
 * staging is unavailable, from already imported legacy submissions.
 */
final class ZAU_Remote_Profile_Repair {
    const VERSION = '2.18.2';
    const DB_VERSION = '1.0.0';
    const OPT_DB_VERSION = 'zau_remote_profile_repair_db_version';
    const JOB_OPT = 'zau_remote_profile_repair_job';
    const SETTINGS_OPT = 'zau_remote_profile_repair_settings';
    const NONCE = 'zau_remote_profile_repair_nonce';
    const LOCK_OPT = 'zau_remote_profile_repair_lock';
    const LOCK_TTL = 180;

    private static $instance = null;
    private $people_table;
    private $stage_table;
    private $submissions_table;
    private $orgs_table;
    private $branches_table;
    private $log_table;
    private $org_cache = null;
    private $branch_cache = null;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->people_table = $wpdb->prefix . 'zau_remote_legacy_people';
        $this->stage_table = $wpdb->prefix . 'zau_remote_legacy_records';
        $this->submissions_table = $wpdb->prefix . 'zau_union_submissions';
        $this->orgs_table = $wpdb->prefix . 'zau_union_organizations';
        $this->branches_table = $wpdb->prefix . 'zau_union_branches';
        $this->log_table = $wpdb->prefix . 'zau_remote_profile_repair_log';

        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 38);
        add_action('admin_menu', [$this, 'admin_menu'], 40);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
        add_action('admin_post_zau_remote_profile_repair_save', [$this, 'save_settings']);
        add_action('admin_post_zau_remote_profile_repair_report', [$this, 'download_report']);
        add_action('wp_ajax_zau_remote_profile_repair_start', [$this, 'ajax_start']);
        add_action('wp_ajax_zau_remote_profile_repair_process', [$this, 'ajax_process']);
        add_action('wp_ajax_zau_remote_profile_repair_status', [$this, 'ajax_status']);
        add_action('wp_ajax_zau_remote_profile_repair_reset', [$this, 'ajax_reset']);
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    private function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->log_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_token varchar(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            display_name varchar(255) NULL,
            result varchar(30) NOT NULL DEFAULT 'pending',
            status_action varchar(100) NULL,
            organization_source text NULL,
            organization_action text NULL,
            branch_source text NULL,
            branch_action text NULL,
            profile_fields text NULL,
            message text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_user (job_token,user_id),
            KEY job_result (job_token,result),
            KEY user_id (user_id)
        ) $charset;");
    }

    private function can_manage() {
        return current_user_can('manage_options') || (class_exists('ZAU_Certificate_PDF_Generator') && current_user_can(ZAU_Certificate_PDF_Generator::CAP_MANAGE));
    }

    private function require_access() {
        if (!$this->can_manage()) { wp_send_json_error(['message'=>'Недостаточно прав.'],403); }
        check_ajax_referer(self::NONCE,'nonce');
    }

    private function settings() {
        $remote = wp_parse_args((array)get_option('zau_remote_bridge_settings',[]), ['default_status'=>'Состоит в профсоюзе']);
        return wp_parse_args((array)get_option(self::SETTINGS_OPT,[]), [
            'batch_size'=>50,
            'default_status'=>(string)$remote['default_status'],
            'create_name_only_orgs'=>1,
            'overwrite_existing'=>0,
            'repair_member_card'=>1,
        ]);
    }

    public function admin_menu() {
        add_submenu_page(
            'zau-certificates',
            'Исправить перенесённые профили',
            'Исправить профили',
            ZAU_Certificate_PDF_Generator::CAP_MANAGE,
            'zau-remote-profile-repair',
            [$this,'page']
        );
    }

    public function admin_assets($hook) {
        if (strpos((string)$hook,'zau-remote-profile-repair') === false) { return; }
        wp_enqueue_style('zau-remote-profile-repair', plugins_url('../assets/css/remote-profile-repair.css',__FILE__), [], self::VERSION);
        wp_enqueue_script('zau-remote-profile-repair', plugins_url('../assets/js/remote-profile-repair.js',__FILE__), ['jquery'], self::VERSION, true);
        wp_localize_script('zau-remote-profile-repair','ZAURemoteProfileRepair',[
            'ajaxUrl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
        ]);
    }

    public function save_settings() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.',403); }
        check_admin_referer(self::NONCE);
        $statuses = ['Состоит в профсоюзе','Заявление подано','На рассмотрении','Регистрация не завершена'];
        $status = sanitize_text_field(wp_unslash($_POST['default_status'] ?? 'Состоит в профсоюзе'));
        if (!in_array($status,$statuses,true)) { $status='Состоит в профсоюзе'; }
        update_option(self::SETTINGS_OPT,[
            'batch_size'=>max(10,min(200,absint($_POST['batch_size'] ?? 50))),
            'default_status'=>$status,
            'create_name_only_orgs'=>!empty($_POST['create_name_only_orgs']) ? 1 : 0,
            'overwrite_existing'=>!empty($_POST['overwrite_existing']) ? 1 : 0,
            'repair_member_card'=>!empty($_POST['repair_member_card']) ? 1 : 0,
        ],false);
        wp_safe_redirect(add_query_arg(['page'=>'zau-remote-profile-repair','saved'=>'1'],admin_url('admin.php')));
        exit;
    }

    private function table_exists($table) {
        global $wpdb;
        return (string)$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table)) === (string)$table;
    }

    private function imported_user_total() {
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(DISTINCT user_id) FROM {$wpdb->usermeta} WHERE meta_key='zau_imported_from_legacy_site' AND meta_value='1'");
    }

    private function imported_user_ids_after($cursor,$limit) {
        global $wpdb;
        return array_map('intval',(array)$wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT user_id FROM {$wpdb->usermeta} WHERE meta_key='zau_imported_from_legacy_site' AND meta_value='1' AND user_id>%d ORDER BY user_id ASC LIMIT %d",
            (int)$cursor,max(1,min(200,(int)$limit))
        )));
    }

    private function normalize_label($value) {
        $value = wp_strip_all_tags((string)$value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value,'UTF-8') : strtolower($value);
        $value = str_replace(['ё','–','—','_','-','/','\\','.',',','(',')','[',']','{','}',':',';','"','\''],['е',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' '],$value);
        $value = preg_replace('/[^\p{L}\p{N}@+]+/u',' ',$value);
        return trim(preg_replace('/\s+/u',' ',(string)$value));
    }

    private function scalar_value($value) {
        if (is_string($value)) {
            $maybe = maybe_unserialize($value);
            if ($maybe !== $value) { $value = $maybe; }
            elseif (($value !== '') && ($value[0] === '{' || $value[0] === '[')) {
                $decoded = json_decode($value,true);
                if (json_last_error() === JSON_ERROR_NONE) { $value=$decoded; }
            }
        }
        if (is_array($value)) {
            $flat=[];
            array_walk_recursive($value,function($v) use (&$flat){ if (is_scalar($v) && trim((string)$v)!=='') { $flat[]=trim((string)$v); } });
            return trim(implode(' ',array_unique($flat)));
        }
        if (is_object($value)) { return $this->scalar_value((array)$value); }
        return trim(wp_strip_all_tags((string)$value));
    }

    private function normalize_iin($value) {
        $digits=preg_replace('/\D+/','',(string)$value);
        return strlen($digits)===12 ? $digits : '';
    }

    private function normalize_bin($value) {
        $digits=preg_replace('/\D+/','',(string)$value);
        return strlen($digits)===12 ? $digits : '';
    }

    private function normalize_phone($value) {
        $digits=preg_replace('/\D+/','',(string)$value);
        if (strlen($digits)===10) { $digits='7'.$digits; }
        if (strlen($digits)===11 && substr($digits,0,1)==='8') { $digits='7'.substr($digits,1); }
        return strlen($digits)>=10 && strlen($digits)<=15 ? $digits : '';
    }

    private function normalize_date($value) {
        $value=trim((string)$value);
        if ($value==='') { return ''; }
        $ts=strtotime($value);
        return $ts ? wp_date('Y-m-d',$ts) : '';
    }

    private function best_field(array $fields,array $exact,array $contains,array $exclude=[]) {
        $best='';$bestScore=-1;
        foreach ($fields as $label=>$raw) {
            $value=$this->scalar_value($raw);
            if ($value==='') { continue; }
            $norm=$this->normalize_label($label);
            $blocked=false;
            foreach ($exclude as $needle) { if ($needle!=='' && strpos($norm,$needle)!==false) { $blocked=true;break; } }
            if ($blocked) { continue; }
            $score=-1;
            foreach ($exact as $needle) {
                if ($norm===$needle) { $score=max($score,120); }
                elseif (strpos($norm,$needle.' ')===0 || substr($norm,-strlen(' '.$needle))===' '.$needle) { $score=max($score,85); }
            }
            foreach ($contains as $needle) {
                if ($norm===$needle) { $score=max($score,100); }
                elseif (strpos($norm,$needle)!==false) { $score=max($score,55); }
            }
            if ($score>$bestScore) { $bestScore=$score;$best=$value; }
        }
        return $bestScore>=0 ? $best : '';
    }

    private function extract_from_fields(array $fields) {
        $out=[];
        $personExclude=['председател','руководител','директор','бухгалтер','глав бух','ответствен','работодател','организац','предприят','филиал','банк','реквизит'];
        $out['iin']=$this->best_field($fields,['иин','иин заявителя','иин работника','индивидуальный идентификационный номер'],['иин участника','иин сотрудника'],['бин','организац','предприят']);
        $out['birth_date']=$this->best_field($fields,['дата рождения','день рождения'],['birth date','birthday'],[]);
        $out['address']=$this->best_field($fields,['адрес проживания','домашний адрес','место проживания','адрес участника'],['адрес заявителя'],['организац','юридическ','филиал']);
        $out['position']=$this->best_field($fields,['должность','занимаемая должность'],['position','job title'],['руководител','председател']);
        $out['department']=$this->best_field($fields,['подразделение','отделение','отдел','структурное подразделение'],['department','division'],[]);
        $out['phone']=$this->best_field($fields,['телефон','номер телефона','мобильный телефон','ваш телефон'],['телефон заявителя','телефон работника','mobile','phone'],['председател','руководител','директор','бухгалтер','организац','филиал']);
        $out['organization']=$this->best_field($fields,
            ['организация','место работы','наименование организации','наименование предприятия','предприятие','учреждение','работодатель','медицинская организация','полное наименование организации'],
            ['место работы','организация работодателя','наименование предприятия','название организации','company','workplace'],
            ['филиал профсоюза','профсоюзный филиал','руководитель','председатель','адрес','бин','банк','реквизит']
        );
        $out['organization_bin']=$this->best_field($fields,['бин организации','бин предприятия','бин работодателя','бин учреждения'],['company bin','бин места работы'],['иин']);
        $out['organization_director']=$this->best_field($fields,['руководитель организации','фио руководителя','директор организации'],['руководитель предприятия','директор'],['председатель профсоюза']);
        $out['organization_address']=$this->best_field($fields,['юридический адрес организации','адрес организации','юр адрес','адрес предприятия'],['юридический адрес'],['филиал профсоюза']);
        $out['branch_name']=$this->best_field($fields,
            ['филиал профсоюза','выберите филиал','филиал','область','регион профсоюза','региональный филиал','территориальный филиал','областной филиал'],
            ['филиал aqniet','филиал акниет','регион профсоюза','область профсоюза','union branch'],
            ['место работы','организация','предприятие','адрес']
        );
        // Some old WPForms installations kept only field IDs. In that case a
        // branch can still be recovered from a select value when it clearly
        // names an AQNİET branch/region and is not an address.
        if (empty($out['branch_name'])) {
            foreach ($fields as $label=>$raw) {
                $value=$this->scalar_value($raw);
                $norm=$this->normalize_label($value);
                $labelNorm=$this->normalize_label($label);
                $len=function_exists('mb_strlen')?mb_strlen($norm,'UTF-8'):strlen($norm);
                if ($value==='' || $len>120 || preg_match('/\b(улица|ул|проспект|пр|дом|мкр|квартира|кабинет)\b/u',$norm)) { continue; }
                if (strpos($labelNorm,'адрес')!==false || strpos($labelNorm,'организац')!==false || strpos($labelNorm,'место работы')!==false) { continue; }
                $labelLooksBranch=(strpos($labelNorm,'филиал')!==false || strpos($labelNorm,'област')!==false || strpos($labelNorm,'регион')!==false);
                $generic=(bool)preg_match('/^поле \d+$/u',$labelNorm);
                if (strpos($norm,'филиал')!==false || strpos($norm,'област')!==false || $labelLooksBranch || ($generic && $this->branch_code_from_text($value)!=='')) {
                    $candidate=$this->branch_match($value);
                    if (!empty($candidate['id'])) { $out['branch_name']=$value; break; }
                }
            }
        }
        foreach ($out as $key=>$value) { if (trim((string)$value)==='') { unset($out[$key]); } }
        return $out;
    }

    private function merge_value(&$profile,$key,$value) {
        $value=$this->scalar_value($value);
        if ($value==='') { return; }
        if ($key==='iin') { $value=$this->normalize_iin($value); }
        elseif ($key==='organization_bin') { $value=$this->normalize_bin($value); }
        elseif ($key==='phone') { $value=$this->normalize_phone($value); }
        elseif ($key==='birth_date') { $value=$this->normalize_date($value); }
        if ($value!=='') { $profile[$key]=$value; }
    }

    private function enrich_profile_from_assoc(array &$profile,array $assoc) {
        foreach (['iin','birth_date','address','position','department','phone','organization','organization_bin','organization_director','organization_address','branch_name'] as $key) {
            if (array_key_exists($key,$assoc)) { $this->merge_value($profile,$key,$assoc[$key]); }
        }
        $extracted=$this->extract_from_fields($assoc);
        foreach ($extracted as $key=>$value) { $this->merge_value($profile,$key,$value); }
    }

    private function latest_people_row($userId) {
        global $wpdb;
        if (!$this->table_exists($this->people_table)) { return null; }
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->people_table} WHERE target_user_id=%d ORDER BY id DESC LIMIT 1",(int)$userId),ARRAY_A);
    }

    private function build_profile($userId) {
        global $wpdb;
        $profile=[];
        $person=$this->latest_people_row($userId);
        if ($person) {
            $base=json_decode((string)$person['profile_json'],true);
            if (is_array($base)) { $this->enrich_profile_from_assoc($profile,$base); }
            if ($this->table_exists($this->stage_table)) {
                $rows=$wpdb->get_results($wpdb->prepare(
                    "SELECT canonical_json,payload_json,record_at,record_sort,id FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s ORDER BY COALESCE(record_at,'1000-01-01 00:00:00') ASC,record_sort ASC,id ASC",
                    (string)$person['job_token'],(string)$person['person_key']
                ),ARRAY_A);
                foreach ((array)$rows as $row) {
                    $canonical=json_decode((string)$row['canonical_json'],true);
                    if (is_array($canonical)) { $this->enrich_profile_from_assoc($profile,$canonical); }
                    $payload=json_decode((string)$row['payload_json'],true);
                    if (is_array($payload)) {
                        if (!empty($payload['raw_meta']) && is_array($payload['raw_meta'])) { $this->enrich_profile_from_assoc($profile,$payload['raw_meta']); }
                        if (!empty($payload['fields']) && is_array($payload['fields'])) { $this->enrich_profile_from_assoc($profile,$payload['fields']); }
                    }
                }
            }
        }
        // Also read every staged source row already mapped to this account. This
        // makes a later re-scan with a newer bridge useful without creating a
        // second WordPress user or a duplicate application.
        if ($this->table_exists($this->stage_table)) {
            $mappedRows=$wpdb->get_results($wpdb->prepare(
                "SELECT canonical_json,payload_json,record_at,record_sort,id FROM {$this->stage_table} WHERE target_user_id=%d ORDER BY COALESCE(record_at,'1000-01-01 00:00:00') ASC,record_sort ASC,id ASC",
                (int)$userId
            ),ARRAY_A);
            foreach ((array)$mappedRows as $row) {
                $canonical=json_decode((string)$row['canonical_json'],true);
                if (is_array($canonical)) { $this->enrich_profile_from_assoc($profile,$canonical); }
                $payload=json_decode((string)$row['payload_json'],true);
                if (is_array($payload)) {
                    if (!empty($payload['raw_meta']) && is_array($payload['raw_meta'])) { $this->enrich_profile_from_assoc($profile,$payload['raw_meta']); }
                    if (!empty($payload['fields']) && is_array($payload['fields'])) { $this->enrich_profile_from_assoc($profile,$payload['fields']); }
                }
            }
        }
        if ($this->table_exists($this->submissions_table)) {
            $subs=$wpdb->get_results($wpdb->prepare("SELECT data_json,created_at,id FROM {$this->submissions_table} WHERE user_id=%d ORDER BY created_at ASC,id ASC",(int)$userId),ARRAY_A);
            foreach ((array)$subs as $sub) {
                $data=json_decode((string)$sub['data_json'],true);
                if (!is_array($data)) { continue; }
                $this->enrich_profile_from_assoc($profile,$data);
                if (!empty($data['legacy_fields']) && is_array($data['legacy_fields'])) {
                    $legacy=$data['legacy_fields'];
                    $this->enrich_profile_from_assoc($profile,$legacy);
                    if (!empty($legacy['application_fields']) && is_array($legacy['application_fields'])) { $this->enrich_profile_from_assoc($profile,$legacy['application_fields']); }
                    if (!empty($legacy['user_meta']) && is_array($legacy['user_meta'])) { $this->enrich_profile_from_assoc($profile,$legacy['user_meta']); }
                }
                if (!empty($data['_legacy']) && is_array($data['_legacy'])) {
                    $this->enrich_profile_from_assoc($profile,$data['_legacy']);
                    foreach (['application_fields','user_meta'] as $legacyKey) {
                        if (!empty($data['_legacy'][$legacyKey]) && is_array($data['_legacy'][$legacyKey])) { $this->enrich_profile_from_assoc($profile,$data['_legacy'][$legacyKey]); }
                    }
                }
            }
        }
        return $profile;
    }

    private function core_org_name($name) {
        $name=$this->normalize_label($name);
        $patterns=['тоо','ао','гкп','гккп','кгп','кгу','гу','ргп','ргу','ип','общественное объединение','филиал','на праве хозяйственного ведения','коммунальное государственное предприятие','коммунальное государственное учреждение','государственное учреждение'];
        foreach ($patterns as $pattern) { $name=preg_replace('/(^| )'.preg_quote($pattern,'/').'( |$)/u',' ',$name); }
        return trim(preg_replace('/\s+/u',' ',$name));
    }

    private function token_similarity($a,$b) {
        $a=array_values(array_unique(array_filter(explode(' ',$this->core_org_name($a)),function($v){return (function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v))>2;})));
        $b=array_values(array_unique(array_filter(explode(' ',$this->core_org_name($b)),function($v){return (function_exists('mb_strlen')?mb_strlen($v,'UTF-8'):strlen($v))>2;})));
        if (!$a || !$b) { return 0; }
        $intersection=count(array_intersect($a,$b));
        $union=count(array_unique(array_merge($a,$b)));
        return $union ? $intersection/$union : 0;
    }

    private function organization_rows_cached() {
        global $wpdb;
        if ($this->org_cache === null) {
            $rows=$wpdb->get_results("SELECT id,bin,name,source FROM {$this->orgs_table} ORDER BY id ASC LIMIT 50000");
            $this->org_cache=['rows'=>[],'bin'=>[],'name'=>[]];
            foreach ((array)$rows as $row) {
                $this->org_cache['rows'][]=$row;
                if (!empty($row->bin)) { $this->org_cache['bin'][(string)$row->bin]=$row; }
                $core=$this->core_org_name($row->name);
                if ($core!=='') {
                    if (!isset($this->org_cache['name'][$core])) { $this->org_cache['name'][$core]=[]; }
                    $this->org_cache['name'][$core][]=$row;
                }
            }
        }
        return $this->org_cache;
    }

    private function add_org_cache_row($row) {
        if (!$row) { return; }
        $this->organization_rows_cached();
        $this->org_cache['rows'][]=$row;
        if (!empty($row->bin)) { $this->org_cache['bin'][(string)$row->bin]=$row; }
        $core=$this->core_org_name($row->name);
        if ($core!=='') {
            if (!isset($this->org_cache['name'][$core])) { $this->org_cache['name'][$core]=[]; }
            $this->org_cache['name'][$core][]=$row;
        }
    }

    /**
     * Public entry point for callers outside this class that already know a
     * BIN-based lookup is not trustworthy for a given member (e.g. because
     * several distinct institutions share one BIN) and want this class's
     * name/fuzzy matching and name-only organization creation instead.
     */
    public function resolve_organization_by_name($name, $allowCreate = true, $dryRun = false) {
        return $this->organization_match($name, '', $allowCreate, $dryRun);
    }

    private function organization_match($name,$bin,$allowCreate,$dryRun) {
        global $wpdb;
        $name=trim((string)$name);
        $bin=$this->normalize_bin($bin);
        $cache=$this->organization_rows_cached();
        if ($bin && !empty($cache['bin'][$bin])) {
            $row=$cache['bin'][$bin];
            if ($name==='' || $this->core_org_name($name)===$this->core_org_name($row->name) || $this->token_similarity($name,$row->name)>=0.6) {
                return ['id'=>(int)$row->id,'name'=>(string)$row->name,'bin'=>(string)$row->bin,'action'=>'matched_bin'];
            }
            // Names clearly differ: this BIN is legitimately shared by another
            // institution. Do not misattribute this member to it — fall
            // through to name-based matching/creation below instead.
        }
        $norm=$this->core_org_name($name);
        if ($norm!=='' && !empty($cache['name'][$norm]) && count($cache['name'][$norm])===1) {
            $row=$cache['name'][$norm][0];
            return ['id'=>(int)$row->id,'name'=>(string)$row->name,'bin'=>(string)$row->bin,'action'=>'matched_name'];
        }
        $matches=[];
        if ($name!=='') {
            foreach ((array)$cache['rows'] as $row) {
                $rowNorm=$this->core_org_name($row->name);
                if ($norm!=='' && $norm===$rowNorm) { $matches[]=['row'=>$row,'score'=>1.0]; continue; }
                $score=$this->token_similarity($name,$row->name);
                if ($score>=0.88) { $matches[]=['row'=>$row,'score'=>$score]; }
                elseif ((function_exists('mb_strlen')?mb_strlen($norm,'UTF-8'):strlen($norm))>=10 && (function_exists('mb_strlen')?mb_strlen($rowNorm,'UTF-8'):strlen($rowNorm))>=10 && (strpos($norm,$rowNorm)!==false || strpos($rowNorm,$norm)!==false)) { $matches[]=['row'=>$row,'score'=>0.9]; }
            }
        }
        usort($matches,function($x,$y){ return $y['score']<=>$x['score']; });
        if (count($matches)===1 || (count($matches)>1 && $matches[0]['score']>$matches[1]['score']+0.08)) {
            $row=$matches[0]['row'];
            return ['id'=>(int)$row->id,'name'=>(string)$row->name,'bin'=>(string)$row->bin,'action'=>'matched_name'];
        }
        // A BIN already claimed by a different (name-mismatched) organization
        // cannot be reused for a new row without violating the unique BIN
        // index, so only treat the BIN as usable here if it is still free.
        $binAvailable = $bin!=='' && empty($cache['bin'][$bin]);
        if ($name==='' || (!$binAvailable && !$allowCreate)) {
            return ['id'=>0,'name'=>$name,'bin'=>$bin,'action'=>$matches?'ambiguous':'unresolved'];
        }
        if ($dryRun) { return ['id'=>0,'name'=>$name,'bin'=>$bin,'action'=>$binAvailable?'would_create_bin':'would_create_name']; }
        $now=current_time('mysql');
        if ($binAvailable) {
            $wpdb->insert($this->orgs_table,[
                'bin'=>$bin,'name'=>$name,'director'=>'','address'=>'','region'=>'','source'=>'legacy_profile_repair','updated_at'=>$now
            ]);
        } else {
            $wpdb->query($wpdb->prepare(
                "INSERT INTO {$this->orgs_table} (bin,name,director,address,region,source,updated_at) VALUES (NULL,%s,'','','','legacy_profile_repair_name_only',%s)",
                $name,$now
            ));
        }
        $id=(int)$wpdb->insert_id;
        if (!$id) { return ['id'=>0,'name'=>$name,'bin'=>$bin,'action'=>'create_failed']; }
        $row=$wpdb->get_row($wpdb->prepare("SELECT id,bin,name,source FROM {$this->orgs_table} WHERE id=%d",$id));
        $this->add_org_cache_row($row);
        return ['id'=>$id,'name'=>$name,'bin'=>$binAvailable?$bin:'','action'=>$binAvailable?'created_bin':'created_name'];
    }

    private function branch_code_from_text($value) {
        $v=$this->normalize_label($value);
        $map=[
            'akmola'=>['акмолин','кокшетау'],
            'aktobe'=>['актюбин','актобе'],
            'almaty_city'=>['город алматы','г алматы','алматы город'],
            'atyrau'=>['атырау'],
            'east_kz'=>['восточно казахстан','вко','усть каменогор','оскемен'],
            'west_kz'=>['западно казахстан','зко','уральск','орал'],
            'zhambyl'=>['жамбыл','тараз'],
            'karaganda'=>['караганд'],
            'kyzylorda'=>['кызылордин','кызылорда'],
            'kostanay'=>['костанай','қостанай'],
            'mangystau'=>['мангиста','актау'],
            'pavlodar'=>['павлодар'],
            'north_kz'=>['северо казахстан','ско','петропавловск'],
            'almaty_region'=>['алматинск область','алматинской области','жетісу','жетысу','талдыкорган','талдықорған'],
            'shymkent_turkestan'=>['шымкент','туркестан'],
            'astana'=>['астана'],
            'abay'=>['область абай','области абай','семей'],
        ];
        foreach ($map as $code=>$aliases) {
            foreach ($aliases as $alias) { if (strpos($v,$alias)!==false) { return $code; } }
        }
        if ($v==='алматы') { return 'almaty_city'; }
        return '';
    }

    private function branch_rows_cached() {
        global $wpdb;
        if ($this->branch_cache === null) {
            $this->branch_cache=$wpdb->get_results("SELECT id,name,region FROM {$this->branches_table} WHERE active=1 ORDER BY sort_order ASC,id ASC");
        }
        return (array)$this->branch_cache;
    }

    private function branch_match($value) {
        global $wpdb;
        $value=trim((string)$value);
        if ($value==='') { return ['id'=>0,'name'=>'','action'=>'unresolved']; }
        if (ctype_digit($value)) {
            $row=$wpdb->get_row($wpdb->prepare("SELECT id,name FROM {$this->branches_table} WHERE id=%d AND active=1",(int)$value));
            if ($row) { return ['id'=>(int)$row->id,'name'=>(string)$row->name,'action'=>'matched_id']; }
        }
        $rows=$this->branch_rows_cached();
        $norm=$this->normalize_label($value);
        $direct=[];
        foreach ((array)$rows as $row) {
            $nameNorm=$this->normalize_label($row->name);
            $regionNorm=$this->normalize_label($row->region);
            if ($norm===$nameNorm || ($regionNorm!=='' && $norm===$regionNorm)) { $direct[]=$row; continue; }
            if ((function_exists('mb_strlen')?mb_strlen($norm,'UTF-8'):strlen($norm))>=5 && (strpos($nameNorm,$norm)!==false || strpos($norm,$nameNorm)!==false)) { $direct[]=$row; }
        }
        if (count($direct)===1) { return ['id'=>(int)$direct[0]->id,'name'=>(string)$direct[0]->name,'action'=>'matched_name']; }
        $code=$this->branch_code_from_text($value);
        if ($code!=='') {
            $coded=[];
            foreach ((array)$rows as $row) {
                $rowCode=$this->branch_code_from_text($row->name.' '.$row->region);
                if ($rowCode===$code) { $coded[]=$row; }
            }
            if (count($coded)===1) { return ['id'=>(int)$coded[0]->id,'name'=>(string)$coded[0]->name,'action'=>'matched_region']; }
        }
        return ['id'=>0,'name'=>$value,'action'=>(count($direct)>1?'ambiguous':'unresolved')];
    }

    private function set_meta($userId,$key,$value,$overwrite,$dryRun,&$changed) {
        if ($value==='' || $value===null) { return false; }
        $old=get_user_meta($userId,$key,true);
        if (!$overwrite && $old!=='' && $old!==null) { return false; }
        if ((string)$old===(string)$value) { return false; }
        if (!$dryRun) { update_user_meta($userId,$key,$value); }
        $changed[]=$key;
        return true;
    }

    private function repair_member_card($userId,array $profile,$overwrite,$dryRun,&$changed) {
        $card=json_decode((string)get_user_meta($userId,'zau_member_card_data',true),true);
        if (!is_array($card)) { $card=[]; }
        $cardChanged=false;
        foreach (['iin','birth_date','address','position','department'] as $field) {
            if (empty($profile[$field])) { continue; }
            if (!$overwrite && !empty($card[$field])) { continue; }
            if ((string)($card[$field] ?? '')===(string)$profile[$field]) { continue; }
            $card[$field]=$profile[$field];$cardChanged=true;$changed[]='card.'.$field;
        }
        if ($cardChanged && !$dryRun) {
            update_user_meta($userId,'zau_member_card_data',wp_json_encode($card,JSON_UNESCAPED_UNICODE));
            if (!get_user_meta($userId,'zau_member_card_review_status',true)) { update_user_meta($userId,'zau_member_card_review_status','pending'); }
        }
    }

    private function repair_user($userId,$dryRun,array $settings) {
        global $wpdb;
        $user=get_user_by('id',$userId);
        if (!$user) { return ['result'=>'error','message'=>'Пользователь не найден.']; }
        $profile=$this->build_profile($userId);
        $changed=[];$messages=[];
        $overwrite=!empty($settings['overwrite_existing']);

        $statusOld=(string)get_user_meta($userId,'zau_member_status',true);
        $statusAction='без изменений';
        if ($statusOld==='' || $overwrite) {
            $newStatus=(string)$settings['default_status'];
            if ($statusOld!==$newStatus) {
                if (!$dryRun) { update_user_meta($userId,'zau_member_status',$newStatus); }
                $changed[]='zau_member_status';$statusAction=($dryRun?'будет установлен: ':'установлен: ').$newStatus;
            }
        }

        foreach (['iin'=>'zau_profile_iin','birth_date'=>'zau_profile_birth_date','address'=>'zau_profile_address','position'=>'zau_profile_position','department'=>'zau_profile_department','organization'=>'zau_profile_organization','organization_bin'=>'zau_profile_organization_bin','organization_director'=>'zau_profile_organization_director','organization_address'=>'zau_profile_organization_address','branch_name'=>'zau_profile_branch_name'] as $source=>$metaKey) {
            if (!empty($profile[$source])) { $this->set_meta($userId,$metaKey,$profile[$source],$overwrite,$dryRun,$changed); }
        }
        if (!empty($profile['phone'])) { $this->set_meta($userId,'zau_phone',$profile['phone'],$overwrite,$dryRun,$changed); }
        if (!empty($profile['iin'])) { $this->set_meta($userId,'zau_legacy_iin',$profile['iin'],false,$dryRun,$changed); }

        $orgSource=trim((string)($profile['organization'] ?? ''));
        $orgBin=(string)($profile['organization_bin'] ?? '');
        $orgAction='нет данных';
        $currentOrg=(int)get_user_meta($userId,'zau_organization_id',true);
        if (($currentOrg===0 || $overwrite) && ($orgSource!=='' || $orgBin!=='')) {
            $org=$this->organization_match($orgSource,$orgBin,!empty($settings['create_name_only_orgs']),$dryRun);
            $orgAction=(string)$org['action'];
            if (!empty($org['id'])) {
                $this->set_meta($userId,'zau_organization_id',(int)$org['id'],$overwrite,$dryRun,$changed);
                $this->set_meta($userId,'zau_organization_name',(string)$org['name'],$overwrite,$dryRun,$changed);
                if (!empty($org['bin'])) { $this->set_meta($userId,'zau_organization_bin',(string)$org['bin'],$overwrite,$dryRun,$changed); }
            } elseif (strpos($orgAction,'would_create')===0) {
                $changed[]='zau_organization_id';
            } else {
                if ($orgSource!=='') { $this->set_meta($userId,'zau_organization_name',$orgSource,$overwrite,$dryRun,$changed); }
                if ($orgBin!=='') { $this->set_meta($userId,'zau_organization_bin',$orgBin,$overwrite,$dryRun,$changed); }
                $messages[]='Организация не сопоставлена автоматически.';
            }
        } elseif ($currentOrg>0) { $orgAction='уже назначена'; }

        $branchSource=trim((string)($profile['branch_name'] ?? ''));
        $branchAction='нет данных';
        $currentBranch=(int)get_user_meta($userId,'zau_profile_branch_id',true);
        if (($currentBranch===0 || $overwrite) && $branchSource!=='') {
            $branch=$this->branch_match($branchSource);
            $branchAction=(string)$branch['action'];
            if (!empty($branch['id'])) {
                $this->set_meta($userId,'zau_profile_branch_id',(int)$branch['id'],$overwrite,$dryRun,$changed);
                $this->set_meta($userId,'zau_profile_branch_name',(string)$branch['name'],$overwrite,$dryRun,$changed);
            } else {
                $this->set_meta($userId,'zau_profile_branch_name',$branchSource,$overwrite,$dryRun,$changed);
                $messages[]='Филиал не сопоставлен автоматически.';
            }
        } elseif ($currentBranch>0) { $branchAction='уже назначен'; }

        if (!empty($settings['repair_member_card'])) { $this->repair_member_card($userId,$profile,$overwrite,$dryRun,$changed); }
        if (!$dryRun) {
            update_user_meta($userId,'zau_legacy_profile_repaired_at',current_time('mysql'));
            update_user_meta($userId,'zau_legacy_profile_repair_version',self::VERSION);
        }
        $partial=(strpos($orgAction,'unresolved')!==false || strpos($orgAction,'ambiguous')!==false || strpos($orgAction,'failed')!==false || strpos($branchAction,'unresolved')!==false || strpos($branchAction,'ambiguous')!==false);
        $result=$partial?'partial':($changed?'updated':'unchanged');
        return [
            'result'=>$result,
            'status_action'=>$statusAction,
            'organization_source'=>$orgSource.($orgBin!==''?' · БИН '.$orgBin:''),
            'organization_action'=>$orgAction,
            'branch_source'=>$branchSource,
            'branch_action'=>$branchAction,
            'profile_fields'=>implode(', ',array_unique($changed)),
            'message'=>implode(' ',$messages),
        ];
    }

    private function acquire_lock($jobToken) {
        $now=time();
        $existing=(array)get_option(self::LOCK_OPT,[]);
        if (!empty($existing['expires']) && (int)$existing['expires']>$now && (string)($existing['job_token'] ?? '')===(string)$jobToken) { return false; }
        delete_option(self::LOCK_OPT);
        return add_option(self::LOCK_OPT,['job_token'=>$jobToken,'expires'=>$now+self::LOCK_TTL], '', false);
    }

    private function release_lock($jobToken) {
        $existing=(array)get_option(self::LOCK_OPT,[]);
        if (!$existing || (string)($existing['job_token'] ?? '')===(string)$jobToken || (int)($existing['expires'] ?? 0)<time()) { delete_option(self::LOCK_OPT); }
    }

    public function ajax_start() {
        $this->require_access();
        $settings=$this->settings();
        $dry=!empty($_POST['dry_run']);
        $token='repair-'.wp_generate_uuid4();
        $job=[
            'token'=>$token,
            'status'=>'running',
            'dry_run'=>$dry?1:0,
            'cursor'=>0,
            'total'=>$this->imported_user_total(),
            'processed'=>0,
            'stats'=>['updated'=>0,'unchanged'=>0,'partial'=>0,'error'=>0],
            'log'=>[$dry?'Запущен пробный анализ перенесённых профилей.':'Запущено восстановление перенесённых профилей.'],
            'started_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql'),
        ];
        update_option(self::JOB_OPT,$job,false);
        delete_option(self::LOCK_OPT);
        global $wpdb;
        $wpdb->delete($this->log_table,['job_token'=>$token]);
        wp_send_json_success($job);
    }

    public function ajax_process() {
        $this->require_access();
        $job=(array)get_option(self::JOB_OPT,[]);
        if (empty($job['token'])) { wp_send_json_error(['message'=>'Процесс не найден.'],404); }
        if (($job['status'] ?? '')==='finished') { wp_send_json_success($job); }
        if (!$this->acquire_lock((string)$job['token'])) { wp_send_json_error(['message'=>'Предыдущая партия ещё выполняется.','retry'=>1],409); }
        $settings=$this->settings();
        $ids=$this->imported_user_ids_after((int)$job['cursor'],(int)$settings['batch_size']);
        global $wpdb;
        foreach ($ids as $userId) {
            $job['cursor']=$userId;$job['processed']=(int)$job['processed']+1;
            try { $result=$this->repair_user($userId,!empty($job['dry_run']),$settings); }
            catch (Throwable $e) { $result=['result'=>'error','message'=>$e->getMessage()]; }
            $kind=(string)($result['result'] ?? 'error');
            if (!isset($job['stats'][$kind])) { $job['stats'][$kind]=0; }
            $job['stats'][$kind]++;
            $user=get_user_by('id',$userId);
            $wpdb->replace($this->log_table,[
                'job_token'=>(string)$job['token'],'user_id'=>$userId,'display_name'=>$user?(string)$user->display_name:'',
                'result'=>$kind,'status_action'=>(string)($result['status_action'] ?? ''),
                'organization_source'=>(string)($result['organization_source'] ?? ''),'organization_action'=>(string)($result['organization_action'] ?? ''),
                'branch_source'=>(string)($result['branch_source'] ?? ''),'branch_action'=>(string)($result['branch_action'] ?? ''),
                'profile_fields'=>(string)($result['profile_fields'] ?? ''),'message'=>(string)($result['message'] ?? ''),'created_at'=>current_time('mysql')
            ]);
            if ($kind==='error' || $kind==='partial') {
                $job['log'][]='#'.$userId.' '.($user?$user->display_name:'').' — '.trim((string)($result['message'] ?? $kind));
                if (count($job['log'])>100) { $job['log']=array_slice($job['log'],-100); }
            }
        }
        if (!$ids || count($ids)<(int)$settings['batch_size']) {
            $job['status']='finished';
            $job['finished_at']=current_time('mysql');
            $job['log'][]=!empty($job['dry_run'])?'Пробный анализ завершён. Изменения не записывались.':'Восстановление профилей завершено.';
        }
        $job['updated_at']=current_time('mysql');
        update_option(self::JOB_OPT,$job,false);
        $this->release_lock((string)$job['token']);
        wp_send_json_success($job);
    }

    public function ajax_status() {
        $this->require_access();
        wp_send_json_success((array)get_option(self::JOB_OPT,[]));
    }

    public function ajax_reset() {
        $this->require_access();
        delete_option(self::JOB_OPT);delete_option(self::LOCK_OPT);
        wp_send_json_success(['message'=>'Прогресс восстановления сброшен. Пользователи и данные не удалены.']);
    }

    public function download_report() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.',403); }
        check_admin_referer(self::NONCE);
        $job=(array)get_option(self::JOB_OPT,[]);
        $token=(string)($job['token'] ?? '');
        if ($token==='') { wp_die('Нет отчёта.'); }
        global $wpdb;
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->log_table} WHERE job_token=%s ORDER BY id ASC",$token),ARRAY_A);
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name('zau-profile-repair-'.wp_date('Y-m-d-H-i').'.csv').'"');
        echo "\xEF\xBB\xBF";
        $out=fopen('php://output','w');
        fputcsv($out,['User ID','ФИО','Результат','Статус','Исходная организация','Действие с организацией','Исходный филиал','Действие с филиалом','Заполненные поля','Сообщение'],';');
        foreach ((array)$rows as $row) {
            fputcsv($out,[$row['user_id'],$row['display_name'],$row['result'],$row['status_action'],$row['organization_source'],$row['organization_action'],$row['branch_source'],$row['branch_action'],$row['profile_fields'],$row['message']],';');
        }
        fclose($out);exit;
    }

    public function page() {
        if (!$this->can_manage()) { wp_die('Недостаточно прав.'); }
        $settings=$this->settings();
        $job=(array)get_option(self::JOB_OPT,[]);
        $total=$this->imported_user_total();
        ?>
        <div class="wrap zau-rpr-wrap">
            <h1>Исправление перенесённых профилей</h1>
            <?php if (!empty($_GET['saved'])): ?><div class="notice notice-success is-dismissible"><p>Настройки восстановления сохранены.</p></div><?php endif; ?>
            <div class="notice notice-info inline"><p>На снимке статус уже установлен как «Состоит в профсоюзе». Пустыми остались связи с организацией и филиалом. Этот инструмент повторно читает промежуточный архив переноса и старые заявления, но <strong>не создаёт новые аккаунты</strong>.</p></div>
            <section class="zau-rpr-card">
                <h2>1. Правила восстановления</h2>
                <p>Найдено перенесённых аккаунтов: <strong><?php echo (int)$total; ?></strong>.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="zau_remote_profile_repair_save">
                    <?php wp_nonce_field(self::NONCE); ?>
                    <div class="zau-rpr-grid">
                        <label>Размер партии<input type="number" name="batch_size" min="10" max="200" step="10" value="<?php echo (int)$settings['batch_size']; ?>"></label>
                        <label>Статус, если он пуст<select name="default_status"><?php foreach (['Состоит в профсоюзе','Заявление подано','На рассмотрении','Регистрация не завершена'] as $status): ?><option <?php selected($settings['default_status'],$status); ?>><?php echo esc_html($status); ?></option><?php endforeach; ?></select></label>
                    </div>
                    <p><label><input type="checkbox" name="create_name_only_orgs" value="1" <?php checked(!empty($settings['create_name_only_orgs'])); ?>> Создавать справочную организацию даже когда в старой заявке есть название, но нет БИН. В справочнике она будет отмечена как «БИН не указан».</label></p>
                    <p><label><input type="checkbox" name="repair_member_card" value="1" <?php checked(!empty($settings['repair_member_card'])); ?>> Заполнить пустые поля личной карточки: ИИН, дата рождения, адрес, должность и подразделение.</label></p>
                    <p><label><input type="checkbox" name="overwrite_existing" value="1" <?php checked(!empty($settings['overwrite_existing'])); ?>> Заменять уже заполненные на новом сайте привязки и данные. На первом запуске оставьте выключенным.</label></p>
                    <p><button class="button button-primary" type="submit">Сохранить правила</button></p>
                </form>
            </section>
            <section class="zau-rpr-card">
                <h2>2. Анализ и исправление</h2>
                <p>Сначала выполните пробный анализ. Он покажет, сколько организаций и филиалов удаётся восстановить, но ничего не запишет.</p>
                <p><button class="button button-secondary button-hero" data-zau-rpr-start="dry">Пробный анализ</button> <button class="button button-primary button-hero" data-zau-rpr-start="repair">Исправить профили</button> <button class="button" data-zau-rpr-reset>Сбросить прогресс</button></p>
                <div class="zau-rpr-progress" data-zau-rpr-progress <?php echo $job?'':'hidden'; ?>><span></span></div>
                <p data-zau-rpr-progress-text></p>
                <div class="zau-rpr-stats" data-zau-rpr-stats></div>
                <pre data-zau-rpr-log><?php echo esc_html(implode("\n",(array)($job['log'] ?? []))); ?></pre>
                <p><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=zau_remote_profile_repair_report'),self::NONCE)); ?>">Скачать отчёт восстановления</a></p>
                <p class="description">Неоднозначные филиалы и организации отмечаются как <code>partial</code> и не назначаются случайным образом. Повторный запуск безопасен и заполняет только пустые значения.</p>
            </section>
        </div>
        <?php
    }
}

ZAU_Remote_Profile_Repair::instance();
