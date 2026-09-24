<?php
// অভিভাবক (পাবলিক ইউজার) অ্যাকাউন্ট — লগইন যাচাই, সেশন, রেট-লিমিট।
// অ্যাডমিন অথ (admin/includes/auth.php) থেকে সম্পূর্ণ আলাদা সেশন-কী ও রেট-লিমিট টেবিল ব্যবহার করে।

require_once __DIR__ . '/functions.php';

const USER_MAX_LOGIN_ATTEMPTS = 5;
const USER_LOGIN_WINDOW_MINUTES = 15;

// এই ফোনে সাম্প্রতিক সময়ে অনেক ভুল লগইন হয়েছে কিনা (per-phone, per-IP নয় — যাতে শেয়ার্ড IP
// অন্য ইউজারকে ব্লক না করে)
function user_login_rate_limited(string $phone): bool
{
    $stmt = get_db()->prepare(
        'SELECT COUNT(*) c FROM user_login_attempts
         WHERE phone = :p AND success = 0 AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
    );
    $stmt->bindValue('p', $phone);
    $stmt->bindValue('mins', USER_LOGIN_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetch()['c'] >= USER_MAX_LOGIN_ATTEMPTS;
}

function user_record_login_attempt(string $phone, bool $success): void
{
    $db = get_db();
    $db->prepare('INSERT INTO user_login_attempts (ip_address, phone, success) VALUES (:ip, :p, :s)')
        ->execute(['ip' => client_ip(), 'p' => $phone, 's' => $success ? 1 : 0]);
    // ৯০ দিন রাখা হয় — admin/users.php-এ "শেষ লগইন / ব্যর্থ চেষ্টা" দেখাতে লাগে
    $db->exec('DELETE FROM user_login_attempts WHERE attempted_at < (NOW() - INTERVAL 90 DAY)');
}

// লগইন চেষ্টা — সফল হলে true। শুধু status='approved' ইউজার লগইন করতে পারে।
// $reason আউটপুটে কারণ ফেরে (not_found / bad_password / pending / rejected / blocked)।
function user_attempt_login(string $phone, string $password, ?string &$reason = null): bool
{
    $stmt = get_db()->prepare('SELECT * FROM users WHERE phone = :p LIMIT 1');
    $stmt->execute(['p' => $phone]);
    $u = $stmt->fetch();

    if (!$u || empty($u['password_hash']) || !password_verify($password, $u['password_hash'])) {
        $reason = $u ? 'bad_password' : 'not_found';
        return false;
    }
    if ($u['status'] !== 'approved') {
        $reason = $u['status']; // pending / rejected / blocked
        return false;
    }

    user_establish_session($u);
    $reason = null;
    return true;
}

// সেশন বসানো — পাসওয়ার্ড লগইন, গুগল লগইন ও "মনে রাখো" তিন পথেই এটাই ব্যবহার হয় (DRY)
function user_establish_session(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_phone'] = $u['phone'];
    $_SESSION['user_name'] = $u['full_name'];
}

// ============================================================================
//  👁 প্রিভিউ মোড — অ্যাডমিন দেখছেন "অভিভাবক কী দেখেন" (২০২৬-০৯-২৪)
// ============================================================================
//  🔴 এটা **লগইন নয়** — অভিভাবকের সেশন-কী ($_SESSION['user_id']) কখনো বসে না; আলাদা
//  কী (account_preview_user_id) ব্যবহার হয়। ফলে অ্যাডমিন কখনো অভিভাবক "হয়ে" যান না।
//  🔴 প্রিভিউতে **সব লেখা বন্ধ** — পাসওয়ার্ড বদল, বার্তা পাঠানো কিছুই কাজ করে না
//  (প্রতিটা সাবমিট হ্যান্ডলারে user_preview_block() ডাকা হয়)।
//  🔴 প্রিভিউ চলে **শুধু অ্যাডমিন সেশন থাকলে** — অ্যাডমিন লগআউট/টাইমআউট হলেই শেষ।
//  অনুমতির যাচাই হয় প্রিভিউ **শুরু করার সময়** (admin/account-preview.php, গার্ডেড পেজ)।

function user_preview_active(): bool
{
    return !empty($_SESSION['account_preview_user_id']) && !empty($_SESSION['admin_id']);
}

function user_preview_stop(): void
{
    unset($_SESSION['account_preview_user_id'], $_SESSION['account_preview_admin']);
}

// প্রিভিউতে কোনো পরিবর্তন করার চেষ্টা হলে এখানেই আটকে যায়
function user_preview_block(): void
{
    if (user_preview_active()) {
        set_flash('error', '👁 প্রিভিউ মোডে কিছু সেভ করা যায় না — এটা শুধু দেখার জন্য।');
        redirect('account');
    }
}

// পেজের উপরে লাল পট্টি (account*.php-এ site-header-এর পরেই ছাপা হয়)
function user_preview_banner(): string
{
    if (!user_preview_active()) {
        return '';
    }
    $u = user_current();
    $who = $u ? (($u['full_name'] ?: 'অভিভাবক') . ' · ' . $u['phone']) : '';
    return '<div class="rounded-2xl p-4 mb-5 border bg-red-50 flex items-center justify-between gap-3 flex-wrap" style="border-color:#fecaca">'
        . '<div><p class="font-bold text-red-700">👁 প্রিভিউ মোড — আপনি অন্য কারও ড্যাশবোর্ড দেখছেন</p>'
        . '<p class="text-red-600 text-xs mt-0.5">' . e($who) . ' · এখানে কিছু সেভ হবে না, তিনি জানতেও পারবেন না।</p></div>'
        . '<a href="admin/account-preview.php?exit=1" class="text-sm font-bold text-white bg-red-600 px-4 py-2 rounded-xl">✕ প্রিভিউ বন্ধ করুন</a>'
        . '</div>';
}

function user_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function user_id(): int
{
    return (int) ($_SESSION['user_id'] ?? 0);
}

function user_require_login(): void
{
    if (!user_logged_in() && !user_preview_active()) {
        redirect('account-login');
    }
}

// বর্তমান লগইন-করা ইউজারের রো (ক্যাশড)। ব্লক/রিমুভ হয়ে গেলে সেশন শেষ করে ফেরে null।
function user_current(): ?array
{
    static $cached = null;
    static $loaded = false;
    if ($loaded) {
        return $cached;
    }
    $loaded = true;

    // 👁 প্রিভিউ: অ্যাডমিন যে অভিভাবককে দেখছেন তাঁর রো (status যাই হোক — pending/blocked
    // অ্যাকাউন্ট কেমন দেখায় সেটাও অ্যাডমিনের দেখা দরকার)
    if (user_preview_active()) {
        $ps = get_db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $ps->execute(['id' => (int) $_SESSION['account_preview_user_id']]);
        $pu = $ps->fetch();
        if (!$pu) {
            user_preview_stop();
            return $cached = null;
        }
        return $cached = $pu;
    }

    if (!user_logged_in()) {
        return $cached = null;
    }
    $stmt = get_db()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => user_id()]);
    $u = $stmt->fetch();
    // অ্যাকাউন্ট মুছে গেছে বা আর approved নেই — সেশন বাতিল
    if (!$u || $u['status'] !== 'approved') {
        user_logout();
        return $cached = null;
    }
    return $cached = $u;
}

