<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'নোটিশ বোর্ড';
$activePage = 'notice';

$notices = get_db()->query('SELECT * FROM notices WHERE is_active = 1 ORDER BY notice_date DESC, id DESC')->fetchAll();
$colors = ['blue', 'green', 'yellow', 'purple', 'red'];

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-4xl mx-auto">
    <?= render_page_header('bell', 'ঘোষণা', 'নোটিশ বোর্ড', 'সর্বশেষ আপডেট ও গুরুত্বপূর্ণ ঘোষণা', 'text-red-600') ?>
    <?php if ($notices): ?>
    <div class="space-y-6 sm:space-y-8">
        <?php foreach ($notices as $i => $n): $color = $colors[$i % count($colors)]; ?>
        <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8 border-l-8 border-<?= $color ?>-500 card-hover">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-start mb-4 space-y-2 sm:space-y-0">
                <h3 class="text-xl sm:text-2xl font-bold text-gray-900"><?= e($n['title']) ?></h3>
                <span class="text-sm text-<?= $color ?>-700 bg-<?= $color ?>-200 px-4 py-2 rounded-full font-semibold"><?= format_date_bn($n['notice_date']) ?></span>
            </div>
            <p class="text-gray-700 text-base sm:text-lg leading-relaxed"><?= nl2br(e($n['content'])) ?></p>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো নোটিশ নেই।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
