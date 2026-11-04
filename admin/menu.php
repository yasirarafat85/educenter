<?php
// ─────────────────────────────────────────────────────────────────────────────
// 🗂️ সব মেনু (২০২৬-১১-০৪) — সাইডবারের সব লিংক এক পাতায়, কার্ড আকারে + সার্চ।
//
// কেন: প্যানেলে মেনু অনেক বেড়েছে, সাইডবার হাতড়ে খুঁজে পাওয়া ঝামেলা (ইউজারের চাওয়া)।
// এই পাতা সাইডবারের হুবহু একই তালিকা (`admin_nav_groups()`) বড় কার্ড-গ্রিডে দেখায়, উপরে
// একটা সার্চ বক্স দিয়ে নাম লিখে ফিল্টার করা যায়। **সাইডবার অপরিবর্তিত থাকে।**
//
// 🔴🔴 এই পাতা কিছুই লেখে না — কোনো POST হ্যান্ডলার/INSERT/UPDATE/DELETE নেই, কোনো ডেটাও
//    দেখায় না, শুধু **লিংক**। তালিকাটা `admin_nav_groups()` থেকেই আসে, তাই —
//    (ক) প্রতিটা আইটেম আগেই `admin_can()`-এ ছাঁকা (অনুমতিহীন লিংক এখানেও দেখায় না),
//    (খ) নতুন অ্যাডমিন পেজ nav.php-এ যোগ করলেই এখানে **নিজে থেকে** চলে আসে — আলাদা
//        করে এই ফাইল ছুঁতে হয় না।
//
// 🔴 ক্রম সাইডবারের হুবহু প্রতিধ্বনি: পিন করা ("⭐ প্রিয়") আগে, তারপর বাকি গ্রুপ (পিন বাদ
//    দিয়ে) — `admin_nav_split()`-এর একই নিয়ম, যাতে দুই জায়গায় অভিজ্ঞতা এক থাকে।
//
// কোনো DB মাইগ্রেশন বা Tailwind রিবিল্ড লাগে না — স্টাইল নিচের `<style>`-এ সাধারণ CSS,
// কার্ডের ডার্ক-থিম সমর্থন বিদ্যমান `bg-white` ইউটিলিটি থেকেই (layout-top-এর override)।
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';
admin_require_login();

$pageTitle = 'সব মেনু';

$navGroups = admin_nav_groups();
$pins      = nav_pins_get();
[$pinned, $restGroups] = admin_nav_split($navGroups, $pins);

// মোট কতগুলো লিংক (পিন + বাকি) — শিরোনামে দেখানোর জন্য
$totalLinks = count($pinned);
foreach ($restGroups as $g) { $totalLinks += count($g['items']); }

// রেন্ডারের জন্য একটা সমন্বিত তালিকা: পিন করা গ্রুপ (থাকলে) আগে, তারপর বাকি গ্রুপগুলো।
$displayGroups = [];
if ($pinned) {
    $displayGroups[] = ['section' => '⭐ প্রিয়', 'items' => $pinned];
}
foreach ($restGroups as $g) {
    $displayGroups[] = $g;
}

$currentFile = basename($_SERVER['SCRIPT_NAME'] ?? 'menu.php');

require __DIR__ . '/includes/layout-top.php';
?>

