<?php
// ─────────────────────────────────────────────────────────────
// 🗑️ তথ্য মুছে ফেলার অনুরোধ — ইঞ্জিন (২০২৬-১১-১৭)
//
// গোপনীয়তা নীতির (privacy.php#data-deletion) প্রতিশ্রুতি পূরণের যন্ত্র:
//   ১. অনুরোধের রেকর্ড (data_deletion_requests) — রেফারেন্স DEL-0007, ৩০ দিনের সময়সীমা
//   ২. একটা মোবাইল নম্বরের তথ্য সাইটের কোথায় কোথায় আছে — খুঁজে দেখা (dd_scan)
//   ৩. এক চাপে সব জায়গা থেকে মোছা (dd_erase) — আর্কাইভের কপিসহ
//
// 🔴🔴 টাকার নিয়ম (ইউজারের সিদ্ধান্ত "ক", ২০২৬-১১-১৭): রেজিস্ট্রেশন/অর্ডার **মোছা হয় না** —
//    নাম, মোবাইল, ঠিকানা, শিশুর নাম, জন্ম তারিখ, ফেসবুক নাম মুছে "(মুছে ফেলা হয়েছে)" বসে;
//    টাকার অঙ্ক, তারিখ, খাতা, আয় অক্ষত থাকে। তাই আয়ের মোট কখনো বদলায় না।
//    `registration_payments`/`income`-এর **অঙ্ক** এখানে কখনো ছোঁয়া হয় না — শুধু লেখার ঘরে
//    থাকা নাম/নম্বর ঢাকা হয়।
// 🔴 টাকার সাথে সম্পর্কহীন তালিকা (আগ্রহ, অ্যাকাউন্ট, যোগাযোগ, পুরাতন শিক্ষার্থী, রিপোর্ট,
//    করণীয়, লগইন-লগ…) পুরো মোছা হয় — আর্কাইভে নয়, সত্যিই মোছা।
// 🔴 কোন সারি ছোঁয়া হবে সেটা সার্ভারই প্রতিবার নতুন করে খোঁজে (dd_scan) — ব্রাউজার থেকে
//    আসা id-তে কখনো ভরসা নয়।
// 🔴 নম্বর মেলানো হয় শেষ ১০ অঙ্ক ধরে PHP-তে (phone_last10) — +880/৮৮০/স্পেস/বাংলা অঙ্ক সব মেলে।
// ─────────────────────────────────────────────────────────────

const DD_DUE_DAYS   = 30;
const DD_ERASED     = '(মুছে ফেলা হয়েছে)';
const DD_MASK_TEXT  = '(মুছে ফেলা)';

function dd_ready(?PDO $db = null): bool
{
    return db_has_table($db, 'data_deletion_requests');
}

