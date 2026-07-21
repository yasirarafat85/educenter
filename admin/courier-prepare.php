<?php
// কুরিয়ার পার্সেল প্রস্তুত (রিডিজাইন, COURIER-REDESIGN-PLAN.md ধাপ ৫) — একটা কোর্স-ব্যাচের সব confirmed
// রেজিস্ট্রেশনের জন্য প্রতি-ব্যক্তি কালেকশন বিল্ডার (auto-হিসাব) কার্ড, খসড়া courier_batches সেভ করে।
// additive — বিদ্যমান courier.php না ভেঙে। মোবাইল কার্ড-লেআউট।
//
// কার্ডে দুইটা আলাদা নিয়ন্ত্রণ (ইচ্ছাকৃতভাবে ভিন্ন জিনিস):
//   • সক্রিয়  → স্থায়ী registrations.courier_active (এই শিক্ষার্থী আদৌ কুরিয়ারে যাবে কিনা), দুই দিকেই ওয়ার্নিং
//   • নির্বাচন → শুধু এই দফায় কার পার্সেল তৈরি/পাঠানো হবে (সক্রিয় সবাই স্বয়ংক্রিয়ভাবে যায় না)
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
require_once __DIR__ . '/includes/courier-notes.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কুরিয়ার পার্সেল প্রস্তুত';

// প্রিসেট (settings; ডিফল্ট fallback)
$dc = [
    'dhaka'   => (float) (get_setting('courier_dc_dhaka')   ?: 60),
    'near'    => (float) (get_setting('courier_dc_near')    ?: 80),
    'outside' => (float) (get_setting('courier_dc_outside') ?: 120),
];
$wxExtra = (float) (get_setting('courier_weight_extra') ?: 20);

