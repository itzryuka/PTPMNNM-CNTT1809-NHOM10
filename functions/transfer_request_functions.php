<?php
// YÊU CẦU ĐIỀU CHUYỂN: kho CẦN hàng chủ động đề nghị, kho CÓ hàng quyết định.
//   1. Nhân viên kho cần hàng xem "có thể xuất" ở các kho khác (chỉ con số tổng, không sửa được kho khác)
//   2. Lập yêu cầu: mặt hàng, số lượng, cần trước ngày, lý do (khách / đơn hàng)
//   3. Quản lý kho có hàng ĐỒNG Ý -> trong 1 transaction: chọn lô hết hạn trước (FEFO) còn có thể xuất,
//      lập phiếu điều chuyển, GIỮ CHỖ hàng, phiếu ở trạng thái "Đã duyệt · chờ gửi" -> rồi gửi / nhận như điều chuyển thường
//      hoặc TỪ CHỐI (bắt buộc lý do). Bên yêu cầu rút lại được khi chưa xử lý.
// Cần nạp trước: helpers.php, auth.php, voucher_functions.php

const TRANSFER_REQUEST_STATUS = [
    'PENDING'   => ['badge-pending', 'Chờ kho có hàng xử lý'],
    'ACCEPTED'  => ['badge-active', 'Đã đồng ý · đã lập phiếu'],
    'DECLINED'  => ['badge-rejected', 'Bị từ chối'],
    'CANCELLED' => ['badge-inactive', 'Đã rút lại'],
];

function transferRequestStatusBadge(string $status): string
{
    [$class, $label] = TRANSFER_REQUEST_STATUS[$status] ?? ['badge-inactive', $status];
    return '<span class="badge ' . $class . '">' . e($label) . '</span>';
}

/**
 * "Có thể xuất" của các mặt hàng ở mọi kho đang hoạt động (bỏ lô hết hạn, trừ hàng đang giữ).
 * Ai đăng nhập cũng xem được con số tổng này để biết nên xin kho nào. Trả về [item_id => [warehouse_id => số]].
 */
function getNetworkAvailability(PDO $pdo): array
{
    $rows = $pdo->query("
        SELECT s.item_id, s.warehouse_id, SUM(s.quantity - s.reserved) AS available
        FROM stocks s
        JOIN warehouses w ON w.id = s.warehouse_id AND w.is_active = 1 AND w.is_transit = 0
        JOIN batches b    ON b.id = s.batch_id
        WHERE s.quantity - s.reserved > 0 AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
        GROUP BY s.item_id, s.warehouse_id
    ")->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $result[(int) $row['item_id']][(int) $row['warehouse_id']] = (float) $row['available'];
    }
    return $result;
}

/**
 * Điều kiện lọc danh sách. $filters = [keyword, status, direction: 'incoming' (gửi tới kho tôi xử lý) | 'outgoing' (kho tôi yêu cầu) | '']
 * Chỉ thấy yêu cầu có kho đi hoặc kho đến thuộc quyền mình.
 */
