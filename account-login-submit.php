<?php
// অভিভাবক লগইন সাবমিট হ্যান্ডলার
require_once __DIR__ . '/includes/user-auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('account-login');
}

function login_fail(string $msg, string $phone = ''): void
{
    set_flash('error', $msg);
    $_SESSION['account_login_old'] = ['phone' => $phone];
    redirect('account-login');
}

if (!csrf_verify()) {
    login_fail('ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
}

$phone = trim($_POST['phone'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($phone === '' || $password === '') {
    login_fail('মোবাইল নাম্বার ও পাসওয়ার্ড দিন।', $phone);
}
if (user_login_rate_limited($phone)) {
    login_fail('অনেকবার ভুল হয়েছে। ১৫ মিনিট পর আবার চেষ্টা করুন।', $phone);
}

$reason = null;
if (user_attempt_login($phone, $password, $reason)) {
    user_record_login_attempt($phone, true);
    redirect('account');
}

user_record_login_attempt($phone, false);

// approve-সংক্রান্ত কারণ হলে স্পষ্ট বার্তা; নাহলে জেনেরিক (কোন নাম্বারে অ্যাকাউন্ট আছে তা ফাঁস না করতে)
switch ($reason) {
    case 'pending':
        login_fail('আপনার অ্যাকাউন্ট এখনো অ্যাডমিন approve করেননি। একটু অপেক্ষা করুন।', $phone);
        break;
    case 'rejected':
        login_fail('আপনার অ্যাকাউন্টটি অনুমোদন করা হয়নি। অ্যাডমিনের সাথে যোগাযোগ করুন।', $phone);
        break;
    case 'blocked':
        login_fail('আপনার অ্যাকাউন্টটি বন্ধ করা হয়েছে। অ্যাডমিনের সাথে যোগাযোগ করুন।', $phone);
        break;
    default:
        login_fail('মোবাইল নাম্বার বা পাসওয়ার্ড ভুল।', $phone);
}
