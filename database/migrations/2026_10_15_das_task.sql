-- DAS: the task / activities performed, typed by the user for each day on the My DAS page.
-- Everything else on the DAS (date, schedule, regular hours, early in, OT) comes from the time log.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_das_task.sql

ALTER TABLE time_logs
  ADD COLUMN IF NOT EXISTS task VARCHAR(500) NULL AFTER undertime_mins;
