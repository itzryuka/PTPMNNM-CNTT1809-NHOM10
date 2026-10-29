        </div>
    </div>

    <!-- Popup xác nhận / thông báo dùng chung (thay cho confirm() / alert() của trình duyệt), điều khiển bởi js/modal.js -->
    <div class="modal-backdrop" id="confirmModal">
        <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
            <div class="modal-header">
                <h3 id="confirmTitle" class="js-confirm-title">Xác nhận</h3>
                <button class="modal-close" type="button" data-modal-close title="Đóng">&times;</button>
            </div>
            <div class="modal-body confirm-body js-confirm-body">
                <i class="fas fa-exclamation-triangle js-confirm-icon"></i>
                <p class="js-confirm-message"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light js-confirm-cancel" type="button" data-modal-close>Hủy</button>
                <button class="btn btn-danger js-confirm-ok" type="button">Đồng ý</button>
            </div>
        </div>
    </div>

    <!-- Trợ lý AI (beta): tab mỏng gắn sát mép phải màn hình (không che bảng / phân trang), khung chat điều khiển bởi js/chat.js -->
    <button class="chat-fab" type="button" id="chatFab" title="Trợ lý AI (beta)" aria-expanded="false" aria-controls="chatPanel">
        <i class="fas fa-robot"></i><span class="chat-fab-label">Trợ lý AI</span><span class="chat-beta">BETA</span>
    </button>
    <section class="chat-panel" id="chatPanel" hidden aria-label="Trợ lý AI"
             data-endpoint="<?= e($basePath . 'handle/chat_process.php') ?>" data-csrf="<?= e(csrfToken()) ?>">
        <header class="chat-head">
            <i class="fas fa-robot"></i>
            <div class="chat-title">Trợ lý OmniStock <small>Beta · chỉ tra cứu, theo kho bạn được xem</small></div>
            <button type="button" class="js-chat-reset" title="Cuộc trò chuyện mới"><i class="fas fa-redo"></i></button>
            <button type="button" class="js-chat-close" title="Đóng"><i class="fas fa-times"></i></button>
        </header>
        <div class="chat-body js-chat-body">
            <div class="chat-msg bot">Xin chào <?= e($user['full_name']) ?>! Mình tra cứu được tồn kho, lô sắp hết hạn, phiếu chờ duyệt, hàng đang đi đường... ở các kho bạn được xem.</div>
            <div class="chat-suggest js-chat-suggest">
                <button type="button">Tổng quan kho của tôi hôm nay</button>
                <button type="button">Lô nào sắp hết hạn?</button>
                <button type="button">Phiếu nhập nào đang chờ duyệt?</button>
                <button type="button">Có điều chuyển nào quá hạn chưa nhận?</button>
            </div>
        </div>
        <form class="chat-form js-chat-form">
            <textarea rows="1" maxlength="1000" placeholder="Hỏi về tồn kho, lô, phiếu..." aria-label="Câu hỏi"></textarea>
            <button type="submit" title="Gửi"><i class="fas fa-paper-plane"></i></button>
        </form>
        <div class="chat-note">AI có thể nhầm: kiểm tra lại số liệu quan trọng ở trang báo cáo.</div>
    </section>

    <script src="<?= e(asset($basePath, 'js/main.js')) ?>"></script>
    <script src="<?= e(asset($basePath, 'js/chat.js')) ?>"></script>
    <script src="<?= e(asset($basePath, 'js/modal.js')) ?>"></script>
    <?php foreach ($extraScripts ?? [] as $script): ?>
        <script src="<?= e(asset($basePath, $script)) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
