<?php
// আগ্রহ-ফর্ম (course-interest.php) সাবমিট হ্যান্ডলার — course_interests টেবিলে সেভ করে।

require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('course-interest.php');
}

$courseId = (int) ($_POST['course_id'] ?? 0);
$backUrl = 'course-interest.php?course_id=' . $courseId;

function course_interest_fail(string $msg, string $backUrl): void
{
    set_flash('error', $msg);
    $_SESSION['course_interest_form_old'] = $_POST;
    redirect($backUrl);
}

if (!csrf_verify()) {
    course_interest_fail('ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।', $backUrl);
}

// স্প্যাম-প্রোটেকশন: honeypot ভরা বা খুব দ্রুত সাবমিট হলে নীরবে বাতিল
if (is_spam_submission($_POST)) {
    redirect('index.php');
}

$db = get_db();
$spamIp = client_ip();
if (form_submit_rate_limited($db, $spamIp)) {
    course_interest_fail('অল্প সময়ে অনেকবার সাবমিট হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', $backUrl);
}

// course_id বাস্তবে course_batches.id
$stmt = $db->prepare(
    'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id AND cb.is_active = 1'
);
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch();

if (!$course) {
    set_flash('error', 'এই কোর্সটি আর পাওয়া যাচ্ছে না।');
    redirect('courses');
}

$contactPhone = trim($_POST['contact_phone'] ?? '');
$phoneOwner   = ($_POST['phone_owner'] ?? 'mother') === 'father' ? 'father' : 'mother';
$childName    = trim($_POST['child_name'] ?? '');
$facebookName = trim($_POST['facebook_name'] ?? '');
$remarks      = trim($_POST['remarks'] ?? '');

if (!is_valid_bd_phone($contactPhone)) {
    course_interest_fail('সঠিক যোগাযোগ নাম্বার দিন (যেমন: 017xxxxxxxx)।', $backUrl);
}
if ($childName === '') {
    course_interest_fail('শিশুর নাম দিন।', $backUrl);
}

$stmt = $db->prepare(
    'INSERT INTO course_interests
        (batch_id, item_title, batch_name, contact_phone, phone_owner, child_name, facebook_name, remarks, ip_address)
     VALUES
        (:batch_id, :item_title, :batch_name, :contact_phone, :phone_owner, :child_name, :facebook_name, :remarks, :ip)'
);
$stmt->execute([
    'batch_id'      => $courseId,
    'item_title'    => $course['title'],
    'batch_name'    => $course['batch_name'] ?: null,
    'contact_phone' => $contactPhone,
    'phone_owner'   => $phoneOwner,
    'child_name'    => $childName,
    'facebook_name' => $facebookName ?: null,
    'remarks'       => $remarks ?: null,
    'ip'            => $spamIp,
]);

form_record_submit($db, $spamIp); // রেট-লিমিটের হিসাবে যোগ
unset($_SESSION['course_interest_form_old']);

set_flash('success', 'ধন্যবাদ! আপনার আগ্রহ আমরা পেয়েছি। নতুন ব্যাচ খুললে এই নাম্বারে যোগাযোগ করা হবে।');
redirect($backUrl);
