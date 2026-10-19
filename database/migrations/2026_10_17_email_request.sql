-- Email Request: terminal details for support emails, engineer mobile / area,
-- saved email requests (history) and admin-editable request types and template texts.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_17_email_request.sql

-- 1. Terminal details used in the email (all optional so existing terminals stay valid).
ALTER TABLE terminals
  ADD COLUMN IF NOT EXISTS model           VARCHAR(50)  NULL AFTER brand,          -- e.g. DX8000, S1F2
  ADD COLUMN IF NOT EXISTS serial_number   VARCHAR(50)  NULL AFTER model,          -- SN
  ADD COLUMN IF NOT EXISTS tid             VARCHAR(20)  NULL AFTER serial_number,  -- Terminal ID
  ADD COLUMN IF NOT EXISTS merchant_name   VARCHAR(150) NULL AFTER tid,
  ADD COLUMN IF NOT EXISTS current_version VARCHAR(30)  NULL AFTER merchant_name;
ALTER TABLE terminals
  ADD UNIQUE KEY IF NOT EXISTS uq_terminals_serial (serial_number),
  ADD KEY IF NOT EXISTS idx_terminals_tid (tid),
  ADD KEY IF NOT EXISTS idx_terminals_merchant (merchant_name);

-- 2. Engineer contact details for the "F.E <AREA>" sign-off.
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS mobile_number VARCHAR(20)  NULL AFTER location,
  ADD COLUMN IF NOT EXISTS area          VARCHAR(100) NULL AFTER mobile_number;   -- e.g. COTABATO CITY

-- 3. Request types shown as checkboxes (admin-editable on Email Templates).
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

-- 4. Greeting / closing texts of the two templates (single row, admin-editable).
CREATE TABLE IF NOT EXISTS email_request_settings (
  id              TINYINT UNSIGNED PRIMARY KEY,
  short_greeting  VARCHAR(100) NOT NULL DEFAULT 'Hi system, for assistance',
  short_closing   VARCHAR(100) NOT NULL DEFAULT 'Thanks and regards',
  formal_greeting VARCHAR(100) NOT NULL DEFAULT 'Hi Maam/Sir',
  formal_intro    VARCHAR(100) NOT NULL DEFAULT 'For assistance',
  formal_closing  VARCHAR(100) NOT NULL DEFAULT 'Thank you',
  mail_to         VARCHAR(255) NOT NULL DEFAULT '',                      -- support address for "Open in Mail app"
  updated_by      INT UNSIGNED NULL,
  updated_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_settings_user FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT IGNORE INTO email_request_settings (id) VALUES (1);

-- 5. Emails built by users (My Email Requests / admin history).
CREATE TABLE IF NOT EXISTS email_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NULL,
  tn          VARCHAR(30)  NULL,                    -- ticket number
  actions     VARCHAR(500) NOT NULL,                -- requested actions, combined
  template    ENUM('short', 'formal') NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body        TEXT         NOT NULL,                -- the email exactly as copied
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_requests_user_date (user_id, created_at),
  KEY idx_email_requests_date (created_at),
  CONSTRAINT fk_email_requests_user     FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_email_requests_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE SET NULL
);
