<?php
// পেমেন্ট মেথড ম্যানেজমেন্ট — থ্যাংক-ইউ পেজে দেখানো পেমেন্ট নাম্বার ও WhatsApp বাটন অ্যাডমিন থেকে নিয়ন্ত্রণ।
// প্রতিটা এন্ট্রির চ্যানেল (বিকাশ/নগদ/রকেট/ব্যাংক/WhatsApp), এবং "সব আইটেমে" নাকি "নির্দিষ্ট কোর্স/আইটেমে"
// দেখাবে সেটা সেট করা যায় (payment_methods টেবিল, scope_all/scope_items)।

require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'পেমেন্ট মেথড';
$action = $_GET['action'] ?? 'list';

$channels = [
    'bkash'    => 'বিকাশ',
    'nagad'    => 'নগদ',
    'rocket'   => 'রকেট',
    'bank'     => 'ব্যাংক',
    'whatsapp' => 'WhatsApp',
    'other'    => 'অন্যান্য',
];

// scope চেকবক্সের অপশন — সব সক্রিয় কোর্স-ব্যাচ + ওয়ার্কশিট + প্রোডাক্ট (টোকেন: "type:id")
function payment_scope_options(PDO $db): array
{
    $opts = [];
    foreach ($db->query("SELECT cb.id, CONCAT(c.title, ' — ', cb.batch_name) AS label FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1 ORDER BY c.title, cb.batch_name")->fetchAll() as $r) {
        $opts[] = ['token' => 'course:' . $r['id'], 'label' => 'কোর্স: ' . $r['label']];
    }
    foreach ($db->query("SELECT id, title FROM worksheets WHERE is_active = 1 ORDER BY title")->fetchAll() as $r) {
        $opts[] = ['token' => 'worksheet:' . $r['id'], 'label' => 'ওয়ার্কশিট: ' . $r['title']];
    }
    foreach ($db->query("SELECT id, title FROM products WHERE is_active = 1 ORDER BY title")->fetchAll() as $r) {
        $opts[] = ['token' => 'product:' . $r['id'], 'label' => 'প্রোডাক্ট: ' . $r['title']];
    }
    return $opts;
}

// ------------------------------------------------------------ SAVE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('payment-methods.php');
    }
    $id = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $channel = in_array($_POST['channel'] ?? '', array_keys($channels), true) ? $_POST['channel'] : 'other';
    $value = trim($_POST['value'] ?? '');
    $instruction = trim($_POST['instruction'] ?? '');
    $scopeAll = (($_POST['scope'] ?? 'all') === 'all') ? 1 : 0;
    $scopeItems = $scopeAll ? null : json_encode(array_values(array_filter((array) ($_POST['scope_items'] ?? []))), JSON_UNESCAPED_UNICODE);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    if ($value === '') {
        set_flash('error', 'নাম্বার/অ্যাকাউন্ট দিন।');
        redirect('payment-methods.php?action=form' . ($id ? '&id=' . $id : ''));
    }

    if ($id) {
        $db->prepare('UPDATE payment_methods SET channel=:ch, value=:v, instruction=:ins, scope_all=:sa, scope_items=:si, is_active=:ia, sort_order=:so WHERE id=:id')
           ->execute(['ch' => $channel, 'v' => $value, 'ins' => $instruction ?: null, 'sa' => $scopeAll, 'si' => $scopeItems, 'ia' => $isActive, 'so' => $sortOrder, 'id' => $id]);
    } else {
        if ($sortOrder === 0) {
            $sortOrder = (int) $db->query('SELECT COALESCE(MAX(sort_order),0)+1 FROM payment_methods')->fetchColumn();
        }
        $db->prepare('INSERT INTO payment_methods (channel, value, instruction, scope_all, scope_items, is_active, sort_order) VALUES (:ch,:v,:ins,:sa,:si,:ia,:so)')
           ->execute(['ch' => $channel, 'v' => $value, 'ins' => $instruction ?: null, 'sa' => $scopeAll, 'si' => $scopeItems, 'ia' => $isActive, 'so' => $sortOrder]);
    }
    set_flash('success', 'পেমেন্ট মেথড সেভ হয়েছে।');
    redirect('payment-methods.php');
}

