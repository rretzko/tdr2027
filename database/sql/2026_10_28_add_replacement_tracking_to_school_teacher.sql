-- =============================================================================
-- Production equivalent of migration
--   2026_10_28_000001_add_replacement_tracking_to_school_teacher_table
--
-- Repairs one zero-date verified_at, adds school_teacher.replaced_teacher_id (nullable FK -> teachers, ON DELETE
-- SET NULL) and school_teacher.replacement_transferred_at, backfills the latter
-- to verified_at for already-verified replacement rows, and records the
-- migration in `migrations` so `php artisan migrate` never runs it again.
--
-- Purely additive: no existing value changes, nothing is read by the currently
-- deployed code. Safe to run while registration is live; no `vapor down` needed.
--
-- Release order:
--   1. Run this script top to bottom; review every result set
--   2. Deploy the replaced-teacher-transfer-fix code (it reads both columns)
--
-- See docs/plans/replaced-teacher-transfer-fix.md.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- 1. Pre-check. Expect zero rows from the first query (not already applied) and
--    the second to list the replacement rows about to be backfilled
--    (10 rows on the 2026-10-28 prod copy).
-- -----------------------------------------------------------------------------
SELECT migration FROM migrations
WHERE migration = '2026_10_28_000001_add_replacement_tracking_to_school_teacher_table';

SELECT id, school_id, teacher_id, replacing_teacher_name, verified_at
FROM school_teacher
WHERE replacing_teacher_name IS NOT NULL
  AND replacing_teacher_name <> ''
  AND verified_at IS NOT NULL
ORDER BY id;


-- -----------------------------------------------------------------------------
-- 2. Zero-date repair. Adding the FK rebuilds the table, and strict mode rejects
--    any row holding 0000-00-00 00:00:00. On the 2026-10-28 prod copy exactly one
--    row (id 471, teacher 170 Jami De Siena @ school 2549 Churchill Jr High, no
--    active-version candidates) has verified_at = 0000-00-00 00:00:00 from a
--    direct edit. The app already treats it as verified (not NULL), so setting it
--    to created_at preserves that meaning. Expect 1 row from the SELECT, then
--    "1 row affected" (0 if already fixed), then 0 rows from the recheck.
-- -----------------------------------------------------------------------------
SELECT id, school_id, teacher_id, CAST(verified_at AS CHAR) AS verified_at, created_at
FROM school_teacher
WHERE CAST(verified_at AS CHAR) LIKE '0000-00-00%'
   OR CAST(created_at AS CHAR) LIKE '0000-00-00%'
   OR CAST(updated_at AS CHAR) LIKE '0000-00-00%';

UPDATE school_teacher
SET verified_at = created_at
WHERE CAST(verified_at AS CHAR) LIKE '0000-00-00%';

SELECT COUNT(*) AS zero_dates_remaining
FROM school_teacher
WHERE CAST(verified_at AS CHAR) LIKE '0000-00-00%'
   OR CAST(created_at AS CHAR) LIKE '0000-00-00%'
   OR CAST(updated_at AS CHAR) LIKE '0000-00-00%';
-- If zero_dates_remaining > 0 (created_at/updated_at), STOP and fix those first.


-- -----------------------------------------------------------------------------
-- 3. Schema change (DDL auto-commits in MySQL; run before the transaction).
-- -----------------------------------------------------------------------------
ALTER TABLE school_teacher
    ADD COLUMN replaced_teacher_id BIGINT UNSIGNED NULL AFTER replacing_teacher_name,
    ADD COLUMN replacement_transferred_at TIMESTAMP NULL DEFAULT NULL AFTER verified_at,
    ADD CONSTRAINT school_teacher_replaced_teacher_id_foreign
        FOREIGN KEY (replaced_teacher_id) REFERENCES teachers (id) ON DELETE SET NULL;


-- -----------------------------------------------------------------------------
-- 4. Backfill + record the migration.
-- -----------------------------------------------------------------------------
START TRANSACTION;

UPDATE school_teacher
SET replacement_transferred_at = verified_at
WHERE replacing_teacher_name IS NOT NULL
  AND replacing_teacher_name <> ''
  AND verified_at IS NOT NULL;

INSERT INTO migrations (migration, batch)
SELECT '2026_10_28_000001_add_replacement_tracking_to_school_teacher_table', next_batch.batch
FROM (SELECT COALESCE(MAX(batch), 0) + 1 AS batch FROM migrations) AS next_batch;

COMMIT;


-- -----------------------------------------------------------------------------
-- 5. Verify. Expect the same rows as step 1 with replacement_transferred_at =
--    verified_at, and replaced_teacher_id NULL everywhere.
-- -----------------------------------------------------------------------------
SELECT id, school_id, teacher_id, replacing_teacher_name, replaced_teacher_id,
       verified_at, replacement_transferred_at
FROM school_teacher
WHERE replacement_transferred_at IS NOT NULL OR replaced_teacher_id IS NOT NULL
ORDER BY id;

SHOW CREATE TABLE school_teacher;


-- =============================================================================
-- ROLLBACK (only if needed) — run manually, after redeploying the previous code:
--
-- ALTER TABLE school_teacher
--     DROP FOREIGN KEY school_teacher_replaced_teacher_id_foreign,
--     DROP COLUMN replaced_teacher_id,
--     DROP COLUMN replacement_transferred_at;
-- DELETE FROM migrations
-- WHERE migration = '2026_10_28_000001_add_replacement_tracking_to_school_teacher_table';
-- =============================================================================
