-- ============================================================
-- 📝 "কার্ডে যা দেখাবে" ঘর (card_excerpt) — ২০২৬-০৯-২৭
--
-- কী: কোর্স তালিকা/হোমপেজ/সার্চের কার্ডে নামের নিচে যে দুই লাইন দেখায়, সেটা এতদিন
--     "বিবরণ"-এর শুরু থেকে অটো নেওয়া হতো। বিবরণ সালাম/ভূমিকা দিয়ে শুরু হলে কার্ডে
--     ঐটাই দেখাত। এখন অ্যাডমিন চাইলে আলাদা করে সারকথা লিখতে পারবেন।
--
-- খালি রাখলে আচরণ হুবহু আগের মতোই — তাই পুরনো কোনো আইটেম বদলায় না।
--
-- ⚠️ এই ফাইলের SQL **লাইভ ও লোকাল দুই জায়গার** phpMyAdmin-এ একবার চালাতে হবে।
-- ⚠️ non-destructive: শুধু নতুন কলাম যোগ হয়, কোনো ডেটা মোছে/বদলায় না।
-- 🔴 SQL-এ বাংলা লেখা ইচ্ছাকৃতভাবে নেই (কিছু টুলে ডাবল-এনকোড হয়ে mojibake হয়) —
--    ডিফল্ট খালি স্ট্রিং, বাংলা লেবেল/হিন্ট সব PHP-তে।
-- ============================================================

ALTER TABLE `course_batches`
    ADD COLUMN `card_excerpt` VARCHAR(300) NOT NULL DEFAULT '' AFTER `description`;

ALTER TABLE `worksheets`
    ADD COLUMN `card_excerpt` VARCHAR(300) NOT NULL DEFAULT '' AFTER `description`;

ALTER TABLE `products`
    ADD COLUMN `card_excerpt` VARCHAR(300) NOT NULL DEFAULT '' AFTER `description`;
