<?php
// Google OAuth 2.0 (authorization code flow, সার্ভার-সাইড) — Composer/লাইব্রেরি ছাড়া, curl দিয়ে।
// ক্রেডেনশিয়াল অ্যাডমিন সেটিংসে (settings টেবিল): google_client_id, google_client_secret।
// Redirect URI = SITE_URL . '/account-google-callback' (Google Cloud-এ হুবহু এটাই রেজিস্টার করা থাকতে হবে)।

require_once __DIR__ . '/functions.php';

function google_redirect_uri(): string
{
    return rtrim(SITE_URL, '/') . '/account-google-callback';
}

// দুই ক্রেডেনশিয়ালই সেট থাকলে Google লগইন সক্রিয়
function google_oauth_enabled(): bool
{
    return get_setting('google_client_id') !== '' && get_setting('google_client_secret') !== '';
}

// Google-এ পাঠানোর auth URL (state = CSRF সুরক্ষা)
function google_auth_url(string $state): string
{
    $params = [
        'client_id'     => get_setting('google_client_id'),
        'redirect_uri'  => google_redirect_uri(),
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'state'         => $state,
        'access_type'   => 'online',
        'prompt'        => 'select_account',
    ];
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

// code → access_token (ব্যর্থ হলে null)
function google_exchange_code(string $code): ?array
{
    $post = [
        'code'          => $code,
        'client_id'     => get_setting('google_client_id'),
        'client_secret' => get_setting('google_client_secret'),
        'redirect_uri'  => google_redirect_uri(),
        'grant_type'    => 'authorization_code',
    ];
    $res = google_http_post('https://oauth2.googleapis.com/token', $post);
    return (is_array($res) && !empty($res['access_token'])) ? $res : null;
}

// access_token → ইউজার তথ্য [sub, email, name] (ব্যর্থ হলে null)
function google_userinfo(string $accessToken): ?array
{
    $ch = curl_init('https://www.googleapis.com/oauth2/v3/userinfo');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200 || !$body) {
        return null;
    }
    $data = json_decode($body, true);
    return (is_array($data) && !empty($data['sub'])) ? $data : null;
}

// সাধারণ POST হেল্পার (JSON রেসপন্স ডিকোড)
function google_http_post(string $url, array $fields): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    if (!$body) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}
