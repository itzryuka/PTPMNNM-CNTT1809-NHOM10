-- =====================================================================
--  CSDL: quanlykhohang  —  Quản lý kho hàng theo mô hình SỔ CÁI (Ledger)
--  Dùng cho: MySQL 8.0.16+  hoặc  MariaDB 10.2+ (XAMPP / Laragon)
--
--  File này chỉ chứa CẤU TRÚC. Dữ liệu mẫu được sinh bởi build_database.php,
--  kết quả ghép lại thành quanlykhohang.sql (file dùng để import).
--
--  Nguyên tắc:
--   1. stock_movements chỉ được INSERT (trigger chặn UPDATE / DELETE).
--   2. stocks.quantity = SUM(stock_movements.quantity) theo kho/lô
--      (kiểm tra bằng view v_stock_reconciliation).
--   3. stocks.quantity >= 0 và 0 <= stocks.reserved <= stocks.quantity (CHECK)
--      — lưới an toàn cuối cùng chống xuất âm và chống "hứa" quá số hàng có.
--   4. Chứng từ đi theo máy trạng thái (trigger kiểm tra từng bước chuyển):
--        IMPORT    : PENDING -> APPROVED (đồng ý nhập) -> PARTIAL -> COMPLETED | CLOSED (đóng, thiếu hàng)
--        EXPORT    : PENDING -> APPROVED (giữ hàng) -> COMPLETED (đã xuất) | CANCELLED (hủy giữ chỗ)
--        TRANSFER  : PENDING -> APPROVED (giữ hàng ở kho đi) -> IN_TRANSIT (đã gửi)
--                    -> COMPLETED | DISCREPANCY (nhận thiếu) -> IN_TRANSIT | COMPLETED
--        ADJUSTMENT: PENDING -> APPROVED (ghi sổ ngay)
--      Đã kết thúc (COMPLETED / CLOSED / CANCELLED / REJECTED) thì không sửa, không xóa.
--      Muốn sửa sai -> lập chứng từ ADJUSTMENT có lý do, tham chiếu chứng từ gốc.
--   5. Mỗi lần hàng thật sự di chuyển là 1 ĐỢT GHI SỔ (voucher_postings): nhận hàng, xuất kho,
--      gửi hàng, hàng đến, điều chỉnh. Phát sinh trỏ về đợt đó; mỗi dòng chứng từ chỉ ghi 1 lần
--      cho mỗi kho trong 1 đợt (UNIQUE posting + dòng + kho) — chống ghi đúp ở mức CSDL.
--   6. Điều chuyển đi qua kho ảo "Hàng đang đi đường" (warehouses.is_transit = 1). Mỗi bước vẫn
--      ghi đủ 2 đầu trong cùng 1 giao dịch: gửi = (-) kho đi, (+) đang đi đường;
--      nhận = (-) đang đi đường, (+) kho đến. Hàng thất lạc: phiếu ADJUSTMENT (-) đang đi đường, có lý do.
--
--  CẢNH BÁO: dòng DROP DATABASE bên dưới xóa toàn bộ dữ liệu cũ của
--  quanlykhohang. Bỏ dòng đó nếu bạn đã có dữ liệu thật.
-- =====================================================================

DROP DATABASE IF EXISTS quanlykhohang;
CREATE DATABASE quanlykhohang
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
USE quanlykhohang;

-- =====================================================================
-- 1. HỆ THỐNG PHÂN QUYỀN
-- =====================================================================

CREATE TABLE users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(50)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,             -- password_hash() của PHP
  full_name      VARCHAR(100) NOT NULL,
  email          VARCHAR(100) NULL,
  -- ADMIN: toàn quyền | MANAGER: duyệt chứng từ, duyệt kiểm kê | STAFF: lập phiếu, nhập số đếm
  role           ENUM('ADMIN','MANAGER','STAFF') NOT NULL DEFAULT 'STAFF',
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,     -- 0 = đã khóa (nghỉ việc): không đăng nhập được, lịch sử chứng từ giữ nguyên
  -- Tài khoản do quản trị cấp kèm mật khẩu tạm -> lần đăng nhập đầu bắt buộc đổi mật khẩu
  must_change_password TINYINT(1) NOT NULL DEFAULT 0,
  last_login_at  DATETIME     NULL,
  created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

-- =====================================================================
-- 2. DANH MỤC (MASTER DATA)
-- =====================================================================

CREATE TABLE warehouses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code        VARCHAR(20)  NOT NULL,
  name        VARCHAR(100) NOT NULL,
  address     VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  -- 1 = kho ảo "Hàng đang đi đường" của điều chuyển. Không phân quyền cho ai -> không hiện trong danh mục kho
  is_transit  TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Khóa chống trùng tên: chữ thường, so sánh nhị phân -> không phân biệt hoa/thường nhưng PHÂN BIỆT dấu
  -- (collation *_ci của MySQL coi "Cá" = "Cà"). Khoảng trắng thừa đã được ứng dụng gộp trước khi lưu.
  name_key    VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin AS (LOWER(name)) PERSISTENT,
  UNIQUE KEY uq_warehouses_code (code),
  UNIQUE KEY uq_warehouses_name (name_key)
) ENGINE=InnoDB;

