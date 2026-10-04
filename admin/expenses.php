<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/finance-link.php';
admin_require_login();

$db = get_db();
$pageTitle = 'খরচ';

// 💼 কোর্স-ব্যাচের সাথে জোড়া (২০২৬-১০-০৪) — মাইগ্রেশন না চললে পুরো অংশটা চুপচাপ বাদ যায়
$finReady = fin_link_ready($db);

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

    // 🔴 ব্যাচটা সবসময় DB থেকে যাচাই — POST-এর নামে কখনো ভরসা নয়
    $link = $finReady ? fin_resolve_batch($db, $_POST['item_batch'] ?? 0) : null;

    if ($link && $link['item_id']) {
        $db->prepare(
            'INSERT INTO expenses (category_id, amount, description, expense_date, item_type, item_id, item_title, batch)
             VALUES (:c, :a, :d, :dt, :it, :ii, :itl, :b)'
        )->execute([
            'c' => $categoryId, 'a' => $amount, 'd' => $description ?: null, 'dt' => $expenseDate ?: date('Y-m-d'),
            'it' => $link['item_type'], 'ii' => $link['item_id'], 'itl' => $link['item_title'], 'b' => $link['batch'],
        ]);
    } else {
        $db->prepare('INSERT INTO expenses (category_id, amount, description, expense_date) VALUES (:c, :a, :d, :dt)')
            ->execute(['c' => $categoryId, 'a' => $amount, 'd' => $description ?: null, 'dt' => $expenseDate ?: date('Y-m-d')]);
    }

    set_flash('success', 'খরচ যোগ করা হয়েছে।' . ($link && $link['item_id'] ? ' (' . $link['item_title'] . ' — ' . $link['batch'] . ')' : ''));
    redirect('expenses.php' . ($link && $link['item_id'] ? '?item=' . (int) $link['item_id'] : ''));
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

// ── ফিল্টার (registrations.php-এর $activeFilters প্যাটার্ন) ──
$filterItem = (int) ($_GET['item'] ?? 0);
$activeFilters = array_filter(['item' => $filterItem ?: null], fn($v) => $v !== null && $v !== '');

