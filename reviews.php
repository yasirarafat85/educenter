<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'শিক্ষার্থীদের মতামত';
$activePage = 'reviews';
$pageDescription = 'আমাদের কোর্স নিয়ে অভিভাবক ও শিক্ষার্থীদের সত্যিকারের অভিজ্ঞতা ও মতামত পড়ুন।';

$reviews = get_db()->query('SELECT * FROM reviews WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();

// 🔍 রিভিউ structured data — গুগল সার্চে প্রতিষ্ঠানের নামের পাশে ⭐ গড় রেটিং দেখাতে পারে।
// 🔴 রিভিউগুলো প্রতিষ্ঠানের (itemReviewed = Organization), কোনো একটা কোর্সের নয় —
//    অ্যাডমিন রিভিউ যোগ করার সময় কোন কোর্স সেটা নেওয়াই হয় না, তাই এটাই সৎ দাবি।
$pageJsonLd = '';
$ratings = array_values(array_filter(array_map(fn($r) => (int) ($r['rating'] ?? 0), $reviews), fn($n) => $n >= 1 && $n <= 5));
if ($ratings) {
    $rvItems = [];
    foreach ($reviews as $rv) {
        $rate = (int) ($rv['rating'] ?? 0);
        if ($rate < 1 || $rate > 5 || trim((string) ($rv['comment'] ?? '')) === '') {
            continue;
        }
        $rvItems[] = [
            '@type'         => 'Review',
            'author'        => ['@type' => 'Person', 'name' => trim((string) ($rv['student_name'] ?? 'একজন অভিভাবক'))],
            'reviewRating'  => ['@type' => 'Rating', 'ratingValue' => (string) $rate, 'bestRating' => '5'],
            'reviewBody'    => mb_substr(trim(strip_tags((string) $rv['comment'])), 0, 500),
        ];
    }
    $pageJsonLd = render_jsonld([
        '@context'        => 'https://schema.org',
        '@type'           => 'EducationalOrganization',
        'name'            => get_setting('site_name', 'EduCenter'),
        'url'             => defined('SITE_URL') ? rtrim(SITE_URL, '/') . '/' : '',
        'aggregateRating' => [
            '@type'       => 'AggregateRating',
            'ratingValue' => (string) round(array_sum($ratings) / count($ratings), 1),
            'reviewCount' => (string) count($ratings),
            'bestRating'  => '5',
        ],
        'review'          => $rvItems,
    ]);
}
$pageJsonLd .= jsonld_breadcrumb([['name' => 'মতামত', 'url' => 'reviews']]);

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-6xl mx-auto">
    <?= render_page_header('star', 'রিভিউ', 'শিক্ষার্থীদের মতামত', 'আমাদের সফল শিক্ষার্থীরা কি বলছেন তাদের অভিজ্ঞতা সম্পর্কে', 'text-amber-600') ?>
    <?php if ($reviews): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
        <?php foreach ($reviews as $r): ?>
        <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8 card-hover border border-white/30 relative">
            <i data-lucide="quote" class="w-8 h-8 text-indigo-100 absolute top-5 right-5"></i>
            <div class="flex items-center mb-6">
                <?php for ($i = 0; $i < (int) $r['rating']; $i++): ?>
                    <i data-lucide="star" class="w-5 h-5 sm:w-6 sm:h-6 text-amber-400 fill-current"></i>
                <?php endfor; ?>
            </div>
            <p class="text-gray-600 mb-6 italic text-base sm:text-lg leading-relaxed">"<?= e($r['comment']) ?>"</p>
            <div class="flex items-center">
                <img src="<?= e($r['image'] ?: placeholder_img('user')) ?>" alt="<?= e($r['student_name']) ?>" class="w-10 h-10 sm:w-12 sm:h-12 rounded-full mr-4 object-cover ring-2 ring-indigo-100" loading="lazy">
                <div>
                    <h4 class="font-bold text-gray-900 text-base sm:text-lg"><?= e($r['student_name']) ?></h4>
                    <?php if ($r['course_label']): ?><p class="text-indigo-600 font-semibold text-sm"><?= e($r['course_label']) ?></p><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো রিভিউ নেই।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
