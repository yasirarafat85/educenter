<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📨 ফেসবুক ইনবক্স — পেইজের মেসেজ ও কমেন্ট এক জায়গায় (ধাপ ১, ২০২৬-১১-১৭)
 *
 * তিন ট্যাব: মেসেজ · কমেন্ট · সংযোগ।
 * 🔴 ধাপ ২ থেকে "💡 প্রস্তাবিত উত্তর" দেখায় (চাপলে শুধু ঘরে বসে); অটো-উত্তর হয় শুধু ওয়েবহুকে, meta-knowledge.php-র সুইচে।
 * 🔴 ধাপ ৩: "🤖 AI-র খসড়া" — বসানো মাত্র, পাঠান অ্যাডমিন; "🤖 AI দিয়ে লিখুন" চাপলে একটা ডাক (খরচ হয়)।
 * 🔴 গোপন চাবি বদলানো শুধু মূল অ্যাডমিন পারেন; চাবি কখনো পর্দায় দেখানো হয় না।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।
 * ════════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/includes/form-helpers.php'; // admin_image_src()
require_once __DIR__ . '/../includes/meta-ai.php'; // meta-kb.php ও meta.php-ও এর ভেতরে লোড হয় (ধাপ ২: প্রস্তাব, ধাপ ৩: AI)

$db = get_db();
$pageTitle = 'ফেসবুক ইনবক্স';
$me = (string) ($_SESSION['admin_username'] ?? '');
$ready = meta_ready($db);
$tab = in_array($_GET['tab'] ?? '', ['messages', 'comments', 'setup'], true) ? $_GET['tab'] : 'messages';
$action = (string) ($_GET['action'] ?? '');

function mi_back(string $tab, array $extra = []): string
{
    return 'meta-inbox.php?' . http_build_query(array_merge(['tab' => $tab], $extra));
}

/** কমেন্ট-তালিকার ফিল্টার (স্ট্যাটাস + ধরন) — redirect-এ বয়ে নিতে; মান হোয়াইটলিস্ট */
function mi_cflt(array $src): array
{
    $st = (string) ($src['st'] ?? '');
    $ct = (string) ($src['ct'] ?? '');
    return array_filter([
        'st' => in_array($st, ['new', 'done', 'all'], true) ? $st : '',
        'ct' => isset(mai_comment_types()[$ct]) ? $ct : '',
    ]);
}

/**
 * ফর্ম থেকে পাঠানোর ছবি: নতুন আপলোড (ফোন থেকে) আগে, নাহলে সংগ্রহ থেকে বাছা।
 * ফেরত: ['url', 'lib_id', 'error'] — কিছু না দিলে url খালি।
 */
function mi_post_image(PDO $db): array
{
    $f = $_FILES['image_file'] ?? null;
    if ($f && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        try {
            return ['url' => mimg_url(mimg_save_upload('image_file', MIMG_DIR . '/sent')), 'lib_id' => 0, 'error' => ''];
        } catch (Throwable $e) {
            return ['url' => '', 'lib_id' => 0, 'error' => $e->getMessage()];
        }
    }
    $id = (int) ($_POST['image_id'] ?? 0);
    $img = $id > 0 ? mimg_get($db, $id) : null;
    return ['url' => $img ? mimg_url((string) $img['file_path']) : '', 'lib_id' => $img ? $id : 0, 'error' => ''];
}

