<?php
// ── 💰 আয় মেলানো — বইয়ের আয় আর টাকার খাতা এক করা (২০২৬-০৯-২৯)
//
// 🔴🔴 কেন এই পেজ: ২০২৬-০৯-২৯ এর আগে "কনফার্ম" চাপলেই আয় বসে যেত, আর অঙ্কটা হতো আইটেমের
//    **বর্তমান দাম** (কোর্সে মাসিক বেতন ৳790) — অথচ অভিভাবক তখন হয়তো শুধু রেজিস্ট্রেশন ফি
//    ৳500 দিয়েছেন। নিয়মটা ঠিক করা হয়েছে (আয় এখন **শুধু টাকার খাতার মোট জমা** থেকে), কিন্তু
//    আগে বসে যাওয়া ভুল অঙ্কগুলো বইয়েই থেকে গেছে। এক-একটা অর্ডারের বিস্তারিততে গিয়ে ঠিক করা
//    কষ্টকর — তাই এই পেজে **এক তালিকা থেকে, এক সেভে** সবগুলো মিলিয়ে নেওয়া যায়।
//
// 🔴 এই পেজ কখনো নিজে থেকে টাকার অঙ্ক অনুমান করে না — "আসলে কত টাকা এসেছে" অ্যাডমিন নিজে
//    লেখেন (পাশের চিপগুলো নিছক শর্টকাট, অ্যাডমিন ট্যাপ করলে তবেই বসে)। অনুমান করাই ছিল
//    মূল বাগ, সেটাই আবার ফিরিয়ে আনা যাবে না।
//
// কী হয় সেভ করলে: লেখা টাকাটা দিয়ে ঐ অর্ডারের **খাতা তৈরি হয়** (pay_build_plan → কিস্তি,
// pay_allocate_paid → জমা উপর থেকে নিচে), তারপর `sync_income_for_status()` বইয়ের আয় খাতার
// মোট জমার সমান করে দেয় — অর্থাৎ registrations.php-এর "টাকা" ড্রয়ারে হাতে করলে যা হতো, হুবহু তাই।
//
// ⚠️ খাতা **আগে থেকেই থাকলে** এই পেজ খাতা ছোঁয় না — খাতাই সত্য, শুধু বইয়ের আয় তার সাথে
//    মিলিয়ে দেওয়া হয় ("গরমিল" ট্যাব)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/payments.php';
require_once __DIR__ . '/includes/income-sync.php';
admin_require_login();

$db = get_db();
$pageTitle = 'আয় মেলানো';

const IFX_PER_PAGE   = 50;
const IFX_MAX_AMOUNT = 1000000;   // টাইপো-গার্ড (ফোন নম্বর বসিয়ে ফেলা ইত্যাদি)

$groupLabels = [
    'orphan'   => '⚠️ খাতা নেই, অথচ আয় বসানো',
    'nopaid'   => '📥 খাতা আছে, জমা বসানো হয়নি',
    'noledger' => '📒 খাতা এখনো বসানো হয়নি',
    'mismatch' => '↔️ খাতা ও বইয়ে গরমিল',
];

$activeFilters = array_filter([
    'g'    => isset($groupLabels[$_GET['g'] ?? '']) ? (string) $_GET['g'] : '',
    'q'    => trim((string) ($_GET['q'] ?? '')),
    'item' => trim((string) ($_GET['item'] ?? '')),
    'page' => ((int) ($_GET['page'] ?? 1)) > 1 ? (int) $_GET['page'] : '',
], fn($v) => $v !== '' && $v !== null);

$group = $activeFilters['g'] ?? 'orphan';

// registrations.php-এর reg_url() প্যাটার্নের কপি — ফিল্টার সব লিংকে অটো বয়ে যায়
function ifx_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'income-fix.php' . ($p ? '?' . http_build_query($p) : '');
}

// POST-এ আসা return মান যাচাই (open-redirect / হেডার-ইনজেকশন গার্ড)
function ifx_safe_return(): string
{
    $r = preg_replace('/[\x00-\x1F\x7F]/', '', (string) ($_POST['return'] ?? ''));
    return ($r !== '' && strpos($r, 'income-fix.php') === 0) ? $r : 'income-fix.php';
}

