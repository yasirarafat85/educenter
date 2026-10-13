<?php
// একটা নির্দিষ্ট কোর্সের (parent, courses টেবিল) সব ব্যাচ (child, course_batches টেবিল) পরিচালনা —
// admin/manage.php এর জেনেরিক CRUD ইঞ্জিনের বাইরে একটা কাস্টম পেজ (course-data.php/courier.php এর
// প্যাটার্নে), কারণ এখানে parent এর ভেতরে child এর নেস্টেড লিস্ট+ফর্ম দরকার যেটা জেনেরিক ইঞ্জিন সাপোর্ট করে না।
// দাম/ইনস্ট্রাক্টর/ছবি/বিবরণ/ফিচার/hide_parcel/registration_open/is_active — সবগুলোই ব্যাচ-ভিত্তিক
// (কোর্স-ভিত্তিক না), তাই প্রতিটা ব্যাচের নিজস্ব সম্পূর্ণ ফর্ম থাকে।

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/form-helpers.php';
require_once __DIR__ . '/includes/archive.php';
require_once __DIR__ . '/includes/payments.php';   // 💰 টাকার ছকের প্রিভিউ (pay_plan_preview_html)
require_once __DIR__ . '/../includes/upload.php';
admin_require_login();

$db = get_db();
$action = $_GET['action'] ?? 'list';

$courseId = (int) ($_GET['course_id'] ?? ($_POST['course_id'] ?? 0));
$courseStmt = $db->prepare('SELECT * FROM courses WHERE id = :id');
$courseStmt->execute(['id' => $courseId]);
$course = $courseStmt->fetch();
if (!$course) {
    set_flash('error', 'কোর্স পাওয়া যায়নি।');
    redirect('manage.php?entity=courses');
}

$pageTitle = 'ব্যাচ পরিচালনা — ' . $course['title'];

// ফিল্ডগুলো দেখানোর গ্রুপ — 🔑 ২০২৬-১০-১৩ (ইউজার: "সব করা আছে কিন্তু বেশি কমপ্লেক্স")।
// আগে ২৫টা ঘর একটাই লম্বা তালিকায় ছিল, টাকার ঘরগুলো মাঝে ছড়ানো — কোন ঘরটা খাতার হিসাবে
// যায় আর কোনটা শুধু সাইটে দেখানোর লেখা, সেটা বোঝার উপায়ই ছিল না।
// 🔴 গ্রুপিং নিছক **দেখানোর** ব্যবস্থা — সেভ হ্যান্ডলার আগের মতোই $batchFields-এর উপর লুপ
//    করে, তাই ক্রম বদলালে বা নতুন গ্রুপ যোগ করলে সেভে কিছু বদলায় না।
$batchGroups = [
    'main'   => ['title' => '📋 মূল তথ্য', 'hint' => ''],
    'money'  => ['title' => '💰 টাকার ছক', 'hint' => '🔑 অর্ডারের টাকার খাতা (কিস্তিগুলো) এই ঘরগুলো দেখেই তৈরি হয় — আর কুরিয়ারের মাসিক কালেকশনের অঙ্কও এখান থেকেই আসে। নিচে সাথে সাথেই দেখে নিতে পারবেন কোন ঘর থেকে কোন মানটা যাচ্ছে।'],
    'show'   => ['title' => '👁️ শুধু দেখানোর জন্য', 'hint' => 'এই লেখাগুলো সাইটে দেখায় — খাতার হিসাবে যোগ হয় না।'],
    'group'  => ['title' => '🔗 প্রাইভেট গ্রুপের লিংক', 'hint' => 'কোর্স কেনা অভিভাবক নিজের অ্যাকাউন্টে এই লিংকগুলো দেখবেন (ঐচ্ছিক)।'],
    'switch' => ['title' => '⚙️ সুইচ, সময় ও ক্রম', 'hint' => ''],
];

