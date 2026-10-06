-- ============================================================
-- Payment QR image for a payment method   (2026-10-06)
--
-- The parent-facing payment page shows ONE channel at a time (bKash / Nagad)
-- with its number, a copy button and -- optionally -- a QR picture.
--
-- 🔴 We cannot generate a scannable bKash/Nagad QR ourselves: their apps only
--    read their own QR format. So the admin downloads the personal QR from
--    their own bKash/Nagad app and uploads it here. Leaving it empty is fine --
--    the page then shows just the number, which is what most parents use.
--
-- 🟢 Safe to run before or after the files are deployed: every read is guarded
--    with db_has_column(), so the site works unchanged until this SQL is run.
--
-- ⚠️ Run once in phpMyAdmin on BOTH the live and the local DB.
-- 🔴 No Bengali text in this file on purpose (some tools double-encode it).
-- ============================================================

ALTER TABLE `payment_methods`
    ADD COLUMN `qr_image` VARCHAR(255) NOT NULL DEFAULT '' AFTER `instruction`;
