-- One leave day per week (Monday-Sunday) per user, set from My DAS.
-- On the DAS a leave day shows as "Leave", or "Duty with pay" if the user timed in that day.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_leave_days.sql

CREATE TABLE IF NOT EXISTS leave_days (
  leave_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  leave_date DATE NOT NULL,
  week_start DATE NOT NULL,                  -- Monday of leave_date's week (enforces 1 per week)
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_user_week (user_id, week_start),
  UNIQUE KEY uq_leave_user_date (user_id, leave_date),
  CONSTRAINT fk_leave_days_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);
