CREATE DATABASE IF NOT EXISTS equitable_system
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE equitable_system;

CREATE TABLE IF NOT EXISTS users (
    user_id       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('admin', 'field_engineer') NOT NULL DEFAULT 'field_engineer',
    status        ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved',  -- self-registrations start pending
    reviewed_by   INT UNSIGNED NULL,     -- admin who approved / rejected
    reviewed_at   DATETIME     NULL,
    review_note   VARCHAR(255) NULL,     -- reason for rejecting
    field_engineer_id VARCHAR(20)  NULL UNIQUE,  -- required for field engineers, optional for admins
    location      VARCHAR(100) NOT NULL DEFAULT 'M2C Depot',
    profile_picture VARCHAR(255) NULL,   -- file name in uploads/profile/ (optional)
    reg_time_in   TIME         NULL,     -- regular schedule, e.g. 09:00:00
    reg_time_out  TIME         NULL,     -- e.g. 18:00:00 (earlier than reg_time_in = overnight)
    first_name    VARCHAR(50)  NOT NULL,
    middle_name   VARCHAR(50)  NULL,
    last_name     VARCHAR(50)  NOT NULL,
    username      VARCHAR(30)  NOT NULL UNIQUE,
    email         VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,   -- store the HASH, never plain text
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                               ON UPDATE CURRENT_TIMESTAMP
);

