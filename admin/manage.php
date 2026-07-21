<?php
// জেনেরিক কনটেন্ট ম্যানেজমেন্ট (CRUD) — courses/worksheets/products/teachers/reviews/notices/gallery/faqs
// সবকিছু admin/includes/entities.php এর কনফিগ অনুযায়ী চলে

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/entities.php';
require_once __DIR__ . '/includes/form-helpers.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();
$entities = get_entities();
$entityKey = $_GET['entity'] ?? '';

if (!isset($entities[$entityKey])) {
    http_response_code(404);
    exit('এই ধরনের কনটেন্ট পাওয়া যায়নি।');
}

$conf = $entities[$entityKey];
$table = $conf['table'];
$action = $_GET['action'] ?? 'list';

// ------------------------------------------------------------
// SAVE (Add অথবা Edit) — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
        redirect('manage.php?entity=' . urlencode($entityKey));
    }

    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $wasNewInsert = ($id === null); // ব্যাচ-ফর্মে স্মার্ট রিডাইরেক্টের জন্য পরে দরকার (নিচে, INSERT এ $id রিএসাইন হওয়ার আগেই ধরে রাখা)

    // কোর্সের টাইটেল এখন সম্পূর্ণ ইউনিক (courses টেবিলে UNIQUE constraint) — ব্যাচ এখন আলাদা টেবিলে
    // (course_batches, admin/course-batches.php তে পরিচালিত), তাই এখানে শুধু টাইটেল-ডুপ্লিকেট বন্ধুত্বপূর্ণভাবে চেক করা হয়
    if ($entityKey === 'courses') {
        $titleVal = trim($_POST['title'] ?? '');
        if ($titleVal !== '') {
            $dupStmt = $db->prepare('SELECT COUNT(*) c FROM courses WHERE title = :title AND id != :id');
            $dupStmt->execute(['title' => $titleVal, 'id' => $id ?? 0]);
            if ((int) $dupStmt->fetch()['c'] > 0) {
                set_flash('error', 'এই নামে আগে থেকেই একটা কোর্স আছে। ভিন্ন নাম দিন, অথবা বিদ্যমান কোর্সেই নতুন ব্যাচ যোগ করুন।');
                redirect('manage.php?entity=courses&action=form' . ($id ? '&id=' . $id : ''));
            }
        }
    }

    $columns = [];
    $lineFields = [];

    foreach ($conf['fields'] as $key => $f) {
        if ($f['type'] === 'lines') {
            $lineFields[$key] = $f;
            continue;
        }
        if ($f['type'] === 'checkbox') {
            $columns[$key] = isset($_POST[$key]) ? 1 : 0;
            continue;
        }
        if ($f['type'] === 'image') {
            $uploaded = null;
            try {
                $uploaded = handle_image_upload($key . '_file', $conf['upload_dir'] ?? 'misc');
            } catch (RuntimeException $e) {
                set_flash('error', $e->getMessage());
                redirect('manage.php?entity=' . urlencode($entityKey) . '&action=form' . ($id ? '&id=' . $id : ''));
            }
            $columns[$key] = $uploaded ?? trim($_POST[$key] ?? '');
            continue;
        }
        $val = trim($_POST[$key] ?? '');
        if ($val === '' && isset($f['auto_from']) && isset($_POST[$f['auto_from']])) {
            $val = make_slug($_POST[$f['auto_from']], $id ?? 0);
        }
        if ($val === '' && isset($f['default'])) {
            $val = $f['default'];
        }
        // auto_from ফিল্ড (যেমন slug) এর উপর DB তে UNIQUE constraint থাকে — একই টাইটেল থেকে একই স্লাগ তৈরি হয়ে
        // কনফ্লিক্ট করতে পারে (যেমন একই কোর্স-টাইটেলের ভিন্ন ব্যাচ), তাই কনফ্লিক্ট হলে সংখ্যা যোগ করে ইউনিক করা হয়
        if ($val !== '' && isset($f['auto_from'])) {
            $val = make_unique_value($db, $table, $key, $val, $id);
        }
        $columns[$key] = $val;
    }

    if ($id) {
        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($columns)));
        $stmt = $db->prepare("UPDATE `$table` SET $set WHERE id = :id");
        $columns['id'] = $id;
        $stmt->execute($columns);
    } else {
        $cols = array_keys($columns);
        $placeholders = array_map(fn($k) => ":$k", $cols);
        $stmt = $db->prepare("INSERT INTO `$table` (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")");
        $stmt->execute($columns);
        $id = (int) $db->lastInsertId();
    }

    // 'lines' টাইপ ফিল্ড (features) হ্যান্ডলিং — পুরনো সব মুছে নতুন করে ইনসার্ট
    foreach ($lineFields as $key => $f) {
        $db->prepare("DELETE FROM `{$f['child_table']}` WHERE `{$f['child_fk']}` = :id")->execute(['id' => $id]);
        $lines = array_filter(array_map('trim', explode("\n", $_POST[$key] ?? '')));
        $order = 1;
        foreach ($lines as $line) {
            $db->prepare("INSERT INTO `{$f['child_table']}` (`{$f['child_fk']}`, `{$f['child_col']}`, sort_order) VALUES (:fk, :val, :ord)")
               ->execute(['fk' => $id, 'val' => $line, 'ord' => $order++]);
        }
    }

    // নতুন কোর্স তৈরি করার সাথে সাথেই (এখনো কোনো ব্যাচ নেই বলে সাইটে/রেজিস্ট্রেশনে অকার্যকর) সরাসরি
    // "নতুন ব্যাচ যোগ করুন" ফর্মে নিয়ে যাওয়া — এডিটের সময় বা অন্য এন্টিটিতে এই স্মার্ট রিডাইরেক্ট প্রযোজ্য না
    if ($entityKey === 'courses' && $wasNewInsert) {
        set_flash('success', 'কোর্স তৈরি হয়েছে। এবার এর প্রথম ব্যাচ যোগ করুন।');
        redirect('course-batches.php?course_id=' . $id . '&action=form');
    }

    set_flash('success', $conf['label'] . ' সফলভাবে সেভ হয়েছে।');
    redirect('manage.php?entity=' . urlencode($entityKey));
}

