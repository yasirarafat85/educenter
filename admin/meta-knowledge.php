<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📚 ফেসবুক উত্তর-ভান্ডার (ধাপ ২, ২০২৬-১১-১৭)
 *
 * তিন ট্যাব: ⚡ নির্দিষ্ট শব্দে উত্তর · 📚 জ্ঞানভান্ডার · 💡 শেখার প্রস্তাব
 * 🔴 অটো-উত্তর চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন (ডিফল্টে বন্ধ)।
 * 🔴 প্রস্তাব অনুমোদন না করা পর্যন্ত কিছুই শেখা হয় না।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।
 * ════════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/../includes/meta-kb.php';

$db = get_db();
$pageTitle = 'ফেসবুক উত্তর-ভান্ডার';
$me = (string) ($_SESSION['admin_username'] ?? '');
$ready = mkb_ready($db);
$tab = in_array($_GET['tab'] ?? '', ['rules', 'kb', 'learn'], true) ? $_GET['tab'] : 'rules';
$action = (string) ($_GET['action'] ?? '');

function mk_back(string $tab, array $extra = []): string
{
    return 'meta-knowledge.php?' . http_build_query(array_merge(['tab' => $tab], $extra));
}
function mk_channels(): array { return ['both' => 'মেসেজ ও কমেন্ট দুটোতেই', 'message' => 'শুধু মেসেজে', 'comment' => 'শুধু কমেন্টে']; }
function mk_modes(): array { return ['suggest' => '💡 শুধু প্রস্তাব (আমি দেখে পাঠাব)', 'auto' => '🤖 নিজে থেকে পাঠাবে']; }

