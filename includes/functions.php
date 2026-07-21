<?php
// সাধারণ হেল্পার ফাংশন — admin panel ও public site দুটোতেই ব্যবহার হয়

require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    // সেশন কুকি হার্ডেনিং — HttpOnly (JS দিয়ে কুকি পড়া যাবে না), SameSite (CSRF ঠেকাতে সাহায্য করে),
    // Secure (HTTPS এ থাকলে শুধু HTTPS এই কুকি পাঠানো হবে)
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// বেসিক সিকিউরিটি হেডার — সব পেজেই প্রযোজ্য (clickjacking, MIME-sniffing ঠেকাতে)
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// HTML আউটপুটে নিরাপদভাবে টেক্সট বসানোর শর্টকাট (XSS প্রতিরোধ)
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

// ফ্ল্যাশ মেসেজ (এক-বার দেখানোর জন্য, যেমন "সফলভাবে সেভ হয়েছে")
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!empty($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

// CSRF টোকেন
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}

// সব সেটিংস key-value আকারে লোড করা (ক্যাশ করা হয় একই রিকোয়েস্টে বারবার কোয়েরি এড়াতে)
function get_all_settings(): array
{
    static $settings = null;

    if ($settings === null) {
        $settings = [];
        $stmt = get_db()->query('SELECT setting_key, setting_value FROM settings');
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return $settings;
}

function get_setting(string $key, string $default = ''): string
{
    $settings = get_all_settings();
    return $settings[$key] ?? $default;
}

function update_setting(string $key, string $value): void
{
    $stmt = get_db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:k, :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute(['k' => $key, 'v' => $value]);
}

// about.php এর ডিফল্ট কনটেন্ট (একবারই ডিফাইন করা — about.php ও admin/settings.php দুটোতেই ব্যবহার হয়,
// যাতে setting খালি স্ট্রিং দিয়ে সেভ হয়ে থাকলেও পেজ ফাঁকা না দেখায় এবং অ্যাডমিন ফর্মেও এই টেক্সটই prefill থাকে)
function about_page_defaults(): array
{
    return [
        'about_mission_text'   => 'আমরা বিশ্বাস করি যে প্রতিটি শিক্ষার্থীর মধ্যে অসীম সম্ভাবনা রয়েছে। আমাদের লক্ষ্য হচ্ছে উন্নতমানের শিক্ষার মাধ্যমে সেই সম্ভাবনাকে বাস্তবায়িত করা। আমরা আধুনিক শিক্ষা পদ্ধতি এবং অভিজ্ঞ শিক্ষকদের মাধ্যমে শিক্ষার্থীদের একাডেমিক এবং ব্যক্তিত্ব উভয় ক্ষেত্রে উন্নতি সাধনে সহায়তা করি।',
        'about_vision_text'    => 'গুণগত শিক্ষার মাধ্যমে প্রতিটি শিক্ষার্থীর সম্ভাবনা বিকশিত করা এবং তাদের ভবিষ্যতের জন্য প্রস্তুত করা। আমাদের স্বপ্ন একটি শিক্ষিত ও দক্ষ জাতি গঠন।',
        'about_team_text'      => 'অভিজ্ঞ এবং নিবেদিতপ্রাণ শিক্ষকদের একটি দল যারা প্রতিটি শিক্ষার্থীর সফলতার জন্য প্রতিশ্রুতিবদ্ধ। আমাদের শিক্ষকরা শুধু পড়ান না, স্বপ্ন দেখান।',
        'about_feature1_title' => 'ইন্টারঅ্যাক্টিভ ক্লাস',
        'about_feature1_text'  => 'আধুনিক প্রযুক্তি ব্যবহার করে ইন্টারঅ্যাক্টিভ এবং মজাদার ক্লাস',
        'about_feature2_title' => '২৪/৭ সাপোর্ট',
        'about_feature2_text'  => 'যেকোনো সময় প্রশ্ন করুন, আমাদের টিম সর্বদা প্রস্তুত',
        'about_feature3_title' => 'লক্ষ্যভিত্তিক শিক্ষা',
        'about_feature3_text'  => 'প্রতিটি শিক্ষার্থীর ব্যক্তিগত লক্ষ্য অনুযায়ী কাস্টমাইজড প্ল্যান',
    ];
}

// about_* setting এর মান — সেভ করা থাকলে সেটাই, খালি/না-থাকলে ডিফল্ট টেক্সট
function get_about_setting(string $key): string
{
    $value = get_setting($key);
    return $value !== '' ? $value : (about_page_defaults()[$key] ?? '');
}

// বাংলাদেশি মোবাইল নম্বর ফরম্যাট যাচাই (register-submit.php ও course-register-submit.php দুটোতেই ব্যবহার হয়)
function is_valid_bd_phone(string $phone): bool
{
    return (bool) preg_match('/^01[3-9][0-9]{8}$/', $phone);
}

// "৳২,৫০০" এর মতো বাংলা/টেক্সট প্রাইস স্ট্রিং থেকে সংখ্যা বের করা (আয়-ব্যয় হিসাবের জন্য)
function parse_price_to_number(?string $price): float
{
    $price = $price ?? '';
    $bn = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];
    $en = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    $price = str_replace($bn, $en, $price);
    $clean = preg_replace('/[^0-9.]/', '', $price);
    return $clean === '' ? 0.0 : (float) $clean;
}

