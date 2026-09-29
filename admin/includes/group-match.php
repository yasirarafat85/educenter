<?php
// ── 👥 গ্রুপ মেলানো — মেসেঞ্জার/ফেসবুক গ্রুপের সদস্য-তালিকা ↔ কোর্সের রেজিস্ট্রেশন (২০২৬-০৯-২৯)
//
// ইউজারের সমস্যা: "গ্রুপে ১০ জন আছে, রেজিস্ট্রেশন করেছে ৮ জন — কে করেনি সেটা মেলাতে চাই।"
//
// 🔴 এই ফাইলটা **বিশুদ্ধ ফাংশন** — কোনো DB/সেশন/আউটপুট নেই, তাই আলাদা করে টেস্ট করা যায়
//    (`t/groupmatch.php`)। পেজ `admin/group-match.php` শুধু ডেটা এনে এখানে পাঠায়।
// 🔴 কিছুই লেখা হয় না — এটা নিছক **রিপোর্ট** (ইউজারের সিদ্ধান্ত ২০২৬-০৯-২৯)।

// মেসেঞ্জারের তালিকা কপি/স্ক্যান করলে নামের সাথে যেসব লাইন আসে — এগুলো নাম নয়।
// 🔴 লম্বা বাক্যাংশই এখানে রাখুন (contains-মিল) — ছোট শব্দ দিলে নামের ভেতরে মিলে যেতে পারে
//    (যেমন "ago" থাকলে "Santiago" বাদ পড়ে যেত)। ছোট শব্দ `gm_noise_equals()`-এ।
function gm_noise_contains(): array
{
    return [
        'joined with invite link', 'joined via invite link', 'invite link',
        'added by', 'group creator', 'group admin', 'created this group',
        'member requests', 'members', 'remove from group', 'see all',
        // বাংলা UI
        'যোগ দিয়েছেন', 'যোগ করেছেন', 'গ্রুপ অ্যাডমিন', 'অ্যাডমিন', 'সদস্য', 'সবাইকে দেখুন',
    ];
}

// পুরো লাইনটাই এই শব্দগুলোর একটা হলে বাদ (contains নয় — নামের অংশ হয়ে যেতে পারত)
function gm_noise_equals(): array
{
    return [
        'you', 'admin', 'member', 'message', 'call', 'remove', 'block', 'active',
        'more', 'search', 'add', 'edit', 'done', 'ok', 'cancel', 'back', 'group',
        'আপনি', 'বার্তা', 'কল', 'সরান', 'খুঁজুন', 'গ্রুপ',
    ];
}

// লাইনটা নাম নয় — এমন গঠন (সময়, সংখ্যা, লিংক)
function gm_noise_regex(): array
{
    return [
        '~^\d+$~u',                                        // শুধু সংখ্যা
        '~^[\p{P}\p{S}\s]+$~u',                            // শুধু যতিচিহ্ন/প্রতীক
        '~\b\d+\s*(m|h|d|w|mo|y|min|mins|minute|minutes|hour|hours|day|days|week|weeks|month|months|year|years)\s+ago\b~iu',
        '~^active\b~iu',                                   // "Active now" / "Active 5m ago"
        '~^\d+\s*(members|people|জন)\b~iu',                // "12 members"
        // 🔴 `@` একা দেখে ইমেইল ধরা যাবে না — প্রোফাইল-ছবি থেকে আসা `S'@` জঞ্জাল
        //    `S'@ Jannatul Ferdaus` লাইনটাকেই "ইমেইল" বলে বাদ দিয়ে দিত (ইউজারের
        //    স্ক্যানে ধরা — একটা আসল নাম হারিয়েছিল)। তাই দুই পাশে অক্ষর/সংখ্যা চাই।
        '~(https?://|www\.|[\w.+-]+@[\w-]+\.[a-z]{2,})~iu',   // লিংক/ইমেইল
    ];
}

