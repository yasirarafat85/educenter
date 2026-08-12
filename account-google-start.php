<?php
// Google লগইন শুরু — state তৈরি করে Google-এ পাঠায়
require_once __DIR__ . '/includes/user-auth.php';
require_once __DIR__ . '/includes/google-oauth.php';

if (user_logged_in()) {
    redirect('account');
}
if (!google_oauth_enabled()) {
    set_flash('error', 'Google লগইন এখন উপলব্ধ নয়।');
    redirect('account-login');
}

$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;
redirect(google_auth_url($state));
