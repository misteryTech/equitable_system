-- Terminals: free-text Remarks, and a list of FAQs (question + answer) per terminal
-- that admins can add, edit, reorder and remove on the Terminals page.
-- RUN ONCE: no IF NOT EXISTS, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_30_terminal_remarks_faq.sql

ALTER TABLE terminals
  ADD COLUMN remarks TEXT NULL AFTER bank_type;

CREATE TABLE terminal_faqs (
  faq_id      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_id INT UNSIGNED NOT NULL,
  question    VARCHAR(255) NOT NULL,
  answer      TEXT NOT NULL,
  sort_order  SMALLINT NOT NULL DEFAULT 0,       -- display order (order in the form)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_terminal_faqs_terminal (terminal_id, sort_order),
  CONSTRAINT fk_terminal_faqs_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE CASCADE
);