// ব্যাচের ফর্ম ফিল্ড কনফিগ — admin/includes/entities.php এর মতোই গঠন, render_field() (form-helpers.php)
// দিয়ে রেন্ডার হয়, শুধু course-batches.php নিজেই save/load হ্যান্ডেল করে (জেনেরিক manage.php ইঞ্জিন ছাড়া)
$batchFields = [
    // ── 📋 মূল তথ্য ──────────────────────────────────────────────
    'batch_name' => ['group' => 'main', 'label' => 'ব্যাচের নাম (যেমন: ৫ম ব্যাচ, July_26 — আগে ব্যবহৃত নাম থেকে বেছে নিতে পারবেন বা নতুন লিখতে পারবেন)', 'type' => 'text', 'required' => true],
    'image' => ['group' => 'main', 'label' => 'ছবি', 'type' => 'image'],
    'instructor' => ['group' => 'main', 'label' => 'প্রশিক্ষক', 'type' => 'text'],
    'description' => ['group' => 'main', 'label' => 'বিবরণ', 'type' => 'textarea'],
    'card_excerpt' => ['group' => 'main', 'label' => 'কার্ডে যা দেখাবে (ঐচ্ছিক)', 'type' => 'textarea', 'help' => 'কোর্স/ওয়ার্কশিট তালিকার কার্ডে নামের নিচে এই লেখাটাই দেখাবে — এক-দুই লাইনে সারকথা লিখুন। খালি রাখলে আগের মতোই "বিবরণ"-এর শুরুর অংশ নিজে থেকে দেখাবে (পূর্ণ বাক্য/শব্দ পর্যন্ত)। বিবরণ যদি সালাম বা ভূমিকা দিয়ে শুরু হয়, তাহলে এই ঘরটা ভরে দিলে কার্ডে সুন্দর দেখাবে।'],
    'features' => ['group' => 'main', 'label' => 'বৈশিষ্ট্য (প্রতি লাইনে একটি)', 'type' => 'lines'],

    // ── 💰 টাকার ছক (খাতা এখান থেকেই কিস্তি বানায়) ───────────────
    'price' => ['group' => 'money', 'label' => 'মাসিক বেতন / মূল ফি', 'type' => 'text', 'help' => '🔑 খাতায় প্রতি মাসের বেতনের প্রাপ্য এখান থেকেই বসে। "৳২৯০ মাসিক" জাতীয় লেখা চলবে — সংখ্যাটা নিজে থেকে বের করে নেওয়া হয়। এক-কালীন কোর্সে এখানেই পুরো ফি দিন।'],
    'registration_fee' => ['group' => 'money', 'label' => 'রেজিস্ট্রেশন ফি — টাকার অঙ্কে (খাতার জন্য)', 'type' => 'number', 'default' => 0, 'help' => '🔑 খাতায় "রেজিস্ট্রেশন ফি" কিস্তির প্রাপ্য এখান থেকেই বসবে। শুধু সংখ্যা লিখুন (যেমন 500)। ০ রাখলে নিচের "দ্বিতীয় ফি\'র পরিমাণ" লেখা থেকে বুঝে নেওয়ার চেষ্টা করবে — তাই নিশ্চিত থাকতে এখানেই সংখ্যাটা লিখুন।'],
    'secondary_fee_label' => ['group' => 'money', 'label' => 'রেজিস্ট্রেশন ফি-র নাম', 'type' => 'text', 'suggest' => true, 'help' => 'যেমন: উপকরণ ফি / রেজিস্ট্রেশন ফি। 🔑 খাতায় ঐ কিস্তির নামও এখান থেকেই বসে (খালি রাখলে "রেজিস্ট্রেশন ফি")। সাইটের কার্ডে দ্বিতীয় ফি-র লাইনের নামও এটাই।'],
    'course_months' => ['group' => 'money', 'label' => 'কোর্স কয় মাসের (খাতার জন্য)', 'type' => 'number', 'default' => 0, 'help' => '🔑 খাতায় এতগুলো মাসের বেতন ধরা হবে (যেমন 3 দিলে ৩ মাসের)। ০ রাখলে নিচের "মোট কয়বার পার্সেল যাবে" বা "মেয়াদ" লেখা থেকে বুঝে নেবে।'],
    'total_parcels' => ['group' => 'money', 'label' => 'মোট কয়বার পার্সেল যাবে', 'type' => 'number', 'default' => 0, 'help' => '🔑 দুটো কাজ করে: কোর্স পার্সেল পেজে প্রতি শিক্ষার্থীর এই কয়টা মাস-স্লট দেখাবে (যেমন ৬), আর খাতার কিস্তিগুলো কোন পার্সেলে তোলা হবে সেটাও এটা দিয়েই ঠিক হয়। ০ দিলে পার্সেল পেজ থেকেও সেট করা যায়।'],
    'reg_installments' => ['group' => 'money', 'label' => 'রেজিস্ট্রেশন ফি কয় কিস্তিতে নেওয়া হয়', 'type' => 'number', 'default' => 0, 'help' => 'যেমন 3 দিলে খাতায় রেজিস্ট্রেশন ফি তিন ভাগে বসবে (কিস্তি 1/3, 2/3, 3/3) — টাকা সমান ভাগ হবে আর ১ম/২য়/৩য় পার্সেলে তোলা হবে; দরকারে প্রতিটার প্রাপ্য হাতে বদলানো যাবে। খালি/০ = একবারেই নেওয়া হয়।'],
    'tuition_installments' => ['group' => 'money', 'label' => 'বেতন কয় কিস্তিতে নেওয়া হয়', 'type' => 'number', 'default' => 0, 'help' => 'খালি/০ = প্রতি মাসে একবার। 2 দিলে মোট বেতন দুই কিস্তিতে বসবে (৩ মাস × ৬৯০ = ২০৭০ → ১০৩৫ + ১০৩৫)।'],
    'tuition_split_mode' => ['group' => 'money', 'label' => 'বেতনের কিস্তি কীভাবে ভাগ হবে', 'type' => 'select', 'options' => ['' => 'টাকা সমান ভাগ (ডিফল্ট)', 'months' => 'মাস ধরে ভাগ (আগের কিস্তিতে বেশি মাস)'], 'help' => '🔑 ডিফল্ট "টাকা সমান ভাগ" = মোট বেতন ÷ কিস্তি-সংখ্যা, প্রতিবার একই টাকা (৩ মাসের কোর্স ২ কিস্তিতে → ১০৩৫ + ১০৩৫, অর্থাৎ দেড় মাস করে)। "মাস ধরে ভাগ" নিলে পূর্ণ মাস ধরে বসে (১৩৮০ + ৬৯০)। মাস যখন কিস্তি দিয়ে সমানভাবে ভাগ যায় (৪ মাস ২ কিস্তিতে) তখন দুটোর ফল একই।'],
    'fee_mode' => ['group' => 'money', 'label' => 'ফি\'র গঠন', 'type' => 'select', 'options' => ['' => '— নিজে থেকে বুঝে নিক —', 'reg_monthly' => 'রেজিস্ট্রেশন ফি + মাসিক বেতন', 'monthly' => 'শুধু মাসিক বেতন', 'onetime' => 'এক-কালীন (পুরো ফি একবারে)'], 'help' => 'খাতার কিস্তিগুলো এই গঠন অনুযায়ী তৈরি হয়। খালি রাখলে উপরের ফি/রেজিস্ট্রেশন ফি/পার্সেল-সংখ্যা দেখে সিস্টেম নিজেই বুঝে নেবে — নিচের ছকে কী বোঝা হয়েছে দেখতে পাবেন। অনুমানটা ভুল হলে এখানে নিজে বেছে দিন।'],

    // ── 👁️ শুধু দেখানোর জন্য ─────────────────────────────────────
    'old_price'   => ['group' => 'show', 'label' => 'আগের দাম (ঐচ্ছিক — ডিসকাউন্ট দেখাতে)', 'type' => 'text', 'help' => 'ডিসকাউন্ট দেখাতে চাইলে এখানে আগের (বেশি) দাম দিন — সাইটে এটা কাটা দাগ দিয়ে দেখাবে ও কত% ছাড় হিসাব করে দেখাবে। খালি রাখলে ডিসকাউন্ট দেখাবে না। (খাতার হিসাবে যায় না।)'],
    'secondary_fee' => ['group' => 'show', 'label' => 'দ্বিতীয় ফি\'র পরিমাণ (সাইটে যা লেখা থাকবে)', 'type' => 'text', 'help' => 'যেমন: ৳৩৫০। উপরের নামসহ কার্ড ও রেজিস্ট্রেশন পেজে আলাদা লাইনে দেখাবে। ⚠️ এটা নিছক **লেখা** — খাতা উপরের "রেজিস্ট্রেশন ফি — টাকার অঙ্কে" ঘরটা পড়ে; সেটা ০ হলে শেষ উপায় হিসেবে এই লেখা থেকে সংখ্যা বের করার চেষ্টা করে।'],
    'duration' => ['group' => 'show', 'label' => 'মেয়াদ (যেমন ৩ মাস)', 'type' => 'text', 'help' => 'সাইটে দেখানোর লেখা। ⚠️ খাতা উপরের "কোর্স কয় মাসের" সংখ্যাটা পড়ে — সেটা ০ হলে শেষ উপায় হিসেবে এই লেখা থেকে মাসের সংখ্যা বের করার চেষ্টা করে।'],
    'payment_schedule' => ['group' => 'show', 'label' => 'পেমেন্ট সময়সূচি (কখন কত টাকা)', 'type' => 'textarea', 'help' => 'প্রতি লাইনে একটা করে লিখুন — যেমন: "রেজিস্ট্রেশনের সময়: ৳৩৫০ (উপকরণ)" / "১ম মাস: ৳২৯০" / "২য় মাস: ৳২৯০"। রেজিস্ট্রেশন সফল কার্ডে (ডাউনলোডযোগ্য) দেখাবে। খালি রাখলে দেখাবে না। (শুধু দেখানোর জন্য — খাতার হিসাবে যায় না।)'],

    // ── 🔗 গ্রুপের লিংক ──────────────────────────────────────────
    'fb_group_url' => ['group' => 'group', 'label' => 'প্রাইভেট Facebook গ্রুপ লিংক', 'type' => 'text'],
    'messenger_group_url' => ['group' => 'group', 'label' => 'প্রাইভেট Messenger গ্রুপ লিংক', 'type' => 'text'],

    // ── ⚙️ সুইচ, সময় ও ক্রম ─────────────────────────────────────
    'hide_parcel' => ['group' => 'switch', 'label' => 'পার্সেল হাইড (Yes হলে রেজিস্ট্রেশন ফর্মে রিসিভার নাম/নম্বর/ঠিকানা হাইড থাকবে — ফুল অনলাইন ব্যাচের জন্য)', 'type' => 'checkbox', 'default' => 0, 'warn_off' => true, 'toggle_label' => 'পার্সেল হাইড'],
    'registration_open' => ['group' => 'switch', 'label' => 'রেজিস্ট্রেশন খোলা (Running)? — বন্ধ (No) করলে ব্যাচ সাইটে দেখাবে কিন্তু নতুন রেজিস্ট্রেশন নেওয়া যাবে না', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'রেজিস্ট্রেশন খোলা রাখা'],
    'registration_deadline' => ['group' => 'switch', 'label' => 'ভর্তির শেষ সময় (ঐচ্ছিক — কাউন্টডাউন ঘড়ি)', 'type' => 'datetime', 'help' => 'এখানে তারিখ ও সময় দিলে সাইটে "⏳ ভর্তির সময় বাকি — 3 দিন 14 ঘণ্টা" ঘড়ি দেখাবে (কার্ড, বিস্তারিত পাতা ও ভর্তি ফর্ম — তিন জায়গাতেই), আর শেষ ২৪ ঘণ্টায় লাল হয়ে যাবে। সময় শেষ হলে এই ব্যাচের রেজিস্ট্রেশন নিজে থেকেই বন্ধ হয়ে যাবে — কোর্সটা "আসছে শীঘ্রই" তালিকায় চলে যাবে আর বোতাম হয়ে যাবে "জানিয়ে রাখুন"। খালি রাখলে কোনো ঘড়ি দেখাবে না। সময় বাংলাদেশ সময় ধরে হিসাব হয়। উপরের সুইচ দিয়ে হাতে বন্ধ করলে সেটাই চূড়ান্ত।'],
    'is_active' => ['group' => 'switch', 'label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'ব্যাচটি সাইটে দেখানো'],
    'sort_order' => ['group' => 'switch', 'label' => 'ক্রম নম্বর (এই কোর্সের ব্যাচগুলোর মধ্যে, ছোট সংখ্যা আগে দেখাবে, অটো বসে)', 'type' => 'number', 'default' => 0],
];

// মাইগ্রেশন (migrate-payment-split.sql) এখনো চালানো না থাকলে নতুন ঘরটা ফর্ম থেকেই বাদ যায় —
// নাহলে INSERT/UPDATE-এ "Unknown column" এসে ব্যাচ সেভ করাই ভেঙে যেত।
foreach (['tuition_split_mode' => 'migrate-payment-split.sql',
          'card_excerpt'      => 'migrate-card-excerpt.sql',
          'old_price'         => 'migrate-course-old-price.sql',
          'registration_deadline' => 'migrate-course-registration-deadline.sql'] as $bfCol => $bfMigration) {
    if (!db_has_column($db, 'course_batches', $bfCol)) {
        unset($batchFields[$bfCol]);
    }
}

// গ্রুপ অনুযায়ী ফিল্ড সাজানো (উপরের unset-গুলোর **পরে**, নাহলে না-থাকা কলামও গ্রুপে ঢুকত)
$groupedFields = array_fill_keys(array_keys($batchGroups), []);
foreach ($batchFields as $bfKey => $bfConf) {
    $bfGroup = $bfConf['group'] ?? 'main';
    if (!isset($groupedFields[$bfGroup])) {
        $bfGroup = 'main';   // অচেনা গ্রুপ = মূল তথ্যে (ফিল্ড কখনো হারাবে না)
    }
    $groupedFields[$bfGroup][$bfKey] = $bfConf;
}

// ------------------------------------------------------------
// SAVE (Add অথবা Edit ব্যাচ) — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
        redirect('course-batches.php?course_id=' . $courseId);
    }

    $batchId = !empty($_POST['id']) ? (int) $_POST['id'] : null;
    $batchNameVal = trim($_POST['batch_name'] ?? '');

    if ($batchNameVal === '') {
        set_flash('error', 'ব্যাচের নাম দিন।');
        redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
    }

    // এই কোর্সে (course_id) একই ব্যাচ-নাম দুইবার হবে না — অন্য কোর্সে একই নাম সমস্যা না
    $dupStmt = $db->prepare('SELECT COUNT(*) c FROM course_batches WHERE course_id = :cid AND batch_name = :bn AND id != :id');
    $dupStmt->execute(['cid' => $courseId, 'bn' => $batchNameVal, 'id' => $batchId ?? 0]);
    if ((int) $dupStmt->fetch()['c'] > 0) {
        set_flash('error', 'এই কোর্সে এই নামে আগে থেকেই একটা ব্যাচ আছে। ভিন্ন নাম দিন।');
        redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
    }

    $columns = ['course_id' => $courseId];

    foreach ($batchFields as $key => $f) {
        if ($f['type'] === 'lines') {
            continue; // features আলাদাভাবে নিচে হ্যান্ডেল হয়
        }
        if ($f['type'] === 'checkbox') {
            $columns[$key] = isset($_POST[$key]) ? 1 : 0;
            continue;
        }
        if ($f['type'] === 'image') {
            $uploaded = null;
            try {
                $uploaded = handle_image_upload($key . '_file', 'courses');
            } catch (RuntimeException $e) {
                set_flash('error', $e->getMessage());
                redirect('course-batches.php?course_id=' . $courseId . '&action=form' . ($batchId ? '&id=' . $batchId : ''));
            }
            $columns[$key] = $uploaded ?? trim($_POST[$key] ?? '');
            continue;
        }
        if ($f['type'] === 'datetime') {
            // 🔴 "Y-m-dTH:i" → "Y-m-d H:i:s", খালি হলে NULL (কাঁচা POST সরাসরি DATETIME-এ দেবেন না)
            $columns[$key] = course_deadline_sql($_POST[$key] ?? '');
            continue;
        }
        $val = trim($_POST[$key] ?? '');
        if ($val === '' && isset($f['default'])) {
            $val = $f['default'];
        }
        $columns[$key] = $val;
    }

    // slug — কোর্সের টাইটেল + ব্যাচের নাম থেকে অটো, গ্লোবালি ইউনিক (একই কোর্স-টাইটেলের ভিন্ন ব্যাচের
    // জন্য একই স্লাগ তৈরি হতে পারে বলে কনফ্লিক্ট হলে -2, -3 ... যোগ হয়)
    $slugBase = make_slug($course['title'] . '-' . $batchNameVal, $batchId ?? 0);
    $columns['slug'] = make_unique_value($db, 'course_batches', 'slug', $slugBase, $batchId);

    // sort_order — নতুন ব্যাচে খালি রাখলে এই কোর্সের মধ্যে অটো পরবর্তী নম্বর বসে (গ্লোবাল না, course-স্কোপড)
    if (!$batchId && (int) ($columns['sort_order'] ?? 0) === 0) {
        $next = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_val FROM course_batches WHERE course_id = :cid');
        $next->execute(['cid' => $courseId]);
        $columns['sort_order'] = (int) $next->fetch()['next_val'];
    }

    if ($batchId) {
        $set = implode(', ', array_map(fn($k) => "`$k` = :$k", array_keys($columns)));
        $stmt = $db->prepare("UPDATE course_batches SET $set WHERE id = :id");
        $columns['id'] = $batchId;
        $stmt->execute($columns);
    } else {
        $cols = array_keys($columns);
        $placeholders = array_map(fn($k) => ":$k", $cols);
        $stmt = $db->prepare('INSERT INTO course_batches (`' . implode('`, `', $cols) . '`) VALUES (' . implode(', ', $placeholders) . ')');
        $stmt->execute($columns);
        $batchId = (int) $db->lastInsertId();
    }

    // ফিচার (lines) — পুরনো সব মুছে নতুন করে ইনসার্ট
    $db->prepare('DELETE FROM course_features WHERE batch_id = :id')->execute(['id' => $batchId]);
    $featureLines = array_filter(array_map('trim', explode("\n", $_POST['features'] ?? '')));
    $order = 1;
    foreach ($featureLines as $line) {
        $db->prepare('INSERT INTO course_features (batch_id, feature_text, sort_order) VALUES (:fk, :val, :ord)')
           ->execute(['fk' => $batchId, 'val' => $line, 'ord' => $order++]);
    }

    set_flash('success', 'ব্যাচ সফলভাবে সেভ হয়েছে।');
    redirect('course-batches.php?course_id=' . $courseId);
}

