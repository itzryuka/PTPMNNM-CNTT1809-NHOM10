# Đưa OmniStock lên Render (miễn phí)

Render không có sẵn môi trường PHP nên chạy bằng **Docker**. Các file trong repo đã chuẩn bị sẵn:

| File | Vai trò |
|---|---|
| `Dockerfile` | Đóng gói PHP 8.2 + Apache (+ MariaDB cho chế độ gói gọn) |
| `docker/entrypoint.sh` | Khi khởi động: bật MariaDB, nạp dữ liệu mẫu, cho Apache nghe đúng cổng Render cấp |
| `docker/apache.conf`, `docker/mariadb.cnf` | Giới hạn bộ nhớ cho vừa gói miễn phí (512 MB RAM) |
| `render.yaml` | Blueprint: Render đọc file này để tự tạo dịch vụ |
| `.dockerignore`, `.gitattributes` | Không đưa `.git`, docs, API key vào image; giữ script ở dạng xuống dòng LF |

## Hai chế độ chạy

**1. Gói gọn (mặc định, miễn phí hoàn toàn):** không đặt biến `DB_HOST`. MariaDB chạy chung container, mỗi lần khởi động nạp lại `database/quanlykhohang.sql`.
- Ưu: không cần dịch vụ CSDL nào khác, đủ trigger, cài 1 lần là chạy.
- Nhược: **dữ liệu quay về dữ liệu mẫu** mỗi khi dịch vụ khởi động lại. Gói miễn phí tự tắt sau 15 phút không ai truy cập, nên phiếu, kiểm kê... tạo trong lúc demo sẽ mất sau đó. Phù hợp để demo, chấm bài.

**2. CSDL ngoài (dữ liệu giữ lâu dài):** đặt `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` và thường cả `DB_SSL=1` trỏ tới một MySQL / MariaDB trên cloud.
- CSDL đó **phải cho tạo TRIGGER** (schema dùng 15 trigger bảo vệ sổ kho). TiDB Cloud **không** hỗ trợ trigger nên không dùng được.
- Aiven đã ngừng gói MySQL miễn phí (từ 6/11/2026), nên chế độ này gần như phải trả phí (vd. Railway MySQL, Aiven Developer ~5 USD/tháng).
- Nạp schema: file `quanlykhohang.sql` có lệnh `DROP/CREATE DATABASE quanlykhohang`; nếu nhà cung cấp không cho tạo CSDL, xóa 3 lệnh đó và chạy phần còn lại trên CSDL họ cấp.

## Các bước (chế độ gói gọn)

1. **Đưa code lên GitHub**: commit các file trên và merge vào nhánh Render sẽ chạy (thường là `main`).
2. Vào <https://render.com>, **Sign up bằng GitHub**, cho Render quyền đọc repo `PTPMNNM-CNTT1809-NHOM10`.
3. Chọn **New → Blueprint**, chọn repo, nhánh `main`. Render đọc `render.yaml` và hiện dịch vụ `omnistock` (Docker, gói Free, Singapore).
   - Ô `GEMINI_API_KEY`: dán API key Gemini nếu muốn dùng Trợ lý AI (để trống thì bot báo chưa có key, phần còn lại vẫn chạy).
   - Bấm **Apply / Deploy Blueprint**.
   - Không dùng Blueprint cũng được: **New → Web Service** → chọn repo → Language **Docker** → Instance Type **Free** → thêm biến `GEMINI_API_KEY` ở mục Environment.
4. Chờ build lần đầu (khoảng 5–10 phút). Xem tab **Logs**, thấy các dòng `[omnistock] CSDL sẵn sàng.` và `Apache nghe cổng 10000` là xong.
5. Mở địa chỉ dạng `https://omnistock-xxxx.onrender.com` (có sẵn HTTPS), đăng nhập bằng tài khoản mẫu.
6. Sau này mỗi lần push lên `main`, Render tự build và deploy lại.

## Lưu ý

- **Lần mở đầu sau khi ngủ** mất khoảng 1 phút (Render bật lại container + nạp dữ liệu mẫu). Trước khi demo nên mở trang trước vài phút.
- Gói Free có 750 giờ chạy / tháng cho cả workspace: đủ cho 1 dịch vụ chạy liên tục.
- **Tài khoản mẫu có mật khẩu `password` công khai** và (chế độ gói gọn) sẽ quay về mật khẩu này sau mỗi lần khởi động lại. Không nhập dữ liệu thật lên bản demo.
- API key chỉ đặt trong mục Environment của Render, **không** ghi vào file trong git.
- Tên miền riêng: Settings → Custom Domains (cần tên miền của mình; tên miền miễn phí kiểu `.onrender.com` là đủ để nộp bài).
