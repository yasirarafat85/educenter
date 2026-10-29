<?php
// 📨 যোগাযোগ বার্তা (Get in touch) — অ্যাডমিন ইনবক্স (2026-10-29)
// পাবলিক contact.php ফর্ম থেকে আসা বার্তা। 🔴 এখান থেকে কিছু অটো বদলায় না —
// অ্যাডমিন পড়ে নিজে ফোন/WhatsApp-এ যোগাযোগ করেন। টাকা/খাতা/আয় কিছুই ছোঁয় না।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/../includes/contact.php';
require_once __DIR__ . '/includes/archive.php';

$db = get_db();
$pageTitle = 'যোগাযোগ বার্তা';

$activeFilters = array_filter([
    'status' => trim($_GET['status'] ?? ''),
    'topic'  => trim($_GET['topic'] ?? ''),
    'q'      => trim($_GET['q'] ?? ''),
], fn($v) => $v !== '');

function cmsg_url(array $overrides = []): string
{
    global $activeFilters;
    $p = array_filter(array_merge($activeFilters, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'contact-messages.php' . ($p ? '?' . http_build_query($p) : '');
}
function cmsg_safe_return(): string
{
    $r = $_POST['return'] ?? '';
    $r = is_string($r) ? preg_replace('/[\x00-\x1f]/', '', $r) : '';
    return ($r !== '' && str_starts_with($r, 'contact-messages.php')) ? $r : 'contact-messages.php';
}

// ---------------- POST: দেখা হয়েছে / আবার নতুন ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'mark') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('contact-messages.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    $new = ($_POST['status'] ?? '') === 'done' ? 'done' : 'new';
    try {
        $db->prepare('UPDATE contact_messages SET status = :s, handled_at = ' . ($new === 'done' ? 'NOW()' : 'NULL') . ' WHERE id = :id')
           ->execute(['s' => $new, 'id' => $id]);
    } catch (Throwable $e) { /* টেবিল না থাকলে নীরবে */ }
    set_flash('success', $new === 'done' ? 'দেখা হয়েছে হিসেবে চিহ্নিত।' : 'আবার নতুন হিসেবে চিহ্নিত।');
    redirect(cmsg_safe_return());
}

// ---------------- POST: ডিলিট (আর্কাইভ হয়ে) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'delete') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('contact-messages.php'); }
    $id = (int) ($_POST['id'] ?? 0);
    try {
        archive_entity($db, 'contact_messages', $id); // রিস্টোরে ফেরানো যায়
        $db->prepare('DELETE FROM contact_messages WHERE id = :id')->execute(['id' => $id]);
    } catch (Throwable $e) { /* নীরবে */ }
    set_flash('success', 'বার্তাটি মুছে ফেলা হয়েছে (আর্কাইভে ফেরানো যাবে)।');
    redirect(cmsg_safe_return());
}