-- Bảng n-n: user được thao tác trên những kho nào (kiểm tra ở server)
CREATE TABLE user_warehouses (
  user_id       INT UNSIGNED NOT NULL,
  warehouse_id  INT UNSIGNED NOT NULL,
  assigned_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, warehouse_id),
  KEY idx_uw_warehouse (warehouse_id),
  CONSTRAINT fk_uw_user      FOREIGN KEY (user_id)      REFERENCES users(id)      ON DELETE CASCADE,
  CONSTRAINT fk_uw_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sku         VARCHAR(30)  NOT NULL,                -- mã sản phẩm
  name        VARCHAR(150) NOT NULL,
  unit        VARCHAR(20)  NOT NULL,                -- đơn vị tính (Hộp, Thùng, Chai...)
  description VARCHAR(255) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Chống tạo cùng một mặt hàng 2 lần dưới 2 mã khác nhau (xem name_key của warehouses)
  name_key    VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin AS (LOWER(name)) PERSISTENT,
  UNIQUE KEY uq_items_sku (sku),
  UNIQUE KEY uq_items_name (name_key)
) ENGINE=InnoDB;

CREATE TABLE batches (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_id       INT UNSIGNED NOT NULL,
  batch_no      VARCHAR(50)  NOT NULL,              -- số lô
  mfg_date      DATE NULL,                          -- ngày sản xuất
  expiry_date   DATE NULL,                          -- ngày hết hạn
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_batches_item_no (item_id, batch_no),
  -- Khóa (id, item_id) để các bảng khác FK kép -> đảm bảo lô thuộc đúng mặt hàng
  UNIQUE KEY uq_batches_id_item (id, item_id),
  KEY idx_batches_expiry (expiry_date),
  CONSTRAINT fk_batches_item FOREIGN KEY (item_id) REFERENCES items(id),
  CONSTRAINT ck_batches_dates CHECK (mfg_date IS NULL OR expiry_date IS NULL OR expiry_date > mfg_date)
) ENGINE=InnoDB;

-- =====================================================================
-- 3. CHỨNG TỪ (VOUCHERS)
-- =====================================================================

CREATE TABLE vouchers (
  id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                  VARCHAR(30)  NOT NULL,      -- PN-/PX-/PC-/DC-...
  type                  ENUM('IMPORT','EXPORT','TRANSFER','ADJUSTMENT') NOT NULL,
  warehouse_id          INT UNSIGNED NOT NULL,      -- kho chính (kho ĐI nếu là TRANSFER)
  to_warehouse_id       INT UNSIGNED NULL,          -- kho ĐẾN, chỉ dùng cho TRANSFER
  reference_voucher_id  INT UNSIGNED NULL,          -- chứng từ gốc bị điều chỉnh/hủy (chỉ ADJUSTMENT)
  voucher_date          DATE         NOT NULL,      -- ngày lập
  status                ENUM('PENDING','APPROVED','PARTIAL','IN_TRANSIT','DISCREPANCY',
                             'COMPLETED','CLOSED','CANCELLED','REJECTED') NOT NULL DEFAULT 'PENDING',
  reason                VARCHAR(255) NULL,          -- ghi chú / lý do (BẮT BUỘC với ADJUSTMENT)
  closed_reason         VARCHAR(255) NULL,          -- lý do đóng phiếu thiếu hàng / hủy giữ chỗ
  shipped_at            DATETIME     NULL,          -- lúc hàng rời kho (xuất kho / gửi điều chuyển)
  completed_at          DATETIME     NULL,          -- lúc phiếu kết thúc (nhận đủ / đã xuất / đóng / hủy)
  -- Mã ngẫu nhiên sinh khi mở form lập phiếu: bấm "Lưu" 2 lần / F5 gửi lại form
  -- sẽ vi phạm UNIQUE -> không tạo trùng chứng từ
  client_token          CHAR(32)     NULL,
  created_by            INT UNSIGNED NOT NULL,      -- người lập
  approved_by           INT UNSIGNED NULL,          -- người duyệt / từ chối
  approved_at           DATETIME     NULL,
  created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vouchers_code (code),
  UNIQUE KEY uq_vouchers_client_token (client_token),
  KEY idx_vouchers_status (status),
  KEY idx_vouchers_wh_status (warehouse_id, status),
  KEY idx_vouchers_type_date (type, voucher_date),
  CONSTRAINT fk_vouchers_wh       FOREIGN KEY (warehouse_id)         REFERENCES warehouses(id),
  CONSTRAINT fk_vouchers_to_wh    FOREIGN KEY (to_warehouse_id)      REFERENCES warehouses(id),
  CONSTRAINT fk_vouchers_ref      FOREIGN KEY (reference_voucher_id) REFERENCES vouchers(id),
  CONSTRAINT fk_vouchers_creator  FOREIGN KEY (created_by)           REFERENCES users(id),
  CONSTRAINT fk_vouchers_approver FOREIGN KEY (approved_by)          REFERENCES users(id),
  -- TRANSFER bắt buộc có kho đến khác kho đi; loại khác không được có kho đến
  CONSTRAINT ck_vouchers_transfer CHECK (
       (type =  'TRANSFER' AND to_warehouse_id IS NOT NULL AND to_warehouse_id <> warehouse_id)
    OR (type <> 'TRANSFER' AND to_warehouse_id IS NULL)
  ),
  -- Chứng từ điều chỉnh bắt buộc có lý do
  CONSTRAINT ck_vouchers_adjust_reason CHECK (
       type <> 'ADJUSTMENT' OR (reason IS NOT NULL AND reason <> '')
  ),
  -- Chỉ chứng từ điều chỉnh mới tham chiếu chứng từ gốc
  CONSTRAINT ck_vouchers_reference CHECK (
       reference_voucher_id IS NULL OR type = 'ADJUSTMENT'
  ),
  -- Đã duyệt / từ chối thì phải có người duyệt
  CONSTRAINT ck_vouchers_approval CHECK (
       status = 'PENDING' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)
  )
) ENGINE=InnoDB;

