<?php
// ─────────────────────────────────────────────────────────────
// 🗑️ তথ্য মুছে ফেলার অনুরোধ (২০২৬-১১-১৭) — শুধু মূল অ্যাডমিন (admin_super_only_pages)
//
// অনুরোধ এলে রেকর্ড (রেফারেন্স DEL-0007 + ৩০ দিনের সময়সীমা) → নম্বর দিয়ে খুঁজে দেখা →
// পরিচয় যাচাই করে এক চাপে মোছা → প্রমাণ হিসেবে কে/কখন/কোথা থেকে কতগুলো।
// ইঞ্জিন admin/includes/data-deletion.php। টাকার রেকর্ড মোছে না, শুধু নাম-নম্বর ঢাকে।
// 🔴 মোছা হয়ে গেলে অনুরোধের নিজের রেকর্ডেও নাম/নম্বর ঢেকে যায় (শুধু ছাপ থাকে)।
// ─────────────────────────────────────────────────────────────
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';          // task_image_abs_path() — করণীয়ের ছবি মুছতে
require_once __DIR__ . '/includes/data-deletion.php';
admin_require_login();

$db = get_db();
$pageTitle = 'তথ্য মুছে ফেলার অনুরোধ';
$ready = dd_ready($db);
$me    = (string) ($_SESSION['admin_username'] ?? '');

function dd_load(PDO $db, int $id): ?array
{
    $st = $db->prepare('SELECT * FROM data_deletion_requests WHERE id = :id');
    $st->execute(['id' => $id]);
    return $st->fetch() ?: null;
}

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না — আবার চেষ্টা করুন।');
        redirect('data-requests.php');
    }
    if (!$ready) {
        set_flash('error', 'আগে SQL মাইগ্রেশন চালাতে হবে (হলুদ ইঙ্গিত দেখুন)।');
        redirect('data-requests.php');
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $name  = mb_substr(trim((string) ($_POST['requester_name'] ?? '')), 0, 150);
        $phone = bd_phone_canonical(trim((string) ($_POST['phone'] ?? '')));
        $chan  = (string) ($_POST['channel'] ?? '');
        if (!isset(dd_channels()[$chan])) { $chan = 'other'; }
        $recv  = (string) ($_POST['received_on'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recv) || strtotime($recv) === false || strtotime($recv) > time()) {
            $recv = date('Y-m-d');
        }
        $note = mb_substr(trim((string) ($_POST['request_note'] ?? '')), 0, 2000);
        if (!is_valid_bd_phone($phone)) {
            set_flash('error', 'মোবাইল নম্বর সঠিক নয় — যে নম্বরে রেজিস্ট্রেশন হয়েছিল সেটা লিখুন।');
            redirect('data-requests.php');
        }
        $hash = dd_phone_hash($phone);
        $prev = $db->prepare("SELECT id FROM data_deletion_requests WHERE phone_hash = :h AND status = 'done' ORDER BY id DESC LIMIT 1");
        $prev->execute(['h' => $hash]);
        $prevId = (int) $prev->fetchColumn();

        $db->prepare('INSERT INTO data_deletion_requests (requester_name, phone, phone_hash, channel, request_note, received_on, created_by)
                      VALUES (:n, :p, :h, :c, :note, :r, :by)')
           ->execute(['n' => $name, 'p' => $phone, 'h' => $hash, 'c' => $chan, 'note' => $note, 'r' => $recv, 'by' => $me]);
        $id = (int) $db->lastInsertId();
        set_flash('success', 'অনুরোধ রেকর্ড হয়েছে — রেফারেন্স ' . dd_ref($id) . '। নিচের লেখাটা অভিভাবককে পাঠিয়ে দিন।'
            . ($prevId ? ' ℹ️ এই নম্বরের তথ্য আগেও একবার মোছা হয়েছিল (' . dd_ref($prevId) . ')।' : ''));
        redirect('data-requests.php?id=' . $id);
    }

    $id  = (int) ($_POST['id'] ?? 0);
    $req = $id > 0 ? dd_load($db, $id) : null;
    if (!$req) {
        set_flash('error', 'অনুরোধটি পাওয়া যায়নি।');
        redirect('data-requests.php');
    }
    if ($req['status'] !== 'new') {
        set_flash('error', 'এই অনুরোধের কাজ আগেই শেষ হয়ে গেছে।');
        redirect('data-requests.php?id=' . $id);
    }

    if ($action === 'reject') {
        $why = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 255);
        if ($why === '') {
            set_flash('error', 'বাতিলের কারণ লিখুন (যেমন: পরিচয় যাচাই করা যায়নি)।');
            redirect('data-requests.php?id=' . $id);
        }
        $db->prepare("UPDATE data_deletion_requests SET status = 'rejected', identity_note = :w, done_by = :by, done_at = NOW() WHERE id = :id AND status = 'new'")
           ->execute(['w' => $why, 'by' => $me, 'id' => $id]);
        set_flash('success', dd_ref($id) . ' বাতিল করা হলো — কিছুই মোছা হয়নি।');
        redirect('data-requests.php?id=' . $id);
    }

    if ($action === 'erase') {
        $idNote = mb_substr(trim((string) ($_POST['identity_note'] ?? '')), 0, 255);
        if ($idNote === '' || empty($_POST['confirm_identity'])) {
            set_flash('error', 'পরিচয় কীভাবে যাচাই করেছেন লিখুন এবং নিশ্চিতকরণের ঘরে টিক দিন।');
            redirect('data-requests.php?id=' . $id);
        }
        $phone   = (string) $req['phone'];
        $scan    = dd_scan($db, $phone);
        $res     = dd_erase($db, $phone);
        if (!$res['ok']) {
            set_flash('error', $res['err']);
            redirect('data-requests.php?id=' . $id);
        }
        // অভিভাবককে জানানোর WhatsApp লিংক এখনই বানাতে হয় — একটু পরেই নম্বরটা ঢেকে যাবে
        $wa = normalize_bd_whatsapp($phone);
        if ($wa !== '') {
            $_SESSION['dd_done_wa'][$id] = 'https://wa.me/' . $wa . '?text=' . rawurlencode(dd_message($req, 'done'));
        }
        $db->prepare("UPDATE data_deletion_requests
                         SET status = 'done', identity_note = :idn, result_json = :rj, done_by = :by, done_at = NOW(),
                             requester_name = :nm, phone = :ph, request_note = :note
                       WHERE id = :id AND status = 'new'")
           ->execute([
               'idn'  => $idNote,
               'rj'   => json_encode($res['counts'], JSON_UNESCAPED_UNICODE),
               'by'   => $me,
               'nm'   => dd_mask_name((string) $req['requester_name']),
               'ph'   => dd_mask_phone($phone),
               'note' => dd_scrub_text((string) $req['request_note'], array_merge($scan['needles'], [(string) $req['requester_name']]), $scan['last10']),
               'id'   => $id,
           ]);
        $total = array_sum($res['counts']);
        set_flash('success', dd_ref($id) . ' সম্পন্ন — মোট ' . $total . 'টি জায়গা থেকে তথ্য মোছা/ঢাকা হয়েছে। নিচের বোতাম দিয়ে অভিভাবককে জানিয়ে দিন।');
        redirect('data-requests.php?id=' . $id);
    }

    redirect('data-requests.php');
}

