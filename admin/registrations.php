<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/courier-notes.php';
require_once __DIR__ . '/includes/payments.php';
// আয়ের হিসাব (INCOME_STATUSES / pay_write_income / sync_income_for_status) — শেয়ার্ড,
// কারণ "আয় মেলানো" পেজ (income-fix.php) হুবহু এই নিয়মেই আয় বসায়
require_once __DIR__ . '/includes/income-sync.php';
require_once __DIR__ . '/../includes/bd-districts.php';
admin_require_login();

$db = get_db();
$pageTitle = 'রেজিস্ট্রেশন/অর্ডার';
$action = $_GET['action'] ?? 'list';

$statusLabels = [
    'pending'   => ['পেন্ডিং', 'bg-yellow-100 text-yellow-800'],
    'confirmed' => ['কনফার্ম', 'bg-blue-100 text-blue-800'],
    'shipped'   => ['পাঠানো হয়েছে', 'bg-purple-100 text-purple-800'],
    'delivered' => ['ডেলিভার্ড', 'bg-green-100 text-green-800'],
    'cancelled' => ['বাতিল', 'bg-red-100 text-red-800'],
];
$typeLabels = ['course' => 'কোর্স', 'worksheet' => 'ওয়ার্কশিট', 'product' => 'প্রোডাক্ট'];

// 💰 ড্রয়ারের "নতুন টাকা জমা নিন" ঘরের টাইপো-গার্ড (income-fix.php-এর IFX_MAX_AMOUNT-এর মতোই —
// ফোন নম্বর বা তারিখ ভুলে বসিয়ে ফেলা আটকায়)
const REG_PAY_MAX_ADD = 1000000;

// 💰 "বাকি আছে" ফিল্টারের শর্ত — **একটাই জায়গায় লেখা ধ্রুবক**, কারণ উপরের সারাংশ
// কার্ডের গণনা থেকে ঠিক এই শর্তটাই বাদ দিতে হয় (স্ট্যাটাসের মতোই — কার্ডটাই টগল,
// তাই টগল করলে তার নিজের সংখ্যা বদলানো চলবে না)।
// 🔴 উৎস `registrations.due_amount` — খাতা (`registration_payments`) থেকে
//    ডিনরমালাইজড; হাতে লেখা নয়। ০.০০৯ ক্ল্যাম্পটা পয়সার গোলমাল এড়াতে।
// 🔴 বাতিল অর্ডার ইচ্ছাকৃতভাবে বাদ — বাতিল করলে আয় সরে যায় কিন্তু খাতার সারিগুলো
//    (আর তাই `due_amount`-ও) থেকে যায়; বাদ না দিলে বাতিল হয়ে যাওয়া শিক্ষার্থীকে
//    টাকার জন্য তাড়া দিতে বলা হতো।
// 🔴 বন্ধনীসহ — শর্তটা ভেতরে `AND` রাখে, আর স্ট্রিংটা অন্য শর্তের সাথে জোড়া লাগে।
const REG_DUE_WHERE = "(COALESCE(due_amount, 0) > 0.009 AND status <> 'cancelled')";

// ফর্ম থেকে পাঠানো return_url শুধুমাত্র চেনা অ্যাডমিন পেজের মধ্যেই থাকা নিশ্চিত করা (open-redirect ঠেকাতে)
// course-data.php ও এখান থেকে (একই action=delete/update-details ব্যবহার করে) রিটার্ন করতে পারে
function safe_return_url(?string $url, string $fallback): string
{
    // 🔴 কন্ট্রোল-ক্যারেক্টার ছেঁটে ফেলা হয় — মানটা POST থেকে আসে আর সরাসরি
    //    `Location:` হেডারে বসে (হেডার-ইনজেকশন গার্ড, `nav_safe_return()`-এর মতোই)।
    $url = $url === null ? null : preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    // 🔴 হোয়াইটলিস্ট — শুধু এই কয়টা পাতা (খোলা রিডাইরেক্ট গার্ড)। `month-work.php`
    //    যোগ হয়েছে ২০২৬-১০-১৫-তে: "এই মাসের কাজ" পাতার "জমা নিন" বোতাম এখানকার
    //    `pay-collect` হ্যান্ডলারেই POST করে, আর কাজ শেষে ওখানেই ফেরত যাওয়া দরকার।
    $allowedPrefixes = ['registrations.php', 'course-data.php', 'month-work.php'];
    foreach ($allowedPrefixes as $prefix) {
        if ($url && strpos($url, $prefix) === 0) {
            return $url;
        }
    }
    return $fallback;
}

/**
 * তালিকার URL-এ `#reg-<id>` নোঙর বসায় — কনফার্ম/সেভের পর ব্রাউজার ঠিক ঐ সারিতেই ফেরে।
 *
 * 🔴 কেন দরকার (২০২৬-০৯-২৯, ইউজারের ধরা সমস্যা): স্ট্যাটাস বদলালে POST → redirect হয়,
 *    ব্রাউজার নতুন পাতা **উপর থেকে** দেখায়। ফোনে পরপর কয়েকজনকে কনফার্ম করতে গেলে
 *    প্রতিবার আবার নিচে স্ক্রল করতে হতো। নোঙর থাকলে ঐ সারিতেই নেমে আসে।
 * ⚠️ আগের fragment বাদ দেওয়া হয় — নাহলে `#reg-5#reg-7` জমতে থাকত।
 */
function reg_anchor_url(string $url, int $id): string
{
    if ($id <= 0) {
        return $url;
    }
    $hash = strpos($url, '#');
    if ($hash !== false) {
        $url = substr($url, 0, $hash);
    }
    return $url . '#reg-' . $id;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-status') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (isset($statusLabels[$status])) {
        $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $reg = $stmt->fetch();

        if ($reg) {
            $db->prepare('UPDATE registrations SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);

            /* 📒 কনফার্ম (বা shipped/delivered) করলে খাতা **নিজে থেকেই** বসে যায় — জমা ০।
             * আগে খাতা বসত শুধু ড্রয়ার খুলে হাতে, তাই বেশির ভাগ অর্ডারে খাতাই থাকত না আর
             * পার্সেলের কালেকশন ফ্ল্যাট মাসিক ফি ধরে নিত। 🔴 আয় নড়ে না — বিস্তারিত
             * `pay_ensure_plan()`-এর ঘরে। ব্যর্থ হলে (মাইগ্রেশন চলেনি) চুপচাপ বাদ,
             * স্ট্যাটাস বদলানোটা কখনো আটকায় না। */
            $madeLedger = 0;
            if (in_array($status, INCOME_STATUSES, true)) {
                try {
                    $madeLedger = pay_ensure_plan($db, $reg);
                } catch (PDOException $ex) {
                    $madeLedger = 0;
                }
            }

            // 🔴 খাতা বসানোর **পরে** সিঙ্ক — নাহলে এইমাত্র বসানো জমা (prefill) বইয়ে যেত না
            sync_income_for_status($db, $reg, $status);

            // 🔔 খাতা আছে কিন্তু জমা এখনো ০ — অ্যাডমিনকে মনে করিয়ে দেওয়া হয়, নাহলে
            //    "কনফার্ম করলাম, আয়ে কিছু এল না কেন" বলে মনে হতো।
            $needsMoney = in_array($status, INCOME_STATUSES, true)
                && pay_paid_total(pay_fetch_many($db, [$id])[$id] ?? []) < 0.01;
            set_flash('success', 'স্ট্যাটাস আপডেট হয়েছে।'
                . ($madeLedger > 0 ? ' 📒 কোর্সের সেটিংস দেখে ' . $madeLedger . ' টি কিস্তির খাতা বসানো হয়েছে।' : '')
                . ($needsMoney ? ' 💰 কত টাকা হাতে এসেছে সেটা "টাকা" চিপে ক্লিক করে বসিয়ে সেভ করুন — আয় ওখান থেকেই বইয়ে যাবে।' : ''));
        }
    }
    redirect(reg_anchor_url($returnUrl, $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'unapprove-income') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $id]);
    // income_amount রেখে দেওয়া হয় ("সর্বশেষ জানা পরিমাণ") — উপরের sync_income_for_status() এর একই কারণে
    $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')->execute(['id' => $id]);

    set_flash('success', 'আয় থেকে বাদ দেওয়া হয়েছে।');
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-income-amount') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);

    if ($amount <= 0) {
        set_flash('error', 'সঠিক পরিমাণ দিন।');
        redirect($returnUrl);
    }

    $db->prepare('UPDATE income SET amount = :amt WHERE registration_id = :id')->execute(['amt' => $amount, 'id' => $id]);
    $db->prepare('UPDATE registrations SET income_amount = :amt WHERE id = :id')->execute(['amt' => $amount, 'id' => $id]);

    set_flash('success', 'আয়ের পরিমাণ আপডেট করা হয়েছে।');
    redirect($returnUrl);
}

// ─────────────────────────────────────────────────────────────
// তালিকা থেকেই দ্রুত সম্পাদনা (ভেতরে না ঢুকে) — কনফার্ম করার সময় যা যা লাগে:
//   quick-group  : ফেসবুক/মেসেঞ্জার গ্রুপে যোগ হয়েছে কিনা টিক (course-parcel.php-এর একই কলাম)
//   pay-save     : টাকার খাতা (কিস্তি/ছাড়/জমা) + অ্যাডমিন নোট
// 🔴 দুটোর কোনোটাই আয়ের হিসাব (income/income_amount/income_approved) ছোঁয় না — বকেয়া নিছক স্মরণ/ট্র্যাকিং।
// RBAC: action-মার্কার delete-তালিকায় নেই বলে কেন্দ্রীয় গার্ড (auth.php) এতে 'edit' ক্ষমতা চায় — ঠিক আছে।
// ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'quick-group') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    // হোয়াইটলিস্ট — কলামের নাম কখনো সরাসরি POST থেকে কুয়েরিতে বসানো হয় না
    $column = ($_POST['field'] ?? '') === 'messenger' ? 'messenger_group_added' : 'fb_group_added';
    $value = (int) ($_POST['value'] ?? 0) ? 1 : 0;

    $db->prepare("UPDATE registrations SET $column = :v WHERE id = :id")->execute(['v' => $value, 'id' => $id]);

    $label = $column === 'messenger_group_added' ? 'মেসেঞ্জার' : 'ফেসবুক';
    set_flash('success', $value ? ($label . ' গ্রুপে যোগ — টিক দেওয়া হলো।') : ($label . ' গ্রুপের টিক তুলে নেওয়া হলো।'));
    redirect(reg_anchor_url($returnUrl, $id));
}

// পুরনো (মাইগ্রেশনে বসানো "পুরনো হিসাব") খাতাকে কোর্সের বর্তমান সেটিংস দেখে
// কিস্তির ছকে সাজিয়ে দেওয়া — 🔴 মোট জমা অপরিবর্তিত, তাই আয়ের সংখ্যা নড়ে না।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay-rebuild') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $regStmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $regStmt->execute(['id' => $id]);
    $regRow = $regStmt->fetch();
    if (!$regRow) {
        set_flash('error', 'রেজিস্ট্রেশনটি পাওয়া যায়নি।');
        redirect($returnUrl);
    }

    try {
        $db->beginTransaction();
        $before = pay_paid_total(pay_fetch_many($db, [$id])[$id] ?? []);
        $count  = pay_rebuild_plan($db, $regRow);
        $rows   = pay_fetch_many($db, [$id])[$id] ?? [];
        $after  = pay_paid_total($rows);

        // 🔴 নিরাপত্তা-জাল: মোট জমা এক পয়সাও বদলালে পুরোটা বাতিল (আয় ভুল হতে দেওয়া যাবে না)
        if (round($before, 2) !== round($after, 2)) {
            $db->rollBack();
            set_flash('error', 'সাজানো বাতিল করা হলো — মোট জমা মিলছিল না (৳'
                . number_format($before, 2) . ' → ৳' . number_format($after, 2) . ')।');
            redirect($returnUrl);
        }

        $summary = pay_summary($rows);
        $db->prepare('UPDATE registrations SET due_amount = :due WHERE id = :id')
            ->execute(['due' => $summary['balance'], 'id' => $id]);
        sync_income_for_status($db, $regRow, $regRow['status']);
        $db->commit();

        set_flash('success', $count . ' টি কিস্তিতে সাজানো হলো — জমা ৳' . number_format($after, 2)
            . ' অপরিবর্তিত।' . ($summary['balance'] > 0 ? ' বাকি ৳' . number_format($summary['balance'], 2) : ' ✅ পুরো পেইড'));
    } catch (PDOException $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        set_flash('error', 'খাতা সাজানো যায়নি — ডাটাবেস মাইগ্রেশন চালানো আছে কিনা দেখুন।');
    }
    redirect(reg_anchor_url($returnUrl, $id));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay-save') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $note = trim((string) ($_POST['admin_note'] ?? ''));
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 500);
    }

    try {
        $db->beginTransaction();
        $saved = pay_save_rows($db, $id, is_array($_POST['p'] ?? null) ? $_POST['p'] : []);

        // বাকি টাকা registrations.due_amount-এ ডিনরমালাইজড রাখা (খাতাই আসল উৎস, এটা শুধু দ্রুত রেফারেন্স)
        $summary = pay_summary(pay_fetch_many($db, [$id])[$id] ?? []);
        $db->prepare('UPDATE registrations SET due_amount = :due, admin_note = :note WHERE id = :id')
            ->execute(['due' => $summary['balance'], 'note' => $note !== '' ? $note : null, 'id' => $id]);

        // 🔴 খাতা বদলেছে → আয়ও সাথে সাথে মিলিয়ে দেওয়া হয় (আয় = মোট জমা)
        $regStmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
        $regStmt->execute(['id' => $id]);
        if ($regRow = $regStmt->fetch()) {
            sync_income_for_status($db, $regRow, $regRow['status']);
        }

        $db->commit();
        set_flash('success', $saved . ' টি কিস্তি সংরক্ষণ করা হয়েছে।'
            . ($summary['balance'] > 0 ? ' বাকি ৳' . number_format($summary['balance'], 2) : ' — পুরো পেইড ✅'));
    } catch (PDOException $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        // টেবিল/কলাম এখনো তৈরি হয়নি — সাদা পেজ না দেখিয়ে কী করতে হবে বলে দেওয়া হয়
        set_flash('error', 'টাকার খাতা সংরক্ষণ করা যায়নি — ডাটাবেসে "registration_payments" টেবিলটি এখনো তৈরি হয়নি। '
            . 'phpMyAdmin-এ database/migrate-payment-ledger.sql ফাইলের SQL একবার চালিয়ে নিন।');
    }
    redirect(reg_anchor_url($returnUrl, $id));
}

