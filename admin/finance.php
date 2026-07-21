<?php
require_once __DIR__ . '/includes/auth.php';
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

require __DIR__ . '/includes/layout-top.php';
?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8">
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-2xl sm:text-3xl font-black text-green-600">৳<?= number_format($totalIncome, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট আয় (এই মাসে ৳<?= number_format($monthIncome, 2) ?>)</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-2xl sm:text-3xl font-black text-red-600">৳<?= number_format($totalExpense, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট খরচ (এই মাসে ৳<?= number_format($monthExpense, 2) ?>)</p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-2xl sm:text-3xl font-black <?= $netProfit >= 0 ? 'text-indigo-600' : 'text-red-600' ?>">৳<?= number_format($netProfit, 2) ?></div>
        <p class="text-gray-500 text-sm mt-1">নীট মুনাফা</p>
    </div>
</div>

<div class="flex gap-3 mb-8">
    <a href="income.php" class="bg-green-600 hover:bg-green-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ আয় যোগ করুন</a>
    <a href="expenses.php" class="bg-red-600 hover:bg-red-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ খরচ যোগ করুন</a>
</div>

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