// ---------------- তালিকা ----------------
$tableReady = contact_table_ready($db);
$rows = [];
$countNew = $countAll = 0;
if ($tableReady) {
    try {
        $where = [];
        $params = [];
        if (!empty($activeFilters['status'])) { $where[] = 'status = :st'; $params['st'] = $activeFilters['status']; }
        if (!empty($activeFilters['topic']))  { $where[] = 'topic = :tp';  $params['tp'] = $activeFilters['topic']; }
        if (!empty($activeFilters['q'])) {
            // 🔴 এক CONCAT_WS গাদায় এক প্লেসহোল্ডার (HY093 এড়াতে); %/_ escape ESCAPE '!'
            $q = $activeFilters['q'];
            $esc = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
            $where[] = "CONCAT_WS(' ', name, phone, email, topic, message) LIKE :q ESCAPE '!'";
            $params['q'] = '%' . $esc . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $db->prepare(
            "SELECT * FROM contact_messages $whereSql ORDER BY status = 'done', id DESC LIMIT 300"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $countAll = (int) $db->query('SELECT COUNT(*) c FROM contact_messages')->fetch()['c'];
        $countNew = (int) $db->query("SELECT COUNT(*) c FROM contact_messages WHERE status = 'new'")->fetch()['c'];
    } catch (Throwable $e) {
        $tableReady = false;
    }
}

$curReturn = cmsg_url();
require __DIR__ . '/includes/layout-top.php';
?>

<?php if (!$tableReady): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-contact-messages.sql</code> ফাইলের কোডটুকু চালান — তার আগ পর্যন্ত ভিজিটরের বার্তা জমা হবে না (ফোন/WhatsApp-এ যোগাযোগ চালু থাকবে)। 🩺 সেটিংস → সিস্টেম চেক পাতায়ও দেখা যাবে।</p>
</div>
<?php else: ?>

<div class="grid grid-cols-2 sm:grid-cols-3 gap-4 mb-6">
    <a href="<?= e(cmsg_url(['status' => 'new'])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-amber-500"><?= $countNew ?></div>
        <p class="text-gray-500 text-sm mt-1">নতুন (দেখা হয়নি)</p>
    </a>
    <a href="<?= e(cmsg_url(['status' => null])) ?>" class="bg-white rounded-2xl shadow p-5 block">
        <div class="text-3xl font-black text-indigo-600"><?= $countAll ?></div>
        <p class="text-gray-500 text-sm mt-1">মোট বার্তা</p>
    </a>
</div>

<!-- ফিল্টার (সার্ভার-সাইড; id গার্ড লিস্টে আছে বলে জেনেরিক অটো-সার্চ বক্স বসে না) -->
<form method="get" id="cmsgFilterForm" class="bg-white rounded-2xl shadow p-4 mb-6 flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-[180px]">
        <label class="block text-gray-500 text-xs font-semibold mb-1">খুঁজুন</label>
        <input type="search" name="q" value="<?= e($activeFilters['q'] ?? '') ?>" data-autosearch="cmsgFilterForm" class="list-search w-full px-3 py-2 rounded-xl border border-gray-200 text-sm" placeholder="নাম / ফোন / বার্তা...">
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">অবস্থা</label>
        <select name="status" onchange="document.getElementById('cmsgFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <option value="new" <?= ($activeFilters['status'] ?? '') === 'new' ? 'selected' : '' ?>>নতুন</option>
            <option value="done" <?= ($activeFilters['status'] ?? '') === 'done' ? 'selected' : '' ?>>দেখা হয়েছে</option>
        </select>
    </div>
    <div>
        <label class="block text-gray-500 text-xs font-semibold mb-1">বিষয়</label>
        <select name="topic" onchange="document.getElementById('cmsgFilterForm').submit()" class="px-3 py-2 rounded-xl border border-gray-200 text-sm">
            <option value="">সব</option>
            <?php foreach (contact_topics() as $t): ?>
                <option value="<?= e($t) ?>" <?= ($activeFilters['topic'] ?? '') === $t ? 'selected' : '' ?>><?= e($t) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <button type="submit" class="px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">🔍 খুঁজুন</button>
    <?php if ($activeFilters): ?>
        <a href="contact-messages.php" class="text-sm font-semibold text-gray-500 py-2">✕ ফিল্টার মুছুন</a>
    <?php endif; ?>
</form>

<div class="bg-white rounded-2xl shadow overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">কে</th>
                <th class="py-3 px-4">বিষয়</th>
                <th class="py-3 px-4">বার্তা</th>
                <th class="py-3 px-4">তারিখ</th>
                <th class="py-3 px-4">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="5" class="py-6 px-4 text-center text-gray-400">কোনো বার্তা নেই।</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $row):
            $isNew = $row['status'] === 'new';
            $wa = normalize_bd_whatsapp($row['phone']);
            [$tint, $deep] = contact_topic_color((string) $row['topic']);
        ?>
            <tr class="border-b last:border-0 <?= $isNew ? '' : 'opacity-60' ?>">
                <td class="py-2.5 px-4">
                    <p class="font-semibold text-gray-800"><?= e($row['name'] ?: '—') ?></p>
                    <a href="tel:<?= e($row['phone']) ?>" class="text-indigo-600 text-xs"><?= e($row['phone']) ?></a>
                    <?php if ($row['email']): ?><br><a href="mailto:<?= e($row['email']) ?>" class="text-gray-500 text-xs break-all"><?= e($row['email']) ?></a><?php endif; ?>
                </td>
                <td class="py-2.5 px-4 whitespace-nowrap">
                    <?php if ($row['topic']): ?>
                        <span class="text-xs font-semibold px-2 py-0.5 rounded-lg" style="background:<?= e($tint) ?>;color:<?= e($deep) ?>;"><?= e($row['topic']) ?></span>
                    <?php else: ?>
                        <span class="text-gray-400 text-xs">—</span>
                    <?php endif; ?>
                </td>
                <td class="py-2.5 px-4 text-gray-700 break-words" style="max-width:340px;"><?= nl2br(e($row['message'])) ?></td>
                <td class="py-2.5 px-4 text-gray-500 text-xs whitespace-nowrap"><?= e(date('d M, H:i', strtotime($row['created_at']))) ?></td>
                <td class="py-2.5 px-4">
                    <div class="flex items-center gap-2 flex-wrap">
                        <a href="tel:<?= e($row['phone']) ?>" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-blue-100 text-blue-700">📞 কল</a>
                        <?php if ($wa): ?>
                            <a href="https://wa.me/<?= e($wa) ?>" target="_blank" rel="noopener noreferrer" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-green-100 text-green-700">💬 WhatsApp</a>
                        <?php endif; ?>
                        <form method="post" action="<?= e(cmsg_url(['action' => 'mark'])) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="status" value="<?= $isNew ? 'done' : 'new' ?>">
                            <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                            <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg <?= $isNew ? 'bg-gray-100 text-gray-700' : 'bg-gray-100 text-gray-500' ?>"><?= $isNew ? '✓ দেখা হয়েছে' : '↩ আবার নতুন' ?></button>
                        </form>
                        <form method="post" action="<?= e(cmsg_url(['action' => 'delete'])) ?>" class="inline" onsubmit="return confirmSubmit(this, 'এই বার্তাটি মুছে ফেলবেন? (আর্কাইভ থেকে ফেরানো যাবে)');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="return" value="<?= e($curReturn) ?>">
                            <button type="submit" class="text-xs font-semibold text-red-600">ডিলিট</button>
                        </form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
