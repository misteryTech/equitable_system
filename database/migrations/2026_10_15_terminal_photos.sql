-- Up to 3 photos per terminal (replaces the single terminals.image column).
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_terminal_photos.sql

CREATE TABLE IF NOT EXISTS terminal_photos (
  photo_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_id INT UNSIGNED NOT NULL,
  slot        TINYINT UNSIGNED NOT NULL,          -- 1, 2 or 3 (display order)
  file        VARCHAR(255) NOT NULL,              -- file name in uploads/terminals/
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_terminal_slot (terminal_id, slot),
  CONSTRAINT fk_terminal_photos_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE CASCADE
);

-- Move any existing single images into slot 1, then drop the old column.
INSERT IGNORE INTO terminal_photos (terminal_id, slot, file)
SELECT terminal_id, 1, image FROM terminals WHERE image IS NOT NULL;

ALTER TABLE terminals DROP COLUMN IF EXISTS image;
