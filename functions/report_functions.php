<?php
// TRA CỨU & BÁO CÁO. Mọi số liệu lọc theo kho user được phép ($allowedWarehouses, kiểm tra ở server).
//   1. Tồn kho hiện tại   : tồn thực / đang giữ / có thể xuất theo kho × lô + hàng đang đi đường
//   2. Nhập – xuất – tồn  : tồn đầu kỳ, nhập, xuất, tồn cuối theo mặt hàng, tính thẳng từ sổ phát sinh
//      Thẻ kho (chi tiết) : từng phát sinh kèm số dư sau phát sinh (v_stock_card)
//   3. Báo cáo kiểm kê    : các đợt đã chốt, dòng lệch và lý do
//   4. Đối chiếu sổ       : 4 phép kiểm tra tính toàn vẹn (tồn = sổ, đang giữ, đang đi đường, tiến độ phiếu)
// Cần nạp trước: helpers.php

// =====================================================================
// 1. TỒN KHO HIỆN TẠI
// =====================================================================

const STOCK_EXPIRY_FILTERS = [
    ''        => 'Mọi hạn dùng',
    'soon'    => 'Sắp hết hạn (≤ 30 ngày)',
    'expired' => 'Đã hết hạn',
    'ok'      => 'Còn hạn trên 30 ngày',
];
const STOCK_REPORT_SORT = [
    'warehouse' => 'w.name',
    'sku'       => 'i.sku',
    'name'      => 'i.name',
    'batch'     => 'b.batch_no',
    'expiry'    => 'b.expiry_date',
    'quantity'  => 's.quantity',
    'reserved'  => 's.reserved',
    'available' => 's.quantity - s.reserved',
];

/** $filters = [warehouse_id, keyword, expiry, reserved_only] */
function buildStockReportCondition(array $filters, array $allowedWarehouses): array
{
    [$sql, $params] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $sql = "s.quantity > 0 AND $sql";
    if (($filters['warehouse_id'] ?? 0) > 0) {
        $sql     .= ' AND s.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if (($filters['keyword'] ?? '') !== '') {
        $sql .= ' AND (i.sku LIKE ? OR i.name LIKE ? OR b.batch_no LIKE ?)';
        array_push($params, "%{$filters['keyword']}%", "%{$filters['keyword']}%", "%{$filters['keyword']}%");
    }
    $sql .= match ($filters['expiry'] ?? '') {
        'soon'    => ' AND b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY',
        'expired' => ' AND b.expiry_date < CURDATE()',
        'ok'      => ' AND (b.expiry_date IS NULL OR b.expiry_date > CURDATE() + INTERVAL 30 DAY)',
        default   => '',
    };
    if (!empty($filters['reserved_only'])) {
        $sql .= ' AND s.reserved > 0';
    }
    return [$sql, $params];
}

const STOCK_REPORT_FROM = '
    FROM stocks s
    JOIN warehouses w ON w.id = s.warehouse_id
    JOIN items i      ON i.id = s.item_id
    JOIN batches b    ON b.id = s.batch_id
';

