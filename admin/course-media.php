<?php
// একটা আইটেমের ছবি ও ভিডিও পরিচালনা (`course_media`, ২০২৬-০৯-২৫; ২০২৬-০৯-২৬ থেকে
// কোর্স-ব্যাচ **ছাড়াও ওয়ার্কশিট ও প্রোডাক্টে**)।
//
// আসা হয়: কোর্স → course-batches.php-এর ব্যাচ তালিকা থেকে "🖼️ ছবি ও ভিডিও";
// ওয়ার্কশিট/প্রোডাক্ট → manage.php-এর তালিকা থেকে একই নামের লিংক।
// 🔴 ভিডিও কখনো আপলোড হয় না — শুধু ইউটিউব/গুগল-ড্রাইভের লিংক রাখা হয় (শেয়ার্ড হোস্টে
// ভিডিও রাখলে ডিস্ক কোটা ও ব্যান্ডউইথ কয়েকটাতেই শেষ)। লিংক পার্সিং/এমবেড তৈরি সবই
// শেয়ার্ড হেল্পার `course_video_parse()`-এ (includes/functions.php) — পাবলিক পেজও
// সেটাই ব্যবহার করে, তাই দুই জায়গায় নিয়ম আলাদা হওয়ার সুযোগ নেই।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form-helpers.php';
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();

// ── কার ছবি/ভিডিও: কোর্স-ব্যাচ, ওয়ার্কশিট, না প্রোডাক্ট? (২০২৬-০৯-২৬) ──
// পুরনো লিংক `?batch_id=X` এখনো কাজ করে (= কোর্স); নতুনগুলো `?type=worksheet&id=5`।
$ownerType = (string) ($_GET['type'] ?? ($_POST['owner_type'] ?? ''));
$ownerId   = (int) ($_GET['id'] ?? ($_POST['owner_id'] ?? 0));
$legacyBid = (int) ($_GET['batch_id'] ?? ($_POST['batch_id'] ?? 0));
if ($legacyBid > 0 && ($ownerType === '' || $ownerType === 'course')) {
    $ownerType = 'course';
    $ownerId   = $legacyBid;
}
if (!media_owner_valid($ownerType)) {
    $ownerType = 'course';
}

$batchId  = $ownerType === 'course' ? $ownerId : 0;   // 🔴 batch_id কলাম শুধু কোর্সেই বসে
$courseId = 0;
$batch    = null;

if ($ownerType === 'course') {
    $batchStmt = $db->prepare(
        'SELECT cb.*, c.title AS course_title, c.id AS parent_course_id
           FROM course_batches cb JOIN courses c ON c.id = cb.course_id
          WHERE cb.id = :id'
    );
    $batchStmt->execute(['id' => $ownerId]);
    $batch = $batchStmt->fetch();
    if (!$batch) {
        set_flash('error', 'ব্যাচটি পাওয়া যায়নি।');
        redirect('manage.php?entity=courses');
    }
    $courseId  = (int) $batch['parent_course_id'];
    $backUrl   = 'course-batches.php?course_id=' . $courseId;
    $ownerName = $batch['course_title'];
    $ownerSub  = $batch['batch_name'];
} else {
    // 🔴 টেবিলের নাম কখনো POST/GET থেকে নয় — হোয়াইটলিস্ট থেকে (SQL ইনজেকশন গার্ড)
    $tbl = $ownerType === 'worksheet' ? 'worksheets' : 'products';
    $ent = $ownerType === 'worksheet' ? 'worksheets' : 'products';
    $st  = $db->prepare("SELECT id, title FROM $tbl WHERE id = :id");
    $st->execute(['id' => $ownerId]);
    $row = $st->fetch();
    if (!$row) {
        set_flash('error', 'আইটেমটি পাওয়া যায়নি।');
        redirect('manage.php?entity=' . $ent);
    }
    $backUrl   = 'manage.php?entity=' . $ent;
    $ownerName = (string) $row['title'];
    $ownerSub  = media_owner_types()[$ownerType];
}

