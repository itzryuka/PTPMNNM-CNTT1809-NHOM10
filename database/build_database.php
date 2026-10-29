<?php
/**
 * Sinh file quanlykhohang.sql = schema.sql + dữ liệu mẫu.
 *
 *   Chạy:  php database/build_database.php
 *
 * Dữ liệu được sinh bằng cách MÔ PHỎNG đúng nghiệp vụ theo thời gian:
 *   - chỉ xuất / điều chuyển khi đủ tồn -> tồn không bao giờ âm
 *   - mỗi phiếu đi đúng quy trình: lập PENDING -> ghi sổ -> APPROVED
 *   - stocks được tính từ chính các phát sinh -> luôn khớp sổ
 * Seed cố định nên mỗi lần chạy đều ra cùng một bộ dữ liệu.
 */

mt_srand(2026);

const PASSWORD_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'; // mật khẩu: password
const MANAGER_ID    = 2;

// ---------------------------------------------------------------------
// DANH MỤC
// ---------------------------------------------------------------------

// id => [username, họ tên, email, role]
$users = [
    1 => ['admin',      'Quản trị hệ thống',     'admin@kho.local', 'ADMIN'],
    2 => ['quanly',     'Nguyễn Văn Quản Lý',    'quanly@kho.local', 'MANAGER'],
    3 => ['nhanvien_a', 'Trần Thị A (Kho HN)',   'nva@kho.local', 'STAFF'],
    4 => ['nhanvien_b', 'Lê Văn B (Kho HCM)',    'nvb@kho.local', 'STAFF'],
    5 => ['nhanvien_c', 'Phạm Thị C (Kho ĐN)',   'nvc@kho.local', 'STAFF'],
];

// id => [mã, tên, địa chỉ, nhân viên phụ trách]
$warehouses = [
    1 => ['KHO-HN',  'Kho Hà Nội',  'Số 1 Đại Cồ Việt, Hai Bà Trưng, Hà Nội', 3],
    2 => ['KHO-HCM', 'Kho TP.HCM',  'Số 10 Nguyễn Huệ, Quận 1, TP.HCM',       4],
    3 => ['KHO-DN',  'Kho Đà Nẵng', 'Số 5 Bạch Đằng, Hải Châu, Đà Nẵng',      5],
];

// admin, quanly: mọi kho | mỗi nhân viên: chỉ kho mình phụ trách
$userWarehouses = [[1, 1], [1, 2], [1, 3], [2, 1], [2, 2], [2, 3], [3, 1], [4, 2], [5, 3]];

// Kho ảo "Hàng đang đi đường" của điều chuyển: không phân quyền cho ai -> không hiện trong danh mục kho
const TRANSIT_ID = 4;

// [tên, đơn vị tính, hạn dùng (tháng)]
$items = [
    1  => ['Sữa tươi Vinamilk 1L',          'Hộp',   6],
    2  => ['Mì Hảo Hảo tôm chua cay',       'Thùng', 8],
    3  => ['Nước mắm Nam Ngư 500ml',        'Chai',  24],
    4  => ['Gạo ST25 5kg',                  'Bao',   12],
    5  => ['Dầu ăn Neptune 1L',             'Chai',  18],
    6  => ['Đường Biên Hòa 1kg',            'Gói',   24],
    7  => ['Bột ngọt Ajinomoto 400g',       'Gói',   36],
    8  => ['Nước tương Chinsu 500ml',       'Chai',  18],
    9  => ['Cà phê G7 3in1',                'Hộp',   24],
    10 => ['Trà Lipton túi lọc',            'Hộp',   24],
    11 => ['Bánh quy Cosy 400g',            'Hộp',   12],
    12 => ['Sữa đặc Ông Thọ 380g',          'Lon',   12],
    13 => ['Nước suối Lavie 500ml',         'Thùng', 12],
    14 => ['Coca-Cola 330ml',               'Thùng', 9],
    15 => ['Bia Saigon Special 330ml',      'Thùng', 12],
    16 => ['Nước giặt OMO 3.7kg',           'Can',   36],
    17 => ['Nước rửa chén Sunlight 750ml',  'Chai',  36],
    18 => ['Dầu gội Clear 650g',            'Chai',  36],
    19 => ['Kem đánh răng P/S 230g',        'Tuýp',  36],
    20 => ['Giấy vệ sinh Pulppy 10 cuộn',   'Lốc',   60],
    21 => ['Xúc xích Vissan 175g',          'Gói',   6],
    22 => ['Cá hộp 3 Cô Gái 190g',          'Lon',   36],
    23 => ['Bột giặt Tide 2.25kg',          'Túi',   36],
    24 => ['Sữa chua Vinamilk 100g',        'Lốc',   2],
    25 => ['Bánh mì sandwich Kinh Đô',      'Gói',   3],
    26 => ['Hạt nêm Knorr 900g',            'Gói',   18],
    27 => ['Tương ớt Chinsu 250g',          'Chai',  18],
    28 => ['Phở bò ăn liền Vifon',          'Thùng', 8],
    29 => ['Nước tăng lực Sting 330ml',     'Thùng', 12],
    30 => ['Khăn giấy An An 180 tờ',        'Gói',   60],
];

