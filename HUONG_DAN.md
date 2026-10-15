# LAB 05 — LƯU, TRUY VẤN VÀ THỐNG KÊ ĐĂNG KÝ VỚI POSTGRESQL

Học phần: Phát triển phần mềm mã nguồn mở. Đối tượng: sinh viên năm 3, chuyên ngành Phát triển phần mềm. Bài thực hành sử dụng mã nguồn CommunityPass được cấp trong thư mục Ma\_nguon.

## I. MỤC TIÊU VÀ CHUẨN BỊ

Nội dung bài giảng: Chương 4, slide 5–10: PostgreSQL và kết nối PHP; 12–17: MySQLi và truy vấn tham số để đối chiếu. Số trang tính cả trang bìa. Git và kiểm thử được sử dụng để hỗ trợ thực hành.

Sau khi hoàn thành bài thực hành, sinh viên có khả năng:

- Tạo/đọc bảng quan hệ và giải thích khóa, ràng buộc duy nhất.
- Lưu/đọc đăng ký bằng PHP và truy vấn tham số hóa.
- Phân biệt dữ liệu trùng với lỗi CSDL; kiểm chứng lưu bền vững.
- Viết truy vấn thống kê đúng, kiểm tra ràng buộc dữ liệu và hoàn thiện luồng lưu/đọc đăng ký.

Chuẩn bị: đọc nội dung bài giảng được nêu trên, giải nén trọn thư mục của lab và mở Ma\_nguon trong trình soạn thảo. Bài đã có đủ mã và dữ liệu khởi đầu; chỉ sửa những tệp được chỉ rõ trong phần hướng dẫn và bài tự làm.

### Công cụ cần có

Cần Ubuntu/WSL, PHP từ 8.1, Git, PostgreSQL, psql và phần mở rộng PHP pgsql. Toàn bộ thao tác cài đặt, tạo tài khoản, cấu hình và thử kết nối nằm trong mục “Chuẩn bị riêng cho Lab 05” của chính bài này.

### Bối cảnh và nội dung thực hành

Thông tin trong Session là bản nháp. Trong bài này, nút xác nhận gọi PostgreSQL để lưu một hàng trong bảng registrations và trả CREATED hoặc DUPLICATE. Dữ liệu được kiểm tra lại sau khi khởi động lại ứng dụng.

Bài mẫu tập trung lưu dữ liệu và chống trùng theo cặp sự kiện–email. Chưa giải quyết giới hạn suất cuối khi nhiều người đăng ký đồng thời. Trang danh sách ban đầu vẫn dùng dữ liệu giả lập; không được dùng số chỗ trên trang đó để khẳng định số chỗ thực tế trong CSDL.

Kết quả cần quan sát: xác nhận lần đầu có CREATED; đăng ký lại cùng email và sự kiện có DUPLICATE; truy vấn SQL chỉ có một hàng. Đóng rồi mở máy chủ PHP không làm mất hàng đã lưu.

## II. QUY TRÌNH THỰC HÀNH

![Sơ đồ hướng thực hiện Lab 05](Hinh_anh/hinh_01.png)

*Sơ đồ hướng thực hiện Lab 05*

Sơ đồ thể hiện thứ tự thực hiện và các bước kiểm tra. Tại nút quyết định hình thoi, đối chiếu kết quả với yêu cầu; nếu chưa đạt, quay lại bước xử lý tương ứng trước khi tiếp tục.

Mở thư mục Lab05/Ma\_nguon bằng trình soạn thảo và mở terminal Ubuntu tại thư mục này. Chạy pwd và ls để kiểm tra vị trí; danh sách tệp cần có lab.sh. Các đường dẫn trong bài được tính từ Ma\_nguon. Trên Windows, thực hiện lệnh Bash trong Ubuntu/WSL.

Riêng bài này: hoàn thành mục “Chuẩn bị riêng cho Lab 05” bên dưới trước. Sau khi check đạt và schema được tạo ở bước 1, mới chạy Web ở bước 6.

