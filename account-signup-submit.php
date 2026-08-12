<?php
// অভিভাবক signup সাবমিট হ্যান্ডলার
require_once __DIR__ . '/includes/user-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account-signup');
}

function signup_fail(string $msg): void
{
    set_flash('error', $msg);
    $post = $_POST;
    unset($post['password'], $post['password_confirm']); // পাসওয়ার্ড সেশনে রাখব না
    $_SESSION['account_signup_old'] = $post;
    redirect('account-signup');
}

if (!csrf_verify()) {
    signup_fail('ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
}
if (is_spam_submission($_POST)) {
    redirect('index.php');
}

$db = get_db();
$ip = client_ip();
if (form_submit_rate_limited($db, $ip)) {
    signup_fail('অল্প সময়ে অনেকবার চেষ্টা হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।');
}

$phone = trim($_POST['phone'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$confirm = (string) ($_POST['password_confirm'] ?? '');

if (!is_valid_bd_phone($phone)) {
    signup_fail('সঠিক মোবাইল নাম্বার দিন (যেমন: 017xxxxxxxx)।');
}
// আসল কাস্টমার যাচাই — এই ফোনে অন্তত একটা রেজিস্ট্রেশন থাকতে হবে
$stmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE phone = :p');
$stmt->execute(['p' => $phone]);
if ((int) $stmt->fetch()['c'] === 0) {
    signup_fail('এই নাম্বারে কোনো রেজিস্ট্রেশন পাওয়া যায়নি। কোর্সে রেজিস্ট্রেশনের সময় দেওয়া নাম্বারটি দিন।');
}
// ইতিমধ্যে অ্যাকাউন্ট আছে কিনা
$stmt = $db->prepare('SELECT COUNT(*) c FROM users WHERE phone = :p');
$stmt->execute(['p' => $phone]);
if ((int) $stmt->fetch()['c'] > 0) {
    signup_fail('এই নাম্বারে ইতিমধ্যে একটি অ্যাকাউন্ট আছে। লগইন করুন অথবা অ্যাডমিনের সাথে যোগাযোগ করুন।');
}
if (mb_strlen($password) < 6) {
    signup_fail('পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের দিন।');
}
if ($password !== $confirm) {
    signup_fail('দুটি পাসওয়ার্ড মিলছে না।');
}

$db->prepare('INSERT INTO users (phone, password_hash, full_name, status) VALUES (:p, :h, :n, "pending")')
   ->execute(['p' => $phone, 'h' => password_hash($password, PASSWORD_DEFAULT), 'n' => $fullName ?: null]);

form_record_submit($db, $ip);
unset($_SESSION['account_signup_old']);

set_flash('success', 'অ্যাকাউন্ট তৈরি হয়েছে! অ্যাডমিন যাচাই করে approve করলে আপনি লগইন করতে পারবেন।');
redirect('account-login');
