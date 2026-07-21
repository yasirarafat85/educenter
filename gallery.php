<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'গ্যালারি';
$activePage = 'gallery';

$images = get_db()->query('SELECT * FROM gallery ORDER BY sort_order ASC, id ASC')->fetchAll();

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('image', 'মুহূর্তগুলো', 'গ্যালারি', 'আমাদের শিক্ষা প্রতিষ্ঠানের সুন্দর মুহূর্তগুলো', 'text-pink-700') ?>
    <?php if ($images): ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6 sm:gap-8">
        <?php foreach ($images as $img): ?>
        <div class="rounded-2xl overflow-hidden shadow-lg hover:shadow-2xl card-hover transition-all relative group">
            <img src="<?= e($img['image']) ?>" alt="<?= e($img['caption'] ?: 'Gallery') ?>" class="w-full h-48 sm:h-64 object-cover group-hover:scale-110 transition-transform duration-300">
            <?php if ($img['caption']): ?>
            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-4 opacity-0 group-hover:opacity-100 transition-opacity">
                <p class="text-white text-sm font-semibold"><?= e($img['caption']) ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো ছবি যোগ করা হয়নি।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
