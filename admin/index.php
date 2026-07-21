<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entities.php';
admin_require_login();

$db = get_db();
$pageTitle = 'ড্যাশবোর্ড';

$counts = [];
foreach (get_entities() as $key => $conf) {
    $counts[$key] = (int) $db->query('SELECT COUNT(*) c FROM ' . $conf['table'])->fetch()['c'];
}

$pendingRegistrations = (int) $db->query("SELECT COUNT(*) c FROM registrations WHERE status = 'pending'")->fetch()['c'];
$totalRegistrations = (int) $db->query('SELECT COUNT(*) c FROM registrations')->fetch()['c'];
$todayRegistrations = (int) $db->query('SELECT COUNT(*) c FROM registrations WHERE DATE(created_at) = CURDATE()')->fetch()['c'];

// গত ৭ দিনের তারিখ লিস্ট (৬ দিন আগে থেকে আজকে পর্যন্ত, পুরনো থেকে নতুন ক্রমে)
$last7Dates = [];
for ($i = 6; $i >= 0; $i--) {
    $last7Dates[] = date('Y-m-d', strtotime("-{$i} days"));
}
$weekStart = $last7Dates[0];

$bnWeekday = ['Sun' => 'রবি', 'Mon' => 'সোম', 'Tue' => 'মঙ্গল', 'Wed' => 'বুধ', 'Thu' => 'বৃহঃ', 'Fri' => 'শুক্র', 'Sat' => 'শনি'];
$chartLabels = array_map(fn($d) => $bnWeekday[date('D', strtotime($d))], $last7Dates);

$weekRegStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE created_at >= :start');
$weekRegStmt->execute(['start' => $weekStart]);
$weekRegistrations = (int) $weekRegStmt->fetch()['c'];

// দিন-ভিত্তিক টাইপ (কোর্স/ওয়ার্কশিট/প্রোডাক্ট) ব্রেকডাউন
$dailyTypeStmt = $db->prepare(
    'SELECT DATE(created_at) d, type, COUNT(*) c FROM registrations WHERE created_at >= :start GROUP BY DATE(created_at), type'
);
$dailyTypeStmt->execute(['start' => $weekStart]);
$dailyTypeMatrix = [];
foreach ($last7Dates as $d) {
    $dailyTypeMatrix[$d] = ['course' => 0, 'worksheet' => 0, 'product' => 0];
}
foreach ($dailyTypeStmt->fetchAll() as $row) {
    if (isset($dailyTypeMatrix[$row['d']][$row['type']])) {
        $dailyTypeMatrix[$row['d']][$row['type']] = (int) $row['c'];
    }
}

// দিন-ভিত্তিক আয়-ব্যয়
$dailyIncome = array_fill_keys($last7Dates, 0.0);
$incomeStmt = $db->prepare('SELECT DATE(income_date) d, SUM(amount) t FROM income WHERE income_date >= :start GROUP BY DATE(income_date)');
$incomeStmt->execute(['start' => $weekStart]);
foreach ($incomeStmt->fetchAll() as $row) {
    if (isset($dailyIncome[$row['d']])) {
        $dailyIncome[$row['d']] = (float) $row['t'];
    }
}

$dailyExpense = array_fill_keys($last7Dates, 0.0);
$expenseStmt = $db->prepare('SELECT DATE(expense_date) d, SUM(amount) t FROM expenses WHERE expense_date >= :start GROUP BY DATE(expense_date)');
$expenseStmt->execute(['start' => $weekStart]);
foreach ($expenseStmt->fetchAll() as $row) {
    if (isset($dailyExpense[$row['d']])) {
        $dailyExpense[$row['d']] = (float) $row['t'];
    }
}

$weekIncomeTotal = array_sum($dailyIncome);
$weekExpenseTotal = array_sum($dailyExpense);

$recentRegistrations = $db->query(
    'SELECT * FROM registrations ORDER BY created_at DESC LIMIT 5'
)->fetchAll();

// ট্রেন্ড তুলনা — আজ vs গতকাল, এই ৭ দিন vs আগের ৭ দিন
$yesterdayReg = (int) $db->query("SELECT COUNT(*) c FROM registrations WHERE DATE(created_at) = DATE(CURDATE() - INTERVAL 1 DAY)")->fetch()['c'];
$prevWeekStmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE created_at >= :s AND created_at < :e');
$prevWeekStmt->execute(['s' => date('Y-m-d', strtotime('-13 days')), 'e' => $weekStart]);
$prevWeekReg = (int) $prevWeekStmt->fetch()['c'];

