<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 SMS-ভিত্তিক পেমেন্ট যাচাই — ইঞ্জিন  (২০২৬-১০-০৬, ধাপ ১)
 *
 * বিকাশ/নগদের টাকা-পাওয়ার SMS একটা পুরনো ফোন থেকে আমাদের সার্ভারে আসে
 * (`sms-in.php`), এখানে পার্স হয়ে `payment_sms`-এ জমা হয়। পরে অভিভাবকের দেওয়া
 * TrxID-র সাথে মিলিয়ে দেখা হয়। পুরো নকশা → PAYMENT-SMS-PLAN.md
 *
 * 🔴🔴 সবচেয়ে বড় নিয়ম — এই সাবসিস্টেম কখনো `registration_payments` বা `income`-এ
 *      লেখে না। যাচাই হওয়া টাকা `payment_claims`-এ **অপেক্ষা করে**, অ্যাডমিন এক
 *      ট্যাপে খাতায় বসান — তখন বিদ্যমান প্রমাণিত পথেই যায়
 *      (`pay_allocate_paid()` → `pay_save_rows()` → `sync_income_for_status()`)।
 *      কারণ: `sync_income_for_status()` খাতা পেলেই আয় = মোট জমা বসায় আর ওটা
 *      ৭ জায়গা থেকে ডাকা হয় — খাতায় সরাসরি রো বসালে পরের যেকোনো সেভে আয়
 *      **নীরবে** বসে যেত (২০২৬-০৯-২৯-এর `$prefill` বাগের হুবহু পুনরাবৃত্তি)।
 *
 * 🔴 এই ফাইলের উপরের অংশ **বিশুদ্ধ ফাংশন** — DB/সেশন/আউটপুট কিছুই ছোঁয় না,
 *    তাই আসল SMS দিয়ে ইউনিট-টেস্ট করা যায় (`t/paymentsms.php`)। DB-ছোঁয়া
 *    ফাংশনগুলো নিচের আলাদা ঘরে, প্রতিটা `PDO` প্যারামিটার নেয়।
 *
 * 🔴 এই ফাইল কিছু `require` করে না (একা লোড করা যায়)। `bd_phone_canonical()`
 *    থাকলে সেটাই ব্যবহার করে, না থাকলে নিজের ফলব্যাক — দুই রূপ কখনো আলাদা হবে না।
 * ════════════════════════════════════════════════════════════════════════════ */

const PSMS_MAX_TEXT      = 2000; // একটা SMS-এর সর্বোচ্চ দৈর্ঘ্য (এর বেশি = ছাঁটা)
const PSMS_MAX_TEMPLATE  = 1000; // অ্যাডমিনের ছাঁচের সর্বোচ্চ দৈর্ঘ্য
const PSMS_KEEP_DAYS     = 730;  // দাবির সাথে জোড়া লাগেনি এমন SMS কত দিন রাখা হবে
const PSMS_HEARTBEAT_HRS = 6;    // এত ঘণ্টা SMS না এলে অ্যাডমিনকে সতর্ক করা হবে

