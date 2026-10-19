-- Serial Inventory: serial numbers scanned from terminal / device labels (QR or barcode).
-- Only the serial and the date scanned are kept — no user, location or terminal link.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_19_serial_inventory.sql

CREATE TABLE IF NOT EXISTS serial_inventory (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  serial       VARCHAR(100) NOT NULL,
  scanned_date DATE         NOT NULL,
  KEY idx_serial_inventory_serial (serial),
  KEY idx_serial_inventory_date (scanned_date)
);