// এই পেজের নিজের URL — সব ফর্ম/রিডাইরেক্ট এটাই ব্যবহার করে
$selfUrl = 'course-media.php?type=' . rawurlencode($ownerType) . '&id=' . $ownerId;

$pageTitle = 'ছবি ও ভিডিও — ' . $ownerName . ' · ' . $ownerSub;

// টেবিলটা আছে কিনা (মাইগ্রেশন চালানো হয়েছে কিনা) — না থাকলে পেজ ভাঙবে না, ইঙ্গিত দেখাবে
$hasTable = true;
try {
    $db->query('SELECT id FROM course_media LIMIT 0');
} catch (PDOException $ex) {
    $hasTable = false;
}

// owner_type/owner_id কলাম আছে কিনা (২০২৬-০৯-২৬-এর দ্বিতীয় মাইগ্রেশন) — না থাকলে লেখা বন্ধ,
// শুধু ইঙ্গিত দেখায় (নাহলে প্রতিটা INSERT PDOException দিয়ে পেজ ভাঙত)।
$hasOwner = false;
if ($hasTable) {
    try {
        $db->query('SELECT owner_type, owner_id FROM course_media LIMIT 0');
        $hasOwner = true;
    } catch (PDOException $ex) {
        $hasOwner = false;
    }
}
$ready = $hasTable && $hasOwner;

// ─────────────────────────────────────────────────────────────
// POST হ্যান্ডলার
// ─────────────────────────────────────────────────────────────
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$act  = (string) ($_POST['action'] ?? '');

if ($post && !csrf_verify()) {
    set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
    redirect($selfUrl);
}

// এই আইটেমের পরবর্তী ক্রম নম্বর
$next_sort = function () use ($db, $ownerType, $ownerId): int {
    $s = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM course_media WHERE owner_type = :t AND owner_id = :b');
    $s->execute(['t' => $ownerType, 'b' => $ownerId]);
    return (int) $s->fetch()['n'];
};

// প্রতিটা INSERT-এ কমন কলাম — 🔴 batch_id শুধু কোর্সে (FK CASCADE), নাহলে NULL
$ownerCols = ['ot' => $ownerType, 'oi' => $ownerId, 'b' => $batchId > 0 ? $batchId : null];

