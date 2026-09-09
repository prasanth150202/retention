-- =====================================================================
-- import_log: separate "deliberately not stored" from "malformed"
--
-- Events we do not subscribe to (input_changed and friends, which carry raw
-- element.value) were being counted as malformed. They are not: skipping
-- them is the intended behaviour and the reason those events never reach
-- the database.
--
-- The distinction matters because health_check alerts on malformed rows. A
-- misconfigured pixel sending unsubscribed events would otherwise raise a
-- data-corruption alarm every few minutes, and an alarm that cries wolf is
-- worse than no alarm.
--
-- IF NOT EXISTS is MariaDB syntax. This project targets MariaDB (Hostinger
-- 11.8 in production, 10.11 locally); it keeps the migration idempotent, as
-- every migration here must be, because the runner re-applies a file whose
-- checksum has changed.
-- =====================================================================

ALTER TABLE import_log
    ADD COLUMN IF NOT EXISTS skipped INT UNSIGNED NOT NULL DEFAULT 0 AFTER malformed;