// ------------------------------------------------------------
// DELETE — POST
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('course-batches.php?course_id=' . $courseId);
    }
    $batchId = (int) ($_POST['id'] ?? 0);

    // ডিলিটের আগে আর্কাইভে (ব্যাচ + তার ফিচার) — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।
    // ছবির ফাইল মোছা হয় না (রিস্টোরে দরকার হবে)।
    archive_entity($db, 'course_batches', $batchId);
    $db->prepare('DELETE FROM course_batches WHERE id = :id')->execute(['id' => $batchId]);
    set_flash('success', 'ব্যাচ আর্কাইভে সরানো হয়েছে — আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।');
    redirect('course-batches.php?course_id=' . $courseId);
}

// ------------------------------------------------------------
// 🔑 বিশেষ (গোপন) রেজিস্ট্রেশন লিংক — তৈরি / নতুন করা / বন্ধ করা
//
// সাইটে ভর্তি বন্ধ রেখেও দু-একজনকে আলাদা করে রেজিস্ট্রেশন করানোর জন্য
// (ইউজারের চাওয়া, ২০২৬-০৯-৩০)। চাবি `settings`-এ, তাই মাইগ্রেশন লাগে না।
// 🔴 অ্যাকশন-মার্কার `reg-key` ইচ্ছাকৃতভাবে `admin_delete_actions()`-এ নেই —
//    কোনো কনটেন্ট মোছা হচ্ছে না, তাই `content:courses`-এ **edit** cap-ই যথেষ্ট।
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'reg-key') {
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('course-batches.php?course_id=' . $courseId);
    }
    $batchId = (int) ($_POST['id'] ?? 0);
    $mode    = ($_POST['mode'] ?? '') === 'off' ? 'off' : 'new';

    // 🔴 ব্যাচটা সত্যিই এই কোর্সের কিনা DB থেকে যাচাই (POST-এর id-তে ভরসা নয়)
    $chk = $db->prepare('SELECT batch_name FROM course_batches WHERE id = :id AND course_id = :cid');
    $chk->execute(['id' => $batchId, 'cid' => $courseId]);
    $chkRow = $chk->fetch();
    if (!$chkRow) {
        set_flash('error', 'ব্যাচটি পাওয়া যায়নি।');
        redirect('course-batches.php?course_id=' . $courseId);
    }

    if ($mode === 'off') {
        update_setting(course_reg_key_name($batchId), '');
        set_flash('success', '🔑 বিশেষ লিংক বন্ধ করা হয়েছে — পুরনো লিংক আর কাজ করবে না।');
    } else {
        $had = course_reg_key_get($batchId) !== '';
        update_setting(course_reg_key_name($batchId), course_reg_key_make());
        set_flash('success', $had
            ? '🔑 নতুন লিংক তৈরি হয়েছে — আগের লিংকটা এখন থেকে আর কাজ করবে না।'
            : '🔑 বিশেষ লিংক তৈরি হয়েছে — "কপি" চেপে যাঁকে দরকার তাঁকে পাঠিয়ে দিন।');
    }
    redirect('course-batches.php?course_id=' . $courseId);
}

