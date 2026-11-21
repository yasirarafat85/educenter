<?php
// 🔁 "কুরিয়ার ট্র্যাকিং" পাতা ২০২৬-১১-২১ থেকে কোর্স পার্সেলের সাথে জোড়া (মেনু গোছানোর ধাপ ২)।
// শিক্ষার্থী × মাসের ছকে প্রতি ঘরের কালেকশন, শিক্ষার্থীর মোট ও "মাসের মোট" সারি এখন
// course-parcel.php-র "অবস্থা" তালিকাতেই। এই ফাইল শুধু পুরনো লিংক চালু রাখতে রিডাইরেক্ট করে — 🔴 মুছবেন না।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$itemId = (int) ($_GET['item_id'] ?? 0);
header('Location: course-parcel.php' . ($itemId > 0 ? '?item_id=' . $itemId : ''));
exit;