/* ────────────────────────────────────────────────────────────────────────────
 * ঘর (placeholder) — অ্যাডমিন ছাঁচে এগুলোই লেখেন
 *
 * 🔴 কাঁচা regex অ্যাডমিনকে দেখানো হয় না (ইউজারের কোডিং জ্ঞান নেই) — তিনি
 *    নমুনা SMS পেস্ট করে বদলে-যাওয়া অংশে এই ঘরগুলো বসান, সিস্টেম regex বানায়।
 *
 * ⚠️ `re` গুলোতে ইচ্ছাকৃতভাবে `u` মডিফায়ার ধরা হয়নি — SMS সবসময় বৈধ UTF-8
 *    নাও হতে পারে (অপারেটরের গেটওয়ে মাঝেমধ্যে ভাঙা বাইট পাঠায়), আর `u` থাকলে
 *    `preg_match()` তখন **চুপচাপ false** দিত আর SMS নীরবে হারাত। literal অংশ
 *    `preg_quote()` করা, তাই বাইট-ভিত্তিক মিলই যথেষ্ট।
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_placeholders(): array
{
    $amount = '([0-9][0-9,]*(?:\.[0-9]{1,2})?)';
    return [
        'amount'   => ['re' => $amount, 'label' => 'টাকার অঙ্ক',        'hint' => 'যেমন 750.00 বা 3,055.50'],
        'number'   => ['re' => '((?:\+?88)?01[0-9]{9})', 'label' => 'যে নম্বর থেকে এসেছে', 'hint' => 'যেমন 01713750530'],
        'trxid'    => ['re' => '([A-Za-z0-9]{6,20})', 'label' => 'TrxID / TxnID',    'hint' => 'যেমন DJ21B6137N'],
        'datetime' => ['re' => '([0-9]{1,2}[/-][0-9]{1,2}[/-][0-9]{2,4}[ \t]+[0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?(?:[ \t]*[APap][Mm])?)', 'label' => 'তারিখ ও সময়', 'hint' => 'যেমন 02/10/2026 20:55'],
        'date'     => ['re' => '([0-9]{1,2}[/-][0-9]{1,2}[/-][0-9]{2,4})', 'label' => 'শুধু তারিখ', 'hint' => 'যেমন 02/10/2026'],
        'time'     => ['re' => '([0-9]{1,2}:[0-9]{2}(?::[0-9]{2})?)', 'label' => 'শুধু সময়', 'hint' => 'যেমন 20:55'],
        'fee'      => ['re' => $amount, 'label' => 'ফি (চার্জ)',        'hint' => 'যেমন 0.00'],
        'balance'  => ['re' => $amount, 'label' => 'ব্যালান্স',          'hint' => '🔴 শুধু পার্স করা হয়, কোথাও দেখানো হয় না'],
        'ref'      => ['re' => '([^.\r\n]{1,60})', 'label' => 'Ref / রেফারেন্স', 'hint' => 'বিকাশের "Ref phonix." ঘরটা'],
        'text'     => ['re' => '([^\r\n]{1,120})', 'label' => 'যেকোনো লেখা',   'hint' => 'একটা লাইনের ভেতরের যেকোনো অংশ'],
        '*'        => ['re' => '(?:.*?)', 'label' => 'যা-ই থাকুক',      'hint' => 'যে অংশটা আমাদের দরকার নেই'],
    ];
}

// ছাঁচে অবশ্যই যে ঘরগুলো থাকতে হবে — টাকার পরিচয় এই দুটোতেই
function psms_required_placeholders(): array
{
    return ['trxid', 'amount'];
}

/* ────────────────────────────────────────────────────────────────────────────
 * ছাঁচ → regex
 *
 * 🔴 literal অংশের **প্রতিটা হোয়াইটস্পেস-গুচ্ছ `\s*` হয়ে যায়** — SMS-এ কখনো
 *    স্পেস, কখনো নতুন লাইন থাকে (নগদের বার্তা কয়েক লাইনে আসে), আর অপারেটরভেদে
 *    বদলায়ও। হুবহু স্পেস ধরলে ছাঁচটা একটা ফোনে চলত, আরেকটায় না।
 * 🔴 একই ঘর দুইবার দিলে এরর — নাহলে কোন বন্ধনী কোন ঘর সেটা অস্পষ্ট হয়ে যেত।
 *
 * ফেরে: ['regex' => '~…~is', 'fields' => ['amount','number',…], 'error' => '']
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_template_to_regex(string $tpl): array
{
    $fail = static fn(string $m): array => ['regex' => '', 'fields' => [], 'error' => $m];

    $tpl = trim(psms_clean_text($tpl));
    if ($tpl === '') {
        return $fail('ছাঁচটা খালি।');
    }
    if (strlen($tpl) > PSMS_MAX_TEMPLATE) {
        return $fail('ছাঁচটা খুব বড় (সর্বোচ্চ ' . PSMS_MAX_TEMPLATE . ' অক্ষর)।');
    }

    $known = psms_placeholders();
    // DELIM_CAPTURE: বিজোড় সূচকে ঘরের নাম, জোড় সূচকে literal
    $parts = preg_split('/\{([A-Za-z*]+)\}/', $tpl, -1, PREG_SPLIT_DELIM_CAPTURE);
    if (!is_array($parts)) {
        return $fail('ছাঁচটা পড়া গেল না।');
    }

    $re = '';
    $fields = [];
    foreach ($parts as $i => $part) {
        if ($i % 2 === 0) {
            $re .= psms_quote_literal($part);
            continue;
        }
        $name = strtolower($part);
        if (!isset($known[$name])) {
            return $fail('অচেনা ঘর: {' . $part . '} — চেনা ঘরগুলো: ' . implode(', ', array_map(static fn($k) => '{' . $k . '}', array_keys($known))));
        }
        if ($name !== '*') {
            if (in_array($name, $fields, true)) {
                return $fail('{' . $name . '} ঘরটা একবারের বেশি দেওয়া যাবে না।');
            }
            $fields[] = $name;
        }
        $re .= $known[$name]['re'];
    }

    foreach (psms_required_placeholders() as $need) {
        if (!in_array($need, $fields, true)) {
            return $fail('{' . $need . '} ঘরটা অবশ্যই থাকতে হবে — এটা ছাড়া টাকাটা চেনা যায় না।');
        }
    }

    return ['regex' => '~' . $re . '~is', 'fields' => $fields, 'error' => ''];
}

// literal অংশ regex-নিরাপদ করা, হোয়াইটস্পেস-গুচ্ছ `\s*` করে
// (🔴 `preg_replace` দিয়ে নয় — replacement স্ট্রিং-এ ব্যাকস্ল্যাশের আচরণ ঘোলাটে,
//  তাই নিজে ভেঙে জোড়া দেওয়া হয়)
function psms_quote_literal(string $lit): string
{
    if ($lit === '') {
        return '';
    }
    $chunks = preg_split('/\s+/', $lit);
    return implode('\s*', array_map(static fn($c) => preg_quote($c, '~'), $chunks));
}

/* ────────────────────────────────────────────────────────────────────────────
 * একটা প্যাটার্ন একটা SMS-এ প্রয়োগ
 *
 * $fields = ছাঁচে যে ক্রমে ঘরগুলো ছিল; regex-এর ১,২,৩… বন্ধনী ঐ ক্রমেই।
 * ফেরে null (মেলেনি) অথবা নরমালাইজ করা মান
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_apply(string $regex, array $fields, string $text): ?array
{
    if ($regex === '') {
        return null;
    }
    $ok = @preg_match($regex, $text, $m);
    if ($ok !== 1) {
        return null; // false (ভাঙা regex) আর 0 (মেলেনি) — দুটোতেই "মেলেনি"
    }

    $raw = [];
    foreach ($fields as $i => $name) {
        $raw[$name] = $m[$i + 1] ?? '';
    }

    $trx = psms_trx_norm($raw['trxid'] ?? '');
    $amt = psms_num($raw['amount'] ?? null);
    if ($trx === '' || $amt === null) {
        return null; // বন্ধনী মিলেছে কিন্তু মান অর্থহীন — ধরা হবে না
    }

    $dt = $raw['datetime'] ?? '';
    if ($dt === '' && isset($raw['date'])) {
        $dt = trim($raw['date'] . ' ' . ($raw['time'] ?? '00:00'));
    }

    return [
        'amount'        => $amt,
        'sender_number' => psms_phone_norm($raw['number'] ?? ''),
        'trxid'         => trim($raw['trxid'] ?? ''),
        'trxid_norm'    => $trx,
        'fee'           => psms_num($raw['fee'] ?? null),
        'balance'       => psms_num($raw['balance'] ?? null),
        'ref_text'      => mb_substr(trim($raw['ref'] ?? ($raw['text'] ?? '')), 0, 120),
        'sent_at'       => psms_datetime($dt),
        'raw_fields'    => $raw,
    ];
}

// প্যাটার্নের তালিকা ধরে পার্স — প্রথম যেটা মেলে সেটাই (তালিকা sort_order অনুযায়ী)
function psms_parse(string $text, array $patterns): ?array
{
    $text = psms_clean_text($text);
    if ($text === '') {
        return null;
    }
    foreach ($patterns as $p) {
        $fields = psms_pattern_fields($p);
        $hit = psms_apply((string) ($p['pattern'] ?? ''), $fields, $text);
        if ($hit !== null) {
            $hit['pattern_id']          = (int) ($p['id'] ?? 0);
            $hit['provider']            = (string) ($p['provider'] ?? '');
            $hit['pattern_label']       = (string) ($p['label'] ?? '');
            $hit['is_customer_payment'] = !empty($p['is_customer_payment']);
            return $hit;
        }
    }
    return null;
}

// প্যাটার্ন-রো থেকে ঘরের ক্রম (DB-তে JSON হিসেবে রাখা)
function psms_pattern_fields(array $p): array
{
    $raw = $p['fields_json'] ?? '';
    if (is_array($raw)) {
        return array_values(array_filter($raw, 'is_string'));
    }
    $dec = json_decode((string) $raw, true);
    return is_array($dec) ? array_values(array_filter($dec, 'is_string')) : [];
}

/* ────────────────────────────────────────────────────────────────────────────
 * ছোট নরমালাইজার
 * ──────────────────────────────────────────────────────────────────────────── */

