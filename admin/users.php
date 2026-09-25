<?php
// অভিভাবক অ্যাকাউন্ট ম্যানেজমেন্ট — signup approve/reject/block, পাসওয়ার্ড রিসেট।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$db = get_db();
$pageTitle = 'অভিভাবক অ্যাকাউন্ট';

// 🔎 ফিল্টার — registrations.php-এর `$activeFilters` + `reg_url()` প্যাটার্নের কপি:
// নতুন ফিল্টার যোগ করতে শুধু এই অ্যারেতে key যোগ করলেই সব লিংক/পেজিনেশনে অটো propagate হয়।
$activeFilters = array_filter([
    'status' => trim($_GET['status'] ?? ''),
    'q'      => trim($_GET['q'] ?? ''),
    'course' => trim($_GET['course'] ?? ''),
    'page'   => max(1, (int) ($_GET['page'] ?? 1)) > 1 ? (int) $_GET['page'] : '',
], fn($v) => $v !== '');

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

// ---------------- কোর্স ড্রপডাউনের অপশন ----------------
// 🔴 courses/course_batches জয়েন করা হয় না — `registrations.item_title` (রেজিস্ট্রেশনের সময়
// নেওয়া স্ন্যাপশট) থেকেই distinct তালিকা (course-data.php-এর মতোই), তাই কোর্স পরে রিনেম/
// ডিলিট হলেও পুরনো অভিভাবকের ফিল্টার-অপশন অক্ষত থাকে।
$courseOptions = [];
try {
    $courseOptions = array_column(
        $db->query("SELECT DISTINCT item_title FROM registrations WHERE type = 'course' AND item_title <> '' ORDER BY item_title")->fetchAll(),
        'item_title'
    );
} catch (Throwable $e) { $courseOptions = []; }

