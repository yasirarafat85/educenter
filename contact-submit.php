<?php
// 📨 যোগাযোগ ফর্ম (contact.php) সাবমিট হ্যান্ডলার — contact_messages টেবিলে সেভ করে।
// অন্য পাবলিক ফর্মের স্প্যাম-গার্ডই রিইউজ (CSRF + honeypot + টাইমিং + IP রেট-লিমিট)।
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/contact.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('contact');
}

function contact_fail(string $msg): void
{
    log_registration_error('contact', $msg);
    set_flash('error', $msg);
    $_SESSION['contact_form_old'] = $_POST;
    redirect('contact');
}

if (!csrf_verify()) {
    contact_fail('নিরাপত্তা যাচাই মেয়াদোত্তীর্ণ হয়েছিল। আপনার লেখা ঠিক আছেই — নিচের ফর্মে আর একবার পাঠান।');
}

// honeypot ভরা বা খুব দ্রুত সাবমিট হলে নীরবে বাতিল (বট)
if (is_spam_submission($_POST)) {
    redirect('index.php');
}

$db = get_db();

// 🔴 মাইগ্রেশনের আগে / বন্ধ থাকলে: সেভ করার জায়গা নেই — বাসি পাতা থেকে POST এলে ভদ্রভাবে ফেরাই
if (!contact_table_ready($db)) {
    set_flash('error', 'এই মুহূর্তে বার্তা পাঠানো যাচ্ছে না। অনুগ্রহ করে ফোন বা WhatsApp-এ যোগাযোগ করুন।');
    redirect('contact');
}

$spamIp = client_ip();
if (form_submit_rate_limited($db, $spamIp)) {
    contact_fail('অল্প সময়ে অনেকবার পাঠানো হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।');
}

$name    = trim((string) ($_POST['name'] ?? ''));
$phone   = trim((string) ($_POST['phone'] ?? ''));
$email   = trim((string) ($_POST['email'] ?? ''));
$topic   = (string) ($_POST['topic'] ?? '');
$message = trim((string) ($_POST['message'] ?? ''));

if ($name === '') {
    contact_fail('আপনার নাম লিখুন।');
}
if (!is_valid_bd_phone($phone)) {
    contact_fail('সঠিক মোবাইল নম্বর দিন (যেমন: 017XXXXXXXX)।');
}
if ($message === '') {
    contact_fail('কিছু লিখে তারপর পাঠান।');
}
// ইমেইল ঐচ্ছিক — দিলে বৈধ হতে হবে (ভুল থাকলে না-হারিয়ে আবার দেখাই)
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    contact_fail('ইমেইলটি ঠিক মনে হচ্ছে না — আবার দেখে দিন (অথবা খালি রাখুন)।');
}
// বিষয় সবসময় হোয়াইটলিস্ট (POST-এ যা এসেছে বিশ্বাস নয়) — না মিললে খালি
if (!contact_topic_valid($topic)) {
    $topic = '';
}

$name    = mb_substr($name, 0, 150);
$email   = mb_substr($email, 0, 190);
$message = mb_substr($message, 0, 2000);
$phoneCanonical = bd_phone_canonical($phone) ?: $phone;

$stmt = $db->prepare(
    'INSERT INTO contact_messages (name, phone, email, topic, message, ip_address)
     VALUES (:name, :phone, :email, :topic, :message, :ip)'
);
$stmt->execute([
    'name'    => $name,
    'phone'   => $phoneCanonical,
    'email'   => $email,
    'topic'   => $topic,
    'message' => $message,
    'ip'      => $spamIp,
]);

form_record_submit($db, $spamIp); // রেট-লিমিটের হিসাবে যোগ
unset($_SESSION['contact_form_old']);

set_flash('success', 'ধন্যবাদ! আপনার বার্তা আমরা পেয়েছি। শীঘ্রই এই নম্বরে যোগাযোগ করা হবে।');
redirect('contact');
