<?php
// পুরাতন শিক্ষার্থী — পুরনো Excel/CSV ডেটার রেফারেন্স তালিকা (২০২৬-০৮-১৭)।
// ⚠️ registrations থেকে সম্পূর্ণ আলাদা টেবিল (legacy_students) — আয়/কুরিয়ার সিস্টেম এটা ছোঁয় না।
// ব্যবহার: (১) CSV আপলোড → কলাম ম্যাপ → ইমপোর্ট, (২) মোট/কোর্স-অনুযায়ী গণনা, (৩) নাম/ফোন/কোর্স/ব্যাচ সার্চ+ফিল্টার,
//          (৪) CSV এক্সপোর্ট, (৫) একই কোর্স+ব্যাচে ডুপ্লিকেট খুঁজে বের করা (২০২৬-১০-২৭)।
// অভিভাবক ফোন দিয়ে লগইন/রেজিস্ট্রেশন করলে account.php + ajax-lookup এই টেবিলও মিলিয়ে পুরনো তথ্য দেখায়।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
admin_require_login();

$db = get_db();
$pageTitle = 'পুরাতন শিক্ষার্থী';

// সিস্টেমের ফিল্ড (এগুলোতেই CSV কলাম ম্যাপ হবে) — বর্তমান রেজিস্ট্রেশনের মতোই
$sysFields = [
    'customer_name'  => 'শিশুর নাম',
    'phone'          => 'মোবাইল (মা) 🔑',
    'father_mobile'  => 'মোবাইল (বাবা)',
    'course_title'   => 'কোর্সের নাম',
    'batch'          => 'ব্যাচ / সাল',
    'date_of_birth'  => 'জন্ম তারিখ / বয়স',
    'facebook_id'    => 'ফেসবুক নাম',
    'receiver_name'  => 'পার্সেল রিসিভার নাম',
    'receiver_phone' => 'পার্সেল রিসিভার নম্বর',
    'address'        => 'ঠিকানা',
    'notes'          => 'মন্তব্য (Remarks)',
];
// ইমপোর্টে ব্যবহৃত কলাম-তালিকা (sysFields-এর কী; DB কলামও এগুলোই)
$LS_COLS = array_keys($sysFields);

// হেডার টেক্সট থেকে সিস্টেম-ফিল্ড আন্দাজ (অটো প্রি-সিলেক্ট, সম্পাদনযোগ্য)
function ls_guess_field(string $header): string
{
    $h = mb_strtolower(trim($header));
    $map = [
        'customer_name'  => ['শিশুর নাম', 'ছাত্র', 'student', 'child'],
        'phone'          => ['মোবাইল নাম্বার (মা)', 'নাম্বার (মা)', '(মা)', 'মা', 'mother'],
        'father_mobile'  => ['(বাবা)', 'বাবা', 'father', 'baba'],
        'course_title'   => ['কোর্স', 'course', 'class', 'ক্লাস', 'subject', 'বিষয়'],
        'batch'          => ['ব্যাচ', 'batch', 'সাল', 'year', 'session'],
        'date_of_birth'  => ['জন্ম', 'birth', 'dob', 'বয়স', 'age'],
        'facebook_id'    => ['ফেসবুক', 'facebook', 'fb'],
        'receiver_phone' => ['রিসিভার নাম্বার', 'রিসিভার নম্বর', 'রিসিভারের নাম্বার'],
        'receiver_name'  => ['রিসিভার নাম', 'receiver', 'রিসিভারের নাম'],
        'address'        => ['ঠিকানা', 'address', 'এলাকা'],
        'notes'          => ['মন্তব্য', 'remark', 'note', 'comment', 'বিবরণ'],
    ];
    foreach ($map as $field => $keys) {
        foreach ($keys as $k) {
            if ($h !== '' && mb_strpos($h, mb_strtolower($k)) !== false) { return $field; }
        }
    }
    return '';
}

/**
 * 🔁 ডুপ্লিকেট-চাবি — একই কোর্স + একই ব্যাচে একই শিক্ষার্থী।
 * 🔴 কোর্স ও ব্যাচ চাবির অংশ, তাই একই শিক্ষার্থী ঐ কোর্সের **অন্য ব্যাচে** বা **আলাদা কোর্সে**
 *    থাকলে আলাদা চাবি হয় — ডুপ্লিকেট হিসেবে দেখানো হয় না (ইউজারের স্পষ্ট শর্ত)।
 * পরিচয় = ফোনের শেষ ১০ অঙ্ক (থাকলে), নাহলে ছোট-হাতের নাম (একাধিক স্পেস এক করে)।
 */
function ls_dupe_key(array $r): string
{
    $course = trim((string) ($r['course_title'] ?? ''));
    $batch  = trim((string) ($r['batch'] ?? ''));
    $phone  = phone_last10((string) ($r['phone'] ?? ''));
    if ($phone !== '') {
        $id = 'p:' . $phone;
    } else {
        $id = 'n:' . mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) ($r['customer_name'] ?? ''))));
    }
    return $course . '||' . $batch . '||' . $id;
}

/**
 * 🗑️ একই দলের কোন সারিটা রাখা হবে, কোনগুলো "অতিরিক্ত" (মুছবে)।
 * 🔴 নিয়ম (ইউজারের প্রশ্নের উত্তর): প্রতি দলে **একটি** সারি রাখা হয় —
 *    সবচেয়ে বেশি ঘর ভরা (পূর্ণ তথ্য) সারিটা; সমান হলে **সবচেয়ে পুরনোটি** (ছোট id)।
 *    বাকিগুলো অতিরিক্ত। তাই ১০ বার থাকলে ৯টা মুছবে, ১টা থাকবে।
 * 🔴🔴 একই ফাংশন ভিউ ও হ্যান্ডলার দুটোই ডাকে — তাই পর্দায় যা "অতিরিক্ত" দেখায়
 *    হুবহু সেগুলোই মোছে (দুই জায়গায় দুই নিয়ম রাখলে অন্যটা মুছে ফেলত)।
 */
