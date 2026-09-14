-- ─────────────────────────────────────────────────────────────
-- অ্যাডমিন রোল ও মডারেটর অনুমতি (RBAC) — ২০২৬-০৯-১৪
-- role: 'admin' (মূল, সব অ্যাক্সেস) / 'moderator' (সীমিত, permissions অনুযায়ী)
-- permissions: JSON array of section keys (শুধু moderator-এর জন্য)
-- is_active: 0 হলে লগইন বন্ধ (মূল অ্যাডমিন থেকে নিয়ন্ত্রিত)
-- non-destructive। লাইভ+লোকাল phpMyAdmin-এ চালাতে হবে।
-- ⚠️ চালানোর পর: বিদ্যমান সব অ্যাকাউন্ট role='admin' থাকবে (আপনার নিজেরটা অক্ষত)।
-- ─────────────────────────────────────────────────────────────
ALTER TABLE admin_users
    ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin' AFTER full_name,
    ADD COLUMN permissions TEXT NULL AFTER role,
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER permissions;
