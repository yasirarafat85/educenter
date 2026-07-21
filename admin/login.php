<?php
require_once __DIR__ . '/includes/auth.php';

if (admin_logged_in()) {
    redirect('index.php');
}

$error = '';
// নিষ্ক্রিয়তা টাইমআউটে লগআউট হলে (auth.php → login.php?expired=1) নীল নোটিশ দেখানো হয়
$notice = isset($_GET['expired']) ? 'অনেকক্ষণ নিষ্ক্রিয় থাকায় নিরাপত্তার জন্য লগআউট হয়েছে। আবার লগইন করুন।' : '';
$ip = admin_client_ip();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।';
    } elseif (admin_is_rate_limited($ip)) {
        $error = 'অনেকবার ভুল লগইন চেষ্টা হয়েছে। নিরাপত্তার জন্য ' . ADMIN_LOGIN_WINDOW_MINUTES . ' মিনিট পর আবার চেষ্টা করুন।';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'ইউজারনেম ও পাসওয়ার্ড দিন।';
        } else {
            $ok = admin_attempt_login($username, $password);
            admin_record_login_attempt($ip, $username, $ok);

            if ($ok) {
                redirect('index.php');
            }
            $error = 'ভুল ইউজারনেম অথবা পাসওয়ার্ড।';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="bn">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Login - EduCenter</title>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&family=Anek+Bangla:wght@600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/css/tailwind.css') ?: '1' ?>">
<style>
    body { font-family: 'Inter', 'Hind Siliguri', system-ui, sans-serif; line-height: 1.75; background: linear-gradient(135deg, #4F46E5, #7C6BF5, #6366F1); }
    h1 { font-family: 'Plus Jakarta Sans', 'Anek Bangla', 'Hind Siliguri', sans-serif; }
</style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
<div class="bg-white rounded-2xl shadow-2xl p-8 w-full max-w-sm">
    <div class="text-center mb-6">
        <span class="inline-flex w-14 h-14 rounded-2xl items-center justify-center text-white mb-3" style="background: linear-gradient(135deg, #4F46E5, #7C6BF5);">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-7 h-7"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        </span>
        <h1 class="text-2xl font-black text-gray-800">EduCenter</h1>
        <p class="text-gray-500 text-sm mt-1">Admin Panel এ লগইন করুন</p>
    </div>
    <?php if ($notice): ?>
        <div class="bg-blue-100 text-blue-800 text-sm p-3 rounded-xl mb-4"><?= e($notice) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="bg-red-100 text-red-800 text-sm p-3 rounded-xl mb-4"><?= e($error) ?></div>
    <?php endif; ?>
    <form method="post" class="space-y-4">
        <?= csrf_field() ?>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">ইউজারনেম</label>
            <input type="text" name="username" required class="w-full border rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-indigo-500 outline-none" value="<?= e($_POST['username'] ?? '') ?>">
        </div>
        <div>
            <label class="block text-sm font-semibold text-gray-700 mb-1">পাসওয়ার্ড</label>
            <input type="password" name="password" required class="w-full border rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-bold py-2.5 rounded-xl transition-colors">লগইন</button>
    </form>
</div>
</body>
</html>
