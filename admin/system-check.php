<?php
// ─────────────────────────────────────────────────────────────────────────────
// 🩺 সিস্টেম চেক (২০২৬-১০-১৫) — "কোন SQL এখনো চালানো হয়নি" সেটা দেখায়।
//
// 🔴🔴 এই পাতা **কিছুই লেখে না** — একটাও INSERT/UPDATE/DELETE/ALTER নেই, কোনো
//    POST হ্যান্ডলারও নেই। শুধু `database/migrate-*.sql` ফাইলগুলো পড়ে আর DB-তে
//    সেগুলোর টেবিল/কলাম আছে কিনা মিলিয়ে দেখে। **এখানে কখনো লেখার কোড যোগ
//    করবেন না** — মাইগ্রেশন ইউজার নিজে phpMyAdmin-এ চালান (AI/PHP থেকে
//    `ALTER TABLE` চালালে ভুল হলে ফেরানোর উপায় থাকে না)।
//
// কেন দরকার: git শুধু ফাইল নেয়, টেবিল/কলাম নয়। কোডবেসে ৪৬ জায়গায়
// `db_has_column()`/`psms_ready()` জাতীয় গার্ড আছে যেগুলো কলাম না থাকলে ঐ ঘরটা
// **চুপচাপ উধাও** করে দেয় (সাইট ভাঙে না, কিন্তু ফিচারটাও চলে না)। ফলে কিছু কাজ
// না করলে "বাগ" আর "SQL চলেনি" আলাদা করা যেত না — এই পাতাই সেই অনুমানটা বন্ধ করে।
//
// ইঞ্জিন (বিশুদ্ধ পার্সার) `admin/includes/schema-check.php`-এ।
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/nav.php';
require_once __DIR__ . '/includes/schema-check.php';
admin_require_login();

$db        = get_db();
$pageTitle = 'সিস্টেম চেক';

// 🔴 ফোল্ডারটা লাইভে থাকতেই হবে। আগে `.cpanel.yml` ডিপ্লয়ের সময় `database/`
//    মুছে ফেলত (SQL লাইভে দরকার নেই ভেবে) — তাতে এই পাতার পড়ার কিছুই থাকত না,
//    আর "SQL কপি করুন" বোতামও দেওয়া যেত না। এখন ফোল্ডারটা কপি হয় আর ভেতরের
//    deny-all `.htaccess` ফাইলগুলো বাইরে থেকে পড়া বন্ধ করে।
$sqlDir   = dirname(__DIR__) . '/database';
$dirReady = is_dir($sqlDir);

$files   = [];   // প্রতিটা মাইগ্রেশন ফাইলের ফল
$okCount = 0;    // মোট কতগুলো টেবিল/কলাম ঠিক আছে
$missing = 0;    // মোট কতগুলো বাকি
$skipped = sc_skip_files();

if ($dirReady) {
    $paths = glob($sqlDir . '/migrate-*.sql') ?: [];
    sort($paths);
    foreach ($paths as $path) {
        $base = basename($path);
        if (isset($skipped[$base])) { continue; }

        $sql = (string) @file_get_contents($path);
        if ($sql === '') { continue; }

        $parsed = sc_parse_migration($sql);
        $need   = [];   // এই ফাইলের বাকি থাকা জিনিস
        $fine   = 0;

        foreach ($parsed['tables'] as $t) {
            if (db_has_table($db, $t)) { $fine++; }
            else { $need[] = ['kind' => 'table', 'label' => $t]; }
        }
        foreach ($parsed['columns'] as [$t, $c]) {
            // 🔴 টেবিলই না থাকলে তার কলামগুলো আলাদা করে বলা হয় না — তালিকা
            //    অকারণে লম্বা হতো, আর টেবিল বসালে কলামও একসাথেই বসে।
            if (!db_has_table($db, $t)) { continue; }
            if (db_has_column($db, $t, $c)) { $fine++; }
            else { $need[] = ['kind' => 'column', 'label' => $t . '.' . $c]; }
        }

        $okCount += $fine;
        $missing += count($need);

        $files[] = [
            'name'  => $base,
            'ok'    => $fine,
            'need'  => $need,
            'note'  => sc_file_notes()[$base] ?? '',
            'sql'   => $sql,
            'total' => count($parsed['tables']) + count($parsed['columns']),
        ];
    }
}

$pending = array_values(array_filter($files, fn($f) => $f['need'] !== []));
$clean   = array_values(array_filter($files, fn($f) => $f['need'] === [] && $f['total'] > 0));
// ⚪ যেসব ফাইলে একটাও নতুন টেবিল/কলাম নেই (শুধু INDEX যোগ করে) — মিলিয়ে দেখার
//    কিছু নেই। **তবু তালিকায় দেখানো হয়**, নাহলে "সব সবুজ" দেখে ইউজার ভাবতেন
//    ওগুলোও যাচাই হয়েছে। না চালালে কিছু ভাঙে না, শুধু পাতা ধীরে খোলে।
$unknown = array_values(array_filter($files, fn($f) => $f['total'] === 0));

