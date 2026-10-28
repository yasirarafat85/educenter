<?php
// ─────────────────────────────────────────────────────────────
// 📌 করণীয় / রিকোয়ারমেন্ট ইনবক্স (2026-10-28)
//
// WhatsApp গ্রুপে স্ক্রিনশট রেখে দেওয়ার বিকল্প — সব খোলা করণীয় এক পর্দায়, ✅ হয়ে গেলে
// টিক দিলে কে/কখন করল জমা থাকে। সম্পূর্ণ আলাদা স্তর — টাকা/খাতা/আয় কিছুই ছোঁয় না।
//
// 🔴 পাতাটা নিজে শুধু টেবিল-গার্ডে চলে (task_table_ready) — অ্যাডমিন সেটিংসে ফিচার "বন্ধ"
//    করলেও সরাসরি URL দিয়ে এসে আবার চালু করা যায়; সর্বত্র দেখানো (nav/ব্যাজ/ড্যাশবোর্ড)
//    task_feature_ready()-তে গার্ডেড।
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upload.php';   // handle_image_upload() — ঐচ্ছিক স্ক্রিনশট
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/nav.php';          // nav_safe_return() — POST হ্যান্ডলারে লাগে (layout-top অনেক পরে লোড হয়)
require_once __DIR__ . '/includes/finance-link.php'; // fin_batch_select_options() + fin_resolve_batch() রিইউজ
require_once __DIR__ . '/includes/payments.php';      // task_month_label() → pay_month_label()-এর সাথে মিলবে
admin_require_login();

$db = get_db();
$pageTitle = 'করণীয় ইনবক্স';

const TASK_IMG_MAX_MB = 4;
const TASK_MAX_MONTH  = 12;