-- Quy ước dấu của quantity:
--   IMPORT / EXPORT / TRANSFER : luôn DƯƠNG (chiều nhập/xuất do loại chứng từ quyết định)
--   ADJUSTMENT                 : CÓ DẤU (+ tăng tồn, - giảm tồn), khác 0
-- Tiến độ thực hiện (chỉ thay đổi được khi phiếu đang chạy, xem trigger):
--   IMPORT  : received_qty (nhận tốt, đã cộng tồn), damaged_qty (hỏng, trả NCC, không vào tồn)
--   EXPORT  : shipped_qty (đã xuất)
--   TRANSFER: shipped_qty (đã gửi), received_qty (kho đến đã nhận), lost_qty (ghi hao hụt trên đường)
--             đang đi đường = shipped_qty - received_qty - lost_qty
CREATE TABLE voucher_lines (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id    INT UNSIGNED  NOT NULL,
  item_id       INT UNSIGNED  NOT NULL,
  batch_id      INT UNSIGNED  NOT NULL,
  quantity      DECIMAL(15,3) NOT NULL,             -- số lượng theo phiếu (đặt / yêu cầu)
  received_qty  DECIMAL(15,3) NOT NULL DEFAULT 0,
  damaged_qty   DECIMAL(15,3) NOT NULL DEFAULT 0,
  shipped_qty   DECIMAL(15,3) NOT NULL DEFAULT 0,
  lost_qty      DECIMAL(15,3) NOT NULL DEFAULT 0,
  note          VARCHAR(255)  NULL,
  UNIQUE KEY uq_vl_voucher_batch (voucher_id, item_id, batch_id),
  KEY idx_vl_item (item_id),
  CONSTRAINT fk_vl_voucher FOREIGN KEY (voucher_id)        REFERENCES vouchers(id) ON DELETE CASCADE,
  CONSTRAINT fk_vl_item    FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_vl_batch   FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT ck_vl_qty CHECK (quantity <> 0),
  -- Không nhận / gửi quá số trên phiếu, không có số âm
  CONSTRAINT ck_vl_progress CHECK (
        received_qty >= 0 AND damaged_qty >= 0 AND shipped_qty >= 0 AND lost_qty >= 0
    AND shipped_qty <= ABS(quantity)
    AND received_qty + lost_qty <= ABS(quantity)
  )
) ENGINE=InnoDB;