// SMS-এর কাঁচা লেখা পরিষ্কার: লাইন-শেষ এক রূপে, কন্ট্রোল-ক্যারেক্টার বাদ, দৈর্ঘ্য সীমা
function psms_clean_text(string $s): string
{
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    // ⚠️ `/u` মডিফায়ার ভাঙা বাইটে চুপচাপ **null** দেয় (অপারেটরের গেটওয়ে মাঝেমধ্যে
    //    ভাঙা UTF-8 পাঠায়) — তখন SMS নীরবে হারাত। তাই null হলে ASCII-নিরাপদ পথে ছাঁকা হয়।
    $clean = preg_replace('/[^\P{C}\n]+/u', '', $s);
    if ($clean === null) {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/', '', $s) ?? $s;
    }
    // 🔴 `substr()` নয় — ওটা বাংলা অক্ষরের মাঝখানে কাটে (প্রতিষ্ঠিত নিয়ম)
    return strlen($clean) > PSMS_MAX_TEXT ? mb_strcut($clean, 0, PSMS_MAX_TEXT) : $clean;
}

// "3,055.50" → 3055.50  (🔴 কমা না সরালে PHP শুধু `3` পড়ত — আসল নমুনায় ধরা)
function psms_num($s): ?float
{
    if ($s === null || $s === '' || is_bool($s)) {
        return null;
    }
    $t = str_replace([',', ' ', "\t"], '', (string) $s);
    return is_numeric($t) ? (float) $t : null;
}