$money = fn($v) => '৳' . number_format((float) $v, (fmod((float) $v, 1) == 0.0) ? 0 : 2);

// ---------------- POST: মেলানো ----------------
// action=fix → delete-অ্যাকশন নয়, তাই মডারেটরের `orders` সেকশনে **edit** cap-ই যথেষ্ট
// (কেন্দ্রীয় গার্ড admin_require_login()-এ)।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'fix') {
    $back = ifx_safe_return();
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($back);
    }

    $amounts = is_array($_POST['amt'] ?? null) ? $_POST['amt'] : [];
    $syncs   = is_array($_POST['sync'] ?? null) ? $_POST['sync'] : [];

    // কোন কোন সারিতে সত্যিই কিছু লেখা হয়েছে
    $want = [];
    foreach ($amounts as $rid => $raw) {
        $raw = trim((string) $raw);
        if ($raw === '') {
            continue;   // খালি ঘর = "এটা এখন থাক" — কিছুই বদলায় না
        }
        $want[(int) $rid] = (float) str_replace([',', '৳', ' '], '', $raw);
    }
    $syncIds = [];
    foreach ($syncs as $rid => $on) {
        if ($on) {
            $syncIds[] = (int) $rid;
        }
    }

    $ids = array_values(array_unique(array_merge(array_keys($want), $syncIds)));
    $ids = array_values(array_filter($ids, fn($i) => $i > 0));

    if (!$ids) {
        set_flash('error', 'কোনো ঘরে টাকা লেখা হয়নি — কিছুই বদলানো হয়নি।');
        redirect($back);
    }

    $before = (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM income')->fetchColumn();
    $done = $filled = $resynced = $skipped = $locked = 0;
    $err = '';

    try {
        $db->beginTransaction();
        $in   = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare("SELECT * FROM registrations WHERE id IN ($in)");
        $stmt->execute($ids);
        foreach ($stmt->fetchAll() as $reg) {
            $rid    = (int) $reg['id'];
            $ledger = pay_fetch_many($db, [$rid])[$rid] ?? [];

            $amount = $want[$rid] ?? null;
            if ($amount !== null && ($amount < 0 || $amount > IFX_MAX_AMOUNT)) {
                $skipped++;   // অসম্ভব অঙ্ক (টাইপো) — ছোঁয়া হয় না
                $amount = null;
            }

            if ($ledger) {
                // 🔴🔴 সেভ করা খাতার কিস্তি কখনো নতুন করে বসানো হয় না — একটাই ব্যতিক্রম:
                //    **মোট জমা ০** (তখন হারানোর মতো কিছুই নেই, শুধু জমার ঘরগুলো ভরে)।
                //    জমা থাকলে অ্যাডমিন অর্ডারের "টাকা" ড্রয়ারে গিয়ে নিজে বদলাবেন।
                if ($amount !== null) {
                    if (pay_paid_total($ledger) >= 0.01) {
                        $locked++;
                        continue;
                    }
                    // বিদ্যমান সারিগুলোতেই জমা বসে (id সহ যায় বলে UPDATE হয়, নাম/প্রাপ্য/ছাড় অক্ষত)
                    pay_save_rows($db, $rid, pay_allocate_paid($ledger, $amount));
                    sync_income_for_status($db, $reg, (string) $reg['status']);
                    $filled++;
                    continue;
                }
                // খাতা আছে, টাকা লেখা হয়নি → শুধু বই মেলানো (চেকবক্স দিলে)
                if (in_array($rid, $syncIds, true)) {
                    sync_income_for_status($db, $reg, (string) $reg['status']);
                    $resynced++;
                }
                continue;
            }
            if ($amount === null) {
                continue;
            }

            // registrations.php-এর "টাকা" ড্রয়ারে যা হয় হুবহু তাই: কিস্তির ছক + জমা বসানো
            $rows = pay_allocate_paid(pay_build_plan($db, $reg), $amount);
            pay_save_rows($db, $rid, $rows);
            sync_income_for_status($db, $reg, (string) $reg['status']);
            $done++;
        }
        $db->commit();
    } catch (PDOException $ex) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $err = $ex->getMessage();
    }

    if ($err !== '') {
        set_flash('error', 'সমস্যা হয়েছে, কিছুই বদলানো হয়নি: ' . $err);
        redirect($back);
    }

    $after = (float) $db->query('SELECT COALESCE(SUM(amount), 0) FROM income')->fetchColumn();
    $parts = [];
    if ($done > 0)     { $parts[] = $done . ' টি অর্ডারের খাতা বসানো হয়েছে'; }
    if ($filled > 0)   { $parts[] = $filled . ' টির খাতায় জমা বসানো হয়েছে'; }
    if ($resynced > 0) { $parts[] = $resynced . ' টির বই খাতার সাথে মেলানো হয়েছে'; }
    $msg = ($parts ? implode(', ', $parts) : 'কিছু বদলানো হয়নি')
        . '। বইয়ে মোট আয় ৳' . number_format($before, 2) . ' → ৳' . number_format($after, 2) . '।';
    if ($skipped > 0) {
        $msg .= ' ⚠️ ' . $skipped . ' টি ঘরে অস্বাভাবিক অঙ্ক (০-এর কম বা ১০ লাখের বেশি) থাকায় বাদ দেওয়া হয়েছে।';
    }
    if ($locked > 0) {
        $msg .= ' ⚠️ ' . $locked . ' টিতে খাতায় আগে থেকেই জমা আছে বলে এখান থেকে বদলানো হয়নি — অর্ডারের "টাকা" ড্রয়ারে গিয়ে ঠিক করুন।';
    }
    set_flash('success', $msg);
    redirect($back);
}

