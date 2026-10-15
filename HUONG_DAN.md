# LAB 02 — XÂY DỰNG MODULE PHP KIỂM TRA ĐIỀU KIỆN ĐĂNG KÝ

Học phần: Phát triển phần mềm mã nguồn mở. Đối tượng: sinh viên năm 3, chuyên ngành Phát triển phần mềm. Bài thực hành sử dụng mã nguồn CommunityPass được cấp trong thư mục Ma\_nguon.

## I. MỤC TIÊU VÀ CHUẨN BỊ

Nội dung bài giảng: Chương 3, slide 3–8: PHP, kiểu dữ liệu, biến, toán tử, điều kiện, hàm và đối tượng. Số trang tính cả trang bìa. Git và kiểm thử được sử dụng để hỗ trợ thực hành.

Sau khi hoàn thành bài thực hành, sinh viên có khả năng:

- Giải thích đầu vào, đầu ra và thứ tự kiểm tra của một quy tắc PHP.
- Viết hàm/lớp độc lập với HTML và CSDL; gọi được từ chương trình thử.
- Tự mở rộng quy tắc với ba kết quả và xử lý dữ liệu biên.
- Hoàn thiện lớp RegistrationPolicy và kiểm thử đầy đủ ba trạng thái đăng ký.

Chuẩn bị: đọc nội dung bài giảng được nêu trên, giải nén trọn thư mục của lab và mở Ma\_nguon trong trình soạn thảo. Bài đã có đủ mã và dữ liệu khởi đầu; chỉ sửa những tệp được chỉ rõ trong phần hướng dẫn và bài tự làm.

### Công cụ cần có

Sử dụng terminal Ubuntu hoặc Ubuntu trong WSL, PHP từ 8.1 và Git. Nếu máy đã có đủ công cụ, chỉ cần kiểm tra phiên bản. Trên máy được phép cài phần mềm, thực hiện:

```bash
sudo apt update
sudo apt install php-cli git unzip
php -v
git --version
```

Các lệnh Bash chạy trong terminal Ubuntu, không chạy trong PowerShell. Bài này không cần cơ sở dữ liệu hoặc framework.

### Bối cảnh và nội dung thực hành

Một sự kiện có sức chứa 10 người. Hệ thống cần xác định có thể tiếp nhận thêm đăng ký hay không dựa trên số người hiện tại. Bài thực hành xây dựng quy tắc này bằng hàm và lớp PHP; chưa sử dụng Form hoặc cơ sở dữ liệu.

Lớp CapacityPolicy nhận sức chứa và số người đã đăng ký, sau đó trả kết quả còn chỗ hoặc không thể đăng ký. Tách quy tắc khỏi giao diện giúp kiểm thử với nhiều bộ dữ liệu và sử dụng lại trong các trang của ứng dụng.

| **Đầu vào** | **Kết quả mẫu** |
| --- | --- |
| Sức chứa 10, hiện có 9 | true, chương trình minh họa in AVAILABLE. |
| Sức chứa 10, hiện có 10 | false, in FULL. |
| Sức chứa 0 hoặc số đã đăng ký âm | false, dữ liệu không hợp lệ. |

Trong mẫu đơn giản, FULL là nhãn chung cho kết quả không thể đăng ký; chưa phân biệt hết chỗ với dữ liệu sai. Phần tự mở rộng sẽ thiết kế kết quả rõ hơn.

## II. QUY TRÌNH THỰC HÀNH

![Sơ đồ hướng thực hiện Lab 02](Hinh_anh/hinh_01.png)

*Sơ đồ hướng thực hiện Lab 02*

Sơ đồ thể hiện thứ tự thực hiện và các bước kiểm tra. Tại nút quyết định hình thoi, đối chiếu kết quả với yêu cầu; nếu chưa đạt, quay lại bước xử lý tương ứng trước khi tiếp tục.

Mở thư mục Lab02/Ma\_nguon bằng trình soạn thảo và mở terminal Ubuntu tại thư mục này. Chạy pwd và ls để kiểm tra vị trí; danh sách tệp cần có lab.sh. Các đường dẫn trong bài được tính từ Ma\_nguon. Trên Windows, thực hiện lệnh Bash trong Ubuntu/WSL.

```bash
bash lab.sh check
bash lab.sh run
```

Kết quả chạy: chương trình demo.php in kết quả trong terminal rồi kết thúc; bài này không có trang Web.

Kiểm tra trước khi thực hành:

- [ ] Lệnh check xác nhận đủ công cụ để chạy bài thực hành.
- [ ] Chạy được chương trình mẫu và xác định được thư mục chứa mã nguồn.
- [ ] Phân biệt lệnh test dùng cho bài mẫu và lệnh extend dùng cho bài tập mở rộng.

## III. CẤU TRÚC MÃ NGUỒN

