-- =============================================================================
-- Production equivalent of migration 2026_10_19_000001_convert_version_dates_to_utc
--
-- Converts version_dates.start_at/end_at from Eastern wall-clock to UTC and
-- changes both columns from TIMESTAMP to DATETIME (drops the stray
-- ON UPDATE current_timestamp() on start_at). Records the migration in
-- `migrations` so `php artisan migrate` never runs it a second time.
--
-- Release order:
--   1. vapor down production
--   2. Snapshot the Aurora cluster
--   3. Run this script top to bottom; review every result set
--   4. Deploy the version-dates-utc code
--   5. vapor up production
--
-- Run it from a client connected with the server's default session time zone
-- (do NOT set time_zone) — that's what the app uses, and step 1 below proves it.
-- The conversion only happens when every check in step 2 passes; otherwise the
-- UPDATE and INSERT match nothing and the script is a no-op past the ALTER.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Session check + canary. Expect version 88's candidate start_at to read
--    exactly 2026-10-05 00:00:00. If it doesn't, STOP: this session's time
--    zone differs from what the app sees.
-- -----------------------------------------------------------------------------
SELECT @@session.time_zone AS session_tz, @@global.time_zone AS global_tz, @@system_time_zone AS system_tz;

SELECT id, version_id, date_type, start_at, end_at
FROM version_dates
WHERE version_id = 88
ORDER BY start_at;


-- -----------------------------------------------------------------------------
-- 2. TIMESTAMP -> DATETIME. Idempotent; implicit commit. Values carry over as
--    the session sees them, so the canary must still read the same afterwards.
-- -----------------------------------------------------------------------------
ALTER TABLE version_dates
    MODIFY start_at DATETIME NOT NULL,
    MODIFY end_at DATETIME NULL;

SHOW CREATE TABLE version_dates;


