<?php
// অ্যাডমিন কুরিয়ার ড্যাশবোর্ড।
//
// ⚠️ ২০২৬-০৭-২০ থেকে লিস্ট ভিউ **ব্যাচ-ভিত্তিক** (প্রতি রো = একটা পার্সেল/চালান), আগের মতো
// রেজিস্ট্রেশন-ভিত্তিক না। ওয়ার্কফ্লো: পার্সেল **তৈরি হয় শুধু `courier-prepare.php`-এ** (কোর্স → ব্যাচ →
// মাস, অটো-হিসাব করা কালেকশন সহ); এই পেজের কাজ সেগুলো **দেখা ও পাঠানো** — নির্দিষ্ট আইটেম / কোর্স ব্যাচ /
// ব্যাচ লেবেল (মাস) দিয়ে ফিল্টার করে নির্বাচিত পার্সেলগুলো একসাথে কুরিয়ারে পাঠানো যায়।
// এখানে আর "নির্বাচিতদের জন্য নতুন ব্যাচ" তৈরি হয় না (ইউজারের স্পষ্ট নির্দেশে সরানো হয়েছে)।
//
// action=view এ গেলে একটা রেজিস্ট্রেশনের পুরো ব্যাচ ইতিহাস + ব্যাচ এডিট/তৈরির ফর্ম দেখা যায় (অপরিবর্তিত —
// রিসেন্ড ও এককালীন সংশোধনের জন্য দরকার)।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কুরিয়ার';
$action = $_GET['action'] ?? 'list';

$typeLabels = ['course' => 'কোর্স', 'worksheet' => 'ওয়ার্কশিট', 'product' => 'প্রোডাক্ট'];
$deliveryTypeLabels = [48 => 'Normal Delivery', 12 => 'On Demand Delivery'];
$itemTypeLabels = [2 => 'Parcel', 1 => 'Document'];
// 'draft' কে ইচ্ছাকৃতভাবে "খসড়া" না বলে "প্রস্তুত" বলা হয় — এই ব্যাচগুলো লেবেল (মাস) দিয়ে খুঁজে বের করার
// জন্য বানানো হয়, "অসম্পূর্ণ কাজ" বোঝানো উদ্দেশ্য না
$batchStatusLabels = [
    'draft' => ['প্রস্তুত', 'bg-gray-100 text-gray-600'],
    'sent' => ['সফল', 'bg-green-100 text-green-700'],
    'failed' => ['ব্যর্থ', 'bg-red-100 text-red-700'],
];
$courierConfigured = get_active_courier_provider() !== null;
$activeProviderName = get_setting('courier_active_provider');

function safe_courier_return_url(?string $url): string
{
    return ($url && strpos($url, 'courier.php') === 0) ? $url : 'courier.php';
}

// রেজিস্ট্রেশন "সক্রিয়/নিষ্ক্রিয়" টগল — নিষ্ক্রিয় করলে সেটা লিস্টে দেখা যাবে (ইতিহাসের জন্য) কিন্তু বাল্ক-সিলেক্ট/পাঠানোর
// জন্য বাছাই করা যাবে না (যেমন "confirmed কিন্তু এইটা কুরিয়ারে যাবে না")
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'toggle-active') {
    $returnUrl = safe_courier_return_url($_POST['return_url'] ?? null);
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }
    $toggleId = (int) ($_POST['id'] ?? 0);
    $newActive = isset($_POST['active']) ? 1 : 0; // চেকবক্স আনচেক থাকলে POST এ কী-টাই থাকে না (ব্রাউজারের স্বাভাবিক আচরণ)
    $db->prepare('UPDATE registrations SET courier_active = :a WHERE id = :id')->execute(['a' => $newActive, 'id' => $toggleId]);
    set_flash('success', $newActive ? 'সক্রিয় করা হয়েছে।' : 'নিষ্ক্রিয় করা হয়েছে — এটা এখন বাছাই করা যাবে না।');
    redirect($returnUrl);
}

// শুধু সেভ (কুরিয়ারে না পাঠিয়ে) — ডিটেইল পেজের ফর্মে দ্বিতীয় বাটন এখানে POST করে
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save-data') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('courier.php');
    }
    $saveId = (int) ($_POST['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $saveId]);
    $saveOrder = $stmt->fetch();
    if (!$saveOrder) {
        set_flash('error', 'রেজিস্ট্রেশন পাওয়া যায়নি।');
        redirect('courier.php');
    }
    $saveBatchId = isset($_POST['batch_id']) && $_POST['batch_id'] !== '' ? (int) $_POST['batch_id'] : null;
    $resolved = save_courier_batch($db, $saveOrder, $_POST, $saveBatchId);
    set_flash('success', 'ব্যাচের তথ্য সেভ হয়েছে।');
    redirect('courier.php?action=view&id=' . $saveId . '&batch=' . $resolved['id']);
}

// একটা ব্যাচ মুছে ফেলা (draft বা ভুল করে বানানো ব্যাচ পরিষ্কার করার জন্য — ইতিহাসও (courier_shipments) এর সাথে মুছে যাবে)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete-batch') {
    // return_url দেওয়া থাকলে সেখানেই ফিরবে (লিস্ট পেজ থেকে মুছলে ফিল্টার অবস্থা বজায় থাকে),
    // না দিলে আগের মতো সেই রেজিস্ট্রেশনের ডিটেইল পেজে
    $delReturnUrl = isset($_POST['return_url']) ? safe_courier_return_url($_POST['return_url']) : null;
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($delReturnUrl ?? 'courier.php');
    }
    $delBatchId = (int) ($_POST['batch_id'] ?? 0);
    $stmt = $db->prepare('SELECT registration_id FROM courier_batches WHERE id = :id');
    $stmt->execute(['id' => $delBatchId]);
    $delBatch = $stmt->fetch();
    if (!$delBatch) {
        set_flash('error', 'ব্যাচ পাওয়া যায়নি।');
        redirect($delReturnUrl ?? 'courier.php');
    }
    $db->prepare('DELETE FROM courier_batches WHERE id = :id')->execute(['id' => $delBatchId]);
    set_flash('success', 'পার্সেল/ব্যাচ মুছে ফেলা হয়েছে।');
    redirect($delReturnUrl ?? ('courier.php?action=view&id=' . $delBatch['registration_id']));
}

