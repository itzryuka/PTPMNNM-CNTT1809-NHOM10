<?php
// CÁC BƯỚC THỰC HIỆN CHỨNG TỪ SAU KHI DUYỆT
//   (b) Phiếu nhập : nhận hàng nhiều đợt (số tốt cộng tồn, số hỏng ghi biên bản NCC), đóng phiếu khi NCC giao thiếu
//   (c) Phiếu xuất : duyệt = giữ chỗ hàng; tồn chỉ bị trừ khi bấm "Đã xuất kho"; hủy giữ chỗ
//   (a) Điều chuyển: gửi hàng (kho đi − / đang đi đường +) -> kho đến nhận (đang đi đường − / kho đến +)
//                    -> nhận thiếu thì xử lý: chờ giao bổ sung hoặc ghi hao hụt bằng phiếu điều chỉnh có lý do
//
// Mỗi bước là MỘT transaction, theo cùng một khuôn:
//   1. khóa phiếu (FOR UPDATE) + kiểm tra đúng bước -> 2 người bấm cùng lúc: người sau chờ rồi thấy trạng thái đã đổi
//   2. kiểm tra quyền kho + kho không đang kiểm kê
//   3. mở 1 ĐỢT GHI SỔ (voucher_postings), khóa các dòng tồn theo thứ tự (kho, lô) cố định, ghi phát sinh 2 đầu
//   4. đổi trạng thái phiếu SAU CÙNG (trigger chỉ cho ghi sổ khi phiếu đang ở đúng bước)
// Cần nạp trước: helpers.php, auth.php; được voucher_functions.php nạp sẵn.

const OVERDUE_TRANSIT_DAYS = 3;                         // điều chuyển đi đường quá số ngày này -> cảnh báo
const QTY_PATTERN          = '/^\d{1,12}(\.\d{1,3})?$/';

/** Id kho ảo "Hàng đang đi đường" */
function transitWarehouseId(PDO $pdo): int
{
    $id = (int) $pdo->query('SELECT id FROM warehouses WHERE is_transit = 1 ORDER BY id LIMIT 1')->fetchColumn();
    if ($id === 0) {
        throw new VoucherException('Chưa có kho "Hàng đang đi đường". Hãy import lại database/quanlykhohang.sql.');
    }
    return $id;
}

function lockVoucher(PDO $pdo, int $voucherId): array
{
    $stmt = $pdo->prepare('SELECT * FROM vouchers WHERE id = ? FOR UPDATE');
    $stmt->execute([$voucherId]);
    $voucher = $stmt->fetch();
    if (!$voucher) {
        throw new VoucherException('Không tìm thấy chứng từ.');
    }
    return $voucher;
}

/** Đúng loại phiếu và đang ở bước cho phép thao tác này */
function assertVoucherStep(array $voucher, array $types, array $statuses, string $action): void
{
    if (!in_array($voucher['type'], $types, true)) {
        throw new VoucherException("Chứng từ {$voucher['code']} không có bước \"$action\".");
    }
    if (!in_array($voucher['status'], $statuses, true)) {
        throw new VoucherException("Không thể $action: chứng từ {$voucher['code']} đang ở trạng thái \""
            . voucherStatusLabel($voucher['status'], $voucher['type']) . '" (có thể thao tác đã được thực hiện trước đó).');
    }
}

function assertWarehouseAccess(array $user, int $warehouseId, string $what): void
{
    if (!canAccessWarehouse($user, $warehouseId)) {
        throw new VoucherException("Lỗi bảo mật: bạn không phụ trách $what của chứng từ này.");
    }
}

/** Các dòng của phiếu, khóa FOR UPDATE, theo lô (thứ tự khóa cố định). Trả về [line_id => dòng] */
function lockVoucherLines(PDO $pdo, int $voucherId): array
{
    $stmt = $pdo->prepare('
        SELECT vl.*, i.sku, i.name AS item_name, b.batch_no, b.expiry_date
        FROM voucher_lines vl
        JOIN items i   ON i.id = vl.item_id
        JOIN batches b ON b.id = vl.batch_id
        WHERE vl.voucher_id = ?
        ORDER BY vl.batch_id, vl.id
        FOR UPDATE
    ');
    $stmt->execute([$voucherId]);
    $lines = [];
    foreach ($stmt->fetchAll() as $line) {
        $lines[(int) $line['id']] = $line;
    }
    if (!$lines) {
        throw new VoucherException('Chứng từ không có dòng hàng nào.');
    }
    return $lines;
}

/** Số lượng nhập trên form: trống -> 0; không âm, tối đa 3 chữ số thập phân */
function parseQuantity($raw, string $label): string
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return '0';
    }
    if (!preg_match(QTY_PATTERN, $raw)) {
        throw new VoucherException("$label \"$raw\" không hợp lệ (số không âm, tối đa 3 chữ số thập phân).");
    }
    return $raw;
}