function ls_complete_score(array $r): int
{
    $cols = ['customer_name', 'phone', 'father_mobile', 'course_title', 'batch',
             'date_of_birth', 'facebook_id', 'receiver_name', 'receiver_phone', 'address', 'notes'];
    $n = 0;
    foreach ($cols as $c) { if (trim((string) ($r[$c] ?? '')) !== '') { $n++; } }
    return $n;
}

/** একই দলের সারিগুলো "রাখা হবে আগে" ক্রমে সাজায় (index 0 = যেটা রাখা হবে)। */
function ls_dupe_keep_sort(array &$rows): void
{
    usort($rows, function ($a, $b) {
        $sa = ls_complete_score($a);
        $sb = ls_complete_score($b);
        if ($sa !== $sb) { return $sb <=> $sa; }      // বেশি ভরা আগে
        return ((int) $a['id']) <=> ((int) $b['id']); // সমান হলে ছোট id আগে
    });
}

/** সব সারি থেকে "অতিরিক্ত ডুপ্লিকেট" id-র তালিকা (প্রতি দলে একটি বাদে বাকি সব)। */
function ls_dupe_extra_ids(array $rows): array
{
    $g = [];
    foreach ($rows as $r) {
        $name = trim((string) ($r['customer_name'] ?? ''));
        $ph   = phone_last10((string) ($r['phone'] ?? ''));
        if ($ph === '' && $name === '') { continue; } // পরিচয়হীন সারি দল করা যায় না
        $g[ls_dupe_key($r)][] = $r;
    }
    $extra = [];
    foreach ($g as $rs) {
        if (count($rs) < 2) { continue; }
        ls_dupe_keep_sort($rs);
        for ($i = 1, $n = count($rs); $i < $n; $i++) { $extra[] = (int) $rs[$i]['id']; }
    }
    return $extra;
}

/** ফিল্টার থেকে WHERE + params — গ্যাপ ও delete-dupes হ্যান্ডলার দুটোই ডাকে (একটাই নিয়ম)। */
function ls_filter_where(string $q, string $course, string $batch): array
{
    $esc = fn(string $s): string => str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s);
    $hay = "CONCAT_WS(' ', customer_name, phone, father_mobile, course_title, batch, facebook_id, address, notes)";
    $where  = [];
    $params = [];
    if ($q !== '') {
        $where[]      = "($hay LIKE :q ESCAPE '!' OR $hay LIKE :qd ESCAPE '!')";
        $params['q']  = '%' . $esc($q) . '%';
        $params['qd'] = '%' . $esc(strtr($q, bn_digit_map())) . '%';
    }
    if ($course !== '') {
        if ($course === '__NONE__') { $where[] = "course_title = ''"; }
        else { $where[] = 'course_title = :course'; $params['course'] = $course; }
    }
    if ($batch !== '') {
        if ($batch === '__NONE__') { $where[] = "batch = ''"; }
        else { $where[] = 'batch = :batch'; $params['batch'] = $batch; }
    }
    return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
}

/* ════════════════════════════════════════════════════════════════════════
 * CSV লেখার নিরাপদ হেল্পার — registrations.php-এর reg_csv_* প্যাটার্নের হুবহু কপি
 * (শেয়ার্ড ফাইলে নেই, আর registrations.php require করলে তার হ্যান্ডলার/সাইড-ইফেক্ট চলে যেত)।
 * ════════════════════════════════════════════════════════════════════════ */

/** 🔴 ভাঙা বাইট ছেঁকে ফেলা — Excel-এ কলাম এলোমেলো হওয়া ঠেকায় (আধখানা বাংলা অক্ষর কমা খেয়ে ফেলত) */
function ls_csv_utf8(string $v): string
{
    if ($v === '' || mb_check_encoding($v, 'UTF-8')) { return $v; }
    $old = mb_substitute_character();
    mb_substitute_character('none'); // 🔴 'none' — নাহলে '?' বসে
    $fixed = (string) mb_convert_encoding($v, 'UTF-8', 'UTF-8');
    mb_substitute_character($old);
    return $fixed;
}

/** 🔒 CSV ইনজেকশন গার্ড — `=`/`+`/`-`/`@`/ট্যাব দিয়ে শুরু হওয়া ঘরের আগে `'` */
function ls_csv_safe(?string $v): string
{
    $v = trim(str_replace(["\r\n", "\r", "\n"], ' ', (string) $v));
    if ($v !== '' && strpbrk($v[0], "=+-@\t") !== false) { $v = "'" . $v; }
    return $v;
}

/** 📞 ফোন — Excel-এ শুরুর ০ বাঁচাতে `="01711..."`; শুধু অঙ্ক/+ রাখা হয় */
function ls_csv_phone(?string $v): string
{
    $d = preg_replace('~[^0-9+]~', '', en_digits((string) $v));
    return $d === '' ? '' : '="' . $d . '"';
}

/** এক সারি লেখা — `fputcsv()` নয় (ফোনের `="..."` রূপ কাঁচা যেতে হবে), RFC 4180 + CRLF */
function ls_csv_put($fh, array $values): void
{
    $out = [];
    foreach ($values as $v) {
        $v = ls_csv_utf8((string) $v);
        if (preg_match('~^="[0-9+]*"$~', $v)) {
            $out[] = $v;
        } elseif (strpbrk($v, ",\"\r\n") !== false) {
            $out[] = '"' . str_replace('"', '""', $v) . '"';
        } else {
            $out[] = $v;
        }
    }
    fwrite($fh, implode(',', $out) . "\r\n");
}

