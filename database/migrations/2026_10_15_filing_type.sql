-- Filing type chosen by the engineer at TIME IN: Regular filling or Holiday filling.
-- It is a label only; hours are computed the same way for both.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_filing_type.sql

ALTER TABLE time_logs
  ADD COLUMN IF NOT EXISTS filing_type ENUM('regular', 'holiday') NOT NULL DEFAULT 'regular' AFTER work_date;
