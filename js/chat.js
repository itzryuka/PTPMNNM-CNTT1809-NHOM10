// Trợ lý AI (beta): mở / đóng khung chat, gửi câu hỏi tới handle/chat_process.php, hiện câu trả lời
(function () {
    const fab = document.getElementById('chatFab');
    const panel = document.getElementById('chatPanel');
    if (!fab || !panel) return;
    const body = panel.querySelector('.js-chat-body');
    const form = panel.querySelector('.js-chat-form');
    const input = form.querySelector('textarea');
    const sendButton = form.querySelector('button');
    const welcome = body.innerHTML;
    let busy = false;

    function toggle(open) {
        panel.hidden = !open;
        fab.setAttribute('aria-expanded', String(open));
        if (open) input.focus();
    }

    function escapeHtml(text) {
        return text.replace(/[&<>"']/g, ch => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
    }

    // Markdown tối giản cho câu trả lời: **đậm**, `mã`, gạch đầu dòng, xuống dòng (đã escape HTML trước)
    function renderMarkdown(text) {
        const lines = escapeHtml(text).split('\n');
        let html = '';
        let inList = false;
        lines.forEach(line => {
            const item = line.match(/^\s*[-*•]\s+(.*)$/);
            if (item) {
                if (!inList) { html += '<ul>'; inList = true; }
                html += `<li>${item[1]}</li>`;
                return;
            }
            if (inList) { html += '</ul>'; inList = false; }
            html += line.trim() === '' ? '<br>' : `${line}<br>`;
        });
        if (inList) html += '</ul>';
        return html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/`([^`]+)`/g, '<code>$1</code>').replace(/(<br>)+$/, '');
    }

    function addMessage(kind, text) {
        const div = document.createElement('div');
        div.className = `chat-msg ${kind}`;
        if (kind === 'bot') div.innerHTML = renderMarkdown(text);
        else div.textContent = text;
        body.appendChild(div);
        body.scrollTop = body.scrollHeight;
        return div;
    }

    async function post(data) {
        const formData = new FormData();
        formData.append('csrf_token', panel.dataset.csrf);
        Object.entries(data).forEach(([key, value]) => formData.append(key, value));
        const response = await fetch(panel.dataset.endpoint, { method: 'POST', body: formData, credentials: 'same-origin' });
        let json;
        try {
            json = await response.json();
        } catch (e) {
            json = { error: 'Phản hồi không hợp lệ từ máy chủ.' };
        }
        if (json.reload) setTimeout(() => location.reload(), 1500);
        return json;
    }

    async function ask(question) {
        question = question.trim();
        if (!question || busy) return;
        busy = true;
        sendButton.disabled = true;
        body.querySelector('.js-chat-suggest')?.remove();
        addMessage('user', question);
        input.value = '';
        input.style.height = '';
        const typing = addMessage('typing bot', '');
        typing.innerHTML = '<i class="fas fa-ellipsis-h"></i> Đang tra cứu...';
        try {
            const result = await post({ action: 'ask', message: question });
            typing.remove();
            if (result.reply) addMessage('bot', result.reply);
            else addMessage('error', result.error || 'Có lỗi xảy ra.');
        } catch (e) {
            typing.remove();
            addMessage('error', 'Không kết nối được máy chủ.');
        } finally {
            busy = false;
            sendButton.disabled = false;
            input.focus();
        }
    }

    fab.addEventListener('click', () => toggle(panel.hidden));
    panel.querySelector('.js-chat-close').addEventListener('click', () => toggle(false));
    panel.querySelector('.js-chat-reset').addEventListener('click', async () => {
        if (busy) return;
        await post({ action: 'reset' }).catch(() => {});
        body.innerHTML = welcome;
        bindSuggestions();
    });
    form.addEventListener('submit', event => {
        event.preventDefault();
        ask(input.value);
    });
    // Enter gửi, Shift+Enter xuống dòng; ô nhập tự giãn chiều cao
    input.addEventListener('keydown', event => {
        if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
            event.preventDefault();
            ask(input.value);
        }
    });
    input.addEventListener('input', () => {
        input.style.height = 'auto';
        input.style.height = `${input.scrollHeight}px`;
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !panel.hidden) toggle(false);
    });

    function bindSuggestions() {
        body.querySelectorAll('.js-chat-suggest button').forEach(button => button.addEventListener('click', () => ask(button.textContent)));
    }
    bindSuggestions();
})();
