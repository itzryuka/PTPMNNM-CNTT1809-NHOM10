<?php
// TRỢ LÝ AI (BETA): hỏi đáp về số liệu kho bằng tiếng Việt.
// Chọn nhà cung cấp trong config/ai.local.php: 'gemini' (Google, có gói miễn phí, gọi thẳng bằng cURL)
// hoặc 'claude' (Anthropic, trả phí, cần cài SDK: composer require anthropic-ai/sdk).
// Bot CHỈ ĐỌC: không lập / duyệt / sửa phiếu. Số liệu lấy qua các hàm báo cáo sẵn có ("công cụ"),
// luôn lọc theo kho user được phân quyền, nên nhân viên kho HN hỏi gì cũng chỉ thấy số của kho HN.
// Cấu hình: config/ai.local.php (không đưa lên git) hoặc biến môi trường GEMINI_API_KEY / ANTHROPIC_API_KEY.
// Cần nạp trước: helpers.php, auth.php, voucher_functions.php, report_functions.php, batch_functions.php, dashboard_functions.php

const CHAT_DEFAULT_MODEL   = 'claude-opus-5-5';
const GEMINI_DEFAULT_MODEL = 'gemini-3.5-flash-lite';   // có trong gói miễn phí của Gemini API
const GEMINI_ENDPOINT      = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
const CHAT_MAX_MESSAGE_LEN = 1000;
const CHAT_HISTORY_TURNS   = 6;
const CHAT_MAX_TOOL_ROUNDS = 6;
const CHAT_RATE_LIMIT      = 15;
const CHAT_ROW_LIMIT       = 40;

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

function chatConfig(): array
{
    $config   = is_file(__DIR__ . '/../config/ai.local.php') ? (array) require __DIR__ . '/../config/ai.local.php' : [];
    $provider = (($config['provider'] ?? '') ?: (getenv('AI_PROVIDER') ?: 'gemini')) === 'claude' ? 'claude' : 'gemini';
    if ($provider === 'gemini') {
        return [
            'provider' => 'gemini',
            'api_key'  => (string) (($config['gemini_api_key'] ?? '') ?: (getenv('GEMINI_API_KEY') ?: '')),
            'model'    => (string) (($config['gemini_model'] ?? '') ?: GEMINI_DEFAULT_MODEL),
        ];
    }
    return [
        'provider' => 'claude',
        'api_key'  => (string) (($config['api_key'] ?? '') ?: (getenv('ANTHROPIC_API_KEY') ?: '')),
        'model'    => (string) (($config['model'] ?? '') ?: CHAT_DEFAULT_MODEL),
    ];
}

function chatUnavailableReason(): ?string
{
    $config = chatConfig();
    if ($config['provider'] === 'gemini') {
        if (!function_exists('curl_init')) {
            return 'Máy chủ chưa bật extension cURL của PHP (cần cho Trợ lý AI).';
        }
        return $config['api_key'] === '' ? 'Trợ lý AI chưa có Gemini API key: điền "gemini_api_key" trong config/ai.local.php.' : null;
    }
    if (!class_exists(\Anthropic\Client::class)) {
        return 'Trợ lý AI (Claude) chưa được cài: cần chạy "composer require anthropic-ai/sdk" trong thư mục dự án.';
    }
    return $config['api_key'] === '' ? 'Trợ lý AI chưa có Claude API key: điền "api_key" trong config/ai.local.php.' : null;
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
 * Vòng lặp: AI gọi công cụ -> server chạy (chỉ đọc, theo quyền kho) -> gửi kết quả lại, tới khi có câu trả lời.
 */
function askAssistant(PDO $pdo, array $user, array $history, string $question): string
{
    $config = chatConfig();
    return $config['provider'] === 'gemini'
        ? askGemini($pdo, $user, $config, $history, $question)
        : askClaude($pdo, $user, $config, $history, $question);
}

const CHAT_TOO_MANY_STEPS = 'Câu hỏi cần tra quá nhiều bước. Bạn thử hỏi cụ thể hơn (vd: một mặt hàng hoặc một kho).';
const CHAT_NO_ANSWER      = 'Mình chưa tìm được câu trả lời. Bạn thử hỏi lại cụ thể hơn nhé.';

function askClaude(PDO $pdo, array $user, array $config, array $history, string $question): string
{
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
        return CHAT_TOO_MANY_STEPS;
    }
    return trim($text) !== '' ? trim($text) : CHAT_NO_ANSWER;
}

