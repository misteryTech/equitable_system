-- Password reset by request: a user asks on the login page ("Forgot password?"),
-- an admin approves the request and sets a new password to give to the user,
-- who must change it at the next login. Nothing is emailed.
-- RUN ONCE: no IF NOT EXISTS on the ALTER, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_28_password_resets.sql

CREATE TABLE IF NOT EXISTS password_reset_requests (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      INT UNSIGNED NOT NULL,
  note         VARCHAR(255) NULL,                        -- message from the user, e.g. how to reach them
  status       ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  requested_ip VARCHAR(45)  NULL,
  handled_by   INT UNSIGNED NULL,                        -- admin who approved / rejected
  handled_at   DATETIME     NULL,
  admin_note   VARCHAR(255) NULL,                        -- reason for rejecting
  KEY idx_password_reset_status (status, requested_at),
  KEY idx_password_reset_user (user_id),
  CONSTRAINT fk_password_reset_user  FOREIGN KEY (user_id)    REFERENCES users(user_id) ON DELETE CASCADE,
  CONSTRAINT fk_password_reset_admin FOREIGN KEY (handled_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- 1 = the password was set by an admin; the user must choose their own at the next login.
ALTER TABLE users
  ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER password_hash;