// কুরিয়ার কালেকশন অটো-হিসাব (COURIER-REDESIGN-PLAN.md ধাপ ২):
// মান্থলি ফি (কোর্স-ফি × multiplier) + ডেলিভারি জোন প্রিসেট + (ওজন বেশি হলে) ওজন-এক্সট্রা + সমন্বয়(±)।
// $zone: 'dhaka'|'near'|'outside' (settings key courier_dc_<zone>)। ফলাফল নন-নেগেটিভ, রাউন্ডেড।
function courier_compute_collection(float $courseFee, float $multiplier, string $zone, bool $weightExtra, float $adjustment): float
{
    $monthly  = $courseFee * $multiplier;
    $delivery = (float) (get_setting('courier_dc_' . $zone) ?: 0);
    $weight   = $weightExtra ? (float) (get_setting('courier_weight_extra') ?: 0) : 0.0;
    return max(0.0, round($monthly + $delivery + $weight + $adjustment));
}

// টাইপ (income/expense) অনুযায়ী সব ক্যাটেগরি লোড করা
function get_finance_categories(string $type): array
{
    $stmt = get_db()->prepare('SELECT * FROM finance_categories WHERE type = :type ORDER BY is_system DESC, name ASC');
    $stmt->execute(['type' => $type]);
    return $stmt->fetchAll();
}

// নাম দিয়ে ক্যাটেগরি খোঁজা, না থাকলে নতুন তৈরি করে ID রিটার্ন করা (কেস-ইনসেনসিটিভ)
function find_or_create_finance_category(string $type, string $name): int
{
    $name = trim($name);
    $db = get_db();
    $stmt = $db->prepare('SELECT id FROM finance_categories WHERE type = :type AND name = :name LIMIT 1');
    $stmt->execute(['type' => $type, 'name' => $name]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int) $existing['id'];
    }
    $ins = $db->prepare('INSERT INTO finance_categories (type, name, is_system) VALUES (:type, :name, 0)');
    $ins->execute(['type' => $type, 'name' => $name]);
    return (int) $db->lastInsertId();
}

// রেজিস্ট্রেশনের type (course/worksheet/product) থেকে সংশ্লিষ্ট system ক্যাটেগরির নাম
function registration_type_to_category_name(string $type): string
{
    return match ($type) {
        'course' => 'কোর্স',
        'worksheet' => 'ওয়ার্কশিট',
        'product' => 'প্রোডাক্ট',
        default => $type,
    };
}

// টাইটেল থেকে URL-friendly slug বানানো (বাংলা টাইটেলেও কাজ করবে)
function make_slug(string $text, int $fallbackId = 0): string
{
    $slug = trim($text);
    // \p{M} যোগ করা জরুরি — নাহলে বাংলা মাত্রা/হসন্তের মতো combining mark গুলো কেটে গিয়ে যুক্তাক্ষর ভেঙে যায়
    $slug = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', '-', $slug);
    $slug = trim($slug, '-');
    $slug = mb_strtolower($slug, 'UTF-8');
    if ($slug === '') {
        $slug = 'item-' . $fallbackId;
    }
    return $slug;
}

function format_date_bn(string $mysqlDate): string
{
    $months = [
        1 => 'জানুয়ারি', 2 => 'ফেব্রুয়ারি', 3 => 'মার্চ', 4 => 'এপ্রিল',
        5 => 'মে', 6 => 'জুন', 7 => 'জুলাই', 8 => 'আগস্ট',
        9 => 'সেপ্টেম্বর', 10 => 'অক্টোবর', 11 => 'নভেম্বর', 12 => 'ডিসেম্বর',
    ];
    $ts = strtotime($mysqlDate);
    if (!$ts) {
        return $mysqlDate;
    }
    return (int) date('d', $ts) . ' ' . $months[(int) date('n', $ts)] . ', ' . date('Y', $ts);
}

// প্রতিটা পাবলিক পেজের উপরের শিরোনাম অংশ — eyebrow badge + টাইটেল + সাবটাইটেল, সব পেজে একই প্যাটার্ন
function render_page_header(string $icon, string $eyebrow, string $title, string $subtitle, string $accentClass = 'text-gray-800'): string
{
    return '
    <div class="text-center mt-3 sm:mt-6 mb-12 sm:mb-16 section-heading">
        <span class="eyebrow-badge"><i data-lucide="' . e($icon) . '" class="w-4 h-4"></i> ' . e($eyebrow) . '</span>
        <h1 class="text-2xl sm:text-5xl font-black mb-3 sm:mb-4 ' . e($accentClass) . '">' . e($title) . '</h1>
        <p class="text-base sm:text-2xl text-gray-600 max-w-3xl mx-auto">' . e($subtitle) . '</p>
    </div>';
}

// ============================================================
// সাইট থিম ইঞ্জিন — অ্যাডমিন-নিয়ন্ত্রিত site-wide থিম (settings: site_theme / site_custom_primary / site_font)।
// একটা থিম = একটা প্রাইমারি রঙ; বাকি সব শেড (গ্রেডিয়েন্ট-এন্ড/টিন্ট/বর্ডার/গাঢ়) এখানে অটো-ডিরাইভ হয় —
// তাই রেডিমেড থিম আর কাস্টম রঙ দুটোই একই ইঞ্জিনে চলে।
// ============================================================

// রেডিমেড থিম প্রিসেট — [id => [label, primary hex]]
function get_site_themes(): array
{
    return [
        'green'  => ['label' => 'Fresh Green', 'primary' => '#16a34a'],
        'blue'   => ['label' => 'Trust Blue',  'primary' => '#2563eb'],
        'indigo' => ['label' => 'Indigo',      'primary' => '#4f46e5'],
        'purple' => ['label' => 'Royal Purple','primary' => '#7c3aed'],
        'teal'   => ['label' => 'Ocean Teal',  'primary' => '#0d9488'],
        'orange' => ['label' => 'Warm Orange', 'primary' => '#ea580c'],
        'rose'   => ['label' => 'Rose Pink',   'primary' => '#e11d48'],
        'cyan'   => ['label' => 'Sky Cyan',    'primary' => '#0891b2'],
    ];
}

