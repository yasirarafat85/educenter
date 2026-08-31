-- ─────────────────────────────────────────────────────────────
-- প্রোডাক্ট ও ওয়ার্কশিটে ডিসকাউন্ট (কাটা দাম) — ২০২৬-০৮-৩১
-- old_price = আগের/মূল (বেশি) দাম; price = বর্তমান (কম) বিক্রয়মূল্য (অপরিবর্তিত, আয়ে ব্যবহৃত)।
-- old_price খালি বা price-এর সমান/কম হলে ডিসকাউন্ট দেখাবে না। non-destructive।
-- লাইভ+লোকাল phpMyAdmin-এ চালাতে হবে।
-- ─────────────────────────────────────────────────────────────
ALTER TABLE products   ADD COLUMN old_price VARCHAR(50) NULL DEFAULT NULL AFTER price;
ALTER TABLE worksheets ADD COLUMN old_price VARCHAR(50) NULL DEFAULT NULL AFTER price;
