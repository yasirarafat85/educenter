<?php
// কোর্স পার্সেল — "পার্সেল প্রস্তুত" + "কোর্স ট্র্যাকিং" এক পেজে (২০২৬-০৮-১৬, ইউজারের ডিজাইন-রিভিউ অনুযায়ী)।
//   • উপরে: অবস্থা (রোস্টার) — গ্রুপে-যোগ টগল, সক্রিয়/নিষ্ক্রিয়, প্রতি শিক্ষার্থীর N পার্সেল-স্লট + X/N প্রগ্রেস
//   • নিচে: এই দফার পার্সেল — মাস বেছে প্রতি সক্রিয় শিক্ষার্থী "যাবে/না এই মাসে", অটো কালেকশন, খসড়া/পাঠান
// শেয়ার্ড ডেটা: registrations.courier_active/fb_group_added/messenger_group_added, course_batches.total_parcels,
//   courier_batches (প্রতি শিক্ষার্থী প্রতি মাসে একটা — upsert, ডুপ্লিকেট হয় না; send_status draft/sent/failed/declined)।
// ⚠️ সংবেদনশীল কাজে ওয়ার্নিং: প্রথমবার সেট করলে না, পরে বদলালে (গ্রুপ টিক তোলা / পার্সেল সংখ্যা / সক্রিয়↔নিষ্ক্রিয় /
//   মাসের "না") confirmSubmit মডাল। hide_parcel=Yes কোর্স-ব্যাচ এই পেজে আসে না (পার্সেলই যায় না)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
require_once __DIR__ . '/includes/courier-notes.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কোর্স পার্সেল';

function cp_url(int $itemId = 0, string $period = ''): string
{
    $u = 'course-parcel.php';
    if ($itemId) {
        $u .= '?item_id=' . $itemId;
        if ($period !== '') { $u .= '&period=' . urlencode($period); }
    }
    return $u;
}

