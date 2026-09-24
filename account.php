<?php
// অভিভাবক ড্যাশবোর্ড — নিজের কোর্স, কিস্তির হিসাব (জমা/বাকি), পার্সেল, নোটিশ, অনুরোধ/মন্তব্য।
// 🔴 প্রাইভেসি: সব কোয়েরি শুধু লগইন-করা ইউজারের নিজের phone দিয়ে — কখনো URL/প্যারামিটার থেকে নয়।
// 🔴 টাকার প্রদর্শন-নীতি (২০২৬-০৯-২৪, ইউজারের স্পষ্ট নির্দেশ): "মোট কত খরচ করেছেন" জাতীয়
//    কোনো যোগফল দেখানো হয় না — অভিভাবক যেন "অনেক টাকা দিয়ে ফেলেছি" অনুভব না করেন।
//    শুধু **কোর্স-প্রতি জমা ও বাকি**। ছাড়ও দেখানো হয় না (নিট প্রাপ্যই দেখানো হয়)।
require_once __DIR__ . '/includes/user-auth.php';
// পেমেন্ট খাতার শেয়ার্ড হেল্পার — বিশুদ্ধ ফাংশন, কোনো auth/সাইড-ইফেক্ট নেই, তাই পাবলিক পেজেও নিরাপদ
require_once __DIR__ . '/admin/includes/payments.php';

user_require_login();
$user = user_current();          // approved না হলে null + অটো-লগআউট
if (!$user) {
    redirect('account-login');
}
$phone = $user['phone'];

$db = get_db();

// ---------------- এই ইউজারের সব রেজিস্ট্রেশন (নিজের ফোন) ----------------
$stmt = $db->prepare(
    "SELECT r.id, r.type, r.item_id, r.item_title, r.batch, r.customer_name, r.quantity, r.status, r.created_at,
            cb.price, cb.fb_group_url, cb.messenger_group_url
     FROM registrations r
     LEFT JOIN course_batches cb ON cb.id = r.item_id AND r.type = 'course'
     WHERE r.phone = :p
     ORDER BY r.created_at DESC"
);
$stmt->execute(['p' => $phone]);
$allRegs = $stmt->fetchAll();

$courses = $others = [];
foreach ($allRegs as $r) {
    if ($r['type'] === 'course') { $courses[] = $r; } else { $others[] = $r; }
}
$regIds = array_map(fn($r) => (int) $r['id'], $allRegs);

// ---------------- কিস্তির খাতা (registration_payments) ----------------
// টেবিল না থাকলে pay_fetch_many() নিজেই খালি অ্যারে ফেরে — পেজ ভাঙে না
$ledgers = $regIds ? pay_fetch_many($db, $regIds) : [];

// ---------------- পাঠানো পার্সেল (শুধু sent — "যাবে না"/খসড়া অভিভাবককে দেখানো হয় না) ----------------
$parcels = [];
if ($regIds) {
    try {
        $in = implode(',', array_fill(0, count($regIds), '?'));
        $ps = $db->prepare(
            "SELECT registration_id, period_label, courier_consignment_id, tracking_url, updated_at, created_at
             FROM courier_batches WHERE registration_id IN ($in) AND send_status = 'sent'
             ORDER BY id"
        );
        $ps->execute($regIds);
        foreach ($ps->fetchAll() as $row) {
            $parcels[(int) $row['registration_id']][] = $row;
        }
    } catch (Throwable $e) {
        $parcels = [];
    }
}

// ---------------- নোটিশ (সর্বশেষ ৩টা) ----------------
$notices = [];
try {
    $notices = $db->query('SELECT title, content, notice_date FROM notices WHERE is_active = 1 ORDER BY notice_date DESC, id DESC LIMIT 3')->fetchAll();
} catch (Throwable $e) {
    $notices = [];
}

// ---------------- পুরনো (legacy) রেকর্ড — এই ফোন মিলিয়ে ----------------
$legacy = [];
try {
    $ls = $db->prepare("SELECT customer_name, course_title, batch, facebook_id FROM legacy_students WHERE RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = :k ORDER BY id DESC");
    $ls->execute(['k' => phone_last10($phone)]);
    $legacy = $ls->fetchAll();
} catch (Throwable $e) { $legacy = []; }

