-- 🖼️ ফেসবুক ইনবক্সে ছবি পাঠানো (2026-11-17)
-- phpMyAdmin → SQL ট্যাবে পুরোটা চালান। বারবার চালালেও ক্ষতি নেই (IF NOT EXISTS)।
-- আগে migrate-meta-knowledge.sql ও migrate-meta-ai.sql চালানো থাকতে হবে।
-- 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।

-- ছবির সংগ্রহ — নিয়মের সাথে জোড়া যায়, AI-ও দরকার মনে করলে এখান থেকে বেছে পাঠায়
CREATE TABLE IF NOT EXISTS meta_images (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(190) NOT NULL,
    when_text   TEXT         NULL,
    file_path   VARCHAR(255) NOT NULL,
    is_active   TINYINT      NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    uses        INT          NOT NULL DEFAULT 0,
    created_by  VARCHAR(100) NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meta_images (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- নিয়মের সাথে একটা ছবি (খালি = শুধু লেখা)
ALTER TABLE meta_rules ADD COLUMN IF NOT EXISTS image_id INT NULL;

-- AI-র খসড়ার সাথে বেছে নেওয়া ছবি
ALTER TABLE meta_ai_drafts ADD COLUMN IF NOT EXISTS image_id INT NULL;
