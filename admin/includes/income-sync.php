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

/* ============================================================================
 * 💰💰 টাকা জমা নেওয়ার **একটাই দরজা** — `pay_collect()`  (২০২৬-১০-১৫, ধাপ ২)
 * ============================================================================
 *
 * এর আগে ঠিক একই ৭ ধাপ **তিন জায়গায় আলাদা করে** লেখা ছিল:
 *   • `admin/registrations.php`   → `action=pay-collect`  (ড্রয়ারের "নতুন টাকা জমা নিন")
 *   • `admin/course-parcel.php`   → `cp_collect_payment()` ("💰 টাকা মিলিয়ে নিন")
 *   • `admin/payment-inbox.php`   → `action=claim-post`    ("💰 খাতায় বসান")
 * তিনটেই হুবহু এক নিয়ম, তাই একটায় কিছু বদলালে বাকি দুটোয় বদলাতে ভুলে যাওয়ার
 * ঝুঁকি ছিল — আর টাকার নিয়ম দুই জায়গায় দু-রকম হলে সেটা **নীরবে** ভুল হয়।
 * এখন তিনটেই এই ফাংশনটাই ডাকে।
 *
 * 🔴🔴 ক্রমটা কখনো বদলাবেন না:
 *      pay_ensure_plan() → pay_apply_payment() → pay_save_rows()
 *                        → due_amount ডিনরমালাইজ → **তারপর** sync_income_for_status()
 *      সিঙ্ক আগে করলে এইমাত্র বসানো জমাটা বইয়ে যেত না।
 *
 * 🔴🔴 `pay_allocate_paid()` এখানে কখনো নয় — ওটা "মোট এতটুকু জমা" ধরে **সব সারি নতুন
 *      করে** বসায়, অর্থাৎ অ্যাডমিনের হাতে বসানো কিস্তির জমা মুছে যেত।
 *      `pay_apply_payment()` শুধু **যোগ** করে (advance শেষ সারিতে বসে, ⊘ বাদ সারিতে কিছুই নয়)।
 *
 * 🔴 এটা **"যোগ করা"** (incremental), **"বসিয়ে দেওয়া" (absolute) নয়** — এই পার্থক্যটা
 *    জরুরি। `admin/income-fix.php` কাজটা করে উল্টো অর্থে ("আসলে কত টাকা এসেছে" =
 *    মোট), তাই সেটা ইচ্ছাকৃতভাবে এই দরজা দিয়ে যায় না; গেলে `pay_ensure_plan()`-এর
 *    prefill-এর উপরে আবার যোগ হয়ে **অর্থ দ্বিগুণ** হয়ে যেত (orphan দলে income_approved=1)।
 *
 * 🔴 কলার নিজে ট্রানজ্যাকশন খোলে — এই ফাংশন খোলে না, commit/rollback-ও করে না।
 *    `ok => false` ফিরলে কলার **অবশ্যই rollback করবে** (টাকার হিসাব নীরবে সরতে পারে না)।
 * 🔴 অঙ্কের সীমা কলারের (প্রতিটার নিজের ধ্রুবক ও নিজের বার্তা আছে) — এখানে শুধু
 *    শেষ রক্ষাকবচ হিসেবে ০/ঋণাত্মক আটকানো হয়।
 * 🔴 `$reg` অ্যারেটা কলার যেভাবে দেয় সেভাবেই ব্যবহার হয় — অর্থাৎ স্ট্যাটাস বদলাতে
 *    হলে (claim-post-এর pending → confirmed) কলার **এই ফাংশন ডাকার আগে** একই
 *    ট্রানজ্যাকশনের ভেতরে বদলে নেবে, তাহলে সিঙ্ক নতুন স্ট্যাটাসই দেখবে।
 *
 * @param string $source  কোন পাতা থেকে — শুধু **ভুল হলে কী করতে হবে** সেই ইঙ্গিতটা
 *                        বাছতে ব্যবহার হয়; DB-তে এর কোনো প্রভাব নেই।
 * @return array{ok:bool, code:string, err:string, before:float, paid:float, balance:float}
 */
function pay_collect(PDO $db, array $reg, float $amount, string $source = ''): array
{
    $regId = (int) ($reg['id'] ?? 0);
    $fail  = fn(string $code, string $err, float $before = 0.0, float $paid = 0.0): array
        => ['ok' => false, 'code' => $code, 'err' => $err, 'before' => $before, 'paid' => $paid, 'balance' => 0.0];

    if ($regId <= 0) {
        return $fail('noreg', 'অর্ডারটি পাওয়া যায়নি।');
    }
    // শেষ রক্ষাকবচ — কলারের নিজের গার্ড আগেই থাকে
    if (!($amount > 0)) {
        return $fail('amount', 'জমার অঙ্কটা ঠিক নেই।');
    }

    // খাতা না থাকলে ব্যাচের সেটিংস দেখে কিস্তিগুলো বসে (জমা ০); থাকলে কখনো ছোঁয়া হয় না
    pay_ensure_plan($db, $reg);

    $rows = pay_fetch_many($db, [$regId])[$regId] ?? [];
    if (!$rows) {
        return $fail('noplan', pay_collect_noplan_hint($source));
    }
    $before = pay_paid_total($rows);

    pay_save_rows($db, $regId, pay_apply_payment($rows, $amount));

    // 🔴 নিরাপত্তা-জাল: মোট জমা ঠিক এই অঙ্কটাই না বাড়লে কলার পুরোটা বাতিল করবে
    $after = pay_fetch_many($db, [$regId])[$regId] ?? [];
    $paid  = pay_paid_total($after);
    if (abs($paid - ($before + $amount)) > 0.05) {
        return $fail(
            'mismatch',
            'মোট জমা মিলছিল না (৳' . number_format($before, 2) . ' → ৳' . number_format($paid, 2) . ')',
            $before,
            $paid
        );
    }

    // বাকি টাকা registrations.due_amount-এ (খাতাই আসল উৎস, এটা দ্রুত রেফারেন্স)
    $summary = pay_summary($after);
    $db->prepare('UPDATE registrations SET due_amount = :due WHERE id = :id')
        ->execute(['due' => $summary['balance'], 'id' => $regId]);

    // 🔴 সিঙ্ক সবসময় খাতা লেখার **পরে**
    sync_income_for_status($db, $reg, (string) ($reg['status'] ?? ''));

    return [
        'ok'      => true,
        'code'    => '',
        'err'     => '',
        'before'  => $before,
        'paid'    => $paid,
        'balance' => (float) $summary['balance'],
    ];
}

// খাতা বসানোই গেল না — কোন পাতা থেকে এসেছে সেই অনুযায়ী "এখন কী করুন" ইঙ্গিত।
// 🔴 প্রতিটা পাতার নিজের বোতামের নামই বলা হয় (সাধারণ বার্তা দিলে অ্যাডমিন কোথায়
//    যাবেন বুঝতেন না)। DB-তে এর কোনো প্রভাব নেই।
function pay_collect_noplan_hint(string $source): string
{
    switch ($source) {
        case 'parcel':
            return 'কিস্তির ছক বসানো যায়নি — উপরের "📒 সবার খাতা বসান" একবার চাপুন';
        case 'claim':
            return 'এই অর্ডারের খাতা তৈরি করা যায়নি — অর্ডারে গিয়ে "টাকা" চিপে একবার সেভ করে দেখুন।';
        case 'drawer':
        default:
            return 'এই অর্ডারে কিস্তির ছক বসানো যায়নি — নিচের খাতা থেকে একবার "সংরক্ষণ করুন" চাপুন।';
    }
}
