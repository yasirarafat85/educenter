<?php
// একটা নির্দিষ্ট কোর্সের (parent, courses টেবিল) সব ব্যাচ (child, course_batches টেবিল) পরিচালনা —
// admin/manage.php এর জেনেরিক CRUD ইঞ্জিনের বাইরে একটা কাস্টম পেজ (course-data.php/courier.php এর
// প্যাটার্নে), কারণ এখানে parent এর ভেতরে child এর নেস্টেড লিস্ট+ফর্ম দরকার যেটা জেনেরিক ইঞ্জিন সাপোর্ট করে না।
// দাম/ইনস্ট্রাক্টর/ছবি/বিবরণ/ফিচার/hide_parcel/registration_open/is_active — সবগুলোই ব্যাচ-ভিত্তিক
// (কোর্স-ভিত্তিক না), তাই প্রতিটা ব্যাচের নিজস্ব সম্পূর্ণ ফর্ম থাকে।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form-helpers.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();
$action = $_GET['action'] ?? 'list';

$courseId = (int) ($_GET['course_id'] ?? ($_POST['course_id'] ?? 0));
$courseStmt = $db->prepare('SELECT * FROM courses WHERE id = :id');
$courseStmt->execute(['id' => $courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    set_flash('error', 'কোর্স পাওয়া যায়নি।');
    redirect('manage.php?entity=courses');
}

$pageTitle = 'ব্যাচ পরিচালনা — ' . $course['title'];

// ব্যাচের ফর্ম ফিল্ড কনফিগ — admin/includes/entities.php এর মতোই গঠন, render_field() (form-helpers.php)
// দিয়ে রেন্ডার হয়, শুধু course-batches.php নিজেই save/load হ্যান্ডেল করে (জেনেরিক manage.php ইঞ্জিন ছাড়া)
$batchFields = [
    'batch_name' => ['label' => 'ব্যাচের নাম (যেমন: ৫ম ব্যাচ, July_26 — আগে ব্যবহৃত নাম থেকে বেছে নিতে পারবেন বা নতুন লিখতে পারবেন)', 'type' => 'text', 'required' => true],
    'image' => ['label' => 'ছবি', 'type' => 'image'],
    'price' => ['label' => 'মূল্য (যেমন ৳২,৫০০)', 'type' => 'text'],
    'duration' => ['label' => 'মেয়াদ (যেমন ৩ মাস)', 'type' => 'text'],
    'instructor' => ['label' => 'প্রশিক্ষক', 'type' => 'text'],
    'description' => ['label' => 'বিবরণ', 'type' => 'textarea'],
    'features' => ['label' => 'বৈশিষ্ট্য (প্রতি লাইনে একটি)', 'type' => 'lines'],
    'hide_parcel' => ['label' => 'পার্সেল হাইড (Yes হলে রেজিস্ট্রেশন ফর্মে রিসিভার নাম/নম্বর/ঠিকানা হাইড থাকবে — ফুল অনলাইন ব্যাচের জন্য)', 'type' => 'checkbox', 'default' => 0, 'warn_off' => true, 'toggle_label' => 'পার্সেল হাইড'],
    'registration_open' => ['label' => 'রেজিস্ট্রেশন খোলা (Running)? — বন্ধ (No) করলে ব্যাচ সাইটে দেখাবে কিন্তু নতুন রেজিস্ট্রেশন নেওয়া যাবে না', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'রেজিস্ট্রেশন খোলা রাখা'],
    'is_active' => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'ব্যাচটি সাইটে দেখানো'],
    'sort_order' => ['label' => 'ক্রম নম্বর (এই কোর্সের ব্যাচগুলোর মধ্যে, ছোট সংখ্যা আগে দেখাবে, অটো বসে)', 'type' => 'number', 'default' => 0],
];