-- Options for the Location dropdown on registration.
-- Add a depot here (or via phpMyAdmin) and it appears in the dropdown.
CREATE TABLE IF NOT EXISTS locations (
    location_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(100) NOT NULL UNIQUE,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO locations (name) VALUES ('M2C Depot');

-- Daily Time In / Time Out (one row per engineer per day).
CREATE TABLE IF NOT EXISTS time_logs (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  work_date  DATE         NOT NULL,
  filing_type ENUM('regular', 'holiday') NOT NULL DEFAULT 'regular',  -- chosen at TIME IN; label only
  sched_in   TIME         NULL,          -- copied from users.reg_time_in at TIME IN
  sched_out  TIME         NULL,
  time_in    DATETIME     NULL,
  time_out   DATETIME     NULL,          -- may be the next day (worked past midnight)
  total_hrs      DECIMAL(5,2) NOT NULL DEFAULT 0,  -- computed at TIME OUT: time in to time out
  reg_hrs        DECIMAL(5,2) NOT NULL DEFAULT 0,  -- inside the schedule, minus break
  early_in_hrs   DECIMAL(5,2) NOT NULL DEFAULT 0,  -- before scheduled time in
  ot_hrs         DECIMAL(5,2) NOT NULL DEFAULT 0,  -- after scheduled time out (+ early in, if set)
  late_mins      INT          NOT NULL DEFAULT 0,  -- arrived after scheduled time in
  undertime_mins INT          NOT NULL DEFAULT 0,  -- left before scheduled time out
  task           VARCHAR(500) NULL,                -- DAS: task / activities performed (typed by the user)
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_time_logs_user_date (user_id, work_date),
  CONSTRAINT fk_time_logs_user FOREIGN KEY (user_id) REFERENCES users(user_id)
);

-- Hour computation settings (single row, editable by admin).
CREATE TABLE IF NOT EXISTS time_settings (
  id                 TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  break_minutes      SMALLINT UNSIGNED NOT NULL DEFAULT 60,  -- deducted from reg_hrs only if worked > 5 hours
  ot_rounding_min    SMALLINT UNSIGNED NOT NULL DEFAULT 30,  -- round early-in and OT down to this many minutes
  ot_minimum_min     SMALLINT UNSIGNED NOT NULL DEFAULT 30,  -- early-in or OT below this becomes 0
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
INSERT IGNORE INTO time_settings (id) VALUES (1);

-- In-app notifications (the bell in the top bar).
CREATE TABLE IF NOT EXISTS notifications (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,              -- who receives it
  type       VARCHAR(30)  NOT NULL,              -- e.g. 'registration'
  ref_id     INT UNSIGNED NULL,                  -- related record, e.g. the registered user's id
  message    VARCHAR(255) NOT NULL,
  link       VARCHAR(255) NULL,                  -- page to open, relative to the site
  read_at    DATETIME     NULL,
  created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_unread (user_id, read_at),
  KEY idx_notifications_ref (type, ref_id),
  CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- Terminal registration.
CREATE TABLE IF NOT EXISTS terminals (
  terminal_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_name VARCHAR(100) NOT NULL UNIQUE,
  brand         VARCHAR(100) NOT NULL,
  label         ENUM('CBG-MP', 'CBG-AB', 'TBG') NOT NULL,
  bank_type     ENUM('bdo', 'non_bdo') NOT NULL,          -- BDO or Non-BDO
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_terminals_label (label),
  KEY idx_terminals_bank (bank_type),
  CONSTRAINT fk_terminals_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- Up to 3 photos per terminal.
CREATE TABLE IF NOT EXISTS terminal_photos (
  photo_id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_id INT UNSIGNED NOT NULL,
  slot        TINYINT UNSIGNED NOT NULL,          -- 1, 2 or 3 (display order)
  file        VARCHAR(255) NOT NULL,              -- file name in uploads/terminals/
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_terminal_slot (terminal_id, slot),
  CONSTRAINT fk_terminal_photos_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE CASCADE
);

-- Leave days picked by each user (any dates, once per date).
CREATE TABLE IF NOT EXISTS leave_days (
  leave_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  leave_date DATE NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_user_date (user_id, leave_date),
  CONSTRAINT fk_leave_days_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===== TRANSPORTATION EXPENSE module (see database/migrations/2026_10_16_transpo.sql) =====
-- 1. Places: bases, merchants and landmarks/dropoints. Compared on normalized_name.
CREATE TABLE IF NOT EXISTS transpo_locations (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name            VARCHAR(150) NOT NULL,                       -- original display name
  normalized_name VARCHAR(150) NOT NULL,
  type            ENUM('base', 'merchant', 'landmark') NOT NULL DEFAULT 'landmark',
  address         VARCHAR(255) NULL,
  times_used      INT UNSIGNED NOT NULL DEFAULT 0,
  last_used_at    DATETIME NULL,
  created_by      INT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transpo_locations_normalized (normalized_name),
  KEY idx_transpo_locations_used (times_used),
  CONSTRAINT fk_transpo_locations_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 2. Other spellings that should match a location.
CREATE TABLE IF NOT EXISTS transpo_location_aliases (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  location_id      INT UNSIGNED NOT NULL,
  alias            VARCHAR(150) NOT NULL,
  normalized_alias VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_transpo_alias_normalized (normalized_alias),
  CONSTRAINT fk_transpo_alias_location FOREIGN KEY (location_id) REFERENCES transpo_locations(id) ON DELETE CASCADE
);

-- 3. ROUTE FARE MASTER LIST.
CREATE TABLE IF NOT EXISTS transpo_route_rates (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  from_location_id INT UNSIGNED NOT NULL,
  to_location_id   INT UNSIGNED NOT NULL,
  mode             ENUM('TRYKE', 'HABAL', 'PUJ', 'OTHER') NOT NULL,
  fare             DECIMAL(8,2) NOT NULL,
  is_two_way       TINYINT(1) NOT NULL DEFAULT 1,            -- A->B and B->A use the same fare
  status           ENUM('approved', 'pending') NOT NULL DEFAULT 'approved',
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  times_used       INT UNSIGNED NOT NULL DEFAULT 0,
  created_by       INT UNSIGNED NULL,
  approved_by      INT UNSIGNED NULL,
  approved_at      DATETIME NULL,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transpo_route (from_location_id, to_location_id, mode),
  KEY idx_transpo_route_reverse (to_location_id, from_location_id, mode),
  KEY idx_transpo_route_status (status),
  CONSTRAINT fk_transpo_route_from FOREIGN KEY (from_location_id) REFERENCES transpo_locations(id),
  CONSTRAINT fk_transpo_route_to   FOREIGN KEY (to_location_id)   REFERENCES transpo_locations(id),
  CONSTRAINT fk_transpo_route_created_by  FOREIGN KEY (created_by)  REFERENCES users(user_id) ON DELETE SET NULL,
  CONSTRAINT fk_transpo_route_approved_by FOREIGN KEY (approved_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 4. Every fare change on the master list.
CREATE TABLE IF NOT EXISTS transpo_route_rate_history (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_rate_id INT UNSIGNED NOT NULL,
  old_fare      DECIMAL(8,2) NULL,                            -- NULL = route created
  new_fare      DECIMAL(8,2) NOT NULL,
  changed_by    INT UNSIGNED NULL,
  changed_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transpo_history_rate FOREIGN KEY (route_rate_id) REFERENCES transpo_route_rates(id) ON DELETE CASCADE,
  CONSTRAINT fk_transpo_history_user FOREIGN KEY (changed_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 5. "Report wrong fare".
CREATE TABLE IF NOT EXISTS transpo_route_rate_reports (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  route_rate_id  INT UNSIGNED NOT NULL,
  reported_by    INT UNSIGNED NULL,
  suggested_fare DECIMAL(8,2) NULL,
  note           VARCHAR(255) NOT NULL,
  status         ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_transpo_reports_status (status),
  CONSTRAINT fk_transpo_reports_rate FOREIGN KEY (route_rate_id) REFERENCES transpo_route_rates(id) ON DELETE CASCADE,
  CONSTRAINT fk_transpo_reports_user FOREIGN KEY (reported_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 6. One ticket/merchant visit by an engineer.
CREATE TABLE IF NOT EXISTS transpo_ticket_visits (
  id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  engineer_id          INT UNSIGNED NOT NULL,
  ticket_no            VARCHAR(30) NOT NULL,
  merchant_location_id INT UNSIGNED NOT NULL,
  visited_at           DATETIME NOT NULL,
  remarks              VARCHAR(255) NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_transpo_visit (engineer_id, ticket_no, visited_at),   -- blocks double filing
  KEY idx_transpo_visit_day (engineer_id, visited_at),
  CONSTRAINT fk_transpo_visit_engineer FOREIGN KEY (engineer_id) REFERENCES users(user_id),
  CONSTRAINT fk_transpo_visit_merchant FOREIGN KEY (merchant_location_id) REFERENCES transpo_locations(id)
);

-- 7. The legs travelled to reach a visit. The fare paid is stored here, so old
--    records never change when master fares change.
CREATE TABLE IF NOT EXISTS transpo_trip_legs (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  visit_id         INT UNSIGNED NOT NULL,
  seq              TINYINT UNSIGNED NOT NULL,
  from_location_id INT UNSIGNED NOT NULL,
  to_location_id   INT UNSIGNED NOT NULL,
  mode             ENUM('TRYKE', 'HABAL', 'PUJ', 'OTHER') NOT NULL,
  fare             DECIMAL(8,2) NOT NULL,
  route_rate_id    INT UNSIGNED NULL,
  fare_overridden  TINYINT(1) NOT NULL DEFAULT 0,
  KEY idx_transpo_legs_visit (visit_id, seq),
  CONSTRAINT fk_transpo_legs_visit FOREIGN KEY (visit_id) REFERENCES transpo_ticket_visits(id) ON DELETE CASCADE,
  CONSTRAINT fk_transpo_legs_from  FOREIGN KEY (from_location_id) REFERENCES transpo_locations(id),
  CONSTRAINT fk_transpo_legs_to    FOREIGN KEY (to_location_id)   REFERENCES transpo_locations(id),
  CONSTRAINT fk_transpo_legs_rate  FOREIGN KEY (route_rate_id) REFERENCES transpo_route_rates(id) ON DELETE SET NULL
);

-- Each engineer's daily starting point ("NEAREST HIGHWAY FROM BASE").
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS transpo_base_location_id INT UNSIGNED NULL AFTER reg_time_out;
ALTER TABLE users
  ADD FOREIGN KEY IF NOT EXISTS fk_users_transpo_base (transpo_base_location_id) REFERENCES transpo_locations(id) ON DELETE SET NULL;

-- Shared default starting point, used until an engineer picks their own base.
INSERT IGNORE INTO transpo_locations (name, normalized_name, type)
VALUES ('NEAREST HIGHWAY FROM BASE', 'NEAREST HIGHWAY FROM BASE', 'base');