-- Đợt ghi sổ: mỗi lần hàng thật sự di chuyển (1 lần xe về, 1 lần xuất, 1 lần gửi, 1 lần nhận...)
--   RECEIVE: nhận hàng của phiếu nhập   SHIP: xuất kho phiếu xuất
--   SEND   : gửi hàng điều chuyển       ARRIVE: kho đến nhận hàng điều chuyển
--   ADJUST : ghi sổ phiếu điều chỉnh
CREATE TABLE voucher_postings (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id    INT UNSIGNED NOT NULL,
  code          VARCHAR(40)  NOT NULL,              -- <mã phiếu>/<số thứ tự>, vd PN-2026-0021/2
  kind          ENUM('RECEIVE','SHIP','SEND','ARRIVE','ADJUST') NOT NULL,
  note          VARCHAR(255) NULL,                  -- vd lý do hàng hỏng khi nhận
  -- Mã sinh khi mở form: bấm "Ghi nhận" 2 lần / F5 -> vi phạm UNIQUE -> không ghi đúp 1 đợt
  client_token  CHAR(32)     NULL,
  created_by    INT UNSIGNED NOT NULL,
  created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vp_code (code),
  UNIQUE KEY uq_vp_token (client_token),
  KEY idx_vp_voucher (voucher_id),
  CONSTRAINT fk_vp_voucher FOREIGN KEY (voucher_id) REFERENCES vouchers(id),
  CONSTRAINT fk_vp_user    FOREIGN KEY (created_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- Số lượng của từng dòng trong 1 đợt (quantity: số đi / đến thật; damaged_qty: hỏng khi nhận hàng nhập)
CREATE TABLE voucher_posting_lines (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  posting_id       INT UNSIGNED  NOT NULL,
  voucher_line_id  INT UNSIGNED  NOT NULL,
  quantity         DECIMAL(15,3) NOT NULL DEFAULT 0,
  damaged_qty      DECIMAL(15,3) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_vpl (posting_id, voucher_line_id),
  CONSTRAINT fk_vpl_posting FOREIGN KEY (posting_id)      REFERENCES voucher_postings(id),
  CONSTRAINT fk_vpl_line    FOREIGN KEY (voucher_line_id) REFERENCES voucher_lines(id),
  CONSTRAINT ck_vpl_qty CHECK (damaged_qty >= 0 AND (quantity <> 0 OR damaged_qty > 0))
) ENGINE=InnoDB;

-- =====================================================================
-- 4. SỔ PHÁT SINH (STOCK LEDGER) — CHỈ INSERT
-- =====================================================================

CREATE TABLE stock_movements (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id       INT UNSIGNED  NOT NULL,
  posting_id       INT UNSIGNED  NOT NULL,          -- đợt ghi sổ sinh ra phát sinh này
  voucher_line_id  INT UNSIGNED  NOT NULL,
  warehouse_id     INT UNSIGNED  NOT NULL,
  item_id          INT UNSIGNED  NOT NULL,
  batch_id         INT UNSIGNED  NOT NULL,
  quantity         DECIMAL(15,3) NOT NULL,          -- (+) nhập, (-) xuất
  moved_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- ngày giờ thực tế
  created_by       INT UNSIGNED  NOT NULL,
  -- Chống ghi đúp: trong 1 đợt, 1 dòng chứng từ chỉ sinh tối đa 1 phát sinh cho mỗi kho
  -- (gửi điều chuyển sinh 2 dòng: -kho đi, +đang đi đường -> khác warehouse_id nên hợp lệ)
  UNIQUE KEY uq_sm_posting_line_wh (posting_id, voucher_line_id, warehouse_id),
  KEY idx_sm_stock (warehouse_id, item_id, batch_id, moved_at),
  KEY idx_sm_item_time (item_id, moved_at),
  KEY idx_sm_voucher (voucher_id),
  KEY idx_sm_moved_at (moved_at),
  CONSTRAINT fk_sm_voucher FOREIGN KEY (voucher_id)        REFERENCES vouchers(id),
  CONSTRAINT fk_sm_posting FOREIGN KEY (posting_id)        REFERENCES voucher_postings(id),
  CONSTRAINT fk_sm_line    FOREIGN KEY (voucher_line_id)   REFERENCES voucher_lines(id),
  CONSTRAINT fk_sm_wh      FOREIGN KEY (warehouse_id)      REFERENCES warehouses(id),
  CONSTRAINT fk_sm_item    FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_sm_batch   FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT fk_sm_user    FOREIGN KEY (created_by)        REFERENCES users(id),
  CONSTRAINT ck_sm_qty CHECK (quantity <> 0)
) ENGINE=InnoDB;

-- =====================================================================
-- 5. TỒN KHO HIỆN TẠI (CACHE) — dòng bị khóa bằng SELECT ... FOR UPDATE
-- =====================================================================

CREATE TABLE stocks (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  warehouse_id  INT UNSIGNED  NOT NULL,
  item_id       INT UNSIGNED  NOT NULL,
  batch_id      INT UNSIGNED  NOT NULL,
  quantity      DECIMAL(15,3) NOT NULL DEFAULT 0,   -- tồn thực (hàng đang nằm trong kho)
  -- Đang giữ chỗ cho phiếu xuất / điều chuyển đã duyệt nhưng hàng chưa rời kho.
  -- Có thể xuất = quantity - reserved
  reserved      DECIMAL(15,3) NOT NULL DEFAULT 0,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stocks (warehouse_id, item_id, batch_id),   -- dùng cho INSERT ... ON DUPLICATE KEY UPDATE
  KEY idx_stocks_item (item_id),
  CONSTRAINT fk_stocks_wh    FOREIGN KEY (warehouse_id)      REFERENCES warehouses(id),
  CONSTRAINT fk_stocks_item  FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_stocks_batch FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT ck_stocks_non_negative CHECK (quantity >= 0),  -- KHÔNG BAO GIỜ ÂM
  -- Không giữ chỗ quá số hàng thực có (2 phiếu cùng giữ 1 lô: phiếu sau bị CSDL chặn nếu vượt)
  CONSTRAINT ck_stocks_reserved CHECK (reserved >= 0 AND reserved <= quantity)
) ENGINE=InnoDB;

-- =====================================================================
-- 5b. YÊU CẦU ĐIỀU CHUYỂN: kho CẦN hàng đề nghị kho CÓ hàng chuyển sang
--     PENDING  -> ACCEPTED (kho có hàng đồng ý: hệ thống chọn lô, lập phiếu điều chuyển đã giữ hàng)
--              -> DECLINED (từ chối, có lý do) | CANCELLED (bên yêu cầu rút lại)
-- =====================================================================

CREATE TABLE transfer_requests (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                 VARCHAR(30)  NOT NULL,       -- YC-2026-0001
  from_warehouse_id    INT UNSIGNED NOT NULL,       -- kho được đề nghị gửi hàng
  to_warehouse_id      INT UNSIGNED NOT NULL,       -- kho cần hàng (bên lập yêu cầu)
  needed_by            DATE         NULL,           -- cần trước ngày
  reason               VARCHAR(255) NOT NULL,       -- vd: Khách Minh Anh đặt 20 thùng, giao 30/10
  status               ENUM('PENDING','ACCEPTED','DECLINED','CANCELLED') NOT NULL DEFAULT 'PENDING',
  decision_note        VARCHAR(255) NULL,
  transfer_voucher_id  INT UNSIGNED NULL,           -- phiếu điều chuyển sinh ra khi đồng ý
  client_token         CHAR(32)     NULL,
  created_by           INT UNSIGNED NOT NULL,
  created_at           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  decided_by           INT UNSIGNED NULL,
  decided_at           DATETIME     NULL,
  UNIQUE KEY uq_tr_code (code),
  UNIQUE KEY uq_tr_token (client_token),
  UNIQUE KEY uq_tr_voucher (transfer_voucher_id),
  KEY idx_tr_from_status (from_warehouse_id, status),
  KEY idx_tr_to_status (to_warehouse_id, status),
  CONSTRAINT fk_tr_from    FOREIGN KEY (from_warehouse_id)   REFERENCES warehouses(id),
  CONSTRAINT fk_tr_to      FOREIGN KEY (to_warehouse_id)     REFERENCES warehouses(id),
  CONSTRAINT fk_tr_voucher FOREIGN KEY (transfer_voucher_id) REFERENCES vouchers(id),
  CONSTRAINT fk_tr_creator FOREIGN KEY (created_by)          REFERENCES users(id),
  CONSTRAINT fk_tr_decider FOREIGN KEY (decided_by)          REFERENCES users(id),
  CONSTRAINT ck_tr_warehouses CHECK (from_warehouse_id <> to_warehouse_id),
  CONSTRAINT ck_tr_reason CHECK (reason <> ''),
  CONSTRAINT ck_tr_accepted CHECK (status <> 'ACCEPTED' OR transfer_voucher_id IS NOT NULL),
  CONSTRAINT ck_tr_decided CHECK (status = 'PENDING' OR (decided_by IS NOT NULL AND decided_at IS NOT NULL))
) ENGINE=InnoDB;

CREATE TABLE transfer_request_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_id  INT UNSIGNED  NOT NULL,
  item_id     INT UNSIGNED  NOT NULL,
  quantity    DECIMAL(15,3) NOT NULL,
  UNIQUE KEY uq_trl (request_id, item_id),
  CONSTRAINT fk_trl_request FOREIGN KEY (request_id) REFERENCES transfer_requests(id) ON DELETE CASCADE,
  CONSTRAINT fk_trl_item    FOREIGN KEY (item_id)    REFERENCES items(id),
  CONSTRAINT ck_trl_qty CHECK (quantity > 0)
) ENGINE=InnoDB;

-- =====================================================================
-- 6. KIỂM KÊ (INVENTORY COUNT)
--    COUNTING  : đã snapshot tồn, nhân viên đang nhập số đếm
--    APPROVED  : quản lý duyệt -> sinh 1 phiếu ADJUSTMENT cho các dòng lệch
--    CANCELLED : hủy đợt kiểm kê, không sinh phát sinh nào
-- =====================================================================

CREATE TABLE stock_counts (
  id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                   VARCHAR(30)  NOT NULL,
  warehouse_id           INT UNSIGNED NOT NULL,
  status                 ENUM('COUNTING','APPROVED','CANCELLED') NOT NULL DEFAULT 'COUNTING',
  note                   VARCHAR(255) NULL,
  created_by             INT UNSIGNED NOT NULL,
  created_at             DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- thời điểm snapshot
  approved_by            INT UNSIGNED NULL,
  approved_at            DATETIME     NULL,
  adjustment_voucher_id  INT UNSIGNED NULL,          -- phiếu ADJUSTMENT sinh ra khi duyệt
  UNIQUE KEY uq_sc_code (code),
  UNIQUE KEY uq_sc_adj_voucher (adjustment_voucher_id),
  KEY idx_sc_wh_status (warehouse_id, status),
  CONSTRAINT fk_sc_wh       FOREIGN KEY (warehouse_id)          REFERENCES warehouses(id),
  CONSTRAINT fk_sc_creator  FOREIGN KEY (created_by)            REFERENCES users(id),
  CONSTRAINT fk_sc_approver FOREIGN KEY (approved_by)           REFERENCES users(id),
  CONSTRAINT fk_sc_voucher  FOREIGN KEY (adjustment_voucher_id) REFERENCES vouchers(id),
  CONSTRAINT ck_sc_approval CHECK (
       status <> 'APPROVED' OR (approved_by IS NOT NULL AND approved_at IS NOT NULL)
  )
) ENGINE=InnoDB;

CREATE TABLE stock_count_lines (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  count_id     INT UNSIGNED  NOT NULL,
  item_id      INT UNSIGNED  NOT NULL,
  batch_id     INT UNSIGNED  NOT NULL,
  system_qty   DECIMAL(15,3) NOT NULL,              -- tồn hệ thống lúc snapshot
  counted_qty  DECIMAL(15,3) NULL,                  -- số đếm thực tế (NULL = chưa đếm)
  difference   DECIMAL(15,3) AS (counted_qty - system_qty) STORED,  -- chênh lệch tự tính
  reason       VARCHAR(255)  NULL,                  -- lý do chênh lệch (hỏng, thất lạc...)
  counted_by   INT UNSIGNED  NULL,
  counted_at   DATETIME      NULL,
  UNIQUE KEY uq_scl (count_id, item_id, batch_id),
  CONSTRAINT fk_scl_count FOREIGN KEY (count_id)          REFERENCES stock_counts(id) ON DELETE CASCADE,
  CONSTRAINT fk_scl_item  FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_scl_batch FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT fk_scl_user  FOREIGN KEY (counted_by)        REFERENCES users(id),
  CONSTRAINT ck_scl_counted CHECK (counted_qty IS NULL OR counted_qty >= 0)
) ENGINE=InnoDB;

-- =====================================================================
-- 7. TRIGGER BẢO VỆ TÍNH TOÀN VẸN
-- =====================================================================
DELIMITER $$

-- 7.1 Sổ phát sinh bất biến
CREATE TRIGGER trg_sm_no_update BEFORE UPDATE ON stock_movements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'stock_movements chi duoc INSERT: khong duoc UPDATE so phat sinh';
END$$

CREATE TRIGGER trg_sm_no_delete BEFORE DELETE ON stock_movements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'stock_movements chi duoc INSERT: khong duoc DELETE so phat sinh';
END$$

-- 7.2 Chỉ ghi sổ được ở đúng bước của chứng từ, và phát sinh phải khớp đợt + dòng chứng từ
--     RECEIVE: phiếu nhập đã duyệt / nhận một phần   SHIP: phiếu xuất đã duyệt (đang giữ hàng)
--     SEND   : điều chuyển đã duyệt                  ARRIVE: điều chuyển đang đi đường / nhận thiếu
--     ADJUST : phiếu điều chỉnh đang PENDING (đang trong transaction duyệt)
CREATE TRIGGER trg_sm_before_insert BEFORE INSERT ON stock_movements
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  DECLARE v_type VARCHAR(12);
  DECLARE p_voucher INT UNSIGNED;
  DECLARE p_kind VARCHAR(10);
  DECLARE v_line_voucher INT UNSIGNED;
  DECLARE v_line_item INT UNSIGNED;
  DECLARE v_line_batch INT UNSIGNED;

  SELECT status, type INTO v_status, v_type FROM vouchers WHERE id = NEW.voucher_id;
  SELECT voucher_id, kind INTO p_voucher, p_kind FROM voucher_postings WHERE id = NEW.posting_id;
  IF p_voucher IS NULL OR p_voucher <> NEW.voucher_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Phat sinh khong thuoc dot ghi so cua chung tu nay';
  END IF;
  IF NOT (   (p_kind = 'RECEIVE' AND v_type = 'IMPORT'     AND v_status IN ('APPROVED', 'PARTIAL'))
          OR (p_kind = 'SHIP'    AND v_type = 'EXPORT'     AND v_status = 'APPROVED')
          OR (p_kind = 'SEND'    AND v_type = 'TRANSFER'   AND v_status = 'APPROVED')
          OR (p_kind = 'ARRIVE'  AND v_type = 'TRANSFER'   AND v_status IN ('IN_TRANSIT', 'DISCREPANCY'))
          OR (p_kind = 'ADJUST'  AND v_type = 'ADJUSTMENT' AND v_status = 'PENDING')) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu khong o buoc cho phep ghi so nay (chong ghi dup / ghi sai buoc)';
  END IF;

  SELECT voucher_id, item_id, batch_id
    INTO v_line_voucher, v_line_item, v_line_batch
    FROM voucher_lines WHERE id = NEW.voucher_line_id;
  IF v_line_voucher <> NEW.voucher_id
     OR v_line_item <> NEW.item_id
     OR v_line_batch <> NEW.batch_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Phat sinh khong khop voi dong chung tu';
  END IF;
END$$

-- 7.3 Máy trạng thái của chứng từ:
--     - đã kết thúc thì không sửa được nữa
--     - đang chạy (đã duyệt) chỉ được đổi trạng thái + thông tin tiến độ, không sửa nội dung phiếu
--     - chỉ cho các bước chuyển hợp lệ theo từng loại phiếu
CREATE TRIGGER trg_vouchers_before_update BEFORE UPDATE ON vouchers
FOR EACH ROW
BEGIN
  IF OLD.status IN ('COMPLETED', 'CLOSED', 'CANCELLED', 'REJECTED')
     OR (OLD.type = 'ADJUSTMENT' AND OLD.status = 'APPROVED') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da ket thuc: khong duoc sua. Hay lap chung tu dieu chinh';
  END IF;

  IF OLD.status <> 'PENDING' AND (
         NOT (NEW.code <=> OLD.code) OR NOT (NEW.type <=> OLD.type)
      OR NOT (NEW.warehouse_id <=> OLD.warehouse_id) OR NOT (NEW.to_warehouse_id <=> OLD.to_warehouse_id)
      OR NOT (NEW.voucher_date <=> OLD.voucher_date) OR NOT (NEW.reason <=> OLD.reason)
      OR NOT (NEW.created_by <=> OLD.created_by) OR NOT (NEW.approved_by <=> OLD.approved_by)
      OR NOT (NEW.approved_at <=> OLD.approved_at) OR NOT (NEW.reference_voucher_id <=> OLD.reference_voucher_id)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da duyet: khong duoc sua noi dung phieu';
  END IF;

  IF NEW.status <> OLD.status AND CONCAT(OLD.type, ':', OLD.status, '>', NEW.status) NOT IN (
       'IMPORT:PENDING>APPROVED', 'IMPORT:PENDING>REJECTED',
       'IMPORT:APPROVED>PARTIAL', 'IMPORT:APPROVED>COMPLETED', 'IMPORT:APPROVED>CLOSED',
       'IMPORT:PARTIAL>COMPLETED', 'IMPORT:PARTIAL>CLOSED',
       'EXPORT:PENDING>APPROVED', 'EXPORT:PENDING>REJECTED',
       'EXPORT:APPROVED>COMPLETED', 'EXPORT:APPROVED>CANCELLED',
       'TRANSFER:PENDING>APPROVED', 'TRANSFER:PENDING>REJECTED',
       'TRANSFER:APPROVED>IN_TRANSIT', 'TRANSFER:APPROVED>CANCELLED',
       'TRANSFER:IN_TRANSIT>COMPLETED', 'TRANSFER:IN_TRANSIT>DISCREPANCY',
       'TRANSFER:DISCREPANCY>IN_TRANSIT', 'TRANSFER:DISCREPANCY>COMPLETED',
       'ADJUSTMENT:PENDING>APPROVED', 'ADJUSTMENT:PENDING>REJECTED') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Buoc chuyen trang thai chung tu khong hop le';
  END IF;
END$$

CREATE TRIGGER trg_vouchers_before_delete BEFORE DELETE ON vouchers
FOR EACH ROW
BEGIN
  IF OLD.status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da duyet/tu choi: khong duoc xoa';
  END IF;
END$$

-- 7.3b Đợt ghi sổ và dòng của nó là lịch sử: chỉ thêm, không sửa / xóa
CREATE TRIGGER trg_vp_no_update BEFORE UPDATE ON voucher_postings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dot ghi so chi duoc INSERT';
END$$

CREATE TRIGGER trg_vp_no_delete BEFORE DELETE ON voucher_postings
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dot ghi so chi duoc INSERT';
END$$

CREATE TRIGGER trg_vpl_no_update BEFORE UPDATE ON voucher_posting_lines
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dot ghi so chi duoc INSERT';
END$$

CREATE TRIGGER trg_vpl_no_delete BEFORE DELETE ON voucher_posting_lines
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Dot ghi so chi duoc INSERT';
END$$

-- 7.3c Yêu cầu điều chuyển đã xử lý thì khóa
CREATE TRIGGER trg_tr_before_update BEFORE UPDATE ON transfer_requests
FOR EACH ROW
BEGIN
  IF OLD.status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Yeu cau dieu chuyen da xu ly: khong duoc sua';
  END IF;
END$$

-- 7.4 Dòng chứng từ: khóa khi chứng từ không còn PENDING + kiểm tra dấu số lượng
CREATE TRIGGER trg_vl_before_insert BEFORE INSERT ON voucher_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  DECLARE v_type VARCHAR(12);
  SELECT status, type INTO v_status, v_type FROM vouchers WHERE id = NEW.voucher_id;
  IF v_status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu khong con PENDING: khong duoc them dong';
  END IF;
  IF v_type <> 'ADJUSTMENT' AND NEW.quantity <= 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'So luong phai > 0 (chi phieu ADJUSTMENT moi duoc am)';
  END IF;
END$$

-- Phiếu đang chạy (đã duyệt, chưa kết thúc): chỉ được cập nhật cột tiến độ, không sửa hàng / lô / số lượng
CREATE TRIGGER trg_vl_before_update BEFORE UPDATE ON voucher_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(12);
  DECLARE v_type VARCHAR(12);
  SELECT status, type INTO v_status, v_type FROM vouchers WHERE id = OLD.voucher_id;
  IF NEW.voucher_id <> OLD.voucher_id
     OR v_status IN ('COMPLETED', 'CLOSED', 'CANCELLED', 'REJECTED')
     OR (v_type = 'ADJUSTMENT' AND v_status = 'APPROVED') THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da ket thuc: khong duoc sua dong';
  END IF;
  IF v_status <> 'PENDING' AND (NEW.item_id <> OLD.item_id OR NEW.batch_id <> OLD.batch_id
                                OR NEW.quantity <> OLD.quantity OR NOT (NEW.note <=> OLD.note)) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu khong con PENDING: chi duoc cap nhat tien do';
  END IF;
  IF v_type <> 'ADJUSTMENT' AND NEW.quantity <= 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'So luong phai > 0 (chi phieu ADJUSTMENT moi duoc am)';
  END IF;
END$$

CREATE TRIGGER trg_vl_before_delete BEFORE DELETE ON voucher_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  SELECT status INTO v_status FROM vouchers WHERE id = OLD.voucher_id;
  IF v_status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu khong con PENDING: khong duoc xoa dong';
  END IF;
END$$

-- 7.5 Đợt kiểm kê đã chốt thì không thêm / sửa số đếm
CREATE TRIGGER trg_scl_before_insert BEFORE INSERT ON stock_count_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  SELECT status INTO v_status FROM stock_counts WHERE id = NEW.count_id;
  IF v_status <> 'COUNTING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Dot kiem ke da chot: khong duoc them dong';
  END IF;
END$$

CREATE TRIGGER trg_scl_before_update BEFORE UPDATE ON stock_count_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  SELECT status INTO v_status FROM stock_counts WHERE id = OLD.count_id;
  IF v_status <> 'COUNTING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Dot kiem ke da chot: khong duoc sua so dem';
  END IF;
END$$

-- 7.6 Đợt kiểm kê đã duyệt / hủy thì không mở lại
CREATE TRIGGER trg_sc_before_update BEFORE UPDATE ON stock_counts
FOR EACH ROW
BEGIN
  IF OLD.status <> 'COUNTING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Dot kiem ke da duyet/huy: khong duoc sua';
  END IF;
END$$

DELIMITER ;

-- =====================================================================
-- 8. VIEW BÁO CÁO / ĐỐI CHIẾU
-- =====================================================================

-- 8.1 Đối chiếu: tồn cache (stocks) vs tổng sổ phát sinh. Lệch => is_matched = 0
CREATE VIEW v_stock_reconciliation AS
SELECT k.warehouse_id, k.item_id, k.batch_id,
       COALESCE(s.quantity, 0)                             AS stock_qty,
       COALESCE(m.ledger_qty, 0)                           AS ledger_qty,
       COALESCE(s.quantity, 0) - COALESCE(m.ledger_qty, 0) AS diff,
       (COALESCE(s.quantity, 0) = COALESCE(m.ledger_qty, 0)) AS is_matched
FROM (
  SELECT warehouse_id, item_id, batch_id FROM stocks
  UNION
  SELECT warehouse_id, item_id, batch_id FROM stock_movements
) k
LEFT JOIN stocks s
       ON s.warehouse_id = k.warehouse_id AND s.item_id = k.item_id AND s.batch_id = k.batch_id
LEFT JOIN (
  SELECT warehouse_id, item_id, batch_id, SUM(quantity) AS ledger_qty
  FROM stock_movements
  GROUP BY warehouse_id, item_id, batch_id
) m    ON m.warehouse_id = k.warehouse_id AND m.item_id = k.item_id AND m.batch_id = k.batch_id;

-- 8.2 Thẻ kho: từng phát sinh kèm số dư lũy kế (lịch sử hàng)
CREATE VIEW v_stock_card AS
SELECT sm.id, sm.moved_at,
       w.code AS warehouse_code, w.name AS warehouse_name,
       i.sku, i.name AS item_name, i.unit,
       b.batch_no, b.expiry_date,
       v.code AS voucher_code, v.type AS voucher_type, v.reason,
       p.code AS posting_code, p.kind AS posting_kind,
       CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END  AS qty_in,
       CASE WHEN sm.quantity < 0 THEN -sm.quantity ELSE 0 END AS qty_out,
       SUM(sm.quantity) OVER (
         PARTITION BY sm.warehouse_id, sm.item_id, sm.batch_id
         ORDER BY sm.moved_at, sm.id
       ) AS balance_after,
       sm.warehouse_id, sm.item_id, sm.batch_id, sm.voucher_id, sm.posting_id, sm.created_by
FROM stock_movements sm
JOIN warehouses w       ON w.id = sm.warehouse_id
JOIN items i            ON i.id = sm.item_id
JOIN batches b          ON b.id = sm.batch_id
JOIN vouchers v         ON v.id = sm.voucher_id
JOIN voucher_postings p ON p.id = sm.posting_id;

-- 8.3 Tồn kho hiện tại (đầy đủ tên) — dùng cho trang danh sách tồn
CREATE VIEW v_current_stock AS
SELECT s.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name, w.is_transit,
       s.item_id, i.sku, i.name AS item_name, i.unit,
       s.batch_id, b.batch_no, b.expiry_date,
       s.quantity, s.reserved, s.quantity - s.reserved AS available, s.updated_at
FROM stocks s
JOIN warehouses w ON w.id = s.warehouse_id
JOIN items i      ON i.id = s.item_id
JOIN batches b    ON b.id = s.batch_id;

-- 8.4 Đối chiếu hàng đang giữ chỗ: stocks.reserved = tổng số lượng các phiếu xuất / điều chuyển
--     đã duyệt nhưng hàng chưa rời kho (trạng thái APPROVED)
CREATE VIEW v_reservation_check AS
SELECT s.warehouse_id, s.item_id, s.batch_id,
       s.reserved                                AS reserved_qty,
       COALESCE(r.expected, 0)                   AS expected_qty,
       (s.reserved = COALESCE(r.expected, 0))    AS is_matched
FROM stocks s
LEFT JOIN (
  SELECT v.warehouse_id, vl.item_id, vl.batch_id, SUM(vl.quantity) AS expected
  FROM vouchers v
  JOIN voucher_lines vl ON vl.voucher_id = v.id
  WHERE v.type IN ('EXPORT', 'TRANSFER') AND v.status = 'APPROVED'
  GROUP BY v.warehouse_id, vl.item_id, vl.batch_id
) r ON r.warehouse_id = s.warehouse_id AND r.item_id = s.item_id AND r.batch_id = s.batch_id;

-- 8.5 Đối chiếu hàng đang đi đường: tồn kho ảo = tổng (đã gửi - đã nhận - hao hụt) của các phiếu điều chuyển
CREATE VIEW v_transit_check AS
SELECT k.item_id, k.batch_id,
       COALESCE(s.quantity, 0)                          AS transit_stock,
       COALESCE(t.expected, 0)                          AS expected_qty,
       (COALESCE(s.quantity, 0) = COALESCE(t.expected, 0)) AS is_matched
FROM (
  SELECT s.item_id, s.batch_id FROM stocks s JOIN warehouses w ON w.id = s.warehouse_id AND w.is_transit = 1
  UNION
  SELECT vl.item_id, vl.batch_id FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.type = 'TRANSFER'
) k
LEFT JOIN (
  SELECT s.item_id, s.batch_id, SUM(s.quantity) AS quantity
  FROM stocks s JOIN warehouses w ON w.id = s.warehouse_id AND w.is_transit = 1
  GROUP BY s.item_id, s.batch_id
) s ON s.item_id = k.item_id AND s.batch_id = k.batch_id
LEFT JOIN (
  SELECT vl.item_id, vl.batch_id, SUM(vl.shipped_qty - vl.received_qty - vl.lost_qty) AS expected
  FROM voucher_lines vl JOIN vouchers v ON v.id = vl.voucher_id AND v.type = 'TRANSFER'
  GROUP BY vl.item_id, vl.batch_id
) t ON t.item_id = k.item_id AND t.batch_id = k.batch_id;
