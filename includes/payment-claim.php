<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 💳 অভিভাবকের পেমেন্ট দাবি — ইঞ্জিন  (২০২৬-১০-০৬, ধাপ ২)
 *
 * অভিভাবক bKash/নগদে Send Money করে TrxID লিখে দেন; আমরা সেটা ফোন থেকে আসা
 * আসল SMS-এর (`payment_sms`) সাথে মিলিয়ে দেখি। পুরো নকশা → PAYMENT-SMS-PLAN.md
 *
 * 🔴🔴 সবচেয়ে বড় নিয়ম (ধাপ ১-এর মতোই): এই ফাইল **কখনো** `registration_payments`
 *      বা `income`-এ লেখে না। যাচাই হওয়া দাবি `payment_claims`-এ অপেক্ষা করে,
 *      অ্যাডমিন এক ট্যাপে খাতায় বসাবেন (ধাপ ৩)। কারণ `sync_income_for_status()`
 *      খাতা পেলেই আয় = মোট জমা বসায় আর ওটা ৭ জায়গা থেকে ডাকা হয়।
 *
 * 🔴 এই ফাইল পাবলিক পাতা থেকে require করা হয়, তাই **`admin/includes/*` ছোঁয় না**
 *    (একমাত্র ব্যতিক্রম `payments.php` — ওটা বিশুদ্ধ, auth/সাইড-ইফেক্ট নেই, আর
 *    `account.php` আগে থেকেই ওভাবেই ব্যবহার করে)।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/payment-sms.php';

const PCLAIM_MIN_TRX      = 6;    // TrxID-র ন্যূনতম দৈর্ঘ্য
const PCLAIM_MAX_AMOUNT   = 500000; // টাইপো-গার্ড
const PCLAIM_TRY_PER_HOUR = 10;   // এক IP থেকে ঘণ্টায় সর্বোচ্চ কয়টা দাবি
const PCLAIM_REMATCH_MAX  = 50;   // নতুন SMS এলে কয়টা অপেক্ষমাণ দাবি মিলিয়ে দেখা হবে
const PCLAIM_REMATCH_DAYS = 30;   // তার চেয়ে পুরনো দাবি আর মেলানো হয় না

/* ────────────────────────────────────────────────────────────────────────────
 * 🔗 স্থায়ী পেমেন্ট লিংক
 *
 * 🔴🔴 কেন দরকার: অভিভাবক TrxID দিতে গেলে **ব্রাউজার ছেড়ে bKash অ্যাপে যান**,
 *      তারপর ফিরে আসেন। পুরনো `register-thanks.php` সেশন থেকে পড়ে সাথে সাথে
 *      `unset()` করত — ফিরে এসে রিফ্রেশ দিলেই হোমপেজে ছিটকে যেতেন, সব হারাত।
 *      এখন লিংকটা স্থায়ী, যতবার খুশি খোলা যায়।
 *
 * 🔴 আইডি অনুমান করে অন্যের পাতা খোলা আটকাতে HMAC — চাবি `settings`-এ
 *    (key-value, তাই **মাইগ্রেশন লাগে না**), একবার নিজে থেকেই তৈরি হয়।
 * ──────────────────────────────────────────────────────────────────────────── */
function pay_link_secret(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $s = trim(get_setting('pay_link_secret'));
    if ($s === '') {
        try {
            $s = bin2hex(random_bytes(16));
            update_setting('pay_link_secret', $s);
            // ⚠️ `get_all_settings()` স্ট্যাটিক-ক্যাশড, তাই এই রিকোয়েস্টে আর DB-তে
            //    যাবে না — নিজের static-এ রেখে দেওয়াই যথেষ্ট (নিচে `$cache`)।
        } catch (Throwable $e) {
            $s = '';
        }
    }
    return $cache = $s;
}

function pay_link_token(int $regId): string
{
    $secret = pay_link_secret();
    if ($secret === '' || $regId <= 0) {
        return '';
    }
    return substr(hash_hmac('sha256', 'pay:' . $regId, $secret), 0, 20);
}