// Lô: mỗi mặt hàng 1-2 lô (lô cũ sản xuất tháng 6, lô mới tháng 8)
$batches   = [];   // id => [item_id, batch_no, mfg, expiry]
$batchIds  = [];   // các lô dùng cho mô phỏng ngẫu nhiên
foreach ($items as $itemId => [, , $shelfMonths]) {
    $mfgDates = ($itemId % 4 === 0) ? ['2026-08-05'] : ['2026-06-10', '2026-08-05'];
    foreach ($mfgDates as $mfg) {
        $id = count($batches) + 1;
        $mfgDay = date('Y-m-d', strtotime($mfg . ' +' . mt_rand(0, 15) . ' days'));
        $batches[$id] = [
            $itemId,
            'L' . date('ym', strtotime($mfgDay)) . '-' . sprintf('%02d', $itemId),
            $mfgDay,
            date('Y-m-d', strtotime("$mfgDay +$shelfMonths months")),
        ];
        $batchIds[] = $id;
    }
}
// Lô riêng cho phép thử 1 (không dùng trong mô phỏng ngẫu nhiên -> tồn đúng 5)
$raceBatchId = count($batches) + 1;
$batches[$raceBatchId] = [3, 'L2610-TEST', '2026-10-01', '2028-10-01'];

// ---------------------------------------------------------------------
// BỘ GHI CHỨNG TỪ: vừa mô phỏng tồn kho, vừa sinh câu SQL
// ---------------------------------------------------------------------

/**
 * Mô phỏng chứng từ theo đúng các bước của ứng dụng (đúng thứ tự trigger yêu cầu):
 *   lập PENDING -> duyệt (xuất / điều chuyển: giữ hàng) -> từng ĐỢT GHI SỔ (nhận / xuất / gửi / hàng đến)
 *   -> trạng thái cuối. Vừa tính tồn + hàng đang giữ, vừa sinh câu SQL.
 */
final class Ledger
{
    public array $sql = [];
    public array $stock = [];          // "kho:lô" => số lượng
    public array $reserved = [];       // "kho:lô" => đang giữ chỗ
    public int $movementCount = 0;
    public int $voucherCount = 0;
    private int $lineId = 0;
    private int $postingId = 0;
    private array $codeSeq = [];
    private array $vouchers = [];      // id => [type, kho, kho đến, mã, số đợt, [dòng: [line_id, batch_id, qty]]]
    private const PREFIX = ['IMPORT' => 'PN', 'EXPORT' => 'PX', 'TRANSFER' => 'PC', 'ADJUSTMENT' => 'DC'];

    public function __construct(private array $batches, private int $transitId) {}

    public function qty(int $wh, int $batchId): int
    {
        return $this->stock["$wh:$batchId"] ?? 0;
    }

    /** Các lô còn hàng CÓ THỂ XUẤT trong kho (tồn - đang giữ): [batch_id => số lượng] */
    public function available(int $wh, array $exclude = []): array
    {
        $result = [];
        foreach ($this->stock as $key => $qty) {
            [$w, $b] = array_map('intval', explode(':', $key));
            $free = $qty - ($this->reserved[$key] ?? 0);
            if ($w === $wh && $free > 0 && !in_array($b, $exclude, true)) {
                $result[$b] = $free;
            }
        }
        return $result;
    }