// ------------------------------------------------------------
// FORM ভিউ (Add / Edit) ডেটা লোড
// ------------------------------------------------------------
$editBatch = null;
$formDefaults = [];
$previewBatch = null;   // 💰 টাকার ছকের প্রিভিউ কোন মানগুলো দিয়ে আঁকা হবে
$previewTyped = false;  // অ্যাডমিনের এইমাত্র টাইপ করা (এখনো সেভ না করা) মান দিয়ে?
if ($action === 'form') {
    $editId = !empty($_GET['id']) ? (int) $_GET['id'] : null;
    if ($editId) {
        $stmt = $db->prepare('SELECT * FROM course_batches WHERE id = :id AND course_id = :cid');
        $stmt->execute(['id' => $editId, 'cid' => $courseId]);
        $editBatch = $stmt->fetch();
        if (!$editBatch) {
            redirect('course-batches.php?course_id=' . $courseId);
        }
        $featStmt = $db->prepare('SELECT feature_text FROM course_features WHERE batch_id = :id ORDER BY sort_order ASC');
        $featStmt->execute(['id' => $editId]);
        $editBatch['features'] = implode("\n", array_column($featStmt->fetchAll(), 'feature_text'));
    }

    // 🔄 "ছক দেখে নিন" — ফর্মটা নিজের কাছেই POST হয় (`preview` নামের বোতাম চাপলে, তাই
    //    সাধারণ সেভে এই শাখা চলে না)। **কিছুই সেভ হয় না** — শুধু টাইপ করা মানগুলো ফর্মে
    //    ফিরিয়ে দিয়ে নিচের ছকটা ঐ মান দিয়ে আঁকা হয়।
    // 🔴 AJAX/JS-এ হিসাব করা হয়নি ইচ্ছাকৃতভাবে — টাকার ভাগ-বাটোয়ারার নিয়ম দুই জায়গায়
    //    থাকলে সময়ের সাথে নীরবে আলাদা হয়ে যেত; এখানে আসল ইঞ্জিনই (pay_plan_from_batch)
    //    চলে, আর ফুল রিলোডই এই কোডবেসের কনভেনশন।
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preview'])) {
        if (!csrf_verify()) {
            set_flash('error', 'ফর্ম টোকেন মিলছে না, আবার চেষ্টা করুন।');
            redirect('course-batches.php?course_id=' . $courseId);
        }
        $typed = $editBatch ?? [];
        foreach ($batchFields as $bfKey => $bfConf) {
            if (($bfConf['type'] ?? '') === 'checkbox') {
                $typed[$bfKey] = isset($_POST[$bfKey]) ? 1 : 0;
            } elseif (isset($_POST[$bfKey])) {
                $typed[$bfKey] = is_string($_POST[$bfKey]) ? trim($_POST[$bfKey]) : $_POST[$bfKey];
            }
        }
        $editBatch    = $typed;   // ফর্মে যা লেখা ছিল তাই থাকবে (ছবির ফাইল-চয়েস ছাড়া)
        $previewTyped = true;
    }

    // প্রিভিউ শুধু কোর্সের জন্য অর্থপূর্ণ, আর নতুন (খালি) ফর্মে দেখানোর কিছু নেই
    if ($editBatch) {
        $previewBatch = $editBatch;
    }
}