// 🔴 `hash_equals()` timing-safe, আর **খালি টোকেন কখনো পাস করে না**
//    (`hash_equals('','')` → true, ২০২৬-১০-০১ অডিটের ফাঁদ)
function pay_link_valid(int $regId, string $token): bool
{
    $want = pay_link_token($regId);
    return $want !== '' && $token !== '' && hash_equals($want, $token);
}

function pay_link_url(int $regId): string
{
    $t = pay_link_token($regId);
    return $t === '' ? '' : 'pay?r=' . $regId . '&k=' . $t;
}

/* ────────────────────────────────────────────────────────────────────────────
 * অভিভাবককে যে লেখাগুলো দেখানো হয় — সবই অ্যাডমিন বদলাতে পারেন
 * 🔴 `get_setting($k) ?: $default` (তৃতীয় প্যারামিটারে ভরসা নয় — খালি স্ট্রিং
 *    সেভ হয়ে থাকলে ডিফল্ট আর আসত না; প্রজেক্টের নিয়ম)
 * ⚠️ `settings` key-value বলে **মাইগ্রেশন লাগে না**।
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_messages(): array
{
    return [
        'verified'  => get_setting('pay_msg_verified')  ?: '✅ পেমেন্ট যাচাই হয়েছে — ধন্যবাদ!',
        'pending'   => get_setting('pay_msg_pending')   ?: '⏳ আপনার তথ্য জমা হয়েছে। মিলিয়ে দেখা হচ্ছে — সাধারণত কয়েক মিনিট সময় লাগে।',
        'duplicate' => get_setting('pay_msg_duplicate') ?: '⚠️ এই TrxID আগে একবার ব্যবহার করা হয়েছে।',
        'notfound'  => get_setting('pay_msg_notfound')  ?: 'এই নম্বরে কোনো রেজিস্ট্রেশন খুঁজে পাইনি। রেজিস্ট্রেশনের সময় যে নম্বরটি দিয়েছিলেন সেটিই দিন।',
        'extra'     => get_setting('pay_msg_extra')     ?: '',
    ];
}

// 🔴 bKash/নগদ ছাড়া অন্য কিছু এই পাতায় দেখানো হয় না (ইউজারের সিদ্ধান্ত — ব্যাংকের
//    SMS-এ প্রায়ই TrxID-ই থাকে না, তাই মেলানো যায় না)। নতুন সেবা যোগ করতে হলে
//    এখানে একটা লাইন + `psms_default_patterns()`-এ একটা ছাঁচ।
function pclaim_channels(): array
{
    return ['bkash', 'nagad'];
}

/* ────────────────────────────────────────────────────────────────────────────
 * এই আইটেমে প্রযোজ্য পেমেন্ট নাম্বারগুলো (payment_methods-এর scope নিয়ম হুবহু
 * `render_payment_box()`-এর মতোই — দুই জায়গায় দুই রকম হতে পারে না)
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_methods(?PDO $db, string $itemType = '', int $itemId = 0): array
{
    $db = $db ?: get_db();
    try {
        $rows = $db->query('SELECT * FROM payment_methods WHERE is_active = 1 ORDER BY sort_order ASC, id ASC')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
    $token = ($itemType !== '' && $itemId > 0) ? $itemType . ':' . $itemId : '';
    $out = [];
    foreach ($rows as $r) {
        if (!in_array((string) $r['channel'], pclaim_channels(), true)) {
            continue;
        }
        if (!$r['scope_all']) {
            $items = json_decode((string) ($r['scope_items'] ?? '[]'), true) ?: [];
            if ($token === '' || !in_array($token, $items, true)) {
                continue;
            }
        }
        $out[] = $r;
    }
    return $out;
}

/* ────────────────────────────────────────────────────────────────────────────
 * "এখন কত দিতে হবে" — খাতার প্রথম জমা-হীন কিস্তির নিট
 *
 * 🔴 অভিভাবককে কখনো "মোট খরচ" যোগফল দেখানো হয় না (প্রজেক্টের স্পষ্ট নিয়ম) —
 *    শুধু এই একটা কিস্তির অঙ্ক। খাতা না থাকলে ব্যাচের রেজিস্ট্রেশন ফি।
 * ⚠️ এটা নিছক **ইঙ্গিত** — আসল সত্য SMS-এর অঙ্কটাই।
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_due_hint(PDO $db, array $reg): float
{
    try {
        require_once __DIR__ . '/../admin/includes/payments.php';
        $byReg = pay_fetch_many($db, [(int) $reg['id']]);
        $rows  = $byReg[(int) $reg['id']] ?? [];
        if (!$rows) {
            $rows = pay_build_plan($db, $reg);
        }
        foreach ($rows as $row) {
            if (!empty($row['is_skipped'])) {
                continue;
            }
            $out = pay_row_outstanding($row);
            if ($out > 0.009) {
                return round($out, 2);
            }
        }
    } catch (Throwable $e) {
        // খাতা পড়া না গেলে ইঙ্গিত ছাড়াই চলবে
    }
    return 0.0;
}

/* ════════════════════════════════════════════════════════════════════════════
 * মেলানো
 * ════════════════════════════════════════════════════════════════════════════ */

