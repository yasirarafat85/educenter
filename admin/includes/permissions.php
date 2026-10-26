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
        'reports'  => 'রিপোর্ট / ফলাফল (PDF আপলোড)',
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
        // ⚠️ course-media.php **এই ম্যাপ দিয়ে চলে না** — টাইপ-নির্ভর, admin_can_action()-এ
        //    আলাদা শাখা আছে (কোর্স/ওয়ার্কশিট/প্রোডাক্ট তিনটা আলাদা সেকশনে)। এন্ট্রিটা
        //    এখানে রাখা হয়েছে শুধু "অজানা পেজ → fail-closed" গার্ডে না পড়ার জন্য।
        'course-media.php'          => ['content:courses'],
        // 📅 এই মাসের কাজ — পার্সেল ও টাকা দুটোই দেখায়; নিজে কিছু লেখে না (POST নেই),
        //    প্রতিটা বোতাম registrations.php/course-parcel.php-র হ্যান্ডলারে যায় — ওখানেই cap যাচাই হয়
        'month-work.php'            => ['parcel', 'orders'],
        'registrations.php'         => ['orders'],
        'reports.php'               => ['reports'],
        'course-data.php'           => ['orders'],
        // 💰 আয় মেলানো — অর্ডারের টাকার খাতা বসায় (registrations.php-এর "টাকা" ড্রয়ারের মতোই), তাই একই সেকশন
        'income-fix.php'            => ['orders'],
        'course-interests.php'      => ['orders'],
        // 📥 পেমেন্ট ইনবক্স — ফোন থেকে আসা SMS ও অভিভাবকের দাবি (২০২৬-১০-০৬)
        // 🔴 টাকার বইয়ে কিছু লেখে না, কিন্তু অর্ডারের টাকার সাথে জড়িত — তাই `orders`।
        //    গোপন চাবি দেখানো/বদলানো আলাদাভাবে `admin_is_super()`-এ গার্ডেড (পাতার ভেতরে)।
        'payment-inbox.php'         => ['orders'],
        // 📨 SMS ছাঁচ — কনফিগারেশন, তাই `settings`
        'sms-patterns.php'          => ['settings'],
        'legacy-students.php'       => ['orders'],
        'course-parcel.php'         => ['parcel'],
        // 👥 গ্রুপ মিলিয়ে দেখা — শুধু পড়ে দেখায় (কিছু সেভ করে না), কিন্তু গ্রুপের
        //    টিক parcel-এ আর রেজিস্ট্রেশনের নাম orders-এ — যেকোনো একটা থাকলেই চলবে।
        'group-match.php'           => ['parcel', 'orders'],
        'course-tracking.php'       => ['parcel'],
        'courier-prepare.php'       => ['parcel'],
        'courier.php'               => ['courier'],
        'courier-tracking.php'      => ['courier'],
        'courier-shipment-logs.php' => ['courier'],
        'bulk-courier-action.php'   => ['courier'],
        'send-to-courier.php'       => ['courier'],
        'courier-note-assign.php'   => ['courier', 'parcel'],
        'users.php'                 => ['users'],
        'user-requests.php'         => ['users'],
        'registration-errors.php'   => ['logs'],
        'download-logs.php'         => ['logs'],
        'visitor-logs.php'          => ['logs'],
        'login-logs.php'            => ['logs'],
        'activity-log.php'          => ['logs'],
        'account-preview.php'       => ['users'],
        'finance.php'               => ['finance'],
        'income.php'                => ['finance'],
        'expenses.php'              => ['finance'],
        'settings.php'              => ['settings'],
        // 🩺 সিস্টেম চেক — শুধু পড়ে (কোন SQL বাকি), কিছুই লেখে না; তাই settings সেকশনেই
        'system-check.php'          => ['settings'],
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
        // ⭐ সাইডবারের প্রিয়-লিংক সাজানো: নিজের পছন্দ, কোনো ডেটা দেখায় না —
        // তাই "শুধু দেখার" মডারেটরও নিজের সাইডবার গুছিয়ে নিতে পারেন
        'nav-pins.php',
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

