<?php
// ⭐ সাইডবারের "প্রিয়" লিংক সাজানো — পিন/আনপিন/উপরে/নিচে/ডিফল্টে ফেরানো।
// কোনো নিজস্ব পেজ নেই: কাজ সেরে যেখান থেকে এসেছিল সেখানেই ফিরিয়ে দেয় (এই কোডবেসে AJAX নেই,
// তাই গ্রুপের টিকের মতোই ফুল POST → redirect প্যাটার্ন)।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/includes/nav.php';

$back = nav_safe_return((string) ($_POST['return_url'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = (string) ($_POST['pin_action'] ?? '');
    $key    = (string) ($_POST['key'] ?? '');
    // 🔴 key অবশ্যই এই অ্যাডমিনের **অনুমোদিত** লিংকগুলোর একটা হতে হবে — নাহলে পিন-তালিকায়
    // আবর্জনা/লুকানো পেজের key ঢুকে যেত। (রেন্ডারেও ফিল্টার আছে, এটা দ্বিতীয় স্তর।)
    $allowed = [];
    foreach (admin_nav_groups() as $g) {
        foreach ($g['items'] as $it) { $allowed[] = $it['key']; }
    }
    if ($action === 'reset') {
        nav_pins_apply('reset', '');
    } elseif (in_array($action, ['pin', 'unpin', 'up', 'down'], true) && in_array($key, $allowed, true)) {
        nav_pins_apply($action, $key);
    }
}

redirect($back);
