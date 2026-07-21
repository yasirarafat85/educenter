-- ============================================================================
--  ফেসবুক পোস্ট/রিল সেকশন (২০২৬-০৭-২০)
-- ============================================================================
--  পাবলিক সাইটে "আমাদের ফেসবুকে" সেকশনে দেখানোর জন্য অ্যাডমিন যে লিংকগুলো
--  পেস্ট করবেন সেগুলো এখানে জমা থাকে। ধরন (পোস্ট/রিল/ভিডিও) লিংক দেখেই
--  অটোমেটিক শনাক্ত হয় — আলাদা করে বাছতে হয় না।
--
--  ⚠️ NON-DESTRUCTIVE ও IDEMPOTENT — একাধিকবার চালালেও সমস্যা নেই।
--  প্রয়োগ: cPanel → phpMyAdmin → আপনার DB সিলেক্ট → Import → এই ফাইল → Go
-- ============================================================================

CREATE TABLE IF NOT EXISTS `social_posts` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`      VARCHAR(200) DEFAULT NULL,   -- ঐচ্ছিক লেবেল (যেমন "ভর্তি বিজ্ঞপ্তি") — খালি রাখলে শুধু পোস্টটাই দেখাবে
    `url`        VARCHAR(500) NOT NULL,       -- ফেসবুক পোস্ট/রিল/ভিডিওর সম্পূর্ণ লিংক (পোস্টটি পাবলিক হতে হবে)
    `is_featured` TINYINT(1)  NOT NULL DEFAULT 0, -- "গুরুত্বপূর্ণ" — উপরে ও একটু বড় করে দেখাবে
    `is_active`  TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order` INT          NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_active_order` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- সেকশনের সেটিংস (অ্যাডমিন → সাইট সেটিংস → "ফেসবুক সেকশন" থেকে বদলানো যাবে)
-- ⚠️ শিরোনামের বাংলা ডিফল্ট এখানে **ইচ্ছাকৃতভাবে দেওয়া হয়নি** — SQL ফাইলে বাংলা টেক্সট
--    কিছু টুলে (যেমন Windows-এর mysql CLI) এনকোডিং নষ্ট করে ফেলে। খালি রাখা হলো, PHP-র
--    `get_setting('facebook_section_title') ?: 'আমাদের ফেসবুকে'` ফলব্যাক ডিফল্টটা দেখাবে,
--    আর অ্যাডমিন চাইলে সেটিংস পেজ থেকে নিজের শিরোনাম বসাতে পারবেন।
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('facebook_section_on',    '1'),
    ('facebook_section_title', ''),
    ('facebook_page_url',      ''),  -- পেজের লিংক দিলে "সর্বশেষ পোস্ট" টাইমলাইন অটো দেখাবে
    ('facebook_page_show',     '1');
