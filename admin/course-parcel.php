<?php
// কোর্স পার্সেল — "পার্সেল প্রস্তুত" + "কোর্স ট্র্যাকিং" এক পেজে (২০২৬-০৮-১৬, ডিজাইন-রিভিউ v3 পলিশ)।
// মডেল: total_parcels=N → ফিক্সড মাস ১..N (Excel শিটের মতো ট্যাব)। প্রতি (reg, মাস)-এ একটা courier_batch (upsert)।
//   • Section 1 "অবস্থা": শিক্ষার্থী × মাস ম্যাট্রিক্স + গ্রুপ/সক্রিয় টগল।
//   • Section 2 "এই মাসের পার্সেল": মাস-ট্যাব বেছে যাবে/না + কালেকশন (সরাসরি এডিটযোগ্য) + খসড়া/পাঠান।
// পলিশ: সংখ্যা English (বাংলা ডিজিট পড়া কঠিন), গ্রুপ টগল স্টাইলড (ইমোজি না), মান্থলি-ফি মাল্টিপ্লায়ার বাদ,
//   পরিমাণ সরাসরি লেখা যায় (আগে লক ছিল)। hide_parcel বাদ; সংবেদনশীল বদলে প্রথমবার-ছাড়া ওয়ার্নিং।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
require_once __DIR__ . '/includes/courier-notes.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কোর্স পার্সেল';

