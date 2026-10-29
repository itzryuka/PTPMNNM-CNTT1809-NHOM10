<?php
// Xuất / nhập phiếu nhập, xuất, điều chuyển bằng file CSV (mở bằng Excel).
// Cần nạp trước: helpers.php, csv_functions.php, voucher_functions.php
//
// Một phiếu có nhiều dòng hàng -> trong file, mỗi dòng là 1 dòng hàng; các dòng cùng "Số phiếu" gộp thành 1 phiếu.
// Phiếu nhập vào luôn ở trạng thái Chờ duyệt (đi qua đúng createVoucher như lập trên form), quản lý duyệt như bình thường.

const VOUCHER_CSV_COLUMNS = [
    'group'        => ['số phiếu', 'so phieu', 'mã phiếu', 'ma phieu'],
    'date'         => ['ngày lập', 'ngay lap'],
    'warehouse'    => ['mã kho', 'ma kho', 'mã kho đi', 'ma kho di'],
    'to_warehouse' => ['mã kho đến', 'ma kho den'],
    'reason'       => ['lý do', 'ly do', 'lý do / ghi chú', 'ghi chú phiếu'],
    'sku'          => ['mã hàng', 'ma hang', 'sku'],
    'batch'        => ['số lô', 'so lo'],
    'mfg'          => ['ngày sản xuất', 'ngay san xuat', 'nsx'],
    'expiry'       => ['hạn sử dụng', 'han su dung', 'hsd'],
    'quantity'     => ['số lượng', 'so luong'],
];
const VOUCHER_CSV_REQUIRED = ['group', 'warehouse', 'sku', 'batch', 'quantity'];

/** Cột của file mẫu theo loại phiếu (đúng các cột chức năng nhập đọc) */
function voucherCsvTemplateHeader(string $type): array
{
    return array_merge(
        ['Số phiếu', 'Ngày lập', $type === 'TRANSFER' ? 'Mã kho đi' : 'Mã kho'],
        $type === 'TRANSFER' ? ['Mã kho đến'] : [],
        ['Lý do', 'Mã hàng', 'Số lô'],
        $type === 'IMPORT' ? ['Ngày sản xuất', 'Hạn sử dụng'] : [],
        ['Số lượng'],
    );
}

/**
 * Dòng ví dụ cho file mẫu, lấy từ dữ liệu thật mà user được phép dùng (để tải mẫu về nhập thử là chạy được).
 * Phiếu 1 có 2 dòng hàng để thấy cách gộp theo "Số phiếu".
 */
