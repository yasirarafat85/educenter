<?php
// 🛒 চেকআউট — কার্টের সব আইটেমের জন্য একবারে রিসিভারের তথ্য নেওয়া হয় (২০২৬-১১-০৭)।
require_once __DIR__ . '/includes/functions.php';

$lines = cart_contents();
if (!$lines) {
    redirect('cart.php');   // খালি কার্টে চেকআউটের কিছু নেই
}
$total = cart_total($lines);

$old = $_SESSION['checkout_form_old'] ?? [];
unset($_SESSION['checkout_form_old']);

$pageTitle       = 'চেকআউট';
$pageDescription = 'অর্ডার সম্পন্ন করুন — রিসিভারের তথ্য দিন।';
$pageNoIndex     = true;
$activePage      = '';
require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-2xl mx-auto">
    <h1 class="text-2xl font-black text-gray-900 mb-5 flex items-center gap-2">
        <i data-lucide="shopping-bag" class="w-6 h-6" style="color:rgb(var(--c-primary));"></i> চেকআউট
    </h1>

    <?php $flash = get_flash(); if ($flash): ?>
        <div class="mb-5 p-4 rounded-xl <?= $flash['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php // 🧾 অর্ডার সারাংশ ?>
    <div class="colorful-card rounded-2xl shadow p-4 sm:p-5 mb-5">
        <div class="flex items-center justify-between mb-3">
            <h2 class="font-bold text-gray-900">আপনার অর্ডার</h2>
            <a href="cart" class="text-sm font-semibold text-gray-600 hover:underline">✎ কার্ট বদলান</a>
        </div>
        <div class="divide-y divide-gray-100">
            <?php foreach ($lines as $l): ?>
                <div class="flex items-center justify-between gap-3 py-2">
                    <span class="min-w-0 text-gray-700 text-sm break-words"><?= e($l['title']) ?> <span class="text-gray-400">× <?= (int) $l['qty'] ?></span></span>
                    <span class="flex-shrink-0 font-bold text-gray-800">৳<?= number_format($l['line']) ?></span>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="flex items-center justify-between pt-3 mt-1 border-t border-gray-200">
            <span class="font-bold text-gray-900">সর্বমোট</span>
            <span class="text-xl font-black" style="color:rgb(var(--c-primary));">৳<?= number_format($total) ?></span>
        </div>
        <p class="text-xs text-gray-500 mt-2">* ডেলিভারি চার্জ আলাদা — অর্ডারের পর জানানো হবে।</p>
    </div>

    <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8">
        <form method="post" action="checkout-submit.php" class="space-y-4">
            <?= csrf_field() ?>
            <?= spam_protection_fields() ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">মোবাইল নম্বর *</label>
                <input type="tel" inputmode="numeric" autocomplete="tel" id="phone" name="phone" required placeholder="01XXXXXXXXX" class="form-input" value="<?= e($old['phone'] ?? '') ?>">
                <p class="text-xs text-gray-400 mt-1">আগে অর্ডার করে থাকলে নম্বর দিলে বাকি তথ্য স্বয়ংক্রিয়ভাবে চলে আসবে</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">আপনার নাম *</label>
                <input type="text" autocomplete="name" id="customer_name" name="customer_name" required class="form-input" value="<?= e($old['customer_name'] ?? '') ?>">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ইমেইল (ঐচ্ছিক)</label>
                <input type="email" autocomplete="email" id="email" name="email" class="form-input" value="<?= e($old['email'] ?? '') ?>">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">সম্পূর্ণ ঠিকানা (থানা ও জেলাসহ) *</label>
                <textarea autocomplete="street-address" id="address" name="address" required rows="2" class="form-input"><?= e($old['address'] ?? '') ?></textarea>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">অতিরিক্ত মন্তব্য (ঐচ্ছিক)</label>
                <textarea name="notes" rows="2" class="form-input"><?= e($old['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="w-full text-white py-4 px-6 rounded-xl font-bold text-lg shadow-lg btn-primary">
                অর্ডার কনফার্ম করুন
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    var phoneInput = document.getElementById('phone');
    if (!phoneInput) return;
    function setIfEmpty(id, val) { var el = document.getElementById(id); if (el && !el.value && val) el.value = val; }
    function lookupPost(url, fields) {
        var tok = document.querySelector('input[name="csrf_token"]');
        if (!tok || !tok.value) { return Promise.reject(new Error('no-token')); }
        var body = new URLSearchParams(fields);
        body.set('csrf_token', tok.value);
        return fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
    }
    phoneInput.addEventListener('blur', function () {
        var phone = this.value.trim();
        if (!/^01[3-9][0-9]{8}$/.test(phone)) return;
        lookupPost('ajax-lookup-registration.php', { mode: 'general', phone: phone })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.found) return;
                setIfEmpty('customer_name', data.customer_name);
                setIfEmpty('email', data.email);
                setIfEmpty('address', data.address);
            })
            .catch(function () { /* নীরবে উপেক্ষা */ });
    });
})();
</script>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