// ব্যাচের নাম ইনপুটে datalist সাজেশন — গ্লোবালি সব কোর্স জুড়ে আগে ব্যবহৃত নাম (যেমন "May_26"), কিন্তু
// ইউনিকনেস চেক শুধু এই course_id এর মধ্যেই হয় (উপরে save হ্যান্ডলারে)
$batchNameSuggestions = $db->query(
    "SELECT DISTINCT batch_name FROM course_batches WHERE batch_name IS NOT NULL AND batch_name != '' ORDER BY batch_name ASC"
)->fetchAll(PDO::FETCH_COLUMN);

// ------------------------------------------------------------
// LIST ভিউ ডেটা লোড
// ------------------------------------------------------------
$batches = [];
$registrationCounts = [];
if ($action === 'list') {
    $batches = $db->prepare('SELECT * FROM course_batches WHERE course_id = :cid ORDER BY sort_order ASC, id DESC');
    $batches->execute(['cid' => $courseId]);
    $batches = $batches->fetchAll();

    if ($batches) {
        $batchIds = array_column($batches, 'id');
        $placeholders = implode(',', array_fill(0, count($batchIds), '?'));
        $regStmt = $db->prepare("SELECT item_id, COUNT(*) c FROM registrations WHERE type = 'course' AND item_id IN ({$placeholders}) GROUP BY item_id");
        $regStmt->execute($batchIds);
        $registrationCounts = array_column($regStmt->fetchAll(), 'c', 'item_id');
    }
}