function voucherCsvTemplateRows(PDO $pdo, string $type, array $allowedWarehouses): array
{
    $today = date('d/m/Y');
    if ($type === 'IMPORT') {
        $warehouses = getWarehouseOptions($pdo, $allowedWarehouses);
        $items      = array_slice(getItemOptions($pdo), 0, 3);
        if (!$warehouses || count($items) < 3) {
            return [];
        }
        $wh     = $warehouses[0]['code'];
        $expiry = date('d/m/Y', strtotime('+1 year'));
        return [
            ['1', $today, $wh, 'Nhập hàng NCC (ví dụ)', $items[0]['sku'], 'LO-MOI-01', $today, $expiry, '50'],
            ['1', $today, $wh, 'Nhập hàng NCC (ví dụ)', $items[1]['sku'], 'LO-MOI-02', '', $expiry, '24'],
            ['2', $today, $wh, 'Nhập bổ sung (ví dụ)', $items[2]['sku'], 'LO-MOI-03', '', $expiry, '10'],
        ];
    }

    // Xuất / điều chuyển: lấy hàng khỏi kho -> dùng lô đang còn tồn, chưa hết hạn
    [$whFilter, $whParams] = warehouseFilter('s.warehouse_id', $allowedWarehouses);
    $stmt = $pdo->prepare("
        SELECT w.code AS wh, i.sku, b.batch_no, s.quantity
        FROM stocks s
        JOIN warehouses w ON w.id = s.warehouse_id AND w.is_active = 1
        JOIN items i      ON i.id = s.item_id AND i.is_active = 1
        JOIN batches b    ON b.id = s.batch_id
        WHERE s.quantity >= 2 AND (b.expiry_date IS NULL OR b.expiry_date >= CURDATE()) AND $whFilter
        ORDER BY w.code, i.sku
        LIMIT 3
    ");
    $stmt->execute($whParams);
    $stock = $stmt->fetchAll();
    if (count($stock) < 3) {
        return [];
    }
    $wh = $stock[0]['wh'];
    $stock = array_values(array_filter($stock, fn ($s) => $s['wh'] === $wh)) ?: $stock;
    $qty = fn (array $s) => (string) max(1, min(5, (int) floor((float) $s['quantity'] / 2)));
    if ($type === 'TRANSFER') {
        $other = array_values(array_filter(getWarehouseOptions($pdo, null), fn ($w) => $w['code'] !== $wh));
        $to    = $other[0]['code'] ?? '';
        return array_map(fn ($s, $n) => [(string) ($n < 2 ? 1 : 2), $today, $wh, $to, 'Điều chuyển bổ sung hàng (ví dụ)', $s['sku'], $s['batch_no'], $qty($s)], $stock, array_keys($stock));
    }
    return array_map(fn ($s, $n) => [(string) ($n < 2 ? 1 : 2), $today, $wh, 'Xuất bán cho khách (ví dụ)', $s['sku'], $s['batch_no'], $qty($s)], $stock, array_keys($stock));
}

/**
 * Dòng cho file xuất: mỗi dòng hàng của mỗi phiếu là 1 dòng, cùng bộ lọc / thứ tự với trang danh sách.
 * Cột "Mã phiếu" dùng được làm "Số phiếu" nếu muốn nhập lại file (hệ thống cấp mã mới).
 */
function getVoucherExportRows(PDO $pdo, string $type, array $filters, array $allowedWarehouses, array $sort): array
{
    $vouchers = getVouchers($pdo, $type, $filters, $allowedWarehouses, 100000, 0, $sort);
    if (!$vouchers) {
        return [];
    }
    $ids  = array_column($vouchers, 'id');
    $stmt = $pdo->prepare('
        SELECT vl.voucher_id, i.sku, i.name, i.unit, b.batch_no, b.mfg_date, b.expiry_date, vl.quantity, vl.note
        FROM voucher_lines vl
        JOIN items i   ON i.id = vl.item_id
        JOIN batches b ON b.id = vl.batch_id
        WHERE vl.voucher_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
        ORDER BY vl.id
    ');
    $stmt->execute($ids);
    $lines = [];
    foreach ($stmt->fetchAll() as $line) {
        $lines[$line['voucher_id']][] = $line;
    }

    $rows = [];
    foreach ($vouchers as $v) {
        foreach ($lines[$v['id']] ?? [] as $l) {
            $rows[] = [
                $v['code'], formatDate($v['voucher_date']), voucherStatusLabel($v['status'], $type),
                $v['warehouse_code'], $v['warehouse_name'], $v['to_warehouse_code'] ?? '', $v['to_warehouse_name'] ?? '',
                $v['reason'] ?? '', $v['creator_name'],
                $l['sku'], $l['name'], $l['unit'], $l['batch_no'], formatDate($l['mfg_date']), formatDate($l['expiry_date']),
                formatQty($l['quantity']), $l['note'] ?? '',
            ];
        }
    }
    return $rows;
}

const VOUCHER_EXPORT_HEADER = [
    'Mã phiếu', 'Ngày lập', 'Trạng thái', 'Mã kho', 'Kho', 'Mã kho đến', 'Kho đến', 'Lý do', 'Người lập',
    'Mã hàng', 'Tên mặt hàng', 'ĐVT', 'Số lô', 'Ngày sản xuất', 'Hạn sử dụng', 'Số lượng', 'Ghi chú dòng',
];

/** Ngày trong file Excel: 28/10/2026, 28-10-2026, 2026-10-28. Trống -> ''. Sai -> null */
function parseCsvDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $value, $m)) {
        $date = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    } elseif (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $value, $m)) {
        $date = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
    } else {
        return null;
    }
    return isValidDate($date) ? $date : null;
}

/** Số lượng kiểu Việt Nam "2,5" -> "2.5"; bỏ dấu chấm phân cách nghìn "1.000" -> "1000" */
function parseCsvQuantity(string $value): string
{
    $value = str_replace(' ', '', trim($value));
    if (preg_match('/^\d{1,3}(\.\d{3})+$/', $value)) {
        $value = str_replace('.', '', $value);
    }
    return str_replace(',', '.', $value);
}

/**
 * Nhập phiếu từ file. Tất cả hoặc không: kiểm tra hết các dòng trước, rồi tạo mọi phiếu trong MỘT transaction.
 * Mỗi phiếu có client_token = md5(nội dung file + loại + số phiếu) -> nhập lại cùng một file không tạo trùng phiếu.
 * Trả về [số phiếu đã tạo, số phiếu bỏ qua vì đã nhập trước đó, các mã phiếu đã tạo].
 */
