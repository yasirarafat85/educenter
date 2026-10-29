-- 📨 যোগাযোগ বার্তা (Get in touch) — 2026-10-29
-- পাবলিক ভিজিটর সাইটেই একটা বার্তা লিখে পাঠায়; অ্যাডমিন ইনবক্সে পড়ে নিজে যোগাযোগ করেন।
-- সম্পূর্ণ আলাদা স্তর — টাকা/খাতা/আয় কোনো টেবিলের সাথে সম্পর্ক নেই, কোনো FK নেই।
--
-- ফিরে যেতে হলে:  DROP TABLE contact_messages;   (সাইট হুবহু আগের অবস্থায় ফিরে যায়)
--
-- 🔴 বাংলা টেক্সট এখানে নেই (mojibake নিয়ম) — ধরন/লেখার ডিফল্ট PHP-তে।

CREATE TABLE IF NOT EXISTS contact_messages (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL DEFAULT '',
    phone       VARCHAR(20)  NOT NULL DEFAULT '',
    email       VARCHAR(190) NOT NULL DEFAULT '',
    topic       VARCHAR(80)  NOT NULL DEFAULT '',
    message     TEXT         NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'new',
    ip_address  VARCHAR(45)  NOT NULL DEFAULT '',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    handled_at  DATETIME     NULL,
    INDEX idx_cmsg_status (status, id),
    INDEX idx_cmsg_topic (topic)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
