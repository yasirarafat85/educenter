<?php
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('course-register.php');
}

$courseId = (int) ($_POST['course_id'] ?? 0);
// 🔑 বিশেষ (গোপন) লিংকের চাবি — ভুল হলে ফেরত পাঠানোর লিংকেও এটা বয়ে যেতে হবে,
//    নাহলে অভিভাবক বন্ধ পাতায় গিয়ে পড়বেন আর লেখা তথ্যও কাজে লাগবে না।
$regKey  = trim((string) ($_POST['k'] ?? ''));
$backUrl = 'course-register.php?course_id=' . $courseId
         . ($regKey !== '' ? '&k=' . rawurlencode($regKey) : '');

function course_register_fail(string $msg, string $backUrl): void
{
    log_registration_error('course', $msg);
    set_flash('error', $msg);
    $_SESSION['course_register_form_old'] = $_POST;
    redirect($backUrl);
}

if (!csrf_verify()) {
    course_register_fail('নিরাপত্তা যাচাই মেয়াদোত্তীর্ণ হয়েছিল। আপনার তথ্য ঠিক আছেই — নিচের ফর্মে আর একবার সাবমিট করুন।', $backUrl);
}

// স্প্যাম-প্রোটেকশন: honeypot ভরা বা খুব দ্রুত সাবমিট হলে নীরবে বাতিল (বটকে কিছু বোঝানো হয় না)
if (is_spam_submission($_POST)) {
    redirect('index.php');
}

$db = get_db();
$spamIp = client_ip();
if (form_submit_rate_limited($db, $spamIp)) {
    course_register_fail('অল্প সময়ে অনেকবার সাবমিট হয়েছে। কিছুক্ষণ পর আবার চেষ্টা করুন।', $backUrl);
}
// 'course_id' এখন বাস্তবে course_batches.id বোঝায় (এক্সটার্নাল প্যারামিটার নাম অপরিবর্তিত)
$stmt = $db->prepare(
    'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id AND cb.is_active = 1'
);
$stmt->execute(['id' => $courseId]);
$course = $stmt->fetch();

if (!$course) {
    set_flash('error', 'এই কোর্সটি আর পাওয়া যাচ্ছে না।');
    redirect('course-register.php');
}

// UI তে রেজিস্ট্রেশন বন্ধ থাকলে ফর্মই দেখানো হয় না, কিন্তু সরাসরি POST করলেও যেন আটকায় (defense in depth)
// 🔴 course_reg_open() হাতের সুইচ **ও** ভর্তির শেষ সময় দুটোই দেখে — কেউ ফর্ম খুলে বসে থাকতে
// থাকতে সময় পেরিয়ে গেলে ব্রাউজারের ঘড়ির উপর ভরসা না করে এখানেই আটকানো হয়।
// 🔑 বিশেষ লিংকের চাবি সঠিক হলে এই দুটো গেটই খোলে (ব্যাচ নিষ্ক্রিয় হলে উপরের কোয়েরিতেই আটকে গেছে)
$viaKey = !course_reg_open($course) && course_reg_key_valid($courseId, $regKey);
if (!course_reg_allowed($course, $regKey)) {
    $closedMsg = course_deadline_passed($course)
        ? 'দুঃখিত, এই ব্যাচে ভর্তির সময় শেষ হয়ে গেছে। নতুন ব্যাচ খুললে জানতে আগ্রহ জানিয়ে রাখুন।'
        : 'এই ব্যাচের রেজিস্ট্রেশন বর্তমানে বন্ধ।';
    course_register_fail($closedMsg, $backUrl);
}

$motherMobile = trim($_POST['mother_mobile'] ?? '');
$childName = trim($_POST['child_name'] ?? '');
$dob = trim($_POST['date_of_birth'] ?? '');
$facebookId = trim($_POST['facebook_id'] ?? '');
$fatherMobile = trim($_POST['father_mobile'] ?? '');
$notes = trim($_POST['notes'] ?? '');

$hideParcel = (bool) $course['hide_parcel'];
$receiverName = $hideParcel ? '' : trim($_POST['receiver_name'] ?? '');
$receiverPhone = $hideParcel ? '' : trim($_POST['receiver_phone'] ?? '');
$address = $hideParcel ? '' : trim($_POST['address'] ?? '');