// ------------------------------------------------------------
// SAVE (Add অথবা Edit ব্যাচ) — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
        redirect('course-batches.php?course_id=' . $courseId);
    }

    $batchId = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $batchNameVal = trim($_POST['batch_name'] ?? '');

    if ($batchNameVal === '') {
        set_flash('error', 'ব্যাচের নাম দিন।');
        redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
    }

    // এই কোর্সে (course_id) একই ব্যাচ-নাম দুইবার হবে না — অন্য কোর্সে একই নাম সমস্যা না
    $dupStmt = $db->prepare('SELECT COUNT(*) c FROM course_batches WHERE course_id = :cid AND batch_name = :bn AND id != :id');
    $dupStmt->execute(['cid' => $courseId, 'bn' => $batchNameVal, 'id' => $batchId ?? 0]);
    if ((int) $dupStmt->fetch()['c'] > 0) {
        set_flash('error', 'এই কোর্সে এই নামে আগে থেকেই একটা ব্যাচ আছে। ভিন্ন নাম দিন।');
        redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
    }

    $columns = ['course_id' => $courseId];

    foreach ($batchFields as $key => $f) {
        if ($f['type'] === 'lines') {
            continue; // features আলাদাভাবে নিচে হ্যান্ডেল হয়
        }
        if ($f['type'] === 'checkbox') {
            $columns[$key] = isset($_POST[$key]) ? 1 : 0;
            continue;
        }
        if ($f['type'] === 'image') {
            $uploaded = null;
            try {
                $uploaded = handle_image_upload($key . '_file', 'courses');
            } catch (RuntimeException $e) {
                set_flash('error', $e->getMessage());
                redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
            }
            $columns[$key] = $uploaded ?? trim($_POST[$key] ?? '');
            continue;
        }
        $val = trim($_POST[$key] ?? '');
        if ($val === '' && isset($f['default'])) {
            $val = $f['default'];
        }
        $columns[$key] = $val;
    }

    // slug — কোর্সের টাইটেল + ব্যাচের নাম থেকে অটো, গ্লোবালি ইউনিক (একই কোর্স-টাইটেলের ভিন্ন ব্যাচের
    // জন্য একই স্লাগ তৈরি হতে পারে বলে কনফ্লিক্ট হলে -2, -3 ... যোগ হয়)
    $slugBase = make_slug($course['title'] . '-' . $batchNameVal, $batchId ?? 0);
    $columns['slug'] = make_unique_value($db, 'course_batches', 'slug', $slugBase, $batchId);

    // sort_order — নতুন ব্যাচে খালি রাখলে এই কোর্সের মধ্যে অটো পরবর্তী নম্বর বসে (গ্লোবাল না, course-স্কোপড)
    if (!$batchId && (int) ($columns['sort_order'] ?? 0) === 0) {
        $next = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_val FROM course_batches WHERE course_id = :cid');
        $next->execute(['cid' => $courseId]);
        $columns['sort_order'] = (int) $next->fetch()['next_val'];
    }

    if ($batchId) {
        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($columns)));
        $stmt = $db->prepare("UPDATE course_batches SET $set WHERE id = :id");
        $columns['id'] = $batchId;
        $stmt->execute($columns);
    } else {
        $cols = array_keys($columns);
        $placeholders = array_map(fn($k) => ":$k", $cols);
        $stmt = $db->prepare('INSERT INTO course_batches (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($columns);
        $batchId = (int) $db->lastInsertId();
    }

    // ফিচার (lines) — পুরনো সব মুছে নতুন করে ইনসার্ট
    $db->prepare('DELETE FROM course_features WHERE batch_id = :id')->execute(['id' => $batchId]);
    $featureLines = array_filter(array_map('trim', explode("\n", $_POST['features'] ?? '')));
    $order = 1;
    foreach ($featureLines as $line) {
        $db->prepare('INSERT INTO course_features (batch_id, feature_text, sort_order) VALUES (:fk, :val, :ord)')
           ->execute(['fk' => $batchId, 'val' => $line, 'ord' => $order++]);
    }

    set_flash('success', 'ব্যাচ সফলভাবে সেভ হয়েছে।');
    redirect('course-batches.php?course_id=' . $courseId);
}