// ---------------- তালিকা ----------------
// ⚠️ খাতার টেবিল না থাকলে (migrate-payment-ledger.sql চালানো হয়নি) পেজ ভাঙবে না
$ready    = true;
$counts   = ['orphan' => 0, 'nopaid' => 0, 'noledger' => 0, 'mismatch' => 0];
$byGroup  = ['orphan' => [], 'nopaid' => [], 'noledger' => [], 'mismatch' => []];
$itemOpts = [];

try {
    $where  = ["r.status IN ('confirmed', 'shipped', 'delivered')"];
    $params = [];
    if (!empty($activeFilters['q'])) {
        // 🔴 LIKE-এর % ও _ escape (search.php-এর মতোই, escape-অক্ষর `!`)
        $needle = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_substr($activeFilters['q'], 0, 100)) . '%';
        $where[] = "(r.customer_name LIKE :q ESCAPE '!' OR r.phone LIKE :q ESCAPE '!')";
        $params['q'] = $needle;
    }
    if (!empty($activeFilters['item'])) {
        $where[] = 'r.item_title = :item';
        $params['item'] = $activeFilters['item'];
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $stmt = $db->prepare(
        "SELECT r.*,
                (SELECT COUNT(*) FROM registration_payments rp WHERE rp.registration_id = r.id) AS ledger_rows,
                (SELECT COALESCE(SUM(rp.amount_paid), 0) FROM registration_payments rp WHERE rp.registration_id = r.id) AS ledger_paid,
                (SELECT COALESCE(SUM(i.amount), 0) FROM income i WHERE i.registration_id = r.id) AS booked
         FROM registrations r
         $whereSql
         ORDER BY r.id DESC
         LIMIT 3000"
    );
    $stmt->execute($params);

    foreach ($stmt->fetchAll() as $row) {
        $lr = (int) $row['ledger_rows'];
        $lp = round((float) $row['ledger_paid'], 2);
        $bk = round((float) $row['booked'], 2);
        if ($lr === 0) {
            $g = ($bk > 0 || !empty($row['income_approved'])) ? 'orphan' : 'noledger';
        } elseif (abs($lp - $bk) >= 0.01) {
            $g = 'mismatch';
        } elseif ($lp < 0.01) {
            // খাতা বসানো আছে কিন্তু কোনো কিস্তিতে জমা ০ — তাই আয়ও ০। টাকা সত্যিই এসে থাকলে
            // এখান থেকে বসানো যায় (ইউজারের ধরা কেস: রেজি ফি ৳500 এসেছে, কিন্তু কোথাও লেখা হয়নি)।
            $g = 'nopaid';
        } else {
            continue;   // খাতা আছে, জমাও আছে, বইও মিলছে — এখানে দেখানোর কিছু নেই
        }
        $counts[$g]++;
        $byGroup[$g][] = $row;
    }

    // কোর্স-ড্রপডাউনের অপশন রেজিস্ট্রেশনের স্ন্যাপশট থেকে (কোর্স রিনেম/ডিলিটেও অপশন হারায় না)
    $itemOpts = $db->query(
        "SELECT DISTINCT item_title FROM registrations
         WHERE status IN ('confirmed', 'shipped', 'delivered') AND item_title <> ''
         ORDER BY item_title"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ex) {
    $ready = false;
}

$all   = $byGroup[$group] ?? [];
$total = count($all);
$pages = max(1, (int) ceil($total / IFX_PER_PAGE));
$page  = min(max(1, (int) ($activeFilters['page'] ?? 1)), $pages);   // সীমার বাইরে হলে শেষ পাতায়
$rows  = array_slice($all, ($page - 1) * IFX_PER_PAGE, IFX_PER_PAGE);

// এই পাতার কোর্স-ব্যাচগুলো একবারেই (N+1 এড়াতে) — শুধু "রেজি ফি" চিপ দেখানোর জন্য
$regFeeByBatch = [];
$batchIds = [];
foreach ($rows as $row) {
    if ($row['type'] === 'course' && (int) $row['item_id'] > 0) {
        $batchIds[(int) $row['item_id']] = true;
    }
}
if ($batchIds) {
    try {
        $in = implode(',', array_fill(0, count($batchIds), '?'));
        $bs = $db->prepare("SELECT * FROM course_batches WHERE id IN ($in)");
        $bs->execute(array_keys($batchIds));
        foreach ($bs->fetchAll() as $b) {
            $regFeeByBatch[(int) $b['id']] = pay_batch_reg_fee($b);
        }
    } catch (PDOException $ex) {
        $regFeeByBatch = [];
    }
}

// এই পাতার যেসব সারিতে খাতা আছে, তাদের খাতা একবারেই (N+1 এড়াতে) — চিপে ঐ খাতার
// **নিজের** প্রথম কিস্তিটাই দেখানো হয় (ব্যাচের সেটিংসের চেয়ে সেটাই সত্য)
$ledgerChip = [];
$withLedger = [];
foreach ($rows as $row) {
    if ((int) $row['ledger_rows'] > 0) {
        $withLedger[] = (int) $row['id'];
    }
}
if ($withLedger) {
    foreach (pay_fetch_many($db, $withLedger) as $rid => $lrows) {
        foreach ($lrows as $lr) {
            if (pay_is_skipped($lr)) {
                continue;
            }
            $net = pay_net($lr);
            if ($net > 0) {
                $ledgerChip[$rid] = [mb_substr((string) $lr['label'], 0, 22), $net];
                break;
            }
        }
    }
}

$curReturn = ifx_url();
require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-payment-ledger.sql</code> চালান — টাকার খাতা ছাড়া আয় মেলানো যায় না।</p>
</div>
<?php else: ?>

<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <p class="text-gray-700 text-sm leading-relaxed">
        আগে <b>কনফার্ম করলেই</b> আয় অটো বসে যেত, আর অঙ্কটা হতো কোর্সের <b>বর্তমান দাম</b> — তাই কারো কারো
        বইয়ে ৳790 (মাসিক বেতন) বসে গেছে যদিও হাতে এসেছিল শুধু রেজিস্ট্রেশন ফি। এখন আয়ের একমাত্র উৎস
        <b>টাকার খাতা</b>। নিচের ঘরে <b>আসলে কত টাকা হাতে এসেছে</b> লিখে একবারে সেভ করলে খাতা বসে যাবে
        আর বইয়ের আয়ও ঠিক হয়ে যাবে।
    </p>
    <p class="text-gray-700 text-sm leading-relaxed mt-2">
        খাতা বসানো আছে অথচ <b>কোনো কিস্তিতে জমা ০</b> — এমন অর্ডারও এখানে আসে
        (<b>📥 খাতা আছে, জমা বসানো হয়নি</b> ট্যাব)। ওখানে টাকা লিখলে <b>খাতার কিস্তিগুলো অপরিবর্তিত থাকে</b>,
        শুধু জমার ঘর ভরে যায়।
    </p>
    <p class="text-gray-500 text-xs mt-2">🔴 অনুমান করে লিখবেন না — যত টাকা সত্যিই পেয়েছেন ততটুকুই। মনে না থাকলে ঘরটা খালি রাখুন, পরে ঠিক করা যাবে।</p>
</div>

<!-- গ্রুপ পিল -->
<div class="flex flex-wrap gap-2 mb-5">
    <?php foreach ($groupLabels as $gk => $glabel): ?>
        <a href="<?= e(ifx_url(['g' => $gk, 'page' => null])) ?>"
           class="px-4 py-2 rounded-xl text-sm font-semibold <?= $group === $gk ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>">
            <?= e($glabel) ?>
            <span class="ml-1 <?= $group === $gk ? 'text-white' : 'text-gray-400' ?>">(<?= (int) $counts[$gk] ?>)</span>
        </a>
    <?php endforeach; ?>
</div>

<!-- ফিল্টার (সার্ভার-সাইড; id গার্ড লিস্টে আছে বলে অটো-সার্চ বক্স বসে না) -->
<form method="get" id="ifxFilterForm" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap gap-3 items-end">
    <input type="hidden" name="g" value="<?= e($group) ?>">
    <div class="flex-1" style="min-width:180px">
        <label class="block text-gray-500 text-xs font-semibold mb-1">নাম বা মোবাইল</label>
        <input type="search" name="q" id="ifxSearch" value="<?= e($activeFilters['q'] ?? '') ?>" placeholder="খুঁজুন…"
               class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:0">
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">কোর্স / আইটেম</label>
        <select name="item" onchange="document.getElementById('ifxFilterForm').submit()"
                class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <?php foreach ($itemOpts as $opt): ?>
                <option value="<?= e($opt) ?>" <?= ($activeFilters['item'] ?? '') === $opt ? 'selected' : '' ?>><?= e($opt) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!empty($activeFilters['q']) || !empty($activeFilters['item'])): ?>
        <a href="<?= e(ifx_url(['q' => null, 'item' => null, 'page' => null])) ?>" class="text-sm font-semibold text-gray-500 py-2">✕ ফিল্টার মুছুন</a>
    <?php endif; ?>
