<?php
// রেজিস্ট্রেশন এরর লগ — পাবলিক ফর্মে (কোর্স/অর্ডার/আগ্রহ) কেউ আটকালে কারণসহ এখানে দেখায়।
// অ্যাডমিন বুঝবে আসল কাস্টমাররা কোথায়/কেন আটকাচ্ছে (ডায়াগনস্টিক)। সাম্প্রতিক ৩০০টা।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$db = get_db();
$pageTitle = 'রেজিস্ট্রেশন এরর লগ';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('registration-errors.php'); }
    if (($_POST['action'] ?? '') === 'clear-all') {
        $db->exec('DELETE FROM registration_errors');
        set_flash('success', 'সব এরর লগ মুছে ফেলা হয়েছে।');
    }
    redirect('registration-errors.php');
}

$typeLabel = ['course' => 'কোর্স রেজিস্ট্রেশন', 'order' => 'ওয়ার্কশিট/প্রোডাক্ট অর্ডার', 'interest' => 'আগ্রহ ফর্ম'];
$typeClass = ['course' => 'bg-indigo-100 text-indigo-700', 'order' => 'bg-blue-100 text-blue-700', 'interest' => 'bg-pink-100 text-pink-700'];

$total = (int) $db->query('SELECT COUNT(*) FROM registration_errors')->fetchColumn();
$last7 = (int) $db->query("SELECT COUNT(*) FROM registration_errors WHERE created_at > (NOW() - INTERVAL 7 DAY)")->fetchColumn();
// সবচেয়ে বেশি যে কারণে আটকাচ্ছে (টপ ৫)
$topReasons = $db->query("SELECT error_message, COUNT(*) n FROM registration_errors GROUP BY error_message ORDER BY n DESC LIMIT 5")->fetchAll();
$rows = $db->query('SELECT * FROM registration_errors ORDER BY id DESC LIMIT 300')->fetchAll();

require __DIR__ . '/includes/layout-top.php';
?>
<div class="max-w-5xl">
    <p class="text-sm text-gray-500 mb-4">কোনো ভিজিটর রেজিস্ট্রেশন/অর্ডার/আগ্রহ ফর্মে আটকালে (যেমন "মোবাইল সঠিক নয়") কারণসহ এখানে জমা হয় — কে কোথায় আটকাচ্ছে বুঝে ঠিক করা যায়। <span class="text-gray-400">(সাম্প্রতিক ৩০০টা; ৬০ দিনের পুরনো অটো-মুছে যায়।)</span></p>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-2xl shadow p-4"><div class="text-xs text-gray-500">মোট এরর</div><div class="text-2xl font-black text-gray-900"><?= number_format($total) ?></div></div>
        <div class="bg-white rounded-2xl shadow p-4"><div class="text-xs text-gray-500">গত ৭ দিনে</div><div class="text-2xl font-black text-red-600"><?= number_format($last7) ?></div></div>
    </div>

    <?php if ($topReasons): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4">
        <div class="text-sm font-bold text-gray-700 mb-2">সবচেয়ে বেশি যে কারণে আটকাচ্ছে</div>
        <div class="flex flex-col gap-1.5">
            <?php foreach ($topReasons as $tr): ?>
                <div class="flex items-center justify-between gap-2 text-sm">
                    <span class="text-gray-700 break-words"><?= e($tr['error_message']) ?></span>
                    <span class="bg-red-50 text-red-700 font-bold px-2.5 py-0.5 rounded-lg whitespace-nowrap"><?= number_format((int) $tr['n']) ?> বার</span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="flex items-center justify-end mb-2">
        <?php if ($total > 0): ?>
        <form method="post" action="registration-errors.php" onsubmit="return confirmSubmit(this, 'সব এরর লগ মুছে ফেলবেন?', 'সব মুছবেন?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="clear-all">
            <button type="submit" class="text-red-600 text-sm font-semibold">সব মুছুন</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$rows): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="shield-check" class="w-8 h-8"></i></div>এখনো কোনো এরর নেই — সব ঠিকঠাক চলছে। 🎉</div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">সময়</th><th class="py-3 px-4">ফর্ম</th><th class="py-3 px-4">কারণ</th>
                <th class="py-3 px-4">নাম</th><th class="py-3 px-4">মোবাইল</th><th class="py-3 px-4">IP</th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4 whitespace-nowrap text-gray-500 text-xs"><?= e(date('d/m/y H:i', strtotime($r['created_at']))) ?></td>
                    <td class="py-2.5 px-4"><span class="text-xs font-semibold px-2 py-0.5 rounded-lg <?= $typeClass[$r['form_type']] ?? 'bg-gray-100 text-gray-600' ?>"><?= e($typeLabel[$r['form_type']] ?? $r['form_type']) ?></span></td>
                    <td class="py-2.5 px-4 font-semibold text-red-700 break-words"><?= e($r['error_message']) ?></td>
                    <td class="py-2.5 px-4"><?= e($r['entered_name'] ?: '—') ?></td>
                    <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($r['entered_phone'] ?: '—') ?></td>
                    <td class="py-2.5 px-4 text-xs text-gray-400 whitespace-nowrap"><?= e(function_exists('format_ip_display') ? format_ip_display($r['ip_address']) : $r['ip_address']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
