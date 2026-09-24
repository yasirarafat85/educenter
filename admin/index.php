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

// ভিজিটর পরিসংখ্যান (পাবলিক সাইটের পেজ-লোড, visitor_logs — site-header.php থেকে লগ হয়)
// 🔴 পুরো ব্লক try/catch-এ: ড্যাশবোর্ড প্যানেলের ল্যান্ডিং পেজ, লগ-কোয়েরি ব্যর্থ হলেও যেন না ভাঙে
$todayVisits = $yesterdayVisits = $weekVisits = $todayUniqueIps = 0;
$dailyVisits = array_fill_keys($last7Dates, 0);
$hasVisitorStats = false;

// 🤖 বট/ক্রলার বাদ (visitor_human_sql, ভিজিটর লগ পেজের সাথে একই নিয়ম)। পুরনো MySQL/MariaDB-তে
// REGEXP না চললে শর্তটা বাদ দিয়ে আগের মতোই সব গোনা হয় — সংখ্যা দেখানো বন্ধ হয় না।
$humanSql  = visitor_human_sql();
$visitCond = '';
try {
    $db->query("SELECT COUNT(*) c FROM visitor_logs WHERE $humanSql");
    $visitCond = " AND $humanSql";
} catch (PDOException $ex) {
    $visitCond = '';
}

try {
    $todayVisits     = (int) $db->query("SELECT COUNT(*) c FROM visitor_logs WHERE DATE(visited_at) = CURDATE()$visitCond")->fetch()['c'];
    $yesterdayVisits = (int) $db->query("SELECT COUNT(*) c FROM visitor_logs WHERE DATE(visited_at) = DATE(CURDATE() - INTERVAL 1 DAY)$visitCond")->fetch()['c'];
    $todayUniqueIps  = (int) $db->query("SELECT COUNT(DISTINCT ip_address) c FROM visitor_logs WHERE DATE(visited_at) = CURDATE()$visitCond")->fetch()['c'];
    $visitStmt = $db->prepare("SELECT DATE(visited_at) d, COUNT(*) c FROM visitor_logs WHERE visited_at >= :start$visitCond GROUP BY DATE(visited_at)");
    $visitStmt->execute(['start' => $weekStart]);
    foreach ($visitStmt->fetchAll() as $row) {
        if (isset($dailyVisits[$row['d']])) {
            $dailyVisits[$row['d']] = (int) $row['c'];
        }
    }
    $weekVisits = array_sum($dailyVisits);
    $hasVisitorStats = true;
} catch (PDOException $ex) {
    $hasVisitorStats = false;
}

// স্ট্যাট কার্ডের নিচে ছোট ট্রেন্ড-চিপ (বেড়েছে/কমেছে/অপরিবর্তিত)
function trend_chip(int $now, int $prev): string
{
    $diff = $now - $prev;
    if ($diff > 0) return '<span class="inline-flex items-center gap-0.5 text-green-600 font-semibold"><i data-lucide="trending-up" class="w-3.5 h-3.5"></i> +' . $diff . '</span>';
    if ($diff < 0) return '<span class="inline-flex items-center gap-0.5 text-red-600 font-semibold"><i data-lucide="trending-down" class="w-3.5 h-3.5"></i> ' . $diff . '</span>';
    return '<span class="inline-flex items-center gap-0.5 text-gray-400 font-semibold"><i data-lucide="minus" class="w-3.5 h-3.5"></i> অপরিবর্তিত</span>';
}

// ============================================================================
//  "আজ কী করতে হবে" — ছড়িয়ে থাকা কাজগুলো এক জায়গায় (২০২৬-০৯-২৪)
// ============================================================================
//  🔴 প্রতিটা গণনা আলাদা try/catch-এ (নিচের $taskCount ক্লোজার) — কোনো টেবিল না থাকলে বা
//  কোয়েরি ব্যর্থ হলে সেই কাজটা চুপচাপ বাদ যায়, ড্যাশবোর্ড ভাঙে না।
//  🔴 প্রতিটা কাজ admin_can_page() দিয়ে গার্ডেড — যে মডারেটরের ঐ পেজে অনুমতি নেই,
//  তিনি সংখ্যাটাও দেখেন না।
//  শূন্য হলে কাজটা দেখানো হয় না — সব শূন্য হলে পুরো সেকশনই আসে না (খালি তালিকা = স্বস্তি)।
$taskCount = function (string $sql) use ($db): ?int {
    try {
        return (int) $db->query($sql)->fetch()['c'];
    } catch (Throwable $e) {
        return null;
    }
};

