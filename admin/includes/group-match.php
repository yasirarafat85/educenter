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
        '~(https?://|www\.|@)~iu',                         // লিংক/ইমেইল
    ];
}

/**
 * পেস্ট/স্ক্যান করা কাঁচা লেখা → নামের তালিকা।
 *
 * ফেরে: ['names' => [ ['name' => 'Ayesha Siddika', 'count' => 2], … ], 'dropped' => int, 'lines' => int]
 * একই নাম একাধিকবার এলে **একটাই এন্ট্রি**, সাথে `count` — ইউজার এটাই চেয়েছেন
 * ("মেসেঞ্জারে এই নামে দুই জন")।
 */
function gm_parse_names(string $raw): array
{
    $raw   = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = explode("\n", $raw);

    $out     = [];   // norm => ['name'=>..., 'count'=>...]
    $dropped = 0;
    $seen    = 0;

    foreach ($lines as $line) {
        // ZWSP/ZWNJ/BOM ইত্যাদি — কপি-পেস্টে প্রায়ই ঢোকে, নাহলে মিল ভেঙে যেত
        $line = preg_replace('~[\x{200B}-\x{200F}\x{FEFF}\x{00AD}]~u', '', $line);
        $line = trim((string) $line, " \t\v\0.,·•|-–—*");
        $line = trim(preg_replace('~\s+~u', ' ', $line));
        if ($line === '') {
            continue;
        }
        $seen++;
        if (gm_is_noise($line)) {
            $dropped++;
            continue;
        }
        $key = gm_norm($line);
        if ($key === '') {
            $dropped++;
            continue;
        }
        if (isset($out[$key])) {
            $out[$key]['count']++;
        } else {
            $out[$key] = ['name' => $line, 'count' => 1];
        }
    }
    return ['names' => array_values($out), 'dropped' => $dropped, 'lines' => $seen];
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
function gm_how_label(string $how): array
{
    return [
        'fb'    => ['ফেসবুক আইডি নাম মিলেছে', 'bg-green-100 text-green-800'],
        'name'  => ['নাম মিলেছে', 'bg-green-100 text-green-800'],
        'part'  => ['নামের অংশ মিলেছে', 'bg-amber-100 text-amber-800'],
        'close' => ['কাছাকাছি নাম', 'bg-amber-100 text-amber-800'],
    ][$how] ?? ['মিলেছে', 'bg-gray-100 text-gray-600'];
}
