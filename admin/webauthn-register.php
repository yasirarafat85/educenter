<?php
// ফিঙ্গারপ্রিন্ট রেজিস্ট্রেশন — attestation যাচাই করে credential সংরক্ষণ (লগইন অবস্থায়)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/webauthn.php';
header('Content-Type: application/json; charset=utf-8');

function wa_out($d, int $code = 200): void { http_response_code($code); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

if (!admin_logged_in()) { wa_out(['error' => 'লগইন করুন'], 401); }
$in = json_decode(file_get_contents('php://input'), true) ?: [];
if (!hash_equals($_SESSION['csrf_token'] ?? '', $in['csrf_token'] ?? '')) { wa_out(['error' => 'csrf'], 403); }

$challenge = $_SESSION['wa_reg_challenge'] ?? '';
unset($_SESSION['wa_reg_challenge']); // একবারই ব্যবহার
if ($challenge === '') { wa_out(['error' => 'চ্যালেঞ্জ পাওয়া যায়নি, আবার চেষ্টা করুন'], 400); }

$attestationObject = wa_b64url_decode($in['attestationObject'] ?? '');
$clientDataJSON = wa_b64url_decode($in['clientDataJSON'] ?? '');
if ($attestationObject === '' || $clientDataJSON === '') { wa_out(['error' => 'অসম্পূর্ণ ডেটা'], 400); }

try {
    $reg = wa_parse_registration($attestationObject, $clientDataJSON, $challenge);
} catch (Throwable $e) {
    wa_out(['error' => 'যাচাই ব্যর্থ: ' . $e->getMessage()], 400);
}

$credIdB64 = wa_b64url_encode($reg['credId']);
$deviceName = trim((string) ($in['device_name'] ?? ''));
if ($deviceName === '') { $deviceName = 'আমার ডিভাইস'; }
$deviceName = mb_substr($deviceName, 0, 100);

$db = get_db();
$exists = $db->prepare('SELECT id FROM admin_webauthn_credentials WHERE credential_id = :c LIMIT 1');
$exists->execute(['c' => $credIdB64]);
if ($exists->fetch()) { wa_out(['error' => 'এই ডিভাইস ইতিমধ্যে যোগ করা আছে'], 409); }

$db->prepare(
    'INSERT INTO admin_webauthn_credentials (admin_id, credential_id, public_key, sign_count, device_name)
     VALUES (:a, :c, :p, :s, :n)'
)->execute([
    'a' => $_SESSION['admin_id'],
    'c' => $credIdB64,
    'p' => $reg['pem'],
    's' => $reg['signCount'],
    'n' => $deviceName,
]);

wa_out(['ok' => true]);
