<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();
$pageTitle = 'সাইট সেটিংস';

// থিম ও ফন্ট অপশন (includes/functions.php থেকে)
$themeOptions = [];
foreach (get_site_themes() as $tid => $t) {
    $themeOptions[$tid] = $t['label'];
}
$fontOptions = [];
foreach (get_site_fonts() as $fid => $f) {
    $fontOptions[$fid] = $f['label'];
}

// ফর্মে গ্রুপ করে দেখানোর জন্য কনফিগ
$groups = [
    'সাইট থিম ও ডিজাইন (পুরো ওয়েবসাইটে প্রযোজ্য)' => [
        'site_theme'          => ['label' => 'রেডিমেড থিম (নিচের কাস্টম রঙ খালি থাকলে এটা ব্যবহার হবে)', 'type' => 'select', 'options' => $themeOptions],
        'site_custom_primary' => ['label' => 'কাস্টম প্রাইমারি রঙ (ঐচ্ছিক) — বসালে রেডিমেড থিমের বদলে এই রঙ থেকে পুরো ডিজাইনের শেড তৈরি হবে। খালি রাখুন উপরের থিম ব্যবহার করতে।', 'type' => 'color'],
        'site_font'           => ['label' => 'বাংলা ফন্ট (পুরো সাইটে)', 'type' => 'select', 'options' => $fontOptions],
    ],
    'সাধারণ তথ্য' => [
        'site_name'    => 'সাইটের নাম',
        'site_tagline' => 'ট্যাগলাইন',
        'footer_text'  => ['label' => 'ফুটারের নিচের লাইন (কপিরাইট)', 'type' => 'text', 'help' => 'খালি রাখলে অটো দেখাবে: © <এই বছর> <সাইটের নাম>. সকল অধিকার সংরক্ষিত। নিজে লিখতে চাইলে সালের জায়গায় {year} আর নামের জায়গায় {site} লিখুন — তাহলে প্রতি বছর সাল নিজে থেকেই বদলাবে।'],
        'footer_about' => ['label' => 'ফুটারে প্রতিষ্ঠানের ছোট বর্ণনা', 'type' => 'textarea', 'help' => 'ফুটারের বাঁ পাশে লোগোর নিচে ২-৩ লাইন। খালি রাখলে ডিফল্ট লেখা দেখাবে।'],
    ],
    'SEO ও সোশ্যাল শেয়ার' => [
        'site_meta_description' => ['label' => 'সাইটের বিবরণ (Google সার্চ ও শেয়ার প্রিভিউতে দেখাবে — ~১৫০ অক্ষরে সংক্ষেপে সাইট কী নিয়ে)', 'type' => 'textarea'],
        'og_image'              => 'শেয়ার-ছবির URL (WhatsApp/Facebook-এ লিংক শেয়ার করলে এই ছবি দেখাবে; খালি রাখলে লোগো ব্যবহার হবে। আদর্শ সাইজ ১২০০×৬৩০)',
    ],
    'হোমপেজ "সংখ্যায় সাফল্য" স্ট্যাট (খালি রাখলে ডিফল্ট দেখাবে)' => [
        'stat1_value' => 'স্ট্যাট ১ — সংখ্যা (যেমন ৫০০+)',
        'stat1_label' => 'স্ট্যাট ১ — লেখা (যেমন সফল শিক্ষার্থী)',
        'stat2_value' => 'স্ট্যাট ২ — সংখ্যা (যেমন ৫০+)',
        'stat2_label' => 'স্ট্যাট ২ — লেখা (যেমন কোর্স সমূহ)',
        'stat3_value' => 'স্ট্যাট ৩ — সংখ্যা (যেমন ২০+)',
        'stat3_label' => 'স্ট্যাট ৩ — লেখা (যেমন অভিজ্ঞ শিক্ষক)',
        'stat4_value' => 'স্ট্যাট ৪ — সংখ্যা (যেমন ৯৮%)',
        'stat4_label' => 'স্ট্যাট ৪ — লেখা (যেমন সন্তুষ্ট শিক্ষার্থী)',
    ],
    'কোর্সের ছবি ও ভিডিও বোতাম (কোর্সের পেজে)' => [
        'course_media_photo_label' => ['label' => 'ছবির বোতামে যা লেখা থাকবে', 'type' => 'text', 'help' => 'খালি রাখলে দেখাবে: 📸 প্রিভিউ দেখুন। এই একটা লেখাই কোর্স কার্ডে ও বিস্তারিত পেজে দুই জায়গাতেই বসে।'],
        'course_media_video_label' => ['label' => 'ভিডিওর বোতামে যা লেখা থাকবে', 'type' => 'text', 'help' => 'খালি রাখলে দেখাবে: ▶️ ভিডিও দেখুন। ছবি/ভিডিও যোগ করা হয় কোর্স → ব্যাচ তালিকা → "🖼️ ছবি/ভিডিও" থেকে; ওয়ার্কশিট/প্রোডাক্টে ঐ তালিকার একই নামের লিংক থেকে (বোতামের এই লেখা তিন জায়গাতেই এক)।'],
        'course_media_watermark'      => ['label' => 'ছবির উপরে নাম (ওয়াটারমার্ক) বসবে? (১ = হ্যাঁ, ০ = না)', 'type' => 'text', 'help' => 'খালি বা ১ = বসবে। ⚠️ স্ক্রিনশট বন্ধ করা ওয়েবে সম্ভব নয় — কেউ স্ক্রিনশট নিলেও যেন আপনার নামটা ছবির উপরেই থাকে, সেটাই এর কাজ।'],
        'course_media_watermark_text' => ['label' => 'ওয়াটারমার্কে যা লেখা থাকবে', 'type' => 'text', 'help' => 'খালি রাখলে সাইটের নামই বসবে।'],
    ],
    'আয়-খরচে কোর্স বাছলে ক্যাটেগরি' => [
        'income_auto_category'  => ['label' => 'আয়ে কোর্স বাছলে ক্যাটেগরিতে যা বসবে', 'type' => 'text', 'help' => 'খালি রাখলে বসবে: কোর্স ফি। 🔴 শুধু ক্যাটেগরির ঘর খালি থাকলেই বসে — আপনার নিজের লেখা কখনো মুছে যায় না। আপনি আগে থেকে অন্য নাম ব্যবহার করলে হুবহু সেটাই এখানে লিখুন, নাহলে নতুন একটা ক্যাটেগরি তৈরি হয়ে যাবে।'],
        'expense_auto_category' => ['label' => 'খরচে কোর্স বাছলে ক্যাটেগরিতে যা বসবে', 'type' => 'text', 'help' => 'খালি রাখলে বসবে: কোর্স খরচ। ভাড়া/বেতন/বিদ্যুতের মতো সাধারণ খরচে কোর্স বাছা হয় না, তাই ওখানে এটা বসবেও না।'],
    ],
    'পেমেন্ট পাতা (অভিভাবক যা দেখবেন)' => [
        'pay_msg_verified'  => ['label' => '✅ যাচাই হয়ে গেলে যা দেখাবে', 'type' => 'text', 'help' => 'খালি রাখলে: ✅ পেমেন্ট যাচাই হয়েছে — ধন্যবাদ!'],
        'pay_msg_pending'   => ['label' => '⏳ এখনো মেলেনি — তখন যা দেখাবে', 'type' => 'textarea', 'help' => 'খালি রাখলে: ⏳ আপনার তথ্য জমা হয়েছে। মিলিয়ে দেখা হচ্ছে — সাধারণত কয়েক মিনিট সময় লাগে। 🔴 এখানে কখনো "ভুল TrxID" লিখবেন না — বিকাশ/নগদের SMS আমাদের ফোনে কয়েক মিনিট পরেও আসতে পারে, তখন দাবিটা নিজে থেকেই ✅ হয়ে যায়।'],
        'pay_msg_duplicate' => ['label' => '⚠️ TrxID আগে ব্যবহার হলে যা দেখাবে', 'type' => 'text', 'help' => 'খালি রাখলে: ⚠️ এই TrxID আগে একবার ব্যবহার করা হয়েছে।'],
        'pay_msg_already'   => ['label' => '✅ আগেই যাচাই হয়ে থাকলে যা দেখাবে', 'type' => 'text', 'help' => 'একই TrxID আবার দিলে। খালি রাখলে: ✅ এই পেমেন্টটি আগেই যাচাই হয়ে গেছে — নতুন করে কিছু করতে হবে না।'],
        'pay_msg_timeout'   => ['label' => '⏱️ ৪৫ সেকেন্ডেও না মিললে যা দেখাবে', 'type' => 'text', 'help' => 'এর নিচে "কেন হতে পারে" তিনটা কারণ ও WhatsApp বোতাম নিজে থেকেই আসে। খালি রাখলে: ⚠️ এখনো মেলানো যায়নি। আপনার তথ্য জমা আছে — আমরা নিজে দেখে যোগাযোগ করব।'],
        'pay_msg_notfound'  => ['label' => '❓ নম্বরে কোনো রেজিস্ট্রেশন না পেলে যা দেখাবে', 'type' => 'text', 'help' => 'খালি রাখলে: এই নম্বরে কোনো রেজিস্ট্রেশন খুঁজে পাইনি…'],
        'pay_msg_extra'     => ['label' => '📌 আপনার নিজের বাড়তি বার্তা (সব "মেলেনি" অবস্থায় নিচে বসবে)', 'type' => 'textarea', 'help' => 'যেমন: "অফিস সময় সকাল ৯টা–রাত ৯টা, এর বাইরে একটু দেরি হতে পারে।" খালি রাখলে কিছুই দেখাবে না। নিচে WhatsApp বোতামটা এমনিতেই থাকে (নম্বর নেওয়া হয় "যোগাযোগ" ঘরের WhatsApp থেকে)।'],
        'pay_auto_confirm'  => ['label' => 'যাচাই হলে অর্ডার নিজে থেকেই কনফার্ম হবে? (১ = হ্যাঁ, খালি/০ = না)', 'type' => 'text', 'help' => '🔴 ডিফল্ট বন্ধ — এটাই পরামর্শ। প্রথম কিছুদিন সিস্টেম শুধু দেখাবে "মিলেছে ✅", কনফার্ম আপনি নিজে করবেন। নিজের চোখে ১৫-২০টা আসল পেমেন্ট মিলতে দেখার পর ১ লিখে চালু করবেন। ⚠️ চালু করলেও টাকার খাতায় কিছু বসে না — সেটা আপনাকেই করতে হবে।'],
    ],
    'টাকার SMS — বিশ্বস্ত প্রেরক' => [
        'sms_trusted_senders' => ['label' => 'যেসব নাম থেকে আসা SMS-ই আসল টাকা বলে ধরা হবে', 'type' => 'textarea', 'help' => 'কমা দিয়ে বা আলাদা লাইনে লিখুন। খালি রাখলে ধরা হবে: bKash, NAGAD, Rocket, upay। 🔴 বিকাশ/নগদের বার্তা অপারেটরের নিবন্ধিত নাম দিয়ে আসে — সাধারণ মোবাইল থেকে ঐ নামে SMS পাঠানো যায় না, তাই এটাই নকল ঠেকানোর সবচেয়ে শক্ত পাহারা। নাম-মিল হুবহু হতে হয় (ছোট/বড় হাতের ও স্পেস-হাইফেন ধরা হয় না)। ⚠️ অন্য কোনো সেবার SMS লাগলে "পেমেন্ট ইনবক্স" পাতায় ঐ বার্তার প্রেরকের নামটা দেখে হুবহু এখানে যোগ করুন।'],
    ],
    'ফেসবুক সেকশন (হোমপেজে)' => [
        'facebook_section_on'    => 'সেকশনটি সাইটে দেখাবে? (১ = হ্যাঁ, ০ = না)',
        'facebook_section_title' => 'সেকশনের শিরোনাম',
        'facebook_page_url'      => 'ফেসবুক পেজের লিংক (দিলে "সর্বশেষ পোস্ট" টাইমলাইন অটো দেখাবে)',
        'facebook_page_show'     => 'পেজের টাইমলাইন দেখাবে? (১ = হ্যাঁ, ০ = না)',
    ],
    'কুরিয়ার প্রিসেট (কালেকশন হিসাবের জন্য)' => [
        'courier_dc_dhaka'     => 'ডেলিভারি চার্জ — ঢাকার মধ্যে (টাকা)',
        'courier_dc_near'      => 'ডেলিভারি চার্জ — ঢাকার নিকটবর্তী (টাকা)',
        'courier_dc_outside'   => 'ডেলিভারি চার্জ — ঢাকার বাইরে (টাকা)',
        'courier_weight_extra' => 'ওজন বেশি হলে অতিরিক্ত চার্জ (টাকা)',
    ],
    'যোগাযোগ' => [
        'contact_address'  => 'ঠিকানা',
        'contact_phone'    => 'ফোন নম্বর',
        'contact_email'    => 'ইমেইল',
        'contact_hours'    => ['label' => 'খোলার সময়', 'type' => 'text', 'help' => 'যেমন: প্রতিদিন সকাল ৯টা – রাত ৯টা। ফুটারের যোগাযোগ ঘরে দেখাবে — খালি রাখলে দেখাবে না।'],
        'contact_whatsapp' => 'WhatsApp নম্বর (মোবাইলে ভাসমান সবুজ বাটন — খালি রাখলে দেখাবে না)',
    ],
    'সোশ্যাল মিডিয়া লিংক' => [
        'social_facebook'  => 'Facebook URL',
        'social_twitter'   => 'Twitter URL',
        'social_youtube'   => 'YouTube URL',
        'social_instagram' => 'Instagram URL',
    ],
    'পেমেন্ট সেকশন (থ্যাংক-ইউ পেজের শিরোনাম/নোট)' => [
        'payment_title'    => 'সেকশন শিরোনাম (যেমন: এখনই পেমেন্ট করতে চান?)',
        'payment_note'     => ['label' => 'ছোট নোট (যেমন: চাইলে কলের জন্য অপেক্ষা না করে এখনই bKash এ Send Money করতে পারেন)। ⚠️ পেমেন্ট নাম্বার/WhatsApp বাটন এখন আলাদা পেজে — সাইডবারে "পেমেন্ট মেথড" থেকে যোগ/এডিট করুন।', 'type' => 'textarea'],
    ],
    'কুরিয়ার API' => [
        'courier_active_provider' => 'সক্রিয় কুরিয়ার প্রোভাইডার (steadfast অথবা pathao লিখুন)',
    ],
    'Steadfast কুরিয়ার' => [
        'courier_api_key'    => 'API Key',
        'courier_api_secret' => 'API Secret',
        'courier_base_url'   => 'API Base URL (খালি রাখলে ডিফল্ট ব্যবহার হবে)',
    ],
    'Pathao কুরিয়ার' => [
        'pathao_client_id'     => 'Client ID',
        'pathao_client_secret' => 'Client Secret',
        'pathao_username'      => 'Username (আপনার Pathao মার্চেন্ট ইমেইল)',
        'pathao_password'      => 'Password',
        'pathao_store_id'      => 'Store ID (Pathao মার্চেন্ট প্যানেল থেকে পাবেন)',
        'pathao_base_url'      => 'API Base URL (Live: https://api-hermes.pathao.com, Sandbox: https://courier-api-sandbox.pathao.com — খালি রাখলে Live ব্যবহার হবে)',
    ],
    'আমাদের সম্পর্কে (About Page)' => [
        'about_mission_text'   => ['label' => 'আমাদের লক্ষ্য (মূল প্যারাগ্রাফ)', 'type' => 'textarea'],
        'about_vision_text'    => ['label' => '"আমাদের মিশন" কার্ডের লেখা', 'type' => 'textarea'],
        'about_team_text'      => ['label' => '"আমাদের দল" কার্ডের লেখা', 'type' => 'textarea'],
        'about_feature1_title' => 'বিশেষত্ব ১ — শিরোনাম',
        'about_feature1_text'  => ['label' => 'বিশেষত্ব ১ — বিবরণ', 'type' => 'textarea'],
        'about_feature2_title' => 'বিশেষত্ব ২ — শিরোনাম',
        'about_feature2_text'  => ['label' => 'বিশেষত্ব ২ — বিবরণ', 'type' => 'textarea'],
        'about_feature3_title' => 'বিশেষত্ব ৩ — শিরোনাম',
        'about_feature3_text'  => ['label' => 'বিশেষত্ব ৩ — বিবরণ', 'type' => 'textarea'],
    ],
    'Google লগইন (অভিভাবক অ্যাকাউন্ট)' => [
        'google_client_id'     => ['label' => 'Google Client ID', 'type' => 'text', 'help' => 'Google Cloud → Clients থেকে (শেষে .apps.googleusercontent.com)। দুটোই বসালে অভিভাবক লগইন/সাইনআপে "Google দিয়ে লগইন" বাটন আসবে।'],
        'google_client_secret' => ['label' => 'Google Client Secret', 'type' => 'text', 'help' => 'GOCSPX-... — গোপন, শুধু এখানে (DB-তে) রাখুন। Redirect URI Google-এ বসাতে হবে: https://shishurmedhabikash.com/account-google-callback'],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    // প্রতি সেকশন এখন আলাদা ফর্ম — শুধু যে key গুলো POST এ এসেছে সেগুলোই আপডেট হয়।
    // ⚠️ array_key_exists গার্ড জরুরি: নাহলে এক সেকশন সেভ করলে বাকি সব key `?? ''` দিয়ে খালি হয়ে যেত।
    foreach ($groups as $fields) {
        foreach (array_keys($fields) as $key) {
            if (array_key_exists($key, $_POST)) {
                update_setting($key, trim($_POST[$key]));
            }
        }
    }

    // সেভের পর যে সেকশন থেকে সাবমিট হয়েছে সেখানেই ফিরে যাওয়ার অ্যাংকর (স্ক্রল অবস্থান অক্ষত থাকে)
    $backAnchor = preg_replace('/[^a-z0-9-]/', '', (string) ($_POST['_section'] ?? ''));
    $backUrl = 'settings.php' . ($backAnchor !== '' ? '#' . $backAnchor : '');

    // লোগো আলাদাভাবে (ইমেজ আপলোড) — শুধু লোগো সেকশন সাবমিট হলে প্রাসঙ্গিক (নাহলে no-op)
    try {
        $uploadedLogo = handle_image_upload('logo_path_file', 'site');
        if ($uploadedLogo) {
            update_setting('logo_path', $uploadedLogo);
        } elseif (isset($_POST['logo_path'])) {
            update_setting('logo_path', trim($_POST['logo_path']));
        }
    } catch (RuntimeException $e) {
        set_flash('error', $e->getMessage());
        redirect($backUrl);
    }

    set_flash('success', 'সেটিংস সেভ করা হয়েছে।');
    redirect($backUrl);
}

$settings = get_all_settings();

// about_* ফিল্ড খালি/না-সেভ করা থাকলে ফর্মে ডিফল্ট টেক্সট prefill করা হয় (about.php তে যেটা দেখায় সেটাই),
// যাতে অ্যাডমিনকে শূন্য থেকে টাইপ না করে শুধু এডিট করে সেভ করলেই চলে
foreach (about_page_defaults() as $key => $default) {
    if (empty($settings[$key])) {
        $settings[$key] = $default;
    }
}

require __DIR__ . '/includes/layout-top.php';
?>

<?php
// জাম্প-মেনুর জন্য ছোট লেবেল (গ্রুপ লেবেল থেকে বন্ধনী-অংশ বাদ)
$short_label = fn(string $s): string => trim(preg_replace('/\s*\(.*$/u', '', $s));
?>

<!-- উপরে জাম্প-মেনু — সেকশনে দ্রুত যেতে (মোবাইল+ডেস্কটপ), স্ক্রল করলেও উপরে আটকে থাকে -->
<div id="settings-jump" class="bg-white rounded-2xl shadow p-3 mb-6 max-w-3xl" style="position:sticky;top:64px;z-index:20;">
    <div class="flex flex-wrap gap-2">
        <a href="#sec-logo" class="whitespace-nowrap text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700">লোগো</a>
        <?php $gi = 0; foreach ($groups as $groupLabel => $fields): $gi++; ?>
            <a href="#sec-<?= $gi ?>" class="whitespace-nowrap text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-700"><?= e($short_label($groupLabel)) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div id="settings-sections" class="space-y-6 max-w-3xl">

    <!-- লোগো — নিজস্ব ফর্ম + সেভ বাটন -->
    <form method="post" enctype="multipart/form-data" id="sec-logo" class="bg-white rounded-2xl shadow p-6" style="scroll-margin-top:130px;">
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="sec-logo">
        <h3 class="font-bold text-gray-800 mb-4">লোগো</h3>
        <?php if (!empty($settings['logo_path'])):
            // DB-তে সাইট-রুট-রিলেটিভ path সেভ থাকে; admin/ সাবফোল্ডার থেকে দেখাতে '../' যোগ করতে হয়
            // (নাহলে ব্রাউজার admin/uploads/... খুঁজে 404 দেখায়)। এক্সটার্নাল URL হলে অপরিবর্তিত।
            $logoSrc = preg_match('#^https?://#i', $settings['logo_path']) ? $settings['logo_path'] : '../' . ltrim($settings['logo_path'], '/');
        ?>
            <img src="<?= e($logoSrc) ?>" class="w-20 h-20 object-cover rounded-xl border mb-3">
        <?php endif; ?>
        <input type="text" name="logo_path" value="<?= e($settings['logo_path'] ?? '') ?>" placeholder="লোগোর URL" class="w-full border rounded-xl px-4 py-2.5 mb-2">
        <input type="file" name="logo_path_file" accept="image/*" class="w-full text-sm mb-4">
        <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
    </form>

    <?php $gi = 0; foreach ($groups as $groupLabel => $fields): $gi++; ?>
    <!-- প্রতিটা গ্রুপ = নিজস্ব ফর্ম, নিজের সেভ বাটন (শুধু এই সেকশন সেভ হয়) -->
    <form method="post" enctype="multipart/form-data" id="sec-<?= $gi ?>" class="bg-white rounded-2xl shadow p-6" style="scroll-margin-top:130px;">
        <?= csrf_field() ?>
        <input type="hidden" name="_section" value="sec-<?= $gi ?>">
        <h3 class="font-bold text-gray-800 mb-4"><?= e($groupLabel) ?></h3>
        <div class="space-y-4">
            <?php foreach ($fields as $key => $field):
                $label = is_array($field) ? $field['label'] : $field;
                $type = is_array($field) ? ($field['type'] ?? 'text') : 'text';
                $val = $settings[$key] ?? '';
            ?>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 mb-1"><?= e($label) ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= e($key) ?>" rows="3" class="w-full border rounded-xl px-4 py-2.5"><?= e($val) ?></textarea>
                    <?php elseif ($type === 'select'): ?>
                        <select name="<?= e($key) ?>" class="w-full border rounded-xl px-4 py-2.5">
                            <?php foreach ($field['options'] as $ov => $ol): ?>
                                <option value="<?= e($ov) ?>" <?= (string) $val === (string) $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'color'): ?>
                        <div class="flex items-center gap-2">
                            <input type="color" value="<?= e(preg_match('/^#?[0-9a-fA-F]{6}$/', $val) ? '#' . ltrim($val, '#') : '#16a34a') ?>" oninput="this.nextElementSibling.value=this.value" class="w-11 h-11 rounded-lg border cursor-pointer flex-shrink-0">
                            <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>" placeholder="#RRGGBB — খালি রাখলে রেডিমেড থিম" class="flex-1 border rounded-xl px-4 py-2.5">
                            <button type="button" onclick="this.previousElementSibling.value=''" class="text-sm text-gray-500 hover:text-red-600 font-semibold px-2 flex-shrink-0">মুছুন</button>
                        </div>
                    <?php else: ?>
                        <input type="text" name="<?= e($key) ?>" value="<?= e($val) ?>" class="w-full border rounded-xl px-4 py-2.5">
                    <?php endif; ?>
                    <?php // 'help' => '...' — ইনপুটের নিচে ছোট ব্যাখ্যা (form-helpers.php-এর render_field()-এর মতোই) ?>
                    <?php if (is_array($field) && !empty($field['help'])): ?>
                        <p class="text-xs text-gray-500 mt-1"><?= e($field['help']) ?></p>
                    <?php endif; ?>
                    <?php
                    // ⚠️ ফুটারের লেখায় **হাতে লেখা সাল** পড়ে আছে কিনা (২০২৬-১০-০৫, ইউজারের স্ক্রিনশট থেকে)।
                    // 🔴 অ্যাডমিনের নিজের লেখা কোড **কখনো নিজে থেকে বদলায় না** (footer_text_html()-এর নিয়ম) —
                    //    তাই নিজে লেখা "© 2021 …" বছরের পর বছর পুরনো হয়েই থাকত। এখানে শুধু **মনে করিয়ে দেওয়া**
                    //    হয়; বোতামটা ইনপুটের লেখায় `{year}` বসায়, সেভ করা না করা অ্যাডমিনের হাতেই।
                    if ($key === 'footer_text' && $val !== '' && strpos($val, '{year}') === false
                        && preg_match('/\b(20\d\d)\b/', $val, $fyM) && $fyM[1] !== date('Y')):
                        $fyFixed = preg_replace('/\b' . preg_quote($fyM[1], '/') . '\b/', '{year}', $val, 1);
                    ?>
                        <div class="text-xs mt-2 px-3 py-2 rounded-xl" style="background:#fffbeb;border:1px solid #fcd34d;color:#78350f;line-height:1.9;">
                            ⚠️ এখানে সাল লেখা আছে <b><?= e($fyM[1]) ?></b>, অথচ এখন <b><?= date('Y') ?></b> সাল।
                            <button type="button" class="font-bold underline" data-fix-year="<?= e($fyFixed) ?>">সাল অটো করে দিন</button>
                            — চাপলে ঐ জায়গায় <b>{year}</b> বসবে, তারপর নিচের "সেভ করুন" চাপুন। এরপর প্রতি বছর সাল নিজে থেকেই বদলাবে।
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <button type="submit" class="mt-5 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
    </form>
    <?php endforeach; ?>

