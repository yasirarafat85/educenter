<?php
// 💸 ছাড়/মাফ → খরচের বই (২০২৬-০৯-৩০, ইউজারের চাওয়া)
//
// কাউকে ডেলিভারি চার্জ মাফ করা হলে বা কোনো ছাড় দেওয়া হলে সেটা প্রতিষ্ঠানের **আসল খরচ**।
// অ্যাডমিন কোর্স পার্সেলের কার্ডে অঙ্কটা লেখেন — তখন (ক) কালেকশন থেকে ঐ টাকা বাদ যায়,
// আর (খ) এই ফাইলটা `expenses`-এ ঠিক একটা সারি বসায়/হালনাগাদ করে।
//
// 🔴🔴 নীতি: **অঙ্ক ০ (বা খালি) করলে সারিটা মুছে যায়** — ইউজারের স্পষ্ট চাওয়া
//    ("আগে দেয়া থাকলে ০ করলে যেন আবার খরচ না হয়")। তাই উৎসটাই সত্য, বইয়ের সারিটা ছায়া।
//
// 🔴 হাতে লেখা খরচ কখনো ছোঁয়া হয় না — অটো সারিগুলো চেনা যায় `source = 'parcel_waiver'`
//    দিয়ে (হাতে লেখা সব সারিতে `source` খালি স্ট্রিং)। খোঁজার চাবি = source + registration_id
//    + period_label, তাই এক (শিক্ষার্থী, মাস)-এ একটাই সারি থাকে।
//
// 🔴 টেবিলে কলামগুলো না থাকলে (মাইগ্রেশন এখনো চালানো হয়নি) সব ফাংশন **চুপচাপ বাদ যায়** —
//    এই প্রজেক্টে ফাইল আগে ডিপ্লয় হয়, SQL ইউজার পরে চালান। এই ফলব্যাক সরাবেন না।
//
// মাইগ্রেশন: database/migrate-parcel-waiver.sql

const EXPENSE_SOURCE_PARCEL = 'parcel_waiver';

// খরচের ক্যাটেগরি — বাংলা লেখাটা **PHP-তে** (SQL মাইগ্রেশনে বাংলা দিলে mojibake হয়, CLAUDE.md নিয়ম)
const EXPENSE_WAIVER_CATEGORY = 'ছাড় / মাফ';

/**
 * মাইগ্রেশন চালানো হয়েছে কিনা — একবার দেখে static ক্যাশে রাখে।
 * 🔴 দুটো টেবিলই দেখা হয়: অঙ্কটা courier_batches-এ জমা থাকে, সারিটা expenses-এ।
 */
function expense_waiver_ready(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $db = $db ?? get_db();
    $ok = db_has_column($db, 'courier_batches', 'waived_amount')
       && db_has_column($db, 'expenses', 'registration_id')
       && db_has_column($db, 'expenses', 'source');
    return $ok;
}

/**
 * এক (রেজিস্ট্রেশন, মাস)-এর ছাড়ের খরচ-সারি মিলিয়ে দেয়।
 *
 * @param float  $amount  ছাড়ের পরিমাণ (০ বা তার কম = সারিটা মুছে ফেলা হবে)
 * @param string $reason  অ্যাডমিনের লেখা কারণ (ঐচ্ছিক)
 * @param string $who     কার — বইয়ে পড়ার মতো বিবরণ বানাতে
 * @return string  '' | 'added' | 'updated' | 'removed'  (ফ্ল্যাশ বার্তার জন্য)
 */
function expense_sync_parcel_waiver(PDO $db, int $regId, string $period, float $amount, string $reason, string $who): string
{
    if ($regId <= 0 || $period === '' || !expense_waiver_ready($db)) { return ''; }
    $amount = round($amount, 2);

    try {
        $find = $db->prepare(
            'SELECT id, amount FROM expenses
             WHERE source = :s AND registration_id = :r AND period_label = :p
             ORDER BY id ASC LIMIT 1'
        );
        $find->execute(['s' => EXPENSE_SOURCE_PARCEL, 'r' => $regId, 'p' => $period]);
        $row = $find->fetch();

        // ── ০ = ছাড় তুলে নেওয়া হলো → বইয়ের সারিটাও চলে যাবে
        if ($amount < 0.01) {
            if ($row) {
                $db->prepare('DELETE FROM expenses WHERE id = :id')->execute(['id' => (int) $row['id']]);
                return 'removed';
            }
            return '';
        }

        $who    = trim($who);
        $reason = trim($reason);
        $desc   = 'পার্সেল ছাড়/মাফ — ' . ($who !== '' ? $who : ('রেজি #' . $regId)) . ' · ' . $period
                . ($reason !== '' ? ' · ' . $reason : '');
        $desc   = mb_substr($desc, 0, 255);

        if ($row) {
            // 🔴 expense_date ইচ্ছাকৃতভাবে বদলানো হয় না — নাহলে প্রতিবার সেভে খরচটা
            //    আজকের তারিখে সরে যেত (income-এর `income_date` নিয়ে একই নিয়ম)।
            $db->prepare('UPDATE expenses SET amount = :a, description = :d WHERE id = :id')
               ->execute(['a' => $amount, 'd' => $desc, 'id' => (int) $row['id']]);
            return 'updated';
        }

        $catId = find_or_create_finance_category('expense', EXPENSE_WAIVER_CATEGORY);
        $db->prepare(
            'INSERT INTO expenses (category_id, registration_id, period_label, source, amount, description, expense_date)
             VALUES (:c, :r, :p, :s, :a, :d, :dt)'
        )->execute([
            'c' => $catId, 'r' => $regId, 'p' => $period, 's' => EXPENSE_SOURCE_PARCEL,
            'a' => $amount, 'd' => $desc, 'dt' => date('Y-m-d'),
        ]);
        return 'added';
    } catch (PDOException $ex) {
        return '';   // বই লিখতে না পারলেও পার্সেল সেভ করা কখনো আটকাবে না
    }
}
