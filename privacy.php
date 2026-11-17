<?php
// 🔒 গোপনীয়তা নীতি (Privacy Policy) — ২০২৬-১১-১৭
// মেটা (Facebook) অ্যাপ Publish করতে ও Google লগইনে এই পাতার URL লাগে।
// 🔴 নিচের প্রতিটা দাবি সাইটের আসল কোড অনুযায়ী লেখা — নতুন কোনো ডেটা সংগ্রহ/শেয়ার শুরু
//    করলে (নতুন ফর্মের ঘর, নতুন বাইরের সেবা) এই পাতাও একই কমিটে হালনাগাদ করুন।
// 🔴 নিচের অংশে ইংরেজি সংস্করণ ইচ্ছাকৃত — মেটার রিভিউয়াররা ইংরেজিতে পড়েন।
require_once __DIR__ . '/includes/functions.php';

$pageTitle       = 'গোপনীয়তা নীতি';
$activePage      = 'privacy';
$pageDescription = 'আমরা কোন তথ্য নিই, কেন নিই, কার সাথে শেয়ার করি, কতদিন রাখি এবং কীভাবে আপনার তথ্য মুছে ফেলতে বলবেন।';
$pageJsonLd      = jsonld_breadcrumb([['name' => 'গোপনীয়তা নীতি', 'url' => 'privacy']]);

const PRIVACY_UPDATED = '17 November 2026';

$pvSite    = get_setting('site_name') ?: 'EduCenter';
$pvPhone   = trim(get_setting('contact_phone'));
$pvEmail   = trim(get_setting('contact_email'));
$pvAddress = trim(get_setting('contact_address'));
$pvWa      = normalize_bd_whatsapp(get_setting('contact_whatsapp'));

// যোগাযোগের লাইনগুলো দুই ভাষাতেই লাগে — একবার বানিয়ে রাখা
$pvContact = static function (bool $en) use ($pvPhone, $pvEmail, $pvAddress, $pvWa): string {
    $rows = [];
    if ($pvEmail !== '')   $rows[] = ($en ? 'Email' : 'ইমেইল') . ': <a href="mailto:' . e($pvEmail) . '">' . e($pvEmail) . '</a>';
    if ($pvPhone !== '')   $rows[] = ($en ? 'Phone' : 'ফোন') . ': <a href="tel:' . e(preg_replace('/[^0-9+]/', '', $pvPhone)) . '">' . e($pvPhone) . '</a>';
    if ($pvWa !== '')      $rows[] = 'WhatsApp: <a href="https://wa.me/' . e($pvWa) . '" rel="noopener">+' . e($pvWa) . '</a>';
    if ($pvAddress !== '') $rows[] = ($en ? 'Address' : 'ঠিকানা') . ': ' . e($pvAddress);
    $rows[] = ($en ? 'Contact form' : 'যোগাযোগ ফর্ম') . ': <a href="contact">' . e(SITE_URL . '/contact') . '</a>';
    return '<ul class="pv-list"><li>' . implode('</li><li>', $rows) . '</li></ul>';
};

