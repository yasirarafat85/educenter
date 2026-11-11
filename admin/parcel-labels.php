<?php
// ─────────────────────────────────────────────────────────────────────────────
// 🖨️ পার্সেল লেবেল প্রিন্ট (২০২৬-১১-১০) — ইউজারের চাওয়া: কোর্স শিক্ষার্থীদের
//    নাম/ঠিকানা/মোবাইল/ফেসবুক আইডির নাম দিয়ে A4 পেজে কার্ড-স্টাইল লেবেল, সুন্দর
//    কেটে পার্সেলের গায়ে লাগানোর জন্য। অনুমোদিত মকআপ অনুযায়ী — **সাদা-কালো** (B&W
//    প্রিন্টারে ঘোলা না হয়), কালো বর্ডার, কার্ড লেখার মাপেই ছোট-বড় (বাড়তি সাদা জায়গা নেই)।
//
// 🔴🔴 এই পাতা কিছুই লেখে না — একটাও POST/DB-লেখা নেই, শুধু পড়ে ছাপায়।
//      তাই edit/delete cap লাগে না; permissions.php-এ `['parcel','orders']` view।
//
// 🔴 দুই রকম উৎস (ইউজার: "উভয়ভাবে"), কোর্স-ব্যাচ বেছে নিয়ে টগল:
//      • prepared  — এই মাসে যাদের পার্সেল প্রস্তুত (courier_batches-এ period_label
//                    মিলে, declined ছাড়া)। 🔴 period_label = `pay_month_label()`,
//                    হুবহু course-parcel.php যেটা সেভ করে — স্ট্রিং মিলিয়েই বের হয়।
//      • confirmed — ব্যাচের সব confirmed শিক্ষার্থী (courier_active=0 নিষ্ক্রিয় বাদ)।
//
// 🔴 রিসিভারের নাম/মোবাইল **খালি থাকলে খালিই থাকবে** (ইউজারের স্পষ্ট সিদ্ধান্ত —
//    customer_name/phone-এ ফলব্যাক নয়)। নাম=receiver_name · মোবাইল=receiver_phone ·
//    ঠিকানা=address · ফেসবুক আইডির নাম=facebook_id।
//
// 🔴 ছাপার লেআউটের সব CSS পাতার নিজের `<style>`-এ `.pl-*` ক্লাসে — **Tailwind রিবিল্ড
//    লাগে না**, আর admin-এর Tailwind ক্লাসের সাথে মেশে না বলে চেহারা হুবহু মকআপের মতো।
//    কন্ট্রোল বারে `.no-print` (admin print CSS সেটা লুকায়), তাই শুধু শিটটাই ছাপে।
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/form-helpers.php'; // admin_image_src()
require_once __DIR__ . '/includes/payments.php';     // pay_month_label()
admin_require_login();

$db        = get_db();
$pageTitle = 'পার্সেল লেবেল';

function plabel_url(int $itemId = 0, string $mode = '', int $month = 0): string
{
    $u = 'parcel-labels.php';
    $q = [];
    if ($itemId) { $q['item_id'] = $itemId; }
    if ($mode !== '') { $q['mode'] = $mode; }
    if ($month) { $q['month'] = $month; }
    return $q ? $u . '?' . http_build_query($q) : $u;
}

$itemId = (int) ($_GET['item_id'] ?? 0);

// ── মার্চেন্ট তথ্য ──
// 🔴 ডেডিকেটেড সেটিংস, ইউজারের দেওয়া মানগুলো ফলব্যাক — ইচ্ছাকৃতভাবে `site_name`/
//    `contact_phone` থেকে টানা হয় না: পাবলিক সাইটের নাম/ফোন আলাদা ফরম্যাটে (যেমন
//    "+৮৮০ ১৭…") থাকতে পারে, অথচ লেবেলে ইউজার ঠিক এই তিনটা লেখাই চান। সেটিংস
//    key-value বলে **মাইগ্রেশন লাগে না**; বদলাতে চাইলে ঐ কী বসালেই হয়।
// 🔴 `get_setting() ?: fallback` — খালি স্ট্রিং সেভ থাকলেও ফলব্যাক ফেরে (About পেজের ফাঁদ)।
$brandBn  = get_setting('parcel_label_brand_bn') ?: 'শিশুর মেধা বিকাশ';
$brandEn  = get_setting('parcel_label_brand_en') ?: 'Sishur Medha Bikash';
$brandPh  = get_setting('parcel_label_phone')    ?: '01685793049';
$logoSrc  = admin_image_src((string) get_setting('logo_path'));

