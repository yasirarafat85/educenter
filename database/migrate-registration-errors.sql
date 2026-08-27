-- ============================================================
-- মাইগ্রেশন: রেজিস্ট্রেশন এরর লগ — পাবলিক ফর্মে (কোর্স/অর্ডার/আগ্রহ) কেউ আটকালে কারণসহ লগ হয়।
-- অ্যাডমিন দেখে বুঝবেন আসল কাস্টমাররা কোথায়/কেন আটকাচ্ছে। শুধু ডায়াগনস্টিক — অন্য লজিক ছোঁয় না।
-- পুরনো রেকর্ড অটো-পরিষ্কার (৬০ দিনের বেশি) কোডে; অ্যাডমিনে "সব মুছুন"ও আছে। non-destructive, idempotent।
-- ============================================================
CREATE TABLE IF NOT EXISTS registration_errors (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    form_type     VARCHAR(20)  NOT NULL DEFAULT '',   -- course / order / interest
    error_message VARCHAR(255) NOT NULL DEFAULT '',
    entered_name  VARCHAR(191) NOT NULL DEFAULT '',
    entered_phone VARCHAR(30)  NOT NULL DEFAULT '',
    item_title    VARCHAR(191) NOT NULL DEFAULT '',
    ip_address    VARCHAR(45)  NOT NULL DEFAULT '',
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_re_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