Địa chỉ dùng ở bước 6: mở http://127.0.0.1:8105. Giữ terminal máy chủ mở, dùng terminal thứ hai tại cùng thư mục để sửa/chạy test. Dừng bằng Ctrl+C; không mở hai máy chủ cùng cổng.

### Chuẩn bị riêng cho Lab 05

### A. Cài công cụ trên Ubuntu/WSL trước bài thực hành

Thực hiện trên máy cá nhân có quyền quản trị hoặc máy thực hành được cấp. PostgreSQL chỉ cần cho bài này.

```bash
sudo apt update
sudo apt install php-cli git unzip postgresql postgresql-client php-pgsql
sudo service postgresql start
psql --version
php -m | grep pgsql
```

Kết quả cần có: psql hiển thị phiên bản; danh sách phần mở rộng có pgsql. Nếu máy có nhiều phiên bản PHP, cài phần mở rộng khớp php -v. Không sửa mã ứng dụng để che lỗi thiếu công cụ.

### B. Tạo tài khoản và CSDL chỉ dùng cho bài

Mở psql bằng tài khoản quản trị cục bộ. Các lệnh CREATE dưới đây chỉ chạy một lần khi tên chưa tồn tại; nếu lớp đã cấp CSDL thì sử dụng thông tin được cấp, không tạo lại.

```bash
sudo -u postgres psql
```

```sql
SELECT rolname FROM pg_roles WHERE rolname = 'communitypass_lab_v5';
SELECT datname FROM pg_database WHERE datname = 'communitypass_lab_v5';
```

Nếu hai truy vấn đều không có hàng, gõ lần lượt trong cửa sổ psql:

```sql
CREATE ROLE communitypass_lab_v5 LOGIN;
\password communitypass_lab_v5
```

Nhập mật khẩu riêng hai lần tại lời nhắc; không đưa mật khẩu vào tài liệu, dòng lệnh công khai hoặc kho mã.

```sql
CREATE DATABASE communitypass_lab_v5 OWNER communitypass_lab_v5;
\q
```

Nếu tên đã có, hỏi giảng viên trước khi sử dụng hoặc tạo tên riêng cho nhóm và nhập đúng tên ở bước cấu hình. Không xóa CSDL đang có để làm lại bài.

### C. Lưu cấu hình và kiểm tra kết nối

Trở về terminal tại Lab05/Ma\_nguon. Chạy các lệnh bên dưới. Khi db-config hỏi, dùng tên CSDL, tài khoản và mật khẩu vừa tạo; mặc định máy chủ 127.0.0.1, cổng 5432. Công cụ chỉ lưu cấu hình sau khi kết nối thành công.

```bash
bash lab.sh db-config
bash lab.sh check
bash lab.sh sql
```

```sql
SELECT current_database(), current_user;
\q
```

Kiểm tra tên cơ sở dữ liệu và tài khoản theo cấu hình được giao. Tệp .local/db.json chứa mật khẩu và chỉ lưu trên máy thực hành; không đưa .local vào Git hoặc bài nộp. Nếu thiếu pgsql hoặc chưa kết nối được, dừng và hoàn thiện môi trường trước khi chạy tiếp. Mỗi máy hoặc nhóm sử dụng cơ sở dữ liệu riêng.

Kiểm tra trước khi thực hành:

- [ ] Lệnh check xác nhận đủ công cụ để chạy bài thực hành.
- [ ] Chạy được chương trình mẫu và xác định được thư mục chứa mã nguồn.
- [ ] Phân biệt lệnh test dùng cho bài mẫu và lệnh extend dùng cho bài tập mở rộng.

Nếu chưa có dịch vụ hoặc chưa kết nối: kiểm tra sudo service postgresql status, cổng ở pg\_lsclusters và tên/mật khẩu đã nhập. Đến khi check đạt mới chạy phần tạo bảng. Các lệnh psql được tham khảo từ PostgreSQL Manual: https://www.postgresql.org/docs/current/app-psql.html .