// পাবলিক ফন্ট অপশন — [id => [label, google family param, css stack]]
function get_site_fonts(): array
{
    return [
        'hind'  => ['label' => 'Hind Siliguri (পরিষ্কার)', 'google' => 'Hind+Siliguri:wght@400;500;600;700', 'stack' => "'Hind Siliguri', sans-serif"],
        'noto'  => ['label' => 'Noto Sans Bengali (ডিফল্ট)', 'google' => 'Noto+Sans+Bengali:wght@400;500;600;700;800', 'stack' => "'Noto Sans Bengali', sans-serif"],
        'anek'  => ['label' => 'Anek Bangla (আধুনিক)', 'google' => 'Anek+Bangla:wght@400;500;600;700;800', 'stack' => "'Anek Bangla', sans-serif"],
        'baloo' => ['label' => 'Baloo Da 2 (গোল/কিডস)', 'google' => 'Baloo+Da+2:wght@400;500;600;700;800', 'stack' => "'Baloo Da 2', sans-serif"],
        'tiro'  => ['label' => 'Tiro Bangla (মার্জিত)', 'google' => 'Tiro+Bangla:ital@0;1', 'stack' => "'Tiro Bangla', serif"],
    ];
}

// hex → HSL (H 0-360, S/L 0-100)
function site_hex_to_hsl(string $hex): array
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    $r = hexdec(substr($hex, 0, 2)) / 255;
    $g = hexdec(substr($hex, 2, 2)) / 255;
    $b = hexdec(substr($hex, 4, 2)) / 255;
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l = ($max + $min) / 2;
    if ($max == $min) {
        $h = $s = 0;
    } else {
        $d = $max - $min;
        $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);
        if ($max == $r) {
            $h = ($g - $b) / $d + ($g < $b ? 6 : 0);
        } elseif ($max == $g) {
            $h = ($b - $r) / $d + 2;
        } else {
            $h = ($r - $g) / $d + 4;
        }
        $h /= 6;
    }
    return [$h * 360, $s * 100, $l * 100];
}

// HSL → RGB চ্যানেল ট্রিপলেট [r, g, b] (0-255)
function site_hsl_to_rgb(float $h, float $s, float $l): array
{
    $h /= 360;
    $s = max(0, min(100, $s)) / 100;
    $l = max(0, min(100, $l)) / 100;
    if ($s == 0) {
        $r = $g = $b = $l;
    } else {
        $hue2rgb = function ($p, $q, $t) {
            if ($t < 0) $t += 1;
            if ($t > 1) $t -= 1;
            if ($t < 1 / 6) return $p + ($q - $p) * 6 * $t;
            if ($t < 1 / 2) return $q;
            if ($t < 2 / 3) return $p + ($q - $p) * (2 / 3 - $t) * 6;
            return $p;
        };
        $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
        $p = 2 * $l - $q;
        $r = $hue2rgb($p, $q, $h + 1 / 3);
        $g = $hue2rgb($p, $q, $h);
        $b = $hue2rgb($p, $q, $h - 1 / 3);
    }
    return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
}

// একটা প্রাইমারি hex থেকে পুরো প্যালেট ডিরাইভ — প্রতিটা কী [r,g,b] চ্যানেল
function derive_site_palette(string $hex): array
{
    [$h, $s, $l] = site_hex_to_hsl($hex);
    return [
        'primary'  => site_hsl_to_rgb($h, $s, $l),
        'primary2' => site_hsl_to_rgb($h, $s, min($l + 12, 64)),   // গ্রেডিয়েন্টের হালকা মাথা
        'tint'     => site_hsl_to_rgb($h, min($s, 62), 96),        // খুব হালকা ব্যাকগ্রাউন্ড শেড
        'border'   => site_hsl_to_rgb($h, min($s, 58), 82),        // হালকা বর্ডার
        'deep'     => site_hsl_to_rgb($h, $s, max($l - 12, 24)),   // গাঢ় টেক্সট
    ];
}

// বর্তমানে সক্রিয় সাইট প্যালেট — কাস্টম রঙ থাকলে সেটা, নাহলে বাছাই করা প্রিসেট (ডিফল্ট green)।
// প্রতি রিকোয়েস্টে একবার হিসাব করে ক্যাশ করা হয়।
function get_active_site_palette(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $custom = trim((string) get_setting('site_custom_primary', ''));
    if ($custom !== '' && preg_match('/^#?[0-9a-fA-F]{6}$/', $custom)) {
        $hex = '#' . ltrim($custom, '#');
    } else {
        $themes = get_site_themes();
        $themeId = get_setting('site_theme') ?: 'green';
        $hex = $themes[$themeId]['primary'] ?? '#16a34a';
    }
    return $cached = derive_site_palette($hex);
}

// একটা [r,g,b] কে "rgb(r g b)" স্ট্রিং এ পরিণত করা (ঐচ্ছিক alpha সহ)
function site_rgb(array $c, ?float $alpha = null): string
{
    return $alpha === null
        ? 'rgb(' . $c[0] . ' ' . $c[1] . ' ' . $c[2] . ')'
        : 'rgb(' . $c[0] . ' ' . $c[1] . ' ' . $c[2] . ' / ' . $alpha . ')';
}

