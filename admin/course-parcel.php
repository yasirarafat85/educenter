<?php
// কোর্স পার্সেল — "পার্সেল প্রস্তুত" + "কোর্স ট্র্যাকিং" এক পেজে (২০২৬-০৮-১৬, ডিজাইন-রিভিউ v3 পলিশ)।
// মডেল: total_parcels=N → ফিক্সড মাস ১..N (Excel শিটের মতো ট্যাব)। প্রতি (reg, মাস)-এ একটা courier_batch (upsert)।
//   • Section 1 "অবস্থা": শিক্ষার্থী × মাস ম্যাট্রিক্স + গ্রুপ/সক্রিয় টগল।
//   • Section 2 "এই মাসের পার্সেল": মাস-ট্যাব বেছে যাবে/না + কালেকশন (সরাসরি এডিটযোগ্য) + খসড়া/পাঠান।
// পলিশ: সংখ্যা English (বাংলা ডিজিট পড়া কঠিন), গ্রুপ টগল স্টাইলড (ইমোজি না), মান্থলি-ফি মাল্টিপ্লায়ার বাদ,
//   পরিমাণ সরাসরি লেখা যায় (আগে লক ছিল)। hide_parcel বাদ; সংবেদনশীল বদলে প্রথমবার-ছাড়া ওয়ার্নিং।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/courier/CourierManager.php';
require_once __DIR__ . '/includes/courier-notes.php';
require_once __DIR__ . '/includes/payments.php'; // 🔑 টাকার খাতা — কালেকশনের পরিমাণ এখান থেকেই আসে
require_once __DIR__ . '/includes/expense-sync.php'; // 💸 ছাড়/মাফ → খরচের বই
require_once __DIR__ . '/includes/income-sync.php';  // 📒 খাতা বসানোর পর আয় মেলানো (ensure-ledger)
require_once __DIR__ . '/includes/finance-link.php'; // 📊 হেডারের "এই ব্যাচের আয়/ব্যয়" লিংকের গার্ড
admin_require_login();

$db = get_db();
$pageTitle = 'কোর্স পার্সেল';

