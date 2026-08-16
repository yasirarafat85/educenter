<?php
// কোর্স ট্র্যাকিং — গ্রুপে যোগ + মাসিক পার্সেল ট্র্যাকিং (overview; পার্সেল তৈরি/পাঠায় না — সেটা courier-prepare-এর কাজ)।
// GROUP-PARCEL-TRACKING-PLAN.md দ্রষ্টব্য। শেয়ার্ড ডেটা: courier_batches (স্লট), registrations.courier_active (অফ)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কোর্স ট্র্যাকিং';

function ct_return(int $itemId): string
{
    return 'course-tracking.php' . ($itemId ? '?item_id=' . $itemId : '');
}

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('course-tracking.php'); }
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $regId  = (int) ($_POST['id'] ?? 0);

    if ($action === 'group') {
        $field = ($_POST['field'] ?? '') === 'messenger' ? 'messenger_group_added' : 'fb_group_added';
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare("UPDATE registrations SET $field = :v WHERE id = :id")->execute(['v' => $val, 'id' => $regId]);
    } elseif ($action === 'active') {
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare('UPDATE registrations SET courier_active = :v WHERE id = :id')->execute(['v' => $val, 'id' => $regId]);
        set_flash('success', $val ? 'সক্রিয় করা হলো।' : 'নিষ্ক্রিয় করা হলো — এই শিক্ষার্থী আর পার্সেল প্রস্তুত লিস্টে আসবে না।');
    } elseif ($action === 'parcels') {
        $n = max(0, min(60, (int) ($_POST['total_parcels'] ?? 0)));
        $db->prepare('UPDATE course_batches SET total_parcels = :n WHERE id = :id')->execute(['n' => $n, 'id' => $itemId]);
        set_flash('success', 'মোট পার্সেল সংখ্যা সেভ হয়েছে।');
    } elseif ($action === 'decline') {
        // "এই দফা না" — একটা declined স্লট রেকর্ড (পার্সেল আসলে পাঠানো হয় না, শুধু ❌ ট্র্যাক)
        $db->prepare("INSERT INTO courier_batches (registration_id, period_label, send_status) VALUES (:r, :p, 'declined')")
           ->execute(['r' => $regId, 'p' => 'না (' . date('d/m/y') . ')']);
    } elseif ($action === 'undecline') {
        // সর্বশেষ declined স্লট মুছি (শুধু declined — sent/draft ছোঁয় না)
        $last = $db->prepare("SELECT id FROM courier_batches WHERE registration_id = :r AND send_status = 'declined' ORDER BY created_at DESC, id DESC LIMIT 1");
        $last->execute(['r' => $regId]);
        $lid = (int) $last->fetchColumn();
        if ($lid) { $db->prepare('DELETE FROM courier_batches WHERE id = :id')->execute(['id' => $lid]); }
    }
    redirect(ct_return($itemId));
}

$itemId = (int) ($_GET['item_id'] ?? 0);
require __DIR__ . '/includes/layout-top.php';

