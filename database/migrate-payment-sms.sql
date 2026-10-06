-- ============================================================
-- migrate-payment-sms.sql   (2026-10-06)
--
-- SMS-BASED AUTO PAYMENT VERIFICATION -- step 1 (foundation).
-- See PAYMENT-SMS-PLAN.md for the full design.
--
-- Three new tables, nothing else touched:
--   payment_sms_patterns  admin-editable SMS templates / regexes
--   payment_sms           raw incoming SMS + parsed fields
--   payment_claims        what a parent says they paid (TrxID unique)
--
-- 🔴 MOST IMPORTANT RULE (enforced in PHP, recorded here so it is not lost):
--    nothing in this subsystem ever writes into `registration_payments`
--    or `income`. A verified claim waits in payment_claims until an admin
--    taps "post to ledger", which then goes through the existing
--    pay_allocate_paid() -> pay_save_rows() -> sync_income_for_status() path.
--    Writing a ledger row directly would make income appear silently at the
--    next save, because sync_income_for_status() derives income from the
--    ledger and is called from 7 places.
--
-- 🔴 UNIQUE(trxid_norm) on payment_claims is the database-level guarantee the
--    user asked for: one TrxID can be claimed only once. A PHP check alone
--    would lose a race between two simultaneous submits.
--
-- 🟢 Safe to run before or after the files are deployed: every PHP read of
--    these tables is wrapped in try/catch, so the site works unchanged until
--    this SQL is run (payment_sms_ready() then simply reports "not ready").
--
-- ⚠️ Run once in phpMyAdmin on BOTH the live and the local DB.
-- 🔴 No Bengali text in this file on purpose (some tools double-encode it into
--    mojibake) -- all Bengali labels, including the built-in pattern names,
--    live in PHP (see psms_default_patterns() in includes/payment-sms.php).
-- ============================================================

-- ─────────────────────────────────────────────────────────────
-- 1. Admin-editable SMS patterns
--    `template` is the human-friendly form the admin edits, e.g.
--      You have received Tk {amount} from {number}. ... TrxID {trxid} at {datetime}
--    `pattern` is the regex compiled from it (psms_template_to_regex()).
--    Advanced mode stores a raw regex in `pattern` with `template` empty.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_sms_patterns` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `label`               VARCHAR(120)  NOT NULL DEFAULT '',
    `provider`            VARCHAR(30)   NOT NULL DEFAULT '',
    `template`            TEXT          NULL,
    `pattern`             TEXT          NOT NULL,
    `fields_json`         TEXT          NULL,
    `sample_sms`          TEXT          NULL,

    -- "should a message of this shape count as a CUSTOMER payment?"
    -- 0 for our own agent cash-in messages, which must not show up as
    -- unclaimed customer money (the Nagad sample literally says "Uddokta:").
    `is_customer_payment` TINYINT(1)    NOT NULL DEFAULT 1,

    `is_active`           TINYINT(1)    NOT NULL DEFAULT 1,
    `sort_order`          INT           NOT NULL DEFAULT 0,
    `created_at`          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX `idx_psp_active` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 2. Raw incoming SMS
--    🔴 the raw text is NEVER discarded, even when no pattern matches --
--    the admin can fix the pattern and re-parse the stored rows.
--    `raw_hash` is UNIQUE so a retrying phone cannot insert the same SMS twice.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_sms` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `pattern_id`          INT UNSIGNED  NULL,
    `provider`            VARCHAR(30)   NOT NULL DEFAULT '',
    `sender`              VARCHAR(60)   NOT NULL DEFAULT '',
    `raw_text`            TEXT          NOT NULL,
    `raw_hash`            CHAR(64)      NOT NULL,

    -- parsed fields (NULL / empty when the SMS did not match any pattern)
    `amount`              DECIMAL(10,2) NULL,
    `sender_number`       VARCHAR(20)   NOT NULL DEFAULT '',
    `trxid`               VARCHAR(40)   NOT NULL DEFAULT '',
    `trxid_norm`          VARCHAR(40)   NOT NULL DEFAULT '',
    `fee`                 DECIMAL(10,2) NULL,
    `balance`             DECIMAL(12,2) NULL,
    `ref_text`            VARCHAR(120)  NOT NULL DEFAULT '',
    `sent_at`             DATETIME      NULL,

    `is_customer_payment` TINYINT(1)    NOT NULL DEFAULT 1,
    `parse_status`        VARCHAR(20)   NOT NULL DEFAULT 'unparsed',
    `claim_id`            INT UNSIGNED  NULL,
    `received_at`         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,

    UNIQUE KEY `uniq_psms_hash` (`raw_hash`),
    INDEX `idx_psms_trx` (`trxid_norm`),
    INDEX `idx_psms_recv` (`received_at`),
    INDEX `idx_psms_open` (`claim_id`, `parse_status`),

    CONSTRAINT `fk_psms_pattern` FOREIGN KEY (`pattern_id`)
        REFERENCES `payment_sms_patterns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3. What a parent says they paid
--    status: new (not matched yet) / verified (SMS found) /
--            posted (admin put it in the ledger) / rejected
--    🔴 CASCADE on registration_id (like `expenses`, unlike `income`) so a
--       deleted registration can never leave an orphan claim behind.
--       item_title keeps the course name readable in history.
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `payment_claims` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `registration_id`  INT UNSIGNED  NULL,
    `item_title`       VARCHAR(255)  NOT NULL DEFAULT '',
    `batch`            VARCHAR(100)  NOT NULL DEFAULT '',
    `channel`          VARCHAR(30)   NOT NULL DEFAULT '',
    `phone`            VARCHAR(20)   NOT NULL DEFAULT '',
    `amount`           DECIMAL(10,2) NOT NULL DEFAULT 0,
    `trxid`            VARCHAR(40)   NOT NULL DEFAULT '',
    `trxid_norm`       VARCHAR(40)   NOT NULL DEFAULT '',
    `status`           VARCHAR(20)   NOT NULL DEFAULT 'new',
    `matched_sms_id`   INT UNSIGNED  NULL,
    `auto_confirmed`   TINYINT(1)    NOT NULL DEFAULT 0,
    `posted_at`        DATETIME      NULL,
    `posted_by`        INT UNSIGNED  NULL,
    `admin_note`       VARCHAR(500)  NOT NULL DEFAULT '',
    `client_ip`        VARCHAR(45)   NOT NULL DEFAULT '',
    `created_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY `uniq_pclaim_trx` (`trxid_norm`),
    INDEX `idx_pclaim_reg` (`registration_id`),
    INDEX `idx_pclaim_status` (`status`, `created_at`),

    CONSTRAINT `fk_pclaim_reg` FOREIGN KEY (`registration_id`)
        REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4. payment_sms.claim_id -> payment_claims (added last: the two tables
--    reference each other, so one link has to be an ALTER)
--
-- 🔴 ON DELETE SET NULL on purpose: if a claim disappears (a registration was
--    deleted, so its claims CASCADE away), the SMS must go back to the
--    "unclaimed money" list. Without this the row would keep a dangling
--    claim_id and vanish from every list -- real money, silently hidden.
--
-- ⚠️ payment_claims.matched_sms_id deliberately has NO foreign key: adding one
--    would make the two tables circularly dependent, which breaks plain
--    mysqldump restores. It is a soft pointer; nothing is computed from it.
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `payment_sms`
    ADD CONSTRAINT `fk_psms_claim` FOREIGN KEY (`claim_id`)
        REFERENCES `payment_claims` (`id`) ON DELETE SET NULL;
