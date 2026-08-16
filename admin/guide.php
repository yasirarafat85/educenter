<?php
// অ্যাডমিন গাইড / সাহায্য — প্রতিটা ফিচার কী ও কখন ব্যবহার করবেন, সহজ বাংলায় (বিল্ট-ইন ম্যানুয়াল)।
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pageTitle = 'গাইড / সাহায্য';

// বিভাগ → [icon, রঙ-ক্লাস(লিটারাল), আইটেম তালিকা]
$guide = [
    'শুরুতেই যা জানা দরকার' => ['rocket', 'bg-indigo-100 text-indigo-600', [
        ['📥 নতুন অর্ডার এলে', 'অভিভাবক ওয়েবসাইট থেকে রেজিস্ট্রেশন করলে তা "রেজিস্ট্রেশন/অর্ডার" পেজে আসে। সেখানে গিয়ে "নিশ্চিত/confirmed" করলে সেটা আয়ের হিসাবে যোগ হয়।', 'registrations.php'],
        ['🚚 কোর্সের পার্সেল পাঠাতে', 'আগে "কোর্স পার্সেল" → কোর্স/ব্যাচ বেছে মাস অনুযায়ী পার্সেল বানান বা সরাসরি পাঠান; একই পেজে গ্রুপে-যোগ ও পার্সেল অগ্রগতিও দেখুন।', 'course-parcel.php'],
        ['💾 নিয়মিত ব্যাকআপ', 'মাঝে মাঝে "ব্যাকআপ ও ডাউনলোড" থেকে ডাটাবেস+ফাইল নামিয়ে রাখুন — সমস্যা হলে বাঁচাবে।', 'backup.php'],
    ]],
    'কনটেন্ট (সাইটে যা দেখায়)' => ['file-text', 'bg-blue-100 text-blue-600', [
        ['কোর্স', 'কোর্স যোগ করুন, তারপর প্রতিটা কোর্সের ভেতরে ব্যাচ (দাম/ছবি/গ্রুপ লিংক/রেজিস্ট্রেশন খোলা-বন্ধ) সেট করুন।', 'manage.php?entity=courses'],
        ['ওয়ার্কশিট / প্রোডাক্ট', 'বিক্রয়যোগ্য ওয়ার্কশিট ও প্রোডাক্ট যোগ/এডিট করুন।', 'manage.php?entity=worksheets'],
        ['শিক্ষক · রিভিউ · নোটিশ · গ্যালারি · FAQ', 'সাইটের এই সেকশনগুলোর কনটেন্ট এখান থেকে ম্যানেজ করুন।', 'manage.php?entity=teachers'],
        ['ফেসবুক পোস্ট', 'হোমপেজে দেখানোর জন্য ফেসবুক পোস্টের লিংক পেস্ট করুন।', 'manage.php?entity=social_posts'],
    ]],
    'অর্ডার ও কাস্টমার' => ['clipboard-list', 'bg-green-100 text-green-600', [
        ['রেজিস্ট্রেশন/অর্ডার', 'সব অর্ডারের তালিকা — স্ট্যাটাস বদলান, এডিট/ডিলিট করুন, ফিল্টার দিয়ে খুঁজুন।', 'registrations.php'],
        ['ডেটা টেবিল', 'কোর্স রেজিস্ট্রেশন কোর্স+ব্যাচ অনুযায়ী গোছানো ভিউ (ডুপ্লিকেট ধরা সহজ)।', 'course-data.php'],
        ['আগ্রহ তালিকা', 'রেজিস্ট্রেশন বন্ধ কোর্সে অভিভাবকরা যে আগ্রহ ("জানিয়ে রাখুন") জমা দেন, তা এখানে। নতুন ব্যাচ খুললে যোগাযোগ করুন।', 'course-interests.php'],
        ['অভিভাবক অ্যাকাউন্ট', 'কোর্স কেনা অভিভাবকরা লগইন করতে অ্যাকাউন্ট বানান — এখানে approve/block/পাসওয়ার্ড-রিসেট করুন।', 'users.php'],
    ]],
    'কুরিয়ার' => ['truck', 'bg-purple-100 text-purple-600', [
        ['কোর্স পার্সেল', 'এক পেজে: গ্রুপে-যোগ টিক, সক্রিয়/নিষ্ক্রিয়, পার্সেল অগ্রগতি, আর মাস বেছে প্রতি শিক্ষার্থীর কালেকশন (অটো হিসাব) সহ পার্সেল প্রস্তুত/পাঠানো। "পার্সেল প্রস্তুত" ও "কোর্স ট্র্যাকিং" এখন এখানেই।', 'course-parcel.php'],
        ['কুরিয়ার', 'প্রস্তুত পার্সেল দেখুন ও কুরিয়ারে পাঠান (Pathao/Steadfast)। ফিল্টার আছে।', 'courier.php'],
        ['কুরিয়ার ট্র্যাকিং', 'কোন শিক্ষার্থীর কোন মাসের পার্সেল গেছে/যায়নি — টাকা সহ ছক আকারে।', 'courier-tracking.php'],
    ]],
    'আয়-ব্যয়' => ['pie-chart', 'bg-emerald-100 text-emerald-600', [
        ['ড্যাশবোর্ড (আয়-ব্যয়)', 'মোট আয়, ব্যয়, লাভের সারাংশ ও চার্ট।', 'finance.php'],
        ['আয়', 'অর্ডার নিশ্চিত হলে আয় অটো যোগ হয়; এখানে দেখা/ম্যানেজ করা যায়।', 'income.php'],
        ['খরচ', 'ব্যবসার খরচ যোগ করুন (বিভাগ অনুযায়ী)।', 'expenses.php'],
    ]],
    'সেটিংস ও নিরাপত্তা' => ['settings', 'bg-amber-100 text-amber-600', [
        ['সাইট সেটিংস', 'সাইটের রঙ/ফন্ট, যোগাযোগ তথ্য, About পেজ, Google লগইন ক্রেডেনশিয়াল ইত্যাদি।', 'settings.php'],
        ['পেমেন্ট মেথড', 'থ্যাংক-ইউ পেজে দেখানোর বিকাশ/নগদ/WhatsApp নাম্বার সেট করুন।', 'payment-methods.php'],
        ['ব্যাকআপ ও ডাউনলোড', 'ডাটাবেস ও ফাইলের ব্যাকআপ নিন/নামান।', 'backup.php'],
        ['আর্কাইভ (রিস্টোর)', 'ভুলে ডিলিট করা জিনিস এখান থেকে ফিরিয়ে আনুন।', 'archive.php'],
        ['পাসওয়ার্ড পরিবর্তন', 'অ্যাডমিন পাসওয়ার্ড বদলান।', 'change-password.php'],
    ]],
    'লগ (রেকর্ড)' => ['history', 'bg-pink-100 text-pink-600', [
        ['ভিজিটর লগ', 'সাইটে কে কবে এসেছে।', 'visitor-logs.php'],
        ['ডাউনলোড লগ', 'কনফার্মেশন কার্ড কে ডাউনলোড করেছে।', 'download-logs.php'],
        ['কুরিয়ার শিপমেন্ট লগ', 'সব কুরিয়ার পাঠানোর ইতিহাস।', 'courier-shipment-logs.php'],
    ]],
];