// ---------------- দেখা ----------------
$viewId  = (int) ($_GET['id'] ?? 0);
$view    = ($ready && $viewId > 0) ? dd_load($db, $viewId) : null;
$scan    = ($view && $view['status'] === 'new') ? dd_scan($db, (string) $view['phone']) : null;
$doneWa  = $view ? ($_SESSION['dd_done_wa'][$view['id']] ?? '') : '';
if ($doneWa !== '') { unset($_SESSION['dd_done_wa'][$view['id']]); }

$list = [];
$tile = ['new' => 0, 'late' => 0, 'done' => 0];
if ($ready && !$view) {
    $list = $db->query('SELECT * FROM data_deletion_requests ORDER BY (status = \'new\') DESC, received_on ASC, id DESC LIMIT 200')->fetchAll();
    foreach ($list as $r) {
        if ($r['status'] === 'new') {
            $tile['new']++;
            if (dd_days_left((string) $r['received_on']) < 0) { $tile['late']++; }
        } elseif ($r['status'] === 'done') {
            $tile['done']++;
        }
    }
}
$statusLabel = ['new' => ['চলমান', 'bg-amber-100 text-amber-800'], 'done' => ['মোছা হয়েছে', 'bg-green-100 text-green-800'], 'rejected' => ['বাতিল', 'bg-gray-100 text-gray-600']];

