<?php
// ─────────────────────────────────────────────────────────────
// 📌 করণীয় / রিকোয়ারমেন্ট ইনবক্স — শেয়ার্ড ইঞ্জিন (2026-10-28)
//
// প্রতি অর্ডারের (বা অর্ডার ছাড়া কারও) ছোট রিকোয়ারমেন্ট জমা রাখা ও ট্র্যাক করা —
// আগে যেগুলো WhatsApp গ্রুপে স্ক্রিনশট দিয়ে মনে রাখা হতো।
//
// 🔴🔴 সম্পূর্ণ আলাদা স্তর — টাকা/খাতা/আয় কিছুই ছোঁয় না (legacy_students-এর মতো নীতি)।
//
// দুই স্তরে সহজে ফেরানো যায়:
//   • নরম: সেটিংসে task_feature_on = 0 → সর্বত্র লুকায়, ডেটা থাকে।
//   • সম্পূর্ণ: phpMyAdmin-এ টেবিল DROP → সব গার্ড (db_has_table) চুপচাপ বাদ,
//     সাইট হুবহু আগের অবস্থায়।
//
// 🔴 নতুন কলাম/ধরন লাগলে টেবিল + এই ফাইলই — ধরনের তালিকা সেটিংসে এডিটযোগ্য (কোড নয়)।
// ─────────────────────────────────────────────────────────────

require_once dirname(__DIR__, 2) . '/includes/functions.php';

// টেবিলটা আছে কিনা (মাইগ্রেশনের আগে চুপচাপ বাদ দেওয়ার গার্ড)
function task_table_ready(?PDO $db = null): bool
{
    return db_has_table($db, 'registration_tasks');
}

// অ্যাডমিন ফিচারটা চালু রেখেছেন কিনা (নরম বন্ধ — ডেটা না মুছে লুকানো)।
// খালি/অন্য কিছু = চালু, শুধু '0' = বন্ধ (facebook_section_on-এর হুবহু নীতি)।
function task_feature_switch_on(): bool
{
    return get_setting('task_feature_on') !== '0';
}

// সর্বত্র দেখানোর গার্ড (nav লিংক · তালিকার 📌 ব্যাজ · ড্যাশবোর্ড কার্ড · reg যোগ-লিংক):
// টেবিল আছে **এবং** সুইচ চালু। (tasks.php পাতাটা নিজে শুধু টেবিল-গার্ডে চলে, তাই বন্ধ
// করলেও সরাসরি URL দিয়ে পৌঁছে আবার চালু করা যায়।)
function task_feature_ready(?PDO $db = null): bool
{
    return task_table_ready($db) && task_feature_switch_on();
}

// করণীয়ের ধরনের তালিকা — সেটিংসে এডিটযোগ্য (প্রতি লাইনে একটা)। এটাই "সহজে ঠিক করা":
// অ্যাডমিন কোড ছাড়াই তালিকা বদলাতে পারেন। খালি রাখলে ইউজারের নিজের দেওয়া উদাহরণগুলো।
function task_type_presets(): array
{
    $raw = get_setting('task_types');
    if (trim($raw) === '') {
        return [
            'ঠিকানা পরিবর্তন',
            'পার্সেল পরে যাবে',
            'টাকা পুরো পেইড',
            'টাকা পেইড — পরে যাবে',
            'ডিউ — পরে দিবে',
            'পার্সেল অ্যামাউন্ট ০ — পরে টাকা',
            'সাথে অতিরিক্ত কিছু যাবে',
            'দুই সেট যাবে',
            'অন্যান্য',
        ];
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $out, true)) {
            $out[] = mb_substr($line, 0, 80);
        }
        if (count($out) >= 40) {
            break;
        }
    }
    return $out ?: ['অন্যান্য'];
}