function user_logout(): void
{
    // "মনে রাখো" টোকেনও বাতিল — নাহলে লগআউটের পরেও কুকি দিয়ে আবার ঢুকে যেত
    user_remember_clear();
    // শুধু ইউজার-সংক্রান্ত সেশন কী মুছি (অ্যাডমিন একই ব্রাউজারে লগইন থাকলে সেটা যেন না ভাঙে)
    unset($_SESSION['user_id'], $_SESSION['user_phone'], $_SESSION['user_name']);
}

// ============================================================================
//  "মনে রাখো" — দীর্ঘমেয়াদি লগইন (২০২৬-০৯-২৪)
// ============================================================================
//  🔴 নিরাপত্তার নিয়ম: কুকিতে থাকে **কাঁচা র‍্যান্ডম মান**, ডাটাবেসে থাকে শুধু তার
//  sha256 হ্যাশ — DB ফাঁস হলেও ঐ হ্যাশ দিয়ে কেউ লগইন করতে পারবে না। প্রতিবার ব্যবহারে
//  টোকেন **ঘুরিয়ে** দেওয়া হয় (পুরনো কুকি চুরি হলে আর কাজ করে না), আর লগআউটে মুছে যায়।
//  সেশন-কুকির বদলে আলাদা কুকি — সেশন ছোট থাকে, শেয়ার্ড হোস্টের session GC ছোঁয় না।

