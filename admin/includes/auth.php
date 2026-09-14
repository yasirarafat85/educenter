<?php
// এডমিন লগইন যাচাই, সেশন ম্যানেজমেন্ট ও ব্রুট-ফোর্স প্রতিরোধ

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/permissions.php';

const ADMIN_MAX_LOGIN_ATTEMPTS = 5;
const ADMIN_LOGIN_WINDOW_MINUTES = 15;
// নিষ্ক্রিয়তা টাইমআউট — এত মিনিট কোনো অ্যাডমিন অনুরোধ (পেজ লোড/অ্যাকশন) না এলে অটো-লগআউট
const ADMIN_IDLE_TIMEOUT_MINUTES = 30;

function admin_client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// এই IP থেকে সাম্প্রতিক সময়ে অনেক বেশি ভুল লগইন চেষ্টা হয়েছে কিনা
function admin_is_rate_limited(string $ip): bool
{
    $stmt = get_db()->prepare(
        'SELECT COUNT(*) c FROM login_attempts
         WHERE ip_address = :ip AND success = 0 AND attempted_at > (NOW() - INTERVAL :mins MINUTE)'
    );
    $stmt->bindValue('ip', $ip);
    $stmt->bindValue('mins', ADMIN_LOGIN_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    return (int) $stmt->fetch()['c'] >= ADMIN_MAX_LOGIN_ATTEMPTS;
}

function admin_record_login_attempt(string $ip, string $username, bool $success): void
{
    $db = get_db();
    $db->prepare('INSERT INTO login_attempts (ip_address, username, success) VALUES (:ip, :u, :s)')
        ->execute(['ip' => $ip, 'u' => $username, 's' => $success ? 1 : 0]);

    // পুরনো লগ (১ দিনের বেশি) মুছে ফেলা — টেবিল যেন অযথা বড় না হয়
    $db->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

function admin_attempt_login(string $username, string $password): bool
{
    $stmt = get_db()->prepare('SELECT * FROM admin_users WHERE username = :u LIMIT 1');
    $stmt->execute(['u' => $username]);
    $admin = $stmt->fetch();

    // is_active=0 (নিষ্ক্রিয় করা) অ্যাকাউন্ট লগইন করতে পারবে না
    if ($admin && (int) ($admin['is_active'] ?? 1) === 1 && password_verify($password, $admin['password_hash'])) {
        admin_establish_session($admin);
        return true;
    }

    return false;
}

// লগইন সফল হলে সেশন-কী বসানো (পাসওয়ার্ড ও ফিঙ্গারপ্রিন্ট দুই পথেই ব্যবহার হয় — DRY)
function admin_establish_session(array $admin): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_name'] = $admin['full_name'];
    $_SESSION['admin_username'] = $admin['username'];
    $_SESSION['admin_role'] = $admin['role'] ?? 'admin';
    $decoded = json_decode($admin['permissions'] ?? '[]', true);
    $_SESSION['admin_permissions'] = is_array($decoded) ? $decoded : [];
    $_SESSION['admin_last_activity'] = time(); // নিষ্ক্রিয়তা টাইমআউটের ভিত্তি
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_require_login(): void
{
    if (!admin_logged_in()) {
        redirect('login.php');
    }

    // নিষ্ক্রিয়তা টাইমআউট: শেষ কার্যকলাপের পর নির্দিষ্ট সময় পার হলে সেশন শেষ করে লগইনে ফেরত।
    // প্রতিটা অ্যাডমিন পেজ/অ্যাকশন admin_require_login() ডাকে বলে প্রতিবার টাইমার রিফ্রেশ হয় (sliding)।
    $now = time();
    $last = $_SESSION['admin_last_activity'] ?? $now; // পুরনো সেশন (এই ফিচারের আগের) হলে graceful
    if ($now - $last > ADMIN_IDLE_TIMEOUT_MINUTES * 60) {
        admin_logout();
        redirect('login.php?expired=1');
    }
    $_SESSION['admin_last_activity'] = $now;

    // রোল-ভিত্তিক অ্যাক্সেস: এই পেজে মডারেটরের অনুমতি আছে কিনা (মূল অ্যাডমিন সবসময় পায়)
    $script = basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
    if ($script !== '' && !admin_can_page($script)) {
        set_flash('error', 'দুঃখিত, এই অংশে আপনার অ্যাক্সেস নেই।');
        redirect('index.php');
    }
}

function admin_logout(): void
{
    $_SESSION = [];
    session_destroy();
}

function current_admin_name(): string
{
    return $_SESSION['admin_name'] ?? '';
}
