-- Profile picture chosen at registration (optional). Stores the file name only;
-- the image lives in uploads/profile/.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_profile_picture.sql

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS profile_picture VARCHAR(255) NULL AFTER location;