function dd_ref(int $id): string
{
    return 'DEL-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function dd_channels(): array
{
    return [
        'whatsapp' => 'WhatsApp',
        'facebook' => 'ফেসবুক / মেসেঞ্জার',
        'email'    => 'ইমেইল',
        'contact'  => 'সাইটের যোগাযোগ ফর্ম',
        'phone'    => 'ফোন কল',
        'other'    => 'অন্যান্য',
    ];
}

/** প্রমাণের জন্য নম্বরের ছাপ — নম্বর নিজে জমা থাকে না, শুধু পরে "এই নম্বর আগে মোছা হয়েছিল কি" মেলানো যায় */
function dd_phone_hash(string $phone): string
{
    $l = phone_last10($phone);
    return $l === '' ? '' : hash('sha256', 'edu-del|' . $l);
}

/** 01711000001 → 017*****001 */
function dd_mask_phone(string $phone): string
{
    $c = bd_phone_canonical($phone);
    if (strlen($c) < 7) { return $c === '' ? '' : '***'; }
    return substr($c, 0, 3) . str_repeat('*', strlen($c) - 6) . substr($c, -3);
}

/** "আয়েশা সিদ্দিকা" → "আ*** সি***" (প্রথম অক্ষরটুকু — রেকর্ডে কার অনুরোধ তা আন্দাজ করা যায়, পুরো নাম নয়) */
function dd_mask_name(string $name): string
{
    $out = [];
    foreach (preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
        $first = preg_match('/^\X/u', $w, $m) ? $m[0] : mb_substr($w, 0, 1);
        $out[] = $first . '***';
    }
    return $out ? implode(' ', array_slice($out, 0, 3)) : '';
}

function dd_due_date(string $receivedOn): string
{
    $ts = strtotime($receivedOn ?: 'today');
    return date('Y-m-d', ($ts ?: time()) + DD_DUE_DAYS * 86400);
}

/** আজ থেকে সময়সীমার কত দিন বাকি (ঋণাত্মক = পেরিয়ে গেছে) */
function dd_days_left(string $receivedOn): int
{
    $due = strtotime(dd_due_date($receivedOn));
    return (int) floor(($due - strtotime(date('Y-m-d'))) / 86400);
}

/** লেখার ভেতর থেকে নাম/নম্বর ঢাকা (খাতার নোট, আয়ের বর্ণনা, SMS-এর লেখা ইত্যাদি) */
function dd_scrub_text(?string $text, array $needles, string $last10): ?string
{
    if ($text === null || $text === '') { return $text; }
    $out = $text;
    foreach ($needles as $n) {
        $n = trim((string) $n);
        if (mb_strlen($n) >= 3) { $out = str_replace($n, DD_MASK_TEXT, $out); }
    }
    if (strlen($last10) === 10) {
        $out = preg_replace('/(?:\+?880|0)?' . preg_quote($last10, '/') . '/', DD_MASK_TEXT, $out) ?? $out;
    }
    return $out;
}

/** JSON-এর ভেতরের প্রতিটা লেখায় dd_scrub_text (গ্রুপ-মেলানোর ইতিহাস) */
function dd_scrub_json(?string $json, array $needles, string $last10): ?string
{
    if ($json === null || $json === '') { return $json; }
    $data = json_decode($json, true);
    if (!is_array($data)) { return dd_scrub_text($json, $needles, $last10); }
    array_walk_recursive($data, function (&$v) use ($needles, $last10) {
        if (is_string($v)) { $v = dd_scrub_text($v, $needles, $last10); }
    });
    $enc = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return $enc === false ? $json : $enc;
}

/** একটা টেবিল থেকে id + কয়েকটা ঘর তুলে PHP-তে নম্বর মেলানো (টেবিল/কলামের নাম কোডে লেখা ধ্রুবক) */
function dd_match_rows(PDO $db, string $table, array $phoneCols, array $extraCols, string $last10): array
{
    if (!db_has_table($db, $table)) { return []; }
    $cols = array_values(array_filter(array_unique(array_merge(['id'], $phoneCols, $extraCols)),
        fn($c) => $c === 'id' || db_has_column($db, $table, $c)));
    try {
        $rows = $db->query('SELECT ' . implode(', ', $cols) . ' FROM ' . $table)->fetchAll();
    } catch (Throwable $e) { return []; }
    $hits = [];
    foreach ($rows as $r) {
        foreach ($phoneCols as $pc) {
            if (isset($r[$pc]) && $r[$pc] !== '' && phone_last10((string) $r[$pc]) === $last10) {
                $r['_hit'] = $pc;
                $hits[] = $r;
                break;
            }
        }
    }
    return $hits;
}

/**
 * একটা নম্বরের তথ্য কোথায় কোথায় আছে — শুধু পড়ে, কিছুই বদলায় না।
 * ফেরত: ['last10', 'regs' (নিজের অর্ডার), 'recv' (অন্যের অর্ডারে শুধু রিসিভার), 'places' => [key => [label, mode, ids]],
 *        'needles' (লেখা থেকে ঢাকার জন্য নাম/নম্বর)]
 */
function dd_scan(PDO $db, string $phone): array
{
    $last10 = phone_last10($phone);
    $out = ['last10' => $last10, 'regs' => [], 'recv' => [], 'places' => [], 'needles' => []];
    if (strlen($last10) !== 10) { return $out; }

    // ── রেজিস্ট্রেশন: মা/বাবার নম্বর মিললে নিজের অর্ডার, শুধু রিসিভারের নম্বর মিললে অন্যের অর্ডারে রিসিভার ──
    $regExtra = ['customer_name', 'facebook_id', 'receiver_name', 'item_title', 'batch', 'type', 'status', 'created_at', 'income_approved'];
    foreach (dd_match_rows($db, 'registrations', ['phone', 'father_mobile', 'receiver_phone'], $regExtra, $last10) as $r) {
        $own = phone_last10((string) ($r['phone'] ?? '')) === $last10 || phone_last10((string) ($r['father_mobile'] ?? '')) === $last10;
        if ($own) {
            $out['regs'][(int) $r['id']] = $r;
            foreach (['customer_name', 'facebook_id', 'receiver_name'] as $k) { $out['needles'][] = (string) ($r[$k] ?? ''); }
        } else {
            $out['recv'][(int) $r['id']] = $r;
            $out['needles'][] = (string) ($r['receiver_name'] ?? '');
        }
    }

    $add = function (string $key, string $label, string $mode, array $rows) use (&$out) {
        if ($rows) { $out['places'][$key] = ['label' => $label, 'mode' => $mode, 'rows' => $rows]; }
    };

    // ── পুরো মোছা হয় (টাকার সাথে সম্পর্ক নেই) ──
    $users = dd_match_rows($db, 'users', ['phone'], ['full_name', 'email'], $last10);
    foreach ($users as $u) { $out['needles'][] = (string) ($u['full_name'] ?? ''); $out['needles'][] = (string) ($u['email'] ?? ''); }
    $add('users', 'অভিভাবক অ্যাকাউন্ট', 'delete', $users);
    $add('user_requests', 'অ্যাকাউন্টের অনুরোধ/মন্তব্য', 'delete', dd_match_rows($db, 'user_requests', ['phone'], [], $last10));
    $ci = dd_match_rows($db, 'course_interests', ['contact_phone'], ['child_name', 'facebook_name'], $last10);
    foreach ($ci as $r) { $out['needles'][] = (string) ($r['child_name'] ?? ''); $out['needles'][] = (string) ($r['facebook_name'] ?? ''); }
    $add('course_interests', 'আগ্রহ তালিকা', 'delete', $ci);
    $ls = dd_match_rows($db, 'legacy_students', ['phone', 'father_mobile', 'receiver_phone'], ['customer_name', 'facebook_id'], $last10);
    foreach ($ls as $r) { $out['needles'][] = (string) ($r['customer_name'] ?? ''); $out['needles'][] = (string) ($r['facebook_id'] ?? ''); }
    $add('legacy_students', 'পুরাতন শিক্ষার্থী', 'delete', $ls);
    $add('contact_messages', 'যোগাযোগ বার্তা', 'delete', dd_match_rows($db, 'contact_messages', ['phone'], ['name'], $last10));
    $add('student_reports', 'রিপোর্ট কার্ড (PDF সহ)', 'delete', dd_match_rows($db, 'student_reports', ['phone'], ['file_path'], $last10));
    $add('user_login_attempts', 'লগইন-চেষ্টার লগ', 'delete', dd_match_rows($db, 'user_login_attempts', ['phone'], [], $last10));
    $add('registration_errors', 'রেজিস্ট্রেশন এরর লগ', 'delete', dd_match_rows($db, 'registration_errors', ['entered_phone'], [], $last10));

    // করণীয়: নম্বর মিললে, অথবা নিজের অর্ডারের সাথে জোড়া থাকলে
    $tasks = dd_match_rows($db, 'registration_tasks', ['person_phone'], ['image_path', 'registration_id'], $last10);
    if ($out['regs'] && db_has_table($db, 'registration_tasks')) {
        $have = array_column($tasks, 'id');
        $in = implode(',', array_map('intval', array_keys($out['regs'])));
        try {
            foreach ($db->query("SELECT id, image_path, registration_id FROM registration_tasks WHERE registration_id IN ($in)")->fetchAll() as $t) {
                if (!in_array($t['id'], $have)) { $tasks[] = $t; }
            }
        } catch (Throwable $e) {}
    }
    $add('registration_tasks', 'করণীয় তালিকা (ছবিসহ)', 'delete', $tasks);

    // ── নাম/নম্বর ঢাকা হয়, সারি থাকে (টাকার রেকর্ড) ──
    $claims = dd_match_rows($db, 'payment_claims', ['phone'], ['registration_id'], $last10);
    $add('payment_claims', 'পেমেন্ট দাবি (TrxID রেকর্ড)', 'mask', $claims);
    $add('payment_sms', 'টাকার SMS (পাঠানোর নম্বর)', 'mask', dd_match_rows($db, 'payment_sms', ['sender_number'], [], $last10));

    // আর্কাইভে থাকা কপি — JSON-এর ভেতরে নম্বর খোঁজা
    if (db_has_table($db, 'archived_items')) {
        try {
            $st = $db->prepare('SELECT id, entity_type, label FROM archived_items WHERE data_json LIKE :p');
            $st->execute(['p' => '%' . $last10 . '%']);
            $add('archived_items', 'আর্কাইভে থাকা কপি', 'delete', $st->fetchAll());
        } catch (Throwable $e) {}
    }

    $out['needles'] = array_values(array_unique(array_filter(array_map('trim', $out['needles']), fn($n) => mb_strlen($n) >= 3)));
    return $out;
}

/**
 * আসলেই মোছা — এক ট্রানজ্যাকশনে। ফেরত: ['ok', 'err', 'counts' => [label => n]]
 * 🔴 কোন সারি ছোঁয়া হবে সেটা এখানেই নতুন করে খোঁজা হয় (dd_scan)।
 */
function dd_erase(PDO $db, string $phone): array
{
    $scan = dd_scan($db, $phone);
    $last10 = $scan['last10'];
    if (strlen($last10) !== 10) { return ['ok' => false, 'err' => 'মোবাইল নম্বর সঠিক নয়।', 'counts' => []]; }

    $needles = $scan['needles'];
    $counts  = [];
    $files   = [];
    $regIds  = array_keys($scan['regs']);
    $has     = fn(string $t, string $c) => db_has_column($db, $t, $c);

    $db->beginTransaction();
    try {
        // ── ১. নিজের অর্ডার — নাম-নম্বর ঢাকা, টাকা অক্ষত ──
        if ($regIds) {
            $set = ['customer_name = :nm', "phone = ''"];
            foreach (['email', 'address', 'notes', 'date_of_birth', 'facebook_id', 'father_mobile', 'receiver_name', 'receiver_phone', 'admin_note', 'district', 'thana'] as $c) {
                if ($has('registrations', $c)) { $set[] = "$c = NULL"; }
            }
            if ($has('registrations', 'courier_active')) { $set[] = 'courier_active = 0'; }
            $in = implode(',', array_map('intval', $regIds));
            $db->prepare('UPDATE registrations SET ' . implode(', ', $set) . " WHERE id IN ($in)")->execute(['nm' => DD_ERASED]);
            $counts['রেজিস্ট্রেশন/অর্ডার (নাম-নম্বর মোছা, টাকা অক্ষত)'] = count($regIds);

            if (db_has_table($db, 'courier_batches')) {
                $cb = [];
                foreach (['recipient_name', 'recipient_phone', 'recipient_secondary_phone', 'recipient_address'] as $c) {
                    if ($has('courier_batches', $c)) { $cb[] = "$c = NULL"; }
                }
                if ($cb) {
                    $st = $db->query('UPDATE courier_batches SET ' . implode(', ', $cb) . " WHERE registration_id IN ($in)");
                    if ($st->rowCount()) { $counts['পার্সেলের প্রাপকের তথ্য'] = $st->rowCount(); }
                }
            }
            if (db_has_table($db, 'courier_shipments')) {
                $st = $db->query("UPDATE courier_shipments SET raw_response = NULL WHERE registration_id IN ($in)");
                if ($st->rowCount()) { $counts['কুরিয়ারের পাঠানো রেকর্ডের বিবরণ'] = $st->rowCount(); }
            }
            // লেখার ঘরে নাম থাকতে পারে — শুধু লেখা ঢাকা, অঙ্ক কখনো নয়
            foreach ([['income', 'description'], ['expenses', 'description'], ['registration_payments', 'note']] as [$t, $c]) {
                if (!db_has_table($db, $t) || !$has($t, $c) || !$has($t, 'registration_id')) { continue; }
                $n = 0;
                foreach ($db->query("SELECT id, $c FROM $t WHERE registration_id IN ($in)")->fetchAll() as $row) {
                    $new = dd_scrub_text($row[$c], $needles, $last10);
                    if ($new !== $row[$c]) {
                        $db->prepare("UPDATE $t SET $c = :v WHERE id = :id")->execute(['v' => $new, 'id' => $row['id']]);
                        $n++;
                    }
                }
                if ($n) { $counts["$t-এর লেখা থেকে নাম"] = $n; }
            }
        }

        // ── ২. অন্যের অর্ডারে শুধু রিসিভার হিসেবে — রিসিভারের নাম/নম্বর/ঠিকানা মোছা ──
        if ($scan['recv']) {
            $in = implode(',', array_map('intval', array_keys($scan['recv'])));
            $db->query("UPDATE registrations SET receiver_name = NULL, receiver_phone = NULL, address = NULL WHERE id IN ($in)");
            $counts['অন্যের অর্ডারে রিসিভার হিসেবে'] = count($scan['recv']);
        }

        // ── ৩. বাকি জায়গা ──
        foreach ($scan['places'] as $key => $pl) {
            $ids = array_map('intval', array_column($pl['rows'], 'id'));
            if (!$ids) { continue; }
            $in = implode(',', $ids);
            if ($pl['mode'] === 'delete') {
                if ($key === 'student_reports') {
                    foreach ($pl['rows'] as $r) { if (!empty($r['file_path'])) { $files[] = report_pdf_abs_path((string) $r['file_path']); } }
                }
                if ($key === 'registration_tasks' && function_exists('task_image_abs_path')) {
                    foreach ($pl['rows'] as $r) { if (!empty($r['image_path'])) { $files[] = task_image_abs_path((string) $r['image_path']); } }
                }
                $db->query("DELETE FROM $key WHERE id IN ($in)");
            } elseif ($key === 'payment_claims') {
                foreach ($db->query("SELECT id, admin_note FROM payment_claims WHERE id IN ($in)")->fetchAll() as $r) {
                    $db->prepare("UPDATE payment_claims SET phone = '', admin_note = :n WHERE id = :id")
                       ->execute(['n' => (string) dd_scrub_text((string) $r['admin_note'], $needles, $last10), 'id' => $r['id']]);
                }
            } elseif ($key === 'payment_sms') {
                foreach ($db->query("SELECT id, raw_text FROM payment_sms WHERE id IN ($in)")->fetchAll() as $r) {
                    $db->prepare("UPDATE payment_sms SET sender_number = '', raw_text = :t WHERE id = :id")
                       ->execute(['t' => (string) dd_scrub_text((string) $r['raw_text'], $needles, $last10), 'id' => $r['id']]);
                }
            }
            $counts[$pl['label']] = count($ids);
        }

        // ── ৪. গ্রুপ-মেলানোর ইতিহাসে নাম থাকতে পারে (ফোন সেখানে কখনো জমা হয় না) ──
        if ($needles && db_has_table($db, 'group_match_runs')) {
            $n = 0;
            foreach ($db->query('SELECT id, raw_names, result_json FROM group_match_runs')->fetchAll() as $r) {
                $raw  = dd_scrub_text((string) $r['raw_names'], $needles, $last10);
                $json = dd_scrub_json((string) $r['result_json'], $needles, $last10);
                if ($raw !== $r['raw_names'] || $json !== $r['result_json']) {
                    $db->prepare('UPDATE group_match_runs SET raw_names = :r, result_json = :j WHERE id = :id')
                       ->execute(['r' => $raw, 'j' => $json, 'id' => $r['id']]);
                    $n++;
                }
            }
            if ($n) { $counts['গ্রুপ মিলিয়ে দেখার ইতিহাস থেকে নাম'] = $n; }
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        error_log('dd_erase: ' . $e->getMessage());
        return ['ok' => false, 'err' => 'মোছা যায়নি, কিছুই বদলানো হয়নি। (' . $e->getMessage() . ')', 'counts' => []];
    }

    // ফাইল মোছা — DB সফল হওয়ার পরেই (নাহলে রেকর্ড থাকত অথচ ফাইল নেই)
    foreach ($files as $f) { if ($f !== '' && is_file($f)) { @unlink($f); } }

    return ['ok' => true, 'err' => '', 'counts' => $counts];
}

/** অভিভাবককে পাঠানোর লেখা — অনুরোধ পেয়েছি / মুছে ফেলা হয়েছে */
function dd_message(array $req, string $kind): string
{
    $site = get_setting('site_name') ?: 'EduCenter';
    $ref  = dd_ref((int) $req['id']);
    if ($kind === 'done') {
        return "আসসালামু আলাইকুম। আপনার তথ্য মুছে ফেলার অনুরোধ (রেফারেন্স: {$ref}) অনুযায়ী {$site}-এর ওয়েবসাইট থেকে আপনার নাম, মোবাইল নম্বর, ঠিকানা ও সংশ্লিষ্ট তথ্য মুছে ফেলা হয়েছে। হিসাবের প্রয়োজনে শুধু টাকার লেনদেনের রেকর্ড নাম-পরিচয় ছাড়া রাখা হয়েছে। ধন্যবাদ।";
    }
    $due = date('d M Y', strtotime(dd_due_date((string) $req['received_on'])));
    return "আসসালামু আলাইকুম। আপনার তথ্য মুছে ফেলার অনুরোধ আমরা পেয়েছি। রেফারেন্স নম্বর: {$ref}। পরিচয় যাচাই করে {$due} তারিখের মধ্যে মুছে দিয়ে জানিয়ে দেব। — {$site}";
}
