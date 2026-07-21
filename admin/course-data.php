<?php
// কোর্স রেজিস্ট্রেশনের মূল ডেটা টেবিল — কোর্স নাম ও ব্যাচ অনুযায়ী ম্যানেজ করার জন্য
// এখান থেকে ভিউ/এডিট/ডিলিট করা যায় (registrations.php এর একই action গুলো রিইউজ করে) এবং
// ডুপ্লিকেট রেজিস্ট্রেশন (একই কোর্স + ব্যাচ + শিশুর নাম + মায়ের মোবাইল) হাইলাইট করে দেখায়

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'ডেটা টেবিল';

$statusLabels = [
    'pending'   => ['পেন্ডিং', 'bg-yellow-100 text-yellow-800'],
    'confirmed' => ['কনফার্ম', 'bg-blue-100 text-blue-800'],
    'shipped'   => ['পাঠানো হয়েছে', 'bg-purple-100 text-purple-800'],
    'delivered' => ['ডেলিভার্ড', 'bg-green-100 text-green-800'],
    'cancelled' => ['বাতিল', 'bg-red-100 text-red-800'],
];

$filterCourse = (int) ($_GET['course_id'] ?? 0);
$filterBatch = trim($_GET['batch'] ?? '');
$onlyDuplicates = isset($_GET['duplicates']);
$search = trim($_GET['q'] ?? '');

// courses/course_batches টেবিল জয়েন করার দরকার নেই — registrations.item_id/item_title ইতিমধ্যেই
// রেজিস্ট্রেশনের সময় স্ন্যাপশট নেওয়া, তাই সরাসরি distinct list বানানো যায় (কোর্স/ব্যাচ পরে ডিলিট/রিনেম
// হলেও পুরনো রেজিস্ট্রেশনের ফিল্টার-অপশন ঠিক থাকে)
$courseOptions = $db->query(
    "SELECT DISTINCT item_id AS id, item_title AS title FROM registrations WHERE type = 'course' ORDER BY item_title ASC"
)->fetchAll();

$batchOptions = $db->query(
    "SELECT DISTINCT batch FROM registrations WHERE type = 'course' AND batch IS NOT NULL AND batch != '' ORDER BY batch ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ডুপ্লিকেট কী সেট বানানো — একই কোর্স+ব্যাচ+শিশুর নাম+মায়ের মোবাইল একাধিকবার থাকলে
$dupStmt = $db->query(
    "SELECT item_id, batch, customer_name, phone, COUNT(*) c
     FROM registrations
     WHERE type = 'course'
     GROUP BY item_id, batch, customer_name, phone
     HAVING COUNT(*) > 1"
);
$duplicateKeys = [];
foreach ($dupStmt->fetchAll() as $d) {
    $duplicateKeys[$d['item_id'] . '|' . $d['batch'] . '|' . $d['customer_name'] . '|' . $d['phone']] = true;
}

$where = ["type = 'course'"];
$params = [];
if ($filterCourse) {
    $where[] = 'item_id = :course_id';
    $params['course_id'] = $filterCourse;
}
if ($filterBatch !== '') {
    $where[] = 'batch = :batch';
    $params['batch'] = $filterBatch;
}
if ($search !== '') {
    $where[] = '(customer_name LIKE :q1 OR phone LIKE :q2 OR facebook_id LIKE :q3)';
    $like = '%' . $search . '%';
    $params['q1'] = $like;
    $params['q2'] = $like;
    $params['q3'] = $like;
}

$sql = 'SELECT * FROM registrations WHERE ' . implode(' AND ', $where) . ' ORDER BY item_title ASC, batch ASC, created_at ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($onlyDuplicates) {
    $rows = array_values(array_filter($rows, function ($row) use ($duplicateKeys) {
        return isset($duplicateKeys[$row['item_id'] . '|' . $row['batch'] . '|' . $row['customer_name'] . '|' . $row['phone']]);
    }));
}

$currentListUrl = 'course-data.php?' . http_build_query(array_filter([
    'course_id' => $filterCourse ?: null,
    'batch' => $filterBatch !== '' ? $filterBatch : null,
    'duplicates' => $onlyDuplicates ? 1 : null,
    'q' => $search !== '' ? $search : null,
]));

require __DIR__ . '/includes/layout-top.php';
?>

