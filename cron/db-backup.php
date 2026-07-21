<?php
// অটো ডাটাবেস ব্যাকআপ — cPanel Cron Job রোজ এই স্ক্রিপ্টটা চালায়।
//
// সেটআপ (cPanel → Cron Jobs → Once a day → Command):
//   php /home/shishur1/public_html/cron/db-backup.php
//   (আপনার আসল পাথ cPanel-এ "Cron Jobs" পেজে দেখানো থাকে — /home/<ইউজার>/public_html/...)
//
// ব্যাকআপ যায়: public_html/storage/backups/  (deny-all .htaccess দিয়ে সুরক্ষিত)
// সর্বশেষ ৭টা রাখে, পুরনোগুলো নিজে মুছে ফেলে (rotation)।
// অ্যাডমিন → "ব্যাকআপ ও ডাউনলোড" পেজের নিচে এই অটো-ব্যাকআপগুলোর তালিকা ও ডাউনলোড আছে।

// ⚠️ CLI ও ওয়েব — দুইভাবেই চালানো নিরাপদ রাখতে: শুধু কমান্ড-লাইন (cron) থেকে চালানো যাবে।
// কেউ ব্রাউজারে URL দিয়ে চালানোর চেষ্টা করলে ব্লক (বারবার ট্রিগার করে সার্ভার ভরানো ঠেকাতে)।
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('এই স্ক্রিপ্ট শুধু সার্ভারের নির্ধারিত সময়ে (cron) চলে।');
}

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../admin/includes/db-dump.php';

$result = db_backup_to_file(get_db(), 7);

$stamp = date('Y-m-d H:i:s');
if ($result['ok']) {
    fwrite(STDOUT, "[$stamp] ✅ ব্যাকআপ সফল: {$result['file']} (" . round($result['size'] / 1024) . " KB)\n");
    exit(0);
}
fwrite(STDERR, "[$stamp] ❌ ব্যাকআপ ব্যর্থ: {$result['error']}\n");
exit(1);