</form>

<?php if (!$rows): ?>
    <div class="empty-state">
        <div class="empty-ic">✅</div>
        <p class="font-bold text-gray-700">এই তালিকায় কিছু নেই</p>
        <p class="text-gray-500 text-sm mt-1">এখানে মেলানোর মতো কোনো অর্ডার নেই।</p>
    </div>
<?php else: ?>

<form method="post" action="income-fix.php?action=fix" id="ifxForm" onsubmit="return ifxConfirm(this);">
    <?= csrf_field() ?>
    <input type="hidden" name="return" value="<?= e($curReturn) ?>">

    <?php if ($group !== 'mismatch'): ?>
    <!-- সবার ঘরে একসাথে বসানোর শর্টকাট (নিছক টাইপিং বাঁচানো — সেভ না চাপলে কিছুই হয় না) -->
    <div class="bg-white rounded-2xl shadow p-4 mb-4 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-gray-500 text-xs font-semibold mb-1">খালি ঘরগুলোতে একসাথে বসান</label>
            <input type="number" step="0.01" min="0" id="ifxBulk" placeholder="যেমন 500"
                   class="px-3 py-2 rounded-xl border border-gray-200 text-sm" style="max-width:150px">
        </div>
        <button type="button" onclick="ifxBulkFill()" class="bg-gray-100 text-gray-700 px-4 py-2 rounded-xl text-sm font-semibold">↓ বসান</button>
        <button type="button" onclick="ifxClear()" class="text-sm font-semibold text-gray-500 py-2">সব ঘর খালি করুন</button>
        <p class="text-gray-400 text-xs flex-1">শুধু <b>খালি</b> ঘরগুলোতেই বসবে — নিজে লেখা ঘর বদলাবে না।</p>
    </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-gray-500 text-xs uppercase">
                <tr>
                    <th class="text-left px-4 py-3">শিক্ষার্থী</th>
                    <th class="text-left px-4 py-3">কোর্স / আইটেম</th>
                    <th class="text-left px-4 py-3">বইয়ে আয়</th>
                    <th class="text-left px-4 py-3">খাতা</th>
                    <th class="text-left px-4 py-3"><?= $group === 'mismatch' ? 'বই মেলান' : 'আসলে কত টাকা এসেছে' ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $rid    = (int) $row['id'];
                $booked = round((float) $row['booked'], 2);
                $lp     = round((float) $row['ledger_paid'], 2);
                $regFee = $regFeeByBatch[(int) $row['item_id']] ?? 0.0;
            ?>
                <tr class="border-b last:border-0">
                    <td class="px-4 py-3">
                        <div class="font-semibold text-gray-800"><?= e($row['customer_name']) ?></div>
                        <a href="tel:<?= e($row['phone']) ?>" class="text-xs text-gray-500"><?= e($row['phone']) ?></a>
                    </td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700"><?= e($row['item_title']) ?></div>
                        <div class="text-xs text-gray-400">
                            <?= e($row['batch'] ?: ($row['type'] === 'course' ? 'কোর্স' : ($row['type'] === 'worksheet' ? 'ওয়ার্কশিট' : 'প্রোডাক্ট'))) ?>
                            · <a href="registrations.php?action=view&amp;id=<?= $rid ?>&amp;return=<?= rawurlencode($curReturn) ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a>
                        </div>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ($booked > 0): ?>
                            <span class="font-bold text-green-700"><?= e($money($booked)) ?></span>
                        <?php elseif (!empty($row['income_approved'])): ?>
                            <span class="text-amber-600 text-xs font-semibold">অনুমোদিত, কিন্তু বইয়ে রো নেই</span>
                        <?php else: ?>
                            <span class="text-gray-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 whitespace-nowrap">
                        <?php if ((int) $row['ledger_rows'] > 0): ?>
                            <span class="text-gray-700">জমা <b><?= e($money($lp)) ?></b></span>
                        <?php else: ?>
                            <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-500">খাতা নেই</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($group === 'mismatch'): ?>
                            <label class="flex items-center gap-2 text-sm text-gray-700">
                                <input type="checkbox" name="sync[<?= $rid ?>]" value="1" class="ifx-sync w-4 h-4">
                                খাতার <?= e($money($lp)) ?> বইয়ে বসান
                            </label>
                        <?php else: ?>
                            <!-- ⚠️ ইনপুট ও চিপ একটাই মোড়কে — মোবাইল কার্ড-লেআউটে td ফ্লেক্স হয়ে যায়,
                                 আলাদা দুটো সন্তান থাকলে চিপগুলো ডান পাশে সরু কলামে চেপে যেত -->
                            <div style="flex:1;min-width:0">
                            <input type="number" step="0.01" min="0" name="amt[<?= $rid ?>]" class="ifx-amt w-full px-3 py-2 rounded-xl border border-gray-200 text-sm"
                                   style="min-width:0" placeholder="টাকা" inputmode="decimal">
                            <div class="flex flex-wrap gap-1 mt-1">
                                <?php if (isset($ledgerChip[$rid])): ?>
                                    <button type="button" onclick="ifxSet(this, <?= (float) $ledgerChip[$rid][1] ?>)" class="text-xs px-2 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold"><?= e($ledgerChip[$rid][0]) ?> <?= e($money($ledgerChip[$rid][1])) ?></button>
                                <?php elseif ($regFee > 0): ?>
                                    <button type="button" onclick="ifxSet(this, <?= (float) $regFee ?>)" class="text-xs px-2 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold">রেজি ফি <?= e($money($regFee)) ?></button>
                                <?php endif; ?>
                                <?php if ($booked > 0): ?>
                                    <button type="button" onclick="ifxSet(this, <?= (float) $booked ?>)" class="text-xs px-2 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold">বইয়েরটাই ঠিক <?= e($money($booked)) ?></button>
                                <?php endif; ?>
                                <button type="button" onclick="ifxSet(this, 0)" class="text-xs px-2 py-1 rounded-lg bg-gray-100 text-gray-600 font-semibold">৳0</button>
                            </div>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-wrap gap-3 items-center">
        <button type="submit" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold">💾 যা লিখেছি সেভ করুন</button>
        <p class="text-gray-500 text-xs">খালি ঘরের অর্ডারে কিছুই বদলাবে না।</p>
    </div>
