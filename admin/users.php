<?php
// অভিভাবক অ্যাকাউন্ট ম্যানেজমেন্ট — signup approve/reject/block, পাসওয়ার্ড রিসেট।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'অভিভাবক অ্যাকাউন্ট';

$activeFilters = array_filter(['status' => trim($_GET['status'] ?? '')], fn($v) => $v !== '');

function users_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'users.php' . ($p ? '?' . http_build_query($p) : '');
}
function users_safe_return(): string
{
    $r = $_POST['return'] ?? '';
    return (is_string($r) && str_starts_with($r, 'users.php')) ? $r : 'users.php';
}

// ---------------- POST: স্ট্যাটাস পরিবর্তন ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'setstatus') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('users.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    $new = in_array($_POST['status'] ?? '', ['approved', 'rejected', 'blocked', 'pending'], true) ? $_POST['status'] : 'pending';
    if ($new === 'approved') {
        $db->prepare('UPDATE users SET status = :s, approved_at = NOW(), approved_by = :a WHERE id = :id')
           ->execute(['s' => $new, 'a' => (int) ($_SESSION['admin_id'] ?? 0), 'id' => $id]);
    } else {
        $db->prepare('UPDATE users SET status = :s WHERE id = :id')->execute(['s' => $new, 'id' => $id]);
    }
    set_flash('success', 'স্ট্যাটাস আপডেট হয়েছে।');
    redirect(users_safe_return());
}

// ---------------- POST: পাসওয়ার্ড রিসেট (র‍্যান্ডম temp) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'reset') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('users.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    $temp = substr(str_shuffle('abcdefghjkmnpqrstuvwxyz23456789'), 0, 8);
    $db->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
       ->execute(['h' => password_hash($temp, PASSWORD_DEFAULT), 'id' => $id]);
    set_flash('success', 'নতুন অস্থায়ী পাসওয়ার্ড: ' . $temp . ' — এটি অভিভাবককে জানিয়ে দিন (তিনি লগইন করে বদলে নিতে পারবেন)।');
    redirect(users_safe_return());
}

// ---------------- POST: ডিলিট ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('users.php'); }
    $db->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
    set_flash('success', 'অ্যাকাউন্ট ডিলিট করা হয়েছে।');
    redirect(users_safe_return());
}

// ---------------- পরিসংখ্যান ----------------
$total    = (int) $db->query('SELECT COUNT(*) c FROM users')->fetch()['c'];
$pending  = (int) $db->query("SELECT COUNT(*) c FROM users WHERE status = 'pending'")->fetch()['c'];
$approved = (int) $db->query("SELECT COUNT(*) c FROM users WHERE status = 'approved'")->fetch()['c'];