    /** Lập chứng từ PENDING. $lines = [[batch_id, số lượng], ...]. Trả về [id, mã]. */
    public function create(string $type, int $wh, ?int $toWh, string $createdAt, int $createdBy,
                           string $reason, array $lines, ?int $refId = null): array
    {
        $id   = ++$this->voucherCount;
        $year = substr($createdAt, 0, 4);
        $seq  = $this->codeSeq[$type] = ($this->codeSeq[$type] ?? 0) + 1;
        $code = sprintf('%s-%s-%04d', self::PREFIX[$type], $year, $seq);

        $this->sql[] = sprintf(
            "INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)\n"
            . "VALUES (%d, %s, '%s', %d, %s, %s, '%s', %s, %d, '%s');",
            $id, q($code), $type, $wh, $toWh ?? 'NULL', $refId ?? 'NULL',
            substr($createdAt, 0, 10), q($reason), $createdBy, $createdAt
        );
        $rows = [];
        $voucherLines = [];
        foreach ($lines as [$batchId, $qty]) {
            $lineId = ++$this->lineId;
            $rows[] = sprintf('(%d, %d, %d, %d, %d)', $lineId, $id, $this->batches[$batchId][0], $batchId, $qty);
            $voucherLines[] = [$lineId, $batchId, $qty];
        }
        $this->sql[] = "INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES\n" . implode(",\n", $rows) . ';';
        $this->vouchers[$id] = [$type, $wh, $toWh, $code, 0, $voucherLines];
        return [$id, $code];
    }

    /** Duyệt: phiếu xuất / điều chuyển giữ hàng ở kho (đi) */
    public function approve(int $id, string $at): void
    {
        [$type, $wh, , $code, , $lines] = $this->vouchers[$id];
        if (in_array($type, ['EXPORT', 'TRANSFER'], true)) {
            foreach ($lines as [, $batchId, $qty]) {
                $key = "$wh:$batchId";
                if ($this->qty($wh, $batchId) - ($this->reserved[$key] ?? 0) < $qty) {
                    throw new RuntimeException("Mô phỏng sai: $code giữ quá số có thể xuất (kho $wh, lô $batchId)");
                }
                $this->reserved[$key] = ($this->reserved[$key] ?? 0) + $qty;
            }
        }
        $this->sql[] = sprintf("UPDATE vouchers SET status = 'APPROVED', approved_by = %d, approved_at = '%s' WHERE id = %d;", MANAGER_ID, $at, $id);
    }

    /**
     * 1 đợt ghi sổ. $quantities = [vị trí dòng => số lượng] (null = đủ số trên phiếu), $damaged tương tự.
     * Trả về [vị trí dòng => số đã ghi].
     */
    public function post(int $id, string $kind, string $at, int $by, ?array $quantities = null, array $damaged = [], ?string $note = null): array
    {
        [$type, $wh, $toWh, $code, $count, $lines] = $this->vouchers[$id];
        $postingId = ++$this->postingId;
        $this->vouchers[$id][4] = ++$count;
        $this->sql[] = sprintf("INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (%d, %d, %s, '%s', %s, %d, '%s');",
            $postingId, $id, q("$code/$count"), $kind, q($note), $by, $at);

        $postingRows = [];
        $movementRows = [];
        $done = [];
        foreach ($lines as $n => [$lineId, $batchId, $lineQty]) {
            $qty = $quantities === null ? $lineQty : ($quantities[$n] ?? 0);
            $bad = $damaged[$n] ?? 0;
            if ($qty === 0 && $bad === 0) {
                continue;
            }
            $postingRows[] = sprintf('(%d, %d, %d, %d)', $postingId, $lineId, $qty, $bad);
            $done[$n] = $qty;
            $changes = match ($kind) {
                'RECEIVE' => [[$wh, $qty, false]],
                'SHIP'    => [[$wh, -$qty, true]],
                'SEND'    => [[$wh, -$qty, true], [$this->transitId, $qty, false]],
                'ARRIVE'  => [[$this->transitId, -$qty, false], [$toWh, $qty, false]],
                'ADJUST'  => [[$wh, $qty, false]],
            };
            foreach ($changes as [$w, $delta, $fromReserved]) {
                if ($delta === 0) {
                    continue;
                }
                $key = "$w:$batchId";
                $newQty = $this->qty($w, $batchId) + $delta;
                if ($newQty < 0) {
                    throw new RuntimeException("Mô phỏng sai: $code làm tồn âm (kho $w, lô $batchId)");
                }
                $this->stock[$key] = $newQty;
                if ($fromReserved) {
                    $this->reserved[$key] -= -$delta;
                }
                $movementRows[] = sprintf("(%d, %d, %d, %d, %d, %d, %d, '%s', %d)",
                    $id, $postingId, $lineId, $w, $this->batches[$batchId][0], $batchId, $delta, $at, $by);
                $this->movementCount++;
            }
        }
        $this->sql[] = "INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES\n" . implode(",\n", $postingRows) . ';';
        if ($movementRows) {
            $this->sql[] = "INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES\n"
                . implode(",\n", $movementRows) . ';';
        }
        // Tiến độ từng dòng
        $column = ['RECEIVE' => 'received_qty', 'SHIP' => 'shipped_qty', 'SEND' => 'shipped_qty', 'ARRIVE' => 'received_qty'][$kind] ?? null;
        foreach ($column ? $done : [] as $n => $qty) {
            $this->sql[] = sprintf('UPDATE voucher_lines SET %s = %s + %d%s WHERE id = %d;',
                $column, $column, $qty, ($damaged[$n] ?? 0) ? ', damaged_qty = damaged_qty + ' . $damaged[$n] : '', $lines[$n][0]);
        }
        return $done;
    }

