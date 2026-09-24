<?php
// লগইন লগ — কে ঢুকেছে, কে পারেনি (২০২৬-০৯-২৪)
// login_attempts টেবিল আগে থেকেই প্রতিটা চেষ্টা লিখত, কিন্তু ২৪ ঘণ্টা পর মুছে যেত ও কোথাও
// দেখা যেত না। এখন ৯০ দিন থাকে (ADMIN_LOGIN_LOG_KEEP_DAYS) এবং এই পেজে দেখা যায়।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'লগইন লগ';

$activeFilters = array_filter([
    'result' => trim($_GET['result'] ?? ''),
    'q'      => trim($_GET['q'] ?? ''),
    'page'   => (int) ($_GET['page'] ?? 0) > 1 ? (int) $_GET['page'] : '',
], fn($v) => $v !== '' && $v !== 0);

function ll_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'login-logs.php' . ($p ? '?' . http_build_query($p) : '');
}

// ⚠️ মাইগ্রেশন চালানো না থাকলে method/admin_id কলাম নেই — তখনও পেজ চলবে (কলামটুকু বাদ যাবে)
$hasMethod = true;
try {
    $db->query('SELECT method, admin_id FROM login_attempts LIMIT 1');
} catch (PDOException $ex) {
    $hasMethod = false;
}

