-- ============================================================
-- মাইগ্রেশন: পুরাতন শিক্ষার্থী (legacy_students) — পুরনো Excel/CSV ডেটার রেফারেন্স তালিকা।
-- ⚠️ এটা registrations টেবিল থেকে সম্পূর্ণ আলাদা — আয় (income)/কুরিয়ার সিস্টেম এটা ছোঁয় না।
--    শুধু গণনা, খোঁজা, ও ফোন মিলিয়ে অভিভাবকের পুরনো তথ্য দেখানোর জন্য।
-- non-destructive, idempotent। বাংলা ডিফল্ট লেখা নেই (mojibake এড়াতে — CLAUDE.md নিয়ম)।
-- ============================================================
CREATE TABLE IF NOT EXISTS legacy_students (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(191) NOT NULL DEFAULT '',
    phone         VARCHAR(30)  NOT NULL DEFAULT '',  -- 🔑 মিলানোর চাবি (অভিভাবক লগইন/অটো-ফিল)
    father_mobile VARCHAR(30)  NOT NULL DEFAULT '',
    course_title  VARCHAR(191) NOT NULL DEFAULT '',
    batch         VARCHAR(100) NOT NULL DEFAULT '',
    date_of_birth VARCHAR(50)  NOT NULL DEFAULT '',  -- টেক্সট (পুরনো ডেটায় ফরম্যাট নানা রকম)
    facebook_id   VARCHAR(191) NOT NULL DEFAULT '',
    address       TEXT,
    notes         TEXT,
    imported_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ls_phone (phone),
    INDEX idx_ls_course (course_title)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