// মাস নম্বর → সংরক্ষণ-লেবেল (courier_batches.period_label এখানেই সেভ; স্থায়ী রাখা — পুরনো ডেটা মেলাতে)
function cp_month_label(int $i): string
{
    static $names = [1 => '১ম মাস', 2 => '২য় মাস', 3 => '৩য় মাস', 4 => '৪র্থ মাস', 5 => '৫ম মাস', 6 => '৬ষ্ঠ মাস',
                     7 => '৭ম মাস', 8 => '৮ম মাস', 9 => '৯ম মাস', 10 => '১০ম মাস', 11 => '১১তম মাস', 12 => '১২তম মাস'];
    if (isset($names[$i])) { return $names[$i]; }
    return strtr((string) $i, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']) . 'তম মাস';
}
// 💸 এক পার্সেলে সর্বোচ্চ কত টাকা ছাড় দেওয়া যাবে — নিছক টাইপো-গার্ড (৫০০০০ লিখে ফেলা আটকাতে)
const CP_MAX_WAIVE = 100000;
// ✅ "কুরিয়ার ডান"-এ এক পার্সেলে সর্বোচ্চ কত টাকা বসানো যাবে — নিছক টাইপো-গার্ড
const CP_MAX_COLLECT = 1000000;

/* ══════ ✅ হাতে পাঠানো + 💰 টাকা — শেয়ার্ড হেল্পার (২০২৬-১০-১৪) ══════
 * 🔴🔴 এই দুটোই একমাত্র জায়গা যেখানে "পাঠানো ধরে নেওয়া" ও "খাতায় টাকা বসানো" লেখা আছে।
 *     আগে কাজটা `action=parcel-done` নামে আলাদা একটা ফর্ম থেকে হতো — সেটা তুলে দেওয়া হয়েছে,
 *     কারণ ঘরগুলো `form="cpDoneForm"` দিয়ে ঐ ফর্মে বাঁধা থাকায় নিচের **"সেভ"** চাপলে টিকটা
 *     নীরবে হারিয়ে যেত (ইউজারের রিপোর্ট: "টিক মার্ক দিয়ে সেভ দিলে সেটা হয়না")।
 *     এখন ঘরগুলো `roundForm`-এর ভেতরেই (`bd[<id>][done_sent]`/`[done_amt]`), আর কাজটা
 *     `round-save` হ্যান্ডলারই করে — অর্থাৎ **যেকোনো সেভ বোতামেই** কাজ হয়। */

/**
 * ✅ একটা পার্সেল-সারিকে "হাতে পাঠানো" ছাপ দেওয়া।
 * 🔴🔴 কোনো আসল কুরিয়ার API ডাকা হয় না — চালানও তৈরি হয় না। নিছক `send_status='sent'` +
 *     `courier_provider='manual'` (ইউজার চালানটা কুরিয়ারের নিজের সাইটে এন্ট্রি দিয়ে এলে)।
 * 🔴 `send_status <> 'sent'` শর্তটা রাখা হয়েছে যাতে আগে সত্যিই পাঠানো চালানের `sent_at`
 *    কখনো আজকের তারিখে সরে না যায় (বাসি পাতা থেকে POST আসতে পারে)।
 * 🔴 `amount_to_collect` এখানে ছোঁয়া হয় না — কলার কার্ডের নিজের কালেকশন-ঘর থেকে ঠিক
 *    আগেই ওটা লিখে দেয় (হিসাবের নিয়ম দুই জায়গায় রাখা হয় না)।
 */
function cp_mark_sent_manual(PDO $db, int $batchId): void
{
    if ($batchId <= 0) { return; }
    $db->prepare("UPDATE courier_batches
                  SET send_status = 'sent', courier_provider = 'manual', sent_at = NOW()
                  WHERE id = :id AND send_status <> 'sent'")->execute(['id' => $batchId]);
}

/* 💰 হাতে পাওয়া টাকা খাতায় জমা বসানো — আগে এখানে নিজের ২৮ লাইন ছিল; ২০২৬-১০-১৫-তে
 * (ধাপ ২) সেটা তুলে দিয়ে শেয়ার্ড **`pay_collect()`** (admin/includes/income-sync.php)
 * ডাকা হয় — registrations.php-এর "নতুন টাকা জমা নিন" আর payment-inbox.php-এর
 * "খাতায় বসান"-ও ঠিক সেটাই ডাকে।
 * 🔴 টাকার নিয়ম এখন একটাই জায়গায়; এখানে আলাদা করে আর লিখবেন না।
 * 🔴 কলার নিজে ট্রানজ্যাকশন খোলে, আর `ok => false` হলে rollback করে (নিচে দেখুন)।
 */

/**
 * 🚚 ধরে রাখা ডেলিভারি চার্জ খাতায় বসানো / তুলে নেওয়া (২০২৬-১০-১৫)
 *
 * ইউজার: *"ডেলিভারি চার্জ সহ ডিউ থাকতে পারে"*। কোর্স ফি/বেতন আগে থেকেই খাতায় আছে,
 * তাই সেগুলোর টিক তুলে দিলে বাকিটা **নিজে থেকেই** থেকে যায় — কিছু লিখতে হয় না।
 * কিন্তু ডেলিভারি চার্জ খাতায় নেই (ওটা কালেকশনের সময় যোগ হয়), তাই সেটা ধরে রাখলে
 * **খাতায় একটা বাকি-কিস্তি বসাতে হয়** — নাহলে টাকাটা নীরবে হারিয়ে যেত।
 *
 * 🔴 চাবি = (registration_id, kind='delivery', label) — এক (শিক্ষার্থী, মাস)-এ একটাই সারি
 *    (`expense_sync_parcel_waiver()`-এর হুবহু প্যাটার্ন)।
 * 🔴 মাসের ঘর দুটো **NULL** — `pay_row_slots()` এই kind-কে কোনো পার্সেলের কালেকশনে
 *    নিজে থেকে বসায় না; অ্যাডমিন যে মাসে ইচ্ছা ছকে "আগের বাকি" হিসেবে টিক দিয়ে তোলেন।
 * 🔴🔴 জমা পড়ে যাওয়া সারি **কখনো মোছা হয় না** (টাকা হারাত)।
 * 🔴 আয়ের বই ছোঁয়া হয় না — এটা শুধু **প্রাপ্য**, জমা ০; আয় = মোট জমা, তাই অপরিবর্তিত।
 */
function cp_sync_delivery_due(PDO $db, int $regId, string $periodLabel, float $amount): string
{
    if ($regId <= 0) { return ''; }
    $label  = pay_delivery_label($periodLabel);
    $amount = max(0.0, round($amount, 2));
    try {
        $sel = $db->prepare("SELECT id, amount_paid FROM registration_payments
                             WHERE registration_id = :r AND kind = 'delivery' AND label = :l LIMIT 1");
        $sel->execute(['r' => $regId, 'l' => $label]);
        $old = $sel->fetch();
        $out = '';
        if ($amount < 0.01) {
            if ($old && (float) $old['amount_paid'] < 0.01) {
                $db->prepare('DELETE FROM registration_payments WHERE id = :id')->execute(['id' => (int) $old['id']]);
                $out = 'removed';
            }
        } elseif ($old) {
            // 🔴 মাসের ঘর দুটো NULL-ই থাকে — `pay_row_slots()` এই kind-কে কোনো পার্সেলে
            //    বসায় না (অ্যাডমিন ছকে টিক দিয়ে যে মাসে ইচ্ছা তোলেন)
            $db->prepare('UPDATE registration_payments SET amount_due = :a WHERE id = :id')
               ->execute(['a' => $amount, 'id' => (int) $old['id']]);
            $out = 'updated';
        } else {
            // seq ৯০০ — খাতার একদম নিচে বসে (রেজি ফি/বেতনের কিস্তির ক্রম অক্ষত থাকে)
            $db->prepare("INSERT INTO registration_payments
                            (registration_id, seq, kind, label, amount_due, amount_paid)
                          VALUES (:r, 900, 'delivery', :l, :a, 0)")
               ->execute(['r' => $regId, 'l' => $label, 'a' => $amount]);
            $out = 'added';
        }
        if ($out !== '') {
            // খাতার বাকি বদলেছে → `registrations.due_amount` ডিনরমালাইজ (আয় নড়ে না)
            $sum = pay_summary(pay_fetch_many($db, [$regId])[$regId] ?? []);
            $db->prepare('UPDATE registrations SET due_amount = :d WHERE id = :id')
               ->execute(['d' => $sum['balance'], 'id' => $regId]);
        }
        return $out;
    } catch (PDOException $e) {
        // খাতার টেবিল নেই — পার্সেল সেভ করা কখনো আটকাবে না।
        // 🔴 তবু লগে লেখা হয়: এই নীরব catch-টাই একবার `SQLSTATE[HY093]` (একই প্লেসহোল্ডার
        //    দুইবার) লুকিয়ে রেখেছিল — টেস্ট না থাকলে ধরাই পড়ত না।
        error_log('cp_sync_delivery_due: ' . $e->getMessage());
        return '';
    }
}

// প্রদর্শনের জন্য (English সংখ্যা — পড়া সহজ)
function cp_month_display(int $i): string { return 'মাস ' . $i; }

function cp_url(int $itemId = 0, int $month = 0): string
{
    $u = 'course-parcel.php';
    if ($itemId) { $u .= '?item_id=' . $itemId; if ($month) { $u .= '&month=' . $month; } }
    return $u;
}

/**
 * 🔙 কাজ শেষে কোথায় ফেরত — "এই মাসের কাজ" (month-work.php) পাতা থেকে
 *    `action=ensure-ledger` POST করলে অ্যাডমিন যেন এখানে ছিটকে না আসেন।
 *
 * 🔴 হোয়াইটলিস্ট — শুধু জানা দুটো পাতা (খোলা রিডাইরেক্ট গার্ড), আর কন্ট্রোল-ক্যারেক্টার
 *    ছেঁটে ফেলা হয় (মানটা POST থেকে এসে সরাসরি `Location:` হেডারে বসে — হেডার-ইনজেকশন
 *    গার্ড, `safe_return_url()`/`nav_safe_return()`-এর হুবহু একই নীতি)।
 */
function cp_back_url(?string $url, string $fallback): string
{
    $url = $url === null ? null : preg_replace('/[\x00-\x1F\x7F]/', '', $url);
    foreach (['month-work.php', 'course-parcel.php'] as $prefix) {
        if ($url && strpos($url, $prefix) === 0) { return $url; }
    }
    return $fallback;
}

// 🔎 এই পাতার সার্চ কত জন হলে দেখানো হবে (এর কম হলে চোখেই সবটা দেখা যায়)
const CP_SEARCH_MIN = 3;

// 🔎 সার্চের খড়ের গাদা — রোস্টারের সারি ও মাসের কার্ড **দুটোতেই একই ফাংশন**
// (দুই জায়গায় আলাদা তালিকা রাখলে একটায় যা খুঁজে পাওয়া যেত অন্যটায় পাওয়া যেত না)।
// 🔴 ইচ্ছাকৃতভাবে সার্ভারেই বানানো হয় — জেনেরিক অটো-সার্চ `el.textContent` দেখত, আর তাতে
//    সারির ডেলিভারি-ড্রপডাউনের সব অপশন ("ঢাকা"/"ঢাকার বাইরে") ঢুকে যেত, ফলে ওই শব্দ
//    লিখলে সবাই "মিলে যেত" (ইউজারের অভিযোগ: "ঠিক ভাবে হয়না")।
function cp_haystack(array $r): string
{
    $parts = [
        $r['customer_name'] ?? '', $r['facebook_id'] ?? '', $r['receiver_name'] ?? '',
        $r['address'] ?? '', $r['batch'] ?? '', $r['admin_note'] ?? '', $r['notes'] ?? '',
        $r['phone'] ?? '', $r['receiver_phone'] ?? '', $r['father_mobile'] ?? '',
    ];
    $txt = mb_strtolower(trim((string) preg_replace('~\s+~u', ' ', implode(' ', array_map('strval', $parts)))), 'UTF-8');
    // 🔢 বাংলা অঙ্কে লেখা ঠিকানা/ব্যাচও ("মিরপুর ১০") English অঙ্ক দিয়ে খুঁজে পাওয়া যায় —
    //    তাই দুই রূপই গাদায় থাকে। 🔴 `en_digits()` নয়: ওটা ক্রমবাচক (৫ম) ইচ্ছাকৃতভাবে বাদ দেয়,
    //    কিন্তু খোঁজার সময় "5ম ব্যাচ" লিখলেও মেলা উচিত।
    $en = strtr($txt, bn_digit_map());
    return $en === $txt ? $txt : $txt . ' ' . $en;
}

// 📞 শুধু অঙ্ক — মোবাইল নম্বর দিয়ে খোঁজার জন্য। "0171…" · "171…" · "+88017…" তিনভাবেই
// মেলে, কারণ গাদায় প্রতিটা নম্বরের পুরো রূপটাই থাকে আর JS প্রশ্নটাকেও অঙ্কে নামিয়ে আনে।
function cp_haystack_digits(array $r): string
{
    $out = [];
    foreach ([$r['phone'] ?? '', $r['receiver_phone'] ?? '', $r['father_mobile'] ?? ''] as $ph) {
        $d = preg_replace('~[^0-9]+~', '', en_digits((string) $ph));
        if ($d !== '') { $out[] = $d; }
    }
    return implode(' ', array_unique($out));
}

$dc = [
    'dhaka'   => (float) (get_setting('courier_dc_dhaka')   ?: 60),
    'near'    => (float) (get_setting('courier_dc_near')    ?: 80),
    'outside' => (float) (get_setting('courier_dc_outside') ?: 120),
];
$wxExtra = (float) (get_setting('courier_weight_extra') ?: 20);

// এলাকার নাম + সেটিংসে দেওয়া ডেলিভারি চার্জ (এক জায়গায়, রোস্টার ও মাস-কার্ড দুটোতেই)
$zoneLabels = [
    'dhaka'   => 'ঢাকা (৳' . (int) round($dc['dhaka']) . ')',
    'near'    => 'নিকটবর্তী (৳' . (int) round($dc['near']) . ')',
    'outside' => 'বাইরে (৳' . (int) round($dc['outside']) . ')',
];

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('course-parcel.php'); }
    $action = $_POST['action'] ?? '';
    $itemId = (int) ($_POST['item_id'] ?? 0);
    $regId  = (int) ($_POST['id'] ?? 0);
    $month  = (int) ($_POST['month'] ?? 0);

    if ($action === 'group') {
        $field = ($_POST['field'] ?? '') === 'messenger' ? 'messenger_group_added' : 'fb_group_added';
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        // 🔴 একমুখী: মডারেটর (super নয়) গ্রুপের টিক তুলতে পারবে না — শুধু মূল অ্যাডমিন পারবেন।
        if ($val === 0 && !admin_is_super()) {
            set_flash('error', 'গ্রুপের টিক তোলার অনুমতি নেই — ভুল হলে মূল অ্যাডমিনকে জানান।');
            redirect(cp_url($itemId, $month));
        }
        $db->prepare("UPDATE registrations SET $field = :v WHERE id = :id")->execute(['v' => $val, 'id' => $regId]);
        reg_group_stamp($db, $regId, $field, $val);   // কে/কখন টিক দিল
        redirect(cp_url($itemId, $month));

    } elseif ($action === 'zone') {
        // 🔑 স্থায়ী ডেলিভারি এলাকা (অর্ডারের ডেটায়) — একবার সেট করলে সব মাসে প্রযোজ্য।
        // মাস-ভিত্তিক ব্যতিক্রম আলাদা (courier_batches.delivery_zone) — এটা সেগুলো ছোঁয় না।
        $zoneVal = in_array($_POST['value'] ?? '', ['dhaka', 'near', 'outside'], true) ? $_POST['value'] : '';
        try {
            $db->prepare('UPDATE registrations SET delivery_zone = :v WHERE id = :id')->execute(['v' => $zoneVal, 'id' => $regId]);
            set_flash('success', 'ডেলিভারি এলাকা সেভ হয়েছে — পরের মাসগুলোতেও এটাই বসবে (আগে সেভ করা মাস অপরিবর্তিত)।');
        } catch (PDOException $ex) {
            set_flash('error', 'ডেলিভারি এলাকা সেভ করা যায়নি — database/migrate-reg-delivery-zone.sql চালানো হয়নি।');
        }
        redirect(cp_url($itemId, $month));

    } elseif ($action === 'active') {
        $val = (int) ($_POST['value'] ?? 0) ? 1 : 0;
        $db->prepare('UPDATE registrations SET courier_active = :v WHERE id = :id')->execute(['v' => $val, 'id' => $regId]);
        set_flash('success', $val ? 'সক্রিয় করা হলো।' : 'নিষ্ক্রিয় করা হলো — এই শিক্ষার্থী আর মাসের তালিকায় আসবে না।');
        redirect(cp_url($itemId, $month));

    } elseif ($action === 'ensure-ledger') {
        /* 📒 এই ব্যাচের যাদের খাতা নেই তাদের খাতা একসাথে বসানো — জমা ০।
         * 🔴 কেন দরকার: খাতা না থাকলে নিচের কালেকশন ফ্ল্যাট মাসিক ফি ধরে নেয় (রেজি ফি-র
         *    কিস্তি, ছাড়, advance কিছুই ধরে না)। খাতা বসলেই আসল বাকিটা বসে।
         * 🔴 আয় নড়ে না — `pay_ensure_plan()`-এর ঘরে বিস্তারিত; আর **আগে আয় অনুমোদিত
         *    অথচ খাতা নেই** এমন অর্ডার ইচ্ছাকৃতভাবে বাদ (ওগুলোর অঙ্ক পুরনো ভুল নিয়মে
         *    বসে থাকতে পারে — "আয় মেলানো" পাতায় অ্যাডমিন নিজে দেখে ঠিক করবেন)।
         * 🔴 খাতা আগে থেকে থাকলে কখনো ছোঁয়া হয় না (`pay_ensure_plan()` নিজেই গার্ড)। */
        $made = $skipped = 0;
        try {
            $db->beginTransaction();
            $rowsStmt = $db->prepare(
                "SELECT * FROM registrations
                 WHERE type = 'course' AND item_id = :id AND status = 'confirmed'
                 ORDER BY id"
            );
            $rowsStmt->execute(['id' => $itemId]);
            foreach ($rowsStmt->fetchAll() as $r) {
                if (!empty($r['income_approved'])) {
                    $skipped++;   // পুরনো নিয়মে বসানো আয় — হাতে দেখা দরকার
                    continue;
                }
                if (pay_ensure_plan($db, $r) > 0) {
                    $made++;
                    sync_income_for_status($db, $r, (string) $r['status']);
                }
            }
            $db->commit();
            set_flash('success', $made > 0
                ? '📒 ' . $made . ' জনের খাতা বসানো হয়েছে (জমা ০) — এখন কালেকশনে আসল বাকিটা বসবে।'
                    . ($skipped > 0 ? ' ' . $skipped . ' জন বাদ (আগে থেকে আয় বসানো — "আয় মেলানো" পাতায় দেখুন)।' : '')
                : 'সবার খাতা আগে থেকেই আছে — কিছু বদলানো হয়নি।'
                    . ($skipped > 0 ? ' ' . $skipped . ' জন বাদ (আগে থেকে আয় বসানো)।' : ''));
        } catch (PDOException $ex) {
            if ($db->inTransaction()) { $db->rollBack(); }
            set_flash('error', 'খাতা বসানো যায়নি — database/migrate-payment-ledger.sql চালানো হয়নি।');
        }
        redirect(cp_back_url($_POST['return_url'] ?? null, cp_url($itemId, $month)));

    } elseif ($action === 'parcels') {
        $n = max(0, min(60, (int) ($_POST['total_parcels'] ?? 0)));
        $db->prepare('UPDATE course_batches SET total_parcels = :n WHERE id = :id')->execute(['n' => $n, 'id' => $itemId]);
        set_flash('success', 'মোট পার্সেল সংখ্যা সেভ হয়েছে — ' . $n . ' টি মাস।');
        redirect(cp_url($itemId, min($month ?: 1, max($n, 1))));

    } elseif ($action === 'round-save') {
        $mode  = ($_POST['mode'] ?? 'draft') === 'send' ? 'send' : 'draft';
        if ($month < 1) { $month = 1; }
        $period = cp_month_label($month);
        $provider = ($mode === 'send') ? get_active_courier_provider() : null;
        if ($mode === 'send' && !$provider) {
            set_flash('error', 'কোনো সক্রিয় কুরিয়ার প্রোভাইডার সেট করা নেই (সেটিংস দেখুন)।');
            redirect(cp_url($itemId, $month));
        }

        // 🔴 `total_parcels`-ও লাগে — খাতার কোনো কিস্তির স্লট পার্সেল-সংখ্যার বাইরে পড়লে
        //    `pay_month_outstanding()` সেটাকে শেষ পার্সেলে ক্ল্যাম্প করে (নাহলে ঐ টাকাটা
        //    কোনো পার্সেলেই উঠত না)। নিচের ভিউ-শাখাতেও হুবহু একই সংখ্যা পাঠানো হয়।
        $feeStmt = $db->prepare('SELECT price, total_parcels FROM course_batches WHERE id = :id');
        $feeStmt->execute(['id' => $itemId]);
        $feeRow    = $feeStmt->fetch() ?: [];
        $courseFee = parse_price_to_number((string) ($feeRow['price'] ?? '0'));
        $slotMax   = max(0, (int) ($feeRow['total_parcels'] ?? 0));

        $rm = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' AND courier_active = 1");
        $rm->execute(['id' => $itemId]);
        $regMap = [];
        foreach ($rm->fetchAll() as $rr) { $regMap[(int) $rr['id']] = $rr; }

        // 🔑 খাতা → পার্সেল: এই মাসে কার কত টাকা বাকি (খাতা না থাকলে মাসিক ফি-ই ধরা হয়)
        $ledgerMap = pay_fetch_many($db, array_keys($regMap));

        $findBatch = $db->prepare('SELECT id, send_status FROM courier_batches WHERE registration_id = :r AND period_label = :p ORDER BY id DESC LIMIT 1');
        $insDraft = $db->prepare(
            'INSERT INTO courier_batches
                (registration_id, period_label, item_description, item_quantity, amount_to_collect,
                 monthly_multiplier, delivery_zone, weight_extra, adjustment, adjustment_reason, recipient_address, send_status)
             VALUES (:r, :p, :d, 1, :amt, 1, :z, :wx, :adj, :rs, :addr, "draft")'
        );
        $updDraft = $db->prepare(
            'UPDATE courier_batches SET item_description=:d, amount_to_collect=:amt, monthly_multiplier=1,
                 delivery_zone=:z, weight_extra=:wx, adjustment=:adj, adjustment_reason=:rs, recipient_address=:addr, send_status="draft"
             WHERE id = :id'
        );
        $insDeclined = $db->prepare('INSERT INTO courier_batches (registration_id, period_label, amount_to_collect, send_status) VALUES (:r, :p, 0, "declined")');
        $updDeclined = $db->prepare('UPDATE courier_batches SET send_status="declined", amount_to_collect=0 WHERE id = :id');
        // পাঠানো ব্যাচের ফিল্ড আপডেট (রেকর্ড সংশোধন) — send_status ছোঁয় না
        $updSent = $db->prepare('UPDATE courier_batches SET item_description=:d, amount_to_collect=:amt, delivery_zone=:z, weight_extra=:wx, adjustment=:adj, adjustment_reason=:rs, recipient_address=COALESCE(:addr, recipient_address) WHERE id = :id');

        // 💸 ছাড়/মাফ — অঙ্কটা পার্সেলের সারিতেই জমা থাকে (উৎস), আর বইয়ে একটা খরচ-সারি বসে।
        // 🔴 আলাদা ছোট UPDATE রাখা হলো (উপরের বড় স্টেটমেন্টগুলোতে কলাম যোগ করা হয়নি) —
        //    মাইগ্রেশন না চালানো থাকলেও পার্সেল সেভ করা যেন আগের মতোই চলে।
        $waiveReady = expense_waiver_ready($db);
        $updWaive = $waiveReady
            ? $db->prepare('UPDATE courier_batches SET waived_amount = :w, waived_reason = :wr WHERE id = :id')
            : null;

        // 🧾 "এই পার্সেলে কী কী তুলব" — একই কারণে আলাদা ছোট UPDATE (মাইগ্রেশনের আগে চুপচাপ বাদ)
        $partsReady = db_has_column($db, 'courier_batches', 'collect_parts');
        $updParts = $partsReady
            ? $db->prepare('UPDATE courier_batches SET collect_parts = :p WHERE id = :id')
            : null;

        $prep = 0; $declined = 0; $sent = 0; $resent = 0; $failed = []; $editedSent = 0;
        // ✅ হাতে পাঠানো / 💰 টাকা — ঘরগুলো এখন এই ফর্মেই, তাই গণনাও এখানেই
        $manualSent = 0; $manualNew = 0; $moneyOk = 0; $moneyTotal = 0.0; $moneyFail = [];
        $incBefore = 0.0;
        try { $incBefore = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM income')->fetch()['s'] ?? 0); }
        catch (PDOException $e) { /* বইয়ের মোট আয় দেখানো যাবে না — সেভ করা আটকাবে না */ }
        $waiveTotal = 0.0; $waiveSet = 0; $waiveCleared = 0;
        $holdCount = 0; $holdTotal = 0.0; $delivDue = 0; $delivCleared = 0;

        // পার্সেলের সারিতে অঙ্কটা লেখা + বইয়ের খরচ-সারি মিলিয়ে দেওয়া — এক জায়গায় (তিন শাখা থেকেই ডাকা হয়)
        $applyWaive = function (int $batchId, int $rid, float $w, string $wr)
                use ($updWaive, $db, $period, $regMap, &$waiveTotal, &$waiveSet, &$waiveCleared): void {
            if (!$updWaive || $batchId <= 0) { return; }
            $updWaive->execute(['w' => $w, 'wr' => $wr, 'id' => $batchId]);
            $res = expense_sync_parcel_waiver($db, $rid, $period, $w, $wr, (string) ($regMap[$rid]['customer_name'] ?? ''));
            if ($res === 'removed') { $waiveCleared++; }
            elseif ($res !== '')    { $waiveSet++; $waiveTotal += $w; }
        };

        /* 🧾 কোন কোন অংশ তোলা হচ্ছে সেটা সারিতে লেখা + ধরে রাখা ডেলিভারি চার্জ খাতায় বসানো।
         * 🔴 `$tokens === null` (ছক রেন্ডারই হয়নি / মাইগ্রেশন চলেনি) হলে কিচ্ছু বদলায় না। */
        $applyParts = function (int $batchId, int $rid, ?array $tokens, float $heldDeliv)
                use ($updParts, $db, $period, &$delivDue, &$delivCleared): void {
            if ($tokens === null) { return; }
            if ($updParts && $batchId > 0) {
                $updParts->execute(['p' => pay_parts_build($tokens), 'id' => $batchId]);
            }
            $res = cp_sync_delivery_due($db, $rid, $period, $heldDeliv);
            if ($res === 'removed')  { $delivCleared++; }
            elseif ($res !== '')     { $delivDue++; }
        };
        foreach (($_POST['bd'] ?? []) as $rid => $row) {
            $rid = (int) $rid;
            if (empty($row['present']) || !isset($regMap[$rid])) { continue; }
            $decision = ($row['decision'] ?? 'go') === 'no' ? 'no' : 'go';
            $resend   = !empty($row['resend']); // পাঠানো কার্ডে "আবার পাঠান" armed কিনা

            $findBatch->execute(['r' => $rid, 'p' => $period]);
            $existing = $findBatch->fetch();
            $existId = $existing ? (int) $existing['id'] : 0;
            $alreadySent = $existing && $existing['send_status'] === 'sent';

            // কালেকশন (নতুন/সংশোধন/আবার-পাঠানো সব ক্ষেত্রে দরকার)
            // এই মাসে আলাদা করে বাছা না থাকলে শিক্ষার্থীর স্থায়ী এলাকা, তাও না থাকলে ঢাকা
            $regZone = (string) ($regMap[$rid]['delivery_zone'] ?? '');
            $zone = in_array($row['zone'] ?? '', ['dhaka', 'near', 'outside'], true) ? $row['zone']
                  : (in_array($regZone, ['dhaka', 'near', 'outside'], true) ? $regZone : 'dhaka');
            $wx   = !empty($row['wx']);
            $adj  = (float) ($row['adj'] ?? 0);
            // 💸 এই পার্সেলে কত টাকা ছাড়/মাফ দেওয়া হলো — কালেকশন থেকে বাদ যায় **এবং** খরচে বসে।
            // 🔴 ঋণাত্মক নয় (ছাড় মানে দেওয়া, নেওয়া নয়) আর টাইপো-গার্ড হিসেবে উপরে সীমা।
            $waive = $waiveReady ? max(0.0, min(CP_MAX_WAIVE, round((float) ($row['waive'] ?? 0), 2))) : 0.0;
            $waiveReason = mb_substr(trim((string) ($row['waive_reason'] ?? '')), 0, 200);
            $ledgerRows = $ledgerMap[$rid] ?? [];
            // 🔴 এই মাসে খাতার **সব** কিস্তির বাকি মিলিয়ে (রেজি ফি + বেতন একসাথে পড়তে পারে;
            //    এক-কালীন/পুরনো সারি প্রথম পার্সেলে) — নিচের কার্ডের `data-due`-তেও হুবহু এই সংখ্যা
            /* 🧾 এই পার্সেলে কী কী তোলা হবে (২০২৬-১০-১৫) — কার্ডের ছক থেকে।
             * 🔴🔴 `parts_on` মার্কার না থাকলে `$tokens = null` = **আগের আচরণ হুবহু**
             *     (এই মাসের সব কিস্তি + ডেলিভারি)। ছকটা রেন্ডার না হলে, বা মাইগ্রেশন
             *     না চললে, বা বাসি পাতা থেকে POST এলে — সব ক্ষেত্রেই পুরনো পথ।
             * 🔴 সব টিক তুলে দিলে `$tokens = []` (খালি অ্যারে), `null` নয় — তখন ভিত্তি ০। */
            $tokens = (!empty($row['parts_on']) && $partsReady)
                ? pay_parts_parse(pay_parts_build(is_array($row['parts'] ?? null) ? $row['parts'] : []))
                : null;
            $picklist = $ledgerRows
                ? pay_picklist_drop_own_delivery(pay_month_picklist($ledgerRows, $month, $slotMax), $period)
                : [];
            $basis = $tokens === null
                ? ($ledgerRows ? pay_month_outstanding($ledgerRows, $month, $slotMax) : $courseFee)
                : pay_parts_basis($picklist, $tokens);
            // 🚚 ডেলিভারি চার্জও ধরে রাখা যায় — তখন জোন খালি পাঠিয়ে শেয়ার্ড ফর্মুলাটাই
            //    শূন্য চার্জ দেয় (হিসাব দুই জায়গায় লেখা হয় না)
            $delivOn   = pay_parts_delivery_on($tokens);
            $delivCost = courier_compute_collection(0, 1, $zone, $wx, 0);   // শুধু ডেলিভারি + ওজন
            $amt  = courier_compute_collection($basis, 1, $delivOn ? $zone : '', $delivOn && $wx, $adj);
            $amt  = max(0, $amt - $waive);                                     // 💸 ছাড় বাদ (০-এর নিচে নামে না)
            // ধরে রাখা ডেলিভারি চার্জ খাতায় বসবে (পার্সেল যাচ্ছে অথচ চার্জ তোলা হচ্ছে না)
            $heldDeliv = (!$delivOn && $decision === 'go') ? $delivCost : 0.0;
            if ($tokens !== null) {
                $heldAmt = pay_parts_basis($picklist, null) - $basis;   // খাতার যতটুকু আজ তোলা হচ্ছে না
                if ($heldAmt > 0.009 || $heldDeliv > 0.009) { $holdCount++; $holdTotal += max(0.0, $heldAmt) + $heldDeliv; }
            }
            if (is_numeric($row['amt'] ?? null)) { $amt = max(0, round((float) $row['amt'])); } // সরাসরি লেখা পরিমাণই চূড়ান্ত
            $desc   = trim($row['desc'] ?? '') ?: null;
            $reason = trim($row['reason'] ?? '') ?: null;
            // 📍 শুধু এই মাসের ঠিকানা (খালি = অর্ডারের মূল ঠিকানাই যাবে)।
            // courier_batches.recipient_address আগে থেকেই আছে — save_courier_batch() পাঠানোর সময়
            // এই সারির মানটাই অগ্রাধিকার দেয়, তাই নতুন কলাম/মাইগ্রেশন লাগে না।
            $addr   = trim($row['addr'] ?? '') ?: null;

            /* ══════ ✅ হাতে পাঠানো + 💰 টাকা মিলিয়ে নেওয়া ══════
             * 🔴🔴 এই ব্লকটা **`$alreadySent` শাখার আগে** — নাহলে আগে পাঠানো কার্ডে
             *     (যেটা আনলক ছাড়া `continue` হয়ে যায়) টাকাটা কখনো বসত না, অথচ ঠিক
             *     ওখানেই কুরিয়ারের তোলা টাকা মেলাতে হয়।
             * 🔴 টাকার ঘরটা কখনো নিজে থেকে ভরে না — অ্যাডমিন লেখেন বা চিপে ট্যাপ করেন। */
            $doneAmt = round((float) str_replace(',', '', (string) ($row['done_amt'] ?? '')), 2);
            if ($doneAmt < 0 || $doneAmt > CP_MAX_COLLECT) { $doneAmt = 0.0; }   // টাইপো-গার্ড
            // 🔴 "না এই মাসে" বলা থাকলে বা আগেই পাঠানো থাকলে টিকটা মানা হয় না
            //    (বাসি পাতা থেকে POST আসতে পারে — গার্ডটা সার্ভারেই)
            $doneSent = !empty($row['done_sent']) && $decision === 'go' && !$alreadySent;

            if ($doneAmt > 0.009) {
                try {
                    $db->beginTransaction();
                    $res = pay_collect($db, $regMap[$rid], $doneAmt, 'parcel');
                    if (!empty($res['ok'])) {
                        $db->commit();
                        $moneyOk++; $moneyTotal += $doneAmt;
                    } else {
                        $db->rollBack();
                        $moneyFail[] = ['name' => (string) $regMap[$rid]['customer_name'], 'reason' => (string) $res['err']];
                    }
                } catch (Throwable $e) {
                    if ($db->inTransaction()) { $db->rollBack(); }
                    $moneyFail[] = ['name' => (string) $regMap[$rid]['customer_name'],
                                    'reason' => 'ডাটাবেসে "registration_payments" টেবিলটি আছে কিনা দেখুন'];
                }
            }

            if ($doneSent) {
                // খসড়ার তথ্যটা (ঠিকানা/এলাকা/ছাড়) আগে সেভ করে নিই, তারপর "পাঠানো" ছাপ
                if ($existId) { $updDraft->execute(['d' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'addr' => $addr, 'id' => $existId]); }
                else {
                    // 🔑 এখানে পার্সেলের সারিই ছিল না (কুরিয়ারের সাইটে নিজে এন্ট্রি দেওয়ার কেস) — নতুন বসে
                    $insDraft->execute(['r' => $rid, 'p' => $period, 'd' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'addr' => $addr]);
                    $existId = (int) $db->lastInsertId();
                    $manualNew++;
                }
                $applyWaive($existId, $rid, $waive, $waiveReason);
                $applyParts($existId, $rid, $tokens, $heldDeliv);
                cp_mark_sent_manual($db, $existId);
                $manualSent++;
                continue;   // 🔴 আসল কুরিয়ারে কিছু যায় না — ডুপ্লিকেট চালান ঠেকাতে
            }

            // ── ইতিমধ্যে পাঠানো ব্যাচ: আনলক করা না থাকলে অপরিবর্তিত (দুর্ঘটনা এড়াতে) ──
            if ($alreadySent) {
                if (empty($row['unlocked'])) { continue; } // 🔓 আনলক না করলে ছোঁয়া হবে না
                $updSent->execute(['d' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'addr' => $addr, 'id' => $existId]);
                $applyWaive($existId, $rid, $waive, $waiveReason);
                $applyParts($existId, $rid, $tokens, $heldDeliv);
                if ($mode === 'send' && $resend) {
                    try {
                        $res = send_courier_batch($db, $provider, $regMap[$rid], [], $existId); // নতুন consignment
                        if (!empty($res['success'])) { $sent++; $resent++; }
                        else { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => (string) ($res['message'] ?? 'অজানা কারণ')]; }
                    } catch (Throwable $e) { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => $e->getMessage()]; }
                } else {
                    $editedSent++; // শুধু তথ্য আপডেট, আবার পাঠানো হয়নি
                }
                continue;
            }

            if ($decision === 'no') {
                if ($existId) { $updDeclined->execute(['id' => $existId]); }
                else { $insDeclined->execute(['r' => $rid, 'p' => $period]); $existId = (int) $db->lastInsertId(); }
                // 🔴 এই মাসে পার্সেলই যাচ্ছে না — তাই ছাড়ও ০, আগের খরচ-সারি থাকলে মুছে যাবে
                $applyWaive($existId, $rid, 0.0, '');
                // 🔴 পার্সেলই যাচ্ছে না — ধরে রাখা ডেলিভারি চার্জও থাকে না (আগে বসানো থাকলে মুছে যায়)
                $applyParts($existId, $rid, $tokens, 0.0);
                $declined++;
                continue;
            }

            if ($existId) {
                $updDraft->execute(['d' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'addr' => $addr, 'id' => $existId]);
                $draftId = $existId;
            } else {
                $insDraft->execute(['r' => $rid, 'p' => $period, 'd' => $desc, 'amt' => $amt, 'z' => $zone, 'wx' => $wx ? 1 : 0, 'adj' => $adj, 'rs' => $reason, 'addr' => $addr]);
                $draftId = (int) $db->lastInsertId();
            }
            $applyWaive($draftId, $rid, $waive, $waiveReason);
            $applyParts($draftId, $rid, $tokens, $heldDeliv);
            $prep++;

            if ($mode === 'send') {
                try {
                    $res = send_courier_batch($db, $provider, $regMap[$rid], [], $draftId);
                    if (!empty($res['success'])) { $sent++; }
                    else { $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => (string) ($res['message'] ?? 'অজানা কারণ')]; }
                } catch (Throwable $e) {
                    $failed[] = ['name' => $regMap[$rid]['customer_name'], 'reason' => $e->getMessage()];
                }
            }
        }

        // 💰 টাকা বসে থাকলে বইয়ের মোট আয় আগে → পরে (টাকা জড়িত, তাই সবসময় বলা হয়)
        $incLine = '';
        if ($moneyOk) {
            $incAfter = (float) ($db->query('SELECT COALESCE(SUM(amount), 0) AS s FROM income')->fetch()['s'] ?? 0);
            $incLine  = 'বইয়ের মোট আয় ৳' . number_format($incBefore, 2) . ' → ৳' . number_format($incAfter, 2) . '।';
        }

        if ($mode === 'send') {
            $_SESSION['cp_result'] = ['item' => $itemId, 'period' => cp_month_display($month), 'sent' => $sent, 'resent' => $resent, 'prep' => $prep, 'declined' => $declined, 'failed' => $failed, 'edited' => $editedSent,
                                      'waive_set' => $waiveSet, 'waive_total' => $waiveTotal, 'waive_cleared' => $waiveCleared,
                                      'manual' => $manualSent, 'money_ok' => $moneyOk, 'money_total' => $moneyTotal,
                                      'money_fail' => $moneyFail, 'income_line' => $incLine];
        } else {
            $bits = [];
            if ($prep)       { $bits[] = $prep . ' জন প্রস্তুত'; }
            if ($declined)   { $bits[] = $declined . ' জন "না"'; }
            if ($editedSent) { $bits[] = $editedSent . ' জন (পাঠানো) তথ্য আপডেট'; }
            // ✅ হাতে পাঠানো / 💰 টাকা — আলাদা করে বলা হয় (এগুলোই ইউজার খুঁজছিলেন)
            if ($manualSent)    { $bits[] = '✅ ' . $manualSent . ' জনের পার্সেল "হাতে পাঠানো" ধরা হলো (কোনো চালান তৈরি হয়নি)'; }
            if ($manualNew)     { $bits[] = $manualNew . ' জনের পার্সেল এখানে তৈরিই ছিল না — নতুন করে বসানো হলো'; }
            if ($moneyOk)       { $bits[] = '💰 ' . $moneyOk . ' জনের ৳' . number_format($moneyTotal) . ' খাতায় জমা বসেছে'; }
            // 💸 ছাড় দিলে/তুলে নিলে খরচের বইয়ে কী হলো — স্পষ্ট করে বলা হয় (টাকা জড়িত)
            if ($waiveSet)     { $bits[] = $waiveSet . ' জনের ছাড় ৳' . number_format($waiveTotal) . ' খরচে যোগ হয়েছে'; }
            if ($waiveCleared) { $bits[] = $waiveCleared . ' জনের ছাড় খরচ থেকে বাদ গেছে'; }
            // 🧾 কুরিয়ারে না পাঠিয়ে খাতায় বাকি রেখে দেওয়া টাকা
            if ($holdCount)    { $bits[] = '🧾 ' . $holdCount . ' জনের ৳' . number_format($holdTotal) . ' এই পার্সেলে তোলা হয়নি — খাতায় বাকি থাকল'; }
            if ($delivDue)     { $bits[] = $delivDue . ' জনের ডেলিভারি চার্জ খাতায় বাকি হিসেবে বসেছে'; }
            if ($delivCleared) { $bits[] = $delivCleared . ' জনের ডেলিভারি-চার্জের বাকি খাতা থেকে বাদ গেছে'; }
            if ($moneyFail) {
                $names = implode(', ', array_map(static fn(array $f): string => $f['name'] . ' (' . $f['reason'] . ')', $moneyFail));
                $bits[] = '⚠️ টাকা বসানো যায়নি — ' . $names;
            }
            $tail = ' (কুরিয়ারে কিছু পাঠানো হয়নি)' . ($incLine !== '' ? ' ' . $incLine : '');
            set_flash($bits ? ($moneyFail ? 'error' : 'success') : 'error',
                $bits ? (cp_month_display($month) . ' — ' . implode(', ', $bits) . '।' . $tail)
                      : 'কিছু করা হয়নি — অন্তত একজন সক্রিয় শিক্ষার্থী থাকতে হবে।');
        }
        redirect(cp_url($itemId, $month));
    }

    redirect('course-parcel.php');
}

