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
        // Google Sheetsの場合はCSV形式で取得
        if (self::is_google_sheets_url($url)) {
            return self::fetch_google_sheets($url);
        }

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
     * Google SheetsのURLかどうか判定
     */
    private static function is_google_sheets_url($url) {
        return (bool) preg_match('/docs\.google\.com\/spreadsheets/', $url);
    }

    /**
     * Google SheetsからCSV形式でデータを取得
     */
    private static function fetch_google_sheets($url) {
        // スプレッドシートIDを抽出
        $spreadsheet_id = '';

        // /d/e/XXXX/pubhtml 形式
        if (preg_match('/\/d\/e\/(2PACX-[^\/]+)/', $url, $m)) {
            $spreadsheet_id = $m[1];
            $csv_url = "https://docs.google.com/spreadsheets/d/e/{$spreadsheet_id}/pub?output=csv";
        }
        // /d/XXXX/edit 形式
        elseif (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            $spreadsheet_id = $m[1];
            $csv_url = "https://docs.google.com/spreadsheets/d/{$spreadsheet_id}/export?format=csv";
        }
        else {
            // IDが取得できない場合は通常のスクレイピングにフォールバック
            return self::fetch_text_fallback($url);
        }

        // GID（シートID）があれば付加
        if (preg_match('/gid=(\d+)/', $url, $gm)) {
            $csv_url .= '&gid=' . $gm[1];
        }

        // CSV取得（リダイレクトを追跡）
        $response = wp_remote_get($csv_url, [
            'timeout'     => 30,
            'redirection' => 5,
            'user-agent'  => 'MYZ-AI-Chatbot/1.0',
        ]);

        if (is_wp_error($response)) {
            error_log('MYZ Chatbot: Google Sheets CSV fetch error: ' . $response->get_error_message());
            return self::fetch_text_fallback($url);
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            error_log('MYZ Chatbot: Google Sheets CSV status: ' . $status);
            return self::fetch_text_fallback($url);
        }

        $csv = wp_remote_retrieve_body($response);
        if (empty($csv)) {
            return self::fetch_text_fallback($url);
        }

        // CSVを見やすいテキスト形式に変換
        return self::csv_to_text($csv);
    }

    /**
     * 通常のHTML取得（フォールバック）
     */
    private static function fetch_text_fallback($url) {
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'user-agent' => 'MYZ-AI-Chatbot/1.0',
        ]);

        if (is_wp_error($response)) return '';

        $html = wp_remote_retrieve_body($response);
        if (empty($html)) return '';

        return self::html_to_text($html);
    }

    /**
     * CSVデータをAIが読みやすいテキスト形式に変換
     */
    private static function csv_to_text($csv) {
        $lines = self::parse_csv($csv);

        if (empty($lines)) return $csv; // パース失敗時はそのまま返す

        $text = '';
        $headers = [];

        foreach ($lines as $i => $row) {
            if ($i === 0) {
                // ヘッダー行として保存
                $headers = $row;
                $text .= "【項目】" . implode(' | ', $row) . "\n";
                continue;
            }

            // 空行をスキップ
            $is_empty = true;
            foreach ($row as $cell) {
                if (trim($cell) !== '') { $is_empty = false; break; }
            }
            if ($is_empty) continue;

            // ヘッダーがある場合は「ヘッダー: 値」形式
            if (!empty($headers)) {
                $parts = [];
                foreach ($row as $j => $cell) {
                    $cell = trim($cell);
                    if ($cell === '') continue;
                    $header = isset($headers[$j]) ? $headers[$j] : "列" . ($j+1);
                    $parts[] = $header . ': ' . $cell;
                }
                if (!empty($parts)) {
                    $text .= implode(' / ', $parts) . "\n";
                }
            } else {
                $text .= implode(' | ', $row) . "\n";
            }
        }

        // 最大文字数制限
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000) . '...';
        }

        return $text;
    }

    /**
     * CSV文字列をパースして2次元配列にする
     */
    private static function parse_csv($csv) {
        $lines = [];
        $rows = explode("\n", $csv);
        foreach ($rows as $row) {
            $row = trim($row, "\r");
            if ($row === '') continue;
            $lines[] = str_getcsv($row);
        }
        return $lines;
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
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000) . '...';
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

        // 学習対象URLから外されたページの古い内容を消す（外してもDBに残り続けていた）
        $keep = array_values(array_filter(array_map('trim', (array) $urls)));
        $url_rows = $wpdb->get_results("SELECT id, url FROM $table WHERE source_type = 'url' OR source_type = ''");
        foreach ($url_rows as $row) {
            if (!in_array($row->url, $keep, true)) {
                $wpdb->delete($table, ['id' => $row->id], ['%d']);
            }
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

        // source_typeカラムが存在するか確認（旧スキーマ互換）
        $has_source = $wpdb->get_var("SHOW COLUMNS FROM $table LIKE 'source_type'");
        if (!empty($has_source)) {
            $rows = $wpdb->get_results("SELECT url, source_type, filename, content FROM $table ORDER BY id ASC");
        } else {
            $rows = $wpdb->get_results("SELECT url, content FROM $table ORDER BY id ASC");
        }

        if (empty($rows)) return '';

        $text = '';
        foreach ($rows as $row) {
            $is_file = !empty($row->source_type) && $row->source_type === 'file';
            if ($is_file) {
                $label = !empty($row->filename) ? $row->filename : $row->url;
                $text .= "【アップロード資料: " . $label . " の内容】\n";
            } else {
                $text .= "【" . $row->url . " の内容】\n";
            }
            $text .= $row->content . "\n\n";
        }

        return $text;
    }
}
