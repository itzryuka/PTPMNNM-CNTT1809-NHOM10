        </div>
    </div>

    <!-- Popup xác nhận dùng chung (nút có data-confirm) -->
    <div class="modal-backdrop" id="confirmModal">
        <div class="modal modal-sm" role="dialog" aria-modal="true" aria-labelledby="confirmTitle">
            <div class="modal-header">
                <h3 id="confirmTitle">Xác nhận</h3>
                <button class="modal-close" type="button" data-modal-close title="Đóng">&times;</button>
            </div>
            <div class="modal-body confirm-body">
                <i class="fas fa-exclamation-triangle"></i>
                <p class="js-confirm-message"></p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" type="button" data-modal-close>Hủy</button>
                <button class="btn btn-danger js-confirm-ok" type="button">Đồng ý</button>
            </div>
        </div>
    </div>

    <script src="<?= e(asset($basePath, 'js/main.js')) ?>"></script>
    <script src="<?= e(asset($basePath, 'js/modal.js')) ?>"></script>
    <?php foreach ($extraScripts ?? [] as $script): ?>
        <script src="<?= e(asset($basePath, $script)) ?>"></script>
    <?php endforeach; ?>
</body>
</html>
