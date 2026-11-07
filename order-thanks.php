<?php
// 🛒 অর্ডার সফল পাতা — মাল্টি-আইটেম অর্ডারের ধন্যবাদ + পেমেন্ট নাম্বার (২০২৬-১১-০৭)।
// 🔴 এক রেফারেন্সে পুরো অর্ডার দেখানো হয়; টাকা অ্যাডমিন আইটেম-প্রতি খাতায় বসান (ইউজারের সিদ্ধান্ত)।
require_once __DIR__ . '/includes/functions.php';

$success = $_SESSION['order_success'] ?? null;
if (!$success || empty($success['items'])) {
    redirect('index.php');   // সরাসরি এলে (সেশন নেই) হোমে
}
unset($_SESSION['order_success']);   // এক-বার দেখানো

$pageTitle   = 'অর্ডার সম্পন্ন';
$pageNoIndex = true;
$activePage  = '';
require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-xl mx-auto">
    <div class="colorful-card rounded-2xl shadow-lg p-6 sm:p-8 text-center">
        <div class="text-5xl mb-3">🎉</div>
        <h1 class="text-2xl font-black text-gray-900 mb-2">অর্ডার সফল হয়েছে!</h1>
        <p class="text-gray-600 mb-1">আপনার অর্ডার আমরা পেয়েছি, <?= e($success['name']) ?>।</p>
        <p class="text-sm text-gray-500 mb-5">রেফারেন্স নম্বর: <span class="font-bold text-gray-800">#<?= (int) $success['ref'] ?></span></p>

        <div class="text-left bg-white border border-gray-200 rounded-xl p-4 mb-2">
            <div class="divide-y divide-gray-100">
                <?php foreach ($success['items'] as $it): ?>
                    <div class="flex items-center justify-between gap-3 py-2">
                        <span class="min-w-0 text-gray-700 text-sm break-words"><?= e($it['title']) ?> <span class="text-gray-400">× <?= (int) $it['qty'] ?></span></span>
                        <span class="flex-shrink-0 font-bold text-gray-800">৳<?= number_format($it['line']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex items-center justify-between pt-3 mt-1 border-t border-gray-200">
                <span class="font-bold text-gray-900">সর্বমোট</span>
                <span class="text-lg font-black" style="color:rgb(var(--c-primary));">৳<?= number_format($success['total']) ?></span>
            </div>
        </div>
        <p class="text-xs text-gray-500">* ডেলিভারি চার্জ আলাদা — আমরা যোগাযোগ করে জানিয়ে দেব।</p>
    </div>

    <?php // 💳 পেমেন্ট নাম্বার — সাধারণ (scope_all) নাম্বারগুলো। টাকা দিলে অ্যাডমিনকে জানান। ?>
    <?= render_payment_box() ?>

    <div class="text-center mt-6">
        <a href="./" class="inline-block font-semibold py-3 px-6 rounded-xl border-2 text-gray-700 hover:bg-gray-50" style="border-color:rgb(var(--c-border));">🏠 হোমে ফিরে যান</a>
    </div>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