// POST: নির্বাচিত রেজিস্ট্রেশনের জন্য খসড়া courier_batch সেভ (auto-computed বা ম্যানুয়াল amount)।
// action=send-now হলে খসড়া তৈরির পরপরই সেটাকে আসল কুরিয়ারে পাঠানো হয় (send_courier_batch)।
$prepAction = $_POST['action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($prepAction, ['save-drafts', 'send-now'], true)) {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('courier-prepare.php');
    }
    $sendNow = ($prepAction === 'send-now');
    $provider = $sendNow ? get_active_courier_provider() : null;
    if ($sendNow && !$provider) {
        set_flash('error', 'কোনো সক্রিয় কুরিয়ার প্রোভাইডার সেট করা নেই (সেটিংস দেখুন)।');
        redirect('courier-prepare.php?item_id=' . (int) ($_POST['item_id'] ?? 0));
    }
    $iid    = (int) ($_POST['item_id'] ?? 0);
    // মাস/পার্সেল লেবেল **বাধ্যতামূলক** (শুধু এই পেজে) — খালি থাকলে কোনো খসড়া তৈরি হবে না, পাঠানোও হবে না।
    // কারণ এই লেবেল দিয়েই পরে কুরিয়ার পেজে/ট্র্যাকিংয়ে "কোন মাসের পার্সেল" খুঁজে বের করা হয় —
    // লেবেল ছাড়া ব্যাচ তৈরি হলে সেটা আর আলাদা করে চেনা যায় না।
    $period = trim($_POST['period'] ?? '');
    if ($period === '') {
        set_flash('error', 'মাস/পার্সেল লেবেল দিতে হবে (যেমন "১ম মাস") — এটা ছাড়া পার্সেল তৈরি বা পাঠানো যাবে না।');
        redirect('courier-prepare.php?item_id=' . $iid);
    }
    $feeStmt = $db->prepare('SELECT price FROM course_batches WHERE id = :id');
    $feeStmt->execute(['id' => $iid]);
    $courseFee = parse_price_to_number($feeStmt->fetchColumn() ?: '0');

    $ins = $db->prepare(
        'INSERT INTO courier_batches
            (registration_id, period_label, item_description, item_quantity, amount_to_collect,
             monthly_multiplier, delivery_zone, weight_extra, adjustment, adjustment_reason, send_status)
         VALUES (:r, :p, :d, 1, :amt, :m, :z, :wx, :adj, :rs, "draft")'
    );
    $activeStmt = $db->prepare('UPDATE registrations SET courier_active = :a WHERE id = :id');

    // send-now এর জন্য রেজিস্ট্রেশন রো লাগে (send_courier_batch এর $order প্যারামিটার)
    $regMap = [];
    if ($sendNow) {
        $rm = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id");
        $rm->execute(['id' => $iid]);
        foreach ($rm->fetchAll() as $rr) { $regMap[(int) $rr['id']] = $rr; }
    }

    $saved = 0; $sent = 0; $failed = 0;
    foreach (($_POST['bd'] ?? []) as $rid => $row) {
        $rid = (int) $rid;
        if (empty($row['present'])) {
            continue; // কার্ডই রেন্ডার হয়নি — উপেক্ষা
        }
        $isActive = !empty($row['active']);
        // "সক্রিয়" টগলটা স্থায়ী — প্রতিবার সেভে registrations.courier_active আপডেট হয় (courier.php-এর সাথে সংগতি রেখে)
        $activeStmt->execute(['a' => $isActive ? 1 : 0, 'id' => $rid]);

        if (!$isActive || empty($row['sel'])) {
            continue; // নিষ্ক্রিয় অথবা এই দফায় নির্বাচন করা হয়নি — পার্সেল তৈরি হবে না
        }

        $mult = (float) ($row['mult'] ?? 1);
        $zone = in_array($row['zone'] ?? '', ['dhaka', 'near', 'outside'], true) ? $row['zone'] : 'dhaka';
        $wx   = !empty($row['wx']);
        $adj  = (float) ($row['adj'] ?? 0);
        // অটো হিসাব; অ্যাডমিন ম্যানুয়ালি এডিট করলে (ওয়ার্নিং দেখিয়ে আনলক করা হয়) সেই মানই ব্যবহার হয়
        $amt  = courier_compute_collection($courseFee, $mult, $zone, $wx, $adj);
        if (!empty($row['manual']) && is_numeric($row['amt'] ?? null)) {
            $amt = max(0, round((float) $row['amt']));
        }
        $ins->execute([
            'r'   => $rid, 'p' => $period, 'd' => trim($row['desc'] ?? '') ?: null,
            'amt' => $amt, 'm' => $mult, 'z' => $zone, 'wx' => $wx ? 1 : 0,
            'adj' => $adj, 'rs' => trim($row['reason'] ?? '') ?: null,
        ]);
        $saved++;

        if ($sendNow) {
            $draftId = (int) $db->lastInsertId();
            $reg = $regMap[$rid] ?? null;
            if ($reg) {
                try {
                    // $post খালি — save_courier_batch এই খসড়ার বিদ্যমান মান (amount/description) রেখেই পাঠায়
                    $res = send_courier_batch($db, $provider, $reg, [], $draftId);
                    if (!empty($res['success'])) { $sent++; } else { $failed++; }
                } catch (Throwable $e) {
                    $failed++;
                }
            } else {
                $failed++;
            }
        }
    }

    if (!$saved) {
        set_flash('error', 'কাউকে নির্বাচন করা হয়নি — যাদের পার্সেল পাঠাবেন তাদের "নির্বাচন" টিক দিন।');
    } elseif ($sendNow) {
        set_flash($failed ? 'error' : 'success',
            "\"$period\" — $saved টি পার্সেল তৈরি, $sent টি কুরিয়ারে পাঠানো সফল"
            . ($failed ? ", $failed টি ব্যর্থ (কুরিয়ার পেজে বিস্তারিত দেখুন)" : '') . '।');
    } else {
        set_flash('success', "\"$period\" — $saved টি খসড়া তৈরি হয়েছে। কুরিয়ার পেজ থেকে যাচাই করে পাঠাতে পারবেন।");
    }
    redirect('courier-prepare.php?item_id=' . $iid . '&period=' . urlencode($period));
}

$itemId = (int) ($_GET['item_id'] ?? 0);
// ডিফল্টে খালি — অ্যাডমিনকে প্রতিবার সচেতনভাবে মাস লিখতে হবে (আগে "১ম মাস" বসে থাকত, ফলে ভুল করে
// একই লেবেলে বারবার পার্সেল তৈরি হয়ে যাওয়ার ঝুঁকি ছিল)
$period = trim($_GET['period'] ?? '');