// পুরো রেজিস্ট্রেশন/অর্ডার ডিলিট (কুরিয়ার লিস্ট থেকে) — এর courier_batches ও courier_shipments FK
// ON DELETE CASCADE দিয়ে অটো মুছে যায়, income আলাদা করে মুছতে হয় (registrations.php এর মতোই প্যাটার্ন)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $returnUrl = safe_courier_return_url($_POST['return_url'] ?? null);
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }
    $delId = (int) ($_POST['id'] ?? 0);
    // ডিলিটের আগে পুরো অর্ডার (রেজিস্ট্রেশন + আয় + কুরিয়ার ব্যাচ/শিপমেন্ট) আর্কাইভে — পরে রিস্টোরযোগ্য
    archive_entity($db, 'registrations', $delId);
    $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $delId]);
    $db->prepare('DELETE FROM registrations WHERE id = :id')->execute(['id' => $delId]);
    set_flash('success', 'রেজিস্ট্রেশন/অর্ডার আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect($returnUrl);
}

$viewRow = null;
$batches = [];
$formMode = 'new';
$formBatch = null;
$bengaliMonths = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই', 'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
$currentMonthLabel = $bengaliMonths[(int) date('n') - 1] . ' ' . date('Y');

if ($action === 'view') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $viewRow = $stmt->fetch();
    if (!$viewRow) {
        redirect('courier.php');
    }

    $batchStmt = $db->prepare('SELECT * FROM courier_batches WHERE registration_id = :id ORDER BY created_at DESC');
    $batchStmt->execute(['id' => $id]);
    $batches = $batchStmt->fetchAll();

    // প্রতিটা ব্যাচের সব পাঠানোর চেষ্টা (প্রথমবার + প্রতিটা রিসেন্ড) — একটা ব্যাচ একাধিকবার পাঠানো হলে
    // পুরো ইতিহাস (আগের consignment/fee সহ) দেখানোর জন্য batch_id অনুযায়ী গ্রুপ করা হলো
    $shipmentsByBatch = [];
    $batchIds = array_column($batches, 'id');
    if ($batchIds) {
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $shipStmt = $db->prepare("SELECT * FROM courier_shipments WHERE batch_id IN ({$placeholders}) ORDER BY created_at DESC");
        $shipStmt->execute($batchIds);
        foreach ($shipStmt->fetchAll() as $s) {
            $shipmentsByBatch[$s['batch_id']][] = $s;
        }
    }

    $batchParam = $_GET['batch'] ?? null;
    if ($batchParam === 'new') {
        $formMode = 'new';
    } elseif ($batchParam !== null && $batchParam !== '') {
        foreach ($batches as $b) {
            if ((int) $b['id'] === (int) $batchParam) {
                $formBatch = $b;
                break;
            }
        }
        $formMode = $formBatch ? 'edit' : ($batches ? 'edit' : 'new');
        if (!$formBatch && $batches) {
            $formBatch = $batches[0];
        }
    } elseif ($batches) {
        $formMode = 'edit';
        $formBatch = $batches[0];
    }
}

// লিস্ট পেজের ফিল্টার — কোন কোর্সের (আইটেম) কোন কোর্স-ব্যাচের কোন মাসের (ব্যাচ লেবেল) পার্সেল, কোন
// স্ট্যাটাসে — সেই গ্রুপটা বেছে নিয়ে একসাথে পাঠানোর সুবিধার্থে
$filterType = $_GET['type'] ?? '';
$filterItem = trim($_GET['item'] ?? '');
$filterCourseBatch = trim($_GET['course_batch'] ?? '');
$filterBatchStatus = $_GET['batch_status'] ?? '';
$filterPeriodLabel = trim($_GET['period_label'] ?? '');
$activeCourierFilters = array_filter(
    [
        'type' => $filterType, 'item' => $filterItem, 'course_batch' => $filterCourseBatch,
        'batch_status' => $filterBatchStatus, 'period_label' => $filterPeriodLabel,
    ],
    fn($v) => $v !== '' && $v !== null
);

