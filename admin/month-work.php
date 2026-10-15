<?php
// ─────────────────────────────────────────────────────────────────────────────
// 📅 এই মাসের কাজ (২০২৬-১০-১৫) — গোছানোর পরিকল্পনার ধাপ ১।
//
// ইউজারের আসল রোজকার কাজ একটাই: "এই মাসে কার পার্সেল যাবে, কে টাকা দিয়েছে,
// হিসাব মিলেছে কি" — অথচ করতে হতো ৫-৬টা পাতা ঘুরে। এই পাতা সেটাই এক পর্দায় আনে।
//
// 🔴🔴 সবচেয়ে বড় নকশার নিয়ম: **এই পাতা একটাও নতুন হ্যান্ডলার লেখে না।**
//     পাতাটায় কোনো POST হ্যান্ডলার নেই — প্রতিটা বোতাম **বিদ্যমান প্রমাণিত**
//     হ্যান্ডলারেই POST করে, `return_url` সাথে নিয়ে (যাতে কাজ শেষে এখানেই ফেরে):
//       • "জমা নিন"       → registrations.php?action=pay-collect   (t/quickpay.php, ৫৩)
//       • "সবার খাতা বসান" → course-parcel.php?action=ensure-ledger (t/autoledger.php, ৩২)
//       • "পার্সেল ঠিক করুন"→ course-parcel.php?item_id=..&month=..  (সাধারণ লিংক)
//       • "বিস্তারিত"      → registrations.php?action=view           (সাধারণ লিংক)
//     কারণ: টাকা বসানোর নিয়ম দুই জায়গায় থাকলে সময়ের সাথে নীরবে আলাদা হয়ে যেত,
//     আর "একই কাজের অনেক দরজা" — এই প্রজেক্টের সবচেয়ে পুনরাবৃত্ত বাগ-শ্রেণি
//     (CLAUDE.md-এর `form=` ঘর দেখুন)। **এখানে কখনো নিজের POST হ্যান্ডলার লিখবেন না।**
//
// 🔴 টাকার কোনো হিসাব এখানে নতুন করে লেখা হয়নি — "এই মাসে বাকি" সবসময়
//    `pay_month_outstanding()` থেকে, হুবহু যেটা course-parcel.php-র কালেকশনে বসে।
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/payments.php';
admin_require_login();

$db        = get_db();
$pageTitle = 'এই মাসের কাজ';

// মাস নম্বর → লেবেল। 🔴 `cp_month_label()`/`pay_month_label()`-এর সাথে **হুবহু এক**
//    থাকতেই হবে — `courier_batches.period_label`-এ ঐ লেখাটাই সেভ থাকে, স্ট্রিং
//    মিলিয়েই "এই মাসের পার্সেল গেছে কিনা" বের হয়। তাই নিজে নতুন করে লেখা হয়নি।
function mw_month_label(int $i): string { return pay_month_label($i); }

function mw_url(int $itemId = 0, int $month = 0): string
{
    $u = 'month-work.php';
    if ($itemId) { $u .= '?item_id=' . $itemId; if ($month) { $u .= '&month=' . $month; } }
    return $u;
}

$itemId = (int) ($_GET['item_id'] ?? 0);
require __DIR__ . '/includes/layout-top.php';

// ───────────────────────── কোর্স-ব্যাচ পিকার ─────────────────────────
// 🔴 কোয়েরিটা course-parcel.php-র পিকারের হুবহু প্যাটার্ন (hide_parcel = 0) —
//    দুই পাতায় দুই রকম তালিকা দেখালে অ্যাডমিন গুলিয়ে ফেলতেন।
if (!$itemId) {
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c, cb.total_parcels
         FROM registrations r
         JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch, cb.total_parcels
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="mb-5">
        <h1 class="text-2xl font-bold text-gray-800">📅 এই মাসের কাজ</h1>
        <p class="text-sm text-gray-500 mt-1">কোন কোর্স-ব্যাচ নিয়ে কাজ করবেন বেছে নিন — কার পার্সেল বাকি, কে টাকা দেননি, সব এক পর্দায়।</p>
    </div>
    <?php if (!$batches): ?>
        <div class="bg-white rounded-2xl shadow empty-state">
            <div class="empty-ic"><i data-lucide="calendar-check" class="w-8 h-8"></i></div>
            পার্সেল যায় এমন কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।
        </div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th>
                <th class="py-3 px-4">শিক্ষার্থী</th><th class="py-3 px-4">মোট মাস</th><th class="py-3 px-4">অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php foreach ($batches as $b): ?>
                <tr class="border-b last:border-0">
                    <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                    <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                    <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                    <td class="py-2.5 px-4"><?= (int) $b['total_parcels'] ?: '<span class="text-gray-300">সেট করা হয়নি</span>' ?></td>
                    <td class="py-2.5 px-4"><a href="<?= e(mw_url((int) $b['item_id'])) ?>" class="text-indigo-600 font-semibold inline-block py-1">খুলুন →</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif;
    require __DIR__ . '/includes/layout-bottom.php';
    return;
}

