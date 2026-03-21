<?php
/**
 * Plugin Name: MYZ AI Chatbot
 * Description: マイズインバウンドのAIチャットボット（Claude API連携）
 * Version: 3.1.0
 * Author: MYZINBOUND INC
 * Text Domain: myz-ai-chatbot
 */

if (!defined('ABSPATH')) exit;

define('MYZ_CHATBOT_VERSION', '5.1.0');
define('MYZ_CHATBOT_PATH', plugin_dir_path(__FILE__));
define('MYZ_CHATBOT_URL', plugin_dir_url(__FILE__));

require_once MYZ_CHATBOT_PATH . 'includes/site-knowledge.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-chatbot-api.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-scraper.php';
require_once MYZ_CHATBOT_PATH . 'includes/class-updater.php';

class MYZ_AI_Chatbot {

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

        // AJAX: 更新チェック
        add_action('wp_ajax_myz_check_update', [$this, 'ajax_check_update']);

        // Cron
        add_action('myz_chatbot_scrape_cron', [$this, 'run_cron_scrape']);
        add_filter('cron_schedules', [$this, 'add_cron_schedules']);

        // GitHub自動更新
        new MYZ_Chatbot_Updater();

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
        register_setting('myz_chatbot_settings', 'myz_chatbot_gemini_model', ['default' => 'gemini-2.0-flash']);
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
        register_setting('myz_chatbot_settings', 'myz_chatbot_send_icon', ['default' => 'paper-plane']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_welcome_message', ['default' => 'こんにちは！マイズインバウンドのAIアシスタントです。サービス内容や料金など、お気軽にご質問ください。']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_size', ['default' => 'medium']);
        register_setting('myz_chatbot_settings', 'myz_chatbot_toggle_radius', ['default' => '50']);
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
                                <?php $gemini_model = get_option('myz_chatbot_gemini_model', 'gemini-2.0-flash'); ?>
                                <select name="myz_chatbot_gemini_model">
                                    <option value="gemini-2.0-flash" <?php selected($gemini_model, 'gemini-2.0-flash'); ?>>Gemini 2.0 Flash（推奨・高速）</option>
                                    <option value="gemini-2.5-flash-preview-05-20" <?php selected($gemini_model, 'gemini-2.5-flash-preview-05-20'); ?>>Gemini 2.5 Flash（最新）</option>
                                    <option value="gemini-2.5-pro-preview-05-06" <?php selected($gemini_model, 'gemini-2.5-pro-preview-05-06'); ?>>Gemini 2.5 Pro（高性能・高コスト）</option>
                                </select>
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
                            <textarea name="myz_chatbot_welcome_message" rows="3" class="large-text"
                                ><?php echo esc_textarea(get_option('myz_chatbot_welcome_message', 'こんにちは！マイズインバウンドのAIアシスタントです。サービス内容や料金など、お気軽にご質問ください。')); ?></textarea>
                            <p class="description">チャットを開いたときに最初に表示されるメッセージです。</p>
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
                            <p>学習済みページ数: <strong><?php echo $knowledge_count; ?></strong></p>
                            <p>最終更新: <strong id="myz-last-scraped"><?php echo esc_html($last_scraped); ?></strong></p>
                            <button type="button" id="myz-scrape-btn" class="button button-secondary">今すぐ更新</button>
                            <span id="myz-scrape-status" style="margin-left:12px;"></span>
                        </td>
                    </tr>
                </table>

                <h2>プラグイン更新設定</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">GitHubリポジトリ</th>
                        <td>
                            <input type="text" name="myz_chatbot_github_repo"
                                   value="<?php echo esc_attr(get_option('myz_chatbot_github_repo', '')); ?>"
                                   class="regular-text" placeholder="例: username/myz-ai-chatbot" />
                            <p class="description">GitHubリポジトリを <code>ユーザー名/リポジトリ名</code> の形式で入力してください。<br>設定すると、WordPressの「更新」ページからワンクリックで更新できるようになります。</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">現在のバージョン</th>
                        <td>
                            <p><strong style="font-size:16px;">v<?php echo MYZ_CHATBOT_VERSION; ?></strong></p>
                            <?php if (get_option('myz_chatbot_github_repo', '')): ?>
                            <button type="button" id="myz-check-update-btn" class="button button-secondary">更新をチェック</button>
                            <span id="myz-update-status" style="margin-left:12px;"></span>
                            <?php else: ?>
                            <p class="description" style="color:#d63638;">GitHubリポジトリを設定すると更新チェックが有効になります。</p>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <?php submit_button('設定を保存'); ?>
            </form>
        </div>

