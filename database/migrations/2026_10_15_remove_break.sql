-- No break time: regular hours are the time worked inside the schedule.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_remove_break.sql
-- Afterwards, run Admin > Time Settings > Recompute so stored regular hours no longer deduct a break.

ALTER TABLE time_settings DROP COLUMN IF EXISTS break_minutes;
