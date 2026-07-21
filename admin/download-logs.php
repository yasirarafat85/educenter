<?php
// register-thanks.php থেকে কনফার্মেশন কার্ড ছবি হিসেবে ডাউনলোড করলে যে লগ তৈরি হয় (log-download.php), তার তালিকা

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'ডাউনলোড লগ';
$typeLabels = ['course' => 'কোর্স', 'worksheet' => 'ওয়ার্কশিট', 'product' => 'প্রোডাক্ট'];

$rows = $db->query(
    "SELECT cd.*, r.type, r.item_title, r.customer_name, r.phone
     FROM confirmation_downloads cd
     JOIN registrations r ON r.id = cd.registration_id
     ORDER BY cd.downloaded_at DESC"
)->fetchAll();

require __DIR__ . '/includes/layout-top.php';
?>

<p class="text-sm text-gray-500 mb-4">মোট <?= count($rows) ?> টি ডাউনলোড — রেজিস্ট্রেশন/অর্ডার কনফার্মেশন পেজ থেকে কারা "ডাউনলোড করুন" বাটনে ক্লিক করেছেন তার লগ।</p>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">Row ID</th>
                <th class="py-3 px-4">নাম</th>
                <th class="py-3 px-4">ফোন</th>
                <th class="py-3 px-4">টাইপ</th>
                <th class="py-3 px-4">আইটেম</th>
                <th class="py-3 px-4">IP</th>
                <th class="py-3 px-4">ডাউনলোডের সময়</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="py-6 px-4 text-center text-gray-400">এখনো কেউ ডাউনলোড করেনি।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4 font-mono text-xs">#<?= $row['registration_id'] ?></td>
                <td class="py-2.5 px-4"><?= e($row['customer_name']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['phone']) ?></td>
                <td class="py-2.5 px-4"><?= e($typeLabels[$row['type']] ?? $row['type']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                <td class="py-2.5 px-4 font-mono text-xs"><?= e(format_ip_display($row['ip_address'])) ?></td>
                <td class="py-2.5 px-4"><?= e($row['downloaded_at']) ?></td>
                <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['registration_id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
