<?php
// ─────────────────────────────────────────────────────────────
// পেমেন্ট খাতা — কিস্তি-ভিত্তিক টাকা আদায়ের শেয়ার্ড হেল্পার (ধাপ ১, ২০২৬-০৯-২২)
//
// এক রেজিস্ট্রেশনে একাধিকবার টাকা আসে (রেজিস্ট্রেশন ফি + প্রতি মাসের বেতন)। প্রতিটা
// কিস্তি `registration_payments`-এ একটা সারি — নিজের প্রাপ্য, ছাড় ও জমা নিয়ে।
//
// 🔴 ধাপ ১-এ এটা আয়ের হিসাব (income / sync_income_for_status) **একদমই ছোঁয় না** —
// নিছক ট্র্যাকিং। আয়ের নিয়ম খাতার উপর সরানো হবে ধাপ ২-এ।
//
// কিস্তির তালিকা ব্যাচের কনফিগ থেকে অটো তৈরি হয় (pay_build_plan) — অ্যাডমিনকে
// হাতে লিখতে হয় না। সেভ না করা পর্যন্ত DB-তে কিছু লেখা হয় না (GET-এ কখনো লেখা নয়)।
// ─────────────────────────────────────────────────────────────

// ব্যাচের ফি'র গঠন — কোর্সভেদে আলাদা হতে পারে (ইউজারের তিন রকম কোর্স আছে)
function pay_fee_modes(): array
{
    return [
        'reg_monthly' => 'রেজিস্ট্রেশন ফি + মাসিক বেতন',
        'monthly'     => 'শুধু মাসিক বেতন',
        'onetime'     => 'এক-কালীন (পুরো ফি একবারে)',
    ];
}

// fee_mode সেট করা না থাকলে ব্যাচের বিদ্যমান তথ্য দেখে আন্দাজ (অ্যাডমিন চাইলে বদলাতে পারবেন)
function pay_guess_fee_mode(array $batch): string
{
    $mode = trim((string) ($batch['fee_mode'] ?? ''));
    if (isset(pay_fee_modes()[$mode])) {
        return $mode;
    }
    // 🔴 রেজি ফি আছে কিনা — **দুটো ঘরই** দেখা হয় (২০২৬-১০-১৩ ফিক্স)। আগে শুধু `secondary_fee`
    //    (শুধু দেখানোর লেখা) দেখা হতো, তাই অ্যাডমিন খাতার নিজের ঘর "রেজিস্ট্রেশন ফি — টাকার
    //    অঙ্কে" ভরলেও লেখার ঘরটা খালি থাকলে গঠন 'monthly' ধরা হতো — রেজি ফি-র কিস্তিটাই খাতায়
    //    বসত না, আর কেন বসছে না সেটা বোঝারও উপায় ছিল না। `pay_batch_reg_fee()` নিজেও
    //    `registration_fee`-কেই আগে রাখে, তাই এখানেও সেটাই ডাকা হয়।
    // 🔴 মাসের সংখ্যাও এখন `pay_batch_months()` দিয়েই (২০২৬-১০-১৩ ফিক্স) — আগে শুধু
    //    `total_parcels` দেখা হতো, অথচ খাতা নিজে `course_months` → `total_parcels` →
    //    "মেয়াদ" ক্রমে পড়ে। ফলে "কোর্স কয় মাসের = 3" ভরা থাকলেও পার্সেল-সংখ্যা ০ হলে
    //    গঠন 'onetime' ধরা হতো আর ৩ মাসের কোর্সে **একটাই** কিস্তি (এক মাসের বেতন) বসত।
    //    দুই জায়গায় দুই নিয়ম থাকার কোনো কারণ ছিল না।
    $hasRegFee = pay_batch_reg_fee($batch) > 0;
    $months    = pay_batch_months($batch);   // কিছু না পেলে ১
    if ($hasRegFee)     { return 'reg_monthly'; }
    if ($months >= 2)   { return 'monthly'; }
    return 'onetime';
}

// "১ম মাস" — course-parcel.php-এর cp_month_label()-এর হুবহু একই ফরম্যাট
// (ধাপ ৩-এ কুরিয়ারের period_label-এর সাথে মেলাতে হবে বলে ইচ্ছাকৃতভাবে এক রাখা)
function pay_month_label(int $i): string
{
    return pay_month_ordinal($i) . ' মাস';
}

