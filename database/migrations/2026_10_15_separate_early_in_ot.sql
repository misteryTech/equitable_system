-- Early time-in and OT are always kept separate: OT no longer includes early_in_hrs,
-- so the early_counts_as_ot setting is removed.
--   mysql -h 127.0.0.1 -P 3308 -u root -p equitable_system < database/migrations/2026_10_15_separate_early_in_ot.sql
-- Afterwards, run Admin > Time Settings > Recompute so stored OT hours drop the early time-in.

ALTER TABLE time_settings DROP COLUMN IF EXISTS early_counts_as_ot;