-- -----------------------------------------------------------------------------
-- 3. Pre-flight checks. All must pass for @go = 1.
-- -----------------------------------------------------------------------------
-- Named time zones loaded? (CONVERT_TZ returns NULL when they aren't.)
SET @tz_ok := CONVERT_TZ('2026-07-01 12:00:00', 'America/New_York', 'UTC') = '2026-07-01 16:00:00'
          AND CONVERT_TZ('2026-01-01 12:00:00', 'America/New_York', 'UTC') = '2026-01-01 17:00:00';

-- Not already converted?
SET @already_ran := (SELECT COUNT(*) FROM migrations WHERE migration = '2026_10_19_000001_convert_version_dates_to_utc');

-- Canary still intact after the ALTER?
SET @canary_ok := (SELECT COUNT(*) FROM version_dates
                   WHERE version_id = 88 AND date_type = 'candidate' AND start_at = '2026-10-05 00:00:00') = 1;

-- DST edge cases: a non-existent wall-clock time doesn't survive a round trip;
-- an ambiguous one maps back to itself from an hour either side.
SELECT id, version_id, date_type, 'start_at' AS col, start_at AS value
FROM version_dates
WHERE CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC'), 'UTC', 'America/New_York') <> start_at
   OR CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC') + INTERVAL 1 HOUR, 'UTC', 'America/New_York') = start_at
   OR CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC') - INTERVAL 1 HOUR, 'UTC', 'America/New_York') = start_at
UNION ALL
SELECT id, version_id, date_type, 'end_at', end_at
FROM version_dates
WHERE end_at IS NOT NULL
  AND (CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC'), 'UTC', 'America/New_York') <> end_at
    OR CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC') + INTERVAL 1 HOUR, 'UTC', 'America/New_York') = end_at
    OR CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC') - INTERVAL 1 HOUR, 'UTC', 'America/New_York') = end_at);

SET @dst_edge_cases := (
    SELECT COUNT(*) FROM version_dates
    WHERE CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC'), 'UTC', 'America/New_York') <> start_at
       OR CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC') + INTERVAL 1 HOUR, 'UTC', 'America/New_York') = start_at
       OR CONVERT_TZ(CONVERT_TZ(start_at, 'America/New_York', 'UTC') - INTERVAL 1 HOUR, 'UTC', 'America/New_York') = start_at
       OR (end_at IS NOT NULL
           AND (CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC'), 'UTC', 'America/New_York') <> end_at
             OR CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC') + INTERVAL 1 HOUR, 'UTC', 'America/New_York') = end_at
             OR CONVERT_TZ(CONVERT_TZ(end_at, 'America/New_York', 'UTC') - INTERVAL 1 HOUR, 'UTC', 'America/New_York') = end_at))
);

-- COALESCE: a NULL from any check (e.g. tz tables missing) must read as 0.
SET @go := COALESCE(@tz_ok = 1 AND @already_ran = 0 AND @canary_ok = 1 AND @dst_edge_cases = 0, 0);

-- Expect: tz_ok=1, already_ran=0, canary_ok=1, dst_edge_cases=0, go=1
SELECT @tz_ok AS tz_ok, @already_ran AS already_ran, @canary_ok AS canary_ok,
       @dst_edge_cases AS dst_edge_cases, @go AS go;


-- -----------------------------------------------------------------------------
-- 4. Convert + record the migration, atomically. Both are no-ops unless @go = 1.
--    updated_at is deliberately left alone.
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE version_dates
SET start_at = CONVERT_TZ(start_at, 'America/New_York', 'UTC'),
    end_at   = CONVERT_TZ(end_at, 'America/New_York', 'UTC')
WHERE @go = 1;

-- Derived table, not a bare `SELECT MAX(batch) ... WHERE @go = 1`: an
-- aggregate always yields one row, so the WHERE alone wouldn't stop the insert.
INSERT INTO migrations (migration, batch)
SELECT '2026_10_19_000001_convert_version_dates_to_utc', next_batch.batch
FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS batch FROM migrations) AS next_batch
WHERE @go = 1;

COMMIT;


-- -----------------------------------------------------------------------------
-- 5. Verify. Expect version 88's candidate window to read
--    2026-10-05 04:00:00 -> 2026-10-26 21:00:00 (EDT, +4h), and the migration
--    row to be present exactly once.
-- -----------------------------------------------------------------------------
SELECT id, version_id, date_type, start_at, end_at
FROM version_dates
WHERE version_id = 88
ORDER BY start_at;

SELECT * FROM migrations WHERE migration = '2026_10_19_000001_convert_version_dates_to_utc';


-- -----------------------------------------------------------------------------
-- 6. Housekeeping, independent of @go: record three earlier migrations whose
--    columns were added to production by hand but never logged, so a plain
--    `php artisan migrate` doesn't fail with "Duplicate column". Each row is
--    inserted only if its column really exists and it isn't already recorded.
-- -----------------------------------------------------------------------------
START TRANSACTION;

INSERT INTO migrations (migration, batch)
SELECT pending.migration, next_batch.batch
FROM (
    SELECT '2026_09_21_143207_add_audition_cap_per_school_to_versions_table' AS migration, 'versions' AS table_name, 'audition_cap_per_school' AS column_name
    UNION ALL SELECT '2026_10_02_000003_add_mail_required_to_versions_table', 'versions', 'mail_required'
    UNION ALL SELECT '2026_10_02_000004_add_online_payment_required_to_version_epayment_configs_table', 'version_epayment_configs', 'online_payment_required'
) AS pending
CROSS JOIN (SELECT COALESCE(MAX(batch), 0) + 1 AS batch FROM migrations) AS next_batch
WHERE EXISTS (SELECT 1 FROM information_schema.COLUMNS c
              WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = pending.table_name AND c.COLUMN_NAME = pending.column_name)
  AND NOT EXISTS (SELECT 1 FROM migrations m WHERE m.migration = pending.migration);

COMMIT;

-- Expect all three listed. Then `php artisan migrate:status --pending` should show nothing.
SELECT * FROM migrations
WHERE migration IN (
    '2026_09_21_143207_add_audition_cap_per_school_to_versions_table',
    '2026_10_02_000003_add_mail_required_to_versions_table',
    '2026_10_02_000004_add_online_payment_required_to_version_epayment_configs_table'
);


-- =============================================================================
-- ROLLBACK (only if needed and the snapshot isn't used) — run manually:
--
-- START TRANSACTION;
-- UPDATE version_dates
-- SET start_at = CONVERT_TZ(start_at, 'UTC', 'America/New_York'),
--     end_at   = CONVERT_TZ(end_at, 'UTC', 'America/New_York');
-- DELETE FROM migrations WHERE migration = '2026_10_19_000001_convert_version_dates_to_utc';
-- COMMIT;
-- (Leave the columns as DATETIME — reverting to TIMESTAMP would bring back the
--  ON UPDATE clobbering. Redeploy the previous code release alongside.)
-- =============================================================================