function buildTransferRequestCondition(array $filters, array $allowedWarehouses): array
{
    [$fromFilter, $fromParams] = warehouseFilter('r.from_warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('r.to_warehouse_id', $allowedWarehouses);
    [$sql, $params] = match ($filters['direction'] ?? '') {
        'incoming' => [$fromFilter, $fromParams],
        'outgoing' => [$toFilter, $toParams],
        default    => ["($fromFilter OR $toFilter)", array_merge($fromParams, $toParams)],
    };
    if (($filters['status'] ?? '') !== '') {
        $sql     .= ' AND r.status = ?';
        $params[] = $filters['status'];
    }
    if (($filters['keyword'] ?? '') !== '') {
        $sql     .= ' AND (r.code LIKE ? OR r.reason LIKE ?)';
        $params[] = "%{$filters['keyword']}%";
        $params[] = "%{$filters['keyword']}%";
    }
    return [$sql, $params];
}

const TRANSFER_REQUEST_SORT_COLUMNS = [
    'created'   => 'r.created_at',
    'code'      => 'r.code',
    'from'      => 'fw.name',
    'to'        => 'tw.name',
    'needed_by' => 'r.needed_by',
    'status'    => 'r.status',
];

function countTransferRequests(PDO $pdo, array $filters, array $allowedWarehouses): int
{
    [$where, $params] = buildTransferRequestCondition($filters, $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM transfer_requests r WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

function getTransferRequests(PDO $pdo, array $filters, array $allowedWarehouses, int $limit, int $offset, array $sort = ['created', 'desc']): array
{
    [$where, $params] = buildTransferRequestCondition($filters, $allowedWarehouses);
    $orderBy = orderByClause(TRANSFER_REQUEST_SORT_COLUMNS, $sort, 'r.id DESC');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);
    $stmt = $pdo->prepare("
        SELECT r.id, r.code, r.status, r.reason, r.needed_by, r.created_at, r.from_warehouse_id, r.to_warehouse_id,
               fw.name AS from_name, tw.name AS to_name, u.full_name AS creator_name,
               v.id AS voucher_id, v.code AS voucher_code, v.status AS voucher_status,
               (SELECT COUNT(*) FROM transfer_request_lines l WHERE l.request_id = r.id) AS line_count
        FROM transfer_requests r
        JOIN warehouses fw ON fw.id = r.from_warehouse_id
        JOIN warehouses tw ON tw.id = r.to_warehouse_id
        JOIN users u       ON u.id = r.created_by
        LEFT JOIN vouchers v ON v.id = r.transfer_voucher_id
        WHERE $where
        ORDER BY $orderBy
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function getTransferRequest(PDO $pdo, int $requestId): ?array
{
    $stmt = $pdo->prepare('
        SELECT r.*, fw.code AS from_code, fw.name AS from_name, tw.code AS to_code, tw.name AS to_name,
               cu.full_name AS creator_name, du.full_name AS decider_name,
               v.code AS voucher_code, v.status AS voucher_status
        FROM transfer_requests r
        JOIN warehouses fw  ON fw.id = r.from_warehouse_id
        JOIN warehouses tw  ON tw.id = r.to_warehouse_id
        JOIN users cu       ON cu.id = r.created_by
        LEFT JOIN users du  ON du.id = r.decided_by
        LEFT JOIN vouchers v ON v.id = r.transfer_voucher_id
        WHERE r.id = ?
    ');
    $stmt->execute([$requestId]);
    return $stmt->fetch() ?: null;
}

/** Dòng yêu cầu kèm số có thể xuất hiện tại ở kho được đề nghị */
function getTransferRequestLines(PDO $pdo, int $requestId, int $fromWarehouseId): array
{
    $stmt = $pdo->prepare("
        SELECT l.id, l.item_id, l.quantity, i.sku, i.name AS item_name, i.unit,
               COALESCE((SELECT SUM(s.quantity - s.reserved) FROM stocks s JOIN batches b ON b.id = s.batch_id
                         WHERE s.warehouse_id = ? AND s.item_id = l.item_id AND s.quantity - s.reserved > 0
                           AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())), 0) AS available
        FROM transfer_request_lines l
        JOIN items i ON i.id = l.item_id
        WHERE l.request_id = ?
        ORDER BY l.id
    ");
    $stmt->execute([$fromWarehouseId, $requestId]);
    return $stmt->fetchAll();
}

/** Yêu cầu điều chuyển đã tạo ra phiếu này (để hiện ở trang chi tiết phiếu) */
function getRequestOfVoucher(PDO $pdo, int $voucherId): ?array
{
    $stmt = $pdo->prepare('SELECT id, code, reason, needed_by FROM transfer_requests WHERE transfer_voucher_id = ?');
    $stmt->execute([$voucherId]);
    return $stmt->fetch() ?: null;
}

/**
 * Lập yêu cầu. $input: to_warehouse_id (kho của mình), from_warehouse_id, needed_by, reason, client_token,
 * lines[] = [item_id, quantity]. Trả về id yêu cầu (gửi lại cùng token -> trả về yêu cầu đã tạo).
 */
function createTransferRequest(PDO $pdo, array $user, array $input): int
{
    $toId     = (int) ($input['to_warehouse_id'] ?? 0);
    $fromId   = (int) ($input['from_warehouse_id'] ?? 0);
    $neededBy = trim((string) ($input['needed_by'] ?? ''));
    $reason   = trim((string) ($input['reason'] ?? ''));
    $token    = (string) ($input['client_token'] ?? '');

    if (!canAccessWarehouse($user, $toId)) {
        throw new VoucherException('Lỗi bảo mật: chỉ lập yêu cầu cho kho mình phụ trách (kho cần hàng).');
    }
    if ($fromId <= 0 || $fromId === $toId) {
        throw new VoucherException('Hãy chọn kho được đề nghị gửi hàng, khác kho cần hàng.');
    }
    if ($reason === '' || mb_strlen($reason) > 255) {
        throw new VoucherException('Phải ghi lý do (khách hàng / đơn hàng cần), tối đa 255 ký tự.');
    }
    if ($neededBy !== '' && (!isValidDate($neededBy) || $neededBy < date('Y-m-d'))) {
        throw new VoucherException('Ngày cần hàng không hợp lệ (không được trước hôm nay).');
    }
    if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
        throw new VoucherException('Biểu mẫu không hợp lệ, vui lòng tải lại trang.');
    }

    $lines = [];
    foreach (is_array($input['lines'] ?? null) ? $input['lines'] : [] as $raw) {
        $itemId = (int) ($raw['item_id'] ?? 0);
        $qtyRaw = trim((string) ($raw['quantity'] ?? ''));
        if ($itemId === 0 && $qtyRaw === '') {
            continue;
        }
        $label = 'Dòng ' . (count($lines) + 1) . ': ';
        if ($itemId <= 0) {
            throw new VoucherException($label . 'chưa chọn mặt hàng.');
        }
        if (!preg_match(QTY_PATTERN, $qtyRaw) || toMilli($qtyRaw) <= 0) {
            throw new VoucherException($label . 'số lượng phải là số dương (tối đa 3 chữ số thập phân).');
        }
        if (isset($lines[$itemId])) {
            throw new VoucherException($label . 'mặt hàng bị lặp, hãy gộp vào 1 dòng.');
        }
        $lines[$itemId] = $qtyRaw;
    }
    if (!$lines) {
        throw new VoucherException('Yêu cầu phải có ít nhất 1 mặt hàng.');
    }

    $stmt = $pdo->prepare('SELECT id FROM transfer_requests WHERE client_token = ?');
    $stmt->execute([$token]);
    if (($existing = $stmt->fetchColumn()) !== false) {
        return (int) $existing;                             // gửi form 2 lần
    }

    return inTransaction($pdo, function () use ($pdo, $user, $toId, $fromId, $neededBy, $reason, $token, $lines) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM warehouses WHERE id IN (?, ?) AND is_active = 1 AND is_transit = 0');
        $stmt->execute([$fromId, $toId]);
        if ((int) $stmt->fetchColumn() !== 2) {
            throw new VoucherException('Kho không tồn tại hoặc đã ngừng hoạt động.');
        }
        $check = $pdo->prepare('SELECT COUNT(*) FROM items WHERE id = ? AND is_active = 1');
        foreach (array_keys($lines) as $itemId) {
            $check->execute([$itemId]);
            if ((int) $check->fetchColumn() !== 1) {
                throw new VoucherException('Có mặt hàng không tồn tại hoặc đã ngừng dùng.');
            }
        }
        $code = nextDocumentCode($pdo, 'transfer_requests', 'YC-' . date('Y') . '-');
        $stmt = $pdo->prepare('
            INSERT INTO transfer_requests (code, from_warehouse_id, to_warehouse_id, needed_by, reason, client_token, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([$code, $fromId, $toId, $neededBy === '' ? null : $neededBy, $reason, $token, $user['id']]);
        $requestId = (int) $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO transfer_request_lines (request_id, item_id, quantity) VALUES (?, ?, ?)');
        foreach ($lines as $itemId => $qty) {
            $stmt->execute([$requestId, $itemId, $qty]);
        }
        return $requestId;
    });
}

function lockPendingRequest(PDO $pdo, int $requestId): array
{
    $stmt = $pdo->prepare('SELECT * FROM transfer_requests WHERE id = ? FOR UPDATE');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch();
    if (!$request) {
        throw new VoucherException('Không tìm thấy yêu cầu điều chuyển.');
    }
    if ($request['status'] !== 'PENDING') {
        throw new VoucherException("Yêu cầu {$request['code']} đã được xử lý trước đó (" . TRANSFER_REQUEST_STATUS[$request['status']][1] . ').');
    }
    return $request;
}

/**
 * Kho có hàng đồng ý. Trong 1 transaction:
 *   chọn lô FEFO còn có thể xuất (khóa dòng tồn) -> lập phiếu điều chuyển -> giữ chỗ -> phiếu "Đã duyệt · chờ gửi"
 *   -> yêu cầu ACCEPTED, gắn phiếu. Không đủ hàng thì báo rõ thiếu bao nhiêu, không thay đổi gì.
 * Trả về id phiếu điều chuyển.
 */
function acceptTransferRequest(PDO $pdo, array $user, int $requestId, string $note): int
{
    return inTransaction($pdo, function () use ($pdo, $user, $requestId, $note) {
        $request = lockPendingRequest($pdo, $requestId);
        $fromId  = (int) $request['from_warehouse_id'];
        assertCanDecide($user, [$fromId]);
        assertNotCounting($pdo, [$fromId]);
        $note = trim($note);
        if (mb_strlen($note) > 255) {
            throw new VoucherException('Ghi chú tối đa 255 ký tự.');
        }

        // Chọn lô: hết hạn trước xuất trước, chỉ lấy phần có thể xuất (tồn - đang giữ), khóa dòng tồn
        $stmt = $pdo->prepare('SELECT l.item_id, l.quantity, i.sku FROM transfer_request_lines l JOIN items i ON i.id = l.item_id WHERE l.request_id = ? ORDER BY l.item_id');
        $stmt->execute([$requestId]);
        $lots = $pdo->prepare('
            SELECT s.batch_id, s.quantity - s.reserved AS free
            FROM stocks s JOIN batches b ON b.id = s.batch_id
            WHERE s.warehouse_id = ? AND s.item_id = ? AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE())
            ORDER BY b.expiry_date IS NULL, b.expiry_date, s.batch_id
            FOR UPDATE
        ');
        $allocation = [];
        $shortages  = [];
        foreach ($stmt->fetchAll() as $line) {
            $need = toMilli($line['quantity']);
            $lots->execute([$fromId, $line['item_id']]);
            foreach ($lots->fetchAll() as $lot) {
                $take = min($need, toMilli($lot['free']));
                if ($take > 0) {
                    $allocation[] = ['item_id' => (int) $line['item_id'], 'batch_id' => (int) $lot['batch_id'], 'quantity' => (string) ($take / 1000)];
                    $need -= $take;
                }
                if ($need === 0) {
                    break;
                }
            }
            if ($need > 0) {
                $shortages[] = "{$line['sku']} thiếu " . formatQty($need / 1000);
            }
        }
        if ($shortages) {
            throw new VoucherException('Kho không đủ hàng có thể xuất để đáp ứng: ' . implode(', ', $shortages) . '. Hãy từ chối kèm lý do, hoặc nhập thêm hàng trước.');
        }

        // Lập phiếu điều chuyển rồi duyệt (giữ chỗ) ngay trong giao dịch này
        $reason = mb_substr("Theo yêu cầu {$request['code']}: {$request['reason']}", 0, 255);
        $stmt = $pdo->prepare("
            INSERT INTO vouchers (code, type, warehouse_id, to_warehouse_id, voucher_date, reason, created_by)
            VALUES (?, 'TRANSFER', ?, ?, CURDATE(), ?, ?)
        ");
        $stmt->execute([nextVoucherCode($pdo, 'TRANSFER', date('Y-m-d')), $fromId, $request['to_warehouse_id'], $reason, $user['id']]);
        $voucherId = (int) $pdo->lastInsertId();
        $insertLine = $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity) VALUES (?, ?, ?, ?)');
        foreach ($allocation as $lot) {
            $insertLine->execute([$voucherId, $lot['item_id'], $lot['batch_id'], $lot['quantity']]);
        }
        $voucher = lockVoucher($pdo, $voucherId);
        reserveVoucherLines($pdo, $voucher, lockVoucherLines($pdo, $voucherId));
        $pdo->prepare("UPDATE vouchers SET status = 'APPROVED', approved_by = ?, approved_at = NOW() WHERE id = ?")->execute([$user['id'], $voucherId]);

        $stmt = $pdo->prepare("UPDATE transfer_requests SET status = 'ACCEPTED', transfer_voucher_id = ?, decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?");
        $stmt->execute([$voucherId, $note === '' ? null : $note, $user['id'], $requestId]);
        return $voucherId;
    });
}

/** Kho có hàng từ chối (quản lý, bắt buộc lý do) */
function declineTransferRequest(PDO $pdo, array $user, int $requestId, string $note): void
{
    inTransaction($pdo, function () use ($pdo, $user, $requestId, $note) {
        $request = lockPendingRequest($pdo, $requestId);
        assertCanDecide($user, [(int) $request['from_warehouse_id']]);
        $stmt = $pdo->prepare("UPDATE transfer_requests SET status = 'DECLINED', decision_note = ?, decided_by = ?, decided_at = NOW() WHERE id = ?");
        $stmt->execute([requireReason($note, 'lý do từ chối'), $user['id'], $requestId]);
    });
}

/** Bên yêu cầu (người phụ trách kho cần hàng) rút lại khi chưa được xử lý */
function cancelTransferRequest(PDO $pdo, array $user, int $requestId): void
{
    inTransaction($pdo, function () use ($pdo, $user, $requestId) {
        $request = lockPendingRequest($pdo, $requestId);
        if (!canAccessWarehouse($user, (int) $request['to_warehouse_id'])) {
            throw new VoucherException('Lỗi bảo mật: chỉ người phụ trách kho cần hàng mới rút lại được yêu cầu.');
        }
        $stmt = $pdo->prepare("UPDATE transfer_requests SET status = 'CANCELLED', decided_by = ?, decided_at = NOW() WHERE id = ?");
        $stmt->execute([$user['id'], $requestId]);
    });
}
