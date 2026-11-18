<h2 align="center">
    <a href="https://dainam.edu.vn/vi/khoa-cong-nghe-thong-tin">
    🎓 Faculty of Information Technology (DaiNam University)
    </a>
</h2>
<h2 align="center">
    Open Source Software Development
</h2>
<div align="center">
    <p align="center">
        <img src="docs/logo/aiotlab_logo.png" alt="AIoTLab Logo" width="170"/>
        <img src="docs/logo/fitdnu_logo.png" alt="AIoTLab Logo" width="180"/>
        <img src="docs/logo/dnu_logo.png" alt="DaiNam University Logo" width="200"/>
    </p>

[![AIoTLab](https://img.shields.io/badge/AIoTLab-green?style=for-the-badge)](https://www.facebook.com/DNUAIoTLab)
[![Faculty of Information Technology](https://img.shields.io/badge/Faculty%20of%20Information%20Technology-blue?style=for-the-badge)](https://dainam.edu.vn/vi/khoa-cong-nghe-thong-tin)
[![DaiNam University](https://img.shields.io/badge/DaiNam%20University-orange?style=for-the-badge)](https://dainam.edu.vn)

</div>

## Nhóm 10 - CNTT 18-09: Xây dựng hệ thống quản lý kho, điều chuyển hàng và kiểm kê tại nhiều điểm

**OmniStock** là ứng dụng web quản lý nhiều kho hàng cùng lúc: nhập, xuất, điều chuyển giữa các kho, kiểm kê và báo cáo tồn kho theo lô / hạn sử dụng. Mọi thay đổi tồn kho đều đi qua **chứng từ** và được ghi vào **sổ phát sinh không sửa / xóa được**, nên tồn kho luôn đối chiếu được với lịch sử.

- **Bản demo:** <https://omnistock-bf5w.onrender.com> (chạy nhánh `main`). Lần mở đầu sau khi dịch vụ ngủ mất khoảng 1 phút. Dữ liệu demo quay về dữ liệu mẫu mỗi khi dịch vụ khởi động lại.
- **Tài khoản mẫu:** xem mục [Tài khoản mẫu](#tài-khoản-mẫu).

---

## Mục lục

1. [Tính năng](#tính-năng)
2. [Công nghệ](#công-nghệ)
3. [Cài đặt và chạy trên máy (XAMPP)](#cài-đặt-và-chạy-trên-máy-xampp)
4. [Tài khoản mẫu](#tài-khoản-mẫu)
5. [Cấu hình](#cấu-hình)
6. [Cấu trúc thư mục](#cấu-trúc-thư-mục)
7. [Quy tắc nghiệp vụ và dữ liệu](#quy-tắc-nghiệp-vụ-và-dữ-liệu)
8. [Kiểm thử tự động](#kiểm-thử-tự-động)
9. [Triển khai](#triển-khai)
10. [Quy ước làm việc nhóm](#quy-ước-làm-việc-nhóm)

---

## Tính năng

### Danh mục
- **Kho, mặt hàng, lô hàng** (số lô, ngày sản xuất, hạn sử dụng). Chống trùng mã / tên; kho, mặt hàng đã có phát sinh thì chuyển sang ngừng dùng (khôi phục lại được) thay vì xóa.
- **Nhập / xuất file Excel (CSV)** cho danh mục và phiếu, có file mẫu để tải về.

### Nghiệp vụ kho
| Chứng từ | Quy trình |
|---|---|
| **Phiếu nhập** | Lập → quản lý duyệt → **nhận hàng nhiều đợt** (hàng tốt cộng tồn, hàng hỏng ghi lý do) → đủ thì hoàn tất; nhà cung cấp giao thiếu thì quản lý đóng phiếu có lý do |
| **Phiếu xuất** | Lập → duyệt (**giữ chỗ** hàng) → **đã xuất kho** (trừ tồn) hoặc hủy giữ chỗ |
| **Điều chuyển** | Lập → duyệt (giữ chỗ ở kho đi) → **gửi hàng** (hàng chuyển sang "đang đi đường") → kho đến **nhận** (nhận thiếu thì chờ giao bổ sung hoặc ghi hao hụt) |
| **Yêu cầu điều chuyển** | Kho thiếu hàng gửi yêu cầu → quản lý kho có hàng đồng ý (hệ thống tự chọn lô hết hạn trước và lập phiếu điều chuyển) hoặc từ chối có lý do |
| **Phiếu điều chỉnh** | Ghi tăng / giảm tồn có lý do bắt buộc (sinh tự động khi chốt kiểm kê) |

### Kiểm kê nhiều người cùng lúc
- Quản lý mở **đợt kiểm kê** cho 1 kho: hệ thống chụp số tồn tại thời điểm mở; kho đang kiểm kê thì tạm khóa nhập / xuất / gửi hàng.
- Nhiều nhân viên cùng đếm: gõ vào dòng nào thì **giữ dòng đó** (người khác thấy tên và không nhập được), rời dòng hoặc Enter là **tự lưu**; người nhập vẫn sửa lại được dòng của mình cho tới khi chốt.
- Dòng lệch bắt buộc có lý do. Quản lý **chốt đợt** → tự sinh phiếu điều chỉnh cho phần chênh lệch; người đang đếm được báo ngay khi đợt bị chốt / hủy.
- Thanh tiến độ và bộ lọc "chỉ dòng chưa đếm".

### Báo cáo và theo dõi
- **Tổng quan:** số liệu nhanh, phiếu gần đây, lô sắp hết hạn, điều chuyển quá hạn, kết quả đối chiếu sổ.
- **Tồn kho hiện tại**, **Nhập – xuất – tồn** theo kỳ, **Thẻ kho / sổ phát sinh**, **Báo cáo kiểm kê**, **Đối chiếu sổ** (tồn = tổng phát sinh, hàng đang giữ / đang đi đường khớp phiếu).
- **Thông báo** ở chuông: phiếu chờ duyệt, yêu cầu điều chuyển, đợt kiểm kê đang mở, điều chuyển quá hạn, phiếu của mình vừa được duyệt / từ chối, lô sắp hết hạn.

### Phân quyền theo vai trò và theo kho
| Vai trò | Quyền |
|---|---|
| `ADMIN` – Quản trị hệ thống | Cấp tài khoản, đặt vai trò và phân quyền kho, tạo / ngừng dùng kho |
| `MANAGER` – Quản lý kho | Duyệt / từ chối chứng từ, mở và chốt kiểm kê, trả lời yêu cầu điều chuyển ở các kho được giao |
| `STAFF` – Nhân viên kho | Lập phiếu, nhận / xuất hàng, đếm kiểm kê ở kho được giao |

Mọi quyền đều được **kiểm tra lại ở server** (sửa HTML hay gửi request bằng Postman cũng không vượt quyền được).

### Khác
- **Trợ lý AI (beta):** hỏi đáp về tồn kho, chứng từ bằng tiếng Việt (Gemini miễn phí hoặc Claude).
- **Giao diện điện thoại:** menu dạng ngăn kéo, thanh tab dưới cùng kiểu app, bảng thành dạng thẻ, trợ lý AI là bong bóng kéo thả được.

---

## Công nghệ

| Thành phần | Sử dụng |
|---|---|
| Backend | PHP 8.2 thuần (không framework), PDO + prepared statements |
| CSDL | MySQL 8 / MariaDB 10.4+ (InnoDB, trigger, `CHECK`, khóa dòng `SELECT … FOR UPDATE`) |
| Frontend | HTML, CSS, JavaScript thuần; Font Awesome |
| Máy chủ | Apache (XAMPP khi chạy trên máy; Docker `php:8.2-apache` khi triển khai) |
| Triển khai | Render (Docker, gói miễn phí) |
| AI | Google Gemini API (mặc định) hoặc Anthropic Claude (`anthropic-ai/sdk` qua Composer) |

---

## Cài đặt và chạy trên máy (XAMPP)

**Yêu cầu:** [XAMPP](https://www.apachefriends.org) có PHP 8.2 trở lên (đã gồm Apache + MariaDB), Git.

1. **Lấy mã nguồn** vào thư mục `htdocs` của XAMPP:
   ```powershell
   cd D:\Program\XAMPP\htdocs
   git clone https://github.com/itzryuka/PTPMNNM-CNTT1809-NHOM10.git StoreManagement
   ```
2. **Bật Apache và MySQL** trong XAMPP Control Panel.
3. **Nạp CSDL mẫu** (tạo CSDL `quanlykhohang` gồm bảng, trigger và dữ liệu mẫu):
   - phpMyAdmin (<http://localhost/phpmyadmin>) → **Import** → chọn `database/quanlykhohang.sql` → **Go**; hoặc
   - dòng lệnh (chạy trong thư mục dự án):
     ```powershell
     cd D:\Program\XAMPP\htdocs\StoreManagement
     D:\Program\XAMPP\mysql\bin\mysql.exe -uroot --default-character-set=utf8mb4 -e "source database/quanlykhohang.sql"
     ```
   > File có lệnh `DROP DATABASE quanlykhohang`: nạp lại sẽ **xóa toàn bộ dữ liệu cũ** và về dữ liệu mẫu.
4. **Mở trình duyệt:** <http://localhost/StoreManagement> và đăng nhập bằng một [tài khoản mẫu](#tài-khoản-mẫu).
5. *(Tùy chọn)* Bật Trợ lý AI: xem [Cấu hình → Trợ lý AI](#trợ-lý-ai).

Mặc định ứng dụng kết nối `localhost:3306`, user `root`, không mật khẩu (đúng cấu hình gốc của XAMPP). Khác cấu hình này thì xem [Cấu hình → CSDL](#csdl).

---

## Tài khoản mẫu

Tất cả dùng mật khẩu **`password`**.

| Tên đăng nhập | Vai trò | Kho phụ trách |
|---|---|---|
| `admin` | Quản trị hệ thống | Kho HN, Kho HCM, Kho ĐN |
| `quanly` | Quản lý kho | Kho HN, Kho HCM, Kho ĐN |
| `nhanvien_a` | Nhân viên kho | Kho Hà Nội |
| `nhanvien_b` | Nhân viên kho | Kho TP.HCM |
| `nhanvien_c` | Nhân viên kho | Kho Đà Nẵng |

> Mật khẩu này công khai, **chỉ dùng cho dữ liệu mẫu và bản demo**. Không nhập dữ liệu thật lên bản demo.

---

## Cấu hình

### CSDL
Đọc trong `functions/db_connection.php`. Mặc định theo XAMPP; ghi đè bằng **biến môi trường**:

| Biến | Mặc định | Ý nghĩa |
|---|---|---|
| `DB_HOST` | `localhost` | Máy chủ MySQL / MariaDB |
| `DB_PORT` | `3306` | Cổng |
| `DB_NAME` | `quanlykhohang` | Tên CSDL |
| `DB_USER` | `root` | Tài khoản |
| `DB_PASS` | *(trống)* | Mật khẩu |
| `DB_SSL` | *(trống)* | `1` để bật SSL (CSDL trên cloud), hoặc đường dẫn file CA |

PHP và MySQL đều chạy múi giờ **Asia/Ho_Chi_Minh (+07:00)** để ngày lập phiếu và thời điểm ghi sổ không lệch nhau.

### Trợ lý AI
1. Sao chép `config/ai.example.php` thành **`config/ai.local.php`**.
2. Điền API key vào `ai.local.php`:
   - **Gemini (miễn phí):** lấy key tại <https://aistudio.google.com> → *Get API key*, điền `gemini_api_key`.
   - **Claude (trả phí):** đặt `provider` = `claude`, điền `api_key`, rồi cài SDK: `composer require anthropic-ai/sdk`.
3. Trên server có thể dùng biến môi trường `AI_PROVIDER`, `GEMINI_API_KEY` thay cho file.

> `config/ai.local.php` đã nằm trong `.gitignore`: **không bao giờ commit API key** lên git.

---

## Cấu trúc thư mục

```
StoreManagement/
├── index.php                 Trang tổng quan (dashboard)
├── views/                    Các trang giao diện (danh sách, form, chi tiết, báo cáo)
│   ├── layout/               header.php (menu, thanh trên, thông báo), footer.php (thanh tab điện thoại, script)
│   └── partials/             Popup thêm / sửa (kho, mặt hàng, tài khoản)
├── handle/                   Nơi xử lý form / AJAX (POST + CSRF), xong thì chuyển trang (PRG)
├── functions/                Logic nghiệp vụ và truy vấn CSDL, chia theo mảng
│   ├── db_connection.php     Kết nối PDO
│   ├── auth.php              Đăng nhập, phân quyền, CSRF, thông báo flash
│   ├── voucher_*.php         Chứng từ nhập / xuất / điều chuyển / điều chỉnh, ghi sổ
│   ├── stock_count_functions.php   Kiểm kê
│   ├── transfer_request_functions.php  Yêu cầu điều chuyển
│   ├── report_functions.php, dashboard_functions.php, notification_functions.php
│   └── chat_functions.php    Trợ lý AI
├── js/                       main.js (menu, thông báo), chat.js, modal.js, voucher_form.js, stock_count.js, ...
├── css/style.css             Toàn bộ giao diện (phần điện thoại nằm trong @media max-width: 768px)
├── database/
│   ├── schema.sql            Cấu trúc bảng, ràng buộc, trigger (nguồn gốc)
│   ├── build_database.php    Sinh dữ liệu mẫu bằng cách mô phỏng nghiệp vụ
│   └── quanlykhohang.sql     = schema.sql + dữ liệu mẫu (file dùng để nạp)
├── tests/                    Bộ kiểm thử tự động (xem tests/README.md)
├── config/                   ai.example.php (mẫu), ai.local.php (bí mật, không commit)
├── docker/, Dockerfile, render.yaml   Triển khai bằng Docker / Render
└── docs/                     Tài liệu (deploy-render.md), logo
```

**Sửa cấu trúc CSDL:** sửa `database/schema.sql` (và dữ liệu mẫu trong `build_database.php` nếu cần), rồi sinh lại file nạp:
```powershell
D:\Program\XAMPP\php\php.exe database\build_database.php
```
Sau đó nạp lại `database/quanlykhohang.sql` và chạy bộ test.

---

## Quy tắc nghiệp vụ và dữ liệu

- **Sổ phát sinh chỉ thêm (INSERT-only):** trigger chặn `UPDATE` / `DELETE` trên sổ. Sửa sai bằng **phiếu điều chỉnh** có lý do, không sửa lịch sử.
- **Tồn chỉ đổi khi hàng thật sự di chuyển** (nhận, xuất, gửi, nhận điều chuyển, điều chỉnh), mỗi lần là 1 đợt ghi sổ trong **1 transaction**: lỗi giữa chừng thì hoàn tác hết.
- **Không tồn âm, không giữ chỗ vượt tồn:** `CHECK` trong CSDL + khóa dòng tồn `SELECT … FOR UPDATE` khi duyệt / xuất.
- **Trạng thái chứng từ đi đúng một chiều:** trigger chặn chuyển trạng thái sai và sửa phiếu đã kết thúc.
- **Chống gửi trùng:** mỗi form lập phiếu có `client_token` riêng (UNIQUE), bấm Lưu 2 lần / F5 không tạo 2 phiếu.
- **Chống đụng độ khi nhiều người thao tác:** khóa theo kho khi mở đợt kiểm kê, giữ dòng có thời hạn khi nhập số đếm.
- **Bảo mật:** mật khẩu băm `password_hash`, CSRF token cho mọi POST, prepared statements, kiểm tra quyền kho ở server cho mọi thao tác, khóa tài khoản có hiệu lực ngay.

---

## Kiểm thử tự động

```powershell
D:\Program\XAMPP\php\php.exe tests\run_tests.php
```

- Bộ test tự dựng CSDL riêng `quanlykhohang_test` từ `database/quanlykhohang.sql`, chạy xong thì xóa: **không đụng tới dữ liệu đang dùng**.
- Kiểm tra các tình huống khó: hai người cùng duyệt khi kho không đủ hàng, lỗi giữa lúc trừ kho A và cộng kho B, gửi lại chứng từ đã xác nhận, vượt quyền kho, kiểm kê nhiều người, báo cáo khớp sổ...
- Kết quả hiện tại: **259 PASS, 0 FAIL**. Chạy lại sau mỗi lần sửa phần nghiệp vụ.

Chi tiết từng phép thử và đáp án: [tests/README.md](tests/README.md).

---

## Triển khai

- **Render (miễn phí):** repo đã có `Dockerfile` và `render.yaml`. Mỗi lần merge vào `main`, Render tự build và deploy lại. Hướng dẫn từng bước: [docs/deploy-render.md](docs/deploy-render.md).
- **Chạy bằng Docker trên máy:**
  ```powershell
  docker build -t omnistock .
  docker run --rm -p 8080:10000 -e GEMINI_API_KEY=<key-của-bạn> omnistock
  ```
  Mở <http://localhost:8080>. Không đặt `DB_HOST` thì container tự chạy MariaDB kèm dữ liệu mẫu (dữ liệu mất khi container dừng); đặt `DB_HOST`, `DB_USER`, ... để dùng CSDL ngoài.

---

## Quy ước làm việc nhóm

- **Nhánh:** làm trên nhánh tính năng (vd. `feature/quan-ly-kho`), mở **Pull Request vào `main`**; `main` là bản đang chạy trên Render.
- **Commit:** viết tiếng Việt, nói rõ thay đổi gì và vì sao (vd. `Kiểm kê: người nhập sửa lại được dòng của mình`).
- **Trước khi mở PR:** chạy `php -l` cho file PHP đã sửa và chạy bộ test (phải 0 FAIL).
- **Không commit:** `config/ai.local.php`, API key, mật khẩu thật, thư mục `vendor/`.
- **Đổi CSDL:** sửa `schema.sql`, sinh lại `quanlykhohang.sql` và commit cả hai.
