<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 meta-webhook.php — ফেসবুক পেইজের মেসেজ ও কমেন্ট এখানে আসে (২০২৬-১১-১৭)
 *
 * Meta Developer → আপনার অ্যাপ → Webhooks-এ এই ঠিকানাটা বসানো হয়:
 *   Callback URL : https://<সাইট>/meta-webhook.php
 *   Verify token : অ্যাডমিন → ফেসবুক ইনবক্স → "সংযোগ" ট্যাবে দেখানো লেখাটা
 *
 * GET  = মেটার প্রথমবারের যাচাই (hub.challenge ফেরত দেওয়া)
 * POST = আসল খবর — `X-Hub-Signature-256` সই App Secret দিয়ে মিলিয়ে তবেই জমা।
 *
 * 🔴 CSRF নয় (কল আসে মেটার সার্ভার থেকে) — **সই-ই একমাত্র গার্ড**।
 * 🔴 জমা হওয়ার পর শুধু "অটো" চিহ্নিত নিয়ম মিললে, আর অটো-সুইচ চালু থাকলে, উত্তর যায়
 *    (ধাপ ২, `mkb_auto_respond()`)। অটো ব্যর্থ হলেও জমা করা তথ্য অক্ষত থাকে।
 * 🔴 এরপর AI (ধাপ ৩, `mai_handle_fresh()`) — AI-র ডাকে কয়েক সেকেন্ড লাগে, তাই আগে মেটাকে
 *    "ok" জানিয়ে সংযোগ ছেড়ে দেওয়া হয় (`meta_wh_finish()`), তারপর কাজ।
 * 🔴 উত্তরে কোনো তথ্য ফেরত যায় না।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/meta-ai.php'; // meta-kb.php ও meta.php-ও এর ভেতরে লোড হয়

header('X-Robots-Tag: noindex, nofollow');

function meta_wh_out(int $code, string $body = 'ok'): never
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $body;
    exit;
}

/** মেটাকে "ok" পাঠিয়ে সংযোগ ছেড়ে দেওয়া — তারপর পেছনে কাজ চলতে থাকে */
function meta_wh_finish(): void
{
    ignore_user_abort(true);
    @set_time_limit(180);
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Length: 2');
    header('Connection: close');
    echo 'ok';
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
    while (ob_get_level() > 0) { @ob_end_flush(); }
    flush();
}

$cfg = meta_cfg();
$method = $_SERVER['REQUEST_METHOD'] ?? '';

// ── GET: মেটার যাচাই ─────────────────────────────────────────────────────────
// PHP ঠিকানার `hub.mode` কে `hub_mode` বানিয়ে দেয়
if ($method === 'GET') {
    $mode  = (string) ($_GET['hub_mode'] ?? '');
    $token = (string) ($_GET['hub_verify_token'] ?? '');
    $chal  = (string) ($_GET['hub_challenge'] ?? '');
    // 🔴 খালি ↔ খালি কখনো মিলবে না
    if ($mode === 'subscribe' && $cfg['verify_token'] !== '' && $token !== ''
        && hash_equals($cfg['verify_token'], $token) && preg_match('/^[A-Za-z0-9_-]{1,200}$/', $chal)) {
        meta_wh_out(200, $chal);
    }
    meta_wh_out(403, 'forbidden');
}

if ($method !== 'POST') {
    meta_wh_out(405, 'post_only');
}

// ── POST: সই যাচাই ───────────────────────────────────────────────────────────
$raw = (string) file_get_contents('php://input');
if (strlen($raw) > 1024 * 1024) {
    meta_wh_out(413, 'too_large');
}
$sig = (string) ($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
if (!meta_signature_valid($raw, $sig, $cfg['app_secret'])) {
    meta_wh_out(403, 'bad_signature');
}

$db = get_db();
// শেষ কখন খবর এসেছে — সংযোগ ট্যাবে দেখানো হয় (সংযোগ ঠিক আছে কিনা বুঝতে)
try { update_setting('meta_last_webhook_at', date('Y-m-d H:i:s')); } catch (Throwable $e) {}

if (!meta_feature_on()) {
    meta_wh_out(200, 'off'); // বন্ধ থাকলে কিছুই জমা হয় না
}
if (!meta_ready($db)) {
    meta_wh_out(503, 'not_ready'); // মাইগ্রেশনের আগে — মেটা পরে আবার পাঠাবে
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    meta_wh_out(400, 'bad_json');
}

try {
    $got = meta_ingest($db, $payload, $cfg['page_id']);
} catch (Throwable $e) {
    error_log('meta-webhook: ' . $e->getMessage());
    meta_wh_out(500, 'error');
}
// অটো-উত্তর — 🔴 আলাদা try-তে, যাতে ব্যর্থ হলেও মেটা "জমা হয়নি" ভেবে আবার না পাঠায়
try {
    mkb_auto_respond($db, $got['fresh'] ?? []);
} catch (Throwable $e) {
    error_log('meta-webhook auto: ' . $e->getMessage());
}
// 🤖 AI — কাজ থাকলে আগে মেটাকে উত্তর দিয়ে দেওয়া (মেটা দ্রুত উত্তর চায়, AI-র কয়েক সেকেন্ড লাগে)
$fresh = $got['fresh'] ?? [];
try {
    if (mai_has_work($db, $fresh)) {
        meta_wh_finish();
        mai_handle_fresh($db, $fresh);
        exit;
    }
} catch (Throwable $e) {
    error_log('meta-webhook ai: ' . $e->getMessage());
    if (headers_sent()) { exit; }
}
meta_wh_out(200, 'ok');
