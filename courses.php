<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'কোর্স সমূহ';
$activePage = 'courses';
$pageDescription = 'আমাদের সব কোর্স — বিশেষজ্ঞ শিক্ষকদের তত্ত্বাবধানে উন্নতমানের কোর্স এবং আধুনিক শিক্ষা পদ্ধতি। এখনই রেজিস্ট্রেশন করুন।';

$courses = get_db()->query(
    'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1 ORDER BY cb.sort_order ASC, cb.id ASC'
)->fetchAll();

// খোলা (চলমান) ও বন্ধ ("আসছে শীঘ্রই") আলাদা করা — বন্ধগুলো আলাদা সেকশনে দেখানো হয়
// 🔴 course_reg_open() — হাতের সুইচ **ও** ভর্তির শেষ সময় (registration_deadline) দুটোই দেখে,
// তাই কাউন্টডাউন শেষ হওয়া ব্যাচ নিজে থেকেই "আসছে শীঘ্রই" সেকশনে নেমে যায়
// 🆕 চলমান কোর্সে নতুন ব্যাচ আগে (২০২৬-১১-১৯, ইউজারের চাওয়া) — course_sort_newest()
$openCourses   = course_sort_newest(array_values(array_filter($courses, fn($c) => course_reg_open($c))));
$closedCourses = array_values(array_filter($courses, fn($c) => !course_reg_open($c)));