// পাবলিক কার্ড/ফর্ম/থ্যাংক-ইউ পেজের অ্যাকসেন্ট — এখন সক্রিয় সাইট থিম থেকে আসে (আগে হার্ডকোড সবুজ ছিল)।
// রিটার্ন: [gradient, solid, tint-bg, deep-text, border]। $type রাখা আছে ভবিষ্যতের জন্য।
function item_accent(string $type = ''): array
{
    $p = get_active_site_palette();
    return [
        'linear-gradient(135deg,' . site_rgb($p['primary2']) . ' 0%,' . site_rgb($p['primary']) . ' 100%)',
        site_rgb($p['primary']),
        site_rgb($p['tint']),
        site_rgb($p['deep']),
        site_rgb($p['border']),
    ];
}

// কোর্স/ওয়ার্কশিট/প্রোডাক্ট কার্ড রেন্ডার — courses.php, worksheets.php, products.php, index.php এ ব্যবহার হয়
// AI Master Bangladesh সাইটের মতো প্রাইসিং-কার্ড স্টাইল: প্রমিনেন্ট দাম, ✓ ফিচার লিস্ট, ফুল-উইডথ CTA
function render_item_card(array $item, string $type): string
{
    [$grad, $solid, $tint, , $border] = item_accent($type);
    $id = (int) $item['id'];

    // মেটা চিপ (কোর্স: মেয়াদ/প্রশিক্ষক · ওয়ার্কশিট: পৃষ্ঠা/লেভেল)
    $meta = '';
    $metaChip = function (string $icon, string $text) {
        return '<span class="inline-flex items-center gap-1 text-xs font-semibold text-gray-600 bg-gray-100 px-2.5 py-1 rounded-full"><i data-lucide="' . e($icon) . '" class="w-3.5 h-3.5"></i>' . e($text) . '</span>';
    };
    if ($type === 'course') {
        if (!empty($item['duration']))   $meta .= $metaChip('clock', $item['duration']);
        if (!empty($item['instructor'])) $meta .= $metaChip('user', $item['instructor']);
    } elseif ($type === 'worksheet') {
        if (!empty($item['pages'])) $meta .= $metaChip('file-text', $item['pages']);
        if (!empty($item['level'])) $meta .= $metaChip('target', $item['level']);
    }

    // ফিচার চেকমার্ক লিস্ট (কোর্স/প্রোডাক্টের নিজস্ব features টেবিল থেকে, সর্বোচ্চ ৫টা)
    $features = [];
    if ($type === 'course') {
        $fs = get_db()->prepare('SELECT feature_text FROM course_features WHERE batch_id = :id ORDER BY sort_order ASC LIMIT 5');
        $fs->execute(['id' => $id]);
        $features = array_column($fs->fetchAll(), 'feature_text');
    } elseif ($type === 'product') {
        $fs = get_db()->prepare('SELECT feature_text FROM product_features WHERE product_id = :id ORDER BY sort_order ASC LIMIT 5');
        $fs->execute(['id' => $id]);
        $features = array_column($fs->fetchAll(), 'feature_text');
    }
    $featureList = '';
    foreach ($features as $f) {
        $featureList .= '<li class="flex items-start gap-2 text-sm text-gray-600"><svg viewBox="0 0 24 24" fill="none" stroke="' . $solid . '" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4 mt-0.5 flex-shrink-0"><polyline points="20 6 9 17 4 12"/></svg><span>' . e($f) . '</span></li>';
    }
    $featuresHtml = $featureList ? '<ul class="space-y-2 mb-5">' . $featureList . '</ul>' : '';

    $registrationClosed = $type === 'course' && empty($item['registration_open']);
    $actionLabel = $registrationClosed ? 'রেজিস্ট্রেশন বন্ধ' : ($type === 'course' ? 'রেজিস্ট্রেশন করুন' : 'অর্ডার করুন');
    $actionUrl = $type === 'course' ? 'course-register?course_id=' . $id : 'register?type=' . e($type) . '&id=' . $id;
    $image = $item['image'] ?: 'https://placehold.co/400x300?text=No+Image';
    $closedBadge = $registrationClosed ? '<div class="absolute top-3 left-3 bg-gray-900/80 text-white px-3 py-1.5 rounded-full font-semibold text-xs">রেজিস্ট্রেশন বন্ধ</div>' : '';

    $ctaBtn = $registrationClosed
        ? '<span class="block w-full text-center py-3 px-4 rounded-xl font-bold bg-gray-200 text-gray-500 cursor-not-allowed">রেজিস্ট্রেশন বন্ধ</span>'
        : '<a href="' . $actionUrl . '" class="pricing-cta block w-full text-center py-3 px-4 rounded-xl font-bold text-white shadow-lg" style="background:' . $grad . '">' . $actionLabel . '</a>';

    return '
    <div class="pricing-card bg-white rounded-2xl shadow-lg overflow-hidden flex flex-col h-full" style="border:2px solid ' . $border . ';">
        <div class="relative">
            <img src="' . e($image) . '" alt="' . e($item['title']) . '" class="w-full object-cover bg-white" style="aspect-ratio:4/3;" loading="lazy">
            ' . $closedBadge . '
        </div>
        <div class="p-5 sm:p-6 flex flex-col flex-1">
            <h3 class="text-lg font-black text-gray-900 mb-1.5 leading-snug">' . e($item['title']) . '</h3>
            <p class="text-gray-500 text-sm mb-3 leading-relaxed">' . e(mb_strimwidth($item['description'] ?? '', 0, 90, '...')) . '</p>
            ' . ($meta ? '<div class="flex flex-wrap gap-1.5 mb-4">' . $meta . '</div>' : '') . '
            ' . $featuresHtml . '
            <div class="mt-auto pt-2">
                <div class="flex items-baseline gap-1 mb-4">
                    <span class="text-3xl font-black" style="color:' . $solid . '">' . e($item['price'] ?? '') . '</span>
                </div>
                ' . $ctaBtn . '
                <a href="detail?type=' . e($type) . '&id=' . $id . '" class="block text-center mt-2.5 text-sm font-semibold text-gray-500 hover:text-gray-700">বিস্তারিত দেখুন →</a>
            </div>
        </div>
    </div>';
}

