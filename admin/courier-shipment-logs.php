<?php
// প্রতিটা কুরিয়ার পাঠানোর চেষ্টার (প্রথমবার + প্রতিটা রিসেন্ড) সম্পূর্ণ raw লগ — courier_shipments টেবিলের
// উপর ভিত্তি করে, courier_batches (period_label) ও registrations (নাম/ফোন/আইটেম) জয়েন করে দেখায়।
// courier.php তে শুধু "সর্বশেষ" consignment/fee দেখা যায় — পুরো ইতিহাস একসাথে দেখতে/খুঁজতে এই পেজ।

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কুরিয়ার শিপমেন্ট লগ';

$typeLabels = ['course' => 'কোর্স', 'worksheet' => 'ওয়ার্কশিট', 'product' => 'প্রোডাক্ট'];
$providerLabels = ['steadfast' => 'Steadfast', 'pathao' => 'Pathao'];
$statusLabels = [
    'created' => ['সফল', 'bg-green-100 text-green-700'],
    'failed' => ['ব্যর্থ', 'bg-red-100 text-red-700'],
];

$filterStatus = $_GET['status'] ?? '';
$filterProvider = $_GET['provider'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$regId = (int) ($_GET['reg_id'] ?? 0);
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($filterStatus && isset($statusLabels[$filterStatus])) {
    $where[] = 'cs.status = :status';
    $params['status'] = $filterStatus;
}
if ($filterProvider && isset($providerLabels[$filterProvider])) {
    $where[] = 'cs.provider = :provider';
    $params['provider'] = $filterProvider;
}
if ($filterType && isset($typeLabels[$filterType])) {
    $where[] = 'r.type = :type';
    $params['type'] = $filterType;
}
if ($regId > 0) {
    $where[] = 'cs.registration_id = :reg_id';
    $params['reg_id'] = $regId;
}
if ($search !== '') {
    $where[] = '(r.customer_name LIKE :q1 OR r.phone LIKE :q2 OR r.item_title LIKE :q3 OR cs.consignment_id LIKE :q4)';
    $like = '%' . $search . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
    $params['q4'] = $like;
}
if ($dateFrom !== '') {
    $where[] = 'DATE(cs.created_at) >= :date_from';
    $params['date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'DATE(cs.created_at) <= :date_to';
    $params['date_to'] = $dateTo;
}
$whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

$countStmt = $db->prepare("SELECT COUNT(*) c FROM courier_shipments cs JOIN registrations r ON r.id = cs.registration_id{$whereSql}");
$countStmt->execute($params);
$totalRows = (int) $countStmt->fetch()['c'];
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$sql = "SELECT cs.*, cb.period_label, r.type, r.item_title, r.customer_name, r.phone, r.receiver_name, r.receiver_phone
        FROM courier_shipments cs
        JOIN registrations r ON r.id = cs.registration_id
        LEFT JOIN courier_batches cb ON cb.id = cs.batch_id
        {$whereSql}
        ORDER BY cs.created_at DESC
        LIMIT {$perPage} OFFSET {$offset}";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$activeFilters = array_filter([
    'status' => $filterStatus, 'provider' => $filterProvider, 'type' => $filterType,
    'q' => $search, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'reg_id' => $regId ?: '',
], fn($v) => $v !== '' && $v !== null && $v !== 0);

function log_url(array $overrides = []): string
{
    global $activeFilters;
    $params = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null && $v !== 0);
    $qs = http_build_query($params);
    return 'courier-shipment-logs.php' . ($qs !== '' ? '?' . $qs : '');
}

$hasActiveFilters = !empty(array_diff_key($activeFilters, ['reg_id' => '']));

require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($regId > 0): ?>
<div class="bg-indigo-50 border border-indigo-200 rounded-2xl p-4 mb-4 text-sm text-indigo-800 flex items-center justify-between">
    <span>শুধু রেজিস্ট্রেশন <strong>#<?= $regId ?></strong> এর জন্য ফিল্টার করা দেখাচ্ছে।</span>
    <a href="<?= e(log_url(['reg_id' => null])) ?>" class="font-semibold underline">✕ ফিল্টার সরান</a>
</div>
<?php endif; ?>

<div class="flex flex-wrap gap-2 mb-3">
    <a href="<?= e(log_url(['status' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === '' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>">সব স্ট্যাটাস</a>
    <?php foreach ($statusLabels as $key => $s): ?>
        <a href="<?= e(log_url(['status' => $key])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === $key ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($s[0]) ?></a>
    <?php endforeach; ?>
</div>