/** Lỗi khi gọi Gemini; code = mã HTTP (429 = hết lượt miễn phí / gọi quá nhanh) */
class ChatServiceException extends RuntimeException
{
}

/** Công cụ theo định dạng Gemini: functionDeclarations (bỏ schema rỗng, bỏ giá trị enum rỗng) */
function geminiTools(): array
{
    $declarations = [];
    foreach (chatTools() as $tool) {
        $declaration = ['name' => $tool['name'], 'description' => $tool['description']];
        $schema      = $tool['inputSchema'];
        if (is_array($schema['properties']) && $schema['properties']) {
            foreach ($schema['properties'] as $key => $property) {
                if (isset($property['enum'])) {
                    $schema['properties'][$key]['enum'] = array_values(array_filter($property['enum'], fn ($v) => $v !== ''));
                }
            }
            $declaration['parameters'] = $schema;
        }
        $declarations[] = $declaration;
    }
    return [['functionDeclarations' => $declarations]];
}

/** Gọi Gemini generateContent bằng cURL, trả về phản hồi đã giải mã JSON */
function geminiRequest(array $config, array $body): array
{
    $curl = curl_init(sprintf(GEMINI_ENDPOINT, rawurlencode($config['model'])));
    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'x-goog-api-key: ' . $config['api_key']],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
    ]);
    $raw    = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error  = curl_error($curl);
    $errno  = curl_errno($curl);
    curl_close($curl);
    if ($raw === false) {
        // mã 504 = model trả lời quá lâu (hết thời gian chờ), 0 = không kết nối được mạng
        throw new ChatServiceException('Không kết nối được Gemini: ' . $error, $errno === CURLE_OPERATION_TIMEDOUT ? 504 : 0);
    }
    $json = json_decode($raw, true);
    if ($status !== 200 || !is_array($json)) {
        $message = $json['error']['message'] ?? substr((string) $raw, 0, 300);
        // Gemini báo key sai bằng 400 "API key not valid": tách riêng (401) để không lẫn với lỗi dữ liệu gửi đi
        $code = $status === 400 && stripos($message, 'API key') !== false ? 401 : $status;
        throw new ChatServiceException('Gemini HTTP ' . $status . ': ' . $message, $code);
    }
    return $json;
}

function askGemini(PDO $pdo, array $user, array $config, array $history, string $question): string
{
    $contents = [];
    foreach ($history as $turn) {
        $contents[] = ['role' => $turn['role'] === 'assistant' ? 'model' : 'user', 'parts' => [['text' => $turn['content']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $question]]];
    $base = [
        'systemInstruction' => ['parts' => [['text' => chatSystemPrompt($pdo, $user)]]],
        'tools'             => geminiTools(),
    ];

    for ($round = 0; $round <= CHAT_MAX_TOOL_ROUNDS; $round++) {
        $candidate = geminiRequest($config, $base + ['contents' => $contents])['candidates'][0] ?? null;
        if ($candidate === null || !isset($candidate['content']['parts'])) {
            return 'Xin lỗi, mình không trả lời được câu hỏi này. Bạn thử hỏi về tồn kho, lô hàng hoặc phiếu nhé.';
        }
        $parts = $candidate['content']['parts'];
        $calls = array_values(array_filter($parts, fn ($part) => isset($part['functionCall'])));
        if (!$calls) {
            $text = implode('', array_map(fn ($part) => empty($part['thought']) ? ($part['text'] ?? '') : '', $parts));
            return trim($text) !== '' ? trim($text) : CHAT_NO_ANSWER;
        }
        // Gửi lại nguyên phần trả lời của model (giữ thoughtSignature), kèm kết quả từng công cụ
        // json_decode biến {} thành [] -> args rỗng phải gửi lại dạng object, nếu không Gemini báo 400
        $content = $candidate['content'];
        foreach ($content['parts'] as $i => $part) {
            if (isset($part['functionCall'])) {
                $content['parts'][$i]['functionCall']['args'] = (object) ($part['functionCall']['args'] ?? []);
            }
        }
        $contents[] = $content;
        $results    = [];
        foreach ($calls as $part) {
            $call   = $part['functionCall'];
            $answer = ['name' => $call['name'], 'response' => ['result' => json_decode(runChatTool($pdo, $user, (string) $call['name'], (array) ($call['args'] ?? [])), true)]];
            if (isset($call['id'])) {
                $answer['id'] = $call['id'];
            }
            $results[] = ['functionResponse' => $answer];
        }
        $contents[] = ['role' => 'user', 'parts' => $results];
    }
    return CHAT_TOO_MANY_STEPS;
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
