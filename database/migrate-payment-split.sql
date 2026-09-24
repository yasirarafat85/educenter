-- ─────────────────────────────────────────────────────────────
-- Payment ledger: money-equal instalment split + exact month mapping
-- (2026-09-24)
--
-- 1) course_batches.tuition_split_mode
--    ''/'money'  = split the TOTAL tuition equally between the instalments
--                  (3 months x 690 = 2070 in 2 instalments -> 1035 + 1035)
--    'months'    = keep whole-month groups (old behaviour -> 1380 + 690)
--    When months divide evenly by instalments both rules give the same result.
--
-- 2) registration_payments.month_from / month_to
--    Which collection slots (courier parcel 1..N) an instalment covers.
--    Until now this was reverse-parsed from the Bangla label text; these two
--    columns make the courier <-> ledger mapping exact and free the label.
--    Old rows stay NULL and keep using the label fallback.
--
-- Non-destructive: only ADD COLUMN. Safe to run once on live and local.
-- ─────────────────────────────────────────────────────────────

ALTER TABLE course_batches
    ADD COLUMN tuition_split_mode VARCHAR(10) NOT NULL DEFAULT '' AFTER tuition_installments;

ALTER TABLE registration_payments
    ADD COLUMN month_from SMALLINT UNSIGNED NULL DEFAULT NULL AFTER label,
    ADD COLUMN month_to   SMALLINT UNSIGNED NULL DEFAULT NULL AFTER month_from;
