<?php
// কুরিয়ার ট্র্যাকিং গ্রিড (COURIER-REDESIGN-PLAN.md ধাপ ৭) — একটা কোর্স-ব্যাচের শিক্ষার্থী × মাস ম্যাট্রিক্স:
// কে কোন মাসে (period_label) পার্সেল পেয়েছে/প্রস্তুত/ব্যর্থ। read-only, additive (কিছু বদলায় না)।
// টেবিল `.overflow-x-auto`-এ মোড়ানো — মোবাইলে অটো কার্ড-লেআউট (CLAUDE.md নিয়ম)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কুরিয়ার ট্র্যাকিং';
$itemId = (int) ($_GET['item_id'] ?? 0);

$statusMeta = [
    'draft'  => ['প্রস্তুত', 'bg-gray-100 text-gray-600', 'clock'],
    'sent'   => ['পাঠানো', 'bg-green-100 text-green-700', 'check'],
    'failed' => ['ব্যর্থ', 'bg-red-100 text-red-700', 'x'],
];

require __DIR__ . '/includes/layout-top.php';

// ── কোর্স-ব্যাচ বাছাই: প্রতি ব্যাচে কত মাস/কত পার্সেল গেছে সেটাও দেখানো হয় ──
if (!$itemId) {
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c,
                COUNT(DISTINCT cb.period_label) months,
                SUM(CASE WHEN cb.send_status = 'sent' THEN 1 ELSE 0 END) sent_cnt
         FROM registrations r
         LEFT JOIN courier_batches cb ON cb.registration_id = r.id
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="max-w-3xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচের পার্সেল-ইতিহাস দেখতে চান বেছে নিন — কোন মাসে কে পেয়েছে, কে বাকি।</p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="calendar" class="w-8 h-8"></i></div>কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">শিক্ষার্থী</th>
                    <th class="py-3 px-4">মাস</th><th class="py-3 px-4">পাঠানো</th><th class="py-3 px-4">অ্যাকশন</th>
                </tr></thead>
                <tbody>
                <?php foreach ($batches as $b): ?>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><?= (int) $b['months'] ?: '—' ?></td>
                        <td class="py-2.5 px-4"><?= (int) $b['sent_cnt'] ? '<span class="text-green-700 font-semibold">' . (int) $b['sent_cnt'] . ' টি</span>' : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="py-2.5 px-4"><a href="courier-tracking.php?item_id=<?= (int) $b['item_id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">ট্র্যাকিং দেখুন →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ── নির্দিষ্ট ব্যাচ ──
$cStmt = $db->prepare('SELECT c.title, cb.batch_name FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();

$regs = $db->prepare("SELECT id, customer_name, courier_active FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

$cells = []; $periods = [];
$periodStats = []; // মাস => [sent, draft, failed, total, amount]
$studentTotals = [];
if ($regs) {
    $ids = implode(',', array_map(fn($r) => (int) $r['id'], $regs));
    foreach ($db->query(
        "SELECT registration_id, period_label, send_status, amount_to_collect, id FROM courier_batches
         WHERE registration_id IN ($ids) ORDER BY id ASC"
    )->fetchAll() as $b) {
        $p = $b['period_label'] ?: '(লেবেল নেই)';
        $periods[$p] = true;
        $cells[$b['registration_id']][$p] = $b; // একই period একাধিক হলে সর্বশেষটা
    }
    // সারাংশ সবসময় $cells (period প্রতি সর্বশেষ ব্যাচ) থেকে হিসাব — নাহলে একই মাসে দুইবার
    // খসড়া তৈরি হলে গণনা দ্বিগুণ দেখাত
    foreach ($cells as $regId => $byPeriod) {
        foreach ($byPeriod as $p => $b) {
            $st = $b['send_status'];
            $periodStats[$p] ??= ['sent' => 0, 'draft' => 0, 'failed' => 0, 'total' => 0, 'amount' => 0.0];
            $periodStats[$p][$st] = ($periodStats[$p][$st] ?? 0) + 1;
            $periodStats[$p]['total']++;
            $periodStats[$p]['amount'] += (float) $b['amount_to_collect'];
            $studentTotals[$regId] = ($studentTotals[$regId] ?? 0) + (float) $b['amount_to_collect'];
        }
    }
}
$periods = array_keys($periods);
?>
<div class="max-w-5xl">
    <a href="courier-tracking.php" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm mb-3 py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> সব কোর্স-ব্যাচ</a>

    <?php // ── কোন কোর্স / কোন ব্যাচ — স্পষ্ট হেডার ── ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4">
        <div class="text-xs text-gray-400">কোর্স</div>
        <h3 class="font-bold text-gray-800 text-lg break-words"><?= e($course['title'] ?? '') ?></h3>
        <div class="flex items-center gap-2 mt-1 flex-wrap">
            <span class="text-xs text-gray-400">ব্যাচ</span>
            <span class="px-2.5 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-sm font-semibold"><?= e($course['batch_name'] ?? '') ?></span>
            <span class="text-xs text-gray-400"><?= count($regs) ?> জন শিক্ষার্থী · <?= count($periods) ?> মাস</span>
        </div>
        <p class="text-xs text-gray-500 mt-3">
            প্রতিটা ঘরে ঐ মাসের কালেকশন — <span class="text-green-700 font-semibold">✅ পাঠানো</span> ·
            <span class="text-gray-600 font-semibold">⏳ প্রস্তুত (এখনো পাঠানো হয়নি)</span> ·
            <span class="text-red-700 font-semibold">✗ ব্যর্থ</span> · — এখনো তৈরি হয়নি।
            <a href="courier-prepare.php?item_id=<?= (int) $itemId ?>" class="text-indigo-600 font-semibold inline-block py-1">নতুন পার্সেল প্রস্তুত করুন →</a>
        </p>
    </div>

    <?php if (!$regs): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>এই ব্যাচে confirmed রেজিস্ট্রেশন নেই।</div>
    <?php elseif (!$periods): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="package" class="w-8 h-8"></i></div>এখনো কোনো পার্সেল/ব্যাচ তৈরি হয়নি।</div>
    <?php else: ?>

        <?php // ── মাস-ভিত্তিক সারাংশ কার্ড (এক নজরে কোন মাসে কী অবস্থা) ── ?>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 mb-4">
            <?php foreach ($periods as $p): $ps = $periodStats[$p]; ?>
                <div class="bg-white rounded-2xl shadow p-3">
                    <div class="font-bold text-gray-800 text-sm break-words"><?= e($p) ?></div>
                    <div class="text-xl font-black text-gray-900 mt-1">৳<?= e(number_format($ps['amount'])) ?></div>
                    <div class="flex flex-wrap gap-1 mt-2">
                        <?php foreach (['sent', 'draft', 'failed'] as $st): if (empty($ps[$st])) continue; $m = $statusMeta[$st]; ?>
                            <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-semibold <?= $m[1] ?>"><i data-lucide="<?= $m[2] ?>" class="w-3 h-3"></i><?= (int) $ps[$st] ?></span>
                        <?php endforeach; ?>
                        <span class="text-[11px] text-gray-400 self-center"><?= (int) $ps['total'] ?>/<?= count($regs) ?> জন</span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php // ── শিক্ষার্থী × মাস ম্যাট্রিক্স ── ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিক্ষার্থী</th>
                <?php foreach ($periods as $p): ?><th class="py-3 px-4 whitespace-nowrap"><?= e($p) ?></th><?php endforeach; ?>
                <th class="py-3 px-4">মোট</th>
            </tr></thead>
            <tbody>
            <?php foreach ($regs as $r): $inactive = !((int) ($r['courier_active'] ?? 1)); ?>
                <tr class="<?= $inactive ? 'opacity-60' : '' ?>">
                    <td class="py-2.5 px-4 font-semibold text-gray-800">
                        <?= e($r['customer_name']) ?>
                        <?php if ($inactive): ?><span class="ml-1 text-[11px] px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 font-normal">নিষ্ক্রিয়</span><?php endif; ?>
                    </td>
                    <?php foreach ($periods as $p):
                        $b = $cells[$r['id']][$p] ?? null; ?>
                        <td class="py-2.5 px-4">
                            <?php if ($b):
                                $m = $statusMeta[$b['send_status']] ?? ['?', 'bg-gray-100 text-gray-600', 'help-circle']; ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-lg text-xs font-semibold <?= $m[1] ?>" title="<?= e($m[0]) ?>"><i data-lucide="<?= $m[2] ?>" class="w-3 h-3"></i> ৳<?= e(number_format((float) $b['amount_to_collect'])) ?></span>
                            <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td class="py-2.5 px-4 font-black text-gray-900 whitespace-nowrap">৳<?= e(number_format($studentTotals[$r['id']] ?? 0)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr class="border-t bg-gray-50 font-bold text-gray-800">
                <td class="py-3 px-4">মাসের মোট</td>
                <?php $grand = 0; foreach ($periods as $p): $grand += $periodStats[$p]['amount']; ?>
                    <td class="py-3 px-4 whitespace-nowrap">৳<?= e(number_format($periodStats[$p]['amount'])) ?></td>
                <?php endforeach; ?>
                <td class="py-3 px-4 whitespace-nowrap">৳<?= e(number_format($grand)) ?></td>
            </tr></tfoot>
        </table></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