// "নাম্বার | লেবেল" ফরম্যাটের প্রতি-লাইন টেক্সট পার্স করে [{value, label}, ...] বানায়
// (অ্যাডমিন সেটিংসে পেমেন্ট নাম্বার ও WhatsApp নাম্বার একাধিক লাইনে রাখা যায়)
function parse_payment_lines(string $raw): array
{
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = explode('|', $line, 2);
        $value = trim($parts[0]);
        if ($value === '') {
            continue;
        }
        $out[] = ['value' => $value, 'label' => isset($parts[1]) ? trim($parts[1]) : ''];
    }
    return $out;
}

// পেমেন্ট চ্যানেলের প্রদর্শন-তথ্য: [বাংলা লেবেল, ব্র্যান্ড রঙ]
function payment_channel_meta(string $channel): array
{
    switch ($channel) {
        case 'bkash':    return ['বিকাশ', '#e2136e'];
        case 'nagad':    return ['নগদ', '#ec1c24'];
        case 'rocket':   return ['রকেট', '#8c3494'];
        case 'bank':     return ['ব্যাংক', '#1d4ed8'];
        case 'whatsapp': return ['WhatsApp', '#128c7e'];
        default:         return ['পেমেন্ট', '#374151'];
    }
}

// বাংলাদেশি নাম্বারকে WhatsApp-এর জন্য আন্তর্জাতিক ফরম্যাটে (দেশকোড ৮৮০ সহ) নরমালাইজ করা —
// লোকাল 01... নাম্বার WhatsApp রিজলভ করতে পারে না, ৮৮০ প্রিফিক্স দরকার (এটাই আগের বাগ ছিল)
function normalize_bd_whatsapp(string $raw): string
{
    $d = preg_replace('/[^0-9]/', '', $raw);
    if ($d === '') {
        return '';
    }
    if (substr($d, 0, 3) === '880') {
        return $d;              // ইতিমধ্যে আন্তর্জাতিক
    }
    if ($d[0] === '0') {
        return '88' . $d;       // 01... → 8801...
    }
    if (strlen($d) === 10) {
        return '880' . $d;      // 1721... → 8801721...
    }
    return $d;
}

// রেজিস্ট্রেশন/অর্ডার সফল হওয়ার পর দেখানো পেমেন্ট বক্স — সম্পূর্ণ অ্যাডমিন-নিয়ন্ত্রিত (admin/payment-methods.php,
// payment_methods টেবিল)। $itemType+$itemId দিলে শুধু সেই আইটেমে প্রযোজ্য (scope_all=1 বা scope_items-এ থাকা)
// পেমেন্ট নাম্বার/WhatsApp দেখায়। কিছু না মিললে খালি স্ট্রিং রিটার্ন করে (কোনো বক্স দেখায় না)।
function render_payment_box(string $itemType = '', int $itemId = 0): string
{
    try {
        $rows = get_db()->query('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) {
        return ''; // টেবিল না থাকলে (পুরনো ইনস্টল) চুপচাপ কিছু দেখায় না
    }

    $token = ($itemType !== '' && $itemId > 0) ? $itemType . ':' . $itemId : '';
    $numbers = [];
    $whatsapps = [];
    foreach ($rows as $r) {
        if (!$r['scope_all']) {
            $items = json_decode($r['scope_items'] ?? '[]', true) ?: [];
            if ($token === '' || !in_array($token, $items, true)) {
                continue; // এই আইটেমে দেখাবে না
            }
        }
        if ($r['channel'] === 'whatsapp') {
            $whatsapps[] = $r;
        } else {
            $numbers[] = $r;
        }
    }
    if (!$numbers && !$whatsapps) {
        return '';
    }

    $title = get_setting('payment_title') ?: 'এখনই পেমেন্ট করতে চান?';
    $note = get_setting('payment_note') ?: '';

    $numbersHtml = '';
    foreach ($numbers as $n) {
        [$chLabel, $chColor] = payment_channel_meta($n['channel']);
        $numbersHtml .= '
        <div class="bg-white border border-gray-200 rounded-xl p-2.5">
            <div class="flex flex-col sm:flex-row sm:items-center gap-2">
                <div class="flex items-center gap-2 min-w-0 flex-1">
                    <span class="flex-shrink-0 text-white text-xs font-bold px-2.5 py-1 rounded-lg" style="background:' . $chColor . ';">' . e($chLabel) . '</span>
                    <div class="min-w-0">
                        <p class="font-black text-gray-900 text-lg tracking-wide break-all">' . e($n['value']) . '</p>
                        ' . ($n['instruction'] ? '<p class="text-xs text-gray-500 leading-snug">' . e($n['instruction']) . '</p>' : '') . '
                    </div>
                </div>
                <button type="button" onclick="copyPaymentNumber(this, \'' . e(preg_replace('/[^0-9]/', '', $n['value'])) . '\')" class="w-full sm:w-auto flex-shrink-0 inline-flex items-center justify-center gap-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold px-3 py-2 rounded-lg text-sm">
                    <i data-lucide="copy" class="w-4 h-4"></i> <span>কপি করুন</span>
                </button>
            </div>
        </div>';
    }

    $waIcon = '<svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5 flex-shrink-0"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.149-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.626.712.226 1.36.194 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.548 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>';
    $whatsappHtml = '';
    foreach ($whatsapps as $w) {
        $intl = normalize_bd_whatsapp($w['value']);
        $waUrl = 'https://wa.me/' . $intl; // canonical click-to-chat লিংক (সবচেয়ে নির্ভরযোগ্য)
        $lbl = $w['instruction'] ?: 'WhatsApp এ পাঠান';
        $whatsappHtml .= '
        <a href="' . e($waUrl) . '" target="_blank" rel="noopener" class="flex items-center justify-center gap-2 text-white font-bold py-3 px-4 rounded-xl shadow-lg" style="background:linear-gradient(135deg,#25D366 0%,#128C7E 100%);">
            ' . $waIcon . '<span class="min-w-0 break-words text-center text-sm sm:text-base">' . e($lbl) . ' — ' . e($w['value']) . '</span>
        </a>';
    }

    return '
    <div class="max-w-xl mx-auto mt-5">
        <div class="rounded-2xl p-5 sm:p-6" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
            <h3 class="font-black text-gray-900 text-lg mb-1">' . e($title) . '</h3>
            ' . ($note ? '<p class="text-gray-500 text-sm mb-4">' . e($note) . '</p>' : '<div class="mb-4"></div>') . '
            ' . ($numbersHtml ? '<div class="space-y-2.5 mb-4">' . $numbersHtml . '</div>' : '') . '
            ' . ($whatsappHtml ? '<div class="space-y-2.5">' . $whatsappHtml . '</div>' : '') . '
        </div>
    </div>
    <script>
    function copyPaymentNumber(btn, num) {
        navigator.clipboard.writeText(num).then(function () {
            var span = btn.querySelector("span");
            var old = span ? span.textContent : "";
            if (span) span.textContent = "কপি হয়েছে ✓";
            btn.classList.add("bg-green-100", "text-green-700");
            setTimeout(function () { if (span) span.textContent = old; btn.classList.remove("bg-green-100", "text-green-700"); }, 1800);
        });
    }
    </script>';
}

// ============================================================
// পাবলিক ফর্ম স্প্যাম-প্রোটেকশন — রেজিস্ট্রেশন/অর্ডার ফর্মে বট/স্প্যাম ঠেকানোর জন্য:
// (১) honeypot (লুকানো ফিল্ড — মানুষ দেখে না, বট পূরণ করে), (২) টাইমিং (খুব দ্রুত সাবমিট = বট),
// (৩) IP রেট-লিমিট (অল্প সময়ে বেশি সাবমিট)। phone_lookup_attempts এর মতোই প্যাটার্ন।
// ============================================================
const FORM_MAX_PER_WINDOW = 8;    // একই IP থেকে উইন্ডোতে সর্বোচ্চ সাবমিট
const FORM_WINDOW_MINUTES  = 10;
const FORM_MIN_FILL_SECONDS = 3;  // ফর্ম খোলার এত সেকেন্ডের কমে সাবমিট = বট সন্দেহ

// পাবলিক ক্লায়েন্ট IP (REMOTE_ADDR — X-Forwarded-For স্পুফযোগ্য বলে নিরাপত্তার জন্য ব্যবহার করা হয় না)
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '';
}