const USER_REMEMBER_COOKIE = 'edu_remember';
const USER_REMEMBER_DAYS = 30;

function user_remember_cookie_params(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !(defined('DEV_MODE') && DEV_MODE),   // লাইভে HTTPS-only
        'httponly' => true,                                  // JS পড়তে পারবে না
        'samesite' => 'Lax',
    ];
}

// লগইনের সময় "মনে রাখুন" টিক দিলে — নতুন টোকেন তৈরি + কুকি বসানো
function user_remember_issue(int $userId): void
{
    try {
        $raw  = bin2hex(random_bytes(32));
        $db   = get_db();
        $db->prepare('INSERT INTO user_remember_tokens (user_id, token_hash, expires_at)
                      VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL ' . (int) USER_REMEMBER_DAYS . ' DAY))')
           ->execute(['u' => $userId, 'h' => hash('sha256', $raw)]);
        setcookie(USER_REMEMBER_COOKIE, $raw, user_remember_cookie_params(time() + USER_REMEMBER_DAYS * 86400));
        $db->exec('DELETE FROM user_remember_tokens WHERE expires_at < NOW()');   // পুরনো পরিষ্কার
    } catch (Throwable $e) {
        // টেবিল না থাকলে (মাইগ্রেশন চালানো হয়নি) — সাধারণ লগইন তবু কাজ করবে
    }
}

// কুকি + DB টোকেন মুছে ফেলা (লগআউট / অবৈধ টোকেন)
function user_remember_clear(): void
{
    $raw = (string) ($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw !== '') {
        try {
            get_db()->prepare('DELETE FROM user_remember_tokens WHERE token_hash = :h')
                    ->execute(['h' => hash('sha256', $raw)]);
        } catch (Throwable $e) {
            // উপেক্ষা
        }
    }
    if (isset($_COOKIE[USER_REMEMBER_COOKIE])) {
        setcookie(USER_REMEMBER_COOKIE, '', user_remember_cookie_params(time() - 3600));
        unset($_COOKIE[USER_REMEMBER_COOKIE]);
    }
}

// পেজ লোডে — সেশন নেই কিন্তু বৈধ কুকি আছে? তাহলে নিজে থেকেই লগইন করিয়ে দাও।
// 🔴 status='approved' না হলে কখনো নয় (ব্লক করা অ্যাকাউন্ট কুকি দিয়ে ফিরতে পারবে না)।
function user_try_remember_login(): void
{
    // প্রিভিউ চলাকালে কুকি দিয়ে অটো-লগইন নয় — অ্যাডমিনের নিজের পুরনো কুকি প্রিভিউয়ের
    // সাথে মিশে যেন বিভ্রান্তি না করে
    if (user_logged_in() || user_preview_active()) {
        return;
    }
    $raw = (string) ($_COOKIE[USER_REMEMBER_COOKIE] ?? '');
    if ($raw === '' || !preg_match('/^[a-f0-9]{64}$/', $raw)) {
        return;
    }
    try {
        $db = get_db();
        $stmt = $db->prepare(
            'SELECT t.id AS token_id, u.* FROM user_remember_tokens t
             JOIN users u ON u.id = t.user_id
             WHERE t.token_hash = :h AND t.expires_at > NOW() LIMIT 1'
        );
        $stmt->execute(['h' => hash('sha256', $raw)]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'approved') {
            user_remember_clear();
            return;
        }
        // ব্যবহৃত টোকেন মুছে নতুন একটা বসাই (rotation) — পুরনো কুকি আর কাজ করবে না
        $db->prepare('DELETE FROM user_remember_tokens WHERE id = :id')->execute(['id' => (int) $row['token_id']]);
        user_establish_session($row);
        user_remember_issue((int) $row['id']);
    } catch (Throwable $e) {
        // টেবিল না থাকলে/কোয়েরি ব্যর্থ — চুপচাপ সাধারণ লগইন পেজে
    }
}

// ফাইল লোড হওয়া মানেই অভিভাবক-এলাকার কোনো পেজ — সেশন না থাকলে "মনে রাখো" কুকি দিয়ে
// একবার অটো-লগইনের চেষ্টা (setcookie আউটপুটের আগে চলতে হয় বলে এখানেই, পেজের শুরুতে)
user_try_remember_login();
