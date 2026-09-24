<?php
// আগ্রহ তালিকা (ওয়েটিং লিস্ট) — অভিভাবকরা যে আগ্রহ জানিয়ে রাখেন (public course-interest.php)
// তা এখানে দেখা যায়। কোর্স/স্ট্যাটাস/কারণ/কবে ফিল্টার, "যোগাযোগ হয়েছে" মার্ক, ডিলিট।
// ⚠️ ২০২৬-০৯-২৪ থেকে চলমান কোর্সের আগ্রহও এখানে আসে (আগে শুধু বন্ধ কোর্সের আসত)।
// 🔑 মূল ব্যবহার: নতুন ব্যাচ খোলার সময় "কোর্স + কারণ=বয়স কম" ফিল্টার করে বয়স-কলাম দেখে
//    ঠিক যাদের বয়স হয়ে গেছে তাঁদের কল-লিস্ট বের করা।

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'আগ্রহ তালিকা';

// ---------------- সক্রিয় ফিল্টার (খালি বাদ) ----------------
$activeFilters = array_filter([
    'status' => trim($_GET['status'] ?? ''),
    'item'   => trim($_GET['item'] ?? ''),
    'reason' => trim($_GET['reason'] ?? ''),
    'when'   => trim($_GET['when'] ?? ''),
], fn($v) => $v !== '');

function ci_url(array $overrides = []): string
{
    global $activeFilters;
    $params = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'course-interests.php' . ($params ? '?' . http_build_query($params) : '');
}

// return_url নিরাপদ করা — শুধু নিজের পেজেই ফেরত
function ci_safe_return(): string
{
    $r = $_POST['return'] ?? '';
    return (is_string($r) && str_starts_with($r, 'course-interests.php')) ? $r : 'course-interests.php';
}

// ---------------- POST: স্ট্যাটাস টগল ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'mark') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('course-interests.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $newStatus = ($_POST['status'] ?? '') === 'contacted' ? 'contacted' : 'new';
    $db->prepare('UPDATE course_interests SET status = :s WHERE id = :id')->execute(['s' => $newStatus, 'id' => $id]);
    set_flash('success', $newStatus === 'contacted' ? 'যোগাযোগ হয়েছে হিসেবে মার্ক করা হলো।' : 'আবার "নতুন" হিসেবে মার্ক করা হলো।');
    redirect(ci_safe_return());
}

// ---------------- POST: ডিলিট ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('course-interests.php');
    }
    $db->prepare('DELETE FROM course_interests WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
    set_flash('success', 'আগ্রহ এন্ট্রি ডিলিট করা হয়েছে।');
    redirect(ci_safe_return());
}

// ---------------- পরিসংখ্যান ----------------
$total     = (int) $db->query('SELECT COUNT(*) c FROM course_interests')->fetch()['c'];
$newCount  = (int) $db->query("SELECT COUNT(*) c FROM course_interests WHERE status = 'new'")->fetch()['c'];
$contacted = $total - $newCount;

// ⚠️ migrate-course-interest-fields.sql চালানো হয়েছে কিনা — একবার দেখে নেওয়া।
// না চালালে নতুন কলামে ফিল্টার করলে কোয়েরি ক্র্যাশ করত; তাই তখন ঐ ফিল্টার/কলাম দেখানোই হয় না,
// বদলে উপরে একটা হলুদ ইঙ্গিত দেখায় (ইউজার যেন বুঝতে পারেন SQL-টা চালাতে হবে)।
$hasNewCols = true;
try {
    $db->query('SELECT reason, start_when, child_dob FROM course_interests LIMIT 1');
} catch (PDOException $ex) {
    $hasNewCols = false;
}

// একই নাম্বার থেকে একাধিকবার আগ্রহ — তালিকায় ছোট ব্যাজ দেখানোর জন্য (মুছে দেওয়া হয় না,
// প্রতিটা আগ্রহের মন্তব্য/কারণ আলাদা হতে পারে)
$dupCounts = [];
foreach ($db->query("SELECT contact_phone, COUNT(*) c FROM course_interests GROUP BY contact_phone HAVING c > 1")->fetchAll() as $d) {
    $dupCounts[$d['contact_phone']] = (int) $d['c'];
}

// কোর্স ড্রপডাউন — distinct item_title (স্ন্যাপশট, কোর্স ডিলিট হলেও অক্ষত)
$itemOptions = $db->query("SELECT DISTINCT item_title FROM course_interests WHERE item_title IS NOT NULL AND item_title <> '' ORDER BY item_title")->fetchAll(PDO::FETCH_COLUMN);