function importVouchers(PDO $pdo, array $user, string $type, array $rows, string $fileHash): array
{
    if (!in_array($type, ['IMPORT', 'EXPORT', 'TRANSFER'], true)) {
        throw new AppException('Loại phiếu này không nhập từ file được.');
    }

    $warehouses = [];
    foreach ($pdo->query('SELECT id, code FROM warehouses WHERE is_active = 1') as $w) {
        $warehouses[strtoupper($w['code'])] = (int) $w['id'];
    }
    $items = [];
    foreach ($pdo->query('SELECT id, sku FROM items WHERE is_active = 1') as $i) {
        $items[strtoupper($i['sku'])] = (int) $i['id'];
    }
    $batches = [];
    foreach ($pdo->query('SELECT id, item_id, batch_no FROM batches') as $b) {
        $batches[$b['item_id'] . '|' . mb_strtolower($b['batch_no'])] = (int) $b['id'];
    }

    // 1. Gộp dòng theo "Số phiếu"
    $groups = [];
    $errors = [];
    foreach ($rows as $line => $row) {
        $key = trim($row['group'] ?? '');
        if ($key === '') {
            $errors[] = "Dòng $line: thiếu Số phiếu";
            continue;
        }
        $groups[$key][$line] = $row;
    }

    // 2. Đổi mã -> id, kiểm tra từng dòng
    $inputs   = [];
    $newBatch = [];                                     // lô mới -> số phiếu đầu tiên dùng nó
    foreach ($groups as $key => $groupRows) {
        $firstLine = array_key_first($groupRows);
        $first     = $groupRows[$firstLine];
        $at        = "Phiếu \"$key\" (dòng $firstLine)";

        foreach ($groupRows as $line => $row) {
            foreach (['date' => 'Ngày lập', 'warehouse' => 'Mã kho', 'to_warehouse' => 'Mã kho đến', 'reason' => 'Lý do'] as $field => $label) {
                if (($row[$field] ?? '') !== ($first[$field] ?? '')) {
                    $errors[] = "Dòng $line: $label khác với dòng $firstLine dù cùng Số phiếu \"$key\"";
                }
            }
        }

        $date = parseCsvDate($first['date'] ?? '');
        if ($date === null) {
            $errors[] = "$at: Ngày lập \"{$first['date']}\" không đúng dạng ngày/tháng/năm";
            continue;
        }
        $warehouseId = $warehouses[strtoupper($first['warehouse'] ?? '')] ?? null;
        if ($warehouseId === null) {
            $errors[] = "$at: không có kho đang hoạt động mã \"{$first['warehouse']}\"";
            continue;
        }
        $toWarehouseId = null;
        if ($type === 'TRANSFER') {
            $toWarehouseId = $warehouses[strtoupper($first['to_warehouse'] ?? '')] ?? null;
            if ($toWarehouseId === null) {
                $errors[] = "$at: thiếu hoặc sai Mã kho đến \"" . ($first['to_warehouse'] ?? '') . '"';
                continue;
            }
        }

        $lines = [];
        foreach ($groupRows as $line => $row) {
            $itemId = $items[strtoupper($row['sku'])] ?? null;
            if ($itemId === null) {
                $errors[] = "Dòng $line: không có mặt hàng đang dùng mã \"{$row['sku']}\"";
                continue;
            }
            $batchNo = trim($row['batch']);
            $batchId = $batches[$itemId . '|' . mb_strtolower($batchNo)] ?? null;
            $entry   = ['item_id' => $itemId, 'quantity' => parseCsvQuantity($row['quantity'])];
            if ($batchId !== null) {
                $entry['batch_id'] = $batchId;
            } elseif ($type === 'IMPORT') {
                $expiry = parseCsvDate($row['expiry'] ?? '');
                $mfg    = parseCsvDate($row['mfg'] ?? '');
                if ($expiry === null || $expiry === '' || $mfg === null) {
                    $errors[] = "Dòng $line: lô mới \"$batchNo\" cần Hạn sử dụng (và Ngày sản xuất nếu có) đúng dạng ngày/tháng/năm";
                    continue;
                }
                $batchKey = $itemId . '|' . mb_strtolower($batchNo);
                if (isset($newBatch[$batchKey]) && $newBatch[$batchKey] !== $key) {
                    $errors[] = "Dòng $line: lô mới \"$batchNo\" của {$row['sku']} đã có ở phiếu \"{$newBatch[$batchKey]}\". Hãy gộp vào một phiếu";
                    continue;
                }
                $newBatch[$batchKey] = $key;
                $entry += ['batch_id' => 'new', 'new_batch_no' => $batchNo, 'new_mfg' => $mfg, 'new_expiry' => $expiry];
            } else {
                $errors[] = "Dòng $line: mặt hàng {$row['sku']} không có lô \"$batchNo\"";
                continue;
            }
            $lines[] = $entry;
        }

        $inputs[$key] = [$at, [
            'type'            => $type,
            'warehouse_id'    => $warehouseId,
            'to_warehouse_id' => $toWarehouseId,
            'voucher_date'    => $date === '' ? date('Y-m-d') : $date,
            'reason'          => $first['reason'] ?? '',
            'client_token'    => md5($fileHash . '|' . $type . '|' . $key),
            'lines'           => $lines,
        ]];
    }
    if ($errors) {
        throw new AppException(importErrorMessage($errors));
    }

    // 3. Tạo phiếu: dùng đúng createVoucher (quyền kho, tồn, hạn dùng, trùng lô...), chung một transaction
    $created = [];
    $skipped = 0;
    $pdo->beginTransaction();
    try {
        $code = $pdo->prepare('SELECT code FROM vouchers WHERE id = ?');
        foreach ($inputs as [$at, $input]) {
            if (findVoucherByToken($pdo, $input['client_token']) !== null) {
                $skipped++;                                  // file này đã nhập trước đó
                continue;
            }
            try {
                $code->execute([createVoucher($pdo, $user, $input)]);
                $created[] = $code->fetchColumn();
            } catch (AppException $e) {
                $errors[] = "$at: " . $e->getMessage();
            }
        }
        if ($errors) {
            throw new AppException(importErrorMessage($errors));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return [count($created), $skipped, $created];
}
