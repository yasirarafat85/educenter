<?php
// ── সাইডবার নেভিগেশন: লিংকের তালিকা (ডেটা) + ⭐ "প্রিয়" পিন ব্যবস্থাপনা
//
// কেন ডেটা-চালিত (২০২৬-০৯-২৩): আগে প্রতিটা লিংক layout-top.php-এ হাতে লেখা <a> ছিল, তাই
// "বেশি দরকারি লিংক উপরে তুলুন" বানানো যেত না। এখন একটা অ্যারে থেকে রেন্ডার হয় — ফলে পিন করা
// লিংকগুলো উপরে "⭐ প্রিয়" সেকশনে তুলে দেওয়া যায়।
//
// 🔴 নতুন অ্যাডমিন পেজ যোগ করলে এখানেই একটা এন্ট্রি যোগ করুন (layout-top.php-এ নয়) — সাথে
// permissions.php-এর ম্যাপ/সেকশনও (নাহলে গার্ড fail-closed করে পেজটা আটকে দেবে)।
//
// 🔴 পিন কোথায় সেভ হয়: `settings` টেবিলে `admin_nav_pins_<admin_id>` কী-তে JSON array।
// ইচ্ছাকৃতভাবে localStorage নয় — ইউজার ফোন ও পিসি দুটোতেই কাজ করেন, পিন দুই জায়গায় এক থাকা চাই।
// `settings` আগে থেকেই key-value টেবিল বলে **কোনো DB মাইগ্রেশন লাগে না**।

require_once __DIR__ . '/entities.php';
require_once __DIR__ . '/permissions.php';

// সেট করা না থাকলে এই চারটা পিন করা অবস্থায় শুরু হয় (ইউজারের নিজের বলা "রোজ যেগুলো বেশি লাগে")।
// ⚠️ খালি অ্যারে সেভ করা আর "কখনো সেভ করাই হয়নি" — দুটো আলাদা: সেটিং-কী না থাকলেই কেবল
// এই ডিফল্ট ফেরে, একবার সেভ হয়ে গেলে (এমনকি খালি হলেও) ইউজারের নিজের তালিকাই চলে।
function nav_default_pins(): array
{
    return ['registrations', 'course-parcel', 'courier', 'finance'];
}

function nav_pins_setting_key(): string
{
    return 'admin_nav_pins_' . (int) ($_SESSION['admin_id'] ?? 0);
}

function nav_pins_get(): array
{
    $raw = get_setting(nav_pins_setting_key());
    if ($raw === '') {
        return nav_default_pins();     // এখনো একবারও সাজানো হয়নি
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        return nav_default_pins();     // করাপ্ট মান — ডিফল্টেই ফিরে যাই, পেজ ভাঙি না
    }
    $out = [];
    foreach ($list as $k) {
        if (is_string($k) && $k !== '' && !in_array($k, $out, true)) {
            $out[] = $k;
        }
    }
    return $out;
}

function nav_pins_save(array $pins): void
{
    update_setting(nav_pins_setting_key(), json_encode(array_values($pins), JSON_UNESCAPED_UNICODE));
}

// pin / unpin / up / down — অজানা key বা অ্যাকশন নীরবে উপেক্ষা করে (কিছু ভাঙে না)
function nav_pins_apply(string $action, string $key): void
{
    $pins = nav_pins_get();
    $i    = array_search($key, $pins, true);

    if ($action === 'pin') {
        if ($i === false) { $pins[] = $key; }
    } elseif ($action === 'unpin') {
        if ($i !== false) { array_splice($pins, $i, 1); }
    } elseif (($action === 'up' || $action === 'down') && $i !== false) {
        $j = $action === 'up' ? $i - 1 : $i + 1;
        if ($j >= 0 && $j < count($pins)) {
            [$pins[$i], $pins[$j]] = [$pins[$j], $pins[$i]];
        }
    } elseif ($action === 'reset') {
        $pins = nav_default_pins();
    } else {
        return;
    }
    nav_pins_save($pins);
}