/**
 * OCR-এ যেসব শব্দ ভেঙে আসে তার মূল রূপ (২০২৬-০৯-২৯ সন্ধ্যা, ইউজারের আসল স্ক্যান থেকে)।
 *
 * 🔴 কেন লাগল: ছবি থেকে পড়া লেখায় "Joined with invite link" বাস্তবে আসে
 *    **"Joired with invite lirk"** / "Jared with invite lirk" / "loired with invite lirk"
 *    / "We aired with imate lirk" — অর্থাৎ `n`↔`r`, `n`↔`i` গুলিয়ে যায়। হুবহু-মিল
 *    (`gm_noise_contains()`) তখন একটাও ধরতে পারে না, আর প্রতিটা লাইন **নাম হিসেবে**
 *    তালিকায় ঢুকে পড়ে (ইউজারের স্ক্যানে ১৮টা ভুয়া "নাম" এসেছিল)।
 * 🔴 তাই শব্দ ধরে **কাছাকাছি** মিল দেখা হয়, কিন্তু **অন্তত দুটো শব্দ** মিলতে হবে —
 *    একটা মিললেই বাদ দিলে "Link Ahmed"/"Adda Rahman" জাতীয় আসল নামও হারাত।
 */
function gm_noise_words(): array
{
    return ['joined', 'invite', 'link', 'added', 'admin', 'member', 'members',
            'active', 'online', 'group', 'with', 'you', 'creator', 'moderator'];
}

const GM_NOISE_WORD_MIN = 0.72;   // এর বেশি কাছাকাছি হলে ঐ শব্দটা "আবর্জনা" ধরা হয়
const GM_NOISE_WORD_HITS = 2;     // কমপক্ষে এতগুলো শব্দ মিললে পুরো লাইন বাদ

/**
 * লাইনটা কি (ভাঙা বানান সহ) UI-র লেখা?
 * 🔴 ৩ অক্ষরের কম শব্দ গোনা হয় না — "by"/"a" যেকোনো নামে মিলে যেত।
 */
function gm_is_fuzzy_noise(string $line): bool
{
    $tokens = gm_tokens($line);
    if (count($tokens) < 2) {
        return false;
    }
    $words = gm_noise_words();
    $hits  = 0;
    foreach ($tokens as $t) {
        if (mb_strlen($t, 'UTF-8') < 3) {
            continue;
        }
        foreach ($words as $w) {
            if ($t === $w || gm_similarity($t, $w) >= GM_NOISE_WORD_MIN) {
                $hits++;
                break;
            }
        }
        if ($hits >= GM_NOISE_WORD_HITS) {
            return true;
        }
    }
    return false;
}

/**
 * নামের **সামনে** বসা প্রোফাইল-ছবির আবর্জনা ছেঁটে ফেলে।
 *
 * 🔴 কেন (ইউজারের আসল স্ক্যান): তালিকার বাঁ পাশে গোল প্রোফাইল ছবি থাকে, OCR সেটাকেও
 *    অক্ষর ভেবে পড়ে ফেলে — `1. AyeSha Siddika` · `y/ Elora Parvin` · `» Farjana Lucky`
 *    · `LU Nahida Akter` · `47, Sarmin Rima` · `9? Yasir Arafat` · `: Trisha Alam`।
 *    এই জঞ্জাল নামের সাথে জুড়ে থাকলে রেজিস্ট্রেশনের নামের সাথে আর মিলত না।
 * 🔴 শুধু **সামনের** টোকেনই ছাঁটা হয়, আর প্রথম আসল-নামের-মতো টোকেন পেলেই থেমে যায় —
 *    নামের ভেতরের কিছু (`Prima Mn Dey`-র `Mn`) কখনো হারায় না।
 * ⚠️ একটাও নাম-সদৃশ টোকেন না থাকলে **আসল লাইনটাই** ফেরত যায় (সব ছেঁটে খালি করা নয়)।
 */