/** ফিল্টার বয়ে নেওয়া URL — reg_url()-এর হালকা সংস্করণ */
function ls_url(array $overrides, array $active): string
{
    $qs = array_filter(array_merge($active, $overrides), fn($v) => $v !== '' && $v !== null);
    return 'legacy-students.php' . ($qs ? ('?' . http_build_query($qs)) : '');
}

// ---------------- POST হ্যান্ডলার ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না।'); redirect('legacy-students.php'); }
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['csv']['tmp_name']) || !is_uploaded_file($_FILES['csv']['tmp_name'])) {
            set_flash('error', 'CSV ফাইল আপলোড করুন।'); redirect('legacy-students.php');
        }
        $ext = strtolower(pathinfo($_FILES['csv']['name'] ?? '', PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv', 'txt'], true)) {
            set_flash('error', 'শুধু .csv ফাইল দিন (Excel-এ "CSV UTF-8" ফরম্যাটে সেভ করুন)।'); redirect('legacy-students.php');
        }
        $raw = file_get_contents($_FILES['csv']['tmp_name']);
        if ($raw === false || trim($raw) === '') { set_flash('error', 'ফাইল খালি বা পড়া যায়নি।'); redirect('legacy-students.php'); }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // BOM সরানো
        if (!mb_check_encoding($raw, 'UTF-8')) {
            set_flash('error', 'ফাইলটি UTF-8 নয় — বাংলা লেখা ভেঙে যাবে। Excel-এ "Save As → CSV UTF-8 (Comma delimited)" দিয়ে সেভ করে আবার আপলোড করুন।');
            redirect('legacy-students.php');
        }
        // CSV পার্স
        $fh = fopen('php://temp', 'r+'); fwrite($fh, $raw); rewind($fh);
        $headers = fgetcsv($fh);
        if (!$headers || count(array_filter($headers, fn($x) => trim((string) $x) !== '')) === 0) {
            fclose($fh); set_flash('error', 'ফাইলে কোনো কলাম-হেডার পাওয়া যায়নি।'); redirect('legacy-students.php');
        }
        $rows = [];
        while (($r = fgetcsv($fh)) !== false) {
            if (count(array_filter($r, fn($x) => trim((string) $x) !== '')) === 0) { continue; } // খালি সারি বাদ
            $rows[] = $r;
            if (count($rows) >= 5000) { break; } // একবারে সর্বোচ্চ ৫০০০ সারি
        }
        fclose($fh);
        if (!$rows) { set_flash('error', 'হেডার ছাড়া কোনো ডেটা-সারি নেই।'); redirect('legacy-students.php'); }
        $_SESSION['legacy_import'] = ['headers' => array_map(fn($h) => trim((string) $h), $headers), 'rows' => $rows];
        redirect('legacy-students.php?step=map');
    }

    if ($action === 'import') {
        $imp = $_SESSION['legacy_import'] ?? null;
        if (!$imp) { set_flash('error', 'আপলোড করা ডেটা পাওয়া যায়নি — আবার আপলোড করুন।'); redirect('legacy-students.php'); }
        $map = $_POST['map'] ?? []; // [sysField => csv column index | '']
        // 🔴 কোর্স ও ব্যাচ — টাইপ করা মান (সব সারির জন্য); খালি হলে ম্যাপ করা কলাম ব্যবহার হবে।
        $courseOverride = trim((string) ($_POST['course_override'] ?? ''));
        $batchOverride  = trim((string) ($_POST['batch_override'] ?? ''));
        $courseMapped = ($map['course_title'] ?? '') !== '';
        $batchMapped  = ($map['batch'] ?? '') !== '';

        // ভুল হলে map পাতায় ফেরার সময় যেন টাইপ করা মান ও ম্যাপ হারিয়ে না যায়
        $_SESSION['legacy_import']['map'] = $map;
        $_SESSION['legacy_import']['course_override'] = $courseOverride;
        $_SESSION['legacy_import']['batch_override']  = $batchOverride;

        // অন্তত নাম বা ফোন ম্যাপ করা থাকতে হবে (নাহলে প্রতিটা সারি বাদ পড়ত)
        if ((($map['customer_name'] ?? '') === '') && (($map['phone'] ?? '') === '')) {
            set_flash('error', 'অন্তত "শিশুর নাম" বা "মোবাইল (মা)" কলাম ম্যাপ করুন।'); redirect('legacy-students.php?step=map');
        }
        // 🔴 কোর্স ও ব্যাচ বাধ্যতামূলক — হয় উপরে লিখুন, নয় কলাম ম্যাপ করুন
        if ($courseOverride === '' && !$courseMapped) {
            set_flash('error', 'কোর্সের নাম দিন — হয় উপরে বাক্সে লিখুন, নয় "কোর্সের নাম" কলাম ম্যাপ করুন।'); redirect('legacy-students.php?step=map');
        }
        if ($batchOverride === '' && !$batchMapped) {
            set_flash('error', 'ব্যাচ দিন — হয় উপরে বাক্সে লিখুন, নয় "ব্যাচ / সাল" কলাম ম্যাপ করুন।'); redirect('legacy-students.php?step=map');
        }

        $cols = implode(', ', $LS_COLS);
        $phs  = ':' . implode(', :', $LS_COLS);
        $ins = $db->prepare("INSERT INTO legacy_students ($cols) VALUES ($phs)");
        $count = 0;
        foreach ($imp['rows'] as $row) {
            $rec = [];
            foreach ($LS_COLS as $f) {
                $idx = $map[$f] ?? '';
                $rec[$f] = ($idx !== '' && isset($row[(int) $idx])) ? trim((string) $row[(int) $idx]) : '';
            }
            // 🔴 টাইপ করা মান জেতে — ফাইলে কোর্স/ব্যাচ কলাম থাকলেও এটাই বসে (সব সারিতে)
            if ($courseOverride !== '') { $rec['course_title'] = $courseOverride; }
            if ($batchOverride !== '')  { $rec['batch'] = $batchOverride; }
            if ($rec['customer_name'] === '' && $rec['phone'] === '') { continue; } // দুটোই খালি → বাদ
            $ins->execute($rec);
            $count++;
        }
        unset($_SESSION['legacy_import']);
        set_flash('success', $count . ' জন পুরাতন শিক্ষার্থী ইমপোর্ট হয়েছে।');
        redirect('legacy-students.php');
    }

    if ($action === 'delete') {
        $db->prepare('DELETE FROM legacy_students WHERE id = :id')->execute(['id' => (int) ($_POST['id'] ?? 0)]);
        set_flash('success', 'মুছে ফেলা হয়েছে।');
        redirect('legacy-students.php');
    }

    if ($action === 'clear-all') {
        $db->exec('DELETE FROM legacy_students');
        set_flash('success', 'সব পুরাতন শিক্ষার্থী মুছে ফেলা হয়েছে।');
        redirect('legacy-students.php');
    }

    // 🗑️ সব "অতিরিক্ত ডুপ্লিকেট" একসাথে মুছুন — প্রতি দলে একটি রাখে (ls_dupe_extra_ids)।
    // 🔴 চলতি ফিল্টার (q/course/batch) হুবহু মেনে, আর কোন id মুছবে সেটা সার্ভারই নতুন করে
    //    হিসাব করে (ব্রাউজারের পাঠানো id-তে ভরসা নয়) — তাই বাসি পাতা থেকেও নিরাপদ।
    if ($action === 'delete-dupes') {
        $dq = trim((string) ($_POST['q'] ?? ''));
        $dc = trim((string) ($_POST['course'] ?? ''));
        $dbt = trim((string) ($_POST['batch'] ?? ''));
        [$wsql, $wparams] = ls_filter_where($dq, $dc, $dbt);
        $rs = $db->prepare("SELECT * FROM legacy_students $wsql");
        $rs->execute($wparams);
        $extra = ls_dupe_extra_ids($rs->fetchAll());
        $deleted = 0;
        foreach (array_chunk($extra, 500) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $del = $db->prepare("DELETE FROM legacy_students WHERE id IN ($ph)");
            $del->execute($chunk);
            $deleted += $del->rowCount();
        }
        set_flash('success', $deleted > 0
            ? ($deleted . ' টি অতিরিক্ত ডুপ্লিকেট মুছে ফেলা হয়েছে — প্রতি দলে একটি রাখা হয়েছে।')
            : 'মোছার মতো কোনো অতিরিক্ত ডুপ্লিকেট পাওয়া যায়নি।');
        redirect(ls_url(['dupes' => '1'], array_filter(
            ['q' => $dq, 'course' => $dc, 'batch' => $dbt], fn($v) => $v !== '')));
    }

    if ($action === 'cancel-import') {
        unset($_SESSION['legacy_import']);
        redirect('legacy-students.php');
    }

    redirect('legacy-students.php');
}

