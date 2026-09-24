-- ============================================================
-- মাইগ্রেশন: আগ্রহ ফর্মে (course_interests) তিনটা নতুন ঘর
--
-- সমস্যা (ইউজার, ২০২৬-০৯-২৪): আগ্রহ জানানোর সুযোগ ছিল শুধু **বন্ধ** কোর্সে।
-- চলমান কোর্সেও অনেকে আগ্রহী কিন্তু এখন ভর্তি হতে পারেন না — কারও শিশুর বয়স কম,
-- কেউ এখন ব্যস্ত। তাঁদের ধরে রাখার কোনো উপায় ছিল না।
--
-- শুধু "আগ্রহী" জানলে কাজে লাগে না — পরে কাকে ফোন দেবেন সেটা বুঝতে দরকার:
--   child_dob  : শিশুর জন্ম তারিখ। 🔑 "বয়স কম" লিড বয়স না জানলে অকেজো —
--                জন্ম তারিখ থাকলে ছয় মাস পরে ছোটদের ব্যাচ খুললে ফিল্টার করে
--                ঠিক যাদের বয়স হয়ে গেছে তাদেরই ডাকা যায় (বয়স অটো হিসাব হয়)।
--   reason     : কেন এখন পারছেন না — age / busy / money / just_looking / other
--   start_when : কবে নাগাদ শুরু করতে চান — now / 1_3m / 6m / unsure
--
-- 🔴 লেবেলের বাংলা লেখা ইচ্ছাকৃতভাবে SQL-এ নেই (mojibake এড়াতে) — PHP-র
--    interest_reasons()/interest_timeframes() হেল্পারে আছে (CLAUDE.md-এর নিয়ম)।
--
-- non-destructive, idempotent (MariaDB)। লাইভ ও লোকাল দুই জায়গায় চালাতে হবে।
-- ============================================================

ALTER TABLE course_interests
    ADD COLUMN IF NOT EXISTS child_dob  DATE        DEFAULT NULL   AFTER child_name,
    ADD COLUMN IF NOT EXISTS reason     VARCHAR(20) NOT NULL DEFAULT '' AFTER remarks,
    ADD COLUMN IF NOT EXISTS start_when VARCHAR(20) NOT NULL DEFAULT '' AFTER reason;
