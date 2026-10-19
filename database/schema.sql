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
    mobile_number VARCHAR(20)  NULL,     -- shown in the Formal email sign-off
    area          VARCHAR(100) NULL,     -- "F.E <AREA>" in the email sign-off, e.g. COTABATO CITY
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
    address       VARCHAR(255) NULL,     -- "Address:" line of ATW emails
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

-- Terminal labels (admin-editable on Terminal Labels).
CREATE TABLE IF NOT EXISTS terminal_labels (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50)  NOT NULL UNIQUE,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,        -- inactive labels stay on old terminals but can't be picked
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO terminal_labels (name, sort_order) VALUES ('CBG-MP', 10), ('CBG-AB', 20), ('TBG', 30);

-- Terminal registration.
CREATE TABLE IF NOT EXISTS terminals (
  terminal_id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  terminal_name VARCHAR(100) NOT NULL UNIQUE,
  brand         VARCHAR(100) NOT NULL,
  model           VARCHAR(50)  NULL,                    -- e.g. DX8000, S1F2
  current_version VARCHAR(30)  NULL,
  label_id      INT UNSIGNED NOT NULL,
  bank_type     ENUM('bdo', 'non_bdo') NOT NULL,          -- BDO or Non-BDO
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_terminals_bank (bank_type),
  CONSTRAINT fk_terminals_label FOREIGN KEY (label_id) REFERENCES terminal_labels(id),
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

-- ===== EMAIL GENERATOR (see database/migrations/2026_10_17_email_request*.sql) =====
-- The system never sends email; it only builds the text to paste into Gmail.
-- 1. Request types shown as checkboxes (admin-editable on Email Templates).
--    phrase: wording in the Formal sentence; {model} = terminal model, {text} = typed text.
CREATE TABLE IF NOT EXISTS email_request_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(60)  NOT NULL UNIQUE,
  phrase     VARCHAR(150) NOT NULL,
  text_mode  ENUM('none', 'append', 'replace') NOT NULL DEFAULT 'none',   -- typed text: none / after the label / instead of it
  text_hint  VARCHAR(60)  NULL,                                          -- placeholder for the text box
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO email_request_types (label, phrase, text_mode, text_hint, sort_order) VALUES
  ('Transfer',                'transfer {model}',                       'none',    NULL,               10),
  ('Update to latest version', 'update {model} to the latest version',   'none',    NULL,               20),
  ('Update to version',       'update {model} to version {text}',       'append',  'e.g. 16.21',       30),
  ('Change SIM to Globe IOT', 'change the SIM to Globe IOT',            'none',    NULL,               40),
  ('Change SIM to Smart',     'change the SIM to Smart',                'none',    NULL,               50),
  ('Replacement',             'replace {model}',                        'none',    NULL,               60),
  ('Pull-out',                'pull out {model}',                       'none',    NULL,               70),
  ('Reprogram/Reset',         'reprogram/reset {model}',                'none',    NULL,               80),
  ('Other',                   '{text}',                                 'replace', 'Describe the request', 90);

-- 2. Greeting / closing texts of the two templates (single row, admin-editable).
CREATE TABLE IF NOT EXISTS email_request_settings (
  id              TINYINT UNSIGNED PRIMARY KEY,
  short_greeting  VARCHAR(100) NOT NULL DEFAULT 'Hi system, for assistance',
  short_closing   VARCHAR(100) NOT NULL DEFAULT 'Thanks and regards',
  formal_greeting VARCHAR(100) NOT NULL DEFAULT 'Hi Maam/Sir',
  formal_intro    VARCHAR(100) NOT NULL DEFAULT 'For assistance',
  formal_closing  VARCHAR(100) NOT NULL DEFAULT 'Thank you',
  updated_by      INT UNSIGNED NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_settings_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT IGNORE INTO email_request_settings (id) VALUES (1);

-- 3. Recipients listed as checkboxes on the generators. group_tag: which generator uses them (ALL = both).
CREATE TABLE IF NOT EXISTS email_recipients (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  display_name VARCHAR(100) NOT NULL DEFAULT '',
  email        VARCHAR(255) NOT NULL,
  group_tag    ENUM('ATW', 'SERVICE', 'ALL') NOT NULL DEFAULT 'ALL',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,              -- off for addresses that bounce
  sort_order   SMALLINT     NOT NULL DEFAULT 0,
  notes        VARCHAR(255) NULL,                            -- e.g. "bounced 10/15/2026"
  updated_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_recipients_group_email (group_tag, email),
  KEY idx_email_recipients_order (group_tag, sort_order)
);

INSERT IGNORE INTO email_recipients (display_name, email, group_tag, sort_order) VALUES
  ('ECS POS HelpDesk',                 'poshelpdesk@equicom.com',                  'ATW', 10),
  ('Merchant Services POS Helpdesk',   'merchant-services-poshelpdesk@bdo.com.ph', 'ATW', 20),
  ('ECS POS HelpDesk',                 'phrobines@equicom.com',                    'ATW', 30),
  ('ECS POS HelpDesk',                 'mjmalacad@equicom.com',                    'ATW', 40),
  ('ECS POS Helpdesk Group',           'ecsposhelpdeskgroup@equicom.com',          'ATW', 50),
  ('ECS POS HelpDesk',                 'amendoza@equicom.com',                     'ATW', 60),
  ('BDO',                              'cashagad-helpdesk@bdo.com.ph',             'ATW', 70),
  ('ECS POS HelpDesk',                 'lbasangan@equicom.com',                    'ATW', 80),
  ('ECS POS HelpDesk',                 'avillar@equicom.com',                      'ATW', 90),
  ('ECS POS HelpDesk',                 'ECSPOSHelpdesk@equicom.com',               'ATW', 100);

-- 4. Service Request emails (My Requests / admin history).
CREATE TABLE IF NOT EXISTS service_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NULL,
  tn          VARCHAR(30)  NULL,                    -- ticket number
  model       VARCHAR(50)  NULL,
  sn          VARCHAR(50)  NULL,
  tid         VARCHAR(20)  NULL,
  merchant    VARCHAR(150) NULL,
  actions     VARCHAR(500) NOT NULL,                -- requested actions, combined
  template    ENUM('short', 'formal') NOT NULL,
  recipients  TEXT         NULL,                    -- "Name <email>, ..." as copied
  subject     VARCHAR(255) NOT NULL,
  body        TEXT         NOT NULL,                -- the email exactly as copied
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_requests_user_date (user_id, created_at),
  KEY idx_email_requests_date (created_at),
  CONSTRAINT fk_email_requests_user     FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_email_requests_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE SET NULL
);

-- 5. ATW Request emails.
CREATE TABLE IF NOT EXISTS atw_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  ticket_no   VARCHAR(30)  NOT NULL,                  -- first ticket (for search and the subject)
  merchant    VARCHAR(150) NOT NULL,                  -- first merchant
  merchants   TEXT         NOT NULL,                  -- JSON list of {ticket, name, address}
  date_from   DATE         NOT NULL,
  date_to     DATE         NOT NULL,
  fe_names    VARCHAR(500) NOT NULL,                  -- "Name , Name , Name"
  address     VARCHAR(255) NULL,
  recipients  TEXT         NULL,
  subject     VARCHAR(255) NOT NULL,
  body        TEXT         NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_atw_requests_user_date (user_id, created_at),
  KEY idx_atw_requests_date (created_at),
  CONSTRAINT fk_atw_requests_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- ===== SERIAL INVENTORY (see database/migrations/2026_10_19_serial_inventory*.sql) =====
-- Register of serial numbers from terminal / device labels (QR or barcode): each serial
-- once, with the date it was first registered. No user, location or terminal link.

CREATE TABLE IF NOT EXISTS serial_inventory (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  serial          VARCHAR(100) NOT NULL,
  registered_date DATE         NOT NULL,
  UNIQUE KEY uq_serial_inventory_serial (serial),
  KEY idx_serial_inventory_date (registered_date)
);