function lineLabel(array $line): string
{
    return "{$line['sku']} - lô {$line['batch_no']}";
}

/**
 * Mở 1 đợt ghi sổ, mã <mã phiếu>/<thứ tự> (phiếu đã bị khóa nên đếm không trùng).
 * $token: mã sinh khi mở form -> gửi form 2 lần thì lần sau bị chặn, không ghi đúp đợt này.
 */
function openPosting(PDO $pdo, array $voucher, string $kind, array $user, ?string $note, ?string $token): int
{
    if ($token !== null) {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            throw new VoucherException('Biểu mẫu không hợp lệ, vui lòng tải lại trang.');
        }
        $stmt = $pdo->prepare('SELECT code FROM voucher_postings WHERE client_token = ?');
        $stmt->execute([$token]);
        if (($code = $stmt->fetchColumn()) !== false) {
            throw new VoucherException("Đợt $code đã được ghi nhận trước đó (biểu mẫu gửi 2 lần). Không ghi thêm.");
        }
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM voucher_postings WHERE voucher_id = ?');
    $stmt->execute([$voucher['id']]);
    $number = (int) $stmt->fetchColumn() + 1;

    $stmt = $pdo->prepare('INSERT INTO voucher_postings (voucher_id, code, kind, note, client_token, created_by) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$voucher['id'], "{$voucher['code']}/$number", $kind, $note, $token, $user['id']]);
    return (int) $pdo->lastInsertId();
}

function addPostingLine(PDO $pdo, int $postingId, int $lineId, string $quantity, string $damaged = '0'): void
{
    $stmt = $pdo->prepare('INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES (?, ?, ?, ?)');
    $stmt->execute([$postingId, $lineId, $quantity, $damaged]);
}

/**
 * Ghi các thay đổi tồn của 1 đợt: mỗi thay đổi = [dòng phiếu, kho, số lượng có dấu, số trừ khỏi "đang giữ"].
 * Khóa dòng tồn theo thứ tự (kho, lô) cố định -> 2 giao dịch đụng cùng lô không khóa chéo nhau (deadlock).
 */
function applyStockChanges(PDO $pdo, array $voucher, int $postingId, array $changes, array $user): void
{
    usort($changes, fn ($a, $b) => [$a[1], (int) $a[0]['batch_id']] <=> [$b[1], (int) $b[0]['batch_id']]);
    $insertMovement = $pdo->prepare('
        INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?)
    ');
    foreach ($changes as $n => [$line, $warehouseId, $delta, $release]) {
        if ($n === 1) {
            failpoint('posting.after_first_change');    // phép thử 2: lỗi sau khi đã trừ kho đi, trước khi cộng kho kia
        }
        changeStock($pdo, (int) $warehouseId, $line, (string) $delta, (string) $release);
        $insertMovement->execute([$voucher['id'], $postingId, $line['id'], $warehouseId, $line['item_id'], $line['batch_id'], $delta, $user['id']]);
    }
}

/**
 * Cập nhật 1 dòng tồn. Giảm tồn: khóa dòng, kiểm tra hàng CÓ THỂ dùng (tồn - đang giữ cho phiếu khác).
 * $release: hàng của chính phiếu này đang được giữ, nay rời kho -> trừ khỏi "đang giữ".
 */
function changeStock(PDO $pdo, int $warehouseId, array $line, string $delta, string $release = '0'): void
{
    $key = [$warehouseId, $line['item_id'], $line['batch_id']];
    if (toMilli($delta) >= 0) {
        $stmt = $pdo->prepare('
            INSERT INTO stocks (warehouse_id, item_id, batch_id, quantity) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
        ');
        $stmt->execute([...$key, $delta]);
        return;
    }

    $stmt = $pdo->prepare('SELECT id, quantity, reserved FROM stocks WHERE warehouse_id = ? AND item_id = ? AND batch_id = ? FOR UPDATE');
    $stmt->execute($key);
    $row  = $stmt->fetch() ?: ['id' => null, 'quantity' => '0', 'reserved' => '0'];
    $need = -toMilli($delta);
    $free = toMilli($row['quantity']) - toMilli($row['reserved']) + toMilli($release);
    if ($free < $need) {
        throw new VoucherException(toMilli($row['quantity']) >= $need
            ? sprintf('Không đủ tồn có thể dùng: %s còn %s nhưng %s đang được giữ cho phiếu xuất / điều chuyển đã duyệt. Hãy hủy giữ chỗ phiếu đó trước.',
                lineLabel($line), formatQty($row['quantity']), formatQty($row['reserved']))
            : sprintf('Không đủ tồn: %s chỉ còn %s, cần %s. Chứng từ chưa được ghi nhận.',
                lineLabel($line), formatQty($row['quantity']), formatQty(-$delta)));
    }
    $stmt = $pdo->prepare('UPDATE stocks SET quantity = quantity + ?, reserved = reserved - ? WHERE id = ?');
    $stmt->execute([$delta, $release, $row['id']]);
}

/**
 * Giữ chỗ hàng cho phiếu xuất / điều chuyển (bước duyệt). Có thể xuất = tồn - đang giữ.
 * 2 quản lý duyệt cùng lúc 2 phiếu cùng lô: phiếu sau chờ khóa, đọc lại số đang giữ mới -> không đủ thì bị từ chối.
 */
function reserveVoucherLines(PDO $pdo, array $voucher, array $lines): void
{
    $select = $pdo->prepare('SELECT id, quantity, reserved FROM stocks WHERE warehouse_id = ? AND item_id = ? AND batch_id = ? FOR UPDATE');
    $update = $pdo->prepare('UPDATE stocks SET reserved = reserved + ? WHERE id = ?');
    foreach ($lines as $line) {                         // đã sắp theo lô
        if ($line['expiry_date'] !== null && $line['expiry_date'] < date('Y-m-d')) {
            throw new VoucherException(lineLabel($line) . ' đã hết hạn, không được xuất / điều chuyển.');
        }
        $select->execute([$voucher['warehouse_id'], $line['item_id'], $line['batch_id']]);
        $row = $select->fetch() ?: ['id' => null, 'quantity' => '0', 'reserved' => '0'];
        if (toMilli($row['quantity']) - toMilli($row['reserved']) < toMilli($line['quantity'])) {
            throw new VoucherException(sprintf(
                'Không đủ tồn có thể xuất: %s còn %s (tồn %s, đang giữ cho phiếu khác %s), chứng từ cần %s. Chứng từ chưa được duyệt.',
                lineLabel($line), formatQty((float) $row['quantity'] - (float) $row['reserved']), formatQty($row['quantity']),
                formatQty($row['reserved']), formatQty($line['quantity'])
            ));
        }
        $update->execute([$line['quantity'], $row['id']]);
    }
}

/** Trả hàng đang giữ của phiếu về "có thể xuất" (hủy giữ chỗ) */
function releaseVoucherLines(PDO $pdo, array $voucher, array $lines): void
{
    $stmt = $pdo->prepare('UPDATE stocks SET reserved = reserved - ? WHERE warehouse_id = ? AND item_id = ? AND batch_id = ?');
    foreach ($lines as $line) {
        $stmt->execute([$line['quantity'], $voucher['warehouse_id'], $line['item_id'], $line['batch_id']]);
    }
}

const SQL_NOW = "\0NOW()";                             // giá trị đặc biệt: ghi thời điểm hiện tại của CSDL

/** Đổi trạng thái phiếu (trigger kiểm tra bước chuyển hợp lệ) + các cột thông tin tiến độ */
function setVoucherStatus(PDO $pdo, int $voucherId, string $status, array $set = []): void
{
    $columns = array_intersect_key($set, array_flip(['closed_reason', 'shipped_at', 'completed_at']));
    $sql = 'UPDATE vouchers SET status = ?';
    $params = [$status];
    foreach ($columns as $column => $value) {
        $sql .= ", $column = " . ($value === SQL_NOW ? 'NOW()' : '?');
        if ($value !== SQL_NOW) {
            $params[] = $value;
        }
    }
    $params[] = $voucherId;
    $pdo->prepare($sql . ' WHERE id = ?')->execute($params);
}

function requireReason(string $reason, string $label): string
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new VoucherException("Phải ghi $label.");
    }
    if (mb_strlen($reason) > 255) {
        throw new VoucherException(ucfirst($label) . ' tối đa 255 ký tự.');
    }
    return $reason;
}