/* ─────────────────────────── POST হ্যান্ডলার ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না — পাতাটা আবার খুলে চেষ্টা করুন।'); redirect(mi_back($tab)); }

    // ── সংযোগের চাবি (শুধু মূল অ্যাডমিন) ──
    if (in_array($action, ['save-config', 'new-verify', 'feature'], true)) {
        if (!admin_is_super()) { set_flash('error', 'চাবি বদলানো শুধু মূল অ্যাডমিন পারেন।'); redirect(mi_back('setup')); }
        if ($action === 'save-config') {
            $pageId = preg_replace('/\D/', '', (string) ($_POST['page_id'] ?? ''));
            update_setting('meta_page_id', $pageId);
            // খালি রাখলে আগের চাবিই থাকে (পর্দায় কখনো দেখানো হয় না বলে)
            $sec = trim((string) ($_POST['app_secret'] ?? ''));
            if ($sec !== '') {
                if (!preg_match('/^[a-f0-9]{16,64}$/i', $sec)) { set_flash('error', 'App Secret দেখতে ঠিক নয় — Meta Developer → App settings → Basic থেকে আবার কপি করুন।'); redirect(mi_back('setup')); }
                update_setting('meta_app_secret', $sec);
            }
            $tok = trim((string) ($_POST['page_token'] ?? ''));
            if ($tok !== '') {
                if (!preg_match('/^[A-Za-z0-9_\-]{30,800}$/', $tok)) { set_flash('error', 'Page Access Token দেখতে ঠিক নয় — পুরোটা কপি হয়েছে কিনা দেখুন।'); redirect(mi_back('setup')); }
                update_setting('meta_page_token', $tok);
            }
            set_flash('success', 'সংযোগের তথ্য সংরক্ষণ হয়েছে। এবার "🔌 সংযোগ পরীক্ষা করুন" চাপুন।');
        } elseif ($action === 'new-verify') {
            update_setting('meta_verify_token', bin2hex(random_bytes(16)));
            set_flash('success', 'নতুন Verify token তৈরি হয়েছে — Meta Developer-এর Webhooks ঘরেও এটা বদলে দিন।');
        } else {
            $on = ($_POST['on'] ?? '') === '1';
            update_setting('meta_feature_on', $on ? '1' : '0');
            set_flash('success', $on ? 'ফেসবুক ইনবক্স চালু করা হলো।' : 'ফেসবুক ইনবক্স বন্ধ — নতুন মেসেজ/কমেন্ট আর জমা হবে না (পুরনোগুলো থাকবে)।');
        }
        redirect(mi_back('setup'));
    }

    if (!$ready) { set_flash('error', 'আগে database/migrate-meta-inbox.sql চালাতে হবে।'); redirect(mi_back($tab)); }

    // ── সংযোগ পরীক্ষা ──
    if ($action === 'test') {
        $c = meta_cfg();
        if (!meta_configured()) { set_flash('error', 'আগে Page ID ও Page Access Token বসান।'); redirect(mi_back('setup')); }
        $r = meta_graph('GET', $c['page_id'], ['fields' => 'id,name']);
        if ($r['ok']) {
            update_setting('meta_page_name', (string) ($r['data']['name'] ?? ''));
            set_flash('success', '✅ সংযোগ ঠিক আছে — পেইজ: ' . (string) ($r['data']['name'] ?? ''));
        } else {
            set_flash('error', '❌ সংযোগ হয়নি: ' . $r['error']);
        }
        redirect(mi_back('setup'));
    }

    // ── পেইজকে অ্যাপের সাথে যুক্ত করা (নাহলে ওয়েবহুকে খবর আসে না) ──
    if ($action === 'subscribe') {
        $c = meta_cfg();
        $r = meta_graph('POST', $c['page_id'] . '/subscribed_apps', [], ['subscribed_fields' => 'messages,messaging_postbacks,message_echoes,feed']);
        set_flash($r['ok'] ? 'success' : 'error', $r['ok']
            ? '✅ পেইজ অ্যাপের সাথে যুক্ত হলো — এখন থেকে নতুন মেসেজ ও কমেন্ট এখানে আসবে।'
            : '❌ যুক্ত করা যায়নি: ' . $r['error']);
        redirect(mi_back('setup'));
    }

    // ── পুরনো তথ্য আনা ──
    if ($action === 'import-msg' || $action === 'import-posts') {
        @set_time_limit(120);
        if ($action === 'import-msg') {
            $r = meta_import_conversations($db);
            set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? "✅ পুরনো মেসেজ আনা হলো — নতুন {$r['new']}টি মেসেজ জমা হয়েছে।" : '❌ আনা যায়নি: ' . $r['error']);
        } else {
            $r = meta_import_posts($db);
            set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? "✅ {$r['posts']}টি পোস্ট দেখা হলো — নতুন {$r['new']}টি কমেন্ট জমা হয়েছে।" : '❌ আনা যায়নি: ' . $r['error']);
        }
        redirect(mi_back('setup'));
    }

    // ── মেসেঞ্জারে উত্তর ──
    if ($action === 'send') {
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $st = $db->prepare('SELECT * FROM meta_contacts WHERE id = :id');
        $st->execute(['id' => $cid]);
        $contact = $st->fetch();
        if (!$contact) { set_flash('error', 'কথোপকথনটা পাওয়া যায়নি।'); redirect(mi_back('messages')); }
        $pi = mi_post_image($db);
        if ($pi['error'] !== '') {
            $_SESSION['mi_draft'][$cid] = (string) ($_POST['text'] ?? '');
            set_flash('error', '🖼️ ' . $pi['error']);
            redirect(mi_back('messages', ['c' => $cid]) . '#mi-reply');
        }
        $r = meta_send_reply($db, $contact, (string) ($_POST['text'] ?? ''), $pi['url'], $me);
        if ($r['ok'] && $pi['lib_id']) { mimg_used($db, $pi['lib_id']); }
        if (!$r['ok'] && $r['text_ok']) {
            // লেখা চলে গেছে — ঘরে আর ফেরত বসানো হয় না (নাহলে আবার পাঠালে লেখা দুইবার যেত)
            $db->prepare("UPDATE meta_contacts SET unread = 0 WHERE id = :id")->execute(['id' => $cid]);
            mai_close_drafts($db, 'message', (string) $contact['psid']);
            set_flash('error', '⚠️ ' . $r['error']);
        } elseif ($r['ok']) {
            $db->prepare("UPDATE meta_contacts SET unread = 0 WHERE id = :id")->execute(['id' => $cid]);
            mai_close_drafts($db, 'message', (string) $contact['psid']);
            set_flash('success', $pi['url'] !== '' ? '✅ উত্তর ও ছবি পাঠানো হয়েছে।' : '✅ উত্তর পাঠানো হয়েছে।');
        } else {
            $_SESSION['mi_draft'][$cid] = (string) ($_POST['text'] ?? ''); // লেখা হারায় না
            set_flash('error', $r['error']);
        }
        redirect(mi_back('messages', ['c' => $cid]) . '#mi-reply');
    }

    // ── কথোপকথন শেষ/আবার খোলা ──
    if ($action === 'contact-status') {
        $cid = (int) ($_POST['contact_id'] ?? 0);
        $done = ($_POST['status'] ?? '') === 'done';
        $db->prepare('UPDATE meta_contacts SET status = :s, unread = ' . ($done ? '0' : 'unread') . ' WHERE id = :id')
           ->execute(['s' => $done ? 'done' : 'open', 'id' => $cid]);
        set_flash('success', $done ? 'কথোপকথনটা "শেষ" হিসেবে রাখা হলো।' : 'আবার খোলা হলো।');
        redirect(mi_back('messages', ['c' => $cid]));
    }

    // ── কমেন্টে উত্তর ──
    if ($action === 'c-reply') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM meta_comments WHERE id = :id AND is_page = 0');
        $st->execute(['id' => $id]);
        $cm = $st->fetch();
        if (!$cm) { set_flash('error', 'কমেন্টটা পাওয়া যায়নি।'); redirect(mi_back('comments')); }
        $pi = mi_post_image($db);
        $r = $pi['error'] !== '' ? ['ok' => false, 'error' => '🖼️ ' . $pi['error']]
           : meta_reply_comment($db, $cm, (string) ($_POST['text'] ?? ''), $me, 'admin', $pi['url']);
        if (!$r['ok']) { $_SESSION['mi_cdraft'][$id] = (string) ($_POST['text'] ?? ''); }
        else { mai_close_drafts($db, 'comment', (string) $cm['comment_id']); if ($pi['lib_id']) { mimg_used($db, $pi['lib_id']); } }
        set_flash($r['ok'] ? 'success' : 'error', $r['ok'] ? '✅ কমেন্টে উত্তর বসানো হয়েছে।' : $r['error']);
        redirect(mi_back('comments', mi_cflt($_POST)) . '#cm-' . $id);
    }

    // ── 🤖 AI দিয়ে খসড়া লেখানো (একটা ডাক — খরচ হয়) ──
    if ($action === 'ai-draft') {
        $isC = ($_POST['channel'] ?? '') === 'comment';
        $back = $isC ? mi_back('comments', mi_cflt($_POST)) . '#cm-' . (int) ($_POST['id'] ?? 0)
                     : mi_back('messages', ['c' => (int) ($_POST['contact_id'] ?? 0)]) . '#mi-reply';
        if (!mai_ready($db) || !mai_has_key()) { set_flash('error', 'AI এখনো চালু হয়নি — উত্তর-ভান্ডার → 🤖 AI ট্যাবে চাবি বসান (আর migrate-meta-ai.sql চালান)।'); redirect($back); }
        @set_time_limit(120);
        if ($isC) {
            $st = $db->prepare('SELECT * FROM meta_comments WHERE id = :id AND is_page = 0');
            $st->execute(['id' => (int) ($_POST['id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) { set_flash('error', 'কমেন্টটা পাওয়া যায়নি।'); redirect($back); }
            $in = mai_comment_input($db, $row);
            $target = (string) $row['comment_id'];
        } else {
            $st = $db->prepare('SELECT * FROM meta_contacts WHERE id = :id');
            $st->execute(['id' => (int) ($_POST['contact_id'] ?? 0)]);
            $row = $st->fetch();
            if (!$row) { set_flash('error', 'কথোপকথনটা পাওয়া যায়নি।'); redirect($back); }
            $in = mai_message_input($db, $row);
            $target = (string) $row['psid'];
        }
        $ch = $isC ? 'comment' : 'message';
        $g = mai_generate($db, $in['messages'], $ch, $target, $in['ref'], 'manual');
        if (!$g['ok']) { set_flash('error', '❌ AI উত্তর লিখতে পারেনি: ' . $g['error']); redirect($back); }
        mai_save_draft($db, $ch, $target, $in['ref'], $in['question'], $g['text'], $g['human'], '', (int) ($g['image_id'] ?? 0));
        set_flash('success', $g['human'] ? '🤖 AI বলছে এটার উত্তর তার জানা নেই — আপনি নিজে উত্তর দিন (দরকার হলে জ্ঞানভান্ডারে যোগ করুন)।' : '🤖 AI খসড়া লিখেছে — দেখে নিয়ে "বসান" চাপুন, তারপর পাঠান।');
        redirect($back);
    }

    // ── 🤖 AI-র খসড়া বাদ ──
    if ($action === 'ai-dismiss') {
        $isC = ($_POST['channel'] ?? '') === 'comment';
        mai_close_drafts($db, $isC ? 'comment' : 'message', (string) ($_POST['target'] ?? ''), 'dismissed');
        redirect($isC ? mi_back('comments', mi_cflt($_POST)) . '#cm-' . (int) ($_POST['id'] ?? 0)
                      : mi_back('messages', ['c' => (int) ($_POST['contact_id'] ?? 0)]) . '#mi-reply');
    }

    // ── কমেন্ট দেখা হয়েছে / আবার নতুন ──
    if ($action === 'c-status') {
        $id = (int) ($_POST['id'] ?? 0);
        $done = ($_POST['status'] ?? '') === 'done';
        $db->prepare("UPDATE meta_comments SET status = :s WHERE id = :id AND status <> 'removed'")
           ->execute(['s' => $done ? 'done' : 'new', 'id' => $id]);
        redirect(mi_back('comments', mi_cflt($_POST)) . '#cm-' . $id);
    }

    redirect(mi_back($tab));
}

/* ─────────────────────────── দেখানোর ডেটা ─────────────────────────── */
$cfg = meta_cfg();
$counts = ['contacts' => 0, 'comments' => 0];
$contacts = []; $thread = []; $openContact = null; $comments = []; $replies = []; $posts = [];
$cStatus = in_array($_GET['st'] ?? '', ['new', 'done', 'all'], true) ? $_GET['st'] : 'new';
$q = trim((string) ($_GET['q'] ?? ''));
$cType = isset(mai_comment_types()[$_GET['ct'] ?? '']) ? (string) $_GET['ct'] : '';
$typeCounts = [];

