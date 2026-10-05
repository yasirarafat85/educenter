<?php
// পাবলিক সাইটের কমন হেডার — প্রতিটা পাবলিক পেজে include হয়
// এই ফাইল include করার আগে $pageTitle (string) এবং $activePage (nav id) সেট করে নিতে হবে

require_once __DIR__ . '/functions.php';

// 🔢 পুরো পাবলিক পেজের দৃশ্যমান লেখায় বাংলা অঙ্ক → English (২০২৬-০৯-২৭)।
// বাংলা ১ · ৮ · ৯ প্রায় একই দেখতে বলে "১২টি" পড়া যাচ্ছিল "৮২টি" (ইউজারের স্ক্রিনশট)।
// 🔴 এখানেই বসানো হয়েছে কারণ এটাই প্রতিটা পাবলিক পেজের **প্রথম আউটপুট** — একটা জায়গায়
//    বসালেই কোর্স · ওয়ার্কশিট · প্রোডাক্ট · রেজিস্ট্রেশন · নোটিশ সব একসাথে কভার হয়।
// 🔴 অ্যাট্রিবিউট/script/textarea বাদ যায়, আর "৫ম ব্যাচ" জাতীয় ক্রমবাচক অক্ষত —
//    বিস্তারিত `digits_filter_html()`-এর কমেন্টে। PHP শাটডাউনে নিজেই ফ্লাশ করে,
//    তাই `exit` করা পেজেও (detail.php-এর ৪০৪ শাখা) চলে।
ob_start('digits_filter_html');

log_visitor();

// ক্লিন URL (.htaccess রিরাইট) — সব লিংক এখন extensionless, home = './' (সাবডিরেক্টরি/রুট দুই জায়গাতেই কাজ করে)
// 🔴 উপরের মেনু = **৮ আইটেম** (২০২৬-১০-০৫, ইউজারের নির্দেশ)। আগে ১১টা ছিল; Teachers ·
//    About · FAQs **ফুটারে সরানো হয়েছে** — ওগুলো একবার পড়ার মতো তথ্য-পাতা, প্রতিদিনের
//    নেভিগেশন নয়, আর ১১টা আইটেম ১০২৪–১৩৫০px প্রস্থে এক সারিতে আঁটত না।
// 🔴 পাতাগুলো মুছে ফেলা হয়নি — `teachers`/`about`/`faqs` তিনটাই আগের মতোই আছে, শুধু
//    প্রবেশপথ ফুটার (+ sitemap/৪০৪)। নতুন কিছু মেনুতে যোগ করার আগে ভাবুন: ফুটারই যথেষ্ট কিনা।
$navigation = [
    ['id' => 'home', 'label' => 'Home', 'icon' => 'home', 'url' => './'],
    ['id' => 'courses', 'label' => 'Our Course', 'icon' => 'book-open', 'url' => 'courses'],
    ['id' => 'worksheets', 'label' => 'Our Worksheet', 'icon' => 'file-text', 'url' => 'worksheets'],
    ['id' => 'products', 'label' => 'Our Products', 'icon' => 'shopping-bag', 'url' => 'products'],
    ['id' => 'notice', 'label' => 'Notice', 'icon' => 'bell', 'url' => 'notice'],
    ['id' => 'reviews', 'label' => 'Reviews', 'icon' => 'star', 'url' => 'reviews'],
    ['id' => 'gallery', 'label' => 'Gallery', 'icon' => 'image', 'url' => 'gallery'],
    ['id' => 'account', 'label' => !empty($_SESSION['user_id']) ? 'My Account' : 'Login', 'icon' => 'user-circle', 'url' => 'account'],
];

$activePage = $activePage ?? '';
$siteName = get_setting('site_name', 'EduCenter');
$siteTagline = get_setting('site_tagline', 'শিক্ষার আলো');
$logoPath = get_setting('logo_path', 'https://i.postimg.cc/T3FzJyxM/logo.png');

