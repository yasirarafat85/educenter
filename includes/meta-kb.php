<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📚 ফেসবুক উত্তর-ভান্ডার — জ্ঞানভান্ডার + নির্দিষ্ট শব্দে উত্তর (ধাপ ২, ২০২৬-১১-১৭)
 *
 * তিনটা অংশ:
 *   ১. নির্দিষ্ট শব্দে ঠিক করা উত্তর (meta_rules) — "প্রস্তাব" বা "অটো" দুই রকম
 *   ২. জ্ঞানভান্ডার (meta_kb) — প্রশ্নোত্তর ও ডকুমেন্ট; ধাপ ৩-এ AI এখান থেকেই পড়বে
 *   ৩. শেখার প্রস্তাব — আপনার/মডারেটরের হাতের উত্তর থেকে প্রস্তাব তৈরি হয়;
 *      🔴 অনুমোদন ছাড়া কিছুই শেখা হয় না, গ্রাহকের মেসেজ থেকে সরাসরি কখনো নয়।
 *
 * 🔴 অটো-উত্তর ডিফল্টে বন্ধ (`meta_autoreply_on` = '1' হলে চালু), আর চালু থাকলেও
 *    শুধু "অটো" চিহ্নিত নিয়মই পাঠায় — বাকিরা শুধু ইনবক্সে প্রস্তাব দেখায়।
 * 🔴 দাম/ব্যাচ কখনো ভান্ডারে লেখা থাকে না — {courses} প্রতিবার DB থেকে টাটকা।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/meta.php';

const MKB_COOLDOWN_HOURS   = 12;   // একই মানুষকে একই নিয়মে আবার অটো-উত্তর কত ঘণ্টা পরে
const MKB_MAX_AUTO_PER_HOUR = 3;   // এক কথোপকথনে ঘণ্টায় সর্বোচ্চ অটো-উত্তর (লুপ ঠেকাতে)
const MKB_HUMAN_QUIET_MIN  = 30;   // মানুষ উত্তর দিলে এত মিনিট অটো চুপ
const MKB_MAX_AUTO_PER_RUN = 5;    // এক ওয়েবহুকে সর্বোচ্চ কয়টা অটো-উত্তর
const MKB_DOC_MAX_CHARS    = 60000;
const MKB_MIN_ANSWER_CHARS = 12;   // এর চেয়ে ছোট উত্তর ("ধন্যবাদ") থেকে প্রস্তাব নয়
const MKB_SUGGEST_MIN      = 0.34;
const MKB_SCAN_LIMIT       = 300;

/* ───────────────────────── বিশুদ্ধ ফাংশন ───────────────────────── */

