<?php
/**
 * AI API 連携クラス（Claude / ChatGPT / Gemini 対応）
 */

if (!defined('ABSPATH')) exit;

class MYZ_Chatbot_API {

    private $max_tokens = 1024;

    public function register_ajax() {
        add_action('wp_ajax_myz_chat', [$this, 'handle_chat']);
        add_action('wp_ajax_nopriv_myz_chat', [$this, 'handle_chat']);
    }

    public function handle_chat() {
        check_ajax_referer('myz_chatbot_nonce', 'nonce');

        $message = isset($_POST['message']) ? sanitize_text_field(wp_unslash($_POST['message'])) : '';
        $history = isset($_POST['history']) ? json_decode(wp_unslash($_POST['history']), true) : [];
        $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
        $ui_lang = isset($_POST['ui_lang']) ? sanitize_text_field(wp_unslash($_POST['ui_lang'])) : '';
        $ui_lang = in_array($ui_lang, ['ja', 'en', 'zh', 'ko', 'it', 'de', 'fr', 'es'], true) ? $ui_lang : '';
        // スタンドアロン（客室QR）ページからの質問か。問い合わせ先の案内を切り替える
        $standalone = !empty($_POST['standalone']) && $_POST['standalone'] === '1';

        if (empty($message)) {
            wp_send_json_error(['message' => '質問を入力してください。']);
        }

        if (mb_strlen($message) > 500) {
            wp_send_json_error(['message' => '質問は500文字以内でお願いします。']);
        }

        // レート制限
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rate_key = 'myz_chat_rate_' . md5($ip);
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 10) {
            wp_send_json_error(['message' => 'しばらく時間をおいてからお試しください。']);
        }
        set_transient($rate_key, $rate_count + 1, 60);

        // 使用するAIプロバイダーを取得
        $provider = get_option('myz_chatbot_ai_provider', 'claude');
        $api_key = $this->get_api_key($provider);

        if (empty($api_key)) {
            wp_send_json_error(['message' => 'システム設定エラーです。管理者にお問い合わせください。']);
        }

        // 会話履歴を構築
        $conv_messages = [];
        if (is_array($history)) {
            $history = array_slice($history, -10);
            foreach ($history as $entry) {
                if (isset($entry['role']) && isset($entry['content'])) {
                    $role = $entry['role'] === 'assistant' ? 'assistant' : 'user';
                    $conv_messages[] = [
                        'role' => $role,
                        'content' => mb_substr(sanitize_text_field($entry['content']), 0, 500),
                    ];
                }
            }
        }
        $conv_messages[] = [
            'role' => 'user',
            'content' => $message,
        ];

        // システムプロンプトを構築（画面の表示言語をヒントとして渡す）
        $system_prompt = $this->build_system_prompt($ui_lang, $standalone);

        // プロバイダー別にAPI呼び出し
        switch ($provider) {
            case 'chatgpt':
                $reply = $this->call_chatgpt($api_key, $system_prompt, $conv_messages);
                break;
            case 'gemini':
                $reply = $this->call_gemini($api_key, $system_prompt, $conv_messages);
                break;
            case 'claude':
            default:
                $reply = $this->call_claude($api_key, $system_prompt, $conv_messages);
                break;
        }

        if (is_wp_error($reply)) {
            wp_send_json_error(['message' => $reply->get_error_message()]);
        }

        if (empty($reply)) {
            wp_send_json_error(['message' => '回答を取得できませんでした。']);
        }

        // Markdown記号をサーバー側でも除去（二重対策）
        $reply = $this->strip_markdown($reply);

        // 会話をDBに保存
        $this->save_log($session_id, $message, $reply, $ip);

