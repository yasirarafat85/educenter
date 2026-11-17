<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 🖼️ ফেসবুক ইনবক্সে ছবি পাঠানো (২০২৬-১১-১৭)
 *
 * তিন পথ, ইঞ্জিন একটাই:
 *   ১. ইনবক্স থেকে অ্যাডমিন নিজে (সংগ্রহ থেকে বেছে, অথবা ফোন থেকে নতুন ছবি)
 *   ২. নিয়মের সাথে জোড়া ছবি (meta_rules.image_id)
 *   ৩. AI উত্তরের শেষে [ছবি:N] লিখে সংগ্রহ থেকে বেছে দেয় (meta_ai_drafts.image_id)
 * ছবিগুলো uploads/meta-images/-এ পাবলিক থাকে — ফেসবুক ঐ URL থেকেই তুলে নেয়।
 * 🔴 তাই এখানে কখনো গ্রাহকের ব্যক্তিগত ছবি রাখা নয় (সংগ্রহে শুধু ওয়ার্কশিট/রুটিন/QR জাতীয় ছবি)।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না। টেবিল না থাকলে সব চুপচাপ বাদ — লেখা আগের মতোই যায়।
 * ════════════════════════════════════════════════════════════════════════════ */

const MIMG_DIR      = 'meta-images';
const MIMG_MAX_MB   = 5;
const MIMG_MAX_SIDE = 1600;
const MIMG_PROMPT_MAX = 40;   // AI-কে সর্বোচ্চ কয়টা ছবির তালিকা দেওয়া হয়

/* ───────────────────────── বিশুদ্ধ ফাংশন ───────────────────────── */

/**
 * AI-র লেখা থেকে [ছবি:N] চিহ্ন তোলা। ফেরত: [চিহ্ন-ছাড়া লেখা, শেষ ছবির id বা 0]
 * বাংলা অঙ্কও চলে (মডেল কখনো ৫ লিখে ফেলে)।
 */
