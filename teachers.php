<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'শিক্ষক মন্ডলী';
$activePage = 'teachers';

$teachers = get_db()->query('SELECT * FROM teachers WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('users', 'আমাদের টিম', 'শিক্ষক মন্ডলী', 'অভিজ্ঞ এবং দক্ষ শিক্ষকদের সাথে পরিচিত হন যারা আপনার সফলতার সাথী', 'text-indigo-700') ?>
    <?php if ($teachers): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
        <?php foreach ($teachers as $t): ?>
        <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8 text-center card-hover border border-white/30">
            <img src="<?= e($t['image'] ?: 'https://placehold.co/300x300?text=Teacher') ?>" alt="<?= e($t['name']) ?>" class="w-32 h-32 sm:w-40 sm:h-40 rounded-full mx-auto mb-6 object-cover shadow-lg ring-4 ring-indigo-100">
            <h3 class="text-xl sm:text-2xl font-bold text-gray-900 mb-2"><?= e($t['name']) ?></h3>
            <p class="text-indigo-600 font-bold mb-3 text-base sm:text-lg"><?= e($t['subject']) ?></p>
            <?php if ($t['experience']): ?>
            <span class="inline-flex items-center gap-1.5 text-gray-500 text-sm mb-4">
                <i data-lucide="award" class="w-4 h-4"></i> অভিজ্ঞতা: <?= e($t['experience']) ?>
            </span>
            <?php endif; ?>
            <?php if ($t['quote']): ?>
            <div class="bg-indigo-50 p-4 rounded-xl">
                <p class="text-indigo-800 font-medium text-sm sm:text-base italic">"<?= e($t['quote']) ?>"</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো শিক্ষক যোগ করা হয়নি।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
