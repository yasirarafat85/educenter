-- ============================================================
-- মাইগ্রেশন: পেমেন্ট খাতা (কিস্তি-ভিত্তিক টাকা আদায়) — ধাপ ১
--
-- কেন: এক রেজিস্ট্রেশনে একাধিকবার টাকা আসে (রেজি ফি + প্রতি মাসের বেতন), কার কত
-- ছাড় দেওয়া হয়েছে ও কে কত দিয়েছে তা রাখার জায়গা ছিল না।
--
-- 🔴 ধাপ ১-এ আয়ের হিসাব (income টেবিল / sync_income_for_status) একদমই ছোঁয়া হয়নি —
-- এই খাতা এখন শুধু ট্র্যাকিং। আয়ের নিয়ম সরানো হবে ধাপ ২-এ, আলাদা মাইগ্রেশনে।
--
-- non-destructive, idempotent (MariaDB)। লাইভ ও লোকাল দুই জায়গায় চালাতে হবে।
-- ============================================================

-- অংশ ক: ব্যাচের ফি'র গঠন — কিস্তি অটো তৈরির ভিত্তি
--   reg_monthly = রেজিস্ট্রেশন ফি + মাসিক বেতন
--   monthly     = শুধু মাসিক বেতন
--   onetime     = এক-কালীন পুরো ফি
--   ''          = সেট করা নেই → কোড নিজে আন্দাজ করবে (pay_guess_fee_mode)
ALTER TABLE course_batches
    ADD COLUMN IF NOT EXISTS fee_mode VARCHAR(20) NOT NULL DEFAULT '' AFTER payment_schedule;

-- অংশ খ: খাতা — প্রতি রেজিস্ট্রেশনে এক বা একাধিক কিস্তির সারি
CREATE TABLE IF NOT EXISTS registration_payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    seq             INT          NOT NULL DEFAULT 0,        -- ক্রম (১, ২, ৩...)
    kind            VARCHAR(20)  NOT NULL DEFAULT 'monthly', -- registration / monthly / onetime / other / legacy
    label           VARCHAR(100) NOT NULL DEFAULT '',        -- "রেজিস্ট্রেশন ফি" / "১ম মাস" (বাংলা লেখা PHP বসায়, SQL-এ নয়)
    amount_due      DECIMAL(10,2) NOT NULL DEFAULT 0,        -- প্রাপ্য (স্ন্যাপশট — ব্যাচের দাম পরে বদলালেও অক্ষত)
    discount_type   VARCHAR(10)  NOT NULL DEFAULT 'fixed',   -- fixed (৳) / percent (%)
    discount_value  DECIMAL(10,2) NOT NULL DEFAULT 0,        -- অ্যাডমিন যা টাইপ করেছেন
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,        -- হিসাব করা ছাড় (টাকায় জমাট — পরে দাম বদলালেও নড়বে না)
    amount_paid     DECIMAL(10,2) NOT NULL DEFAULT 0,        -- জমা
    paid_at         DATE         DEFAULT NULL,
    method          VARCHAR(30)  NOT NULL DEFAULT '',        -- bkash / nagad / courier / cash ...
    note            VARCHAR(255) DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    INDEX idx_rp_reg (registration_id),
    INDEX idx_rp_seq (registration_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