// ── সব নেভ লিংক, সেকশন অনুযায়ী সাজানো। প্রতিটা এন্ট্রি:
//    key (স্থায়ী পরিচয়, পিনে এটাই সেভ হয়) / href / file+entity (nav_active-এর জন্য) / icon / label
// শুধু সেই লিংকগুলোই থাকে যেগুলো এই অ্যাডমিন দেখার অনুমতি পান — তাই পিন দিয়ে কেউ
// লুকানো পেজে পৌঁছাতে পারে না (পিন করা key অনুমতি-তালিকায় না থাকলে এমনিতেই রেন্ডার হয় না)।
function admin_nav_groups(): array
{
    $groups  = [];
    $content = [];
    foreach (get_entities() as $navEntityKey => $navEntityConf) {
        if (admin_can('content:' . $navEntityKey)) {
            $content[] = [
                'key'    => 'manage:' . $navEntityKey,
                'href'   => 'manage.php?entity=' . rawurlencode($navEntityKey),
                'file'   => 'manage.php',
                'entity' => $navEntityKey,
                'icon'   => 'file-text',
                'label'  => $navEntityConf['label_plural'],
            ];
        }
    }

    $orders = [];
    if (admin_can('orders')) {
        $orders[] = ['key' => 'registrations',    'href' => 'registrations.php',    'file' => 'registrations.php',    'icon' => 'clipboard-list',    'label' => 'রেজিস্ট্রেশন/অর্ডার'];
        $orders[] = ['key' => 'course-data',      'href' => 'course-data.php',      'file' => 'course-data.php',      'icon' => 'table',             'label' => 'ডেটা টেবিল'];
        $orders[] = ['key' => 'course-interests', 'href' => 'course-interests.php', 'file' => 'course-interests.php', 'icon' => 'heart-handshake',   'label' => 'আগ্রহ তালিকা'];
        $orders[] = ['key' => 'legacy-students',  'href' => 'legacy-students.php',  'file' => 'legacy-students.php',  'icon' => 'user-round-search', 'label' => 'পুরাতন শিক্ষার্থী'];
    }
    if (admin_can('parcel')) {
        $orders[] = ['key' => 'course-parcel', 'href' => 'course-parcel.php', 'file' => 'course-parcel.php', 'icon' => 'package-check', 'label' => 'কোর্স পার্সেল'];
    }
    if (admin_can('users')) {
        $orders[] = ['key' => 'users', 'href' => 'users.php', 'file' => 'users.php', 'icon' => 'users', 'label' => 'অভিভাবক অ্যাকাউন্ট'];
    }
    if (admin_can('courier')) {
        $orders[] = ['key' => 'courier',          'href' => 'courier.php',          'file' => 'courier.php',          'icon' => 'truck',          'label' => 'কুরিয়ার'];
        $orders[] = ['key' => 'courier-tracking', 'href' => 'courier-tracking.php', 'file' => 'courier-tracking.php', 'icon' => 'calendar-check', 'label' => 'কুরিয়ার ট্র্যাকিং'];
    }

    $logs = [];
    if (admin_can('logs')) {
        $logs[] = ['key' => 'registration-errors', 'href' => 'registration-errors.php', 'file' => 'registration-errors.php', 'icon' => 'alert-triangle', 'label' => 'রেজিস্ট্রেশন এরর'];
        $logs[] = ['key' => 'download-logs',       'href' => 'download-logs.php',       'file' => 'download-logs.php',       'icon' => 'download',       'label' => 'ডাউনলোড লগ'];
        $logs[] = ['key' => 'visitor-logs',        'href' => 'visitor-logs.php',        'file' => 'visitor-logs.php',        'icon' => 'footprints',     'label' => 'ভিজিটর লগ'];
    }
    if (admin_can('courier')) {
        $logs[] = ['key' => 'courier-shipment-logs', 'href' => 'courier-shipment-logs.php', 'file' => 'courier-shipment-logs.php', 'icon' => 'history', 'label' => 'কুরিয়ার শিপমেন্ট লগ'];
    }

    $finance = [];
    if (admin_can('finance')) {
        $finance[] = ['key' => 'finance',  'href' => 'finance.php',  'file' => 'finance.php',  'icon' => 'pie-chart',      'label' => 'আয়-ব্যয় ড্যাশবোর্ড'];
        $finance[] = ['key' => 'income',   'href' => 'income.php',   'file' => 'income.php',   'icon' => 'trending-up',    'label' => 'আয়'];
        $finance[] = ['key' => 'expenses', 'href' => 'expenses.php', 'file' => 'expenses.php', 'icon' => 'trending-down',  'label' => 'খরচ'];
    }

    $settings = [];
    if (admin_can('settings')) {
        $settings[] = ['key' => 'settings', 'href' => 'settings.php', 'file' => 'settings.php', 'icon' => 'settings', 'label' => 'সাইট সেটিংস'];
    }
    if (admin_can('payment')) {
        $settings[] = ['key' => 'payment-methods', 'href' => 'payment-methods.php', 'file' => 'payment-methods.php', 'icon' => 'wallet', 'label' => 'পেমেন্ট মেথড'];
    }
    if (admin_is_super()) {
        $settings[] = ['key' => 'team', 'href' => 'team.php', 'file' => 'team.php', 'icon' => 'user-cog', 'label' => 'টিম / মডারেটর'];
    }
    $settings[] = ['key' => 'change-password', 'href' => 'change-password.php', 'file' => 'change-password.php', 'icon' => 'key',         'label' => 'পাসওয়ার্ড পরিবর্তন'];
    $settings[] = ['key' => 'security',        'href' => 'security.php',        'file' => 'security.php',        'icon' => 'fingerprint', 'label' => 'নিরাপত্তা / ফিঙ্গারপ্রিন্ট'];
    if (admin_can('backup')) {
        $settings[] = ['key' => 'backup', 'href' => 'backup.php', 'file' => 'backup.php', 'icon' => 'hard-drive-download', 'label' => 'ব্যাকআপ ও ডাউনলোড'];
    }
    if (admin_can('archive')) {
        $settings[] = ['key' => 'archive', 'href' => 'archive.php', 'file' => 'archive.php', 'icon' => 'archive', 'label' => 'আর্কাইভ (রিস্টোর)'];
    }

    $groups[] = ['section' => '', 'items' => [
        ['key' => 'index', 'href' => 'index.php', 'file' => 'index.php', 'icon' => 'layout-dashboard', 'label' => 'ড্যাশবোর্ড'],
        ['key' => 'guide', 'href' => 'guide.php', 'file' => 'guide.php', 'icon' => 'help-circle',      'label' => 'গাইড / সাহায্য'],
    ]];
    $groups[] = ['section' => 'কনটেন্ট', 'items' => $content];
    $groups[] = ['section' => 'অর্ডার',   'items' => $orders];
    $groups[] = ['section' => 'লগ',       'items' => $logs];
    $groups[] = ['section' => 'আয়-ব্যয়', 'items' => $finance];
    $groups[] = ['section' => 'সেটিংস',   'items' => $settings];

    return array_values(array_filter($groups, fn($g) => !empty($g['items'])));
}

