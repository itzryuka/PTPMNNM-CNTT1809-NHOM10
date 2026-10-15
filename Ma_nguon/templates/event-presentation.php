<?php
// Read-only presentation helpers. No session, database mutation or registration policy.
function uiImage(string $filename): string {
    $allowed = ['connect.svg','workshop.svg','acoustic.svg','career.svg','online.svg',
        'exhibition.svg','connect-room.svg','connect-lab.svg','acoustic-stage.svg',
        'acoustic-lounge.svg','fallback.svg'];
    return '/assets/images/' . (in_array($filename, $allowed, true) ? $filename : 'fallback.svg');
}
function uiContent(int $id): array {
    static $content;
    $content ??= require __DIR__ . '/../data/event-content.php';
    return $content[$id] ?? [
        'image'=>'fallback.svg','alt'=>'Minh họa sự kiện chưa có nội dung bổ sung',
        'summary'=>'Chưa có nội dung bổ sung cho sự kiện này.',
        'intro'=>'Thông tin nghiệp vụ vẫn lấy từ dữ liệu gốc của lab.',
        'start'=>'','end'=>'','timezone'=>'Asia/Ho_Chi_Minh (UTC+07:00)',
        'venue'=>'Chưa có thông tin bổ sung','format'=>'Chưa xác định',
        'organizer'=>'Chưa có thông tin bổ sung','audience'=>'Chưa có thông tin bổ sung',
        'prepare'=>'Chưa có thông tin bổ sung','agenda'=>[],'policy'=>[],'faq'=>[],'gallery'=>[]
    ];
}
function uiDate(string $value): string {
    if ($value === '') { return 'Chưa có thời gian bổ sung'; }
    return (new DateTimeImmutable($value))->format('H:i · d/m/Y');
}
function uiMoney(int $value): string {
    return $value === 0 ? 'Miễn phí' : number_format($value, 0, ',', '.') . ' đồng';
}
function uiSeats(): array {
    $unavailable = [
        'A01','A04','A08','A12','B02','B05','B09','B12',
        'C02','C06','C10','D01','D05','D09','E03','E07','E11',
        'F02','F06','F10','G01','G04','G08','G12','H02','H05','H09','H12'
    ];
    $seats = [];
    foreach (range('A', 'H') as $row) {
        for ($column = 1; $column <= 12; $column++) {
            $id = $row . str_pad((string)$column, 2, '0', STR_PAD_LEFT);
            $seats[] = ['id'=>$id, 'row'=>$row, 'column'=>$column,
                'tier'=>in_array($row, ['A','B'], true) ? 'vip' : 'standard',
                'available'=>!in_array($id, $unavailable, true)];
        }
    }
    return $seats;
}

function uiReadOnlyPage(): void {
    if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'HEAD'], true)) {
        header('Allow: GET, HEAD');
        http_response_code(405);
        exit('GET_REQUIRED');
    }
}
