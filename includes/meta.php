<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 includes/meta.php — ফেসবুক পেইজের মেসেজ ও কমেন্ট (ধাপ ১: সংযোগ ও ইনবক্স, ২০২৬-১১-১৭)
 *
 * পাবলিক `meta-webhook.php` ও অ্যাডমিন `admin/meta-inbox.php` দুটোই এই ফাইল require করে।
 *
 * 🔴 এই ধাপে কোনো অটো-উত্তর নেই — মেসেজ/কমেন্ট শুধু জমা হয়, অ্যাডমিন হাতে উত্তর দেন।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।
 * 🔴 গোপন চাবি (App Secret, Page Token) `settings`-এ থাকে — কখনো repo/লগ/স্ক্রিনে নয়।
 * 🔴 Graph API-র ঠিকানা `META_GRAPH_BASE` ধ্রুবক দিয়ে বদলানো যায় (শুধু টেস্টের জন্য,
 *    config.php-তে) — লাইভে সেট করার দরকার নেই।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/meta-media.php';

const META_DEFAULT_VERSION = 'v24.0';
// মেটার নিয়ম: অভিভাবকের শেষ মেসেজের পর ২৪ ঘণ্টার মধ্যেই সাধারণ উত্তর পাঠানো যায়
const META_REPLY_WINDOW_HOURS = 24;
/** কমেন্টকারীকে ইনবক্সে মেসেজ (Private Reply) — ফেসবুকের নিয়মে কমেন্টের ৭ দিনের মধ্যে, একবারই */
const META_PRIVATE_REPLY_DAYS = 7;
// পুরনো তথ্য একবারে কতটা টানা হবে (শেয়ার্ড হোস্টে সময়সীমা পেরোনো ঠেকাতে)
const META_IMPORT_CONVERSATIONS = 25;
const META_IMPORT_POSTS = 15;

/* ───────────────────────── বিশুদ্ধ ফাংশন ───────────────────────── */

/** ওয়েবহুকের সই সঠিক কিনা — `X-Hub-Signature-256: sha256=<hex>` */
function meta_signature_valid(string $raw, string $header, string $secret): bool
{
    // 🔴 খালি ↔ খালি কখনো মিলবে না (hash_equals('', '') ফাঁদ)
    if ($secret === '' || $header === '' || !str_starts_with($header, 'sha256=')) {
        return false;
    }
    $given = substr($header, 7);
    if ($given === '' || !ctype_xdigit($given)) {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $raw, $secret), strtolower($given));
}

/** সেকেন্ড / মিলিসেকেন্ড / ISO লেখা → 'Y-m-d H:i:s' (সার্ভারের সময়ে) */
function meta_ts($v): string
{
    if (is_numeric($v)) {
        $n = (int) $v;
        if ($n > 20000000000) { $n = intdiv($n, 1000); } // মিলিসেকেন্ড
        return $n > 0 ? date('Y-m-d H:i:s', $n) : date('Y-m-d H:i:s');
    }
    $t = is_string($v) ? strtotime($v) : false;
    return $t ? date('Y-m-d H:i:s', $t) : date('Y-m-d H:i:s');
}

/** ছোট ঝলক (তালিকায় দেখানোর জন্য) — মাল্টিবাইট-নিরাপদ */
function meta_snippet(?string $text, int $max = 120): string
{
    $t = trim(preg_replace('/\s+/u', ' ', (string) $text) ?? '');
    return mb_strlen($t) > $max ? mb_substr($t, 0, $max - 1) . '…' : $t;
}

/**
 * লাইন-ভাঙা অক্ষত রেখে ছোট ঝলক (তালিকায় দেখানোর জন্য) — meta_snippet() সব লাইন এক করে ফেলে।
 * ফেরত: ['text' => ঝলক, 'cut' => কাটা পড়েছে কিনা]। পাঠানো লেখা এতে কখনো বদলায় না।
 */
function meta_snippet_lines(?string $text, int $maxChars = 400, int $maxLines = 8): array
{
    $t = str_replace(["\r\n", "\r"], "\n", (string) $text);
    $t = preg_replace("/[ \t]+\n/u", "\n", $t) ?? $t;
    $t = trim(preg_replace("/\n{3,}/u", "\n\n", $t) ?? $t);
    $cut = false;
    $lines = explode("\n", $t);
    $keep = []; $filled = 0;
    foreach ($lines as $ln) {   // ফাঁকা লাইন গোনা হয় না — শুধু লেখা-ওয়ালা লাইন
        if (trim($ln) !== '' && ++$filled > $maxLines) { $cut = true; break; }
        $keep[] = $ln;
    }
    if ($cut) { $t = rtrim(implode("\n", $keep)); }
    if (mb_strlen($t) > $maxChars) { $t = mb_substr($t, 0, $maxChars); $cut = true; }
    return ['text' => rtrim($t) . ($cut ? '…' : ''), 'cut' => $cut];
}

/** সংযুক্তির তালিকা থেকে লেখার রূপ — ছবি/ভিডিও ইত্যাদি */
function meta_attach_label(array $attachments): string
{
    $names = ['image' => '🖼️ ছবি', 'video' => '🎬 ভিডিও', 'audio' => '🎤 ভয়েস', 'file' => '📎 ফাইল', 'sticker' => '👍 স্টিকার'];
    $out = [];
    foreach ($attachments as $a) {
        $type = is_array($a) ? (string) ($a['type'] ?? 'file') : 'file';
        $out[] = $names[$type] ?? '📎 সংযুক্তি';
    }
    return implode(' ', $out);
}

