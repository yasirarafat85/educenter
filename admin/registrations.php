<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/courier-notes.php';
require_once __DIR__ . '/includes/payments.php';
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

// যে স্ট্যাটাসগুলোতে থাকলে অর্ডারটা আয় হিসেবে গণনা হবে — এর বাইরে গেলে আয় সরে যাবে
const INCOME_STATUSES = ['confirmed', 'shipped', 'delivered'];

// ফর্ম থেকে পাঠানো return_url শুধুমাত্র চেনা অ্যাডমিন পেজের মধ্যেই থাকা নিশ্চিত করা (open-redirect ঠেকাতে)
// course-data.php ও এখান থেকে (একই action=delete/update-details ব্যবহার করে) রিটার্ন করতে পারে
function safe_return_url(?string $url, string $fallback): string
{
    $allowedPrefixes = ['registrations.php', 'course-data.php'];
    foreach ($allowedPrefixes as $prefix) {
        if ($url && strpos($url, $prefix) === 0) {
            return $url;
        }
    }
    return $fallback;
}

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
    // 🔴 খাতা থাকলে আয় খাতা থেকেই হয় (ধাপ ২)। খাতা না থাকলে নিচের পুরনো নিয়ম চলে —
    // তাই যেসব রেজিস্ট্রেশনে এখনো খাতা তৈরি হয়নি সেগুলোর হিসাব আগের মতোই অক্ষত থাকে।
    $ledger = pay_fetch_many($db, [(int) $reg['id']])[(int) $reg['id']] ?? [];
    if ($ledger) {
        pay_write_income($db, $reg, $newStatus, pay_paid_total($ledger));
        return;
    }

    $shouldHaveIncome = in_array($newStatus, INCOME_STATUSES, true);

    if ($shouldHaveIncome && !$reg['income_approved']) {
        // কোর্সের দাম এখন course_batches-এ (courses parent টেবিলে শুধু title) — item_id (course) সরাসরি course_batches.id পয়েন্ট করে
        $tableMap = ['course' => 'course_batches', 'worksheet' => 'worksheets', 'product' => 'products'];
        $amount = 0.0;
        if (isset($tableMap[$reg['type']])) {
            $itemStmt = $db->prepare("SELECT price FROM `{$tableMap[$reg['type']]}` WHERE id = :id");
            $itemStmt->execute(['id' => $reg['item_id']]);
            $item = $itemStmt->fetch();
            if ($item) {
                $amount = parse_price_to_number($item['price']) * max(1, (int) $reg['quantity']);
            }
        }
        // ⚠️ ফলব্যাক (২০২৬-০৭-২০ এ অডিটে ধরা পড়া বাগ): আইটেমটা (কোর্স-ব্যাচ/ওয়ার্কশিট/প্রোডাক্ট) যদি
        // ইতিমধ্যে ডিলিট/আর্কাইভ হয়ে গিয়ে থাকে, তাহলে দাম খুঁজে পাওয়া যায় না → $amount = 0 → আগে
        // **নীরবে কিছুই হতো না**: রেজিস্ট্রেশন "কনফার্ম" দেখাত কিন্তু আয় বইয়ে উঠত না, কোনো সতর্কতাও নয়।
        // এখন আগের অনুমোদনে সেভ করা পরিমাণ (income_amount স্ন্যাপশট) থেকে হিসাব করা হয়।
        if ($amount <= 0 && !empty($reg['income_amount'])) {
            $amount = (float) $reg['income_amount'];
        }
        if ($amount <= 0) {
            // এখনো ঠিক করা গেল না — নীরব না থেকে অ্যাডমিনকে জানানো হয়, যাতে হাতে যোগ করে নিতে পারেন
            set_flash('error',
                'স্ট্যাটাস বদলেছে, কিন্তু আয় যোগ করা যায়নি — এই অর্ডারের আইটেমটি ("' . $reg['item_title'] . '") '
                . 'ডিলিট/আর্কাইভ হয়ে গেছে বলে দাম পাওয়া যাচ্ছে না। "আয়" পেজ থেকে পরিমাণটা হাতে যোগ করে নিন, '
                . 'অথবা আর্কাইভ পেজ থেকে আইটেমটি ফিরিয়ে এনে আবার চেষ্টা করুন।');
        }
        if ($amount > 0) {
            $categoryId = find_or_create_finance_category('income', registration_type_to_category_name($reg['type']));
            $db->prepare(
                'INSERT INTO income (category_id, registration_id, amount, description, income_date) VALUES (:cat, :reg, :amt, :desc, CURDATE())'
            )->execute([
                'cat' => $categoryId,
                'reg' => $reg['id'],
                'amt' => $amount,
                'desc' => $reg['item_title'] . ' - ' . $reg['customer_name'],
            ]);
            $db->prepare('UPDATE registrations SET income_approved = 1, income_amount = :amt, approved_at = NOW() WHERE id = :id')
                ->execute(['amt' => $amount, 'id' => $reg['id']]);
        }
    } elseif (!$shouldHaveIncome && $reg['income_approved']) {
        $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $reg['id']]);
        // ⚠️ income_amount ইচ্ছাকৃতভাবে **মোছা হয় না** (আগে NULL করা হতো) — এটা "সর্বশেষ জানা পরিমাণ"
        // হিসেবে থেকে যায়, যাতে আইটেম ডিলিট হয়ে গেলেও পরে আবার কনফার্ম করলে আয় ঠিক পরিমাণে ফিরে আসে।
        // প্রদর্শনে কোনো প্রভাব নেই — তালিকা ও ডিটেইল দুই জায়গাতেই income_approved দেখে দেখানো হয়।
        $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')
            ->execute(['id' => $reg['id']]);
    }
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
            sync_income_for_status($db, $reg, $status);
            set_flash('success', 'স্ট্যাটাস আপডেট হয়েছে।');
        }
    }
    redirect($returnUrl);
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
    redirect($returnUrl);
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
    redirect($returnUrl);
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
    redirect($returnUrl);
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

