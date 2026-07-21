<?php
// আর্কাইভ পেজ — ডিলিট করা কনটেন্ট (archived_items) দেখা, রিস্টোর, বা চিরতরে ডিলিট।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/archive.php';
admin_require_login();

$db = get_db();
$pageTitle = 'আর্কাইভ';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('archive.php');
    }
    $act = $_POST['act'] ?? '';
    $aid = (int) ($_POST['id'] ?? 0);

    if ($act === 'restore') {
        try {
            if (restore_archived($db, $aid)) {
                set_flash('success', 'সফলভাবে ফিরিয়ে আনা হয়েছে।');
            } else {
                set_flash('error', 'আইটেমটি আর্কাইভে পাওয়া যায়নি।');
            }
        } catch (Throwable $e) {
            // সাধারণত UNIQUE কনফ্লিক্ট (মাঝে একই নাম/স্লাগে নতুন আইটেম তৈরি হয়েছে)
            set_flash('error', 'ফিরিয়ে আনা যায়নি — সম্ভবত একই নাম/স্লাগে ইতিমধ্যে একটা আইটেম আছে। সেটির নাম বদলে আবার চেষ্টা করুন।');
        }
    } elseif ($act === 'purge') {
        purge_archived($db, $aid);
        set_flash('success', 'চিরতরে মুছে ফেলা হয়েছে।');
    }
    redirect('archive.php');
}

$rows = $db->query('SELECT * FROM archived_items ORDER BY deleted_at DESC, id DESC')->fetchAll();

require __DIR__ . '/includes/layout-top.php';
?>

<div class="max-w-4xl">
    <p class="text-sm text-gray-500 mb-5">এখানে ডিলিট করা কনটেন্ট (কোর্স/ব্যাচ/প্রোডাক্ট/ওয়ার্কশিট ইত্যাদি) জমা থাকে। <b>রিস্টোর</b> করলে হুবহু — সব তথ্য, ছবি ও সম্পর্ক সহ — ফিরে আসে। <b>চিরতরে ডিলিট</b> করলে আর ফেরানো যাবে না।</p>

    <?php if (!$rows): ?>
        <div class="bg-white rounded-2xl shadow empty-state">
            <div class="empty-ic"><i data-lucide="archive" class="w-8 h-8"></i></div>
            আর্কাইভ খালি — এখনো কিছু ডিলিট করা হয়নি।
        </div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-gray-500 border-b bg-gray-50">
                        <th class="py-3 px-4">নাম</th>
                        <th class="py-3 px-4">ধরন</th>
                        <th class="py-3 px-4">ডিলিটের সময়</th>
                        <th class="py-3 px-4">অ্যাকশন</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($r['label']) ?></td>
                        <td class="py-2.5 px-4">
                            <span class="bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded-full text-xs font-bold"><?= e(archive_type_label($r['entity_type'])) ?></span>
                        </td>
                        <td class="py-2.5 px-4 text-gray-500 whitespace-nowrap"><?= e($r['deleted_at']) ?></td>
                        <td class="py-2.5 px-4 space-x-3 whitespace-nowrap">
                            <form method="post" class="inline" onsubmit="return confirmSubmit(this, 'এই আইটেমটি (ও এর সব তথ্য) ফিরিয়ে আনতে চান?', 'রিস্টোর নিশ্চিতকরণ');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="restore">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="text-green-600 font-semibold inline-flex items-center gap-1"><i data-lucide="rotate-ccw" class="w-4 h-4"></i> রিস্টোর</button>
                            </form>
                            <form method="post" class="inline" onsubmit="return confirmSubmit(this, 'চিরতরে মুছে ফেলতে চান? এটা আর কখনো ফেরানো যাবে না।', 'চিরতরে ডিলিট');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="act" value="purge">
                                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                                <button type="submit" class="text-red-600 font-semibold">চিরতরে ডিলিট</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
