<?php
// ── আয়ের হিসাব: স্ট্যাটাস + টাকার খাতা → `income` টেবিল (শেয়ার্ড হেল্পার)
//
// 🔴🔴 টাকা জড়িত — খুব সাবধানে ছোঁবেন। আগে এই দুটো ফাংশন `admin/registrations.php`-এর
//    ভেতরে ছিল; ২০২৬-০৯-২৯-এ এখানে সরানো হয়েছে যাতে **"আয় মেলানো" পেজও (admin/income-fix.php)
//    হুবহু একই কোড ব্যবহার করে** — আয় বসানোর নিয়ম দুই জায়গায় দুই রকম হয়ে যেতে পারে না।
//    ফাইলটা শুধু অ্যাডমিন পেজ থেকে require করবেন (পাবলিক পেজ থেকে নয়)।
//
// নির্ভরতা: `admin/includes/payments.php` (pay_fetch_many/pay_paid_total) এবং
//           `includes/functions.php` (find_or_create_finance_category / registration_type_to_category_name)।

require_once __DIR__ . '/payments.php';

// যে স্ট্যাটাসগুলোতে থাকলে অর্ডারটা আয় হিসেবে গণনা হবে — এর বাইরে গেলে আয় সরে যাবে
const INCOME_STATUSES = ['confirmed', 'shipped', 'delivered'];

// স্ট্যাটাস অনুযায়ী আয় অটোমেটিক যোগ/বাদ দেওয়া — status 'confirmed'/'shipped'/'delivered' হলে আয়, নাহলে আয় বাদ
// 🔴🔴 খাতা-ভিত্তিক আয় (ধাপ ২, ২০২৬-০৯-২২) — আয় = **যতটুকু টাকা আসলে হাতে এসেছে**
// (ইউজারের স্পষ্ট সিদ্ধান্ত: নগদ-ভিত্তিক, প্রাপ্য/accrual নয়)। স্ট্যাটাসও গেট হিসেবে থাকে —
// বাতিল/পেন্ডিং হলে আয় বইয়ে থাকে না, টাকা জমা থাকলেও।
// registration-প্রতি **একটাই** income রো রাখা হয়; পরিমাণ বদলালে সেটাই আপডেট হয় (তারিখ অক্ষত)।
function pay_write_income(PDO $db, array $reg, string $newStatus, float $paid): void
{
    $id = (int) $reg['id'];
    $amount = in_array($newStatus, INCOME_STATUSES, true) ? round(max(0.0, $paid), 2) : 0.0;

    $cur = $db->prepare('SELECT id FROM income WHERE registration_id = :id ORDER BY id LIMIT 1');
    $cur->execute(['id' => $id]);
    $incomeId = (int) ($cur->fetchColumn() ?: 0);

    if ($amount <= 0) {
        // টাকা নেই / স্ট্যাটাস আয়-যোগ্য না → আয়ের রো সরে যায়।
        // ⚠️ income_amount ইচ্ছাকৃতভাবে মোছা হয় না ("সর্বশেষ জানা পরিমাণ", CLAUDE.md নিয়ম)।
        $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $id]);
        $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')
            ->execute(['id' => $id]);
        return;
    }

    if ($incomeId > 0) {
        // তারিখ অপরিবর্তিত রাখা হয় — নাহলে প্রতিবার সেভে আয় আজকের তারিখে সরে যেত
        $db->prepare('UPDATE income SET amount = :amt WHERE id = :iid')
            ->execute(['amt' => $amount, 'iid' => $incomeId]);
    } else {
        $db->prepare(
            'INSERT INTO income (category_id, registration_id, amount, description, income_date) VALUES (:cat, :reg, :amt, :desc, CURDATE())'
        )->execute([
            'cat'  => find_or_create_finance_category('income', registration_type_to_category_name($reg['type'])),
            'reg'  => $id,
            'amt'  => $amount,
            'desc' => $reg['item_title'] . ' - ' . $reg['customer_name'],
        ]);
    }
    $db->prepare('UPDATE registrations SET income_approved = 1, income_amount = :amt, approved_at = NOW() WHERE id = :id')
        ->execute(['amt' => $amount, 'id' => $id]);
}

