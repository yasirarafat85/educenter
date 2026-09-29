<?php
// ── 👥 গ্রুপ মেলানো (২০২৬-০৯-২৯, ইউজারের চাওয়া)
//
// "কোর্সের মেসেঞ্জার গ্রুপে ১০ জন আছে, রেজিস্ট্রেশন করেছে ৮ জন — কে করেনি সেটা মেলাতে চাই।"
//
// অ্যাডমিন গ্রুপের সদস্য-তালিকা **পেস্ট** করেন (অথবা স্ক্রিনশট দিলে ব্রাউজারেই OCR চলে),
// পেজটা সেই নামগুলো ঐ কোর্স-ব্যাচের রেজিস্ট্রেশনের **ফেসবুক আইডি নাম** ও নামের সাথে মেলায়।
//
// 🔴🔴 এই পেজ **কিছুই লেখে না** — নিছক রিপোর্ট (ইউজারের সিদ্ধান্ত)। গ্রুপের টিক/স্ট্যাটাস
//    কিছুই বদলায় না; যা দেখানো হয় তা দেখে অ্যাডমিন নিজে সিদ্ধান্ত নেন।
// 🔴 OCR **সার্ভারে নয়, ব্রাউজারে** (tesseract.js, নিজেদের সার্ভারে রাখা) — ছবিটা কোথাও
//    আপলোডই হয় না, শুধু পড়া লেখাটা টেক্সট-বাক্সে বসে। তাই কোনো ফাইল সংরক্ষণ/পরিষ্কারের
//    ঝামেলা নেই, আর শিক্ষার্থীদের নাম বাইরের কোনো সার্ভিসে যায় না।
// ⚠️ POST বলে কেন্দ্রীয় গার্ড `orders`-এ **edit** cap চায় — শুধু-দেখার মডারেটর এটা
//    চালাতে পারবেন না (`account-preview.php`-এর মতোই সীমাবদ্ধতা, ইচ্ছাকৃত)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/group-match.php';
admin_require_login();

$db = get_db();
$pageTitle = 'গ্রুপ মেলানো';

const GM_MAX_CHARS = 20000;   // ~৬০০ নাম — এর বেশি পেস্ট হলে ছেঁটে নেওয়া হয়

// ── কোর্স-ব্যাচের তালিকা (রেজিস্ট্রেশনের স্ন্যাপশট থেকে — কোর্স রিনেম/ডিলিট হলেও অপশন থাকে,
//    course-data.php-এর পিকারের মতোই)
$batchOptions = [];
try {
    $batchOptions = $db->query(
        "SELECT item_id, item_title, COALESCE(batch, '') AS batch, COUNT(*) AS c
         FROM registrations
         WHERE type = 'course' AND status <> 'cancelled'
         GROUP BY item_id, item_title, COALESCE(batch, '')
         ORDER BY item_title, batch"
    )->fetchAll();
} catch (PDOException $ex) {
    $batchOptions = [];
}

// নির্বাচিত ব্যাচ — মান "<item_id>|<batch>" (ব্যাচের নামে `|` থাকলেও যাতে না ভাঙে, limit 2)
$sel       = (string) ($_GET['b'] ?? '');
$selItemId = 0;
$selBatch  = '';
if ($sel !== '') {
    $parts     = explode('|', $sel, 2);
    $selItemId = (int) $parts[0];
    $selBatch  = (string) ($parts[1] ?? '');
}

// ── ঐ ব্যাচের রেজিস্ট্রেশন
// 🔴 বাতিল করা রেজিস্ট্রেশন গোনা হয় না — "রেজিস্ট্রেশন করেছে" প্রশ্নের উত্তরে ওটা "না"।
//    ফলে বাতিল হওয়া কেউ গ্রুপে থাকলে "রেজিস্ট্রেশন পাইনি" তালিকাতেই আসবেন (যা কাম্য)।
$regs      = [];
$selLabel  = '';
if ($selItemId > 0) {
    try {
        $st = $db->prepare(
            "SELECT id, customer_name, phone, facebook_id, father_mobile, item_title, batch, status
             FROM registrations
             WHERE type = 'course' AND item_id = :i AND COALESCE(batch, '') = :b AND status <> 'cancelled'
             ORDER BY customer_name"
        );
        $st->execute(['i' => $selItemId, 'b' => $selBatch]);
        $regs = $st->fetchAll();
    } catch (PDOException $ex) {
        $regs = [];
    }
    foreach ($batchOptions as $bo) {
        if ((int) $bo['item_id'] === $selItemId && (string) $bo['batch'] === $selBatch) {
            $selLabel = $bo['item_title'] . ($bo['batch'] !== '' ? ' — ' . $bo['batch'] : '');
            break;
        }
    }
}

function gm_url(string $b, array $extra = []): string
{
    $q = $b !== '' ? ['b' => $b] : [];
    $q = array_merge($q, array_filter($extra, fn($v) => $v !== null && $v !== ''));
    return 'group-match.php' . ($q ? '?' . http_build_query($q) : '');
}