// মাস নম্বর → সংরক্ষণ-লেবেল (courier_batches.period_label এখানেই সেভ; স্থায়ী রাখা — পুরনো ডেটা মেলাতে)
function cp_month_label(int $i): string
{
    static $names = [1 => '১ম মাস', 2 => '২য় মাস', 3 => '৩য় মাস', 4 => '৪র্থ মাস', 5 => '৫ম মাস', 6 => '৬ষ্ঠ মাস',
                     7 => '৭ম মাস', 8 => '৮ম মাস', 9 => '৯ম মাস', 10 => '১০ম মাস', 11 => '১১তম মাস', 12 => '১২তম মাস'];
    if (isset($names[$i])) { return $names[$i]; }
    return strtr((string) $i, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']) . 'তম মাস';
}
// প্রদর্শনের জন্য (English সংখ্যা — পড়া সহজ)
function cp_month_display(int $i): string { return 'মাস ' . $i; }

function cp_url(int $itemId = 0, int $month = 0): string
{
    $u = 'course-parcel.php';
    if ($itemId) { $u .= '?item_id=' . $itemId; if ($month) { $u .= '&month=' . $month; } }
    return $u;
}

$dc = [
    'dhaka'   => (float) (get_setting('courier_dc_dhaka')   ?: 60),
    'near'    => (float) (get_setting('courier_dc_near')    ?: 80),
    'outside' => (float) (get_setting('courier_dc_outside') ?: 120),
];
$wxExtra = (float) (get_setting('courier_weight_extra') ?: 20);

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('course-parcel.php'); }
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $regId  = (int) ($_POST['id'] ?? 0);
    $month  = (int) ($_POST['month'] ?? 0);

    if ($action === 'group') {
        $field = ($_POST['field'] ?? '') === 'messenger' ? 'messenger_group_added' : 'fb_group_added';
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare("UPDATE registrations SET $field = :v WHERE id = :id")->execute(['v' => $val, 'id' => $regId]);
        redirect(cp_url($itemId, $month));

    } elseif ($action === 'active') {
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare('UPDATE registrations SET courier_active = :v WHERE id = :id')->execute(['v' => $val, 'id' => $regId]);
        set_flash('success', $val ? 'সক্রিয় করা হলো।' : 'নিষ্ক্রিয় করা হলো — এই শিক্ষার্থী আর মাসের তালিকায় আসবে না।');
        redirect(cp_url($itemId, $month));

    } elseif ($action === 'parcels') {
        $n = max(0, min(60, (int) ($_POST['total_parcels'] ?? 0)));
        $db->prepare('UPDATE course_batches SET total_parcels = :n WHERE id = :id')->execute(['n' => $n, 'id' => $itemId]);
        set_flash('success', 'মোট পার্সেল সংখ্যা সেভ হয়েছে — ' . $n . ' টি মাস।');
        redirect(cp_url($itemId, min($month ?: 1, max($n, 1))));

    } elseif ($action === 'round-save') {
        $mode  = ($_POST['mode'] ?? 'draft') === 'send' ? 'send' : 'draft';
        if ($month < 1) { $month = 1; }
        $period = cp_month_label($month);
        $provider = ($mode === 'send') ? get_active_courier_provider() : null;
        if ($mode === 'send' && !$provider) {
            set_flash('error', 'কোনো সক্রিয় কুরিয়ার প্রোভাইডার সেট করা নেই (সেটিংস দেখুন)।');
            redirect(cp_url($itemId, $month));
        }

        $feeStmt = $db->prepare('SELECT price FROM course_batches WHERE id = :id');
        $feeStmt->execute(['id' => $itemId]);
        $courseFee = parse_price_to_number($feeStmt->fetchColumn() ?: '0');

        $rm = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' AND courier_active = 1");
        $rm->execute(['id' => $itemId]);
        $regMap = [];
        foreach ($rm->fetchAll() as $rr) { $regMap[(int) $rr['id']] = $rr; }

        $findBatch = $db->prepare('SELECT id, send_status FROM courier_batches WHERE registration_id = :r AND period_label = :p ORDER BY id DESC LIMIT 1');
        $insDraft = $db->prepare(
            'INSERT INTO courier_batches
                (registration_id, period_label, item_description, item_quantity, amount_to_collect,
                 monthly_multiplier, delivery_zone, weight_extra, adjustment, adjustment_reason, send_status)
             VALUES (:r, :p, :d, 1, :amt, 1, :z, :wx, :adj, :rs, "draft")'
        );
        $updDraft = $db->prepare(
            'UPDATE courier_batches SET item_description=:d, amount_to_collect=:amt, monthly_multiplier=1,
                 delivery_zone=:z, weight_extra=:wx, adjustment=:adj, adjustment_reason=:rs, send_status="draft"
             WHERE id = :id'
        );
        $insDeclined = $db->prepare('INSERT INTO courier_batches (registration_id, period_label, amount_to_collect, send_status) VALUES (:r, :p, 0, "declined")');
        $updDeclined = $db->prepare('UPDATE courier_batches SET send_status="declined", amount_to_collect=0 WHERE id = :id');
        // পাঠানো ব্যাচের ফিল্ড আপডেট (রেকর্ড সংশোধন) — send_status ছোঁয় না
        $updSent = $db->prepare('UPDATE courier_batches SET item_description=:d, amount_to_collect=:amt, delivery_zone=:z, weight_extra=:wx, adjustment=:adj, adjustment_reason=:rs WHERE id = :id');

        $prep = 0; $declined = 0; $sent = 0; $resent = 0; $failed = []; $editedSent = 0;
        foreach (($_POST['bd'] ?? []) as $rid => $row) {
            $rid = (int) $rid;
            if (empty($row['present']) || !isset($regMap[$rid])) { continue; }
            $decision = ($row['decision'] ?? 'go') === 'no' ? 'no' : 'go';
            $resend   = !empty($row['resend']); // পাঠানো কার্ডে "আবার পাঠান" armed কিনা

            $findBatch->execute(['r' => $rid, 'p' => $period]);
            $existing = $findBatch->fetch();
            $existId = $existing ? (int) $existing['id'] : 0;
            $alreadySent = $existing && $existing['send_status'] === 'sent';

            // কালেকশন (নতুন/সংশোধন/আবার-পাঠানো সব ক্ষেত্রে দরকার)
            $zone = in_array($row['zone'] ?? '', ['dhaka', 'near', 'outside'], true) ? $row['zone'] : 'dhaka';
            $wx   = !empty($row['wx']);
            $adj  = (float) ($row['adj'] ?? 0);
            $amt  = courier_compute_collection($courseFee, 1, $zone, $wx, $adj); // মাল্টিপ্লায়ার সবসময় ১
            if (is_numeric($row['amt'] ?? null)) { $amt = max(0, round((float) $row['amt'])); } // সরাসরি লেখা পরিমাণই চূড়ান্ত
            $desc   = trim($row['desc'] ?? '') ?: null;
            $reason = trim($row['reason'] ?? '') ?: null;

            // ── ইতিমধ্যে পাঠানো ব্যাচ: আনলক করা না থাকলে অপরিবর্তিত (দুর্ঘটনা এড়াতে) ──
            if ($alreadySent) {
                if (empty($row['unlocked'])) { continue; } // 🔓 আনলক না করলে ছোঁয়া হবে না
                $updSent->execute(['d' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'id' => $existId]);
                if ($mode === 'send' && $resend) {
                    try {
                        $res = send_courier_batch($db, $provider, $regMap[$rid], [], $existId); // নতুন consignment
                        if (!empty($res['success'])) { $sent++; $resent++; }
                        else { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => (string) ($res['message'] ?? 'অজানা কারণ')]; }
                    } catch (Throwable $e) { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => $e->getMessage()]; }
                } else {
                    $editedSent++; // শুধু তথ্য আপডেট, আবার পাঠানো হয়নি
                }
                continue;
            }

            if ($decision === 'no') {
                if ($existId) { $updDeclined->execute(['id' => $existId]); }
                else { $insDeclined->execute(['r' => $rid, 'p' => $period]); }
                $declined++;
                continue;
            }

            if ($existId) {
                $updDraft->execute(['d' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'id' => $existId]);
                $draftId = $existId;
            } else {
                $insDraft->execute(['r' => $rid, 'p' => $period, 'd' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason]);
                $draftId = (int) $db->lastInsertId();
            }
            $prep++;

            if ($mode === 'send') {
                try {
                    $res = send_courier_batch($db, $provider, $regMap[$rid], [], $draftId);
                    if (!empty($res['success'])) { $sent++; }
                    else { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => (string) ($res['message'] ?? 'অজানা কারণ')]; }
                } catch (Throwable $e) {
                    $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => $e->getMessage()];
                }
            }
        }

        if ($mode === 'send') {
            $_SESSION['cp_result'] = ['item' => $itemId, 'period' => cp_month_display($month), 'sent' => $sent, 'resent' => $resent, 'prep' => $prep, 'declined' => $declined, 'failed' => $failed, 'edited' => $editedSent];
        } else {
            $bits = [];
            if ($prep)       { $bits[] = $prep . ' জন প্রস্তুত'; }
            if ($declined)   { $bits[] = $declined . ' জন "না"'; }
            if ($editedSent) { $bits[] = $editedSent . ' জন (পাঠানো) তথ্য আপডেট'; }
            set_flash($bits ? 'success' : 'error', $bits ? (cp_month_display($month) . ' — ' . implode(', ', $bits) . '। (এখনো পাঠানো হয়নি)') : 'কিছু করা হয়নি — অন্তত একজন সক্রিয় শিক্ষার্থী থাকতে হবে।');
        }
        redirect(cp_url($itemId, $month));
    }

    redirect('course-parcel.php');
}

