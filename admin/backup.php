<?php
// ব্যাকআপ ও ডাউনলোড — ডাটাবেস (.sql) ও ফাইল (.zip) এক ক্লিকে নামানো যায়।
//
// ⚠️ যে সার্ভারে এই পেজটা চলছে সেই সার্ভারেরই ব্যাকআপ হয়:
//    localhost/website/admin/  → লোকাল DB
//    shishurmedhabikash.com/admin/ → **লাইভ DB (আসল কাস্টমার ডেটা)**
//    তাই আসল ব্যাকআপের জন্য লাইভ সাইটের অ্যাডমিনে ঢুকে বাটন চাপতে হবে।
//
// শেয়ার্ড হোস্টিংয়ে `mysqldump` কমান্ড থাকে না, তাই ডাম্প বিশুদ্ধ PHP-তে (PDO দিয়ে টেবিল ঘুরে
// INSERT বানিয়ে) তৈরি হয় এবং **সরাসরি ব্রাউজারে স্ট্রিম** হয় — সার্ভারের মেমরিতে পুরো ফাইল জমে না।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/db-dump.php';
admin_require_login();

$db = get_db();
$pageTitle = 'ব্যাকআপ';
$action = $_GET['action'] ?? '';

/** ডাউনলোডের হেডার + আউটপুট বাফার পরিষ্কার (নাহলে ফাইলের শুরুতে HTML ঢুকে ফাইল নষ্ট হয়) */
function backup_send_headers(string $filename, string $mime): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
}

/** ফাইলের নামে তারিখ+সময় — একাধিক ব্যাকআপ গুলিয়ে যায় না */
function backup_stamp(): string
{
    return date('Ymd-Hi');
}

// ── ডাটাবেস ডাম্প (.sql) ────────────────────────────────────────────────
if ($action === 'db' && csrf_verify()) {
    $mode = $_POST['mode'] ?? 'full'; // full | structure | data
    @set_time_limit(0);
    backup_send_headers('educenter-db-' . $mode . '-' . backup_stamp() . '.sql', 'application/sql; charset=utf-8');

    // শেয়ার্ড হেল্পার (admin/includes/db-dump.php) — cron স্ক্রিপ্টও এটাই ব্যবহার করে।
    // এখানে খণ্ডগুলো সরাসরি ব্রাউজারে echo হয়, প্রতি খণ্ডে flush() করে ধাপে ধাপে পাঠানো।
    $n = 0;
    db_dump_stream($db, $mode, function (string $chunk) use (&$n) {
        echo $chunk;
        if ((++$n % 200) === 0) { flush(); }
    });
    exit;
}

// ── অটো-ব্যাকআপ ফাইল ডাউনলোড (তালিকা থেকে) ──────────────────────────────
if ($action === 'get' && csrf_verify()) {
    // ⚠️ শুধু ফাইলের নাম (basename) নেওয়া হয় — কেউ ../ দিয়ে অন্য ফাইল টানতে পারবে না (path traversal ঠেকানো)
    $name = basename($_POST['file'] ?? '');
    $path = db_backup_dir() . DIRECTORY_SEPARATOR . $name;
    if (!preg_match('/^educenter-db-[0-9]{8}-[0-9]{6}\.sql$/', $name) || !is_file($path)) {
        set_flash('error', 'ফাইলটি পাওয়া যায়নি।');
        redirect('backup.php');
    }
    backup_send_headers($name, 'application/sql; charset=utf-8');
    readfile($path);
    exit;
}

// ── অটো-ব্যাকআপ ফাইল মুছে ফেলা ───────────────────────────────────────────
if ($action === 'del' && csrf_verify()) {
    $name = basename($_POST['file'] ?? '');
    $path = db_backup_dir() . DIRECTORY_SEPARATOR . $name;
    if (preg_match('/^educenter-db-[0-9]{8}-[0-9]{6}\.sql$/', $name) && is_file($path)) {
        @unlink($path);
        set_flash('success', 'ব্যাকআপ ফাইল মুছে ফেলা হয়েছে।');
    }
    redirect('backup.php');
}

// ── "এখনই একটা সার্ভার-ব্যাকআপ বানাও" (cron ছাড়াই টেস্ট/ম্যানুয়াল ট্রিগার) ──
if ($action === 'runnow' && csrf_verify()) {
    $r = db_backup_to_file($db, 7);
    set_flash($r['ok'] ? 'success' : 'error',
        $r['ok'] ? "সার্ভারে ব্যাকআপ তৈরি হয়েছে: {$r['file']} (" . round($r['size'] / 1024) . " KB)"
                 : 'ব্যাকআপ ব্যর্থ: ' . $r['error']);
    redirect('backup.php');
}

