<?php
/**
 * サイトスクレイピングクラス
 * 登録されたURLからテキストを取得してDBに保存
 */

if (!defined('ABSPATH')) exit;

class MYZ_Chatbot_Scraper {

    /**
     * URLからテキストコンテンツを取得
     */
    public static function fetch_text($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'MYZ-AI-Chatbot/1.0',
        ]);

        if (is_wp_error($response)) {
            return '';
        }

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) return '';

        // HTMLからテキストを抽出
        return self::html_to_text($html);
    }

    /**
     * HTMLからメインコンテンツのテキストを抽出
     */
    private static function html_to_text($html) {
        // script, style, nav, footer, header タグを除去
        $html = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $html);
        $html = preg_replace('/<nav[^>]*>.*?<\/nav>/is', '', $html);

        // 改行を保持するタグ
        $html = preg_replace('/<(br|\/p|\/div|\/h[1-6]|\/li|\/tr)[^>]*>/i', "\n", $html);
        $html = preg_replace('/<\/td>/i', ' | ', $html);

        // 全HTMLタグを除去
        $text = strip_tags($html);

        // HTML エンティティをデコード
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // 連続空白・改行を整理
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n\s*\n+/', "\n\n", $text);
        $text = trim($text);

        // 最大文字数制限（1ページあたり）
        if (mb_strlen($text) > 5000) {
            $text = mb_substr($text, 0, 5000) . '...';
        }

        return $text;
    }

    /**
     * 全登録URLをスクレイピングしてDBに保存
     */
    public static function scrape_all() {
        global $wpdb;
        $table = $wpdb->prefix . 'myz_knowledge';
        $urls = get_option('myz_chatbot_urls', []);

        if (empty($urls)) return 0;

        $count = 0;
        foreach ($urls as $url) {
            $url = trim($url);
            if (empty($url)) continue;

            $text = self::fetch_text($url);
            if (empty($text)) continue;

            // 既存データがあれば更新、なければ挿入
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table WHERE url = %s", $url
            ));

            if ($existing) {
                $wpdb->update($table, [
                    'content'    => $text,
                    'updated_at' => current_time('mysql'),
                ], ['id' => $existing], ['%s', '%s'], ['%d']);
            } else {
                $wpdb->insert($table, [
                    'url'        => $url,
                    'content'    => $text,
                    'updated_at' => current_time('mysql'),
                ], ['%s', '%s', '%s']);
            }
            $count++;
        }

        update_option('myz_chatbot_last_scraped', current_time('mysql'));
        return $count;
    }

    /**
     * DBからナレッジテキストを取得
     */
    public static function get_knowledge_from_db() {
        global $wpdb;
        $table = $wpdb->prefix . 'myz_knowledge';

        $rows = $wpdb->get_results("SELECT url, content FROM $table ORDER BY id ASC");

        if (empty($rows)) return '';

        $text = '';
        foreach ($rows as $row) {
            $text .= "【" . $row->url . " の内容】\n";
            $text .= $row->content . "\n\n";
        }

        return $text;
    }
}
