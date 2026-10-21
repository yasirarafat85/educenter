<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 🔎 "আমার রেজিস্ট্রেশন খুঁজুন" — কার্ড হারিয়ে ফেললে  (২০২৬-১০-২১)
 *
 * অভিভাবক **মোবাইল নম্বর + রেফারেন্স নম্বর দুটোই** দিলে তাঁর স্থায়ী লিংকে
 * (`pay?r=…&k=…`) পাঠিয়ে দেওয়া হয় — ওখানে কার্ড, ডাউনলোড ও পেমেন্ট সবই আছে।
 *
 * 🔴🔴 **শুধু রেফারেন্স নম্বর কখনো যথেষ্ট নয়** — নম্বরগুলো ধারাবাহিক (#186, #187,
 *      #188…), তাই একটা নম্বর দিয়ে কার্ড দেখা গেলে যে কেউ ১ থেকে গুনে গুনে
 *      প্রতিটা শিক্ষার্থীর নাম, কোর্স, ব্যাচ ও টাকার ছক বের করে ফেলতে পারত।
 *      মোবাইল নম্বরটা জোড়া থাকায় সেটা বন্ধ — নিজের নম্বর ছাড়া কিছুই মেলে না।
 *
 * 🔴 ব্যর্থ হলে **কখনো বলা হয় না কোনটা ভুল** (নম্বর না রেফারেন্স) — নাহলে এটা
 *    "কোন রেফারেন্স নম্বরটা আসল" খুঁজে বের করার যন্ত্র হয়ে যেত।
 * 🔴 এই পাতা DB-তে কিছুই লেখে না (রেট-লিমিটের গণনা ছাড়া)।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/payment-claim.php';
require_once __DIR__ . '/includes/registration-card.php';

$db   = get_db();
$err  = '';
$card = null;          // ⚠️ শুধু ফলব্যাক — স্থায়ী লিংক বানানো না গেলে এখানেই কার্ড দেখানো হয়
$oldPhone = '';
$oldRef   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $oldPhone = trim((string) ($_POST['phone'] ?? ''));
    $oldRef   = trim((string) ($_POST['ref'] ?? ''));

    // 🔴 সব ব্যর্থ অবস্থায় **একই লেখা** — কোনটা ভুল সেটা কখনো জানানো হয় না
    $notFound = 'এই তথ্য দিয়ে কোনো রেজিস্ট্রেশন খুঁজে পাইনি। মোবাইল নম্বর ও রেফারেন্স নম্বর দুটোই মিলিয়ে দেখুন — রেজিস্ট্রেশনের সময় যে নম্বরটা দিয়েছিলেন সেটাই লাগবে।';

    if (!csrf_verify()) {
        $err = 'ফর্মের সময় শেষ হয়ে গেছে। পাতাটা রিফ্রেশ করে আবার চেষ্টা করুন।';
    } elseif (is_spam_submission($_POST)) {
        $err = $notFound;                               // বটকে আলাদা কিছু বলা হয় না
    } elseif (form_submit_rate_limited($db, client_ip())) {
        $err = 'একটু বেশি চেষ্টা হয়ে গেছে। কিছুক্ষণ পরে আবার চেষ্টা করুন।';
    } else {
        $phone = bd_phone_canonical($oldPhone);
        $ref   = (int) preg_replace('/[^0-9]/', '', $oldRef);

        if (!preg_match('/^01[3-9][0-9]{8}$/', $phone) || $ref <= 0) {
            $err = 'মোবাইল নম্বর (১১ ডিজিট, 01 দিয়ে শুরু) ও রেফারেন্স নম্বর দুটোই ঠিকভাবে লিখুন।';
        } else {
            form_record_submit($db, client_ip());       // 🔴 যাচাইয়ের পরে, নাহলে ভুল লেখাও কোটা খেত

            $found = null;
            try {
                // 🔴🔴 দুটো শর্তই একসাথে — আইডি **ও** ফোন। একটা মিললে হবে না।
                $st = $db->prepare("SELECT id FROM registrations
                                    WHERE id = :id AND phone = :p AND status <> 'cancelled'
                                    LIMIT 1");
                $st->execute(['id' => $ref, 'p' => $phone]);
                $found = $st->fetch() ?: null;
            } catch (Throwable $e) {
                $found = null;
            }

            if (!$found) {
                $err = $notFound;
            } else {
                $url = pay_link_url((int) $found['id']);
                if ($url !== '') {
                    // 🔑 স্থায়ী লিংকেই পাঠিয়ে দেওয়া — কার্ড, ডাউনলোড, পেমেন্ট সব ওখানে
                    redirect($url);
                }
                // চাবি বানানো না গেলে (settings-এ লেখা যায়নি) — অন্তত কার্ডটা এখানেই
                $card = reg_card_fetch($db, (int) $found['id']);
                if (!$card) {
                    $err = $notFound;
                }
            }
        }
    }
}

$pageTitle       = 'আমার রেজিস্ট্রেশন';
$pageDescription = 'মোবাইল নম্বর ও রেফারেন্স নম্বর দিয়ে আপনার রেজিস্ট্রেশন কার্ড আবার দেখুন ও ডাউনলোড করুন।';
$pageNoIndex     = true;   // 🔴 ব্যক্তিগত পাতা — গুগল যেন কখনো ইনডেক্স না করে
$activePage      = '';

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-xl mx-auto">

    <?php if ($card): ?>
        <?= reg_card_html($card) ?>
        <div class="flex justify-center mt-5">
            <?= reg_card_download_btn('কার্ড ডাউনলোড করুন') ?>
        </div>
        <p class="text-center mt-5">
            <a href="./" class="text-sm text-gray-500 hover:underline">হোমপেজে ফিরে যান</a>
        </p>
    <?php else: ?>

        <div class="text-center mb-6">
            <h1 class="text-2xl sm:text-3xl font-black text-gray-900">আমার রেজিস্ট্রেশন খুঁজুন</h1>
            <p class="text-gray-500 mt-2 text-sm">
                কার্ডটা হারিয়ে ফেলেছেন? নিচে দুটো তথ্য দিলেই আবার দেখতে ও ডাউনলোড করতে পারবেন।
            </p>
        </div>

        <?php if ($err !== ''): ?>
            <div class="rounded-2xl p-4 mb-5 text-sm" style="background:#FEF2F2;border:1px solid #FECACA;color:#991B1B;">
                <?= e($err) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-2xl shadow-lg overflow-hidden" style="border:1px solid rgb(var(--c-border));">
            <div class="h-1.5" style="background:linear-gradient(90deg, rgb(var(--c-deep)), rgb(var(--c-primary)), rgb(var(--c-primary-2)));"></div>

            <form method="post" action="my-registration.php" class="p-5 sm:p-6">
                <?= csrf_field() ?>
                <?= spam_protection_fields() ?>

                <label class="block mb-4">
                    <span class="block text-sm font-bold text-gray-700 mb-1.5">মোবাইল নম্বর</span>
                    <input type="tel" name="phone" inputmode="numeric" autocomplete="tel"
                           value="<?= e($oldPhone) ?>" placeholder="01XXXXXXXXX" required
                           class="w-full rounded-xl px-4 py-3 text-gray-800"
                           style="border:1px solid rgb(var(--c-border));font-size:16px;">
                    <span class="block text-xs text-gray-500 mt-1">রেজিস্ট্রেশনের সময় যে নম্বরটা দিয়েছিলেন</span>
                </label>

                <label class="block mb-5">
                    <span class="block text-sm font-bold text-gray-700 mb-1.5">রেফারেন্স নম্বর</span>
                    <input type="text" name="ref" inputmode="numeric"
                           value="<?= e($oldRef) ?>" placeholder="188" required
                           class="w-full rounded-xl px-4 py-3 text-gray-800"
                           style="border:1px solid rgb(var(--c-border));font-size:16px;">
                    <span class="block text-xs text-gray-500 mt-1">কার্ডে বড় করে লেখা ছিল — যেমন #188</span>
                </label>

                <button type="submit" class="pay-go">🔎 খুঁজুন</button>
            </form>
        </div>

        <p class="text-center mt-5 text-sm text-gray-500">
            রেফারেন্স নম্বরটা মনে নেই?
            <a href="payment" class="hover:underline" style="color:rgb(var(--c-deep));font-weight:700;">পেমেন্ট জানিয়ে দিন</a>
            পাতায় শুধু মোবাইল নম্বর ও TrxID দিলেই চলবে।
        </p>

    <?php endif; ?>
</div>

<?php if ($card): ?><?= reg_card_scripts((int) $card['ref']) ?><?php endif; ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