// =====================================================================
// (b) PHIẾU NHẬP: NHẬN HÀNG NHIỀU ĐỢT
// =====================================================================

/**
 * Ghi nhận 1 đợt hàng về. $entries = [line_id => ['good' => số nhận tốt, 'damaged' => số hỏng]].
 * Số tốt cộng vào tồn; số hỏng chỉ ghi lại (biên bản với nhà cung cấp), vẫn còn chờ NCC giao bù.
 * Trả về id đợt ghi sổ.
 */
function receiveImport(PDO $pdo, array $user, int $voucherId, array $entries, string $note, ?string $token): int
{
    return inTransaction($pdo, function () use ($pdo, $user, $voucherId, $entries, $note, $token) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['IMPORT'], ['APPROVED', 'PARTIAL'], 'nhận hàng');
        assertWarehouseAccess($user, (int) $voucher['warehouse_id'], 'kho nhận hàng');
        assertNotCounting($pdo, [(int) $voucher['warehouse_id']]);
        $lines = lockVoucherLines($pdo, $voucherId);

        $plan = [];
        foreach ($entries as $lineId => $entry) {
            $line = $lines[(int) $lineId] ?? throw new VoucherException('Dòng hàng không thuộc phiếu này.');
            $good = parseQuantity($entry['good'] ?? '', 'Số nhận tốt của ' . lineLabel($line));
            $bad  = parseQuantity($entry['damaged'] ?? '', 'Số hỏng của ' . lineLabel($line));
            if (toMilli($good) === 0 && toMilli($bad) === 0) {
                continue;
            }
            $waiting = (float) $line['quantity'] - (float) $line['received_qty'];
            if (toMilli($good) > toMilli($waiting)) {
                throw new VoucherException(lineLabel($line) . ': chỉ còn chờ ' . formatQty($waiting) . ', không nhận quá số trên phiếu.');
            }
            $plan[] = [$line, $good, $bad];
        }
        if (!$plan) {
            throw new VoucherException('Chưa nhập số lượng nhận của dòng nào.');
        }
        $note = trim($note);
        if (array_filter($plan, fn ($p) => toMilli($p[2]) > 0) && $note === '') {
            throw new VoucherException('Có hàng hỏng thì phải ghi lý do (để làm việc với nhà cung cấp).');
        }
        if (mb_strlen($note) > 255) {
            throw new VoucherException('Ghi chú tối đa 255 ký tự.');
        }

        $postingId = openPosting($pdo, $voucher, 'RECEIVE', $user, $note === '' ? null : $note, $token);
        $progress  = $pdo->prepare('UPDATE voucher_lines SET received_qty = received_qty + ?, damaged_qty = damaged_qty + ? WHERE id = ?');
        $changes   = [];
        foreach ($plan as [$line, $good, $bad]) {
            addPostingLine($pdo, $postingId, (int) $line['id'], $good, $bad);
            $progress->execute([$good, $bad, $line['id']]);
            if (toMilli($good) > 0) {
                $changes[] = [$line, (int) $voucher['warehouse_id'], $good, '0'];
            }
        }
        applyStockChanges($pdo, $voucher, $postingId, $changes, $user);

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM voucher_lines WHERE voucher_id = ? AND received_qty < quantity');
        $stmt->execute([$voucherId]);
        $done = (int) $stmt->fetchColumn() === 0;
        setVoucherStatus($pdo, $voucherId, $done ? 'COMPLETED' : 'PARTIAL', $done ? ['completed_at' => SQL_NOW] : []);
        return $postingId;
    });
}