<style>
    /* 🗂️ সব মেনু — সাধারণ CSS (Tailwind রিবিল্ড লাগে না) */
    .mnu-search-wrap { position: relative; max-width: 520px; }
    .mnu-search-wrap > i, .mnu-search-wrap > svg {
        position: absolute; left: 14px; top: 50%; transform: translateY(-50%);
        width: 18px; height: 18px; color: rgb(var(--c-text-muted)); pointer-events: none;
    }
    .mnu-search {
        width: 100%; padding: 12px 14px 12px 42px; font-size: 16px; /* 16px = iOS ফোকাসে জুম ঠেকায় */
        border: 1px solid rgb(var(--c-border)); border-radius: 12px;
        background: rgb(var(--c-surface)); color: rgb(var(--c-text));
    }
    .mnu-search:focus { outline: none; border-color: rgb(var(--c-primary)); box-shadow: 0 0 0 3px rgb(var(--c-primary) / .15); }

    .mnu-section { margin-top: 26px; }
    .mnu-section-title {
        font-size: 13px; font-weight: 700; letter-spacing: .02em;
        color: rgb(var(--c-text-muted)); margin-bottom: 10px; text-transform: none;
    }
    .mnu-grid {
        display: grid; gap: 12px;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    }
    .mnu-card {
        display: flex; align-items: center; gap: 12px; min-width: 0;
        padding: 14px 16px; border-radius: 14px;
        border: 1px solid rgb(var(--c-border)); color: rgb(var(--c-text));
        font-weight: 600; font-size: 14px; line-height: 1.4;
        transition: border-color .15s, box-shadow .15s, transform .1s, color .15s;
    }
    .mnu-card:hover {
        border-color: rgb(var(--c-primary));
        box-shadow: 0 6px 16px rgb(var(--c-primary) / .18);
        transform: translateY(-2px); color: rgb(var(--c-primary));
    }
    .mnu-ic {
        flex: 0 0 auto; display: grid; place-items: center;
        width: 40px; height: 40px; border-radius: 11px;
        background: rgb(var(--c-primary) / .10); color: rgb(var(--c-primary));
    }
    .mnu-ic svg, .mnu-ic i { width: 20px; height: 20px; }
    .mnu-label { min-width: 0; word-break: break-word; }

    .mnu-empty { display: none; color: rgb(var(--c-text-muted)); font-size: 14px; padding: 20px 4px; }

    /* ছোঁয়া-পর্দায় hover-lift আটকে যায় (CLAUDE.md নিয়ম) */
    @media (hover: none) { .mnu-card:hover { transform: none; } }
</style>

<div class="mb-5">
    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">🗂️ সব মেনু</h1>
    <p class="text-sm text-gray-500 mt-1">
        প্যানেলের সব পাতা এক জায়গায় (<?= e(bn_digits((string) $totalLinks)) ?> টি)।
        নাম লিখে খুঁজুন, অথবা কার্ডে ক্লিক করে সরাসরি যান। সাইডবার আগের মতোই আছে।
    </p>
</div>

<div class="mnu-search-wrap mb-2">
    <i data-lucide="search"></i>
    <input type="text" id="mnuSearch" class="mnu-search" placeholder="পাতার নাম লিখে খুঁজুন…"
           autocomplete="off" aria-label="মেনু খুঁজুন">
</div>

<?php foreach ($displayGroups as $gi => $group): ?>
    <section class="mnu-section" data-mnu-section>
        <div class="mnu-section-title"><?= e($group['section'] !== '' ? $group['section'] : 'প্রধান') ?></div>
        <div class="mnu-grid">
            <?php foreach ($group['items'] as $item): ?>
                <a href="<?= e($item['href']) ?>" class="mnu-card bg-white"
                   data-mnu-label="<?= e(mb_strtolower($item['label'])) ?>">
                    <span class="mnu-ic"><i data-lucide="<?= e($item['icon']) ?>"></i></span>
                    <span class="mnu-label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<p class="mnu-empty" id="mnuEmpty">কোনো পাতা মিলল না। অন্য শব্দ লিখে দেখুন।</p>

<script>
(function () {
    var box = document.getElementById('mnuSearch');
    if (!box) { return; }
    var cards    = Array.prototype.slice.call(document.querySelectorAll('.mnu-card'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('[data-mnu-section]'));
    var empty    = document.getElementById('mnuEmpty');

    function apply() {
        var q = box.value.trim().toLowerCase();
        var anyShown = false;
        cards.forEach(function (c) {
            var hit = q === '' || (c.getAttribute('data-mnu-label') || '').indexOf(q) !== -1;
            c.style.display = hit ? '' : 'none';
            if (hit) { anyShown = true; }
        });
        // যে সেকশনের একটাও কার্ড দেখা যাচ্ছে না সেটার শিরোনামও লুকাই
        sections.forEach(function (s) {
            var visible = s.querySelectorAll('.mnu-card:not([style*="display: none"])').length;
            s.style.display = visible ? '' : 'none';
        });
        if (empty) { empty.style.display = anyShown ? 'none' : 'block'; }
    }

    box.addEventListener('input', apply);
    // Esc-এ পরিষ্কার
    box.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { box.value = ''; apply(); }
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