$itemId = (int) ($_GET['item_id'] ?? 0);
require __DIR__ . '/includes/layout-top.php';

// ---------------- কোর্স-ব্যাচ পিকার (hide_parcel বাদ) ----------------
if (!$itemId) {
    // 🔎 `act_cnt`/`done_cnt` শুধু "আর কত পার্সেল বাকি" দেখানোর জন্য (২০২৬-১০-২০)।
    // 🔴 বাকির হিসাবে শুধু **সক্রিয়** শিক্ষার্থী, আর "না এই মাসে" (declined) কখনো
    //    বাকি নয় — ম্যানেজ পাতার `$cpMonthStat`-এর হুবহু একই নিয়ম।
    // ⚠️ `done_cnt` সব period মিলিয়ে গোনা হয়, তাই অ্যাডমিন পরে মোট পার্সেল-সংখ্যা **কমিয়ে**
    //    দিলে সীমার বাইরের পুরনো চালানও গোনা হতে পারে — তাই `max(0, …)` দিয়ে ক্ল্যাম্প করা।
    $batches = $db->query(
        "SELECT r.item_id, r.item_title, r.batch, COUNT(DISTINCT r.id) c, cb.total_parcels,
                COUNT(DISTINCT CASE WHEN r.courier_active = 1 THEN r.id END) act_cnt,
                SUM(CASE WHEN cbz.send_status = 'sent' THEN 1 ELSE 0 END) sent_cnt,
                SUM(CASE WHEN r.courier_active = 1 AND cbz.send_status IN ('sent', 'declined')
                         THEN 1 ELSE 0 END) done_cnt
         FROM registrations r
         JOIN course_batches cb ON cb.id = r.item_id AND cb.hide_parcel = 0
         LEFT JOIN courier_batches cbz ON cbz.registration_id = r.id
         WHERE r.type='course' AND r.status='confirmed'
         GROUP BY r.item_id, r.item_title, r.batch, cb.total_parcels
         ORDER BY r.item_title, r.batch"
    )->fetchAll();
    ?>
    <div class="max-w-3xl">
        <p class="text-sm text-gray-500 mb-4">যে কোর্স-ব্যাচের পার্সেল ম্যানেজ করবেন বেছে নিন — গ্রুপে যোগ, মাস অনুযায়ী পার্সেল প্রস্তুত+পাঠানো, প্রগ্রেস সব এক জায়গায়। <span class="text-gray-400">("পার্সেল হাইড" করা কোর্স এখানে আসে না।)</span></p>
        <?php if (!$batches): ?>
            <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="package" class="w-8 h-8"></i></div>পার্সেল যায় এমন কোনো confirmed কোর্স রেজিস্ট্রেশন নেই।</div>
        <?php else: ?>
            <?php // 🔎 কোন ব্যাচে কত পার্সেল এখনো যায়নি — আগে ভেতরে ঢুকে প্রতিটা মাস
                  //    ঘুরে দেখা ছাড়া বোঝার উপায় ছিল না (ইউজারের চাওয়া)।
                  // 🔴 স্লট = সক্রিয় শিক্ষার্থী × মোট মাস; মোট মাস সেট না থাকলে হিসাব
                  //    করার কিছু নেই, তাই তখন "—" দেখানো হয় (অনুমান করা হয় না)।
                  $cpLeftAll = 0;
                  foreach ($batches as $bRow) {
                      $slots = (int) $bRow['total_parcels'] * (int) $bRow['act_cnt'];
                      if ($slots > 0) { $cpLeftAll += max(0, $slots - (int) $bRow['done_cnt']); }
                  } ?>
            <?php if ($cpLeftAll > 0): ?>
                <div class="mb-3 px-3 py-2 rounded-xl bg-amber-50 border border-amber-200 text-sm text-amber-800">
                    📦 সব মিলিয়ে <b><?= (int) $cpLeftAll ?> টি পার্সেল</b> এখনো পাঠানো হয়নি — নিচের
                    <b>"বাকি"</b> ঘরে কোন ব্যাচে কত বাকি দেখা যাচ্ছে। "ম্যানেজ করুন" চাপলে
                    <b>প্রথম যে মাসে কাজ বাকি</b> সেটাই খুলবে।
                </div>
            <?php endif; ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
                <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                    <th class="py-3 px-4">কোর্স</th><th class="py-3 px-4">ব্যাচ</th><th class="py-3 px-4">শিক্ষার্থী</th>
                    <th class="py-3 px-4">মোট মাস</th><th class="py-3 px-4">পাঠানো</th><th class="py-3 px-4">বাকি</th><th class="py-3 px-4">অ্যাকশন</th>
                </tr></thead>
                <tbody>
                <?php foreach ($batches as $b):
                    $slots  = (int) $b['total_parcels'] * (int) $b['act_cnt'];
                    $cpLeft = $slots > 0 ? max(0, $slots - (int) $b['done_cnt']) : -1;
                ?>
                    <tr class="border-b last:border-0">
                        <td class="py-2.5 px-4 font-semibold text-gray-800"><?= e($b['item_title']) ?></td>
                        <td class="py-2.5 px-4"><span class="px-2 py-0.5 rounded-lg bg-indigo-50 text-indigo-700 text-xs font-semibold"><?= e($b['batch'] ?: '—') ?></span></td>
                        <td class="py-2.5 px-4"><?= (int) $b['c'] ?> জন</td>
                        <td class="py-2.5 px-4"><?= (int) $b['total_parcels'] ? (int) $b['total_parcels'] : '<span class="text-gray-300">সেট করা হয়নি</span>' ?></td>
                        <td class="py-2.5 px-4"><?= (int) $b['sent_cnt'] ? '<span class="text-green-700 font-semibold">' . (int) $b['sent_cnt'] . ' টি</span>' : '<span class="text-gray-300">—</span>' ?></td>
                        <td class="py-2.5 px-4"><?php if ($cpLeft < 0): ?><span class="text-gray-300" title="মোট কয়বার পার্সেল যাবে সেটা সেট করা হয়নি">—</span>
                            <?php elseif ($cpLeft === 0): ?><span class="text-green-700 font-semibold">✓ সব হয়ে গেছে</span>
                            <?php else: ?><span class="px-2 py-0.5 rounded-lg bg-amber-100 text-amber-800 text-xs font-bold" title="<?= (int) $b['act_cnt'] ?> জন × <?= (int) $b['total_parcels'] ?> মাস = <?= (int) $slots ?> টি পার্সেলের মধ্যে <?= (int) $cpLeft ?> টি এখনো যায়নি">📦 <?= (int) $cpLeft ?> টি বাকি</span><?php endif; ?></td>
                        <td class="py-2.5 px-4"><a href="course-parcel.php?item_id=<?= (int) $b['item_id'] ?>" class="text-indigo-600 font-semibold inline-block py-1">ম্যানেজ করুন →</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ---------------- নির্দিষ্ট কোর্স-ব্যাচ ----------------
// `cb.*` ইচ্ছাকৃত — খাতার ছক (`pay_plan_from_batch()`) বানাতে ব্যাচের সব ঘরই লাগে, আর
// নতুন কলাম মাইগ্রেশনের আগে না থাকলেও কোয়েরি ভাঙে না। ⚠️ `course_batches`-এ `title` নেই
// (ওটা `courses`-এ), তাই `c.title` ঢাকা পড়ে না।
$cStmt = $db->prepare('SELECT cb.*, c.title
                       FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id');
$cStmt->execute(['id' => $itemId]);
$course = $cStmt->fetch();
$totalParcels = (int) ($course['total_parcels'] ?? 0);
$courseFee = parse_price_to_number($course['price'] ?? '0');

$months = max($totalParcels, 0);
$selMonth = (int) ($_GET['month'] ?? 1);
if ($months > 0) { $selMonth = max(1, min($selMonth, $months)); } else { $selMonth = 0; }
$selLabel = $selMonth ? cp_month_label($selMonth) : '';

$regs = $db->prepare("SELECT * FROM registrations WHERE type='course' AND item_id = :id AND status='confirmed' ORDER BY customer_name");
$regs->execute(['id' => $itemId]);
$regs = $regs->fetchAll();

$byRegPeriod = [];
if ($regs) {
    $ids = array_map(fn($r) => (int) $r['id'], $regs);
    $in = implode(',', array_fill(0, count($ids), '?'));
    // 🔴 ছাড়ের ঘর দুটো শর্তসাপেক্ষে — মাইগ্রেশন না চালানো থাকলে কলামই নেই, সরাসরি চাইলে পুরো পাতা ভাঙত
    $cbWaiveCols = expense_waiver_ready($db) ? ', waived_amount, waived_reason' : '';
    $cbPartsCol  = db_has_column($db, 'courier_batches', 'collect_parts') ? ', collect_parts' : '';
    $st = $db->prepare("SELECT id, registration_id, period_label, send_status, amount_to_collect,
                               delivery_zone, weight_extra, adjustment, adjustment_reason, recipient_address
                               $cbWaiveCols $cbPartsCol
                        FROM courier_batches WHERE registration_id IN ($in) ORDER BY id ASC");
    $st->execute($ids);
    foreach ($st->fetchAll() as $row) { $byRegPeriod[(int) $row['registration_id']][$row['period_label']] = $row; }
}

$notesByReg = fetch_registration_notes($db, array_map(fn($r) => (int) $r['id'], $regs));
$ledgerByReg = pay_fetch_many($db, array_map(fn($r) => (int) $r['id'], $regs)); // 🔑 খাতা (কালেকশনের ভিত্তি)
$activeRegs   = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) === 1));

// 📒 কাদের খাতা এখনো বসানো হয়নি — খাতা না থাকলে নিচের কালেকশন ফ্ল্যাট মাসিক ফি ধরে নেয়,
//    তাই উপরে একটা বাল্ক বোতাম দেখানো হয়। 🔴 আগে আয় অনুমোদিত অথচ খাতা নেই — এমনগুলো
//    গোনা হয় না (ওগুলোর অঙ্ক পুরনো ভুল নিয়মে বসে থাকতে পারে, "আয় মেলানো"-তেই দেখতে হবে)।
$noLedgerCount = 0;
foreach ($regs as $r) {
    if (empty($ledgerByReg[(int) $r['id']] ?? []) && empty($r['income_approved'])) {
        $noLedgerCount++;
    }
}
// 🧩 কাদের খাতা **পুরনো ছকে** বসে আছে — সেভ করা খাতা নিজে থেকে কখনো বদলায় না, তাই
//    ব্যাচের সেটিংস (বা কোডের অনুমানের নিয়ম) পরে বদলালে পুরনো খাতায় বেতনের মাসিক
//    কিস্তিগুলোই থাকে না — একটাই সারি ("পুরো কোর্স ফি" / পুরনো legacy হিসাব) পড়ে থাকে,
//    আর তখন কালেকশনে মাস অনুযায়ী ভাগ দেখা যায় না (২০২৬-১০-১৩ ইউজারের রিপোর্ট)।
// 🔴 এটা নিছক **ইঙ্গিত, কিছু বদলায় না** — অ্যাডমিন অর্ডারে গিয়ে "🧩 কিস্তির ছকে সাজান"
//    চাপেন (বাল্কে করা হয় না: ওখানে হাতে লেখা কিস্তি/নাম/ছাড় হারানোর ঝুঁকি আছে)।
// 🔴 শর্তটা ইচ্ছাকৃতভাবে সংকীর্ণ — ব্যাচের ছকে বেতনের কিস্তি আছে অথচ খাতায় একটাও নেই।
//    কিস্তি-সংখ্যা কম থাকা (tuition_installments) অ্যাডমিনের নিজের সিদ্ধান্ত, তাই ধরা হয় না।
$offPlanRegs = [];
try {
    $planMonthly = 0;
    foreach (pay_plan_from_batch($course ?: []) as $pr) {
        if (($pr['kind'] ?? '') === 'monthly') { $planMonthly++; }
    }
    if ($planMonthly > 0) {
        foreach ($regs as $r) {
            $lr = $ledgerByReg[(int) $r['id']] ?? [];
            if (!$lr) { continue; }
            $has = false;
            foreach ($lr as $lrow) {
                if (($lrow['kind'] ?? '') === 'monthly') { $has = true; break; }
            }
            if (!$has) { $offPlanRegs[] = $r; }
        }
    }
} catch (Throwable $e) {
    $offPlanRegs = [];
}

