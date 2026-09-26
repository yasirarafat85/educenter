<?php
// একটা নির্দিষ্ট কোর্স-ব্যাচের ছবি ও ভিডিও পরিচালনা (`course_media`, ২০২৬-০৯-২৫)।
//
// course-batches.php-এর ব্যাচ তালিকা থেকে "🖼️ ছবি ও ভিডিও" বোতামে এখানে আসা হয়।
// 🔴 ভিডিও কখনো আপলোড হয় না — শুধু ইউটিউব/গুগল-ড্রাইভের লিংক রাখা হয় (শেয়ার্ড হোস্টে
// ভিডিও রাখলে ডিস্ক কোটা ও ব্যান্ডউইথ কয়েকটাতেই শেষ)। লিংক পার্সিং/এমবেড তৈরি সবই
// শেয়ার্ড হেল্পার `course_video_parse()`-এ (includes/functions.php) — পাবলিক পেজও
// সেটাই ব্যবহার করে, তাই দুই জায়গায় নিয়ম আলাদা হওয়ার সুযোগ নেই।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form-helpers.php';
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();

$batchId = (int) ($_GET['batch_id'] ?? ($_POST['batch_id'] ?? 0));
$batchStmt = $db->prepare(
    'SELECT cb.*, c.title AS course_title, c.id AS parent_course_id
       FROM course_batches cb JOIN courses c ON c.id = cb.course_id
      WHERE cb.id = :id'
);
$batchStmt->execute(['id' => $batchId]);
$batch = $batchStmt->fetch();
if (!$batch) {
    set_flash('error', 'ব্যাচটি পাওয়া যায়নি।');
    redirect('manage.php?entity=courses');
}
$courseId = (int) $batch['parent_course_id'];
$backUrl  = 'course-batches.php?course_id=' . $courseId;

$pageTitle = 'ছবি ও ভিডিও — ' . $batch['course_title'] . ' · ' . $batch['batch_name'];

// টেবিলটা আছে কিনা (মাইগ্রেশন চালানো হয়েছে কিনা) — না থাকলে পেজ ভাঙবে না, ইঙ্গিত দেখাবে
$hasTable = true;
try {
    $db->query('SELECT id FROM course_media LIMIT 0');
} catch (PDOException $ex) {
    $hasTable = false;
}

// ─────────────────────────────────────────────────────────────
// POST হ্যান্ডলার
// ─────────────────────────────────────────────────────────────
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
$act  = (string) ($_POST['action'] ?? '');

if ($post && !csrf_verify()) {
    set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
    redirect('course-media.php?batch_id=' . $batchId);
}

// এই ব্যাচের পরবর্তী ক্রম নম্বর
$next_sort = function () use ($db, $batchId): int {
    $s = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM course_media WHERE batch_id = :b');
    $s->execute(['b' => $batchId]);
    return (int) $s->fetch()['n'];
};

// ── ছবি যোগ (একসাথে কয়েকটা) ──
if ($post && $act === 'add-photos' && $hasTable) {
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
                        'INSERT INTO course_media (batch_id, kind, file_path, caption, sort_order)
                         VALUES (:b, \'photo\', :p, :c, :s)'
                    )->execute(['b' => $batchId, 'p' => $path, 'c' => $caption, 's' => $seq++]);
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
    redirect('course-media.php?batch_id=' . $batchId);
}

