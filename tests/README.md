# Kiểm thử tự động

## Chạy

```bash
php tests/run_tests.php
```

- Với XAMPP trên Windows: `D:\Program\XAMPP\php\php.exe tests\run_tests.php` (chạy từ thư mục gốc project).
- Bộ test **không đụng vào CSDL `quanlykhohang`**. Nó dựng CSDL riêng `quanlykhohang_test` từ `database/quanlykhohang.sql`, chạy test, rồi xóa CSDL đó. Dữ liệu đang dùng giữ nguyên kể cả khi test lỗi giữa chừng.
- `--keep`: giữ lại `quanlykhohang_test` để mở bằng phpMyAdmin xem dữ liệu sau test.
- `--mysql="đường/dẫn/mysql.exe"`: chỉ đường dẫn mysql client nếu không tự tìm được (mặc định tìm `../mysql/bin/` cạnh `php.exe` của XAMPP, rồi tới `mysql` trong PATH).
- Mã thoát: `0` tất cả PASS, `1` có FAIL, `2` không dựng được CSDL test.

## Dữ liệu và đáp án

Dữ liệu lấy từ `database/quanlykhohang.sql` (sinh bởi `database/build_database.php`).
Tài khoản: `quanly` (quản lý, 3 kho), `nhanvien_a` (nhân viên, chỉ Kho HN).

| Phép thử | Dữ liệu | Đáp án (kết quả đúng) |
|---|---|---|
| **1.** Kho còn 5, hai yêu cầu cùng xuất 4 | Lô `L2610-TEST` tại Kho HN tồn 5; phiếu `PX-2026-0032`, `PX-2026-0033` mỗi phiếu xuất 4. Hai **tiến trình PHP riêng** (`race_worker.php`) bấm duyệt cùng một thời điểm. Lặp 3 vòng (2 vòng sau tự tạo lô mới tồn 5). | Đúng 1 phiếu `APPROVED`, phiếu kia bị từ chối "Không đủ tồn"; tồn còn 1; sổ phát sinh thêm đúng 1 dòng. |
| **2.** Lỗi giữa lúc trừ kho A và cộng kho B | Phiếu `PC-2026-0018` (HN → HCM 10). Phiếu lỗi `PC-TEST-LOI`: dòng 1 hợp lệ (kho HN bị trừ trước), dòng 2 vượt tồn → lỗi **trước khi** cộng kho ĐN. | Phiếu hợp lệ: HN −10, HCM +10, 2 dòng phát sinh (−/+) cùng chứng từ. Phiếu lỗi: tồn cả 2 kho và sổ phát sinh y nguyên, phiếu vẫn `PENDING`. |
| **3.** Gửi lại chứng từ đã xác nhận | Duyệt lại `PC-2026-0018`; 2 tiến trình cùng duyệt lại; gửi form lập phiếu 2 lần cùng `client_token`. | Đều bị từ chối "đã được xử lý"; tồn và sổ không đổi; gửi form lần 2 trả về đúng phiếu cũ. |
| **4.** Tài khoản chỉ phụ trách kho A lập phiếu xuất kho B | `nhanvien_a` gửi phiếu xuất với `warehouse_id = 2` (Kho HCM), như sửa HTML / Postman. | Server từ chối "không có quyền", không có chứng từ nào được tạo. Nhân viên không duyệt được phiếu; quản lý kho A không duyệt được phiếu kho B. |
| **5.** Điều chỉnh kiểm kê có lý do và chứng từ (chạy theo **tình huống 2**) | `nhanvien_a` điều chuyển 20 từ Kho HN sang Kho ĐN → `quanly` duyệt → `quanly` mở đợt kiểm kê Kho ĐN → `nhanvien_c` đếm: lô vừa nhận còn 18 (mất 2), một lô khác dư 1, còn lại khớp → `quanly` duyệt. | Chưa đếm hết / dòng lệch chưa có lý do / thiếu lý do chung: **không duyệt được**. Duyệt xong: 1 phiếu `ADJUSTMENT` đã ghi nhận, lý do "Kiểm kê KK-…: …", đúng 2 dòng (−2, +1) mỗi dòng kèm lý do; sổ phát sinh thêm đúng 2 dòng; tồn = số đếm; đợt `APPROVED` gắn với phiếu; **tồn khớp tổng sổ phát sinh**. Duyệt lại → từ chối; số đếm không sửa được nữa. |

Ngoài 5 phép thử bắt buộc, bộ test còn kiểm tra:
- **Kiểm kê:** không mở 2 đợt cho 1 kho; nhân viên không mở / duyệt được đợt; nhân viên kho khác không nhập được số đếm; kho đang kiểm kê thì phiếu nhập / xuất / điều chuyển không duyệt được, chốt đợt xong thì duyệt được; đợt không lệch thì không sinh phiếu; hủy đợt không ghi phát sinh.
- **Điều chuyển:** kho đến trùng kho đi, điều chuyển từ kho không phụ trách, vượt tồn kho đi đều bị chặn; nhân viên kho đến thấy phiếu chuyển tới.
- **Nhập / xuất:** nhập lô mới, từ chối phiếu, chặn xuất vượt tồn / lô hết hạn / dòng trùng.
- **Danh mục kho, mặt hàng:** nhân viên không thêm / sửa được; trùng mã, mã sai định dạng bị chặn; chưa có lịch sử thì xóa thật, đã có lịch sử thì chuyển "ngừng dùng" và không lập phiếu được nữa; còn tồn / còn phiếu chờ duyệt thì không xóa; kho mới tự phân quyền cho người tạo và ADMIN.
- **Nhập file CSV:** đọc file Excel lưu ra (BOM, dấu chấm phẩy, tiêu đề tiếng Việt), báo thiếu cột / sai mã hóa; file có 1 dòng lỗi thì báo đúng số dòng và **không ghi dòng nào**.
- **Sổ phát sinh:** không UPDATE / DELETE được; không có tồn âm; cuối cùng **tồn khớp tổng sổ phát sinh ở mọi kho / lô**.

## Kiểm tra lại sau khi sửa code

Chạy lại `php tests/run_tests.php` sau mỗi lần sửa phần nghiệp vụ: nếu chức năng cũ bị hỏng, test tương ứng sẽ FAIL.
Ví dụ: bỏ `FOR UPDATE` khi khóa dòng tồn trong `applyStockChange()` → 3 test của phép thử 1 FAIL.
