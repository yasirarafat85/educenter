-- ============================================================================
-- migrate-user-account-v2.sql  (2026-09-24)
-- Parent account phase 3 : correction-requests / remarks  +  "remember me" login
-- Run once in phpMyAdmin (LIVE and LOCAL).  Non-destructive : only CREATE TABLE.
-- No Bangla text here on purpose (mysql CLI mojibake) - labels live in PHP.
-- ============================================================================

-- Parent-submitted requests : wrong-info correction or a free remark.
-- Admin reads them in admin/user-requests.php.
CREATE TABLE IF NOT EXISTS user_requests (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL,
    phone           VARCHAR(20)  NOT NULL,            -- snapshot, survives account delete
    registration_id INT UNSIGNED DEFAULT NULL,        -- which course/order it is about (optional)
    item_title      VARCHAR(255) DEFAULT NULL,        -- snapshot of that course name
    kind            VARCHAR(20)  NOT NULL DEFAULT 'remark',   -- correction / remark
    message         TEXT         NOT NULL,
    status          VARCHAR(20)  NOT NULL DEFAULT 'new',      -- new / done
    created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    handled_at      TIMESTAMP    NULL DEFAULT NULL,
    INDEX idx_status (status),
    INDEX idx_user (user_id),
    CONSTRAINT fk_ureq_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Remember me" : long-lived login token (cookie holds the raw value, DB the hash).
-- Rotated on every use, so a stolen old cookie stops working.
CREATE TABLE IF NOT EXISTS user_remember_tokens (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64)     NOT NULL,                 -- sha256 of the raw cookie value
    expires_at DATETIME     NOT NULL,
    created_at TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP  NULL DEFAULT NULL,
    UNIQUE KEY uq_token (token_hash),
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at),
    CONSTRAINT fk_urem_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
