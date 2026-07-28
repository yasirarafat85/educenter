-- ============================================================
-- মাইগ্রেশন: course_interests টেবিল যোগ (আগ্রহ / ওয়েটিং লিস্ট)
-- রেজিস্ট্রেশন বন্ধ কোর্সে অভিভাবক আগ্রহ জানিয়ে রাখেন — নতুন ব্যাচ খুললে যোগাযোগ।
-- non-destructive, idempotent (IF NOT EXISTS) — real DB-তে নিরাপদে চালানো যায়।
-- ============================================================

CREATE TABLE IF NOT EXISTS course_interests (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id      INT UNSIGNED DEFAULT NULL,
    item_title    VARCHAR(255) DEFAULT NULL,
    batch_name    VARCHAR(100) DEFAULT NULL,
    contact_phone VARCHAR(20)  NOT NULL,
    phone_owner   VARCHAR(10)  NOT NULL DEFAULT 'mother',
    child_name    VARCHAR(150) DEFAULT NULL,
    facebook_name VARCHAR(150) DEFAULT NULL,
    remarks       VARCHAR(500) DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'new',
    ip_address    VARCHAR(45)  DEFAULT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id) REFERENCES course_batches(id) ON DELETE SET NULL,
    INDEX idx_ci_phone (contact_phone),
    INDEX idx_ci_status (status),
    INDEX idx_ci_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