function dd_due_chip(array $r): string
{
    if ($r['status'] !== 'new') { return ''; }
    $left = dd_days_left((string) $r['received_on']);
    if ($left < 0) {
        return '<span class="dd-chip" style="background:#fee2e2;color:#991b1b">⏰ সময় পেরিয়েছে ' . abs($left) . ' দিন</span>';
    }
    $style = $left <= 5 ? 'background:#ffedd5;color:#9a3412' : 'background:#e0f2fe;color:#075985';
    return '<span class="dd-chip" style="' . $style . '">⏳ আর ' . $left . ' দিন বাকি</span>';
}

require __DIR__ . '/includes/layout-top.php';
?>
<style>
.dd-chip{display:inline-block;font-size:.75rem;font-weight:700;padding:3px 10px;border-radius:999px;}
.dd-tiles{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px;}
@media(max-width:520px){.dd-tiles{grid-template-columns:1fr;}}
.dd-msg{width:100%;min-width:0;font-size:.9rem;line-height:1.6;border:1px solid rgb(var(--c-border,229 231 235));border-radius:12px;padding:10px;background:rgb(var(--c-surface-2,249 250 251));}
.dd-place{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;padding:10px 0;border-bottom:1px dashed rgb(var(--c-border,229 231 235));}
.dd-place:last-child{border-bottom:0;}
.dd-row{min-width:0;overflow-wrap:anywhere;}
</style>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <h1 class="text-2xl font-bold text-gray-800">🗑️ তথ্য মুছে ফেলার অনুরোধ</h1>
    <?php if ($view): ?><a href="data-requests.php" class="text-sm text-indigo-600">← সব অনুরোধ</a><?php endif; ?>
</div>
<p class="text-sm text-gray-500 mb-5">গোপনীয়তা নীতি অনুযায়ী কেউ তাঁর তথ্য মুছে ফেলতে বললে এখানে রেকর্ড করুন, পরিচয় যাচাই করে মুছে দিন — কে, কখন, কোথা থেকে কতগুলো মুছলেন সবই প্রমাণ হিসেবে থেকে যায়। <strong>টাকার লেনদেন মোছা হয় না</strong> — শুধু নাম-নম্বর-ঠিকানা মুছে "(মুছে ফেলা হয়েছে)" বসে, তাই আয়ের হিসাব ঠিক থাকে।</p>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
        এই পাতা চালু করতে phpMyAdmin-এ <code>database/migrate-data-deletion.sql</code> চালান (সাইডবার → সেটিংস → সিস্টেম চেক-এ কপি করার বোতাম আছে)।
    </div>
