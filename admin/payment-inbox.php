<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📥 পেমেন্ট ইনবক্স — ফোন থেকে আসা SMS ও অভিভাবকের দাবি  (২০২৬-১০-০৬, ধাপ ১)
 *
 * তিনটা জিনিস এক পাতায়:
 *   ১. 📶 হার্টবিট — শেষ কখন SMS এসেছে (ফোন বন্ধ/নেট নেই = একক ব্যর্থতার বিন্দু)
 *   ২. 💰 অদাবিকৃত টাকা — SMS এসেছে, কেউ এখনো দাবি করেননি
 *   ৩. 📝 অমীমাংসিত দাবি — অভিভাবক TrxID দিয়েছেন, এখনো মেলেনি (ধাপ ২-এ তৈরি হবে)
 *
 * 🔴🔴 এই পাতা টাকার বইয়ে **কিছুই লেখে না** — `registration_payments`/`income`
 *      দুটোই অস্পৃশ্য। টাকা খাতায় বসানোর বোতাম ধাপ ৩-এ আসবে।
 * 🔴 ধাপ ১-এ কোনো দাবি তৈরি হয় না (পাবলিক ফর্ম এখনো নেই), তাই ৩ নম্বর ঘরটা
 *    এখন খালিই থাকবে — গঠনটা আগেই বসানো হলো যাতে ধাপ ২-এ শুধু ফর্মটা লাগে।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment-sms.php';
admin_require_login();

$db = get_db();
$pageTitle = 'পেমেন্ট ইনবক্স';
$ready = psms_ready($db);

// 🔴 গোপন চাবি শুধু মূল অ্যাডমিন দেখেন — চাবিটা জানলে যেকেউ SMS ঢোকাতে পারে
$canKey = admin_is_super();

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_GET['action'] ?? '';
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('payment-inbox.php');
    }

    // গোপন চাবি তৈরি / বদল / বন্ধ
    if ($act === 'key') {
        if (!$canKey) {
            set_flash('error', 'গোপন চাবি শুধু মূল অ্যাডমিন বদলাতে পারেন।');
            redirect('payment-inbox.php');
        }
        $mode = $_POST['mode'] ?? 'new';
        if ($mode === 'off') {
            update_setting('sms_in_secret', '');
            set_flash('success', 'চাবি বন্ধ করা হলো — এখন আর কোনো SMS গ্রহণ করা হবে না।');
        } else {
            update_setting('sms_in_secret', bin2hex(random_bytes(16)));
            set_flash('success', 'নতুন চাবি তৈরি হয়েছে। 🔴 ফোনের MacroDroid-এ নতুন চাবিটা বসাতে ভুলবেন না — নাহলে SMS আসা বন্ধ হয়ে যাবে।');
        }
        redirect('payment-inbox.php');
    }

    // একটা আসা SMS মুছে ফেলা (স্প্যাম/ভুল করে ঢোকা বার্তা)
    if ($act === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // 🔴 দাবির সাথে জোড়া লেগে যাওয়া SMS মোছা যাবে না — ওটা টাকার প্রমাণ
            $db->prepare('DELETE FROM payment_sms WHERE id = :id AND claim_id IS NULL')->execute(['id' => $id]);
            set_flash('success', 'বার্তাটা মুছে ফেলা হয়েছে।');
        } catch (Throwable $e) {
            set_flash('error', 'মুছতে সমস্যা হয়েছে।');
        }
        redirect('payment-inbox.php');
    }

    redirect('payment-inbox.php');
}

// ---------------- ডেটা ----------------
$secret   = $ready ? trim(get_setting('sms_in_secret')) : '';
$lastSms  = $ready ? psms_last_received($db) : null;
$stale    = $ready ? psms_heartbeat_stale($db) : false;
$endpoint = rtrim(SITE_URL, '/') . '/sms-in.php';

$unclaimed = $unparsed = $claims = [];
$counts = ['sms' => 0, 'unclaimed' => 0, 'unparsed' => 0, 'claims' => 0, 'untrusted' => 0];

