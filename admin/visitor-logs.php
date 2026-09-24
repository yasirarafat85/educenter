<?php
// পাবলিক সাইটের ভিজিটর লগ (includes/site-header.php থেকে প্রতিটা পেজ-লোডে সেভ হয়) — ডাউনলোড লগ থেকে সম্পূর্ণ আলাদা

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'ভিজিটর লগ';

// 🤖 বট/ক্রলার বাদ দিয়ে গণনা (২০২৬-০৯-২৪) — গুগল/ফেসবুকের ক্রলার আর আপটাইম-মনিটর
// আগে সব গোনা হতো বলে সংখ্যাটা বাস্তবের চেয়ে অনেক বড় দেখাত। শর্তটা শেয়ার্ড
// (visitor_human_sql) — ড্যাশবোর্ড ও এই পেজ সবসময় একই নিয়মে গোনে।
$totalAll = (int) $db->query('SELECT COUNT(*) c FROM visitor_logs')->fetch()['c'];
$todayAll = (int) $db->query('SELECT COUNT(*) c FROM visitor_logs WHERE DATE(visited_at) = CURDATE()')->fetch()['c'];

$humanSql = visitor_human_sql();
$botFilterOk = true;
try {
    $totalVisits = (int) $db->query("SELECT COUNT(*) c FROM visitor_logs WHERE $humanSql")->fetch()['c'];
    $todayVisits = (int) $db->query("SELECT COUNT(*) c FROM visitor_logs WHERE DATE(visited_at) = CURDATE() AND $humanSql")->fetch()['c'];
    $uniqueIps   = (int) $db->query("SELECT COUNT(DISTINCT ip_address) c FROM visitor_logs WHERE $humanSql")->fetch()['c'];
} catch (PDOException $ex) {
    // পুরনো MySQL/MariaDB-তে REGEXP না চললে আগের মতোই সব গোনা হয় (পেজ ভাঙে না)
    $botFilterOk = false;
    $totalVisits = $totalAll;
    $todayVisits = $todayAll;
    $uniqueIps   = (int) $db->query('SELECT COUNT(DISTINCT ip_address) c FROM visitor_logs')->fetch()['c'];
}
$botTotal = max(0, $totalAll - $totalVisits);
$botToday = max(0, $todayAll - $todayVisits);

// ডেটা বেশি হয়ে গেলে পুরো টেবিল লোড না করে সাম্প্রতিক ৩০০টা দেখানো হয়
$rows = $db->query('SELECT * FROM visitor_logs ORDER BY visited_at DESC LIMIT 300')->fetchAll();

require __DIR__ . '/includes/layout-top.php';
?>

<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-indigo-600"><?= $todayVisits ?></div>
        <p class="text-gray-500 text-sm mt-1">আজকের ভিজিট<?= $botFilterOk ? ' (বট বাদে)' : '' ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-green-600"><?= $totalVisits ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট ভিজিট (সর্বকালের)<?= $botFilterOk ? ' (বট বাদে)' : '' ?></p>
    </div>
    <div class="bg-white rounded-2xl shadow p-5">
        <div class="text-3xl font-black text-purple-600"><?= $uniqueIps ?></div>
        <p class="text-gray-500 text-sm mt-1">ইউনিক IP</p>
    </div>
</div>

<p class="text-sm text-gray-500 mb-4">সাম্প্রতিক ৩০০টা ভিজিট দেখানো হচ্ছে (নতুন থেকে পুরনো)। <?php if ($botFilterOk): ?><span class="text-gray-400">উপরের সংখ্যায় <strong><?= $botTotal ?></strong> টি বট/ক্রলার ভিজিট বাদ দেওয়া হয়েছে (আজ <?= $botToday ?> টি) — নিচের তালিকায় সেগুলো 🤖 চিহ্নে দেখানো হচ্ছে। অ্যাডমিনে লগইন থাকা অবস্থায় আপনার নিজের ভিজিটও আর গোনা হয় না।</span><?php endif; ?></p>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">IP</th>
                <th class="py-3 px-4">পেজ</th>
                <th class="py-3 px-4">ব্রাউজার/ডিভাইস</th>
                <th class="py-3 px-4">সময়</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="4" class="py-6 px-4 text-center text-gray-400">এখনো কোনো ভিজিট রেকর্ড হয়নি।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row): ?>
            <tr class="border-b last:border-0 hover:bg-gray-50">
                <td class="py-2.5 px-4 font-mono text-xs whitespace-nowrap"><?= e(format_ip_display($row['ip_address'])) ?></td>
                <td class="py-2.5 px-4 max-w-xs truncate" title="<?= e($row['page_url']) ?>"><?= e($row['page_url']) ?></td>
                <td class="py-2.5 px-4 max-w-xs truncate text-gray-500 text-xs" title="<?= e($row['user_agent']) ?>"><?php if (is_bot_user_agent($row['user_agent'])): ?><span class="px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 text-xs font-semibold" title="বট/ক্রলার — গণনায় ধরা হয়নি">🤖</span><?php endif; ?><?= e($row['user_agent'] ?: '-') ?></td>
                <td class="py-2.5 px-4 whitespace-nowrap"><?= e($row['visited_at']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