// প্রিসেট (settings; ডিফল্ট fallback) — কালেকশন অটো হিসাবের জন্য
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

    if ($action === 'group') {
        // গ্রুপে যোগ টগল (FB/Messenger) — ওয়ার্নিং শুধু টিক তোলার সময় (ক্লায়েন্ট-সাইড)
        $field = ($_POST['field'] ?? '') === 'messenger' ? 'messenger_group_added' : 'fb_group_added';
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare("UPDATE registrations SET $field = :v WHERE id = :id")->execute(['v' => $val, 'id' => $regId]);
        redirect(cp_url($itemId, trim($_POST['period'] ?? '')));

    } elseif ($action === 'active') {
        // সক্রিয়/নিষ্ক্রিয় — নিষ্ক্রিয় করলে সেই শিক্ষার্থী নিচের "এই দফা" তালিকায় আর আসবে না
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare('UPDATE registrations SET courier_active = :v WHERE id = :id')->execute(['v' => $val, 'id' => $regId]);
        set_flash('success', $val ? 'সক্রিয় করা হলো।' : 'নিষ্ক্রিয় করা হলো — এই শিক্ষার্থী আর পার্সেল তালিকায় আসবে না।');
        redirect(cp_url($itemId, trim($_POST['period'] ?? '')));

    } elseif ($action === 'parcels') {
        // মোট কয়বার পার্সেল যাবে (স্লট-সংখ্যা)
        $n = max(0, min(60, (int) ($_POST['total_parcels'] ?? 0)));
        $db->prepare('UPDATE course_batches SET total_parcels = :n WHERE id = :id')->execute(['n' => $n, 'id' => $itemId]);
        set_flash('success', 'মোট পার্সেল সংখ্যা সেভ হয়েছে।');
        redirect(cp_url($itemId, trim($_POST['period'] ?? '')));

    } elseif ($action === 'round-save') {
        // এই দফার (period) পার্সেল প্রস্তুত/পাঠানো — প্রতি সক্রিয় শিক্ষার্থীর সিদ্ধান্ত (go=যাবে / no=না)।
        // প্রতি (registration, period)-এ একটা মাত্র ব্যাচ — upsert (ডুপ্লিকেট হয় না); সফল-পাঠানো ব্যাচ ছোঁয়া হয় না।
        $mode   = ($_POST['mode'] ?? 'draft') === 'send' ? 'send' : 'draft';
        $period = trim($_POST['period'] ?? '');
        if ($period === '') {
            set_flash('error', 'মাস/পার্সেল লেবেল দিতে হবে (যেমন "১ম মাস")।');
            redirect(cp_url($itemId));
        }
        $provider = ($mode === 'send') ? get_active_courier_provider() : null;
        if ($mode === 'send' && !$provider) {
            set_flash('error', 'কোনো সক্রিয় কুরিয়ার প্রোভাইডার সেট করা নেই (সেটিংস দেখুন)।');
            redirect(cp_url($itemId, $period));
        }

        $feeStmt = $db->prepare('SELECT price FROM course_batches WHERE id = :id');
        $feeStmt->execute(['id' => $itemId]);
        $courseFee = parse_price_to_number($feeStmt->fetchColumn() ?: '0');

        // সব সক্রিয় confirmed রেজিস্ট্রেশন (send-এর জন্য পুরো রো লাগে)
        $rm = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' AND courier_active = 1");
        $rm->execute(['id' => $itemId]);
        $regMap = [];
        foreach ($rm->fetchAll() as $rr) { $regMap[(int) $rr['id']] = $rr; }

        $findBatch = $db->prepare('SELECT id, send_status FROM courier_batches WHERE registration_id = :r AND period_label = :p ORDER BY id DESC LIMIT 1');
        $insDraft = $db->prepare(
            'INSERT INTO courier_batches
                (registration_id, period_label, item_description, item_quantity, amount_to_collect,
                 monthly_multiplier, delivery_zone, weight_extra, adjustment, adjustment_reason, send_status)
             VALUES (:r, :p, :d, 1, :amt, :m, :z, :wx, :adj, :rs, "draft")'
        );
        $updDraft = $db->prepare(
            'UPDATE courier_batches SET item_description=:d, amount_to_collect=:amt, monthly_multiplier=:m,
                 delivery_zone=:z, weight_extra=:wx, adjustment=:adj, adjustment_reason=:rs, send_status="draft"
             WHERE id = :id'
        );
        $insDeclined = $db->prepare('INSERT INTO courier_batches (registration_id, period_label, send_status) VALUES (:r, :p, "declined")');
        $updDeclined = $db->prepare('UPDATE courier_batches SET send_status="declined", amount_to_collect=NULL WHERE id = :id');

        $prep = 0; $declined = 0; $sent = 0; $failed = 0; $skipped = 0;
        foreach (($_POST['bd'] ?? []) as $rid => $row) {
            $rid = (int) $rid;
            if (empty($row['present']) || !isset($regMap[$rid])) { continue; }
            $decision = ($row['decision'] ?? 'go') === 'no' ? 'no' : 'go';

            $findBatch->execute(['r' => $rid, 'p' => $period]);
            $existing = $findBatch->fetch();
            $existId = $existing ? (int) $existing['id'] : 0;
            $alreadySent = $existing && $existing['send_status'] === 'sent';

            if ($decision === 'no') {
                if ($alreadySent) { $skipped++; continue; } // সফল-পাঠানো মাস "না" করা যায় না
                if ($existId) { $updDeclined->execute(['id' => $existId]); }
                else { $insDeclined->execute(['r' => $rid, 'p' => $period]); }
                $declined++;
                continue;
            }

            // decision = go → কালেকশন হিসাব
            $mult = (float) ($row['mult'] ?? 1);
            $zone = in_array($row['zone'] ?? '', ['dhaka', 'near', 'outside'], true) ? $row['zone'] : 'dhaka';
            $wx   = !empty($row['wx']);
            $adj  = (float) ($row['adj'] ?? 0);
            $amt  = courier_compute_collection($courseFee, $mult, $zone, $wx, $adj);
            if (!empty($row['manual']) && is_numeric($row['amt'] ?? null)) {
                $amt = max(0, round((float) $row['amt']));
            }
            $desc   = trim($row['desc'] ?? '') ?: null;
            $reason = trim($row['reason'] ?? '') ?: null;

            if ($alreadySent) { $skipped++; continue; } // আগেই পাঠানো — পুনরায় পাঠাতে কুরিয়ার পেজ ব্যবহার করুন

            if ($existId) {
                $updDraft->execute(['d' => $desc, 'amt' => $amt, 'm' => $mult, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'id' => $existId]);
                $draftId = $existId;
            } else {
                $insDraft->execute(['r' => $rid, 'p' => $period, 'd' => $desc, 'amt' => $amt, 'm' => $mult, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason]);
                $draftId = (int) $db->lastInsertId();
            }
            $prep++;

            if ($mode === 'send') {
                try {
                    $res = send_courier_batch($db, $provider, $regMap[$rid], [], $draftId);
                    if (!empty($res['success'])) { $sent++; } else { $failed++; }
                } catch (Throwable $e) { $failed++; }
            }
        }

        $bits = [];
        if ($prep)     { $bits[] = "$prep টি প্রস্তুত"; }
        if ($sent)     { $bits[] = "$sent টি কুরিয়ারে পাঠানো"; }
        if ($declined) { $bits[] = "$declined টি \"না\""; }
        if ($failed)   { $bits[] = "$failed টি ব্যর্থ"; }
        if ($skipped)  { $bits[] = "$skipped টি বাদ (আগেই পাঠানো)"; }
        if (!$bits) {
            set_flash('error', 'কিছু করা হয়নি — অন্তত একজন সক্রিয় শিক্ষার্থী থাকতে হবে।');
        } else {
            set_flash($failed ? 'error' : 'success', "\"$period\" — " . implode(', ', $bits) . '।');
        }
        redirect(cp_url($itemId, $period));
    }

    redirect('course-parcel.php');
}

