<?php
// সাইটের নিজের ডিজাইনে "পাতাটি পাওয়া যায়নি" পেজ (২০২৬-০৯-২৭)।
// আগে `.htaccess`-এ কোনো ErrorDocument ছিল না — ভুল ঠিকানায় গেলে হোস্টিংয়ের সাদা
// "Not Found" পাতা দেখাত, ভিজিটর ভাবতেন সাইটটাই বন্ধ।
//
// 🔴 এখানে `http_response_code(404)` রাখা **বাধ্যতামূলক** — নাহলে গুগল ভুল ঠিকানাগুলোকেও
//    আসল পেজ ভেবে ইনডেক্স করে ফেলবে ("soft 404")।
// ⚠️ Apache ErrorDocument দিয়ে আসলে REQUEST_URI-তে আসল (ভুল) ঠিকানাটাই থাকে, তাই
//    site-header.php-এর canonical ঐ অস্তিত্বহীন URL বসাত — সেজন্য `$pageNoIndex` দিয়ে
//    এই পেজে robots: noindex পাঠানো হয়।
require_once __DIR__ . '/includes/functions.php';

http_response_code(404);

$pageTitle = 'পাতাটি পাওয়া যায়নি';
$activePage = '';
$pageDescription = 'আপনি যে পাতাটি খুঁজছেন সেটি পাওয়া যায়নি। নিচের লিংকগুলো থেকে দরকারি পাতায় যেতে পারেন।';
$pageNoIndex = true;

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-2xl mx-auto text-center py-10 sm:py-16">
    <div class="text-7xl sm:text-8xl font-black mb-4" style="color:rgb(var(--c-primary));">404</div>
    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 mb-3">দুঃখিত, পাতাটি খুঁজে পাওয়া যায়নি</h1>
    <p class="text-gray-600 text-base sm:text-lg mb-8 leading-relaxed">
        ঠিকানাটি হয়তো ভুল টাইপ হয়েছে, অথবা পাতাটি সরিয়ে ফেলা হয়েছে।<br class="hidden sm:block">
        নিচের যেকোনো একটায় যেতে পারেন —
    </p>

    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4 mb-8 text-left">
        <?php
        // 🔴 সব লিংক extensionless + রিলেটিভ (ক্লিন-URL নিয়ম, সাবডিরেক্টরিতেও চলে)
        $lostLinks = [
            ['./',         'home',       'হোম'],
            ['courses',    'book-open',  'কোর্স সমূহ'],
            ['worksheets', 'file-text',  'ওয়ার্কশিট'],
            ['products',   'shopping-bag', 'প্রোডাক্ট'],
            ['notice',     'bell',       'নোটিশ'],
            ['about',      'phone',      'যোগাযোগ'],
            ['search',     'search',     'খুঁজুন'],
        ];
        foreach ($lostLinks as [$u, $ic, $lb]):
        ?>
            <a href="<?= e($u) ?>" class="colorful-card rounded-2xl shadow p-4 card-hover border border-white/30 flex items-center gap-3">
                <span class="icon-circle bg-indigo-100 text-indigo-600 w-9 h-9 flex-shrink-0"><i data-lucide="<?= e($ic) ?>" class="w-5 h-5"></i></span>
                <span class="font-semibold text-gray-800 text-sm sm:text-base"><?= e($lb) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <a href="./" class="btn-primary inline-flex items-center justify-center gap-2 px-8 py-3 rounded-2xl font-bold">
        <i data-lucide="home" class="w-5 h-5"></i> হোম পেজে ফিরে যান
    </a>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