// honeypot + টাইমিং যাচাই — true মানে স্প্যাম (নীরবে বাতিল করা উচিত)
function is_spam_submission(array $post): bool
{
    if (trim($post['website'] ?? '') !== '') {
        return true; // honeypot ভরা = বট
    }
    $ts = (int) ($post['form_ts'] ?? 0);
    if ($ts <= 0) {
        return true; // টাইমস্ট্যাম্প নেই = সরাসরি স্ক্রিপ্টেড POST
    }
    $elapsed = time() - $ts;
    return $elapsed < FORM_MIN_FILL_SECONDS || $elapsed > 7200; // খুব দ্রুত অথবা ২ ঘণ্টার বেশি বাসি
}

// IP রেট-লিমিট চেক (form_submit_attempts টেবিল)। টেবিল না থাকলে নীরবে false (ব্লক করে না)।
function form_submit_rate_limited(PDO $db, string $ip): bool
{
    try {
        $stmt = $db->prepare('SELECT COUNT(*) c FROM form_submit_attempts WHERE ip_address = :ip AND attempted_at > (NOW() - INTERVAL :m MINUTE)');
        $stmt->bindValue('ip', $ip);
        $stmt->bindValue('m', FORM_WINDOW_MINUTES, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetch()['c'] >= FORM_MAX_PER_WINDOW;
    } catch (Throwable $e) {
        return false;
    }
}

// সফল সাবমিট রেকর্ড (রেট-লিমিটের হিসাবের জন্য) + পুরনো এন্ট্রি পরিষ্কার
function form_record_submit(PDO $db, string $ip): void
{
    try {
        $db->prepare('INSERT INTO form_submit_attempts (ip_address) VALUES (:ip)')->execute(['ip' => $ip]);
        $db->exec('DELETE FROM form_submit_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    } catch (Throwable $e) {
        // টেবিল না থাকলে চুপচাপ
    }
}

// ফর্মে বসানোর হিডেন স্প্যাম-প্রোটেকশন ফিল্ড (honeypot + রেন্ডার টাইমস্ট্যাম্প) — csrf_field() এর পাশে বসান
function spam_protection_fields(): string
{
    return '<input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;top:-9999px;width:1px;height:1px;opacity:0;pointer-events:none;">'
        . '<input type="hidden" name="form_ts" value="' . time() . '">';
}

// একটা টাইপ+আইডি দিয়ে আইটেম লোড করা (courses/worksheets/products এর জন্য কমন)
function fetch_item(string $type, int $id): ?array
{
    if ($type === 'course') {
        // course_batches.id (child) — 'course_id' নামের প্যারামিটার এখনো ব্যবহার হয় কিন্তু বাস্তবে
        // এটা একটা নির্দিষ্ট ব্যাচকে বোঝায়, title parent courses টেবিল থেকে জয়েন করে আনা হয়
        $stmt = get_db()->prepare(
            'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id AND cb.is_active = 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }
        $f = get_db()->prepare('SELECT feature_text FROM course_features WHERE batch_id = :id ORDER BY sort_order');
        $f->execute(['id' => $id]);
        $row['features'] = array_column($f->fetchAll(), 'feature_text');
        return $row;
    }

    $tableMap = ['worksheet' => 'worksheets', 'product' => 'products'];
    if (!isset($tableMap[$type])) {
        return null;
    }
    $stmt = get_db()->prepare("SELECT * FROM `{$tableMap[$type]}` WHERE id = :id AND is_active = 1");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }

    if ($type === 'product') {
        $f = get_db()->prepare('SELECT feature_text FROM product_features WHERE product_id = :id ORDER BY sort_order');
        $f->execute(['id' => $id]);
        $row['features'] = array_column($f->fetchAll(), 'feature_text');
    }

    return $row;
}

// অ্যাডমিন কনটেক্সটে item এর দাম/টাইটেল বের করার জন্য — fetch_item() এর মতো is_active ফিল্টার করে না,
// কারণ ডিঅ্যাক্টিভেটেড আইটেমের পুরনো রেজিস্ট্রেশনেও (income/courier হিসাবের জন্য) দাম লাগে
function get_item_details(string $type, int $itemId): ?array
{
    if ($type === 'course') {
        $stmt = get_db()->prepare(
            'SELECT c.title, cb.price FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id'
        );
        $stmt->execute(['id' => $itemId]);
        return $stmt->fetch() ?: null;
    }

    $tableMap = ['worksheet' => 'worksheets', 'product' => 'products'];
    if (!isset($tableMap[$type])) {
        return null;
    }
    $stmt = get_db()->prepare("SELECT title, price FROM `{$tableMap[$type]}` WHERE id = :id");
    $stmt->execute(['id' => $itemId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

// IP অ্যাড্রেস অ্যাডমিন প্যানেলে বোঝার মতো করে দেখানো — ::1 (IPv6 loopback, লোকাল টেস্টে REMOTE_ADDR এটাই হয়)
// ও ::ffff:x.x.x.x (IPv6-mapped IPv4) ফরম্যাট সাধারণ ইউজারের কাছে বিভ্রান্তিকর লাগে
function format_ip_display(?string $ip): string
{
    if (!$ip) {
        return '-';
    }
    if ($ip === '::1') {
        return '127.0.0.1 (localhost)';
    }
    if (strpos($ip, '::ffff:') === 0) {
        return substr($ip, 7);
    }
    return $ip;
}

// প্রতিটা পাবলিক পেজ লোডে একটা ভিজিটর লগ এন্ট্রি সেভ করে (includes/site-header.php থেকে কল হয়) —
// ব্যর্থ হলেও (DB সমস্যা ইত্যাদি) নীরবে উপেক্ষা করে, পেজ লোড কখনো আটকাবে না
function log_visitor(): void
{
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $page = $_SERVER['REQUEST_URI'] ?? '';
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        get_db()->prepare('INSERT INTO visitor_logs (ip_address, page_url, user_agent) VALUES (:ip, :page, :ua)')
            ->execute([
                'ip' => $ip,
                'page' => mb_substr($page, 0, 500),
                'ua' => mb_substr($ua, 0, 255),
            ]);
    } catch (Throwable $e) {
        // নীরবে উপেক্ষা
    }
}

// ============================================================================
//  ফেসবুক পোস্ট/রিল সেকশন (২০২৬-০৭-২০)
// ============================================================================
//  অ্যাডমিন শুধু ফেসবুক লিংক পেস্ট করেন (admin → "ফেসবুক পোস্ট"), এখানে সেটা
//  অফিসিয়াল Facebook embed iframe-এ রূপান্তরিত হয়। কোনো API key/টোকেন লাগে না।
//  ⚠️ পোস্টটি অবশ্যই **পাবলিক** হতে হবে, নাহলে ফেসবুক এমবেড দেখাবে না।

/**
 * লিংক দেখে ধরন শনাক্ত: 'video' (রিল/ভিডিও/watch) নাকি 'post'।
 * ইউজারকে আলাদা করে ধরন বাছতে হয় না — শুধু লিংক পেস্ট করলেই হয়।
 */
function fb_link_kind(string $url): string
{
    $u = strtolower(trim($url));
    // fb.watch হলো ফেসবুকের ভিডিও শর্ট-লিংক — পুরো হোস্টটাই ভিডিও বোঝায়
    $host = (string) parse_url($u, PHP_URL_HOST);
    if ($host === 'fb.watch' || substr($host, -9) === '.fb.watch') {
        return 'video';
    }
    // `/share/r/...` = রিলের নতুন শেয়ার-লিংক ফরম্যাট, `/share/v/...` = ভিডিও
    foreach (['/reel/', '/reels/', '/videos/', '/video.php', '/watch', '/share/r/', '/share/v/'] as $needle) {
        if (strpos($u, $needle) !== false) {
            return 'video';
        }
    }
    return 'post';
}

/** লিংকটা আদৌ ফেসবুকের কিনা (অন্য সাইটের লিংক এমবেড করার চেষ্টা ঠেকাতে) */
function is_facebook_url(string $url): bool
{
    $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));
    if ($host === '') {
        return false;
    }
    foreach (['facebook.com', 'fb.com', 'fb.watch', 'm.facebook.com', 'web.facebook.com'] as $allowed) {
        if ($host === $allowed || substr($host, -strlen('.' . $allowed)) === '.' . $allowed) {
            return true;
        }
    }
    return false;
}

/**
 * এমবেড iframe-এর src বানায়। ব্যর্থ হলে (ফেসবুকের লিংক না হলে) null।
 * `plugins/post.php` ও `plugins/video.php` — দুটোই ফেসবুকের অফিসিয়াল, কী ছাড়াই চলে।
 */
/**
 * ধরন অনুযায়ী কার্ডের ব্যাজ: [লেবেল, lucide আইকন]।
 */
function fb_kind_badge(string $url): array
{
    $u = strtolower($url);
    if (strpos($u, "/reel") !== false || strpos($u, "/share/r/") !== false) {
        return ["রিল", "clapperboard"];
    }
    if (fb_link_kind($url) === "video") {
        return ["ভিডিও", "play-circle"];
    }
    return ["পোস্ট", "image"];
}

/** সক্রিয় ফেসবুক পোস্টগুলো (গুরুত্বপূর্ণগুলো আগে) */
function get_social_posts(): array
{
    try {
        return get_db()->query(
            'SELECT * FROM social_posts WHERE is_active = 1 ORDER BY is_featured DESC, sort_order ASC, id DESC'
        )->fetchAll();
    } catch (Throwable $e) {
        return []; // টেবিল না থাকলে (মাইগ্রেশন চালানো হয়নি) সেকশনটা নীরবে বাদ যাবে
    }
}

/**
 * পুরো "আমাদের ফেসবুকে" সেকশন রেন্ডার করে। কিছু না থাকলে খালি স্ট্রিং (সেকশনই দেখাবে না)।
 *
 * ⚠️ পারফরম্যান্স: প্রতিটা iframe ফেসবুক থেকে লোড হয় বলে ভারী — তাই `data-fb-src` এ রেখে
 * **lazy-load** করা হয় (site-footer.php এর IntersectionObserver স্ক্রল করে কাছে গেলে তবেই
 * আসল `src` বসায়)। নাহলে হোমপেজ অনেক ধীর হয়ে যেত।
 */
function render_facebook_section(): string
{
    if (get_setting('facebook_section_on', '1') !== '1') {
        return '';
    }
    $posts   = get_social_posts();
    $pageUrl = trim((string) get_setting('facebook_page_url', ''));
    $hasPage = $pageUrl !== '' && is_facebook_url($pageUrl);
    if (!$posts) {
        return '';
    }

    $title = get_setting('facebook_section_title') ?: 'আমাদের ফেসবুকে';
    $out  = '<section class="mb-4">';
    $out .= render_page_header('thumbs-up', 'সোশ্যাল মিডিয়া', $title,
        'ফেসবুকে আমাদের সর্বশেষ খবর, ছবি ও ভিডিও', 'text-blue-700');
    $out .= '<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">';
    $noImgIndex = 0; // ছবিহীন কার্ডের রঙ ঘোরানোর জন্য

    foreach ($posts as $p) {
        $url = trim((string) $p['url']);
        if (!is_facebook_url($url)) {
            continue; // ফেসবুকের লিংক না — বাদ
        }
        [$kindLabel, $kindIcon] = fb_kind_badge($url);
        $feat = (int) $p['is_featured'] === 1;

        // পুরো কার্ডটাই একটা লিংক — মোবাইলে ট্যাপ করলে OS নিজেই ফেসবুক অ্যাপে খুলে দেয়
        // (Android App Links / iOS Universal Links), অ্যাপ না থাকলে ব্রাউজারে।
        $out .= '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer" '
             .  'class="fb-card' . ($feat ? ' fb-card-feat' : '') . '">';

        // ছবি থাকলে ৪:৩ অনুপাতে; **না থাকলে** রঙিন গ্রেডিয়েন্ট + হালকা নকশার উপর
        // শিরোনামটাই বড় করে বসে (খবরের সাইটের ছবিহীন খবরের মতো) — ফাঁকা/ভাঙা লাগে না।
        // রঙ কার্ডভেদে ঘুরে যায় (৫টা), তাই পাশাপাশি কয়েকটা থাকলেও একঘেয়ে লাগে না।
        $hasImg = !empty($p['image']);
        $out .= '<div class="fb-card-media' . ($hasImg ? '' : ' fb-card-noimg c' . ($noImgIndex++ % 5)) . '">';
        if ($hasImg) {
            $out .= '<img src="' . e($p['image']) . '" alt="" loading="lazy">';
        } else {
            $out .= '<span class="fb-card-ph-title">' . e((string) $p['title']) . '</span>';
        }
        $out .= '<span class="fb-card-kind"><i data-lucide="' . e($kindIcon) . '"></i> ' . e($kindLabel) . '</span>';
        if ($feat) {
            $out .= '<span class="fb-card-star">★ গুরুত্বপূর্ণ</span>';
        }
        $out .= '</div>';

        $out .= '<div class="fb-card-body">';
        if ($hasImg && !empty($p['title'])) { // ছবিহীন কার্ডে শিরোনাম উপরে দেখানো হয়েছে
            $out .= '<h3 class="fb-card-title">' . e($p['title']) . '</h3>';
        }
        if (!empty($p['excerpt'])) {
            $out .= '<p class="fb-card-text">' . e($p['excerpt']) . '</p>';
        }
        $out .= '<span class="fb-card-cta">ফেসবুকে দেখুন <span aria-hidden="true">&rarr;</span></span>';
        $out .= '</div></a>';
    }
    $out .= '</div>';

    if ($hasPage) {
        $out .= '<div class="text-center mt-6"><a href="' . e($pageUrl) . '" target="_blank" rel="noopener noreferrer" '
             .  'class="fb-open-link">আমাদের ফেসবুক পেজে যান <span aria-hidden="true">&rarr;</span></a></div>';
    }
    $out .= '</section>';
    return $out;
}