$itemId = (int) ($_GET['item_id'] ?? 0);
$period = trim($_GET['period'] ?? '');
require __DIR__ . '/includes/layout-top.php';

// ---------------- কোর্স-ব্যাচ পিকার (item_id না থাকলে) — hide_parcel বাদ ----------------
if (!$itemId) {
    // শুধু সেই কোর্স-ব্যাচ যার পার্সেল যায় (hide_parcel=0) ও confirmed রেজিস্ট্রেশন আছে
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c,
                COUNT(DISTINCT cbz.period_label) months,
                SUM(CASE WHEN cbz.send_status = 'sent' THEN 1 ELSE 0 END) sent_cnt
         FROM registrations r
         JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0
         LEFT JOIN courier_batches cbz ON cbz.registration_id = r.id
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="max-w-3xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচের পার্সেল ম্যানেজ করবেন বেছে নিন — গ্রুপে যোগ, পার্সেল প্রস্তুত+পাঠানো, প্রগ্রেস সব এক জায়গায়। <span class="text-gray-400">("পার্সেল হাইড" করা কোর্স এখানে আসে না।)</span></p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="package" class="w-8 h-8"></i></div>পার্সেল যায় এমন কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">শিক্ষার্থী</th>
                    <th class="py-3 px-4">মাস</th><th class="py-3 px-4">পাঠানো</th><th class="py-3 px-4">অ্যাকশন</th>
                </tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><?= (int) $b['months'] ?: '—' ?></td>
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
$cStmt = $db->prepare('SELECT cb.total_parcels, cb.batch_name, cb.price, cb.hide_parcel, c.title
                       FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
$totalParcels = (int) ($course['total_parcels'] ?? 0);
$courseFee = parse_price_to_number($course['price'] ?? '0');

$regs = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

// প্রতি রেজিস্ট্রেশনের courier_batches — স্লট (created_at ক্রমে) + নির্বাচিত মাসের বিদ্যমান ব্যাচ (Section 2 initial state)
$slotsByReg = [];   // regId => [status,...]
$periodByReg = [];  // regId => বিদ্যমান ব্যাচ (নির্বাচিত $period)
if ($regs) {
    $ids = array_map(fn($r) => (int) $r['id'], $regs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT id, registration_id, period_label, send_status, amount_to_collect,
                               monthly_multiplier, delivery_zone, weight_extra, adjustment, adjustment_reason, item_description
                        FROM courier_batches WHERE registration_id IN ($in) ORDER BY created_at ASC, id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) {
        $rid = (int) $row['registration_id'];
        $slotsByReg[$rid][] = $row['send_status'];
        if ($period !== '' && $row['period_label'] === $period) { $periodByReg[$rid] = $row; }
    }
}

$notesByReg = fetch_registration_notes($db, array_map(fn($r) => (int) $r['id'], $regs));

$activeRegs   = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) === 1));
$inactiveRegs = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) !== 1));

// এই মাসে আগে থেকে কোনো (সক্রিয়) শিক্ষার্থীর ব্যাচ আছে কিনা — থাকলে সেভে "পরিবর্তন" ওয়ার্নিং
$periodHasExisting = false;
foreach ($activeRegs as $r) { if (isset($periodByReg[(int) $r['id']])) { $periodHasExisting = true; break; } }

// স্লট স্ট্যাটাস → রঙ/আইকন (Section 1 প্রগ্রেস)
function cp_slot(string $status): string
{
    $map = [
        'sent'     => ['✓', 'bg-green-500'],
        'draft'    => ['●', 'bg-amber-400'],
        'declined' => ['✕', 'bg-red-500'],
        'failed'   => ['!', 'bg-orange-500'],
        'empty'    => ['', 'bg-gray-200'],
    ];
    [$ch, $bg] = $map[$status] ?? $map['empty'];
    return '<span class="inline-flex w-5 h-5 items-center justify-center rounded-full text-white text-[10px] font-bold ' . $bg . '" title="' . e($status) . '">' . $ch . '</span>';
}