$itemId = (int) ($_GET['item_id'] ?? 0);
require __DIR__ . '/includes/layout-top.php';

// ---------------- কোর্স-ব্যাচ পিকার (hide_parcel বাদ) ----------------
if (!$itemId) {
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c, cb.total_parcels,
                SUM(CASE WHEN cbz.send_status = 'sent' THEN 1 ELSE 0 END) sent_cnt
         FROM registrations r
         JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0
         LEFT JOIN courier_batches cbz ON cbz.registration_id = r.id
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch, cb.total_parcels
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="max-w-3xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচের পার্সেল ম্যানেজ করবেন বেছে নিন — গ্রুপে যোগ, মাস অনুযায়ী পার্সেল প্রস্তুত+পাঠানো, প্রগ্রেস সব এক জায়গায়। <span class="text-gray-400">("পার্সেল হাইড" করা কোর্স এখানে আসে না।)</span></p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="package" class="w-8 h-8"></i></div>পার্সেল যায় এমন কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">শিক্ষার্থী</th>
                    <th class="py-3 px-4">মোট মাস</th><th class="py-3 px-4">পাঠানো</th><th class="py-3 px-4">অ্যাকশন</th>
                </tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><?= (int) $b['total_parcels'] ? (int) $b['total_parcels'] : '<span class="text-gray-300">সেট করা হয়নি</span>' ?></td>
                        <td class="py-2.5 px-4"><?= (int) $b['sent_cnt'] ? '<span class="text-green-700 font-semibold">' . (int) $b['sent_cnt'] . ' টি</span>' : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="py-2.5 px-4"><a href="course-parcel.php?item_id=<?= (int) $b['item_id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">ম্যানেজ করুন →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ---------------- নির্দিষ্ট কোর্স-ব্যাচ ----------------
$cStmt = $db->prepare('SELECT cb.total_parcels, cb.batch_name, cb.price, c.title
                       FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
$totalParcels = (int) ($course['total_parcels'] ?? 0);
$courseFee = parse_price_to_number($course['price'] ?? '0');

$months = max($totalParcels, 0);
$selMonth = (int) ($_GET['month'] ?? 1);
if ($months > 0) { $selMonth = max(1, min($selMonth, $months)); } else { $selMonth = 0; }
$selLabel = $selMonth ? cp_month_label($selMonth) : '';

$regs = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

$byRegPeriod = [];
if ($regs) {
    $ids = array_map(fn($r) => (int) $r['id'], $regs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, registration_id, period_label, send_status, amount_to_collect,
                               delivery_zone, weight_extra, adjustment, adjustment_reason
                        FROM courier_batches WHERE registration_id IN ($in) ORDER BY id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) { $byRegPeriod[(int) $row['registration_id']][$row['period_label']] = $row; }
}

$notesByReg = fetch_registration_notes($db, array_map(fn($r) => (int) $r['id'], $regs));
$activeRegs   = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) === 1));
$inactiveRegs = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) !== 1));
$pendingGroupRemoval = array_values(array_filter($inactiveRegs, fn($r) => !empty($r['fb_group_added']) || !empty($r['messenger_group_added'])));

$periodHasExisting = false;
if ($selLabel) { foreach ($activeRegs as $r) { if (isset($byRegPeriod[(int) $r['id']][$selLabel])) { $periodHasExisting = true; break; } } }

$sendResult = null;
if (!empty($_SESSION['cp_result']) && (int) $_SESSION['cp_result']['item'] === $itemId) {
    $sendResult = $_SESSION['cp_result']; unset($_SESSION['cp_result']);
}

