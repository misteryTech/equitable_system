-- Simplify the DAS module to a daily Time In / Time Out log.
-- Run once on a database that has the DAS module (2026_10_15_das_module.sql):
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_simplify_das_to_time_logs.sql
--
-- This system's users key is users.user_id INT UNSIGNED, so the foreign key
-- references users(user_id).

-- 1. New table: one row per engineer per day
CREATE TABLE IF NOT EXISTS time_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  work_date  DATE         NOT NULL,
  time_in    DATETIME     NULL,
  time_out   DATETIME     NULL,          -- may be the next day (worked past midnight)
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_time_logs_user_date (user_id, work_date),
  CONSTRAINT fk_time_logs_user FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- 2. Move existing times from das_entries (skips the blank pre-generated rows).
--    Skip this statement if das_entries does not exist.
INSERT IGNORE INTO time_logs (user_id, work_date, time_in, time_out)
SELECT user_id, work_date, time_in, time_out
FROM das_entries
WHERE time_in IS NOT NULL OR time_out IS NOT NULL;

-- 3. Drop the tables that are no longer used (children first because of foreign keys)
DROP TABLE IF EXISTS das_entry_tickets;
DROP TABLE IF EXISTS das_entries;
DROP TABLE IF EXISTS das_periods;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS das_settings;

-- 4. Drop the schedule columns from users
ALTER TABLE users
  DROP COLUMN IF EXISTS reg_time_in,
  DROP COLUMN IF EXISTS reg_time_out,
  DROP COLUMN IF EXISTS break_minutes,
  DROP COLUMN IF EXISTS rest_days;
