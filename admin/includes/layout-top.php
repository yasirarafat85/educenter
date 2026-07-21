<?php
// admin panel এর কমন হেডার/সাইডবার — প্রতিটা admin পেজের শুরুতে include হয়
// এই ফাইল ব্যবহার করার আগে admin_require_login() কল করা থাকতে হবে

require_once __DIR__ . '/entities.php'; // সাইডবার নেভিগেশনের জন্য get_entities() প্রয়োজন

$currentFile = basename($_SERVER['SCRIPT_NAME']);
$currentEntity = $_GET['entity'] ?? '';

function nav_active(string $file, string $currentFile, string $entity = '', string $currentEntity = ''): string
{
    if ($entity !== '') {
        return ($currentFile === $file && $currentEntity === $entity) ? 'active' : '';
    }
    return $currentFile === $file ? 'active' : '';
}
?>
<!DOCTYPE html>
<html lang="bn" data-theme="indigo">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? e($pageTitle) . ' - ' : '' ?>Admin Panel</title>
<script>
    // সেভ করা থিম রেন্ডারের আগেই বসিয়ে দেওয়া হয় যাতে পেজ লোডে ঝলক (flash) না হয়
    (function () { try { document.documentElement.setAttribute('data-theme', localStorage.getItem('admin_theme') || 'indigo'); } catch (e) {} })();
</script>
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Hind+Siliguri:wght@400;500;600;700&family=Anek+Bangla:wght@600;700;800&family=Plus+Jakarta+Sans:wght@600;700;800&display=swap" rel="stylesheet">
<!-- সেল্ফ-হোস্টেড কম্পাইলড Tailwind (indigo/gray → CSS ভ্যারিয়েবল ম্যাপিং কম্পাইলের সময়ই বেক করা,
     আগের ইনলাইন tailwind.config আর CDN JIT স্ক্রিপ্ট বাদ — এখন স্ট্যাটিক CSS, দ্রুত ও ঝলকমুক্ত) -->
