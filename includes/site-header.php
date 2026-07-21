<?php
// পাবলিক সাইটের কমন হেডার — প্রতিটা পাবলিক পেজে include হয়
// এই ফাইল include করার আগে $pageTitle (string) এবং $activePage (nav id) সেট করে নিতে হবে

require_once __DIR__ . '/functions.php';

log_visitor();

// ক্লিন URL (.htaccess রিরাইট) — সব লিংক এখন extensionless, home = './' (সাবডিরেক্টরি/রুট দুই জায়গাতেই কাজ করে)
$navigation = [
    ['id' => 'home', 'label' => 'Home', 'icon' => 'home', 'url' => './'],
    ['id' => 'courses', 'label' => 'Our Course', 'icon' => 'book-open', 'url' => 'courses'],
    ['id' => 'worksheets', 'label' => 'Our Worksheet', 'icon' => 'file-text', 'url' => 'worksheets'],
    ['id' => 'products', 'label' => 'Our Products', 'icon' => 'shopping-bag', 'url' => 'products'],
    ['id' => 'notice', 'label' => 'Notice', 'icon' => 'bell', 'url' => 'notice'],
    ['id' => 'teachers', 'label' => 'Teachers', 'icon' => 'users', 'url' => 'teachers'],
    ['id' => 'reviews', 'label' => 'Reviews', 'icon' => 'star', 'url' => 'reviews'],
    ['id' => 'about', 'label' => 'About', 'icon' => 'info', 'url' => 'about'],
    ['id' => 'gallery', 'label' => 'Gallery', 'icon' => 'image', 'url' => 'gallery'],
    ['id' => 'faqs', 'label' => 'FAQs', 'icon' => 'help-circle', 'url' => 'faqs'],
];

$activePage = $activePage ?? '';
$siteName = get_setting('site_name', 'EduCenter');
$siteTagline = get_setting('site_tagline', 'শিক্ষার আলো');
$logoPath = get_setting('logo_path', 'https://i.postimg.cc/T3FzJyxM/logo.png');

// সাইট থিম ও ফন্ট (অ্যাডমিন-নিয়ন্ত্রিত, site-wide) — প্যালেট CSS ভ্যারিয়েবলে বসানো হয় নিচে
$sitePalette = get_active_site_palette();
$siteFonts = get_site_fonts();
$siteFontId = get_setting('site_font') ?: 'noto';
$siteFont = $siteFonts[$siteFontId] ?? $siteFonts['noto'];

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
$ogImageAbs = preg_match('#^https?://#i', $metaOgImage) ? $metaOgImage : ($baseUrl . '/' . ltrim($metaOgImage, '/'));
$metaFullTitle = (isset($pageTitle) ? $pageTitle . ' - ' : '') . $siteName;
?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($metaFullTitle) ?></title>

    <!-- SEO -->
    <meta name="description" content="<?= e($metaDescription) ?>">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">
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

    <!-- সেল্ফ-হোস্টেড কম্পাইলড Tailwind (আগে cdn.tailwindcss.com JIT ছিল — এখন স্ট্যাটিক CSS, দ্রুত + ঝলকমুক্ত) -->
    <link rel="stylesheet" href="assets/css/tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/css/tailwind.css') ?: '1' ?>">
    <script src="assets/js/lucide.js?v=<?= @filemtime(__DIR__ . '/../assets/js/lucide.js') ?: '1' ?>"></script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=<?= e($siteFont['google']) ?>&display=swap" rel="stylesheet">
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

                <button id="mobile-menu-btn" class="lg:hidden p-3 rounded-xl glass-effect hover:bg-white/30 transition-colors">
                    <i data-lucide="menu" class="w-6 h-6 text-gray-700"></i>
                </button>
            </div>

            <nav id="mobile-nav" class="lg:hidden py-4 border-t border-white/20 hidden">
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                    <?php foreach ($navigation as $navItem): ?>
                        <a href="<?= e($navItem['url']) ?>" class="mobile-menu-item flex flex-col sm:flex-row items-center space-y-1 sm:space-y-0 sm:space-x-2 px-3 py-3 rounded-xl transition-all font-medium text-sm <?= $activePage === $navItem['id'] ? 'mm-on text-white' : '' ?>">
                            <i data-lucide="<?= e($navItem['icon']) ?>" class="w-4 h-4"></i>
                            <span class="text-xs sm:text-sm"><?= e($navItem['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </nav>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8">
        <div class="fade-in">
