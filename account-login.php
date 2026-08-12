<?php
// অভিভাবক অ্যাকাউন্ট — লগইন ফর্ম
require_once __DIR__ . '/includes/user-auth.php';

if (user_logged_in()) {
    redirect('account');
}

$pageTitle = 'লগইন';
$activePage = '';
$old = $_SESSION['account_login_old'] ?? [];
unset($_SESSION['account_login_old']);

require __DIR__ . '/includes/site-header.php';
?>
<div class="max-w-md mx-auto px-1 sm:px-0">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl p-5 sm:p-8 relative overflow-hidden" style="background: linear-gradient(150deg, rgb(var(--c-deep)) 0%, rgb(var(--c-primary)) 55%, rgb(var(--c-primary-2)) 100%);">
        <div class="absolute inset-0 bg-black/10 pointer-events-none"></div>
        <div class="relative z-10">
            <div class="text-center mb-6">
                <div class="inline-flex w-14 h-14 items-center justify-center rounded-full bg-white/20 mb-3"><i data-lucide="log-in" class="w-7 h-7 text-white"></i></div>
                <h1 class="text-xl sm:text-2xl font-black text-white mb-1">অভিভাবক লগইন</h1>
                <p class="text-fuchsia-100 text-sm">আপনার কোর্স, খরচ ও গ্রুপ লিংক দেখুন</p>
            </div>

            <?php $flash = get_flash(); if ($flash): ?>
                <div class="mb-5 p-4 rounded-xl text-sm <?= $flash['type'] === 'error' ? 'bg-red-500/40 text-white' : 'bg-green-500/40 text-white' ?>"><?= e($flash['message']) ?></div>
            <?php endif; ?>

            <form method="post" action="account-login-submit.php" class="space-y-4">
                <?= csrf_field() ?>
                <div>
                    <label class="block text-white font-semibold mb-1.5 text-sm">মোবাইল নাম্বার *</label>
                    <input type="text" inputmode="numeric" name="phone" required placeholder="01XXXXXXXXX" value="<?= e($old['phone'] ?? '') ?>"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none">
                </div>
                <div>
                    <label class="block text-white font-semibold mb-1.5 text-sm">পাসওয়ার্ড *</label>
                    <input type="password" name="password" required placeholder="আপনার পাসওয়ার্ড"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none">
                </div>
                <button type="submit" class="w-full py-3.5 rounded-xl font-bold text-base text-white shadow-lg active:scale-[0.98] transition-transform" style="background: linear-gradient(135deg, rgb(var(--c-primary-2)) 0%, rgb(var(--c-primary)) 100%);">
                    লগইন করুন
                </button>
                <p class="text-fuchsia-100 text-xs text-center">পাসওয়ার্ড ভুলে গেছেন? অ্যাডমিনের সাথে যোগাযোগ করুন।</p>
            </form>

            <div class="text-center mt-5 pt-4 border-t border-white/20">
                <a href="account-signup" class="text-white text-sm font-semibold">অ্যাকাউন্ট নেই? তৈরি করুন →</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