// মাস-সেল আইকন (ম্যাট্রিক্স)
function cp_cell(?array $b): array
{
    $s = $b['send_status'] ?? 'empty';
    $map = [
        'sent'     => ['✓', 'bg-green-500 text-white', 'পাঠানো'],
        'draft'    => ['●', 'bg-amber-400 text-white', 'প্রস্তুত'],
        'declined' => ['✕', 'bg-red-500 text-white', 'না'],
        'failed'   => ['!', 'bg-orange-500 text-white', 'ব্যর্থ'],
        'empty'    => ['', 'border-2 border-gray-200 bg-white', 'বাকি'],
    ];
    return $map[$s] ?? $map['empty'];
}
?>
<style>
.cp-hd{display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem}
.cp-hd .bar{width:4px;height:18px;border-radius:2px;background:rgb(var(--c-primary))}
.cp-tab{flex-shrink:0;padding:.5rem .9rem;border-radius:.6rem .6rem 0 0;font-weight:600;font-size:.875rem;border-bottom:3px solid transparent;white-space:nowrap}
.cp-tab.on{background:#fff;border-color:rgb(var(--c-primary));color:rgb(var(--c-primary));box-shadow:0 -1px 3px rgba(0,0,0,.05)}
.cp-tab.off{background:#f3f4f6;color:#6b7280}
.cp-tab.off:hover{background:#e5e7eb}
.cp-toggle{display:inline-flex;width:26px;height:26px;border-radius:9999px;align-items:center;justify-content:center;transition:.15s}
</style>

<div class="mb-3"><a href="course-parcel.php" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> সব কোর্স-ব্যাচ</a></div>

<?php // ── হেডার + মোট পার্সেল ── ?>
<div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
            <div class="text-xs text-gray-400">কোর্স · ব্যাচ</div>
            <h1 class="text-lg font-black text-gray-800 break-words"><?= e($course['title'] ?? '') ?> <span class="text-indigo-600 font-semibold text-sm">— <?= e($course['batch_name'] ?? '') ?></span></h1>
            <div class="text-xs text-gray-400 mt-0.5">মান্থলি ফি ৳<?= e(number_format($courseFee)) ?> · প্রতি মাসে এক পার্সেল</div>
        </div>
        <form method="post" action="course-parcel.php" class="flex items-end gap-2 flex-shrink-0"
              onsubmit="return <?= $totalParcels ? "confirmSubmit(this, 'মোট পার্সেল সংখ্যা বদলাচ্ছেন — এটা প্রতি শিক্ষার্থীর মাসের সংখ্যা বদলে দেবে। নিশ্চিত?', 'সংখ্যা বদলাবেন?')" : 'true' ?>;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="parcels"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="month" value="<?= $selMonth ?>">
            <label class="text-xs text-gray-600 font-semibold">মোট কয়বার<br>পার্সেল?<input type="number" name="total_parcels" min="0" max="60" value="<?= $totalParcels ?>" class="block w-20 border rounded-lg px-3 py-1.5 text-sm mt-1 font-bold"></label>
            <button type="submit" class="bg-indigo-600 text-white text-sm font-semibold px-3 py-2 rounded-lg">সেভ</button>
        </form>
    </div>
</div>

<?php // ── পাঠানোর ফলাফল প্যানেল ── ?>
<?php if ($sendResult): $sr = $sendResult; $hasFail = !empty($sr['failed']); ?>
    <div class="rounded-2xl shadow p-4 mb-4 border <?= $hasFail ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200' ?>">
        <div class="flex items-start justify-between gap-2">
            <div class="font-bold <?= $hasFail ? 'text-red-800' : 'text-green-800' ?>"><?= $hasFail ? '⚠️' : '✓' ?> <?= e($sr['period']) ?> — পাঠানোর ফলাফল</div>
            <a href="<?= e(cp_url($itemId, $selMonth)) ?>" class="text-xs text-gray-500 hover:text-gray-800">✕ বন্ধ</a>
        </div>
        <div class="flex flex-wrap gap-2 mt-2 text-sm">
            <span class="px-2 py-1 rounded-lg bg-green-100 text-green-800 font-semibold">✓ সফল: <?= (int) $sr['sent'] ?><?= !empty($sr['resent']) ? ' (আবার-পাঠানো ' . (int) $sr['resent'] . ')' : '' ?></span>
            <?php if ($hasFail): ?><span class="px-2 py-1 rounded-lg bg-red-100 text-red-800 font-semibold">✕ ব্যর্থ: <?= count($sr['failed']) ?></span><?php endif; ?>
            <?php if (!empty($sr['edited'])): ?><span class="px-2 py-1 rounded-lg bg-gray-100 text-gray-700 font-semibold">তথ্য আপডেট: <?= (int) $sr['edited'] ?></span><?php endif; ?>
        </div>
        <?php if ($hasFail): ?>
            <div class="mt-3 text-sm text-red-900"><div class="font-semibold mb-1">যাদের পাঠানো যায়নি (কারণ সহ):</div>
                <ul class="list-disc pl-5 space-y-1"><?php foreach ($sr['failed'] as $f): ?><li><span class="font-semibold"><?= e($f['name']) ?></span> — <?= e($f['reason']) ?></li><?php endforeach; ?></ul>
                <p class="text-xs text-red-700 mt-2">এদের ব্যাচ "প্রস্তুত" অবস্থায় আছে — কারণ ঠিক করে আবার পাঠাতে পারবেন।</p>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$regs): ?>
    <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>এই ব্যাচে কোনো confirmed রেজিস্ট্রেশন নেই।</div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit; ?>
<?php endif; ?>

<?php // ── নিষ্ক্রিয় কিন্তু এখনো গ্রুপে — সতর্কতা ── ?>
<?php if ($pendingGroupRemoval): ?>
    <div class="rounded-2xl shadow p-4 mb-4 bg-red-50 border border-red-200">
        <div class="font-bold text-red-800">⚠ <?= count($pendingGroupRemoval) ?> জন নিষ্ক্রিয় শিক্ষার্থী এখনো গ্রুপে আছে</div>
        <p class="text-sm text-red-900 mt-1">এদের FB/Messenger গ্রুপ থেকে বাদ দিন, তারপর নিচের "নিষ্ক্রিয় শিক্ষার্থী" অংশে গিয়ে সেই টিক তুলে দিন — তাহলে বোঝা যাবে কাজটা হয়ে গেছে।</p>
        <div class="flex flex-wrap gap-1.5 mt-2">
            <?php foreach ($pendingGroupRemoval as $pr): ?>
                <span class="text-xs px-2 py-1 rounded-lg bg-white border border-red-200 text-red-800 font-semibold"><?= e($pr['customer_name']) ?><?= !empty($pr['fb_group_added']) ? ' · FB' : '' ?><?= !empty($pr['messenger_group_added']) ? ' · Msngr' : '' ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php // ══════════ Section 1 — শিক্ষার্থী × মাস ম্যাট্রিক্স ══════════ ?>
<div class="cp-hd"><span class="bar"></span><span class="text-sm font-bold text-gray-700">অবস্থা</span><span class="text-xs text-gray-400">গ্রুপে যোগ · সক্রিয়/নিষ্ক্রিয় · কোন মাসে কী (মাসে ক্লিক করে নিচে কাজ)</span></div>

<?php
$grp_toggle = function (array $r, string $field, bool $on, int $itemId, int $selMonth) {
    $lbl = $field === 'messenger' ? 'Messenger' : 'FB';
    ?>
    <form method="post" action="course-parcel.php" class="inline" onsubmit="return <?= $on ? "confirmSubmit(this, '$lbl গ্রুপ থেকে টিক তুলে ফেলবেন? (এই শিক্ষার্থী গ্রুপে নেই বলে চিহ্নিত হবে)', 'টিক তুলবেন?')" : 'true' ?>;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="<?= $field ?>"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="month" value="<?= $selMonth ?>"><input type="hidden" name="value" value="<?= $on ? 0 : 1 ?>">
        <button type="submit" title="<?= $lbl ?> গ্রুপে যোগ হয়েছে?" class="cp-toggle <?= $on ? 'bg-green-500' : 'border-2 border-gray-300 hover:border-green-400' ?>"><?php if ($on): ?><i data-lucide="check" class="w-4 h-4 text-white"></i><?php endif; ?></button>
    </form>
    <?php
};

$render_row = function (array $r) use ($byRegPeriod, $months, $selMonth, $itemId, $grp_toggle) {
    $rid = (int) $r['id'];
    $active = (int) ($r['courier_active'] ?? 1);
    $fbOn = !empty($r['fb_group_added']); $msgOn = !empty($r['messenger_group_added']);
    $needsRemoval = !$active && ($fbOn || $msgOn);
    $sentCount = 0;
    for ($i = 1; $i <= $months; $i++) { if (($byRegPeriod[$rid][cp_month_label($i)]['send_status'] ?? '') === 'sent') { $sentCount++; } }
    ?>
    <tr class="border-b last:border-0 hover:bg-gray-50 <?= $active ? '' : 'opacity-70' ?>">
        <td class="py-2.5 px-3 font-semibold text-gray-900 whitespace-nowrap"><?= e($r['customer_name']) ?><div class="text-[11px] text-gray-400 font-normal font-mono"><?= e($r['phone']) ?></div><?php if ($needsRemoval): ?><div class="text-[11px] text-red-600 font-semibold mt-0.5">⚠ গ্রুপ থেকে বাদ দিন</div><?php endif; ?></td>
        <td class="py-2.5 px-2 text-center"><?php $grp_toggle($r, 'fb', $fbOn, $itemId, $selMonth); ?></td>
        <td class="py-2.5 px-2 text-center"><?php $grp_toggle($r, 'messenger', $msgOn, $itemId, $selMonth); ?></td>
        <td class="py-2.5 px-2 text-center">
            <form method="post" action="course-parcel.php" class="inline" onsubmit="return confirmSubmit(this, <?= $active ? "'নিষ্ক্রিয় করলে এই শিক্ষার্থী আর মাসের তালিকায় আসবে না। নিশ্চিত?', 'নিষ্ক্রিয় করবেন?'" : "'আবার সক্রিয় করবেন?', 'সক্রিয় করবেন?'" ?>);"><?= csrf_field() ?>
                <input type="hidden" name="action" value="active"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="month" value="<?= $selMonth ?>"><input type="hidden" name="value" value="<?= $active ? 0 : 1 ?>">
                <button type="submit" class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></button>
            </form>
        </td>
        <?php for ($i = 1; $i <= $months; $i++): [$ch, $cls] = cp_cell($byRegPeriod[$rid][cp_month_label($i)] ?? null); ?>
            <td class="py-2.5 px-1.5 text-center <?= $i === $selMonth ? 'bg-indigo-50' : '' ?>">
                <span class="inline-flex w-6 h-6 items-center justify-center rounded-full text-[11px] font-bold <?= $cls ?>" title="মাস <?= $i ?>"><?= $ch ?></span>
            </td>
        <?php endfor; ?>
        <?php if ($months): ?><td class="py-2.5 px-2 text-center text-xs font-semibold text-gray-500 whitespace-nowrap"><?= $sentCount ?>/<?= $months ?></td><?php endif; ?>
    </tr>
    <?php
};
?>

<?php if (!$months): ?>
    <div class="p-4 mb-6 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl">
        <i data-lucide="alert-triangle" class="w-4 h-4 inline"></i> উপরে <b>"মোট কয়বার পার্সেল"</b> সংখ্যাটা দিন — তারপর মাস অনুযায়ী পার্সেল প্রস্তুত করা যাবে।
    </div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow overflow-x-auto mb-3">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
            <th class="py-3 px-3">শিশুর নাম</th><th class="py-3 px-2 text-center">FB</th><th class="py-3 px-2 text-center">Msngr</th><th class="py-3 px-2 text-center">সক্রিয়</th>
            <?php for ($i = 1; $i <= $months; $i++): ?>
                <th class="py-3 px-1.5 text-center whitespace-nowrap <?= $i === $selMonth ? 'bg-indigo-50' : '' ?>"><a href="<?= e(cp_url($itemId, $i)) ?>" class="<?= $i === $selMonth ? 'text-indigo-700 font-bold' : 'text-gray-500 hover:text-indigo-600' ?>" title="এই মাসে কাজ করুন">মাস <?= $i ?></a></th>
            <?php endfor; ?>
            <?php if ($months): ?><th class="py-3 px-2 text-center whitespace-nowrap">পাঠানো</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$activeRegs && $inactiveRegs): ?><tr><td colspan="<?= 5 + $months ?>" class="py-6 px-4 text-center text-gray-400">সব শিক্ষার্থী নিষ্ক্রিয় — নিচে থেকে সক্রিয় করুন।</td></tr><?php endif; ?>
        <?php foreach ($activeRegs as $r) { $render_row($r); } ?>
        </tbody>
    </table>
</div>
<?php if ($months): ?>
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mb-6 px-1">
        <span><span class="inline-block w-3 h-3 rounded-full bg-green-500 align-middle"></span> পাঠানো</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400 align-middle"></span> প্রস্তুত</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-red-500 align-middle"></span> না</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-orange-500 align-middle"></span> ব্যর্থ</span>
        <span><span class="inline-block w-3 h-3 rounded-full border-2 border-gray-200 align-middle"></span> বাকি</span>
    </div>
<?php else: ?><div class="mb-6"></div><?php endif; ?>

<?php if ($inactiveRegs): ?>
    <details class="mb-6" <?= $pendingGroupRemoval ? 'open' : '' ?>>
        <summary class="cursor-pointer select-none text-sm font-semibold <?= $pendingGroupRemoval ? 'text-red-600' : 'text-gray-500' ?> py-2 px-1 flex items-center gap-1.5">
            <i data-lucide="chevron-right" class="w-4 h-4"></i> নিষ্ক্রিয় শিক্ষার্থী (<?= count($inactiveRegs) ?> জন)<?= $pendingGroupRemoval ? ' — ' . count($pendingGroupRemoval) . ' জন এখনো গ্রুপে (বাদ দিন)' : ' — দেখতে/আবার সক্রিয় করতে ক্লিক করুন' ?>
        </summary>
        <div class="bg-white rounded-2xl shadow overflow-x-auto mt-2"><table class="w-full text-sm"><tbody>
        <?php foreach ($inactiveRegs as $r) { $render_row($r); } ?>
        </tbody></table></div>
    </details>
<?php endif; ?>

<?php // ══════════ Section 2 — মাস ট্যাব + এই মাসের পার্সেল ══════════ ?>
<?php if ($months): ?>
    <div class="cp-hd"><span class="bar"></span><span class="text-sm font-bold text-gray-700">এই মাসের পার্সেল</span><span class="text-xs text-gray-400">মাস বেছে প্রস্তুত করুন / পাঠান</span></div>

    <div class="flex gap-1 overflow-x-auto pb-0 mb-0 border-b border-gray-200">
        <?php for ($i = 1; $i <= $months; $i++): ?>
            <a href="<?= e(cp_url($itemId, $i)) ?>" class="cp-tab <?= $i === $selMonth ? 'on' : 'off' ?>">মাস <?= $i ?></a>
        <?php endfor; ?>
    </div>

    <?php if (!$activeRegs): ?>
        <div class="bg-white rounded-2xl shadow empty-state mt-3"><div class="empty-ic"><i data-lucide="user-x" class="w-8 h-8"></i></div>কোনো সক্রিয় শিক্ষার্থী নেই — উপরে থেকে সক্রিয় করলে এখানে আসবে।</div>
    <?php else: ?>
        <form method="post" id="roundForm" action="course-parcel.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="round-save"><input type="hidden" name="mode" id="roundMode" value="draft">
            <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="month" value="<?= $selMonth ?>">

            <div class="bg-indigo-50 border border-indigo-100 rounded-b-xl rounded-tr-xl px-3 py-2 mb-3 mt-0 text-sm text-indigo-800">
                <b>মাস <?= $selMonth ?></b>-এর পার্সেল। সবাই ডিফল্টে <b>"✓ যাবে"</b> — কেউ এই মাসে না নিলে তার <b>"না এই মাসে"</b> চাপুন।
                <?php if ($periodHasExisting): ?><span class="block text-xs text-indigo-600 mt-0.5">এই মাসের কিছু আগে থেকেই আছে — বদলালে নিশ্চিতকরণ চাইবে।</span><?php endif; ?>
            </div>

            <div class="space-y-3 pb-32">
            <?php foreach ($activeRegs as $r):
                $rid = (int) $r['id'];
                $ex = $byRegPeriod[$rid][$selLabel] ?? null;
                $isNo = $ex && $ex['send_status'] === 'declined';
                $isSent = $ex && $ex['send_status'] === 'sent';
                $isFailed = $ex && $ex['send_status'] === 'failed';
                $exZone = $ex['delivery_zone'] ?? 'dhaka'; $exWx = !empty($ex['weight_extra']);
                $exAdj = $ex['adjustment'] ?? 0; $exReason = $ex['adjustment_reason'] ?? '';
                $exAmt = ($ex && $ex['amount_to_collect'] !== null && $ex['send_status'] !== 'declined') ? (string) (int) round((float) $ex['amount_to_collect']) : '';
                $notes = $notesByReg[$rid] ?? [];
            ?>
                <div class="stu bg-white rounded-2xl shadow p-4 <?= $isNo ? 'opacity-60' : '' ?>" data-fee="<?= (int) $courseFee ?>" data-sent="<?= $isSent ? 1 : 0 ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][present]" value="1">
                    <input type="hidden" name="bd[<?= $rid ?>][decision]" class="decision" value="<?= $isNo ? 'no' : 'go' ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][unlocked]" class="unlocked" value="">
                    <input type="hidden" name="bd[<?= $rid ?>][resend]" class="resend" value="">
                    <div class="flex items-start gap-2.5 mb-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-gray-900 text-sm break-words"><?= e($r['customer_name']) ?></div>
                            <div class="text-[11px] text-gray-500">রিসিভার: <?= e($r['receiver_name'] ?: $r['customer_name']) ?> · <?= e($r['receiver_phone'] ?: $r['phone']) ?></div>
                            <div class="text-[11px] text-gray-400 break-words">ঠিকানা: <?= e($r['address'] ?: '—') ?></div>
                        </div>
                        <?php if ($isSent): ?>
                            <div class="flex-shrink-0 flex flex-col items-end gap-1">
                                <span class="text-xs px-2.5 py-1.5 rounded-lg bg-green-100 text-green-700 font-semibold">✓ পাঠানো</span>
                                <button type="button" class="unlockBtn text-xs font-semibold px-2.5 py-1 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">🔓 আনলক</button>
                                <button type="button" class="resendBtn text-xs font-semibold px-2.5 py-1 rounded-lg border border-indigo-300 text-indigo-700 bg-indigo-50 hidden">↻ আবার পাঠান</button>
                            </div>
                        <?php else: ?>
                            <button type="button" class="goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border"></button>
                        <?php endif; ?>
                    </div>
                    <?php if ($isFailed): ?><div class="text-[11px] text-orange-700 bg-orange-50 rounded px-2 py-1 mb-2">গতবার পাঠানো ব্যর্থ হয়েছিল — আবার চেষ্টা করতে পারেন।</div><?php endif; ?>
                    <?php if ($notes): ?><div class="flex flex-wrap items-center gap-1.5 mb-3"><?php render_note_chips($notes); ?></div><?php endif; ?>
                    <div class="builder <?= $isSent ? 'opacity-50 pointer-events-none' : '' ?>">
                        <div class="grid gap-2" style="grid-template-columns:1.3fr auto 1fr; align-items:end;">
                            <label class="text-xs text-gray-600">ডেলিভারি<select name="bd[<?= $rid ?>][zone]" class="zone block w-full border rounded-lg px-2 py-2 text-sm mt-1">
                                <option value="dhaka" <?= $exZone==='dhaka'?'selected':'' ?>>ঢাকা</option><option value="near" <?= $exZone==='near'?'selected':'' ?>>নিকটবর্তী</option><option value="outside" <?= $exZone==='outside'?'selected':'' ?>>বাইরে</option>
                            </select></label>
                            <label class="flex items-center gap-1 text-xs text-gray-600 pb-2"><input type="checkbox" class="wx w-4 h-4 accent-indigo-600" name="bd[<?= $rid ?>][wx]" value="1" <?= $exWx?'checked':'' ?>> ওজন+</label>
                            <label class="text-xs text-gray-600">সমন্বয়±<input type="number" step="1" value="<?= e((string)(int)$exAdj) ?>" name="bd[<?= $rid ?>][adj]" class="adj block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t">
                            <div class="text-xs text-gray-500">কালেকশন (টাকা)<div class="autohint text-[11px] text-gray-400">অটো হিসাব: ৳<span class="autoval">0</span> · <button type="button" class="text-indigo-600 font-semibold underline autobtn">অটো বসান</button></div></div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <span class="text-lg font-black text-gray-900">৳</span>
                                <input type="number" step="1" min="0" name="bd[<?= $rid ?>][amt]" value="<?= e($exAmt) ?>" class="amt border-2 border-indigo-200 focus:border-indigo-500 rounded-lg px-2 py-1.5 text-lg font-black text-gray-900 text-right" style="width:120px;">
                                <input type="hidden" name="bd[<?= $rid ?>][manual]" class="manual" value="<?= $exAmt !== '' ? '1' : '' ?>">
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="fixed inset-x-0 bottom-0 bg-white border-t border-gray-200 px-4 py-3 z-30" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
                <div class="max-w-2xl mx-auto flex items-center gap-3 flex-wrap">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500"><b>মাস <?= $selMonth ?></b> · <span id="goCount">0</span> যাবে · <span id="noCount">0</span> না · মোট</div>
                        <div id="sumAmt" class="text-lg font-black text-gray-900">৳0</div>
                    </div>
                    <button type="button" id="draftBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-4 py-3 rounded-xl text-sm" title="এখনো পাঠাবে না, শুধু ঠিক করে রাখবে"><i data-lucide="save" class="w-4 h-4 inline"></i> সেভ</button>
                    <button type="button" id="sendBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4 inline"></i> কুরিয়ারে পাঠান</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
(function(){
  var form=document.getElementById('roundForm');
  if(!form){ return; }
  var DC={dhaka:<?= (float) $dc['dhaka'] ?>,near:<?= (float) $dc['near'] ?>,outside:<?= (float) $dc['outside'] ?>};
  var WX=<?= (float) $wxExtra ?>;
  var periodHasExisting=<?= $periodHasExisting ? 'true' : 'false' ?>;
  var selMonth=<?= (int) $selMonth ?>;

  function paintGoNo(el){
    var dec=el.querySelector('.decision').value, btn=el.querySelector('.goNoBtn');
    if(!btn){ return; }
    var bld=el.querySelector('.builder');
    if(dec==='no'){ btn.textContent='না এই মাসে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-red-300 bg-red-50 text-red-700'; el.classList.add('opacity-60'); if(bld)bld.style.display='none'; }
    else { btn.textContent='✓ যাবে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-green-300 bg-green-50 text-green-700'; el.classList.remove('opacity-60'); if(bld)bld.style.display=''; }
  }
  function calc(el){
    var fee=+el.getAttribute('data-fee'), zone=el.querySelector('.zone').value;
    var wx=el.querySelector('.wx').checked?WX:0, adj=+el.querySelector('.adj').value||0;
    var auto=Math.max(0,Math.round(fee+(DC[zone]||0)+wx+adj));
    var amtEl=el.querySelector('.amt'), manual=el.querySelector('.manual').value==='1';
    el.querySelector('.autoval').textContent=auto;
    if(!manual){ amtEl.value=auto; }
    var dec=el.querySelector('.decision').value, sent=el.getAttribute('data-sent')==='1';
    var resendEl=el.querySelector('.resend'), resend=resendEl && resendEl.value==='1';
    // পাঠাবে = (নতুন যাবে) অথবা (পাঠানো + আবার-পাঠান armed)
    var willSend=(dec==='go' && !sent) || (sent && resend);
    return {send: willSend, resend: sent && resend, no: dec==='no' && !sent, t:+amtEl.value||0};
  }
  function refresh(){
    var s=0,g=0,no=0;
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el); if(r.send){s+=r.t;g++;} if(r.no){no++;} paintGoNo(el);});
    var gc=document.getElementById('goCount'),nc=document.getElementById('noCount'),sa=document.getElementById('sumAmt');
    if(gc)gc.textContent=g; if(nc)nc.textContent=no; if(sa)sa.textContent='৳'+s;
  }
  document.querySelectorAll('.stu').forEach(function(el){
    var btn=el.querySelector('.goNoBtn');
    if(btn){ btn.addEventListener('click',function(){ var d=el.querySelector('.decision'); d.value=(d.value==='no')?'go':'no'; refresh(); }); }
    var amtEl=el.querySelector('.amt'), manualEl=el.querySelector('.manual');
    if(amtEl){ amtEl.addEventListener('input',function(){ manualEl.value='1'; refresh(); }); }
    var ab=el.querySelector('.autobtn');
    if(ab){ ab.addEventListener('click',function(){ manualEl.value=''; refresh(); }); }
    // পাঠানো কার্ড আনলক (ওয়ার্নিং সহ) → এডিট + "আবার পাঠান" চালু
    var ub=el.querySelector('.unlockBtn'), rb=el.querySelector('.resendBtn'),
        unl=el.querySelector('.unlocked'), bld=el.querySelector('.builder'), re=el.querySelector('.resend');
    if(ub){ ub.addEventListener('click',function(){
      showConfirmModal('এই পার্সেল ইতিমধ্যে কুরিয়ারে পাঠানো হয়ে গেছে। আনলক করে টাকা/ডেলিভারি ঠিক করা যাবে বা "আবার পাঠান" করা যাবে। খেয়াল রাখুন — এটা আগের পাঠানো চালান বাতিল করে না। আনলক করবেন?',function(){
        unl.value='1'; if(bld){ bld.classList.remove('opacity-50','pointer-events-none'); }
        ub.classList.add('hidden'); if(rb){ rb.classList.remove('hidden'); }
        refresh();
      },'আনলক করবেন?');
    }); }
    if(rb){ rb.addEventListener('click',function(){
      if(re.value==='1'){ re.value=''; rb.textContent='↻ আবার পাঠান'; rb.classList.remove('bg-indigo-600','text-white'); rb.classList.add('bg-indigo-50','text-indigo-700'); el.classList.remove('ring-2','ring-indigo-400'); refresh(); return; }
      showConfirmModal('নতুন করে কুরিয়ারে পাঠাবেন? একটা নতুন চালান তৈরি হবে (আগেরটা বাতিল হবে না)। "কুরিয়ারে পাঠান" চাপলে এটা যাবে।',function(){
        re.value='1'; rb.textContent='✓ আবার পাঠানো হবে (বাতিল করতে ক্লিক)'; rb.classList.remove('bg-indigo-50','text-indigo-700'); rb.classList.add('bg-indigo-600','text-white'); el.classList.add('ring-2','ring-indigo-400'); refresh();
      },'আবার পাঠাবেন?');
    }); }
  });
  function doSubmit(mode){
    document.getElementById('roundMode').value=mode;
    var g=0,no=0,s=0,re=0;
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el); if(r.send){g++;s+=r.t; if(r.resend)re++;} if(r.no)no++;});
    if(mode==='send' && g===0){ showConfirmModal('কাউকে পাঠানোর জন্য বাছাই করা হয়নি (নতুন "যাবে" বা পাঠানো কার্ডে "আবার পাঠান")।',function(){},'পাঠানো খালি'); return; }
    var msg = (mode==='send')
      ? ('মাস '+selMonth+' — '+g+' জনের পার্সেল এখনই আসল কুরিয়ারে পাঠাবেন?'+(re?(' (তার মধ্যে '+re+' জন আবার-পাঠানো)'):'')+' মোট কালেকশন ৳'+s+'। পাঠানোর পর আর ফেরানো যাবে না।')
      : ('মাস '+selMonth+' — সেভ করবেন? এটা সংবেদনশীল পেজ — শুধু ঠিক করে রাখা হবে (এখনো কুরিয়ারে পাঠাবে না)।');
    if(periodHasExisting){ msg='⚠️ এই মাসের কিছু আগে থেকেই আছে — পরিবর্তন হবে।\n\n'+msg; }
    showConfirmModal(msg,function(){ form.submit(); }, mode==='send'?'কুরিয়ারে পাঠাবেন?':'সেভ করবেন?');
  }
  var d1=document.getElementById('draftBtn'); if(d1){ d1.addEventListener('click',function(){ doSubmit('draft'); }); }
  var s1=document.getElementById('sendBtn'); if(s1){ s1.addEventListener('click',function(){ doSubmit('send'); }); }
  form.addEventListener('input',refresh); form.addEventListener('change',refresh); refresh();
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
