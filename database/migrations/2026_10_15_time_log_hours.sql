-- Hours worked, early time-in, overtime, late and undertime per time log,
-- computed at TIME OUT from that day's saved schedule, plus admin settings.
-- Safe to run more than once (MariaDB IF NOT EXISTS).
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_time_log_hours.sql
-- Afterwards, use Admin > Time Settings > Recompute to fill in rows that already have a time out.

ALTER TABLE time_logs
  ADD COLUMN IF NOT EXISTS total_hrs      DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER time_out,      -- time in to time out
  ADD COLUMN IF NOT EXISTS reg_hrs        DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER total_hrs,     -- inside the schedule, minus break
  ADD COLUMN IF NOT EXISTS early_in_hrs   DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER reg_hrs,       -- before scheduled time in
  ADD COLUMN IF NOT EXISTS ot_hrs         DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER early_in_hrs,  -- after scheduled time out (+ early in, if set)
  ADD COLUMN IF NOT EXISTS late_mins      INT          NOT NULL DEFAULT 0 AFTER ot_hrs,        -- arrived after scheduled time in
  ADD COLUMN IF NOT EXISTS undertime_mins INT          NOT NULL DEFAULT 0 AFTER late_mins;     -- left before scheduled time out

-- Computation settings (single row, editable by admin)
CREATE TABLE IF NOT EXISTS time_settings (
  id                 TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  break_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 60,  -- deducted from reg_hrs only if worked > 5 hours
  ot_rounding_min    SMALLINT UNSIGNED NOT NULL DEFAULT 30,  -- round early-in and OT down to this many minutes
  ot_minimum_min     SMALLINT UNSIGNED NOT NULL DEFAULT 30,  -- early-in or OT below this becomes 0
  early_counts_as_ot TINYINT(1)        NOT NULL DEFAULT 1,   -- 1 = add early_in_hrs into ot_hrs
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO time_settings (id) VALUES (1);