## III. CẤU TRÚC DỮ LIỆU VÀ MÃ NGUỒN

Thư mục Lab05/Ma\_nguon đã có mã mẫu cho phần hướng dẫn. Chạy mẫu trước để xem kết quả, sau đó đọc và thực hiện lại các bước ở phần IV trên chính các tệp đó. Khi bài yêu cầu thay toàn bộ nội dung, không chép nối thêm một hàm hoặc lớp trùng tên. Phần mở rộng để sinh viên tự hoàn thiện ở mục VI.

| **Phần thực hành** | **Tệp và công việc** | **Kết quả cần đạt** |
| --- | --- | --- |
| Mẫu có hướng dẫn | src/Database.php; src/RegistrationRepository.php; database/schema.sql; demo-db.php; public/complete.php<br>Cấu hình PostgreSQL riêng, tạo bảng; đọc và thực hiện lại phần lưu/đọc đăng ký bằng truy vấn có tham số. | Tạo lần đầu: CREATED; lặp cùng email/sự kiện: DUPLICATE. Sau khi đủ môi trường, test in TESTS=6 FAILED=0 và TESTS=7 FAILED=0. |
| Bài tự mở rộng | src/RegistrationStats.php<br>Viết truy vấn thống kê theo sự kiện bằng LEFT JOIN. Sự kiện chưa có đăng ký trả total=0; ID không tồn tại trả mảng rỗng. | Sau khi đủ môi trường, bash lab.sh extend: TESTS=4 FAILED=0. Đây là kết quả yêu cầu; PostgreSQL chưa được chạy trong bản kiểm tra bàn giao. |

Vai trò từng tệp trong bài mẫu:

| **Thành phần** | **Trách nhiệm** |
| --- | --- |
| database/schema.sql | Tạo hai bảng và ba sự kiện mẫu. |
| src/Database.php | Đã cấp. Mở kết nối và thực thi SQL có tham số. |
| src/RegistrationRepository.php | Bạn đọc và sửa. Lưu đăng ký và đọc danh sách theo sự kiện. |
| demo-db.php | Bạn đọc và sửa. Kiểm tra CSDL qua dòng lệnh trước khi nối Web. |
| public/complete.php | Bạn đọc và sửa. Thay hoàn tất mô phỏng bằng lưu CSDL. |
| tests/integration.php | Kiểm thử thực sự bằng PostgreSQL, có rollback dữ liệu test. |

Bảng events có một hàng cho mỗi sự kiện. Bảng registrations có nhiều hàng tham chiếu đến events.id. Khóa ngoại ngăn lưu đăng ký cho một sự kiện không tồn tại; ràng buộc duy nhất ngăn cùng email chiếm nhiều đăng ký trong cùng sự kiện \[PG-CONSTRAINT\].

Hình chỉ mô tả nhánh tạo mới/trùng; lỗi kết nối hoặc truy vấn được xử lý riêng, không quy thành DUPLICATE.

## IV. HƯỚNG DẪN THỰC HÀNH

### Bước 1. Đọc schema, chạy và kiểm tra bảng

Mở database/schema.sql. Nội dung đã cấp:

```sql
-- Chạy trong database lab riêng; không dùng database production.
CREATE TABLE IF NOT EXISTS events (
    id integer PRIMARY KEY,
    title varchar(160) NOT NULL,
    category varchar(30) NOT NULL,
    capacity integer NOT NULL CHECK (capacity > 0)
);
CREATE TABLE IF NOT EXISTS registrations (
    id bigserial PRIMARY KEY,
    event_id integer NOT NULL REFERENCES events(id),
    name varchar(120) NOT NULL CHECK (length(trim(name)) > 0),
    email varchar(200) NOT NULL CHECK (email = lower(trim(email))),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (event_id, email)
);
INSERT INTO events (id,title,category,capacity) VALUES
(1,'PHP Open Day','tech',10),(2,'Design Workshop','design',5),
(3,'Git Clinic','tech',20) ON CONFLICT (id) DO NOTHING;
```