// ---------------- নিজের পাঠানো অনুরোধ/মন্তব্য (সর্বশেষ ৫টা) ----------------
$myRequests = [];
try {
    $rq = $db->prepare('SELECT kind, item_title, message, status, created_at FROM user_requests WHERE user_id = :u ORDER BY id DESC LIMIT 5');
    $rq->execute(['u' => (int) $user['id']]);
    $myRequests = $rq->fetchAll();
} catch (Throwable $e) { $myRequests = []; }   // মাইগ্রেশন চালানো হয়নি

// ---------------- প্রাইভেট গ্রুপ লিংক — কেনা কোর্স থেকে distinct ----------------
$groups = [];
foreach ($courses as $c) {
    if (!empty($c['fb_group_url']))        $groups['fb:' . $c['fb_group_url']] = ['type' => 'fb', 'url' => $c['fb_group_url'], 'title' => $c['item_title']];
    if (!empty($c['messenger_group_url'])) $groups['ms:' . $c['messenger_group_url']] = ['type' => 'ms', 'url' => $c['messenger_group_url'], 'title' => $c['item_title']];
}

$statusMeta = [
    'pending'   => ['অপেক্ষমাণ', 'bg-amber-100 text-amber-700'],
    'confirmed' => ['নিশ্চিত', 'bg-green-100 text-green-700'],
    'shipped'   => ['পাঠানো হয়েছে', 'bg-blue-100 text-blue-700'],
    'delivered' => ['ডেলিভারড', 'bg-green-100 text-green-700'],
    'cancelled' => ['বাতিল', 'bg-red-100 text-red-700'],
];

// কোর্স-প্রতি টাকার লাইন — 🔴 কোনো যোগফল নয়, শুধু এই কোর্সের জমা ও বাকি
function acc_money_line(array $summary): string
{
    if ($summary['status'] === 'none') {
        return '';
    }
    $out = '<span class="text-gray-500">জমা <span class="font-bold text-gray-700">' . number_format($summary['paid']) . ' ৳</span></span>';
    if ($summary['balance'] > 0) {
        $out .= ' <span class="text-gray-300">·</span> <span class="text-amber-700">বাকি <span class="font-bold">' . number_format($summary['balance']) . ' ৳</span></span>';
    } else {
        $out .= ' <span class="text-gray-300">·</span> <span class="text-green-700 font-semibold">✓ পরিশোধ সম্পন্ন</span>';
    }
    return $out;
}