// TrxID মেলানোর রূপ — বড় হাতের, শুধু অক্ষর-সংখ্যা
// 🔴 O↔0 / I↔1 এখানে **গুলিয়ে ফেলা হয় না** — টাকার হিসাবে অনুমান নিষিদ্ধ।
//    "প্রায় মিলেছে" ইঙ্গিত অ্যাডমিন-কিউতে আলাদাভাবে দেখানো হয় (psms_trx_loose())।
function psms_trx_norm(string $s): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
}

// শুধু "প্রায় মিলেছে" ইঙ্গিত দেখানোর জন্য (🔴 অটো-অনুমোদনে কখনো ব্যবহার নয়)
function psms_trx_loose(string $s): string
{
    return strtr(psms_trx_norm($s), ['O' => '0', 'I' => '1', 'L' => '1', 'S' => '5', 'B' => '8', 'Z' => '2']);
}

// নম্বরের তুলনার রূপ — সাইটের বাকি অংশের সাথে এক রাখতে `bd_phone_canonical()`
function psms_phone_norm(string $s): string
{
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    if (function_exists('bd_phone_canonical')) {
        return bd_phone_canonical($s);
    }
    $d = preg_replace('/[^0-9]/', '', $s) ?? '';
    $last10 = strlen($d) >= 10 ? substr($d, -10) : $d;
    return (strlen($last10) === 10 && $last10[0] === '1') ? '0' . $last10 : $d;
}

// SMS-এর তারিখ → 'Y-m-d H:i:s'
// 🔴 DD/MM/YYYY **আগে** — MM/DD ধরলে 02/10/2026 হতো ১০ ফেব্রুয়ারি (নমুনা থেকে শেখা)
function psms_datetime(?string $s): ?string
{
    $s = trim((string) $s);
    if ($s === '') {
        return null;
    }
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'd/m/y H:i', 'd-m-y H:i',
              'd/m/Y h:i A', 'd/m/Y h:iA', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y', 'd-m-Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $s);
        $err = DateTime::getLastErrors();
        $bad = is_array($err) ? (($err['warning_count'] ?? 0) + ($err['error_count'] ?? 0)) : 0;
        if ($dt instanceof DateTime && $bad === 0) {
            $y = (int) $dt->format('Y');
            if ($y >= 2000 && $y <= 2100) {
                return $dt->format('Y-m-d H:i:s');
            }
        }
    }
    return null;
}

// একই SMS দুইবার এলে চেনার চিহ্ন (হোয়াইটস্পেস উপেক্ষা করে)
function psms_fingerprint(string $text): string
{
    return hash('sha256', trim(preg_replace('/\s+/', ' ', psms_clean_text($text)) ?? ''));
}

/* ────────────────────────────────────────────────────────────────────────────
 * 🛡️ প্রেরক যাচাই — "SMS-টা সত্যিই বিকাশ/নগদ থেকে এসেছে তো?"  (২০২৬-১০-০৬)
 *
 * 🔴🔴 কেন দরকার: আমাদের ফোনে **যে কেউ** SMS পাঠাতে পারে। কেউ অন্যের আসল
 *      বার্তা কপি করে, বা TrxID বসিয়ে নিজে একটা বার্তা বানিয়ে পাঠালে সেটাও
 *      ছাঁচে মিলে যেত আর "টাকা এসেছে" হিসেবে বসত।
 *
 * 🔑 আসল প্রতিরক্ষা অপারেটরের কাছেই আছে: বিকাশ/নগদের বার্তা আসে **নিবন্ধিত
 *    নাম-মাস্ক** দিয়ে (`bKash`, `NAGAD`) — সাধারণ মোবাইল থেকে ঐ নামে SMS
 *    পাঠানো **যায় না**। নকল করতে গেলে প্রেরক হয় `+8801XXXXXXXXX`, অর্থাৎ
 *    একটা ফোন নম্বর — দেখলেই আলাদা।
 *
 * 🔴 তাই নিয়ম: **হুবহু নাম মিললে তবেই বিশ্বস্ত** (অংশ-মিল নয়) — নাহলে
 *    `bkash-refund` বা `NAGADX` জাতীয় নামও পাস করে যেত।
 * 🔴 অবিশ্বস্ত SMS **কখনো ফেলে দেওয়া হয় না** — জমা হয়, শুধু চিহ্নিত থাকে
 *    (পুরো প্রজেক্টের "কাঁচা SMS হারানো যাবে না" নিয়ম)।
 * 🔴 ধাপ ২-এর নিয়ম: **`psms_sender_status() === 'trusted'` না হলে কোনো দাবি
 *    অটো-অনুমোদন পাবে না** — অ্যাডমিন নিজে দেখে সিদ্ধান্ত নেবেন।
 * ──────────────────────────────────────────────────────────────────────────── */

const PSMS_SENDER_MAX   = 60; // একটা প্রেরকের নামের সর্বোচ্চ দৈর্ঘ্য
const PSMS_SENDER_LIMIT = 30; // হোয়াইটলিস্টে সর্বোচ্চ কয়টা নাম

