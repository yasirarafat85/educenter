<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 SMS ছাঁচ (প্যাটার্ন) — অ্যাডমিন নিজে শেখাবেন  (২০২৬-১০-০৬, ধাপ ১)
 *
 * বিকাশ/নগদের বার্তার লেখা বদলালে বা ভবিষ্যতে ব্যাংকের SMS যোগ করতে হলে
 * **কোড ছুঁতে হবে না** — এখানে নমুনা SMS পেস্ট করে বদলে-যাওয়া অংশে ঘর বসালেই হয়।
 *
 * 🔴 সেভ করার **আগেই** নমুনার সাথে মিলিয়ে দেখা হয় — না মিললে সেভই হয় না
 *    (ভাঙা ছাঁচ সেভ হলে ঐ ধরনের সব SMS নীরবে "পড়া যায়নি" তালিকায় পড়ত)।
 * 🔴 এই পাতা টাকার বইয়ে কিছুই লেখে না।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment-sms.php';
admin_require_login();

$db = get_db();
$pageTitle = 'SMS ছাঁচ (প্যাটার্ন)';
$ready = psms_ready($db);
if ($ready) {
    psms_ensure_seeds($db);
}

// ছাঁচ (অথবা কাঁচা regex) → যাচাই করা regex; এক জায়গায় রাখা, POST ও পরীক্ষার বাক্স দুটোই ব্যবহার করে
function sp_compile(string $mode, string $template, string $rawRegex, string $fieldsCsv, string $sample): array
{
    if ($mode === 'regex') {
        $re = trim($rawRegex);
        if ($re === '') {
            return ['regex' => '', 'fields' => [], 'error' => 'regex খালি।'];
        }
        $known = psms_placeholders();
        $fields = array_values(array_filter(array_map('trim', explode(',', strtolower($fieldsCsv))), fn($f) => $f !== '' && isset($known[$f]) && $f !== '*'));
        foreach (psms_required_placeholders() as $need) {
            if (!in_array($need, $fields, true)) {
                return ['regex' => '', 'fields' => [], 'error' => 'ঘরের তালিকায় ' . $need . ' থাকতেই হবে (বন্ধনীর ক্রম অনুযায়ী লিখুন)।'];
            }
        }
        if (@preg_match($re, '') === false) {
            return ['regex' => '', 'fields' => [], 'error' => 'regex-টা ঠিক নয় (PHP পড়তে পারছে না)।'];
        }
        $out = ['regex' => $re, 'fields' => $fields, 'error' => ''];
    } else {
        $out = psms_template_to_regex($template);
        if ($out['error'] !== '') {
            return $out;
        }
    }
    if (trim($sample) === '') {
        return $out + ['error' => 'একটা নমুনা SMS দিন — ছাঁচটা সত্যিই মেলে কিনা যাচাই করা দরকার।'];
    }
    if (psms_apply($out['regex'], $out['fields'], $sample) === null) {
        $out['error'] = 'ছাঁচটা নমুনা SMS-এর সাথে মেলেনি — তাই সেভ করা হয়নি। নিচের "পরীক্ষা করুন" দিয়ে মিলিয়ে নিন।';
    }
    return $out;
}

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_GET['action'] ?? '';
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('sms-patterns.php');
    }
    if (!$ready) {
        set_flash('error', 'আগে database/migrate-payment-sms.sql চালাতে হবে।');
        redirect('sms-patterns.php');
    }

    if ($act === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $db->prepare('DELETE FROM payment_sms_patterns WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'ছাঁচটা মুছে ফেলা হয়েছে।');
        } catch (Throwable $e) {
            set_flash('error', 'মুছতে সমস্যা হয়েছে।');
        }
        redirect('sms-patterns.php');
    }

    if ($act === 'toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $col = ($_POST['field'] ?? '') === 'customer' ? 'is_customer_payment' : 'is_active';
        try {
            // 🔴 কলামের নাম কোডে লেখা ধ্রুবক (উপরের টার্নারি) — কখনো POST থেকে সরাসরি নয়
            $db->prepare('UPDATE payment_sms_patterns SET ' . $col . ' = 1 - ' . $col . ' WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'হালনাগাদ হয়েছে।');
        } catch (Throwable $e) {
            set_flash('error', 'হালনাগাদ করা গেল না।');
        }
        redirect('sms-patterns.php');
    }

    if ($act === 'reparse') {
        $r = psms_reparse($db);
        set_flash('success', 'জমা থাকা ' . $r['scanned'] . ' টি SMS আবার দেখা হলো — ' . $r['parsed'] . ' টি এখন পড়া গেছে।');
        redirect('sms-patterns.php');
    }

    if ($act === 'save') {
        $id       = (int) ($_POST['id'] ?? 0);
        $label    = trim((string) ($_POST['label'] ?? ''));
        $provider = trim((string) ($_POST['provider'] ?? ''));
        $mode     = ($_POST['mode'] ?? 'template') === 'regex' ? 'regex' : 'template';
        $template = (string) ($_POST['template'] ?? '');
        $rawRegex = (string) ($_POST['pattern'] ?? '');
        $fieldsIn = (string) ($_POST['fields'] ?? '');
        $sample   = psms_clean_text((string) ($_POST['sample_sms'] ?? ''));
        $isCust   = !empty($_POST['is_customer_payment']) ? 1 : 0;
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $order    = (int) ($_POST['sort_order'] ?? 0);

        if ($label === '') {
            $label = $provider !== '' ? $provider : 'নতুন ছাঁচ';
        }
        $c = sp_compile($mode, $template, $rawRegex, $fieldsIn, $sample);
        if ($c['error'] !== '') {
            set_flash('error', $c['error']);
            redirect('sms-patterns.php');
        }

        $params = [
            'l'  => mb_substr($label, 0, 120),
            'p'  => mb_substr($provider, 0, 30),
            't'  => $mode === 'template' ? trim($template) : '',
            're' => $c['regex'],
            'f'  => json_encode($c['fields']),
            's'  => $sample,
            'cp' => $isCust,
            'a'  => $isActive,
            'o'  => $order,
        ];
        try {
            if ($id > 0) {
                $db->prepare('UPDATE payment_sms_patterns SET label = :l, provider = :p, template = :t,
                                pattern = :re, fields_json = :f, sample_sms = :s,
                                is_customer_payment = :cp, is_active = :a, sort_order = :o
                              WHERE id = :id')->execute($params + ['id' => $id]);
                set_flash('success', 'ছাঁচটা হালনাগাদ হয়েছে।');
            } else {
                $db->prepare('INSERT INTO payment_sms_patterns
                                (label, provider, template, pattern, fields_json, sample_sms, is_customer_payment, is_active, sort_order)
                              VALUES (:l, :p, :t, :re, :f, :s, :cp, :a, :o)')->execute($params);
                set_flash('success', 'নতুন ছাঁচ যোগ হয়েছে। জমা থাকা SMS আবার পড়াতে চাইলে "আবার পড়ান" চাপুন।');
            }
        } catch (Throwable $e) {
            set_flash('error', 'সেভ করা গেল না।');
        }
        redirect('sms-patterns.php');
    }

    redirect('sms-patterns.php');
}

// ---------------- পরীক্ষার বাক্স (GET, কিছুই সেভ হয় না) ----------------
$testOut = null;
if (($_GET['action'] ?? '') === 'test') {
    $tMode = ($_GET['mode'] ?? 'template') === 'regex' ? 'regex' : 'template';
    $tTpl  = (string) ($_GET['template'] ?? '');
    $tRe   = (string) ($_GET['pattern'] ?? '');
    $tF    = (string) ($_GET['fields'] ?? '');
    $tSms  = psms_clean_text((string) ($_GET['sample_sms'] ?? ''));
    $c = sp_compile($tMode, $tTpl, $tRe, $tF, $tSms);
    $testOut = ['error' => $c['error'], 'regex' => $c['regex'], 'fields' => $c['fields'], 'sample' => $tSms,
                'hit' => $c['error'] === '' ? psms_apply($c['regex'], $c['fields'], $tSms) : null];
    // এরর থাকলেও যদি regex তৈরি হয়ে থাকে, মিল দেখানোর চেষ্টা করা হয় (অ্যাডমিন বুঝবেন কোথায় আটকাল)
    if ($testOut['hit'] === null && $c['regex'] !== '') {
        $testOut['hit'] = psms_apply($c['regex'], $c['fields'], $tSms);
    }
}

$edit = null;
if (($_GET['edit'] ?? '') !== '' && $ready) {
    $st = $db->prepare('SELECT * FROM payment_sms_patterns WHERE id = :id');
    $st->execute(['id' => (int) $_GET['edit']]);
    $edit = $st->fetch() ?: null;
}

$patterns = psms_patterns($db, false);
$ph = psms_placeholders();

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">📨 SMS ছাঁচ (প্যাটার্ন)</h1>
    <p class="text-sm text-gray-500 mt-1">
        বিকাশ/নগদের টাকা-পাওয়ার বার্তা থেকে কোন অংশটা অঙ্ক, কোনটা TrxID — সেটা এখানে শেখানো হয়।
        লেখা বদলে গেলে বা নতুন ব্যাংকের SMS যোগ করতে হলে এখানেই করুন, কোড বদলানোর দরকার নেই।
    </p>
</div>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-6 text-sm">
        ⚠️ এই অংশটা এখনো চালু হয়নি — phpMyAdmin-এ একবার
        <code class="bg-white px-1 rounded">database/migrate-payment-sms.sql</code> চালাতে হবে
        (লাইভ ও লোকাল দুটোতেই)। ততক্ষণ সাইটের বাকি সব আগের মতোই চলবে।
    </div>
<?php endif; ?>

<div class="bg-blue-50 border border-blue-200 rounded-xl p-4 mb-6 text-sm text-gray-700">
    <p class="font-bold mb-2">কীভাবে একটা ছাঁচ লেখেন</p>
    <p class="mb-2">আপনার ফোনে আসা SMS-টা হুবহু কপি করে নিচে বসান, তারপর যে অংশগুলো প্রতিবার বদলায়
        সেগুলোর জায়গায় এই ঘরগুলো লিখুন:</p>
    <div class="flex flex-wrap gap-2 mb-2">
        <?php foreach ($ph as $k => $m): ?>
            <span class="bg-white border border-blue-200 rounded px-2 py-1 text-xs">
                <code class="font-bold">{<?= e($k) ?>}</code> — <?= e($m['label']) ?>
            </span>
        <?php endforeach; ?>
    </div>
    <p class="text-xs text-gray-500">
        যেমন: <code class="bg-white px-1 rounded">You have received Tk {amount} from {number}. {*}TrxID {trxid} at {datetime}</code><br>
        <code>{*}</code> মানে "এই জায়গায় যা-ই থাকুক, দরকার নেই"। <b>{trxid}</b> আর <b>{amount}</b> দুটো অবশ্যই থাকতে হবে।
    </p>
</div>

<!-- ─── পরীক্ষার বাক্স + যোগ/এডিট ফর্ম ─── -->
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h2 class="font-bold text-gray-800 mb-3"><?= $edit ? '✏️ ছাঁচ এডিট' : '➕ নতুন ছাঁচ যোগ করুন' ?></h2>

    <?php
    $fMode     = $edit ? (trim((string) $edit['template']) === '' ? 'regex' : 'template') : 'template';
    $fTemplate = $edit['template'] ?? ($_GET['template'] ?? '');
    $fPattern  = $edit['pattern'] ?? ($_GET['pattern'] ?? '');
    $fFields   = $edit ? implode(',', psms_pattern_fields($edit)) : ($_GET['fields'] ?? '');
    $fSample   = $edit['sample_sms'] ?? ($_GET['sample_sms'] ?? '');
    if (($_GET['action'] ?? '') === 'test') {
        $fMode     = ($_GET['mode'] ?? 'template') === 'regex' ? 'regex' : 'template';
        $fTemplate = (string) ($_GET['template'] ?? '');
        $fPattern  = (string) ($_GET['pattern'] ?? '');
        $fFields   = (string) ($_GET['fields'] ?? '');
        $fSample   = (string) ($_GET['sample_sms'] ?? '');
    }
    ?>

    <form method="post" action="sms-patterns.php?action=save" id="spForm" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">নাম (আপনার বোঝার জন্য)</label>
                <input type="text" name="label" id="spLabel" value="<?= e($edit['label'] ?? '') ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2" placeholder="বিকাশ — টাকা পেয়েছি">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">কোন সেবা</label>
                <select name="provider" id="spProvider" class="w-full border border-gray-300 rounded-lg px-3 py-2">
                    <?php foreach (['' => '— বাছুন —', 'bkash' => 'বিকাশ', 'nagad' => 'নগদ', 'rocket' => 'রকেট', 'bank' => 'ব্যাংক', 'other' => 'অন্য'] as $pv => $pl): ?>
                        <option value="<?= e($pv) ?>" <?= ($edit['provider'] ?? '') === $pv ? 'selected' : '' ?>><?= e($pl) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ক্রম (ছোট আগে দেখা হয়)</label>
                <input type="number" name="sort_order" value="<?= (int) ($edit['sort_order'] ?? (count($patterns) + 1)) ?>"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2">
            </div>
        </div>

        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">নমুনা SMS (হুবহু কপি করে বসান)</label>
            <textarea name="sample_sms" id="spSample" rows="4"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" style="font-family:monospace"
                      placeholder="You have received Tk 750.00 from 01644170419. ..."><?= e($fSample) ?></textarea>
        </div>

        <div class="flex flex-wrap gap-4 items-center text-sm">
            <label class="flex items-center gap-2">
                <input type="radio" name="mode" value="template" <?= $fMode === 'template' ? 'checked' : '' ?> onchange="spMode()">
                <span>সহজ ছাঁচ ({amount} ইত্যাদি)</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="radio" name="mode" value="regex" <?= $fMode === 'regex' ? 'checked' : '' ?> onchange="spMode()">
                <span>উন্নত — কাঁচা regex</span>
            </label>
        </div>

        <div id="spTplBox">
            <label class="block text-sm font-bold text-gray-700 mb-1">ছাঁচ</label>
            <textarea name="template" id="spTemplate" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" style="font-family:monospace"
                      placeholder="You have received Tk {amount} from {number}. {*}TrxID {trxid} at {datetime}"><?= e($fTemplate) ?></textarea>
        </div>

        <div id="spReBox" style="display:none">
            <label class="block text-sm font-bold text-gray-700 mb-1">কাঁচা regex (ডেলিমিটার সহ, যেমন <code>~…~is</code>)</label>
            <textarea name="pattern" id="spPattern" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" style="font-family:monospace"><?= e($fPattern) ?></textarea>
            <label class="block text-sm font-bold text-gray-700 mb-1 mt-3">ঘরের ক্রম (কমা দিয়ে — বন্ধনীর ক্রম অনুযায়ী)</label>
            <input type="text" name="fields" id="spFields" value="<?= e($fFields) ?>"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm" style="font-family:monospace"
                   placeholder="amount,number,trxid,datetime">
        </div>

        <div class="flex flex-wrap gap-4 items-center text-sm">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_customer_payment" value="1"
                       <?= ($edit === null || !empty($edit['is_customer_payment'])) ? 'checked' : '' ?>>
                <span><b>এই ধরনের বার্তা গ্রাহকের পেমেন্ট</b> (টিক না দিলে নিজের ক্যাশ-ইন ধরা হবে, "অদাবিকৃত টাকা"-য় আসবে না)</span>
            </label>
            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_active" value="1" <?= ($edit === null || !empty($edit['is_active'])) ? 'checked' : '' ?>>
                <span>চালু</span>
            </label>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="spTest()" class="bg-gray-600 text-white px-4 py-2 rounded-lg text-sm font-bold">🧪 পরীক্ষা করুন</button>
            <button type="submit" class="bg-indigo-600 text-white px-5 py-2 rounded-lg text-sm font-bold" <?= $ready ? '' : 'disabled' ?>>
                <?= $edit ? 'হালনাগাদ করুন' : 'যোগ করুন' ?>
            </button>
            <?php if ($edit): ?>
                <a href="sms-patterns.php" class="px-4 py-2 rounded-lg text-sm border border-gray-300">বাতিল</a>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($testOut !== null): ?>
        <div class="mt-5 border-t pt-4">
            <p class="font-bold text-sm text-gray-800 mb-2">🧪 পরীক্ষার ফল</p>
            <?php if ($testOut['error'] !== ''): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm mb-2"><?= e($testOut['error']) ?></div>
            <?php endif; ?>
            <?php if ($testOut['hit']): ?>
                <div class="bg-green-50 border border-green-200 rounded-lg p-3 text-sm">
                    <p class="font-bold text-green-800 mb-2">✅ মিলেছে — যা যা উঠল:</p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1">
                        <?php
                        $show = [
                            'টাকার অঙ্ক'   => $testOut['hit']['amount'] !== null ? number_format((float) $testOut['hit']['amount'], 2) : '—',
                            'TrxID'        => $testOut['hit']['trxid_norm'] ?: '—',
                            'যে নম্বর থেকে' => $testOut['hit']['sender_number'] ?: '—',
                            'তারিখ ও সময়'  => $testOut['hit']['sent_at'] ?: '— (পড়া যায়নি)',
                            'ফি'           => $testOut['hit']['fee'] !== null ? number_format((float) $testOut['hit']['fee'], 2) : '—',
                            'Ref'          => $testOut['hit']['ref_text'] ?: '—',
                        ];
                        foreach ($show as $k => $v): ?>
                            <div class="flex justify-between gap-3 border-b border-green-100 py-1">
                                <span class="text-gray-600"><?= e($k) ?></span>
                                <span class="font-bold" style="font-family:monospace"><?= e((string) $v) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php elseif ($testOut['error'] === ''): ?>
                <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
                    ❌ ছাঁচটা এই নমুনার সাথে মেলেনি। প্রতিটা অক্ষর/যতিচিহ্ন হুবহু মিলছে কিনা দেখুন
                    (স্পেস/নতুন লাইন নিয়ে ভাবতে হবে না — সেটা নিজে থেকেই মিলে যায়)।
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- ─── তালিকা ─── -->
<?php if ($ready): ?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-3">
    <h2 class="font-bold text-gray-800">বর্তমান ছাঁচগুলো (<?= count($patterns) ?>)</h2>
    <form method="post" action="sms-patterns.php?action=reparse" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="bg-gray-600 text-white px-3 py-2 rounded-lg text-xs font-bold">
            ♻️ জমা থাকা SMS আবার পড়ান
        </button>
    </form>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">নাম</th>
                <th class="px-3 py-2">সেবা</th>
                <th class="px-3 py-2">ছাঁচ</th>
                <th class="px-3 py-2">গ্রাহকের পেমেন্ট?</th>
                <th class="px-3 py-2">চালু?</th>
                <th class="px-3 py-2">ক্রম</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$patterns): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">এখনো কোনো ছাঁচ নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($patterns as $p): ?>
            <tr class="border-t <?= empty($p['is_active']) ? 'opacity-60' : '' ?>">
                <td class="px-3 py-2"><div class="min-w-0 font-bold"><?= e($p['label']) ?></div></td>
                <td class="px-3 py-2"><?= e($p['provider'] ?: '—') ?></td>
                <td class="px-3 py-2">
                    <div class="min-w-0" style="max-width:420px;word-break:break-word;font-family:monospace;font-size:12px">
                        <?= e(trim((string) $p['template']) !== '' ? $p['template'] : $p['pattern']) ?>
                    </div>
                </td>
                <td class="px-3 py-2">
                    <form method="post" action="sms-patterns.php?action=toggle" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="field" value="customer">
                        <button type="submit" class="text-xs font-bold <?= !empty($p['is_customer_payment']) ? 'text-green-700' : 'text-gray-500' ?>">
                            <?= !empty($p['is_customer_payment']) ? '✓ হ্যাঁ' : '✕ না (নিজের)' ?>
                        </button>
                    </form>
                </td>
                <td class="px-3 py-2">
                    <form method="post" action="sms-patterns.php?action=toggle" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                        <input type="hidden" name="field" value="active">
                        <button type="submit" class="text-xs font-bold <?= !empty($p['is_active']) ? 'text-green-700' : 'text-gray-500' ?>">
                            <?= !empty($p['is_active']) ? '✓ চালু' : '✕ বন্ধ' ?>
                        </button>
                    </form>
                </td>
                <td class="px-3 py-2"><?= (int) $p['sort_order'] ?></td>
                <td class="px-3 py-2">
                    <div class="min-w-0 flex flex-wrap gap-2">
                        <a href="sms-patterns.php?edit=<?= (int) $p['id'] ?>" class="text-indigo-600 font-bold text-xs">এডিট</a>
                        <?php if (admin_can('settings', 'delete')): ?>
                        <form method="post" action="sms-patterns.php?action=delete" class="inline"
                              onsubmit="return confirmSubmit(this, 'এই ছাঁচটা মুছে ফেলবেন? এই ধরনের নতুন SMS আর পড়া যাবে না (জমা থাকা SMS মুছবে না)।')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="text-red-600 font-bold text-xs">মুছুন</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// মোড বদল — সহজ ছাঁচ ↔ কাঁচা regex
function spMode() {
    var m = document.querySelector('input[name="mode"]:checked');
    var isRe = m && m.value === 'regex';
    document.getElementById('spTplBox').style.display = isRe ? 'none' : '';
    document.getElementById('spReBox').style.display  = isRe ? '' : 'none';
}
spMode();

// 🧪 পরীক্ষা — GET দিয়ে নিজের পাতাতেই ফিরে আসে (কিছুই সেভ হয় না, তাই GET-ই ঠিক)
function spTest() {
    var m = document.querySelector('input[name="mode"]:checked');
    var q = new URLSearchParams({
        action: 'test',
        mode: m ? m.value : 'template',
        template: document.getElementById('spTemplate').value,
        pattern: document.getElementById('spPattern').value,
        fields: document.getElementById('spFields').value,
        sample_sms: document.getElementById('spSample').value
    });
    window.location = 'sms-patterns.php?' + q.toString();
}
</script>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>