if ($ready) {
    // 💡 হাতের নতুন উত্তর থেকে শেখার প্রস্তাব (ধাপ ২) — টেবিল না থাকলে চুপচাপ বাদ
    try { if (mkb_ready($db)) { mkb_scan_history($db); } } catch (Throwable $e) {}
    try {
        $counts['contacts'] = (int) $db->query('SELECT COUNT(*) FROM meta_contacts WHERE unread > 0')->fetchColumn();
        $counts['comments'] = (int) $db->query("SELECT COUNT(*) FROM meta_comments WHERE status = 'new' AND is_page = 0")->fetchColumn();

        if ($tab === 'messages') {
            meta_fill_names($db);
            $where = ''; $params = [];
            if ($q !== '') {
                $where = "WHERE CONCAT_WS(' ', name, last_text) LIKE :q ESCAPE '!'";
                $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            }
            $st = $db->prepare("SELECT * FROM meta_contacts $where ORDER BY (unread > 0) DESC, last_at DESC LIMIT 200");
            $st->execute($params);
            $contacts = $st->fetchAll();

            $cid = (int) ($_GET['c'] ?? 0);
            if ($cid) {
                $st = $db->prepare('SELECT * FROM meta_contacts WHERE id = :id');
                $st->execute(['id' => $cid]);
                $openContact = $st->fetch() ?: null;
                if ($openContact) {
                    $st = $db->prepare('SELECT * FROM (SELECT * FROM meta_messages WHERE contact_id = :id ORDER BY sent_at DESC, id DESC LIMIT 150) t ORDER BY sent_at ASC, id ASC');
                    $st->execute(['id' => $cid]);
                    $thread = $st->fetchAll();
                    // খুলে দেখলেই "পড়া হয়েছে"
                    if ((int) $openContact['unread'] > 0) {
                        $db->prepare('UPDATE meta_contacts SET unread = 0 WHERE id = :id')->execute(['id' => $cid]);
                        $counts['contacts'] = max(0, $counts['contacts'] - 1);
                    }
                }
            }
        } elseif ($tab === 'comments') {
            meta_fill_posts($db);
            $where = ['is_page = 0'];
            $params = [];
            if ($cStatus === 'new') { $where[] = "status = 'new'"; }
            elseif ($cStatus === 'done') { $where[] = "status IN ('done','removed')"; }
            if ($q !== '') {
                $where[] = "CONCAT_WS(' ', from_name, message) LIKE :q ESCAPE '!'";
                $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            }
            // ধরন (ধাপ ৪) — নিয়মে, PHP-তে হিসাব হয় (জমা থাকে না, তাই মাইগ্রেশন লাগে না)
            $st = $db->prepare('SELECT * FROM meta_comments WHERE ' . implode(' AND ', $where) . ' ORDER BY commented_at DESC LIMIT 300');
            $st->execute($params);
            $comments = [];
            foreach ($st->fetchAll() as $row) {
                $row['_type'] = mai_classify((string) $row['message']);
                $typeCounts[$row['_type']] = ($typeCounts[$row['_type']] ?? 0) + 1;
                if ($cType === '' || $row['_type'] === $cType) { $comments[] = $row; }
            }
            $comments = array_slice($comments, 0, 100);
            if ($comments) {
                $ids = array_column($comments, 'comment_id');
                $in = implode(',', array_fill(0, count($ids), '?'));
                $st = $db->prepare("SELECT * FROM meta_comments WHERE parent_id IN ($in) ORDER BY commented_at ASC");
                $st->execute($ids);
                foreach ($st->fetchAll() as $r) { $replies[$r['parent_id']][] = $r; }
                $pids = array_values(array_unique(array_filter(array_column($comments, 'post_id'))));
                if ($pids) {
                    $in = implode(',', array_fill(0, count($pids), '?'));
                    $st = $db->prepare("SELECT * FROM meta_posts WHERE post_id IN ($in)");
                    $st->execute($pids);
                    foreach ($st->fetchAll() as $p) { $posts[$p['post_id']] = $p; }
                }
            }
        }
    } catch (Throwable $e) {
        error_log('meta-inbox: ' . $e->getMessage());
    }
}

$webhookUrl = rtrim(SITE_URL, '/') . '/meta-webhook.php';
$lastHook = get_setting('meta_last_webhook_at');
$isSuper = admin_is_super();

function mi_time(?string $t): string
{
    if (!$t) { return ''; }
    $ts = strtotime($t);
    if (!$ts) { return ''; }
    return date('Y-m-d') === date('Y-m-d', $ts) ? date('h:i A', $ts) : date('d M, h:i A', $ts);
}
function mi_initial(?string $name): string
{
    $n = trim((string) $name);
    return $n === '' ? '?' : mb_strtoupper(mb_substr($n, 0, 1));
}

/** "💡 প্রস্তাবিত উত্তর" বাক্স — চাপলে লেখাটা নিচের ঘরে বসে মাত্র, 🔴 কিছুই পাঠানো হয় না */
function mi_suggest_box(array $sugs, string $targetId): void
{
    if (!$sugs) { return; }
    echo '<div class="mi-sug"><p class="mi-sug-h">💡 প্রস্তাবিত উত্তর <span>— চাপলে নিচের ঘরে বসবে, দেখে নিয়ে পাঠান</span></p>';
    foreach ($sugs as $sg) {
        $sim = !empty($sg['image_id']) ? mimg_get(get_db(), (int) $sg['image_id']) : null;
        echo '<button type="button" class="mi-sug-b" data-mi-fill="' . e($targetId) . '" data-text="' . e($sg['text']) . '" data-image="' . ($sim ? (int) $sim['id'] : 0) . '">'
           . '<span class="mi-sug-l">' . e($sg['label']) . '</span>' . e(meta_snippet($sg['text'], 140))
           . ($sim ? '<span class="mi-img-tag">🖼️ সাথে ছবি: ' . e((string) $sim['title']) . '</span>' : '') . '</button>';
    }
    echo '</div>';
}

/**
 * 🤖 AI বাক্স — সর্বশেষ খসড়া (থাকলে) + "AI দিয়ে লিখুন" বোতাম। $hidden = ফর্মে যাওয়ার ঘর।
 * 🔴 নিজের আলাদা ফর্ম — উত্তরের ফর্মের ভেতরে কখনো নয় (নেস্টেড ফর্ম চলে না)।
 */
