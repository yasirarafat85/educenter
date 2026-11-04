<?php
// ── 💰 যাচাই হওয়া দাবি → টাকার খাতায় বসানো (শেয়ার্ড: হাতে বোতাম + 🤖 অটো-সুইপ)
//    ২০২৬-১১-০৪
//
// 🔴🔴 এই ফাইল **শুধু অ্যাডমিন-সাইড** থেকে require হবে — এটা income-sync.php টানে, যেটা
//    পাবলিক পাতা থেকে কখনো লোড করা যায় না। পাবলিক পাতা (pay.php / pay-submit.php /
//    sms-in.php) এটা require করবে না; তাই অটো-পোস্টও **পাবলিক রিকোয়েস্টে কখনো চলে না**,
//    চলে শুধু অ্যাডমিন পেমেন্ট-ইনবক্স খুললে (ভবিষ্যতে চাইলে CLI cron থেকেও)।
//
// 🔴🔴 হাতে "💰 খাতায় বসান" আর অটো-সুইপ — **দুটোই `pclaim_post_one()` ডাকে**, যাতে টাকার
//    নিয়ম দুই জায়গায় দু-রকম হয়ে না যায় (CLAUDE.md-এর সবচেয়ে বড় নিয়ম)। আগে এই
//    ৭ ধাপ শুধু payment-inbox.php-এর `claim-post` হ্যান্ডলারে ছিল; এখন এখানে।

require_once __DIR__ . '/income-sync.php';                   // pay_collect() — অ্যাডমিন-only
require_once __DIR__ . '/../../includes/payment-claim.php';  // PCLAIM_MAX_AMOUNT
require_once __DIR__ . '/../../includes/payment-sms.php';    // psms_sender_status()

/**
 * একটা **যাচাই-হওয়া** দাবির টাকা অর্ডারের খাতায় ও আয়ে বসায় (হুবহু প্রমাণিত পথে)।
 * নিজে ট্রানজ্যাকশন খোলে; যেকোনো ব্যর্থতায় rollback করে — টাকার হিসাব নীরবে সরতে পারে না।
 *
 * 🔴 ক্রম: (pending হলে) কনফার্ম → pay_collect() → মিলে যাওয়া SMS জোড়া → দাবি 'posted'।
 *    pay_collect() নিজেই pay_ensure_plan → pay_apply_payment → pay_save_rows →
 *    due_amount → sync_income_for_status() করে। এখানে আলাদা আয়-লজিক নেই।
 *
 * @param ?int $byAdminId  অ্যাডমিনের id (হাতে বসানো), বা **null** (🤖 অটো/সিস্টেম)।
 * @return array{ok:bool, code:string, err:string, amount:float, confirmed:bool,
 *               incBefore:float, incAfter:float, balance:float}
 */