// ── 💰 "নতুন টাকা জমা নিন" — ড্রয়ারের উপরের এক-ঘরের ফর্ম (২০২৬-১০-১৩, ধাপ ৪)।
//
// সবচেয়ে সাধারণ কাজটা (অভিভাবক টাকা দিলেন, খাতায় বসাতে হবে) আগে ৬টা সারি মিলিয়ে
// "সংরক্ষণ করুন" চাপা লাগত। এখন শুধু অঙ্কটা লিখলেই হয়।
//
// 🔴🔴 এটা `pay_apply_payment()` ব্যবহার করে, **কখনো `pay_allocate_paid()` নয়** —
//    allocate "মোট জমা এতটুকু" ধরে প্রতিটা সারি নতুন করে বসায়, অর্থাৎ চলতি খাতায় চালালে
//    অ্যাডমিনের হাতে বসানো কিস্তিগুলো মুছে যেত। apply শুধু **যোগ করে**: আগের জমা অক্ষত,
//    বাকি অংশে উপর থেকে নিচে, উদ্বৃত্ত (advance) শেষ কিস্তিতে, ⊘ বাদ সারিতে কিছু বসে না।
//    হুবহু একই পথ `admin/payment-inbox.php`-এর `claim-post`-এও (ধাপ ৩)।
// 🔴 খাতা না থাকলে `pay_ensure_plan()` আগে কিস্তিগুলো বসায় (আগে-অনুমোদিত আয়ের prefill সহ),
//    তারপর টাকা — আর আয় সিঙ্ক হয় **সবার শেষে** (আগে করলে এইমাত্র বসানো জমা বইয়ে যেত না)।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'pay-collect') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id     = (int) ($_POST['id'] ?? 0);
    $amount = round((float) str_replace(',', '', (string) ($_POST['amount'] ?? '')), 2);

    $regStmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $regStmt->execute(['id' => $id]);
    $regRow = $regStmt->fetch();
    if (!$regRow) {
        set_flash('error', 'রেজিস্ট্রেশনটি পাওয়া যায়নি।');
        redirect($returnUrl);
    }

    // 🔴 টাইপো-গার্ড: ০/ঋণাত্মক হলে কিছুই হয় না, অসম্ভব বড় অঙ্কও আটকায়
    if ($amount <= 0 || $amount > REG_PAY_MAX_ADD) {
        set_flash('error', 'জমার অঙ্কটা ঠিক নেই — ০-এর বেশি আর ৳'
            . number_format(REG_PAY_MAX_ADD) . '-এর মধ্যে একটা সংখ্যা লিখুন।');
        redirect(reg_anchor_url($returnUrl, $id));
    }

    try {
        $incBefore = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM income')->fetch()['s'] ?? 0);

        $db->beginTransaction();
        // 🔴 টাকা বসানোর একটাই দরজা — `pay_collect()` (admin/includes/income-sync.php)।
        //    ভেতরে হুবহু সেই প্রমাণিত পথ: pay_ensure_plan() → pay_apply_payment() →
        //    pay_save_rows() → due_amount → **তারপর** sync_income_for_status()।
        //    এখানে আলাদা করে ঐ ধাপগুলো আর লিখবেন না (course-parcel ও payment-inbox-ও
        //    এই একটাই ফাংশন ডাকে — নিয়ম দুই জায়গায় রাখা যাবে না)।
        $res = pay_collect($db, $regRow, $amount, 'drawer');
        if (!$res['ok']) {
            $db->rollBack();
            set_flash('error', $res['code'] === 'mismatch'
                ? 'টাকা বসানো বাতিল করা হলো — ' . $res['err'] . '।'
                : $res['err']);
            redirect(reg_anchor_url($returnUrl, $id));
        }
        $db->commit();

        $paidAfter = $res['paid'];
        $summary   = ['balance' => $res['balance']];
        $incAfter = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM income')->fetch()['s'] ?? 0);
        $msg = '💰 ৳' . number_format($amount, 2) . ' জমা বসানো হলো — এই অর্ডারে মোট জমা ৳'
            . number_format($paidAfter, 2) . '।'
            . ($summary['balance'] > 0 ? ' বাকি ৳' . number_format($summary['balance'], 2) : ' ✅ পুরো পেইড।')
            . ' বইয়ের মোট আয় ৳' . number_format($incBefore, 2) . ' → ৳' . number_format($incAfter, 2) . '।';
        // স্ট্যাটাস আয়-যোগ্য না হলে টাকা খাতায় বসে কিন্তু আয়ে যায় না — সেটা এখানেই বলে দেওয়া
        if (!in_array((string) $regRow['status'], INCOME_STATUSES, true)) {
            $msg .= ' ⚠️ অর্ডারের স্ট্যাটাস "' . ($statusLabels[$regRow['status']][0] ?? $regRow['status'])
                . '" — আয়ের বইয়ে যোগ হতে স্ট্যাটাস কনফার্ম করতে হবে।';
        }
        set_flash('success', $msg);
    } catch (PDOException $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        set_flash('error', 'টাকা বসানো যায়নি — ডাটাবেসে "registration_payments" টেবিলটি তৈরি আছে কিনা দেখুন '
            . '(phpMyAdmin-এ database/migrate-payment-ledger.sql একবার চালিয়ে নিন)।');
    }
    redirect(reg_anchor_url($returnUrl, $id));
}
// ------------------------------------------------------------
// 🔄 অন্য কোর্সে সরানো (২০২৬-০৯-২৮, ইউজার: "ভুলে এক কোর্সে রেজিস্ট্রেশন করেছে,
//    আমি তাকে অন্য কোর্সে দিতে চাই")
//
// 🔴 টাকার সাথে জড়িত — তাই যা বদলায় আর যা বদলায় না সেটা এখানে স্পষ্ট করে রাখা:
//    বদলায়   : item_id · item_title · batch (স্ন্যাপশট), আর চাইলে পেমেন্ট খাতার কিস্তির ছক
//    বদলায় না: **জমা দেওয়া টাকা** (কখনো নয়) · পাঠানো পার্সেল/চালান (courier_batches —
//               ইতিহাস, কুরিয়ারে চলে গেছে) · গ্রুপের টিক · নোট · অভিভাবকের অ্যাকাউন্ট
// 🔴 খাতা না থাকলে আয় = আইটেমের **বর্তমান দাম**, তাই কোর্স বদলালে আয়ের অঙ্কও বদলাতে পারে —
//    সেটা এখানেই (`sync_income_for_status()`) ঘটানো হয় ও ফ্ল্যাশে জানানো হয়, যাতে পরে
//    কোনো স্ট্যাটাস-সেভে নীরবে না বদলায়।
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'move-course') {
    $id      = (int) ($_POST['id'] ?? 0);
    $editUrl = 'registrations.php?action=edit&id=' . $id;

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($editUrl);
    }

    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $reg = $stmt->fetch();
    if (!$reg) {
        set_flash('error', 'রেজিস্ট্রেশনটি পাওয়া যায়নি।');
        redirect('registrations.php');
    }
    if ($reg['type'] !== 'course') {
        set_flash('error', 'শুধু কোর্সের রেজিস্ট্রেশন অন্য কোর্সে সরানো যায়।');
        redirect($editUrl);
    }

    // 🔴 নতুন ব্যাচ DB থেকেই যাচাই — POST-এর নাম/ফি-র উপর কখনো ভরসা নয়
    $newId = (int) ($_POST['new_item_id'] ?? 0);
    $tgt = $db->prepare(
        'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id
         WHERE cb.id = :id AND cb.is_active = 1'
    );
    $tgt->execute(['id' => $newId]);
    $target = $tgt->fetch();
    if (!$target) {
        set_flash('error', 'যে কোর্সে সরাতে চাইছেন সেটি পাওয়া গেল না (নিষ্ক্রিয় হয়ে থাকতে পারে)।');
        redirect($editUrl);
    }
    if ((int) $reg['item_id'] === $newId) {
        set_flash('error', 'এটি তো একই কোর্স-ব্যাচ — সরানোর কিছু নেই।');
        redirect($editUrl);
    }

    $oldLabel = trim((string) $reg['item_title'] . ($reg['batch'] ? ' (' . $reg['batch'] . ')' : ''));
    $newLabel = trim((string) $target['title'] . ($target['batch_name'] ? ' (' . $target['batch_name'] . ')' : ''));

    $hadLedger = (bool) (pay_fetch_many($db, [$id])[$id] ?? []);
    $doRebuild = $hadLedger && !empty($_POST['rebuild_ledger']);

    // সরানোর একটা চিহ্ন অ্যাডমিনের নোটে — কোথা থেকে এসেছে পরে যেন খুঁজে পাওয়া যায়।
    // 🔴 আগের লেখা কখনো মোছা হয় না, উপরে জোড়া লাগে (৫০০ অক্ষরের সীমা মেনে)।
    $trail = '🔄 আগে ছিল: ' . $oldLabel . ' — সরানো হয়েছে ' . date('d M Y');
    $note  = trim((string) ($reg['admin_note'] ?? ''));
    $note  = $note === '' ? $trail : $trail . "\n" . $note;
    if (function_exists('mb_substr')) {
        $note = mb_substr($note, 0, 500);
    }

    try {
        $db->beginTransaction();
        $paidBefore   = pay_paid_total(pay_fetch_many($db, [$id])[$id] ?? []);
        $incomeBefore = (float) ($reg['income_amount'] ?? 0);

        $db->prepare(
            'UPDATE registrations SET item_id = :item, item_title = :title, batch = :batch, admin_note = :note
             WHERE id = :id'
        )->execute([
            'item'  => $newId,
            'title' => $target['title'],
            'batch' => $target['batch_name'],
            'note'  => $note,
            'id'    => $id,
        ]);

        // হাতের কাছের কপিটাও আপডেট — নিচের pay_build_plan()/sync_income দুটোই item_id পড়ে
        $reg['item_id']    = $newId;
        $reg['item_title'] = $target['title'];
        $reg['batch']      = $target['batch_name'];
        $reg['admin_note'] = $note;

        if ($doRebuild) {
            pay_rebuild_plan($db, $reg);
            $rows = pay_fetch_many($db, [$id])[$id] ?? [];
            // 🔴 নিরাপত্তা-জাল (pay-rebuild হ্যান্ডলারের মতোই): মোট জমা এক পয়সাও বদলালে পুরোটা বাতিল
            if (round($paidBefore, 2) !== round(pay_paid_total($rows), 2)) {
                $db->rollBack();
                set_flash('error', 'সরানো বাতিল করা হলো — খাতার মোট জমা মিলছিল না, তাই কিছুই বদলানো হয়নি।');
                redirect($editUrl);
            }
            $db->prepare('UPDATE registrations SET due_amount = :due WHERE id = :id')
                ->execute(['due' => pay_summary($rows)['balance'], 'id' => $id]);
        }

        sync_income_for_status($db, $reg, $reg['status']);
        $db->commit();
    } catch (PDOException $ex) {
        if ($db->inTransaction()) { $db->rollBack(); }
        set_flash('error', 'সরানো যায়নি — আবার চেষ্টা করুন।');
        redirect($editUrl);
    }

    // আয় বদলেছে কিনা দেখে নেওয়া (খাতাহীন রেজিস্ট্রেশনে কোর্সের দাম বদলালে এটা বদলায়)
    $after = $db->prepare('SELECT income_amount, income_approved FROM registrations WHERE id = :id');
    $after->execute(['id' => $id]);
    $post = $after->fetch() ?: [];
    $incomeAfter = (float) ($post['income_amount'] ?? 0);

    $msg = '🔄 সরানো হলো: ' . $oldLabel . ' → ' . $newLabel . '।';
    // নতুন কোর্সে পার্সেল যায় অথচ এই অর্ডারে ঠিকানা নেই (আগে ফুল-অনলাইন কোর্স ছিল) —
    // কুরিয়ারে পাঠাতে গেলে আটকে যেত, তাই এখনই বলে দেওয়া
    if (empty($target['hide_parcel']) && trim((string) ($reg['address'] ?? '')) === '') {
        $msg .= ' 📦 নতুন কোর্সে পার্সেল যায়, কিন্তু এই অর্ডারে ঠিকানা নেই — "তথ্য সম্পাদনা" থেকে রিসিভারের নাম/নম্বর/ঠিকানা বসিয়ে দিন।';
    }
    if ($doRebuild) {
        $msg .= ' নতুন কোর্সের ফি অনুযায়ী খাতা সাজানো হয়েছে — জমা ৳' . number_format($paidBefore, 2) . ' অপরিবর্তিত।';
    } elseif ($hadLedger) {
        $msg .= ' ⚠️ খাতা আগের কোর্সের ফি ধরেই আছে — দরকার হলে "🧩 কিস্তির ছকে সাজান" চাপুন।';
    }
    if (!empty($post['income_approved']) && round($incomeBefore, 2) !== round($incomeAfter, 2)) {
        $msg .= ' 💰 আয় ৳' . number_format($incomeBefore, 2) . ' → ৳' . number_format($incomeAfter, 2) . '।';
    }
    set_flash('success', $msg);
    redirect('registrations.php?action=view&id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-details') {
    $id = (int) ($_POST['id'] ?? 0);
    $editUrl = 'registrations.php?action=edit&id=' . $id;
    $viewUrl = 'registrations.php?action=view&id=' . $id;

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($editUrl);
    }

    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $reg = $stmt->fetch();
    if (!$reg) {
        redirect('registrations.php');
    }

    $customerName = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($customerName === '') {
        set_flash('error', 'নাম দিন।');
        redirect($editUrl);
    }
    if (!is_valid_bd_phone($phone)) {
        set_flash('error', 'সঠিক মোবাইল নম্বর দিন (যেমন: 017xxxxxxxx)।');
        redirect($editUrl);
    }

    if ($reg['type'] === 'course') {
        $dob = trim($_POST['date_of_birth'] ?? '');
        $facebookId = trim($_POST['facebook_id'] ?? '');
        $fatherMobile = trim($_POST['father_mobile'] ?? '');

        $dobTimestamp = strtotime($dob);
        if (!$dob || !$dobTimestamp || $dobTimestamp > time()) {
            set_flash('error', 'সঠিক জন্ম তারিখ দিন।');
            redirect($editUrl);
        }
        if ($facebookId === '') {
            set_flash('error', 'ফেসবুক আইডি নাম দিন।');
            redirect($editUrl);
        }
        if ($fatherMobile !== '' && !is_valid_bd_phone($fatherMobile)) {
            set_flash('error', 'বাবার মোবাইল নম্বরটি সঠিক নয়।');
            redirect($editUrl);
        }

        // এই রেজিস্ট্রেশনে মূলত পার্সেল/ডেলিভারি তথ্য ছিল কিনা (hide_parcel কোর্সে থাকে না)
        $hasParcel = $reg['receiver_name'] !== null || $reg['receiver_phone'] !== null || $reg['address'] !== null;
        $receiverName = null;
        $receiverPhone = null;
        $address = null;
        if ($hasParcel) {
            $receiverName = trim($_POST['receiver_name'] ?? '');
            $receiverPhone = trim($_POST['receiver_phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            if ($receiverName === '') {
                set_flash('error', 'রিসিভারের নাম দিন।');
                redirect($editUrl);
            }
            if (!is_valid_bd_phone($receiverPhone)) {
                set_flash('error', 'রিসিভারের সঠিক মোবাইল নম্বর দিন।');
                redirect($editUrl);
            }
            if ($address === '') {
                set_flash('error', 'ঠিকানা দিন।');
                redirect($editUrl);
            }
        }

        $db->prepare(
            'UPDATE registrations SET customer_name = :name, phone = :phone, date_of_birth = :dob, facebook_id = :fb, father_mobile = :fm, receiver_name = :rn, receiver_phone = :rp, address = :addr, notes = :notes WHERE id = :id'
        )->execute([
            'name' => $customerName,
            'phone' => $phone,
            'dob' => date('Y-m-d', $dobTimestamp),
            'fb' => $facebookId,
            'fm' => $fatherMobile !== '' ? $fatherMobile : null,
            'rn' => $receiverName,
            'rp' => $receiverPhone,
            'addr' => $address,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $id,
        ]);
    } else {
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $thana = trim($_POST['thana'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'সঠিক ইমেইল ঠিকানা দিন।');
            redirect($editUrl);
        }
        if ($address === '') {
            set_flash('error', 'ঠিকানা দিন।');
            redirect($editUrl);
        }
        // জেলা/থানা এখন অর্ডার ফর্মে নেই (কাস্টমার পুরো ঠিকানায় লিখে দেন) — তাই এখানে ঐচ্ছিক, দিলে valid হতে হবে
        if ($district !== '' && !in_array($district, bd_districts(), true)) {
            set_flash('error', 'সঠিক জেলা নির্বাচন করুন।');
            redirect($editUrl);
        }

        $db->prepare(
            'UPDATE registrations SET customer_name = :name, phone = :phone, email = :email, address = :addr, district = :dist, thana = :thana, quantity = :qty, notes = :notes WHERE id = :id'
        )->execute([
            'name' => $customerName,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'addr' => $address,
            'dist' => $district !== '' ? $district : null,
            'thana' => $thana !== '' ? $thana : null,
            'qty' => $quantity,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $id,
        ]);
    }

    set_flash('success', 'তথ্য আপডেট করা হয়েছে।');
    redirect($viewUrl);
}

// রেজিস্ট্রেশনে কুরিয়ার নোট যোগ/মোছা — এখানে (approve করার সময়) দেওয়া নোট
// courier-prepare.php-এর কার্ডে অটোমেটিক চিপ হিসেবে দেখা যায় (একই registration_courier_notes টেবিল)।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add-note', 'del-note'], true)) {
    $rid = (int) ($_POST['registration_id'] ?? 0);
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php?action=view&id=' . $rid);

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    if ($action === 'add-note') {
        if (add_registration_note($db, $rid, (int) ($_POST['note_type_id'] ?? 0), $_POST['custom_text'] ?? '', $_POST['color'] ?? 'amber')) {
            set_flash('success', 'নোট যোগ হয়েছে — কুরিয়ার প্রস্তুত পেজেও দেখা যাবে।');
        } else {
            set_flash('error', 'নোট খালি — একটা তৈরি নোট বাছুন অথবা কাস্টম লেখা দিন।');
        }
    } else {
        delete_registration_note($db, $rid, (int) ($_POST['note_id'] ?? 0));
        set_flash('success', 'নোট মোছা হয়েছে।');
    }
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);

    // ডিলিটের আগে পুরো অর্ডার (রেজিস্ট্রেশন + আয়ের এন্ট্রি + কুরিয়ার ব্যাচ/শিপমেন্ট) আর্কাইভে রাখা হয় —
    // আর্কাইভ পেজ থেকে হুবহু (আসল id ও আয়ের হিসাব সহ) ফিরিয়ে আনা যায়। archive_entity() income/courier
    // child গুলো এখনই তুলে নেয়, তারপর নিচের DELETE লাইভ থেকে মোছে (income + registration→cascade)।
    archive_entity($db, 'registrations', $id);
    $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $id]);
    $db->prepare('DELETE FROM registrations WHERE id = :id')->execute(['id' => $id]);

    set_flash('success', 'রেজিস্ট্রেশন/অর্ডার আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect($returnUrl);
}

$viewRow = null;
$shipments = [];
$viewNotes = [];
$noteTypes = [];
if ($action === 'view' || $action === 'edit') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $viewRow = $stmt->fetch();
    if (!$viewRow) {
        redirect('registrations.php');
    }
    if ($action === 'view') {
        $shipStmt = $db->prepare('SELECT * FROM courier_shipments WHERE registration_id = :id ORDER BY created_at DESC');
        $shipStmt->execute(['id' => $id]);
        $shipments = $shipStmt->fetchAll();
        $viewNotes = fetch_one_registration_notes($db, $id);
        $noteTypes = fetch_courier_note_types($db);
    }
}

$courierConfigured = get_setting('courier_active_provider') !== '';

/* ══════════════════════════════════════════════════════════════════════════
 * 📤 CSV ডাউনলোড (২০২৬-১০-১৪, ইউজারের চাওয়া: "কোর্স অনুযায়ী ব্যাচ অনুযায়ী
 *    ডেটা ফিলটার করে CSV excel download করা যাবে")
 *
 * 🔴 ট্রিগার `?export=csv` — ইচ্ছাকৃতভাবে `action=` **নয়**। কেন্দ্রীয় গার্ড
 *    (`admin_require_login()`) POST-এ `action` দেখে edit/delete cap চায়; এটা নিছক
 *    পড়া, তাই পাতার সাধারণ `orders` view অনুমতিই যথেষ্ট।
 * 🔴 কলামগুলো তালিকার তিন লেআউটের প্রতিধ্বনি — কোর্সে কোর্সের ঘর, ওয়ার্কশিট/
 *    প্রোডাক্টে ওগুলোর ঘর, টাইপ বাছা না থাকলে মেশানো। **নতুন ফিল্ড যোগ করলে
 *    এখানেও যোগ করুন** (তালিকায় কলাম বাড়ানো নিষেধ, কিন্তু CSV-তে জায়গা আছে)।
 * ════════════════════════════════════════════════════════════════════════ */

/**
 * 🔴🔴 ভাঙা বাইট ছেঁকে ফেলা — এটাই Excel-এ কলাম এলোমেলো হওয়া ঠেকায় (২০২৬-১০-১৪)
 *
 * ডেটায় কোথাও একটা বাংলা অক্ষর **আধখানা** থেকে গেলে (যেমন ৩ বাইটের অক্ষরের শুধু
 * প্রথম বাইট `E0`) Excel-এর UTF-8 পাঠক ঢিলেঢালা — সে পরের দুই বাইট **গিলে ফেলে**,
 * অর্থাৎ ঘর-আলাদা-করা কমা আর তার পরের ঘরের প্রথম অক্ষরটাও খেয়ে নেয়:
 *   `…<E0>` + `,` + `Art`  →  একটা অদ্ভুত অক্ষর + `rt`
 * ইউজারের ফাইলে ঠিক এটাই হয়েছিল — `Art` → `ଁrt`, `Junior` → `ଊunior`, প্রতিটা সারিতে।
 * 🔴 তাই প্রতিটা ঘর লেখার আগে **বৈধ UTF-8** কিনা দেখা হয়, ভাঙা বাইট থাকলে বাদ।
 * (`mb_check_encoding()` সস্তা, তাই ভালো ঘরে বাড়তি কাজ হয় না।)
 */
function reg_csv_utf8(string $v): string
{
    if ($v === '' || mb_check_encoding($v, 'UTF-8')) {
        return $v;
    }
    // 🔴 'none' — নাহলে mbstring ভাঙা বাইটের জায়গায় '?' বসিয়ে দেয়
    $old = mb_substitute_character();
    mb_substitute_character('none');
    $fixed = (string) mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    mb_substitute_character($old);
    return $fixed;
}

/** 🔒 CSV ইনজেকশন গার্ড — Excel `=`/`+`/`-`/`@` দিয়ে শুরু হওয়া ঘরকে সূত্র ভাবে */
function reg_csv_safe(?string $v): string
{
    $v = trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $v));
    if ($v !== '' && strpbrk($v[0], "=+-@\t") !== false) {
        $v = "'" . $v;
    }
    return $v;
}

/** 📞 ফোন — Excel-এ শুরুর ০ যেন না হারায়। শুধু অঙ্ক/+ রাখা হয়, তাই সূত্র বসানো অসম্ভব */
function reg_csv_phone(?string $v): string
{
    $d = preg_replace('~[^0-9+]~', '', en_digits((string) $v));
    return $d === '' ? '' : '="' . $d . '"';
}

function reg_csv_money(float $v): string { return number_format($v, 2, '.', ''); }

/**
 * এক সারি লেখা — `fputcsv()` ইচ্ছাকৃতভাবে ব্যবহার করা হয়নি।
 * 🔴 কারণ: ফোনের ঘর `="01711000001"` রূপে যেতে হবে (Excel-এ শুরুর ০ বাঁচাতে), কিন্তু
 *    ওতে কোট থাকায় `fputcsv()` পুরো ঘরটা আবার কোট করে ফেলত, আর তখন কিছু প্রোগ্রাম
 *    ঐ লেখাটাই হুবহু দেখায়। তাই আগে থেকে তৈরি ঐ রূপটা কাঁচা যায়, বাকি সব ঘর
 *    RFC 4180 নিয়মে কোট হয়। (PHP ৮.৪-এর `$escape` deprecation-ও এতে আর লাগে না।)
 */