// ==================== ফিল্টার + স্ক্যান + এক্সপোর্ট (layout-top-এর আগে) ====================
// 🔴 এক্সপোর্ট CSV হেডার পাঠায়, তাই কোনো HTML আউটপুটের আগেই চালাতে হবে।
$step        = $_GET['step'] ?? '';
$q           = trim($_GET['q'] ?? '');
$filterCourse = trim($_GET['course'] ?? '');     // '' = সব; '__NONE__' = কোর্স নেই; নাহলে হুবহু নাম
$filterBatch  = trim($_GET['batch'] ?? '');       // '' = সব; '__NONE__' = ব্যাচ নেই; নাহলে হুবহু ব্যাচ
$dupeView    = ($_GET['dupes'] ?? '') === '1';

$activeFilters = array_filter([
    'q'      => $q,
    'course' => $filterCourse,
    'batch'  => $filterBatch,
    'dupes'  => $dupeView ? '1' : '',
], fn($v) => $v !== '');

// WHERE তৈরি — 🔴 ls_filter_where() (delete-dupes হ্যান্ডলারও এটাই ডাকে, একটাই নিয়ম);
//    প্রতিটা প্লেসহোল্ডার একবারই (HY093 এড়াতে), `%`/`_` escape (ESCAPE '!'), বাংলা অঙ্কও।
[$whereSql, $params] = ls_filter_where($q, $filterCourse, $filterBatch);

