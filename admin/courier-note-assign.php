<?php
// একটা রেজিস্ট্রেশনে কুরিয়ার নোট (রঙিন) যোগ/মোছা (COURIER-REDESIGN-PLAN.md ধাপ ৪b) — self-contained, additive।
// নোটের লজিক admin/includes/courier-notes.php-এ শেয়ার্ড (registrations.php ও courier-prepare.php-ও ব্যবহার করে)।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/courier-notes.php';
admin_require_login();

$db = get_db();
$pageTitle = 'কুরিয়ার নোট';
$rid = (int) ($_GET['registration_id'] ?? $_POST['registration_id'] ?? 0);
$return = $_GET['return'] ?? $_POST['return'] ?? '';
// শুধু নির্দিষ্ট অ্যাডমিন পেজে ফেরা যাবে (open-redirect ঠেকাতে)
$safeReturn = (is_string($return) && preg_match('#^(courier-(prepare|tracking)|registrations)\.php#', $return))
    ? $return
    : ('courier-note-assign.php?registration_id=' . $rid);

$colorOptions = courier_note_colors();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('courier-note-assign.php?registration_id=' . $rid); }
    $act = $_POST['act'] ?? '';
    if ($act === 'add' && $rid) {
        if (add_registration_note($db, $rid, (int) ($_POST['note_type_id'] ?? 0), $_POST['custom_text'] ?? '', $_POST['color'] ?? 'amber')) {
            set_flash('success', 'নোট যোগ হয়েছে।');
        }
    } elseif ($act === 'del') {
        delete_registration_note($db, $rid, (int) ($_POST['note_id'] ?? 0));
        set_flash('success', 'নোট মোছা হয়েছে।');
    }
    redirect('courier-note-assign.php?registration_id=' . $rid . ($return ? '&return=' . urlencode($return) : ''));
}

$regStmt = $db->prepare('SELECT customer_name, item_title FROM registrations WHERE id = :id');
$regStmt->execute(['id' => $rid]);
$reg = $regStmt->fetch();
$notes = $reg ? fetch_one_registration_notes($db, $rid) : [];
$types = $reg ? fetch_courier_note_types($db) : [];

require __DIR__ . '/includes/layout-top.php';
?>
<div class="max-w-lg">
    <?php if ($return): ?><a href="<?= e($safeReturn) ?>" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm mb-3 py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> ফিরে যান</a><?php endif; ?>
    <?php if (!$reg): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="alert-circle" class="w-8 h-8"></i></div>রেজিস্ট্রেশন পাওয়া যায়নি।</div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow p-5">
            <h3 class="font-bold text-gray-800"><?= e($reg['customer_name']) ?></h3>
            <p class="text-xs text-gray-500 mb-4"><?= e($reg['item_title']) ?> — কুরিয়ার নোট (কালেকশন ঠিক করতে সাহায্য করে)</p>

            <div class="flex flex-wrap gap-2 mb-4">
                <?php if (!$notes): ?><span class="text-sm text-gray-400">এখনো কোনো নোট নেই।</span><?php endif; ?>
                <?php render_note_chips($notes, 'courier-note-assign.php', $rid, $return); ?>
            </div>

            <form method="post" class="border-t pt-4 space-y-3">
                <?= csrf_field() ?>
                <input type="hidden" name="registration_id" value="<?= $rid ?>">
                <input type="hidden" name="act" value="add">
                <?php if ($return): ?><input type="hidden" name="return" value="<?= e($return) ?>"><?php endif; ?>
                <?php if ($types): ?>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">তৈরি নোট থেকে বাছুন</label>
                    <select name="note_type_id" class="w-full border rounded-xl px-4 py-2.5">
                        <option value="0">— নিচে কাস্টম লিখুন —</option>
                        <?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['label']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div><label class="block text-sm font-semibold text-gray-700 mb-1">অথবা কাস্টম নোট</label><input type="text" name="custom_text" placeholder="যেমন: আগের বকেয়া ২০" class="w-full border rounded-xl px-4 py-2.5"></div>
                    <div><label class="block text-sm font-semibold text-gray-700 mb-1">রঙ</label><select name="color" class="w-full border rounded-xl px-4 py-2.5"><?php foreach ($colorOptions as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?></select></div>
                </div>
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-3 rounded-xl text-sm w-full sm:w-auto"><i data-lucide="plus" class="w-4 h-4 inline"></i> নোট যোগ করুন</button>
                <?php if (!$types): ?><p class="text-xs text-gray-400">টিপ: অ্যাডমিন → "কুরিয়ার নোট-টাইপ" থেকে বারবার ব্যবহারযোগ্য নোট বানিয়ে রাখতে পারেন।</p><?php endif; ?>
            </form>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