require __DIR__ . '/includes/layout-top.php';

// ───────────────────────── কোর্স-ব্যাচ পিকার ─────────────────────────
// 🔴 month-work.php/course-parcel.php-র হুবহু পিকার-প্যাটার্ন (hide_parcel=0) —
//    তিন পাতায় তিন রকম তালিকা দেখালে অ্যাডমিন গুলিয়ে ফেলতেন।
if (!$itemId) {
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c
         FROM registrations r
         JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="mb-5">
        <h1 class="text-2xl font-bold text-gray-800">🖨️ পার্সেল লেবেল</h1>
        <p class="text-sm text-gray-500 mt-1">কোন কোর্স-ব্যাচের পার্সেল লেবেল ছাপাবেন বেছে নিন — নাম · ঠিকানা · মোবাইল · যোগাযোগ নাম দিয়ে A4 কার্ড, কেটে পার্সেলে লাগানোর জন্য।</p>
    </div>
    <?php if (!$batches): ?>
        <div class="bg-white rounded-2xl shadow empty-state">
            <div class="empty-ic"><i data-lucide="printer" class="w-8 h-8"></i></div>
            পার্সেল যায় এমন কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।
        </div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th>
                <th class="py-3 px-4">শিক্ষার্থী</th><th class="py-3 px-4">অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr class="border-b last:border-0">
                    <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                    <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                    <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                    <td class="py-2.5 px-4"><a href="<?= e(plabel_url((int) $b['item_id'])) ?>" class="text-indigo-600 font-semibold inline-block py-1">লেবেল দেখুন →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif;
    require __DIR__ . '/includes/layout-bottom.php';
    return;
}

