<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'খরচ';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'add') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('expenses.php');
    }
    $amount = (float) ($_POST['amount'] ?? 0);
    $categoryName = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $expenseDate = trim($_POST['expense_date'] ?? date('Y-m-d'));

    if ($amount <= 0 || $categoryName === '') {
        set_flash('error', 'সঠিক পরিমাণ ও ক্যাটেগরি দিন।');
        redirect('expenses.php');
    }

    $categoryId = find_or_create_finance_category('expense', $categoryName);
    $db->prepare('INSERT INTO expenses (category_id, amount, description, expense_date) VALUES (:c, :a, :d, :dt)')
        ->execute(['c' => $categoryId, 'a' => $amount, 'd' => $description ?: null, 'dt' => $expenseDate ?: date('Y-m-d')]);

    set_flash('success', 'খরচ যোগ করা হয়েছে।');
    redirect('expenses.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('expenses.php');
    }
    $id = (int) ($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM expenses WHERE id = :id')->execute(['id' => $id]);
    set_flash('success', 'খরচের এন্ট্রি ডিলিট করা হয়েছে।');
    redirect('expenses.php');
}

$rows = $db->query(
    'SELECT e.*, fc.name category_name
     FROM expenses e
     JOIN finance_categories fc ON fc.id = e.category_id
     ORDER BY e.expense_date DESC, e.id DESC'
)->fetchAll();

$total = array_sum(array_column($rows, 'amount'));
$categories = get_finance_categories('expense');

require __DIR__ . '/includes/layout-top.php';
?>

<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h3 class="font-bold text-gray-800 mb-4">➕ নতুন খরচ যোগ করুন</h3>
    <form method="post" action="expenses.php?action=add" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <?= csrf_field() ?>
        <div class="lg:col-span-1">
            <label class="block text-xs text-gray-500 mb-1">ক্যাটেগরি</label>
            <input type="text" name="category" required list="expense-cat-list" placeholder="যেমন: কোর্স, ভাড়া, বেতন" class="w-full border rounded-lg px-3 py-2 text-sm">
            <datalist id="expense-cat-list">
                <?php foreach ($categories as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">পরিমাণ (৳)</label>
            <input type="number" step="0.01" min="0.01" name="amount" required class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="lg:col-span-2">
            <label class="block text-xs text-gray-500 mb-1">বিবরণ (ঐচ্ছিক)</label>
            <input type="text" name="description" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">তারিখ</label>
            <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
        </div>
        <div class="lg:col-span-5">
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm">যোগ করুন</button>
        </div>
    </form>
</div>

<div class="flex justify-between items-center mb-4">
    <p class="text-gray-500 text-sm">মোট <?= count($rows) ?> টি এন্ট্রি</p>
    <p class="text-lg font-bold text-red-700">সর্বমোট: ৳<?= number_format($total, 2) ?></p>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">ক্যাটেগরি</th>
                <th class="py-3 px-4">বিবরণ</th>
                <th class="py-3 px-4">পরিমাণ</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-6 px-4 text-center text-gray-400">কোনো খরচ যোগ করা হয়নি।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4"><?= e($r['category_name']) ?></td>
                <td class="py-2.5 px-4"><?= e($r['description'] ?? '-') ?></td>
                <td class="py-2.5 px-4 font-bold text-red-700">৳<?= number_format($r['amount'], 2) ?></td>
                <td class="py-2.5 px-4"><?= e($r['expense_date']) ?></td>
                <td class="py-2.5 px-4">
                    <form method="post" action="expenses.php?action=delete" onsubmit="return confirmSubmit(this, 'এই খরচের এন্ট্রি ডিলিট করতে চান?', 'ডিলিট নিশ্চিতকরণ');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $r['id'] ?>">
                        <button type="submit" class="text-red-600 font-semibold text-xs">ডিলিট</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
