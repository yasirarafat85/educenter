<?php
// অভিভাবকের তথ্য-সংশোধনের অনুরোধ / মন্তব্য সাবমিট (২০২৬-০৯-২৪)
// 🔴 প্রাইভেসি: registration_id POST থেকে এলেও **সবসময় যাচাই করা হয় সেটা এই ইউজারের নিজের
//    ফোনের রেজিস্ট্রেশন কিনা** — নাহলে অন্যের অর্ডার সম্পর্কে বার্তা পাঠানো যেত।
require_once __DIR__ . '/includes/user-auth.php';

user_require_login();
$user = user_current();
if (!$user) {
    redirect('account-login');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account');
}

function acc_req_fail(string $msg): void
{
    set_flash('error', $msg);
    redirect('account');
}

if (!csrf_verify()) {
    acc_req_fail('ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
}
// অন্য পাবলিক ফর্মের স্প্যাম-গার্ডই রিইউজ (honeypot + টাইমিং + IP রেট-লিমিট)
if (is_spam_submission($_POST)) {
    acc_req_fail('অনুরোধটি পাঠানো যায়নি। একটু পরে আবার চেষ্টা করুন।');
}
$db = get_db();
$spamIp = client_ip();
if (form_submit_rate_limited($db, $spamIp)) {
    acc_req_fail('অল্প সময়ে অনেকবার পাঠানো হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।');
}

$kind = ($_POST['kind'] ?? '') === 'correction' ? 'correction' : 'remark';
$message = trim((string) ($_POST['message'] ?? ''));
$regId = (int) ($_POST['registration_id'] ?? 0);

if ($message === '') {
    acc_req_fail('কিছু লিখে তারপর পাঠান।');
}
if (mb_strlen($message) > 1000) {
    $message = mb_substr($message, 0, 1000);
}

// 🔴 নিজের রেজিস্ট্রেশন কিনা যাচাই (ফোন মিলিয়ে) — না মিললে শুধু সংযোগটা বাদ, বার্তা তবু যায়
$itemTitle = null;
if ($regId > 0) {
    $chk = $db->prepare('SELECT item_title FROM registrations WHERE id = :id AND phone = :p LIMIT 1');
    $chk->execute(['id' => $regId, 'p' => $user['phone']]);
    $row = $chk->fetch();
    if ($row) {
        $itemTitle = $row['item_title'];
    } else {
        $regId = 0;
    }
}

try {
    $db->prepare(
        'INSERT INTO user_requests (user_id, phone, registration_id, item_title, kind, message)
         VALUES (:u, :ph, :rid, :it, :k, :m)'
    )->execute([
        'u'   => (int) $user['id'],
        'ph'  => $user['phone'],
        'rid' => $regId ?: null,
        'it'  => $itemTitle,
        'k'   => $kind,
        'm'   => $message,
    ]);
} catch (PDOException $ex) {
    // টেবিল নেই (মাইগ্রেশন চালানো হয়নি) — অভিভাবককে বিভ্রান্ত না করে স্পষ্ট বার্তা
    acc_req_fail('এই মুহূর্তে বার্তা পাঠানো যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।');
}

form_record_submit($db, $spamIp);
set_flash('success', 'আপনার বার্তা পাঠানো হয়েছে। আমরা দেখে ব্যবস্থা নেব।');
redirect('account');
