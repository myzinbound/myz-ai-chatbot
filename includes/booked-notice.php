<?php
/**
 * 予約済みのお客様への案内（お問い合わせフォームの直前に表示）— v5.27.0〜（v5.27.3 で「当サイトから直接ご予約の方は」の記載を削除・ユーザー指示／v5.27.5 で CSS だけのウィジェットに出ないよう修正）
 *
 * 公式サイトのお問い合わせフォームに、予約サイト（Booking.com・Agoda 等）で予約済みのゲストから
 * 「予約できているか確認したい」「チェックイン方法は？」といった連絡が増えた（2026-10〜）。
 * 予約済みの方は予約サイトのメッセージ機能から連絡してもらうよう、フォームの直前に目立つ案内を出す。
 *
 * ・宿サイト（MYZ_AI_Chatbot::category_set() === 'hotel'）だけ。会社サイト（myzminpaku.com）には出さない。
 * ・設定「予約済みのお客様への案内」（myz_chatbot_booked_notice・既定ON）でOFFにできる。
 * ・Contact Form 7 のショートコード出力の直前に差し込む（Elementor のショートコードウィジェット・本文）。
 *   Happy Addons の CF7 ウィジェット（ha-cf7）はショートコードを通らないので、Elementor のウィジェット出力フィルターでも差し込む（v5.27.1）。
 *   1ページに複数フォームがあっても最初の1つだけ。
 * ・<head> より前の描画（AIOSEO が説明文を作るために本文のショートコードを展開する等）では差し込まない。
 *   v5.27.0〜5.27.1 はそこで「表示済み」になり、CP の本物のフォームに出なかった（v5.27.2 で修正）。
 * ・言語はページのURL（/en-contact/ /tw-contact/ /cn-contact/ /ko-contact/ /en/contact/ /zh/contact/ 等）→ ページのロケールの順で判定。
 */
if (!defined('ABSPATH')) exit;

class MYZ_Chatbot_Booked_Notice {

    private static $shown = false;

    public static function init() {
        add_filter('do_shortcode_tag', [__CLASS__, 'before_form'], 10, 2);
        add_filter('elementor/widget/render_content', [__CLASS__, 'before_widget_form'], 10, 2);
    }

    /** ページ本体の描画中か（<head> の出力より前は SEO プラグイン等の下書き描画なので除外） */
    private static function in_body() {
        // did_action は wp_head の開始時点で 1 になるので、AIOSEO 等が wp_head の中で本文を展開する場合は doing_action で除く（v5.27.2）
        return did_action('wp_head') > 0 && !doing_action('wp_head') && !is_admin() && !wp_doing_ajax() && !(defined('REST_REQUEST') && REST_REQUEST) && !is_feed();
    }

    /** Elementor ウィジェット（Happy Addons の ha-cf7 など）の出力に CF7 フォームがあれば、その直前に差し込む */
    public static function before_widget_form($content, $widget) {
        // 本物のフォーム（<form class="wpcf7-form …">）がある時だけ。CSS だけの HTML ウィジェット（.wpcf7 の装飾指定）に反応して
        // ヒーロー・フッターに案内が出ていた（EZ・v5.27.5 で修正）
        if (self::$shown || !is_string($content) || strpos($content, 'wpcf7') === false || !self::in_body()) return $content;
        if (!preg_match('/<form\b[^>]*\bclass=["\'][^"\']*\bwpcf7-form\b/i', $content)) return $content;
        if (!self::enabled()) return $content;
        self::$shown = true;
        $lang = self::lang();
        return apply_filters('myz_chatbot_booked_notice_html', self::html($lang), $lang) . $content;
    }

    public static function enabled() {
        if (!class_exists('MYZ_AI_Chatbot') || MYZ_AI_Chatbot::category_set() !== 'hotel') return false;
        return get_option('myz_chatbot_booked_notice', '1') === '1';
    }

    public static function before_form($output, $tag) {
        if ($tag !== 'contact-form-7' && $tag !== 'contact-form') return $output;
        if (self::$shown || !self::in_body()) return $output;
        if (!self::enabled()) return $output;
        self::$shown = true;
        $lang = self::lang();
        return apply_filters('myz_chatbot_booked_notice_html', self::html($lang), $lang) . $output;
    }