// শুধু ক্রমবাচক অংশ ("১ম") — একাধিক মাস একসাথে দেখাতে লাগে ("১ম–২য় মাস")
function pay_month_ordinal(int $i): string
{
    static $names = [1 => '১ম', 2 => '২য়', 3 => '৩য়', 4 => '৪র্থ', 5 => '৫ম', 6 => '৬ষ্ঠ',
                     7 => '৭ম', 8 => '৮ম', 9 => '৯ম', 10 => '১০ম', 11 => '১১তম', 12 => '১২তম'];
    if (isset($names[$i])) { return $names[$i]; }
    return strtr((string) $i, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']) . 'তম';
}

// এক কিস্তিতে একাধিক মাস ঢুকলে তার লেবেল — এক মাস হলে হুবহু pay_month_label()
// (কুরিয়ারের period_label-এর সাথে মেলাতে হয় বলে এক-মাসের ফরম্যাট বদলানো যাবে না)
function pay_month_span_label(int $from, int $to): string
{
    if ($to <= $from) {
        return pay_month_label($from);
    }
    return pay_month_ordinal($from) . '–' . pay_month_ordinal($to) . ' মাস';
}

// লেবেল থেকে মাস-নম্বর ফেরত ("৩য়" → 3) — কুরিয়ারের মাসের সাথে খাতার কিস্তি মেলাতে লাগে
function pay_ordinal_to_number(string $ord): int
{
    static $map = null;
    if ($map === null) {
        $map = [];
        for ($i = 1; $i <= 60; $i++) { $map[pay_month_ordinal($i)] = $i; }
    }
    return $map[trim($ord)] ?? 0;
}

// একটা কিস্তির লেবেল কোন কোন মাস ঢাকে — "২য় মাস" → [2], "১ম–২য় মাস" → [1, 2]
// (মাসিক কিস্তি না হলে খালি অ্যারে)
function pay_label_months(string $label): array
{
    $label = trim($label);
    if (!str_ends_with($label, ' মাস')) {
        return [];
    }
    $body = trim(substr($label, 0, -strlen(' মাস')));
    $parts = explode('–', $body);
    if (count($parts) === 1) {
        $n = pay_ordinal_to_number($parts[0]);
        return $n > 0 ? [$n] : [];
    }
    $a = pay_ordinal_to_number($parts[0]);
    $b = pay_ordinal_to_number($parts[1]);
    return ($a > 0 && $b >= $a) ? range($a, $b) : [];
}

// একটা কিস্তি কোন কোন কালেকশন-মাস ঢাকে — আগে `month_from`/`month_to` ঘর দুটো দেখা হয়
// (নির্ভুল, লেবেলের লেখার উপর নির্ভর করে না), না থাকলে পুরনো সারির লেবেল পড়ে আন্দাজ।
// 🔴 এই ফলব্যাকটা রাখতেই হবে — মাইগ্রেশনের আগে সেভ হওয়া খাতায় ঘর দুটো খালি।
function pay_row_months(array $row): array
{
    $from = $row['month_from'] ?? null;
    $to   = $row['month_to'] ?? null;
    if ($from !== null && $from !== '' && (int) $from > 0) {
        $a = (int) $from;
        $b = ($to !== null && $to !== '' && (int) $to >= $a) ? (int) $to : $a;
        return range($a, $b);
    }
    return pay_label_months((string) ($row['label'] ?? ''));
}

// 🔑 কুরিয়ারের N-তম পার্সেলে খাতার কোন কোন কিস্তির টাকা তোলার কথা।
//
// 🔴🔴 এক পার্সেলে **একাধিক** কিস্তি পড়তে পারে (যেমন রেজিস্ট্রেশন ফি + ঐ মাসের বেতন) —
//    ২০২৬-০৯-২৮ পর্যন্ত এখানে শুধু **প্রথম** মিলে যাওয়া কিস্তিটাই ফেরত যেত, তাই বাকিগুলোর
//    টাকা কুরিয়ারে কখনো তোলা হতো না।
// 🔴🔴 আর যেসব কিস্তির কোনো মাস-পরিসর **নেই** — এক-কালীন ফি (`onetime`), রেজিস্ট্রেশন ফি,
//    আর মাইগ্রেশনে বসানো পুরনো `legacy` সারি — সেগুলো একটাও মিলত না, ফলে ভিত্তি ০ হয়ে
//    কালেকশনে শুধু ডেলিভারি চার্জটাই বসত (ইউজারের স্ক্রিনশটে ধরা: বাকি ৳৫০০ থাকা সত্ত্বেও
//    কালেকশন ৳১৩০ দেখাচ্ছিল)। এখন সেগুলো **প্রথম পার্সেলের** সাথে ধরা হয় — একবারই
//    নেওয়ার টাকা, প্রথমবারেই ওঠে।
function pay_rows_for_month(array $rows, int $month): array
{
    $out = [];
    foreach ($rows as $r) {
        $months = pay_row_months($r);
        // মাস-পরিসর জানা থাকলে সেটাই মানি; না জানলে প্রথম পার্সেল
        if ($months ? in_array($month, $months, true) : $month === 1) {
            $out[] = $r;
        }
    }
    return $out;
}

// ঐ পার্সেলে খাতা অনুযায়ী মোট কত টাকা তোলার কথা (প্রতিটা কিস্তির নিট − জমা-র যোগফল)।
// 🔴 কুরিয়ারের কালেকশনের **ভিত্তি এটাই** — course-parcel.php-এর সার্ভার ও ব্রাউজার
//    দুই জায়গাতেই এই একই সংখ্যা বসে।
function pay_month_outstanding(array $rows, int $month): float
{
    $sum = 0.0;
    foreach (pay_rows_for_month($rows, $month) as $r) {
        $sum += pay_row_outstanding($r);
    }
    return round($sum, 2);
}

// ঐ পার্সেলের প্রথম কিস্তি (শুধু লেবেল দেখানোর জন্য; টাকার হিসাবে
// `pay_month_outstanding()` ব্যবহার করুন, এটা নয়)
function pay_row_for_month(array $rows, int $month): ?array
{
    return pay_rows_for_month($rows, $month)[0] ?? null;
}

// ঐ কিস্তিতে আর কত টাকা পাওনা (নিট − জমা)। বাদ দেওয়া বা বেশি জমা থাকলে ০।
function pay_row_outstanding(?array $row): float
{
    if (!$row || pay_is_skipped($row)) {
        return 0.0;
    }
    return round(max(0.0, pay_net($row) - (float) $row['amount_paid']), 2);
}

// ছাড় — % হলে প্রাপ্যের শতাংশ, নাহলে সরাসরি টাকা। কখনো প্রাপ্যের বেশি বা ঋণাত্মক না।
function pay_compute_discount(float $due, string $type, float $value): float
{
    if ($value <= 0 || $due <= 0) {
        return 0.0;
    }
    $amount = $type === 'percent' ? ($due * min(100.0, $value) / 100) : $value;
    return round(max(0.0, min($due, $amount)), 2);
}

// নিট প্রাপ্য = প্রাপ্য − ছাড়
// এই কিস্তিটা "বাদ" কিনা (মাঝপথে কোর্স ছেড়ে দিয়েছে) — বাদ হলে প্রাপ্য/বাকির হিসাবে ধরা হয় না
function pay_is_skipped(array $row): bool
{
    return !empty($row['is_skipped']);
}

function pay_net(array $row): float
{
    if (pay_is_skipped($row)) {
        return 0.0;
    }
    return round(max(0.0, (float) $row['amount_due'] - (float) $row['discount_amount']), 2);
}

// একটা কিস্তির অবস্থা: paid (পুরো) / partial (আংশিক) / due (কিছুই দেয়নি)
function pay_row_status(array $row): string
{
    if (pay_is_skipped($row)) {
        return 'skipped';
    }
    $net  = pay_net($row);
    $paid = (float) $row['amount_paid'];
    if ($paid >= $net) { return 'paid'; }   // net = 0 (পুরো ছাড়) হলেও paid
    return $paid > 0 ? 'partial' : 'due';
}

// রেজিস্ট্রেশন ফি (খাতার জন্য): ডেডিকেটেড সংখ্যার ঘর আগে, নাহলে ডিসপ্লে-টেক্সট থেকে আন্দাজ।
// (আগে শুধু secondary_fee পড়া হতো — সেটা "৳৩৫০" ধরনের ডিসপ্লে টেক্সট ও প্রায়ই খালি,
//  তাই খাতায় প্রাপ্য ০ আসত — ইউজারের স্ক্রিনশটে ধরা পড়ে, ২০২৬-০৯-২২)
function pay_batch_reg_fee(array $batch): float
{
    $fee = (float) ($batch['registration_fee'] ?? 0);
    return $fee > 0 ? $fee : parse_price_to_number($batch['secondary_fee'] ?? '');
}

// কোর্স কয় মাসের (খাতায় কয়টা মাসিক কিস্তি): ডেডিকেটেড ঘর → পার্সেল-সংখ্যা → "মেয়াদ" লেখা থেকে।
// "৩ মাস" → parse_price_to_number() বাংলা অঙ্ককেও ইংরেজি করে দেয় বলে ৩ বেরিয়ে আসে।
// কিছুই না পেলে ১। সর্বোচ্চ ৬০ — ভুল টাইপে শত শত সারি তৈরি ঠেকাতে।
function pay_batch_months(array $batch): int
{
    foreach ([(int) ($batch['course_months'] ?? 0),
              (int) ($batch['total_parcels'] ?? 0),
              (int) parse_price_to_number($batch['duration'] ?? '')] as $n) {
        if ($n > 0) {
            return min(60, $n);
        }
    }
    return 1;
}

// রেজিস্ট্রেশন ফি কয় কিস্তিতে নেওয়া হয় (কিছু কোর্সে ৩ বারে) — ০/খালি = একবারে।
function pay_batch_reg_installments(array $batch): int
{
    $n = (int) ($batch['reg_installments'] ?? 0);
    return $n > 0 ? min(12, $n) : 1;
}

// বেতন কয় কিস্তিতে নেওয়া হয় — ০/খালি = প্রতি মাসে একবার (মাসের সংখ্যার সমান)।
// মাসের চেয়ে বেশি দেওয়া যাবে না (৩ মাসের কোর্সে ৪ কিস্তি অর্থহীন)।
function pay_batch_tuition_installments(array $batch, int $months): int
{
    $n = (int) ($batch['tuition_installments'] ?? 0);
    if ($n <= 0) {
        return max(1, $months);
    }
    return max(1, min($months, $n));
}

// বেতনের কিস্তি কীভাবে ভাগ হবে — 'money' (মোট বেতন কিস্তি-সংখ্যা দিয়ে সমান ভাগ, ডিফল্ট)
// নাকি 'months' (প্রতি কিস্তিতে পূর্ণ মাস ধরে — আগের কিস্তিতে বেশি মাস)।
// 🔑 মাস যখন কিস্তি দিয়ে সমানভাবে ভাগ যায় (৪÷২, ৬÷৩) তখন দুই নিয়মের ফল হুবহু এক;
// পার্থক্য শুধু ভাগ না গেলে (৩ মাস ২ কিস্তিতে → money: ১০৩৫+১০৩৫, months: ১৩৮০+৬৯০)।
function pay_tuition_split_modes(): array
{
    return [
        'money'  => 'টাকা সমান ভাগ (ডিফল্ট)',
        'months' => 'মাস ধরে ভাগ (আগের কিস্তিতে বেশি মাস)',
    ];
}

function pay_batch_tuition_split_mode(array $batch): string
{
    return trim((string) ($batch['tuition_split_mode'] ?? '')) === 'months' ? 'months' : 'money';
}

// টাকা কয়বার তোলা হয় (কুরিয়ারের ১ম..Nম পার্সেলই কালেকশনের উপলক্ষ) — খাতার কিস্তি
// এই স্লটগুলোর সাথেই মেলে। পার্সেল-সংখ্যা না থাকলে মাসের সংখ্যাই স্লট।
function pay_collect_slots(array $batch, int $months): int
{
    $n = (int) ($batch['total_parcels'] ?? 0);
    return $n > 0 ? min(60, $n) : max(1, $months);
}

// সংখ্যাটা পড়ার মতো করে (1.5 → "1.5", 2.00 → "2", 1.666 → "1.67") — লেবেলে বসাতে।
// সংখ্যা ইংরেজিতেই (প্রজেক্ট কনভেনশন — বাংলা অঙ্ক পড়া কঠিন)।
function pay_trim_number(float $v): string
{
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

// মোট টাকা কয়েক কিস্তিতে ভাগ — যোগফল সবসময় হুবহু মোটের সমান থাকে
// (পয়সার অবশিষ্ট আগের কিস্তিগুলোতে বসে; যেমন ৫০০ ÷ ৩ = 166.67 + 166.67 + 166.66)।
function pay_split_amount(float $total, int $parts): array
{
    $parts = max(1, $parts);
    $cents = (int) round($total * 100);
    $neg   = $cents < 0;
    $cents = abs($cents);
    $base  = intdiv($cents, $parts);
    $extra = $cents - ($base * $parts);
    $out   = [];
    for ($i = 0; $i < $parts; $i++) {
        $c = $base + ($i < $extra ? 1 : 0);
        $out[] = ($neg ? -$c : $c) / 100;
    }
    return $out;
}

// N মাসকে K কিস্তিতে ভাগ → [[from, to], ...] (আগের কিস্তিতে বেশি মাস)
// যেমন ৩ মাস ২ কিস্তিতে → [[1,2],[3]]; ৪ মাস ২ কিস্তিতে → [[1,2],[3,4]]
function pay_month_groups(int $months, int $parts): array
{
    $months = max(1, $months);
    $parts  = max(1, min($months, $parts));
    $base   = intdiv($months, $parts);
    $extra  = $months - ($base * $parts);
    $groups = [];
    $from   = 1;
    for ($i = 0; $i < $parts; $i++) {
        $len = $base + ($i < $extra ? 1 : 0);
        $groups[] = [$from, $from + $len - 1];
        $from    += $len;
    }
    return $groups;
}

// একাধিক কিস্তিতে নেওয়া ফি-র লেবেল — একবারে হলে নামটাই, নাহলে "নাম (কিস্তি 2/3)"
// (সংখ্যা ইংরেজিতে — প্রজেক্ট কনভেনশন)
function pay_part_label(string $base, int $i, int $total): string
{
    return $total > 1 ? $base . ' (কিস্তি ' . $i . '/' . $total . ')' : $base;
}

// ব্যাচের কনফিগ থেকে কিস্তির তালিকা তৈরি (এখনো সেভ করা হয় না — শুধু প্রস্তাব)
function pay_build_plan(PDO $db, array $reg): array
{
    if (($reg['type'] ?? '') !== 'course') {
        // ওয়ার্কশিট/প্রোডাক্ট — এক-কালীনই, আইটেমের দাম × পরিমাণ
        $table = ($reg['type'] ?? '') === 'worksheet' ? 'worksheets' : 'products';
        $stmt = $db->prepare("SELECT price FROM `$table` WHERE id = :id");
        $stmt->execute(['id' => $reg['item_id']]);
        $due = parse_price_to_number((string) ($stmt->fetchColumn() ?: '0')) * max(1, (int) $reg['quantity']);
        return [pay_blank_row(1, 'onetime', 'পুরো মূল্য', $due, 1, 1)];
    }

    // `*` ইচ্ছাকৃত — নতুন কলাম (registration_fee/course_months) মাইগ্রেশনের আগে না থাকলেও
    // কোয়েরি ভাঙে না; হেল্পারগুলো `?? 0` দিয়ে পুরনো উৎসে ফলব্যাক করে।
    $stmt = $db->prepare('SELECT * FROM course_batches WHERE id = :id');
    $stmt->execute(['id' => $reg['item_id']]);
    $batch = $stmt->fetch();
    if (!$batch) {
        // ব্যাচ ডিলিট/আর্কাইভ হয়ে গেছে — সর্বশেষ জানা পরিমাণ দিয়ে একটা সারি
        return [pay_blank_row(1, 'onetime', 'পুরো মূল্য', (float) ($reg['income_amount'] ?? 0), 1, 1)];
    }

    return pay_plan_from_batch($batch);
}

// ব্যাচের কনফিগ (একটা অ্যারে) থেকেই কিস্তির তালিকা — DB ছোঁয় না, তাই **বিশুদ্ধ**।
//
// 🔑 `pay_build_plan()` থেকে আলাদা করা হয়েছে (২০২৬-১০-১৩) যাতে `admin/course-batches.php`-এর
//    "💰 টাকার ছক" প্রিভিউ **হুবহু এই কোডেই** ছক দেখাতে পারে — অ্যাডমিন যা টাইপ করেছেন সেই
//    মানগুলো একটা অ্যারেতে ভরে দিলেই হয়, সেভ করার দরকার নেই।
// 🔴 প্রিভিউর জন্য JS-এ হিসাব আবার লেখা হয়নি — ইচ্ছাকৃত। টাকার ভাগ-বাটোয়ারার নিয়ম দুই
//    জায়গায় থাকলে সময়ের সাথে নীরবে আলাদা হয়ে যেত; তাই প্রিভিউ সার্ভার-রেন্ডারড
//    (এই কোডবেসে কোথাও AJAX নেই, ফুল রিলোডই কনভেনশন)।
function pay_plan_from_batch(array $batch): array
{
    $rows = [];
    $seq  = 1;

    $mode   = pay_guess_fee_mode($batch);
    $fee    = parse_price_to_number($batch['price'] ?? '');
    $regFee = pay_batch_reg_fee($batch);
    $months = pay_batch_months($batch);

    if ($mode === 'onetime') {
        return [pay_blank_row(1, 'onetime', 'পুরো কোর্স ফি', $fee, 1, 1)];
    }
    // রেজিস্ট্রেশন ফি — এক বা একাধিক কিস্তিতে (reg_installments)
    if ($mode === 'reg_monthly') {
        $label    = trim((string) ($batch['secondary_fee_label'] ?? '')) ?: 'রেজিস্ট্রেশন ফি';
        $regParts = pay_batch_reg_installments($batch);
        // 🔴 রেজি ফি-র কিস্তিগুলোও **কালেকশন-স্লটে** বসে — নাহলে কুরিয়ারের কোন পার্সেলে এই
        //    টাকাটা তোলা হবে সেটা জানার উপায় থাকত না, আর পুরোটাই বাদ পড়ত।
        //    কিস্তি k → k-তম পার্সেল (পার্সেল কম হলে শেষটায়): একবারে নিলে ১ম পার্সেল,
        //    ৩ বারে নিলে ১ম/২য়/৩য়।
        // 🔴 বেতনের মতো **পরিসর (month_from..month_to) দেওয়া হয় না** — বেতনের এক কিস্তি
        //    সত্যিই কয়েক মাসের টাকা, কিন্তু রেজি ফি একবারই নেওয়ার; পরিসর দিলে ঐ এক টাকাটাই
        //    পরপর কয়েকটা পার্সেলে তোলার জন্য দেখাত (টেস্টে ধরা পড়েছিল)।
        $regSlotCount = pay_collect_slots($batch, $months);
        foreach (pay_split_amount($regFee, $regParts) as $i => $part) {
            $slot   = min($i + 1, $regSlotCount);
            $rows[] = pay_blank_row($seq++, 'registration', pay_part_label($label, $i + 1, $regParts), $part, $slot, $slot);
        }
    }

    // বেতন — ডিফল্টে প্রতি মাসে একটা কিস্তি, কিন্তু tuition_installments দিলে কম কিস্তিতে।
    //
    // 🔑 টাকা ভাগ করার দুই নিয়ম (ব্যাচের tuition_split_mode):
    //   • money  (ডিফল্ট) — মোট বেতন ÷ কিস্তি-সংখ্যা, প্রতিটা কিস্তি সমান
    //     (৩ মাস × ৬৯০ = ২০৭০, ২ কিস্তিতে → ১০৩৫ + ১০৩৫ — ইউজারের বাস্তব নিয়ম, "দেড় মাস পরপর")
    //   • months — প্রতি কিস্তিতে পূর্ণ মাস (৩ মাস ২ কিস্তিতে → ১৩৮০ + ৬৯০)
    //   মাস কিস্তি দিয়ে সমানভাবে ভাগ গেলে (৪÷২, ৬÷৩) দুই নিয়মের ফল **হুবহু এক**।
    //
    // মাস-পরিসর (month_from/month_to) হিসাব হয় **কালেকশন-স্লট** ধরে (কুরিয়ারের ১ম..Nম
    // পার্সেল), মাস ধরে নয় — তাই কিস্তি-সংখ্যা ও পার্সেল-সংখ্যা সমান হলে কিস্তি k ঠিক
    // k-তম পার্সেলের সাথেই মেলে (ধাপ ৩-এর কালেকশন এখান থেকেই ভিত্তি নেয়)।
    $tuitionParts = pay_batch_tuition_installments($batch, $months);
    $splitMode    = pay_batch_tuition_split_mode($batch);
    $monthGroups  = pay_month_groups($months, $tuitionParts);
    $slotGroups   = pay_month_groups(pay_collect_slots($batch, $months), $tuitionParts);
    $amounts      = $splitMode === 'months'
        ? array_map(fn(array $g) => $fee * ($g[1] - $g[0] + 1), $monthGroups)
        : pay_split_amount($fee * $months, $tuitionParts);

    // লেবেল: মাস-ভিত্তিক ভাগে (বা টাকা সমান ভাগ যখন মাসের সীমার সাথে হুবহু মেলে) আগের
    // "১ম–২য় মাস" লেখাই থাকে — পুরনো খাতা/কুরিয়ারের লেবেলের সাথে এক থাকে। মাসের সীমা না
    // মিললে (৩ মাস ২ কিস্তিতে = দেড় মাস করে) মাসের নামে লেখা যায় না, তাই কিস্তির নম্বর
    // **আর কত মাসের টাকা সেটাও** — "বেতন (কিস্তি 1/2 — 1.5 মাস)"।
    $useMonthLabels = ($splitMode === 'months') || ($months % $tuitionParts === 0);
    $perPart        = pay_trim_number($months / $tuitionParts);

    foreach ($monthGroups as $i => $group) {
        $label = $useMonthLabels
            ? pay_month_span_label($group[0], $group[1])
            : 'বেতন (কিস্তি ' . ($i + 1) . '/' . $tuitionParts . ' — ' . $perPart . ' মাস)';
        $slot  = $slotGroups[min($i, count($slotGroups) - 1)];
        $rows[] = pay_blank_row($seq++, 'monthly', $label, (float) ($amounts[$i] ?? 0), $slot[0], $slot[1]);
    }
    return $rows;
}

// টাকার অঙ্ক দেখানোর একটাই রূপ (৳১,২৩৪ / পয়সা থাকলে ৳১,২৩৪.৫০) — সংখ্যা English
// (প্রজেক্ট কনভেনশন: বাংলা ১/৮/৯ পড়তে ভুল হয়)
function pay_money(float $v): string
{
    return '৳' . number_format($v, (fmod($v, 1) == 0.0) ? 0 : 2);
}

// 💰 "টাকার ছক" প্রিভিউর উপরের অংশ — খাতা আসলে **কোন ঘর থেকে কোন মানটা পড়ছে** সেটা
// খুলে বলা। ইউজারের আসল অভিযোগ ছিল "সব করা আছে কিন্তু বেশি কমপ্লেক্স" — একই তথ্য
// তিন-চারটা ঘরে থাকায় কোনটা হিসাবে যাচ্ছে বোঝা যেত না (যেমন "মেয়াদ" লেখা vs
// "কোর্স কয় মাসের" সংখ্যা)। তাই প্রতিটা মানের পাশে উৎসটাও লেখা হয়।
//
// ফেরে: ['mode'=>..., 'facts'=>[['label','value','src'],...], 'warnings'=>[...]]
// 🔴 বিশুদ্ধ ফাংশন — DB/আউটপুট ছোঁয় না।
function pay_plan_explain(array $batch): array
{
    $mode       = pay_guess_fee_mode($batch);
    $modeGiven  = isset(pay_fee_modes()[trim((string) ($batch['fee_mode'] ?? ''))]);
    $fee        = parse_price_to_number($batch['price'] ?? '');
    $regFeeNum  = (float) ($batch['registration_fee'] ?? 0);
    $regFeeText = parse_price_to_number($batch['secondary_fee'] ?? '');
    $regFee     = pay_batch_reg_fee($batch);
    $months     = pay_batch_months($batch);
    $slots      = pay_collect_slots($batch, $months);
    $regParts   = pay_batch_reg_installments($batch);
    $tuiParts   = pay_batch_tuition_installments($batch, $months);
    $splitMode  = pay_batch_tuition_split_mode($batch);

    // মাসের সংখ্যা কোন ঘর থেকে এলো — pay_batch_months()-এর ফলব্যাক ক্রমের হুবহু প্রতিধ্বনি
    if ((int) ($batch['course_months'] ?? 0) > 0) {
        $monthSrc = 'এই ঘর থেকে';
    } elseif ((int) ($batch['total_parcels'] ?? 0) > 0) {
        $monthSrc = '"মোট কয়বার পার্সেল যাবে" থেকে (ঘরটা খালি)';
    } elseif ((int) parse_price_to_number($batch['duration'] ?? '') > 0) {
        $monthSrc = '"মেয়াদ" লেখা থেকে বোঝা হয়েছে (ঘরটা খালি)';
    } else {
        $monthSrc = 'কোথাও পাওয়া যায়নি — তাই ১ ধরা হয়েছে';
    }

    $facts = [
        ['label' => 'ফি-র গঠন', 'value' => pay_fee_modes()[$mode] ?? $mode,
         'src' => $modeGiven ? 'আপনি নিজে বেছেছেন' : 'নিজে থেকে বোঝা হয়েছে (ঘরটা খালি)'],
        ['label' => 'মাসিক বেতন', 'value' => pay_money($fee), 'src' => '"মূল/মাসিক ফি" ঘর থেকে'],
    ];
    if ($mode === 'reg_monthly') {
        $facts[] = ['label' => 'রেজিস্ট্রেশন ফি', 'value' => pay_money($regFee),
            'src' => $regFeeNum > 0
                ? 'টাকার অঙ্কের ঘর থেকে'
                : ($regFeeText > 0 ? '"দ্বিতীয় ফি\'র পরিমাণ" লেখা থেকে বোঝা হয়েছে' : 'কোথাও পাওয়া যায়নি')];
    }
    if ($mode !== 'onetime') {
        $facts[] = ['label' => 'কোর্স কয় মাসের', 'value' => $months . ' মাস', 'src' => $monthSrc];
        $facts[] = ['label' => 'টাকা কয়বার তোলা হবে', 'value' => $slots . ' বার (পার্সেল)',
            'src' => (int) ($batch['total_parcels'] ?? 0) > 0 ? '"মোট কয়বার পার্সেল যাবে" থেকে' : 'মাসের সংখ্যাই ধরা হয়েছে'];
    }
    if ($mode === 'reg_monthly') {
        $facts[] = ['label' => 'রেজি ফি কয় কিস্তিতে', 'value' => $regParts . ' কিস্তি',
            'src' => (int) ($batch['reg_installments'] ?? 0) > 0 ? 'এই ঘর থেকে' : 'ঘরটা খালি — একবারেই ধরা হয়েছে'];
    }
    if ($mode !== 'onetime') {
        $facts[] = ['label' => 'বেতন কয় কিস্তিতে', 'value' => $tuiParts . ' কিস্তি',
            'src' => (int) ($batch['tuition_installments'] ?? 0) > 0 ? 'এই ঘর থেকে' : 'ঘরটা খালি — প্রতি মাসে একবার'];
        // ⚠️ `pay_tuition_split_modes()`-এর লেখায় নিজেই "(ডিফল্ট)" আছে — পাশে উৎসেও "ডিফল্ট"
        //    লিখলে দুইবার দেখাত (স্ক্রিনশটে ধরা), তাই এখানে ছোট নামটাই
        $facts[] = ['label' => 'বেতনের টাকা যেভাবে ভাগ হবে',
            'value' => $splitMode === 'months' ? 'মাস ধরে ভাগ' : 'টাকা সমান ভাগ',
            'src' => $splitMode === 'months' ? 'আপনি নিজে বেছেছেন' : 'ডিফল্ট'];
    }

    // ⚠️ যেগুলো ভুলে যাওয়া সহজ, আর ভুলে গেলে খাতার অঙ্ক নীরবে ভুল হয়
    $warnings = [];
    if ($mode !== 'onetime' && $fee <= 0) {
        $warnings[] = '"মূল/মাসিক ফি" ঘরে টাকার অঙ্ক পাওয়া যাচ্ছে না — বেতনের কিস্তিগুলো ৳0 হয়ে বসবে।';
    }
    if ($regFeeNum <= 0 && $regFeeText > 0) {
        $warnings[] = 'রেজিস্ট্রেশন ফি এখন "দ্বিতীয় ফি"-র লেখা থেকে বোঝা হচ্ছে (' . pay_money($regFeeText)
            . ')। নিশ্চিত থাকতে "রেজিস্ট্রেশন ফি — টাকার অঙ্কে" ঘরে সংখ্যাটা লিখে দিন।';
    }
    if ($mode !== 'reg_monthly' && $regFee > 0) {
        $warnings[] = 'রেজিস্ট্রেশন ফি ' . pay_money($regFee) . ' লেখা আছে, কিন্তু গঠন "'
            . (pay_fee_modes()[$mode] ?? $mode) . '" — তাই খাতায় রেজিস্ট্রেশন ফি-র কিস্তি বসবে না।';
    }
    if ((int) ($batch['tuition_installments'] ?? 0) > $months) {
        $warnings[] = 'বেতনের কিস্তি ' . (int) $batch['tuition_installments'] . ' চাওয়া হয়েছে, কিন্তু কোর্স '
            . $months . ' মাসের — তাই ' . $tuiParts . ' কিস্তিতেই বসবে।';
    }
    if ($mode === 'reg_monthly' && $regParts > $slots) {
        $warnings[] = 'রেজি ফি ' . $regParts . ' কিস্তিতে, কিন্তু পার্সেল যাবে ' . $slots
            . ' বার — শেষ কিস্তিগুলো একই (শেষ) পার্সেলেই তোলা হবে।';
    }

    return ['mode' => $mode, 'facts' => $facts, 'warnings' => $warnings];
}

// তালিকায় দেখানোর এক-লাইনের সারকথা — "📒 রেজি ফি ৩ কিস্তি · বেতন ২ কিস্তি · মোট প্রাপ্য ৳2,570"
// 🔴 বিশুদ্ধ ফাংশন, HTML নয় (escape করার দায় কলারের)।
function pay_plan_summary_text(array $rows): string
{
    if (!$rows) {
        return '';
    }
    $kinds = ['registration' => 'রেজি ফি', 'monthly' => 'বেতন', 'onetime' => 'এক-কালীন'];
    $count = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $k = (string) ($r['kind'] ?? '');
        $count[$k] = ($count[$k] ?? 0) + 1;
        $total += (float) ($r['amount_due'] ?? 0);
    }
    $parts = [];
    foreach ($kinds as $k => $label) {
        if (!empty($count[$k])) {
            $parts[] = $count[$k] > 1 ? $label . ' ' . $count[$k] . ' কিস্তি' : $label;
        }
    }
    $parts[] = 'মোট ' . pay_money($total);   // তালিকার সরু ঘরে বসে বলে সংক্ষিপ্ত
    return implode(' · ', $parts);
}

// 💰 প্রিভিউ — ব্যাচের সেটিংস থেকে যে কিস্তিগুলো তৈরি হবে, হুবহু `pay_plan_from_batch()` দিয়েই।
// 🔴 বিশুদ্ধ ফাংশন (DB/সেশন ছোঁয় না), স্টাইল inline — অ্যাডমিনের কম্পাইলড Tailwind-এ
//    সব রঙ/ক্লাস নেই বলে রিবিল্ড এড়াতে (CLAUDE.md-র নিয়ম)।
function pay_plan_preview_html(array $batch): string
{
    $rows = pay_plan_from_batch($batch);
    $ex   = pay_plan_explain($batch);

    $h = '<div class="bg-white rounded-xl border p-4">';
    $h .= '<div class="font-bold text-gray-800 mb-1">📒 এই সেটিংসে খাতায় যে কিস্তিগুলো বসবে</div>';
    $h .= '<div class="text-xs text-gray-500 mb-3">অর্ডার কনফার্ম করলে ঠিক এই ছকটাই বসে (জমা ০ থেকে শুরু) — '
        . 'প্রতিটা অর্ডারে পরে হাতে বদলানো যায়, এখানকার পরিবর্তনে পুরনো খাতা বদলায় না।</div>';

    // উপরের অংশ — কোন ঘর থেকে কোন মান
    $h .= '<div style="display:grid;grid-template-columns:1fr;gap:.35rem;margin-bottom:.9rem">';
    foreach ($ex['facts'] as $f) {
        $h .= '<div class="text-sm" style="display:flex;flex-wrap:wrap;gap:.35rem;align-items:baseline">'
            . '<span class="text-gray-500">' . e($f['label']) . ':</span>'
            . '<b class="text-gray-800">' . e($f['value']) . '</b>'
            . '<span class="text-xs text-gray-400">(' . e($f['src']) . ')</span>'
            . '</div>';
    }
    $h .= '</div>';

    // কিস্তির তালিকা
    $total = 0.0;
    $h .= '<div style="display:grid;grid-template-columns:1fr;gap:.3rem">';
    foreach ($rows as $r) {
        $total += (float) $r['amount_due'];
        $from = $r['month_from'] ?? null;
        $to   = $r['month_to'] ?? null;
        // "পার্সেল 2" / "পার্সেল 1–2" — সংখ্যা English, cp_month_display()-এর ঢঙে
        $slot = $from === null ? '' : ('পার্সেল ' . $from . ($from === $to ? '' : '–' . $to));
        $h .= '<div style="display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline;border-bottom:1px solid #f3f4f6;padding:.3rem 0">'
            . '<span class="text-sm text-gray-700" style="flex:1 1 auto;min-width:0">' . e($r['label']) . '</span>'
            . '<b class="text-sm text-gray-900">' . e(pay_money((float) $r['amount_due'])) . '</b>'
            . ($slot !== '' ? '<span class="text-xs text-gray-400">' . e($slot) . '</span>' : '')
            . '</div>';
    }
    $h .= '</div>';
    $h .= '<div class="mt-2 text-sm font-bold text-gray-800">মোট প্রাপ্য: ' . e(pay_money($total))
        . ' <span class="text-xs font-normal text-gray-500">(' . count($rows) . ' কিস্তি)</span></div>';

    foreach ($ex['warnings'] as $w) {
        $h .= '<div class="mt-2 text-xs" style="background:#fef3c7;color:#92400e;border-radius:.5rem;padding:.5rem .65rem">⚠️ '
            . e($w) . '</div>';
    }
    $h .= '</div>';
    return $h;
}

// এই খাতাটা কি নিছক "পুরনো হিসাব"-এর এক সারি? (মাইগ্রেশনে বসানো — কিস্তিতে ভাগ করা নেই)
function pay_is_legacy_only(array $rows): bool
{
    if (!$rows) {
        return false;
    }
    foreach ($rows as $r) {
        if (($r['kind'] ?? '') !== 'legacy') {
            return false;
        }
    }
    return true;
}

// মোট জমা টাকাটা কিস্তিগুলোতে উপর থেকে নিচে বসানো (প্রতিটা নিট প্রাপ্য পর্যন্ত),
// উদ্বৃত্ত থাকলে শেষ কিস্তিতে। 🔴 যোগফল হুবহু অপরিবর্তিত — তাই আয়ের সংখ্যা নড়ে না।
function pay_allocate_paid(array $rows, float $paid): array
{
    $left = round(max(0.0, $paid), 2);
    foreach ($rows as $i => $r) {
        $take = min($left, pay_net($r));
        $rows[$i]['amount_paid'] = round($take, 2);
        $left = round($left - $take, 2);
    }
    if ($left > 0 && $rows) {
        $last = array_key_last($rows);
        $rows[$last]['amount_paid'] = round((float) $rows[$last]['amount_paid'] + $left, 2);
    }
    return $rows;
}

// 💰 নতুন একটা পেমেন্ট খাতায় বসানো — উপর থেকে নিচে **বাকি অংশে** যোগ করে।
//
// 🔑 `pay_allocate_paid()` থেকে আলাদা, আর এই পার্থক্যটা গুরুত্বপূর্ণ:
//    • `pay_allocate_paid($rows, $total)` — "মোট এতটুকু জমা পড়েছে" ধরে **সব সারি নতুন করে**
//      বসায়, অর্থাৎ আগের `amount_paid` মুছে যায়। নতুন খাতা বসানোয় (prefill) ঠিক আছে,
//      কিন্তু চলতি খাতায় চালালে **অ্যাডমিনের হাতে বসানো কিস্তির জমা হারিয়ে যেত**
//      (`income-fix.php`-এর ঘরেও একই সতর্কতা লেখা)।
//    • এটা **যোগ করে** — প্রতিটা সারির ইতিমধ্যে জমা অক্ষত রেখে শুধু বাকিটুকু ভরে,
//      তাই "অভিভাবক আরেকটা কিস্তি দিলেন" ঘটনাটা হুবহু এভাবেই বসে।
//
// 🔴 উদ্বৃত্ত (সব কিস্তি ভরে যাওয়ার পরেও টাকা বাকি) **শেষ সারিতে** বসে — `pay_allocate_paid()`-এর
//    হুবহু একই নিয়ম, যাতে মোট জমা কখনো হারিয়ে না যায় (advance এভাবেই ধরা পড়ে)।
// 🔴 ⊘ বাদ দেওয়া সারিতে টাকা বসে না (`pay_net()` ০ ফেরায়) — কিন্তু আগের জমা থাকলে সেটাও অক্ষত।
// 🔴 বিশুদ্ধ ফাংশন — DB ছোঁয় না।
function pay_apply_payment(array $rows, float $amount): array
{
    $left = round(max(0.0, $amount), 2);
    if ($left <= 0 || !$rows) {
        return $rows;
    }
    foreach ($rows as $i => $r) {
        if ($left <= 0) {
            break;
        }
        $already = max(0.0, (float) ($r['amount_paid'] ?? 0));
        $room    = round(pay_net($r) - $already, 2);   // এই কিস্তিতে আর কত ধরে
        if ($room <= 0) {
            continue;
        }
        $take = min($left, $room);
        $rows[$i]['amount_paid'] = round($already + $take, 2);
        $left = round($left - $take, 2);
    }
    if ($left > 0) {
        $last = array_key_last($rows);
        $rows[$last]['amount_paid'] = round((float) ($rows[$last]['amount_paid'] ?? 0) + $left, 2);
    }
    return $rows;
}

// পুরনো (বা ভুলভাবে বসা) খাতা কোর্স-ব্যাচের **বর্তমান** সেটিংস দেখে নতুন করে সাজানো।
// আগের সারিগুলো মুছে যায়, কিন্তু **মোট জমা ও টাকা জমার তারিখ থাকে** — শুধু কিস্তিতে ভাগ হয়ে বসে।
function pay_rebuild_plan(PDO $db, array $reg): int
{
    $regId    = (int) $reg['id'];
    $existing = pay_fetch_many($db, [$regId])[$regId] ?? [];
    $paid     = pay_paid_total($existing);

    // আগের জমার তারিখ ধরে রাখা — নাহলে pay_save_rows() আজকের তারিখ বসিয়ে দিত
    $paidAt = '';
    foreach ($existing as $r) {
        if ((float) $r['amount_paid'] > 0 && !empty($r['paid_at'])) {
            $paidAt = (string) $r['paid_at'];
            break;
        }
    }

    $rows = pay_allocate_paid(pay_build_plan($db, $reg), $paid);
    foreach ($rows as $i => $r) {
        $rows[$i]['id'] = 0; // সবগুলোই নতুন সারি হিসেবে বসবে
        if ($paidAt !== '' && (float) $r['amount_paid'] > 0) {
            $rows[$i]['paid_at'] = $paidAt;
        }
    }

    $db->prepare('DELETE FROM registration_payments WHERE registration_id = :id')->execute(['id' => $regId]);
    return pay_save_rows($db, $regId, $rows);
}

/* ────────────────────────────────────────────────────────────────────────────
 * 📒 খাতা না থাকলে নিজে থেকে বসিয়ে দেওয়া  (২০২৬-১০-১৩)
 *
 * 🔴🔴 আগে খাতা বসত **শুধু হাতে** — অর্ডার তালিকার "টাকা" ড্রয়ার, নয়তো আয় মেলানো
 *      পাতা; দুটোই একজন করে। তাই বেশির ভাগ রেজিস্ট্রেশনে খাতাই থাকত না, আর তাতে
 *      তিনটা জিনিস একসাথে ভুল দেখাত:
 *        • আয় ০ (২০২৬-০৯-২৯ থেকে আয়ের একমাত্র উৎস খাতার জমা)
 *        • কোর্স পার্সেলের কালেকশন = ফ্ল্যাট মাসিক ফি (রেজি ফি/ছাড়/advance ধরত না)
 *        • "আয় মেলানো"-র `📒 খাতা এখনো বসানো হয়নি` দল ভরে থাকত
 *
 * এই ফাংশন খাতা **না থাকলে** ব্যাচের সেটিংস দেখে কিস্তিগুলো বসায় — **জমা ০**।
 *
 * 🔴 আয় এক পয়সাও নড়ে না:
 *    • আগে আয় অনুমোদিত না থাকলে (স্বাভাবিক) জমা ০ → আয়ও ০, অর্থাৎ আগের মতোই।
 *    • আগে অনুমোদিত থাকলে (পুরনো নিয়মে কনফার্ম করা) ঐ অঙ্কটা `pay_allocate_paid()`
 *      দিয়ে জমায় বসে — হুবহু যা `reg_pay_panel()`-এর `$prefill` আজ দেখায়। তাই
 *      বইয়ের মোট আয় আগে-পরে এক। **এই prefill সরাবেন না** (সরালে ঐ আয়গুলো ০ হয়ে যাবে)।
 *
 * 🔴 আয় এখানে সিঙ্ক করা হয় **না** — `sync_income_for_status()` আছে
 *    `admin/includes/income-sync.php`-এ, আর এই ফাইলটা `account.php` (পাবলিক) থেকেও
 *    require হয়। তাই **কলার (অ্যাডমিন পেজ) নিজে সিঙ্ক করবে**, ঠিক `pay-save`
 *    হ্যান্ডলারের মতো। `due_amount` ডিনরমালাইজেশন এখানেই হয়ে যায়।
 *
 * ফেরে কয়টা কিস্তি বসল (০ = খাতা আগে থেকেই ছিল, কিছু ছোঁয়া হয়নি)।
 * ──────────────────────────────────────────────────────────────────────────── */
function pay_ensure_plan(PDO $db, array $reg): int
{
    $regId = (int) ($reg['id'] ?? 0);
    if ($regId <= 0) {
        return 0;
    }
    if (pay_fetch_many($db, [$regId])[$regId] ?? []) {
        return 0;   // 🔴 খাতা আছে — কখনো ছোঁয়া হয় না
    }

    $rows = pay_build_plan($db, $reg);
    if (!$rows) {
        return 0;
    }
    // পুরনো নিয়মে অনুমোদিত আয় থাকলে সেটাই জমা হিসেবে (নাহলে সেভে আয় নীরবে ০ হতো)
    $prefill = !empty($reg['income_approved']) ? (float) ($reg['income_amount'] ?? 0) : 0.0;
    if ($prefill > 0) {
        $rows = pay_allocate_paid($rows, $prefill);
    }

    $saved = pay_save_rows($db, $regId, $rows);

    // বাকি টাকা registrations.due_amount-এ (খাতাই আসল উৎস, এটা দ্রুত রেফারেন্স)
    $summary = pay_summary(pay_fetch_many($db, [$regId])[$regId] ?? []);
    $db->prepare('UPDATE registrations SET due_amount = :due WHERE id = :id')
        ->execute(['due' => $summary['balance'], 'id' => $regId]);

    return $saved;
}

// খালি (এখনো সেভ না হওয়া) কিস্তির সারি
function pay_blank_row(int $seq, string $kind, string $label, float $due, ?int $monthFrom = null, ?int $monthTo = null): array
{
    return [
        'id' => 0, 'seq' => $seq, 'kind' => $kind, 'label' => $label,
        'month_from' => $monthFrom, 'month_to' => $monthTo ?? $monthFrom,
        'amount_due' => round($due, 2), 'discount_type' => 'fixed', 'discount_value' => 0.0,
        'discount_amount' => 0.0, 'amount_paid' => 0.0, 'is_skipped' => 0, 'paid_at' => null, 'method' => '', 'note' => null,
    ];
}

// একাধিক রেজিস্ট্রেশনের খাতা একবারে (তালিকায় N+1 কোয়েরি এড়াতে) → [regId => rows[]]
function pay_fetch_many(PDO $db, array $regIds): array
{
    $regIds = array_values(array_unique(array_map('intval', $regIds)));
    if (!$regIds) {
        return [];
    }
    $in = implode(',', array_fill(0, count($regIds), '?'));
    $out = [];
    try {
        $stmt = $db->prepare("SELECT * FROM registration_payments WHERE registration_id IN ($in) ORDER BY registration_id, seq, id");
        $stmt->execute($regIds);
        foreach ($stmt->fetchAll() as $row) {
            // 🔴 SQL মাইগ্রেশনে বাংলা লেখা হয় না (mojibake ঠেকাতে) — তাই legacy সারির লেবেল
            // এখানে বসে। খালি লেবেলের সারি pay_save_rows() বাদ দিয়ে দিত, তাই এটা জরুরি।
            if (trim((string) $row['label']) === '') {
                $row['label'] = $row['kind'] === 'legacy' ? 'পুরনো হিসাব' : 'কিস্তি';
            }
            $out[(int) $row['registration_id']][] = $row;
        }
    } catch (PDOException $ex) {
        return []; // টেবিল এখনো তৈরি হয়নি (মাইগ্রেশন চালানো হয়নি) — পেজ ভাঙবে না
    }
    return $out;
}

// খাতায় মোট কত টাকা আসলে হাতে এসেছে — 🔴 ধাপ ২ থেকে **এটাই আয়ের ভিত্তি**
// (ইউজারের সিদ্ধান্ত ২০২৬-০৯-২২: "যতটুকু হাতে এসেছে" = নগদ-ভিত্তিক আয়, প্রাপ্য নয়)
function pay_paid_total(array $rows): float
{
    $paid = 0.0;
    foreach ($rows as $row) {
        $paid += (float) $row['amount_paid'];
    }
    return round($paid, 2);
}

// খাতার সারাংশ — প্রাপ্য / ছাড় / নিট / জমা / বাকি + সার্বিক অবস্থা
function pay_summary(array $rows): array
{
    $due = $discount = $paid = 0.0;
    $skipped = 0;
    foreach ($rows as $row) {
        $paid += (float) $row['amount_paid']; // বাদ দেওয়া মাসেও টাকা নেওয়া থাকলে সেটা জমাই
        if (pay_is_skipped($row)) {
            $skipped++;
            continue; // বাদ → প্রাপ্য/ছাড়ে গোনা হয় না
        }
        $due      += (float) $row['amount_due'];
        $discount += (float) $row['discount_amount'];
    }
    $net     = round(max(0.0, $due - $discount), 2);
    $paid    = round($paid, 2);
    $balance = round($net - $paid, 2);
    return [
        'rows'     => count($rows),
        'skipped'  => $skipped,
        'due'      => round($due, 2),
        'discount' => round($discount, 2),
        'net'      => $net,
        'paid'     => $paid,
        'balance'  => max(0.0, $balance),
        'status'   => !$rows ? 'none' : ($balance <= 0 ? 'paid' : ($paid > 0 ? 'partial' : 'due')),
    ];
}

// তালিকার চিপ — এক নজরে টাকার অবস্থা
function pay_status_chip(array $summary): string
{
    $money = fn(float $v) => pay_money($v);
    // $style — কম্পাইলড Tailwind-এ নেই এমন রঙের জন্য (যেমন text-purple-700); রিবিল্ড-নির্ভরতা এড়াতে
    $chip = function (string $cls, string $text, string $style = '') {
        return '<span class="inline-block px-2 py-1 rounded-lg text-xs font-semibold ' . $cls . '"'
            . ($style !== '' ? ' style="' . $style . '"' : '') . '>' . e($text) . '</span>';
    };
    $out = match ($summary['status']) {
        'paid'    => $chip('bg-green-100 text-green-800', '✅ পুরো পেইড'),
        'partial' => $chip('bg-amber-100 text-amber-800', '⏳ ' . $money($summary['balance']) . ' বাকি'),
        'due'     => $chip('bg-red-100 text-red-700', '● ' . $money($summary['balance']) . ' বাকি'),
        default   => $chip('bg-gray-100 text-gray-500', 'খাতা নেই'),
    };
    if (!empty($summary['skipped'])) {
        $out .= '<div class="mt-1">' . $chip('bg-gray-100 text-gray-500', '⊘ ' . $summary['skipped'] . ' মাস বাদ') . '</div>';
    }
    if ($summary['discount'] > 0) {
        $out .= '<div class="mt-1">' . $chip('bg-purple-100', '🏷️ ' . $money($summary['discount']) . ' ছাড়', 'color:#7e22ce') . '</div>';
    }
    return $out;
}

// খাতা সেভ — POST থেকে আসা সারিগুলো (নতুন হলে INSERT, থাকলে UPDATE)।
// $input = [ ['id'=>..,'seq'=>..,'kind'=>..,'label'=>..,'amount_due'=>..,'discount_type'=>..,
//             'discount_value'=>..,'amount_paid'=>..,'paid_at'=>..,'method'=>..,'note'=>..], ... ]
function pay_save_rows(PDO $db, int $regId, array $input): int
{
    // month_from/month_to মাইগ্রেশন চালানো না থাকলে ঐ দুটো ঘর ছাড়াই সেভ হয় (খাতা ভাঙে না)।
    $hasMonthCols = pay_has_month_columns($db);
    $mCols = $hasMonthCols ? ', month_from, month_to' : '';
    $mVals = $hasMonthCols ? ', :mfrom, :mto' : '';
    $mSet  = $hasMonthCols ? ', month_from = :mfrom, month_to = :mto' : '';

    $ins = $db->prepare(
        'INSERT INTO registration_payments
            (registration_id, seq, kind, label, amount_due, discount_type, discount_value, discount_amount, amount_paid, is_skipped, paid_at, method, note' . $mCols . ')
         VALUES (:reg, :seq, :kind, :label, :due, :dtype, :dval, :damt, :paid, :skip, :pat, :method, :note' . $mVals . ')'
    );
    $upd = $db->prepare(
        'UPDATE registration_payments SET seq = :seq, kind = :kind, label = :label, amount_due = :due,
            discount_type = :dtype, discount_value = :dval, discount_amount = :damt, amount_paid = :paid,
            is_skipped = :skip, paid_at = :pat, method = :method, note = :note' . $mSet . '
         WHERE id = :id AND registration_id = :reg'
    );

    $saved   = 0;
    $keepIds = [];
    // ⚠️ কী দিয়ে গোনা হয় না — প্যানেল থেকে নতুন সারি "n1"/"n2" ধরনের কী নিয়ে আসে (সংখ্যা নয়)
    $pos = 0;
    foreach ($input as $raw) {
        $pos++;
        $label = trim((string) ($raw['label'] ?? ''));
        if ($label === '') {
            // 🔴 নাম খালি হলে নতুন সারিটা বাদ, কিন্তু **আগে থেকে থাকা সারি মোছা হয় না** —
            // নাহলে নাম মুছে ফেলার মতো ছোট ভুলে ঐ কিস্তির জমা (মানে আয়) নীরবে হারাত।
            if (($existingId = (int) ($raw['id'] ?? 0)) > 0) {
                $keepIds[] = $existingId;
            }
            continue;
        }
        $due   = max(0.0, (float) str_replace(',', '', (string) ($raw['amount_due'] ?? 0)));
        $dtype = ($raw['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $dval  = max(0.0, (float) str_replace(',', '', (string) ($raw['discount_value'] ?? 0)));
        $paid  = max(0.0, (float) str_replace(',', '', (string) ($raw['amount_paid'] ?? 0)));
        $pat   = trim((string) ($raw['paid_at'] ?? ''));

        // 🔴 ছাড়ের টাকা সার্ভারেই হিসাব হয় — ব্রাউজার থেকে আসা মান বিশ্বাস করা হয় না
        $fields = [
            'reg'    => $regId,
            'seq'    => ((int) ($raw['seq'] ?? 0)) ?: $pos,
            'kind'   => (string) ($raw['kind'] ?? 'other'),
            'label'  => mb_substr($label, 0, 100),
            'due'    => $due,
            'dtype'  => $dtype,
            'dval'   => $dval,
            'damt'   => pay_compute_discount($due, $dtype, $dval),
            'paid'   => $paid,
            'skip'   => !empty($raw['is_skipped']) ? 1 : 0,
            'pat'    => preg_match('/^\d{4}-\d{2}-\d{2}$/', $pat) ? $pat : ($paid > 0 ? date('Y-m-d') : null),
            'method' => mb_substr(trim((string) ($raw['method'] ?? '')), 0, 30),
            'note'   => ($n = mb_substr(trim((string) ($raw['note'] ?? '')), 0, 255)) !== '' ? $n : null,
        ];
        if ($hasMonthCols) {
            $mf = (int) ($raw['month_from'] ?? 0);
            $mt = (int) ($raw['month_to'] ?? 0);
            $fields['mfrom'] = $mf > 0 ? min(60, $mf) : null;
            $fields['mto']   = $mf > 0 ? min(60, max($mf, $mt)) : null;
        }

        $id = (int) ($raw['id'] ?? 0);
        if ($id > 0) {
            $upd->execute($fields + ['id' => $id]);
            $keepIds[] = $id;
        } else {
            $ins->execute($fields);
            $keepIds[] = (int) $db->lastInsertId(); // 🔴 নাহলে নিচের DELETE এইমাত্র বসানো সারিটাই মুছে ফেলত
        }
        $saved++;
    }

    // 🔴 প্যানেল থেকে মুছে ফেলা সারি DB থেকেও যায় — প্যানেল সবসময় **সব** সারি সাবমিট করে,
    // তাই যেটা এলো না সেটা অ্যাডমিন ইচ্ছে করে মুছেছেন। (খালি সাবমিটে কিছু মোছে না — নিরাপত্তা:
    // ফর্ম আংশিক পৌঁছালে পুরো খাতা উবে যেত।)
    if ($keepIds) {
        $in = implode(',', array_fill(0, count($keepIds), '?'));
        $del = $db->prepare("DELETE FROM registration_payments WHERE registration_id = ? AND id NOT IN ($in)");
        $del->execute(array_merge([$regId], $keepIds));
    }
    return $saved;
}

// registration_payments-এ month_from/month_to ঘর দুটো আছে কিনা (প্রতি রিকোয়েস্টে একবারই দেখে)
function pay_has_month_columns(PDO $db): bool
{
    static $cache = [];
    $key = spl_object_id($db);
    if (!array_key_exists($key, $cache)) {
        try {
            $db->query('SELECT month_from, month_to FROM registration_payments LIMIT 0');
            $cache[$key] = true;
        } catch (PDOException $ex) {
            $cache[$key] = false;
        }
    }
    return $cache[$key];
}
