-- Leave days: users can now mark any dates as leave (no 1-per-week limit).
-- Each date can still only be marked once per user.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_leave_days_no_limit.sql

ALTER TABLE leave_days
  DROP INDEX IF EXISTS uq_leave_user_week,
  DROP COLUMN IF EXISTS week_start;