// ── 🕘 হিস্ট্রি (২০২৬-০৯-২৯, ইউজারের চাওয়া: "মিলালাম সেটা একটা হিস্ট্রি থাকবে")
//
// 🔴 এই পাতা এখন **একটাই জিনিস লেখে — নিজের রিপোর্টের রেকর্ড** (`group_match_runs`)।
//    রেজিস্ট্রেশন · গ্রুপের টিক · স্ট্যাটাস · টাকার খাতা — কিচ্ছু আগের মতোই ছোঁয়া হয় না।
// 🔴 টেবিল না থাকলে (মাইগ্রেশন চালানো হয়নি) পুরো হিস্ট্রি **চুপচাপ বাদ যায়**, মেলানো
//    আগের মতোই চলে — এই কোডবেসের প্রতিষ্ঠিত প্যাটার্ন (course_media/user-auth-এর মতো)।
const GM_KEEP_RUNS = 100;   // এর বেশি পুরনো রান ছেঁটে ফেলা হয়

function gm_history_ready(?PDO $db): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try {
        ($db ?? get_db())->query('SELECT id FROM group_match_runs LIMIT 0');
        $ok = true;
    } catch (PDOException $ex) {
        $ok = false;
    }
    return $ok;
}

/**
 * একটা মেলানো সংরক্ষণ করে। ব্যর্থ হলে চুপচাপ false — রিপোর্ট দেখানো কখনো আটকায় না।
 *
 * 🔴 হুবহু একই লেখা পরপর দুইবার মেলালে **নতুন সারি বসে না**, আগেরটার সময় হালনাগাদ হয় —
 *    OCR-এর লেখা ঠিক করতে করতে কেউ ৫ বার চাপলে হিস্ট্রি আবর্জনায় ভরে যেত।
 */
function gm_history_save(PDO $db, array $ctx, string $raw, array $snap): bool
{
    if (!gm_history_ready($db)) { return false; }
    try {
        $st = $db->prepare(
            'SELECT id FROM group_match_runs
             WHERE item_id = :i AND batch = :b AND raw_names = :r
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute(['i' => $ctx['item_id'], 'b' => $ctx['batch'], 'r' => $raw]);
        $dupe = (int) ($st->fetchColumn() ?: 0);
        $json = json_encode($snap, JSON_UNESCAPED_UNICODE);
        if ($json === false) { return false; }

        if ($dupe > 0) {
            $db->prepare('UPDATE group_match_runs SET result_json = :j, n_group = :g, n_matched = :m,
                          n_unmatched = :u, n_missing = :x, created_at = NOW() WHERE id = :id')
               ->execute([
                   'j' => $json, 'g' => $snap['stats']['group'], 'm' => $snap['stats']['matched'],
                   'u' => $snap['stats']['unmatched'], 'x' => $snap['stats']['missing'], 'id' => $dupe,
               ]);
            return true;
        }

        $db->prepare(
            'INSERT INTO group_match_runs
             (item_id, item_title, batch, admin_id, admin_name, raw_names, result_json,
              n_group, n_matched, n_unmatched, n_missing)
             VALUES (:i, :t, :b, :aid, :an, :r, :j, :g, :m, :u, :x)'
        )->execute([
            'i' => $ctx['item_id'], 't' => $ctx['item_title'], 'b' => $ctx['batch'],
            'aid' => $ctx['admin_id'] ?: null, 'an' => $ctx['admin_name'],
            'r' => $raw, 'j' => $json,
            'g' => $snap['stats']['group'], 'm' => $snap['stats']['matched'],
            'u' => $snap['stats']['unmatched'], 'x' => $snap['stats']['missing'],
        ]);

        // পুরনো রান ছাঁটাই — সবচেয়ে নতুন GM_KEEP_RUNS টা রেখে বাকিগুলো
        // (🔴 LIMIT ইচ্ছাকৃতভাবে int-কাস্ট করে সরাসরি SQL-এ — কোডে লেখা ধ্রুবক,
        //  আর MySQL-এর DELETE ... LIMIT-এ প্লেসহোল্ডার চলে না)
        $keep = (int) GM_KEEP_RUNS;
        $cut  = (int) ($db->query("SELECT id FROM group_match_runs ORDER BY id DESC LIMIT 1 OFFSET {$keep}")->fetchColumn() ?: 0);
        if ($cut > 0) {
            $db->prepare('DELETE FROM group_match_runs WHERE id <= :c')->execute(['c' => $cut]);
        }
        return true;
    } catch (PDOException $ex) {
        return false;
    }
}

// ── POST: মেলানো (ফলাফল এখানেই দেখানো হয়, redirect নেই — রিপোর্ট POST থেকেই তৈরি)
$raw    = '';
$result = null;
$parsed = null;
$snap   = null;
$saved  = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'scan') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
    } else {
        $raw    = mb_substr((string) ($_POST['names'] ?? ''), 0, GM_MAX_CHARS);
        $parsed = gm_parse_names($raw);
        $result = gm_match_names($parsed['names'], $regs);
        $snap   = gm_snapshot($result, $parsed);
        if ($selItemId > 0 && trim($raw) !== '') {
            // 🔴 কে চালাচ্ছেন সেটা **সেশন থেকে** — এই কোডবেসে `admin_current()` বলে
            //    কোনো ফাংশন নেই (auth.php-এ আছে `current_admin_name()` আর
            //    `$_SESSION['admin_id']`)। একবার ভুল করে ওটা ডাকা হয়েছিল, লাইভে
            //    "মিলিয়ে দেখুন" চাপলেই HTTP 500 হতো।
            $saved = gm_history_save($db, [
                'item_id'    => $selItemId,
                'item_title' => $selLabel !== '' ? explode(' — ', $selLabel)[0] : '',
                'batch'      => $selBatch,
                'admin_id'   => (int) ($_SESSION['admin_id'] ?? 0),
                'admin_name' => (string) ($_SESSION['admin_name'] ?? ($_SESSION['admin_username'] ?? '')),
            ], $raw, $snap);
        }
    }
}

