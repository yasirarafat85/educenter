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
        <div class="mt-14 sm:mt-16 bg-violet-50 border border-violet-100 rounded-3xl p-4 sm:p-8">
            <div class="rounded-2xl p-5 sm:p-6 mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center gap-3 text-center sm:text-left shadow-lg" style="background:linear-gradient(135deg,#6366f1 0%,#7c3aed 100%);">
                <span class="text-4xl sm:text-5xl">🔜</span>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-black text-white leading-tight">আসছে শীঘ্রই</h2>
                    <p class="text-violet-100 text-sm sm:text-base mt-1">রেজিস্ট্রেশন এখন বন্ধ — <strong>"জানিয়ে রাখুন"</strong> চাপুন, নতুন ব্যাচ খুললেই আপনাকে যোগাযোগ করব</p>
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