// প্রতি ব্যাচে কয়টা ছবি/ভিডিও আছে — এক কোয়েরিতে (তালিকায় N+1 এড়াতে); টেবিল না থাকলে খালি
$mediaCounts = course_media_counts($db, array_map(fn($b) => (int) $b['id'], $batches), 'course');

require __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-5">
    <a href="manage.php?entity=courses" class="text-gray-500 text-sm">← সব কোর্স</a>
</div>

<?php if ($action === 'list'): ?>
    <div class="flex justify-between items-center mb-5">
        <div>
            <h3 class="text-lg font-bold text-gray-800"><?= e($course['title']) ?></h3>
            <p class="text-gray-500 text-sm">মোট <?= count($batches) ?> টি ব্যাচ · <a href="manage.php?entity=courses&action=form&id=<?= $course['id'] ?>" class="text-indigo-600 font-semibold">কোর্সের নাম সম্পাদনা</a></p>
        </div>
        <a href="course-batches.php?course_id=<?= $courseId ?>&action=form" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl text-sm">+ নতুন ব্যাচ যোগ করুন</a>
    </div>

    <?php if (!$batches): ?>
        <div class="bg-white rounded-2xl shadow p-10 text-center text-gray-400">
            এখনো কোনো ব্যাচ নেই। "+ নতুন ব্যাচ যোগ করুন" বাটনে ক্লিক করে প্রথম ব্যাচ তৈরি করুন — এর আগে এই কোর্সটা সাইটে দেখাবে না বা রেজিস্ট্রেশনযোগ্য হবে না।
        </div>
    <?php else: ?>
    <div class="bg-white rounded-2xl shadow overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">ছবি</th>
                    <th class="py-3 px-4">ব্যাচের নাম</th>
                    <th class="py-3 px-4">মূল্য</th>
                    <th class="py-3 px-4">প্রশিক্ষক</th>
                    <th class="py-3 px-4">মেয়াদ</th>
                    <th class="py-3 px-4">রেজিস্ট্রেশন খোলা?</th>
                    <th class="py-3 px-4">🔑 বিশেষ লিংক</th>
                    <th class="py-3 px-4">পার্সেল হাইড?</th>
                    <th class="py-3 px-4">সাইটে দেখাবে?</th>
                    <th class="py-3 px-4">রেজিস্ট্রেশন</th>
                    <th class="py-3 px-4">অ্যাকশন</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($batches as $b): $regCount = (int) ($registrationCounts[$b['id']] ?? 0); ?>
                <tr class="border-b last:border-0 hover:bg-gray-50">
                    <td class="py-2.5 px-4">
                        <?php if (!empty($b['image'])): ?>
                            <img src="<?= e(admin_image_src($b['image'])) ?>" class="w-12 h-12 object-cover rounded-lg border">
                        <?php else: ?>
                            <div class="w-12 h-12 bg-gray-100 rounded-lg border flex items-center justify-center text-gray-300"><i data-lucide="image" class="w-5 h-5"></i></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 font-semibold"><?= e($b['batch_name']) ?></td>
                    <td class="py-2.5 px-4" style="min-width:170px;max-width:220px;white-space:normal;vertical-align:top">
                        <?= e($b['price'] ?: '-') ?>
                        <?php // 💸 আগের দাম থাকলে পাশে কাটা দাগে — তালিকা থেকেই বোঝা যায় কোন ব্যাচে ছাড় চলছে।
                              //    ⚠️ inline style (Tailwind-এর line-through ক্লাস অ্যাডমিন CSS-এ নেই, রিবিল্ড এড়াতে) ?>
                        <?php if (trim((string) ($b['old_price'] ?? '')) !== ''): ?>
                            <span class="text-xs" style="color:#9ca3af;text-decoration:line-through"><?= e($b['old_price']) ?></span>
                        <?php endif; ?>
                        <?php // 📒 এই ব্যাচের সেটিংসে খাতায় কী বসবে — এক লাইনে (এডিটে না ঢুকেই বোঝা যায়)।
                              //    🔴 নতুন কলাম নয়, এই ঘরেই দ্বিতীয় লাইন — ১১ কলামের টেবিল আর বাড়ানো যায় না।
                              $bPlan = pay_plan_summary_text(pay_plan_from_batch($b)); ?>
                        <?php if ($bPlan !== ''): ?>
                            <div class="text-xs text-gray-500 mt-0.5" style="white-space:normal">📒 <?= e($bPlan) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4"><?= e($b['instructor'] ?: '-') ?></td>
                    <td class="py-2.5 px-4"><?= e($b['duration'] ?: '-') ?></td>
                    <td class="py-2.5 px-4">
                        <?= $b['registration_open'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?>
                        <?php // ⏳ ভর্তির শেষ সময় — সময় পেরিয়ে গেলে সুইচ "হ্যাঁ" থাকলেও সাইটে বন্ধ, তাই এখানেই দেখানো
                              $bDl = course_deadline_ts($b['registration_deadline'] ?? ''); ?>
                        <?php if ($bDl !== null): ?>
                            <?php $bOver = $bDl <= time(); ?>
                            <div class="text-xs mt-0.5 <?= $bOver ? 'text-red-600 font-semibold' : 'text-gray-500' ?>">
                                ⏳ <?= e(course_deadline_display($bDl)) ?><?= $bOver ? ' — সময় শেষ' : '' ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <?php // 🔑 বিশেষ (গোপন) রেজিস্ট্রেশন লিংক — সাইটে ভর্তি বন্ধ রেখেও যাঁকে দরকার শুধু তাঁকে পাঠানোর জন্য
                          $bKey = course_reg_key_get((int) $b['id']);
                          $bUrl = $bKey !== '' ? course_reg_key_url((int) $b['id'], $bKey) : ''; ?>
                    <td class="py-2.5 px-4" style="min-width:210px;max-width:320px;white-space:normal;vertical-align:top;">
                        <?php // 🔴 পুরো ঘরটা একটাই মোড়কে — মোবাইল কার্ড-লেআউটে `td` flex হয়ে যায়,
                              //    খোলা রাখলে বোতাম/লিংক/URL পাশাপাশি তিন কলামে চেপে যেত (স্ক্রিনশটে ধরা) ?>
                        <div class="min-w-0">
                        <?php if ($bKey === ''): ?>
                            <form method="post" action="course-batches.php?action=reg-key" class="inline">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                <input type="hidden" name="mode" value="new">
                                <button type="submit" class="text-indigo-600 font-semibold text-sm">🔑 লিংক তৈরি করুন</button>
                            </form>
                            <div class="text-xs text-gray-400 mt-0.5">ভর্তি বন্ধ থাকলেও এই লিংক দিয়ে ফর্ম খোলে</div>
                        <?php else: ?>
                            <button type="button" class="regkey-copy text-white font-semibold text-xs px-2.5 py-1.5 rounded-lg bg-indigo-600"
                                    data-url="<?= e($bUrl) ?>">🔗 লিংক কপি করুন</button>
                            <div class="text-xs text-gray-400 mt-1" style="word-break:break-all;"><?= e($bUrl) ?></div>
                            <div class="mt-1 flex flex-wrap" style="column-gap:.75rem;row-gap:.25rem;">
                                <form method="post" action="course-batches.php?action=reg-key" class="inline"
                                      onsubmit="return confirmSubmit(this, 'নতুন লিংক তৈরি করলে আগের লিংকটা সাথে সাথে অচল হয়ে যাবে — কাউকে আগেরটা পাঠিয়ে থাকলে সে আর ঢুকতে পারবে না। নতুন লিংক বানাবেন?', 'নতুন লিংক বানাবেন?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                    <input type="hidden" name="mode" value="new">
                                    <button type="submit" class="text-gray-500 text-xs font-semibold">↻ নতুন লিংক</button>
                                </form>
                                <form method="post" action="course-batches.php?action=reg-key" class="inline"
                                      onsubmit="return confirmSubmit(this, 'বিশেষ লিংকটা বন্ধ করে দেবেন? যাঁদের পাঠিয়েছিলেন তাঁরা আর ফর্ম খুলতে পারবেন না। (আগে হয়ে যাওয়া রেজিস্ট্রেশনে কিছু হবে না।)', 'লিংক বন্ধ করবেন?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                                    <input type="hidden" name="course_id" value="<?= $courseId ?>">
                                    <input type="hidden" name="mode" value="off">
                                    <button type="submit" class="text-red-600 text-xs font-semibold">✕ বন্ধ করুন</button>
                                </form>
                            </div>
                        <?php endif; ?>
                        </div>
                    </td>
                    <td class="py-2.5 px-4"><?= $b['hide_parcel'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4"><?= $b['is_active'] ? '<span class="text-green-600 font-semibold">হ্যাঁ</span>' : '<span class="text-gray-400">না</span>' ?></td>
                    <td class="py-2.5 px-4">
                        <?php if ($regCount > 0): ?>
                            <a href="course-data.php?course_id=<?= $b['id'] ?>" class="text-indigo-600 font-semibold"><?= $regCount ?> টি</a>
                        <?php else: ?>
                            <span class="text-gray-300">০</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 space-x-2 whitespace-nowrap">
                        <a href="course-batches.php?course_id=<?= $courseId ?>&action=form&id=<?= $b['id'] ?>" class="text-indigo-600 font-semibold">এডিট</a>
                        <?php // 📸 ছবি ও ভিডিও — আলাদা পেজ (course_media), গণনা $mediaCounts থেকে (N+1 এড়াতে bulk) ?>
                        <a href="course-media.php?type=course&amp;id=<?= $b['id'] ?>" class="text-purple-600 font-semibold">🖼️ ছবি/ভিডিও<?php
                            $mc = $mediaCounts[(int) $b['id']] ?? [];
                            $mcN = (int) ($mc['photos'] ?? 0) + (int) ($mc['videos'] ?? 0);
                            if ($mcN > 0) { echo ' (' . $mcN . ')'; }
                        ?></a>
                        <form method="post" action="course-batches.php?action=delete" class="inline" onsubmit="return confirmSubmit(this, '<?= $regCount > 0 ? "এই ব্যাচে {$regCount} টি রেজিস্ট্রেশন আছে। ব্যাচ আর্কাইভে সরালেও রেজিস্ট্রেশনগুলো থেকে যাবে; পরে আর্কাইভ পেজ থেকে ব্যাচ (দাম/ছবি সহ) ফিরিয়ে আনা যাবে। আর্কাইভে সরাতে চান?" : "এই ব্যাচ আর্কাইভে সরাতে চান? পরে আর্কাইভ পেজ থেকে ফিরিয়ে আনা যাবে।" ?>', 'ব্যাচ আর্কাইভ নিশ্চিতকরণ');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                            <input type="hidden" name="course_id" value="<?= $courseId ?>">
                            <button type="submit" class="text-red-600 font-semibold">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php // 🔗 লিংক কপি — 🔴 নিজের আলাদা ব্লকে (layout-bottom-এর বড় স্ক্রিপ্টের ভেতরে নয়),
          //    ওখানে কিছু ভাঙলে মডাল/সার্চ/টেবিল-কার্ড সব একসাথে মরে। ?>
    <script>
    (function () {
        document.querySelectorAll('.regkey-copy').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-url') || '', old = btn.textContent;
                function done(ok) {
                    btn.textContent = ok ? '✓ কপি হয়েছে' : 'কপি হয়নি — নিচের লেখাটা হাতে কপি করুন';
                    setTimeout(function () { btn.textContent = old; }, 2500);
                }
                // navigator.clipboard শুধু HTTPS-এ চলে — না পারলে লুকানো textarea + execCommand
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(function () { done(true); }, function () { fallback(); });
                } else { fallback(); }
                function fallback() {
                    try {
                        var t = document.createElement('textarea');
                        t.value = url; t.style.position = 'fixed'; t.style.opacity = '0';
                        document.body.appendChild(t); t.select();
                        var ok = document.execCommand('copy');
                        document.body.removeChild(t); done(ok);
                    } catch (e) { done(false); }
                }
            });
        });
    })();
    </script>