// ── 🗑 পুরনো রান মোছা (action-মার্কার `delete` — কেন্দ্রীয় গার্ডে delete cap লাগে)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
    } else {
        $rid = (int) ($_POST['run_id'] ?? 0);
        try {
            $db->prepare('DELETE FROM group_match_runs WHERE id = :i')->execute(['i' => $rid]);
            set_flash('success', 'রেকর্ডটি মুছে ফেলা হয়েছে।');
        } catch (PDOException $ex) {
            set_flash('error', 'মোছা গেল না।');
        }
    }
    header('Location: ' . gm_url($sel));
    exit;
}

// ── 🔍 একটা পুরনো রান দেখা (?run=<id>)
$viewRun = null;
$viewSnap = null;
if (($_GET['run'] ?? '') !== '' && gm_history_ready($db)) {
    try {
        $st = $db->prepare('SELECT * FROM group_match_runs WHERE id = :i');
        $st->execute(['i' => (int) $_GET['run']]);
        $viewRun = $st->fetch() ?: null;
        if ($viewRun) {
            $viewSnap = json_decode((string) $viewRun['result_json'], true);
            if (!is_array($viewSnap)) { $viewSnap = null; }
        }
    } catch (PDOException $ex) {
        $viewRun = null;
    }
}

// ── 📜 এই ব্যাচের আগের মেলানোগুলো
$runs = [];
if ($selItemId > 0 && gm_history_ready($db)) {
    try {
        $st = $db->prepare(
            'SELECT id, admin_name, n_group, n_matched, n_unmatched, n_missing, created_at
             FROM group_match_runs WHERE item_id = :i AND batch = :b
             ORDER BY id DESC LIMIT 20'
        );
        $st->execute(['i' => $selItemId, 'b' => $selBatch]);
        $runs = $st->fetchAll();
    } catch (PDOException $ex) {
        $runs = [];
    }
}

$ocrVer = @filemtime(__DIR__ . '/assets/ocr/tesseract.min.js') ?: time();
require __DIR__ . '/includes/layout-top.php';
?>

<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <p class="text-gray-700 text-sm leading-relaxed">
        কোর্সের <b>মেসেঞ্জার/ফেসবুক গ্রুপে কারা আছেন</b> আর <b>কারা রেজিস্ট্রেশন করেছেন</b> — দুটো মিলিয়ে দেখার পাতা।
        গ্রুপের সদস্য-তালিকার নামগুলো নিচের বাক্সে বসান, তারপর <b>“মিলিয়ে দেখুন”</b>।
    </p>
    <p class="text-gray-500 text-xs mt-2">
        🔴 এখানে <b>কিছুই সংরক্ষণ হয় না</b> — শুধু দেখানো হয়। মিল খোঁজা হয় রেজিস্ট্রেশনের
        <b>“ফেসবুক আইডি নাম”</b> ও শিক্ষার্থী/অভিভাবকের নামের সাথে। বাতিল করা রেজিস্ট্রেশন গোনা হয় না।
    </p>
</div>

<!-- ধাপ ১: কোন কোর্স-ব্যাচ (সার্ভার-সাইড; id গার্ড লিস্টে আছে বলে অটো-সার্চ বক্স বসে না) -->
<form method="get" id="gmPickForm" class="bg-white rounded-2xl shadow p-4 mb-6">
    <label class="block text-gray-500 text-xs font-semibold mb-1">ধাপ ১ — কোন কোর্সের গ্রুপ?</label>
    <select name="b" onchange="document.getElementById('gmPickForm').submit()"
            class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:0">
        <option value="">— কোর্স ও ব্যাচ বাছুন —</option>
        <?php foreach ($batchOptions as $bo):
            $val = $bo['item_id'] . '|' . $bo['batch']; ?>
            <option value="<?= e($val) ?>" <?= $val === $sel ? 'selected' : '' ?>>
                <?= e($bo['item_title']) ?><?= $bo['batch'] !== '' ? ' — ' . e($bo['batch']) : '' ?>
                (<?= (int) $bo['c'] ?> জন)
            </option>
        <?php endforeach; ?>
    </select>
    <?php if (!$batchOptions): ?>
        <p class="text-gray-400 text-xs mt-2">এখনো কোনো কোর্স রেজিস্ট্রেশন নেই।</p>
    <?php endif; ?>
</form>

<?php if ($selItemId > 0): ?>