// ------------------------------------------------------------ DELETE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('payment-methods.php');
    }
    $db->prepare('DELETE FROM payment_methods WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
    set_flash('success', 'পেমেন্ট মেথড ডিলিট করা হয়েছে।');
    redirect('payment-methods.php');
}

// ------------------------------------------------------------ FORM data
$editRow = null;
if ($action === 'form' && !empty($_GET['id'])) {
    $stmt = $db->prepare('SELECT * FROM payment_methods WHERE id = :id');
    $stmt->execute(['id' => (int) $_GET['id']]);
    $editRow = $stmt->fetch();
    if (!$editRow) {
        redirect('payment-methods.php');
    }
}
$editScopeItems = $editRow && !$editRow['scope_all'] ? (json_decode($editRow['scope_items'] ?? '[]', true) ?: []) : [];

$rows = $action === 'list' ? $db->query('SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC')->fetchAll() : [];

require __DIR__ . '/includes/layout-top.php';
?>

<?php if ($action === 'list'): ?>
    <div class="flex justify-between items-center mb-5">
        <p class="text-gray-500 text-sm">রেজিস্ট্রেশন/অর্ডার সফল হওয়ার পর থ্যাংক-ইউ পেজে এই নাম্বার/বাটনগুলো দেখাবে</p>
        <a href="payment-methods.php?action=form" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ নতুন পেমেন্ট মেথড</a>
    </div>

    <?php if (!$rows): ?>
        <div class="bg-white rounded-2xl shadow empty-state">
            <div class="empty-ic"><i data-lucide="wallet"></i></div>
            <p class="font-semibold text-gray-700 mb-1">এখনো কোনো পেমেন্ট মেথড নেই</p>
            <p class="text-sm mb-4">বিকাশ/নগদ/ব্যাংক নাম্বার বা WhatsApp বাটন যোগ করুন।</p>
            <a href="payment-methods.php?action=form" class="inline-flex items-center gap-2 bg-indigo-600 text-white font-bold px-5 py-2.5 rounded-xl text-sm"><i data-lucide="plus" class="w-4 h-4"></i> নতুন পেমেন্ট মেথড</a>
        </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">চ্যানেল</th>
                    <th class="py-3 px-4">নাম্বার/অ্যাকাউন্ট</th>
                    <th class="py-3 px-4">নির্দেশনা</th>
                    <th class="py-3 px-4">কোথায় দেখাবে</th>
                    <th class="py-3 px-4">সক্রিয়</th>
                    <th class="py-3 px-4">ক্রম</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                [$chLabel, $chColor] = payment_channel_meta($r['channel']);
                $scopeCount = $r['scope_all'] ? 0 : count(json_decode($r['scope_items'] ?? '[]', true) ?: []);
            ?>
                <tr class="border-b last:border-0 hover:bg-gray-50 <?= $r['is_active'] ? '' : 'opacity-60' ?>">
                    <td class="py-2.5 px-4"><span class="text-white text-xs font-bold px-2.5 py-1 rounded-lg" style="background:<?= $chColor ?>;"><?= e($chLabel) ?></span></td>
                    <td class="py-2.5 px-4 font-black text-gray-900"><?= e($r['value']) ?></td>
                    <td class="py-2.5 px-4 text-gray-600 max-w-[220px] truncate" title="<?= e($r['instruction'] ?? '') ?>"><?= e($r['instruction'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= $r['scope_all'] ? '<span class="text-green-600 font-semibold">সব আইটেমে</span>' : '<span class="text-indigo-600 font-semibold">' . $scopeCount . ' টি নির্দিষ্ট আইটেম</span>' ?></td>
                    <td class="py-2.5 px-4"><?= $r['is_active'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4"><?= (int) $r['sort_order'] ?></td>
                    <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                        <a href="payment-methods.php?action=form&id=<?= $r['id'] ?>" class="text-indigo-600 font-semibold">এডিট</a>
                        <form method="post" action="payment-methods.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, 'এই পেমেন্ট মেথডটি ডিলিট করতে চান?', 'ডিলিট নিশ্চিতকরণ');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $r['id'] ?>">
                            <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

<?php else: /* FORM */ ?>
    <div class="mb-5"><a href="payment-methods.php" class="text-gray-500 text-sm">← সব পেমেন্ট মেথড</a></div>
    <div class="bg-white rounded-2xl shadow p-6 max-w-2xl">
        <h3 class="text-lg font-bold text-gray-800 mb-4"><?= $editRow ? 'পেমেন্ট মেথড সম্পাদনা' : 'নতুন পেমেন্ট মেথড' ?></h3>
        <form method="post" action="payment-methods.php?action=save" class="space-y-4">
            <?= csrf_field() ?>
            <?php if ($editRow): ?><input type="hidden" name="id" value="<?= $editRow['id'] ?>"><?php endif; ?>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">চ্যানেল</label>
                <select name="channel" class="w-full border rounded-xl px-4 py-2.5">
                    <?php foreach ($channels as $ck => $cl): ?>
                        <option value="<?= $ck ?>" <?= ($editRow['channel'] ?? 'bkash') === $ck ? 'selected' : '' ?>><?= e($cl) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">WhatsApp বাছাই করলে এটা একটা WhatsApp বাটন হবে (নাম্বারসহ), নাহলে কপি-করার পেমেন্ট নাম্বার।</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">নাম্বার / অ্যাকাউন্ট *</label>
                <input type="text" name="value" required value="<?= e($editRow['value'] ?? '') ?>" placeholder="যেমন: 01625867557 অথবা ব্যাংক অ্যাকাউন্ট নম্বর" class="w-full border rounded-xl px-4 py-2.5">
                <p class="text-xs text-gray-400 mt-1">WhatsApp হলে দেশকোডসহ দিন (যেমন 8801721809925) অথবা 01... দিলেও চলবে — অটো ঠিক হয়ে যাবে।</p>
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-1">নির্দেশনা / নোট</label>
                <input type="text" name="instruction" value="<?= e($editRow['instruction'] ?? '') ?>" placeholder="যেমন: Send Money করুন (Personal, Merchant না) / Screenshot পাঠান" class="w-full border rounded-xl px-4 py-2.5">
            </div>

            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">কোথায় দেখাবে?</label>
                <?php $curScope = $editRow ? ($editRow['scope_all'] ? 'all' : 'specific') : 'all'; ?>
                <div class="space-y-2">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="scope" value="all" <?= $curScope === 'all' ? 'checked' : '' ?> onchange="document.getElementById('scopeItemsBox').classList.add('hidden')">
                        <span class="text-sm">সব কোর্স/ওয়ার্কশিট/প্রোডাক্টে দেখাবে</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="scope" value="specific" <?= $curScope === 'specific' ? 'checked' : '' ?> onchange="document.getElementById('scopeItemsBox').classList.remove('hidden')">
                        <span class="text-sm">শুধু নির্দিষ্ট আইটেমে দেখাবে (নিচে বাছাই করুন)</span>
                    </label>
                </div>
                <div id="scopeItemsBox" class="mt-3 border rounded-xl p-3 max-h-56 overflow-y-auto space-y-1.5 <?= $curScope === 'specific' ? '' : 'hidden' ?>">
                    <?php $scopeOptions = payment_scope_options($db); if (!$scopeOptions): ?>
                        <p class="text-sm text-gray-400">কোনো সক্রিয় কোর্স/ওয়ার্কশিট/প্রোডাক্ট নেই।</p>
                    <?php else: foreach ($scopeOptions as $opt): ?>
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            <input type="checkbox" name="scope_items[]" value="<?= e($opt['token']) ?>" <?= in_array($opt['token'], $editScopeItems, true) ? 'checked' : '' ?>>
                            <span><?= e($opt['label']) ?></span>
                        </label>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ক্রম নম্বর</label>
                    <input type="number" name="sort_order" value="<?= e((string) ($editRow['sort_order'] ?? 0)) ?>" class="w-full border rounded-xl px-4 py-2.5">
                </div>
                <div class="flex items-end pb-1">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" <?= ($editRow['is_active'] ?? 1) ? 'checked' : '' ?> class="w-4 h-4">
                        <span class="text-sm font-semibold text-gray-700">সক্রিয় (সাইটে দেখাবে)</span>
                    </label>
                </div>
            </div>

            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
                <a href="payment-methods.php" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-xl">বাতিল</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
