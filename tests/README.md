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

## Quy trình nghiệp vụ được kiểm thử

Duyệt phiếu **không** trừ / cộng tồn ngay. Tồn chỉ đổi khi hàng thật sự di chuyển, mỗi lần là 1 **đợt ghi sổ**:

| Phiếu | Duyệt | Các bước sau duyệt |
|---|---|---|
| Nhập | đồng ý nhập | **Nhận hàng** nhiều đợt (số tốt cộng tồn, số hỏng ghi biên bản NCC) → đủ thì hoàn tất; NCC giao thiếu thì quản lý **đóng phiếu** có lý do |
| Xuất | **giữ chỗ** hàng (có thể xuất = tồn − đang giữ) | **Đã xuất kho** (trừ tồn + trừ đang giữ) hoặc quản lý **hủy giữ chỗ** |
| Điều chuyển | **giữ chỗ** ở kho đi | **Gửi hàng** (kho đi −, "Hàng đang đi đường" +) → kho đến **nhận** (đang đi đường −, kho đến +) → nhận thiếu thì quản lý xử lý: chờ giao bổ sung, hoặc ghi hao hụt bằng phiếu điều chỉnh có lý do |
| Điều chỉnh | ghi sổ ngay | — |

## Dữ liệu và đáp án

Dữ liệu lấy từ `database/quanlykhohang.sql` (sinh bởi `database/build_database.php`).
Tài khoản: `quanly` (quản lý, 3 kho), `nhanvien_a` (Kho HN), `nhanvien_b` (Kho HCM), `nhanvien_c` (Kho ĐN).

| Phép thử | Dữ liệu | Đáp án (kết quả đúng) |
|---|---|---|
| **1.** Kho còn 5, hai yêu cầu cùng xuất 4 | Lô `L2610-TEST` tại Kho HN tồn 5; phiếu `PX-2026-0032`, `PX-2026-0033` mỗi phiếu xuất 4. Hai **tiến trình PHP riêng** (`race_worker.php`) bấm duyệt cùng một thời điểm. Lặp 3 vòng (2 vòng sau tự tạo lô mới tồn 5). Thêm: 2 người cùng bấm "Đã xuất kho" 1 phiếu. | Đúng 1 phiếu được duyệt (giữ 4), phiếu kia bị từ chối "Không đủ tồn có thể xuất"; tồn thực vẫn 5, chưa ghi sổ. Xuất kho phiếu đã duyệt: tồn còn 1, hết giữ chỗ, sổ thêm đúng 1 dòng. Bấm "Đã xuất kho" 2 lần cùng lúc: chỉ ghi 1 lần. |
| **2.** Lỗi giữa lúc trừ kho A và cộng kho B | Phiếu `PC-2026-0018` (HN → HCM 10). Bật **điểm gây lỗi giả lập** `posting.after_first_change` (hàm `failpoint()`): lỗi ngay sau khi đã trừ một đầu, trước khi cộng đầu kia, ở cả bước gửi và bước nhận. Phiếu `PC-TEST-LOI` có dòng 2 vượt tồn. | Lỗi giả lập: tồn kho đi, đang giữ, đang đi đường, sổ phát sinh, trạng thái **y nguyên**. Chạy lại bình thường: gửi = (−10 HN, +10 đang đi đường), nhận = (−10 đang đi đường, +10 HCM), mỗi bước 2 dòng trong 1 đợt ghi sổ; tổng hàng được bảo toàn. `PC-TEST-LOI`: không giữ chỗ dòng nào, vẫn `PENDING`. |
| **3.** Gửi lại chứng từ đã xác nhận | Duyệt / gửi / nhận lại `PC-2026-0018` đã hoàn tất; 2 tiến trình cùng duyệt lại; 2 tiến trình cùng bấm "Gửi hàng" 1 phiếu; gửi lại form nhận hàng cùng mã; gửi form lập phiếu 2 lần cùng `client_token`. | Đều bị từ chối; tồn và sổ không đổi; gửi hàng chỉ ghi 1 lần (2 dòng phát sinh); form nhận hàng lần 2 bị chặn "đã được ghi nhận"; form lập phiếu lần 2 trả về đúng phiếu cũ. |
| **4.** Tài khoản chỉ phụ trách kho A lập phiếu xuất kho B | `nhanvien_a` gửi phiếu xuất với `warehouse_id = 2` (Kho HCM), như sửa HTML / Postman. | Server từ chối "không có quyền", không có chứng từ nào được tạo. Nhân viên không duyệt được phiếu; quản lý kho A không duyệt được phiếu kho B; nhân viên kho HN không xuất kho / gửi hàng phiếu kho khác; kho đi không tự nhận hàng thay kho đến. |
| **5.** Điều chỉnh kiểm kê có lý do và chứng từ (chạy theo **tình huống 2**) | `nhanvien_a` điều chuyển 20 từ Kho HN sang Kho ĐN → duyệt → gửi → ĐN nhận đủ → `quanly` mở đợt kiểm kê Kho ĐN → `nhanvien_c` đếm: lô vừa nhận còn 18 (mất 2), một lô khác dư 1, còn lại khớp → `quanly` duyệt. | Chưa đếm hết / dòng lệch chưa có lý do / thiếu lý do chung: **không duyệt được**. Duyệt xong: 1 phiếu `ADJUSTMENT` đã ghi nhận, đúng 2 dòng (−2, +1) mỗi dòng kèm lý do; sổ phát sinh thêm đúng 2 dòng; tồn = số đếm; đợt `APPROVED` gắn với phiếu; **tồn khớp tổng sổ phát sinh**. Duyệt lại → từ chối; số đếm không sửa được nữa. |