function courier_url(array $overrides = []): string
{
    global $activeCourierFilters;
    $params = array_filter(array_merge($activeCourierFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($params);
    return 'courier.php' . ($qs !== '' ? '?' . $qs : '');
}

// বাল্ক-ব্যাচ মডেলে লেবেল ইনপুটে সাজেশন (আগে ব্যবহৃত লেবেলগুলো) — লিস্ট ও ডিটেইল দুই পেজেই লাগে
$periodLabelSuggestions = $db->query("SELECT DISTINCT period_label FROM courier_batches WHERE period_label != '' ORDER BY period_label")->fetchAll(PDO::FETCH_COLUMN);

// ⚠️ ২০২৬-০৭-২০ থেকে লিস্টটা **ব্যাচ-ভিত্তিক** (প্রতি রো = একটা পার্সেল/চালান), আগের মতো রেজিস্ট্রেশন-ভিত্তিক
// না। কারণ: নতুন ওয়ার্কফ্লোতে পার্সেল তৈরি হয় courier-prepare.php-এ (কোর্স→ব্যাচ→মাস), আর এই পেজের কাজ
// শুধু "কোন কোর্সের কোন ব্যাচের কোন মাসের কোন কোন পার্সেল প্রস্তুত/পাঠানো" দেখা ও প্রস্তুতগুলো পাঠানো।
// এখানে আর নতুন ব্যাচ তৈরি হয় না (ইউজারের স্পষ্ট নির্দেশ) — তৈরি শুধু prepare পেজে।
$rows = [];
$distinctItems = [];
$distinctCourseBatches = [];
$filterPeriodLabelOptions = [];
$attemptCountByBatch = [];
$summaryCounts = ['draft' => 0, 'sent' => 0, 'failed' => 0, 'amount' => 0.0];
if ($action === 'list') {
    $where = ['1 = 1'];
    $params = [];
    if ($filterType && isset($typeLabels[$filterType])) {
        $where[] = 'r.type = :type';
        $params['type'] = $filterType;
    }
    if ($filterItem !== '') {
        $where[] = 'r.item_title = :item';
        $params['item'] = $filterItem;
    }
    if ($filterCourseBatch !== '') {
        $where[] = 'r.batch = :course_batch';
        $params['course_batch'] = $filterCourseBatch;
    }
    if ($filterBatchStatus && isset($batchStatusLabels[$filterBatchStatus])) {
        $where[] = 'cb.send_status = :batch_status';
        $params['batch_status'] = $filterBatchStatus;
    }
    if ($filterPeriodLabel !== '') {
        $where[] = 'cb.period_label = :period_label';
        $params['period_label'] = $filterPeriodLabel;
    }
    $whereSql = implode(' AND ', $where);

    $rowsStmt = $db->prepare(
        "SELECT cb.*,
                r.id AS reg_id, r.type, r.item_title, r.batch AS course_batch, r.customer_name,
                r.facebook_id, r.receiver_name, r.receiver_phone, r.address, r.courier_active,
                r.notes AS reg_notes,
                (SELECT COUNT(*) FROM courier_batches cbx WHERE cbx.registration_id = r.id) batch_count
         FROM courier_batches cb
         JOIN registrations r ON r.id = cb.registration_id
         WHERE {$whereSql}
         ORDER BY cb.created_at DESC, cb.id DESC"
    );
    $rowsStmt->execute($params);
    $rows = $rowsStmt->fetchAll();

    foreach ($rows as $rw) {
        $st = $rw['send_status'];
        if (isset($summaryCounts[$st])) { $summaryCounts[$st]++; }
        $summaryCounts['amount'] += (float) $rw['amount_to_collect'];
    }

    // ── ফিল্টার ড্রপডাউনের অপশন: শুধু যেসব আইটেম/ব্যাচ/মাসে আসলে পার্সেল তৈরি হয়েছে সেগুলোই
    //    (আগে সব confirmed রেজিস্ট্রেশন থেকে আসত — এখন লিস্টই ব্যাচ-ভিত্তিক বলে এটাই সংগতিপূর্ণ)।
    //    কাস্কেডিং: আইটেম বাছলে কোর্স-ব্যাচ ও মাস সেই আইটেমে, কোর্স-ব্যাচ বাছলে মাস সেই ব্যাচে সীমাবদ্ধ।
    $optBase = 'FROM courier_batches cb JOIN registrations r ON r.id = cb.registration_id';

    $itemsSql = "SELECT DISTINCT r.item_title {$optBase} WHERE 1 = 1";
    $itemsParams = [];
    if ($filterType && isset($typeLabels[$filterType])) {
        $itemsSql .= ' AND r.type = :type';
        $itemsParams['type'] = $filterType;
    }
    $itemsSql .= ' ORDER BY r.item_title';
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->execute($itemsParams);
    $distinctItems = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);

    $courseBatchSql = "SELECT DISTINCT r.batch {$optBase} WHERE r.type = 'course' AND r.batch IS NOT NULL AND r.batch != ''";
    $courseBatchParams = [];
    if ($filterItem !== '') {
        $courseBatchSql .= ' AND r.item_title = :item';
        $courseBatchParams['item'] = $filterItem;
    }
    $courseBatchSql .= ' ORDER BY r.batch';
    $courseBatchStmt = $db->prepare($courseBatchSql);
    $courseBatchStmt->execute($courseBatchParams);
    $distinctCourseBatches = $courseBatchStmt->fetchAll(PDO::FETCH_COLUMN);

    $filterLabelSql = "SELECT DISTINCT cb.period_label {$optBase} WHERE cb.period_label != ''";
    $filterLabelParams = [];
    if ($filterItem !== '') {
        $filterLabelSql .= ' AND r.item_title = :item';
        $filterLabelParams['item'] = $filterItem;
    }
    if ($filterCourseBatch !== '') {
        $filterLabelSql .= ' AND r.batch = :course_batch';
        $filterLabelParams['course_batch'] = $filterCourseBatch;
    }
    $filterLabelSql .= ' ORDER BY cb.period_label';
    $filterLabelStmt = $db->prepare($filterLabelSql);
    $filterLabelStmt->execute($filterLabelParams);
    $filterPeriodLabelOptions = $filterLabelStmt->fetchAll(PDO::FETCH_COLUMN);

    // প্রতিটা ব্যাচের কতবার পাঠানোর চেষ্টা হয়েছে (রিসেন্ড কাউন্ট, শুধু sent/failed ব্যাচে প্রাসঙ্গিক)
    $listBatchIds = array_column($rows, 'id');
    if ($listBatchIds) {
        $bPlaceholders = implode(',', array_fill(0, count($listBatchIds), '?'));
        $attemptCountStmt = $db->prepare(
            "SELECT batch_id, COUNT(*) c FROM courier_shipments WHERE batch_id IN ({$bPlaceholders}) GROUP BY batch_id"
        );
        $attemptCountStmt->execute($listBatchIds);
        $attemptCountByBatch = array_column($attemptCountStmt->fetchAll(), 'c', 'batch_id');
    }
}

// সক্রিয়/নিষ্ক্রিয় টগলের পর একই ফিল্টারে ফিরে আসার জন্য
$currentCourierListUrl = courier_url();

require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$courierConfigured): ?>
<div class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-5 text-amber-800 text-sm">
    কোনো কুরিয়ার প্রোভাইডার সেট করা নেই। <a href="settings.php" class="font-semibold underline">সাইট সেটিংসে</a> গিয়ে "কুরিয়ার API" সেকশনে প্রোভাইডার (steadfast/pathao) ও ক্রেডেনশিয়াল বসান।
</div>
<?php elseif ($action === 'list'): ?>
<p class="text-sm text-gray-500 mb-4">সক্রিয় প্রোভাইডার: <span class="font-semibold text-gray-800"><?= e(ucfirst($activeProviderName)) ?></span></p>
<?php endif; ?>

<?php if ($action === 'list'): ?>

<form id="bulkCourierForm" method="post" action="bulk-courier-action.php" class="hidden">
    <?= csrf_field() ?>
    <input type="hidden" name="return_url" value="<?= e($currentCourierListUrl) ?>">
</form>

