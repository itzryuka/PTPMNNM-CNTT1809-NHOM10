<?php
// Thông báo ở chuông trên thanh điều hướng. Không có bảng riêng: mỗi lần tải trang tính lại từ dữ liệu
// nghiệp vụ (phiếu chờ duyệt, yêu cầu điều chuyển, đợt kiểm kê đang mở, ...) theo kho user được phân quyền.
// "Đã đọc" lưu ở trình duyệt (js/main.js so mốc giờ server lúc mở chuông với 'at' của từng thông báo).

const NOTIFICATION_RECENT_DAYS = 7;     // phiếu / yêu cầu của mình được duyệt / từ chối trong ngần này ngày
const NOTIFICATION_EXPIRY_DAYS = 30;    // lô còn hàng sắp hết hạn trong ngần này ngày
const NOTIFICATION_LIMIT       = 15;

/**
 * Danh sách thông báo cho user, mới nhất trước. Mỗi phần tử:
 *   icon, tone (amber|blue|indigo|red|green|slate), title, text, url (tính từ thư mục gốc), at (unix time)
 * Trả về [danh sách, giờ server hiện tại (unix)].
 */
function getNotifications(PDO $pdo, array $user): array
{
    $now   = (int) $pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();
    $mine  = $user['warehouses'];
    $items = [];
    if (empty($mine)) {
        return [[], $now];
    }
    $in       = implode(',', array_fill(0, count($mine), '?'));
    $userId   = (int) $user['id'];
    $overdueDays = defined('OVERDUE_TRANSIT_DAYS') ? (int) OVERDUE_TRANSIT_DAYS : 3;

    if (isManager($user)) {
        // Phiếu chờ mình duyệt (kho của phiếu thuộc quyền mình, người khác lập)
        $stmt = $pdo->prepare("
            SELECT v.id, v.code, v.type, UNIX_TIMESTAMP(v.created_at) AS at, w.name AS wh, u.full_name AS creator
            FROM vouchers v JOIN warehouses w ON w.id = v.warehouse_id JOIN users u ON u.id = v.created_by
            WHERE v.status = 'PENDING' AND v.warehouse_id IN ($in) AND v.created_by <> ?
            ORDER BY v.created_at DESC LIMIT 8
        ");
        $stmt->execute([...$mine, $userId]);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'icon' => 'fa-file-signature', 'tone' => 'amber',
                'title' => 'Phiếu ' . mb_strtolower(voucherTypeLabel($row['type'])) . " {$row['code']} chờ duyệt",
                'text' => "{$row['creator']} lập · {$row['wh']}",
                'url' => 'views/voucher_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
            ];
        }

        // Yêu cầu điều chuyển đề nghị kho mình gửi hàng, chưa trả lời
        $stmt = $pdo->prepare("
            SELECT r.id, r.code, r.reason, UNIX_TIMESTAMP(r.created_at) AS at, tw.name AS to_name
            FROM transfer_requests r JOIN warehouses tw ON tw.id = r.to_warehouse_id
            WHERE r.status = 'PENDING' AND r.from_warehouse_id IN ($in)
            ORDER BY r.created_at DESC LIMIT 6
        ");
        $stmt->execute($mine);
        foreach ($stmt->fetchAll() as $row) {
            $items[] = [
                'icon' => 'fa-people-carry', 'tone' => 'blue',
                'title' => "Yêu cầu {$row['code']}: {$row['to_name']} cần hàng",
                'text' => $row['reason'],
                'url' => 'views/transfer_request_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
            ];
        }
    }

    // Đợt kiểm kê đang mở ở kho mình
    $stmt = $pdo->prepare("
        SELECT c.id, c.code, UNIX_TIMESTAMP(c.created_at) AS at, w.name AS wh, u.full_name AS creator
        FROM stock_counts c JOIN warehouses w ON w.id = c.warehouse_id JOIN users u ON u.id = c.created_by
        WHERE c.status = 'COUNTING' AND c.warehouse_id IN ($in)
        ORDER BY c.created_at DESC LIMIT 5
    ");
    $stmt->execute($mine);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'fa-clipboard-check', 'tone' => 'indigo',
            'title' => "Đợt kiểm kê {$row['code']} đang mở",
            'text' => "{$row['wh']} · {$row['creator']} mở đợt",
            'url' => 'views/stock_count_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
        ];
    }

    // Điều chuyển đi đường quá hạn chưa nhận đủ (đi hoặc đến kho mình)
    $stmt = $pdo->prepare("
        SELECT v.id, v.code, UNIX_TIMESTAMP(v.shipped_at) AS at, DATEDIFF(CURDATE(), DATE(v.shipped_at)) AS days,
               fw.name AS from_name, tw.name AS to_name
        FROM vouchers v JOIN warehouses fw ON fw.id = v.warehouse_id JOIN warehouses tw ON tw.id = v.to_warehouse_id
        WHERE v.type = 'TRANSFER' AND v.status IN ('IN_TRANSIT', 'DISCREPANCY')
          AND v.shipped_at < NOW() - INTERVAL $overdueDays DAY
          AND (v.warehouse_id IN ($in) OR v.to_warehouse_id IN ($in))
        ORDER BY v.shipped_at LIMIT 5
    ");
    $stmt->execute([...$mine, ...$mine]);
    foreach ($stmt->fetchAll() as $row) {
        $items[] = [
            'icon' => 'fa-truck', 'tone' => 'red',
            'title' => "Điều chuyển {$row['code']} quá hạn nhận",
            'text' => "{$row['from_name']} → {$row['to_name']} · {$row['days']} ngày trên đường",
            'url' => 'views/voucher_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
        ];
    }

    // Phiếu mình lập vừa được người khác duyệt / từ chối
    $stmt = $pdo->prepare("
        SELECT v.id, v.code, v.type, v.status, UNIX_TIMESTAMP(v.approved_at) AS at, u.full_name AS approver
        FROM vouchers v JOIN users u ON u.id = v.approved_by
        WHERE v.created_by = ? AND v.approved_by <> ? AND v.approved_at >= NOW() - INTERVAL " . NOTIFICATION_RECENT_DAYS . " DAY
        ORDER BY v.approved_at DESC LIMIT 6
    ");
    $stmt->execute([$userId, $userId]);
    foreach ($stmt->fetchAll() as $row) {
        $rejected = $row['status'] === 'REJECTED';
        $items[] = [
            'icon' => $rejected ? 'fa-times-circle' : 'fa-check-circle', 'tone' => $rejected ? 'red' : 'green',
            'title' => "Phiếu {$row['code']} " . ($rejected ? 'bị từ chối' : 'đã được duyệt'),
            'text' => 'Bởi ' . $row['approver'] . ' · ' . voucherTypeLabel($row['type']),
            'url' => 'views/voucher_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
        ];
    }

    // Yêu cầu điều chuyển mình lập vừa được trả lời
    $stmt = $pdo->prepare("
        SELECT r.id, r.code, r.status, UNIX_TIMESTAMP(r.decided_at) AS at, u.full_name AS decider, fw.name AS from_name
        FROM transfer_requests r JOIN users u ON u.id = r.decided_by JOIN warehouses fw ON fw.id = r.from_warehouse_id
        WHERE r.created_by = ? AND r.decided_by <> ? AND r.status IN ('ACCEPTED', 'DECLINED')
          AND r.decided_at >= NOW() - INTERVAL " . NOTIFICATION_RECENT_DAYS . " DAY
        ORDER BY r.decided_at DESC LIMIT 5
    ");
    $stmt->execute([$userId, $userId]);
    foreach ($stmt->fetchAll() as $row) {
        $accepted = $row['status'] === 'ACCEPTED';
        $items[] = [
            'icon' => $accepted ? 'fa-check-circle' : 'fa-times-circle', 'tone' => $accepted ? 'green' : 'red',
            'title' => "Yêu cầu {$row['code']} " . ($accepted ? 'được đồng ý' : 'bị từ chối'),
            'text' => "{$row['from_name']} · {$row['decider']}",
            'url' => 'views/transfer_request_detail.php?id=' . (int) $row['id'], 'at' => (int) $row['at'],
        ];
    }

    // Lô còn hàng sắp hết hạn: gộp 1 thông báo / ngày
    $stmt = $pdo->prepare("
        SELECT COUNT(*) AS total, MIN(b.expiry_date) AS first_expiry, MIN(DATEDIFF(b.expiry_date, CURDATE())) AS days_left
        FROM stocks s JOIN batches b ON b.id = s.batch_id
        WHERE s.quantity > 0 AND b.expiry_date <= CURDATE() + INTERVAL " . NOTIFICATION_EXPIRY_DAYS . " DAY
          AND s.warehouse_id IN ($in)
    ");
    $stmt->execute($mine);
    $expiry = $stmt->fetch();
    if ((int) $expiry['total'] > 0) {
        $daysLeft = (int) $expiry['days_left'];
        $items[] = [
            'icon' => 'fa-hourglass-half', 'tone' => $daysLeft < 0 ? 'red' : 'amber',
            'title' => "{$expiry['total']} lô còn hàng sắp hết hạn",
            'text' => $daysLeft < 0 ? 'Có lô đã quá hạn sử dụng, cần xử lý' : "Lô gần nhất còn $daysLeft ngày (" . formatDate($expiry['first_expiry']) . ')',
            'url' => 'index.php', 'at' => (int) $pdo->query('SELECT UNIX_TIMESTAMP(CURDATE())')->fetchColumn(),
        ];
    }

    usort($items, fn ($a, $b) => $b['at'] <=> $a['at']);
    return [array_slice($items, 0, NOTIFICATION_LIMIT), $now];
}

/** "vừa xong", "5 phút trước", "3 giờ trước", "2 ngày trước", quá 1 tuần thì ghi ngày */
function timeAgo(int $at, int $now): string
{
    $diff = max(0, $now - $at);
    if ($diff < 60) {
        return 'vừa xong';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . ' phút trước';
    }
    if ($diff < 86400) {
        return intdiv($diff, 3600) . ' giờ trước';
    }
    if ($diff < 7 * 86400) {
        return intdiv($diff, 86400) . ' ngày trước';
    }
    return date('d/m/Y', $at);
}
