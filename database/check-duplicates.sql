-- ============================================================
-- 🔍 ডুপ্লিকেট আছে কিনা দেখা (অডিট D1 + D2, ২০২৬-১১-১৪)
--
-- 🔴 এই ফাইলে শুধু SELECT — কিছুই বদলায় না, মোছেও না। নিশ্চিন্তে চালান।
--
-- phpMyAdmin → ডাটাবেস বেছে → SQL ট্যাব → নিচের দুটো কোয়েরি পেস্ট করে Go।
--   ক) কোনো সারি না এলে ("MySQL returned an empty result set") = সব ঠিক আছে।
--   খ) সারি এলে = ডুপ্লিকেট আছে। তখন migrate-unique-income.sql চালাবেন না —
--      ফলাফলের স্ক্রিনশট পাঠান, ঠিকভাবে মিলিয়ে দেওয়া হবে।
-- ============================================================

-- ১) এক অর্ডারে একাধিক আয়ের সারি (হলে বইয়ে আয় দুইবার গোনা হচ্ছে)
SELECT registration_id, COUNT(*) AS rows_count, SUM(amount) AS total_amount, GROUP_CONCAT(id ORDER BY id) AS income_ids
FROM income
WHERE registration_id IS NOT NULL
GROUP BY registration_id
HAVING COUNT(*) > 1;

-- ২) কোর্স পার্সেলে এক শিক্ষার্থীর একই মাসে একাধিক পার্সেল
--    (খালি লেবেলের সারি বাদ — ওগুলো "কুরিয়ার" পাতা থেকে বানানো আলাদা চালান, সেটা স্বাভাবিক)
SELECT registration_id, period_label, COUNT(*) AS rows_count, GROUP_CONCAT(CONCAT(id, ':', send_status) ORDER BY id) AS batch_ids
FROM courier_batches
WHERE period_label <> ''
GROUP BY registration_id, period_label
HAVING COUNT(*) > 1;
