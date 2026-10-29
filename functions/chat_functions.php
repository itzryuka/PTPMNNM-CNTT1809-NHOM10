<?php
// TRỢ LÝ AI (BETA): hỏi đáp về số liệu kho bằng tiếng Việt, dùng Claude API (SDK chính thức anthropic-ai/sdk).
// Bot CHỈ ĐỌC: không lập / duyệt / sửa phiếu. Số liệu lấy qua các hàm báo cáo sẵn có ("công cụ"),
// luôn lọc theo kho user được phân quyền, nên nhân viên kho HN hỏi gì cũng chỉ thấy số của kho HN.
// Cấu hình: config/ai.local.php (không đưa lên git) hoặc biến môi trường ANTHROPIC_API_KEY.
// Cần nạp trước: helpers.php, auth.php, voucher_functions.php, report_functions.php, batch_functions.php, dashboard_functions.php

const CHAT_DEFAULT_MODEL   = 'claude-opus-5-5';
const CHAT_MAX_MESSAGE_LEN = 1000;
const CHAT_HISTORY_TURNS   = 6;      // giữ 6 lượt hỏi - đáp gần nhất làm ngữ cảnh
const CHAT_MAX_TOOL_ROUNDS = 6;      // tối đa số vòng gọi công cụ cho 1 câu hỏi
const CHAT_RATE_LIMIT      = 15;     // số câu hỏi tối đa / 5 phút / phiên
const CHAT_ROW_LIMIT       = 40;     // số dòng tối đa mỗi công cụ trả về

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/** ['api_key' => ..., 'model' => ...]; api_key rỗng = chưa cấu hình */
function chatConfig(): array
{
    $config = is_file(__DIR__ . '/../config/ai.local.php') ? (array) require __DIR__ . '/../config/ai.local.php' : [];
    return [
        'api_key' => (string) ($config['api_key'] ?? getenv('ANTHROPIC_API_KEY') ?: ''),
        'model'   => (string) ($config['model'] ?? CHAT_DEFAULT_MODEL),
    ];
}

/** Lý do chưa dùng được (null = sẵn sàng) */
function chatUnavailableReason(): ?string
{
    if (!class_exists(\Anthropic\Client::class)) {
        return 'Trợ lý AI chưa được cài: cần chạy "composer require anthropic-ai/sdk" trong thư mục dự án.';
    }
    if (chatConfig()['api_key'] === '') {
        return 'Trợ lý AI chưa được cấu hình API key (config/ai.local.php hoặc biến môi trường ANTHROPIC_API_KEY).';
    }
    return null;
}

function chatSystemPrompt(PDO $pdo, array $user): string
{
    $warehouses = array_map(fn ($w) => "{$w['code']} ({$w['name']})", getWarehouseOptions($pdo, $user['warehouses']));
    return 'Bạn là trợ lý của OmniStock, phần mềm quản lý kho nhiều điểm (nhập, xuất, điều chuyển qua kho trung chuyển, kiểm kê). '
        . 'Trả lời bằng tiếng Việt, ngắn gọn, đi thẳng vào số liệu; liệt kê bằng gạch đầu dòng (không dùng bảng), in đậm số quan trọng bằng **...**.' . "\n"
        . '- Mọi con số (tồn kho, lô, phiếu) phải lấy từ công cụ, không tự đoán. Nếu công cụ không trả về dữ liệu thì nói rõ là không tìm thấy.' . "\n"
        . '- Bạn chỉ đọc dữ liệu. Không lập, duyệt, sửa hay xóa được phiếu: khi người dùng muốn làm việc đó, chỉ họ tới trang tương ứng '
        . '(Phiếu Nhập Kho, Phiếu Xuất Kho, Yêu Cầu Điều Chuyển, Đợt kiểm kê, Tồn kho hiện tại, Lô Hàng, Thẻ kho).' . "\n"
        . '- "Có thể xuất" = tồn thực − đang giữ cho phiếu xuất / điều chuyển đã duyệt. Lô đã hết hạn không xuất được.' . "\n"
        . '- Người dùng chỉ xem được các kho được phân quyền; nếu họ hỏi kho khác, nói rằng họ không có quyền xem kho đó.' . "\n"
        . '- Câu hỏi không liên quan tới kho hàng: từ chối lịch sự và gợi ý những gì bạn giúp được.' . "\n\n"
        . 'Người đang hỏi: ' . $user['full_name'] . ' (' . roleLabel($user['role']) . '). '
        . 'Kho được xem: ' . ($warehouses ? implode(', ', $warehouses) : 'chưa được giao kho nào') . '. Hôm nay: ' . date('d/m/Y') . '.';
}