// ---------------- তালিকা (ফিল্টার + পেজিনেশন) ----------------
$where = [];
$params = [];
if (!empty($activeFilters['status'])) {
    $where[] = 'u.status = :st';
    $params['st'] = $activeFilters['status'];
}
if (!empty($activeFilters['q'])) {
    // নাম **অথবা** মোবাইল — নম্বরটাই নিশ্চিত চাবি (নামের বানান অভিভাবকভেদে আলাদা হয়)
    $where[] = '(u.full_name LIKE :q1 OR u.phone LIKE :q2)';
    $params['q1'] = '%' . $activeFilters['q'] . '%';
    $params['q2'] = '%' . $activeFilters['q'] . '%';
}
if (!empty($activeFilters['course'])) {
    // ফোন মিলিয়ে — এই সিস্টেমে মোবাইল নম্বরই অভিভাবকের পরিচয়-চাবি
    $where[] = "EXISTS (SELECT 1 FROM registrations r2 WHERE r2.phone = u.phone AND r2.type = 'course' AND r2.item_title = :course)";
    $params['course'] = $activeFilters['course'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$perPage = 25;
$page    = max(1, (int) ($activeFilters['page'] ?? 1));
$cnt = $db->prepare("SELECT COUNT(*) c FROM users u $whereSql");
$cnt->execute($params);
$matched   = (int) $cnt->fetch()['c'];
$totalPages = max(1, (int) ceil($matched / $perPage));
if ($page > $totalPages) { $page = $totalPages; }

$stmt = $db->prepare(
    "SELECT u.*, (SELECT COUNT(*) FROM registrations r WHERE r.phone = u.phone) AS reg_count
     FROM users u $whereSql ORDER BY u.created_at DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage)
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ---------------- কে কোন কোর্সে (bulk — সারিপ্রতি কোয়েরি নয়) ----------------
// 🔴 এই পেজের ফোনগুলোর জন্য **একটাই** কোয়েরি (N+1 এড়াতে); ফোন মিলিয়ে জোড়া লাগে, তাই
// রেজিস্ট্রেশন যদি অন্য নম্বরে করা থাকে সেটা এখানে দেখাবে না (ইচ্ছাকৃত, নিচে ইঙ্গিত দেওয়া আছে)।
$userCourses = $userOtherOrders = [];
$pagePhones = array_values(array_unique(array_filter(array_column($rows, 'phone'))));
if ($pagePhones) {
    try {
        $ph = implode(',', array_fill(0, count($pagePhones), '?'));
        $q = $db->prepare("SELECT phone, type, item_title, batch FROM registrations WHERE phone IN ($ph) ORDER BY created_at DESC");
        $q->execute($pagePhones);
        foreach ($q->fetchAll() as $r) {
            if ($r['type'] === 'course') {
                $key = $r['item_title'] . '|' . ($r['batch'] ?? '');
                $userCourses[$r['phone']][$key] = ['title' => $r['item_title'], 'batch' => $r['batch'] ?? ''];
            } else {
                $userOtherOrders[$r['phone']] = ($userOtherOrders[$r['phone']] ?? 0) + 1;
            }
        }
    } catch (Throwable $e) { $userCourses = $userOtherOrders = []; }
}

// ---------------- লগইন-অবস্থা (user_login_attempts থেকে, ফোন মিলিয়ে) ----------------
// 🔴 "ঢুকতে পারছি না" ফোন এলে অ্যাডমিন যেন দেখেই বুঝতে পারেন — পাসওয়ার্ড ভুল, নাকি
// approve করা হয়নি, নাকি বারবার ভুল দিয়ে সাময়িক লক হয়ে আছে।
$lastLogin = $failCount = $lockedPhones = [];
try {
    foreach ($db->query("SELECT phone, MAX(attempted_at) t FROM user_login_attempts WHERE success = 1 GROUP BY phone")->fetchAll() as $r) {
        $lastLogin[$r['phone']] = $r['t'];
    }
    foreach ($db->query("SELECT phone, COUNT(*) c FROM user_login_attempts
                         WHERE success = 0 AND attempted_at > (NOW() - INTERVAL 1 DAY) GROUP BY phone")->fetchAll() as $r) {
        $failCount[$r['phone']] = (int) $r['c'];
    }
    // রেট-লিমিটের নিয়ম হুবহু user_login_rate_limited()-এর মতোই — ৫ ভুল / ১৫ মিনিট।
    // ⚠️ সংখ্যা দুটো includes/user-auth.php-এর কনস্ট্যান্ট থেকে কপি করা (ঐ ফাইল অ্যাডমিন পেজে
    // ইচ্ছাকৃতভাবে লোড করা হয় না); ওখানে বদলালে এখানেও বদলান।
    foreach ($db->query("SELECT phone, COUNT(*) c FROM user_login_attempts
                         WHERE success = 0 AND attempted_at > (NOW() - INTERVAL 15 MINUTE)
                         GROUP BY phone HAVING c >= 5")->fetchAll() as $r) {
        $lockedPhones[$r['phone']] = true;
    }
} catch (Throwable $e) {
    $lastLogin = $failCount = $lockedPhones = [];
}

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

<?php // 🔴 স্ট্যাটাস পিল **ফর্মের বাইরে** — ওগুলো সাধারণ লিংক (users_url() দিয়ে বাকি ফিল্টার
      // বয়ে নিয়ে যায়); ফর্মের ভেতরে <a> রাখলে সাবমিটে সার্চ/কোর্স হারিয়ে যেত। ?>
<div class="bg-white rounded-2xl shadow p-4 mb-3 flex flex-wrap gap-2">
    <?php $pills = ['' => 'সব', 'pending' => 'অপেক্ষমাণ', 'approved' => 'অনুমোদিত', 'blocked' => 'ব্লক', 'rejected' => 'বাতিল']; $cur = $activeFilters['status'] ?? '';
    foreach ($pills as $sv => $sl): ?>
        <a href="<?= e(users_url(['status' => $sv ?: null, 'page' => null])) ?>" class="px-4 py-2 rounded-full text-sm font-semibold <?= $cur === $sv ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>"><?= e($sl) ?></a>
    <?php endforeach; ?>
</div>

<form method="get" action="users.php" id="usersFilterForm" class="bg-white rounded-2xl shadow p-4 mb-5">
    <?php // স্ট্যাটাস hidden-এ বয়ে নেওয়া হয়; page ইচ্ছাকৃতভাবে নয় — ফিল্টার বদলালে ১ নম্বর পাতায় ফিরবে ?>
    <?php if (!empty($activeFilters['status'])): ?><input type="hidden" name="status" value="<?= e($activeFilters['status']) ?>"><?php endif; ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="sm:col-span-2">
            <label class="block text-xs font-semibold text-gray-500 mb-1">নাম বা মোবাইল নম্বর</label>
            <input type="text" name="q" id="usersSearchInput" value="<?= e($activeFilters['q'] ?? '') ?>" placeholder="টাইপ করা মাত্র ফলাফল আপডেট হবে..." class="w-full border rounded-xl px-3 py-2.5 text-sm">
        </div>
        <div>
            <label class="block text-xs font-semibold text-gray-500 mb-1">কোন কোর্সের অভিভাবক</label>
            <select name="course" onchange="document.getElementById('usersFilterForm').submit()" class="w-full border rounded-xl px-3 py-2.5 text-sm">
                <option value="">সব কোর্স</option>
                <?php foreach ($courseOptions as $co): ?>
                    <option value="<?= e($co) ?>" <?= ($activeFilters['course'] ?? '') === $co ? 'selected' : '' ?>><?= e($co) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if (!empty($activeFilters['q']) || !empty($activeFilters['course'])): ?>
        <p class="text-xs text-gray-500 mt-2">
            <strong><?= (int) $matched ?></strong> জন পাওয়া গেছে ·
            <a href="<?= e(users_url(['q' => null, 'course' => null, 'page' => null])) ?>" class="text-indigo-600 font-semibold">ফিল্টার মুছুন</a>
        </p>
    <?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
            <th class="py-3 px-4">নাম</th><th class="py-3 px-4">মোবাইল</th><th class="py-3 px-4">রেজিস্ট্রেশন</th>
            <th class="py-3 px-4">স্ট্যাটাস</th><th class="py-3 px-4">শেষ লগইন</th><th class="py-3 px-4">তারিখ</th><th class="py-3 px-4">অ্যাকশন</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="7" class="py-8 px-4 text-center text-gray-400">কোনো অ্যাকাউন্ট নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $u): [$sl, $sc] = $statusMeta[$u['status']] ?? [$u['status'], 'bg-gray-100 text-gray-600']; ?>
            <tr class="border-b last:border-0 hover:bg-gray-50 <?= $u['status'] === 'approved' ? '' : 'opacity-80' ?>">
                <td class="py-2.5 px-4 font-semibold text-gray-900">
                    <?= e($u['full_name'] ?: '-') ?><?= $u['google_id'] ? ' <span class="text-xs text-blue-500">(Google)</span>' : '' ?>
                    <?php // 📚 কোন কোন কোর্সে — নামের নিচে চিপ (ফোন মিলিয়ে, bulk কোয়েরি থেকে) ?>
                    <?php $ucs = $userCourses[$u['phone']] ?? []; $others = (int) ($userOtherOrders[$u['phone']] ?? 0); ?>
                    <?php if ($ucs || $others): ?>
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            <?php foreach ($ucs as $c): ?>
                                <?php // 🔴 চিপে ক্লিকে সার্চও মোছা হয় — নাহলে "এই কোর্সের সবাই" চেয়ে শুধু খোঁজা নামটাই আসত ?>
                                <a href="<?= e(users_url(['course' => $c['title'], 'q' => null, 'page' => null])) ?>" class="text-xs font-semibold bg-indigo-50 text-indigo-700 px-2 py-0.5 rounded-lg" title="এই কোর্সের সব অভিভাবক দেখুন">
                                    <?= e($c['title']) ?><?= $c['batch'] !== '' ? ' <span class="font-normal">· ' . e($c['batch']) . '</span>' : '' ?>
                                </a>
                            <?php endforeach; ?>
                            <?php if ($others): ?>
                                <span class="text-xs font-semibold bg-gray-100 text-gray-500 px-2 py-0.5 rounded-lg">+ <?= $others ?>টি অন্য অর্ডার</span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($u['phone']) ?></td>
                <td class="py-2.5 px-4">
                    <?php if ((int) $u['reg_count'] > 0): ?>
                        <?php // সংখ্যাটা ক্লিকে অর্ডার তালিকায় ঐ নম্বরে ফিল্টার হয়ে খোলে ?>
                        <a href="registrations.php?q=<?= urlencode($u['phone']) ?>" class="text-indigo-600 font-semibold" title="এই নম্বরের সব অর্ডার দেখুন"><?= (int) $u['reg_count'] ?> টি</a>
                    <?php else: ?>
                        <span class="text-gray-400">0 টি</span>
                        <span class="block text-xs text-amber-600" title="রেজিস্ট্রেশন হয়তো অন্য মোবাইল নম্বরে করা">🔍 এই নম্বরে কোনো অর্ডার নেই</span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4"><span class="text-xs font-bold px-2.5 py-1 rounded-lg <?= $sc ?>"><?= e($sl) ?></span></td>
                <td class="py-2.5 px-4 text-xs whitespace-nowrap">
                    <?php $lg = $lastLogin[$u['phone']] ?? null; $fc = $failCount[$u['phone']] ?? 0; ?>
                    <?php if ($lg): ?>
                        <span class="text-gray-600" title="<?= e($lg) ?>"><?= e(date('d M, H:i', strtotime($lg))) ?></span>
                    <?php else: ?>
                        <span class="text-gray-300">কখনো ঢোকেননি</span>
                    <?php endif; ?>
                    <?php if (!empty($lockedPhones[$u['phone']])): ?>
                        <span class="block text-red-600 font-bold">🔒 সাময়িক লক (১৫ মিনিট)</span>
                    <?php elseif ($fc): ?>
                        <span class="block text-amber-600 font-semibold"><?= (int) $fc ?> বার ভুল (২৪ ঘণ্টায়)</span>
                    <?php endif; ?>
                </td>
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
                    <form method="post" action="account-preview.php" class="inline" target="_blank">
                        <?= csrf_field() ?><input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <button type="submit" class="text-indigo-600 font-semibold" title="এই অভিভাবক লগইন করলে কী দেখেন — নতুন ট্যাবে">👁 যেমন দেখাচ্ছে</button>
                    </form>
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

<?php if ($totalPages > 1): ?>
<div class="flex flex-wrap items-center justify-center gap-2 mt-5">
    <?php if ($page > 1): ?>
        <a href="<?= e(users_url(['page' => $page - 1 > 1 ? $page - 1 : null])) ?>" class="px-4 py-2 rounded-xl bg-white shadow text-sm font-semibold text-gray-600">← আগের</a>
    <?php endif; ?>
    <span class="px-4 py-2 text-sm text-gray-500">পাতা <?= $page ?> / <?= $totalPages ?> · মোট <?= (int) $matched ?> জন</span>
    <?php if ($page < $totalPages): ?>
        <a href="<?= e(users_url(['page' => $page + 1])) ?>" class="px-4 py-2 rounded-xl bg-white shadow text-sm font-semibold text-gray-600">পরের →</a>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// সার্চ বক্সে টাইপ করা মাত্র (থামার পর) অটো-ফিল্টার — registrations.php-এর হুবহু প্যাটার্ন
(function () {
    var box = document.getElementById('usersSearchInput');
    if (!box) { return; }
    var t;
    box.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () { document.getElementById('usersFilterForm').submit(); }, 500);
    });
})();
</script>
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