$ready   = task_table_ready($db);
$canEdit = admin_is_super() || admin_can('tasks', 'edit');
$canDel  = admin_is_super() || admin_can('tasks', 'delete');
$presets = task_type_presets();

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না — আবার চেষ্টা করুন।');
        redirect('tasks.php');
    }
    if (!$ready) {
        set_flash('error', 'আগে SQL মাইগ্রেশন চালাতে হবে (নিচের হলুদ ইঙ্গিত দেখুন)।');
        redirect('tasks.php');
    }
    $action = $_POST['action'] ?? '';
    $ret    = nav_safe_return((string) ($_POST['return_url'] ?? ''), 'tasks.php');

    // ── নতুন করণীয় ──
    if ($action === 'add') {
        if (!$canEdit) {
            set_flash('error', 'এই কাজের অনুমতি নেই।');
            redirect($ret);
        }
        $regId   = (int) ($_POST['registration_id'] ?? 0);
        $type    = mb_substr(trim((string) ($_POST['type_key'] ?? '')), 0, 80);
        $note    = trim((string) ($_POST['note'] ?? ''));
        $month   = (int) ($_POST['target_month'] ?? 0);
        if ($month < 1 || $month > TASK_MAX_MONTH) { $month = 0; }
        if ($type === '') { $type = $presets[0] ?? 'অন্যান্য'; }

        // 🔴 অর্ডারের সাথে জোড়া থাকলে নাম/ফোন/কোর্স সব DB থেকে (POST-এর নাম/নম্বরে ভরসা নয়,
        //    move-course/reports-এর হুবহু নীতি); নাহলে হাতে দেওয়া নাম/ফোন + পিকারের কোর্স-ব্যাচ।
        $pName = ''; $pPhone = ''; $snap = ['item_id' => null, 'item_title' => '', 'batch' => ''];
        if ($regId > 0) {
            try {
                $st = $db->prepare('SELECT customer_name, phone, item_id, item_title, batch, type FROM registrations WHERE id = :id');
                $st->execute(['id' => $regId]);
                $reg = $st->fetch();
            } catch (Throwable $e) { $reg = null; }
            if (!$reg) {
                set_flash('error', 'অর্ডারটি খুঁজে পাওয়া যায়নি।');
                redirect('tasks.php');
            }
            $pName  = trim((string) $reg['customer_name']);
            $pPhone = bd_phone_canonical((string) $reg['phone']);
            if (($reg['type'] ?? '') === 'course') {
                $snap = [
                    'item_id'    => $reg['item_id'] !== null ? (int) $reg['item_id'] : null,
                    'item_title' => (string) ($reg['item_title'] ?? ''),
                    'batch'      => (string) ($reg['batch'] ?? ''),
                ];
            }
        } else {
            $pName  = mb_substr(trim((string) ($_POST['person_name'] ?? '')), 0, 150);
            $pPhone = bd_phone_canonical(trim((string) ($_POST['person_phone'] ?? '')));
            $batchId = (int) ($_POST['item_batch'] ?? 0);
            if ($batchId > 0) { $snap = fin_resolve_batch($db, $batchId); }
            if ($pName === '') {
                set_flash('error', 'কার জন্য করণীয় — নাম দিন (অথবা অর্ডার থেকে যোগ করুন)।');
                redirect('tasks.php');
            }
        }

        // ঐচ্ছিক স্ক্রিনশট — uploads/tasks/ (deny-all) নিশ্চিত করে তবেই আপলোড; ছোট করে WebP।
        $imgPath = '';
        try {
            task_upload_dir();
            $up = handle_image_upload('task_image', 'tasks', false, 1200, 1200);
            if ($up) { $imgPath = $up; }
        } catch (RuntimeException $e) {
            set_flash('error', $e->getMessage());
            redirect('tasks.php');
        }

        try {
            $db->prepare(
                'INSERT INTO registration_tasks
                   (registration_id, person_name, person_phone, item_id, item_title, batch,
                    type_key, note, target_month, image_path, status, created_by)
                 VALUES (:rid, :pn, :pp, :iid, :it, :ba, :tk, :nt, :mo, :im, :st, :by)'
            )->execute([
                'rid' => $regId > 0 ? $regId : null,
                'pn'  => $pName,
                'pp'  => $pPhone,
                'iid' => $snap['item_id'],
                'it'  => (string) ($snap['item_title'] ?? ''),
                'ba'  => (string) ($snap['batch'] ?? ''),
                'tk'  => $type,
                'nt'  => $note !== '' ? $note : null,
                'mo'  => $month > 0 ? $month : null,
                'im'  => $imgPath,
                'st'  => 'open',
                'by'  => $_SESSION['admin_username'] ?? '',
            ]);
            set_flash('success', '✅ করণীয় যোগ হয়েছে — ' . $pName . '।');
        } catch (Throwable $e) {
            if ($imgPath !== '') {
                $abs = task_image_abs_path($imgPath);
                if ($abs !== '') { @unlink($abs); } // DB-তে না বসলে ছবিও রাখব না
            }
            set_flash('error', 'সেভ করা গেল না।');
        }
        redirect($ret);
    }

    // ── ✅ হয়ে গেছে / ↩ আবার খোলা ──
    if ($action === 'done' || $action === 'reopen') {
        if (!$canEdit) {
            set_flash('error', 'এই কাজের অনুমতি নেই।');
            redirect($ret);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                if ($action === 'done') {
                    $db->prepare("UPDATE registration_tasks SET status='done', done_by=:by, done_at=NOW() WHERE id=:id")
                       ->execute(['by' => $_SESSION['admin_username'] ?? '', 'id' => $id]);
                    set_flash('success', '✅ করণীয়টি সম্পন্ন হিসেবে চিহ্নিত হয়েছে।');
                } else {
                    $db->prepare("UPDATE registration_tasks SET status='open', done_by='', done_at=NULL WHERE id=:id")
                       ->execute(['id' => $id]);
                    set_flash('success', '↩ করণীয়টি আবার খোলা হয়েছে।');
                }
            } catch (Throwable $e) {}
        }
        redirect($ret);
    }

    // ── ডিলিট (আর্কাইভে যায় — পরে ফেরানো যায়) ──
    if ($action === 'delete') {
        if (!$canDel) {
            set_flash('error', 'ডিলিটের অনুমতি নেই।');
            redirect($ret);
        }
        $id = (int) ($_POST['id'] ?? 0);
        if ($id > 0) {
            try { require_once __DIR__ . '/includes/archive.php'; archive_entity($db, 'registration_tasks', $id); } catch (Throwable $e) {}
            try { $db->prepare('DELETE FROM registration_tasks WHERE id = :id')->execute(['id' => $id]); } catch (Throwable $e) {}
            set_flash('success', 'করণীয়টি আর্কাইভে সরানো হয়েছে।');
        }
        redirect($ret);
    }

    redirect('tasks.php');
}