// ৬টা রঙ ঘুরেফিরে — [tint ব্যাকগ্রাউন্ড, deep লেখা/দাগ]। নোটিশ প্যালেটের মতোই নীতি।
// 🔴 ইনলাইন রঙ (Tailwind ক্লাস নয়) — তাই নতুন ধরন যোগ করলে Tailwind রিবিল্ড লাগে না।
function task_palette(): array
{
    return [
        ['#EFF6FF', '#1D4ED8'], // blue
        ['#FFF7ED', '#C2410C'], // amber
        ['#ECFDF5', '#047857'], // green
        ['#FEF2F2', '#B91C1C'], // red
        ['#FAF5FF', '#7E22CE'], // purple
        ['#ECFEFF', '#0E7490'], // teal
    ];
}

// একটা ধরনের রঙ — তালিকায় থাকলে তার অবস্থান ধরে (স্থিতিশীল), নাহলে নামের hash ধরে।
// 🔴 একই ধরন সবসময় একই রঙ পায় (অবস্থান বদলালে সামান্য বদলাতে পারে — ক্ষতি নেই)।
function task_type_color(string $type): array
{
    $pal = task_palette();
    $presets = task_type_presets();
    $i = array_search($type, $presets, true);
    if ($i === false) {
        $i = crc32($type);
    }
    return $pal[((int) $i % count($pal) + count($pal)) % count($pal)];
}

// মাসের লেবেল — pay_month_label() থাকলে সেটাই (কোর্স পার্সেলের "২য় মাস"-এর সাথে মিলবে),
// নাহলে সরল ফলব্যাক। target_month 0/খালি হলে খালি স্ট্রিং (= স্থায়ী)।
function task_month_label(?int $m): string
{
    $m = (int) $m;
    if ($m < 1) {
        return '';
    }
    if (function_exists('pay_month_label')) {
        return pay_month_label($m);
    }
    return $m . ' নম্বর মাস';
}

// তালিকার 📌 ব্যাজের জন্য — একগুচ্ছ registration_id-র খোলা করণীয়ের সংখ্যা (bulk, N+1 এড়াতে)।
// টেবিল না থাকলে/ব্যর্থ হলে [] (পেজ ভাঙে না)।
function task_open_counts(?PDO $db, array $regIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $regIds), fn($v) => $v > 0)));
    if (!$ids || !task_feature_ready($db)) {
        return [];
    }
    try {
        $db = $db ?: get_db();
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $db->prepare(
            "SELECT registration_id, COUNT(*) c FROM registration_tasks
              WHERE status = 'open' AND registration_id IN ($in)
              GROUP BY registration_id"
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['registration_id']] = (int) $r['c'];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

// uploads/tasks/ ফোল্ডার — report_upload_dir()-এর হুবহু প্যাটার্ন: mkdir + deny-all .htaccess
// (স্ক্রিনশটে কাস্টমারের তথ্য থাকে — সরাসরি URL দিয়ে খোলা যাবে না, admin/task-image.php ছাড়া) + index.html।
function task_upload_dir(): string
{
    $dir = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'tasks';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @chmod($dir, 0755);
    }
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    if (!is_file($dir . DIRECTORY_SEPARATOR . 'index.html')) {
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'index.html', '');
    }
    return $dir;
}

// সেভ করা image_path (`uploads/tasks/xxx.webp`) → ডিস্কের absolute পাথ, realpath দিয়ে
// যাচাই করে সেটা সত্যিই uploads/tasks/-এর ভেতরে কিনা (path-traversal গার্ড)।
// বাইরে/অস্তিত্বহীন হলে খালি স্ট্রিং। (report_pdf_abs_path()-এর হুবহু নীতি)
function task_image_abs_path(string $filePath): string
{
    $base = realpath(task_upload_dir());
    $abs  = realpath(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace(['\\', '..'], ['/', ''], $filePath));
    if ($base === false || $abs === false) {
        return '';
    }
    if (strncmp($abs, $base . DIRECTORY_SEPARATOR, strlen($base) + 1) !== 0) {
        return '';
    }
    return is_file($abs) ? $abs : '';
}
