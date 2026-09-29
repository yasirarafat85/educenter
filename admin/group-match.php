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

// ── POST: মেলানো (কোনো কিছু সংরক্ষণ করা হয় না, তাই redirect-ও নেই — ফলাফল এখানেই দেখানো হয়)
$raw    = '';
$result = null;
$parsed = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'scan') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
    } else {
        $raw    = mb_substr((string) ($_POST['names'] ?? ''), 0, GM_MAX_CHARS);
        $parsed = gm_parse_names($raw);
        $result = gm_match_names($parsed['names'], $regs);
    }
}

function gm_url(string $b): string
{
    return 'group-match.php' . ($b !== '' ? '?b=' . rawurlencode($b) : '');
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

        <textarea name="names" id="gmNames" rows="9" placeholder="AyeSha Siddika&#10;Elora Parvin&#10;Israt Jahan"
                  class="w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:0"><?= e($raw) ?></textarea>
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
                ⚠️ <b>ইংরেজি নামে ভালো কাজ করে; বাংলা নাম হলে পেস্ট করাই ভরসা</b>। পড়ার পর লেখাটা নিজে একবার দেখে নিন।
            </p>
        </div>
    </div>
    <button type="submit" class="bg-indigo-600 text-white px-6 py-3 rounded-xl font-bold">🔍 মিলিয়ে দেখুন</button>
</form>

<?php endif; ?>

<?php if ($result !== null): ?>
<?php
$s = $result['stats'];
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
foreach ($parsed['names'] as $pn) { $gmTotalLines += (int) $pn['count']; }
?>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-6 mb-6">
    <?php foreach ($cards as $c): ?>
        <div class="bg-white rounded-2xl shadow p-5">
            <div class="text-3xl font-black <?= $c[2] ?>"><?= (int) $c[1] ?></div>
            <p class="text-gray-500 text-sm mt-1"><?= $c[0] ?></p>
        </div>
    <?php endforeach; ?>
</div>
<p class="text-gray-400 text-xs mb-6">
    <?php if ($gmTotalLines !== $s['group']): ?>
        গ্রুপের তালিকায় <?= (int) $gmTotalLines ?> টি নাম-লাইন, আলাদা নাম <?= (int) $s['group'] ?> টি।
    <?php endif; ?>
    <?php if ($parsed['dropped'] > 0): ?>
        <?= (int) $parsed['dropped'] ?> টি লাইন নাম নয় বলে বাদ দেওয়া হয়েছে (“যোগ দিয়েছেন”, সময়, সংখ্যা ইত্যাদি)।
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
            $r = $m['reg']; ?>
            <div class="mt-2 pl-3" style="border-left:1px solid rgb(var(--c-border))">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="registrations.php?action=view&amp;id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"
                       class="text-indigo-600 font-semibold text-sm"><?= e($r['customer_name']) ?></a>
                    <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold <?= $cls ?>"><?= e($lbl) ?></span>
                </div>
                <div class="text-xs text-gray-500 mt-1">
                    <?php if (trim((string) $r['facebook_id']) !== ''): ?>ফেসবুক: <?= e($r['facebook_id']) ?> · <?php endif; ?>
                    <a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a>
                    <?php if (trim((string) ($r['father_mobile'] ?? '')) !== ''): ?> · বাবা: <?= e($r['father_mobile']) ?><?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

        <?php foreach ($entry['notes'] as $note): ?>
            <p class="text-xs bg-amber-50 text-amber-800 rounded-xl px-3 py-2 mt-2">⚠️ <?= e($note) ?></p>
        <?php endforeach; ?>
    </div>
    <?php
}

$matched = array_values(array_filter($result['entries'], fn($x) => (bool) $x['matches']));
$noReg   = array_values(array_filter($result['entries'], fn($x) => !$x['matches']));
?>

<?php if ($noReg): ?>
    <h3 class="font-bold text-gray-800 mb-2">⚠️ গ্রুপে আছেন, রেজিস্ট্রেশন পাইনি (<?= count($noReg) ?>)</h3>
    <p class="text-gray-500 text-xs mb-3">নামের বানান আলাদা হলেও এমন দেখাতে পারে — নিচের “গ্রুপে পাইনি” তালিকার সাথে মিলিয়ে দেখুন।</p>
    <?php foreach ($noReg as $entry) { gm_card($entry); } ?>
<?php endif; ?>

<?php if ($result['missing']): ?>
    <h3 class="font-bold text-gray-800 mt-6 mb-2">📋 রেজিস্ট্রেশন আছে, গ্রুপে পাইনি (<?= count($result['missing']) ?>)</h3>
    <p class="text-gray-500 text-xs mb-3">এঁদের গ্রুপে যোগ করা বাকি থাকতে পারে (অথবা গ্রুপে নাম আলাদা)।</p>
    <div class="bg-white rounded-2xl shadow p-4 mb-3">
        <?php foreach ($result['missing'] as $r): ?>
            <div class="py-2 border-b last:border-0">
                <a href="registrations.php?action=view&amp;id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"
                   class="text-indigo-600 font-semibold text-sm"><?= e($r['customer_name']) ?></a>
                <div class="text-xs text-gray-500 mt-1">
                    <?php if (trim((string) $r['facebook_id']) !== ''): ?>ফেসবুক: <?= e($r['facebook_id']) ?> · <?php else: ?>
                        <span class="text-amber-600">ফেসবুক আইডি নাম দেওয়া নেই</span> ·
                    <?php endif; ?>
                    <a href="tel:<?= e($r['phone']) ?>"><?= e($r['phone']) ?></a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php if ($matched): ?>
    <h3 class="font-bold text-gray-800 mt-6 mb-2">✅ গ্রুপেও আছেন, রেজিস্ট্রেশনও আছে (<?= count($matched) ?>)</h3>
    <?php foreach ($matched as $entry) { gm_card($entry); } ?>
<?php endif; ?>

<?php if (!$result['entries']): ?>
    <div class="empty-state">
        <div class="empty-ic">📝</div>
        <p class="font-bold text-gray-700">কোনো নাম পাওয়া যায়নি</p>
        <p class="text-gray-500 text-sm mt-1">বাক্সে গ্রুপের সদস্যদের নাম বসিয়ে আবার চেষ্টা করুন।</p>
    </div>
<?php endif; ?>
<?php endif; /* $result */ ?>

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

    // ছোট স্ক্রিনশটে লেখা ছোট থাকে — বড় করে সাদাকালো করলে পড়া অনেক ভালো হয়
    function prep(file) {
        return new Promise(function (res, rej) {
            var img = new Image();
            img.onload = function () {
                var scale = Math.min(3, Math.max(1, 1400 / img.width));
                var c = document.createElement('canvas');
                c.width = Math.round(img.width * scale);
                c.height = Math.round(img.height * scale);
                var x = c.getContext('2d');
                x.drawImage(img, 0, 0, c.width, c.height);
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
            // oem 1 = LSTM — আমরা lstm-only core রেখেছি, তাই এটাই দিতে হবে
            return Tesseract.createWorker('eng', 1, {
                workerPath: base + 'worker.min.js?v=' + v,
                corePath: base + 'core/tesseract-core-lstm.wasm.js?v=' + v,
                langPath: base + 'lang',
                logger: function (m) {
                    if (m.status === 'recognizing text') { say('পড়ছে… ' + Math.round(m.progress * 100) + '%'); }
                }
            });
        }).then(function (worker) {
            var out = [];
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
