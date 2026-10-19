-- Serial Inventory becomes a register: each serial once, with the date it was first registered.
-- Removes duplicate serials (keeping the earliest date), renames scanned_date to
-- registered_date and makes serial UNIQUE. Run AFTER 2026_10_19_serial_inventory.sql.
-- RUN ONCE: no IF EXISTS, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_19_serial_inventory_2_unique.sql

-- 1. Back up the rows as they are now. Drop it once everything is confirmed working:
--      DROP TABLE serial_inventory_backup_20261019;
CREATE TABLE serial_inventory_backup_20261019 AS SELECT * FROM serial_inventory;

-- 2. Keep one row per serial: the earliest date (and the first id on that date).
DELETE s FROM serial_inventory s
JOIN serial_inventory k
  ON k.serial = s.serial
 AND (k.scanned_date < s.scanned_date OR (k.scanned_date = s.scanned_date AND k.id < s.id));

-- 3. scanned_date -> registered_date, and one row per serial from now on.
ALTER TABLE serial_inventory CHANGE scanned_date registered_date DATE NOT NULL;
ALTER TABLE serial_inventory DROP INDEX idx_serial_inventory_serial;
ALTER TABLE serial_inventory ADD UNIQUE KEY uq_serial_inventory_serial (serial);
