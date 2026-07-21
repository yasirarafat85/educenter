<?php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('index.php');
}

$type = $_POST['type'] ?? '';
$itemId = (int) ($_POST['item_id'] ?? 0);
$backUrl = 'register.php?type=' . urlencode($type) . '&id=' . $itemId;

// কোর্স রেজিস্ট্রেশন এখন নতুন ২-ধাপের ফর্মে হয় (course-register-submit.php)
if ($type === 'course') {
    redirect('course-register.php');
}

function fail(string $msg, string $backUrl): void
{
    set_flash('error', $msg);
    $_SESSION['register_form_old'] = $_POST;
    redirect($backUrl);
}

if (!csrf_verify()) {
    fail('ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।', $backUrl);
}

// স্প্যাম-প্রোটেকশন: honeypot ভরা বা খুব দ্রুত সাবমিট হলে নীরবে বাতিল
if (is_spam_submission($_POST)) {
    redirect('index.php');
}
$spamIp = client_ip();
if (form_submit_rate_limited(get_db(), $spamIp)) {
    fail('অল্প সময়ে অনেকবার সাবমিট হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', $backUrl);
}

$orderItem = fetch_item($type, $itemId);
if (!$orderItem) {
    fail('এই আইটেমটি আর পাওয়া যাচ্ছে না।', 'index.php');
}

$customerName = trim($_POST['customer_name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$quantity = max(1, (int) ($_POST['quantity'] ?? 1));
$notes = trim($_POST['notes'] ?? '');

if ($customerName === '') {
    fail('আপনার নাম দিন।', $backUrl);
}
if (!is_valid_bd_phone($phone)) {
    fail('সঠিক মোবাইল নম্বর দিন (যেমন: 017xxxxxxxx)।', $backUrl);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fail('সঠিক ইমেইল ঠিকানা দিন।', $backUrl);
}
if ($address === '') {
    fail('ঠিকানা দিন।', $backUrl);
}

$db = get_db();
$stmt = $db->prepare(
    'INSERT INTO registrations (type, item_id, item_title, customer_name, phone, email, address, quantity, notes, status)
     VALUES (:type, :item_id, :item_title, :name, :phone, :email, :address, :qty, :notes, "pending")'
);
$stmt->execute([
    'type' => $type,
    'item_id' => $itemId,
    'item_title' => $orderItem['title'],
    'name' => $customerName,
    'phone' => $phone,
    'email' => $email ?: null,
    'address' => $address,
    'qty' => $quantity,
    'notes' => $notes ?: null,
]);

$newId = (int) $db->lastInsertId();
form_record_submit($db, $spamIp); // রেট-লিমিটের হিসাবে যোগ
unset($_SESSION['register_form_old']);

// এখানে ইচ্ছাকৃতভাবে URL এ raw ID পাস করা হচ্ছে না — নাহলে যে কেউ ID পাল্টে
// অন্য কাস্টমারের রেজিস্ট্রেশন তথ্য দেখতে পারতো (IDOR)। সেশনে রেখে এক-বার দেখানো হচ্ছে।
$_SESSION['registration_success'] = [
    'ref' => $newId,
    'item_title' => $orderItem['title'],
    'type' => $type,
];
redirect('register-thanks.php');
