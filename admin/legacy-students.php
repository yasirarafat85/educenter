<?php
// পুরাতন শিক্ষার্থী — পুরনো Excel/CSV ডেটার রেফারেন্স তালিকা (২০২৬-০৮-১৭)।
// ⚠️ registrations থেকে সম্পূর্ণ আলাদা টেবিল (legacy_students) — আয়/কুরিয়ার সিস্টেম এটা ছোঁয় না।
// ব্যবহার: (১) CSV আপলোড → কলাম ম্যাপ → ইমপোর্ট, (২) মোট/কোর্স-অনুযায়ী গণনা, (৩) নাম/ফোন সার্চ।
// অভিভাবক ফোন দিয়ে লগইন/রেজিস্ট্রেশন করলে account.php + ajax-lookup এই টেবিলও মিলিয়ে পুরনো তথ্য দেখায়।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$db = get_db();
$pageTitle = 'পুরাতন শিক্ষার্থী';

// সিস্টেমের ফিল্ড (এগুলোতেই CSV কলাম ম্যাপ হবে) — বর্তমান রেজিস্ট্রেশনের মতোই
$sysFields = [
    'customer_name' => 'শিশুর নাম',
    'phone'         => 'মোবাইল (মা) 🔑',
    'father_mobile' => 'মোবাইল (বাবা)',
    'course_title'  => 'কোর্সের নাম',
    'batch'         => 'ব্যাচ / সাল',
    'date_of_birth' => 'জন্ম তারিখ',
    'facebook_id'   => 'ফেসবুক নাম',
    'address'       => 'ঠিকানা',
    'notes'         => 'মন্তব্য',
];

