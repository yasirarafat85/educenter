<?php
// কার্যকলাপ লগ — কে কী করল (২০২৬-০৯-২৪)
// লেখা হয় কেন্দ্রীয় গার্ড থেকে (admin/includes/activity.php দ্রষ্টব্য) — প্রতিটা POST।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কার্যকলাপ লগ';

$activeFilters = array_filter([
    'admin'  => trim($_GET['admin'] ?? ''),
    'act'    => trim($_GET['act'] ?? ''),
    'page_f' => trim($_GET['page_f'] ?? ''),
    'page'   => (int) ($_GET['page'] ?? 0) > 1 ? (int) $_GET['page'] : '',
], fn($v) => $v !== '' && $v !== 0);

function al_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'activity-log.php' . ($p ? '?' . http_build_query($p) : '');
}

// পেজ ফাইলের বাংলা নাম (সাইডবারের নাম থেকেই — একই শব্দ দুই জায়গায় থাকে)
$pageLabels = [];
foreach (admin_nav_groups() as $grp) {
    foreach ($grp['items'] ?? [] as $it) {
        if (!empty($it['file'])) { $pageLabels[$it['file']] = $it['label']; }
    }
}
$pageLabels += ['manage.php' => 'কনটেন্ট', 'course-batches.php' => 'কোর্স ব্যাচ', 'send-to-courier.php' => 'কুরিয়ারে পাঠানো',
                'bulk-courier-action.php' => 'কুরিয়ার (বাল্ক)', 'courier-note-assign.php' => 'কুরিয়ার নোট', 'settings.php' => 'সাইট সেটিংস'];
$actLabels = admin_activity_action_labels();

$tableReady = true;
$rows = [];
$total = $todayCount = 0;
$admins = [];
$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
try {
    $where = [];
    $params = [];
    if (!empty($activeFilters['admin']))  { $where[] = 'username = :un';  $params['un'] = $activeFilters['admin']; }
    if (!empty($activeFilters['act']))    { $where[] = 'action = :act';   $params['act'] = $activeFilters['act']; }
    if (!empty($activeFilters['page_f'])) { $where[] = 'page = :pg';      $params['pg'] = $activeFilters['page_f']; }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $cs = $db->prepare("SELECT COUNT(*) c FROM admin_activity_log $whereSql");
    $cs->execute($params);
    $total = (int) $cs->fetch()['c'];

    $stmt = $db->prepare("SELECT * FROM admin_activity_log $whereSql ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $todayCount = (int) $db->query('SELECT COUNT(*) c FROM admin_activity_log WHERE DATE(created_at) = CURDATE()')->fetch()['c'];
    $admins = $db->query('SELECT DISTINCT username FROM admin_activity_log WHERE username <> "" ORDER BY username')->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ex) {
    $tableReady = false;
}
$totalPages = (int) ceil(max(1, $total) / $perPage);
require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$tableReady): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-admin-visibility.sql</code> চালান — তার আগ পর্যন্ত কার্যকলাপ জমা হবে না।</p>
</div>
<?php else: ?>

<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-indigo-600"><?= $todayCount ?></div>
        <p class="text-gray-500 text-sm mt-1">আজকের কাজ</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-gray-700"><?= $total ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট (ফিল্টার অনুযায়ী)</p>
    </div>
</div>

<form method="get" id="alFilterForm" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">কে</label>
        <select name="admin" onchange="document.getElementById('alFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সবাই</option>
            <?php foreach ($admins as $un): ?>
                <option value="<?= e($un) ?>" <?= ($activeFilters['admin'] ?? '') === $un ? 'selected' : '' ?>><?= e($un) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">কী ধরনের কাজ</label>
        <select name="act" onchange="document.getElementById('alFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <?php foreach (['delete' => '🗑 ডিলিট', 'pay-save' => '💰 খাতা সেভ', 'status' => 'স্ট্যাটাস বদল', 'setstatus' => 'স্ট্যাটাস বদল (অভিভাবক)'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= ($activeFilters['act'] ?? '') === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if ($activeFilters): ?><a href="activity-log.php" class="text-sm font-semibold text-gray-500 py-2">✕ ফিল্টার মুছুন</a><?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কে</th>
                <th class="py-3 px-4">কী করল</th>
                <th class="py-3 px-4">কোথায়</th>
                <th class="py-3 px-4">কোনটায়</th>
                <th class="py-3 px-4">কখন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-6 px-4 text-center text-gray-400">এখনো কোনো কাজ রেকর্ড হয়নি।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $isDelete = in_array($row['action'], admin_delete_actions(), true);
        ?>
            <tr class="border-b last:border-0">
                <td class="py-2.5 px-4">
                    <span class="font-semibold text-gray-800"><?= e($row['username'] ?: '—') ?></span>
                    <?php if ($row['role'] === 'moderator'): ?><span class="text-xs text-gray-400">(মডারেটর)</span><?php endif; ?>
                </td>
                <td class="py-2.5 px-4">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-lg <?= $isDelete ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-600' ?>"><?= e($actLabels[$row['action']] ?? $row['action']) ?></span>
                </td>
                <td class="py-2.5 px-4 text-gray-600"><?= e($pageLabels[$row['page']] ?? $row['page']) ?></td>
                <td class="py-2.5 px-4 text-gray-500 text-xs">
                    <?= $row['target_id'] ? '#' . (int) $row['target_id'] : '' ?>
                    <?= $row['context'] ? '<span class="text-gray-400">' . e($row['context']) . '</span>' : '' ?>
                </td>
                <td class="py-2.5 px-4 text-gray-500 text-xs whitespace-nowrap" title="<?= e($row['created_at'] . ' · ' . $row['ip_address']) ?>"><?= e(date('d M, H:i', strtotime($row['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex flex-wrap gap-2 mt-4">
    <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
        <a href="<?= e(al_url(['page' => $i > 1 ? $i : null])) ?>" class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $i === $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<p class="text-gray-400 text-xs mt-4">
    প্রতিটা <b>পরিবর্তন</b> (সেভ/ডিলিট/স্ট্যাটাস বদল) এখানে জমা হয় — শুধু পেজ দেখা/খোঁজা জমা হয় না।
    সর্বশেষ <?= (int) ADMIN_ACTIVITY_KEEP_DAYS ?> দিনের রেকর্ড রাখা হয়।
</p>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