function sync_income_for_status(PDO $db, array $reg, string $newStatus): void
{
    // 🔴 আয় সবসময় টাকার খাতা থেকে — খাতা থাকলে আয় = মোট জমা (ধাপ ২)।
    // খাতা না থাকলে নতুন আয় **তৈরি হয় না**, শুধু স্ট্যাটাস-গেট মানা হয় (নিচে দেখুন)।
    $ledger = pay_fetch_many($db, [(int) $reg['id']])[(int) $reg['id']] ?? [];
    if ($ledger) {
        pay_write_income($db, $reg, $newStatus, pay_paid_total($ledger));
        return;
    }

    // 🔴🔴 খাতাহীন রেজিস্ট্রেশনে কনফার্ম করলে আর **অটো আয় বসে না** (২০২৬-০৯-২৯, ইউজারের ধরা বাগ)।
    //
    // আগে এখানে আয় = আইটেমের **বর্তমান দাম** × পরিমাণ বসত। কিন্তু "কনফার্ম" মানে ভর্তি নিশ্চিত,
    // "পুরো টাকা হাতে এসেছে" নয় — বাস্তবে অভিভাবক তখন হয়তো শুধু রেজিস্ট্রেশন ফি ৳500 দিয়েছেন,
    // অথচ ব্যাচের `price` (মাসিক বেতন ৳790) আয় হিসেবে বসে যেত। তারপর টাকার খাতা খুললে ঐ ভুল
    // অঙ্কটাই `reg_pay_panel()`-এর `$prefill` হয়ে জমার ঘরে ছড়িয়ে পড়ত (৳500 রেজি ফি পুরো +
    // ৳290 বেতনে আংশিক) — ইউজারের স্ক্রিনশটে ঠিক এটাই ধরা পড়ে; কে কখন কনফার্ম হয়েছে তার
    // উপর নির্ভর করে একেকজনের একেক অঙ্ক দাঁড়াত (৳790 / ৳500 / ০)।
    //
    // **এখন আয়ের একমাত্র উৎস টাকার খাতার মোট জমা** — নগদ-ভিত্তিক নীতি, যেটা ২০২৬-০৯-২২ এ
    // ধাপ ২-তেই ঠিক হয়েছিল; এই শাখাটাই ছিল তার শেষ ব্যতিক্রম।
    //
    // 🔴 আগে অনুমোদিত আয় এখানে **ছোঁয়া হয় না** — পুরনো (খাতাহীন) রেজিস্ট্রেশনের বইয়ের অঙ্ক
    //    যেমন ছিল তেমনই থাকে। `reg_pay_panel()`-এর `$prefill`-ও তাই রাখতেই হবে, নাহলে ঐ
    //    খাতা প্রথমবার সেভ করলে আয় নীরবে ০ হয়ে যেত।
    $shouldHaveIncome = in_array($newStatus, INCOME_STATUSES, true);

    if (!$shouldHaveIncome && $reg['income_approved']) {
        $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $reg['id']]);
        // ⚠️ income_amount ইচ্ছাকৃতভাবে **মোছা হয় না** (আগে NULL করা হতো) — এটা "সর্বশেষ জানা পরিমাণ"
        // হিসেবে থেকে যায়, যাতে আইটেম ডিলিট হয়ে গেলেও পরে আবার কনফার্ম করলে আয় ঠিক পরিমাণে ফিরে আসে।
        // প্রদর্শনে কোনো প্রভাব নেই — তালিকা ও ডিটেইল দুই জায়গাতেই income_approved দেখে দেখানো হয়।
        $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')
            ->execute(['id' => $reg['id']]);
    }
}
