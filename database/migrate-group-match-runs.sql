-- ============================================================
-- 👥 GROUP MATCH HISTORY (group_match_runs) -- 2026-09-29
--
-- Purpose: admin/group-match.php now keeps a record of every scan --
--          which member names were pasted, and what the matching found.
--          Requested by the user ("মিলালাম সেটা একটা হিস্ট্রি থাকবে")।
--
-- NOTE: this table only records the report. It never changes a
--       registration, a group tick, a status or a payment row.
--
-- PRIVACY: `result_json` intentionally stores only NAMES and registration
--          ids -- never phone numbers (phones are re-read live from the
--          registrations table when a past run is opened).
--
-- ⚠️ Run this SQL once in phpMyAdmin on BOTH the live and the local DB.
-- ⚠️ non-destructive: creates one new table, touches nothing else.
-- 🔴 No Bengali text in this file on purpose (some tools double-encode it
--    into mojibake) -- all Bengali labels live in PHP.
-- ============================================================

CREATE TABLE IF NOT EXISTS `group_match_runs` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- which course batch was matched (snapshot, so renames never break history)
    `item_id`     INT UNSIGNED NOT NULL DEFAULT 0,
    `item_title`  VARCHAR(255) NOT NULL DEFAULT '',
    `batch`       VARCHAR(100) NOT NULL DEFAULT '',

    -- who ran it
    `admin_id`    INT UNSIGNED DEFAULT NULL,
    `admin_name`  VARCHAR(100) NOT NULL DEFAULT '',

    -- exactly what was pasted into the textarea (capped in PHP at GM_MAX_CHARS)
    `raw_names`   MEDIUMTEXT   NOT NULL,

    -- compact snapshot of the report (see gm_snapshot() in
    -- admin/includes/group-match.php). No phone numbers inside.
    `result_json` MEDIUMTEXT   NOT NULL,

    -- denormalised counters so the history list needs no JSON parsing
    `n_group`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `n_matched`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `n_unmatched` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `n_missing`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX `idx_item` (`item_id`, `batch`),
    INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