/* একটা দাবি ও তার সম্ভাব্য SMS — অটো-যাচাইয়ের সব শর্ত এক জায়গায়।
 *
 * 🔴🔴 এই চারটা শর্তের একটাও শিথিল করবেন না:
 *   ১. SMS-টা বিশ্বস্ত প্রেরক থেকে (`psms_sender_status() === 'trusted'`) —
 *      নাহলে যে কেউ নিজের ফোন থেকে নকল "বার্তা" পাঠিয়ে টাকা দাবি করতে পারত।
 *   ২. ওটা গ্রাহকের পেমেন্ট (`is_customer_payment`) — নিজের ক্যাশ-ইন নয়।
 *   ৩. SMS-টা আগে অন্য দাবিতে জোড়া লাগেনি (`claim_id IS NULL`)।
 *   ৪. অঙ্ক ও যে নম্বর থেকে টাকা এসেছে — মিললে তবেই অটো, নাহলে অ্যাডমিনের কাছে।
 *      🔑 নম্বর মেলানোটাই **চুরি করা TrxID** ধরার প্রধান উপায়।
 *
 * ফেরে ['ok' => bool, 'note' => '…']  — ok=false মানে "ফেলে দাও" নয়,
 * মানে "অ্যাডমিন দেখবেন" (দাবিটা জমা থাকে, শুধু verified হয় না)।
 */
function pclaim_evaluate(array $claim, array $sms): array
{
    $notes = [];

    if (psms_sender_status((string) ($sms['sender'] ?? '')) !== 'trusted') {
        $notes[] = 'প্রেরক বিশ্বস্ত নয়';
    }
    if (empty($sms['is_customer_payment'])) {
        $notes[] = 'এটা গ্রাহকের পেমেন্ট নয় (ক্যাশ-ইন)';
    }
    if (!empty($sms['claim_id'])) {
        $notes[] = 'এই SMS আগেই অন্য দাবিতে জোড়া লেগেছে';
    }

    $said = (float) ($claim['amount'] ?? 0);
    $got  = (float) ($sms['amount'] ?? 0);
    if ($said > 0.009 && abs($said - $got) > 0.5) {
        $notes[] = 'অঙ্ক মেলেনি (লেখা ' . number_format($said, 2) . ', SMS-এ ' . number_format($got, 2) . ')';
    }

    $claimPhone = psms_phone_norm((string) ($claim['phone'] ?? ''));
    $smsPhone   = psms_phone_norm((string) ($sms['sender_number'] ?? ''));
    if ($claimPhone !== '' && $smsPhone !== '' && $claimPhone !== $smsPhone) {
        $notes[] = 'টাকা এসেছে অন্য নম্বর থেকে';
    }

    return ['ok' => !$notes, 'note' => implode(' · ', $notes)];
}

