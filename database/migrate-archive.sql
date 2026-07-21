-- আর্কাইভ/রিস্টোর সিস্টেম — ডিলিট করা কনটেন্ট (রো + সব child) JSON বান্ডল হিসেবে জমা রাখে,
-- প্রয়োজনে হুবহু (আসল id সহ) ফিরিয়ে আনা যায়। non-destructive: শুধু একটা নতুন টেবিল যোগ করে।
-- প্রয়োগ: phpMyAdmin এ আপনার DB সিলেক্ট করে Import, অথবা এই SQL চালান।

CREATE TABLE IF NOT EXISTS `archived_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(40)  NOT NULL,               -- courses / course_batches / products / worksheets / ...
    `original_id` INT UNSIGNED NOT NULL,               -- আসল রো-এর id (রিস্টোরে এই id-তেই ফেরে)
    `label`       VARCHAR(255) DEFAULT NULL,           -- তালিকায় দেখানোর নাম (title/name)
    `data_json`   LONGTEXT     NOT NULL,               -- রো + সব child-এর nested বান্ডল (JSON)
    `deleted_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_entity` (`entity_type`),
    KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