        <script>
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

    public function enqueue_assets() {
        if (get_option('myz_chatbot_enabled', '1') !== '1') return;

        wp_enqueue_style('myz-chatbot-css', MYZ_CHATBOT_URL . 'assets/css/chatbot.css', [], MYZ_CHATBOT_VERSION);
        wp_enqueue_script('myz-chatbot-js', MYZ_CHATBOT_URL . 'assets/js/chatbot.js', [], MYZ_CHATBOT_VERSION, true);

        $primary_color = get_option('myz_chatbot_primary_color', '#159BBE');
        $position = get_option('myz_chatbot_position', 'left');
        wp_localize_script('myz-chatbot-js', 'myzChatbot', [
            'ajaxUrl'      => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('myz_chatbot_nonce'),
            'primaryColor' => $primary_color,
            'position'     => $position,
        ]);

        // CSSカスタムプロパティでテーマカラーと位置を注入
        $pos_css = ':root { --myz-primary: ' . esc_attr($primary_color) . '; }';
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
            'paper-plane'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="#ffffff"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>',
            'arrow-up'     => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5"><path d="M12 19V5M5 12l7-7 7 7"/></svg>',
            'arrow-right'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2.5"><path d="M5 12h14M12 5l7 7-7 7"/></svg>',
            'send-circle'  => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M16 12l-4-4v8l4-4z" fill="#ffffff"/></svg>',
        ];
        return $icons[$icon] ?? $icons['paper-plane'];
    }

    public function render_widget() {
        if (get_option('myz_chatbot_enabled', '1') !== '1') return;

        $toggle_text = esc_html(get_option('myz_chatbot_toggle_text', 'AIに質問'));
        $toggle_icon = get_option('myz_chatbot_toggle_icon', 'chat');
        $toggle_size = get_option('myz_chatbot_toggle_size', 'medium');
        $toggle_radius = get_option('myz_chatbot_toggle_radius', '50');

        // サイズ別のスタイル
        $size_styles = [
            'small'  => 'padding:8px 16px; font-size:12px;',
            'medium' => 'padding:14px 24px; font-size:15px;',
            'large'  => 'padding:18px 32px; font-size:18px;',
        ];
        $toggle_style = ($size_styles[$toggle_size] ?? $size_styles['medium'])
                       . ' border-radius:' . intval($toggle_radius) . 'px;';

        $header_text = esc_html(get_option('myz_chatbot_header_text', 'AIに質問'));
        $header_icon_key = get_option('myz_chatbot_header_icon', 'chat');
        $header_emoji_map = [
            'chat' => '💬', 'robot' => '🤖', 'operator' => '👩‍💼', 'house' => '🏠',
            'target' => '🎯', 'sparkle' => '✨', 'phone' => '📞', 'bulb' => '💡', 'headset' => '🎧',
        ];
        $header_icon = $header_emoji_map[$header_icon_key] ?? '💬';
        $send_icon   = get_option('myz_chatbot_send_icon', 'paper-plane');
        $welcome_msg = esc_html(get_option('myz_chatbot_welcome_message', 'こんにちは！マイズインバウンドのAIアシスタントです。サービス内容や料金など、お気軽にご質問ください。'));
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
                           placeholder="質問を入力してください..."
                           autocomplete="off" />
                    <button id="myz-chatbot-send" aria-label="送信" title="送信">
                        <?php echo $this->get_send_icon_svg($send_icon); ?>
                    </button>
                </div>
            </div>
            <button id="myz-chatbot-toggle" aria-label="<?php echo $toggle_text; ?>" style="<?php echo esc_attr($toggle_style); ?>">
                <?php echo $this->get_toggle_icon_svg($toggle_icon); ?>
                <span id="myz-toggle-text"><?php echo $toggle_text; ?></span>
            </button>
        </div>
        <?php
    }
}

new MYZ_AI_Chatbot();