// TrxID ধরে SMS খোঁজা (হুবহু মিল — 🔴 O↔0 কখনো অনুমান করা হয় না)
function pclaim_find_sms(PDO $db, string $trxNorm): ?array
{
    if ($trxNorm === '') {
        return null;
    }
    try {
        $st = $db->prepare('SELECT * FROM payment_sms WHERE trxid_norm = :t AND parse_status = \'parsed\' ORDER BY id DESC LIMIT 1');
        $st->execute(['t' => $trxNorm]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// দাবি ↔ SMS জোড়া লাগানো (দুই টেবিলেই)
function pclaim_link(PDO $db, int $claimId, array $sms, array $verdict): bool
{
    try {
        $db->prepare('UPDATE payment_claims SET matched_sms_id = :s, status = :st,
                          admin_note = CASE WHEN :n2 = \'\' THEN admin_note ELSE :n END
                      WHERE id = :id')
           ->execute([
               's'  => (int) $sms['id'],
               'st' => $verdict['ok'] ? 'verified' : 'new',
               'n'  => mb_substr($verdict['note'], 0, 500),
               'n2' => $verdict['note'],
               'id' => $claimId,
           ]);
        // 🔴 SMS-টা শুধু **নিশ্চিত** হলে দাবির সাথে বাঁধা হয় — নাহলে ওটা
        //    "দাবি হয়নি এমন টাকা" তালিকাতেই থাকবে, অ্যাডমিন দেখতে পাবেন
        if ($verdict['ok']) {
            $db->prepare('UPDATE payment_sms SET claim_id = :c WHERE id = :s AND claim_id IS NULL')
               ->execute(['c' => $claimId, 's' => (int) $sms['id']]);
        }
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * ফোন নম্বর ধরে রেজিস্ট্রেশন খোঁজা (আলাদা `payment` পাতার জন্য)
 * 🔴 সবচেয়ে সাম্প্রতিক কোর্স-রেজিস্ট্রেশন, বাতিল বাদ
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_reg_by_phone(PDO $db, string $phone): ?array
{
    $p = bd_phone_canonical($phone);
    if ($p === '') {
        return null;
    }
    try {
        $st = $db->prepare("SELECT * FROM registrations
                            WHERE phone = :p AND status <> 'cancelled'
                            ORDER BY (type = 'course') DESC, id DESC LIMIT 1");
        $st->execute(['p' => $p]);
        return $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/* ════════════════════════════════════════════════════════════════════════════
 * দাবি জমা দেওয়া — পাবলিক ফর্মের একমাত্র প্রবেশপথ
 *
 * ফেরে ['state' => 'verified'|'pending'|'duplicate'|'notfound'|'invalid'|'blocked',
 *       'message' => '…', 'claim_id' => int]
 *
 * 🔴🔴 ব্যর্থ ফলাফলে **কখনো টাকার অঙ্ক/তারিখ/নাম ফাঁস করা হয় না** — পাবলিক ফর্মটা
 *      নাহলে "কোন TrxID আসল" খুঁজে বের করার যন্ত্র হয়ে যেত, আর কেউ অন্যের
 *      পেমেন্ট নিজের নামে দাবি করতে পারত।
 * ════════════════════════════════════════════════════════════════════════════ */
function pclaim_submit(PDO $db, array $in): array
{
    $msg  = pclaim_messages();
    $fail = static fn(string $state, string $m): array => ['state' => $state, 'message' => $m, 'claim_id' => 0];

    if (!psms_ready($db)) {
        return $fail('invalid', 'পেমেন্ট যাচাই এখনো চালু হয়নি। সরাসরি যোগাযোগ করুন।');
    }

    $trxRaw = trim((string) ($in['trxid'] ?? ''));
    $trx    = psms_trx_norm($trxRaw);
    if (strlen($trx) < PCLAIM_MIN_TRX) {
        return $fail('invalid', 'TrxID ঠিকভাবে লিখুন (অন্তত ' . PCLAIM_MIN_TRX . ' অক্ষর/সংখ্যা)।');
    }

    $phone = bd_phone_canonical((string) ($in['phone'] ?? ''));
    if (!preg_match('/^01[3-9][0-9]{8}$/', $phone)) {
        return $fail('invalid', 'মোবাইল নম্বরটি ঠিকভাবে লিখুন (১১ ডিজিট, 01 দিয়ে শুরু)।');
    }

    $amount = (float) (psms_num((string) ($in['amount'] ?? '')) ?? 0);
    if ($amount < 0 || $amount > PCLAIM_MAX_AMOUNT) {
        $amount = 0;
    }

    $channel = (string) ($in['channel'] ?? '');
    if (!in_array($channel, pclaim_channels(), true)) {
        $channel = '';
    }

    // ── রেট-লিমিট (🔴 TrxID অনুমান করে করে খোঁজা ঠেকাতে) ──────────────────────
    $ip = client_ip();
    try {
        $st = $db->prepare('SELECT COUNT(*) c FROM payment_claims
                            WHERE client_ip = :ip AND created_at > (NOW() - INTERVAL 1 HOUR)');
        $st->execute(['ip' => $ip]);
        if ((int) $st->fetch()['c'] >= PCLAIM_TRY_PER_HOUR) {
            return $fail('blocked', 'একটু বেশি চেষ্টা হয়ে গেছে। কিছুক্ষণ পরে আবার চেষ্টা করুন, অথবা সরাসরি যোগাযোগ করুন।');
        }
    } catch (Throwable $e) {
        // গোনা না গেলে আটকানো হয় না
    }

    // ── রেজিস্ট্রেশন ঠিক করা ──────────────────────────────────────────────────
    $reg = null;
    $regId = (int) ($in['registration_id'] ?? 0);
    if ($regId > 0) {
        try {
            $st = $db->prepare('SELECT * FROM registrations WHERE id = :id LIMIT 1');
            $st->execute(['id' => $regId]);
            $reg = $st->fetch() ?: null;
        } catch (Throwable $e) {
            $reg = null;
        }
    }
    if (!$reg) {
        $reg = pclaim_reg_by_phone($db, $phone);
    }
    // 🔴 নম্বরটা সত্যিই কোনো রেজিস্ট্রেশনের — এটাই অচেনা লোকের TrxID-অনুসন্ধান ঠেকায়
    if (!$reg) {
        return $fail('notfound', $msg['notfound']);
    }

    // ── আগে থেকেই আছে? (🔴 UNIQUE(trxid_norm) — এক TrxID একবারই) ──────────────
    try {
        $st = $db->prepare('SELECT * FROM payment_claims WHERE trxid_norm = :t LIMIT 1');
        $st->execute(['t' => $trx]);
        $prev = $st->fetch();
    } catch (Throwable $e) {
        $prev = null;
    }
    if ($prev) {
        // একই ফোন থেকে একই TrxID = "কী হলো দেখতে এসেছেন", তাই অবস্থাটাই জানানো হয়
        if (bd_phone_canonical((string) $prev['phone']) === $phone) {
            $state = ((string) $prev['status'] === 'verified' || (string) $prev['status'] === 'posted') ? 'verified' : 'pending';
            return ['state' => $state, 'message' => $msg[$state], 'claim_id' => (int) $prev['id']];
        }
        return $fail('duplicate', $msg['duplicate']);
    }

    // ── দাবি বসানো ────────────────────────────────────────────────────────────
    try {
        $db->prepare('INSERT INTO payment_claims
                (registration_id, item_title, batch, channel, phone, amount, trxid, trxid_norm, status, client_ip)
              VALUES (:rid, :it, :ba, :ch, :ph, :am, :tx, :tn, \'new\', :ip)')
           ->execute([
               'rid' => (int) $reg['id'],
               'it'  => mb_substr((string) ($reg['item_title'] ?? ''), 0, 255),
               'ba'  => mb_substr((string) ($reg['batch'] ?? ''), 0, 100),
               'ch'  => $channel,
               'ph'  => $phone,
               'am'  => $amount,
               'tx'  => mb_substr($trxRaw, 0, 40),
               'tn'  => $trx,
               'ip'  => $ip,
           ]);
        $claimId = (int) $db->lastInsertId();
    } catch (Throwable $e) {
        // UNIQUE-এ ধাক্কা খেলে (দুটো সমান্তরাল সাবমিট) — ডুপ্লিকেটই
        return $fail('duplicate', $msg['duplicate']);
    }

    // ── SMS এসে থাকলে এখনই মিলিয়ে দেখা ────────────────────────────────────────
    $claim = ['id' => $claimId, 'phone' => $phone, 'amount' => $amount];
    $sms = pclaim_find_sms($db, $trx);
    if ($sms) {
        $verdict = pclaim_evaluate($claim, $sms);
        pclaim_link($db, $claimId, $sms, $verdict);
        if ($verdict['ok']) {
            pclaim_maybe_confirm($db, $reg, $claimId);
            return ['state' => 'verified', 'message' => $msg['verified'], 'claim_id' => $claimId];
        }
    }

    // ⏳ এখনো মেলেনি — 🔴 "ভুল TrxID" **বলা হয় না**: SMS কয়েক মিনিট পরেও আসতে
    //    পারে (ফোন ঘুমিয়ে থাকলে), আর সত্যিই টাকা দেওয়া অভিভাবক ভয় পেয়ে আবার
    //    টাকা পাঠাতেন।
    return ['state' => 'pending', 'message' => $msg['pending'], 'claim_id' => $claimId];
}

/* ────────────────────────────────────────────────────────────────────────────
 * অটো-কনফার্ম — 🔴 ডিফল্টে **বন্ধ** (settings `pay_auto_confirm` = '1' হলে চালু)
 *
 * 🔴🔴 এখানেও খাতা/আয় ছোঁয়া হয় না — শুধু `registrations.status` pending → confirmed।
 *      খাতা না থাকলে কনফার্মে এমনিতেও আয় বসে না (২০২৬-০৯-২৯-এর নিয়ম), তাই এটা
 *      টাকার হিসাবের দিক থেকে নিরাপদ। টাকা খাতায় বসানো ধাপ ৩-এর কাজ।
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_maybe_confirm(PDO $db, array $reg, int $claimId): bool
{
    if (get_setting('pay_auto_confirm') !== '1') {
        return false;
    }
    if ((string) ($reg['status'] ?? '') !== 'pending') {
        return false;
    }
    try {
        $db->prepare("UPDATE registrations SET status = 'confirmed' WHERE id = :id AND status = 'pending'")
           ->execute(['id' => (int) $reg['id']]);
        $db->prepare('UPDATE payment_claims SET auto_confirmed = 1 WHERE id = :id')->execute(['id' => $claimId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/* ────────────────────────────────────────────────────────────────────────────
 * দেরিতে আসা SMS — অপেক্ষমাণ দাবিগুলো আবার মিলিয়ে দেখা
 * 🔑 `psms_store()` প্রতিটা নতুন SMS জমা হওয়ার পর এটা ডাকে, তাই অভিভাবক আগে
 *    TrxID দিলেও SMS আসা মাত্রই দাবিটা নিজে থেকেই ✅ হয়ে যায়।
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_rematch(PDO $db, ?string $trxNorm = null): int
{
    if (!psms_ready($db)) {
        return 0;
    }
    $done = 0;
    try {
        $sql = "SELECT * FROM payment_claims
                WHERE status = 'new' AND matched_sms_id IS NULL
                  AND created_at > (NOW() - INTERVAL " . (int) PCLAIM_REMATCH_DAYS . " DAY)";
        $params = [];
        if ($trxNorm !== null && $trxNorm !== '') {
            $sql .= ' AND trxid_norm = :t';
            $params['t'] = $trxNorm;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . (int) PCLAIM_REMATCH_MAX;
        $st = $db->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $claim) {
            $sms = pclaim_find_sms($db, (string) $claim['trxid_norm']);
            if (!$sms) {
                continue;
            }
            $verdict = pclaim_evaluate($claim, $sms);
            pclaim_link($db, (int) $claim['id'], $sms, $verdict);
            if ($verdict['ok'] && !empty($claim['registration_id'])) {
                $r = $db->prepare('SELECT * FROM registrations WHERE id = :id LIMIT 1');
                $r->execute(['id' => (int) $claim['registration_id']]);
                $reg = $r->fetch();
                if ($reg) {
                    pclaim_maybe_confirm($db, $reg, (int) $claim['id']);
                }
            }
            $done++;
        }
    } catch (Throwable $e) {
        // চুপচাপ — মেলানো কখনো SMS জমা হওয়া ভাঙবে না
    }
    return $done;
}

// একটা দাবির বর্তমান অবস্থা (পাতা নিজে থেকে কয়েকবার দেখে নেয়)
function pclaim_state(PDO $db, int $claimId): string
{
    try {
        $st = $db->prepare('SELECT status FROM payment_claims WHERE id = :id LIMIT 1');
        $st->execute(['id' => $claimId]);
        $s = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return 'pending';
    }
    return ($s === 'verified' || $s === 'posted') ? 'verified' : 'pending';
}

// একটা দাবির অবস্থা পাতা থেকে দেখে নেওয়ার চাবি (pay-status.php) — 🔴 আইডি
// অনুমান করে অন্যের দাবির অবস্থা পড়া আটকাতে, পেমেন্ট লিংকের মতোই HMAC
function pclaim_token(int $claimId): string
{
    $secret = pay_link_secret();
    return ($secret === '' || $claimId <= 0) ? '' : substr(hash_hmac('sha256', 'claim:' . $claimId, $secret), 0, 20);
}

function pclaim_token_valid(int $claimId, string $token): bool
{
    $want = pclaim_token($claimId);
    return $want !== '' && $token !== '' && hash_equals($want, $token);
}

/* ────────────────────────────────────────────────────────────────────────────
 * 💬 "মিলছে না? সরাসরি জানান" — WhatsApp বোতাম
 * 🔴 নম্বরটা `contact_whatsapp` সেটিং থেকেই (ফুটারের ভাসমান বোতামের সাথে এক),
 *    আর `normalize_bd_whatsapp()` দিয়ে যাচাই — ভুল নম্বরে বোতামই দেখায় না।
 * ──────────────────────────────────────────────────────────────────────────── */
function pclaim_whatsapp_url(array $ctx = []): string
{
    $wa = normalize_bd_whatsapp(get_setting('contact_whatsapp'));
    if ($wa === '') {
        return '';
    }
    $lines = ['পেমেন্ট যাচাই নিয়ে সাহায্য দরকার।'];
    if (!empty($ctx['ref'])) {
        $lines[] = 'রেজিস্ট্রেশন নম্বর: #' . (int) $ctx['ref'];
    }
    if (!empty($ctx['item'])) {
        $lines[] = 'কোর্স: ' . $ctx['item'];
    }
    if (!empty($ctx['phone'])) {
        $lines[] = 'মোবাইল: ' . $ctx['phone'];
    }
    if (!empty($ctx['trxid'])) {
        $lines[] = 'TrxID: ' . $ctx['trxid'];
    }
    return 'https://wa.me/' . $wa . '?text=' . rawurlencode(implode("\n", $lines));
}