<!-- ধাপ ২: গ্রুপের নাম -->
<form method="post" action="<?= e(gm_url($sel)) ?>&amp;action=scan" id="gmScanForm">
    <?= csrf_field() ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4">
        <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
            <label for="gmNames" class="block text-gray-500 text-xs font-semibold">
                ধাপ ২ — গ্রুপের সদস্যদের নাম (প্রতি লাইনে একটা)
            </label>
            <span class="text-xs text-gray-400"><?= e($selLabel) ?> · <?= count($regs) ?> টি রেজিস্ট্রেশন</span>
        </div>

        <?php
        // পুরনো রান খুলে থাকলে ঐ লেখাটাই বাক্সে বসে — "আবার মিলিয়ে দেখুন" এক ক্লিকে
        $gmBoxText = $raw !== '' ? $raw : (string) ($viewRun['raw_names'] ?? '');
        ?>
        <textarea name="names" id="gmNames" rows="9" placeholder="AyeSha Siddika&#10;Elora Parvin&#10;Israt Jahan"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:0"><?= e($gmBoxText) ?></textarea>
        <p class="text-gray-400 text-xs mt-1">
            “Joined with invite link” / “Added by you” জাতীয় লাইন থাকলেও সমস্যা নেই — নিজে থেকেই বাদ যাবে।
        </p>

        <!-- ছবি থেকে পড়া (ঐচ্ছিক) — ব্রাউজারেই চলে, ছবি কোথাও যায় না -->
        <div class="mt-3 pt-3 border-t">
            <label class="inline-block bg-gray-100 text-gray-700 px-4 py-2 rounded-xl text-sm font-semibold cursor-pointer">
                🖼️ স্ক্রিনশট থেকে পড়ুন
                <input type="file" id="gmShots" accept="image/*" multiple class="hidden">
            </label>
            <span id="gmOcrMsg" class="text-xs text-gray-500 ml-1"></span>
            <p class="text-gray-400 text-xs mt-2">
                ছবিটা <b>কোথাও আপলোড হয় না</b>, আপনার ব্রাউজারেই পড়া হয় (প্রথমবার ~৭ MB নামবে, পরে আর নামবে না)।
                ইংরেজি ও <b>বাংলা</b> দুই রকম নামই পড়ে। প্রোফাইল ছবির অংশটা নিজে থেকেই বাদ যায়।
                ⚠️ তবু <b>পড়ার পর লেখাটা একবার চোখ বুলিয়ে নিন</b> — কিছু ভুল এলে ঠিক করে দিন।
            </p>
        </div>
    </div>
    <button type="submit" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold">🔍 মিলিয়ে দেখুন</button>
</form>

<?php endif; ?>

<?php // ── 🕘 আগের মেলানোগুলো ─────────────────────────────────────────────── ?>
<?php if ($selItemId > 0 && !gm_history_ready($db)): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-6" style="box-shadow:inset 4px 0 0 0 #d97706">
        <p class="text-gray-700 text-sm">
            🕘 <b>হিস্ট্রি এখনো চালু হয়নি</b> — মেলানোর রেকর্ড জমা রাখতে
            <code class="bg-gray-100 px-1 rounded">database/migrate-group-match-runs.sql</code>
            ফাইলের SQL একবার phpMyAdmin-এ চালাতে হবে (লাইভ ও লোকাল দুই জায়গায়)।
            ততক্ষণ মেলানো আগের মতোই কাজ করবে, শুধু জমা থাকবে না।
        </p>
    </div>
<?php elseif ($runs): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-6">
        <p class="font-bold text-gray-800 text-sm mb-1">🕘 আগে যতবার মিলিয়েছেন (<?= count($runs) ?>)</p>
        <p class="text-gray-500 text-xs mb-3">“দেখুন” চাপলে সেদিন কী নাম দিয়েছিলেন আর কী পাওয়া গিয়েছিল — দুটোই দেখা যাবে।</p>
        <?php foreach ($runs as $r): ?>
            <div class="flex flex-wrap items-center gap-2 py-2 border-b last:border-0">
                <span class="text-gray-800 text-sm font-semibold"><?= e(date('d M Y, g:i a', strtotime((string) $r['created_at']))) ?></span>
                <?php if (trim((string) $r['admin_name']) !== ''): ?>
                    <span class="text-gray-400 text-xs"><?= e($r['admin_name']) ?></span>
                <?php endif; ?>
                <span class="inline-block px-2 py-1 rounded-lg text-xs bg-gray-100 text-gray-600">গ্রুপে <?= (int) $r['n_group'] ?></span>
                <span class="inline-block px-2 py-1 rounded-lg text-xs bg-green-100 text-green-800">মিলেছে <?= (int) $r['n_matched'] ?></span>
                <?php if ((int) $r['n_unmatched'] > 0): ?>
                    <span class="inline-block px-2 py-1 rounded-lg text-xs bg-red-100 text-red-700">পাইনি <?= (int) $r['n_unmatched'] ?></span>
                <?php endif; ?>
                <span class="flex-1"></span>
                <a href="<?= e(gm_url($sel, ['run' => (int) $r['id']])) ?>" class="text-indigo-600 font-semibold text-sm">দেখুন</a>
                <?php if (admin_can('orders', 'delete') || admin_can('parcel', 'delete')): ?>
                    <form method="post" action="<?= e(gm_url($sel)) ?>&amp;action=delete" class="inline"
                          onsubmit="return confirmSubmit(this, 'এই রেকর্ডটি মুছে ফেলবেন?', 'মেলানোর এই ইতিহাসটা আর দেখা যাবে না। রেজিস্ট্রেশনের কোনো তথ্য বদলাবে না।');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="run_id" value="<?= (int) $r['id'] ?>">
                        <button type="submit" class="text-red-600 text-sm">মুছুন</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php // ── পুরনো একটা রান দেখা হচ্ছে ──────────────────────────────────────── ?>
