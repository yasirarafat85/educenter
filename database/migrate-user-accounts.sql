-- ============================================================
-- মাইগ্রেশন: অভিভাবক অ্যাকাউন্ট সিস্টেম (ধাপ ১ — ফোন পথ + ড্যাশবোর্ড)
-- USER-ACCOUNT-PLAN.md দ্রষ্টব্য। non-destructive, idempotent (MariaDB)।
-- ============================================================

-- অভিভাবক অ্যাকাউন্ট। phone = রেজিস্ট্রেশনের মোবাইল (পরিচয়-চাবি)।
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone         VARCHAR(20)  NOT NULL,
    password_hash VARCHAR(255) DEFAULT NULL,          -- ফোন-পথের জন্য
    google_id     VARCHAR(64)  DEFAULT NULL,          -- ধাপ ২ (Google) — এখন NULL
    email         VARCHAR(150) DEFAULT NULL,
    full_name     VARCHAR(100) DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'pending', -- pending/approved/rejected/blocked
    created_at    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    approved_at   TIMESTAMP    NULL DEFAULT NULL,
    approved_by   INT UNSIGNED DEFAULT NULL,
    UNIQUE KEY uq_phone (phone),
    UNIQUE KEY uq_google (google_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- অভিভাবক লগইন রেট-লিমিট (অ্যাডমিনের login_attempts থেকে আলাদা, যাতে ক্রস-লক না হয়)
CREATE TABLE IF NOT EXISTS user_login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address   VARCHAR(45),
    phone        VARCHAR(20),
    success      TINYINT(1) DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_phone_time (phone, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- কোর্স-ব্যাচে প্রাইভেট গ্রুপ লিংক (ইউজার কেনা কোর্সের গ্রুপ ড্যাশবোর্ডে দেখবে)
ALTER TABLE course_batches
    ADD COLUMN IF NOT EXISTS fb_group_url        VARCHAR(500) DEFAULT NULL AFTER description,
    ADD COLUMN IF NOT EXISTS messenger_group_url VARCHAR(500) DEFAULT NULL AFTER fb_group_url;
