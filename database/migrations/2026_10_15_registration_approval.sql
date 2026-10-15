-- Admin approval for self-registered engineers.
-- Existing accounts become 'approved' (the column default), so nobody is locked out.
-- New self-registrations are saved as 'pending' and can't log in until an admin approves them.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_registration_approval.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS status      ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER role,
  ADD COLUMN IF NOT EXISTS reviewed_by INT UNSIGNED NULL AFTER status,      -- admin who approved / rejected
  ADD COLUMN IF NOT EXISTS reviewed_at DATETIME     NULL AFTER reviewed_by,
  ADD COLUMN IF NOT EXISTS review_note VARCHAR(255) NULL AFTER reviewed_at; -- reason for rejecting