$inactiveRegs = array_values(array_filter($regs, fn($r) => (int) ($r['courier_active'] ?? 1) !== 1));
$pendingGroupRemoval = array_values(array_filter($inactiveRegs, fn($r) => !empty($r['fb_group_added']) || !empty($r['messenger_group_added'])));

// 💸 ছাড়/মাফের ঘর দেখানো যাবে কিনা (মাইগ্রেশন চালানো হয়েছে কিনা)
$cpWaiveReady = expense_waiver_ready($db);
// 🧾 "এই পার্সেলে কী কী তুলব" ছকটা দেখানো যাবে? (মাইগ্রেশন না চললে চুপচাপ বাদ)
$cpPartsReady = db_has_column($db, 'courier_batches', 'collect_parts');

// 🔎🔎 কোন মাসে কার পার্সেল এখনো যায়নি — মাস-ট্যাবের ব্যাজ, কার্ডের চিপ আর
//    ডিফল্ট মাস তিনটাই **এই একটাই হিসাব** পড়ে (তিন জায়গায় তিন নিয়ম রাখলে সংখ্যা মিলত না)।
// 🔴 শুধু **সক্রিয়** শিক্ষার্থী গোনা হয় (নিষ্ক্রিয়দের পার্সেল এমনিতেও যাবে না), আর
//    "না এই মাসে" (declined) কখনো "বাকি" নয় — অ্যাডমিন সিদ্ধান্ত নিয়েই ফেলেছেন।
// 🔴 ব্যর্থ (failed) ও খসড়া (draft) পার্সেল **বাকি**-ই — ওগুলো এখনো যায়নি।
$cpMonthStat = [];
for ($i = 1; $i <= $months; $i++) {
    $lbl = cp_month_label($i);
    $cSent = $cNo = 0;
    foreach ($activeRegs as $r) {
        $ss = (string) ($byRegPeriod[(int) $r['id']][$lbl]['send_status'] ?? '');
        if ($ss === 'sent') { $cSent++; } elseif ($ss === 'declined') { $cNo++; }
    }
    $cpMonthStat[$i] = ['sent' => $cSent, 'no' => $cNo,
                        'pending' => max(0, count($activeRegs) - $cSent - $cNo)];
}
$cpFirstPending = 0;
foreach ($cpMonthStat as $mi => $ms) { if ($ms['pending'] > 0) { $cpFirstPending = $mi; break; } }
$cpLeftTotal = 0;
foreach ($cpMonthStat as $ms) { $cpLeftTotal += $ms['pending']; }

// 🔴 `?month=` না থাকলে **প্রথম যে মাসে পার্সেল বাকি** সেখানেই নামা হয় (ইউজারের চাওয়া —
//    "ম্যানেজ গেলে পাঠানো বাকিটা সহজে খুঁজে পাওয়া যাবে")। ঠিকানায় মাস লেখা থাকলে সেটাই
//    জেতে — ট্যাব ও সেভের redirect সবসময় মাস বয়ে নেয়, তাই কাজের মাঝপথে নিজে থেকে মাস
//    বদলে যাওয়ার ভয় নেই।
if (!isset($_GET['month']) && $cpFirstPending > 0) {
    $selMonth = $cpFirstPending;
    $selLabel = cp_month_label($selMonth);
}

// 🔖 এই মাসের অবস্থা + কার্ডের চিপ ডিফল্টে কোনটা
$cpStat = $cpMonthStat[$selMonth] ?? ['sent' => 0, 'no' => 0, 'pending' => count($activeRegs)];
// 🔴 আগে কিছু পাঠানো/না-করা থাকলেই চিপ দেখানো হয় ও ডিফল্টে "পাঠানো বাকি" — কিছুই
//    করা না থাকলে সবাই বাকি, তখন চিপ শুধু ভিড় করত।
$cpChips    = ($cpStat['sent'] + $cpStat['no']) > 0;
$cpChipMode = ($cpChips && $cpStat['pending'] > 0) ? 'pending' : 'all';

$periodHasExisting = false;
if ($selLabel) { foreach ($activeRegs as $r) { if (isset($byRegPeriod[(int) $r['id']][$selLabel])) { $periodHasExisting = true; break; } } }

$sendResult = null;
if (!empty($_SESSION['cp_result']) && (int) $_SESSION['cp_result']['item'] === $itemId) {
    $sendResult = $_SESSION['cp_result']; unset($_SESSION['cp_result']);
}

// মাস-সেল আইকন (ম্যাট্রিক্স)
function cp_cell(?array $b): array
{
    $s = $b['send_status'] ?? 'empty';
    $map = [
        'sent'     => ['✓', 'bg-green-500 text-white', 'পাঠানো'],
        'draft'    => ['●', 'bg-amber-400 text-white', 'প্রস্তুত'],
        'declined' => ['✕', 'bg-red-500 text-white', 'না'],
        'failed'   => ['!', 'bg-orange-500 text-white', 'ব্যর্থ'],
        'empty'    => ['', 'border-2 border-gray-200 bg-white', 'বাকি'],
    ];
    return $map[$s] ?? $map['empty'];
}
?>
<style>
.cp-hd{display:flex;align-items:center;gap:.5rem;margin-bottom:.6rem}
.cp-hd .bar{width:4px;height:18px;border-radius:2px;background:rgb(var(--c-primary))}
/* 📱 "অবস্থা" তালিকাটা ফোনে ভাঁজ করা থাকে (২০২৬-১০-১৫, ইউজার: "উপরে আলাদা করে দরকার নেই")
   — রোজকার কাজ "এই মাসের পার্সেল" আগে ৭,২৬৩px (৮.৬ স্ক্রিন) নিচে শুরু হতো।
   🔴 HTML-এ `open` থাকে, বন্ধ করে নিচের ছোট স্ক্রিপ্ট শুধু ছোট পর্দায় — তাই JS না চললেও
      আগের আচরণই (খোলা) থাকে, আর ডেস্কটপ হুবহু অপরিবর্তিত। */