// সাইট থিম ও ফন্ট (অ্যাডমিন-নিয়ন্ত্রিত, site-wide) — প্যালেট CSS ভ্যারিয়েবলে বসানো হয় নিচে
$sitePalette = get_active_site_palette();
$siteFonts = get_site_fonts();
// 🔴 আইডিটা সবসময় তালিকার মধ্যেই ক্ল্যাম্প করা হয় — নিচে এটা দিয়ে **ফাইলের পাথ** বানানো হয়
// (assets/fonts/<id>.css), তাই settings-এ আজেবাজে মান থাকলে পাথ-ট্রাভার্সাল হতে পারত।
$siteFontId = get_setting('site_font') ?: 'noto';
if (!isset($siteFonts[$siteFontId])) {
    $siteFontId = 'noto';
}
$siteFont = $siteFonts[$siteFontId];

// SEO / সোশ্যাল শেয়ার মেটা — কোনো পেজ চাইলে include করার আগে $pageDescription / $pageOgImage সেট করে
// আইটেম-ভিত্তিক (যেমন নির্দিষ্ট কোর্সের) বিবরণ/ছবি দিতে পারে; নাহলে সাইট-ওয়াইড সেটিংস বা ডিফল্ট ব্যবহার হয়।
$metaDescription = (isset($pageDescription) && trim((string) $pageDescription) !== '')
    ? $pageDescription
    : (get_setting('site_meta_description') ?: ($siteName . ' — ' . $siteTagline));
$metaOgImage = (isset($pageOgImage) && trim((string) $pageOgImage) !== '')
    ? $pageOgImage
    : (get_setting('og_image') ?: $logoPath);
