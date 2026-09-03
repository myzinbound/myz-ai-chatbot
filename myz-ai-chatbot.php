<?php
/**
 * Plugin Name: MYZ AI Chatbot
 * Description: マイズインバウンドのAIチャットボット（Claude API連携）
 * Version: 5.15.0
 * Author: MYZINBOUND INC
 * Text Domain: myz-ai-chatbot
 */

if (!defined('ABSPATH')) exit;

define('MYZ_CHATBOT_VERSION', '5.15.0');
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

        // AJAX: 手動テーブル作成
        add_action('wp_ajax_myz_create_tables_manual', [$this, 'ajax_create_tables']);

        // AJAX: 手動スクレイピング
        add_action('wp_ajax_myz_scrape_now', [$this, 'ajax_scrape_now']);

        // AJAX: ファイルアップロード/削除
        add_action('wp_ajax_myz_upload_knowledge_file', [$this, 'ajax_upload_knowledge_file']);
        add_action('wp_ajax_myz_delete_knowledge_file', [$this, 'ajax_delete_knowledge_file']);

        // AJAX: 更新チェック
        add_action('wp_ajax_myz_check_update', [$this, 'ajax_check_update']);

        // Cron
        add_action('myz_chatbot_scrape_cron', [$this, 'run_cron_scrape']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // GitHub自動更新
        new MYZ_Chatbot_Updater();

        // スタンドアロンページ
        add_action('template_redirect', [$this, 'render_standalone_page']);
        add_action('init', [$this, 'add_rewrite_rules']);
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
        register_setting('myz_chatbot_settings', 'myz_chatbot_urls', [
            'sanitize_callback' => [$this, 'sanitize_urls'],
        ]);
        register_setting('myz_chatbot_settings', 'myz_chatbot_scrape_frequency', ['default' => 'weekly']);

        // UI設定
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_text', ['default' => 'AIに質問']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_icon', ['default' => 'chat']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_header_text', ['default' => 'AIに質問']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_header_icon', ['default' => 'chat']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_primary_color', ['default' => '#159BBE']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_text_color', ['default' => '#ffffff']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_send_icon', ['default' => 'paper-plane']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message', ['default' => "こんにちは！AIアシスタントです。サービス内容や料金など、お気軽にご質問ください。\n\nHello! I'm your AI assistant. Please feel free to ask me any questions about our services, pricing, or anything else."]);
        // 言語別初期メッセージ（空欄なら上の共通メッセージを使用＝後方互換）
        // 言語別ヘッダータイトル（空ならmyz_chatbot_header_textにフォールバック）
        foreach (['en', 'zh', 'ko'] as $myz_header_lang) {
            register_setting('myz_chatbot_settings', 'myz_chatbot_header_text_' . $myz_header_lang, ['default' => '']);
        }
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message_ja', ['default' => '']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message_en', ['default' => '']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message_zh', ['default' => '']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message_ko', ['default' => '']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_standalone_slug', [
            'default' => 'chatbot',
            'sanitize_callback' => function($val) {
                $val = sanitize_title($val);
                // スラグ変更時にリライトルールをフラッシュ
                flush_rewrite_rules();
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
                            <?php foreach (['en' => '英語', 'zh' => '中国語', 'ko' => '韓国語'] as $hl => $hl_label) : ?>
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
                            $lang_labels = [
                                'ja' => '日本語',
                                'en' => 'English',
                                'zh' => '中文（繁體）',
                                'ko' => '한국어',
                            ];
                            foreach ($lang_labels as $lang_code => $lang_label) :
                            ?>
                            <p style="margin:8px 0 2px;"><strong><?php echo $lang_label; ?></strong></p>
                            <textarea name="myz_chatbot_welcome_message_<?php echo $lang_code; ?>" rows="2" class="large-text"
                                ><?php echo esc_textarea(get_option('myz_chatbot_welcome_message_' . $lang_code, '')); ?></textarea>
                            <?php endforeach; ?>
                            <p class="description">サイト内のウィジェットはページURLの言語（/zh-〜, /ko-〜, /en/・〜-en、それ以外は日本語）、<strong>QRコードのスタンドアロンページは視聴者の端末（スマホ）の言語設定</strong>に応じて表示されます。中文・韓国語が空欄の場合は同じ言語の汎用メッセージを自動で表示します（日本語のままにはなりません）。日本語・英語が空欄の場合は上の共通メッセージを使います。</p>
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
                </table>

                <h2>スタンドアロンページ（QRコード用）</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">ページURL</th>
                        <td>
                            <?php
                            $sa_slug = get_option('myz_chatbot_standalone_slug', 'chatbot');
                            $sa_url = home_url('/' . $sa_slug . '/');
                            ?>
                            <code style="font-size:15px; padding:8px 12px; background:#f0f4f8; border-radius:6px; display:inline-block;">
                                <a href="<?php echo esc_url($sa_url); ?>" target="_blank"><?php echo esc_html($sa_url); ?></a>
                            </code>
                            <p class="description" style="margin-top:8px;">このURLにアクセスすると、チャットボットだけの全画面ページが表示されます。<br>初期メッセージ・タイトル・入力欄は<strong>ゲストの端末の言語設定（日本語 / English / 中文 / 韓国語）を自動判定</strong>して切り替わります。言語を固定したQRを配る場合は <code>?lang=en</code> （en / zh / ko / ja）を付けてください。</p>
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

        $category_counts = $wpdb->get_results(
            "SELECT category, COUNT(*) as cnt FROM $table GROUP BY category ORDER BY cnt DESC"
        );
        $total_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $table");
        $logs = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT 50"
        );

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
                    <div style="font-size:13px; opacity:0.9;">総質問数</div>
                    <div style="font-size:40px; font-weight:700; margin-top:4px;"><?php echo $total_count; ?></div>
                </div>
            </div>

            <!-- カテゴリ別バーチャート -->
            <?php if (!empty($category_counts)): ?>
            <div style="display:flex; gap:32px; flex-wrap:wrap; margin:24px 0;">
                <div style="flex:1; min-width:400px; background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:24px;">
                    <h2 style="font-size:16px; margin:0 0 20px 0; color:#333;">カテゴリ別質問数</h2>
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

            <h2>直近50件の会話</h2>
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
                    <tr><td colspan="4" style="text-align:center; padding:24px; color:#999;">まだ会話履歴がありません。</td></tr>
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
        $slug = get_option('myz_chatbot_standalone_slug', 'chatbot');
        add_rewrite_rule('^' . preg_quote($slug) . '/?$', 'index.php?myz_chatbot_standalone=1', 'top');
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

        if (strpos($path, '/zh-') !== false || strpos($path, '/zh/') !== false) {
            return 'zh';
        }
        if (strpos($path, '/ko-') !== false || strpos($path, '/ko/') !== false) {
            return 'ko';
        }
        if (strpos($path, '/en/') !== false || strpos($path, '-en/') !== false || substr($path, -3) === '-en') {
            return 'en';
        }

        // 3) スタンドアロンページ（QRコード）はURLに言語の手がかりが無いので端末の言語設定に従う
        if ($this->standalone_context) {
            $browser = $this->detect_browser_lang();
            if ($browser !== '') { return $browser; }
        }

        $locale = function_exists('get_locale') ? get_locale() : '';
        if (strpos($locale, 'zh') === 0) { return 'zh'; }
        if (strpos($locale, 'ko') === 0) { return 'ko'; }
        if (strpos($locale, 'en') === 0) { return 'en'; }

        return 'ja';
    }

    /**
     * 言語タグ（ja / en-US / zh-TW / zh_CN / ko-KR 等）を ja/en/zh/ko に正規化。
     * 対応外の言語は '' を返す（呼び出し側でフォールバックする）。
     */
    private function normalize_lang($raw) {
        $raw = strtolower(str_replace('_', '-', trim((string) $raw)));
        if ($raw === '') { return ''; }
        if (strpos($raw, 'zh') === 0) { return 'zh'; }
        if (strpos($raw, 'ko') === 0) { return 'ko'; }
        if (strpos($raw, 'en') === 0) { return 'en'; }
        if (strpos($raw, 'ja') === 0) { return 'ja'; }
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
        return ['ja', 'en', 'zh', 'ko'];
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

        // 2) 中国語・韓国語は未設定なら組み込みの同言語メッセージを使う
        //    （共通メッセージは日本語＋英語なので、そのまま出すと言語が合わない）
        if ($lang === 'zh' || $lang === 'ko') {
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
    public function render_standalone_page() {
        if (!get_query_var('myz_chatbot_standalone')) return;

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
#sa-messages::-webkit-scrollbar { width: 4px; }
#sa-messages::-webkit-scrollbar-thumb { background: #cbd5e0; border-radius: 2px; }
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

    function pickLang(){
        var tags = (navigator.languages && navigator.languages.length)
            ? navigator.languages
            : [navigator.language || navigator.userLanguage || ''];
        for (var i = 0; i < tags.length; i++) {
            var t = String(tags[i] || '').toLowerCase();
            if (t.indexOf('zh') === 0) return 'zh';
            if (t.indexOf('ko') === 0) return 'ko';
            if (t.indexOf('en') === 0) return 'en';
            if (t.indexOf('ja') === 0) return 'ja';
        }
        return '';
    }

    function applyLang(){
        var q = (location.search.match(/[?&]lang=([^&]+)/) || [])[1];
        var picked = q ? String(q).toLowerCase().slice(0, 2) : pickLang();
        if (picked === 'zh' || picked === 'ko' || picked === 'en' || picked === 'ja') {
            if (i18n[picked]) { lang = picked; }
        }
        var t = i18n[lang];
        if (!t) return;
        document.documentElement.lang = t.htmlLang;
        var titleEl = document.querySelector('.sa-header-title');
        if (titleEl) { titleEl.textContent = t.title; }
        var firstMsg = messages.querySelector('.sa-msg.sa-bot .sa-msg-content');
        if (firstMsg) { firstMsg.innerHTML = t.welcome; }
        input.placeholder = t.placeholder;
        sendBtn.setAttribute('aria-label', t.send);
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

    function sendMessage(){
        var text=input.value.trim();if(!text||isLoading)return;
        appendMessage('user',text);history.push({role:'user',content:text});input.value='';
        showTyping();isLoading=true;sendBtn.disabled=true;
        var fd=new FormData();
        fd.append('action','myz_chat');fd.append('nonce',nonce);
        fd.append('message',text);fd.append('history',JSON.stringify(history.slice(-10)));
        fd.append('session_id',sessionId);fd.append('ui_lang',lang);
        fetch(ajaxUrl,{method:'POST',body:fd})
            .then(function(r){return r.json();})
            .then(function(data){
                removeTyping();
                if(data.success&&data.data.reply){appendMessage('bot',data.data.reply);history.push({role:'assistant',content:data.data.reply});}
                else{appendMessage('bot',(data.data&&data.data.message)?data.data.message:((i18n[lang]&&i18n[lang].error)||'エラーが発生しました。'));}
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

new MYZ_AI_Chatbot();
