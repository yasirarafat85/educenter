<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 sms-in.php — ফোন থেকে আসা বিকাশ/নগদের SMS এখানে জমা হয়  (২০২৬-১০-০৬)
 *
 * একটা পুরনো অ্যান্ড্রয়েড ফোনে MacroDroid বসানো থাকে:
 *   Trigger  : SMS Received  (প্রেরক ফিল্টার bKash / NAGAD)
 *   Action   : HTTP Request POST → https://<সাইট>/sms-in.php
 *   Body     : key=<গোপন চাবি>&sender=[sms_sender]&text=[sms_message]
 * (চাবিটা হেডারেও দেওয়া যায়: `X-Edu-Sms-Key`)
 *
 * 🔴 CSRF টোকেন এখানে চলবে না — কলটা মানুষের ব্রাউজার থেকে আসে না, একটা ফোন
 *    থেকে আসে (কোনো সেশনই নেই)। **গোপন চাবিই একমাত্র গার্ড**, তাই:
 *      · চাবি সেট না থাকলে কোনো মানই গ্রহণ করা হয় না (খালি ↔ খালি মিলবে না)
 *      · তুলনা `hash_equals()` দিয়ে (timing-safe)
 *      · চাবিটা `settings`-এ থাকে, **কখনো repo-তে নয়**
 *
 * 🔴 এই এন্ডপয়েন্ট `registration_payments`/`income`-এ কিছুই লেখে না — শুধু
 *    `payment_sms`-এ কাঁচা SMS জমা করে। মেলানো ও খাতায় বসানো ধাপ ২ ও ৩।
 *
 * ⚠️ উত্তর সবসময় JSON। ভুল চাবিতে কোনো বিস্তারিত কারণ বলা হয় না।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-sms.php';
// 🔑 দেরিতে আসা SMS অপেক্ষমাণ দাবির সাথে নিজে থেকেই মিলিয়ে দেওয়ার জন্য
require_once __DIR__ . '/includes/payment-claim.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// প্রতি মিনিটে সর্বোচ্চ কত SMS গ্রহণ করা হবে (flood গার্ড; আসল ব্যবহারে দিনে ২০-৫০)
const SMS_IN_MAX_PER_MIN = 40;

function sms_in_out(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    sms_in_out(405, ['ok' => false, 'error' => 'post_only']);
}

// ── চাবি যাচাই ──────────────────────────────────────────────────────────────
$secret = trim(get_setting('sms_in_secret'));
$given  = trim((string) ($_SERVER['HTTP_X_EDU_SMS_KEY'] ?? $_POST['key'] ?? ''));

// 🔴 দুটো শর্ত আলাদা করে লেখা হয়েছে ইচ্ছাকৃতভাবে: `hash_equals('', '')` → true,
//    তাই চাবি সেট না থাকলে চাবিহীন POST-ও পাস করে যেত (অ্যাডমিন অ্যাকাউন্টে
//    পাসকি বসানোর বাগের হুবহু একই শ্রেণি — ২০২৬-১০-০১ অডিটে ধরা)।
if ($secret === '' || $given === '' || !hash_equals($secret, $given)) {
    sms_in_out(403, ['ok' => false, 'error' => 'auth']);
}

$db = get_db();

if (!psms_ready($db)) {
    // মাইগ্রেশন এখনো চালানো হয়নি — ফোনকে স্পষ্ট করে বলা হয় যেন সে আবার চেষ্টা করে
    sms_in_out(503, ['ok' => false, 'error' => 'not_ready', 'hint' => 'database/migrate-payment-sms.sql']);
}

// ── flood গার্ড (আলাদা টেবিল লাগে না — আসা SMS নিজেই হিসাব) ─────────────────
try {
    $recent = (int) $db->query('SELECT COUNT(*) c FROM payment_sms WHERE received_at > (NOW() - INTERVAL 1 MINUTE)')->fetch()['c'];
    if ($recent >= SMS_IN_MAX_PER_MIN) {
        sms_in_out(429, ['ok' => false, 'error' => 'rate_limited']);
    }
} catch (Throwable $e) {
    // গোনা না গেলে আটকানো হয় না — SMS হারানোর চেয়ে বেশি গ্রহণ করা ভালো
}

// ── SMS জমা ─────────────────────────────────────────────────────────────────
$text   = (string) ($_POST['text'] ?? $_POST['message'] ?? '');
$sender = (string) ($_POST['sender'] ?? $_POST['from'] ?? '');
$sentAt = (string) ($_POST['sent_at'] ?? '');

if (trim($text) === '') {
    sms_in_out(400, ['ok' => false, 'error' => 'empty_text']);
}

$res = psms_store($db, $text, $sender, $sentAt !== '' ? $sentAt : null);

if (!$res['ok']) {
    sms_in_out($res['reason'] === 'db_error' ? 500 : 400, ['ok' => false, 'error' => $res['reason']]);
}

psms_prune($db);

// ⚠️ উত্তরে TrxID/অঙ্ক পাঠানো হয় না — চাবি জানা থাকলেও এটা নিছক "জমা হয়েছে"
//    রসিদ; অঙ্ক ফেরালে চাবি ফাঁস হলে পুরো লেনদেনের তালিকা পড়া যেত।
sms_in_out(200, [
    'ok'        => true,
    'id'        => $res['id'],
    'duplicate' => $res['dup'],
    'parsed'    => $res['parsed'],
]);