// স্ট্যাট কার্ডের নিচে ছোট ট্রেন্ড-চিপ (বেড়েছে/কমেছে/অপরিবর্তিত)
function trend_chip(int $now, int $prev): string
{
    $diff = $now - $prev;
    if ($diff > 0) return '<span class="inline-flex items-center gap-0.5 text-green-600 font-semibold"><i data-lucide="trending-up" class="w-3.5 h-3.5"></i> +' . $diff . '</span>';
    if ($diff < 0) return '<span class="inline-flex items-center gap-0.5 text-red-600 font-semibold"><i data-lucide="trending-down" class="w-3.5 h-3.5"></i> ' . $diff . '</span>';
    return '<span class="inline-flex items-center gap-0.5 text-gray-400 font-semibold"><i data-lucide="minus" class="w-3.5 h-3.5"></i> অপরিবর্তিত</span>';
}

$greetHour = (int) date('G');
$greeting = $greetHour < 12 ? 'সুপ্রভাত' : ($greetHour < 17 ? 'শুভ অপরাহ্ন' : 'শুভ সন্ধ্যা');

require __DIR__ . '/includes/layout-top.php';
?>

<script src="assets/chart.js?v=<?= @filemtime(__DIR__ . '/assets/chart.js') ?: '1' ?>"></script>
<script>
    // Chart.js থিম-ম্যাচিং — ফন্ট, গ্রিড/টেক্সট রঙ CSS ভ্যারিয়েবল থেকে, বার গোল কোণা (রিলোডে বর্তমান থিম নেয়)
    (function () {
        if (!window.Chart) return;
        var cs = getComputedStyle(document.documentElement);
        var vv = function (n, a) { return 'rgb(' + cs.getPropertyValue(n).trim() + (a != null ? ' / ' + a : '') + ')'; };
        Chart.defaults.font.family = "'Hind Siliguri', 'Inter', sans-serif";
        Chart.defaults.color = vv('--c-text-muted');
        Chart.defaults.borderColor = vv('--c-border', .55);
        Chart.defaults.elements.bar.borderRadius = 6;
        Chart.defaults.elements.bar.borderSkipped = false;
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.padding = 16;
    })();
</script>

<!-- হিরো ব্যানার — স্বাগতম + দ্রুত অ্যাকশন -->
<div class="rounded-2xl p-6 sm:p-7 mb-6 relative overflow-hidden text-white shadow" style="background: linear-gradient(135deg, rgb(var(--c-primary)), rgb(var(--c-primary-2)));">
    <div class="absolute inset-0 pointer-events-none" style="background: radial-gradient(420px circle at 88% -20%, rgba(255,255,255,.28), transparent 60%);"></div>
    <div class="relative">
        <p class="text-white/80 text-sm font-semibold mb-1"><?= $greeting ?>, স্বাগতম 👋</p>
        <h2 class="text-2xl sm:text-3xl font-black mb-1.5" style="font-family: var(--font-head);"><?= e(current_admin_name()) ?></h2>
        <p class="text-white/85 text-sm mb-5">আজকে <?= $todayRegistrations ?> টি নতুন রেজিস্ট্রেশন · <?= $pendingRegistrations ?> টি অপেক্ষমাণ অর্ডার</p>
        <div class="flex flex-wrap gap-2.5">
            <a href="manage.php?entity=courses&action=form" class="inline-flex items-center gap-2 bg-white text-gray-800 font-bold px-4 py-2.5 rounded-xl text-sm shadow-sm hover:shadow-md"><i data-lucide="plus" class="w-4 h-4"></i> নতুন কোর্স</a>
            <a href="registrations.php" class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/30 text-white font-bold px-4 py-2.5 rounded-xl text-sm"><i data-lucide="clipboard-list" class="w-4 h-4"></i> রেজিস্ট্রেশন</a>
            <a href="courier.php" class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/30 text-white font-bold px-4 py-2.5 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4"></i> কুরিয়ার</a>
        </div>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-5 flex items-center gap-4">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-indigo-100 text-indigo-600"><i data-lucide="calendar-check" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-indigo-600 leading-none"><?= $todayRegistrations ?></div>
            <p class="text-gray-500 text-sm mt-1">আজকের রেজিস্ট্রেশন/অর্ডার</p>
            <p class="text-xs mt-1.5"><?= trend_chip($todayRegistrations, $yesterdayReg) ?> <span class="text-gray-400">গতকালের তুলনায়</span></p>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 flex items-center gap-4">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-blue-100 text-blue-600"><i data-lucide="calendar-days" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-blue-600 leading-none"><?= $weekRegistrations ?></div>
            <p class="text-gray-500 text-sm mt-1">গত ৭ দিনে</p>
            <p class="text-xs mt-1.5"><?= trend_chip($weekRegistrations, $prevWeekReg) ?> <span class="text-gray-400">আগের সপ্তাহের তুলনায়</span></p>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 flex items-center gap-4">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-orange-100 text-orange-500"><i data-lucide="clock" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-orange-500 leading-none"><?= $pendingRegistrations ?></div>
            <p class="text-gray-500 text-sm mt-1">পেন্ডিং অর্ডার</p>
            <p class="text-xs mt-1.5 text-gray-400">অ্যাকশন প্রয়োজন</p>
        </div>
    </div>
    <div class="bg-white rounded-2xl shadow p-5 flex items-center gap-4">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-green-100 text-green-600"><i data-lucide="layers" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-green-600 leading-none"><?= $totalRegistrations ?></div>
            <p class="text-gray-500 text-sm mt-1">মোট রেজিস্ট্রেশন/অর্ডার</p>
            <p class="text-xs mt-1.5 text-gray-400">সর্বমোট</p>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <div class="bg-white rounded-2xl shadow p-5">
        <h3 class="font-bold text-gray-800 mb-1">গত ৭ দিনের রেজিস্ট্রেশন/অর্ডার</h3>
        <p class="text-gray-400 text-xs mb-4">কোর্স, ওয়ার্কশিট ও প্রোডাক্ট — কোন দিনে কতগুলো</p>
        <div class="h-64">
            <canvas id="typeChart"></canvas>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex items-center justify-between mb-1">
            <h3 class="font-bold text-gray-800">আয়-ব্যয় (গত ৭ দিন)</h3>
            <a href="finance.php" class="text-indigo-600 text-xs font-semibold">বিস্তারিত →</a>
        </div>
        <div class="flex gap-4 mb-4 text-sm">
            <p><span class="text-gray-400">আয়:</span> <span class="font-bold text-green-600">৳<?= number_format($weekIncomeTotal, 2) ?></span></p>
            <p><span class="text-gray-400">খরচ:</span> <span class="font-bold text-red-600">৳<?= number_format($weekExpenseTotal, 2) ?></span></p>
            <p><span class="text-gray-400">নিট:</span> <span class="font-bold text-indigo-600">৳<?= number_format($weekIncomeTotal - $weekExpenseTotal, 2) ?></span></p>
        </div>
        <div class="h-56">
            <canvas id="financeChart"></canvas>
        </div>
    </div>
