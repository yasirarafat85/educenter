-- ─────────────────────────────────────────────────────────────
-- WebAuthn (ফিঙ্গারপ্রিন্ট/পাসকি) অ্যাডমিন লগইন — ২০২৬-০৮-৩০
-- অ্যাডমিন পাসওয়ার্ডের পাশাপাশি অতিরিক্ত ফিঙ্গারপ্রিন্ট লগইন।
-- non-destructive (শুধু নতুন টেবিল)। লাইভ+লোকাল দুই জায়গায় চালাতে হবে।
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS admin_webauthn_credentials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    credential_id VARCHAR(255) NOT NULL UNIQUE,   -- base64url
    public_key TEXT NOT NULL,                      -- PEM (P-256 পাবলিক কী)
    sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
    device_name VARCHAR(100) NOT NULL DEFAULT 'আমার ডিভাইস',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    last_used_at TIMESTAMP NULL DEFAULT NULL,
    CONSTRAINT fk_webauthn_admin FOREIGN KEY (admin_id) REFERENCES admin_users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
