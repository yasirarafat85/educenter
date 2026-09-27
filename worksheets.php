<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'ওয়ার্কশিট সমূহ';
$activePage = 'worksheets';
$pageDescription = 'শিশুদের অনুশীলনের জন্য বিশেষভাবে তৈরি ওয়ার্কশিট — হাতের লেখা, অঙ্ক ও চিন্তার দক্ষতা গড়ে তোলার সহজ উপকরণ। ঘরে বসেই অর্ডার করুন।';

// 📄 পেজিনেশন (২০২৬-০৯-২৭) — আগে সব রো একসাথে আসত
$db = get_db();
$wsPage = public_paginate((int) $db->query('SELECT COUNT(*) FROM worksheets WHERE is_active = 1')->fetchColumn());
$wsStmt = $db->prepare('SELECT * FROM worksheets WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT :lim OFFSET :off');
$wsStmt->bindValue(':lim', $wsPage['per'], PDO::PARAM_INT);   // 🔴 LIMIT/OFFSET-এ bindValue+PARAM_INT লাগে
$wsStmt->bindValue(':off', $wsPage['offset'], PDO::PARAM_INT); //    (non-emulated prepare-এ স্ট্রিং দিলে SQL সিনট্যাক্স এরর)
$wsStmt->execute();
$worksheets = $wsStmt->fetchAll();

$pageJsonLd = jsonld_breadcrumb([['name' => 'ওয়ার্কশিট', 'url' => 'worksheets']]);

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('file-text', 'অনুশীলন সামগ্রী', 'ওয়ার্কশিট সমূহ', 'অনুশীলনের জন্য বিশেষভাবে প্রস্তুতকৃত প্রিমিয়াম ওয়ার্কশিট', 'text-green-700') ?>
    <?php if ($worksheets): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
        <?= implode('', array_map(fn($w) => render_item_card($w, 'worksheet'), $worksheets)) ?>
    </div>
    <?= render_pagination($wsPage, 'worksheets') ?>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো ওয়ার্কশিট যোগ করা হয়নি।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