// ---------------- কোর্স-ব্যাচ পিকার (item_id না থাকলে) ----------------
if (!$itemId) {
    $batches = $db->query(
        "SELECT item_id, item_title, batch, COUNT(*) c FROM registrations
         WHERE type='course' AND status='confirmed' GROUP BY item_id, item_title, batch ORDER BY item_title, batch"
    )->fetchAll();
    ?>
    <div class="max-w-2xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচ ট্র্যাক করবেন সেটা বেছে নিন — গ্রুপে যোগ, পার্সেল প্রগ্রেস, সক্রিয়/নিষ্ক্রিয় সব এক জায়গায়।</p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="clipboard-check" class="w-8 h-8"></i></div>কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50"><th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">শিক্ষার্থী</th><th class="py-3 px-4">অ্যাকশন</th></tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><?= e($b['batch'] ?: '—') ?></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><a href="course-tracking.php?item_id=<?= (int) $b['item_id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">ট্র্যাক করুন →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ---------------- নির্দিষ্ট ব্যাচের ট্র্যাকিং ----------------
$cStmt = $db->prepare('SELECT cb.total_parcels, cb.batch_name, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
$totalParcels = (int) ($course['total_parcels'] ?? 0);

$regs = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

// প্রতি রেজিস্ট্রেশনের courier_batches (স্লট) — created_at ক্রমে
$batchesByReg = [];
if ($regs) {
    $ids = array_map(fn($r) => (int) $r['id'], $regs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $db->prepare("SELECT registration_id, send_status FROM courier_batches WHERE registration_id IN ($in) ORDER BY created_at ASC, id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) { $batchesByReg[(int) $row['registration_id']][] = $row['send_status']; }
}

// স্লট স্ট্যাটাস → রঙ/আইকন
function ct_slot(string $status): string
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
?>
<div class="mb-4"><a href="course-tracking.php" class="text-gray-500 text-sm">← সব কোর্স-ব্যাচ</a></div>

<div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-5">
    <h1 class="text-lg font-black text-gray-800 mb-1"><?= e($course['title']) ?> <span class="text-gray-400 font-semibold text-sm">— <?= e($course['batch_name']) ?></span></h1>
    <form method="post" action="course-tracking.php?item_id=<?= $itemId ?>" class="flex items-center gap-2 mt-3 flex-wrap">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="parcels">
        <input type="hidden" name="item_id" value="<?= $itemId ?>">
        <label class="text-sm font-semibold text-gray-700">মোট কয়বার পার্সেল যাবে?</label>
        <input type="number" name="total_parcels" min="0" max="60" value="<?= $totalParcels ?>" class="w-20 border rounded-lg px-3 py-1.5 text-sm">
        <button type="submit" class="bg-indigo-600 text-white text-sm font-semibold px-3 py-1.5 rounded-lg">সেভ</button>
        <span class="text-xs text-gray-400">প্রতি শিক্ষার্থীর <?= $totalParcels ?: 'N' ?> টা স্লট দেখাবে</span>
    </form>
</div>

<?php $curReturn = ct_return($itemId); ?>
<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm mcard">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিশুর নাম</th>
                <th class="py-3 px-4">মোবাইল (মা)</th>
                <th class="py-3 px-4">ফেসবুক নাম</th>
                <th class="py-3 px-4 text-center">FB গ্রুপ</th>
                <th class="py-3 px-4 text-center">Messenger</th>
                <th class="py-3 px-4 text-center">সক্রিয়</th>
                <th class="py-3 px-4">পার্সেল (<?= $totalParcels ?: '—' ?>)</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$regs): ?>
            <tr><td colspan="8" class="py-8 px-4 text-center text-gray-400">এই ব্যাচে কোনো confirmed রেজিস্ট্রেশন নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($regs as $r):
            $rid = (int) $r['id'];
            $slots = $batchesByReg[$rid] ?? [];
            $sentCount = count(array_filter($slots, fn($s) => $s === 'sent'));
            $n = max($totalParcels, count($slots));
            $active = (int) $r['courier_active'];
        ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $active ? '' : 'opacity-60' ?>">
                <td class="py-2.5 px-4 font-semibold text-gray-900"><?= e($r['customer_name']) ?></td>
                <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($r['phone']) ?></td>
                <td class="py-2.5 px-4 text-gray-600"><?= e($r['facebook_id'] ?: '-') ?></td>
                <!-- গ্রুপ টগল (FB) -->
                <td class="py-2.5 px-4 text-center">
                    <form method="post" action="course-tracking.php" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="fb">
                        <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                        <input type="hidden" name="value" value="<?= $r['fb_group_added'] ? 0 : 1 ?>">
                        <button type="submit" class="text-lg leading-none" title="FB গ্রুপে যোগ হয়েছে?"><?= $r['fb_group_added'] ? '✅' : '⬜' ?></button>
                    </form>
                </td>
                <!-- গ্রুপ টগল (Messenger) -->
                <td class="py-2.5 px-4 text-center">
                    <form method="post" action="course-tracking.php" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="messenger">
                        <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                        <input type="hidden" name="value" value="<?= $r['messenger_group_added'] ? 0 : 1 ?>">
                        <button type="submit" class="text-lg leading-none" title="Messenger গ্রুপে যোগ হয়েছে?"><?= $r['messenger_group_added'] ? '✅' : '⬜' ?></button>
                    </form>
                </td>
                <!-- সক্রিয় টগল -->
                <td class="py-2.5 px-4 text-center">
                    <form method="post" action="course-tracking.php" class="inline"
                        onsubmit="return <?= $active ? "confirmSubmit(this, 'নিষ্ক্রিয় করলে এই শিক্ষার্থী আর পার্সেল প্রস্তুত লিস্টে আসবে না। নিশ্চিত?', 'নিষ্ক্রিয় করবেন?')" : 'true' ?>;">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="active"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                        <input type="hidden" name="value" value="<?= $active ? 0 : 1 ?>">
                        <button type="submit" class="font-semibold <?= $active ? 'text-green-600' : 'text-gray-400' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></button>
                    </form>
                </td>
                <!-- পার্সেল স্লট + প্রগ্রেস -->
                <td class="py-2.5 px-4">
                    <div class="flex items-center gap-1 flex-wrap">
                        <?php for ($i = 0; $i < $n; $i++): echo ct_slot($slots[$i] ?? 'empty'); endfor; ?>
                        <?php if ($n === 0): ?><span class="text-xs text-gray-400">উপরে "মোট পার্সেল" দিন</span><?php endif; ?>
                    </div>
                    <?php if ($totalParcels): ?><span class="text-xs text-gray-500 font-semibold"><?= $sentCount ?>/<?= $totalParcels ?> পাঠানো</span><?php endif; ?>
                </td>
                <!-- অ্যাকশন: এই দফা না / বিস্তারিত -->
                <td class="py-2.5 px-4 whitespace-nowrap space-x-2">
                    <form method="post" action="course-tracking.php" class="inline" onsubmit="return confirmSubmit(this, 'এই শিক্ষার্থী এই দফা \'না\' করেছেন — একটা ❌ স্লট যোগ হবে (পার্সেল পাঠানো হবে না)। ঠিক আছে?', 'এই দফা না');">
                        <?= csrf_field() ?><input type="hidden" name="action" value="decline"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                        <button type="submit" class="text-red-600 font-semibold text-xs">❌ না</button>
                    </form>
                    <?php if (in_array('declined', $slots, true)): ?>
                    <form method="post" action="course-tracking.php" class="inline"><?= csrf_field() ?>
                        <input type="hidden" name="action" value="undecline"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>">
                        <button type="submit" class="text-gray-500 font-semibold text-xs" title="সর্বশেষ ❌ ফেরাও">↩</button>
                    </form>
                    <?php endif; ?>
                    <button type="button" onclick="var d=document.getElementById('det-<?= $rid ?>');d.classList.toggle('hidden');" class="text-indigo-600 font-semibold text-xs">বিস্তারিত</button>
                    <div id="det-<?= $rid ?>" class="hidden mt-2 text-xs text-gray-600 bg-gray-50 rounded-lg p-2 leading-relaxed font-normal whitespace-normal">
                        <div>রিসিভার: <?= e($r['receiver_name'] ?: '-') ?> · <?= e($r['receiver_phone'] ?: '-') ?></div>
                        <div>ঠিকানা: <?= e($r['address'] ?: '-') ?></div>
                        <div>বাবার মোবাইল: <?= e($r['father_mobile'] ?: '-') ?></div>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<p class="text-xs text-gray-400 mt-3">স্লট: <span class="text-green-600 font-semibold">✓ পাঠানো</span> · <span class="text-amber-500 font-semibold">● প্রস্তুত</span> · <span class="text-red-600 font-semibold">✕ না</span> · <span class="text-gray-400 font-semibold">⬜ এখনো না</span>। পার্সেল আসল পাঠানো হয় <a href="courier-prepare.php" class="text-indigo-600">পার্সেল প্রস্তুত</a> পেজ থেকে।</p>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