// রোস্টার সারি (Section 1) — সক্রিয় ও নিষ্ক্রিয় দুই তালিকায় রিইউজ
function cp_roster_row(array $r, array $slotsByReg, int $totalParcels, int $itemId, string $period): void
{
    $rid    = (int) $r['id'];
    $slots  = $slotsByReg[$rid] ?? [];
    $sent   = count(array_filter($slots, fn($s) => $s === 'sent'));
    $n      = max($totalParcels, count($slots));
    $active = (int) ($r['courier_active'] ?? 1);
    $fbOn   = !empty($r['fb_group_added']);
    $msgOn  = !empty($r['messenger_group_added']);
    ?>
    <tr class="border-b last:border-0 hover:bg-gray-50 <?= $active ? '' : 'opacity-60' ?>">
        <td class="py-2.5 px-4 font-semibold text-gray-900"><?= e($r['customer_name']) ?><div class="text-[11px] text-gray-400 font-normal font-mono"><?= e($r['phone']) ?></div></td>
        <?php // FB গ্রুপ টগল — টিক তোলার সময় ওয়ার্নিং ?>
        <td class="py-2.5 px-4 text-center">
            <form method="post" action="course-parcel.php" class="inline" onsubmit="return <?= $fbOn ? "confirmSubmit(this, 'FB গ্রুপ থেকে টিক তুলে ফেলবেন? (এই শিক্ষার্থী গ্রুপে যোগ হয়নি বলে চিহ্নিত হবে)', 'টিক তুলবেন?')" : 'true' ?>;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="fb">
                <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="period" value="<?= e($period) ?>">
                <input type="hidden" name="value" value="<?= $fbOn ? 0 : 1 ?>">
                <button type="submit" class="text-lg leading-none" title="FB গ্রুপে যোগ হয়েছে?"><?= $fbOn ? '✅' : '⬜' ?></button>
            </form>
        </td>
        <?php // Messenger গ্রুপ টগল ?>
        <td class="py-2.5 px-4 text-center">
            <form method="post" action="course-parcel.php" class="inline" onsubmit="return <?= $msgOn ? "confirmSubmit(this, 'Messenger গ্রুপ থেকে টিক তুলে ফেলবেন?', 'টিক তুলবেন?')" : 'true' ?>;"><?= csrf_field() ?>
                <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="messenger">
                <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="period" value="<?= e($period) ?>">
                <input type="hidden" name="value" value="<?= $msgOn ? 0 : 1 ?>">
                <button type="submit" class="text-lg leading-none" title="Messenger গ্রুপে যোগ হয়েছে?"><?= $msgOn ? '✅' : '⬜' ?></button>
            </form>
        </td>
        <?php // সক্রিয় টগল — দুই দিকেই ওয়ার্নিং ?>
        <td class="py-2.5 px-4 text-center">
            <form method="post" action="course-parcel.php" class="inline"
                onsubmit="return confirmSubmit(this, <?= $active ? "'নিষ্ক্রিয় করলে এই শিক্ষার্থী আর পার্সেল তালিকায় আসবে না। নিশ্চিত?', 'নিষ্ক্রিয় করবেন?'" : "'আবার সক্রিয় করবেন? এরপর থেকে এই শিক্ষার্থীর পার্সেল তৈরি করা যাবে।', 'সক্রিয় করবেন?'" ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="active"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="period" value="<?= e($period) ?>">
                <input type="hidden" name="value" value="<?= $active ? 0 : 1 ?>">
                <button type="submit" class="font-semibold text-xs <?= $active ? 'text-green-600' : 'text-gray-400' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></button>
            </form>
        </td>
        <?php // পার্সেল স্লট + প্রগ্রেস ?>
        <td class="py-2.5 px-4">
            <div class="flex items-center gap-1 flex-wrap">
                <?php for ($i = 0; $i < $n; $i++) { echo cp_slot($slots[$i] ?? 'empty'); } ?>
                <?php if ($n === 0): ?><span class="text-xs text-gray-400">উপরে "মোট পার্সেল" দিন</span><?php endif; ?>
            </div>
            <?php if ($totalParcels): ?><span class="text-xs text-gray-500 font-semibold"><?= $sent ?>/<?= $totalParcels ?> পাঠানো</span><?php endif; ?>
        </td>
    </tr>
    <?php
}
?>
<div class="mb-3"><a href="course-parcel.php" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> সব কোর্স-ব্যাচ</a></div>

