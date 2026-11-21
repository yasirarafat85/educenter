<?php
// 🔁 "ডেটা টেবিল" পাতা ২০২৬-১১-২১ থেকে রেজিস্ট্রেশন/অর্ডার তালিকার সাথে জোড়া (মেনু গোছানোর ধাপ ২)।
// কোর্স + ব্যাচ ফিল্টার আর ডুপ্লিকেট (✅ রাখা হবে / 🗑️ অতিরিক্ত) এখন registrations.php-তে
// (`?type=course&item=…&batch=…&dupes=1`)। এই ফাইল শুধু পুরনো লিংক/বুকমার্ক চালু রাখতে রিডাইরেক্ট করে
// (courier-prepare.php/course-tracking.php-এর মতোই স্টাব) — 🔴 মুছবেন না।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$q = ['type' => 'course'];
$cid = (int) ($_GET['course_id'] ?? 0);   // = registrations.item_id (course_batches.id)
if ($cid > 0) {
    try {
        $st = get_db()->prepare("SELECT item_title FROM registrations WHERE type = 'course' AND item_id = :i ORDER BY id DESC LIMIT 1");
        $st->execute(['i' => $cid]);
        $t = (string) ($st->fetchColumn() ?: '');
        if ($t !== '') { $q['item'] = $t; }
    } catch (Throwable $e) { /* নাম না পেলে শুধু কোর্স-তালিকায় */ }
}
$b = trim((string) ($_GET['batch'] ?? ''));
if ($b !== '') { $q['batch'] = $b; }
if (isset($_GET['duplicates'])) { $q['dupes'] = '1'; }
header('Location: registrations.php?' . http_build_query($q));
exit;
