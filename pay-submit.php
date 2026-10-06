<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 💳 পেমেন্ট দাবি জমা — POST হ্যান্ডলার  (pay.php ও payment.php দুটোরই)
 *
 * 🔴 CSRF + honeypot + টাইমিং + রেট-লিমিট — পাবলিক ফর্মের চারটা গার্ডই বহাল।
 * 🔴 এই ফাইল টাকার খাতায় কিছুই লেখে না (`pclaim_submit()`-এর ঘরে বিস্তারিত)।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirect('payment');
}

$regId = (int) ($_POST['r'] ?? 0);
$key   = (string) ($_POST['k'] ?? '');
$back  = ($regId > 0 && pay_link_valid($regId, $key)) ? pay_link_url($regId) : 'payment';

// ফলাফল সেশনে রেখে redirect (PRG) — রিফ্রেশে আবার সাবমিট হবে না
$finish = static function (array $res, string $trxid, string $back): never {
    $_SESSION['pay_result'] = $res + ['trxid' => $trxid];
    redirect($back);
};

$trxid = trim((string) ($_POST['trxid'] ?? ''));

if (!csrf_verify()) {
    $finish(['state' => 'invalid', 'message' => 'ফর্ম টোকেন মিলছে না — পাতাটা রিফ্রেশ করে আবার চেষ্টা করুন।'], $trxid, $back);
}
if (is_spam_submission($_POST)) {
    // 🔴 বটকে কিছু জানানো হয় না — সাধারণ বার্তাই
    $finish(['state' => 'invalid', 'message' => 'আবার চেষ্টা করুন।'], $trxid, $back);
}

$db = get_db();
$ip = client_ip();
if (form_submit_rate_limited($db, $ip)) {
    $finish(['state' => 'blocked', 'message' => 'একটু বেশি চেষ্টা হয়ে গেছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।'], $trxid, $back);
}
form_record_submit($db, $ip);

$res = pclaim_submit($db, [
    'registration_id' => ($regId > 0 && pay_link_valid($regId, $key)) ? $regId : 0,
    'phone'           => (string) ($_POST['phone'] ?? ''),
    'trxid'           => $trxid,
    'amount'          => (string) ($_POST['amount'] ?? ''),
    'channel'         => (string) ($_POST['channel'] ?? ''),
]);

$finish($res, $trxid, $back);