<?php // ── হেডার + মোট পার্সেল সেটার ── ?>
<div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
    <div class="text-xs text-gray-400">কোর্স · ব্যাচ</div>
    <h1 class="text-lg font-black text-gray-800"><?= e($course['title'] ?? '') ?> <span class="text-indigo-600 font-semibold text-sm">— <?= e($course['batch_name'] ?? '') ?></span></h1>
    <form method="post" action="course-parcel.php" class="flex items-center gap-2 mt-3 flex-wrap"
          onsubmit="return <?= $totalParcels ? "confirmSubmit(this, 'মোট পার্সেল সংখ্যা বদলাচ্ছেন — এটা প্রতি শিক্ষার্থীর স্লট-সংখ্যা বদলে দেবে। নিশ্চিত?', 'সংখ্যা বদলাবেন?')" : 'true' ?>;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="parcels">
        <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="period" value="<?= e($period) ?>">
        <label class="text-sm font-semibold text-gray-700">মোট কয়বার পার্সেল যাবে?</label>
        <input type="number" name="total_parcels" min="0" max="60" value="<?= $totalParcels ?>" class="w-20 border rounded-lg px-3 py-1.5 text-sm">
        <button type="submit" class="bg-indigo-600 text-white text-sm font-semibold px-3 py-1.5 rounded-lg">সেভ</button>
        <span class="text-xs text-gray-400">প্রতি শিক্ষার্থীর <?= $totalParcels ?: 'N' ?> টা স্লট। মান্থলি ফি ৳<?= e(number_format($courseFee)) ?></span>
    </form>
</div>

<?php // ══════════ Section 1 — অবস্থা (রোস্টার) ══════════ ?>
<div class="flex items-center gap-2 mb-2"><span class="text-sm font-bold text-gray-700">১ · অবস্থা</span><span class="text-xs text-gray-400">গ্রুপে যোগ · সক্রিয়/নিষ্ক্রিয় · পার্সেল অগ্রগতি</span></div>
<?php if (!$regs): ?>
    <div class="bg-white rounded-2xl shadow empty-state mb-6"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>এই ব্যাচে কোনো confirmed রেজিস্ট্রেশন নেই।</div>
