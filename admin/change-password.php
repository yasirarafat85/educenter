<?php
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pageTitle = 'পাসওয়ার্ড পরিবর্তন';
$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $stmt = $db->prepare('SELECT * FROM admin_users WHERE id = :id');
    $stmt->execute(['id' => $_SESSION['admin_id']]);
    $admin = $stmt->fetch();

    if (!$admin || !password_verify($current, $admin['password_hash'])) {
        set_flash('error', 'বর্তমান পাসওয়ার্ড ভুল।');
    } elseif (strlen($new) < 6) {
        set_flash('error', 'নতুন পাসওয়ার্ড কমপক্ষে ৬ ক্যারেক্টারের হতে হবে।');
    } elseif ($new !== $confirm) {
        set_flash('error', 'নতুন পাসওয়ার্ড ও কনফার্ম পাসওয়ার্ড মিলছে না।');
    } else {
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $db->prepare('UPDATE admin_users SET password_hash = :h WHERE id = :id');
        $upd->execute(['h' => $hash, 'id' => $_SESSION['admin_id']]);
        set_flash('success', 'পাসওয়ার্ড সফলভাবে পরিবর্তন হয়েছে।');
    }
    redirect('change-password.php');
}

require __DIR__ . '/includes/layout-top.php';
?>
<div class="bg-white rounded-2xl shadow p-6 max-w-md">
    <form method="post" class="space-y-4">
        <?= csrf_field() ?>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">বর্তমান পাসওয়ার্ড</label>
            <input type="password" name="current_password" required class="w-full border rounded-xl px-4 py-2.5">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">নতুন পাসওয়ার্ড</label>
            <input type="password" name="new_password" required class="w-full border rounded-xl px-4 py-2.5">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">নতুন পাসওয়ার্ড আবার লিখুন</label>
            <input type="password" name="confirm_password" required class="w-full border rounded-xl px-4 py-2.5">
        </div>
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">পরিবর্তন করুন</button>
    </form>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
