<?php
// 👁 অভিভাবক-ভিউ প্রিভিউ — অ্যাডমিন দেখবেন একজন অভিভাবকের ড্যাশবোর্ড ঠিক যেমন দেখায় (২০২৬-০৯-২৪)
//
// 🔴 এটা ছদ্মবেশ (impersonation) নয়: অভিভাবকের লগইন-কী কখনো বসে না, আলাদা সেশন-কী
//    (account_preview_user_id) বসে, আর প্রিভিউতে সব সাবমিট বন্ধ থাকে।
//    অনুমতির যাচাই এখানেই হয় — পেজটা admin_page_sections()-এ 'users' সেকশনে ম্যাপ করা।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

// বেরিয়ে আসা (GET) — নিজের সেশন-কী মোছা ছাড়া কিছুই করে না, তাই CSRF লাগে না
if (isset($_GET['exit'])) {
    unset($_SESSION['account_preview_user_id'], $_SESSION['account_preview_admin']);
    set_flash('success', 'প্রিভিউ বন্ধ করা হয়েছে।');
    redirect('users.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    set_flash('error', 'অনুরোধটি বৈধ নয়।');
    redirect('users.php');
}

$userId = (int) ($_POST['user_id'] ?? 0);
$stmt = get_db()->prepare('SELECT id, full_name, phone FROM users WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $userId]);
$u = $stmt->fetch();
if (!$u) {
    set_flash('error', 'অভিভাবক অ্যাকাউন্টটি পাওয়া যায়নি।');
    redirect('users.php');
}

$_SESSION['account_preview_user_id'] = (int) $u['id'];
$_SESSION['account_preview_admin']   = (int) ($_SESSION['admin_id'] ?? 0);

// পাবলিক সাইটের ড্যাশবোর্ডে পাঠাই (admin/ থেকে এক ধাপ উপরে)
redirect('../account');