// ডিফল্ট বিশ্বস্ত প্রেরক (অ্যাডমিন সেটিংসে না লিখলে এগুলোই)
function psms_default_trusted_senders(): array
{
    return ['bKash', 'NAGAD', 'Rocket', 'upay'];
}

// নাম স্বাভাবিক করা — ছোট হাতের, অক্ষর/সংখ্যা ছাড়া সব বাদ
// ("bKash" · "BKASH" · " bkash " তিনটাই এক; "-"/"."/স্পেস উপেক্ষিত)
function psms_sender_norm(string $s): string
{
    return (string) preg_replace('/[^a-z0-9]+/', '', strtolower(trim($s)));
}

// প্রেরকটা কি নিছক একটা ফোন নম্বর? (নাম-মাস্ক নয় — অর্থাৎ যে কেউ পাঠাতে পারে)
function psms_sender_is_number(string $s): bool
{
    $n = psms_sender_norm($s);
    return $n !== '' && strlen($n) >= 4 && ctype_digit($n);
}

// অ্যাডমিনের লেখা তালিকা (কমা/সেমিকোলন/নতুন লাইন — যেভাবেই লিখুন) → অ্যারে
function psms_parse_sender_list(string $raw): array
{
    $out = [];
    foreach (preg_split('/[,;\r\n]+/', $raw) ?: [] as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        $out[] = mb_substr($p, 0, PSMS_SENDER_MAX);
        if (count($out) >= PSMS_SENDER_LIMIT) {
            break;
        }
    }
    return $out;
}

// 🔴 হুবহু মিল (স্বাভাবিক করার পর) — অংশ-মিল ইচ্ছাকৃতভাবে নয়
function psms_sender_trusted(string $sender, array $trusted): bool
{
    $n = psms_sender_norm($sender);
    if ($n === '') {
        return false;
    }
    foreach ($trusted as $t) {
        if (psms_sender_norm((string) $t) === $n) {
            return true;
        }
    }
    return false;
}

/* ────────────────────────────────────────────────────────────────────────────
 * বিল্ট-ইন প্যাটার্ন — বিকাশের দুই রকম + নগদ
 *
 * 🔴 ছাঁচগুলো ইউজারের পাঠানো **আসল** SMS থেকে লেখা (কল্পনা করা নয়) —
 *    `sample_sms` ঘরে ঐ আসল নমুনাই রাখা আছে, যাতে অ্যাডমিন পরে বদলালে
 *    পরীক্ষার বাক্স সাথে সাথে বলে দেয় ছাঁচটা এখনো মেলে কিনা।
 *
 * 🔴 নগদের বার্তায় `Uddokta:` লেখা থাকে — ওটা **এজেন্টের কাছ থেকে নিজের টাকা
 *    তোলা**, গ্রাহকের পেমেন্ট নয়। তাই `is_customer_payment = false` — নাহলে
 *    নিজের ক্যাশ-ইনগুলো "অদাবিকৃত টাকা" তালিকায় ভিড় করত। একই কারণে বিকাশের
 *    `Cash In … successful`-ও false।
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_default_patterns(): array
{
    return [
        [
            'label'      => 'বিকাশ — টাকা পেয়েছি',
            'provider'   => 'bkash',
            'template'   => 'You have received Tk {amount} from {number}. {*}TrxID {trxid} at {datetime}',
            'sample_sms' => 'You have received Tk 3,055.50 from 01713750530. Fee Tk 0.00. Balance Tk 11,917.52. TrxID DJ21B6137N at 02/10/2026 20:55',
            'is_customer_payment' => 1,
            'sort_order' => 1,
        ],
        [
            'label'      => 'বিকাশ — Cash In (নিজের টাকা তোলা)',
            'provider'   => 'bkash',
            'template'   => 'Cash In Tk {amount} from {number} successful. {*}TrxID {trxid} at {datetime}',
            'sample_sms' => 'Cash In Tk 6,120.00 from 01823722506 successful. Fee Tk 0.00. Balance Tk 7,844.24. TrxID DIK8P92C3M at 20/09/2026 22:31. Download App: https://bKa.sh/8app',
            'is_customer_payment' => 0,
            'sort_order' => 2,
        ],
        [
            'label'      => 'নগদ — Cash In (নিজের টাকা তোলা)',
            'provider'   => 'nagad',
            'template'   => 'Amount: Tk {amount}{*}Uddokta: {number}{*}TxnID: {trxid}{*}Balance: {balance}{*}{datetime}',
            'sample_sms' => "Cash In Received.\nAmount: Tk 820.00\nUddokta: 01755593793\nTxnID: 73XUNZOH\nBalance: 2536.00\n18/05/2025 21:22",
            'is_customer_payment' => 0,
            'sort_order' => 3,
        ],
        // ⚠️⚠️ এই একটা ছাঁচ **অনুমান করে লেখা** — ইউজারের পাঠানো নমুনায় নগদের
        //      "টাকা পেয়েছি" বার্তাটা ছিল না (শুধু Uddokta Cash In ছিল)। তাই আসল
        //      বার্তার লেখা একটু অন্যরকম হতে পারে। 🔴 ভুল হলেও **চুপচাপ কিছু হারায় না** —
        //      বার্তাটা `payment_sms`-এ "পড়া যায়নি" হয়ে জমা থাকে, অ্যাডমিন
        //      `admin/sms-patterns.php`-এ আসল নমুনা বসিয়ে ছাঁচটা ঠিক করে
        //      "আবার পড়ান" চাপলেই পুরনোগুলোও পড়া যায়।
        //      **আসল নগদ SMS হাতে পেলে এই ছাঁচ ও নমুনা দুটোই বদলে দিন।**
        [
            'label'      => 'নগদ — টাকা পেয়েছি',
            'provider'   => 'nagad',
            'template'   => 'Money Received.{*}Amount: Tk {amount}{*}Sender: {number}{*}TxnID: {trxid}{*}Balance: {balance}{*}{datetime}',
            'sample_sms' => "Money Received.\nAmount: Tk 750.00\nSender: 01644170419\nTxnID: 73XUNZOI\nBalance: 3286.00\n21/09/2026 14:03",
            'is_customer_payment' => 1,
            'sort_order' => 4,
        ],
    ];
}

/* ════════════════════════════════════════════════════════════════════════════
 * ↓↓↓ এখান থেকে DB-ছোঁয়া অংশ (উপরেরগুলো বিশুদ্ধ ফাংশন) ↓↓↓
 * ════════════════════════════════════════════════════════════════════════════ */