// নম্বরের শুরুর ০ বাদ পড়া / +880 ঠিক করে প্রমিত 01... ফরম্যাটে (পুরনো ডেটা অটো-ফিল বা টাইপোর জন্য)
if ($motherMobile !== '') { $motherMobile = bd_phone_canonical($motherMobile); }
if ($fatherMobile !== '') { $fatherMobile = bd_phone_canonical($fatherMobile); }
if ($receiverPhone !== '') { $receiverPhone = bd_phone_canonical($receiverPhone); }

if (!is_valid_bd_phone($motherMobile)) {
    course_register_fail('মায়ের সঠিক মোবাইল নম্বর দিন (যেমন: 017xxxxxxxx)।', $backUrl);
}
if ($childName === '') {
    course_register_fail('শিশুর নাম দিন।', $backUrl);
}
$dobTimestamp = strtotime($dob);
if (!$dob || !$dobTimestamp || $dobTimestamp > time()) {
    course_register_fail('সঠিক জন্ম তারিখ দিন।', $backUrl);
}
if ($facebookId === '') {
    course_register_fail('ফেসবুক আইডি নাম দিন।', $backUrl);
}
if ($fatherMobile !== '' && !is_valid_bd_phone($fatherMobile)) {
    course_register_fail('বাবার মোবাইল নম্বরটি সঠিক নয়।', $backUrl);
}
if (!$hideParcel) {
    if ($receiverName === '') {
        course_register_fail('রিসিভারের নাম দিন।', $backUrl);
    }
    if (!is_valid_bd_phone($receiverPhone)) {
        course_register_fail('রিসিভারের সঠিক মোবাইল নম্বর দিন।', $backUrl);
    }
    if ($address === '') {
        course_register_fail('ঠিকানা দিন।', $backUrl);
    }
}

$stmt = $db->prepare(
    'INSERT INTO registrations
        (type, item_id, item_title, batch, customer_name, phone, address, date_of_birth, facebook_id, father_mobile, receiver_name, receiver_phone, notes, status)
     VALUES
        ("course", :item_id, :item_title, :batch, :child_name, :mother_mobile, :address, :dob, :facebook_id, :father_mobile, :receiver_name, :receiver_phone, :notes, "pending")'
);
$stmt->execute([
    'item_id' => $courseId,
    'item_title' => $course['title'],
    'batch' => $course['batch_name'] ?: null,
    'child_name' => $childName,
    'mother_mobile' => $motherMobile,
    'address' => $address ?: null,
    'dob' => date('Y-m-d', $dobTimestamp),
    'facebook_id' => $facebookId,
    'father_mobile' => $fatherMobile ?: null,
    'receiver_name' => $receiverName ?: null,
    'receiver_phone' => $receiverPhone ?: null,
    'notes' => $notes ?: null,
]);

$newId = (int) $db->lastInsertId();

// 🔑 বিশেষ লিংক দিয়ে এলে অ্যাডমিন-নোটে একটা চিহ্ন — তালিকায় দেখেই বোঝা যায় এটা
//    সাইট বন্ধ থাকা অবস্থায় আলাদা করে নেওয়া রেজিস্ট্রেশন।
// 🔴 আলাদা UPDATE-এ, try/catch-এ — এতে মূল INSERT অক্ষত থাকে, কিছু ভুল হলেও
//    রেজিস্ট্রেশনটা কখনো হারায় না।
if ($viaKey) {
    try {
        $db->prepare('UPDATE registrations SET admin_note = :n WHERE id = :id')
           ->execute(['n' => '🔑 বিশেষ লিংক দিয়ে রেজিস্ট্রেশন (সাইটে ভর্তি বন্ধ ছিল)', 'id' => $newId]);
    } catch (PDOException $ex) {
        // নোট বসাতে না পারলেও রেজিস্ট্রেশন সফলই
    }
}

form_record_submit($db, $spamIp); // রেট-লিমিটের হিসাবে যোগ
unset($_SESSION['course_register_form_old']);

$_SESSION['registration_success'] = [
    'ref' => $newId,
    'item_title' => $course['title'],
    'type' => 'course',
];
redirect('register-thanks.php');