<link rel="stylesheet" href="assets/tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/tailwind.css') ?: '1' ?>">
<script src="../assets/js/lucide.js?v=<?= @filemtime(__DIR__ . '/../../assets/js/lucide.js') ?: '1' ?>"></script>
<style>
    /* ── থিম টোকেন — প্রতিটা থিম মাত্র কয়েকটা রঙ বদলায় (RGB চ্যানেল, Tailwind opacity ইউটিলিটির জন্য)।
         --c-bg স্পষ্টভাবে tinted (সাদা না), --c-bg-2 দিয়ে ব্যাকগ্রাউন্ডে নরম গ্রেডিয়েন্ট, glow আভা ── */
    [data-theme="indigo"]  { --c-bg:226 231 245; --c-bg-2:234 233 250; --c-surface:255 255 255; --c-surface-2:238 241 250; --c-border:214 221 239; --c-text:26 32 54;  --c-text-muted:88 100 136; --c-primary:79 70 229;  --c-primary-2:124 107 245; --glow:rgba(79,70,229,.20); }
    [data-theme="emerald"] { --c-bg:222 237 230; --c-bg-2:225 240 231; --c-surface:255 255 255; --c-surface-2:236 245 240; --c-border:206 226 216; --c-text:18 36 27;  --c-text-muted:84 112 97;  --c-primary:5 150 105;  --c-primary-2:16 185 129;  --glow:rgba(5,150,105,.18); }
    [data-theme="amber"]   { --c-bg:244 231 216; --c-bg-2:247 233 214; --c-surface:255 253 251; --c-surface-2:246 236 223; --c-border:228 212 191; --c-text:43 31 20;  --c-text-muted:120 98 74;  --c-primary:194 65 12;  --c-primary-2:234 88 12;   --glow:rgba(234,88,12,.18); }
    [data-theme="plum"]    { --c-bg:239 227 238; --c-bg-2:243 228 244; --c-surface:255 255 255; --c-surface-2:246 237 244; --c-border:227 208 223; --c-text:38 22 42;  --c-text-muted:118 90 124; --c-primary:147 51 234; --c-primary-2:192 38 211;  --glow:rgba(147,51,234,.18); }
    [data-theme="kids"]    { --c-bg:255 238 213; --c-bg-2:255 234 210; --c-surface:255 255 255; --c-surface-2:255 240 219; --c-border:250 219 178; --c-text:51 35 15;  --c-text-muted:134 103 65; --c-primary:249 115 22; --c-primary-2:251 146 60;  --glow:rgba(249,115,22,.22); }
    /* ── ডার্ক থিম (beta) — bg-white কার্ডগুলো নিচের override দিয়ে surface-এ বদলায় ── */
    [data-theme="midnight"] { --c-bg:12 16 36;  --c-bg-2:16 20 44; --c-surface:22 27 56;  --c-surface-2:30 37 71; --c-border:44 53 96;  --c-text:230 234 250; --c-text-muted:142 154 200; --c-primary:129 140 248; --c-primary-2:167 139 250; --glow:rgba(129,140,248,.20); }
    [data-theme="teal"]     { --c-bg:6 24 27;   --c-bg-2:9 30 34;  --c-surface:13 42 46;  --c-surface-2:17 52 56; --c-border:29 74 80;  --c-text:223 245 243; --c-text-muted:127 175 173; --c-primary:45 212 191;  --c-primary-2:94 234 212;  --glow:rgba(45,212,191,.18); }
    [data-theme="carbon"]   { --c-bg:22 18 14;  --c-bg-2:28 23 18; --c-surface:34 28 21;  --c-surface-2:44 36 27; --c-border:69 56 40;  --c-text:242 233 220; --c-text-muted:169 150 126; --c-primary:245 158 11;  --c-primary-2:251 191 36;  --glow:rgba(245,158,11,.18); }

    :root {
        --font-body: 'Inter', 'Hind Siliguri', system-ui, sans-serif;
        --font-head: 'Plus Jakarta Sans', 'Anek Bangla', 'Hind Siliguri', sans-serif;
    }
    body {
        font-family: var(--font-body);
        line-height: 1.75;                 /* বাংলার জন্য দরকার (মাত্রা/যুক্তাক্ষরের জায়গা) */
        /* স্পষ্ট থিমড ক্যানভাস — সলিড না, নরম গ্রেডিয়েন্ট */
        background: linear-gradient(155deg, rgb(var(--c-bg-2)) 0%, rgb(var(--c-bg)) 55%);
        background-attachment: fixed;
        color: rgb(var(--c-text));
        -webkit-font-smoothing: antialiased;
    }
    /* ফ্ল্যাট ব্যাকগ্রাউন্ড এড়াতে বড় নরম আলোর আভা (৩টা ব্লব) */
    body::before {
        content: ""; position: fixed; inset: 0; z-index: -1; pointer-events: none;
        background:
            radial-gradient(820px circle at 6% -12%, var(--glow), transparent 55%),
            radial-gradient(720px circle at 100% 2%, var(--glow), transparent 52%),
            radial-gradient(680px circle at 55% 118%, var(--glow), transparent 55%);
    }
    h1, h2, h3, h4 { font-family: var(--font-head); line-height: 1.45; }

    /* ── থিমড সাইডবার — সাদা না, থিমের tinted surface-2 প্যানেল + ডান দিকে নরম শ্যাডো (গভীরতা) ── */
    .admin-sidebar {
        background: linear-gradient(180deg, rgb(var(--c-surface-2)) 0%, rgb(var(--c-bg)) 100%);
        border-right: 1px solid rgb(var(--c-border));
        box-shadow: 8px 0 30px -18px rgba(16,24,64,.35);
    }
    .nav-link { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border-radius: 10px; color: rgb(var(--c-text-muted)); transition: all .15s; font-size: 14px; font-weight: 500; }
    .nav-link:hover { background: rgb(var(--c-primary) / .08); color: rgb(var(--c-text)); }
    .nav-link.active { background: linear-gradient(135deg, rgb(var(--c-primary)), rgb(var(--c-primary-2))); color: #fff; font-weight: 600; box-shadow: 0 4px 12px rgb(var(--c-primary) / .35); }
    .nav-link.active svg { color: #fff; }
    .nav-section { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: rgb(var(--c-text-muted)); padding: 16px 16px 4px; opacity: .75; }

    /* ── কার্ড গভীরতা — থিমড হালকা বর্ডার + নরম গভীর শ্যাডো (সাদা কার্ড tinted ব্যাকগ্রাউন্ডে ফুটে ওঠে) ── */
    main .bg-white.rounded-2xl, main .bg-white.rounded-xl, main .bg-white.rounded-3xl { border: 1px solid rgb(var(--c-border)); }
    .shadow-sm { box-shadow: 0 1px 2px rgba(16,24,64,.05), 0 1px 3px rgba(16,24,64,.04) !important; }
    .shadow    { box-shadow: 0 10px 26px -8px rgba(16,24,64,.12), 0 2px 6px rgba(16,24,64,.05) !important; }
    .shadow-md { box-shadow: 0 12px 30px -8px rgba(16,24,64,.14), 0 3px 8px rgba(16,24,64,.06) !important; }
    .shadow-lg { box-shadow: 0 18px 40px -10px rgba(16,24,64,.16) !important; }
    .shadow-xl, .shadow-2xl { box-shadow: 0 26px 52px -12px rgba(16,24,64,.22) !important; }
    /* টেবিল হেডার একটু বেশি tinted যাতে আলাদা করা যায় */
    main table thead th, main table thead tr { background: rgb(var(--c-surface-2)); }

    /* ══ গ্লোবাল কম্পোনেন্ট পলিশ (সব পেজে প্রযোজ্য) ══ */

    /* ── ১. রঙিন গ্রেডিয়েন্ট বাটন — বিদ্যমান solid ফিল ক্লাসগুলো (bg-*-600 ইত্যাদি) স্বয়ংক্রিয়ভাবে
         গ্রেডিয়েন্ট হয়ে যায়; text-white মাস্ক হয় না বলে লেখা পড়া যায়। প্রাইমারি (indigo) থিম অনুসরণ করে ── */
    .bg-indigo-600, .bg-indigo-700 { background-image: linear-gradient(135deg, rgb(var(--c-primary)), rgb(var(--c-primary-2))) !important; }
    .bg-green-600, .bg-green-700, .bg-emerald-600 { background-image: linear-gradient(135deg, #34D399, #059669) !important; }
    .bg-red-600, .bg-red-700 { background-image: linear-gradient(135deg, #F87171, #DC2626) !important; }
    .bg-amber-600, .bg-orange-600, .bg-orange-500, .bg-yellow-600 { background-image: linear-gradient(135deg, #FBBF24, #D97706) !important; }
    .bg-blue-600, .bg-blue-700 { background-image: linear-gradient(135deg, #60A5FA, #2563EB) !important; }
    .bg-purple-600, .bg-purple-700, .bg-fuchsia-600 { background-image: linear-gradient(135deg, #A78BFA, #7C3AED) !important; }
    .bg-pink-600, .bg-pink-700 { background-image: linear-gradient(135deg, #F472B6, #DB2777) !important; }
    .bg-gray-800, .bg-gray-900 { background-image: linear-gradient(135deg, rgb(var(--c-text)), rgb(var(--c-text-muted))) !important; }
    /* বাটন/লিংকে (div-এ না) hover ফিডব্যাক */
    a[class*="bg-indigo-6"]:hover, button[class*="bg-indigo-6"]:hover,
    a[class*="bg-green-6"]:hover, button[class*="bg-green-6"]:hover, button[class*="bg-emerald-6"]:hover,
    a[class*="bg-red-6"]:hover, button[class*="bg-red-6"]:hover,
    a[class*="bg-orange-"]:hover, button[class*="bg-orange-"]:hover, button[class*="bg-amber-6"]:hover,
    a[class*="bg-blue-6"]:hover, button[class*="bg-blue-6"]:hover,
    a[class*="bg-purple-6"]:hover, button[class*="bg-purple-6"]:hover,
    a[class*="bg-gray-8"]:hover, button[class*="bg-gray-8"]:hover
    { filter: brightness(1.08); transform: translateY(-1px); }
    /* disabled বাটনে গ্রেডিয়েন্ট বাদ — নাহলে background-image, disabled:bg-* কালারের উপর থেকে যায় ও
       disabled:text-* মিউটেড লেখা গ্রেডিয়েন্টের উপর পড়া যায় না (কিছু থিমে অদৃশ্য হয়ে যেত) */
    button:disabled { background-image: none !important; filter: none !important; transform: none !important; }

    /* ── ৭. কার্ড hover lift ── */
    main .bg-white.rounded-2xl, main .bg-white.rounded-xl, main .bg-white.rounded-3xl { transition: transform .18s ease, box-shadow .18s ease; }
    main .bg-white.rounded-2xl:hover, main .bg-white.rounded-3xl:hover { transform: translateY(-3px); box-shadow: 0 20px 44px -12px rgba(16,24,64,.20) !important; }

    /* ── ১৫. স্মুথ ট্রানজিশন সব ইন্টার‍্যাকটিভ কন্ট্রোলে ── */
    a, button, input, select, textarea, .nav-link { transition: color .15s, background-color .15s, background-image .15s, border-color .15s, box-shadow .15s, filter .15s, transform .1s; }

    /* ── ১৪. ফোকাস রিং — থিম glow ── */
    main input:focus, main select:focus, main textarea:focus { outline: none; border-color: rgb(var(--c-primary)) !important; box-shadow: 0 0 0 3px rgb(var(--c-primary) / .18) !important; }
    :focus-visible { outline-offset: 2px; }

    /* ── ১১. কাস্টম স্ক্রলবার (থিম রঙ) ── */
    * { scrollbar-width: thin; scrollbar-color: rgb(var(--c-primary) / .4) transparent; }
    ::-webkit-scrollbar { width: 10px; height: 10px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: rgb(var(--c-primary) / .35); border-radius: 999px; border: 2px solid transparent; background-clip: padding-box; }
    ::-webkit-scrollbar-thumb:hover { background: rgb(var(--c-primary) / .6); background-clip: padding-box; }

    /* ── ৬. অ্যাভাটার (initial বৃত্ত) ── */
    .avatar { display: inline-flex; align-items: center; justify-content: center; border-radius: 50%; font-weight: 700; color: #fff; background: linear-gradient(135deg, rgb(var(--c-primary)), rgb(var(--c-primary-2))); flex-shrink: 0; font-family: var(--font-head); letter-spacing: .02em; }
    .avatar-sm { width: 30px; height: 30px; font-size: 12px; }
    .avatar-md { width: 40px; height: 40px; font-size: 15px; }

    /* ── ৫. টেবিল zebra + hover + sticky header ── */
    main table tbody tr:nth-child(even) td { background: rgb(var(--c-surface-2) / .45); }
    main table tbody tr:hover td { background: rgb(var(--c-primary) / .06); }

    /* ── মোবাইলে অ্যাডমিন টেবিল → কার্ড-লেআউট (অনুভূমিক স্ক্রলের বদলে প্রতিটা রো একটা কার্ড;
          প্রতিটা সেলের পাশে কলাম-নাম দেখায় — data-label, layout-bottom.php-এর JS thead থেকে সেট করে)।
          ডেস্কটপে (>৭৬৭px) স্বাভাবিক টেবিলই থাকে। ── */
    @media (max-width: 767px) {
        main .overflow-x-auto { overflow-x: visible; }

        /* ⚠️ কার্ড-ইন-কার্ড ঠিক করা (২০২৬-০৭-২০, ইউজারের স্ক্রিনশট): টেবিলের মোড়ক
           `.bg-white rounded-2xl shadow` নিজেই একটা সাদা কার্ডের মতো দেখায়, আর ভেতরে প্রতিটা রো-ও
           কার্ড হয়ে যায় — ফলে "একটা কার্ডের ভেতরে আরেকটা কার্ড" দেখাত। মোবাইলে মোড়কটা স্বচ্ছ করে
           দিলে শুধু রো-কার্ডগুলোই পেজের ব্যাকগ্রাউন্ডে ভাসে (ডেস্কটপে টেবিল অপরিবর্তিত)। */
        /* `:has()` আধুনিক ব্রাউজারে চলে; পুরনো ফোনের জন্য layout-bottom.php এর JS একই মোড়কে
           `.table-wrap` ক্লাস বসিয়ে দেয় — দুটোর যেকোনো একটা কাজ করলেই হলো। */
        main .overflow-x-auto:has(> table),
        main .overflow-x-auto.table-wrap {
            background: transparent !important;
            box-shadow: none !important;
            border-radius: 0 !important;
            padding: 0 !important;
        }
        main .overflow-x-auto > table thead { display: none; }
        main .overflow-x-auto > table, main .overflow-x-auto > table tbody { display: block; width: 100%; }
        main .overflow-x-auto > table tr { display: block; border: 1px solid rgb(var(--c-border)); border-radius: 12px; margin: 0 0 12px; padding: 4px 14px; background: rgb(var(--c-surface)); box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        main .overflow-x-auto > table td { display: flex; justify-content: space-between; align-items: center; gap: 14px; padding: 8px 0; border: none !important; text-align: right; background: none !important; min-width: 0; }
        main .overflow-x-auto > table td + td { border-top: 1px solid rgb(var(--c-border) / .5) !important; }
        main .overflow-x-auto > table td::before { content: attr(data-label); font-weight: 600; color: rgb(var(--c-text-muted)); text-align: left; flex-shrink: 0; }
        main .overflow-x-auto > table td:empty { display: none; }
        /* "কোনো ডেটা নেই" ধরনের colspan রো — কার্ড না বানিয়ে স্বাভাবিক কেন্দ্রীভূত রাখি */
        main .overflow-x-auto > table td[colspan] { display: block; text-align: center; }
        main .overflow-x-auto > table td[colspan]::before { content: none; }

        /* ── কার্ডের প্রথম ঘরটা "শিরোনাম" হিসেবে (opt-in: <table class="mcard">) ──
           label:value সারির বদলে বড় বোল্ড টাইটেল — অনেক কার্ডের মধ্যে চোখ বুলিয়ে খুঁজে পাওয়া সহজ হয়।
           ⚠️ opt-in রাখা হয়েছে কারণ কিছু টেবিলের প্রথম ঘর চেকবক্স (courier.php/registrations) —
           সেগুলোতে এটা প্রয়োগ হলে লেআউট ভেঙে যেত। */
        main .overflow-x-auto > table.mcard td:first-child {
            display: block; text-align: left; font-weight: 700; font-size: 15px;
            color: rgb(var(--c-text)); padding: 10px 0 8px; line-height: 1.4;
        }
        main .overflow-x-auto > table.mcard td:first-child::before { content: none; }
        main .overflow-x-auto > table.mcard td:first-child + td { border-top: 1px solid rgb(var(--c-border)) !important; }
        /* অ্যাকশন ঘর (শেষ) — লিংকগুলো বাটনের মতো, ট্যাপ করা সহজ */
        main .overflow-x-auto > table.mcard td:last-child { flex-wrap: wrap; justify-content: flex-end; gap: 8px; padding-top: 10px; }
        main .overflow-x-auto > table.mcard td:last-child a,
        main .overflow-x-auto > table.mcard td:last-child button {
            padding: 6px 14px; border-radius: 8px; background: rgb(var(--c-surface-2)); font-size: 13px;
        }
    }

    /* ── লিস্ট সার্চ (মোবাইলে অনেক আইটেমের মধ্যে দ্রুত খুঁজে বের করতে) ── */
    .list-search-wrap { position: relative; }
    .list-search-wrap > i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); pointer-events: none; color: rgb(var(--c-text-muted)); }
    .list-search { width: 100%; padding: 11px 14px 11px 38px; border: 1px solid rgb(var(--c-border)); border-radius: 12px; background: rgb(var(--c-surface)); color: rgb(var(--c-text)); font-size: 14px; }
    .list-search:focus { outline: none; border-color: rgb(var(--c-primary)); box-shadow: 0 0 0 3px rgb(var(--c-primary) / .15); }
    /* অ্যাক্সেসিবিলিটি: ডিভাইসে "reduce motion" চালু থাকলে অ্যানিমেশন/ট্রানজিশন প্রায় বন্ধ */
    @media (prefers-reduced-motion: reduce) {
        *, ::before, ::after { animation-duration: .001ms !important; animation-iteration-count: 1 !important; transition-duration: .001ms !important; scroll-behavior: auto !important; }
    }

    /* ── ৪. সুন্দর empty state ── */
    .empty-state { text-align: center; padding: 48px 24px; color: rgb(var(--c-text-muted)); }
    .empty-state .empty-ic { width: 56px; height: 56px; margin: 0 auto 14px; border-radius: 16px; display: grid; place-items: center; background: rgb(var(--c-primary) / .10); color: rgb(var(--c-primary)); }
    .empty-state .empty-ic svg { width: 28px; height: 28px; }

    /* ── ১০. টোস্ট নোটিফিকেশন ── */
    #toast-wrap { position: fixed; top: 18px; right: 18px; z-index: 60; display: flex; flex-direction: column; gap: 10px; max-width: 92vw; }
    .toast { display: flex; align-items: flex-start; gap: 10px; min-width: 260px; max-width: 380px; padding: 13px 15px; border-radius: 12px; background: rgb(var(--c-surface)); border: 1px solid rgb(var(--c-border)); box-shadow: 0 18px 40px -12px rgba(16,24,64,.28); font-size: 14px; color: rgb(var(--c-text)); border-left: 4px solid; animation: toastIn .25s ease; }
    .toast.ok { border-left-color: #059669; } .toast.err { border-left-color: #DC2626; }
    .toast .toast-ic { flex-shrink: 0; margin-top: 1px; } .toast.ok .toast-ic { color: #059669; } .toast.err .toast-ic { color: #DC2626; }
    .toast.hide { animation: toastOut .3s ease forwards; }
    @keyframes toastIn { from { opacity: 0; transform: translateX(24px); } to { opacity: 1; transform: none; } }
    @keyframes toastOut { to { opacity: 0; transform: translateX(24px); } }

    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; } }

    /* ── ৯. ডার্ক থিম override — সাদা কার্ড/মডাল/হেডার → surface; ছোট গোল knob/dot সাদাই থাকে ── */
    [data-theme="midnight"] .bg-white, [data-theme="teal"] .bg-white, [data-theme="carbon"] .bg-white { background-color: rgb(var(--c-surface)) !important; }
    [data-theme="midnight"] .bg-white.rounded-full, [data-theme="teal"] .bg-white.rounded-full, [data-theme="carbon"] .bg-white.rounded-full { background-color: #fff !important; }
    /* ডার্ক থিমে হালকা-tint ব্যাজ ব্যাকগ্রাউন্ড (bg-*-100/50) একটু গাঢ় করা যাতে টেক্সট পড়া যায় */
    [data-theme="midnight"] [class*="bg-gray-50"], [data-theme="teal"] [class*="bg-gray-50"], [data-theme="carbon"] [class*="bg-gray-50"] { background-color: rgb(var(--c-surface-2)) !important; }
    [data-theme="midnight"] .border, [data-theme="teal"] .border, [data-theme="carbon"] .border { border-color: rgb(var(--c-border)); }

    /* ── থিম পিকার ── */
    .theme-picker { display: flex; align-items: center; gap: 6px; }
    .theme-dot { width: 22px; height: 22px; border-radius: 50%; border: 2px solid transparent; cursor: pointer; padding: 0; transition: transform .1s, border-color .15s; }
    .theme-dot:hover { transform: translateY(-1px); }
    .theme-dot.on { border-color: rgb(var(--c-text)); }
</style>
</head>
<body class="bg-gray-100 min-h-screen">
<div class="flex min-h-screen">
    <!-- মোবাইলে সাইডবার খোলা থাকলে পেছনে অন্ধকার ব্যাকড্রপ, ট্যাপ করলে বন্ধ হয়ে যাবে -->
    <div id="admin-sidebar-backdrop" class="hidden fixed inset-0 bg-black/50 z-40 md:hidden"></div>

    <!-- Sidebar -->
    <aside id="admin-sidebar" class="admin-sidebar w-64 flex-shrink-0 flex flex-col fixed md:static inset-y-0 left-0 z-50 -translate-x-full md:translate-x-0 transition-transform duration-200">
        <div class="p-5 border-b border-gray-200 flex items-center justify-between">
            <div class="flex items-center gap-2.5">
                <span class="w-9 h-9 rounded-xl flex items-center justify-center text-white flex-shrink-0" style="background: linear-gradient(135deg, rgb(var(--c-primary)), rgb(var(--c-primary-2)));">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
                </span>
                <div>
                    <h1 class="text-base font-bold text-gray-800 leading-tight">EduCenter</h1>
                    <p class="text-xs text-gray-500">Admin Panel</p>
                </div>
            </div>
            <button id="admin-sidebar-close" class="md:hidden p-1.5 rounded-lg hover:bg-gray-100 text-gray-600">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <nav class="flex-1 p-3 space-y-1 overflow-y-auto">
            <a href="index.php" class="nav-link <?= nav_active('index.php', $currentFile) ?>"><i data-lucide="layout-dashboard" class="w-4 h-4"></i> ড্যাশবোর্ড</a>
            <p class="nav-section">কনটেন্ট</p>
            <?php foreach (get_entities() as $navEntityKey => $navEntityConf): ?>
                <a href="manage.php?entity=<?= e($navEntityKey) ?>" class="nav-link <?= nav_active('manage.php', $currentFile, $navEntityKey, $currentEntity) ?>"><i data-lucide="file-text" class="w-4 h-4"></i> <?= e($navEntityConf['label_plural']) ?></a>
            <?php endforeach; ?>
            <p class="nav-section">অর্ডার</p>
            <a href="registrations.php" class="nav-link <?= nav_active('registrations.php', $currentFile) ?>"><i data-lucide="clipboard-list" class="w-4 h-4"></i> রেজিস্ট্রেশন/অর্ডার</a>
            <a href="course-data.php" class="nav-link <?= nav_active('course-data.php', $currentFile) ?>"><i data-lucide="table" class="w-4 h-4"></i> ডেটা টেবিল</a>
            <a href="courier.php" class="nav-link <?= nav_active('courier.php', $currentFile) ?>"><i data-lucide="truck" class="w-4 h-4"></i> কুরিয়ার</a>
            <a href="courier-prepare.php" class="nav-link <?= nav_active('courier-prepare.php', $currentFile) ?>"><i data-lucide="package" class="w-4 h-4"></i> পার্সেল প্রস্তুত</a>
            <a href="courier-tracking.php" class="nav-link <?= nav_active('courier-tracking.php', $currentFile) ?>"><i data-lucide="calendar-check" class="w-4 h-4"></i> কুরিয়ার ট্র্যাকিং</a>
            <p class="nav-section">লগ</p>
            <a href="download-logs.php" class="nav-link <?= nav_active('download-logs.php', $currentFile) ?>"><i data-lucide="download" class="w-4 h-4"></i> ডাউনলোড লগ</a>
            <a href="visitor-logs.php" class="nav-link <?= nav_active('visitor-logs.php', $currentFile) ?>"><i data-lucide="footprints" class="w-4 h-4"></i> ভিজিটর লগ</a>
            <a href="courier-shipment-logs.php" class="nav-link <?= nav_active('courier-shipment-logs.php', $currentFile) ?>"><i data-lucide="history" class="w-4 h-4"></i> কুরিয়ার শিপমেন্ট লগ</a>
            <p class="nav-section">আয়-ব্যয়</p>
            <a href="finance.php" class="nav-link <?= nav_active('finance.php', $currentFile) ?>"><i data-lucide="pie-chart" class="w-4 h-4"></i> ড্যাশবোর্ড</a>
            <a href="income.php" class="nav-link <?= nav_active('income.php', $currentFile) ?>"><i data-lucide="trending-up" class="w-4 h-4"></i> আয়</a>
            <a href="expenses.php" class="nav-link <?= nav_active('expenses.php', $currentFile) ?>"><i data-lucide="trending-down" class="w-4 h-4"></i> খরচ</a>
            <p class="nav-section">সেটিংস</p>
            <a href="settings.php" class="nav-link <?= nav_active('settings.php', $currentFile) ?>"><i data-lucide="settings" class="w-4 h-4"></i> সাইট সেটিংস</a>
            <a href="payment-methods.php" class="nav-link <?= nav_active('payment-methods.php', $currentFile) ?>"><i data-lucide="wallet" class="w-4 h-4"></i> পেমেন্ট মেথড</a>
            <a href="change-password.php" class="nav-link <?= nav_active('change-password.php', $currentFile) ?>"><i data-lucide="key" class="w-4 h-4"></i> পাসওয়ার্ড পরিবর্তন</a>
            <a href="backup.php" class="nav-link <?= nav_active('backup.php', $currentFile) ?>"><i data-lucide="hard-drive-download" class="w-4 h-4"></i> ব্যাকআপ ও ডাউনলোড</a>
            <a href="archive.php" class="nav-link <?= nav_active('archive.php', $currentFile) ?>"><i data-lucide="archive" class="w-4 h-4"></i> আর্কাইভ (রিস্টোর)</a>
        </nav>
        <?php
            $adminName = current_admin_name();
            $nameParts = preg_split('/\s+/', trim($adminName));
            $adminInitials = mb_strtoupper(mb_substr($nameParts[0] ?? 'A', 0, 1) . (isset($nameParts[1]) ? mb_substr($nameParts[1], 0, 1) : ''), 'UTF-8');
        ?>
        <?php // থিম পিকার সাইডবারেও — মোবাইলে হেডারের ক্লাস্টার লুকানো থাকে (জায়গার অভাবে), তাই
              // মোবাইল ইউজার এখান থেকেই (হ্যামবার্গার মেনু → নিচে) থিম বদলাতে পারেন। md+ এ হেডারেরটাই
              // যথেষ্ট, তাই এটা সেখানে লুকানো (md:hidden)। দুইটা পিকারই একই JS হ্যান্ডলার ব্যবহার করে। ?>
        <div class="p-3 border-t border-gray-200 md:hidden">
            <p class="text-[11px] font-bold text-gray-500 uppercase tracking-wide mb-2 px-1.5">থিম</p>
            <div class="theme-picker flex-wrap gap-2 px-1.5" data-theme-picker title="থিম বেছে নিন">
                <button type="button" class="theme-dot" data-theme-id="indigo"   style="background:#4F46E5" title="Indigo"></button>
                <button type="button" class="theme-dot" data-theme-id="emerald"  style="background:#059669" title="Emerald"></button>
                <button type="button" class="theme-dot" data-theme-id="amber"    style="background:#C2410C" title="Amber"></button>
                <button type="button" class="theme-dot" data-theme-id="plum"     style="background:#9333EA" title="Plum"></button>
                <button type="button" class="theme-dot" data-theme-id="kids"     style="background:#F97316" title="Kids"></button>
                <span class="w-px h-4 bg-gray-300 mx-0.5"></span>
                <button type="button" class="theme-dot" data-theme-id="midnight" style="background:#818CF8" title="Midnight (dark)"></button>
                <button type="button" class="theme-dot" data-theme-id="teal"     style="background:#2DD4BF" title="Teal (dark)"></button>
                <button type="button" class="theme-dot" data-theme-id="carbon"   style="background:#F59E0B" title="Carbon (dark)"></button>
            </div>
        </div>
        <div class="p-3 border-t border-gray-200">
            <div class="flex items-center gap-2.5 px-1.5 py-1.5">
                <span class="avatar avatar-md"><?= e($adminInitials) ?></span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-bold text-gray-800 truncate leading-tight"><?= e($adminName) ?></p>
                    <p class="text-xs text-gray-500">অ্যাডমিন</p>
                </div>
                <a href="logout.php" title="লগআউট" class="p-2 rounded-lg text-gray-500 hover:text-red-600 hover:bg-red-50 flex-shrink-0">
                    <i data-lucide="log-out" class="w-4 h-4"></i>
                </a>
            </div>
        </div>
    </aside>

    <!-- Main -->
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-white border-b border-gray-200 px-4 md:px-6 py-3 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <button id="admin-sidebar-open" class="md:hidden p-2 rounded-lg hover:bg-gray-100 flex-shrink-0">
                    <i data-lucide="menu" class="w-5 h-5 text-gray-700"></i>
                </button>
                <div class="min-w-0">
                    <?php if ($currentFile !== 'index.php'): ?>
                    <div class="hidden sm:flex items-center gap-1.5 text-xs text-gray-400 leading-none mb-1">
                        <a href="index.php" class="inline-flex items-center gap-1 hover:text-indigo-600"><i data-lucide="home" class="w-3 h-3"></i> হোম</a>
                        <span>›</span>
                        <span class="text-gray-500 font-medium truncate"><?= isset($pageTitle) ? e($pageTitle) : '' ?></span>
                    </div>
                    <?php endif; ?>
                    <h2 class="font-bold text-gray-800 text-base sm:text-lg truncate leading-tight"><?= isset($pageTitle) ? e($pageTitle) : '' ?></h2>
                </div>
            </div>
            <!-- মোবাইলে (sm এর নিচে) পুরো ডান ক্লাস্টার (থিম পিকার + নাম) লুকানো — নাহলে ৮টা রঙের ডট
                 হেডারের জায়গা নিয়ে পেজ টাইটেল চাপা দিত। থিম বদলানো ট্যাবলেট/ডেস্কটপে (sm+) থাকবে। -->
            <div class="hidden sm:flex items-center gap-3 flex-shrink-0">
                <!-- থিম পিকার — রঙের বিন্দুতে ক্লিক করলে পুরো প্যানেলের থিম বদলায় (localStorage এ সেভ হয়) -->
                <div class="theme-picker" id="admin-theme-picker" data-theme-picker title="থিম বেছে নিন">
                    <button type="button" class="theme-dot" data-theme-id="indigo"  style="background:#4F46E5" title="Indigo"></button>
                    <button type="button" class="theme-dot" data-theme-id="emerald" style="background:#059669" title="Emerald"></button>
                    <button type="button" class="theme-dot" data-theme-id="amber"   style="background:#C2410C" title="Amber"></button>
                    <button type="button" class="theme-dot" data-theme-id="plum"    style="background:#9333EA" title="Plum"></button>
                    <button type="button" class="theme-dot" data-theme-id="kids"    style="background:#F97316" title="Kids"></button>
                    <span class="w-px h-4 bg-gray-300 mx-0.5"></span>
                    <button type="button" class="theme-dot" data-theme-id="midnight" style="background:#818CF8" title="Midnight (dark)"></button>
                    <button type="button" class="theme-dot" data-theme-id="teal"     style="background:#2DD4BF" title="Teal (dark)"></button>
                    <button type="button" class="theme-dot" data-theme-id="carbon"   style="background:#F59E0B" title="Carbon (dark)"></button>
                </div>
                <span class="text-sm text-gray-600 hidden sm:inline"><?= e(current_admin_name()) ?></span>
            </div>
        </header>
        <main class="p-4 md:p-6 flex-1">
        <?php $flash = get_flash(); if ($flash): ?>
            <div id="toast-wrap">
                <div class="toast <?= $flash['type'] === 'error' ? 'err' : 'ok' ?>" data-toast>
                    <span class="toast-ic"><i data-lucide="<?= $flash['type'] === 'error' ? 'alert-circle' : 'check-circle-2' ?>" class="w-5 h-5"></i></span>
                    <span class="flex-1 leading-snug"><?= e($flash['message']) ?></span>
                    <button type="button" onclick="this.closest('.toast').remove()" class="text-gray-400 hover:text-gray-600 flex-shrink-0" aria-label="বন্ধ করুন"><i data-lucide="x" class="w-4 h-4"></i></button>
                </div>
            </div>
        <?php endif; ?>
