<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'আমাদের সম্পর্কে';
$activePage = 'about';

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-5xl mx-auto">
    <?= render_page_header('info', 'পরিচিতি', 'আমাদের সম্পর্কে', get_setting('site_name', 'EduCenter') . ' এর যাত্রা, লক্ষ্য এবং আমাদের শিক্ষার দর্শন', 'text-teal-700') ?>
    <div class="prose prose-lg max-w-none">
        <div class="colorful-card rounded-2xl shadow-lg p-8 sm:p-10 mb-10 border border-white/30">
            <h2 class="text-2xl sm:text-3xl font-bold mb-6 text-gray-900 flex items-center gap-3">
                <span class="icon-circle bg-teal-100 text-teal-600 w-11 h-11"><i data-lucide="target" class="w-5 h-5"></i></span>
                আমাদের লক্ষ্য
            </h2>
            <p class="text-gray-700 mb-8 text-base sm:text-lg leading-relaxed"><?= nl2br(e(get_about_setting('about_mission_text'))) ?></p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 sm:gap-8">
            <div class="bg-gradient-to-r from-blue-50 to-blue-100 rounded-2xl p-6 sm:p-8 shadow-lg border border-blue-200 card-hover">
                <i data-lucide="award" class="w-12 h-12 sm:w-16 sm:h-16 text-blue-600 mb-6"></i>
                <h3 class="text-xl sm:text-2xl font-bold mb-4 text-gray-900">আমাদের মিশন</h3>
                <p class="text-gray-700 leading-relaxed text-base sm:text-lg"><?= nl2br(e(get_about_setting('about_vision_text'))) ?></p>
            </div>

            <div class="bg-gradient-to-r from-green-50 to-green-100 rounded-2xl p-6 sm:p-8 shadow-lg border border-green-200 card-hover">
                <i data-lucide="users" class="w-12 h-12 sm:w-16 sm:h-16 text-green-600 mb-6"></i>
                <h3 class="text-xl sm:text-2xl font-bold mb-4 text-gray-900">আমাদের দল</h3>
                <p class="text-gray-700 leading-relaxed text-base sm:text-lg"><?= nl2br(e(get_about_setting('about_team_text'))) ?></p>
            </div>
        </div>

        <div class="bg-gradient-to-r from-purple-50 to-pink-50 rounded-2xl p-8 sm:p-10 mt-8 shadow-lg border border-purple-200">
            <h3 class="text-xl sm:text-2xl font-bold mb-6 text-gray-900 text-center flex items-center justify-center gap-2">
                <i data-lucide="sparkles" class="w-6 h-6 text-purple-500"></i> আমাদের বিশেষত্ব
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="text-center">
                    <div class="bg-purple-100 p-4 rounded-xl mb-4 inline-block">
                        <i data-lucide="monitor" class="w-6 h-6 sm:w-8 sm:h-8 text-purple-600"></i>
                    </div>
                    <h4 class="font-bold text-gray-900 mb-2 text-base sm:text-lg"><?= e(get_about_setting('about_feature1_title')) ?></h4>
                    <p class="text-gray-600 text-sm sm:text-base"><?= nl2br(e(get_about_setting('about_feature1_text'))) ?></p>
                </div>
                <div class="text-center">
                    <div class="bg-blue-100 p-4 rounded-xl mb-4 inline-block">
                        <i data-lucide="clock" class="w-6 h-6 sm:w-8 sm:h-8 text-blue-600"></i>
                    </div>
                    <h4 class="font-bold text-gray-900 mb-2 text-base sm:text-lg"><?= e(get_about_setting('about_feature2_title')) ?></h4>
                    <p class="text-gray-600 text-sm sm:text-base"><?= nl2br(e(get_about_setting('about_feature2_text'))) ?></p>
                </div>
                <div class="text-center">
                    <div class="bg-green-100 p-4 rounded-xl mb-4 inline-block">
                        <i data-lucide="target" class="w-6 h-6 sm:w-8 sm:h-8 text-green-600"></i>
                    </div>
                    <h4 class="font-bold text-gray-900 mb-2 text-base sm:text-lg"><?= e(get_about_setting('about_feature3_title')) ?></h4>
                    <p class="text-gray-600 text-sm sm:text-base"><?= nl2br(e(get_about_setting('about_feature3_text'))) ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
