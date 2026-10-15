# Lab05 — Bài thực hành dành cho sinh viên

Nhánh này chỉ chứa một bài lab. Dùng Ubuntu/WSL, PHP từ 8.1 và Git.

**Trước khi chạy:** làm mục Chuẩn bị riêng cho Lab 05 trong [HUONG_DAN.md](HUONG_DAN.md): cài PostgreSQL và PHP pgsql, tạo CSDL riêng, chạy bash lab.sh db-config rồi tạo schema.

## Chuẩn bị trên Ubuntu/WSL

```bash
sudo apt update
sudo apt install php-cli git unzip
php -v
git --version
```

PHP cần từ 8.1 trở lên. Các lệnh bên dưới chạy trong Ubuntu/WSL. Khi tải nhiều bài, trở về thư mục cha chứa các thư mục lab trước mỗi lệnh clone; không clone bài tiếp theo bên trong `Ma_nguon` của bài trước.

## Tải bài

```bash
git clone --single-branch --branch lab5 https://github.com/FIT-DNU/Open-Source-Software-Development.git lab5
cd lab5/Ma_nguon
```

## Cấu hình PostgreSQL trước lần chạy đầu

Tại `lab5/Ma_nguon`, cài và khởi động PostgreSQL:

```bash
sudo apt install postgresql postgresql-client php-pgsql
sudo service postgresql start
```