/* ─────────────────────────── POST হ্যান্ডলার ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না — পাতাটা আবার খুলে চেষ্টা করুন।'); redirect(mk_back($tab)); }
    if (!$ready) { set_flash('error', 'আগে database/migrate-meta-knowledge.sql চালাতে হবে।'); redirect(mk_back($tab)); }
    $now = date('Y-m-d H:i:s');

    // ── অটো-উত্তর চালু/বন্ধ (শুধু মূল অ্যাডমিন) ──
    if ($action === 'auto') {
        if (!admin_is_super()) { set_flash('error', 'অটো-উত্তর চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন।'); redirect(mk_back('rules')); }
        $on = ($_POST['on'] ?? '') === '1';
        update_setting('meta_autoreply_on', $on ? '1' : '0');
        set_flash('success', $on ? '🤖 অটো-উত্তর চালু হলো — শুধু "নিজে থেকে পাঠাবে" চিহ্নিত নিয়মগুলো উত্তর দেবে।' : '⏸️ অটো-উত্তর বন্ধ — এখন সব নিয়ম শুধু ইনবক্সে প্রস্তাব দেখাবে।');
        redirect(mk_back('rules'));
    }

    // ── নিয়ম সেভ ──
    if ($action === 'rule-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $kw = trim((string) ($_POST['keywords'] ?? ''));
        $reply = trim(str_replace("\r\n", "\n", (string) ($_POST['reply'] ?? '')));
        $ch = array_key_exists($_POST['channel'] ?? '', mk_channels()) ? $_POST['channel'] : 'both';
        $mode = array_key_exists($_POST['mode'] ?? '', mk_modes()) ? $_POST['mode'] : 'suggest';
        $act = !empty($_POST['is_active']) ? 1 : 0;
        $sort = max(0, min(9999, (int) ($_POST['sort_order'] ?? 0)));
        if (!mkb_keywords($kw) || $reply === '' || mb_strlen($reply) > 2000) {
            $_SESSION['mk_rule_old'] = $_POST;
            set_flash('error', 'অন্তত একটা শব্দ আর উত্তর লিখুন (উত্তর ২০০০ অক্ষরের মধ্যে)।');
            redirect(mk_back('rules', array_filter(['edit' => $id ?: null, 'new' => $id ? null : 1])) . '#mk-form');
        }
        $p = ['k' => mb_substr($kw, 0, 2000), 'r' => $reply, 'c' => $ch, 'm' => $mode, 'a' => $act, 's' => $sort];
        if ($id) {
            $db->prepare('UPDATE meta_rules SET keywords = :k, reply = :r, channel = :c, mode = :m, is_active = :a, sort_order = :s WHERE id = :id')
               ->execute($p + ['id' => $id]);
        } else {
            $db->prepare('INSERT INTO meta_rules (keywords, reply, channel, mode, is_active, sort_order) VALUES (:k, :r, :c, :m, :a, :s)')->execute($p);
            $id = (int) $db->lastInsertId();
        }
        set_flash('success', '✅ নিয়ম সংরক্ষণ হয়েছে।' . ($mode === 'auto' && !mkb_auto_on() ? ' (অটো-উত্তরের সুইচ এখন বন্ধ — তাই এটাও আপাতত শুধু প্রস্তাব দেখাবে।)' : ''));
        redirect(mk_back('rules') . '#rule-' . $id);
    }

    if ($action === 'rule-toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE meta_rules SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
        redirect(mk_back('rules') . '#rule-' . $id);
    }

    // ── মোছা (নিয়ম / ভান্ডারের লেখা) ──
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (($_POST['what'] ?? '') === 'rule') {
            $db->prepare('DELETE FROM meta_rules WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'নিয়মটা মুছে ফেলা হলো।');
            redirect(mk_back('rules'));
        }
        $st = $db->prepare('SELECT * FROM meta_kb WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if ($row) {
            if (!empty($row['source_ref'])) {
                // 🔴 হাতের উত্তর থেকে আসা — "বাদ" হিসেবে রাখা হয়, নাহলে পরের বার আবার প্রস্তাব হয়ে ফিরত
                $db->prepare("UPDATE meta_kb SET status = 'rejected', updated_at = :at WHERE id = :id")->execute(['at' => $now, 'id' => $id]);
            } else {
                $db->prepare('DELETE FROM meta_kb WHERE id = :id')->execute(['id' => $id]);
            }
            set_flash('success', 'মুছে ফেলা হলো।');
        }
        redirect(mk_back('kb', ['kind' => ($row['kind'] ?? 'qa') === 'doc' ? 'doc' : 'qa']));
    }

    // ── প্রশ্নোত্তর সেভ ──
    if ($action === 'qa-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $q = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));
        $a = trim(str_replace("\r\n", "\n", (string) ($_POST['answer'] ?? '')));
        if ($q === '' || $a === '' || mb_strlen($q) > 2000 || mb_strlen($a) > 4000) {
            $_SESSION['mk_qa_old'] = $_POST;
            set_flash('error', 'প্রশ্ন ও উত্তর দুটোই লিখুন (প্রশ্ন ২০০০, উত্তর ৪০০০ অক্ষরের মধ্যে)।');
            redirect(mk_back('kb', array_filter(['kind' => 'qa', 'edit' => $id ?: null])) . '#mk-form');
        }
        if ($id) {
            $db->prepare("UPDATE meta_kb SET question = :q, answer = :a, updated_at = :at WHERE id = :id AND kind = 'qa'")
               ->execute(['q' => $q, 'a' => $a, 'at' => $now, 'id' => $id]);
        } else {
            $db->prepare("INSERT INTO meta_kb (kind, status, question, answer, source, created_by, approved_by, approved_at) VALUES ('qa', 'active', :q, :a, 'admin', :b, :b2, :at)")
               ->execute(['q' => $q, 'a' => $a, 'b' => $me, 'b2' => $me, 'at' => $now]);
        }
        set_flash('success', '✅ প্রশ্নোত্তর জ্ঞানভান্ডারে রাখা হলো।');
        redirect(mk_back('kb', ['kind' => 'qa']));
    }

    // ── ডকুমেন্ট সেভ (লেখা পেস্ট বা .txt/.md/.docx আপলোড) ──
    if ($action === 'doc-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $f = $_FILES['doc_file'] ?? null;
        if ($f && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if ((int) $f['size'] > 2 * 1024 * 1024 || !in_array($ext, ['txt', 'md', 'docx'], true)) {
                set_flash('error', 'শুধু .txt, .md বা .docx ফাইল, ২ MB-এর মধ্যে। PDF হলে লেখাটা কপি করে নিচের বাক্সে পেস্ট করুন।');
                redirect(mk_back('kb', ['kind' => 'doc']) . '#mk-form');
            }
            $body = $ext === 'docx' ? mkb_docx_text((string) $f['tmp_name']) : (string) file_get_contents((string) $f['tmp_name']);
            if ($title === '') { $title = pathinfo((string) $f['name'], PATHINFO_FILENAME); }
        }
        $body = mkb_clean_doc($body);
        if ($title === '' || $body === '') {
            set_flash('error', 'শিরোনাম আর লেখা দুটোই লাগবে (ফাইল থেকে লেখা পড়া না গেলে কপি করে পেস্ট করুন)।');
            redirect(mk_back('kb', array_filter(['kind' => 'doc', 'edit' => $id ?: null])) . '#mk-form');
        }
        $title = mb_substr($title, 0, 190);
        if ($id) {
            $db->prepare("UPDATE meta_kb SET title = :t, answer = :b, updated_at = :at WHERE id = :id AND kind = 'doc'")
               ->execute(['t' => $title, 'b' => $body, 'at' => $now, 'id' => $id]);
        } else {
            $db->prepare("INSERT INTO meta_kb (kind, status, title, answer, source, created_by, approved_by, approved_at) VALUES ('doc', 'active', :t, :b, 'upload', :by, :by2, :at)")
               ->execute(['t' => $title, 'b' => $body, 'by' => $me, 'by2' => $me, 'at' => $now]);
        }
        set_flash('success', '✅ ডকুমেন্ট রাখা হলো (' . number_format(mb_strlen($body)) . ' অক্ষর)।');
        redirect(mk_back('kb', ['kind' => 'doc']));
    }

    // ── প্রস্তাব অনুমোদন / বাদ ──
    if ($action === 'approve' || $action === 'reject') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'approve') {
            $q = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));
            $a = trim(str_replace("\r\n", "\n", (string) ($_POST['answer'] ?? '')));
            if ($q === '' || $a === '') { set_flash('error', 'প্রশ্ন ও উত্তর খালি রাখা যাবে না।'); redirect(mk_back('learn') . '#kb-' . $id); }
            $db->prepare("UPDATE meta_kb SET status = 'active', question = :q, answer = :a, approved_by = :b, approved_at = :at, updated_at = :at2
                          WHERE id = :id AND status = 'proposed'")
               ->execute(['q' => mb_substr($q, 0, 2000), 'a' => mb_substr($a, 0, 4000), 'b' => $me, 'at' => $now, 'at2' => $now, 'id' => $id]);
            set_flash('success', '✅ শেখা হলো — এখন থেকে মিলে যাওয়া প্রশ্নে এই উত্তর প্রস্তাব হিসেবে আসবে।');
        } else {
            $db->prepare("UPDATE meta_kb SET status = 'rejected', updated_at = :at WHERE id = :id AND status = 'proposed'")->execute(['at' => $now, 'id' => $id]);
            set_flash('success', 'প্রস্তাবটা বাদ দেওয়া হলো — আর আসবে না।');
        }
        redirect(mk_back('learn'));
    }

    if ($action === 'scan') {
        $n = mkb_scan_history($db);
        set_flash('success', $n ? "💡 নতুন {$n}টি প্রস্তাব পাওয়া গেছে।" : 'নতুন কোনো প্রস্তাব পাওয়া যায়নি — হাতে উত্তর দিলে বা পুরনো মেসেজ/কমেন্ট আনলে প্রস্তাব তৈরি হয়।');
        redirect(mk_back('learn'));
    }

    redirect(mk_back($tab));
}

/* ─────────────────────────── দেখানোর ডেটা ─────────────────────────── */
$rules = []; $kbRows = []; $proposals = []; $editRow = null; $testOut = null;
$counts = ['rules' => 0, 'qa' => 0, 'doc' => 0, 'learn' => 0];
$kind = ($_GET['kind'] ?? '') === 'doc' ? 'doc' : 'qa';
$q = trim((string) ($_GET['q'] ?? ''));
$testText = trim((string) ($_GET['t'] ?? ''));
$testCh = ($_GET['tc'] ?? '') === 'comment' ? 'comment' : 'message';
$newScan = 0;