/** ২৪ ঘণ্টার জানালা খোলা আছে কিনা */
function meta_window_open(?string $lastInAt, ?int $now = null): bool
{
    if (!$lastInAt) { return false; }
    $t = strtotime($lastInAt);
    return $t !== false && (($now ?? time()) - $t) < META_REPLY_WINDOW_HOURS * 3600;
}

/* ───────────────────────── সেটিংস ───────────────────────── */

function meta_cfg(): array
{
    $v = trim(get_setting('meta_graph_version'));
    return [
        'app_secret'   => trim(get_setting('meta_app_secret')),
        'page_id'      => trim(get_setting('meta_page_id')),
        'page_token'   => trim(get_setting('meta_page_token')),
        'verify_token' => trim(get_setting('meta_verify_token')),
        'version'      => preg_match('/^v\d{1,3}\.\d$/', $v) ? $v : META_DEFAULT_VERSION,
    ];
}

/** নরম সুইচ — শুধু '0' = বন্ধ (খালি/অন্য কিছু = চালু) */
function meta_feature_on(): bool
{
    return get_setting('meta_feature_on') !== '0';
}

function meta_ready(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $db = $db ?: get_db();
    return $ok = db_has_table($db, 'meta_contacts') && db_has_table($db, 'meta_messages')
        && db_has_table($db, 'meta_comments') && db_has_table($db, 'meta_posts');
}

/** সব চাবি বসানো আছে কিনা (Graph API ডাকা যাবে কিনা) */
function meta_configured(): bool
{
    $c = meta_cfg();
    return $c['page_id'] !== '' && $c['page_token'] !== '';
}

/** পাতা খোলার সময় নাম/পোস্ট আনতে মোট সর্বোচ্চ কত সেকেন্ড (বাকিটা পরের বার) */
const META_FILL_BUDGET = 3.0;

function meta_graph_base(): string
{
    return defined('META_GRAPH_BASE') ? rtrim((string) META_GRAPH_BASE, '/') : 'https://graph.facebook.com';
}

/**
 * Graph API ডাকা। ফেরত: ['ok', 'status', 'data', 'error']
 * 🔴 টোকেন কখনো এরর-বার্তা বা লগে যায় না।
 */
function meta_graph(string $method, string $path, array $params = [], ?array $body = null, int $timeout = 20): array
{
    $c = meta_cfg();
    if ($c['page_token'] === '') {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'Page Access Token বসানো হয়নি'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'সার্ভারে curl নেই'];
    }
    // কলার চাইলে অন্য token দিতে পারে (যেমন User token থেকে পেইজের token বের করা, বা app token)
    $tok = (string) ($params['access_token'] ?? $c['page_token']);
    $params['access_token'] = $tok;
    $url = meta_graph_base() . '/' . $c['version'] . '/' . ltrim($path, '/') . '?' . http_build_query($params);

    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => max(2, $timeout),
        CURLOPT_CONNECTTIMEOUT => min(8, max(2, $timeout)),
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
    }
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['ok' => false, 'status' => 0, 'data' => null, 'error' => 'ফেসবুকের সাথে সংযোগ হয়নি (' . $cerr . ')'];
    }
    $data = json_decode((string) $resp, true);
    if ($status >= 200 && $status < 300 && is_array($data) && !isset($data['error'])) {
        return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => ''];
    }
    $msg = is_array($data) && isset($data['error']['message']) ? (string) $data['error']['message'] : ('HTTP ' . $status);
    foreach (array_filter([$c['page_token'], $tok, $c['app_secret'], (string) ($params['input_token'] ?? '')]) as $secret) { $msg = str_replace($secret, '***', $msg); }
    return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => meta_snippet($msg, 300)];
}

/* ───────────────────────── জমা রাখা ───────────────────────── */

/** কন্টাক্ট খুঁজে বা বানিয়ে id ফেরত */
function meta_contact_id(PDO $db, string $psid, ?string $name = null): int
{
    $st = $db->prepare('SELECT id, name FROM meta_contacts WHERE psid = :p');
    $st->execute(['p' => $psid]);
    $row = $st->fetch();
    if ($row) {
        if ($name && !$row['name']) {
            $db->prepare('UPDATE meta_contacts SET name = :n WHERE id = :id')->execute(['n' => $name, 'id' => $row['id']]);
        }
        return (int) $row['id'];
    }
    try {
        $db->prepare('INSERT INTO meta_contacts (psid, name) VALUES (:p, :n)')->execute(['p' => $psid, 'n' => $name]);
        return (int) $db->lastInsertId();
    } catch (PDOException $e) {
        // একই সময়ে দুই ওয়েবহুক — অন্যটা আগে বসিয়েছে
        $st->execute(['p' => $psid]);
        return (int) ($st->fetch()['id'] ?? 0);
    }
}

/**
 * একটা মেসেজ জমা। একই mid আগে থাকলে কিছুই হয় না (false ফেরে)।
 * $direction: 'in' (অভিভাবক → পেইজ) / 'out' (পেইজ → অভিভাবক)
 */
