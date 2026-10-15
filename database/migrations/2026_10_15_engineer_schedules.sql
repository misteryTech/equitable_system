-- Regular schedule per engineer, copied into each day's time log at TIME IN
-- so later schedule changes don't alter past records.
-- Safe to run more than once (MariaDB IF NOT EXISTS).
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_engineer_schedules.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS reg_time_in  TIME NULL AFTER location,      -- e.g. 09:00:00
  ADD COLUMN IF NOT EXISTS reg_time_out TIME NULL AFTER reg_time_in;   -- e.g. 18:00:00 (may be earlier than reg_time_in for overnight)

ALTER TABLE time_logs
  ADD COLUMN IF NOT EXISTS sched_in  TIME NULL AFTER work_date,        -- copied from users.reg_time_in at TIME IN
  ADD COLUMN IF NOT EXISTS sched_out TIME NULL AFTER sched_in;
