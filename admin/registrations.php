<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/courier-notes.php';
require_once __DIR__ . '/../includes/bd-districts.php';
admin_require_login();

$db = get_db();
$pageTitle = 'রেজিস্ট্রেশন/অর্ডার';
$action = $_GET['action'] ?? 'list';

$statusLabels = [
    'pending'   => ['পেন্ডিং', 'bg-yellow-100 text-yellow-800'],
    'confirmed' => ['কনফার্ম', 'bg-blue-100 text-blue-800'],
    'shipped'   => ['পাঠানো হয়েছে', 'bg-purple-100 text-purple-800'],
    'delivered' => ['ডেলিভার্ড', 'bg-green-100 text-green-800'],
    'cancelled' => ['বাতিল', 'bg-red-100 text-red-800'],
];
$typeLabels = ['course' => 'কোর্স', 'worksheet' => 'ওয়ার্কশিট', 'product' => 'প্রোডাক্ট'];

// যে স্ট্যাটাসগুলোতে থাকলে অর্ডারটা আয় হিসেবে গণনা হবে — এর বাইরে গেলে আয় সরে যাবে
const INCOME_STATUSES = ['confirmed', 'shipped', 'delivered'];

// ফর্ম থেকে পাঠানো return_url শুধুমাত্র চেনা অ্যাডমিন পেজের মধ্যেই থাকা নিশ্চিত করা (open-redirect ঠেকাতে)
// course-data.php ও এখান থেকে (একই action=delete/update-details ব্যবহার করে) রিটার্ন করতে পারে
function safe_return_url(?string $url, string $fallback): string
{
    $allowedPrefixes = ['registrations.php', 'course-data.php'];
    foreach ($allowedPrefixes as $prefix) {
        if ($url && strpos($url, $prefix) === 0) {
            return $url;
        }
    }
    return $fallback;
}