// ── ভিডিও লিংক যোগ ──
if ($post && $act === 'add-video' && $hasTable) {
    $url  = trim((string) ($_POST['video_url'] ?? ''));
    $name = mb_substr(trim((string) ($_POST['caption'] ?? '')), 0, 200);
    $meta = course_video_parse($url);

    if (!$meta) {
        // 🔴 চেনা না গেলে কখনো সেভ করা হয় না — নাহলে পাবলিক পেজে ভাঙা কার্ড দেখাত
        set_flash('error', 'লিংকটা চেনা গেল না। ইউটিউব (youtube.com / youtu.be) অথবা গুগল ড্রাইভের (drive.google.com) সম্পূর্ণ লিংক দিন।');
    } else {
        $db->prepare(
            'INSERT INTO course_media (batch_id, kind, video_url, provider, video_id, caption, sort_order)
             VALUES (:b, \'video\', :u, :pr, :vi, :c, :s)'
        )->execute([
            'b' => $batchId, 'u' => $url, 'pr' => $meta['provider'], 'vi' => $meta['id'],
            'c' => $name !== '' ? $name : 'ভিডিও', 's' => $next_sort(),
        ]);
        set_flash('success', course_media_provider_label($meta['provider']) . ' ভিডিও যোগ করা হয়েছে।');
    }
    redirect('course-media.php?batch_id=' . $batchId);
}

// ── ক্রম ও নাম সংরক্ষণ ──
if ($post && $act === 'save-order' && $hasTable) {
    $rows = is_array($_POST['m'] ?? null) ? $_POST['m'] : [];
    $upd = $db->prepare('UPDATE course_media SET sort_order = :s, caption = :c WHERE id = :id AND batch_id = :b');
    $n = 0;
    foreach ($rows as $id => $r) {
        $upd->execute([
            's'  => max(0, min(9999, (int) ($r['sort_order'] ?? 0))),
            'c'  => mb_substr(trim((string) ($r['caption'] ?? '')), 0, 200),
            'id' => (int) $id,
            'b'  => $batchId,
        ]);
        $n++;
    }
    set_flash('success', $n . ' টি সংরক্ষণ করা হয়েছে।');
    redirect('course-media.php?batch_id=' . $batchId);
}

// ── ডিলিট ──
// action-মার্কার 'delete' — কেন্দ্রীয় RBAC গার্ড (admin_delete_actions()) এটাই দেখে
if ($post && $act === 'delete' && $hasTable) {
    $id = (int) ($_POST['id'] ?? 0);
    $sel = $db->prepare('SELECT * FROM course_media WHERE id = :id AND batch_id = :b');
    $sel->execute(['id' => $id, 'b' => $batchId]);
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
    redirect('course-media.php?batch_id=' . $batchId);
}

// ── অন্য ব্যাচ থেকে কপি ──
if ($post && $act === 'copy-from' && $hasTable) {
    $fromId = (int) ($_POST['from_batch'] ?? 0);
    // 🔴 শুধু **একই কোর্সের** অন্য ব্যাচ থেকে — অন্য কোর্সের ছবি ভুল করে চলে আসা ঠেকাতে
    $chk = $db->prepare('SELECT id FROM course_batches WHERE id = :id AND course_id = :c');
    $chk->execute(['id' => $fromId, 'c' => $courseId]);

    if (!$chk->fetch() || $fromId === $batchId) {
        set_flash('error', 'ব্যাচটি বেছে নিন (একই কোর্সের অন্য একটা ব্যাচ)।');
    } else {
        $src = $db->prepare('SELECT * FROM course_media WHERE batch_id = :b ORDER BY sort_order ASC, id ASC');
        $src->execute(['b' => $fromId]);
        $ins = $db->prepare(
            'INSERT INTO course_media (batch_id, kind, file_path, video_url, provider, video_id, caption, sort_order)
             VALUES (:b, :k, :f, :u, :pr, :vi, :c, :s)'
        );
        $seq = $next_sort();
        $n = 0;
        foreach ($src->fetchAll() as $r) {
            // ছবির ফাইল কপি করা হয় না — একই ফাইলটাই দুই ব্যাচ দেখায় (জায়গা বাঁচে)।
            // ⚠️ তাই উৎস ব্যাচের ছবি মুছলে এখানেও আর দেখাবে না (নিচে ইঙ্গিত লেখা আছে)।
            $ins->execute([
                'b' => $batchId, 'k' => $r['kind'], 'f' => $r['file_path'], 'u' => $r['video_url'],
                'pr' => $r['provider'], 'vi' => $r['video_id'], 'c' => $r['caption'], 's' => $seq++,
            ]);
            $n++;
        }
        set_flash($n ? 'success' : 'error', $n ? ($n . ' টি কপি করা হয়েছে।') : 'ঐ ব্যাচে কিছু নেই।');
    }
    redirect('course-media.php?batch_id=' . $batchId);
}

// ─────────────────────────────────────────────────────────────
// ডেটা
// ─────────────────────────────────────────────────────────────
$media = $hasTable ? course_media_fetch($db, $batchId) : ['photos' => [], 'videos' => []];
$all = array_merge($media['photos'], $media['videos']);
usort($all, function ($a, $b) {
    return [(int) $a['sort_order'], (int) $a['id']] <=> [(int) $b['sort_order'], (int) $b['id']];
});

// কপি করার জন্য একই কোর্সের অন্য ব্যাচ (যাদের কিছু আছে)
$otherBatches = [];
if ($hasTable) {
    $ob = $db->prepare(
        'SELECT cb.id, cb.batch_name, COUNT(cm.id) AS n
           FROM course_batches cb JOIN course_media cm ON cm.batch_id = cb.id
          WHERE cb.course_id = :c AND cb.id != :self
          GROUP BY cb.id, cb.batch_name ORDER BY cb.sort_order ASC, cb.id ASC'
    );
    $ob->execute(['c' => $courseId, 'self' => $batchId]);
    $otherBatches = $ob->fetchAll();
}

require __DIR__ . '/includes/layout-top.php';
?>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">🖼️ ছবি ও ভিডিও</h1>
        <p class="text-sm text-gray-500"><?= e($batch['course_title']) ?> · <?= e($batch['batch_name']) ?></p>
    </div>
    <a href="<?= e($backUrl) ?>" class="text-indigo-600 font-semibold text-sm">&larr; ব্যাচ তালিকায় ফিরুন</a>
</div>

<?php if (!$hasTable): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-5 text-sm">
        <strong>ডাটাবেস মাইগ্রেশন এখনো চালানো হয়নি।</strong>
        phpMyAdmin-এ <code>database/migrate-course-media.sql</code> ফাইলের SQL একবার চালিয়ে নিন —
        তারপর এই পেজ থেকে ছবি ও ভিডিও যোগ করা যাবে। (ততক্ষণ সাইটের আর কিছু ভাঙবে না।)
    </div>
<?php else: ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-5">
    <!-- ছবি যোগ -->
    <div class="bg-white rounded-2xl shadow p-5">
        <h2 class="font-bold text-gray-800 mb-3">📸 ছবি যোগ করুন</h2>
        <form method="post" action="course-media.php?batch_id=<?= $batchId ?>" enctype="multipart/form-data" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add-photos">
            <input type="hidden" name="batch_id" value="<?= $batchId ?>">
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">ছবি (একসাথে কয়েকটা বাছাই করতে পারবেন)</label>
                <input type="file" name="photos[]" accept="image/*" multiple required
                       class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm">
                <p class="text-xs text-gray-500 mt-1">একবারে সর্বোচ্চ ১০টি · প্রতিটি ৩ মেগাবাইট পর্যন্ত · অটোমেটিক ৪:৩ মাপে ও WebP-তে ছোট হয়ে যাবে</p>
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
        <form method="post" action="course-media.php?batch_id=<?= $batchId ?>" class="space-y-3">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add-video">
            <input type="hidden" name="batch_id" value="<?= $batchId ?>">
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
    <h2 class="font-bold text-gray-800 mb-1">📋 অন্য ব্যাচ থেকে কপি করুন</h2>
    <p class="text-xs text-gray-500 mb-3">
        একই কোর্সের অন্য ব্যাচের ছবি ও ভিডিও এই ব্যাচে যোগ হবে (আগেরগুলো মুছবে না)।
        ছবির ফাইল দুইবার রাখা হয় না — একই ফাইলটাই দুই ব্যাচ দেখায়, তাই জায়গা বাড়ে না।
    </p>
    <form method="post" action="course-media.php?batch_id=<?= $batchId ?>" class="flex flex-wrap items-center gap-2">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="copy-from">
        <input type="hidden" name="batch_id" value="<?= $batchId ?>">
        <select name="from_batch" required class="border border-gray-300 rounded-lg px-3 py-2 text-sm">
            <option value="">— ব্যাচ বেছে নিন —</option>
            <?php foreach ($otherBatches as $ob2): ?>
                <option value="<?= (int) $ob2['id'] ?>"><?= e($ob2['batch_name']) ?> (<?= (int) $ob2['n'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="bg-amber-100 text-amber-800 font-bold px-4 py-2 rounded-xl text-sm">কপি করুন</button>
    </form>
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <div class="px-5 pt-5 pb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 class="font-bold text-gray-800">এই ব্যাচে যা আছে (<?= count($all) ?>)</h2>
        <p class="text-xs text-gray-500">ছোট ক্রম নম্বর আগে দেখাবে</p>
    </div>

    <?php if (!$all): ?>
        <p class="px-5 pb-5 text-sm text-gray-500">এখনো কিছু যোগ করা হয়নি — উপরের ঘর দুটো থেকে ছবি বা ভিডিও যোগ করুন। কিছু না থাকলে সাইটে বোতামই দেখাবে না।</p>
    <?php else: ?>
    <form method="post" action="course-media.php?batch_id=<?= $batchId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save-order">
        <input type="hidden" name="batch_id" value="<?= $batchId ?>">
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
                               class="w-full border border-gray-300 rounded-lg px-2 py-1 text-sm" style="min-width:12rem">
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
        <div class="p-5">
            <button type="submit" class="bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm">ক্রম ও নাম সংরক্ষণ করুন</button>
        </div>
    </form>

    <?php // ডিলিট আলাদা ফর্মে — নেস্টেড ফর্ম HTML-এ চলে না, তাই JS দিয়ে এটাতে id বসিয়ে সাবমিট করা হয় ?>
    <form method="post" action="course-media.php?batch_id=<?= $batchId ?>" id="cmDeleteForm" class="hidden">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="batch_id" value="<?= $batchId ?>">
        <input type="hidden" name="id" id="cmDeleteId" value="">
    </form>
    <?php endif; ?>
</div>

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