    public function status(int $id, string $status, array $set = []): void
    {
        $assign = array_map(fn ($col, $value) => "$col = " . q($value), array_keys($set), $set);
        $this->sql[] = sprintf("UPDATE vouchers SET status = '%s'%s WHERE id = %d;", $status, $assign ? ', ' . implode(', ', $assign) : '', $id);
    }

    /**
     * Lập + thực hiện trọn vẹn 1 chứng từ (dữ liệu lịch sử). $approveAt = null -> để PENDING (dùng cho phép thử).
     * Điều chuyển: gửi lúc duyệt, kho đến nhận sau 90 phút. Trả về [id, mã chứng từ].
     */
    public function voucher(string $type, int $wh, ?int $toWh, string $createdAt, int $createdBy,
                            string $reason, array $lines, ?string $approveAt, ?int $refId = null, int $receiver = 0): array
    {
        [$id, $code] = $this->create($type, $wh, $toWh, $createdAt, $createdBy, $reason, $lines, $refId);
        if ($approveAt === null) {
            $this->sql[] = '';
            return [$id, $code];
        }
        switch ($type) {
            case 'ADJUSTMENT':
                $this->post($id, 'ADJUST', $approveAt, MANAGER_ID);
                $this->approve($id, $approveAt);
                break;
            case 'IMPORT':
                $this->approve($id, $approveAt);
                $this->post($id, 'RECEIVE', $approveAt, $createdBy);
                $this->status($id, 'COMPLETED', ['completed_at' => $approveAt]);
                break;
            case 'EXPORT':
                $this->approve($id, $approveAt);
                $this->post($id, 'SHIP', $approveAt, $createdBy);
                $this->status($id, 'COMPLETED', ['shipped_at' => $approveAt, 'completed_at' => $approveAt]);
                break;
            case 'TRANSFER':
                $arriveAt = date('Y-m-d H:i:s', strtotime($approveAt) + 5400);
                $this->approve($id, $approveAt);
                $this->post($id, 'SEND', $approveAt, $createdBy);
                $this->status($id, 'IN_TRANSIT', ['shipped_at' => $approveAt]);
                $this->post($id, 'ARRIVE', $arriveAt, $receiver ?: $createdBy);
                $this->status($id, 'COMPLETED', ['completed_at' => $arriveAt]);
                break;
        }
        $this->sql[] = '';
        return [$id, $code];
    }
}

function q(?string $value): string
{
    return $value === null ? 'NULL' : "'" . str_replace("'", "''", $value) . "'";
}

function randomTime(string $date, int $fromHour = 8, int $toHour = 16): string
{
    return sprintf('%s %02d:%02d:00', $date, mt_rand($fromHour, $toHour), mt_rand(0, 59));
}

/** Chọn ngẫu nhiên $n phần tử khác nhau */
function pick(array $values, int $n): array
{
    shuffle($values);
    return array_slice($values, 0, min($n, count($values)));
}

$ledger = new Ledger($batches, TRANSIT_ID);
$suppliers = ['Vinamilk', 'Acecook', 'Masan', 'Unilever', 'Kinh Đô', 'Sabeco', 'Coca-Cola VN', 'Tân Hiệp Phát'];
$customers = ['Đại lý Minh Anh', 'Siêu thị Hòa Bình', 'Tạp hóa Cô Ba', 'Cửa hàng Thành Công', 'Đại lý Phú Quý', 'Mini mart 24h'];

