# Lab01 — Bài thực hành dành cho sinh viên

Nhánh này chỉ chứa một bài lab. Dùng Ubuntu/WSL, PHP từ 8.1 và Git.

Không cần PostgreSQL hoặc Composer.

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
git clone --single-branch --branch lab1 https://github.com/FIT-DNU/Open-Source-Software-Development.git lab1
cd lab1/Ma_nguon
```

## Chạy bài

```bash
bash lab.sh check
bash lab.sh run
```

Mở http://127.0.0.1:8101. Giữ terminal mở; Ctrl+C để dừng.

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

Kết quả yêu cầu của phần mẫu: `CHECK_ENV=PASS`. `check` chỉ kiểm tra môi trường. `extend` ở bản khởi đầu còn ca chưa đạt vì TODO; không sửa test để bỏ qua lỗi.


Trang mẫu đã dùng cùng hệ màu Material 3; không có bước build hay thư viện mới. Bài shell và TODO giữ nguyên.
