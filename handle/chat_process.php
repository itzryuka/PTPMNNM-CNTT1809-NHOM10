<?php
// Trợ lý AI (beta): nhận câu hỏi từ khung chat (fetch, POST), trả JSON {reply} hoặc {error}.
//   action=ask   (message)  hỏi; lịch sử hội thoại giữ trong phiên đăng nhập
//   action=reset            xóa hội thoại
require_once __DIR__ . '/../functions/db_connection.php';
require_once __DIR__ . '/../functions/helpers.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/voucher_functions.php';
require_once __DIR__ . '/../functions/report_functions.php';
require_once __DIR__ . '/../functions/batch_functions.php';
require_once __DIR__ . '/../functions/dashboard_functions.php';
require_once __DIR__ . '/../functions/chat_functions.php';

header('Content-Type: application/json; charset=utf-8');

function chatJson(int $status, array $body): never
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

startSession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    chatJson(405, ['error' => 'Chỉ chấp nhận POST.']);
}
if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
    chatJson(400, ['error' => 'Phiên làm việc đã hết hạn, vui lòng tải lại trang.', 'reload' => true]);
}
$pdo  = getDbConnection();
$user = loadUser($pdo, (int) ($_SESSION['user_id'] ?? 0));
if ($user === null || $user['must_change_password']) {
    // tải lại trang: requireLogin sẽ đưa về trang đăng nhập (kèm thông báo nếu tài khoản bị khóa) / đổi mật khẩu
    chatJson(401, ['error' => 'Bạn cần đăng nhập lại.', 'reload' => true]);
}

$action = (string) ($_POST['action'] ?? 'ask');
if ($action === 'reset') {
    unset($_SESSION['chat_history']);
    chatJson(200, ['ok' => true]);
}

$question = trim((string) ($_POST['message'] ?? ''));
if ($question === '' || mb_strlen($question) > CHAT_MAX_MESSAGE_LEN) {
    chatJson(422, ['error' => 'Câu hỏi không được trống (tối đa ' . CHAT_MAX_MESSAGE_LEN . ' ký tự).']);
}
if (($reason = chatUnavailableReason()) !== null) {
    chatJson(503, ['error' => $reason]);
}
if (chatRateLimited()) {
    chatJson(429, ['error' => 'Bạn hỏi hơi nhanh, đợi vài phút rồi hỏi tiếp nhé.']);
}

$history = $_SESSION['chat_history'] ?? [];
session_write_close();                       // gọi API mất vài giây: không giữ khóa phiên, các trang khác vẫn mở được

try {
    $reply = askAssistant($pdo, $user, $history, $question);
} catch (\Anthropic\Core\Exceptions\APIStatusException $e) {
    error_log('[chat] ' . ($e->type?->value ?? '') . ' ' . $e->getMessage());
    $busy = in_array($e->type?->value, ['rate_limit_error', 'overloaded_error'], true);
    chatJson(502, ['error' => $busy ? 'Trợ lý đang quá tải, bạn thử lại sau ít phút.' : 'Trợ lý gặp lỗi khi gọi dịch vụ AI. Bạn thử lại sau.']);
} catch (Throwable $e) {
    error_log('[chat] ' . get_class($e) . ': ' . $e->getMessage());
    chatJson(500, ['error' => 'Trợ lý gặp lỗi, bạn thử lại sau.']);
}

// Lưu lượt hỏi - đáp (chỉ văn bản), giữ CHAT_HISTORY_TURNS lượt gần nhất
startSession();
$history[] = ['role' => 'user', 'content' => $question];
$history[] = ['role' => 'assistant', 'content' => $reply];
$_SESSION['chat_history'] = array_slice($history, -2 * CHAT_HISTORY_TURNS);
chatJson(200, ['reply' => $reply]);