<div class="flex flex-wrap gap-2 mb-4">
    <a href="<?= e(log_url(['provider' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterProvider === '' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>">সব প্রোভাইডার</a>
    <?php foreach ($providerLabels as $key => $label): ?>
        <a href="<?= e(log_url(['provider' => $key])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterProvider === $key ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="courier-shipment-logs.php" class="mb-3 bg-white rounded-2xl shadow p-4">
    <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
    <?php if ($filterProvider): ?><input type="hidden" name="provider" value="<?= e($filterProvider) ?>"><?php endif; ?>
    <?php if ($regId): ?><input type="hidden" name="reg_id" value="<?= $regId ?>"><?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
        <div class="lg:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 mb-1">নাম, ফোন, আইটেম বা Consignment ID</label>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="খুঁজুন..." class="w-full border rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">টাইপ</label>
            <select name="type" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব টাইপ</option>
                <?php foreach ($typeLabels as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $filterType === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ থেকে</label>
            <input type="date" name="date_from" value="<?= e($dateFrom) ?>" class="w-full border rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ পর্যন্ত</label>
            <input type="date" name="date_to" value="<?= e($dateTo) ?>" class="w-full border rounded-xl px-3 py-2.5 text-sm">
        </div>
    </div>
    <div class="flex flex-wrap gap-2 mt-3">
        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">🔍 ফিল্টার করুন</button>
        <?php if ($hasActiveFilters): ?><a href="<?= e(log_url(['status' => null, 'provider' => null, 'type' => null, 'q' => null, 'date_from' => null, 'date_to' => null])) ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ ফিল্টার মুছুন</a><?php endif; ?>
    </div>
</form>

<p class="text-sm text-gray-500 mb-4">মোট <strong><?= $totalRows ?></strong> টি ফলাফল<?= $totalPages > 1 ? " — পৃষ্ঠা {$page}/{$totalPages}" : '' ?></p>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">Row ID</th>
                <th class="py-3 px-4">টাইপ / নাম</th>
                <th class="py-3 px-4">আইটেম</th>
                <th class="py-3 px-4">ব্যাচ লেবেল</th>
                <th class="py-3 px-4">প্রোভাইডার</th>
                <th class="py-3 px-4">স্ট্যাটাস</th>
                <th class="py-3 px-4">Consignment ID</th>
                <th class="py-3 px-4">Delivery Fee</th>
                <th class="py-3 px-4">সময়</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="10" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters || $regId ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'এখনো কোনো কুরিয়ার পাঠানো হয়নি।' ?></td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): $s = $statusLabels[$row['status']] ?? ['?', 'bg-gray-100']; ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4 font-mono text-xs">#<?= $row['registration_id'] ?></td>
                <td class="py-2.5 px-4">
                    <?= e($typeLabels[$row['type']] ?? $row['type']) ?>
                    <br><span class="text-gray-400 text-xs"><?= e($row['receiver_name'] ?: $row['customer_name']) ?></span>
                </td>
                <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['period_label'] ?: '—') ?></td>
                <td class="py-2.5 px-4"><?= e(ucfirst($row['provider'])) ?></td>
                <td class="py-2.5 px-4"><span class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $s[1] ?>"><?= e($s[0]) ?></span></td>
                <td class="py-2.5 px-4 font-mono text-xs"><?= e($row['consignment_id'] ?: '-') ?></td>
                <td class="py-2.5 px-4"><?= $row['delivery_fee'] !== null ? '৳' . number_format((float) $row['delivery_fee'], 2) : '-' ?></td>
                <td class="py-2.5 px-4 whitespace-nowrap"><?= e($row['created_at']) ?></td>
                <td class="py-2.5 px-4"><a href="courier.php?action=view&id=<?= $row['registration_id'] ?><?= $row['batch_id'] ? '&batch=' . $row['batch_id'] : '' ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
    <div class="flex flex-wrap items-center justify-center gap-1.5 mt-5">
        <a href="<?= e(log_url(['page' => max(1, $page - 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page <= 1 ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">‹ আগে</a>
        <?php
            $rangeStart = max(1, $page - 2);
            $rangeEnd = min($totalPages, $page + 2);
            if ($rangeStart > 1) {
                echo '<a href="' . e(log_url(['page' => 1])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">1</a>';
                if ($rangeStart > 2) { echo '<span class="px-1 text-gray-400">…</span>'; }
            }
            for ($p = $rangeStart; $p <= $rangeEnd; $p++) {
                $active = $p === $page;
                echo '<a href="' . e(log_url(['page' => $p])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold ' . ($active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 shadow') . '">' . $p . '</a>';
            }
            if ($rangeEnd < $totalPages) {
                if ($rangeEnd < $totalPages - 1) { echo '<span class="px-1 text-gray-400">…</span>'; }
                echo '<a href="' . e(log_url(['page' => $totalPages])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">' . $totalPages . '</a>';
            }
        ?>
        <a href="<?= e(log_url(['page' => min($totalPages, $page + 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page >= $totalPages ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">পরে ›</a>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