</div>

<script>
// ⚠️ "সাল অটো করে দিন" — শুধু ইনপুটের লেখায় {year} বসায়, সেভ অ্যাডমিন নিজে করেন
document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-fix-year]');
    if (!b) { return; }
    var inp = b.closest('div').parentElement.querySelector('input[name="footer_text"]');
    if (inp) { inp.value = b.getAttribute('data-fix-year'); inp.focus(); b.closest('div').remove(); }
});

// জাম্প-মেনু ও সেকশন অফসেট হেডারের প্রকৃত উচ্চতার সাথে সিঙ্ক করা হয় (hardcode px না) —
// মেনু হেডারের ঠিক নিচে ফ্লাশ বসে (মাঝে ফাঁক থাকে না, পেছনের বাটন উঁকি দেয় না), আর অ্যাংকর জাম্পে
// সেকশন হেডার+মেনুর (২ লাইন হলেও) ঠিক নিচে এসে থামে।
(function () {
    var header = document.querySelector('header');
    var menu = document.getElementById('settings-jump');
    var secs = document.querySelectorAll('#settings-sections > form');
    function sync() {
        var hh = header ? header.getBoundingClientRect().height : 60;
        if (menu) menu.style.top = Math.round(hh) + 'px';
        var off = Math.round(hh + (menu ? menu.getBoundingClientRect().height : 0) + 14);
        secs.forEach(function (s) { s.style.scrollMarginTop = off + 'px'; });
    }
    sync();
    window.addEventListener('resize', sync);
    window.addEventListener('load', sync);
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