// ───────────────────────── এক ব্যাচের মাস-ভিত্তিক কাজ ─────────────────────────
$cStmt = $db->prepare('SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
if (!$course) {
    echo '<div class="bg-white rounded-2xl shadow empty-state">কোর্স-ব্যাচটি পাওয়া যায়নি। <a class="text-indigo-600" href="month-work.php">← ফিরে যান</a></div>';
    require __DIR__ . '/includes/layout-bottom.php';
    return;
}

$totalParcels = (int) ($course['total_parcels'] ?? 0);
$months       = max($totalParcels, 0);
$selMonth     = (int) ($_GET['month'] ?? 1);
$selMonth     = $months > 0 ? max(1, min($selMonth, $months)) : 0;
$selLabel     = $selMonth ? mw_month_label($selMonth) : '';

$rStmt = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$rStmt->execute(['id' => $itemId]);
$regs = $rStmt->fetchAll();

// এই ব্যাচের সবার খাতা **এক কোয়েরিতে** (N+1 এড়াতে — course-parcel.php-র মতোই)
$ledgerByReg = $regs ? pay_fetch_many($db, array_map(fn($r) => (int) $r['id'], $regs)) : [];

// এই মাসের পার্সেলের অবস্থা — (registration, period_label) ধরে
$sentByReg = [];
if ($regs && $selLabel !== '') {
    $ids = array_map(fn($r) => (int) $r['id'], $regs);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $db->prepare("SELECT registration_id, send_status, amount_to_collect
                         FROM courier_batches WHERE registration_id IN ($in) AND period_label = ?");
    $st->execute(array_merge($ids, [$selLabel]));
    foreach ($st->fetchAll() as $row) { $sentByReg[(int) $row['registration_id']] = $row; }
}

// 📒 কাদের খাতা এখনো বসানো হয়নি — 🔴 শর্তটা course-parcel.php-র `$noLedgerCount`-এর
//    হুবহু কপি (আগে আয় অনুমোদিত অথচ খাতা নেই — এমনগুলো গোনা হয় না, ওগুলোর অঙ্ক
//    পুরনো ভুল নিয়মে বসে থাকতে পারে; সেগুলো "আয় মেলানো" পাতার কাজ)।
$noLedger = 0;
foreach ($regs as $r) {
    if (empty($ledgerByReg[(int) $r['id']] ?? []) && empty($r['income_approved'])) { $noLedger++; }
}

$activeRegs = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) === 1));

// সারাংশ — 🔴 প্রতিটা অঙ্ক `pay_*()` থেকে, এখানে নতুন কোনো হিসাব লেখা হয়নি
$sumDue = 0.0; $sentCnt = 0; $paidCnt = 0;
foreach ($activeRegs as $r) {
    $rid  = (int) $r['id'];
    $rows = $ledgerByReg[$rid] ?? [];
    $due  = $rows ? pay_month_outstanding($rows, $selMonth ?: 1, $totalParcels) : 0.0;
    $sumDue += $due;
    if ($due <= 0.009) { $paidCnt++; }
    if (($sentByReg[$rid]['send_status'] ?? '') === 'sent') { $sentCnt++; }
}
$currentUrl = mw_url($itemId, $selMonth);
?>

<div class="mb-4">
    <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-2xl font-bold text-gray-800">📅 এই মাসের কাজ</h1>
        <a href="month-work.php" class="text-xs text-indigo-600 py-1">← অন্য কোর্স</a>
    </div>
    <p class="text-sm text-gray-600 mt-1">
        <span class="font-semibold text-gray-800"><?= e($course['title']) ?></span>
        <span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($course['batch_name'] ?? '') ?></span>
    </p>
