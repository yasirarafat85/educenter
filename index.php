<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'হোম';
$activePage = 'home';
$pageDescription = get_setting('site_meta_description') ?: (get_setting('site_name', 'EduCenter') . ' — উন্নতমানের কোর্স, ওয়ার্কশিট ও শিক্ষা উপকরণ। বিশেষজ্ঞ শিক্ষকদের তত্ত্বাবধানে আধুনিক শিক্ষা পদ্ধতি।');

$db = get_db();
$featuredCourses = $db->query(
    'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1 ORDER BY cb.sort_order ASC, cb.id ASC LIMIT 3'
)->fetchAll();
$siteName = get_setting('site_name', 'EduCenter');

// হোমপেজ "সংখ্যায় সাফল্য" স্ট্যাট — অ্যাডমিন সেটিংস থেকে (খালি হলে ডিফল্ট)। [value, label, icon, color]
$statDefaults = [
    ['৫০০+', 'সফল শিক্ষার্থী',    'users',           'blue'],
    ['৫০+',  'কোর্স সমূহ',         'book-open',       'green'],
    ['২০+',  'অভিজ্ঞ শিক্ষক',     'graduation-cap',  'purple'],
    ['৯৮%',  'সন্তুষ্ট শিক্ষার্থী', 'heart-handshake', 'red'],
];
$stats = [];
foreach ($statDefaults as $si => $sd) {
    $sn = $si + 1;
    $stats[] = [
        'value' => get_setting("stat{$sn}_value") ?: $sd[0],
        'label' => get_setting("stat{$sn}_label") ?: $sd[1],
        'icon'  => $sd[2],
        'color' => $sd[3],
    ];
}

require __DIR__ . '/includes/site-header.php';
?>