// ------------------------------------------------------------
// DELETE — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('manage.php?entity=' . urlencode($entityKey));
    }
    $id = (int) ($_POST['id'] ?? 0);

    // ডিলিটের আগে আর্কাইভে রাখা হয় (রো + সব child) — "আর্কাইভ" পেজ থেকে হুবহু ফিরিয়ে আনা যায়।
    // ছবির ফাইল ইচ্ছাকৃতভাবে মোছা হয় না (রিস্টোরে দরকার হবে) — আগে এখানে delete_uploaded_image ছিল।
    archive_entity($db, $table, $id);
    $db->prepare("DELETE FROM `$table` WHERE id = :id")->execute(['id' => $id]);
    set_flash('success', $conf['label'] . ' আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect('manage.php?entity=' . urlencode($entityKey));
}

// ------------------------------------------------------------
// FORM ভিউ (Add / Edit) ডেটা লোড
// ------------------------------------------------------------
$editRow = null;
$formDefaults = [];
$fieldSuggestions = [];
if ($action === 'form') {
    $editId = !empty($_GET['id']) ? (int) $_GET['id'] : null;
    if ($editId) {
        $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = :id");
        $stmt->execute(['id' => $editId]);
        $editRow = $stmt->fetch();
        if (!$editRow) {
            redirect('manage.php?entity=' . urlencode($entityKey));
        }
        foreach ($conf['fields'] as $key => $f) {
            if ($f['type'] === 'lines') {
                $rows = $db->prepare("SELECT `{$f['child_col']}` FROM `{$f['child_table']}` WHERE `{$f['child_fk']}` = :id ORDER BY sort_order ASC");
                $rows->execute(['id' => $editId]);
                $editRow[$key] = implode("\n", array_column($rows->fetchAll(), $f['child_col']));
            }
        }
    }

    foreach ($conf['fields'] as $key => $f) {
        // নতুন এন্ট্রি যোগ করার সময় (এডিট না) auto_next ফিল্ডে পরবর্তী ক্রম নম্বর অটো বসানো — চাইলে বদলানো যাবে
        if (!$editRow && !empty($f['auto_next'])) {
            $next = $db->query("SELECT COALESCE(MAX(`$key`), 0) + 1 AS next_val FROM `$table`")->fetch();
            $formDefaults[$key] = (int) $next['next_val'];
        }
        // suggest => true ফিল্ডে আগে ব্যবহৃত মানগুলো দিয়ে datalist বানানো (ড্রপডাউনের মতো বেছে নেওয়া যায়, তবে নতুন মানও লেখা যায়)
        if (!empty($f['suggest'])) {
            $fieldSuggestions[$key] = $db->query(
                "SELECT DISTINCT `$key` FROM `$table` WHERE `$key` IS NOT NULL AND `$key` != '' ORDER BY `$key` ASC"
            )->fetchAll(PDO::FETCH_COLUMN);
        }
    }
}

