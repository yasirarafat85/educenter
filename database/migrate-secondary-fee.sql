-- ============================================================
-- মাইগ্রেশন: কোর্স-ব্যাচে দ্বিতীয় ফি (রেজিস্ট্রেশন/উপকরণ ফি) — মূল/মাসিক ফি থেকে আলাদা করে দেখানোর জন্য।
-- শুধু ডিসপ্লে (কার্ড + রেজিস্ট্রেশন পেজ) — আয়/কালেকশন হিসাব ছোঁয় না। non-destructive, idempotent।
-- বাংলা ডিফল্ট লেখা নেই (mojibake এড়াতে — CLAUDE.md নিয়ম); লেবেল অ্যাডমিন নিজে লেখেন।
-- ============================================================
ALTER TABLE course_batches
    ADD COLUMN IF NOT EXISTS secondary_fee_label VARCHAR(100) NOT NULL DEFAULT '' AFTER price,
    ADD COLUMN IF NOT EXISTS secondary_fee       VARCHAR(50)  NOT NULL DEFAULT '' AFTER secondary_fee_label;