PRIMARY KEY định danh hàng; REFERENCES tạo quan hệ; NOT NULL không chấp nhận giá trị thiếu; CHECK kiểm soát điều kiện dữ liệu; UNIQUE(event\_id, email) áp dụng cho cặp hai cột, không phải mỗi cột riêng.

Các câu CREATE TABLE IF NOT EXISTS phục vụ khởi tạo; chúng không tự cập nhật cấu trúc một bảng đã có phiên bản khác. Trong phạm vi lab, dùng một database mới dành riêng cho bài; không coi script này là hệ thống migration hoàn chỉnh.

Chạy:

```bash
bash lab.sh sql
# Trong cửa sổ psql vừa mở, gõ:
```

```sql
\i database/schema.sql
SELECT id, title FROM events ORDER BY id;
\q
```

```bash
bash lab.sh sql
```

```sql
SELECT COUNT(*) FROM registrations;
\q
```

Có 3 sự kiện. Trong database mới, số đăng ký bằng 0. Nếu đã chạy demo, số có thể khác; không tự xóa dữ liệu để làm giống ảnh mẫu.

Đầu tệp src/Database.php đã có phần nạp .local/db.json và thiết lập biến kết nối. Giữ nguyên phần này khi đối chiếu mã minh họa. Nội dung thực hành tập trung vào pgsql, truy vấn tham số, ràng buộc và lưu/đọc dữ liệu.

Kiểm tra kết quả bước 1:

- [ ] Schema chạy trong CSDL lab riêng; events/registrations và ràng buộc được giải thích.

### Bước 2. Hiểu kết nối PHP được cấp

Mở src/Database.php. Đầu tệp đã đọc .local/db.json rồi đặt PGHOST, PGPORT, PGDATABASE, PGUSER và PGPASSWORD. Giữ phần đó; đoạn dưới chỉ trích hai hàm kết nối và truy vấn, không thay toàn bộ tệp bằng đoạn trích.

```php
<?php
declare(strict_types=1);
function db(): PgSql\Connection {
    if (!extension_loaded('pgsql')) { throw new RuntimeException('PGSQL_EXTENSION_MISSING'); }
    foreach (['PGHOST','PGDATABASE','PGUSER'] as $key) {
        if (!getenv($key)) { throw new RuntimeException('DATABASE_CONFIG_MISSING'); }
    }
    // libpq đọc PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD từ môi trường.
    $connection = @pg_connect('connect_timeout=3');
    if (!$connection) { throw new RuntimeException('DATABASE_UNAVAILABLE'); }
    return $connection;
}
function queryParams(PgSql\Connection $connection, string $sql, array $params): PgSql\Result {
    $result = @pg_query_params($connection, $sql, $params);
    if ($result === false) { throw new RuntimeException('DATABASE_OPERATION_FAILED'); }
    return $result;
}
```

Hàm db kiểm tra extension và cấu hình trước, sau đó pg\_connect dùng các biến môi trường đã khai báo. Kết nối giới hạn thời gian chờ. Khi thất bại, hàm ném lỗi để nơi gọi xử lý; không in mật khẩu hoặc chuỗi kết nối ra trình duyệt.

queryParams là hàm hỗ trợ gọi pg\_query\_params. SQL và dữ liệu được truyền tách biệt. Các vị trí $1, $2, $3 trong chuỗi SQL tương ứng phần tử 1, 2, 3 của mảng tham số; đây là cú pháp tham số PostgreSQL, không phải biến PHP nội suy \[PHP-PG\].

Phần này tiếp nối pg\_connect ở slide 10. PDO ở slide 17 là phương án khác; bài chính chỉ dùng một cách để tránh trộn nhiều API trong cùng thời lượng.

