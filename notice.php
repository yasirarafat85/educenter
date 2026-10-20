<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'নোটিশ বোর্ড';
$activePage = 'notice';
$pageDescription = 'ভর্তি, ক্লাসের সময়সূচি ও পরীক্ষা সংক্রান্ত সর্বশেষ নোটিশ ও ঘোষণা এখানে পাবেন।';

// ⏳ মেয়াদ-শেষ নোটিশ বাদ — ছাঁকনি **PHP-তে** (`notice_filter_live()`), SQL-এ `NOW()` দিয়ে নয়:
//    MySQL সার্ভারের টাইমজোন ঢাকার সাথে না-ও মিলতে পারে, আর কলামটা না থাকলে (মাইগ্রেশনের
//    আগে) WHERE-এ লিখলে পুরো কোয়েরিই ভাঙত। `SELECT *` তাই ইচ্ছাকৃত।
$notices = notice_filter_live(
    get_db()->query('SELECT * FROM notices WHERE is_active = 1 ORDER BY notice_date DESC, id DESC')->fetchAll()
);

// 🎨 রঙ ঘুরেফিরে বসে — তালিকার **অবস্থান** ধরে (`id` ধরে নয়), তাই পরপর দুটো কার্ড
//    কখনো একই রঙ পায় না; এটাই ইউজারের চাওয়া ("এক একটা এক রকম কালার")।
$ntColors = notice_palette();

$pageJsonLd = jsonld_breadcrumb([['name' => 'নোটিশ', 'url' => 'notice']]);

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-4xl mx-auto">
    <?= render_page_header('bell', 'ঘোষণা', 'নোটিশ বোর্ড', 'সর্বশেষ আপডেট ও গুরুত্বপূর্ণ ঘোষণা', 'text-red-600') ?>
    <?php if ($notices): ?>
    <div class="nt-list">
        <?php foreach ($notices as $i => $n):
            $c = $ntColors[$i % count($ntColors)];
            // 😀 শিরোনামের সামনে ইমোজি থাকলে সেটা রঙিন বৃত্তে ওঠে, শিরোনাম থেকে বাদ যায়
            [$ntEmoji, $ntTitle] = notice_lead_emoji((string) $n['title']);
            $ntFresh = notice_is_fresh((string) $n['notice_date']);
        ?>
        <article class="nt-card" style="--n: <?= e($c['base']) ?>; --nd: <?= e($c['deep']) ?>;">
            <div class="nt-top">
                <span class="nt-ic" aria-hidden="true"><?php
                    // ইমোজি না থাকলে সাধারণ 📣 আইকন — 🔴 কখনো এলোমেলো আইকন ঘোরানো হয় না
                    //    (কোনটা কোন নোটিশ সেটা আইকন দেখে ভুল বোঝা যেত); রঙটাই আলাদা করে।
                    echo $ntEmoji !== '' ? e($ntEmoji) : '<i data-lucide="megaphone"></i>';
                ?></span>
                <h3 class="nt-title"><?= e($ntTitle) ?></h3>
                <div class="nt-meta">
                    <?php if ($ntFresh): ?><span class="nt-new">নতুন</span><?php endif; ?>
                    <span class="nt-date"><?= e(format_date_bn($n['notice_date'])) ?></span>
                </div>
            </div>
            <div class="nt-body"><?= format_rich_text($n['content']) ?></div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো নোটিশ নেই।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