function reg_csv_put($fh, array $values): void
{
    $out = [];
    foreach ($values as $v) {
        $v = reg_csv_utf8((string) $v);
        if (preg_match('~^="[0-9+]*"$~', $v)) {
            $out[] = $v;                                   // Excel-এর টেক্সট-সূত্র, কাঁচাই যাবে
        } elseif (strpbrk($v, ",\"\r\n") !== false) {
            $out[] = '"' . str_replace('"', '""', $v) . '"';
        } else {
            $out[] = $v;
        }
    }
    fwrite($fh, implode(',', $out) . "\r\n");               // RFC 4180 — CRLF
}

function reg_csv_date(?string $v): string
{
    $t = strtotime((string) $v);
    return $t ? date('Y-m-d H:i', $t) : '';
}

/**
 * টাইপ-অনুযায়ী কলাম: [শিরোনাম => fn(array $row, array $ledger): string]
 * $type খালি = "সব টাইপ" (মেশানো লেআউট)।
 */
function reg_csv_columns(string $type, array $statusLabels, array $typeLabels): array
{
    $common = [
        'আইডি'      => fn($r, $l) => (string) $r['id'],
        'তারিখ'     => fn($r, $l) => reg_csv_date($r['created_at'] ?? ''),
        'স্ট্যাটাস'  => fn($r, $l) => $statusLabels[$r['status']][0] ?? (string) $r['status'],
    ];
    $money = [
        'জমা'  => fn($r, $l) => reg_csv_money($l ? pay_paid_total($l) : 0.0),
        'বাকি' => fn($r, $l) => reg_csv_money($l ? pay_summary($l)['balance'] : (float) ($r['due_amount'] ?? 0)),
        'আয় (অনুমোদিত)' => fn($r, $l) => !empty($r['income_approved']) ? reg_csv_money((float) ($r['income_amount'] ?? 0)) : '',
    ];
    $notes = [
        'অভিভাবকের মন্তব্য' => fn($r, $l) => reg_csv_safe($r['notes'] ?? ''),
        'নোট'              => fn($r, $l) => reg_csv_safe($r['admin_note'] ?? ''),
    ];

    if ($type === 'course') {
        // course-register.php ফর্মের ক্রমেই (তালিকার কোর্স-লেআউটের মতো)
        return $common + [
            'কোর্স'            => fn($r, $l) => reg_csv_safe($r['item_title'] ?? ''),
            'ব্যাচ'            => fn($r, $l) => reg_csv_safe($r['batch'] ?? ''),
            'শিশুর নাম'        => fn($r, $l) => reg_csv_safe($r['customer_name'] ?? ''),
            'মোবাইল (মা)'      => fn($r, $l) => reg_csv_phone($r['phone'] ?? ''),
            'জন্ম তারিখ'       => fn($r, $l) => reg_csv_safe($r['date_of_birth'] ?? ''),
            'ফেসবুক আইডি'      => fn($r, $l) => reg_csv_safe($r['facebook_id'] ?? ''),
            'মোবাইল (বাবা)'    => fn($r, $l) => reg_csv_phone($r['father_mobile'] ?? ''),
            'রিসিভারের নাম'    => fn($r, $l) => reg_csv_safe($r['receiver_name'] ?? ''),
            'রিসিভারের নম্বর'  => fn($r, $l) => reg_csv_phone($r['receiver_phone'] ?? ''),
            'ঠিকানা'           => fn($r, $l) => reg_csv_safe($r['address'] ?? ''),
            'ডেলিভারি এলাকা'   => fn($r, $l) => reg_csv_safe($r['delivery_zone'] ?? ''),
            'FB গ্রুপে'        => fn($r, $l) => !empty($r['fb_group_added']) ? 'হ্যাঁ' : 'না',
            'মেসেঞ্জার গ্রুপে' => fn($r, $l) => !empty($r['messenger_group_added']) ? 'হ্যাঁ' : 'না',
            'কুরিয়ারে যাবে'    => fn($r, $l) => (int) ($r['courier_active'] ?? 1) ? 'হ্যাঁ' : 'না',
        ] + $money + $notes;
    }

    if ($type === 'worksheet' || $type === 'product') {
        // register.php ফর্ম — ওয়ার্কশিট ও প্রোডাক্ট একই ফর্ম শেয়ার করে
        return $common + [
            'আইটেম'   => fn($r, $l) => reg_csv_safe($r['item_title'] ?? ''),
            'পরিমাণ'  => fn($r, $l) => (string) (int) ($r['quantity'] ?? 1),
            'নাম'     => fn($r, $l) => reg_csv_safe($r['customer_name'] ?? ''),
            'মোবাইল'  => fn($r, $l) => reg_csv_phone($r['phone'] ?? ''),
            'ইমেইল'   => fn($r, $l) => reg_csv_safe($r['email'] ?? ''),
            'ঠিকানা'  => fn($r, $l) => reg_csv_safe($r['address'] ?? ''),
            'জেলা'    => fn($r, $l) => reg_csv_safe($r['district'] ?? ''),
            'থানা'    => fn($r, $l) => reg_csv_safe($r['thana'] ?? ''),
        ] + $money + $notes;
    }

    // টাইপ বাছা নেই — মেশানো
    return $common + [
        'ধরন'     => fn($r, $l) => $typeLabels[$r['type']][0] ?? (string) $r['type'],
        'আইটেম'   => fn($r, $l) => reg_csv_safe($r['item_title'] ?? ''),
        'ব্যাচ'    => fn($r, $l) => reg_csv_safe($r['batch'] ?? ''),
        'নাম'     => fn($r, $l) => reg_csv_safe($r['customer_name'] ?? ''),
        'মোবাইল'  => fn($r, $l) => reg_csv_phone($r['phone'] ?? ''),
        // তালিকার মেশানো লেআউটেও রিসিভার দেখানো হয় (কোর্সের সারিতে), তাই CSV-তেও
        'রিসিভারের নাম'   => fn($r, $l) => reg_csv_safe($r['receiver_name'] ?? ''),
        'রিসিভারের নম্বর' => fn($r, $l) => reg_csv_phone($r['receiver_phone'] ?? ''),
        'ঠিকানা'  => fn($r, $l) => reg_csv_safe($r['address'] ?? ''),
        'পরিমাণ'  => fn($r, $l) => (string) (int) ($r['quantity'] ?? 1),
    ] + $money + $notes;
}

/** ফাইলের নাম — ASCII-নিরাপদ (বাংলা নাম কিছু ব্রাউজারে ভাঙে) */
function reg_csv_filename(string $type, string $item, string $batch): string
{
    $bits = ['orders'];
    if ($type !== '') { $bits[] = $type; }
    // 🔴 বাংলা কোর্স/ব্যাচের নাম ফাইলনামে দেওয়া যায় না — তাই শুধু ইঙ্গিত
    if ($item !== '') { $bits[] = 'filtered'; }
    elseif ($batch !== '') { $bits[] = 'batch'; }
    $bits[] = date('Ymd-His');
    return implode('-', $bits) . '.csv';
}

// ── 🔎 তালিকার সার্চ — "সকল তথ্য দিয়ে খোঁজা" (২০২৬-১০-১৫, ইউজারের চাওয়া) ─────────
// আগে শুধু ৩টা ঘরে খোঁজা হতো (নাম · ফোন · আইটেম), তাই রিসিভারের নাম/নম্বর, বাবার
// মোবাইল, ফেসবুক আইডি, ঠিকানা, ব্যাচ, নোট, কনসাইনমেন্ট আইডি — কিছু দিয়েই খুঁজে
// পাওয়া যেত না।
//
// 🔴 খড়ের গাদা **একটাই SQL এক্সপ্রেশনে** (`CONCAT_WS`) — তাই প্রতি শব্দের জন্য
//    একটাই প্লেসহোল্ডার লাগে। ঘর-প্রতি আলাদা `LIKE` লিখলে ১৭ ঘর × ২ রূপ = ৩৪
//    প্লেসহোল্ডার হতো, আর নতুন ঘর যোগ করতে একাধিক জায়গা এডিট করা লাগত।
//    (`ATTR_EMULATE_PREPARES => false` বলে একই নামের প্লেসহোল্ডার দুইবার দেওয়াও
//     যায় না — HY093।) `CONCAT_WS` নিজেই NULL ঘর বাদ দেয়।
// 🔴 কলামের নামগুলো **কোডে লেখা ধ্রুবক**, কখনো GET/POST থেকে নয়।
// 🔴 স্ট্যাটাস/ধরন/টাকার ঘর ইচ্ছাকৃতভাবে বাদ — ওগুলোর নিজের ফিল্টার আছে, আর
//    অঙ্ক দিয়ে খুঁজলে প্রতিটা সারির বাকি/আয়ের সংখ্যাও "মিলত"।
function reg_search_haystack_sql(): string
{
    return "CONCAT_WS(' ', id, customer_name, phone, item_title, batch,"
        . " receiver_name, receiver_phone, father_mobile, facebook_id, email,"
        . " address, district, thana, date_of_birth, notes, admin_note,"
        . " courier_consignment_id, courier_provider)";
}

// খোঁজার শব্দের রূপগুলো — কাঁচা লেখা, আর বাংলা অঙ্ক থাকলে তার English রূপও।
// 🔴 **দুটোই রাখা হয়** — ফোন নম্বর DB-তে English অঙ্কে, কিন্তু ব্যাচের নাম ("৫ম ব্যাচ")
//    বাংলা অঙ্কে; একটা বাদ দিলে অন্য ধরনের লেখা খুঁজে পাওয়া যেত না।
// 🔴 `en_digits()` নয় — ওটা ক্রমবাচক ("৫ম") ইচ্ছাকৃতভাবে ছোঁয় না, অথচ "০১৭" লিখে
//    ফোন খুঁজতে পুরো রূপান্তরই দরকার (course-parcel.php-এর সার্চেও একই নিয়ম)।
function reg_search_terms(string $search): array
{
    $terms = [$search];
    $en = strtr($search, bn_digit_map());
    if ($en !== $search) { $terms[] = $en; }
    return $terms;
}

