<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📥 পেমেন্ট ইনবক্স — ফোন থেকে আসা SMS ও অভিভাবকের দাবি  (২০২৬-১০-০৬, ধাপ ১)
 *
 * তিনটা জিনিস এক পাতায়:
 *   ১. 📶 হার্টবিট — শেষ কখন SMS এসেছে (ফোন বন্ধ/নেট নেই = একক ব্যর্থতার বিন্দু)
 *   ২. 💰 অদাবিকৃত টাকা — SMS এসেছে, কেউ এখনো দাবি করেননি
 *   ৩. 📝 অমীমাংসিত দাবি — অভিভাবক TrxID দিয়েছেন; মিলে গেলে "💰 খাতায় বসান" বোতাম আসে
 *
 * 🔴🔴 কোনো **অটোমেটিক** পথ টাকার বইয়ে কিছু লেখে না — SMS আসা, দাবি জমা পড়া,
 *      অটো-যাচাই, হাতে মিলিয়ে দেওয়া: কোনোটাতেই `registration_payments`/`income`
 *      ছোঁয়া হয় না। **একমাত্র ব্যতিক্রম** "💰 খাতায় বসান" বোতাম (ধাপ ৩,
 *      ২০২৬-১০-১৩) — অ্যাডমিন নিজে চাপলে, আর তখনও বিদ্যমান প্রমাণিত পথেই
 *      (`pay_ensure_plan` → `pay_apply_payment` → `pay_save_rows` → `sync_income_for_status`)।
 * ════════════════════════════════════════════════════════════════════════════ */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/payment-sms.php';
require_once __DIR__ . '/../includes/payment-claim.php';
require_once __DIR__ . '/includes/payments.php';      // 💰 ধাপ ৩ — খাতায় বসানো
require_once __DIR__ . '/includes/income-sync.php';   // 🔴 শুধু অ্যাডমিন পেজ থেকে require করা যায়
admin_require_login();

$db = get_db();
$pageTitle = 'পেমেন্ট ইনবক্স';
$ready = psms_ready($db);

// 🔴 গোপন চাবি শুধু মূল অ্যাডমিন দেখেন — চাবিটা জানলে যেকেউ SMS ঢোকাতে পারে
$canKey = admin_is_super();

