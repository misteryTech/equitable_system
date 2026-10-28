-- Serial Inventory: each registered serial can carry a label (CBG-MP, CBG-AB, TBG, …)
-- from the same terminal_labels list used by Terminals. Serials registered before
-- this keep no label (an admin can set one on Records).
-- RUN ONCE: no IF NOT EXISTS, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_28_serial_inventory_label.sql

ALTER TABLE serial_inventory
  ADD COLUMN label_id INT UNSIGNED NULL AFTER serial,
  ADD KEY idx_serial_inventory_label (label_id),
  ADD CONSTRAINT fk_serial_inventory_label FOREIGN KEY (label_id) REFERENCES terminal_labels(id);
