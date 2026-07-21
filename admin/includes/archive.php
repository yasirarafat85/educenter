<?php
// ─────────────────────────────────────────────────────────────────────────────
// আর্কাইভ/রিস্টোর লাইব্রেরি — ডিলিট করা কনটেন্ট চিরতরে না মুছে `archived_items` টেবিলে
// একটা JSON বান্ডল (রো + সব descendant child) হিসেবে রাখে; প্রয়োজনে হুবহু **আসল id সহ**
// ফিরিয়ে আনা যায় (id বদলায় না বলে registrations.item_id ইত্যাদি রিলেশন পুনরায় কানেক্ট হয়)।
//
// ⭐ মূল সুবিধা: ডিলিট করা রো সত্যিই টেবিল থেকে চলে যায় — তাই কোনো পাবলিক/অ্যাডমিন read-query
//    বদলাতে হয় না (soft-delete `deleted_at` হলে প্রতিটা কোয়েরিতে ফিল্টার লাগত, একটা মিস করলেই বাগ)।
//
// ব্যবহার: ডিলিট করার আগে archive_entity() ডাকুন, তারপর বিদ্যমান DELETE চালান (FK cascade লাইভ
// child মুছে দেয়, বান্ডলে সব আগেই তোলা হয়ে গেছে)।
// ─────────────────────────────────────────────────────────────────────────────

// প্রতিটা এন্টিটির (টেবিল-নাম কী) child হায়ারার্কি — recursive; যেগুলোর child নেই সেগুলো তালিকায় নেই
function archive_children_map(): array
{
    return [
        'courses'        => [['table' => 'course_batches', 'fk' => 'course_id', 'children' => [
                                ['table' => 'course_features', 'fk' => 'batch_id'],
                            ]]],
        'course_batches' => [['table' => 'course_features', 'fk' => 'batch_id']],
        'products'       => [['table' => 'product_features', 'fk' => 'product_id']],
        // রেজিস্ট্রেশন/অর্ডার — child ক্রম গুরুত্বপূর্ণ (রিস্টোরে এই ক্রমেই re-insert হয়, FK টার্গেট আগে থাকতে হয়):
        // income (registration_id), তারপর courier_batches (registration_id), তারপর courier_shipments
        // (registration_id — flat, batch_id ওই batch গুলোকেই পয়েন্ট করে যা এইমাত্র restore হলো)।
        // courier_shipments ইচ্ছাকৃতভাবে registration_id দিয়ে flat সংগ্রহ (courier_batches এর নিচে nested না) —
        // নাহলে batch_id-যুক্ত শিপমেন্ট দুইবার সংগ্রহ হয়ে restore এ duplicate id crash করত।
        'registrations'  => [
            ['table' => 'income', 'fk' => 'registration_id'],
            ['table' => 'registration_courier_notes', 'fk' => 'registration_id'],
            ['table' => 'courier_batches', 'fk' => 'registration_id'],
            ['table' => 'courier_shipments', 'fk' => 'registration_id'],
        ],
    ];
}

// এন্টিটি টেবিল → মানুষের পড়ার মতো বাংলা লেবেল (আর্কাইভ তালিকায় দেখাতে)
function archive_type_label(string $table): string
{
    return [
        'courses' => 'কোর্স', 'course_batches' => 'কোর্স ব্যাচ', 'worksheets' => 'ওয়ার্কশিট',
        'products' => 'প্রোডাক্ট', 'teachers' => 'শিক্ষক', 'reviews' => 'রিভিউ',
        'notices' => 'নোটিশ', 'gallery' => 'গ্যালারি', 'faqs' => 'প্রশ্নোত্তর (FAQ)',
        'registrations' => 'রেজিস্ট্রেশন/অর্ডার',
    ][$table] ?? $table;
}

