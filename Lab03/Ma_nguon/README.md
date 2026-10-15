# Lab 03 — Đề tài 10: Quản lý kho, điều chuyển hàng và kiểm kê tại nhiều điểm

Bài Lab 03 áp dụng cho đề tài 10. Dựa trên mã mẫu CommunityPass, đã đổi:

- `data/events.php`: 3 mặt hàng tồn tại 3 điểm kho (Hà Nội, Đà Nẵng, TP.HCM). `capacity` = tồn kho, `registered` = đã giữ cho phiếu điều chuyển, `category` = loại hàng (`dien-tu`, `noi-that`).
- `src/EventFilter.php`: lọc kết hợp từ khóa (tên hàng/tên kho) AND loại hàng AND còn hàng.
- `public/index.php`: trang tồn kho có ô từ khóa, chọn loại, ô "chỉ hàng còn có thể điều chuyển"; giữ lựa chọn sau khi gửi.
- `public/register.php`: form POST "Lập phiếu điều chuyển", kiểm tra ở máy chủ, chỉ xem trước (`PREVIEW_OK`).
- `tests/run.php`, `tests/extension.php`: giữ nguyên số ca (7 và 8), đổi từ khóa/loại theo dữ liệu mới.

| Bộ lọc | ID trả về |
| --- | --- |
| Không lọc | 1, 2, 3 |
| `laptop` | 1 |
| Loại `dien-tu` | 1, 3 |
| Chỉ còn hàng | 1, 3 |
| `hcm` + `dien-tu` + còn hàng | 3 |
| `noi-that` + còn hàng | rỗng |
| Loại không tồn tại | rỗng |

## Mở bài

Mở terminal Ubuntu/WSL tại thư mục chứa `lab.sh`. Dùng PHP từ 8.1 và Git. Hướng dẫn chi tiết, sơ đồ, đoạn mã cần sửa, dữ liệu đối chiếu và rubric nằm trong tài liệu của bài lab.

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8103. Giữ terminal máy chủ hoạt động; dùng terminal thứ hai để kiểm thử. Nhấn Ctrl+C để dừng.

Bài này không cần PostgreSQL, Composer hoặc framework.

## Kiểm tra bài

```bash
bash lab.sh test
bash lab.sh extend
```

`test` kiểm tra phần mẫu; `extend` kiểm tra phần tự làm. Các vị trí TODO là nhiệm vụ cần hoàn thiện; không sửa test để bỏ qua lỗi.

Kết quả mở rộng cần đạt: `TESTS=8 FAILED=0`. Có trang danh sách, bộ lọc theo từ khóa/loại/còn chỗ và Form đăng ký kiểm tra phía máy chủ. Dữ liệu đăng ký hợp lệ hiển thị ở mức xem trước, chưa lưu CSDL.

## Tệp nộp

Giữ mã nguồn, dữ liệu mẫu và kiểm thử. Ghi lại lệnh chạy trong README. Không đưa `.local`, mật khẩu hoặc dữ liệu cá nhân thật vào kho mã và gói nộp. Nộp đúng commit theo yêu cầu của bài; nếu dùng Actions, đính kèm lần chạy tương ứng.


## Phần trình bày giao diện

Lab này giữ CSS cục bộ tại `public/assets/css/app.css`. HTML nằm trong `public/`; các lab có nhiều trang dùng `templates/header.php` và `templates/footer.php`. Có thể lần từ các lớp `form-field`, `event-item`, `error-summary` sang CSS để đọc cách trình bày; Lab01 chỉ dùng phần trang giới thiệu.

Các lớp, nhãn và bố cục chỉ phục vụ hiển thị. Giữ nguyên khối PHP, GET/POST, tên trường, giá trị option, hidden input và thuộc tính kiểm tra đầu vào khi thực hành. Không cần JavaScript, thư viện hoặc bước build mới để chạy giao diện.


## Giao diện Material 3

Màu và kiểu chữ nằm trong `public/assets/css/tokens.css`; bố cục trong `app.css`. Không có bước build hoặc thư viện mới.

Ba lớp được tách rõ:

- `/`: sự kiện gốc của lab; tập kết quả, bộ lọc và form nghiệp vụ giữ nguyên.
- `/event.php?id=1`: chi tiết chỉ đọc, bổ sung metadata hư cấu từ `data/event-content.php`. CTA mở `/register.php` không kèm tham số mới.
- `/ui-demo.php`: sáu sự kiện hư cấu, ID `demo-...` từ `data/ui-demo-events.php`. `/ticket-preview.php?id=demo-open-source` chỉ thử vé/ghế trong bộ nhớ trang, không có form hoặc API ghi dữ liệu.

Khu thử có 96 ghế cố định, 68 khả dụng (16 VIP và 52 Tiêu chuẩn). Hai vé VIP mẫu 350.000 đồng cho tổng 700.000 đồng. Đổi hạng, đổi sự kiện hoặc tải lại sẽ bỏ lựa chọn không phù hợp; không có trạng thái đã giữ chỗ.

Ảnh SVG tự tạo, lưu cục bộ và ghi quyền CC0 trong `public/assets/images/LICENSE.txt`. `presentation.js` chỉ xử lý ảnh lỗi; `ticket-preview.js` chỉ chạy trên trang thử. Khi tắt JavaScript, vẫn đọc được nội dung và dùng form lab như cũ; khu chọn thử được khóa kèm giải thích.

Không đưa ID demo, vé hoặc ghế vào form, Session, Cookie, CSDL hoặc test cũ. TODO sinh viên và đáp án giữ nguyên khác biệt. Không có backend bán vé, thanh toán hoặc gửi email.