Thư mục Lab02/Ma\_nguon đã có mã mẫu cho phần hướng dẫn. Chạy mẫu trước để xem kết quả, sau đó đọc và thực hiện lại các bước ở phần IV trên chính các tệp đó. Khi bài yêu cầu thay toàn bộ nội dung, không chép nối thêm một hàm hoặc lớp trùng tên. Phần mở rộng để sinh viên tự hoàn thiện ở mục VI.

| **Phần thực hành** | **Tệp và công việc** | **Kết quả cần đạt** |
| --- | --- | --- |
| Mẫu có hướng dẫn | src/CapacityPolicy.php; demo.php<br>Đọc và viết lại lớp CapacityPolicy cùng chương trình gọi. Kiểm tra sức chứa, số người hiện tại và điều kiện còn chỗ. | bash lab.sh run: AVAILABLE với (10, 9). bash lab.sh test: TESTS=5 FAILED=0. |
| Bài tự mở rộng | src/RegistrationPolicy.php<br>Viết phương thức decide để trả confirmed, waiting hoặc rejected; xử lý dữ liệu sai, sự kiện đóng và danh sách chờ. | Tám ca đã cấp cùng ca bổ sung: TESTS=9 FAILED=0. Ca bổ sung xác nhận vẫn đăng ký được khi còn chỗ dù danh sách chờ tắt. Nếu thêm ca khác, tổng TESTS tăng tương ứng; FAILED phải bằng 0. |

Vai trò từng tệp trong bài mẫu:

| **Tệp** | **Vai trò** |
| --- | --- |
| src/CapacityPolicy.php | Mã mẫu xét điều kiện còn chỗ; đọc và viết lại theo bước 3. |
| demo.php | Tạo đối tượng, truyền đầu vào và in kết quả. Đọc và viết lại theo bước 4. |
| tests/run.php | Bộ dữ liệu thử đã cấp để kiểm tra hành vi của lớp. |
| tests/helper.php | Hàm so sánh kết quả mong đợi và kết quả thật; không cần sửa. |

Luồng: demo.php nạp lớp → tạo đối tượng → gọi phương thức với hai số nguyên → nhận Boolean → quyết định dòng chữ cần in. Không có database hoặc Session trong luồng này.

## IV. HƯỚNG DẪN THỰC HÀNH

### Bước 1. Viết quy tắc bằng lời trước khi viết mã

Trong ghi chú của bài, ghi: sức chứa phải lớn hơn 0; số đã đăng ký không âm; chỉ còn chỗ khi số đã đăng ký nhỏ hơn sức chứa. Với mẫu này, nếu số đã đăng ký lớn hơn sức chứa thì cũng không cho đăng ký tiếp.

Câu “còn chỗ” được biểu diễn bằng toán tử &lt;, không phải &lt;=. Đây là quyết định nghiệp vụ, không phải quy ước đặt tên.

Kiểm tra kết quả bước 1:

- [ ] Viết được điều kiện còn chỗ và quy tắc từ chối đầu vào không hợp lệ.

### Bước 2. Thử quy tắc dưới dạng một hàm

Mở tệp thu-ham.php tại thư mục gốc bài làm với nội dung:

```php
<?php
function canRegister(int $capacity, int $registered): bool
{
    if ($capacity <= 0 || $registered < 0) {
        return false;
    }
    return $registered < $capacity;
}
 
var_dump(canRegister(10, 9));
var_dump(canRegister(10, 10));
```

Chạy php thu-ham.php. Kết quả gồm bool(true) và bool(false).

int chỉ tham số số nguyên; bool chỉ kết quả đúng/sai; \|\| nghĩa là hoặc. return kết thúc hàm và trả giá trị cho nơi gọi. Hai biến tham số chỉ có ý nghĩa bên trong lần gọi hàm, không đọc dữ liệu từ giao diện.

Kiểm tra kết quả bước 2:

- [ ] Hàm thử trả true khi 10/9, false khi 10/10 và khi dữ liệu sai.

### Bước 3. Đưa cùng quy tắc vào một lớp

Mở src/CapacityPolicy.php và nhập toàn bộ nội dung sau:

```php
<?php
declare(strict_types=1);
 
final class CapacityPolicy
{
    public function canRegister(int $capacity, int $registered): bool
    {
        if ($capacity <= 0 || $registered < 0) {
            return false;
        }
        return $registered < $capacity;
    }
}
```

Lớp là nơi nhóm các phương thức liên quan; trong bài này chỉ cần một phương thức canRegister. public cho phép gọi phương thức từ nơi khác. final không cho lớp khác kế thừa lớp này; bài không yêu cầu thiết kế kế thừa. declare(strict\_types=1) bật kiểm tra kiểu nghiêm ngặt cho các lời gọi có kiểu trong tệp; dữ liệu form dạng chuỗi khi nhận dữ liệu Form vẫn phải được kiểm tra và chuyển đổi trước khi sử dụng.