$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');
$filterItem = trim($_GET['item'] ?? '');
$filterBatch = trim($_GET['batch'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
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
        $where[] = '(customer_name LIKE :q1 OR phone LIKE :q2 OR item_title LIKE :q3)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
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

    // ── উপরের সারাংশ কার্ড: স্ট্যাটাস **বাদে** বাকি সব ফিল্টার মেনে গণনা
    // (তাই "পেন্ডিং ৯ / কনফার্ম ২" কার্ডে ক্লিক করে স্ট্যাটাস বদলালেও সংখ্যাগুলো একই থাকে)
    $sumWhere  = array_values(array_filter($where, fn($w) => $w !== 'status = :status'));
    $sumParams = $params;
    unset($sumParams['status']);
    $sumSql = $sumWhere ? (' WHERE ' . implode(' AND ', $sumWhere)) : '';
    try {
        $summaryStmt = $db->prepare(
            "SELECT
                SUM(status = 'pending')   AS pending,
                SUM(status = 'confirmed') AS confirmed,
                SUM(COALESCE(due_amount, 0))                                              AS due_total,
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

    $sql = 'SELECT * FROM registrations' . $whereSql . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
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
    $name  = trim((string) ($row['customer_name'] ?? ''));
    $phone = trim((string) ($row['phone'] ?? ''));
    $addr  = trim((string) ($row['address'] ?? ''));
    ?>
    <div class="font-semibold text-gray-800 leading-tight"><?= e($name !== '' ? $name : '—') ?></div>
    <?php if ($phone !== ''): ?>
        <a href="tel:<?= e($phone) ?>" class="text-[11px] text-indigo-600 font-semibold" title="ফোন করুন"><?= e($phone) ?></a>
    <?php endif; ?>
    <?php if ($addr !== ''): ?>
        <div class="text-[10px] text-gray-400 max-w-[200px] truncate" title="<?= e($addr) ?>">📍 <?= e($addr) ?></div>
    <?php endif;
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
function reg_more_cell(array $row): void
{
    ?>
    <button type="button" onclick="toggleInfoPanel(<?= (int) $row['id'] ?>)" title="বাকি তথ্য দেখুন/লুকান"
            class="px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600 border border-gray-200 whitespace-nowrap">ℹ️ তথ্য</button>
    <?php
}

// ── তথ্য-ড্রয়ার। 🔴 ভেতরে নেস্টেড <table> দেবেন না — মোবাইল কার্ড-CSS
// (main .overflow-x-auto > table td) ভেঙে দেবে; grid/flex ব্যবহার করুন (খাতার প্যানেলের মতোই)।
function reg_more_panel(array $row, int $colspan): void
{
    $rid = (int) $row['id'];
    if (($row['type'] ?? '') === 'course') {
        $fields = [
            'জন্ম তারিখ'            => !empty($row['date_of_birth']) ? format_date_bn((string) $row['date_of_birth']) : '',
            'মোবাইল নাম্বার (বাবা)' => (string) ($row['father_mobile'] ?? ''),
            'রিসিভার নাম'           => (string) ($row['receiver_name'] ?? ''),
            'রিসিভার নাম্বার'       => (string) ($row['receiver_phone'] ?? ''),
        ];
    } else {
        $fields = [
            'ইমেইল'  => (string) ($row['email'] ?? ''),
            'পরিমাণ' => (string) ((int) ($row['quantity'] ?? 0)),
            'জেলা'   => (string) ($row['district'] ?? ''),
            'থানা'   => (string) ($row['thana'] ?? ''),
        ];
    }
    $fields['ঠিকানা']            = (string) ($row['address'] ?? '');
    $fields['অভিভাবকের মন্তব্য'] = (string) ($row['notes'] ?? '');
    $fields['খাতার নোট']         = (string) ($row['admin_note'] ?? '');
    ?>
    <tr class="reg-info-row" id="info-<?= $rid ?>" hidden>
        <td colspan="<?= $colspan ?>" style="text-align:left;padding:0">
            <div class="p-4" style="background:#F9FAFB;border-top:2px solid #E5E7EB">
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
                    <a href="registrations.php?action=view&id=<?= $rid ?>" class="text-indigo-600 font-semibold text-sm">সম্পূর্ণ বিস্তারিত পাতায় যান →</a>
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
    ?>
    <tr class="reg-pay-row" id="pay-<?= $rid ?>" hidden>
        <td colspan="<?= $colspan ?>" style="text-align:left;padding:0">
            <form method="post" action="registrations.php?action=pay-save" class="pay-form p-4" data-rid="<?= $rid ?>"
                  style="background:#EEF2FF;border-top:2px solid #C7D2FE" onsubmit="return <?= $warn ?>;">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $rid ?>">
                <input type="hidden" name="return_url" value="<?= e($returnUrl) ?>">

                <div class="flex flex-wrap items-center justify-between gap-2 mb-3">
                    <h4 class="font-bold text-gray-800 text-sm">💰 টাকার খাতা — <?= e($row['customer_name']) ?></h4>
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
                            <label class="text-xs text-gray-500">কিস্তির নাম
                                <input type="text" name="p[<?= $idx ?>][label]" value="<?= e($r['label']) ?>" maxlength="100"
                                       class="pay-label block border rounded-lg px-2 py-1 text-sm font-bold text-gray-800" style="width:9.5rem">
                            </label>

                            <label class="text-xs text-gray-500">প্রাপ্য
                                <input type="number" step="any" min="0" name="p[<?= $idx ?>][amount_due]" value="<?= e((string) round((float) $r['amount_due'], 2)) ?>"
                                       class="pay-due block border rounded-lg px-2 py-1 text-sm text-gray-800" style="width:6rem">
                            </label>

                            <label class="text-xs text-gray-500">ছাড়
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
                            <button type="button" class="pay-skip-btn text-xs font-semibold text-gray-500" title="এই মাসটা আর প্রযোজ্য নয় (কোর্স ছেড়ে দিয়েছে)"><?= $st === 'skipped' ? '↩ ফেরাও' : '⊘ বাদ' ?></button>
                            <?php // 🔴 "বাদ" থেকে আলাদা: বাদ = সারিটা থাকে কিন্তু হিসাবে ধরা হয় না; মুছুন = সারিটাই আর থাকবে না ?>
                            <button type="button" class="pay-del text-xs font-semibold text-red-600 ml-auto" title="এই কিস্তির সারিটাই মুছে ফেলুন (সংরক্ষণ করলে কার্যকর হবে)">✕</button>
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
                            <label class="text-xs text-gray-500">কিস্তির নাম
                                <input type="text" name="p[__IDX__][label]" value="" maxlength="100" placeholder="যেমন: বাড়তি কিস্তি"
                                       class="pay-label block border rounded-lg px-2 py-1 text-sm font-bold text-gray-800" style="width:9.5rem">
                            </label>
                            <label class="text-xs text-gray-500">প্রাপ্য
                                <input type="number" step="any" min="0" name="p[__IDX__][amount_due]" value="0"
                                       class="pay-due block border rounded-lg px-2 py-1 text-sm text-gray-800" style="width:6rem">
                            </label>
                            <label class="text-xs text-gray-500">ছাড়
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
                            <button type="button" class="pay-skip-btn text-xs font-semibold text-gray-500" title="এই মাসটা আর প্রযোজ্য নয়">⊘ বাদ</button>
                            <button type="button" class="pay-del text-xs font-semibold text-red-600 ml-auto" title="এই কিস্তির সারিটাই মুছে ফেলুন">✕</button>
                        </div>
                    </div></template>
                <div class="pay-rows-end mb-2">
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
                    <button type="button" class="pay-same-disc text-xs font-semibold text-indigo-600 ml-auto" title="প্রথম মাসের ছাড়টা বাকি সব মাসে বসিয়ে দিন">সব মাসে একই ছাড়</button>
                </div>

                <div class="flex flex-wrap items-end gap-2">
                    <label class="text-xs text-gray-500 flex-1" style="min-width:12rem">নোট (কুরিয়ার নোট থেকে আলাদা)
                        <input type="text" name="admin_note" value="<?= e($note) ?>" maxlength="500" class="block w-full border rounded-lg px-3 py-2 text-sm">
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
                <label class="block text-xs font-semibold text-gray-500 mb-1">নাম, ফোন বা আইটেম</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="টাইপ করা মাত্র ফলাফল আপডেট হবে..." id="regSearchInput" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">নির্দিষ্ট আইটেম</label>
                <select name="item" id="regItemSelect" onchange="document.getElementById('regBatchSelect').value=''; document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব আইটেম</option>
                    <?php foreach ($distinctItems as $itemTitle): ?>
                        <option value="<?= e($itemTitle) ?>" <?= $filterItem === $itemTitle ? 'selected' : '' ?>><?= e($itemTitle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">ব্যাচ (কোর্স)</label>
                <select name="batch" id="regBatchSelect" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব ব্যাচ</option>
                    <?php foreach ($distinctBatches as $b): ?>
                        <option value="<?= e($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ থেকে</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ পর্যন্ত</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
        </div>
        <?php if ($hasActiveFilters): ?>
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="registrations.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ সব ফিল্টার মুছুন</a>
        </div>
        <?php endif; ?>
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
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-3 mb-4">
        <?php foreach ($sumCards as $card): $isOn = ($filterStatus === $card['key']); ?>
            <a href="<?= e(reg_url(['status' => $card['key'] !== '' ? $card['key'] : null])) ?>"
               class="block bg-white rounded-2xl shadow p-3 <?= $isOn ? 'border border-indigo-400' : '' ?>">
                <div class="text-[11px] text-gray-500 font-semibold"><?= e($card['label']) ?></div>
                <div class="text-2xl font-bold <?= $card['color'] ?>"><?= $card['value'] ?></div>
            </a>
        <?php endforeach; ?>
        <div class="bg-white rounded-2xl shadow p-3">
            <div class="text-[11px] text-gray-500 font-semibold">মোট বাকি</div>
            <div class="text-xl font-bold text-red-600">৳<?= number_format((float) ($listSummary['due_total'] ?? 0), 0) ?></div>
        </div>
        <div class="bg-white rounded-2xl shadow p-3">
            <div class="text-[11px] text-gray-500 font-semibold">অনুমোদিত আয়</div>
            <div class="text-xl font-bold text-green-700">৳<?= number_format((float) ($listSummary['income_total'] ?? 0), 0) ?></div>
        </div>
    </div>

    <p class="text-sm text-gray-500 mb-4">এই ফিল্টারে <strong><?= $totalRows ?></strong> টি ফলাফল<?= $totalPages > 1 ? " — পৃষ্ঠা {$page}/{$totalPages}" : '' ?></p>

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
                <tr class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_fb_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_group_cell($row, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 10); ?>
                <?php reg_more_panel($row, 10); ?>
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
                <tr class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 8); ?>
                <?php reg_more_panel($row, 8); ?>
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
                <tr class="border-b last:border-0" style="<?= e(reg_row_style($row)) ?>">
                    <td class="py-2.5 px-4"><?php reg_person_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_item_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_fb_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_group_cell($row, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_pay_cell($row, pay_summary($ledgerByReg[$row['id']] ?? [])); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_date_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?php reg_more_cell($row); ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
                <?php reg_pay_panel($db, $row, $ledgerByReg[$row['id']] ?? [], $currentListUrl, 10); ?>
                <?php reg_more_panel($row, 10); ?>
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
            <?php if ($viewRow['notes']): ?>
            <div class="sm:col-span-2"><span class="text-gray-500">মন্তব্য:</span> <span class="font-semibold"><?= e($viewRow['notes']) ?></span></div>
            <?php endif; ?>
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
                    <?php reg_pay_panel($db, $viewRow, pay_fetch_many($db, [(int) $viewRow['id']])[(int) $viewRow['id']] ?? [], $viewUrlSelf, 1); ?>
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
                <p class="text-sm text-gray-500 italic">এই অর্ডারটি এখনো আয় হিসেবে গণনা হয়নি। স্ট্যাটাস "কনফার্ম" করলে অটোমেটিক যোগ হয়ে যাবে।</p>
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
            <a href="registrations.php" class="inline-block text-gray-500 text-sm">← তালিকায় ফিরে যান</a>
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
<?php endif; ?>

<script>
    // showConfirmModal() ও confirmSubmit() admin/includes/layout-bottom.php তে ডিফাইন করা (পুরো অ্যাডমিন প্যানেলে শেয়ার্ড)
    const INCOME_STATUSES = <?= json_encode(INCOME_STATUSES) ?>;
    const STATUS_LABELS = <?= json_encode(array_map(fn($l) => $l[0], $statusLabels)) ?>;

    function statusChangeConfirmMessage(original) {
        return 'এই অর্ডারটি ইতিমধ্যে "' + STATUS_LABELS[original] + '" অবস্থায় আছে এবং আয়ের হিসাবে যুক্ত থাকতে পারে। স্ট্যাটাস পরিবর্তন করলে আয়ের হিসাবও বদলে যেতে পারে। আপনি কি নিশ্চিত?';
    }

    // সার্চ বক্সে টাইপ করা মাত্র (থামার পর) অটো-ফিল্টার — বাটনে ক্লিক করা লাগে না
    (function () {
        var searchInput = document.getElementById('regSearchInput');
        if (!searchInput) { return; }
        var debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                document.getElementById('regFilterForm').submit();
            }, 500);
        });
    })();

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

        document.querySelectorAll('form.pay-form').forEach(function (form) {
            refresh(form);
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
