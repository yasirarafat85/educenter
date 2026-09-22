-- ============================================================
-- মাইগ্রেশন: আয়ের হিসাব পেমেন্ট খাতার উপর সরানো — ধাপ ২
--
-- 🔴 নিয়ম বদলাচ্ছে: আগে "কনফার্ম করলেই price × পরিমাণ একবার আয়",
-- এখন **আয় = খাতায় যত টাকা জমা পড়েছে** (নগদ-ভিত্তিক; ইউজারের সিদ্ধান্ত ২০২৬-০৯-২২)।
--
-- এই স্ক্রিপ্টের কাজ শুধু একটাই: যেসব রেজিস্ট্রেশনের আয় ইতিমধ্যে অনুমোদিত অথচ খাতা নেই,
-- তাদের জন্য ঠিক সেই পরিমাণের একটা "পুরনো হিসাব" (legacy) সারি বসানো —
-- ✅ ফলে **মোট আয়ের সংখ্যা অপরিবর্তিত থাকে**, শুধু উৎসটা খাতায় সরে আসে।
--
-- ⚠️ label ইচ্ছাকৃতভাবে খালি ('') — SQL-এ বাংলা লিখলে কিছু টুলে mojibake হয় (CLAUDE.md নিয়ম);
-- বাংলা লেবেল PHP বসায় (pay_fetch_many() → 'পুরনো হিসাব')।
--
-- ⚠️ যেসব রেজিস্ট্রেশনে আপনি ইতিমধ্যে নিজে খাতা বানিয়েছেন, সেগুলো এখানে বাদ যায় (NOT EXISTS) —
-- তাদের আয় এখন থেকে খাতার জমা অনুযায়ী হবে, অর্থাৎ পুরনো সংখ্যা থেকে বদলাতে পারে। এটাই উদ্দেশ্য।
--
-- 🔴 চালানোর আগে DB ব্যাকআপ নিন। non-destructive, idempotent (দুইবার চালালেও ডুপ্লিকেট হয় না)।
-- ⚠️ আগে migrate-payment-ledger.sql (ধাপ ১) চালানো থাকতে হবে।
-- ============================================================

INSERT INTO registration_payments
    (registration_id, seq, kind, label, amount_due, discount_type, discount_value, discount_amount, amount_paid, paid_at)
SELECT
    r.id, 1, 'legacy', '',
    COALESCE(r.income_amount, 0), 'fixed', 0, 0,
    COALESCE(r.income_amount, 0),
    DATE(COALESCE(r.approved_at, r.created_at))
FROM registrations r
WHERE r.income_approved = 1
  AND COALESCE(r.income_amount, 0) > 0
  AND NOT EXISTS (SELECT 1 FROM registration_payments p WHERE p.registration_id = r.id);

-- যাচাই (চালিয়ে দেখুন — দুটো সংখ্যা মিলতে হবে):
--   SELECT COALESCE(SUM(amount),0) AS আয়ের_বই FROM income;
--   SELECT COALESCE(SUM(amount_paid),0) AS খাতার_জমা FROM registration_payments p
--     JOIN registrations r ON r.id = p.registration_id
--     WHERE r.status IN ('confirmed','shipped','delivered');