// ---------------- ফিল্টার ----------------
$status = (($_GET['status'] ?? 'open') === 'done') ? 'done' : 'open';
$fItem  = (int) ($_GET['item'] ?? 0);
$fMonth = (int) ($_GET['month'] ?? 0);
$fType  = trim((string) ($_GET['type'] ?? ''));
$fReg   = (int) ($_GET['reg'] ?? 0);
$q      = trim((string) ($_GET['q'] ?? ''));

$activeFilters = array_filter([
    'status' => $status !== 'open' ? $status : null,
    'item'   => $fItem ?: null,
    'month'  => $fMonth ?: null,
    'type'   => $fType !== '' ? $fType : null,
    'reg'    => $fReg ?: null,
    'q'      => $q !== '' ? $q : null,
], fn($v) => $v !== null);

function tasks_url(array $over = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $over), fn($v) => $v !== null && $v !== '');
    return 'tasks.php' . ($p ? '?' . http_build_query($p) : '');
}

// অর্ডার থেকে এসে থাকলে তার স্ন্যাপশট (তালিকা ঐ অর্ডারে সীমাবদ্ধ + যোগ-ফর্ম প্রি-ফিল)
$regInfo = null;
if ($ready && $fReg > 0) {
    try {
        $st = $db->prepare('SELECT id, customer_name, phone, item_title, batch, type FROM registrations WHERE id = :id');
        $st->execute(['id' => $fReg]);
        $regInfo = $st->fetch() ?: null;
    } catch (Throwable $e) { $regInfo = null; }
}

// তালিকা
$rows = [];
$openTotal = $doneTotal = $linkedOpen = 0;
$courseOpts = [];
if ($ready) {
    try {
        $where = ['status = :st'];
        $params = ['st' => $status];
        if ($fReg > 0)    { $where[] = 'registration_id = :rid'; $params['rid'] = $fReg; }
        if ($fItem > 0)   { $where[] = 'item_id = :iid';        $params['iid'] = $fItem; }
        if ($fMonth > 0)  { $where[] = 'target_month = :mo';    $params['mo']  = $fMonth; }
        if ($fType !== '') { $where[] = 'type_key = :tk';       $params['tk']  = $fType; }
        if ($q !== '') {
            // নাম/ফোন/নোট — এক CONCAT_WS গাদায় এক প্লেসহোল্ডার (HY093 এড়াতে), %/_ escape
            $where[] = "CONCAT_WS(' ', person_name, person_phone, item_title, batch, note) LIKE :q ESCAPE '!'";
            $esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
            $params['q'] = '%' . $esc . '%';
        }
        $sql = 'SELECT * FROM registration_tasks WHERE ' . implode(' AND ', $where)
             . ' ORDER BY created_at DESC, id DESC LIMIT 500';
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        // সারাংশ (ফিল্টার-নিরপেক্ষ মোট, try/catch আলাদা নয় — একই ব্লকে)
        $openTotal  = (int) $db->query("SELECT COUNT(*) c FROM registration_tasks WHERE status='open'")->fetch()['c'];
        $doneTotal  = (int) $db->query("SELECT COUNT(*) c FROM registration_tasks WHERE status='done'")->fetch()['c'];
        $linkedOpen = (int) $db->query("SELECT COUNT(*) c FROM registration_tasks WHERE status='open' AND registration_id IS NOT NULL")->fetch()['c'];

        // কোর্স ফিল্টার অপশন — শুধু যেসব কোর্সে করণীয় আছে
        foreach ($db->query(
            "SELECT item_id, MAX(item_title) it, MAX(batch) ba FROM registration_tasks
              WHERE item_id IS NOT NULL AND item_title <> '' GROUP BY item_id ORDER BY it"
        )->fetchAll() as $r) {
            $courseOpts[(int) $r['item_id']] = trim($r['it'] . ($r['ba'] ? ' · ' . $r['ba'] : ''));
        }
    } catch (Throwable $e) {
        $rows = [];
    }
}

