-- ফেসবুক ইনবক্স: কমেন্টকারীকে ইনবক্সে মেসেজ (Private Reply) — কখন ও কে পাঠাল (২০২৬-১১-১৮)
-- ফেসবুক একটা কমেন্টে একবারই ইনবক্সে মেসেজ পাঠাতে দেয়; এই ঘর দুটো সেটা মনে রাখে।
-- না চালালেও পাঠানো চলে — শুধু "আগেই পাঠানো হয়েছে" চিহ্নটা দেখায় না।
ALTER TABLE meta_comments ADD COLUMN private_reply_at DATETIME NULL AFTER replied_at;
ALTER TABLE meta_comments ADD COLUMN private_reply_by VARCHAR(100) NULL AFTER private_reply_at;
