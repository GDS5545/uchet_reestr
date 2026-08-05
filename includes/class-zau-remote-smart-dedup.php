<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Persistent staging and safe duplicate resolution for remote legacy migration.
 * No source record is deleted: current, archived, duplicate and review rows stay
 * in the staging/archive table and can be exported after the migration.
 */
final class ZAU_Remote_Smart_Dedup {
    const VERSION = '2.18.2';
    const DB_VERSION = '1.2.0';
    const OPT_DB_VERSION = 'zau_remote_smart_dedup_db_version';

    private static $instance = null;
    private $stage_table;
    private $people_table;
    private $map_table;

    public static function instance() {
        if (self::$instance === null) { self::$instance = new self(); }
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->stage_table = $wpdb->prefix . 'zau_remote_legacy_records';
        $this->people_table = $wpdb->prefix . 'zau_remote_legacy_people';
        $this->map_table = $wpdb->prefix . 'zau_external_import_map';
        add_action('plugins_loaded', [$this, 'maybe_upgrade'], 37);
    }

    public function maybe_upgrade() {
        if (get_option(self::OPT_DB_VERSION) !== self::DB_VERSION) {
            $this->install_tables();
            update_option(self::OPT_DB_VERSION, self::DB_VERSION, false);
        }
    }

    public function install_tables() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$this->stage_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_token varchar(64) NOT NULL,
            source_site_hash varchar(64) NOT NULL,
            source_hash varchar(64) NOT NULL,
            record_type varchar(20) NOT NULL,
            legacy_user_id varchar(100) NULL,
            legacy_entry_id varchar(100) NULL,
            form_id bigint(20) unsigned NOT NULL DEFAULT 0,
            form_title text NULL,
            record_at datetime NULL,
            record_sort bigint(20) unsigned NOT NULL DEFAULT 0,
            person_key varchar(191) NOT NULL,
            identity_strength varchar(24) NOT NULL DEFAULT 'weak',
            identity_rank smallint(5) unsigned NOT NULL DEFAULT 0,
            name_key varchar(191) NULL,
            iin_key varchar(64) NULL,
            email_key varchar(64) NULL,
            phone_key varchar(64) NULL,
            content_hash varchar(64) NOT NULL,
            canonical_json longtext NULL,
            payload_json longtext NULL,
            decision varchar(40) NOT NULL DEFAULT 'staged',
            message text NULL,
            is_current tinyint(1) NOT NULL DEFAULT 0,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_source (job_token,source_hash),
            KEY job_type (job_token,record_type),
            KEY job_person (job_token,person_key),
            KEY job_decision (job_token,decision),
            KEY legacy_user_id (legacy_user_id),
            KEY legacy_entry_id (legacy_entry_id),
            KEY form_id (form_id),
            KEY iin_key (iin_key),
            KEY email_key (email_key),
            KEY phone_key (phone_key),
            KEY content_hash (content_hash)
        ) $charset;");
        dbDelta("CREATE TABLE {$this->people_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            job_token varchar(64) NOT NULL,
            person_key varchar(191) NOT NULL,
            identity_strength varchar(24) NOT NULL DEFAULT 'weak',
            identity_rank smallint(5) unsigned NOT NULL DEFAULT 0,
            name_key varchar(191) NULL,
            record_count bigint(20) unsigned NOT NULL DEFAULT 0,
            application_count bigint(20) unsigned NOT NULL DEFAULT 0,
            latest_at datetime NULL,
            profile_json longtext NULL,
            existing_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            target_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            decision varchar(40) NOT NULL DEFAULT 'pending',
            message text NULL,
            processed tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY job_person (job_token,person_key),
            KEY job_processed (job_token,processed),
            KEY job_decision (job_token,decision),
            KEY target_user_id (target_user_id)
        ) $charset;");
    }

    public function stage_table() { return $this->stage_table; }
    public function people_table() { return $this->people_table; }

    private function lower($value) {
        $value = wp_strip_all_tags((string)$value);
        return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    }

    private function normalize_text($value) {
        $value = $this->lower($value);
        $value = str_replace(['ё','–','—','_','-','/','\\','.',',','(',')','[',']','{','}',':',';','"','\''], ['е',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' ',' '], $value);
        $value = preg_replace('/[^\p{L}\p{N}@+]+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', (string)$value));
    }

    private function normalize_email($value) {
        $email = strtolower(trim((string)$value));
        return is_email($email) ? $email : '';
    }

    private function normalize_phone($value) {
        $digits = preg_replace('/\D+/', '', (string)$value);
        if (strlen($digits) === 10) { $digits = '7' . $digits; }
        if (strlen($digits) === 11 && substr($digits, 0, 1) === '8') { $digits = '7' . substr($digits, 1); }
        return strlen($digits) >= 10 && strlen($digits) <= 15 ? $digits : '';
    }

    private function normalize_iin($value) {
        $digits = preg_replace('/\D+/', '', (string)$value);
        return strlen($digits) === 12 ? $digits : '';
    }

    private function mysql_date($value) {
        $value = trim((string)$value);
        if ($value === '') { return ''; }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) { return $value; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) { return $value . ' 00:00:00'; }
        $ts = strtotime($value);
        return $ts ? gmdate('Y-m-d H:i:s', $ts) : '';
    }

    private function source_identity($sourceSite, array $record) {
        $identity = implode('|', [
            untrailingslashit((string)$sourceSite),
            (string)($record['type'] ?? ''),
            (string)($record['legacy_user_id'] ?? ''),
            (string)($record['legacy_entry_id'] ?? ''),
            (string)($record['form_id'] ?? ''),
        ]);
        if (trim(str_replace('|', '', $identity)) === '') {
            $identity .= '|' . wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return $identity;
    }

    private function legacy_source_hash($sourceSite, array $record) {
        return hash('sha256', 'zau-bridge|' . $this->source_identity($sourceSite,$record));
    }

    private function source_hash($sourceSite, array $record) {
        $version = (string)($record['source_record_hash'] ?? '');
        if ($version === '') {
            $version = hash('sha256', wp_json_encode([
                $record['canonical'] ?? [],$record['fields'] ?? [],$record['raw_meta'] ?? [],
                $record['source_modified_at'] ?? '',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        return hash('sha256', 'zau-bridge-v2|' . $this->source_identity($sourceSite,$record) . '|version:' . $version);
    }

    private function content_hash(array $canonical, array $record) {
        $data = $canonical;
        foreach (['legacy_user_id','legacy_entry_id','submission_date','registration_date','pdf_url','signature_url'] as $key) { unset($data[$key]); }
        if (!empty($record['fields']) && is_array($record['fields'])) { $data['_fields'] = $record['fields']; }
        $this->recursive_sort($data);
        return hash('sha256', wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function recursive_sort(&$value) {
        if (!is_array($value)) { return; }
        foreach ($value as &$item) { $this->recursive_sort($item); }
        unset($item);
        if ($this->is_assoc($value)) { ksort($value); }
    }

    private function is_assoc(array $value) {
        if ($value === []) { return false; }
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function identity_rank($strength) {
        $ranks = [
            'strong-name-email'=>120,
            'legacy-user'=>100,
            'review-missing-email'=>10,
            'review-missing-name'=>10,
            'review'=>10,
            'isolated'=>5,
        ];
        return isset($ranks[$strength]) ? (int)$ranks[$strength] : 0;
    }

    /**
     * Identity rule requested for the legacy migration:
     * one person = normalized full name + normalized e-mail.
     * Phone and IIN are profile data and never merge two people by themselves.
     * When an old WordPress user ID exists but one component is missing, that
     * old ID remains a safe one-to-one anchor so its applications are not lost.
     */
    private function account_person_key($jobToken, array $canonical, $legacyUserId, $weakAction, $recordUnique = '') {
        $email = $this->normalize_email($canonical['email'] ?? '');
        $name = $this->normalize_text($canonical['full_name'] ?? '');
        if ($name && $email) {
            return [
                'key'=>'name-email:' . hash('sha256',$name.'|'.$email),
                'strength'=>'strong-name-email',
                'name'=>$name,
            ];
        }
        if ((int)$legacyUserId > 0) {
            return [
                'key'=>'legacy-user:' . (int)$legacyUserId,
                'strength'=>'legacy-user',
                'name'=>$name,
            ];
        }
        if ($name && !$email) {
            return [
                'key'=>'review-name:' . hash('sha256',$name.'|'.(string)$recordUnique),
                'strength'=>'review-missing-email',
                'name'=>$name,
            ];
        }
        if ($email && !$name) {
            return [
                'key'=>'review-email:' . hash('sha256',$email.'|'.(string)$recordUnique),
                'strength'=>'review-missing-name',
                'name'=>'',
            ];
        }
        $unique = (string)$recordUnique;
        if ($unique === '') {
            $unique = (string)$legacyUserId . '|' . ($canonical['registration_date'] ?? '') . '|' . ($canonical['submission_date'] ?? '');
        }
        return ['key'=>'isolated:' . hash('sha256',$jobToken.'|'.$unique),'strength'=>'isolated','name'=>$name];
    }

    private function account_key_by_legacy_user($jobToken, $legacyUserId) {
        global $wpdb;
        if ((int)$legacyUserId <= 0) { return null; }
        return $wpdb->get_row($wpdb->prepare(
            "SELECT person_key,identity_strength,identity_rank,name_key,canonical_json FROM {$this->stage_table} WHERE job_token=%s AND record_type='account' AND legacy_user_id=%s ORDER BY id ASC LIMIT 1",
            $jobToken, (string)$legacyUserId
        ), ARRAY_A);
    }

    private function prior_target_for_record($sourceSiteHash, $recordType, $legacyUserId, $legacyEntryId, $formId) {
        global $wpdb;
        $rows = [];
        if ($recordType === 'application' && (int)$legacyEntryId > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT source_hash,target_user_id,target_submission_id,updated_at FROM {$this->stage_table}
                 WHERE source_site_hash=%s AND record_type='application' AND legacy_entry_id=%s AND form_id=%d
                 AND target_user_id>0 ORDER BY updated_at DESC,id DESC LIMIT 20",
                $sourceSiteHash,(string)$legacyEntryId,(int)$formId
            ),ARRAY_A);
            if (!$rows) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT source_hash,target_user_id,target_submission_id,updated_at FROM {$this->map_table}
                     WHERE legacy_entry_id=%s AND target_user_id>0 ORDER BY updated_at DESC,id DESC LIMIT 20",
                    (string)$legacyEntryId
                ),ARRAY_A);
            }
        } elseif ($recordType === 'account' && (int)$legacyUserId > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT source_hash,target_user_id,target_submission_id,updated_at FROM {$this->stage_table}
                 WHERE source_site_hash=%s AND record_type='account' AND legacy_user_id=%s
                 AND target_user_id>0 ORDER BY updated_at DESC,id DESC LIMIT 20",
                $sourceSiteHash,(string)$legacyUserId
            ),ARRAY_A);
            if (!$rows) {
                $rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT source_hash,target_user_id,target_submission_id,updated_at FROM {$this->map_table}
                     WHERE legacy_user_id=%s AND legacy_entry_id='' AND target_user_id>0 ORDER BY updated_at DESC,id DESC LIMIT 20",
                    (string)$legacyUserId
                ),ARRAY_A);
            }
        }
        if (!$rows) { return []; }
        $users=[]; $submissions=[];
        foreach ($rows as $row) {
            if (!empty($row['target_user_id'])) { $users[(int)$row['target_user_id']]=1; }
            if (!empty($row['target_submission_id'])) { $submissions[(int)$row['target_submission_id']]=1; }
        }
        if (count($users)>1 || count($submissions)>1) {
            return ['conflict'=>1,'user_ids'=>array_keys($users),'submission_ids'=>array_keys($submissions)];
        }
        $first=reset($rows);
        return [
            'target_user_id'=>count($users)===1 ? (int)array_key_first($users) : 0,
            'target_submission_id'=>count($submissions)===1 ? (int)array_key_first($submissions) : 0,
            'source_hash'=>(string)($first['source_hash'] ?? ''),
        ];
    }

    public function stage_record($jobToken, $sourceSite, array $record, array $options = []) {
        global $wpdb;
        $weakAction = sanitize_key((string)($options['weak_identity_action'] ?? 'review_skip'));
        if (!in_array($weakAction, ['review_skip','merge_name','create_separate'], true)) { $weakAction = 'review_skip'; }
        $canonical = is_array($record['canonical'] ?? null) ? $record['canonical'] : [];
        if (!empty($record['legacy_user_id'])) { $canonical['legacy_user_id'] = (string)$record['legacy_user_id']; }
        if (!empty($record['legacy_entry_id'])) { $canonical['legacy_entry_id'] = (string)$record['legacy_entry_id']; }
        if (!empty($record['password_hash'])) { $canonical['password_hash'] = (string)$record['password_hash']; }
        $recordType = (($record['type'] ?? '') === 'application') ? 'application' : 'account';
        $legacyUserId = (string)($record['legacy_user_id'] ?? ($canonical['legacy_user_id'] ?? ''));
        $legacyEntryId = (string)($record['legacy_entry_id'] ?? ($canonical['legacy_entry_id'] ?? ''));
        $identity = null;
        $identityMessage = '';
        if ($recordType === 'application' && (int)$legacyUserId > 0) {
            $account = $this->account_key_by_legacy_user($jobToken, $legacyUserId);
            if ($account) {
                $identity = ['key'=>$account['person_key'],'strength'=>$account['identity_strength'],'rank'=>(int)$account['identity_rank'],'name'=>$account['name_key']];
                $accountCanonical = json_decode((string)$account['canonical_json'], true);
                if (is_array($accountCanonical)) {
                    $accountIin = $this->normalize_iin($accountCanonical['iin'] ?? '');
                    $appIin = $this->normalize_iin($canonical['iin'] ?? '');
                    if ($accountIin && $appIin && $accountIin !== $appIin) {
                        $identityMessage = 'Конфликт ИИН между аккаунтом и заявлением; связь по старому User ID сохранена, требуется проверка.';
                    }
                }
            }
        }
        if (!$identity) {
            $recordUnique = $recordType === 'application' ? ('entry:' . $legacyEntryId) : ('user:' . $legacyUserId);
            $identity = $this->account_person_key($jobToken, $canonical, $legacyUserId, $weakAction, $recordUnique);
        }

        $iinNorm = $this->normalize_iin($canonical['iin'] ?? '');
        $emailNorm = $this->normalize_email($canonical['email'] ?? '');
        $phoneNorm = $this->normalize_phone($canonical['phone'] ?? '');
        $identityRank = isset($identity['rank']) ? (int)$identity['rank'] : $this->identity_rank((string)$identity['strength']);
        $sourceHash = $this->source_hash($sourceSite, $record);
        $recordAt = $recordType === 'application'
            ? $this->mysql_date($canonical['submission_date'] ?? ($record['submission_date'] ?? ''))
            : $this->mysql_date($canonical['registration_date'] ?? '');
        $sort = 0;
        if ($recordAt) { $sort = (int)strtotime($recordAt); }
        if (!$sort) {
            $numericId = preg_replace('/\D+/', '', $recordType === 'application' ? $legacyEntryId : $legacyUserId);
            $sort = $numericId !== '' ? (int)$numericId : 0;
        }
        $contentHash = $this->content_hash($canonical, $record);
        $siteHash = hash('sha256', untrailingslashit((string)$sourceSite));
        $existingMap = $wpdb->get_row($wpdb->prepare("SELECT id,target_user_id,target_submission_id FROM {$this->map_table} WHERE source_hash=%s LIMIT 1", $sourceHash), ARRAY_A);
        if (!$existingMap) {
            // Compatibility with records imported by bridge 2.16.0, where the
            // source hash did not yet include the record version.
            $legacyHash = $this->legacy_source_hash($sourceSite,$record);
            $existingMap = $wpdb->get_row($wpdb->prepare("SELECT id,target_user_id,target_submission_id FROM {$this->map_table} WHERE source_hash=%s LIMIT 1", $legacyHash), ARRAY_A);
        }
        $already = !empty($existingMap['id']);
        $prior = $already ? [] : $this->prior_target_for_record($siteHash,$recordType,$legacyUserId,$legacyEntryId,(int)($record['form_id'] ?? 0));
        $priorConflict = !empty($prior['conflict']);
        $isRevision = !$already && !$priorConflict && !empty($prior['target_user_id']);
        $decision = $already ? 'already_imported' : ($priorConflict ? 'identity_conflict' : ((strpos((string)$identity['strength'], 'review') === 0) ? 'review_required' : 'staged'));
        if ($already) {
            $message = 'Эта исходная запись уже переносилась ранее.';
        } elseif ($priorConflict) {
            $message = 'Эта исходная запись ранее оказалась привязана к нескольким объектам нового сайта. Требуется ручная проверка.';
        } elseif ($isRevision) {
            $message = 'Обнаружена обновлённая версия уже перенесённой исходной записи. Будет обновлена существующая связь без создания дубля.';
        } else {
            $message = $identityMessage;
        }
        $now = current_time('mysql');
        $data = [
            'job_token'=>sanitize_text_field($jobToken),
            'source_site_hash'=>$siteHash,
            'source_hash'=>$sourceHash,
            'record_type'=>$recordType,
            'legacy_user_id'=>sanitize_text_field($legacyUserId),
            'legacy_entry_id'=>sanitize_text_field($legacyEntryId),
            'form_id'=>absint($record['form_id'] ?? 0),
            'form_title'=>sanitize_text_field($record['form_title'] ?? ''),
            'record_at'=>$recordAt ?: null,
            'record_sort'=>max(0,$sort),
            'person_key'=>substr((string)$identity['key'],0,191),
            'identity_strength'=>sanitize_key((string)$identity['strength']),
            'identity_rank'=>max(0,$identityRank),
            'name_key'=>substr((string)$identity['name'],0,191),
            'iin_key'=>$iinNorm ? hash('sha256',$iinNorm) : '',
            'email_key'=>$emailNorm ? hash('sha256',$emailNorm) : '',
            'phone_key'=>$phoneNorm ? hash('sha256',$phoneNorm) : '',
            'content_hash'=>$contentHash,
            'canonical_json'=>wp_json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json'=>wp_json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'decision'=>$decision,
            'message'=>$message,
            'target_user_id'=>$already ? (int)$existingMap['target_user_id'] : (int)($prior['target_user_id'] ?? 0),
            'target_submission_id'=>$already ? (int)$existingMap['target_submission_id'] : (int)($prior['target_submission_id'] ?? 0),
            'updated_at'=>$now,
        ];
        $existingId = (int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->stage_table} WHERE job_token=%s AND source_hash=%s LIMIT 1", $jobToken, $sourceHash));
        if ($existingId) {
            $wpdb->update($this->stage_table, $data, ['id'=>$existingId]);
            return ['kind'=>'staged_existing','id'=>$existingId,'decision'=>$decision];
        }
        $data['created_at'] = $now;
        $wpdb->insert($this->stage_table, $data);
        if (!$wpdb->insert_id) { return new WP_Error('stage_insert', 'Не удалось сохранить запись анализа: ' . $wpdb->last_error); }
        return ['kind'=>'staged','id'=>(int)$wpdb->insert_id,'decision'=>$decision];
    }

    private function unify_identifier_column($jobToken,$column) {
        global $wpdb;
        if (!in_array($column,['iin_key','email_key','phone_key'],true)) { return 0; }
        $groups = $wpdb->get_results($wpdb->prepare(
            "SELECT {$column} identifier FROM {$this->stage_table} WHERE job_token=%s AND {$column}<>'' GROUP BY {$column} HAVING COUNT(DISTINCT person_key)>1",
            $jobToken
        ),ARRAY_A);
        $changed = 0;
        foreach ((array)$groups as $group) {
            $identifier=(string)$group['identifier'];
            $best=$wpdb->get_row($wpdb->prepare(
                "SELECT person_key,identity_strength,identity_rank,name_key FROM {$this->stage_table} WHERE job_token=%s AND {$column}=%s ORDER BY identity_rank DESC,id ASC LIMIT 1",
                $jobToken,$identifier
            ),ARRAY_A);
            if (!$best) { continue; }
            $affected=$wpdb->query($wpdb->prepare(
                "UPDATE {$this->stage_table} SET person_key=%s,identity_strength=%s,identity_rank=%d,message=CASE WHEN message='' OR message IS NULL THEN %s ELSE message END,updated_at=%s WHERE job_token=%s AND {$column}=%s AND person_key<>%s",
                (string)$best['person_key'],(string)$best['identity_strength'],(int)$best['identity_rank'],
                'Связано с тем же человеком по совпадающему ИИН, email или телефону.',current_time('mysql'),$jobToken,$identifier,(string)$best['person_key']
            ));
            if ($affected) { $changed+=(int)$affected; }
        }
        return $changed;
    }

    private function unify_strong_identifiers($jobToken) {
        // Deliberately disabled in 2.18.2. A shared phone, IIN or e-mail alone
        // must not merge different full names. The composite full-name + e-mail
        // person_key already unifies only exact requested identities.
        return 0;
    }

    public function prepare_people($jobToken) {
        global $wpdb;
        $now = current_time('mysql');
        $this->unify_strong_identifiers($jobToken);

        // In the safe default mode, a record that has only a full name is
        // never attached automatically, even when there appears to be one matching
        // account. Names are not unique identifiers and must be reviewed manually.

        $wpdb->query($wpdb->prepare("DELETE FROM {$this->people_table} WHERE job_token=%s", $jobToken));
        $sql = $wpdb->prepare(
            "INSERT INTO {$this->people_table}
            (job_token,person_key,identity_strength,identity_rank,name_key,record_count,application_count,latest_at,profile_json,decision,message,processed,created_at,updated_at)
            SELECT job_token,person_key,
            SUBSTRING_INDEX(GROUP_CONCAT(identity_strength ORDER BY identity_rank DESC,id ASC SEPARATOR ','),',',1),
            MAX(identity_rank),MAX(name_key),COUNT(*),SUM(record_type='application'),MAX(record_at),'{}',
            CASE WHEN SUM(decision='identity_conflict')>0 THEN 'identity_conflict' WHEN MAX(identity_rank)<=10 THEN 'review_required' ELSE 'pending' END,
            CASE WHEN SUM(decision='identity_conflict')>0 THEN 'Обнаружена противоречивая старая связь. Автоматический импорт этой группы остановлен.' WHEN MAX(identity_rank)<=10 THEN 'Недостаточно надёжных данных: найдено только ФИО. Автоматическое объединение отключено.' ELSE '' END,
            0,%s,%s
            FROM {$this->stage_table}
            WHERE job_token=%s AND decision<>'already_imported'
            GROUP BY job_token,person_key",
            $now,$now,$jobToken
        );
        $wpdb->query($sql);
        return [
            'people'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->people_table} WHERE job_token=%s", $jobToken)),
            'applications'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->stage_table} WHERE job_token=%s AND record_type='application'", $jobToken)),
            'already_imported'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->stage_table} WHERE job_token=%s AND decision='already_imported'", $jobToken)),
            'review'=>(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->people_table} WHERE job_token=%s AND decision='review_required'", $jobToken)),
        ];
    }

    private function canonical_rows_for_person($jobToken, $personKey) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND decision<>'already_imported' ORDER BY COALESCE(record_at,'1000-01-01 00:00:00') ASC,record_sort ASC,id ASC",
            $jobToken,$personKey
        ), ARRAY_A);
    }

    private function merge_profile(array $rows) {
        $profile = [];
        $latestAt = '';
        $legacyUserIds = [];
        $iinValues = [];
        $nameValues = [];
        $linkedLegacyUsers = [];
        $targetUserIds = [];
        $targetSubmissionIds = [];
        foreach ($rows as $row) {
            $canonical = json_decode((string)$row['canonical_json'], true);
            if (!empty($row['target_user_id'])) { $targetUserIds[(int)$row['target_user_id']] = 1; }
            if (!empty($row['target_submission_id'])) { $targetSubmissionIds[(int)$row['target_submission_id']] = 1; }
            if (!is_array($canonical)) { $canonical = []; }
            $rowAt = (string)($row['record_at'] ?? '');
            if ($rowAt && (!$latestAt || $rowAt > $latestAt)) { $latestAt = $rowAt; }
            if (!empty($row['legacy_user_id']) && (int)$row['legacy_user_id'] > 0) {
                $legacyUserIds[(string)$row['legacy_user_id']] = 1;
                $linkedLegacyUsers[(string)$row['legacy_user_id']] = 1;
            }
            $iin = $this->normalize_iin($canonical['iin'] ?? '');
            if ($iin) { $iinValues[$iin] = 1; }
            $nameValue = $this->normalize_text($canonical['full_name'] ?? '');
            if ($nameValue) { $nameValues[$nameValue] = 1; }
            foreach ($canonical as $key=>$value) {
                if (is_array($value) || is_object($value)) { continue; }
                $value = trim((string)$value);
                if ($value === '') { continue; }
                if (in_array($key, ['legacy_entry_id','submission_date','pdf_url','signature_url','application_no'], true)) { continue; }
                $profile[$key] = $value;
            }
        }
        if ($legacyUserIds) { $legacyKeys = array_keys($legacyUserIds); $profile['legacy_user_id'] = (string)reset($legacyKeys); }
        if ($latestAt) { $profile['_legacy_latest_at'] = $latestAt; }
        return [
            'profile'=>$profile,
            'iin_conflict'=>count($iinValues)>1,
            'iin_values'=>array_keys($iinValues),
            'name_conflict'=>count($nameValues)>1 && count($linkedLegacyUsers)!==1,
            'name_values'=>array_keys($nameValues),
            'target_user_conflict'=>count($targetUserIds)>1,
            'target_user_ids'=>array_keys($targetUserIds),
            'anchored_user_id'=>count($targetUserIds)===1 ? (int)array_key_first($targetUserIds) : 0,
            'target_submission_ids'=>array_keys($targetSubmissionIds),
        ];
    }

    private function form_family(array $row) {
        $title = $this->normalize_text($row['form_title'] ?? '');
        if (strpos($title, 'вступ') !== false || strpos($title, 'membership') !== false) { return 'membership'; }
        if (strpos($title, 'взнос') !== false || strpos($title, 'удерж') !== false || strpos($title, 'оплат') !== false || strpos($title, 'contribution') !== false) { return 'contribution'; }
        if (strpos($title, 'регистрац') !== false || strpos($title, 'анкет') !== false || strpos($title, 'profile') !== false) { return 'registration'; }
        if ((int)($row['form_id'] ?? 0) > 0) { return 'form:' . (int)$row['form_id']; }
        return 'title:' . hash('sha256', $title ?: (string)($row['id'] ?? '0'));
    }

    private function classify_applications($jobToken, $personKey, $policy) {
        global $wpdb;
        $apps = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND record_type='application' AND decision NOT IN ('review_required','identity_conflict') ORDER BY COALESCE(record_at,'1000-01-01 00:00:00') DESC,record_sort DESC,id DESC",
            $jobToken,$personKey
        ), ARRAY_A);
        $groups = [];
        foreach ($apps as $row) {
            $key = $this->form_family($row);
            if (!isset($groups[$key])) { $groups[$key] = []; }
            $groups[$key][] = $row;
        }
        $stats = ['current'=>0,'archived'=>0,'exact_duplicates'=>0];
        foreach ($groups as $groupRows) {
            $seenContent = [];
            $currentChosen = false;
            foreach ($groupRows as $row) {
                $id = (int)$row['id'];
                $hash = (string)$row['content_hash'];
                $wasImported = ((string)$row['decision'] === 'already_imported');
                if (isset($seenContent[$hash])) {
                    $wpdb->update($this->stage_table, [
                        'decision'=>'exact_duplicate','is_current'=>0,
                        'message'=>'Полное содержимое совпадает с более новой записью.','updated_at'=>current_time('mysql')
                    ], ['id'=>$id]);
                    $stats['exact_duplicates']++;
                    continue;
                }
                $seenContent[$hash] = 1;
                if (!$currentChosen && $wasImported) {
                    $wpdb->update($this->stage_table, ['is_current'=>1,'message'=>'Эта самая новая запись уже была перенесена ранее.','updated_at'=>current_time('mysql')], ['id'=>$id]);
                    $currentChosen = true;
                    continue;
                }
                if (!$currentChosen) {
                    $wpdb->update($this->stage_table, [
                        'decision'=>'current','is_current'=>1,
                        'message'=>'Самая новая уникальная заявка этого вида.','updated_at'=>current_time('mysql')
                    ], ['id'=>$id]);
                    $stats['current']++;
                    $currentChosen = true;
                } else {
                    if ($wasImported) { continue; }
                    $decision = ($policy === 'all_unique') ? 'current_extra' : 'archived';
                    $message = ($policy === 'all_unique') ? 'Уникальная более старая заявка будет импортирована отдельно.' : 'Более старая заявка сохранена в архиве переноса и не показывается как актуальная.';
                    $wpdb->update($this->stage_table, [
                        'decision'=>$decision,'is_current'=>0,'message'=>$message,'updated_at'=>current_time('mysql')
                    ], ['id'=>$id]);
                    $stats['archived']++;
                }
            }
        }
        return $stats;
    }

    private function choose_latest_target_user(array $rows) {
        $bestUserId = 0;
        $bestSort = -1;
        $bestId = -1;
        foreach ($rows as $row) {
            $userId = (int)($row['target_user_id'] ?? 0);
            if (!$userId || !get_user_by('id', $userId)) { continue; }
            $sort = 0;
            if (!empty($row['record_at'])) { $sort = (int)strtotime((string)$row['record_at']); }
            if (!$sort) { $sort = (int)($row['record_sort'] ?? 0); }
            $rowId = (int)($row['id'] ?? 0);
            if ($sort > $bestSort || ($sort === $bestSort && $rowId > $bestId)) {
                $bestUserId = $userId;
                $bestSort = $sort;
                $bestId = $rowId;
            }
        }
        return $bestUserId;
    }

    /**
     * Earlier migration versions could split one exact name+email identity into
     * several new accounts. We never delete those accounts automatically. We do
     * move legacy applications/maps to the newest mapped account and mark the
     * other generated accounts as migration duplicates for a later audit.
     */
    private function consolidate_previous_targets($jobToken, $personKey, $canonicalUserId, array $rows) {
        global $wpdb;
        $canonicalUserId = (int)$canonicalUserId;
        if (!$canonicalUserId) { return []; }
        $duplicates = [];
        $submissionIds = [];
        foreach ($rows as $row) {
            $uid = (int)($row['target_user_id'] ?? 0);
            if ($uid && $uid !== $canonicalUserId) { $duplicates[$uid] = 1; }
            $sid = (int)($row['target_submission_id'] ?? 0);
            if ($sid) { $submissionIds[$sid] = 1; }
            if (!empty($row['source_hash'])) {
                $wpdb->update($this->map_table, [
                    'target_user_id'=>$canonicalUserId,
                    'updated_at'=>current_time('mysql'),
                ], ['source_hash'=>(string)$row['source_hash']]);
            }
        }
        if ($submissionIds) {
            $ids = array_map('intval', array_keys($submissionIds));
            $wpdb->query("UPDATE {$wpdb->prefix}zau_union_submissions SET user_id={$canonicalUserId} WHERE id IN (" . implode(',', $ids) . ")");
        }
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->stage_table} SET target_user_id=%d,updated_at=%s WHERE job_token=%s AND person_key=%s",
            $canonicalUserId,current_time('mysql'),$jobToken,$personKey
        ));
        foreach (array_keys($duplicates) as $duplicateId) {
            update_user_meta((int)$duplicateId, 'zau_legacy_duplicate_of', $canonicalUserId);
            update_user_meta((int)$duplicateId, 'zau_hidden_from_registry', 1);
            update_user_meta((int)$duplicateId, 'zau_member_status', 'Дубликат переноса');
        }
        return array_map('intval', array_keys($duplicates));
    }

    public function process_people_batch($jobToken, $cursor, $limit, array $options) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->people_table} WHERE job_token=%s AND id>%d ORDER BY id ASC LIMIT %d",
            $jobToken,(int)$cursor,max(1,min(100,(int)$limit))
        ), ARRAY_A);
        $out = ['next_cursor'=>(int)$cursor,'handled'=>0,'done'=>false,'stats'=>[],'results'=>[]];
        foreach ($rows as $person) {
            $out['next_cursor'] = (int)$person['id'];
            $out['handled']++;
            $personKey = (string)$person['person_key'];
            if ($person['decision'] === 'identity_conflict') {
                $wpdb->update($this->stage_table, ['decision'=>'identity_conflict','message'=>$person['message'],'updated_at'=>current_time('mysql')], ['job_token'=>$jobToken,'person_key'=>$personKey]);
                $wpdb->update($this->people_table, ['processed'=>1,'updated_at'=>current_time('mysql')], ['id'=>(int)$person['id']]);
                $out['stats']['identity_conflicts'] = ($out['stats']['identity_conflicts'] ?? 0) + 1;
                $out['results'][] = ['kind'=>'identity_conflict','message'=>$person['message'],'person'=>$person];
                continue;
            }
            if ($person['decision'] === 'review_required') {
                $wpdb->update($this->stage_table, ['decision'=>'review_required','message'=>$person['message'],'updated_at'=>current_time('mysql')], ['job_token'=>$jobToken,'person_key'=>$personKey]);
                $wpdb->update($this->people_table, ['processed'=>1,'updated_at'=>current_time('mysql')], ['id'=>(int)$person['id']]);
                $out['stats']['review_required'] = ($out['stats']['review_required'] ?? 0) + 1;
                $out['results'][] = ['kind'=>'review_required','message'=>$person['message'],'person'=>$person];
                continue;
            }
            $sourceRows = $this->canonical_rows_for_person($jobToken,$personKey);
            $merged = $this->merge_profile($sourceRows);
            $identityWarnings = [];
            if (!empty($merged['target_user_conflict'])) {
                $canConsolidate = in_array((string)$person['identity_strength'], ['strong-name-email','legacy-user'], true);
                $canonicalTarget = $canConsolidate ? $this->choose_latest_target_user($sourceRows) : 0;
                if ($canonicalTarget) {
                    $duplicates = $this->consolidate_previous_targets($jobToken,$personKey,$canonicalTarget,$sourceRows);
                    $merged['target_user_conflict'] = false;
                    $merged['anchored_user_id'] = $canonicalTarget;
                    if ($duplicates) {
                        $identityWarnings[] = 'Ранее разделённые аккаунты сведены к User ID ' . $canonicalTarget . '; дубликаты помечены для проверки: ' . implode(', ', $duplicates) . '.';
                    }
                } else {
                    $message = 'Совпавшие исходные данные уже привязаны к разным аккаунтам нового сайта. Автоматическое объединение остановлено.';
                    $wpdb->update($this->people_table, ['decision'=>'identity_conflict','message'=>$message,'processed'=>1,'profile_json'=>wp_json_encode($merged['profile'],JSON_UNESCAPED_UNICODE),'updated_at'=>current_time('mysql')], ['id'=>(int)$person['id']]);
                    $wpdb->query($wpdb->prepare("UPDATE {$this->stage_table} SET decision='identity_conflict',message=%s,updated_at=%s WHERE job_token=%s AND person_key=%s",$message,current_time('mysql'),$jobToken,$personKey));
                    $out['stats']['identity_conflicts'] = ($out['stats']['identity_conflicts'] ?? 0) + 1;
                    $out['results'][] = ['kind'=>'identity_conflict','message'=>$message,'person'=>$person];
                    continue;
                }
            }
            if (!empty($merged['iin_conflict'])) {
                $identityWarnings[] = 'В старых записях этой пары ФИО + email встречаются разные ИИН; в профиль попадёт значение из самой новой записи, а все исходные значения сохраняются в архиве.';
            }
            if (!empty($merged['name_conflict'])) {
                $identityWarnings[] = 'В заявлениях, привязанных по старому User ID, встречаются варианты написания ФИО; владельцем считается аккаунт с точной парой ФИО + email.';
            }
            $profile = $merged['profile'];
            $record = [
                'type'=>'account',
                'source_person_key'=>$personKey,
                'legacy_user_id'=>(string)($profile['legacy_user_id'] ?? ''),
                'canonical'=>$profile,
            ];
            $importOptions = [
                'mode'=>'accounts',
                'dry_run'=>!empty($options['dry_run']) ? 1 : 0,
                'create_users'=>!empty($options['create_users']) ? 1 : 0,
                'update_existing'=>!empty($options['update_existing']) ? 1 : 0,
                'overwrite'=>!empty($options['overwrite']) ? 1 : 0,
                'default_status'=>(string)($options['default_status'] ?? 'Состоит в профсоюзе'),
                'preserve_password'=>!empty($options['preserve_password']) ? 1 : 0,
                'source_site'=>(string)($options['source_site'] ?? ''),
                'import_token'=>(string)$jobToken,
                'legacy_latest_profile'=>1,
                'forced_user_id'=>(int)($merged['anchored_user_id'] ?? 0),
                'identity_mode'=>'name_email',
            ];
            $result = ZAU_Universal_Import::instance()->import_external_record($record,$importOptions);
            $targetUserId = (int)($result['user_id'] ?? 0);
            $decision = (string)($result['kind'] ?? 'error');
            $message = (string)($result['message'] ?? '');
            if ($identityWarnings) { $message = trim($message . ' ' . implode(' ', $identityWarnings)); }
            if (!empty($options['dry_run']) && !$targetUserId && (string)($result['kind'] ?? '') !== 'error') {
                $existing = ZAU_Universal_Import::instance()->find_external_user($profile, 'name_email');
                $targetUserId = (!is_wp_error($existing) && $existing) ? (int)$existing->ID : 0;
            }
            $wpdb->update($this->people_table, [
                'profile_json'=>wp_json_encode($profile,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'existing_user_id'=>$targetUserId,
                'target_user_id'=>$targetUserId,
                'decision'=>$decision,
                'message'=>$message,
                'processed'=>1,
                'updated_at'=>current_time('mysql'),
            ], ['id'=>(int)$person['id']]);
            if ($targetUserId) {
                $wpdb->query($wpdb->prepare("UPDATE {$this->stage_table} SET target_user_id=%d,updated_at=%s WHERE job_token=%s AND person_key=%s",$targetUserId,current_time('mysql'),$jobToken,$personKey));
                if (empty($options['dry_run'])) { $this->map_account_rows($jobToken,$personKey,$targetUserId); }
            }
            if (!$targetUserId && in_array($decision,['error','skipped'],true)) {
                $stageDecision = (strpos($message,'несколько аккаунтов')!==false || strpos($message,'идентификатор')!==false) ? 'identity_conflict' : 'import_error';
                $wpdb->update($this->people_table,['decision'=>$stageDecision,'message'=>$message,'updated_at'=>current_time('mysql')],['id'=>(int)$person['id']]);
                $wpdb->query($wpdb->prepare("UPDATE {$this->stage_table} SET decision=%s,message=%s,updated_at=%s WHERE job_token=%s AND person_key=%s",$stageDecision,$message,current_time('mysql'),$jobToken,$personKey));
                $out['stats'][$decision] = ($out['stats'][$decision] ?? 0) + 1;
                $out['results'][] = ['kind'=>$decision,'message'=>$message,'person'=>$person,'profile'=>$profile,'user_id'=>0];
                continue;
            }
            $class = $this->classify_applications($jobToken,$personKey,(string)($options['application_policy'] ?? 'latest_only_archive'));
            if (empty($options['dry_run'])) { $this->map_previously_imported_anchors($jobToken,$personKey,(string)($options['application_policy'] ?? 'latest_only_archive')); }
            foreach ($class as $k=>$v) { $out['stats'][$k] = ($out['stats'][$k] ?? 0) + $v; }
            $out['stats'][$decision] = ($out['stats'][$decision] ?? 0) + 1;
            $out['results'][] = ['kind'=>$decision,'message'=>$message,'person'=>$person,'profile'=>$profile,'user_id'=>$targetUserId];
        }
        if (!$rows || count($rows) < max(1,min(100,(int)$limit))) { $out['done'] = true; }
        return $out;
    }

    private function map_previously_imported_anchors($jobToken,$personKey,$policy) {
        global $wpdb;
        $anchors = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND record_type='application' AND decision='already_imported' AND is_current=1 AND target_user_id>0",
            $jobToken,$personKey
        ),ARRAY_A);
        foreach ((array)$anchors as $anchor) {
            $this->map_related_rows($jobToken,$anchor,(int)$anchor['target_user_id'],(int)$anchor['target_submission_id'],$policy);
        }
    }

    private function map_account_rows($jobToken,$personKey,$targetUserId) {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND record_type='account'",
            $jobToken,$personKey
        ),ARRAY_A);
        $now = current_time('mysql');
        foreach ($rows as $row) {
            $wpdb->replace($this->map_table,[
                'import_token'=>sanitize_text_field($jobToken),
                'source_hash'=>(string)$row['source_hash'],
                'row_number'=>0,
                'legacy_user_id'=>sanitize_text_field($row['legacy_user_id']),
                'legacy_entry_id'=>'',
                'target_user_id'=>(int)$targetUserId,
                'target_submission_id'=>0,
                'status'=>'imported_account',
                'message'=>'smart dedup account mapping',
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
        }
    }

    public function application_total($jobToken, $policy) {
        global $wpdb;
        $decisions = $policy === 'all_unique' ? ['current','current_extra'] : ['current'];
        $placeholders = implode(',', array_fill(0,count($decisions),'%s'));
        $params = array_merge([$jobToken],$decisions);
        return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->stage_table} WHERE job_token=%s AND record_type='application' AND decision IN ($placeholders)",$params));
    }

    public function process_applications_batch($jobToken, $cursor, $limit, array $options) {
        global $wpdb;
        $policy = (string)($options['application_policy'] ?? 'latest_only_archive');
        $decisions = $policy === 'all_unique' ? ['current','current_extra'] : ['current'];
        $placeholders = implode(',', array_fill(0,count($decisions),'%s'));
        $params = array_merge([$jobToken,(int)$cursor],$decisions,[max(1,min(200,(int)$limit))]);
        $sql = $wpdb->prepare("SELECT * FROM {$this->stage_table} WHERE job_token=%s AND id>%d AND record_type='application' AND decision IN ($placeholders) ORDER BY id ASC LIMIT %d",$params);
        $rows = $wpdb->get_results($sql,ARRAY_A);
        $out = ['next_cursor'=>(int)$cursor,'handled'=>0,'done'=>false,'stats'=>[],'results'=>[]];
        foreach ($rows as $row) {
            $out['next_cursor'] = (int)$row['id'];
            $out['handled']++;
            $record = json_decode((string)$row['payload_json'],true);
            if (!is_array($record)) { $record = []; }
            $canonical = json_decode((string)$row['canonical_json'],true);
            if (!is_array($canonical)) { $canonical = []; }
            $record['canonical'] = $canonical;
            $record['source_person_key'] = (string)$row['person_key'];
            $targetUserId = (int)$row['target_user_id'];
            if (!$targetUserId) {
                $person = $wpdb->get_row($wpdb->prepare("SELECT target_user_id FROM {$this->people_table} WHERE job_token=%s AND person_key=%s LIMIT 1",$jobToken,$row['person_key']));
                $targetUserId = $person ? (int)$person->target_user_id : 0;
            }
            $allRelated = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND record_type='application' AND decision IN ('archived','exact_duplicate') ORDER BY COALESCE(record_at,'1000-01-01') DESC,record_sort DESC",
                $jobToken,$row['person_key']
            ),ARRAY_A);
            $family = $this->form_family($row);
            $archiveRows = [];
            foreach ((array)$allRelated as $related) {
                if ($this->form_family($related) !== $family) { continue; }
                $archiveRows[] = [
                    'legacy_entry_id'=>$related['legacy_entry_id'],
                    'record_at'=>$related['record_at'],
                    'decision'=>$related['decision'],
                ];
            }
            $record['legacy_archive_summary'] = [
                'count'=>count($archiveRows),
                'records'=>$archiveRows,
                'current_entry_id'=>$row['legacy_entry_id'],
                'current_date'=>$row['record_at'],
            ];
            if (!empty($options['dry_run'])) {
                $wouldUpdateSubmission = !empty($row['target_submission_id']);
                $result = [
                    'kind'=>$wouldUpdateSubmission ? 'would_update_submission' : 'would_submit',
                    'message'=>$wouldUpdateSubmission ? 'Будет обновлена ранее перенесённая версия этого же исходного заявления без создания дубля.' : 'Будет перенесена как актуальная заявка; более старые записи этого вида останутся в архиве переноса.',
                    'user_id'=>$targetUserId,
                    'submission_id'=>$wouldUpdateSubmission ? (int)$row['target_submission_id'] : 0,
                    'would_submit'=>$wouldUpdateSubmission ? 0 : 1,
                    'mapped'=>$canonical,
                ];
            } else {
                $result = ZAU_Universal_Import::instance()->import_external_record($record,[
                'mode'=>'applications',
                'dry_run'=>!empty($options['dry_run']) ? 1 : 0,
                'create_users'=>0,
                'update_existing'=>0,
                'overwrite'=>0,
                'default_status'=>(string)($options['default_status'] ?? 'Состоит в профсоюзе'),
                'preserve_password'=>0,
                'source_site'=>(string)($options['source_site'] ?? ''),
                'import_token'=>(string)$jobToken,
                'forced_user_id'=>$targetUserId,
                'existing_submission_id'=>(int)$row['target_submission_id'],
                'submission_status'=>$row['decision']==='current' ? 'submitted' : 'archived',
                ]);
            }
            $submissionId = (int)($result['submission_id'] ?? 0);
            if ($submissionId) { $out['stats']['submissions'] = ($out['stats']['submissions'] ?? 0) + 1; }
            $kind = (string)($result['kind'] ?? 'error');
            $message = (string)($result['message'] ?? '');
            $wpdb->update($this->stage_table,[
                'target_user_id'=>$targetUserId,
                'target_submission_id'=>$submissionId,
                'message'=>$message ?: $row['message'],
                'updated_at'=>current_time('mysql')
            ],['id'=>(int)$row['id']]);
            if (empty($options['dry_run']) && $targetUserId && ($submissionId || $kind==='duplicate')) {
                $this->map_related_rows($jobToken,$row,$targetUserId,$submissionId,$policy);
            }
            $out['stats'][$kind] = ($out['stats'][$kind] ?? 0) + 1;
            if (!empty($result['would_submit'])) { $out['stats']['would_submit'] = ($out['stats']['would_submit'] ?? 0) + 1; }
            $out['results'][] = ['kind'=>$kind,'message'=>$message,'row'=>$row,'user_id'=>$targetUserId,'submission_id'=>$submissionId];
        }
        if (!$rows || count($rows) < max(1,min(200,(int)$limit))) { $out['done'] = true; }
        return $out;
    }

    private function map_related_rows($jobToken,array $currentRow,$targetUserId,$targetSubmissionId,$policy='latest_only_archive') {
        global $wpdb;
        $allRows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->stage_table} WHERE job_token=%s AND person_key=%s AND record_type='application' AND decision IN ('current','archived','exact_duplicate','current_extra')",
            $jobToken,$currentRow['person_key']
        ),ARRAY_A);
        $family = $this->form_family($currentRow);
        $rows = [];
        foreach ((array)$allRows as $candidate) {
            if ($this->form_family($candidate) !== $family) { continue; }
            if ($policy === 'all_unique' && (string)$candidate['content_hash'] !== (string)$currentRow['content_hash'] && (int)$candidate['id'] !== (int)$currentRow['id']) { continue; }
            $rows[] = $candidate;
        }
        $now = current_time('mysql');
        foreach ($rows as $row) {
            $wpdb->replace($this->map_table,[
                'import_token'=>sanitize_text_field($jobToken),
                'source_hash'=>(string)$row['source_hash'],
                'row_number'=>0,
                'legacy_user_id'=>sanitize_text_field($row['legacy_user_id']),
                'legacy_entry_id'=>sanitize_text_field($row['legacy_entry_id']),
                'target_user_id'=>(int)$targetUserId,
                'target_submission_id'=>(int)$targetSubmissionId,
                'status'=>$row['decision']==='current' ? 'imported' : 'archived_mapped',
                'message'=>'smart dedup: ' . $row['decision'],
                'created_at'=>$now,
                'updated_at'=>$now,
            ]);
            $wpdb->update($this->stage_table,[
                'target_user_id'=>(int)$targetUserId,
                'target_submission_id'=>(int)$targetSubmissionId,
                'updated_at'=>$now
            ],['id'=>(int)$row['id']]);
        }
    }

    public function job_counts($jobToken) {
        global $wpdb;
        $counts = [];
        $rows = $wpdb->get_results($wpdb->prepare("SELECT decision,COUNT(*) c FROM {$this->stage_table} WHERE job_token=%s GROUP BY decision",$jobToken));
        foreach ((array)$rows as $row) { $counts[(string)$row->decision]=(int)$row->c; }
        $counts['people']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->people_table} WHERE job_token=%s",$jobToken));
        $counts['review_people']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->people_table} WHERE job_token=%s AND decision='review_required'",$jobToken));
        $counts['conflict_people']=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->people_table} WHERE job_token=%s AND decision='identity_conflict'",$jobToken));
        return $counts;
    }

    public function report_rows($jobToken,$offset=0,$limit=500) {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT s.*,p.decision person_decision,p.message person_message FROM {$this->stage_table} s LEFT JOIN {$this->people_table} p ON p.job_token=s.job_token AND p.person_key=s.person_key WHERE s.job_token=%s ORDER BY s.id ASC LIMIT %d OFFSET %d",
            $jobToken,max(1,min(5000,(int)$limit)),max(0,(int)$offset)
        ),ARRAY_A);
    }

    public function clear_job($jobToken) {
        global $wpdb;
        if (!$jobToken) { return; }
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->people_table} WHERE job_token=%s",$jobToken));
        $wpdb->query($wpdb->prepare("DELETE FROM {$this->stage_table} WHERE job_token=%s",$jobToken));
    }
}

ZAU_Remote_Smart_Dedup::instance();
