<?php
// Google থেকে ফেরত — code → token → userinfo → লগইন বা ফোন-লিংকে পাঠানো
require_once __DIR__ . '/includes/user-auth.php';
require_once __DIR__ . '/includes/google-oauth.php';

if (user_logged_in()) {
    redirect('account');
}

// state যাচাই (CSRF) — একবার ব্যবহার করে মুছে ফেলা
$stateOk = isset($_GET['state'], $_SESSION['google_oauth_state'])
    && hash_equals($_SESSION['google_oauth_state'], (string) $_GET['state']);
unset($_SESSION['google_oauth_state']);

if (!empty($_GET['error']) || !$stateOk) {
    set_flash('error', 'Google লগইন সম্পন্ন হয়নি। আবার চেষ্টা করুন।');
    redirect('account-login');
}

$code = trim($_GET['code'] ?? '');
if ($code === '' || !google_oauth_enabled()) {
    set_flash('error', 'Google লগইন সম্পন্ন হয়নি।');
    redirect('account-login');
}

$token = google_exchange_code($code);
$info = $token ? google_userinfo($token['access_token']) : null;
if (!$info) {
    set_flash('error', 'Google থেকে তথ্য নেওয়া যায়নি। আবার চেষ্টা করুন।');
    redirect('account-login');
}

$googleId = (string) $info['sub'];
$email = $info['email'] ?? null;
$name = $info['name'] ?? null;

$db = get_db();
$stmt = $db->prepare('SELECT * FROM users WHERE google_id = :g LIMIT 1');
$stmt->execute(['g' => $googleId]);
$u = $stmt->fetch();

if ($u) {
    // পরিচিত Google অ্যাকাউন্ট
    if ($u['status'] !== 'approved') {
        $msg = ['pending' => 'আপনার অ্যাকাউন্ট এখনো অ্যাডমিন approve করেননি।',
                'blocked' => 'আপনার অ্যাকাউন্টটি বন্ধ করা হয়েছে।',
                'rejected' => 'আপনার অ্যাকাউন্টটি অনুমোদন করা হয়নি।'][$u['status']] ?? 'লগইন করা যাচ্ছে না।';
        set_flash('error', $msg);
        redirect('account-login');
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_phone'] = $u['phone'];
    $_SESSION['user_name'] = $u['full_name'];
    redirect('account');
}

// নতুন Google অ্যাকাউন্ট — রেজিস্ট্রেশনের ফোন নাম্বার চাওয়ার জন্য পেন্ডিং ডেটা সেশনে রেখে লিংক পেজে
$_SESSION['google_pending'] = ['google_id' => $googleId, 'email' => $email, 'name' => $name];
redirect('account-google-link');
