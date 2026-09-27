<?php
require_once __DIR__ . '/includes/functions.php';

// ⚠️ এই পেজ এতদিন `fetch_item()`-এর ভেতরের DB কানেকশনেই চলত, নিজের `$db` ছিল না।
// render_course_media()-এ $db লাগে — না থাকলে TypeError-এ পেজ মাঝপথে ভেঙে যেত।
$db = get_db();

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);
$item = fetch_item($type, $id);

if (!$item) {
    http_response_code(404);
    $pageTitle = 'পাওয়া যায়নি';
    $activePage = '';
    require __DIR__ . '/includes/site-header.php';
    echo '<div class="text-center text-2xl text-gray-600 py-20">দুঃখিত, এই আইটেমটি পাওয়া যায়নি।</div>';
    require __DIR__ . '/includes/site-footer.php';
    exit;
}

$pageTitle = $item['title'];
// আইটেম-ভিত্তিক SEO/শেয়ার মেটা — নির্দিষ্ট কোর্স/আইটেমের লিংক WhatsApp/FB এ শেয়ার করলে ঐ আইটেমের
// বিবরণ ও ছবি প্রিভিউতে দেখাবে (site-header.php এই দুটো ভ্যারিয়েবল পড়ে)
$pageDescription = mb_substr(trim(strip_tags($item['description'] ?? '')), 0, 155) ?: ($item['title'] . ' — ' . ($item['price'] ?? ''));
$pageOgImage = $item['image'] ?? '';
$activePage = $type === 'course' ? 'courses' : ($type === 'worksheet' ? 'worksheets' : 'products');
$backUrl = $type === 'course' ? 'courses' : ($type === 'worksheet' ? 'worksheets' : 'products');
// নিচের ফিরে-যাওয়ার বোতামে টাইপ অনুযায়ী পরিষ্কার নাম (উপরেরটা ছোট, শুধু "ফিরে যান")
$backLabel = $type === 'course' ? 'সব কোর্স দেখুন' : ($type === 'worksheet' ? 'সব ওয়ার্কশিট দেখুন' : 'সব প্রোডাক্ট দেখুন');
$registrationClosed = $type === 'course' && empty($item['registration_open']);
$actionLabel = $registrationClosed ? 'রেজিস্ট্রেশন বন্ধ' : ($type === 'course' ? 'Register Now - রেজিস্ট্রেশন করুন' : 'Order Now - অর্ডার করুন');
$actionUrl = $type === 'course' ? ('course-register?course_id=' . (int) $item['id']) : ('register?type=' . urlencode($type) . '&id=' . (int) $item['id']);

// 🔗 এই আইটেমের পড়ার-মতো URL (`course/12-নাম`) — পুরনো `detail?type=..&id=..` রূপে
// খোলা হলেও canonical/OG/structured-data সবখানে এটাই যায়, তাই গুগল একটাই ঠিকানা চেনে।
$pageCanonical = item_url($item, $type);

