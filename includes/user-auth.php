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
    $db->exec('DELETE FROM user_login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
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

    session_regenerate_id(true);
    $_SESSION['user_id'] = $u['id'];
    $_SESSION['user_phone'] = $u['phone'];
    $_SESSION['user_name'] = $u['full_name'];
    $reason = null;
    return true;
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
    if (!user_logged_in()) {
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
    // শুধু ইউজার-সংক্রান্ত সেশন কী মুছি (অ্যাডমিন একই ব্রাউজারে লগইন থাকলে সেটা যেন না ভাঙে)
    unset($_SESSION['user_id'], $_SESSION['user_phone'], $_SESSION['user_name']);
}