// হেডার টেক্সট থেকে সিস্টেম-ফিল্ড আন্দাজ (অটো প্রি-সিলেক্ট, সম্পাদনযোগ্য)
function ls_guess_field(string $header): string
{
    $h = mb_strtolower(trim($header));
    $map = [
        'customer_name' => ['শিশু', 'নাম', 'student', 'name', 'child'],
        'phone'         => ['মোবাইল (মা)', 'মা', 'mother', 'mobile', 'phone', 'ফোন', 'নাম্বার', 'নম্বর', 'contact'],
        'father_mobile' => ['বাবা', 'father', 'baba'],
        'course_title'  => ['কোর্স', 'course', 'class', 'ক্লাস', 'subject', 'বিষয়'],
        'batch'         => ['ব্যাচ', 'batch', 'সাল', 'year', 'session', 'ব্যাচ/সাল'],
        'date_of_birth' => ['জন্ম', 'birth', 'dob', 'বয়স', 'age'],
        'facebook_id'   => ['ফেসবুক', 'facebook', 'fb'],
        'address'       => ['ঠিকানা', 'address', 'এলাকা'],
        'notes'         => ['মন্তব্য', 'note', 'remark', 'comment', 'বিবরণ'],
    ];
    foreach ($map as $field => $keys) {
        foreach ($keys as $k) {
            if ($h !== '' && mb_strpos($h, mb_strtolower($k)) !== false) { return $field; }
        }
    }
    return '';
}

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('legacy-students.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            set_flash('error', 'CSV ফাইল আপলোড করুন।'); redirect('legacy-students.php');
        }
        $ext = strtolower(pathinfo($_FILES['csv']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            set_flash('error', 'শুধু .csv ফাইল দিন (Excel-এ "CSV UTF-8" ফরম্যাটে সেভ করুন)।'); redirect('legacy-students.php');
        }
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if ($raw === false || trim($raw) === '') { set_flash('error', 'ফাইল খালি বা পড়া যায়নি।'); redirect('legacy-students.php'); }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM সরানো
        if (!mb_check_encoding($raw, 'UTF-8')) {
            set_flash('error', 'ফাইলটি UTF-8 নয় — বাংলা লেখা ভেঙে যাবে। Excel-এ "Save As → CSV UTF-8 (Comma delimited)" দিয়ে সেভ করে আবার আপলোড করুন।');
            redirect('legacy-students.php');
        }
        // CSV পার্স
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
        $headers = fgetcsv($fh);
        if (!$headers || count(array_filter($headers, fn($x) => trim((string) $x) !== '')) === 0) {
            fclose($fh); set_flash('error', 'ফাইলে কোনো কলাম-হেডার পাওয়া যায়নি।'); redirect('legacy-students.php');
        }
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count(array_filter($r, fn($x) => trim((string) $x) !== '')) === 0) { continue; } // খালি সারি বাদ
            $rows[] = $r;
            if (count($rows) >= 5000) { break; } // একবারে সর্বোচ্চ ৫০০০ সারি
        }
        fclose($fh);
        if (!$rows) { set_flash('error', 'হেডার ছাড়া কোনো ডেটা-সারি নেই।'); redirect('legacy-students.php'); }
        $_SESSION['legacy_import'] = ['headers' => array_map(fn($h) => trim((string) $h), $headers), 'rows' => $rows];
        redirect('legacy-students.php?step=map');
    }

    if ($action === 'import') {
        $imp = $_SESSION['legacy_import'] ?? null;
        if (!$imp) { set_flash('error', 'আপলোড করা ডেটা পাওয়া যায়নি — আবার আপলোড করুন।'); redirect('legacy-students.php'); }
        $map = $_POST['map'] ?? []; // [sysField => csv column index | '']
        // অন্তত নাম বা ফোন ম্যাপ করা থাকতে হবে
        if ((($map['customer_name'] ?? '') === '') && (($map['phone'] ?? '') === '')) {
            set_flash('error', 'অন্তত "শিশুর নাম" বা "মোবাইল (মা)" কলাম ম্যাপ করুন।'); redirect('legacy-students.php?step=map');
        }
        $ins = $db->prepare(
            'INSERT INTO legacy_students (customer_name, phone, father_mobile, course_title, batch, date_of_birth, facebook_id, address, notes)
             VALUES (:customer_name, :phone, :father_mobile, :course_title, :batch, :date_of_birth, :facebook_id, :address, :notes)'
        );
        $count = 0;
        foreach ($imp['rows'] as $row) {
            $rec = [];
            foreach (['customer_name','phone','father_mobile','course_title','batch','date_of_birth','facebook_id','address','notes'] as $f) {
                $idx = $map[$f] ?? '';
                $rec[$f] = ($idx !== '' && isset($row[(int) $idx])) ? trim((string) $row[(int) $idx]) : '';
            }
            if ($rec['customer_name'] === '' && $rec['phone'] === '') { continue; } // দুটোই খালি → বাদ
            $ins->execute($rec);
            $count++;
        }
        unset($_SESSION['legacy_import']);
        set_flash('success', $count . ' জন পুরাতন শিক্ষার্থী ইমপোর্ট হয়েছে।');
        redirect('legacy-students.php');
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM legacy_students WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        set_flash('success', 'মুছে ফেলা হয়েছে।');
        redirect('legacy-students.php');
    }

    if ($action === 'clear-all') {
        $db->exec('DELETE FROM legacy_students');
        set_flash('success', 'সব পুরাতন শিক্ষার্থী মুছে ফেলা হয়েছে।');
        redirect('legacy-students.php');
    }

    if ($action === 'cancel-import') {
        unset($_SESSION['legacy_import']);
        redirect('legacy-students.php');
    }

    redirect('legacy-students.php');
}

require __DIR__ . '/includes/layout-top.php';

