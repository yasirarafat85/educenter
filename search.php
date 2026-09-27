<?php
// 🔎 পাবলিক সার্চ (২০২৬-০৯-২৭) — কোর্স + ওয়ার্কশিট + প্রোডাক্ট একসাথে।
//
// এতদিন সাইটে কোনো সার্চ বক্সই ছিল না (অ্যাডমিন প্যানেলে ছিল); আইটেম বাড়তে থাকায়
// অভিভাবকদের খুঁজে পাওয়া কঠিন হচ্ছিল।
//
// 🔴 `noindex` — সার্চ ফলাফলের পাতা গুগলে ইনডেক্স করানো উচিত নয় (অসীম সংখ্যক
//    URL তৈরি হয়, আর গুগল নিজেই এগুলোকে নিম্নমানের ধরে)। `sitemap.php`-এও নেই।
// 🔴 শুধু পড়া — কোনো লেখা/সেশন নেই, তাই CSRF লাগে না; কিন্তু সব কোয়েরি
//    prepared statement-এ (LIKE-এর ভেতরেও ইউজারের লেখা সরাসরি বসানো হয় না)।
require_once __DIR__ . '/includes/functions.php';

$q = trim((string) ($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 100);   // অস্বাভাবিক লম্বা ইনপুট কেটে ফেলা

$pageTitle = $q !== '' ? ('খোঁজা: ' . $q) : 'খুঁজুন';
$activePage = '';
$pageDescription = 'কোর্স, ওয়ার্কশিট ও প্রোডাক্টের মধ্যে খুঁজুন — নাম বা বিষয় লিখলেই মিলে যাবে।';
$pageNoIndex = true;

$results = ['course' => [], 'worksheet' => [], 'product' => []];
$total = 0;
$searchFailed = false;

// অন্তত ২ অক্ষর — নাহলে "অ" লিখলেই পুরো তালিকা আসত
if (mb_strlen($q, 'UTF-8') >= 2) {
    // 🔴 LIKE-এর বিশেষ অক্ষর escape — নাহলে কেউ `%` লিখলেই সব আইটেম ম্যাচ করত।
    // ⚠️ ব্যাকস্ল্যাশ **নয়**, `!` কে escape-অক্ষর ধরা হয়েছে ও প্রতিটা LIKE-এ স্পষ্ট
    //    `ESCAPE '!'` দেওয়া হয়েছে — MySQL-এ ব্যাকস্ল্যাশের আচরণ `NO_BACKSLASH_ESCAPES`
    //    SQL-মোডের উপর নির্ভর করে (শেয়ার্ড হোস্টে এটা বদলে যেতে পারে), আর SQLite-এ
    //    ডিফল্ট escape-অক্ষরই নেই। `!` দুই জায়গাতেই একইভাবে চলে।
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
    try {
        $db = get_db();

        // কোর্স — title (parent) বা batch_name/description/instructor (child) যেকোনোটায় মিললে
        // 🔴 parent/child নিয়ম: `courses`-এ শুধু title, বাকি সব `course_batches`-এ (CLAUDE.md)
        $cs = $db->prepare(
            'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id
              WHERE cb.is_active = 1
                AND (c.title LIKE :q1 ESCAPE \'!\' OR cb.batch_name LIKE :q2 ESCAPE \'!\'
                     OR cb.description LIKE :q3 ESCAPE \'!\' OR cb.instructor LIKE :q4 ESCAPE \'!\')
              ORDER BY cb.registration_open DESC, cb.sort_order ASC, cb.id ASC LIMIT 24'
        );
        $cs->execute(['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like]);
        $results['course'] = $cs->fetchAll();

        $ws = $db->prepare(
            'SELECT * FROM worksheets WHERE is_active = 1
              AND (title LIKE :q1 ESCAPE \'!\' OR description LIKE :q2 ESCAPE \'!\')
              ORDER BY sort_order ASC, id ASC LIMIT 24'
        );
        $ws->execute(['q1' => $like, 'q2' => $like]);
        $results['worksheet'] = $ws->fetchAll();

        $ps = $db->prepare(
            'SELECT * FROM products WHERE is_active = 1
              AND (title LIKE :q1 ESCAPE \'!\' OR description LIKE :q2 ESCAPE \'!\')
              ORDER BY sort_order ASC, id ASC LIMIT 24'
        );
        $ps->execute(['q1' => $like, 'q2' => $like]);
        $results['product'] = $ps->fetchAll();

        $total = count($results['course']) + count($results['worksheet']) + count($results['product']);
    } catch (Throwable $e) {
        // 🔴 কোনো কারণে কোয়েরি ব্যর্থ হলে পেজ ভাঙবে না — ভদ্র বার্তা দেখাবে
        $searchFailed = true;
    }
}

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('search', 'খুঁজুন', 'কী খুঁজছেন?', 'কোর্স, ওয়ার্কশিট ও প্রোডাক্ট — সব এক জায়গায় খুঁজুন', 'text-indigo-700') ?>

    <?php // 🔴 action extensionless — `.php` দিলে প্রতিবার একটা বাড়তি ৩০১ ঘুরপথ হতো
          //    (`.htaccess`-এর (A) নিয়ম GET-এ `/search.php` → `/search` পাঠায়)। ?>
    <form method="get" action="search" role="search" class="max-w-2xl mx-auto mb-10">
        <label for="q" class="sr-only-label">কী খুঁজবেন</label>
        <div class="sr-box">
            <i data-lucide="search" class="w-5 h-5 sr-ic" aria-hidden="true"></i>
            <input type="search" id="q" name="q" value="<?= e($q) ?>" autofocus
                   placeholder="যেমন: হাতের লেখা, গণিত, ওয়ার্কশিট…"
                   class="sr-input" maxlength="100">
            <button type="submit" class="btn-primary sr-btn">খুঁজুন</button>
        </div>
    </form>

    <?php if ($searchFailed): ?>
        <p class="text-center text-gray-500 py-10">দুঃখিত, এই মুহূর্তে খোঁজা যাচ্ছে না। একটু পরে আবার চেষ্টা করুন।</p>

    <?php elseif ($q === ''): ?>
        <p class="text-center text-gray-500 py-6">উপরের ঘরে কোর্স বা ওয়ার্কশিটের নাম লিখুন।</p>

    <?php elseif (mb_strlen($q, 'UTF-8') < 2): ?>
        <p class="text-center text-gray-500 py-6">অন্তত 2টি অক্ষর লিখুন।</p>

    <?php elseif ($total === 0): ?>
        <div class="max-w-xl mx-auto text-center py-8">
            <p class="text-xl font-bold text-gray-800 mb-3">"<?= e($q) ?>" — কিছু পাওয়া গেল না</p>
            <p class="text-gray-600 mb-7">বানান মিলিয়ে দেখুন, বা কম শব্দে খুঁজুন। নিচের তালিকাগুলোও দেখতে পারেন —</p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="courses" class="btn-primary inline-flex items-center gap-2 px-6 py-3 rounded-2xl font-bold">সব কোর্স</a>
                <a href="worksheets" class="cc-btn cc-btn-plain" style="min-width:150px">সব ওয়ার্কশিট</a>
                <a href="products" class="cc-btn cc-btn-plain" style="min-width:150px">সব প্রোডাক্ট</a>
            </div>
        </div>

    <?php else: ?>
        <p class="text-center text-gray-600 mb-8">
            "<strong class="text-gray-900"><?= e($q) ?></strong>" — <strong><?= e(bn_digits($total)) ?></strong> টি পাওয়া গেছে
        </p>

        <?php
        // তিন ধরনের ফলাফল আলাদা সেকশনে — কার্ড একই `render_item_card()` থেকে,
        // তাই প্রিভিউ বোতাম/আগ্রহ-লাইন/দাম সবই তালিকা-পেজের মতোই দেখায়।
        $sections = [
            'course'    => ['📚 কোর্স', 'text-blue-700'],
            'worksheet' => ['📝 ওয়ার্কশিট', 'text-green-700'],
            'product'   => ['🛍️ প্রোডাক্ট', 'text-purple-700'],
        ];
        foreach ($sections as $sType => [$sLabel, $sColor]):
            if (!$results[$sType]) { continue; }
        ?>
            <section class="mb-12">
                <h2 class="text-2xl sm:text-3xl font-black mb-6 <?= e($sColor) ?>">
                    <?= e($sLabel) ?>
                    <span class="text-base font-bold text-gray-500">(<?= e(bn_digits(count($results[$sType]))) ?>)</span>
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
                    <?= implode('', array_map(fn($it) => render_item_card($it, $sType), $results[$sType])) ?>
                </div>
            </section>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
