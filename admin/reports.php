<?php
// শিক্ষার্থীর রিপোর্ট কার্ড / ফলাফল (২০২৬-১০-২৬)
// অ্যাডমিন প্রতিটা শিক্ষার্থীর PDF রিপোর্ট আপলোড করেন (মোবাইল = পরিচয়-চাবি)।
// অভিভাবক নিজের অ্যাকাউন্টে লগইন করে শুধু নিজের মোবাইলেরটা দেখেন (account.php → report-view.php)।
// 🔴 PDF uploads/reports/-এ deny-all .htaccess দিয়ে সুরক্ষিত; গেটেড report-view.php ছাড়া খোলা যায় না।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/finance-link.php'; // fin_batch_select_options() + fin_resolve_batch() রিইউজ
admin_require_login();

$db = get_db();
$pageTitle = 'রিপোর্ট / ফলাফল';

const REPORT_MAX_MB = 10;

$ready   = report_reports_ready($db);
$canEdit = admin_is_super() || admin_can('reports', 'edit');
$canDel  = admin_is_super() || admin_can('reports', 'delete');

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না — আবার চেষ্টা করুন।');
        redirect('reports.php');
    }
    if (!$ready) {
        set_flash('error', 'আগে SQL মাইগ্রেশন চালাতে হবে (নিচের হলুদ ইঙ্গিত দেখুন)।');
        redirect('reports.php');
    }
    $action = $_POST['action'] ?? '';

    // ── আপলোড ──
    if ($action === 'upload') {
        $phoneRaw = trim((string) ($_POST['phone'] ?? ''));
        $phone    = bd_phone_canonical($phoneRaw);
        $student  = trim((string) ($_POST['student_name'] ?? ''));
        $title    = trim((string) ($_POST['title'] ?? ''));
        $batchId  = (int) ($_POST['item_batch'] ?? 0);

        $err = '';
        if (strlen($phone) !== 11 || $phone[0] !== '0') { $err = 'মোবাইল নম্বরটি সঠিক নয় (01XXXXXXXXX দিন)।'; }
        elseif ($student === '')                        { $err = 'শিক্ষার্থীর নাম দিন।'; }
        elseif ($title === '')                          { $err = 'রিপোর্টের নাম দিন (যেমন "বার্ষিক রিপোর্ট কার্ড")।'; }

        $file = $_FILES['pdf'] ?? null;
        if (!$err) {
            if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $err = 'PDF ফাইল বাছাই করুন।';
            } elseif (($file['size'] ?? 0) > REPORT_MAX_MB * 1024 * 1024) {
                $err = 'ফাইল খুব বড় (সর্বোচ্চ ' . REPORT_MAX_MB . ' MB)।';
            } else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : '';
                if ($finfo) { finfo_close($finfo); }
                if ($mime !== 'application/pdf') { $err = 'শুধু PDF ফাইল আপলোড করা যাবে।'; }
            }
        }

        if ($err) {
            set_flash('error', $err);
            redirect('reports.php');
        }

        // কোর্স/ব্যাচ স্ন্যাপশট — DB থেকে যাচাই (POST-এর নামে ভরসা নয়), ঐচ্ছিক
        $snap = fin_resolve_batch($db, $batchId);

        // ফাইল সেভ — র‍্যান্ডম নাম (মূল নাম guess করা যাবে না)
        $dir  = report_upload_dir();
        $name = 'rep-' . date('Ymd') . '-' . bin2hex(random_bytes(10)) . '.pdf';
        $dest = $dir . DIRECTORY_SEPARATOR . $name;
        if (!@move_uploaded_file($file['tmp_name'], $dest)) {
            set_flash('error', 'ফাইল সেভ করা গেল না — আবার চেষ্টা করুন।');
            redirect('reports.php');
        }
        @chmod($dest, 0644);

        $origName = (string) ($file['name'] ?? 'report.pdf');
        $origName = preg_replace('/[^\x20-\x7E\x{0980}-\x{09FF}._-]/u', '', $origName);
        $origName = mb_substr($origName !== '' ? $origName : 'report.pdf', 0, 200);

        try {
            $db->prepare(
                'INSERT INTO student_reports (phone, student_name, title, item_id, item_title, batch, file_path, file_name, uploaded_by)
                 VALUES (:ph, :st, :ti, :iid, :it, :ba, :fp, :fn, :by)'
            )->execute([
                'ph'  => $phone,
                'st'  => mb_substr($student, 0, 150),
                'ti'  => mb_substr($title, 0, 200),
                'iid' => $snap['item_id'],
                'it'  => (string) ($snap['item_title'] ?? ''),
                'ba'  => (string) ($snap['batch'] ?? ''),
                'fp'  => 'uploads/reports/' . $name,
                'fn'  => $origName,
                'by'  => $_SESSION['admin_username'] ?? '',
            ]);
            set_flash('success', '✅ রিপোর্ট আপলোড হয়েছে — ' . $student . ' (' . $phone . ')। অভিভাবক লগইন করে দেখতে পারবেন।');
        } catch (Throwable $e) {
            @unlink($dest); // DB-তে না বসলে ফাইলও রাখব না (অনাথ ফাইল এড়াতে)
            set_flash('error', 'সেভ করা গেল না।');
        }
        redirect('reports.php');
    }

    // ── ডিলিট ── (আর্কাইভে যায় — পরে ফেরানো যায়; ফাইল রাখা হয় যাতে রিস্টোরে কাজ করে)
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try { require_once __DIR__ . '/includes/archive.php'; archive_entity($db, 'student_reports', $id); } catch (Throwable $e) {}
            $db->prepare('DELETE FROM student_reports WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'রিপোর্টটি আর্কাইভে সরানো হয়েছে।');
        }
        redirect('reports.php');
    }

    redirect('reports.php');
}

