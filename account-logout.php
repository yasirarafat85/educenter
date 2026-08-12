<?php
// অভিভাবক লগআউট
require_once __DIR__ . '/includes/user-auth.php';
user_logout();
set_flash('success', 'আপনি লগআউট হয়েছেন।');
redirect('account-login');