Kiểm tra kết quả bước 2:

- [ ] db() mở kết nối; queryParams tách SQL và giá trị, không ghép chuỗi đầu vào.

### Bước 3. Tạo lớp chịu trách nhiệm lưu và đọc đăng ký

Mở src/RegistrationRepository.php và đối chiếu với mã dưới đây. Nếu viết lại tệp, thay nội dung hiện tại; tránh khai báo trùng hàm hoặc lớp:

```php
<?php
declare(strict_types=1);
require_once __DIR__.'/Database.php';
final class RegistrationRepository {
    public function __construct(private PgSql\Connection $connection) {}
    public function save(int $eventId, string $name, string $email): string {
        $email = strtolower(trim($email));
        $name = trim($name);
        if ($eventId <= 0 || $name === '' || strlen($name)>120
            || str_contains($name, "\0") || strlen($email)>200
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('REGISTRATION_INVALID');
        }
        $result = queryParams($this->connection,
            'INSERT INTO registrations (event_id,name,email) VALUES ($1,$2,$3)
             ON CONFLICT (event_id,email) DO NOTHING RETURNING id',
            [$eventId, $name, $email]);
        return pg_num_rows($result) === 1 ? 'CREATED' : 'DUPLICATE';
    }
    public function listForEvent(int $eventId): array {
        $result = queryParams($this->connection,
            'SELECT id,event_id,name,email FROM registrations WHERE event_id=$1 ORDER BY id',
            [$eventId]);
        return pg_fetch_all($result) ?: [];
    }
}
```

Giải thích phương thức \`save\`: chuẩn hóa email theo quy ước mẫu; kiểm tra dữ liệu; truyền ba giá trị vào SQL; lấy ID vừa tạo. ON CONFLICT (event\_id,email) DO NOTHING không chèn khi trùng cặp khóa; RETURNING id trả một hàng khi tạo được. Từ đó phương thức phân biệt CREATED với DUPLICATE.

Chỉ kiểm tra trùng bằng SELECT trước INSERT chưa bảo vệ được tình huống hai request cùng kiểm tra trước khi một request kịp chèn. Vì vậy ràng buộc duy nhất cần nằm trong CSDL. Bài này không giải quyết tất cả các trường hợp đồng thời của hệ thống sự kiện.

Giải thích phương thức \`listForEvent\`: lọc theo $1, sắp xếp ID để kết quả ổn định và trả mảng. Định nghĩa private ... $connection trong tham số constructor là cách PHP khai báo/gán thuộc tính; có thể hiểu tương đương khai báo thuộc tính riêng rồi gán $this-&gt;connection trong hàm khởi tạo.

Kiểm tra kết quả bước 3:

- [ ] save trả CREATED hoặc DUPLICATE; lỗi không thuộc trùng khóa không được coi là DUPLICATE.

### Bước 4. Tạo chương trình demo trước khi nối giao diện

Mở demo-db.php và đối chiếu với mã dưới đây. Nếu viết lại tệp, thay nội dung hiện tại; tránh khai báo trùng hàm hoặc lớp:

```php
<?php
require __DIR__.'/src/RegistrationRepository.php';
try {
    $repo = new RegistrationRepository(db());
    echo $repo->save(1, "O'Neil Demo", 'demo@example.test') . "\n";
    echo json_encode($repo->listForEvent(1), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Database demo failed. Check lab configuration.\n'); exit(1);
}
```

Trong terminal tại Ma\_nguon, sau khi đã lưu cấu hình, chạy hai lần:

```bash
php demo-db.php
php demo-db.php
```

Với database mới, lần đầu có CREATED; lần hai có DUPLICATE. Phần JSON in danh sách của sự kiện 1. Nếu dữ liệu demo đã tồn tại, cả hai có thể là DUPLICATE; đó không phải lỗi.

Kiểm tra bằng SQL, không chỉ nhìn thông báo PHP:

```bash
bash lab.sh sql
```

```sql
SELECT event_id,email,COUNT(*) FROM registrations GROUP BY event_id,email;
\q
```

Truy vấn cần cho thấy đúng một bản ghi của demo@example.test tại sự kiện 1. Đối chiếu số hàng trong cơ sở dữ liệu với thông báo của chương trình.

Kiểm tra kết quả bước 4:

- [ ] Chạy demo hai lần có đúng một hàng theo cặp sự kiện–email; có SELECT kiểm chứng.

### Bước 5. Nối bước xác nhận với lớp lưu dữ liệu

Mở public/complete.php và đối chiếu nội dung dưới đây. Các trang nhập và xác nhận đã có đầy đủ trong Ma\_nguon của bài:

```php
<?php
require __DIR__.'/../src/Session.php';
require __DIR__.'/../src/Input.php';
require __DIR__.'/../src/Draft.php';
require __DIR__.'/../src/RegistrationRepository.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    checkCsrf($_POST);
    if (draftExpired($_SESSION,time(),1800)) {
        cancelDraft($_SESSION); header('Location: /register.php',true,303); exit;
    }
    try {
        $data = $_SESSION['draft']['data'];
        $repo = new RegistrationRepository(db());
        $_SESSION['completed'] = $repo->save($data['event_id'],$data['name'],$data['email']);
        cancelDraft($_SESSION);
        header('Location: /complete.php',true,303); exit;
    } catch (Throwable $ex) {
        error_log('CommunityPass: registration_save_failed');
        http_response_code(503); $error = 'Không lưu được. Bản nháp vẫn được giữ để thử lại.';
    }
}
if ($error === '' && !isset($_SESSION['completed'])) {
    header('Location: /register.php',true,303); exit;
}
require __DIR__.'/../templates/header.php';
?>
<div class="page-heading">
<h1>Kết quả lưu trữ</h1>
</div>
<section class="completion" role="status">
<p class="status-message">
    <?= e($error !== '' ? $error : $_SESSION['completed']) ?>