// ---------------------------------------------------------------------
// 1. NHẬP TỒN ĐẦU KỲ (01-03/08): mỗi kho 1 phiếu nhập nhiều dòng
// ---------------------------------------------------------------------
foreach ($warehouses as $wh => [, $whName, , $staff]) {
    $date = sprintf('2026-08-%02d', $wh);
    $lines = array_map(fn ($b) => [$b, mt_rand(8, 30) * 10], pick($batchIds, 14));
    $ledger->voucher('IMPORT', $wh, null, "$date 08:00:00", $staff, "Nhập tồn đầu kỳ $whName", $lines, "$date 10:00:00");
}

// ---------------------------------------------------------------------
// 2. HOẠT ĐỘNG HẰNG NGÀY (05/08 - 05/10): nhập / xuất / điều chuyển
// ---------------------------------------------------------------------
for ($day = strtotime('2026-08-05'); $day <= strtotime('2026-10-05'); $day += 86400) {
    $date = date('Y-m-d', $day);
    $wh = mt_rand(1, 3);
    $staff = $warehouses[$wh][3];
    $roll = mt_rand(1, 100);

    if ($roll <= 25) {                                          // nhập hàng
        $lines = array_map(fn ($b) => [$b, mt_rand(5, 20) * 10], pick($batchIds, mt_rand(3, 5)));
        $supplier = $suppliers[array_rand($suppliers)];
        $ledger->voucher('IMPORT', $wh, null, randomTime($date, 8, 10), $staff,
            "Nhập hàng từ NCC $supplier", $lines, randomTime($date, 11, 12));
    } elseif ($roll <= 75) {                                    // xuất bán
        $available = $ledger->available($wh, [$raceBatchId]);
        if (count($available) < 2) {
            continue;
        }
        $lines = [];
        foreach (pick(array_keys($available), mt_rand(2, 4)) as $b) {
            $lines[] = [$b, mt_rand(1, max(1, intdiv($available[$b], 4)))];
        }
        $customer = $customers[array_rand($customers)];
        $ledger->voucher('EXPORT', $wh, null, randomTime($date, 8, 11), $staff,
            "Xuất bán cho $customer", $lines, randomTime($date, 13, 16));
    } else {                                                    // điều chuyển
        $available = $ledger->available($wh, [$raceBatchId]);
        if (count($available) < 1) {
            continue;
        }
        $toWh = pick(array_diff([1, 2, 3], [$wh]), 1)[0];
        $lines = [];
        foreach (pick(array_keys($available), mt_rand(1, 3)) as $b) {
            $lines[] = [$b, mt_rand(1, max(1, intdiv($available[$b], 3)))];
        }
        $ledger->voucher('TRANSFER', $wh, $toWh, randomTime($date, 8, 11), $staff,
            "Điều chuyển bổ sung hàng cho {$warehouses[$toWh][1]}", $lines, randomTime($date, 13, 16), null, $warehouses[$toWh][3]);
    }
}

// ---------------------------------------------------------------------
// 3. SỬA CHỨNG TỪ ĐÃ GHI NHẬN (06-07/10): nhập nhầm 100 thay vì 10
//    -> lập phiếu điều chỉnh -100 tham chiếu phiếu sai, rồi nhập lại 10
// ---------------------------------------------------------------------
$oilBatch = array_values(array_filter($batchIds, fn ($b) => $batches[$b][0] === 5))[0];
[$wrongId, $wrongCode] = $ledger->voucher('IMPORT', 1, null, '2026-10-06 08:30:00', 3,
    'Nhập hàng từ NCC Tường An', [[$oilBatch, 100]], '2026-10-06 09:00:00');
$ledger->voucher('ADJUSTMENT', 1, null, '2026-10-07 08:00:00', 3,
    "Hủy phiếu $wrongCode: nhập nhầm 100 thay vì 10", [[$oilBatch, -100]], '2026-10-07 08:30:00', $wrongId);
$ledger->voucher('IMPORT', 1, null, '2026-10-07 08:40:00', 3,
    "Nhập lại đúng số lượng thay cho phiếu $wrongCode", [[$oilBatch, 10]], '2026-10-07 09:00:00');