function pclaim_post_one(PDO $db, int $claimId, float $amount, bool $alsoConfirm, ?int $byAdminId): array
{
    $fail = fn(string $code, string $err): array => [
        'ok' => false, 'code' => $code, 'err' => $err, 'amount' => 0.0,
        'confirmed' => false, 'incBefore' => 0.0, 'incAfter' => 0.0, 'balance' => 0.0,
    ];

    $st = $db->prepare('SELECT * FROM payment_claims WHERE id = :id LIMIT 1');
    $st->execute(['id' => $claimId]);
    $claim = $st->fetch();

    // 🔴 শুধু "verified" দাবিই বসানো যায় — অযাচাই (new) দাবি বসালে ভুয়া TrxID-ও টাকা হয়ে
    //    যেত, আর আগে বসানো (posted) দাবি দুইবার বসত। (idempotent: দুইবার ডাকলেও একবারই বসে)
    if (!$claim || $claim['status'] !== 'verified') {
        return $fail('notverified', 'এই দাবিটা "যাচাই হয়েছে" অবস্থায় নেই (বা আগেই বসানো হয়েছে)।');
    }
    if (!($amount > 0) || $amount > PCLAIM_MAX_AMOUNT) {
        return $fail('amount', 'টাকার অঙ্কটা ঠিক নেই।');
    }

    $regId = (int) ($claim['registration_id'] ?? 0);
    $r2 = $db->prepare('SELECT * FROM registrations WHERE id = :id LIMIT 1');
    $r2->execute(['id' => $regId]);
    $reg = $r2->fetch();
    if (!$reg) {
        return $fail('noreg', 'দাবিটা কোনো অর্ডারের সাথে জোড়া নেই, তাই খাতায় বসানো যাচ্ছে না।');
    }

    $incBefore = (float) $db->query('SELECT COALESCE(SUM(amount), 0) s FROM income')->fetch()['s'];

    try {
        $db->beginTransaction();

        /* অর্ডারটা এখনো pending হলে কনফার্ম করা — নাহলে টাকা খাতায় বসলেও আয়ে যেত না
         * (আয়ের গেট `INCOME_STATUSES`)। 🔴 শুধু pending → confirmed; অন্য কোনো স্ট্যাটাস
         * কখনো বদলানো হয় না। 🔴 এটা pay_collect()-এর **আগে**, একই ট্রানজ্যাকশনে — কারণ ঐ
         *    ফাংশনের ভেতরের sync_income_for_status() `$reg['status']`-ই দেখে। */
        $didConfirm = false;
        if ($alsoConfirm && ($reg['status'] ?? '') === 'pending') {
            $db->prepare("UPDATE registrations SET status = 'confirmed' WHERE id = :id")->execute(['id' => $regId]);
            $reg['status'] = 'confirmed';
            $didConfirm = true;
        }

        $res = pay_collect($db, $reg, $amount, 'claim');
        if (!$res['ok']) {
            $db->rollBack();
            return $fail($res['code'] ?: 'collect', $res['err'] ?: 'খাতায় বসানো যায়নি।');
        }

        /* 🔗 মিলে যাওয়া বার্তাটা এখনো জোড়া না থাকলে এখানেই লাগানো — নাহলে টাকাটা খাতায়
         * বসার পরেও বার্তাটা "দাবি হয়নি এমন টাকা" তালিকায় পড়ে থেকে দুইবার গোনা হতো।
         * 🔴 অন্য দাবিতে লাগেনি — DB থেকেই যাচাই (claim_id IS NULL)। */
        $mId = (int) ($claim['matched_sms_id'] ?? 0);
        if ($mId > 0) {
            $db->prepare('UPDATE payment_sms SET claim_id = :c WHERE id = :s AND claim_id IS NULL')
               ->execute(['c' => $claimId, 's' => $mId]);
        }

        // 🤖 অটো হলে posted_by NULL (সিস্টেম), নোটেও আলাদা চিহ্ন — তালিকায় চেনা যায়
        $note = (($byAdminId === null) ? '🤖 অটো খাতায় বসানো হয়েছে — ' : '💰 খাতায় বসানো হয়েছে — ')
              . number_format($amount, 2);
        $old  = trim((string) ($claim['admin_note'] ?? ''));
        $db->prepare("UPDATE payment_claims
                        SET status = 'posted', posted_at = NOW(), posted_by = :by, admin_note = :n
                      WHERE id = :id AND status = 'verified'")
           ->execute([
               'by' => $byAdminId,   // null = 🤖 সিস্টেম
               'n'  => mb_substr($old === '' ? $note : $note . ' · ' . $old, 0, 500),
               'id' => $claimId,
           ]);

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        return $fail('ex', 'খাতায় বসাতে সমস্যা হয়েছে — কিছুই বদলায়নি।');
    }

    $incAfter = (float) $db->query('SELECT COALESCE(SUM(amount), 0) s FROM income')->fetch()['s'];
    return [
        'ok' => true, 'code' => '', 'err' => '', 'amount' => $amount,
        'confirmed' => $didConfirm, 'incBefore' => $incBefore, 'incAfter' => $incAfter,
        'balance' => (float) $res['balance'],
    ];
}

/** সুইচ — সেটিংস `pay_auto_post` = '1' হলেই অটো চালু (ডিফল্ট বন্ধ)। */
function pclaim_autopost_enabled(): bool
{
    return get_setting('pay_auto_post') === '1';
}

/**
 * 🤖 অটো-সুইপ — পেমেন্ট ইনবক্স খুললে চলে (অ্যাডমিন-সাইড, idempotent)।
 * সুইচ বন্ধ থাকলে কিচ্ছু করে না। প্রতিটা দাবি নিজের ট্রানজ্যাকশনে বসে (একটা ব্যর্থ
 * হলে বাকিগুলো তবু চলে)। পোস্ট হওয়া দাবি 'posted' হয়ে যায় বলে পরের বার আর ছোঁয়া হয় না।
 *
 * 🔴🔴 শুধু সেই দাবি যেগুলো: verified · একটা অর্ডারের সাথে জোড়া · মিলে যাওয়া SMS
 *      **বিশ্বস্ত প্রেরকের** (bKash/নগদ মাস্ক) · অঙ্ক = **SMS-এর আসল অঙ্ক** (অভিভাবকের
 *      টাইপ করা নয়)। 'verified' একা যথেষ্ট নয় — অ্যাডমিন হাতে অবিশ্বস্ত প্রেরকও
 *      verified করতে পারেন, তাই প্রেরক আবার এখানে যাচাই হয়।
 *
 * @return array{on:bool, posted:int, total:float, confirmed:int}
 */
function pclaim_auto_sweep(PDO $db): array
{
    $out = ['on' => false, 'posted' => 0, 'total' => 0.0, 'confirmed' => 0];
    if (!pclaim_autopost_enabled()) {
        return $out;
    }
    $out['on'] = true;

    try {
        $rows = $db->query(
            "SELECT c.id AS claim_id, s.amount AS sms_amount, s.sender AS sender
             FROM payment_claims c
             JOIN payment_sms s ON s.id = c.matched_sms_id
             JOIN registrations r ON r.id = c.registration_id
             WHERE c.status = 'verified'
               AND c.registration_id IS NOT NULL
               AND c.matched_sms_id IS NOT NULL
               AND s.amount > 0
               AND r.status IN ('pending', 'confirmed', 'shipped', 'delivered')
             ORDER BY c.id
             LIMIT 200"
        )->fetchAll();
    } catch (Throwable $e) {
        return $out;   // টেবিল না থাকলে / মাইগ্রেশনের আগে — চুপচাপ বাদ, পাতা ভাঙে না
    }

    foreach ($rows as $row) {
        // 🔴 বিশ্বস্ত প্রেরক ছাড়া কখনো অটো নয় (চুরি করা TrxID-র প্রধান গার্ড)
        if (psms_sender_status((string) $row['sender']) !== 'trusted') {
            continue;
        }
        $amt = round((float) $row['sms_amount'], 2);
        if (!($amt > 0) || $amt > PCLAIM_MAX_AMOUNT) {
            continue;
        }
        $res = pclaim_post_one($db, (int) $row['claim_id'], $amt, true, null);   // null = 🤖 সিস্টেম
        if ($res['ok']) {
            $out['posted']++;
            $out['total'] += $res['amount'];
            if ($res['confirmed']) {
                $out['confirmed']++;
            }
        }
    }
    return $out;
}
