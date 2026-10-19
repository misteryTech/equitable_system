-- Email Generator: Service Request + ATW Request emails, editable recipient list,
-- merchant / location addresses. Run AFTER 2026_10_17_email_request.sql.
-- The system never sends email; it only builds the text to paste into Gmail.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_17_email_request_2_generator.sql

-- 1. Merchant address on terminals (ATW emails) and an address per location (ATW "Address:" line).
ALTER TABLE terminals
  ADD COLUMN IF NOT EXISTS merchant_address VARCHAR(255) NULL AFTER merchant_name;
ALTER TABLE locations
  ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL AFTER name;   -- e.g. C/O 3F BDO PIONEER, Pioneer Ave, General Santos City

-- 2. Recipients the generators list as checkboxes. group_tag: which generator uses them (ALL = both).
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

-- 3. Service Request history: the earlier email_requests table, renamed, plus the
--    terminal details and recipients used.
RENAME TABLE IF EXISTS email_requests TO service_requests;
CREATE TABLE IF NOT EXISTS service_requests (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  terminal_id INT UNSIGNED NULL,
  tn          VARCHAR(30)  NULL,
  actions     VARCHAR(500) NOT NULL,
  template    ENUM('short', 'formal') NOT NULL,
  subject     VARCHAR(255) NOT NULL,
  body        TEXT         NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email_requests_user_date (user_id, created_at),
  KEY idx_email_requests_date (created_at),
  CONSTRAINT fk_email_requests_user     FOREIGN KEY (user_id)     REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_email_requests_terminal FOREIGN KEY (terminal_id) REFERENCES terminals(terminal_id) ON DELETE SET NULL
);
ALTER TABLE service_requests
  ADD COLUMN IF NOT EXISTS model      VARCHAR(50)  NULL AFTER tn,
  ADD COLUMN IF NOT EXISTS sn         VARCHAR(50)  NULL AFTER model,
  ADD COLUMN IF NOT EXISTS tid        VARCHAR(20)  NULL AFTER sn,
  ADD COLUMN IF NOT EXISTS merchant   VARCHAR(150) NULL AFTER tid,
  ADD COLUMN IF NOT EXISTS recipients TEXT         NULL AFTER template;   -- "Name <email>, ..." as copied

-- 4. ATW Request history.
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

-- 5. Recipients now come from email_recipients; the single "mail to" setting is no longer used.
ALTER TABLE email_request_settings DROP COLUMN IF EXISTS mail_to;