// ------------------------------------------------------------
// LIST ভিউ ডেটা লোড
// ------------------------------------------------------------
$listRows = [];
if ($action === 'list') {
    $listRows = $db->query("SELECT * FROM `$table` ORDER BY {$conf['order_by']}")->fetchAll();
}

// এই entity তে 'image' টাইপ ফিল্ড থাকলে লিস্টে থাম্বনেইল দেখানো যাবে (নতুন করে প্রতিটা entity তে আলাদা কনফিগ লাগে না)
$imageField = null;
foreach ($conf['fields'] as $fKey => $f) {
    if ($f['type'] === 'image') {
        $imageField = $fKey;
        break;
    }
}
// শুধু ছবি-নির্ভর কনটেন্টের জন্য (যেমন গ্যালারি) থাম্বনেইল গ্রিড ভিউ — entities.php তে 'list_display' => 'grid' মার্ক করা থাকলে
$listDisplay = $conf['list_display'] ?? 'table';

// courses এন্টিটির জন্য বিশেষ: প্রতি কোর্সের ব্যাচ সারাংশ (course_batches থেকে) — মোট ব্যাচ, সক্রিয়
// (সাইটে দৃশ্যমান) ব্যাচ, রেজিস্ট্রেশন খোলা ব্যাচ, এবং সব ব্যাচ মিলিয়ে মোট রেজিস্ট্রেশন। যেহেতু কোর্স
// এখন শুধু parent (আসল তথ্য ব্যাচে), এই সারাংশই লিস্টে সবচেয়ে অর্থপূর্ণ "বিস্তারিত"। ব্যাচের আসল CRUD
// admin/course-batches.php তে ("ব্যাচসমূহ পরিচালনা" লিংক)।
$courseBatchStats = [];
$courseRegCounts = [];
$courseBatchDetails = [];   // course_id => [ব্যাচ রো, ...] — 🕘 আইকনে খোলা মডালের প্রি-রেন্ডার কন্টেন্টের জন্য
$batchRegCounts = [];       // batch_id => রেজিস্ট্রেশন সংখ্যা (মডালে প্রতি ব্যাচের পাশে দেখানোর জন্য)
if ($entityKey === 'courses' && $action === 'list') {
    foreach ($db->query(
        'SELECT course_id, COUNT(*) AS total, SUM(is_active = 1) AS active_cnt, SUM(registration_open = 1) AS open_cnt
         FROM course_batches GROUP BY course_id'
    )->fetchAll() as $r) {
        $courseBatchStats[$r['course_id']] = $r;
    }
    $batchRegCounts = array_column(
        $db->query("SELECT item_id, COUNT(*) c FROM registrations WHERE type = 'course' GROUP BY item_id")->fetchAll(),
        'c',
        'item_id'
    );
    $courseRegCounts = array_column(
        $db->query(
            "SELECT cb.course_id, COUNT(*) c FROM registrations r JOIN course_batches cb ON cb.id = r.item_id
             WHERE r.type = 'course' GROUP BY cb.course_id"
        )->fetchAll(),
        'c',
        'course_id'
    );
    foreach ($db->query(
        'SELECT id, course_id, batch_name, price, instructor, is_active, registration_open, hide_parcel, created_at
         FROM course_batches ORDER BY course_id, sort_order ASC, id ASC'
    )->fetchAll() as $b) {
        $courseBatchDetails[$b['course_id']][] = $b;
    }
}