/** তুলনার জন্য লেখা সমান করা: ছোট হাতের, বাংলা অঙ্ক → English, যতিচিহ্ন → ফাঁকা */
function mkb_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, ['০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9']);
    $s = preg_replace('/[^\p{L}\p{N}\p{M}]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

/** "দাম, ফি\nকত টাকা" → ['দাম', 'ফি', 'কত টাকা'] */
function mkb_keywords(string $raw): array
{
    $out = [];
    foreach (preg_split('/[\r\n,،;]+/u', $raw) ?: [] as $k) {
        $k = mkb_norm($k);
        if ($k !== '' && !in_array($k, $out, true)) { $out[] = $k; }
        if (count($out) >= 40) { break; }
    }
    return $out;
}

/**
 * একটা শব্দ লেখায় আছে কিনা।
 * 🔴 ইংরেজি শব্দ পুরো শব্দ হিসেবে মেলে ("hi" যেন "this"-এ না মেলে);
 *    বাংলা শব্দ অংশ হিসেবেও মেলে ("দাম" → "দামটা কত") — বাংলায় শব্দের শেষে বিভক্তি বসে।
 */
function mkb_keyword_in(string $kw, string $normText): bool
{
    if ($kw === '' || $normText === '') { return false; }
    if (preg_match('/^[a-z0-9 ]+$/', $kw)) {
        return strpos(' ' . $normText . ' ', ' ' . $kw . ' ') !== false;
    }
    return mb_strpos($normText, $kw) !== false;
}

/** নিয়ম এই লেখায়/এই জায়গায় খাটে কিনা */
function mkb_rule_matches(array $rule, string $text, string $channel): bool
{
    if (empty($rule['is_active'])) { return false; }
    $ch = (string) ($rule['channel'] ?? 'both');
    if ($ch !== 'both' && $ch !== $channel) { return false; }
    $norm = mkb_norm($text);
    foreach (mkb_keywords((string) ($rule['keywords'] ?? '')) as $kw) {
        if (mkb_keyword_in($kw, $norm)) { return true; }
    }
    return false;
}

/** প্রথম যে নিয়মটা খাটে (ক্রম অনুযায়ী) */
function mkb_rule_pick(array $rules, string $text, string $channel, ?string $mode = null): ?array
{
    foreach ($rules as $r) {
        if ($mode !== null && ($r['mode'] ?? '') !== $mode) { continue; }
        if (mkb_rule_matches($r, $text, $channel)) { return $r; }
    }
    return null;
}

/** খুব সাধারণ শব্দ — মিল খোঁজায় গোনা হয় না */
function mkb_stopwords(): array
{
    return ['কি', 'কী', 'আছে', 'আমি', 'আমার', 'আপনার', 'আপনি', 'এই', 'ওই', 'একটা', 'কিভাবে', 'কীভাবে', 'করে', 'করব', 'করবো',
            'হবে', 'হয়', 'জন্য', 'দিয়ে', 'থেকে', 'এবং', 'আর', 'না', 'হ্যাঁ', 'জি', 'জ্বি', 'ভাই', 'আপু', 'প্লিজ', 'দয়া',
            'the', 'is', 'a', 'an', 'to', 'of', 'and', 'for', 'in', 'on', 'me', 'my', 'i', 'you', 'please', 'pls', 'plz'];
}

function mkb_tokens(string $s): array
{
    $stop = array_flip(mkb_stopwords());
    $out = [];
    foreach (explode(' ', mkb_norm($s)) as $t) {
        if (mb_strlen($t) < 2 || isset($stop[$t])) { continue; }
        $out[$t] = true;
    }
    return array_keys($out);
}

/**
 * দুই লেখা কতটা মেলে (০–১)। হুবহু শব্দ = ১, একটা আরেকটার শুরু (৩+ অক্ষর) = ০.৭
 * (বাংলায় "কোর্সের" / "কোর্স" একই শব্দ)।
 */
function mkb_score(array $q, array $c): float
{
    if (!$q || !$c) { return 0.0; }
    $hit = 0.0;
    foreach ($q as $a) {
        $best = 0.0;
        foreach ($c as $b) {
            if ($a === $b) { $best = 1.0; break; }
            $la = mb_strlen($a); $lb = mb_strlen($b);
            if (min($la, $lb) >= 3 && ($la < $lb ? mb_strpos($b, $a) === 0 : mb_strpos($a, $b) === 0)) { $best = max($best, 0.7); }
        }
        $hit += $best;
    }
    return $hit / sqrt(count($q) * count($c));
}

/** উত্তরের {name}/{courses}/{site}/{whatsapp} বসানো */
function mkb_fill(string $reply, array $vars): string
{
    $map = [];
    foreach (['name', 'courses', 'site', 'whatsapp'] as $k) { $map['{' . $k . '}'] = (string) ($vars[$k] ?? ''); }
    $out = strtr($reply, $map);
    $out = preg_replace("/[ \t]+\n/", "\n", $out) ?? $out;
    return trim(preg_replace("/\n{3,}/", "\n\n", $out) ?? $out);
}

/** ডকুমেন্টের লেখা পরিষ্কার: লাইন-শেষ এক রকম, কন্ট্রোল অক্ষর বাদ, সীমা */
function mkb_clean_doc(string $s): string
{
    if (!mb_check_encoding($s, 'UTF-8')) { $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8'); }
    $s = preg_replace('/^\xEF\xBB\xBF/', '', $s) ?? $s;
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? $s;
    $s = preg_replace("/\n{3,}/", "\n\n", $s) ?? $s;
    $s = trim($s);
    return mb_strlen($s) > MKB_DOC_MAX_CHARS ? mb_substr($s, 0, MKB_DOC_MAX_CHARS) : $s;
}

/** .docx থেকে লেখা (লাইব্রেরি ছাড়া — ZipArchive দিয়ে word/document.xml পড়ে) */
function mkb_docx_text(string $path): string
{
    if (!class_exists('ZipArchive')) { return ''; }
    $z = new ZipArchive();
    if ($z->open($path) !== true) { return ''; }
    $xml = (string) $z->getFromName('word/document.xml');
    $z->close();
    if ($xml === '') { return ''; }
    $xml = preg_replace('#</w:p>#', "\n", $xml) ?? $xml;
    $xml = preg_replace('#<w:(tab|br)[^>]*/>#', ' ', $xml) ?? $xml;
    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/* ───────────────────────── সেটিংস ও প্রস্তুতি ───────────────────────── */

function mkb_ready(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $db = $db ?: get_db();
    $ok = meta_ready($db) && db_has_table($db, 'meta_kb') && db_has_table($db, 'meta_rules') && db_has_table($db, 'meta_auto_log');
    return $ok;
}

/** অটো-উত্তর চালু? 🔴 ডিফল্টে বন্ধ — শুধু '1' হলেই চালু */
function mkb_auto_on(): bool
{
    return get_setting('meta_autoreply_on') === '1';
}

/** সব নিয়ম (ক্রম অনুযায়ী)। $fresh দিলে ক্যাশ ছাড়া। */
function mkb_rules(PDO $db, bool $fresh = false): array
{
    static $cache = null;
    if ($cache !== null && !$fresh) { return $cache; }
    try {
        $cache = $db->query('SELECT * FROM meta_rules ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) { $cache = []; }
    return $cache;
}

/** চলমান কোর্সের তালিকা — {courses}। 🔴 প্রতিবার DB থেকে টাটকা, ভর্তি খোলা থাকলেই। */
function mkb_courses_text(PDO $db): string
{
    static $txt = null;
    if ($txt !== null) { return $txt; }
    $lines = [];
    try {
        $rows = $db->query('SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id
                            WHERE cb.is_active = 1 ORDER BY cb.sort_order ASC, cb.id ASC')->fetchAll();
        foreach ($rows as $r) {
            if (!course_reg_open($r)) { continue; }
            $line = '• ' . trim((string) $r['title']) . (trim((string) ($r['batch_name'] ?? '')) !== '' ? ' (' . trim((string) $r['batch_name']) . ')' : '');
            if (trim((string) ($r['price'] ?? '')) !== '') { $line .= ' — ' . trim((string) $r['price']); }
            if ($ts = course_deadline_ts($r['registration_deadline'] ?? null)) {
                $line .= ' · ভর্তির শেষ সময় ' . course_deadline_display($ts);
            }
            $lines[] = $line;
            if (count($lines) >= 8) { break; }
        }
    } catch (Throwable $e) {}
    $txt = $lines ? implode("\n", $lines) : 'এই মুহূর্তে কোনো কোর্সে ভর্তি চলছে না।';
    return $txt;
}

function mkb_vars(PDO $db, ?string $name = null): array
{
    $wa = normalize_bd_whatsapp(get_setting('contact_whatsapp'));
    return [
        'name'     => trim((string) $name),
        'courses'  => mkb_courses_text($db),
        'site'     => rtrim(SITE_URL, '/'),
        'whatsapp' => $wa !== '' ? 'https://wa.me/' . $wa : '',
    ];
}

/* ───────────────────────── প্রস্তাব (ইনবক্সে দেখানো) ───────────────────────── */

/**
 * একটা মেসেজ/কমেন্টের জন্য প্রস্তাবিত উত্তর — মিলে যাওয়া নিয়ম + ভান্ডারের ৩টা কাছাকাছি প্রশ্নোত্তর।
 * ফেরত: [['from' => 'rule'|'kb', 'label', 'text'], ...] — কিছুই পাঠানো হয় না।
 */
function mkb_suggest(PDO $db, string $text, string $channel, ?string $name = null): array
{
    if (!mkb_ready($db) || trim($text) === '') { return []; }
    $out = [];
    $vars = mkb_vars($db, $name);
    $rule = mkb_rule_pick(mkb_rules($db), $text, $channel);
    if ($rule) {
        $img = mkb_rule_image($db, $rule);
        $out[] = ['from' => 'rule', 'label' => '⚡ নির্দিষ্ট শব্দ: ' . meta_snippet((string) $rule['keywords'], 40), 'text' => mkb_fill((string) $rule['reply'], $vars),
                  'image_id' => $img ? (int) $img['id'] : 0];
    }
    $q = mkb_tokens($text);
    if ($q) {
        $scored = [];
        try {
            $rows = $db->query("SELECT id, question, answer FROM meta_kb WHERE kind = 'qa' AND status = 'active' ORDER BY id DESC LIMIT 2000")->fetchAll();
        } catch (Throwable $e) { $rows = []; }
        foreach ($rows as $r) {
            $s = mkb_score($q, mkb_tokens((string) $r['question']));
            if ($s >= MKB_SUGGEST_MIN) { $scored[] = [$s, $r]; }
        }
        usort($scored, fn($a, $b) => $b[0] <=> $a[0]);
        foreach (array_slice($scored, 0, 3) as [$s, $r]) {
            $out[] = ['from' => 'kb', 'label' => '📚 ' . meta_snippet((string) $r['question'], 50), 'text' => mkb_fill((string) $r['answer'], $vars)];
        }
    }
    return $out;
}

/* ───────────────────────── অটো-উত্তর (ওয়েবহুক থেকে) ───────────────────────── */

function mkb_log(PDO $db, ?int $ruleId, string $channel, string $target, bool $ok, string $err = ''): void
{
    try {
        $db->prepare('INSERT INTO meta_auto_log (rule_id, channel, target, ok, error, sent_at) VALUES (:r, :c, :t, :o, :e, :at)')
           ->execute(['r' => $ruleId, 'c' => $channel, 't' => $target, 'o' => $ok ? 1 : 0, 'e' => $err !== '' ? mb_substr($err, 0, 250) : null, 'at' => date('Y-m-d H:i:s')]);
        if ($ok && $ruleId) {
            $db->prepare('UPDATE meta_rules SET hits = hits + 1, last_hit_at = :at WHERE id = :id')->execute(['at' => date('Y-m-d H:i:s'), 'id' => $ruleId]);
        }
    } catch (Throwable $e) {}
}

/**
 * মেসেঞ্জারে অটো-উত্তর পাঠানো যায় কিনা — চারটা গার্ড:
 * নিয়মটা "অটো" · একই নিয়মে MKB_COOLDOWN_HOURS-এর মধ্যে পাঠানো হয়নি · ঘণ্টায় MKB_MAX_AUTO_PER_HOUR-এর কম ·
 * শেষ MKB_HUMAN_QUIET_MIN মিনিটে কোনো মানুষ উত্তর দেননি।
 */
function mkb_auto_message_allowed(PDO $db, int $contactId, string $psid, int $ruleId): bool
{
    $now = time();
    $st = $db->prepare('SELECT COUNT(*) FROM meta_auto_log WHERE channel = :c AND target = :t AND ok = 1 AND rule_id = :r AND sent_at >= :since');
    $st->execute(['c' => 'message', 't' => $psid, 'r' => $ruleId, 'since' => date('Y-m-d H:i:s', $now - MKB_COOLDOWN_HOURS * 3600)]);
    if ((int) $st->fetchColumn() > 0) { return false; }
    $st = $db->prepare('SELECT COUNT(*) FROM meta_auto_log WHERE channel = :c AND target = :t AND sent_at >= :since');
    $st->execute(['c' => 'message', 't' => $psid, 'since' => date('Y-m-d H:i:s', $now - 3600)]);
    if ((int) $st->fetchColumn() >= MKB_MAX_AUTO_PER_HOUR) { return false; }
    $st = $db->prepare("SELECT COUNT(*) FROM meta_messages WHERE contact_id = :c AND direction = 'out' AND source IN ('admin','page') AND sent_at >= :since");
    $st->execute(['c' => $contactId, 'since' => date('Y-m-d H:i:s', $now - MKB_HUMAN_QUIET_MIN * 60)]);
    return (int) $st->fetchColumn() === 0;
}

/**
 * ওয়েবহুকে এইমাত্র আসা মেসেজ/কমেন্টে অটো-উত্তর। $items = meta_ingest()-এর 'fresh'।
 * ফেরত: পাঠানো উত্তরের সংখ্যা। 🔴 সুইচ বন্ধ বা নিয়ম "অটো" না হলে কিছুই পাঠায় না।
 */
/** নিয়মের সাথে জোড়া ছবি (চালু থাকলে), নাহলে null */
function mkb_rule_image(PDO $db, array $rule): ?array
{
    $id = (int) ($rule['image_id'] ?? 0);
    return $id > 0 && function_exists('mimg_get') ? mimg_get($db, $id) : null;
}

function mkb_auto_respond(PDO $db, array $items): int
{
    if (!$items || !mkb_auto_on() || !meta_configured() || !mkb_ready($db)) { return 0; }
    $rules = mkb_rules($db);
    $sent = 0;
    foreach ($items as $it) {
        if ($sent >= MKB_MAX_AUTO_PER_RUN) { break; }
        $text = trim((string) ($it['text'] ?? ''));
        if ($text === '') { continue; }
        try {
            if (($it['type'] ?? '') === 'message') {
                $rule = mkb_rule_pick($rules, $text, 'message', 'auto');
                if (!$rule) { continue; }
                $st = $db->prepare('SELECT * FROM meta_contacts WHERE psid = :p');
                $st->execute(['p' => (string) $it['psid']]);
                $contact = $st->fetch();
                if (!$contact || !mkb_auto_message_allowed($db, (int) $contact['id'], (string) $contact['psid'], (int) $rule['id'])) { continue; }
                $reply = mkb_fill((string) $rule['reply'], mkb_vars($db, $contact['name'] ?? null));
                $img = mkb_rule_image($db, $rule);
                $r = meta_send_reply($db, $contact, $reply, $img ? mimg_url((string) $img['file_path']) : '', '🤖 অটো', 'auto');
                // লেখা চলে গেলে সফল ধরা হয় (ছবি না গেলেও) — নাহলে একই উত্তর আবার যেত
                mkb_log($db, (int) $rule['id'], 'message', (string) $contact['psid'], $r['ok'] || $r['text_ok'], $r['error']);
                if ($img && $r['ok']) { mimg_used($db, (int) $img['id']); }
                if ($r['ok'] || $r['text_ok']) { $sent++; }
            } elseif (($it['type'] ?? '') === 'comment') {
                $rule = mkb_rule_pick($rules, $text, 'comment', 'auto');
                if (!$rule) { continue; }
                $cid = (string) ($it['comment_id'] ?? '');
                $st = $db->prepare('SELECT COUNT(*) FROM meta_auto_log WHERE channel = :c AND target = :t');
                $st->execute(['c' => 'comment', 't' => $cid]);
                if ($cid === '' || (int) $st->fetchColumn() > 0) { continue; }
                $st = $db->prepare("SELECT * FROM meta_comments WHERE comment_id = :c AND is_page = 0 AND status = 'new'");
                $st->execute(['c' => $cid]);
                $cm = $st->fetch();
                if (!$cm) { continue; }
                $reply = mkb_fill((string) $rule['reply'], mkb_vars($db, $cm['from_name'] ?? null));
                $img = mkb_rule_image($db, $rule);
                $r = meta_reply_comment($db, $cm, $reply, '🤖 অটো', 'auto', $img ? mimg_url((string) $img['file_path']) : '');
                if ($img && $r['ok']) { mimg_used($db, (int) $img['id']); }
                mkb_log($db, (int) $rule['id'], 'comment', $cid, $r['ok'], $r['error']);
                if ($r['ok']) { $sent++; }
            }
        } catch (Throwable $e) {
            error_log('meta auto: ' . $e->getMessage());
        }
    }
    return $sent;
}

/* ───────────────────────── শেখার প্রস্তাব ───────────────────────── */

/** একটা প্রস্তাব বসানো। একই উৎস (source_ref) আগে থাকলে কিছুই হয় না। */
function mkb_propose(PDO $db, string $question, string $answer, string $source, string $ref, ?string $by = null): bool
{
    $question = trim($question); $answer = trim($answer);
    if (mb_strlen($question) < 3 || mb_strlen($answer) < MKB_MIN_ANSWER_CHARS) { return false; }
    if (!preg_match('/[\p{L}]/u', $answer)) { return false; }
    try {
        // হুবহু একই উত্তর আগেই শেখা থাকলে আবার প্রস্তাব নয়
        $st = $db->prepare("SELECT COUNT(*) FROM meta_kb WHERE kind = 'qa' AND status = 'active' AND answer = :a");
        $st->execute(['a' => $answer]);
        if ((int) $st->fetchColumn() > 0) { return false; }
        $db->prepare("INSERT INTO meta_kb (kind, status, question, answer, source, source_ref, created_by) VALUES ('qa', 'proposed', :q, :a, :s, :r, :b)")
           ->execute(['q' => mb_substr($question, 0, 2000), 'a' => mb_substr($answer, 0, 4000), 's' => $source, 'r' => $ref, 'b' => $by]);
        return true;
    } catch (PDOException $e) {
        return false; // ডুপ্লিকেট source_ref
    }
}

/**
 * পুরনো/নতুন হাতের উত্তর খুঁজে প্রস্তাব বানানো (প্রস্তাব-ট্যাব খুললে চলে)।
 * মেসেজ: পেইজের পাঠানো (আপনি/Business Suite/পুরনো আনা) উত্তর ↔ তার ঠিক আগের অভিভাবকের মেসেজ।
 * কমেন্ট: পেইজের উত্তর ↔ মূল কমেন্ট। 🔴 অটো-উত্তর থেকে কখনো নয়।
 */
function mkb_scan_history(PDO $db): int
{
    if (!mkb_ready($db)) { return 0; }
    $new = 0;
    try {
        $outs = $db->query("SELECT m.id, m.mid, m.contact_id, m.text, m.sent_at, m.source, m.sent_by FROM meta_messages m
                            LEFT JOIN meta_kb k ON k.source_ref = CONCAT('msg:', m.mid)
                            WHERE m.direction = 'out' AND m.source IN ('admin','page','import') AND m.mid IS NOT NULL
                              AND m.text IS NOT NULL AND m.text <> '' AND k.id IS NULL
                            ORDER BY m.id DESC LIMIT " . (int) MKB_SCAN_LIMIT)->fetchAll();
        $prev = $db->prepare("SELECT text FROM meta_messages WHERE contact_id = :c AND direction = 'in' AND text IS NOT NULL AND text <> ''
                              AND (sent_at < :at OR (sent_at = :at2 AND id < :id)) ORDER BY sent_at DESC, id DESC LIMIT 1");
        foreach ($outs as $o) {
            $prev->execute(['c' => $o['contact_id'], 'at' => $o['sent_at'], 'at2' => $o['sent_at'], 'id' => $o['id']]);
            $q = (string) ($prev->fetchColumn() ?: '');
            if ($q === '' || strpos($q, '[বোতাম]') === 0) { continue; }
            if (mkb_propose($db, $q, (string) $o['text'], 'message', 'msg:' . $o['mid'], $o['sent_by'])) { $new++; }
        }
        $reps = $db->query("SELECT r.comment_id, r.message, r.sent_by, p.message AS q FROM meta_comments r
                            JOIN meta_comments p ON p.comment_id = r.parent_id AND p.is_page = 0
                            LEFT JOIN meta_kb k ON k.source_ref = CONCAT('cmt:', r.comment_id)
                            WHERE r.is_page = 1 AND r.source NOT IN ('auto','ai') AND r.message IS NOT NULL AND r.message <> '' AND k.id IS NULL
                            ORDER BY r.id DESC LIMIT " . (int) MKB_SCAN_LIMIT)->fetchAll();
        foreach ($reps as $r) {
            if (mkb_propose($db, (string) $r['q'], (string) $r['message'], 'comment', 'cmt:' . $r['comment_id'], $r['sent_by'])) { $new++; }
        }
    } catch (Throwable $e) {
        error_log('mkb scan: ' . $e->getMessage());
    }
    return $new;
}

/** অপেক্ষমাণ প্রস্তাবের সংখ্যা (ড্যাশবোর্ড/ট্যাবের ব্যাজ) */
function mkb_proposed_count(?PDO $db = null): int
{
    $db = $db ?: get_db();
    if (!mkb_ready($db)) { return 0; }
    try {
        return (int) $db->query("SELECT COUNT(*) FROM meta_kb WHERE status = 'proposed'")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}