/** Công cụ chỉ đọc mà bot được gọi */
function chatTools(): array
{
    $statuses = array_values(array_unique(array_merge(...array_values(VOUCHER_TYPE_STATUSES))));
    return [
        [
            'name'        => 'get_overview',
            'description' => 'Tổng quan các kho người dùng được xem: số kho, số mặt hàng, phiếu chờ duyệt, yêu cầu điều chuyển chờ xử lý, '
                           . 'phiếu điều chuyển đang đi đường và số phiếu quá hạn nhận.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
        [
            'name'        => 'search_stock',
            'description' => 'Tra tồn kho hiện tại theo từng kho và lô: tồn thực, đang giữ, có thể xuất, hạn sử dụng. '
                           . 'Dùng để trả lời "còn bao nhiêu", "kho nào còn hàng", "lô nào sắp / đã hết hạn".',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'keyword'        => ['type' => 'string', 'description' => 'Mã hàng, tên mặt hàng hoặc số lô (bỏ trống = mọi mặt hàng)'],
                    'warehouse_code' => ['type' => 'string', 'description' => 'Mã kho, vd KHO-HN (bỏ trống = mọi kho được xem)'],
                    'expiry'         => ['type' => 'string', 'enum' => ['', 'soon', 'expired'], 'description' => 'soon = hết hạn trong 30 ngày, expired = đã hết hạn'],
                ],
            ],
        ],
        [
            'name'        => 'list_vouchers',
            'description' => 'Danh sách phiếu mới nhất theo loại và trạng thái (vd phiếu nhập đang chờ duyệt, phiếu điều chuyển đang đi đường).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'type'           => ['type' => 'string', 'enum' => array_keys(VOUCHER_TYPE_STATUSES), 'description' => 'IMPORT nhập, EXPORT xuất, TRANSFER điều chuyển, ADJUSTMENT điều chỉnh'],
                    'status'         => ['type' => 'string', 'enum' => array_merge([''], $statuses), 'description' => 'Bỏ trống = mọi trạng thái'],
                    'warehouse_code' => ['type' => 'string', 'description' => 'Mã kho (bỏ trống = mọi kho được xem)'],
                ],
                'required'   => ['type'],
            ],
        ],
        [
            'name'        => 'list_overdue_transfers',
            'description' => 'Phiếu điều chuyển đã gửi đi quá ' . OVERDUE_TRANSIT_DAYS . ' ngày mà kho đến chưa nhận đủ.',
            'inputSchema' => ['type' => 'object', 'properties' => new stdClass()],
        ],
    ];
}

/** Mã kho -> id, chỉ trong các kho được xem. Trả về [id, lỗi] */
function chatWarehouseId(PDO $pdo, array $user, string $code): array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return [0, null];
    }
    foreach (getWarehouseOptions($pdo, $user['warehouses']) as $warehouse) {
        if (strtoupper($warehouse['code']) === $code) {
            return [(int) $warehouse['id'], null];
        }
    }
    return [0, "Không có kho mã $code trong các kho người dùng được xem (không tồn tại hoặc không có quyền)."];
}