$where = [];
$params = [];
if (($activeFilters['result'] ?? '') === 'success') { $where[] = 'success = 1'; }
if (($activeFilters['result'] ?? '') === 'fail')    { $where[] = 'success = 0'; }
if (!empty($activeFilters['q'])) {
    $where[] = '(username LIKE :q OR ip_address LIKE :q)';
    $params['q'] = '%' . $activeFilters['q'] . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$perPage = 25;
$page = max(1, (int) ($_GET['page'] ?? 1));
$total = 0;
$rows = [];
$todayOk = $todayFail = 0;
$online = [];
try {
    $cs = $db->prepare("SELECT COUNT(*) c FROM login_attempts $whereSql");
    $cs->execute($params);
    $total = (int) $cs->fetch()['c'];

    $stmt = $db->prepare("SELECT * FROM login_attempts $whereSql ORDER BY id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage));
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $todayOk   = (int) $db->query('SELECT COUNT(*) c FROM login_attempts WHERE success = 1 AND DATE(attempted_at) = CURDATE()')->fetch()['c'];
    $todayFail = (int) $db->query('SELECT COUNT(*) c FROM login_attempts WHERE success = 0 AND DATE(attempted_at) = CURDATE()')->fetch()['c'];
} catch (PDOException $ex) {
    $rows = [];
}

// এখন কে অনলাইনে (last_seen_at — কলাম না থাকলে চুপচাপ বাদ)
try {
    $os = $db->prepare('SELECT full_name, username, role, last_seen_at FROM admin_users
                        WHERE last_seen_at IS NOT NULL AND last_seen_at > (NOW() - INTERVAL ' . (int) ADMIN_ONLINE_WINDOW_MINUTES . ' MINUTE)
                        ORDER BY last_seen_at DESC');
    $os->execute();
    $online = $os->fetchAll();
} catch (Throwable $e) {
    $online = [];
}

$totalPages = (int) ceil(max(1, $total) / $perPage);
require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$hasMethod): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-admin-visibility.sql</code> চালান — তার আগে "কীভাবে ঢুকল (পাসওয়ার্ড/ফিঙ্গার)" ও "এখন অনলাইনে" দেখাবে না।</p>
</div>
<?php endif; ?>

<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    <a href="<?= e(ll_url(['result' => 'success', 'page' => null])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-green-600"><?= $todayOk ?></div>
        <p class="text-gray-500 text-sm mt-1">আজ সফল লগইন</p>
    </a>
    <a href="<?= e(ll_url(['result' => 'fail', 'page' => null])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-red-600"><?= $todayFail ?></div>
        <p class="text-gray-500 text-sm mt-1">আজ ব্যর্থ চেষ্টা</p>
    </a>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-indigo-600"><?= count($online) ?></div>
        <p class="text-gray-500 text-sm mt-1">এখন অনলাইনে</p>
    </div>
</div>

<?php if ($todayFail >= 10): ?>
<div class="bg-red-50 border border-red-200 text-red-700 rounded-2xl p-4 mb-6 text-sm">
    <b>⚠️ আজ <?= $todayFail ?> বার ভুল পাসওয়ার্ড দেওয়া হয়েছে।</b> নিচের তালিকায় দেখুন কোন ইউজারনেম ও কোন IP থেকে — আপনি নিজে না হলে পাসওয়ার্ড বদলে ফেলুন।
</div>
<?php endif; ?>

<?php if ($online): ?>
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h3 class="font-bold text-gray-800 mb-3">🟢 এখন অনলাইনে (শেষ <?= (int) ADMIN_ONLINE_WINDOW_MINUTES ?> মিনিটে সক্রিয়)</h3>
    <div class="flex flex-wrap gap-2">
        <?php foreach ($online as $o): ?>
            <span class="inline-flex items-center gap-2 bg-green-50 text-green-800 px-3 py-1.5 rounded-xl text-sm font-semibold">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <?= e($o['full_name'] ?: $o['username']) ?>
                <span class="text-green-600"><?= $o['role'] === 'moderator' ? '(মডারেটর)' : '' ?></span>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<form method="get" id="llFilterForm" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">ফলাফল</label>
        <select name="result" onchange="document.getElementById('llFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <option value="success" <?= ($activeFilters['result'] ?? '') === 'success' ? 'selected' : '' ?>>শুধু সফল</option>
            <option value="fail" <?= ($activeFilters['result'] ?? '') === 'fail' ? 'selected' : '' ?>>শুধু ব্যর্থ</option>
        </select>
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">ইউজারনেম / IP</label>
        <input type="text" name="q" value="<?= e($activeFilters['q'] ?? '') ?>" placeholder="খুঁজুন…" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
    </div>
    <button type="submit" class="bg-indigo-600 text-white font-bold px-4 py-2 rounded-xl text-sm">খুঁজুন</button>
    <?php if ($activeFilters): ?><a href="login-logs.php" class="text-sm font-semibold text-gray-500 py-2">✕ ফিল্টার মুছুন</a><?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">ফলাফল</th>
                <th class="py-3 px-4">ইউজারনেম</th>
                <th class="py-3 px-4">কীভাবে</th>
                <th class="py-3 px-4">IP</th>
                <th class="py-3 px-4">কখন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-6 px-4 text-center text-gray-400">কোনো লগইন রেকর্ড নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): $ok = (int) $row['success'] === 1; ?>
            <tr class="border-b last:border-0">
                <td class="py-2.5 px-4 whitespace-nowrap">
                    <span class="text-xs font-bold px-2 py-0.5 rounded-lg <?= $ok ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>"><?= $ok ? '✓ সফল' : '✕ ব্যর্থ' ?></span>
                </td>
                <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($row['username']) ?></td>
                <td class="py-2.5 px-4 text-gray-600"><?= ($row['method'] ?? 'password') === 'webauthn' ? '🔑 ফিঙ্গারপ্রিন্ট' : '🔒 পাসওয়ার্ড' ?></td>
                <td class="py-2.5 px-4 font-mono text-xs"><?= e(format_ip_display($row['ip_address'])) ?></td>
                <td class="py-2.5 px-4 text-gray-500 text-xs whitespace-nowrap" title="<?= e($row['attempted_at']) ?>"><?= e(date('d M, H:i', strtotime($row['attempted_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="flex flex-wrap gap-2 mt-4">
    <?php for ($i = 1; $i <= min($totalPages, 20); $i++): ?>
        <a href="<?= e(ll_url(['page' => $i > 1 ? $i : null])) ?>" class="px-3 py-1.5 rounded-lg text-sm font-semibold <?= $i === $page ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 shadow' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<p class="text-gray-400 text-xs mt-4">সর্বশেষ <?= (int) ADMIN_LOGIN_LOG_KEEP_DAYS ?> দিনের লগইন-ইতিহাস রাখা হয়, তার পুরনোগুলো নিজে থেকেই মুছে যায়।</p>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