require __DIR__ . '/includes/layout-top.php';

// ── কোর্স-ব্যাচ বাছাই (item_id না থাকলে) ──
if (!$itemId) {
    $batches = $db->query(
        "SELECT item_id, item_title, batch, COUNT(*) c FROM registrations
         WHERE type='course' AND status='confirmed' GROUP BY item_id, item_title, batch ORDER BY item_title, batch"
    )->fetchAll();
    ?>
    <div class="max-w-2xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচের পার্সেল প্রস্তুত করবেন সেটা বেছে নিন। প্রতিটা রেজিস্ট্রেশনের কালেকশন পরিমাণ (মান্থলি ফি + ডেলিভারি + সমন্বয়) অটো হিসাব হবে।</p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="package" class="w-8 h-8"></i></div>কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50"><th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">রেজিস্ট্রেশন</th><th class="py-3 px-4">অ্যাকশন</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><?= e($b['batch'] ?: '—') ?></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><a href="courier-prepare.php?item_id=<?= (int) $b['item_id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">প্রস্তুত করুন →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/includes/layout-bottom.php';
    exit;
}

// ── নির্দিষ্ট ব্যাচের রেজিস্ট্রেশন কার্ড ──
$feeStmt = $db->prepare('SELECT cb.price, cb.batch_name, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$feeStmt->execute(['id' => $itemId]);
$course = $feeStmt->fetch();
$courseFee = parse_price_to_number($course['price'] ?? '0');

$regs = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

// প্রতিটা রেজিস্ট্রেশনের নোট (রঙসহ) — registrations.php থেকে দেওয়া নোটও এখানেই আসে
$notesByReg = fetch_registration_notes($db, array_map(fn($r) => (int) $r['id'], $regs));

// এই মাসের (period) জন্য ইতিমধ্যে কার পার্সেল তৈরি হয়ে গেছে — দুইবার তৈরি ঠেকাতে সতর্কতা দেখানো হয়
// ($period খালি থাকলে চেক করার কিছু নেই — খালি লেবেলে ব্যাচ তৈরিই হতে দেওয়া হয় না)
$alreadyThisPeriod = [];
if ($regs && $period !== '') {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $regs));
    $ap = $db->prepare("SELECT registration_id, send_status FROM courier_batches WHERE registration_id IN ($ids) AND period_label = :p");
    $ap->execute(['p' => $period]);
    foreach ($ap->fetchAll() as $a) { $alreadyThisPeriod[(int) $a['registration_id']] = $a['send_status']; }
}