<?php else: ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto mb-3">
        <table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিশুর নাম</th>
                <th class="py-3 px-4 text-center">FB গ্রুপ</th>
                <th class="py-3 px-4 text-center">Messenger</th>
                <th class="py-3 px-4 text-center">সক্রিয়</th>
                <th class="py-3 px-4">পার্সেল অগ্রগতি (<?= $totalParcels ?: '—' ?>)</th>
            </tr></thead>
            <tbody>
            <?php if (!$activeRegs && $inactiveRegs): ?>
                <tr><td colspan="5" class="py-6 px-4 text-center text-gray-400">সব শিক্ষার্থী নিষ্ক্রিয় — নিচে থেকে সক্রিয় করুন।</td></tr>
            <?php endif; ?>
            <?php foreach ($activeRegs as $r) { cp_roster_row($r, $slotsByReg, $totalParcels, $itemId, $period); } ?>
            </tbody>
        </table>
    </div>
    <?php if ($inactiveRegs): ?>
        <details class="mb-6">
            <summary class="cursor-pointer select-none text-sm font-semibold text-gray-500 py-2 px-1 flex items-center gap-1.5">
                <i data-lucide="chevron-right" class="w-4 h-4"></i> নিষ্ক্রিয় শিক্ষার্থী (<?= e(number_format(count($inactiveRegs))) ?> জন) — দেখতে/আবার সক্রিয় করতে ক্লিক করুন
            </summary>
            <div class="bg-white rounded-2xl shadow overflow-x-auto mt-2">
                <table class="w-full text-sm mcard"><tbody>
                <?php foreach ($inactiveRegs as $r) { cp_roster_row($r, $slotsByReg, $totalParcels, $itemId, $period); } ?>
                </tbody></table>
            </div>
        </details>
    <?php else: ?><div class="mb-6"></div><?php endif; ?>

    <?php // ══════════ Section 2 — এই দফার পার্সেল ══════════ ?>
    <div class="flex items-center gap-2 mb-2"><span class="text-sm font-bold text-gray-700">২ · এই দফার পার্সেল</span><span class="text-xs text-gray-400">মাস বেছে প্রস্তুত করুন / পাঠান</span></div>

    <?php if (!$activeRegs): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="user-x" class="w-8 h-8"></i></div>কোনো সক্রিয় শিক্ষার্থী নেই — উপরে থেকে সক্রিয় করলে এখানে আসবে।</div>
    <?php else: ?>
        <?php // মাস পিকার — বদলালে GET রিলোড করে ঐ মাসের বিদ্যমান অবস্থা লোড হয় ?>
        <div class="bg-white rounded-2xl shadow p-4 mb-3">
            <label class="text-xs text-gray-600 font-semibold">কোন মাস/দফা? <span class="text-red-500">*</span></label>
            <div class="flex items-center gap-2 mt-1 flex-wrap">
                <input type="text" id="periodPick" value="<?= e($period) ?>" placeholder="যেমন: ১ম মাস" list="periodList"
                       class="border rounded-lg px-3 py-2 text-sm" style="width:160px;">
                <datalist id="periodList"><option value="১ম মাস"><option value="২য় মাস"><option value="৩য় মাস"><option value="৪র্থ মাস"><option value="৫ম মাস"><option value="৬ষ্ঠ মাস"></datalist>
                <button type="button" id="loadPeriod" class="bg-gray-100 hover:bg-gray-200 text-gray-800 text-sm font-semibold px-3 py-2 rounded-lg">এই মাস দেখুন</button>
                <?php if ($period !== ''): ?>
                    <span class="text-xs <?= $periodHasExisting ? 'text-blue-700' : 'text-gray-400' ?>"><?= $periodHasExisting ? 'এই মাসের কিছু পার্সেল আগে থেকেই আছে (বদলালে ওয়ার্নিং দেবে)' : 'নতুন মাস — সবাই ডিফল্টে "যাবে"' ?></span>
                <?php endif; ?>
            </div>
            <?php if ($period === ''): ?><p class="text-xs text-gray-400 mt-2">মাস লিখে "এই মাস দেখুন" চাপুন — তারপর প্রতি শিক্ষার্থীর পার্সেল প্রস্তুত করা যাবে।</p><?php endif; ?>
        </div>

        <?php if ($period !== ''): ?>
        <form method="post" id="roundForm" action="course-parcel.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="round-save">
            <input type="hidden" name="mode" id="roundMode" value="draft">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="period" value="<?= e($period) ?>">

            <div class="space-y-3 pb-32">
            <?php foreach ($activeRegs as $r):
                $rid = (int) $r['id'];
                $ex = $periodByReg[$rid] ?? null;
                $isNo = $ex && $ex['send_status'] === 'declined';
                $isSent = $ex && $ex['send_status'] === 'sent';
                $exMult = $ex['monthly_multiplier'] ?? 1;
                $exZone = $ex['delivery_zone'] ?? 'dhaka';
                $exWx = !empty($ex['weight_extra']);
                $exAdj = $ex['adjustment'] ?? 0;
                $exReason = $ex['adjustment_reason'] ?? '';
                $exAmt = $ex['amount_to_collect'] ?? '';
                $notes = $notesByReg[$rid] ?? [];
            ?>
                <div class="stu bg-white rounded-2xl shadow p-4 <?= $isNo ? 'opacity-60' : '' ?>" data-fee="<?= (int) $courseFee ?>" data-sent="<?= $isSent ? 1 : 0 ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][present]" value="1">
                    <input type="hidden" name="bd[<?= $rid ?>][decision]" class="decision" value="<?= $isNo ? 'no' : 'go' ?>">

                    <div class="flex items-start gap-2.5 mb-3">
                        <div class="min-w-0 flex-1">
                            <div class="text-[11px] text-gray-400 leading-tight">শিশুর নাম</div>
                            <div class="font-bold text-gray-900 text-sm break-words"><?= e($r['customer_name']) ?></div>
                            <div class="text-[11px] text-gray-500">রিসিভার: <?= e($r['receiver_name'] ?: $r['customer_name']) ?> · <?= e($r['receiver_phone'] ?: $r['phone']) ?></div>
                            <div class="text-[11px] text-gray-400 break-words">ঠিকানা: <?= e($r['address'] ?: '—') ?></div>
                        </div>
                        <?php if ($isSent): ?>
                            <span class="flex-shrink-0 text-xs px-2 py-1 rounded-lg bg-green-100 text-green-700 font-semibold">✓ পাঠানো</span>
                        <?php else: ?>
                            <button type="button" class="goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border"
                                    data-name="<?= e($r['customer_name']) ?>"></button>
                        <?php endif; ?>
                    </div>

                    <?php if ($notes): ?><div class="flex flex-wrap items-center gap-1.5 mb-3"><?php render_note_chips($notes); ?></div><?php endif; ?>

                    <?php // কালেকশন বিল্ডার (isSent হলে লক করা দেখায়) ?>
                    <div class="builder <?= $isSent ? 'opacity-50 pointer-events-none' : '' ?>">
                        <div class="grid grid-cols-2 gap-2">
                            <label class="text-xs text-gray-600">মান্থলি ফি<select name="bd[<?= $rid ?>][mult]" class="mult block w-full border rounded-lg px-2 py-2 text-sm mt-1">
                                <option value="1" <?= (float)$exMult==1?'selected':'' ?>>১ মাস</option>
                                <option value="1.5" <?= (float)$exMult==1.5?'selected':'' ?>>১.৫ মাস</option>
                                <option value="2" <?= (float)$exMult==2?'selected':'' ?>>২ মাস</option>
                            </select></label>
                            <label class="text-xs text-gray-600">ডেলিভারি<select name="bd[<?= $rid ?>][zone]" class="zone block w-full border rounded-lg px-2 py-2 text-sm mt-1">
                                <option value="dhaka" <?= $exZone==='dhaka'?'selected':'' ?>>ঢাকা</option>
                                <option value="near" <?= $exZone==='near'?'selected':'' ?>>নিকটবর্তী</option>
                                <option value="outside" <?= $exZone==='outside'?'selected':'' ?>>বাইরে</option>
                            </select></label>
                        </div>
                        <div class="grid gap-2 mt-2" style="grid-template-columns:auto 1fr 1.1fr; align-items:end;">
                            <label class="flex items-center gap-1 text-xs text-gray-600 pb-2"><input type="checkbox" class="wx w-4 h-4 accent-indigo-600" name="bd[<?= $rid ?>][wx]" value="1" <?= $exWx?'checked':'' ?>> ওজন+</label>
                            <label class="text-xs text-gray-600">সমন্বয়±<input type="number" step="1" value="<?= e((string)(float)$exAdj) ?>" name="bd[<?= $rid ?>][adj]" class="adj block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                            <label class="text-xs text-gray-600">কারণ<input type="text" name="bd[<?= $rid ?>][reason]" value="<?= e($exReason) ?>" placeholder="ডিসকাউন্ট..." class="block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                        </div>
                        <div class="flex items-end justify-between gap-2 mt-3 pt-3 border-t">
                            <div class="min-w-0">
                                <div class="text-xs text-gray-500">কালেকশন</div>
                                <div class="autohint text-[11px] text-gray-400 hidden">অটো: ৳<span class="autoval">০</span> · <button type="button" class="text-indigo-600 font-semibold underline resetauto">অটোতে ফিরুন</button></div>
                            </div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <span class="text-lg font-black text-gray-900">৳</span>
                                <input type="number" step="1" min="0" name="bd[<?= $rid ?>][amt]" value="<?= e((string)$exAmt) ?>" class="amt border rounded-lg px-2 py-1.5 text-lg font-black text-gray-900 text-right bg-gray-50" style="width:110px;" readonly>
                                <input type="hidden" name="bd[<?= $rid ?>][manual]" class="manual" value="">
                                <button type="button" class="editamt text-gray-400 hover:text-indigo-600 p-1.5" title="পরিমাণ এডিট করুন"><i data-lucide="lock" class="w-4 h-4"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <?php // স্টিকি অ্যাকশন বার ?>
            <div class="fixed inset-x-0 bottom-0 bg-white border-t border-gray-200 px-4 py-3 z-30" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
                <div class="max-w-2xl mx-auto flex items-center gap-3 flex-wrap">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500"><span id="goCount">০</span> জন "যাবে" · <span id="noCount">০</span> জন "না" · মোট কালেকশন</div>
                        <div id="sumAmt" class="text-lg font-black text-gray-900">৳০</div>
                    </div>
                    <button type="button" id="draftBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="save" class="w-4 h-4 inline"></i> খসড়া/সেভ</button>
                    <button type="button" id="sendBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4 inline"></i> কুরিয়ারে পাঠান</button>
                </div>
            </div>
        </form>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>