</p>
<p>Mẫu Lab 05 kiểm tra lưu bền vững và đăng ký trùng; chưa có giới hạn chỗ đồng thời.</p>
<a href="/confirm.php">Quay lại xác nhận</a>
</section>
<?php require __DIR__ . '/../templates/footer.php'; ?>```

Phần lưu dữ liệu nằm trong khối try: lấy nháp từ Session → tạo đối tượng lưu trữ → gọi save → chỉ sau khi có kết quả mới xóa nháp. Khi gặp lỗi database, giữ nháp để người dùng có thể thử lại và trả thông báo chung.

Giữ các bước kiểm tra POST, token và chuyển hướng. Các câu SQL được đặt trong lớp lưu trữ để tách việc truy cập cơ sở dữ liệu khỏi xử lý giao diện.

Kiểm tra kết quả bước 5:

- [ ] complete.php chỉ xóa nháp sau kết quả lưu hợp lệ; lỗi giữ nháp.

### Bước 6. Kiểm tra chức năng Web với PostgreSQL

Dừng các máy chủ PHP cũ. Trong terminal tại thư mục dự án của bài, chạy:

```bash
bash lab.sh run
```

Mở /register.php, chọn sự kiện 1, nhập tên giả lập và email khác demo, đi qua trang xác nhận rồi hoàn tất. Kết quả lần đầu là CREATED. Đăng ký lại cùng thông tin có DUPLICATE. Đổi sự kiện hoặc email có thể tạo một hàng mới theo ràng buộc đã công bố.

Dừng rồi khởi chạy lại máy chủ PHP, sau đó truy vấn bằng psql để kiểm tra bản ghi. Dữ liệu trong cơ sở dữ liệu vẫn tồn tại sau khi kết thúc máy chủ PHP. Do mẫu chưa có phân quyền quản trị, chỉ chạy cục bộ với dữ liệu giả lập.

Kiểm tra kết quả bước 6:

- [ ] Đăng ký được lưu vào cơ sở dữ liệu và còn tồn tại sau khi khởi động lại máy chủ PHP.

### Bước 7. Chạy kiểm thử tích hợp

```bash
php tests/run.php
bash lab.sh test
```

Bộ tích hợp tạo sự kiện thử riêng, kiểm tra lưu/trùng/dấu nháy/danh sách rồi rollback. Khi đủ môi trường, cần TESTS=7 FAILED=0. Nếu có NOT\_RUN, nghĩa là chưa thực thi kiểm thử do thiếu kết nối/cấu hình; không coi đó là PASS.

Báo cáo kiểm tra môi trường biên soạn được lưu trong tài liệu giảng viên. Phần tích hợp PostgreSQL cần được chạy và xác nhận trên máy giảng viên trước khi sử dụng trong lớp.

Kiểm tra kết quả bước 7:

- [ ] Chạy cả test cơ bản/tích hợp; nếu thiếu CSDL ghi NOT\_RUN, không tự báo đạt.

Kết quả sau phần hướng dẫn: Tạo lần đầu: CREATED; lặp cùng email/sự kiện: DUPLICATE. Sau khi đủ môi trường, test in TESTS=6 FAILED=0 và TESTS=7 FAILED=0.

## V. LỖI THƯỜNG GẶP VÀ CÁCH KHẮC PHỤC

| **Lỗi/hiện tượng** | **Cách xử lý** |
| --- | --- |
| Thiếu pgsql | So phiên bản php -v với extension đã cài; chạy php -m. |
| Database không kết nối | Kiểm tra dịch vụ, user/database lab, mật khẩu. Chạy bash lab.sh db-config nếu cần sửa cấu hình cục bộ. |
| Không có bảng | Chạy schema trong đúng database; xác minh current\_database(). |
| DUPLICATE ngay lần chạy thử đầu tiên | Có thể dữ liệu demo đã tồn tại từ lần trước; kiểm tra SQL trước khi kết luận. |
| Chạy demo được nhưng Web lỗi | Kiểm tra đang chạy đúng bản Lab05 và .local/db.json của bản đó; dừng PHP rồi chạy lại sau khi sửa cấu hình. |
| Test ghi NOT\_RUN | Sửa điều kiện chạy, chạy lại; không sửa test để tự báo đạt. |

## VI. BÀI TẬP MỞ RỘNG: THỐNG KÊ ĐĂNG KÝ THEO SỰ KIỆN

```bash
# Vẫn đứng trong Ma_nguon của bài này.
bash lab.sh extend
```

Hoàn thiện src/RegistrationStats.php để nhận ID sự kiện và trả event\_id, title, total. Sự kiện không tồn tại trả mảng rỗng; sự kiện tồn tại nhưng chưa có đăng ký phải trả tổng 0.

Dùng truy vấn có tham số, không ghép ID trực tiếp vào SQL. Gợi ý: bắt đầu từ bảng sự kiện, kết hợp đăng ký bằng LEFT JOIN, đếm khóa đăng ký thay vì COUNT(\*) để phân biệt không có đăng ký. Xem lược đồ trong phần hướng dẫn, tự viết câu SQL.

Chạy bash lab.sh extend trên PostgreSQL, sau đó chạy bash lab.sh test để kiểm tra các chức năng đã có. Phần nâng cao: phân tích phương án kiểm soát sức chứa khi nhiều yêu cầu đăng ký được gửi đồng thời; không bắt buộc triển khai trong thời gian làm bài mở rộng.

## KẾT QUẢ CỦA BÀI THỰC HÀNH

Có luồng Form lưu đăng ký thật, chống trùng theo sự kiện–email, tra cứu danh sách và thống kê cả sự kiện chưa có người đăng ký.

## VII. BẢNG KIỂM HOÀN THÀNH

- [ ] Kết nối tới CSDL thực hành riêng; schema và dữ liệu mẫu đã được tạo.
- [ ] Lưu lần đầu CREATED, lặp cùng email/sự kiện DUPLICATE.
- [ ] Tên chứa dấu nháy được lưu và đọc lại nguyên vẹn.
- [ ] Thống kê chưa có người trả total=0; ID thiếu trả mảng rỗng.
- [ ] test và extend đạt; khởi động lại PHP không làm mất bản ghi.

Kết quả tổng hợp của phần tự làm phải có TESTS=4 FAILED=0. Ngoài các phép thử tự động, thực hiện các tình huống kiểm tra trực tiếp nêu trong bài. Lệnh check chỉ xác nhận môi trường, không thay cho test và extend.

## VIII. RUBRIC LAB — 10 ĐIỂM

| **Tiêu chí** | **Điểm** | **Cách chấm** |
| --- | --- | --- |
| Phần mẫu có hướng dẫn | 2,0 | 1 điểm: thực hiện và có kết quả đúng; 1 điểm: giải thích luồng mã. Mỗi phần đạt một phần: 0,5; chưa đạt: 0. |
| Truy vấn thống kê | 1,5 | LEFT JOIN giữ sự kiện chưa có đăng ký; COUNT đúng; lọc theo ID bằng tham số. |
| Kết quả trả về | 1,5 | Trả đúng event\_id/title/total; total là số nguyên; ID không tồn tại trả mảng rỗng. |
| Nhất quán dữ liệu | 1,0 | Đăng ký trùng không tăng tổng; xử lý lỗi CSDL riêng, không che lỗi thành kết quả 0. |
| Kiểm thử và xử lý lỗi | 2,0 | 1 điểm: có dữ liệu và kết quả mong đợi cho tình huống đúng, sai, biên; 1 điểm: chạy lại phần mẫu và mở rộng sau sửa. Mỗi phần đạt một phần: 0,5; chưa đạt: 0. |
| Mã nguồn và bàn giao | 1,0 | 0,5: nộp đúng tệp, dữ liệu và phiên bản; 0,5: README có lệnh chạy/kiểm thử, không chứa cấu hình riêng. Đạt một phần mỗi mục: 0,25. |
| Giải thích cá nhân | 1,0 | 0,5: giải thích phần tự viết; 0,5: giải thích một ca lỗi hoặc thực hiện một thay đổi nhỏ. Đạt một phần mỗi mục: 0,25. |
| Tổng | 10,0 | Chấm trực tiếp sản phẩm và phần giải thích của bài lab. |

Với tiêu chí 1,5 điểm: đủ yêu cầu được 1,5; thực hiện một phần được 0,75; chưa thực hiện được 0. Với tiêu chí 1 điểm không chia tiểu mục: đủ được 1; một phần được 0,5; chưa thực hiện được 0. Bài tự làm không đạt điểm chỉ nhờ thay bộ kiểm thử hoặc ghi sẵn kết quả.

## IX. NỘP BÀI

Bước 1. Lưu mã bài mẫu và bài tự làm tại Lab05/Ma\_nguon, kèm dữ liệu, kiểm thử và README. Loại bỏ .local, mật khẩu và dữ liệu cá nhân thật khỏi bài nộp.

Bước 2. Chạy bash lab.sh check, bash lab.sh test và bash lab.sh extend. Thực hiện các phép kiểm tra trực tiếp trong bài và sửa lỗi trước khi nộp.

Bước 3. Commit, đẩy mã lên GitHub và nộp tên nhóm/thành viên, Lab 05, liên kết Ma\_nguon tại đúng commit. Kiểm tra quyền đọc; kèm lần chạy Actions khi được yêu cầu. Hạn và địa chỉ nộp do giảng viên công bố. Nếu nhận ZIP, giữ nguyên cấu trúc trên.
