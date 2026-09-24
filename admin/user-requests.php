<?php
// অভিভাবকের অনুরোধ / মন্তব্য — account.php-এর ফর্ম থেকে আসা বার্তা (২০২৬-০৯-২৪)
// তথ্য সংশোধনের অনুরোধ (correction) ও সাধারণ মন্তব্য (remark) দুটোই এখানে।
// 🔴 এখান থেকে কোনো ডেটা **অটো বদলায় না** — অ্যাডমিন পড়ে নিজে রেজিস্ট্রেশনে গিয়ে ঠিক করেন
//    (অভিভাবকের লেখা সরাসরি অর্ডারে বসালে কুরিয়ারের ঠিকানা/নাম ভুল হয়ে যেতে পারত)।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'অনুরোধ / মন্তব্য';

$activeFilters = array_filter([
    'status' => trim($_GET['status'] ?? ''),
    'kind'   => trim($_GET['kind'] ?? ''),
], fn($v) => $v !== '');

function ureq_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'user-requests.php' . ($p ? '?' . http_build_query($p) : '');
}
function ureq_safe_return(): string
{
    $r = $_POST['return'] ?? '';
    return (is_string($r) && str_starts_with($r, 'user-requests.php')) ? $r : 'user-requests.php';
}

$kindLabels = ['correction' => '✏️ তথ্য সংশোধন', 'remark' => '💬 মন্তব্য'];

// ---------------- POST: দেখা হয়েছে / আবার নতুন ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'mark') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('user-requests.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    $new = ($_POST['status'] ?? '') === 'done' ? 'done' : 'new';
    $db->prepare('UPDATE user_requests SET status = :s, handled_at = ' . ($new === 'done' ? 'NOW()' : 'NULL') . ' WHERE id = :id')
       ->execute(['s' => $new, 'id' => $id]);
    set_flash('success', $new === 'done' ? 'দেখা হয়েছে হিসেবে চিহ্নিত।' : 'আবার নতুন হিসেবে চিহ্নিত।');
    redirect(ureq_safe_return());
}

// ---------------- POST: ডিলিট ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('user-requests.php'); }
    $db->prepare('DELETE FROM user_requests WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
    set_flash('success', 'বার্তাটি মুছে ফেলা হয়েছে।');
    redirect(ureq_safe_return());
}

// ---------------- তালিকা ----------------
// ⚠️ টেবিল না থাকলে (migrate-user-account-v2.sql চালানো হয়নি) পেজ ভাঙবে না — হলুদ ইঙ্গিত দেখাবে
$tableReady = true;
$rows = [];
$countNew = $countAll = 0;
try {
    $where = [];
    $params = [];
    if (!empty($activeFilters['status'])) { $where[] = 'r.status = :st'; $params['st'] = $activeFilters['status']; }
    if (!empty($activeFilters['kind']))   { $where[] = 'r.kind = :kd';   $params['kd'] = $activeFilters['kind']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $stmt = $db->prepare(
        "SELECT r.*, u.full_name, u.status AS user_status
         FROM user_requests r LEFT JOIN users u ON u.id = r.user_id
         $whereSql ORDER BY r.status = 'done', r.id DESC LIMIT 200"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $countAll = (int) $db->query('SELECT COUNT(*) c FROM user_requests')->fetch()['c'];
    $countNew = (int) $db->query("SELECT COUNT(*) c FROM user_requests WHERE status = 'new'")->fetch()['c'];
} catch (PDOException $ex) {
    $tableReady = false;
}

$curReturn = ureq_url();
require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$tableReady): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-user-account-v2.sql</code> ফাইলের কোডটুকু চালান — তার আগ পর্যন্ত অভিভাবকেরা বার্তা পাঠাতে পারবেন না।</p>
</div>
<?php else: ?>

<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    <a href="<?= e(ureq_url(['status' => 'new'])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-amber-500"><?= $countNew ?></div>
        <p class="text-gray-500 text-sm mt-1">নতুন (দেখা হয়নি)</p>
    </a>
    <a href="<?= e(ureq_url(['status' => null])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-indigo-600"><?= $countAll ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট বার্তা</p>
    </a>
</div>

<!-- ফিল্টার (সার্ভার-সাইড; id গার্ড লিস্টে আছে বলে অটো-সার্চ বক্স বসে না) -->
<form method="get" id="ureqFilterForm" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">অবস্থা</label>
        <select name="status" onchange="document.getElementById('ureqFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <option value="new" <?= ($activeFilters['status'] ?? '') === 'new' ? 'selected' : '' ?>>নতুন</option>
            <option value="done" <?= ($activeFilters['status'] ?? '') === 'done' ? 'selected' : '' ?>>দেখা হয়েছে</option>
        </select>
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">ধরন</label>
        <select name="kind" onchange="document.getElementById('ureqFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <?php foreach ($kindLabels as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= ($activeFilters['kind'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($activeFilters): ?>
        <a href="user-requests.php" class="text-sm font-semibold text-gray-500 py-2">✕ ফিল্টার মুছুন</a>
    <?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কে</th>
                <th class="py-3 px-4">ধরন</th>
                <th class="py-3 px-4">কোন কোর্স/অর্ডার</th>
                <th class="py-3 px-4">বার্তা</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="py-6 px-4 text-center text-gray-400">কোনো বার্তা নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): $isNew = $row['status'] === 'new'; ?>
            <tr class="border-b last:border-0 <?= $isNew ? '' : 'opacity-60' ?>">
                <td class="py-2.5 px-4">
                    <p class="font-semibold text-gray-800"><?= e($row['full_name'] ?: '—') ?></p>
                    <a href="tel:<?= e($row['phone']) ?>" class="text-indigo-600 text-xs"><?= e($row['phone']) ?></a>
                </td>
                <td class="py-2.5 px-4 whitespace-nowrap">
                    <span class="text-xs font-semibold px-2 py-0.5 rounded-lg <?= $row['kind'] === 'correction' ? 'bg-amber-100 text-amber-700' : 'bg-blue-100 text-blue-700' ?>"><?= e($kindLabels[$row['kind']] ?? $row['kind']) ?></span>
                </td>
                <td class="py-2.5 px-4 text-gray-600"><?= e($row['item_title'] ?: '—') ?></td>
                <td class="py-2.5 px-4 text-gray-700 break-words" style="max-width:320px;"><?= nl2br(e($row['message'])) ?></td>
                <td class="py-2.5 px-4 text-gray-500 text-xs whitespace-nowrap"><?= e(date('d M, H:i', strtotime($row['created_at']))) ?></td>
                <td class="py-2.5 px-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <form method="post" action="<?= e(ureq_url(['action' => 'mark'])) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="status" value="<?= $isNew ? 'done' : 'new' ?>">
                            <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $isNew ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>"><?= $isNew ? '✓ দেখা হয়েছে' : '↩ আবার নতুন' ?></button>
                        </form>
                        <?php if ($row['registration_id']): ?>
                            <a href="registrations.php?action=view&id=<?= (int) $row['registration_id'] ?>" class="text-xs font-semibold text-indigo-600">অর্ডার →</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(ureq_url(['action' => 'delete'])) ?>" class="inline" onsubmit="return confirmSubmit(this, 'এই বার্তাটি মুছে ফেলবেন? এটি আর ফেরানো যাবে না।');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                            <button type="submit" class="text-xs font-semibold text-red-600">ডিলিট</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
