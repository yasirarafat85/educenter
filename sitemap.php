<?php
// ডাইনামিক XML সাইটম্যাপ — Google/সার্চ ইঞ্জিনের জন্য। স্ট্যাটিক পাবলিক পেজ + সক্রিয় কোর্স-ব্যাচ/
// ওয়ার্কশিট/প্রোডাক্টের ডিটেইল URL লিস্ট করে। (robots.txt এ বা Google Search Console এ সাবমিট করুন।)
require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/xml; charset=utf-8');

$base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$db = get_db();
$urls = [];

// ক্লিন URL (.htaccess রিরাইট) — index → রুট, বাকি পেজ extensionless, detail → detail?type=..&id=..
$urls[] = [$base . '/', '1.0'];
foreach (['courses', 'worksheets', 'products', 'notice', 'teachers', 'reviews', 'about', 'gallery', 'faqs'] as $p) {
    $urls[] = [$base . '/' . $p, '0.8'];
}

try {
    foreach ($db->query("SELECT id FROM course_batches WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=course&id=' . (int) $r['id'], '0.7'];
    }
    foreach ($db->query("SELECT id FROM worksheets WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=worksheet&id=' . (int) $r['id'], '0.6'];
    }
    foreach ($db->query("SELECT id FROM products WHERE is_active = 1")->fetchAll() as $r) {
        $urls[] = [$base . '/detail?type=product&id=' . (int) $r['id'], '0.6'];
    }
} catch (Throwable $e) {
    // টেবিল না থাকলে শুধু স্ট্যাটিক পেজ থাকবে
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as [$loc, $pri]) {
    echo '  <url><loc>' . htmlspecialchars($loc, ENT_XML1) . '</loc><changefreq>weekly</changefreq><priority>' . $pri . '</priority></url>' . "\n";
}
echo '</urlset>';
