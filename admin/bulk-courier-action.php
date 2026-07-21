<?php
// কুরিয়ার লিস্টে (courier.php) চেকবক্স দিয়ে নির্বাচিত **ইতিমধ্যে তৈরি করা পার্সেল/ব্যাচ** একসাথে কুরিয়ারে
// পাঠানোর জন্য। `ids[]` = courier_batches.id (আগে registrations.id ছিল)।
//
// ⚠️ ২০২৬-০৭-২০ থেকে এই স্ক্রিপ্ট আর কোনো **নতুন** ব্যাচ তৈরি করে না (ইউজারের স্পষ্ট নির্দেশ) — পার্সেল
// তৈরি হয় শুধু `admin/courier-prepare.php`-এ (কোর্স → ব্যাচ → মাস অনুযায়ী, অটো-হিসাব করা কালেকশন সহ)।
// এখান থেকে সেই তৈরি করা ব্যাচগুলোই যেমন আছে তেমনই পাঠানো হয় (কোনো ওভাররাইড ছাড়া)।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    set_flash('error', 'ফর্ম টোকেন মিলছে না।');
    redirect('courier.php');
}

$db = get_db();
$batchIds = array_unique(array_filter(array_map('intval', $_POST['ids'] ?? [])));
$returnUrl = (isset($_POST['return_url']) && strpos((string) $_POST['return_url'], 'courier.php') === 0)
    ? $_POST['return_url']
    : 'courier.php';

if (!$batchIds) {
    set_flash('error', 'কোনো পার্সেল নির্বাচন করা হয়নি।');
    redirect($returnUrl);
}

$provider = get_active_courier_provider();
if (!$provider) {
    set_flash('error', 'কোনো কুরিয়ার প্রোভাইডার সেট করা নেই। সাইট সেটিংস থেকে "কুরিয়ার API" সেকশনে গিয়ে প্রোভাইডার ও কী বসান।');
    redirect($returnUrl);
}

$successCount = 0;
$failCount = 0;
$skipCount = 0;
$failMessages = [];

$batchStmt = $db->prepare(
    'SELECT cb.*, r.id AS reg_id, r.courier_active
     FROM courier_batches cb JOIN registrations r ON r.id = cb.registration_id
     WHERE cb.id = :id'
);
$regStmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');

foreach ($batchIds as $batchId) {
    $batchStmt->execute(['id' => $batchId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        $failCount++;
        $failMessages[] = "#{$batchId}: পার্সেল পাওয়া যায়নি";
        continue;
    }
    // নিষ্ক্রিয় রেজিস্ট্রেশন বা ইতিমধ্যে সফলভাবে পাঠানো ব্যাচ — নীরবে বাদ (UI-তেও checkbox disabled থাকে,
    // কিন্তু সার্ভার-সাইডেও যাচাই করা হয় যাতে ডুপ্লিকেট চালান তৈরি না হয়)
    if ((int) $batch['courier_active'] !== 1 || $batch['send_status'] === 'sent') {
        $skipCount++;
        continue;
    }

    $regStmt->execute(['id' => (int) $batch['reg_id']]);
    $order = $regStmt->fetch();
    if (!$order) {
        $failCount++;
        $failMessages[] = "#{$batchId}: রেজিস্ট্রেশন পাওয়া যায়নি";
        continue;
    }

    // $post খালি — save_courier_batch এই ব্যাচের বিদ্যমান মান (কালেকশন/লেবেল/বিবরণ) অপরিবর্তিত রেখে পাঠায়
    $result = send_courier_batch($db, $provider, $order, [], $batchId);
    if ($result['success']) {
        $successCount++;
    } else {
        $failCount++;
        $failMessages[] = "#{$batchId} ({$order['item_title']}): {$result['message']}";
    }
}

$summary = "{$successCount} টি পার্সেল সফলভাবে কুরিয়ারে পাঠানো হয়েছে";
if ($skipCount > 0) {
    $summary .= ", {$skipCount} টি বাদ পড়েছে (নিষ্ক্রিয় অথবা আগেই পাঠানো)";
}
if ($failCount > 0) {
    $summary .= ", {$failCount} টি ব্যর্থ — " . implode('; ', array_slice($failMessages, 0, 5));
    if (count($failMessages) > 5) {
        $summary .= ' ...';
    }
} else {
    $summary .= '।';
}

set_flash($failCount > 0 ? 'error' : 'success', $summary);
redirect($returnUrl);