/** কার্ডের তথ্য-সারি: লেবেল + মান (মান খালি হলে ম্লান "—") */
function prep_field(string $label, ?string $value, string $icon = ''): string
{
    $val = trim((string) $value);
    $ic  = $icon ? '<i data-lucide="' . e($icon) . '" class="w-3.5 h-3.5 inline-block text-gray-400 flex-shrink-0"></i> ' : '';
    return '<div class="flex items-start gap-1.5 min-w-0">'
        . '<span class="text-xs text-gray-500 flex-shrink-0" style="min-width:88px;">' . $ic . e($label) . '</span>'
        . '<span class="text-xs font-semibold text-gray-800 break-words min-w-0">' . ($val !== '' ? e($val) : '<span class="text-gray-300 font-normal">—</span>') . '</span>'
        . '</div>';
}
?>
<div class="max-w-2xl">
    <a href="courier-prepare.php" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm mb-3 py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> সব কোর্স-ব্যাচ</a>

    <form method="post" id="prepForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" id="prepAction" value="save-drafts">
        <input type="hidden" name="item_id" value="<?= (int) $itemId ?>">

        <div class="bg-white rounded-2xl shadow p-4 mb-4">
            <div class="flex items-start justify-between gap-3 flex-wrap">
                <div class="min-w-0">
                    <div class="text-xs text-gray-400">কোর্স</div>
                    <div class="font-bold text-gray-800 break-words"><?= e($course['title'] ?? '') ?></div>
                    <div class="text-xs text-gray-400 mt-1">ব্যাচ</div>
                    <div class="font-semibold text-indigo-600 text-sm break-words"><?= e($course['batch_name'] ?? '') ?></div>
                    <div class="text-xs text-gray-500 mt-2">মান্থলি ফি (কোর্স-ফি): ৳<?= e(number_format($courseFee)) ?> · প্রিসেট: ঢাকা ৳<?= (int) $dc['dhaka'] ?> · কাছে ৳<?= (int) $dc['near'] ?> · বাইরে ৳<?= (int) $dc['outside'] ?> · ওজন+ ৳<?= (int) $wxExtra ?></div>
                </div>
                <label class="text-xs text-gray-600 flex-shrink-0">
                    মাস/পার্সেল <span class="text-red-500 font-bold">*</span>
                    <input type="text" name="period" id="periodInput" value="<?= e($period) ?>" required placeholder="যেমন: ১ম মাস"
                           class="block border rounded-lg px-3 py-2 text-sm mt-1" style="width:150px;" list="periodList">
                    <datalist id="periodList"><option value="১ম মাস"><option value="২য় মাস"><option value="৩য় মাস"><option value="৪র্থ মাস"><option value="৫ম মাস"><option value="৬ষ্ঠ মাস"></datalist>
                    <span class="block text-[11px] text-gray-400 mt-1" style="max-width:150px;">না দিলে পার্সেল তৈরি বা পাঠানো যাবে না</span>
                </label>
            </div>
        </div>

        <?php if (!$regs): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>এই ব্যাচে কোনো confirmed রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="space-y-3 pb-32">
            <?php foreach ($regs as $r):
                $rid = (int) $r['id'];
                $notes = $notesByReg[$rid] ?? [];
                $active = (int) ($r['courier_active'] ?? 1);
                $done = $alreadyThisPeriod[$rid] ?? null;
            ?>
                <div class="stu bg-white rounded-2xl shadow p-4 <?= $active ? '' : 'opacity-60' ?>" data-fee="<?= (int) $courseFee ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][present]" value="1">

                    <?php // ── হেডার: নির্বাচন + নাম + সক্রিয় টগল ── ?>
                    <div class="flex items-start gap-2.5 mb-3">
                        <label class="flex items-center pt-0.5 cursor-pointer flex-shrink-0" title="এই দফায় পাঠাবেন?">
                            <input type="checkbox" class="sel w-5 h-5 accent-indigo-600" name="bd[<?= $rid ?>][sel]" value="1" <?= $active ? '' : 'disabled' ?>>
                        </label>
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] text-gray-400 leading-tight">শিশুর নাম</div>
                            <div class="font-bold text-gray-900 text-sm break-words"><?= e($r['customer_name']) ?></div>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer flex-shrink-0" title="সক্রিয়/নিষ্ক্রিয়">
                            <span class="relative inline-block w-11 h-6 flex-shrink-0">
                                <input type="checkbox" class="act sr-only peer" name="bd[<?= $rid ?>][active]" value="1" <?= $active ? 'checked' : '' ?>
                                       onchange="prepActiveWarn(this)" data-name="<?= e($r['customer_name']) ?>">
                                <span class="absolute inset-0 bg-gray-300 peer-checked:bg-indigo-600 rounded-full transition-colors"></span>
                                <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow-md transition-transform peer-checked:translate-x-5"></span>
                            </span>
                            <span class="text-xs font-semibold text-gray-600">সক্রিয়</span>
                        </label>
                    </div>

                    <?php // ── লেবেল করা তথ্য (কোনটা কিসের ডেটা স্পষ্ট বোঝা যায়) ── ?>
                    <div class="grid grid-cols-1 gap-1 bg-gray-50 rounded-xl p-3 mb-2">
                        <?= prep_field('ফেসবুক আইডি', $r['facebook_id'], 'at-sign') ?>
                        <?= prep_field('রিসিভার নাম', $r['receiver_name'], 'user') ?>
                        <?= prep_field('রিসিভার নম্বর', $r['receiver_phone'] ?: $r['phone'], 'phone') ?>
                        <?= prep_field('ঠিকানা', $r['address'], 'map-pin') ?>
                        <?php if (trim((string) $r['notes']) !== ''): ?>
                            <?= prep_field('বিশেষ মন্তব্য', $r['notes'], 'message-square') ?>
                        <?php endif; ?>
                    </div>

                    <?php // ── নোট চিপ (registrations.php বা নোট পেজ থেকে দেওয়া) ── ?>
                    <div class="flex flex-wrap items-center gap-1.5 mb-3">
                        <?php render_note_chips($notes); // ডিলিট ফর্ম ছাড়া — এই চিপগুলো একটা বড় ফর্মের ভেতরে (নেস্টেড ফর্ম চলে না) ?>
                        <a href="courier-note-assign.php?registration_id=<?= $rid ?>&return=<?= urlencode('courier-prepare.php?item_id=' . $itemId . '&period=' . $period) ?>" class="text-xs text-indigo-600 font-semibold whitespace-nowrap inline-block py-1"><i data-lucide="plus" class="w-3 h-3 inline"></i> নোট</a>
                        <?php if ($done !== null): ?>
                            <span class="text-xs px-2 py-0.5 rounded-lg bg-blue-50 text-blue-700 font-semibold">এই মাসের পার্সেল ইতিমধ্যে তৈরি<?= $done === 'sent' ? ' ও পাঠানো' : '' ?></span>
                        <?php endif; ?>
                    </div>

                    <?php // ── কালেকশন বিল্ডার ── ?>
                    <div class="grid grid-cols-2 gap-2">
                        <label class="text-xs text-gray-600">মান্থলি ফি<select name="bd[<?= $rid ?>][mult]" class="mult block w-full border rounded-lg px-2 py-2 text-sm mt-1"><option value="1">১ মাস</option><option value="1.5">১.৫ মাস</option><option value="2">২ মাস</option></select></label>
                        <label class="text-xs text-gray-600">ডেলিভারি<select name="bd[<?= $rid ?>][zone]" class="zone block w-full border rounded-lg px-2 py-2 text-sm mt-1"><option value="dhaka">ঢাকা</option><option value="near">নিকটবর্তী</option><option value="outside">বাইরে</option></select></label>
                    </div>
                    <div class="grid gap-2 mt-2" style="grid-template-columns:auto 1fr 1.1fr; align-items:end;">
                        <label class="flex items-center gap-1 text-xs text-gray-600 pb-2"><input type="checkbox" class="wx w-4 h-4 accent-indigo-600" name="bd[<?= $rid ?>][wx]" value="1"> ওজন+</label>
                        <label class="text-xs text-gray-600">সমন্বয়±<input type="number" step="1" value="0" name="bd[<?= $rid ?>][adj]" class="adj block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                        <label class="text-xs text-gray-600">কারণ<input type="text" name="bd[<?= $rid ?>][reason]" placeholder="ডিসকাউন্ট..." class="block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                    </div>

                    <?php // ── কালেকশন: অটো, তবে ওয়ার্নিং দেখিয়ে এডিট করা যায় ── ?>
                    <div class="flex items-end justify-between gap-2 mt-3 pt-3 border-t">
                        <div class="min-w-0">
                            <div class="text-xs text-gray-500">কালেকশন</div>
                            <div class="autohint text-[11px] text-gray-400 hidden">অটো হিসাব: ৳<span class="autoval">০</span> · <button type="button" class="text-indigo-600 font-semibold underline resetauto">অটোতে ফিরুন</button></div>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <span class="text-lg font-black text-gray-900">৳</span>
                            <input type="number" step="1" min="0" name="bd[<?= $rid ?>][amt]" class="amt border rounded-lg px-2 py-1.5 text-lg font-black text-gray-900 text-right bg-gray-50" style="width:110px;" readonly>
                            <input type="hidden" name="bd[<?= $rid ?>][manual]" class="manual" value="">
                            <button type="button" class="editamt text-gray-400 hover:text-indigo-600 p-1.5" title="পরিমাণ এডিট করুন"><i data-lucide="lock" class="w-4 h-4"></i></button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <?php // ── স্টিকি অ্যাকশন বার ── ?>
            <div class="fixed inset-x-0 bottom-0 bg-white border-t border-gray-200 px-4 py-3 z-30" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
                <div class="max-w-2xl mx-auto flex items-center gap-3 flex-wrap">
                    <label class="flex items-center gap-1.5 text-xs font-semibold text-gray-700 cursor-pointer flex-shrink-0">
                        <input type="checkbox" id="selAll" class="w-4 h-4 accent-indigo-600"> সব নির্বাচন
                    </label>
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500">নির্বাচিত <span id="acount">০</span> টি · মোট কালেকশন</div>
                        <div id="asum" class="text-lg font-black text-gray-900">৳০</div>
                    </div>
                    <button type="button" id="draftBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="save" class="w-4 h-4 inline"></i> খসড়া</button>
                    <button type="button" id="sendBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4 inline"></i> কুরিয়ারে পাঠান</button>
                </div>
            </div>
        <?php endif; ?>
    </form>