function meta_store_message(PDO $db, string $psid, string $direction, ?string $mid, string $text, array $attachments,
                            string $source, string $sentAt, ?string $by = null, ?string $name = null): bool
{
    if ($psid === '') { return false; }
    $cid = meta_contact_id($db, $psid, $name);
    if (!$cid) { return false; }
    if ($mid !== null && $mid !== '') {
        $chk = $db->prepare('SELECT id FROM meta_messages WHERE mid = :m');
        $chk->execute(['m' => $mid]);
        if ($chk->fetch()) { return false; }
    }
    $label = trim($text) !== '' ? $text : meta_attach_label($attachments);
    try {
        $db->prepare('INSERT INTO meta_messages (contact_id, mid, direction, source, text, attach_json, sent_by, sent_at)
                      VALUES (:c, :m, :d, :s, :t, :a, :b, :at)')
           ->execute([
               'c' => $cid, 'm' => ($mid !== '' ? $mid : null), 'd' => $direction === 'out' ? 'out' : 'in',
               's' => $source, 't' => $text, 'a' => $attachments ? json_encode($attachments, JSON_UNESCAPED_UNICODE) : null,
               'b' => $by, 'at' => $sentAt,
           ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '23000') { return false; } // ডুপ্লিকেট mid
        throw $e;
    }

    // কন্টাক্টের সারাংশ — শুধু নতুন হলে সামনে আনা হয় (পুরনো ইমপোর্ট নতুন বার্তাকে ঢাকবে না)
    $snip = meta_snippet($label, 250);
    $db->prepare('UPDATE meta_contacts SET last_text = :t, last_at = :at WHERE id = :id AND (last_at IS NULL OR last_at <= :at2)')
       ->execute(['t' => $snip, 'at' => $sentAt, 'at2' => $sentAt, 'id' => $cid]);
    if ($direction === 'in') {
        $db->prepare('UPDATE meta_contacts SET last_in_at = :at WHERE id = :id AND (last_in_at IS NULL OR last_in_at < :at2)')
           ->execute(['at' => $sentAt, 'at2' => $sentAt, 'id' => $cid]);
        if ($source === 'webhook') {
            $db->prepare("UPDATE meta_contacts SET unread = unread + 1, status = 'open' WHERE id = :id")->execute(['id' => $cid]);
        }
    }
    return true;
}

/** কমেন্ট জমা/হালনাগাদ। পেইজের নিজের উত্তর হলে মূল কমেন্টটা "উত্তর দেওয়া হয়েছে" হয়ে যায়। */
function meta_store_comment(PDO $db, array $c, string $source, string $pageId, ?string $by = null): bool
{
    $cid = (string) ($c['comment_id'] ?? '');
    if ($cid === '') { return false; }
    $fromId = (string) ($c['from_id'] ?? '');
    $isPage = $pageId !== '' && $fromId === $pageId ? 1 : 0;
    $parent = (string) ($c['parent_id'] ?? '');
    $at = (string) ($c['at'] ?? date('Y-m-d H:i:s'));

    $st = $db->prepare('SELECT id FROM meta_comments WHERE comment_id = :c');
    $st->execute(['c' => $cid]);
    if ($st->fetch()) {
        $db->prepare('UPDATE meta_comments SET message = :m WHERE comment_id = :c')
           ->execute(['m' => (string) ($c['message'] ?? ''), 'c' => $cid]);
        return false;
    }
    try {
        $db->prepare('INSERT INTO meta_comments (comment_id, post_id, parent_id, from_id, from_name, is_page, message, status, source, sent_by, commented_at)
                      VALUES (:c, :p, :pa, :f, :fn, :ip, :m, :st, :src, :by, :at)')
           ->execute([
               'c' => $cid, 'p' => ($c['post_id'] ?? null) ?: null, 'pa' => $parent !== '' ? $parent : null,
               'f' => $fromId !== '' ? $fromId : null, 'fn' => ($c['from_name'] ?? null) ?: null, 'ip' => $isPage,
               'm' => (string) ($c['message'] ?? ''),
               // পেইজের নিজের কমেন্ট "নতুন" নয়; ইমপোর্ট করা পুরনো কমেন্টও কাজের তালিকা ভরাবে না
               'st' => ($isPage || $source === 'import') ? 'done' : 'new',
               'src' => $source, 'by' => $by, 'at' => $at,
           ]);
    } catch (PDOException $e) {
        if (($e->errorInfo[0] ?? '') === '23000') { return false; }
        throw $e;
    }
    if ($isPage && $parent !== '') {
        $db->prepare("UPDATE meta_comments SET status = 'done', replied_at = COALESCE(replied_at, :at) WHERE comment_id = :p")
           ->execute(['at' => $at, 'p' => $parent]);
    }
    return true;
}

/**
 * ওয়েবহুকের পুরো বার্তা (payload) জমা। ফেরত: ['messages' => n, 'comments' => n, 'ignored' => n]
 */
function meta_ingest(PDO $db, array $payload, string $pageId): array
{
    // 'fresh' = এইমাত্র নতুন জমা হওয়া অভিভাবকের মেসেজ/কমেন্ট (অটো-উত্তরের জন্য, ধাপ ২)
    $n = ['messages' => 0, 'comments' => 0, 'ignored' => 0, 'fresh' => []];
    if (($payload['object'] ?? '') !== 'page') { $n['ignored']++; return $n; }

    foreach ((array) ($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) { continue; }
        $entryPage = (string) ($entry['id'] ?? '');
        $page = $pageId !== '' ? $pageId : $entryPage;

        foreach ((array) ($entry['messaging'] ?? []) as $ev) {
            if (!is_array($ev)) { continue; }
            $sender = (string) ($ev['sender']['id'] ?? '');
            $recip  = (string) ($ev['recipient']['id'] ?? '');
            $at     = meta_ts($ev['timestamp'] ?? time());
            if (isset($ev['message']) && is_array($ev['message'])) {
                $m = $ev['message'];
                $echo = !empty($m['is_echo']) || ($page !== '' && $sender === $page);
                $psid = $echo ? $recip : $sender;
                $ok = meta_store_message($db, $psid, $echo ? 'out' : 'in', (string) ($m['mid'] ?? ''),
                    (string) ($m['text'] ?? ''), (array) ($m['attachments'] ?? []), $echo ? 'page' : 'webhook', $at);
                $ok ? $n['messages']++ : $n['ignored']++;
                if ($ok && !$echo && trim((string) ($m['text'] ?? '')) !== '') {
                    $n['fresh'][] = ['type' => 'message', 'psid' => $psid, 'text' => (string) $m['text']];
                }
            } elseif (isset($ev['postback']) && is_array($ev['postback'])) {
                $title = (string) ($ev['postback']['title'] ?? $ev['postback']['payload'] ?? '');
                $ok = meta_store_message($db, $sender, 'in', (string) ($ev['postback']['mid'] ?? ''),
                    '[বোতাম] ' . $title, [], 'webhook', $at);
                $ok ? $n['messages']++ : $n['ignored']++;
            } else {
                $n['ignored']++; // delivery / read জাতীয় খবর
            }
        }

        foreach ((array) ($entry['changes'] ?? []) as $ch) {
            $v = is_array($ch) ? (array) ($ch['value'] ?? []) : [];
            if (($ch['field'] ?? '') !== 'feed' || ($v['item'] ?? '') !== 'comment') { $n['ignored']++; continue; }
            $verb = (string) ($v['verb'] ?? 'add');
            $cid = (string) ($v['comment_id'] ?? '');
            if ($verb === 'remove') {
                if ($cid !== '') {
                    $db->prepare("UPDATE meta_comments SET status = 'removed' WHERE comment_id = :c")->execute(['c' => $cid]);
                }
                $n['ignored']++;
                continue;
            }
            $ok = meta_store_comment($db, [
                'comment_id' => $cid,
                'post_id'    => (string) ($v['post_id'] ?? ''),
                'parent_id'  => (string) ($v['parent_id'] ?? ''),
                'from_id'    => (string) ($v['from']['id'] ?? ''),
                'from_name'  => (string) ($v['from']['name'] ?? ''),
                'message'    => (string) ($v['message'] ?? ''),
                'at'         => meta_ts($v['created_time'] ?? time()),
            ], 'webhook', $page);
            $ok ? $n['comments']++ : $n['ignored']++;
            if ($ok && $verb === 'add' && (string) ($v['from']['id'] ?? '') !== $page) {
                $n['fresh'][] = ['type' => 'comment', 'comment_id' => $cid, 'text' => (string) ($v['message'] ?? '')];
            }
        }
    }
    return $n;
}

/* ───────────────────────── পাঠানো ───────────────────────── */

/** মেসেঞ্জারে উত্তর পাঠানো। ফেরত: ['ok', 'error'] */
function meta_send_message(PDO $db, array $contact, string $text, string $by, string $source = 'admin'): array
{
    $text = trim($text);
    if ($text === '') { return ['ok' => false, 'error' => 'উত্তর লেখা খালি।']; }
    if (mb_strlen($text) > 2000) { return ['ok' => false, 'error' => 'উত্তর ২০০০ অক্ষরের বেশি হতে পারবে না।']; }
    if (!meta_window_open($contact['last_in_at'] ?? null)) {
        return ['ok' => false, 'error' => 'অভিভাবকের শেষ মেসেজের পর ২৪ ঘণ্টা পেরিয়ে গেছে — মেটার নিয়মে এখন আর উত্তর পাঠানো যায় না। তিনি আবার মেসেজ দিলে উত্তর দিতে পারবেন।'];
    }
    $c = meta_cfg();
    $r = meta_graph('POST', $c['page_id'] . '/messages', [], [
        'recipient'      => ['id' => (string) $contact['psid']],
        'messaging_type' => 'RESPONSE',
        'message'        => ['text' => $text],
    ]);
    if (!$r['ok']) { return ['ok' => false, 'error' => 'ফেসবুক পাঠাতে দেয়নি: ' . $r['error']]; }
    $mid = (string) ($r['data']['message_id'] ?? '');
    if (!meta_store_message($db, (string) $contact['psid'], 'out', $mid !== '' ? $mid : null, $text, [], $source, date('Y-m-d H:i:s'), $by)
        && $mid !== '') {
        // ফেসবুকের "echo" আমাদের আগে পৌঁছে 'page' হিসেবে বসে গেছে — কে পাঠাল সেটা ঠিক করে দেওয়া
        $db->prepare('UPDATE meta_messages SET source = :s, sent_by = :b WHERE mid = :m')->execute(['s' => $source, 'b' => $by, 'm' => $mid]);
    }
    return ['ok' => true, 'error' => ''];
}

/**
 * মেসেঞ্জারে একটা ছবি (লেখা থেকে আলাদা মেসেজ — মেসেঞ্জারে লেখা আর ছবি একসাথে যায় না)।
 * $url = ফেসবুক যেখান থেকে ছবিটা তুলবে (সাইটের পাবলিক https ঠিকানা)। ফেরত: ['ok', 'error']
 */
function meta_send_image(PDO $db, array $contact, string $url, string $by, string $source = 'admin'): array
{
    if ($url === '') { return ['ok' => false, 'error' => 'ছবির ঠিকানা খালি।']; }
    if (!meta_window_open($contact['last_in_at'] ?? null)) {
        return ['ok' => false, 'error' => 'অভিভাবকের শেষ মেসেজের পর ২৪ ঘণ্টা পেরিয়ে গেছে — মেটার নিয়মে এখন আর ছবি পাঠানো যায় না।'];
    }
    $c = meta_cfg();
    $att = ['type' => 'image', 'payload' => ['url' => $url, 'is_reusable' => true]];
    $r = meta_graph('POST', $c['page_id'] . '/messages', [], [
        'recipient'      => ['id' => (string) $contact['psid']],
        'messaging_type' => 'RESPONSE',
        'message'        => ['attachment' => $att],
    ]);
    if (!$r['ok']) { return ['ok' => false, 'error' => 'ফেসবুক ছবিটা পাঠাতে দেয়নি: ' . $r['error']]; }
    $mid = (string) ($r['data']['message_id'] ?? '');
    if (!meta_store_message($db, (string) $contact['psid'], 'out', $mid !== '' ? $mid : null, '', [['type' => 'image', 'payload' => ['url' => $url]]], $source, date('Y-m-d H:i:s'), $by)
        && $mid !== '') {
        $db->prepare('UPDATE meta_messages SET source = :s, sent_by = :b WHERE mid = :m')->execute(['s' => $source, 'b' => $by, 'm' => $mid]);
    }
    return ['ok' => true, 'error' => ''];
}

/** লেখা (থাকলে) তারপর ছবি (থাকলে) — তিন পথ (ইনবক্স/নিয়ম/AI) এই একটাই ফাংশন ডাকে। ফেরত: ['ok', 'error', 'text_ok'] */
function meta_send_reply(PDO $db, array $contact, string $text, string $imageUrl, string $by, string $source = 'admin'): array
{
    $text = trim($text);
    if ($text === '' && $imageUrl === '') { return ['ok' => false, 'error' => 'লেখা বা ছবি — অন্তত একটা দিন।', 'text_ok' => false]; }
    if ($text !== '') {
        $r = meta_send_message($db, $contact, $text, $by, $source);
        if (!$r['ok']) { return $r + ['text_ok' => false]; }
    }
    if ($imageUrl !== '') {
        $r = meta_send_image($db, $contact, $imageUrl, $by, $source);
        if (!$r['ok']) {
            return ['ok' => false, 'text_ok' => $text !== '', 'error' => ($text !== '' ? 'লেখা চলে গেছে, কিন্তু ছবি যায়নি — ' : '') . $r['error']];
        }
    }
    return ['ok' => true, 'error' => '', 'text_ok' => $text !== ''];
}

/** কমেন্টের নিচে পাবলিক উত্তর (চাইলে একটা ছবিসহ)। ফেরত: ['ok', 'error'] */
function meta_reply_comment(PDO $db, array $comment, string $text, string $by, string $source = 'admin', string $imageUrl = ''): array
{
    $text = trim($text);
    if ($text === '' && $imageUrl === '') { return ['ok' => false, 'error' => 'উত্তর লেখা খালি।']; }
    if (mb_strlen($text) > 2000) { return ['ok' => false, 'error' => 'উত্তর ২০০০ অক্ষরের বেশি হতে পারবে না।']; }
    $body = $text !== '' ? ['message' => $text] : [];
    if ($imageUrl !== '') { $body['attachment_url'] = $imageUrl; }
    $r = meta_graph('POST', (string) $comment['comment_id'] . '/comments', [], $body);
    if (!$r['ok']) { return ['ok' => false, 'error' => 'ফেসবুক উত্তর বসাতে দেয়নি: ' . $r['error']]; }
    $c = meta_cfg();
    $newId = (string) ($r['data']['id'] ?? '');
    $now = date('Y-m-d H:i:s');
    if ($newId !== '') {
        if (!meta_store_comment($db, [
            'comment_id' => $newId, 'post_id' => (string) ($comment['post_id'] ?? ''),
            'parent_id' => (string) $comment['comment_id'], 'from_id' => $c['page_id'], 'from_name' => '',
            'message' => $text !== '' ? $text : '🖼️ ছবি', 'at' => $now,
        ], $source, $c['page_id'], $by)) {
            // ওয়েবহুক আগে পৌঁছে গেছে — কে পাঠাল সেটা ঠিক করে দেওয়া
            $db->prepare('UPDATE meta_comments SET source = :s, sent_by = :b WHERE comment_id = :c')->execute(['s' => $source, 'b' => $by, 'c' => $newId]);
        }
    }
    $db->prepare("UPDATE meta_comments SET status = 'done', replied_at = COALESCE(replied_at, :at) WHERE id = :id")
       ->execute(['at' => $now, 'id' => (int) $comment['id']]);
    return ['ok' => true, 'error' => ''];
}

/** "আগেই ইনবক্সে পাঠানো হয়েছে" মনে রাখার ঘর আছে? (migrate-meta-private-reply.sql) */
function meta_private_ready(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok === null) { $ok = db_has_column($db ?: get_db(), 'meta_comments', 'private_reply_at'); }
    return $ok;
}

/**
 * কমেন্টকারীকে ইনবক্সে পাঠানো যাবে কিনা (বিশুদ্ধ)। ফেরত: '' = যাবে, নাহলে কারণ।
 * 🔴 ফেসবুকের নিয়ম: কমেন্টের ৭ দিনের মধ্যে, প্রতি কমেন্টে একবারই, পেইজের নিজের কমেন্টে নয়।
 */
function meta_private_reply_block(array $comment, ?int $now = null): string
{
    if (!empty($comment['is_page'])) { return 'পেইজের নিজের কমেন্ট'; }
    if (($comment['status'] ?? '') === 'removed') { return 'কমেন্টটা মুছে ফেলা হয়েছে'; }
    if (!empty($comment['private_reply_at'])) { return 'আগেই ইনবক্সে পাঠানো হয়েছে'; }
    $t = strtotime((string) ($comment['commented_at'] ?? ''));
    if ($t === false || (($now ?? time()) - $t) >= META_PRIVATE_REPLY_DAYS * 86400) {
        return 'কমেন্টটা ' . META_PRIVATE_REPLY_DAYS . ' দিনের বেশি পুরনো — ফেসবুকের নিয়মে আর ইনবক্সে পাঠানো যায় না';
    }
    return '';
}

/**
 * কমেন্টকারীর মেসেঞ্জার-ইনবক্সে একটা মেসেজ (Private Reply)। শুধু লেখা।
 * পাঠানো মেসেজটা ঐ মানুষের কথোপকথনে জমা হয় — তিনি উত্তর দিলে "মেসেজ" ট্যাবে সেখানেই আসবে।
 * ফেরত: ['ok', 'error']
 */
function meta_private_reply(PDO $db, array $comment, string $text, string $by, string $source = 'admin'): array
{
    $text = trim($text);
    if ($text === '') { return ['ok' => false, 'error' => 'ইনবক্সের মেসেজ খালি।']; }
    if (mb_strlen($text) > 2000) { return ['ok' => false, 'error' => 'ইনবক্সের মেসেজ ২০০০ অক্ষরের বেশি হতে পারবে না।']; }
    $block = meta_private_reply_block($comment);
    if ($block !== '') { return ['ok' => false, 'error' => 'ইনবক্সে পাঠানো যায়নি — ' . $block . '।']; }
    $c = meta_cfg();
    $r = meta_graph('POST', $c['page_id'] . '/messages', [], [
        'recipient' => ['comment_id' => (string) $comment['comment_id']],
        'message'   => ['text' => $text],
    ]);
    if (!$r['ok']) { return ['ok' => false, 'error' => 'ফেসবুক ইনবক্সে পাঠাতে দেয়নি: ' . $r['error']]; }
    $now = date('Y-m-d H:i:s');
    $psid = (string) ($r['data']['recipient_id'] ?? '');
    $mid = (string) ($r['data']['message_id'] ?? '');
    if ($psid !== '') {
        $name = trim((string) ($comment['from_name'] ?? ''));
        if (!meta_store_message($db, $psid, 'out', $mid !== '' ? $mid : null, $text, [], $source, $now, $by, $name !== '' ? $name : null)
            && $mid !== '') {
            $db->prepare('UPDATE meta_messages SET source = :s, sent_by = :b WHERE mid = :m')->execute(['s' => $source, 'b' => $by, 'm' => $mid]);
        }
    }
    if (meta_private_ready($db)) {
        $db->prepare('UPDATE meta_comments SET private_reply_at = :at, private_reply_by = :b WHERE id = :id')
           ->execute(['at' => $now, 'b' => $by, 'id' => (int) $comment['id']]);
    }
    $db->prepare("UPDATE meta_comments SET status = 'done', replied_at = COALESCE(replied_at, :at) WHERE id = :id")
       ->execute(['at' => $now, 'id' => (int) $comment['id']]);
    return ['ok' => true, 'error' => '', 'psid' => $psid];
}

/* ───────────────────────── পুরনো তথ্য আনা ───────────────────────── */

/** পুরনো কথোপকথন — সর্বশেষ META_IMPORT_CONVERSATIONS টি। ফেরত: ['ok', 'error', 'new' => n] */
function meta_import_conversations(PDO $db): array
{
    $c = meta_cfg();
    $r = meta_graph('GET', $c['page_id'] . '/conversations', [
        'platform' => 'messenger',
        'limit'    => META_IMPORT_CONVERSATIONS,
        'fields'   => 'participants,updated_time,messages.limit(25){id,message,from,created_time,attachments}',
    ]);
    if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'new' => 0]; }
    $new = 0;
    foreach ((array) ($r['data']['data'] ?? []) as $conv) {
        $psid = ''; $name = null;
        foreach ((array) ($conv['participants']['data'] ?? []) as $p) {
            if ((string) ($p['id'] ?? '') !== $c['page_id']) { $psid = (string) ($p['id'] ?? ''); $name = $p['name'] ?? null; }
        }
        if ($psid === '') { continue; }
        foreach ((array) ($conv['messages']['data'] ?? []) as $m) {
            $fromPage = (string) ($m['from']['id'] ?? '') === $c['page_id'];
            if (meta_store_message($db, $psid, $fromPage ? 'out' : 'in', (string) ($m['id'] ?? ''), (string) ($m['message'] ?? ''),
                (array) ($m['attachments']['data'] ?? []), 'import', meta_ts($m['created_time'] ?? ''), null, $name)) {
                $new++;
            }
        }
    }
    return ['ok' => true, 'error' => '', 'new' => $new];
}

