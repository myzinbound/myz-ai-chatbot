<?php
/**
 * GitHub経由のプラグイン自動更新クラス
 *
 * WordPressの「更新」ページにプラグインの新バージョンを表示し、
 * ワンクリックで更新できるようにする。
 */

if (!defined('ABSPATH')) exit;

class MYZ_Chatbot_Updater {

    private $slug;
    private $plugin_file;
    private $github_repo;
    private $current_version;
    private $cache_key = 'myz_chatbot_update_check';
    private $cache_duration = 43200; // 12時間

    public function __construct() {
        $this->slug = 'myz-ai-chatbot';
        $this->plugin_file = 'myz-ai-chatbot/myz-ai-chatbot.php';
        $this->github_repo = get_option('myz_chatbot_github_repo', '');
        $this->current_version = MYZ_CHATBOT_VERSION;

        if (empty($this->github_repo)) return;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_post_install', [$this, 'after_install'], 10, 3);
    }

    /**
     * GitHubから最新リリース情報を取得
     */
    private function get_latest_release() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $repo = trim($this->github_repo);
        // "owner/repo" 形式を想定
        $url = "https://api.github.com/repos/{$repo}/releases/latest";

        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'MYZ-AI-Chatbot-Updater',
            ],
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // エラーの場合は空結果を短時間キャッシュ
            set_transient($this->cache_key, [], 3600);
            return [];
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || !isset($body['tag_name'])) {
            set_transient($this->cache_key, [], 3600);
            return [];
        }

        $release = [
            'version'     => ltrim($body['tag_name'], 'v'),
            'download_url' => $body['zipball_url'] ?? '',
            'description' => $body['body'] ?? '',
            'published'   => $body['published_at'] ?? '',
            'html_url'    => $body['html_url'] ?? '',
        ];

        // アセット（ZIPファイル）があればそちらを優先
        if (!empty($body['assets'])) {
            foreach ($body['assets'] as $asset) {
                if (str_ends_with($asset['name'], '.zip')) {
                    $release['download_url'] = $asset['browser_download_url'];
                    break;
                }
            }
        }

        set_transient($this->cache_key, $release, $this->cache_duration);
        return $release;
    }

    /**
     * 更新チェック — WordPressの更新一覧に追加
     */
    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (empty($release) || empty($release['version'])) {
            return $transient;
        }

        if (version_compare($release['version'], $this->current_version, '>')) {
            $plugin = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['download_url'],
                'icons'       => [],
                'banners'     => [],
                'tested'      => '',
                'requires_php' => '7.4',
            ];
            $transient->response[$this->plugin_file] = $plugin;
        } else {
            // 最新版の場合は no_update に追加
            $plugin = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->plugin_file,
                'new_version' => $release['version'],
                'url'         => $release['html_url'],
                'package'     => $release['download_url'],
            ];
            $transient->no_update[$this->plugin_file] = $plugin;
        }

        return $transient;
    }

    /**
     * プラグイン情報ダイアログ
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        if (empty($release)) {
            return $result;
        }

        return (object) [
            'name'            => 'MYZ AI Chatbot',
            'slug'            => $this->slug,
            'version'         => $release['version'],
            'author'          => '<a href="https://myzminpaku.com">MYZINBOUND INC</a>',
            'homepage'        => $release['html_url'],
            'download_link'   => $release['download_url'],
            'requires'        => '5.0',
            'tested'          => '6.7',
            'requires_php'    => '7.4',
            'last_updated'    => $release['published'],
            'sections'        => [
                'description' => '<p>マイズインバウンドのAIチャットボットプラグイン</p>',
                'changelog'   => '<pre>' . esc_html($release['description']) . '</pre>',
            ],
        ];
    }

    /**
     * インストール後にフォルダ名を修正
     */
    public function after_install($response, $hook_extra, $result) {
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $response;
        }

        global $wp_filesystem;
        $proper_dir = WP_PLUGIN_DIR . '/' . $this->slug;

        // GitHubのZIPはフォルダ名が "owner-repo-hash" になるので修正
        if ($result['destination'] !== $proper_dir) {
            $wp_filesystem->move($result['destination'], $proper_dir);
            $result['destination'] = $proper_dir;
        }

        // キャッシュをクリア
        delete_transient($this->cache_key);

        // プラグインを再有効化
        activate_plugin($this->plugin_file);

        return $response;
    }

    /**
     * 手動で更新チェック（キャッシュをクリアして再チェック）
     */
    public static function force_check() {
        delete_transient('myz_chatbot_update_check');
        delete_site_transient('update_plugins');
        wp_update_plugins();
    }
}