if ($ready) {
    try {
        if ($tab === 'learn') { $newScan = mkb_scan_history($db); }
        $counts['rules'] = (int) $db->query('SELECT COUNT(*) FROM meta_rules')->fetchColumn();
        foreach ($db->query("SELECT kind, COUNT(*) n FROM meta_kb WHERE status = 'active' GROUP BY kind")->fetchAll() as $r) { $counts[$r['kind']] = (int) $r['n']; }
        $counts['learn'] = mkb_proposed_count($db);

        if ($tab === 'rules') {
            $rules = mkb_rules($db, true);
            $eid = (int) ($_GET['edit'] ?? 0);
            if ($eid) { foreach ($rules as $r) { if ((int) $r['id'] === $eid) { $editRow = $r; } } }
            if ($testText !== '') {
                $testOut = ['rule' => mkb_rule_pick($rules, $testText, $testCh), 'sug' => mkb_suggest($db, $testText, $testCh, 'রহিমা')];
            }
        } elseif ($tab === 'kb') {
            $params = ['k' => $kind];
            $where = "kind = :k AND status = 'active'";
            if ($q !== '') {
                $where .= " AND CONCAT_WS(' ', title, question, answer) LIKE :q ESCAPE '!'";
                $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            }
            $st = $db->prepare("SELECT * FROM meta_kb WHERE $where ORDER BY id DESC LIMIT 300");
            $st->execute($params);
            $kbRows = $st->fetchAll();
            $eid = (int) ($_GET['edit'] ?? 0);
            if ($eid) {
                $st = $db->prepare("SELECT * FROM meta_kb WHERE id = :id AND kind = :k AND status = 'active'");
                $st->execute(['id' => $eid, 'k' => $kind]);
                $editRow = $st->fetch() ?: null;
            }
        } else {
            $proposals = $db->query("SELECT * FROM meta_kb WHERE status = 'proposed' ORDER BY id DESC LIMIT 50")->fetchAll();
        }
    } catch (Throwable $e) {
        error_log('meta-knowledge: ' . $e->getMessage());
    }
}
$isSuper = admin_is_super();
$autoOn = mkb_auto_on();
$ruleOld = $_SESSION['mk_rule_old'] ?? null; unset($_SESSION['mk_rule_old']);
$qaOld = $_SESSION['mk_qa_old'] ?? null; unset($_SESSION['mk_qa_old']);

