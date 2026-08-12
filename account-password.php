<?php
// অভিভাবক — পাসওয়ার্ড পরিবর্তন
require_once __DIR__ . '/includes/user-auth.php';
user_require_login();
$user = user_current();
if (!$user) {
    redirect('account-login');
}

// ------------------ POST: পাসওয়ার্ড আপডেট ------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('account-password');
    }
    $current = (string) ($_POST['current_password'] ?? '');
    $new = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['new_password_confirm'] ?? '');

    if (empty($user['password_hash']) || !password_verify($current, $user['password_hash'])) {
        set_flash('error', 'বর্তমান পাসওয়ার্ড ভুল।');
        redirect('account-password');
    }
    if (mb_strlen($new) < 6) {
        set_flash('error', 'নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের দিন।');
        redirect('account-password');
    }
    if ($new !== $confirm) {
        set_flash('error', 'নতুন পাসওয়ার্ড দুটি মিলছে না।');
        redirect('account-password');
    }
    get_db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
        ->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => (int) $user['id']]);
    set_flash('success', 'পাসওয়ার্ড পরিবর্তন হয়েছে।');
    redirect('account');
}

$pageTitle = 'পাসওয়ার্ড পরিবর্তন';
$activePage = '';
require __DIR__ . '/includes/site-header.php';
?>
<div class="max-w-md mx-auto px-1 sm:px-0">
    <div class="mb-4"><a href="account" class="text-indigo-600 text-sm font-semibold">← আমার অ্যাকাউন্ট</a></div>
    <div class="bg-white rounded-2xl shadow p-6">
        <h1 class="text-xl font-black text-gray-900 mb-5">পাসওয়ার্ড পরিবর্তন</h1>
        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mb-4 p-3 rounded-xl text-sm <?= $flash['type'] === 'error' ? 'bg-red-50 text-red-700' : 'bg-green-50 text-green-700' ?>"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <form method="post" action="account-password.php" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">বর্তমান পাসওয়ার্ড *</label>
                <input type="password" name="current_password" required class="w-full border rounded-xl px-4 py-2.5">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">নতুন পাসওয়ার্ড *</label>
                <input type="password" name="new_password" required placeholder="কমপক্ষে ৬ অক্ষর" class="w-full border rounded-xl px-4 py-2.5">
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">নতুন পাসওয়ার্ড আবার *</label>
                <input type="password" name="new_password_confirm" required class="w-full border rounded-xl px-4 py-2.5">
            </div>
            <button type="submit" class="w-full btn-primary text-white font-bold py-3 rounded-xl">পরিবর্তন করুন</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