</div>

<div class="bg-white rounded-2xl shadow p-5">
    <h3 class="font-bold text-gray-800 mb-4">সাম্প্রতিক রেজিস্ট্রেশন/অর্ডার</h3>
    <?php if (!$recentRegistrations): ?>
        <p class="text-gray-500 text-sm">এখনো কোনো রেজিস্ট্রেশন আসেনি।</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b">
                    <th class="py-2 pr-4">নাম</th>
                    <th class="py-2 pr-4">ফোন</th>
                    <th class="py-2 pr-4">ফেসবুক আইডি</th>
                    <th class="py-2 pr-4">আইটেম</th>
                    <th class="py-2 pr-4">স্ট্যাটাস</th>
                    <th class="py-2 pr-4">তারিখ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($recentRegistrations as $r): $rInitial = mb_strtoupper(mb_substr(trim($r['customer_name']) ?: '?', 0, 1), 'UTF-8'); ?>
                <tr class="border-b last:border-0">
                    <td class="py-2 pr-4">
                        <span class="inline-flex items-center gap-2">
                            <span class="avatar avatar-sm"><?= e($rInitial) ?></span>
                            <span class="font-medium text-gray-700"><?= e($r['customer_name']) ?></span>
                        </span>
                    </td>
                    <td class="py-2 pr-4"><?= e($r['phone']) ?></td>
                    <td class="py-2 pr-4"><?= e($r['facebook_id'] ?: '-') ?></td>
                    <td class="py-2 pr-4"><?= e($r['item_title']) ?></td>
                    <td class="py-2 pr-4"><?= e($r['status']) ?></td>
                    <td class="py-2 pr-4"><?= e($r['created_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <a href="registrations.php" class="inline-block mt-4 text-indigo-600 font-semibold text-sm">সব দেখুন →</a>
    <?php endif; ?>
</div>

<script>
new Chart(document.getElementById('typeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            { label: 'কোর্স', data: <?= json_encode(array_column($dailyTypeMatrix, 'course')) ?>, backgroundColor: '#4f46e5' },
            { label: 'ওয়ার্কশিট', data: <?= json_encode(array_column($dailyTypeMatrix, 'worksheet')) ?>, backgroundColor: '#16a34a' },
            { label: 'প্রোডাক্ট', data: <?= json_encode(array_column($dailyTypeMatrix, 'product')) ?>, backgroundColor: '#f59e0b' },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
        }
    }
});

new Chart(document.getElementById('financeChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chartLabels) ?>,
        datasets: [
            { label: 'আয়', data: <?= json_encode(array_values($dailyIncome)) ?>, backgroundColor: '#16a34a' },
            { label: 'খরচ', data: <?= json_encode(array_values($dailyExpense)) ?>, backgroundColor: '#dc2626' },
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: { y: { beginAtZero: true } }
    }
});
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