/** Đóng phiếu nhập khi nhà cung cấp không giao phần còn lại (quản lý, bắt buộc lý do). Tồn giữ nguyên = số đã nhận. */
function closeImport(PDO $pdo, array $user, int $voucherId, string $reason): void
{
    inTransaction($pdo, function () use ($pdo, $user, $voucherId, $reason) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['IMPORT'], ['APPROVED', 'PARTIAL'], 'đóng phiếu');
        assertCanDecide($user, [(int) $voucher['warehouse_id']]);
        setVoucherStatus($pdo, $voucherId, 'CLOSED', ['closed_reason' => requireReason($reason, 'lý do đóng phiếu'), 'completed_at' => SQL_NOW]);
    });
}

// =====================================================================
// (c) PHIẾU XUẤT: GIỮ CHỖ -> ĐÃ XUẤT KHO
// =====================================================================

/** Hàng lên xe: trừ tồn và trừ "đang giữ" cùng lúc, ghi sổ. Phiếu kết thúc. */
function shipExport(PDO $pdo, array $user, int $voucherId, ?string $token): int
{
    return inTransaction($pdo, function () use ($pdo, $user, $voucherId, $token) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['EXPORT'], ['APPROVED'], 'xuất kho');
        assertWarehouseAccess($user, (int) $voucher['warehouse_id'], 'kho xuất');
        assertNotCounting($pdo, [(int) $voucher['warehouse_id']]);
        $lines = lockVoucherLines($pdo, $voucherId);

        $postingId = openPosting($pdo, $voucher, 'SHIP', $user, null, $token);
        $changes   = [];
        foreach ($lines as $line) {
            addPostingLine($pdo, $postingId, (int) $line['id'], $line['quantity']);
            $changes[] = [$line, (int) $voucher['warehouse_id'], '-' . $line['quantity'], $line['quantity']];
        }
        applyStockChanges($pdo, $voucher, $postingId, $changes, $user);
        $pdo->prepare('UPDATE voucher_lines SET shipped_qty = quantity WHERE voucher_id = ?')->execute([$voucherId]);
        setVoucherStatus($pdo, $voucherId, 'COMPLETED', ['shipped_at' => SQL_NOW, 'completed_at' => SQL_NOW]);
        return $postingId;
    });
}

