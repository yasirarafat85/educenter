<?php
// অভিভাবক লগআউট
// 🔴 অডিট S5 (২০২৬-১১-১৩): শুধু POST + CSRF টোকেনে কাজ করে (আগে সাধারণ লিংক ছিল —
//    অন্য সাইটের একটা লুকানো ছবি-ট্যাগ দিয়েই যে কাউকে লগআউট করানো যেত)।
//    GET-এ এলে কিছুই হয় না, ড্যাশবোর্ডে ফেরত।
require_once __DIR__ . '/includes/user-auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify()) {
    redirect('account');
}
// 👁 প্রিভিউ চলাকালে "লগআউট" মানে শুধু প্রিভিউ বন্ধ (অ্যাডমিনের নিজের সেশন অক্ষত)
if (user_preview_active()) {
    user_preview_stop();
    set_flash('success', 'প্রিভিউ বন্ধ করা হয়েছে।');
    redirect('admin/users.php');
}

user_logout();
set_flash('success', 'আপনি লগআউট হয়েছেন।');
redirect('account-login');
