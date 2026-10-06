<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 💳 "পেমেন্ট জানিয়ে দিন" — যেকোনো সময়, রেজিস্ট্রেশনের লিংক ছাড়াই  (২০২৬-১০-০৬)
 *
 * অভিভাবক রেজিস্ট্রেশনের সময় দেওয়া **মোবাইল নম্বর + TrxID** দিলেই হয়।
 * 🔴 নম্বরটা সত্যিই কোনো রেজিস্ট্রেশনের হতে হবে — এটাই অচেনা লোকের
 *    TrxID-অনুসন্ধান ঠেকায় (`pclaim_submit()`-এর ঘরে বিস্তারিত)।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';

$db      = get_db();
$ready   = psms_ready($db);
$methods = pclaim_methods($db);           // 🔴 এখানে আইটেম জানা নেই, তাই শুধু "সব আইটেমে" নাম্বারগুলো
$msgs    = pclaim_messages();
$result  = $_SESSION['pay_result'] ?? null;
unset($_SESSION['pay_result']);

$waUrl = pclaim_whatsapp_url(['trxid' => $result['trxid'] ?? '']);

$pageTitle       = 'পেমেন্ট জানিয়ে দিন';
$pageDescription = 'বিকাশ/নগদে টাকা পাঠিয়ে থাকলে TrxID দিয়ে জানিয়ে দিন — আমরা মিলিয়ে দেখে নিশ্চিত করব।';
$pageNoIndex     = true;   // 🔴 সার্চে আসার মতো পাতা নয়
$activePage      = '';

require __DIR__ . '/includes/site-header.php';
require __DIR__ . '/includes/payment-ui.php';
?>

<div class="max-w-xl mx-auto">
    <div class="text-center mb-6">
        <h1 class="text-2xl sm:text-3xl font-black text-gray-900">পেমেন্ট জানিয়ে দিন</h1>
        <p class="text-gray-500 mt-2 text-sm">
            বিকাশ/নগদে টাকা পাঠিয়ে থাকলে নিচে TrxID দিন — আমরা মিলিয়ে দেখে নিশ্চিত করব।
        </p>
    </div>

    <?= pay_result_box($result, $msgs, $waUrl, (int) ($result['claim_id'] ?? 0)) ?>

    <div class="bg-white rounded-2xl shadow-lg overflow-hidden" style="border:1px solid rgb(var(--c-border));">
        <div class="h-1.5" style="background:linear-gradient(90deg, rgb(var(--c-deep)), rgb(var(--c-primary)), rgb(var(--c-primary-2)));"></div>

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
                <?= pay_steps_html($methods, '', 0.0, true) ?>
            </form>
        <?php endif; ?>
    </div>

    <p class="text-center mt-5 text-xs text-gray-400">
        এখনো রেজিস্ট্রেশন করেননি? <a href="courses" class="hover:underline" style="color:rgb(var(--c-primary));">কোর্স দেখুন</a>
    </p>
</div>

<?= pay_scripts_html() ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