$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');
$filterItem = trim($_GET['item'] ?? '');
$filterBatch = trim($_GET['batch'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
// 💰 শুধু যাঁদের টাকা বাকি আছে — 🔴 কলামটা আছে কিনা আগে দেখা হয় (মাইগ্রেশনের আগে
//    ফিল্টারটা চুপচাপ বাদ যায়, তালিকা আগের মতোই চলে)
$hasDueCol = db_has_column($db, 'registrations', 'due_amount');
$filterDue = $hasDueCol && ($_GET['due'] ?? '') === '1';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$rows = [];
$listSummary = [];
$totalRows = 0;
$totalPages = 1;
$distinctItems = [];
$distinctBatches = [];

if ($action === 'list') {
    $where = [];
    $params = [];

    if ($filterStatus && isset($statusLabels[$filterStatus])) {
        $where[] = 'status = :status';
        $params['status'] = $filterStatus;
    }
    if ($filterType && isset($typeLabels[$filterType])) {
        $where[] = 'type = :type';
        $params['type'] = $filterType;
    }
    if ($filterItem !== '') {
        $where[] = 'item_title = :item';
        $params['item'] = $filterItem;
    }
    if ($filterBatch !== '') {
        $where[] = 'batch = :batch';
        $params['batch'] = $filterBatch;
    }
    if ($search !== '') {
        // 🔴 `%`/`_` escape বাধ্যতামূলক — নাহলে `%` লিখলেই সব সারি "মিলত"
        //    (search.php-এর একই নিয়ম; escape-অক্ষর `!`, ব্যাকস্ল্যাশ নয় —
        //     তার আচরণ MySQL-এর `NO_BACKSLASH_ESCAPES` মোডের উপর নির্ভর করে)।
        $hay = reg_search_haystack_sql();
        $ors = [];
        foreach (reg_search_terms($search) as $i => $term) {
            $ors[] = $hay . " LIKE :q{$i} ESCAPE '!'";
            $params["q{$i}"] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term) . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
    if ($filterDue) {
        $where[] = REG_DUE_WHERE;   // প্লেসহোল্ডার লাগে না — পুরো শর্তটা কোডে লেখা
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    // ── 📤 CSV ডাউনলোড — চলতি সব ফিল্টার মেনে, পেজিনেশন ছাড়া পুরোটা ──────────
    // 🔴 এখানেই, `$whereSql` তৈরির পরপর — তাই তালিকা যা দেখাচ্ছে CSV-তেও হুবহু তাই
    //    (ফিল্টারের নিয়ম দুই জায়গায় লিখলে সময়ের সাথে আলাদা হয়ে যেত)।
    if (($_GET['export'] ?? '') === 'csv') {
        $cols = reg_csv_columns($filterType, $statusLabels, $typeLabels);
        $fn   = reg_csv_filename($filterType, $filterItem, $filterBatch);

        while (ob_get_level() > 0) { ob_end_clean(); }   // কোনো আগের আউটপুট যেন ফাইলে না ঢোকে
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Cache-Control: no-store');
        $out = fopen('php://output', 'w');
        // 🔴 UTF-8 BOM — নাহলে Excel বাংলা লেখা mojibake দেখায়
        fwrite($out, "\xEF\xBB\xBF");
        reg_csv_put($out, array_keys($cols));

        // বড় ব্যাচেও মেমরি না বাড়ে — ৫০০ করে টেনে, প্রতি গুচ্ছের খাতা একবারে
        $chunk = 500;
        $off   = 0;
        while (true) {
            // 🔴 `id DESC` টাই-ব্রেকার বাধ্যতামূলক — একই সেকেন্ডে বসা সারিগুলোর ক্রম
            //    স্থির না থাকলে OFFSET দিয়ে টানায় কেউ দুইবার আসত, কেউ বাদ পড়ত।
            //    LIMIT/OFFSET কোডের নিজের গোনা সংখ্যা (int-কাস্ট), কখনো ইউজার-ইনপুট নয়।
            $st = $db->prepare("SELECT * FROM registrations{$whereSql}
                                ORDER BY created_at DESC, id DESC
                                LIMIT " . (int) $chunk . " OFFSET " . (int) $off);
            $st->execute($params);
            $batchRows = $st->fetchAll();
            if (!$batchRows) { break; }

            // 🔑 খাতা একবারে (N+1 এড়াতে) — টেবিল না থাকলেও যেন ডাউনলোড না ভাঙে
            $ledgers = [];
            try {
                $ledgers = pay_fetch_many($db, array_map(fn($r) => (int) $r['id'], $batchRows));
            } catch (PDOException $ex) {
                $ledgers = [];
            }
            foreach ($batchRows as $r) {
                $lg   = $ledgers[(int) $r['id']] ?? [];
                $line = [];
                foreach ($cols as $cb) { $line[] = $cb($r, $lg); }
                reg_csv_put($out, $line);
            }
            if (count($batchRows) < $chunk) { break; }
            $off += $chunk;
        }
        fclose($out);
        exit;
    }

    // ── উপরের সারাংশ কার্ড: স্ট্যাটাস **বাদে** বাকি সব ফিল্টার মেনে গণনা
    // (তাই "পেন্ডিং ৯ / কনফার্ম ২" কার্ডে ক্লিক করে স্ট্যাটাস বদলালেও সংখ্যাগুলো একই থাকে)
    // 🔴 স্ট্যাটাস **ও বাকির** শর্ত দুটোই বাদ — দুটোরই নিজের কার্ড আছে, কার্ডে চাপলে
    //    তার নিজের সংখ্যা বদলে গেলে অ্যাডমিন বুঝতেন না আসলে কতজনের বাকি।
    $sumWhere  = array_values(array_filter(
        $where,
        fn($w) => $w !== 'status = :status' && $w !== REG_DUE_WHERE
    ));
    $sumParams = $params;
    unset($sumParams['status']);
    $sumSql = $sumWhere ? (' WHERE ' . implode(' AND ', $sumWhere)) : '';
    try {
        $summaryStmt = $db->prepare(
            "SELECT
                SUM(status = 'pending')   AS pending,
                SUM(status = 'confirmed') AS confirmed,
                SUM(CASE WHEN " . REG_DUE_WHERE . " THEN COALESCE(due_amount, 0) ELSE 0 END) AS due_total,
                SUM(CASE WHEN " . REG_DUE_WHERE . " THEN 1 ELSE 0 END)                       AS due_rows,
                SUM(CASE WHEN income_approved = 1 THEN COALESCE(income_amount, 0) ELSE 0 END) AS income_total,
                COUNT(*) AS all_rows
             FROM registrations" . $sumSql
        );
        $summaryStmt->execute($sumParams);
        $listSummary = $summaryStmt->fetch() ?: [];
    } catch (PDOException $ex) {
        $listSummary = []; // due_amount কলাম না থাকলেও (মাইগ্রেশনের আগে) পেজ ভাঙবে না
    }

    $countStmt = $db->prepare('SELECT COUNT(*) c FROM registrations' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetch()['c'];
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    // 🔴 "বাকি আছে" ফিল্টার চালু থাকলে **সবচেয়ে বেশি বাকি আগে** — তাড়া দেওয়ার কাজে
    //    ওটাই দরকার। অন্য সময় আগের মতোই নতুন অর্ডার আগে (`idx_reg_status_created`
    //    তখন কাজে লাগে; বাকির শর্ত বসলে ঐ ইনডেক্স এমনিতেই ব্যবহার হয় না, তাই
    //    এই ক্রম বদলানোয় বাড়তি খরচ নেই)।
    $orderBy = $filterDue
        ? ' ORDER BY COALESCE(due_amount, 0) DESC, created_at DESC'
        : ' ORDER BY created_at DESC';
    $sql = 'SELECT * FROM registrations' . $whereSql . $orderBy . ' LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $ledgerByReg = pay_fetch_many($db, array_column($rows, 'id')); // প্রতি রো-তে আলাদা কোয়েরি না করে একবারে

    // ফিল্টার ড্রপডাউনে দেখানোর জন্য আইটেমের নামের তালিকা (টাইপ ফিল্টার করা থাকলে সেই টাইপেই সীমাবদ্ধ)
    $itemsSql = 'SELECT DISTINCT item_title FROM registrations';
    $itemsParams = [];
    if ($filterType && isset($typeLabels[$filterType])) {
        $itemsSql .= ' WHERE type = :type';
        $itemsParams['type'] = $filterType;
    }
    $itemsSql .= ' ORDER BY item_title';
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->execute($itemsParams);
    $distinctItems = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);

    // ব্যাচ ফিল্টার ড্রপডাউন — শুধু কোর্স টাইপে প্রযোজ্য (registrations.batch), নির্দিষ্ট আইটেম/কোর্স বাছাই করা
    // থাকলে সেই কোর্সের ব্যাচগুলোতেই সীমাবদ্ধ থাকে (কোনো আইটেম বাছাই না থাকলে সব কোর্সের সব ব্যাচ দেখায়)
    $batchesSql = "SELECT DISTINCT batch FROM registrations WHERE type = 'course' AND batch IS NOT NULL AND batch != ''";
    $batchesParams = [];
    if ($filterItem !== '') {
        $batchesSql .= ' AND item_title = :item';
        $batchesParams['item'] = $filterItem;
    }
    $batchesSql .= ' ORDER BY batch';
    $batchesStmt = $db->prepare($batchesSql);
    $batchesStmt->execute($batchesParams);
    $distinctBatches = $batchesStmt->fetchAll(PDO::FETCH_COLUMN);
}

// পেজ নম্বর বাদে বাকি সব সক্রিয় ফিল্টার — লিংক/ফর্ম তৈরিতে বারবার ব্যবহার হয়
$activeFilters = array_filter([
    'status' => $filterStatus,
    'type' => $filterType,
    'q' => $search,
    'item' => $filterItem,
    'batch' => $filterBatch,
    'due' => $filterDue ? '1' : '',
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], fn($v) => $v !== '' && $v !== null);

function reg_url(array $overrides = []): string
{
    global $activeFilters;
    $params = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($params);
    return 'registrations.php' . ($qs !== '' ? '?' . $qs : '');
}

// লিস্ট পেজে থাকা অবস্থাতেই স্ট্যাটাস পরিবর্তনের পর একই ফিল্টার/সার্চ/পেজে ফিরে আসার জন্য
$currentListUrl = reg_url($page > 1 ? ['page' => $page] : []);
$hasActiveFilters = !empty($activeFilters);

// ── তালিকা/ডিটেইল দুই জায়গাতেই ব্যবহৃত সেল-রেন্ডারার (ডুপ্লিকেট না করে শেয়ার্ড)
// স্ট্যাটাস ড্রপডাউন সেল — তিনটা কলাম-লেআউটেই হুবহু একই, তাই একটা ফাংশনে বের করা হলো (DRY)
function reg_status_cell(array $row, array $statusLabels, string $currentListUrl): void
{
    $s = $statusLabels[$row['status']] ?? ['?', 'bg-gray-100'];
    ?>
    <form method="post" action="registrations.php?action=update-status">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $row['id'] ?>">
        <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
        <div class="relative inline-block">
            <select name="status" data-original="<?= e($row['status']) ?>" onchange="confirmStatusChange(this)" class="status-select appearance-none pl-3 pr-7 py-1.5 rounded-full text-xs font-semibold border-0 cursor-pointer shadow-sm hover:shadow transition-shadow focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-1 <?= $s[1] ?>">
                <?php foreach ($statusLabels as $key => $lbl): ?>
                    <option value="<?= e($key) ?>" <?= $row['status'] === $key ? 'selected' : '' ?>><?= e($lbl[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <i data-lucide="chevron-down" class="w-3 h-3 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none opacity-60"></i>
        </div>
    </form>
    <?php
}
function reg_income_cell(array $row): void
{
    if ($row['income_approved']): ?>
        <span class="text-green-700 font-semibold text-xs">✅ ৳<?= number_format((float) $row['income_amount'], 2) ?></span>
    <?php else: ?>
        <span class="text-gray-300 text-xs">—</span>
    <?php endif;
}

// ── 👤 ব্যক্তি-সেল (নাম + ফোন + ঠিকানার ইঙ্গিত) — ২০২৬-০৯-২৩ রিডিজাইন।
// আগে নাম/ফোন/ঠিকানা তিনটা আলাদা কলাম ছিল; এক ঘরে তিন স্তরে বসানোয় তালিকা অনেক সরু হয়েছে।
function reg_person_cell(array $row): void
{
    $isCourse = ($row['type'] ?? '') === 'course';
    $name   = trim((string) ($row['customer_name'] ?? ''));
    $phone  = trim((string) ($row['phone'] ?? ''));
    $rName  = trim((string) ($row['receiver_name'] ?? ''));
    $rPhone = trim((string) ($row['receiver_phone'] ?? ''));
    $addr   = trim((string) ($row['address'] ?? ''));
    // 🔴 লেবেলগুলো course-register.php / register.php ফর্মের ঘরের নামের সংক্ষেপ —
    //    "কোনটা শিশুর নাম, কোনটা রিসিভারের, কোনটা কার মোবাইল" এক নজরে বোঝার জন্য
    //    (২০২৬-১০-১৫, ইউজারের চাওয়া)। **নতুন কলাম বাড়ানো হয়নি** — তালিকার নিয়ম।
    $nameLabel  = $isCourse ? 'শিশু' : 'ক্রেতা';
    $phoneLabel = $isCourse ? 'মা' : 'ফোন';
    // 🔴 মোড়কটা `min-w-0` — মোবাইল কার্ড-লেআউটে `td` flex হয়ে যায়, খোলা রাখলে
    //    লাইনগুলো পাশাপাশি কলামে চেপে যেত (course-parcel.php-এর রোস্টারে ধরা পড়েছিল)।
    ?>
    <div class="min-w-0">
        <?php reg_person_line($nameLabel, $name !== '' ? $name : '—', '', true); ?>
        <?php if ($phone !== '') { reg_person_line($phoneLabel, $phone, 'tel:' . $phone); } ?>
        <?php if ($rName !== '' || $rPhone !== ''): ?>
            <?php // রিসিভারের দুটো লাইন আলাদা দাগে — তাই নিচের "ফোন" কারটা তা নিয়ে ভুল হয় না ?>
            <div class="mt-0.5" style="border-left:2px solid #E5E7EB;padding-left:5px">
                <?php if ($rName !== '') { reg_person_line('রিসিভার', $rName); } ?>
                <?php if ($rPhone !== '') { reg_person_line('ফোন', $rPhone, 'tel:' . $rPhone); } ?>
            </div>
        <?php endif; ?>
        <?php if ($addr !== ''): ?>
            <div class="text-[10px] text-gray-400 max-w-[200px] truncate mt-0.5" title="<?= e($addr) ?>">📍 <?= e($addr) ?></div>
        <?php endif; ?>
    </div>
    <?php
}

// ছোট ধূসর লেবেল + মান এক লাইনে (ফোন হলে `tel:` লিংক — ফোনে এক ট্যাপে কল)।
// 🔴 লেবেলের জন্য ফিক্সড প্রস্থের কলাম দেওয়া হয়নি — ৩২০px পর্দায় ঐ প্রস্থটা নামের
//    জায়গা খেয়ে ফেলত; বদলে "লেবেল · মান" রূপ, মান আলাদা রঙে বলে চোখে আলাদাই লাগে।
function reg_person_line(string $label, string $value, string $href = '', bool $strong = false): void
{
    ?>
    <div class="leading-tight break-words">
        <span class="text-[10px] text-gray-400 font-semibold"><?= e($label) ?> ·</span>
        <?php if ($href !== ''): ?>
            <a href="<?= e($href) ?>" class="text-[11px] text-indigo-600 font-semibold" title="ফোন করুন"><?= e($value) ?></a>
        <?php elseif ($strong): ?>
            <span class="font-semibold text-gray-800"><?= e($value) ?></span>
        <?php else: ?>
            <span class="text-[11px] text-gray-600"><?= e($value) ?></span>
        <?php endif; ?>
    </div>
    <?php
}

// ── 📦 আইটেম-সেল (আইটেমের নাম + নিচে ছোট করে ব্যাচ/পরিমাণ) — আলাদা "ব্যাচ"/"পরিমাণ" কলাম আর লাগে না
function reg_item_cell(array $row): void
{
    global $typeLabels;                       // reg_url()-এর `global $activeFilters`-এর মতোই
    $title = trim((string) ($row['item_title'] ?? ''));
    // 🔴 টাইপ এখন এই ঘরেই (২০২৬-০৯-২৩, ইউজার: "টাইপ + আইটেম/ব্যাচ এক ঘরে থাকবে") —
    // আলাদা "টাইপ" কলাম তুলে দিয়ে সেই জায়গাটা "ফেসবুক আইডি নাম"-কে দেওয়া হয়েছে।
    // আইটেম/ব্যাচ না থাকলেও মেটা-লাইনে অন্তত টাইপটা দেখা যায়।
    $meta  = [];
    if (isset($typeLabels[$row['type'] ?? ''])) {
        $meta[] = $typeLabels[$row['type']];
    }
    if (trim((string) ($row['batch'] ?? '')) !== '') {
        $meta[] = trim((string) $row['batch']);
    }
    if (($row['type'] ?? '') !== 'course' && (int) ($row['quantity'] ?? 0) > 1) {
        $meta[] = 'পরিমাণ ' . (int) $row['quantity'];
    }
    ?>
    <div class="text-gray-800 max-w-[200px] truncate" title="<?= e($title) ?>"><?= e($title !== '' ? $title : '—') ?></div>
    <?php if ($meta): ?>
        <div class="text-[10px] text-gray-400"><?= e(implode(' · ', $meta)) ?></div>
    <?php endif;
}

// ── 👍 ফেসবুক আইডি নাম — ইউজার কাজ করার সময় এটা তালিকাতেই দেখতে চান (২০২৬-০৯-২৩)।
// ফর্মে শুধু কোর্সেই নেওয়া হয়, তাই worksheet/product লেআউটে এই কলামটা নেই।
function reg_fb_cell(array $row): void
{
    $fb = trim((string) ($row['facebook_id'] ?? ''));
    if ($fb === '') {
        echo '<span class="text-gray-300 text-xs">—</span>';
        return;
    }
    ?>
    <div class="text-xs text-gray-700 max-w-[200px] truncate" title="<?= e($fb) ?>"><?= e($fb) ?></div>
    <?php
}

// ── 📅 তারিখ-সেল — সংক্ষিপ্ত তারিখ + "৩ দিন আগে"; পুরো টাইমস্ট্যাম্প title-এ (হোভারে দেখা যায়)
function reg_date_cell(array $row): void
{
    $raw = (string) ($row['created_at'] ?? '');
    $ts  = $raw !== '' ? strtotime($raw) : false;
    if (!$ts) {
        echo '<span class="text-gray-300 text-xs">—</span>';
        return;
    }
    $days = (int) floor((time() - $ts) / 86400);
    $rel  = $days <= 0 ? 'আজ' : ($days === 1 ? 'গতকাল' : $days . ' দিন আগে');
    ?>
    <div class="text-xs text-gray-600 whitespace-nowrap" title="<?= e($raw) ?>"><?= e(date('d M', $ts)) ?></div>
    <div class="text-[10px] text-gray-400 whitespace-nowrap"><?= e($rel) ?></div>
    <?php
}

// ── ℹ️ "তথ্য" বোতাম — বাকি (কম দরকারি) ফিল্ডগুলো সারির নিচে ড্রয়ারে খোলে।
// খাতার প্যানেলের মতোই কলাম না বাড়িয়ে নিচে খোলার নীতি (১৬ কলামে টেবিল কেটে যাচ্ছিল)।
// 📝 অর্ডারে নোট লেখা আছে কিনা — খাতার নোট (অ্যাডমিনের) ও অভিভাবকের মন্তব্য দুটোই।
// একটাই জায়গায় রাখা হয়েছে যাতে তালিকা · তথ্য-ড্রয়ার · বিস্তারিত পাতা তিন জায়গায় একই
// হিসাব হয় (একটা বদলে অন্যটা রেখে দিলে কোথাও চিহ্ন দেখাত কোথাও না)।
function reg_notes_of(array $row): array
{
    $out = [];
    $a = trim((string) ($row['admin_note'] ?? ''));
    $b = trim((string) ($row['notes'] ?? ''));
    if ($a !== '') { $out['📝 নোট'] = $a; }
    if ($b !== '') { $out['🗒️ অভিভাবকের মন্তব্য'] = $b; }
    return $out;
}

function reg_more_cell(array $row): void
{
    // 🔴 নোট থাকলে বোতামটাই অ্যাম্বার হয়ে "📝 নোট" লেখা দেখায় — তালিকায় **নতুন কলাম
    //    বাড়ানো নিষেধ**, তাই "এখানে কিছু লেখা আছে" বোঝানোর জায়গা এটাই। পুরো লেখাটা
    //    `title`-এ (ডেস্কটপে হোভারে), আর ট্যাপ করলে ড্রয়ারে হাইলাইট করা অবস্থায় আছেই।
    $notes = reg_notes_of($row);
    $tip   = $notes ? implode("\n", array_map(fn($k, $v) => $k . ': ' . $v, array_keys($notes), $notes))
                    : 'বাকি তথ্য দেখুন/লুকান';
    ?>
    <button type="button" onclick="toggleInfoPanel(<?= (int) $row['id'] ?>)" title="<?= e($tip) ?>"
            class="px-2 py-1 rounded-lg text-xs font-semibold border whitespace-nowrap <?= $notes ? 'bg-amber-100 text-amber-800 border-amber-200' : 'bg-gray-100 text-gray-600 border-gray-200' ?>"><?= $notes ? '📝 নোট' : 'ℹ️ তথ্য' ?></button>
    <?php
}

// ── তথ্য-ড্রয়ার। 🔴 ভেতরে নেস্টেড <table> দেবেন না — মোবাইল কার্ড-CSS
// (main .overflow-x-auto > table td) ভেঙে দেবে; grid/flex ব্যবহার করুন (খাতার প্যানেলের মতোই)।
function reg_more_panel(array $row, int $colspan, string $returnUrl = ''): void
{
    $rid = (int) $row['id'];
    $viewHref = 'registrations.php?action=view&id=' . $rid
        . ($returnUrl !== '' ? '&return=' . rawurlencode($returnUrl) : '');
    if (($row['type'] ?? '') === 'course') {
        // 🔴 রিসিভারের নাম/নম্বর এখন প্রথম কলামেই সবসময় দেখা যায় (২০২৬-১০-১৫),
        //    তাই এখান থেকে তুলে দেওয়া হয়েছে — ডুপ্লিকেট নয় (ফেসবুক আইডির মতোই নীতি)।
        $fields = [
            'জন্ম তারিখ'            => !empty($row['date_of_birth']) ? format_date_bn((string) $row['date_of_birth']) : '',
            'মোবাইল নাম্বার (বাবা)' => (string) ($row['father_mobile'] ?? ''),
            'জেলা'                  => (string) ($row['district'] ?? ''),
            'থানা'                  => (string) ($row['thana'] ?? ''),
        ];
    } else {
        $fields = [
            'ইমেইল'  => (string) ($row['email'] ?? ''),
            'পরিমাণ' => (string) ((int) ($row['quantity'] ?? 0)),
            'জেলা'   => (string) ($row['district'] ?? ''),
            'থানা'   => (string) ($row['thana'] ?? ''),
        ];
    }
    $fields['ঠিকানা'] = (string) ($row['address'] ?? '');
    // 🔴 নোট দুটো এই গ্রিডে **নেই** — নিচে আলাদা অ্যাম্বার বাক্সে, নাহলে ৯টা সাধারণ ঘরের
    //    মাঝে মিশে গিয়ে চোখেই পড়ত না (ইউজারের চাওয়া)।
    $noteList = reg_notes_of($row);
    ?>
    <tr class="reg-info-row" id="info-<?= $rid ?>" hidden>
        <td colspan="<?= $colspan ?>" style="text-align:left;padding:0">
            <div class="p-4" style="background:#F9FAFB;border-top:2px solid #E5E7EB">
                <?php foreach ($noteList as $nLabel => $nText): ?>
                    <div class="bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 mb-2 text-sm text-amber-800 break-words"
                         style="box-shadow:inset 3px 0 0 #F59E0B">
                        <b><?= e($nLabel) ?>:</b> <?= e($nText) ?>
                    </div>
                <?php endforeach; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <?php foreach ($fields as $label => $val): $val = trim((string) $val); ?>
                        <div>
                            <div class="text-[10px] text-gray-400 font-semibold"><?= e($label) ?></div>
                            <?php if ($val !== ''): ?>
                                <div class="text-sm text-gray-800 break-words"><?= e($val) ?></div>
                            <?php else: ?>
                                <div class="text-sm text-gray-300">—</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mt-3">
                    <a href="<?= e($viewHref) ?>" class="text-indigo-600 font-semibold text-sm">সম্পূর্ণ বিস্তারিত পাতায় যান →</a>
                </div>
            </div>
        </td>
    </tr>
    <?php
}

// ── সারির বাঁ পাশে স্ট্যাটাসের রঙিন দাগ (inline style — কম্পাইলড Tailwind-এ border-l-4 রঙের
// ক্লাসগুলো নেই, আর রিবিল্ড ছাড়া নতুন ক্লাস নীরবে কাজ করে না)
function reg_row_style(array $row): string
{
    $colors = [
        'pending'   => '#F59E0B',
        'confirmed' => '#3B82F6',
        'shipped'   => '#8B5CF6',
        'delivered' => '#22C55E',
        'cancelled' => '#EF4444',
    ];
    $c = $colors[$row['status'] ?? ''] ?? '#E5E7EB';
    return 'box-shadow: inset 3px 0 0 ' . $c;
}

// ── গ্রুপে যোগ হয়েছে? (ফেসবুক / মেসেঞ্জার) — তালিকা থেকেই এক ট্যাপে টিক।
// একই কলাম course-parcel.php-ও ব্যবহার করে (fb_group_added / messenger_group_added), তাই
// দুই পেজে অবস্থা সবসময় এক থাকে। টিক তোলার সময় ওয়ার্নিং, টিক দেওয়ার সময় না (প্রথমবার-ছাড়া-পরে নিয়ম)।
function reg_group_cell(array $row, string $currentListUrl): void
{
    if (($row['type'] ?? '') !== 'course') {
        echo '<span class="text-gray-300 text-xs">—</span>';   // গ্রুপ শুধু কোর্সে প্রযোজ্য
        return;
    }
    $toggles = [
        ['field' => 'fb',        'label' => 'ফেসবুক',    'short' => 'F', 'column' => 'fb_group_added'],
        ['field' => 'messenger', 'label' => 'মেসেঞ্জার', 'short' => 'M', 'column' => 'messenger_group_added'],
    ];
    echo '<div class="flex gap-1">';
    foreach ($toggles as $t) {
        $on = !empty($row[$t['column']]);
        $onsubmit = $on
            ? "return confirmSubmit(this, '" . $t['label'] . " গ্রুপের টিক তুলে ফেলবেন? এই শিক্ষার্থী গ্রুপে নেই বলে চিহ্নিত হবে।', 'টিক তুলবেন?')"
            : 'return true';
        ?>
        <form method="post" action="registrations.php?action=quick-group" class="inline" onsubmit="<?= e($onsubmit) ?>;">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $row['id'] ?>">
            <input type="hidden" name="field" value="<?= e($t['field']) ?>">
            <input type="hidden" name="value" value="<?= $on ? 0 : 1 ?>">
            <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
            <button type="submit" title="<?= e($t['label']) ?> গ্রুপে যোগ হয়েছে?<?= $on ? ' (টিক দেওয়া আছে)' : '' ?>" class="w-7 h-7 rounded-full text-xs font-bold <?= $on ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-400 border border-gray-200' ?>"><?= e($t['short']) ?></button>
        </form>
        <?php
    }
    echo '</div>';
}

// ── 💰 টাকার অবস্থা (তালিকার সরু কলাম) — ক্লিকে নিচের খাতা-প্যানেল খোলে/বন্ধ হয়।
// কলাম বাড়ানোর বদলে প্যানেল নিচে খোলার কারণ: কোর্স-লেআউটে এমনিতেই ১৬ কলাম, ইনপুট-ভরা
// চওড়া ঘর যোগ করলে ডেস্কটপেও টেবিল কেটে যাচ্ছিল (২০২৬-০৯-২২, ইউজারের স্ক্রিনশট)।
function reg_pay_cell(array $row, array $summary): void
{
    ?>
    <button type="button" class="text-left whitespace-nowrap" onclick="togglePayPanel(<?= (int) $row['id'] ?>)" title="টাকার খাতা খুলুন/বন্ধ করুন">
        <?= pay_status_chip($summary) ?><span class="text-[10px] text-indigo-600 font-semibold"> ▾</span>
    </button>
    <?php
}

// ── খাতার প্যানেল — সারির ঠিক নিচে পুরো চওড়া জুড়ে (ডানে স্ক্রল করা লাগে না)।
// খাতা না থাকলে ব্যাচের কনফিগ থেকে **প্রস্তাবিত** কিস্তি দেখায় (DB-তে তখনো কিছু লেখা হয় না —
// GET-এ কখনো লেখা নয়); সেভ চাপলে তবেই সারিগুলো তৈরি হয়।
function reg_pay_panel(PDO $db, array $row, array $ledger, string $returnUrl, int $colspan): void
{
    $rid     = (int) $row['id'];
    $isNew   = !$ledger;
    $rows    = $isNew ? pay_build_plan($db, $row) : $ledger;

    // 🔴 খাতা এখনো সেভ হয়নি অথচ আগেই আয় অনুমোদিত (পুরনো নিয়মে কনফার্ম করা) — তখন
    // প্রস্তাবিত কিস্তিতে ঐ টাকাটা জমা হিসেবে বসিয়ে দেওয়া হয়। নাহলে জমা ০ দেখাত আর
    // সেভ করলে আয় নীরবে ০ হয়ে যেত (আয় = খাতার মোট জমা)।
    $prefill = ($isNew && !empty($row['income_approved'])) ? (float) ($row['income_amount'] ?? 0) : 0.0;
    if ($prefill > 0) {
        $rows = pay_allocate_paid($rows, $prefill);
    }
    $summary = pay_summary($rows);
    $note    = (string) ($row['admin_note'] ?? '');
    $warn    = $isNew
        ? 'true'
        : "confirmSubmit(this, 'টাকার খাতার হিসাব বদলে সংরক্ষণ করতে চান?', 'পরিবর্তনের নিশ্চিতকরণ')";
    $isLegacy = pay_is_legacy_only($rows);
    $money   = fn($v) => number_format((float) $v, (fmod((float) $v, 1) == 0.0) ? 0 : 2);

    // ── ধাপ ৪: সারিগুলো ডিফল্টে কমপ্যাক্ট (নাম · নিট · জমা) — প্রাপ্য/ছাড়/⊘ বাদ/✕ থাকে
    // "⚙️ বিস্তারিত"-এর পিছনে। 🔴 তবে ঐ ঘরগুলোতে **আগে থেকে কিছু লেখা থাকলে কখনো লুকানো
    // হয় না** (ছাড় দেওয়া আছে, বা কোনো মাস ⊘ বাদ) — নাহলে "নিট কেন কম দেখাচ্ছে" সেটা
    // বোঝার উপায়ই থাকত না।
    $hasExtras = false;
    foreach ($rows as $r0) {
        if (pay_is_skipped($r0) || (float) ($r0['discount_value'] ?? 0) > 0) {
            $hasExtras = true;
            break;
        }
    }
    // "নতুন টাকা জমা নিন" ঘরের শর্টকাট-চিপ = প্রথম যে কিস্তিতে এখনো বাকি আছে তার বাকিটা
    $nextDue = 0.0;
    foreach ($rows as $r0) {
        if (pay_is_skipped($r0)) { continue; }
        $left = round(pay_net($r0) - (float) ($r0['amount_paid'] ?? 0), 2);
        if ($left > 0.01) { $nextDue = $left; break; }
    }
    ?>
    <tr class="reg-pay-row" id="pay-<?= $rid ?>" hidden>
        <td colspan="<?= $colspan ?>" style="text-align:left;padding:0">
            <?php // 💰 ধাপ ৪ — সবচেয়ে সাধারণ কাজটা (টাকা জমা নেওয়া) সবার উপরে, একটাই ঘর।
                  // 🔴 এটা নিচের খাতা-ফর্মের **ভেতরে নয়, আলাদা `<form>`** — HTML-এ নেস্টেড ফর্ম
                  //    চলেই না, আর নিচের ফর্ম সেভ করলে সব সারি নতুন করে লেখে (জমা-হীন সারি
                  //    মুছেও ফেলে), তাই দুটো আলাদা রাখাই নিরাপদ। ?>
            <form method="post" action="registrations.php?action=pay-collect" class="pay-quick-form"
                  style="background:#EEF2FF;border-top:2px solid #C7D2FE;padding:1rem 1rem 0"
                  onsubmit="return payQuickConfirm(this);">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $rid ?>">
                <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">
                <div class="bg-white rounded-xl p-3" style="max-width:760px">
                    <div class="flex flex-wrap items-end" style="column-gap:.75rem;row-gap:.5rem">
                        <label class="text-xs text-gray-500 font-semibold" style="min-width:0">💰 নতুন টাকা জমা নিন (৳)
                            <input type="number" step="any" min="0" name="amount" value="" placeholder="যেমন 500"
                                   class="pay-quick-amt block w-full border-2 border-indigo-200 rounded-lg px-3 py-2 text-base font-bold text-gray-800" style="max-width:9rem">
                        </label>
                        <button type="submit" class="bg-green-600 text-white font-bold px-4 py-2 rounded-xl text-sm whitespace-nowrap">✅ জমা বসান</button>
                        <?php if ($nextDue > 0.01): ?>
                            <?php // শর্টকাট — অঙ্কটা **অনুমান নয়**, খাতার পরের বাকি কিস্তির নিজের বাকিটাই ?>
                            <button type="button" class="pay-quick-chip text-xs font-semibold text-indigo-700 bg-indigo-50 rounded-lg px-2 py-1.5 whitespace-nowrap"
                                    data-amt="<?= e((string) $nextDue) ?>" title="পরের বাকি কিস্তির অঙ্কটা বসিয়ে দিন">৳<?= $money($nextDue) ?> বসান</button>
                        <?php endif; ?>
                        <span class="text-xs font-bold ml-auto <?= $summary['balance'] > 0 ? 'text-red-600' : 'text-green-700' ?>">
                            <?= $summary['balance'] > 0 ? 'এখন বাকি ৳' . $money($summary['balance']) : '✅ পুরো পেইড' ?>
                        </span>
                    </div>
                    <p class="text-[11px] text-gray-500 mt-2">
                        যা লিখবেন সেটা <strong>আগের জমার সাথে যোগ</strong> হবে — উপরের কিস্তি থেকে নিচে বসবে, বাড়তি থাকলে শেষ কিস্তিতে (advance)।
                        <?php /* 🚪 ধাপ ২ (২০২৬-১০-১৫): তিন জায়গা থেকেই টাকা বসানো যায়, আর তিনটেই
                               এখন একটাই শেয়ার্ড `pay_collect()` ডাকে — অ্যাডমিনকে "কোনটা দিয়ে
                               বসাব" ভাবতে হয় না। এই লাইনটা নিছক লেখা, কিছুই বদলায় না। */ ?>
                        <br><span class="text-indigo-700">🚪 এখান থেকে, <strong>কোর্স পার্সেল</strong> থেকে, আর <strong>পেমেন্ট ইনবক্স</strong> থেকে — তিন জায়গায় টাকা বসালে হিসাব হুবহু একই হয়।</span>
                        <?php if ($isNew): ?> <strong>খাতা এখনো সেভ হয়নি — টাকা বসালে কিস্তিগুলোও একসাথে তৈরি হয়ে যাবে।</strong><?php endif; ?>
                    </p>
                </div>
            </form>

            <form method="post" action="registrations.php?action=pay-save" class="pay-form<?= $hasExtras ? '' : ' pay-lean' ?> p-4" data-rid="<?= $rid ?>"
                  style="background:#EEF2FF" onsubmit="return <?= $warn ?>;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $rid ?>">
                <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">

                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <h4 class="font-bold text-gray-800 text-sm">🧾 কিস্তির খাতা — <?= e($row['customer_name']) ?></h4>
                        <?php // ⚙️ প্রাপ্য/ছাড়/⊘ বাদ/✕ দেখানো-লুকানো। ইচ্ছাকৃতভাবে **মনে রাখা হয় না**
                              // (localStorage নয়) — প্রতিটা ড্রয়ার আলাদা করে খোলা হয়, আর ছাড়/বাদ
                              // থাকা খাতা সার্ভারেই খোলা অবস্থায় আসে। ?>
                        <button type="button" class="pay-detail-toggle text-xs font-semibold px-3 py-1 rounded-lg bg-white border border-gray-200 text-gray-600"
                                title="প্রাপ্য, ছাড়, ⊘ বাদ ও ✕ মুছুন — দেখানো/লুকানো">⚙️ বিস্তারিত</button>
                    </div>
                    <?php if ($isNew): ?>
                        <span class="text-xs text-indigo-700 font-semibold">কোর্সের সেটিংস দেখে কিস্তিগুলো বসানো হয়েছে — মিলিয়ে নিয়ে সেভ করুন<?php if ($prefill > 0): ?> (আগে অনুমোদিত আয় ৳<?= $money($prefill) ?> জমা হিসেবে বসানো)<?php endif; ?></span>
                    <?php else: ?>
                        <?php // 🔑 সেভ করা খাতা নিজে থেকে কখনো বদলায় না (টাকার হিসাব নীরবে নড়তে পারে না) —
                              // ব্যাচের ফি/কিস্তির সেটিংস বদলালে অ্যাডমিন নিজে এই বোতামে নতুন করে বসান। ?>
                        <button type="button" class="pay-rebuild text-xs font-bold px-3 py-1 rounded-lg bg-amber-100 text-amber-800"
                                title="কোর্সের বর্তমান ফি, মাস ও কিস্তি-সংখ্যা দেখে কিস্তিগুলো নতুন করে বসাবে (মোট জমা অপরিবর্তিত থাকবে)">🧩 কিস্তির ছকে সাজান</button>
                    <?php endif; ?>
                </div>

                <?php if (!$isNew && !$isLegacy): ?>
                    <p class="text-xs bg-amber-50 text-amber-800 rounded-xl px-3 py-2 mb-2">
                        এই খাতা <strong>আগেই সেভ করা</strong> — কোর্সের ফি বা কিস্তির সেটিংস পরে বদলালে এখানে নিজে থেকে বসে না।
                        উপরের <strong>“🧩 কিস্তির ছকে সাজান”</strong> চাপলে বর্তমান সেটিংস অনুযায়ী কিস্তিগুলো নতুন করে বসবে।
                        <strong>মোট জমা ও আয় বদলাবে না।</strong>
                    </p>
                <?php endif; ?>

                <?php if ($isLegacy): ?>
                    <p class="text-xs bg-amber-50 text-amber-800 rounded-xl px-3 py-2 mb-2">
                        এটা <strong>পুরনো হিসাব</strong> — আগে অনুমোদন করা আয়ের পরিমাণটা এক সারিতে বসানো আছে।
                        উপরের <strong>“🧩 কিস্তির ছকে সাজান”</strong> চাপলে কোর্সের রেজিস্ট্রেশন ফি ও মাস অনুযায়ী কিস্তিগুলো বসবে,
                        আর এই জমা টাকাটা উপর থেকে নিচে ভাগ হয়ে যাবে। <strong>মোট জমা ও আয় বদলাবে না।</strong>
                    </p>
                <?php endif; ?>

                <?php foreach ($rows as $idx => $r):
                    $net = pay_net($r);
                    $st  = pay_row_status($r);
                    // এই কিস্তি কোন কালেকশন-মাস ঢাকে — ঘর দুটো খালি থাকলে (মাইগ্রেশনের আগে সেভ
                    // হওয়া সারি) লেবেল পড়ে বের করে hidden-এ বসানো হয়, যাতে লেবেল বদলালেও
                    // কুরিয়ারের মাসের সাথে মিল হারিয়ে না যায়।
                    $rMonths = pay_row_months($r);
                    $mFrom   = $rMonths ? (int) $rMonths[0] : 0;
                    $mTo     = $rMonths ? (int) end($rMonths) : 0;
                    ?>
                    <div class="pay-row bg-white rounded-xl p-3 mb-2<?= $st === 'skipped' ? ' opacity-60' : '' ?>">
                        <input type="hidden" name="p[<?= $idx ?>][id]" value="<?= (int) $r['id'] ?>">
                        <input type="hidden" class="pay-skip" name="p[<?= $idx ?>][is_skipped]" value="<?= $st === 'skipped' ? 1 : 0 ?>">
                        <input type="hidden" name="p[<?= $idx ?>][seq]" value="<?= (int) $r['seq'] ?>">
                        <input type="hidden" name="p[<?= $idx ?>][kind]" value="<?= e($r['kind']) ?>">
                        <input type="hidden" name="p[<?= $idx ?>][month_from]" value="<?= $mFrom ?>">
                        <input type="hidden" name="p[<?= $idx ?>][month_to]" value="<?= $mTo ?>">
                        <div class="flex flex-wrap items-center" style="column-gap:.75rem;row-gap:.5rem">
                            <label class="text-xs text-gray-500 pay-name">কিস্তির নাম
                                <input type="text" name="p[<?= $idx ?>][label]" value="<?= e($r['label']) ?>" maxlength="100"
                                       class="pay-label block border rounded-lg px-2 py-1 text-sm font-bold text-gray-800" style="width:9.5rem">
                            </label>

                            <label class="text-xs text-gray-500 pay-x">প্রাপ্য
                                <input type="number" step="any" min="0" name="p[<?= $idx ?>][amount_due]" value="<?= e((string) round((float) $r['amount_due'], 2)) ?>"
                                       class="pay-due block border rounded-lg px-2 py-1 text-sm text-gray-800" style="width:6rem">
                            </label>

                            <label class="text-xs text-gray-500 pay-x">ছাড়
                                <span class="flex gap-1">
                                    <input type="number" step="any" min="0" name="p[<?= $idx ?>][discount_value]" value="<?= e((string) round((float) $r['discount_value'], 2)) ?>"
                                           class="pay-disc border rounded-lg px-2 py-1 text-sm" style="width:4.5rem">
                                    <select name="p[<?= $idx ?>][discount_type]" class="pay-disc-type border rounded-lg px-1 py-1 text-sm">
                                        <option value="fixed" <?= $r['discount_type'] !== 'percent' ? 'selected' : '' ?>>৳</option>
                                        <option value="percent" <?= $r['discount_type'] === 'percent' ? 'selected' : '' ?>>%</option>
                                    </select>
                                </span>
                            </label>

                            <span class="text-xs text-gray-500">নিট<br><span class="pay-net font-bold text-gray-800 text-sm">৳<?= $money($net) ?></span></span>

                            <label class="text-xs text-gray-500">জমা
                                <input type="number" step="any" min="0" name="p[<?= $idx ?>][amount_paid]" value="<?= e((string) round((float) $r['amount_paid'], 2)) ?>"
                                       class="pay-paid block border rounded-lg px-2 py-1 text-sm font-semibold" style="width:6rem">
                            </label>

                            <span class="pay-badge inline-block px-2 py-1 rounded-lg text-xs font-semibold <?= $st === 'paid' ? 'bg-green-100 text-green-800' : ($st === 'partial' ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500') ?>">
                                <?= $st === 'paid' ? '✅ পেইড' : ($st === 'partial' ? '◐ আংশিক' : ($st === 'skipped' ? '⊘ বাদ' : '○ বাকি')) ?>
                            </span>

                            <button type="button" class="pay-fill text-xs font-semibold text-indigo-600" title="নিট পরিমাণটা জমায় বসিয়ে দিন">পুরোটা জমা</button>
                            <?php // মাঝপথে কোর্স ছেড়ে দিলে বাকি মাসগুলো "বাদ" — তখন ঐ কিস্তি প্রাপ্য/বাকিতে গোনা হয় না ?>
                            <button type="button" class="pay-skip-btn pay-x text-xs font-semibold text-gray-500" title="এই মাসটা আর প্রযোজ্য নয় (কোর্স ছেড়ে দিয়েছে)"><?= $st === 'skipped' ? '↩ ফেরাও' : '⊘ বাদ' ?></button>
                            <?php // 🔴 "বাদ" থেকে আলাদা: বাদ = সারিটা থাকে কিন্তু হিসাবে ধরা হয় না; মুছুন = সারিটাই আর থাকবে না ?>
                            <button type="button" class="pay-del pay-x text-xs font-semibold text-red-600 ml-auto" title="এই কিস্তির সারিটাই মুছে ফেলুন (সংরক্ষণ করলে কার্যকর হবে)">✕</button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php // একজন অভিভাবকের ব্যবস্থা আলাদা হলে তাঁর খাতায় বাড়তি কিস্তি যোগ করা যায় (শুধু এই একজনের) ?>
                <template class="pay-tpl"><div class="pay-row bg-white rounded-xl p-3 mb-2">
                        <input type="hidden" name="p[__IDX__][id]" value="0">
                        <input type="hidden" class="pay-skip" name="p[__IDX__][is_skipped]" value="0">
                        <input type="hidden" name="p[__IDX__][seq]" value="__SEQ__">
                        <input type="hidden" name="p[__IDX__][kind]" value="other">
                        <input type="hidden" name="p[__IDX__][month_from]" value="0">
                        <input type="hidden" name="p[__IDX__][month_to]" value="0">
                        <div class="flex flex-wrap items-center" style="column-gap:.75rem;row-gap:.5rem">
                            <label class="text-xs text-gray-500 pay-name">কিস্তির নাম
                                <input type="text" name="p[__IDX__][label]" value="" maxlength="100" placeholder="যেমন: বাড়তি কিস্তি"
                                       class="pay-label block border rounded-lg px-2 py-1 text-sm font-bold text-gray-800" style="width:9.5rem">
                            </label>
                            <label class="text-xs text-gray-500 pay-x">প্রাপ্য
                                <input type="number" step="any" min="0" name="p[__IDX__][amount_due]" value="0"
                                       class="pay-due block border rounded-lg px-2 py-1 text-sm text-gray-800" style="width:6rem">
                            </label>
                            <label class="text-xs text-gray-500 pay-x">ছাড়
                                <span class="flex gap-1">
                                    <input type="number" step="any" min="0" name="p[__IDX__][discount_value]" value="0"
                                           class="pay-disc border rounded-lg px-2 py-1 text-sm" style="width:4.5rem">
                                    <select name="p[__IDX__][discount_type]" class="pay-disc-type border rounded-lg px-1 py-1 text-sm">
                                        <option value="fixed" selected>৳</option>
                                        <option value="percent">%</option>
                                    </select>
                                </span>
                            </label>
                            <span class="text-xs text-gray-500">নিট<br><span class="pay-net font-bold text-gray-800 text-sm">৳0</span></span>
                            <label class="text-xs text-gray-500">জমা
                                <input type="number" step="any" min="0" name="p[__IDX__][amount_paid]" value="0"
                                       class="pay-paid block border rounded-lg px-2 py-1 text-sm font-semibold" style="width:6rem">
                            </label>
                            <span class="pay-badge inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-500">○ বাকি</span>
                            <button type="button" class="pay-fill text-xs font-semibold text-indigo-600" title="নিট পরিমাণটা জমায় বসিয়ে দিন">পুরোটা জমা</button>
                            <button type="button" class="pay-skip-btn pay-x text-xs font-semibold text-gray-500" title="এই মাসটা আর প্রযোজ্য নয়">⊘ বাদ</button>
                            <button type="button" class="pay-del pay-x text-xs font-semibold text-red-600 ml-auto" title="এই কিস্তির সারিটাই মুছে ফেলুন">✕</button>
                        </div>
                    </div></template>
                <div class="pay-rows-end pay-x mb-2">
                    <button type="button" class="pay-add text-xs font-bold text-indigo-600">+ কিস্তি যোগ করুন</button>
                    <span class="text-xs text-gray-400">— শুধু এই একজনের খাতায় (অন্য কারো বদলাবে না)</span>
                </div>

                <div class="flex flex-wrap items-center gap-3 bg-white rounded-xl p-3 mb-3 text-sm">
                    <span class="text-gray-500">মোট প্রাপ্য <strong class="pay-t-due text-gray-800">৳<?= $money($summary['due']) ?></strong></span>
                    <span class="text-gray-500">ছাড় <strong class="pay-t-disc" style="color:#7e22ce">৳<?= $money($summary['discount']) ?></strong></span>
                    <span class="text-gray-500">নিট <strong class="pay-t-net text-gray-800">৳<?= $money($summary['net']) ?></strong></span>
                    <span class="text-gray-500">জমা <strong class="pay-t-paid text-green-700">৳<?= $money($summary['paid']) ?></strong></span>
                    <span class="pay-t-bal font-bold <?= $summary['balance'] > 0 ? 'text-red-600' : 'text-green-700' ?>">
                        <?= $summary['balance'] > 0 ? 'বাকি ৳' . $money($summary['balance']) : '✅ পুরো পেইড' ?>
                    </span>
                    <button type="button" class="pay-same-disc pay-x text-xs font-semibold text-indigo-600 ml-auto" title="প্রথম মাসের ছাড়টা বাকি সব মাসে বসিয়ে দিন">সব মাসে একই ছাড়</button>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <?php // 📝 নোট লেখা থাকলে ঘরটা নিজেই অ্যাম্বার হয়ে যায় (CSS, টাইপ করার সাথে সাথেই) —
                          // 🔴 `placeholder=" "` ইচ্ছাকৃত: `:placeholder-shown` দিয়েই "ঘরটা খালি কিনা" বোঝা হয়,
                          //    তাই কোনো JS লাগে না। প্লেসহোল্ডারটা একটা স্পেস, তাই চোখে পড়ে না। ?>
                    <label class="text-xs text-gray-500 flex-1" style="min-width:12rem">📝 নোট (কুরিয়ার নোট থেকে আলাদা)
                        <input type="text" name="admin_note" value="<?= e($note) ?>" placeholder=" " maxlength="500"
                               class="pay-note block w-full border rounded-lg px-3 py-2 text-sm">
                        <span class="pay-note-flag">✓ নোট লেখা আছে — তালিকায় এই অর্ডারে <b>📝 নোট</b> চিহ্ন দেখাবে</span>
                    </label>
                    <button type="submit" class="bg-indigo-600 text-white font-bold px-5 py-2 rounded-xl text-sm">সংরক্ষণ করুন</button>
                </div>
                <p class="text-[11px] text-gray-400 mt-2">🔴 আয়-ব্যয় পেজের “আয়” এই খাতার <strong>মোট জমা</strong> থেকেই আসে — জমার ঘর বদলালে আয়ও বদলাবে।</p>
            </form>
        </td>
    </tr>
    <?php
}

require __DIR__ . '/includes/layout-top.php';
?>
<style>
    /* 💰 খাতার প্যানেল কমপ্যাক্ট অবস্থায় (ধাপ ৪) — প্রাপ্য/ছাড়/⊘ বাদ/✕ লুকানো থাকে।
       🔴 `display:none` ইচ্ছাকৃত, `disabled` নয় — ইনপুটগুলো তবু সাবমিট হয়; নাহলে সেভ করলে
       pay_save_rows() ঐ সারিগুলোর প্রাপ্য/ছাড় হারিয়ে ফেলত। */
    form.pay-form.pay-lean .pay-x { display: none !important; }

    /* 📱 মোবাইলে ফিল্টার ফর্মের বাকি ঘরগুলো ভাঁজ (২০২৬-১০-১৫) — আগে স্ট্যাটাস/টাইপ পিল +
       ফিল্টার ফর্ম + সারাংশ কার্ড মিলে ১,১২৪px (১.৩ স্ক্রিন) পেরিয়ে তবে প্রথম নামটা আসত।
       সার্চ বাক্স ও "🔍 খুঁজুন" সবসময় দেখা যায়, বাকিটা "⚙️ আরও ফিল্টার"-এ।
       🔴 `display:none` ইচ্ছাকৃত, `disabled` নয় — লুকানো ঘরগুলোও সাবমিট হয়, তাই
          চলতি ফিল্টার হারায় না (খাতার `.pay-x`-এর হুবহু একই নীতি)।
       🔴 `flt-lean` ক্লাসটা HTML-এ নেই — নিচের স্ক্রিপ্ট শুধু ছোট পর্দায় বসায়, তাই JS না
          চললে সব ঘরই দেখা যায় (আগের আচরণ)। */
    .flt-more { display: none; }
    @media screen and (max-width: 767px) {
        .flt-more { display: inline-flex; align-items: center; }
        #regFilterForm.flt-lean .flt-x { display: none; }
    }
    #regFilterForm.flt-lean .flt-close { display: none; }
    #regFilterForm:not(.flt-lean) .flt-open { display: none; }
    /* কমপ্যাক্ট অবস্থায় জায়গা খালি থাকে, তাই কিস্তির নামের ঘরটা বাকিটুকু নিয়ে নেয় —
       নাহলে "বেতন (কিস্তি 1/2 — 1.5 মাস)" জাতীয় নাম কেটে যেত (স্ক্রিনশটে ধরা)। */
    form.pay-form.pay-lean .pay-name { flex: 1 1 11rem; min-width: 0; max-width: 24rem; }
    form.pay-form.pay-lean .pay-label { width: 100% !important; }

    /* 📝 নোটের ঘর — কিছু লেখা থাকলেই অ্যাম্বার (ইউজারের চাওয়া: "এখানে কিছু লিখলে যাতে
       হাইলাইট থাকে")। 🔴 `:placeholder-shown` দিয়ে খালি-কিনা দেখা হয়, তাই ইনপুটে
       `placeholder=" "` থাকা **বাধ্যতামূলক** — সরালে হাইলাইট নীরবে কাজ করা বন্ধ করবে।
       🔴 `:has()` ব্যবহার করা হয়নি (এই কোডবেসে ওটার উপর ভরসা করা হয় না) — ইঙ্গিতের
       লেখাটা ইনপুটের **পরে** রাখা হয়েছে যাতে সাধারণ `~` সিলেক্টরেই চলে। */
    .pay-note:not(:placeholder-shown) {
        background: #FFFBEB;
        border-color: #F59E0B;
        box-shadow: inset 3px 0 0 #F59E0B;
        color: #78350F;
        font-weight: 600;
    }
    .pay-note-flag { display: none; margin-top: .25rem; font-size: 11px; font-weight: 700; color: #B45309; }
    .pay-note:not(:placeholder-shown) ~ .pay-note-flag { display: block; }
</style>

<?php if ($action === 'list'): ?>
    <div class="flex flex-wrap gap-2 mb-3">
        <a href="<?= e(reg_url(['status' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === '' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>">সব স্ট্যাটাস</a>
        <?php foreach ($statusLabels as $key => $s): ?>
            <a href="<?= e(reg_url(['status' => $key])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === $key ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($s[0]) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="<?= e(reg_url(['type' => null, 'item' => null, 'batch' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === '' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>">সব টাইপ</a>
        <?php foreach ($typeLabels as $key => $label): ?>
            <a href="<?= e(reg_url(['type' => $key, 'item' => null, 'batch' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === $key ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="registrations.php" id="regFilterForm" class="mb-3 bg-white rounded-2xl shadow p-4">
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
        <?php if ($filterType): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif; ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-gray-500 mb-1">খোঁজা — সব তথ্য দিয়ে</label>
                <div class="flex gap-2 items-center">
                    <input type="text" name="q" value="<?= e($search) ?>" placeholder="নাম / ফোন / রিসিভার / ঠিকানা / ব্যাচ / নোট…" id="regSearchInput" data-autosearch="regFilterForm" class="flex-1 min-w-0 border rounded-xl px-3 py-2.5 text-sm">
                    <button type="submit" class="flex-shrink-0 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl text-sm whitespace-nowrap">🔍 খুঁজুন</button>
                </div>
                <button type="button" class="flt-more mt-2 gap-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-3 py-2 rounded-xl text-sm"
                        onclick="this.closest('form').classList.toggle('flt-lean')">
                    <span class="flt-open">⚙️ আরও ফিল্টার</span><span class="flt-close">✕ ফিল্টার লুকান</span>
                </button>
                <p class="flt-x text-[11px] text-gray-500 mt-1">🔎 শিশুর নাম · মা/বাবার মোবাইল · রিসিভারের নাম ও নম্বর · ফেসবুক আইডি · ঠিকানা · জেলা/থানা · ব্যাচ · কোর্সের নাম · ইমেইল · নোট · কনসাইনমেন্ট আইডি · অর্ডার নম্বর — যেকোনোটা দিয়ে খোঁজা যায় (বাংলা অঙ্কেও)।</p>
            </div>
            <div class="flt-x">
                <label class="block text-xs font-semibold text-gray-500 mb-1">নির্দিষ্ট আইটেম</label>
                <select name="item" id="regItemSelect" onchange="document.getElementById('regBatchSelect').value=''; document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব আইটেম</option>
                    <?php foreach ($distinctItems as $itemTitle): ?>
                        <option value="<?= e($itemTitle) ?>" <?= $filterItem === $itemTitle ? 'selected' : '' ?>><?= e($itemTitle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flt-x">
                <label class="block text-xs font-semibold text-gray-500 mb-1">ব্যাচ (কোর্স)</label>
                <select name="batch" id="regBatchSelect" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব ব্যাচ</option>
                    <?php foreach ($distinctBatches as $b): ?>
                        <option value="<?= e($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flt-x">
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ থেকে</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div class="flt-x">
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ পর্যন্ত</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
        </div>
        <div class="flt-x flex flex-wrap items-center gap-2 mt-3">
            <?php if ($hasActiveFilters): ?>
                <a href="registrations.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ সব ফিল্টার মুছুন</a>
            <?php endif; ?>
            <?php // 📤 উপরের ফিল্টার যা দেখাচ্ছে ঠিক সেটাই নামে — পেজিনেশন ছাড়া পুরোটা।
                  // 🔴 লিংকটা `reg_url()` থেকে, তাই নতুন ফিল্টার যোগ করলে এখানে কিছু করতে হয় না। ?>
            <a href="<?= e(reg_url(['export' => 'csv'])) ?>"
               class="bg-green-50 hover:bg-green-100 text-green-700 border border-green-200 font-semibold px-4 py-2.5 rounded-xl text-sm">
                📤 Excel/CSV ডাউনলোড<?= $hasActiveFilters ? ' (এই ফিল্টারের)' : '' ?>
            </a>
            <span class="text-xs text-gray-400">ফাইলটা Excel / Google Sheets দুটোতেই খোলে।</span>
        </div>
    </form>

    <?php
    // ── উপরের সারাংশ কার্ড (২০২৬-০৯-২৩): শুধু "মোট N টি ফলাফল" লাইনের বদলে এক নজরে
    // কয়টা পেন্ডিং/কনফার্ম, কত টাকা বাকি, কত আয় অনুমোদিত। প্রথম তিনটা কার্ড ক্লিকযোগ্য
    // (স্ট্যাটাস ফিল্টার বসায়); সংখ্যাগুলো স্ট্যাটাস **বাদে** বাকি ফিল্টার মেনে গণনা করা।
    $sumCards = [
        ['key' => '',          'label' => 'সব অর্ডার', 'value' => (int) ($listSummary['all_rows'] ?? $totalRows), 'color' => 'text-gray-800'],
        ['key' => 'pending',   'label' => 'পেন্ডিং',   'value' => (int) ($listSummary['pending'] ?? 0),           'color' => 'text-amber-600'],
        ['key' => 'confirmed', 'label' => 'কনফার্ম',   'value' => (int) ($listSummary['confirmed'] ?? 0),         'color' => 'text-green-600'],
    ];
    ?>
    <script>
    // 📱 ছোট পর্দায় ফিল্টার ফর্মটা ভাঁজ করা থাকে — সার্চ বাক্সই যথেষ্ট।
    // 🔴 আইটেম/ব্যাচ/তারিখের কোনো ফিল্টার চালু থাকলে ভাঁজ করা হয় না — নাহলে
    //    "কেন কম ফলাফল দেখাচ্ছে" সেটা বোঝার উপায় থাকত না।
    (function () {
        try {
            var f = document.getElementById('regFilterForm');
            var advOn = <?= ($filterItem !== '' || $filterBatch !== '' || $dateFrom !== '' || $dateTo !== '') ? 'true' : 'false' ?>;
            if (f && !advOn && window.matchMedia('(max-width: 767px)').matches) { f.classList.add('flt-lean'); }
        } catch (e) {}
    })();
    </script>

    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
        <?php foreach ($sumCards as $card): $isOn = ($filterStatus === $card['key']); ?>
            <a href="<?= e(reg_url(['status' => $card['key'] !== '' ? $card['key'] : null])) ?>"
               class="block bg-white rounded-2xl shadow p-3 <?= $isOn ? 'border border-indigo-400' : '' ?>">
                <div class="text-[11px] text-gray-500 font-semibold"><?= e($card['label']) ?></div>
                <div class="text-2xl font-bold <?= $card['color'] ?>"><?= $card['value'] ?></div>
            </a>
        <?php endforeach; ?>
        <?php
        // 💰💰 "মোট বাকি" কার্ডটা এখন **বোতামও** (২০২৬-১০-১৫, ইউজারের চাওয়া: "কারোর
        // ডিউ রাখলে সেটা বোঝার সহজ উপায় রাখো")। চাপলে `?due=1` — শুধু যাঁদের টাকা
        // বাকি আছে তাঁরাই তালিকায় থাকেন, প্রতিটা সারির "টাকা" চিপে অঙ্কটা লেখা।
        // 🔴 বাকির অঙ্ক নতুন করে হিসাব করা হয়নি — খাতা থেকেই (`due_amount`)।
        // 🔴 বাকি কেউ না থাকলে ক্লিকযোগ্য হয় না (খালি তালিকা দেখানোর মানে নেই)।
        $dueRows = (int) ($listSummary['due_rows'] ?? 0);
        $dueLink = $hasDueCol && ($dueRows > 0 || $filterDue);
        $dueTotal = '৳' . number_format((float) ($listSummary['due_total'] ?? 0), 0);
        ?>
        <?php if ($dueLink): ?>
            <a href="<?= e(reg_url(['due' => $filterDue ? null : '1'])) ?>"
               class="block bg-white rounded-2xl shadow p-3 <?= $filterDue ? 'border border-indigo-400' : '' ?>"
               title="<?= $filterDue ? 'সবাইকে আবার দেখুন' : 'শুধু যাঁদের টাকা বাকি আছে তাঁদের দেখুন' ?>">
                <div class="text-[11px] text-gray-500 font-semibold">মোট বাকি<?= $filterDue ? ' ✓' : '' ?></div>
                <div class="text-xl font-bold text-red-600"><?= $dueTotal ?></div>
                <div class="text-[10px] font-semibold text-red-600 mt-0.5">
                    <?= $filterDue ? '← সবাইকে দেখুন' : $dueRows . ' জনের বাকি — দেখুন' ?>
                </div>
            </a>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow p-3">
                <div class="text-[11px] text-gray-500 font-semibold">মোট বাকি</div>
                <div class="text-xl font-bold text-red-600"><?= $dueTotal ?></div>
                <?php if ($hasDueCol): ?>
                    <div class="text-[10px] font-semibold text-green-700 mt-0.5">কারো বাকি নেই ✅</div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <div class="bg-white rounded-2xl shadow p-3">
            <div class="text-[11px] text-gray-500 font-semibold">অনুমোদিত আয়</div>
            <div class="text-xl font-bold text-green-700">৳<?= number_format((float) ($listSummary['income_total'] ?? 0), 0) ?></div>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">
        <?php if ($filterDue): ?><span class="font-semibold text-red-600">💰 শুধু যাঁদের বাকি আছে —</span> <?php endif; ?>
        এই ফিল্টারে <strong><?= $totalRows ?></strong> টি ফলাফল<?= $totalPages > 1 ? " — পৃষ্ঠা {$page}/{$totalPages}" : '' ?>
        <?php if ($filterDue): ?><span class="text-gray-400">(সবচেয়ে বেশি বাকি আগে)</span><?php endif; ?>
    </p>

    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <?php if ($filterType === 'course'): ?>
            <!-- কোর্স: ১০ কলাম (আগে ১৬ ছিল) — নাম/ফোন/ঠিকানা এক ঘরে, আইটেম+ব্যাচ এক ঘরে,
                 আর জন্ম তারিখ/ফেসবুক/বাবার মোবাইল/রিসিভার তথ্য "ℹ️ তথ্য" ড্রয়ারে (২০২৬-০৯-২৩) -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">শিক্ষার্থী</th>
                    <th class="py-3 px-4">আইটেম / ব্যাচ</th>
                    <th class="py-3 px-4">ফেসবুক আইডি নাম</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">গ্রুপ</th>
                    <th class="py-3 px-4">টাকা</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">তথ্য</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="reg-<?= (int) $row['id'] ?>" class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_fb_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_group_cell($row, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>&return=<?= rawurlencode($currentListUrl) ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 10); ?>
                <?php reg_more_panel($row, 10, $currentListUrl); ?>
            <?php endforeach; ?>
            </tbody>
            <?php elseif ($filterType === 'worksheet' || $filterType === 'product'): ?>
            <!-- ওয়ার্কশিট/প্রোডাক্ট: ৮ কলাম (আগে ১১) — ইমেইল/জেলা/থানা/পরিমাণ তথ্য-ড্রয়ারে -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">ক্রেতা</th>
                    <th class="py-3 px-4">আইটেম</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">টাকা</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">তথ্য</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="8" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="reg-<?= (int) $row['id'] ?>" class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>&return=<?= rawurlencode($currentListUrl) ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 8); ?>
                <?php reg_more_panel($row, 8, $currentListUrl); ?>
            <?php endforeach; ?>
            </tbody>
            <?php else: ?>
            <!-- "সব টাইপ" — মিশ্র টাইপ, তাই দুই ফর্মেই কমন ফিল্ড; নির্দিষ্ট টাইপ ফিল্টার করলে উপরের ভিউ -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">নাম</th>
                    <th class="py-3 px-4">আইটেম / ব্যাচ</th>
                    <th class="py-3 px-4">ফেসবুক আইডি নাম</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">গ্রুপ</th>
                    <th class="py-3 px-4">টাকা</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">তথ্য</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr id="reg-<?= (int) $row['id'] ?>" class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_fb_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_group_cell($row, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>&return=<?= rawurlencode($currentListUrl) ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 10); ?>
                <?php reg_more_panel($row, 10, $currentListUrl); ?>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="flex flex-wrap items-center justify-center gap-1.5 mt-5">
            <a href="<?= e(reg_url(['page' => max(1, $page - 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page <= 1 ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">‹ আগে</a>
            <?php
                $rangeStart = max(1, $page - 2);
                $rangeEnd = min($totalPages, $page + 2);
                if ($rangeStart > 1) {
                    echo '<a href="' . e(reg_url(['page' => 1])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">1</a>';
                    if ($rangeStart > 2) { echo '<span class="px-1 text-gray-400">…</span>'; }
                }
                for ($p = $rangeStart; $p <= $rangeEnd; $p++) {
                    $active = $p === $page;
                    echo '<a href="' . e(reg_url(['page' => $p])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold ' . ($active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 shadow') . '">' . $p . '</a>';
                }
                if ($rangeEnd < $totalPages) {
                    if ($rangeEnd < $totalPages - 1) { echo '<span class="px-1 text-gray-400">…</span>'; }
                    echo '<a href="' . e(reg_url(['page' => $totalPages])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">' . $totalPages . '</a>';
                }
            ?>
            <a href="<?= e(reg_url(['page' => min($totalPages, $page + 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page >= $totalPages ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">পরে ›</a>
        </div>
    <?php endif; ?>

<?php elseif ($action === 'view'): $s = $statusLabels[$viewRow['status']] ?? ['?', 'bg-gray-100']; ?>
    <div class="max-w-2xl bg-white rounded-2xl shadow p-6 space-y-5">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?= e($viewRow['item_title']) ?></h3>
                <p class="text-gray-500 text-sm">রেফারেন্স #<?= $viewRow['id'] ?> • <?= e($typeLabels[$viewRow['type']] ?? $viewRow['type']) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $s[1] ?>"><?= e($s[0]) ?></span>
                <a href="registrations.php?action=edit&id=<?= $viewRow['id'] ?>" class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-300 rounded-lg px-3 py-1.5">
                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i> সম্পাদনা
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <?php if ($viewRow['type'] === 'course'): ?>
                <div><span class="text-gray-500">শিশুর নাম:</span> <span class="font-semibold"><?= e($viewRow['customer_name']) ?></span></div>
                <div><span class="text-gray-500">মোবাইল (মা):</span> <span class="font-semibold"><?= e($viewRow['phone']) ?></span></div>
                <div><span class="text-gray-500">জন্ম তারিখ:</span> <span class="font-semibold"><?= $viewRow['date_of_birth'] ? e(format_date_bn($viewRow['date_of_birth'])) : '-' ?></span></div>
                <div><span class="text-gray-500">ফেসবুক আইডি:</span> <span class="font-semibold"><?= e($viewRow['facebook_id'] ?? '-') ?></span></div>
                <div><span class="text-gray-500">মোবাইল (বাবা):</span> <span class="font-semibold"><?= e($viewRow['father_mobile'] ?: '-') ?></span></div>
                <?php if ($viewRow['receiver_name'] || $viewRow['receiver_phone'] || $viewRow['address']): ?>
                    <div><span class="text-gray-500">রিসিভার নাম:</span> <span class="font-semibold"><?= e($viewRow['receiver_name'] ?: '-') ?></span></div>
                    <div><span class="text-gray-500">রিসিভার নম্বর:</span> <span class="font-semibold"><?= e($viewRow['receiver_phone'] ?: '-') ?></span></div>
                    <div class="sm:col-span-2"><span class="text-gray-500">ঠিকানা:</span> <span class="font-semibold"><?= e($viewRow['address'] ?: '-') ?></span></div>
                <?php else: ?>
                    <div class="sm:col-span-2 text-gray-500 italic">এই কোর্সে পার্সেল/ডেলিভারি তথ্য প্রযোজ্য নয় (ফুল অনলাইন কোর্স)।</div>
                <?php endif; ?>
            <?php else: ?>
                <div><span class="text-gray-500">নাম:</span> <span class="font-semibold"><?= e($viewRow['customer_name']) ?></span></div>
                <div><span class="text-gray-500">ফোন:</span> <span class="font-semibold"><?= e($viewRow['phone']) ?></span></div>
                <div><span class="text-gray-500">ইমেইল:</span> <span class="font-semibold"><?= e($viewRow['email'] ?? '-') ?></span></div>
                <div><span class="text-gray-500">পরিমাণ:</span> <span class="font-semibold"><?= (int) $viewRow['quantity'] ?></span></div>
                <div class="sm:col-span-2"><span class="text-gray-500">ঠিকানা:</span> <span class="font-semibold"><?= e(implode(', ', array_filter([$viewRow['address'], $viewRow['thana'], $viewRow['district']]))) ?></span></div>
            <?php endif; ?>
            <?php // 📝 নোট দুটো অ্যাম্বার বাক্সে — সাধারণ ঘরের মতো দেখালে চোখে পড়ত না ?>
            <?php foreach (reg_notes_of($viewRow) as $vnLabel => $vnText): ?>
                <div class="sm:col-span-2 bg-amber-50 border border-amber-200 rounded-xl px-3 py-2 text-amber-800 break-words"
                     style="box-shadow:inset 3px 0 0 #F59E0B">
                    <b><?= e($vnLabel) ?>:</b> <?= e($vnText) ?>
                </div>
            <?php endforeach; ?>
            <div><span class="text-gray-500">তারিখ:</span> <span class="font-semibold"><?= e($viewRow['created_at']) ?></span></div>
        </div>

        <form method="post" action="registrations.php?action=update-status" class="flex gap-3 items-end pt-2 border-t" data-original="<?= e($viewRow['status']) ?>" onsubmit="return confirmStatusFormSubmit(this)">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
            <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
            <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-700 mb-1">স্ট্যাটাস পরিবর্তন করুন</label>
                <div class="relative">
                    <select name="status" class="w-full appearance-none border rounded-xl pl-4 pr-9 py-2.5 font-medium cursor-pointer hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-colors">
                        <?php foreach ($statusLabels as $key => $lbl): ?>
                            <option value="<?= e($key) ?>" <?= $viewRow['status'] === $key ? 'selected' : '' ?>><?= e($lbl[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400"></i>
                </div>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">আপডেট করুন</button>
        </form>
        <p class="text-xs text-gray-400 -mt-3">স্ট্যাটাস "কনফার্ম"/"পাঠানো হয়েছে"/"ডেলিভার্ড" করলে স্বয়ংক্রিয়ভাবে আয় যোগ হয়ে যাবে (দাম × পরিমাণ অনুযায়ী)।</p>

        <?php // গ্রুপে যোগ + বকেয়া/নোট — তালিকার সাথে হুবহু একই উইজেট (শেয়ার্ড ফাংশন), তাই দুই জায়গায় অবস্থা সবসময় এক ?>
        <?php $viewUrlSelf = 'registrations.php?action=view&id=' . (int) $viewRow['id']; ?>
        <div class="pt-4 border-t space-y-3">
            <?php if ($viewRow['type'] === 'course'): ?>
                <div>
                    <h4 class="text-sm font-bold text-gray-700 mb-2">গ্রুপে যোগ হয়েছে?</h4>
                    <?php reg_group_cell($viewRow, $viewUrlSelf); ?>
                </div>
            <?php endif; ?>
            <div>
                <h4 class="text-sm font-bold text-gray-700 mb-2">টাকার খাতা</h4>
                <?php // তালিকার সাথে হুবহু একই প্যানেল (শেয়ার্ড ফাংশন) — একটা লুকানো টেবিলে মুড়ে, যাতে <tr> বৈধ থাকে ?>
                <table class="w-full"><tbody>
                    <?php
                    // খাতাটা একবারই তোলা হয় — নিচের "আয়" বক্সের ইঙ্গিতেও এটাই লাগে
                    $viewLedger = pay_fetch_many($db, [(int) $viewRow['id']])[(int) $viewRow['id']] ?? [];
                    reg_pay_panel($db, $viewRow, $viewLedger, $viewUrlSelf, 1);
                    ?>
                </tbody></table>
                <script>document.getElementById('pay-<?= (int) $viewRow['id'] ?>').hidden = false;</script>
            </div>
        </div>

        <?php // কুরিয়ার নোট — এখানে দেওয়া নোট "কুরিয়ার পার্সেল প্রস্তুত" পেজের কার্ডে অটোমেটিক দেখা যাবে ?>
        <div class="pt-4 border-t">
            <div class="flex items-center justify-between gap-2 mb-2">
                <h4 class="text-sm font-bold text-gray-700"><i data-lucide="sticky-note" class="w-4 h-4 inline text-amber-500"></i> কুরিয়ার নোট</h4>
                <span class="text-xs text-gray-400">প্রস্তুত পেজে অটো দেখাবে</span>
            </div>
            <div class="flex flex-wrap gap-2 mb-3">
                <?php if (!$viewNotes): ?><span class="text-sm text-gray-400">কোনো নোট নেই।</span><?php endif; ?>
                <?php render_note_chips($viewNotes, 'registrations.php?action=del-note', (int) $viewRow['id'], 'registrations.php?action=view&id=' . (int) $viewRow['id']); ?>
            </div>
            <form method="post" action="registrations.php?action=add-note" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="registration_id" value="<?= $viewRow['id'] ?>">
                <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                <?php if ($noteTypes): ?>
                <label class="text-xs text-gray-500">তৈরি নোট
                    <select name="note_type_id" class="block w-full border rounded-xl px-3 py-2.5 text-sm mt-1">
                        <option value="0">— কাস্টম লিখুন —</option>
                        <?php foreach ($noteTypes as $nt): ?><option value="<?= (int) $nt['id'] ?>"><?= e($nt['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="text-xs text-gray-500">কাস্টম নোট
                    <input type="text" name="custom_text" placeholder="যেমন: আগের বকেয়া ২০" class="block w-full border rounded-xl px-3 py-2.5 text-sm mt-1">
                </label>
                <div class="flex gap-2">
                    <select name="color" class="border rounded-xl px-2 py-2.5 text-sm" aria-label="রঙ"><?php foreach (courier_note_colors() as $ck => $cv): ?><option value="<?= $ck ?>"><?= $cv ?></option><?php endforeach; ?></select>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-4 py-2.5 rounded-xl text-sm whitespace-nowrap">যোগ</button>
                </div>
            </form>
        </div>

        <div class="pt-4 border-t">
            <?php if ($viewRow['income_approved']): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-green-800">✅ আয় হিসেবে যোগ হয়েছে (<?= e($viewRow['approved_at']) ?>)</p>
                        <form method="post" action="registrations.php?action=unapprove-income" onsubmit="return confirmSubmit(this, 'আয় থেকে বাদ দিতে চান? সংশ্লিষ্ট আয়ের এন্ট্রিও মুছে যাবে।');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                            <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                            <button type="submit" class="text-red-600 text-xs font-semibold underline">আয় থেকে বাদ দিন</button>
                        </form>
                    </div>
                    <form method="post" action="registrations.php?action=update-income-amount" class="flex gap-2 items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                        <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">পরিমাণ পরিবর্তন করুন (৳)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" value="<?= e(number_format((float) $viewRow['income_amount'], 2, '.', '')) ?>" class="border rounded-lg px-3 py-2 text-sm w-36">
                        </div>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2 rounded-lg">আপডেট</button>
                    </form>
                </div>
            <?php else: ?>
                <?php
                // 🔴 আগে এখানে লেখা ছিল "স্ট্যাটাস কনফার্ম করলে অটোমেটিক যোগ হয়ে যাবে" — ২০২৬-০৯-২৯
                // থেকে সেটা আর সত্যি নয় (কনফার্ম করলে আয় বসে না, আয় আসে খাতার মোট জমা থেকে)।
                // ইউজার ঐ লেখাটা পড়ে অপেক্ষা করছিলেন, অথচ অর্ডারটা আগেই কনফার্ম করা ছিল।
                $viewPaid = pay_paid_total($viewLedger ?? []);
                ?>
                <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                    <p class="font-bold mb-1">এই অর্ডারটি এখনো আয় হিসেবে গণনা হয়নি</p>
                    <?php if (!$viewLedger): ?>
                        <p>উপরের <strong>💰 টাকার খাতা</strong>-য় কিস্তিগুলো মিলিয়ে নিয়ে, যে কিস্তিতে টাকা এসেছে তার
                           <strong>“জমা”</strong> ঘরে অঙ্কটা লিখে <strong>“সংরক্ষণ করুন”</strong> চাপুন — ততটুকুই আয়ে যাবে।</p>
                    <?php elseif ($viewPaid <= 0): ?>
                        <p>খাতা বসানো আছে, কিন্তু কোনো কিস্তিতে <strong>জমা 0</strong>। উপরের খাতায় যে কিস্তিতে টাকা এসেছে
                           (যেমন রেজিস্ট্রেশন ফি) তার <strong>“জমা”</strong> ঘরে অঙ্কটা লিখে <strong>“সংরক্ষণ করুন”</strong> চাপলেই আয়ে যোগ হবে।</p>
                    <?php else: ?>
                        <p>স্ট্যাটাস কনফার্ম/পাঠানো/ডেলিভার্ড না হলে জমা থাকলেও আয় বইয়ে ওঠে না — উপরে স্ট্যাটাসটা দেখুন।</p>
                    <?php endif; ?>
                    <p class="text-xs mt-2">🔴 শুধু “কনফার্ম” করলে আয় বসে না — যত টাকা সত্যিই হাতে এসেছে, ততটুকুই খাতা থেকে আয়ে যায়।</p>
                </div>
            <?php endif; ?>
        </div>

        <div class="pt-4 border-t">
            <?php if ($viewRow['type'] === 'course' && !$viewRow['address']): ?>
                <p class="text-sm text-gray-500 italic">এই রেজিস্ট্রেশনে পার্সেল/ডেলিভারি তথ্য নেই (ফুল অনলাইন কোর্স), তাই কুরিয়ারে পাঠানোর দরকার নেই।</p>
            <?php elseif (!$courierConfigured): ?>
                <p class="text-sm text-gray-500">কুরিয়ারে পাঠাতে চাইলে আগে <a href="settings.php" class="text-indigo-600 font-semibold">সাইট সেটিংস</a> এ গিয়ে কুরিয়ার প্রোভাইডার ও API Key/Secret বসান।</p>
            <?php elseif (in_array($viewRow['status'], ['shipped', 'delivered'], true)): ?>
                <p class="text-sm text-green-700">এই অর্ডারটি ইতিমধ্যে কুরিয়ারে পাঠানো হয়েছে<?= $viewRow['courier_consignment_id'] ? ' (Consignment ID: ' . e($viewRow['courier_consignment_id']) . ')' : '' ?>।</p>
            <?php else: ?>
                <form method="post" action="send-to-courier.php" onsubmit="return confirmSubmit(this, 'এই অর্ডারটি কুরিয়ারে পাঠাতে চান?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm">📦 কুরিয়ারে পাঠান (<?= e(get_setting('courier_active_provider')) ?>)</button>
                </form>
            <?php endif; ?>

            <?php if ($shipments): ?>
            <div class="mt-4 space-y-2">
                <p class="text-sm font-semibold text-gray-700">কুরিয়ার লগ:</p>
                <?php foreach ($shipments as $sh): ?>
                <div class="text-xs bg-gray-50 rounded-lg p-3">
                    <span class="font-semibold"><?= e($sh['provider']) ?></span> —
                    <?= $sh['status'] === 'created' ? '<span class="text-green-700">সফল</span>' : '<span class="text-red-700">ব্যর্থ</span>' ?>
                    <?php if ($sh['tracking_url']): ?> — <a href="<?= e($sh['tracking_url']) ?>" target="_blank" class="text-indigo-600">ট্র্যাক করুন</a><?php endif; ?>
                    <span class="text-gray-400">(<?= e($sh['created_at']) ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between pt-2">
            <?php // 🔴 ফিল্টার ধরে রেখে ফেরা (২০২৬-০৯-২৯) — আগে হার্ডকোড `registrations.php` ছিল, তাই
      //    বিস্তারিতে ঢুকে ফিরলে সার্চ/স্ট্যাটাস/পাতা সব হারিয়ে যেত। তালিকার "বিস্তারিত"
      //    লিংক এখন `&return=<তালিকার URL>` বয়ে আনে; `safe_return_url()` সেটা যাচাই করে
      //    (registrations.php/course-data.php ছাড়া কিছু মানে না), আর `#reg-<id>` নোঙরে
      //    ঠিক ঐ সারিতেই নামে। ?>
<a href="<?= e(reg_anchor_url(safe_return_url($_GET['return'] ?? null, 'registrations.php'), (int) $viewRow['id'])) ?>" class="inline-block text-gray-500 text-sm">← তালিকায় ফিরে যান</a>
            <form method="post" action="registrations.php?action=delete" onsubmit="return confirmSubmit(this, 'এই রেজিস্ট্রেশন/অর্ডারটি আর্কাইভে সরাতে চান? আয়ের এন্ট্রি ও কুরিয়ার ব্যাচ সহ পুরোটা আর্কাইভে যাবে — পরে আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।', 'আর্কাইভ নিশ্চিতকরণ');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                <input type="hidden" name="return_url" value="registrations.php">
                <button type="submit" class="text-red-600 text-sm font-semibold">ডিলিট করুন</button>
            </form>
        </div>
    </div>

<?php elseif ($action === 'edit'):
    $hasParcel = $viewRow['type'] === 'course'
        ? ($viewRow['receiver_name'] !== null || $viewRow['receiver_phone'] !== null || $viewRow['address'] !== null)
        : true;
?>
    <div class="max-w-2xl bg-white rounded-2xl shadow p-6 space-y-5">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900">তথ্য সম্পাদনা করুন</h3>
                <p class="text-gray-500 text-sm"><?= e($viewRow['item_title']) ?> • রেফারেন্স #<?= $viewRow['id'] ?></p>
            </div>
        </div>

        <form method="post" action="registrations.php?action=update-details" onsubmit="return confirmSubmit(this, 'আপনি কি এই তথ্য পরিবর্তন করে সংরক্ষণ করতে চান?', 'তথ্য পরিবর্তনের নিশ্চিতকরণ')" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">

            <?php if ($viewRow['type'] === 'course'): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">শিশুর নাম</label>
                        <input type="text" name="customer_name" value="<?= e($viewRow['customer_name']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল (মা)</label>
                        <input type="text" name="phone" value="<?= e($viewRow['phone']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">জন্ম তারিখ</label>
                        <input type="date" name="date_of_birth" value="<?= e($viewRow['date_of_birth'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ফেসবুক আইডি নাম</label>
                        <input type="text" name="facebook_id" value="<?= e($viewRow['facebook_id'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল (বাবা) — ঐচ্ছিক</label>
                        <input type="text" name="father_mobile" value="<?= e($viewRow['father_mobile'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                </div>
                <?php if ($hasParcel): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">রিসিভার নাম</label>
                        <input type="text" name="receiver_name" value="<?= e($viewRow['receiver_name'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">রিসিভার মোবাইল</label>
                        <input type="text" name="receiver_phone" value="<?= e($viewRow['receiver_phone'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ঠিকানা</label>
                        <textarea name="address" rows="2" required class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['address'] ?? '') ?></textarea>
                    </div>
                </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500 italic pt-2 border-t">এই কোর্সে পার্সেল/ডেলিভারি তথ্য প্রযোজ্য নয় (ফুল অনলাইন কোর্স)।</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">নাম</label>
                        <input type="text" name="customer_name" value="<?= e($viewRow['customer_name']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ফোন</label>
                        <input type="text" name="phone" value="<?= e($viewRow['phone']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ইমেইল — ঐচ্ছিক</label>
                        <input type="email" name="email" value="<?= e($viewRow['email'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">পরিমাণ</label>
                        <input type="number" name="quantity" min="1" value="<?= (int) $viewRow['quantity'] ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">জেলা — ঐচ্ছিক</label>
                        <select name="district" class="w-full border rounded-xl px-4 py-2.5">
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach (bd_districts() as $d): ?>
                                <option value="<?= e($d) ?>" <?= $viewRow['district'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">থানা/উপজেলা — ঐচ্ছিক</label>
                        <input type="text" name="thana" value="<?= e($viewRow['thana'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ঠিকানা</label>
                        <textarea name="address" rows="2" required class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['address'] ?? '') ?></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">মন্তব্য — ঐচ্ছিক</label>
                <textarea name="notes" rows="2" class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['notes'] ?? '') ?></textarea>
            </div>

            <div class="flex items-center gap-3 pt-2 border-t">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">পরিবর্তন সংরক্ষণ করুন</button>
                <a href="registrations.php?action=view&id=<?= $viewRow['id'] ?>" class="text-gray-500 text-sm font-semibold">বাতিল</a>
            </div>
        </form>
    </div>

    <?php // 🔄 অন্য কোর্সে সরানো — শুধু কোর্সের রেজিস্ট্রেশনে (২০২৬-০৯-২৮)
          // 🔴 ইচ্ছাকৃতভাবে উপরের ফর্মের **বাইরে** আলাদা কার্ড ও আলাদা <form> — নাহলে
          //    "তথ্য সংরক্ষণ" চাপলেই কোর্সও বদলে যেত। HTML-এ নেস্টেড ফর্মও চলে না।
          if ($viewRow['type'] === 'course'):
        $mvBatches = [];
        $mvLedger  = false;
        $mvSent    = 0;
        try {
            $mvBatches = $db->query(
                'SELECT cb.id, cb.batch_name, cb.price, c.title
                   FROM course_batches cb JOIN courses c ON c.id = cb.course_id
                  WHERE cb.is_active = 1
                  ORDER BY c.title ASC, cb.sort_order ASC, cb.id ASC'
            )->fetchAll();
            $mvLedger = (bool) (pay_fetch_many($db, [(int) $viewRow['id']])[(int) $viewRow['id']] ?? []);
            // আগে পাঠানো চালান — সরালেও এগুলো অপরিবর্তিত থাকে, তাই সংখ্যাটা দেখিয়ে দেওয়া
            $mvSentQ = $db->prepare("SELECT COUNT(*) FROM courier_batches WHERE registration_id = :id AND send_status = 'sent'");
            $mvSentQ->execute(['id' => (int) $viewRow['id']]);
            $mvSent = (int) $mvSentQ->fetchColumn();
        } catch (PDOException $mvEx) {
            $mvBatches = [];   // কোনো কারণে না পারলে কার্ডটাই দেখাব না
        }
        if ($mvBatches):
    ?>
    <div class="max-w-2xl bg-white rounded-2xl shadow p-6 mt-6 border-2 border-amber-200">
        <h3 class="text-lg font-bold text-gray-900">🔄 অন্য কোর্সে সরান</h3>
        <p class="text-gray-500 text-sm mt-1">ভুল কোর্সে রেজিস্ট্রেশন হয়ে গেলে এখান থেকেই ঠিক কোর্সে সরিয়ে দিন — নতুন করে রেজিস্ট্রেশন করার দরকার নেই।</p>

        <div class="mt-4 text-sm bg-gray-50 rounded-xl px-4 py-3">
            <span class="text-gray-500">এখন আছে:</span>
            <b class="text-gray-900"><?= e($viewRow['item_title']) ?></b><?= $viewRow['batch'] ? ' <span class="text-gray-600">(' . e($viewRow['batch']) . ')</span>' : '' ?>
        </div>

        <form method="post" action="registrations.php?action=move-course" class="mt-4 space-y-4"
              onsubmit="return confirmSubmit(this, 'এই রেজিস্ট্রেশনটি সত্যিই অন্য কোর্সে সরাতে চান? জমা দেওয়া টাকা ও আগে পাঠানো পার্সেল অপরিবর্তিত থাকবে।', 'কোর্স পরিবর্তনের নিশ্চিতকরণ')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">যে কোর্সে সরাবেন</label>
                <select name="new_item_id" required class="w-full border rounded-xl px-4 py-2.5">
                    <option value="">নির্বাচন করুন</option>
                    <?php foreach ($mvBatches as $mb): if ((int) $mb['id'] === (int) $viewRow['item_id']) { continue; } ?>
                        <option value="<?= (int) $mb['id'] ?>">
                            <?= e($mb['title']) ?><?= $mb['batch_name'] ? ' — ' . e($mb['batch_name']) : '' ?><?= trim((string) $mb['price']) !== '' ? ' · ' . e($mb['price']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-500 mt-1">শুধু সক্রিয় কোর্স-ব্যাচগুলো দেখাচ্ছে। রেজিস্ট্রেশন বন্ধ থাকলেও সরানো যাবে।</p>
            </div>

            <?php if ($mvLedger): ?>
            <label class="flex items-start gap-2.5 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 cursor-pointer">
                <input type="checkbox" name="rebuild_ledger" value="1" checked class="mt-0.5">
                <span class="text-sm text-amber-800">
                    <b>নতুন কোর্সের ফি অনুযায়ী টাকার খাতা নতুন করে সাজান</b>
                    <span class="block text-xs mt-0.5">🔴 <b>জমা দেওয়া টাকা এক পয়সাও বদলাবে না</b> — শুধু প্রাপ্য ও কিস্তির ছক নতুন কোর্সের নিয়মে বসবে। টিক না দিলে খাতা আগের কোর্সের ফি ধরেই থেকে যাবে।</span>
                </span>
            </label>
            <?php endif; ?>

            <div class="text-xs text-gray-600 bg-gray-50 rounded-xl px-4 py-3 space-y-1">
                <div>✅ <b>যা অপরিবর্তিত থাকবে</b>: জমা দেওয়া টাকা · আগে পাঠানো পার্সেল ও চালান · গ্রুপের টিক · নোট · অভিভাবকের অ্যাকাউন্ট</div>
                <div>🔄 <b>যা বদলাবে</b>: কোর্সের নাম ও ব্যাচ — তালিকা, কোর্স পার্সেল ও অভিভাবকের ড্যাশবোর্ড সবখানেই নতুন কোর্সটাই দেখাবে</div>
                <?php if ($mvSent > 0): ?>
                    <div class="text-red-700">⚠️ এই অর্ডারে <b><?= (int) $mvSent ?> টি পার্সেল আগেই কুরিয়ারে পাঠানো হয়েছে</b> — ওগুলোর চালান আগের কোর্সের হিসাবেই থেকে যাবে (বদলানো যায় না)।</div>
                <?php endif; ?>
            </div>

            <button type="submit" class="bg-amber-600 hover:bg-amber-700 text-white font-bold px-6 py-2.5 rounded-xl">এই কোর্সে সরিয়ে দিন</button>
        </form>
    </div>
    <?php endif; endif; ?>
<?php endif; ?>

<script>
    // showConfirmModal() ও confirmSubmit() admin/includes/layout-bottom.php তে ডিফাইন করা (পুরো অ্যাডমিন প্যানেলে শেয়ার্ড)
    const INCOME_STATUSES = <?= json_encode(INCOME_STATUSES) ?>;
    const STATUS_LABELS = <?= json_encode(array_map(fn($l) => $l[0], $statusLabels)) ?>;

    function statusChangeConfirmMessage(original) {
        return 'এই অর্ডারটি ইতিমধ্যে "' + STATUS_LABELS[original] + '" অবস্থায় আছে এবং আয়ের হিসাবে যুক্ত থাকতে পারে। স্ট্যাটাস পরিবর্তন করলে আয়ের হিসাবও বদলে যেতে পারে। আপনি কি নিশ্চিত?';
    }

    // সার্চ বক্সের অটো-ফিল্টার এখন শেয়ার্ড: ইনপুটের data-autosearch="regFilterForm" দেখে
    // admin/includes/layout-bottom.php-এর adminAutoSearch() নিজেই ওয়্যার করে নেয়
    // (ডেস্কটপে লেখা থামলে, মোবাইলে Enter/🔍 বোতামে — কেন সেভাবে, তা ওখানের কমেন্টে লেখা)

    // লিস্ট পেজের ইনলাইন ড্রপডাউন — onchange এ কল হয়
    function confirmStatusChange(select) {
        const original = select.dataset.original;
        const newValue = select.value;

        if (INCOME_STATUSES.includes(original) && newValue !== original) {
            select.value = original; // যতক্ষণ না Confirm করছে ততক্ষণ আগের অবস্থায় দেখাবে
            showConfirmModal(statusChangeConfirmMessage(original), function () {
                select.value = newValue;
                select.form.submit();
            }, 'স্ট্যাটাস পরিবর্তনের নিশ্চিতকরণ');
            return;
        }
        select.form.submit();
    }

    // ডিটেইল পেজের স্ট্যাটাস ফর্ম — onsubmit এ কল হয়
    function confirmStatusFormSubmit(form) {
        const original = form.dataset.original;
        const select = form.querySelector('select[name="status"]');

        if (INCOME_STATUSES.includes(original) && select.value !== original) {
            showConfirmModal(statusChangeConfirmMessage(original), function () {
                form.submit();
            }, 'স্ট্যাটাস পরিবর্তনের নিশ্চিতকরণ');
            return false; // মডাল থেকে Confirm না দেওয়া পর্যন্ত সরাসরি সাবমিট আটকানো
        }
        return true;
    }

    // ── টাকার খাতা: প্যানেল টগল + লাইভ হিসাব (সার্ভারেও একই হিসাব হয়, এটা শুধু চোখের জন্য)
    function togglePayPanel(id) {
        var el = document.getElementById('pay-' + id);
        if (el) { el.hidden = !el.hidden; }
    }

    // ── ℹ️ তথ্য-ড্রয়ার (খাতার প্যানেলের হুবহু একই কায়দায়) — কম দরকারি ফিল্ডগুলো
    // কলাম না বাড়িয়ে সারির নিচে দেখায়
    function toggleInfoPanel(id) {
        var el = document.getElementById('info-' + id);
        if (el) { el.hidden = !el.hidden; }
    }

    (function () {
        var money = function (v) { return '৳' + (Math.round(v * 100) / 100).toLocaleString('en-US'); };
        var num = function (el) { var v = parseFloat(el && el.value); return isNaN(v) || v < 0 ? 0 : v; };

        // একটা কিস্তির ছাড়/নিট/অবস্থা
        function rowCalc(row) {
            var skipped = (row.querySelector('.pay-skip') || {}).value === '1';
            if (skipped) { return { due: 0, disc: 0, net: 0, paid: num(row.querySelector('.pay-paid')), skipped: true }; }
            var due = num(row.querySelector('.pay-due'));
            var dv = num(row.querySelector('.pay-disc'));
            var dt = row.querySelector('.pay-disc-type');
            var disc = dv <= 0 || due <= 0 ? 0 : (dt && dt.value === 'percent' ? due * Math.min(100, dv) / 100 : dv);
            disc = Math.max(0, Math.min(due, disc));
            var net = Math.max(0, due - disc);
            var paid = num(row.querySelector('.pay-paid'));
            return { due: due, disc: disc, net: net, paid: paid };
        }

        function refresh(form) {
            var tDue = 0, tDisc = 0, tNet = 0, tPaid = 0;
            form.querySelectorAll('.pay-row').forEach(function (row) {
                var c = rowCalc(row);
                tDue += c.due; tDisc += c.disc; tNet += c.net; tPaid += c.paid;
                var netEl = row.querySelector('.pay-net');
                if (netEl) { netEl.textContent = money(c.net); }
                var b = row.querySelector('.pay-badge');
                if (b) {
                    var paidFull = c.paid >= c.net;
                    b.textContent = c.skipped ? '⊘ বাদ' : (paidFull ? '✅ পেইড' : (c.paid > 0 ? '◐ আংশিক' : '○ বাকি'));
                    b.className = 'pay-badge inline-block px-2 py-1 rounded-lg text-xs font-semibold '
                        + (c.skipped ? 'bg-gray-100 text-gray-500'
                           : (paidFull ? 'bg-green-100 text-green-800' : (c.paid > 0 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-500')));
                }
                row.classList.toggle('opacity-60', !!c.skipped);
                var sb = row.querySelector('.pay-skip-btn');
                if (sb) { sb.textContent = c.skipped ? '↩ ফেরাও' : '⊘ বাদ'; }
            });
            var bal = Math.max(0, tNet - tPaid);
            var set = function (sel, txt) { var el = form.querySelector(sel); if (el) { el.textContent = txt; } };
            set('.pay-t-due', money(tDue)); set('.pay-t-disc', money(tDisc));
            set('.pay-t-net', money(tNet)); set('.pay-t-paid', money(tPaid));
            var balEl = form.querySelector('.pay-t-bal');
            if (balEl) {
                balEl.textContent = bal > 0 ? ('বাকি ' + money(bal)) : '✅ পুরো পেইড';
                balEl.className = 'pay-t-bal font-bold ' + (bal > 0 ? 'text-red-600' : 'text-green-700');
            }
        }

        // 💰 "নতুন টাকা জমা নিন" — অঙ্ক যাচাই + একবার নিশ্চিতকরণ (টাকা ও আয় দুটোই বাড়ে)
        window.payQuickConfirm = function (form) {
            var el = form.querySelector('.pay-quick-amt');
            var v = parseFloat(el && el.value);
            if (isNaN(v) || v <= 0) {
                if (el) { el.focus(); }
                return false;   // খালি/০ হলে কিছুই পাঠানো হয় না
            }
            return confirmSubmit(form,
                'এই অর্ডারের খাতায় ' + money(v) + ' জমা হিসেবে যোগ হবে (আগের জমা অক্ষত থাকবে) — উপরের কিস্তি থেকে নিচে বসবে, '
                + 'আর বইয়ের আয়ও ঐ পরিমাণ বাড়বে।', 'টাকা জমা নেবেন?');
        };

        // শর্টকাট চিপ — পরের বাকি কিস্তির অঙ্কটা ঘরে বসায় (সেভ হয় না, শুধু বসে)
        document.querySelectorAll('form.pay-quick-form').forEach(function (form) {
            form.addEventListener('click', function (ev) {
                var chip = ev.target.closest('.pay-quick-chip');
                if (!chip) { return; }
                var el = form.querySelector('.pay-quick-amt');
                if (el) { el.value = chip.dataset.amt || ''; el.focus(); }
            });
        });

        document.querySelectorAll('form.pay-form').forEach(function (form) {
            refresh(form);

            // ⚙️ "বিস্তারিত" — প্রাপ্য/ছাড়/⊘ বাদ/✕ দেখানো-লুকানো (শুধু এই প্যানেলে)
            var tog = form.querySelector('.pay-detail-toggle');
            if (tog) {
                var paint = function () {
                    var lean = form.classList.contains('pay-lean');
                    tog.textContent = lean ? '⚙️ বিস্তারিত' : '⚙️ কমপ্যাক্ট';
                };
                paint();
                tog.addEventListener('click', function () {
                    form.classList.toggle('pay-lean');
                    paint();
                });
            }
            form.addEventListener('input', function () { refresh(form); });
            form.addEventListener('change', function () { refresh(form); });

            form.addEventListener('click', function (ev) {
                // "পুরোটা জমা" — ঐ কিস্তির নিট পরিমাণটা জমার ঘরে বসায়
                var fill = ev.target.closest('.pay-fill');
                if (fill) {
                    var row = fill.closest('.pay-row');
                    var paidEl = row.querySelector('.pay-paid');
                    if (paidEl) { paidEl.value = rowCalc(row).net; refresh(form); }
                    return;
                }
                // "⊘ বাদ" / "↩ ফেরাও" — মাঝপথে ছেড়ে দেওয়া মাস প্রাপ্যের হিসাব থেকে বাদ
                var skipBtn = ev.target.closest('.pay-skip-btn');
                if (skipBtn) {
                    var srow = skipBtn.closest('.pay-row');
                    var sf = srow.querySelector('.pay-skip');
                    if (sf) { sf.value = sf.value === '1' ? '0' : '1'; refresh(form); }
                    return;
                }
                // "🧩 কিস্তির ছকে সাজান" — পুরনো এক-সারির খাতা কোর্সের সেটিংস দেখে কিস্তিতে ভাগ করে
                if (ev.target.closest('.pay-rebuild')) {
                    showConfirmModal(
                        'কোর্সের বর্তমান সেটিংস (রেজিস্ট্রেশন ফি ও মাস) দেখে কিস্তিগুলো নতুন করে বসানো হবে। '
                        + 'এখনকার সারিগুলো মুছে যাবে, তবে মোট জমা টাকা ও আয়ের হিসাব অপরিবর্তিত থাকবে।',
                        function () {
                            form.action = 'registrations.php?action=pay-rebuild';
                            form.submit();
                        },
                        'খাতা নতুন করে সাজাবেন?'
                    );
                    return;
                }
                // "+ কিস্তি যোগ করুন" — এই একজনের খাতায় একটা বাড়তি খালি সারি
                if (ev.target.closest('.pay-add')) {
                    var tpl = form.querySelector('.pay-tpl');
                    var end = form.querySelector('.pay-rows-end');
                    if (!tpl || !end) { return; }
                    // ইনডেক্স কখনো পুনরাবৃত্তি হবে না (মুছে ফেলার পরেও) — নাহলে PHP-তে একটা সারি
                    // আরেকটাকে চাপা দিয়ে দিত
                    form.dataset.payNext = String(parseInt(form.dataset.payNext || '0', 10) + 1);
                    var idx = 'n' + form.dataset.payNext;
                    var seq = form.querySelectorAll('.pay-row').length + 1;
                    var html = tpl.innerHTML.split('__IDX__').join(idx).split('__SEQ__').join(String(seq));
                    var box = document.createElement('div');
                    box.innerHTML = html;
                    var node = box.firstElementChild;
                    end.parentNode.insertBefore(node, end);
                    var nameEl = node.querySelector('.pay-label');
                    if (nameEl) { nameEl.focus(); }
                    refresh(form);
                    return;
                }
                // "✕" — সারিটা ফর্ম থেকে সরানো; সংরক্ষণ করলে DB থেকেও মুছবে
                var delBtn = ev.target.closest('.pay-del');
                if (delBtn) {
                    var drow = delBtn.closest('.pay-row');
                    if (!drow) { return; }
                    if (form.querySelectorAll('.pay-row').length < 2) { return; } // শেষ সারিটা রাখা হয়
                    var paidOnRow = parseFloat((drow.querySelector('.pay-paid') || {}).value) || 0;
                    var drop = function () { drow.remove(); refresh(form); };
                    if (paidOnRow > 0) {
                        // 🔴 জমা থাকা সারি মুছলে মোট জমা (= আয়) কমে যাবে — তাই আগে সতর্কতা
                        showConfirmModal(
                            'এই কিস্তিতে জমা ' + money(paidOnRow) + ' লেখা আছে। সারিটা মুছে সংরক্ষণ করলে মোট জমা ও আয় ঐ পরিমাণ কমে যাবে।',
                            drop, 'জমা থাকা কিস্তি মুছবেন?'
                        );
                    } else {
                        drop();
                    }
                    return;
                }
                // "সব মাসে একই ছাড়" — প্রথম মাসের ছাড় বাকি সব মাসে কপি (রেজিস্ট্রেশন ফি অছোঁয়া থাকে)
                if (ev.target.closest('.pay-same-disc')) {
                    var monthly = Array.prototype.filter.call(form.querySelectorAll('.pay-row'), function (r) {
                        return (r.querySelector('input[name*="[kind]"]') || {}).value === 'monthly';
                    });
                    if (monthly.length < 2) { return; }
                    var srcV = monthly[0].querySelector('.pay-disc').value;
                    var srcT = monthly[0].querySelector('.pay-disc-type').value;
                    monthly.slice(1).forEach(function (r) {
                        r.querySelector('.pay-disc').value = srcV;
                        r.querySelector('.pay-disc-type').value = srcT;
                    });
                    refresh(form);
                }
            });
        });
    })();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