</div>

<?php if ($months < 1): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-sm text-amber-800">
        এই ব্যাচে <b>"মোট কয়বার পার্সেল যাবে"</b> সেট করা নেই, তাই মাস ধরে কাজ দেখানো যাচ্ছে না।
        <a class="font-semibold underline" href="<?= e('course-parcel.php?item_id=' . $itemId) ?>">কোর্স পার্সেল পাতায় গিয়ে</a> সংখ্যাটা বসিয়ে আসুন।
    </div>
<?php else: ?>
    <?php /* মাস-ট্যাব — course-parcel.php-র `.cp-tab` বারের মতোই */ ?>
    <div class="flex flex-wrap gap-2 mb-5">
        <?php for ($i = 1; $i <= $months; $i++): ?>
            <a href="<?= e(mw_url($itemId, $i)) ?>"
               class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $i === $selMonth ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>">
                মাস <?= $i ?>
            </a>
        <?php endfor; ?>
    </div>
<?php endif; ?>

<?php /* ── এক নজরে ── */ ?>
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-2xl shadow p-4 min-w-0">
        <div class="text-xs text-gray-500">সক্রিয় শিক্ষার্থী</div>
        <div class="text-xl font-bold text-gray-800"><?= count($activeRegs) ?> জন</div>
        <?php if (count($regs) > count($activeRegs)): ?>
            <div class="text-xs text-gray-400 mt-1"><?= count($regs) - count($activeRegs) ?> জন নিষ্ক্রিয়</div>
        <?php endif; ?>
    </div>
    <div class="bg-white rounded-2xl shadow p-4 min-w-0">
        <div class="text-xs text-gray-500">পার্সেল গেছে</div>
        <div class="text-xl font-bold <?= $sentCnt >= count($activeRegs) && $activeRegs ? 'text-green-600' : 'text-gray-800' ?>">
            <?= $sentCnt ?> / <?= count($activeRegs) ?>
        </div>
        <div class="text-xs text-gray-400 mt-1"><?= e($selLabel ?: '—') ?></div>
    </div>
    <div class="bg-white rounded-2xl shadow p-4 min-w-0">
        <div class="text-xs text-gray-500">টাকা মিলেছে</div>
        <div class="text-xl font-bold <?= $paidCnt >= count($activeRegs) && $activeRegs ? 'text-green-600' : 'text-gray-800' ?>">
            <?= $paidCnt ?> / <?= count($activeRegs) ?>
        </div>
        <div class="text-xs text-gray-400 mt-1">এই মাসের কিস্তি</div>
    </div>
    <div class="bg-white rounded-2xl shadow p-4 min-w-0">
        <div class="text-xs text-gray-500">এই মাসে বাকি</div>
        <div class="text-xl font-bold <?= $sumDue > 0.009 ? 'text-red-600' : 'text-green-600' ?> break-all">
            ৳<?= number_format($sumDue) ?>
        </div>
        <div class="text-xs text-gray-400 mt-1">সবার মিলিয়ে</div>
    </div>
</div>

<?php if ($noLedger > 0): ?>
    <?php /* 🔴 ফর্মটা course-parcel.php-র বিদ্যমান `ensure-ledger` হ্যান্ডলারেই যায় —
             এখানে নিজের কোনো হ্যান্ডলার নেই। `return_url` দেওয়ায় কাজ শেষে এই পাতাতেই ফেরে। */ ?>
    <form method="POST" action="course-parcel.php" class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="ensure-ledger">
        <input type="hidden" name="item_id" value="<?= (int) $itemId ?>">
        <input type="hidden" name="month" value="<?= (int) $selMonth ?>">
        <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
        <p class="text-sm text-amber-800 mb-2">
            📒 <b><?= (int) $noLedger ?> জনের টাকার খাতা এখনো বসানো হয়নি</b> — তাই তাঁদের "এই মাসে বাকি" ৳০ দেখাচ্ছে।
            খাতা বসালে আসল বাকিটা বসবে <span class="text-amber-700">(জমা ০ থেকে শুরু, আয়ের হিসাব নড়ে না)।</span>
        </p>
        <button type="submit" class="bg-amber-600 text-white px-4 py-2 rounded-lg text-sm font-semibold">📒 সবার খাতা বসান</button>
    </form>
