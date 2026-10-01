<?php
// কোর্স রেজিস্ট্রেশন ফর্মে "মোবাইল নাম্বার (মা)" দিয়ে আগের তথ্য অটো-ফিল করার জন্য AJAX এন্ডপয়েন্ট
// রেট-লিমিট করা আছে যাতে কেউ ফোন নম্বর দিয়ে ঘুরে ঘুরে ব্যক্তিগত তথ্য স্ক্র্যাপ করতে না পারে
//
// একই মোবাইল নম্বরে একাধিক ভিন্ন শিশুর রেজিস্ট্রেশন থাকতে পারে (একই মায়ের একাধিক সন্তান) —
// তাই children এর তালিকা আলাদাভাবে পাঠানো হয় (family তথ্য একবার, যেটা সব শিশুর জন্যই এক)

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// 🔴 এখন **POST + ফর্মের টোকেন** বাধ্যতামূলক (২০২৬-১০-০১ অডিট)। আগে যেকোনো জায়গা থেকে
//    শুধু একটা GET ঠিকানা দিয়েই যে কেউ নম্বর বসিয়ে বসিয়ে অভিভাবকের নাম/ঠিকানা তুলে
//    নিতে পারত। এখন আগে আসল ফর্মের পাতাটা খুলতে হয় — টোকেনটা ওখান থেকেই আসে।
// 🔴 টোকেনটা GET-এ নয়, POST বডিতে — URL সার্ভারের অ্যাক্সেস-লগ ও Referer হেডারে জমা হয়,
//    সেখানে টোকেন ফাঁস হওয়া উচিত নয়।
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    http_response_code(403);
    echo json_encode(['found' => false, 'error' => 'forbidden']);
    exit;
}

$db = get_db();
$ip = client_ip();

$phone = trim($_POST['phone'] ?? '');
$mode = $_POST['mode'] ?? 'course';

if (!is_valid_bd_phone($phone)) {
    echo json_encode(['found' => false]);
    exit;
}

// 🔑 রেট-লিমিট — "কয়টা **ভিন্ন** নম্বর দেখা হলো" ধরে গোনা হয় (বিস্তারিত functions.php-এর
//    phone_lookup_rate_limited()-এর ঘরে)। একই নম্বর যতবার খুশি দেখা যায়, তাই আসল
//    অভিভাবক কখনো আটকান না। 🔴 নম্বর যাচাইয়ের **পরে** — অবৈধ নম্বর কোটা খাবে না।
if (phone_lookup_rate_limited($db, $ip, $phone)) {
    http_response_code(429);
    echo json_encode(['found' => false, 'error' => 'too_many_requests']);
    exit;
}
phone_lookup_record($db, $ip, $phone);

// ওয়ার্কশিট/প্রোডাক্ট অর্ডার ফর্মের জন্য — কোর্স বাদে আগের যেকোনো অর্ডার থেকে নাম/ইমেইল/ঠিকানা অটো-ফিল
if ($mode === 'general') {
    $stmt = $db->prepare(
        "SELECT customer_name, email, address
         FROM registrations
         WHERE phone = :phone AND type != 'course'
         ORDER BY created_at DESC LIMIT 1"
    );
    $stmt->execute(['phone' => $phone]);
    $row = $stmt->fetch();

    if (!$row) {
        // পুরাতন তালিকা মিলিয়ে দেখি
        try {
            $lg = $db->prepare("SELECT customer_name, address FROM legacy_students WHERE RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = :k ORDER BY id DESC LIMIT 1");
            $lg->execute(['k' => phone_last10($phone)]);
            $row = $lg->fetch();
        } catch (Throwable $e) { $row = false; }
        if (!$row) { echo json_encode(['found' => false]); exit; }
        echo json_encode(['found' => true, 'customer_name' => $row['customer_name'], 'email' => '', 'address' => $row['address'] ?? '']);
        exit;
    }

    echo json_encode([
        'found' => true,
        'customer_name' => $row['customer_name'],
        'email' => $row['email'],
        'address' => $row['address'],
    ]);
    exit;
}