/** পুরনো পোস্ট ও তার কমেন্ট — সর্বশেষ META_IMPORT_POSTS টি পোস্ট। ফেরত: ['ok', 'error', 'posts', 'new'] */
function meta_import_posts(PDO $db): array
{
    $c = meta_cfg();
    $r = meta_graph('GET', $c['page_id'] . '/posts', [
        'limit'  => META_IMPORT_POSTS,
        'fields' => 'id,message,created_time,permalink_url,comments.limit(50){id,message,from,created_time,parent{id},comments.limit(20){id,message,from,created_time}}',
    ]);
    if (!$r['ok']) { return ['ok' => false, 'error' => $r['error'], 'posts' => 0, 'new' => 0]; }
    $posts = 0; $new = 0;
    foreach ((array) ($r['data']['data'] ?? []) as $p) {
        $pid = (string) ($p['id'] ?? '');
        if ($pid === '') { continue; }
        meta_store_post($db, $pid, (string) ($p['message'] ?? ''), (string) ($p['permalink_url'] ?? ''), meta_ts($p['created_time'] ?? ''));
        $posts++;
        foreach ((array) ($p['comments']['data'] ?? []) as $cm) {
            $cmId = (string) ($cm['id'] ?? '');
            if (meta_store_comment($db, [
                'comment_id' => $cmId, 'post_id' => $pid,
                'parent_id'  => (string) ($cm['parent']['id'] ?? $pid),
                'from_id'    => (string) ($cm['from']['id'] ?? ''), 'from_name' => (string) ($cm['from']['name'] ?? ''),
                'message'    => (string) ($cm['message'] ?? ''), 'at' => meta_ts($cm['created_time'] ?? ''),
            ], 'import', $c['page_id'])) { $new++; }
            foreach ((array) ($cm['comments']['data'] ?? []) as $rep) {
                if (meta_store_comment($db, [
                    'comment_id' => (string) ($rep['id'] ?? ''), 'post_id' => $pid, 'parent_id' => $cmId,
                    'from_id'    => (string) ($rep['from']['id'] ?? ''), 'from_name' => (string) ($rep['from']['name'] ?? ''),
                    'message'    => (string) ($rep['message'] ?? ''), 'at' => meta_ts($rep['created_time'] ?? ''),
                ], 'import', $c['page_id'])) { $new++; }
            }
        }
    }
    return ['ok' => true, 'error' => '', 'posts' => $posts, 'new' => $new];
}