if ($ready) {
    try {
        $counts['sms'] = (int) $db->query('SELECT COUNT(*) c FROM payment_sms')->fetch()['c'];

        // 💰 অদাবিকৃত গ্রাহক-পেমেন্ট (নিজের ক্যাশ-ইন বাদ — is_customer_payment)
        $unclaimed = $db->query("SELECT * FROM payment_sms
                                 WHERE claim_id IS NULL AND parse_status = 'parsed' AND is_customer_payment = 1
                                 ORDER BY received_at DESC LIMIT 100")->fetchAll();
        $counts['unclaimed'] = (int) $db->query("SELECT COUNT(*) c FROM payment_sms
                                 WHERE claim_id IS NULL AND parse_status = 'parsed' AND is_customer_payment = 1")->fetch()['c'];

        // ⚠️ পড়া যায়নি — ছাঁচ ঠিক করে আবার পড়ানো যাবে
        $unparsed = $db->query("SELECT * FROM payment_sms
                                WHERE parse_status <> 'parsed' ORDER BY received_at DESC LIMIT 50")->fetchAll();
        $counts['unparsed'] = (int) $db->query("SELECT COUNT(*) c FROM payment_sms WHERE parse_status <> 'parsed'")->fetch()['c'];

        // 📝 অমীমাংসিত দাবি (ধাপ ২ থেকে আসবে)
        $claims = $db->query("SELECT * FROM payment_claims
                              WHERE status IN ('new', 'verified') ORDER BY created_at DESC LIMIT 100")->fetchAll();
        $counts['claims'] = count($claims);

        // 🛡️ এই তালিকায় কয়টা SMS অবিশ্বস্ত প্রেরক থেকে এসেছে
        // 🔴 গণনা PHP-তে, SQL-এ নয় — তালিকাটা settings থেকে আসে, আর অ্যাডমিন
        //    নাম যোগ/বাদ দিলে সাথে সাথেই এখানে প্রতিফলিত হওয়া দরকার।
        foreach ($unclaimed as $u) {
            if (psms_sender_status((string) ($u['sender'] ?? '')) !== 'trusted') {
                $counts['untrusted']++;
            }
        }
    } catch (Throwable $e) {
        // 🔴 পুরো ব্লক try/catch-এ — একটা কোয়েরি ব্যর্থ হলেও পাতা খোলে
    }
}

// টাকার অঙ্ক দেখানো
function pi_money($v): string
{
    return $v === null ? '—' : '৳' . number_format((float) $v, 2);
}

/* 🛡️ প্রেরকের রায় → একটা ছোট চিপ
 * 🔴 শুধু "trusted" মানেই নিশ্চিত — বাকি তিনটা অবস্থাতেই অ্যাডমিনকে নিজে যাচাই
 *    করতে হবে, কারণ ফোন নম্বর থেকে যে কেউ নকল বার্তা পাঠাতে পারে।
 */
function pi_sender_chip(?string $sender): string
{
    $st  = psms_sender_status((string) $sender);
    $raw = trim((string) $sender);
    $map = [
        'trusted' => ['✅', 'bg-green-50 text-green-800 border-green-200',   'অপারেটরের নিবন্ধিত নাম — নকল করা যায় না'],
        'number'  => ['⚠️', 'bg-red-50 text-red-800 border-red-200',        'একটা ফোন নম্বর থেকে এসেছে — যে কেউ এমন বার্তা পাঠাতে পারে, যাচাই না করে টাকা ধরবেন না'],
        'unknown' => ['❓', 'bg-amber-50 text-amber-800 border-amber-200',  'এই নামটা বিশ্বস্ত তালিকায় নেই — আসল হলে সাইট সেটিংস → "টাকার SMS — বিশ্বস্ত প্রেরক"-এ যোগ করুন'],
        'missing' => ['❓', 'bg-gray-50 text-gray-600 border-gray-200',     'ফোন প্রেরকের নামই পাঠায়নি — MacroDroid-এর Body-তে Incoming SMS number বসানো আছে কিনা দেখুন'],
    ];
    [$ic, $cls, $why] = $map[$st] ?? $map['missing'];
    $label = $raw !== '' ? $raw : 'নাম নেই';

    // ⚠️ `py-0.5` কম্পাইলড অ্যাডমিন CSS-এ নেই — তাই inline (Tailwind রিবিল্ড এড়াতে)
    return '<span class="inline-flex items-center gap-1 border rounded-full px-2 text-xs ' . $cls . '"'
        . ' style="padding-top:2px;padding-bottom:2px"'
        . ' title="' . e($why) . '">' . $ic . ' ' . e($label) . '</span>';
}

// "৩ ঘণ্টা আগে" ধরনের লেখা
function pi_ago(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - strtotime($ts);
    if ($d < 60) {
        return 'এইমাত্র';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . ' মিনিট আগে';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . ' ঘণ্টা আগে';
    }
    return intdiv($d, 86400) . ' দিন আগে';
}

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">📥 পেমেন্ট ইনবক্স</h1>
    <p class="text-sm text-gray-500 mt-1">
        ফোন থেকে আসা বিকাশ/নগদের টাকা-পাওয়ার বার্তাগুলো এখানে জমা হয়।
        <b>এই পাতা থেকে টাকার খাতায় কিছুই বসে না</b> — শুধু দেখা যায়।
    </p>
</div>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-6 text-sm">
        ⚠️ এই অংশটা এখনো চালু হয়নি — phpMyAdmin-এ একবার
        <code class="bg-white px-1 rounded">database/migrate-payment-sms.sql</code> চালাতে হবে
        (লাইভ ও লোকাল দুটোতেই)। ততক্ষণ সাইটের বাকি সব আগের মতোই চলবে।
    </div>
<?php endif; ?>

<!-- ─── সারাংশ ─── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <?php
    $cards = [
        ['💰', 'দাবি হয়নি এমন টাকা', $counts['unclaimed'], 'text-green-700'],
        ['📝', 'অমীমাংসিত দাবি',      $counts['claims'],    'text-amber-700'],
        ['⚠️', 'পড়া যায়নি',          $counts['unparsed'],  'text-red-700'],
        ['📨', 'মোট জমা SMS',         $counts['sms'],       'text-gray-700'],
    ];
    foreach ($cards as [$ic, $lb, $n, $cls]): ?>
        <div class="bg-white rounded-2xl shadow p-4 min-w-0">
            <p class="text-xs text-gray-500"><?= $ic ?> <?= e($lb) ?></p>
            <p class="text-2xl font-bold <?= $cls ?>" style="word-break:break-all"><?= (int) $n ?></p>
        </div>
    <?php endforeach; ?>
</div>

<!-- ─── ১. হার্টবিট ─── -->
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h2 class="font-bold text-gray-800 mb-3">📶 ফোনটা কাজ করছে?</h2>
    <?php if (!$ready): ?>
        <p class="text-sm text-gray-500">মাইগ্রেশন চালানোর পর এখানে দেখা যাবে।</p>
    <?php elseif ($secret === ''): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-sm">
            🔑 এখনো কোনো গোপন চাবি তৈরি হয়নি — তাই <b>কোনো SMS গ্রহণ করা হচ্ছে না</b>।
            নিচের ঘর থেকে একটা চাবি বানিয়ে ফোনের MacroDroid-এ বসান।
        </div>
    <?php elseif ($lastSms === null): ?>
        <div class="bg-blue-50 border border-blue-200 text-gray-700 rounded-lg p-3 text-sm">
            চাবি তৈরি আছে, কিন্তু এখনো একটাও SMS আসেনি। ফোনে MacroDroid বসানো হয়ে গেলে
            নিজের নম্বর থেকে ৫ টাকা পাঠিয়ে পরীক্ষা করে দেখুন।
        </div>
    <?php elseif ($stale): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
            🔴 শেষ SMS এসেছে <b><?= e(pi_ago($lastSms)) ?></b> (<?= e($lastSms) ?>) —
            <?= (int) PSMS_HEARTBEAT_HRS ?> ঘণ্টার বেশি চুপচাপ। ফোনটা চালু আছে কিনা, নেট আছে কিনা,
            আর ব্যাটারি-সেভার MacroDroid বন্ধ করে দিয়েছে কিনা দেখুন।
        </div>
    <?php else: ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 text-sm">
            ✅ শেষ SMS এসেছে <b><?= e(pi_ago($lastSms)) ?></b> (<?= e($lastSms) ?>)।
        </div>
    <?php endif; ?>
</div>

<!-- ─── ফোন সেটআপ (শুধু মূল অ্যাডমিন) ─── -->
<?php if ($canKey): ?>
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h2 class="font-bold text-gray-800 mb-1">🔑 ফোন সেটআপ (MacroDroid)</h2>
    <p class="text-xs text-gray-500 mb-3">
        একটা পুরনো অ্যান্ড্রয়েড ফোনে MacroDroid অ্যাপ বসান, তারপর নিচের তথ্য দিয়ে একটা macro বানান।
        🔴 ঐ ফোনে <b>ব্যাটারি অপটিমাইজেশন বন্ধ</b> রাখতে হবে, নাহলে অ্যান্ড্রয়েড অ্যাপটাকে ঘুম পাড়িয়ে দেয়।
    </p>

    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm space-y-2 mb-4">
        <div>
            <p class="text-xs text-gray-500 mb-1">Trigger</p>
            <p class="font-bold">SMS Received → Any Number → SMS Content: <b>Contains</b> <code>TrxID</code></p>
            <p class="text-xs text-gray-500 mt-1">
                একই macro-তে আরেকটা Trigger যোগ করুন <code>TxnID</code> দিয়ে (বিকাশ লেখে TrxID, নগদ লেখে TxnID)।
                এতে শুধু টাকার বার্তাই সার্ভারে আসে, ব্যক্তিগত SMS নয়।
            </p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Action → HTTP Request (POST)</p>
            <div class="min-w-0 flex flex-wrap items-center gap-2">
                <code class="bg-white border rounded px-2 py-1 text-xs" style="word-break:break-all"><?= e($endpoint) ?></code>
                <button type="button" class="text-indigo-600 font-bold text-xs" data-sms-copy="<?= e($endpoint) ?>">🔗 কপি</button>
            </div>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Content Body — <code>application/x-www-form-urlencoded</code></p>
            <code class="bg-white border rounded px-2 py-1 text-xs block" style="word-break:break-all">key=&lt;চাবি&gt;&amp;sender={sms_number}&amp;text={sms_message}</code>
            <p class="text-xs text-gray-500 mt-1">
                🔴 বন্ধনীর অংশ দুটো হাতে লিখবেন না — ঘরের পাশের <b>…</b> বোতাম চেপে
                <b>Incoming SMS number</b> ও <b>Incoming SMS message</b> বেছে নিন
                (MacroDroid-এর ভার্সনভেদে বন্ধনী <code>{ }</code> বা <code>[ ]</code> হতে পারে, অ্যাপ নিজে যা বসায় সেটাই ঠিক)।
                ⚠️ <b>Incoming SMS contact</b> নয় — ওটা ফোনবুকে সেভ করা নাম, bKash/NAGAD সেভ না থাকলে খালি আসে।
            </p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">গোপন চাবি</p>
            <?php if ($secret === ''): ?>
                <p class="text-sm text-amber-800">এখনো তৈরি হয়নি।</p>
            <?php else: ?>
                <div class="min-w-0 flex flex-wrap items-center gap-2">
                    <code class="bg-white border rounded px-2 py-1 text-xs" style="word-break:break-all"><?= e($secret) ?></code>
                    <button type="button" class="text-indigo-600 font-bold text-xs" data-sms-copy="<?= e($secret) ?>">🔗 কপি</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">🔴 চাবিটা কাউকে দেবেন না — এটা দিয়ে যে কেউ ভুয়া SMS ঢোকাতে পারে।</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="post" action="payment-inbox.php?action=key" class="inline"
              onsubmit="return confirmSubmit(this, '<?= $secret === '' ? 'নতুন একটা গোপন চাবি তৈরি করা হবে।' : 'নতুন চাবি তৈরি হলে পুরনোটা সাথে সাথে অচল হয়ে যাবে — ফোনের MacroDroid-এ নতুন চাবি না বসানো পর্যন্ত SMS আসা বন্ধ থাকবে।' ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="new">
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-bold" <?= $ready ? '' : 'disabled' ?>>
                <?= $secret === '' ? '🔑 চাবি তৈরি করুন' : '↻ নতুন চাবি' ?>
            </button>
        </form>
        <?php if ($secret !== ''): ?>
        <form method="post" action="payment-inbox.php?action=key" class="inline"
              onsubmit="return confirmSubmit(this, 'চাবি বন্ধ করলে ফোন থেকে আর কোনো SMS গ্রহণ করা হবে না। জমা থাকা বার্তা মুছবে না।')">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="off">
            <button type="submit" class="border border-gray-300 px-4 py-2 rounded-lg text-sm">✕ বন্ধ করুন</button>
        </form>
        <?php endif; ?>
        <a href="sms-patterns.php" class="border border-gray-300 px-4 py-2 rounded-lg text-sm">📨 SMS ছাঁচ দেখুন</a>
    </div>
</div>
<?php endif; ?>

<!-- ─── ২. অদাবিকৃত টাকা ─── -->
<div class="mb-3">
    <h2 class="font-bold text-gray-800">💰 দাবি হয়নি এমন টাকা (<?= (int) $counts['unclaimed'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        টাকা এসেছে কিন্তু কোনো অভিভাবক এখনো TrxID দিয়ে জানাননি। 🔴 এগুলো এমনিতেই কোনো অর্ডারের
        সাথে জোড়া লাগে না — সেটা ধাপ ২ ও ৩-এর কাজ।
    </p>
</div>
<?php if ($counts['untrusted'] > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-3">
    <p class="font-bold text-red-800">⚠️ এই তালিকায় <?= (int) $counts['untrusted'] ?>টি বার্তার প্রেরক বিশ্বস্ত নয়</p>
    <p class="text-xs text-red-800 mt-1">
        বিকাশ/নগদের বার্তা অপারেটরের <b>নিবন্ধিত নাম</b> (<code>bKash</code> / <code>NAGAD</code>) দিয়ে আসে —
        ঐ নামে সাধারণ মোবাইল থেকে SMS পাঠানো যায় না। কিন্তু <b>একটা ফোন নম্বর থেকে যে কেউ</b> হুবহু একই রকম
        লেখা বানিয়ে পাঠাতে পারে। লাল চিপওয়ালা বার্তাগুলো তাই <b>টাকা এসেছে ধরে নেবেন না</b> —
        বিকাশ/নগদ অ্যাপে মিলিয়ে দেখুন।
    </p>
    <p class="text-xs text-red-800 mt-1">
        নামটা আসলেই আসল কোনো সেবার হলে <a href="settings.php" class="font-bold underline">সাইট সেটিংস → "টাকার SMS — বিশ্বস্ত প্রেরক"</a>-এ হুবহু যোগ করে দিন।
    </p>
</div>
<?php endif; ?>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">টাকা</th>
                <th class="px-3 py-2">TrxID</th>
                <th class="px-3 py-2">যে নম্বর থেকে</th>
                <th class="px-3 py-2">প্রেরক</th>
                <th class="px-3 py-2">কখন পাঠানো</th>
                <th class="px-3 py-2">আমরা পেলাম</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$unclaimed): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">
                <?= $ready ? 'কোনো অদাবিকৃত টাকা নেই। ✅' : 'মাইগ্রেশন চালানোর পর দেখা যাবে।' ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($unclaimed as $s): ?>
            <tr class="border-t">
                <td class="px-3 py-2 font-bold text-green-700"><?= e(pi_money($s['amount'])) ?></td>
                <td class="px-3 py-2" style="font-family:monospace"><?= e($s['trxid_norm']) ?></td>
                <td class="px-3 py-2"><?= e($s['sender_number'] ?: '—') ?></td>
                <td class="px-3 py-2"><?= pi_sender_chip($s['sender'] ?? '') ?></td>
                <td class="px-3 py-2 text-xs text-gray-600"><?= e($s['sent_at'] ?: '—') ?></td>
                <td class="px-3 py-2 text-xs text-gray-600" title="<?= e($s['received_at']) ?>"><?= e(pi_ago($s['received_at'])) ?></td>
                <td class="px-3 py-2">
                    <?php if (admin_can('orders', 'delete')): ?>
                    <form method="post" action="payment-inbox.php?action=delete" class="inline"
                          onsubmit="return confirmSubmit(this, 'বার্তাটা মুছে ফেলবেন? টাকা সত্যিই এসে থাকলে পরে আর খুঁজে পাওয়া যাবে না।')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="text-red-600 font-bold text-xs">মুছুন</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ─── ৩. অমীমাংসিত দাবি ─── -->
<div class="mb-3">
    <h2 class="font-bold text-gray-800">📝 অমীমাংসিত দাবি (<?= (int) $counts['claims'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        অভিভাবক TrxID দিয়ে জানিয়েছেন কিন্তু SMS-এর সাথে এখনো মেলেনি।
        <b>ধাপ ২-এ পাবলিক ফর্ম চালু হলে এখানে আসতে শুরু করবে।</b>
    </p>
</div>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">কোর্স / অর্ডার</th>
                <th class="px-3 py-2">টাকা</th>
                <th class="px-3 py-2">TrxID</th>
                <th class="px-3 py-2">মাধ্যম</th>
                <th class="px-3 py-2">অবস্থা</th>
                <th class="px-3 py-2">কখন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$claims): ?>
            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">এখনো কোনো দাবি আসেনি।</td></tr>
        <?php endif; ?>
        <?php foreach ($claims as $c): ?>
            <tr class="border-t">
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <?php if (!empty($c['registration_id'])): ?>
                            <a href="registrations.php?action=view&id=<?= (int) $c['registration_id'] ?>" class="text-indigo-600 font-bold">
                                <?= e($c['item_title'] ?: 'অর্ডার #' . (int) $c['registration_id']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-gray-500"><?= e($c['item_title'] ?: '— কোনো অর্ডারের সাথে জোড়া নেই') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['batch'])): ?>
                            <div class="text-xs text-gray-500"><?= e($c['batch']) ?></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-3 py-2 font-bold"><?= e(pi_money($c['amount'])) ?></td>
                <td class="px-3 py-2" style="font-family:monospace"><?= e($c['trxid_norm']) ?></td>
                <td class="px-3 py-2"><?= e($c['channel'] ?: '—') ?></td>
                <td class="px-3 py-2">
                    <?php $sl = ['new' => '⏳ যাচাই চলছে', 'verified' => '✅ যাচাই হয়েছে — খাতায় বসানো বাকি']; ?>
                    <span class="text-xs font-bold"><?= e($sl[$c['status']] ?? $c['status']) ?></span>
                </td>
                <td class="px-3 py-2 text-xs text-gray-600"><?= e(pi_ago($c['created_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ─── ৪. পড়া যায়নি ─── -->
<?php if ($unparsed): ?>
<div class="mb-3">
    <h2 class="font-bold text-gray-800">⚠️ পড়া যায়নি (<?= (int) $counts['unparsed'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        কোনো ছাঁচে মেলেনি। 🔴 কাঁচা লেখাটা ফেলে দেওয়া হয়নি — <a href="sms-patterns.php" class="text-indigo-600 font-bold">SMS ছাঁচ</a>
        পাতায় ছাঁচটা ঠিক করে "আবার পড়ান" চাপলেই এগুলো পড়া যাবে।
        (OTP/প্রচারমূলক বার্তাও এখানে আসতে পারে — সেগুলো মুছে দিলেই হয়।)
    </p>
</div>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">প্রেরক</th>
                <th class="px-3 py-2">কাঁচা লেখা</th>
                <th class="px-3 py-2">কখন</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($unparsed as $s): ?>
            <tr class="border-t">
                <td class="px-3 py-2"><?= pi_sender_chip($s['sender'] ?? '') ?></td>
                <td class="px-3 py-2">
                    <div class="min-w-0" style="max-width:520px;word-break:break-word;font-family:monospace;font-size:12px">
                        <?= e(mb_substr((string) $s['raw_text'], 0, 300)) ?>
                    </div>
                </td>
                <td class="px-3 py-2 text-xs text-gray-600"><?= e(pi_ago($s['received_at'])) ?></td>
                <td class="px-3 py-2">
                    <?php if (admin_can('orders', 'delete')): ?>
                    <form method="post" action="payment-inbox.php?action=delete" class="inline"
                          onsubmit="return confirmSubmit(this, 'বার্তাটা মুছে ফেলবেন?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="text-red-600 font-bold text-xs">মুছুন</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// 🔴 কপি-বোতাম নিজের আলাদা ব্লকে (layout-bottom-এর বড় স্ক্রিপ্টের ভেতরে নয়)
document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-sms-copy]');
    if (!b) { return; }
    var txt = b.getAttribute('data-sms-copy') || '';
    var done = function () { var o = b.textContent; b.textContent = '✓ কপি হয়েছে'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
        try {
            var ta = document.createElement('textarea');
            ta.value = txt; ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy');
            document.body.removeChild(ta); done();
        } catch (e) { b.textContent = 'কপি করা গেল না'; }
    }
});
</script>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>
