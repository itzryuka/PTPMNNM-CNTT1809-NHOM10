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
--   3. stocks.quantity >= 0 (CHECK) — lưới an toàn cuối cùng chống xuất âm.
--   4. Chứng từ đã APPROVED / REJECTED thì không sửa, không xóa được.
--      Muốn sửa sai -> lập chứng từ ADJUSTMENT có lý do, tham chiếu chứng từ gốc.
--   5. Mỗi dòng chứng từ chỉ ghi sổ 1 lần cho mỗi kho
--      (UNIQUE voucher_line_id + warehouse_id) — chống ghi đúp ở mức CSDL.
--   6. Điều chuyển = 1 chứng từ TRANSFER sinh 2 dòng phát sinh trong cùng
--      giao dịch: (-) tại kho đi và (+) tại kho đến, cùng trỏ về chứng từ đó.
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
  is_active      TINYINT(1)   NOT NULL DEFAULT 1,
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
  created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_warehouses_code (code)
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
  UNIQUE KEY uq_items_sku (sku)
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
  status                ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
  reason                VARCHAR(255) NULL,          -- ghi chú / lý do (BẮT BUỘC với ADJUSTMENT)
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
CREATE TABLE voucher_lines (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id  INT UNSIGNED  NOT NULL,
  item_id     INT UNSIGNED  NOT NULL,
  batch_id    INT UNSIGNED  NOT NULL,
  quantity    DECIMAL(15,3) NOT NULL,               -- số lượng yêu cầu
  note        VARCHAR(255)  NULL,
  UNIQUE KEY uq_vl_voucher_batch (voucher_id, item_id, batch_id),
  KEY idx_vl_item (item_id),
  CONSTRAINT fk_vl_voucher FOREIGN KEY (voucher_id)        REFERENCES vouchers(id) ON DELETE CASCADE,
  CONSTRAINT fk_vl_item    FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_vl_batch   FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT ck_vl_qty CHECK (quantity <> 0)
) ENGINE=InnoDB;

-- =====================================================================
-- 4. SỔ PHÁT SINH (STOCK LEDGER) — CHỈ INSERT
-- =====================================================================

