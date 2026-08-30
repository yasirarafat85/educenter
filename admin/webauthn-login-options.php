<?php
// ফিঙ্গারপ্রিন্ট লগইন — অপশন/চ্যালেঞ্জ (লগইন পেজ থেকে, সেশন আছে কিন্তু লগইন নেই)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

function wa_out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals($_SESSION['csrf_token'] ?? '', $in['csrf_token'] ?? '')) { wa_out(['error' => 'csrf'], 403); }

$db = get_db();
$challenge = wa_challenge();
$_SESSION['wa_login_challenge'] = $challenge;

$allow = [];
foreach ($db->query('SELECT credential_id FROM admin_webauthn_credentials')->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $allow[] = ['type' => 'public-key', 'id' => $cid];
}

wa_out([
    'challenge' => wa_b64url_encode($challenge),
    'rpId' => wa_rp_id(),
    'allowCredentials' => $allow,
    'userVerification' => 'preferred',
    'timeout' => 60000,
    'hasCredentials' => count($allow) > 0,
]);