$pageTitle = $conf['label_plural'];
require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($action === 'list'): ?>
    <div class="flex justify-between items-center gap-3 mb-3 flex-wrap">
        <p class="text-gray-500 text-sm">মোট <span id="listCount"><?= count($listRows) ?></span> টি</p>
        <a href="manage.php?entity=<?= e($entityKey) ?>&action=form" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-3 rounded-xl text-sm">+ নতুন <?= e($conf['label']) ?> যোগ করুন</a>
    </div>

    <?php // সার্চ বক্স এখানে লেখা নেই — `admin/includes/layout-bottom.php` এর অটো লিস্ট-সার্চ
          // ৫+ আইটেম হলে নিজেই বসিয়ে দেয় (সব অ্যাডমিন পেজে একইভাবে কাজ করে)। ?>

    <?php if ($listDisplay === 'grid'): ?>
    <!-- শুধু ছবি-নির্ভর কনটেন্টের জন্য (গ্যালারি) থাম্বনেইল গ্রিড ভিউ — তুলনা করার মতো তেমন টেক্সট ডেটা নেই বলে টেবিলের বদলে -->
    <?php if (!$listRows): ?>
        <div class="bg-white rounded-2xl shadow empty-state">
            <div class="empty-ic"><i data-lucide="inbox"></i></div>
            <p class="font-semibold text-gray-700 mb-1">এখনো কোনো <?= e($conf['label']) ?> নেই</p>
            <p class="text-sm mb-4">প্রথম <?= e($conf['label']) ?> যোগ করে শুরু করুন।</p>
            <a href="manage.php?entity=<?= e($entityKey) ?>&action=form" class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm"><i data-lucide="plus" class="w-4 h-4"></i> নতুন <?= e($conf['label']) ?> যোগ করুন</a>
        </div>
    <?php else: ?>
    <?php // মোবাইলে ১ কলাম, ছবি বাঁয়ে + তথ্য ডানে (আগে ২ কলামে ~১৭০px চওড়া কার্ড ছিল — ছবি ও লেখা
          // দুটোই চাপা পড়ত, পড়া/ট্যাপ করা কষ্ট হতো)। sm+ এ আগের মতোই থাম্বনেইল গ্রিড। ?>
    <div class="list-grid grid grid-cols-1 sm:grid-cols-3 lg:grid-cols-4 gap-3 sm:gap-4">
        <?php foreach ($listRows as $row): ?>
        <div class="list-item bg-white rounded-2xl shadow overflow-hidden flex sm:block">
            <?php if (!empty($row[$imageField])): ?>
                <img src="<?= e(admin_image_src($row[$imageField])) ?>" class="w-28 h-28 sm:w-full sm:h-36 object-cover flex-shrink-0" loading="lazy">
            <?php else: ?>
                <div class="w-28 h-28 sm:w-full sm:h-36 bg-gray-100 flex items-center justify-center text-gray-300 flex-shrink-0"><i data-lucide="image" class="w-8 h-8"></i></div>
            <?php endif; ?>
            <div class="p-3 min-w-0 flex-1 flex flex-col justify-center sm:block">
                <?php $isFirst = true; foreach ($conf['list_columns'] as $col): $val = $row[$col] ?? ''; if ($col === $imageField) continue; ?>
                    <p class="<?= $isFirst ? 'text-sm font-bold text-gray-900' : 'text-xs text-gray-500' ?> truncate"><?= e((string) $val) ?: '—' ?></p>
                <?php $isFirst = false; endforeach; ?>
                <div class="flex gap-2 mt-2 text-sm">
                    <a href="manage.php?entity=<?= e($entityKey) ?>&action=form&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold bg-indigo-50 px-3 py-1.5 rounded-lg">এডিট</a>
                    <form method="post" action="manage.php?entity=<?= e($entityKey) ?>&action=delete" class="inline" onsubmit="return confirmSubmit(this, 'আপনি কি নিশ্চিত এই <?= e($conf['label']) ?> ডিলিট করতে চান?', 'ডিলিট নিশ্চিতকরণ');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $row['id'] ?>">
                        <button type="submit" class="text-red-600 font-semibold bg-red-50 px-3 py-1.5 rounded-lg">ডিলিট</button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php else: ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <?php // class="mcard" — মোবাইল কার্ড-লেআউটে প্রথম কলামটা কার্ড-শিরোনাম হিসেবে রেন্ডার হবে
              // (layout-top.php এর @media CSS দেখুন), তাই অনেক কার্ডের মধ্যে খুঁজে পাওয়া সহজ ?>
        <table class="w-full text-sm mcard">
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <?php if ($imageField): ?><th class="py-3 px-4">ছবি</th><?php endif; ?>
                    <?php foreach ($conf['list_columns'] as $col): ?>
                        <th class="py-3 px-4"><?= e($conf['fields'][$col]['label']) ?></th>
                    <?php endforeach; ?>
                    <?php if ($entityKey === 'courses'): ?>
                        <th class="py-3 px-4">সক্রিয় ব্যাচ</th>
                        <th class="py-3 px-4">রেজিস্ট্রেশন খোলা</th>
                        <th class="py-3 px-4">মোট রেজিস্ট্রেশন</th>
                        <th class="py-3 px-4">ব্যাচ</th>
                    <?php endif; ?>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$listRows): ?>
                <tr><td colspan="<?= count($conf['list_columns']) + ($imageField ? 2 : 1) + ($entityKey === 'courses' ? 4 : 0) ?>">
                    <div class="empty-state">
                        <div class="empty-ic"><i data-lucide="inbox"></i></div>
                        <p class="font-semibold text-gray-700 mb-1">এখনো কোনো <?= e($conf['label']) ?> নেই</p>
                        <p class="text-sm mb-4">প্রথম <?= e($conf['label']) ?> যোগ করে শুরু করুন।</p>
                        <a href="manage.php?entity=<?= e($entityKey) ?>&action=form" class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm"><i data-lucide="plus" class="w-4 h-4"></i> নতুন <?= e($conf['label']) ?> যোগ করুন</a>
                    </div>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($listRows as $row): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <?php if ($imageField): ?>
                    <td class="py-2.5 px-4">
                        <?php if (!empty($row[$imageField])): ?>
                            <img src="<?= e(admin_image_src($row[$imageField])) ?>" class="w-12 h-12 object-cover rounded-lg border">
                        <?php else: ?>
                            <div class="w-12 h-12 bg-gray-100 rounded-lg border flex items-center justify-center text-gray-300"><i data-lucide="image" class="w-5 h-5"></i></div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                    <?php foreach ($conf['list_columns'] as $col):
                        $f = $conf['fields'][$col];
                        $val = $row[$col] ?? '';
                    ?>
                        <td class="py-2.5 px-4">
                        <?php if ($f['type'] === 'checkbox'): ?>
                            <?= $val ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?>
                        <?php else: ?>
                            <?= e((string) $val) ?>
                        <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($entityKey === 'courses'):
                        $stats = $courseBatchStats[$row['id']] ?? null;
                        $batchCount = (int) ($stats['total'] ?? 0);
                        $activeCount = (int) ($stats['active_cnt'] ?? 0);
                        $openCount = (int) ($stats['open_cnt'] ?? 0);
                        $regCount = (int) ($courseRegCounts[$row['id']] ?? 0);
                    ?>
                    <td class="py-2.5 px-4">
                        <?php if ($batchCount): ?>
                            <span class="<?= $activeCount ? 'text-green-600 font-semibold' : 'text-gray-400' ?>"><?= $activeCount ?></span>
                            <span class="text-gray-400"> / <?= $batchCount ?></span>
                        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4">
                        <?php if ($batchCount): ?>
                            <span class="<?= $openCount ? 'text-green-600 font-semibold' : 'text-gray-400' ?>"><?= $openCount ?></span>
                            <span class="text-gray-400"> / <?= $batchCount ?></span>
                        <?php else: ?><span class="text-gray-300">—</span><?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4">
                        <?php // course-data.php item_id (ব্যাচ-ভিত্তিক) দিয়ে ফিল্টার করে, parent course id দিয়ে না —
                        // তাই এখানে সব ব্যাচ মিলিয়ে মোট সংখ্যাটাই দেখানো হয়, ব্যাচ-ভিত্তিক ড্রিলডাউন "ব্যাচ" কলামে ?>
                        <?php if ($regCount): ?>
                            <span class="font-semibold text-gray-700"><?= $regCount ?> টি</span>
                        <?php else: ?><span class="text-gray-300">০</span><?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4">
                        <div class="flex items-center gap-2 whitespace-nowrap">
                            <a href="course-batches.php?course_id=<?= $row['id'] ?>" class="inline-flex items-center gap-1.5 text-indigo-600 font-semibold">
                                <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= $batchCount ?></span>
                                পরিচালনা
                            </a>
                            <a href="course-batches.php?course_id=<?= $row['id'] ?>&action=form" title="এই কোর্সে সরাসরি নতুন ব্যাচ যোগ করুন" class="inline-flex items-center gap-1 text-green-600 font-semibold whitespace-nowrap">
                                <i data-lucide="plus" class="w-4 h-4"></i> ব্যাচ
                            </a>
                            <?php if ($batchCount): ?>
                            <button type="button" onclick="showCourseBatches(<?= $row['id'] ?>)" title="ব্যাচের বিস্তারিত দেখুন" class="text-gray-300 hover:text-indigo-600 flex-shrink-0">
                                <i data-lucide="eye" class="w-4 h-4"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                    <?php endif; ?>
                    <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                        <a href="manage.php?entity=<?= e($entityKey) ?>&action=form&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">এডিট</a>
                        <form method="post" action="manage.php?entity=<?= e($entityKey) ?>&action=delete" class="inline" onsubmit="return confirmSubmit(this, '<?= $entityKey === 'courses' ? 'এই কোর্স ও এর সব ব্যাচ আর্কাইভে সরাতে চান? পরে আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।' : 'এই ' . e($conf['label']) . ' আর্কাইভে সরাতে চান? পরে ফিরিয়ে আনা যাবে।' ?>', 'আর্কাইভ নিশ্চিতকরণ');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $row['id'] ?>">
                            <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($entityKey === 'courses'): ?>
    <!-- প্রতিটা কোর্সের জন্য প্রি-রেন্ডার করা ব্যাচ-বিস্তারিত (হাইডেন) — 🕘 আইকনে ক্লিকে showCourseBatches()
         এই কন্টেন্ট শেয়ার্ড মডালে বসিয়ে দেয়, কোনো নতুন রিকোয়েস্ট/AJAX লাগে না (courier.php এর প্যাটার্ন) -->
    <?php foreach ($listRows as $row): if (empty($courseBatchDetails[$row['id']])) { continue; } ?>
    <div id="course-batches-content-<?= $row['id'] ?>" class="hidden" data-course-title="<?= e($row['title']) ?>">
        <?php foreach ($courseBatchDetails[$row['id']] as $b): $bReg = (int) ($batchRegCounts[$b['id']] ?? 0); ?>
        <div class="border-b last:border-0 py-3 text-sm">
            <div class="flex justify-between items-center gap-2">
                <span class="font-bold text-gray-800"><?= e($b['batch_name']) ?></span>
                <div class="flex items-center gap-1.5 flex-shrink-0">
                    <?php if ($b['is_active']): ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">সাইটে দৃশ্যমান</span>
                    <?php else: ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-gray-100 text-gray-500">হাইড</span><?php endif; ?>
                    <?php if ($b['registration_open']): ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-blue-100 text-blue-700">রেজি. খোলা</span>
                    <?php else: ?><span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-700">রেজি. বন্ধ</span><?php endif; ?>
                </div>
            </div>
            <div class="text-xs text-gray-500 mt-1.5 space-y-0.5">
                <div><i data-lucide="calendar" class="w-3 h-3 inline-block align-middle"></i> তৈরি হয়েছে: <?= e(format_date_bn($b['created_at'])) ?></div>
                <div>
                    মূল্য: <span class="font-semibold text-gray-700"><?= e($b['price'] ?: '—') ?></span>
                    · প্রশিক্ষক: <span class="font-semibold text-gray-700"><?= e($b['instructor'] ?: '—') ?></span>
                </div>
                <div>
                    রেজিস্ট্রেশন: <span class="font-semibold text-gray-700"><?= $bReg ?> টি</span>
                    <?php if ($b['hide_parcel']): ?> · <span class="text-purple-600">পার্সেল হাইড</span><?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div id="courseBatchesModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl shadow-xl max-w-md w-full max-h-[80vh] overflow-y-auto p-6">
            <div class="flex justify-between items-start mb-3">
                <h3 id="courseBatchesModalTitle" class="font-bold text-lg text-gray-800">ব্যাচের বিস্তারিত</h3>
                <button type="button" onclick="closeCourseBatchesModal()" class="text-gray-400 hover:text-gray-600"><i data-lucide="x" class="w-5 h-5"></i></button>
            </div>
            <div id="courseBatchesModalBody"></div>
            <a href="#" id="courseBatchesModalManageLink" class="inline-block mt-4 text-sm text-indigo-600 font-semibold">ব্যাচসমূহ পরিচালনা করুন →</a>
        </div>
    </div>

    <script>
        // এক ক্লিকে কোনো কোর্সের সব ব্যাচের বিস্তারিত দেখা — প্রি-রেন্ডার করা হাইডেন কন্টেন্ট শেয়ার্ড মডালে বসিয়ে
        // দেয় (courier.php এর showCourierHistory() এর প্যাটার্ন), আলাদা কোনো রিকোয়েস্ট লাগে না
        function showCourseBatches(id) {
            var content = document.getElementById('course-batches-content-' + id);
            var body = document.getElementById('courseBatchesModalBody');
            body.innerHTML = content ? content.innerHTML : '<p class="text-sm text-gray-400">কোনো ব্যাচ নেই।</p>';
            document.getElementById('courseBatchesModalTitle').textContent = content ? (content.getAttribute('data-course-title') + ' — ব্যাচসমূহ') : 'ব্যাচের বিস্তারিত';
            document.getElementById('courseBatchesModalManageLink').href = 'course-batches.php?course_id=' + id;
            document.getElementById('courseBatchesModal').classList.remove('hidden');
            if (window.lucide) { lucide.createIcons(); }
        }
        function closeCourseBatchesModal() {
            document.getElementById('courseBatchesModal').classList.add('hidden');
        }
    </script>
    <?php endif; ?>

    <?php endif; ?>

<?php elseif ($action === 'form'): ?>
    <div class="bg-white rounded-2xl shadow p-6 max-w-2xl">
        <form method="post" action="manage.php?entity=<?= e($entityKey) ?>&action=save" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= $editRow['id'] ?>"><?php endif; ?>
            <?php foreach ($conf['fields'] as $key => $f):
                $value = $editRow[$key] ?? ($formDefaults[$key] ?? ($f['default'] ?? ''));
            ?>
                <div><?= render_field($key, $f, $value, $fieldSuggestions[$key] ?? []) ?></div>
            <?php endforeach; ?>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
                <a href="manage.php?entity=<?= e($entityKey) ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-xl">বাতিল</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
