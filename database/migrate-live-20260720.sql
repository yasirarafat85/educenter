-- ============================================================================
--  EduCenter — লাইভ সার্ভারের জন্য একত্রিত মাইগ্রেশন (২০২৬-০৭-২০)
-- ============================================================================
--  ২০২৬-০৭-১৫ এর লাইভ ডিপ্লয়ের পর যা যা DB পরিবর্তন হয়েছে তার সবগুলো এক ফাইলে:
--    • archived_items                     (আর্কাইভ/রিসাইকেল বিন)
--    • registrations.courier_active       (কুরিয়ারে যাবে কিনা টগল)
--    • courier_batches (+ ৫টা হিসাব-ভাঙা কলাম)
--    • courier_shipments.batch_id
--    • courier_note_types, registration_courier_notes  (রঙিন নোট)
--    • কুরিয়ার প্রিসেট সেটিংস (ডেলিভারি চার্জ/ওজন)
--
--  ⚠️ এটা সম্পূর্ণ NON-DESTRUCTIVE ও IDEMPOTENT — কোনো টেবিল/কলাম আগে থেকে
--     থাকলে সেটা চুপচাপ বাদ দিয়ে যায়, কিছু মোছে না, কোনো ডেটা বদলায় না।
--     **একাধিকবার চালালেও কোনো সমস্যা হবে না** — তাই কোনটা আগে চালিয়েছেন
--     মনে না থাকলেও নিশ্চিন্তে চালাতে পারেন।
--
--  প্রয়োগ: cPanel → phpMyAdmin → বাঁ পাশে আপনার ডাটাবেস (shishur1_...) সিলেক্ট
--          করুন → উপরে "Import" ট্যাব → এই ফাইলটা Choose File → Go।
--          (চালানোর সময় কিছু "skip" লেখা ছোট রেজাল্ট দেখাবে — এটা স্বাভাবিক,
--           মানে ঐ কলামটা আগে থেকেই ছিল।)
-- ============================================================================


-- ────────────────────────────────────────────────────────────────────────────
-- ১) আর্কাইভ/রিস্টোর — ডিলিট করা কনটেন্ট JSON বান্ডল হিসেবে জমা থাকে
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `archived_items` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(40)  NOT NULL,
    `original_id` INT UNSIGNED NOT NULL,
    `label`       VARCHAR(255) DEFAULT NULL,
    `data_json`   LONGTEXT     NOT NULL,
    `deleted_at`  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_entity` (`entity_type`),
    KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────────────────
-- ২) কুরিয়ার ব্যাচ টেবিল (একটা রেজিস্ট্রেশন থেকে একাধিক মাসের চালান)
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `courier_batches` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `registration_id`           INT UNSIGNED NOT NULL,
    `period_label`              VARCHAR(100) NOT NULL DEFAULT '',
    `recipient_name`            VARCHAR(150),
    `recipient_phone`           VARCHAR(30),
    `recipient_secondary_phone` VARCHAR(30),
    `recipient_address`         VARCHAR(500),
    `item_description`          VARCHAR(255),
    `item_quantity`             INT UNSIGNED DEFAULT 1,
    `item_weight`               DECIMAL(5,2) DEFAULT 0.5,
    `item_type`                 TINYINT UNSIGNED DEFAULT 2,
    `delivery_type`             TINYINT UNSIGNED DEFAULT 48,
    `special_instruction`       VARCHAR(500),
    `amount_to_collect`         DECIMAL(10,2) DEFAULT 0,
    `courier_provider`          VARCHAR(50),
    `courier_consignment_id`    VARCHAR(100),
    `tracking_url`              VARCHAR(500),
    `delivery_fee`              DECIMAL(10,2) NULL,
    `send_status`               VARCHAR(20) NOT NULL DEFAULT 'draft',
    `sent_at`                   TIMESTAMP NULL,
    `created_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`registration_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
    INDEX `idx_registration` (`registration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────────────────