// ---------------- তালিকা কোয়েরি ----------------
$where = [];
$params = [];
if (!empty($activeFilters['status'])) {
    $where[] = 'status = :status';
    $params['status'] = $activeFilters['status'] === 'contacted' ? 'contacted' : 'new';
}
if (!empty($activeFilters['item'])) {
    $where[] = 'item_title = :item';
    $params['item'] = $activeFilters['item'];
}
if ($hasNewCols && !empty($activeFilters['reason']) && isset(interest_reasons()[$activeFilters['reason']])) {
    $where[] = 'reason = :reason';                 // 🔴 হোয়াইটলিস্ট করা key-ই কেবল
    $params['reason'] = $activeFilters['reason'];
}
if ($hasNewCols && !empty($activeFilters['when']) && isset(interest_timeframes()[$activeFilters['when']])) {
    $where[] = 'start_when = :when';
    $params['when'] = $activeFilters['when'];
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
$stmt = $db->prepare("SELECT * FROM course_interests $whereSql ORDER BY created_at DESC");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$curReturn = ci_url();

require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$hasNewCols): ?>
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 mb-5 text-sm text-amber-800">
    <strong>⚠️ একটা SQL চালানো বাকি।</strong> "শিশুর বয়স / কেন পারছেন না / কবে শুরু করতে চান" — এই তিনটা তথ্য
    এখনো সেভ হচ্ছে না। phpMyAdmin-এ <code class="bg-amber-100 px-1 rounded">database/migrate-course-interest-fields.sql</code>
    চালালেই কলামগুলো দেখা যাবে। ততক্ষণ পর্যন্ত বাকি সব আগের মতোই কাজ করছে — কোনো আগ্রহ হারাচ্ছে না।
</div>
<?php endif; ?>

<div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5">
        <div class="text-2xl sm:text-3xl font-black text-indigo-600"><?= $total ?></div>
        <p class="text-gray-500 text-xs sm:text-sm mt-1">মোট আগ্রহ</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5">
        <div class="text-2xl sm:text-3xl font-black text-amber-600"><?= $newCount ?></div>
        <p class="text-gray-500 text-xs sm:text-sm mt-1">নতুন (যোগাযোগ বাকি)</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5">
        <div class="text-2xl sm:text-3xl font-black text-green-600"><?= $contacted ?></div>
        <p class="text-gray-500 text-xs sm:text-sm mt-1">যোগাযোগ হয়েছে</p>
    </div>
</div>

