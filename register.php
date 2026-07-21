<?php
require_once __DIR__ . '/includes/functions.php';

$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);

// কোর্স রেজিস্ট্রেশন এখন নতুন ২-ধাপের ফর্মে হয় (course-register.php)
if ($type === 'course') {
    redirect('course-register.php');
}

$orderItem = fetch_item($type, $id);

if (!$orderItem) {
    http_response_code(404);
    $pageTitle = 'পাওয়া যায়নি';
    $activePage = '';
    require __DIR__ . '/includes/site-header.php';
    echo '<div class="text-center text-2xl text-gray-600 py-20">দুঃখিত, এই আইটেমটি পাওয়া যায়নি।</div>';
    require __DIR__ . '/includes/site-footer.php';
    exit;
}

$pageTitle = 'অর্ডার - ' . $orderItem['title'];
$activePage = '';
$old = $_SESSION['register_form_old'] ?? [];
unset($_SESSION['register_form_old']);
$unitPrice = parse_price_to_number($orderItem['price'] ?? '');
[$accentGrad, $accentSolid] = item_accent($type); // প্রাইসিং কার্ডের সাথে রঙ মিলানোর জন্য (worksheet=বেগুনি, product=টিল)

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-2xl mx-auto">
    <div class="colorful-card rounded-2xl shadow-lg p-5 sm:p-6 mb-6 flex items-center gap-4">
        <img src="<?= e($orderItem['image'] ?: 'https://placehold.co/100x100') ?>" class="w-16 h-16 rounded-xl object-cover">
        <div>
            <p class="text-xs text-gray-500 uppercase font-semibold">অর্ডার</p>
            <h2 class="font-bold text-gray-900 text-lg"><?= e($orderItem['title']) ?></h2>
            <p class="font-bold" id="order-total" style="color:<?= $accentSolid ?>;"><?= e($orderItem['price'] ?? '') ?></p>
        </div>
    </div>

    <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8">
        <h1 class="text-2xl font-bold text-gray-900 mb-6 flex items-center gap-2">
            <i data-lucide="shopping-cart" class="w-6 h-6" style="color:<?= $accentSolid ?>;"></i> অর্ডার ফর্ম
        </h1>

        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mb-5 p-4 rounded-xl <?= $flash['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="register-submit.php" class="space-y-4">
            <?= csrf_field() ?>
            <?= spam_protection_fields() ?>
            <input type="hidden" name="type" value="<?= e($type) ?>">
            <input type="hidden" name="item_id" value="<?= (int) $id ?>">

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল নম্বর *</label>
                <input type="text" id="phone" name="phone" required placeholder="01XXXXXXXXX" class="form-input" value="<?= e($old['phone'] ?? '') ?>">
                <p class="text-xs text-gray-400 mt-1">আগে অর্ডার করে থাকলে নম্বর দিলে বাকি তথ্য স্বয়ংক্রিয়ভাবে চলে আসবে</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">আপনার নাম *</label>
                <input type="text" id="customer_name" name="customer_name" required class="form-input" value="<?= e($old['customer_name'] ?? '') ?>">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ইমেইল (ঐচ্ছিক)</label>
                <input type="email" id="email" name="email" class="form-input" value="<?= e($old['email'] ?? '') ?>">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">সম্পূর্ণ ঠিকানা (থানা ও জেলাসহ) *</label>
                <textarea id="address" name="address" required rows="2" class="form-input"><?= e($old['address'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">পরিমাণ</label>
                <input type="number" id="quantity" name="quantity" min="1" value="<?= e($old['quantity'] ?? '1') ?>" class="form-input">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">অতিরিক্ত মন্তব্য (ঐচ্ছিক)</label>
                <textarea name="notes" rows="2" class="form-input"><?= e($old['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="w-full text-white py-4 px-6 rounded-xl font-bold text-lg shadow-lg pricing-cta" style="background:<?= $accentGrad ?>;">
                অর্ডার কনফার্ম করুন
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    var phoneInput = document.getElementById('phone');
    if (!phoneInput) return;

    function setIfEmpty(id, val) {
        var el = document.getElementById(id);
        if (el && !el.value && val) el.value = val;
    }

    phoneInput.addEventListener('blur', function () {
        var phone = this.value.trim();
        if (!/^01[3-9][0-9]{8}$/.test(phone)) return;

        fetch('ajax-lookup-registration.php?mode=general&phone=' + encodeURIComponent(phone))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.found) return;
                setIfEmpty('customer_name', data.customer_name);
                setIfEmpty('email', data.email);
                setIfEmpty('address', data.address);
            })
            .catch(function () { /* নীরবে উপেক্ষা করা হলো, ফর্ম পূরণে সমস্যা হবে না */ });
    });
})();

(function () {
    var qty = document.getElementById('quantity');
    var totalEl = document.getElementById('order-total');
    var unitPrice = <?= json_encode($unitPrice) ?>;
    if (!qty || !totalEl || !unitPrice) return;

    var bnDigits = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    function toBengaliDigits(str) {
        return str.replace(/[0-9]/g, function (d) { return bnDigits[d]; });
    }
    function updateTotal() {
        var q = Math.max(1, parseInt(qty.value, 10) || 1);
        var total = Math.round(unitPrice * q);
        totalEl.textContent = '৳' + toBengaliDigits(total.toLocaleString('en-US'));
    }
    qty.addEventListener('input', updateTotal);
})();
</script>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
