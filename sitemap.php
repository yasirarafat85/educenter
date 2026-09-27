<?php
// ডাইনামিক XML সাইটম্যাপ — Google/সার্চ ইঞ্জিনের জন্য। স্ট্যাটিক পাবলিক পেজ + সক্রিয় কোর্স-ব্যাচ/
// ওয়ার্কশিট/প্রোডাক্টের ডিটেইল URL লিস্ট করে। (robots.txt এ বা Google Search Console এ সাবমিট করুন।)
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');

$base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$db = get_db();
$urls = [];

// ক্লিন URL (.htaccess রিরাইট) — index → রুট, বাকি পেজ extensionless,
// আইটেম → `course/12-নাম` (item_url(); ২০২৬-০৯-২৭ থেকে, আগে `detail?type=..&id=..` ছিল)
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
    // 🔴 item_url() কোর্সে `title` + `batch_name` দুটোই পড়ে — তাই courses-এর সাথে JOIN
    //    (course_batches-এ title নেই, ওটা parent টেবিলে — CLAUDE.md-এর parent/child নিয়ম)
    $cbSql = "SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1";
    foreach ($db->query($cbSql)->fetchAll() as $r) {
        $urls[] = [$base . '/' . item_url($r, 'course'), '0.7', $lastmod($r)];
    }
    foreach ($db->query("SELECT * FROM worksheets WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/' . item_url($r, 'worksheet'), '0.6', $lastmod($r)];
    }
    foreach ($db->query("SELECT * FROM products WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/' . item_url($r, 'product'), '0.6', $lastmod($r)];
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