$metaThemeColor = sprintf('#%02x%02x%02x', $sitePalette['primary'][0], $sitePalette['primary'][1], $sitePalette['primary'][2]);
$baseUrl = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
// canonical/og:url — REQUEST_URI ডোমেইন-রুট থেকে শুরু হয় (সাবডিরেক্টরি প্রিফিক্স সহ), কিন্তু SITE_URL-এও সেই
// প্রিফিক্স থাকতে পারে (লোকাল /website); ডাবল হওয়া এড়াতে SITE_URL-এর path অংশ REQUEST_URI থেকে বাদ দেওয়া হয়
$reqPath = $_SERVER['REQUEST_URI'] ?? '';   // query string সহ (detail.php?type=..&id=.. এর জন্য জরুরি)
$sitePath = parse_url(SITE_URL, PHP_URL_PATH) ?: '';
if ($sitePath !== '' && $sitePath !== '/' && strpos($reqPath, $sitePath) === 0) {
    $reqPath = substr($reqPath, strlen($sitePath));
}
$canonicalUrl = $baseUrl . '/' . ltrim($reqPath, '/');
// 🔗 পেজ চাইলে নিজের canonical দিতে পারে — detail.php পুরনো `detail?type=..&id=..` রূপে
// খোলা হলেও canonical-এ নতুন পড়ার-মতো URL (`course/12-নাম`) দেখায়, তাই গুগল ওটাকেই
// আসল ঠিকানা ধরে। (রিলেটিভ দিলে SITE_URL জুড়ে absolute করা হয়।)
if (!empty($pageCanonical)) {
    $canonicalUrl = preg_match('#^https?://#i', $pageCanonical)
        ? $pageCanonical
        : ($baseUrl . '/' . ltrim($pageCanonical, '/'));
}
$ogImageAbs = preg_match('#^https?://#i', $metaOgImage) ? $metaOgImage : ($baseUrl . '/' . ltrim($metaOgImage, '/'));
$metaFullTitle = (!empty($pageTitle) ? $pageTitle . ' - ' : '') . $siteName;
// 🔢 মেটা/সোশ্যাল লেখাগুলো অ্যাট্রিবিউটের ভেতরে বসে বলে আউটপুট-ফিল্টার (digits_filter_html)
// এদের ছোঁয় না — কিন্তু গুগল/ফেসবুকে এগুলোই দেখা যায়, তাই এখানে আলাদা করে English অঙ্ক।
$metaFullTitle   = en_digits($metaFullTitle);
$metaDescription = en_digits($metaDescription);
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($metaFullTitle) ?></title>

    <!-- SEO -->
    <meta name="description" content="<?= e($metaDescription) ?>">
    <?php // 🔴 ৪০৪/সার্চের মতো পেজ ইনডেক্স করানো উচিত নয় — `$pageNoIndex = true;` দিলে
          // canonical-ও বাদ যায় (নাহলে অস্তিত্বহীন URL-টাই canonical হিসেবে যেত)। ?>
    <?php if (!empty($pageNoIndex)): ?>
        <meta name="robots" content="noindex, follow">
    <?php else: ?>
        <link rel="canonical" href="<?= e($canonicalUrl) ?>">
    <?php endif; ?>
    <meta name="theme-color" content="<?= e($metaThemeColor) ?>">
    <link rel="icon" href="<?= e($logoPath) ?>">
    <link rel="apple-touch-icon" href="<?= e($logoPath) ?>">

    <!-- Open Graph (Facebook / WhatsApp শেয়ার প্রিভিউ) -->
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e($siteName) ?>">
    <meta property="og:title" content="<?= e($metaFullTitle) ?>">
    <meta property="og:description" content="<?= e($metaDescription) ?>">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta property="og:image" content="<?= e($ogImageAbs) ?>">

    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= e($metaFullTitle) ?>">
    <meta name="twitter:description" content="<?= e($metaDescription) ?>">
    <meta name="twitter:image" content="<?= e($ogImageAbs) ?>">

    <?php // 🔍 Structured data — সব পাবলিক পেজে প্রতিষ্ঠানের পরিচয়, আর পেজ চাইলে নিজেরটা
          // ($pageJsonLd-এ বসিয়ে; detail/faqs/reviews এভাবেই দেয়)। বিস্তারিত functions.php-এ। ?>
    <?= jsonld_organization() ?>
    <?= $pageJsonLd ?? '' ?>

    <!-- সেল্ফ-হোস্টেড কম্পাইলড Tailwind (আগে cdn.tailwindcss.com JIT ছিল — এখন স্ট্যাটিক CSS, দ্রুত + ঝলকমুক্ত) -->
    <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/css/tailwind.css') ?: '1' ?>">
    <?php // 🔴 `defer` — আগে এটা render-blocking ছিল (লেখা দেখানোর আগে স্ক্রিপ্টের জন্য অপেক্ষা)।
          // আইকন বসানোর কল site-footer.php-এ, পেজের একদম নিচে — তাই defer-এ সমস্যা হয় না। ?>
    <script defer src="assets/js/lucide.js?v=<?= @filemtime(__DIR__ . '/../assets/js/lucide.js') ?: '1' ?>"></script>

    <?php // 🔤 ফন্ট এখন **সেল্ফ-হোস্টেড** (২০২৬-০৯-২৭) — আগে fonts.googleapis.com থেকে আসত, যেটা
          // বাইরের দুটো রাউন্ড-ট্রিপ (CSS তারপর woff2) যোগ করত। ফাইল assets/fonts/<id>.(css|woff2)।
          // ফলব্যাক: কোনো কারণে ফাইলটা না থাকলে পুরনো গুগল-লিংকই ব্যবহার হয় (ফন্ট কখনো ভাঙে না)। ?>
    <?php $fontCss = __DIR__ . '/../assets/fonts/' . $siteFontId . '.css'; ?>
    <?php if (is_file($fontCss)): ?>
        <link rel="stylesheet" href="assets/fonts/<?= e($siteFontId) ?>.css?v=<?= @filemtime($fontCss) ?: '1' ?>">
    <?php else: ?>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=<?= e($siteFont['google']) ?>&display=swap" rel="stylesheet">
    <?php endif; ?>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= @filemtime(__DIR__ . '/../assets/css/style.css') ?: '1' ?>">
    <style>
        /* অ্যাডমিন-নিয়ন্ত্রিত সাইট থিম — এই ভ্যারিয়েবলগুলো থেকে সব ব্র্যান্ড রঙ আসে (style.css + inline) */
        :root {
            --c-primary: <?= implode(' ', $sitePalette['primary']) ?>;
            --c-primary-2: <?= implode(' ', $sitePalette['primary2']) ?>;
            --c-tint: <?= implode(' ', $sitePalette['tint']) ?>;
            --c-border: <?= implode(' ', $sitePalette['border']) ?>;
            --c-deep: <?= implode(' ', $sitePalette['deep']) ?>;
            --site-font: <?= $siteFont['stack'] ?>;
        }
        body { font-family: var(--site-font); }
        /* অ্যাক্সেসিবিলিটি: ব্যবহারকারী ডিভাইসে "reduce motion" চালু থাকলে অ্যানিমেশন/ট্রানজিশন প্রায় বন্ধ */
        @media (prefers-reduced-motion: reduce) {
            *, ::before, ::after {
                animation-duration: .001ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001ms !important;
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    <?php // ♿ "সরাসরি মূল লেখায় যান" — কীবোর্ড/স্ক্রিন-রিডার ব্যবহারকারীকে পুরো মেনু-লিংক
          // পেরোতে হয় না। ডিফল্টে অদৃশ্য, Tab চাপলে দেখা যায় (স্টাইল style.css-এর `.skip-link`)। ?>
    <a href="#main-content" class="skip-link">সরাসরি মূল লেখায় যান</a>
    <header class="header-glass sticky top-0 z-40">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center py-4">
                <a href="./" class="flex items-center space-x-4">
                    <img src="<?= e($logoPath) ?>" alt="<?= e($siteName) ?> Logo" class="w-12 h-12 sm:w-14 sm:h-14 rounded-xl shadow-lg object-cover">
                    <div>
                        <h1 class="text-xl sm:text-2xl font-black text-gray-800"><?= e($siteName) ?></h1>
                        <p class="text-xs sm:text-sm text-gray-600 font-medium"><?= e($siteTagline) ?></p>
                    </div>
                </a>

                <nav class="hidden lg:flex space-x-2">
                    <?php foreach ($navigation as $navItem): ?>
                        <a href="<?= e($navItem['url']) ?>" class="nav-item flex items-center space-x-2 px-3 py-2 rounded-xl font-medium transition-all text-sm <?= $activePage === $navItem['id'] ? 'active' : '' ?>">
                            <i data-lucide="<?= e($navItem['icon']) ?>" class="w-4 h-4"></i>
                            <span><?= e($navItem['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <div class="flex items-center gap-2">
                    <?php // 🔎 সার্চ — ডেস্কটপ ও মোবাইল দুটোতেই আইকন হিসেবে (২০২৬-০৯-২৭)।
                          // 🔴 উপরের মেনুতে টেক্সট-আইটেম হিসেবে যোগ করা হয়নি — ওটা আইকনেই যথেষ্ট,
                          //    আর মেনুর জায়গা কম (CLAUDE.md-এর নিয়ম)। নিচের স্টিকি বারেও নয় (৫ স্লট পূর্ণ)। ?>
                    <a href="search" class="p-3 rounded-xl glass-effect hover:bg-white/30 transition-colors" aria-label="খুঁজুন" title="খুঁজুন">
                        <i data-lucide="search" class="w-6 h-6 text-gray-700"></i>
                    </a>
                    <button id="mobile-menu-btn" class="lg:hidden p-3 rounded-xl glass-effect hover:bg-white/30 transition-colors" aria-label="মেনু" aria-expanded="false" aria-controls="mobile-nav">
                        <i data-lucide="menu" class="w-6 h-6 text-gray-700"></i>
                    </button>
                </div>
            </div>

            <nav id="mobile-nav" class="lg:hidden py-4 border-t border-white/20 hidden">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <?php foreach ($navigation as $navIndex => $navItem): $mmActive = $activePage === $navItem['id']; ?>
                        <a href="<?= e($navItem['url']) ?>" style="--mm-i: <?= (int) $navIndex ?>" class="mobile-menu-item relative flex flex-col sm:flex-row items-center space-y-1 sm:space-y-0 sm:space-x-2 px-3 py-3 rounded-xl transition-all font-medium text-sm <?= $navItem['id'] === 'account' ? 'mobile-menu-login' : '' ?> <?= $mmActive ? 'mm-on text-white' : '' ?>">
                            <span class="mm-ic"><i data-lucide="<?= e($navItem['icon']) ?>" class="w-4 h-4"></i></span>
                            <span class="text-xs sm:text-sm"><?= e($navItem['label']) ?></span>
                            <?php if ($mmActive): ?><span class="mm-now">● এখন</span><?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>
        </div>
    </header>

    <main id="main-content" tabindex="-1" class="container mx-auto px-4 py-8">
        <div class="fade-in">
