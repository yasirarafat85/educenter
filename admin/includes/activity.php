<?php
// ─────────────────────────────────────────────────────────────
// অ্যাডমিন কার্যকলাপ লগ — "কে কী করল" (২০২৬-০৯-২৪)
//
// 🔑 লেখা হয় **একটাই জায়গা থেকে** — কেন্দ্রীয় গার্ড admin_require_login()।
// প্রতিটা পরিবর্তন (POST) ঐ গার্ড দিয়েই যায়, তাই ৫০টা পেজে আলাদা কোড বসাতে হয় না;
// নতুন কোনো অ্যাডমিন পেজ বানালে সেটাও **নিজে থেকেই** লগ হবে।
//
// ⚠️ যা লেখা হয় তা হলো "কী করতে চাওয়া হয়েছিল" (অনুরোধ) — গার্ড অ্যাকশনের *আগে* চলে
// বলে সফল/ব্যর্থ আলাদা করা যায় না। বাস্তবে প্রায় সব অনুরোধই সফল হয়, আর এটাই
// সবচেয়ে সস্তা ও নির্ভরযোগ্য পদ্ধতি (প্রতিটা হ্যান্ডলারে কোড বসালে একটা মিস হলেই ফাঁক)।
//
// 🔴 লগ কখনো পেজ ভাঙতে পারবে না — পুরোটা try/catch-এ; টেবিল না থাকলে (মাইগ্রেশন
// চালানো হয়নি) চুপচাপ বাদ যায়।
// ─────────────────────────────────────────────────────────────

const ADMIN_ACTIVITY_KEEP_DAYS = 180;

// যেসব পেজের POST লগ করা অর্থহীন (নিছক ব্যক্তিগত পছন্দ, কোনো ডেটা বদলায় না)
function admin_activity_skip_pages(): array
{
    return ['nav-pins.php', 'login.php', 'webauthn-login.php', 'webauthn-login-options.php',
            'webauthn-register.php', 'webauthn-register-options.php'];
}

// অ্যাকশনের বাংলা নাম (তালিকায় পড়ার জন্য) — না মিললে কাঁচা কী-টাই দেখানো হয়
function admin_activity_action_labels(): array
{
    return [
        ''              => 'সেভ',
        'save'          => 'সেভ',
        'delete'        => '🗑 ডিলিট',
        'delete-batch'  => '🗑 ব্যাচ ডিলিট',
        'del'           => '🗑 ডিলিট',
        'del-note'      => '🗑 নোট মুছল',
        'clear-all'     => '🗑 সব মুছল',
        'purge'         => '🗑 আর্কাইভ থেকে চিরতরে',
        'restore'       => '↩ রিস্টোর',
        'status'        => 'স্ট্যাটাস বদল',
        'setstatus'     => 'স্ট্যাটাস বদল',
        'mark'          => 'চিহ্নিত করল',
        'reset'         => '🔑 পাসওয়ার্ড রিসেট',
        'update'        => 'অনুমতি/তথ্য আপডেট',
        'create'        => '➕ নতুন যোগ',
        'toggle'        => 'চালু/বন্ধ',
        'active'        => 'সক্রিয়/নিষ্ক্রিয়',
        'group'         => 'গ্রুপ টিক',
        'quick-group'   => 'গ্রুপ টিক',
        'quick-fields'  => 'বকেয়া/নোট সেভ',
        'pay-save'      => '💰 খাতা সেভ',
        'pay-rebuild'   => '💰 খাতা পুনর্গঠন',
        'zone'          => 'এলাকা বদল',
        'parcels'       => 'মোট পার্সেল বদল',
        'add-note'      => 'নোট যোগ',
        'approve-income'   => '💰 আয় অনুমোদন',
        'unapprove-income' => '💰 আয় বাতিল',
    ];
}

// একটা POST অনুরোধ লগ করা (গার্ড থেকে ডাকা হয়)
function admin_log_activity(string $page): void
{
    if (in_array($page, admin_activity_skip_pages(), true)) {
        return;
    }
    try {
        $action = (string) ($_POST['action'] ?? $_GET['action'] ?? '');
        $target = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);

        // প্রসঙ্গ: শুধু পরিচিত ও নিরাপদ কী (ব্যক্তিগত তথ্য/টাকা কখনো লগে যায় না)
        $ctx = [];
        foreach (['entity', 'item_id', 'month', 'type', 'course_id', 'batch'] as $k) {
            $v = $_GET[$k] ?? $_POST[$k] ?? null;
            if (is_scalar($v) && (string) $v !== '') {
                $ctx[] = $k . '=' . mb_substr((string) $v, 0, 40);
            }
        }
        if (isset($_POST['ids']) && is_array($_POST['ids'])) {
            $ctx[] = 'ids=' . count($_POST['ids']) . ' টি';
        }

        $db = get_db();
        $db->prepare(
            'INSERT INTO admin_activity_log (admin_id, username, role, page, action, target_id, context, ip_address)
             VALUES (:aid, :un, :role, :page, :act, :tid, :ctx, :ip)'
        )->execute([
            'aid'  => (int) ($_SESSION['admin_id'] ?? 0) ?: null,
            'un'   => mb_substr((string) ($_SESSION['admin_username'] ?? ''), 0, 50),
            'role' => (string) ($_SESSION['admin_role'] ?? ''),
            'page' => mb_substr($page, 0, 60),
            'act'  => mb_substr($action, 0, 40),
            'tid'  => $target ?: null,
            'ctx'  => mb_substr(implode(' · ', $ctx), 0, 255),
            'ip'   => admin_client_ip(),
        ]);

        // মাঝেমধ্যে (১% অনুরোধে) পুরনো লগ ছাঁটাই — প্রতিবার DELETE চালানো অপচয়
        if (random_int(1, 100) === 1) {
            $db->exec('DELETE FROM admin_activity_log WHERE created_at < (NOW() - INTERVAL ' . (int) ADMIN_ACTIVITY_KEEP_DAYS . ' DAY)');
        }
    } catch (Throwable $e) {
        // টেবিল নেই / কোয়েরি ব্যর্থ — লগের জন্য কখনো কাজ আটকাবে না
    }
}

// "এখন অনলাইনে" — শেষ কত সেকেন্ডের মধ্যে সক্রিয় থাকলে অনলাইন ধরা হবে
const ADMIN_ONLINE_WINDOW_MINUTES = 5;

// প্রতি পেজ-লোডে last_seen_at আপডেট — 🔴 সেশনে থ্রটল করা (৬০ সেকেন্ডে একবার),
// নাহলে প্রতিটা ক্লিকে একটা করে অপ্রয়োজনীয় UPDATE চলত
function admin_touch_last_seen(): void
{
    $now = time();
    if (($_SESSION['admin_seen_written'] ?? 0) > $now - 60) {
        return;
    }
    $_SESSION['admin_seen_written'] = $now;
    try {
        get_db()->prepare('UPDATE admin_users SET last_seen_at = NOW() WHERE id = :id')
                ->execute(['id' => (int) ($_SESSION['admin_id'] ?? 0)]);
    } catch (Throwable $e) {
        // কলাম নেই (মাইগ্রেশন বাকি) — চুপচাপ বাদ
    }
}
