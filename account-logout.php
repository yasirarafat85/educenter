<?php
// অভিভাবক লগআউট
require_once __DIR__ . '/includes/user-auth.php';
// 👁 প্রিভিউ চলাকালে "লগআউট" মানে শুধু প্রিভিউ বন্ধ (অ্যাডমিনের নিজের সেশন অক্ষত)
if (user_preview_active()) {
    user_preview_stop();
    set_flash('success', 'প্রিভিউ বন্ধ করা হয়েছে।');
    redirect('admin/users.php');
}

user_logout();
set_flash('success', 'আপনি লগআউট হয়েছেন।');
redirect('account-login');
