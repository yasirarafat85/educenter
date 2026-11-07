<?php
// 🛒 কার্টে আইটেম যোগ — POST only (কার্ড/ডিটেইল পেজের "কার্টে যোগ করুন" বোতাম থেকে)।
// GET-এ কার্টে কিছু বসে না (prefetch/crawler এড়াতে)।
require_once __DIR__ . '/includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('cart.php');
}
if (!csrf_verify()) {
    set_flash('error', 'নিরাপত্তা যাচাই মেয়াদোত্তীর্ণ হয়েছিল — আবার চেষ্টা করুন।');
    redirect('cart.php');
}

$type = $_POST['type'] ?? '';
$id   = (int) ($_POST['id'] ?? 0);
$qty  = max(1, (int) ($_POST['qty'] ?? 1));

if (cart_add($type, $id, $qty)) {
    set_flash('success', '🛒 কার্টে যোগ হয়েছে।');
} else {
    set_flash('error', 'দুঃখিত, আইটেমটি কার্টে যোগ করা গেল না।');
}
redirect('cart.php');
