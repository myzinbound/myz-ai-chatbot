(function () {
    'use strict';

    const container = document.getElementById('myz-chatbot-container');
    if (!container) return;

    const toggle = document.getElementById('myz-chatbot-toggle');
    const window_ = document.getElementById('myz-chatbot-window');
    const closeBtn = document.getElementById('myz-chatbot-close');
    const input = document.getElementById('myz-chatbot-input');
    const sendBtn = document.getElementById('myz-chatbot-send');
    const messages = document.getElementById('myz-chatbot-messages');

    let isOpen = false;
    let isLoading = false;
    let isToggleVisible = false;
    let scrollHideTimer = null;
    let history = [];
    const sessionId = 's_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);

    // スクロール中のみ表示、停止後3秒でフェードアウト
    function showToggle() {
        if (isOpen) return;
        if (!isToggleVisible) {
            isToggleVisible = true;
            toggle.classList.add('myz-visible');
        }
        // タイマーをリセット
        if (scrollHideTimer) clearTimeout(scrollHideTimer);
        scrollHideTimer = setTimeout(function () {
            if (!isOpen) {
                isToggleVisible = false;
                toggle.classList.remove('myz-visible');
            }
        }, 3000);
    }

    function checkScroll() {
        var scrollY = window.pageYOffset || document.documentElement.scrollTop;
        if (scrollY > 200) {
            showToggle();
        } else if (!isOpen) {
            isToggleVisible = false;
            toggle.classList.remove('myz-visible');
            if (scrollHideTimer) clearTimeout(scrollHideTimer);
        }
    }
    window.addEventListener('scroll', checkScroll, { passive: true });

    // スマホ・タブレット判定
    function isMobile() {
        return window.innerWidth <= 768;
    }

    // ウィンドウの開閉
    toggle.addEventListener('click', function () {
        isOpen = !isOpen;
        if (isOpen) {
            window_.classList.remove('myz-hidden');
            if (isMobile()) {
                toggle.classList.add('myz-toggle-hidden');
            }
            input.focus();
        } else {
            window_.classList.add('myz-hidden');
            toggle.classList.remove('myz-toggle-hidden');
            showToggle();
        }
    });

    closeBtn.addEventListener('click', function () {
        isOpen = false;
        window_.classList.add('myz-hidden');
        toggle.classList.remove('myz-toggle-hidden');
        // 閉じたらボタンを一度表示して3秒後にフェードアウト
        showToggle();
    });

    // メッセージ送信
    sendBtn.addEventListener('click', sendMessage);
    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.isComposing) {
            e.preventDefault();
            sendMessage();
        }
    });

    function sendMessage() {
        const text = input.value.trim();
        if (!text || isLoading) return;

        appendMessage('user', text);
        history.push({ role: 'user', content: text });
        input.value = '';

        showTyping();
        isLoading = true;
        sendBtn.disabled = true;

        // ページキャッシュで古いnonceが配られた場合、サーバーが新しいnonceを返すので1回だけ再送する
        function postChat(retried) {
            const formData = new FormData();
            formData.append('action', 'myz_chat');
            formData.append('nonce', myzChatbot.nonce);
            formData.append('message', text);
            formData.append('history', JSON.stringify(history.slice(-11, -1)));
            formData.append('session_id', sessionId);
            formData.append('page_lang', myzChatbot.pageLang || '');
            return fetch(myzChatbot.ajaxUrl, { method: 'POST', body: formData })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!retried && data && data.success === false && data.data && data.data.code === 'bad_nonce' && data.data.nonce) {
                        myzChatbot.nonce = data.data.nonce;
                        return postChat(true);
                    }
                    return data;
                });
        }

        postChat(false)
            .then(function (data) {
                removeTyping();
                if (data && data.success && data.data.reply) {
                    appendMessage('bot', data.data.reply);
                    history.push({ role: 'assistant', content: data.data.reply });
                } else {
                    var errorMsg = (data && data.data && data.data.message)
                        ? data.data.message
                        : '申し訳ございません。エラーが発生しました。';
                    appendMessage('bot', errorMsg);
                }
            })
            .catch(function () {
                removeTyping();
                appendMessage('bot', '通信エラーが発生しました。しばらくしてからお試しください。');
            })
            .finally(function () {
                isLoading = false;
                sendBtn.disabled = false;
            });
    }

    /**
     * テキストを整形してHTMLに変換（Markdown記号は完全除去）
     */
    function formatReply(text) {
        // HTMLエスケープ
        var s = text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');

        // HTMLタグが混入した場合も除去（&lt;strong&gt;等）
        s = s.replace(/&lt;\/?(?:strong|em|b|i|br\s*\/?)&gt;/g, '');

        // 見出し記号を完全除去（## テキスト → テキスト）
        s = s.replace(/^#{1,6}\s+/gm, '');

        // 太字記号を完全除去（**テキスト** → テキスト）
        s = s.replace(/\*\*(.+?)\*\*/g, '$1');

        // イタリック記号を除去
        s = s.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '$1');

        // Markdownリストマーカーを「・」に変換
        s = s.replace(/^[\-\*]\s+/gm, '・');

        // 残った孤立アスタリスクを除去
        s = s.replace(/^\*\s*/gm, '');

        // 【】で囲まれた見出しを太字表示
        s = s.replace(/【(.+?)】/g, '<strong>【$1】</strong>');

        // URLを自動リンク化（https://... や http://...）
        s = s.replace(/(https?:\/\/[^\s<>&「」）)】]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer" style="color:var(--myz-primary);word-break:break-all;">$1</a>');

        // メールアドレスを自動リンク化
        s = s.replace(/([a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,})/g, '<a href="mailto:$1" style="color:var(--myz-primary);">$1</a>');

        // 改行を適切にHTMLに変換
        s = s.replace(/\n\n+/g, '<br><br>');
        s = s.replace(/\n/g, '<br>');

        return s;
    }

    function appendMessage(type, text) {
        var div = document.createElement('div');
        div.className = 'myz-message ' + (type === 'user' ? 'myz-user' : 'myz-bot');

        var content = document.createElement('div');
        content.className = 'myz-message-content';

        if (type === 'bot') {
            content.innerHTML = formatReply(text);
        } else {
            content.textContent = text;
        }

        div.appendChild(content);
        messages.appendChild(div);

        if (type === 'user') {
            // ユーザーメッセージは最下部へスクロール
            messages.scrollTop = messages.scrollHeight;
        } else {
            // ボットの回答は先頭が見えるようにスクロール
            var msgTop = div.offsetTop - messages.offsetTop - 8;
            messages.scrollTop = msgTop;
        }
    }

    function showTyping() {
        var div = document.createElement('div');
        div.className = 'myz-message myz-bot';
        div.id = 'myz-typing-indicator';

        var content = document.createElement('div');
        content.className = 'myz-typing';
        content.innerHTML =
            '<span class="myz-typing-dot"></span>' +
            '<span class="myz-typing-dot"></span>' +
            '<span class="myz-typing-dot"></span>';

        div.appendChild(content);
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
    }

    function removeTyping() {
        var el = document.getElementById('myz-typing-indicator');
        if (el) el.remove();
    }
})();