<?php if ($viewRun && $viewSnap && $result === null): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4" style="box-shadow:inset 4px 0 0 0 #4f46e5">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-gray-800">🕘 <?= e(date('d M Y, g:i a', strtotime((string) $viewRun['created_at']))) ?> — এই মেলানোটা</span>
            <span class="text-gray-500 text-xs"><?= e($viewRun['item_title']) ?><?= trim((string) $viewRun['batch']) !== '' ? ' — ' . e($viewRun['batch']) : '' ?></span>
            <span class="flex-1"></span>
            <a href="<?= e(gm_url($sel)) ?>" class="text-indigo-600 text-sm font-semibold">✕ বন্ধ করুন</a>
        </div>
        <p class="text-gray-500 text-xs mt-1">
            সেদিন যে নামগুলো দিয়েছিলেন সেগুলো <b>উপরের বাক্সেই বসানো আছে</b> — চাইলে “মিলিয়ে দেখুন” চেপে
            <b>আজকের রেজিস্ট্রেশন দিয়ে আবার</b> মিলিয়ে নিতে পারেন।
        </p>
    </div>
<?php endif; ?>

<?php
// 🔴 পুরনো রান আর এইমাত্রের মেলানো — **একই মার্কআপ** দিয়ে দেখানো হয়, শুধু উৎস আলাদা।
//    (আলাদা টেমপ্লেট বানালে সময়ের সাথে দুটো আলাদা হয়ে যেত।)
$showSnap = $snap ?? (($viewRun && $result === null) ? $viewSnap : null);
?>
<?php if ($showSnap !== null): ?>
<?php
$s = $showSnap['stats'];
// 🔴 “গ্রুপে” = **আলাদা নাম**, লাইন-সংখ্যা নয় (একই নাম দুইবার থাকলে একটাই কার্ড হয়,
//    তার ভেতরে “গ্রুপে ২ বার” লেখা থাকে) — লেবেলটা তাই স্পষ্ট করে লেখা।
$cards = [
    ['👥 গ্রুপে পাওয়া নাম',   $s['group'],     'text-indigo-600'],
    ['✅ রেজিস্ট্রেশন মিলেছে', $s['matched'],   'text-green-600'],
    ['⚠️ রেজিস্ট্রেশন পাইনি',  $s['unmatched'], 'text-red-600'],
    ['📋 গ্রুপে পাইনি',       $s['missing'],   'text-amber-600'],
];
// একই নাম একাধিকবার থাকলে লাইন-সংখ্যা আর নাম-সংখ্যা আলাদা হয় — সেটা বলে দেওয়া হয়
$gmTotalLines = 0;
foreach ($showSnap['entries'] as $pn) { $gmTotalLines += (int) $pn['count']; }

// 🔴 স্ন্যাপশটে ফোন নম্বর রাখা হয় না (গোপনীয়তা) — তাই id দিয়ে **এখনকার** ফোন তোলা হয়,
//    এক কোয়েরিতে। পুরনো রানে কেউ ডিলিট হয়ে থাকলে শুধু নামটাই দেখাবে, ভাঙবে না।
$gmPhones = [];
$gmIds = [];
foreach ($showSnap['entries'] as $en) {
    foreach ($en['matches'] as $mm) { $gmIds[] = (int) $mm['id']; }
}
foreach ($showSnap['missing'] as $ms) { $gmIds[] = (int) $ms['id']; }
$gmIds = array_values(array_unique(array_filter($gmIds)));
if ($gmIds) {
    try {
        $in = implode(',', array_map('intval', $gmIds));   // কোড-নিয়ন্ত্রিত int, ইউজার-ইনপুট নয়
        foreach ($db->query("SELECT id, phone, father_mobile FROM registrations WHERE id IN ($in)") as $pr) {
            $gmPhones[(int) $pr['id']] = $pr;
        }
    } catch (PDOException $ex) { $gmPhones = []; }
}
?>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 mb-6">
    <?php foreach ($cards as $c): ?>
        <div class="bg-white rounded-2xl shadow p-5">
            <div class="text-3xl font-black <?= $c[2] ?>"><?= (int) $c[1] ?></div>
            <p class="text-gray-500 text-sm mt-1"><?= $c[0] ?></p>
        </div>
    <?php endforeach; ?>