// ------------------------------------------------------------
// DELETE — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('course-batches.php?course_id=' . $courseId);
    }
    $batchId = (int) ($_POST['id'] ?? 0);

    // ডিলিটের আগে আর্কাইভে (ব্যাচ + তার ফিচার) — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।
    // ছবির ফাইল মোছা হয় না (রিস্টোরে দরকার হবে)।
    archive_entity($db, 'course_batches', $batchId);
    $db->prepare('DELETE FROM course_batches WHERE id = :id')->execute(['id' => $batchId]);
    set_flash('success', 'ব্যাচ আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect('course-batches.php?course_id=' . $courseId);
}

// ------------------------------------------------------------
// FORM ভিউ (Add / Edit) ডেটা লোড
// ------------------------------------------------------------
$editBatch = null;
$formDefaults = [];
if ($action === 'form') {
    $editId = !empty($_GET['id']) ? (int) $_GET['id'] : null;
    if ($editId) {
        $stmt = $db->prepare('SELECT * FROM course_batches WHERE id = :id AND course_id = :cid');
        $stmt->execute(['id' => $editId, 'cid' => $courseId]);
        $editBatch = $stmt->fetch();
        if (!$editBatch) {
            redirect('course-batches.php?course_id=' . $courseId);
        }
        $featStmt = $db->prepare('SELECT feature_text FROM course_features WHERE batch_id = :id ORDER BY sort_order ASC');
        $featStmt->execute(['id' => $editId]);
        $editBatch['features'] = implode("\n", array_column($featStmt->fetchAll(), 'feature_text'));
    }
}