-- ৩) রঙিন নোট টেবিল (নোট-টাইপ আগে, কারণ পরেরটা এটাকে রেফার করে)
-- ────────────────────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `courier_note_types` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `label`      VARCHAR(80) NOT NULL,
    `color`      VARCHAR(20) NOT NULL DEFAULT 'amber',
    `sort_order` INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `registration_courier_notes` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `registration_id` INT UNSIGNED NOT NULL,
    `note_type_id`    INT UNSIGNED NULL,
    `custom_text`     VARCHAR(120) DEFAULT NULL,
    `color`           VARCHAR(20)  DEFAULT NULL,
    FOREIGN KEY (`registration_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`note_type_id`)    REFERENCES `courier_note_types`(`id`) ON DELETE SET NULL,
    INDEX `idx_reg` (`registration_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ────────────────────────────────────────────────────────────────────────────
-- ৪) কলাম যোগ — প্রতিটার আগে "আছে কিনা" চেক করা হয় (তাই বারবার চালানো নিরাপদ)
-- ────────────────────────────────────────────────────────────────────────────

-- registrations.courier_active
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'registrations' AND COLUMN_NAME = 'courier_active');
SET @s := IF(@x = 0,
    'ALTER TABLE registrations ADD COLUMN courier_active TINYINT(1) NOT NULL DEFAULT 1',
    'SELECT ''skip: registrations.courier_active'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_shipments.batch_id
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_shipments' AND COLUMN_NAME = 'batch_id');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_shipments ADD COLUMN batch_id INT UNSIGNED NULL AFTER registration_id,
       ADD FOREIGN KEY (batch_id) REFERENCES courier_batches(id) ON DELETE CASCADE',
    'SELECT ''skip: courier_shipments.batch_id'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_batches.monthly_multiplier
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_batches' AND COLUMN_NAME = 'monthly_multiplier');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_batches ADD COLUMN monthly_multiplier DECIMAL(3,1) NOT NULL DEFAULT 1 AFTER amount_to_collect',
    'SELECT ''skip: courier_batches.monthly_multiplier'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_batches.delivery_zone
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_batches' AND COLUMN_NAME = 'delivery_zone');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_batches ADD COLUMN delivery_zone VARCHAR(20) DEFAULT ''dhaka'' AFTER monthly_multiplier',
    'SELECT ''skip: courier_batches.delivery_zone'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_batches.weight_extra
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_batches' AND COLUMN_NAME = 'weight_extra');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_batches ADD COLUMN weight_extra TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_zone',
    'SELECT ''skip: courier_batches.weight_extra'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_batches.adjustment
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_batches' AND COLUMN_NAME = 'adjustment');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_batches ADD COLUMN adjustment DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER weight_extra',
    'SELECT ''skip: courier_batches.adjustment'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- courier_batches.adjustment_reason
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courier_batches' AND COLUMN_NAME = 'adjustment_reason');
SET @s := IF(@x = 0,
    'ALTER TABLE courier_batches ADD COLUMN adjustment_reason VARCHAR(60) DEFAULT NULL AFTER adjustment',
    'SELECT ''skip: courier_batches.adjustment_reason'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;


-- ────────────────────────────────────────────────────────────────────────────
-- ৫) কুরিয়ার প্রিসেট (ডেলিভারি চার্জ/ওজন) — আগে সেট করা থাকলে অপরিবর্তিত থাকে
--    পরে অ্যাডমিন → সাইট সেটিংস → "কুরিয়ার প্রিসেট" থেকে বদলানো যাবে
-- ────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('courier_dc_dhaka',     '60'),
    ('courier_dc_near',      '80'),
    ('courier_dc_outside',   '120'),
    ('courier_weight_extra', '20');


-- ============================================================================
--  শেষ। এখন অ্যাডমিন প্যানেলে ঢুকে দেখুন — সাইডবারে "পার্সেল প্রস্তুত",
--  "কুরিয়ার ট্র্যাকিং", "কুরিয়ার নোট-টাইপ" ও "আর্কাইভ" মেনু আসার কথা।
-- ============================================================================