$where = [];
$params = [];
if ($finReady && $filterItem > 0) {
    $where[] = fin_item_where('e', 'r', $db);
    $params['fin_item'] = $filterItem;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $db->prepare(
    'SELECT e.*, fc.name category_name, ' . fin_item_select('e', 'r', $db) . '
     FROM expenses e
     JOIN finance_categories fc ON fc.id = e.category_id
     LEFT JOIN registrations r ON r.id = e.registration_id
     ' . $whereSql . '
     ORDER BY e.expense_date DESC, e.id DESC'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$total = array_sum(array_column($rows, 'amount'));
$categories = get_finance_categories('expense');
$batchOptions = $finReady ? fin_batch_options($db) : [];

require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$finReady): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-5 text-sm">
    ⚠️ <b>কোর্স-ব্যাচ অনুযায়ী খরচ এখনো চালু হয়নি।</b> phpMyAdmin-এ
    <code class="bg-white px-1 rounded">database/migrate-finance-course-link.sql</code> একবার চালালে
    নিচের ফর্মে কোর্স-ব্যাচ বাছার ঘরটা আসবে। (ততক্ষণ খরচ আগের মতোই যোগ করা যাবে।)
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h3 class="font-bold text-gray-800 mb-4">➕ নতুন খরচ যোগ করুন</h3>
    <form method="post" action="expenses.php?action=add" class="space-y-3">
        <?= csrf_field() ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
            <div>
                <label class="block text-xs text-gray-500 mb-1">ক্যাটেগরি</label>
                <input type="text" name="category" required list="expense-cat-list" placeholder="যেমন: ভাড়া, বেতন" class="w-full border rounded-lg px-3 py-2 text-sm">
                <datalist id="expense-cat-list">
                    <?php foreach ($categories as $c): ?><option value="<?= e($c['name']) ?>"><?php endforeach; ?>
                </datalist>
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">পরিমাণ (৳)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">বিবরণ (ঐচ্ছিক)</label>
                <input type="text" name="description" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-xs text-gray-500 mb-1">তারিখ</label>
                <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" class="w-full border rounded-lg px-3 py-2 text-sm">
            </div>
        </div>
        <?php if ($finReady): ?>
        <div class="border-t pt-3">
            <label class="block text-xs text-gray-500 mb-1">কোন কোর্স-ব্যাচের খরচ? <span class="text-gray-400">(ঐচ্ছিক — নাম লিখে খুঁজতে পারেন)</span></label>
            <select name="item_batch" data-picker class="w-full border rounded-lg px-3 py-2 text-sm">
                <option value="">— সাধারণ খরচ (কোর্স নির্দিষ্ট নয়) —</option>
                <?= fin_batch_select_options($filterItem, $db) ?>
            </select>
            <p class="text-xs text-gray-400 mt-1">ভাড়া/বেতন/বিদ্যুতের মতো সাধারণ খরচে দুটোই খালি রাখুন।</p>
        </div>
        <?php endif; ?>
        <div>
            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm">যোগ করুন</button>
        </div>
    </form>
</div>

<?php if ($finReady && $batchOptions): ?>
<form method="get" action="expenses.php" id="expFilterForm" class="bg-white rounded-2xl shadow p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 mb-1">কোর্স-ব্যাচ অনুযায়ী খরচ দেখুন <span class="font-normal text-gray-400">(নাম লিখে খুঁজুন)</span></label>
            <select name="item" data-picker onchange="document.getElementById('expFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব খরচ</option>
                <?= fin_batch_select_options($filterItem, $db) ?>
            </select>
        </div>
        <?php if ($activeFilters): ?>
        <div><a href="expenses.php" class="inline-block bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ ফিল্টার মুছুন</a></div>
        <?php endif; ?>
    </div>
</form>
<?php endif; ?>

<div class="flex justify-between items-center mb-4 flex-wrap gap-2">
    <p class="text-gray-500 text-sm">
        মোট <?= count($rows) ?> টি এন্ট্রি
        <?php if ($filterItem > 0): ?>
            <span class="text-gray-700 font-semibold">— <?= e(fin_item_label('course', $filterItem, '', '', $db)) ?></span>
        <?php endif; ?>
    </p>
    <p class="text-lg font-bold text-red-700"><?= $filterItem > 0 ? 'এই ব্যাচের খরচ' : 'সর্বমোট' ?>: ৳<?= number_format($total, 2) ?></p>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">ক্যাটেগরি</th>
                <th class="py-3 px-4">কোর্স / ব্যাচ</th>
                <th class="py-3 px-4">বিবরণ</th>
                <th class="py-3 px-4">পরিমাণ</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="py-6 px-4 text-center text-gray-400">কোনো খরচ যোগ করা হয়নি।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <?php $key = fin_item_key($r['eff_type'] ?? null, $r['eff_item_id'] ?? 0); ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4"><?= e($r['category_name']) ?></td>
                <td class="py-2.5 px-4">
                    <?php if ($key !== ''): ?>
                        <span class="text-gray-800"><?= e(fin_item_label($r['eff_type'], $r['eff_item_id'], $r['eff_item_title'] ?? '', $r['eff_batch'] ?? '', $db)) ?></span>
                        <?php if (!empty($r['registration_id'])): ?>
                            <span class="block text-xs text-gray-400">রেজিস্ট্রেশন #<?= (int) $r['registration_id'] ?> থেকে</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="text-gray-400 text-xs">সাধারণ</span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4">
                    <?= e($r['description'] ?? '-') ?>
                    <?php // 💸 কোর্স পার্সেলের ছাড়/মাফ থেকে অটো বসা সারি — এখান থেকে মুছলে ওখানে সেভ করলেই আবার আসবে ?>
                    <?php if (($r['source'] ?? '') === 'parcel_waiver'): ?>
                        <span class="inline-block px-2 py-0.5 rounded-lg text-xs font-semibold bg-amber-100 text-amber-800 ml-1"
                              title="কোর্স পার্সেলের কার্ডে ছাড়ের ঘরটা ০ করলে এই সারি নিজে থেকেই মুছে যাবে">কোর্স পার্সেল থেকে</span>
                    <?php endif; ?>
                </td>
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