<div class="space-y-12 sm:space-y-20">
    <section class="relative text-center gradient-bg text-white py-14 sm:py-20 rounded-3xl shadow-2xl overflow-hidden">
        <div class="absolute inset-0 bg-black/10"></div>
        <div class="hero-blob w-72 h-72 -top-20 -right-16"></div>
        <div class="hero-blob w-72 h-72 -bottom-24 -left-20"></div>
        <div class="relative z-10 px-4">
            <span class="eyebrow-badge on-dark">
                <i data-lucide="graduation-cap" class="w-4 h-4"></i> বিশ্বস্ত শিক্ষা প্ল্যাটফর্ম
            </span>
            <h1 class="text-4xl sm:text-6xl font-black mb-6 hero-text leading-tight">শিক্ষার নতুন দিগন্ত</h1>
            <p class="text-lg sm:text-2xl mb-10 max-w-3xl mx-auto font-light leading-relaxed opacity-95">আমাদের সাথে যুক্ত হয়ে পেয়ে যান উন্নতমানের শিক্ষা এবং দক্ষতা উন্নয়নের সুযোগ</p>
            <div class="flex flex-col sm:flex-row gap-4 justify-center items-center">
                <a href="courses" class="inline-flex items-center justify-center gap-2 bg-white text-indigo-700 px-8 sm:px-10 py-4 sm:py-5 rounded-2xl text-lg sm:text-xl font-bold shadow-lg hover:shadow-2xl transition-all transform hover:-translate-y-1 w-full sm:w-auto">
                    <i data-lucide="rocket" class="w-5 h-5"></i> কোর্স দেখুন
                </a>
                <a href="about" class="inline-flex items-center justify-center gap-2 btn-outline-white px-8 sm:px-10 py-4 sm:py-5 rounded-2xl text-lg sm:text-xl font-bold w-full sm:w-auto">
                    আমাদের সম্পর্কে
                </a>
            </div>
        </div>
    </section>

    <section>
        <div class="text-center mb-14 sm:mb-16 section-heading">
            <span class="eyebrow-badge">
                <i data-lucide="flame" class="w-4 h-4"></i> জনপ্রিয় কোর্স
            </span>
            <h2 class="text-3xl sm:text-5xl font-black mb-4 text-gray-800">সেরা মানের কোর্স সমূহ</h2>
            <p class="text-lg sm:text-xl text-gray-600 max-w-2xl mx-auto">বিশেষজ্ঞ শিক্ষকদের তত্বাবধানে প্রস্তুতকৃত পাঠক্রম</p>
        </div>
        <?php if ($featuredCourses): ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
            <?= implode('', array_map(fn($c) => render_item_card($c, 'course'), $featuredCourses)) ?>
        </div>
        <div class="text-center mt-12">
            <a href="courses" class="inline-flex items-center gap-2 text-indigo-600 font-bold text-lg hover:gap-3 transition-all">
                সব কোর্স দেখুন <i data-lucide="arrow-right" class="w-5 h-5"></i>
            </a>
        </div>
        <?php else: ?>
            <p class="text-center text-gray-500">এখনো কোনো কোর্স যোগ করা হয়নি।</p>
        <?php endif; ?>
    </section>

    <section class="relative gradient-bg py-12 sm:py-16 rounded-3xl text-white shadow-2xl overflow-hidden">
        <div class="hero-blob w-64 h-64 -top-16 -left-16"></div>
        <div class="text-center mb-10 px-4 relative z-10">
            <span class="eyebrow-badge on-dark">
                <i data-lucide="bar-chart-3" class="w-4 h-4"></i> আমাদের অর্জন
            </span>
            <h2 class="text-3xl sm:text-4xl font-bold mb-3">সংখ্যায় সাফল্যের গল্প</h2>
            <p class="text-lg sm:text-xl opacity-90"><?= e($siteName) ?> এর সাথে হাজারো শিক্ষার্থীর যাত্রা</p>
        </div>
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-8 px-4 relative z-10">
            <?php foreach ($stats as $s): ?>
            <div class="text-center stats-card p-6 sm:p-8 rounded-2xl">
                <div class="icon-circle mx-auto mb-4 bg-<?= $s['color'] ?>-100 text-<?= $s['color'] ?>-600"><i data-lucide="<?= e($s['icon']) ?>" class="w-6 h-6"></i></div>
                <div class="text-3xl sm:text-5xl font-black text-<?= $s['color'] ?>-600 mb-2" data-countup="<?= e($s['value']) ?>"><?= e($s['value']) ?></div>
                <p class="text-gray-700 font-semibold text-sm sm:text-lg"><?= e($s['label']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
        <!-- ট্রাস্ট/ফিচার স্ট্রিপ -->
        <div class="mt-9 sm:mt-10 flex flex-wrap justify-center gap-x-6 gap-y-3 px-4 relative z-10 text-white">
            <span class="inline-flex items-center gap-2 text-sm sm:text-base font-semibold"><i data-lucide="shield-check" class="w-5 h-5"></i> নিরাপদ পেমেন্ট</span>
            <span class="inline-flex items-center gap-2 text-sm sm:text-base font-semibold"><i data-lucide="truck" class="w-5 h-5"></i> হোম ডেলিভারি</span>
            <span class="inline-flex items-center gap-2 text-sm sm:text-base font-semibold"><i data-lucide="user-check" class="w-5 h-5"></i> অভিজ্ঞ শিক্ষক</span>
            <span class="inline-flex items-center gap-2 text-sm sm:text-base font-semibold"><i data-lucide="headphones" class="w-5 h-5"></i> সবসময় সাপোর্ট</span>
        </div>
    </section>

    <!-- CTA ব্যানার -->
    <section class="text-center rounded-3xl px-6 py-12 sm:py-14" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
        <h2 class="text-2xl sm:text-3xl font-black text-gray-800 mb-3">আজই আপনার সন্তানকে যুক্ত করুন</h2>
        <p class="text-gray-600 mb-6 max-w-xl mx-auto">সেরা শিক্ষকদের তত্ত্বাবধানে আনন্দময় শেখার যাত্রা শুরু হোক আজ থেকেই।</p>
        <a href="courses" class="btn-primary inline-flex items-center gap-2 text-white px-8 py-4 rounded-2xl font-bold text-lg shadow-lg">
            <i data-lucide="rocket" class="w-5 h-5"></i> কোর্স দেখুন
        </a>
    </section>
</div>

<!-- স্ট্যাট সংখ্যা count-up অ্যানিমেশন — স্ক্রলে দৃশ্যমান হলে ০ থেকে গুনে গুনে ওঠে (বাংলা সংখ্যা সহ) -->
<script>
(function () {
    var bn = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    function toBn(s) { return String(s).replace(/[0-9]/g, function (d) { return bn[d]; }); }
    function toEn(s) { return s.replace(/[০-৯]/g, function (d) { return bn.indexOf(d); }); }
    function run(el) {
        var en = toEn(el.getAttribute('data-countup') || '');
        var m = en.match(/(\d[\d,]*)/);
        if (!m) { return; } // সংখ্যা না থাকলে যেমন আছে তেমন থাকুক
        var target = parseInt(m[1].replace(/,/g, ''), 10);
        var prefix = en.slice(0, m.index), suffix = en.slice(m.index + m[1].length);
        var dur = 1400, start = null;
        function step(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = toBn(prefix + Math.round(target * eased) + suffix);
            if (p < 1) requestAnimationFrame(step);
            else el.textContent = toBn(prefix + target + suffix);
        }
        requestAnimationFrame(step);
    }
    var els = Array.prototype.slice.call(document.querySelectorAll('[data-countup]'));
    // reduce-motion চালু থাকলে অ্যানিমেশন বাদ — HTML-এর চূড়ান্ত সংখ্যাগুলোই দেখায় (অ্যাক্সেসিবিলিটি)
    if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
    if (!('IntersectionObserver' in window)) { els.forEach(run); return; }
    els.forEach(function (el) { if (/\d/.test(toEn(el.getAttribute('data-countup') || ''))) el.textContent = '০'; });
    var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (e) { if (e.isIntersecting) { run(e.target); io.unobserve(e.target); } });
    }, { threshold: 0.3 });
    els.forEach(function (el) { io.observe(el); });
})();
</script>

<?php // ফেসবুক পোস্ট/রিল সেকশন — অ্যাডমিনে কোনো পোস্ট না থাকলে ও পেজ-লিংক না দিলে কিছুই দেখাবে না ?>
<?= render_facebook_section() ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
