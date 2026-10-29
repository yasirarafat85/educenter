<?php
// 📨 যোগাযোগ করুন / আমাদের লিখে জানান — পাবলিক পাতা (2026-10-29)
// যে কেউ (লগইন ছাড়া) নাম/মোবাইল/বার্তা লিখে পাঠাতে পারেন → contact_messages টেবিলে।
// 🔴 ফিচার বন্ধ/মাইগ্রেশনের আগে ফর্ম লুকায়, কিন্তু ফোন/WhatsApp/ইমেইল তথ্য তবু দেখায়।
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/contact.php';

$pageTitle = 'যোগাযোগ করুন';
$activePage = 'contact';
$pageDescription = 'যেকোনো প্রশ্ন, ভর্তি সংক্রান্ত জিজ্ঞাসা বা মতামত — সরাসরি আমাদের লিখে জানান। আমরা দ্রুত যোগাযোগ করব।';
$pageJsonLd = jsonld_breadcrumb([['name' => 'যোগাযোগ', 'url' => 'contact']]);

$db = get_db();
$formReady = contact_feature_ready($db);

// যোগাযোগের তথ্য (ফুটারের একই সেটিংস)
$cPhone   = trim((string) get_setting('contact_phone'));
$cEmail   = trim((string) get_setting('contact_email'));
$cAddress = trim((string) get_setting('contact_address'));
$cHours   = trim((string) get_setting('contact_hours'));
$cWa      = normalize_bd_whatsapp(get_setting('contact_whatsapp'));

