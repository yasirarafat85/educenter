-- ─────────────────────────────────────────────────────────────
-- শিক্ষার্থীর রিপোর্ট কার্ড / ফলাফল (2026-10-26)
--
-- অ্যাডমিন প্রতিটা শিক্ষার্থীর PDF রিপোর্ট কার্ড আপলোড করেন (uploads/reports/,
-- deny-all .htaccess দিয়ে সুরক্ষিত — সরাসরি URL দিয়ে খোলা যায় না)।
-- অভিভাবক নিজের অ্যাকাউন্টে লগইন করে শুধু **নিজের মোবাইলের** রিপোর্ট দেখেন
-- (account.php → report-view.php, সেশনের ফোন ধরে last-10 মিলিয়ে)।
--
-- 🔑 মোবাইল নম্বরই পরিচয়-চাবি (registrations/legacy_students-এর মতোই)।
-- 🔴 item_id-তে FK দেওয়া হয়নি (registrations.item_id-এর মতোই) — ব্যাচ ডিলিট/রিনেম
--    হলেও রিপোর্ট হারায় না, কোর্স/ব্যাচ স্ন্যাপশট নামে ইতিহাসে থাকে।
--
-- Non-destructive: শুধু CREATE TABLE। লাইভ ও লোকালে একবার চালানো নিরাপদ।
-- রোল-ব্যাক: DROP TABLE student_reports;
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS student_reports (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    phone        VARCHAR(20)  NOT NULL DEFAULT '',   -- পরিচয়-চাবি (অভিভাবকের লগইন মোবাইল)
    student_name VARCHAR(150) NOT NULL DEFAULT '',   -- শিক্ষার্থীর নাম (স্ন্যাপশট)
    title        VARCHAR(200) NOT NULL DEFAULT '',   -- রিপোর্টের নাম (যেমন "বার্ষিক রিপোর্ট কার্ড")
    item_id      INT UNSIGNED NULL,                  -- course_batches.id আপলোডের সময় (FK নয়, snapshot)
    item_title   VARCHAR(200) NOT NULL DEFAULT '',   -- কোর্সের নাম স্ন্যাপশট
    batch        VARCHAR(160) NOT NULL DEFAULT '',   -- ব্যাচের নাম স্ন্যাপশট
    file_path    VARCHAR(255) NOT NULL DEFAULT '',   -- uploads/reports/xxxx.pdf (সাইট-রুট-রিলেটিভ)
    file_name    VARCHAR(255) NOT NULL DEFAULT '',   -- আসল ফাইলের নাম (ডাউনলোডের নামে)
    uploaded_by  VARCHAR(50)  NOT NULL DEFAULT '',   -- যে অ্যাডমিন/মডারেটর আপলোড করেছেন
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rep_phone (phone),
    INDEX idx_rep_item (item_id, batch)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