</div>

<script>
(function(){
  var bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
  function toBn(n){return String(n).replace(/[0-9]/g,function(d){return bn[d];});}
  var DC={dhaka:<?= (float) $dc['dhaka'] ?>,near:<?= (float) $dc['near'] ?>,outside:<?= (float) $dc['outside'] ?>};
  var WX=<?= (float) $wxExtra ?>;

  // একটা কার্ডের অটো-হিসাব; ম্যানুয়াল মোডে amt ইনপুট ছোঁয়া হয় না (শুধু "অটো হিসাব" হিন্ট আপডেট হয়)
  function calc(el){
    var fee=+el.getAttribute('data-fee'), mult=+el.querySelector('.mult').value, zone=el.querySelector('.zone').value;
    var wx=el.querySelector('.wx').checked?WX:0, adj=+el.querySelector('.adj').value||0;
    var auto=Math.max(0,Math.round(fee*mult+(DC[zone]||0)+wx+adj));
    var amtEl=el.querySelector('.amt'), manual=el.querySelector('.manual').value==='1';
    el.querySelector('.autoval').textContent=toBn(auto);
    if(!manual){ amtEl.value=auto; }
    var active=el.querySelector('.act').checked, selEl=el.querySelector('.sel');
    selEl.disabled=!active;
    if(!active){ selEl.checked=false; }
    el.classList.toggle('opacity-60',!active);
    return {on:active&&selEl.checked, t:+amtEl.value||0};
  }
  function refresh(){
    var s=0,c=0;
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el);if(r.on){s+=r.t;c++;}});
    var ac=document.getElementById('acount'),as=document.getElementById('asum');
    if(ac)ac.textContent=toBn(c);
    if(as)as.textContent='৳'+toBn(s);
  }

  // সক্রিয়/নিষ্ক্রিয় — দুই দিকেই একবার নিশ্চিতকরণ (ইউজারের স্পষ্ট চাওয়া)
  window.prepActiveWarn=function(cb){
    var turningOn=cb.checked, nm=cb.dataset.name||'এই শিক্ষার্থী';
    cb.checked=!turningOn; // নিশ্চিত না করা পর্যন্ত আগের অবস্থাতেই থাকবে
    showConfirmModal(
      turningOn ? ('"'+nm+'" কে আবার সক্রিয় করবেন? এরপর থেকে এই শিক্ষার্থীর পার্সেল তৈরি করা যাবে।')
                : ('"'+nm+'" কে নিষ্ক্রিয় করবেন? নিষ্ক্রিয় থাকলে এই শিক্ষার্থীর কোনো পার্সেল তৈরি/পাঠানো যাবে না।'),
      function(){ cb.checked=turningOn; refresh(); },
      turningOn ? 'সক্রিয় করবেন?' : 'নিষ্ক্রিয় করবেন?'
    );
  };

  // কালেকশন এডিট আনলক (ওয়ার্নিং সহ) ও অটোতে ফেরত
  document.querySelectorAll('.stu').forEach(function(el){
    var amtEl=el.querySelector('.amt'), manualEl=el.querySelector('.manual'),
        btn=el.querySelector('.editamt'), hint=el.querySelector('.autohint');
    btn.addEventListener('click',function(){
      if(manualEl.value==='1'){ return; }
      showConfirmModal(
        'কালেকশনের পরিমাণ নিজে লিখবেন? এরপর অটো হিসাব (মান্থলি ফি + ডেলিভারি + সমন্বয়) আর প্রযোজ্য হবে না — আপনার লেখা পরিমাণই কুরিয়ারে যাবে।',
        function(){
          manualEl.value='1'; amtEl.readOnly=false;
          amtEl.classList.remove('bg-gray-50'); amtEl.classList.add('bg-yellow-50','ring-2','ring-amber-300');
          btn.innerHTML='<i data-lucide="pencil" class="w-4 h-4"></i>';
          hint.classList.remove('hidden');
          if(window.lucide&&lucide.createIcons)lucide.createIcons();
          amtEl.focus(); amtEl.select();
        },
        'পরিমাণ এডিট করবেন?'
      );
    });
    el.querySelector('.resetauto').addEventListener('click',function(){
      manualEl.value=''; amtEl.readOnly=true;
      amtEl.classList.add('bg-gray-50'); amtEl.classList.remove('bg-yellow-50','ring-2','ring-amber-300');
      btn.innerHTML='<i data-lucide="lock" class="w-4 h-4"></i>';
      hint.classList.add('hidden');
      if(window.lucide&&lucide.createIcons)lucide.createIcons();
      refresh();
    });
  });

  var selAll=document.getElementById('selAll');
  if(selAll){
    selAll.addEventListener('change',function(){
      document.querySelectorAll('.stu .sel').forEach(function(c){ if(!c.disabled) c.checked=selAll.checked; });
      refresh();
    });
  }

  var f=document.getElementById('prepForm'), periodEl=document.getElementById('periodInput');

  // মাস/পার্সেল লেবেল বাধ্যতামূলক — খালি থাকলে খসড়া বা পাঠানো কোনোটাই হবে না (সার্ভারেও একই চেক আছে)
  function periodOk(){
    if(periodEl && periodEl.value.trim()!==''){ return true; }
    showConfirmModal(
      'উপরের "মাস/পার্সেল" ঘরটা খালি — কোন মাসের পার্সেল সেটা লিখুন (যেমন "১ম মাস")। এটা ছাড়া খসড়া তৈরি বা কুরিয়ারে পাঠানো যাবে না।',
      function(){ if(periodEl){ periodEl.focus(); } },
      'মাস/পার্সেল লিখুন'
    );
    if(periodEl){ periodEl.classList.add('ring-2','ring-red-400'); }
    return false;
  }
  if(periodEl){
    periodEl.addEventListener('input',function(){ periodEl.classList.remove('ring-2','ring-red-400'); });
  }

  // নির্বাচিত কতজন ও মোট কত — কনফার্মেশনে দেখানোর জন্য
  function selectedSummary(){
    var n=0,s=0;
    document.querySelectorAll('.stu').forEach(function(el){
      if(el.querySelector('.act').checked&&el.querySelector('.sel').checked){ n++; s+=+el.querySelector('.amt').value||0; }
    });
    return {n:n,s:s};
  }
  function noneSelected(){
    showConfirmModal('কাউকে নির্বাচন করা হয়নি। যাদের পার্সেল পাঠাবেন তাদের বাঁ পাশের টিক দিন।',function(){},'নির্বাচন খালি');
  }

  var draftBtn=document.getElementById('draftBtn');
  if(draftBtn){
    draftBtn.addEventListener('click',function(){
      if(!periodOk()){ return; }
      var r=selectedSummary();
      if(!r.n){ noneSelected(); return; }
      document.getElementById('prepAction').value='save-drafts';
      f.submit();
    });
  }

  var sendBtn=document.getElementById('sendBtn');
  if(sendBtn){
    sendBtn.addEventListener('click',function(){
      if(!periodOk()){ return; }
      var r=selectedSummary();
      if(!r.n){ noneSelected(); return; }
      showConfirmModal(
        '"'+periodEl.value.trim()+'" — নির্বাচিত '+toBn(r.n)+' জনের পার্সেল এখনই আসল কুরিয়ারে পাঠাতে চান? মোট কালেকশন ৳'+toBn(r.s)+'। পাঠানোর পর আর ফেরানো যাবে না।',
        function(){ document.getElementById('prepAction').value='send-now'; f.submit(); },
        'কুরিয়ারে পাঠান'
      );
    });
  }

  if(f){ f.addEventListener('input',refresh); f.addEventListener('change',refresh); refresh(); }
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
