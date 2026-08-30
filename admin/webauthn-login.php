<?php
// ফিঙ্গারপ্রিন্ট লগইন — assertion যাচাই করে অ্যাডমিন সেশন তৈরি
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

function wa_out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals($_SESSION['csrf_token'] ?? '', $in['csrf_token'] ?? '')) { wa_out(['error' => 'csrf'], 403); }

$ip = admin_client_ip();
if (admin_is_rate_limited($ip)) {
    wa_out(['error' => 'অনেকবার চেষ্টা হয়েছে, ' . ADMIN_LOGIN_WINDOW_MINUTES . ' মিনিট পর আবার চেষ্টা করুন'], 429);
}

$challenge = $_SESSION['wa_login_challenge'] ?? '';
unset($_SESSION['wa_login_challenge']); // একবারই
if ($challenge === '') { wa_out(['error' => 'চ্যালেঞ্জ পাওয়া যায়নি, আবার চেষ্টা করুন'], 400); }

$credIdB64 = $in['id'] ?? '';
$authData = wa_b64url_decode($in['authenticatorData'] ?? '');
$clientDataJSON = wa_b64url_decode($in['clientDataJSON'] ?? '');
$signature = wa_b64url_decode($in['signature'] ?? '');
if ($credIdB64 === '' || $authData === '' || $clientDataJSON === '' || $signature === '') {
    wa_out(['error' => 'অসম্পূর্ণ ডেটা'], 400);
}

$db = get_db();
$stmt = $db->prepare('SELECT * FROM admin_webauthn_credentials WHERE credential_id = :c LIMIT 1');
$stmt->execute(['c' => $credIdB64]);
$cred = $stmt->fetch();
if (!$cred) {
    admin_record_login_attempt($ip, 'webauthn', false);
    wa_out(['error' => 'এই ডিভাইস চেনা গেল না'], 401);
}

$res = wa_verify_assertion($cred['public_key'], $authData, $clientDataJSON, $signature, $challenge);
if (!$res['ok']) {
    admin_record_login_attempt($ip, 'webauthn', false);
    wa_out(['error' => 'যাচাই ব্যর্থ: ' . $res['error']], 401);
}

// signCount ক্লোন-ডিটেকশন: প্রত্যাবর্তিত count আগেরটার চেয়ে ছোট হলে সন্দেহজনক (উভয় 0 হলে বাদ)
if ($res['signCount'] > 0 && $cred['sign_count'] > 0 && $res['signCount'] <= (int) $cred['sign_count']) {
    admin_record_login_attempt($ip, 'webauthn', false);
    wa_out(['error' => 'নিরাপত্তা যাচাইয়ে সমস্যা (signature counter)'], 401);
}

$admin = $db->prepare('SELECT * FROM admin_users WHERE id = :id LIMIT 1');
$admin->execute(['id' => $cred['admin_id']]);
$admin = $admin->fetch();
if (!$admin) {
    admin_record_login_attempt($ip, 'webauthn', false);
    wa_out(['error' => 'অ্যাডমিন অ্যাকাউন্ট পাওয়া যায়নি'], 401);
}

// সফল — পাসওয়ার্ড লগইনের মতোই সেশন তৈরি
session_regenerate_id(true);
$_SESSION['admin_id'] = $admin['id'];
$_SESSION['admin_name'] = $admin['full_name'];
$_SESSION['admin_username'] = $admin['username'];
$_SESSION['admin_last_activity'] = time();

$db->prepare('UPDATE admin_webauthn_credentials SET last_used_at = NOW(), sign_count = :s WHERE id = :id')
    ->execute(['s' => $res['signCount'], 'id' => $cred['id']]);

admin_record_login_attempt($ip, $admin['username'], true);
wa_out(['ok' => true, 'redirect' => 'index.php']);
