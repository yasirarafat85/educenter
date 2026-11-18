-- ফেসবুক উত্তর-ভান্ডার: প্রশ্নোত্তর কোথায় ব্যবহার হবে (২০২৬-১১-১৮)
-- 'message' = শুধু ইনবক্স · 'comment' = শুধু কমেন্ট · 'both' = দুই জায়গায়
-- আগে শেখা সব উত্তর 'both' থাকে — আচরণ কিছুই বদলায় না। ডকুমেন্টে এই ঘর ব্যবহার হয় না।
ALTER TABLE meta_kb ADD COLUMN channel VARCHAR(8) NOT NULL DEFAULT 'both' AFTER answer;
