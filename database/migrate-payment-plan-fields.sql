-- ============================================================
-- মাইগ্রেশন: খাতার কিস্তি অটো তৈরির জন্য পরিষ্কার ঘর — ধাপ ২.১
--
-- সমস্যা যা ধরা পড়েছিল (ইউজারের স্ক্রিনশট, ২০২৬-০৯-২২):
--  (ক) "রেজিস্ট্রেশন ফি" সারিতে প্রাপ্য ০ আসছিল — কারণ খাতা `secondary_fee` পড়ত,
--      যেটা ডিসপ্লে-only টেক্সট ("৳৩৫০" ধরনের) এবং প্রায়ই খালি থাকে।
--  (খ) ৩/৪ মাসের কোর্সেও শুধু "১ম মাস" আসছিল — কারণ `total_parcels` ০ ছিল
--      (ওটা পার্সেলের সংখ্যা, কোর্সের মাস নয় — দুটো সবসময় এক না)।
--
-- সমাধান: হিসাবের জন্য দুটো ডেডিকেটেড সংখ্যার ঘর। খালি থাকলে কোড আগের উৎস থেকেই
-- আন্দাজ করে (secondary_fee / total_parcels / duration) — কিছু ভাঙে না।
--
-- registration_payments.is_skipped: ৪ মাসের কোর্সে কেউ ২ মাস পরে বাদ দিলে বাকি মাসগুলো
-- "বাদ" মার্ক করা যায় — তখন ঐ কিস্তি প্রাপ্য/বাকির হিসাবে ধরা হয় না।
--
-- non-destructive, idempotent (MariaDB)। লাইভ ও লোকাল দুই জায়গায় চালাতে হবে।
-- ============================================================

ALTER TABLE course_batches
    ADD COLUMN IF NOT EXISTS registration_fee DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER secondary_fee,
    ADD COLUMN IF NOT EXISTS course_months    INT           NOT NULL DEFAULT 0 AFTER registration_fee;

ALTER TABLE registration_payments
    ADD COLUMN IF NOT EXISTS is_skipped TINYINT(1) NOT NULL DEFAULT 0 AFTER amount_paid;
