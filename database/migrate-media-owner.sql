-- ওয়ার্কশিট ও প্রোডাক্টেও ছবি/ভিডিও (২০২৬-০৯-২৬)
-- course_media এতদিন শুধু কোর্স-ব্যাচের সাথে বাঁধা ছিল (batch_id)। এখন যেকোনো
-- আইটেমের (course / worksheet / product) ছবি-ভিডিও একই টেবিলে রাখা যায়।
--
-- 🔴 সম্পূর্ণ non-destructive: পুরনো কোনো সারি মোছা/বদলানো হয় না, FK-ও ড্রপ করা হয় না।
--    কোর্সের সারিতে batch_id আগের মতোই থাকে (তাই ব্যাচ ডিলিটে CASCADE কাজ করে);
--    ওয়ার্কশিট/প্রোডাক্টের সারিতে batch_id NULL থাকে (NULL কখনো FK ভাঙে না)।
--
-- ⚠️ phpMyAdmin-এ লাইভ ও লোকাল — দুই জায়গাতেই একবার চালাতে হবে।

ALTER TABLE course_media
    ADD COLUMN owner_type VARCHAR(20) NOT NULL DEFAULT 'course' AFTER id,
    ADD COLUMN owner_id   INT UNSIGNED NOT NULL DEFAULT 0       AFTER owner_type;

-- পুরনো সব সারি কোর্সেরই — batch_id-টাই owner_id
UPDATE course_media SET owner_type = 'course', owner_id = batch_id WHERE owner_id = 0;

-- ওয়ার্কশিট/প্রোডাক্টের সারিতে batch_id লাগবে না
ALTER TABLE course_media MODIFY batch_id INT UNSIGNED NULL;

ALTER TABLE course_media ADD INDEX idx_cm_owner (owner_type, owner_id, sort_order);