require __DIR__ . '/includes/site-header.php';
?>
<style>
.pv-wrap { max-width: 56rem; margin: 0 auto; }
.pv-card { background: #fff; border-radius: 1rem; box-shadow: 0 4px 18px rgba(0,0,0,.06); padding: 1.25rem; margin-bottom: 1rem; overflow-wrap: anywhere; }
@media (min-width: 640px) { .pv-card { padding: 1.75rem 2rem; } }
.pv-card h2 { font-size: 1.15rem; font-weight: 700; color: rgb(var(--c-deep)); margin: 0 0 .6rem; }
.pv-card p { color: #374151; line-height: 1.8; margin: 0 0 .6rem; }
.pv-list { list-style: disc; padding-inline-start: 1.3rem; color: #374151; line-height: 1.8; margin: 0 0 .6rem; }
.pv-list li { margin-bottom: .25rem; }
.pv-card a { color: rgb(var(--c-primary)); text-decoration: underline; }
.pv-meta { text-align: center; color: #6b7280; font-size: .9rem; margin: -.5rem 0 1.25rem; }
.pv-toc { display: flex; flex-wrap: wrap; gap: .5rem; justify-content: center; margin-bottom: 1.25rem; }
.pv-toc a { background: rgb(var(--c-tint)); color: rgb(var(--c-deep)); border-radius: 999px; padding: .35rem .9rem; font-size: .9rem; text-decoration: none; }
.pv-card { scroll-margin-top: 96px; }
.pv-hl { border-left: 4px solid rgb(var(--c-primary)); }
.pv-en h2 { font-family: inherit; }
</style>

<div class="pv-wrap">
    <?= render_page_header('shield-check', 'Privacy Policy', 'গোপনীয়তা নীতি', 'আপনার ও আপনার সন্তানের তথ্য আমরা কীভাবে ব্যবহার ও সুরক্ষা করি', 'text-green-700') ?>
    <p class="pv-meta">সর্বশেষ হালনাগাদ: <?= PRIVACY_UPDATED ?></p>

    <nav class="pv-toc" aria-label="এই পাতায়">
        <a href="#data-deletion">🗑️ তথ্য মুছে ফেলার অনুরোধ</a>
        <a href="#english">🇬🇧 English version</a>
    </nav>

    <div class="pv-card">
        <h2>1. আমরা কারা</h2>
        <p>এই ওয়েবসাইট (<a href="./"><?= e(SITE_URL) ?></a>) পরিচালনা করে <strong><?= e($pvSite) ?></strong> — শিশুদের জন্য কোর্স, ওয়ার্কশিট ও শিক্ষা-উপকরণ দেওয়া একটি প্রতিষ্ঠান। এই নীতিতে "আমরা" বলতে এই প্রতিষ্ঠানকে বোঝানো হয়েছে।</p>
    </div>

    <div class="pv-card">
        <h2>2. আমরা কোন তথ্য নিই</h2>
        <ul class="pv-list">
            <li><strong>কোর্স রেজিস্ট্রেশন:</strong> শিশুর নাম ও জন্ম তারিখ, মা-বাবার মোবাইল নম্বর, ফেসবুক আইডির নাম, পার্সেল গ্রহণকারীর নাম, নম্বর ও ঠিকানা, আর আপনার লেখা মন্তব্য।</li>
            <li><strong>ওয়ার্কশিট/প্রোডাক্ট অর্ডার:</strong> নাম, মোবাইল, ইমেইল (ঐচ্ছিক), ঠিকানা ও অর্ডারের বিবরণ।</li>
            <li><strong>অভিভাবক অ্যাকাউন্ট:</strong> মোবাইল নম্বর ও পাসওয়ার্ড (পাসওয়ার্ড শুধু এনক্রিপ্ট করা রূপে রাখা হয়, আমরা নিজেও পড়তে পারি না)। Google দিয়ে লগইন করলে Google থেকে আপনার নাম, ইমেইল ও Google অ্যাকাউন্টের একটি পরিচয়-নম্বর।</li>
            <li><strong>পেমেন্ট:</strong> আপনি যে bKash/নগদের TrxID ও নম্বর দেন, আর আমাদের নম্বরে টাকা আসার SMS-এর তথ্য (অঙ্ক, পাঠানোর নম্বর, তারিখ) — শুধু পেমেন্ট মিলিয়ে দেখতে। আমরা কখনো আপনার পিন/পাসওয়ার্ড চাই না।</li>
            <li><strong>যোগাযোগ ও অনুরোধ:</strong> যোগাযোগ ফর্ম, আগ্রহ-ফর্ম বা অ্যাকাউন্ট থেকে পাঠানো বার্তা।</li>
            <li><strong>ফেসবুক পেজ ও মেসেঞ্জার:</strong> আমাদের ফেসবুক পেজে মেসেজ বা কমেন্ট করলে আপনার ফেসবুক নাম, পেজ-নির্দিষ্ট পরিচয়-নম্বর ও বার্তাটি আমরা পাই — শুধু উত্তর দিতে ও আপনার প্রশ্নের সমাধান করতে।</li>
            <li><strong>স্বয়ংক্রিয় তথ্য:</strong> সাইট দেখার সময় IP ঠিকানা, ব্রাউজারের ধরন, কোন পাতা দেখেছেন ও সময় — সাইট নিরাপদ রাখতে ও কতজন দেখছেন বুঝতে।</li>
        </ul>
    </div>

    <div class="pv-card">
        <h2>3. কেন ব্যবহার করি</h2>
        <ul class="pv-list">
            <li>ভর্তি নিশ্চিত করা, ক্লাস ও গ্রুপে যুক্ত করা, পার্সেল পাঠানো।</li>
            <li>পেমেন্ট যাচাই ও আপনার জমা-বাকির হিসাব রাখা।</li>
            <li>আপনার প্রশ্ন, মেসেজ ও কমেন্টের উত্তর দেওয়া এবং জরুরি নোটিশ জানানো।</li>
            <li>স্প্যাম ও প্রতারণা ঠেকানো, সাইট নিরাপদ রাখা।</li>
        </ul>
        <p>আমরা আপনার তথ্য <strong>কখনো বিক্রি করি না</strong>, আর বিজ্ঞাপনদাতাদের দিই না।</p>
    </div>

    <div class="pv-card">
        <h2>4. কার সাথে শেয়ার হয়</h2>
        <ul class="pv-list">
            <li><strong>কুরিয়ার প্রতিষ্ঠান</strong> (যেমন Steadfast, Pathao): পার্সেল পৌঁছাতে গ্রহণকারীর নাম, ফোন, ঠিকানা ও কালেকশনের অঙ্ক।</li>
            <li><strong>Google:</strong> শুধু আপনি নিজে "Google দিয়ে লগইন" বাছলে।</li>
            <li><strong>Meta (Facebook/Messenger):</strong> আপনি আমাদের পেজে মেসেজ/কমেন্ট করলে সেটা Meta-র প্ল্যাটফর্মেই থাকে, Meta-র নিজের নীতি মেনে। হোমপেজে আমাদের ফেসবুক পোস্ট দেখালে Facebook নিজের কুকি ব্যবহার করতে পারে।</li>
            <li><strong>আইন অনুযায়ী</strong> সরকারি কর্তৃপক্ষ বৈধভাবে চাইলে।</li>
        </ul>
    </div>

    <div class="pv-card">
        <h2>5. কুকি</h2>
        <p>সাইট চালাতে কয়েকটি দরকারি কুকি লাগে — লগইন অবস্থা ধরে রাখা, "মনে রাখো" লগইন, আর ফর্মের নিরাপত্তা টোকেন। আমরা কোনো বিজ্ঞাপন বা ট্র্যাকিং কুকি ব্যবহার করি না।</p>
    </div>

    <div class="pv-card">
        <h2>6. কতদিন রাখি ও কীভাবে সুরক্ষা করি</h2>
        <ul class="pv-list">
            <li>রেজিস্ট্রেশন, অর্ডার ও পেমেন্টের তথ্য হিসাব ও আইনগত প্রয়োজনে যতদিন দরকার ততদিন রাখা হয়।</li>
            <li>ভিজিটর লগ প্রায় 180 দিন, লগইন-চেষ্টার লগ প্রায় 90 দিন পর নিজে থেকেই মুছে যায়।</li>
            <li>পুরো সাইট HTTPS (এনক্রিপ্টেড সংযোগ)-এ চলে; অ্যাডমিন অংশ শুধু অনুমোদিত কর্মীরা পাসওয়ার্ড দিয়ে খুলতে পারেন; ব্যাকআপ সুরক্ষিত জায়গায় থাকে।</li>
        </ul>
    </div>

    <div class="pv-card">
        <h2>7. শিশুদের তথ্য</h2>
        <p>আমাদের কোর্স শিশুদের জন্য, কিন্তু তথ্য দেন <strong>মা-বাবা বা অভিভাবক</strong>। শিশুদের নিজে অ্যাকাউন্ট খোলার বা সরাসরি তথ্য দেওয়ার সুযোগ নেই। শিশুর তথ্য শুধু ক্লাস ও যোগাযোগের কাজেই ব্যবহার হয়।</p>
    </div>

    <div class="pv-card">
        <h2>8. আপনার অধিকার</h2>
        <p>আপনার কোন তথ্য আমাদের কাছে আছে তা জানতে, ভুল সংশোধন করতে বা মুছে ফেলতে বলতে পারেন। অভিভাবক অ্যাকাউন্ট থেকেও সংশোধনের অনুরোধ পাঠানো যায়।</p>
    </div>

    <div class="pv-card pv-hl" id="data-deletion">
        <h2>9. 🗑️ তথ্য মুছে ফেলার অনুরোধ</h2>
        <p>আপনার তথ্য (ফেসবুক/মেসেঞ্জার থেকে পাওয়া তথ্যসহ) মুছে ফেলতে চাইলে:</p>
        <ul class="pv-list">
            <li>নিচের যেকোনো মাধ্যমে <strong>"আমার তথ্য মুছে দিন"</strong> লিখে পাঠান, সাথে রেজিস্ট্রেশনের মোবাইল নম্বর (আর ফেসবুকের হলে আপনার ফেসবুক নাম)।</li>
            <li>আমরা পরিচয় যাচাই করে <strong>30 দিনের মধ্যে</strong> তথ্য মুছে দিয়ে জানিয়ে দেব।</li>
            <li>আইন বা হিসাবের জন্য যেটুকু রাখা বাধ্যতামূলক (যেমন টাকার লেনদেনের রেকর্ড), শুধু সেটুকু রাখা হতে পারে — সেটাও আমরা জানিয়ে দেব।</li>
            <li>ফেসবুক থেকে আমাদের অ্যাপের অনুমতি সরাতে: Facebook → Settings &amp; privacy → Settings → Apps and websites → আমাদের অ্যাপ → Remove।</li>
        </ul>
        <?= $pvContact(false) ?>
    </div>

    <div class="pv-card">
        <h2>10. নীতিতে পরিবর্তন ও যোগাযোগ</h2>
        <p>এই নীতি সময়ে সময়ে হালনাগাদ হতে পারে; পরিবর্তন এই পাতাতেই প্রকাশ করা হবে এবং উপরের তারিখ বদলাবে। যেকোনো প্রশ্নে যোগাযোগ করুন:</p>
        <?= $pvContact(false) ?>
    </div>

    <div class="pv-card pv-en" id="english" lang="en">
        <h2>English version — Privacy Policy</h2>
        <p><em>Last updated: <?= PRIVACY_UPDATED ?></em></p>
        <p><strong>Who we are.</strong> This website (<?= e(SITE_URL) ?>) is operated by <strong><?= e($pvSite) ?></strong>, an education provider offering courses, worksheets and learning materials for children.</p>
        <p><strong>Information we collect.</strong> Course registrations (child's name and date of birth, parents' mobile numbers, Facebook profile name, parcel recipient's name, phone and address, notes); orders (name, mobile, optional email, address); parent accounts (mobile number and a hashed password; name, email and Google ID if you sign in with Google); payment details you submit (bKash/Nagad transaction ID and sender number) and the payment-received SMS on our own number, used only to verify payments — we never ask for your PIN; messages sent via our contact or interest forms; when you message or comment on our Facebook Page, your Facebook name, page-scoped ID and the message; and technical data (IP address, browser type, pages visited, time).</p>
        <p><strong>How we use it.</strong> To confirm admissions, add students to class groups, ship parcels, verify payments and keep account balances, reply to your messages and comments (including automated replies on Messenger/Facebook), send important notices, and prevent spam and fraud. <strong>We never sell your data</strong> or share it with advertisers.</p>
        <p><strong>Sharing.</strong> Courier companies (e.g. Steadfast, Pathao) receive recipient name, phone, address and collection amount to deliver parcels; Google only if you choose Google sign-in; Meta processes messages and comments on its own platform under its policies, and embedded Facebook posts on our homepage may set Facebook cookies; government authorities only when legally required.</p>
        <p><strong>Cookies.</strong> Only essential cookies (login session, "remember me", form security token). No advertising or tracking cookies.</p>
        <p><strong>Retention and security.</strong> Registration, order and payment records are kept as long as needed for accounting and legal purposes. Visitor logs are deleted after about 180 days and login-attempt logs after about 90 days. The site runs entirely over HTTPS, and the admin area is restricted to authorised staff.</p>
        <p><strong>Children.</strong> Our courses are for children, but information is provided by parents or guardians. Children cannot create accounts or submit information themselves.</p>
        <p><strong>Your rights and data deletion.</strong> You may ask to access, correct or delete your data, including data received from Facebook/Messenger. Send "Please delete my data" with your registered mobile number (and your Facebook name, if relevant) via any channel below. We will verify your identity and delete your data within <strong>30 days</strong>, except records we are legally required to keep (such as payment transactions), and confirm to you. To remove our app's access on Facebook: Settings &amp; privacy → Settings → Apps and websites → select our app → Remove.</p>
        <p><strong>Contact.</strong></p>
        <?= $pvContact(true) ?>
    </div>
</div>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