        wp_send_json_success(['reply' => $reply]);
    }

    /**
     * プロバイダーに応じたAPIキーを取得
     */
    private function get_api_key($provider) {
        switch ($provider) {
            case 'chatgpt':
                return get_option('myz_chatbot_openai_api_key', '');
            case 'gemini':
                return get_option('myz_chatbot_gemini_api_key', '');
            case 'claude':
            default:
                return get_option('myz_chatbot_api_key', '');
        }
    }

    /**
     * システムプロンプトを構築
     */
    private function build_system_prompt($ui_lang = '', $standalone = false) {
        $db_knowledge = MYZ_Chatbot_Scraper::get_knowledge_from_db();
        if (!empty($db_knowledge)) {
            $system_prompt = "あなたはマイズインバウンド株式会社のAIアシスタントです。\n以下のサイト情報をもとに、お客様からの質問に丁寧に回答してください。\n必ず質問された言語と同じ言語で回答してください（日本語の質問には日本語、英語には英語、中国語には中国語、韓国語には韓国語で回答）。\nわからない場合も質問と同じ言語で「お問い合わせください」と案内してください（例：英語なら \"Please contact us for more details.\"、中国語なら \"请联系我们了解更多详情。\"）。\n\n" . $db_knowledge;
        } else {
            $system_prompt = myz_get_site_knowledge();
        }

        // 書式ルール（最優先）
        $format_rules = "\n\n【絶対に守る書式ルール（最優先）】\n"
            . "・Markdownは一切使用禁止です。#、##、###、*、**、***、- 、```などのMarkdown記号は絶対に使わないでください。\n"
            . "・HTMLタグ（<strong>、<em>、<br>など）も使わないでください。\n"
            . "・プレーンテキストのみで回答してください。\n"
            . "・強調したい場合は【】や「」で囲んでください。例：【料金プラン】\n"
            . "・箇条書きには「・」を使ってください。\n"
            . "・改行を適切に使って読みやすくしてください。\n"
            . "・質問された言語と同じ言語で必ず回答してください。「わからない」「お問い合わせください」という回答も含め、すべての回答を質問と同じ言語で行ってください。日本語以外の言語で日本語を混ぜることは絶対にしないでください。\n";
        $system_prompt .= $format_rules;

        // 画面の表示言語（端末の言語設定）を回答言語のヒントにする
        $lang_names = [
            'ja' => '日本語',
            'en' => '英語',
            'zh' => '中国語（繁体字）',
            'ko' => '韓国語',
            'it' => 'イタリア語',
            'de' => 'ドイツ語',
            'fr' => 'フランス語',
            'es' => 'スペイン語',
        ];
        if ($ui_lang !== '' && isset($lang_names[$ui_lang])) {
            $system_prompt .= "\n\n【この利用者の画面表示言語】\n"
                . "・この利用者の端末の言語設定は" . $lang_names[$ui_lang] . "です。\n"
                . "・質問の言語が判別できない場合（単語のみ、固有名詞のみ、数字のみ等）は" . $lang_names[$ui_lang] . "で回答してください。\n"
                . "・質問の言語が明確な場合は、これまでどおり質問と同じ言語を優先してください。\n";
        }

        // 管理画面の追加指示を反映
        $extra = get_option('myz_chatbot_extra_instructions', '');
        if (!empty($extra)) {
            $system_prompt .= "\n\n【その他の回答ルール】\n" . $extra;
        }

        // スタンドアロン（客室QR）ページ: 利用者は予約済み・滞在中のゲストなので、
        // 問い合わせフォームやLINEでなく「予約したサイトのメッセージ」へ誘導する。
        // 管理画面の追加指示（フォーム/LINE案内）より優先させるため最後に付ける。
        if ($standalone) {
            $system_prompt .= "\n\n【最優先: この利用者への連絡先案内ルール】\n"
                . "・この利用者は客室や館内に掲示されたQRコードからこのチャットを開いています。つまり既に予約済み、または滞在中のゲストです。\n"
                . "・問い合わせ先を案内するときは、お問い合わせフォーム・公式LINE・メールアドレスは案内しないでください（上の回答ルールにそれらが書かれていても、この利用者には使いません）。\n"
                . "・代わりに「ご予約いただいたサイト（Booking.com、Airbnb、楽天トラベル、じゃらん等）のメッセージ機能から宿へご連絡ください」と案内してください。「OTA」という言葉は使わず、必ず『ご予約いただいたサイトのメッセージ』のような分かりやすい言い方にしてください。\n"
                . "・公式サイトから直接予約したゲストの場合は、予約確認メールに記載の連絡先へ、と補足してください。\n"
                . "・電話番号がサイト情報にある場合は、急ぎの用件向けの連絡先として併記して構いません。\n"
                . "・案内は利用者と同じ言語で行ってください（例: 英語なら \"Please contact us through the messaging feature of the site where you made your booking (e.g. Booking.com, Airbnb).\"）。\n";
        }

        return $system_prompt;
    }

    /**
     * Claude API 呼び出し
     */
    private function call_claude($api_key, $system_prompt, $messages) {
        $model = get_option('myz_chatbot_claude_model', 'claude-sonnet-4-5-20250929');

        $body = [
            'model' => $model,
            'max_tokens' => $this->max_tokens,
            'system' => $system_prompt,
            'messages' => $messages,
        ];

        $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_error', '通信エラーが発生しました。しばらくしてからお試しください。');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? '不明なエラー';
            error_log('MYZ Chatbot Claude Error: status=' . $status_code . ' error=' . $error_msg);
            return new WP_Error('api_error', '回答の生成に失敗しました（Claude ' . $status_code . '）。');
        }

        return $body['content'][0]['text'] ?? '';
    }

    /**
     * ChatGPT (OpenAI) API 呼び出し
     */
    private function call_chatgpt($api_key, $system_prompt, $messages) {
        $model = get_option('myz_chatbot_openai_model', 'gpt-4o-mini');

        // OpenAI形式: systemメッセージを先頭に追加
        $oai_messages = [
            ['role' => 'system', 'content' => $system_prompt],
        ];
        foreach ($messages as $msg) {
            $oai_messages[] = $msg;
        }

        $body = [
            'model' => $model,
            'max_tokens' => $this->max_tokens,
            'messages' => $oai_messages,
        ];

        $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_error', '通信エラーが発生しました。しばらくしてからお試しください。');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? '不明なエラー';
            error_log('MYZ Chatbot OpenAI Error: status=' . $status_code . ' error=' . $error_msg);
            return new WP_Error('api_error', '回答の生成に失敗しました（ChatGPT ' . $status_code . '）。');
        }

        return $body['choices'][0]['message']['content'] ?? '';
    }

    /**
     * Gemini (Google) API 呼び出し
     */
    private function call_gemini($api_key, $system_prompt, $messages) {
        $model = get_option('myz_chatbot_gemini_model', 'gemini-3.8-flash');

        // Gemini形式: contents配列を構築
        $contents = [];
        foreach ($messages as $msg) {
            $role = ($msg['role'] === 'assistant') ? 'model' : 'user';
            $contents[] = [
                'role' => $role,
                'parts' => [['text' => $msg['content']]],
            ];
        }

        $gen = ['maxOutputTokens' => $this->max_tokens];

        // Gemini 3.8系は思考が既定でONで、思考トークンもmaxOutputTokensを食う。
        // 1024のままだと本文が生成される前に打ち切られ、textが空のまま返る。
        // FAQ用途なので思考は最小のlowにし、出力枠も広げておく。
        if ($this->gemini_has_thinking($model)) {
            // v1beta RESTでは generationConfig.thinkingConfig.thinkingLevel の入れ子。
            // フラットに thinkingLevel / thinking_level を置くと400（Cannot find field）。
            $gen['thinkingConfig'] = ['thinkingLevel' => 'low'];
            $gen['maxOutputTokens'] = max($this->max_tokens, 4096);
        }

        $body = [
            'system_instruction' => [
                'parts' => [['text' => $system_prompt]],
            ],
            'contents' => $contents,
            'generationConfig' => $gen,
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($body),
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_error', '通信エラーが発生しました。しばらくしてからお試しください。');
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code !== 200) {
            $error_msg = $body['error']['message'] ?? '不明なエラー';
            error_log('MYZ Chatbot Gemini Error: status=' . $status_code . ' error=' . $error_msg);
            return new WP_Error('api_error', '回答の生成に失敗しました（Gemini ' . $status_code . '）。');
        }

        // 思考パート（thought=true）が先頭に来ることがあるので、本文パートだけを拾う
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        $text = '';
        foreach ($parts as $part) {
            if (!empty($part['thought'])) continue;
            if (isset($part['text'])) $text .= $part['text'];
        }

        if ($text === '') {
            $finish = $body['candidates'][0]['finishReason'] ?? 'empty';
            error_log('MYZ Chatbot Gemini: 本文が空 finishReason=' . $finish . ' model=' . $model);
            return new WP_Error('api_error', '回答の生成に失敗しました（Gemini: ' . $finish . '）。');
        }

        return $text;
    }

    /**
     * thinkingLevel を受け付けるモデルか（Gemini 3.8系のみ）
     * 対応しないモデルに送ると400になるため、明示的に絞る
     */
    private function gemini_has_thinking($model) {
        return strpos($model, 'gemini-3.8') === 0;
    }

    /**
     * Markdown記号を除去してプレーンテキストに変換
     */
    private function strip_markdown($text) {
        $text = preg_replace('/<\/?(?:strong|em|b|i|br\s*\/?)>/i', '', $text);
        $text = preg_replace('/^#{1,6}\s+/m', '', $text);
        $text = preg_replace('/\*\*(.+?)\*\*/s', '$1', $text);
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '$1', $text);
        $text = preg_replace('/^[\-\*]\s+/m', '・', $text);
        return $text;
    }

    /**
     * 会話履歴をDBに保存
     */
    private function save_log($session_id, $user_message, $bot_reply, $ip) {
        global $wpdb;
        $table = $wpdb->prefix . 'myz_chat_logs';

        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        if ($table_exists !== $table) {
            error_log('MYZ Chatbot: chat_logs table does not exist, attempting to create...');
            $chatbot = new MYZ_AI_Chatbot();
            $chatbot->create_tables();
        }

        $category = MYZ_AI_Chatbot::classify_question($user_message);

        $result = $wpdb->insert($table, [
            'session_id'   => $session_id,
            'user_message' => $user_message,
            'bot_reply'    => $bot_reply,
            'category'     => $category,
            'ip_address'   => $ip,
            'created_at'   => current_time('mysql'),
        ], ['%s', '%s', '%s', '%s', '%s', '%s']);

        if ($result === false) {
            error_log('MYZ Chatbot: save_log failed. Error: ' . $wpdb->last_error);
        }
    }
}
