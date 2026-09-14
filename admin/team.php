<?php
// টিম / মডারেটর — মূল অ্যাডমিন মডারেটর অ্যাকাউন্ট যোগ করে ও কে কী দেখবে ঠিক করে।
// এই পেজ শুধু মূল অ্যাডমিন (super) খুলতে পারে (admin_can_page → super_only)।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pageTitle = 'টিম / মডারেটর';
$db = get_db();
$sections = admin_permission_sections();
$selfId = (int) $_SESSION['admin_id'];

// POST থেকে সেকশন=>caps[] ম্যাপ বানানো (এডিট/ডিলিট থাকলে দেখা স্বয়ংক্রিয়)
function team_clean_perms(array $sections): string
{
    $out = [];
    $raw = (array) ($_POST['perms'] ?? []);
    foreach (array_keys($sections) as $key) {
        $caps = array_values(array_intersect(['view', 'edit', 'delete'], (array) ($raw[$key] ?? [])));
        if ($caps) {
            if (!in_array('view', $caps, true)) {
                $caps[] = 'view';
            }
            $out[$key] = $caps;
        }
    }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// একটা moderator id সত্যিই moderator কিনা (admin অ্যাকাউন্ট এই পেজ থেকে বদলানো/মোছা যাবে না)
function team_is_moderator(PDO $db, int $id): bool
{
    $s = $db->prepare("SELECT role FROM admin_users WHERE id = :id");
    $s->execute(['id' => $id]);
    return $s->fetchColumn() === 'moderator';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $fullName = trim($_POST['full_name'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username === '' || $fullName === '' || $password === '') {
            set_flash('error', 'ইউজারনেম, নাম ও পাসওয়ার্ড — সব দিন।');
        } elseif (!preg_match('/^[A-Za-z0-9_.]{3,50}$/', $username)) {
            set_flash('error', 'ইউজারনেম ৩-৫০ অক্ষর, শুধু ইংরেজি অক্ষর/সংখ্যা/_/. ব্যবহার করুন।');
        } elseif (strlen($password) < 6) {
            set_flash('error', 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।');
        } else {
            $exists = $db->prepare('SELECT id FROM admin_users WHERE username = :u');
            $exists->execute(['u' => $username]);
            if ($exists->fetch()) {
                set_flash('error', 'এই ইউজারনেম আগে থেকেই আছে, অন্যটা দিন।');
            } else {
                $db->prepare(
                    'INSERT INTO admin_users (username, password_hash, full_name, role, permissions, is_active)
                     VALUES (:u, :p, :n, :r, :perm, 1)'
                )->execute([
                    'u' => $username,
                    'p' => password_hash($password, PASSWORD_DEFAULT),
                    'n' => $fullName,
                    'r' => 'moderator',
                    'perm' => team_clean_perms($sections),
                ]);
                set_flash('success', 'মডারেটর "' . $fullName . '" যোগ হয়েছে।');
            }
        }
        redirect('team.php');
    }

    $id = (int) ($_POST['id'] ?? 0);
    // নিচের সব অ্যাকশন শুধু moderator-এর উপর (নিজের/মূল-অ্যাডমিন অ্যাকাউন্টে না)
    if ($id > 0 && $id !== $selfId && team_is_moderator($db, $id)) {
        if ($action === 'update') {
            $fullName = trim($_POST['full_name'] ?? '');
            $db->prepare('UPDATE admin_users SET full_name = :n, permissions = :perm WHERE id = :id AND role = "moderator"')
                ->execute(['n' => $fullName !== '' ? $fullName : 'মডারেটর', 'perm' => team_clean_perms($sections), 'id' => $id]);
            set_flash('success', 'অনুমতি সংরক্ষণ হয়েছে। (মডারেটর পরের বার লগইন করলে নতুন অনুমতি কার্যকর হবে)');
        } elseif ($action === 'toggle_active') {
            $db->prepare('UPDATE admin_users SET is_active = 1 - is_active WHERE id = :id AND role = "moderator"')
                ->execute(['id' => $id]);
            set_flash('success', 'অ্যাকাউন্টের অবস্থা পরিবর্তন হয়েছে।');
        } elseif ($action === 'reset_password') {
            $np = $_POST['new_password'] ?? '';
            if (strlen($np) < 6) {
                set_flash('error', 'নতুন পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।');
            } else {
                $db->prepare('UPDATE admin_users SET password_hash = :p WHERE id = :id AND role = "moderator"')
                    ->execute(['p' => password_hash($np, PASSWORD_DEFAULT), 'id' => $id]);
                set_flash('success', 'পাসওয়ার্ড রিসেট হয়েছে।');
            }
        } elseif ($action === 'delete') {
            // ফিঙ্গারপ্রিন্ট credential FK ON DELETE CASCADE দিয়ে অটো মোছে
            $db->prepare('DELETE FROM admin_users WHERE id = :id AND role = "moderator"')->execute(['id' => $id]);
            set_flash('success', 'মডারেটর মুছে ফেলা হয়েছে।');
        }
    } else {
        set_flash('error', 'এই অ্যাকাউন্টে এই পরিবর্তন করা যাবে না।');
    }
    redirect('team.php');
}

$admins = $db->query("SELECT * FROM admin_users WHERE role = 'admin' ORDER BY id")->fetchAll();
$mods   = $db->query("SELECT * FROM admin_users WHERE role = 'moderator' ORDER BY full_name")->fetchAll();

require __DIR__ . '/includes/layout-top.php';

// প্রতি সেকশনে দেখা/এডিট/ডিলিট চেকবক্স-সারি রেন্ডার (add ও edit দুই ফর্মে রিইউজ)
$capLabels = admin_capabilities();
$renderCapRow = function (string $key, string $label, array $current) use ($capLabels): string {
    $h = '<div class="p-3 rounded-xl border border-gray-200">';
    $h .= '<div class="text-sm font-semibold text-gray-800 mb-2">' . e($label) . '</div>';
    $h .= '<div class="flex flex-wrap gap-x-4 gap-y-1">';
    foreach ($capLabels as $cap => $clabel) {
        $checked = in_array($cap, $current, true) ? 'checked' : '';
        $h .= '<label class="inline-flex items-center gap-1.5 text-sm cursor-pointer">'
            . '<input type="checkbox" name="perms[' . e($key) . '][]" value="' . e($cap) . '" ' . $checked . '> ' . e($clabel) . '</label>';
    }
    $h .= '</div></div>';
    return $h;
};

// পুরো অনুমতি-গ্রিড (কনটেন্ট গ্রুপ + অন্যান্য) + সম্পূর্ণ-অ্যাক্সেস/সব-বাদ বোতাম — add ও edit দুই ফর্মে
$renderCapGrid = function (array $currentMap) use ($sections, $renderCapRow): string {
    $content = [];
    $others = [];
    foreach ($sections as $key => $label) {
        if (admin_is_content_section($key)) {
            $content[$key] = $label;
        } else {
            $others[$key] = $label;
        }
    }
    $section = function (string $title, array $items) use ($renderCapRow, $currentMap): string {
        if (!$items) {
            return '';
        }
        $h = '<p class="text-xs font-bold text-gray-400 mt-2 mb-1" style="letter-spacing:.05em;text-transform:uppercase">' . e($title) . '</p>';
        $h .= '<div class="grid grid-cols-1 sm:grid-cols-2 gap-2">';
        foreach ($items as $k => $l) {
            $h .= $renderCapRow($k, $l, $currentMap[$k] ?? []);
        }
        $h .= '</div>';
        return $h;
    };
    return '<div class="perm-grid space-y-1">'
        . '<div class="flex flex-wrap gap-2 mb-2">'
        . '<button type="button" class="perm-all text-xs font-bold text-green-700 bg-green-50 border border-green-200 px-3 py-1.5 rounded-lg">✓ সম্পূর্ণ অ্যাক্সেস দিন</button>'
        . '<button type="button" class="perm-none text-xs font-bold text-gray-600 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-lg">সব বাদ</button>'
        . '</div>'
        . $section('কনটেন্ট (প্রতিটা আলাদা)', $content)
        . $section('অন্যান্য অংশ', $others)
        . '</div>';
};
?>
<div class="max-w-3xl space-y-6">

    <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5 text-sm text-gray-700 leading-relaxed">
        <p class="font-bold text-indigo-800 mb-1">মডারেটর/স্টাফ অ্যাকাউন্ট</p>
        মডারেটর নিজের ইউজারনেম/পাসওয়ার্ড দিয়ে <b>একই লগইন পেজে</b> ঢুকবে, তবে শুধু আপনি যে অংশে অনুমতি দেবেন সেটুকুই দেখবে ও করতে পারবে।
        <b>আপনি (মূল অ্যাডমিন) সবসময় সব পান।</b> আয়-ব্যয়, ব্যাকআপ, টিম-ম্যানেজমেন্ট মডারেটরকে না দিলে সে ছুঁতেও পারবে না।
    </div>

    <!-- মূল অ্যাডমিন তালিকা (read-only) -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-3">মূল অ্যাডমিন</h3>
        <?php foreach ($admins as $a): ?>
            <div class="flex items-center gap-3 py-2 border-b last:border-0">
                <span class="avatar avatar-sm"><?= e(mb_substr($a['full_name'], 0, 1)) ?></span>
                <div class="min-w-0">
                    <div class="font-semibold text-gray-800"><?= e($a['full_name']) ?> <span class="text-xs font-bold text-indigo-700 bg-indigo-100 px-2 py-0.5 rounded-full ml-1">সব অ্যাক্সেস</span></div>
                    <div class="text-xs text-gray-500">@<?= e($a['username']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- নতুন মডারেটর যোগ -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-4">নতুন মডারেটর যোগ করুন</h3>
        <form method="post" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">পুরো নাম</label>
                    <input type="text" name="full_name" required class="w-full border rounded-xl px-3 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">ইউজারনেম (ইংরেজি)</label>
                    <input type="text" name="username" required autocomplete="off" class="w-full border rounded-xl px-3 py-2.5">
                </div>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1">পাসওয়ার্ড</label>
                    <div class="pw-wrap" style="position:relative">
                        <input type="password" name="password" required minlength="6" autocomplete="new-password" class="w-full border rounded-xl px-3 py-2.5" style="padding-right:2.6rem">
                        <button type="button" class="pw-eye" tabindex="-1" aria-label="পাসওয়ার্ড দেখান" style="position:absolute;top:0;bottom:0;right:0;padding:0 .7rem;color:#9ca3af"><i data-lucide="eye" class="w-4 h-4"></i></button>
                    </div>
                </div>
            </div>
            <div>
                <label class="block text-sm font-semibold text-gray-700 mb-2">কোন কোন অংশে কী কী করতে পারবে? (দেখা / এডিট / ডিলিট আলাদা)</label>
                <?= $renderCapGrid([]) ?>
            </div>
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">মডারেটর যোগ করুন</button>
        </form>
    </div>

    <!-- বিদ্যমান মডারেটর তালিকা -->
    <div>
        <h3 class="font-bold text-gray-800 mb-3">মডারেটর তালিকা (<?= count($mods) ?>)</h3>
        <?php if (!$mods): ?>
            <div class="bg-white rounded-2xl shadow p-6 text-center text-gray-500 text-sm">এখনো কোনো মডারেটর যোগ করা হয়নি।</div>
        <?php else: ?>
            <div class="space-y-4">
            <?php foreach ($mods as $m): $mCaps = admin_normalize_permissions(json_decode($m['permissions'] ?? '[]', true)); ?>
                <div class="bg-white rounded-2xl shadow p-5 <?= $m['is_active'] ? '' : 'opacity-60' ?>">
                    <div class="flex items-center gap-3 mb-4">
                        <span class="avatar avatar-sm"><?= e(mb_substr($m['full_name'], 0, 1)) ?></span>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-gray-800"><?= e($m['full_name']) ?>
                                <?php if (!$m['is_active']): ?><span class="text-xs font-bold text-red-700 bg-red-100 px-2 py-0.5 rounded-full ml-1">নিষ্ক্রিয়</span><?php endif; ?>
                            </div>
                            <div class="text-xs text-gray-500">@<?= e($m['username']) ?></div>
                        </div>
                    </div>

                    <!-- অনুমতি সম্পাদনা -->
                    <form method="post" class="space-y-3">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                        <div>
                            <label class="block text-xs font-semibold text-gray-500 mb-1">পুরো নাম</label>
                            <input type="text" name="full_name" value="<?= e($m['full_name']) ?>" class="w-full border rounded-xl px-3 py-2">
                        </div>
                        <?= $renderCapGrid($mCaps) ?>
                        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2 rounded-xl text-sm">অনুমতি সংরক্ষণ</button>
                    </form>

                    <!-- ছোট অ্যাকশন: পাসওয়ার্ড রিসেট / সক্রিয়-নিষ্ক্রিয় / মুছুন -->
                    <div class="border-t mt-4 pt-4 flex flex-wrap items-center gap-2">
                        <form method="post" class="flex items-center gap-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <span class="pw-wrap" style="position:relative;display:inline-block">
                                <input type="password" name="new_password" placeholder="নতুন পাসওয়ার্ড" minlength="6" class="border rounded-lg px-3 py-1.5 text-sm" style="width:11rem;padding-right:2.2rem">
                                <button type="button" class="pw-eye" tabindex="-1" aria-label="দেখান" style="position:absolute;top:0;bottom:0;right:0;padding:0 .5rem;color:#9ca3af"><i data-lucide="eye" class="w-4 h-4"></i></button>
                            </span>
                            <button type="submit" class="text-indigo-700 hover:text-indigo-900 font-semibold text-sm">রিসেট</button>
                        </form>
                        <span class="text-gray-300">|</span>
                        <form method="post" onsubmit="return confirmSubmit(this, '<?= $m['is_active'] ? 'এই মডারেটরকে নিষ্ক্রিয় করবেন? সে আর লগইন করতে পারবে না।' : 'এই মডারেটরকে আবার সক্রিয় করবেন?' ?>', 'নিশ্চিতকরণ')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="toggle_active">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="<?= $m['is_active'] ? 'text-amber-700 hover:text-amber-900' : 'text-green-700 hover:text-green-900' ?> font-semibold text-sm"><?= $m['is_active'] ? 'নিষ্ক্রিয় করুন' : 'সক্রিয় করুন' ?></button>
                        </form>
                        <span class="text-gray-300">|</span>
                        <form method="post" onsubmit="return confirmSubmit(this, 'এই মডারেটর অ্যাকাউন্ট চিরতরে মুছে ফেলবেন?', 'মডারেটর মুছুন')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                            <button type="submit" class="text-red-600 hover:text-red-800 font-semibold text-sm">মুছুন</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
document.addEventListener('click', function (e) {
    var eye = e.target.closest ? e.target.closest('.pw-eye') : null;
    if (eye) {
        var w = eye.closest('.pw-wrap'); var i = w && w.querySelector('input');
        if (i) { i.type = (i.type === 'password') ? 'text' : 'password';
            eye.innerHTML = '<i data-lucide="' + (i.type === 'password' ? 'eye' : 'eye-off') + '" class="w-4 h-4"></i>';
            if (window.lucide) lucide.createIcons(); }
        return;
    }
    var all = e.target.closest ? e.target.closest('.perm-all') : null;
    if (all) { all.closest('.perm-grid').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; }); return; }
    var none = e.target.closest ? e.target.closest('.perm-none') : null;
    if (none) { none.closest('.perm-grid').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; }); return; }
});
</script>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
