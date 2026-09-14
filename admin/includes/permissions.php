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

// মডারেটরকে যে যে অংশে অনুমতি দেওয়া যায় (checkbox UI + সাইডবার এখান থেকে)।
// কনটেন্ট প্রতিটা টাইপ আলাদা (content:courses, content:worksheets ...) — get_entities() থেকে ডাইনামিক,
// যাতে পুরো অ্যাপের প্রতিটা অংশ আলাদাভাবে নিয়ন্ত্রণ করা যায়।
function admin_permission_sections(): array
{
    require_once __DIR__ . '/entities.php';
    $out = [];
    foreach (get_entities() as $ek => $ec) {
        $out['content:' . $ek] = 'কনটেন্ট — ' . ($ec['label_plural'] ?? $ek);
    }
    return $out + [
        'orders'   => 'অর্ডার / রেজিস্ট্রেশন / আগ্রহ তালিকা / পুরাতন শিক্ষার্থী',
        'parcel'   => 'কোর্স পার্সেল',
        'courier'  => 'কুরিয়ার (পাঠানো ও ট্র্যাকিং)',
        'users'    => 'অভিভাবক অ্যাকাউন্ট',
        'logs'     => 'লগ (ভিজিটর / ডাউনলোড / এরর)',
        'finance'  => 'আয়-ব্যয় (আয়/খরচ)',
        'settings' => 'সাইট সেটিংস',
        'payment'  => 'পেমেন্ট মেথড',
        'backup'   => 'ব্যাকআপ ও ডাউনলোড',
        'archive'  => 'আর্কাইভ (রিস্টোর)',
    ];
}

// কনটেন্ট সেকশন কিনা (content:<entity>) — UI-তে আলাদা গ্রুপে দেখাতে
function admin_is_content_section(string $key): bool
{
    return strncmp($key, 'content:', 8) === 0;
}

// পেজ ফাইল → যে সেকশন(গুলো) থাকলে অ্যাক্সেস (যেকোনো একটা থাকলেই চলবে)
function admin_page_sections(): array
{
    return [
        // manage.php entity-নির্ভর — admin_can_action()-এ আলাদা হ্যান্ডল করা হয়
        'course-batches.php'        => ['content:courses'],
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
        'payment-methods.php'       => ['payment'],
        'backup.php'                => ['backup'],
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

// শুধু মূল অ্যাডমিন (super) — মডারেটর কখনো পাবে না।
// শুধু team.php (মডারেটর-ম্যানেজমেন্ট — মডারেটরকে দিলে সে নিজেই নিজেকে সব অনুমতি দিয়ে দিতে পারত = privilege escalation)।
function admin_super_only_pages(): array
{
    return ['team.php'];
}

// প্রতি সেকশনে যে ৩ ধরনের ক্ষমতা: দেখা / এডিট (যোগ+পরিবর্তন) / ডিলিট
function admin_capabilities(): array
{
    return ['view' => 'দেখা', 'edit' => 'যোগ / এডিট', 'delete' => 'ডিলিট'];
}

// POST-এ যেসব action-মার্কার (GET বা POST) মানে "ডিলিট" — বাকি সব "এডিট" ধরা হয়।
// (২০২৬-০৯-১৪ অডিটে পাওয়া — প্রতিটা ডিলিট-পথ এই তালিকায়; কোনো নীরব ডিলিট নেই)
function admin_delete_actions(): array
{
    return ['delete', 'delete-batch', 'del', 'del-note', 'clear-all', 'purge'];
}

// permissions (DB/session) → সেকশন=>caps[] ম্যাপ-এ নরমালাইজ।
// পুরনো ফরম্যাট (["orders","content"] — flat list) হলে প্রতিটা সেকশনে পূর্ণ caps ধরা হয়।
function admin_normalize_permissions($raw): array
{
    $out = [];
    if (!is_array($raw) || !$raw) {
        return $out;
    }
    $isList = array_keys($raw) === range(0, count($raw) - 1);
    if ($isList) {
        foreach ($raw as $sec) {
            if (is_string($sec)) {
                $out[$sec] = ['view', 'edit', 'delete']; // পুরনো = পূর্ণ
            }
        }
        return $out;
    }
    foreach ($raw as $sec => $caps) {
        $c = array_values(array_intersect(['view', 'edit', 'delete'], (array) $caps));
        if ($c) {
            if (!in_array('view', $c, true)) {
                $c[] = 'view'; // এডিট/ডিলিট থাকলে দেখা স্বয়ংক্রিয়
            }
            $out[$sec] = $c;
        }
    }
    return $out;
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

// একটা সেকশনে নির্দিষ্ট ক্ষমতা (view/edit/delete) আছে কিনা (super সবসময় true)
function admin_can(string $section, string $cap = 'view'): bool
{
    if (admin_is_super()) {
        return true;
    }
    $perms = $_SESSION['admin_permissions'] ?? [];
    return in_array($cap, $perms[$section] ?? [], true);
}

// একটা পেজ ফাইল খোলার (view) অনুমতি আছে কিনা — কেন্দ্রীয় গার্ড এটাই ব্যবহার করে
function admin_can_page(string $script): bool
{
    return admin_can_action($script, 'view');
}

// একটা পেজে নির্দিষ্ট কাজ (view/edit/delete) করার অনুমতি আছে কিনা
function admin_can_action(string $script, string $cap): bool
{
    if (admin_is_super()) {
        return true;
    }
    if (in_array($script, admin_always_allowed_pages(), true)) {
        return true; // self-service (নিজ পাসওয়ার্ড/ফিঙ্গার) — সব caps
    }
    if (in_array($script, admin_super_only_pages(), true)) {
        return false;
    }
    // manage.php entity-নির্ভর: ?entity=courses → content:courses সেকশন
    if ($script === 'manage.php') {
        $ent = $_GET['entity'] ?? '';
        return $ent !== '' && admin_can('content:' . $ent, $cap);
    }
    $sections = admin_page_sections()[$script] ?? null;
    if ($sections === null) {
        return false; // অজানা পেজ → fail-closed
    }
    foreach ($sections as $s) {
        if (admin_can($s, $cap)) {
            return true;
        }
    }
    return false;
}
