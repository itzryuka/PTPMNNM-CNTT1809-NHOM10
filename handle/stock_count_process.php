<?php
// Xử lý Kiểm kê: tạo đợt, lưu số đếm, duyệt (sinh phiếu điều chỉnh), hủy. Chỉ nhận POST.
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/stock_count_functions.php';

$user = requireLogin('../');

// GET ?action=lines&id=...: trạng thái các dòng (JSON) để trang chi tiết thấy ngay dòng người khác vừa đếm và khóa
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'lines') {
    header('Content-Type: application/json; charset=utf-8');
    $pdo   = getDbConnection();
    $count = getStockCount($pdo, (int) ($_GET['id'] ?? 0));
    if ($count === null || !canAccessWarehouse($user, (int) $count['warehouse_id'])) {
        http_response_code(404);
        exit(json_encode(['error' => 'Không tìm thấy đợt kiểm kê.'], JSON_UNESCAPED_UNICODE));
    }
    exit(json_encode([
        'status'    => $count['status'],
        'code'      => $count['code'],
        'closed_by' => $count['status'] === 'COUNTING' ? null : $count['approver_name'],   // ai vừa duyệt / hủy đợt
        'lines'     => getStockCountLineStates($pdo, (int) $count['id'], (int) $user['id']),
    ], JSON_UNESCAPED_UNICODE));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Chỉ chấp nhận POST.');
}
verifyCsrf();

$action  = (string) ($_POST['action'] ?? '');
$countId = (int) ($_POST['id'] ?? 0);

// Lưu 1 dòng (fetch từ trang chi tiết): lưu xong dòng khóa ngay. Trả JSON kèm trạng thái mọi dòng để trang cập nhật luôn.
if ($action === 'save_line') {
    header('Content-Type: application/json; charset=utf-8');
    $lineId = (int) ($_POST['line_id'] ?? 0);
    $entry  = ['counted_qty' => (string) ($_POST['counted_qty'] ?? ''), 'reason' => (string) ($_POST['reason'] ?? '')];
    try {
        $pdo = getDbConnection();
        if (trim($entry['counted_qty']) === '') {
            throw new VoucherException('Nhập số đếm thực tế trước khi lưu.');
        }
        $result = saveStockCountEntries($pdo, $user, $countId, [$lineId => $entry]);
        // Có người lưu dòng này trước (2 người bấm gần như cùng lúc): 409 + báo ai đã nhập
        http_response_code($result['conflicts'] ? 409 : 200);
        echo json_encode([
            'ok'      => !$result['conflicts'],
            'message' => $result['conflicts'][0] ?? 'Đã lưu và khóa dòng.',
            'lines'   => getStockCountLineStates($pdo, $countId, (int) $user['id']),
        ], JSON_UNESCAPED_UNICODE);
    } catch (VoucherException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[stock_count_process] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Lỗi CSDL, dòng chưa được lưu. Bạn thử lại.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Giữ dòng khi bắt đầu gõ / nhả dòng (fetch, sendBeacon khi rời trang): trả JSON kèm trạng thái mọi dòng
if ($action === 'claim_line' || $action === 'release_line') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = getDbConnection();
        if ($action === 'claim_line') {
            $result = claimStockCountLine($pdo, $user, $countId, (int) ($_POST['line_id'] ?? 0));
        } else {
            $lineIds = is_array($_POST['line_ids'] ?? null) ? $_POST['line_ids'] : [(int) ($_POST['line_id'] ?? 0)];
            releaseStockCountLines($pdo, $user, $countId, $lineIds);
            $result = ['ok' => true, 'message' => ''];
        }
        http_response_code($result['ok'] ? 200 : 409);
        echo json_encode($result + ['lines' => getStockCountLineStates($pdo, $countId, (int) $user['id'])], JSON_UNESCAPED_UNICODE);
    } catch (VoucherException $e) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        error_log('[stock_count_process] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'message' => 'Lỗi CSDL, bạn thử lại.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

try {
    $pdo = getDbConnection();
    switch ($action) {
        case 'join':
            $code = joinStockCount($pdo, $user, $countId);
            setFlash('success', "Bạn đã tham gia đợt kiểm kê $code: gõ số đếm vào dòng nào thì dòng đó giữ cho bạn và tự lưu khi bạn chuyển sang dòng khác.");
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'create':
            $members = is_array($_POST['members'] ?? null) ? $_POST['members'] : [];
            $countId = createStockCount($pdo, $user, (int) ($_POST['warehouse_id'] ?? 0), (string) ($_POST['note'] ?? ''), $members);
            setFlash('success', 'Đã mở đợt kiểm kê, chụp số tồn và giao cho ' . count(array_unique($members)) . ' nhân viên đi đếm. Kho tạm khóa giao dịch cho tới khi chốt đợt.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'save':
            $entries = is_array($_POST['lines'] ?? null) ? $_POST['lines'] : [];
            $result = saveStockCountEntries($pdo, $user, $countId, $entries);
            if ($result['conflicts']) {
                // Người khác đã lưu trước những dòng này: không ghi đè, báo lại để người dùng đối chiếu
                setFlash('error', "Đã lưu {$result['saved']} dòng. Không ghi đè " . count($result['conflicts']) . ' dòng đã bị khóa: ' . implode(' ', $result['conflicts']));
            } else {
                setFlash('success', $result['saved'] > 0
                    ? "Đã lưu {$result['saved']} dòng. Các dòng này đã được khóa, người khác không sửa được."
                    : 'Chưa có dòng mới nào được nhập số đếm.');
            }
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'members':
            updateStockCountMembers($pdo, $user, $countId, is_array($_POST['members'] ?? null) ? $_POST['members'] : []);
            setFlash('success', 'Đã cập nhật danh sách nhân viên kiểm kê.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'unlock_line':
            $lineName = unlockStockCountLine($pdo, $user, $countId, (int) ($_POST['line_id'] ?? 0));
            setFlash('success', "Đã mở khóa dòng $lineName: nhân viên có thể đếm lại.");
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'approve':
            $adjustmentId = approveStockCount($pdo, $user, $countId, (string) ($_POST['reason'] ?? ''));
            setFlash('success', $adjustmentId
                ? 'Đã duyệt kiểm kê: phiếu điều chỉnh đã được ghi sổ, tồn kho khớp số đếm thực tế.'
                : 'Đã duyệt kiểm kê: không có chênh lệch nên không cần phiếu điều chỉnh.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        case 'cancel':
            cancelStockCount($pdo, $user, $countId);
            setFlash('success', 'Đã hủy đợt kiểm kê. Không có phát sinh nào được ghi, kho mở lại giao dịch.');
            redirect('../views/stock_count_detail.php?id=' . $countId);

        default:
            http_response_code(400);
            exit('Thao tác không hợp lệ.');
    }
} catch (VoucherException $e) {
    setFlash('error', $e->getMessage());
} catch (PDOException $e) {
    error_log('[stock_count_process] ' . $e->getMessage());
    setFlash('error', 'Lỗi CSDL, thao tác đã được hoàn tác: ' . $e->getMessage());
}

// Lỗi khi lưu số đếm: giữ lại số vừa nhập để không phải gõ lại
if ($action === 'save') {
    $_SESSION['stock_count_old'] = ['id' => $countId, 'lines' => $_POST['lines'] ?? []];
}
redirect($countId > 0 ? '../views/stock_count_detail.php?id=' . $countId : '../views/stock_count_list.php');