<!-- ফিল্টার: কোন কোর্সের (আইটেম) কোন ব্যাচের কোন মাসের (ব্যাচ লেবেল) পার্সেলগুলো দেখতে/পাঠাতে চান — সেই গ্রুপটা বেছে নেওয়ার জন্য -->
<div class="flex flex-wrap gap-2 mb-3">
    <a href="<?= e(courier_url(['type' => null, 'item' => null, 'course_batch' => null, 'period_label' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === '' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>">সব টাইপ</a>
    <?php foreach ($typeLabels as $key => $label): ?>
        <a href="<?= e(courier_url(['type' => $key, 'item' => null, 'course_batch' => null, 'period_label' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === $key ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="flex flex-wrap gap-2 mb-3">
    <a href="<?= e(courier_url(['batch_status' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterBatchStatus === '' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>">সব ব্যাচ স্ট্যাটাস</a>
    <?php foreach ($batchStatusLabels as $key => $lbl): ?>
        <a href="<?= e(courier_url(['batch_status' => $key])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterBatchStatus === $key ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($lbl[0]) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="courier.php" id="courierFilterForm" class="mb-4 bg-white rounded-2xl shadow p-4">
    <?php if ($filterType): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif; ?>
    <?php if ($filterBatchStatus): ?><input type="hidden" name="batch_status" value="<?= e($filterBatchStatus) ?>"><?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">নির্দিষ্ট আইটেম</label>
            <select name="item" id="courierItemSelect" onchange="document.getElementById('courierCourseBatchSelect').value=''; document.getElementById('courierPeriodLabelSelect').value=''; document.getElementById('courierFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব আইটেম</option>
                <?php foreach ($distinctItems as $itemTitle): ?>
                    <option value="<?= e($itemTitle) ?>" <?= $filterItem === $itemTitle ? 'selected' : '' ?>><?= e($itemTitle) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">কোর্স ব্যাচ (যেমন — ৫ম ব্যাচ)<?= $filterItem !== '' ? ' — নির্বাচিত আইটেমের' : '' ?></label>
            <select name="course_batch" id="courierCourseBatchSelect" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব ব্যাচ</option>
                <?php foreach ($distinctCourseBatches as $cb): ?>
                    <option value="<?= e($cb) ?>" <?= $filterCourseBatch === $cb ? 'selected' : '' ?>><?= e($cb) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">ব্যাচ লেবেল (মাস/কিস্তি)<?= $filterItem !== '' ? ' — নির্বাচিত আইটেমের' : '' ?></label>
            <select name="period_label" id="courierPeriodLabelSelect" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব লেবেল</option>
                <?php foreach ($filterPeriodLabelOptions as $pl): ?>
                    <option value="<?= e($pl) ?>" <?= $filterPeriodLabel === $pl ? 'selected' : '' ?>><?= e($pl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <button type="submit" class="bg-gray-800 hover:bg-gray-900 text-white font-semibold px-5 py-2.5 rounded-xl text-sm">🔍 ফিল্টার করুন</button>
            <?php if ($activeCourierFilters): ?><a href="courier.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ মুছুন</a><?php endif; ?>
        </div>
    </div>
    <p class="text-xs text-gray-400 mt-2">নির্দিষ্ট আইটেম বাছাই করলে কোর্স ব্যাচ ও ব্যাচ লেবেল ড্রপডাউন স্বয়ংক্রিয়ভাবে সেই আইটেমের সাথে সীমাবদ্ধ হয়ে যাবে।</p>
</form>

<p class="text-sm text-gray-500 mb-3">মোট <strong><?= count($rows) ?></strong> টি ফলাফল</p>

<?php if ($courierConfigured): ?>
<div class="flex flex-wrap items-center gap-3 mb-4">
    <button id="courierBulkBtn" type="button" onclick="submitBulkCourier()" disabled class="bg-indigo-600 hover:bg-indigo-700 disabled:bg-gray-200 disabled:text-gray-400 disabled:cursor-not-allowed text-white font-semibold px-5 py-3 rounded-xl text-sm">
        <i data-lucide="truck" class="w-4 h-4 inline"></i> নির্বাচিত (<span id="courierSelCount">০</span>) টি কুরিয়ারে পাঠান
    </button>
    <span class="text-xs text-gray-400">নতুন পার্সেল তৈরি করতে <a href="courier-prepare.php" class="text-indigo-600 font-semibold">পার্সেল প্রস্তুত</a> পেজে যান।</span>
</div>
<?php endif; ?>

<?php // সারাংশ: এই ফিল্টারে কত পার্সেল প্রস্তুত/সফল/ব্যর্থ ও মোট কালেকশন ?>
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
    <?php foreach ([['draft', 'প্রস্তুত', 'text-gray-700'], ['sent', 'সফল', 'text-green-700'], ['failed', 'ব্যর্থ', 'text-red-700']] as $sc): ?>
        <div class="bg-white rounded-2xl shadow p-3">
            <div class="text-xs text-gray-500"><?= $sc[1] ?></div>
            <div class="text-xl font-black <?= $sc[2] ?>"><?= (int) $summaryCounts[$sc[0]] ?></div>
        </div>
    <?php endforeach; ?>
    <div class="bg-white rounded-2xl shadow p-3">
        <div class="text-xs text-gray-500">মোট কালেকশন</div>
        <div class="text-xl font-black text-gray-900">৳<?= e(number_format($summaryCounts['amount'])) ?></div>
    </div>
</div>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4"><input type="checkbox" class="w-4 h-4 accent-indigo-600" onchange="toggleAllCourierSelect(this)"></th>
                <th class="py-3 px-4">সক্রিয়?</th>
                <th class="py-3 px-4">মাস</th>
                <th class="py-3 px-4">আইটেম</th>
                <th class="py-3 px-4">কোর্স ব্যাচ</th>
                <th class="py-3 px-4">নাম</th>
                <th class="py-3 px-4">রিসিভার নাম</th>
                <th class="py-3 px-4">রিসিভার নাম্বার</th>
                <th class="py-3 px-4">ঠিকানা</th>
                <th class="py-3 px-4">কালেকশন</th>
                <th class="py-3 px-4">স্ট্যাটাস</th>
                <th class="py-3 px-4">কনসাইনমেন্ট/ফি</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="13" class="py-6 px-4 text-center text-gray-400">
                <?= $activeCourierFilters ? 'এই ফিল্টারে কোনো পার্সেল পাওয়া যায়নি।' : 'এখনো কোনো পার্সেল তৈরি হয়নি — <a href="courier-prepare.php" class="text-indigo-600 font-semibold">পার্সেল প্রস্তুত</a> পেজ থেকে কোর্স-ব্যাচ বেছে নিয়ে তৈরি করুন।' ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $isActive = (int) $row['courier_active'] === 1;
            $isSent = $row['send_status'] === 'sent';
            $bs = $batchStatusLabels[$row['send_status']] ?? $batchStatusLabels['draft'];
            $attempts = (int) ($attemptCountByBatch[$row['id']] ?? 0);
            // ইতিমধ্যে সফলভাবে পাঠানো ব্যাচ বাল্ক-সিলেকশনে নেওয়া যায় না (ডুপ্লিকেট চালান ঠেকাতে) —
            // দরকার হলে "বিস্তারিত" থেকে রিসেন্ড করা যাবে
            $selectable = $isActive && !$isSent;
        ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $isActive ? '' : 'bg-gray-50 opacity-60' ?>">
                <td class="py-2.5 px-4"><input type="checkbox" class="courier-select w-4 h-4 accent-indigo-600" value="<?= (int) $row['id'] ?>" onchange="updateCourierSelCount()" <?= $selectable ? '' : 'disabled' ?>></td>
                <td class="py-2.5 px-4">
                    <form method="post" action="courier.php?action=toggle-active">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $row['reg_id'] ?>">
                        <input type="hidden" name="return_url" value="<?= e($currentCourierListUrl) ?>">
                        <label class="relative inline-block w-9 h-5 cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="active" value="1" <?= $isActive ? 'checked' : '' ?> onchange="handleCourierActiveToggle(this)" class="sr-only peer">
                            <span class="absolute inset-0 bg-gray-300 peer-checked:bg-green-600 rounded-full transition-colors"></span>
                            <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow-md transition-transform peer-checked:translate-x-4"></span>
                        </label>
                    </form>
                </td>
                <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-amber-50 text-amber-800 text-xs font-bold whitespace-nowrap"><?= e($row['period_label'] ?: '—') ?></span></td>
                <td class="py-2.5 px-4"><?= e($row['item_title']) ?><br><span class="text-gray-400 text-xs"><?= e($typeLabels[$row['type']] ?? $row['type']) ?></span></td>
                <td class="py-2.5 px-4"><?= $row['course_batch'] ? '<span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold">' . e($row['course_batch']) . '</span>' : '<span class="text-gray-300">—</span>' ?></td>
                <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($row['customer_name']) ?><?php if ($row['facebook_id']): ?><br><span class="text-gray-400 text-xs font-normal"><?= e($row['facebook_id']) ?></span><?php endif; ?></td>
                <td class="py-2.5 px-4"><?= e($row['receiver_name'] ?: '-') ?></td>
                <td class="py-2.5 px-4 whitespace-nowrap"><?= e($row['receiver_phone'] ?: '-') ?></td>
                <td class="py-2.5 px-4 max-w-[180px] truncate" title="<?= e($row['address'] ?? '') ?>"><?= e($row['address'] ?: '-') ?></td>
                <td class="py-2.5 px-4 font-black text-gray-900 whitespace-nowrap">৳<?= e(number_format((float) $row['amount_to_collect'])) ?></td>
                <td class="py-2.5 px-4">
                    <span class="px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap <?= $bs[1] ?>"><?= e($bs[0]) ?></span>
                    <?php if ($attempts > 1): ?><br><span class="text-amber-600 text-xs"><?= (int) $attempts ?> বার পাঠানো</span><?php endif; ?>
                </td>
                <td class="py-2.5 px-4 text-xs">
                    <?php if ($row['courier_consignment_id']): ?>
                        <span class="font-mono"><?= e($row['courier_consignment_id']) ?></span>
                        <?php if ($row['delivery_fee'] !== null): ?><br>৳<?= number_format((float) $row['delivery_fee'], 2) ?><?php endif; ?>
                    <?php else: ?><span class="text-gray-300">-</span><?php endif; ?>
                </td>
                <td class="py-2.5 px-4 whitespace-nowrap space-x-2">
                    <a href="courier.php?action=view&id=<?= (int) $row['reg_id'] ?>&batch=<?= (int) $row['id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">বিস্তারিত</a>
                    <a href="courier-shipment-logs.php?reg_id=<?= (int) $row['reg_id'] ?>" class="text-gray-400 hover:text-gray-600 inline-block py-1" title="সব শিপমেন্ট লগ"><i data-lucide="history" class="w-3.5 h-3.5 inline"></i></a>
                    <form method="post" action="courier.php?action=delete-batch" class="inline" onsubmit="return confirmSubmit(this, 'এই পার্সেলটি (<?= e(addslashes($row['period_label'] ?: '—')) ?> — <?= e(addslashes($row['customer_name'])) ?>) মুছে ফেলবেন? শুধু এই চালানটাই মুছবে, রেজিস্ট্রেশন থাকবে।', 'পার্সেল ডিলিট');">
                        <?= csrf_field() ?>
                        <input type="hidden" name="batch_id" value="<?= (int) $row['id'] ?>">
                        <input type="hidden" name="return_url" value="<?= e(courier_url()) ?>">
                        <button type="submit" class="text-red-600 font-semibold py-1">ডিলিট</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php elseif ($action === 'view'):
    $itemDetails = get_item_details($viewRow['type'], $viewRow['item_id']);
    $defaultAddress = implode(', ', array_filter([$viewRow['address'], $viewRow['thana'], $viewRow['district']]));
    $computedAmount = $itemDetails ? parse_price_to_number($itemDetails['price']) * max(1, (int) $viewRow['quantity']) : 0;

    // এডিট করা হচ্ছে এমন ব্যাচ থাকলে সেটাই prefill হবে, নাহলে (নতুন ব্যাচ) রেজিস্ট্রেশন/আইটেম থেকে ডিফল্ট
    $fPeriodLabel = $formBatch['period_label'] ?? ($formMode === 'new' ? $currentMonthLabel : '');
    $fRecipientName = $formBatch['recipient_name'] ?? ($viewRow['receiver_name'] ?: $viewRow['customer_name']);
    $fRecipientPhone = $formBatch['recipient_phone'] ?? ($viewRow['receiver_phone'] ?: $viewRow['phone']);
    $fRecipientSecondaryPhone = $formBatch['recipient_secondary_phone'] ?? '';
    $fRecipientAddress = $formBatch['recipient_address'] ?? $defaultAddress;
    $fItemDescription = $formBatch['item_description'] ?? ($itemDetails['title'] ?? $viewRow['item_title']);
    $fItemQuantity = $formBatch['item_quantity'] ?? max(1, (int) $viewRow['quantity']);
    $fItemWeight = $formBatch['item_weight'] ?? 0.5;
    $fItemType = $formBatch['item_type'] ?? 2;
    $fDeliveryType = $formBatch['delivery_type'] ?? 48;
    $fSpecialInstruction = $formBatch['special_instruction'] ?? ($viewRow['notes'] ?? '');
    $fAmountToCollect = $formBatch['amount_to_collect'] ?? $computedAmount;
?>
<div class="max-w-3xl space-y-5">
    <div class="bg-white rounded-2xl shadow p-6">
        <div class="flex justify-between items-start mb-4">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?= e($viewRow['item_title']) ?></h3>
                <p class="text-gray-500 text-sm">Row ID #<?= $viewRow['id'] ?> • <?= e($typeLabels[$viewRow['type']] ?? $viewRow['type']) ?></p>
            </div>
            <a href="registrations.php?action=edit&id=<?= $viewRow['id'] ?>" class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-300 rounded-lg px-3 py-1.5">
                <i data-lucide="pencil" class="w-3.5 h-3.5"></i> রেজিস্ট্রেশন তথ্য সম্পাদনা
            </a>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm bg-gray-50 rounded-xl p-4 mb-2">
            <div><span class="text-gray-500">রিসিভার নাম:</span> <span class="font-semibold"><?= e($viewRow['receiver_name'] ?: $viewRow['customer_name']) ?></span></div>
            <div><span class="text-gray-500">রিসিভার ফোন:</span> <span class="font-semibold"><?= e($viewRow['receiver_phone'] ?: $viewRow['phone']) ?></span></div>
            <?php if ($viewRow['facebook_id']): ?>
            <div><span class="text-gray-500">ফেসবুক আইডি:</span> <span class="font-semibold"><?= e($viewRow['facebook_id']) ?></span></div>
            <?php endif; ?>
            <div class="sm:col-span-2"><span class="text-gray-500">ঠিকানা:</span> <span class="font-semibold"><?= e($defaultAddress ?: '-') ?></span></div>
        </div>
        <p class="text-xs text-gray-400">রিসিভার নাম/ফোন/ঠিকানা স্থায়ীভাবে বদলাতে উপরের "সম্পাদনা" বাটন ব্যবহার করুন। নিচের প্রতিটা ব্যাচ শুধু সেই নির্দিষ্ট চালানের জন্য (রেজিস্ট্রেশনে সেভ হয় না)।</p>
    </div>

    <div class="bg-white rounded-2xl shadow p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-bold text-gray-800">কুরিয়ার ব্যাচ ইতিহাস <span class="text-gray-400 font-normal text-sm">(মোট <?= count($batches) ?> টি)</span></h3>
            <div class="flex items-center gap-2">
                <?php if ($batches): ?>
                <a href="courier-shipment-logs.php?reg_id=<?= $viewRow['id'] ?>" class="flex items-center gap-1 text-sm font-semibold text-gray-500 hover:text-gray-700 border border-gray-200 hover:border-gray-300 rounded-lg px-3 py-1.5">
                    <i data-lucide="history" class="w-4 h-4"></i> সব শিপমেন্ট লগ
                </a>
                <?php endif; ?>
                <a href="courier.php?action=view&id=<?= $viewRow['id'] ?>&batch=new" class="flex items-center gap-1 text-sm font-semibold text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-300 rounded-lg px-3 py-1.5">
                    <i data-lucide="plus" class="w-4 h-4"></i> নতুন ব্যাচ (যেমন — পরবর্তী মাস)
                </a>
            </div>
        </div>

        <?php if (!$batches): ?>
            <p class="text-sm text-gray-400">এখনো কোনো ব্যাচ তৈরি হয়নি — নিচের ফর্ম দিয়ে প্রথম ব্যাচ তৈরি করুন।</p>
        <?php else: ?>
        <div class="overflow-x-auto -mx-2">
            <table class="w-full text-xs">
                <thead>
                    <tr class="text-left text-gray-500 border-b">
                        <th class="py-2 px-2">লেবেল</th>
                        <th class="py-2 px-2">Item Description</th>
                        <th class="py-2 px-2">কালেকশন</th>
                        <th class="py-2 px-2">স্ট্যাটাস</th>
                        <th class="py-2 px-2">Consignment / Fee</th>
                        <th class="py-2 px-2">সময়</th>
                        <th class="py-2 px-2">অ্যাকশন</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($batches as $b): $bs = $batchStatusLabels[$b['send_status']] ?? $batchStatusLabels['draft']; $isOpen = $formBatch && (int) $formBatch['id'] === (int) $b['id']; $attempts = $shipmentsByBatch[$b['id']] ?? []; ?>
                    <tr class="border-b last:border-0 <?= $isOpen ? 'bg-indigo-50' : 'hover:bg-gray-50' ?>">
                        <td class="py-2 px-2 font-semibold"><?= e($b['period_label'] ?: '—') ?></td>
                        <td class="py-2 px-2"><?= e($b['item_description'] ?: '-') ?></td>
                        <td class="py-2 px-2">৳<?= number_format((float) $b['amount_to_collect'], 2) ?></td>
                        <td class="py-2 px-2"><span class="px-2 py-0.5 rounded-full font-semibold <?= $bs[1] ?>"><?= e($bs[0]) ?></span></td>
                        <td class="py-2 px-2">
                            <?php if (count($attempts) > 1): ?>
                                <details>
                                    <summary class="cursor-pointer text-indigo-600 font-semibold"><?= count($attempts) ?>টা চেষ্টা — বিস্তারিত দেখুন</summary>
                                    <div class="mt-1.5 space-y-1 max-w-xs">
                                        <?php foreach ($attempts as $a): $as = $batchStatusLabels[$a['status'] === 'created' ? 'sent' : 'failed']; ?>
                                            <div class="text-[11px] bg-gray-50 rounded px-2 py-1">
                                                <span class="px-1.5 py-0.5 rounded-full font-semibold <?= $as[1] ?>"><?= e($as[0]) ?></span>
                                                <?php if ($a['consignment_id']): ?> <span class="font-mono"><?= e($a['consignment_id']) ?></span><?php endif; ?>
                                                <?php if ($a['delivery_fee'] !== null): ?> / ৳<?= number_format((float) $a['delivery_fee'], 2) ?><?php endif; ?>
                                                <br><span class="text-gray-400"><?= e($a['created_at']) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            <?php elseif ($b['courier_consignment_id']): ?>
                                <span class="font-mono"><?= e($b['courier_consignment_id']) ?></span>
                                <?php if ($b['delivery_fee'] !== null): ?> / ৳<?= number_format((float) $b['delivery_fee'], 2) ?><?php endif; ?>
                                <?php if ($b['tracking_url']): ?> — <a href="<?= e($b['tracking_url']) ?>" target="_blank" class="text-indigo-600">ট্র্যাক</a><?php endif; ?>
                            <?php else: ?>
                                <span class="text-gray-300">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="py-2 px-2 text-gray-400 whitespace-nowrap"><?= e($b['sent_at'] ?: $b['created_at']) ?></td>
                        <td class="py-2 px-2">
                            <div class="flex items-center gap-2">
                                <a href="courier.php?action=view&id=<?= $viewRow['id'] ?>&batch=<?= $b['id'] ?>" class="text-indigo-600 font-semibold">সম্পাদনা</a>
                                <form method="post" action="courier.php?action=delete-batch" onsubmit="return confirmSubmit(this, 'এই ব্যাচটা মুছে ফেলতে চান? এর সাথে সংশ্লিষ্ট কুরিয়ার লগও মুছে যাবে।', 'ব্যাচ ডিলিট নিশ্চিতকরণ');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="text-red-600 font-semibold">মুছুন</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <div class="bg-white rounded-2xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4">
            <?= $formMode === 'new' ? 'নতুন ব্যাচ তৈরি করুন' : 'ব্যাচ সম্পাদনা' . ($formBatch['period_label'] ? ' — ' . e($formBatch['period_label']) : ' #' . $formBatch['id']) ?>
            <span class="text-gray-400 font-normal text-sm">(Pathao/Steadfast API প্যারামিটার)</span>
        </h3>

        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mb-5 p-4 rounded-xl text-sm <?= $flash['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$courierConfigured): ?>
            <p class="text-sm text-gray-500">কুরিয়ারে পাঠাতে চাইলে আগে <a href="settings.php" class="text-indigo-600 font-semibold">সাইট সেটিংস</a> এ গিয়ে প্রোভাইডার ও ক্রেডেনশিয়াল বসান।</p>
        <?php else: ?>
        <form method="post" action="send-to-courier.php" onsubmit="return handleCourierFormSubmit(event);" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
            <input type="hidden" name="batch_id" value="<?= $formMode === 'edit' && $formBatch ? $formBatch['id'] : '' ?>">
            <input type="hidden" name="return_url" value="courier.php?action=view&id=<?= $viewRow['id'] ?>">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">লেবেল (কোন মাস/কিস্তির চালান)</label>
                <input type="text" name="period_label" value="<?= e($fPeriodLabel) ?>" list="periodLabelSuggestions" placeholder="যেমন: আগস্ট ২০২৬" class="w-full border rounded-xl px-4 py-2.5">
                <datalist id="periodLabelSuggestions">
                    <?php foreach ($periodLabelSuggestions as $pl): ?><option value="<?= e($pl) ?>"><?php endforeach; ?>
                </datalist>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">recipient_name *</label>
                    <input type="text" name="recipient_name" value="<?= e($fRecipientName) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">recipient_phone *</label>
                    <input type="text" name="recipient_phone" value="<?= e($fRecipientPhone) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">recipient_secondary_phone (ঐচ্ছিক)</label>
                    <input type="text" name="recipient_secondary_phone" value="<?= e($fRecipientSecondaryPhone) ?>" class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">amount_to_collect (কালেকশন পরিমাণ) *</label>
                    <input type="number" step="0.01" min="0" name="amount_to_collect" value="<?= e(number_format((float) $fAmountToCollect, 2, '.', '')) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">recipient_address *</label>
                <textarea name="recipient_address" rows="2" required class="w-full border rounded-xl px-4 py-2.5"><?= e($fRecipientAddress) ?></textarea>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">item_description</label>
                    <input type="text" name="item_description" value="<?= e($fItemDescription) ?>" class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">item_quantity</label>
                    <input type="number" min="1" name="item_quantity" value="<?= (int) $fItemQuantity ?>" class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">item_weight (কেজি, ন্যূনতম ০.৫, সর্বোচ্চ ১০)</label>
                    <input type="number" step="0.1" min="0.5" max="10" name="item_weight" value="<?= e((string) $fItemWeight) ?>" class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">item_type</label>
                    <select name="item_type" class="w-full border rounded-xl px-4 py-2.5">
                        <?php foreach ($itemTypeLabels as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= $val === (int) $fItemType ? 'selected' : '' ?>><?= e($lbl) ?> (<?= $val ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">delivery_type (শুধু Pathao)</label>
                    <select name="delivery_type" class="w-full border rounded-xl px-4 py-2.5">
                        <?php foreach ($deliveryTypeLabels as $val => $lbl): ?>
                            <option value="<?= $val ?>" <?= $val === (int) $fDeliveryType ? 'selected' : '' ?>><?= e($lbl) ?> (<?= $val ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">special_instruction (ঐচ্ছিক)</label>
                <textarea name="special_instruction" rows="2" class="w-full border rounded-xl px-4 py-2.5"><?= e($fSpecialInstruction) ?></textarea>
            </div>

            <p class="text-xs text-gray-400">recipient_city/zone/area ইচ্ছাকৃতভাবে খালি রাখা হয়েছে — Pathao ঠিকানা থেকে অটো ডিটেক্ট করে (ডকুমেন্টেশন অনুযায়ী ঐচ্ছিক)। এই ব্যাচের তথ্য সেভ করলে/পাঠালে স্থায়ীভাবে সেভ থাকবে।</p>

            <div class="flex flex-wrap gap-3">
                <button type="submit" name="save_only" value="1" formaction="courier.php?action=save-data" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 font-bold px-6 py-2.5 rounded-xl">
                    সেভ করুন
                </button>
                <button type="submit" name="send_courier" value="1" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">
                    <?= $formMode === 'edit' && $formBatch && $formBatch['send_status'] === 'sent' ? 'সেভ করে আবার পাঠান (রিসেন্ড)' : 'সেভ করে কুরিয়ারে পাঠান' ?>
                </button>
                <?php if ($formMode === 'edit' && $formBatch): ?>
                    <a href="courier.php?action=view&id=<?= $viewRow['id'] ?>&batch=new" class="text-gray-500 text-sm self-center">এটা বাদে নতুন ব্যাচ শুরু করুন</a>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>

    <a href="courier.php" class="inline-block text-gray-500 text-sm">← তালিকায় ফিরে যান</a>
</div>
<?php endif; ?>

<script>
    // "সেভ করুন" বাটনে ক্লিক করলে সরাসরি সাবমিট হবে (কোনো API কল হয় না বলে ওয়ার্নিং লাগে না),
    // "সেভ করে কুরিয়ারে পাঠান" বাটনে ক্লিক করলে showConfirmModal() (layout-bottom.php তে শেয়ার্ড) দিয়ে একবার নিশ্চিত করা হয়
    function handleCourierFormSubmit(e) {
        var submitter = e.submitter;
        if (submitter && submitter.name === 'save_only') {
            return true;
        }
        e.preventDefault();
        showConfirmModal('এই তথ্য দিয়ে কুরিয়ারে পাঠাতে চান?', function () {
            e.target.submit();
        }, 'কুরিয়ার নিশ্চিতকরণ');
        return false;
    }

    // লিস্ট পেজে বাল্ক সিলেক্ট/পাঠানো
    var courierBnDigits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    function courierToBn(n) { return String(n).replace(/[0-9]/g, function (d) { return courierBnDigits[d]; }); }
    function updateCourierSelCount() {
        var checked = document.querySelectorAll('.courier-select:checked').length;
        var countEl = document.getElementById('courierSelCount');
        var btnEl = document.getElementById('courierBulkBtn');
        if (countEl) { countEl.textContent = courierToBn(checked); }
        if (btnEl) { btnEl.disabled = checked === 0; }
    }
    // "সব নির্বাচন" — ইতিমধ্যে পাঠানো/নিষ্ক্রিয় রো disabled, সেগুলো বাদ পড়ে
    function toggleAllCourierSelect(master) {
        document.querySelectorAll('.courier-select').forEach(function (cb) { if (!cb.disabled) { cb.checked = master.checked; } });
        updateCourierSelCount();
    }
    function getSelectedCourierIds() {
        return Array.from(document.querySelectorAll('.courier-select:checked')).map(function (cb) { return cb.value; });
    }
    function injectBulkIds(form, ids) {
        form.querySelectorAll('input[name="ids[]"]').forEach(function (el) { el.remove(); });
        ids.forEach(function (id) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'ids[]';
            inp.value = id;
            form.appendChild(inp);
        });
    }
    // নির্বাচিত (ইতিমধ্যে তৈরি) পার্সেলগুলো কুরিয়ারে পাঠানো — নতুন ব্যাচ তৈরি হয় না,
    // এই ব্যাচগুলোই যেমন আছে তেমনই যায় (তৈরি হয় শুধু courier-prepare.php-এ)
    function submitBulkCourier() {
        var ids = getSelectedCourierIds();
        if (ids.length === 0) { return; }
        var total = 0;
        document.querySelectorAll('.courier-select:checked').forEach(function (cb) {
            var amtCell = cb.closest('tr').querySelector('td:nth-child(10)');
            if (amtCell) { total += parseFloat(amtCell.textContent.replace(/[^0-9.]/g, '')) || 0; }
        });
        showConfirmModal('নির্বাচিত ' + courierToBn(ids.length) + ' টি পার্সেল এখনই কুরিয়ারে পাঠাতে চান? মোট কালেকশন ৳' + courierToBn(Math.round(total)) + '। পাঠানোর পর আর ফেরানো যাবে না।', function () {
            var form = document.getElementById('bulkCourierForm');
            injectBulkIds(form, ids);
            form.submit();
        }, 'কুরিয়ারে পাঠান');
    }

    // "সক্রিয়?" টগল — চালু করার সময় ওয়ার্নিং লাগে না, শুধু বন্ধ (নিষ্ক্রিয়) করার সময় একবার নিশ্চিত করা হয়,
    // নিশ্চিত করলে সাথে সাথে ফর্ম সাবমিট হয়ে যায় (registrations.php এর confirmStatusChange() এর প্যাটার্ন)
    function handleCourierActiveToggle(checkbox) {
        var turningOn = checkbox.checked;
        checkbox.checked = !turningOn; // Confirm না করা পর্যন্ত আগের অবস্থায় দেখাবে
        var message = turningOn
            ? 'এই রেজিস্ট্রেশনটি আবার সক্রিয় করতে চান? এটা কুরিয়ার সিলেকশনে ফিরে আসবে।'
            : 'এই রেজিস্ট্রেশনটি নিষ্ক্রিয় করতে চান? এটা তালিকায় দেখা যাবে কিন্তু বাছাই/পাঠানোর জন্য নেওয়া যাবে না — পরে চাইলে আবার সক্রিয় করা যাবে।';
        showConfirmModal(message, function () {
            checkbox.checked = turningOn;
            checkbox.form.submit();
        }, turningOn ? 'সক্রিয় করার নিশ্চিতকরণ' : 'নিষ্ক্রিয় করার নিশ্চিতকরণ');
    }

</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