Ngoài 5 phép thử bắt buộc, bộ test còn kiểm tra:
- **Nhập nhiều đợt:** duyệt không cộng tồn; hỏng phải có lý do; không nhận quá số đặt; kho khác không nhận được; đợt 1 nhận một phần, đợt 2 nhận đủ; đóng phiếu thiếu hàng (chỉ quản lý, bắt buộc lý do).
- **Xuất giữ chỗ:** phiếu sau bị chặn ngay lúc duyệt khi hết "có thể xuất"; form lập phiếu chỉ gợi ý số có thể xuất; CSDL chặn giữ chỗ vượt tồn (`CHECK reserved <= quantity`); hủy giữ chỗ trả hàng về.
- **Điều chuyển qua kho trung chuyển:** nhận thiếu → hàng vẫn ở "Đang đi đường"; chờ giao bổ sung; ghi hao hụt bằng phiếu điều chỉnh có lý do ở kho trung chuyển; cảnh báo quá hạn; CSDL chặn bước chuyển trạng thái sai và sửa phiếu đã kết thúc.
- **Yêu cầu điều chuyển:** chỉ lập cho kho mình; chỉ quản lý kho có hàng đồng ý; đồng ý → tự chọn lô hết hạn trước, lập phiếu và giữ hàng ngay; không đủ hàng thì báo thiếu bao nhiêu, không tạo gì; từ chối phải có lý do; bên yêu cầu rút lại được.
- **Báo cáo:** nhập – xuất – tồn tính từ sổ khớp tồn hiện tại; thẻ kho đúng số dư sau từng phát sinh; nhân viên chỉ thấy kho mình; trang đối chiếu đạt cả 4 phép kiểm tra.
- **Kiểm kê:** không mở 2 đợt cho 1 kho; nhân viên không mở / duyệt được đợt; kho đang kiểm kê thì không nhận / xuất / gửi / giữ hàng được, chốt đợt xong thì làm được; đợt không lệch thì không sinh phiếu; hủy đợt không ghi phát sinh.
- **Danh mục, nhập file CSV, chống trùng mã / tên:** như các phiên bản trước.
- **Sổ phát sinh:** không UPDATE / DELETE được (cả đợt ghi sổ); không có tồn âm; cuối cùng **tồn khớp sổ, hàng đang giữ và hàng đang đi đường khớp phiếu**.

## Kiểm tra lại sau khi sửa code

Chạy lại `php tests/run_tests.php` sau mỗi lần sửa phần nghiệp vụ: nếu chức năng cũ bị hỏng, test tương ứng sẽ FAIL.
Ví dụ: bỏ `FOR UPDATE` khi khóa dòng tồn trong `reserveVoucherLines()` → các test "chỉ 1 trong 2 phiếu được duyệt" của phép thử 1 FAIL.