function mimg_parse_tag(string $text): array
{
    $id = 0;
    $t = preg_replace_callback('/\[\s*(?:ছবি|image|img)\s*[:：]\s*([0-9০-৯]{1,9})\s*\]/iu', function ($m) use (&$id) {
        $id = (int) strtr($m[1], ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
        return '';
    }, $text) ?? $text;
    $t = preg_replace("/[ \t]+\n/u", "\n", $t) ?? $t;
    return [trim($t), $id];
}

/** সাইটের ভেতরের ছবির পাথ → ফেসবুক যেটা তুলতে পারে এমন পুরো URL */
function mimg_url(string $path): string
{
    $path = ltrim($path, '/');
    if ($path === '' || str_starts_with($path, 'http')) { return $path; }
    return rtrim(defined('SITE_URL') ? SITE_URL : '', '/') . '/' . $path;
}

/* ───────────────────────── DB ───────────────────────── */

function mimg_ready(?PDO $db = null): bool
{
    static $r = null;
    if ($r !== null) { return $r; }
    return $r = db_has_table($db ?? get_db(), 'meta_images');
}

/** কোনো টেবিলে image_id ঘর আছে কিনা (meta_rules / meta_ai_drafts) */
function mimg_col(?PDO $db, string $table): bool
{
    return mimg_ready($db) && db_has_column($db ?? get_db(), $table, 'image_id');
}

/** সংগ্রহের সব ছবি (static ক্যাশ — $fresh দিলে নতুন করে) */
function mimg_all(PDO $db, bool $activeOnly = true, bool $fresh = false): array
{
    static $cache = [];
    $k = $activeOnly ? 'a' : 'x';
    if (!$fresh && isset($cache[$k])) { return $cache[$k]; }
    if (!mimg_ready($db)) { return $cache[$k] = []; }
    try {
        $rows = $db->query('SELECT * FROM meta_images' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) { $rows = []; }
    $out = [];
    foreach ($rows as $r) { $out[(int) $r['id']] = $r; }
    return $cache[$k] = $out;
}

function mimg_get(PDO $db, int $id, bool $activeOnly = true): ?array
{
    if ($id <= 0) { return null; }
    return mimg_all($db, $activeOnly)[$id] ?? null;
}

function mimg_used(PDO $db, int $id): void
{
    try { $db->prepare('UPDATE meta_images SET uses = uses + 1 WHERE id = :id')->execute(['id' => $id]); } catch (Throwable $e) {}
}

/** AI-কে দেওয়ার তালিকা — খালি হলে খালি স্ট্রিং (তখন প্রম্পটে ছবির অংশই যায় না) */
function mimg_prompt_text(PDO $db): string
{
    $lines = [];
    foreach (array_slice(mimg_all($db), 0, MIMG_PROMPT_MAX, true) as $id => $r) {
        $w = trim((string) ($r['when_text'] ?? ''));
        $lines[] = '[ছবি:' . $id . '] ' . trim((string) $r['title']) . ($w !== '' ? ' — কখন পাঠাবেন: ' . preg_replace('/\s+/u', ' ', $w) : '');
    }
    if (!$lines) { return ''; }
    return "ছবির সংগ্রহ (উত্তরের সাথে ছবি পাঠানো যায়):\n" . implode("\n", $lines) . "\n\n"
        . "ছবির নিয়ম: উত্তরের সাথে কোনো ছবি সত্যিই কাজে লাগলে (প্রশ্নের সাথে মেলে, বা ছবির \"কখন পাঠাবেন\" কথাটা খাটে) উত্তরের একদম শেষে আলাদা লাইনে শুধু তার চিহ্নটা লিখুন, যেমন [ছবি:3]। "
        . "সর্বোচ্চ একটা ছবি। কাজে না লাগলে কোনো চিহ্ন লিখবেন না। তালিকার বাইরের কোনো নম্বর কখনো লিখবেন না। উত্তরের লেখায় \"ছবি পাঠালাম\" জাতীয় কথা লিখতে পারেন, কিন্তু চিহ্নটা ছাড়া ছবি যায় না।";
}

/**
 * আপলোড করা ছবি সেভ — ফেসবুকের জন্য JPG/PNG/GIF রাখা হয় (WebP নয়)।
 * ফেরত: সাইট-রুট-রিলেটিভ পাথ, নাহলে RuntimeException।
 */
function mimg_save_upload(string $field, string $sub = MIMG_DIR): string
{
    require_once __DIR__ . '/upload.php';
    $f = $_FILES[$field] ?? null;
    if (!$f || (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { throw new RuntimeException('কোনো ছবি বাছা হয়নি।'); }
    if ((int) $f['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('ছবি আপলোড ব্যর্থ হয়েছে (এরর কোড: ' . (int) $f['error'] . ')।'); }
    if ((int) $f['size'] > MIMG_MAX_MB * 1024 * 1024) { throw new RuntimeException('ছবির সাইজ ' . MIMG_MAX_MB . ' মেগাবাইটের বেশি হতে পারবে না।'); }
    if (!is_uploaded_file((string) $f['tmp_name'])) { throw new RuntimeException('ছবি আপলোড যাচাই ব্যর্থ হয়েছে।'); }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = (string) finfo_file($finfo, (string) $f['tmp_name']);
    finfo_close($finfo);
    $map = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    if (!isset($map[$mime])) { throw new RuntimeException('শুধু JPG, PNG, GIF বা WEBP ছবি দেওয়া যাবে।'); }
    $src = $map[$mime];
    $out = $src === 'png' || $src === 'gif' ? $src : 'jpg';

    $root = dirname(__DIR__) . '/uploads/' . $sub;
    if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
        throw new RuntimeException('ছবির ফোল্ডার তৈরি করা যাচ্ছে না: uploads/' . $sub . ' — cPanel-এ uploads ফোল্ডারের Permission 755 করে দিন।');
    }
    if (!is_file($root . '/index.html')) { @file_put_contents($root . '/index.html', ''); }
    $name = date('Ymd') . '-' . bin2hex(random_bytes(6)) . '.' . $out;
    $dest = $root . '/' . $name;
    // GIF ছোঁয়া হয় না (নড়াচড়া হারাত); বাকিগুলো সর্বোচ্চ MIMG_MAX_SIDE-এ ছোট করা, প্যাডিং ছাড়া
    $ok = $src !== 'gif' && resize_image_to_canvas((string) $f['tmp_name'], $dest, $src, $out, MIMG_MAX_SIDE, MIMG_MAX_SIDE, false);
    if (!$ok) {
        if ($src === 'webp') { throw new RuntimeException('এই ছবিটা ঠিক করা যায়নি — JPG বা PNG ছবি দিন।'); }
        if (!move_uploaded_file((string) $f['tmp_name'], $dest)) { throw new RuntimeException('ছবি সেভ করা যায়নি।'); }
    }
    @chmod($dest, 0644);
    return 'uploads/' . $sub . '/' . $name;
}

/** সংগ্রহের ছবির ফাইল মোছা — শুধু আমাদের uploads/meta-images/-এর ভেতরে */
function mimg_delete_file(string $path): void
{
    if (!str_starts_with($path, 'uploads/' . MIMG_DIR . '/')) { return; }
    $base = realpath(dirname(__DIR__) . '/uploads/' . MIMG_DIR);
    $full = realpath(dirname(__DIR__) . '/' . $path);
    if ($base && $full && str_starts_with($full, $base . DIRECTORY_SEPARATOR) && is_file($full)) { @unlink($full); }
}