<script>
(function(){
  var bn=['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
  function toBn(n){return String(n).replace(/[0-9]/g,function(d){return bn[d];});}

  // মাস পিকার → GET রিলোড (ঐ মাসের বিদ্যমান অবস্থা লোড)
  var pick=document.getElementById('periodPick'), loadBtn=document.getElementById('loadPeriod');
  function gotoPeriod(){
    var p=(pick&&pick.value.trim())||'';
    var base='course-parcel.php?item_id=<?= $itemId ?>';
    window.location.href = p ? base+'&period='+encodeURIComponent(p) : base;
  }
  if(loadBtn){ loadBtn.addEventListener('click',gotoPeriod); }
  if(pick){ pick.addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); gotoPeriod(); } }); }

  var form=document.getElementById('roundForm');
  if(!form){ return; }
  var DC={dhaka:<?= (float) $dc['dhaka'] ?>,near:<?= (float) $dc['near'] ?>,outside:<?= (float) $dc['outside'] ?>};
  var WX=<?= (float) $wxExtra ?>;
  var periodHasExisting=<?= $periodHasExisting ? 'true' : 'false' ?>;

  // go/no বাটনের চেহারা আপডেট
  function paintGoNo(el){
    var dec=el.querySelector('.decision').value, btn=el.querySelector('.goNoBtn');
    if(!btn){ return; } // পাঠানো কার্ডে বাটন নেই
    if(dec==='no'){
      btn.textContent='না এই মাসে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-red-300 bg-red-50 text-red-700';
      el.classList.add('opacity-60');
    } else {
      btn.textContent='✓ যাবে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-green-300 bg-green-50 text-green-700';
      el.classList.remove('opacity-60');
    }
  }
  function calc(el){
    var fee=+el.getAttribute('data-fee'), mult=+el.querySelector('.mult').value, zone=el.querySelector('.zone').value;
    var wx=el.querySelector('.wx').checked?WX:0, adj=+el.querySelector('.adj').value||0;
    var auto=Math.max(0,Math.round(fee*mult+(DC[zone]||0)+wx+adj));
    var amtEl=el.querySelector('.amt'), manual=el.querySelector('.manual').value==='1';
    el.querySelector('.autoval').textContent=toBn(auto);
    if(!manual){ amtEl.value=auto; }
    var dec=el.querySelector('.decision').value, sent=el.getAttribute('data-sent')==='1';
    return {go: dec==='go' && !sent, no: dec==='no', t:+amtEl.value||0};
  }
  function refresh(){
    var s=0,g=0,no=0;
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el); if(r.go){s+=r.t;g++;} if(r.no){no++;} paintGoNo(el);});
    var gc=document.getElementById('goCount'),nc=document.getElementById('noCount'),sa=document.getElementById('sumAmt');
    if(gc)gc.textContent=toBn(g); if(nc)nc.textContent=toBn(no); if(sa)sa.textContent='৳'+toBn(s);
  }

  // go/no টগল
  document.querySelectorAll('.stu').forEach(function(el){
    var btn=el.querySelector('.goNoBtn');
    if(btn){ btn.addEventListener('click',function(){
      var d=el.querySelector('.decision'); d.value=(d.value==='no')?'go':'no'; refresh();
    }); }
    // কালেকশন এডিট আনলক (ওয়ার্নিং সহ)
    var amtEl=el.querySelector('.amt'), manualEl=el.querySelector('.manual'),
        eb=el.querySelector('.editamt'), hint=el.querySelector('.autohint');
    if(eb){ eb.addEventListener('click',function(){
      if(manualEl.value==='1'){ return; }
      showConfirmModal('কালেকশনের পরিমাণ নিজে লিখবেন? এরপর অটো হিসাব আর প্রযোজ্য হবে না।',function(){
        manualEl.value='1'; amtEl.readOnly=false; amtEl.classList.remove('bg-gray-50'); amtEl.classList.add('bg-yellow-50','ring-2','ring-amber-300');
        eb.innerHTML='<i data-lucide="pencil" class="w-4 h-4"></i>'; hint.classList.remove('hidden');
        if(window.lucide&&lucide.createIcons)lucide.createIcons(); amtEl.focus(); amtEl.select();
      },'পরিমাণ এডিট করবেন?');
    }); }
    var ra=el.querySelector('.resetauto');
    if(ra){ ra.addEventListener('click',function(){
      manualEl.value=''; amtEl.readOnly=true; amtEl.classList.add('bg-gray-50'); amtEl.classList.remove('bg-yellow-50','ring-2','ring-amber-300');
      eb.innerHTML='<i data-lucide="lock" class="w-4 h-4"></i>'; hint.classList.add('hidden');
      if(window.lucide&&lucide.createIcons)lucide.createIcons(); refresh();
    }); }
  });

  function doSubmit(mode){
    document.getElementById('roundMode').value=mode;
    var summary={g:0,no:0,s:0};
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el); if(r.go){summary.g++;summary.s+=r.t;} if(r.no)summary.no++;});
    if(mode==='send' && summary.g===0){ showConfirmModal('কাউকে "যাবে" রাখা হয়নি — পাঠানোর মতো কেউ নেই।',function(){},'পাঠানো খালি'); return; }
    var msg = (mode==='send')
      ? ('নির্বাচিত '+toBn(summary.g)+' জনের পার্সেল এখনই আসল কুরিয়ারে পাঠাবেন? মোট কালেকশন ৳'+toBn(summary.s)+'। পাঠানোর পর ফেরানো যাবে না।')
      : ('এই দফার '+toBn(summary.g)+' জন "যাবে", '+toBn(summary.no)+' জন "না" — সেভ করবেন?');
    if(periodHasExisting){ msg = 'এই মাসের কিছু পার্সেল আগে থেকেই আছে — পরিবর্তন হবে। ' + msg; }
    showConfirmModal(msg,function(){ form.submit(); }, mode==='send'?'কুরিয়ারে পাঠান':'সেভ করবেন?');
  }
  var db=document.getElementById('draftBtn'); if(db){ db.addEventListener('click',function(){ doSubmit('draft'); }); }
  var sb=document.getElementById('sendBtn'); if(sb){ sb.addEventListener('click',function(){ doSubmit('send'); }); }

  form.addEventListener('input',refresh); form.addEventListener('change',refresh); refresh();
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