<?php elseif ($action === 'form'): ?>
    <div class="bg-white rounded-2xl shadow p-6 max-w-2xl">
        <h3 class="text-lg font-bold text-gray-800 mb-4"><?= !empty($editBatch['id']) ? 'ব্যাচ সম্পাদনা — ' . e($course['title']) : 'নতুন ব্যাচ — ' . e($course['title']) ?></h3>
        <form method="post" action="course-batches.php?action=save" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="course_id" value="<?= $courseId ?>">
            <?php // 🔴 `$editBatch` প্রিভিউ-POST-এ নতুন ব্যাচেও ভরে যায় (টাইপ করা মান), তাই id আছে কিনা দেখা হয় ?>
            <?php if (!empty($editBatch['id'])): ?><input type="hidden" name="id" value="<?= (int) $editBatch['id'] ?>"><?php endif; ?>
            <?php // ঘরগুলো গ্রুপ ধরে — আর 💰 টাকার ছকের ঠিক নিচে প্রিভিউ (গ্রুপ লুপের ভেতরেই,
                  // তাই নতুন গ্রুপ যোগ করলে/ক্রম বদলালে প্রিভিউ আপনাআপনি ঠিক জায়গায় থাকে) ?>
            <?php foreach ($batchGroups as $gKey => $g): if (!$groupedFields[$gKey]) { continue; } ?>
                <div class="pt-3 border-t">
                    <h4 class="font-bold text-gray-800"><?= e($g['title']) ?></h4>
                    <?php if ($g['hint'] !== ''): ?>
                        <p class="text-xs text-gray-500 mt-1 leading-relaxed"><?= e($g['hint']) ?></p>
                    <?php endif; ?>
                </div>
                <?php foreach ($groupedFields[$gKey] as $key => $f):
                    $value = $editBatch[$key] ?? ($formDefaults[$key] ?? ($f['default'] ?? ''));
                    $suggestions = $key === 'batch_name' ? $batchNameSuggestions : [];
                ?>
                    <div><?= render_field($key, $f, $value, $suggestions) ?></div>
                <?php endforeach; ?>

                <?php if ($gKey === 'money'): ?>
                    <?php if ($previewBatch !== null): ?>
                        <?php if ($previewTyped): ?>
                            <div class="text-xs font-semibold" style="color:#92400e">🔄 নিচের ছকটা <u>এইমাত্র টাইপ করা</u> মান দিয়ে — এখনো সেভ হয়নি।</div>
                        <?php endif; ?>
                        <?= pay_plan_preview_html($previewBatch) ?>
                    <?php else: ?>
                        <div class="bg-white rounded-xl border p-4 text-sm text-gray-500">
                            📒 উপরের ঘরগুলো ভরে <b>"🔄 ছক দেখে নিন"</b> চাপলে এখানেই দেখাবে — খাতায় কোন কিস্তি কত টাকার হবে আর কোন পার্সেলে তোলা হবে।
                        </div>
                    <?php endif; ?>
                    <div>
                        <button type="submit" name="preview" value="1"
                                formaction="course-batches.php?action=form&amp;course_id=<?= $courseId ?><?= $editBatch && !empty($editBatch['id']) ? '&amp;id=' . (int) $editBatch['id'] : '' ?>"
                                class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-4 py-2 rounded-xl text-sm">🔄 ছক দেখে নিন (সেভ হবে না)</button>
                        <p class="text-xs text-gray-500 mt-1">টাকার ঘরগুলো বদলে এটা চাপলে নিচের ছক সাথে সাথে নতুন করে আঁকা হবে — সেভ করার আগেই মিলিয়ে নিতে পারবেন। (⚠️ ছবির ফাইল বেছে রাখলে সেটা আবার বেছে নিতে হবে।)</p>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
            <div class="flex gap-3 pt-2">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-6 py-2.5 rounded-xl">সেভ করুন</button>
                <a href="course-batches.php?course_id=<?= $courseId ?>" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-6 py-2.5 rounded-xl">বাতিল</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
