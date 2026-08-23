<?php
// অভিভাবক ড্যাশবোর্ড — নিজের কেনা কোর্স, খরচ, প্রাইভেট গ্রুপ লিংক।
// 🔴 প্রাইভেসি: সব কোয়েরি শুধু লগইন-করা ইউজারের নিজের phone দিয়ে — কখনো URL/প্যারামিটার থেকে নয়।
require_once __DIR__ . '/includes/user-auth.php';

user_require_login();
$user = user_current();          // approved না হলে null + অটো-লগআউট
if (!$user) {
    redirect('account-login');
}
$phone = $user['phone'];

$db = get_db();

// এই ইউজারের কোর্স রেজিস্ট্রেশন (নিজের ফোন) + গ্রুপ লিংক (course_batches থেকে)
$stmt = $db->prepare(
    "SELECT r.id, r.item_id, r.item_title, r.batch, r.customer_name, r.status,
            r.income_amount, r.income_approved, r.created_at,
            cb.price, cb.fb_group_url, cb.messenger_group_url
     FROM registrations r
     LEFT JOIN course_batches cb ON cb.id = r.item_id
     WHERE r.phone = :p AND r.type = 'course'
     ORDER BY r.created_at DESC"
);
$stmt->execute(['p' => $phone]);
$courses = $stmt->fetchAll();

// মোট খরচ — এই ফোনের অনুমোদিত আয় (courses + অন্য অর্ডার সব মিলিয়ে যা পরিশোধ করেছেন)
$stmt = $db->prepare("SELECT COALESCE(SUM(income_amount),0) s FROM registrations WHERE phone = :p AND income_approved = 1");
$stmt->execute(['p' => $phone]);
$totalSpent = (float) $stmt->fetch()['s'];

// পুরনো (legacy) রেকর্ড — এই ফোন মিলিয়ে (আয়/কুরিয়ার নয়, শুধু ঐতিহাসিক তথ্য)। টেবিল না থাকলে চুপচাপ বাদ।
$legacy = [];
try {
    $ls = $db->prepare("SELECT customer_name, course_title, batch, facebook_id FROM legacy_students WHERE RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = :k ORDER BY id DESC");
    $ls->execute(['k' => phone_last10($phone)]);
    $legacy = $ls->fetchAll();
} catch (Throwable $e) { $legacy = []; }

// প্রাইভেট গ্রুপ লিংক — কেনা কোর্স থেকে distinct
$groups = [];
foreach ($courses as $c) {
    if (!empty($c['fb_group_url']))        $groups['fb:' . $c['fb_group_url']] = ['type' => 'fb', 'url' => $c['fb_group_url'], 'title' => $c['item_title']];
    if (!empty($c['messenger_group_url'])) $groups['ms:' . $c['messenger_group_url']] = ['type' => 'ms', 'url' => $c['messenger_group_url'], 'title' => $c['item_title']];
}

$statusMeta = [
    'pending'   => ['অপেক্ষমাণ', 'bg-amber-100 text-amber-700'],
    'confirmed' => ['নিশ্চিত', 'bg-green-100 text-green-700'],
    'shipped'   => ['পাঠানো হয়েছে', 'bg-blue-100 text-blue-700'],
    'delivered' => ['ডেলিভারড', 'bg-green-100 text-green-700'],
    'cancelled' => ['বাতিল', 'bg-red-100 text-red-700'],
];