// ---------------------------------------------------------------------
// 4. KIỂM KÊ KHO HCM (10/10) có chênh lệch -> phiếu điều chỉnh (11/10)
// ---------------------------------------------------------------------
$countWh = 2;
$snapshot = $ledger->available($countWh);
ksort($snapshot);
$differences = [];   // batch_id => [chênh lệch, lý do]
$reasons = [[-3, 'Hàng móp méo, hư hỏng khi bốc xếp'], [2, 'Hàng nhập dư chưa ghi sổ'], [-1, 'Thất lạc, chưa xác định nguyên nhân']];
foreach (pick(array_keys(array_filter($snapshot, fn ($q) => $q >= 5)), 3) as $i => $b) {
    $differences[$b] = $reasons[$i];
}

$ledger->sql[] = "INSERT INTO stock_counts (id, code, warehouse_id, status, note, created_by, created_at)\n"
    . "VALUES (1, 'KK-2026-0001', $countWh, 'COUNTING', 'Kiểm kê định kỳ tháng 10', 4, '2026-10-10 07:30:00');";
$countRows = [];
foreach ($snapshot as $b => $systemQty) {
    [$diff, $reason] = $differences[$b] ?? [0, null];
    $countRows[] = sprintf("(1, %d, %d, %d, %d, %s, 4, '2026-10-10 15:00:00')",
        $batches[$b][0], $b, $systemQty, $systemQty + $diff, q($reason));
}
$ledger->sql[] = "INSERT INTO stock_count_lines (count_id, item_id, batch_id, system_qty, counted_qty, reason, counted_by, counted_at) VALUES\n"
    . implode(",\n", $countRows) . ';';
$ledger->sql[] = '';

$adjustLines = array_map(fn ($b) => [$b, $differences[$b][0]], array_keys($differences));
[$adjustId] = $ledger->voucher('ADJUSTMENT', $countWh, null, '2026-10-11 08:00:00', 4,
    'Điều chỉnh chênh lệch theo kiểm kê KK-2026-0001', $adjustLines, '2026-10-11 09:00:00');
$ledger->sql[] = "UPDATE stock_counts SET status = 'APPROVED', approved_by = " . MANAGER_ID
    . ", approved_at = '2026-10-11 09:00:00', adjustment_voucher_id = $adjustId WHERE id = 1;";
$ledger->sql[] = '';

// ---------------------------------------------------------------------
// 5. DỮ LIỆU CHO PHÉP THỬ
// ---------------------------------------------------------------------
// Phép thử 1: lô L2610-TEST ở Kho HN tồn đúng 5, hai phiếu cùng xuất 4 (PENDING)
$ledger->voucher('IMPORT', 1, null, '2026-10-18 08:00:00', 3,
    'Nhập lô dùng cho phép thử 1 (tồn đúng 5)', [[$raceBatchId, 5]], '2026-10-18 09:00:00');
[, $race1] = $ledger->voucher('EXPORT', 1, null, '2026-10-20 08:00:00', 3, 'Phép thử 1 - yêu cầu xuất thứ nhất', [[$raceBatchId, 4]], null);
[, $race2] = $ledger->voucher('EXPORT', 1, null, '2026-10-20 08:01:00', 3, 'Phép thử 1 - yêu cầu xuất thứ hai', [[$raceBatchId, 4]], null);

// Phép thử 2: phiếu điều chuyển HN -> HCM đang chờ duyệt
$hnStock = $ledger->available(1, [$raceBatchId]);
arsort($hnStock);
$transferBatch = array_key_first($hnStock);
[, $transferCode] = $ledger->voucher('TRANSFER', 1, 2, '2026-10-20 09:00:00', 3,
    'Phép thử 2 - điều chuyển HN sang HCM', [[$transferBatch, 10]], null);

// ---------------------------------------------------------------------
// 5b. DỮ LIỆU MINH HỌA CÁC BƯỚC MỚI (chỉ dùng Kho ĐN / HCM, không đụng dữ liệu phép thử ở Kho HN)
// ---------------------------------------------------------------------
// Lô mới nhất của mặt hàng (id lô tăng theo ngày sản xuất)
$newestBatch = fn (int $itemId) => max(array_filter($batchIds, fn ($b) => $batches[$b][0] === $itemId));
$pickAvailable = function (int $wh, int $min) use ($ledger, $raceBatchId): array {
    $available = array_filter($ledger->available($wh, [$raceBatchId]), fn ($q) => $q >= $min);
    ksort($available);
    return array_keys($available);
};