// 🔍 এই আইটেমের structured data + ব্রেডক্রাম্ব (site-header.php `$pageJsonLd` পড়ে <head>-এ বসায়)
$jsonLdUrl = (defined('SITE_URL') ? rtrim(SITE_URL, '/') : '') . '/' . $pageCanonical;
$pageJsonLd = jsonld_item($item, $type, $jsonLdUrl)
    . jsonld_breadcrumb([
        ['name' => $type === 'course' ? 'কোর্স' : ($type === 'worksheet' ? 'ওয়ার্কশিট' : 'প্রোডাক্ট'), 'url' => $backUrl],
        ['name' => $item['title'], 'url' => $jsonLdUrl],
    ]);

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-3xl mx-auto pb-10">
    <a href="<?= e($backUrl) ?>" class="inline-flex items-center gap-1.5 text-indigo-600 font-semibold text-sm mb-5 hover:gap-2.5 transition-all">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> ফিরে যান
    </a>

    <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8">
        <h1 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-6"><?= e($item['title']) ?></h1>

        <img src="<?= e($item['image'] ?: placeholder_img()) ?>" alt="<?= e($item['title']) ?>" class="w-full object-cover rounded-xl mb-6 shadow-lg bg-white zoomable" style="aspect-ratio:4/3;">

        <div class="space-y-6">
            <p class="text-gray-700 text-base sm:text-lg leading-relaxed break-words" style="overflow-wrap:anywhere;"><?= nl2br(e($item['description'] ?? '')) ?></p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="flex items-center gap-3 bg-gradient-to-r from-blue-50 to-blue-100 p-4 rounded-xl border border-blue-200">
                    <span class="icon-circle bg-blue-200 text-blue-700 w-10 h-10 flex-shrink-0"><i data-lucide="wallet" class="w-5 h-5"></i></span>
                    <span class="text-blue-800 font-bold text-lg flex items-baseline flex-wrap gap-2">মূল্য: <?= e($item['price'] ?? '') ?><?= render_discount_html($item['old_price'] ?? '', $item['price'] ?? '') ?></span>
                </div>
                <?php if (!empty($item['duration'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-green-50 to-green-100 p-4 rounded-xl border border-green-200">
                    <span class="icon-circle bg-green-200 text-green-700 w-10 h-10 flex-shrink-0"><i data-lucide="clock" class="w-5 h-5"></i></span>
                    <span class="text-green-800 font-bold text-lg">মেয়াদ: <?= e($item['duration']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['instructor'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-indigo-50 to-indigo-100 p-4 rounded-xl border border-indigo-200">
                    <span class="icon-circle bg-indigo-200 text-indigo-700 w-10 h-10 flex-shrink-0"><i data-lucide="user" class="w-5 h-5"></i></span>
                    <span class="text-indigo-800 font-bold text-lg">প্রশিক্ষক: <?= e($item['instructor']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['pages'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-purple-50 to-purple-100 p-4 rounded-xl border border-purple-200">
                    <span class="icon-circle bg-purple-200 text-purple-700 w-10 h-10 flex-shrink-0"><i data-lucide="file-text" class="w-5 h-5"></i></span>
                    <span class="text-purple-800 font-bold text-lg">পৃষ্ঠা: <?= e($item['pages']) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($item['level'])): ?>
                <div class="flex items-center gap-3 bg-gradient-to-r from-amber-50 to-amber-100 p-4 rounded-xl border border-amber-200">
                    <span class="icon-circle bg-amber-200 text-amber-700 w-10 h-10 flex-shrink-0"><i data-lucide="target" class="w-5 h-5"></i></span>
                    <span class="text-amber-800 font-bold text-lg">লেভেল: <?= e($item['level']) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($item['features'])): ?>
            <div class="bg-gradient-to-r from-gray-50 to-gray-100 p-6 rounded-xl">
                <h3 class="font-bold text-gray-900 mb-4 text-xl flex items-center gap-2">
                    <i data-lucide="sparkles" class="w-5 h-5 text-violet-500"></i> বৈশিষ্ট্য
                </h3>
                <ul class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <?php foreach ($item['features'] as $feature): ?>
                    <li class="flex items-center text-gray-700">
                        <i data-lucide="check-circle-2" class="w-4 h-4 text-violet-500 mr-2 flex-shrink-0"></i>
                        <?= e($feature) ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>

            <?php // 📸 ছবি ও ভিডিও — ভর্তি/অর্ডার বোতামের ঠিক নিচে (নিচে render_course_media())।
                  // ২০২৬-০৯-২৬ থেকে **ওয়ার্কশিট ও প্রোডাক্টেও** (আগে শুধু কোর্সে ছিল);
                  // কিছু যোগ করা না থাকলে বোতামই দেখায় না। ?>
            <?php $courseMediaHtml = media_owner_valid($type) ? render_course_media($db, (int) $item['id'], $type) : ''; ?>

            <?php if ($registrationClosed): ?>
            <div>
                <a href="course-interest?course_id=<?= (int) $item['id'] ?>" class="block w-full text-center py-4 px-6 rounded-xl font-bold text-lg shadow-lg btn-primary text-white">
                    জানিয়ে রাখুন
                </a>
                <?= $courseMediaHtml ?>
                <p class="text-center text-gray-500 text-sm mt-2">এই কোর্সের রেজিস্ট্রেশন এখন বন্ধ — আগ্রহ জানিয়ে রাখুন</p>
            </div>
            <?php else: ?>
            <a href="<?= e($actionUrl) ?>" class="block w-full text-center py-4 px-6 rounded-xl font-bold text-lg shadow-lg btn-primary text-white">
                <?= e($actionLabel) ?>
            </a>
            <?= $courseMediaHtml ?>
            <?php if ($type === 'course'): ?>
                <?php // ⚠️ ইচ্ছাকৃতভাবে ছোট টেক্সট লিংক, বোতাম নয় — ভর্তির বোতামের পাশে সমান বড়
                      // "জানিয়ে রাখুন" বোতাম দিলে যিনি আজই ভর্তি হতেন তিনিও ওয়েটিং লিস্টে চলে যেতে পারেন ?>
                <p class="text-center text-sm mt-3 text-gray-500">
                    এখন ভর্তি হতে পারছেন না?
                    <a href="course-interest?course_id=<?= (int) $item['id'] ?>" class="font-semibold underline" style="color: rgb(var(--c-deep));">আগ্রহ জানিয়ে রাখুন</a>
                </p>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php
// 🔗 "আরও কোর্স / সম্পর্কিত আইটেম" (২০২৬-০৯-২৭) — এতদিন ডিটেইল পেজের শেষে কিছুই ছিল না,
// ভিজিটর পড়া শেষ করে হয় ফিরে যেতেন নয় সাইট ছেড়ে দিতেন। কার্ড বিদ্যমান
// `render_item_card()` থেকেই (কোনো নতুন ডিজাইন/CSS নয়)।
// ⚠️ সেকশনটা ইচ্ছাকৃতভাবে উপরের `max-w-3xl` মোড়কের **বাইরে** — ভেতরে রাখলে
//    ৩-কলাম গ্রিড ডেস্কটপে চাপা দেখাত।
$related = fetch_related_items($type, $item, 3);
$relatedHeading = $type === 'course' ? '📚 আরও কোর্স'
    : ($type === 'worksheet' ? '📝 আরও ওয়ার্কশিট' : '🛍️ আরও প্রোডাক্ট');
?>
<?php if ($related): ?>
<section class="mt-12">
    <h2 class="text-2xl sm:text-3xl font-black mb-6 text-center text-gray-800"><?= e($relatedHeading) ?></h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
        <?= implode('', array_map(fn($rel) => render_item_card($rel, $type), $related)) ?>
    </div>
</section>
<?php endif; ?>

<?php // ⬅️ নিচেও একটা "ফিরে যান" (২০২৬-০৯-২৫, ইউজারের স্ক্রিনশট — কার্ডের নিচে ফাঁকা জায়গা
      // পড়ে থাকত, আর পুরো পেজ পড়ার পর আবার উপরে স্ক্রল করতে হতো)। স্টাইল `.cc-btn`
      // (সাধারণ CSS, থিম-রঙ) — Tailwind রিবিল্ড লাগে না।
      // 🔗 তার উপরে "লিংক কপি করুন" (২০২৬-০৯-২৭) — অ্যাড্রেস বার থেকে কপি করলে বাংলা
      //    নামের অংশটা `%E0%A6%…` রূপে বিশাল লম্বা হয়ে যায় (ইউজারের প্রশ্ন থেকে);
      //    এই বোতাম **ছোট রূপটা** (`…/course-15`) কপি করে, যেটা হুবহু একই পাতা খোলে। ?>
<div class="cc-btns mt-8 mb-4 mx-auto" style="max-width:360px;grid-template-columns:minmax(0,1fr)">
    <button type="button" class="cc-btn cc-btn-go" data-copy-url="<?= e(item_share_url($item, $type)) ?>">🔗 লিংক কপি করুন</button>
    <a href="<?= e($backUrl) ?>" class="cc-btn cc-btn-plain">← <?= e($backLabel) ?></a>
</div>

<script>
// 🔗 লিংক কপি — 🔴 ইচ্ছাকৃতভাবে site-footer.php-এর বড় স্ক্রিপ্টের **বাইরে** আলাদা ব্লকে:
//    ওখানে কিছু ভাঙলে আইকন/মেনু/গ্যালারি সব একসাথে বন্ধ হয়ে যায় (CLAUDE.md-এর নিয়ম)।
// 🔴 `navigator.clipboard` শুধু HTTPS-এ (ও কিছু পুরনো ব্রাউজারে একেবারেই) চলে না —
//    তাই লুকানো <textarea> + execCommand ফলব্যাক, আর তাতেও না হলে ইউজারকে বলা হয়।
(function () {
    var btns = document.querySelectorAll('[data-copy-url]');
    if (!btns.length) { return; }
    Array.prototype.forEach.call(btns, function (b) {
        var orig = b.textContent;                      // আসল লেখা একবারই ধরে রাখি
        var timer = null;
        function flash(ok) {
            b.textContent = ok ? '✅ কপি হয়েছে' : '⚠️ কপি হলো না, হাতে নিন';
            if (timer) { clearTimeout(timer); }
            timer = setTimeout(function () { b.textContent = orig; }, 2200);
        }
        function fallback(url) {
            try {
                var ta = document.createElement('textarea');
                ta.value = url;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                document.body.appendChild(ta);
                ta.select();
                ta.setSelectionRange(0, url.length);   // iOS-এ select() একা যথেষ্ট নয়
                var ok = document.execCommand('copy');
                document.body.removeChild(ta);
                flash(!!ok);
            } catch (e) { flash(false); }
        }
        b.addEventListener('click', function () {
            var url = b.getAttribute('data-copy-url') || '';
            if (!url) { return; }
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () { flash(true); },
                                                       function () { fallback(url); });
            } else {
                fallback(url);
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
