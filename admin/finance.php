<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/finance-link.php';
admin_require_login();

$db = get_db();
$pageTitle = 'আয়-ব্যয় ড্যাশবোর্ড';

$totalIncome = (float) $db->query('SELECT COALESCE(SUM(amount), 0) t FROM income')->fetch()['t'];
$totalExpense = (float) $db->query('SELECT COALESCE(SUM(amount), 0) t FROM expenses')->fetch()['t'];
$netProfit = $totalIncome - $totalExpense;

$monthStart = date('Y-m-01');
$stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) t FROM income WHERE income_date >= :d');
$stmt->execute(['d' => $monthStart]);
$monthIncome = (float) $stmt->fetch()['t'];

$stmt = $db->prepare('SELECT COALESCE(SUM(amount), 0) t FROM expenses WHERE expense_date >= :d');
$stmt->execute(['d' => $monthStart]);
$monthExpense = (float) $stmt->fetch()['t'];

$incomeByCategory = $db->query(
    "SELECT fc.name, COALESCE(SUM(i.amount), 0) total, COUNT(i.id) cnt
     FROM finance_categories fc
     LEFT JOIN income i ON i.category_id = fc.id
     WHERE fc.type = 'income'
     GROUP BY fc.id, fc.name
     ORDER BY total DESC"
)->fetchAll();

$expenseByCategory = $db->query(
    "SELECT fc.name, COALESCE(SUM(e.amount), 0) total, COUNT(e.id) cnt
     FROM finance_categories fc
     LEFT JOIN expenses e ON e.category_id = fc.id
     WHERE fc.type = 'expense'
     GROUP BY fc.id, fc.name
     ORDER BY total DESC"
)->fetchAll();

$recentIncome = $db->query('SELECT i.*, fc.name category_name FROM income i JOIN finance_categories fc ON fc.id = i.category_id ORDER BY i.created_at DESC LIMIT 5')->fetchAll();
$recentExpense = $db->query('SELECT e.*, fc.name category_name FROM expenses e JOIN finance_categories fc ON fc.id = e.category_id ORDER BY e.created_at DESC LIMIT 5')->fetchAll();

// ── 💼 কোর্স-ব্যাচ অনুযায়ী আয় − খরচ = লাভ (২০২৬-১০-০৪, ইউজারের চাওয়া) ──
// 🔴 "কার টাকা" ঠিক করার নিয়মটা এক জায়গায়: fin_item_select() — রেজিস্ট্রেশন থাকলে
//    সেখান থেকেই (move-course করলেও ঠিক থাকে), নাহলে সারির নিজের ঘর থেকে।
// 🔴 পুরো ব্লক try/catch-এ — এটা ল্যান্ডিং-পাতা নয় ঠিকই, তবু মাইগ্রেশনের আগে বা কোনো
//    কোয়েরি ব্যর্থ হলে পুরো আয়-ব্যয় পাতা ভাঙতে দেওয়া যাবে না।
$byItem = [];
$byItemFailed = false;
try {
    $incRows = $db->query(
        'SELECT ' . fin_item_select('i', 'r', $db, true) . ', SUM(i.amount) total, COUNT(*) cnt
           FROM income i LEFT JOIN registrations r ON r.id = i.registration_id
          GROUP BY eff_type, eff_item_id'
    )->fetchAll();
    $expRows = $db->query(
        'SELECT ' . fin_item_select('e', 'r', $db, true) . ', SUM(e.amount) total, COUNT(*) cnt
           FROM expenses e LEFT JOIN registrations r ON r.id = e.registration_id
          GROUP BY eff_type, eff_item_id'
    )->fetchAll();

    $put = function (array $list, string $side) use (&$byItem) {
        foreach ($list as $row) {
            $key = fin_item_key($row['eff_type'] ?? null, $row['eff_item_id'] ?? 0);
            if (!isset($byItem[$key])) {
                $byItem[$key] = [
                    'type' => $row['eff_type'] ?? null, 'id' => (int) ($row['eff_item_id'] ?? 0),
                    'title' => $row['eff_item_title'] ?? '', 'batch' => $row['eff_batch'] ?? '',
                    'income' => 0.0, 'expense' => 0.0, 'inc_cnt' => 0, 'exp_cnt' => 0,
                ];
            }
            $byItem[$key][$side] += (float) $row['total'];
            $byItem[$key][$side === 'income' ? 'inc_cnt' : 'exp_cnt'] += (int) $row['cnt'];
        }
    };
    $put($incRows, 'income');
    $put($expRows, 'expense');

    // 🔴 "সাধারণ" সারিটা সবসময় সবার শেষে — ওটা কোনো কোর্সের হিসাব নয়
    uasort($byItem, function ($a, $b) {
        $ga = fin_item_key($a['type'], $a['id']) === '' ? 1 : 0;
        $gb = fin_item_key($b['type'], $b['id']) === '' ? 1 : 0;
        if ($ga !== $gb) { return $ga <=> $gb; }
        return ($b['income'] - $b['expense']) <=> ($a['income'] - $a['expense']);
    });
} catch (Throwable $e) {
    $byItem = [];
    $byItemFailed = true;
}

require __DIR__ . '/includes/layout-top.php';
?>

