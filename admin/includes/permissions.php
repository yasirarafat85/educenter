<?php
// ─────────────────────────────────────────────────────────────
// রোল-ভিত্তিক অ্যাক্সেস কন্ট্রোল (RBAC) — মডারেটর/স্টাফ অনুমতি
//
// role='admin'      → মূল অ্যাডমিন, সবকিছুতে অ্যাক্সেস (permissions উপেক্ষিত)
// role='moderator'  → শুধু $_SESSION['admin_permissions'] তালিকার সেকশনে
//
// গার্ড কেন্দ্রীয়: admin_require_login() (auth.php) প্রতিটা পেজে admin_can_page()
// চেক করে — তাই প্রায় সব অ্যাডমিন পেজ অটো সুরক্ষিত। সাইডবার ও team.php-ও এই
// একই ম্যাপ ব্যবহার করে (DRY)।
// ─────────────────────────────────────────────────────────────

// মডারেটরকে যে যে অংশে অনুমতি দেওয়া যায় (checkbox UI + সাইডবার এখান থেকে)
function admin_permission_sections(): array
{
    return [
        'content'  => 'কনটেন্ট (কোর্স/ওয়ার্কশিট/প্রোডাক্ট/নোটিশ/গ্যালারি ইত্যাদি)',
        'orders'   => 'অর্ডার / রেজিস্ট্রেশন / আগ্রহ তালিকা / পুরাতন শিক্ষার্থী',
        'parcel'   => 'কোর্স পার্সেল',
        'courier'  => 'কুরিয়ার (পাঠানো ও ট্র্যাকিং)',
        'users'    => 'অভিভাবক অ্যাকাউন্ট',
        'logs'     => 'লগ (ভিজিটর / ডাউনলোড / এরর)',
        'finance'  => 'আয়-ব্যয়',
        'settings' => 'সাইট সেটিংস ও পেমেন্ট মেথড',
        'archive'  => 'আর্কাইভ (রিস্টোর)',
    ];
}

// পেজ ফাইল → যে সেকশন(গুলো) থাকলে অ্যাক্সেস (যেকোনো একটা থাকলেই চলবে)
function admin_page_sections(): array
{
    return [
        'manage.php'                => ['content'],
        'course-batches.php'        => ['content'],
        'registrations.php'         => ['orders'],
        'course-data.php'           => ['orders'],
        'course-interests.php'      => ['orders'],
        'legacy-students.php'       => ['orders'],
        'course-parcel.php'         => ['parcel'],
        'course-tracking.php'       => ['parcel'],
        'courier-prepare.php'       => ['parcel'],
        'courier.php'               => ['courier'],
        'courier-tracking.php'      => ['courier'],
        'courier-shipment-logs.php' => ['courier'],
        'bulk-courier-action.php'   => ['courier'],
        'send-to-courier.php'       => ['courier'],
        'courier-note-assign.php'   => ['courier', 'parcel'],
        'users.php'                 => ['users'],
        'registration-errors.php'   => ['logs'],
        'download-logs.php'         => ['logs'],
        'visitor-logs.php'          => ['logs'],
        'finance.php'               => ['finance'],
        'income.php'                => ['finance'],
        'expenses.php'              => ['finance'],
        'settings.php'              => ['settings'],
        'payment-methods.php'       => ['settings'],
        'archive.php'               => ['archive'],
    ];
}

// সবসময় অনুমতি (যেকোনো লগইন-করা অ্যাডমিন/মডারেটর — self-service/সাধারণ)
function admin_always_allowed_pages(): array
{
    return [
        'index.php', 'guide.php', 'change-password.php', 'security.php',
        'webauthn-register-options.php', 'webauthn-register.php', 'logout.php',
    ];
}

// শুধু মূল অ্যাডমিন (super) — মডারেটর কখনো পাবে না
function admin_super_only_pages(): array
{
    return ['team.php', 'backup.php'];
}

function admin_role(): string
{
    // লেগাসি সেশন (এই ফিচারের আগের) → 'admin' (তখন সবাই মূল অ্যাডমিন ছিল)
    return $_SESSION['admin_role'] ?? 'admin';
}

function admin_is_super(): bool
{
    return admin_role() === 'admin';
}

// একটা সেকশনে অ্যাক্সেস আছে কিনা (super সবসময় true)
function admin_can(string $section): bool
{
    if (admin_is_super()) {
        return true;
    }
    return in_array($section, $_SESSION['admin_permissions'] ?? [], true);
}

// একটা পেজ ফাইল খোলার অনুমতি আছে কিনা (কেন্দ্রীয় গার্ড এটাই ব্যবহার করে)
function admin_can_page(string $script): bool
{
    if (admin_is_super()) {
        return true;
    }
    if (in_array($script, admin_always_allowed_pages(), true)) {
        return true;
    }
    if (in_array($script, admin_super_only_pages(), true)) {
        return false;
    }
    $sections = admin_page_sections()[$script] ?? null;
    if ($sections === null) {
        return false; // অজানা পেজ → fail-closed (শুধু super)
    }
    foreach ($sections as $s) {
        if (admin_can($s)) {
            return true;
        }
    }
    return false;
}
