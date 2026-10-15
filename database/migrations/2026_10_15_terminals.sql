-- Terminal registration: name, image, brand, label (CBG-MP / CBG-AB / TBG) and BDO / Non-BDO.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_terminals.sql

CREATE TABLE IF NOT EXISTS terminals (
  terminal_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_name VARCHAR(100) NOT NULL UNIQUE,
  brand         VARCHAR(100) NOT NULL,
  label         ENUM('CBG-MP', 'CBG-AB', 'TBG') NOT NULL,
  bank_type     ENUM('bdo', 'non_bdo') NOT NULL,          -- BDO or Non-BDO
  image         VARCHAR(255) NULL,                        -- file name in uploads/terminals/
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_terminals_label (label),
  KEY idx_terminals_bank (bank_type),
  CONSTRAINT fk_terminals_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);