$taskDefs = [
    ['page' => 'registrations.php', 'url' => 'registrations.php?status=pending', 'icon' => 'clock',
     'label' => 'পেন্ডিং অর্ডার', 'hint' => 'কনফার্ম করা বাকি', 'cls' => 'bg-orange-100 text-orange-500', 'num' => 'text-orange-500',
     'sql' => "SELECT COUNT(*) c FROM registrations WHERE status = 'pending'"],

    ['page' => 'users.php', 'url' => 'users.php?status=pending', 'icon' => 'user-plus',
     'label' => 'নতুন অভিভাবক অ্যাকাউন্ট', 'hint' => 'approve করা বাকি', 'cls' => 'bg-blue-100 text-blue-600', 'num' => 'text-blue-600',
     'sql' => "SELECT COUNT(*) c FROM users WHERE status = 'pending'"],

    ['page' => 'course-interests.php', 'url' => 'course-interests.php?status=new', 'icon' => 'heart-handshake',
     'label' => 'নতুন আগ্রহ', 'hint' => 'এখনো যোগাযোগ হয়নি', 'cls' => 'bg-pink-100 text-pink-600', 'num' => 'text-pink-600',
     'sql' => "SELECT COUNT(*) c FROM course_interests WHERE status = 'new'"],

    ['page' => 'course-parcel.php', 'url' => 'course-parcel.php', 'icon' => 'users',
     'label' => 'গ্রুপে যোগ করা বাকি', 'hint' => 'কনফার্ম হয়েছে, গ্রুপে নেই', 'cls' => 'bg-indigo-100 text-indigo-600', 'num' => 'text-indigo-600',
     'sql' => "SELECT COUNT(*) c FROM registrations WHERE type = 'course' AND status = 'confirmed'
                 AND courier_active = 1 AND fb_group_added = 0 AND messenger_group_added = 0"],

    ['page' => 'course-parcel.php', 'url' => 'course-parcel.php', 'icon' => 'user-minus',
     'label' => 'নিষ্ক্রিয় অথচ গ্রুপে', 'hint' => 'গ্রুপ থেকে বাদ দিতে হবে', 'cls' => 'bg-red-100 text-red-600', 'num' => 'text-red-600',
     'sql' => "SELECT COUNT(*) c FROM registrations WHERE courier_active = 0
                 AND (fb_group_added = 1 OR messenger_group_added = 1)"],

    ['page' => 'courier.php', 'url' => 'courier.php?batch_status=failed', 'icon' => 'truck',
     'label' => 'ব্যর্থ কুরিয়ার চালান', 'hint' => 'আবার পাঠাতে হবে', 'cls' => 'bg-red-100 text-red-600', 'num' => 'text-red-600',
     'sql' => "SELECT COUNT(*) c FROM courier_batches WHERE send_status = 'failed'"],
];

$tasks = [];
foreach ($taskDefs as $td) {
    if (!admin_can_page($td['page'])) {
        continue;
    }
    $n = $taskCount($td['sql']);
    if ($n === null || $n < 1) {
        continue;
    }
    $td['count'] = $n;
    $tasks[] = $td;
}

// ============================================================================
//  এই কোর্স-ব্যাচগুলোর পার্সেল কতদূর (২০২৬-০৯-২৪)
// ============================================================================
//  🔴 মাসের লেবেল pay_month_label() থেকেই নেওয়া হয় — course-parcel.php-এর cp_month_label()
//  ও courier_batches.period_label-এর সাথে হুবহু মিলতে হবে, নাহলে "কোন মাস চলছে" ভুল দেখাবে।
$parcelProgress = [];
if (admin_can_page('course-parcel.php')) {
    try {
        require_once __DIR__ . '/includes/payments.php';
        $pBatches = $db->query(
            "SELECT r.item_id, r.item_title, r.batch, cb.total_parcels, COUNT(DISTINCT r.id) students
             FROM registrations r
             JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0 AND cb.is_active = 1
             WHERE r.type = 'course' AND r.status = 'confirmed' AND r.courier_active = 1 AND cb.total_parcels > 0
             GROUP BY r.item_id, r.item_title, r.batch, cb.total_parcels
             ORDER BY r.item_title, r.batch"
        )->fetchAll();

        // প্রতি ব্যাচের প্রতি মাসে কতজনকে পাঠানো হয়েছে (এক কোয়েরিতেই, N+1 এড়াতে)
        $sentMap = [];
        foreach ($db->query(
            "SELECT r.item_id, b.period_label, COUNT(DISTINCT b.registration_id) c
             FROM courier_batches b JOIN registrations r ON r.id = b.registration_id
             WHERE b.send_status = 'sent' AND r.type = 'course'
             GROUP BY r.item_id, b.period_label"
        )->fetchAll() as $row) {
            $sentMap[(int) $row['item_id']][$row['period_label']] = (int) $row['c'];
        }

        foreach ($pBatches as $b) {
            $bid      = (int) $b['item_id'];
            $students = (int) $b['students'];
            $months   = (int) $b['total_parcels'];
            $sentTotal = 0;
            $curMonth  = 0;      // যে মাসের পাঠানো এখনো শেষ হয়নি
            $curSent   = 0;
            for ($i = 1; $i <= $months; $i++) {
                $c = (int) ($sentMap[$bid][pay_month_label($i)] ?? 0);
                $sentTotal += min($c, $students);
                if ($curMonth === 0 && $c < $students) {
                    $curMonth = $i;
                    $curSent  = $c;
                }
            }
            $need = $students * $months;
            $parcelProgress[] = [
                'id' => $bid, 'title' => $b['item_title'], 'batch' => $b['batch'],
                'students' => $students, 'months' => $months,
                'sent' => $sentTotal, 'need' => $need,
                'pct' => $need > 0 ? (int) round($sentTotal / $need * 100) : 0,
                'cur_month' => $curMonth, 'cur_sent' => $curSent,
            ];
        }
        // যেগুলোর কাজ বাকি সেগুলো আগে, তারপর অগ্রগতি অনুযায়ী
        usort($parcelProgress, fn($a, $b) => [$a['cur_month'] === 0 ? 1 : 0, -$a['pct']] <=> [$b['cur_month'] === 0 ? 1 : 0, -$b['pct']]);
    } catch (Throwable $e) {
        $parcelProgress = [];
    }
}

// ============================================================================
//  আগ্রহ তালিকার সারাংশ — কোন কোর্সে কতজন অপেক্ষায় (২০২৬-০৯-২৪)
// ============================================================================
//  ⚠️ start_when কলামটা migrate-course-interest-fields.sql-এ যোগ হয়েছে — না চালানো থাকলে
//  ঐ অংশটুকু নিজের try/catch-এ চুপচাপ বাদ যায় (বাকি সারাংশ তবু দেখায়)।
$interestTop = [];
$interestWhen = [];
$interestNewTotal = 0;
if (admin_can_page('course-interests.php')) {
    try {
        $interestTop = $db->query(
            "SELECT item_title, COUNT(*) total, SUM(status = 'new') new_cnt
             FROM course_interests
             WHERE item_title IS NOT NULL AND item_title <> ''
             GROUP BY item_title ORDER BY total DESC LIMIT 5"
        )->fetchAll();
        $interestNewTotal = (int) $db->query("SELECT COUNT(*) c FROM course_interests WHERE status = 'new'")->fetch()['c'];
    } catch (Throwable $e) {
        $interestTop = [];
    }
    try {
        foreach ($db->query(
            "SELECT start_when, COUNT(*) c FROM course_interests
             WHERE start_when <> '' AND status = 'new' GROUP BY start_when"
        )->fetchAll() as $row) {
            $interestWhen[$row['start_when']] = (int) $row['c'];
        }
    } catch (Throwable $e) {
        $interestWhen = [];   // মাইগ্রেশন চালানো হয়নি
    }
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
        Chart.defaults.font.family = "'Noto Sans Bengali', 'Inter', sans-serif";
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
        <p class="text-white/85 text-sm mb-5">আজকে <?= $todayRegistrations ?> টি নতুন রেজিস্ট্রেশন · <?= $pendingRegistrations ?> টি অপেক্ষমাণ অর্ডার<?= $hasVisitorStats ? ' · ' . $todayVisits . ' টি ভিজিট' : '' ?></p>
        <div class="flex flex-wrap gap-2.5">
            <a href="manage.php?entity=courses&action=form" class="inline-flex items-center gap-2 bg-white text-gray-800 font-bold px-4 py-2.5 rounded-xl text-sm shadow-sm hover:shadow-md"><i data-lucide="plus" class="w-4 h-4"></i> নতুন কোর্স</a>
            <a href="registrations.php" class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/30 text-white font-bold px-4 py-2.5 rounded-xl text-sm"><i data-lucide="clipboard-list" class="w-4 h-4"></i> রেজিস্ট্রেশন</a>
            <a href="courier.php" class="inline-flex items-center gap-2 bg-white/15 hover:bg-white/25 border border-white/30 text-white font-bold px-4 py-2.5 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4"></i> কুরিয়ার</a>
        </div>
    </div>
</div>

<!-- 📋 আজ কী করতে হবে — ছড়িয়ে থাকা কাজগুলো এক জায়গায়; শূন্য হলে কাজটা (সব শূন্য হলে পুরো সেকশন) দেখায় না -->
<?php if ($tasks): ?>
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h3 class="text-sm font-bold text-gray-500 mb-3 flex items-center gap-2"><i data-lucide="list-checks" class="w-4 h-4"></i> আজ কী করতে হবে</h3>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <?php foreach ($tasks as $t): ?>
        <a href="<?= e($t['url']) ?>" class="flex items-center gap-3 rounded-xl border border-gray-100 p-3 hover:shadow-md transition-all">
            <span class="inline-flex w-9 h-9 items-center justify-center rounded-xl flex-shrink-0 <?= $t['cls'] ?>"><i data-lucide="<?= e($t['icon']) ?>" class="w-4 h-4"></i></span>
            <span class="min-w-0 flex-1">
                <span class="block font-bold text-gray-800 text-sm leading-tight"><?= e($t['label']) ?></span>
                <span class="block text-gray-400 text-xs mt-0.5"><?= e($t['hint']) ?></span>
            </span>
            <span class="text-xl font-black flex-shrink-0 <?= $t['num'] ?>"><?= (int) $t['count'] ?></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- কাজের লঞ্চার — দরকারি কাজ এক ট্যাপে (সাইডবার হাতড়াতে হয় না)। রঙ-ক্লাস পূর্ণ লিটারাল (ডাইনামিক না) যাতে Tailwind স্ক্যানার ধরে -->
<?php
$quickTasks = [
    ['url' => 'manage.php?entity=courses',      'icon' => 'book-open',       'label' => 'কোর্স',           'desc' => 'কোর্স/ব্যাচ যোগ-এডিট', 'cls' => 'bg-indigo-100 text-indigo-600'],
    ['url' => 'registrations.php',               'icon' => 'clipboard-list',  'label' => 'অর্ডার',          'desc' => 'রেজিস্ট্রেশন দেখুন',    'cls' => 'bg-blue-100 text-blue-600'],
    ['url' => 'users.php',                       'icon' => 'users',           'label' => 'অভিভাবক',         'desc' => 'অ্যাকাউন্ট approve',    'cls' => 'bg-green-100 text-green-600'],
    ['url' => 'course-interests.php',            'icon' => 'heart-handshake', 'label' => 'আগ্রহ তালিকা',     'desc' => 'ওয়েটিং লিস্ট',         'cls' => 'bg-pink-100 text-pink-600'],
    ['url' => 'course-parcel.php',              'icon' => 'package-check',   'label' => 'কোর্স পার্সেল',    'desc' => 'গ্রুপ+পার্সেল ট্র্যাক', 'cls' => 'bg-amber-100 text-amber-600'],
    ['url' => 'courier.php',                     'icon' => 'truck',           'label' => 'কুরিয়ার',         'desc' => 'পাঠান ও ট্র্যাক',       'cls' => 'bg-purple-100 text-purple-600'],
    ['url' => 'finance.php',                     'icon' => 'pie-chart',       'label' => 'আয়-ব্যয়',         'desc' => 'হিসাব দেখুন',           'cls' => 'bg-emerald-100 text-emerald-600'],
    ['url' => 'guide.php',                       'icon' => 'help-circle',     'label' => 'গাইড / সাহায্য',   'desc' => 'কোনটা কীসের কাজ',      'cls' => 'bg-fuchsia-100 text-fuchsia-600'],
];
?>
<div class="mb-6">
    <h3 class="text-sm font-bold text-gray-500 mb-3 flex items-center gap-2"><i data-lucide="zap" class="w-4 h-4"></i> কী করতে চান?</h3>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        <?php foreach ($quickTasks as $t): ?>
        <a href="<?= e($t['url']) ?>" class="bg-white rounded-2xl shadow p-4 flex items-start gap-3 hover:shadow-lg hover:-translate-y-0.5 transition-all">
            <span class="inline-flex w-10 h-10 items-center justify-center rounded-xl flex-shrink-0 <?= $t['cls'] ?>"><i data-lucide="<?= e($t['icon']) ?>" class="w-5 h-5"></i></span>
            <div class="min-w-0">
                <p class="font-bold text-gray-800 text-base leading-tight"><?= e($t['label']) ?></p>
                <p class="text-gray-500 text-sm mt-0.5 leading-tight"><?= e($t['desc']) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-5 flex items-center gap-4">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-indigo-100 text-indigo-600"><i data-lucide="calendar-check" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-indigo-600 leading-none"><?= $todayRegistrations ?></div>
            <p class="text-gray-500 text-sm mt-1">আজকের রেজিস্ট্রেশন/অর্ডার</p>
            <p class="text-xs mt-1.5"><?= trend_chip($todayRegistrations, $yesterdayReg) ?> <span class="text-gray-400">গতকালের তুলনায়</span></p>
        </div>
    </div>
    <?php if ($hasVisitorStats && admin_can('logs')): ?>
    <a href="visitor-logs.php" class="bg-white rounded-2xl shadow p-5 flex items-center gap-4 hover:shadow-lg hover:-translate-y-0.5 transition-all">
        <span class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-purple-100 text-purple-600"><i data-lucide="eye" class="w-6 h-6"></i></span>
        <div>
            <div class="text-3xl font-black text-purple-600 leading-none"><?= $todayVisits ?></div>
            <p class="text-gray-500 text-sm mt-1">আজকের ভিজিট</p>
            <p class="text-xs mt-1.5"><?= trend_chip($todayVisits, $yesterdayVisits) ?> <span class="text-gray-400"><?= $todayUniqueIps ?> জন (ইউনিক IP)</span></p>
        </div>
    </a>
    <?php endif; ?>
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
        <div class="flex items-center justify-between mb-1">
            <h3 class="font-bold text-gray-800">গত ৭ দিনের রেজিস্ট্রেশন/অর্ডার</h3>
            <?php if ($hasVisitorStats && admin_can('logs')): ?><a href="visitor-logs.php" class="text-indigo-600 text-xs font-semibold">ভিজিটর লগ →</a><?php endif; ?>
        </div>
        <p class="text-gray-400 text-xs mb-4">কোর্স, ওয়ার্কশিট ও প্রোডাক্ট — কোন দিনে কতগুলো<?= $hasVisitorStats ? ' · সাথে ঐ দিনের ভিজিট (ডান পাশের স্কেল), গত ৭ দিনে মোট ' . $weekVisits . ' টি' : '' ?></p>
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

<?php if ($parcelProgress || $interestTop): ?>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
    <?php if ($parcelProgress): ?>
    <!-- 📦 পার্সেল কতদূর — চলমান কোর্স-ব্যাচে কত পার্সেল যাওয়ার কথা, কতটা গেছে -->
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex items-center justify-between mb-1">
            <h3 class="font-bold text-gray-800">পার্সেল কতদূর</h3>
            <a href="course-parcel.php" class="text-indigo-600 text-xs font-semibold">সব দেখুন →</a>
        </div>
        <p class="text-gray-400 text-xs mb-4">সক্রিয় শিক্ষার্থী × মোট মাস হিসাবে কতটা পাঠানো হয়েছে</p>
        <div class="space-y-3">
        <?php foreach (array_slice($parcelProgress, 0, 5) as $pp): ?>
            <a href="course-parcel.php?item_id=<?= (int) $pp['id'] ?><?= $pp['cur_month'] ? '&amp;month=' . (int) $pp['cur_month'] : '' ?>" class="block">
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="font-semibold text-gray-800 text-sm truncate"><?= e($pp['title']) ?> <span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($pp['batch'] ?: '—') ?></span></span>
                    <span class="text-xs font-bold text-gray-500 flex-shrink-0"><?= (int) $pp['sent'] ?>/<?= (int) $pp['need'] ?></span>
                </div>
                <div class="h-2 rounded-full bg-gray-100 overflow-hidden"><div class="h-2 rounded-full bg-indigo-600" style="width: <?= (int) $pp['pct'] ?>%"></div></div>
                <p class="text-xs text-gray-400 mt-1"><?= (int) $pp['students'] ?> জন · <?= (int) $pp['months'] ?> মাস · <?= $pp['cur_month']
                    ? 'এখন ' . e(pay_month_label((int) $pp['cur_month'])) . ' — ' . (int) $pp['cur_sent'] . '/' . (int) $pp['students'] . ' পাঠানো'
                    : '<span class="text-green-700 font-semibold">সব পাঠানো হয়ে গেছে ✓</span>' ?></p>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($interestTop): ?>
    <!-- 💚 আগ্রহ তালিকার সারাংশ — কোন কোর্সে কতজন অপেক্ষায় -->
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="flex items-center justify-between mb-1">
            <h3 class="font-bold text-gray-800">আগ্রহ তালিকা</h3>
            <a href="course-interests.php" class="text-indigo-600 text-xs font-semibold">সব দেখুন →</a>
        </div>
        <p class="text-gray-400 text-xs mb-4">কোন কোর্সে কতজন জানিয়ে রেখেছেন — নতুন ব্যাচ কবে খুলবেন সেই সিদ্ধান্তে কাজে লাগে<?= $interestNewTotal ? ' · ' . $interestNewTotal . ' জনের সাথে এখনো যোগাযোগ হয়নি' : '' ?></p>
        <?php if ($interestWhen): ?>
        <div class="flex flex-wrap gap-1.5 mb-4">
            <?php foreach (interest_timeframes() as $wKey => $wLabel): if (empty($interestWhen[$wKey])) { continue; } ?>
            <a href="course-interests.php?status=new&amp;when=<?= e($wKey) ?>" class="px-2 py-0.5 rounded-lg bg-pink-100 text-pink-700 text-xs font-semibold"><?= e($wLabel) ?> <?= (int) $interestWhen[$wKey] ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="space-y-2">
        <?php foreach ($interestTop as $it): ?>
            <a href="course-interests.php?item=<?= urlencode($it['item_title']) ?>" class="flex items-center justify-between gap-2 text-sm">
                <span class="text-gray-700 truncate"><?= e($it['item_title']) ?></span>
                <span class="flex-shrink-0 flex items-center gap-1.5">
                    <?php if ((int) $it['new_cnt']): ?><span class="px-2 py-0.5 rounded-lg bg-pink-100 text-pink-700 text-xs font-bold"><?= (int) $it['new_cnt'] ?> নতুন</span><?php endif; ?>
                    <span class="text-gray-400 text-xs">মোট <?= (int) $it['total'] ?></span>
                </span>
            </a>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

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
<?php if ($hasVisitorStats): ?>
            // ভিজিট আলাদা স্কেলে (y1, ডান পাশে) — রেজিস্ট্রেশনের চেয়ে সংখ্যা অনেক বড় হয় বলে একই স্কেলে দিলে বারগুলো চ্যাপ্টা দেখাত
            { type: 'line', label: 'ভিজিট', data: <?= json_encode(array_values($dailyVisits)) ?>, yAxisID: 'y1', borderColor: '#7c3aed', backgroundColor: '#7c3aed', borderWidth: 2, tension: .35, pointRadius: 3, fill: false, order: 0 },
<?php endif; ?>
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        scales: {
            x: { stacked: true },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
<?php if ($hasVisitorStats): ?>
            , y1: { position: 'right', beginAtZero: true, ticks: { precision: 0 }, grid: { drawOnChartArea: false } }
<?php endif; ?>
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