// ── ফাইল ব্যাকআপ (.zip) ─────────────────────────────────────────────────
if ($action === 'files' && csrf_verify()) {
    $what = $_POST['what'] ?? 'uploads'; // uploads | app
    // ⚠️ ZipArchive সব সার্ভারে থাকে না (লোকাল XAMPP-এ বন্ধ ছিল — টেস্টে ধরা পড়ে)। না থাকলে
    // নীরবে ব্যর্থ না হয়ে **কী করতে হবে সেটা বলে দেওয়া হয়** (নিচে বিকল্প উপায়ও দেখানো আছে)।
    if (!class_exists('ZipArchive')) {
        set_flash('error', 'এই সার্ভারে ZIP বানানোর সুবিধা (ZipArchive) চালু নেই। '
            . 'বিকল্প: cPanel → File Manager → uploads ফোল্ডারে ডান-ক্লিক → Compress → ডাউনলোড। '
            . 'ডাটাবেস ব্যাকআপ কিন্তু এখান থেকেই নিতে পারবেন — সেটাই সবচেয়ে জরুরি।');
        redirect('backup.php');
    }
    @set_time_limit(0);
    $root = realpath(__DIR__ . '/..');
    $tmp  = tempnam(sys_get_temp_dir(), 'edubk');
    $zip  = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        set_flash('error', 'অস্থায়ী ZIP ফাইল তৈরি করা যায়নি (সার্ভারে জায়গা/অনুমতির সমস্যা হতে পারে)।');
        redirect('backup.php');
    }

    // 'uploads' = শুধু ছবি; 'app' = পুরো সাইট (ছবি সহ)
    $base = $what === 'uploads' ? $root . DIRECTORY_SEPARATOR . 'uploads' : $root;
    $skipDirs = ['node_modules', '.git', 'build'];
    if (is_dir($base)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $file) {
            $path = $file->getRealPath();
            $rel  = ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
            $rel  = str_replace(DIRECTORY_SEPARATOR, '/', $rel); // ⚠️ Linux-এ খোলার জন্য forward slash
            foreach ($skipDirs as $sd) {
                if (strpos($rel, $sd . '/') === 0) { continue 2; }
            }
            if ($file->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($path, $rel);
            }
        }
    }
    $zip->close();

    backup_send_headers('educenter-' . $what . '-' . backup_stamp() . '.zip', 'application/zip');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

// ── তথ্য সংগ্রহ (পেজে দেখানোর জন্য) ──────────────────────────────────────
$tableInfo = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
$totalRows = 0;
foreach (['registrations', 'income', 'expenses', 'courier_batches'] as $t) {
    try { $totalRows += (int) $db->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } catch (Throwable $e) {}
}
$uploadsSize = 0;
$uploadsDir = realpath(__DIR__ . '/../uploads');
if ($uploadsDir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir, FilesystemIterator::SKIP_DOTS)) as $f) {
        if ($f->isFile()) { $uploadsSize += $f->getSize(); }
    }
}
$isLive = !defined('DEV_MODE') || !DEV_MODE;