<div class="flex flex-wrap gap-2 mb-4 items-center">
    <form method="get" action="course-data.php" class="flex flex-wrap gap-2 items-center">
        <select name="course_id" onchange="this.form.submit()" class="border rounded-xl px-3 py-2 text-sm">
            <option value="">সব কোর্স</option>
            <?php foreach ($courseOptions as $c): ?>
                <option value="<?= $c['id'] ?>" <?= $filterCourse === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['title']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="batch" onchange="this.form.submit()" class="border rounded-xl px-3 py-2 text-sm">
            <option value="">সব ব্যাচ</option>
            <?php foreach ($batchOptions as $b): ?>
                <option value="<?= e($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= e($b) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="শিশুর নাম, ফোন বা ফেসবুক আইডি..." class="border rounded-xl px-4 py-2 text-sm">
        <?php if ($onlyDuplicates): ?><input type="hidden" name="duplicates" value="1"><?php endif; ?>
        <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-4 py-2 rounded-xl text-sm">সার্চ</button>
    </form>
    <a href="course-data.php?<?= http_build_query(array_filter(['course_id' => $filterCourse ?: null, 'batch' => $filterBatch !== '' ? $filterBatch : null, 'q' => $search !== '' ? $search : null, 'duplicates' => $onlyDuplicates ? null : 1])) ?>"
       class="px-4 py-2 rounded-xl text-sm font-semibold <?= $onlyDuplicates ? 'bg-red-600 text-white' : 'bg-red-100 text-red-700' ?>">
        <?php if (count($duplicateKeys)): ?>⚠️<?php endif; ?> <?= $onlyDuplicates ? 'সব দেখান' : 'শুধু ডুপ্লিকেট দেখান' ?> (<?= count($duplicateKeys) ?>)
    </a>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কোর্স</th>
                <th class="py-3 px-4">ব্যাচ</th>
                <th class="py-3 px-4">শিশুর নাম</th>
                <th class="py-3 px-4">মোবাইল নাম্বার (মা)</th>
                <th class="py-3 px-4">ফেসবুক আইডি নাম</th>
                <th class="py-3 px-4">বাবার মোবাইল</th>
                <th class="py-3 px-4">রিসিভার নাম</th>
                <th class="py-3 px-4">রিসিভার নাম্বার</th>
                <th class="py-3 px-4">জন্ম তারিখ</th>
                <th class="py-3 px-4">স্ট্যাটাস</th>
                <th class="py-3 px-4">আয়</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="13" class="py-6 px-4 text-center text-gray-400">কোনো ডেটা নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $s = $statusLabels[$row['status']] ?? ['?', 'bg-gray-100'];
            $isDuplicate = isset($duplicateKeys[$row['item_id'] . '|' . $row['batch'] . '|' . $row['customer_name'] . '|' . $row['phone']]);
        ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $isDuplicate ? 'bg-red-50' : '' ?>">
                <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['batch'] ?: '-') ?></td>
                <td class="py-2.5 px-4">
                    <?= e($row['customer_name']) ?>
                    <?php if ($isDuplicate): ?><span class="ml-1 text-xs font-semibold text-red-600" title="একই কোর্স+ব্যাচ+শিশুর নাম+মায়ের মোবাইলে একাধিক রেজিস্ট্রেশন">⚠️ ডুপ্লিকেট</span><?php endif; ?>
                </td>
                <td class="py-2.5 px-4"><?= e($row['phone']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['facebook_id'] ?: '-') ?></td>
                <td class="py-2.5 px-4"><?= e($row['father_mobile'] ?: '-') ?></td>
                <td class="py-2.5 px-4"><?= e($row['receiver_name'] ?: '-') ?></td>
                <td class="py-2.5 px-4"><?= e($row['receiver_phone'] ?: '-') ?></td>
                <td class="py-2.5 px-4"><?= $row['date_of_birth'] ? e(format_date_bn($row['date_of_birth'])) : '-' ?></td>
                <td class="py-2.5 px-4"><span class="px-3 py-1 rounded-full text-xs font-semibold <?= $s[1] ?>"><?= e($s[0]) ?></span></td>
                <td class="py-2.5 px-4">
                    <?php if ($row['income_approved']): ?>
                        <span class="text-green-700 font-semibold text-xs">✅ ৳<?= number_format((float) $row['income_amount'], 2) ?></span>
                    <?php else: ?>
                        <span class="text-gray-300 text-xs">—</span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4"><?= e($row['created_at']) ?></td>
                <td class="py-2.5 px-4 whitespace-nowrap space-x-2">
                    <a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a>
                    <a href="registrations.php?action=edit&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">এডিট</a>
                    <form method="post" action="registrations.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, 'এই রেজিস্ট্রেশনটি ডিলিট করতে চান? এটা আর ফিরিয়ে আনা যাবে না।', 'ডিলিট নিশ্চিতকরণ');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                        <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