// একটা রো + তার সব descendant (recursive) কে nested বান্ডল হিসেবে তোলে
function archive_collect_row(PDO $db, string $table, array $row, array $childDefs): array
{
    $bundle = ['table' => $table, 'row' => $row, 'children' => []];
    foreach ($childDefs as $cd) {
        $cstmt = $db->prepare("SELECT * FROM `{$cd['table']}` WHERE `{$cd['fk']}` = :id");
        $cstmt->execute(['id' => $row['id']]);
        foreach ($cstmt->fetchAll(PDO::FETCH_ASSOC) as $crow) {
            $bundle['children'][] = archive_collect_row($db, $cd['table'], $crow, $cd['children'] ?? []);
        }
    }
    return $bundle;
}

// একটা এন্টিটি রো (+ children) আর্কাইভ করে — DELETE করার ঠিক আগে ডাকতে হবে। আর্কাইভ id রিটার্ন (0 = রো নেই)
function archive_entity(PDO $db, string $table, int $id): int
{
    $stmt = $db->prepare("SELECT * FROM `$table` WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0;
    }
    $childDefs = archive_children_map()[$table] ?? [];
    $bundle = archive_collect_row($db, $table, $row, $childDefs);

    // তালিকায় দেখানোর মতো একটা লেবেল (রো থেকে অনুমান)
    if ($table === 'registrations') {
        $label = trim(($row['item_title'] ?? '') . ' — ' . ($row['customer_name'] ?? ''), ' —') ?: ('#' . $id);
    } else {
        $label = $row['title'] ?? $row['name'] ?? $row['batch_name'] ?? $row['question'] ?? $row['heading'] ?? ('#' . $id);
    }

    $ins = $db->prepare(
        'INSERT INTO archived_items (entity_type, original_id, label, data_json) VALUES (:t, :oid, :label, :json)'
    );
    $ins->execute([
        't'     => $table,
        'oid'   => $id,
        'label' => mb_substr((string) $label, 0, 250),
        'json'  => json_encode($bundle, JSON_UNESCAPED_UNICODE),
    ]);
    return (int) $db->lastInsertId();
}

// একটা বান্ডল (parent তারপর children) পুনরায় আসল id সহ INSERT করে (recursive)
function archive_reinsert(PDO $db, array $bundle): void
{
    $table = $bundle['table'];
    $row = $bundle['row'];
    $cols = array_keys($row);
    $sql = "INSERT INTO `$table` (" . implode(',', array_map(fn($c) => "`$c`", $cols)) . ')'
         . ' VALUES (' . implode(',', array_map(fn($c) => ':' . $c, $cols)) . ')';
    $db->prepare($sql)->execute($row);
    foreach ($bundle['children'] ?? [] as $child) {
        archive_reinsert($db, $child);
    }
}

// আর্কাইভ থেকে রিস্টোর — রো(+children) ফিরিয়ে আনে ও আর্কাইভ রেকর্ড মোছে (এক ট্রানজ্যাকশনে)।
// টাইটেল/স্লাগ UNIQUE কনফ্লিক্ট হলে (মাঝে একই নামে নতুন তৈরি হয়ে থাকলে) PDOException ছুঁড়বে, rollback হবে।
function restore_archived(PDO $db, int $archiveId): bool
{
    $stmt = $db->prepare('SELECT * FROM archived_items WHERE id = :id');
    $stmt->execute(['id' => $archiveId]);
    $arc = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$arc) {
        return false;
    }
    $bundle = json_decode($arc['data_json'], true);
    if (!is_array($bundle) || empty($bundle['table'])) {
        return false;
    }
    $db->beginTransaction();
    try {
        archive_reinsert($db, $bundle);
        $db->prepare('DELETE FROM archived_items WHERE id = :id')->execute(['id' => $archiveId]);
        $db->commit();
        return true;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// আর্কাইভ থেকে চিরতরে মুছে ফেলা (আর ফেরানো যাবে না)
function purge_archived(PDO $db, int $archiveId): void
{
    $db->prepare('DELETE FROM archived_items WHERE id = :id')->execute(['id' => $archiveId]);
}