</div>
<?php if ($showSnap['unreadable']): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4" style="box-shadow:inset 4px 0 0 0 #d97706">
        <p class="font-bold text-gray-800 text-sm">⚠️ এই লেখাগুলো ঠিকমতো পড়া যায়নি (<?= count($showSnap['unreadable']) ?>)</p>
        <p class="text-gray-500 text-xs mt-1">
            এগুলো নাম হিসেবে ধরা হয়নি। ছবিটা আরও বড় করে তুলে আবার চেষ্টা করুন,
            অথবা নামগুলো উপরের বাক্সে নিজে লিখে দিন।
        </p>
        <div class="mt-2 flex flex-wrap gap-2">
            <?php foreach ($showSnap['unreadable'] as $u): ?>
                <span class="inline-block px-2 py-1 rounded-lg text-xs bg-amber-100 text-amber-800"><?= e($u) ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<p class="text-gray-400 text-xs mb-6">
    <?php if ($gmTotalLines !== $s['group']): ?>
        গ্রুপের তালিকায় <?= (int) $gmTotalLines ?> টি নাম-লাইন, আলাদা নাম <?= (int) $s['group'] ?> টি।
    <?php endif; ?>
    <?php if ($showSnap['dropped'] > 0): ?>
        <?= (int) $showSnap['dropped'] ?> টি লাইন নাম নয় বলে বাদ দেওয়া হয়েছে (“যোগ দিয়েছেন”, সময়, সংখ্যা ইত্যাদি)।
    <?php endif; ?>
</p>

<?php
// এক সদস্যের কার্ড — মিল পেলে সবুজ, না পেলে লাল
//
// 🔴 রেজিস্ট্রেশনের লিংক **নতুন ট্যাবে** খোলে (`return=` বয়ে নেওয়া হয় না) — এই পাতার
//    ফলাফল POST থেকে তৈরি, ফিরে এলে পেস্ট করা নামগুলো হারিয়ে যেত আর খালি পিকার দেখাত।
//    (তাছাড়া `safe_return_url()` শুধু registrations.php/course-data.php মানে।)
function gm_card(array $entry): void
{
    $has = (bool) $entry['matches'];
    ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-3" style="box-shadow:inset 4px 0 0 0 <?= $has ? '#16a34a' : '#dc2626' ?>">
        <div class="flex flex-wrap items-center gap-2">
            <span class="font-bold text-gray-800"><?= e($entry['name']) ?></span>
            <?php if ($entry['count'] > 1): ?>
                <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-gray-100 text-gray-600">গ্রুপে <?= (int) $entry['count'] ?> বার</span>
            <?php endif; ?>
            <?php if (!$has): ?>
                <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-red-100 text-red-700">রেজিস্ট্রেশন পাইনি</span>
            <?php endif; ?>
        </div>

        <?php foreach ($entry['matches'] as $m):
            [$lbl, $cls] = gm_how_label($m['how']);
            // ফোন স্ন্যাপশটে নেই — id দিয়ে এখনকারটা (উপরে এক কোয়েরিতে তোলা)
            $ph = $GLOBALS['gmPhones'][(int) $m['id']] ?? null; ?>
            <div class="mt-2 pl-3" style="border-left:1px solid rgb(var(--c-border))">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="registrations.php?action=view&amp;id=<?= (int) $m['id'] ?>" target="_blank" rel="noopener"
                       class="text-indigo-600 font-semibold text-sm"><?= e($m['name']) ?></a>
                    <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold <?= $cls ?>"><?= e($lbl) ?></span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    <?php if (trim((string) $m['fb']) !== ''): ?>ফেসবুক: <?= e($m['fb']) ?> · <?php endif; ?>
                    <?php if ($ph): ?>
                        <a href="tel:<?= e($ph['phone']) ?>"><?= e($ph['phone']) ?></a>
                        <?php if (trim((string) ($ph['father_mobile'] ?? '')) !== ''): ?> · বাবা: <?= e($ph['father_mobile']) ?><?php endif; ?>
                    <?php else: ?>
                        <span class="text-gray-400">(এই রেজিস্ট্রেশনটি আর নেই)</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($entry['notes'] as $note): ?>
            <p class="text-xs bg-amber-50 text-amber-800 rounded-xl px-3 py-2 mt-2">⚠️ <?= e($note) ?></p>
        <?php endforeach; ?>
    </div>
    <?php
}

$matched = array_values(array_filter($showSnap['entries'], fn($x) => (bool) $x['matches']));
$noReg   = array_values(array_filter($showSnap['entries'], fn($x) => !$x['matches']));
?>

<?php if ($noReg): ?>
    <h3 class="font-bold text-gray-800 mb-2">⚠️ গ্রুপে আছেন, রেজিস্ট্রেশন পাইনি (<?= count($noReg) ?>)</h3>
    <p class="text-gray-500 text-xs mb-3">নামের বানান আলাদা হলেও এমন দেখাতে পারে — নিচের “গ্রুপে পাইনি” তালিকার সাথে মিলিয়ে দেখুন।</p>
    <?php foreach ($noReg as $entry) { gm_card($entry); } ?>
<?php endif; ?>

