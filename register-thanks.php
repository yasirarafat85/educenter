<?php
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';
require_once __DIR__ . '/includes/registration-card.php';

/* 🔴🔴 আগে এখানে `unset($_SESSION['registration_success'])` ছিল — পাতাটা একবারই
 *      খুলত। অভিভাবক পেমেন্ট করতে bKash অ্যাপে গিয়ে ফিরে এসে রিফ্রেশ দিলেই
 *      হোমপেজে ছিটকে যেতেন। এখন সেশনে থেকেই যায় (ব্রাউজার বন্ধ হলে মুছবে), আর
 *      আসল স্থায়ী ঠিকানা হলো `pay?r=…&k=…` (HMAC চাবি, মাইগ্রেশন লাগে না)। */
$success = $_SESSION['registration_success'] ?? null;

if (!$success) {
    redirect('index.php');
}

$regRef  = (int) ($success['ref'] ?? 0);
$payUrl  = pay_link_url($regRef);

/* 🪪 কার্ডের সব তথ্য একটাই জায়গা থেকে (`includes/registration-card.php`) —
 *    এই পাতা, `pay.php` ও `my-registration.php` তিনটাতেই হুবহু একই কার্ড।
 * 🔴 রেজিস্ট্রেশনটা কোনো কারণে DB-তে না পেলে সেশনের তথ্য দিয়েই কার্ড আঁকা হয়
 *    (পাতাটা কখনো ফাঁকা দেখাবে না) — আগের আচরণ হুবহু। */
$card = reg_card_fetch(get_db(), $regRef) ?: [
    'ref'        => $regRef,
    'type'       => (string) ($success['type'] ?? ''),
    'item_title' => (string) ($success['item_title'] ?? ''),
    'batch'      => '',
    'is_course'  => ($success['type'] ?? '') === 'course',
    'schedule'   => '',
];