CREATE TABLE stock_movements (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  voucher_id       INT UNSIGNED  NOT NULL,
  voucher_line_id  INT UNSIGNED  NOT NULL,
  warehouse_id     INT UNSIGNED  NOT NULL,
  item_id          INT UNSIGNED  NOT NULL,
  batch_id         INT UNSIGNED  NOT NULL,
  quantity         DECIMAL(15,3) NOT NULL,          -- (+) nhập, (-) xuất
  moved_at         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- ngày giờ thực tế
  created_by       INT UNSIGNED  NOT NULL,
  -- Chống ghi đúp: 1 dòng chứng từ chỉ sinh tối đa 1 phát sinh cho mỗi kho
  -- (TRANSFER sinh 2 dòng: -kho đi, +kho đến -> khác warehouse_id nên hợp lệ)
  UNIQUE KEY uq_sm_line_wh (voucher_line_id, warehouse_id),
  KEY idx_sm_stock (warehouse_id, item_id, batch_id, moved_at),
  KEY idx_sm_item_time (item_id, moved_at),
  KEY idx_sm_voucher (voucher_id),
  KEY idx_sm_moved_at (moved_at),
  CONSTRAINT fk_sm_voucher FOREIGN KEY (voucher_id)        REFERENCES vouchers(id),
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
  quantity      DECIMAL(15,3) NOT NULL DEFAULT 0,
  updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_stocks (warehouse_id, item_id, batch_id),   -- dùng cho INSERT ... ON DUPLICATE KEY UPDATE
  KEY idx_stocks_item (item_id),
  CONSTRAINT fk_stocks_wh    FOREIGN KEY (warehouse_id)      REFERENCES warehouses(id),
  CONSTRAINT fk_stocks_item  FOREIGN KEY (item_id)           REFERENCES items(id),
  CONSTRAINT fk_stocks_batch FOREIGN KEY (batch_id, item_id) REFERENCES batches(id, item_id),
  CONSTRAINT ck_stocks_non_negative CHECK (quantity >= 0)   -- KHÔNG BAO GIỜ ÂM
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

-- 7.2 Chỉ được ghi sổ cho chứng từ đang PENDING (đang trong transaction duyệt)
--     và phát sinh phải khớp với dòng chứng từ
CREATE TRIGGER trg_sm_before_insert BEFORE INSERT ON stock_movements
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  DECLARE v_line_voucher INT UNSIGNED;
  DECLARE v_line_item INT UNSIGNED;
  DECLARE v_line_batch INT UNSIGNED;

  SELECT status INTO v_status FROM vouchers WHERE id = NEW.voucher_id;
  IF v_status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da duyet/tu choi: khong duoc ghi so them (chong ghi dup)';
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

-- 7.3 Chứng từ đã APPROVED/REJECTED thì không sửa / không xóa
CREATE TRIGGER trg_vouchers_before_update BEFORE UPDATE ON vouchers
FOR EACH ROW
BEGIN
  IF OLD.status <> 'PENDING' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu da duyet/tu choi: khong duoc sua. Hay lap chung tu dieu chinh';
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

CREATE TRIGGER trg_vl_before_update BEFORE UPDATE ON voucher_lines
FOR EACH ROW
BEGIN
  DECLARE v_status VARCHAR(10);
  DECLARE v_type VARCHAR(12);
  SELECT status, type INTO v_status, v_type FROM vouchers WHERE id = OLD.voucher_id;
  IF v_status <> 'PENDING' OR NEW.voucher_id <> OLD.voucher_id THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Chung tu khong con PENDING: khong duoc sua dong';
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
       CASE WHEN sm.quantity > 0 THEN sm.quantity ELSE 0 END  AS qty_in,
       CASE WHEN sm.quantity < 0 THEN -sm.quantity ELSE 0 END AS qty_out,
       SUM(sm.quantity) OVER (
         PARTITION BY sm.warehouse_id, sm.item_id, sm.batch_id
         ORDER BY sm.moved_at, sm.id
       ) AS balance_after,
       sm.warehouse_id, sm.item_id, sm.batch_id, sm.voucher_id
FROM stock_movements sm
JOIN warehouses w ON w.id = sm.warehouse_id
JOIN items i      ON i.id = sm.item_id
JOIN batches b    ON b.id = sm.batch_id
JOIN vouchers v   ON v.id = sm.voucher_id;

-- 8.3 Tồn kho hiện tại (đầy đủ tên) — dùng cho trang danh sách tồn
CREATE VIEW v_current_stock AS
SELECT s.warehouse_id, w.code AS warehouse_code, w.name AS warehouse_name,
       s.item_id, i.sku, i.name AS item_name, i.unit,
       s.batch_id, b.batch_no, b.expiry_date,
       s.quantity, s.updated_at
FROM stocks s
JOIN warehouses w ON w.id = s.warehouse_id
JOIN items i      ON i.id = s.item_id
JOIN batches b    ON b.id = s.batch_id;

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

-- Chứng từ: lập PENDING -> ghi sổ phát sinh -> APPROVED (đúng thứ tự trigger yêu cầu)
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
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(1, 1, 1, 23, 41, 90, '2026-08-01 10:00:00', 2),
(1, 2, 1, 29, 50, 260, '2026-08-01 10:00:00', 2),
(1, 3, 1, 18, 31, 160, '2026-08-01 10:00:00', 2),
(1, 4, 1, 25, 43, 190, '2026-08-01 10:00:00', 2),
(1, 5, 1, 27, 48, 120, '2026-08-01 10:00:00', 2),
(1, 6, 1, 12, 21, 280, '2026-08-01 10:00:00', 2),
(1, 7, 1, 14, 25, 280, '2026-08-01 10:00:00', 2),
(1, 8, 1, 10, 18, 270, '2026-08-01 10:00:00', 2),
(1, 9, 1, 25, 44, 240, '2026-08-01 10:00:00', 2),
(1, 10, 1, 27, 47, 90, '2026-08-01 10:00:00', 2),
(1, 11, 1, 30, 52, 240, '2026-08-01 10:00:00', 2),
(1, 12, 1, 29, 51, 220, '2026-08-01 10:00:00', 2),
(1, 13, 1, 3, 5, 120, '2026-08-01 10:00:00', 2),
(1, 14, 1, 17, 29, 220, '2026-08-01 10:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-01 10:00:00' WHERE id = 1;

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
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(2, 15, 2, 25, 43, 260, '2026-08-02 10:00:00', 2),
(2, 16, 2, 7, 13, 180, '2026-08-02 10:00:00', 2),
(2, 17, 2, 1, 1, 80, '2026-08-02 10:00:00', 2),
(2, 18, 2, 15, 27, 290, '2026-08-02 10:00:00', 2),
(2, 19, 2, 23, 41, 90, '2026-08-02 10:00:00', 2),
(2, 20, 2, 27, 47, 180, '2026-08-02 10:00:00', 2),
(2, 21, 2, 30, 53, 190, '2026-08-02 10:00:00', 2),
(2, 22, 2, 29, 50, 120, '2026-08-02 10:00:00', 2),
(2, 23, 2, 19, 33, 170, '2026-08-02 10:00:00', 2),
(2, 24, 2, 29, 51, 180, '2026-08-02 10:00:00', 2),
(2, 25, 2, 30, 52, 120, '2026-08-02 10:00:00', 2),
(2, 26, 2, 6, 10, 100, '2026-08-02 10:00:00', 2),
(2, 27, 2, 28, 49, 230, '2026-08-02 10:00:00', 2),
(2, 28, 2, 17, 29, 160, '2026-08-02 10:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-02 10:00:00' WHERE id = 2;

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
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(3, 29, 3, 4, 7, 190, '2026-08-03 10:00:00', 2),
(3, 30, 3, 8, 14, 140, '2026-08-03 10:00:00', 2),
(3, 31, 3, 11, 20, 170, '2026-08-03 10:00:00', 2),
(3, 32, 3, 6, 11, 140, '2026-08-03 10:00:00', 2),
(3, 33, 3, 26, 45, 240, '2026-08-03 10:00:00', 2),
(3, 34, 3, 18, 32, 270, '2026-08-03 10:00:00', 2),
(3, 35, 3, 9, 16, 170, '2026-08-03 10:00:00', 2),
(3, 36, 3, 25, 44, 90, '2026-08-03 10:00:00', 2),
(3, 37, 3, 10, 18, 140, '2026-08-03 10:00:00', 2),
(3, 38, 3, 6, 10, 280, '2026-08-03 10:00:00', 2),
(3, 39, 3, 11, 19, 240, '2026-08-03 10:00:00', 2),
(3, 40, 3, 20, 35, 110, '2026-08-03 10:00:00', 2),
(3, 41, 3, 12, 21, 170, '2026-08-03 10:00:00', 2),
(3, 42, 3, 22, 39, 140, '2026-08-03 10:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-03 10:00:00' WHERE id = 3;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (4, 'PX-2026-0001', 'EXPORT', 1, NULL, NULL, '2026-08-05', 'Xuất bán cho Siêu thị Hòa Bình', 3, '2026-08-05 08:43:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(43, 4, 23, 41, 2),
(44, 4, 30, 52, 17),
(45, 4, 25, 44, 52),
(46, 4, 14, 25, 11);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(4, 43, 1, 23, 41, -2, '2026-08-05 16:56:00', 2),
(4, 44, 1, 30, 52, -17, '2026-08-05 16:56:00', 2),
(4, 45, 1, 25, 44, -52, '2026-08-05 16:56:00', 2),
(4, 46, 1, 14, 25, -11, '2026-08-05 16:56:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-05 16:56:00' WHERE id = 4;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (5, 'PX-2026-0002', 'EXPORT', 3, NULL, NULL, '2026-08-06', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-06 09:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(47, 5, 20, 35, 14),
(48, 5, 6, 11, 28),
(49, 5, 25, 44, 19),
(50, 5, 11, 19, 43);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(5, 47, 3, 20, 35, -14, '2026-08-06 13:45:00', 2),
(5, 48, 3, 6, 11, -28, '2026-08-06 13:45:00', 2),
(5, 49, 3, 25, 44, -19, '2026-08-06 13:45:00', 2),
(5, 50, 3, 11, 19, -43, '2026-08-06 13:45:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-06 13:45:00' WHERE id = 5;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (6, 'PX-2026-0003', 'EXPORT', 2, NULL, NULL, '2026-08-07', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-07 10:14:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(51, 6, 29, 51, 39),
(52, 6, 25, 43, 15);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(6, 51, 2, 29, 51, -39, '2026-08-07 14:35:00', 2),
(6, 52, 2, 25, 43, -15, '2026-08-07 14:35:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-07 14:35:00' WHERE id = 6;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (7, 'PX-2026-0004', 'EXPORT', 3, NULL, NULL, '2026-08-08', 'Xuất bán cho Cửa hàng Thành Công', 5, '2026-08-08 08:46:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(53, 7, 8, 14, 21),
(54, 7, 11, 20, 26),
(55, 7, 20, 35, 2),
(56, 7, 6, 10, 21);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(7, 53, 3, 8, 14, -21, '2026-08-08 16:21:00', 2),
(7, 54, 3, 11, 20, -26, '2026-08-08 16:21:00', 2),
(7, 55, 3, 20, 35, -2, '2026-08-08 16:21:00', 2),
(7, 56, 3, 6, 10, -21, '2026-08-08 16:21:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-08 16:21:00' WHERE id = 7;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (8, 'PN-2026-0004', 'IMPORT', 2, NULL, NULL, '2026-08-09', 'Nhập hàng từ NCC Kinh Đô', 4, '2026-08-09 09:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(57, 8, 10, 17, 100),
(58, 8, 20, 35, 150),
(59, 8, 24, 42, 140);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(8, 57, 2, 10, 17, 100, '2026-08-09 12:15:00', 2),
(8, 58, 2, 20, 35, 150, '2026-08-09 12:15:00', 2),
(8, 59, 2, 24, 42, 140, '2026-08-09 12:15:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-09 12:15:00' WHERE id = 8;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (9, 'PC-2026-0001', 'TRANSFER', 3, 1, NULL, '2026-08-10', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-10 09:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(60, 9, 12, 21, 50),
(61, 9, 9, 16, 55);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(9, 60, 3, 12, 21, -50, '2026-08-10 13:44:00', 2),
(9, 60, 1, 12, 21, 50, '2026-08-10 13:44:00', 2),
(9, 61, 3, 9, 16, -55, '2026-08-10 13:44:00', 2),
(9, 61, 1, 9, 16, 55, '2026-08-10 13:44:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-10 13:44:00' WHERE id = 9;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (10, 'PC-2026-0002', 'TRANSFER', 3, 1, NULL, '2026-08-11', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-11 08:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(62, 10, 18, 32, 86);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(10, 62, 3, 18, 32, -86, '2026-08-11 16:07:00', 2),
(10, 62, 1, 18, 32, 86, '2026-08-11 16:07:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-11 16:07:00' WHERE id = 10;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (11, 'PX-2026-0005', 'EXPORT', 2, NULL, NULL, '2026-08-12', 'Xuất bán cho Đại lý Minh Anh', 4, '2026-08-12 10:20:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(63, 11, 29, 50, 1),
(64, 11, 27, 47, 19),
(65, 11, 20, 35, 23),
(66, 11, 24, 42, 21);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(11, 63, 2, 29, 50, -1, '2026-08-12 13:55:00', 2),
(11, 64, 2, 27, 47, -19, '2026-08-12 13:55:00', 2),
(11, 65, 2, 20, 35, -23, '2026-08-12 13:55:00', 2),
(11, 66, 2, 24, 42, -21, '2026-08-12 13:55:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-12 13:55:00' WHERE id = 11;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (12, 'PN-2026-0005', 'IMPORT', 1, NULL, NULL, '2026-08-13', 'Nhập hàng từ NCC Coca-Cola VN', 3, '2026-08-13 10:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(67, 12, 10, 18, 130),
(68, 12, 1, 2, 190),
(69, 12, 9, 15, 140);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(12, 67, 1, 10, 18, 130, '2026-08-13 11:04:00', 2),
(12, 68, 1, 1, 2, 190, '2026-08-13 11:04:00', 2),
(12, 69, 1, 9, 15, 140, '2026-08-13 11:04:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-13 11:04:00' WHERE id = 12;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (13, 'PX-2026-0006', 'EXPORT', 3, NULL, NULL, '2026-08-14', 'Xuất bán cho Đại lý Minh Anh', 5, '2026-08-14 09:08:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(70, 13, 8, 14, 2),
(71, 13, 18, 32, 30),
(72, 13, 9, 16, 4),
(73, 13, 25, 44, 9);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(13, 70, 3, 8, 14, -2, '2026-08-14 13:17:00', 2),
(13, 71, 3, 18, 32, -30, '2026-08-14 13:17:00', 2),
(13, 72, 3, 9, 16, -4, '2026-08-14 13:17:00', 2),
(13, 73, 3, 25, 44, -9, '2026-08-14 13:17:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-14 13:17:00' WHERE id = 13;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (14, 'PX-2026-0007', 'EXPORT', 3, NULL, NULL, '2026-08-15', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-08-15 09:56:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(74, 14, 9, 16, 4),
(75, 14, 22, 39, 25),
(76, 14, 11, 20, 30),
(77, 14, 12, 21, 4);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(14, 74, 3, 9, 16, -4, '2026-08-15 14:02:00', 2),
(14, 75, 3, 22, 39, -25, '2026-08-15 14:02:00', 2),
(14, 76, 3, 11, 20, -30, '2026-08-15 14:02:00', 2),
(14, 77, 3, 12, 21, -4, '2026-08-15 14:02:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-15 14:02:00' WHERE id = 14;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (15, 'PX-2026-0008', 'EXPORT', 2, NULL, NULL, '2026-08-16', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-16 10:31:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(78, 15, 19, 33, 7),
(79, 15, 17, 29, 12),
(80, 15, 24, 42, 6),
(81, 15, 30, 53, 5);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(15, 78, 2, 19, 33, -7, '2026-08-16 14:15:00', 2),
(15, 79, 2, 17, 29, -12, '2026-08-16 14:15:00', 2),
(15, 80, 2, 24, 42, -6, '2026-08-16 14:15:00', 2),
(15, 81, 2, 30, 53, -5, '2026-08-16 14:15:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-16 14:15:00' WHERE id = 15;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (16, 'PN-2026-0006', 'IMPORT', 2, NULL, NULL, '2026-08-17', 'Nhập hàng từ NCC Coca-Cola VN', 4, '2026-08-17 09:03:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(82, 16, 22, 38, 70),
(83, 16, 6, 11, 130),
(84, 16, 27, 47, 130);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(16, 82, 2, 22, 38, 70, '2026-08-17 11:12:00', 2),
(16, 83, 2, 6, 11, 130, '2026-08-17 11:12:00', 2),
(16, 84, 2, 27, 47, 130, '2026-08-17 11:12:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-17 11:12:00' WHERE id = 16;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (17, 'PC-2026-0003', 'TRANSFER', 3, 2, NULL, '2026-08-18', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-08-18 10:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(85, 17, 9, 16, 21);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(17, 85, 3, 9, 16, -21, '2026-08-18 14:30:00', 2),
(17, 85, 2, 9, 16, 21, '2026-08-18 14:30:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-18 14:30:00' WHERE id = 17;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (18, 'PN-2026-0007', 'IMPORT', 1, NULL, NULL, '2026-08-19', 'Nhập hàng từ NCC Sabeco', 3, '2026-08-19 10:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(86, 18, 24, 42, 170),
(87, 18, 5, 9, 140),
(88, 18, 13, 22, 60),
(89, 18, 15, 27, 160);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(18, 86, 1, 24, 42, 170, '2026-08-19 11:18:00', 2),
(18, 87, 1, 5, 9, 140, '2026-08-19 11:18:00', 2),
(18, 88, 1, 13, 22, 60, '2026-08-19 11:18:00', 2),
(18, 89, 1, 15, 27, 160, '2026-08-19 11:18:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-19 11:18:00' WHERE id = 18;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (19, 'PC-2026-0004', 'TRANSFER', 2, 1, NULL, '2026-08-20', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 4, '2026-08-20 10:49:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(90, 19, 25, 43, 71);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(19, 90, 2, 25, 43, -71, '2026-08-20 15:21:00', 2),
(19, 90, 1, 25, 43, 71, '2026-08-20 15:21:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-20 15:21:00' WHERE id = 19;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (20, 'PC-2026-0005', 'TRANSFER', 3, 2, NULL, '2026-08-21', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-08-21 08:29:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(91, 20, 25, 44, 6),
(92, 20, 6, 10, 55),
(93, 20, 12, 21, 29);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(20, 91, 3, 25, 44, -6, '2026-08-21 14:52:00', 2),
(20, 91, 2, 25, 44, 6, '2026-08-21 14:52:00', 2),
(20, 92, 3, 6, 10, -55, '2026-08-21 14:52:00', 2),
(20, 92, 2, 6, 10, 55, '2026-08-21 14:52:00', 2),
(20, 93, 3, 12, 21, -29, '2026-08-21 14:52:00', 2),
(20, 93, 2, 12, 21, 29, '2026-08-21 14:52:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-21 14:52:00' WHERE id = 20;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (21, 'PX-2026-0009', 'EXPORT', 3, NULL, NULL, '2026-08-22', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-08-22 10:49:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(94, 21, 11, 19, 9),
(95, 21, 20, 35, 14),
(96, 21, 6, 11, 26),
(97, 21, 4, 7, 12);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(21, 94, 3, 11, 19, -9, '2026-08-22 14:00:00', 2),
(21, 95, 3, 20, 35, -14, '2026-08-22 14:00:00', 2),
(21, 96, 3, 6, 11, -26, '2026-08-22 14:00:00', 2),
(21, 97, 3, 4, 7, -12, '2026-08-22 14:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-22 14:00:00' WHERE id = 21;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (22, 'PC-2026-0006', 'TRANSFER', 3, 1, NULL, '2026-08-23', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 5, '2026-08-23 10:16:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(98, 22, 26, 45, 64);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(22, 98, 3, 26, 45, -64, '2026-08-23 15:40:00', 2),
(22, 98, 1, 26, 45, 64, '2026-08-23 15:40:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-23 15:40:00' WHERE id = 22;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (23, 'PX-2026-0010', 'EXPORT', 3, NULL, NULL, '2026-08-24', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-24 11:20:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(99, 23, 11, 19, 7),
(100, 23, 8, 14, 17),
(101, 23, 6, 10, 34);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(23, 99, 3, 11, 19, -7, '2026-08-24 13:46:00', 2),
(23, 100, 3, 8, 14, -17, '2026-08-24 13:46:00', 2),
(23, 101, 3, 6, 10, -34, '2026-08-24 13:46:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-24 13:46:00' WHERE id = 23;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (24, 'PX-2026-0011', 'EXPORT', 1, NULL, NULL, '2026-08-25', 'Xuất bán cho Mini mart 24h', 3, '2026-08-25 08:03:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(102, 24, 9, 15, 5),
(103, 24, 18, 32, 6),
(104, 24, 18, 31, 2),
(105, 24, 10, 18, 43);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(24, 102, 1, 9, 15, -5, '2026-08-25 16:40:00', 2),
(24, 103, 1, 18, 32, -6, '2026-08-25 16:40:00', 2),
(24, 104, 1, 18, 31, -2, '2026-08-25 16:40:00', 2),
(24, 105, 1, 10, 18, -43, '2026-08-25 16:40:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-25 16:40:00' WHERE id = 24;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (25, 'PN-2026-0008', 'IMPORT', 3, NULL, NULL, '2026-08-26', 'Nhập hàng từ NCC Masan', 5, '2026-08-26 08:19:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(106, 25, 23, 40, 150),
(107, 25, 27, 48, 50),
(108, 25, 4, 7, 160);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(25, 106, 3, 23, 40, 150, '2026-08-26 11:36:00', 2),
(25, 107, 3, 27, 48, 50, '2026-08-26 11:36:00', 2),
(25, 108, 3, 4, 7, 160, '2026-08-26 11:36:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-26 11:36:00' WHERE id = 25;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (26, 'PX-2026-0012', 'EXPORT', 3, NULL, NULL, '2026-08-27', 'Xuất bán cho Mini mart 24h', 5, '2026-08-27 11:23:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(109, 26, 12, 21, 8),
(110, 26, 26, 45, 8);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(26, 109, 3, 12, 21, -8, '2026-08-27 15:19:00', 2),
(26, 110, 3, 26, 45, -8, '2026-08-27 15:19:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-27 15:19:00' WHERE id = 26;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (27, 'PX-2026-0013', 'EXPORT', 2, NULL, NULL, '2026-08-28', 'Xuất bán cho Đại lý Phú Quý', 4, '2026-08-28 08:10:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(111, 27, 27, 47, 6),
(112, 27, 15, 27, 72),
(113, 27, 19, 33, 24),
(114, 27, 29, 51, 8);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(27, 111, 2, 27, 47, -6, '2026-08-28 16:47:00', 2),
(27, 112, 2, 15, 27, -72, '2026-08-28 16:47:00', 2),
(27, 113, 2, 19, 33, -24, '2026-08-28 16:47:00', 2),
(27, 114, 2, 29, 51, -8, '2026-08-28 16:47:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-28 16:47:00' WHERE id = 27;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (28, 'PX-2026-0014', 'EXPORT', 3, NULL, NULL, '2026-08-29', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-08-29 10:56:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(115, 28, 11, 19, 33),
(116, 28, 10, 18, 8);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(28, 115, 3, 11, 19, -33, '2026-08-29 15:33:00', 2),
(28, 116, 3, 10, 18, -8, '2026-08-29 15:33:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-29 15:33:00' WHERE id = 28;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (29, 'PX-2026-0015', 'EXPORT', 2, NULL, NULL, '2026-08-30', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-08-30 10:14:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(117, 29, 1, 1, 4),
(118, 29, 15, 27, 21),
(119, 29, 25, 43, 11),
(120, 29, 25, 44, 1);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(29, 117, 2, 1, 1, -4, '2026-08-30 15:34:00', 2),
(29, 118, 2, 15, 27, -21, '2026-08-30 15:34:00', 2),
(29, 119, 2, 25, 43, -11, '2026-08-30 15:34:00', 2),
(29, 120, 2, 25, 44, -1, '2026-08-30 15:34:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-30 15:34:00' WHERE id = 29;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (30, 'PX-2026-0016', 'EXPORT', 1, NULL, NULL, '2026-08-31', 'Xuất bán cho Cửa hàng Thành Công', 3, '2026-08-31 10:40:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(121, 30, 3, 5, 2),
(122, 30, 17, 29, 42),
(123, 30, 29, 51, 17),
(124, 30, 24, 42, 4);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(30, 121, 1, 3, 5, -2, '2026-08-31 14:51:00', 2),
(30, 122, 1, 17, 29, -42, '2026-08-31 14:51:00', 2),
(30, 123, 1, 29, 51, -17, '2026-08-31 14:51:00', 2),
(30, 124, 1, 24, 42, -4, '2026-08-31 14:51:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-08-31 14:51:00' WHERE id = 30;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (31, 'PX-2026-0017', 'EXPORT', 3, NULL, NULL, '2026-09-01', 'Xuất bán cho Đại lý Minh Anh', 5, '2026-09-01 10:50:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(125, 31, 23, 40, 23),
(126, 31, 10, 18, 1),
(127, 31, 9, 16, 17),
(128, 31, 20, 35, 11);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(31, 125, 3, 23, 40, -23, '2026-09-01 15:03:00', 2),
(31, 126, 3, 10, 18, -1, '2026-09-01 15:03:00', 2),
(31, 127, 3, 9, 16, -17, '2026-09-01 15:03:00', 2),
(31, 128, 3, 20, 35, -11, '2026-09-01 15:03:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-01 15:03:00' WHERE id = 31;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (32, 'PX-2026-0018', 'EXPORT', 3, NULL, NULL, '2026-09-02', 'Xuất bán cho Siêu thị Hòa Bình', 5, '2026-09-02 10:39:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(129, 32, 11, 20, 26),
(130, 32, 6, 11, 5);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(32, 129, 3, 11, 20, -26, '2026-09-02 15:46:00', 2),
(32, 130, 3, 6, 11, -5, '2026-09-02 15:46:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-02 15:46:00' WHERE id = 32;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (33, 'PC-2026-0007', 'TRANSFER', 1, 2, NULL, '2026-09-03', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-03 10:28:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(131, 33, 27, 47, 26),
(132, 33, 9, 15, 3),
(133, 33, 17, 29, 4);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(33, 131, 1, 27, 47, -26, '2026-09-03 15:34:00', 2),
(33, 131, 2, 27, 47, 26, '2026-09-03 15:34:00', 2),
(33, 132, 1, 9, 15, -3, '2026-09-03 15:34:00', 2),
(33, 132, 2, 9, 15, 3, '2026-09-03 15:34:00', 2),
(33, 133, 1, 17, 29, -4, '2026-09-03 15:34:00', 2),
(33, 133, 2, 17, 29, 4, '2026-09-03 15:34:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-03 15:34:00' WHERE id = 33;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (34, 'PC-2026-0008', 'TRANSFER', 1, 2, NULL, '2026-09-04', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-04 08:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(134, 34, 17, 29, 40),
(135, 34, 5, 9, 29);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(34, 134, 1, 17, 29, -40, '2026-09-04 15:41:00', 2),
(34, 134, 2, 17, 29, 40, '2026-09-04 15:41:00', 2),
(34, 135, 1, 5, 9, -29, '2026-09-04 15:41:00', 2),
(34, 135, 2, 5, 9, 29, '2026-09-04 15:41:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-04 15:41:00' WHERE id = 34;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (35, 'PC-2026-0009', 'TRANSFER', 3, 2, NULL, '2026-09-05', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-05 09:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(136, 35, 25, 44, 8),
(137, 35, 27, 48, 4);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(35, 136, 3, 25, 44, -8, '2026-09-05 13:59:00', 2),
(35, 136, 2, 25, 44, 8, '2026-09-05 13:59:00', 2),
(35, 137, 3, 27, 48, -4, '2026-09-05 13:59:00', 2),
(35, 137, 2, 27, 48, 4, '2026-09-05 13:59:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-05 13:59:00' WHERE id = 35;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (36, 'PX-2026-0019', 'EXPORT', 1, NULL, NULL, '2026-09-06', 'Xuất bán cho Đại lý Phú Quý', 3, '2026-09-06 09:59:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(138, 36, 23, 41, 6),
(139, 36, 18, 32, 4),
(140, 36, 13, 22, 1),
(141, 36, 17, 29, 22);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(36, 138, 1, 23, 41, -6, '2026-09-06 15:41:00', 2),
(36, 139, 1, 18, 32, -4, '2026-09-06 15:41:00', 2),
(36, 140, 1, 13, 22, -1, '2026-09-06 15:41:00', 2),
(36, 141, 1, 17, 29, -22, '2026-09-06 15:41:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-06 15:41:00' WHERE id = 36;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (37, 'PX-2026-0020', 'EXPORT', 2, NULL, NULL, '2026-09-07', 'Xuất bán cho Mini mart 24h', 4, '2026-09-07 08:44:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(142, 37, 30, 53, 40),
(143, 37, 29, 51, 7);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(37, 142, 2, 30, 53, -40, '2026-09-07 16:39:00', 2),
(37, 143, 2, 29, 51, -7, '2026-09-07 16:39:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-07 16:39:00' WHERE id = 37;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (38, 'PC-2026-0010', 'TRANSFER', 3, 2, NULL, '2026-09-08', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-08 08:11:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(144, 38, 11, 20, 4),
(145, 38, 8, 14, 20),
(146, 38, 23, 40, 14);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(38, 144, 3, 11, 20, -4, '2026-09-08 16:28:00', 2),
(38, 144, 2, 11, 20, 4, '2026-09-08 16:28:00', 2),
(38, 145, 3, 8, 14, -20, '2026-09-08 16:28:00', 2),
(38, 145, 2, 8, 14, 20, '2026-09-08 16:28:00', 2),
(38, 146, 3, 23, 40, -14, '2026-09-08 16:28:00', 2),
(38, 146, 2, 23, 40, 14, '2026-09-08 16:28:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-08 16:28:00' WHERE id = 38;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (39, 'PC-2026-0011', 'TRANSFER', 1, 2, NULL, '2026-09-09', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 3, '2026-09-09 08:31:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(147, 39, 27, 47, 17);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(39, 147, 1, 27, 47, -17, '2026-09-09 14:30:00', 2),
(39, 147, 2, 27, 47, 17, '2026-09-09 14:30:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-09 14:30:00' WHERE id = 39;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (40, 'PN-2026-0009', 'IMPORT', 2, NULL, NULL, '2026-09-10', 'Nhập hàng từ NCC Masan', 4, '2026-09-10 08:15:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(148, 40, 25, 43, 50),
(149, 40, 14, 24, 120),
(150, 40, 15, 27, 100),
(151, 40, 5, 9, 110);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(40, 148, 2, 25, 43, 50, '2026-09-10 12:41:00', 2),
(40, 149, 2, 14, 24, 120, '2026-09-10 12:41:00', 2),
(40, 150, 2, 15, 27, 100, '2026-09-10 12:41:00', 2),
(40, 151, 2, 5, 9, 110, '2026-09-10 12:41:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-10 12:41:00' WHERE id = 40;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (41, 'PC-2026-0012', 'TRANSFER', 2, 3, NULL, '2026-09-11', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-11 11:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(152, 41, 23, 41, 19),
(153, 41, 25, 43, 20);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(41, 152, 2, 23, 41, -19, '2026-09-11 13:44:00', 2),
(41, 152, 3, 23, 41, 19, '2026-09-11 13:44:00', 2),
(41, 153, 2, 25, 43, -20, '2026-09-11 13:44:00', 2),
(41, 153, 3, 25, 43, 20, '2026-09-11 13:44:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-11 13:44:00' WHERE id = 41;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (42, 'PC-2026-0013', 'TRANSFER', 2, 3, NULL, '2026-09-12', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-12 11:29:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(154, 42, 27, 48, 1);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(42, 154, 2, 27, 48, -1, '2026-09-12 14:33:00', 2),
(42, 154, 3, 27, 48, 1, '2026-09-12 14:33:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-12 14:33:00' WHERE id = 42;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (43, 'PX-2026-0021', 'EXPORT', 3, NULL, NULL, '2026-09-13', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-09-13 08:27:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(155, 43, 12, 21, 7),
(156, 43, 11, 19, 33),
(157, 43, 4, 7, 18),
(158, 43, 9, 16, 14);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(43, 155, 3, 12, 21, -7, '2026-09-13 13:57:00', 2),
(43, 156, 3, 11, 19, -33, '2026-09-13 13:57:00', 2),
(43, 157, 3, 4, 7, -18, '2026-09-13 13:57:00', 2),
(43, 158, 3, 9, 16, -14, '2026-09-13 13:57:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-13 13:57:00' WHERE id = 43;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (44, 'PN-2026-0010', 'IMPORT', 3, NULL, NULL, '2026-09-14', 'Nhập hàng từ NCC Coca-Cola VN', 5, '2026-09-14 09:34:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(159, 44, 17, 29, 180),
(160, 44, 24, 42, 170),
(161, 44, 12, 21, 120),
(162, 44, 23, 40, 160),
(163, 44, 1, 2, 160);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(44, 159, 3, 17, 29, 180, '2026-09-14 11:36:00', 2),
(44, 160, 3, 24, 42, 170, '2026-09-14 11:36:00', 2),
(44, 161, 3, 12, 21, 120, '2026-09-14 11:36:00', 2),
(44, 162, 3, 23, 40, 160, '2026-09-14 11:36:00', 2),
(44, 163, 3, 1, 2, 160, '2026-09-14 11:36:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-14 11:36:00' WHERE id = 44;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (45, 'PN-2026-0011', 'IMPORT', 3, NULL, NULL, '2026-09-15', 'Nhập hàng từ NCC Tân Hiệp Phát', 5, '2026-09-15 08:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(164, 45, 22, 39, 180),
(165, 45, 26, 46, 160),
(166, 45, 10, 18, 120),
(167, 45, 13, 22, 190),
(168, 45, 25, 43, 80);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(45, 164, 3, 22, 39, 180, '2026-09-15 12:49:00', 2),
(45, 165, 3, 26, 46, 160, '2026-09-15 12:49:00', 2),
(45, 166, 3, 10, 18, 120, '2026-09-15 12:49:00', 2),
(45, 167, 3, 13, 22, 190, '2026-09-15 12:49:00', 2),
(45, 168, 3, 25, 43, 80, '2026-09-15 12:49:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-15 12:49:00' WHERE id = 45;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (46, 'PC-2026-0014', 'TRANSFER', 2, 3, NULL, '2026-09-16', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 4, '2026-09-16 08:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(169, 46, 30, 52, 9);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(46, 169, 2, 30, 52, -9, '2026-09-16 14:15:00', 2),
(46, 169, 3, 30, 52, 9, '2026-09-16 14:15:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-16 14:15:00' WHERE id = 46;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (47, 'PX-2026-0022', 'EXPORT', 1, NULL, NULL, '2026-09-17', 'Xuất bán cho Siêu thị Hòa Bình', 3, '2026-09-17 09:09:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(170, 47, 14, 25, 2),
(171, 47, 29, 50, 40);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(47, 170, 1, 14, 25, -2, '2026-09-17 16:48:00', 2),
(47, 171, 1, 29, 50, -40, '2026-09-17 16:48:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-17 16:48:00' WHERE id = 47;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (48, 'PX-2026-0023', 'EXPORT', 1, NULL, NULL, '2026-09-18', 'Xuất bán cho Đại lý Minh Anh', 3, '2026-09-18 08:58:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(172, 48, 25, 44, 11),
(173, 48, 14, 25, 1),
(174, 48, 27, 48, 17);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(48, 172, 1, 25, 44, -11, '2026-09-18 15:26:00', 2),
(48, 173, 1, 14, 25, -1, '2026-09-18 15:26:00', 2),
(48, 174, 1, 27, 48, -17, '2026-09-18 15:26:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-18 15:26:00' WHERE id = 48;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (49, 'PX-2026-0024', 'EXPORT', 2, NULL, NULL, '2026-09-19', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-09-19 09:08:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(175, 49, 24, 42, 13),
(176, 49, 23, 41, 2);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(49, 175, 2, 24, 42, -13, '2026-09-19 13:17:00', 2),
(49, 176, 2, 23, 41, -2, '2026-09-19 13:17:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-19 13:17:00' WHERE id = 49;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (50, 'PX-2026-0025', 'EXPORT', 2, NULL, NULL, '2026-09-20', 'Xuất bán cho Cửa hàng Thành Công', 4, '2026-09-20 09:25:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(177, 50, 30, 53, 6),
(178, 50, 6, 11, 29),
(179, 50, 12, 21, 7);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(50, 177, 2, 30, 53, -6, '2026-09-20 15:14:00', 2),
(50, 178, 2, 6, 11, -29, '2026-09-20 15:14:00', 2),
(50, 179, 2, 12, 21, -7, '2026-09-20 15:14:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-20 15:14:00' WHERE id = 50;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (51, 'PX-2026-0026', 'EXPORT', 2, NULL, NULL, '2026-09-21', 'Xuất bán cho Tạp hóa Cô Ba', 4, '2026-09-21 11:04:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(180, 51, 7, 13, 23),
(181, 51, 6, 11, 3),
(182, 51, 30, 53, 30);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(51, 180, 2, 7, 13, -23, '2026-09-21 15:09:00', 2),
(51, 181, 2, 6, 11, -3, '2026-09-21 15:09:00', 2),
(51, 182, 2, 30, 53, -30, '2026-09-21 15:09:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-21 15:09:00' WHERE id = 51;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (52, 'PN-2026-0012', 'IMPORT', 2, NULL, NULL, '2026-09-22', 'Nhập hàng từ NCC Sabeco', 4, '2026-09-22 10:13:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(183, 52, 15, 27, 110),
(184, 52, 5, 9, 80),
(185, 52, 14, 24, 160);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(52, 183, 2, 15, 27, 110, '2026-09-22 12:39:00', 2),
(52, 184, 2, 5, 9, 80, '2026-09-22 12:39:00', 2),
(52, 185, 2, 14, 24, 160, '2026-09-22 12:39:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-22 12:39:00' WHERE id = 52;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (53, 'PC-2026-0015', 'TRANSFER', 3, 2, NULL, '2026-09-23', 'Điều chuyển bổ sung hàng cho Kho TP.HCM', 5, '2026-09-23 11:34:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(186, 53, 27, 48, 5),
(187, 53, 6, 10, 36),
(188, 53, 17, 29, 1);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(53, 186, 3, 27, 48, -5, '2026-09-23 14:31:00', 2),
(53, 186, 2, 27, 48, 5, '2026-09-23 14:31:00', 2),
(53, 187, 3, 6, 10, -36, '2026-09-23 14:31:00', 2),
(53, 187, 2, 6, 10, 36, '2026-09-23 14:31:00', 2),
(53, 188, 3, 17, 29, -1, '2026-09-23 14:31:00', 2),
(53, 188, 2, 17, 29, 1, '2026-09-23 14:31:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-23 14:31:00' WHERE id = 53;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (54, 'PN-2026-0013', 'IMPORT', 2, NULL, NULL, '2026-09-24', 'Nhập hàng từ NCC Tân Hiệp Phát', 4, '2026-09-24 09:32:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(189, 54, 6, 11, 120),
(190, 54, 1, 1, 80),
(191, 54, 14, 25, 90),
(192, 54, 27, 48, 170);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(54, 189, 2, 6, 11, 120, '2026-09-24 12:34:00', 2),
(54, 190, 2, 1, 1, 80, '2026-09-24 12:34:00', 2),
(54, 191, 2, 14, 25, 90, '2026-09-24 12:34:00', 2),
(54, 192, 2, 27, 48, 170, '2026-09-24 12:34:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-24 12:34:00' WHERE id = 54;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (55, 'PN-2026-0014', 'IMPORT', 3, NULL, NULL, '2026-09-25', 'Nhập hàng từ NCC Coca-Cola VN', 5, '2026-09-25 09:24:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(193, 55, 13, 22, 50),
(194, 55, 10, 18, 200),
(195, 55, 12, 21, 90);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(55, 193, 3, 13, 22, 50, '2026-09-25 11:57:00', 2),
(55, 194, 3, 10, 18, 200, '2026-09-25 11:57:00', 2),
(55, 195, 3, 12, 21, 90, '2026-09-25 11:57:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-25 11:57:00' WHERE id = 55;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (56, 'PX-2026-0027', 'EXPORT', 2, NULL, NULL, '2026-09-26', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-09-26 09:50:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(196, 56, 6, 10, 13),
(197, 56, 17, 29, 23);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(56, 196, 2, 6, 10, -13, '2026-09-26 13:13:00', 2),
(56, 197, 2, 17, 29, -23, '2026-09-26 13:13:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-26 13:13:00' WHERE id = 56;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (57, 'PN-2026-0015', 'IMPORT', 2, NULL, NULL, '2026-09-27', 'Nhập hàng từ NCC Unilever', 4, '2026-09-27 08:48:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(198, 57, 6, 11, 70),
(199, 57, 14, 24, 50),
(200, 57, 21, 37, 170),
(201, 57, 7, 13, 120);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(57, 198, 2, 6, 11, 70, '2026-09-27 11:23:00', 2),
(57, 199, 2, 14, 24, 50, '2026-09-27 11:23:00', 2),
(57, 200, 2, 21, 37, 170, '2026-09-27 11:23:00', 2),
(57, 201, 2, 7, 13, 120, '2026-09-27 11:23:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-27 11:23:00' WHERE id = 57;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (58, 'PX-2026-0028', 'EXPORT', 3, NULL, NULL, '2026-09-28', 'Xuất bán cho Tạp hóa Cô Ba', 5, '2026-09-28 09:55:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(202, 58, 22, 39, 70),
(203, 58, 26, 45, 25),
(204, 58, 13, 22, 59);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(58, 202, 3, 22, 39, -70, '2026-09-28 16:31:00', 2),
(58, 203, 3, 26, 45, -25, '2026-09-28 16:31:00', 2),
(58, 204, 3, 13, 22, -59, '2026-09-28 16:31:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-28 16:31:00' WHERE id = 58;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (59, 'PC-2026-0016', 'TRANSFER', 1, 3, NULL, '2026-09-29', 'Điều chuyển bổ sung hàng cho Kho Đà Nẵng', 3, '2026-09-29 11:16:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(205, 59, 5, 9, 19),
(206, 59, 9, 16, 12),
(207, 59, 29, 50, 65);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(59, 205, 1, 5, 9, -19, '2026-09-29 16:07:00', 2),
(59, 205, 3, 5, 9, 19, '2026-09-29 16:07:00', 2),
(59, 206, 1, 9, 16, -12, '2026-09-29 16:07:00', 2),
(59, 206, 3, 9, 16, 12, '2026-09-29 16:07:00', 2),
(59, 207, 1, 29, 50, -65, '2026-09-29 16:07:00', 2),
(59, 207, 3, 29, 50, 65, '2026-09-29 16:07:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-29 16:07:00' WHERE id = 59;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (60, 'PX-2026-0029', 'EXPORT', 3, NULL, NULL, '2026-09-30', 'Xuất bán cho Đại lý Phú Quý', 5, '2026-09-30 08:02:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(208, 60, 9, 16, 4),
(209, 60, 23, 40, 26),
(210, 60, 6, 11, 9),
(211, 60, 17, 29, 13);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(60, 208, 3, 9, 16, -4, '2026-09-30 14:34:00', 2),
(60, 209, 3, 23, 40, -26, '2026-09-30 14:34:00', 2),
(60, 210, 3, 6, 11, -9, '2026-09-30 14:34:00', 2),
(60, 211, 3, 17, 29, -13, '2026-09-30 14:34:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-09-30 14:34:00' WHERE id = 60;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (61, 'PN-2026-0016', 'IMPORT', 1, NULL, NULL, '2026-10-01', 'Nhập hàng từ NCC Sabeco', 3, '2026-10-01 10:42:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(212, 61, 29, 50, 190),
(213, 61, 15, 27, 100),
(214, 61, 11, 19, 200),
(215, 61, 5, 9, 60);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(61, 212, 1, 29, 50, 190, '2026-10-01 12:30:00', 2),
(61, 213, 1, 15, 27, 100, '2026-10-01 12:30:00', 2),
(61, 214, 1, 11, 19, 200, '2026-10-01 12:30:00', 2),
(61, 215, 1, 5, 9, 60, '2026-10-01 12:30:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-01 12:30:00' WHERE id = 61;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (62, 'PC-2026-0017', 'TRANSFER', 2, 1, NULL, '2026-10-02', 'Điều chuyển bổ sung hàng cho Kho Hà Nội', 4, '2026-10-02 11:28:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(216, 62, 9, 16, 5);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(62, 216, 2, 9, 16, -5, '2026-10-02 16:51:00', 2),
(62, 216, 1, 9, 16, 5, '2026-10-02 16:51:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-02 16:51:00' WHERE id = 62;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (63, 'PX-2026-0030', 'EXPORT', 1, NULL, NULL, '2026-10-03', 'Xuất bán cho Đại lý Minh Anh', 3, '2026-10-03 08:46:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(217, 63, 15, 27, 17),
(218, 63, 1, 2, 5);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(63, 217, 1, 15, 27, -17, '2026-10-03 14:02:00', 2),
(63, 218, 1, 1, 2, -5, '2026-10-03 14:02:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-03 14:02:00' WHERE id = 63;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (64, 'PX-2026-0031', 'EXPORT', 2, NULL, NULL, '2026-10-04', 'Xuất bán cho Siêu thị Hòa Bình', 4, '2026-10-04 10:52:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(219, 64, 29, 50, 12),
(220, 64, 1, 1, 2),
(221, 64, 14, 25, 7),
(222, 64, 19, 33, 22);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(64, 219, 2, 29, 50, -12, '2026-10-04 15:03:00', 2),
(64, 220, 2, 1, 1, -2, '2026-10-04 15:03:00', 2),
(64, 221, 2, 14, 25, -7, '2026-10-04 15:03:00', 2),
(64, 222, 2, 19, 33, -22, '2026-10-04 15:03:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-04 15:03:00' WHERE id = 64;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (65, 'PN-2026-0017', 'IMPORT', 2, NULL, NULL, '2026-10-05', 'Nhập hàng từ NCC Unilever', 4, '2026-10-05 09:27:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(223, 65, 30, 52, 190),
(224, 65, 25, 43, 50),
(225, 65, 26, 45, 70),
(226, 65, 13, 23, 90);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(65, 223, 2, 30, 52, 190, '2026-10-05 11:37:00', 2),
(65, 224, 2, 25, 43, 50, '2026-10-05 11:37:00', 2),
(65, 225, 2, 26, 45, 70, '2026-10-05 11:37:00', 2),
(65, 226, 2, 13, 23, 90, '2026-10-05 11:37:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-05 11:37:00' WHERE id = 65;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (66, 'PN-2026-0018', 'IMPORT', 1, NULL, NULL, '2026-10-06', 'Nhập hàng từ NCC Tường An', 3, '2026-10-06 08:30:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(227, 66, 5, 8, 100);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(66, 227, 1, 5, 8, 100, '2026-10-06 09:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-06 09:00:00' WHERE id = 66;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (67, 'DC-2026-0001', 'ADJUSTMENT', 1, NULL, 66, '2026-10-07', 'Hủy phiếu PN-2026-0018: nhập nhầm 100 thay vì 10', 3, '2026-10-07 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(228, 67, 5, 8, -100);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(67, 228, 1, 5, 8, -100, '2026-10-07 08:30:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-07 08:30:00' WHERE id = 67;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (68, 'PN-2026-0019', 'IMPORT', 1, NULL, NULL, '2026-10-07', 'Nhập lại đúng số lượng thay cho phiếu PN-2026-0018', 3, '2026-10-07 08:40:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(229, 68, 5, 8, 10);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(68, 229, 1, 5, 8, 10, '2026-10-07 09:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-07 09:00:00' WHERE id = 68;

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
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(69, 230, 2, 10, 17, -3, '2026-10-11 09:00:00', 2),
(69, 231, 2, 12, 21, 2, '2026-10-11 09:00:00', 2),
(69, 232, 2, 15, 27, -1, '2026-10-11 09:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-11 09:00:00' WHERE id = 69;

UPDATE stock_counts SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-11 09:00:00', adjustment_voucher_id = 69 WHERE id = 1;

INSERT INTO vouchers (id, code, type, warehouse_id, to_warehouse_id, reference_voucher_id, voucher_date, reason, created_by, created_at)
VALUES (70, 'PN-2026-0020', 'IMPORT', 1, NULL, NULL, '2026-10-18', 'Nhập lô dùng cho phép thử 1 (tồn đúng 5)', 3, '2026-10-18 08:00:00');
INSERT INTO voucher_lines (id, voucher_id, item_id, batch_id, quantity) VALUES
(233, 70, 3, 54, 5);
INSERT INTO stock_movements (voucher_id, voucher_line_id, warehouse_id, item_id, batch_id, quantity, moved_at, created_by) VALUES
(70, 233, 1, 3, 54, 5, '2026-10-18 09:00:00', 2);
UPDATE vouchers SET status = 'APPROVED', approved_by = 2, approved_at = '2026-10-18 09:00:00' WHERE id = 70;

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

-- Tồn hiện tại = SUM(stock_movements) theo kho/lô
INSERT INTO stocks (warehouse_id, item_id, batch_id, quantity) VALUES
(1, 1, 2, 185),
(1, 3, 5, 118),
(1, 5, 8, 10),
(1, 5, 9, 152),
(1, 9, 15, 132),
(1, 9, 16, 48),
(1, 10, 18, 357),
(1, 11, 19, 200),
(1, 12, 21, 330),
(1, 13, 22, 59),
(1, 14, 25, 266),
(1, 15, 27, 243),
(1, 17, 29, 112),
(1, 18, 31, 158),
(1, 18, 32, 76),
(1, 23, 41, 82),
(1, 24, 42, 166),
(1, 25, 43, 261),
(1, 25, 44, 177),
(1, 26, 45, 64),
(1, 27, 47, 47),
(1, 27, 48, 103),
(1, 29, 50, 345),
(1, 29, 51, 203),
(1, 30, 52, 223),
(1, 3, 54, 5),
(2, 1, 1, 154),
(2, 5, 9, 219),
(2, 6, 10, 178),
(2, 6, 11, 288),
(2, 7, 13, 277),
(2, 8, 14, 20),
(2, 9, 15, 3),
(2, 9, 16, 16),
(2, 10, 17, 97),
(2, 11, 20, 4),
(2, 12, 21, 24),
(2, 13, 23, 90),
(2, 14, 24, 330),
(2, 14, 25, 83),
(2, 15, 27, 406),
(2, 17, 29, 170),
(2, 19, 33, 117),
(2, 20, 35, 127),
(2, 21, 37, 170),
(2, 22, 38, 70),
(2, 23, 40, 14),
(2, 23, 41, 69),
(2, 24, 42, 100),
(2, 25, 43, 243),
(2, 25, 44, 13),
(2, 26, 45, 70),
(2, 27, 47, 328),
(2, 27, 48, 178),
(2, 28, 49, 230),
(2, 29, 50, 107),
(2, 29, 51, 126),
(2, 30, 52, 301),
(2, 30, 53, 109),
(3, 1, 2, 160),
(3, 4, 7, 320),
(3, 5, 9, 19),
(3, 6, 10, 134),
(3, 6, 11, 72),
(3, 8, 14, 80),
(3, 9, 16, 63),
(3, 10, 18, 451),
(3, 11, 19, 115),
(3, 11, 20, 84),
(3, 12, 21, 282),
(3, 13, 22, 181),
(3, 17, 29, 166),
(3, 18, 32, 154),
(3, 20, 35, 69),
(3, 22, 39, 225),
(3, 23, 40, 247),
(3, 23, 41, 19),
(3, 24, 42, 170),
(3, 25, 43, 100),
(3, 25, 44, 48),
(3, 26, 45, 143),
(3, 26, 46, 160),
(3, 27, 48, 42),
(3, 29, 50, 65),
(3, 30, 52, 9);

-- Phiếu chờ duyệt cho phép thử: PX-2026-0032, PX-2026-0033 (phép thử 1), PC-2026-0018 (phép thử 2)
