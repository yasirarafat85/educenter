<?php
// ডাইনামিক XML সাইটম্যাপ — Google/সার্চ ইঞ্জিনের জন্য। স্ট্যাটিক পাবলিক পেজ + সক্রিয় কোর্স-ব্যাচ/
// ওয়ার্কশিট/প্রোডাক্টের ডিটেইল URL লিস্ট করে। (robots.txt এ বা Google Search Console এ সাবমিট করুন।)
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');

$base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$db = get_db();
$urls = [];

// ক্লিন URL (.htaccess রিরাইট) — index → রুট, বাকি পেজ extensionless, detail → detail?type=..&id=..
$urls[] = [$base . '/', '1.0', ''];
foreach (['courses', 'worksheets', 'products', 'notice', 'teachers', 'reviews', 'about', 'gallery', 'faqs', 'course-interest'] as $p) {
    $urls[] = [$base . '/' . $p, '0.8', ''];
}

// <lastmod> — কোন পাতা কবে শেষ বদলেছে; গুগল এটা দেখে বদলানো পাতাগুলো আগে ক্রল করে।
// 🔴 `updated_at` না থাকলে (পুরনো রো/মাইগ্রেশনের আগে) চুপচাপ বাদ — ভুল তারিখ দেওয়ার
//    চেয়ে না দেওয়াই ভালো, গুগল ভুল lastmod-কে অবিশ্বাস করতে শুরু করে।
$lastmod = static function ($row): string {
    $v = $row['updated_at'] ?? $row['created_at'] ?? '';
    if (!$v) {
        return '';
    }
    $t = strtotime((string) $v);
    return $t ? date('Y-m-d', $t) : '';
};

try {
    foreach ($db->query("SELECT * FROM course_batches WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=course&id=' . (int) $r['id'], '0.7', $lastmod($r)];
    }
    foreach ($db->query("SELECT * FROM worksheets WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=worksheet&id=' . (int) $r['id'], '0.6', $lastmod($r)];
    }
    foreach ($db->query("SELECT * FROM products WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=product&id=' . (int) $r['id'], '0.6', $lastmod($r)];
    }
} catch (Throwable $e) {
    // টেবিল না থাকলে শুধু স্ট্যাটিক পেজ থাকবে
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $pri, $mod]) {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc>'
        . ($mod !== '' ? '<lastmod>' . $mod . '</lastmod>' : '')
        . '<changefreq>weekly</changefreq><priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>';
