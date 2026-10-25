-- ============================================================
-- মাইগ্রেশন: গ্রুপ-টিক কে/কখন দিয়েছে (মডারেটর ট্র্যাকিং, ২০২৬-১০-২৫)
--
-- মডারেটরকে "গ্রুপে যোগ টিক" কাজ দেওয়া হলে — কে টিক দিল ও কখন, সেটা সারিতেই
-- দেখানোর জন্য ৪টা কলাম। fb_group_added / messenger_group_added আগে থেকেই আছে;
-- এই কলামগুলো শুধু "কে/কখন" রাখে।
--
-- 🔴 সব কোডে db_has_column() গার্ড আছে — এই SQL না চালালেও সব আগের মতো চলবে,
--    শুধু "কে টিক দিয়েছে" সারিতে দেখাবে না।
-- 🔴 এই SQL একাধিকবার চালালে "Duplicate column" এরর দেবে (ক্ষতি নেই) — একবারই চালান।
-- ============================================================

ALTER TABLE registrations ADD COLUMN fb_group_by VARCHAR(50) NOT NULL DEFAULT '';     -- ফেসবুক গ্রুপ টিক যে দিয়েছে (অ্যাডমিন/মডারেটরের ইউজারনেম)
ALTER TABLE registrations ADD COLUMN fb_group_at DATETIME NULL;                       -- ফেসবুক গ্রুপ টিক কখন দেওয়া হলো
ALTER TABLE registrations ADD COLUMN msgr_group_by VARCHAR(50) NOT NULL DEFAULT '';   -- মেসেঞ্জার গ্রুপ টিক যে দিয়েছে
ALTER TABLE registrations ADD COLUMN msgr_group_at DATETIME NULL;                     -- মেসেঞ্জার গ্রুপ টিক কখন দেওয়া হলো