require __DIR__ . '/includes/layout-top.php';
?>
<div class="max-w-3xl space-y-5">

    <?php // কোন সার্ভারের ব্যাকআপ হচ্ছে সেটা স্পষ্ট করে বলা — সবচেয়ে বড় বিভ্রান্তির জায়গা ?>
    <div class="rounded-2xl p-4 <?= $isLive ? 'bg-green-50 border border-green-200' : 'bg-amber-50 border border-amber-200' ?>">
        <p class="font-bold <?= $isLive ? 'text-green-800' : 'text-amber-800' ?>">
            <?= $isLive
                ? '✅ এটা লাইভ সার্ভার — এখানকার ব্যাকআপই আপনার আসল কাস্টমার ডেটা।'
                : '⚠️ এটা আপনার কম্পিউটারের (লোকাল) সাইট — এখানকার ব্যাকআপে টেস্ট ডেটা থাকবে।' ?>
        </p>
        <?php if (!$isLive): ?>
            <p class="text-sm text-amber-700 mt-1">আসল ব্যাকআপের জন্য <strong>লাইভ সাইটের অ্যাডমিনে</strong> (shishurmedhabikash.com/admin/) ঢুকে এই পেজ থেকে ডাউনলোড করুন।</p>
        <?php endif; ?>
    </div>

    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <?php foreach ([['টেবিল', count($tableInfo)], ['মূল রেকর্ড', $totalRows],
                        ['ছবির জায়গা', round($uploadsSize / 1048576, 1) . ' MB'], ['তারিখ', date('d/m/Y')]] as $s): ?>
            <div class="bg-white rounded-2xl shadow p-3">
                <div class="text-xs text-gray-500"><?= e($s[0]) ?></div>
                <div class="text-xl font-black text-gray-900"><?= e((string) $s[1]) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php // ── ডাটাবেস ── ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-1"><i data-lucide="database" class="w-5 h-5 inline text-indigo-600"></i> ডাটাবেস ব্যাকআপ</h3>
        <p class="text-sm text-gray-500 mb-4">সব রেজিস্ট্রেশন, আয়-ব্যয়, কোর্স, কুরিয়ার — সবকিছু একটা <code>.sql</code> ফাইলে। <strong>এটাই সবচেয়ে জরুরি ব্যাকআপ।</strong></p>
        <div class="flex flex-wrap gap-2">
            <?php foreach ([
                ['full', 'সম্পূর্ণ ব্যাকআপ (গঠন + ডেটা)', 'bg-indigo-600 hover:bg-indigo-700 text-white'],
                ['data', 'শুধু ডেটা', 'bg-gray-100 hover:bg-gray-200 text-gray-800'],
                ['structure', 'শুধু গঠন', 'bg-gray-100 hover:bg-gray-200 text-gray-800'],
            ] as $b): ?>
                <form method="post" action="backup.php?action=db">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="<?= $b[0] ?>">
                    <button type="submit" class="<?= $b[2] ?> font-bold px-5 py-3 rounded-xl text-sm"><?= e($b[1]) ?></button>
                </form>
            <?php endforeach; ?>
        </div>
        <p class="text-xs text-gray-400 mt-3">⚠️ ফাইলে কাস্টমারের নাম/ফোন/ঠিকানা থাকে — নিরাপদ জায়গায় রাখুন, কাউকে পাঠাবেন না।</p>
    </div>

    <?php // ── ফাইল ── ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-1"><i data-lucide="folder" class="w-5 h-5 inline text-indigo-600"></i> ফাইল ব্যাকআপ</h3>
        <p class="text-sm text-gray-500 mb-4">আপলোড করা ছবি, অথবা পুরো ওয়েবসাইটের কোড+ছবি।</p>
        <?php if (!class_exists('ZipArchive')): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                এই সার্ভারে ZIP বানানোর সুবিধা (<code>ZipArchive</code>) চালু নেই, তাই এখান থেকে ফাইল ব্যাকআপ নেওয়া যাবে না।<br>
                <strong>বিকল্প:</strong> cPanel → File Manager → <code>uploads</code> ফোল্ডারে ডান-ক্লিক → <strong>Compress</strong> → ডাউনলোড।<br>
                <span class="text-amber-700">ডাটাবেস ব্যাকআপ উপরের বাটন থেকেই নিতে পারবেন — সেটাই সবচেয়ে জরুরি।</span>
            </div>
        <?php else: ?>
        <div class="flex flex-wrap gap-2">
            <form method="post" action="backup.php?action=files">
                <?= csrf_field() ?><input type="hidden" name="what" value="uploads">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-3 rounded-xl text-sm">🖼️ শুধু ছবি (<?= round($uploadsSize / 1048576, 1) ?> MB)</button>
            </form>
            <form method="post" action="backup.php?action=files">
                <?= csrf_field() ?><input type="hidden" name="what" value="app">
                <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-5 py-3 rounded-xl text-sm">💾 পুরো ওয়েবসাইট</button>
            </form>
        </div>
        <?php endif; ?>
        <p class="text-xs text-gray-400 mt-3">ফাইল বেশি হলে "পুরো ওয়েবসাইট" সময় নিতে পারে। না নামলে ছবি ও কোড আলাদা করে নিন, অথবা cPanel → File Manager → Compress ব্যবহার করুন।</p>
    </div>

    <?php // ── অটো ব্যাকআপ (সার্ভারে জমা) ── ?>
    <?php
        $autoDir = db_backup_dir();
        $autoFiles = glob($autoDir . DIRECTORY_SEPARATOR . 'educenter-db-*.sql') ?: [];
        usort($autoFiles, fn($a, $b) => filemtime($b) <=> filemtime($a)); // নতুন আগে
    ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex items-center justify-between gap-2 flex-wrap mb-1">
            <h3 class="font-bold text-gray-800"><i data-lucide="history" class="w-5 h-5 inline text-indigo-600"></i> অটো ব্যাকআপ (সার্ভারে জমা)</h3>
            <form method="post" action="backup.php?action=runnow">
                <?= csrf_field() ?>
                <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-4 py-2 rounded-xl text-sm"><i data-lucide="plus" class="w-4 h-4 inline"></i> এখনই একটা বানাও</button>
            </form>
        </div>
        <p class="text-sm text-gray-500 mb-4">cPanel Cron রোজ একটা করে ব্যাকআপ বানায়, সর্বশেষ ৭টা এখানে জমা থাকে (পুরনোগুলো নিজে মুছে যায়)। যেকোনোটা ডাউনলোড করে কম্পিউটার/ফোনে রেখে দিন।</p>

        <?php if (!$autoFiles): ?>
            <div class="bg-amber-50 border border-amber-200 rounded-xl p-4 text-sm text-amber-800">
                এখনো কোনো অটো ব্যাকআপ নেই। Cron সেট করলে রোজ নিজে থেকে তৈরি হবে — অথবা উপরের <strong>"এখনই একটা বানাও"</strong> চেপে পরীক্ষা করুন।<br>
                <span class="text-xs">Cron সেটআপ: cPanel → Cron Jobs → Once a day → Command-এ দিন:<br>
                <code class="break-all">php <?= e(realpath(__DIR__ . '/../cron/db-backup.php') ?: '/home/ইউজার/public_html/cron/db-backup.php') ?></code></span>
            </div>
        <?php else: ?>
            <div class="bg-white rounded-2xl border border-gray-100 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="text-left text-gray-500 border-b bg-gray-50"><th class="py-3 px-4">তারিখ ও সময়</th><th class="py-3 px-4">সাইজ</th><th class="py-3 px-4">অ্যাকশন</th></tr></thead>
                    <tbody>
                    <?php foreach ($autoFiles as $f): $bn = basename($f); ?>
                        <tr class="border-b last:border-0">
                            <td class="py-2.5 px-4 font-mono text-xs"><?= e(date('d/m/Y  H:i', filemtime($f))) ?></td>
                            <td class="py-2.5 px-4"><?= round(filesize($f) / 1024) ?> KB</td>
                            <td class="py-2.5 px-4 whitespace-nowrap space-x-2">
                                <form method="post" action="backup.php?action=get" class="inline">
                                    <?= csrf_field() ?><input type="hidden" name="file" value="<?= e($bn) ?>">
                                    <button type="submit" class="text-indigo-600 font-semibold">ডাউনলোড</button>
                                </form>
                                <form method="post" action="backup.php?action=del" class="inline" onsubmit="return confirmSubmit(this, 'এই ব্যাকআপ ফাইলটি মুছবেন?', 'ডিলিট নিশ্চিতকরণ');">
                                    <?= csrf_field() ?><input type="hidden" name="file" value="<?= e($bn) ?>">
                                    <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php // ── কীভাবে ফেরাবেন ── ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-3">🔄 ব্যাকআপ থেকে ফেরানোর নিয়ম</h3>
        <ol class="text-sm text-gray-600 space-y-2 list-decimal list-inside leading-relaxed">
            <li><strong>ডাটাবেস</strong>: phpMyAdmin → আপনার ডাটাবেস সিলেক্ট → <strong>Import</strong> → <code>.sql</code> ফাইলটা দিন → Go</li>
            <li><strong>ছবি</strong>: ZIP-টা <code>public_html</code> এ আপলোড করে Extract করুন</li>
            <li><strong>কোড</strong>: একইভাবে Extract, তারপর <code>config.php</code> ঠিক আছে কিনা দেখে নিন</li>
        </ol>
        <p class="text-xs text-gray-500 mt-3">💡 <strong>পরামর্শ</strong>: মাসে অন্তত একবার ডাটাবেস ব্যাকআপ নামিয়ে রাখুন। ফাইলগুলো কম্পিউটারের পাশাপাশি Google Drive/পেনড্রাইভেও রাখলে সবচেয়ে নিরাপদ।</p>
    </div>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