require __DIR__ . '/includes/layout-top.php';
?>
<div class="bg-white rounded-2xl shadow p-5 sm:p-6 mb-6">
    <h1 class="text-xl sm:text-2xl font-black text-gray-800 mb-1 flex items-center gap-2"><i data-lucide="help-circle" class="w-6 h-6 text-fuchsia-600"></i> গাইড / সাহায্য</h1>
    <p class="text-gray-500 text-sm">কোন পেজ কী কাজ করে, সহজ বাংলায়। কার্ডে ক্লিক করলে সরাসরি সেই পেজে যাবেন।</p>
</div>

<?php foreach ($guide as $section => [$icon, $cls, $items]): ?>
<div class="mb-6">
    <h2 class="text-sm font-bold text-gray-500 mb-3 flex items-center gap-2">
        <span class="inline-flex w-7 h-7 items-center justify-center rounded-lg <?= $cls ?>"><i data-lucide="<?= e($icon) ?>" class="w-4 h-4"></i></span>
        <?= e($section) ?>
    </h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <?php foreach ($items as $it): ?>
        <a href="<?= e($it[2]) ?>" class="block bg-white rounded-2xl shadow p-4 hover:shadow-lg transition-all">
            <p class="font-bold text-gray-800 text-sm mb-1"><?= e($it[0]) ?></p>
            <p class="text-gray-500 text-xs leading-relaxed"><?= e($it[1]) ?></p>
            <span class="inline-flex items-center gap-1 text-indigo-600 text-xs font-semibold mt-2">খুলুন <i data-lucide="arrow-right" class="w-3.5 h-3.5"></i></span>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