// ---------------- তালিকা ----------------
$rows = [];
if ($ready) {
    try {
        $rows = $db->query(
            'SELECT id, phone, student_name, title, item_title, batch, file_name, uploaded_by, created_at
             FROM student_reports ORDER BY created_at DESC, id DESC LIMIT 500'
        )->fetchAll();
    } catch (Throwable $e) { $rows = []; }
}

require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-sm text-amber-800">
    <strong>⚠️ একটা SQL চালানো বাকি।</strong> রিপোর্ট সিস্টেম চালু হতে phpMyAdmin-এ
    <code class="bg-amber-100 px-1 rounded">database/migrate-student-reports.sql</code> চালাতে হবে।
    (সাইডবার → সেটিংস → <a href="system-check.php" class="underline font-semibold">সিস্টেম চেক</a> পাতাতেও দেখতে পাবেন কোন SQL বাকি।)
</div>
<?php endif; ?>

<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-5 text-sm text-blue-800 flex items-start gap-2">
    <i data-lucide="info" class="w-5 h-5 shrink-0 mt-0.5"></i>
    <div>অভিভাবক যে <strong>মোবাইল নম্বরে লগইন করেন</strong> (= রেজিস্ট্রেশনের নম্বর) ঠিক সেই নম্বরেই রিপোর্ট আপলোড করুন —
    তবেই তিনি নিজের অ্যাকাউন্টে রিপোর্টটি দেখতে পাবেন। PDF সরাসরি লিংক দিয়ে কেউ খুলতে পারবে না; শুধু ঐ অভিভাবক লগইন করে দেখবেন।</div>
</div>

