-- DAS (Data Activity Sheet, ERM-DASFM-02) filing module.
-- Run once on an existing database:
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_das_module.sql
--
-- Adapted to this system's users table: the key is users.user_id INT UNSIGNED,
-- so every user foreign key below is INT UNSIGNED and references users(user_id).

-- 1. Regular schedule per engineer
ALTER TABLE users
  ADD COLUMN reg_time_in   TIME        NOT NULL DEFAULT '09:00:00' AFTER location,
  ADD COLUMN reg_time_out  TIME        NOT NULL DEFAULT '18:00:00' AFTER reg_time_in,
  ADD COLUMN break_minutes SMALLINT UNSIGNED NOT NULL DEFAULT 60 AFTER reg_time_out,
  ADD COLUMN rest_days     VARCHAR(20) NOT NULL DEFAULT 'Sat' AFTER break_minutes;  -- e.g. 'Sat' or 'Sat,Sun'

-- 2. Cutoff periods (one DAS form per engineer per cutoff: 25th-10th, 11th-24th)
CREATE TABLE IF NOT EXISTS das_periods (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  period_start DATE NOT NULL,
  period_end   DATE NOT NULL,
  status       ENUM('draft','submitted','approved','returned') NOT NULL DEFAULT 'draft',
  submitted_at DATETIME NULL,
  approved_by  INT UNSIGNED NULL,
  approved_at  DATETIME NULL,
  return_note  VARCHAR(255) NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_period (user_id, period_start),
  KEY idx_status (status),
  CONSTRAINT fk_das_periods_user     FOREIGN KEY (user_id)     REFERENCES users(user_id),
  CONSTRAINT fk_das_periods_approver FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 3. Daily entries (one row = one line on the DAS form)
CREATE TABLE IF NOT EXISTS das_entries (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  period_id      INT UNSIGNED NOT NULL,
  user_id        INT UNSIGNED NOT NULL,
  work_date      DATE NOT NULL,
  sched_in       TIME NOT NULL,            -- copied from users at time of filing
  sched_out      TIME NOT NULL,
  change_in      TIME NULL,                -- "Change To" column
  change_out     TIME NULL,
  time_in        DATETIME NULL,            -- DATETIME so overnight work is handled
  time_out       DATETIME NULL,
  reg_hrs        DECIMAL(4,2) NOT NULL DEFAULT 0,
  ot_hrs         DECIMAL(4,2) NOT NULL DEFAULT 0,
  night_hrs      DECIMAL(4,2) NOT NULL DEFAULT 0,
  weekend_hrs    DECIMAL(4,2) NOT NULL DEFAULT 0,
  holiday_hrs    DECIMAL(4,2) NOT NULL DEFAULT 0,
  activities     VARCHAR(255) NULL,        -- non-ticket tasks, e.g. "SCANNING PERIPHERALS"
  is_out_of_town TINYINT(1)   NOT NULL DEFAULT 0,
  ot_coordinated_with VARCHAR(100) NULL,  -- e.g. "Sir JM", "Maam"
  remarks        VARCHAR(255) NULL,
  created_at     DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_date (user_id, work_date),
  CONSTRAINT fk_das_entries_period FOREIGN KEY (period_id) REFERENCES das_periods(id) ON DELETE CASCADE,
  CONSTRAINT fk_das_entries_user   FOREIGN KEY (user_id)   REFERENCES users(user_id)
);

-- 4. Tickets worked per day ("Activities/Task Performed")
CREATE TABLE IF NOT EXISTS das_entry_tickets (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  entry_id    INT UNSIGNED NOT NULL,
  ticket_no   VARCHAR(30) NOT NULL,        -- e.g. 260921-SSERV-0023
  ticket_type VARCHAR(10) NOT NULL,        -- SSERV, BSERV, BPUL, INST, SERV, BPRO
  UNIQUE KEY uq_entry_ticket (entry_id, ticket_no),
  CONSTRAINT fk_das_tickets_entry FOREIGN KEY (entry_id) REFERENCES das_entries(id) ON DELETE CASCADE
);

-- 5. Holidays (for the Holiday column)
CREATE TABLE IF NOT EXISTS holidays (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  holiday_date DATE NOT NULL UNIQUE,
  name         VARCHAR(100) NOT NULL,
  type         ENUM('regular','special') NOT NULL
);

-- 6. Computation rules (single row, editable by admin)
CREATE TABLE IF NOT EXISTS das_settings (
  id               TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  count_early_ot   TINYINT(1) NOT NULL DEFAULT 1,  -- time before reg_time_in counts as OT
  ot_rounding_min  SMALLINT UNSIGNED NOT NULL DEFAULT 30, -- round OT down to this many minutes
  ot_minimum_min   SMALLINT UNSIGNED NOT NULL DEFAULT 30, -- OT below this is ignored
  night_start      TIME       NOT NULL DEFAULT '22:00:00',
  night_end        TIME       NOT NULL DEFAULT '06:00:00',
  form_code        VARCHAR(30) NOT NULL DEFAULT 'ERM-DASFM-02',
  revision_code    VARCHAR(10) NOT NULL DEFAULT '01',
  effectivity_date DATE        NOT NULL DEFAULT '2020-02-14'
);
INSERT IGNORE INTO das_settings (id) VALUES (1);