function mi_ai_box(?array $draft, string $fillTarget, string $channel, string $target, array $hidden, bool $canGen): void
{
    if (!$draft && !$canGen) { return; }
    echo '<div class="mi-ai">';
    if ($draft) {
        $isComplaint = (string) ($draft['note'] ?? '') === MAI_COMPLAINT_NOTE;
        if ($isComplaint) {
            echo '<p class="mi-ai-h" style="color:#B91C1C">⚠️ অভিযোগ মনে হচ্ছে <span>— AI ডাকা হয়নি। নিজে দেখে, যত্ন নিয়ে উত্তর দিন (দরকার হলে ফোন করুন)।</span></p>';
        } elseif ((int) $draft['needs_human'] === 1) {
            echo '<p class="mi-ai-h">🤖 AI এর উত্তর জানে না <span>— আপনি নিজে উত্তর দিন। একই প্রশ্ন বারবার এলে জ্ঞানভান্ডারে উত্তরটা যোগ করে দিন।</span></p>';
        } else {
            echo '<p class="mi-ai-h">🤖 AI-র খসড়া <span>— চাপলে নিচের ঘরে বসবে, দেখে নিয়ে পাঠান</span></p>';
            $dim = !empty($draft['image_id']) ? mimg_get(get_db(), (int) $draft['image_id']) : null;
            echo '<button type="button" class="mi-sug-b mi-ai-b" data-mi-fill="' . e($fillTarget) . '" data-text="' . e($draft['draft']) . '" data-image="' . ($dim ? (int) $dim['id'] : 0) . '">' . e($draft['draft'])
               . ($dim ? '<span class="mi-img-tag"><img src="' . e(admin_image_src((string) $dim['file_path'])) . '" alt="" loading="lazy">🖼️ AI এই ছবিটাও দিতে বলছে: ' . e((string) $dim['title']) . '</span>' : '') . '</button>';
        }
        if (!empty($draft['note']) && !$isComplaint) { echo '<p class="mi-ai-n">ℹ️ ' . e($draft['note']) . '</p>'; }
    }
    echo '<div class="mi-ai-row">';
    if ($canGen) {
        echo '<form method="post" action="' . e(mi_back($channel === 'comment' ? 'comments' : 'messages', ['action' => 'ai-draft'])) . '" class="mi-ai-gen">' . csrf_field()
           . '<input type="hidden" name="channel" value="' . e($channel) . '">';
        foreach ($hidden as $k => $v) { echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
        echo '<button type="submit" class="mi-ai-btn">🤖 ' . ($draft ? 'AI দিয়ে আবার লিখুন' : 'AI দিয়ে উত্তর লিখুন') . '</button></form>';
    }
    if ($draft) {
        echo '<form method="post" action="' . e(mi_back($channel === 'comment' ? 'comments' : 'messages', ['action' => 'ai-dismiss'])) . '">' . csrf_field()
           . '<input type="hidden" name="channel" value="' . e($channel) . '"><input type="hidden" name="target" value="' . e($target) . '">';
        foreach ($hidden as $k => $v) { echo '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">'; }
        echo '<button type="submit" class="mi-ai-x">✕ খসড়া বাদ</button></form>';
    }
    echo '</div></div>';
}

/** ছবি বাছার ঘর — সংগ্রহ থেকে, অথবা ফোন থেকে নতুন। উত্তরের ফর্মের ভেতরেই বসে (আলাদা ফর্ম নয়)। */
function mi_image_picker(PDO $db): void
{
    $lib = mimg_all($db);
    echo '<details class="mi-imgpick"><summary>🖼️ ছবি যোগ করুন <span class="mi-img-sel"></span></summary><div class="mi-imgpick-b">';
    if ($lib) {
        echo '<label>সংগ্রহ থেকে</label><select name="image_id" class="mi-img-lib"><option value="0">— ছবি নেই —</option>';
        foreach ($lib as $id => $im) {
            echo '<option value="' . (int) $id . '" data-src="' . e(admin_image_src((string) $im['file_path'])) . '">' . e((string) $im['title']) . '</option>';
        }
        echo '</select><img class="mi-img-prev" alt="" hidden>';
    }
    echo '<label>' . ($lib ? 'অথবা ' : '') . 'ফোন/কম্পিউটার থেকে নতুন ছবি (JPG/PNG, ' . MIMG_MAX_MB . ' MB পর্যন্ত)</label>'
       . '<input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" style="max-width:100%">'
       . '<p class="mi-imgpick-n">নতুন ছবি দিলে সেটাই যাবে। ' . ($lib ? '' : 'বারবার পাঠানোর ছবি উত্তর-ভান্ডার → 🖼️ ছবি ট্যাবে রেখে দিলে এখানে বেছে নিতে পারবেন। ') . 'লেখা খালি রেখে শুধু ছবিও পাঠানো যায়।</p>';
    echo '</div></details>';
}

$aiOn = mai_ready($db) && mai_has_key();

require __DIR__ . '/includes/layout-top.php';
?>
<style>
.mi-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.mi-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1rem;border-radius:.9rem;font-weight:700;font-size:.9rem;background:rgb(var(--c-surface,255 255 255));border:1px solid rgb(var(--c-border,229 231 235));color:rgb(var(--c-text,31 41 55))}
.mi-tab.on{background:rgb(var(--c-primary));color:#fff;border-color:transparent}
.mi-ct{display:inline-block;font-size:.72rem;font-weight:700;padding:.1rem .5rem;border-radius:999px;line-height:1.5}
.mi-ctrow{display:flex;gap:.4rem;flex-wrap:wrap;margin:-.25rem 0 1rem}
.mi-ctf{display:inline-flex;align-items:center;gap:.35rem;padding:.4rem .75rem;border-radius:999px;font-size:.82rem;font-weight:700;background:var(--ctb,#F3F4F6);color:var(--ctd,#374151);border:2px solid transparent}
.mi-ctf span{font-size:.72rem;background:rgba(255,255,255,.7);border-radius:999px;padding:0 .4rem}
.mi-ctf.on{border-color:var(--ctd,#374151)}
.mi-badge{display:inline-block;min-width:1.4rem;padding:0 .4rem;border-radius:999px;background:#EF4444;color:#fff;font-size:.72rem;line-height:1.4rem;text-align:center}
.mi-grid{display:grid;grid-template-columns:minmax(0,1fr);gap:1rem}
@media (min-width:1024px){.mi-grid.has-open{grid-template-columns:minmax(0,340px) minmax(0,1fr)}}
.mi-list a.mi-row{display:flex;gap:.75rem;padding:.8rem 1rem;border-bottom:1px solid rgb(var(--c-border,229 231 235));min-width:0}
.mi-list a.mi-row.on{background:rgb(var(--c-primary) / .08)}
.mi-av{flex:0 0 2.4rem;height:2.4rem;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:800;color:#fff;background:linear-gradient(135deg,#3B82F6,#8B5CF6)}
.mi-bubbles{display:flex;flex-direction:column;gap:.5rem;padding:1rem;max-height:60vh;overflow-y:auto}
.mi-b{max-width:85%;padding:.55rem .85rem;border-radius:1rem;font-size:.92rem;line-height:1.6;word-wrap:break-word;overflow-wrap:anywhere;white-space:pre-wrap}
.mi-b.in{align-self:flex-start;background:rgb(var(--c-surface-2,243 244 246));border-bottom-left-radius:.3rem}
.mi-b.out{align-self:flex-end;background:#2563EB;color:#fff;border-bottom-right-radius:.3rem}
.mi-meta{font-size:.7rem;opacity:.7;margin-top:.15rem}
.mi-reply textarea,.mi-cform textarea{width:100%;min-width:0;border:1px solid rgb(var(--c-border,229 231 235));border-radius:.8rem;padding:.6rem .8rem;font-size:16px;line-height:1.6;resize:vertical}
.mi-card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:1rem;min-width:0}
.mi-post{font-size:.78rem;color:#6B7280;border-left:3px solid #CBD5E1;padding-left:.6rem;margin-bottom:.6rem}
.mi-preply{margin-top:.5rem;padding:.5rem .75rem;border-radius:.75rem;background:#EFF6FF;font-size:.88rem;overflow-wrap:anywhere}
.mi-code{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
.mi-code code{background:#F3F4F6;padding:.35rem .6rem;border-radius:.5rem;font-size:.82rem;word-break:break-all;min-width:0}
.mi-step{border-left:3px solid rgb(var(--c-primary));padding-left:.8rem;margin-bottom:1rem}
.mi-imgpick{margin:.4rem 0 0;font-size:.85rem}
.mi-imgpick summary{cursor:pointer;font-weight:700;color:rgb(var(--c-primary));padding:.25rem 0;list-style:none}
.mi-imgpick summary::-webkit-details-marker{display:none}
.mi-img-sel{font-weight:600;color:#047857}
.mi-imgpick-b{display:grid;gap:.3rem;padding:.6rem;border-radius:.8rem;background:#F9FAFB;border:1px solid rgb(var(--c-border,229 231 235));min-width:0}
.mi-imgpick-b label{font-size:.8rem;font-weight:700;color:#374151;margin-top:.2rem}
.mi-imgpick-b select{width:100%;min-width:0;border:1px solid rgb(var(--c-border,229 231 235));border-radius:.7rem;padding:.45rem .7rem;font-size:16px;background:#fff}
.mi-imgpick-n{font-size:.75rem;color:#6B7280}
.mi-img-prev{max-width:140px;max-height:140px;border-radius:.6rem;border:1px solid #E5E7EB;object-fit:contain;background:#fff}
.mi-img-tag{display:flex;align-items:center;gap:.4rem;margin-top:.35rem;font-size:.78rem;font-weight:600;color:#047857}
.mi-img-tag img{width:40px;height:40px;object-fit:cover;border-radius:.4rem;flex:0 0 auto}
.mi-b-img{display:block;max-width:200px;max-height:200px;margin-top:.35rem;border-radius:.6rem;object-fit:contain;background:#fff}
.mi-sug{margin:.25rem 0 .6rem;padding:.6rem;border-radius:.8rem;background:#FFFBEB;border:1px solid #FDE68A}
.mi-sug-h{font-size:.8rem;font-weight:700;color:#92400E;margin-bottom:.35rem}
.mi-sug-h span{font-weight:400;color:#B45309}
.mi-sug-b{display:block;width:100%;text-align:left;padding:.5rem .7rem;margin-top:.35rem;border-radius:.6rem;background:#fff;border:1px solid #FDE68A;font-size:.86rem;line-height:1.55;color:#1F2937;overflow-wrap:anywhere}
.mi-sug-b:hover{border-color:#F59E0B}
.mi-sug-l{display:block;font-size:.72rem;color:#92400E;font-weight:600}
.mi-ai{margin:.25rem 0 .6rem;padding:.6rem;border-radius:.8rem;background:#F5F3FF;border:1px solid #DDD6FE}
.mi-ai-h{font-size:.8rem;font-weight:700;color:#5B21B6}
.mi-ai-h span{font-weight:400;color:#6D28D9}
.mi-ai-b{border-color:#DDD6FE;white-space:pre-wrap}
.mi-ai-b:hover{border-color:#8B5CF6}
.mi-ai-n{font-size:.75rem;color:#6B7280;margin-top:.35rem;overflow-wrap:anywhere}
.mi-ai-row{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.45rem}
.mi-ai-btn{padding:.4rem .8rem;border-radius:.6rem;background:#7C3AED;color:#fff;font-size:.82rem;font-weight:700}
.mi-ai-x{padding:.4rem .8rem;border-radius:.6rem;background:#fff;border:1px solid #DDD6FE;color:#6B7280;font-size:.82rem;font-weight:600}
.mi-ai-gen button[disabled]{opacity:.6}
</style>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-meta-inbox.sql</code> ফাইলের কোডটুকু চালান — তার আগ পর্যন্ত ফেসবুকের মেসেজ/কমেন্ট জমা হবে না। 🩺 সেটিংস → সিস্টেম চেক পাতাতেও দেখা যাবে, কপি বোতামসহ।</p>
</div>
<?php elseif (!meta_feature_on()): ?>
<div class="bg-gray-100 border border-gray-200 text-gray-700 rounded-2xl p-4 mb-6 text-sm">
    ⏸️ ফেসবুক ইনবক্স এখন <b>বন্ধ</b> — নতুন মেসেজ/কমেন্ট জমা হচ্ছে না। চালু করতে "সংযোগ" ট্যাবে যান।
</div>
<?php endif; ?>

<div class="mi-tabs">
    <a href="<?= e(mi_back('messages')) ?>" class="mi-tab <?= $tab === 'messages' ? 'on' : '' ?>">💬 মেসেজ <?php if ($counts['contacts']): ?><span class="mi-badge"><?= $counts['contacts'] ?></span><?php endif; ?></a>
    <a href="<?= e(mi_back('comments')) ?>" class="mi-tab <?= $tab === 'comments' ? 'on' : '' ?>">🗨️ কমেন্ট <?php if ($counts['comments']): ?><span class="mi-badge"><?= $counts['comments'] ?></span><?php endif; ?></a>
    <a href="<?= e(mi_back('setup')) ?>" class="mi-tab <?= $tab === 'setup' ? 'on' : '' ?>">🔌 সংযোগ</a>
    <?php $mkProp = mkb_proposed_count($db); ?>
    <a href="meta-knowledge.php" class="mi-tab">📚 উত্তর-ভান্ডার <?php if ($mkProp): ?><span class="mi-badge" title="শেখার প্রস্তাব অপেক্ষায়"><?= $mkProp ?></span><?php endif; ?></a>
</div>

<?php if ($tab === 'messages'): ?>
    <?php if ($ready && !$contacts && $q === ''): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">💬</p>
            <p class="font-bold text-gray-800">এখনো কোনো মেসেজ আসেনি</p>
            <p class="text-sm text-gray-500 mt-1">সংযোগ ঠিক হয়ে গেলে পেইজের মেসেজ এখানে আসবে। আগের মেসেজ আনতে "সংযোগ" ট্যাবে "পুরনো মেসেজ আনুন" চাপুন।</p>
        </div>
    <?php elseif ($ready): ?>
    <div class="mi-grid <?= $openContact ? 'has-open' : '' ?>">
        <div class="bg-white rounded-2xl shadow overflow-hidden <?= $openContact ? 'hidden lg:block' : '' ?>" style="min-width:0">
            <form method="get" id="miSearchForm" class="p-3 border-b flex gap-2">
                <input type="hidden" name="tab" value="messages">
                <input type="search" name="q" value="<?= e($q) ?>" data-autosearch="miSearchForm" class="list-search flex-1 px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:0" placeholder="নাম বা লেখা দিয়ে খুঁজুন">
                <button type="submit" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">🔍</button>
            </form>
            <div class="mi-list">
                <?php if (!$contacts): ?><p class="p-4 text-sm text-gray-400">কিছু পাওয়া যায়নি।</p><?php endif; ?>
                <?php foreach ($contacts as $ct):
                    $nm = $ct['name'] !== null && $ct['name'] !== '' ? $ct['name'] : 'ফেসবুক ব্যবহারকারী'; ?>
                    <a href="<?= e(mi_back('messages', array_filter(['c' => $ct['id'], 'q' => $q]))) ?>" class="mi-row <?= $openContact && (int) $openContact['id'] === (int) $ct['id'] ? 'on' : '' ?>">
                        <span class="mi-av"><?= e(mi_initial($nm)) ?></span>
                        <span style="flex:1;min-width:0">
                            <span class="flex items-center gap-2">
                                <b class="text-gray-800 truncate" style="min-width:0;flex:1"><?= e($nm) ?></b>
                                <span class="text-xs text-gray-400 whitespace-nowrap"><?= e(mi_time($ct['last_at'])) ?></span>
                            </span>
                            <span class="flex items-center gap-2">
                                <span class="text-sm text-gray-500 truncate" style="min-width:0;flex:1"><?= e(meta_snippet($ct['last_text'], 60)) ?></span>
                                <?php if ((int) $ct['unread'] > 0): ?><span class="mi-badge"><?= (int) $ct['unread'] ?></span>
                                <?php elseif ($ct['status'] === 'done'): ?><span class="text-xs text-green-600">✓</span><?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($openContact):
            $nm = $openContact['name'] !== null && $openContact['name'] !== '' ? $openContact['name'] : 'ফেসবুক ব্যবহারকারী';
            $win = meta_window_open($openContact['last_in_at']);
            $draft = (string) ($_SESSION['mi_draft'][(int) $openContact['id']] ?? '');
            unset($_SESSION['mi_draft'][(int) $openContact['id']]);
        ?>
        <div class="bg-white rounded-2xl shadow overflow-hidden" style="min-width:0">
            <div class="p-3 border-b flex items-center gap-3 flex-wrap">
                <a href="<?= e(mi_back('messages', array_filter(['q' => $q]))) ?>" class="lg:hidden text-sm font-semibold text-indigo-600">← সব</a>
                <span class="mi-av"><?= e(mi_initial($nm)) ?></span>
                <b class="text-gray-800" style="flex:1;min-width:0;overflow-wrap:anywhere"><?= e($nm) ?></b>
                <form method="post" action="<?= e(mi_back('messages', ['action' => 'contact-status'])) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="contact_id" value="<?= (int) $openContact['id'] ?>">
                    <input type="hidden" name="status" value="<?= $openContact['status'] === 'done' ? 'open' : 'done' ?>">
                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700"><?= $openContact['status'] === 'done' ? '↩ আবার খুলুন' : '✓ শেষ হয়েছে' ?></button>
                </form>
            </div>
            <div class="mi-bubbles" id="miThread">
                <?php if (!$thread): ?><p class="text-sm text-gray-400">কোনো মেসেজ নেই।</p><?php endif; ?>
                <?php foreach ($thread as $m):
                    $out = $m['direction'] === 'out';
                    $txt = trim((string) $m['text']) !== '' ? (string) $m['text'] : meta_attach_label((array) json_decode((string) $m['attach_json'], true));
                    $who = $out ? ($m['source'] === 'admin' ? ('আপনি' . ($m['sent_by'] ? ' (' . $m['sent_by'] . ')' : '')) : ($m['source'] === 'auto' ? '🤖 অটো-উত্তর' : ($m['source'] === 'ai' ? '🤖 AI' : 'পেইজ থেকে'))) : '';
                ?>
                    <div class="mi-b <?= $out ? 'out' : 'in' ?>"><?= e($txt !== '' ? $txt : '(খালি)') ?><?php
                        foreach ((array) json_decode((string) $m['attach_json'], true) as $att) {
                            $au = is_array($att) && ($att['type'] ?? '') === 'image' ? (string) ($att['payload']['url'] ?? '') : '';
                            if (preg_match('~^https?://~i', $au)) { echo '<a href="' . e($au) . '" target="_blank" rel="noopener noreferrer"><img class="mi-b-img" src="' . e($au) . '" alt="ছবি" loading="lazy"></a>'; }
                        } ?><div class="mi-meta"><?= e(trim($who . ' · ' . mi_time($m['sent_at']), ' ·')) ?></div></div>
                <?php endforeach; ?>
            </div>
            <div class="p-3 border-t mi-reply" id="mi-reply">
                <?php if (!$win): ?>
                    <p class="text-sm text-amber-700 bg-amber-50 rounded-xl p-3">⏳ শেষ মেসেজের পর ২৪ ঘণ্টা পেরিয়ে গেছে — মেটার নিয়মে এখন উত্তর পাঠানো যায় না। অভিভাবক আবার মেসেজ দিলে উত্তর দিতে পারবেন।</p>
                <?php elseif (!meta_configured()): ?>
                    <p class="text-sm text-amber-700 bg-amber-50 rounded-xl p-3">উত্তর পাঠাতে আগে "সংযোগ" ট্যাবে Page ID ও Page Access Token বসাতে হবে।</p>
                <?php else: ?>
                <?php
                    $lastIn = '';
                    foreach (array_reverse($thread) as $tm) { if ($tm['direction'] === 'in' && trim((string) $tm['text']) !== '') { $lastIn = (string) $tm['text']; break; } }
                    $sugs = $lastIn !== '' ? mkb_suggest($db, $lastIn, 'message', $openContact['name'] ?? null) : [];
                    mi_suggest_box($sugs, 'mi-reply');
                    mi_ai_box(mai_latest_draft($db, 'message', (string) $openContact['psid']), 'mi-reply', 'message', (string) $openContact['psid'],
                              ['contact_id' => (int) $openContact['id']], $aiOn && $lastIn !== '');
                ?>
                <form method="post" action="<?= e(mi_back('messages', ['action' => 'send'])) ?>" enctype="multipart/form-data" class="mi-sendf">
                    <?= csrf_field() ?>
                    <input type="hidden" name="contact_id" value="<?= (int) $openContact['id'] ?>">
                    <textarea name="text" rows="3" maxlength="2000" placeholder="উত্তর লিখুন…"><?= e($draft) ?></textarea>
                    <?php mi_image_picker($db); ?>
                    <div class="flex items-center justify-between gap-2 mt-2 flex-wrap">
                        <span class="text-xs text-gray-400">ফেসবুক মেসেঞ্জারে সরাসরি যাবে</span>
                        <button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold">📨 পাঠান</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <script>(function(){var t=document.getElementById('miThread');if(t){t.scrollTop=t.scrollHeight;}})();</script>
        <?php endif; ?>
    </div>
    <?php endif; ?>

<?php elseif ($tab === 'comments'): ?>
    <?php if ($ready): ?>
    <form method="get" id="miCommentForm" class="bg-white rounded-2xl shadow p-3 mb-4 flex flex-wrap gap-2 items-center">
        <input type="hidden" name="tab" value="comments">
        <?php foreach (['new' => 'নতুন', 'done' => 'উত্তর দেওয়া / দেখা', 'all' => 'সব'] as $k => $lb): ?>
            <a href="<?= e(mi_back('comments', array_filter(['st' => $k, 'q' => $q, 'ct' => $cType]))) ?>" class="text-sm font-semibold px-3 py-1.5 rounded-lg <?= $cStatus === $k ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>"><?= $lb ?></a>
        <?php endforeach; ?>
        <input type="hidden" name="st" value="<?= e($cStatus) ?>"><input type="hidden" name="ct" value="<?= e($cType) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" data-autosearch="miCommentForm" class="list-search flex-1 px-3 py-2 rounded-xl border border-gray-200 text-sm" style="min-width:160px" placeholder="নাম বা কমেন্ট খুঁজুন">
        <button type="submit" class="px-3 py-2 rounded-xl bg-indigo-600 text-white text-sm font-semibold">🔍 খুঁজুন</button>
    </form>
    <div class="mi-ctrow" aria-label="ধরন অনুযায়ী">
        <a href="<?= e(mi_back('comments', array_filter(['st' => $cStatus, 'q' => $q]))) ?>" class="mi-ctf <?= $cType === '' ? 'on' : '' ?>">সব ধরন <span><?= array_sum($typeCounts) ?></span></a>
        <?php foreach (mai_comment_types() as $tk => $tv): if (empty($typeCounts[$tk]) && $cType !== $tk) { continue; } ?>
            <a href="<?= e(mi_back('comments', array_filter(['st' => $cStatus, 'q' => $q, 'ct' => $tk]))) ?>" class="mi-ctf <?= $cType === $tk ? 'on' : '' ?>" style="--ctb:<?= $tv[1] ?>;--ctd:<?= $tv[2] ?>"><?= e($tv[0]) ?> <span><?= (int) ($typeCounts[$tk] ?? 0) ?></span></a>
        <?php endforeach; ?>
    </div>

    <?php if (!$comments): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">🗨️</p>
            <p class="font-bold text-gray-800"><?= $cStatus === 'new' ? 'নতুন কোনো কমেন্ট নেই' : 'কিছু পাওয়া যায়নি' ?></p>
        </div>
    <?php endif; ?>
    <div class="grid gap-3">
    <?php foreach ($comments as $cm):
        $post = $posts[$cm['post_id']] ?? null;
        $kids = $replies[$cm['comment_id']] ?? [];
        $cdraft = (string) ($_SESSION['mi_cdraft'][(int) $cm['id']] ?? '');
        unset($_SESSION['mi_cdraft'][(int) $cm['id']]);
        $fbLink = $post && $post['permalink'] ? $post['permalink'] : '';
    ?>
        <div class="mi-card" id="cm-<?= (int) $cm['id'] ?>">
            <?php if ($post && trim((string) $post['message']) !== ''): ?>
                <div class="mi-post">📄 পোস্ট: <?= e(meta_snippet($post['message'], 110)) ?></div>
            <?php endif; ?>
            <div class="flex items-start gap-3">
                <span class="mi-av"><?= e(mi_initial($cm['from_name'])) ?></span>
                <div style="flex:1;min-width:0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <b class="text-gray-800"><?= e($cm['from_name'] ?: 'ফেসবুক ব্যবহারকারী') ?></b>
                        <span class="text-xs text-gray-400"><?= e(mi_time($cm['commented_at'])) ?></span>
                        <?= mai_type_chip((string) $cm['_type']) ?>
                        <?php if ($cm['status'] === 'removed'): ?><span class="text-xs font-semibold px-2 py-0.5 rounded bg-red-100 text-red-700">মুছে ফেলা হয়েছে</span>
                        <?php elseif ($cm['status'] === 'new'): ?><span class="text-xs font-semibold px-2 py-0.5 rounded bg-amber-100 text-amber-800">নতুন</span><?php endif; ?>
                    </div>
                    <p class="text-gray-800 mt-1" style="overflow-wrap:anywhere;white-space:pre-wrap"><?= e($cm['message'] !== '' ? $cm['message'] : '(ছবি/স্টিকার)') ?></p>
                    <?php foreach ($kids as $k): ?>
                        <div class="mi-preply"><?= $k['is_page'] ? '↳ <b>পেইজ</b>' . ($k['sent_by'] ? ' (' . e($k['sent_by']) . ')' : '') : '↳ <b>' . e($k['from_name'] ?: 'ব্যবহারকারী') . '</b>' ?>: <?= e($k['message']) ?></div>
                    <?php endforeach; ?>

                    <?php if ($cm['status'] !== 'removed' && meta_configured()): ?>
                    <?php if ($cm['status'] === 'new' && $cm['_type'] === 'praise'): ?>
                        <div class="mi-ai"><p class="mi-ai-h">❤️ প্রশংসা <span>— AI ডাকা হয়নি (খরচ বাঁচাতে)। চাপলে নিচের ঘরে ধন্যবাদ বসবে, দেখে নিয়ে পাঠান।</span></p>
                        <button type="button" class="mi-sug-b" data-mi-fill="cmf-<?= (int) $cm['id'] ?>" data-text="<?= e(mai_thanks_text()) ?>">❤️ ধন্যবাদ জানান</button></div>
                    <?php elseif ($cm['status'] === 'new' && $cm['_type'] === 'spam'): ?>
                        <p class="mi-ai-n mt-2">🚫 স্প্যাম মনে হচ্ছে — উত্তর না দিয়ে "দেখা হয়েছে" চাপুন, দরকার হলে ফেসবুকে লুকিয়ে দিন।</p>
                    <?php endif; ?>
                    <?php if ($cm['status'] === 'new' && $cm['_type'] !== 'spam') {
                        mi_suggest_box(mkb_suggest($db, (string) $cm['message'], 'comment', $cm['from_name'] ?? null), 'cmf-' . (int) $cm['id']);
                        mi_ai_box(mai_latest_draft($db, 'comment', (string) $cm['comment_id']), 'cmf-' . (int) $cm['id'], 'comment', (string) $cm['comment_id'],
                                  ['id' => (int) $cm['id'], 'st' => $cStatus, 'ct' => $cType], $aiOn && trim((string) $cm['message']) !== '');
                    } ?>
                    <form method="post" action="<?= e(mi_back('comments', ['action' => 'c-reply'])) ?>" class="mi-cform mi-sendf mt-3" id="cmf-<?= (int) $cm['id'] ?>" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $cm['id'] ?>">
                        <input type="hidden" name="st" value="<?= e($cStatus) ?>"><input type="hidden" name="ct" value="<?= e($cType) ?>">
                        <textarea name="text" rows="2" maxlength="2000" placeholder="কমেন্টের নিচে উত্তর লিখুন (সবাই দেখবে)…"><?= e($cdraft) ?></textarea>
                        <?php mi_image_picker($db); ?>
                        <button type="submit" class="mt-2 px-4 py-2 rounded-xl bg-indigo-600 text-white text-sm font-bold">💬 উত্তর দিন</button>
                    </form>
                    <?php endif; ?>
                    <div class="flex items-center gap-2 mt-2 flex-wrap">
                        <?php if ($cm['status'] !== 'removed'): ?>
                        <form method="post" action="<?= e(mi_back('comments', ['action' => 'c-status'])) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $cm['id'] ?>">
                            <input type="hidden" name="st" value="<?= e($cStatus) ?>"><input type="hidden" name="ct" value="<?= e($cType) ?>">
                            <input type="hidden" name="status" value="<?= $cm['status'] === 'new' ? 'done' : 'new' ?>">
                            <button type="submit" class="px-3 py-2 rounded-xl bg-gray-100 text-gray-700 text-sm font-semibold"><?= $cm['status'] === 'new' ? '✓ দেখা হয়েছে (উত্তর লাগবে না)' : '↩ আবার নতুন' ?></button>
                        </form>
                        <?php endif; ?>
                        <?php if ($fbLink): ?><a href="<?= e($fbLink) ?>" target="_blank" rel="noopener noreferrer" class="text-sm font-semibold text-indigo-600">ফেসবুকে দেখুন ↗</a><?php endif; ?>
                        </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php else: /* ── সংযোগ ট্যাব ── */ ?>
    <div class="grid gap-4" style="max-width:860px">
        <div class="mi-card">
            <h3 class="font-bold text-gray-800 mb-3">📶 অবস্থা</h3>
            <ul class="text-sm space-y-1.5 text-gray-700">
                <li><?= $ready ? '✅' : '❌' ?> ডাটাবেস টেবিল <?= $ready ? 'আছে' : '— migrate-meta-inbox.sql চালানো বাকি' ?></li>
                <li><?= $cfg['page_id'] !== '' ? '✅' : '⬜' ?> Page ID <?= $cfg['page_id'] !== '' ? e('(' . $cfg['page_id'] . ')') : 'বসানো হয়নি' ?> <?= get_setting('meta_page_name') ? '— ' . e(get_setting('meta_page_name')) : '' ?></li>
                <li><?= $cfg['page_token'] !== '' ? '✅' : '⬜' ?> Page Access Token <?= $cfg['page_token'] !== '' ? 'বসানো আছে (…' . e(substr($cfg['page_token'], -4)) . ')' : 'বসানো হয়নি' ?></li>
                <li><?= $cfg['app_secret'] !== '' ? '✅' : '⬜' ?> App Secret <?= $cfg['app_secret'] !== '' ? 'বসানো আছে' : 'বসানো হয়নি' ?></li>
                <li><?= $lastHook ? '✅' : '⬜' ?> ফেসবুক থেকে শেষ খবর এসেছে: <?= $lastHook ? e(date('d M Y, h:i A', strtotime($lastHook))) : 'এখনো আসেনি' ?></li>
                <li><?= meta_feature_on() ? '✅ চালু' : '⏸️ বন্ধ' ?></li>
            </ul>
        </div>

        <div class="mi-card">
            <h3 class="font-bold text-gray-800 mb-3">🔗 ধাপ ১ — Meta Developer-এর Webhooks ঘরে এই দুটো বসান</h3>
            <p class="text-sm text-gray-600 mb-2">Meta Developer → আপনার অ্যাপ → <b>Webhooks</b> (বা Messenger → Settings → Webhooks) → <b>Page</b> বেছে "Subscribe to this object"।</p>
            <div class="mi-step">
                <p class="text-xs text-gray-500 mb-1">Callback URL</p>
                <div class="mi-code"><code id="miUrl"><?= e($webhookUrl) ?></code><button type="button" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-indigo-100 text-indigo-700" data-mi-copy="miUrl">📋 কপি</button></div>
            </div>
            <div class="mi-step">
                <p class="text-xs text-gray-500 mb-1">Verify token</p>
                <?php if ($cfg['verify_token'] !== ''): ?>
                    <div class="mi-code"><code id="miVt"><?= e($cfg['verify_token']) ?></code><button type="button" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-indigo-100 text-indigo-700" data-mi-copy="miVt">📋 কপি</button></div>
                <?php else: ?>
                    <p class="text-sm text-gray-500">এখনো তৈরি হয়নি — নিচের বোতাম চাপুন।</p>
                <?php endif; ?>
                <?php if ($isSuper): ?>
                <form method="post" action="<?= e(mi_back('setup', ['action' => 'new-verify'])) ?>" class="mt-2" <?= $cfg['verify_token'] !== '' ? 'onsubmit="return confirmSubmit(this, \'নতুন Verify token বানালে Meta Developer-এর ঘরেও বদলাতে হবে। চালিয়ে যাবেন?\');"' : '' ?>>
                    <?= csrf_field() ?>
                    <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700"><?= $cfg['verify_token'] !== '' ? '↻ নতুন token বানান' : '➕ Verify token তৈরি করুন' ?></button>
                </form>
                <?php endif; ?>
            </div>
            <p class="text-sm text-gray-600">এরপর একই পাতায় এই ঘরগুলোতে <b>Subscribe</b> চাপুন: <code>messages</code>, <code>messaging_postbacks</code>, <code>message_echoes</code>, <code>feed</code>।</p>
        </div>

        <div class="mi-card">
            <h3 class="font-bold text-gray-800 mb-1">🔑 ধাপ ২ — চাবিগুলো বসান</h3>
            <p class="text-sm text-gray-600 mb-3">🔴 এগুলো কাউকে (চ্যাটেও) পাঠাবেন না — শুধু এখানে বসান। একবার বসালে পর্দায় আর দেখানো হয় না; ঘর খালি রেখে সংরক্ষণ করলে আগেরটাই থাকে।</p>
            <?php if ($isSuper): ?>
            <form method="post" action="<?= e(mi_back('setup', ['action' => 'save-config'])) ?>" class="grid gap-3" autocomplete="off">
                <?= csrf_field() ?>
                <label class="text-sm"><b>Page ID</b> <span class="text-gray-500">— ফেসবুক পেইজ → About → Page transparency, বা Meta Business Suite-এ</span>
                    <input type="text" name="page_id" inputmode="numeric" value="<?= e($cfg['page_id']) ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200" style="font-size:16px;min-width:0">
                </label>
                <label class="text-sm"><b>App Secret</b> <span class="text-gray-500">— Meta Developer → App settings → Basic → App secret (Show)</span>
                    <input type="password" name="app_secret" placeholder="<?= $cfg['app_secret'] !== '' ? '•••••••• (বসানো আছে)' : '' ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200" style="font-size:16px;min-width:0" autocomplete="new-password">
                </label>
                <label class="text-sm"><b>Page Access Token</b> <span class="text-gray-500">— Messenger → Settings → Access Tokens → পেইজের পাশে "Generate token"</span>
                    <input type="password" name="page_token" placeholder="<?= $cfg['page_token'] !== '' ? '•••••••• (বসানো আছে)' : '' ?>" class="mt-1 w-full px-3 py-2 rounded-xl border border-gray-200" style="font-size:16px;min-width:0" autocomplete="new-password">
                </label>
                <div><button type="submit" class="px-5 py-2.5 rounded-xl bg-indigo-600 text-white text-sm font-bold">💾 সংরক্ষণ করুন</button></div>
            </form>
            <?php else: ?>
                <p class="text-sm text-gray-500">চাবি বসানো/বদলানো শুধু মূল অ্যাডমিন পারেন।</p>
            <?php endif; ?>
        </div>

        <div class="mi-card">
            <h3 class="font-bold text-gray-800 mb-3">✅ ধাপ ৩ — পরীক্ষা ও যুক্ত করা</h3>
            <div class="flex flex-wrap gap-2">
                <?php foreach ([
                    ['test', '🔌 সংযোগ পরীক্ষা করুন', 'bg-indigo-600 text-white'],
                    ['subscribe', '🔗 পেইজকে অ্যাপের সাথে যুক্ত করুন', 'bg-green-600 text-white'],
                    ['import-msg', '📥 পুরনো মেসেজ আনুন', 'bg-gray-100 text-gray-800'],
                    ['import-posts', '📥 পুরনো পোস্টের কমেন্ট আনুন', 'bg-gray-100 text-gray-800'],
                ] as [$act, $lbl, $cls]): ?>
                    <form method="post" action="<?= e(mi_back('setup', ['action' => $act])) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="px-4 py-2.5 rounded-xl text-sm font-bold <?= $cls ?>" <?= (!$ready || !meta_configured()) ? 'disabled style="opacity:.5"' : '' ?>><?= $lbl ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
            <p class="text-xs text-gray-500 mt-3">পুরনো মেসেজ: সর্বশেষ <?= META_IMPORT_CONVERSATIONS ?>টি কথোপকথন · পুরনো কমেন্ট: সর্বশেষ <?= META_IMPORT_POSTS ?>টি পোস্ট। আবার চাপলে নতুনগুলোই যোগ হয়, ডুপ্লিকেট হয় না। আনা পুরনো কমেন্ট "নতুন" তালিকায় আসে না।</p>
        </div>

        <?php if ($isSuper): ?>
        <div class="mi-card">
            <h3 class="font-bold text-gray-800 mb-2">⏯️ চালু / বন্ধ</h3>
            <form method="post" action="<?= e(mi_back('setup', ['action' => 'feature'])) ?>" class="flex items-center gap-3 flex-wrap">
                <?= csrf_field() ?>
                <input type="hidden" name="on" value="<?= meta_feature_on() ? '0' : '1' ?>">
                <span class="text-sm text-gray-600">এখন: <b><?= meta_feature_on() ? 'চালু' : 'বন্ধ' ?></b></span>
                <button type="submit" class="px-4 py-2 rounded-xl text-sm font-bold <?= meta_feature_on() ? 'bg-red-100 text-red-700' : 'bg-green-600 text-white' ?>"><?= meta_feature_on() ? '⏸️ বন্ধ করুন' : '▶️ চালু করুন' ?></button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    <script>
    (function () {
        document.querySelectorAll('[data-mi-copy]').forEach(function (b) {
            b.addEventListener('click', function () {
                var t = (document.getElementById(b.getAttribute('data-mi-copy')) || {}).textContent || '';
                var done = function () { b.textContent = '✅ কপি হয়েছে'; setTimeout(function () { b.textContent = '📋 কপি'; }, 1800); };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(t).then(done, function () { fallback(); });
                } else { fallback(); }
                function fallback() {
                    var ta = document.createElement('textarea'); ta.value = t; ta.style.position = 'fixed'; ta.style.opacity = '0';
                    document.body.appendChild(ta); ta.select();
                    try { document.execCommand('copy'); done(); } catch (e) {}
                    document.body.removeChild(ta);
                }
            });
        });
    })();
    </script>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('form.mi-ai-gen').forEach(function (f) {
        f.addEventListener('submit', function () {
            var b = f.querySelector('button');
            if (b) { setTimeout(function () { b.disabled = true; b.textContent = '🤖 লিখছে… একটু অপেক্ষা করুন'; }, 0); }
        });
    });
    document.querySelectorAll('.mi-imgpick').forEach(function (d) {
        var sel = d.querySelector('select.mi-img-lib'), prev = d.querySelector('.mi-img-prev'),
            file = d.querySelector('input[type=file]'), lbl = d.querySelector('.mi-img-sel');
        function show() {
            var t = '';
            if (file && file.files && file.files.length) { t = '— ' + file.files[0].name; }
            else if (sel && sel.value !== '0') { t = '— ' + sel.options[sel.selectedIndex].text; }
            if (lbl) { lbl.textContent = t; }
            if (prev && sel) {
                var o = sel.options[sel.selectedIndex];
                var src = o ? o.getAttribute('data-src') : '';
                if (src && !(file && file.files && file.files.length)) { prev.src = src; prev.hidden = false; } else { prev.hidden = true; }
            }
        }
        if (sel) { sel.addEventListener('change', show); }
        if (file) { file.addEventListener('change', show); }
    });
    document.querySelectorAll('form.mi-sendf').forEach(function (f) {
        f.addEventListener('submit', function (ev) {
            var ta = f.querySelector('textarea[name=text]'), sel = f.querySelector('select.mi-img-lib'), file = f.querySelector('input[name=image_file]');
            var has = (ta && ta.value.trim() !== '') || (sel && sel.value !== '0') || (file && file.files && file.files.length);
            if (!has && ta) { ev.preventDefault(); ta.setCustomValidity('উত্তর লিখুন অথবা একটা ছবি বাছুন।'); ta.reportValidity(); }
        });
        var t2 = f.querySelector('textarea[name=text]');
        if (t2) { t2.addEventListener('input', function () { t2.setCustomValidity(''); }); }
    });
    document.querySelectorAll('[data-mi-fill]').forEach(function (b) {
        b.addEventListener('click', function () {
            var box = document.getElementById(b.getAttribute('data-mi-fill'));
            var ta = box ? box.querySelector('textarea') : null;
            if (!ta) { return; }
            ta.value = b.getAttribute('data-text') || '';
            var imgId = b.getAttribute('data-image') || '0';
            var sel = box.querySelector('select.mi-img-lib');
            if (sel) {
                sel.value = sel.querySelector('option[value="' + imgId + '"]') ? imgId : '0';
                sel.dispatchEvent(new Event('change'));
                if (imgId !== '0') { var d = sel.closest('details'); if (d) { d.open = true; } }
            }
            ta.focus();
        });
    });
})();
</script>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