</form>

<?php if ($pages > 1): ?>
<div class="flex flex-wrap gap-2 mt-6">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
        <a href="<?= e(ifx_url(['page' => $i > 1 ? $i : null])) ?>"
           class="px-3 py-2 rounded-xl text-sm font-semibold <?= $i === $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<p class="text-gray-400 text-xs mt-4">মোট <?= (int) $total ?> টি — দেখানো হচ্ছে <?= count($rows) ?> টি।</p>

<?php endif; /* $rows */ ?>
<?php endif; /* $ready */ ?>

<script>
// ইনপুটের নিচের চিপ → ঐ সারির ঘরে বসায়
function ifxSet(btn, value) {
    var cell = btn.closest('td');
    var input = cell ? cell.querySelector('.ifx-amt') : null;
    if (input) { input.value = value; }
}
function ifxBulkFill() {
    var v = document.getElementById('ifxBulk').value.trim();
    if (v === '') { return; }
    document.querySelectorAll('#ifxForm .ifx-amt').forEach(function (el) {
        if (el.value.trim() === '') { el.value = v; }   // 🔴 নিজে লেখা ঘর কখনো বদলায় না
    });
}
function ifxClear() {
    document.querySelectorAll('#ifxForm .ifx-amt').forEach(function (el) { el.value = ''; });
}
// সেভের আগে সবসময় নিশ্চিতকরণ — কয়টা সারি ও মোট কত টাকা বসছে সেটা দেখিয়ে (টাকা জড়িত)
function ifxConfirm(form) {
    var n = 0, sum = 0, syncs = 0;
    form.querySelectorAll('.ifx-amt').forEach(function (el) {
        var t = el.value.trim();
        if (t !== '') { n++; sum += parseFloat(t) || 0; }
    });
    form.querySelectorAll('.ifx-sync:checked').forEach(function () { syncs++; });
    if (n === 0 && syncs === 0) {
        showConfirmModal('কোনো ঘরে কিছু লেখা হয়নি — সেভ করার মতো কিছু নেই।', function () {}, 'কিছু বদলাবে না');
        return false;
    }
    var msg = '';
    if (n > 0) { msg += n + ' টি অর্ডারে জমা বসবে, মোট ৳' + sum.toLocaleString('en-US') + '। '; }
    if (syncs > 0) { msg += syncs + ' টির বই খাতার সাথে মেলানো হবে। '; }
    msg += 'বইয়ের আয় এই অনুযায়ী বদলে যাবে। ঠিক আছে?';
    return confirmSubmit(form, msg, 'টাকার হিসাব বদলাচ্ছে');
}
// সার্চ বক্সে টাইপ করলে ৫০০ms পর নিজেই সাবমিট (registrations.php-এর প্যাটার্ন)
(function () {
    var box = document.getElementById('ifxSearch');
    if (!box) { return; }
    var timer = null;
    box.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { document.getElementById('ifxFilterForm').submit(); }, 500);
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