// (b) Phiếu nhập giao 2 đợt: đợt 1 về 60 hộp sữa (2 hộp móp) + đủ 48 lốc sữa chua -> còn chờ 40 hộp
[$partialId, $partialCode] = $ledger->create('IMPORT', 3, null, '2026-10-22 08:30:00', 5,
    'Nhập hàng từ NCC Vinamilk (giao nhiều đợt)', [[$newestBatch(1), 100], [$newestBatch(24), 48]]);
$ledger->approve($partialId, '2026-10-22 09:00:00');
$ledger->post($partialId, 'RECEIVE', '2026-10-24 10:15:00', 5, [0 => 60, 1 => 48], [0 => 2], '2 hộp sữa móp méo, trả lại NCC');
$ledger->status($partialId, 'PARTIAL');
$ledger->sql[] = '';

// (b) Phiếu nhập đã duyệt, hàng chưa về
[$waitingId] = $ledger->create('IMPORT', 2, null, '2026-10-25 08:00:00', 4,
    'Nhập hàng từ NCC Masan (chờ hàng về)', [[$newestBatch(26), 60], [$newestBatch(27), 80]]);
$ledger->approve($waitingId, '2026-10-25 09:30:00');
$ledger->sql[] = '';

// (c) Phiếu xuất đã duyệt, đang giữ hàng chờ xe (lô 0, 1); lô 2 dùng cho điều chuyển bên dưới
$dnLots = $pickAvailable(3, 40);
[$reservedId] = $ledger->create('EXPORT', 3, null, '2026-10-26 08:00:00', 5,
    'Xuất bán cho Siêu thị Hòa Bình (chờ xe lấy hàng)', [[$dnLots[0], 20], [$dnLots[1], 15]]);
$ledger->approve($reservedId, '2026-10-26 09:00:00');
$ledger->sql[] = '';

// (a) Yêu cầu điều chuyển HCM -> ĐN đã được đồng ý: phiếu điều chuyển đã gửi, đang đi đường quá hạn
[$transitId, $transitCode] = $ledger->create('TRANSFER', 3, 2, '2026-10-21 09:00:00', MANAGER_ID,
    'Theo yêu cầu YC-2026-0001: Đại lý Phú Quý (HCM) đặt hàng, cần trước 25/10', [[$dnLots[2], 12]]);
$ledger->approve($transitId, '2026-10-21 09:00:00');
$ledger->post($transitId, 'SEND', '2026-10-21 14:00:00', 5);
$ledger->status($transitId, 'IN_TRANSIT', ['shipped_at' => '2026-10-21 14:00:00']);
$ledger->sql[] = '';

$ledger->sql[] = "INSERT INTO transfer_requests (id, code, from_warehouse_id, to_warehouse_id, needed_by, reason, created_by, created_at) VALUES\n"
    . "(1, 'YC-2026-0001', 3, 2, '2026-10-25', 'Đại lý Phú Quý (HCM) đặt hàng, cần trước 25/10', 4, '2026-10-20 16:00:00'),\n"
    . "(2, 'YC-2026-0002', 1, 2, '2026-11-02', 'Cửa hàng Thành Công đặt hàng, giao trước 02/11', 4, '2026-10-27 15:30:00');";
$hnLots = $pickAvailable(1, 40);
$ledger->sql[] = sprintf("INSERT INTO transfer_request_lines (request_id, item_id, quantity) VALUES\n(1, %d, 12),\n(2, %d, 20),\n(2, %d, 15);",
    $batches[$dnLots[2]][0], $batches[$hnLots[0]][0], $batches[$hnLots[1]][0]);
$ledger->sql[] = "UPDATE transfer_requests SET status = 'ACCEPTED', transfer_voucher_id = $transitId, decided_by = " . MANAGER_ID
    . ", decided_at = '2026-10-21 09:00:00', decision_note = 'Đồng ý, gửi chuyến chiều 21/10' WHERE id = 1;";
$ledger->sql[] = '';

