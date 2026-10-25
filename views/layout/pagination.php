<?php
// Thanh phân trang dùng chung. Cần có: $page, $totalPages, $pageSize, $keyword
?>
            <div class="pagination-container">
                <div class="page-size">
                    Hiển thị
                    <select id="pageSize">
                        <?php foreach ([10, 20, 30, 50] as $size): ?>
                            <option value="<?= $size ?>" <?= $size === $pageSize ? 'selected' : '' ?>><?= $size ?></option>
                        <?php endforeach; ?>
                    </select>
                     dữ liệu / trang
                </div>
                <ul class="pagination">
                    <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                        <a href="<?= e(pageUrl($page - 1, $keyword, $pageSize)) ?>">&laquo;</a>
                    </li>
                    <?php for ($p = max(1, $page - 2); $p <= min($totalPages, $page + 2); $p++): ?>
                        <li class="<?= $p === $page ? 'active' : '' ?>">
                            <a href="<?= e(pageUrl($p, $keyword, $pageSize)) ?>"><?= $p ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="<?= $page >= $totalPages ? 'disabled' : '' ?>">
                        <a href="<?= e(pageUrl($page + 1, $keyword, $pageSize)) ?>">&raquo;</a>
                    </li>
                </ul>
                <div class="page-jump">
                    Chuyển đến trang:
                    <select id="pageJump">
                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <option value="<?= $p ?>" <?= $p === $page ? 'selected' : '' ?>><?= $p ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
