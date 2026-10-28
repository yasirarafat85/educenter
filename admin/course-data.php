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

// ডুপ্লিকেট কী — একই কোর্স+ব্যাচ+শিশুর নাম+মায়ের মোবাইল
function cd_dup_key(array $r): string
{
    return ($r['item_id'] ?? '') . '|' . ($r['batch'] ?? '') . '|' . ($r['customer_name'] ?? '') . '|' . ($r['phone'] ?? '');
}

// কোন সারিতে বেশি তথ্য ভরা — কোনটা রাখা হবে ঠিক করতে (legacy-students.php-এর ls_complete_score-এর
// হুবহু নীতি, কিন্তু registrations-এর কলাম ধরে)
function cd_complete_score(array $r): int
{
    $cols = ['customer_name', 'phone', 'facebook_id', 'father_mobile', 'receiver_name',
        'receiver_phone', 'date_of_birth', 'address', 'email', 'notes', 'admin_note'];
    $n = 0;
    foreach ($cols as $c) {
        if (trim((string) ($r[$c] ?? '')) !== '') {
            $n++;
        }
    }
    return $n;
}

// 🔴 ডুপ্লিকেট শনাক্তকরণ ও "কোনটা রাখা হবে/অতিরিক্ত" — কোর্স+ব্যাচ অনুযায়ী স্কোপ করা (সার্চ বাদে),
// যাতে পুরো দল ধরে হিসাব হয় (সার্চ-ছাঁকা আংশিক সারিতে নয়) আর টগলের সংখ্যা চলতি কোর্সেরই দেখায়।
$dupWhere = ["type = 'course'"];
$dupParams = [];
if ($filterCourse) {
    $dupWhere[] = 'item_id = :course_id';
    $dupParams['course_id'] = $filterCourse;
}
if ($filterBatch !== '') {
    $dupWhere[] = 'batch = :batch';
    $dupParams['batch'] = $filterBatch;
}
$dupScan = $db->prepare('SELECT * FROM registrations WHERE ' . implode(' AND ', $dupWhere));
$dupScan->execute($dupParams);

$cdGroups = [];
foreach ($dupScan->fetchAll() as $r) {
    $cdGroups[cd_dup_key($r)][] = $r;
}

$duplicateKeys = [];   // key => true (২+ সদস্যের দল)
$dupRole = [];         // registration id => 'keep' | 'extra'
$dupGroupCount = 0;    // চলতি স্কোপে কয়টা ডুপ্লিকেট দল
foreach ($cdGroups as $k => $members) {
    if (count($members) < 2) {
        continue;
    }
    $duplicateKeys[$k] = true;
    $dupGroupCount++;
    // keeper = সবচেয়ে বেশি তথ্য-ভরা সারি; সমান হলে ছোট id (পুরনোটি)
    usort($members, function ($a, $b) {
        $sa = cd_complete_score($a);
        $sb = cd_complete_score($b);
        if ($sa !== $sb) {
            return $sb - $sa;
        }
        return (int) $a['id'] - (int) $b['id'];
    });
    foreach ($members as $i => $m) {
        $dupRole[$m['id']] = $i === 0 ? 'keep' : 'extra';
    }
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

// 🔴 একই দলের সদস্যরা পাশাপাশি আসে (customer_name+phone যোগ করায়) — ডুপ্লিকেট ভিউতে চোখে বোঝা সহজ হয়
$sql = 'SELECT * FROM registrations WHERE ' . implode(' AND ', $where)
    . ' ORDER BY item_title ASC, batch ASC, customer_name ASC, phone ASC, created_at ASC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

if ($onlyDuplicates) {
    $rows = array_values(array_filter($rows, function ($row) use ($duplicateKeys) {
        return isset($duplicateKeys[cd_dup_key($row)]);
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
        <?php if ($dupGroupCount): ?>⚠️<?php endif; ?> <?= $onlyDuplicates ? 'সব দেখান' : ($filterCourse ? 'এই কোর্সে ডুপ্লিকেট দেখান' : 'শুধু ডুপ্লিকেট দেখান') ?> (<?= $dupGroupCount ?>)
    </a>
</div>

<?php if ($onlyDuplicates): ?>
    <div class="bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 mb-4 text-sm text-amber-800">
        প্রতিটি দলে সবচেয়ে বেশি তথ্য-ভরা সারিটি <span class="font-semibold text-green-700">✅ রাখা হবে</span> (সমান হলে পুরনোটি),
        বাকিগুলো <span class="font-semibold text-red-600">🗑️ অতিরিক্ত</span> হিসেবে চিহ্নিত।
        <span class="font-semibold">ডিলিট নিজে থেকে হয় না</span> — কোন সারিটা রাখবেন নিজে দেখে প্রতিটি সারির “ডিলিট” বোতাম থেকে মুছুন
        (এখানে আয়/কুরিয়ারের হিসাব জড়িত বলে এক-ক্লিকে সব মোছার সুবিধা ইচ্ছাকৃতভাবে রাখা হয়নি)।
        <?php if ($filterCourse): ?>
            <br>এই তালিকা শুধু বাছাই করা কোর্সের<?= $filterBatch !== '' ? ' (' . e($filterBatch) . ' ব্যাচের)' : '' ?> ডুপ্লিকেট দেখাচ্ছে।
        <?php else: ?>
            <br>উপরের ড্রপডাউন থেকে কোর্স বাছলে শুধু সেই কোর্সের ডুপ্লিকেট দেখবেন।
        <?php endif; ?>
    </div>
<?php endif; ?>

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
            $isDuplicate = isset($duplicateKeys[cd_dup_key($row)]);
            $role = $dupRole[$row['id']] ?? '';
            $rowBg = '';
            if ($isDuplicate) {
                $rowBg = $role === 'keep' ? 'bg-green-50' : 'bg-red-50';
            }
        ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $rowBg ?>">
                <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                <td class="py-2.5 px-4"><?= e($row['batch'] ?: '-') ?></td>
                <td class="py-2.5 px-4">
                    <?= e($row['customer_name']) ?>
                    <?php if ($isDuplicate && $role === 'keep'): ?>
                        <span class="ml-1 text-xs font-semibold text-green-700" title="এই দলে সবচেয়ে বেশি তথ্য-ভরা সারি — এটি রাখুন">✅ রাখা হবে</span>
                    <?php elseif ($isDuplicate): ?>
                        <span class="ml-1 text-xs font-semibold text-red-600" title="একই কোর্স+ব্যাচ+শিশুর নাম+মায়ের মোবাইলে একাধিক রেজিস্ট্রেশন — অতিরিক্ত সারি">🗑️ অতিরিক্ত</span>
                    <?php endif; ?>
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