/** Hủy giữ chỗ phiếu xuất / điều chuyển đã duyệt nhưng hàng chưa rời kho (quản lý, bắt buộc lý do) */
function cancelReservation(PDO $pdo, array $user, int $voucherId, string $reason): void
{
    inTransaction($pdo, function () use ($pdo, $user, $voucherId, $reason) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['EXPORT', 'TRANSFER'], ['APPROVED'], 'hủy giữ chỗ');
        assertCanDecide($user, [(int) $voucher['warehouse_id']]);
        $reason = requireReason($reason, 'lý do hủy');
        releaseVoucherLines($pdo, $voucher, lockVoucherLines($pdo, $voucherId));
        setVoucherStatus($pdo, $voucherId, 'CANCELLED', ['closed_reason' => $reason, 'completed_at' => SQL_NOW]);
    });
}

// =====================================================================
// (a) ĐIỀU CHUYỂN QUA KHO TRUNG CHUYỂN
// =====================================================================

/** Hàng rời kho đi: (−) kho đi (trừ cả đang giữ), (+) Hàng đang đi đường — cùng 1 transaction */
function sendTransfer(PDO $pdo, array $user, int $voucherId, ?string $token): int
{
    return inTransaction($pdo, function () use ($pdo, $user, $voucherId, $token) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['TRANSFER'], ['APPROVED'], 'gửi hàng');
        assertWarehouseAccess($user, (int) $voucher['warehouse_id'], 'kho đi');
        assertNotCounting($pdo, [(int) $voucher['warehouse_id']]);
        $lines   = lockVoucherLines($pdo, $voucherId);
        $transit = transitWarehouseId($pdo);

        $postingId = openPosting($pdo, $voucher, 'SEND', $user, null, $token);
        $changes   = [];
        foreach ($lines as $line) {
            addPostingLine($pdo, $postingId, (int) $line['id'], $line['quantity']);
            $changes[] = [$line, (int) $voucher['warehouse_id'], '-' . $line['quantity'], $line['quantity']];
            $changes[] = [$line, $transit, $line['quantity'], '0'];
        }
        applyStockChanges($pdo, $voucher, $postingId, $changes, $user);
        $pdo->prepare('UPDATE voucher_lines SET shipped_qty = quantity WHERE voucher_id = ?')->execute([$voucherId]);
        setVoucherStatus($pdo, $voucherId, 'IN_TRANSIT', ['shipped_at' => SQL_NOW]);
        return $postingId;
    });
}