$currentUrl = tasks_url();

require __DIR__ . '/includes/layout-top.php';
?>

<style>
/* 📌 করণীয় ইনবক্স — সাধারণ CSS (Tailwind রিবিল্ড লাগে না) */
.tk-tiles{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px;}
@media(max-width:600px){.tk-tiles{grid-template-columns:1fr;}}
.tk-card{position:relative;border:1px solid var(--c-border,#e5e7eb);border-radius:16px;padding:14px 16px 14px 20px;overflow:hidden;}
.tk-card .stripe{position:absolute;left:0;top:0;bottom:0;width:6px;}
.tk-done{opacity:.62;}
.tk-chip{font-size:.74rem;font-weight:700;padding:3px 11px;border-radius:999px;display:inline-block;}
.tk-shot{width:84px;height:100px;flex:0 0 auto;border-radius:10px;object-fit:cover;border:1px solid var(--c-border,#e5e7eb);background:#f3f4f6;}
@media(max-width:480px){.tk-shot{width:100%;height:120px;}}
.tk-body{display:flex;gap:14px;}
.tk-main{flex:1;min-width:0;}
</style>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-sm text-amber-800">
    <strong>⚠️ একটা SQL চালানো বাকি।</strong> করণীয় ইনবক্স চালু হতে phpMyAdmin-এ
    <code class="bg-amber-100 px-1 rounded">database/migrate-registration-tasks.sql</code> চালাতে হবে।
    (সাইডবার → সেটিংস → <a href="system-check.php" class="underline font-semibold">সিস্টেম চেক</a> পাতাতেও দেখতে পাবেন কোন SQL বাকি।)
</div>
<?php endif; ?>

<?php if ($ready && !task_feature_switch_on()): ?>
<div class="bg-gray-100 border border-gray-200 rounded-2xl p-4 mb-5 text-sm text-gray-600">
    ℹ️ করণীয় ইনবক্স এখন <strong>বন্ধ</strong> রাখা আছে (সাইডবার থেকে লুকানো) — ডেটা নিরাপদ আছে।
    আবার চালু করতে সাইডবার → সেটিংস → "করণীয় ইনবক্স" গ্রুপে <code>১</code> দিন।
</div>
<?php endif; ?>

<div class="bg-blue-50 border border-blue-200 rounded-2xl p-4 mb-5 text-sm text-blue-800 flex items-start gap-2">
    <i data-lucide="info" class="w-5 h-5 shrink-0 mt-0.5"></i>
    <div>কারও ঠিকানা বদল, পার্সেল পরে যাবে, টাকা পরে দিবে, দুই সেট — এমন ছোট রিকোয়ারমেন্ট এখানে লিখে রাখুন।
    হয়ে গেলে <strong>✅ হয়ে গেছে</strong> টিক দিন। 🔴 এখান থেকে টাকা/খাতা কিছুই অটো বদলায় না — এটা শুধু মনে রাখার তালিকা।</div>
</div>

<!-- সারাংশ টাইল -->
<div class="tk-tiles">
    <a href="<?= e(tasks_url(['status' => null, 'reg' => $fReg ?: null])) ?>" class="bg-white rounded-2xl shadow p-4 block hover:shadow-md transition">
        <div class="text-xs text-gray-500 font-semibold">🔵 খোলা করণীয়</div>
        <div class="text-3xl font-extrabold text-indigo-600"><?= $openTotal ?></div>
    </a>
    <a href="<?= e(tasks_url(['status' => 'done', 'reg' => $fReg ?: null])) ?>" class="bg-white rounded-2xl shadow p-4 block hover:shadow-md transition">
        <div class="text-xs text-gray-500 font-semibold">🟢 সম্পন্ন (মোট)</div>
        <div class="text-3xl font-extrabold text-emerald-600"><?= $doneTotal ?></div>
    </a>
    <div class="bg-white rounded-2xl shadow p-4">
        <div class="text-xs text-gray-500 font-semibold">🔗 অর্ডারের সাথে জোড়া (খোলা)</div>
        <div class="text-3xl font-extrabold text-gray-700"><?= $linkedOpen ?></div>
    </div>
</div>

<?php if ($ready && $canEdit): ?>
<!-- নতুন করণীয় -->
<form method="post" enctype="multipart/form-data" class="bg-white rounded-2xl shadow p-5 mb-6 space-y-4" id="tasksAddForm">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
    <h3 class="font-bold text-gray-800 flex items-center gap-2"><i data-lucide="plus-circle" class="w-5 h-5 text-indigo-500"></i> নতুন করণীয় যোগ করুন</h3>

    <?php if ($regInfo): ?>
        <input type="hidden" name="registration_id" value="<?= (int) $regInfo['id'] ?>">
        <div class="bg-indigo-50 border border-indigo-100 rounded-xl p-3 text-sm">
            এই অর্ডারের জন্য: <strong><?= e($regInfo['customer_name'] ?: '—') ?></strong>
            <?php if ($regInfo['item_title']): ?><span class="text-gray-400">·</span> <?= e($regInfo['item_title']) ?><?php if ($regInfo['batch']): ?> · <?= e($regInfo['batch']) ?><?php endif; ?><?php endif; ?>
            <span class="text-gray-400">·</span> <?= e($regInfo['phone']) ?>
            <a href="<?= e(tasks_url(['reg' => null])) ?>" class="text-indigo-600 hover:underline ml-2">(অন্য কারও জন্য)</a>
        </div>
    <?php else: ?>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">কার জন্য — নাম *</label>
                <input type="text" name="person_name" placeholder="অভিভাবক/শিশুর নাম" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-600 mb-1">মোবাইল (ঐচ্ছিক)</label>
                <input type="text" name="person_phone" inputmode="numeric" placeholder="01XXXXXXXXX" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0">
            </div>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">কোর্স / ব্যাচ (ঐচ্ছিক)</label>
            <select name="item_batch" data-picker class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="0">— কোর্স / ব্যাচ বেছে নিন (ঐচ্ছিক) —</option>
                <?= fin_batch_select_options(0, $db) ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">💡 অর্ডারের তালিকা থেকে "📌 করণীয়" লিংকে এলে নাম/কোর্স নিজে থেকেই বসে।</p>
        </div>
    <?php endif; ?>

    <div class="grid sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">ধরন *</label>
            <select name="type_key" class="w-full border rounded-lg px-3 py-2 text-sm">
                <?php foreach ($presets as $p): ?>
                    <option value="<?= e($p) ?>"><?= e($p) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-600 mb-1">কোন মাসে? (ঐচ্ছিক)</label>
            <select name="target_month" class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="0">— স্থায়ী / সব মাসে —</option>
                <?php for ($m = 1; $m <= TASK_MAX_MONTH; $m++): ?>
                    <option value="<?= $m ?>"><?= e(task_month_label($m)) ?></option>
                <?php endfor; ?>
            </select>
        </div>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">বিস্তারিত নোট</label>
        <textarea name="note" rows="2" placeholder="যেমন: বাসা বদল হচ্ছে, পরের মাসের পার্সেলের সাথে পাঠাতে।" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0"></textarea>
    </div>

    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">📸 স্ক্রিনশট / ছবি (ঐচ্ছিক) <span class="text-gray-400 font-normal">(শুধু অ্যাডমিন দেখবে, সর্বোচ্চ <?= TASK_IMG_MAX_MB ?> MB)</span></label>
        <input type="file" name="task_image" accept="image/*" class="w-full border rounded-lg px-3 py-2 text-sm bg-gray-50" style="max-width:100%">
    </div>

    <div>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm inline-flex items-center gap-2">
            <i data-lucide="plus" class="w-4 h-4"></i> করণীয় যোগ করুন
        </button>
    </div>
</form>
<?php endif; ?>

<!-- ফিল্টার -->
<form method="get" id="tasksFilterForm" class="bg-white rounded-2xl shadow p-4 mb-5 flex flex-wrap gap-3 items-end">
    <?php if ($fReg > 0): ?><input type="hidden" name="reg" value="<?= $fReg ?>"><?php endif; ?>
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <div class="flex-1" style="min-width:160px">
        <label class="block text-xs font-semibold text-gray-600 mb-1">খুঁজুন (নাম / নম্বর / নোট)</label>
        <input type="search" name="q" value="<?= e($q) ?>" data-autosearch="tasksFilterForm" class="list-search w-full border rounded-lg px-3 py-2 text-sm" placeholder="লিখে Enter দিন" autocomplete="off" style="min-width:0">
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">কোর্স</label>
        <select name="item" onchange="this.form.submit()" class="border rounded-lg px-3 py-2 text-sm">
            <option value="0">সব কোর্স</option>
            <?php foreach ($courseOpts as $iid => $lbl): ?>
                <option value="<?= $iid ?>" <?= $fItem === $iid ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">ধরন</label>
        <select name="type" onchange="this.form.submit()" class="border rounded-lg px-3 py-2 text-sm">
            <option value="">সব ধরন</option>
            <?php foreach ($presets as $p): ?>
                <option value="<?= e($p) ?>" <?= $fType === $p ? 'selected' : '' ?>><?= e($p) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-xs font-semibold text-gray-600 mb-1">মাস</label>
        <select name="month" onchange="this.form.submit()" class="border rounded-lg px-3 py-2 text-sm">
            <option value="0">সব</option>
            <?php for ($m = 1; $m <= TASK_MAX_MONTH; $m++): ?>
                <option value="<?= $m ?>" <?= $fMonth === $m ? 'selected' : '' ?>><?= e(task_month_label($m)) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div>
        <button type="submit" class="bg-gray-700 hover:bg-gray-800 text-white font-semibold px-4 py-2 rounded-lg text-sm">🔍 খুঁজুন</button>
    </div>
    <?php if ($activeFilters && ($fItem || $fType !== '' || $fMonth || $q !== '')): ?>
        <a href="<?= e(tasks_url(['item' => null, 'type' => null, 'month' => null, 'q' => null])) ?>" class="text-sm text-gray-500 hover:underline py-2">✕ ফিল্টার মুছুন</a>
    <?php endif; ?>
</form>

<!-- স্ট্যাটাস ট্যাব (ফর্মের বাইরে — বাকি ফিল্টার বয়ে নেয়) -->
<div class="flex gap-2 mb-4">
    <a href="<?= e(tasks_url(['status' => null])) ?>" class="px-4 py-1.5 rounded-full text-sm font-semibold <?= $status === 'open' ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600' ?>">🔵 খোলা</a>
    <a href="<?= e(tasks_url(['status' => 'done'])) ?>" class="px-4 py-1.5 rounded-full text-sm font-semibold <?= $status === 'done' ? 'bg-indigo-600 text-white' : 'bg-white border text-gray-600' ?>">🟢 সম্পন্ন</a>
</div>

<!-- তালিকা -->
<div class="space-y-3">
<?php if (!$rows): ?>
    <div class="bg-white rounded-2xl shadow p-8 text-center text-gray-400">
        <?= $status === 'open' ? 'এখন কোনো খোলা করণীয় নেই। 🎉' : 'কোনো সম্পন্ন করণীয় নেই।' ?>
    </div>
<?php else: foreach ($rows as $r):
    [$tint, $deep] = task_type_color((string) $r['type_key']);
    $isDone  = ($r['status'] ?? '') === 'done';
    $mLabel  = task_month_label($r['target_month'] !== null ? (int) $r['target_month'] : 0);
    $regLink = $r['registration_id'] ? 'registrations.php?action=view&id=' . (int) $r['registration_id'] : '';
?>
    <div class="tk-card bg-white shadow <?= $isDone ? 'tk-done' : '' ?>" style="background:<?= $isDone ? '#fff' : e($tint) ?>;">
        <span class="stripe" style="background:<?= e($deep) ?>;"></span>
        <div class="tk-body">
            <?php if ($r['image_path']): ?>
                <a href="task-image.php?id=<?= (int) $r['id'] ?>" target="_blank" rel="noopener" title="বড় করে দেখুন">
                    <img src="task-image.php?id=<?= (int) $r['id'] ?>" class="tk-shot" alt="স্ক্রিনশট" loading="lazy">
                </a>
            <?php endif; ?>
            <div class="tk-main">
                <div class="flex items-start gap-2 flex-wrap">
                    <span class="font-bold text-gray-800"><?= e($r['person_name'] ?: '—') ?></span>
                    <?php if ($r['item_title']): ?>
                        <span class="text-xs text-gray-500 bg-white border rounded-full px-2 py-0.5"><?= e($r['item_title']) ?><?php if ($r['batch']): ?> · <?= e($r['batch']) ?><?php endif; ?></span>
                    <?php endif; ?>
                    <span class="flex-1"></span>
                    <span class="tk-chip" style="background:<?= e($tint) ?>;color:<?= e($deep) ?>;"><?= e($r['type_key']) ?></span>
                </div>
                <?php if (trim((string) $r['note']) !== ''): ?>
                    <p class="text-sm text-gray-700 mt-2" style="white-space:pre-wrap;"><?= e($r['note']) ?></p>
                <?php endif; ?>
                <div class="flex flex-wrap gap-2 items-center mt-2 text-xs text-gray-500">
                    <?php if ($mLabel !== ''): ?><span class="font-semibold" style="color:<?= e($deep) ?>;background:<?= e($tint) ?>;padding:2px 9px;border-radius:999px;">📦 <?= e($mLabel) ?></span><?php endif; ?>
                    <?php if ($r['person_phone']): ?><a href="tel:<?= e($r['person_phone']) ?>" class="hover:underline">📞 <?= e($r['person_phone']) ?></a><?php endif; ?>
                    <?php if ($regLink): ?><a href="<?= e($regLink) ?>&return=<?= rawurlencode($currentUrl) ?>" class="text-indigo-600 hover:underline">অর্ডার দেখুন →</a><?php endif; ?>
                    <?php if ($isDone): ?>
                        <span class="text-emerald-600 font-semibold">✅ <?= e($r['done_by'] ?: 'সম্পন্ন') ?> · <?= e($r['done_at'] ? date('d M', strtotime((string) $r['done_at'])) : '') ?></span>
                    <?php else: ?>
                        <span>✍️ <?= e($r['created_by'] ?: '—') ?> · <?= e(date('d M', strtotime((string) $r['created_at']))) ?></span>
                    <?php endif; ?>
                    <span class="flex-1"></span>
                    <?php if ($canEdit): ?>
                        <form method="post" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <?php if ($isDone): ?>
                                <input type="hidden" name="action" value="reopen">
                                <button type="submit" class="text-gray-500 hover:underline font-semibold">↩ আবার খোলা</button>
                            <?php else: ?>
                                <input type="hidden" name="action" value="done">
                                <button type="submit" class="text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-lg px-3 py-1 font-bold">✅ হয়ে গেছে</button>
                            <?php endif; ?>
                        </form>
                    <?php endif; ?>
                    <?php if ($canDel): ?>
                        <form method="post" class="inline" onsubmit="return confirmSubmit(this, 'এই করণীয়টি আর্কাইভে সরাবেন?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="return_url" value="<?= e($currentUrl) ?>">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="text-red-500 hover:underline">✕ মুছুন</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
