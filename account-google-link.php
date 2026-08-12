<?php
// নতুন Google ইউজার — রেজিস্ট্রেশনের ফোন নাম্বার দিয়ে অ্যাকাউন্ট চূড়ান্ত করা (তারপর admin approve)
require_once __DIR__ . '/includes/user-auth.php';

if (user_logged_in()) {
    redirect('account');
}
$pending = $_SESSION['google_pending'] ?? null;
if (!$pending || empty($pending['google_id'])) {
    set_flash('error', 'Google তথ্য পাওয়া যায়নি। আবার লগইন করুন।');
    redirect('account-login');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('account-google-link');
    }
    $db = get_db();
    $phone = trim($_POST['phone'] ?? '');

    if (!is_valid_bd_phone($phone)) {
        set_flash('error', 'সঠিক মোবাইল নাম্বার দিন।');
        redirect('account-google-link');
    }
    // আসল কাস্টমার যাচাই
    $stmt = $db->prepare('SELECT COUNT(*) c FROM registrations WHERE phone = :p');
    $stmt->execute(['p' => $phone]);
    if ((int) $stmt->fetch()['c'] === 0) {
        set_flash('error', 'এই নাম্বারে কোনো রেজিস্ট্রেশন পাওয়া যায়নি। কোর্সে রেজিস্ট্রেশনের সময় দেওয়া নাম্বার দিন।');
        redirect('account-google-link');
    }
    // এই ফোনে আগে থেকে অ্যাকাউন্ট থাকলে Google-signup না — পাসওয়ার্ড লগইনে পাঠাই (নিরাপত্তা)
    $stmt = $db->prepare('SELECT COUNT(*) c FROM users WHERE phone = :p');
    $stmt->execute(['p' => $phone]);
    if ((int) $stmt->fetch()['c'] > 0) {
        set_flash('error', 'এই নাম্বারে ইতিমধ্যে একটি অ্যাকাউন্ট আছে। পাসওয়ার্ড দিয়ে লগইন করুন অথবা অ্যাডমিনের সাথে যোগাযোগ করুন।');
        unset($_SESSION['google_pending']);
        redirect('account-login');
    }

    $db->prepare('INSERT INTO users (phone, google_id, email, full_name, status) VALUES (:p, :g, :e, :n, "pending")')
       ->execute(['p' => $phone, 'g' => $pending['google_id'], 'e' => $pending['email'] ?: null, 'n' => $pending['name'] ?: null]);

    unset($_SESSION['google_pending']);
    set_flash('success', 'অ্যাকাউন্ট তৈরি হয়েছে! অ্যাডমিন approve করলে Google দিয়ে লগইন করতে পারবেন।');
    redirect('account-login');
}

$pageTitle = 'অ্যাকাউন্ট সম্পন্ন করুন';
$activePage = '';
require __DIR__ . '/includes/site-header.php';
?>
<div class="max-w-md mx-auto px-1 sm:px-0">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl p-5 sm:p-8 relative overflow-hidden" style="background: linear-gradient(150deg, rgb(var(--c-deep)) 0%, rgb(var(--c-primary)) 55%, rgb(var(--c-primary-2)) 100%);">
        <div class="absolute inset-0 bg-black/10 pointer-events-none"></div>
        <div class="relative z-10">
            <div class="text-center mb-6">
                <div class="inline-flex w-14 h-14 items-center justify-center rounded-full bg-white/20 mb-3"><i data-lucide="smartphone" class="w-7 h-7 text-white"></i></div>
                <h1 class="text-xl font-black text-white mb-1">আর একটা ধাপ<?= $pending['name'] ? ', ' . e($pending['name']) : '' ?>!</h1>
                <p class="text-fuchsia-100 text-sm">Google লগইন সফল। এখন কোর্সে রেজিস্ট্রেশনের সময় যে মোবাইল নাম্বার দিয়েছিলেন সেটি দিন — এটি দিয়েই আপনার কেনা কোর্স মিলিয়ে দেখানো হবে।</p>
            </div>
            <?php $flash = get_flash(); if ($flash): ?>
                <div class="mb-5 p-4 rounded-xl text-sm <?= $flash['type'] === 'error' ? 'bg-red-500/40 text-white' : 'bg-green-500/40 text-white' ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>
            <form method="post" action="account-google-link.php" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-white font-semibold mb-1.5 text-sm">মোবাইল নাম্বার (রেজিস্ট্রেশনের) *</label>
                    <input type="text" inputmode="numeric" name="phone" required placeholder="01XXXXXXXXX"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none">
                </div>
                <button type="submit" class="w-full py-3.5 rounded-xl font-bold text-base text-white shadow-lg active:scale-[0.98] transition-transform" style="background: linear-gradient(135deg, rgb(var(--c-primary-2)) 0%, rgb(var(--c-primary)) 100%);">
                    সম্পন্ন করুন
                </button>
                <p class="text-fuchsia-100 text-xs text-center">এরপর অ্যাডমিন approve করলে লগইন করতে পারবেন।</p>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
