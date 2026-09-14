-- ============================================================
-- মাইগ্রেশন: রেজিস্ট্রেশন তালিকায় দ্রুত-সম্পাদনা ফিল্ড (বকেয়া + অ্যাডমিন নোট)
--
-- কনফার্ম করার সময় তালিকা থেকেই (ভেতরে না ঢুকে) লেখা যায়:
--   due_amount  — এখনো কত টাকা বাকি (শুধু ট্র্যাকিং/স্মরণ; 🔴 আয়ের হিসাব সম্পূর্ণ অস্পৃশ্য)
--   admin_note  — কুরিয়ার নোট (registration_courier_notes) ছাড়াও সাধারণ অ্যাডমিন মন্তব্য
--
-- গ্রুপে যোগ হয়েছে কিনা (fb_group_added / messenger_group_added) কলাম দুটো আগে থেকেই আছে
-- (migrate-course-tracking.sql) — এখানে শুধু একই কলাম তালিকা থেকে টিক দেওয়ার সুবিধা যোগ হয়েছে,
-- নতুন কলাম লাগেনি।
--
-- non-destructive, idempotent (MariaDB)। লাইভ ও লোকাল দুই জায়গায় phpMyAdmin-এ চালাতে হবে।
-- ============================================================

ALTER TABLE registrations
    ADD COLUMN IF NOT EXISTS due_amount DECIMAL(10,2) NOT NULL DEFAULT 0    AFTER income_amount,
    ADD COLUMN IF NOT EXISTS admin_note VARCHAR(500)           DEFAULT NULL AFTER due_amount;