// কোন ডাটাবেসে দেখা হচ্ছে — ইউজার যেন phpMyAdmin-এ ঠিক ঐটাই খোলেন
$dbName = '';
try { $dbName = (string) $db->query('SELECT DATABASE()')->fetchColumn(); } catch (Throwable $e) { $dbName = ''; }

require __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">🩺 সিস্টেম চেক</h1>
    <p class="text-sm text-gray-500 mt-1">
        নতুন ফিচারের SQL আপনি phpMyAdmin-এ চালান। কোনটা এখনো চালানো হয়নি — এই পাতা সেটাই দেখায়।
        <span class="font-semibold text-gray-700">এই পাতা নিজে কিছুই বদলায় না।</span>
    </p>
</div>

<?php if (!$dirReady): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-6 text-sm text-amber-800">
        <p class="font-bold mb-1">⚠️ <code>database/</code> ফোল্ডারটা সার্ভারে পাওয়া যায়নি</p>
        <p>আগের ডিপ্লয়ে এই ফোল্ডারটা বাদ পড়ত। cPanel → Git Version Control → <b>Update from Remote</b> →
           <b>Deploy HEAD Commit</b> একবার চাপলেই ফোল্ডারটা চলে আসবে, তারপর এই পাতা কাজ করবে।</p>
    </div>
<?php else: ?>

    <?php /* সারাংশ — দুটো কার্ড */ ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500 mb-1">ঠিক আছে</div>
            <div class="text-2xl font-bold text-green-600"><?= (int) $okCount ?> টি</div>
            <div class="text-xs text-gray-400 mt-1">টেবিল ও কলাম</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500 mb-1">বাকি আছে</div>
            <div class="text-2xl font-bold <?= $missing > 0 ? 'text-red-600' : 'text-green-600' ?>"><?= (int) $missing ?> টি</div>
            <div class="text-xs text-gray-400 mt-1"><?= count($pending) ?> টি ফাইল চালাতে হবে</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500 mb-1">ডাটাবেস</div>
            <div class="text-base font-bold text-gray-800 break-all"><?= e($dbName !== '' ? $dbName : '—') ?></div>
            <div class="text-xs text-gray-400 mt-1"><?= DEV_MODE ? '🟡 লোকাল (DEV_MODE)' : '🟢 লাইভ সার্ভার' ?></div>
        </div>
    </div>

    <?php if ($missing === 0): ?>
        <div class="bg-green-50 border border-green-200 rounded-2xl p-5 mb-6">
            <p class="text-green-800 font-bold text-lg">✅ সব SQL চালানো হয়ে গেছে — কিছুই বাকি নেই।</p>
            <p class="text-sm text-green-700 mt-1">
                অর্থাৎ কোনো ফিচার কাজ না করলে সেটা <b>মাইগ্রেশনের কারণে নয়</b> — আমাকে বাগ হিসেবে জানান।
            </p>
        </div>
    <?php else: ?>
        <div class="bg-red-50 border border-red-200 rounded-2xl p-5 mb-6">
            <p class="text-red-800 font-bold text-lg mb-2">⚠️ <?= count($pending) ?> টি SQL ফাইল এখনো চালানো হয়নি</p>
            <p class="text-sm text-red-700 mb-3">নিচের প্রতিটা ফাইলের জন্য এই ৩ ধাপ:</p>
            <ol class="text-sm text-red-800 list-decimal space-y-1" style="padding-inline-start:1.3rem;">
                <li><b>📋 SQL কপি করুন</b> বোতামে চাপুন</li>
                <li>cPanel → <b>phpMyAdmin</b> → বাঁ পাশে <code><?= e($dbName !== '' ? $dbName : 'আপনার ডাটাবেস') ?></code> বেছে নিন → উপরের <b>SQL</b> ট্যাব</li>
                <li>বড় ঘরে পেস্ট করে <b>Go</b> চাপুন → তারপর এই পাতায় ফিরে এসে রিফ্রেশ করুন</li>
            </ol>
            <p class="text-xs text-red-600 mt-3">
                🔴 এগুলো <b>কিছু মুছে না</b> — শুধু নতুন টেবিল/কলাম যোগ করে। একই ফাইল দুইবার চালালে
                "already exists" লেখা আসতে পারে, তাতে কোনো ক্ষতি নেই।
            </p>
        </div>

        <?php foreach ($pending as $f): ?>
            <div class="bg-white rounded-2xl shadow p-4 mb-4" style="box-shadow: inset 3px 0 0 #DC2626;">
                <div class="font-bold text-gray-800 break-all">📄 database/<?= e($f['name']) ?></div>
                <?php if ($f['note'] !== ''): ?>
                    <div class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mt-2">
                        <b>না চালালে কাজ করবে না:</b> <?= e($f['note']) ?>
                    </div>
                <?php endif; ?>

                <div class="text-sm text-gray-600 mt-3">
                    <b><?= count($f['need']) ?> টি জিনিস নেই</b><?= $f['ok'] > 0 ? ' (' . (int) $f['ok'] . ' টি আগেই আছে)' : '' ?>:
                </div>
                <ul class="text-xs text-gray-600 mt-1 space-y-0.5">
                    <?php foreach ($f['need'] as $n): ?>
                        <li><?= $n['kind'] === 'table' ? '🗃️ টেবিল' : '🔹 কলাম' ?>
                            <code class="break-all"><?= e($n['label']) ?></code></li>
                    <?php endforeach; ?>
                </ul>

                <div class="flex flex-wrap gap-2 mt-3">
                    <button type="button" class="sc-copy bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-semibold">
                        📋 SQL কপি করুন
                    </button>
                    <button type="button" class="sc-show bg-gray-100 text-gray-700 px-4 py-2 rounded-lg text-sm">
                        ▸ SQL দেখুন
                    </button>
                </div>
                <?php /* 🔴 লেখাটা <textarea>-তে, যাতে কপি-ফলব্যাক (execCommand) কাজ করে
                          আর ব্রাউজার ভেতরের `<` `>` ট্যাগ ভেবে না বসে। */ ?>
                <textarea class="sc-sql w-full mt-3 text-xs font-mono border rounded-lg p-2"
                          rows="12" readonly style="display:none;"><?= e($f['sql']) ?></textarea>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <?php if ($clean): ?>
        <details class="bg-white rounded-2xl shadow p-4">
            <summary class="cursor-pointer font-semibold text-gray-700">
                ✅ চালানো হয়ে গেছে — <?= count($clean) ?> টি ফাইল
            </summary>
            <ul class="mt-3 space-y-1 text-sm text-gray-600">
                <?php foreach ($clean as $f): ?>
                    <li class="flex items-start gap-2">
                        <span class="text-green-600">✓</span>
                        <span class="min-w-0">
                            <code class="break-all"><?= e($f['name']) ?></code>
                            <span class="text-xs text-gray-400">(<?= (int) $f['ok'] ?> টি)</span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

    <?php if ($unknown): ?>
        <details class="bg-white rounded-2xl shadow p-4 mt-4">
            <summary class="cursor-pointer font-semibold text-gray-700">
                ⚪ মিলিয়ে দেখা যায় না — <?= count($unknown) ?> টি ফাইল (শুধু গতি বাড়ায়)
            </summary>
            <p class="text-xs text-gray-500 mt-2">
                এই ফাইলগুলো নতুন কোনো টেবিল/কলাম বানায় না — শুধু ইনডেক্স যোগ করে, অর্থাৎ পাতা দ্রুত খোলে।
                চালানো হয়েছে কিনা কোড থেকে বোঝার উপায় নেই, কিন্তু <b>না চালালে কিছুই ভাঙে না</b>।
                নিশ্চিত হতে চাইলে একবার চালিয়ে নিতে পারেন (দুইবার চালালেও ক্ষতি নেই)।
            </p>
            <ul class="mt-2 space-y-1 text-sm text-gray-600">
                <?php foreach ($unknown as $f): ?>
                    <li><code class="break-all"><?= e($f['name']) ?></code></li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endif; ?>