/** Chạy 1 công cụ, trả về JSON (luôn lọc theo kho được phân quyền của $user) */
function runChatTool(PDO $pdo, array $user, string $name, array $input): string
{
    $allowed = $user['warehouses'];
    $result  = match ($name) {
        'get_overview' => getDashboardStats($pdo, $allowed),
        'search_stock' => (function () use ($pdo, $user, $allowed, $input) {
            [$warehouseId, $error] = chatWarehouseId($pdo, $user, (string) ($input['warehouse_code'] ?? ''));
            if ($error) {
                return ['error' => $error];
            }
            $filters = ['keyword' => trim((string) ($input['keyword'] ?? '')), 'warehouse_id' => $warehouseId,
                        'expiry' => in_array($input['expiry'] ?? '', ['soon', 'expired'], true) ? $input['expiry'] : ''];
            $rows = getStockReport($pdo, $filters, $allowed, CHAT_ROW_LIMIT + 1, 0, ['expiry', 'asc']);
            return [
                'truncated' => count($rows) > CHAT_ROW_LIMIT,
                'rows'      => array_map(fn ($r) => [
                    'kho' => $r['warehouse_code'], 'ma_hang' => $r['sku'], 'ten' => $r['item_name'], 'dvt' => $r['unit'],
                    'lo' => $r['batch_no'], 'han_sd' => formatDate($r['expiry_date']), 'con_ngay' => $r['days_left'],
                    'ton' => plainQty($r['quantity']), 'dang_giu' => plainQty($r['reserved']), 'co_the_xuat' => plainQty($r['available']),
                ], array_slice($rows, 0, CHAT_ROW_LIMIT)),
            ];
        })(),
        'list_vouchers' => (function () use ($pdo, $user, $allowed, $input) {
            $type = (string) ($input['type'] ?? '');
            if (!array_key_exists($type, VOUCHER_TYPE_STATUSES)) {
                return ['error' => 'Loại phiếu không hợp lệ.'];
            }
            [$warehouseId, $error] = chatWarehouseId($pdo, $user, (string) ($input['warehouse_code'] ?? ''));
            if ($error) {
                return ['error' => $error];
            }
            $status  = in_array($input['status'] ?? '', VOUCHER_TYPE_STATUSES[$type], true) ? $input['status'] : '';
            $filters = ['keyword' => '', 'status' => $status, 'warehouse_id' => $warehouseId];
            return [
                'total' => countVouchers($pdo, $type, $filters, $allowed),
                'rows'  => array_map(fn ($v) => [
                    'ma_phieu' => $v['code'], 'ngay' => formatDate($v['voucher_date']), 'trang_thai' => voucherStatusLabel($v['status'], $type),
                    'kho' => $v['warehouse_code'] . ($v['to_warehouse_code'] ? ' -> ' . $v['to_warehouse_code'] : ''),
                    'so_dong' => (int) $v['line_count'], 'ly_do' => $v['reason'], 'nguoi_lap' => $v['creator_name'],
                ], getVouchers($pdo, $type, $filters, $allowed, 20, 0)),
            ];
        })(),
        'list_overdue_transfers' => array_map(fn ($v) => [
            'ma_phieu' => $v['code'], 'tu' => $v['from_name'], 'den' => $v['to_name'], 'so_ngay_di_duong' => (int) $v['days_on_road'],
            'trang_thai' => voucherStatusLabel($v['status'], 'TRANSFER'),
        ], getOverdueTransfers($pdo, $allowed)),
        default => ['error' => "Không có công cụ $name."],
    };
    return json_encode($result, JSON_UNESCAPED_UNICODE);
}

/**
 * Hỏi trợ lý. $history = các lượt trước [['role' => user|assistant, 'content' => chuỗi]].
 * Vòng lặp: Claude gọi công cụ -> server chạy (chỉ đọc, theo quyền kho) -> gửi kết quả lại, tới khi có câu trả lời.
 */
function askAssistant(PDO $pdo, array $user, array $history, string $question): string
{
    $config   = chatConfig();
    $client   = new \Anthropic\Client(apiKey: $config['api_key']);
    $system   = chatSystemPrompt($pdo, $user);
    $tools    = chatTools();
    $messages = array_merge($history, [['role' => 'user', 'content' => $question]]);

    for ($round = 0; ; $round++) {
        $response = $client->messages->create(
            model: $config['model'],
            maxTokens: 16000,
            system: [['type' => 'text', 'text' => $system]],
            tools: $tools,
            outputConfig: ['effort' => 'low'],   // hỏi đáp ngắn: mức suy nghĩ thấp là đủ, trả lời nhanh và rẻ
            messages: $messages,
        );
        if ($response->stopReason !== 'tool_use' || $round >= CHAT_MAX_TOOL_ROUNDS) {
            break;
        }
        $toolResults = [];
        foreach ($response->content as $block) {
            if ($block->type === 'tool_use') {
                $toolResults[] = [
                    'type'      => 'tool_result',
                    'toolUseID' => $block->id,
                    'content'   => runChatTool($pdo, $user, $block->name, (array) $block->input),
                ];
            }
        }
        $messages[] = ['role' => 'assistant', 'content' => $response->content];
        $messages[] = ['role' => 'user', 'content' => $toolResults];
    }

    if ($response->stopReason === 'refusal') {
        return 'Xin lỗi, mình không trả lời được câu hỏi này. Bạn thử hỏi về tồn kho, lô hàng hoặc phiếu nhé.';
    }
    $text = '';
    foreach ($response->content as $block) {
        if ($block->type === 'text') {
            $text .= $block->text;
        }
    }
    if ($response->stopReason === 'tool_use') {
        return 'Câu hỏi cần tra quá nhiều bước. Bạn thử hỏi cụ thể hơn (vd: một mặt hàng hoặc một kho).';
    }
    return trim($text) !== '' ? trim($text) : 'Mình chưa tìm được câu trả lời. Bạn thử hỏi lại cụ thể hơn nhé.';
}

/** Chặn hỏi dồn dập: tối đa CHAT_RATE_LIMIT câu / 5 phút / phiên */
function chatRateLimited(): bool
{
    $now  = time();
    $hits = array_filter($_SESSION['chat_hits'] ?? [], fn ($t) => $t > $now - 300);
    if (count($hits) >= CHAT_RATE_LIMIT) {
        return true;
    }
    $hits[] = $now;
    $_SESSION['chat_hits'] = array_values($hits);
    return false;
}