    /** ページ言語: ja / en / zh-hant / zh-hans / ko / de / fr / es / it */
    public static function lang() {
        $path = strtolower(isset($_SERVER['REQUEST_URI']) ? (string) wp_parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '');
        $has = function ($code) use ($path) {
            return strpos($path, '/' . $code . '/') !== false || strpos($path, '/' . $code . '-') !== false || strpos($path, '-' . $code . '/') !== false;
        };
        if ($has('cn')) return 'zh-hans';
        if ($has('tw') || $has('zh')) return 'zh-hant';
        if ($has('ko')) return 'ko';
        if ($has('en')) return 'en';
        foreach (['de', 'fr', 'es', 'it'] as $eu) {
            if ($has($eu)) return $eu;
        }
        $loc = strtolower(str_replace('_', '-', function_exists('determine_locale') ? determine_locale() : get_locale()));
        if (strpos($loc, 'zh-cn') === 0 || strpos($loc, 'zh-hans') === 0 || strpos($loc, 'zh-sg') === 0) return 'zh-hans';
        if (strpos($loc, 'zh') === 0) return 'zh-hant';
        foreach (['ko', 'en', 'de', 'fr', 'es', 'it'] as $c) {
            if (strpos($loc, $c) === 0) return $c;
        }
        return 'ja';
    }

