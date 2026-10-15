-- Schedules include a break: e.g. 9:00 AM - 6:00 PM = 8 hours duty + 1 hour break.
-- The break is deducted from regular hours (only if worked > 5 hours), so 9-6 gives 8 regular hours.
-- Undoes 2026_10_15_remove_break.sql.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_restore_break.sql
-- Afterwards, run Admin > Time Settings > Recompute.

ALTER TABLE time_settings
  ADD COLUMN IF NOT EXISTS break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER id;  -- deducted from reg_hrs only if worked > 5 hours
