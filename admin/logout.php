<?php
// অ্যাডমিন লগআউট।
// 🔴 অডিট S5 (২০২৬-১১-১৩): শুধু POST + CSRF টোকেনে কাজ করে। আগে সাধারণ লিংক (GET) ছিল —
//    তাই অন্য কোনো সাইট/মেসেজের একটা লুকানো <img src=".../admin/logout.php"> দিয়েই
//    অ্যাডমিনকে কাজের মাঝখানে লগআউট করিয়ে দেওয়া যেত। GET-এ এখন কিছুই হয় না।
require_once __DIR__ . '/includes/auth.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify()) {
    redirect('index.php'); // লগইন না থাকলে index.php নিজেই login.php-এ পাঠায়
}
admin_logout();
redirect('login.php');