$pageTitle = 'আমার অ্যাকাউন্ট';
$activePage = 'account';
require __DIR__ . '/includes/site-header.php';
?>
<div class="max-w-3xl mx-auto pb-10 space-y-6">
    <?= user_preview_banner() ?>
    <!-- হেডার -->
    <div class="flex items-center justify-between flex-wrap gap-3">
        <div>
            <h1 class="text-2xl font-black text-gray-900">স্বাগতম<?= $user['full_name'] ? ', ' . e($user['full_name']) : '' ?>!</h1>
            <p class="text-gray-500 text-sm">মোবাইল: <?= e($phone) ?></p>
        </div>
        <div class="flex gap-2">
            <a href="account-password" class="text-sm font-semibold text-indigo-600 bg-indigo-50 px-4 py-2 rounded-xl">পাসওয়ার্ড</a>
            <a href="account-logout" class="text-sm font-semibold text-red-600 bg-red-50 px-4 py-2 rounded-xl">লগআউট</a>
        </div>
    </div>

    <?php $flash = get_flash(); if ($flash): ?>
        <div class="p-4 rounded-xl text-sm <?= $flash['type'] === 'error' ? 'bg-red-50 text-red-700 border border-gray-200' : 'bg-green-50 text-green-700 border border-green-200' ?>"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <!-- প্রাইভেট গ্রুপ -->
    <?php if ($groups): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-3 flex items-center gap-2"><i data-lucide="users" class="w-5 h-5 text-violet-500"></i> প্রাইভেট গ্রুপ</h2>
        <div class="flex flex-col gap-2.5">
            <?php foreach ($groups as $g): ?>
                <a href="<?= e($g['url']) ?>" target="_blank" rel="noopener" class="flex items-center gap-3 p-3 rounded-xl <?= $g['type'] === 'fb' ? 'bg-blue-50 hover:bg-blue-100' : 'bg-sky-50 hover:bg-sky-100' ?>">
                    <span class="text-white text-xs font-bold px-2 py-1 rounded" style="background:<?= $g['type'] === 'fb' ? '#1877F2' : '#0084FF' ?>;"><?= $g['type'] === 'fb' ? 'Facebook' : 'Messenger' ?></span>
                    <span class="text-gray-700 text-sm font-semibold truncate"><?= e($g['title']) ?> গ্রুপে যোগ দিন →</span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- আমার কোর্স + কিস্তি + পার্সেল -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-4 flex items-center gap-2"><i data-lucide="book-open" class="w-5 h-5 text-indigo-500"></i> আমার কোর্স</h2>
        <?php if (!$courses): ?>
            <p class="text-gray-400 text-sm text-center py-6">এখনো কোনো কোর্স রেজিস্ট্রেশন নেই।</p>
        <?php else: ?>
        <div class="flex flex-col gap-3">
            <?php foreach ($courses as $c):
                $rows = $ledgers[(int) $c['id']] ?? [];
                $sum  = pay_summary($rows);
                [$sLabel, $sClass] = $statusMeta[$c['status']] ?? [$c['status'], 'bg-gray-100 text-gray-600'];
                $myParcels = $parcels[(int) $c['id']] ?? [];
            ?>
            <div class="border border-gray-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 break-words"><?= e($c['item_title']) ?></p>
                        <p class="text-gray-500 text-xs mt-0.5">
                            <?= $c['batch'] ? 'ব্যাচ: ' . e($c['batch']) . ' · ' : '' ?>শিশু: <?= e($c['customer_name']) ?>
                        </p>
                        <p class="text-gray-400 text-xs mt-0.5"><?= e(date('Y-m-d', strtotime($c['created_at']))) ?></p>
                    </div>
                    <span class="text-xs font-bold px-2.5 py-1 rounded-lg flex-shrink-0 <?= $sClass ?>"><?= e($sLabel) ?></span>
                </div>

                <?php // ── টাকার অবস্থা: শুধু এই কোর্সের জমা ও বাকি ── ?>
                <?php if ($sum['status'] !== 'none'): ?>
                <div class="mt-3 pt-4 border-t border-gray-100">
                    <p class="text-sm"><?= acc_money_line($sum) ?></p>
                    <details class="mt-2">
                        <summary class="text-xs font-semibold text-indigo-600 cursor-pointer">কিস্তির হিসাব দেখুন</summary>
                        <div class="mt-2 flex flex-col gap-1.5">
                            <?php foreach ($rows as $row):
                                // বাদ দেওয়া কিস্তি অভিভাবককে দেখানোর দরকার নেই — তবে ঐ কিস্তিতে টাকা
                                // নেওয়া থাকলে দেখাতেই হবে, নাহলে জমার যোগফল মিলবে না
                                if (pay_is_skipped($row) && (float) $row['amount_paid'] <= 0) { continue; }
                                $net  = pay_net($row);
                                $paid = (float) $row['amount_paid'];
                                $bal  = pay_row_outstanding($row);
                            ?>
                            <div class="flex items-center justify-between gap-2 text-xs bg-gray-50 rounded-lg px-3 py-2">
                                <span class="text-gray-700 font-semibold truncate"><?= e($row['label']) ?></span>
                                <span class="flex-shrink-0 text-gray-500">
                                    <?= number_format($paid) ?>/<?= number_format($net) ?> ৳
                                    <?= $bal > 0
                                        ? '<span class="text-amber-700 font-semibold">· বাকি ' . number_format($bal) . '</span>'
                                        : '<span class="text-green-600 font-bold">✓</span>' ?>
                                </span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </details>
                </div>
                <?php endif; ?>

                <?php // ── পাঠানো পার্সেল ── ?>
                <?php if ($myParcels): ?>
                <div class="mt-3 pt-4 border-t border-gray-100">
                    <p class="text-xs font-semibold text-gray-500 mb-1.5">📦 পাঠানো হয়েছে</p>
                    <div class="flex flex-wrap gap-1.5">
                        <?php foreach ($myParcels as $pc): ?>
                            <?php if (!empty($pc['tracking_url'])): ?>
                                <a href="<?= e($pc['tracking_url']) ?>" target="_blank" rel="noopener" class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700"><?= e($pc['period_label'] ?: 'পার্সেল') ?> · ট্র্যাক করুন →</a>
                            <?php else: ?>
                                <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-green-50 text-green-700">✓ <?= e($pc['period_label'] ?: 'পার্সেল') ?></span>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- অন্যান্য অর্ডার (ওয়ার্কশিট / প্রোডাক্ট) -->
    <?php if ($others): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-4 flex items-center gap-2"><i data-lucide="shopping-bag" class="w-5 h-5 text-green-600"></i> আমার অন্যান্য অর্ডার</h2>
        <div class="flex flex-col gap-3">
            <?php foreach ($others as $o):
                [$sLabel, $sClass] = $statusMeta[$o['status']] ?? [$o['status'], 'bg-gray-100 text-gray-600'];
                $oSum = pay_summary($ledgers[(int) $o['id']] ?? []);
                $oParcels = $parcels[(int) $o['id']] ?? [];
            ?>
            <div class="border border-gray-200 rounded-xl p-4">
                <div class="flex items-start justify-between gap-3 flex-wrap">
                    <div class="min-w-0">
                        <p class="font-bold text-gray-900 break-words"><?= e($o['item_title']) ?></p>
                        <p class="text-gray-500 text-xs mt-0.5">
                            <?= $o['type'] === 'worksheet' ? 'ওয়ার্কশিট' : 'প্রোডাক্ট' ?><?= (int) $o['quantity'] > 1 ? ' · পরিমাণ ' . (int) $o['quantity'] : '' ?>
                            · <?= e(date('Y-m-d', strtotime($o['created_at']))) ?>
                        </p>
                        <?php if ($oSum['status'] !== 'none'): ?><p class="text-xs mt-1"><?= acc_money_line($oSum) ?></p><?php endif; ?>
                    </div>
                    <span class="text-xs font-bold px-2.5 py-1 rounded-lg flex-shrink-0 <?= $sClass ?>"><?= e($sLabel) ?></span>
                </div>
                <?php if ($oParcels): ?>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <?php foreach ($oParcels as $pc): ?>
                        <?php if (!empty($pc['tracking_url'])): ?>
                            <a href="<?= e($pc['tracking_url']) ?>" target="_blank" rel="noopener" class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-blue-50 text-blue-700">📦 ট্র্যাক করুন →</a>
                        <?php else: ?>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-lg bg-green-50 text-green-700">📦 পাঠানো হয়েছে</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- নোটিশ -->
    <?php if ($notices): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold text-gray-900 flex items-center gap-2"><i data-lucide="bell" class="w-5 h-5 text-amber-500"></i> নোটিশ</h2>
            <a href="notice" class="text-xs font-semibold text-indigo-600">সব নোটিশ →</a>
        </div>
        <div class="flex flex-col gap-2.5">
            <?php foreach ($notices as $n): ?>
            <div class="border border-amber-200 bg-amber-50 rounded-xl p-3">
                <p class="font-bold text-gray-900 text-sm break-words"><?= e($n['title']) ?></p>
                <?php if (!empty($n['content'])): ?><p class="text-gray-600 text-xs mt-1 break-words"><?= e(mb_strimwidth((string) $n['content'], 0, 160, '…')) ?></p><?php endif; ?>
                <p class="text-gray-400 text-xs mt-1"><?= e($n['notice_date']) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- তথ্য সংশোধনের অনুরোধ / মন্তব্য -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-1 flex items-center gap-2"><i data-lucide="message-square" class="w-5 h-5 text-indigo-500"></i> কিছু জানাতে চান?</h2>
        <p class="text-gray-400 text-xs mb-4">কোনো তথ্য ভুল থাকলে সংশোধনের অনুরোধ করুন, অথবা যেকোনো মন্তব্য/প্রশ্ন লিখুন — আমরা দেখে ব্যবস্থা নেব।</p>
        <form method="post" action="account-request-submit.php" class="space-y-3">
            <?= csrf_field() ?>
            <?= spam_protection_fields() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-gray-600 text-xs font-semibold mb-1">কী বিষয়ে?</label>
                    <select name="kind" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" style="color:#111827;background:#ffffff">
                        <option value="correction">তথ্য ভুল আছে — সংশোধনের অনুরোধ</option>
                        <option value="remark">মন্তব্য / প্রশ্ন</option>
                    </select>
                </div>
                <div>
                    <label class="block text-gray-600 text-xs font-semibold mb-1">কোন কোর্স/অর্ডার? <span class="text-gray-400">(ঐচ্ছিক)</span></label>
                    <select name="registration_id" class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm" style="color:#111827;background:#ffffff">
                        <option value="">— নির্দিষ্ট কিছু নয় —</option>
                        <?php foreach ($allRegs as $r): ?>
                            <option value="<?= (int) $r['id'] ?>"><?= e($r['item_title']) ?><?= $r['batch'] ? ' (' . e($r['batch']) . ')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="block text-gray-600 text-xs font-semibold mb-1">আপনার বার্তা</label>
                <textarea name="message" rows="3" required maxlength="1000" placeholder="যেমন: শিশুর নামের বানান ভুল আছে — সঠিক বানান ..." class="w-full px-3 py-2.5 rounded-xl border border-gray-200 text-sm"></textarea>
            </div>
            <button type="submit" class="px-5 py-2.5 rounded-xl font-bold text-sm text-white" style="background: linear-gradient(135deg, rgb(var(--c-primary-2)), rgb(var(--c-primary)));">পাঠান</button>
        </form>

        <?php if ($myRequests): ?>
        <div class="mt-5 pt-4 border-t border-gray-100">
            <p class="text-xs font-semibold text-gray-500 mb-2">আপনার আগের বার্তা</p>
            <div class="flex flex-col gap-2">
                <?php foreach ($myRequests as $q): ?>
                <div class="bg-gray-50 rounded-xl px-3 py-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-xs font-semibold text-gray-600"><?= $q['kind'] === 'correction' ? 'সংশোধনের অনুরোধ' : 'মন্তব্য' ?><?= $q['item_title'] ? ' · ' . e($q['item_title']) : '' ?></span>
                        <span class="text-xs font-bold px-2 py-0.5 rounded-lg flex-shrink-0 <?= $q['status'] === 'done' ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' ?>"><?= $q['status'] === 'done' ? '✓ দেখা হয়েছে' : 'অপেক্ষমাণ' ?></span>
                    </div>
                    <p class="text-gray-600 text-xs mt-1 break-words"><?= e($q['message']) ?></p>
                    <p class="text-gray-400 text-xs mt-0.5"><?= e(date('Y-m-d', strtotime($q['created_at']))) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- আরেকটা কোর্সে আগ্রহ -->
    <div class="rounded-2xl p-5 border border-green-200 bg-green-50 flex items-center justify-between gap-3 flex-wrap">
        <div>
            <p class="font-bold text-gray-900">আরেকটা কোর্সে আগ্রহী?</p>
            <p class="text-gray-600 text-xs mt-0.5">এখন সময় না হলেও জানিয়ে রাখুন — নতুন ব্যাচ খুললে আমরাই জানাব।</p>
        </div>
        <a href="course-interest" class="text-sm font-bold text-green-700 bg-white px-4 py-2.5 rounded-xl border border-green-200">আগ্রহ জানান →</a>
    </div>

    <?php // পুরনো তথ্য (legacy) — এই ফোনে আগের কোনো রেকর্ড থাকলে ?>
    <?php if ($legacy): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-900 mb-1 flex items-center gap-2"><i data-lucide="history" class="w-5 h-5 text-amber-500"></i> আপনার পুরনো তথ্য</h2>
        <p class="text-gray-400 text-xs mb-4">আমাদের আগের রেকর্ড অনুযায়ী (এই মোবাইল নম্বরে)</p>
        <div class="flex flex-col gap-3">
            <?php foreach ($legacy as $lg): ?>
            <div class="border border-amber-200 bg-amber-50 rounded-xl p-4">
                <p class="font-bold text-gray-900 break-words"><?= e($lg['customer_name'] ?: '—') ?></p>
                <p class="text-gray-600 text-xs mt-0.5">
                    <?= $lg['course_title'] ? 'কোর্স: ' . e($lg['course_title']) : '' ?><?= $lg['batch'] ? ' · ব্যাচ/সাল: ' . e($lg['batch']) : '' ?>
                </p>
                <?php if ($lg['facebook_id']): ?><p class="text-gray-400 text-xs mt-0.5">ফেসবুক: <?= e($lg['facebook_id']) ?></p><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
