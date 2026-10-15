-- In-app notifications (the bell in the top bar).
-- First use: every admin is notified when an engineer registers and is waiting for approval.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_notifications.sql

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