/**
 * Kho đến nhận hàng (có thể nhiều lần). $entries = [line_id => số nhận được].
 * (−) Hàng đang đi đường, (+) kho đến. Nhận đủ -> hoàn tất; còn thiếu -> "Nhận thiếu", chờ quản lý xử lý.
 * Trả về id đợt ghi sổ (null nếu không nhận được gì: chuyển thẳng sang "Nhận thiếu").
 */
function receiveTransfer(PDO $pdo, array $user, int $voucherId, array $entries, ?string $token): ?int
{
    return inTransaction($pdo, function () use ($pdo, $user, $voucherId, $entries, $token) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['TRANSFER'], ['IN_TRANSIT', 'DISCREPANCY'], 'nhận hàng');
        assertWarehouseAccess($user, (int) $voucher['to_warehouse_id'], 'kho đến');
        assertNotCounting($pdo, [(int) $voucher['to_warehouse_id']]);
        $lines   = lockVoucherLines($pdo, $voucherId);
        $transit = transitWarehouseId($pdo);

        $plan = [];
        foreach ($entries as $lineId => $raw) {
            $line = $lines[(int) $lineId] ?? throw new VoucherException('Dòng hàng không thuộc phiếu này.');
            $qty  = parseQuantity($raw, 'Số nhận của ' . lineLabel($line));
            $onRoad = (float) $line['shipped_qty'] - (float) $line['received_qty'] - (float) $line['lost_qty'];
            if (toMilli($qty) > toMilli($onRoad)) {
                throw new VoucherException(lineLabel($line) . ': chỉ có ' . formatQty($onRoad) . ' đang trên đường, không nhận quá số đã gửi.');
            }
            if (toMilli($qty) > 0) {
                $plan[] = [$line, $qty];
            }
        }

        $postingId = null;
        if ($plan) {
            $postingId = openPosting($pdo, $voucher, 'ARRIVE', $user, null, $token);
            $progress  = $pdo->prepare('UPDATE voucher_lines SET received_qty = received_qty + ? WHERE id = ?');
            $changes   = [];
            foreach ($plan as [$line, $qty]) {
                addPostingLine($pdo, $postingId, (int) $line['id'], $qty);
                $progress->execute([$qty, $line['id']]);
                $changes[] = [$line, $transit, '-' . $qty, '0'];
                $changes[] = [$line, (int) $voucher['to_warehouse_id'], $qty, '0'];
            }
            applyStockChanges($pdo, $voucher, $postingId, $changes, $user);
        } elseif ($voucher['status'] === 'DISCREPANCY') {
            throw new VoucherException('Chưa nhập số lượng nhận được.');
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM voucher_lines WHERE voucher_id = ? AND shipped_qty - received_qty - lost_qty > 0');
        $stmt->execute([$voucherId]);
        $done = (int) $stmt->fetchColumn() === 0;
        setVoucherStatus($pdo, $voucherId, $done ? 'COMPLETED' : 'DISCREPANCY', $done ? ['completed_at' => SQL_NOW] : []);
        return $postingId;
    });
}

const TRANSFER_LOSS_REASONS = ['lost' => 'Thất lạc trên đường', 'damaged' => 'Hư hỏng khi vận chuyển'];

/**
 * Quản lý xử lý phiếu nhận thiếu:
 *   'wait'            -> quay lại "Đang đi đường", chờ giao bổ sung (hàng vẫn nằm ở kho trung chuyển)
 *   'lost' / 'damaged'-> lập phiếu ĐIỀU CHỈNH (−) kho trung chuyển, có lý do, tham chiếu phiếu điều chuyển; phiếu hoàn tất
 * Trả về id phiếu điều chỉnh (null khi chờ giao bổ sung).
 */