    public static function texts($lang) {
        $t = [
            'ja' => [
                'title' => 'ご予約済みのお客様へ',
                'lead'  => 'ご予約内容の確認・変更・キャンセル、チェックイン方法、ご滞在中のご連絡は、<strong>ご予約いただいたサイト（Booking.com・Airbnb・Agoda・楽天トラベル・じゃらん など）のメッセージ機能</strong>からお送りください。',
                'items' => [
                    '予約サイトからのご連絡なら、ご予約内容をすぐに確認でき、より早くお返事できます。',
                    'ご予約が成立しているかは、予約サイトから届く予約確認メール、または予約サイトのマイページでご確認いただけます。宿へ改めてお問い合わせいただく必要はございません。',
                    '予約サイトから連絡できない場合は、予約番号を添えて下のフォームからご連絡ください。',
                ],
                'foot'  => 'ご予約前のご質問は、下のフォームからお気軽にどうぞ。',
            ],
            'en' => [
                'title' => 'Already booked with us?',
                'lead'  => 'For questions about your reservation (checking, changing or cancelling it), check-in instructions, or anything during your stay, please <strong>message us through the website or app where you made your booking (Booking.com, Airbnb, Agoda, Expedia, Trip.com, etc.)</strong>.',
                'items' => [
                    'We can see your booking details there right away, so we can reply faster.',
                    'To check that your booking is confirmed, please see the confirmation email from the booking site or your account page on that site. There is no need to contact us to confirm it.',
                    'If you cannot message us through the booking site, please use the form below and include your booking number.',
                ],
                'foot'  => 'Questions before booking are welcome via the form below.',
            ],
            'zh-hant' => [
                'title' => '已預訂的旅客請注意',
                'lead'  => '關於預訂內容的確認、變更或取消、入住方式，以及住宿期間的聯絡，請<strong>透過您預訂時使用的網站或App（Booking.com、Airbnb、Agoda、Trip.com 等）的訊息功能</strong>與我們聯繫。',
                'items' => [
                    '透過訂房網站聯絡，我們能立即確認您的預訂內容，更快回覆您。',
                    '預訂是否成功，請查看訂房網站寄出的預訂確認信，或該網站的會員頁面。無需另外向本館確認。',
                    '若無法透過訂房網站聯絡，請於下方表單填寫預訂編號後與我們聯繫。',
                ],
                'foot'  => '預訂前的疑問，歡迎使用下方表單詢問。',
            ],
            'zh-hans' => [
                'title' => '已预订的客人请注意',
                'lead'  => '关于预订内容的确认、变更或取消、入住方式，以及入住期间的联系，请<strong>通过您预订时使用的网站或App（Booking.com、Airbnb、Agoda、携程 Trip.com 等）的消息功能</strong>与我们联系。',
                'items' => [
                    '通过订房网站联系，我们能立即确认您的预订内容，更快回复您。',
                    '预订是否成功，请查看订房网站发送的预订确认邮件，或该网站的会员页面。无需另行向本酒店确认。',
                    '如果无法通过订房网站联系，请在下方表单中填写预订编号后与我们联系。',
                ],
                'foot'  => '预订前的疑问，欢迎通过下方表单咨询。',
            ],
            'ko' => [
                'title' => '이미 예약하신 고객님께',
                'lead'  => '예약 내용 확인·변경·취소, 체크인 방법, 숙박 중 연락은 <strong>예약하신 사이트 또는 앱(Booking.com, Airbnb, Agoda, Trip.com 등)의 메시지 기능</strong>으로 보내 주세요.',
                'items' => [
                    '예약 사이트로 연락 주시면 예약 내용을 바로 확인할 수 있어 더 빠르게 답변드릴 수 있습니다.',
                    '예약 완료 여부는 예약 사이트에서 발송된 예약 확인 메일 또는 해당 사이트의 마이페이지에서 확인하실 수 있습니다. 숙소에 따로 확인하실 필요는 없습니다.',
                    '예약 사이트로 연락이 어려운 경우에는, 예약 번호를 적어 아래 양식으로 문의해 주세요.',
                ],
                'foot'  => '예약 전 문의는 아래 양식으로 편하게 보내 주세요.',
            ],
            'de' => [
                'title' => 'Sie haben bereits gebucht?',
                'lead'  => 'Für Fragen zu Ihrer Buchung (Prüfung, Änderung oder Stornierung), zum Check-in oder während Ihres Aufenthalts schreiben Sie uns bitte <strong>über die Nachrichtenfunktion der Website oder App, über die Sie gebucht haben (Booking.com, Airbnb, Agoda, Expedia usw.)</strong>.',
                'items' => [
                    'Dort sehen wir Ihre Buchungsdaten sofort und können schneller antworten.',
                    'Ob Ihre Buchung bestätigt ist, sehen Sie in der Bestätigungs-E-Mail der Buchungsseite oder in Ihrem Konto dort. Eine zusätzliche Bestätigung bei uns ist nicht nötig.',
                    'Wenn Sie uns über die Buchungsseite nicht erreichen, nutzen Sie bitte das Formular unten und geben Sie Ihre Buchungsnummer an.',
                ],
                'foot'  => 'Fragen vor der Buchung beantworten wir gern über das Formular unten.',
            ],
            'fr' => [
                'title' => 'Vous avez déjà réservé ?',
                'lead'  => 'Pour toute question sur votre réservation (vérification, modification ou annulation), l’arrivée ou pendant votre séjour, merci de nous écrire <strong>via la messagerie du site ou de l’application où vous avez réservé (Booking.com, Airbnb, Agoda, Expedia, etc.)</strong>.',
                'items' => [
                    'Nous y voyons immédiatement votre réservation et pouvons vous répondre plus vite.',
                    'Pour vérifier que votre réservation est confirmée, consultez l’e-mail de confirmation du site de réservation ou votre espace client sur ce site. Inutile de nous contacter pour le confirmer.',
                    'Si vous ne pouvez pas nous écrire via le site de réservation, utilisez le formulaire ci-dessous en indiquant votre numéro de réservation.',
                ],
                'foot'  => 'Pour les questions avant réservation, le formulaire ci-dessous est à votre disposition.',
            ],
            'es' => [
                'title' => '¿Ya tiene una reserva?',
                'lead'  => 'Para consultas sobre su reserva (comprobarla, modificarla o cancelarla), el check-in o durante su estancia, escríbanos <strong>a través de la mensajería del sitio web o la app donde reservó (Booking.com, Airbnb, Agoda, Expedia, etc.)</strong>.',
                'items' => [
                    'Allí vemos los datos de su reserva al instante y podemos responder más rápido.',
                    'Para comprobar que su reserva está confirmada, revise el correo de confirmación del sitio de reservas o su cuenta en ese sitio. No es necesario contactarnos para confirmarla.',
                    'Si no puede escribirnos desde el sitio de reservas, use el formulario de abajo e indique su número de reserva.',
                ],
                'foot'  => 'Las preguntas antes de reservar son bienvenidas a través del formulario de abajo.',
            ],
            'it' => [
                'title' => 'Ha già prenotato?',
                'lead'  => 'Per domande sulla prenotazione (verifica, modifica o cancellazione), sul check-in o durante il soggiorno, ci scriva <strong>tramite la messaggistica del sito o dell’app con cui ha prenotato (Booking.com, Airbnb, Agoda, Expedia, ecc.)</strong>.',
                'items' => [
                    'Lì vediamo subito i dati della prenotazione e possiamo rispondere più rapidamente.',
                    'Per verificare che la prenotazione sia confermata, controlli l’e-mail di conferma del sito di prenotazione o il suo account su quel sito. Non è necessario contattarci per confermarla.',
                    'Se non riesce a scriverci tramite il sito di prenotazione, usi il modulo qui sotto indicando il numero di prenotazione.',
                ],
                'foot'  => 'Per domande prima della prenotazione, usi pure il modulo qui sotto.',
            ],
        ];
        return isset($t[$lang]) ? $t[$lang] : $t['ja'];
    }