<?php elseif ($view): ?>
    <?php [$sl, $sc] = $statusLabel[$view['status']] ?? [$view['status'], 'bg-gray-100 text-gray-600']; ?>
    <div class="bg-white rounded-2xl shadow p-5 mb-5">
        <div class="flex flex-wrap items-center gap-2 mb-3">
            <span class="text-xl font-bold text-gray-800"><?= e(dd_ref((int) $view['id'])) ?></span>
            <span class="dd-chip <?= $sc ?>"><?= e($sl) ?></span>
            <?= dd_due_chip($view) ?>
        </div>
        <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1 text-sm text-gray-700 dd-row">
            <div><span class="text-gray-500">নাম:</span> <?= e($view['requester_name'] ?: '—') ?></div>
            <div><span class="text-gray-500">মোবাইল:</span> <?= e($view['phone']) ?></div>
            <div><span class="text-gray-500">কোন পথে এসেছে:</span> <?= e(dd_channels()[$view['channel']] ?? $view['channel']) ?></div>
            <div><span class="text-gray-500">অনুরোধ পেয়েছেন:</span> <?= e(date('d M Y', strtotime($view['received_on']))) ?> · সময়সীমা <?= e(date('d M Y', strtotime(dd_due_date($view['received_on'])))) ?></div>
            <div><span class="text-gray-500">রেকর্ড করেছেন:</span> <?= e($view['created_by'] ?: '—') ?></div>
            <?php if ($view['done_at']): ?>
                <div><span class="text-gray-500"><?= $view['status'] === 'done' ? 'মুছেছেন' : 'বাতিল করেছেন' ?>:</span> <?= e($view['done_by']) ?> · <?= e(date('d M Y, h:i A', strtotime($view['done_at']))) ?></div>
            <?php endif; ?>
        </div>
        <?php if (trim((string) $view['request_note']) !== ''): ?>
            <p class="text-sm text-gray-600 mt-3 dd-row"><span class="text-gray-500">নোট:</span> <?= nl2br(e($view['request_note'])) ?></p>
        <?php endif; ?>
        <?php if ($view['identity_note'] !== ''): ?>
            <p class="text-sm text-gray-600 mt-2 dd-row"><span class="text-gray-500"><?= $view['status'] === 'done' ? 'পরিচয় যাচাই:' : 'কারণ:' ?></span> <?= e($view['identity_note']) ?></p>
        <?php endif; ?>
    </div>

    <?php
    // অভিভাবককে পাঠানোর লেখা
    $msgKind = $view['status'] === 'done' ? 'done' : ($view['status'] === 'new' ? 'ack' : '');
    if ($msgKind !== ''):
        $msg  = dd_message($view, $msgKind);
        $waNo = $view['status'] === 'new' ? normalize_bd_whatsapp((string) $view['phone']) : '';
        $wa   = $view['status'] === 'new' ? ($waNo !== '' ? 'https://wa.me/' . $waNo . '?text=' . rawurlencode($msg) : '') : $doneWa;
    ?>
    <div class="bg-white rounded-2xl shadow p-5 mb-5">
        <h2 class="font-bold text-gray-800 mb-2"><?= $msgKind === 'done' ? '✅ অভিভাবককে জানান — মুছে ফেলা হয়েছে' : '📨 অভিভাবককে জানান — অনুরোধ পেয়েছি' ?></h2>
        <textarea class="dd-msg" rows="4" readonly id="ddMsg"><?= e($msg) ?></textarea>
        <div class="flex flex-wrap gap-2 mt-3">
            <button type="button" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold" id="ddCopy">📋 লেখাটা কপি করুন</button>
            <?php if ($wa !== ''): ?>
                <a href="<?= e($wa) ?>" target="_blank" rel="noopener" class="px-4 py-2 rounded-xl bg-green-600 text-white text-sm font-semibold">💬 WhatsApp-এ পাঠান</a>
            <?php elseif ($msgKind === 'done'): ?>
                <span class="text-xs text-gray-500 self-center">নম্বরটা এখন মুছে ফেলা — যে পথে অনুরোধ এসেছিল সেখানেই লেখাটা পাঠান।</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($view['status'] === 'done'): ?>
        <?php $counts = json_decode((string) $view['result_json'], true) ?: []; ?>
        <div class="bg-white rounded-2xl shadow p-5 mb-5">
            <h2 class="font-bold text-gray-800 mb-2">🧾 প্রমাণ — কোথা থেকে কী মোছা হয়েছে</h2>
            <?php if (!$counts): ?>
                <p class="text-sm text-gray-500">সাইটে এই নম্বরের কোনো তথ্য পাওয়া যায়নি — তাই কিছু মুছতে হয়নি।</p>
            <?php else: ?>
                <?php foreach ($counts as $label => $n): ?>
                    <div class="dd-place text-sm"><span class="dd-row"><?= e($label) ?></span><b><?= (int) $n ?>টি</b></div>
                <?php endforeach; ?>
            <?php endif; ?>
            <p class="text-xs text-gray-500 mt-3">এই রেকর্ডেও নাম ও নম্বর ঢেকে রাখা হয়েছে। রোজকার ব্যাকআপে পুরনো কপি থাকলে সেটা ৭ দিনের মধ্যে নিজে থেকেই মুছে যায়।</p>
        </div>
    <?php elseif ($view['status'] === 'new' && $scan): ?>
        <div class="bg-white rounded-2xl shadow p-5 mb-5">
            <h2 class="font-bold text-gray-800 mb-1">🔎 এই নম্বরের তথ্য সাইটের কোথায় আছে</h2>
            <p class="text-xs text-gray-500 mb-3">কিছুই এখনো বদলায়নি। মোছার আগে দেখে নিন সঠিক মানুষ কিনা।</p>

            <?php if ($scan['regs']): ?>
                <h3 class="text-sm font-bold text-gray-700 mt-2">রেজিস্ট্রেশন/অর্ডার — নাম-নম্বর মুছবে, টাকার হিসাব থাকবে</h3>
                <?php foreach ($scan['regs'] as $rid => $r): ?>
                    <div class="dd-place text-sm">
                        <span class="dd-row">#<?= (int) $rid ?> · <b><?= e($r['customer_name'] ?? '') ?></b> · <?= e(trim(($r['item_title'] ?? '') . ' ' . ($r['batch'] ?? ''))) ?> · <span class="text-gray-500"><?= e($r['status'] ?? '') ?>, <?= e(substr((string) ($r['created_at'] ?? ''), 0, 10)) ?></span></span>
                        <a href="registrations.php?action=view&id=<?= (int) $rid ?>" target="_blank" class="text-indigo-600 text-xs whitespace-nowrap">দেখুন ↗</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($scan['recv']): ?>
                <h3 class="text-sm font-bold text-gray-700 mt-4">অন্যের অর্ডারে শুধু রিসিভার হিসেবে — শুধু রিসিভারের নাম/নম্বর/ঠিকানা মুছবে</h3>
                <?php foreach ($scan['recv'] as $rid => $r): ?>
                    <div class="dd-place text-sm">
                        <span class="dd-row">#<?= (int) $rid ?> · রিসিভার: <b><?= e($r['receiver_name'] ?? '') ?></b> · অর্ডার: <?= e($r['customer_name'] ?? '') ?></span>
                        <a href="registrations.php?action=view&id=<?= (int) $rid ?>" target="_blank" class="text-indigo-600 text-xs whitespace-nowrap">দেখুন ↗</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if ($scan['places']): ?>
                <h3 class="text-sm font-bold text-gray-700 mt-4">অন্যান্য জায়গা</h3>
                <?php foreach ($scan['places'] as $pl): ?>
                    <div class="dd-place text-sm">
                        <span class="dd-row"><?= e($pl['label']) ?> <span class="text-xs text-gray-500">— <?= $pl['mode'] === 'delete' ? 'পুরো মুছে যাবে' : 'নম্বর ঢাকা হবে, রেকর্ড থাকবে' ?></span></span>
                        <b><?= count($pl['rows']) ?>টি</b>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <?php if (!$scan['regs'] && !$scan['recv'] && !$scan['places']): ?>
                <p class="text-sm text-gray-600">এই নম্বরের কোনো তথ্য সাইটে পাওয়া যায়নি। চাইলে তবু "মুছে ফেলুন" চেপে অনুরোধটা সম্পন্ন করতে পারেন — প্রমাণে লেখা থাকবে কিছু পাওয়া যায়নি।</p>
            <?php endif; ?>
            <p class="text-xs text-gray-500 mt-3">⚠️ রিভিউ বা গ্যালারির ছবিতে তাঁর নাম/ছবি থাকলে ওটা নম্বর দিয়ে খোঁজা যায় না — কনটেন্ট পাতা থেকে হাতে দেখে নিন। গ্রুপ মিলিয়ে দেখার ইতিহাসে নাম থাকলে সেটা নিজে থেকেই ঢেকে যাবে।</p>
        </div>

        <form method="post" class="bg-white rounded-2xl shadow p-5 mb-5 space-y-3" onsubmit="return confirmSubmit(this, 'এই নম্বরের তথ্য সব জায়গা থেকে মুছে ফেলা হবে — এটা আর ফেরানো যাবে না। নিশ্চিত?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="erase">
            <input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
            <h2 class="font-bold text-red-700">🗑️ মুছে ফেলুন</h2>
            <label class="block text-sm">
                <span class="text-gray-700 font-semibold">পরিচয় কীভাবে যাচাই করেছেন? <span class="text-red-600">*</span></span>
                <input type="text" name="identity_note" required maxlength="255" class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="যেমন: রেজিস্ট্রেশনের নম্বর থেকেই WhatsApp করেছেন / শিশুর নাম ও জন্ম তারিখ মিলেছে">
            </label>
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input type="checkbox" name="confirm_identity" value="1" required class="mt-1">
                <span>আমি নিশ্চিত যে অনুরোধকারী এই নম্বরের প্রকৃত মালিক, আর বুঝেছি যে মোছা তথ্য আর ফেরানো যাবে না।</span>
            </label>
            <button type="submit" class="px-5 py-2.5 rounded-xl bg-red-600 text-white font-semibold">মুছে ফেলুন</button>
        </form>

        <form method="post" class="bg-white rounded-2xl shadow p-5 mb-5 space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reject">
            <input type="hidden" name="id" value="<?= (int) $view['id'] ?>">
            <h2 class="font-bold text-gray-700">অনুরোধ বাতিল</h2>
            <input type="text" name="reason" maxlength="255" class="w-full border rounded-xl px-3 py-2 text-sm" placeholder="কারণ — যেমন: পরিচয় যাচাই করা যায়নি / ভুল করে পাঠিয়েছিলেন">
            <button type="submit" class="px-4 py-2 rounded-xl bg-gray-600 text-white text-sm font-semibold">বাতিল করুন</button>
        </form>
    <?php endif; ?>