// ---------------- POST ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_GET['action'] ?? '';
    if (!csrf_verify()) {
        set_flash('error', 'ফর্ম টোকেন মিলছে না।');
        redirect('payment-inbox.php');
    }

    // গোপন চাবি তৈরি / বদল / বন্ধ
    if ($act === 'key') {
        if (!$canKey) {
            set_flash('error', 'গোপন চাবি শুধু মূল অ্যাডমিন বদলাতে পারেন।');
            redirect('payment-inbox.php');
        }
        $mode = $_POST['mode'] ?? 'new';
        if ($mode === 'off') {
            update_setting('sms_in_secret', '');
            set_flash('success', 'চাবি বন্ধ করা হলো — এখন আর কোনো SMS গ্রহণ করা হবে না।');
        } else {
            update_setting('sms_in_secret', bin2hex(random_bytes(16)));
            set_flash('success', 'নতুন চাবি তৈরি হয়েছে। 🔴 ফোনের MacroDroid-এ নতুন চাবিটা বসাতে ভুলবেন না — নাহলে SMS আসা বন্ধ হয়ে যাবে।');
        }
        redirect('payment-inbox.php');
    }

    /* 🗑️ মুছে ফেলা — SMS অথবা দাবি
     * 🔴 মার্কারটা ইচ্ছাকৃতভাবে বিদ্যমান `delete`-ই (group-match.php-এর মতো):
     *    `admin_delete_actions()`-এ নতুন কিছু যোগ করতে হয়নি আর কেন্দ্রীয় গার্ড
     *    এমনিতেই **delete cap** চায়। কোনটা মুছবে সেটা `what` বলে। */
    if ($act === 'delete') {
        $id   = (int) ($_POST['id'] ?? 0);
        $what = (string) ($_POST['what'] ?? 'sms');

        if ($what === 'claim') {
            /* 🔴 দাবি মোছা নিরাপদ ও কখনো কখনো দরকারি: এটা নিছক অভিভাবকের লেখা
             *    একটা দাবি, টাকার প্রমাণ নয়। মুছলে —
             *      (ক) `UNIQUE(trxid_norm)` ছেড়ে যায়, তাই ভুল TrxID লিখে ফেললে
             *          অভিভাবক ঠিকটা আবার লিখতে পারেন (নাহলে চিরতরে আটকে থাকতেন),
             *      (খ) জোড়া লাগা SMS থাকলে FK `ON DELETE SET NULL`-এ সেটা নিজে
             *          থেকেই "অদাবিকৃত টাকা" তালিকায় ফিরে যায়।
             *    টাকার খাতা/আয় এখানে ছোঁয়াই হয় না। */
            try {
                $db->prepare('DELETE FROM payment_claims WHERE id = :id')->execute(['id' => $id]);
                set_flash('success', 'দাবিটা মুছে ফেলা হলো। একই TrxID এখন আবার লেখা যাবে।');
            } catch (Throwable $e) {
                set_flash('error', 'মুছতে সমস্যা হয়েছে।');
            }
            redirect('payment-inbox.php');
        }

        try {
            // 🔴 দাবির সাথে জোড়া লেগে যাওয়া SMS মোছা যাবে না — ওটা টাকার প্রমাণ
            $db->prepare('DELETE FROM payment_sms WHERE id = :id AND claim_id IS NULL')->execute(['id' => $id]);
            set_flash('success', 'বার্তাটা মুছে ফেলা হয়েছে।');
        } catch (Throwable $e) {
            set_flash('error', 'মুছতে সমস্যা হয়েছে।');
        }
        redirect('payment-inbox.php');
    }

    /* ✅ হাতে মিলিয়ে দেওয়া  (২০২৬-১০-০৬)
     *
     * অটো-মিল না হলে অ্যাডমিন নিজে চোখে দেখে সিদ্ধান্ত নেন — এটাই সেই বোতাম।
     * `sms_id` দিলে ঐ বার্তাটাও দাবির সাথে জোড়া লেগে যায় (তখন ওটা আর
     * "অদাবিকৃত টাকা" তালিকায় দেখায় না, আর দুইবার গোনার ভয় থাকে না)।
     *
     * 🔴🔴 এখানেও টাকার খাতায় (`registration_payments`) বা আয়ে কিচ্ছু বসে না —
     *      শুধু দাবির `status` বদলায়। খাতায় বসানো আলাদা একটা ধাপ, নিচের
     *      `claim-post` হ্যান্ডলার (অ্যাডমিনকে আরেকবার বোতাম চাপতে হয়)।
     * 🔴 মার্কারটা delete-সেটে নেই, তাই কেন্দ্রীয় গার্ড **edit cap** চায় — ঠিকই
     *    আছে, কিছু মোছা হচ্ছে না।
     */
    if ($act === 'claim-ok') {
        $id    = (int) ($_POST['id'] ?? 0);
        $smsId = (int) ($_POST['sms_id'] ?? 0);
        try {
            $st = $db->prepare('SELECT * FROM payment_claims WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $claim = $st->fetch();
            if (!$claim) {
                set_flash('error', 'দাবিটা পাওয়া যায়নি।');
                redirect('payment-inbox.php');
            }

            $db->beginTransaction();

            if ($smsId > 0) {
                // 🔴 বার্তাটা সত্যিই আছে ও এখনো অন্য দাবিতে জোড়া লাগেনি — DB থেকে
                //    যাচাই (POST-এর id-তে কখনো ভরসা নয়)
                $s2 = $db->prepare('SELECT * FROM payment_sms WHERE id = :s LIMIT 1');
                $s2->execute(['s' => $smsId]);
                $sms = $s2->fetch();
                if ($sms && (empty($sms['claim_id']) || (int) $sms['claim_id'] === $id)) {
                    $db->prepare('UPDATE payment_claims SET matched_sms_id = :s WHERE id = :id')
                       ->execute(['s' => $smsId, 'id' => $id]);
                    // 🔴 একই নামের প্লেসহোল্ডার দুইবার দেওয়া যায় না (ATTR_EMULATE_PREPARES=false
                    //    হলে MySQL `SQLSTATE[HY093]` ছোঁড়ে) — তাই :c ও :c2
                    $db->prepare('UPDATE payment_sms SET claim_id = :c WHERE id = :s AND (claim_id IS NULL OR claim_id = :c2)')
                       ->execute(['c' => $id, 'c2' => $id, 's' => $smsId]);
                } else {
                    $smsId = 0; // নীরবে বাদ — দাবিটা তবু "মিলিয়ে দেওয়া" হবে
                }
            }

            $note = '✋ হাতে মিলিয়ে দেওয়া হয়েছে' . ($smsId > 0 ? ' (বার্তা #' . $smsId . ')' : '');
            $old  = trim((string) ($claim['admin_note'] ?? ''));
            $db->prepare('UPDATE payment_claims SET status = \'verified\', admin_note = :n WHERE id = :id')
               ->execute(['n' => mb_substr($old === '' ? $note : $note . ' · ' . $old, 0, 500), 'id' => $id]);

            $db->commit();
            set_flash('success', 'দাবিটা "যাচাই হয়েছে" হিসেবে চিহ্নিত হলো। এখন অ্যাকশন ঘরে "💰 খাতায় বসান" বোতামটা চাপলেই টাকাটা অর্ডারের খাতায় ও আয়ে যাবে।');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash('error', 'সংরক্ষণে সমস্যা হয়েছে।');
        }
        redirect('payment-inbox.php');
    }

    /* 💰💰 খাতায় বসান  (২০২৬-১০-১৩, ধাপ ৩)
     *
     * যাচাই হওয়া দাবির টাকাটা অর্ডারের **টাকার খাতায়** বসিয়ে দেয় — এতদিন অ্যাডমিনকে
     * অর্ডারে গিয়ে হাতে লিখতে হতো।
     *
     * 🔴🔴 এটাই এই সাবসিস্টেমের **একমাত্র** জায়গা যেখান থেকে `registration_payments`-এ
     *      লেখা হয়, আর সেটা হয় শুধু অ্যাডমিন নিজে বোতাম চাপলে — কোনো অটোমেটিক পথ
     *      (SMS আসা · দাবি জমা · অটো-যাচাই) কখনো খাতায় বা আয়ে কিছু লেখে না।
     * 🔴 পথটা হুবহু প্রমাণিত পথ: (খাতা না থাকলে) `pay_ensure_plan()` → `pay_apply_payment()`
     *      → `pay_save_rows()` → **তারপর** `sync_income_for_status()`। আলাদা কোনো আয়-লজিক
     *      লেখা হয়নি (income-sync.php শেয়ার্ড থাকার কারণই এটা)।
     * 🔴 `pay_allocate_paid()` ব্যবহার করা হয় না — ওটা সব সারি নতুন করে বসায়, অর্থাৎ
     *      অ্যাডমিনের হাতে বসানো আগের জমা মুছে যেত। `pay_apply_payment()` **যোগ করে**।
     */
    if ($act === 'claim-post') {
        $id     = (int) ($_POST['id'] ?? 0);
        $amount = round((float) str_replace(',', '', (string) ($_POST['amount'] ?? '0')), 2);
        $alsoConfirm = !empty($_POST['confirm_order']);

        try {
            $st = $db->prepare('SELECT * FROM payment_claims WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $claim = $st->fetch();

            // 🔴 শুধু "যাচাই হয়েছে" দাবিই বসানো যায় — অযাচাই (new) দাবি বসালে ভুয়া TrxID-ও
            //    টাকা হয়ে যেত, আর আগে বসানো (posted) দাবি দুইবার বসত।
            if (!$claim || $claim['status'] !== 'verified') {
                set_flash('error', $claim ? 'এই দাবিটা এখনো "যাচাই হয়েছে" অবস্থায় নেই (বা আগেই বসানো হয়েছে)।' : 'দাবিটা পাওয়া যায়নি।');
                redirect('payment-inbox.php');
            }
            if ($amount <= 0 || $amount > PCLAIM_MAX_AMOUNT) {
                set_flash('error', 'টাকার অঙ্কটা ঠিক নেই — ০-এর বেশি একটা সংখ্যা লিখুন।');
                redirect('payment-inbox.php');
            }

            $regId = (int) ($claim['registration_id'] ?? 0);
            $r2 = $db->prepare('SELECT * FROM registrations WHERE id = :id LIMIT 1');
            $r2->execute(['id' => $regId]);
            $reg = $r2->fetch();
            if (!$reg) {
                set_flash('error', 'দাবিটা কোনো অর্ডারের সাথে জোড়া নেই, তাই খাতায় বসানো যাচ্ছে না।');
                redirect('payment-inbox.php');
            }

            $incBefore = (float) $db->query('SELECT COALESCE(SUM(amount), 0) s FROM income')->fetch()['s'];

            $db->beginTransaction();

            /* অর্ডারটা এখনো pending হলে কনফার্ম করা — নাহলে টাকা খাতায় বসলেও আয়ে যেত না
             * (আয়ের গেট `INCOME_STATUSES`)। 🔴 শুধু pending → confirmed, আর শুধু
             * অ্যাডমিন টিক রাখলে; অন্য কোনো স্ট্যাটাস কখনো বদলানো হয় না।
             * 🔴 এটা `pay_collect()`-এর **আগে**, একই ট্রানজ্যাকশনের ভেতরে — কারণ ঐ
             *    ফাংশনের ভেতরের `sync_income_for_status()` `$reg['status']`-ই দেখে।
             *    টাকা বসাতে ব্যর্থ হলে নিচের rollback এই কনফার্মটাও ফিরিয়ে নেয়। */
            $didConfirm = false;
            if ($alsoConfirm && ($reg['status'] ?? '') === 'pending') {
                $db->prepare("UPDATE registrations SET status = 'confirmed' WHERE id = :id")->execute(['id' => $regId]);
                $reg['status'] = 'confirmed';
                $didConfirm = true;
            }

            /* 🔴 টাকা বসানোর একটাই দরজা — `pay_collect()` (admin/includes/income-sync.php)।
             *    ভেতরে হুবহু সেই প্রমাণিত পথ: pay_ensure_plan() → pay_apply_payment() →
             *    pay_save_rows() → due_amount → **তারপর** sync_income_for_status()।
             *    registrations.php-এর ড্রয়ার ও course-parcel.php-এর "টাকা মিলিয়ে নিন"-ও
             *    এই একটাই ফাংশন ডাকে — এখানে আলাদা করে আর লিখবেন না। */
            $res = pay_collect($db, $reg, $amount, 'claim');
            if (!$res['ok']) {
                $db->rollBack();
                set_flash('error', $res['code'] === 'mismatch'
                    ? 'হিসাব মিলছে না, তাই কিছুই বসানো হয়নি। অর্ডারের "টাকা" খাতায় নিজে বসান।'
                    : $res['err']);
                redirect('payment-inbox.php');
            }
            $sum = ['balance' => $res['balance']];

            /* 🔗 মিলে যাওয়া বার্তাটা এখনো জোড়া না লাগানো থাকলে এখানেই লাগিয়ে দেওয়া —
             * নাহলে টাকাটা খাতায় বসার পরেও বার্তাটা "দাবি হয়নি এমন টাকা" তালিকায়
             * পড়ে থাকত আর অ্যাডমিন চোখে দুইবার গুনতেন।
             * 🔴 বার্তাটা সত্যিই আছে ও অন্য দাবিতে লাগেনি — DB থেকেই যাচাই। */
            $mId = (int) ($claim['matched_sms_id'] ?? 0);
            if ($mId > 0) {
                $db->prepare('UPDATE payment_sms SET claim_id = :c WHERE id = :s AND claim_id IS NULL')
                   ->execute(['c' => $id, 's' => $mId]);
            }

            $note = '💰 খাতায় বসানো হয়েছে — ' . number_format($amount, 2);
            $old  = trim((string) ($claim['admin_note'] ?? ''));
            $db->prepare("UPDATE payment_claims
                            SET status = 'posted', posted_at = NOW(), posted_by = :by, admin_note = :n
                          WHERE id = :id AND status = 'verified'")
               ->execute([
                   'by' => (int) ($_SESSION['admin_id'] ?? 0),
                   'n'  => mb_substr($old === '' ? $note : $note . ' · ' . $old, 0, 500),
                   'id' => $id,
               ]);

            $db->commit();

            $incAfter = (float) $db->query('SELECT COALESCE(SUM(amount), 0) s FROM income')->fetch()['s'];
            set_flash('success', '✅ ' . pi_money($amount) . ' টাকার খাতায় বসানো হয়েছে।'
                . ($didConfirm ? ' অর্ডারটাও কনফার্ম করা হলো।' : '')
                . ' বইয়ের মোট আয় ' . pi_money($incBefore) . ' → ' . pi_money($incAfter) . '।'
                . ' এখন বাকি ' . pi_money($sum['balance']) . '।');
        } catch (Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            set_flash('error', 'খাতায় বসাতে সমস্যা হয়েছে — কিছুই বদলায়নি।');
        }
        redirect('payment-inbox.php');
    }

    redirect('payment-inbox.php');
}

// ---------------- ডেটা ----------------
$secret   = $ready ? trim(get_setting('sms_in_secret')) : '';
$lastSms  = $ready ? psms_last_received($db) : null;
$stale    = $ready ? psms_heartbeat_stale($db) : false;
$endpoint = rtrim(SITE_URL, '/') . '/sms-in.php';

$unclaimed = $unparsed = $claims = $nearBy = $matchedBy = $regStatuses = $posted = [];
$counts = ['sms' => 0, 'unclaimed' => 0, 'unparsed' => 0, 'claims' => 0, 'untrusted' => 0];

if ($ready) {
    try {
        $counts['sms'] = (int) $db->query('SELECT COUNT(*) c FROM payment_sms')->fetch()['c'];

        // 💰 অদাবিকৃত গ্রাহক-পেমেন্ট (নিজের ক্যাশ-ইন বাদ — is_customer_payment)
        $unclaimed = $db->query("SELECT * FROM payment_sms
                                 WHERE claim_id IS NULL AND parse_status = 'parsed' AND is_customer_payment = 1
                                 ORDER BY received_at DESC LIMIT 100")->fetchAll();
        $counts['unclaimed'] = (int) $db->query("SELECT COUNT(*) c FROM payment_sms
                                 WHERE claim_id IS NULL AND parse_status = 'parsed' AND is_customer_payment = 1")->fetch()['c'];

        // ⚠️ পড়া যায়নি — ছাঁচ ঠিক করে আবার পড়ানো যাবে
        $unparsed = $db->query("SELECT * FROM payment_sms
                                WHERE parse_status <> 'parsed' ORDER BY received_at DESC LIMIT 50")->fetchAll();
        $counts['unparsed'] = (int) $db->query("SELECT COUNT(*) c FROM payment_sms WHERE parse_status <> 'parsed'")->fetch()['c'];

        // 📝 অমীমাংসিত দাবি — বসানো (posted) হয়ে গেলে তালিকা থেকে চলে যায়
        $claims = $db->query("SELECT * FROM payment_claims
                              WHERE status IN ('new', 'verified') ORDER BY created_at DESC LIMIT 100")->fetchAll();
        $counts['claims'] = count($claims);

        /* 🔎 "প্রায় মিলেছে" — I↔1 / O↔0 গুলিয়ে ফেলা TrxID ধরার জন্য।
         * 🔴 শুধু ইঙ্গিত; অ্যাডমিন না চাপলে কিচ্ছু হয় না (উপরের ইঞ্জিনের ঘর দেখুন)। */
        $nearBy = pclaim_near_matches($db, $claims);

        /* 🔗 যেসব দাবির TrxID মিলেছিল কিন্তু কোনো শর্তে আটকে গেছে — ঐ বার্তাটা
         * পাশে দেখানোর জন্য (নাহলে কোন বার্তার কথা বলা হচ্ছে বোঝাই যেত না)। */
        $matchedBy = pclaim_matched_sms($db, $claims);

        /* 💰 ধাপ ৩ — কোন অর্ডার এখনো pending, এক কোয়েরিতে (N+1 এড়াতে)।
         * দরকার কারণ: টাকা খাতায় বসলেও স্ট্যাটাস আয়-যোগ্য না হলে আয়ে যায় না,
         * তাই বোতামের পাশে "অর্ডারটাও কনফার্ম করুন" টিক দেখাতে হয়। */
        $regIds = array_values(array_filter(array_map(fn($c) => (int) ($c['registration_id'] ?? 0), $claims)));
        if ($regIds) {
            $ph = implode(',', array_fill(0, count($regIds), '?'));
            $rs = $db->prepare("SELECT id, status FROM registrations WHERE id IN ($ph)");
            $rs->execute($regIds);
            $regStatuses = array_column($rs->fetchAll(), 'status', 'id');
        }

        // ✔ সম্প্রতি খাতায় বসানো — উপরের তালিকা থেকে উধাও হয়ে যায় বলে একটা ছোট হিসাব
        $posted = $db->query("SELECT * FROM payment_claims WHERE status = 'posted'
                              ORDER BY posted_at DESC, id DESC LIMIT 8")->fetchAll();

        // 🛡️ এই তালিকায় কয়টা SMS অবিশ্বস্ত প্রেরক থেকে এসেছে
        // 🔴 গণনা PHP-তে, SQL-এ নয় — তালিকাটা settings থেকে আসে, আর অ্যাডমিন
        //    নাম যোগ/বাদ দিলে সাথে সাথেই এখানে প্রতিফলিত হওয়া দরকার।
        foreach ($unclaimed as $u) {
            if (psms_sender_status((string) ($u['sender'] ?? '')) !== 'trusted') {
                $counts['untrusted']++;
            }
        }
    } catch (Throwable $e) {
        // 🔴 পুরো ব্লক try/catch-এ — একটা কোয়েরি ব্যর্থ হলেও পাতা খোলে
    }
}

// টাকার অঙ্ক দেখানো
function pi_money($v): string
{
    return $v === null ? '—' : '৳' . number_format((float) $v, 2);
}

/* 🛡️ প্রেরকের রায় → একটা ছোট চিপ
 * 🔴 শুধু "trusted" মানেই নিশ্চিত — বাকি তিনটা অবস্থাতেই অ্যাডমিনকে নিজে যাচাই
 *    করতে হবে, কারণ ফোন নম্বর থেকে যে কেউ নকল বার্তা পাঠাতে পারে।
 */
function pi_sender_chip(?string $sender): string
{
    $st  = psms_sender_status((string) $sender);
    $raw = trim((string) $sender);
    $map = [
        'trusted' => ['✅', 'bg-green-50 text-green-800 border-green-200',   'অপারেটরের নিবন্ধিত নাম — নকল করা যায় না'],
        'number'  => ['⚠️', 'bg-red-50 text-red-800 border-red-200',        'একটা ফোন নম্বর থেকে এসেছে — যে কেউ এমন বার্তা পাঠাতে পারে, যাচাই না করে টাকা ধরবেন না'],
        'unknown' => ['❓', 'bg-amber-50 text-amber-800 border-amber-200',  'এই নামটা বিশ্বস্ত তালিকায় নেই — আসল হলে সাইট সেটিংস → "টাকার SMS — বিশ্বস্ত প্রেরক"-এ যোগ করুন'],
        'missing' => ['❓', 'bg-gray-50 text-gray-600 border-gray-200',     'ফোন প্রেরকের নামই পাঠায়নি — MacroDroid-এর Body-তে Incoming SMS number বসানো আছে কিনা দেখুন'],
    ];
    [$ic, $cls, $why] = $map[$st] ?? $map['missing'];
    $label = $raw !== '' ? $raw : 'নাম নেই';

    // ⚠️ `py-0.5` কম্পাইলড অ্যাডমিন CSS-এ নেই — তাই inline (Tailwind রিবিল্ড এড়াতে)
    return '<span class="inline-flex items-center gap-1 border rounded-full px-2 text-xs ' . $cls . '"'
        . ' style="padding-top:2px;padding-bottom:2px"'
        . ' title="' . e($why) . '">' . $ic . ' ' . e($label) . '</span>';
}

// "৩ ঘণ্টা আগে" ধরনের লেখা
function pi_ago(?string $ts): string
{
    if (!$ts) {
        return '—';
    }
    $d = time() - strtotime($ts);
    if ($d < 60) {
        return 'এইমাত্র';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . ' মিনিট আগে';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . ' ঘণ্টা আগে';
    }
    return intdiv($d, 86400) . ' দিন আগে';
}

require_once __DIR__ . '/includes/layout-top.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">📥 পেমেন্ট ইনবক্স</h1>
    <p class="text-sm text-gray-500 mt-1">
        ফোন থেকে আসা বিকাশ/নগদের টাকা-পাওয়ার বার্তাগুলো এখানে জমা হয়।
        যাচাই হয়ে যাওয়া দাবির টাকা <b>"💰 খাতায় বসান"</b> চাপলেই অর্ডারের খাতায় ও আয়ের বইয়ে যায় —
        <b>আপনি না চাপলে নিজে থেকে কিচ্ছু বসে না</b>।
    </p>
</div>

<?php if (!$ready): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-xl p-4 mb-6 text-sm">
        ⚠️ এই অংশটা এখনো চালু হয়নি — phpMyAdmin-এ একবার
        <code class="bg-white px-1 rounded">database/migrate-payment-sms.sql</code> চালাতে হবে
        (লাইভ ও লোকাল দুটোতেই)। ততক্ষণ সাইটের বাকি সব আগের মতোই চলবে।
    </div>
<?php endif; ?>

<!-- ─── সারাংশ ─── -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
    <?php
    $cards = [
        ['💰', 'দাবি হয়নি এমন টাকা', $counts['unclaimed'], 'text-green-700'],
        ['📝', 'অমীমাংসিত দাবি',      $counts['claims'],    'text-amber-700'],
        ['⚠️', 'পড়া যায়নি',          $counts['unparsed'],  'text-red-700'],
        ['📨', 'মোট জমা SMS',         $counts['sms'],       'text-gray-700'],
    ];
    foreach ($cards as [$ic, $lb, $n, $cls]): ?>
        <div class="bg-white rounded-2xl shadow p-4 min-w-0">
            <p class="text-xs text-gray-500"><?= $ic ?> <?= e($lb) ?></p>
            <p class="text-2xl font-bold <?= $cls ?>" style="word-break:break-all"><?= (int) $n ?></p>
        </div>
    <?php endforeach; ?>
</div>

<!-- ─── ১. হার্টবিট ─── -->
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h2 class="font-bold text-gray-800 mb-3">📶 ফোনটা কাজ করছে?</h2>
    <?php if (!$ready): ?>
        <p class="text-sm text-gray-500">মাইগ্রেশন চালানোর পর এখানে দেখা যাবে।</p>
    <?php elseif ($secret === ''): ?>
        <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-lg p-3 text-sm">
            🔑 এখনো কোনো গোপন চাবি তৈরি হয়নি — তাই <b>কোনো SMS গ্রহণ করা হচ্ছে না</b>।
            নিচের ঘর থেকে একটা চাবি বানিয়ে ফোনের MacroDroid-এ বসান।
        </div>
    <?php elseif ($lastSms === null): ?>
        <div class="bg-blue-50 border border-blue-200 text-gray-700 rounded-lg p-3 text-sm">
            চাবি তৈরি আছে, কিন্তু এখনো একটাও SMS আসেনি। ফোনে MacroDroid বসানো হয়ে গেলে
            নিজের নম্বর থেকে ৫ টাকা পাঠিয়ে পরীক্ষা করে দেখুন।
        </div>
    <?php elseif ($stale): ?>
        <div class="bg-red-50 border border-red-200 text-red-800 rounded-lg p-3 text-sm">
            🔴 শেষ SMS এসেছে <b><?= e(pi_ago($lastSms)) ?></b> (<?= e($lastSms) ?>) —
            <?= (int) PSMS_HEARTBEAT_HRS ?> ঘণ্টার বেশি চুপচাপ। ফোনটা চালু আছে কিনা, নেট আছে কিনা,
            আর ব্যাটারি-সেভার MacroDroid বন্ধ করে দিয়েছে কিনা দেখুন।
        </div>
    <?php else: ?>
        <div class="bg-green-50 border border-green-200 text-green-800 rounded-lg p-3 text-sm">
            ✅ শেষ SMS এসেছে <b><?= e(pi_ago($lastSms)) ?></b> (<?= e($lastSms) ?>)।
        </div>
    <?php endif; ?>
</div>

<!-- ─── ফোন সেটআপ (শুধু মূল অ্যাডমিন) ─── -->
<?php if ($canKey): ?>
<div class="bg-white rounded-2xl shadow p-5 mb-6">
    <h2 class="font-bold text-gray-800 mb-1">🔑 ফোন সেটআপ (MacroDroid)</h2>
    <p class="text-xs text-gray-500 mb-3">
        একটা পুরনো অ্যান্ড্রয়েড ফোনে MacroDroid অ্যাপ বসান, তারপর নিচের তথ্য দিয়ে একটা macro বানান।
        🔴 ঐ ফোনে <b>ব্যাটারি অপটিমাইজেশন বন্ধ</b> রাখতে হবে, নাহলে অ্যান্ড্রয়েড অ্যাপটাকে ঘুম পাড়িয়ে দেয়।
    </p>

    <div class="bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm space-y-2 mb-4">
        <div>
            <p class="text-xs text-gray-500 mb-1">Trigger</p>
            <p class="font-bold">SMS Received → Any Number → SMS Content: <b>Contains</b> <code>TrxID</code></p>
            <p class="text-xs text-gray-500 mt-1">
                একই macro-তে আরেকটা Trigger যোগ করুন <code>TxnID</code> দিয়ে (বিকাশ লেখে TrxID, নগদ লেখে TxnID)।
                এতে শুধু টাকার বার্তাই সার্ভারে আসে, ব্যক্তিগত SMS নয়।
            </p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Action → HTTP Request (POST)</p>
            <div class="min-w-0 flex flex-wrap items-center gap-2">
                <code class="bg-white border rounded px-2 py-1 text-xs" style="word-break:break-all"><?= e($endpoint) ?></code>
                <button type="button" class="text-indigo-600 font-bold text-xs" data-sms-copy="<?= e($endpoint) ?>">🔗 কপি</button>
            </div>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">Content Body — <code>application/x-www-form-urlencoded</code></p>
            <code class="bg-white border rounded px-2 py-1 text-xs block" style="word-break:break-all">key=&lt;চাবি&gt;&amp;sender={sms_number}&amp;text={sms_message}</code>
            <p class="text-xs text-gray-500 mt-1">
                🔴 বন্ধনীর অংশ দুটো হাতে লিখবেন না — ঘরের পাশের <b>…</b> বোতাম চেপে
                <b>Incoming SMS number</b> ও <b>Incoming SMS message</b> বেছে নিন
                (MacroDroid-এর ভার্সনভেদে বন্ধনী <code>{ }</code> বা <code>[ ]</code> হতে পারে, অ্যাপ নিজে যা বসায় সেটাই ঠিক)।
                ⚠️ <b>Incoming SMS contact</b> নয় — ওটা ফোনবুকে সেভ করা নাম, bKash/NAGAD সেভ না থাকলে খালি আসে।
            </p>
        </div>
        <div>
            <p class="text-xs text-gray-500 mb-1">গোপন চাবি</p>
            <?php if ($secret === ''): ?>
                <p class="text-sm text-amber-800">এখনো তৈরি হয়নি।</p>
            <?php else: ?>
                <div class="min-w-0 flex flex-wrap items-center gap-2">
                    <code class="bg-white border rounded px-2 py-1 text-xs" style="word-break:break-all"><?= e($secret) ?></code>
                    <button type="button" class="text-indigo-600 font-bold text-xs" data-sms-copy="<?= e($secret) ?>">🔗 কপি</button>
                </div>
                <p class="text-xs text-gray-500 mt-1">🔴 চাবিটা কাউকে দেবেন না — এটা দিয়ে যে কেউ ভুয়া SMS ঢোকাতে পারে।</p>
            <?php endif; ?>
        </div>
    </div>

    <div class="flex flex-wrap gap-2">
        <form method="post" action="payment-inbox.php?action=key" class="inline"
              onsubmit="return confirmSubmit(this, '<?= $secret === '' ? 'নতুন একটা গোপন চাবি তৈরি করা হবে।' : 'নতুন চাবি তৈরি হলে পুরনোটা সাথে সাথে অচল হয়ে যাবে — ফোনের MacroDroid-এ নতুন চাবি না বসানো পর্যন্ত SMS আসা বন্ধ থাকবে।' ?>')">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="new">
            <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-lg text-sm font-bold" <?= $ready ? '' : 'disabled' ?>>
                <?= $secret === '' ? '🔑 চাবি তৈরি করুন' : '↻ নতুন চাবি' ?>
            </button>
        </form>
        <?php if ($secret !== ''): ?>
        <form method="post" action="payment-inbox.php?action=key" class="inline"
              onsubmit="return confirmSubmit(this, 'চাবি বন্ধ করলে ফোন থেকে আর কোনো SMS গ্রহণ করা হবে না। জমা থাকা বার্তা মুছবে না।')">
            <?= csrf_field() ?>
            <input type="hidden" name="mode" value="off">
            <button type="submit" class="border border-gray-300 px-4 py-2 rounded-lg text-sm">✕ বন্ধ করুন</button>
        </form>
        <?php endif; ?>
        <a href="sms-patterns.php" class="border border-gray-300 px-4 py-2 rounded-lg text-sm">📨 SMS ছাঁচ দেখুন</a>
    </div>
</div>
<?php endif; ?>

<!-- ─── ২. অদাবিকৃত টাকা ─── -->
<div class="mb-3">
    <h2 class="font-bold text-gray-800">💰 দাবি হয়নি এমন টাকা (<?= (int) $counts['unclaimed'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        টাকা এসেছে কিন্তু কোনো অভিভাবক এখনো TrxID দিয়ে জানাননি। 🔴 এগুলো নিজে থেকে কোনো অর্ডারের
        সাথে জোড়া লাগে না — অভিভাবক পেমেন্ট পাতায় TrxID দিলে তবেই নিচের তালিকায় একটা দাবি হয়ে আসে।
    </p>
</div>
<?php if ($counts['untrusted'] > 0): ?>
<div class="bg-red-50 border border-red-200 rounded-xl p-4 mb-3">
    <p class="font-bold text-red-800">⚠️ এই তালিকায় <?= (int) $counts['untrusted'] ?>টি বার্তার প্রেরক বিশ্বস্ত নয়</p>
    <p class="text-xs text-red-800 mt-1">
        বিকাশ/নগদের বার্তা অপারেটরের <b>নিবন্ধিত নাম</b> (<code>bKash</code> / <code>NAGAD</code>) দিয়ে আসে —
        ঐ নামে সাধারণ মোবাইল থেকে SMS পাঠানো যায় না। কিন্তু <b>একটা ফোন নম্বর থেকে যে কেউ</b> হুবহু একই রকম
        লেখা বানিয়ে পাঠাতে পারে। লাল চিপওয়ালা বার্তাগুলো তাই <b>টাকা এসেছে ধরে নেবেন না</b> —
        বিকাশ/নগদ অ্যাপে মিলিয়ে দেখুন।
    </p>
    <p class="text-xs text-red-800 mt-1">
        নামটা আসলেই আসল কোনো সেবার হলে <a href="settings.php" class="font-bold underline">সাইট সেটিংস → "টাকার SMS — বিশ্বস্ত প্রেরক"</a>-এ হুবহু যোগ করে দিন।
    </p>
</div>
<?php endif; ?>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">টাকা</th>
                <th class="px-3 py-2">TrxID</th>
                <th class="px-3 py-2">যে নম্বর থেকে</th>
                <th class="px-3 py-2">প্রেরক</th>
                <th class="px-3 py-2">কখন পাঠানো</th>
                <th class="px-3 py-2">আমরা পেলাম</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$unclaimed): ?>
            <tr><td colspan="7" class="px-3 py-6 text-center text-gray-500">
                <?= $ready ? 'কোনো অদাবিকৃত টাকা নেই। ✅' : 'মাইগ্রেশন চালানোর পর দেখা যাবে।' ?>
            </td></tr>
        <?php endif; ?>
        <?php foreach ($unclaimed as $s): ?>
            <tr class="border-t">
                <td class="px-3 py-2 font-bold text-green-700"><?= e(pi_money($s['amount'])) ?></td>
                <td class="px-3 py-2" style="font-family:monospace"><?= e($s['trxid_norm']) ?></td>
                <td class="px-3 py-2"><?= e($s['sender_number'] ?: '—') ?></td>
                <td class="px-3 py-2"><?= pi_sender_chip($s['sender'] ?? '') ?></td>
                <td class="px-3 py-2 text-xs text-gray-600"><?= e($s['sent_at'] ?: '—') ?></td>
                <td class="px-3 py-2 text-xs text-gray-600" title="<?= e($s['received_at']) ?>"><?= e(pi_ago($s['received_at'])) ?></td>
                <td class="px-3 py-2">
                    <?php if (admin_can('orders', 'delete')): ?>
                    <form method="post" action="payment-inbox.php?action=delete" class="inline"
                          onsubmit="return confirmSubmit(this, 'বার্তাটা মুছে ফেলবেন? টাকা সত্যিই এসে থাকলে পরে আর খুঁজে পাওয়া যাবে না।')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="text-red-600 font-bold text-xs">মুছুন</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- ─── ৩. অমীমাংসিত দাবি ─── -->
<div class="mb-3">
    <h2 class="font-bold text-gray-800">📝 অমীমাংসিত দাবি (<?= (int) $counts['claims'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        অভিভাবক নিজে TrxID দিয়ে জানিয়েছেন। ⏳ মানে SMS-এর সাথে এখনো মেলেনি বা কোথাও গরমিল আছে
        (কারণটা "নোট" ঘরে লেখা), ✅ মানে মিলে গেছে — তখন অ্যাকশন ঘরে <b>"💰 খাতায় বসান"</b> বোতাম আসে।
        🔴 অঙ্কটা আগে থেকে বসানো থাকে (SMS-এ যত টাকা এসেছে), কিন্তু <b>বোতাম না চাপা পর্যন্ত খাতায় কিছুই বসে না</b>।
    </p>
</div>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">কোর্স / অর্ডার</th>
                <th class="px-3 py-2">যিনি জানিয়েছেন</th>
                <th class="px-3 py-2">টাকা</th>
                <th class="px-3 py-2">TrxID</th>
                <th class="px-3 py-2">অবস্থা</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$claims): ?>
            <tr><td colspan="6" class="px-3 py-6 text-center text-gray-500">এখনো কোনো দাবি আসেনি।</td></tr>
        <?php endif; ?>
        <?php foreach ($claims as $c): ?>
            <tr class="border-t">
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <?php if (!empty($c['registration_id'])): ?>
                            <a href="registrations.php?action=view&id=<?= (int) $c['registration_id'] ?>" class="text-indigo-600 font-bold">
                                <?= e($c['item_title'] ?: 'অর্ডার #' . (int) $c['registration_id']) ?>
                            </a>
                        <?php else: ?>
                            <span class="text-gray-500"><?= e($c['item_title'] ?: '— কোনো অর্ডারের সাথে জোড়া নেই') ?></span>
                        <?php endif; ?>
                        <?php if (!empty($c['batch'])): ?>
                            <div class="text-xs text-gray-500"><?= e($c['batch']) ?></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <a href="tel:<?= e($c['phone']) ?>" class="font-bold text-gray-800"><?= e($c['phone'] ?: '—') ?></a>
                        <?php if (!empty($c['channel'])): ?>
                            <?php [$chL, $chC] = payment_channel_meta((string) $c['channel']); ?>
                            <div><span class="text-white text-xs font-bold px-2 rounded" style="background:<?= e($chC) ?>;padding-top:1px;padding-bottom:1px"><?= e($chL) ?></span></div>
                        <?php endif; ?>
                    </div>
                </td>
                <td class="px-3 py-2 font-bold"><?= e(pi_money($c['amount'])) ?></td>
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <span style="font-family:monospace"><?= e($c['trxid_norm']) ?></span>
                        <?php /* 🔗 TrxID মিলেছিল, কিন্তু কোনো শর্তে আটকে গেছে — কোন বার্তাটা, সেটা দেখানো */ ?>
                        <?php $mSms = $matchedBy[(int) $c['id']] ?? null; ?>
                        <?php if ($mSms):
                            $linked  = (int) ($mSms['claim_id'] ?? 0) === (int) $c['id'];
                            $smsPh   = psms_phone_norm((string) ($mSms['sender_number'] ?? ''));
                            $clPh    = psms_phone_norm((string) ($c['phone'] ?? ''));
                            $phDiff  = $smsPh !== '' && $clPh !== '' && $smsPh !== $clPh;
                            $amtDiff = (float) ($c['amount'] ?? 0) > 0.009
                                       && abs((float) $c['amount'] - (float) ($mSms['amount'] ?? 0)) > 0.5;
                        ?>
                            <div class="mt-1 text-xs <?= $linked ? 'bg-green-50 border-green-200' : 'bg-amber-50 border-amber-200' ?> border rounded p-2" style="max-width:300px">
                                <div class="<?= $linked ? 'text-green-800' : 'text-amber-800' ?> font-bold">
                                    <?= $linked ? '🔗 এই বার্তাটার সাথে জোড়া লাগানো আছে' : '🔗 এই বার্তাটার সাথে মিলেছে — তবে গরমিল আছে' ?>
                                </div>
                                <div class="text-gray-800 mt-0.5">
                                    টাকা এসেছে <b><?= e(pi_money($mSms['amount'])) ?></b>
                                    <?php if ($amtDiff): ?><span class="text-red-700">(অভিভাবক লিখেছেন <?= e(pi_money($c['amount'])) ?>)</span><?php endif; ?>
                                </div>
                                <div class="text-gray-800">
                                    যে নম্বর থেকে: <b><?= e((string) ($mSms['sender_number'] ?: '—')) ?></b>
                                    <?php if ($phDiff): ?>
                                        <div class="text-red-700">🔴 রেজিস্ট্রেশনের নম্বর <?= e((string) $c['phone']) ?> — দুটো আলাদা, তাই নিজে থেকে যাচাই হয়নি</div>
                                    <?php endif; ?>
                                </div>
                                <?php if (!$linked && admin_can('orders', 'edit')): ?>
                                <?php /* 🔴 sms_id সহ পাঠানো হয় — নাহলে অনুমোদনের পরেও বার্তাটা
                                         "দাবি হয়নি এমন টাকা" তালিকায় পড়ে থাকত (এক টাকা দুইবার গোনার ঝুঁকি) */ ?>
                                <form method="post" action="payment-inbox.php?action=claim-ok" class="inline mt-1"
                                      onsubmit="return confirmSubmit(this, 'নিজে দেখে নিশ্চিত হয়েছেন যে টাকাটা এসেছে? বার্তাটা এই দাবির সাথে জোড়া লেগে যাবে। 🔴 এই ধাপে টাকার খাতায় কিছুই বসবে না — তার জন্য পরে “💰 খাতায় বসান” বোতামটা চাপতে হবে।')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="sms_id" value="<?= (int) $mSms['id'] ?>">
                                    <button type="submit" class="text-green-700 font-bold text-xs">✅ ঠিক আছে — মিলিয়ে দিন</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <?php $near = $nearBy[(int) $c['id']] ?? []; ?>
                        <?php foreach ($near as $nm): $ns = $nm['sms']; ?>
                            <?php /* 🔎 প্রায় একই TrxID — বড় হাতের I আর 1, O আর 0 প্রায় একই দেখায় */ ?>
                            <div class="mt-1 text-xs bg-amber-50 border border-amber-200 rounded p-2" style="max-width:300px">
                                <div class="text-amber-800 font-bold">🔎 প্রায় একই TrxID-র একটা বার্তা আছে</div>
                                <?php if (trim((string) ($ns['trxid_norm'] ?? '')) !== ''): ?>
                                    <div style="font-family:monospace" class="text-gray-800"><?= e((string) $ns['trxid_norm']) ?></div>
                                <?php else: ?>
                                    <div class="text-amber-800">⚠️ বার্তাটা এখনো <b>পড়া যায়নি</b> (কোনো ছাঁচে মেলেনি)</div>
                                <?php endif; ?>
                                <div class="text-gray-600 mt-1" style="word-break:break-word"><?= e(text_excerpt((string) ($ns['raw_text'] ?? ''), 120)) ?></div>
                                <?php if (admin_can('orders', 'edit')): ?>
                                <form method="post" action="payment-inbox.php?action=claim-ok" class="inline mt-1"
                                      onsubmit="return confirmSubmit(this, 'এই বার্তাটাই এই দাবির টাকা — এভাবে মিলিয়ে দেবেন? এই ধাপে টাকার খাতায় কিছু বসবে না — তার জন্য পরে “💰 খাতায় বসান” চাপতে হবে।')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                    <input type="hidden" name="sms_id" value="<?= (int) $ns['id'] ?>">
                                    <button type="submit" class="text-green-700 font-bold text-xs">✅ এটাই — মিলিয়ে দিন</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </td>
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <?php $sl = ['new' => '⏳ যাচাই চলছে', 'verified' => '✅ যাচাই হয়েছে — খাতায় বসানো বাকি', 'posted' => '✔ খাতায় বসানো হয়েছে']; ?>
                        <span class="text-xs font-bold"><?= e($sl[$c['status']] ?? $c['status']) ?></span>
                        <?php if (!empty($c['auto_confirmed'])): ?>
                            <div class="text-xs text-green-700">অর্ডার অটো-কনফার্ম হয়েছে</div>
                        <?php endif; ?>
                        <?php if (trim((string) ($c['admin_note'] ?? '')) !== ''): ?>
                            <?php /* যাচাই হয়ে গেলে নোটটা নিছক তথ্য, সতর্কতা নয় */ ?>
                            <div class="text-xs text-amber-800 mt-0.5"><?= $c['status'] === 'new' ? '⚠️' : 'ℹ️' ?> <?= e((string) $c['admin_note']) ?></div>
                        <?php endif; ?>
                        <?php /* ⚠️ সময়টা ইচ্ছাকৃতভাবে এখানেই — আলাদা কলাম করলে ৭৬৮px-এ
                                 টেবিল ভিউপোর্ট ছাড়িয়ে যেত (মেপে দেখা) */ ?>
                        <div class="text-xs text-gray-500 mt-0.5" title="<?= e((string) $c['created_at']) ?>"><?= e(pi_ago($c['created_at'])) ?></div>
                    </div>
                </td>
                <td class="px-3 py-2">
                    <div class="min-w-0">
                        <?php /* 💰 ধাপ ৩ — যাচাই হয়ে যাওয়া দাবির টাকা এক ট্যাপে খাতায়।
                                 🔴 অঙ্কটা **আগে থেকে বসানো থাকে কিন্তু নিজে থেকে কিছু হয় না** —
                                    অ্যাডমিন বোতাম না চাপলে খাতায় একটা পয়সাও বসে না।
                                 🔴 ডিফল্ট অঙ্ক SMS-এরটাই (অভিভাবকের লেখাটা নয়) — টাকার সত্য
                                    সবসময় আমাদের অ্যাকাউন্টে আসা বার্তা। */ ?>
                        <?php if ($c['status'] === 'verified' && !empty($c['registration_id']) && admin_can('orders', 'edit')):
                            $pSms  = $matchedBy[(int) $c['id']] ?? null;
                            $pAmt  = (float) ($pSms['amount'] ?? 0) > 0.009
                                     ? (float) $pSms['amount'] : (float) ($c['amount'] ?? 0);
                            $pName = e((string) ($c['item_title'] ?: 'অর্ডার #' . (int) $c['registration_id']));
                        ?>
                        <form method="post" action="payment-inbox.php?action=claim-post" class="mb-2"
                              onsubmit="return confirmSubmit(this, 'এই টাকাটা <?= $pName ?> অর্ডারের খাতায় বসে যাবে, আর আয়ের বইয়েও যোগ হবে। অঙ্কটা ঠিক আছে তো? (খাতা না থাকলে কোর্সের সেটিংস দেখে কিস্তিগুলো নিজে থেকেই বসবে।)', 'খাতায় বসাবেন?')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <div class="flex items-center" style="gap:.35rem">
                                <span class="text-xs text-gray-500">৳</span>
                                <input type="number" name="amount" step="0.01" min="0" required
                                       value="<?= e(number_format($pAmt, 2, '.', '')) ?>"
                                       class="border rounded-lg px-2 py-1 text-sm" style="width:92px;min-width:0">
                            </div>
                            <?php /* অর্ডার এখনো pending হলে টাকা বসলেও আয়ে যেত না (আয়ের গেট স্ট্যাটাস) */ ?>
                            <?php if (($regStatuses[(int) $c['registration_id']] ?? '') === 'pending'): ?>
                                <label class="flex items-center text-xs text-gray-600 mt-1" style="gap:.3rem">
                                    <input type="checkbox" name="confirm_order" value="1" checked>
                                    অর্ডারটাও কনফার্ম করুন
                                </label>
                                <div class="text-xs text-amber-700">⚠️ কনফার্ম না করলে টাকা খাতায় বসবে কিন্তু আয়ে যাবে না</div>
                            <?php endif; ?>
                            <button type="submit" class="bg-green-600 text-white font-bold text-xs rounded-lg px-2 py-1 mt-1">💰 খাতায় বসান</button>
                        </form>
                        <?php endif; ?>
                        <?php /* 🔴 মিলে যাওয়া বার্তা থাকলে এখানে সাধারণ বোতামটা দেখানো হয় না —
                                 তাহলে অ্যাডমিন ভুল করে বার্তাটা না-জুড়েই অনুমোদন করে ফেলতেন
                                 (বার্তাটা অদাবিকৃত তালিকায় পড়ে থেকে দুইবার গোনা হতো)।
                                 ঐ ক্ষেত্রে TrxID-র ঘরের বোতামটাই ব্যবহার করতে হবে। */ ?>
                        <?php if ($c['status'] === 'new' && empty($matchedBy[(int) $c['id']]) && admin_can('orders', 'edit')): ?>
                        <form method="post" action="payment-inbox.php?action=claim-ok" class="inline"
                              onsubmit="return confirmSubmit(this, 'নিজে দেখে নিশ্চিত হয়েছেন যে টাকাটা এসেছে? দাবিটা “যাচাই হয়েছে” হয়ে যাবে। 🔴 এই ধাপে টাকার খাতায় কিছুই বসবে না — তার জন্য পরে “💰 খাতায় বসান” বোতামটা চাপতে হবে।')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="text-green-700 font-bold text-xs">✅ মিলিয়ে দিলাম</button>
                        </form>
                        <?php endif; ?>
                        <?php if (admin_can('orders', 'delete')): ?>
                        <form method="post" action="payment-inbox.php?action=delete" class="inline"
                              onsubmit="return confirmSubmit(this, 'দাবিটা মুছে ফেলবেন? জোড়া লাগা বার্তা থাকলে সেটা আবার “অদাবিকৃত টাকা” তালিকায় ফিরে যাবে, আর একই TrxID আবার লেখা যাবে। টাকার হিসাবে কিছু বদলায় না।')">
                            <?= csrf_field() ?>
                            <input type="hidden" name="what" value="claim">
                            <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                            <button type="submit" class="text-red-600 font-bold text-xs">✕ দাবিটা মুছুন</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php /* ✔ বসানো দাবিগুলো উপরের তালিকা থেকে উধাও হয়ে যায় (ওটা "অমীমাংসিত"-র তালিকা),
         তাই এখানে শেষ কয়টার একটা ভাঁজ-করা হিসাব — কী বসানো হয়েছে মিলিয়ে দেখার জন্য। */ ?>
<?php if ($posted): ?>
<details class="bg-white rounded-2xl shadow p-4 mb-6">
    <summary class="font-bold text-gray-800 cursor-pointer">✔ সম্প্রতি খাতায় বসানো (<?= count($posted) ?>)</summary>
    <div class="mt-3" style="display:grid;grid-template-columns:1fr;gap:.4rem">
        <?php foreach ($posted as $pc): ?>
            <div class="text-sm border-t pt-2" style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline">
                <b class="text-gray-800"><?= e(pi_money($pc['amount'])) ?></b>
                <?php if (!empty($pc['registration_id'])): ?>
                    <a href="registrations.php?action=view&amp;id=<?= (int) $pc['registration_id'] ?>" class="text-indigo-600">
                        <?= e($pc['item_title'] ?: 'অর্ডার #' . (int) $pc['registration_id']) ?>
                    </a>
                <?php endif; ?>
                <span class="text-xs text-gray-500" style="font-family:monospace"><?= e((string) $pc['trxid_norm']) ?></span>
                <span class="text-xs text-gray-500"><?= e(pi_ago($pc['posted_at'] ?: $pc['updated_at'])) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</details>
<?php endif; ?>

<!-- ─── ৪. পড়া যায়নি ─── -->
<?php if ($unparsed): ?>
<div class="mb-3">
    <h2 class="font-bold text-gray-800">⚠️ পড়া যায়নি (<?= (int) $counts['unparsed'] ?>)</h2>
    <p class="text-xs text-gray-500 mt-1">
        কোনো ছাঁচে মেলেনি। 🔴 কাঁচা লেখাটা ফেলে দেওয়া হয়নি — <a href="sms-patterns.php" class="text-indigo-600 font-bold">SMS ছাঁচ</a>
        পাতায় ছাঁচটা ঠিক করে "আবার পড়ান" চাপলেই এগুলো পড়া যাবে।
        (OTP/প্রচারমূলক বার্তাও এখানে আসতে পারে — সেগুলো মুছে দিলেই হয়।)
    </p>
</div>
<div class="bg-white rounded-2xl shadow overflow-x-auto mb-6">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left">
            <tr>
                <th class="px-3 py-2">প্রেরক</th>
                <th class="px-3 py-2">কাঁচা লেখা</th>
                <th class="px-3 py-2">কখন</th>
                <th class="px-3 py-2">অ্যাকশন</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($unparsed as $s): ?>
            <tr class="border-t">
                <td class="px-3 py-2"><?= pi_sender_chip($s['sender'] ?? '') ?></td>
                <td class="px-3 py-2">
                    <div class="min-w-0" style="max-width:520px;word-break:break-word;font-family:monospace;font-size:12px">
                        <?= e(mb_substr((string) $s['raw_text'], 0, 300)) ?>
                    </div>
                </td>
                <td class="px-3 py-2 text-xs text-gray-600"><?= e(pi_ago($s['received_at'])) ?></td>
                <td class="px-3 py-2">
                    <?php if (admin_can('orders', 'delete')): ?>
                    <form method="post" action="payment-inbox.php?action=delete" class="inline"
                          onsubmit="return confirmSubmit(this, 'বার্তাটা মুছে ফেলবেন?')">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                        <button type="submit" class="text-red-600 font-bold text-xs">মুছুন</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<script>
// 🔴 কপি-বোতাম নিজের আলাদা ব্লকে (layout-bottom-এর বড় স্ক্রিপ্টের ভেতরে নয়)
document.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-sms-copy]');
    if (!b) { return; }
    var txt = b.getAttribute('data-sms-copy') || '';
    var done = function () { var o = b.textContent; b.textContent = '✓ কপি হয়েছে'; setTimeout(function () { b.textContent = o; }, 1500); };
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done, function () { fallback(); });
    } else { fallback(); }
    function fallback() {
        try {
            var ta = document.createElement('textarea');
            ta.value = txt; ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy');
            document.body.removeChild(ta); done();
        } catch (e) { b.textContent = 'কপি করা গেল না'; }
    }
});
</script>

<?php require_once __DIR__ . '/includes/layout-bottom.php'; ?>