// ───────────────────────── এক ব্যাচের লেবেল ─────────────────────────
$cStmt = $db->prepare('SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
if (!$course) {
    echo '<div class="bg-white rounded-2xl shadow empty-state">কোর্স-ব্যাচটি পাওয়া যায়নি। <a class="text-indigo-600" href="parcel-labels.php">← ফিরে যান</a></div>';
    require __DIR__ . '/includes/layout-bottom.php';
    return;
}

$totalParcels = (int) ($course['total_parcels'] ?? 0);
$months       = max($totalParcels, 0);

$mode = ($_GET['mode'] ?? '') === 'confirmed' ? 'confirmed' : 'prepared';
// মাস সেট না থাকলে পার্সেল মোডে অন্তত ১ মাস লাগে; না থাকলে confirmed-এ ফেরত।
if ($mode === 'prepared' && $months < 1) { $mode = 'confirmed'; }

$selMonth = (int) ($_GET['month'] ?? 1);
$selMonth = $months > 0 ? max(1, min($selMonth, $months)) : 0;
$selLabel = ($mode === 'prepared' && $selMonth) ? pay_month_label($selMonth) : '';

// ব্যাচের সব confirmed রেজিস্ট্রেশন
$rStmt = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY receiver_name, customer_name");
$rStmt->execute(['id' => $itemId]);
$allRegs = $rStmt->fetchAll();

$inactive = 0;  // confirmed কিন্তু কুরিয়ারে যাবে না — confirmed মোডে বাদ
$cards    = [];

if ($mode === 'confirmed') {
    foreach ($allRegs as $r) {
        if ((int) ($r['courier_active'] ?? 1) !== 1) { $inactive++; continue; }
        $cards[] = $r;
    }
} else {
    // এই মাসে যাদের পার্সেল প্রস্তুত — courier_batches-এ period_label মিলে, declined ছাড়া।
    $prepared = [];
    if ($allRegs && $selLabel !== '') {
        $ids = array_map(fn($r) => (int) $r['id'], $allRegs);
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $st  = $db->prepare("SELECT DISTINCT registration_id FROM courier_batches
                             WHERE registration_id IN ($in) AND period_label = ? AND send_status <> 'declined'");
        $st->execute(array_merge($ids, [$selLabel]));
        foreach ($st->fetchAll() as $row) { $prepared[(int) $row['registration_id']] = true; }
    }
    foreach ($allRegs as $r) {
        if (isset($prepared[(int) $r['id']])) { $cards[] = $r; }
    }
}

$currentUrl = plabel_url($itemId, $mode, $selMonth);
?>

<style>
/* ── পাতার নিজের ছাপার-লেআউট CSS (Tailwind রিবিল্ড লাগে না; admin ক্লাসের সাথে মেশে না) ── */
.pl-sheet{
    width:100%; max-width:210mm; margin:0 auto; background:#fff;
    padding:7mm; display:grid; grid-template-columns:repeat(2, minmax(0,1fr));
    grid-auto-rows:min-content; gap:5mm; align-content:start;
    box-shadow:0 2px 14px rgba(0,0,0,.10); border-radius:4px;
}
.pl-card{
    border:1.4px solid #000; border-radius:3px; overflow:hidden;
    display:flex; flex-direction:column; background:#fff;
    break-inside:avoid; page-break-inside:avoid;
}
.pl-head{ display:flex; align-items:center; gap:3mm; padding:2.2mm 3mm; border-bottom:1.4px solid #000; }
.pl-logo{
    width:13mm; height:13mm; flex:0 0 13mm; border:1.3px solid #000; border-radius:3px;
    display:flex; align-items:center; justify-content:center; overflow:hidden; line-height:1;
}
.pl-logo img{ width:100%; height:100%; object-fit:contain; }
.pl-logo .pl-lt{ font-size:6.5px; font-weight:700; color:#000; letter-spacing:.3px; text-align:center; }
.pl-brand{ min-width:0; color:#000; }
.pl-brand .pl-en{ font-size:15px; font-weight:800; letter-spacing:.2px; }
.pl-brand .pl-bn{ font-size:12px; font-weight:700; margin-top:1px; }
.pl-brand .pl-ph{ font-size:13px; font-weight:800; margin-top:1.5px; letter-spacing:.4px; }
.pl-body{ padding:2.6mm 3.2mm 3mm; display:flex; flex-direction:column; gap:1.3mm; }
.pl-row{ display:flex; gap:6px; color:#000; line-height:1.38; }
.pl-row .pl-k{ color:#333; flex:0 0 auto; font-size:11px; font-weight:600; align-self:flex-start; padding-top:1px; }
.pl-row .pl-v{ min-width:0; word-break:break-word; }
.pl-row.pl-name .pl-v{ font-size:16px; font-weight:800; min-height:1em; }
.pl-row.pl-mob  .pl-v{ font-size:15px; font-weight:800; letter-spacing:.5px; min-height:1em; }
.pl-row.pl-addr .pl-v{ font-size:13px; font-weight:500; }
.pl-row.pl-fb   .pl-v{ font-size:13px; font-weight:700; }
@media screen and (max-width:640px){ .pl-sheet{ grid-template-columns:1fr; } }
@media print{
    /* admin print CSS @page A4/margin:12mm মেনেই শিটটা ছাপার-এলাকা ভরে — ওভাররাইড লাগে না */
    .pl-sheet{ box-shadow:none; border-radius:0; padding:0; max-width:none; }
}
</style>

<?php /* ── কন্ট্রোল বার (শুধু পর্দায়, ছাপায় না) ── */ ?>
<div class="no-print">
    <div class="flex flex-wrap items-center gap-2 mb-3">
        <h1 class="text-2xl font-bold text-gray-800">🖨️ পার্সেল লেবেল</h1>
        <a href="parcel-labels.php" class="text-xs text-indigo-600 py-1">← অন্য কোর্স</a>
    </div>
    <p class="text-sm text-gray-600 mb-4">
        <span class="font-semibold text-gray-800"><?= e($course['title']) ?></span>
        <span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($course['batch_name'] ?? '') ?></span>
    </p>

    <?php /* উৎস টগল */ ?>
    <div class="flex flex-wrap gap-2 mb-3">
        <a href="<?= e(plabel_url($itemId, 'prepared', $selMonth ?: 1)) ?>"
           class="px-4 py-2 rounded-lg text-sm font-semibold <?= $mode === 'prepared' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?> <?= $months < 1 ? 'opacity-50 pointer-events-none' : '' ?>">
            📦 এই মাসে পার্সেল প্রস্তুত
        </a>
        <a href="<?= e(plabel_url($itemId, 'confirmed')) ?>"
           class="px-4 py-2 rounded-lg text-sm font-semibold <?= $mode === 'confirmed' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>">
            ✅ সব কনফার্ম শিক্ষার্থী
        </a>
    </div>

    <?php if ($mode === 'prepared' && $months >= 1): ?>
        <div class="flex flex-wrap gap-2 mb-4">
            <?php for ($i = 1; $i <= $months; $i++): ?>
                <a href="<?= e(plabel_url($itemId, 'prepared', $i)) ?>"
                   class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $i === $selMonth ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>">
                    মাস <?= $i ?>
                </a>
            <?php endfor; ?>
        </div>
    <?php elseif ($mode === 'prepared' && $months < 1): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-4 text-sm text-amber-800">
            এই ব্যাচে "মোট কয়বার পার্সেল যাবে" সেট করা নেই, তাই মাস ধরে প্রস্তুত পার্সেল দেখানো যাচ্ছে না।
            <b>সব কনফার্ম শিক্ষার্থী</b> টগল ব্যবহার করুন।
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-3 mb-4 bg-white rounded-2xl shadow p-4">
        <div class="text-sm text-gray-700">
            <b class="text-gray-900"><?= count($cards) ?> টি লেবেল</b>
            <?php if ($mode === 'prepared'): ?>
                — <?= e($selLabel ?: '—') ?> মাসে পার্সেল প্রস্তুত
            <?php else: ?>
                — সব কনফার্ম শিক্ষার্থী<?php if ($inactive > 0): ?> <span class="text-gray-400">(<?= $inactive ?> জন নিষ্ক্রিয় বাদ)</span><?php endif; ?>
            <?php endif; ?>
        </div>
        <?php if ($cards): ?>
            <button type="button" onclick="window.print()" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold ml-auto">🖨️ এখন প্রিন্ট করুন</button>
        <?php endif; ?>
    </div>

    <?php if ($cards): ?>
        <p class="text-xs text-gray-500 mb-3">
            নিচের লেবেলগুলোই ছাপা হবে। 🔴 রিসিভারের নাম/মোবাইল খালি থাকলে খালিই থাকবে — পার্সেলের গায়ে লেখার আগে মিলিয়ে নিন।
            কালো বর্ডার ধরে কেটে নিন। ছাপাতে Ctrl/⌘+P চাপুন (A4, রঙিন দরকার নেই)।
        </p>
    <?php endif; ?>
</div>

<?php if (!$cards): ?>
    <div class="bg-white rounded-2xl shadow empty-state no-print">
        <div class="empty-ic"><i data-lucide="printer" class="w-8 h-8"></i></div>
        <?php if ($mode === 'prepared'): ?>
            <?= e($selLabel ?: 'এই মাস') ?>-এ কারও পার্সেল এখনো প্রস্তুত করা হয়নি।
            <a class="text-indigo-600" href="<?= e('course-parcel.php?item_id=' . $itemId . ($selMonth ? '&month=' . $selMonth : '')) ?>">কোর্স পার্সেল পাতায়</a> প্রস্তুত করুন,
            অথবা <b>সব কনফার্ম শিক্ষার্থী</b> টগল দেখুন।
        <?php else: ?>
            এই ব্যাচে কুরিয়ারে যাবে এমন confirmed কোনো শিক্ষার্থী নেই।
        <?php endif; ?>
    </div>
<?php else: ?>
    <div class="pl-sheet">
        <?php foreach ($cards as $r): ?>
            <div class="pl-card">
                <div class="pl-head">
                    <div class="pl-logo">
                        <?php if ($logoSrc !== ''): ?>
                            <img src="<?= e($logoSrc) ?>" alt="logo">
                        <?php else: ?>
                            <span class="pl-lt">লোগো</span>
                        <?php endif; ?>
                    </div>
                    <div class="pl-brand">
                        <div class="pl-en"><?= e($brandEn) ?></div>
                        <div class="pl-bn"><?= e($brandBn) ?></div>
                        <div class="pl-ph">📞 <?= e($brandPh) ?></div>
                    </div>
                </div>
                <div class="pl-body">
                    <div class="pl-row pl-name"><span class="pl-k">নাম:</span><span class="pl-v"><?= e(trim((string) ($r['receiver_name'] ?? ''))) ?></span></div>
                    <div class="pl-row pl-addr"><span class="pl-k">ঠিকানা:</span><span class="pl-v"><?= e(trim((string) ($r['address'] ?? ''))) ?></span></div>
                    <div class="pl-row pl-mob"><span class="pl-k">মোবাইল:</span><span class="pl-v"><?= e(trim((string) ($r['receiver_phone'] ?? ''))) ?></span></div>
                    <div class="pl-row pl-fb"><span class="pl-k">যোগাযোগ নামঃ</span><span class="pl-v"><?= e(trim((string) ($r['facebook_id'] ?? ''))) ?></span></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
