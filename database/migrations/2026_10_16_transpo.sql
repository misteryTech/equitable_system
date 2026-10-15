-- TRANSPORTATION EXPENSE module.
-- Engineers record the fares they pay to reach each ticket/merchant, leg by leg,
-- with a route fare master list for auto-filled fares.
--
-- Tables are prefixed "transpo_" because this system already has a `locations`
-- table (the depot list used at registration). Users are users.user_id.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_16_transpo.sql

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
