<?php
// আগ্রহ-ফর্মে (course-interest.php) "যোগাযোগ নাম্বার" দিয়ে আগের আগ্রহ-তথ্য অটো-ফিল করার AJAX এন্ডপয়েন্ট।
// একই নাম্বারে আগে কেউ আগ্রহ জমা দিয়ে থাকলে শিশুর নাম / ফেসবুক নাম / মা-বাবা / মন্তব্য টেনে আনে।
// রেট-লিমিট করা (phone_lookup_attempts রিইউজ) যাতে কেউ নাম্বার দিয়ে ঘুরে তথ্য স্ক্র্যাপ করতে না পারে।

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

const INTEREST_LOOKUP_MAX_PER_WINDOW = 20;
const INTEREST_LOOKUP_WINDOW_MINUTES = 10;

$db = get_db();
$ip = client_ip();

$stmt = $db->prepare(
    'SELECT COUNT(*) c FROM phone_lookup_attempts WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
);
$stmt->bindValue('ip', $ip);
$stmt->bindValue('mins', INTEREST_LOOKUP_WINDOW_MINUTES, PDO::PARAM_INT);
$stmt->execute();

if ((int) $stmt->fetch()['c'] >= INTEREST_LOOKUP_MAX_PER_WINDOW) {
    http_response_code(429);
    echo json_encode(['found' => false, 'error' => 'too_many_requests']);
    exit;
}

$db->prepare('INSERT INTO phone_lookup_attempts (ip_address) VALUES (:ip)')->execute(['ip' => $ip]);
$db->exec('DELETE FROM phone_lookup_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');

$phone = trim($_GET['phone'] ?? '');
if (!is_valid_bd_phone($phone)) {
    echo json_encode(['found' => false]);
    exit;
}

// সবচেয়ে সাম্প্রতিক আগ্রহ-এন্ট্রি থেকে তথ্য (একই পরিবার সাধারণত একই নাম/ফেসবুক ব্যবহার করে)
// remarks ইচ্ছাকৃতভাবে আনা হয় না — মন্তব্য প্রতিবার নতুন করে লেখা হয় (ইউজারের স্পষ্ট চাওয়া,
// প্রতিটা আগ্রহের মন্তব্য আলাদা হতে পারে বলে আগেরটা টেনে আনা ঠিক না)। নাম/ফেসবুক/মা-বাবা আসে।
$stmt = $db->prepare(
    'SELECT child_name, facebook_name, phone_owner
     FROM course_interests WHERE contact_phone = :phone ORDER BY created_at DESC LIMIT 1'
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
    'facebook_name' => $row['facebook_name'],
    'phone_owner'   => $row['phone_owner'],
]);
