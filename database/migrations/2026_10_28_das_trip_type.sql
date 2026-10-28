-- DAS: mark each duty day as "Within the city" or "Out of town" (chosen on My DAS).
-- Existing days become "Within the city".
-- RUN ONCE: no IF NOT EXISTS, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_28_das_trip_type.sql

ALTER TABLE time_logs
  ADD COLUMN trip_type ENUM('within_city', 'out_of_town') NOT NULL DEFAULT 'within_city' AFTER filing_type;
