<?php
// 🛒 কার্ট পাতা — ওয়ার্কশিট ও প্রোডাক্টের আইটেম তালিকা, পরিমাণ বদল, বাদ, চেকআউট।
// কোর্স এখানে আসে না (কোর্স আলাদা ফর্মে — course-register)।
require_once __DIR__ . '/includes/functions.php';

// ── POST: পরিমাণ হালনাগাদ / বাদ / খালি (PRG — তারপর redirect)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'নিরাপত্তা যাচাই মেয়াদোত্তীর্ণ হয়েছিল — আবার চেষ্টা করুন।');
        redirect('cart.php');
    }
    $action = $_POST['action'] ?? '';
    $type   = $_POST['type'] ?? '';
    $id     = (int) ($_POST['id'] ?? 0);

    if ($action === 'clear') {
        cart_clear();
        set_flash('success', 'কার্ট খালি করা হয়েছে।');
    } elseif ($action === 'remove') {
        cart_set($type, $id, 0);
        set_flash('success', 'আইটেমটি কার্ট থেকে বাদ দেওয়া হয়েছে।');
    } elseif ($action === 'update-one') {
        $qty = (int) ($_POST['qty'] ?? 1);
        cart_set($type, $id, $qty);
        set_flash('success', $qty <= 0 ? 'আইটেমটি বাদ দেওয়া হয়েছে।' : 'পরিমাণ হালনাগাদ হয়েছে।');
    }
    redirect('cart.php');
}

$lines = cart_contents();
$total = cart_total($lines);

$pageTitle     = 'আমার কার্ট';
$pageDescription = 'আপনার অর্ডার কার্ট — ওয়ার্কশিট ও প্রোডাক্ট একসাথে অর্ডার করুন।';
$pageNoIndex   = true;   // কার্ট ইনডেক্স করার কিছু নেই
$activePage    = '';
require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl sm:text-3xl font-black text-gray-900 mb-5 flex items-center gap-2">
        <i data-lucide="shopping-cart" class="w-7 h-7" style="color:rgb(var(--c-primary));"></i> আমার কার্ট
    </h1>

    <?php $flash = get_flash(); if ($flash): ?>
        <div class="mb-5 p-4 rounded-xl <?= $flash['type'] === 'error' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800' ?>">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if (!$lines): ?>
        <div class="colorful-card rounded-2xl shadow-lg p-8 text-center">
            <div class="text-5xl mb-3">🛒</div>
            <p class="text-gray-600 text-lg mb-5">আপনার কার্ট এখন খালি।</p>
            <div class="flex flex-wrap justify-center gap-3">
                <a href="products" class="btn-primary text-white font-bold py-3 px-6 rounded-xl">প্রোডাক্ট দেখুন</a>
                <a href="worksheets" class="inline-block font-semibold py-3 px-6 rounded-xl border-2 text-gray-700 hover:bg-gray-50" style="border-color:rgb(var(--c-border));">ওয়ার্কশিট দেখুন</a>
            </div>
        </div>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($lines as $l): ?>
                <div class="colorful-card rounded-2xl shadow p-4 flex items-center gap-3 sm:gap-4">
                    <a href="<?= e('detail?type=' . urlencode($l['type']) . '&id=' . $l['id']) ?>" class="flex-shrink-0">
                        <img src="<?= e($l['image'] ?: placeholder_img()) ?>" alt="<?= e($l['title']) ?>" class="w-16 h-16 sm:w-20 sm:h-20 rounded-xl object-cover bg-white" loading="lazy">
                    </a>
                    <div class="min-w-0 flex-1">
                        <a href="<?= e('detail?type=' . urlencode($l['type']) . '&id=' . $l['id']) ?>" class="font-bold text-gray-900 leading-snug break-words hover:underline"><?= e($l['title']) ?></a>
                        <p class="text-sm text-gray-500 mt-0.5">
                            <?= e($l['price_text'] !== '' ? $l['price_text'] : ('৳' . number_format($l['unit']))) ?> × <?= (int) $l['qty'] ?>
                            <span class="font-bold text-gray-800">= ৳<?= number_format($l['line']) ?></span>
                        </p>
                        <form method="post" action="cart.php" class="mt-2 flex items-center gap-2 flex-wrap">
                            <?= csrf_field() ?>
                            <input type="hidden" name="type" value="<?= e($l['type']) ?>">
                            <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                            <label class="text-xs text-gray-500">পরিমাণ</label>
                            <input type="number" name="qty" value="<?= (int) $l['qty'] ?>" min="1" max="99" inputmode="numeric"
                                   class="w-16 border border-gray-300 rounded-lg px-2 py-1.5 text-center" style="font-size:16px;">
                            <button type="submit" name="action" value="update-one" class="text-sm font-semibold px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700">হালনাগাদ</button>
                            <button type="submit" name="action" value="remove" class="text-sm font-semibold px-3 py-1.5 rounded-lg text-red-600 hover:bg-red-50">✕ বাদ</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="colorful-card rounded-2xl shadow-lg p-5 sm:p-6 mt-5">
            <div class="flex items-center justify-between mb-4">
                <span class="text-lg font-semibold text-gray-700">সর্বমোট</span>
                <span class="text-2xl font-black" style="color:rgb(var(--c-primary));">৳<?= number_format($total) ?></span>
            </div>
            <p class="text-xs text-gray-500 mb-4">* ডেলিভারি চার্জ আলাদা — অর্ডারের পর আমরা জানিয়ে দেব।</p>
            <a href="checkout" class="btn-primary text-white font-bold text-lg py-3.5 px-6 rounded-xl block text-center shadow-lg">চেকআউট করুন →</a>
            <div class="flex flex-wrap items-center justify-between gap-3 mt-4">
                <a href="products" class="text-sm font-semibold text-gray-600 hover:underline">← আরও কেনাকাটা করুন</a>
                <form method="post" action="cart.php" onsubmit="return confirm('পুরো কার্ট খালি করবেন?');">
                    <?= csrf_field() ?>
                    <button type="submit" name="action" value="clear" class="text-sm font-semibold text-red-600 hover:underline">কার্ট খালি করুন</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
