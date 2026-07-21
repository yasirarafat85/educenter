<?php
// ============================================================
//  config.php-এর নমুনা। ব্যবহার:
//  এই ফাইলটা কপি করে `config.php` নামে রাখুন, তারপর নিচের ৪টা
//  ডাটাবেস মান আপনার cPanel-এর তথ্য দিয়ে বসান।
//
//  ⚠️ আসল config.php কখনো git/GitHub-এ যায় না (.gitignore-এ বাদ) —
//     কারণ ওতে ডাটাবেস পাসওয়ার্ড থাকে। এই নমুনাটাই শুধু git-এ থাকে।
// ============================================================

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');       // cPanel-এ বানানো ডাটাবেসের নাম
define('DB_USER', 'YOUR_DB_USER');       // ডাটাবেস ইউজার
define('DB_PASS', 'YOUR_DB_PASSWORD');   // ঐ ইউজারের পাসওয়ার্ড

// সাইটের বেস URL (শেষে স্ল্যাশ ছাড়া)
//   লোকাল টেস্টে: http://localhost/website
//   লাইভে যেমন:   https://yourdomain.com
define('SITE_URL', 'https://yourdomain.com');

// ডেভেলপমেন্ট মোড: লোকালে true (এরর দেখাবে), লাইভে অবশ্যই false
define('DEV_MODE', false);

if (DEV_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(0);
    ini_set('display_errors', '0');

    // লাইভে (DEV_MODE=false) http:// দিয়ে ঢুকলে জোর করে https:// তে পাঠানো হয়
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    if (!$isHttps && php_sapi_name() !== 'cli') {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
}