    public static function html($lang) {
        $t = self::texts($lang);
        $allowed = ['strong' => []];
        $icon = '<svg class="myz-booked-notice__icon" viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false">'
            . '<rect x="3.5" y="5" width="17" height="15.5" rx="2.5" fill="none" stroke="currentColor" stroke-width="1.8"/>'
            . '<path d="M3.5 10h17M8 3v4M16 3v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>'
            . '<path d="M8.8 15.2l2.2 2.2 4.4-4.6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
        $items = '';
        foreach ($t['items'] as $it) {
            $items .= '<li>' . wp_kses($it, $allowed) . '</li>';
        }
        $html_lang = ['zh-hant' => 'zh-Hant', 'zh-hans' => 'zh-Hans'];
        $hl = isset($html_lang[$lang]) ? $html_lang[$lang] : $lang;
        return '<style id="myz-booked-notice-css">' . self::css() . '</style>'
            . '<div class="myz-booked-notice" id="booked-guests" role="note" lang="' . esc_attr($hl) . '">'
            . '<p class="myz-booked-notice__title">' . $icon . '<span>' . esc_html($t['title']) . '</span></p>'
            . '<p class="myz-booked-notice__lead">' . wp_kses($t['lead'], $allowed) . '</p>'
            . '<ul class="myz-booked-notice__list">' . $items . '</ul>'
            . '<p class="myz-booked-notice__foot">' . esc_html($t['foot']) . '</p>'
            . '</div>';
    }

    private static function css() {
        // テーマ（Hello Elementor・各サイトの追加CSS）の p/ul 指定に負けないよう、クラスで閉じて書く
        return '.myz-booked-notice{box-sizing:border-box;max-width:100%;margin:0 0 32px;padding:22px 24px 20px;border:2px solid #d9a441;border-radius:12px;background:#fff8ea;color:#2b2416;text-align:left;font-size:15px;line-height:1.8;letter-spacing:normal}'
            . '.myz-booked-notice p,.myz-booked-notice ul,.myz-booked-notice li{color:#2b2416;font-family:inherit;text-align:left;letter-spacing:normal;text-transform:none}'
            . '.myz-booked-notice .myz-booked-notice__title{display:flex;align-items:center;gap:10px;margin:0 0 10px;padding:0;font-size:1.25em;font-weight:700;line-height:1.4;color:#7a4b00}'
            . '.myz-booked-notice__icon{flex:0 0 auto;width:26px;height:26px;color:#b7791f}'
            . '.myz-booked-notice .myz-booked-notice__lead{margin:0 0 12px;padding:0;font-size:1.02em;font-weight:500}'
            . '.myz-booked-notice strong{font-weight:700;color:#2b2416;background:linear-gradient(transparent 58%,#ffe08a 58%);padding:0 2px}'
            . '.myz-booked-notice .myz-booked-notice__list{margin:0 0 12px;padding:0 0 0 1.3em;list-style:disc outside}'
            . '.myz-booked-notice .myz-booked-notice__list li{margin:0 0 6px;padding:0;font-size:.95em;list-style:disc outside}'
            . '.myz-booked-notice .myz-booked-notice__foot{margin:0;padding:12px 0 0;border-top:1px dashed #e3c27f;font-size:.93em;color:#5b4a2a}'
            . '@media (max-width:767px){.myz-booked-notice{padding:18px 16px 16px;font-size:14.5px;line-height:1.75}.myz-booked-notice .myz-booked-notice__title{font-size:1.15em}}';
    }
}
MYZ_Chatbot_Booked_Notice::init();
