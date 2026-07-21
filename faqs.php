<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'প্রায়শ জিজ্ঞাসিত প্রশ্ন';
$activePage = 'faqs';

$faqs = get_db()->query('SELECT * FROM faqs WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-4xl mx-auto">
    <?= render_page_header('help-circle', 'সাহায্য কেন্দ্র', 'প্রায়শ জিজ্ঞাসিত প্রশ্ন', 'আপনার মনের সব প্রশ্নের উত্তর এখানে পাবেন', 'text-cyan-700') ?>
    <?php if ($faqs): ?>
    <div class="space-y-5 sm:space-y-6">
        <?php foreach ($faqs as $f): ?>
        <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8 card-hover border border-white/30">
            <h3 class="text-lg sm:text-xl font-bold text-gray-900 mb-4 flex items-start gap-3">
                <span class="icon-circle bg-indigo-100 text-indigo-600 flex-shrink-0 w-9 h-9"><i data-lucide="help-circle" class="w-5 h-5"></i></span>
                <span class="pt-1"><?= e($f['question']) ?></span>
            </h3>
            <p class="text-gray-700 leading-relaxed text-base sm:text-lg pl-12"><?= nl2br(e($f['answer'])) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো প্রশ্ন যোগ করা হয়নি।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
