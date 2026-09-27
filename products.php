<?php
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'পণ্য সমূহ';
$activePage = 'products';
$pageDescription = 'শিশুদের শেখার সহায়ক প্রোডাক্ট — বই, শিক্ষা উপকরণ ও শিশুবান্ধব সামগ্রী। সারা দেশে কুরিয়ারে পৌঁছে দেওয়া হয়।';

// 📄 পেজিনেশন (২০২৬-০৯-২৭) — আগে সব রো একসাথে আসত
$db = get_db();
$prPage = public_paginate((int) $db->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn());
$prStmt = $db->prepare('SELECT * FROM products WHERE is_active = 1 ORDER BY sort_order ASC, id ASC LIMIT :lim OFFSET :off');
$prStmt->bindValue(':lim', $prPage['per'], PDO::PARAM_INT);
$prStmt->bindValue(':off', $prPage['offset'], PDO::PARAM_INT);
$prStmt->execute();
$products = $prStmt->fetchAll();

$pageJsonLd = jsonld_breadcrumb([['name' => 'প্রোডাক্ট', 'url' => 'products']]);

require __DIR__ . '/includes/site-header.php';
?>

<div>
    <?= render_page_header('shopping-bag', 'শিক্ষা উপকরণ', 'আমাদের পণ্য সমূহ', 'শিক্ষার জন্য প্রয়োজনীয় সেরা মানের উপকরণ ও টুলস', 'text-purple-700') ?>
    <?php if ($products): ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6 sm:gap-8">
        <?= implode('', array_map(fn($p) => render_item_card($p, 'product'), $products)) ?>
    </div>
    <?= render_pagination($prPage, 'products') ?>
    <?php else: ?>
        <p class="text-center text-gray-500">এখনো কোনো পণ্য যোগ করা হয়নি।</p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