/** Tổng hợp: số dòng (kho × lô), số mặt hàng, số lô đang giữ chỗ, số lô hết hạn / sắp hết hạn */
function getStockReportSummary(PDO $pdo, array $filters, array $allowedWarehouses): array
{
    [$where, $params] = buildStockReportCondition($filters, $allowedWarehouses);
    $stmt = $pdo->prepare('
        SELECT COUNT(*) AS line_count, COUNT(DISTINCT s.item_id) AS items,
               COALESCE(SUM(s.reserved > 0), 0) AS reserved_lines,
               COALESCE(SUM(b.expiry_date < CURDATE()), 0) AS expired_lines,
               COALESCE(SUM(b.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY), 0) AS soon_lines
    ' . STOCK_REPORT_FROM . " WHERE $where");
    $stmt->execute($params);
    return array_map('intval', $stmt->fetch());
}

function getStockReport(PDO $pdo, array $filters, array $allowedWarehouses, int $limit, int $offset, array $sort): array
{
    [$where, $params] = buildStockReportCondition($filters, $allowedWarehouses);
    $orderBy = orderByClause(STOCK_REPORT_SORT, $sort, 'w.code, i.sku, b.expiry_date');
    $limit   = max(1, $limit);
    $offset  = max(0, $offset);
    $stmt = $pdo->prepare('
        SELECT w.code AS warehouse_code, w.name AS warehouse_name, i.id AS item_id, i.sku, i.name AS item_name, i.unit,
               b.batch_no, b.expiry_date, DATEDIFF(b.expiry_date, CURDATE()) AS days_left,
               s.warehouse_id, s.quantity, s.reserved, s.quantity - s.reserved AS available, s.updated_at
    ' . STOCK_REPORT_FROM . " WHERE $where ORDER BY $orderBy LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Hàng đang trên đường của các phiếu điều chuyển có kho đi hoặc kho đến thuộc quyền user */
function getInTransitLines(PDO $pdo, array $allowedWarehouses): array
{
    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT v.id AS voucher_id, v.code, v.status, v.shipped_at, DATEDIFF(CURDATE(), DATE(v.shipped_at)) AS days_on_road,
               fw.name AS from_name, tw.name AS to_name, i.sku, i.name AS item_name, i.unit, b.batch_no,
               vl.shipped_qty - vl.received_qty - vl.lost_qty AS on_road
        FROM vouchers v
        JOIN voucher_lines vl ON vl.voucher_id = v.id
        JOIN warehouses fw ON fw.id = v.warehouse_id
        JOIN warehouses tw ON tw.id = v.to_warehouse_id
        JOIN items i       ON i.id = vl.item_id
        JOIN batches b     ON b.id = vl.batch_id
        WHERE v.type = 'TRANSFER' AND v.status IN ('IN_TRANSIT', 'DISCREPANCY')
          AND vl.shipped_qty - vl.received_qty - vl.lost_qty > 0
          AND ($fromFilter OR $toFilter)
        ORDER BY v.shipped_at, v.id, vl.id
    ");
    $stmt->execute(array_merge($fromParams, $toParams));
    return $stmt->fetchAll();
}

// =====================================================================
// 2. NHẬP – XUẤT – TỒN VÀ THẺ KHO
// =====================================================================

/** Khoảng ngày trên URL (?from=&to=), mặc định đầu tháng -> hôm nay; to < from thì đổi chỗ */
function readDateRange(): array
{
    $from = (string) ($_GET['from'] ?? '');
    $to   = (string) ($_GET['to'] ?? '');
    $from = isValidReportDate($from) ? $from : date('Y-m-01');
    $to   = isValidReportDate($to) ? $to : date('Y-m-d');
    return $from <= $to ? [$from, $to] : [$to, $from];
}

function isValidReportDate(string $value): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

const MOVEMENT_SUMMARY_SORT = [
    'sku'     => 'i.sku',
    'name'    => 'i.name',
    'opening' => 'opening',
    'in'      => 'qty_in',
    'out'     => 'qty_out',
    'closing' => 'closing',
];

/**
 * Nhập – xuất – tồn theo mặt hàng trong kỳ [from, to] (cả 2 ngày), tại 1 kho hoặc mọi kho được xem.
 * Tồn đầu = tổng phát sinh trước kỳ; tồn cuối = tồn đầu + nhập - xuất. Điều chuyển tính là xuất ở kho đi, nhập ở kho đến.
 */
function getMovementSummary(PDO $pdo, array $filters, array $allowedWarehouses, array $sort): array
{
    [$whFilter, $whParams] = warehouseFilter('sm.warehouse_id', $allowedWarehouses);
    $params = [$filters['from'], $filters['from'], $filters['to'], $filters['from'], $filters['to'], $filters['to']];
    $sql = "
        SELECT i.id AS item_id, i.sku, i.name AS item_name, i.unit,
               SUM(CASE WHEN sm.moved_at < ? THEN sm.quantity ELSE 0 END) AS opening,
               SUM(CASE WHEN sm.moved_at >= ? AND sm.moved_at < ? + INTERVAL 1 DAY AND sm.quantity > 0 THEN sm.quantity ELSE 0 END) AS qty_in,
               SUM(CASE WHEN sm.moved_at >= ? AND sm.moved_at < ? + INTERVAL 1 DAY AND sm.quantity < 0 THEN -sm.quantity ELSE 0 END) AS qty_out,
               SUM(sm.quantity) AS closing
        FROM stock_movements sm
        JOIN items i ON i.id = sm.item_id
        WHERE sm.moved_at < ? + INTERVAL 1 DAY AND $whFilter
    ";
    $params = array_merge($params, $whParams);
    if (($filters['warehouse_id'] ?? 0) > 0) {
        $sql .= ' AND sm.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if (($filters['keyword'] ?? '') !== '') {
        $sql .= ' AND (i.sku LIKE ? OR i.name LIKE ?)';
        array_push($params, "%{$filters['keyword']}%", "%{$filters['keyword']}%");
    }
    $sql .= ' GROUP BY i.id, i.sku, i.name, i.unit HAVING opening <> 0 OR qty_in <> 0 OR qty_out <> 0 OR closing <> 0';
    $sql .= ' ORDER BY ' . orderByClause(MOVEMENT_SUMMARY_SORT, $sort, 'i.sku');
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Điều kiện cho thẻ kho / sổ phát sinh chi tiết. $filters = [warehouse_id, keyword, type, from, to] */
function buildLedgerCondition(array $filters, array $allowedWarehouses): array
{
    [$sql, $params] = warehouseFilter('c.warehouse_id', $allowedWarehouses);
    $sql .= ' AND c.moved_at >= ? AND c.moved_at < ? + INTERVAL 1 DAY';
    array_push($params, $filters['from'], $filters['to']);
    if (($filters['warehouse_id'] ?? 0) > 0) {
        $sql     .= ' AND c.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    if (($filters['type'] ?? '') !== '') {
        $sql     .= ' AND c.voucher_type = ?';
        $params[] = $filters['type'];
    }
    if (($filters['keyword'] ?? '') !== '') {
        $sql .= ' AND (c.sku LIKE ? OR c.item_name LIKE ? OR c.batch_no LIKE ? OR c.voucher_code LIKE ?)';
        array_push($params, "%{$filters['keyword']}%", "%{$filters['keyword']}%", "%{$filters['keyword']}%", "%{$filters['keyword']}%");
    }
    return [$sql, $params];
}

function countLedgerEntries(PDO $pdo, array $filters, array $allowedWarehouses): int
{
    [$where, $params] = buildLedgerCondition($filters, $allowedWarehouses);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM v_stock_card c WHERE $where");
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
}

/** Từng phát sinh (mới nhất trước) kèm số dư của kho × lô ngay sau phát sinh đó */
function getLedgerEntries(PDO $pdo, array $filters, array $allowedWarehouses, int $limit, int $offset): array
{
    [$where, $params] = buildLedgerCondition($filters, $allowedWarehouses);
    $limit  = max(1, $limit);
    $offset = max(0, $offset);
    $stmt = $pdo->prepare("
        SELECT c.*, u.full_name AS created_by_name
        FROM v_stock_card c
        JOIN users u ON u.id = c.created_by
        WHERE $where
        ORDER BY c.moved_at DESC, c.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// =====================================================================
// 3. BÁO CÁO KIỂM KÊ
// =====================================================================

/** Các đợt kiểm kê ĐÃ CHỐT trong kỳ (theo ngày duyệt), kèm số dòng, số dòng lệch, thiếu / thừa */
function getStockCountReport(PDO $pdo, array $filters, array $allowedWarehouses): array
{
    [$whFilter, $params] = warehouseFilter('sc.warehouse_id', $allowedWarehouses);
    $sql = "
        SELECT sc.id, sc.code, sc.note, sc.created_at, sc.approved_at, w.name AS warehouse_name,
               v.id AS adjustment_id, v.code AS adjustment_code, au.full_name AS approver_name,
               COUNT(l.id) AS line_count,
               COALESCE(SUM(l.difference <> 0), 0) AS diff_count,
               COALESCE(SUM(l.difference < 0), 0) AS short_count,
               COALESCE(SUM(l.difference > 0), 0) AS surplus_count
        FROM stock_counts sc
        JOIN warehouses w ON w.id = sc.warehouse_id
        LEFT JOIN users au ON au.id = sc.approved_by
        LEFT JOIN vouchers v ON v.id = sc.adjustment_voucher_id
        LEFT JOIN stock_count_lines l ON l.count_id = sc.id
        WHERE sc.status = 'APPROVED' AND $whFilter AND sc.approved_at >= ? AND sc.approved_at < ? + INTERVAL 1 DAY
    ";
    array_push($params, $filters['from'], $filters['to']);
    if (($filters['warehouse_id'] ?? 0) > 0) {
        $sql     .= ' AND sc.warehouse_id = ?';
        $params[] = $filters['warehouse_id'];
    }
    $sql .= ' GROUP BY sc.id, sc.code, sc.note, sc.created_at, sc.approved_at, w.name, v.id, v.code, au.full_name ORDER BY sc.approved_at DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Các dòng lệch của những đợt trên (dòng nào cũng phải có lý do khi chốt) */
function getStockCountDifferences(PDO $pdo, array $countIds): array
{
    if (!$countIds) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT sc.code AS count_code, sc.id AS count_id, w.name AS warehouse_name, i.sku, i.name AS item_name, i.unit,
               b.batch_no, l.system_qty, l.counted_qty, l.difference, l.reason, u.full_name AS counted_by_name
        FROM stock_count_lines l
        JOIN stock_counts sc ON sc.id = l.count_id
        JOIN warehouses w    ON w.id = sc.warehouse_id
        JOIN items i         ON i.id = l.item_id
        JOIN batches b       ON b.id = l.batch_id
        LEFT JOIN users u    ON u.id = l.counted_by
        WHERE l.difference <> 0 AND l.count_id IN (' . implode(',', array_fill(0, count($countIds), '?')) . ')
        ORDER BY sc.approved_at DESC, l.difference
    ');
    $stmt->execute(array_map('intval', $countIds));
    return $stmt->fetchAll();
}

// =====================================================================
// 4. ĐỐI CHIẾU SỔ
// =====================================================================

/**
 * 4 phép kiểm tra, mỗi phép trả về ['total' => số dòng được kiểm, 'bad' => [các dòng lệch]]:
 *   stock       : tồn (stocks) = tổng sổ phát sinh theo kho × lô
 *   reservation : hàng đang giữ = tổng các phiếu xuất / điều chuyển đã duyệt chưa rời kho
 *   transit     : tồn kho trung chuyển = tổng (đã gửi - đã nhận - hao hụt) của các phiếu điều chuyển
 *   progress    : số nhận / xuất / gửi ghi trên dòng phiếu = tổng phát sinh của các đợt ghi sổ tương ứng
 */
function getReconciliationReport(PDO $pdo, array $allowedWarehouses): array
{
    $names = '
        JOIN warehouses w ON w.id = r.warehouse_id
        JOIN items i      ON i.id = r.item_id
        JOIN batches b    ON b.id = r.batch_id
    ';
    $report = [];

    [$whFilter, $whParams] = warehouseFilter('r.warehouse_id', $allowedWarehouses);
    foreach (['stock' => ['v_stock_reconciliation', 'r.stock_qty AS left_qty, r.ledger_qty AS right_qty'],
              'reservation' => ['v_reservation_check', 'r.reserved_qty AS left_qty, r.expected_qty AS right_qty']] as $key => [$view, $columns]) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM $view r WHERE $whFilter");
        $stmt->execute($whParams);
        $total = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT w.name AS warehouse_name, i.sku, i.name AS item_name, b.batch_no, $columns FROM $view r $names WHERE r.is_matched = 0 AND $whFilter");
        $stmt->execute($whParams);
        $report[$key] = ['total' => $total, 'bad' => $stmt->fetchAll()];
    }

    // Kho trung chuyển không phân quyền cho ai: kiểm tra trên toàn hệ thống (chỉ là con số)
    $report['transit'] = [
        'total' => (int) $pdo->query('SELECT COUNT(*) FROM v_transit_check')->fetchColumn(),
        'bad'   => $pdo->query('
            SELECT i.sku, i.name AS item_name, b.batch_no, r.transit_stock AS left_qty, r.expected_qty AS right_qty
            FROM v_transit_check r JOIN items i ON i.id = r.item_id JOIN batches b ON b.id = r.batch_id
            WHERE r.is_matched = 0
        ')->fetchAll(),
    ];

    [$fromFilter, $fromParams] = warehouseFilter('v.warehouse_id', $allowedWarehouses);
    [$toFilter, $toParams]     = warehouseFilter('v.to_warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT v.code, v.type, i.sku, b.batch_no, vl.received_qty, vl.shipped_qty,
               COALESCE(SUM(CASE WHEN p.kind = 'RECEIVE' THEN sm.quantity END), 0) AS received_posted,
               COALESCE(SUM(CASE WHEN p.kind IN ('SHIP', 'SEND') AND sm.quantity < 0 THEN -sm.quantity END), 0) AS shipped_posted,
               COALESCE(SUM(CASE WHEN p.kind = 'ARRIVE' AND sm.quantity > 0 THEN sm.quantity END), 0) AS arrived_posted
        FROM voucher_lines vl
        JOIN vouchers v  ON v.id = vl.voucher_id
        JOIN items i     ON i.id = vl.item_id
        JOIN batches b   ON b.id = vl.batch_id
        LEFT JOIN stock_movements sm ON sm.voucher_line_id = vl.id
        LEFT JOIN voucher_postings p ON p.id = sm.posting_id
        WHERE v.type IN ('IMPORT', 'EXPORT', 'TRANSFER') AND ($fromFilter OR $toFilter)
        GROUP BY vl.id, v.code, v.type, i.sku, b.batch_no, vl.received_qty, vl.shipped_qty
    ");
    $stmt->execute(array_merge($fromParams, $toParams));
    $rows = $stmt->fetchAll();
    $bad = array_values(array_filter($rows, fn ($r) =>
        ($r['type'] === 'IMPORT' && toMilli($r['received_qty']) !== toMilli($r['received_posted']))
        || ($r['type'] !== 'IMPORT' && toMilli($r['shipped_qty']) !== toMilli($r['shipped_posted']))
        || ($r['type'] === 'TRANSFER' && toMilli($r['received_qty']) !== toMilli($r['arrived_posted']))
    ));
    $report['progress'] = ['total' => count($rows), 'bad' => $bad];
    return $report;
}
