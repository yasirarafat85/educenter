<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 💳 পেমেন্ট পাতা — রেজিস্ট্রেশনের পরের ধাপ  (২০২৬-১০-০৬)
 *
 * `pay?r=<রেজিস্ট্রেশন আইডি>&k=<চাবি>` — চাবিটা HMAC (`pay_link_token()`),
 * তাই আইডি অনুমান করে অন্যের পাতা খোলা যায় না।
 *
 * 🔴🔴 লিংকটা **স্থায়ী ও বারবার খোলা যায়** — এটাই এই পাতার মূল শর্ত। অভিভাবক
 *      TrxID দিতে গেলে ব্রাউজার ছেড়ে bKash অ্যাপে যান, তারপর ফিরে আসেন; পুরনো
 *      "ধন্যবাদ" পাতা সেশন থেকে পড়ে সাথে সাথে `unset()` করত বলে ফিরে এসে
 *      রিফ্রেশ দিলেই সব হারাত।
 *
 * 🔴 এই পাতা টাকার খাতায় কিছুই লেখে না — শুধু `payment_claims`-এ একটা দাবি বসে।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';

$db    = get_db();
$regId = (int) ($_GET['r'] ?? 0);
$key   = (string) ($_GET['k'] ?? '');

if ($regId <= 0 || !pay_link_valid($regId, $key)) {
    // 🔴 "ভুল চাবি" বলে আলাদা বার্তা দেওয়া হয় না — আইডি অনুমান করে দেখার সুযোগ থাকত
    redirect('payment');
}

try {
    $st = $db->prepare('SELECT * FROM registrations WHERE id = :id LIMIT 1');
    $st->execute(['id' => $regId]);
    $reg = $st->fetch() ?: null;
} catch (Throwable $e) {
    $reg = null;
}
if (!$reg) {
    redirect('payment');
}

$ready    = psms_ready($db);
$methods  = pclaim_methods($db, (string) $reg['type'], (int) $reg['item_id']);
$due      = pclaim_due_hint($db, $reg);
$msgs     = pclaim_messages();
$result   = $_SESSION['pay_result'] ?? null;
unset($_SESSION['pay_result']);

$waUrl = pclaim_whatsapp_url([
    'ref'   => $regId,
    'item'  => (string) $reg['item_title'],
    'phone' => (string) $reg['phone'],
    'trxid' => $result['trxid'] ?? '',
]);

$pageTitle       = 'পেমেন্ট';
$pageDescription = 'রেজিস্ট্রেশনের পেমেন্ট সম্পন্ন করুন ও TrxID দিয়ে যাচাই করে নিন।';
$pageNoIndex     = true;   // 🔴 ব্যক্তিগত পাতা — গুগল যেন কখনো ইনডেক্স না করে
$activePage      = '';

require __DIR__ . '/includes/site-header.php';
require __DIR__ . '/includes/payment-ui.php';
?>

<div class="max-w-xl mx-auto">

    <?= pay_result_box($result, $msgs, $waUrl, (int) ($result['claim_id'] ?? 0)) ?>

    <div class="bg-white rounded-2xl shadow-lg overflow-hidden" style="border:1px solid rgb(var(--c-border));">
        <div class="h-1.5" style="background:linear-gradient(90deg, rgb(var(--c-deep)), rgb(var(--c-primary)), rgb(var(--c-primary-2)));"></div>

        <!-- অর্ডারের সারাংশ -->
        <div class="p-5 sm:p-6 border-b" style="border-color:rgb(var(--c-border));background:rgb(var(--c-tint));">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-xs text-gray-500">রেফারেন্স</p>
                    <p class="text-xl font-black text-gray-900">#<?= $regId ?></p>
                </div>
                <div class="min-w-0 text-right">
                    <p class="text-xs text-gray-500">যার জন্য</p>
                    <p class="font-bold text-gray-800 break-words"><?= e($reg['item_title']) ?></p>
                    <?php if (!empty($reg['batch'])): ?>
                        <p class="text-xs text-gray-500"><?= e($reg['batch']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($due > 0.009): ?>
                <div class="mt-4 pt-4 border-t" style="border-color:rgb(var(--c-border));">
                    <p class="text-xs text-gray-500">এখন দিতে হবে</p>
                    <p class="text-3xl font-black" style="color:rgb(var(--c-deep));">৳<?= number_format($due, 2) ?></p>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$ready || !$methods): ?>
            <div class="p-6 text-center text-gray-600">
                <p>অনলাইনে পেমেন্ট যাচাই এখনো চালু হয়নি।</p>
                <?php if ($waUrl !== ''): ?>
                    <a href="<?= e($waUrl) ?>" target="_blank" rel="noopener" class="pay-wa mt-4">💬 WhatsApp-এ জানান</a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <form method="post" action="pay-submit.php" class="p-5 sm:p-6" id="payForm">
                <?= csrf_field() ?>
                <?= spam_protection_fields() ?>
                <input type="hidden" name="r" value="<?= $regId ?>">
                <input type="hidden" name="k" value="<?= e($key) ?>">
                <?= pay_steps_html($methods, (string) $reg['phone'], $due) ?>
            </form>
        <?php endif; ?>
    </div>

    <p class="text-center mt-5">
        <a href="./" class="text-sm text-gray-500 hover:underline">পরে দিতে চাই — হোমপেজে ফিরে যান</a>
    </p>
    <p class="text-center mt-2 text-xs text-gray-400">
        পেমেন্ট না করলেও আপনার রেজিস্ট্রেশন বাতিল হবে না — আমরা নিজেরাই যোগাযোগ করব।
    </p>
</div>

<?= pay_scripts_html() ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
