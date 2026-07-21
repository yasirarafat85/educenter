-- পেমেন্ট মেথড টেবিল — রেজিস্ট্রেশন/অর্ডার সফল হওয়ার পর থ্যাংক-ইউ পেজে দেখানো পেমেন্ট নাম্বার ও
-- WhatsApp বাটন অ্যাডমিন থেকে নিয়ন্ত্রণের জন্য। প্রতিটা এন্ট্রির চ্যানেল (বিকাশ/নগদ/রকেট/ব্যাংক/WhatsApp),
-- এবং "সব আইটেমে" নাকি "নির্দিষ্ট কোর্স/আইটেমে" দেখাবে সেটা সেট করা যায়।
-- non-destructive: শুধু CREATE TABLE, লাইভ DB তে নিরাপদে চালানো যায়।

CREATE TABLE IF NOT EXISTS `payment_methods` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `channel` VARCHAR(20) NOT NULL DEFAULT 'bkash',   -- bkash / nagad / rocket / bank / whatsapp / other
    `value` VARCHAR(150) NOT NULL,                     -- নাম্বার অথবা অ্যাকাউন্ট
    `instruction` VARCHAR(255) DEFAULT NULL,           -- নির্দেশনা/নোট (যেমন: Send Money, Personal — Merchant না)
    `scope_all` TINYINT(1) NOT NULL DEFAULT 1,         -- 1 = সব আইটেমে দেখাবে; 0 = শুধু নির্দিষ্ট আইটেমে
    `scope_items` TEXT DEFAULT NULL,                   -- scope_all=0 হলে JSON টোকেন লিস্ট: ["course:5","product:2","worksheet:3"]
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_active_sort` (`is_active`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