// ==================== ধাপ ২: কলাম ম্যাপিং ====================
if (($_GET['step'] ?? '') === 'map' && !empty($_SESSION['legacy_import'])) {
    $imp = $_SESSION['legacy_import'];
    $headers = $imp['headers'];
    $preview = array_slice($imp['rows'], 0, 4);
    ?>
    <div class="max-w-3xl">
        <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
            <h2 class="font-black text-gray-800 text-lg mb-1">কলাম মিলিয়ে দিন</h2>
            <p class="text-sm text-gray-500">আপনার ফাইলে <b><?= count($imp['rows']) ?></b> টি সারি পাওয়া গেছে। প্রতিটা সিস্টেম-ফিল্ডের পাশে বেছে নিন — আপনার ফাইলের কোন কলাম সেখানে বসবে। (অটো আন্দাজ করা আছে, দরকারে বদলান।)</p>
        </div>

        <form method="post" action="legacy-students.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">
            <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4 space-y-3">
                <?php foreach ($sysFields as $field => $label):
                    $guess = ''; foreach ($headers as $i => $h) { if (ls_guess_field($h) === $field) { $guess = (string) $i; break; } } ?>
                    <div class="flex items-center gap-3 flex-wrap">
                        <label class="text-sm font-semibold text-gray-700" style="min-width:130px;"><?= e($label) ?></label>
                        <span class="text-gray-400">→</span>
                        <select name="map[<?= e($field) ?>]" class="border rounded-lg px-3 py-2 text-sm flex-1" style="min-width:180px;">
                            <option value="">— বাদ দিন —</option>
                            <?php foreach ($headers as $i => $h): ?>
                                <option value="<?= (int) $i ?>" <?= (string) $i === $guess ? 'selected' : '' ?>><?= e($h !== '' ? $h : ('কলাম ' . ($i + 1))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // প্রিভিউ ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto mb-4">
                <div class="px-4 pt-3 text-xs font-semibold text-gray-500">আপনার ফাইলের প্রথম কয়েকটা সারি (প্রিভিউ)</div>
                <table class="w-full text-xs mt-2">
                    <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                        <?php foreach ($headers as $h): ?><th class="py-2 px-3 whitespace-nowrap"><?= e($h) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r): ?>
                        <tr class="border-b last:border-0">
                            <?php foreach ($headers as $i => $h): ?><td class="py-1.5 px-3 whitespace-nowrap text-gray-700"><?= e((string) ($r[$i] ?? '')) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-3 rounded-xl text-sm"><i data-lucide="download" class="w-4 h-4 inline"></i> ইমপোর্ট করুন</button>
                <button type="submit" name="action" value="cancel-import" formnovalidate class="text-gray-500 font-semibold text-sm">বাতিল</button>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ==================== ডিফল্ট: গণনা + তালিকা + আপলোড ====================
$q = trim($_GET['q'] ?? '');
$total = (int) $db->query('SELECT COUNT(*) FROM legacy_students')->fetchColumn();
$withPhone = (int) $db->query("SELECT COUNT(*) FROM legacy_students WHERE phone <> ''")->fetchColumn();
$byCourse = $db->query("SELECT IF(course_title='','(কোর্স নেই)',course_title) c, COUNT(*) n FROM legacy_students GROUP BY course_title ORDER BY n DESC")->fetchAll();

// তালিকা (সার্চ + লিমিট)
if ($q !== '') {
    $st = $db->prepare("SELECT * FROM legacy_students WHERE customer_name LIKE :q OR phone LIKE :q OR course_title LIKE :q OR facebook_id LIKE :q ORDER BY customer_name LIMIT 200");
    $st->execute(['q' => '%' . $q . '%']);
} else {
    $st = $db->query('SELECT * FROM legacy_students ORDER BY id DESC LIMIT 200');
}
$list = $st->fetchAll();
?>
<div class="max-w-5xl">
    <?php // ── আপলোড ফর্ম ── ?>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
        <h2 class="font-black text-gray-800 text-lg mb-1"><i data-lucide="upload" class="w-5 h-5 inline text-indigo-600"></i> পুরনো ডেটা আপলোড (CSV)</h2>
        <p class="text-sm text-gray-500 mb-3">Excel থেকে <b>"Save As → CSV UTF-8 (Comma delimited)"</b> করে সেভ করুন, তারপর এখানে আপলোড করে কলাম মিলিয়ে দিন। <span class="text-gray-400">(এই ডেটা আয়/কুরিয়ারে যাবে না — শুধু রেফারেন্স।)</span></p>
        <form method="post" action="legacy-students.php" enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="csv" accept=".csv,text/csv" required class="text-sm border rounded-lg px-3 py-2 bg-gray-50">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl text-sm">আপলোড ও কলাম মেলান →</button>
        </form>
    </div>

    <?php // ── গণনা কার্ড ── ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">মোট পুরাতন শিক্ষার্থী</div>
            <div class="text-2xl font-black text-gray-900"><?= number_format($total) ?></div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">ফোন নম্বর আছে</div>
            <div class="text-2xl font-black text-green-600"><?= number_format($withPhone) ?></div>
            <div class="text-[11px] text-gray-400">এরা লগইন/অটো-ফিলে মিলবে</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">ভিন্ন কোর্স</div>
            <div class="text-2xl font-black text-gray-900"><?= number_format(count($byCourse)) ?></div>
        </div>
    </div>

    <?php // ── কোর্স অনুযায়ী সংখ্যা ── ?>
    <?php if ($byCourse): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4">
        <div class="text-sm font-bold text-gray-700 mb-2">কোর্স অনুযায়ী সংখ্যা</div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($byCourse as $bc): ?>
                <span class="inline-flex items-center gap-1.5 bg-indigo-50 text-indigo-700 rounded-lg px-3 py-1.5 text-sm font-semibold">
                    <?= e($bc['c']) ?> <span class="bg-white rounded-md px-1.5 text-indigo-600"><?= number_format((int) $bc['n']) ?></span>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── তালিকা + সার্চ ── ?>
    <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
        <form method="get" action="legacy-students.php" id="lsFilterForm" class="flex items-center gap-2">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="নাম / ফোন / কোর্স খুঁজুন..." class="border rounded-lg px-3 py-2 text-sm" style="width:230px;">
            <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold px-3 py-2 rounded-lg text-sm">খুঁজুন</button>
            <?php if ($q !== ''): ?><a href="legacy-students.php" class="text-gray-500 text-sm">✕ ক্লিয়ার</a><?php endif; ?>
        </form>
        <?php if ($total > 0): ?>
        <form method="post" action="legacy-students.php" onsubmit="return confirmSubmit(this, 'সব পুরাতন শিক্ষার্থী মুছে ফেলবেন? এটা ফেরানো যাবে না।', 'সব মুছবেন?');">
            <?= csrf_field() ?><input type="hidden" name="action" value="clear-all">
            <button type="submit" class="text-red-600 text-sm font-semibold">সব মুছুন</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!$list): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div><?= $q !== '' ? 'কিছু পাওয়া যায়নি।' : 'এখনো কোনো পুরাতন শিক্ষার্থী যোগ করা হয়নি — উপরে CSV আপলোড করুন।' ?></div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিশুর নাম</th><th class="py-3 px-4">মোবাইল (মা)</th><th class="py-3 px-4">কোর্স</th>
                <th class="py-3 px-4">ব্যাচ/সাল</th><th class="py-3 px-4">ফেসবুক</th><th class="py-3 px-4">অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $s): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4 font-semibold text-gray-900"><?= e($s['customer_name'] ?: '—') ?></td>
                    <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($s['phone'] ?: '—') ?></td>
                    <td class="py-2.5 px-4"><?= e($s['course_title'] ?: '—') ?></td>
                    <td class="py-2.5 px-4"><?= e($s['batch'] ?: '—') ?></td>
                    <td class="py-2.5 px-4 text-gray-600"><?= e($s['facebook_id'] ?: '—') ?></td>
                    <td class="py-2.5 px-4">
                        <form method="post" action="legacy-students.php" class="inline" onsubmit="return confirmSubmit(this, 'এই শিক্ষার্থীকে মুছবেন?', 'মুছবেন?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                            <button type="submit" class="text-red-600 font-semibold text-xs">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (count($list) >= 200): ?><p class="text-xs text-gray-400 mt-2">প্রথম ২০০টি দেখানো হচ্ছে — নির্দিষ্ট কাউকে খুঁজতে উপরে সার্চ করুন।</p><?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
