<?php
/**
 * Plugin Name: MYZ AI Chatbot
 * Description: マイズインバウンドのAIチャットボット（Claude API連携）
 * Version: 5.21.3
 * Author: MYZINBOUND INC
 * Text Domain: myz-ai-chatbot
 */

if (!defined('ABSPATH')) exit;

define('MYZ_CHATBOT_VERSION', '5.21.3');
define('MYZ_CHATBOT_PATH', plugin_dir_path(__FILE__));
define('MYZ_CHATBOT_URL', plugin_dir_url(__FILE__));
define('MYZ_CHATBOT_MAX_UPLOAD_SIZE', 10 * 1024 * 1024); // 10MB

require_once MYZ_CHATBOT_PATH . 'includes/site-knowledge.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-chatbot-api.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-scraper.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-file-parser.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-updater.php';

class MYZ_AI_Chatbot {

    /** スタンドアロンページ描画中は true（端末言語による判定を有効にする） */
    private $standalone_context = false;

    /** スタンドアロンページのURLスラグ既定値（空欄保存時もこれに寄せる） */
    const DEFAULT_STANDALONE_SLUG = 'chatbot';

    const CATEGORIES = [
        '料金・費用'       => ['料金', '手数料', '費用', '価格', 'いくら', 'コスト', '月額', 'システム費', '契約金'],
        'サービス内容'     => ['サービス', 'サポート', '内容', '何をして', 'どんな', '代行', '運営', 'オペレーション', '清掃', 'チェックイン', '対応'],
        '契約・解約'       => ['契約', '解約', '期間', '違約金', 'キャンセル', '最低', '乗り換え'],
        'AI・システム'     => ['AI', 'ダイナミック', 'プライシング', 'トラッキング', 'システム', '価格設定', '自動'],
        '対応エリア・物件' => ['沖縄', 'エリア', '全国', '対応', '物件', '民泊', '戸建て', '新法', '旅館業', '室数', '何室'],
        'OTA・集客'        => ['OTA', 'Airbnb', 'Booking', '楽天', 'じゃらん', '予約サイト', '集客'],
        '会社・代表'       => ['会社', '代表', '松根', '経歴', '設立', '実績', '住所', '電話', 'メール'],
        '導入・流れ'       => ['導入', '流れ', '開始', '期間', '相談', '訪問', '無料'],
        'その他'           => [],
    ];

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'maybe_create_tables']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_widget']);

        // AJAX: チャット
        $api = new MYZ_Chatbot_API();
        $api->register_ajax();

        // 固定ページが変わったらお問い合わせフォームURLの自動検出キャッシュを捨てる
        add_action('save_post_page', [$this, 'clear_contact_url_cache']);

        // AJAX: 手動テーブル作成
        add_action('wp_ajax_myz_create_tables_manual', [$this, 'ajax_create_tables']);

        // AJAX: 手動スクレイピング
        add_action('wp_ajax_myz_scrape_now', [$this, 'ajax_scrape_now']);

        // AJAX: ファイルアップロード/削除
        add_action('wp_ajax_myz_upload_knowledge_file', [$this, 'ajax_upload_knowledge_file']);
        add_action('wp_ajax_myz_delete_knowledge_file', [$this, 'ajax_delete_knowledge_file']);

        // AJAX: 更新チェック
        add_action('wp_ajax_myz_check_update', [$this, 'ajax_check_update']);

        // 外部同期（宿シート等の自動投入）: 同期トークンで認証するのでログイン不要
        add_action('wp_ajax_nopriv_myz_sync_knowledge', [$this, 'ajax_sync_knowledge']);
        add_action('wp_ajax_myz_sync_knowledge', [$this, 'ajax_sync_knowledge']);

        // Cron
        add_action('myz_chatbot_scrape_cron', [$this, 'run_cron_scrape']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // GitHub自動更新
        new MYZ_Chatbot_Updater();

        // スタンドアロンページ
        add_action('template_redirect', [$this, 'render_standalone_page']);
        // WP Fastest Cache は DONOTCACHEPAGE を無視するので、WPFCの除外ルール（WpFastestCacheExclude）に客室QRページを自動登録する
        add_action('admin_init', [$this, 'ensure_wpfc_exclusion']);
        // 客室QRページのクイック質問ボタン: 保存時に他言語へ自動翻訳してキャッシュ
        add_action('add_option_myz_chatbot_quick_buttons', [$this, 'on_quick_buttons_added'], 10, 2);
        add_action('update_option_myz_chatbot_quick_buttons', [$this, 'on_quick_buttons_saved'], 10, 2);
        // チャット履歴のCSVダウンロード
        add_action('admin_post_myz_export_chat_logs', [$this, 'export_chat_logs_csv']);
        add_action('init', [$this, 'add_rewrite_rules']);
        add_action('wp_loaded', [$this, 'maybe_flush_rewrite_rules']);
        add_action('admin_init', [$this, 'repair_standalone_slug']);
        add_filter('query_vars', [$this, 'add_query_vars']);

        register_activation_hook(__FILE__, [$this, 'on_activate']);
        register_deactivation_hook(__FILE__, [$this, 'on_deactivate']);
    }

    /**
     * プラグイン有効化
     */
    public function on_activate() {
        $this->create_tables();
        // デフォルトの更新頻度を設定
        if (!get_option('myz_chatbot_scrape_frequency')) {
            update_option('myz_chatbot_scrape_frequency', 'weekly');
        }
        $this->schedule_cron();
        // リライトルールをフラッシュ
        $this->add_rewrite_rules();
        flush_rewrite_rules();
    }

    /**
     * プラグイン無効化
     */
    public function on_deactivate() {
        wp_clear_scheduled_hook('myz_chatbot_scrape_cron');
    }

    /**
     * カスタムCronスケジュールを追加
     */
    public function add_cron_schedules($schedules) {
        $schedules['myz_monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => '月1回',
        ];
        return $schedules;
    }

    /**
     * Cronスケジュール設定
     */
    public function schedule_cron() {
        wp_clear_scheduled_hook('myz_chatbot_scrape_cron');
        $freq = get_option('myz_chatbot_scrape_frequency', 'weekly');
        if ($freq !== 'manual') {
            wp_schedule_event(time(), $freq, 'myz_chatbot_scrape_cron');
        }
    }

    /**
     * Cron実行
     */
    public function run_cron_scrape() {
        MYZ_Chatbot_Scraper::scrape_all();
    }

    /**
     * DBテーブル作成
     */
    public function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        // チャットログテーブル
        $table1 = $wpdb->prefix . 'myz_chat_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table1'") !== $table1) {
            $wpdb->query("CREATE TABLE `$table1` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `session_id` varchar(64) NOT NULL,
                `user_message` text NOT NULL,
                `bot_reply` text NOT NULL,
                `category` varchar(50) DEFAULT 'その他',
                `ip_address` varchar(45) DEFAULT '',
                `created_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_category` (`category`)
            ) $charset;");
            error_log('MYZ Chatbot: Created table ' . $table1 . ' result=' . ($wpdb->last_error ?: 'OK'));
        }

        // ナレッジベーステーブル
        $table2 = $wpdb->prefix . 'myz_knowledge';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table2'") !== $table2) {
            $wpdb->query("CREATE TABLE `$table2` (
                `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                `url` varchar(500) NOT NULL,
                `source_type` varchar(10) NOT NULL DEFAULT 'url',
                `filename` varchar(255) NOT NULL DEFAULT '',
                `file_size` bigint(20) NOT NULL DEFAULT 0,
                `content` longtext NOT NULL,
                `updated_at` datetime DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_url` (`url`(191))
            ) $charset;");
            error_log('MYZ Chatbot: Created table ' . $table2 . ' result=' . ($wpdb->last_error ?: 'OK'));
        }
    }

    /**
     * バージョンが変わったらテーブルを再作成
     */
    public function maybe_create_tables() {
        global $wpdb;
        $table1 = $wpdb->prefix . 'myz_chat_logs';
        $table2 = $wpdb->prefix . 'myz_knowledge';

        // テーブルが存在しなければ作成（バージョン関係なく毎回チェック）
        $t1_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table1'") === $table1);
        $t2_exists = ($wpdb->get_var("SHOW TABLES LIKE '$table2'") === $table2);

        if (!$t1_exists || !$t2_exists) {
            $this->create_tables();
        }

        // ファイル学習用カラムを追加（既存テーブルのマイグレーション）
        if ($t2_exists) {
            $has_source = $wpdb->get_var("SHOW COLUMNS FROM `$table2` LIKE 'source_type'");
            if (empty($has_source)) {
                $wpdb->query("ALTER TABLE `$table2` ADD COLUMN `source_type` VARCHAR(10) NOT NULL DEFAULT 'url' AFTER `url`");
                $wpdb->query("ALTER TABLE `$table2` ADD COLUMN `filename` VARCHAR(255) NOT NULL DEFAULT '' AFTER `source_type`");
                $wpdb->query("ALTER TABLE `$table2` ADD COLUMN `file_size` BIGINT NOT NULL DEFAULT 0 AFTER `filename`");
            }
        }
    }

    public function add_admin_menu() {
        add_options_page(
            'MYZ AI Chatbot 設定',
            'MYZ AI Chatbot',
            'manage_options',
            'myz-ai-chatbot',
            [$this, 'render_settings_page']
        );
        add_menu_page(
            'チャット履歴',
            'チャット履歴',
            'manage_options',
            'myz-chat-logs',
            [$this, 'render_logs_page'],
            'dashicons-format-chat',
            30
        );
    }

    public function register_settings() {
        register_setting('myz_chatbot_settings', 'myz_chatbot_ai_provider', ['default' => 'claude']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_api_key');
        register_setting('myz_chatbot_settings', 'myz_chatbot_claude_model', ['default' => 'claude-sonnet-4-5-20250929']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_openai_api_key');
        register_setting('myz_chatbot_settings', 'myz_chatbot_openai_model', ['default' => 'gpt-4o-mini']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_gemini_api_key');
        register_setting('myz_chatbot_settings', 'myz_chatbot_gemini_model', ['default' => 'gemini-3.8-flash']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_enabled', ['default' => '1']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_extra_instructions', [
            'default' => "プレーンテキストで回答してください。Markdownの記号（#, *, **など）は絶対に使わないでください。\n改行を適切に入れて読みやすくしてください。\n箇条書きには「・」を使ってください。\n回答は200文字以内を目安に簡潔にお願いします。",
        ]);
        // サイト内ウィジェットで問い合わせ先として案内するお問い合わせフォームのURL（空欄なら固定ページから自動検出）
        register_setting('myz_chatbot_settings', 'myz_chatbot_contact_url', [
            'default' => '',
            'sanitize_callback' => 'esc_url_raw',
        ]);
        register_setting('myz_chatbot_settings', 'myz_chatbot_urls', [
            'sanitize_callback' => [$this, 'sanitize_urls'],
        ]);
        register_setting('myz_chatbot_settings', 'myz_chatbot_scrape_frequency', ['default' => 'weekly']);
        // 外部同期トークン（空で保存されたら自動生成に戻す）
        register_setting('myz_chatbot_settings', 'myz_chatbot_sync_token', [
            'default' => '',
            'sanitize_callback' => function($val) {
                $val = preg_replace('/[^A-Za-z0-9]/', '', (string) $val);
                return strlen($val) >= 24 ? $val : wp_generate_password(40, false, false);
            },
        ]);

        // UI設定
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_text', ['default' => 'AIに質問']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_icon', ['default' => 'chat']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_header_text', ['default' => 'AIに質問']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_header_icon', ['default' => 'chat']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_primary_color', ['default' => '#159BBE']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_text_color', ['default' => '#ffffff']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_send_icon', ['default' => 'paper-plane']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message', ['default' => "こんにちは！AIアシスタントです。サービス内容や料金など、お気軽にご質問ください。\n\nHello! I'm your AI assistant. Please feel free to ask me any questions about our services, pricing, or anything else."]);
        register_setting('myz_chatbot_settings', 'myz_chatbot_quick_buttons', [
            'default' => '',
            'sanitize_callback' => 'sanitize_textarea_field',
        ]);
        // 言語別初期メッセージ（空欄なら上の共通メッセージを使用＝後方互換）
        // 言語別ヘッダータイトル（空ならmyz_chatbot_header_textにフォールバック）
        foreach ($this->supported_langs() as $myz_lang) {
            if ($myz_lang !== 'ja') {
                register_setting('myz_chatbot_settings', 'myz_chatbot_header_text_' . $myz_lang, ['default' => '']);
            }
            register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message_' . $myz_lang, ['default' => '']);
        }
        register_setting('myz_chatbot_settings', 'myz_chatbot_standalone_slug', [
            'default' => self::DEFAULT_STANDALONE_SLUG,
            'sanitize_callback' => function($val) {
                $val = sanitize_title($val);
                // 空欄で保存されるとリライトルールが '^/?$'（サイトのトップ）に一致してしまうため既定値に戻す
                if ($val === '') {
                    $val = self::DEFAULT_STANDALONE_SLUG;
                }
                // ここでflushしても、この時点のルールは旧スラグで組まれているため効かない。
                // フラグだけ立てて、新しいスラグでルールを組み直した次のリクエスト（wp_loaded）でflushする。
                update_option('myz_chatbot_flush_needed', 1);
                return $val;
            },
        ]);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_size', ['default' => 'medium']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_radius', ['default' => '50']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_font_family', ['default' => 'system']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_font_weight', ['default' => '600']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_font_size', ['default' => '15']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_position', ['default' => 'left']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_github_repo', ['default' => '']);
    }

    /**
     * URL入力をサニタイズ
     */
    public function sanitize_urls($input) {
        if (empty($input)) return [];
        if (is_string($input)) {
            $lines = explode("\n", $input);
        } else {
            $lines = (array) $input;
        }
        $urls = [];
        foreach ($lines as $line) {
            $url = trim(esc_url_raw($line));
            if (!empty($url)) {
                $urls[] = $url;
            }
        }

        // 頻度が変更されたらCronを再スケジュール
        $this->schedule_cron();

        return $urls;
    }

    /**
     * 手動スクレイピング AJAX
     */
    /**
     * 手動テーブル作成 AJAX
     */
    public function ajax_create_tables() {
        check_ajax_referer('myz_create_tables_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $errors = [];

        // チャットログテーブル
        $table1 = $wpdb->prefix . 'myz_chat_logs';
        $result1 = $wpdb->query("CREATE TABLE IF NOT EXISTS `$table1` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `session_id` varchar(64) NOT NULL,
            `user_message` text NOT NULL,
            `bot_reply` text NOT NULL,
            `category` varchar(50) DEFAULT 'その他',
            `ip_address` varchar(45) DEFAULT '',
            `created_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_created_at` (`created_at`),
            KEY `idx_category` (`category`)
        ) $charset;");
        if ($wpdb->last_error) {
            $errors[] = 'chat_logs: ' . $wpdb->last_error;
        }

        // ナレッジテーブル
        $table2 = $wpdb->prefix . 'myz_knowledge';
        $result2 = $wpdb->query("CREATE TABLE IF NOT EXISTS `$table2` (
            `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            `url` varchar(500) NOT NULL,
            `source_type` varchar(10) NOT NULL DEFAULT 'url',
            `filename` varchar(255) NOT NULL DEFAULT '',
            `file_size` bigint(20) NOT NULL DEFAULT 0,
            `content` longtext NOT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `idx_url` (`url`(191))
        ) $charset;");
        if ($wpdb->last_error) {
            $errors[] = 'knowledge: ' . $wpdb->last_error;
        }

        // 確認
        $t1 = $wpdb->get_var("SHOW TABLES LIKE '$table1'");
        $t2 = $wpdb->get_var("SHOW TABLES LIKE '$table2'");

        if (!empty($errors)) {
            wp_send_json_error(implode(' / ', $errors));
        } elseif ($t1 === $table1 && $t2 === $table2) {
            wp_send_json_success(['message' => 'テーブルを作成しました！']);
        } else {
            wp_send_json_error("テーブル作成に失敗しました。DB: {$wpdb->dbname}, Prefix: {$wpdb->prefix}, charset: $charset");
        }
    }

    public function ajax_check_update() {
        check_ajax_referer('myz_update_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }
        MYZ_Chatbot_Updater::force_check();
        $update_plugins = get_site_transient('update_plugins');
        $plugin_file = 'myz-ai-chatbot/myz-ai-chatbot.php';
        if (isset($update_plugins->response[$plugin_file])) {
            $new = $update_plugins->response[$plugin_file];
            wp_send_json_success([
                'has_update' => true,
                'new_version' => $new->new_version,
                'current_version' => MYZ_CHATBOT_VERSION,
                'update_url' => admin_url('update.php?action=upgrade-plugin&plugin=' . urlencode($plugin_file) . '&_wpnonce=' . wp_create_nonce('upgrade-plugin_' . $plugin_file)),
            ]);
        } else {
            wp_send_json_success([
                'has_update' => false,
                'current_version' => MYZ_CHATBOT_VERSION,
            ]);
        }
    }

    public function ajax_scrape_now() {
        check_ajax_referer('myz_scrape_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('権限がありません。');
        }
        $count = MYZ_Chatbot_Scraper::scrape_all();
        wp_send_json_success(['count' => $count, 'time' => current_time('Y/m/d H:i')]);
    }

    /**
     * 学習用ファイルのアップロードディレクトリを取得（必要なら作成）
     */
    public static function get_upload_dir() {
        $wp_upload = wp_upload_dir();
        $dir = trailingslashit($wp_upload['basedir']) . 'myz-chatbot';
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
            // 直接アクセス禁止
            @file_put_contents($dir . '/.htaccess', "Order Deny,Allow\nDeny from all\n");
            @file_put_contents($dir . '/index.php', "<?php // Silence is golden.\n");
        }
        return $dir;
    }

    /**
     * 学習ファイル アップロード AJAX
     */
    public function ajax_upload_knowledge_file() {
        check_ajax_referer('myz_file_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません。']);
        }
        if (empty($_FILES['file']) || !isset($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['message' => 'ファイルが選択されていません。']);
        }
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error(['message' => 'アップロードエラー（コード: ' . $file['error'] . '）']);
        }
        if ($file['size'] > MYZ_CHATBOT_MAX_UPLOAD_SIZE) {
            wp_send_json_error(['message' => 'ファイルサイズが上限（' . round(MYZ_CHATBOT_MAX_UPLOAD_SIZE / 1024 / 1024) . 'MB）を超えています。']);
        }

        $original_name = sanitize_file_name($file['name']);
        $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $allowed_exts = ['pdf', 'xlsx', 'docx', 'csv', 'txt', 'md', 'markdown'];
        if (!in_array($ext, $allowed_exts, true)) {
            wp_send_json_error(['message' => '対応していないファイル形式です。対応: PDF / Excel(.xlsx) / Word(.docx) / CSV / TXT / Markdown']);
        }

        // 保存先パスを生成（ハッシュ化して直リンクを推測不能に）
        $upload_dir = self::get_upload_dir();
        $unique = wp_generate_password(12, false, false);
        $stored_basename = $unique . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $original_name);
        $stored_path = $upload_dir . '/' . $stored_basename;

        if (!@move_uploaded_file($file['tmp_name'], $stored_path)) {
            wp_send_json_error(['message' => 'ファイルの保存に失敗しました。']);
        }

        // パース
        $parsed = MYZ_Chatbot_File_Parser::parse($stored_path, $original_name);
        if (isset($parsed['error'])) {
            @unlink($stored_path);
            wp_send_json_error(['message' => $parsed['error']]);
        }
        $text = $parsed['text'];

        // DB保存
        global $wpdb;
        $table = $wpdb->prefix . 'myz_knowledge';
        $url_key = 'file://' . $stored_basename;
        $insert = $wpdb->insert($table, [
            'url'         => $url_key,
            'source_type' => 'file',
            'filename'    => $original_name,
            'file_size'   => (int) $file['size'],
            'content'     => $text,
            'updated_at'  => current_time('mysql'),
        ], ['%s', '%s', '%s', '%d', '%s', '%s']);

        if ($insert === false) {
            @unlink($stored_path);
            wp_send_json_error(['message' => 'DB保存に失敗: ' . $wpdb->last_error]);
        }

        wp_send_json_success([
            'id'        => $wpdb->insert_id,
            'filename'  => $original_name,
            'chars'     => mb_strlen($text),
            'size'      => (int) $file['size'],
            'truncated' => !empty($parsed['truncated']),
            'updated'   => current_time('Y/m/d H:i'),
        ]);
    }

    /**
     * 外部同期トークン（未設定なら生成して保存）
     */
    public function get_sync_token() {
        $token = (string) get_option('myz_chatbot_sync_token', '');
        if (strlen($token) < 24) {
            $token = wp_generate_password(40, false, false);
            update_option('myz_chatbot_sync_token', $token);
        }
        return $token;
    }

    /**
     * 学習ファイル 外部同期 AJAX（ログイン不要・トークン認証）
     * 同名(filename)の学習ファイルがあれば内容を置き換え、無ければ追加する。
     */
    public function ajax_sync_knowledge() {
        $token = isset($_POST['token']) ? (string) wp_unslash($_POST['token']) : '';
        $expected = (string) get_option('myz_chatbot_sync_token', '');
        if (strlen($expected) < 24 || !hash_equals($expected, $token)) {
            status_header(403);
            wp_send_json_error(['message' => 'トークンが一致しません。']);
        }

        $filename = isset($_POST['filename']) ? sanitize_file_name(wp_unslash($_POST['filename'])) : '';
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($filename === '' || !in_array($ext, ['txt', 'md', 'markdown'], true)) {
            wp_send_json_error(['message' => 'filename は .txt / .md のみ対応です。']);
        }
        $content = isset($_POST['content']) ? (string) wp_unslash($_POST['content']) : '';
        $content = str_replace("\r\n", "\n", $content);
        if (trim($content) === '') {
            wp_send_json_error(['message' => 'content が空です。']);
        }
        if (strlen($content) > 512 * 1024) {
            wp_send_json_error(['message' => 'content が大きすぎます（512KB以内）。']);
        }

        global $wpdb;
        $table = $wpdb->prefix . 'myz_knowledge';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, content FROM $table WHERE source_type = 'file' AND filename = %s ORDER BY id ASC", $filename
        ));
        $now = current_time('mysql');
        $size = strlen($content);

        if (!empty($rows)) {
            $first = array_shift($rows);
            // 重複（同名が複数）は最初の1件に寄せる
            foreach ($rows as $dup) {
                $wpdb->delete($table, ['id' => $dup->id], ['%d']);
            }
            if ($first->content === $content) {
                wp_send_json_success(['result' => 'unchanged', 'id' => (int) $first->id, 'chars' => mb_strlen($content)]);
            }
            $ok = $wpdb->update($table,
                ['content' => $content, 'file_size' => $size, 'updated_at' => $now],
                ['id' => $first->id], ['%s', '%d', '%s'], ['%d']);
            if ($ok === false) {
                wp_send_json_error(['message' => 'DB更新に失敗: ' . $wpdb->last_error]);
            }
            wp_send_json_success(['result' => 'updated', 'id' => (int) $first->id, 'chars' => mb_strlen($content)]);
        }

        $ok = $wpdb->insert($table, [
            'url'         => 'sync://' . $filename,
            'source_type' => 'file',
            'filename'    => $filename,
            'file_size'   => $size,
            'content'     => $content,
            'updated_at'  => $now,
        ], ['%s', '%s', '%s', '%d', '%s', '%s']);
        if ($ok === false) {
            wp_send_json_error(['message' => 'DB保存に失敗: ' . $wpdb->last_error]);
        }
        wp_send_json_success(['result' => 'inserted', 'id' => (int) $wpdb->insert_id, 'chars' => mb_strlen($content)]);
    }

    /**
     * 学習ファイル 削除 AJAX
     */
    public function ajax_delete_knowledge_file() {
        check_ajax_referer('myz_file_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => '権限がありません。']);
        }
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        if ($id <= 0) {
            wp_send_json_error(['message' => 'IDが不正です。']);
        }
        global $wpdb;
        $table = $wpdb->prefix . 'myz_knowledge';
        $row = $wpdb->get_row($wpdb->prepare("SELECT url, source_type FROM $table WHERE id = %d", $id));
        if (!$row) wp_send_json_error(['message' => '対象が見つかりません。']);

        if ($row->source_type === 'file' && strpos($row->url, 'file://') === 0) {
            $basename = substr($row->url, 7);
            // パストラバーサル防止
            $basename = basename($basename);
            $path = self::get_upload_dir() . '/' . $basename;
            if (file_exists($path)) @unlink($path);
        }
        $wpdb->delete($table, ['id' => $id], ['%d']);
        wp_send_json_success(['id' => $id]);
    }

    /**
     * 設定ページ
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $urls = get_option('myz_chatbot_urls', []);
        $urls_text = is_array($urls) ? implode("\n", $urls) : '';
        $last_scraped = get_option('myz_chatbot_last_scraped', '未実行');
        $frequency = get_option('myz_chatbot_scrape_frequency', 'weekly');
        $default_instructions = "プレーンテキストで回答してください。Markdownの記号（#, *, **など）は絶対に使わないでください。\n改行を適切に入れて読みやすくしてください。\n箇条書きには「・」を使ってください。\n回答は200文字以内を目安に簡潔にお願いします。";

        // ナレッジDB状況
        global $wpdb;
        $knowledge_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}myz_knowledge");
        $url_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}myz_knowledge WHERE source_type = 'url' OR source_type = ''");
        $file_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}myz_knowledge WHERE source_type = 'file'");
        $file_rows = $wpdb->get_results("SELECT id, filename, file_size, updated_at, CHAR_LENGTH(content) AS chars FROM {$wpdb->prefix}myz_knowledge WHERE source_type = 'file' ORDER BY updated_at DESC");
        ?>
        <div class="wrap">
            <h1>MYZ AI Chatbot 設定</h1>
            <p><a href="<?php echo admin_url('admin.php?page=myz-chat-logs'); ?>" class="button button-secondary" style="margin-bottom:12px;">📊 チャット履歴・カテゴリ別分析を見る</a></p>
            <form method="post" action="options.php">
                <?php settings_fields('myz_chatbot_settings'); ?>

                <h2>AI設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">使用するAI</th>
                        <td>
                            <?php $provider = get_option('myz_chatbot_ai_provider', 'claude'); ?>
                            <fieldset style="display:flex; gap:12px; flex-wrap:wrap;">
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:12px 20px; border:2px solid <?php echo $provider === 'claude' ? '#D97706' : '#ddd'; ?>; border-radius:10px; background:<?php echo $provider === 'claude' ? '#FFFBEB' : '#fff'; ?>;">
                                    <input type="radio" name="myz_chatbot_ai_provider" value="claude" <?php checked($provider, 'claude'); ?> onchange="myzToggleProvider()" />
                                    <span style="font-size:18px;">🟠</span>
                                    <div>
                                        <strong>Claude</strong><br>
                                        <span style="font-size:12px; color:#666;">Anthropic</span>
                                    </div>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:12px 20px; border:2px solid <?php echo $provider === 'chatgpt' ? '#10A37F' : '#ddd'; ?>; border-radius:10px; background:<?php echo $provider === 'chatgpt' ? '#F0FDF9' : '#fff'; ?>;">
                                    <input type="radio" name="myz_chatbot_ai_provider" value="chatgpt" <?php checked($provider, 'chatgpt'); ?> onchange="myzToggleProvider()" />
                                    <span style="font-size:18px;">🟢</span>
                                    <div>
                                        <strong>ChatGPT</strong><br>
                                        <span style="font-size:12px; color:#666;">OpenAI</span>
                                    </div>
                                </label>
                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; padding:12px 20px; border:2px solid <?php echo $provider === 'gemini' ? '#4285F4' : '#ddd'; ?>; border-radius:10px; background:<?php echo $provider === 'gemini' ? '#EFF6FF' : '#fff'; ?>;">
                                    <input type="radio" name="myz_chatbot_ai_provider" value="gemini" <?php checked($provider, 'gemini'); ?> onchange="myzToggleProvider()" />
                                    <span style="font-size:18px;">🔵</span>
                                    <div>
                                        <strong>Gemini</strong><br>
                                        <span style="font-size:12px; color:#666;">Google</span>
                                    </div>
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                </table>

                <!-- Claude設定 -->
                <div id="myz-provider-claude" class="myz-provider-section" style="<?php echo $provider !== 'claude' ? 'display:none;' : ''; ?>">
                    <h3 style="margin-top:0;">🟠 Claude 設定</h3>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row">API キー</th>
                            <td>
                                <input type="password" name="myz_chatbot_api_key"
                                       value="<?php echo esc_attr(get_option('myz_chatbot_api_key')); ?>"
                                       class="regular-text" autocomplete="off" />
                                <p class="description"><a href="https://console.anthropic.com/settings/keys" target="_blank">Anthropic Consoleでキーを取得 →</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">モデル</th>
                            <td>
                                <?php $claude_model = get_option('myz_chatbot_claude_model', 'claude-sonnet-4-5-20250929'); ?>
                                <select name="myz_chatbot_claude_model">
                                    <option value="claude-sonnet-4-5-20250929" <?php selected($claude_model, 'claude-sonnet-4-5-20250929'); ?>>Claude Sonnet 4.5（推奨・バランス型）</option>
                                    <option value="claude-haiku-4-5-20250929" <?php selected($claude_model, 'claude-haiku-4-5-20250929'); ?>>Claude Haiku 4.5（高速・低コスト）</option>
                                    <option value="claude-opus-4-5-20250929" <?php selected($claude_model, 'claude-opus-4-5-20250929'); ?>>Claude Opus 4.5（高性能・高コスト）</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- ChatGPT設定 -->
                <div id="myz-provider-chatgpt" class="myz-provider-section" style="<?php echo $provider !== 'chatgpt' ? 'display:none;' : ''; ?>">
                    <h3 style="margin-top:0;">🟢 ChatGPT 設定</h3>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row">API キー</th>
                            <td>
                                <input type="password" name="myz_chatbot_openai_api_key"
                                       value="<?php echo esc_attr(get_option('myz_chatbot_openai_api_key')); ?>"
                                       class="regular-text" autocomplete="off" />
                                <p class="description"><a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platformでキーを取得 →</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">モデル</th>
                            <td>
                                <?php $openai_model = get_option('myz_chatbot_openai_model', 'gpt-4o-mini'); ?>
                                <select name="myz_chatbot_openai_model">
                                    <option value="gpt-4o-mini" <?php selected($openai_model, 'gpt-4o-mini'); ?>>GPT-4o mini（推奨・低コスト）</option>
                                    <option value="gpt-4o" <?php selected($openai_model, 'gpt-4o'); ?>>GPT-4o（高性能）</option>
                                    <option value="gpt-4.1" <?php selected($openai_model, 'gpt-4.1'); ?>>GPT-4.1（最新）</option>
                                    <option value="gpt-4.1-mini" <?php selected($openai_model, 'gpt-4.1-mini'); ?>>GPT-4.1 mini（最新・低コスト）</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <!-- Gemini設定 -->
                <div id="myz-provider-gemini" class="myz-provider-section" style="<?php echo $provider !== 'gemini' ? 'display:none;' : ''; ?>">
                    <h3 style="margin-top:0;">🔵 Gemini 設定</h3>
                    <table class="form-table" style="margin-top:0;">
                        <tr>
                            <th scope="row">API キー</th>
                            <td>
                                <input type="password" name="myz_chatbot_gemini_api_key"
                                       value="<?php echo esc_attr(get_option('myz_chatbot_gemini_api_key')); ?>"
                                       class="regular-text" autocomplete="off" />
                                <p class="description"><a href="https://aistudio.google.com/apikey" target="_blank">Google AI Studioでキーを取得 →</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">モデル</th>
                            <td>
                                <?php $gemini_model = get_option('myz_chatbot_gemini_model', 'gemini-3.8-flash'); ?>
                                <select name="myz_chatbot_gemini_model">
                                    <option value="gemini-3.8-flash" <?php selected($gemini_model, 'gemini-3.8-flash'); ?>>Gemini 3.8 Flash（推奨・最新）</option>
                                    <option value="gemini-3.5-flash" <?php selected($gemini_model, 'gemini-3.5-flash'); ?>>Gemini 3.5 Flash（前世代・高速）</option>
                                    <option value="gemini-3.1-pro-preview" <?php selected($gemini_model, 'gemini-3.1-pro-preview'); ?>>Gemini 3.1 Pro（最高性能・応答遅め）</option>
                                    <option value="gemini-3.5-flash-lite" <?php selected($gemini_model, 'gemini-3.5-flash-lite'); ?>>Gemini 3.5 Flash Lite（最速・低コスト）</option>
                                    <option value="gemini-3-flash-preview" <?php selected($gemini_model, 'gemini-3-flash-preview'); ?>>Gemini 3 Flash（プレビュー）</option>
                                    <option value="gemini-flash-latest" <?php selected($gemini_model, 'gemini-flash-latest'); ?>>Gemini Flash 最新版（自動追従）</option>
                                    <option value="gemini-pro-latest" <?php selected($gemini_model, 'gemini-pro-latest'); ?>>Gemini Pro 最新版（自動追従）</option>
                                    <option value="gemini-2.5-pro" <?php selected($gemini_model, 'gemini-2.5-pro'); ?>>Gemini 2.5 Pro（旧キーのみ・新規キーでは利用不可）</option>
                                    <option value="gemini-2.5-flash" <?php selected($gemini_model, 'gemini-2.5-flash'); ?>>Gemini 2.5 Flash（旧キーのみ・新規キーでは利用不可）</option>
                                    <option value="gemini-2.0-flash" <?php selected($gemini_model, 'gemini-2.0-flash'); ?>>Gemini 2.0 Flash（旧世代）</option>
                                </select>
                                <p class="description">※2026年以降に発行した新形式キー（AQ.〜）ではGemini 3系のみ利用可能です。</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <script>
                function myzToggleProvider() {
                    var selected = document.querySelector('input[name="myz_chatbot_ai_provider"]:checked').value;
                    document.querySelectorAll('.myz-provider-section').forEach(function(el) { el.style.display = 'none'; });
                    var target = document.getElementById('myz-provider-' + selected);
                    if (target) target.style.display = '';
                }
                </script>

                <h2>基本設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">チャットボットを有効化</th>
                        <td>
                            <label>
                                <input type="checkbox" name="myz_chatbot_enabled" value="1"
                                    <?php checked(get_option('myz_chatbot_enabled', '1'), '1'); ?> />
                                フロントエンドにチャットウィジェットを表示する
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">AIへの追加指示</th>
                        <td>
                            <textarea name="myz_chatbot_extra_instructions" rows="6" class="large-text"
                                ><?php echo esc_textarea(get_option('myz_chatbot_extra_instructions', $default_instructions)); ?></textarea>
                            <p class="description">AIの回答スタイルについての指示。例：「敬語で回答」「料金の質問にはお問い合わせを促す」</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">お問い合わせフォームURL</th>
                        <td>
                            <?php $detected_contact = $this->detect_contact_url(); ?>
                            <input type="url" name="myz_chatbot_contact_url" class="large-text"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_contact_url', '')); ?>"
                                   placeholder="<?php echo esc_attr($detected_contact ?: home_url('/contact/')); ?>" />
                            <p class="description">
                                サイト内のチャットで問い合わせ先を案内するとき、メールアドレスの代わりにこのフォームへ誘導します。
                                空欄なら固定ページから自動検出します<?php if ($detected_contact): ?>（現在の検出結果: <a href="<?php echo esc_url($detected_contact); ?>" target="_blank"><?php echo esc_html($detected_contact); ?></a>）<?php else: ?>（<strong style="color:#c00;">検出できませんでした。URLを入力してください</strong>）<?php endif; ?>。<br>
                                客室QR（スタンドアロン）ページはこの設定に関係なく「ご予約いただいたサイトのメッセージ」へ誘導します。
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>外観設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">開くボタンの文言</th>
                        <td>
                            <input type="text" name="myz_chatbot_toggle_text"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_toggle_text', 'AIに質問')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">開くボタンのアイコン</th>
                        <td>
                            <?php $toggle_icon = get_option('myz_chatbot_toggle_icon', 'chat'); ?>
                            <fieldset style="display:flex; gap:16px; flex-wrap:wrap;">
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_icon === 'chat' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_icon" value="chat" <?php checked($toggle_icon, 'chat'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                    チャット
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_icon === 'question' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_icon" value="question" <?php checked($toggle_icon, 'question'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/></svg>
                                    はてな
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_icon === 'headset' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_icon" value="headset" <?php checked($toggle_icon, 'headset'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>
                                    ヘッドセット
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_icon === 'robot' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_icon" value="robot" <?php checked($toggle_icon, 'robot'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 2v6"/><circle cx="9" cy="14" r="1.5" fill="currentColor"/><circle cx="15" cy="14" r="1.5" fill="currentColor"/></svg>
                                    ロボット
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ヘッダーの文言</th>
                        <td>
                            <input type="text" name="myz_chatbot_header_text"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_header_text', 'AIに質問')); ?>"
                                   class="regular-text" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ヘッダーの文言（言語別）<br><span style="font-weight:normal;font-size:12px;color:#666;">多言語サイト用。空欄なら上の文言を使います</span></th>
                        <td>
                            <?php foreach ($this->lang_labels(true) as $hl => $hl_label) : ?>
                                <p style="margin:0 0 8px;">
                                    <label style="display:inline-block;width:5em;"><?php echo esc_html($hl_label); ?></label>
                                    <input type="text" name="myz_chatbot_header_text_<?php echo esc_attr($hl); ?>"
                                           value="<?php echo esc_attr(get_option('myz_chatbot_header_text_' . $hl, '')); ?>"
                                           class="regular-text" />
                                </p>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ヘッダーのアイコン</th>
                        <td>
                            <?php $header_icon = get_option('myz_chatbot_header_icon', 'chat'); ?>
                            <fieldset style="display:flex; gap:12px; flex-wrap:wrap;">
                                <?php
                                $icon_options = [
                                    'chat'     => ['emoji' => '💬', 'label' => 'チャット'],
                                    'robot'    => ['emoji' => '🤖', 'label' => 'ロボット'],
                                    'operator' => ['emoji' => '👩‍💼', 'label' => 'オペレーター'],
                                    'house'    => ['emoji' => '🏠', 'label' => '家'],
                                    'target'   => ['emoji' => '🎯', 'label' => 'ターゲット'],
                                    'sparkle'  => ['emoji' => '✨', 'label' => 'キラキラ'],
                                    'phone'    => ['emoji' => '📞', 'label' => '電話'],
                                    'bulb'     => ['emoji' => '💡', 'label' => '電球'],
                                    'headset'  => ['emoji' => '🎧', 'label' => 'ヘッドセット'],
                                ];
                                foreach ($icon_options as $key => $opt):
                                ?>
                                <label style="display:flex; align-items:center; gap:4px; cursor:pointer; padding:6px 10px; border:2px solid <?php echo $header_icon === $key ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_header_icon" value="<?php echo esc_attr($key); ?>" <?php checked($header_icon, $key); ?> />
                                    <span style="font-size:20px;"><?php echo $opt['emoji']; ?></span>
                                    <span style="font-size:12px;"><?php echo esc_html($opt['label']); ?></span>
                                </label>
                                <?php endforeach; ?>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">テーマカラー</th>
                        <td>
                            <input type="color" name="myz_chatbot_primary_color"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_primary_color', '#159BBE')); ?>"
                                   style="width:60px; height:40px; border:none; cursor:pointer;" />
                            <input type="text" id="myz-color-text" name="myz_chatbot_primary_color_text"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_primary_color', '#159BBE')); ?>"
                                   class="small-text" style="margin-left:8px;" />
                            <p class="description">ボタン、ヘッダー、ユーザー吹き出しの色を一括変更します。</p>
                            <script>
                            (function(){
                                var cp = document.querySelector('input[name="myz_chatbot_primary_color"]');
                                var tx = document.getElementById('myz-color-text');
                                cp.addEventListener('input', function(){ tx.value = cp.value; });
                                tx.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(tx.value)) cp.value = tx.value; });
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">文字・アイコンの色</th>
                        <td>
                            <?php $text_color = get_option('myz_chatbot_text_color', '#ffffff'); ?>
                            <input type="color" name="myz_chatbot_text_color"
                                   value="<?php echo esc_attr($text_color); ?>"
                                   style="width:60px; height:40px; border:none; cursor:pointer;" />
                            <input type="text" id="myz-text-color-text"
                                   value="<?php echo esc_attr($text_color); ?>"
                                   class="small-text" style="margin-left:8px;" />
                            <p class="description">ボタンの文字、ヘッダーの文字、送信ボタンのアイコンの色を一括変更します。</p>
                            <div style="margin-top:12px; display:flex; gap:16px;">
                                <div style="text-align:center;">
                                    <div id="myz-text-preview" style="background:<?php echo esc_attr(get_option('myz_chatbot_primary_color', '#159BBE')); ?>; color:<?php echo esc_attr($text_color); ?>; padding:10px 20px; border-radius:50px; font-size:14px; font-weight:600;">プレビュー</div>
                                </div>
                            </div>
                            <script>
                            (function(){
                                var cp = document.querySelector('input[name="myz_chatbot_text_color"]');
                                var tx = document.getElementById('myz-text-color-text');
                                var pv = document.getElementById('myz-text-preview');
                                cp.addEventListener('input', function(){ tx.value = cp.value; pv.style.color = cp.value; });
                                tx.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(tx.value)){ cp.value = tx.value; pv.style.color = tx.value; }});
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">フォント</th>
                        <td>
                            <?php
                            $font_family = get_option('myz_chatbot_font_family', 'system');
                            $font_weight = get_option('myz_chatbot_font_weight', '600');
                            $font_size = get_option('myz_chatbot_font_size', '15');
                            ?>
                            <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:flex-start;">
                                <div>
                                    <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">書体</label>
                                    <select name="myz_chatbot_font_family" id="myz-font-family" style="min-width:200px;">
                                        <option value="system" <?php selected($font_family, 'system'); ?>>システム標準</option>
                                        <option value="gothic" <?php selected($font_family, 'gothic'); ?>>ゴシック体</option>
                                        <option value="mincho" <?php selected($font_family, 'mincho'); ?>>明朝体</option>
                                        <option value="maru" <?php selected($font_family, 'maru'); ?>>丸ゴシック</option>
                                        <option value="times" <?php selected($font_family, 'times'); ?>>Times New Roman</option>
                                        <option value="mono" <?php selected($font_family, 'mono'); ?>>等幅フォント</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">太さ</label>
                                    <select name="myz_chatbot_font_weight" id="myz-font-weight" style="min-width:140px;">
                                        <option value="300" <?php selected($font_weight, '300'); ?>>細い（Light）</option>
                                        <option value="400" <?php selected($font_weight, '400'); ?>>標準（Regular）</option>
                                        <option value="600" <?php selected($font_weight, '600'); ?>>やや太い（Semi Bold）</option>
                                        <option value="700" <?php selected($font_weight, '700'); ?>>太い（Bold）</option>
                                    </select>
                                </div>
                                <div>
                                    <label style="font-weight:600; font-size:13px; display:block; margin-bottom:6px;">サイズ</label>
                                    <select name="myz_chatbot_font_size" id="myz-font-size" style="min-width:120px;">
                                        <option value="12" <?php selected($font_size, '12'); ?>>12px（小）</option>
                                        <option value="13" <?php selected($font_size, '13'); ?>>13px</option>
                                        <option value="14" <?php selected($font_size, '14'); ?>>14px</option>
                                        <option value="15" <?php selected($font_size, '15'); ?>>15px（標準）</option>
                                        <option value="16" <?php selected($font_size, '16'); ?>>16px</option>
                                        <option value="17" <?php selected($font_size, '17'); ?>>17px</option>
                                        <option value="18" <?php selected($font_size, '18'); ?>>18px（大）</option>
                                    </select>
                                </div>
                            </div>
                            <div id="myz-font-preview" style="margin-top:16px; padding:16px; background:#f0f4f8; border-radius:12px; font-size:<?php echo esc_attr($font_size); ?>px; font-weight:<?php echo esc_attr($font_weight); ?>;">
                                こんにちは！マイズインバウンドのAIアシスタントです。<br>サービス内容や料金など、お気軽にご質問ください。
                            </div>
                            <script>
                            (function(){
                                var pv = document.getElementById('myz-font-preview');
                                var fonts = {
                                    system: '-apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Noto Sans JP", sans-serif',
                                    gothic: '"Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", "Meiryo", sans-serif',
                                    mincho: '"Hiragino Mincho ProN", "Noto Serif JP", "Yu Mincho", "MS PMincho", serif',
                                    maru: '"Hiragino Maru Gothic ProN", "Kosugi Maru", "Yu Gothic", sans-serif',
                                    mono: '"SF Mono", "Hiragino Kaku Gothic ProN", "Courier New", monospace'
                                };
                                document.getElementById('myz-font-family').addEventListener('change', function(){ pv.style.fontFamily = fonts[this.value] || fonts.system; });
                                document.getElementById('myz-font-weight').addEventListener('change', function(){ pv.style.fontWeight = this.value; });
                                document.getElementById('myz-font-size').addEventListener('change', function(){ pv.style.fontSize = this.value + 'px'; });
                            })();
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">送信ボタンのアイコン</th>
                        <td>
                            <?php $send_icon = get_option('myz_chatbot_send_icon', 'paper-plane'); ?>
                            <fieldset style="display:flex; gap:16px; flex-wrap:wrap;">
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $send_icon === 'paper-plane' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_send_icon" value="paper-plane" <?php checked($send_icon, 'paper-plane'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                                    紙飛行機
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $send_icon === 'arrow-up' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_send_icon" value="arrow-up" <?php checked($send_icon, 'arrow-up'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
                                    上矢印
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $send_icon === 'arrow-right' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_send_icon" value="arrow-right" <?php checked($send_icon, 'arrow-right'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                                    右矢印
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $send_icon === 'send-circle' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_send_icon" value="send-circle" <?php checked($send_icon, 'send-circle'); ?> />
                                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M16 12l-4-4v8l4-4z" fill="currentColor"/></svg>
                                    再生
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">表示位置</th>
                        <td>
                            <?php $position = get_option('myz_chatbot_position', 'left'); ?>
                            <fieldset style="display:flex; gap:16px; flex-wrap:wrap;">
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $position === 'left' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_position" value="left" <?php checked($position, 'left'); ?> />
                                    ⬅️ 左下
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 16px; border:2px solid <?php echo $position === 'right' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_position" value="right" <?php checked($position, 'right'); ?> />
                                    ➡️ 右下
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ボタンのサイズ</th>
                        <td>
                            <?php $toggle_size = get_option('myz_chatbot_toggle_size', 'medium'); ?>
                            <fieldset style="display:flex; gap:16px; flex-wrap:wrap;">
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_size === 'small' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_size" value="small" <?php checked($toggle_size, 'small'); ?> /> 小
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_size === 'medium' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_size" value="medium" <?php checked($toggle_size, 'medium'); ?> /> 中（デフォルト）
                                </label>
                                <label style="display:flex; align-items:center; gap:6px; cursor:pointer; padding:8px 12px; border:2px solid <?php echo $toggle_size === 'large' ? '#159BBE' : '#ddd'; ?>; border-radius:8px;">
                                    <input type="radio" name="myz_chatbot_toggle_size" value="large" <?php checked($toggle_size, 'large'); ?> /> 大
                                </label>
                            </fieldset>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ボタンの丸み</th>
                        <td>
                            <?php $toggle_radius = get_option('myz_chatbot_toggle_radius', '50'); ?>
                            <input type="range" name="myz_chatbot_toggle_radius" id="myz-radius-range"
                                   min="0" max="50" value="<?php echo esc_attr($toggle_radius); ?>"
                                   style="width:300px; vertical-align:middle;" />
                            <span id="myz-radius-value" style="margin-left:8px; font-weight:600;"><?php echo esc_attr($toggle_radius); ?>px</span>
                            <div style="display:flex; gap:24px; margin-top:12px;">
                                <div style="text-align:center;">
                                    <div style="width:120px; height:44px; background:#159BBE; border-radius:0px;"></div>
                                    <span style="font-size:11px; color:#999;">0px（角ばり）</span>
                                </div>
                                <div style="text-align:center;">
                                    <div style="width:120px; height:44px; background:#159BBE; border-radius:12px;"></div>
                                    <span style="font-size:11px; color:#999;">12px（角丸）</span>
                                </div>
                                <div style="text-align:center;">
                                    <div style="width:120px; height:44px; background:#159BBE; border-radius:50px;"></div>
                                    <span style="font-size:11px; color:#999;">50px（カプセル）</span>
                                </div>
                            </div>
                            <script>
                            document.getElementById('myz-radius-range').addEventListener('input', function(){
                                document.getElementById('myz-radius-value').textContent = this.value + 'px';
                            });
                            </script>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">初期メッセージ</th>
                        <td>
                            <?php
                            $default_welcome_msg = "こんにちは！AIアシスタントです。サービス内容や料金など、お気軽にご質問ください。\n\nHello! I'm your AI assistant. Please feel free to ask me any questions about our services, pricing, or anything else.";
                            ?>
                            <textarea name="myz_chatbot_welcome_message" rows="3" class="large-text"
                                ><?php echo esc_textarea(get_option('myz_chatbot_welcome_message', $default_welcome_msg)); ?></textarea>
                            <p class="description">チャットを開いたときに最初に表示されるメッセージです（共通・フォールバック用）。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">言語別初期メッセージ</th>
                        <td>
                            <?php
                            foreach ($this->lang_labels() as $lang_code => $lang_label) :
                            ?>
                            <p style="margin:8px 0 2px;"><strong><?php echo $lang_label; ?></strong></p>
                            <textarea name="myz_chatbot_welcome_message_<?php echo $lang_code; ?>" rows="2" class="large-text"
                                ><?php echo esc_textarea(get_option('myz_chatbot_welcome_message_' . $lang_code, '')); ?></textarea>
                            <?php endforeach; ?>
                            <p class="description">サイト内のウィジェットはページURLの言語（/zh-〜, /ko-〜, /en/・〜-en, /it/, /de/, /fr/, /es/、それ以外は日本語）、<strong>QRコードのスタンドアロンページは視聴者の端末（スマホ）の言語設定</strong>に応じて表示されます。日本語・英語以外が空欄の場合は同じ言語の汎用メッセージを自動で表示します（日本語のままにはなりません）。日本語・英語が空欄の場合は上の共通メッセージを使います。</p>
                        </td>
                    </tr>
                </table>

                <h2>学習ページ設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">学習対象URL</th>
                        <td>
                            <textarea name="myz_chatbot_urls" rows="10" class="large-text" placeholder="https://myzminpaku.com/&#10;https://myzminpaku.com/service/&#10;https://myzminpaku.com/price/"
                                ><?php echo esc_textarea($urls_text); ?></textarea>
                            <p class="description">AIに学習させるページのURLを1行に1つずつ入力してください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">自動更新頻度</th>
                        <td>
                            <select name="myz_chatbot_scrape_frequency">
                                <option value="weekly" <?php selected($frequency, 'weekly'); ?>>週1回</option>
                                <option value="myz_monthly" <?php selected($frequency, 'myz_monthly'); ?>>月1回</option>
                                <option value="daily" <?php selected($frequency, 'daily'); ?>>毎日</option>
                                <option value="manual" <?php selected($frequency, 'manual'); ?>>手動のみ</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ステータス</th>
                        <td>
                            <p>学習済み件数: <strong><?php echo $knowledge_count; ?></strong>（URL: <?php echo $url_count; ?> / ファイル: <?php echo $file_count; ?>）</p>
                            <p>最終更新: <strong id="myz-last-scraped"><?php echo esc_html($last_scraped); ?></strong></p>
                            <button type="button" id="myz-scrape-btn" class="button button-secondary">今すぐ更新</button>
                            <span id="myz-scrape-status" style="margin-left:12px;"></span>
                        </td>
                    </tr>
                </table>

                <h2>ファイル学習（Excel / PDF / Word / CSV / TXT）</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">ファイルをアップロード</th>
                        <td>
                            <div id="myz-file-drop" style="border:2px dashed #b4c1d4; border-radius:10px; padding:24px; text-align:center; background:#f8fafc; cursor:pointer; transition:all 0.2s;">
                                <div style="font-size:36px; line-height:1; margin-bottom:8px;">📄</div>
                                <p style="margin:6px 0 4px 0; font-weight:600; color:#333;">ファイルをドラッグ＆ドロップ または クリックして選択</p>
                                <p style="margin:0; font-size:12px; color:#666;">対応: PDF / Excel(.xlsx) / Word(.docx) / CSV / TXT / Markdown（最大10MB）</p>
                                <input type="file" id="myz-file-input" accept=".pdf,.xlsx,.docx,.csv,.txt,.md,.markdown" multiple style="display:none;" />
                            </div>
                            <div id="myz-file-status" style="margin-top:10px;"></div>
                            <p class="description" style="margin-top:8px;">アップロードしたファイルから自動でテキストを抽出し、AIの学習データに追加します。<br>※ PDFは画像化されたものや一部の日本語フォントは抽出できない場合があります。その場合はTXTに変換してアップロードしてください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">アップロード済みファイル</th>
                        <td>
                            <table id="myz-file-list" class="widefat striped" style="max-width:900px;">
                                <thead>
                                    <tr>
                                        <th style="width:40%;">ファイル名</th>
                                        <th style="width:12%;">形式</th>
                                        <th style="width:13%;">サイズ</th>
                                        <th style="width:13%;">抽出文字数</th>
                                        <th style="width:14%;">登録日時</th>
                                        <th style="width:8%;">操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if (empty($file_rows)): ?>
                                    <tr id="myz-file-empty"><td colspan="6" style="text-align:center; color:#888; padding:16px;">まだファイルがアップロードされていません</td></tr>
                                <?php else: foreach ($file_rows as $r):
                                    $ext_upper = strtoupper(pathinfo($r->filename, PATHINFO_EXTENSION));
                                    $size_kb = $r->file_size > 0 ? number_format($r->file_size / 1024, 1) . ' KB' : '-';
                                ?>
                                    <tr data-id="<?php echo (int)$r->id; ?>">
                                        <td><?php echo esc_html($r->filename); ?></td>
                                        <td><?php echo esc_html($ext_upper); ?></td>
                                        <td><?php echo esc_html($size_kb); ?></td>
                                        <td><?php echo number_format((int)$r->chars); ?> 文字</td>
                                        <td><?php echo esc_html($r->updated_at); ?></td>
                                        <td><button type="button" class="button button-small myz-delete-file" data-id="<?php echo (int)$r->id; ?>">削除</button></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">外部同期トークン</th>
                        <td>
                            <input type="text" name="myz_chatbot_sync_token" class="regular-text code"
                                   value="<?php echo esc_attr($this->get_sync_token()); ?>" />
                            <p class="description">
                                外部のジョブ（宿シートの自動投入など）が学習ファイルをログインなしで更新するための合言葉。<br>
                                <code>POST <?php echo esc_html(admin_url('admin-ajax.php')); ?></code>
                                に <code>action=myz_sync_knowledge</code> / <code>token</code> / <code>filename</code>（.txt/.md）/ <code>content</code> を送ると、同名の学習ファイルを置き換えます（無ければ追加）。
                                空にして保存すると新しいトークンを自動生成します。
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>スタンドアロンページ（QRコード用）</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">ページURL</th>
                        <td>
                            <?php
                            $sa_slug = $this->get_standalone_slug();
                            $sa_url = home_url('/' . $sa_slug . '/');
                            ?>
                            <code style="font-size:15px; padding:8px 12px; background:#f0f4f8; border-radius:6px; display:inline-block;">
                                <a href="<?php echo esc_url($sa_url); ?>" target="_blank"><?php echo esc_html($sa_url); ?></a>
                            </code>
                            <p class="description" style="margin-top:8px;">このURLにアクセスすると、チャットボットだけの全画面ページが表示されます。<br>初期メッセージ・タイトル・入力欄は<strong>ゲストの端末の言語設定（日本語 / English / 中文 / 한국어 / Italiano / Deutsch / Français / Español）を自動判定</strong>して切り替わります。言語を固定したQRを配る場合は <code>?lang=en</code> （ja / en / zh / ko / it / de / fr / es）を付けてください。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">URLスラグ</th>
                        <td>
                            <input type="text" name="myz_chatbot_standalone_slug"
                                   value="<?php echo esc_attr($sa_slug); ?>"
                                   class="regular-text" placeholder="chatbot" />
                            <p class="description">URLの末尾部分を変更できます。例: <code>chatbot</code> → <?php echo home_url('/chatbot/'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">クイック質問ボタン</th>
                        <td>
                            <textarea name="myz_chatbot_quick_buttons" rows="9" class="large-text" placeholder="<?php echo esc_attr(self::quick_buttons_default_text()); ?>"><?php echo esc_textarea(get_option('myz_chatbot_quick_buttons', '')); ?></textarea>
                            <p class="description">QRページの初期メッセージの下に並ぶ、タップするだけで質問できるボタンです。1行1ボタン、<code>ボタン名 | 送る質問文</code>（「|」以降を省略するとボタン名がそのまま質問になります）。<strong>空欄なら共通の7ボタン</strong>（チェックイン方法・駐車場・アメニティ・Wi-Fi・ゴミの出し方・荷物預かり・周辺情報）を表示します。宿ごとのサービス（食器レンタル・自転車レンタル・サウナ・三線教室など）を足すときは、上の7行をコピーしたうえで行を追加してください。日本語で書けば保存時にAIが7言語へ自動翻訳します（共通7ボタンは辞書で即時）。最大20個。</p>
                            <?php
                            $qb_i18n = get_option('myz_chatbot_quick_buttons_i18n', '');
                            $qb_i18n = $qb_i18n ? json_decode($qb_i18n, true) : null;
                            if (is_array($qb_i18n) && !empty($qb_i18n['_error'])): ?>
                            <p style="color:#c00; margin:6px 0;">自動翻訳に失敗しました: <?php echo esc_html($qb_i18n['_error']); ?>（該当ボタンは日本語のまま表示されます。設定を少し変えて保存し直すと再試行します）</p>
                            <?php endif; ?>
                            <?php if (is_array($qb_i18n) && !empty($qb_i18n['en'])): ?>
                            <details style="margin-top:6px;"><summary style="cursor:pointer;">翻訳プレビュー（English）</summary>
                                <ul style="margin:6px 0 0 18px;">
                                <?php foreach ($qb_i18n['en'] as $b): ?><li><strong><?php echo esc_html($b['l']); ?></strong> → <?php echo esc_html($b['q']); ?></li><?php endforeach; ?>
                                </ul>
                            </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">QRコード</th>
                        <td>
                            <img src="https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=<?php echo urlencode($sa_url); ?>"
                                 alt="QR Code" style="border:1px solid #e2e8f0; border-radius:8px; padding:8px; background:#fff;" />
                            <p class="description" style="margin-top:8px;">このQRコードを客室やチラシに印刷してご利用ください。<br>右クリック →「名前を付けて画像を保存」でダウンロードできます。</p>
                        </td>
                    </tr>
                </table>

                <h2>プラグイン更新設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">現在のバージョン</th>
                        <td>
                            <p><strong style="font-size:16px;">v<?php echo MYZ_CHATBOT_VERSION; ?></strong></p>
                            <button type="button" id="myz-check-update-btn" class="button button-secondary">更新をチェック</button>
                            <span id="myz-update-status" style="margin-left:12px;"></span>
                            <p class="description" style="margin-top:8px;">リポジトリ: <code>myzinbound/myz-ai-chatbot</code></p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('設定を保存'); ?>
            </form>
        </div>

        <script>
        // ファイル学習: アップロード/削除
        (function(){
            var drop = document.getElementById('myz-file-drop');
            var input = document.getElementById('myz-file-input');
            var status = document.getElementById('myz-file-status');
            var list = document.getElementById('myz-file-list');
            if (!drop || !input) return;

            var nonce = '<?php echo wp_create_nonce("myz_file_nonce"); ?>';
            var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';

            drop.addEventListener('click', function(){ input.click(); });
            drop.addEventListener('dragover', function(e){
                e.preventDefault();
                drop.style.background = '#eef6fb';
                drop.style.borderColor = '#159BBE';
            });
            drop.addEventListener('dragleave', function(){
                drop.style.background = '#f8fafc';
                drop.style.borderColor = '#b4c1d4';
            });
            drop.addEventListener('drop', function(e){
                e.preventDefault();
                drop.style.background = '#f8fafc';
                drop.style.borderColor = '#b4c1d4';
                if (e.dataTransfer && e.dataTransfer.files) {
                    uploadFiles(e.dataTransfer.files);
                }
            });
            input.addEventListener('change', function(){
                if (input.files) uploadFiles(input.files);
            });

            function setStatus(html, color) {
                status.innerHTML = html;
                status.style.color = color || '#333';
            }

            function escapeHtml(s) {
                return String(s).replace(/[&<>"']/g, function(c){
                    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
                });
            }

            function uploadFiles(files) {
                if (!files.length) return;
                var arr = Array.from(files);
                setStatus('アップロード中... (' + arr.length + 'ファイル)', '#666');
                var idx = 0;
                var results = [];

                function next() {
                    if (idx >= arr.length) {
                        var ok = results.filter(function(r){ return r.ok; }).length;
                        var ng = results.length - ok;
                        var msg = '✅ ' + ok + '件 成功';
                        if (ng > 0) msg += ' / ❌ ' + ng + '件 失敗';
                        var errors = results.filter(function(r){ return !r.ok; }).map(function(r){ return '・' + r.name + ': ' + r.msg; });
                        if (errors.length) msg += '<br>' + errors.join('<br>');
                        setStatus(msg, ng > 0 ? '#d63638' : '#00a32a');
                        input.value = '';
                        return;
                    }
                    var f = arr[idx++];
                    var fd = new FormData();
                    fd.append('action', 'myz_upload_knowledge_file');
                    fd.append('nonce', nonce);
                    fd.append('file', f);
                    fetch(ajaxUrl, { method: 'POST', body: fd })
                        .then(function(r){ return r.json(); })
                        .then(function(data){
                            if (data.success) {
                                results.push({ ok: true, name: f.name });
                                addRow(data.data);
                            } else {
                                results.push({ ok: false, name: f.name, msg: (data.data && data.data.message) || 'エラー' });
                            }
                            next();
                        })
                        .catch(function(){
                            results.push({ ok: false, name: f.name, msg: '通信エラー' });
                            next();
                        });
                }
                next();
            }

            function addRow(d) {
                var tbody = list.querySelector('tbody');
                var empty = document.getElementById('myz-file-empty');
                if (empty) empty.remove();
                var ext = (d.filename.split('.').pop() || '').toUpperCase();
                var sizeKb = d.size > 0 ? (d.size / 1024).toFixed(1) + ' KB' : '-';
                var tr = document.createElement('tr');
                tr.setAttribute('data-id', d.id);
                tr.innerHTML =
                    '<td>' + escapeHtml(d.filename) + (d.truncated ? ' <span style="color:#d97706; font-size:11px;">（上限到達）</span>' : '') + '</td>' +
                    '<td>' + escapeHtml(ext) + '</td>' +
                    '<td>' + sizeKb + '</td>' +
                    '<td>' + d.chars.toLocaleString() + ' 文字</td>' +
                    '<td>' + escapeHtml(d.updated) + '</td>' +
                    '<td><button type="button" class="button button-small myz-delete-file" data-id="' + d.id + '">削除</button></td>';
                tbody.insertBefore(tr, tbody.firstChild);
            }

            // 削除（イベントデリゲーション）
            list.addEventListener('click', function(e){
                var btn = e.target.closest('.myz-delete-file');
                if (!btn) return;
                if (!confirm('このファイルを削除してよろしいですか？')) return;
                var id = btn.getAttribute('data-id');
                btn.disabled = true;
                var fd = new FormData();
                fd.append('action', 'myz_delete_knowledge_file');
                fd.append('nonce', nonce);
                fd.append('id', id);
                fetch(ajaxUrl, { method: 'POST', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(data){
                        if (data.success) {
                            var row = list.querySelector('tr[data-id="' + id + '"]');
                            if (row) row.remove();
                            if (!list.querySelector('tbody tr')) {
                                var tbody = list.querySelector('tbody');
                                var tr = document.createElement('tr');
                                tr.id = 'myz-file-empty';
                                tr.innerHTML = '<td colspan="6" style="text-align:center; color:#888; padding:16px;">まだファイルがアップロードされていません</td>';
                                tbody.appendChild(tr);
                            }
                        } else {
                            alert('削除に失敗: ' + ((data.data && data.data.message) || '不明なエラー'));
                            btn.disabled = false;
                        }
                    })
                    .catch(function(){
                        alert('通信エラー');
                        btn.disabled = false;
                    });
            });
        })();

        // 更新チェック
        (function(){
            var btn = document.getElementById('myz-check-update-btn');
            if (!btn) return;
            btn.addEventListener('click', function(){
                btn.disabled = true;
                var status = document.getElementById('myz-update-status');
                status.textContent = 'チェック中...';
                status.style.color = '#666';
                var fd = new FormData();
                fd.append('action', 'myz_check_update');
                fd.append('nonce', '<?php echo wp_create_nonce("myz_update_nonce"); ?>');
                fetch('<?php echo admin_url("admin-ajax.php"); ?>', {method:'POST', body:fd})
                    .then(function(r){return r.json();})
                    .then(function(data){
                        btn.disabled = false;
                        if (data.success) {
                            if (data.data.has_update) {
                                status.innerHTML = '<span style="color:#d63638;">🔔 新バージョン v' + data.data.new_version + ' が利用可能です！</span> '
                                    + '<a href="' + data.data.update_url + '" class="button button-primary" style="margin-left:8px;">今すぐ更新</a>';
                            } else {
                                status.innerHTML = '<span style="color:#00a32a;">✅ 最新バージョンです（v' + data.data.current_version + '）</span>';
                            }
                        } else {
                            status.innerHTML = '<span style="color:#d63638;">❌ チェックに失敗しました。リポジトリ設定を確認してください。</span>';
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        status.innerHTML = '<span style="color:#d63638;">❌ 通信エラー</span>';
                    });
            });
        })();

        document.getElementById('myz-scrape-btn').addEventListener('click', function() {
            var btn = this;
            var status = document.getElementById('myz-scrape-status');
            btn.disabled = true;
            status.textContent = 'スクレイピング中...';
            status.style.color = '#666';

            var fd = new FormData();
            fd.append('action', 'myz_scrape_now');
            fd.append('nonce', '<?php echo wp_create_nonce("myz_scrape_nonce"); ?>');

            fetch('<?php echo admin_url("admin-ajax.php"); ?>', {method:'POST', body:fd})
                .then(function(r){return r.json();})
                .then(function(data){
                    btn.disabled = false;
                    if (data.success) {
                        status.textContent = data.data.count + 'ページを更新しました！';
                        status.style.color = '#159BBE';
                        document.getElementById('myz-last-scraped').textContent = data.data.time;
                    } else {
                        status.textContent = 'エラーが発生しました。';
                        status.style.color = '#d63638';
                    }
                })
                .catch(function(){
                    btn.disabled = false;
                    status.textContent = 'エラーが発生しました。';
                    status.style.color = '#d63638';
                });
        });
        </script>
        <?php
    }

    /**
     * 会話履歴ページ
     */
    public function render_logs_page() {
        if (!current_user_can('manage_options')) return;

        global $wpdb;
        $table = $wpdb->prefix . 'myz_chat_logs';

        // テーブル存在チェック
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if ($table_exists !== $table) {
            $this->create_tables();
        }

        // 表示期間・ページング（保存自体は無制限で、ここは表示の絞り込みだけ）
        $period_options = [7 => '7日', 30 => '30日', 90 => '90日', 180 => '半年', 365 => '1年', 0 => '全期間'];
        $days = isset($_GET['days']) ? (int) $_GET['days'] : 180;
        if (!array_key_exists($days, $period_options)) $days = 180;
        $per_page = 100;
        $paged = max(1, (int) ($_GET['paged'] ?? 1));
        $where = $days > 0 ? $wpdb->prepare("WHERE created_at >= %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS)) : '';

        $category_counts = $wpdb->get_results(
            "SELECT category, COUNT(*) as cnt FROM $table $where GROUP BY category ORDER BY cnt DESC"
        );
        $total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $period_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table $where");
        $total_pages = max(1, (int) ceil($period_count / $per_page));
        if ($paged > $total_pages) $paged = $total_pages;
        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table $where ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d",
            $per_page, ($paged - 1) * $per_page
        ));
        $base_url = admin_url('admin.php?page=myz-chat-logs&days=' . $days);
        $export_url = wp_nonce_url(admin_url('admin-post.php?action=myz_export_chat_logs&days=' . $days), 'myz_export_chat_logs');

        // 日別推移（過去14日）
        $daily_counts = $wpdb->get_results(
            "SELECT DATE(created_at) as day, COUNT(*) as cnt FROM $table WHERE created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY day ASC"
        );

        // グラフ用の色
        $colors = ['#159BBE', '#FF6B6B', '#4ECDC4', '#FFE66D', '#95E1D3', '#F38181', '#AA96DA', '#A8D8EA', '#FCBAD3'];
        $max_cat_count = 0;
        foreach ($category_counts as $cat) {
            if ((int)$cat->cnt > $max_cat_count) $max_cat_count = (int)$cat->cnt;
        }
        $max_daily = 0;
        foreach ($daily_counts as $d) {
            if ((int)$d->cnt > $max_daily) $max_daily = (int)$d->cnt;
        }

        ?>
        <div class="wrap">
            <h1>チャット履歴・分析</h1>

            <!-- サマリーカード -->
            <div style="display:flex; gap:24px; flex-wrap:wrap; margin:20px 0;">
                <div style="background:linear-gradient(135deg, #159BBE, #0d7a99); color:#fff; border-radius:12px; padding:24px 30px; min-width:180px; box-shadow:0 4px 12px rgba(21,155,190,0.3);">
                    <div style="font-size:13px; opacity:0.9;"><?php echo $period_options[$days]; ?>の質問数</div>
                    <div style="font-size:40px; font-weight:700; margin-top:4px;"><?php echo number_format($period_count); ?></div>
                </div>
                <div style="background:#fff; color:#333; border:1px solid #e2e8f0; border-radius:12px; padding:24px 30px; min-width:180px;">
                    <div style="font-size:13px; color:#666;">保存している総数（全期間）</div>
                    <div style="font-size:40px; font-weight:700; margin-top:4px; color:#159BBE;"><?php echo number_format($total_count); ?></div>
                </div>
            </div>

            <!-- カテゴリ別バーチャート -->
            <?php if (!empty($category_counts)): ?>
            <div style="display:flex; gap:32px; flex-wrap:wrap; margin:24px 0;">
                <div style="flex:1; min-width:400px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px;">
                    <h2 style="font-size:16px; margin:0 0 20px 0; color:#333;">カテゴリ別質問数（<?php echo $period_options[$days]; ?>）</h2>
                    <?php $i = 0; foreach ($category_counts as $cat):
                        $pct = $max_cat_count > 0 ? round(($cat->cnt / $max_cat_count) * 100) : 0;
                        $color = $colors[$i % count($colors)];
                    ?>
                    <div style="margin-bottom:14px;">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">
                            <span style="font-size:13px; font-weight:600; color:#555;"><?php echo esc_html($cat->category); ?></span>
                            <span style="font-size:14px; font-weight:700; color:<?php echo $color; ?>;"><?php echo (int)$cat->cnt; ?>件</span>
                        </div>
                        <div style="background:#f0f4f8; border-radius:8px; height:24px; overflow:hidden;">
                            <div style="background:<?php echo $color; ?>; height:100%; width:<?php echo $pct; ?>%; border-radius:8px; transition:width 0.5s ease; min-width:<?php echo $cat->cnt > 0 ? '24px' : '0'; ?>; display:flex; align-items:center; justify-content:flex-end; padding-right:8px;">
                                <?php if ($total_count > 0): ?>
                                <span style="font-size:11px; color:#fff; font-weight:600;"><?php echo round(($cat->cnt / $total_count) * 100, 1); ?>%</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php $i++; endforeach; ?>
                </div>

                <!-- 円グラフ風（CSSのみ） -->
                <div style="min-width:280px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px;">
                    <h2 style="font-size:16px; margin:0 0 20px 0; color:#333;">構成比</h2>
                    <?php if ($total_count > 0):
                        // CSS conic-gradientで円グラフ
                        $gradient_parts = [];
                        $cumulative = 0;
                        $i = 0;
                        foreach ($category_counts as $cat) {
                            $pct = ($cat->cnt / $total_count) * 100;
                            $color = $colors[$i % count($colors)];
                            $gradient_parts[] = "$color {$cumulative}% " . ($cumulative + $pct) . "%";
                            $cumulative += $pct;
                            $i++;
                        }
                        $gradient = implode(', ', $gradient_parts);
                    ?>
                    <div style="width:200px; height:200px; border-radius:50%; background:conic-gradient(<?php echo $gradient; ?>); margin:0 auto 20px; box-shadow:0 2px 8px rgba(0,0,0,0.1);"></div>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; justify-content:center;">
                        <?php $i = 0; foreach ($category_counts as $cat):
                            $color = $colors[$i % count($colors)];
                        ?>
                        <span style="display:flex; align-items:center; gap:4px; font-size:12px; color:#555;">
                            <span style="width:10px; height:10px; border-radius:50%; background:<?php echo $color; ?>; display:inline-block;"></span>
                            <?php echo esc_html($cat->category); ?>
                        </span>
                        <?php $i++; endforeach; ?>
                    </div>
                    <?php else: ?>
                    <p style="text-align:center; color:#999;">データなし</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- 日別推移チャート -->
            <?php if (!empty($daily_counts)): ?>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px; margin-bottom:24px;">
                <h2 style="font-size:16px; margin:0 0 20px 0; color:#333;">日別質問数（過去14日間）</h2>
                <div style="display:flex; align-items:flex-end; gap:6px; height:150px; padding-bottom:28px; position:relative; border-bottom:1px solid #e2e8f0;">
                    <?php foreach ($daily_counts as $d):
                        $bar_h = $max_daily > 0 ? round(($d->cnt / $max_daily) * 120) : 0;
                        $day_label = date('n/j', strtotime($d->day));
                    ?>
                    <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px;">
                        <span style="font-size:11px; font-weight:700; color:#159BBE;"><?php echo (int)$d->cnt; ?></span>
                        <div style="width:100%; max-width:40px; height:<?php echo max($bar_h, 4); ?>px; background:linear-gradient(180deg, #159BBE, #4ECDC4); border-radius:4px 4px 0 0;"></div>
                        <span style="font-size:10px; color:#999; position:absolute; bottom:4px;"><?php echo $day_label; ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <h2 style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">会話履歴
                <span style="font-size:13px; font-weight:400; color:#666;">（<?php echo $period_options[$days]; ?>: <?php echo number_format($period_count); ?>件、<?php echo $per_page; ?>件ずつ表示）</span>
            </h2>
            <div style="display:flex; align-items:center; gap:6px; flex-wrap:wrap; margin:8px 0;">
                <span style="font-size:13px; color:#555;">期間:</span>
                <?php foreach ($period_options as $d => $label): ?>
                <a class="button <?php echo $d === $days ? 'button-primary' : ''; ?>" href="<?php echo esc_url(admin_url('admin.php?page=myz-chat-logs&days=' . $d)); ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
                <a class="button" style="margin-left:auto;" href="<?php echo esc_url($export_url); ?>">⬇ CSVダウンロード（<?php echo $period_options[$days]; ?>・<?php echo number_format($period_count); ?>件）</a>
            </div>
            <table class="widefat striped" style="margin-top:12px; table-layout:auto;">
                <thead>
                    <tr>
                        <th style="width:130px;">日時</th>
                        <th style="width:100px;">カテゴリ</th>
                        <th style="width:25%;">質問</th>
                        <th>AI回答</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                    <tr><td colspan="4" style="text-align:center; padding:24px; color:#999;">この期間の会話履歴はありません。</td></tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td style="vertical-align:top;"><?php echo esc_html(wp_date('Y/m/d H:i', strtotime($log->created_at))); ?></td>
                            <td style="vertical-align:top;">
                                <span style="background:#e8f4f8; color:#159BBE; padding:2px 8px; border-radius:10px; font-size:12px; white-space:nowrap;">
                                    <?php echo esc_html($log->category); ?>
                                </span>
                            </td>
                            <td style="vertical-align:top; white-space:pre-wrap; word-break:break-word;">
                                <?php echo esc_html($log->user_message); ?>
                            </td>
                            <td style="vertical-align:top; white-space:pre-wrap; word-break:break-word; font-size:13px; line-height:1.6;">
                                <?php echo esc_html($log->bot_reply); ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($total_pages > 1): ?>
            <div style="display:flex; align-items:center; gap:8px; margin:12px 0;">
                <?php if ($paged > 1): ?><a class="button" href="<?php echo esc_url($base_url . '&paged=' . ($paged - 1)); ?>">‹ 前の<?php echo $per_page; ?>件</a><?php endif; ?>
                <span style="font-size:13px; color:#555;"><?php echo $paged; ?> / <?php echo $total_pages; ?> ページ</span>
                <?php if ($paged < $total_pages): ?><a class="button" href="<?php echo esc_url($base_url . '&paged=' . ($paged + 1)); ?>">次の<?php echo $per_page; ?>件 ›</a><?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- デバッグ情報 -->
            <div style="margin-top:24px; padding:16px; background:#f9fafb; border:1px solid #e2e8f0; border-radius:8px;">
                <h3 style="font-size:14px; margin:0 0 8px 0; color:#666;">テーブル状態</h3>
                <?php
                $table_check = $wpdb->get_var("SHOW TABLES LIKE '$table'");
                $row_count = $table_check ? $wpdb->get_var("SELECT COUNT(*) FROM $table") : 'テーブルなし';
                ?>
                <p style="font-size:13px; color:#666; margin:0;">
                    テーブル: <strong><?php echo $table_check ? '✅ 存在' : '❌ 未作成'; ?></strong>
                    ｜ レコード数: <strong><?php echo $row_count; ?></strong>
                </p>
                <?php if (!$table_check): ?>
                <div style="margin-top:12px;">
                    <button type="button" id="myz-create-table-btn" class="button button-primary">テーブルを手動作成</button>
                    <span id="myz-create-table-status" style="margin-left:12px;"></span>
                </div>
                <?php endif; ?>
            </div>

            <script>
            (function(){
                var btn = document.getElementById('myz-create-table-btn');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    btn.disabled = true;
                    var status = document.getElementById('myz-create-table-status');
                    status.textContent = '作成中...';
                    var fd = new FormData();
                    fd.append('action', 'myz_create_tables_manual');
                    fd.append('nonce', '<?php echo wp_create_nonce("myz_create_tables_nonce"); ?>');
                    fetch('<?php echo admin_url("admin-ajax.php"); ?>', {method:'POST', body:fd})
                        .then(function(r){return r.json();})
                        .then(function(data){
                            btn.disabled = false;
                            if (data.success) {
                                status.innerHTML = '<span style="color:green;">✅ ' + data.data.message + '</span>';
                                setTimeout(function(){ location.reload(); }, 1500);
                            } else {
                                status.innerHTML = '<span style="color:red;">❌ ' + (data.data || 'エラー') + '</span>';
                            }
                        })
                        .catch(function(e){
                            btn.disabled = false;
                            status.innerHTML = '<span style="color:red;">❌ 通信エラー: ' + e.message + '</span>';
                        });
                });
            })();
            </script>
        </div>
        <?php
    }

    public static function classify_question($text) {
        foreach (self::CATEGORIES as $category => $keywords) {
            if (empty($keywords)) continue;
            foreach ($keywords as $keyword) {
                if (mb_stripos($text, $keyword) !== false) {
                    return $category;
                }
            }
        }
        return 'その他';
    }

    /**
     * リライトルール追加（/chatbot/ でアクセス可能に）
     */
    public function add_rewrite_rules() {
        $slug = $this->get_standalone_slug();
        add_rewrite_rule('^' . preg_quote($slug) . '/?$', 'index.php?myz_chatbot_standalone=1', 'top');
    }

    /**
     * スタンドアロンページのURLスラグ。
     * 空文字が保存されているとリライトルールが '^/?$'（サイトのトップ）に化けて
     * トップページがチャット画面に乗っ取られるため、必ず既定値に寄せる。
     */
    /**
     * サイト内ウィジェットで案内するお問い合わせフォームURL。
     * 管理画面の設定が空なら固定ページ（スラグ・タイトル）から自動検出する。見つからなければ空文字。
     * $lang を渡すと、その言語版のお問い合わせページ（/en-contact/ /tw-contact/ /ko-contact/ 等）を優先する。
     */
    public function get_contact_url($lang = '') {
        $url = trim((string) get_option('myz_chatbot_contact_url', ''));
        if ($url !== '') {
            return $url;
        }
        return $this->detect_contact_url($lang);
    }

    public function detect_contact_url($lang = '') {
        $lang = $this->normalize_lang((string) $lang);
        $cache_key = 'myz_chatbot_contact_url_' . ($lang !== '' ? $lang : 'base');
        $cached = get_transient($cache_key);
        if (is_string($cached)) {
            return $cached;
        }

        $base_slugs = ['contact', 'contact-us', 'inquiry', 'otoiawase', 'toiawase', 'お問い合わせ', 'お問合せ'];
        $slugs = [];
        if ($lang !== '' && $lang !== 'ja') {
            // 多言語サイトの命名ゆれ: /en-contact/（接頭辞）, /contact-en/（接尾辞）, /en/contact/（Bogo）
            $prefixes = ($lang === 'zh') ? ['tw', 'zh', 'cn'] : [$lang];
            foreach ($prefixes as $pf) {
                $slugs[] = $pf . '-contact';
                $slugs[] = 'contact-' . $pf;
                $slugs[] = $pf . '/contact';
            }
        }
        $slugs = array_merge($slugs, $base_slugs);

        $found = '';
        foreach ($slugs as $slug) {
            $page = get_page_by_path($slug, OBJECT, 'page');
            if ($page && $page->post_status === 'publish') {
                $found = get_permalink($page);
                break;
            }
        }
        // 次にタイトルにお問い合わせ/Contactを含む公開ページ
        if ($found === '') {
            $pages = get_posts([
                'post_type' => 'page',
                'post_status' => 'publish',
                'posts_per_page' => 200,
                'orderby' => 'menu_order',
                'order' => 'ASC',
                'fields' => 'ids',
            ]);
            foreach ($pages as $pid) {
                if (preg_match('/お問い?合わ?せ|問合せ|contact/iu', get_the_title($pid))) {
                    $found = get_permalink($pid);
                    break;
                }
            }
        }
        set_transient($cache_key, $found, HOUR_IN_SECONDS);
        return $found;
    }

    public function clear_contact_url_cache() {
        foreach (['base', 'ja', 'en', 'zh', 'ko', 'it', 'de', 'fr', 'es'] as $k) {
            delete_transient('myz_chatbot_contact_url_' . $k);
        }
    }

    public function get_standalone_slug() {
        $slug = sanitize_title((string) get_option('myz_chatbot_standalone_slug', self::DEFAULT_STANDALONE_SLUG));
        return $slug !== '' ? $slug : self::DEFAULT_STANDALONE_SLUG;
    }

    /**
     * スラグ変更後のリライトルール再生成。
     * init（add_rewrite_rules）が新しいスラグでルールを組んだ後に走る wp_loaded で実行する。
     */
    public function maybe_flush_rewrite_rules() {
        if (!get_option('myz_chatbot_flush_needed')) {
            return;
        }
        delete_option('myz_chatbot_flush_needed');
        flush_rewrite_rules();
    }

    /**
     * 空スラグが保存されている既存サイトの自己修復（管理画面アクセス時に1回だけ）。
     * 空のままだと /（トップページ）がスタンドアロンページに乗っ取られる可能性がある。
     */
    public function repair_standalone_slug() {
        $raw = get_option('myz_chatbot_standalone_slug', null);
        if ($raw === null) {
            return;
        }
        if (sanitize_title((string) $raw) === '') {
            update_option('myz_chatbot_standalone_slug', self::DEFAULT_STANDALONE_SLUG);
            update_option('myz_chatbot_flush_needed', 1);
        }
    }

    public function add_query_vars($vars) {
        $vars[] = 'myz_chatbot_standalone';
        return $vars;
    }

    /**
     * ページ言語の判定（URLスラッグ接頭辞優先。Bogoロケールは当てにならないため補助扱い）
     * myz-floating-cta v2.1.0 の detect_lang() と同方式。
     */
    private function detect_lang() {
        // 1) 明示指定（?lang=en 等）。言語別QRコードを配る場合に使う。
        if (isset($_GET['lang'])) {
            $forced = $this->normalize_lang(sanitize_text_field(wp_unslash($_GET['lang'])));
            if ($forced !== '') { return $forced; }
        }

        // 2) URLスラッグ接頭辞（多言語サイトのページ言語）
        $path = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

        // /zh/ /zh-… に加え、SZ/MV 方式の /tw-home/（繁体字）も zh 扱い
        if (strpos($path, '/zh-') !== false || strpos($path, '/zh/') !== false || strpos($path, '/tw-') !== false) {
            return 'zh';
        }
        if (strpos($path, '/ko-') !== false || strpos($path, '/ko/') !== false) {
            return 'ko';
        }
        // /en/（Bogo）・…-en/（接尾辞）・/en-home/（接頭辞。SZ/MV 方式）
        if (strpos($path, '/en/') !== false || strpos($path, '-en/') !== false || strpos($path, '/en-') !== false || substr($path, -3) === '-en') {
            return 'en';
        }
        foreach (['it', 'de', 'fr', 'es'] as $eu) {
            if (strpos($path, '/' . $eu . '/') !== false || strpos($path, '/' . $eu . '-') !== false) {
                return $eu;
            }
        }

        // 3) スタンドアロンページ（QRコード）はURLに言語の手がかりが無いので端末の言語設定に従う
        if ($this->standalone_context) {
            $browser = $this->detect_browser_lang();
            if ($browser !== '') { return $browser; }
            // 端末言語が対応外（ポルトガル語・タイ語等）なら日本語より英語の方が確実に伝わる
            if (!empty($_SERVER['HTTP_ACCEPT_LANGUAGE'])) { return 'en'; }
        }

        $locale = function_exists('get_locale') ? get_locale() : '';
        $from_locale = $this->normalize_lang($locale);
        if ($from_locale !== '') { return $from_locale; }

        return 'ja';
    }

    /**
     * 言語タグ（ja / en-US / zh-TW / zh_CN / ko-KR / it-IT / de-DE / fr-FR / es-ES 等）を対応言語コードに正規化。
     * 対応外の言語は '' を返す（呼び出し側でフォールバックする）。
     */
    private function normalize_lang($raw) {
        $raw = strtolower(str_replace('_', '-', trim((string) $raw)));
        if ($raw === '') { return ''; }
        foreach ($this->supported_langs() as $code) {
            if (strpos($raw, $code) === 0) { return $code; }
        }
        return '';
    }

    /**
     * ブラウザ（スマホ）の言語設定を Accept-Language ヘッダーから判定。
     * q値の大きい順に見て、最初に対応できた言語を返す。
     */
    private function detect_browser_lang() {
        $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';
        if ($header === '') { return ''; }

        $best = '';
        $best_q = -1.0;
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') { continue; }
            $q = 1.0;
            $tag = $part;
            if (strpos($part, ';') !== false) {
                $pieces = explode(';', $part, 2);
                $tag = $pieces[0];
                if (preg_match('/q\s*=\s*([0-9.]+)/i', $pieces[1], $mq)) {
                    $q = (float) $mq[1];
                }
            }
            $lang = $this->normalize_lang($tag);
            if ($lang === '') { continue; }
            if ($q > $best_q) {
                $best_q = $q;
                $best = $lang;
            }
        }
        return $best;
    }

    /**
     * 対応言語の一覧（表示順）
     */
    private function supported_langs() {
        return ['ja', 'en', 'zh', 'ko', 'it', 'de', 'fr', 'es'];
    }

    /**
     * 管理画面用の言語ラベル（$without_ja=true でヘッダー文言欄のように日本語を除く）
     */
    private function lang_labels($without_ja = false) {
        $labels = [
            'ja' => '日本語',
            'en' => 'English',
            'zh' => '中文（繁體）',
            'ko' => '한국어',
            'it' => 'Italiano',
            'de' => 'Deutsch',
            'fr' => 'Français',
            'es' => 'Español',
        ];
        if ($without_ja) { unset($labels['ja']); }
        return $labels;
    }

    /**
     * UI文字列（入力欄プレースホルダ・ボタンラベル・エラー文）の言語別辞書。
     */
    private function get_ui_strings($lang = null) {
        if ($lang === null) { $lang = $this->detect_lang(); }
        $dict = [
            'ja' => [
                'html_lang'   => 'ja',
                'placeholder' => '質問を入力してください...',
                'send'        => '送信',
                'error'       => 'エラーが発生しました。',
                'net_error'   => '通信エラーが発生しました。',
            ],
            'en' => [
                'html_lang'   => 'en',
                'placeholder' => 'Type your question...',
                'send'        => 'Send',
                'error'       => 'Sorry, an error occurred.',
                'net_error'   => 'A connection error occurred.',
            ],
            'zh' => [
                'html_lang'   => 'zh-Hant',
                'placeholder' => '請輸入您的問題...',
                'send'        => '傳送',
                'error'       => '發生錯誤，請稍後再試。',
                'net_error'   => '連線發生錯誤。',
            ],
            'ko' => [
                'html_lang'   => 'ko',
                'placeholder' => '질문을 입력해 주세요...',
                'send'        => '전송',
                'error'       => '오류가 발생했습니다.',
                'net_error'   => '통신 오류가 발생했습니다.',
            ],
            'it' => [
                'html_lang'   => 'it',
                'placeholder' => 'Scrivi la tua domanda...',
                'send'        => 'Invia',
                'error'       => 'Si è verificato un errore.',
                'net_error'   => 'Errore di connessione.',
            ],
            'de' => [
                'html_lang'   => 'de',
                'placeholder' => 'Frage eingeben...',
                'send'        => 'Senden',
                'error'       => 'Es ist ein Fehler aufgetreten.',
                'net_error'   => 'Verbindungsfehler.',
            ],
            'fr' => [
                'html_lang'   => 'fr',
                'placeholder' => 'Posez votre question...',
                'send'        => 'Envoyer',
                'error'       => 'Une erreur est survenue.',
                'net_error'   => 'Erreur de connexion.',
            ],
            'es' => [
                'html_lang'   => 'es',
                'placeholder' => 'Escribe tu pregunta...',
                'send'        => 'Enviar',
                'error'       => 'Se ha producido un error.',
                'net_error'   => 'Error de conexión.',
            ],
        ];
        return isset($dict[$lang]) ? $dict[$lang] : $dict['ja'];
    }

    /**
     * 言語別の既定ヘッダータイトル（管理画面で未入力のときの受け皿）
     */
    private function default_header_text($lang) {
        $map = [
            'en' => 'Ask AI',
            'zh' => '詢問AI',
            'ko' => 'AI에게 질문',
            'it' => 'Chiedi all\'AI',
            'de' => 'KI fragen',
            'fr' => 'Demander à l\'IA',
            'es' => 'Pregunta a la IA',
        ];
        return isset($map[$lang]) ? $map[$lang] : '';
    }

    /**
     * 言語別の既定初期メッセージ（管理画面で未入力のときの受け皿）
     */
    private function default_welcome_message($lang) {
        $map = [
            'en' => "Hello! I'm your AI assistant. Feel free to ask me anything about the facility, your stay, rates, or the surrounding area.",
            'zh' => "您好！我是AI客服助理。有關設施、住宿、費用或周邊資訊等問題，歡迎隨時詢問。",
            'ko' => "안녕하세요! AI 어시스턴트입니다. 시설이나 숙박, 요금 등 무엇이든 편하게 문의해 주세요.",
            'it' => "Ciao! Sono l'assistente AI. Chiedimi pure qualsiasi cosa sulla struttura, il soggiorno, le tariffe o i dintorni.",
            'de' => "Hallo! Ich bin Ihr KI-Assistent. Fragen Sie mich gerne alles zur Unterkunft, Ihrem Aufenthalt, den Preisen oder der Umgebung.",
            'fr' => "Bonjour ! Je suis votre assistant IA. N'hésitez pas à me poser vos questions sur l'établissement, votre séjour, les tarifs ou les environs.",
            'es' => "¡Hola! Soy el asistente de IA. Pregúntame lo que quieras sobre el alojamiento, tu estancia, las tarifas o los alrededores.",
        ];
        return isset($map[$lang]) ? $map[$lang] : '';
    }

    /**
     * ページ言語に応じたヘッダータイトルを返す。
     * 言語別設定が空なら共通のmyz_chatbot_header_textへフォールバック（後方互換）。
     */
    private function get_header_text($lang = null) {
        if ($lang === null) { $lang = $this->detect_lang(); }
        if ($lang !== 'ja') {
            $text = get_option('myz_chatbot_header_text_' . $lang, '');
            if (trim($text) !== '') {
                return $text;
            }
            $text = $this->default_header_text($lang);
            if ($text !== '') {
                return $text;
            }
        }
        return get_option('myz_chatbot_header_text', 'AIに質問');
    }

    /**
     * ページ言語に応じた初期メッセージを返す。
     * 言語別設定が空ならenへ、それも空なら従来の共通メッセージへフォールバック（後方互換）。
     */
    private function get_welcome_message($lang = null) {
        if ($lang === null) { $lang = $this->detect_lang(); }

        // 1) その言語の設定があればそれを使う
        $msg = get_option('myz_chatbot_welcome_message_' . $lang, '');
        if (trim($msg) !== '') {
            return $msg;
        }

        // 2) 日本語・英語以外は未設定なら組み込みの同言語メッセージを使う
        //    （共通メッセージは日本語＋英語なので、そのまま出すと言語が合わない）
        if ($lang !== 'ja' && $lang !== 'en') {
            $msg = $this->default_welcome_message($lang);
            if ($msg !== '') {
                return $msg;
            }
            $msg = get_option('myz_chatbot_welcome_message_en', '');
            if (trim($msg) !== '') {
                return $msg;
            }
        }

        // 3) 従来どおりの共通メッセージ（言語別設定が未使用のサイト向け）
        $default_welcome = "こんにちは！AIアシスタントです。サービス内容や料金など、お気軽にご質問ください。\n\nHello! I'm your AI assistant. Please feel free to ask me any questions about our services, pricing, or anything else.";
        return get_option('myz_chatbot_welcome_message', $default_welcome);
    }

    /**
     * スタンドアロンページの表示
     */
    /* ====================== クイック質問ボタン（客室QRページ） ====================== */

    /** 設定が空のときに使う共通7ボタン（日本語。他言語は quick_buttons_dictionary の辞書で即時展開） */
    public static function quick_buttons_default_text() {
        return implode("\n", [
            'チェックイン方法 | チェックインの方法と時間を教えてください',
            '駐車場 | 駐車場はありますか？場所と料金を教えてください',
            'アメニティ | 客室のアメニティと備品を教えてください',
            'Wi-Fi | Wi-FiのSSIDとパスワードを教えてください',
            'ゴミの出し方 | ゴミの分別と捨て方を教えてください',
            '荷物預かり | チェックイン前やチェックアウト後に荷物を預かってもらえますか？',
            '周辺情報 | 周辺のおすすめの飲食店・コンビニ・観光スポットを教えてください',
        ]);
    }

    /** 共通ボタンの辞書（日本語ラベル → 言語 → [ラベル, 質問]）。AIを呼ばずに表示できる */
    private static function quick_buttons_dictionary() {
        return [
            'チェックイン方法' => [
                'en' => ['Check-in', 'How and when can I check in?'],
                'zh' => ['入住方式', '請告訴我入住的方法和時間'],
                'ko' => ['체크인 방법', '체크인 방법과 시간을 알려주세요'],
                'it' => ['Check-in', 'Come e a che ora posso fare il check-in?'],
                'de' => ['Check-in', 'Wie und wann kann ich einchecken?'],
                'fr' => ['Arrivée', 'Comment et à quelle heure puis-je faire le check-in ?'],
                'es' => ['Check-in', '¿Cómo y a qué hora puedo hacer el check-in?'],
            ],
            '駐車場' => [
                'en' => ['Parking', 'Is there parking? Where is it and how much does it cost?'],
                'zh' => ['停車場', '有停車場嗎？請告訴我位置和費用'],
                'ko' => ['주차장', '주차장이 있나요? 위치와 요금을 알려주세요'],
                'it' => ['Parcheggio', "C'è un parcheggio? Dove si trova e quanto costa?"],
                'de' => ['Parkplatz', 'Gibt es einen Parkplatz? Wo ist er und was kostet er?'],
                'fr' => ['Parking', 'Y a-t-il un parking ? Où se trouve-t-il et combien coûte-t-il ?'],
                'es' => ['Aparcamiento', '¿Hay aparcamiento? ¿Dónde está y cuánto cuesta?'],
            ],
            'アメニティ' => [
                'en' => ['Amenities', 'What amenities and supplies are in the room?'],
                'zh' => ['備品', '請告訴我客房的備品和設備'],
                'ko' => ['어메니티', '객실 어메니티와 비품을 알려주세요'],
                'it' => ['Dotazioni', 'Quali dotazioni e accessori ci sono in camera?'],
                'de' => ['Ausstattung', 'Welche Ausstattung und Artikel gibt es im Zimmer?'],
                'fr' => ['Équipements', 'Quels équipements et fournitures y a-t-il dans la chambre ?'],
                'es' => ['Amenities', '¿Qué amenities y artículos hay en la habitación?'],
            ],
            'Wi-Fi' => [
                'en' => ['Wi-Fi', 'What are the Wi-Fi network name and password?'],
                'zh' => ['Wi-Fi', '請告訴我Wi-Fi的名稱和密碼'],
                'ko' => ['Wi-Fi', 'Wi-Fi 이름과 비밀번호를 알려주세요'],
                'it' => ['Wi-Fi', 'Quali sono il nome e la password del Wi-Fi?'],
                'de' => ['WLAN', 'Wie lauten WLAN-Name und Passwort?'],
                'fr' => ['Wi-Fi', 'Quels sont le nom et le mot de passe du Wi-Fi ?'],
                'es' => ['Wi-Fi', '¿Cuáles son el nombre y la contraseña del Wi-Fi?'],
            ],
            'ゴミの出し方' => [
                'en' => ['Trash', 'How should I sort and dispose of the trash?'],
                'zh' => ['垃圾處理', '請告訴我垃圾的分類和丟棄方式'],
                'ko' => ['쓰레기 배출', '쓰레기 분리와 버리는 방법을 알려주세요'],
                'it' => ['Rifiuti', 'Come devo separare e smaltire i rifiuti?'],
                'de' => ['Müll', 'Wie soll ich den Müll trennen und entsorgen?'],
                'fr' => ['Poubelles', 'Comment trier et jeter les déchets ?'],
                'es' => ['Basura', '¿Cómo debo separar y tirar la basura?'],
            ],
            '荷物預かり' => [
                'en' => ['Luggage storage', 'Can you store my luggage before check-in or after check-out?'],
                'zh' => ['行李寄放', '入住前或退房後可以寄放行李嗎？'],
                'ko' => ['짐 보관', '체크인 전이나 체크아웃 후에 짐을 맡길 수 있나요?'],
                'it' => ['Deposito bagagli', 'Posso lasciare i bagagli prima del check-in o dopo il check-out?'],
                'de' => ['Gepäckaufbewahrung', 'Kann ich mein Gepäck vor dem Check-in oder nach dem Check-out aufbewahren lassen?'],
                'fr' => ['Bagagerie', 'Puis-je laisser mes bagages avant le check-in ou après le check-out ?'],
                'es' => ['Consigna de equipaje', '¿Puedo dejar mi equipaje antes del check-in o después del check-out?'],
            ],
            '周辺情報' => [
                'en' => ['Nearby', 'What restaurants, convenience stores and sights do you recommend nearby?'],
                'zh' => ['周邊資訊', '請推薦附近的餐廳、便利商店和觀光景點'],
                'ko' => ['주변 정보', '주변의 추천 음식점, 편의점, 관광지를 알려주세요'],
                'it' => ['Dintorni', 'Quali ristoranti, minimarket e attrazioni consiglia nei dintorni?'],
                'de' => ['Umgebung', 'Welche Restaurants, Convenience Stores und Sehenswürdigkeiten empfehlen Sie in der Nähe?'],
                'fr' => ['Environs', 'Quels restaurants, supérettes et sites recommandez-vous à proximité ?'],
                'es' => ['Alrededores', '¿Qué restaurantes, tiendas y lugares de interés recomienda cerca?'],
            ],
        ];
    }

    /** 設定テキスト（1行1ボタン「ラベル | 質問」）→ [['l'=>ラベル,'q'=>質問], ...] */
    private static function parse_quick_buttons($text) {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            $parts = array_map('trim', explode('|', $line, 2));
            $label = $parts[0];
            $q = (isset($parts[1]) && $parts[1] !== '') ? $parts[1] : $label;
            if ($label === '') continue;
            $out[] = ['l' => mb_substr($label, 0, 40), 'q' => mb_substr($q, 0, 200)];
            if (count($out) >= 20) break;
        }
        return $out;
    }

    public function on_quick_buttons_added($option, $value) { $this->refresh_quick_buttons_i18n($value); }
    public function on_quick_buttons_saved($old, $value) { if ($old !== $value) $this->refresh_quick_buttons_i18n($value); }

    /**
     * 日本語のボタン定義を8言語に展開して myz_chatbot_quick_buttons_i18n にキャッシュする。
     * 共通7ボタンは辞書で即時、それ以外（宿ごとのサービス等）は設定保存時に1回だけAIで翻訳する。
     */
    public function refresh_quick_buttons_i18n($text) {
        $src = trim((string) $text) !== '' ? $text : self::quick_buttons_default_text();
        $ja = self::parse_quick_buttons($src);
        $dict = self::quick_buttons_dictionary();
        $langs = $this->supported_langs();
        $i18n = array_fill_keys($langs, []);
        $need_ai = [];
        foreach ($ja as $idx => $b) {
            foreach ($langs as $l) {
                if ($l === 'ja') { $i18n['ja'][$idx] = $b; continue; }
                if (isset($dict[$b['l']][$l])) {
                    $i18n[$l][$idx] = ['l' => $dict[$b['l']][$l][0], 'q' => $dict[$b['l']][$l][1]];
                } else {
                    $i18n[$l][$idx] = $b; // いったん日本語のまま。AI翻訳で上書き
                    $need_ai[$idx] = $b;
                }
            }
        }
        $error = '';
        if (!empty($need_ai)) {
            $names = ['en' => 'English', 'zh' => 'Traditional Chinese (繁體中文)', 'ko' => 'Korean', 'it' => 'Italian', 'de' => 'German', 'fr' => 'French', 'es' => 'Spanish'];
            $system = "You translate short UI button labels and guest questions for a hotel chatbot. Return ONLY valid JSON. No markdown, no code fences, no commentary.";
            $lang_list = [];
            foreach ($names as $k => $n) { $lang_list[] = "$k=$n"; }
            $user = "Translate each item into these languages: " . implode(', ', $lang_list) . ".\n"
                  . "Keep labels very short (1-3 words). Keep questions natural, as a hotel guest would ask.\n"
                  . "Output format: {\"<index>\": {\"en\": [label, question], \"zh\": [label, question], \"ko\": [...], \"it\": [...], \"de\": [...], \"fr\": [...], \"es\": [...]}}\n\n"
                  . "Items (index => {l: label, q: question}):\n" . wp_json_encode($need_ai, JSON_UNESCAPED_UNICODE);
            $api = new MYZ_Chatbot_API();
            $res = $api->complete_text($system, $user);
            if (is_wp_error($res)) {
                $error = $res->get_error_message();
            } else {
                $json = trim((string) $res);
                $json = preg_replace('/^```(?:json)?\s*/i', '', $json);
                $json = preg_replace('/\s*```$/', '', $json);
                $data = json_decode($json, true);
                if (!is_array($data)) {
                    $error = 'AIの応答をJSONとして解釈できませんでした';
                } else {
                    foreach ($need_ai as $idx => $b) {
                        $row = $data[(string) $idx] ?? ($data[$idx] ?? null);
                        if (!is_array($row)) continue;
                        foreach ($names as $l => $_) {
                            if (!empty($row[$l][0])) {
                                $ql = trim((string) $row[$l][0]);
                                $qq = trim((string) ($row[$l][1] ?? $row[$l][0]));
                                $i18n[$l][$idx] = ['l' => mb_substr($ql, 0, 40), 'q' => mb_substr($qq, 0, 200)];
                            }
                        }
                    }
                }
            }
        }
        foreach ($langs as $l) { ksort($i18n[$l]); $i18n[$l] = array_values($i18n[$l]); }
        if ($error) $i18n['_error'] = $error;
        update_option('myz_chatbot_quick_buttons_i18n', wp_json_encode($i18n, JSON_UNESCAPED_UNICODE), false);
    }

    /** 表示用: 言語コード → ボタン配列。キャッシュが無ければ（初回・旧版からの更新直後）その場で組み立てる */
    private function get_quick_buttons_all() {
        $raw = get_option('myz_chatbot_quick_buttons_i18n', '');
        $data = $raw ? json_decode($raw, true) : null;
        if (!is_array($data) || empty($data['ja'])) {
            $this->refresh_quick_buttons_i18n(get_option('myz_chatbot_quick_buttons', ''));
            $data = json_decode((string) get_option('myz_chatbot_quick_buttons_i18n', '[]'), true);
            if (!is_array($data)) $data = [];
        }
        unset($data['_error']);
        return $data;
    }

    /* ====================== チャット履歴CSV ====================== */

    public function export_chat_logs_csv() {
        if (!current_user_can('manage_options')) wp_die('権限がありません');
        check_admin_referer('myz_export_chat_logs');
        global $wpdb;
        $table = $wpdb->prefix . 'myz_chat_logs';
        $days = isset($_GET['days']) ? (int) $_GET['days'] : 180;
        $where = $days > 0 ? $wpdb->prepare("WHERE created_at >= %s", gmdate('Y-m-d H:i:s', current_time('timestamp') - $days * DAY_IN_SECONDS)) : '';
        $rows = $wpdb->get_results("SELECT created_at, category, session_id, user_message, bot_reply FROM $table $where ORDER BY created_at DESC, id DESC", ARRAY_A);
        $host = sanitize_title((string) wp_parse_url(home_url(), PHP_URL_HOST));
        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="chat_logs_' . $host . '_' . ($days ?: 'all') . 'd_' . wp_date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excelで文字化けしないようBOM
        fputcsv($out, ['日時', 'カテゴリ', 'セッション', '質問', 'AI回答']);
        foreach ((array) $rows as $r) {
            fputcsv($out, [$r['created_at'], $r['category'], $r['session_id'], $r['user_message'], $r['bot_reply']]);
        }
        fclose($out);
        exit;
    }

    /**
     * WP Fastest Cache の除外ルールに客室QR（スタンドアロン）ページを登録する。
     * HTMLに埋め込むnonceは12〜24hで失効するため、ページキャッシュされると全問エラーになる。
     * WPFCは DONOTCACHEPAGE を無視する（Wordfence/Divi等の特例のみ）ので、option直書きで除外する。
     */
    public function ensure_wpfc_exclusion() {
        if (!function_exists('is_plugin_active')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
        if (!is_plugin_active('wp-fastest-cache/wpFastestCache.php')) return;

        $path = wp_parse_url(home_url('/' . $this->get_standalone_slug() . '/'), PHP_URL_PATH);
        if (!$path) return;
        $regex = '^' . preg_quote($path, '/') . '?$';

        $raw = get_option('WpFastestCacheExclude', '');
        $rules = $raw ? json_decode($raw, true) : [];
        if (!is_array($rules)) $rules = [];
        foreach ($rules as $r) {
            if (is_array($r) && ($r['content'] ?? '') === $regex) return;
        }
        $rules[] = ['prefix' => 'regex', 'content' => $regex, 'type' => 'page'];
        update_option('WpFastestCacheExclude', wp_json_encode($rules));
    }

    public function render_standalone_page() {
        if (!get_query_var('myz_chatbot_standalone')) return;

        // ページキャッシュ禁止: HTMLに埋め込むnonceは12〜24時間で失効するため、
        // キャッシュプラグイン（WP Fastest Cache等）が古いコピーを配ると全問「エラーが発生しました。」になる
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
        if (!defined('DONOTMINIFY')) define('DONOTMINIFY', true);
        if (!headers_sent()) nocache_headers();

        // このページはURLに言語の手がかりが無いため、端末（スマホ）の言語設定で初期表示を決める
        $this->standalone_context = true;
        $lang = $this->detect_lang();
        $ui = $this->get_ui_strings($lang);

        // ページキャッシュ対策: 全言語ぶんのテキストを埋め込み、ブラウザ側でも navigator.language で確定させる
        $i18n = [];
        foreach ($this->supported_langs() as $l) {
            $l_ui = $this->get_ui_strings($l);
            $i18n[$l] = [
                'htmlLang'    => $l_ui['html_lang'],
                'title'       => $this->get_header_text($l),
                'welcome'     => nl2br(esc_html($this->get_welcome_message($l))),
                'placeholder' => $l_ui['placeholder'],
                'send'        => $l_ui['send'],
                'error'       => $l_ui['error'],
                'netError'    => $l_ui['net_error'],
            ];
        }

        if (!headers_sent()) {
            header('Vary: Accept-Language');
        }

        // クイック質問ボタン（言語別）
        $quick_all = $this->get_quick_buttons_all();

        $primary_color = get_option('myz_chatbot_primary_color', '#159BBE');
        $text_color = get_option('myz_chatbot_text_color', '#ffffff');
        $header_text = esc_html($this->get_header_text($lang));
        $header_icon_key = get_option('myz_chatbot_header_icon', 'chat');
        $header_emoji_map = [
            'chat' => '💬', 'robot' => '🤖', 'operator' => '👩‍💼', 'house' => '🏠',
            'target' => '🎯', 'sparkle' => '✨', 'phone' => '📞', 'bulb' => '💡', 'headset' => '🎧',
        ];
        $header_icon = $header_emoji_map[$header_icon_key] ?? '💬';
        $send_icon = get_option('myz_chatbot_send_icon', 'paper-plane');
        $welcome_msg = nl2br(esc_html($this->get_welcome_message($lang)));
        $font_family = get_option('myz_chatbot_font_family', 'system');
        $font_weight = get_option('myz_chatbot_font_weight', '400');
        $font_size = get_option('myz_chatbot_font_size', '14');

        $font_map = [
            'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Noto Sans JP", sans-serif',
            'gothic' => '"Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", sans-serif',
            'mincho' => '"Hiragino Mincho ProN", "Noto Serif JP", "Yu Mincho", serif',
            'maru'   => '"Hiragino Maru Gothic ProN", "M PLUS Rounded 1c", "Kosugi Maru", sans-serif',
            'times'  => '"Times New Roman", Times, "Noto Serif", serif',
            'rounded' => '"M PLUS Rounded 1c", "Kosugi Maru", sans-serif',
        ];
        $font_css = $font_map[$font_family] ?? $font_map['system'];

        $site_name = get_bloginfo('name');

        // 送信アイコンSVG
        $send_icons = [
            'paper-plane' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="' . esc_attr($text_color) . '"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
            'arrow-up'    => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($text_color) . '" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
            'arrow-right' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($text_color) . '" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
            'send-circle' => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="' . esc_attr($text_color) . '" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M16 12l-4-4v8l4-4z" fill="' . esc_attr($text_color) . '"/></svg>',
        ];
        $send_svg = $send_icons[$send_icon] ?? $send_icons['paper-plane'];

        ?><!DOCTYPE html>
<html lang="<?php echo esc_attr($ui['html_lang']); ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?php echo $header_text; ?> - <?php echo esc_html($site_name); ?></title>
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
:root {
    --myz-primary: <?php echo esc_attr($primary_color); ?>;
    --myz-text-color: <?php echo esc_attr($text_color); ?>;
}
body {
    font-family: <?php echo $font_css; ?>;
    font-weight: <?php echo esc_attr($font_weight); ?>;
    font-size: <?php echo esc_attr($font_size); ?>px;
    background: #f0f4f8;
    height: 100vh;
    height: 100dvh;
    display: flex;
    flex-direction: column;
}
#sa-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: var(--myz-primary);
    color: var(--myz-text-color);
    padding: 16px 20px;
    flex-shrink: 0;
}
.sa-header-info {
    display: flex;
    align-items: center;
    gap: 8px;
}
.sa-header-icon { font-size: 22px; }
.sa-header-title { font-size: 18px; font-weight: 600; }
#sa-messages {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.sa-msg { display: flex; max-width: 85%; }
.sa-msg.sa-bot { align-self: flex-start; }
.sa-msg.sa-user { align-self: flex-end; }
.sa-msg-content {
    padding: 12px 16px;
    border-radius: 16px;
    line-height: 1.6;
    word-break: break-word;
}
.sa-bot .sa-msg-content {
    background: #fff;
    color: #333;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.sa-user .sa-msg-content {
    background: var(--myz-primary);
    color: var(--myz-text-color);
    border-bottom-right-radius: 4px;
}
.sa-typing { display: flex; align-items: center; gap: 4px; padding: 12px 16px; }
.sa-dot {
    width: 8px; height: 8px; background: #a0aec0; border-radius: 50%;
    animation: saDot 1.2s infinite ease-in-out;
}
.sa-dot:nth-child(2) { animation-delay: 0.2s; }
.sa-dot:nth-child(3) { animation-delay: 0.4s; }
@keyframes saDot {
    0%, 60%, 100% { transform: scale(0.6); opacity: 0.4; }
    30% { transform: scale(1); opacity: 1; }
}
#sa-input-area {
    display: flex;
    align-items: center;
    padding: 12px 16px;
    padding-bottom: max(12px, env(safe-area-inset-bottom));
    border-top: 1px solid #e8ecf0;
    background: #fff;
    gap: 8px;
    flex-shrink: 0;
}
#sa-input {
    flex: 1;
    border: 1px solid #dde2e8;
    border-radius: 24px;
    padding: 12px 18px;
    font-size: 16px;
    outline: none;
    font-family: inherit;
    color: #1a1a1a;
}
#sa-input:focus { border-color: var(--myz-primary); }
#sa-input::placeholder { color: #6b7280; }
#sa-send {
    background: var(--myz-primary);
    color: var(--myz-text-color);
    border: none;
    border-radius: 50%;
    width: 44px; height: 44px;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    flex-shrink: 0;
}
#sa-send:disabled { background: #a0aec0; cursor: not-allowed; }
#sa-send svg { width: 20px; height: 20px; display: block; pointer-events: none; }
.sa-msg-content strong { font-weight: 700; }
.sa-msg-content a { color: var(--myz-primary); word-break: break-all; }
.sa-quick { display: flex; flex-wrap: wrap; gap: 8px; margin: 2px 0 14px 0; }
/* 配色はユーザー吹き出しと同じ（テーマ色の地＋テーマの文字色）。淡いテーマ色でも読める */
.sa-quick button {
    background: var(--myz-primary);
    color: var(--myz-text-color);
    border: 1px solid rgba(0,0,0,0.08);
    border-radius: 20px;
    padding: 8px 14px;
    font-size: 14px;
    font-family: inherit;
    font-weight: 600;
    line-height: 1.3;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
    -webkit-tap-highlight-color: transparent;
}
.sa-quick button:hover { filter: brightness(0.96); }
.sa-quick button:active { filter: brightness(0.9); }
#sa-messages::-webkit-scrollbar { width: 4px; }
#sa-messages::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 2px; }
/* スマホ（QRから全画面で開く想定）: ウィジェット用の文字サイズ設定は小さすぎるので最低18pxに底上げ */
@media (max-width: 767px) {
    body { font-size: max(<?php echo esc_attr($font_size); ?>px, 18px); }
    #sa-header { padding: 14px 16px; }
    .sa-header-icon { font-size: 24px; }
    .sa-header-title { font-size: 20px; }
    .sa-msg { max-width: 92%; }
    .sa-msg-content { padding: 14px 18px; line-height: 1.7; }
    #sa-input { font-size: 18px; padding: 14px 20px; }
    #sa-send { width: 50px; height: 50px; }
    #sa-send svg { width: 24px; height: 24px; }
    .sa-quick button { font-size: 16px; padding: 10px 16px; }
}
</style>
</head>
<body>
<div id="sa-header">
    <div class="sa-header-info">
        <span class="sa-header-icon"><?php echo $header_icon; ?></span>
        <span class="sa-header-title"><?php echo $header_text; ?></span>
    </div>
</div>
<div id="sa-messages">
    <div class="sa-msg sa-bot">
        <div class="sa-msg-content"><?php echo $welcome_msg; ?></div>
    </div>
    <div id="sa-quick" class="sa-quick"></div>
</div>
<div id="sa-input-area">
    <input type="text" id="sa-input" placeholder="<?php echo esc_attr($ui['placeholder']); ?>" autocomplete="off" />
    <button id="sa-send" aria-label="<?php echo esc_attr($ui['send']); ?>"><?php echo $send_svg; ?></button>
</div>
<script>
(function(){
    var input = document.getElementById('sa-input');
    var sendBtn = document.getElementById('sa-send');
    var messages = document.getElementById('sa-messages');
    var isLoading = false;
    var history = [];
    var sessionId = 's_' + Date.now() + '_' + Math.random().toString(36).substr(2,8);
    var ajaxUrl = '<?php echo admin_url("admin-ajax.php"); ?>';
    var nonce = '<?php echo wp_create_nonce("myz_chatbot_nonce"); ?>';

    // 端末の言語設定で初期表示を確定する（サーバー側判定＋ページキャッシュのズレを吸収）
    var i18n = <?php echo wp_json_encode($i18n, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    var lang = <?php echo wp_json_encode($lang); ?>;
    var quick = <?php echo wp_json_encode($quick_all, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;

    function pickLang(){
        var tags = (navigator.languages && navigator.languages.length)
            ? navigator.languages
            : [navigator.language || navigator.userLanguage || ''];
        var codes = Object.keys(i18n);
        var hasTag = false;
        for (var i = 0; i < tags.length; i++) {
            var t = String(tags[i] || '').toLowerCase();
            if (t) { hasTag = true; }
            for (var j = 0; j < codes.length; j++) {
                if (t.indexOf(codes[j]) === 0) return codes[j];
            }
        }
        // 端末言語が対応外なら英語（日本語より確実に伝わる）
        return hasTag ? 'en' : '';
    }

    function applyLang(){
        var q = (location.search.match(/[?&]lang=([^&]+)/) || [])[1];
        var picked = q ? String(q).toLowerCase().slice(0, 2) : pickLang();
        if (picked && i18n[picked]) { lang = picked; }
        var t = i18n[lang];
        if (!t) return;
        document.documentElement.lang = t.htmlLang;
        var titleEl = document.querySelector('.sa-header-title');
        if (titleEl) { titleEl.textContent = t.title; }
        var firstMsg = messages.querySelector('.sa-msg.sa-bot .sa-msg-content');
        if (firstMsg) { firstMsg.innerHTML = t.welcome; }
        input.placeholder = t.placeholder;
        sendBtn.setAttribute('aria-label', t.send);
        renderQuick();
    }

    // タップするだけで質問できるボタン（初期メッセージの下）。言語切替に追従する
    function renderQuick(){
        var box = document.getElementById('sa-quick');
        if (!box) return;
        var list = (quick && quick[lang]) || (quick && quick.ja) || [];
        box.innerHTML = '';
        list.forEach(function(b){
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = b.l;
            btn.addEventListener('click', function(){
                if (isLoading) return;
                input.value = b.q;
                sendMessage();
            });
            box.appendChild(btn);
        });
        box.style.display = list.length ? '' : 'none';
    }
    applyLang();

    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function(e){
        if(e.key==='Enter'&&!e.isComposing){e.preventDefault();sendMessage();}
    });

    function formatReply(text){
        var s=text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        s=s.replace(/&lt;\/?(?:strong|em|b|i|br\s*\/?)&gt;/g,'');
        s=s.replace(/^#{1,6}\s+/gm,'');
        s=s.replace(/\*\*(.+?)\*\*/g,'$1');
        s=s.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g,'$1');
        s=s.replace(/^[\-\*]\s+/gm,'・');
        s=s.replace(/^\*\s*/gm,'');
        s=s.replace(/【(.+?)】/g,'<strong>【$1】</strong>');
        s=s.replace(/(https?:\/\/[^\s<>&「」）)】]+)/g,'<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');
        s=s.replace(/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/g,'<a href="mailto:$1">$1</a>');
        s=s.replace(/\n\n+/g,'<br><br>');
        s=s.replace(/\n/g,'<br>');
        return s;
    }

    function appendMessage(type, text){
        var div=document.createElement('div');
        div.className='sa-msg '+(type==='user'?'sa-user':'sa-bot');
        var c=document.createElement('div');
        c.className='sa-msg-content';
        if(type==='bot'){c.innerHTML=formatReply(text);}else{c.textContent=text;}
        div.appendChild(c);
        messages.appendChild(div);
        if(type==='user'){messages.scrollTop=messages.scrollHeight;}
        else{var t=div.offsetTop-messages.offsetTop-8;messages.scrollTop=t;}
    }

    function showTyping(){
        var div=document.createElement('div');div.className='sa-msg sa-bot';div.id='sa-typing';
        var c=document.createElement('div');c.className='sa-typing';
        c.innerHTML='<span class="sa-dot"></span><span class="sa-dot"></span><span class="sa-dot"></span>';
        div.appendChild(c);messages.appendChild(div);messages.scrollTop=messages.scrollHeight;
    }

    function removeTyping(){var e=document.getElementById('sa-typing');if(e)e.remove();}

    // ページキャッシュで古いnonceが配られた場合、サーバーが新しいnonceを返すので1回だけ再送する
    function postChat(text, retried){
        var fd=new FormData();
        fd.append('action','myz_chat');fd.append('nonce',nonce);
        fd.append('message',text);fd.append('history',JSON.stringify(history.slice(-11,-1)));
        fd.append('session_id',sessionId);fd.append('ui_lang',lang);fd.append('standalone','1');
        return fetch(ajaxUrl,{method:'POST',body:fd})
            .then(function(r){return r.json();})
            .then(function(data){
                if(!retried&&data&&data.success===false&&data.data&&data.data.code==='bad_nonce'&&data.data.nonce){
                    nonce=data.data.nonce;
                    return postChat(text,true);
                }
                return data;
            });
    }

    function sendMessage(){
        var text=input.value.trim();if(!text||isLoading)return;
        appendMessage('user',text);history.push({role:'user',content:text});input.value='';
        showTyping();isLoading=true;sendBtn.disabled=true;
        postChat(text,false)
            .then(function(data){
                removeTyping();
                if(data&&data.success&&data.data.reply){appendMessage('bot',data.data.reply);history.push({role:'assistant',content:data.data.reply});}
                else{appendMessage('bot',(data&&data.data&&data.data.message)?data.data.message:((i18n[lang]&&i18n[lang].error)||'エラーが発生しました。'));}
            })
            .catch(function(){removeTyping();appendMessage('bot',(i18n[lang]&&i18n[lang].netError)||'通信エラーが発生しました。');})
            .finally(function(){isLoading=false;sendBtn.disabled=false;});
    }
})();
</script>
</body>
</html>
        <?php
        exit;
    }

    public function enqueue_assets() {
        if (get_option('myz_chatbot_enabled', '1') !== '1') return;

        wp_enqueue_style('myz-chatbot-css', MYZ_CHATBOT_URL . 'assets/css/chatbot.css', [], MYZ_CHATBOT_VERSION);
        wp_enqueue_script('myz-chatbot-js', MYZ_CHATBOT_URL . 'assets/js/chatbot.js', [], MYZ_CHATBOT_VERSION, true);

        $primary_color = get_option('myz_chatbot_primary_color', '#159BBE');
        $text_color = get_option('myz_chatbot_text_color', '#ffffff');
        $font_family = get_option('myz_chatbot_font_family', 'system');
        $font_weight = get_option('myz_chatbot_font_weight', '600');
        $font_size = get_option('myz_chatbot_font_size', '15');
        $position = get_option('myz_chatbot_position', 'left');

        $font_map = [
            'system' => '-apple-system, BlinkMacSystemFont, "Segoe UI", "Hiragino Sans", "Noto Sans JP", sans-serif',
            'gothic' => '"Hiragino Kaku Gothic ProN", "Noto Sans JP", "Yu Gothic", "Meiryo", sans-serif',
            'mincho' => '"Hiragino Mincho ProN", "Noto Serif JP", "Yu Mincho", "MS PMincho", serif',
            'maru'   => '"Hiragino Maru Gothic ProN", "Kosugi Maru", "Yu Gothic", sans-serif',
            'times'  => '"Times New Roman", Times, "Noto Serif", serif',
            'mono'   => '"SF Mono", "Hiragino Kaku Gothic ProN", "Courier New", monospace',
        ];
        $font_css = $font_map[$font_family] ?? $font_map['system'];

        wp_localize_script('myz-chatbot-js', 'myzChatbot', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('myz_chatbot_nonce'),
            'primaryColor' => $primary_color,
            'textColor'    => $text_color,
            'position'     => $position,
            // ページの言語（AIに言語別のお問い合わせページを案内させるため）
            'pageLang'     => $this->detect_lang(),
        ]);

        // CSSカスタムプロパティでテーマカラー、文字色、フォント、位置を注入
        $pos_css = ':root { --myz-primary: ' . esc_attr($primary_color) . '; --myz-text-color: ' . esc_attr($text_color) . '; }'
            . ' #myz-chatbot-container, #myz-chatbot-toggle, #myz-chatbot-toggle span { font-family: ' . $font_css . ' !important; }'
            . ' #myz-chatbot-toggle, #myz-chatbot-toggle span { font-weight: ' . esc_attr($font_weight) . '; font-size: ' . esc_attr($font_size) . 'px; }'
            . ' .myz-message-content { font-size: ' . esc_attr($font_size) . 'px; }'
            . ' .myz-header-title { font-family: ' . $font_css . ' !important; }';
        if ($position === 'right') {
            $pos_css .= '
                #myz-chatbot-container { left:auto; right:24px; }
                #myz-chatbot-window { left:auto; right:0; transform-origin:bottom right; }
                @media (max-width:480px) {
                    #myz-chatbot-container { left:auto; right:16px; }
                }
            ';
        }
        wp_add_inline_style('myz-chatbot-css', $pos_css);
    }

    /**
     * トグルボタンアイコンのSVGを取得
     */
    private function get_toggle_icon_svg($icon) {
        $icons = [
            'chat'     => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>',
            'question' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><circle cx="12" cy="17" r="0.5" fill="currentColor"/></svg>',
            'headset'  => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 18v-6a9 9 0 0 1 18 0v6"/><path d="M21 19a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-3a2 2 0 0 1 2-2h3zM3 19a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-3a2 2 0 0 0-2-2H3z"/></svg>',
            'robot'    => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="8" width="18" height="12" rx="2"/><path d="M12 2v6"/><circle cx="9" cy="14" r="1.5" fill="currentColor"/><circle cx="15" cy="14" r="1.5" fill="currentColor"/></svg>',
        ];
        return $icons[$icon] ?? $icons['chat'];
    }

    /**
     * 送信ボタンアイコンのSVGを取得
     */
    private function get_send_icon_svg($icon) {
        $icons = [
            'paper-plane'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
            'arrow-up'     => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
            'arrow-right'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
            'send-circle'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M16 12l-4-4v8l4-4z" fill="currentColor"/></svg>',
        ];
        return $icons[$icon] ?? $icons['paper-plane'];
    }

    public function render_widget() {
        if (get_option('myz_chatbot_enabled', '1') !== '1') return;

        $toggle_text = esc_html(get_option('myz_chatbot_toggle_text', 'AIに質問'));
        $toggle_icon = get_option('myz_chatbot_toggle_icon', 'chat');
        $toggle_size = get_option('myz_chatbot_toggle_size', 'medium');
        $toggle_radius = get_option('myz_chatbot_toggle_radius', '50');

        // アイコンのみ（テキスト空）の場合は丸ボタン
        $is_icon_only = empty(trim($toggle_text));

        if ($is_icon_only) {
            $size_styles = [
                'small'  => 'width:44px; height:44px; padding:0; font-size:12px;',
                'medium' => 'width:56px; height:56px; padding:0; font-size:15px;',
                'large'  => 'width:68px; height:68px; padding:0; font-size:18px;',
            ];
            $toggle_style = ($size_styles[$toggle_size] ?? $size_styles['medium'])
                           . ' border-radius:50%; justify-content:center;';
        } else {
            $size_styles = [
                'small'  => 'padding:8px 16px; font-size:12px;',
                'medium' => 'padding:14px 24px; font-size:15px;',
                'large'  => 'padding:18px 32px; font-size:18px;',
            ];
            $toggle_style = ($size_styles[$toggle_size] ?? $size_styles['medium'])
                           . ' border-radius:' . intval($toggle_radius) . 'px;';
        }

        $header_text = esc_html($this->get_header_text());
        $header_icon_key = get_option('myz_chatbot_header_icon', 'chat');
        $header_emoji_map = [
            'chat' => '💬', 'robot' => '🤖', 'operator' => '👩‍💼', 'house' => '🏠',
            'target' => '🎯', 'sparkle' => '✨', 'phone' => '📞', 'bulb' => '💡', 'headset' => '🎧',
        ];
        $header_icon = $header_emoji_map[$header_icon_key] ?? '💬';
        $send_icon   = get_option('myz_chatbot_send_icon', 'paper-plane');
        $welcome_msg = nl2br(esc_html($this->get_welcome_message()));
        $ui          = $this->get_ui_strings();
        ?>
        <div id="myz-chatbot-container">
            <div id="myz-chatbot-window" class="myz-hidden">
                <div id="myz-chatbot-header">
                    <div class="myz-header-info">
                        <span class="myz-header-icon"><?php echo $header_icon; ?></span>
                        <span class="myz-header-title"><?php echo $header_text; ?></span>
                    </div>
                    <button id="myz-chatbot-close" aria-label="閉じる">&times;</button>
                </div>
                <div id="myz-chatbot-messages">
                    <div class="myz-message myz-bot">
                        <div class="myz-message-content">
                            <?php echo $welcome_msg; ?>
                        </div>
                    </div>
                </div>
                <div id="myz-chatbot-input-area">
                    <input type="text" id="myz-chatbot-input"
                           placeholder="<?php echo esc_attr($ui['placeholder']); ?>"
                           autocomplete="off" />
                    <button id="myz-chatbot-send" aria-label="<?php echo esc_attr($ui['send']); ?>" title="<?php echo esc_attr($ui['send']); ?>">
                        <?php echo $this->get_send_icon_svg($send_icon); ?>
                    </button>
                </div>
            </div>
            <button id="myz-chatbot-toggle" aria-label="<?php echo $toggle_text; ?>" style="<?php echo esc_attr($toggle_style); ?>">
                <?php echo $this->get_toggle_icon_svg($toggle_icon); ?>
                <?php if (!$is_icon_only): ?>
                <span id="myz-toggle-text"><?php echo $toggle_text; ?></span>
                <?php endif; ?>
            </button>
        </div>
        <?php
    }
}

$GLOBALS['myz_ai_chatbot'] = new MYZ_AI_Chatbot();

/**
 * サイト内ウィジェットで案内するお問い合わせフォームURL（設定 or 自動検出）。無ければ空文字。
 */
function myz_chatbot_contact_url($lang = '') {
    $plugin = $GLOBALS['myz_ai_chatbot'] ?? null;
    return ($plugin instanceof MYZ_AI_Chatbot) ? $plugin->get_contact_url($lang) : '';
}
