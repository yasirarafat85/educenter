<?php
// কোর্স রেজিস্ট্রেশন ফর্মে "মোবাইল নাম্বার (মা)" দিয়ে আগের তথ্য অটো-ফিল করার জন্য AJAX এন্ডপয়েন্ট
// রেট-লিমিট করা আছে যাতে কেউ ফোন নম্বর দিয়ে ঘুরে ঘুরে ব্যক্তিগত তথ্য স্ক্র্যাপ করতে না পারে
//
// একই মোবাইল নম্বরে একাধিক ভিন্ন শিশুর রেজিস্ট্রেশন থাকতে পারে (একই মায়ের একাধিক সন্তান) —
// তাই children এর তালিকা আলাদাভাবে পাঠানো হয় (family তথ্য একবার, যেটা সব শিশুর জন্যই এক)

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

function lookup_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

const LOOKUP_MAX_PER_WINDOW = 20;
const LOOKUP_WINDOW_MINUTES = 10;

$db = get_db();
$ip = lookup_client_ip();

$stmt = $db->prepare(
    'SELECT COUNT(*) c FROM phone_lookup_attempts WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
);
$stmt->bindValue('ip', $ip);
$stmt->bindValue('mins', LOOKUP_WINDOW_MINUTES, PDO::PARAM_INT);
$stmt->execute();

if ((int) $stmt->fetch()['c'] >= LOOKUP_MAX_PER_WINDOW) {
    http_response_code(429);
    echo json_encode(['found' => false, 'error' => 'too_many_requests']);
    exit;
}

$db->prepare('INSERT INTO phone_lookup_attempts (ip_address) VALUES (:ip)')->execute(['ip' => $ip]);
$db->exec('DELETE FROM phone_lookup_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');

$phone = trim($_GET['phone'] ?? '');
$mode = $_GET['mode'] ?? 'course';

if (!is_valid_bd_phone($phone)) {
    echo json_encode(['found' => false]);
    exit;
}

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
        echo json_encode(['found' => false]);
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

if (!$rows) {
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