<?php if ($showSnap['missing']): ?>
    <h3 class="font-bold text-gray-800 mt-6 mb-2">📋 রেজিস্ট্রেশন আছে, গ্রুপে পাইনি (<?= count($showSnap['missing']) ?>)</h3>
    <p class="text-gray-500 text-xs mb-3">এঁদের গ্রুপে যোগ করা বাকি থাকতে পারে (অথবা গ্রুপে নাম আলাদা)।</p>
    <div class="bg-white rounded-2xl shadow p-4 mb-3">
        <?php foreach ($showSnap['missing'] as $r): $ph = $gmPhones[(int) $r['id']] ?? null; ?>
            <div class="py-2 border-b last:border-0">
                <a href="registrations.php?action=view&amp;id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"
                   class="text-indigo-600 font-semibold text-sm"><?= e($r['name']) ?></a>
                <div class="text-xs text-gray-500 mt-1">
                    <?php if (trim((string) $r['fb']) !== ''): ?>ফেসবুক: <?= e($r['fb']) ?> · <?php else: ?>
                        <span class="text-amber-600">ফেসবুক আইডি নাম দেওয়া নেই</span> ·
                    <?php endif; ?>
                    <?php if ($ph): ?><a href="tel:<?= e($ph['phone']) ?>"><?= e($ph['phone']) ?></a>
                    <?php else: ?><span class="text-gray-400">(এই রেজিস্ট্রেশনটি আর নেই)</span><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($matched): ?>
    <h3 class="font-bold text-gray-800 mt-6 mb-2">✅ গ্রুপেও আছেন, রেজিস্ট্রেশনও আছে (<?= count($matched) ?>)</h3>
    <?php foreach ($matched as $entry) { gm_card($entry); } ?>
<?php endif; ?>

<?php if (!$showSnap['entries']): ?>
    <div class="empty-state">
        <div class="empty-ic">📝</div>
        <p class="font-bold text-gray-700">কোনো নাম পাওয়া যায়নি</p>
        <p class="text-gray-500 text-sm mt-1">বাক্সে গ্রুপের সদস্যদের নাম বসিয়ে আবার চেষ্টা করুন।</p>
    </div>
<?php endif; ?>
<?php endif; /* $showSnap */ ?>