$isCourse   = !empty($card['is_course']);
$pageTitle  = 'ধন্যবাদ';
$activePage = '';

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-xl mx-auto">
    <?= reg_card_html($card) ?>

    <div class="flex flex-col sm:flex-row gap-3 justify-center mt-6">
        <a href="./" class="text-white px-8 py-3 rounded-xl font-bold text-center shadow-lg" style="background:linear-gradient(135deg, rgb(var(--c-primary-2)), rgb(var(--c-primary)));">হোমে ফিরে যান</a>
        <?= reg_card_download_btn() ?>
    </div>

    <?php
        /* 💳 পেমেন্টের ধাপ — এখন একটা আলাদা পাতায় (`pay.php`): চ্যানেল বাছা →
         * নম্বর/QR → TrxID দিয়ে যাচাই। এখানে শুধু একটা বড় বোতাম।
         * 🔴 পুরনো `render_payment_box()` ফলব্যাক হিসেবে থেকেই যায় — SMS-যাচাই
         *    চালু না থাকলে (বা কোনো bKash/নগদ নাম্বার সেট না থাকলে) অভিভাবক যেন
         *    অন্তত নাম্বারগুলো দেখতে পান। **এই ফলব্যাক সরাবেন না।** */
        $payReg = get_db()->prepare('SELECT type, item_id, phone FROM registrations WHERE id = :id');
        $payReg->execute(['id' => $regRef]);
        $payRow = $payReg->fetch() ?: [];

        $payReady = $payUrl !== ''
            && psms_ready(get_db())
            && pclaim_methods(get_db(), (string) ($payRow['type'] ?? ''), (int) ($payRow['item_id'] ?? 0));
    ?>
    <?php if ($payReady): ?>
        <div class="mt-8 text-center">
            <a href="<?= e($payUrl) ?>" class="pay-go" style="display:inline-block;width:auto;padding:16px 32px;text-decoration:none;">
                💳 এখনই পেমেন্ট করুন
            </a>
            <p class="text-xs text-gray-500 mt-3">
                বিকাশ/নগদে টাকা পাঠিয়ে TrxID দিলেই সাথে সাথে যাচাই হয়ে যাবে।
                <br>পরে করতে চাইলেও সমস্যা নেই — আপনার রেজিস্ট্রেশন অক্ষত থাকবে।
            </p>
        </div>
    <?php else: ?>
        <?= render_payment_box($payRow['type'] ?? '', (int) ($payRow['item_id'] ?? 0)) ?>
    <?php endif; ?>

    <?php
        /* 💬 "ফি দিয়ে ফেলেছেন? সরাসরি জানান" — WhatsApp ও (লিংক সেট থাকলে) ইনবক্স।
         *
         * 🔴🔴 ব্লকটা `#confirmation-card`-এর **বাইরে**, ইচ্ছাকৃত — ঐ কার্ডটাই
         *      html2canvas দিয়ে ছবি হয়ে ডাউনলোড হয়; ভেতরে দিলে ডাউনলোড করা ছবিতে
         *      একটা অচল সবুজ বোতাম ছাপা থাকত (হোম/ডাউনলোড বোতামও এজন্যই বাইরে)।
         * 🔴 পেমেন্টের অংশের **পরে** — অভিভাবক আগে সহজ পথটা দেখবেন (TrxID দিলে নিজে
         *    থেকেই যাচাই, অ্যাডমিনের কিছু করার থাকে না); আগে বসালে সবাই ইনবক্সেই
         *    মেসেজ করতেন আর হাতের কাজ বাড়ত।
         * 🔴 নম্বর/লিংক সেট না থাকলে (বা নম্বর ভুল হলে) পুরো ব্লকটাই রেন্ডার হয় না। */
        $thxLines = [$isCourse ? 'কোর্স ফি পরিশোধ করেছি — জানাতে চাই।' : 'অর্ডারের টাকা পরিশোধ করেছি — জানাতে চাই।'];
        $thxLines[] = 'রেফারেন্স নম্বর: #' . $regRef;
        $thxLines[] = ($isCourse ? 'কোর্স: ' : 'আইটেম: ') . (string) ($success['item_title'] ?? '');
        if (!empty($payRow['phone'])) {
            $thxLines[] = 'মোবাইল: ' . $payRow['phone'];
        }
        $thxWa   = wa_chat_url(implode("\n", $thxLines));
        $thxMsg  = messenger_chat_url();
        $thxBoth = ($thxWa !== '' && $thxMsg !== '');
    ?>
    <?php if ($thxWa !== '' || $thxMsg !== ''): ?>
    <div class="max-w-xl mx-auto mt-5">
        <div class="rounded-2xl p-5 sm:p-6" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
            <p class="text-gray-600 text-sm text-center mb-3">
                ফি দিয়ে ফেলেছেন? <span class="font-bold text-gray-800">সরাসরি জানিয়ে দিন</span> —
            </p>
            <div class="grid gap-2.5<?= $thxBoth ? ' sm:grid-cols-2' : '' ?>">
                <?php if ($thxWa !== ''): ?>
                <a href="<?= e($thxWa) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 text-white font-bold py-3 px-4 rounded-xl shadow-lg" style="background:linear-gradient(135deg,#25D366 0%,#128C7E 100%);">
                    <?php /* 🔴 ব্র্যান্ড লোগো সবসময় inline SVG — `data-lucide="whatsapp"` নেই, খালি বসত */ ?>
                    <svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.548 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    <span class="min-w-0 break-words text-center text-sm sm:text-base">WhatsApp-এ জানান</span>
                </a>
                <?php endif; ?>
                <?php if ($thxMsg !== ''): ?>
                <a href="<?= e($thxMsg) ?>" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 text-white font-bold py-3 px-4 rounded-xl shadow-lg" style="background:linear-gradient(135deg,#00B2FF 0%,#006AFF 100%);">
                    <svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path d="M12 2C6.36 2 2 6.13 2 11.7c0 2.91 1.19 5.44 3.14 7.17.16.14.26.34.27.55l.05 1.78a.8.8 0 0 0 1.15.69l1.99-.88a.8.8 0 0 1 .53-.04c.91.25 1.88.38 2.87.38 5.64 0 10-4.13 10-9.7S17.64 2 12 2m6 7.46-2.94 4.67a1.53 1.53 0 0 1-2.2.41l-2.34-1.75a.6.6 0 0 0-.72 0l-3.16 2.4c-.42.32-.97-.18-.69-.63l2.94-4.67a1.53 1.53 0 0 1 2.2-.41l2.34 1.75a.6.6 0 0 0 .72 0l3.16-2.4c.42-.32.97.18.69.63"/></svg>
                    <span class="min-w-0 break-words text-center text-sm sm:text-base">ইনবক্সে জানান</span>
                </a>
                <?php endif; ?>
            </div>
            <?php if ($payReady): ?>
            <p class="text-xs text-gray-500 mt-3 text-center">
                তবে উপরের 💳 বোতামে TrxID দিলে সাথে সাথে যাচাই হয়ে যায় — সেটাই সবচেয়ে দ্রুত।
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
    /* 🔗 স্থায়ী লিংক — অভিভাবক এটা রেখে দিলে পরে যেকোনো দিন কার্ড নামাতে ও
     *    পেমেন্ট জানাতে পারবেন (লিংকে HMAC চাবি আছে, অনুমান করা যায় না)।
     * 🔴 ব্লকটা ইচ্ছাকৃতভাবে পেমেন্ট ও WhatsApp-এর **পরে** — আগে বসালে
     *    "এখনই পেমেন্ট করুন" ঢাকা পড়ত। */
?>
<?= reg_card_save_link_html($payUrl) ?>

<?= reg_card_scripts($regRef) ?>
<?= reg_card_save_link_scripts() ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
