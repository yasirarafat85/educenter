<?php
// ─────────────────────────────────────────────────────────────
// 📨 যোগাযোগ বার্তা (Get in touch) — শেয়ার্ড ইঞ্জিন (2026-10-29)
//
// পাবলিক ভিজিটর (লগইন ছাড়া) সাইটেই নাম/মোবাইল/বার্তা লিখে পাঠায়; অ্যাডমিন একটা
// ইনবক্সে পড়ে নিজে ফোনে/WhatsApp-এ যোগাযোগ করেন। এখান থেকে কিছু অটো বদলায় না।
//
// 🔴🔴 সম্পূর্ণ আলাদা স্তর — টাকা/খাতা/আয় কিছুই ছোঁয় না (legacy_students-এর মতো নীতি)।
//
// দুই স্তরে সহজে ফেরানো যায়:
//   • নরম: সেটিংসে contact_feature_on = 0 → ফর্ম/সাইডবার/কার্ড লুকায়, ডেটা থাকে।
//   • সম্পূর্ণ: phpMyAdmin-এ টেবিল DROP → সব গার্ড (db_has_table) চুপচাপ বাদ,
//     সাইট হুবহু আগের অবস্থায় (ফোন/WhatsApp/ইমেইল তথ্যটুকু তবু দেখায়)।
//
// এই ফাইলটা পাবলিক পাতা (contact.php) ও অ্যাডমিন (contact-messages.php) দুটোই require করে,
// তাই includes/-এ রাখা (admin/includes/ নয়)।
// ─────────────────────────────────────────────────────────────

require_once __DIR__ . '/functions.php';

// টেবিলটা আছে কিনা (মাইগ্রেশনের আগে চুপচাপ বাদ দেওয়ার গার্ড)
function contact_table_ready(?PDO $db = null): bool
{
    return db_has_table($db, 'contact_messages');
}

// অ্যাডমিন ফিচারটা চালু রেখেছেন কিনা (নরম বন্ধ — ডেটা না মুছে লুকানো)।
// খালি/অন্য কিছু = চালু, শুধু '0' = বন্ধ (facebook_section_on-এর হুবহু নীতি)।
function contact_feature_switch_on(): bool
{
    return get_setting('contact_feature_on') !== '0';
}

// ফর্ম দেখানো / সাইডবার লিংক / ড্যাশবোর্ড কার্ডের গার্ড: টেবিল আছে **এবং** সুইচ চালু।
// (অ্যাডমিন ইনবক্স পাতাটা নিজে শুধু টেবিল-গার্ডে চলে, তাই বন্ধ করলেও পুরনো বার্তা পড়া যায়।)
function contact_feature_ready(?PDO $db = null): bool
{
    return contact_table_ready($db) && contact_feature_switch_on();
}

// বিষয়/ধরনের তালিকা — সেটিংসে এডিটযোগ্য (প্রতি লাইনে একটা)। এটাই "সহজে ঠিক করা":
// অ্যাডমিন কোড ছাড়াই তালিকা বদলাতে পারেন। খালি রাখলে নিচের তৈরি তালিকা।
// 🔴 বাংলা ডিফল্ট PHP-তে (SQL-এ নয়) — mojibake নিয়ম।
function contact_topics(): array
{
    $raw = (string) get_setting('contact_topics');
    if (trim($raw) === '') {
        return ['ভর্তি সংক্রান্ত', 'কোর্স জানতে চাই', 'অভিযোগ', 'সাধারণ প্রশ্ন'];
    }
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim($line);
        if ($line !== '' && !in_array($line, $out, true)) {
            $out[] = mb_substr($line, 0, 80);
        }
        if (count($out) >= 30) {
            break;
        }
    }
    return $out ?: ['সাধারণ প্রশ্ন'];
}

// সাবমিটে বিষয় সবসময় হোয়াইটলিস্ট করা হয় (POST-এ যা এসেছে তা বিশ্বাস নয়)।
function contact_topic_valid(string $t): bool
{
    return $t !== '' && in_array($t, contact_topics(), true);
}

// একটা বিষয়ের রঙ — তালিকায় অবস্থান ধরে স্থিতিশীল, নাহলে নামের hash। ইনলাইন [tint, deep]।
// 🔴 Tailwind ক্লাস নয় (রিবিল্ড এড়াতে) — notice/task প্যালেটের মতোই।
function contact_topic_color(string $t): array
{
    $pal = [
        ['#EFF6FF', '#1D4ED8'], // blue
        ['#FFF7ED', '#C2410C'], // amber
        ['#FEF2F2', '#B91C1C'], // red
        ['#ECFDF5', '#047857'], // green
        ['#FAF5FF', '#7E22CE'], // purple
        ['#ECFEFF', '#0E7490'], // teal
    ];
    $topics = contact_topics();
    $i = array_search($t, $topics, true);
    if ($i === false) {
        $i = crc32($t);
    }
    return $pal[((int) $i % count($pal) + count($pal)) % count($pal)];
}