<?php if ($canEdit && $ready): ?>
<form method="post" enctype="multipart/form-data" class="bg-white rounded-2xl shadow p-5 mb-6" onsubmit="return repValidate(this)">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <h3 class="font-bold text-gray-800 mb-4 flex items-center gap-2"><i data-lucide="upload" class="w-5 h-5 text-indigo-500"></i> নতুন রিপোর্ট আপলোড</h3>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">মোবাইল নম্বর * <span class="text-gray-400 font-normal">(অভিভাবক যেটায় লগইন করেন)</span></label>
            <input type="text" name="phone" inputmode="numeric" placeholder="01XXXXXXXXX" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">শিক্ষার্থীর নাম *</label>
            <input type="text" name="student_name" placeholder="শিশুর পুরো নাম" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">রিপোর্টের নাম *</label>
            <input type="text" name="title" placeholder="যেমন: বার্ষিক রিপোর্ট কার্ড" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">কোর্স / ব্যাচ <span class="text-gray-400 font-normal">(ঐচ্ছিক)</span></label>
            <select name="item_batch" data-picker class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="0">— কোর্স/ব্যাচ (ঐচ্ছিক) —</option>
                <?= fin_batch_select_options(0, $db) ?>
            </select>
        </div>
    </div>

    <div class="mt-4">
        <label class="block text-xs font-semibold text-gray-600 mb-1">PDF ফাইল * <span class="text-gray-400 font-normal">(শুধু PDF, সর্বোচ্চ <?= REPORT_MAX_MB ?> MB)</span></label>
        <input type="file" name="pdf" accept="application/pdf,.pdf" class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50">
    </div>

    <div class="mt-4">
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm inline-flex items-center gap-2">
            <i data-lucide="upload" class="w-4 h-4"></i> আপলোড করুন
        </button>
    </div>
</form>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিক্ষার্থী / মোবাইল</th>
                <th class="py-3 px-4">রিপোর্ট</th>
                <th class="py-3 px-4">কোর্স / ব্যাচ</th>
                <th class="py-3 px-4">আপলোড</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-8 px-4 text-center text-gray-400">এখনো কোনো রিপোর্ট আপলোড করা হয়নি।</td></tr>
        <?php else: foreach ($rows as $r): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-3 px-4" data-label="শিক্ষার্থী / মোবাইল">
                    <div class="font-semibold text-gray-800"><?= e($r['student_name'] ?: '—') ?></div>
                    <div class="text-xs text-gray-500"><?= e($r['phone']) ?></div>
                </td>
                <td class="py-3 px-4" data-label="রিপোর্ট">
                    <div class="text-gray-700"><?= e($r['title'] ?: 'রিপোর্ট') ?></div>
                    <div class="text-xs text-gray-400 break-all"><?= e($r['file_name']) ?></div>
                </td>
                <td class="py-3 px-4 text-gray-600" data-label="কোর্স / ব্যাচ">
                    <?php if ($r['item_title'] || $r['batch']): ?>
                        <?= e($r['item_title']) ?><?php if ($r['batch']): ?> <span class="text-gray-400">·</span> <?= e($r['batch']) ?><?php endif; ?>
                    <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                </td>
                <td class="py-3 px-4 text-xs text-gray-500" data-label="আপলোড">
                    <?= e(date('d M Y', strtotime((string) $r['created_at']))) ?>
                    <?php if ($r['uploaded_by']): ?><div class="text-gray-400"><?= e($r['uploaded_by']) ?></div><?php endif; ?>
                </td>
                <td class="py-3 px-4" data-label="অ্যাকশন">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="../report-view.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener"
                           class="text-indigo-600 hover:underline text-xs font-semibold inline-flex items-center gap-1">
                            <i data-lucide="eye" class="w-3.5 h-3.5"></i> দেখুন
                        </a>
                        <?php if ($canDel): ?>
                        <form method="post" class="inline" onsubmit="return confirmSubmit(this, 'এই রিপোর্টটি আর্কাইভে সরাবেন?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="text-red-600 hover:underline text-xs font-semibold inline-flex items-center gap-1">
                                <i data-lucide="trash-2" class="w-3.5 h-3.5"></i> মুছুন
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
function repValidate(f) {
    var ph = (f.phone.value || '').replace(/[^0-9]/g, '');
    if (ph.length < 10) { alert('সঠিক মোবাইল নম্বর দিন।'); return false; }
    if (!(f.student_name.value || '').trim()) { alert('শিক্ষার্থীর নাম দিন।'); return false; }
    if (!(f.title.value || '').trim()) { alert('রিপোর্টের নাম দিন।'); return false; }
    if (!f.pdf.files || !f.pdf.files.length) { alert('PDF ফাইল বাছাই করুন।'); return false; }
    return true;
}
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
