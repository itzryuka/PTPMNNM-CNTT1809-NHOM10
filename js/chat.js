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

    // Nhớ khung chat đang mở / đóng khi chuyển trang (trong tab trình duyệt này)
    const OPEN_KEY = 'omnistock.chatOpen';

    function toggle(open, focus = true) {
        panel.hidden = !open;
        fab.setAttribute('aria-expanded', String(open));
        try { sessionStorage.setItem(OPEN_KEY, open ? '1' : '0'); } catch (e) {}
        if (open) {
            if (focus) input.focus();
            body.scrollTop = body.scrollHeight;
        }
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

    // ===== Điện thoại: nút trợ lý là bong bóng tròn kéo thả được =====
    // Kéo tới đâu thả ra thì bong bóng tự dạt vào mép trái / phải gần nhất (không che giữa màn hình),
    // vị trí được nhớ trong trình duyệt. Chạm nhẹ (không kéo) = mở khung chat.
    const mobile = window.matchMedia('(max-width: 768px)');
    const POS_KEY = 'omnistock.chatBubble';
    const EDGE = 10;                                         // khoảng cách tới mép màn hình
    let drag = null;
    let justDragged = false;

    function clampBubble(x, y) {
        const size = fab.offsetWidth;
        const tabBar = document.querySelector('.bottom-nav')?.getBoundingClientRect().height || 0;   // thanh tab dưới (0 nếu ẩn)
        return [
            Math.min(Math.max(x, EDGE), window.innerWidth - size - EDGE),
            Math.min(Math.max(y, EDGE + 56), window.innerHeight - tabBar - size - EDGE),   // không đè thanh trên / thanh tab dưới
        ];
    }

    function placeBubble(x, y) {
        [x, y] = clampBubble(x, y);
        fab.style.left = `${x}px`;
        fab.style.top = `${y}px`;
        fab.style.right = fab.style.bottom = 'auto';
    }

    function restoreBubble() {
        if (!mobile.matches) {
            fab.style.left = fab.style.top = fab.style.right = fab.style.bottom = '';   // máy tính: tab mép phải như cũ
            return;
        }
        let saved = null;
        try { saved = JSON.parse(localStorage.getItem(POS_KEY) || 'null'); } catch (e) {}
        if (!saved) return;                                  // chưa kéo lần nào: góc dưới phải (CSS)
        const size = fab.offsetWidth;
        placeBubble(saved.side === 'left' ? EDGE : window.innerWidth - size - EDGE, saved.ratio * window.innerHeight);
    }

    fab.addEventListener('pointerdown', event => {
        if (!mobile.matches || event.button > 0) return;
        const rect = fab.getBoundingClientRect();
        drag = { id: event.pointerId, dx: event.clientX - rect.left, dy: event.clientY - rect.top, x0: event.clientX, y0: event.clientY, moved: false };
        fab.setPointerCapture(event.pointerId);
    });
    fab.addEventListener('pointermove', event => {
        if (!drag || event.pointerId !== drag.id) return;
        if (!drag.moved && Math.hypot(event.clientX - drag.x0, event.clientY - drag.y0) < 6) return;   // rung tay khi chạm: chưa tính là kéo
        drag.moved = true;
        fab.classList.add('dragging');
        placeBubble(event.clientX - drag.dx, event.clientY - drag.dy);
    });
    function endDrag(event) {
        if (!drag || event.pointerId !== drag.id) return;
        const moved = drag.moved;
        drag = null;
        fab.classList.remove('dragging');
        if (!moved) return;
        justDragged = true;                                  // nhả tay sau khi kéo: không tính là bấm mở chat
        const rect = fab.getBoundingClientRect();
        const side = rect.left + rect.width / 2 < window.innerWidth / 2 ? 'left' : 'right';
        placeBubble(side === 'left' ? EDGE : window.innerWidth - rect.width - EDGE, rect.top);
        try { localStorage.setItem(POS_KEY, JSON.stringify({ side, ratio: rect.top / window.innerHeight })); } catch (e) {}
    }
    fab.addEventListener('pointerup', endDrag);
    fab.addEventListener('pointercancel', endDrag);
    window.addEventListener('resize', restoreBubble);
    mobile.addEventListener('change', restoreBubble);
    restoreBubble();

    fab.addEventListener('click', () => {
        if (justDragged) {
            justDragged = false;
            return;
        }
        toggle(panel.hidden);
    });
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

    // Vẽ lại các lượt hỏi - đáp đã lưu trong phiên đăng nhập (server giữ, xóa khi bấm "Cuộc trò chuyện mới")
    let history = [];
    try { history = JSON.parse(panel.dataset.history || '[]'); } catch (e) {}
    if (history.length) {
        body.querySelector('.js-chat-suggest')?.remove();
        history.forEach(turn => addMessage(turn.role === 'assistant' ? 'bot' : 'user', turn.content));
    }
    let wasOpen = false;
    try { wasOpen = sessionStorage.getItem(OPEN_KEY) === '1'; } catch (e) {}
    // Mở lại nhưng không giành con trỏ khỏi ô nhập của trang. Điện thoại thì không tự mở (khung chat che hết màn hình)
    if (wasOpen && !mobile.matches) toggle(true, false);
})();