// map পাতায় এই স্ক্যান/এক্সপোর্ট দরকার নেই
$dupIds = [];
$dupGroupCount = 0;
if ($step !== 'map') {
    // 🔁 ডুপ্লিকেট স্ক্যান — হালকা কলামে পুরো ফিল্টার-সেট পড়ে PHP-তে গ্রুপ করা
    //    (SQLite-এ REGEXP_REPLACE নেই, আর বাংলা অঙ্ক phone_last10()-ই ঠিকভাবে ধরে — তাই PHP-তে)
    $scanSt = $db->prepare("SELECT id, customer_name, phone, course_title, batch FROM legacy_students $whereSql");
    $scanSt->execute($params);
    $groups = [];
    foreach ($scanSt->fetchAll() as $r) {
        $name = trim((string) $r['customer_name']);
        $ph   = phone_last10((string) $r['phone']);
        if ($ph === '' && $name === '') { continue; } // পরিচয়হীন সারি গ্রুপ করা যায় না
        $groups[ls_dupe_key($r)][] = (int) $r['id'];
    }
    foreach ($groups as $ids) {
        if (count($ids) >= 2) { $dupGroupCount++; foreach ($ids as $i) { $dupIds[$i] = true; } }
    }

    // 📤 CSV এক্সপোর্ট — চলতি ফিল্টার হুবহু মেনে (dupes হলে শুধু ডুপ্লিকেট সারি)
    if (($_GET['export'] ?? '') === 'csv') {
        $csvCols = [
            'আইডি'              => fn($r) => (string) $r['id'],
            'শিশুর নাম'          => fn($r) => ls_csv_safe($r['customer_name'] ?? ''),
            'মোবাইল (মা)'        => fn($r) => ls_csv_phone($r['phone'] ?? ''),
            'মোবাইল (বাবা)'      => fn($r) => ls_csv_phone($r['father_mobile'] ?? ''),
            'কোর্স'              => fn($r) => ls_csv_safe($r['course_title'] ?? ''),
            'ব্যাচ / সাল'        => fn($r) => ls_csv_safe($r['batch'] ?? ''),
            'জন্ম তারিখ / বয়স'  => fn($r) => ls_csv_safe($r['date_of_birth'] ?? ''),
            'ফেসবুক নাম'         => fn($r) => ls_csv_safe($r['facebook_id'] ?? ''),
            'রিসিভার নাম'        => fn($r) => ls_csv_safe($r['receiver_name'] ?? ''),
            'রিসিভার নম্বর'      => fn($r) => ls_csv_phone($r['receiver_phone'] ?? ''),
            'ঠিকানা'             => fn($r) => ls_csv_safe($r['address'] ?? ''),
            'মন্তব্য'            => fn($r) => ls_csv_safe($r['notes'] ?? ''),
        ];
        $fname = 'legacy-students' . ($dupeView ? '-duplicates' : '') . '-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fname . '"');
        echo "\xEF\xBB\xBF"; // UTF-8 BOM — নাহলে Excel-এ বাংলা mojibake
        $out = fopen('php://output', 'w');
        ls_csv_put($out, array_keys($csvCols));
        $exSt = $db->prepare("SELECT * FROM legacy_students $whereSql ORDER BY id DESC");
        $exSt->execute($params);
        while (($r = $exSt->fetch()) !== false) {
            if ($dupeView && !isset($dupIds[(int) $r['id']])) { continue; }
            $vals = [];
            foreach ($csvCols as $fn) { $vals[] = $fn($r); }
            ls_csv_put($out, $vals);
        }
        fclose($out);
        exit;
    }
}

require __DIR__ . '/includes/layout-top.php';