<?php endif; ?>

<?php if (!$regs): ?>
    <div class="bg-white rounded-2xl shadow empty-state">
        <div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>
        এই ব্যাচে confirmed কোনো রেজিস্ট্রেশন নেই।
    </div>
<?php else: ?>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
        <h2 class="font-bold text-gray-800"><?= e($selLabel ?: 'শিক্ষার্থী') ?> — কার কী বাকি</h2>
        <?php if ($selMonth): ?>
            <a href="<?= e('course-parcel.php?item_id=' . $itemId . '&month=' . $selMonth) ?>"
               class="text-sm bg-indigo-600 text-white px-4 py-2 rounded-lg font-semibold">📦 এই মাসের পার্সেল ঠিক করুন →</a>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
            <th class="py-3 px-4">শিক্ষার্থী</th>
            <th class="py-3 px-4">পার্সেল</th>
            <th class="py-3 px-4">এই মাসে বাকি</th>
            <th class="py-3 px-4">টাকা জমা নিন</th>
            <th class="py-3 px-4"></th>
        </tr></thead>
        <tbody>
        <?php foreach ($regs as $r):
            $rid    = (int) $r['id'];
            $rows   = $ledgerByReg[$rid] ?? [];
            $active = (int) ($r['courier_active'] ?? 1) === 1;
            $due    = $rows ? pay_month_outstanding($rows, $selMonth ?: 1, $totalParcels) : 0.0;
            $st     = $sentByReg[$rid]['send_status'] ?? '';
            // রঙিন দাগ — inline box-shadow (কম্পাইলড CSS-এ `border-l-4` রঙগুলো নেই)
            $bar = !$active ? '#9CA3AF' : ($due > 0.009 ? '#DC2626' : '#16A34A');
        ?>
            <tr id="reg-<?= $rid ?>" class="border-b last:border-0 <?= $active ? '' : 'opacity-60' ?>"
                style="box-shadow: inset 3px 0 0 <?= $bar ?>;">
                <td class="py-3 px-4" data-label="শিক্ষার্থী">
                    <div class="min-w-0">
                        <div class="font-semibold text-gray-800"><?= e($r['customer_name']) ?></div>
                        <div class="text-xs text-gray-500">
                            <a href="tel:<?= e($r['phone']) ?>" class="text-indigo-600"><?= e($r['phone']) ?></a>
                            <?= $active ? '' : ' · <span class="text-gray-400">নিষ্ক্রিয়</span>' ?>
                        </div>
                    </div>
                </td>
                <td class="py-3 px-4" data-label="পার্সেল">
                    <?php if (!$active): ?>
                        <span class="text-xs text-gray-400">যাবে না</span>
                    <?php elseif ($st === 'sent'): ?>
                        <span class="text-green-700 font-semibold text-xs">✅ গেছে</span>
                    <?php elseif ($st === 'declined'): ?>
                        <span class="text-xs text-gray-500">⊘ এই মাসে না</span>
                    <?php elseif ($st !== ''): ?>
                        <span class="text-amber-700 font-semibold text-xs">● প্রস্তুত</span>
                    <?php else: ?>
                        <span class="text-xs text-gray-400">⬜ যায়নি</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 px-4" data-label="এই মাসে বাকি">
                    <?php if (!$rows): ?>
                        <span class="text-xs text-amber-700">খাতা বসানো হয়নি</span>
                    <?php elseif ($due > 0.009): ?>
                        <span class="font-bold text-red-600">৳<?= number_format($due) ?></span>
                    <?php else: ?>
                        <span class="text-green-700 font-semibold text-xs">✓ মিলেছে</span>
                    <?php endif; ?>
                    <?php
                    // 💰 দ্বিতীয় লাইনে **সব মাস মিলিয়ে মোট বাকি** (২০২৬-১০-১৫) — উপরের
                    // অঙ্কটা শুধু এই মাসের কিস্তির, তাই আগের মাসের বকেয়া চোখেই পড়ত না।
                    // 🔴 নতুন কলাম নয় (তালিকার নিয়ম), আর হিসাবও নতুন করে লেখা হয়নি —
                    //    `pay_summary()`-র balance, অর্থাৎ registrations.php-র চিপ ও
                    //    `due_amount` যা দেখায় হুবহু তাই।
                    // 🔴 এই মাসের অঙ্কের চেয়ে বেশি হলেই কেবল দেখানো হয় (নাহলে একই
                    //    সংখ্যা দুইবার দেখাত)।
                    $allDue = $rows ? pay_summary($rows)['balance'] : 0.0;
                    if ($allDue > $due + 0.009): ?>
                        <div class="text-[11px] text-red-600 font-semibold mt-0.5"
                             title="সব মাস মিলিয়ে এখনো বাকি">সব মিলিয়ে ৳<?= number_format($allDue) ?></div>
                    <?php endif; ?>
                </td>
                <td class="py-3 px-4" data-label="টাকা জমা নিন">
                    <?php if ($rows): ?>
                        <?php /* 🔴🔴 নিজের আলাদা `<form>` — `form=` অ্যাট্রিবিউট **কখনো নয়**
                                 (CLAUDE.md-এর `form=` ঘর: ওভাবে বাঁধা ঘর অন্য বোতামে নীরবে হারায়)।
                                 গন্তব্য registrations.php-র প্রমাণিত `pay-collect` হ্যান্ডলার। */ ?>
                        <?php /* 🔴🔴 `action=pay-collect` **query string-এ**, hidden ইনপুটে নয় —
                                 registrations.php `$action` পড়ে `$_GET` থেকে (`$action = $_GET['action'] ?? 'list'`)।
                                 POST বডিতে দিলে হ্যান্ডলারটা চলতই না: পাতা HTTP ২০০ দিত, কোনো এরর নেই,
                                 আর **টাকাটা নীরবে বসত না** (টেস্টে ধরা)। ⚠️ course-parcel.php ঠিক উল্টো —
                                 ওটা `$_POST['action']` পড়ে, তাই উপরের খাতা-ফর্মে hidden ইনপুটই লাগে। */ ?>
                        <form method="POST" action="registrations.php?action=pay-collect" class="flex items-center gap-1 min-w-0">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $rid ?>">
                            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                            <input type="text" name="amount" inputmode="decimal" placeholder="৳"
                                   class="w-full border rounded-lg px-2 py-1.5 text-sm" style="min-width:0;max-width:110px;">
                            <?php if ($due > 0.009): ?>
                                <button type="button" class="mw-chip text-xs bg-gray-100 text-gray-700 px-2 py-1.5 rounded-lg whitespace-nowrap"
                                        data-amt="<?= e((string) round($due, 2)) ?>">৳<?= number_format($due) ?></button>
                            <?php endif; ?>
                            <button type="submit" class="bg-green-600 text-white px-3 py-1.5 rounded-lg text-sm font-semibold whitespace-nowrap">✅</button>
                        </form>
                    <?php else: ?>
                        <span class="text-xs text-gray-400">—</span>
                    <?php endif; ?>
                </td>
                <td class="py-3 px-4" data-label="">
                    <a href="<?= e('registrations.php?action=view&id=' . $rid . '&return=' . rawurlencode($currentUrl)) ?>"
                       class="text-indigo-600 text-sm font-semibold inline-block py-1 whitespace-nowrap">বিস্তারিত →</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>

    <p class="text-xs text-gray-500 mt-3">
        🔴 "এই মাসে বাকি" আসে <b>টাকার খাতা</b> থেকে — কোর্স পার্সেলের কালেকশনে ঠিক এই অঙ্কটাই বসে।
        টাকা বসানোর কাজটা অর্ডারের "টাকা" ড্রয়ারের মতোই হয় (একই পথ), তাই আয়ের হিসাবও নিজে থেকে মিলে যায়।
    </p>
<?php endif; ?>

<?php /* 🔴 নিজের আলাদা স্ক্রিপ্ট-ব্লক (layout-bottom-এর বড় স্ক্রিপ্টের ভেতরে নয়) */ ?>
<script>
(function () {
    // শর্টকাট চিপ — ট্যাপ করলে অঙ্কটা ঘরে **বসে মাত্র**, সেভ হয় না (অনুমান নিষিদ্ধ)
    document.querySelectorAll('.mw-chip').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = b.closest('form'); if (!f) { return; }
            var i = f.querySelector('input[name="amount"]'); if (!i) { return; }
            i.value = b.getAttribute('data-amt') || ''; i.focus();
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
