<?php
// 🛒 চেকআউট সাবমিট — কার্টের প্রতিটা আইটেম একটা করে registrations সারি হয়,
// সব একই order_group টোকেনে বাঁধা (২০২৬-১১-০৭)। আয়/কুরিয়ার/অ্যাডমিন আগের যন্ত্রেই চলে।
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('cart.php');
}

function checkout_fail(string $msg): void
{
    log_registration_error('order', $msg);
    set_flash('error', $msg);
    $_SESSION['checkout_form_old'] = $_POST;
    redirect('checkout.php');
}

if (!csrf_verify()) {
    checkout_fail('নিরাপত্তা যাচাই মেয়াদোত্তীর্ণ হয়েছিল। আপনার তথ্য ঠিক আছেই — নিচের ফর্মে আর একবার সাবমিট করুন।');
}

// স্প্যাম-প্রোটেকশন (register-submit.php-এর হুবহু নীতি)
if (is_spam_submission($_POST)) {
    redirect('index.php');
}
$spamIp = client_ip();
if (form_submit_rate_limited(get_db(), $spamIp)) {
    checkout_fail('অল্প সময়ে অনেকবার সাবমিট হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।');
}

// 🔴 দাম/আইটেম সবসময় সার্ভার থেকে (cart_contents) — POST-এ কোনো দাম আসে না
$lines = cart_contents();
if (!$lines) {
    set_flash('error', 'আপনার কার্ট খালি।');
    redirect('cart.php');
}
$total = cart_total($lines);

$customerName = trim($_POST['customer_name'] ?? '');
$phone        = trim($_POST['phone'] ?? '');
if ($phone !== '') {
    $phone = bd_phone_canonical($phone);
}
$email   = trim($_POST['email'] ?? '');
$address = trim($_POST['address'] ?? '');
$notes   = trim($_POST['notes'] ?? '');

if ($customerName === '') {
    checkout_fail('আপনার নাম দিন।');
}
if (!is_valid_bd_phone($phone)) {
    checkout_fail('সঠিক মোবাইল নম্বর দিন (যেমন: 017xxxxxxxx)।');
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    checkout_fail('সঠিক ইমেইল ঠিকানা দিন।');
}
if ($address === '') {
    checkout_fail('ঠিকানা দিন।');
}

$db        = get_db();
$hasGroup  = db_has_column($db, 'registrations', 'order_group');  // মাইগ্রেশনের আগেও না ভাঙতে
$orderGrp  = bin2hex(random_bytes(8));   // 16 হেক্স — একই চেকআউটের সব আইটেম বাঁধে

// প্রতিটা কার্ট-লাইন একটা করে registrations সারি
$cols = 'type, item_id, item_title, customer_name, phone, email, address, quantity, notes, status';
$vals = ':type, :item_id, :item_title, :name, :phone, :email, :address, :qty, :notes, "pending"';
if ($hasGroup) {
    $cols .= ', order_group';
    $vals .= ', :grp';
}
$ins = $db->prepare("INSERT INTO registrations ($cols) VALUES ($vals)");

$refIds = [];
$db->beginTransaction();
try {
    foreach ($lines as $l) {
        $params = [
            'type'       => $l['type'],
            'item_id'    => $l['id'],
            'item_title' => $l['title'],
            'name'       => $customerName,
            'phone'      => $phone,
            'email'      => $email ?: null,
            'address'    => $address,
            'qty'        => $l['qty'],
            'notes'      => $notes ?: null,
        ];
        if ($hasGroup) {
            $params['grp'] = $orderGrp;
        }
        $ins->execute($params);
        $refIds[] = (int) $db->lastInsertId();
    }
    $db->commit();
} catch (Throwable $ex) {
    $db->rollBack();
    checkout_fail('দুঃখিত, অর্ডারটি সংরক্ষণ করা গেল না। আবার চেষ্টা করুন।');
}

form_record_submit($db, $spamIp);
cart_clear();
unset($_SESSION['checkout_form_old']);

// 🔴 raw ID URL-এ দেওয়া হয় না (IDOR) — সেশনে রেখে এক-বার দেখানো হয়
$_SESSION['order_success'] = [
    'group'    => $orderGrp,
    'ref_ids'  => $refIds,
    'ref'      => $refIds[0] ?? 0,     // মানুষ-বোধ্য রেফারেন্স = প্রথম আইটেমের নম্বর
    'name'     => $customerName,
    'phone'    => $phone,
    'total'    => $total,
    'items'    => array_map(static function ($l) {
        return ['title' => $l['title'], 'qty' => $l['qty'], 'line' => $l['line'], 'type' => $l['type'], 'id' => $l['id']];
    }, $lines),
];
redirect('order-thanks.php');