function gm_strip_lead_junk(string $line): string
{
    $parts = preg_split('~\s+~u', trim($line), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $i = 0;
    $n = count($parts);
    while ($i < $n && !gm_looks_like_name_token($parts[$i])) {
        $i++;
    }
    if ($i === 0 || $i >= $n) {
        return $line;
    }
    return implode(' ', array_slice($parts, $i));
}

// টোকেনটা কি নামের অংশ হতে পারে? (ছবি থেকে আসা জঞ্জাল নয়)
function gm_looks_like_name_token(string $t): bool
{
    // যতিচিহ্ন/সংখ্যা/প্রতীক মেশানো থাকলে নাম নয় — `1.` `y/` `S'@` `47,` `9?` `{]`
    if (!preg_match('~^[\p{L}\x{0980}-\x{09FF}]+$~u', $t)) {
        return false;
    }
    $len = mb_strlen($t, 'UTF-8');
    if ($len < 2) {
        return false;                       // একক অক্ষর — `A` `y` `\`
    }
    // ৩ অক্ষরের কম **সম্পূর্ণ বড় হাতের** — `LU` `AN` `YT` (ছবির আবর্জনা)।
    // ⚠️ `Md`/`Mn` ঠিকই থাকে (ওগুলোয় ছোট হাতের অক্ষর আছে)।
    if ($len <= 2 && preg_match('~^\p{Lu}+$~u', $t)) {
        return false;
    }
    return true;
}

/**
 * লাইনটা কি পড়াই যায়নি? (সম্ভবত বাংলা নাম — OCR ইংরেজি ছাড়া পড়ে না)
 *
 * 🔴 ইউজারের স্ক্যানে "প্রকৌশলী তানজিন আরা" → **`ATI! OIG ST`** আর
 *    "ফারহানা আফরোজ" → **`PIFRAANT HELIS`** হয়েছে। এগুলো দেখতে নামের মতো, তাই চুপচাপ
 *    "রেজিস্ট্রেশন পাইনি" তালিকায় বসে অ্যাডমিনকে বিভ্রান্ত করত। এখন আলাদা করে দেখিয়ে
 *    বলা হয় — "এগুলো নিজে লিখে দিন"।
 * ধরার নিয়ম **দুটো শর্তই** লাগে: (১) লেখায় একটাও ছোট হাতের অক্ষর নেই, **আর**
 * (২) অন্তত একটা শব্দে একটাও স্বরবর্ণ নেই (`ST`, `NGKR`)।
 * 🔴 দুটো শর্ত কেন — শুধু "বড় হাতের" দেখলেই বাদ দিলে কেউ **ইচ্ছে করে বড় হাতে**
 *    নাম লিখলে (`ISRAT JAHAN`, `MD RAKIB`) সেটাও ধরা পড়ত, আর পেস্ট করা পুরো
 *    তালিকা বড় হাতের হলে সবই অপাঠ্য দেখাত (টেস্টে ধরা পড়েছিল)। আসল নামে
 *    স্বরবর্ণ থাকেই; OCR-এর আবর্জনায় প্রায়ই থাকে না।
 * ⚠️ এটা **বাদ দেওয়া নয়** — শুধু আলাদা বাক্সে দেখানো, অ্যাডমিন নিজে ঠিক করে দেবেন।
 * ⚠️ সব বাংলা-জনিত আবর্জনা এতে ধরা পড়বে না (`PIFRAANT HELIS`-এ স্বরবর্ণ আছে) —
 *    তাই পাতায় আলাদা করে লেখা আছে যে বাংলা নাম হাতে লিখে দিতে হবে।
 */
function gm_looks_unreadable(string $line): bool
{
    if (preg_match('~\p{Ll}~u', $line) || !preg_match('~\p{Lu}~u', $line)) {
        return false;
    }
    foreach (preg_split('~\s+~u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $t) {
        $letters = preg_replace('~[^\p{L}]~u', '', $t);
        if (mb_strlen((string) $letters, 'UTF-8') >= 2 && !preg_match('~[AEIOUY]~iu', (string) $letters)) {
            return true;
        }
    }
    return false;
}

/**
 * পেস্ট/স্ক্যান করা কাঁচা লেখা → নামের তালিকা।
 *
 * ফেরে: ['names' => [ ['name' => 'Ayesha Siddika', 'count' => 2], … ], 'dropped' => int,
 *        'lines' => int, 'unreadable' => ['ATI! OIG ST', …]]
 * একই নাম একাধিকবার এলে **একটাই এন্ট্রি**, সাথে `count` — ইউজার এটাই চেয়েছেন
 * ("মেসেঞ্জারে এই নামে দুই জন")।
 */
function gm_parse_names(string $raw): array
{
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);

    $out        = [];   // norm => ['name'=>..., 'count'=>...]
    $dropped    = 0;
    $seen       = 0;
    $unreadable = [];

    foreach ($lines as $line) {
        // ZWSP/ZWNJ/BOM ইত্যাদি — কপি-পেস্টে প্রায়ই ঢোকে, নাহলে মিল ভেঙে যেত
        $line = preg_replace('~[\x{200B}-\x{200F}\x{FEFF}\x{00AD}]~u', '', $line);
        // 🔴🔴 `trim()`-এর চরিত্র-তালিকা **বাইট ধরে** কাজ করে — এখানে আগে `·•–—` ছিল,
        //    ফলে `» Farjana Lucky`-র `»` (0xC2 0xBB)-এর প্রথম বাইটটা ছেঁটে গিয়ে
        //    একটা **অবৈধ UTF-8 বাইট** পড়ে থাকত; তারপর `/u` রেগেক্স সব ব্যর্থ হয়ে
        //    নামটা নীরবে হারিয়ে যেত (ইউজারের আসল স্ক্যানে ধরা)। তাই তালিকায়
        //    **শুধু ASCII**, আর ইউনিকোড যতিচিহ্ন আলাদা `/u` রেগেক্সে।
        //    (এই নিয়মটা CLAUDE.md-এ `text_excerpt()`-এর ঘরেও লেখা আছে।)
        $line = trim((string) $line, " \t\v\0.,|-*");
        $line = preg_replace('~^[\p{P}\p{S}\s]+~u', '', (string) $line);
        $line = preg_replace('~[\p{P}\p{S}\s]+$~u', '', (string) $line);
        $line = trim((string) preg_replace('~\s+~u', ' ', (string) $line));
        if ($line === '') {
            continue;
        }
        $seen++;
        // 🔴 ক্রম জরুরি: **আগে** সামনের জঞ্জাল ছাঁটা, **তারপর** আবর্জনা-যাচাই।
        //    উল্টো করলে ছবির জঞ্জালই যাচাইটা ভুল পথে নিত (`S'@ Jannatul Ferdaus`
        //    "ইমেইল" বলে বাদ পড়ত, `YY Added by you` আবর্জনা বলে ধরা পড়ত না)।
        $line = gm_strip_lead_junk($line);
        if (gm_is_noise($line) || gm_is_fuzzy_noise($line)) {
            $dropped++;
            continue;
        }
        $key = gm_norm($line);
        if ($key === '') {
            $dropped++;
            continue;
        }
        if (gm_looks_unreadable($line)) {
            if (!in_array($line, $unreadable, true)) {
                $unreadable[] = $line;
            }
            continue;
        }
        if (isset($out[$key])) {
            $out[$key]['count']++;
        } else {
            $out[$key] = ['name' => $line, 'count' => 1];
        }
    }
    return [
        'names'      => array_values($out),
        'dropped'    => $dropped,
        'lines'      => $seen,
        'unreadable' => $unreadable,
    ];
}

// লাইনটা কি নাম নয় (UI-র লেখা / আবর্জনা)?
function gm_is_noise(string $line): bool
{
    $low = mb_strtolower($line, 'UTF-8');

    foreach (gm_noise_equals() as $w) {
        if ($low === $w) {
            return true;
        }
    }
    foreach (gm_noise_contains() as $w) {
        if (mb_strpos($low, $w) !== false) {
            return true;
        }
    }
    foreach (gm_noise_regex() as $re) {
        if (preg_match($re, $line)) {
            return true;
        }
    }
    // অক্ষরই নেই বা এক অক্ষরের — OCR-এর আবর্জনা
    if (preg_match_all('~[\p{L}]~u', $line) < 2) {
        return true;
    }
    // খুব লম্বা = বাক্য, নাম নয়
    if (mb_strlen($line, 'UTF-8') > 60) {
        return true;
    }
    return false;
}

/**
 * নাম মেলানোর জন্য স্বাভাবিক রূপ — ছোট হাতের, যতিচিহ্ন বাদ, এক স্পেস।
 * 🔴 বাংলা অক্ষরে case নেই, তাই `mb_strtolower` শুধু ইংরেজিতে কাজ করে — সেটাই যথেষ্ট।
 */
function gm_norm(string $s): string
{
    $s = preg_replace('~[\x{200B}-\x{200F}\x{FEFF}\x{00AD}]~u', '', $s);
    $s = mb_strtolower((string) $s, 'UTF-8');
    $s = preg_replace('~[\p{P}\p{S}]+~u', ' ', $s);   // . , ' " ( ) - _ ইত্যাদি → স্পেস
    $s = preg_replace('~\s+~u', ' ', $s);
    return trim((string) $s);
}

function gm_tokens(string $s): array
{
    $n = gm_norm($s);
    return $n === '' ? [] : explode(' ', $n);
}

/**
 * মাল্টিবাইট-নিরাপদ Levenshtein দূরত্ব।
 * 🔴 PHP-র নিজের `levenshtein()` **বাইট ধরে** গোনে — বাংলায় এক অক্ষর ৩ বাইট, তাই
 *    ওটা দিয়ে বাংলা নামের দূরত্ব অর্থহীন হতো। তাই অক্ষর ধরে নিজের DP।
 * খরচ বাঁধতে দুই পাশেই ৮০ অক্ষরে ছাঁটা (নাম এর চেয়ে বড় হয় না)।
 */
function gm_lev(string $a, string $b): int
{
    $x = preg_split('//u', mb_substr($a, 0, 80, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $y = preg_split('//u', mb_substr($b, 0, 80, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $m = count($x);
    $n = count($y);
    if ($m === 0) { return $n; }
    if ($n === 0) { return $m; }

    $prev = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $cur = [$i];
        for ($j = 1; $j <= $n; $j++) {
            $cost  = ($x[$i - 1] === $y[$j - 1]) ? 0 : 1;
            $cur[$j] = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
        }
        $prev = $cur;
    }
    return $prev[$n];
}

// ০..১ — ১ মানে হুবহু এক
function gm_similarity(string $a, string $b): float
{
    $a = gm_norm($a);
    $b = gm_norm($b);
    if ($a === '' || $b === '') { return 0.0; }
    if ($a === $b) { return 1.0; }
    $len = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));
    return $len === 0 ? 0.0 : max(0.0, 1 - (gm_lev($a, $b) / $len));
}

/**
 * ছোট নামের সব শব্দ বড় নামের ভেতরে আছে কিনা ("Jannatul" ⊂ "Jannatul Ferdaus")।
 * 🔴 এক-শব্দের নামে **দুই দিকেই** মিলে যেতে পারে (এক নামে দুই জন) — সেটাই আমরা
 *    ইউজারকে সতর্কতা হিসেবে দেখাই, নিজে থেকে একটা বেছে নিই না।
 */
function gm_token_subset(string $a, string $b): bool
{
    $ta = gm_tokens($a);
    $tb = gm_tokens($b);
    if (!$ta || !$tb || $ta === $tb) { return false; }
    $small = count($ta) <= count($tb) ? $ta : $tb;
    $big   = count($ta) <= count($tb) ? $tb : $ta;
    foreach ($small as $t) {
        if (mb_strlen($t, 'UTF-8') < 2) { return false; }   // এক অক্ষরের টোকেনে মেলানো যায় না
        if (!in_array($t, $big, true)) { return false; }
    }
    return true;
}

// কতটা মিললে "কাছাকাছি" বলা যাবে (এর নিচে কিছুই দেখানো হয় না)
const GM_CLOSE_MIN = 0.82;

/**
 * মূল ইঞ্জিন — গ্রুপের নাম ↔ রেজিস্ট্রেশন মেলানো।
 *
 * $groupNames : gm_parse_names()-এর 'names'
 * $regs       : registrations রো (facebook_id / customer_name / id / phone / … )
 *
 * মিলের স্তর (উপরেরটা পেলে নিচেরগুলো আর দেখানো হয় না — নাহলে তালিকা আবর্জনায় ভরে যেত):
 *   fb    = ফেসবুক আইডি নাম হুবহু
 *   name  = শিক্ষার্থী/অভিভাবকের নাম হুবহু
 *   part  = এক নাম আরেকটার ভেতরে ("Jannatul" ⊂ "Jannatul Ferdaus")
 *   close = বানানে কাছাকাছি (≥ GM_CLOSE_MIN)
 * 🔴 part/close **কখনো নিশ্চিত নয়** — UI-তে সবসময় "নিজে দেখে নিন" বলতে হবে।
 */
function gm_match_names(array $groupNames, array $regs): array
{
    $entries = [];
    $regHit  = [];   // reg id => কয়টা গ্রুপ-নাম একে দাবি করেছে

    foreach ($groupNames as $g) {
        $gname   = (string) $g['name'];
        $buckets = ['fb' => [], 'name' => [], 'part' => [], 'close' => []];

        foreach ($regs as $r) {
            $fb  = trim((string) ($r['facebook_id'] ?? ''));
            $cn  = trim((string) ($r['customer_name'] ?? ''));
            $gn  = gm_norm($gname);

            if ($fb !== '' && gm_norm($fb) === $gn) {
                $buckets['fb'][] = ['reg' => $r, 'how' => 'fb', 'score' => 1.0];
                continue;
            }
            if ($cn !== '' && gm_norm($cn) === $gn) {
                $buckets['name'][] = ['reg' => $r, 'how' => 'name', 'score' => 1.0];
                continue;
            }
            if (($fb !== '' && gm_token_subset($fb, $gname)) || ($cn !== '' && gm_token_subset($cn, $gname))) {
                $buckets['part'][] = ['reg' => $r, 'how' => 'part', 'score' => 0.9];
                continue;
            }
            $sim = max(
                $fb !== '' ? gm_similarity($fb, $gname) : 0.0,
                $cn !== '' ? gm_similarity($cn, $gname) : 0.0
            );
            if ($sim >= GM_CLOSE_MIN) {
                $buckets['close'][] = ['reg' => $r, 'how' => 'close', 'score' => round($sim, 3)];
            }
        }

        // সবচেয়ে ভালো স্তরটাই রাখা হয়
        $matches = [];
        foreach (['fb', 'name', 'part', 'close'] as $tier) {
            if ($buckets[$tier]) {
                $matches = $buckets[$tier];
                break;
            }
        }
        usort($matches, fn($a, $b) => $b['score'] <=> $a['score']);

        foreach ($matches as $m) {
            $rid = (int) ($m['reg']['id'] ?? 0);
            $regHit[$rid] = ($regHit[$rid] ?? 0) + 1;
        }

        $entries[] = [
            'name'    => $gname,
            'count'   => (int) $g['count'],
            'matches' => $matches,
            'notes'   => gm_entry_notes((int) $g['count'], $matches),
        ];
    }

    // যাদের কেউ দাবি করেনি — "রেজিস্ট্রেশন আছে, গ্রুপে পাইনি"
    $missing = [];
    foreach ($regs as $r) {
        if (empty($regHit[(int) ($r['id'] ?? 0)])) {
            $missing[] = $r;
        }
    }

    $matchedCount = 0;
    foreach ($entries as $e) {
        if ($e['matches']) { $matchedCount++; }
    }

    return [
        'entries' => $entries,
        'missing' => $missing,
        'stats'   => [
            'group'     => count($entries),
            'regs'      => count($regs),
            'matched'   => $matchedCount,
            'unmatched' => count($entries) - $matchedCount,
            'missing'   => count($missing),
        ],
    ];
}

/**
 * এক-নামে-একাধিক পরিস্থিতির সতর্কবার্তা — ইউজার হুবহু এটাই চেয়েছেন।
 * 🔴 সিস্টেম কখনো নিজে সিদ্ধান্ত নেয় না, শুধু "অন্য তথ্য থেকে যাচাই করুন" বলে।
 */
function gm_entry_notes(int $groupCount, array $matches): array
{
    $notes = [];
    $m     = count($matches);

    if ($groupCount > 1) {
        $notes[] = $m === 0
            ? 'মেসেঞ্জারে এই নামে ' . $groupCount . ' জন — কারো রেজিস্ট্রেশন পাইনি।'
            : 'মেসেঞ্জারে এই নামে ' . $groupCount . ' জন, রেজিস্ট্রেশন পেয়েছি ' . $m . 'টি — একই ব্যক্তি কিনা অন্য তথ্য (ফোন/শিশুর নাম) থেকে যাচাই করুন।';
    }
    if ($m > 1) {
        $notes[] = 'এই নামে ' . $m . 'টি রেজিস্ট্রেশন আছে — কোনটা এই সদস্য, অন্য তথ্য মিলিয়ে নিন।';
    }
    if ($m === 1 && in_array($matches[0]['how'], ['part', 'close'], true)) {
        $notes[] = $matches[0]['how'] === 'part'
            ? 'নামের একটা অংশ মিলেছে — নিশ্চিত নয়, দেখে নিন।'
            : 'বানানে কাছাকাছি (' . round($matches[0]['score'] * 100) . '%) — নিশ্চিত নয়, দেখে নিন।';
    }
    return $notes;
}

// মিলের ধরন → বাংলা লেবেল ও রঙ
/**
 * মেলানোর ফলাফল → হিস্ট্রিতে সংরক্ষণের জন্য ছোট স্ন্যাপশট (২০২৬-০৯-২৯, ইউজারের চাওয়া)।
 *
 * 🔴🔴 **ফোন নম্বর কখনো এখানে যাবে না** — রিপোর্টে ফোন দেখানো হয় ঠিকই, কিন্তু সেটা
 *    `registrations` থেকে **তখনকার মতো** পড়া হয়। হিস্ট্রিতে ফোন জমালে একই তথ্য দুই
 *    টেবিলে ছড়াত, আর অভিভাবক নম্বর বদলালে পুরনো রেকর্ডে ভুল নম্বর থেকে যেত।
 *    পুরনো রান খুললে রেজিস্ট্রেশনের **id** দিয়ে বর্তমান ফোন তোলা হয় (অ্যাডমিন-নোটের মতোই)।
 *    (একই নীতি `admin/includes/activity.php`-এ লেখা আছে — লগে ফোন/টাকা নয়।)
 *
 * 🔴 যা রাখা হয়: গ্রুপের নাম · গ্রুপে কতবার · কোন রেজিস্ট্রেশনে মিলেছে (id + নাম + স্তর)
 *    · সতর্কবার্তা · "গ্রুপে পাইনি" তালিকা (id + নাম) · অপাঠ্য লাইন · গণনা।
 * ⚠️ রেজিস্ট্রেশন পরে ডিলিট হলে id আর মিলবে না — তাই **নামটাও** রাখা হয়
 *    (স্ন্যাপশট, `registrations.item_title`-এর মতোই নীতি)।
 */
function gm_snapshot(array $result, array $parsed): array
{
    $entries = [];
    foreach ($result['entries'] as $e) {
        $m = [];
        foreach ($e['matches'] as $mt) {
            $m[] = [
                'id'   => (int) ($mt['reg']['id'] ?? 0),
                'name' => (string) ($mt['reg']['customer_name'] ?? ''),
                'fb'   => (string) ($mt['reg']['facebook_id'] ?? ''),
                'how'  => (string) ($mt['how'] ?? ''),
            ];
        }
        $entries[] = [
            'name'    => (string) $e['name'],
            'count'   => (int) $e['count'],
            'matches' => $m,
            'notes'   => array_values($e['notes']),
        ];
    }

    $missing = [];
    foreach ($result['missing'] as $r) {
        $missing[] = [
            'id'   => (int) ($r['id'] ?? 0),
            'name' => (string) ($r['customer_name'] ?? ''),
            'fb'   => (string) ($r['facebook_id'] ?? ''),
        ];
    }

    return [
        'v'          => 1,                       // ভার্সন — গঠন বদলালে পুরনো রান পড়তে কাজে লাগবে
        'entries'    => $entries,
        'missing'    => $missing,
        'unreadable' => array_values($parsed['unreadable'] ?? []),
        'dropped'    => (int) ($parsed['dropped'] ?? 0),
        'lines'      => (int) ($parsed['lines'] ?? 0),
        'stats'      => $result['stats'],
    ];
}

function gm_how_label(string $how): array
{
    return [
        'fb'    => ['ফেসবুক আইডি নাম মিলেছে', 'bg-green-100 text-green-800'],
        'name'  => ['নাম মিলেছে', 'bg-green-100 text-green-800'],
        'part'  => ['নামের অংশ মিলেছে', 'bg-amber-100 text-amber-800'],
        'close' => ['কাছাকাছি নাম', 'bg-amber-100 text-amber-800'],
    ][$how] ?? ['মিলেছে', 'bg-gray-100 text-gray-600'];
}
