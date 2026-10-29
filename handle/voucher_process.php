<?php
// Xử lý chứng từ: lập phiếu, nhập file, duyệt / từ chối, và các bước sau duyệt
// (nhận hàng, đóng phiếu, xuất kho, hủy giữ chỗ, gửi / nhận điều chuyển, xử lý chênh lệch).
// Chỉ nhận POST, mọi kiểm tra quyền / trạng thái đều ở server (functions/voucher_flow_functions.php).
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/csv_functions.php';
require_once __DIR__ . '/../functions/voucher_csv_functions.php';

$user = requireLogin('../');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action    = (string) ($_POST['action'] ?? '');
$voucherId = (int) ($_POST['id'] ?? 0);
$type      = requestedVoucherType($_POST['type'] ?? '', ['IMPORT', 'EXPORT', 'TRANSFER']);

// Nhập từ file: quay về danh sách đang xem (giữ bộ lọc / sắp xếp / trang)
parse_str((string) ($_POST['return_query'] ?? ''), $listQuery);
$listQuery = ['type' => $type] + array_intersect_key($listQuery, array_flip(['q', 'status', 'warehouse', 'sort', 'dir', 'size', 'page']));
$listUrl   = '../views/voucher_list.php?' . http_build_query($listQuery);

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'import':
            $file = $_FILES['file'] ?? null;
            $rows = readCsvUpload($file, VOUCHER_CSV_COLUMNS, VOUCHER_CSV_REQUIRED);
            [$created, $skipped, $codes] = importVouchers($pdo, $user, $type, $rows, md5_file($file['tmp_name']));
            setFlash('success', $created
                ? "Đã nhập $created phiếu chờ duyệt: " . implode(', ', $codes) . '.' . ($skipped ? " Bỏ qua $skipped phiếu đã nhập từ file này trước đó." : '')
                : "File này đã được nhập trước đó ($skipped phiếu), không tạo thêm phiếu nào.");
            redirect($listUrl);

        case 'create':
            $voucherId = createVoucher($pdo, $user, $_POST);
            unset($_SESSION['voucher_form_old']);
            setFlash('success', 'Đã lưu phiếu, đang chờ quản lý kho duyệt.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'approve':
            approveVoucher($pdo, $user, $voucherId);
            setFlash('success', [
                'IMPORT'     => 'Đã duyệt phiếu nhập. Khi hàng về, thủ kho bấm "Nhận hàng" và ghi số thực nhận.',
                'EXPORT'     => 'Đã duyệt và giữ chỗ hàng cho phiếu xuất. Tồn chỉ bị trừ khi bấm "Đã xuất kho".',
                'TRANSFER'   => 'Đã duyệt và giữ chỗ hàng ở kho đi. Khi xe rời kho, bấm "Gửi hàng".',
                'ADJUSTMENT' => 'Đã xác nhận phiếu điều chỉnh: sổ phát sinh và tồn kho đã được cập nhật.',
            ][getVoucher($pdo, $voucherId)['type']]);
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'reject':
            rejectVoucher($pdo, $user, $voucherId);
            setFlash('success', 'Đã từ chối chứng từ. Không có phát sinh nào được ghi.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        // ----- (b) phiếu nhập -----
        case 'receive':
            receiveImport($pdo, $user, $voucherId, is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [], (string) ($_POST['note'] ?? ''), (string) ($_POST['client_token'] ?? ''));
            setFlash('success', 'Đã ghi nhận đợt nhận hàng: số nhận tốt đã cộng vào tồn' . (getVoucher($pdo, $voucherId)['status'] === 'COMPLETED' ? ', phiếu đã nhận đủ.' : ', phiếu vẫn mở chờ phần còn lại.'));
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'close':
            closeImport($pdo, $user, $voucherId, (string) ($_POST['reason'] ?? ''));
            setFlash('success', 'Đã đóng phiếu nhập. Tồn giữ nguyên số đã thực nhận.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        // ----- (c) phiếu xuất -----
        case 'ship':
            shipExport($pdo, $user, $voucherId, (string) ($_POST['client_token'] ?? ''));
            setFlash('success', 'Đã xuất kho: tồn và hàng đang giữ đã được trừ, sổ phát sinh đã ghi.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'cancel_reservation':
            cancelReservation($pdo, $user, $voucherId, (string) ($_POST['reason'] ?? ''));
            setFlash('success', 'Đã hủy giữ chỗ: hàng trở lại "có thể xuất".');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        // ----- (a) điều chuyển qua kho trung chuyển -----
        case 'send':
            sendTransfer($pdo, $user, $voucherId, (string) ($_POST['client_token'] ?? ''));
            setFlash('success', 'Đã gửi hàng: kho đi đã trừ, hàng chuyển sang "Đang đi đường" chờ kho đến nhận.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'receive_transfer':
            receiveTransfer($pdo, $user, $voucherId, is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [], (string) ($_POST['client_token'] ?? ''));
            setFlash('success', getVoucher($pdo, $voucherId)['status'] === 'COMPLETED'
                ? 'Đã nhận đủ hàng điều chuyển. Phiếu hoàn tất.'
                : 'Đã ghi nhận số thực nhận. Phần còn thiếu vẫn nằm ở "Đang đi đường", chờ quản lý xử lý.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        case 'resolve':
            $adjustmentId = resolveTransfer($pdo, $user, $voucherId, (string) ($_POST['resolution'] ?? ''), (string) ($_POST['note'] ?? ''));
            setFlash('success', $adjustmentId === null
                ? 'Phiếu quay lại "Đang đi đường": chờ giao bổ sung, kho đến nhận tiếp khi hàng tới.'
                : 'Đã ghi hao hụt bằng phiếu điều chỉnh ' . getVoucher($pdo, $adjustmentId)['code'] . '. Phiếu điều chuyển hoàn tất.');
            redirect('../views/voucher_detail.php?id=' . $voucherId);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (AppException $e) {
    setFlash('error', $e->getMessage());
} catch (PDOException $e) {
    // Transaction đã rollBack trong hàm nghiệp vụ -> không có thay đổi dở dang
    error_log('[voucher_process] ' . $e->getMessage());
    setFlash('error', dbErrorMessage($e));
}

// Lỗi: quay lại form (giữ dữ liệu đã nhập), danh sách (nhập file) hoặc trang chi tiết
if ($action === 'import') {
    redirect($listUrl);
}
if ($action === 'create') {
    $_SESSION['voucher_form_old'] = $_POST;
    redirect('../views/voucher_form.php?type=' . $type);
}
redirect('../views/voucher_detail.php?id=' . $voucherId);