// স্ট্যাটাস অনুযায়ী আয় অটোমেটিক যোগ/বাদ দেওয়া — status 'confirmed'/'shipped'/'delivered' হলে আয়, নাহলে আয় বাদ
function sync_income_for_status(PDO $db, array $reg, string $newStatus): void
{
    $shouldHaveIncome = in_array($newStatus, INCOME_STATUSES, true);

    if ($shouldHaveIncome && !$reg['income_approved']) {
        // কোর্সের দাম এখন course_batches-এ (courses parent টেবিলে শুধু title) — item_id (course) সরাসরি course_batches.id পয়েন্ট করে
        $tableMap = ['course' => 'course_batches', 'worksheet' => 'worksheets', 'product' => 'products'];
        $amount = 0.0;
        if (isset($tableMap[$reg['type']])) {
            $itemStmt = $db->prepare("SELECT price FROM `{$tableMap[$reg['type']]}` WHERE id = :id");
            $itemStmt->execute(['id' => $reg['item_id']]);
            $item = $itemStmt->fetch();
            if ($item) {
                $amount = parse_price_to_number($item['price']) * max(1, (int) $reg['quantity']);
            }
        }
        // ⚠️ ফলব্যাক (২০২৬-০৭-২০ এ অডিটে ধরা পড়া বাগ): আইটেমটা (কোর্স-ব্যাচ/ওয়ার্কশিট/প্রোডাক্ট) যদি
        // ইতিমধ্যে ডিলিট/আর্কাইভ হয়ে গিয়ে থাকে, তাহলে দাম খুঁজে পাওয়া যায় না → $amount = 0 → আগে
        // **নীরবে কিছুই হতো না**: রেজিস্ট্রেশন "কনফার্ম" দেখাত কিন্তু আয় বইয়ে উঠত না, কোনো সতর্কতাও নয়।
        // এখন আগের অনুমোদনে সেভ করা পরিমাণ (income_amount স্ন্যাপশট) থেকে হিসাব করা হয়।
        if ($amount <= 0 && !empty($reg['income_amount'])) {
            $amount = (float) $reg['income_amount'];
        }
        if ($amount <= 0) {
            // এখনো ঠিক করা গেল না — নীরব না থেকে অ্যাডমিনকে জানানো হয়, যাতে হাতে যোগ করে নিতে পারেন
            set_flash('error',
                'স্ট্যাটাস বদলেছে, কিন্তু আয় যোগ করা যায়নি — এই অর্ডারের আইটেমটি ("' . $reg['item_title'] . '") '
                . 'ডিলিট/আর্কাইভ হয়ে গেছে বলে দাম পাওয়া যাচ্ছে না। "আয়" পেজ থেকে পরিমাণটা হাতে যোগ করে নিন, '
                . 'অথবা আর্কাইভ পেজ থেকে আইটেমটি ফিরিয়ে এনে আবার চেষ্টা করুন।');
        }
        if ($amount > 0) {
            $categoryId = find_or_create_finance_category('income', registration_type_to_category_name($reg['type']));
            $db->prepare(
                'INSERT INTO income (category_id, registration_id, amount, description, income_date) VALUES (:cat, :reg, :amt, :desc, CURDATE())'
            )->execute([
                'cat' => $categoryId,
                'reg' => $reg['id'],
                'amt' => $amount,
                'desc' => $reg['item_title'] . ' - ' . $reg['customer_name'],
            ]);
            $db->prepare('UPDATE registrations SET income_approved = 1, income_amount = :amt, approved_at = NOW() WHERE id = :id')
                ->execute(['amt' => $amount, 'id' => $reg['id']]);
        }
    } elseif (!$shouldHaveIncome && $reg['income_approved']) {
        $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $reg['id']]);
        // ⚠️ income_amount ইচ্ছাকৃতভাবে **মোছা হয় না** (আগে NULL করা হতো) — এটা "সর্বশেষ জানা পরিমাণ"
        // হিসেবে থেকে যায়, যাতে আইটেম ডিলিট হয়ে গেলেও পরে আবার কনফার্ম করলে আয় ঠিক পরিমাণে ফিরে আসে।
        // প্রদর্শনে কোনো প্রভাব নেই — তালিকা ও ডিটেইল দুই জায়গাতেই income_approved দেখে দেখানো হয়।
        $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')
            ->execute(['id' => $reg['id']]);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-status') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }
    $id = (int) ($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (isset($statusLabels[$status])) {
        $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $reg = $stmt->fetch();

        if ($reg) {
            $db->prepare('UPDATE registrations SET status = :s WHERE id = :id')->execute(['s' => $status, 'id' => $id]);
            sync_income_for_status($db, $reg, $status);
            set_flash('success', 'স্ট্যাটাস আপডেট হয়েছে।');
        }
    }
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'unapprove-income') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $id]);
    // income_amount রেখে দেওয়া হয় ("সর্বশেষ জানা পরিমাণ") — উপরের sync_income_for_status() এর একই কারণে
    $db->prepare('UPDATE registrations SET income_approved = 0, approved_at = NULL WHERE id = :id')->execute(['id' => $id]);

    set_flash('success', 'আয় থেকে বাদ দেওয়া হয়েছে।');
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-income-amount') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);

    if ($amount <= 0) {
        set_flash('error', 'সঠিক পরিমাণ দিন।');
        redirect($returnUrl);
    }

    $db->prepare('UPDATE income SET amount = :amt WHERE registration_id = :id')->execute(['amt' => $amount, 'id' => $id]);
    $db->prepare('UPDATE registrations SET income_amount = :amt WHERE id = :id')->execute(['amt' => $amount, 'id' => $id]);

    set_flash('success', 'আয়ের পরিমাণ আপডেট করা হয়েছে।');
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update-details') {
    $id = (int) ($_POST['id'] ?? 0);
    $editUrl = 'registrations.php?action=edit&id=' . $id;
    $viewUrl = 'registrations.php?action=view&id=' . $id;

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($editUrl);
    }

    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $reg = $stmt->fetch();
    if (!$reg) {
        redirect('registrations.php');
    }

    $customerName = trim($_POST['customer_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($customerName === '') {
        set_flash('error', 'নাম দিন।');
        redirect($editUrl);
    }
    if (!is_valid_bd_phone($phone)) {
        set_flash('error', 'সঠিক মোবাইল নম্বর দিন (যেমন: 017xxxxxxxx)।');
        redirect($editUrl);
    }

    if ($reg['type'] === 'course') {
        $dob = trim($_POST['date_of_birth'] ?? '');
        $facebookId = trim($_POST['facebook_id'] ?? '');
        $fatherMobile = trim($_POST['father_mobile'] ?? '');

        $dobTimestamp = strtotime($dob);
        if (!$dob || !$dobTimestamp || $dobTimestamp > time()) {
            set_flash('error', 'সঠিক জন্ম তারিখ দিন।');
            redirect($editUrl);
        }
        if ($facebookId === '') {
            set_flash('error', 'ফেসবুক আইডি নাম দিন।');
            redirect($editUrl);
        }
        if ($fatherMobile !== '' && !is_valid_bd_phone($fatherMobile)) {
            set_flash('error', 'বাবার মোবাইল নম্বরটি সঠিক নয়।');
            redirect($editUrl);
        }

        // এই রেজিস্ট্রেশনে মূলত পার্সেল/ডেলিভারি তথ্য ছিল কিনা (hide_parcel কোর্সে থাকে না)
        $hasParcel = $reg['receiver_name'] !== null || $reg['receiver_phone'] !== null || $reg['address'] !== null;
        $receiverName = null;
        $receiverPhone = null;
        $address = null;
        if ($hasParcel) {
            $receiverName = trim($_POST['receiver_name'] ?? '');
            $receiverPhone = trim($_POST['receiver_phone'] ?? '');
            $address = trim($_POST['address'] ?? '');
            if ($receiverName === '') {
                set_flash('error', 'রিসিভারের নাম দিন।');
                redirect($editUrl);
            }
            if (!is_valid_bd_phone($receiverPhone)) {
                set_flash('error', 'রিসিভারের সঠিক মোবাইল নম্বর দিন।');
                redirect($editUrl);
            }
            if ($address === '') {
                set_flash('error', 'ঠিকানা দিন।');
                redirect($editUrl);
            }
        }

        $db->prepare(
            'UPDATE registrations SET customer_name = :name, phone = :phone, date_of_birth = :dob, facebook_id = :fb, father_mobile = :fm, receiver_name = :rn, receiver_phone = :rp, address = :addr, notes = :notes WHERE id = :id'
        )->execute([
            'name' => $customerName,
            'phone' => $phone,
            'dob' => date('Y-m-d', $dobTimestamp),
            'fb' => $facebookId,
            'fm' => $fatherMobile !== '' ? $fatherMobile : null,
            'rn' => $receiverName,
            'rp' => $receiverPhone,
            'addr' => $address,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $id,
        ]);
    } else {
        $email = trim($_POST['email'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $district = trim($_POST['district'] ?? '');
        $thana = trim($_POST['thana'] ?? '');
        $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            set_flash('error', 'সঠিক ইমেইল ঠিকানা দিন।');
            redirect($editUrl);
        }
        if ($address === '') {
            set_flash('error', 'ঠিকানা দিন।');
            redirect($editUrl);
        }
        // জেলা/থানা এখন অর্ডার ফর্মে নেই (কাস্টমার পুরো ঠিকানায় লিখে দেন) — তাই এখানে ঐচ্ছিক, দিলে valid হতে হবে
        if ($district !== '' && !in_array($district, bd_districts(), true)) {
            set_flash('error', 'সঠিক জেলা নির্বাচন করুন।');
            redirect($editUrl);
        }

        $db->prepare(
            'UPDATE registrations SET customer_name = :name, phone = :phone, email = :email, address = :addr, district = :dist, thana = :thana, quantity = :qty, notes = :notes WHERE id = :id'
        )->execute([
            'name' => $customerName,
            'phone' => $phone,
            'email' => $email !== '' ? $email : null,
            'addr' => $address,
            'dist' => $district !== '' ? $district : null,
            'thana' => $thana !== '' ? $thana : null,
            'qty' => $quantity,
            'notes' => $notes !== '' ? $notes : null,
            'id' => $id,
        ]);
    }

    set_flash('success', 'তথ্য আপডেট করা হয়েছে।');
    redirect($viewUrl);
}

// রেজিস্ট্রেশনে কুরিয়ার নোট যোগ/মোছা — এখানে (approve করার সময়) দেওয়া নোট
// courier-prepare.php-এর কার্ডে অটোমেটিক চিপ হিসেবে দেখা যায় (একই registration_courier_notes টেবিল)।
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add-note', 'del-note'], true)) {
    $rid = (int) ($_POST['registration_id'] ?? 0);
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php?action=view&id=' . $rid);

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    if ($action === 'add-note') {
        if (add_registration_note($db, $rid, (int) ($_POST['note_type_id'] ?? 0), $_POST['custom_text'] ?? '', $_POST['color'] ?? 'amber')) {
            set_flash('success', 'নোট যোগ হয়েছে — কুরিয়ার প্রস্তুত পেজেও দেখা যাবে।');
        } else {
            set_flash('error', 'নোট খালি — একটা তৈরি নোট বাছুন অথবা কাস্টম লেখা দিন।');
        }
    } else {
        delete_registration_note($db, $rid, (int) ($_POST['note_id'] ?? 0));
        set_flash('success', 'নোট মোছা হয়েছে।');
    }
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $returnUrl = safe_return_url($_POST['return_url'] ?? null, 'registrations.php');

    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect($returnUrl);
    }

    $id = (int) ($_POST['id'] ?? 0);

    // ডিলিটের আগে পুরো অর্ডার (রেজিস্ট্রেশন + আয়ের এন্ট্রি + কুরিয়ার ব্যাচ/শিপমেন্ট) আর্কাইভে রাখা হয় —
    // আর্কাইভ পেজ থেকে হুবহু (আসল id ও আয়ের হিসাব সহ) ফিরিয়ে আনা যায়। archive_entity() income/courier
    // child গুলো এখনই তুলে নেয়, তারপর নিচের DELETE লাইভ থেকে মোছে (income + registration→cascade)।
    archive_entity($db, 'registrations', $id);
    $db->prepare('DELETE FROM income WHERE registration_id = :id')->execute(['id' => $id]);
    $db->prepare('DELETE FROM registrations WHERE id = :id')->execute(['id' => $id]);

    set_flash('success', 'রেজিস্ট্রেশন/অর্ডার আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect($returnUrl);
}

