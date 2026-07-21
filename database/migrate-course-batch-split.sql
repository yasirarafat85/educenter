-- কোর্স ↔ ব্যাচ ডেটা মডেল আলাদা করার মাইগ্রেশন (parent/child পুনর্গঠন)
-- চালানোর আগে অবশ্যই real DB এর ব্যাকআপ নিন (mysqldump)।
-- এই স্ক্রিপ্ট non-destructive (RENAME/ALTER/CREATE — কোনো DROP TABLE বা ডেটা মোছা নেই)।
-- একবার educenter_test এ ফুল টেস্ট করার পরই real educenter DB তে চালানো উচিত।

-- ধাপ ১: পুরনো flat courses টেবিলকে course_batches নামে রিনেম — সব ডেটা/id/FK (course_features সহ)
-- স্বয়ংক্রিয়ভাবে নতুন নামে বজায় থাকে, registrations.item_id এর কোনো রিম্যাপিং লাগে না।
RENAME TABLE `courses` TO `course_batches`;

-- ধাপ ২: batch কলাম রিনেম করে batch_name + NOT NULL (real ডেটায় সব রো তে ভরা আছে, যাচাই করা হয়েছে)
ALTER TABLE `course_batches` CHANGE COLUMN `batch` `batch_name` VARCHAR(100) NOT NULL;

-- ধাপ ৩: নতুন হালকা parent টেবিল
CREATE TABLE `courses` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `title` (`title`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ধাপ ৪: ডিস্টিংক্ট টাইটেল থেকে parent রো তৈরি — একই টাইটেলের একাধিক ব্যাচ-রো একটাই parent এ merge হবে
INSERT INTO `courses` (`title`, `sort_order`)
SELECT `title`, MIN(`sort_order`) FROM `course_batches` GROUP BY `title`;

-- ধাপ ৫: প্রতিটা ব্যাচকে তার parent course_id দিয়ে লিংক করা, তারপর পুরনো title কলাম বাদ,
-- course_id NOT NULL + FK + UNIQUE(course_id, batch_name) যোগ
ALTER TABLE `course_batches` ADD COLUMN `course_id` INT UNSIGNED NULL AFTER `id`;

UPDATE `course_batches` cb
JOIN `courses` c ON c.`title` = cb.`title`
SET cb.`course_id` = c.`id`;

ALTER TABLE `course_batches`
    DROP COLUMN `title`,
    MODIFY COLUMN `course_id` INT UNSIGNED NOT NULL,
    ADD CONSTRAINT `fk_course_batches_course` FOREIGN KEY (`course_id`) REFERENCES `courses` (`id`) ON DELETE CASCADE,
    ADD UNIQUE KEY `uq_course_batch` (`course_id`, `batch_name`);

-- ধাপ ৬: course_features.course_id রিনেম করে batch_id — FK টার্গেট এখন course_batches
ALTER TABLE `course_features` DROP FOREIGN KEY `course_features_ibfk_1`;
ALTER TABLE `course_features` CHANGE COLUMN `course_id` `batch_id` INT UNSIGNED NOT NULL;
ALTER TABLE `course_features`
    ADD CONSTRAINT `fk_course_features_batch` FOREIGN KEY (`batch_id`) REFERENCES `course_batches` (`id`) ON DELETE CASCADE;
