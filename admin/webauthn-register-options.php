<?php
// ফিঙ্গারপ্রিন্ট রেজিস্ট্রেশন — অপশন/চ্যালেঞ্জ (অ্যাডমিন লগইন অবস্থায়)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

function wa_out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!admin_logged_in()) { wa_out(['error' => 'লগইন করুন'], 401); }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals($_SESSION['csrf_token'] ?? '', $in['csrf_token'] ?? '')) { wa_out(['error' => 'csrf'], 403); }

$db = get_db();
$challenge = wa_challenge();
$_SESSION['wa_reg_challenge'] = $challenge;

$rows = $db->prepare('SELECT credential_id FROM admin_webauthn_credentials WHERE admin_id = :a');
$rows->execute(['a' => $_SESSION['admin_id']]);
$exclude = [];
foreach ($rows->fetchAll(PDO::FETCH_COLUMN) as $cid) {
    $exclude[] = ['type' => 'public-key', 'id' => $cid]; // ইতিমধ্যে b64url
}

wa_out([
    'challenge' => wa_b64url_encode($challenge),
    'rp' => ['id' => wa_rp_id(), 'name' => get_setting('site_name') ?: 'EduCenter Admin'],
    'user' => [
        'id' => wa_b64url_encode('admin-' . (int) $_SESSION['admin_id']),
        'name' => $_SESSION['admin_username'] ?? 'admin',
        'displayName' => $_SESSION['admin_name'] ?? 'Admin',
    ],
    'pubKeyCredParams' => [['type' => 'public-key', 'alg' => -7]], // ES256
    'excludeCredentials' => $exclude,
    'authenticatorSelection' => ['residentKey' => 'preferred', 'userVerification' => 'preferred'],
    'attestation' => 'none',
    'timeout' => 60000,
]);