function resolveTransfer(PDO $pdo, array $user, int $voucherId, string $action, string $note): ?int
{
    return inTransaction($pdo, function () use ($pdo, $user, $voucherId, $action, $note) {
        $voucher = lockVoucher($pdo, $voucherId);
        assertVoucherStep($voucher, ['TRANSFER'], ['DISCREPANCY'], 'xử lý chênh lệch');
        if (!isManager($user)) {
            throw new VoucherException('Chỉ quản lý kho mới được xử lý chênh lệch điều chuyển.');
        }
        if (!canAccessWarehouse($user, (int) $voucher['warehouse_id']) && !canAccessWarehouse($user, (int) $voucher['to_warehouse_id'])) {
            throw new VoucherException('Lỗi bảo mật: bạn không phụ trách kho nào của chứng từ này.');
        }

        if ($action === 'wait') {
            setVoucherStatus($pdo, $voucherId, 'IN_TRANSIT');
            return null;
        }
        $label = TRANSFER_LOSS_REASONS[$action] ?? throw new VoucherException('Cách xử lý không hợp lệ.');
        $note  = requireReason($note, 'diễn giải hao hụt');

        $lines   = lockVoucherLines($pdo, $voucherId);
        $missing = array_filter($lines, fn ($l) => toMilli($l['shipped_qty']) - toMilli($l['received_qty']) - toMilli($l['lost_qty']) > 0);
        $transit = transitWarehouseId($pdo);

        $stmt = $pdo->prepare("
            INSERT INTO vouchers (code, type, warehouse_id, reference_voucher_id, voucher_date, reason, created_by)
            VALUES (?, 'ADJUSTMENT', ?, ?, CURDATE(), ?, ?)
        ");
        $stmt->execute([nextVoucherCode($pdo, 'ADJUSTMENT', date('Y-m-d')), $transit, $voucherId,
            mb_substr("Hao hụt điều chuyển {$voucher['code']} - $label: $note", 0, 255), $user['id']]);
        $adjustmentId = (int) $pdo->lastInsertId();

        $insertLine = $pdo->prepare('INSERT INTO voucher_lines (voucher_id, item_id, batch_id, quantity, note) VALUES (?, ?, ?, ?, ?)');
        $lost       = $pdo->prepare('UPDATE voucher_lines SET lost_qty = lost_qty + ? WHERE id = ?');
        foreach ($missing as $line) {
            $qty = (float) $line['shipped_qty'] - (float) $line['received_qty'] - (float) $line['lost_qty'];
            $insertLine->execute([$adjustmentId, $line['item_id'], $line['batch_id'], -$qty, $label]);
            $lost->execute([$qty, $line['id']]);
        }
        postVoucher($pdo, $user, $adjustmentId, null, false);   // kho trung chuyển không phân quyền cho ai -> bỏ kiểm tra quyền kho
        setVoucherStatus($pdo, $voucherId, 'COMPLETED', ['completed_at' => SQL_NOW]);
        return $adjustmentId;
    });
}

// =====================================================================
// TRA CỨU CHO TRANG CHI TIẾT
// =====================================================================

/** Các đợt ghi sổ của phiếu, kèm dòng: [đợt + 'lines' => [...]] */
function getVoucherPostings(PDO $pdo, int $voucherId): array
{
    $stmt = $pdo->prepare('
        SELECT p.id, p.code, p.kind, p.note, p.created_at, u.full_name AS created_by_name
        FROM voucher_postings p JOIN users u ON u.id = p.created_by
        WHERE p.voucher_id = ? ORDER BY p.id
    ');
    $stmt->execute([$voucherId]);
    $postings = [];
    foreach ($stmt->fetchAll() as $posting) {
        $postings[$posting['id']] = $posting + ['lines' => []];
    }
    $stmt = $pdo->prepare('
        SELECT pl.posting_id, pl.quantity, pl.damaged_qty, i.sku, i.name AS item_name, i.unit, b.batch_no
        FROM voucher_posting_lines pl
        JOIN voucher_lines vl ON vl.id = pl.voucher_line_id
        JOIN items i   ON i.id = vl.item_id
        JOIN batches b ON b.id = vl.batch_id
        WHERE vl.voucher_id = ?
        ORDER BY pl.posting_id, vl.id
    ');
    $stmt->execute([$voucherId]);
    foreach ($stmt->fetchAll() as $line) {
        $postings[$line['posting_id']]['lines'][] = $line;
    }
    return array_values($postings);
}

/** Phiếu điều chỉnh lập để sửa / ghi hao hụt cho phiếu này */
function getReferencingAdjustments(PDO $pdo, int $voucherId): array
{
    $stmt = $pdo->prepare('SELECT id, code, reason, status FROM vouchers WHERE reference_voucher_id = ? ORDER BY id');
    $stmt->execute([$voucherId]);
    return $stmt->fetchAll();
}

function postingKindLabel(string $kind): string
{
    return ['RECEIVE' => 'Nhận hàng', 'SHIP' => 'Xuất kho', 'SEND' => 'Gửi hàng', 'ARRIVE' => 'Kho đến nhận', 'ADJUST' => 'Điều chỉnh'][$kind] ?? $kind;
}
