-- =====================================================================
--  FILE TỰ SINH - KHÔNG SỬA TAY.
--  Sửa database/schema.sql hoặc database/build_database.php rồi chạy:
--      php database/build_database.php
--  Import: phpMyAdmin > Nhập (Import)  hoặc  mysql -u root -p < quanlykhohang.sql
--  Mật khẩu của TẤT CẢ tài khoản mẫu: password
-- =====================================================================

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

-- =====================================================================
-- 9. DỮ LIỆU MẪU
-- =====================================================================

INSERT INTO users (id, username, password_hash, full_name, email, role) VALUES
(1, 'admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Quản trị hệ thống', 'admin@kho.local', 'ADMIN'),
(2, 'quanly', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Nguyễn Văn Quản Lý', 'quanly@kho.local', 'MANAGER'),
(3, 'nhanvien_a', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Trần Thị A (Kho HN)', 'nva@kho.local', 'STAFF'),
(4, 'nhanvien_b', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Lê Văn B (Kho HCM)', 'nvb@kho.local', 'STAFF'),
(5, 'nhanvien_c', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Phạm Thị C (Kho ĐN)', 'nvc@kho.local', 'STAFF');

INSERT INTO warehouses (id, code, name, address) VALUES
(1, 'KHO-HN', 'Kho Hà Nội', 'Số 1 Đại Cồ Việt, Hai Bà Trưng, Hà Nội'),
(2, 'KHO-HCM', 'Kho TP.HCM', 'Số 10 Nguyễn Huệ, Quận 1, TP.HCM'),
(3, 'KHO-DN', 'Kho Đà Nẵng', 'Số 5 Bạch Đằng, Hải Châu, Đà Nẵng');

-- Kho ảo cho hàng đang trên đường điều chuyển (không ai được phân quyền trực tiếp)
INSERT INTO warehouses (id, code, name, address, is_transit) VALUES
(4, 'TRANSIT', 'Hàng đang đi đường', NULL, 1);

INSERT INTO user_warehouses (user_id, warehouse_id) VALUES
(1, 1),
(1, 2),
(1, 3),
(2, 1),
(2, 2),
(2, 3),
(3, 1),
(4, 2),
(5, 3);

INSERT INTO items (id, sku, name, unit) VALUES
(1, 'SP001', 'Sữa tươi Vinamilk 1L', 'Hộp'),
(2, 'SP002', 'Mì Hảo Hảo tôm chua cay', 'Thùng'),
(3, 'SP003', 'Nước mắm Nam Ngư 500ml', 'Chai'),
(4, 'SP004', 'Gạo ST25 5kg', 'Bao'),
(5, 'SP005', 'Dầu ăn Neptune 1L', 'Chai'),
(6, 'SP006', 'Đường Biên Hòa 1kg', 'Gói'),
(7, 'SP007', 'Bột ngọt Ajinomoto 400g', 'Gói'),
(8, 'SP008', 'Nước tương Chinsu 500ml', 'Chai'),
(9, 'SP009', 'Cà phê G7 3in1', 'Hộp'),
(10, 'SP010', 'Trà Lipton túi lọc', 'Hộp'),
(11, 'SP011', 'Bánh quy Cosy 400g', 'Hộp'),
(12, 'SP012', 'Sữa đặc Ông Thọ 380g', 'Lon'),
(13, 'SP013', 'Nước suối Lavie 500ml', 'Thùng'),
(14, 'SP014', 'Coca-Cola 330ml', 'Thùng'),
(15, 'SP015', 'Bia Saigon Special 330ml', 'Thùng'),
(16, 'SP016', 'Nước giặt OMO 3.7kg', 'Can'),
(17, 'SP017', 'Nước rửa chén Sunlight 750ml', 'Chai'),
(18, 'SP018', 'Dầu gội Clear 650g', 'Chai'),
(19, 'SP019', 'Kem đánh răng P/S 230g', 'Tuýp'),
(20, 'SP020', 'Giấy vệ sinh Pulppy 10 cuộn', 'Lốc'),
(21, 'SP021', 'Xúc xích Vissan 175g', 'Gói'),
(22, 'SP022', 'Cá hộp 3 Cô Gái 190g', 'Lon'),
(23, 'SP023', 'Bột giặt Tide 2.25kg', 'Túi'),
(24, 'SP024', 'Sữa chua Vinamilk 100g', 'Lốc'),
(25, 'SP025', 'Bánh mì sandwich Kinh Đô', 'Gói'),
(26, 'SP026', 'Hạt nêm Knorr 900g', 'Gói'),
(27, 'SP027', 'Tương ớt Chinsu 250g', 'Chai'),
(28, 'SP028', 'Phở bò ăn liền Vifon', 'Thùng'),
(29, 'SP029', 'Nước tăng lực Sting 330ml', 'Thùng'),
(30, 'SP030', 'Khăn giấy An An 180 tờ', 'Gói');

INSERT INTO batches (id, item_id, batch_no, mfg_date, expiry_date) VALUES
(1, 1, 'L2606-01', '2026-06-11', '2026-12-11'),
(2, 1, 'L2608-01', '2026-08-11', '2027-02-11'),
(3, 2, 'L2606-02', '2026-06-20', '2027-02-20'),
(4, 2, 'L2608-02', '2026-08-13', '2027-04-13'),
(5, 3, 'L2606-03', '2026-06-23', '2028-06-23'),
(6, 3, 'L2608-03', '2026-08-18', '2028-08-18'),
(7, 4, 'L2608-04', '2026-08-18', '2027-08-18'),
(8, 5, 'L2606-05', '2026-06-14', '2027-12-14'),
(9, 5, 'L2608-05', '2026-08-17', '2028-02-17'),
(10, 6, 'L2606-06', '2026-06-13', '2028-06-13'),
(11, 6, 'L2608-06', '2026-08-20', '2028-08-20'),
(12, 7, 'L2606-07', '2026-06-15', '2029-06-15'),
(13, 7, 'L2608-07', '2026-08-17', '2029-08-17'),
(14, 8, 'L2608-08', '2026-08-17', '2028-02-17'),
(15, 9, 'L2606-09', '2026-06-12', '2028-06-12'),
(16, 9, 'L2608-09', '2026-08-11', '2028-08-11'),
(17, 10, 'L2606-10', '2026-06-23', '2028-06-23'),
(18, 10, 'L2608-10', '2026-08-16', '2028-08-16'),
(19, 11, 'L2606-11', '2026-06-10', '2027-06-10'),
(20, 11, 'L2608-11', '2026-08-14', '2027-08-14'),
(21, 12, 'L2608-12', '2026-08-12', '2027-08-12'),
(22, 13, 'L2606-13', '2026-06-10', '2027-06-10'),
(23, 13, 'L2608-13', '2026-08-12', '2027-08-12'),
(24, 14, 'L2606-14', '2026-06-25', '2027-03-25'),
(25, 14, 'L2608-14', '2026-08-09', '2027-05-09'),
(26, 15, 'L2606-15', '2026-06-11', '2027-06-11'),
(27, 15, 'L2608-15', '2026-08-05', '2027-08-05'),
(28, 16, 'L2608-16', '2026-08-11', '2029-08-11'),
(29, 17, 'L2606-17', '2026-06-19', '2029-06-19'),
(30, 17, 'L2608-17', '2026-08-09', '2029-08-09'),
(31, 18, 'L2606-18', '2026-06-13', '2029-06-13'),
(32, 18, 'L2608-18', '2026-08-05', '2029-08-05'),
(33, 19, 'L2606-19', '2026-06-22', '2029-06-22'),
(34, 19, 'L2608-19', '2026-08-06', '2029-08-06'),
(35, 20, 'L2608-20', '2026-08-15', '2031-08-15'),
(36, 21, 'L2606-21', '2026-06-19', '2026-12-19'),
(37, 21, 'L2608-21', '2026-08-09', '2027-02-09'),
(38, 22, 'L2606-22', '2026-06-25', '2029-06-25'),
(39, 22, 'L2608-22', '2026-08-20', '2029-08-20'),
(40, 23, 'L2606-23', '2026-06-23', '2029-06-23'),
(41, 23, 'L2608-23', '2026-08-09', '2029-08-09'),
(42, 24, 'L2608-24', '2026-08-14', '2026-10-14'),
(43, 25, 'L2606-25', '2026-06-25', '2026-09-25'),
(44, 25, 'L2608-25', '2026-08-18', '2026-11-18'),
(45, 26, 'L2606-26', '2026-06-16', '2027-12-16'),
(46, 26, 'L2608-26', '2026-08-11', '2028-02-11'),
(47, 27, 'L2606-27', '2026-06-19', '2027-12-19'),
(48, 27, 'L2608-27', '2026-08-13', '2028-02-13'),
(49, 28, 'L2608-28', '2026-08-10', '2027-04-10'),
(50, 29, 'L2606-29', '2026-06-10', '2027-06-10'),
(51, 29, 'L2608-29', '2026-08-20', '2027-08-20'),
(52, 30, 'L2606-30', '2026-06-13', '2031-06-13'),
(53, 30, 'L2608-30', '2026-08-09', '2031-08-09'),
(54, 3, 'L2610-TEST', '2026-10-01', '2028-10-01');

-- Chứng từ: lập PENDING -> duyệt -> từng đợt ghi sổ -> trạng thái cuối (đúng thứ tự trigger yêu cầu)
INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (1, 'PN-2026-0001', 'IMPORT', 1, NULL, NULL, '2026-08-01', 'Nhập tồn đầu kỳ Kho Hà Nội', 3, '2026-08-01 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(1, 1, 23, 41, 90),
(2, 1, 29, 50, 260),
(3, 1, 18, 31, 160),
(4, 1, 25, 43, 190),
(5, 1, 27, 48, 120),
(6, 1, 12, 21, 280),
(7, 1, 14, 25, 280),
(8, 1, 10, 18, 270),
(9, 1, 25, 44, 240),
(10, 1, 27, 47, 90),
(11, 1, 30, 52, 240),
(12, 1, 29, 51, 220),
(13, 1, 3, 5, 120),
(14, 1, 17, 29, 220);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-01 10:00:00' WHERE id = 1;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (1, 1, 'PN-2026-0001/1', 'RECEIVE', NULL, 3, '2026-08-01 10:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(1, 1, 90, 0),
(1, 2, 260, 0),
(1, 3, 160, 0),
(1, 4, 190, 0),
(1, 5, 120, 0),
(1, 6, 280, 0),
(1, 7, 280, 0),
(1, 8, 270, 0),
(1, 9, 240, 0),
(1, 10, 90, 0),
(1, 11, 240, 0),
(1, 12, 220, 0),
(1, 13, 120, 0),
(1, 14, 220, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(1, 1, 1, 1, 23, 41, 90, '2026-08-01 10:00:00', 3),
(1, 1, 2, 1, 29, 50, 260, '2026-08-01 10:00:00', 3),
(1, 1, 3, 1, 18, 31, 160, '2026-08-01 10:00:00', 3),
(1, 1, 4, 1, 25, 43, 190, '2026-08-01 10:00:00', 3),
(1, 1, 5, 1, 27, 48, 120, '2026-08-01 10:00:00', 3),
(1, 1, 6, 1, 12, 21, 280, '2026-08-01 10:00:00', 3),
(1, 1, 7, 1, 14, 25, 280, '2026-08-01 10:00:00', 3),
(1, 1, 8, 1, 10, 18, 270, '2026-08-01 10:00:00', 3),
(1, 1, 9, 1, 25, 44, 240, '2026-08-01 10:00:00', 3),
(1, 1, 10, 1, 27, 47, 90, '2026-08-01 10:00:00', 3),
(1, 1, 11, 1, 30, 52, 240, '2026-08-01 10:00:00', 3),
(1, 1, 12, 1, 29, 51, 220, '2026-08-01 10:00:00', 3),
(1, 1, 13, 1, 3, 5, 120, '2026-08-01 10:00:00', 3),
(1, 1, 14, 1, 17, 29, 220, '2026-08-01 10:00:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 1;
UPDATE voucher_lines SET received_qty = received_qty + 260 WHERE id = 2;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 3;
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 4;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 5;
UPDATE voucher_lines SET received_qty = received_qty + 280 WHERE id = 6;
UPDATE voucher_lines SET received_qty = received_qty + 280 WHERE id = 7;
UPDATE voucher_lines SET received_qty = received_qty + 270 WHERE id = 8;
UPDATE voucher_lines SET received_qty = received_qty + 240 WHERE id = 9;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 10;
UPDATE voucher_lines SET received_qty = received_qty + 240 WHERE id = 11;
UPDATE voucher_lines SET received_qty = received_qty + 220 WHERE id = 12;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 13;
UPDATE voucher_lines SET received_qty = received_qty + 220 WHERE id = 14;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-01 10:00:00' WHERE id = 1;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (2, 'PN-2026-0002', 'IMPORT', 2, NULL, NULL, '2026-08-02', 'Nhập tồn đầu kỳ Kho TP.HCM', 4, '2026-08-02 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(15, 2, 25, 43, 260),
(16, 2, 7, 13, 180),
(17, 2, 1, 1, 80),
(18, 2, 15, 27, 290),
(19, 2, 23, 41, 90),
(20, 2, 27, 47, 180),
(21, 2, 30, 53, 190),
(22, 2, 29, 50, 120),
(23, 2, 19, 33, 170),
(24, 2, 29, 51, 180),
(25, 2, 30, 52, 120),
(26, 2, 6, 10, 100),
(27, 2, 28, 49, 230),
(28, 2, 17, 29, 160);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-02 10:00:00' WHERE id = 2;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (2, 2, 'PN-2026-0002/1', 'RECEIVE', NULL, 4, '2026-08-02 10:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(2, 15, 260, 0),
(2, 16, 180, 0),
(2, 17, 80, 0),
(2, 18, 290, 0),
(2, 19, 90, 0),
(2, 20, 180, 0),
(2, 21, 190, 0),
(2, 22, 120, 0),
(2, 23, 170, 0),
(2, 24, 180, 0),
(2, 25, 120, 0),
(2, 26, 100, 0),
(2, 27, 230, 0),
(2, 28, 160, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(2, 2, 15, 2, 25, 43, 260, '2026-08-02 10:00:00', 4),
(2, 2, 16, 2, 7, 13, 180, '2026-08-02 10:00:00', 4),
(2, 2, 17, 2, 1, 1, 80, '2026-08-02 10:00:00', 4),
(2, 2, 18, 2, 15, 27, 290, '2026-08-02 10:00:00', 4),
(2, 2, 19, 2, 23, 41, 90, '2026-08-02 10:00:00', 4),
(2, 2, 20, 2, 27, 47, 180, '2026-08-02 10:00:00', 4),
(2, 2, 21, 2, 30, 53, 190, '2026-08-02 10:00:00', 4),
(2, 2, 22, 2, 29, 50, 120, '2026-08-02 10:00:00', 4),
(2, 2, 23, 2, 19, 33, 170, '2026-08-02 10:00:00', 4),
(2, 2, 24, 2, 29, 51, 180, '2026-08-02 10:00:00', 4),
(2, 2, 25, 2, 30, 52, 120, '2026-08-02 10:00:00', 4),
(2, 2, 26, 2, 6, 10, 100, '2026-08-02 10:00:00', 4),
(2, 2, 27, 2, 28, 49, 230, '2026-08-02 10:00:00', 4),
(2, 2, 28, 2, 17, 29, 160, '2026-08-02 10:00:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 260 WHERE id = 15;
UPDATE voucher_lines SET received_qty = received_qty + 180 WHERE id = 16;
UPDATE voucher_lines SET received_qty = received_qty + 80 WHERE id = 17;
UPDATE voucher_lines SET received_qty = received_qty + 290 WHERE id = 18;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 19;
UPDATE voucher_lines SET received_qty = received_qty + 180 WHERE id = 20;
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 21;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 22;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 23;
UPDATE voucher_lines SET received_qty = received_qty + 180 WHERE id = 24;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 25;
UPDATE voucher_lines SET received_qty = received_qty + 100 WHERE id = 26;
UPDATE voucher_lines SET received_qty = received_qty + 230 WHERE id = 27;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 28;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-02 10:00:00' WHERE id = 2;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (3, 'PN-2026-0003', 'IMPORT', 3, NULL, NULL, '2026-08-03', 'Nhập tồn đầu kỳ Kho Đà Nẵng', 5, '2026-08-03 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(29, 3, 4, 7, 190),
(30, 3, 8, 14, 140),
(31, 3, 11, 20, 170),
(32, 3, 6, 11, 140),
(33, 3, 26, 45, 240),
(34, 3, 18, 32, 270),
(35, 3, 9, 16, 170),
(36, 3, 25, 44, 90),
(37, 3, 10, 18, 140),
(38, 3, 6, 10, 280),
(39, 3, 11, 19, 240),
(40, 3, 20, 35, 110),
(41, 3, 12, 21, 170),
(42, 3, 22, 39, 140);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-03 10:00:00' WHERE id = 3;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (3, 3, 'PN-2026-0003/1', 'RECEIVE', NULL, 5, '2026-08-03 10:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(3, 29, 190, 0),
(3, 30, 140, 0),
(3, 31, 170, 0),
(3, 32, 140, 0),
(3, 33, 240, 0),
(3, 34, 270, 0),
(3, 35, 170, 0),
(3, 36, 90, 0),
(3, 37, 140, 0),
(3, 38, 280, 0),
(3, 39, 240, 0),
(3, 40, 110, 0),
(3, 41, 170, 0),
(3, 42, 140, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(3, 3, 29, 3, 4, 7, 190, '2026-08-03 10:00:00', 5),
(3, 3, 30, 3, 8, 14, 140, '2026-08-03 10:00:00', 5),
(3, 3, 31, 3, 11, 20, 170, '2026-08-03 10:00:00', 5),
(3, 3, 32, 3, 6, 11, 140, '2026-08-03 10:00:00', 5),
(3, 3, 33, 3, 26, 45, 240, '2026-08-03 10:00:00', 5),
(3, 3, 34, 3, 18, 32, 270, '2026-08-03 10:00:00', 5),
(3, 3, 35, 3, 9, 16, 170, '2026-08-03 10:00:00', 5),
(3, 3, 36, 3, 25, 44, 90, '2026-08-03 10:00:00', 5),
(3, 3, 37, 3, 10, 18, 140, '2026-08-03 10:00:00', 5),
(3, 3, 38, 3, 6, 10, 280, '2026-08-03 10:00:00', 5),
(3, 3, 39, 3, 11, 19, 240, '2026-08-03 10:00:00', 5),
(3, 3, 40, 3, 20, 35, 110, '2026-08-03 10:00:00', 5),
(3, 3, 41, 3, 12, 21, 170, '2026-08-03 10:00:00', 5),
(3, 3, 42, 3, 22, 39, 140, '2026-08-03 10:00:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 29;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 30;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 31;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 32;
UPDATE voucher_lines SET received_qty = received_qty + 240 WHERE id = 33;
UPDATE voucher_lines SET received_qty = received_qty + 270 WHERE id = 34;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 35;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 36;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 37;
UPDATE voucher_lines SET received_qty = received_qty + 280 WHERE id = 38;
UPDATE voucher_lines SET received_qty = received_qty + 240 WHERE id = 39;
UPDATE voucher_lines SET received_qty = received_qty + 110 WHERE id = 40;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 41;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 42;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-03 10:00:00' WHERE id = 3;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (4, 'PX-2026-0001', 'EXPORT', 1, NULL, NULL, '2026-08-05', 'Xuất bán cho Siêu thị Hòa Bình', 3, '2026-08-05 08:43:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(43, 4, 23, 41, 2),
(44, 4, 30, 52, 17),
(45, 4, 25, 44, 52),
(46, 4, 14, 25, 11);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-05 16:56:00' WHERE id = 4;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (4, 4, 'PX-2026-0001/1', 'SHIP', NULL, 3, '2026-08-05 16:56:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(4, 43, 2, 0),
(4, 44, 17, 0),
(4, 45, 52, 0),
(4, 46, 11, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(4, 4, 43, 1, 23, 41, -2, '2026-08-05 16:56:00', 3),
(4, 4, 44, 1, 30, 52, -17, '2026-08-05 16:56:00', 3),
(4, 4, 45, 1, 25, 44, -52, '2026-08-05 16:56:00', 3),
(4, 4, 46, 1, 14, 25, -11, '2026-08-05 16:56:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 43;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 44;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 52 WHERE id = 45;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 11 WHERE id = 46;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-05 16:56:00', completed_at = '2026-08-05 16:56:00' WHERE id = 4;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (5, 'PX-2026-0002', 'EXPORT', 3, NULL, NULL, '2026-08-06', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-06 09:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(47, 5, 20, 35, 14),
(48, 5, 6, 11, 28),
(49, 5, 25, 44, 19),
(50, 5, 11, 19, 43);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-06 13:45:00' WHERE id = 5;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (5, 5, 'PX-2026-0002/1', 'SHIP', NULL, 5, '2026-08-06 13:45:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(5, 47, 14, 0),
(5, 48, 28, 0),
(5, 49, 19, 0),
(5, 50, 43, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(5, 5, 47, 3, 20, 35, -14, '2026-08-06 13:45:00', 5),
(5, 5, 48, 3, 6, 11, -28, '2026-08-06 13:45:00', 5),
(5, 5, 49, 3, 25, 44, -19, '2026-08-06 13:45:00', 5),
(5, 5, 50, 3, 11, 19, -43, '2026-08-06 13:45:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 14 WHERE id = 47;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 28 WHERE id = 48;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 19 WHERE id = 49;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 43 WHERE id = 50;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-06 13:45:00', completed_at = '2026-08-06 13:45:00' WHERE id = 5;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (6, 'PX-2026-0003', 'EXPORT', 2, NULL, NULL, '2026-08-07', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-07 10:14:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(51, 6, 29, 51, 39),
(52, 6, 25, 43, 15);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-07 14:35:00' WHERE id = 6;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (6, 6, 'PX-2026-0003/1', 'SHIP', NULL, 4, '2026-08-07 14:35:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(6, 51, 39, 0),
(6, 52, 15, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(6, 6, 51, 2, 29, 51, -39, '2026-08-07 14:35:00', 4),
(6, 6, 52, 2, 25, 43, -15, '2026-08-07 14:35:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 39 WHERE id = 51;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 15 WHERE id = 52;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-07 14:35:00', completed_at = '2026-08-07 14:35:00' WHERE id = 6;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (7, 'PX-2026-0004', 'EXPORT', 3, NULL, NULL, '2026-08-08', 'Xuất bán cho Cửa hàng Thành Công', 5, '2026-08-08 08:46:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(53, 7, 8, 14, 21),
(54, 7, 11, 20, 26),
(55, 7, 20, 35, 2),
(56, 7, 6, 10, 21);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-08 16:21:00' WHERE id = 7;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (7, 7, 'PX-2026-0004/1', 'SHIP', NULL, 5, '2026-08-08 16:21:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(7, 53, 21, 0),
(7, 54, 26, 0),
(7, 55, 2, 0),
(7, 56, 21, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(7, 7, 53, 3, 8, 14, -21, '2026-08-08 16:21:00', 5),
(7, 7, 54, 3, 11, 20, -26, '2026-08-08 16:21:00', 5),
(7, 7, 55, 3, 20, 35, -2, '2026-08-08 16:21:00', 5),
(7, 7, 56, 3, 6, 10, -21, '2026-08-08 16:21:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 21 WHERE id = 53;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 26 WHERE id = 54;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 55;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 21 WHERE id = 56;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-08 16:21:00', completed_at = '2026-08-08 16:21:00' WHERE id = 7;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (8, 'PN-2026-0004', 'IMPORT', 2, NULL, NULL, '2026-08-09', 'Nhập hàng từ NCC Kinh Đô', 4, '2026-08-09 09:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(57, 8, 10, 17, 100),
(58, 8, 20, 35, 150),
(59, 8, 24, 42, 140);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-09 12:15:00' WHERE id = 8;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (8, 8, 'PN-2026-0004/1', 'RECEIVE', NULL, 4, '2026-08-09 12:15:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(8, 57, 100, 0),
(8, 58, 150, 0),
(8, 59, 140, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(8, 8, 57, 2, 10, 17, 100, '2026-08-09 12:15:00', 4),
(8, 8, 58, 2, 20, 35, 150, '2026-08-09 12:15:00', 4),
(8, 8, 59, 2, 24, 42, 140, '2026-08-09 12:15:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 100 WHERE id = 57;
UPDATE voucher_lines SET received_qty = received_qty + 150 WHERE id = 58;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 59;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-09 12:15:00' WHERE id = 8;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (9, 'PC-2026-0001', 'TRANSFER', 3, 1, NULL, '2026-08-10', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-10 09:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(60, 9, 12, 21, 50),
(61, 9, 9, 16, 55);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-10 13:44:00' WHERE id = 9;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (9, 9, 'PC-2026-0001/1', 'SEND', NULL, 5, '2026-08-10 13:44:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(9, 60, 50, 0),
(9, 61, 55, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(9, 9, 60, 3, 12, 21, -50, '2026-08-10 13:44:00', 5),
(9, 9, 60, 4, 12, 21, 50, '2026-08-10 13:44:00', 5),
(9, 9, 61, 3, 9, 16, -55, '2026-08-10 13:44:00', 5),
(9, 9, 61, 4, 9, 16, 55, '2026-08-10 13:44:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 50 WHERE id = 60;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 55 WHERE id = 61;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-10 13:44:00' WHERE id = 9;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (10, 9, 'PC-2026-0001/2', 'ARRIVE', NULL, 3, '2026-08-10 15:14:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(10, 60, 50, 0),
(10, 61, 55, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(9, 10, 60, 4, 12, 21, -50, '2026-08-10 15:14:00', 3),
(9, 10, 60, 1, 12, 21, 50, '2026-08-10 15:14:00', 3),
(9, 10, 61, 4, 9, 16, -55, '2026-08-10 15:14:00', 3),
(9, 10, 61, 1, 9, 16, 55, '2026-08-10 15:14:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 60;
UPDATE voucher_lines SET received_qty = received_qty + 55 WHERE id = 61;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-10 15:14:00' WHERE id = 9;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (10, 'PC-2026-0002', 'TRANSFER', 3, 1, NULL, '2026-08-11', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-11 08:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(62, 10, 18, 32, 86);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-11 16:07:00' WHERE id = 10;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (11, 10, 'PC-2026-0002/1', 'SEND', NULL, 5, '2026-08-11 16:07:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(11, 62, 86, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(10, 11, 62, 3, 18, 32, -86, '2026-08-11 16:07:00', 5),
(10, 11, 62, 4, 18, 32, 86, '2026-08-11 16:07:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 86 WHERE id = 62;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-11 16:07:00' WHERE id = 10;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (12, 10, 'PC-2026-0002/2', 'ARRIVE', NULL, 3, '2026-08-11 17:37:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(12, 62, 86, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(10, 12, 62, 4, 18, 32, -86, '2026-08-11 17:37:00', 3),
(10, 12, 62, 1, 18, 32, 86, '2026-08-11 17:37:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 86 WHERE id = 62;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-11 17:37:00' WHERE id = 10;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (11, 'PX-2026-0005', 'EXPORT', 2, NULL, NULL, '2026-08-12', 'Xuất bán cho Đại lý Minh Anh', 4, '2026-08-12 10:20:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(63, 11, 29, 50, 1),
(64, 11, 27, 47, 19),
(65, 11, 20, 35, 23),
(66, 11, 24, 42, 21);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-12 13:55:00' WHERE id = 11;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (13, 11, 'PX-2026-0005/1', 'SHIP', NULL, 4, '2026-08-12 13:55:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(13, 63, 1, 0),
(13, 64, 19, 0),
(13, 65, 23, 0),
(13, 66, 21, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(11, 13, 63, 2, 29, 50, -1, '2026-08-12 13:55:00', 4),
(11, 13, 64, 2, 27, 47, -19, '2026-08-12 13:55:00', 4),
(11, 13, 65, 2, 20, 35, -23, '2026-08-12 13:55:00', 4),
(11, 13, 66, 2, 24, 42, -21, '2026-08-12 13:55:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 63;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 19 WHERE id = 64;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 23 WHERE id = 65;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 21 WHERE id = 66;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-12 13:55:00', completed_at = '2026-08-12 13:55:00' WHERE id = 11;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (12, 'PN-2026-0005', 'IMPORT', 1, NULL, NULL, '2026-08-13', 'Nhập hàng từ NCC Coca-Cola VN', 3, '2026-08-13 10:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(67, 12, 10, 18, 130),
(68, 12, 1, 2, 190),
(69, 12, 9, 15, 140);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-13 11:04:00' WHERE id = 12;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (14, 12, 'PN-2026-0005/1', 'RECEIVE', NULL, 3, '2026-08-13 11:04:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(14, 67, 130, 0),
(14, 68, 190, 0),
(14, 69, 140, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(12, 14, 67, 1, 10, 18, 130, '2026-08-13 11:04:00', 3),
(12, 14, 68, 1, 1, 2, 190, '2026-08-13 11:04:00', 3),
(12, 14, 69, 1, 9, 15, 140, '2026-08-13 11:04:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 130 WHERE id = 67;
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 68;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 69;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-13 11:04:00' WHERE id = 12;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (13, 'PX-2026-0006', 'EXPORT', 3, NULL, NULL, '2026-08-14', 'Xuất bán cho Đại lý Minh Anh', 5, '2026-08-14 09:08:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(70, 13, 8, 14, 2),
(71, 13, 18, 32, 30),
(72, 13, 9, 16, 4),
(73, 13, 25, 44, 9);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-14 13:17:00' WHERE id = 13;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (15, 13, 'PX-2026-0006/1', 'SHIP', NULL, 5, '2026-08-14 13:17:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(15, 70, 2, 0),
(15, 71, 30, 0),
(15, 72, 4, 0),
(15, 73, 9, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(13, 15, 70, 3, 8, 14, -2, '2026-08-14 13:17:00', 5),
(13, 15, 71, 3, 18, 32, -30, '2026-08-14 13:17:00', 5),
(13, 15, 72, 3, 9, 16, -4, '2026-08-14 13:17:00', 5),
(13, 15, 73, 3, 25, 44, -9, '2026-08-14 13:17:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 70;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 30 WHERE id = 71;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 72;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 9 WHERE id = 73;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-14 13:17:00', completed_at = '2026-08-14 13:17:00' WHERE id = 13;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (14, 'PX-2026-0007', 'EXPORT', 3, NULL, NULL, '2026-08-15', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-08-15 09:56:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(74, 14, 9, 16, 4),
(75, 14, 22, 39, 25),
(76, 14, 11, 20, 30),
(77, 14, 12, 21, 4);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-15 14:02:00' WHERE id = 14;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (16, 14, 'PX-2026-0007/1', 'SHIP', NULL, 5, '2026-08-15 14:02:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(16, 74, 4, 0),
(16, 75, 25, 0),
(16, 76, 30, 0),
(16, 77, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(14, 16, 74, 3, 9, 16, -4, '2026-08-15 14:02:00', 5),
(14, 16, 75, 3, 22, 39, -25, '2026-08-15 14:02:00', 5),
(14, 16, 76, 3, 11, 20, -30, '2026-08-15 14:02:00', 5),
(14, 16, 77, 3, 12, 21, -4, '2026-08-15 14:02:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 74;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 25 WHERE id = 75;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 30 WHERE id = 76;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 77;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-15 14:02:00', completed_at = '2026-08-15 14:02:00' WHERE id = 14;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (15, 'PX-2026-0008', 'EXPORT', 2, NULL, NULL, '2026-08-16', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-16 10:31:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(78, 15, 19, 33, 7),
(79, 15, 17, 29, 12),
(80, 15, 24, 42, 6),
(81, 15, 30, 53, 5);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-16 14:15:00' WHERE id = 15;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (17, 15, 'PX-2026-0008/1', 'SHIP', NULL, 4, '2026-08-16 14:15:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(17, 78, 7, 0),
(17, 79, 12, 0),
(17, 80, 6, 0),
(17, 81, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(15, 17, 78, 2, 19, 33, -7, '2026-08-16 14:15:00', 4),
(15, 17, 79, 2, 17, 29, -12, '2026-08-16 14:15:00', 4),
(15, 17, 80, 2, 24, 42, -6, '2026-08-16 14:15:00', 4),
(15, 17, 81, 2, 30, 53, -5, '2026-08-16 14:15:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 78;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 12 WHERE id = 79;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 80;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 81;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-16 14:15:00', completed_at = '2026-08-16 14:15:00' WHERE id = 15;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (16, 'PN-2026-0006', 'IMPORT', 2, NULL, NULL, '2026-08-17', 'Nhập hàng từ NCC Coca-Cola VN', 4, '2026-08-17 09:03:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(82, 16, 22, 38, 70),
(83, 16, 6, 11, 130),
(84, 16, 27, 47, 130);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-17 11:12:00' WHERE id = 16;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (18, 16, 'PN-2026-0006/1', 'RECEIVE', NULL, 4, '2026-08-17 11:12:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(18, 82, 70, 0),
(18, 83, 130, 0),
(18, 84, 130, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(16, 18, 82, 2, 22, 38, 70, '2026-08-17 11:12:00', 4),
(16, 18, 83, 2, 6, 11, 130, '2026-08-17 11:12:00', 4),
(16, 18, 84, 2, 27, 47, 130, '2026-08-17 11:12:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 70 WHERE id = 82;
UPDATE voucher_lines SET received_qty = received_qty + 130 WHERE id = 83;
UPDATE voucher_lines SET received_qty = received_qty + 130 WHERE id = 84;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-17 11:12:00' WHERE id = 16;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (17, 'PC-2026-0003', 'TRANSFER', 3, 2, NULL, '2026-08-18', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-08-18 10:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(85, 17, 9, 16, 21);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-18 14:30:00' WHERE id = 17;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (19, 17, 'PC-2026-0003/1', 'SEND', NULL, 5, '2026-08-18 14:30:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(19, 85, 21, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(17, 19, 85, 3, 9, 16, -21, '2026-08-18 14:30:00', 5),
(17, 19, 85, 4, 9, 16, 21, '2026-08-18 14:30:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 21 WHERE id = 85;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-18 14:30:00' WHERE id = 17;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (20, 17, 'PC-2026-0003/2', 'ARRIVE', NULL, 4, '2026-08-18 16:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(20, 85, 21, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(17, 20, 85, 4, 9, 16, -21, '2026-08-18 16:00:00', 4),
(17, 20, 85, 2, 9, 16, 21, '2026-08-18 16:00:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 21 WHERE id = 85;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-18 16:00:00' WHERE id = 17;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (18, 'PN-2026-0007', 'IMPORT', 1, NULL, NULL, '2026-08-19', 'Nhập hàng từ NCC Sabeco', 3, '2026-08-19 10:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(86, 18, 24, 42, 170),
(87, 18, 5, 9, 140),
(88, 18, 13, 22, 60),
(89, 18, 15, 27, 160);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-19 11:18:00' WHERE id = 18;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (21, 18, 'PN-2026-0007/1', 'RECEIVE', NULL, 3, '2026-08-19 11:18:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(21, 86, 170, 0),
(21, 87, 140, 0),
(21, 88, 60, 0),
(21, 89, 160, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(18, 21, 86, 1, 24, 42, 170, '2026-08-19 11:18:00', 3),
(18, 21, 87, 1, 5, 9, 140, '2026-08-19 11:18:00', 3),
(18, 21, 88, 1, 13, 22, 60, '2026-08-19 11:18:00', 3),
(18, 21, 89, 1, 15, 27, 160, '2026-08-19 11:18:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 86;
UPDATE voucher_lines SET received_qty = received_qty + 140 WHERE id = 87;
UPDATE voucher_lines SET received_qty = received_qty + 60 WHERE id = 88;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 89;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-19 11:18:00' WHERE id = 18;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (19, 'PC-2026-0004', 'TRANSFER', 2, 1, NULL, '2026-08-20', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 4, '2026-08-20 10:49:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(90, 19, 25, 43, 71);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-20 15:21:00' WHERE id = 19;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (22, 19, 'PC-2026-0004/1', 'SEND', NULL, 4, '2026-08-20 15:21:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(22, 90, 71, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(19, 22, 90, 2, 25, 43, -71, '2026-08-20 15:21:00', 4),
(19, 22, 90, 4, 25, 43, 71, '2026-08-20 15:21:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 71 WHERE id = 90;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-20 15:21:00' WHERE id = 19;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (23, 19, 'PC-2026-0004/2', 'ARRIVE', NULL, 3, '2026-08-20 16:51:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(23, 90, 71, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(19, 23, 90, 4, 25, 43, -71, '2026-08-20 16:51:00', 3),
(19, 23, 90, 1, 25, 43, 71, '2026-08-20 16:51:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 71 WHERE id = 90;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-20 16:51:00' WHERE id = 19;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (20, 'PC-2026-0005', 'TRANSFER', 3, 2, NULL, '2026-08-21', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-08-21 08:29:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(91, 20, 25, 44, 6),
(92, 20, 6, 10, 55),
(93, 20, 12, 21, 29);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-21 14:52:00' WHERE id = 20;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (24, 20, 'PC-2026-0005/1', 'SEND', NULL, 5, '2026-08-21 14:52:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(24, 91, 6, 0),
(24, 92, 55, 0),
(24, 93, 29, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(20, 24, 91, 3, 25, 44, -6, '2026-08-21 14:52:00', 5),
(20, 24, 91, 4, 25, 44, 6, '2026-08-21 14:52:00', 5),
(20, 24, 92, 3, 6, 10, -55, '2026-08-21 14:52:00', 5),
(20, 24, 92, 4, 6, 10, 55, '2026-08-21 14:52:00', 5),
(20, 24, 93, 3, 12, 21, -29, '2026-08-21 14:52:00', 5),
(20, 24, 93, 4, 12, 21, 29, '2026-08-21 14:52:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 91;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 55 WHERE id = 92;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 29 WHERE id = 93;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-21 14:52:00' WHERE id = 20;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (25, 20, 'PC-2026-0005/2', 'ARRIVE', NULL, 4, '2026-08-21 16:22:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(25, 91, 6, 0),
(25, 92, 55, 0),
(25, 93, 29, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(20, 25, 91, 4, 25, 44, -6, '2026-08-21 16:22:00', 4),
(20, 25, 91, 2, 25, 44, 6, '2026-08-21 16:22:00', 4),
(20, 25, 92, 4, 6, 10, -55, '2026-08-21 16:22:00', 4),
(20, 25, 92, 2, 6, 10, 55, '2026-08-21 16:22:00', 4),
(20, 25, 93, 4, 12, 21, -29, '2026-08-21 16:22:00', 4),
(20, 25, 93, 2, 12, 21, 29, '2026-08-21 16:22:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 6 WHERE id = 91;
UPDATE voucher_lines SET received_qty = received_qty + 55 WHERE id = 92;
UPDATE voucher_lines SET received_qty = received_qty + 29 WHERE id = 93;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-21 16:22:00' WHERE id = 20;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (21, 'PX-2026-0009', 'EXPORT', 3, NULL, NULL, '2026-08-22', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-08-22 10:49:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(94, 21, 11, 19, 9),
(95, 21, 20, 35, 14),
(96, 21, 6, 11, 26),
(97, 21, 4, 7, 12);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-22 14:00:00' WHERE id = 21;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (26, 21, 'PX-2026-0009/1', 'SHIP', NULL, 5, '2026-08-22 14:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(26, 94, 9, 0),
(26, 95, 14, 0),
(26, 96, 26, 0),
(26, 97, 12, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(21, 26, 94, 3, 11, 19, -9, '2026-08-22 14:00:00', 5),
(21, 26, 95, 3, 20, 35, -14, '2026-08-22 14:00:00', 5),
(21, 26, 96, 3, 6, 11, -26, '2026-08-22 14:00:00', 5),
(21, 26, 97, 3, 4, 7, -12, '2026-08-22 14:00:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 9 WHERE id = 94;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 14 WHERE id = 95;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 26 WHERE id = 96;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 12 WHERE id = 97;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-22 14:00:00', completed_at = '2026-08-22 14:00:00' WHERE id = 21;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (22, 'PC-2026-0006', 'TRANSFER', 3, 1, NULL, '2026-08-23', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-23 10:16:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(98, 22, 26, 45, 64);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-23 15:40:00' WHERE id = 22;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (27, 22, 'PC-2026-0006/1', 'SEND', NULL, 5, '2026-08-23 15:40:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(27, 98, 64, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(22, 27, 98, 3, 26, 45, -64, '2026-08-23 15:40:00', 5),
(22, 27, 98, 4, 26, 45, 64, '2026-08-23 15:40:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 64 WHERE id = 98;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-08-23 15:40:00' WHERE id = 22;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (28, 22, 'PC-2026-0006/2', 'ARRIVE', NULL, 3, '2026-08-23 17:10:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(28, 98, 64, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(22, 28, 98, 4, 26, 45, -64, '2026-08-23 17:10:00', 3),
(22, 28, 98, 1, 26, 45, 64, '2026-08-23 17:10:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 64 WHERE id = 98;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-23 17:10:00' WHERE id = 22;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (23, 'PX-2026-0010', 'EXPORT', 3, NULL, NULL, '2026-08-24', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-24 11:20:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(99, 23, 11, 19, 7),
(100, 23, 8, 14, 17),
(101, 23, 6, 10, 34);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-24 13:46:00' WHERE id = 23;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (29, 23, 'PX-2026-0010/1', 'SHIP', NULL, 5, '2026-08-24 13:46:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(29, 99, 7, 0),
(29, 100, 17, 0),
(29, 101, 34, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(23, 29, 99, 3, 11, 19, -7, '2026-08-24 13:46:00', 5),
(23, 29, 100, 3, 8, 14, -17, '2026-08-24 13:46:00', 5),
(23, 29, 101, 3, 6, 10, -34, '2026-08-24 13:46:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 99;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 100;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 34 WHERE id = 101;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-24 13:46:00', completed_at = '2026-08-24 13:46:00' WHERE id = 23;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (24, 'PX-2026-0011', 'EXPORT', 1, NULL, NULL, '2026-08-25', 'Xuất bán cho Mini mart 24h', 3, '2026-08-25 08:03:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(102, 24, 9, 15, 5),
(103, 24, 18, 32, 6),
(104, 24, 18, 31, 2),
(105, 24, 10, 18, 43);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-25 16:40:00' WHERE id = 24;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (30, 24, 'PX-2026-0011/1', 'SHIP', NULL, 3, '2026-08-25 16:40:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(30, 102, 5, 0),
(30, 103, 6, 0),
(30, 104, 2, 0),
(30, 105, 43, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(24, 30, 102, 1, 9, 15, -5, '2026-08-25 16:40:00', 3),
(24, 30, 103, 1, 18, 32, -6, '2026-08-25 16:40:00', 3),
(24, 30, 104, 1, 18, 31, -2, '2026-08-25 16:40:00', 3),
(24, 30, 105, 1, 10, 18, -43, '2026-08-25 16:40:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 102;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 103;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 104;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 43 WHERE id = 105;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-25 16:40:00', completed_at = '2026-08-25 16:40:00' WHERE id = 24;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (25, 'PN-2026-0008', 'IMPORT', 3, NULL, NULL, '2026-08-26', 'Nhập hàng từ NCC Masan', 5, '2026-08-26 08:19:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(106, 25, 23, 40, 150),
(107, 25, 27, 48, 50),
(108, 25, 4, 7, 160);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-26 11:36:00' WHERE id = 25;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (31, 25, 'PN-2026-0008/1', 'RECEIVE', NULL, 5, '2026-08-26 11:36:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(31, 106, 150, 0),
(31, 107, 50, 0),
(31, 108, 160, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(25, 31, 106, 3, 23, 40, 150, '2026-08-26 11:36:00', 5),
(25, 31, 107, 3, 27, 48, 50, '2026-08-26 11:36:00', 5),
(25, 31, 108, 3, 4, 7, 160, '2026-08-26 11:36:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 150 WHERE id = 106;
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 107;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 108;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-08-26 11:36:00' WHERE id = 25;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (26, 'PX-2026-0012', 'EXPORT', 3, NULL, NULL, '2026-08-27', 'Xuất bán cho Mini mart 24h', 5, '2026-08-27 11:23:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(109, 26, 12, 21, 8),
(110, 26, 26, 45, 8);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-27 15:19:00' WHERE id = 26;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (32, 26, 'PX-2026-0012/1', 'SHIP', NULL, 5, '2026-08-27 15:19:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(32, 109, 8, 0),
(32, 110, 8, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(26, 32, 109, 3, 12, 21, -8, '2026-08-27 15:19:00', 5),
(26, 32, 110, 3, 26, 45, -8, '2026-08-27 15:19:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 8 WHERE id = 109;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 8 WHERE id = 110;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-27 15:19:00', completed_at = '2026-08-27 15:19:00' WHERE id = 26;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (27, 'PX-2026-0013', 'EXPORT', 2, NULL, NULL, '2026-08-28', 'Xuất bán cho Đại lý Phú Quý', 4, '2026-08-28 08:10:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(111, 27, 27, 47, 6),
(112, 27, 15, 27, 72),
(113, 27, 19, 33, 24),
(114, 27, 29, 51, 8);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-28 16:47:00' WHERE id = 27;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (33, 27, 'PX-2026-0013/1', 'SHIP', NULL, 4, '2026-08-28 16:47:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(33, 111, 6, 0),
(33, 112, 72, 0),
(33, 113, 24, 0),
(33, 114, 8, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(27, 33, 111, 2, 27, 47, -6, '2026-08-28 16:47:00', 4),
(27, 33, 112, 2, 15, 27, -72, '2026-08-28 16:47:00', 4),
(27, 33, 113, 2, 19, 33, -24, '2026-08-28 16:47:00', 4),
(27, 33, 114, 2, 29, 51, -8, '2026-08-28 16:47:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 111;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 72 WHERE id = 112;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 24 WHERE id = 113;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 8 WHERE id = 114;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-28 16:47:00', completed_at = '2026-08-28 16:47:00' WHERE id = 27;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (28, 'PX-2026-0014', 'EXPORT', 3, NULL, NULL, '2026-08-29', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-29 10:56:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(115, 28, 11, 19, 33),
(116, 28, 10, 18, 8);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-29 15:33:00' WHERE id = 28;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (34, 28, 'PX-2026-0014/1', 'SHIP', NULL, 5, '2026-08-29 15:33:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(34, 115, 33, 0),
(34, 116, 8, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(28, 34, 115, 3, 11, 19, -33, '2026-08-29 15:33:00', 5),
(28, 34, 116, 3, 10, 18, -8, '2026-08-29 15:33:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 33 WHERE id = 115;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 8 WHERE id = 116;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-29 15:33:00', completed_at = '2026-08-29 15:33:00' WHERE id = 28;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (29, 'PX-2026-0015', 'EXPORT', 2, NULL, NULL, '2026-08-30', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-30 10:14:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(117, 29, 1, 1, 4),
(118, 29, 15, 27, 21),
(119, 29, 25, 43, 11),
(120, 29, 25, 44, 1);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-30 15:34:00' WHERE id = 29;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (35, 29, 'PX-2026-0015/1', 'SHIP', NULL, 4, '2026-08-30 15:34:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(35, 117, 4, 0),
(35, 118, 21, 0),
(35, 119, 11, 0),
(35, 120, 1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(29, 35, 117, 2, 1, 1, -4, '2026-08-30 15:34:00', 4),
(29, 35, 118, 2, 15, 27, -21, '2026-08-30 15:34:00', 4),
(29, 35, 119, 2, 25, 43, -11, '2026-08-30 15:34:00', 4),
(29, 35, 120, 2, 25, 44, -1, '2026-08-30 15:34:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 117;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 21 WHERE id = 118;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 11 WHERE id = 119;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 120;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-30 15:34:00', completed_at = '2026-08-30 15:34:00' WHERE id = 29;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (30, 'PX-2026-0016', 'EXPORT', 1, NULL, NULL, '2026-08-31', 'Xuất bán cho Cửa hàng Thành Công', 3, '2026-08-31 10:40:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(121, 30, 3, 5, 2),
(122, 30, 17, 29, 42),
(123, 30, 29, 51, 17),
(124, 30, 24, 42, 4);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-31 14:51:00' WHERE id = 30;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (36, 30, 'PX-2026-0016/1', 'SHIP', NULL, 3, '2026-08-31 14:51:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(36, 121, 2, 0),
(36, 122, 42, 0),
(36, 123, 17, 0),
(36, 124, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(30, 36, 121, 1, 3, 5, -2, '2026-08-31 14:51:00', 3),
(30, 36, 122, 1, 17, 29, -42, '2026-08-31 14:51:00', 3),
(30, 36, 123, 1, 29, 51, -17, '2026-08-31 14:51:00', 3),
(30, 36, 124, 1, 24, 42, -4, '2026-08-31 14:51:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 121;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 42 WHERE id = 122;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 123;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 124;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-08-31 14:51:00', completed_at = '2026-08-31 14:51:00' WHERE id = 30;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (31, 'PX-2026-0017', 'EXPORT', 3, NULL, NULL, '2026-09-01', 'Xuất bán cho Đại lý Minh Anh', 5, '2026-09-01 10:50:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(125, 31, 23, 40, 23),
(126, 31, 10, 18, 1),
(127, 31, 9, 16, 17),
(128, 31, 20, 35, 11);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-01 15:03:00' WHERE id = 31;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (37, 31, 'PX-2026-0017/1', 'SHIP', NULL, 5, '2026-09-01 15:03:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(37, 125, 23, 0),
(37, 126, 1, 0),
(37, 127, 17, 0),
(37, 128, 11, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(31, 37, 125, 3, 23, 40, -23, '2026-09-01 15:03:00', 5),
(31, 37, 126, 3, 10, 18, -1, '2026-09-01 15:03:00', 5),
(31, 37, 127, 3, 9, 16, -17, '2026-09-01 15:03:00', 5),
(31, 37, 128, 3, 20, 35, -11, '2026-09-01 15:03:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 23 WHERE id = 125;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 126;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 127;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 11 WHERE id = 128;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-01 15:03:00', completed_at = '2026-09-01 15:03:00' WHERE id = 31;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (32, 'PX-2026-0018', 'EXPORT', 3, NULL, NULL, '2026-09-02', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-09-02 10:39:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(129, 32, 11, 20, 26),
(130, 32, 6, 11, 5);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-02 15:46:00' WHERE id = 32;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (38, 32, 'PX-2026-0018/1', 'SHIP', NULL, 5, '2026-09-02 15:46:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(38, 129, 26, 0),
(38, 130, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(32, 38, 129, 3, 11, 20, -26, '2026-09-02 15:46:00', 5),
(32, 38, 130, 3, 6, 11, -5, '2026-09-02 15:46:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 26 WHERE id = 129;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 130;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-02 15:46:00', completed_at = '2026-09-02 15:46:00' WHERE id = 32;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (33, 'PC-2026-0007', 'TRANSFER', 1, 2, NULL, '2026-09-03', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-03 10:28:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(131, 33, 27, 47, 26),
(132, 33, 9, 15, 3),
(133, 33, 17, 29, 4);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-03 15:34:00' WHERE id = 33;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (39, 33, 'PC-2026-0007/1', 'SEND', NULL, 3, '2026-09-03 15:34:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(39, 131, 26, 0),
(39, 132, 3, 0),
(39, 133, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(33, 39, 131, 1, 27, 47, -26, '2026-09-03 15:34:00', 3),
(33, 39, 131, 4, 27, 47, 26, '2026-09-03 15:34:00', 3),
(33, 39, 132, 1, 9, 15, -3, '2026-09-03 15:34:00', 3),
(33, 39, 132, 4, 9, 15, 3, '2026-09-03 15:34:00', 3),
(33, 39, 133, 1, 17, 29, -4, '2026-09-03 15:34:00', 3),
(33, 39, 133, 4, 17, 29, 4, '2026-09-03 15:34:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 26 WHERE id = 131;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 3 WHERE id = 132;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 133;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-03 15:34:00' WHERE id = 33;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (40, 33, 'PC-2026-0007/2', 'ARRIVE', NULL, 4, '2026-09-03 17:04:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(40, 131, 26, 0),
(40, 132, 3, 0),
(40, 133, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(33, 40, 131, 4, 27, 47, -26, '2026-09-03 17:04:00', 4),
(33, 40, 131, 2, 27, 47, 26, '2026-09-03 17:04:00', 4),
(33, 40, 132, 4, 9, 15, -3, '2026-09-03 17:04:00', 4),
(33, 40, 132, 2, 9, 15, 3, '2026-09-03 17:04:00', 4),
(33, 40, 133, 4, 17, 29, -4, '2026-09-03 17:04:00', 4),
(33, 40, 133, 2, 17, 29, 4, '2026-09-03 17:04:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 26 WHERE id = 131;
UPDATE voucher_lines SET received_qty = received_qty + 3 WHERE id = 132;
UPDATE voucher_lines SET received_qty = received_qty + 4 WHERE id = 133;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-03 17:04:00' WHERE id = 33;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (34, 'PC-2026-0008', 'TRANSFER', 1, 2, NULL, '2026-09-04', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-04 08:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(134, 34, 17, 29, 40),
(135, 34, 5, 9, 29);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-04 15:41:00' WHERE id = 34;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (41, 34, 'PC-2026-0008/1', 'SEND', NULL, 3, '2026-09-04 15:41:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(41, 134, 40, 0),
(41, 135, 29, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(34, 41, 134, 1, 17, 29, -40, '2026-09-04 15:41:00', 3),
(34, 41, 134, 4, 17, 29, 40, '2026-09-04 15:41:00', 3),
(34, 41, 135, 1, 5, 9, -29, '2026-09-04 15:41:00', 3),
(34, 41, 135, 4, 5, 9, 29, '2026-09-04 15:41:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 40 WHERE id = 134;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 29 WHERE id = 135;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-04 15:41:00' WHERE id = 34;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (42, 34, 'PC-2026-0008/2', 'ARRIVE', NULL, 4, '2026-09-04 17:11:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(42, 134, 40, 0),
(42, 135, 29, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(34, 42, 134, 4, 17, 29, -40, '2026-09-04 17:11:00', 4),
(34, 42, 134, 2, 17, 29, 40, '2026-09-04 17:11:00', 4),
(34, 42, 135, 4, 5, 9, -29, '2026-09-04 17:11:00', 4),
(34, 42, 135, 2, 5, 9, 29, '2026-09-04 17:11:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 40 WHERE id = 134;
UPDATE voucher_lines SET received_qty = received_qty + 29 WHERE id = 135;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-04 17:11:00' WHERE id = 34;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (35, 'PC-2026-0009', 'TRANSFER', 3, 2, NULL, '2026-09-05', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-05 09:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(136, 35, 25, 44, 8),
(137, 35, 27, 48, 4);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-05 13:59:00' WHERE id = 35;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (43, 35, 'PC-2026-0009/1', 'SEND', NULL, 5, '2026-09-05 13:59:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(43, 136, 8, 0),
(43, 137, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(35, 43, 136, 3, 25, 44, -8, '2026-09-05 13:59:00', 5),
(35, 43, 136, 4, 25, 44, 8, '2026-09-05 13:59:00', 5),
(35, 43, 137, 3, 27, 48, -4, '2026-09-05 13:59:00', 5),
(35, 43, 137, 4, 27, 48, 4, '2026-09-05 13:59:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 8 WHERE id = 136;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 137;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-05 13:59:00' WHERE id = 35;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (44, 35, 'PC-2026-0009/2', 'ARRIVE', NULL, 4, '2026-09-05 15:29:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(44, 136, 8, 0),
(44, 137, 4, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(35, 44, 136, 4, 25, 44, -8, '2026-09-05 15:29:00', 4),
(35, 44, 136, 2, 25, 44, 8, '2026-09-05 15:29:00', 4),
(35, 44, 137, 4, 27, 48, -4, '2026-09-05 15:29:00', 4),
(35, 44, 137, 2, 27, 48, 4, '2026-09-05 15:29:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 8 WHERE id = 136;
UPDATE voucher_lines SET received_qty = received_qty + 4 WHERE id = 137;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-05 15:29:00' WHERE id = 35;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (36, 'PX-2026-0019', 'EXPORT', 1, NULL, NULL, '2026-09-06', 'Xuất bán cho Đại lý Phú Quý', 3, '2026-09-06 09:59:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(138, 36, 23, 41, 6),
(139, 36, 18, 32, 4),
(140, 36, 13, 22, 1),
(141, 36, 17, 29, 22);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-06 15:41:00' WHERE id = 36;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (45, 36, 'PX-2026-0019/1', 'SHIP', NULL, 3, '2026-09-06 15:41:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(45, 138, 6, 0),
(45, 139, 4, 0),
(45, 140, 1, 0),
(45, 141, 22, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(36, 45, 138, 1, 23, 41, -6, '2026-09-06 15:41:00', 3),
(36, 45, 139, 1, 18, 32, -4, '2026-09-06 15:41:00', 3),
(36, 45, 140, 1, 13, 22, -1, '2026-09-06 15:41:00', 3),
(36, 45, 141, 1, 17, 29, -22, '2026-09-06 15:41:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 138;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 139;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 140;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 22 WHERE id = 141;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-06 15:41:00', completed_at = '2026-09-06 15:41:00' WHERE id = 36;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (37, 'PX-2026-0020', 'EXPORT', 2, NULL, NULL, '2026-09-07', 'Xuất bán cho Mini mart 24h', 4, '2026-09-07 08:44:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(142, 37, 30, 53, 40),
(143, 37, 29, 51, 7);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-07 16:39:00' WHERE id = 37;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (46, 37, 'PX-2026-0020/1', 'SHIP', NULL, 4, '2026-09-07 16:39:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(46, 142, 40, 0),
(46, 143, 7, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(37, 46, 142, 2, 30, 53, -40, '2026-09-07 16:39:00', 4),
(37, 46, 143, 2, 29, 51, -7, '2026-09-07 16:39:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 40 WHERE id = 142;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 143;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-07 16:39:00', completed_at = '2026-09-07 16:39:00' WHERE id = 37;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (38, 'PC-2026-0010', 'TRANSFER', 3, 2, NULL, '2026-09-08', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-08 08:11:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(144, 38, 11, 20, 4),
(145, 38, 8, 14, 20),
(146, 38, 23, 40, 14);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-08 16:28:00' WHERE id = 38;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (47, 38, 'PC-2026-0010/1', 'SEND', NULL, 5, '2026-09-08 16:28:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(47, 144, 4, 0),
(47, 145, 20, 0),
(47, 146, 14, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(38, 47, 144, 3, 11, 20, -4, '2026-09-08 16:28:00', 5),
(38, 47, 144, 4, 11, 20, 4, '2026-09-08 16:28:00', 5),
(38, 47, 145, 3, 8, 14, -20, '2026-09-08 16:28:00', 5),
(38, 47, 145, 4, 8, 14, 20, '2026-09-08 16:28:00', 5),
(38, 47, 146, 3, 23, 40, -14, '2026-09-08 16:28:00', 5),
(38, 47, 146, 4, 23, 40, 14, '2026-09-08 16:28:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 144;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 20 WHERE id = 145;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 14 WHERE id = 146;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-08 16:28:00' WHERE id = 38;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (48, 38, 'PC-2026-0010/2', 'ARRIVE', NULL, 4, '2026-09-08 17:58:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(48, 144, 4, 0),
(48, 145, 20, 0),
(48, 146, 14, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(38, 48, 144, 4, 11, 20, -4, '2026-09-08 17:58:00', 4),
(38, 48, 144, 2, 11, 20, 4, '2026-09-08 17:58:00', 4),
(38, 48, 145, 4, 8, 14, -20, '2026-09-08 17:58:00', 4),
(38, 48, 145, 2, 8, 14, 20, '2026-09-08 17:58:00', 4),
(38, 48, 146, 4, 23, 40, -14, '2026-09-08 17:58:00', 4),
(38, 48, 146, 2, 23, 40, 14, '2026-09-08 17:58:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 4 WHERE id = 144;
UPDATE voucher_lines SET received_qty = received_qty + 20 WHERE id = 145;
UPDATE voucher_lines SET received_qty = received_qty + 14 WHERE id = 146;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-08 17:58:00' WHERE id = 38;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (39, 'PC-2026-0011', 'TRANSFER', 1, 2, NULL, '2026-09-09', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-09 08:31:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(147, 39, 27, 47, 17);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-09 14:30:00' WHERE id = 39;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (49, 39, 'PC-2026-0011/1', 'SEND', NULL, 3, '2026-09-09 14:30:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(49, 147, 17, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(39, 49, 147, 1, 27, 47, -17, '2026-09-09 14:30:00', 3),
(39, 49, 147, 4, 27, 47, 17, '2026-09-09 14:30:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 147;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-09 14:30:00' WHERE id = 39;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (50, 39, 'PC-2026-0011/2', 'ARRIVE', NULL, 4, '2026-09-09 16:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(50, 147, 17, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(39, 50, 147, 4, 27, 47, -17, '2026-09-09 16:00:00', 4),
(39, 50, 147, 2, 27, 47, 17, '2026-09-09 16:00:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 17 WHERE id = 147;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-09 16:00:00' WHERE id = 39;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (40, 'PN-2026-0009', 'IMPORT', 2, NULL, NULL, '2026-09-10', 'Nhập hàng từ NCC Masan', 4, '2026-09-10 08:15:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(148, 40, 25, 43, 50),
(149, 40, 14, 24, 120),
(150, 40, 15, 27, 100),
(151, 40, 5, 9, 110);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-10 12:41:00' WHERE id = 40;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (51, 40, 'PN-2026-0009/1', 'RECEIVE', NULL, 4, '2026-09-10 12:41:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(51, 148, 50, 0),
(51, 149, 120, 0),
(51, 150, 100, 0),
(51, 151, 110, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(40, 51, 148, 2, 25, 43, 50, '2026-09-10 12:41:00', 4),
(40, 51, 149, 2, 14, 24, 120, '2026-09-10 12:41:00', 4),
(40, 51, 150, 2, 15, 27, 100, '2026-09-10 12:41:00', 4),
(40, 51, 151, 2, 5, 9, 110, '2026-09-10 12:41:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 148;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 149;
UPDATE voucher_lines SET received_qty = received_qty + 100 WHERE id = 150;
UPDATE voucher_lines SET received_qty = received_qty + 110 WHERE id = 151;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-10 12:41:00' WHERE id = 40;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (41, 'PC-2026-0012', 'TRANSFER', 2, 3, NULL, '2026-09-11', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-11 11:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(152, 41, 23, 41, 19),
(153, 41, 25, 43, 20);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-11 13:44:00' WHERE id = 41;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (52, 41, 'PC-2026-0012/1', 'SEND', NULL, 4, '2026-09-11 13:44:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(52, 152, 19, 0),
(52, 153, 20, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(41, 52, 152, 2, 23, 41, -19, '2026-09-11 13:44:00', 4),
(41, 52, 152, 4, 23, 41, 19, '2026-09-11 13:44:00', 4),
(41, 52, 153, 2, 25, 43, -20, '2026-09-11 13:44:00', 4),
(41, 52, 153, 4, 25, 43, 20, '2026-09-11 13:44:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 19 WHERE id = 152;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 20 WHERE id = 153;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-11 13:44:00' WHERE id = 41;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (53, 41, 'PC-2026-0012/2', 'ARRIVE', NULL, 5, '2026-09-11 15:14:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(53, 152, 19, 0),
(53, 153, 20, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(41, 53, 152, 4, 23, 41, -19, '2026-09-11 15:14:00', 5),
(41, 53, 152, 3, 23, 41, 19, '2026-09-11 15:14:00', 5),
(41, 53, 153, 4, 25, 43, -20, '2026-09-11 15:14:00', 5),
(41, 53, 153, 3, 25, 43, 20, '2026-09-11 15:14:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 19 WHERE id = 152;
UPDATE voucher_lines SET received_qty = received_qty + 20 WHERE id = 153;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-11 15:14:00' WHERE id = 41;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (42, 'PC-2026-0013', 'TRANSFER', 2, 3, NULL, '2026-09-12', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-12 11:29:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(154, 42, 27, 48, 1);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-12 14:33:00' WHERE id = 42;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (54, 42, 'PC-2026-0013/1', 'SEND', NULL, 4, '2026-09-12 14:33:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(54, 154, 1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(42, 54, 154, 2, 27, 48, -1, '2026-09-12 14:33:00', 4),
(42, 54, 154, 4, 27, 48, 1, '2026-09-12 14:33:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 154;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-12 14:33:00' WHERE id = 42;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (55, 42, 'PC-2026-0013/2', 'ARRIVE', NULL, 5, '2026-09-12 16:03:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(55, 154, 1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(42, 55, 154, 4, 27, 48, -1, '2026-09-12 16:03:00', 5),
(42, 55, 154, 3, 27, 48, 1, '2026-09-12 16:03:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 1 WHERE id = 154;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-12 16:03:00' WHERE id = 42;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (43, 'PX-2026-0021', 'EXPORT', 3, NULL, NULL, '2026-09-13', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-09-13 08:27:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(155, 43, 12, 21, 7),
(156, 43, 11, 19, 33),
(157, 43, 4, 7, 18),
(158, 43, 9, 16, 14);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-13 13:57:00' WHERE id = 43;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (56, 43, 'PX-2026-0021/1', 'SHIP', NULL, 5, '2026-09-13 13:57:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(56, 155, 7, 0),
(56, 156, 33, 0),
(56, 157, 18, 0),
(56, 158, 14, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(43, 56, 155, 3, 12, 21, -7, '2026-09-13 13:57:00', 5),
(43, 56, 156, 3, 11, 19, -33, '2026-09-13 13:57:00', 5),
(43, 56, 157, 3, 4, 7, -18, '2026-09-13 13:57:00', 5),
(43, 56, 158, 3, 9, 16, -14, '2026-09-13 13:57:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 155;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 33 WHERE id = 156;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 18 WHERE id = 157;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 14 WHERE id = 158;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-13 13:57:00', completed_at = '2026-09-13 13:57:00' WHERE id = 43;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (44, 'PN-2026-0010', 'IMPORT', 3, NULL, NULL, '2026-09-14', 'Nhập hàng từ NCC Coca-Cola VN', 5, '2026-09-14 09:34:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(159, 44, 17, 29, 180),
(160, 44, 24, 42, 170),
(161, 44, 12, 21, 120),
(162, 44, 23, 40, 160),
(163, 44, 1, 2, 160);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-14 11:36:00' WHERE id = 44;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (57, 44, 'PN-2026-0010/1', 'RECEIVE', NULL, 5, '2026-09-14 11:36:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(57, 159, 180, 0),
(57, 160, 170, 0),
(57, 161, 120, 0),
(57, 162, 160, 0),
(57, 163, 160, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(44, 57, 159, 3, 17, 29, 180, '2026-09-14 11:36:00', 5),
(44, 57, 160, 3, 24, 42, 170, '2026-09-14 11:36:00', 5),
(44, 57, 161, 3, 12, 21, 120, '2026-09-14 11:36:00', 5),
(44, 57, 162, 3, 23, 40, 160, '2026-09-14 11:36:00', 5),
(44, 57, 163, 3, 1, 2, 160, '2026-09-14 11:36:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 180 WHERE id = 159;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 160;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 161;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 162;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 163;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-14 11:36:00' WHERE id = 44;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (45, 'PN-2026-0011', 'IMPORT', 3, NULL, NULL, '2026-09-15', 'Nhập hàng từ NCC Tân Hiệp Phát', 5, '2026-09-15 08:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(164, 45, 22, 39, 180),
(165, 45, 26, 46, 160),
(166, 45, 10, 18, 120),
(167, 45, 13, 22, 190),
(168, 45, 25, 43, 80);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-15 12:49:00' WHERE id = 45;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (58, 45, 'PN-2026-0011/1', 'RECEIVE', NULL, 5, '2026-09-15 12:49:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(58, 164, 180, 0),
(58, 165, 160, 0),
(58, 166, 120, 0),
(58, 167, 190, 0),
(58, 168, 80, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(45, 58, 164, 3, 22, 39, 180, '2026-09-15 12:49:00', 5),
(45, 58, 165, 3, 26, 46, 160, '2026-09-15 12:49:00', 5),
(45, 58, 166, 3, 10, 18, 120, '2026-09-15 12:49:00', 5),
(45, 58, 167, 3, 13, 22, 190, '2026-09-15 12:49:00', 5),
(45, 58, 168, 3, 25, 43, 80, '2026-09-15 12:49:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 180 WHERE id = 164;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 165;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 166;
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 167;
UPDATE voucher_lines SET received_qty = received_qty + 80 WHERE id = 168;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-15 12:49:00' WHERE id = 45;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (46, 'PC-2026-0014', 'TRANSFER', 2, 3, NULL, '2026-09-16', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-16 08:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(169, 46, 30, 52, 9);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-16 14:15:00' WHERE id = 46;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (59, 46, 'PC-2026-0014/1', 'SEND', NULL, 4, '2026-09-16 14:15:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(59, 169, 9, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(46, 59, 169, 2, 30, 52, -9, '2026-09-16 14:15:00', 4),
(46, 59, 169, 4, 30, 52, 9, '2026-09-16 14:15:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 9 WHERE id = 169;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-16 14:15:00' WHERE id = 46;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (60, 46, 'PC-2026-0014/2', 'ARRIVE', NULL, 5, '2026-09-16 15:45:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(60, 169, 9, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(46, 60, 169, 4, 30, 52, -9, '2026-09-16 15:45:00', 5),
(46, 60, 169, 3, 30, 52, 9, '2026-09-16 15:45:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 9 WHERE id = 169;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-16 15:45:00' WHERE id = 46;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (47, 'PX-2026-0022', 'EXPORT', 1, NULL, NULL, '2026-09-17', 'Xuất bán cho Siêu thị Hòa Bình', 3, '2026-09-17 09:09:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(170, 47, 14, 25, 2),
(171, 47, 29, 50, 40);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-17 16:48:00' WHERE id = 47;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (61, 47, 'PX-2026-0022/1', 'SHIP', NULL, 3, '2026-09-17 16:48:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(61, 170, 2, 0),
(61, 171, 40, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(47, 61, 170, 1, 14, 25, -2, '2026-09-17 16:48:00', 3),
(47, 61, 171, 1, 29, 50, -40, '2026-09-17 16:48:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 170;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 40 WHERE id = 171;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-17 16:48:00', completed_at = '2026-09-17 16:48:00' WHERE id = 47;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (48, 'PX-2026-0023', 'EXPORT', 1, NULL, NULL, '2026-09-18', 'Xuất bán cho Đại lý Minh Anh', 3, '2026-09-18 08:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(172, 48, 25, 44, 11),
(173, 48, 14, 25, 1),
(174, 48, 27, 48, 17);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-18 15:26:00' WHERE id = 48;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (62, 48, 'PX-2026-0023/1', 'SHIP', NULL, 3, '2026-09-18 15:26:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(62, 172, 11, 0),
(62, 173, 1, 0),
(62, 174, 17, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(48, 62, 172, 1, 25, 44, -11, '2026-09-18 15:26:00', 3),
(48, 62, 173, 1, 14, 25, -1, '2026-09-18 15:26:00', 3),
(48, 62, 174, 1, 27, 48, -17, '2026-09-18 15:26:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 11 WHERE id = 172;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 173;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 174;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-18 15:26:00', completed_at = '2026-09-18 15:26:00' WHERE id = 48;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (49, 'PX-2026-0024', 'EXPORT', 2, NULL, NULL, '2026-09-19', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-09-19 09:08:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(175, 49, 24, 42, 13),
(176, 49, 23, 41, 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-19 13:17:00' WHERE id = 49;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (63, 49, 'PX-2026-0024/1', 'SHIP', NULL, 4, '2026-09-19 13:17:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(63, 175, 13, 0),
(63, 176, 2, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(49, 63, 175, 2, 24, 42, -13, '2026-09-19 13:17:00', 4),
(49, 63, 176, 2, 23, 41, -2, '2026-09-19 13:17:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 13 WHERE id = 175;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 176;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-19 13:17:00', completed_at = '2026-09-19 13:17:00' WHERE id = 49;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (50, 'PX-2026-0025', 'EXPORT', 2, NULL, NULL, '2026-09-20', 'Xuất bán cho Cửa hàng Thành Công', 4, '2026-09-20 09:25:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(177, 50, 30, 53, 6),
(178, 50, 6, 11, 29),
(179, 50, 12, 21, 7);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-20 15:14:00' WHERE id = 50;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (64, 50, 'PX-2026-0025/1', 'SHIP', NULL, 4, '2026-09-20 15:14:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(64, 177, 6, 0),
(64, 178, 29, 0),
(64, 179, 7, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(50, 64, 177, 2, 30, 53, -6, '2026-09-20 15:14:00', 4),
(50, 64, 178, 2, 6, 11, -29, '2026-09-20 15:14:00', 4),
(50, 64, 179, 2, 12, 21, -7, '2026-09-20 15:14:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 6 WHERE id = 177;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 29 WHERE id = 178;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 179;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-20 15:14:00', completed_at = '2026-09-20 15:14:00' WHERE id = 50;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (51, 'PX-2026-0026', 'EXPORT', 2, NULL, NULL, '2026-09-21', 'Xuất bán cho Tạp hóa Cô Ba', 4, '2026-09-21 11:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(180, 51, 7, 13, 23),
(181, 51, 6, 11, 3),
(182, 51, 30, 53, 30);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-21 15:09:00' WHERE id = 51;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (65, 51, 'PX-2026-0026/1', 'SHIP', NULL, 4, '2026-09-21 15:09:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(65, 180, 23, 0),
(65, 181, 3, 0),
(65, 182, 30, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(51, 65, 180, 2, 7, 13, -23, '2026-09-21 15:09:00', 4),
(51, 65, 181, 2, 6, 11, -3, '2026-09-21 15:09:00', 4),
(51, 65, 182, 2, 30, 53, -30, '2026-09-21 15:09:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 23 WHERE id = 180;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 3 WHERE id = 181;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 30 WHERE id = 182;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-21 15:09:00', completed_at = '2026-09-21 15:09:00' WHERE id = 51;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (52, 'PN-2026-0012', 'IMPORT', 2, NULL, NULL, '2026-09-22', 'Nhập hàng từ NCC Sabeco', 4, '2026-09-22 10:13:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(183, 52, 15, 27, 110),
(184, 52, 5, 9, 80),
(185, 52, 14, 24, 160);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-22 12:39:00' WHERE id = 52;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (66, 52, 'PN-2026-0012/1', 'RECEIVE', NULL, 4, '2026-09-22 12:39:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(66, 183, 110, 0),
(66, 184, 80, 0),
(66, 185, 160, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(52, 66, 183, 2, 15, 27, 110, '2026-09-22 12:39:00', 4),
(52, 66, 184, 2, 5, 9, 80, '2026-09-22 12:39:00', 4),
(52, 66, 185, 2, 14, 24, 160, '2026-09-22 12:39:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 110 WHERE id = 183;
UPDATE voucher_lines SET received_qty = received_qty + 80 WHERE id = 184;
UPDATE voucher_lines SET received_qty = received_qty + 160 WHERE id = 185;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-22 12:39:00' WHERE id = 52;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (53, 'PC-2026-0015', 'TRANSFER', 3, 2, NULL, '2026-09-23', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-23 11:34:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(186, 53, 27, 48, 5),
(187, 53, 6, 10, 36),
(188, 53, 17, 29, 1);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-23 14:31:00' WHERE id = 53;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (67, 53, 'PC-2026-0015/1', 'SEND', NULL, 5, '2026-09-23 14:31:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(67, 186, 5, 0),
(67, 187, 36, 0),
(67, 188, 1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(53, 67, 186, 3, 27, 48, -5, '2026-09-23 14:31:00', 5),
(53, 67, 186, 4, 27, 48, 5, '2026-09-23 14:31:00', 5),
(53, 67, 187, 3, 6, 10, -36, '2026-09-23 14:31:00', 5),
(53, 67, 187, 4, 6, 10, 36, '2026-09-23 14:31:00', 5),
(53, 67, 188, 3, 17, 29, -1, '2026-09-23 14:31:00', 5),
(53, 67, 188, 4, 17, 29, 1, '2026-09-23 14:31:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 186;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 36 WHERE id = 187;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 1 WHERE id = 188;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-23 14:31:00' WHERE id = 53;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (68, 53, 'PC-2026-0015/2', 'ARRIVE', NULL, 4, '2026-09-23 16:01:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(68, 186, 5, 0),
(68, 187, 36, 0),
(68, 188, 1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(53, 68, 186, 4, 27, 48, -5, '2026-09-23 16:01:00', 4),
(53, 68, 186, 2, 27, 48, 5, '2026-09-23 16:01:00', 4),
(53, 68, 187, 4, 6, 10, -36, '2026-09-23 16:01:00', 4),
(53, 68, 187, 2, 6, 10, 36, '2026-09-23 16:01:00', 4),
(53, 68, 188, 4, 17, 29, -1, '2026-09-23 16:01:00', 4),
(53, 68, 188, 2, 17, 29, 1, '2026-09-23 16:01:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 5 WHERE id = 186;
UPDATE voucher_lines SET received_qty = received_qty + 36 WHERE id = 187;
UPDATE voucher_lines SET received_qty = received_qty + 1 WHERE id = 188;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-23 16:01:00' WHERE id = 53;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (54, 'PN-2026-0013', 'IMPORT', 2, NULL, NULL, '2026-09-24', 'Nhập hàng từ NCC Tân Hiệp Phát', 4, '2026-09-24 09:32:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(189, 54, 6, 11, 120),
(190, 54, 1, 1, 80),
(191, 54, 14, 25, 90),
(192, 54, 27, 48, 170);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-24 12:34:00' WHERE id = 54;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (69, 54, 'PN-2026-0013/1', 'RECEIVE', NULL, 4, '2026-09-24 12:34:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(69, 189, 120, 0),
(69, 190, 80, 0),
(69, 191, 90, 0),
(69, 192, 170, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(54, 69, 189, 2, 6, 11, 120, '2026-09-24 12:34:00', 4),
(54, 69, 190, 2, 1, 1, 80, '2026-09-24 12:34:00', 4),
(54, 69, 191, 2, 14, 25, 90, '2026-09-24 12:34:00', 4),
(54, 69, 192, 2, 27, 48, 170, '2026-09-24 12:34:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 189;
UPDATE voucher_lines SET received_qty = received_qty + 80 WHERE id = 190;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 191;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 192;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-24 12:34:00' WHERE id = 54;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (55, 'PN-2026-0014', 'IMPORT', 3, NULL, NULL, '2026-09-25', 'Nhập hàng từ NCC Coca-Cola VN', 5, '2026-09-25 09:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(193, 55, 13, 22, 50),
(194, 55, 10, 18, 200),
(195, 55, 12, 21, 90);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-25 11:57:00' WHERE id = 55;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (70, 55, 'PN-2026-0014/1', 'RECEIVE', NULL, 5, '2026-09-25 11:57:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(70, 193, 50, 0),
(70, 194, 200, 0),
(70, 195, 90, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(55, 70, 193, 3, 13, 22, 50, '2026-09-25 11:57:00', 5),
(55, 70, 194, 3, 10, 18, 200, '2026-09-25 11:57:00', 5),
(55, 70, 195, 3, 12, 21, 90, '2026-09-25 11:57:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 193;
UPDATE voucher_lines SET received_qty = received_qty + 200 WHERE id = 194;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 195;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-25 11:57:00' WHERE id = 55;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (56, 'PX-2026-0027', 'EXPORT', 2, NULL, NULL, '2026-09-26', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-09-26 09:50:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(196, 56, 6, 10, 13),
(197, 56, 17, 29, 23);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-26 13:13:00' WHERE id = 56;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (71, 56, 'PX-2026-0027/1', 'SHIP', NULL, 4, '2026-09-26 13:13:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(71, 196, 13, 0),
(71, 197, 23, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(56, 71, 196, 2, 6, 10, -13, '2026-09-26 13:13:00', 4),
(56, 71, 197, 2, 17, 29, -23, '2026-09-26 13:13:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 13 WHERE id = 196;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 23 WHERE id = 197;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-26 13:13:00', completed_at = '2026-09-26 13:13:00' WHERE id = 56;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (57, 'PN-2026-0015', 'IMPORT', 2, NULL, NULL, '2026-09-27', 'Nhập hàng từ NCC Unilever', 4, '2026-09-27 08:48:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(198, 57, 6, 11, 70),
(199, 57, 14, 24, 50),
(200, 57, 21, 37, 170),
(201, 57, 7, 13, 120);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-27 11:23:00' WHERE id = 57;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (72, 57, 'PN-2026-0015/1', 'RECEIVE', NULL, 4, '2026-09-27 11:23:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(72, 198, 70, 0),
(72, 199, 50, 0),
(72, 200, 170, 0),
(72, 201, 120, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(57, 72, 198, 2, 6, 11, 70, '2026-09-27 11:23:00', 4),
(57, 72, 199, 2, 14, 24, 50, '2026-09-27 11:23:00', 4),
(57, 72, 200, 2, 21, 37, 170, '2026-09-27 11:23:00', 4),
(57, 72, 201, 2, 7, 13, 120, '2026-09-27 11:23:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 70 WHERE id = 198;
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 199;
UPDATE voucher_lines SET received_qty = received_qty + 170 WHERE id = 200;
UPDATE voucher_lines SET received_qty = received_qty + 120 WHERE id = 201;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-27 11:23:00' WHERE id = 57;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (58, 'PX-2026-0028', 'EXPORT', 3, NULL, NULL, '2026-09-28', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-09-28 09:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(202, 58, 22, 39, 70),
(203, 58, 26, 45, 25),
(204, 58, 13, 22, 59);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-28 16:31:00' WHERE id = 58;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (73, 58, 'PX-2026-0028/1', 'SHIP', NULL, 5, '2026-09-28 16:31:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(73, 202, 70, 0),
(73, 203, 25, 0),
(73, 204, 59, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(58, 73, 202, 3, 22, 39, -70, '2026-09-28 16:31:00', 5),
(58, 73, 203, 3, 26, 45, -25, '2026-09-28 16:31:00', 5),
(58, 73, 204, 3, 13, 22, -59, '2026-09-28 16:31:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 70 WHERE id = 202;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 25 WHERE id = 203;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 59 WHERE id = 204;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-28 16:31:00', completed_at = '2026-09-28 16:31:00' WHERE id = 58;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (59, 'PC-2026-0016', 'TRANSFER', 1, 3, NULL, '2026-09-29', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 3, '2026-09-29 11:16:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(205, 59, 5, 9, 19),
(206, 59, 9, 16, 12),
(207, 59, 29, 50, 65);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-29 16:07:00' WHERE id = 59;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (74, 59, 'PC-2026-0016/1', 'SEND', NULL, 3, '2026-09-29 16:07:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(74, 205, 19, 0),
(74, 206, 12, 0),
(74, 207, 65, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(59, 74, 205, 1, 5, 9, -19, '2026-09-29 16:07:00', 3),
(59, 74, 205, 4, 5, 9, 19, '2026-09-29 16:07:00', 3),
(59, 74, 206, 1, 9, 16, -12, '2026-09-29 16:07:00', 3),
(59, 74, 206, 4, 9, 16, 12, '2026-09-29 16:07:00', 3),
(59, 74, 207, 1, 29, 50, -65, '2026-09-29 16:07:00', 3),
(59, 74, 207, 4, 29, 50, 65, '2026-09-29 16:07:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 19 WHERE id = 205;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 12 WHERE id = 206;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 65 WHERE id = 207;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-09-29 16:07:00' WHERE id = 59;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (75, 59, 'PC-2026-0016/2', 'ARRIVE', NULL, 5, '2026-09-29 17:37:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(75, 205, 19, 0),
(75, 206, 12, 0),
(75, 207, 65, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(59, 75, 205, 4, 5, 9, -19, '2026-09-29 17:37:00', 5),
(59, 75, 205, 3, 5, 9, 19, '2026-09-29 17:37:00', 5),
(59, 75, 206, 4, 9, 16, -12, '2026-09-29 17:37:00', 5),
(59, 75, 206, 3, 9, 16, 12, '2026-09-29 17:37:00', 5),
(59, 75, 207, 4, 29, 50, -65, '2026-09-29 17:37:00', 5),
(59, 75, 207, 3, 29, 50, 65, '2026-09-29 17:37:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 19 WHERE id = 205;
UPDATE voucher_lines SET received_qty = received_qty + 12 WHERE id = 206;
UPDATE voucher_lines SET received_qty = received_qty + 65 WHERE id = 207;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-09-29 17:37:00' WHERE id = 59;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (60, 'PX-2026-0029', 'EXPORT', 3, NULL, NULL, '2026-09-30', 'Xuất bán cho Đại lý Phú Quý', 5, '2026-09-30 08:02:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(208, 60, 9, 16, 4),
(209, 60, 23, 40, 26),
(210, 60, 6, 11, 9),
(211, 60, 17, 29, 13);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-30 14:34:00' WHERE id = 60;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (76, 60, 'PX-2026-0029/1', 'SHIP', NULL, 5, '2026-09-30 14:34:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(76, 208, 4, 0),
(76, 209, 26, 0),
(76, 210, 9, 0),
(76, 211, 13, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(60, 76, 208, 3, 9, 16, -4, '2026-09-30 14:34:00', 5),
(60, 76, 209, 3, 23, 40, -26, '2026-09-30 14:34:00', 5),
(60, 76, 210, 3, 6, 11, -9, '2026-09-30 14:34:00', 5),
(60, 76, 211, 3, 17, 29, -13, '2026-09-30 14:34:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 4 WHERE id = 208;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 26 WHERE id = 209;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 9 WHERE id = 210;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 13 WHERE id = 211;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-09-30 14:34:00', completed_at = '2026-09-30 14:34:00' WHERE id = 60;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (61, 'PN-2026-0016', 'IMPORT', 1, NULL, NULL, '2026-10-01', 'Nhập hàng từ NCC Sabeco', 3, '2026-10-01 10:42:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(212, 61, 29, 50, 190),
(213, 61, 15, 27, 100),
(214, 61, 11, 19, 200),
(215, 61, 5, 9, 60);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-01 12:30:00' WHERE id = 61;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (77, 61, 'PN-2026-0016/1', 'RECEIVE', NULL, 3, '2026-10-01 12:30:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(77, 212, 190, 0),
(77, 213, 100, 0),
(77, 214, 200, 0),
(77, 215, 60, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(61, 77, 212, 1, 29, 50, 190, '2026-10-01 12:30:00', 3),
(61, 77, 213, 1, 15, 27, 100, '2026-10-01 12:30:00', 3),
(61, 77, 214, 1, 11, 19, 200, '2026-10-01 12:30:00', 3),
(61, 77, 215, 1, 5, 9, 60, '2026-10-01 12:30:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 212;
UPDATE voucher_lines SET received_qty = received_qty + 100 WHERE id = 213;
UPDATE voucher_lines SET received_qty = received_qty + 200 WHERE id = 214;
UPDATE voucher_lines SET received_qty = received_qty + 60 WHERE id = 215;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-01 12:30:00' WHERE id = 61;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (62, 'PC-2026-0017', 'TRANSFER', 2, 1, NULL, '2026-10-02', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 4, '2026-10-02 11:28:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(216, 62, 9, 16, 5);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-02 16:51:00' WHERE id = 62;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (78, 62, 'PC-2026-0017/1', 'SEND', NULL, 4, '2026-10-02 16:51:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(78, 216, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(62, 78, 216, 2, 9, 16, -5, '2026-10-02 16:51:00', 4),
(62, 78, 216, 4, 9, 16, 5, '2026-10-02 16:51:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 216;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-10-02 16:51:00' WHERE id = 62;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (79, 62, 'PC-2026-0017/2', 'ARRIVE', NULL, 3, '2026-10-02 18:21:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(79, 216, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(62, 79, 216, 4, 9, 16, -5, '2026-10-02 18:21:00', 3),
(62, 79, 216, 1, 9, 16, 5, '2026-10-02 18:21:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 5 WHERE id = 216;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-02 18:21:00' WHERE id = 62;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (63, 'PX-2026-0030', 'EXPORT', 1, NULL, NULL, '2026-10-03', 'Xuất bán cho Đại lý Minh Anh', 3, '2026-10-03 08:46:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(217, 63, 15, 27, 17),
(218, 63, 1, 2, 5);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-03 14:02:00' WHERE id = 63;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (80, 63, 'PX-2026-0030/1', 'SHIP', NULL, 3, '2026-10-03 14:02:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(80, 217, 17, 0),
(80, 218, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(63, 80, 217, 1, 15, 27, -17, '2026-10-03 14:02:00', 3),
(63, 80, 218, 1, 1, 2, -5, '2026-10-03 14:02:00', 3);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 17 WHERE id = 217;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 5 WHERE id = 218;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-10-03 14:02:00', completed_at = '2026-10-03 14:02:00' WHERE id = 63;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (64, 'PX-2026-0031', 'EXPORT', 2, NULL, NULL, '2026-10-04', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-10-04 10:52:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(219, 64, 29, 50, 12),
(220, 64, 1, 1, 2),
(221, 64, 14, 25, 7),
(222, 64, 19, 33, 22);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-04 15:03:00' WHERE id = 64;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (81, 64, 'PX-2026-0031/1', 'SHIP', NULL, 4, '2026-10-04 15:03:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(81, 219, 12, 0),
(81, 220, 2, 0),
(81, 221, 7, 0),
(81, 222, 22, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(64, 81, 219, 2, 29, 50, -12, '2026-10-04 15:03:00', 4),
(64, 81, 220, 2, 1, 1, -2, '2026-10-04 15:03:00', 4),
(64, 81, 221, 2, 14, 25, -7, '2026-10-04 15:03:00', 4),
(64, 81, 222, 2, 19, 33, -22, '2026-10-04 15:03:00', 4);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 12 WHERE id = 219;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 2 WHERE id = 220;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 7 WHERE id = 221;
UPDATE voucher_lines SET shipped_qty = shipped_qty + 22 WHERE id = 222;
UPDATE vouchers SET status = 'COMPLETED', shipped_at = '2026-10-04 15:03:00', completed_at = '2026-10-04 15:03:00' WHERE id = 64;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (65, 'PN-2026-0017', 'IMPORT', 2, NULL, NULL, '2026-10-05', 'Nhập hàng từ NCC Unilever', 4, '2026-10-05 09:27:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(223, 65, 30, 52, 190),
(224, 65, 25, 43, 50),
(225, 65, 26, 45, 70),
(226, 65, 13, 23, 90);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-05 11:37:00' WHERE id = 65;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (82, 65, 'PN-2026-0017/1', 'RECEIVE', NULL, 4, '2026-10-05 11:37:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(82, 223, 190, 0),
(82, 224, 50, 0),
(82, 225, 70, 0),
(82, 226, 90, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(65, 82, 223, 2, 30, 52, 190, '2026-10-05 11:37:00', 4),
(65, 82, 224, 2, 25, 43, 50, '2026-10-05 11:37:00', 4),
(65, 82, 225, 2, 26, 45, 70, '2026-10-05 11:37:00', 4),
(65, 82, 226, 2, 13, 23, 90, '2026-10-05 11:37:00', 4);
UPDATE voucher_lines SET received_qty = received_qty + 190 WHERE id = 223;
UPDATE voucher_lines SET received_qty = received_qty + 50 WHERE id = 224;
UPDATE voucher_lines SET received_qty = received_qty + 70 WHERE id = 225;
UPDATE voucher_lines SET received_qty = received_qty + 90 WHERE id = 226;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-05 11:37:00' WHERE id = 65;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (66, 'PN-2026-0018', 'IMPORT', 1, NULL, NULL, '2026-10-06', 'Nhập hàng từ NCC Tường An', 3, '2026-10-06 08:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(227, 66, 5, 8, 100);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-06 09:00:00' WHERE id = 66;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (83, 66, 'PN-2026-0018/1', 'RECEIVE', NULL, 3, '2026-10-06 09:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(83, 227, 100, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(66, 83, 227, 1, 5, 8, 100, '2026-10-06 09:00:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 100 WHERE id = 227;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-06 09:00:00' WHERE id = 66;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (67, 'DC-2026-0001', 'ADJUSTMENT', 1, NULL, 66, '2026-10-07', 'Hủy phiếu PN-2026-0018: nhập nhầm 100 thay vì 10', 3, '2026-10-07 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(228, 67, 5, 8, -100);
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (84, 67, 'DC-2026-0001/1', 'ADJUST', NULL, 2, '2026-10-07 08:30:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(84, 228, -100, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(67, 84, 228, 1, 5, 8, -100, '2026-10-07 08:30:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-07 08:30:00' WHERE id = 67;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (68, 'PN-2026-0019', 'IMPORT', 1, NULL, NULL, '2026-10-07', 'Nhập lại đúng số lượng thay cho phiếu PN-2026-0018', 3, '2026-10-07 08:40:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(229, 68, 5, 8, 10);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-07 09:00:00' WHERE id = 68;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (85, 68, 'PN-2026-0019/1', 'RECEIVE', NULL, 3, '2026-10-07 09:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(85, 229, 10, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(68, 85, 229, 1, 5, 8, 10, '2026-10-07 09:00:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 10 WHERE id = 229;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-07 09:00:00' WHERE id = 68;

INSERT INTO stock_counts (id, code, warehouse_id, status, note, created_by, created_at)
VALUES (1, 'KK-2026-0001', 2, 'COUNTING', 'Kiểm kê định kỳ tháng 10', 4, '2026-10-10 07:30:00');
INSERT INTO stock_count_lines (count_id, item_id, batch_id, system_qty, counted_qty, reason, counted_by, counted_at) VALUES
(1, 1, 1, 154, 154, NULL, 4, '2026-10-10 15:00:00'),
(1, 5, 9, 219, 219, NULL, 4, '2026-10-10 15:00:00'),
(1, 6, 10, 178, 178, NULL, 4, '2026-10-10 15:00:00'),
(1, 6, 11, 288, 288, NULL, 4, '2026-10-10 15:00:00'),
(1, 7, 13, 277, 277, NULL, 4, '2026-10-10 15:00:00'),
(1, 8, 14, 20, 20, NULL, 4, '2026-10-10 15:00:00'),
(1, 9, 15, 3, 3, NULL, 4, '2026-10-10 15:00:00'),
(1, 9, 16, 16, 16, NULL, 4, '2026-10-10 15:00:00'),
(1, 10, 17, 100, 97, 'Hàng móp méo, hư hỏng khi bốc xếp', 4, '2026-10-10 15:00:00'),
(1, 11, 20, 4, 4, NULL, 4, '2026-10-10 15:00:00'),
(1, 12, 21, 22, 24, 'Hàng nhập dư chưa ghi sổ', 4, '2026-10-10 15:00:00'),
(1, 13, 23, 90, 90, NULL, 4, '2026-10-10 15:00:00'),
(1, 14, 24, 330, 330, NULL, 4, '2026-10-10 15:00:00'),
(1, 14, 25, 83, 83, NULL, 4, '2026-10-10 15:00:00'),
(1, 15, 27, 407, 406, 'Thất lạc, chưa xác định nguyên nhân', 4, '2026-10-10 15:00:00'),
(1, 17, 29, 170, 170, NULL, 4, '2026-10-10 15:00:00'),
(1, 19, 33, 117, 117, NULL, 4, '2026-10-10 15:00:00'),
(1, 20, 35, 127, 127, NULL, 4, '2026-10-10 15:00:00'),
(1, 21, 37, 170, 170, NULL, 4, '2026-10-10 15:00:00'),
(1, 22, 38, 70, 70, NULL, 4, '2026-10-10 15:00:00'),
(1, 23, 40, 14, 14, NULL, 4, '2026-10-10 15:00:00'),
(1, 23, 41, 69, 69, NULL, 4, '2026-10-10 15:00:00'),
(1, 24, 42, 100, 100, NULL, 4, '2026-10-10 15:00:00'),
(1, 25, 43, 243, 243, NULL, 4, '2026-10-10 15:00:00'),
(1, 25, 44, 13, 13, NULL, 4, '2026-10-10 15:00:00'),
(1, 26, 45, 70, 70, NULL, 4, '2026-10-10 15:00:00'),
(1, 27, 47, 328, 328, NULL, 4, '2026-10-10 15:00:00'),
(1, 27, 48, 178, 178, NULL, 4, '2026-10-10 15:00:00'),
(1, 28, 49, 230, 230, NULL, 4, '2026-10-10 15:00:00'),
(1, 29, 50, 107, 107, NULL, 4, '2026-10-10 15:00:00'),
(1, 29, 51, 126, 126, NULL, 4, '2026-10-10 15:00:00'),
(1, 30, 52, 301, 301, NULL, 4, '2026-10-10 15:00:00'),
(1, 30, 53, 109, 109, NULL, 4, '2026-10-10 15:00:00');

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (69, 'DC-2026-0002', 'ADJUSTMENT', 2, NULL, NULL, '2026-10-11', 'Điều chỉnh chênh lệch theo kiểm kê KK-2026-0001', 4, '2026-10-11 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(230, 69, 10, 17, -3),
(231, 69, 12, 21, 2),
(232, 69, 15, 27, -1);
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (86, 69, 'DC-2026-0002/1', 'ADJUST', NULL, 2, '2026-10-11 09:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(86, 230, -3, 0),
(86, 231, 2, 0),
(86, 232, -1, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(69, 86, 230, 2, 10, 17, -3, '2026-10-11 09:00:00', 2),
(69, 86, 231, 2, 12, 21, 2, '2026-10-11 09:00:00', 2),
(69, 86, 232, 2, 15, 27, -1, '2026-10-11 09:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-11 09:00:00' WHERE id = 69;

UPDATE stock_counts SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-11 09:00:00', adjustment_voucher_id = 69 WHERE id = 1;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (70, 'PN-2026-0020', 'IMPORT', 1, NULL, NULL, '2026-10-18', 'Nhập lô dùng cho phép thử 1 (tồn đúng 5)', 3, '2026-10-18 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(233, 70, 3, 54, 5);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-18 09:00:00' WHERE id = 70;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (87, 70, 'PN-2026-0020/1', 'RECEIVE', NULL, 3, '2026-10-18 09:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(87, 233, 5, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(70, 87, 233, 1, 3, 54, 5, '2026-10-18 09:00:00', 3);
UPDATE voucher_lines SET received_qty = received_qty + 5 WHERE id = 233;
UPDATE vouchers SET status = 'COMPLETED', completed_at = '2026-10-18 09:00:00' WHERE id = 70;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (71, 'PX-2026-0032', 'EXPORT', 1, NULL, NULL, '2026-10-20', 'Phép thử 1 - yêu cầu xuất thứ nhất', 3, '2026-10-20 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(234, 71, 3, 54, 4);

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (72, 'PX-2026-0033', 'EXPORT', 1, NULL, NULL, '2026-10-20', 'Phép thử 1 - yêu cầu xuất thứ hai', 3, '2026-10-20 08:01:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(235, 72, 3, 54, 4);

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (73, 'PC-2026-0018', 'TRANSFER', 1, 2, NULL, '2026-10-20', 'Phép thử 2 - điều chuyển HN sang HCM', 3, '2026-10-20 09:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(236, 73, 10, 18, 10);

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (74, 'PN-2026-0021', 'IMPORT', 3, NULL, NULL, '2026-10-22', 'Nhập hàng từ NCC Vinamilk (giao nhiều đợt)', 5, '2026-10-22 08:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(237, 74, 1, 2, 100),
(238, 74, 24, 42, 48);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-22 09:00:00' WHERE id = 74;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (88, 74, 'PN-2026-0021/1', 'RECEIVE', '2 hộp sữa móp méo, trả lại NCC', 5, '2026-10-24 10:15:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(88, 237, 60, 2),
(88, 238, 48, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(74, 88, 237, 3, 1, 2, 60, '2026-10-24 10:15:00', 5),
(74, 88, 238, 3, 24, 42, 48, '2026-10-24 10:15:00', 5);
UPDATE voucher_lines SET received_qty = received_qty + 60, damaged_qty = damaged_qty + 2 WHERE id = 237;
UPDATE voucher_lines SET received_qty = received_qty + 48 WHERE id = 238;
UPDATE vouchers SET status = 'PARTIAL' WHERE id = 74;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (75, 'PN-2026-0022', 'IMPORT', 2, NULL, NULL, '2026-10-25', 'Nhập hàng từ NCC Masan (chờ hàng về)', 4, '2026-10-25 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(239, 75, 26, 46, 60),
(240, 75, 27, 48, 80);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-25 09:30:00' WHERE id = 75;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (76, 'PX-2026-0034', 'EXPORT', 3, NULL, NULL, '2026-10-26', 'Xuất bán cho Siêu thị Hòa Bình (chờ xe lấy hàng)', 5, '2026-10-26 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(241, 76, 1, 2, 20),
(242, 76, 4, 7, 15);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-26 09:00:00' WHERE id = 76;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (77, 'PC-2026-0019', 'TRANSFER', 3, 2, NULL, '2026-10-21', 'Theo yêu cầu YC-2026-0001: Đại lý Phú Quý (HCM) đặt hàng, cần trước 25/10', 2, '2026-10-21 09:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(243, 77, 6, 10, 12);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-21 09:00:00' WHERE id = 77;
INSERT INTO voucher_postings (id, voucher_id, code, kind, note, created_by, created_at) VALUES (89, 77, 'PC-2026-0019/1', 'SEND', NULL, 5, '2026-10-21 14:00:00');
INSERT INTO voucher_posting_lines (posting_id, voucher_line_id, quantity, damaged_qty) VALUES
(89, 243, 12, 0);
INSERT INTO stock_movements (voucher_id, posting_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(77, 89, 243, 3, 6, 10, -12, '2026-10-21 14:00:00', 5),
(77, 89, 243, 4, 6, 10, 12, '2026-10-21 14:00:00', 5);
UPDATE voucher_lines SET shipped_qty = shipped_qty + 12 WHERE id = 243;
UPDATE vouchers SET status = 'IN_TRANSIT', shipped_at = '2026-10-21 14:00:00' WHERE id = 77;

INSERT INTO transfer_requests (id, code, from_warehouse_id, to_warehouse_id, needed_by, reason, created_by, created_at) VALUES
(1, 'YC-2026-0001', 3, 2, '2026-10-25', 'Đại lý Phú Quý (HCM) đặt hàng, cần trước 25/10', 4, '2026-10-20 16:00:00'),
(2, 'YC-2026-0002', 1, 2, '2026-11-02', 'Cửa hàng Thành Công đặt hàng, giao trước 02/11', 4, '2026-10-27 15:30:00');
INSERT INTO transfer_request_lines (request_id, item_id, quantity) VALUES
(1, 6, 12),
(2, 1, 20),
(2, 3, 15);
UPDATE transfer_requests SET status = 'ACCEPTED', transfer_voucher_id = 77, decided_by = 2, decided_at = '2026-10-21 09:00:00', decision_note = 'Đồng ý, gửi chuyến chiều 21/10' WHERE id = 1;

-- Tồn hiện tại = SUM(stock_movements) theo kho/lô; reserved = hàng đang giữ chỗ
INSERT INTO stocks (warehouse_id, item_id, batch_id, quantity, reserved) VALUES
(1, 1, 2, 185, 0),
(1, 3, 5, 118, 0),
(1, 5, 8, 10, 0),
(1, 5, 9, 152, 0),
(1, 9, 15, 132, 0),
(1, 9, 16, 48, 0),
(1, 10, 18, 357, 0),
(1, 11, 19, 200, 0),
(1, 12, 21, 330, 0),
(1, 13, 22, 59, 0),
(1, 14, 25, 266, 0),
(1, 15, 27, 243, 0),
(1, 17, 29, 112, 0),
(1, 18, 31, 158, 0),
(1, 18, 32, 76, 0),
(1, 23, 41, 82, 0),
(1, 24, 42, 166, 0),
(1, 25, 43, 261, 0),
(1, 25, 44, 177, 0),
(1, 26, 45, 64, 0),
(1, 27, 47, 47, 0),
(1, 27, 48, 103, 0),
(1, 29, 50, 345, 0),
(1, 29, 51, 203, 0),
(1, 30, 52, 223, 0),
(1, 3, 54, 5, 0),
(2, 1, 1, 154, 0),
(2, 5, 9, 219, 0),
(2, 6, 10, 178, 0),
(2, 6, 11, 288, 0),
(2, 7, 13, 277, 0),
(2, 8, 14, 20, 0),
(2, 9, 15, 3, 0),
(2, 9, 16, 16, 0),
(2, 10, 17, 97, 0),
(2, 11, 20, 4, 0),
(2, 12, 21, 24, 0),
(2, 13, 23, 90, 0),
(2, 14, 24, 330, 0),
(2, 14, 25, 83, 0),
(2, 15, 27, 406, 0),
(2, 17, 29, 170, 0),
(2, 19, 33, 117, 0),
(2, 20, 35, 127, 0),
(2, 21, 37, 170, 0),
(2, 22, 38, 70, 0),
(2, 23, 40, 14, 0),
(2, 23, 41, 69, 0),
(2, 24, 42, 100, 0),
(2, 25, 43, 243, 0),
(2, 25, 44, 13, 0),
(2, 26, 45, 70, 0),
(2, 27, 47, 328, 0),
(2, 27, 48, 178, 0),
(2, 28, 49, 230, 0),
(2, 29, 50, 107, 0),
(2, 29, 51, 126, 0),
(2, 30, 52, 301, 0),
(2, 30, 53, 109, 0),
(3, 1, 2, 220, 20),
(3, 4, 7, 320, 15),
(3, 5, 9, 19, 0),
(3, 6, 10, 122, 0),
(3, 6, 11, 72, 0),
(3, 8, 14, 80, 0),
(3, 9, 16, 63, 0),
(3, 10, 18, 451, 0),
(3, 11, 19, 115, 0),
(3, 11, 20, 84, 0),
(3, 12, 21, 282, 0),
(3, 13, 22, 181, 0),
(3, 17, 29, 166, 0),
(3, 18, 32, 154, 0),
(3, 20, 35, 69, 0),
(3, 22, 39, 225, 0),
(3, 23, 40, 247, 0),
(3, 23, 41, 19, 0),
(3, 24, 42, 218, 0),
(3, 25, 43, 100, 0),
(3, 25, 44, 48, 0),
(3, 26, 45, 143, 0),
(3, 26, 46, 160, 0),
(3, 27, 48, 42, 0),
(3, 29, 50, 65, 0),
(3, 30, 52, 9, 0),
(4, 5, 9, 0, 0),
(4, 6, 10, 12, 0),
(4, 8, 14, 0, 0),
(4, 9, 15, 0, 0),
(4, 9, 16, 0, 0),
(4, 11, 20, 0, 0),
(4, 12, 21, 0, 0),
(4, 17, 29, 0, 0),
(4, 18, 32, 0, 0),
(4, 23, 40, 0, 0),
(4, 23, 41, 0, 0),
(4, 25, 43, 0, 0),
(4, 25, 44, 0, 0),
(4, 26, 45, 0, 0),
(4, 27, 47, 0, 0),
(4, 27, 48, 0, 0),
(4, 29, 50, 0, 0),
(4, 30, 52, 0, 0);

-- Phiếu chờ duyệt cho phép thử: PX-2026-0032, PX-2026-0033 (phép thử 1), PC-2026-0018 (phép thử 2)
-- Minh họa: PN-2026-0021 nhận một phần, PC-2026-0019 đang đi đường (quá hạn), YC-2026-0002 chờ kho HN xử lý