// মাইগ্রেশন চালানো হয়েছে কিনা — স্ট্যাটিক ক্যাশড।
// 🔴 এই গার্ডটা সরাবেন না: এই প্রজেক্টে ফাইল আগে ডিপ্লয় হয়, SQL ইউজার পরে চালান।
function psms_ready(?PDO $db): bool
{
    static $ok = null;
    if ($ok !== null || !$db instanceof PDO) {
        return (bool) $ok;
    }
    $ok = false;
    try {
        foreach (['payment_sms_patterns', 'payment_sms', 'payment_claims'] as $t) {
            $db->query('SELECT id FROM ' . $t . ' LIMIT 0');
        }
        $ok = true;
    } catch (Throwable $e) {
        $ok = false;
    }
    return $ok;
}

// সক্রিয় প্যাটার্নের তালিকা (sort_order অনুযায়ী)
function psms_patterns(PDO $db, bool $activeOnly = true): array
{
    if (!psms_ready($db)) {
        return [];
    }
    try {
        $sql = 'SELECT * FROM payment_sms_patterns';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        return $db->query($sql . ' ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// টেবিল একদম খালি হলে বিল্ট-ইন প্যাটার্নগুলো বসায় (একবারই)।
// 🔴 অ্যাডমিন একটা মুছে দিলে আবার ফিরে আসে না — শুধু **সম্পূর্ণ খালি** হলে বসে।
function psms_ensure_seeds(PDO $db): int
{
    if (!psms_ready($db)) {
        return 0;
    }
    try {
        if ((int) $db->query('SELECT COUNT(*) c FROM payment_sms_patterns')->fetch()['c'] > 0) {
            return 0;
        }
        $n = 0;
        foreach (psms_default_patterns() as $d) {
            $c = psms_template_to_regex((string) $d['template']);
            if ($c['error'] !== '') {
                continue; // 🔴 ভাঙা ছাঁচ কখনো বসানো হবে না
            }
            if (psms_apply($c['regex'], $c['fields'], (string) $d['sample_sms']) === null) {
                continue; // 🔴 নিজের নমুনার সাথেই না মিললে বসানো হবে না
            }
            $db->prepare('INSERT INTO payment_sms_patterns
                    (label, provider, template, pattern, fields_json, sample_sms, is_customer_payment, is_active, sort_order)
                  VALUES (:l, :p, :t, :re, :f, :s, :cp, 1, :o)')
               ->execute([
                   'l' => $d['label'], 'p' => $d['provider'], 't' => $d['template'],
                   're' => $c['regex'], 'f' => json_encode($c['fields']),
                   's' => $d['sample_sms'], 'cp' => (int) $d['is_customer_payment'], 'o' => (int) $d['sort_order'],
               ]);
            $n++;
        }
        return $n;
    } catch (Throwable $e) {
        return 0;
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * আসা SMS জমা করা
 *
 * 🔴 কাঁচা লেখা **কখনো ফেলে দেওয়া হয় না** — কোনো প্যাটার্নে না মিললেও সারিটা
 *    বসে (`parse_status = 'unparsed'`)। অ্যাডমিন ছাঁচ ঠিক করে পরে আবার পার্স
 *    করতে পারেন (`psms_reparse()`); ফেলে দিলে ঐ টাকার প্রমাণ হারাত।
 *
 * ফেরে ['ok'=>bool,'id'=>int,'dup'=>bool,'parsed'=>bool,'reason'=>string]
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_store(PDO $db, string $rawText, string $sender = '', ?string $sentAtHint = null): array
{
    $out = ['ok' => false, 'id' => 0, 'dup' => false, 'parsed' => false, 'reason' => ''];
    if (!psms_ready($db)) {
        $out['reason'] = 'not_ready';
        return $out;
    }
    $text = psms_clean_text($rawText);
    if (trim($text) === '') {
        $out['reason'] = 'empty';
        return $out;
    }
    $hash = psms_fingerprint($text);

    try {
        $prev = $db->prepare('SELECT id FROM payment_sms WHERE raw_hash = :h LIMIT 1');
        $prev->execute(['h' => $hash]);
        $row = $prev->fetch();
        if ($row) {
            // একই SMS আবার এসেছে (ফোনের retry) — নতুন সারি নয়, কিন্তু সফলই বলা হয়,
            // নাহলে MacroDroid অনির্দিষ্টকাল ধরে আবার পাঠাতে থাকত
            return ['ok' => true, 'id' => (int) $row['id'], 'dup' => true, 'parsed' => false, 'reason' => 'duplicate'];
        }

        $hit = psms_parse($text, psms_patterns($db));
        $ins = $db->prepare('INSERT INTO payment_sms
                (pattern_id, provider, sender, raw_text, raw_hash, amount, sender_number,
                 trxid, trxid_norm, fee, balance, ref_text, sent_at, is_customer_payment, parse_status)
              VALUES (:pid, :prov, :snd, :raw, :h, :amt, :num, :trx, :trxn, :fee, :bal, :ref, :sent, :cp, :st)');
        $ins->execute([
            'pid'  => $hit ? ($hit['pattern_id'] ?: null) : null,
            'prov' => $hit ? $hit['provider'] : psms_guess_provider($sender, $text),
            'snd'  => mb_substr(trim($sender), 0, 60),
            'raw'  => $text,
            'h'    => $hash,
            'amt'  => $hit ? $hit['amount'] : null,
            'num'  => $hit ? $hit['sender_number'] : '',
            'trx'  => $hit ? $hit['trxid'] : '',
            'trxn' => $hit ? $hit['trxid_norm'] : '',
            'fee'  => $hit ? $hit['fee'] : null,
            'bal'  => $hit ? $hit['balance'] : null,
            'ref'  => $hit ? $hit['ref_text'] : '',
            'sent' => $hit ? ($hit['sent_at'] ?: psms_datetime($sentAtHint)) : psms_datetime($sentAtHint),
            'cp'   => $hit ? (int) $hit['is_customer_payment'] : 1,
            'st'   => $hit ? 'parsed' : 'unparsed',
        ]);
        $out = ['ok' => true, 'id' => (int) $db->lastInsertId(), 'dup' => false, 'parsed' => (bool) $hit, 'reason' => ''];

        // 🔑 অভিভাবক আগে TrxID দিয়ে থাকলে (SMS তখনো আসেনি) এই বার্তাটা আসা মাত্রই
        //    ঐ অপেক্ষমাণ দাবিটা নিজে থেকেই মিলে যাবে — তাই তাঁকে আর কিছু করতে হয় না।
        // 🔴 নিজের try/catch-এ ও `function_exists` গার্ডে: মেলানো কখনো SMS জমা
        //    হওয়া ভাঙতে পারবে না (এটাই এই এন্ডপয়েন্টের একমাত্র কাজ)।
        if ($hit && function_exists('pclaim_rematch')) {
            try {
                pclaim_rematch($db, (string) $hit['trxid_norm']);
            } catch (Throwable $e) {
                // চুপচাপ
            }
        }
    } catch (Throwable $e) {
        $out['reason'] = 'db_error';
    }
    return $out;
}

// প্রেরকের নাম/লেখা দেখে কোন সেবা — শুধু প্যাটার্ন না মিললে, দেখানোর জন্য
function psms_guess_provider(string $sender, string $text): string
{
    $hay = strtolower($sender . ' ' . substr($text, 0, 120));
    if (strpos($hay, 'bkash') !== false || strpos($hay, 'bka.sh') !== false) {
        return 'bkash';
    }
    if (strpos($hay, 'nagad') !== false) {
        return 'nagad';
    }
    if (strpos($hay, 'rocket') !== false) {
        return 'rocket';
    }
    return '';
}

/* ────────────────────────────────────────────────────────────────────────────
 * বিশ্বস্ত প্রেরকের তালিকা ও রায়
 * 🔴 `get_setting($k) ?: $default` প্যাটার্ন — তৃতীয় প্যারামিটারের উপর ভরসা নয়
 *    (settings-এ খালি স্ট্রিং সেভ হয়ে থাকলে ডিফল্ট আর আসত না; প্রজেক্টের নিয়ম)।
 * ⚠️ `settings` key-value বলে **মাইগ্রেশন লাগে না**।
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_trusted_senders(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $raw  = function_exists('get_setting') ? trim((string) get_setting('sms_trusted_senders')) : '';
    $list = psms_parse_sender_list($raw);
    return $cache = ($list ?: psms_default_trusted_senders());
}

/* একটা SMS-এর প্রেরক নিয়ে রায়:
 *   'trusted' — নিবন্ধিত নাম-মাস্ক, অপারেটর ছাড়া কেউ পাঠাতে পারে না
 *   'number'  — একটা ফোন নম্বর থেকে এসেছে (🔴 যে কেউ পাঠাতে পারে)
 *   'unknown' — নাম আছে কিন্তু তালিকায় নেই (নতুন সেবা হতে পারে, যাচাই করুন)
 *   'missing' — ফোন প্রেরকের নামই পাঠায়নি (MacroDroid-এর ঘর ঠিক নেই)
 */
function psms_sender_status(string $sender): string
{
    if (trim($sender) === '') {
        return 'missing';
    }
    if (psms_sender_trusted($sender, psms_trusted_senders())) {
        return 'trusted';
    }
    return psms_sender_is_number($sender) ? 'number' : 'unknown';
}

/* ────────────────────────────────────────────────────────────────────────────
 * জমা থাকা SMS আবার পার্স করা (ছাঁচ ঠিক করার পর)
 * 🔴 শুধু যেগুলো এখনো কোনো দাবির সাথে জোড়া লাগেনি (`claim_id IS NULL`) —
 *    জোড়া লেগে যাওয়া সারির অঙ্ক বদলে ফেললে হিসাব নীরবে সরে যেত।
 * ──────────────────────────────────────────────────────────────────────────── */
function psms_reparse(PDO $db, int $limit = 500): array
{
    $res = ['scanned' => 0, 'parsed' => 0];
    if (!psms_ready($db)) {
        return $res;
    }
    try {
        $pats = psms_patterns($db);
        if (!$pats) {
            return $res;
        }
        $lim = max(1, min(5000, $limit));
        $rows = $db->query('SELECT id, raw_text, sender FROM payment_sms
                            WHERE claim_id IS NULL AND parse_status <> \'parsed\'
                            ORDER BY id DESC LIMIT ' . $lim)->fetchAll();
        $upd = $db->prepare('UPDATE payment_sms SET pattern_id = :pid, provider = :prov, amount = :amt,
                                sender_number = :num, trxid = :trx, trxid_norm = :trxn, fee = :fee,
                                balance = :bal, ref_text = :ref, sent_at = COALESCE(:sent, sent_at),
                                is_customer_payment = :cp, parse_status = \'parsed\'
                             WHERE id = :id');
        foreach ($rows as $r) {
            $res['scanned']++;
            $hit = psms_parse((string) $r['raw_text'], $pats);
            if (!$hit) {
                continue;
            }
            $upd->execute([
                'pid' => $hit['pattern_id'] ?: null, 'prov' => $hit['provider'],
                'amt' => $hit['amount'], 'num' => $hit['sender_number'],
                'trx' => $hit['trxid'], 'trxn' => $hit['trxid_norm'],
                'fee' => $hit['fee'], 'bal' => $hit['balance'], 'ref' => $hit['ref_text'],
                'sent' => $hit['sent_at'], 'cp' => (int) $hit['is_customer_payment'],
                'id' => (int) $r['id'],
            ]);
            $res['parsed']++;
        }
    } catch (Throwable $e) {
        // চুপচাপ — আবার পার্স করা কখনো পেজ ভাঙবে না
    }
    return $res;
}

// হার্টবিট: শেষ কখন কোনো SMS পেয়েছি (ফোন বন্ধ/নেট নেই = একক ব্যর্থতার বিন্দু)
function psms_last_received(PDO $db): ?string
{
    if (!psms_ready($db)) {
        return null;
    }
    try {
        $v = $db->query('SELECT MAX(received_at) m FROM payment_sms')->fetch()['m'] ?? null;
        return $v ? (string) $v : null;
    } catch (Throwable $e) {
        return null;
    }
}

// হার্টবিট চুপ কিনা (ড্যাশবোর্ড/ইনবক্সের লাল সতর্কতা)
function psms_heartbeat_stale(PDO $db): bool
{
    $last = psms_last_received($db);
    if ($last === null) {
        return false; // একটাও আসেনি = এখনো চালু হয়নি, সতর্কতা অর্থহীন
    }
    return (time() - strtotime($last)) > PSMS_HEARTBEAT_HRS * 3600;
}

// পুরনো, দাবির সাথে জোড়া না লাগা SMS ছাঁটাই (`log_visitor()`-এর মতো probabilistic)
function psms_prune(PDO $db): void
{
    if (!psms_ready($db)) {
        return;
    }
    try {
        if (random_int(1, 100) !== 1) {
            return;
        }
        $db->exec('DELETE FROM payment_sms
                   WHERE claim_id IS NULL
                     AND received_at < (NOW() - INTERVAL ' . (int) PSMS_KEEP_DAYS . ' DAY)');
    } catch (Throwable $e) {
        // চুপচাপ
    }
}