require __DIR__ . '/includes/layout-top.php';
?>
<style>
.mk-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.mk-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1rem;border-radius:.9rem;font-weight:700;font-size:.9rem;background:rgb(var(--c-surface,255 255 255));border:1px solid rgb(var(--c-border,229 231 235));color:rgb(var(--c-text,31 41 55))}
.mk-tab.on{background:rgb(var(--c-primary));color:#fff;border-color:transparent}
.mk-badge{display:inline-block;min-width:1.4rem;padding:0 .4rem;border-radius:999px;background:#EF4444;color:#fff;font-size:.72rem;line-height:1.4rem;text-align:center}
.mk-card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:1rem;min-width:0}
.mk-f label{display:block;font-size:.85rem;font-weight:700;color:#374151;margin:.6rem 0 .25rem}
.mk-f textarea,.mk-f input[type=text],.mk-f input[type=search],.mk-f input[type=number],.mk-f select{width:100%;min-width:0;border:1px solid rgb(var(--c-border,229 231 235));border-radius:.8rem;padding:.55rem .8rem;font-size:16px;line-height:1.6;background:#fff}
.mk-hint{font-size:.78rem;color:#6B7280;margin-top:.2rem}
.mk-chip{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:.78rem;font-weight:600;background:#EEF2FF;color:#3730A3;margin:0 .25rem .25rem 0;overflow-wrap:anywhere}
.mk-pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:.9rem;line-height:1.7;color:#1F2937}
.mk-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.mk-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.5rem .9rem;border-radius:.75rem;font-size:.85rem;font-weight:700}
.mk-var{font-family:monospace;background:#F3F4F6;padding:.05rem .35rem;border-radius:.35rem}
</style>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-meta-knowledge.sql</code> চালান (তার আগে <code class="bg-white px-1.5 py-0.5 rounded">migrate-meta-inbox.sql</code>)। 🩺 সেটিংস → সিস্টেম চেক পাতাতেও কপি বোতামসহ দেখা যাবে।</p>
</div>
<?php endif; ?>

<div class="mk-tabs">
    <a href="meta-inbox.php" class="mk-tab">← ইনবক্স</a>
    <a href="<?= e(mk_back('rules')) ?>" class="mk-tab <?= $tab === 'rules' ? 'on' : '' ?>">⚡ নির্দিষ্ট শব্দে উত্তর <span class="text-xs" style="opacity:.75">(<?= $counts['rules'] ?>)</span></a>
    <a href="<?= e(mk_back('kb')) ?>" class="mk-tab <?= $tab === 'kb' ? 'on' : '' ?>">📚 জ্ঞানভান্ডার <span class="text-xs" style="opacity:.75">(<?= $counts['qa'] + $counts['doc'] ?>)</span></a>
    <a href="<?= e(mk_back('learn')) ?>" class="mk-tab <?= $tab === 'learn' ? 'on' : '' ?>">💡 শেখার প্রস্তাব <?php if ($counts['learn']): ?><span class="mk-badge"><?= $counts['learn'] ?></span><?php endif; ?></a>
</div>

<?php if ($ready && $tab === 'rules'): ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-card" style="border-left:4px solid <?= $autoOn ? '#16A34A' : '#9CA3AF' ?>">
        <div class="mk-row" style="justify-content:space-between">
            <div style="min-width:0;flex:1">
                <p class="font-bold text-gray-800"><?= $autoOn ? '🤖 অটো-উত্তর চালু' : '⏸️ অটো-উত্তর বন্ধ' ?></p>
                <p class="text-sm text-gray-600 mt-1"><?= $autoOn
                    ? '"নিজে থেকে পাঠাবে" চিহ্নিত নিয়মে শব্দ মিললে উত্তর সাথে সাথে চলে যায়। একই মানুষকে একই উত্তর ' . MKB_COOLDOWN_HOURS . ' ঘণ্টায় একবার, আর আপনি নিজে উত্তর দিলে ' . MKB_HUMAN_QUIET_MIN . ' মিনিট অটো চুপ থাকে।'
                    : 'এখন কোনো উত্তর নিজে থেকে যায় না — মিলে যাওয়া উত্তর শুধু ইনবক্সে "💡 প্রস্তাবিত উত্তর" হিসেবে দেখায়, আপনি এক চাপে বসিয়ে পাঠান।' ?></p>
            </div>
            <?php if ($isSuper): ?>
            <form method="post" action="<?= e(mk_back('rules', ['action' => 'auto'])) ?>" <?= $autoOn ? '' : 'onsubmit="return confirmSubmit(this, \'চালু করলে নিজে-থেকে-পাঠানো নিয়মগুলো আপনার দেখা ছাড়াই ফেসবুকে উত্তর পাঠাবে। চালু করবেন?\', \'অটো-উত্তর চালু\');"' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="on" value="<?= $autoOn ? '0' : '1' ?>">
                <button type="submit" class="mk-btn <?= $autoOn ? 'bg-red-100 text-red-700' : 'bg-green-600 text-white' ?>"><?= $autoOn ? '⏸️ বন্ধ করুন' : '▶️ চালু করুন' ?></button>
            </form>
            <?php else: ?>
            <span class="text-xs text-gray-500">চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="mk-card mk-f" id="mkTestForm">
        <input type="hidden" name="tab" value="rules">
        <p class="font-bold text-gray-800">🧪 পরীক্ষা করে দেখুন <span class="text-xs font-normal text-gray-500">(কিছুই পাঠানো হয় না)</span></p>
        <div class="mk-row mt-2">
            <input type="text" name="t" value="<?= e($testText) ?>" placeholder="যেমন: কোর্সের দাম কত?" style="flex:1;min-width:180px">
            <select name="tc" style="width:auto"><option value="message" <?= $testCh === 'message' ? 'selected' : '' ?>>মেসেজ হিসেবে</option><option value="comment" <?= $testCh === 'comment' ? 'selected' : '' ?>>কমেন্ট হিসেবে</option></select>
            <button type="submit" class="mk-btn bg-indigo-600 text-white">দেখুন</button>
        </div>
        <?php if ($testOut !== null): ?>
            <div class="mt-3 text-sm">
                <?php if ($testOut['rule']): ?>
                    <p class="font-semibold text-green-700">✅ মিলেছে — নিয়ম #<?= (int) $testOut['rule']['id'] ?> (<?= ($testOut['rule']['mode'] === 'auto' && $autoOn) ? '🤖 নিজে থেকে যেত' : '💡 শুধু প্রস্তাব হতো' ?>)</p>
                <?php else: ?>
                    <p class="font-semibold text-gray-600">কোনো নির্দিষ্ট-শব্দের নিয়মে মেলেনি।</p>
                <?php endif; ?>
                <?php foreach ($testOut['sug'] as $sg): ?>
                    <div class="mt-2 p-3 rounded-xl bg-gray-50"><p class="text-xs text-gray-500 mb-1"><?= e($sg['label']) ?></p><div class="mk-pre"><?= e($sg['text']) ?></div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php $showForm = $editRow || $ruleOld || isset($_GET['new']);
    $fr = $ruleOld ?: ($editRow ?: ['keywords' => '', 'reply' => '', 'channel' => 'both', 'mode' => 'suggest', 'is_active' => 1, 'sort_order' => 0]); ?>
    <?php if ($showForm): ?>
    <form method="post" action="<?= e(mk_back('rules', ['action' => 'rule-save'])) ?>" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fr['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fr['id']) ? '✏️ নিয়ম বদলান' : '➕ নতুন নিয়ম' ?></p>
        <label>কোন শব্দ থাকলে? (প্রতি লাইনে বা কমা দিয়ে একটা)</label>
        <textarea name="keywords" rows="3" required placeholder="দাম, ফি, কত টাকা&#10;price"><?= e((string) $fr['keywords']) ?></textarea>
        <p class="mk-hint">যেকোনো একটা শব্দ মিললেই নিয়মটা খাটে। বাংলা শব্দ অংশ হিসেবেও মেলে ("দাম" → "দামটা কত")।</p>
        <label>উত্তর</label>
        <textarea name="reply" rows="5" required maxlength="2000"><?= e((string) $fr['reply']) ?></textarea>
        <p class="mk-hint">লেখায় বসাতে পারেন: <span class="mk-var">{name}</span> নাম · <span class="mk-var">{courses}</span> এখন ভর্তি চলা কোর্সের তালিকা ও দাম (প্রতিবার টাটকা) · <span class="mk-var">{site}</span> ওয়েবসাইট · <span class="mk-var">{whatsapp}</span> WhatsApp লিংক</p>
        <div class="grid sm:grid-cols-2" style="column-gap:1rem">
            <div><label>কোথায়?</label><select name="channel"><?php foreach (mk_channels() as $k => $lb): ?><option value="<?= $k ?>" <?= ($fr['channel'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
            <div><label>কীভাবে?</label><select name="mode"><?php foreach (mk_modes() as $k => $lb): ?><option value="<?= $k ?>" <?= ($fr['mode'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
            <div><label>ক্রম (ছোট সংখ্যা আগে দেখা হয়)</label><input type="number" name="sort_order" min="0" max="9999" value="<?= (int) ($fr['sort_order'] ?? 0) ?>"></div>
            <div><label>&nbsp;</label><label style="font-weight:600;display:flex;gap:.5rem;align-items:center;margin:0"><input type="checkbox" name="is_active" value="1" <?= !empty($fr['is_active']) ? 'checked' : '' ?>> চালু</label></div>
        </div>
        <div class="mk-row mt-4">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <a href="<?= e(mk_back('rules')) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a>
        </div>
    </form>
    <?php else: ?>
        <div><a href="<?= e(mk_back('rules', ['new' => 1])) ?>#mk-form" class="mk-btn bg-indigo-600 text-white">➕ নতুন নিয়ম যোগ করুন</a></div>
    <?php endif; ?>

    <?php if (!$rules): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">⚡</p>
            <p class="font-bold text-gray-800">এখনো কোনো নিয়ম নেই</p>
            <p class="text-sm text-gray-500 mt-1">যেমন: "দাম, ফি, কত টাকা" শব্দ এলে উত্তর — "আমাদের চলমান কোর্স: {courses}"।</p>
        </div>
    <?php endif; ?>
    <?php foreach ($rules as $r): ?>
        <div class="mk-card" id="rule-<?= (int) $r['id'] ?>" style="<?= $r['is_active'] ? '' : 'opacity:.6' ?>">
            <div class="mk-row" style="justify-content:space-between">
                <div style="min-width:0;flex:1">
                    <?php foreach (mkb_keywords((string) $r['keywords']) as $k): ?><span class="mk-chip"><?= e($k) ?></span><?php endforeach; ?>
                </div>
                <span class="text-xs font-semibold px-2 py-1 rounded <?= $r['mode'] === 'auto' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $r['mode'] === 'auto' ? '🤖 অটো' : '💡 প্রস্তাব' ?></span>
            </div>
            <div class="mk-pre mt-2"><?= e(meta_snippet((string) $r['reply'], 300)) ?></div>
            <p class="text-xs text-gray-500 mt-2"><?= e(mk_channels()[$r['channel']] ?? '') ?> · ক্রম <?= (int) $r['sort_order'] ?> · অটো-উত্তর গেছে <?= (int) $r['hits'] ?> বার<?= !$r['is_active'] ? ' · ⏸️ বন্ধ' : '' ?></p>
            <div class="mk-row mt-2">
                <a href="<?= e(mk_back('rules', ['edit' => $r['id']])) ?>#mk-form" class="mk-btn bg-indigo-50 text-indigo-700">✏️ বদলান</a>
                <form method="post" action="<?= e(mk_back('rules', ['action' => 'rule-toggle'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-gray-100 text-gray-700"><?= $r['is_active'] ? '⏸️ বন্ধ' : '▶️ চালু' ?></button></form>
                <form method="post" action="<?= e(mk_back('rules', ['action' => 'delete'])) ?>" onsubmit="return confirmSubmit(this, 'এই নিয়মটা মুছবেন?', 'মুছে ফেলা');"><?= csrf_field() ?><input type="hidden" name="what" value="rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ মুছুন</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php elseif ($ready && $tab === 'kb'): ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-row">
        <a href="<?= e(mk_back('kb', ['kind' => 'qa'])) ?>" class="mk-btn <?= $kind === 'qa' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>">❓ প্রশ্নোত্তর (<?= $counts['qa'] ?>)</a>
        <a href="<?= e(mk_back('kb', ['kind' => 'doc'])) ?>" class="mk-btn <?= $kind === 'doc' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>">📄 ডকুমেন্ট (<?= $counts['doc'] ?>)</a>
    </div>
    <p class="text-sm text-gray-600"><?= $kind === 'qa'
        ? 'অভিভাবকেরা যা যা জিজ্ঞেস করেন আর তার সঠিক উত্তর। মেসেজ/কমেন্ট এলে কাছাকাছি প্রশ্নের উত্তর ইনবক্সে প্রস্তাব হিসেবে আসে; ধাপ ৩-এ AI-ও এখান থেকে শিখবে।'
        : 'শিশুর মেধা বিকাশ, কোর্সের ধাপ, কোন কাজ কীভাবে করতে হয় — এমন বড় লেখা। ধাপ ৩-এ AI উত্তর লেখার সময় এগুলো পড়বে। দাম/ব্যাচ/শেষ সময় এখানে লিখবেন না — সেগুলো প্রতিবার সাইট থেকে টাটকা নেওয়া হয়।' ?></p>

    <?php if ($kind === 'qa'):
        $fq = $qaOld ?: ($editRow ?: ['question' => '', 'answer' => '']); ?>
    <form method="post" action="<?= e(mk_back('kb', ['kind' => 'qa', 'action' => 'qa-save'])) ?>" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fq['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fq['id']) ? '✏️ প্রশ্নোত্তর বদলান' : '➕ নতুন প্রশ্নোত্তর' ?></p>
        <label>প্রশ্ন (একই প্রশ্ন নানাভাবে লিখতে চাইলে প্রতি লাইনে একটা)</label>
        <textarea name="question" rows="2" required><?= e((string) $fq['question']) ?></textarea>
        <label>উত্তর</label>
        <textarea name="answer" rows="4" required maxlength="4000"><?= e((string) $fq['answer']) ?></textarea>
        <p class="mk-hint">উত্তরে <span class="mk-var">{name}</span> · <span class="mk-var">{courses}</span> · <span class="mk-var">{site}</span> · <span class="mk-var">{whatsapp}</span> বসানো যায়।</p>
        <div class="mk-row mt-3">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <?php if (!empty($fq['id'])): ?><a href="<?= e(mk_back('kb', ['kind' => 'qa'])) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a><?php endif; ?>
        </div>
    </form>
    <?php else:
        $fd = $editRow ?: ['title' => '', 'answer' => '']; ?>
    <form method="post" action="<?= e(mk_back('kb', ['kind' => 'doc', 'action' => 'doc-save'])) ?>" enctype="multipart/form-data" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fd['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fd['id']) ? '✏️ ডকুমেন্ট বদলান' : '➕ নতুন ডকুমেন্ট' ?></p>
        <label>শিরোনাম</label>
        <input type="text" name="title" maxlength="190" value="<?= e((string) $fd['title']) ?>" placeholder="যেমন: ৩-৫ বছরের শিশুর মনোযোগ বাড়ানোর ধাপ">
        <?php if (empty($fd['id'])): ?>
        <label>ফাইল থেকে (.txt, .md, .docx — ২ MB পর্যন্ত)</label>
        <input type="file" name="doc_file" accept=".txt,.md,.docx" style="max-width:100%">
        <p class="mk-hint">ফাইল দিলে নিচের বাক্স লাগবে না। PDF হলে লেখাটা কপি করে নিচে পেস্ট করুন।</p>
        <?php endif; ?>
        <label>অথবা লেখা পেস্ট করুন</label>
        <textarea name="body" rows="10"><?= e((string) $fd['answer']) ?></textarea>
        <p class="mk-hint">একটা ডকুমেন্টে সর্বোচ্চ <?= number_format(MKB_DOC_MAX_CHARS) ?> অক্ষর।</p>
        <div class="mk-row mt-3">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <?php if (!empty($fd['id'])): ?><a href="<?= e(mk_back('kb', ['kind' => 'doc'])) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <form method="get" id="mkKbSearch" class="mk-card mk-f mk-row" style="padding:.75rem">
        <input type="hidden" name="tab" value="kb"><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" data-autosearch="mkKbSearch" class="list-search" style="flex:1;min-width:0" placeholder="খুঁজুন…">
        <button type="submit" class="mk-btn bg-indigo-600 text-white">🔍 খুঁজুন</button>
    </form>

    <?php if (!$kbRows): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center"><p class="font-bold text-gray-800"><?= $q !== '' ? 'কিছু পাওয়া যায়নি' : 'এখনো কিছু রাখা হয়নি' ?></p></div>
    <?php endif; ?>
    <?php foreach ($kbRows as $r): ?>
        <div class="mk-card" id="kb-<?= (int) $r['id'] ?>">
            <?php if ($kind === 'qa'): ?>
                <p class="font-bold text-gray-800" style="overflow-wrap:anywhere">❓ <?= e((string) $r['question']) ?></p>
                <div class="mk-pre mt-1"><?= e(meta_snippet((string) $r['answer'], 400)) ?></div>
            <?php else: ?>
                <p class="font-bold text-gray-800" style="overflow-wrap:anywhere">📄 <?= e((string) $r['title']) ?></p>
                <p class="text-xs text-gray-500"><?= number_format(mb_strlen((string) $r['answer'])) ?> অক্ষর</p>
                <div class="mk-pre mt-1 text-gray-600"><?= e(meta_snippet((string) $r['answer'], 220)) ?></div>
            <?php endif; ?>
            <p class="text-xs text-gray-400 mt-2"><?= $r['source'] === 'message' ? '💬 মেসেজের উত্তর থেকে শেখা' : ($r['source'] === 'comment' ? '🗨️ কমেন্টের উত্তর থেকে শেখা' : 'হাতে লেখা') ?><?= $r['approved_by'] ? ' · ' . e($r['approved_by']) : '' ?></p>
            <div class="mk-row mt-2">
                <a href="<?= e(mk_back('kb', ['kind' => $kind, 'edit' => $r['id']])) ?>#mk-form" class="mk-btn bg-indigo-50 text-indigo-700">✏️ বদলান</a>
                <form method="post" action="<?= e(mk_back('kb', ['kind' => $kind, 'action' => 'delete'])) ?>" onsubmit="return confirmSubmit(this, 'এটা জ্ঞানভান্ডার থেকে মুছবেন?', 'মুছে ফেলা');"><?= csrf_field() ?><input type="hidden" name="what" value="kb"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ মুছুন</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php elseif ($ready): /* ── শেখার প্রস্তাব ── */ ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-card">
        <p class="text-sm text-gray-700">আপনি বা মডারেটর ইনবক্সে (বা Business Suite থেকে) যে উত্তর দেন, আর পুরনো কমেন্টে পেইজ যে উত্তর দিয়েছিল — সেগুলো থেকে প্রশ্ন-উত্তর জোড়া এখানে আসে। <b>✅ শিখে নিন</b> চাপলে তবেই জ্ঞানভান্ডারে যায় (চাইলে আগে লেখা ঠিক করে নিন)। 🔴 অনুমোদন ছাড়া কিছুই শেখা হয় না।</p>
        <?php if ($newScan): ?><p class="text-sm font-semibold text-green-700 mt-2">💡 এইমাত্র নতুন <?= (int) $newScan ?>টি প্রস্তাব পাওয়া গেছে।</p><?php endif; ?>
        <form method="post" action="<?= e(mk_back('learn', ['action' => 'scan'])) ?>" class="mt-3"><?= csrf_field() ?><button type="submit" class="mk-btn bg-gray-100 text-gray-700">🔄 আবার খুঁজে দেখুন</button></form>
    </div>
    <?php if (!$proposals): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">💡</p>
            <p class="font-bold text-gray-800">অপেক্ষায় কোনো প্রস্তাব নেই</p>
            <p class="text-sm text-gray-500 mt-1">ইনবক্স → সংযোগ ট্যাবে "পুরনো মেসেজ/কমেন্ট আনুন" চাপলে আগের উত্তরগুলো থেকেও প্রস্তাব আসবে।</p>
        </div>
    <?php endif; ?>
    <?php foreach ($proposals as $p): ?>
        <div class="mk-card" id="kb-<?= (int) $p['id'] ?>">
            <form method="post" action="<?= e(mk_back('learn', ['action' => 'approve'])) ?>" class="mk-f">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <p class="text-xs text-gray-500"><?= $p['source'] === 'comment' ? '🗨️ কমেন্টের উত্তর থেকে' : '💬 মেসেজের উত্তর থেকে' ?><?= $p['created_by'] ? ' · উত্তর দিয়েছিলেন ' . e($p['created_by']) : '' ?> · <?= e(date('d M Y', strtotime((string) $p['created_at']))) ?></p>
                <label>প্রশ্ন (অভিভাবক যা লিখেছিলেন)</label>
                <textarea name="question" rows="2"><?= e((string) $p['question']) ?></textarea>
                <label>উত্তর</label>
                <textarea name="answer" rows="4"><?= e((string) $p['answer']) ?></textarea>
                <p class="mk-hint">নাম, ফোন নম্বর বা একজনের নিজস্ব কথা থাকলে শেখানোর আগে মুছে দিন — এটা সবার জন্য ব্যবহার হবে।</p>
                <button type="submit" class="mk-btn bg-green-600 text-white mt-3">✅ শিখে নিন</button>
            </form>
            <form method="post" action="<?= e(mk_back('learn', ['action' => 'reject'])) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button type="submit" class="mk-btn bg-gray-100 text-gray-700">✕ বাদ দিন</button></form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
