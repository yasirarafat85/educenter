<?php
// আগ্রহ-ফর্মে (course-interest.php) "যোগাযোগ নাম্বার" দিয়ে আগের আগ্রহ-তথ্য অটো-ফিল করার AJAX এন্ডপয়েন্ট।
// একই নাম্বারে আগে কেউ আগ্রহ জমা দিয়ে থাকলে শিশুর নাম / ফেসবুক নাম / মা-বাবা / মন্তব্য টেনে আনে।
// রেট-লিমিট করা (phone_lookup_attempts রিইউজ) যাতে কেউ নাম্বার দিয়ে ঘুরে তথ্য স্ক্র্যাপ করতে না পারে।

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// 🔴 POST + ফর্মের টোকেন বাধ্যতামূলক, আর রেট-লিমিট "কয়টা **ভিন্ন** নম্বর দেখা হলো" ধরে —
//    দুটোই `ajax-lookup-registration.php`-এর হুবহু একই নিয়ম (শেয়ার্ড হেল্পার, ২০২৬-১০-০১)।
//    **দুই এন্ডপয়েন্টের নিয়ম সবসময় এক রাখুন** — একটা কড়া আর অন্যটা খোলা থাকলে
//    আক্রমণকারী সহজ পথটাই বেছে নেবে (দুটোই একই `phone_lookup_attempts` ব্যবহার করে)।
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['found' => false, 'error' => 'forbidden']);
    exit;
}

$db = get_db();
$ip = client_ip();

$phone = trim($_POST['phone'] ?? '');
if (!is_valid_bd_phone($phone)) {
    echo json_encode(['found' => false]);
    exit;
}

if (phone_lookup_rate_limited($db, $ip, $phone)) {
    http_response_code(429);
    echo json_encode(['found' => false, 'error' => 'too_many_requests']);
    exit;
}
phone_lookup_record($db, $ip, $phone);

// সবচেয়ে সাম্প্রতিক আগ্রহ-এন্ট্রি থেকে তথ্য (একই পরিবার সাধারণত একই নাম/ফেসবুক ব্যবহার করে)
// remarks ইচ্ছাকৃতভাবে আনা হয় না — মন্তব্য প্রতিবার নতুন করে লেখা হয় (ইউজারের স্পষ্ট চাওয়া,
// প্রতিটা আগ্রহের মন্তব্য আলাদা হতে পারে বলে আগেরটা টেনে আনা ঠিক না)। নাম/ফেসবুক/মা-বাবা আসে।
// `SELECT *` ইচ্ছাকৃত — child_dob কলামের মাইগ্রেশন না চালিয়ে ফাইল ডিপ্লয় হলেও কোয়েরি ভাঙে না
// (তখন `?? null` দিয়ে চুপচাপ বাদ পড়ে)। reason/start_when ইচ্ছাকৃতভাবে আনা হয় না —
// remarks-এর মতোই ওগুলো প্রতিবার আলাদা (এবার কেন পারছেন না, সেটা আগেরবারের মতো নাও হতে পারে)।
$stmt = $db->prepare(
    'SELECT * FROM course_interests WHERE contact_phone = :phone ORDER BY created_at DESC LIMIT 1'
);
$stmt->execute(['phone' => $phone]);
$row = $stmt->fetch();

if (!$row) {
    echo json_encode(['found' => false]);
    exit;
}

echo json_encode([
    'found'         => true,
    'child_name'    => $row['child_name'],
    'child_dob'     => $row['child_dob'] ?? null,
    'facebook_name' => $row['facebook_name'],
    'phone_owner'   => $row['phone_owner'],
]);
