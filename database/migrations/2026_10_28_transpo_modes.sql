-- Transportation: modes (Tryke, Habal-habal, PUJ, …) move from a fixed list into the
-- transpo_modes table, so a missing mode can be registered from the leg form and admins
-- can rename, hide or remove modes on Transport Modes.
-- Legs and route fares keep the mode code; the ENUM columns become VARCHAR + foreign key.
-- RUN ONCE: no IF EXISTS on the ALTERs, so it works on both MariaDB and MySQL 8.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_28_transpo_modes.sql

CREATE TABLE IF NOT EXISTS transpo_modes (
  code       VARCHAR(20)  NOT NULL PRIMARY KEY,   -- stored on legs and fares, e.g. TRYKE
  label      VARCHAR(50)  NOT NULL,               -- shown in dropdowns, e.g. Tryke
  is_active  TINYINT(1)   NOT NULL DEFAULT 1,     -- inactive: kept on old legs, not offered for new ones
  sort_order SMALLINT     NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_transpo_modes_user FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

INSERT IGNORE INTO transpo_modes (code, label, sort_order) VALUES
  ('TRYKE', 'Tryke',       10),
  ('HABAL', 'Habal-habal', 20),
  ('PUJ',   'PUJ (jeep)',  30),
  ('BUS',   'Bus',         40),
  ('VAN',   'Van',         50),
  ('CAR',   'Car',         60),
  ('OTHER', 'Other',       90);

-- Any other mode already used on a leg or fare (none expected) gets a row too.
INSERT IGNORE INTO transpo_modes (code, label, sort_order) SELECT DISTINCT mode, mode, 80 FROM transpo_route_rates;
INSERT IGNORE INTO transpo_modes (code, label, sort_order) SELECT DISTINCT mode, mode, 80 FROM transpo_trip_legs;

ALTER TABLE transpo_route_rates MODIFY mode VARCHAR(20) NOT NULL;
ALTER TABLE transpo_trip_legs   MODIFY mode VARCHAR(20) NOT NULL;
ALTER TABLE transpo_route_rates ADD CONSTRAINT fk_transpo_rates_mode FOREIGN KEY (mode) REFERENCES transpo_modes(code) ON UPDATE CASCADE;
ALTER TABLE transpo_trip_legs   ADD CONSTRAINT fk_transpo_legs_mode  FOREIGN KEY (mode) REFERENCES transpo_modes(code) ON UPDATE CASCADE;