<div class="fin-stats mb-8">
    <div class="bg-white rounded-2xl shadow p-5 min-w-0">
        <div class="fin-stat font-black break-all text-green-600">৳<?= number_format($totalIncome, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট আয় (এই মাসে ৳<?= number_format($monthIncome, 2) ?>)</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 min-w-0">
        <div class="fin-stat font-black break-all text-red-600">৳<?= number_format($totalExpense, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট খরচ (এই মাসে ৳<?= number_format($monthExpense, 2) ?>)</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 min-w-0">
        <div class="fin-stat font-black break-all <?= $netProfit >= 0 ? 'text-indigo-600' : 'text-red-600' ?>">৳<?= number_format($netProfit, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">নীট মুনাফা</p>
    </div>
</div>

<div class="flex gap-3 mb-8">
    <a href="income.php" class="bg-green-600 hover:bg-green-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ আয় যোগ করুন</a>
    <a href="expenses.php" class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ খরচ যোগ করুন</a>
</div>

<?php if ($byItem && !$byItemFailed): ?>
<h3 class="font-bold text-gray-800 mb-1">📚 কোর্স-ব্যাচ অনুযায়ী আয়-খরচ</h3>
<p class="text-xs text-gray-500 mb-3">
    রেজিস্ট্রেশন থেকে আসা টাকা নিজে থেকেই তার কোর্স-ব্যাচে বসে; হাতে লেখা আয়/খরচে ব্যাচটা বেছে দিলে এখানে যোগ হয়।
    সংখ্যায় ক্লিক করলে ঐ ব্যাচের তালিকা খুলবে।
</p>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-8">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কোর্স / ব্যাচ</th>
                <th class="py-3 px-4">আয়</th>
                <th class="py-3 px-4">খরচ</th>
                <th class="py-3 px-4">লাভ / ক্ষতি</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($byItem as $bi): $net = $bi['income'] - $bi['expense']; $isGeneral = fin_item_key($bi['type'], $bi['id']) === ''; ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4 <?= $isGeneral ? 'text-gray-500' : 'font-semibold text-gray-800' ?>">
                    <?= e(fin_item_label($bi['type'], $bi['id'], $bi['title'], $bi['batch'], $db)) ?>
                </td>
                <td class="py-2.5 px-4 text-green-700 font-semibold">
                    <?php if (!$isGeneral && $bi['type'] === 'course'): ?>
                        <a href="income.php?item=<?= (int) $bi['id'] ?>" style="text-decoration: underline">৳<?= number_format($bi['income'], 2) ?></a>
                    <?php else: ?>৳<?= number_format($bi['income'], 2) ?><?php endif; ?>
                </td>
                <td class="py-2.5 px-4 text-red-700 font-semibold">
                    <?php if (!$isGeneral && $bi['type'] === 'course'): ?>
                        <a href="expenses.php?item=<?= (int) $bi['id'] ?>" style="text-decoration: underline">৳<?= number_format($bi['expense'], 2) ?></a>
                    <?php else: ?>৳<?= number_format($bi['expense'], 2) ?><?php endif; ?>
                </td>
                <td class="py-2.5 px-4 font-bold <?= $net >= 0 ? 'text-indigo-600' : 'text-red-600' ?>">৳<?= number_format($net, 2) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-4">ক্যাটেগরি অনুযায়ী আয়</h3>
        <?php if (!array_filter($incomeByCategory, fn($c) => $c['cnt'] > 0)): ?>
            <p class="text-gray-400 text-sm">এখনো কোনো আয় নেই।</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($incomeByCategory as $c): if ($c['cnt'] == 0) continue; ?>
            <div class="flex justify-between items-center text-sm border-b pb-2">
                <span class="text-gray-700"><?= e($c['name']) ?> <span class="text-gray-400">(<?= $c['cnt'] ?>)</span></span>
                <span class="font-bold text-green-700">৳<?= number_format($c['total'], 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h4 class="font-semibold text-gray-700 mt-6 mb-2 text-sm">সাম্প্রতিক আয়</h4>
        <?php foreach ($recentIncome as $r): ?>
            <div class="text-xs text-gray-500 flex justify-between py-1">
                <span><?= e($r['category_name']) ?> — <?= e($r['description'] ?? '') ?></span>
                <span class="font-semibold text-green-700">৳<?= number_format($r['amount'], 2) ?></span>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-4">ক্যাটেগরি অনুযায়ী খরচ</h3>
        <?php if (!array_filter($expenseByCategory, fn($c) => $c['cnt'] > 0)): ?>
            <p class="text-gray-400 text-sm">এখনো কোনো খরচ নেই।</p>
        <?php else: ?>
        <div class="space-y-2">
            <?php foreach ($expenseByCategory as $c): if ($c['cnt'] == 0) continue; ?>
            <div class="flex justify-between items-center text-sm border-b pb-2">
                <span class="text-gray-700"><?= e($c['name']) ?> <span class="text-gray-400">(<?= $c['cnt'] ?>)</span></span>
                <span class="font-bold text-red-700">৳<?= number_format($c['total'], 2) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h4 class="font-semibold text-gray-700 mt-6 mb-2 text-sm">সাম্প্রতিক খরচ</h4>
        <?php foreach ($recentExpense as $r): ?>
            <div class="text-xs text-gray-500 flex justify-between py-1">
                <span><?= e($r['category_name']) ?> — <?= e($r['description'] ?? '') ?></span>
                <span class="font-semibold text-red-700">৳<?= number_format($r['amount'], 2) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