$stmt = $db->prepare(
    "SELECT customer_name, date_of_birth, facebook_id, father_mobile, receiver_name, receiver_phone, address
     FROM registrations
     WHERE phone = :phone AND type = 'course'
     ORDER BY created_at DESC"
);
$stmt->execute(['phone' => $phone]);
$rows = $stmt->fetchAll();

// বর্তমান রেজিস্ট্রেশন না থাকলে পুরাতন (legacy) তালিকা মিলিয়ে দেখি — পুরনো শিক্ষার্থীর তথ্য অটো-ফিল
if (!$rows) {
    try {
        // নমনীয় ম্যাচ — নম্বরের শেষ ১০ ডিজিট মিলিয়ে (ফরম্যাট ভিন্ন হলেও চলে)
        $lg = $db->prepare("SELECT customer_name, date_of_birth, facebook_id, father_mobile, receiver_name, receiver_phone, address
                            FROM legacy_students WHERE RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = :k ORDER BY id DESC");
        $lg->execute(['k' => phone_last10($phone)]);
        $lrows = $lg->fetchAll();
    } catch (Throwable $e) { $lrows = []; }
    if ($lrows) {
        $lchildren = [];
        foreach ($lrows as $lr) {
            $key = mb_strtolower(trim($lr['customer_name']));
            if (trim($lr['customer_name']) !== '' && !isset($lchildren[$key])) {
                $lchildren[$key] = ['child_name' => $lr['customer_name'], 'date_of_birth' => $lr['date_of_birth'] ?? ''];
            }
        }
        echo json_encode([
            'found' => true,
            'family' => [
                'facebook_id' => $lrows[0]['facebook_id'],
                'father_mobile' => $lrows[0]['father_mobile'] !== '' ? bd_phone_canonical($lrows[0]['father_mobile']) : '',
                'receiver_name' => $lrows[0]['receiver_name'] ?? '',
                'receiver_phone' => ($lrows[0]['receiver_phone'] ?? '') !== '' ? bd_phone_canonical($lrows[0]['receiver_phone']) : '',
                'address' => $lrows[0]['address'] ?? '',
            ],
            'children' => array_values($lchildren),
        ]);
        exit;
    }
    echo json_encode(['found' => false]);
    exit;
}

// পরিবার-পর্যায়ের তথ্য (ফেসবুক আইডি/বাবার মোবাইল/রিসিভার/ঠিকানা) — সবচেয়ে সাম্প্রতিক রেকর্ড থেকে,
// এগুলো সাধারণত এক পরিবারের সব শিশুর জন্যই একই থাকে, তাই "নতুন শিশু" বেছে নিলেও এগুলো অটো-ফিল থাকবে
$family = [
    'facebook_id' => $rows[0]['facebook_id'],
    'father_mobile' => $rows[0]['father_mobile'],
    'receiver_name' => $rows[0]['receiver_name'],
    'receiver_phone' => $rows[0]['receiver_phone'],
    'address' => $rows[0]['address'],
];

// শিশু-পর্যায়ের তথ্য (শুধু নাম ও জন্ম তারিখ — এই দুটোই সত্যিকারের শিশু-ভিত্তিক তথ্য) —
// নাম দিয়ে ডিডুপ করা (একই নামে একাধিকবার রেজিস্ট্রেশন থাকলে সবচেয়ে নতুনটা রাখা)
$children = [];
foreach ($rows as $row) {
    $key = mb_strtolower(trim($row['customer_name']));
    if (!isset($children[$key])) {
        $children[$key] = [
            'child_name' => $row['customer_name'],
            'date_of_birth' => $row['date_of_birth'],
        ];
    }
}

echo json_encode([
    'found' => true,
    'family' => $family,
    'children' => array_values($children),
]);
