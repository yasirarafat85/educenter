-- ─────────────────────────────────────────────────────────────
-- 📌 করণীয় / রিকোয়ারমেন্ট ইনবক্স (2026-10-28)
--
-- প্রতি অর্ডারের (বা অর্ডার ছাড়াই কারও) ছোট ছোট রিকোয়ারমেন্ট — ঠিকানা বদল,
-- পার্সেল পরে যাবে, টাকা পরে দিবে, দুই সেট ইত্যাদি — যেগুলো আগে WhatsApp গ্রুপে
-- স্ক্রিনশট রেখে মনে রাখা হতো। এখন কাঠামোবদ্ধভাবে জমা থাকে, "✅ হয়ে গেছে" টিক দিলে
-- কে/কখন করল তা থেকে যায়।
--
-- 🔴🔴 সম্পূর্ণ আলাদা স্তর — টাকা/খাতা/আয় (registration_payments/income) কিছুই ছোঁয় না।
-- 🔴 registration_id nullable (অর্ডার ছাড়া রিকোয়ারমেন্টও রাখা যায়), FK CASCADE —
--    অর্ডার ডিলিটে করণীয়ও যায় (আর্কাইভে বান্ডল হয়, রিস্টোরে ফেরে)।
-- 🔴 নাম/ফোন/কোর্স/ব্যাচ স্ন্যাপশট — অর্ডার মুছে গেলেও ইনবক্সে কে/কিসের তা থাকে
--    (registrations.item_title/batch স্ন্যাপশটের মতোই নীতি)।
-- 🔴 type_key-তে বাংলা ডিফল্ট নেই (খালি) — আসল ধরনের লেখা PHP থেকে বসে (mojibake নিয়ম)।
--
-- Non-destructive: শুধু CREATE TABLE। লাইভ ও লোকালে একবার চালানো নিরাপদ।
-- রোল-ব্যাক: DROP TABLE registration_tasks;  (ফিচার সম্পূর্ণ উধাও, সাইট হুবহু আগের মতো)
-- নরম বন্ধ: সেটিংসে task_feature_on = 0  (লুকায়, ডেটা থাকে)
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS registration_tasks (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NULL,                  -- অর্ডারের সাথে জোড়া (ঐচ্ছিক), FK CASCADE
    person_name     VARCHAR(150) NOT NULL DEFAULT '',   -- কার জন্য (স্ন্যাপশট)
    person_phone    VARCHAR(20)  NOT NULL DEFAULT '',   -- মোবাইল (স্ন্যাপশট)
    item_id         INT UNSIGNED NULL,                  -- course_batches.id (snapshot, FK নয়)
    item_title      VARCHAR(200) NOT NULL DEFAULT '',   -- কোর্সের নাম স্ন্যাপশট
    batch           VARCHAR(160) NOT NULL DEFAULT '',   -- ব্যাচের নাম স্ন্যাপশট
    type_key        VARCHAR(80)  NOT NULL DEFAULT '',   -- ধরনের লেখা (প্রিসেট থেকে; বাংলা PHP থেকে বসে)
    note            TEXT         NULL,                  -- বিস্তারিত নোট
    target_month    INT          NULL,                  -- কোন মাসে (NULL/0 = স্থায়ী)
    image_path      VARCHAR(255) NOT NULL DEFAULT '',   -- uploads/tasks/xxxx.webp (ঐচ্ছিক স্ক্রিনশট)
    status          VARCHAR(20)  NOT NULL DEFAULT 'open', -- open / done
    created_by      VARCHAR(50)  NOT NULL DEFAULT '',   -- যে অ্যাডমিন/মডারেটর যোগ করেছেন
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    done_by         VARCHAR(50)  NOT NULL DEFAULT '',   -- যিনি সম্পন্ন করেছেন
    done_at         DATETIME     NULL,
    INDEX idx_rtask_status (status, created_at),
    INDEX idx_rtask_reg (registration_id),
    INDEX idx_rtask_item (item_id),
    CONSTRAINT fk_rtask_reg FOREIGN KEY (registration_id)
        REFERENCES registrations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