// ── ছবি যোগ (একসাথে কয়েকটা) ──
if ($post && $act === 'add-photos' && $ready) {
    $files = $_FILES['photos'] ?? null;
    $added = 0;
    $errors = [];

    if (is_array($files['name'] ?? null)) {
        $caption = mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 200);
        $seq = $next_sort();
        $count = min(10, count($files['name'])); // একবারে সর্বোচ্চ ১০টা

        for ($i = 0; $i < $count; $i++) {
            if ((int) $files['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            // handle_image_upload() একটা ফাইল ধরে ($_FILES[$field]) — তাই প্রতিটার জন্য
            // একটা করে সাময়িক এন্ট্রি বানিয়ে সেই প্রমাণিত হেল্পারটাই রিইউজ করা হয়
            // (MIME যাচাই + র‍্যান্ডম নাম + ৪:৩ WebP রিসাইজ সবই ওখানেই হয়)।
            $_FILES['__cm_one'] = [
                'name'     => $files['name'][$i],
                'type'     => $files['type'][$i],
                'tmp_name' => $files['tmp_name'][$i],
                'error'    => $files['error'][$i],
                'size'     => $files['size'][$i],
            ];
            try {
                // 🔴 গ্যালারির ছবি **প্যাডিং ছাড়া** (pad=false) ও বড় বাক্সে (১৪০০×১৪০০) — পোর্ট্রেট ছবিও
                //    পুরো স্ক্রিন জুড়ে দেখায়; ৪:৩ ক্যানভাসে বসালে দুই পাশে সাদা ফালি পড়ত।
                $path = handle_image_upload('__cm_one', 'course-media', false, 1400, 1400);
                if ($path) {
                    $db->prepare(
                        'INSERT INTO course_media (owner_type, owner_id, batch_id, kind, file_path, caption, sort_order)
                         VALUES (:ot, :oi, :b, \'photo\', :p, :c, :s)'
                    )->execute($ownerCols + ['p' => $path, 'c' => $caption, 's' => $seq++]);
                    $added++;
                }
            } catch (RuntimeException $e) {
                $errors[] = $files['name'][$i] . ' — ' . $e->getMessage();
            }
        }
        unset($_FILES['__cm_one']);
    }

    if ($added > 0) {
        set_flash('success', $added . ' টি ছবি যোগ করা হয়েছে।' . ($errors ? ' তবে কিছু বাদ গেছে: ' . implode(' | ', $errors) : ''));
    } else {
        set_flash('error', $errors ? implode(' | ', $errors) : 'কোনো ছবি বাছাই করা হয়নি।');
    }
    redirect($selfUrl);
}

// ── ভিডিও লিংক যোগ ──
if ($post && $act === 'add-video' && $ready) {
    $url  = trim((string) ($_POST['video_url'] ?? ''));
    $name = mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 200);
    $meta = course_video_parse($url);

    if (!$meta) {
        // 🔴 চেনা না গেলে কখনো সেভ করা হয় না — নাহলে পাবলিক পেজে ভাঙা কার্ড দেখাত
        set_flash('error', 'লিংকটা চেনা গেল না। ইউটিউব (youtube.com / youtu.be) অথবা গুগল ড্রাইভের (drive.google.com) সম্পূর্ণ লিংক দিন।');
    } else {
        $db->prepare(
            'INSERT INTO course_media (owner_type, owner_id, batch_id, kind, video_url, provider, video_id, caption, sort_order)
             VALUES (:ot, :oi, :b, \'video\', :u, :pr, :vi, :c, :s)'
        )->execute($ownerCols + [
            'u' => $url, 'pr' => $meta['provider'], 'vi' => $meta['id'],
            'c' => $name !== '' ? $name : 'ভিডিও', 's' => $next_sort(),
        ]);
        set_flash('success', course_media_provider_label($meta['provider']) . ' ভিডিও যোগ করা হয়েছে।');
    }
    redirect($selfUrl);
}

// ── ক্রম ও নাম সংরক্ষণ ──
if ($post && $act === 'save-order' && $ready) {
    $rows = is_array($_POST['m'] ?? null) ? $_POST['m'] : [];
    // 🔴 `owner_type`+`owner_id` দিয়ে ছাঁকা — POST-এ অন্য আইটেমের id পাঠালেও কিছু বদলাবে না
    $upd = $db->prepare('UPDATE course_media SET sort_order = :s, caption = :c WHERE id = :id AND owner_type = :t AND owner_id = :b');
    $n = 0;
    foreach ($rows as $id => $r) {
        $upd->execute([
            's'  => max(0, min(9999, (int) ($r['sort_order'] ?? 0))),
            'c'  => mb_substr(trim((string) ($r['caption'] ?? '')), 0, 200),
            'id' => (int) $id,
            't'  => $ownerType,
            'b'  => $ownerId,
        ]);
        $n++;
    }
    set_flash('success', $n . ' টি সংরক্ষণ করা হয়েছে।');
    redirect($selfUrl);
}

// ── ডিলিট ──
// action-মার্কার 'delete' — কেন্দ্রীয় RBAC গার্ড (admin_delete_actions()) এটাই দেখে
if ($post && $act === 'delete' && $ready) {
    $id = (int) ($_POST['id'] ?? 0);
    $sel = $db->prepare('SELECT * FROM course_media WHERE id = :id AND owner_type = :t AND owner_id = :b');
    $sel->execute(['id' => $id, 't' => $ownerType, 'b' => $ownerId]);
    if ($row = $sel->fetch()) {
        $db->prepare('DELETE FROM course_media WHERE id = :id')->execute(['id' => $id]);
        // ছবির ফাইলটাও মুছে দেওয়া হয় — এটা কনটেন্ট আর্কাইভের অংশ নয় (ব্যাচ ডিলিট করলে
        // আর্কাইভে যায়, কিন্তু এখান থেকে একটা ছবি মোছা মানে অ্যাডমিন ইচ্ছে করেই মুছছেন)
        if (($row['kind'] ?? '') === 'photo' && !empty($row['file_path'])) {
            $f = __DIR__ . '/../' . ltrim((string) $row['file_path'], '/');
            if (is_file($f) && strpos(realpath($f) ?: '', realpath(__DIR__ . '/../uploads') ?: '') === 0) {
                @unlink($f);
            }
        }
        set_flash('success', 'মুছে ফেলা হয়েছে।');
    }
    redirect($selfUrl);
}

// ── অন্য আইটেম থেকে কপি ──
// 🔴 উৎস সবসময় **একই ধরনের** (কোর্সে: একই কোর্সের অন্য ব্যাচ; ওয়ার্কশিটে: অন্য ওয়ার্কশিট) —
//    অন্য কোর্স/ধরনের ছবি ভুল করে চলে আসা ঠেকাতে। যাচাই সার্ভারেই, ড্রপডাউনের উপর ভরসা নয়।
if ($post && $act === 'copy-from' && $ready) {
    $fromId = (int) ($_POST['from_batch'] ?? 0);
    $okSrc  = false;
    if ($fromId > 0 && $fromId !== $ownerId) {
        if ($ownerType === 'course') {
            $chk = $db->prepare('SELECT id FROM course_batches WHERE id = :id AND course_id = :c');
            $chk->execute(['id' => $fromId, 'c' => $courseId]);
        } else {
            $srcTbl = $ownerType === 'worksheet' ? 'worksheets' : 'products';  // হোয়াইটলিস্ট
            $chk = $db->prepare("SELECT id FROM $srcTbl WHERE id = :id");
            $chk->execute(['id' => $fromId]);
        }
        $okSrc = (bool) $chk->fetch();
    }

    if (!$okSrc) {
        set_flash('error', $ownerType === 'course'
            ? 'ব্যাচটি বেছে নিন (একই কোর্সের অন্য একটা ব্যাচ)।'
            : 'কোনটা থেকে কপি হবে সেটা বেছে নিন।');
    } else {
        $src = $db->prepare('SELECT * FROM course_media WHERE owner_type = :t AND owner_id = :b ORDER BY sort_order ASC, id ASC');
        $src->execute(['t' => $ownerType, 'b' => $fromId]);
        $ins = $db->prepare(
            'INSERT INTO course_media (owner_type, owner_id, batch_id, kind, file_path, video_url, provider, video_id, caption, sort_order)
             VALUES (:ot, :oi, :b, :k, :f, :u, :pr, :vi, :c, :s)'
        );
        $seq = $next_sort();
        $n = 0;
        foreach ($src->fetchAll() as $r) {
            // ছবির ফাইল কপি করা হয় না — একই ফাইলটাই দুই জায়গায় দেখায় (জায়গা বাঁচে)।
            // ⚠️ তাই উৎসের ছবি মুছলে এখানেও আর দেখাবে না (নিচে ইঙ্গিত লেখা আছে)।
            $ins->execute($ownerCols + [
                'k' => $r['kind'], 'f' => $r['file_path'], 'u' => $r['video_url'],
                'pr' => $r['provider'], 'vi' => $r['video_id'], 'c' => $r['caption'], 's' => $seq++,
            ]);
            $n++;
        }
        set_flash($n ? 'success' : 'error', $n ? ($n . ' টি কপি করা হয়েছে।') : 'ওখানে কিছু নেই।');
    }
    redirect($selfUrl);
}

// ─────────────────────────────────────────────────────────────
// ডেটা
// ─────────────────────────────────────────────────────────────
$media = $ready ? course_media_fetch($db, $ownerId, $ownerType) : ['photos' => [], 'videos' => []];
$all = array_merge($media['photos'], $media['videos']);
usort($all, function ($a, $b) {
    return [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']];
});

// কপি করার জন্য উৎসের তালিকা (যাদের কিছু আছে) — কোর্সে একই কোর্সের অন্য ব্যাচ,
// ওয়ার্কশিট/প্রোডাক্টে একই ধরনের অন্য আইটেম। কলাম-নাম `batch_name` রাখা হয়েছে যাতে
// নিচের মার্কআপ একটাই থাকে (দুই রকম লুপ লিখতে না হয়)।
$otherBatches = [];
if ($ready) {
    try {
        if ($ownerType === 'course') {
            $ob = $db->prepare(
                "SELECT cb.id, cb.batch_name, COUNT(cm.id) AS n
                   FROM course_batches cb JOIN course_media cm ON cm.owner_type = 'course' AND cm.owner_id = cb.id
                  WHERE cb.course_id = :c AND cb.id != :self
                  GROUP BY cb.id, cb.batch_name ORDER BY cb.sort_order ASC, cb.id ASC"
            );
            $ob->execute(['c' => $courseId, 'self' => $ownerId]);
        } else {
            $srcTbl = $ownerType === 'worksheet' ? 'worksheets' : 'products';  // হোয়াইটলিস্ট
            $ob = $db->prepare(
                "SELECT t.id, t.title AS batch_name, COUNT(cm.id) AS n
                   FROM $srcTbl t JOIN course_media cm ON cm.owner_type = :t AND cm.owner_id = t.id
                  WHERE t.id != :self
                  GROUP BY t.id, t.title ORDER BY t.sort_order ASC, t.id ASC"
            );
            $ob->execute(['t' => $ownerType, 'self' => $ownerId]);
        }
        $otherBatches = $ob->fetchAll();
    } catch (PDOException $ex) {
        $otherBatches = [];   // মাইগ্রেশনের আগে owner_* কলাম নেই — কপি-বক্স দেখাবে না
    }
}

require __DIR__ . '/includes/layout-top.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">🖼️ ছবি ও ভিডিও</h1>
        <p class="text-sm text-gray-500"><?= e($ownerName) ?> · <?= e($ownerSub) ?></p>
    </div>
    <a href="<?= e($backUrl) ?>" class="text-indigo-600 font-semibold text-sm">&larr; <?= $ownerType === 'course' ? 'ব্যাচ তালিকায়' : 'তালিকায়' ?> ফিরুন</a>
</div>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-5 text-sm">
        <strong>ডাটাবেস মাইগ্রেশন এখনো চালানো হয়নি।</strong>
        phpMyAdmin-এ
        <?php if (!$hasTable): ?>
            <code>database/migrate-course-media.sql</code> এবং <code>database/migrate-media-owner.sql</code> —
            দুটো ফাইলের SQL একবার (এই ক্রমে) চালিয়ে নিন
        <?php else: ?>
            <code>database/migrate-media-owner.sql</code> ফাইলের SQL একবার চালিয়ে নিন
        <?php endif; ?>
        — তারপর এই পেজ থেকে ছবি ও ভিডিও যোগ করা যাবে। (ততক্ষণ সাইটের আর কিছু ভাঙবে না।)
    </div>
<?php else: ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <!-- ছবি যোগ -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-800 mb-3">📸 ছবি যোগ করুন</h2>
        <form method="post" action="<?= e($selfUrl) ?>" enctype="multipart/form-data" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add-photos">
            <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ছবি (একসাথে কয়েকটা বাছাই করতে পারবেন)</label>
                <input type="file" name="photos[]" accept="image/*" multiple required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">একবারে সর্বোচ্চ ১০টি · প্রতিটি ৩ মেগাবাইট পর্যন্ত · ছবির নিজের অনুপাতেই থাকবে, শুধু WebP-তে ছোট হয়ে যাবে</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ক্যাপশন (ঐচ্ছিক)</label>
                <input type="text" name="caption" maxlength="200" placeholder="যেমন: ক্লাসে হাতে-কলমে গণিত"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">একসাথে কয়েকটা দিলে সবগুলোতে এই একই ক্যাপশন বসবে — পরে নিচের তালিকা থেকে আলাদা করে বদলানো যাবে।</p>
            </div>
            <button type="submit" class="bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm">যোগ করুন</button>
        </form>
    </div>

    <!-- ভিডিও যোগ -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-800 mb-3">▶️ ভিডিও যোগ করুন</h2>
        <form method="post" action="<?= e($selfUrl) ?>" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add-video">
            <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ইউটিউব বা গুগল ড্রাইভের লিংক</label>
                <input type="url" name="video_url" required placeholder="https://youtu.be/xxxxxxxxxxx"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">ভিডিও সার্ভারে আপলোড হয় না — শুধু লিংকটা রাখা হয়, তাই হোস্টিংয়ের জায়গা লাগে না।</p>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ভিডিওর নাম</label>
                <input type="text" name="caption" maxlength="200" placeholder="যেমন: কোর্স পরিচিতি — ২ মিনিট"
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
            </div>
            <button type="submit" class="bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm">যোগ করুন</button>
            <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-3 text-xs">
                <strong>গুগল ড্রাইভ ব্যবহার করলে:</strong> ফাইলটা অবশ্যই <strong>"Anyone with the link → Viewer"</strong> করা থাকতে হবে,
                নাহলে সাইটে চলবে না। আর ড্রাইভে ভিউ-লিমিট আছে — অনেকে দেখলে গুগল কয়েক ঘণ্টার জন্য বন্ধ করে দিতে পারে।
                <strong>ইউটিউবে "Unlisted" রাখাই নিরাপদ।</strong>
            </div>
        </form>
    </div>
</div>

<?php if ($otherBatches): ?>
<div class="bg-white rounded-2xl shadow p-5 mb-5">
    <h2 class="font-bold text-gray-800 mb-1">📋 অন্য <?= $ownerType === 'course' ? 'ব্যাচ' : e($ownerSub) ?> থেকে কপি করুন</h2>
    <p class="text-xs text-gray-500 mb-3">
        <?= $ownerType === 'course' ? 'একই কোর্সের অন্য ব্যাচের' : 'অন্য একটার' ?> ছবি ও ভিডিও এখানে যোগ হবে (আগেরগুলো মুছবে না)।
        ছবির ফাইল দুইবার রাখা হয় না — একই ফাইলটাই দুই জায়গায় দেখায়, তাই জায়গা বাড়ে না।
    </p>
    <form method="post" action="<?= e($selfUrl) ?>" class="flex flex-wrap items-center gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="copy-from">
        <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
        <select name="from_batch" required class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="">— <?= $ownerType === 'course' ? 'ব্যাচ' : e($ownerSub) ?> বেছে নিন —</option>
            <?php foreach ($otherBatches as $ob2): ?>
                <option value="<?= (int) $ob2['id'] ?>"><?= e($ob2['batch_name']) ?> (<?= (int) $ob2['n'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-amber-100 text-amber-800 font-bold px-4 py-2 rounded-xl text-sm">কপি করুন</button>
    </form>
</div>
<?php endif; ?>

<?php // 🔴 কাঠামোর নিয়ম (২০২৬-০৯-২৬-এ ইউজারের মোবাইল স্ক্রিনশটে ধরা): `<table>` অবশ্যই
      //    `.overflow-x-auto`-এর **সরাসরি সন্তান** হতে হবে — layout-top.php-এর মোবাইল কার্ড-CSS ও
      //    layout-bottom.php-এর labelize() দুটোই `main .overflow-x-auto > table` সিলেক্টর ব্যবহার করে।
      //    আগে টেবিলটা `<form>`-এর ভেতরে ছিল, তাই কার্ড-লেআউট বসত না আর ক্যাপশনের ঘর পেজ ছাপিয়ে যেত।
      //    তাই ফর্ম ও শিরোনাম এখন মোড়কের **বাইরে**। ?>
<div class="flex flex-wrap items-center justify-between gap-2 mb-3">
    <h2 class="font-bold text-gray-800">এখানে যা আছে (<?= count($all) ?>)</h2>
    <p class="text-xs text-gray-500">ছোট ক্রম নম্বর আগে দেখাবে</p>
</div>

<?php if (!$all): ?>
    <div class="bg-white rounded-2xl shadow p-5">
        <p class="text-sm text-gray-500">এখনো কিছু যোগ করা হয়নি — উপরের ঘর দুটো থেকে ছবি বা ভিডিও যোগ করুন। কিছু না থাকলে সাইটে বোতামই দেখাবে না।</p>
    </div>
<?php else: ?>
    <form method="post" action="<?= e($selfUrl) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save-order">
        <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
        <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-left text-gray-600">
                <tr>
                    <th class="py-3 px-4">ক্রম</th>
                    <th class="py-3 px-4">প্রিভিউ</th>
                    <th class="py-3 px-4">ধরন</th>
                    <th class="py-3 px-4">নাম / ক্যাপশন</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody><?php // divide-y কম্পাইলড অ্যাডমিন CSS-এ নেই — প্রতি সারিতে border-t দেওয়া হয়েছে ?>
            <?php foreach ($all as $m):
                $isVideo = ($m['kind'] ?? '') === 'video';
                $meta = $isVideo ? course_video_parse((string) $m['video_url']) : null;
                ?>
                <tr class="border-t border-gray-100">
                    <td class="py-2.5 px-4">
                        <input type="number" name="m[<?= (int) $m['id'] ?>][sort_order]" value="<?= (int) $m['sort_order'] ?>"
                               class="border border-gray-300 rounded-lg px-2 py-1 text-sm" style="width:4.5rem">
                    </td>
                    <td class="py-2.5 px-4">
                        <?php if ($isVideo): ?>
                            <?php if ($meta): ?>
                                <img src="<?= e($meta['thumb']) ?>" alt="" class="object-cover rounded-lg bg-gray-100"
                                     style="width:80px;height:60px" onerror="this.style.visibility='hidden'">
                            <?php else: ?>
                                <span class="text-red-600 text-xs font-semibold">⚠ লিংক চেনা যাচ্ছে না</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <img src="<?= e(admin_image_src((string) $m['file_path'])) ?>" alt=""
                                 class="object-cover rounded-lg bg-gray-100" style="width:80px;height:60px">
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 whitespace-nowrap">
                        <?php if ($isVideo): ?>
                            <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-purple-100 text-purple-800">ভিডিও</span>
                            <span class="block text-xs text-gray-400 mt-1"><?= e($meta ? course_media_provider_label((string) $meta['provider']) : '—') ?></span>
                        <?php else: ?>
                            <span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold bg-green-100 text-green-800">ছবি</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4">
                        <input type="text" name="m[<?= (int) $m['id'] ?>][caption]" value="<?= e((string) $m['caption']) ?>" maxlength="200"
                               <?php // 🔴 min-width দেবেন না — মোবাইল কার্ডে সেলটা flex (লেবেল + ইনপুট),
                                     //    ফিক্সড প্রস্থ দিলে ৩২০px পর্দায় ঘরটা বাইরে বেরিয়ে যায় ?>
                               class="w-full border border-gray-300 rounded-lg px-2 py-1 text-sm" style="min-width:0">
                        <?php if ($isVideo): ?>
                            <span class="block text-xs text-gray-400 mt-1" style="word-break:break-all"><?= e((string) $m['video_url']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 whitespace-nowrap">
                        <button type="button" class="text-red-600 font-semibold cm-del" data-id="<?= (int) $m['id'] ?>">ডিলিট</button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <div class="mt-4">
            <button type="submit" class="bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm">ক্রম ও নাম সংরক্ষণ করুন</button>
        </div>
    </form>

    <?php // ডিলিট আলাদা ফর্মে — নেস্টেড ফর্ম HTML-এ চলে না, তাই JS দিয়ে এটাতে id বসিয়ে সাবমিট করা হয় ?>
    <form method="post" action="<?= e($selfUrl) ?>" id="cmDeleteForm" class="hidden">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="owner_type" value="<?= e($ownerType) ?>">
        <input type="hidden" name="owner_id" value="<?= $ownerId ?>">
        <input type="hidden" name="id" id="cmDeleteId" value="">
    </form>
<?php endif; ?>

<script>
document.querySelectorAll('.cm-del').forEach(function (b) {
    b.addEventListener('click', function () {
        showConfirmModal('এটি মুছে ফেললে সাইটে আর দেখাবে না। ছবি হলে ফাইলটাও সার্ভার থেকে মুছে যাবে।', function () {
            document.getElementById('cmDeleteId').value = b.getAttribute('data-id');
            document.getElementById('cmDeleteForm').submit();
        }, 'মুছে ফেলার নিশ্চিতকরণ');
    });
});
</script>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
