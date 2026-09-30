-- ============================================================
-- Parcel waiver / discount recorded as an expense (2026-09-30)
--
-- WHY: when a student is given free delivery or a discount on a
-- monthly parcel, that money is a real cost for the institute.
-- Admin now types the waived amount on the course-parcel page:
--   * it is subtracted from the amount the courier collects, and
--   * one matching row is written into `expenses`.
-- Setting the amount back to 0 removes that expense row again,
-- so nothing lingers in the books.
--
-- Run this once in phpMyAdmin (LIVE and LOCAL).
-- Non-destructive: only adds columns, no data is touched.
-- ============================================================

-- 1) The waiver itself lives on the parcel row (source of truth).
ALTER TABLE courier_batches
    ADD COLUMN waived_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER adjustment_reason,
    ADD COLUMN waived_reason VARCHAR(255) DEFAULT '' AFTER waived_amount;

-- 2) An expense row can now point back at the registration + month
--    it was generated from, so the sync can update / delete exactly
--    that row and never touch a manually added expense.
--    `source` stays empty ('') for every expense typed by hand.
ALTER TABLE expenses
    ADD COLUMN registration_id INT UNSIGNED NULL AFTER category_id,
    ADD COLUMN period_label VARCHAR(50) NULL AFTER registration_id,
    ADD COLUMN source VARCHAR(30) NOT NULL DEFAULT '' AFTER period_label,
    ADD KEY idx_exp_source (source, registration_id, period_label),
    ADD CONSTRAINT fk_expenses_registration
        FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE;