function meta_store_post(PDO $db, string $postId, string $message, string $permalink, ?string $at): void
{
    $st = $db->prepare('SELECT post_id FROM meta_posts WHERE post_id = :p');
    $st->execute(['p' => $postId]);
    if ($st->fetch()) {
        $db->prepare('UPDATE meta_posts SET message = :m, permalink = :l, fetched_at = NOW() WHERE post_id = :p')
           ->execute(['m' => $message, 'l' => $permalink ?: null, 'p' => $postId]);
    } else {
        $db->prepare('INSERT INTO meta_posts (post_id, message, permalink, posted_at) VALUES (:p, :m, :l, :at)')
           ->execute(['p' => $postId, 'm' => $message, 'l' => $permalink ?: null, 'at' => $at]);
    }
}

/** ওয়েবহুকে শুধু post_id আসে — লেখাটা না থাকলে অল্প কয়েকটা পোস্ট আনা হয় (ইনবক্স খুললে) */
function meta_fill_posts(PDO $db, int $max = 5): void
{
    if (!meta_configured()) { return; }
    try {
        $ids = $db->query("SELECT DISTINCT c.post_id FROM meta_comments c LEFT JOIN meta_posts p ON p.post_id = c.post_id
                           WHERE c.post_id IS NOT NULL AND p.post_id IS NULL LIMIT " . (int) $max)->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { return; }
    $t0 = microtime(true);
    foreach ($ids as $pid) {
        // পাতা খোলার সময় চলে — মোট META_FILL_BUDGET সেকেন্ডের বেশি অপেক্ষা নয়, বাকিটা পরের বার
        if (microtime(true) - $t0 > META_FILL_BUDGET) { break; }
        $r = meta_graph('GET', (string) $pid, ['fields' => 'id,message,created_time,permalink_url'], null, 5);
        // না পেলেও একটা খালি সারি বসে — নাহলে প্রতিবার আবার চেষ্টা করত
        meta_store_post($db, (string) $pid, $r['ok'] ? (string) ($r['data']['message'] ?? '') : '',
            $r['ok'] ? (string) ($r['data']['permalink_url'] ?? '') : '', $r['ok'] ? meta_ts($r['data']['created_time'] ?? '') : null);
    }
}

/** নাম জানা নেই এমন কয়েকজনের নাম আনা (ইনবক্স খুললে, ওয়েবহুকে নয় — দ্রুত উত্তর দিতে) */
function meta_fill_names(PDO $db, int $max = 8): void
{
    if (!meta_configured()) { return; }
    // ফেসবুক "অনুমতি নেই" বলে থাকলে (App Review-এর আগে) পাতা খোলার সময় আর চেষ্টা নয় —
    // প্রতিবার দুটো ব্যর্থ Graph ডাকেই পাতা ধীর হচ্ছিল। "↻ নাম আনুন" হাতে চাপলে তবু চেষ্টা হয়।
    $blocked = (int) strtotime((string) get_setting('meta_names_blocked_until'));
    if ($blocked > time()) { return; }
    try {
        $rows = $db->query('SELECT id, psid FROM meta_contacts WHERE name IS NULL ORDER BY last_at DESC LIMIT ' . (int) $max)->fetchAll();
        // আগে ব্যর্থ হওয়া (খালি) নাম — ৬ ঘণ্টায় একবার, অল্প কয়েকটা করে আবার চেষ্টা
        $last = (int) strtotime((string) get_setting('meta_names_retry_at'));
        if ($last < time() - 6 * 3600) {
            update_setting('meta_names_retry_at', date('Y-m-d H:i:s'));
            $rows = array_merge($rows, $db->query("SELECT id, psid FROM meta_contacts WHERE name = '' ORDER BY last_at DESC LIMIT 5")->fetchAll());
        }
    } catch (Throwable $e) { return; }
    $t0 = microtime(true);
    foreach ($rows as $row) {
        if (microtime(true) - $t0 > META_FILL_BUDGET) { break; }
        $got = meta_fetch_name($db, (int) $row['id'], (string) $row['psid'], 5);
        if ($got === '' && meta_name_capability_error(meta_name_error())) {
            update_setting('meta_names_blocked_until', date('Y-m-d H:i:s', time() + 12 * 3600));
            break;
        }
    }
}

/** ফেসবুকের "এই অ্যাপের অনুমতি নেই" জাতীয় এরর (নেটওয়ার্কের সাময়িক সমস্যা নয়) */
function meta_name_capability_error(string $err): bool
{
    return $err !== '' && (bool) preg_match('/\(#(3|10|200|230)\)|capability|permission|advanced access/i', $err);
}

/**
 * একজনের নাম ফেসবুক থেকে আনা — দুই পথ: (ক) PSID-এর প্রোফাইল, (খ) কথোপকথনের অংশগ্রহণকারী।
 * না পেলে খালি স্ট্রিং বসে (বারবার চেষ্টা নয়), আর শেষ কারণটা meta_name_error-এ থাকে।
 */
function meta_fetch_name(PDO $db, int $contactId, string $psid, int $timeout = 20): string
{
    $name = ''; $err = '';
    $r = meta_graph('GET', $psid, ['fields' => 'name,first_name,last_name'], null, $timeout);
    if ($r['ok']) {
        $d = (array) $r['data'];
        $name = trim((string) ($d['name'] ?? trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''))));
    } else { $err = $r['error']; }
    if ($name === '') {
        $c = meta_cfg();
        $r2 = meta_graph('GET', $c['page_id'] . '/conversations', ['user_id' => $psid, 'fields' => 'participants'], null, $timeout);
        if ($r2['ok']) {
            foreach ((array) ($r2['data']['data'][0]['participants']['data'] ?? []) as $pt) {
                if ((string) ($pt['id'] ?? '') === $psid) { $name = trim((string) ($pt['name'] ?? '')); break; }
            }
        } elseif ($err === '') { $err = $r2['error']; }
    }
    try {
        $db->prepare('UPDATE meta_contacts SET name = :n WHERE id = :id')->execute(['n' => $name, 'id' => $contactId]);
        if ($name !== '') { update_setting('meta_names_blocked_until', ''); }
        if ($name === '' && $err !== '') { update_setting('meta_name_error', meta_snippet($err, 200)); meta_name_error(meta_snippet($err, 200)); }
    } catch (Throwable $e) {}
    return $name;
}

/** নাম আনতে শেষ ব্যর্থতার কারণ (এই রিকোয়েস্টে নতুন হলে সেটাই — সেটিংসের ক্যাশ পুরনো থাকে) */
function meta_name_error(?string $set = null): string
{
    static $now = null;
    if ($set !== null) { $now = $set; }
    return $now ?? (string) get_setting('meta_name_error');
}

/** ড্যাশবোর্ড/সাইডবারের সংখ্যা: অপঠিত কথোপকথন + নতুন কমেন্ট */
function meta_open_counts(?PDO $db = null): array
{
    $out = ['contacts' => 0, 'comments' => 0];
    $db = $db ?: get_db();
    if (!meta_feature_on() || !meta_ready($db)) { return $out; }
    try {
        $out['contacts'] = (int) $db->query('SELECT COUNT(*) FROM meta_contacts WHERE unread > 0')->fetchColumn();
        $out['comments'] = (int) $db->query("SELECT COUNT(*) FROM meta_comments WHERE status = 'new' AND is_page = 0")->fetchColumn();
    } catch (Throwable $e) {}
    return $out;
}