// ---------------- তালিকা (প্রতি ইউজারের registration কাউন্ট সহ) ----------------
$where = '';
$params = [];
if (!empty($activeFilters['status'])) { $where = 'WHERE u.status = :st'; $params['st'] = $activeFilters['status']; }
$stmt = $db->prepare(
    "SELECT u.*, (SELECT COUNT(*) FROM registrations r WHERE r.phone = u.phone) AS reg_count
     FROM users u $where ORDER BY u.created_at DESC"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$curReturn = users_url();
$statusMeta = [
    'pending'  => ['অপেক্ষমাণ', 'bg-amber-100 text-amber-700'],
    'approved' => ['অনুমোদিত', 'bg-green-100 text-green-700'],
    'rejected' => ['বাতিল', 'bg-gray-200 text-gray-600'],
    'blocked'  => ['ব্লক', 'bg-red-100 text-red-700'],
];

require __DIR__ . '/includes/layout-top.php';
?>
<div class="grid grid-cols-3 gap-3 sm:gap-4 mb-6">
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5"><div class="text-2xl sm:text-3xl font-black text-indigo-600"><?= $total ?></div><p class="text-gray-500 text-xs sm:text-sm mt-1">মোট অ্যাকাউন্ট</p></div>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5"><div class="text-2xl sm:text-3xl font-black text-amber-600"><?= $pending ?></div><p class="text-gray-500 text-xs sm:text-sm mt-1">অপেক্ষমাণ (approve বাকি)</p></div>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5"><div class="text-2xl sm:text-3xl font-black text-green-600"><?= $approved ?></div><p class="text-gray-500 text-xs sm:text-sm mt-1">অনুমোদিত</p></div>
</div>

<form method="get" id="usersFilterForm" class="bg-white rounded-2xl shadow p-4 mb-5 flex flex-wrap gap-2">
    <?php $pills = ['' => 'সব', 'pending' => 'অপেক্ষমাণ', 'approved' => 'অনুমোদিত', 'blocked' => 'ব্লক', 'rejected' => 'বাতিল']; $cur = $activeFilters['status'] ?? '';
    foreach ($pills as $sv => $sl): ?>
        <a href="<?= e(users_url(['status' => $sv ?: null])) ?>" class="px-4 py-2 rounded-full text-sm font-semibold <?= $cur === $sv ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
            <th class="py-3 px-4">নাম</th><th class="py-3 px-4">মোবাইল</th><th class="py-3 px-4">রেজিস্ট্রেশন</th>
            <th class="py-3 px-4">স্ট্যাটাস</th><th class="py-3 px-4">তারিখ</th><th class="py-3 px-4">অ্যাকশন</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="6" class="py-8 px-4 text-center text-gray-400">কোনো অ্যাকাউন্ট নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $u): [$sl, $sc] = $statusMeta[$u['status']] ?? [$u['status'], 'bg-gray-100 text-gray-600']; ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $u['status'] === 'approved' ? '' : 'opacity-80' ?>">
                <td class="py-2.5 px-4 font-semibold text-gray-900"><?= e($u['full_name'] ?: '-') ?><?= $u['google_id'] ? ' <span class="text-xs text-blue-500">(Google)</span>' : '' ?></td>
                <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($u['phone']) ?></td>
                <td class="py-2.5 px-4"><?= (int) $u['reg_count'] ?> টি</td>
                <td class="py-2.5 px-4"><span class="text-xs font-bold px-2.5 py-1 rounded-lg <?= $sc ?>"><?= e($sl) ?></span></td>
                <td class="py-2.5 px-4 text-gray-500 text-xs whitespace-nowrap"><?= e(date('Y-m-d', strtotime($u['created_at']))) ?></td>
                <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                    <?php if ($u['status'] !== 'approved'): ?>
                        <?= status_btn($u['id'], 'approved', '✓ approve', 'text-green-600', $curReturn) ?>
                    <?php endif; ?>
                    <?php if ($u['status'] === 'pending'): ?>
                        <?= status_btn($u['id'], 'rejected', 'বাতিল', 'text-gray-500', $curReturn, 'এই signup বাতিল করতে চান?') ?>
                    <?php endif; ?>
                    <?php if ($u['status'] === 'approved'): ?>
                        <?= status_btn($u['id'], 'blocked', 'ব্লক', 'text-red-600', $curReturn, 'এই অ্যাকাউন্ট ব্লক করতে চান?') ?>
                        <form method="post" action="users.php?action=reset" class="inline" onsubmit="return confirmSubmit(this, 'পাসওয়ার্ড রিসেট করে নতুন অস্থায়ী পাসওয়ার্ড তৈরি করবেন?', 'পাসওয়ার্ড রিসেট');">
                            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="return" value="<?= e($curReturn) ?>">
                            <button type="submit" class="text-indigo-600 font-semibold">🔑 রিসেট</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($u['status'] === 'blocked'): ?>
                        <?= status_btn($u['id'], 'approved', 'আনব্লক', 'text-green-600', $curReturn) ?>
                    <?php endif; ?>
                    <form method="post" action="users.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, 'এই অ্যাকাউন্টটি ডিলিট করতে চান?', 'ডিলিট নিশ্চিতকরণ');">
                        <?= csrf_field() ?><input type="hidden" name="id" value="<?= $u['id'] ?>"><input type="hidden" name="return" value="<?= e($curReturn) ?>">
                        <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
require __DIR__ . '/includes/layout-bottom.php';

// স্ট্যাটাস-বদল বাটন হেল্পার (ফর্ম) — confirm লাগলে $confirm দিন
function status_btn(int $id, string $status, string $label, string $cls, string $return, ?string $confirm = null): string
{
    $onsubmit = $confirm ? ' onsubmit="return confirmSubmit(this, \'' . e($confirm) . '\', \'নিশ্চিতকরণ\');"' : '';
    return '<form method="post" action="users.php?action=setstatus" class="inline"' . $onsubmit . '>'
        . csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="status" value="' . e($status) . '"><input type="hidden" name="return" value="' . e($return) . '">'
        . '<button type="submit" class="' . $cls . ' font-semibold">' . e($label) . '</button></form>';
}
