<?php
// ⚠️ "কোর্স ট্র্যাকিং" ও "পার্সেল প্রস্তুত" এখন এক পেজে মিলিয়ে দেওয়া হয়েছে → course-parcel.php (২০২৬-০৮-১৬)।
// পুরনো লিংক/বুকমার্ক যাতে না ভাঙে তাই এখানে রিডাইরেক্ট রাখা হয়েছে (item_id প্যারামিটার সহ)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();
$q = [];
if (!empty($_GET['item_id'])) { $q['item_id'] = (int) $_GET['item_id']; }
redirect('course-parcel.php' . ($q ? '?' . http_build_query($q) : ''));