<?php else: ?>
    <div class="dd-tiles">
        <div class="bg-white rounded-2xl shadow p-4"><div class="text-xs text-gray-500">চলমান অনুরোধ</div><div class="text-2xl font-bold text-gray-800"><?= $tile['new'] ?></div></div>
        <div class="bg-white rounded-2xl shadow p-4"><div class="text-xs text-gray-500">সময় পেরিয়ে গেছে</div><div class="text-2xl font-bold <?= $tile['late'] ? 'text-red-600' : 'text-gray-800' ?>"><?= $tile['late'] ?></div></div>
        <div class="bg-white rounded-2xl shadow p-4"><div class="text-xs text-gray-500">মোছা সম্পন্ন</div><div class="text-2xl font-bold text-green-700"><?= $tile['done'] ?></div></div>
    </div>

    <details class="bg-white rounded-2xl shadow p-5 mb-6" <?= $list ? '' : 'open' ?>>
        <summary class="font-bold text-gray-800 cursor-pointer">➕ নতুন অনুরোধ রেকর্ড করুন</summary>
        <form method="post" class="grid sm:grid-cols-2 gap-3 mt-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <label class="block text-sm"><span class="text-gray-700 font-semibold">মোবাইল নম্বর <span class="text-red-600">*</span></span>
                <input type="tel" inputmode="numeric" name="phone" required class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="যে নম্বরে রেজিস্ট্রেশন হয়েছিল">
            </label>
            <label class="block text-sm"><span class="text-gray-700 font-semibold">নাম</span>
                <input type="text" name="requester_name" maxlength="150" class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="অনুরোধকারীর নাম">
            </label>
            <label class="block text-sm"><span class="text-gray-700 font-semibold">কোন পথে এসেছে</span>
                <select name="channel" class="mt-1 w-full border rounded-xl px-3 py-2">
                    <?php foreach (dd_channels() as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach; ?>
                </select>
            </label>
            <label class="block text-sm"><span class="text-gray-700 font-semibold">কবে পেয়েছেন</span>
                <input type="date" name="received_on" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" class="mt-1 w-full border rounded-xl px-3 py-2">
            </label>
            <label class="block text-sm sm:col-span-2"><span class="text-gray-700 font-semibold">নোট (ঐচ্ছিক)</span>
                <textarea name="request_note" rows="2" maxlength="2000" class="mt-1 w-full border rounded-xl px-3 py-2" placeholder="যেমন: সব তথ্য মুছতে বলেছেন / শুধু ফেসবুকের তথ্য"></textarea>
            </label>
            <div class="sm:col-span-2"><button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white font-semibold">রেকর্ড করুন</button></div>
        </form>
    </details>

    <?php if (!$list): ?>
        <div class="bg-white rounded-2xl shadow p-8 text-center text-gray-400">এখনো কোনো অনুরোধ আসেনি।</div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($list as $r): [$sl, $sc] = $statusLabel[$r['status']] ?? [$r['status'], 'bg-gray-100 text-gray-600']; ?>
                <a href="data-requests.php?id=<?= (int) $r['id'] ?>" class="block bg-white rounded-2xl shadow p-4 hover:shadow-md transition">
                    <div class="flex flex-wrap items-center gap-2">
                        <b class="text-gray-800"><?= e(dd_ref((int) $r['id'])) ?></b>
                        <span class="dd-chip <?= $sc ?>"><?= e($sl) ?></span>
                        <?= dd_due_chip($r) ?>
                    </div>
                    <div class="text-sm text-gray-600 mt-1 dd-row">
                        <?= e($r['requester_name'] ?: '—') ?> · <?= e($r['phone']) ?> · <?= e(dd_channels()[$r['channel']] ?? $r['channel']) ?> · <?= e(date('d M Y', strtotime($r['received_on']))) ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    var b = document.getElementById('ddCopy'), t = document.getElementById('ddMsg');
    if (!b || !t) return;
    b.addEventListener('click', function () {
        var done = function () { b.textContent = '✅ কপি হয়েছে'; setTimeout(function () { b.textContent = '📋 লেখাটা কপি করুন'; }, 2000); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(t.value).then(done, function () { t.select(); try { document.execCommand('copy'); done(); } catch (e) {} });
        } else { t.select(); try { document.execCommand('copy'); done(); } catch (e) {} }
    });
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
