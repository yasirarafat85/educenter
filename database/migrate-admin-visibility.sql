-- ============================================================================
-- migrate-admin-visibility.sql  (2026-09-24)
-- Admin visibility : login log, who-is-online, activity (audit) log.
-- Run once in phpMyAdmin (LIVE and LOCAL). Non-destructive : only ADD / CREATE.
-- No Bangla text here on purpose (mysql CLI mojibake) - labels live in PHP.
-- ============================================================================

-- 1) login_attempts : remember HOW the login happened and WHO it was.
--    (The table already exists and is written on every attempt; until now the
--     rows were deleted after 1 day and shown nowhere.)
ALTER TABLE login_attempts
    ADD COLUMN IF NOT EXISTS method   VARCHAR(20)  NOT NULL DEFAULT 'password' AFTER success,  -- password / webauthn
    ADD COLUMN IF NOT EXISTS admin_id INT UNSIGNED DEFAULT NULL AFTER method;

-- 2) admin_users : last login + "seen a moment ago" (online dot)
ALTER TABLE admin_users
    ADD COLUMN IF NOT EXISTS last_login_at DATETIME    NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(45)      DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS last_seen_at  DATETIME    NULL DEFAULT NULL;

-- 3) admin_activity_log : who did what, when.
--    Written from the central guard (admin_require_login) on every POST, so no
--    page needs its own logging code. See admin/includes/activity.php
CREATE TABLE IF NOT EXISTS admin_activity_log (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id   INT UNSIGNED DEFAULT NULL,
    username   VARCHAR(50)  NOT NULL DEFAULT '',   -- snapshot, survives account delete
    role       VARCHAR(20)  NOT NULL DEFAULT '',
    page       VARCHAR(60)  NOT NULL DEFAULT '',   -- registrations.php, manage.php ...
    action     VARCHAR(40)  NOT NULL DEFAULT '',   -- delete / pay-save / setstatus ...
    target_id  INT UNSIGNED DEFAULT NULL,
    context    VARCHAR(255) NOT NULL DEFAULT '',   -- entity=courses, month=3 ...
    ip_address VARCHAR(45)  NOT NULL DEFAULT '',
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_admin (admin_id),
    INDEX idx_page (page)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