// ব্যাচের নাম ইনপুটে datalist সাজেশন — গ্লোবালি সব কোর্স জুড়ে আগে ব্যবহৃত নাম (যেমন "May_26"), কিন্তু
// ইউনিকনেস চেক শুধু এই course_id এর মধ্যেই হয় (উপরে save হ্যান্ডলারে)
$batchNameSuggestions = $db->query(
    "SELECT DISTINCT batch_name FROM course_batches WHERE batch_name IS NOT NULL AND batch_name != '' ORDER BY batch_name ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ------------------------------------------------------------
// LIST ভিউ ডেটা লোড
// ------------------------------------------------------------
$batches = [];
$registrationCounts = [];
if ($action === 'list') {
    $batches = $db->prepare('SELECT * FROM course_batches WHERE course_id = :cid ORDER BY sort_order ASC, id DESC');
    $batches->execute(['cid' => $courseId]);
    $batches = $batches->fetchAll();

    if ($batches) {
        $batchIds = array_column($batches, 'id');
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $regStmt = $db->prepare("SELECT item_id, COUNT(*) c FROM registrations WHERE type = 'course' AND item_id IN ({$placeholders}) GROUP BY item_id");
        $regStmt->execute($batchIds);
        $registrationCounts = array_column($regStmt->fetchAll(), 'c', 'item_id');
    }
}

require __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-5">
    <a href="manage.php?entity=courses" class="text-gray-500 text-sm">← সব কোর্স</a>
</div>

<?php if ($action === 'list'): ?>
    <div class="flex justify-between items-center mb-5">
        <div>
            <h3 class="text-lg font-bold text-gray-800"><?= e($course['title']) ?></h3>
            <p class="text-gray-500 text-sm">মোট <?= count($batches) ?> টি ব্যাচ · <a href="manage.php?entity=courses&action=form&id=<?= $course['id'] ?>" class="text-indigo-600 font-semibold">কোর্সের নাম সম্পাদনা</a></p>
        </div>
        <a href="course-batches.php?course_id=<?= $courseId ?>&action=form" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ নতুন ব্যাচ যোগ করুন</a>
    </div>

    <?php if (!$batches): ?>
        <div class="bg-white rounded-2xl shadow p-10 text-center text-gray-400">
            এখনো কোনো ব্যাচ নেই। "+ নতুন ব্যাচ যোগ করুন" বাটনে ক্লিক করে প্রথম ব্যাচ তৈরি করুন — এর আগে এই কোর্সটা সাইটে দেখাবে না বা রেজিস্ট্রেশনযোগ্য হবে না।
        </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">ছবি</th>
                    <th class="py-3 px-4">ব্যাচের নাম</th>
                    <th class="py-3 px-4">মূল্য</th>
                    <th class="py-3 px-4">প্রশিক্ষক</th>
                    <th class="py-3 px-4">মেয়াদ</th>
                    <th class="py-3 px-4">রেজিস্ট্রেশন খোলা?</th>
                    <th class="py-3 px-4">পার্সেল হাইড?</th>
                    <th class="py-3 px-4">সাইটে দেখাবে?</th>
                    <th class="py-3 px-4">রেজিস্ট্রেশন</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): $regCount = (int) ($registrationCounts[$b['id']] ?? 0); ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4">
                        <?php if (!empty($b['image'])): ?>
                            <img src="<?= e(admin_image_src($b['image'])) ?>" class="w-12 h-12 object-cover rounded-lg border">
                        <?php else: ?>
                            <div class="w-12 h-12 bg-gray-100 rounded-lg border flex items-center justify-center text-gray-300"><i data-lucide="image" class="w-5 h-5"></i></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 font-semibold"><?= e($b['batch_name']) ?></td>
                    <td class="py-2.5 px-4"><?= e($b['price'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($b['instructor'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($b['duration'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= $b['registration_open'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4"><?= $b['hide_parcel'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4"><?= $b['is_active'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4">
                        <?php if ($regCount > 0): ?>
                            <a href="course-data.php?course_id=<?= $b['id'] ?>" class="text-indigo-600 font-semibold"><?= $regCount ?> টি</a>
                        <?php else: ?>
                            <span class="text-gray-300">০</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                        <a href="course-batches.php?course_id=<?= $courseId ?>&action=form&id=<?= $b['id'] ?>" class="text-indigo-600 font-semibold">এডিট</a>
                        <form method="post" action="course-batches.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, '<?= $regCount > 0 ? "এই ব্যাচে {$regCount} টি রেজিস্ট্রেশন আছে। ব্যাচ আর্কাইভে সরালেও রেজিস্ট্রেশনগুলো থেকে যাবে; পরে আর্কাইভ পেজ থেকে ব্যাচ (দাম/ছবি সহ) ফিরিয়ে আনা যাবে। আর্কাইভে সরাতে চান?" : "এই ব্যাচ আর্কাইভে সরাতে চান? পরে আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।" ?>', 'ব্যাচ আর্কাইভ নিশ্চিতকরণ');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php elseif ($action === 'form'): ?>
    <div class="bg-white rounded-2xl shadow p-6 max-w-2xl">
        <h3 class="text-lg font-bold text-gray-800 mb-4"><?= $editBatch ? 'ব্যাচ সম্পাদনা — ' . e($course['title']) : 'নতুন ব্যাচ — ' . e($course['title']) ?></h3>
        <form method="post" action="course-batches.php?action=save" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <?php if ($editBatch): ?><input type="hidden" name="id" value="<?= $editBatch['id'] ?>"><?php endif; ?>
            <?php foreach ($batchFields as $key => $f):
                $value = $editBatch[$key] ?? ($formDefaults[$key] ?? ($f['default'] ?? ''));
                $suggestions = $key === 'batch_name' ? $batchNameSuggestions : [];
            ?>
                <div><?= render_field($key, $f, $value, $suggestions) ?></div>
            <?php endforeach; ?>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
                <a href="course-batches.php?course_id=<?= $courseId ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-xl">বাতিল</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
