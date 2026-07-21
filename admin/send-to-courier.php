<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    set_flash('error', 'ফর্ম টোকেন মিলছে না।');
    redirect('registrations.php');
}

$id = (int) ($_POST['id'] ?? 0);
$db = get_db();

// registrations.php ও courier.php দুটো পেজ থেকেই এখানে পাঠানো হতে পারে — যেটা থেকে এসেছে সেখানেই ফিরিয়ে দেওয়া হয় (open-redirect ঠেকিয়ে)
$allowedReturnPrefixes = ['registrations.php', 'courier.php'];
$returnUrl = 'registrations.php?action=view&id=' . $id;
$postedReturn = $_POST['return_url'] ?? '';
foreach ($allowedReturnPrefixes as $prefix) {
    if ($postedReturn && strpos($postedReturn, $prefix) === 0) {
        $returnUrl = $postedReturn;
        break;
    }
}

$stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
$stmt->execute(['id' => $id]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'রেজিস্ট্রেশন পাওয়া যায়নি।');
    redirect('registrations.php');
}

$provider = get_active_courier_provider();
if (!$provider) {
    set_flash('error', 'কোনো কুরিয়ার প্রোভাইডার সেট করা নেই। সাইট সেটিংস থেকে "কুরিয়ার API" সেকশনে গিয়ে প্রোভাইডার ও কী বসান।');
    redirect($returnUrl);
}

// batch_id দিলে সেই নির্দিষ্ট ব্যাচ (আগের কোনো মাসের/কিস্তির) আপডেট হয়ে পাঠানো হয়, না দিলে নতুন একটা ব্যাচ
// তৈরি হয় (একই registration এর জন্য একাধিক মাসের চালান আলাদা আলাদা ট্র্যাক রাখার এটাই মেকানিজম)
$batchId = isset($_POST['batch_id']) && $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
$result = send_courier_batch($db, $provider, $order, $_POST, $batchId);

// ডিটেইল পেজে ফিরে গেলে যেন সদ্য পাঠানো ব্যাচটাই খোলা থাকে
if (strpos($returnUrl, 'courier.php?action=view&id=') === 0 && strpos($returnUrl, 'batch=') === false) {
    $returnUrl .= '&batch=' . $result['batch_id'];
}

set_flash($result['success'] ? 'success' : 'error', $result['message']);
redirect($returnUrl);