// ---------------------------------------------------------------------
// 6. GHI FILE
// ---------------------------------------------------------------------
$out = [];
$out[] = "-- =====================================================================";
$out[] = "--  FILE TỰ SINH - KHÔNG SỬA TAY.";
$out[] = "--  Sửa database/schema.sql hoặc database/build_database.php rồi chạy:";
$out[] = "--      php database/build_database.php";
$out[] = "--  Import: phpMyAdmin > Nhập (Import)  hoặc  mysql -u root -p < quanlykhohang.sql";
$out[] = "--  Mật khẩu của TẤT CẢ tài khoản mẫu: password";
$out[] = "-- =====================================================================";
$out[] = '';
$out[] = rtrim(file_get_contents(__DIR__ . '/schema.sql'));
$out[] = '';
$out[] = "-- =====================================================================";
$out[] = "-- 9. DỮ LIỆU MẪU";
$out[] = "-- =====================================================================";
$out[] = '';

$rows = [];
foreach ($users as $id => [$username, $name, $email, $role]) {
    $rows[] = sprintf("(%d, %s, %s, %s, %s, '%s')", $id, q($username), q(PASSWORD_HASH), q($name), q($email), $role);
}
$out[] = "INSERT INTO users (id, username, password_hash, full_name, email, role) VALUES\n" . implode(",\n", $rows) . ";\n";

$rows = [];
foreach ($warehouses as $id => [$code, $name, $address]) {
    $rows[] = sprintf('(%d, %s, %s, %s)', $id, q($code), q($name), q($address));
}
$out[] = "INSERT INTO warehouses (id, code, name, address) VALUES\n" . implode(",\n", $rows) . ";\n";
$out[] = "-- Kho ảo cho hàng đang trên đường điều chuyển (không ai được phân quyền trực tiếp)";
$out[] = "INSERT INTO warehouses (id, code, name, address, is_transit) VALUES\n(" . TRANSIT_ID . ", 'TRANSIT', 'Hàng đang đi đường', NULL, 1);\n";

$rows = array_map(fn ($p) => "($p[0], $p[1])", $userWarehouses);
$out[] = "INSERT INTO user_warehouses (user_id, warehouse_id) VALUES\n" . implode(",\n", $rows) . ";\n";

$rows = [];
foreach ($items as $id => [$name, $unit]) {
    $rows[] = sprintf("(%d, 'SP%03d', %s, %s)", $id, $id, q($name), q($unit));
}
$out[] = "INSERT INTO items (id, sku, name, unit) VALUES\n" . implode(",\n", $rows) . ";\n";

$rows = [];
foreach ($batches as $id => [$itemId, $batchNo, $mfg, $expiry]) {
    $rows[] = sprintf("(%d, %d, %s, '%s', '%s')", $id, $itemId, q($batchNo), $mfg, $expiry);
}
$out[] = "INSERT INTO batches (id, item_id, batch_no, mfg_date, expiry_date) VALUES\n" . implode(",\n", $rows) . ";\n";

$out[] = "-- Chứng từ: lập PENDING -> duyệt -> từng đợt ghi sổ -> trạng thái cuối (đúng thứ tự trigger yêu cầu)";
$out[] = implode("\n", $ledger->sql);

// Tồn hiện tại = tổng phát sinh; đang giữ = phiếu xuất / điều chuyển đã duyệt chưa rời kho (tính từ chính mô phỏng)
$rows = [];
ksort($ledger->stock, SORT_NATURAL);
foreach ($ledger->stock as $key => $qty) {
    [$wh, $b] = array_map('intval', explode(':', $key));
    $rows[] = sprintf('(%d, %d, %d, %d, %d)', $wh, $batches[$b][0], $b, $qty, $ledger->reserved[$key] ?? 0);
}
$out[] = "-- Tồn hiện tại = SUM(stock_movements) theo kho/lô; reserved = hàng đang giữ chỗ";
$out[] = "INSERT INTO stocks (warehouse_id, item_id, batch_id, quantity, reserved) VALUES\n" . implode(",\n", $rows) . ";\n";

$out[] = "-- Phiếu chờ duyệt cho phép thử: $race1, $race2 (phép thử 1), $transferCode (phép thử 2)";
$out[] = "-- Minh họa: $partialCode nhận một phần, $transitCode đang đi đường (quá hạn), YC-2026-0002 chờ kho HN xử lý";

file_put_contents(__DIR__ . '/quanlykhohang.sql', implode("\n", $out) . "\n");

printf("Đã ghi database/quanlykhohang.sql\n");
printf("  Mặt hàng: %d | Lô: %d | Chứng từ: %d | Dòng phát sinh: %d | Dòng tồn: %d\n",
    count($items), count($batches), $ledger->voucherCount, $ledger->movementCount, count($ledger->stock));