Làm mục **Chuẩn bị riêng cho Lab 05** trong [HUONG_DAN.md](HUONG_DAN.md) để tạo tài khoản và CSDL lab riêng, hoặc dùng thông tin giảng viên cấp. Không xóa CSDL đã tồn tại. Sau đó:

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh sql
```

Nhập thông tin kết nối theo lời nhắc. Trong cửa sổ PostgreSQL vừa mở:

```sql
\i database/schema.sql
SELECT id, title FROM events ORDER BY id;
\q
```

Cần có 3 sự kiện mẫu. Trở về Bash rồi làm mục Chạy bài bên dưới. Nếu khởi động lại WSL và PostgreSQL chưa chạy, dùng `sudo service postgresql start`.

## Chạy bài

```bash
bash lab.sh check
bash lab.sh run
```

Mở [danh sách sự kiện](http://127.0.0.1:8105/) hoặc [form đăng ký](http://127.0.0.1:8105/register.php). Giữ terminal mở; Ctrl+C để dừng.

## Kiểm tra

Mở terminal thứ hai tại Ma_nguon, hoặc dừng máy chủ trước:

```bash
bash lab.sh test
bash lab.sh extend
```

test kiểm tra phần mẫu; extend kiểm tra phần tự làm. Sinh viên cần hoàn thiện TODO; extend có thể chưa đạt ở bản khởi đầu.

[Hướng dẫn đầy đủ](HUONG_DAN.md) · [Hướng dẫn mã nguồn](Ma_nguon/README.md)

Không đưa .local, mật khẩu hoặc dữ liệu cá nhân thật vào bài nộp.

## Các bài khác

[Lab01](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab1) · [Lab02](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab2) · [Lab03](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab3) · [Lab04](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab4) · [Lab05](https://github.com/FIT-DNU/Open-Source-Software-Development/tree/lab5)

Kết quả yêu cầu của phần mẫu: `TESTS=6 FAILED=0 và TESTS=7 FAILED=0`. `check` chỉ kiểm tra môi trường. `extend` ở bản khởi đầu còn ca chưa đạt vì TODO; không sửa test để bỏ qua lỗi.

## Nhập mật khẩu trong db-config

Chạy `bash lab.sh db-config` trực tiếp trong terminal Ubuntu/WSL tương tác, tại `Ma_nguon`. Không dùng pipe hoặc chuyển hướng đầu vào cho lệnh này.

- Nhấn Enter để dùng máy chủ `127.0.0.1` và cổng `5432`, hoặc nhập giá trị được cấp.
- Tên CSDL và tài khoản phải khớp tài khoản đã tạo; mặc định là `communitypass_lab_v5`.
- Khi nhập mật khẩu, terminal **không hiện ký tự hoặc dấu sao**. Gõ mật khẩu rồi nhấn Enter; đây là hành vi bình thường.
- Kết nối thành công mới có `CAU_HINH=DA_LUU`. Sau đó chạy `bash lab.sh check`, tạo schema theo hướng dẫn rồi chạy Web.
- Sai mật khẩu: công cụ báo kết nối thất bại và không lưu cấu hình mới. Cấu hình đã lưu từ trước được giữ nguyên; chạy lại `db-config` để sửa thông tin.
- Nếu báo `Mo terminal tuong tac de nhap mat khau`, mở terminal tương tác thực sự rồi chạy lại. Không đưa mật khẩu vào câu lệnh, ảnh chụp, Git hoặc bài nộp.

Chỉ cần cấu hình lại khi thông tin kết nối thay đổi. Mỗi lần chạy `check` đạt không thay thế bước tạo schema ban đầu.
## Tự kiểm tra luồng trên trình duyệt

1. Mở `/register.php`, gửi form trống: thấy lỗi họ tên/email.
2. Chọn sự kiện, nhập tên và email thử nghiệm, nhấn **Tiếp tục**: chuyển sang trang xác nhận.
3. Chọn **Sửa lại**: sự kiện, tên và email của bản nháp vẫn còn. Thử sửa rồi tiếp tục.
4. Chọn **Hủy bản nháp**: quay về form trống. Nhập lại thông tin và xác nhận để kiểm tra luồng hoàn tất.
5. Tại form đăng ký, chọn **Đầy đủ**, nhấn **Lưu tùy chọn**: dòng `Tùy chọn Cookie` hiển thị `full`.
6. Dùng một phiên trình duyệt riêng để mở `/confirm.php`: phiên mới chưa có bản nháp nên được chuyển về form nhập. Không dùng hai tab cùng phiên để kiểm tra cách ly Session.

Khi so sánh Lab04 và Lab05 trên `127.0.0.1`, dùng hai phiên trình duyệt riêng: Cookie tùy chọn `view` dùng chung theo host, không tách theo cổng. Dữ liệu Session của từng lab dùng tên và thư mục riêng do `lab.sh` thiết lập.
Hoàn tất lần đầu cần thấy `CREATED`. Nhập lại cùng sự kiện và email (kể cả đổi chữ hoa/thường): cần thấy `DUPLICATE`. Đổi sang một sự kiện khác là một đăng ký khác.

Đóng máy chủ bằng Ctrl+C rồi chạy lại `bash lab.sh run`: dữ liệu trong CSDL vẫn còn. Thử cùng cặp sự kiện–email vẫn trả `DUPLICATE`.

Nếu mất kết nối khi xác nhận, ứng dụng trả lỗi 503 và thông báo giữ bản nháp. Khôi phục kết nối, chọn **Quay lại xác nhận** rồi thử lại; không coi lỗi kết nối là `DUPLICATE`. Danh sách sự kiện vẫn hiển thị số chỗ mẫu; không dùng số đó để kết luận số đăng ký thực trong CSDL. Thống kê mở rộng vẫn là TODO.


## Giao diện Material 3 và khu thử riêng

Sau `bash lab.sh run`, mở [sự kiện mẫu](http://127.0.0.1:8105/ui-demo.php) hoặc [thử vé/ghế](http://127.0.0.1:8105/ticket-preview.php?id=demo-open-source). Sáu sự kiện hư cấu và ảnh SVG cục bộ có giấy phép CC0. Không cần cài thêm thư viện hoặc build.

Chi tiết sự kiện lab tại `/event.php?id=1` chỉ đọc; nút mở biểu mẫu vẫn dùng chức năng gốc. Lựa chọn demo chỉ ở bộ nhớ trang, tải lại sẽ xóa; chưa giữ ghế, tạo vé hoặc thanh toán. ID demo không đi vào Form/CSDL. TODO và các ca `extend` sinh viên vẫn giữ nguyên.

Xem [hướng dẫn phần trình bày](Ma_nguon/README.md#giao-diện-material-3).

Kiểm tra dữ liệu UI riêng (không thay test gốc, không cần CSDL): tại `Ma_nguon`, chạy `php tests/ui-demo.php`.