<!-- ফিল্টার -->
<form method="get" id="ciFilterForm" class="bg-white rounded-2xl shadow p-4 mb-5 flex flex-wrap items-center gap-3">
    <div class="flex flex-wrap gap-2">
        <?php
        $statusPills = ['' => 'সব', 'new' => 'নতুন', 'contacted' => 'যোগাযোগ হয়েছে'];
        $curStatus = $activeFilters['status'] ?? '';
        foreach ($statusPills as $sv => $sl):
            $on = $curStatus === $sv;
        ?>
            <a href="<?= e(ci_url(['status' => $sv ?: null])) ?>" class="px-4 py-2 rounded-full text-sm font-semibold <?= $on ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= e($sl) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="ml-auto flex flex-wrap gap-2">
        <select name="item" onchange="document.getElementById('ciFilterForm').submit()" class="border rounded-xl px-3 py-2 text-sm">
            <option value="">— সব কোর্স —</option>
            <?php foreach ($itemOptions as $it): ?>
                <option value="<?= e($it) ?>" <?= ($activeFilters['item'] ?? '') === $it ? 'selected' : '' ?>><?= e($it) ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($hasNewCols): ?>
        <select name="reason" onchange="document.getElementById('ciFilterForm').submit()" class="border rounded-xl px-3 py-2 text-sm">
            <option value="">— সব কারণ —</option>
            <?php foreach (interest_reasons() as $rk => $rl): ?>
                <option value="<?= e($rk) ?>" <?= ($activeFilters['reason'] ?? '') === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="when" onchange="document.getElementById('ciFilterForm').submit()" class="border rounded-xl px-3 py-2 text-sm">
            <option value="">— কবে শুরু (সব) —</option>
            <?php foreach (interest_timeframes() as $tk => $tl): ?>
                <option value="<?= e($tk) ?>" <?= ($activeFilters['when'] ?? '') === $tk ? 'selected' : '' ?>><?= e($tl) ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>
    </div>
    <?php if (!empty($activeFilters['status'])): ?><input type="hidden" name="status" value="<?= e($activeFilters['status']) ?>"><?php endif; ?>
    <?php if (!empty($activeFilters)): ?>
        <a href="course-interests.php" class="text-sm font-semibold text-gray-500 hover:text-gray-700">✕ ফিল্টার মুছুন</a>
    <?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিশুর নাম</th>
                <th class="py-3 px-4">যোগাযোগ নাম্বার</th>
                <th class="py-3 px-4">ফেসবুক নাম</th>
                <th class="py-3 px-4">কোর্স</th>
                <th class="py-3 px-4">কেন / কবে</th>
                <th class="py-3 px-4">মন্তব্য</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">স্ট্যাটাস</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="py-8 px-4 text-center text-gray-400">এই ফিল্টারে কোনো আগ্রহ এন্ট্রি নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $isNew = $r['status'] !== 'contacted';
            $ownerLabel = $r['phone_owner'] === 'father' ? 'বাবা' : 'মা';
            $courseLabel = $r['item_title'] . ($r['batch_name'] ? ' — ' . $r['batch_name'] : '');
            $ageLabel    = interest_age_label($r['child_dob'] ?? null);   // জন্ম তারিখ থেকে অটো
            $reasonLabel = interest_reasons()[$r['reason'] ?? ''] ?? '';
            $whenLabel   = interest_timeframes()[$r['start_when'] ?? ''] ?? '';
            $dupCount    = $dupCounts[$r['contact_phone']] ?? 0;
        ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $isNew ? '' : 'opacity-70' ?>">
                <td class="py-2.5 px-4">
                    <div class="font-semibold text-gray-900"><?= e($r['child_name'] ?: '-') ?></div>
                    <?php if ($ageLabel !== ''): ?>
                        <div class="text-[11px] text-gray-500" title="জন্ম তারিখ: <?= e((string) $r['child_dob']) ?>">🎂 <?= e($ageLabel) ?></div>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 whitespace-nowrap">
                    <span class="font-mono"><?= e($r['contact_phone']) ?></span>
                    <span class="ml-1 text-xs px-1.5 py-0.5 rounded <?= $r['phone_owner'] === 'father' ? 'bg-blue-100 text-blue-700' : 'bg-pink-100 text-pink-700' ?>"><?= e($ownerLabel) ?></span>
                    <?php if ($dupCount > 1): ?>
                        <span class="ml-1 text-xs px-1.5 py-0.5 rounded bg-purple-100 text-indigo-700" title="এই নাম্বার থেকে মোট <?= $dupCount ?> বার আগ্রহ জানানো হয়েছে">🔁 <?= $dupCount ?></span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 text-gray-600"><?= e($r['facebook_name'] ?: '-') ?></td>
                <td class="py-2.5 px-4 text-gray-700"><?= e($courseLabel ?: '-') ?></td>
                <td class="py-2.5 px-4">
                    <?php if ($reasonLabel === '' && $whenLabel === ''): ?>
                        <span class="text-gray-300 text-xs">—</span>
                    <?php else: ?>
                        <?php if ($reasonLabel !== ''): ?>
                            <span class="inline-block text-xs px-2 py-0.5 rounded-lg bg-orange-100 text-orange-700"><?= e($reasonLabel) ?></span>
                        <?php endif; ?>
                        <?php if ($whenLabel !== ''): ?>
                            <span class="inline-block text-xs px-2 py-0.5 rounded-lg bg-blue-100 text-blue-700 mt-1"><?= e($whenLabel) ?></span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 text-gray-500 max-w-[220px] truncate" title="<?= e($r['remarks'] ?? '') ?>"><?= e($r['remarks'] ?: '-') ?></td>
                <td class="py-2.5 px-4 whitespace-nowrap text-gray-500 text-xs"><?= e(date('Y-m-d', strtotime($r['created_at']))) ?></td>
                <td class="py-2.5 px-4">
                    <?php if ($isNew): ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-lg bg-amber-100 text-amber-700">নতুন</span>
                    <?php else: ?>
                        <span class="text-xs font-bold px-2.5 py-1 rounded-lg bg-green-100 text-green-700">যোগাযোগ হয়েছে</span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                    <form method="post" action="course-interests.php?action=mark" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="status" value="<?= $isNew ? 'contacted' : 'new' ?>">
                        <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                        <button type="submit" class="<?= $isNew ? 'text-green-600' : 'text-amber-600' ?> font-semibold"><?= $isNew ? '✓ যোগাযোগ হয়েছে' : '↩ আবার নতুন' ?></button>
                    </form>
                    <form method="post" action="course-interests.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, 'এই আগ্রহ এন্ট্রিটি ডিলিট করতে চান?', 'ডিলিট নিশ্চিতকরণ');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                        <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
