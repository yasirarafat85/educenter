<?php
// 📄 গেটেড রিপোর্ট PDF — অভিভাবক লগইন করে শুধু নিজের মোবাইলের রিপোর্ট দেখতে পারেন।
// PDF uploads/reports/-এ deny-all .htaccess দিয়ে সুরক্ষিত; সরাসরি URL দিয়ে খোলা যায় না —
// এই পাতাটাই একমাত্র পথ, আর সেশনের ফোন মিলিয়ে তবেই ফাইল পাঠায় (readfile)।
//
// 🔴🔴 প্রাইভেসি নিয়ম: অভিভাবকের মোবাইল সবসময় সেশন থেকে নেওয়া হয় — কখনো URL থেকে ফোন নয়।
//      অন্যের id দিলে "পাওয়া যায়নি"-র মতোই আচরণ (অস্তিত্ব ফাঁস হয় না)।
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/user-auth.php';

function report_deny(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'পাওয়া যায়নি।';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !report_reports_ready()) {
    report_deny(404);
}

$db = get_db();
try {
    $stmt = $db->prepare('SELECT phone, file_path, file_name FROM student_reports WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    $row = null;
}
if (!$row) {
    report_deny(404);
}

// ── অ্যাক্সেস যাচাই ──
// (ক) অ্যাডমিন লগইন করা থাকলে যেকোনো রিপোর্ট দেখতে পারেন (নিজেই আপলোড করেছেন — প্রিভিউ)।
// (খ) নাহলে অভিভাবক লগইন বাধ্যতামূলক, আর তাঁর সেশনের ফোন = রিপোর্টের ফোন হতে হবে।
$adminLoggedIn = !empty($_SESSION['admin_id']);
if (!$adminLoggedIn) {
    $user = user_current(); // approved না হলে null
    if (!$user) {
        // লগইন পাতায় পাঠাই — লগইনের পর অভিভাবক ড্যাশবোর্ডে (account.php) রিপোর্ট সেকশনেই দেখবেন
        redirect('account-login');
    }
    if (bd_phone_canonical($user['phone']) !== (string) $row['phone']) {
        report_deny(404); // অন্যের রিপোর্ট — অস্তিত্বই জানানো হয় না
    }
}

$abs = report_pdf_abs_path((string) $row['file_path']);
if ($abs === '') {
    report_deny(404);
}

// ── PDF পাঠানো (inline — ব্রাউজারে খোলে, চাইলে নামানো যায়) ──
$downloadName = (string) ($row['file_name'] ?: 'report.pdf');
$asciiName = preg_replace('/[^\x20-\x7E]/', '', $downloadName);
if ($asciiName === '' || !preg_match('/\.pdf$/i', $asciiName)) { $asciiName = 'report.pdf'; }

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $asciiName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
