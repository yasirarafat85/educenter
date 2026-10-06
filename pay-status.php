<?php
/* 💳 একটা দাবির অবস্থা (JSON) — অপেক্ষমাণ পাতা নিজে থেকে কয়েকবার দেখে নেয়।
 * 🔴 আইডি + HMAC চাবি দুটোই লাগে, আর উত্তরে **শুধু অবস্থা** যায় —
 *    কোনো TrxID/অঙ্ক/নাম নয় (চাবি ফাঁস হলেও লেনদেনের তথ্য পড়া যাবে না)। */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$id = (int) ($_GET['c'] ?? 0);
$k  = (string) ($_GET['k'] ?? '');

if ($id <= 0 || !pclaim_token_valid($id, $k)) {
    http_response_code(403);
    echo json_encode(['ok' => false]);
    exit;
}

$state = pclaim_state(get_db(), $id);
echo json_encode(['ok' => true, 'state' => $state]);