<script>
// ── ছবি থেকে নাম পড়া — সম্পূর্ণ ব্রাউজারে (tesseract.js, নিজেদের সার্ভারে রাখা)
// 🔴 লাইব্রেরিটা **শুধু ক্লিক করলে** নামে (~৭ MB) — নাহলে প্রতিটা পেজ-লোড ভারী হতো।
(function () {
    var input = document.getElementById('gmShots');
    if (!input) { return; }
    var msg = document.getElementById('gmOcrMsg');
    var box = document.getElementById('gmNames');
    var base = 'assets/ocr/';
    var v = '<?= (int) $ocrVer ?>';
    var busy = false;

    function say(t) { msg.textContent = t; }

    function loadLib() {
        return new Promise(function (res, rej) {
            if (window.Tesseract) { return res(); }
            var s = document.createElement('script');
            s.src = base + 'tesseract.min.js?v=' + v;
            s.onload = function () { res(); };
            s.onerror = function () { rej(new Error('লাইব্রেরি লোড হয়নি')); };
            document.head.appendChild(s);
        });
    }

    // 🔴 প্রোফাইল ছবির কলাম কোথায় শেষ — বাঁ পাশ থেকে মেপে বের করা (২০২৬-০৯-২৯)
    //
    // কেন দরকার: তালিকার বাঁ পাশে গোল প্রোফাইল ছবি থাকে, OCR সেগুলোকেও অক্ষর ভেবে
    // পড়ে ফেলে আর নামের সামনে জঞ্জাল জোড়ে (`LU Nahida Akter`, `9? Yasir Arafat`)।
    // ছবিগুলো **রঙিন**, আর লেখা প্রায় সাদাকালো — তাই কলাম ধরে "রঙের তীব্রতা" মেপে
    // যেখানে রঙ থেমে যায় সেখান থেকেই লেখা শুরু ধরা হয়।
    // 🔴 নিরাপত্তা: সর্বোচ্চ ২৫% পর্যন্ত কাটা যায়, আর স্পষ্ট সীমানা না পেলে কিছুই কাটে না
    //    (সাদাকালো/ছবিহীন স্ক্রিনশটে যেন নামই কেটে না যায়)।
    function avatarCut(x, w, h) {
        try {
            var d = x.getImageData(0, 0, w, h).data;
            var maxCut = Math.floor(w * 0.25);
            var colorful = new Array(maxCut).fill(0);
            var step = Math.max(1, Math.floor(h / 400));         // প্রতিটা সারি না মেপে নমুনা
            var rows = 0;
            for (var y = 0; y < h; y += step) {
                rows++;
                for (var cx = 0; cx < maxCut; cx++) {
                    var i = (y * w + cx) * 4;
                    var r = d[i], g = d[i + 1], b = d[i + 2];
                    if (Math.max(r, g, b) - Math.min(r, g, b) > 40) { colorful[cx]++; }
                }
            }
            if (!rows) { return 0; }
            // ডান দিক থেকে বাঁয়ে হেঁটে প্রথম যেখানে রঙ ৮%-এর বেশি — সেটাই ছবির শেষ প্রান্ত
            for (var cx2 = maxCut - 1; cx2 >= 0; cx2--) {
                if (colorful[cx2] / rows > 0.08) {
                    return Math.min(maxCut, cx2 + Math.round(w * 0.01));
                }
            }
        } catch (e) { /* ক্যানভাস পড়া না গেলে কাটা নয় */ }
        return 0;
    }

    // ছোট স্ক্রিনশটে লেখা ছোট থাকে — বড় করে সাদাকালো করলে পড়া অনেক ভালো হয়
    function prep(file) {
        return new Promise(function (res, rej) {
            var img = new Image();
            img.onload = function () {
                // ১ম ধাপ: আসল মাপে এঁকে প্রোফাইল-ছবির কলামটা মেপে নেওয়া
                var m = document.createElement('canvas');
                m.width = img.width; m.height = img.height;
                var mx = m.getContext('2d', { willReadFrequently: true });
                mx.drawImage(img, 0, 0);
                var cut = avatarCut(mx, img.width, img.height);
                var srcW = img.width - cut;

                // ২য় ধাপ: ছবির কলাম বাদ দিয়ে বাকিটা বড় করে সাদাকালো
                var scale = Math.min(3, Math.max(1, 1400 / srcW));
                var c = document.createElement('canvas');
                c.width = Math.round(srcW * scale);
                c.height = Math.round(img.height * scale);
                var x = c.getContext('2d', { willReadFrequently: true });
                x.fillStyle = '#fff';
                x.fillRect(0, 0, c.width, c.height);
                x.drawImage(img, cut, 0, srcW, img.height, 0, 0, c.width, c.height);
                try {
                    var d = x.getImageData(0, 0, c.width, c.height);
                    for (var i = 0; i < d.data.length; i += 4) {
                        var g = (d.data[i] * 0.3 + d.data[i + 1] * 0.59 + d.data[i + 2] * 0.11);
                        g = g < 110 ? 0 : (g > 165 ? 255 : g);   // হালকা কনট্রাস্ট
                        d.data[i] = d.data[i + 1] = d.data[i + 2] = g;
                    }
                    x.putImageData(d, 0, 0);
                } catch (e) { /* ক্যানভাস পড়া না গেলে আসল ছবিই যাবে */ }
                URL.revokeObjectURL(img.src);
                res(c);
            };
            img.onerror = function () { rej(new Error('ছবিটা পড়া গেল না')); };
            img.src = URL.createObjectURL(file);
        });
    }

    input.addEventListener('change', function () {
        var files = Array.prototype.slice.call(input.files || []);
        if (!files.length || busy) { return; }
        busy = true;
        say('লাইব্রেরি নামছে…');

        loadLib().then(function () {
            say('প্রস্তুত হচ্ছে…');
            // 🔴 'eng+ben' — বাংলা নামও পড়া হয় (২০২৬-০৯-২৯ সন্ধ্যা)। আগে শুধু 'eng'
            //    ছিল, তাতে "প্রকৌশলী তানজিন আরা" → "ACSA OAS Say" জাতীয় আবর্জনা আসত।
            //    প্রোফাইল-ছবির কলাম কাটা শুরু করার পর বাংলাও নিখুঁত পড়ে (আসল
            //    স্ক্রিনশটে যাচাই করা)। ben = tessdata 4.0.0_fast (~538 KB)।
            // oem 1 = LSTM — আমরা lstm-only core রেখেছি, তাই এটাই দিতে হবে
            return Tesseract.createWorker('eng+ben', 1, {
                workerPath: base + 'worker.min.js?v=' + v,
                corePath: base + 'core/tesseract-core-lstm.wasm.js?v=' + v,
                langPath: base + 'lang',
                logger: function (m) {
                    if (m.status === 'recognizing text') { say('পড়ছে… ' + Math.round(m.progress * 100) + '%'); }
                }
            });
        }).then(function (worker) {
            // PSM 4 = "একটাই কলামে নানা মাপের লেখা" — মেম্বার-তালিকার গঠন ঠিক এটাই
            // (ডিফল্ট PSM 3 পুরো পাতাকে অনুচ্ছেদ ভেবে লাইন জোড়া লাগিয়ে দিত)।
            var out = [];
            try { worker.setParameters({ tessedit_pageseg_mode: '4' }); } catch (e) { /* পুরনো ভার্সনে নেই */ }
            var step = files.reduce(function (chain, f, i) {
                return chain.then(function () {
                    say('ছবি ' + (i + 1) + '/' + files.length + ' পড়ছে…');
                    return prep(f).then(function (canvas) {
                        return worker.recognize(canvas);
                    }).then(function (r) {
                        out.push(((r && r.data && r.data.text) || '').trim());
                    });
                });
            }, Promise.resolve());

            return step.then(function () {
                return worker.terminate();
            }).then(function () {
                var text = out.join('\n').trim();
                if (text === '') {
                    say('কিছু পড়া গেল না — ছবিটা বড় করে আবার তুলুন, বা নাম পেস্ট করুন।');
                } else {
                    box.value = (box.value.trim() ? box.value.trim() + '\n' : '') + text;
                    say('✅ পড়া হয়েছে — লেখাটা একবার দেখে নিন, ভুল থাকলে ঠিক করুন।');
                }
            });
        }).catch(function (err) {
            say('⚠️ ছবি পড়া গেল না (' + (err && err.message ? err.message : 'অজানা সমস্যা') + ') — নাম পেস্ট করুন।');
        }).then(function () {
            busy = false;
            input.value = '';
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