<?php endif; ?>

<?php /* 🔴 নিজের আলাদা স্ক্রিপ্ট-ব্লক — layout-bottom-এর বড় স্ক্রিপ্টের ভেতরে নয়
          (ওখানে কিছু ভাঙলে সাইডবার/মডাল/টোস্ট সব একসাথে মরে)। */ ?>
<script>
(function () {
    document.querySelectorAll('.sc-show').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ta = btn.closest('.bg-white').querySelector('.sc-sql');
            if (!ta) { return; }
            var open = ta.style.display !== 'none';
            ta.style.display = open ? 'none' : 'block';
            btn.textContent = open ? '▸ SQL দেখুন' : '▾ লুকান';
        });
    });

    document.querySelectorAll('.sc-copy').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var ta = btn.closest('.bg-white').querySelector('.sc-sql');
            if (!ta) { return; }
            var done = function () { btn.textContent = '✅ কপি হয়েছে'; setTimeout(function () { btn.textContent = '📋 SQL কপি করুন'; }, 2500); };
            // navigator.clipboard শুধু HTTPS-এ চলে — না পারলে পুরনো পথে
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(ta.value).then(done, function () { legacy(); });
            } else { legacy(); }
            function legacy() {
                var vis = ta.style.display;
                ta.style.display = 'block';
                ta.select();
                try { document.execCommand('copy'); done(); }
                catch (e) { btn.textContent = '⚠️ কপি হয়নি — "SQL দেখুন" চেপে হাতে কপি করুন'; }
                ta.style.display = vis;
            }
        });
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