// পিন করা লিংকগুলো আলাদা করে ফেরায়: [পিন-করা (পিনের ক্রমে), বাকি গ্রুপগুলো (পিন বাদ দিয়ে)]
function admin_nav_split(array $groups, array $pins): array
{
    $byKey = [];
    foreach ($groups as $g) {
        foreach ($g['items'] as $it) { $byKey[$it['key']] = $it; }
    }
    $pinned = [];
    foreach ($pins as $k) {
        if (isset($byKey[$k])) { $pinned[] = $byKey[$k]; }   // অনুমতি নেই এমন key এখানেই বাদ পড়ে
    }
    $rest = [];
    foreach ($groups as $g) {
        $items = array_values(array_filter($g['items'], fn($it) => !in_array($it['key'], $pins, true)));
        if ($items) { $rest[] = ['section' => $g['section'], 'items' => $items]; }
    }
    return [$pinned, $rest];
}

// অ্যাডমিন প্যানেলের ভেতরের রিলেটিভ URL ছাড়া কোথাও রিডাইরেক্ট করা যাবে না
// (খোলা রিডাইরেক্ট ঠেকাতে — স্কিম/প্রোটোকল-রিলেটিভ/ব্যাকস্ল্যাশ সবই বাদ)
function nav_safe_return(string $url, string $fallback = 'index.php'): string
{
    $url = trim($url);
    if ($url === '' || strlen($url) > 300) { return $fallback; }
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) { return $fallback; }   // হেডার-ইনজেকশন (newline) ঠেকাতে
    if ($url[0] === '/' || strpos($url, '\\') !== false || strpos($url, '//') !== false) { return $fallback; }
    if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*:#', $url)) { return $fallback; }
    if (!preg_match('#^[A-Za-z0-9_-]+\.php(\?.*)?$#', $url)) { return $fallback; }
    return $url;
}

// একটা ★/☆/↑/↓ বোতাম = একটা ছোট POST ফর্ম (এই কোডবেসে AJAX নেই — গ্রুপের টিকের মতোই)।
// $disabled হলে বোতামের বদলে ফিকে <span> বসে (তালিকার প্রথম/শেষে ↑/↓ অর্থহীন)।
function nav_pin_form(string $action, string $key, string $glyph, string $title, string $returnUrl, bool $disabled = false, bool $on = false): string
{
    if ($disabled) {
        return '<span class="nav-pin"><span>' . e($glyph) . '</span></span>';
    }
    return '<form method="post" action="nav-pins.php" class="nav-pin' . ($on ? ' on' : '') . '">'
        . csrf_field()
        . '<input type="hidden" name="pin_action" value="' . e($action) . '">'
        . '<input type="hidden" name="key" value="' . e($key) . '">'
        . '<input type="hidden" name="return_url" value="' . e($returnUrl) . '">'
        . '<button type="submit" title="' . e($title) . '">' . e($glyph) . '</button>'
        . '</form>';
}