// ==================== ধাপ ২: কলাম ম্যাপিং ====================
if ($step === 'map' && !empty($_SESSION['legacy_import'])) {
    $imp = $_SESSION['legacy_import'];
    $headers = $imp['headers'];
    $preview = array_slice($imp['rows'], 0, 4);
    $savedMap = $imp['map'] ?? [];
    $savedCourseOv = (string) ($imp['course_override'] ?? '');
    $savedBatchOv  = (string) ($imp['batch_override'] ?? '');
    ?>
    <div class="max-w-3xl">
        <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
            <h2 class="font-black text-gray-800 text-lg mb-1">কলাম মিলিয়ে দিন</h2>
            <p class="text-sm text-gray-500">আপনার ফাইলে <b><?= count($imp['rows']) ?></b> টি সারি পাওয়া গেছে। প্রতিটা সিস্টেম-ফিল্ডের পাশে বেছে নিন — আপনার ফাইলের কোন কলাম সেখানে বসবে। (অটো আন্দাজ করা আছে, দরকারে বদলান।)</p>
        </div>

        <form method="post" action="legacy-students.php">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="import">

            <?php // 🔴 কোর্স + ব্যাচ (বাধ্যতামূলক) — টাইপ করলে সব সারিতে এটাই বসে ?>
            <div class="bg-amber-50 border border-amber-200 rounded-2xl p-4 sm:p-5 mb-4">
                <div class="text-sm font-black text-amber-800 mb-1">🔴 কোর্স ও ব্যাচ (বাধ্যতামূলক)</div>
                <p class="text-xs text-amber-800 mb-3">এখানে লিখলে ফাইলে কোর্স/ব্যাচ কলাম যাই থাকুক, <b>সব সারিতে এই লেখাটাই</b> বসবে। খালি রাখলে নিচে ম্যাপ করা কলাম ব্যবহার হবে — তাই অন্তত একটা উপায়ে কোর্স ও ব্যাচ দিতেই হবে।</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">কোর্সের নাম (সব সারির জন্য)</label>
                        <input type="text" name="course_override" value="<?= e($savedCourseOv) ?>" placeholder="যেমন: আরবি ভাষা" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0;">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1">ব্যাচ / সাল (সব সারির জন্য)</label>
                        <input type="text" name="batch_override" value="<?= e($savedBatchOv) ?>" placeholder="যেমন: ৫ম ব্যাচ" class="w-full border rounded-lg px-3 py-2 text-sm" style="min-width:0;">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4 space-y-3">
                <?php foreach ($sysFields as $field => $label):
                    // সেভ করা ম্যাপ থাকলে সেটা, নাহলে হেডার থেকে আন্দাজ
                    $sel = array_key_exists($field, $savedMap) ? (string) $savedMap[$field] : '';
                    if ($sel === '') { foreach ($headers as $i => $h) { if (ls_guess_field($h) === $field) { $sel = (string) $i; break; } } } ?>
                    <div class="flex items-center gap-3 flex-wrap">
                        <label class="text-sm font-semibold text-gray-700" style="min-width:130px;"><?= e($label) ?></label>
                        <span class="text-gray-400">→</span>
                        <select name="map[<?= e($field) ?>]" class="border rounded-lg px-3 py-2 text-sm flex-1" style="min-width:180px;">
                            <option value="">— বাদ দিন —</option>
                            <?php foreach ($headers as $i => $h): ?>
                                <option value="<?= (int) $i ?>" <?= (string) $i === $sel ? 'selected' : '' ?>><?= e($h !== '' ? $h : ('কলাম ' . ($i + 1))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php // প্রিভিউ ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto mb-4">
                <div class="px-4 pt-3 text-xs font-semibold text-gray-500">আপনার ফাইলের প্রথম কয়েকটা সারি (প্রিভিউ)</div>
                <table class="w-full text-xs mt-2">
                    <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                        <?php foreach ($headers as $h): ?><th class="py-2 px-3 whitespace-nowrap"><?= e($h) ?></th><?php endforeach; ?>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($preview as $r): ?>
                        <tr class="border-b last:border-0">
                            <?php foreach ($headers as $i => $h): ?><td class="py-1.5 px-3 whitespace-nowrap text-gray-700"><?= e((string) ($r[$i] ?? '')) ?></td><?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-3 rounded-xl text-sm"><i data-lucide="download" class="w-4 h-4 inline"></i> ইমপোর্ট করুন</button>
                <button type="submit" name="action" value="cancel-import" formnovalidate class="text-gray-500 font-semibold text-sm">বাতিল</button>
            </div>
        </form>
    </div>
    <?php require __DIR__ . '/includes/layout-bottom.php'; exit;
}

// ==================== ডিফল্ট: গণনা + তালিকা + আপলোড ====================
$total = (int) $db->query('SELECT COUNT(*) FROM legacy_students')->fetchColumn();
$withPhone = (int) $db->query("SELECT COUNT(*) FROM legacy_students WHERE phone <> ''")->fetchColumn();
// কোর্স অনুযায়ী সংখ্যা (raw সহ — চিপের লিংক বানাতে)
$byCourse = $db->query("SELECT course_title raw, IF(course_title='','(কোর্স নেই)',course_title) c, COUNT(*) n
                        FROM legacy_students GROUP BY course_title ORDER BY n DESC")->fetchAll();

// ফিল্টার-ড্রপডাউনের অপশন (ব্যাচ কোর্সের সাথে কাস্কেড করে)
$courseOpts = $db->query("SELECT DISTINCT course_title FROM legacy_students WHERE course_title <> '' ORDER BY course_title")->fetchAll(PDO::FETCH_COLUMN);
if ($filterCourse !== '' && $filterCourse !== '__NONE__') {
    $bst = $db->prepare("SELECT DISTINCT batch FROM legacy_students WHERE batch <> '' AND course_title = :c ORDER BY batch");
    $bst->execute(['c' => $filterCourse]);
    $batchOpts = $bst->fetchAll(PDO::FETCH_COLUMN);
} else {
    $batchOpts = $db->query("SELECT DISTINCT batch FROM legacy_students WHERE batch <> '' ORDER BY batch")->fetchAll(PDO::FETCH_COLUMN);
}

// তালিকা — ডুপ্লিকেট ভিউ হলে শুধু ডুপ্লিকেট সারি (গ্রুপ ধরে পাশাপাশি), নাহলে সার্চ/ফিল্টার + LIMIT 200
$rowGroupCount = []; // id => ঐ গ্রুপে কতবার
$rowGroupIdx   = []; // id => গ্রুপ-ইনডেক্স (zebra striping)
$extraSet  = [];   // id => true : "অতিরিক্ত" সারি (মুছবে)
$extraCount = 0;
if ($dupeView) {
    $allSt = $db->prepare("SELECT * FROM legacy_students $whereSql");
    $allSt->execute($params);
    $rowsAll = $allSt->fetchAll();
    // 🔴 কোনগুলো অতিরিক্ত — হ্যান্ডলারের হুবহু একই ফাংশন (পর্দা = যা মুছবে)
    $extraSet   = array_flip(ls_dupe_extra_ids($rowsAll));
    $extraCount = count($extraSet);
    $byKey = [];
    foreach ($rowsAll as $r) {
        if (!isset($dupIds[(int) $r['id']])) { continue; }
        $byKey[ls_dupe_key($r)][] = $r;
    }
    ksort($byKey);
    $list = [];
    $gi = 0;
    foreach ($byKey as $rs) {
        ls_dupe_keep_sort($rs); // রাখা-হবে সারিটা দলের উপরে
        foreach ($rs as $r) {
            $list[] = $r;
            $rowGroupCount[(int) $r['id']] = count($rs);
            $rowGroupIdx[(int) $r['id']]   = $gi;
        }
        $gi++;
    }
} else {
    $st = $db->prepare("SELECT * FROM legacy_students $whereSql ORDER BY id DESC LIMIT 200");
    $st->execute($params);
    $list = $st->fetchAll();
}
$hasFilter = $q !== '' || $filterCourse !== '' || $filterBatch !== '';
?>
<div class="max-w-5xl">
    <?php // ── আপলোড ফর্ম ── ?>
    <div class="bg-white rounded-2xl shadow p-4 sm:p-5 mb-4">
        <h2 class="font-black text-gray-800 text-lg mb-1"><i data-lucide="upload" class="w-5 h-5 inline text-indigo-600"></i> পুরনো ডেটা আপলোড (CSV)</h2>
        <p class="text-sm text-gray-500 mb-3">Excel থেকে <b>"Save As → CSV UTF-8 (Comma delimited)"</b> করে সেভ করুন, তারপর এখানে আপলোড করে কলাম মিলিয়ে দিন। <span class="text-gray-400">(এই ডেটা আয়/কুরিয়ারে যাবে না — শুধু রেফারেন্স।)</span></p>
        <form method="post" action="legacy-students.php" enctype="multipart/form-data" class="flex items-center gap-3 flex-wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="upload">
            <input type="file" name="csv" accept=".csv,text/csv" required class="text-sm border rounded-lg px-3 py-2 bg-gray-50" style="max-width:100%;min-width:0;">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-4 py-2.5 rounded-xl text-sm">আপলোড ও কলাম মেলান →</button>
        </form>
    </div>

    <?php // ── গণনা কার্ড ── ?>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">মোট পুরাতন শিক্ষার্থী</div>
            <div class="text-2xl font-black text-gray-900"><?= number_format($total) ?></div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">ফোন নম্বর আছে</div>
            <div class="text-2xl font-black text-green-600"><?= number_format($withPhone) ?></div>
            <div class="text-[11px] text-gray-400">এরা লগইন/অটো-ফিলে মিলবে</div>
        </div>
        <div class="bg-white rounded-2xl shadow p-4">
            <div class="text-xs text-gray-500">ভিন্ন কোর্স</div>
            <div class="text-2xl font-black text-gray-900"><?= number_format(count($courseOpts)) ?></div>
        </div>
        <?php // 🔁 ডুপ্লিকেট কার্ড — ক্লিকে ডুপ্লিকেট ভিউ (চলতি ফিল্টার বয়ে নিয়ে) ?>
        <?php if ($dupGroupCount > 0): ?>
            <a href="<?= e(ls_url(['dupes' => '1'], $activeFilters)) ?>" class="bg-white rounded-2xl shadow p-4 block hover:bg-amber-50 border border-transparent hover:border-amber-200 transition">
                <div class="text-xs text-gray-500">🔁 একই কোর্স+ব্যাচে ডুপ্লিকেট</div>
                <div class="text-2xl font-black text-amber-600"><?= number_format($dupGroupCount) ?></div>
                <div class="text-[11px] text-amber-700"><?= $dupeView ? 'এখন দেখছেন' : 'দেখতে ক্লিক করুন' ?></div>
            </a>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow p-4">
                <div class="text-xs text-gray-500">🔁 ডুপ্লিকেট</div>
                <div class="text-2xl font-black text-gray-400">0</div>
                <div class="text-[11px] text-gray-400">একই কোর্স+ব্যাচে নেই</div>
            </div>
        <?php endif; ?>
    </div>

    <?php // ── কোর্স অনুযায়ী সংখ্যা (চিপে ক্লিকে কোর্স-ফিল্টার) ── ?>
    <?php if ($byCourse): ?>
    <div class="bg-white rounded-2xl shadow p-4 mb-4">
        <div class="text-sm font-bold text-gray-700 mb-2">কোর্স অনুযায়ী সংখ্যা <span class="text-xs font-normal text-gray-400">(ক্লিক করে ফিল্টার করুন)</span></div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($byCourse as $bc):
                $cval = $bc['raw'] === '' ? '__NONE__' : $bc['raw'];
                $isActive = $filterCourse === $cval; ?>
                <a href="<?= e(ls_url(['course' => $cval, 'batch' => '', 'dupes' => ''], ['q' => $q])) ?>"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold <?= $isActive ? 'bg-indigo-600 text-white' : 'bg-indigo-50 text-indigo-700 hover:bg-indigo-100' ?>">
                    <?= e($bc['c']) ?> <span class="<?= $isActive ? 'bg-white/20' : 'bg-white text-indigo-600' ?> rounded-md px-1.5"><?= number_format((int) $bc['n']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php // ── ফিল্টার + সার্চ + এক্সপোর্ট ── ?>
    <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
        <form method="get" action="legacy-students.php" id="lsFilterForm" class="flex items-center gap-2 flex-wrap">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="নাম / ফোন / কোর্স খুঁজুন..." data-autosearch="lsFilterForm" class="list-search border rounded-lg px-3 py-2 text-sm" style="width:200px;">
            <select name="course" id="lsCourseSelect" class="border rounded-lg px-3 py-2 text-sm" onchange="var b=document.getElementById('lsBatchSelect'); if(b)b.value=''; this.form.submit();">
                <option value="">সব কোর্স</option>
                <option value="__NONE__" <?= $filterCourse === '__NONE__' ? 'selected' : '' ?>>(কোর্স নেই)</option>
                <?php foreach ($courseOpts as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCourse === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
            <select name="batch" id="lsBatchSelect" class="border rounded-lg px-3 py-2 text-sm" onchange="this.form.submit();">
                <option value="">সব ব্যাচ</option>
                <option value="__NONE__" <?= $filterBatch === '__NONE__' ? 'selected' : '' ?>>(ব্যাচ নেই)</option>
                <?php foreach ($batchOpts as $b): ?>
                    <option value="<?= e($b) ?>" <?= $filterBatch === $b ? 'selected' : '' ?>><?= e($b) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if ($dupeView): ?><input type="hidden" name="dupes" value="1"><?php endif; ?>
            <button type="submit" class="bg-gray-100 hover:bg-gray-200 text-gray-800 font-semibold px-3 py-2 rounded-lg text-sm">🔍 খুঁজুন</button>
            <?php if ($hasFilter || $dupeView): ?><a href="legacy-students.php" class="text-gray-500 text-sm">✕ ক্লিয়ার</a><?php endif; ?>
        </form>
        <div class="flex items-center gap-3">
            <?php if ($total > 0): ?>
            <a href="<?= e(ls_url(['export' => 'csv'], $activeFilters)) ?>" class="inline-flex items-center gap-1.5 bg-green-600 hover:bg-green-700 text-white font-semibold px-3 py-2 rounded-lg text-sm">
                <i data-lucide="download" class="w-4 h-4"></i> CSV এক্সপোর্ট
            </a>
            <form method="post" action="legacy-students.php" onsubmit="return confirmSubmit(this, 'সব পুরাতন শিক্ষার্থী মুছে ফেলবেন? এটা ফেরানো যাবে না।', 'সব মুছবেন?');">
                <?= csrf_field() ?><input type="hidden" name="action" value="clear-all">
                <button type="submit" class="text-red-600 text-sm font-semibold">সব মুছুন</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($dupeView): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 mb-3 text-sm text-amber-800">
            🔁 <b>ডুপ্লিকেট ভিউ</b> — একই <b>কোর্স + ব্যাচে</b> একই শিক্ষার্থী (ফোন বা নাম মিলিয়ে) একাধিকবার আছে এমন সারি। একই শিক্ষার্থী ঐ কোর্সের অন্য ব্যাচে বা আলাদা কোর্সে থাকলে এখানে আসবে না।
            <div class="mt-2 pt-2 border-t border-amber-200">
                প্রতি দলে <b>✅ রাখা হবে</b> = সবচেয়ে বেশি তথ্য-ভরা একটি সারি (সমান হলে সবচেয়ে পুরনোটি); বাকিগুলো <b>🗑️ অতিরিক্ত</b> হিসেবে মুছবে।
                <span class="text-amber-700">(১০ বার থাকলে ৯টা মুছবে, ১টা থাকবে।)</span>
                <?php if ($extraCount > 0): ?>
                    <div class="mt-2">
                        <form method="post" action="legacy-students.php" class="inline"
                              onsubmit="return confirmSubmit(this, 'প্রতি দলে একটি সারি রেখে বাকি <?= number_format($extraCount) ?> টি অতিরিক্ত ডুপ্লিকেট মুছে ফেলবেন? এটা আর ফেরানো যাবে না।', 'অতিরিক্ত ডুপ্লিকেট মুছবেন?');">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete-dupes">
                            <input type="hidden" name="q" value="<?= e($q) ?>">
                            <input type="hidden" name="course" value="<?= e($filterCourse) ?>">
                            <input type="hidden" name="batch" value="<?= e($filterBatch) ?>">
                            <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold px-4 py-2 rounded-lg text-sm">
                                🗑️ সব অতিরিক্ত ডুপ্লিকেট মুছুন (<?= number_format($extraCount) ?> টি)
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$list): ?>
        <div class="bg-white rounded-2xl shadow empty-state"><div class="empty-ic"><i data-lucide="users" class="w-8 h-8"></i></div><?= ($hasFilter || $dupeView) ? 'কিছু পাওয়া যায়নি।' : 'এখনো কোনো পুরাতন শিক্ষার্থী যোগ করা হয়নি — উপরে CSV আপলোড করুন।' ?></div>
    <?php else: ?>
        <div class="bg-white rounded-2xl shadow overflow-x-auto"><table class="w-full text-sm mcard">
            <thead><tr class="text-left text-gray-500 border-b bg-gray-50">
                <th class="py-3 px-4">শিশুর নাম</th><th class="py-3 px-4">মোবাইল (মা)</th><th class="py-3 px-4">কোর্স</th>
                <th class="py-3 px-4">ব্যাচ/সাল</th><th class="py-3 px-4">ফেসবুক</th><th class="py-3 px-4">অ্যাকশন</th>
            </tr></thead>
            <tbody>
            <?php foreach ($list as $s):
                $rid = (int) $s['id'];
                // ডুপ্লিকেট ভিউতে গ্রুপ ধরে হালকা zebra (পরপর গ্রুপ আলাদা দেখা যায়)
                $rowBg = ($dupeView && (($rowGroupIdx[$rid] ?? 0) % 2) === 1) ? 'bg-amber-50/60' : ''; ?>
                <tr class="border-b last:border-0 hover:bg-gray-50 <?= $rowBg ?>">
                    <td class="py-2.5 px-4 font-semibold text-gray-900">
                        <?= e($s['customer_name'] ?: '—') ?>
                        <?php if ($dupeView && isset($rowGroupCount[$rid])): ?>
                            <span class="inline-block ml-1 text-[11px] font-bold text-amber-700 bg-amber-100 rounded px-1.5 py-0.5">🔁 <?= (int) $rowGroupCount[$rid] ?> বার</span>
                            <?php if (isset($extraSet[$rid])): ?>
                                <span class="inline-block ml-1 text-[11px] font-bold text-red-700 bg-red-100 rounded px-1.5 py-0.5">🗑️ অতিরিক্ত</span>
                            <?php else: ?>
                                <span class="inline-block ml-1 text-[11px] font-bold text-green-700 bg-green-100 rounded px-1.5 py-0.5">✅ রাখা হবে</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="py-2.5 px-4 font-mono whitespace-nowrap"><?= e($s['phone'] ?: '—') ?></td>
                    <td class="py-2.5 px-4"><?= e($s['course_title'] ?: '—') ?></td>
                    <td class="py-2.5 px-4"><?= e($s['batch'] ?: '—') ?></td>
                    <td class="py-2.5 px-4 text-gray-600"><?= e($s['facebook_id'] ?: '—') ?></td>
                    <td class="py-2.5 px-4">
                        <form method="post" action="legacy-students.php" class="inline" onsubmit="return confirmSubmit(this, 'এই শিক্ষার্থীকে মুছবেন?', 'মুছবেন?');">
                            <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $rid ?>">
                            <button type="submit" class="text-red-600 font-semibold text-xs">ডিলিট</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (!$dupeView && count($list) >= 200): ?><p class="text-xs text-gray-400 mt-2">প্রথম ২০০টি দেখানো হচ্ছে — নির্দিষ্ট কাউকে খুঁজতে উপরে সার্চ/ফিল্টার করুন।</p><?php endif; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
