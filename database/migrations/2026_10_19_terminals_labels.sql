-- Terminals: remove Serial Number, Terminal ID, Merchant Name and Merchant Address,
-- and move Label from a fixed list (ENUM) to the editable terminal_labels table.
-- serial_inventory (QR scanning) is not touched.
-- RUN ONCE: it uses no IF EXISTS so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_19_terminals_labels.sql

-- 1. Back up the terminals table as it is now. Drop it once everything is confirmed working:
--      DROP TABLE terminals_backup_20261019;
CREATE TABLE terminals_backup_20261019 AS SELECT * FROM terminals;

-- 2. Labels admins can add, rename, deactivate, reorder and delete.
CREATE TABLE IF NOT EXISTS terminal_labels (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50)  NOT NULL UNIQUE,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO terminal_labels (name, sort_order) VALUES ('CBG-MP', 10), ('CBG-AB', 20), ('TBG', 30);
-- Any other label text already in use (none expected) gets its own row too.
INSERT IGNORE INTO terminal_labels (name, sort_order) SELECT DISTINCT label, 100 FROM terminals;

-- 3. terminals.label (ENUM text) -> terminals.label_id
ALTER TABLE terminals ADD COLUMN label_id INT UNSIGNED NULL AFTER current_version;
UPDATE terminals t JOIN terminal_labels l ON l.name = t.label SET t.label_id = l.id;
ALTER TABLE terminals
  MODIFY label_id INT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_terminals_label FOREIGN KEY (label_id) REFERENCES terminal_labels(id);
ALTER TABLE terminals DROP INDEX idx_terminals_label;
ALTER TABLE terminals DROP COLUMN label;

-- 4. Remove SN (with its unique index), TID, merchant name and merchant address.
ALTER TABLE terminals DROP INDEX uq_terminals_serial;
ALTER TABLE terminals DROP INDEX idx_terminals_tid;
ALTER TABLE terminals DROP INDEX idx_terminals_merchant;
ALTER TABLE terminals
  DROP COLUMN serial_number,
  DROP COLUMN tid,
  DROP COLUMN merchant_name,
  DROP COLUMN merchant_address;