Kiểm tra kết quả bước 3:

- [ ] Lớp CapacityPolicy chỉ xử lý quy tắc, chưa cần HTML hoặc database.

### Bước 4. Tạo chương trình sử dụng lớp

Mở demo.php ở gốc bài làm:

```php
<?php
require __DIR__ . '/src/CapacityPolicy.php';
 
$policy = new CapacityPolicy();
$available = $policy->canRegister(10, 9);
if ($available) {
    echo "AVAILABLE\n";
} else {
    echo "FULL\n";
}
```

require nạp tệp định nghĩa lớp; \_\_DIR\_\_ là thư mục của chính tệp hiện tại; new tạo đối tượng. Dấu -&gt; gọi phương thức của đối tượng. Biến $available nhận kết quả Boolean, còn việc in chữ nằm ở demo.php, không nằm trong lớp nghiệp vụ.

Đây là ví dụ sử dụng require tối thiểu để tách tệp. Cách dùng nhiều tệp và tổ chức giao diện được học đầy đủ trong phần xử lý Form, không yêu cầu tự viết bộ tự nạp lớp lúc này.

Kiểm tra kết quả bước 4:

- [ ] demo.php nạp đúng tệp và gọi canRegister của đối tượng đã tạo.

### Bước 5. Chạy và thay đầu vào để thấy tác dụng

```bash
php -l src/CapacityPolicy.php
php demo.php
```

Lần đầu có AVAILABLE. Sửa duy nhất lời gọi thành canRegister(10, 10), lưu demo.php, chạy lại: có FULL. Sửa về 10,9 trước khi đối chiếu với bản mẫu.

Thử các cặp (10, 11), (0, 0), (10, -1); cả ba đều không được đăng ký. Lập bảng ghi dữ liệu đầu vào, kết quả yêu cầu và kết quả thực tế.

Kiểm tra kết quả bước 5:

- [ ] Khi thay dữ liệu đầu vào, chương trình trả kết quả phù hợp với quy tắc đã xác định.

### Bước 6. Dùng bộ kiểm thử đã cấp

```bash
php tests/run.php
```

Mở tests/run.php và đọc các lời gọi same(...). Hàm so sánh kết quả của chương trình với giá trị yêu cầu; nhãn của ca kiểm thử giúp xác định trường hợp sai. Giữ nguyên các giá trị yêu cầu khi sửa chương trình.

Thử tạm đổi &lt; thành &lt;= trong lớp rồi chạy test: ca sức chứa bằng số đã đăng ký phải bị phát hiện. Khôi phục &lt; và chạy lại. Đây là kiểm thử hồi quy: giúp lần sửa sau không làm hỏng hành vi cũ.

Kiểm tra kết quả bước 6:

- [ ] Năm ca cơ bản đạt; giải thích được ca đầy chỗ và ca sức chứa sai.

Kết quả sau phần hướng dẫn: bash lab.sh run: AVAILABLE với (10, 9). bash lab.sh test: TESTS=5 FAILED=0.

## V. LỖI THƯỜNG GẶP VÀ CÁCH KHẮC PHỤC

| **Hiện tượng** | **Nguyên nhân thường gặp và cách kiểm tra** |
| --- | --- |
| Class ... not found | Kiểm tra tên lớp, tên tệp và đường dẫn require. |
| Failed opening required | CapacityPolicy.php có ở đúng src không? Kiểm tra tên chữ hoa/chữ thường trên Linux. |
| Parse error | Kiểm tra dấu ;, {}, tên phương thức; chạy php -l trước. |
| Đủ chỗ vẫn được đăng ký | Bạn có viết &lt;= thay vì &lt; không? Chạy ca 10,10. |
| Chương trình in chữ nhưng test sai | Không viết echo thay cho return trong phương thức; test cần giá trị Boolean. |

## VI. BÀI TẬP MỞ RỘNG: BA TRẠNG THÁI ĐĂNG KÝ

Yêu cầu mới: sự kiện có thể đang đóng và có thể bật danh sách chờ. Mỗi yêu cầu đăng ký cần nhận một trong ba kết quả: confirmed (xác nhận), waiting (chờ), rejected (từ chối).

```bash
# Vẫn đứng trong Ma_nguon của bài này.
bash lab.sh extend
```

Mở src/RegistrationPolicy.php, giữ tên và tham số của phương thức đã cấp; tự viết phần thân decide.

