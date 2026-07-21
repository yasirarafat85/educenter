<?php
// register-thanks.php এর "ডাউনলোড করুন" বাটনে ক্লিক করলে (html2canvas দিয়ে JPEG বানিয়ে ডাউনলোডের পর)
// এই এন্ডপয়েন্টে একটা লগ এন্ট্রি হয় — অ্যাডমিন প্যানেলে দেখা যায় কে কবে ডাউনলোড করেছে (admin/download-logs.php)

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$registrationId = (int) ($_POST['registration_id'] ?? 0);
if ($registrationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false]);
    exit;
}

$db = get_db();
$stmt = $db->prepare('SELECT id FROM registrations WHERE id = :id');
$stmt->execute(['id' => $registrationId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['success' => false]);
    exit;
}

$db->prepare('INSERT INTO confirmation_downloads (registration_id, ip_address) VALUES (:id, :ip)')
   ->execute(['id' => $registrationId, 'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0']);

echo json_encode(['success' => true]);