$viewRow = null;
$shipments = [];
$viewNotes = [];
$noteTypes = [];
if ($action === 'view' || $action === 'edit') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = $db->prepare('SELECT * FROM registrations WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $viewRow = $stmt->fetch();
    if (!$viewRow) {
        redirect('registrations.php');
    }
    if ($action === 'view') {
        $shipStmt = $db->prepare('SELECT * FROM courier_shipments WHERE registration_id = :id ORDER BY created_at DESC');
        $shipStmt->execute(['id' => $id]);
        $shipments = $shipStmt->fetchAll();
        $viewNotes = fetch_one_registration_notes($db, $id);
        $noteTypes = fetch_courier_note_types($db);
    }
}

$courierConfigured = get_setting('courier_active_provider') !== '';

$filterStatus = $_GET['status'] ?? '';
$filterType = $_GET['type'] ?? '';
$search = trim($_GET['q'] ?? '');
$filterItem = trim($_GET['item'] ?? '');
$filterBatch = trim($_GET['batch'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$rows = [];
$totalRows = 0;
$totalPages = 1;
$distinctItems = [];
$distinctBatches = [];

if ($action === 'list') {
    $where = [];
    $params = [];

    if ($filterStatus && isset($statusLabels[$filterStatus])) {
        $where[] = 'status = :status';
        $params['status'] = $filterStatus;
    }
    if ($filterType && isset($typeLabels[$filterType])) {
        $where[] = 'type = :type';
        $params['type'] = $filterType;
    }
    if ($filterItem !== '') {
        $where[] = 'item_title = :item';
        $params['item'] = $filterItem;
    }
    if ($filterBatch !== '') {
        $where[] = 'batch = :batch';
        $params['batch'] = $filterBatch;
    }
    if ($search !== '') {
        $where[] = '(customer_name LIKE :q1 OR phone LIKE :q2 OR item_title LIKE :q3)';
        $like = '%' . $search . '%';
        $params['q1'] = $like;
        $params['q2'] = $like;
        $params['q3'] = $like;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(created_at) >= :date_from';
        $params['date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(created_at) <= :date_to';
        $params['date_to'] = $dateTo;
    }

    $whereSql = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $db->prepare('SELECT COUNT(*) c FROM registrations' . $whereSql);
    $countStmt->execute($params);
    $totalRows = (int) $countStmt->fetch()['c'];
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $sql = 'SELECT * FROM registrations' . $whereSql . ' ORDER BY created_at DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // ফিল্টার ড্রপডাউনে দেখানোর জন্য আইটেমের নামের তালিকা (টাইপ ফিল্টার করা থাকলে সেই টাইপেই সীমাবদ্ধ)
    $itemsSql = 'SELECT DISTINCT item_title FROM registrations';
    $itemsParams = [];
    if ($filterType && isset($typeLabels[$filterType])) {
        $itemsSql .= ' WHERE type = :type';
        $itemsParams['type'] = $filterType;
    }
    $itemsSql .= ' ORDER BY item_title';
    $itemsStmt = $db->prepare($itemsSql);
    $itemsStmt->execute($itemsParams);
    $distinctItems = $itemsStmt->fetchAll(PDO::FETCH_COLUMN);

    // ব্যাচ ফিল্টার ড্রপডাউন — শুধু কোর্স টাইপে প্রযোজ্য (registrations.batch), নির্দিষ্ট আইটেম/কোর্স বাছাই করা
    // থাকলে সেই কোর্সের ব্যাচগুলোতেই সীমাবদ্ধ থাকে (কোনো আইটেম বাছাই না থাকলে সব কোর্সের সব ব্যাচ দেখায়)
    $batchesSql = "SELECT DISTINCT batch FROM registrations WHERE type = 'course' AND batch IS NOT NULL AND batch != ''";
    $batchesParams = [];
    if ($filterItem !== '') {
        $batchesSql .= ' AND item_title = :item';
        $batchesParams['item'] = $filterItem;
    }
    $batchesSql .= ' ORDER BY batch';
    $batchesStmt = $db->prepare($batchesSql);
    $batchesStmt->execute($batchesParams);
    $distinctBatches = $batchesStmt->fetchAll(PDO::FETCH_COLUMN);
}

// পেজ নম্বর বাদে বাকি সব সক্রিয় ফিল্টার — লিংক/ফর্ম তৈরিতে বারবার ব্যবহার হয়
$activeFilters = array_filter([
    'status' => $filterStatus,
    'type' => $filterType,
    'q' => $search,
    'item' => $filterItem,
    'batch' => $filterBatch,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
], fn($v) => $v !== '' && $v !== null);

function reg_url(array $overrides = []): string
{
    global $activeFilters;
    $params = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($params);
    return 'registrations.php' . ($qs !== '' ? '?' . $qs : '');
}

// লিস্ট পেজে থাকা অবস্থাতেই স্ট্যাটাস পরিবর্তনের পর একই ফিল্টার/সার্চ/পেজে ফিরে আসার জন্য
$currentListUrl = reg_url($page > 1 ? ['page' => $page] : []);
$hasActiveFilters = !empty($activeFilters);

require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($action === 'list'): ?>
    <div class="flex flex-wrap gap-2 mb-3">
        <a href="<?= e(reg_url(['status' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === '' ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>">সব স্ট্যাটাস</a>
        <?php foreach ($statusLabels as $key => $s): ?>
            <a href="<?= e(reg_url(['status' => $key])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterStatus === $key ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($s[0]) ?></a>
        <?php endforeach; ?>
    </div>

    <div class="flex flex-wrap gap-2 mb-4">
        <a href="<?= e(reg_url(['type' => null, 'item' => null, 'batch' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === '' ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>">সব টাইপ</a>
        <?php foreach ($typeLabels as $key => $label): ?>
            <a href="<?= e(reg_url(['type' => $key, 'item' => null, 'batch' => null])) ?>" class="px-4 py-2 rounded-xl text-sm font-semibold <?= $filterType === $key ? 'bg-teal-600 text-white' : 'bg-white text-gray-600' ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </div>

    <form method="get" action="registrations.php" id="regFilterForm" class="mb-3 bg-white rounded-2xl shadow p-4">
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= e($filterStatus) ?>"><?php endif; ?>
        <?php if ($filterType): ?><input type="hidden" name="type" value="<?= e($filterType) ?>"><?php endif; ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-6 gap-3">
            <div class="lg:col-span-2">
                <label class="block text-xs font-semibold text-gray-500 mb-1">নাম, ফোন বা আইটেম</label>
                <input type="text" name="q" value="<?= e($search) ?>" placeholder="টাইপ করা মাত্র ফলাফল আপডেট হবে..." id="regSearchInput" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">নির্দিষ্ট আইটেম</label>
                <select name="item" id="regItemSelect" onchange="document.getElementById('regBatchSelect').value=''; document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব আইটেম</option>
                    <?php foreach ($distinctItems as $itemTitle): ?>
                        <option value="<?= e($itemTitle) ?>" <?= $filterItem === $itemTitle ? 'selected' : '' ?>><?= e($itemTitle) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">ব্যাচ (কোর্স)</label>
                <select name="batch" id="regBatchSelect" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                    <option value="">সব ব্যাচ</option>
                    <?php foreach ($distinctBatches as $b): ?>
                        <option value="<?= e($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ থেকে</label>
                <input type="date" name="date_from" value="<?= e($dateFrom) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold text-gray-500 mb-1">তারিখ পর্যন্ত</label>
                <input type="date" name="date_to" value="<?= e($dateTo) ?>" onchange="document.getElementById('regFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
            </div>
        </div>
        <?php if ($hasActiveFilters): ?>
        <div class="flex flex-wrap gap-2 mt-3">
            <a href="registrations.php" class="bg-gray-100 hover:bg-gray-200 text-gray-600 font-semibold px-4 py-2.5 rounded-xl text-sm">✕ সব ফিল্টার মুছুন</a>
        </div>
        <?php endif; ?>
    </form>

    <p class="text-sm text-gray-500 mb-4">মোট <strong><?= $totalRows ?></strong> টি ফলাফল<?= $totalPages > 1 ? " — পৃষ্ঠা {$page}/{$totalPages}" : '' ?></p>

    <?php
        // স্ট্যাটাস ড্রপডাউন সেল — তিনটা কলাম-লেআউটেই হুবহু একই, তাই একটা ফাংশনে বের করা হলো (DRY)
        function reg_status_cell(array $row, array $statusLabels, string $currentListUrl): void
        {
            $s = $statusLabels[$row['status']] ?? ['?', 'bg-gray-100'];
            ?>
            <form method="post" action="registrations.php?action=update-status">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $row['id'] ?>">
                <input type="hidden" name="return_url" value="<?= e($currentListUrl) ?>">
                <div class="relative inline-block">
                    <select name="status" data-original="<?= e($row['status']) ?>" onchange="confirmStatusChange(this)" class="status-select appearance-none pl-3 pr-7 py-1.5 rounded-full text-xs font-semibold border-0 cursor-pointer shadow-sm hover:shadow transition-shadow focus:outline-none focus:ring-2 focus:ring-indigo-400 focus:ring-offset-1 <?= $s[1] ?>">
                        <?php foreach ($statusLabels as $key => $lbl): ?>
                            <option value="<?= e($key) ?>" <?= $row['status'] === $key ? 'selected' : '' ?>><?= e($lbl[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" class="w-3 h-3 absolute right-2 top-1/2 -translate-y-1/2 pointer-events-none opacity-60"></i>
                </div>
            </form>
            <?php
        }
        function reg_income_cell(array $row): void
        {
            if ($row['income_approved']): ?>
                <span class="text-green-700 font-semibold text-xs">✅ ৳<?= number_format((float) $row['income_amount'], 2) ?></span>
            <?php else: ?>
                <span class="text-gray-300 text-xs">—</span>
            <?php endif;
        }
    ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <?php if ($filterType === 'course'): ?>
            <!-- কোর্স রেজিস্ট্রেশন ফর্মে যে ফিল্ডগুলো নেওয়া হয় (course-register.php) হুবহু সেই ক্রমে -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">আইটেম</th>
                    <th class="py-3 px-4">ব্যাচ</th>
                    <th class="py-3 px-4">শিশুর নাম</th>
                    <th class="py-3 px-4">মোবাইল নাম্বার (মা)</th>
                    <th class="py-3 px-4">জন্ম তারিখ</th>
                    <th class="py-3 px-4">ফেসবুক আইডি নাম</th>
                    <th class="py-3 px-4">মোবাইল নাম্বার (বাবা)</th>
                    <th class="py-3 px-4">রিসিভার নাম</th>
                    <th class="py-3 px-4">রিসিভার নাম্বার</th>
                    <th class="py-3 px-4">ঠিকানা</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="14" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['batch'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($row['customer_name']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['phone']) ?></td>
                    <td class="py-2.5 px-4"><?= $row['date_of_birth'] ? e(format_date_bn($row['date_of_birth'])) : '-' ?></td>
                    <td class="py-2.5 px-4"><?= e($row['facebook_id'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($row['father_mobile'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($row['receiver_name'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($row['receiver_phone'] ?: '-') ?></td>
                    <td class="py-2.5 px-4 max-w-[200px] truncate" title="<?= e($row['address'] ?? '') ?>"><?= e($row['address'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?= e($row['created_at']) ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php elseif ($filterType === 'worksheet' || $filterType === 'product'): ?>
            <!-- ওয়ার্কশিট/প্রোডাক্ট অর্ডার ফর্মে যে ফিল্ডগুলো নেওয়া হয় (register.php, দুটোই একই ফর্ম শেয়ার করে) হুবহু সেই ক্রমে -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">মোবাইল নম্বর</th>
                    <th class="py-3 px-4">নাম</th>
                    <th class="py-3 px-4">ইমেইল</th>
                    <th class="py-3 px-4">ঠিকানা</th>
                    <th class="py-3 px-4">আইটেম</th>
                    <th class="py-3 px-4">পরিমাণ</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4"><?= e($row['phone']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['customer_name']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['email'] ?: '-') ?></td>
                    <td class="py-2.5 px-4 max-w-[200px] truncate" title="<?= e($row['address'] ?? '') ?>"><?= e($row['address'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                    <td class="py-2.5 px-4"><?= (int) $row['quantity'] ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?= e($row['created_at']) ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php else: ?>
            <!-- "সব টাইপ" — কোর্স ও ওয়ার্কশিট/প্রোডাক্ট মিশ্রিত থাকে বলে দুই ফর্মেই কমন এমন ফিল্ড দেখানো হয়; নির্দিষ্ট টাইপ ফিল্টার করলে উপরের বিস্তারিত ভিউ পাওয়া যায় -->
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">নাম</th>
                    <th class="py-3 px-4">ফোন</th>
                    <th class="py-3 px-4">টাইপ</th>
                    <th class="py-3 px-4">আইটেম</th>
                    <th class="py-3 px-4">ব্যাচ</th>
                    <th class="py-3 px-4">ঠিকানা</th>
                    <th class="py-3 px-4">স্ট্যাটাস</th>
                    <th class="py-3 px-4">আয়</th>
                    <th class="py-3 px-4">তারিখ</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="py-6 px-4 text-center text-gray-400"><?= $hasActiveFilters ? 'এই ফিল্টারে কোনো ফলাফল পাওয়া যায়নি।' : 'কোনো ডেটা নেই।' ?></td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4"><?= e($row['customer_name']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['phone']) ?></td>
                    <td class="py-2.5 px-4"><?= e($typeLabels[$row['type']] ?? $row['type']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['item_title']) ?></td>
                    <td class="py-2.5 px-4"><?= e($row['batch'] ?: '-') ?></td>
                    <td class="py-2.5 px-4 max-w-[200px] truncate" title="<?= e($row['address'] ?? '') ?>"><?= e($row['address'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?php reg_status_cell($row, $statusLabels, $currentListUrl); ?></td>
                    <td class="py-2.5 px-4"><?php reg_income_cell($row); ?></td>
                    <td class="py-2.5 px-4"><?= e($row['created_at']) ?></td>
                    <td class="py-2.5 px-4"><a href="registrations.php?action=view&id=<?= $row['id'] ?>" class="text-indigo-600 font-semibold">বিস্তারিত</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php endif; ?>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="flex flex-wrap items-center justify-center gap-1.5 mt-5">
            <a href="<?= e(reg_url(['page' => max(1, $page - 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page <= 1 ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">‹ আগে</a>
            <?php
                $rangeStart = max(1, $page - 2);
                $rangeEnd = min($totalPages, $page + 2);
                if ($rangeStart > 1) {
                    echo '<a href="' . e(reg_url(['page' => 1])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">1</a>';
                    if ($rangeStart > 2) { echo '<span class="px-1 text-gray-400">…</span>'; }
                }
                for ($p = $rangeStart; $p <= $rangeEnd; $p++) {
                    $active = $p === $page;
                    echo '<a href="' . e(reg_url(['page' => $p])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold ' . ($active ? 'bg-indigo-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-50 shadow') . '">' . $p . '</a>';
                }
                if ($rangeEnd < $totalPages) {
                    if ($rangeEnd < $totalPages - 1) { echo '<span class="px-1 text-gray-400">…</span>'; }
                    echo '<a href="' . e(reg_url(['page' => $totalPages])) . '" class="px-3.5 py-2 rounded-lg text-sm font-semibold bg-white text-gray-600 hover:bg-gray-50 shadow">' . $totalPages . '</a>';
                }
            ?>
            <a href="<?= e(reg_url(['page' => min($totalPages, $page + 1)])) ?>" class="px-3 py-2 rounded-lg text-sm font-semibold <?= $page >= $totalPages ? 'bg-gray-100 text-gray-300 pointer-events-none' : 'bg-white text-gray-600 hover:bg-gray-50 shadow' ?>">পরে ›</a>
        </div>
    <?php endif; ?>

<?php elseif ($action === 'view'): $s = $statusLabels[$viewRow['status']] ?? ['?', 'bg-gray-100']; ?>
    <div class="max-w-2xl bg-white rounded-2xl shadow p-6 space-y-5">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900"><?= e($viewRow['item_title']) ?></h3>
                <p class="text-gray-500 text-sm">রেফারেন্স #<?= $viewRow['id'] ?> • <?= e($typeLabels[$viewRow['type']] ?? $viewRow['type']) ?></p>
            </div>
            <div class="flex items-center gap-2">
                <span class="px-3 py-1 rounded-full text-xs font-semibold <?= $s[1] ?>"><?= e($s[0]) ?></span>
                <a href="registrations.php?action=edit&id=<?= $viewRow['id'] ?>" class="flex items-center gap-1 text-xs font-semibold text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-300 rounded-lg px-3 py-1.5">
                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i> সম্পাদনা
                </a>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
            <?php if ($viewRow['type'] === 'course'): ?>
                <div><span class="text-gray-500">শিশুর নাম:</span> <span class="font-semibold"><?= e($viewRow['customer_name']) ?></span></div>
                <div><span class="text-gray-500">মোবাইল (মা):</span> <span class="font-semibold"><?= e($viewRow['phone']) ?></span></div>
                <div><span class="text-gray-500">জন্ম তারিখ:</span> <span class="font-semibold"><?= $viewRow['date_of_birth'] ? e(format_date_bn($viewRow['date_of_birth'])) : '-' ?></span></div>
                <div><span class="text-gray-500">ফেসবুক আইডি:</span> <span class="font-semibold"><?= e($viewRow['facebook_id'] ?? '-') ?></span></div>
                <div><span class="text-gray-500">মোবাইল (বাবা):</span> <span class="font-semibold"><?= e($viewRow['father_mobile'] ?: '-') ?></span></div>
                <?php if ($viewRow['receiver_name'] || $viewRow['receiver_phone'] || $viewRow['address']): ?>
                    <div><span class="text-gray-500">রিসিভার নাম:</span> <span class="font-semibold"><?= e($viewRow['receiver_name'] ?: '-') ?></span></div>
                    <div><span class="text-gray-500">রিসিভার নম্বর:</span> <span class="font-semibold"><?= e($viewRow['receiver_phone'] ?: '-') ?></span></div>
                    <div class="sm:col-span-2"><span class="text-gray-500">ঠিকানা:</span> <span class="font-semibold"><?= e($viewRow['address'] ?: '-') ?></span></div>
                <?php else: ?>
                    <div class="sm:col-span-2 text-gray-500 italic">এই কোর্সে পার্সেল/ডেলিভারি তথ্য প্রযোজ্য নয় (ফুল অনলাইন কোর্স)।</div>
                <?php endif; ?>
            <?php else: ?>
                <div><span class="text-gray-500">নাম:</span> <span class="font-semibold"><?= e($viewRow['customer_name']) ?></span></div>
                <div><span class="text-gray-500">ফোন:</span> <span class="font-semibold"><?= e($viewRow['phone']) ?></span></div>
                <div><span class="text-gray-500">ইমেইল:</span> <span class="font-semibold"><?= e($viewRow['email'] ?? '-') ?></span></div>
                <div><span class="text-gray-500">পরিমাণ:</span> <span class="font-semibold"><?= (int) $viewRow['quantity'] ?></span></div>
                <div class="sm:col-span-2"><span class="text-gray-500">ঠিকানা:</span> <span class="font-semibold"><?= e(implode(', ', array_filter([$viewRow['address'], $viewRow['thana'], $viewRow['district']]))) ?></span></div>
            <?php endif; ?>
            <?php if ($viewRow['notes']): ?>
            <div class="sm:col-span-2"><span class="text-gray-500">মন্তব্য:</span> <span class="font-semibold"><?= e($viewRow['notes']) ?></span></div>
            <?php endif; ?>
            <div><span class="text-gray-500">তারিখ:</span> <span class="font-semibold"><?= e($viewRow['created_at']) ?></span></div>
        </div>

        <form method="post" action="registrations.php?action=update-status" class="flex gap-3 items-end pt-2 border-t" data-original="<?= e($viewRow['status']) ?>" onsubmit="return confirmStatusFormSubmit(this)">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
            <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
            <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-700 mb-1">স্ট্যাটাস পরিবর্তন করুন</label>
                <div class="relative">
                    <select name="status" class="w-full appearance-none border rounded-xl pl-4 pr-9 py-2.5 font-medium cursor-pointer hover:border-indigo-300 focus:outline-none focus:ring-2 focus:ring-indigo-400 transition-colors">
                        <?php foreach ($statusLabels as $key => $lbl): ?>
                            <option value="<?= e($key) ?>" <?= $viewRow['status'] === $key ? 'selected' : '' ?>><?= e($lbl[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <i data-lucide="chevron-down" class="w-4 h-4 absolute right-3 top-1/2 -translate-y-1/2 pointer-events-none text-gray-400"></i>
                </div>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">আপডেট করুন</button>
        </form>
        <p class="text-xs text-gray-400 -mt-3">স্ট্যাটাস "কনফার্ম"/"পাঠানো হয়েছে"/"ডেলিভার্ড" করলে স্বয়ংক্রিয়ভাবে আয় যোগ হয়ে যাবে (দাম × পরিমাণ অনুযায়ী)।</p>

        <?php // কুরিয়ার নোট — এখানে দেওয়া নোট "কুরিয়ার পার্সেল প্রস্তুত" পেজের কার্ডে অটোমেটিক দেখা যাবে ?>
        <div class="pt-4 border-t">
            <div class="flex items-center justify-between gap-2 mb-2">
                <h4 class="text-sm font-bold text-gray-700"><i data-lucide="sticky-note" class="w-4 h-4 inline text-amber-500"></i> কুরিয়ার নোট</h4>
                <span class="text-xs text-gray-400">প্রস্তুত পেজে অটো দেখাবে</span>
            </div>
            <div class="flex flex-wrap gap-2 mb-3">
                <?php if (!$viewNotes): ?><span class="text-sm text-gray-400">কোনো নোট নেই।</span><?php endif; ?>
                <?php render_note_chips($viewNotes, 'registrations.php?action=del-note', (int) $viewRow['id'], 'registrations.php?action=view&id=' . (int) $viewRow['id']); ?>
            </div>
            <form method="post" action="registrations.php?action=add-note" class="grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-2 items-end">
                <?= csrf_field() ?>
                <input type="hidden" name="registration_id" value="<?= $viewRow['id'] ?>">
                <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                <?php if ($noteTypes): ?>
                <label class="text-xs text-gray-500">তৈরি নোট
                    <select name="note_type_id" class="block w-full border rounded-xl px-3 py-2.5 text-sm mt-1">
                        <option value="0">— কাস্টম লিখুন —</option>
                        <?php foreach ($noteTypes as $nt): ?><option value="<?= (int) $nt['id'] ?>"><?= e($nt['label']) ?></option><?php endforeach; ?>
                    </select>
                </label>
                <?php endif; ?>
                <label class="text-xs text-gray-500">কাস্টম নোট
                    <input type="text" name="custom_text" placeholder="যেমন: আগের বকেয়া ২০" class="block w-full border rounded-xl px-3 py-2.5 text-sm mt-1">
                </label>
                <div class="flex gap-2">
                    <select name="color" class="border rounded-xl px-2 py-2.5 text-sm" aria-label="রঙ"><?php foreach (courier_note_colors() as $ck => $cv): ?><option value="<?= $ck ?>"><?= $cv ?></option><?php endforeach; ?></select>
                    <button type="submit" class="bg-amber-500 hover:bg-amber-600 text-white font-bold px-4 py-2.5 rounded-xl text-sm whitespace-nowrap">যোগ</button>
                </div>
            </form>
        </div>

        <div class="pt-4 border-t">
            <?php if ($viewRow['income_approved']): ?>
                <div class="bg-green-50 border border-green-200 rounded-xl p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <p class="text-sm text-green-800">✅ আয় হিসেবে যোগ হয়েছে (<?= e($viewRow['approved_at']) ?>)</p>
                        <form method="post" action="registrations.php?action=unapprove-income" onsubmit="return confirmSubmit(this, 'আয় থেকে বাদ দিতে চান? সংশ্লিষ্ট আয়ের এন্ট্রিও মুছে যাবে।');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                            <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                            <button type="submit" class="text-red-600 text-xs font-semibold underline">আয় থেকে বাদ দিন</button>
                        </form>
                    </div>
                    <form method="post" action="registrations.php?action=update-income-amount" class="flex gap-2 items-end">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                        <input type="hidden" name="return_url" value="registrations.php?action=view&id=<?= $viewRow['id'] ?>">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">পরিমাণ পরিবর্তন করুন (৳)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" value="<?= e(number_format((float) $viewRow['income_amount'], 2, '.', '')) ?>" class="border rounded-lg px-3 py-2 text-sm w-36">
                        </div>
                        <button type="submit" class="bg-green-600 hover:bg-green-700 text-white text-xs font-bold px-4 py-2 rounded-lg">আপডেট</button>
                    </form>
                </div>
            <?php else: ?>
                <p class="text-sm text-gray-500 italic">এই অর্ডারটি এখনো আয় হিসেবে গণনা হয়নি। স্ট্যাটাস "কনফার্ম" করলে অটোমেটিক যোগ হয়ে যাবে।</p>
            <?php endif; ?>
        </div>

        <div class="pt-4 border-t">
            <?php if ($viewRow['type'] === 'course' && !$viewRow['address']): ?>
                <p class="text-sm text-gray-500 italic">এই রেজিস্ট্রেশনে পার্সেল/ডেলিভারি তথ্য নেই (ফুল অনলাইন কোর্স), তাই কুরিয়ারে পাঠানোর দরকার নেই।</p>
            <?php elseif (!$courierConfigured): ?>
                <p class="text-sm text-gray-500">কুরিয়ারে পাঠাতে চাইলে আগে <a href="settings.php" class="text-indigo-600 font-semibold">সাইট সেটিংস</a> এ গিয়ে কুরিয়ার প্রোভাইডার ও API Key/Secret বসান।</p>
            <?php elseif (in_array($viewRow['status'], ['shipped', 'delivered'], true)): ?>
                <p class="text-sm text-green-700">এই অর্ডারটি ইতিমধ্যে কুরিয়ারে পাঠানো হয়েছে<?= $viewRow['courier_consignment_id'] ? ' (Consignment ID: ' . e($viewRow['courier_consignment_id']) . ')' : '' ?>।</p>
            <?php else: ?>
                <form method="post" action="send-to-courier.php" onsubmit="return confirmSubmit(this, 'এই অর্ডারটি কুরিয়ারে পাঠাতে চান?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                    <button type="submit" class="bg-purple-600 hover:bg-purple-700 text-white font-bold px-6 py-2.5 rounded-xl text-sm">📦 কুরিয়ারে পাঠান (<?= e(get_setting('courier_active_provider')) ?>)</button>
                </form>
            <?php endif; ?>

            <?php if ($shipments): ?>
            <div class="mt-4 space-y-2">
                <p class="text-sm font-semibold text-gray-700">কুরিয়ার লগ:</p>
                <?php foreach ($shipments as $sh): ?>
                <div class="text-xs bg-gray-50 rounded-lg p-3">
                    <span class="font-semibold"><?= e($sh['provider']) ?></span> —
                    <?= $sh['status'] === 'created' ? '<span class="text-green-700">সফল</span>' : '<span class="text-red-700">ব্যর্থ</span>' ?>
                    <?php if ($sh['tracking_url']): ?> — <a href="<?= e($sh['tracking_url']) ?>" target="_blank" class="text-indigo-600">ট্র্যাক করুন</a><?php endif; ?>
                    <span class="text-gray-400">(<?= e($sh['created_at']) ?>)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="registrations.php" class="inline-block text-gray-500 text-sm">← তালিকায় ফিরে যান</a>
            <form method="post" action="registrations.php?action=delete" onsubmit="return confirmSubmit(this, 'এই রেজিস্ট্রেশন/অর্ডারটি আর্কাইভে সরাতে চান? আয়ের এন্ট্রি ও কুরিয়ার ব্যাচ সহ পুরোটা আর্কাইভে যাবে — পরে আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।', 'আর্কাইভ নিশ্চিতকরণ');">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">
                <input type="hidden" name="return_url" value="registrations.php">
                <button type="submit" class="text-red-600 text-sm font-semibold">ডিলিট করুন</button>
            </form>
        </div>
    </div>

<?php elseif ($action === 'edit'):
    $hasParcel = $viewRow['type'] === 'course'
        ? ($viewRow['receiver_name'] !== null || $viewRow['receiver_phone'] !== null || $viewRow['address'] !== null)
        : true;
?>
    <div class="max-w-2xl bg-white rounded-2xl shadow p-6 space-y-5">
        <div class="flex justify-between items-start">
            <div>
                <h3 class="text-xl font-bold text-gray-900">তথ্য সম্পাদনা করুন</h3>
                <p class="text-gray-500 text-sm"><?= e($viewRow['item_title']) ?> • রেফারেন্স #<?= $viewRow['id'] ?></p>
            </div>
        </div>

        <form method="post" action="registrations.php?action=update-details" onsubmit="return confirmSubmit(this, 'আপনি কি এই তথ্য পরিবর্তন করে সংরক্ষণ করতে চান?', 'তথ্য পরিবর্তনের নিশ্চিতকরণ')" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $viewRow['id'] ?>">

            <?php if ($viewRow['type'] === 'course'): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">শিশুর নাম</label>
                        <input type="text" name="customer_name" value="<?= e($viewRow['customer_name']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল (মা)</label>
                        <input type="text" name="phone" value="<?= e($viewRow['phone']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">জন্ম তারিখ</label>
                        <input type="date" name="date_of_birth" value="<?= e($viewRow['date_of_birth'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ফেসবুক আইডি নাম</label>
                        <input type="text" name="facebook_id" value="<?= e($viewRow['facebook_id'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল (বাবা) — ঐচ্ছিক</label>
                        <input type="text" name="father_mobile" value="<?= e($viewRow['father_mobile'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                </div>
                <?php if ($hasParcel): ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2 border-t">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">রিসিভার নাম</label>
                        <input type="text" name="receiver_name" value="<?= e($viewRow['receiver_name'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">রিসিভার মোবাইল</label>
                        <input type="text" name="receiver_phone" value="<?= e($viewRow['receiver_phone'] ?? '') ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ঠিকানা</label>
                        <textarea name="address" rows="2" required class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['address'] ?? '') ?></textarea>
                    </div>
                </div>
                <?php else: ?>
                    <p class="text-sm text-gray-500 italic pt-2 border-t">এই কোর্সে পার্সেল/ডেলিভারি তথ্য প্রযোজ্য নয় (ফুল অনলাইন কোর্স)।</p>
                <?php endif; ?>
            <?php else: ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">নাম</label>
                        <input type="text" name="customer_name" value="<?= e($viewRow['customer_name']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ফোন</label>
                        <input type="text" name="phone" value="<?= e($viewRow['phone']) ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ইমেইল — ঐচ্ছিক</label>
                        <input type="email" name="email" value="<?= e($viewRow['email'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">পরিমাণ</label>
                        <input type="number" name="quantity" min="1" value="<?= (int) $viewRow['quantity'] ?>" required class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">জেলা — ঐচ্ছিক</label>
                        <select name="district" class="w-full border rounded-xl px-4 py-2.5">
                            <option value="">নির্বাচন করুন</option>
                            <?php foreach (bd_districts() as $d): ?>
                                <option value="<?= e($d) ?>" <?= $viewRow['district'] === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-1">থানা/উপজেলা — ঐচ্ছিক</label>
                        <input type="text" name="thana" value="<?= e($viewRow['thana'] ?? '') ?>" class="w-full border rounded-xl px-4 py-2.5">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-1">ঠিকানা</label>
                        <textarea name="address" rows="2" required class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['address'] ?? '') ?></textarea>
                    </div>
                </div>
            <?php endif; ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">মন্তব্য — ঐচ্ছিক</label>
                <textarea name="notes" rows="2" class="w-full border rounded-xl px-4 py-2.5"><?= e($viewRow['notes'] ?? '') ?></textarea>
            </div>

            <div class="flex items-center gap-3 pt-2 border-t">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">পরিবর্তন সংরক্ষণ করুন</button>
                <a href="registrations.php?action=view&id=<?= $viewRow['id'] ?>" class="text-gray-500 text-sm font-semibold">বাতিল</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<script>
    // showConfirmModal() ও confirmSubmit() admin/includes/layout-bottom.php তে ডিফাইন করা (পুরো অ্যাডমিন প্যানেলে শেয়ার্ড)
    const INCOME_STATUSES = <?= json_encode(INCOME_STATUSES) ?>;
    const STATUS_LABELS = <?= json_encode(array_map(fn($l) => $l[0], $statusLabels)) ?>;

    function statusChangeConfirmMessage(original) {
        return 'এই অর্ডারটি ইতিমধ্যে "' + STATUS_LABELS[original] + '" অবস্থায় আছে এবং আয়ের হিসাবে যুক্ত থাকতে পারে। স্ট্যাটাস পরিবর্তন করলে আয়ের হিসাবও বদলে যেতে পারে। আপনি কি নিশ্চিত?';
    }

    // সার্চ বক্সে টাইপ করা মাত্র (থামার পর) অটো-ফিল্টার — বাটনে ক্লিক করা লাগে না
    (function () {
        var searchInput = document.getElementById('regSearchInput');
        if (!searchInput) { return; }
        var debounceTimer;
        searchInput.addEventListener('input', function () {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(function () {
                document.getElementById('regFilterForm').submit();
            }, 500);
        });
    })();

    // লিস্ট পেজের ইনলাইন ড্রপডাউন — onchange এ কল হয়
    function confirmStatusChange(select) {
        const original = select.dataset.original;
        const newValue = select.value;

        if (INCOME_STATUSES.includes(original) && newValue !== original) {
            select.value = original; // যতক্ষণ না Confirm করছে ততক্ষণ আগের অবস্থায় দেখাবে
            showConfirmModal(statusChangeConfirmMessage(original), function () {
                select.value = newValue;
                select.form.submit();
            }, 'স্ট্যাটাস পরিবর্তনের নিশ্চিতকরণ');
            return;
        }
        select.form.submit();
    }

    // ডিটেইল পেজের স্ট্যাটাস ফর্ম — onsubmit এ কল হয়
    function confirmStatusFormSubmit(form) {
        const original = form.dataset.original;
        const select = form.querySelector('select[name="status"]');

        if (INCOME_STATUSES.includes(original) && select.value !== original) {
            showConfirmModal(statusChangeConfirmMessage(original), function () {
                form.submit();
            }, 'স্ট্যাটাস পরিবর্তনের নিশ্চিতকরণ');
            return false; // মডাল থেকে Confirm না দেওয়া পর্যন্ত সরাসরি সাবমিট আটকানো
        }
        return true;
    }
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