| **Điều kiện** | **Kết quả yêu cầu** |
| --- | --- |
| Sức chứa ≤ 0, số đã đăng ký &lt; 0 hoặc &gt; sức chứa | rejected. |
| Dữ liệu hợp lệ nhưng sự kiện đóng | rejected, kể cả vẫn còn chỗ. |
| Sự kiện mở và còn chỗ | confirmed. |
| Sự kiện mở, hết chỗ, có danh sách chờ | waiting. |
| Sự kiện mở, hết chỗ, không có danh sách chờ | rejected. |

Kiểm tra tính hợp lệ của dữ liệu và trạng thái đóng của sự kiện trước khi xét số chỗ. Chạy bash lab.sh extend, bổ sung ít nhất một ca kiểm thử và giải thích mục đích của ca đó. Phần nâng cao: bổ sung mã lý do cho kết quả nhưng giữ nguyên giao diện của phương thức bắt buộc.

## KẾT QUẢ CỦA BÀI THỰC HÀNH

Có lớp kiểm tra sức chứa, chương trình gọi lớp và lớp mở rộng trả confirmed, waiting hoặc rejected theo quy tắc đã công bố.

## VII. BẢNG KIỂM HOÀN THÀNH

- [ ] Giải thích được đầu vào và giá trị trả về của canRegister().
- [ ] demo.php gọi đúng lớp và in kết quả theo dữ liệu thử.
- [ ] decide() trả đúng ba trạng thái và thứ tự ưu tiên.
- [ ] Các ca đúng, sai và biên có kết quả đối chiếu.
- [ ] test và extend đạt; không sửa đáp án trong bộ kiểm thử.

Kết quả tổng hợp của phần tự làm phải có TESTS=9 FAILED=0. Ngoài các phép thử tự động, thực hiện các tình huống kiểm tra trực tiếp nêu trong bài. Lệnh check chỉ xác nhận môi trường, không thay cho test và extend.

## VIII. RUBRIC LAB — 10 ĐIỂM

| **Tiêu chí** | **Điểm** | **Cách chấm** |
| --- | --- | --- |
| Phần mẫu có hướng dẫn | 2,0 | 1 điểm: thực hiện và có kết quả đúng; 1 điểm: giải thích luồng mã. Mỗi phần đạt một phần: 0,5; chưa đạt: 0. |
| Dữ liệu và trạng thái đóng | 1,5 | Từ chối sức chứa không hợp lệ, số đăng ký âm/vượt sức chứa và sự kiện đã đóng. |
| Xác nhận và danh sách chờ | 1,5 | Trả confirmed khi còn chỗ; waiting chỉ khi đầy và cho chờ; các trường hợp còn lại rejected. |
| Tổ chức module | 1,0 | Lớp trả dữ liệu đúng kiểu; không in HTML, không phụ thuộc Session hoặc CSDL. |
| Kiểm thử và xử lý lỗi | 2,0 | 1 điểm: có dữ liệu và kết quả mong đợi cho tình huống đúng, sai, biên; 1 điểm: chạy lại phần mẫu và mở rộng sau sửa. Mỗi phần đạt một phần: 0,5; chưa đạt: 0. |
| Mã nguồn và bàn giao | 1,0 | 0,5: nộp đúng tệp, dữ liệu và phiên bản; 0,5: README có lệnh chạy/kiểm thử, không chứa cấu hình riêng. Đạt một phần mỗi mục: 0,25. |
| Giải thích cá nhân | 1,0 | 0,5: giải thích phần tự viết; 0,5: giải thích một ca lỗi hoặc thực hiện một thay đổi nhỏ. Đạt một phần mỗi mục: 0,25. |
| Tổng | 10,0 | Chấm trực tiếp sản phẩm và phần giải thích của bài lab. |

Với tiêu chí 1,5 điểm: đủ yêu cầu được 1,5; thực hiện một phần được 0,75; chưa thực hiện được 0. Với tiêu chí 1 điểm không chia tiểu mục: đủ được 1; một phần được 0,5; chưa thực hiện được 0. Bài tự làm không đạt điểm chỉ nhờ thay bộ kiểm thử hoặc ghi sẵn kết quả.

## IX. NỘP BÀI

Bước 1. Lưu mã bài mẫu và bài tự làm tại Lab02/Ma\_nguon, kèm dữ liệu, kiểm thử và README. Loại bỏ .local, mật khẩu và dữ liệu cá nhân thật khỏi bài nộp.

Bước 2. Chạy bash lab.sh check, bash lab.sh test và bash lab.sh extend. Thực hiện các phép kiểm tra trực tiếp trong bài và sửa lỗi trước khi nộp.

Bước 3. Commit, đẩy mã lên GitHub và nộp tên nhóm/thành viên, Lab 02, liên kết Ma\_nguon tại đúng commit. Kiểm tra quyền đọc; kèm lần chạy Actions khi được yêu cầu. Hạn và địa chỉ nộp do giảng viên công bố. Nếu nhận ZIP, giữ nguyên cấu trúc trên.
