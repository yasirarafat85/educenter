<?php
require_once __DIR__ . '/includes/functions.php';

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$item = fetch_item($type, $id);

if (!$item) {
    http_response_code(404);
    $pageTitle = 'পাওয়া যায়নি';
    $activePage = '';
    require __DIR__ . '/includes/site-header.php';
    echo '<div class="text-center text-2xl text-gray-600 py-20">দুঃখিত, এই আইটেমটি পাওয়া যায়নি।</div>';
    require __DIR__ . '/includes/site-footer.php';
    exit;
}

$pageTitle = $item['title'];
// আইটেম-ভিত্তিক SEO/শেয়ার মেটা — নির্দিষ্ট কোর্স/আইটেমের লিংক WhatsApp/FB এ শেয়ার করলে ঐ আইটেমের
// বিবরণ ও ছবি প্রিভিউতে দেখাবে (site-header.php এই দুটো ভ্যারিয়েবল পড়ে)
$pageDescription = mb_substr(trim(strip_tags($item['description'] ?? '')), 0, 155) ?: ($item['title'] . ' — ' . ($item['price'] ?? ''));
$pageOgImage = $item['image'] ?? '';
$activePage = $type === 'course' ? 'courses' : ($type === 'worksheet' ? 'worksheets' : 'products');
$backUrl = $type === 'course' ? 'courses' : ($type === 'worksheet' ? 'worksheets' : 'products');
$registrationClosed = $type === 'course' && empty($item['registration_open']);
$actionLabel = $registrationClosed ? 'রেজিস্ট্রেশন বন্ধ' : ($type === 'course' ? 'Register Now - রেজিস্ট্রেশন করুন' : 'Order Now - অর্ডার করুন');
$actionUrl = $type === 'course' ? ('course-register?course_id=' . (int) $item['id']) : ('register?type=' . urlencode($type) . '&id=' . (int) $item['id']);

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-3xl mx-auto pb-28 sm:pb-0">
    <a href="<?= e($backUrl) ?>" class="inline-flex items-center gap-1.5 text-indigo-600 font-semibold text-sm mb-5 hover:gap-2.5 transition-all">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> ফিরে যান
    </a>

    <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-6"><?= e($item['title']) ?></h1>

        <img src="<?= e($item['image'] ?: 'https://placehold.co/800x600?text=No+Image') ?>" alt="<?= e($item['title']) ?>" class="w-full object-cover rounded-xl mb-6 shadow-lg bg-white" style="aspect-ratio:4/3;">

        <div class="space-y-6">
            <p class="text-gray-700 text-base sm:text-lg leading-relaxed"><?= nl2br(e($item['description'] ?? '')) ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex items-center gap-3 bg-gradient-to-r from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                    <span class="icon-circle bg-blue-200 text-blue-700 w-10 h-10 flex-shrink-0"><i data-lucide="wallet" class="w-5 h-5"></i></span>
                    <span class="text-blue-800 font-bold text-lg">মূল্য: <?= e($item['price'] ?? '') ?></span>
                </div>
                <?php if (!empty($item['duration'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-green-50 to-green-100 p-4 rounded-xl border border-green-200">
                    <span class="icon-circle bg-green-200 text-green-700 w-10 h-10 flex-shrink-0"><i data-lucide="clock" class="w-5 h-5"></i></span>
                    <span class="text-green-800 font-bold text-lg">মেয়াদ: <?= e($item['duration']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['instructor'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-indigo-50 to-indigo-100 p-4 rounded-xl border border-indigo-200">
                    <span class="icon-circle bg-indigo-200 text-indigo-700 w-10 h-10 flex-shrink-0"><i data-lucide="user" class="w-5 h-5"></i></span>
                    <span class="text-indigo-800 font-bold text-lg">প্রশিক্ষক: <?= e($item['instructor']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['pages'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-purple-50 to-purple-100 p-4 rounded-xl border border-purple-200">
                    <span class="icon-circle bg-purple-200 text-purple-700 w-10 h-10 flex-shrink-0"><i data-lucide="file-text" class="w-5 h-5"></i></span>
                    <span class="text-purple-800 font-bold text-lg">পৃষ্ঠা: <?= e($item['pages']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['level'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-amber-50 to-amber-100 p-4 rounded-xl border border-amber-200">
                    <span class="icon-circle bg-amber-200 text-amber-700 w-10 h-10 flex-shrink-0"><i data-lucide="target" class="w-5 h-5"></i></span>
                    <span class="text-amber-800 font-bold text-lg">লেভেল: <?= e($item['level']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($item['features'])): ?>
            <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-6 rounded-xl">
                <h3 class="font-bold text-gray-900 mb-4 text-xl flex items-center gap-2">
                    <i data-lucide="sparkles" class="w-5 h-5 text-violet-500"></i> বৈশিষ্ট্য
                </h3>
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($item['features'] as $feature): ?>
                    <li class="flex items-center text-gray-700">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-violet-500 mr-2 flex-shrink-0"></i>
                        <?= e($feature) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <a href="<?= e($actionUrl) ?>" class="hidden sm:block w-full text-center py-4 px-6 rounded-xl font-bold text-lg shadow-lg <?= $registrationClosed ? 'bg-gray-300 text-gray-600' : 'btn-primary text-white' ?>">
                <?= e($actionLabel) ?>
            </a>
        </div>
    </div>
</div>

<!-- মোবাইলে নিচে স্থির (sticky) CTA বার — স্ক্রল করলেও সবসময় দেখা যায়, রেজিস্ট্রেশন সহজ করে (ডেস্কটপে লুকানো) -->
<?php if ($registrationClosed): ?>
<div class="sm:hidden fixed inset-x-0 bottom-0 z-40 bg-gray-100 border-t border-gray-200 px-4 py-3 text-center text-gray-600 font-bold" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
    রেজিস্ট্রেশন বন্ধ
</div>
<?php else: ?>
<div class="sm:hidden fixed inset-x-0 bottom-0 z-40 bg-white border-t border-gray-200 px-4 py-3 flex items-center gap-3" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
    <div class="flex-shrink-0 min-w-0">
        <p class="text-xs text-gray-500 leading-none mb-0.5">মূল্য</p>
        <p class="font-black text-lg leading-none truncate" style="color:rgb(var(--c-primary));"><?= e($item['price'] ?? '') ?></p>
    </div>
    <a href="<?= e($actionUrl) ?>" class="flex-1 text-center py-3 rounded-xl font-bold text-white shadow-lg" style="background:linear-gradient(135deg,rgb(var(--c-primary-2)),rgb(var(--c-primary)));">
        <?= e($actionLabel) ?>
    </a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
