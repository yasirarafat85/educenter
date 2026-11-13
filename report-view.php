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

/**
 * অ্যাডমিন/মডারেটর এই রিপোর্ট প্রিভিউ করতে পারেন কিনা।
 * সেশনের role/permissions সরাসরি পড়া হয় (admin/includes/auth.php লোড করা হয় না —
 * ওটা পাবলিক পাতায় টানলে অ্যাডমিনের পুরো গার্ড-যন্ত্র চলে আসত)। নিয়মটা
 * admin_can('reports') ও admin_require_login()-এর নিষ্ক্রিয়তা-সীমার হুবহু প্রতিধ্বনি —
 * permissions.php / auth.php-এ ওগুলো বদলালে এখানেও বদলান।
 */
function report_admin_may_view(): bool
{
    if (empty($_SESSION['admin_id'])) {
        return false;
    }
    $last = (int) ($_SESSION['admin_last_activity'] ?? 0);
    if ($last > 0 && (time() - $last) > 30 * 60) {
        return false; // নিষ্ক্রিয় হয়ে পড়ে থাকা অ্যাডমিন সেশন — এখান দিয়ে ঢোকা যাবে না
    }
    if (($_SESSION['admin_role'] ?? 'admin') === 'admin') {
        return true;
    }
    $perms = $_SESSION['admin_permissions'] ?? [];
    return is_array($perms) && in_array('view', (array) ($perms['reports'] ?? []), true);
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
// 🔴 অডিট S4 (২০২৬-১১-১৩): আগে "অ্যাডমিন লগইন আছে" দেখেই ছাড়া হতো — তাই যে মডারেটরকে
//    রিপোর্টের অনুমতি দেওয়াই হয়নি তিনিও id বদলে বদলে সব শিক্ষার্থীর PDF খুলতে পারতেন।
//    এখন মূল অ্যাডমিন অথবা `reports` সেকশনে view অনুমতি লাগে, আর অ্যাডমিন সেশনটা
//    নিষ্ক্রিয়তা-সীমার (ADMIN_IDLE_TIMEOUT_MINUTES) ভেতরে থাকতে হয়। না মিললে নিচের
//    অভিভাবক-যাচাইয়ে পড়ে — অর্থাৎ আগের মতোই ৪০৪/লগইন, অস্তিত্ব ফাঁস হয় না।
$adminLoggedIn = report_admin_may_view();
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

// PDF-এর উত্তরে পাতার CSP লাগে না (ওটা HTML-এর জন্য); কিছু ব্রাউজারের PDF-ভিউয়ার
// `object-src 'none'` পেলে ফাইলটা দেখাতে চায় না — তাই শুধু এই উত্তর থেকে সরানো।
header_remove('Content-Security-Policy');
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $asciiName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
