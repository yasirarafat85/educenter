-- ============================================================================
--  ফেসবুক সেকশন — এমবেড (iframe) বাদ দিয়ে সুন্দর কার্ড (২০২৬-০৭-২০)
-- ============================================================================
--  কারণ: ফেসবুকের iframe এমবেড অনেক পোস্টে কাজ করে না ("refused to connect"),
--  রিল/ভিডিও তো সাপোর্টই করে না, আর প্রতিটা এমবেডে ~৮৬ KB আসে (সাইট ধীর হয়)।
--  এখন অ্যাডমিনের দেওয়া তথ্য দিয়ে নিজেদের কার্ড বানানো হয় — ক্লিকে ফেসবুকে যায়
--  (মোবাইলে ফেসবুক অ্যাপে)। সব ধরনের পোস্টেই কাজ করে।
--
--  ⚠️ NON-DESTRUCTIVE ও IDEMPOTENT — একাধিকবার চালালেও সমস্যা নেই।
--  প্রয়োগ: phpMyAdmin → আপনার DB সিলেক্ট → Import → এই ফাইল → Go
-- ============================================================================

-- social_posts.image (কার্ডের ছবি — ঐচ্ছিক)
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'image');
SET @s := IF(@x = 0,
    'ALTER TABLE social_posts ADD COLUMN image VARCHAR(255) DEFAULT NULL AFTER title',
    'SELECT ''skip: social_posts.image'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- social_posts.excerpt (ছোট বর্ণনা — ঐচ্ছিক)
SET @x := (SELECT COUNT(*) FROM information_schema.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'social_posts' AND COLUMN_NAME = 'excerpt');
SET @s := IF(@x = 0,
    'ALTER TABLE social_posts ADD COLUMN excerpt VARCHAR(300) DEFAULT NULL AFTER image',
    'SELECT ''skip: social_posts.excerpt'' AS status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