$pageJsonLd = jsonld_breadcrumb([['name' => 'কোর্স', 'url' => 'courses']]);

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('book-open', 'আমাদের কোর্স', 'সব কোর্স সমূহ', 'বিশেষজ্ঞ শিক্ষকদের তত্বাবধানে উন্নতমানের কোর্স এবং আধুনিক শিক্ষা পদ্ধতি', 'text-blue-700') ?>

    <?php if (!$courses): ?>
        <p class="text-center text-gray-500">এখনো কোনো কোর্স যোগ করা হয়নি।</p>
    <?php else: ?>

        <?php // 📚 সারাংশ + বড় রঙিন বোতাম (২০২৬-১১-১৯, ইউজারের চাওয়া — হোমপেজ হিরোর বোতামের ধাঁচে, প্রতিটা আলাদা রঙ)।
              // উপরে সংখ্যার তিন টাইল, নিচে বোতাম (সংখ্যাসহ), আর কোর্সের নাম ভাঁজ-করা <details>-এ (লম্বা তালিকা লুকানো)।
              // 🔴 ব্যাচের নাম দেখানো হয় না, এক কোর্সের একাধিক ব্যাচ একটাই নাম (cix_unique())।
              // খোলা ব্যাচ আছে এমন কোর্স "আগ্রহ জানান"-এ আবার আসে না। স্টাইল .cix-* (style.css, সাধারণ CSS)। ?>
        <?php
        $cixOpen = cix_unique($openCourses);
        $cixSoon = array_diff_key(cix_unique($closedCourses), $cixOpen);
        $cixLogged = !empty($_SESSION['user_id']);
        ?>
        <nav class="cix" aria-label="সব কোর্সের তালিকা">
            <div class="cix-stats">
                <div class="cix-stat cix-st-all"><b><?= count($cixOpen) + count($cixSoon) ?></b><span>মোট কোর্স</span></div>
                <div class="cix-stat cix-st-open"><b><?= count($cixOpen) ?></b><span>ভর্তি চলছে</span></div>
                <div class="cix-stat cix-st-soon"><b><?= count($cixSoon) ?></b><span>আসছে শীঘ্রই</span></div>
            </div>
            <div class="cix-btns">
                <?php if ($cixOpen): ?>
                <a href="#cix-open" class="cix-btn cix-btn-open">
                    <i data-lucide="book-open" class="w-5 h-5"></i><span>ভর্তি চলছে</span><em class="cix-n"><?= count($cixOpen) ?>টি কোর্স</em>
                </a>
                <?php endif; ?>
                <?php if ($cixSoon): ?>
                <a href="#cix-closed" class="cix-btn cix-btn-soon">
                    <i data-lucide="bell" class="w-5 h-5"></i><span>আগ্রহ জানান</span><em class="cix-n"><?= count($cixSoon) ?>টি কোর্স</em>
                </a>
                <?php endif; ?>
                <a href="<?= $cixLogged ? 'account' : 'account-login' ?>" class="cix-btn cix-btn-login">
                    <i data-lucide="<?= $cixLogged ? 'layout-dashboard' : 'log-in' ?>" class="w-5 h-5"></i><span><?= $cixLogged ? 'আপনার ড্যাশবোর্ড' : 'অভিভাবক লগইন' ?></span>
                </a>
            </div>
            <details class="cix-names">
                <summary>📋 সব কোর্সের নাম দেখুন <i data-lucide="chevron-down" class="w-4 h-4"></i></summary>
                <?php foreach ([[$cixOpen, '✅ ভর্তি চলছে', 'cix-pill-open'], [$cixSoon, '🔜 আসছে — আগ্রহ জানান', 'cix-pill-soon']] as [$cixList, $cixLabel, $cixCls]): ?>
                    <?php if (!$cixList) continue; ?>
                    <div class="cix-group">
                        <div class="cix-glabel <?= $cixCls ?>"><?= $cixLabel ?> (<?= count($cixList) ?>)</div>
                        <div class="cix-chips">
                            <?php foreach ($cixList as $cixC): ?>
                                <a href="#course-<?= (int) $cixC['id'] ?>" class="cix-chip <?= $cixCls ?>"><?= course_is_new($cixC) ? '🆕 ' : '' ?><?= e($cixC['title']) ?></a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </details>
        </nav>

        <?php if ($openCourses): ?>
        <div id="cix-open" class="cix-anchor flex flex-wrap items-center gap-2.5 mb-6 sm:mb-8">
            <span class="cro-dot" style="width:12px;height:12px;border-radius:50%;background:#16a34a;display:inline-block;"></span>
            <h2 class="text-2xl sm:text-3xl font-black text-green-700 leading-tight">চলমান কোর্স</h2>
            <span style="background:#dcfce7;color:#15803d;font-weight:700;padding:5px 14px;border-radius:9999px;font-size:14px;">এখন <?= count(cix_unique($openCourses)) ?> টিতে ভর্তি খোলা</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
            <?= implode('', array_map(fn($c) => '<div id="course-' . (int) $c['id'] . '" class="cix-anchor">' . render_item_card($c, 'course') . '</div>', $openCourses)) ?>
        </div>
        <?php endif; ?>

        <?php if ($openCourses): ?>
        <?php // 🔴 চলমান কোর্সে আগ্রহী কিন্তু এখন পারছেন না — তাঁদের জন্য প্রধান দরজা (২০২৬-০৯-২৪)।
              // আগে আগ্রহ ফর্মে ঢোকার একমাত্র পথ ছিল একটা **বন্ধ** কোর্সের কার্ড, তাই চলমান কোর্সের
              // আগ্রহীরা পেজটার অস্তিত্বই জানতেন না। ?>
        <div class="mt-12 sm:mt-16 rounded-3xl p-6 sm:p-8 text-center shadow-lg" style="background:linear-gradient(135deg, rgb(var(--c-tint)) 0%, #ffffff 100%); border:1px solid rgb(var(--c-border));">
            <span class="text-4xl sm:text-5xl">💚</span>
            <h2 class="text-xl sm:text-2xl font-black mt-2 mb-2" style="color: rgb(var(--c-deep));">আগ্রহী, কিন্তু এখন ভর্তি হতে পারছেন না?</h2>
            <p class="text-gray-600 text-sm sm:text-base mb-5 max-w-xl mx-auto">শিশুর বয়স এখনো কম, কিংবা এই মুহূর্তে ব্যস্ত — কোনো সমস্যা নেই। একবার আগ্রহ জানিয়ে রাখুন, সময় হলে <strong>আমরাই আপনাকে মনে করিয়ে দেব</strong>।</p>
            <a href="course-interest" class="inline-block px-6 py-3.5 rounded-xl font-bold text-white shadow-lg btn-primary">আগ্রহ জানিয়ে রাখুন →</a>
        </div>
        <?php endif; ?>

        <?php if ($closedCourses): ?>
        <div id="cix-closed" class="cix-anchor mt-14 sm:mt-16 bg-violet-50 border border-violet-100 rounded-3xl p-4 sm:p-8">
            <div class="rounded-2xl p-5 sm:p-6 mb-6 sm:mb-8 flex flex-col sm:flex-row sm:items-center gap-3 text-center sm:text-left shadow-lg" style="background:linear-gradient(135deg,#6366f1 0%,#7c3aed 100%);">
                <span class="text-4xl sm:text-5xl">🔜</span>
                <div>
                    <h2 class="text-2xl sm:text-3xl font-black text-white leading-tight">আসছে শীঘ্রই</h2>
                    <p class="text-violet-100 text-sm sm:text-base mt-1">রেজিস্ট্রেশন এখন বন্ধ — <strong>"জানিয়ে রাখুন"</strong> চাপুন, নতুন ব্যাচ খুললেই আপনাকে যোগাযোগ করব। কী কী শেখানো হয় নিচে দেখুন; ফি ও সময় নতুন ব্যাচে জানানো হবে।</p>
                </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
                <?= implode('', array_map(fn($c) => '<div id="course-' . (int) $c['id'] . '" class="cix-anchor">' . render_item_card($c, 'course') . '</div>', $closedCourses)) ?>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