$pageTitle = 'আমার অ্যাকাউন্ট';
$activePage = '';
require __DIR__ . '/includes/site-header.php';
?>
<div class="max-w-3xl mx-auto pb-10">
    <!-- হেডার -->
    <div class="flex items-center justify-between flex-wrap gap-3 mb-6">
        <div>
            <h1 class="text-2xl font-black text-gray-900">স্বাগতম<?= $user['full_name'] ? ', ' . e($user['full_name']) : '' ?>!</h1>
            <p class="text-gray-500 text-sm">মোবাইল: <?= e($phone) ?></p>
        </div>
        <div class="flex gap-2">
            <a href="account-password" class="text-sm font-semibold text-indigo-600 bg-indigo-50 px-4 py-2 rounded-xl">পাসওয়ার্ড</a>
            <a href="account-logout" class="text-sm font-semibold text-red-600 bg-red-50 px-4 py-2 rounded-xl">লগআউট</a>
        </div>
    </div>

    <!-- খরচ -->
    <div class="bg-gradient-to-r from-indigo-50 to-indigo-100 border border-indigo-200 rounded-2xl p-5 mb-6 flex items-center gap-4">
        <span class="inline-flex w-12 h-12 items-center justify-center rounded-full bg-indigo-200 text-indigo-700"><i data-lucide="wallet" class="w-6 h-6"></i></span>
        <div>
            <p class="text-indigo-700 text-sm font-semibold">মোট খরচ (পরিশোধিত)</p>
            <p class="text-2xl font-black text-indigo-900"><?= number_format($totalSpent) ?> ৳</p>
        </div>
    </div>

    <!-- প্রাইভেট গ্রুপ -->
    <?php if ($groups): ?>
    <div class="bg-white rounded-2xl shadow p-5 mb-6">
        <h2 class="font-bold text-gray-900 mb-3 flex items-center gap-2"><i data-lucide="users" class="w-5 h-5 text-violet-500"></i> প্রাইভেট গ্রুপ</h2>
        <div class="flex flex-col gap-2.5">
            <?php foreach ($groups as $g): ?>
                <a href="<?= e($g['url']) ?>" target="_blank" rel="noopener" class="flex items-center gap-3 p-3 rounded-xl <?= $g['type'] === 'fb' ? 'bg-blue-50 hover:bg-blue-100' : 'bg-sky-50 hover:bg-sky-100' ?>">
                    <span class="text-white text-xs font-bold px-2 py-1 rounded" style="background:<?= $g['type'] === 'fb' ? '#1877F2' : '#0084FF' ?>;"><?= $g['type'] === 'fb' ? 'Facebook' : 'Messenger' ?></span>
                    <span class="text-gray-700 text-sm font-semibold truncate"><?= e($g['title']) ?> গ্রুপে যোগ দিন →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- কেনা কোর্স -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-4 flex items-center gap-2"><i data-lucide="book-open" class="w-5 h-5 text-indigo-500"></i> আমার কোর্স</h2>
        <?php if (!$courses): ?>
            <p class="text-gray-400 text-sm text-center py-6">এখনো কোনো কোর্স রেজিস্ট্রেশন নেই।</p>
        <?php else: ?>
        <div class="flex flex-col gap-3">
            <?php foreach ($courses as $c):
                $amount = ($c['income_approved'] && $c['income_amount'] !== null) ? number_format((float) $c['income_amount']) . ' ৳' : ($c['price'] ?: '-');
                [$sLabel, $sClass] = $statusMeta[$c['status']] ?? [$c['status'], 'bg-gray-100 text-gray-600'];
            ?>
            <div class="border border-gray-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 break-words"><?= e($c['item_title']) ?></p>
                        <p class="text-gray-500 text-xs mt-0.5">
                            <?= $c['batch'] ? 'ব্যাচ: ' . e($c['batch']) . ' · ' : '' ?>শিশু: <?= e($c['customer_name']) ?>
                        </p>
                        <p class="text-gray-400 text-xs mt-0.5"><?= e(date('Y-m-d', strtotime($c['created_at']))) ?></p>
                    </div>
                    <div class="text-right flex-shrink-0">
                        <span class="text-xs font-bold px-2.5 py-1 rounded-lg <?= $sClass ?>"><?= e($sLabel) ?></span>
                        <p class="font-black text-gray-800 mt-1.5 text-sm"><?= e($amount) ?></p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php // পুরনো তথ্য (legacy) — এই ফোনে আগের কোনো রেকর্ড থাকলে ?>
    <?php if ($legacy): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-1 flex items-center gap-2"><i data-lucide="history" class="w-5 h-5 text-amber-500"></i> আপনার পুরনো তথ্য</h2>
        <p class="text-gray-400 text-xs mb-4">আমাদের আগের রেকর্ড অনুযায়ী (এই মোবাইল নম্বরে)</p>
        <div class="flex flex-col gap-3">
            <?php foreach ($legacy as $lg): ?>
            <div class="border border-amber-200 bg-amber-50 rounded-xl p-4">
                <p class="font-bold text-gray-900 break-words"><?= e($lg['customer_name'] ?: '—') ?></p>
                <p class="text-gray-600 text-xs mt-0.5">
                    <?= $lg['course_title'] ? 'কোর্স: ' . e($lg['course_title']) : '' ?><?= $lg['batch'] ? ' · ব্যাচ/সাল: ' . e($lg['batch']) : '' ?>
                </p>
                <?php if ($lg['facebook_id']): ?><p class="text-gray-400 text-xs mt-0.5">ফেসবুক: <?= e($lg['facebook_id']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