#cpRoster > summary{cursor:pointer;list-style:none}
#cpRoster > summary::-webkit-details-marker{display:none}
.cp-fold-hint{margin-left:auto;white-space:nowrap}
#cpRoster[open] .cp-fold-show{display:none}
#cpRoster:not([open]) .cp-fold-hide{display:none}
.cp-tab{flex-shrink:0;padding:.5rem .9rem;border-radius:.6rem .6rem 0 0;font-weight:600;font-size:.875rem;border-bottom:3px solid transparent;white-space:nowrap}
.cp-tab.on{background:#fff;border-color:rgb(var(--c-primary));color:rgb(var(--c-primary));box-shadow:0 -1px 3px rgba(0,0,0,.05)}
.cp-tab.off{background:#f3f4f6;color:#6b7280}
.cp-tab.off:hover{background:#e5e7eb}
/* 🔎 "পাঠানো বাকি" চোখে পড়ার ব্যবস্থা (২০২৬-১০-২০, ইউজার: "পাঠানো বাকি যেটা সেটা
   সহজে খুজে পাওয়া যায় তেমন একটা ব্যবস্থা রাখো") — মাস-ট্যাবের ব্যাজ ও কার্ডের চিপ।
   🔴 সাধারণ CSS-এ, তাই Tailwind রিবিল্ড লাগে না। */
.cp-tab-due{display:inline-block;margin-left:.3rem;padding:0 .35rem;border-radius:9999px;background:#FDE68A;color:#92400E;font-size:.7rem;font-weight:800;vertical-align:middle}
.cp-tab.on .cp-tab-due{background:#D97706;color:#fff}
.cp-tab-ok{margin-left:.3rem;color:#15803D;font-weight:800}
.cp-chips{display:flex;flex-wrap:wrap;gap:.4rem}
.cp-chip{flex-shrink:0;padding:.35rem .75rem;border-radius:9999px;font-size:.8rem;font-weight:600;background:#f3f4f6;color:#4b5563;border:1px solid #e5e7eb;line-height:1.5}
.cp-chip b{font-weight:800}
.cp-chip:hover{background:#e5e7eb}
.cp-chip-due{background:#FEF3C7;color:#92400E;border-color:#FDE68A}
.cp-chip.on{background:rgb(var(--c-primary));color:#fff;border-color:rgb(var(--c-primary))}
.cp-chip-due.on{background:#D97706;border-color:#D97706;color:#fff}
.cp-toggle{display:inline-flex;width:26px;height:26px;border-radius:9999px;align-items:center;justify-content:center;transition:.15s}
</style>

<div class="mb-3"><a href="course-parcel.php" class="inline-flex items-center gap-1 text-indigo-600 font-semibold text-sm py-1"><i data-lucide="arrow-left" class="w-4 h-4"></i> সব কোর্স-ব্যাচ</a></div>

<?php // ── হেডার + মোট পার্সেল ── ?>
<div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
    <div class="flex items-start justify-between gap-3 flex-wrap">
        <div class="min-w-0">
            <div class="text-xs text-gray-400">কোর্স · ব্যাচ</div>
            <h1 class="text-lg font-black text-gray-800 break-words"><?= e($course['title'] ?? '') ?> <span class="text-indigo-600 font-semibold text-sm">— <?= e($course['batch_name'] ?? '') ?></span></h1>
            <div class="text-xs text-gray-400 mt-0.5">মান্থলি ফি ৳<?= e(number_format($courseFee)) ?> · প্রতি মাসে এক পার্সেল</div>
            <?php // 📊 এই ব্যাচের আয়-ব্যয় মিলিয়ে দেখার সরাসরি পথ (ইউজারের চাওয়া "আয় ব্যয় মেলানো যাবে")।
                  // 🔴 `fin_link_ready()` false হলে (migrate-finance-course-link.sql চলেনি) লিংকগুলো
                  //    দেখানো হয় না — ওখানে ফিল্টারটাই কাজ করত না। ?>
            <?php if (admin_can('finance') && fin_link_ready($db)): ?>
                <div class="flex flex-wrap items-center gap-2 mt-2" style="font-size:12px">
                    <span class="text-gray-400">📊 এই ব্যাচের:</span>
                    <a href="income.php?item=<?= $itemId ?>" class="text-green-700 font-semibold underline">আয়</a>
                    <a href="expenses.php?item=<?= $itemId ?>" class="text-red-600 font-semibold underline">ব্যয়</a>
                    <a href="finance.php" class="text-indigo-600 font-semibold underline">লাভ-ক্ষতি</a>
                </div>
            <?php endif; ?>
        </div>
        <form method="post" action="course-parcel.php" class="flex items-end gap-2 flex-shrink-0"
              onsubmit="return <?= $totalParcels ? "confirmSubmit(this, 'মোট পার্সেল সংখ্যা বদলাচ্ছেন — এটা প্রতি শিক্ষার্থীর মাসের সংখ্যা বদলে দেবে। নিশ্চিত?', 'সংখ্যা বদলাবেন?')" : 'true' ?>;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="parcels"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="month" value="<?= $selMonth ?>">
            <label class="text-xs text-gray-600 font-semibold">মোট কয়বার<br>পার্সেল?<input type="number" name="total_parcels" min="0" max="60" value="<?= $totalParcels ?>" class="block w-20 border rounded-lg px-3 py-1.5 text-sm mt-1 font-bold"></label>
            <button type="submit" class="bg-indigo-600 text-white text-sm font-semibold px-3 py-2 rounded-lg">সেভ</button>
        </form>
    </div>
</div>

<?php // ── পাঠানোর ফলাফল প্যানেল ── ?>
<?php if ($sendResult): $sr = $sendResult; $hasFail = !empty($sr['failed']) || !empty($sr['money_fail']); ?>
    <div class="rounded-2xl shadow p-4 mb-4 border <?= $hasFail ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200' ?>">
        <div class="flex items-start justify-between gap-2">
            <div class="font-bold <?= $hasFail ? 'text-red-800' : 'text-green-800' ?>"><?= $hasFail ? '⚠️' : '✓' ?> <?= e($sr['period']) ?> — পাঠানোর ফলাফল</div>
            <a href="<?= e(cp_url($itemId, $selMonth)) ?>" class="text-xs text-gray-500 hover:text-gray-800">✕ বন্ধ</a>
        </div>
        <div class="flex flex-wrap gap-2 mt-2 text-sm">
            <span class="px-2 py-1 rounded-lg bg-green-100 text-green-800 font-semibold">✓ সফল: <?= (int) $sr['sent'] ?><?= !empty($sr['resent']) ? ' (আবার-পাঠানো ' . (int) $sr['resent'] . ')' : '' ?></span>
            <?php if ($hasFail): ?><span class="px-2 py-1 rounded-lg bg-red-100 text-red-800 font-semibold">✕ ব্যর্থ: <?= count($sr['failed']) ?></span><?php endif; ?>
            <?php if (!empty($sr['edited'])): ?><span class="px-2 py-1 rounded-lg bg-gray-100 text-gray-700 font-semibold">তথ্য আপডেট: <?= (int) $sr['edited'] ?></span><?php endif; ?>
            <?php // 💸 ছাড় দিলে সেটা খরচের বইয়ে গেছে — টাকা জড়িত বলে ফলাফলেও বলা হয় ?>
            <?php if (!empty($sr['waive_set'])): ?><span class="px-2 py-1 rounded-lg bg-amber-100 text-amber-800 font-semibold">💸 ছাড় খরচে: <?= (int) $sr['waive_set'] ?> জন · ৳<?= e(number_format((float) $sr['waive_total'])) ?></span><?php endif; ?>
            <?php if (!empty($sr['waive_cleared'])): ?><span class="px-2 py-1 rounded-lg bg-gray-100 text-gray-700 font-semibold">ছাড় বাদ: <?= (int) $sr['waive_cleared'] ?> জন</span><?php endif; ?>
            <?php // ✅ হাতে পাঠানো ধরা হয়েছে এমন কার্ডে আসল কুরিয়ারে কিছু যায়নি — স্পষ্ট করে বলা হয় ?>
            <?php if (!empty($sr['manual'])): ?><span class="px-2 py-1 rounded-lg bg-green-100 text-green-800 font-semibold">✅ হাতে পাঠানো ধরা হলো: <?= (int) $sr['manual'] ?> (চালান তৈরি হয়নি)</span><?php endif; ?>
            <?php if (!empty($sr['money_ok'])): ?><span class="px-2 py-1 rounded-lg bg-green-100 text-green-800 font-semibold">💰 খাতায় জমা: <?= (int) $sr['money_ok'] ?> জন · ৳<?= e(number_format((float) $sr['money_total'])) ?></span><?php endif; ?>
        </div>
        <?php if (!empty($sr['income_line'])): ?><p class="text-xs text-gray-600 mt-2"><?= e($sr['income_line']) ?></p><?php endif; ?>
        <?php if (!empty($sr['money_fail'])): ?>
            <div class="mt-3 text-sm text-red-900"><div class="font-semibold mb-1">যাদের টাকা খাতায় বসানো যায়নি:</div>
                <ul class="list-disc pl-5 space-y-1"><?php foreach ($sr['money_fail'] as $mf): ?><li><span class="font-semibold"><?= e($mf['name']) ?></span> — <?= e($mf['reason']) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <?php if (!empty($sr['failed'])): ?>
            <div class="mt-3 text-sm text-red-900"><div class="font-semibold mb-1">যাদের পাঠানো যায়নি (কারণ সহ):</div>
                <ul class="list-disc pl-5 space-y-1"><?php foreach ($sr['failed'] as $f): ?><li><span class="font-semibold"><?= e($f['name']) ?></span> — <?= e($f['reason']) ?></li><?php endforeach; ?></ul>
                <p class="text-xs text-red-700 mt-2">এদের ব্যাচ "প্রস্তুত" অবস্থায় আছে — কারণ ঠিক করে আবার পাঠাতে পারবেন।</p>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if (!$regs): ?>
    <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div>এই ব্যাচে কোনো confirmed রেজিস্ট্রেশন নেই।</div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit; ?>
<?php endif; ?>

<?php // ── নিষ্ক্রিয় কিন্তু এখনো গ্রুপে — সতর্কতা ── ?>
<?php if ($pendingGroupRemoval): ?>
    <div class="rounded-2xl shadow p-4 mb-4 bg-red-50 border border-red-200">
        <div class="font-bold text-red-800">⚠ <?= count($pendingGroupRemoval) ?> জন নিষ্ক্রিয় শিক্ষার্থী এখনো গ্রুপে আছে</div>
        <p class="text-sm text-red-900 mt-1">এদের FB/Messenger গ্রুপ থেকে বাদ দিন, তারপর নিচের "নিষ্ক্রিয় শিক্ষার্থী" অংশে গিয়ে সেই টিক তুলে দিন — তাহলে বোঝা যাবে কাজটা হয়ে গেছে।</p>
        <div class="flex flex-wrap gap-1.5 mt-2">
            <?php foreach ($pendingGroupRemoval as $pr): ?>
                <span class="text-xs px-2 py-1 rounded-lg bg-white border border-red-200 text-red-800 font-semibold"><?= e($pr['customer_name']) ?><?= !empty($pr['fb_group_added']) ? ' · FB' : '' ?><?= !empty($pr['messenger_group_added']) ? ' · Msngr' : '' ?></span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php // ══════════ Section 1 — শিক্ষার্থী × মাস ম্যাট্রিক্স ══════════
      // 🔴 পুরো সেকশনটা `<details id="cpRoster" open>`-এ — ফোনে নিচের স্ক্রিপ্ট এটা বন্ধ করে
      //    দেয় (ডেস্কটপে খোলাই থাকে)। গ্রুপ/সক্রিয়/এলাকার ফর্মগুলো আলাদা ছোট ফর্ম, আর
      //    `roundForm` এই অংশের বাইরে — তাই ভাঁজ করায় কোনো সেভ ক্ষতিগ্রস্ত হয় না। ?>
<details id="cpRoster" open>
<summary class="cp-hd"><span class="bar"></span><span class="text-sm font-bold text-gray-700">অবস্থা</span><span class="text-xs text-gray-400">গ্রুপে যোগ · সক্রিয়/নিষ্ক্রিয় · কোন মাসে কী</span><span class="cp-fold-hint text-xs text-indigo-600 font-semibold"><span class="cp-fold-show">▼ দেখুন</span><span class="cp-fold-hide">▲ লুকান</span></span></summary>

<?php
$grp_toggle = function (array $r, string $field, bool $on, int $itemId, int $selMonth) {
    $lbl = $field === 'messenger' ? 'Messenger' : 'FB';
    ?>
    <form method="post" action="course-parcel.php" class="inline" onsubmit="return <?= $on ? "confirmSubmit(this, '$lbl গ্রুপ থেকে টিক তুলে ফেলবেন? (এই শিক্ষার্থী গ্রুপে নেই বলে চিহ্নিত হবে)', 'টিক তুলবেন?')" : 'true' ?>;"><?= csrf_field() ?>
        <input type="hidden" name="action" value="group"><input type="hidden" name="field" value="<?= $field ?>"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="month" value="<?= $selMonth ?>"><input type="hidden" name="value" value="<?= $on ? 0 : 1 ?>">
        <button type="submit" title="<?= $lbl ?> গ্রুপে যোগ হয়েছে?" class="cp-toggle <?= $on ? 'bg-green-500' : 'border-2 border-gray-300 hover:border-green-400' ?>"><?php if ($on): ?><i data-lucide="check" class="w-4 h-4 text-white"></i><?php endif; ?></button>
    </form>
    <?php
};

$render_row = function (array $r) use ($byRegPeriod, $months, $selMonth, $itemId, $grp_toggle, $zoneLabels) {
    $rid = (int) $r['id'];
    $active = (int) ($r['courier_active'] ?? 1);
    $fbOn = !empty($r['fb_group_added']); $msgOn = !empty($r['messenger_group_added']);
    $regZone = (string) ($r['delivery_zone'] ?? '');
    $needsRemoval = !$active && ($fbOn || $msgOn);
    $sentCount = 0;
    for ($i = 1; $i <= $months; $i++) { if (($byRegPeriod[$rid][cp_month_label($i)]['send_status'] ?? '') === 'sent') { $sentCount++; } }
    ?>
    <tr class="cp-row border-b last:border-0 hover:bg-gray-50 <?= $active ? '' : 'opacity-70' ?>"
        data-s="<?= e(cp_haystack($r)) ?>" data-d="<?= e(cp_haystack_digits($r)) ?>">
        <td class="py-2.5 px-3" style="min-width:150px;vertical-align:top;">
            <?php // 🔴 তিন লাইন একটাই মোড়কে — মোবাইল কার্ড-লেআউটে `td` flex হয়ে যায়, খোলা রাখলে
                  //    নাম/ফেসবুক/ফোন পাশাপাশি তিন কলামে চেপে যেত (স্ক্রিনশটে ধরা) ?>
            <div class="min-w-0">
                <div class="font-bold text-gray-900 text-base leading-snug"><span class="text-xs text-gray-400 font-normal">শিশু:</span> <?= e($r['customer_name']) ?></div>
                <div class="text-sm text-blue-600 leading-snug break-words"><span class="text-gray-400">ফেসবুক:</span> <?= e($r['facebook_id'] ?: '—') ?></div>
                <div class="text-sm text-gray-500 font-mono break-all"><?= e($r['phone']) ?></div>
                <?php if ($needsRemoval): ?><div class="text-sm text-red-600 font-semibold mt-0.5">⚠ গ্রুপ থেকে বাদ দিন</div><?php endif; ?>
            </div>
        </td>
        <td class="py-2.5 px-2 text-center"><?php $grp_toggle($r, 'fb', $fbOn, $itemId, $selMonth); ?></td>
        <td class="py-2.5 px-2 text-center"><?php $grp_toggle($r, 'messenger', $msgOn, $itemId, $selMonth); ?></td>
        <td class="py-2.5 px-2 text-center">
            <form method="post" action="course-parcel.php" class="inline" onsubmit="return confirmSubmit(this, <?= $active ? "'নিষ্ক্রিয় করলে এই শিক্ষার্থী আর মাসের তালিকায় আসবে না। নিশ্চিত?', 'নিষ্ক্রিয় করবেন?'" : "'আবার সক্রিয় করবেন?', 'সক্রিয় করবেন?'" ?>);"><?= csrf_field() ?>
                <input type="hidden" name="action" value="active"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="month" value="<?= $selMonth ?>"><input type="hidden" name="value" value="<?= $active ? 0 : 1 ?>">
                <button type="submit" class="px-2.5 py-1 rounded-full text-xs font-semibold <?= $active ? 'bg-green-100 text-green-700' : 'bg-gray-200 text-gray-500' ?>"><?= $active ? 'সক্রিয়' : 'নিষ্ক্রিয়' ?></button>
            </form>
        </td>
        <td class="py-2.5 px-2 text-sm text-gray-600" style="min-width:180px;max-width:300px;white-space:normal;vertical-align:top;">
            <?php // 📍 ডেলিভারি এলাকা বাছার আগে ঠিকানাটা দেখা দরকার — তাই পাশেই (ইউজারের চাওয়া) ?>
            <?= e(trim((string) ($r['address'] ?? '')) ?: '—') ?>
        </td>
        <td class="py-2.5 px-2 text-center">
            <?php // 🔑 স্থায়ী এলাকা — একবার সেট করলেই প্রতি মাসের কালেকশনে অটো বসে ?>
            <form method="post" action="course-parcel.php" class="inline"><?= csrf_field() ?>
                <input type="hidden" name="action" value="zone"><input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="id" value="<?= $rid ?>"><input type="hidden" name="month" value="<?= $selMonth ?>">
                <select name="value" data-orig="<?= e($regZone) ?>" onchange="cpPermZone(this)" class="border rounded-lg px-2 py-1 text-xs text-gray-700" title="এই শিক্ষার্থীর স্থায়ী ডেলিভারি এলাকা — সব মাসে প্রযোজ্য">
                    <option value="" <?= $regZone === '' ? 'selected' : '' ?>>— সেট করা নেই —</option>
                    <?php foreach ($zoneLabels as $zk => $zl): ?>
                        <option value="<?= $zk ?>" <?= $regZone === $zk ? 'selected' : '' ?>><?= e($zl) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </td>
        <?php for ($i = 1; $i <= $months; $i++): [$ch, $cls] = cp_cell($byRegPeriod[$rid][cp_month_label($i)] ?? null); ?>
            <td class="py-2.5 px-1.5 text-center <?= $i === $selMonth ? 'bg-indigo-50' : '' ?>">
                <span class="inline-flex w-6 h-6 items-center justify-center rounded-full text-[11px] font-bold <?= $cls ?>" title="মাস <?= $i ?>"><?= $ch ?></span>
            </td>
        <?php endfor; ?>
        <?php if ($months): ?><td class="py-2.5 px-2 text-center text-xs font-semibold text-gray-500 whitespace-nowrap"><?= $sentCount ?>/<?= $months ?></td><?php endif; ?>
    </tr>
    <?php
};
?>

<?php if (!$months): ?>
    <div class="p-4 mb-6 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl">
        <i data-lucide="alert-triangle" class="w-4 h-4 inline"></i> উপরে <b>"মোট কয়বার পার্সেল"</b> সংখ্যাটা দিন — তারপর মাস অনুযায়ী পার্সেল প্রস্তুত করা যাবে।
    </div>
<?php endif; ?>

<script>
// স্থায়ী ডেলিভারি এলাকা বদলানো — প্রথমবার সেট করায় ওয়ার্নিং নয়, পরে বদলালে নিশ্চিতকরণ
function cpPermZone(sel) {
    var orig = sel.getAttribute('data-orig') || '';
    if (orig === '') { sel.form.submit(); return; }   // প্রথমবার সেট — ওয়ার্নিং লাগে না
    var now = sel.value, txt = sel.options[sel.selectedIndex].text;
    sel.value = orig;                                  // নিশ্চিত না করা পর্যন্ত আগেরটাই দেখাবে
    showConfirmModal('এই শিক্ষার্থীর স্থায়ী ডেলিভারি এলাকা বদলে "' + txt + '" করবেন? পরের মাসগুলোর কালেকশনে এটাই বসবে (আগে সেভ করা মাস অপরিবর্তিত থাকবে)।',
        function () { sel.value = now; sel.form.submit(); },
        'এলাকা বদলাবেন?');
}
</script>

<?php // ══════ 🔎 তালিকায় খুঁজুন — নাম / মোবাইল / ফেসবুক / ঠিকানা ══════
      // 🔴 এই বাক্সটা থাকলে `layout-bottom.php`-এর জেনেরিক অটো-সার্চ এই পাতায় বসে না
      //    (গার্ড-লিস্টে `#cpSearchBox` যোগ করা আছে) — নাহলে তিনটা টেবিলে তিনটা আলাদা
      //    বাক্স বসত, আর সেটা সারির ড্রপডাউনের অপশনও "মিল" ধরত।
      // 🔴 নিষ্ক্রিয়দের ভাঁজ-করা অংশে মিল পেলে সেটা নিজে থেকে খুলে যায় — নাহলে
      //    "পাওয়া যায়নি" মনে হতো যদিও মানুষটা ওখানেই ছিল। ?>
<?php if (count($regs) >= CP_SEARCH_MIN): ?>
    <div id="cpSearchBox" class="mb-3">
        <div class="list-search-wrap">
            <i data-lucide="search" class="w-4 h-4"></i>
            <input type="search" id="cpRosterSearch" class="list-search" autocomplete="off"
                   aria-label="তালিকায় খুঁজুন" placeholder="তালিকায় খুঁজুন — নাম, মোবাইল, ফেসবুক, ঠিকানা...">
        </div>
        <p id="cpRosterNote" class="text-xs text-gray-500 mt-1 hidden"></p>
    </div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow overflow-x-auto mb-3">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
            <th class="py-3 px-3">শিশুর নাম</th><th class="py-3 px-2 text-center">FB</th><th class="py-3 px-2 text-center">Msngr</th><th class="py-3 px-2 text-center">সক্রিয়</th><th class="py-3 px-2">ঠিকানা</th><th class="py-3 px-2 text-center whitespace-nowrap">ডেলিভারি</th>
            <?php for ($i = 1; $i <= $months; $i++): ?>
                <th class="py-3 px-1.5 text-center whitespace-nowrap <?= $i === $selMonth ? 'bg-indigo-50' : '' ?>"><a href="<?= e(cp_url($itemId, $i)) ?>" class="<?= $i === $selMonth ? 'text-indigo-700 font-bold' : 'text-gray-500 hover:text-indigo-600' ?>" title="এই মাসে কাজ করুন">মাস <?= $i ?></a></th>
            <?php endfor; ?>
            <?php if ($months): ?><th class="py-3 px-2 text-center whitespace-nowrap">পাঠানো</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php if (!$activeRegs && $inactiveRegs): ?><tr><td colspan="<?= 7 + $months ?>" class="py-6 px-4 text-center text-gray-400">সব শিক্ষার্থী নিষ্ক্রিয় — নিচে থেকে সক্রিয় করুন।</td></tr><?php endif; ?>
        <?php foreach ($activeRegs as $r) { $render_row($r); } ?>
        </tbody>
    </table>
</div>
<?php if ($months): ?>
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 mb-6 px-1">
        <span><span class="inline-block w-3 h-3 rounded-full bg-green-500 align-middle"></span> পাঠানো</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-amber-400 align-middle"></span> প্রস্তুত</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-red-500 align-middle"></span> না</span>
        <span><span class="inline-block w-3 h-3 rounded-full bg-orange-500 align-middle"></span> ব্যর্থ</span>
        <span><span class="inline-block w-3 h-3 rounded-full border-2 border-gray-200 align-middle"></span> বাকি</span>
    </div>
<?php else: ?><div class="mb-6"></div><?php endif; ?>

<?php if ($inactiveRegs): ?>
    <details id="cpInactive" class="mb-6" <?= $pendingGroupRemoval ? 'open' : '' ?>>
        <summary class="cursor-pointer select-none text-sm font-semibold <?= $pendingGroupRemoval ? 'text-red-600' : 'text-gray-500' ?> py-2 px-1 flex items-center gap-1.5">
            <i data-lucide="chevron-right" class="w-4 h-4"></i> নিষ্ক্রিয় শিক্ষার্থী (<?= count($inactiveRegs) ?> জন)<?= $pendingGroupRemoval ? ' — ' . count($pendingGroupRemoval) . ' জন এখনো গ্রুপে (বাদ দিন)' : ' — দেখতে/আবার সক্রিয় করতে ক্লিক করুন' ?>
        </summary>
        <div class="bg-white rounded-2xl shadow overflow-x-auto mt-2"><table class="w-full text-sm"><tbody>
        <?php foreach ($inactiveRegs as $r) { $render_row($r); } ?>
        </tbody></table></div>
    </details>
<?php endif; ?>

</details>
<script>
// 📱 ফোনে "অবস্থা" তালিকাটা ভাঁজ করা থাকে — ইউজার রোজ যেটা করেন ("এই মাসের পার্সেল")
// সেটাই যেন প্রথম পর্দাতেই থাকে। ডেস্কটপে কিছুই বদলায় না।
// 🔴 স্ক্রিপ্ট ভেঙে গেলেও তালিকাটা **খোলাই** থাকে (HTML-এ `open`) — নিরাপদ দিক।
(function () {
    try {
        var d = document.getElementById('cpRoster');
        if (d && window.matchMedia('(max-width: 767px)').matches) { d.open = false; }
    } catch (e) {}
})();
</script>

<?php // ══════════ Section 2 — মাস ট্যাব + এই মাসের পার্সেল ══════════ ?>
<?php if ($months): ?>
    <div class="cp-hd"><span class="bar"></span><span class="text-sm font-bold text-gray-700">এই মাসের পার্সেল</span><span class="text-xs text-gray-400">মাস বেছে প্রস্তুত করুন / পাঠান</span></div>

    <?php if ($noLedgerCount > 0): ?>
        <?php // খাতা না বসা থাকলে কালেকশন ফ্ল্যাট মাসিক ফি ধরে — এক ট্যাপে সবার খাতা বসিয়ে নেওয়া যায় ?>
        <form method="post" action="course-parcel.php" class="bg-amber-50 border border-amber-200 rounded-2xl p-3 mb-3 flex flex-wrap items-center gap-2"
              onsubmit="return confirmSubmit(this, 'এই ব্যাচের <?= (int) $noLedgerCount ?> জনের টাকার খাতা কোর্সের সেটিংস (রেজি ফি, মাস, কিস্তি) অনুযায়ী বসানো হবে — জমা ০। আয় বদলাবে না। চালিয়ে যাবেন?', 'খাতা বসানো')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ensure-ledger">
            <input type="hidden" name="item_id" value="<?= $itemId ?>">
            <input type="hidden" name="month" value="<?= $selMonth ?>">
            <span class="text-sm text-amber-900 min-w-0" style="flex:1 1 16rem">
                📒 <b><?= (int) $noLedgerCount ?> জনের টাকার খাতা এখনো বসানো হয়নি</b> — তাই তাদের কালেকশনে
                আসল বাকির বদলে <b>ফ্ল্যাট মাসিক ফি</b> ধরা হচ্ছে (রেজি ফি-র কিস্তি, ছাড়, advance ধরা হচ্ছে না)।
            </span>
            <button class="bg-amber-600 text-white px-4 py-2 rounded-xl text-sm font-bold">📒 সবার খাতা বসান</button>
        </form>
    <?php endif; ?>

    <?php if ($offPlanRegs): ?>
        <?php // 🧩 খাতা আছে কিন্তু বেতনের মাসিক কিস্তি নেই — তাই মাস অনুযায়ী ভাগ দেখা যায় না।
              // 🔴 এখান থেকে কিছুই বদলানো হয় না (বাল্ক রিবিল্ড নেই — হাতে লেখা কিস্তি হারাতে পারে),
              //    শুধু কোন অর্ডারগুলোতে যেতে হবে সেটা দেখানো হয়। ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-3 mb-3">
            <p class="text-sm text-amber-800">
                🧩 <b><?= count($offPlanRegs) ?> জনের খাতায় বেতনের মাসিক কিস্তি নেই</b> — পুরনো ছকে (এক সারিতে) বসে আছে,
                তাই তাদের কালেকশনে <b>মাস অনুযায়ী ভাগ</b> দেখা যাবে না। নিচের নামে ক্লিক করে অর্ডারের
                <b>“টাকা”</b> ড্রয়ার খুলে <b>“🧩 কিস্তির ছকে সাজান”</b> চাপুন — <b>মোট জমা ও আয় বদলাবে না</b>।
            </p>
            <?php // ⚠️ `text-amber-900`/`mr-1` কম্পাইলড অ্যাডমিন CSS-এ নেই — তাই amber-800 + flex gap
                  //    (Tailwind রিবিল্ড এড়াতে) ?>
            <div class="flex flex-wrap gap-1 mt-2">
                <?php foreach ($offPlanRegs as $opr): ?>
                    <a href="registrations.php?action=view&amp;id=<?= (int) $opr['id'] ?>" target="_blank" rel="noopener"
                       class="text-xs font-semibold text-amber-800 bg-white border border-amber-200 rounded-lg px-2 py-1"><?= e((string) $opr['customer_name']) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="flex gap-1 overflow-x-auto pb-0 mb-0 border-b border-gray-200">
        <?php // 🔴 ট্যাবেই "কত বাকি" লেখা থাকে — আগে প্রতিটা মাসে ঢুকে দেখতে হতো
              //    কোন মাসের কাজ পড়ে আছে (ইউজারের চাওয়া)। সংখ্যা `$cpMonthStat` থেকেই। ?>
        <?php for ($i = 1; $i <= $months; $i++): $ms = $cpMonthStat[$i] ?? null; ?>
            <a href="<?= e(cp_url($itemId, $i)) ?>" class="cp-tab <?= $i === $selMonth ? 'on' : 'off' ?>">মাস <?= $i ?> <?php
                if ($ms && $ms['pending'] > 0): ?><span class="cp-tab-due" title="<?= (int) $ms['pending'] ?> জনের পার্সেল এখনো যায়নি"><?= (int) $ms['pending'] ?> বাকি</span><?php
                elseif ($ms && ($ms['sent'] + $ms['no']) > 0): ?><span class="cp-tab-ok" title="এই মাসে আর কারও পার্সেল বাকি নেই">✓</span><?php
                endif; ?></a>
        <?php endfor; ?>
    </div>

    <?php if (!$activeRegs): ?>
        <div class="bg-white rounded-2xl shadow empty-state mt-3"><div class="empty-ic"><i data-lucide="user-x" class="w-8 h-8"></i></div>কোনো সক্রিয় শিক্ষার্থী নেই — উপরে থেকে সক্রিয় করলে এখানে আসবে।</div>
    <?php else: ?>
        <?php // ══════ 🔖 এই মাসের কার্ড — অবস্থার চিপ + 🔎 খোঁজা ══════
              // 🔴 ফর্মের **বাইরে** — ভেতরে দিলে ইনপুটে Enter চাপলেই ব্রাউজার `roundForm`
              //    সাবমিট করে বসত, অর্থাৎ ভুল করে পুরো মাসের পার্সেল সেভ হয়ে যেত।
              // 🔴🔴 কার্ড লুকানো হয় `display:none` দিয়ে, `disabled` দিয়ে নয় — লুকানো কার্ডের
              //    ঘরগুলোও সাবমিট হয়, তাই চিপ/খোঁজা কখনো সেভ বা পাঠানোর ফলাফল বদলায় না।
              // 🔴 চিপ ও সার্চ **একটাই দৃশ্যমানতার নিয়ম** মানে (নিচের `cardApply()`), আর
              //    ইঙ্গিতের লাইনও একটাই — আলাদা রাখলে একটা আরেকটাকে ঢেকে দিত। ?>
        <div class="mb-3">
            <?php if ($cpChips): ?>
                <div id="cpChips" class="cp-chips" data-mode="<?= e($cpChipMode) ?>">
                    <?php if ($cpStat['pending']): ?>
                        <button type="button" class="cp-chip cp-chip-due<?= $cpChipMode === 'pending' ? ' on' : '' ?>" data-mode="pending">📦 পাঠানো বাকি <b><?= (int) $cpStat['pending'] ?></b></button>
                    <?php endif; ?>
                    <?php if ($cpStat['sent']): ?>
                        <button type="button" class="cp-chip" data-mode="sent">✓ পাঠানো <b><?= (int) $cpStat['sent'] ?></b></button>
                    <?php endif; ?>
                    <?php if ($cpStat['no']): ?>
                        <button type="button" class="cp-chip" data-mode="no">⊘ না এই মাসে <b><?= (int) $cpStat['no'] ?></b></button>
                    <?php endif; ?>
                    <button type="button" class="cp-chip<?= $cpChipMode === 'all' ? ' on' : '' ?>" data-mode="all">সবাই <b><?= count($activeRegs) ?></b></button>
                    <?php if (!$cpStat['pending']): ?>
                        <span class="text-xs text-green-700 font-semibold self-center">✓ এই মাসে আর কারও পার্সেল বাকি নেই</span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if (count($activeRegs) >= CP_SEARCH_MIN): ?>
                <div class="list-search-wrap<?= $cpChips ? ' mt-2' : '' ?>">
                    <i data-lucide="search" class="w-4 h-4"></i>
                    <input type="search" id="cpCardSearch" class="list-search" autocomplete="off"
                           aria-label="এই মাসের কার্ডে খুঁজুন" placeholder="এই মাসের কার্ডে খুঁজুন — নাম, মোবাইল, ফেসবুক, ঠিকানা...">
                </div>
            <?php endif; ?>
            <p id="cpCardNote" class="text-xs text-gray-500 mt-1 hidden"></p>
        </div>

        <form method="post" id="roundForm" action="course-parcel.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="round-save"><input type="hidden" name="mode" id="roundMode" value="draft">
            <input type="hidden" name="item_id" value="<?= $itemId ?>"><input type="hidden" name="month" value="<?= $selMonth ?>">

            <div class="bg-indigo-50 border border-indigo-100 rounded-b-xl rounded-tr-xl px-3 py-2 mb-3 mt-0 text-sm text-indigo-800">
                <b>মাস <?= $selMonth ?></b>-এর পার্সেল। সবাই ডিফল্টে <b>"✓ যাবে"</b> — কেউ এই মাসে না নিলে তার <b>"না এই মাসে"</b> চাপুন।
                <?php if ($periodHasExisting): ?><span class="block text-xs text-indigo-600 mt-0.5">এই মাসের কিছু আগে থেকেই আছে — বদলালে নিশ্চিতকরণ চাইবে।</span><?php endif; ?>
            </div>

            <?php if (!$cpWaiveReady): ?>
                <div class="p-3 mb-3 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-xl">
                    💸 <b>ছাড় / মাফ খরচে নেওয়ার ঘরটা এখনো চালু হয়নি।</b>
                    phpMyAdmin-এ <code class="bg-white px-1 rounded">database/migrate-parcel-waiver.sql</code> একবার চালালেই আসবে।
                    ততক্ষণ পার্সেল আগের মতোই কাজ করবে।
                </div>
            <?php endif; ?>

            <div class="space-y-3 pb-32">
            <?php foreach ($activeRegs as $r):
                $rid = (int) $r['id'];
                $ex = $byRegPeriod[$rid][$selLabel] ?? null;
                $isNo = $ex && $ex['send_status'] === 'declined';
                $isSent = $ex && $ex['send_status'] === 'sent';
                $isFailed = $ex && $ex['send_status'] === 'failed';
                // 🔑 জোনের ক্রম: এই মাসে আগে সেভ করা → শিক্ষার্থীর স্থায়ী এলাকা → ঢাকা
                // (সার্ভারের round-save-এও হুবহু এই ক্রম — দুই জায়গা মিলিয়ে রাখতে হবে)
                $permZone = (string) ($r['delivery_zone'] ?? '');
                if (!in_array($permZone, ['dhaka', 'near', 'outside'], true)) { $permZone = ''; }
                $exZone = $ex['delivery_zone'] ?? ($permZone ?: 'dhaka');
                $exWx = !empty($ex['weight_extra']);
                // 📍 এই মাসে আলাদা ঠিকানা? (মূল ঠিকানার সমান হলে "আলাদা" নয়)
                $regAddr = trim((string) ($r['address'] ?? ''));
                $exAddr  = trim((string) ($ex['recipient_address'] ?? ''));
                $addrDiff = $exAddr !== '' && $exAddr !== $regAddr;
                $exAdj = $ex['adjustment'] ?? 0; $exReason = $ex['adjustment_reason'] ?? '';
                // 💸 আগে দেওয়া ছাড় (মাইগ্রেশনের আগে ঘরদুটো কোয়েরিতেই আসে না — তাই ?? 0)
                $exWaive  = (float) ($ex['waived_amount'] ?? 0);
                $exWaiveR = (string) ($ex['waived_reason'] ?? '');
                $exAmt = ($ex && $ex['amount_to_collect'] !== null && $ex['send_status'] !== 'declined') ? (string) (int) round((float) $ex['amount_to_collect']) : '';
                $notes = $notesByReg[$rid] ?? [];

                // 🔑 খাতা → এই মাসের কালেকশনের ভিত্তি
                $lRows  = $ledgerByReg[$rid] ?? [];
                // 🔴 এই মাসে একাধিক কিস্তি পড়তে পারে — সবগুলোর বাকি যোগ হয় (উপরের round-save-এ একই হিসাব)
                $lMonth = $lRows ? pay_rows_for_month($lRows, $selMonth, $totalParcels) : [];
                $lDue   = $lRows ? pay_month_outstanding($lRows, $selMonth, $totalParcels) : (float) $courseFee;   // খাতা না থাকলে পুরনো নিয়ম
                $lSum   = $lRows ? pay_summary($lRows) : null;
                // 🧾 এই পার্সেলে কী কী তোলা যায় + আগে কী বেছে দেওয়া হয়েছিল
                $pick       = $lRows
                    ? pay_picklist_drop_own_delivery(pay_month_picklist($lRows, $selMonth, $totalParcels), cp_month_label($selMonth))
                    : [];
                $savedParts = $cpPartsReady ? pay_parts_parse((string) ($ex['collect_parts'] ?? '')) : null;
                $delivOnCard = pay_parts_delivery_on($savedParts);
                // 💰 "হাতে কত টাকা এলো" চিপের অঙ্ক — ধরে রাখা কিস্তি বাদ দিয়ে (নাহলে
                //    যতটা তোলাই হয়নি ততটা জমা বসানোর পরামর্শ দিত)
                $chipDue = $savedParts === null ? $lDue : pay_parts_basis($pick, $savedParts);
                // সব কিস্তিই ⊘ বাদ দেওয়া থাকলেই "বাদ" বার্তা
                $lSkip  = $lMonth && count(array_filter($lMonth, 'pay_is_skipped')) === count($lMonth);
            ?>
                <?php // 🔖 `data-st` = চিপের ছাঁকনির চাবি। 🔴 ব্যর্থ/খসড়া দুটোই "pending" —
                      //    ওগুলো এখনো যায়নি, তাই "পাঠানো বাকি"-তেই থাকা উচিত ($cpMonthStat-এর
                      //    হিসাবের সাথে হুবহু এক, নাহলে চিপের সংখ্যা আর কার্ডের সংখ্যা মিলত না)। ?>
                <div class="stu bg-white rounded-2xl shadow p-4 <?= $isNo ? 'opacity-60' : '' ?>" data-fee="<?= (int) $courseFee ?>" data-due="<?= (int) round($lDue) ?>" data-sent="<?= $isSent ? 1 : 0 ?>" data-waive0="<?= (int) round($exWaive) ?>" data-st="<?= $isSent ? 'sent' : ($isNo ? 'no' : 'pending') ?>"
                     data-s="<?= e(cp_haystack($r)) ?>" data-d="<?= e(cp_haystack_digits($r)) ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][present]" value="1">
                    <input type="hidden" name="bd[<?= $rid ?>][decision]" class="decision" value="<?= $isNo ? 'no' : 'go' ?>">
                    <input type="hidden" name="bd[<?= $rid ?>][unlocked]" class="unlocked" value="">
                    <input type="hidden" name="bd[<?= $rid ?>][resend]" class="resend" value="">
                    <div class="flex items-start gap-2.5 mb-3">
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-gray-900 text-base break-words"><span class="text-xs text-gray-400 font-normal">শিশু:</span> <?= e($r['customer_name']) ?></div>
                            <div class="text-sm text-blue-600 break-words"><span class="text-gray-400">ফেসবুক:</span> <?= e($r['facebook_id'] ?: '—') ?></div>
                            <div class="text-sm text-gray-600">রিসিভার: <?= e($r['receiver_name'] ?: $r['customer_name']) ?> · <?= e($r['receiver_phone'] ?: $r['phone']) ?></div>
                            <div class="text-sm text-gray-600 break-words">ঠিকানা: <?= e($r['address'] ?: '—') ?></div>
                        </div>
                        <?php if ($isSent): ?>
                            <div class="flex-shrink-0 flex flex-col items-end gap-1">
                                <span class="text-xs px-2.5 py-1.5 rounded-lg bg-green-100 text-green-700 font-semibold">✓ পাঠানো</span>
                                <button type="button" class="unlockBtn text-xs font-semibold px-2.5 py-1 rounded-lg border border-gray-300 text-gray-600 hover:bg-gray-100">🔓 আনলক</button>
                                <button type="button" class="resendBtn text-xs font-semibold px-2.5 py-1 rounded-lg border border-indigo-300 text-indigo-700 bg-indigo-50 hidden">↻ আবার পাঠান</button>
                            </div>
                        <?php else: ?>
                            <button type="button" class="goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border"></button>
                        <?php endif; ?>
                    </div>
                    <?php if ($isFailed): ?><div class="text-sm text-orange-700 bg-orange-50 rounded px-2 py-1.5 mb-2">গতবার পাঠানো ব্যর্থ হয়েছিল — আবার চেষ্টা করতে পারেন।</div><?php endif; ?>
                    <?php if ($notes): ?><div class="flex flex-wrap items-center gap-1.5 mb-3"><?php render_note_chips($notes); ?></div><?php endif; ?>
                    <?php
                    // লেখা মন্তব্য দুটো — খাতার নোট (অ্যাডমিনের) ও রেজিস্ট্রেশন ফর্মে অভিভাবকের লেখা।
                    // টাকা ঠিক করার সময় "কেন এমন" বোঝার জন্য এখানেই দেখানো (ভেতরে ঢুকতে হয় না)।
                    $ledgerNote = trim((string) ($r['admin_note'] ?? ''));
                    $formNote   = trim((string) ($r['notes'] ?? ''));
                    ?>
                    <?php // 🔴 অ্যাম্বার বাক্স, ধূসর নয় (২০২৬-১০-১৩, ইউজারের চাওয়া) — আগে ধূসরে
                          //    কার্ডের বাকি লেখার সাথে মিশে যেত, নোট আছে কিনা চোখেই পড়ত না। ?>
                    <?php if ($ledgerNote !== '' || $formNote !== ''): ?>
                        <div class="text-sm bg-amber-50 border border-amber-200 text-amber-800 rounded-lg px-2.5 py-2 mb-2 break-words"
                             style="box-shadow:inset 3px 0 0 #F59E0B">
                            <?php if ($ledgerNote !== ''): ?>
                                <div>📝 <b>নোট:</b> <?= e($ledgerNote) ?></div>
                            <?php endif; ?>
                            <?php if ($formNote !== ''): ?>
                                <div class="<?= $ledgerNote !== '' ? 'mt-0.5' : '' ?>">🗒️ <b>অভিভাবকের মন্তব্য:</b> <?= e($formNote) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php // 🔑 খাতার অবস্থা — কালেকশনের টাকা এখান থেকেই বসে ?>
                    <div class="text-sm rounded-lg px-2.5 py-2 mb-2 <?= $lRows ? 'bg-indigo-50 text-indigo-800' : 'bg-amber-50 text-amber-800' ?>">
                        <?php if (!$lRows): ?>
                            💰 <b>খাতা নেই</b> — মাসিক ফি ৳<?= e(number_format($courseFee)) ?> ধরা হয়েছে। অর্ডার তালিকার “খাতা” থেকে সেভ করলে এখানে আসল বাকি বসবে।
                        <?php elseif ($lSkip): ?>
                            💰 খাতায় এই মাস <b>⊘ বাদ</b> দেওয়া আছে — বাকি ৳0।
                        <?php elseif (!$lMonth): ?>
                            💰 খাতায় এই মাসের কিস্তি নেই — বাকি ৳0 ধরা হয়েছে (দরকার হলে হাতে লিখুন)।
                        <?php else: ?>
                            <?php // এই পার্সেলে যতগুলো কিস্তি পড়ে, প্রতিটা আলাদা লাইনে ?>
                            <?php foreach ($lMonth as $lr): $lrDue = pay_row_outstanding($lr); ?>
                                <div>
                                    💰 <b><?= e($lr['label']) ?></b><?= pay_is_skipped($lr) ? ' <span class="text-gray-500">(⊘ বাদ)</span>' : '' ?>:
                                    প্রাপ্য ৳<?= e(number_format((float) $lr['amount_due'])) ?>
                                    <?php if ((float) $lr['discount_amount'] > 0): ?> · ছাড় ৳<?= e(number_format((float) $lr['discount_amount'])) ?><?php endif; ?>
                                    · জমা ৳<?= e(number_format((float) $lr['amount_paid'])) ?>
                                    · <b class="<?= $lrDue > 0 ? 'text-red-700' : 'text-green-700' ?>"><?= $lrDue > 0 ? 'বাকি ৳' . e(number_format($lrDue)) : '✅ পাওয়া হয়ে গেছে' ?></b>
                                </div>
                            <?php endforeach; ?>
                            <?php if (count($lMonth) > 1): ?>
                                <div class="mt-0.5 border-t border-indigo-200" style="padding-top:2px;">
                                    এই পার্সেলে মোট <b class="<?= $lDue > 0 ? 'text-red-700' : 'text-green-700' ?>">৳<?= e(number_format($lDue)) ?></b> তুলতে হবে
                                </div>
                            <?php endif; ?>
                            <?php if ($lSum && $lSum['balance'] > 0): ?><div class="text-gray-500">সব মিলিয়ে বাকি ৳<?= e(number_format($lSum['balance'])) ?></div><?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <div class="builder <?= $isSent ? 'opacity-50 pointer-events-none' : '' ?>">
                        <div class="grid gap-2" style="grid-template-columns:1.3fr auto 1fr; align-items:end;">
                            <label class="text-xs text-gray-600">ডেলিভারি<select name="bd[<?= $rid ?>][zone]" data-orig="<?= e($exZone) ?>" class="zone block w-full border rounded-lg px-2 py-2 text-sm mt-1">
                                <?php foreach ($zoneLabels as $zk => $zl): ?><option value="<?= $zk ?>" <?= $exZone === $zk ? 'selected' : '' ?>><?= e($zl) ?></option><?php endforeach; ?>
                            </select>
                            <?php if ($permZone === ''): ?>
                                <span class="block text-xs text-amber-700 mt-0.5">উপরের তালিকায় স্থায়ী এলাকা একবার সেট করুন — তাহলে প্রতি মাসে আর বাছতে হবে না</span>
                            <?php elseif ($exZone !== $permZone): ?>
                                <span class="block text-xs text-amber-700 mt-0.5">শুধু এই মাসে আলাদা (স্থায়ী: <?= e($zoneLabels[$permZone]) ?>)</span>
                            <?php endif; ?></label>
                            <label class="flex items-center gap-1 text-xs text-gray-600 pb-2"><input type="checkbox" class="wx w-4 h-4 accent-indigo-600" name="bd[<?= $rid ?>][wx]" value="1" <?= $exWx?'checked':'' ?>> ওজন+</label>
                            <label class="text-xs text-gray-600">সমন্বয়±<input type="number" step="1" value="<?= e((string)(int)$exAdj) ?>" name="bd[<?= $rid ?>][adj]" class="adj block w-full border rounded-lg px-2 py-2 text-sm mt-1"></label>
                        </div>
                        <?php // 📍 শুধু এই মাসের ঠিকানা — টিক না দিলে অর্ডারের মূল ঠিকানাতেই যাবে ?>
                        <div class="mt-2">
                            <label class="flex items-center gap-1.5 text-xs text-gray-600">
                                <input type="checkbox" class="addrChk w-4 h-4 accent-indigo-600" <?= $addrDiff ? 'checked' : '' ?>>
                                📍 এই মাসে অন্য ঠিকানায় যাবে
                            </label>
                            <textarea name="bd[<?= $rid ?>][addr]" rows="2"
                                      class="addrBox block w-full border rounded-lg px-2 py-2 text-sm mt-1 <?= $addrDiff ? '' : 'hidden' ?>"
                                      placeholder="মূল ঠিকানা: <?= e($regAddr ?: '—') ?>"><?= $addrDiff ? e($exAddr) : '' ?></textarea>
                            <?php if ($addrDiff): ?>
                                <span class="block text-xs text-amber-700 mt-0.5">এই মাসে আলাদা ঠিকানায় যাচ্ছে — অর্ডারের মূল ঠিকানা অপরিবর্তিত</span>
                            <?php endif; ?>
                        </div>

                        <?php // 💸 ছাড় / মাফ — কালেকশন থেকে বাদ যায় **এবং** খরচের বইয়ে বসে (২০২৬-০৯-৩০) ?>
                        <?php if ($cpWaiveReady): ?>
                        <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50 px-2.5 py-2">
                            <div class="text-sm font-semibold text-amber-800">💸 ছাড় / মাফ — খরচে যাবে</div>
                            <div class="text-xs text-amber-700 mb-1">ডেলিভারি চার্জ মাফ করলেন বা কোনো ছাড় দিলেন? অঙ্কটা লিখুন — কালেকশন থেকে বাদ যাবে, আর ঠিক এই টাকাটাই <b>খরচের বইয়ে</b> যোগ হবে।</div>
                            <div class="grid gap-2" style="grid-template-columns:110px minmax(0,1fr);">
                                <input type="number" step="1" min="0" max="<?= CP_MAX_WAIVE ?>" name="bd[<?= $rid ?>][waive]"
                                       value="<?= $exWaive > 0 ? (int) round($exWaive) : '' ?>" placeholder="0"
                                       class="waive w-full border rounded-lg px-2 py-2 text-sm" style="min-width:0">
                                <input type="text" name="bd[<?= $rid ?>][waive_reason]" value="<?= e($exWaiveR) ?>" maxlength="200"
                                       placeholder="কেন (ঐচ্ছিক) — যেমন: ডেলিভারি ফ্রি"
                                       class="w-full border rounded-lg px-2 py-2 text-sm" style="min-width:0">
                            </div>
                            <div class="waivehint text-xs text-amber-800 mt-1"></div>
                        </div>
                        <?php endif; ?>

                        <?php /* ══════ 🧾 এই পার্সেলে কী কী তুলবেন? (২০২৬-১০-১৫) ══════
                                 ইউজার: "কেউ জানাল পার্সেল শুধু ডেলিভারি চার্জ দিয়ে যাবে, বেতন পরে দিবো"।
                                 🔴 টিক তুলে দিলে ঐ টাকাটা কুরিয়ারের কালেকশনে যায় না, খাতায় বাকিই থাকে।
                                 🔴 ঘরগুলো `roundForm`-এর ভেতরেই (`bd[<id>][parts][]`) — `form=` অ্যাট্রিবিউট নয়। */ ?>
                        <?php if ($cpPartsReady && ($pick || $lRows)): ?>
                        <div class="mt-2 rounded-lg border border-indigo-200 bg-indigo-50 px-2.5 py-2">
                            <div class="text-sm font-semibold text-indigo-800">🧾 এই পার্সেলে কী কী তুলবেন?</div>
                            <div class="text-xs text-indigo-700 mb-1">টিক তুলে দিলে ঐ টাকাটা <b>কুরিয়ারে যাবে না</b> — খাতায় বাকিই থেকে যাবে।</div>
                            <input type="hidden" name="bd[<?= $rid ?>][parts_on]" value="1" class="partsOn">
                            <?php foreach ($pick as $pk):
                                $pkOn = $savedParts === null ? !empty($pk['on']) : in_array((string) $pk['token'], $savedParts, true); ?>
                                <label class="flex items-start gap-1.5 text-sm text-gray-800 py-0.5">
                                    <input type="checkbox" class="part w-4 h-4 accent-indigo-600" style="margin-top:3px"
                                           name="bd[<?= $rid ?>][parts][]" value="<?= e((string) $pk['token']) ?>"
                                           data-amt="<?= (int) round($pk['due']) ?>" <?= $pkOn ? 'checked' : '' ?>>
                                    <span class="min-w-0"><?= e($pk['label']) ?> · <b>৳<?= e(number_format($pk['due'])) ?></b><?php
                                        // 🔴 স্লট ০ = ধরে রাখা ডেলিভারি চার্জ (কোনো পার্সেলের নয়) → "আগের বাকি"
                                        if (empty($pk['on'])): ?> <span class="text-xs text-amber-700">(<?= (int) $pk['slot'] > $selMonth ? 'পরের মাসের' : 'আগের বাকি' ?>)</span><?php endif; ?></span>
                                </label>
                            <?php endforeach; ?>
                            <label class="flex items-start gap-1.5 text-sm text-gray-800 py-0.5 mt-1 pt-1 border-t border-indigo-200">
                                <input type="checkbox" class="partDeliv w-4 h-4 accent-indigo-600" style="margin-top:3px"
                                       name="bd[<?= $rid ?>][parts][]" value="<?= PAY_PART_DELIVERY ?>" <?= $delivOnCard ? 'checked' : '' ?>>
                                <span class="min-w-0">🚚 ডেলিভারি চার্জ · <b>৳<span class="delivVal">0</span></b></span>
                            </label>
                            <div class="partHint text-xs text-amber-800 mt-1"></div>
                        </div>
                        <?php endif; ?>

                        <div class="flex items-center justify-between gap-2 mt-3 pt-3 border-t">
                            <div class="text-sm font-semibold text-gray-600">কালেকশন (টাকা)<div class="autohint text-xs text-gray-500"><?= ($cpPartsReady && ($pick || $lRows)) ? 'বেছে নেওয়া কিস্তি + ডেলিভারি' : ($lRows ? 'খাতার বাকি + ডেলিভারি' : 'মাসিক ফি + ডেলিভারি') ?><span class="autowv"></span> = ৳<span class="autoval">0</span> · <button type="button" class="text-indigo-600 font-semibold underline autobtn">অটো বসান</button></div></div>
                            <div class="flex items-center gap-1.5 flex-shrink-0">
                                <span class="text-lg font-black text-gray-900">৳</span>
                                <input type="number" step="1" min="0" name="bd[<?= $rid ?>][amt]" value="<?= e($exAmt) ?>" class="amt border-2 border-indigo-200 focus:border-indigo-500 rounded-lg px-2 py-1.5 text-lg font-black text-gray-900 text-right" style="width:120px;">
                                <input type="hidden" name="bd[<?= $rid ?>][manual]" class="manual" value="<?= $exAmt !== '' ? '1' : '' ?>">
                            </div>
                        </div>
                    </div>

                    <?php // ══════ ✅ কুরিয়ার ডান + 💰 টাকা মিলিয়ে নেওয়া ══════
                          // 🔴 `.builder`-এর **বাইরে** — পাঠানো কার্ডে builder লক থাকে, অথচ
                          //    আগে পাঠানো পার্সেলের টাকাটা তখনই মেলাতে হয়।
                          // 🔴🔴 ঘরগুলো `roundForm`-এর ভেতরেই (`bd[<id>][done_sent]`/`[done_amt]`) —
                          //    আগে আলাদা `form="cpDoneForm"` দিয়ে বাঁধা ছিল, তাই নিচের "সেভ" চাপলে
                          //    টিকটা নীরবে হারিয়ে যেত (২০২৬-১০-১৪ এ ইউজারের রিপোর্ট)। নতুন কোনো
                          //    ঘর যোগ করলে সেটাও `bd[<id>][...]`-এই দিন, `form=` অ্যাট্রিবিউট নয়।
                          // 🔴 "পাঠানো ধরে নিন"-এর জন্য এখানে পার্সেল আগে তৈরি থাকা **লাগে না**
                          //    (২০২৬-১০-১৪): ইউজার অনেক সময় কুরিয়ারের নিজের সাইটে এন্ট্রি দিয়ে
                          //    আসেন, তখন আমাদের এখানে সারিই থাকে না — শর্ত রাখায় বাক্সটাই খুঁজে
                          //    পাওয়া যাচ্ছিল না। সারি না থাকলে হ্যান্ডলার নতুন সারি বসায়। ?>
                    <?php
                    $pdCanSend = !$isSent && !$isNo;                 // এখনো পাঠানো হয়নি, "না" বলাও হয়নি
                    $pdMoney   = $lRows && $lDue > 0.009;            // খাতা আছে ও এই পার্সেলে বাকি আছে
                    ?>
                    <?php if ($pdCanSend || $pdMoney): ?>
                        <div class="mt-3 pt-3 border-t">
                            <details>
                                <summary class="cursor-pointer select-none text-sm font-semibold text-green-700">
                                    ✅ হাতে পাঠানো / 💰 টাকা মিলিয়ে নিন
                                </summary>
                                <div class="mt-2 rounded-lg border border-green-200 bg-green-50 px-2.5 py-2">
                                    <?php if ($pdCanSend): ?>
                                        <label class="flex items-start gap-1.5 text-sm text-green-800">
                                            <input type="checkbox" name="bd[<?= $rid ?>][done_sent]" value="1"
                                                   class="doneSent w-4 h-4 accent-indigo-600" style="margin-top:3px">
                                            <span>এই পার্সেলটা <b>পাঠানো হয়েছে</b> ধরে নিন
                                                <span class="block text-xs text-gray-500">কুরিয়ারের API ডাকা হবে না, নতুন চালানও তৈরি হবে না — শুধু এখানে "✓ পাঠানো" দেখাবে।</span>
                                                <?php if (!$ex): ?>
                                                    <span class="block text-xs text-amber-700">এই মাসের পার্সেল এখানে এখনো তৈরি নেই। কুরিয়ারের সাইটে নিজে এন্ট্রি দিয়ে থাকলে এটাই টিক দিন — নতুন করে বসিয়ে "পাঠানো" ধরা হবে, আর উপরের <b>কালেকশন (টাকা)</b> ঘরে যা লেখা আছে সেটাই রেকর্ডে যাবে।</span>
                                                <?php endif; ?>
                                                <span class="block text-xs text-gray-500">টিক দিয়ে নিচের <b>"✅ করে ফেলুন"</b> অথবা পাতার নিচের <b>"সেভ"</b> — যেটাই চাপুন, কাজ হয়ে যাবে।</span>
                                            </span>
                                        </label>
                                    <?php elseif ($isSent): ?>
                                        <div class="text-sm text-green-800">✓ এই মাসের পার্সেল পাঠানো হয়ে গেছে — নিচে শুধু টাকাটা মিলিয়ে নিন।</div>
                                    <?php endif; ?>

                                    <?php if ($pdMoney): ?>
                                        <div class="<?= $pdCanSend ? 'mt-2 pt-2 border-t border-green-200' : 'mt-2' ?>">
                                            <div class="text-sm text-green-800">💰 হাতে কত টাকা এলো? <span class="text-gray-500">(খাতায় জমা বসবে, আয়ের বইও মিলে যাবে)</span></div>
                                            <div class="flex items-center flex-wrap gap-2 mt-1">
                                                <input type="number" step="1" min="0" max="<?= CP_MAX_COLLECT ?>"
                                                       name="bd[<?= $rid ?>][done_amt]" value="" placeholder="0"
                                                       class="doneAmt border rounded-lg px-2 py-2 text-sm font-bold text-right" style="width:120px;min-width:0">
                                                <?php // 🔴 অঙ্কটা নিজে থেকে বসে না — ট্যাপ করলে তবেই বসে (অনুমান নিষিদ্ধ) ?>
                                                <button type="button" class="doneChip text-xs font-semibold text-green-700 bg-white border border-green-200 rounded-lg px-2 py-1"
                                                        data-amt="<?= (int) round($chipDue) ?>">এই পার্সেলে তোলার কথা ৳<?= e(number_format($chipDue)) ?></button>
                                            </div>
                                            <div class="text-xs text-gray-500 mt-1">ডেলিভারি চার্জ বাদে — শুধু কোর্সের টাকাটা লিখুন।</div>
                                        </div>
                                    <?php elseif (!$lRows): ?>
                                        <div class="<?= $pdCanSend ? 'mt-2 pt-2 border-t border-green-200' : '' ?> text-xs text-gray-500">
                                            💰 টাকা বসাতে হলে আগে উপরের <b>“📒 সবার খাতা বসান”</b> একবার চাপুন — তারপর এখানে ঘরটা আসবে।
                                        </div>
                                    <?php endif; ?>

                                    <button type="button" class="doneBtn mt-2 bg-green-600 hover:bg-green-700 text-white font-bold px-3 py-2 rounded-xl text-sm"
                                            data-rid="<?= $rid ?>" data-name="<?= e($r['customer_name']) ?>">✅ করে ফেলুন</button>
                                </div>
                            </details>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="fixed inset-x-0 bottom-0 bg-white border-t border-gray-200 px-4 py-3 z-30" style="box-shadow:0 -4px 20px rgba(0,0,0,0.08);">
                <div class="max-w-2xl mx-auto flex items-center gap-3 flex-wrap">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500"><b>মাস <?= $selMonth ?></b> · <span id="goCount">0</span> যাবে · <span id="noCount">0</span> না · মোট</div>
                        <div id="sumAmt" class="text-lg font-black text-gray-900">৳0</div>
                    </div>
                    <button type="button" id="draftBtn" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold px-4 py-3 rounded-xl text-sm" title="এখনো পাঠাবে না, শুধু ঠিক করে রাখবে"><i data-lucide="save" class="w-4 h-4 inline"></i> সেভ</button>
                    <button type="button" id="sendBtn" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-3 rounded-xl text-sm"><i data-lucide="truck" class="w-4 h-4 inline"></i> কুরিয়ারে পাঠান</button>
                </div>
            </div>
        </form>
    <?php endif; ?>
<?php endif; ?>

<script>
(function(){
  var form=document.getElementById('roundForm');
  if(!form){ return; }
  var DC={dhaka:<?= (float) $dc['dhaka'] ?>,near:<?= (float) $dc['near'] ?>,outside:<?= (float) $dc['outside'] ?>};
  var WX=<?= (float) $wxExtra ?>;
  var periodHasExisting=<?= $periodHasExisting ? 'true' : 'false' ?>;
  var selMonth=<?= (int) $selMonth ?>;

  function paintGoNo(el){
    var dec=el.querySelector('.decision').value, btn=el.querySelector('.goNoBtn');
    if(!btn){ return; }
    var bld=el.querySelector('.builder');
    if(dec==='no'){ btn.textContent='না এই মাসে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-red-300 bg-red-50 text-red-700'; el.classList.add('opacity-60'); if(bld)bld.style.display='none'; }
    else { btn.textContent='✓ যাবে'; btn.className='goNoBtn flex-shrink-0 text-xs font-semibold px-3 py-1.5 rounded-lg border border-green-300 bg-green-50 text-green-700'; el.classList.remove('opacity-60'); if(bld)bld.style.display=''; }
  }
  function calc(el){
    // ভিত্তি = খাতায় এই মাসের বাকি (খাতা না থাকলে মাসিক ফি) — সার্ভারেও হুবহু একই হিসাব
    var zone=el.querySelector('.zone').value;
    var wx=el.querySelector('.wx').checked?WX:0, adj=+el.querySelector('.adj').value||0;
    // 🧾 ছক রেন্ডার হয়ে থাকলে ভিত্তি = **টিক দেওয়া** কিস্তিগুলোর যোগফল; নাহলে আগের মতোই
    //    এই মাসের পুরো বাকি (`data-due`)। সার্ভারের round-save-এও হুবহু এই নিয়ম —
    //    একটা বদলালে অন্যটাও বদলান, নাহলে UI আর সেভ আলাদা টাকা দেখাবে।
    var base, held=0, delivOn=true, pDel=el.querySelector('.partDeliv');
    if(el.querySelector('.partsOn')){
      base=0;
      el.querySelectorAll('.part').forEach(function(c){
        var v=+c.getAttribute('data-amt')||0;
        if(c.checked){ base+=v; } else { held+=v; }
      });
      delivOn = !pDel || pDel.checked;
    } else { base=+el.getAttribute('data-due'); }
    var dcOnly=(DC[zone]||0)+wx;
    var dvEl=el.querySelector('.delivVal'); if(dvEl){ dvEl.textContent=dcOnly; }
    // 💸 ছাড়/মাফ — কালেকশন থেকে বাদ (সার্ভারের round-save-এও হুবহু একই হিসাব, একটা বদলালে অন্যটাও)
    var wvEl=el.querySelector('.waive'), wv=wvEl?Math.max(0,+wvEl.value||0):0;
    var auto=Math.max(0,Math.round(base+(delivOn?dcOnly:0)+adj-wv));
    var ph=el.querySelector('.partHint');
    if(ph){
      var msg=[];
      if(held>0){ msg.push('৳'+held+' এই পার্সেলে তোলা হচ্ছে না — খাতায় বাকি থাকবে।'); }
      if(!delivOn&&dcOnly>0){ msg.push('ডেলিভারি চার্জ ৳'+dcOnly+' খাতায় নতুন কিস্তি হিসেবে বসবে।'); }
      ph.textContent=msg.join(' ');
    }
    var amtEl=el.querySelector('.amt'), manual=el.querySelector('.manual').value==='1';
    el.querySelector('.autoval').textContent=auto;
    var wvl=el.querySelector('.autowv');
    if(wvl){ wvl.textContent = wv>0 ? (' − ছাড় ৳'+wv) : ''; }
    var wh=el.querySelector('.waivehint');
    if(wh){
      var had=+el.getAttribute('data-waive0')||0;
      if(wv>0){ wh.textContent='৳'+wv+' কালেকশন থেকে বাদ যাবে, আর খরচের বইয়ে "ছাড় / মাফ" হিসেবে যোগ হবে।'; }
      else if(had>0){ wh.textContent='০ করা হয়েছে — সেভ করলে আগের ৳'+had+' খরচের বই থেকে মুছে যাবে।'; }
      else { wh.textContent=''; }
    }
    if(!manual){ amtEl.value=auto; }
    var dec=el.querySelector('.decision').value, sent=el.getAttribute('data-sent')==='1';
    var resendEl=el.querySelector('.resend'), resend=resendEl && resendEl.value==='1';
    // ✅ "হাতে পাঠানো" টিক দেওয়া কার্ড আসল কুরিয়ারে যায় না (সার্ভারেও `continue`) —
    //    তাই গণনা/মোট থেকেও বাদ, নাহলে মডাল ভুল সংখ্যা বলত
    var dsEl=el.querySelector('.doneSent'), ds=!!(dsEl&&dsEl.checked);
    // পাঠাবে = (নতুন যাবে) অথবা (পাঠানো + আবার-পাঠান armed)
    var willSend=(dec==='go' && !sent && !ds) || (sent && resend);
    return {send: willSend, resend: sent && resend, no: dec==='no' && !sent, t:+amtEl.value||0};
  }
  function refresh(){
    var s=0,g=0,no=0;
    document.querySelectorAll('.stu').forEach(function(el){var r=calc(el); if(r.send){s+=r.t;g++;} if(r.no){no++;} paintGoNo(el);});
    var gc=document.getElementById('goCount'),nc=document.getElementById('noCount'),sa=document.getElementById('sumAmt');
    if(gc)gc.textContent=g; if(nc)nc.textContent=no; if(sa)sa.textContent='৳'+s;
  }
  document.querySelectorAll('.stu').forEach(function(el){
    var btn=el.querySelector('.goNoBtn');
    if(btn){ btn.addEventListener('click',function(){ var d=el.querySelector('.decision'); d.value=(d.value==='no')?'go':'no'; refresh(); }); }
    var amtEl=el.querySelector('.amt'), manualEl=el.querySelector('.manual');
    if(amtEl){ amtEl.addEventListener('input',function(){ manualEl.value='1'; refresh(); }); }
    // 🧾 কিস্তির টিক বদলালে অঙ্কটা আবার হিসাব হয় — 🔴 `manual` রিসেট করা হয়, নাহলে
    //    আগে হাতে লেখা অঙ্কটাই থেকে যেত আর টিক তোলার কোনো ফল দেখা যেত না
    el.querySelectorAll('.part, .partDeliv').forEach(function(c){
      c.addEventListener('change',function(){ if(manualEl){ manualEl.value=''; } refresh(); });
    });
    // শুধু এই মাসের এলাকা বদলাতে গেলে নিশ্চিতকরণ (স্থায়ীটা এতে বদলায় না)
    var zoneEl=el.querySelector('.zone');
    if(zoneEl){ zoneEl.addEventListener('change',function(){
      var orig=zoneEl.getAttribute('data-orig')||'', now=zoneEl.value;
      if(now===orig){ return; }
      zoneEl.value=orig; refresh();
      showConfirmModal('শুধু এই মাসের জন্য ডেলিভারি এলাকা বদলাবেন? স্থায়ী এলাকা অপরিবর্তিত থাকবে — অন্য মাসগুলোতে আগেরটাই বসবে।',
        function(){ zoneEl.value=now; zoneEl.setAttribute('data-orig',now); refresh(); }, 'এই মাসে এলাকা বদলাবেন?');
    }); }
    // 📍 এই মাসে অন্য ঠিকানা — টিক দেওয়ার সময় নিশ্চিতকরণ, টিক তুললে লেখা মুছে যায়
    var addrChk=el.querySelector('.addrChk'), addrBox=el.querySelector('.addrBox');
    if(addrChk&&addrBox){ addrChk.addEventListener('change',function(){
      if(addrChk.checked){
        addrChk.checked=false;
        showConfirmModal('এই মাসের পার্সেল অন্য ঠিকানায় যাবে? শুধু এই মাসেই প্রযোজ্য হবে — অর্ডারের মূল ঠিকানা বদলাবে না। এলাকা (ঢাকা/নিকটবর্তী/বাইরে) আলাদা হলে উপরের ডেলিভারি ঘরটাও বদলে দিন।',
          function(){ addrChk.checked=true; addrBox.classList.remove('hidden'); addrBox.focus(); }, 'এই মাসে ঠিকানা বদলাবেন?');
      } else { addrBox.classList.add('hidden'); addrBox.value=''; }
    }); }
    // 💸 ছাড় লিখলে কালেকশনটা আবার অটো হিসাব হয় — নাহলে হাতে বসানো পুরনো অঙ্কটাই থেকে যেত
    var wvIn=el.querySelector('.waive');
    if(wvIn){ wvIn.addEventListener('input',function(){ manualEl.value=''; refresh(); }); }
    var ab=el.querySelector('.autobtn');
    if(ab){ ab.addEventListener('click',function(){ manualEl.value=''; refresh(); }); }
    // পাঠানো কার্ড আনলক (ওয়ার্নিং সহ) → এডিট + "আবার পাঠান" চালু
    var ub=el.querySelector('.unlockBtn'), rb=el.querySelector('.resendBtn'),
        unl=el.querySelector('.unlocked'), bld=el.querySelector('.builder'), re=el.querySelector('.resend');
    if(ub){ ub.addEventListener('click',function(){
      showConfirmModal('এই পার্সেল ইতিমধ্যে কুরিয়ারে পাঠানো হয়ে গেছে। আনলক করে টাকা/ডেলিভারি ঠিক করা যাবে বা "আবার পাঠান" করা যাবে। খেয়াল রাখুন — এটা আগের পাঠানো চালান বাতিল করে না। আনলক করবেন?',function(){
        unl.value='1'; if(bld){ bld.classList.remove('opacity-50','pointer-events-none'); }
        ub.classList.add('hidden'); if(rb){ rb.classList.remove('hidden'); }
        refresh();
      },'আনলক করবেন?');
    }); }
    if(rb){ rb.addEventListener('click',function(){
      if(re.value==='1'){ re.value=''; rb.textContent='↻ আবার পাঠান'; rb.classList.remove('bg-indigo-600','text-white'); rb.classList.add('bg-indigo-50','text-indigo-700'); el.classList.remove('ring-2','ring-indigo-400'); refresh(); return; }
      showConfirmModal('নতুন করে কুরিয়ারে পাঠাবেন? একটা নতুন চালান তৈরি হবে (আগেরটা বাতিল হবে না)। "কুরিয়ারে পাঠান" চাপলে এটা যাবে।',function(){
        re.value='1'; rb.textContent='✓ আবার পাঠানো হবে (বাতিল করতে ক্লিক)'; rb.classList.remove('bg-indigo-50','text-indigo-700'); rb.classList.add('bg-indigo-600','text-white'); el.classList.add('ring-2','ring-indigo-400'); refresh();
      },'আবার পাঠাবেন?');
    }); }
  });
  function doSubmit(mode){
    document.getElementById('roundMode').value=mode;
    var g=0,no=0,s=0,re=0,wv=0;
    document.querySelectorAll('.stu').forEach(function(el){
      var r=calc(el); if(r.send){g++;s+=r.t; if(r.resend)re++;} if(r.no)no++;
      if(!r.no){ var w=el.querySelector('.waive'); if(w){ wv+=Math.max(0,+w.value||0); } }
    });
    if(mode==='send' && g===0){
      var dm0=doneMsg();
      showConfirmModal('আসল কুরিয়ারে পাঠানোর জন্য কাউকে বাছাই করা হয়নি (নতুন "যাবে" বা পাঠানো কার্ডে "আবার পাঠান")।'
        + (dm0 ? ('\n\n' + dm0 + '\n\nএগুলো করতে পাশের "সেভ" বোতামটা চাপুন — কুরিয়ারে কিছু যাবে না।') : ''),
        function(){},'পাঠানো খালি'); return; }
    var msg = (mode==='send')
      ? ('মাস '+selMonth+' — '+g+' জনের পার্সেল এখনই আসল কুরিয়ারে পাঠাবেন?'+(re?(' (তার মধ্যে '+re+' জন আবার-পাঠানো)'):'')+' মোট কালেকশন ৳'+s+'। পাঠানোর পর আর ফেরানো যাবে না।')
      : ('মাস '+selMonth+' — সেভ করবেন? এটা সংবেদনশীল পেজ — শুধু ঠিক করে রাখা হবে (এখনো কুরিয়ারে পাঠাবে না)।');
    if(wv>0){ msg+='\n\n💸 মোট ৳'+wv+' ছাড়/মাফ দেওয়া হয়েছে — এটা খরচের বইয়ে যোগ হবে।'; }
    // ✅/💰 ড্রয়ারে কিছু বাছাই করা থাকলে সেটাও এই সেভেই হবে — না বললে অ্যাডমিন টের পেতেন না
    var dm=doneMsg(); if(dm){ msg+='\n\n'+dm; }
    if(periodHasExisting){ msg='⚠️ এই মাসের কিছু আগে থেকেই আছে — পরিবর্তন হবে।\n\n'+msg; }
    showConfirmModal(msg,function(){ form.submit(); }, mode==='send'?'কুরিয়ারে পাঠাবেন?':'সেভ করবেন?');
  }
  // ══════ ✅ কুরিয়ার ডান + 💰 টাকা ══════
  // 🔴🔴 ঘরগুলো এখন `roundForm`-এর ভেতরেই, তাই কার্ডের নিজের বোতাম ও পাতার নিচের
  //     "সেভ" — দুটোই একই কাজ করে (আগে আলাদা ফর্ম ছিল, "সেভ" চাপলে টিকটা হারাত)।
  // 🔴 অঙ্কটা কখনো নিজে থেকে বসে না — চিপে ট্যাপ করলে তবেই বসে (অনুমান নিষিদ্ধ)।
  function doneSummary(){
    var sent=0, money=0, names=[];
    document.querySelectorAll('.stu').forEach(function(el){
      var dec=el.querySelector('.decision'), isNo=dec && dec.value==='no';
      var c=el.querySelector('.doneSent'), a=el.querySelector('.doneAmt');
      var m=Math.max(0,+(a?a.value:0)||0), tick=!!(c&&c.checked&&!isNo);
      if(!tick && m<=0){ return; }
      if(tick){ sent++; }
      money+=m;
      var nb=el.querySelector('.doneBtn');
      names.push((nb?nb.getAttribute('data-name'):'')||'');
    });
    return {sent:sent, money:money, names:names};
  }
  function doneMsg(){
    var d=doneSummary(), out='';
    if(d.sent>0){ out+='✅ '+d.sent+' জনের পার্সেল "হাতে পাঠানো" ধরা হবে — কুরিয়ারে কিছু যাবে না, নতুন চালানও হবে না।'; }
    if(d.money>0){ out+=(out?'\n':'')+'💰 মোট ৳'+d.money+' টাকার খাতায় জমা বসবে — আয়ের বইয়ের অঙ্কও বদলাবে।'; }
    if(out && d.names.length){ out+='\n('+d.names.join(', ')+')'; }
    return out;
  }
  document.querySelectorAll('.stu').forEach(function(el){
    var chip=el.querySelector('.doneChip'), amt=el.querySelector('.doneAmt');
    if(chip&&amt){ chip.addEventListener('click',function(){ amt.value=chip.getAttribute('data-amt')||''; amt.focus(); refresh(); }); }
    var btn=el.querySelector('.doneBtn');
    if(!btn){ return; }
    btn.addEventListener('click',function(){
      var sentEl=el.querySelector('.doneSent'), sent=!!(sentEl&&sentEl.checked),
          money=Math.max(0,+(amt?amt.value:0)||0);
      if(!sent&&money<=0){
        showConfirmModal('এই কার্ডে কিছুই বাছাই করা হয়নি — "পাঠানো হয়েছে ধরে নিন" টিক দিন অথবা হাতে পাওয়া টাকার অঙ্কটা লিখুন।',function(){},'কিছু বাছাই করুন');
        return;
      }
      // 🔴 বোতামটা পুরো `roundForm` সাবমিট করে (খসড়া মোডে), তাই এই মাসের সব কার্ডই
      //    একসাথে সেভ হয় — মডালে সেটা স্পষ্ট করে বলা হয়।
      var msg=doneMsg()+'\n\nমাস '+selMonth+'-এর সব কার্ড একসাথে সেভ হবে (কুরিয়ারে কিছু পাঠানো হবে না)। চালিয়ে যাবেন?';
      showConfirmModal(msg,function(){ document.getElementById('roundMode').value='draft'; form.submit(); },'করে ফেলবেন?');
    });
  });

  var d1=document.getElementById('draftBtn'); if(d1){ d1.addEventListener('click',function(){ doSubmit('draft'); }); }
  var s1=document.getElementById('sendBtn'); if(s1){ s1.addEventListener('click',function(){ doSubmit('send'); }); }
  form.addEventListener('input',refresh); form.addEventListener('change',refresh); refresh();
})();
</script>

<script>
// ══════ 🔎 কোর্স পার্সেলের নিজের সার্চ (রোস্টার + এই মাসের কার্ড) ══════
// একটাই ম্যাচিং-ফাংশন দুই জায়গায় — তাই এক বাক্সে যাকে পাওয়া যায় অন্য বাক্সেও পাওয়া যায়।
// 🔴 গাদা (`data-s`/`data-d`) সার্ভারে বানানো (`cp_haystack()`), `textContent` নয় —
//    নাহলে সারির ড্রপডাউনের অপশনও "মিল" ধরত।
(function () {
    var BN = {'\u09e6':'0','\u09e7':'1','\u09e8':'2','\u09e9':'3','\u09ea':'4','\u09eb':'5','\u09ec':'6','\u09ed':'7','\u09ee':'8','\u09ef':'9'};

    // বাংলা অঙ্কে টাইপ করলেও যাতে নম্বর মেলে
    function enDigits(t) { return t.replace(/[\u09e6-\u09ef]/g, function (c) { return BN[c]; }); }

    function matches(el, q, qd) {
        if (q === '') { return true; }
        if ((el.getAttribute('data-s') || '').indexOf(q) !== -1) { return true; }
        // 📞 প্রশ্নে অঙ্ক থাকলে নম্বরের গাদাতেও দেখা হয় — "171" দিয়ে "01712..." মেলে
        return qd.length >= 2 && (el.getAttribute('data-d') || '').indexOf(qd) !== -1;
    }

    function wire(inputId, noteId, items, word, onApply) {
        var input = document.getElementById(inputId), note = document.getElementById(noteId);
        if (!input || !items.length) { return; }
        function apply() {
            var raw = enDigits(input.value.trim()),
                q = raw.toLowerCase(),
                qd = raw.replace(/[^0-9]+/g, ''),
                shown = 0;
            items.forEach(function (el) {
                var hit = matches(el, q, qd);
                el.style.display = hit ? '' : 'none';
                if (hit) { shown++; }
            });
            if (note) {
                if (q === '') { note.classList.add('hidden'); note.textContent = ''; }
                else {
                    note.classList.remove('hidden');
                    note.textContent = shown
                        ? ('🔎 ' + shown + ' ' + word + ' মিলেছে (মোট ' + items.length + ') — খোঁজা শুধু দেখার সুবিধার জন্য, সেভ / পাঠানোয় সবাই আগের মতোই থাকবে।')
                        : '🔎 এই লেখার সাথে কেউ মেলেনি। নাম, মোবাইল নম্বর, ফেসবুক আইডি বা ঠিকানার অংশ দিয়ে চেষ্টা করুন।';
                }
            }
            if (onApply) { onApply(q); }
        }
        // 🔴 Enter-এ কিছু সাবমিট হবে না (ফিল্টার সম্পূর্ণ ব্রাউজারে, রিলোড লাগে না)
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); }
            if (e.key === 'Escape') { input.value = ''; apply(); }
        });
        input.addEventListener('input', apply);
        apply();
    }

    var rows = Array.prototype.slice.call(document.querySelectorAll('tr.cp-row'));
    var det = document.getElementById('cpInactive');
    wire('cpRosterSearch', 'cpRosterNote', rows, 'জন', function (q) {
        // নিষ্ক্রিয়দের ভাঁজ-করা অংশে মিল থাকলে নিজে থেকেই খুলে যায়
        if (!det) { return; }
        if (q === '') { return; }
        var inside = det.querySelectorAll('tr.cp-row');
        for (var i = 0; i < inside.length; i++) {
            if (inside[i].style.display !== 'none') { det.open = true; return; }
        }
    });

    // ══════ 🔖 এই মাসের কার্ড — চিপ (অবস্থা) + খোঁজা, একটাই দৃশ্যমানতার নিয়ম ══════
    // 🔴🔴 লুকানো হয় `display:none` দিয়ে (`disabled` নয়) — লুকানো কার্ডের ঘরগুলোও
    //    সাবমিট হয়, তাই চিপ বা খোঁজা কখনো সেভ/পাঠানোর ফলাফল বদলায় না।
    (function () {
        var cards = Array.prototype.slice.call(document.querySelectorAll('.stu'));
        if (!cards.length) { return; }
        var wrap  = document.getElementById('cpChips'),
            input = document.getElementById('cpCardSearch'),
            note  = document.getElementById('cpCardNote'),
            chips = wrap ? Array.prototype.slice.call(wrap.querySelectorAll('.cp-chip')) : [],
            mode  = wrap ? (wrap.getAttribute('data-mode') || 'all') : 'all';
        if (!wrap && !input) { return; }
        var LBL = {pending: 'পাঠানো বাকি', sent: 'পাঠানো হয়ে গেছে যাদের', no: '"না এই মাসে" যাদের'};

        function apply() {
            var raw = input ? enDigits(input.value.trim()) : '',
                q = raw.toLowerCase(),
                qd = raw.replace(/[^0-9]+/g, ''),
                shown = 0, hidByMode = 0;
            cards.forEach(function (el) {
                var okMode = mode === 'all' || (el.getAttribute('data-st') || 'pending') === mode,
                    hit = okMode && matches(el, q, qd);
                if (!okMode) { hidByMode++; }
                el.style.display = hit ? '' : 'none';
                if (hit) { shown++; }
            });
            chips.forEach(function (c) {
                if (c.getAttribute('data-mode') === mode) { c.classList.add('on'); } else { c.classList.remove('on'); }
            });
            var msg = '';
            if (q !== '') {
                msg = shown
                    ? ('🔎 ' + shown + ' জন মিলেছে' + (mode === 'all' ? '' : ' (শুধু ' + LBL[mode] + '-এর মধ্যে)')
                       + ' — খোঁজা শুধু দেখার সুবিধার জন্য, সেভ / পাঠানোয় সবাই আগের মতোই থাকবে।')
                    : '🔎 এই লেখার সাথে কেউ মেলেনি। নাম, মোবাইল নম্বর, ফেসবুক আইডি বা ঠিকানার অংশ দিয়ে চেষ্টা করুন।';
            } else if (mode !== 'all') {
                msg = '🔖 শুধু ' + LBL[mode] + ' দেখানো হচ্ছে — ' + shown + ' জন; ' + hidByMode
                    + ' জন লুকানো আছে। লুকানো কার্ডের তথ্যও সেভ হয়, কিছুই হারায় না।'
                    + ' পাঠানো কার্ড (টাকা মেলানোর ঘরসহ) দেখতে উপরের "✓ পাঠানো" বা "সবাই" চাপুন।';
            }
            if (note) {
                note.textContent = msg;
                if (msg === '') { note.classList.add('hidden'); } else { note.classList.remove('hidden'); }
            }
        }
        chips.forEach(function (c) {
            c.addEventListener('click', function () { mode = c.getAttribute('data-mode') || 'all'; apply(); });
        });
        if (input) {
            // 🔴 Enter-এ কিছু সাবমিট হবে না (ফিল্টার সম্পূর্ণ ব্রাউজারে, রিলোড লাগে না)
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') { e.preventDefault(); }
                if (e.key === 'Escape') { input.value = ''; apply(); }
            });
            input.addEventListener('input', apply);
        }
        apply();
    })();
})();
</script>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