$flash = get_flash();
$justSent = $flash && ($flash['type'] ?? '') === 'success';
$old = $_SESSION['contact_form_old'] ?? [];
unset($_SESSION['contact_form_old']);

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-3xl mx-auto">
    <?= render_page_header('mail', 'যোগাযোগ', 'আমাদের লিখে জানান', 'যেকোনো প্রশ্ন বা মতামত — সরাসরি এখানে লিখুন, আমরা দ্রুত যোগাযোগ করব', 'text-indigo-700') ?>

    <?php // ── যোগাযোগের সরাসরি পথ (সবসময় দেখায়) ── ?>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-6">
        <?php if ($cPhone): ?>
            <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cPhone)) ?>" class="ct-info">
                <span class="ct-ic" style="background:#EFF6FF;color:#1D4ED8;"><i data-lucide="phone" class="w-5 h-5"></i></span>
                <span><b>ফোন করুন</b><small><?= e($cPhone) ?></small></span>
            </a>
        <?php endif; ?>
        <?php if ($cWa): ?>
            <a href="https://wa.me/<?= e($cWa) ?>" target="_blank" rel="noopener noreferrer" class="ct-info">
                <span class="ct-ic" style="background:#ECFDF5;color:#047857;"><i data-lucide="message-circle" class="w-5 h-5"></i></span>
                <span><b>WhatsApp</b><small>সরাসরি চ্যাট করুন</small></span>
            </a>
        <?php endif; ?>
        <?php if ($cEmail): ?>
            <a href="mailto:<?= e($cEmail) ?>" class="ct-info">
                <span class="ct-ic" style="background:#FAF5FF;color:#7E22CE;"><i data-lucide="mail" class="w-5 h-5"></i></span>
                <span><b>ইমেইল</b><small class="ct-break"><?= e($cEmail) ?></small></span>
            </a>
        <?php endif; ?>
    </div>

    <?php if ($cAddress || $cHours): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-6 text-sm text-gray-600 flex flex-col gap-1">
        <?php if ($cAddress): ?><p class="flex items-start gap-2"><i data-lucide="map-pin" class="w-4 h-4 mt-0.5 text-gray-400"></i><span><?= nl2br(e($cAddress)) ?></span></p><?php endif; ?>
        <?php if ($cHours): ?><p class="flex items-center gap-2"><i data-lucide="clock" class="w-4 h-4 text-gray-400"></i><span><?= e($cHours) ?></span></p><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($justSent): ?>
        <div class="bg-green-50 border border-green-200 rounded-2xl p-6 text-center mb-6">
            <div class="text-4xl mb-2">✅</div>
            <p class="font-bold text-green-800 text-lg mb-1">বার্তা পাঠানো হয়েছে</p>
            <p class="text-green-700 text-sm"><?= e($flash['message']) ?></p>
            <a href="./" class="inline-block mt-4 text-indigo-600 font-semibold text-sm">← হোমপেজে ফিরে যান</a>
        </div>
    <?php endif; ?>

    <?php if ($formReady && !$justSent): ?>
        <?php if ($flash && ($flash['type'] ?? '') === 'error'): ?>
            <div class="mb-5 p-4 rounded-xl text-sm bg-red-50 border border-red-200 text-red-700"><?= e($flash['message']) ?></div>
        <?php endif; ?>
        <div class="bg-white rounded-2xl shadow p-5 sm:p-7">
            <h2 class="text-lg font-bold text-gray-800 mb-1">বার্তা লিখুন</h2>
            <p class="text-gray-500 text-sm mb-5">নিচের ঘরগুলো পূরণ করে পাঠান — আপনার মোবাইল নম্বরেই আমরা যোগাযোগ করব।</p>
            <form method="post" action="contact-submit.php" class="space-y-4">
                <?= csrf_field() ?>
                <?= spam_protection_fields() ?>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">আপনার নাম <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required maxlength="150" value="<?= e($old['name'] ?? '') ?>" class="ct-in" placeholder="আপনার নাম">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">মোবাইল নম্বর <span class="text-red-500">*</span></label>
                        <input type="tel" name="phone" required maxlength="20" value="<?= e($old['phone'] ?? '') ?>" class="ct-in" placeholder="017XXXXXXXX" inputmode="numeric">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">ইমেইল <span class="text-gray-400 font-normal">(ঐচ্ছিক)</span></label>
                        <input type="email" name="email" maxlength="190" value="<?= e($old['email'] ?? '') ?>" class="ct-in" placeholder="you@example.com">
                    </div>
                    <div>
                        <label class="block text-gray-600 text-sm font-semibold mb-1">কী বিষয়ে?</label>
                        <select name="topic" class="ct-in">
                            <?php $oldTopic = (string) ($old['topic'] ?? ''); ?>
                            <?php foreach (contact_topics() as $t): ?>
                                <option value="<?= e($t) ?>" <?= $oldTopic === $t ? 'selected' : '' ?>><?= e($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-gray-600 text-sm font-semibold mb-1">আপনার বার্তা <span class="text-red-500">*</span></label>
                    <textarea name="message" required rows="5" maxlength="2000" class="ct-in" placeholder="আপনি কী জানতে চান বিস্তারিত লিখুন..."><?= e($old['message'] ?? '') ?></textarea>
                </div>
                <button type="submit" class="btn-primary w-full py-3 rounded-xl font-bold text-white">বার্তা পাঠান</button>
            </form>
        </div>
    <?php elseif (!$formReady && !$justSent): ?>
        <div class="bg-white rounded-2xl shadow p-6 text-center text-gray-600">
            <p class="mb-2">এই মুহূর্তে ফর্মটি বন্ধ আছে। উপরের ফোন বা WhatsApp-এ সরাসরি যোগাযোগ করুন।</p>
        </div>
    <?php endif; ?>
</div>

<style>
.ct-info { display:flex; align-items:center; gap:.65rem; background:#fff; border-radius:1rem; padding:.9rem 1rem; box-shadow:0 1px 3px rgba(0,0,0,.08); transition:box-shadow .15s; }
.ct-info:hover { box-shadow:0 4px 12px rgba(0,0,0,.1); }
.ct-ic { display:inline-flex; width:2.5rem; height:2.5rem; align-items:center; justify-content:center; border-radius:.75rem; flex-shrink:0; }
.ct-info b { display:block; font-size:.9rem; color:#1f2937; }
.ct-info small { display:block; font-size:.78rem; color:#6b7280; }
.ct-break { word-break:break-all; }
.ct-in { width:100%; font-size:16px; padding:.6rem .8rem; border:1px solid #e5e7eb; border-radius:.75rem; background:#fff; color:#1f2937; }
.ct-in:focus { outline:none; border-color:rgb(var(--c-primary)); box-shadow:0 0 0 3px rgb(var(--c-primary) / .15); }
</style>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
