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