// ─────────────────────────────────────────────────────────────
// 🧩 স্টাফ-কাজ (সেকশন×cap-এর উপরে পাতলা স্তর, ২০২৬-১০-২৫)
//
// সমস্যা: "অর্ডার — এডিট" দিলে registrations.php-এর ~১০টা হ্যান্ডলার একসাথে খোলে
// (কনফার্ম · টাকা · সরানো · ডিলিট …)। কখনো কখনো একজন স্টাফকে **শুধু একটা কাজ**
// দিতে হয় — যেমন শুধু পেন্ডিং→কনফার্ম, বা শুধু গ্রুপে যোগ টিক।
//
// এখানে প্রতিটা কাজ শুধু একটা নির্দিষ্ট (পেজ, POST-অ্যাকশন) খোলে — আর কিছু নয়।
// 🔴 নিরাপদ দিকটা নিজে থেকেই আসে: কাজটা না দিলে কেন্দ্রীয় গার্ড ঐ অ্যাকশন আটকায়;
//    আসল সীমা (যেমন "শুধু confirmed, শুধু pending থেকে") সংশ্লিষ্ট হ্যান্ডলারে যাচাই হয়।
// ─────────────────────────────────────────────────────────────
function admin_staff_tasks(): array
{
    return [
        'order-confirm' => [
            'label' => 'শুধু পেন্ডিং অর্ডার কনফার্ম করা',
            'hint'  => 'অর্ডার তালিকায় পেন্ডিং অর্ডারে "✓ কনফার্ম" — অন্য স্ট্যাটাস, টাকা, সরানো বা ডিলিট নয়।',
            'allow' => [['registrations.php', 'update-status']],
        ],
        'group-add' => [
            'label' => 'গ্রুপে যোগ হলে টিক দেওয়া (তোলা যায় না)',
            'hint'  => 'ফেসবুক/মেসেঞ্জার গ্রুপে যোগ হলে ✓ — একবার দিলে স্টাফ আর তুলতে পারবে না, শুধু মূল অ্যাডমিন পারবেন।',
            'allow' => [['registrations.php', 'quick-group'], ['course-parcel.php', 'group']],
        ],
    ];
}

// এই স্টাফ-কাজটা মডারেটরকে দেওয়া আছে কিনা (super সবসময় পায়)
function admin_task_granted(string $key): bool
{
    if (admin_is_super()) {
        return true;
    }
    $perms = $_SESSION['admin_permissions'] ?? [];
    return in_array('do', $perms['task:' . $key] ?? [], true);
}

// granted কোনো কাজ ঠিক এই (পেজ, POST-অ্যাকশন) খোলে কিনা — কেন্দ্রীয় গার্ড এটাই দেখে
function admin_task_allows(string $script, string $action): bool
{
    if (admin_is_super()) {
        return true;
    }
    foreach (admin_staff_tasks() as $key => $t) {
        if (!admin_task_granted($key)) {
            continue;
        }
        foreach ($t['allow'] as $pair) {
            if (($pair[0] ?? '') === $script && ($pair[1] ?? '') === $action) {
                return true;
            }
        }
    }
    return false;
}

// granted কোনো কাজ এই পেজে কিছু করতে দেয় কিনা — তাহলে পেজটা খোলার (view) অনুমতিও দরকার
function admin_task_opens_page(string $script): bool
{
    if (admin_is_super()) {
        return true;
    }
    foreach (admin_staff_tasks() as $key => $t) {
        if (!admin_task_granted($key)) {
            continue;
        }
        foreach ($t['allow'] as $pair) {
            if (($pair[0] ?? '') === $script) {
                return true;
            }
        }
    }
    return false;
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
        // 🧩 স্টাফ-কাজ (task:*) — view/edit/delete নয়, একটাই বুলিয়ান 'do'
        if (strncmp((string) $sec, 'task:', 5) === 0) {
            if (in_array('do', (array) $caps, true)) {
                $out[$sec] = ['do'];
            }
            continue;
        }
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
    // 🧩 granted স্টাফ-কাজ এই পেজে কিছু করতে দিলে, পেজটা খোলার (view) অনুমতিও থাকে।
    // লেখা (edit/delete) আলাদা করে কেন্দ্রীয় গার্ডে admin_task_allows() দিয়ে যাচাই হয়।
    if ($cap === 'view' && admin_task_opens_page($script)) {
        return true;
    }
    // manage.php entity-নির্ভর: ?entity=courses → content:courses সেকশন
    if ($script === 'manage.php') {
        $ent = $_GET['entity'] ?? '';
        return $ent !== '' && admin_can('content:' . $ent, $cap);
    }
    // 🔴 course-media.php-ও এখন টাইপ-নির্ভর (২০২৬-০৯-২৬): ওয়ার্কশিটের ছবি পরিচালনার
    //    অনুমতি `content:worksheets`-এ, কোর্সেরটা `content:courses`-এ — একটার অনুমতি
    //    দিয়ে অন্যটা খোলা যাবে না। টাইপ না চিনলে fail-closed (কোর্স ধরে নেওয়া হয় না)।
    if ($script === 'course-media.php') {
        $t = (string) ($_GET['type'] ?? ($_POST['owner_type'] ?? ''));
        if ($t === '' && (isset($_GET['batch_id']) || isset($_POST['batch_id']))) {
            $t = 'course';   // পুরনো `?batch_id=X` লিংক
        }
        $map = ['course' => 'content:courses', 'worksheet' => 'content:worksheets', 'product' => 'content:products'];
        return isset($map[$t]) && admin_can($map[$t], $cap);
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
