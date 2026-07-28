<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'কোর্স সমূহ';
$activePage = 'courses';
$pageDescription = 'আমাদের সব কোর্স — বিশেষজ্ঞ শিক্ষকদের তত্ত্বাবধানে উন্নতমানের কোর্স এবং আধুনিক শিক্ষা পদ্ধতি। এখনই রেজিস্ট্রেশন করুন।';

$courses = get_db()->query(
    'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1 ORDER BY cb.sort_order ASC, cb.id ASC'
)->fetchAll();

// খোলা (চলমান) ও বন্ধ ("আসছে শীঘ্রই") আলাদা করা — বন্ধগুলো আলাদা সেকশনে দেখানো হয়
$openCourses   = array_filter($courses, fn($c) => !empty($c['registration_open']));
$closedCourses = array_filter($courses, fn($c) => empty($c['registration_open']));

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('book-open', 'আমাদের কোর্স', 'সব কোর্স সমূহ', 'বিশেষজ্ঞ শিক্ষকদের তত্বাবধানে উন্নতমানের কোর্স এবং আধুনিক শিক্ষা পদ্ধতি', 'text-blue-700') ?>

    <?php if (!$courses): ?>
        <p class="text-center text-gray-500">এখনো কোনো কোর্স যোগ করা হয়নি।</p>
    <?php else: ?>

        <?php if ($openCourses): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
            <?= implode('', array_map(fn($c) => render_item_card($c, 'course'), $openCourses)) ?>
        </div>
        <?php endif; ?>

        <?php if ($closedCourses): ?>
        <div class="mt-14 sm:mt-16">
            <div class="flex items-center gap-3 mb-6 sm:mb-8">
                <span class="text-2xl">🔜</span>
                <div>
                    <h2 class="text-xl sm:text-2xl font-black text-gray-800">আসছে শীঘ্রই</h2>
                    <p class="text-gray-500 text-sm">এই কোর্সগুলোর রেজিস্ট্রেশন এখন বন্ধ — আগ্রহ জানিয়ে রাখুন, নতুন ব্যাচ খুললে যোগাযোগ করব</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
                <?= implode('', array_map(fn($c) => render_item_card($c, 'course'), $closedCourses)) ?>
            </div>
        </div>
        <?php endif; ?>

    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
