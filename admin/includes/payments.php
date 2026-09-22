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
    $hasRegFee = parse_price_to_number($batch['secondary_fee'] ?? '') > 0;
    $months    = (int) ($batch['total_parcels'] ?? 0);
    if ($hasRegFee && $months >= 1) { return 'reg_monthly'; }
    if ($months >= 2)               { return 'monthly'; }
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
        return [pay_blank_row(1, 'onetime', 'পুরো মূল্য', $due)];
    }

    // `*` ইচ্ছাকৃত — নতুন কলাম (registration_fee/course_months) মাইগ্রেশনের আগে না থাকলেও
    // কোয়েরি ভাঙে না; হেল্পারগুলো `?? 0` দিয়ে পুরনো উৎসে ফলব্যাক করে।
    $stmt = $db->prepare('SELECT * FROM course_batches WHERE id = :id');
    $stmt->execute(['id' => $reg['item_id']]);
    $batch = $stmt->fetch();
    if (!$batch) {
        // ব্যাচ ডিলিট/আর্কাইভ হয়ে গেছে — সর্বশেষ জানা পরিমাণ দিয়ে একটা সারি
        return [pay_blank_row(1, 'onetime', 'পুরো মূল্য', (float) ($reg['income_amount'] ?? 0))];
    }

    $mode   = pay_guess_fee_mode($batch);
    $fee    = parse_price_to_number($batch['price'] ?? '');
    $regFee = pay_batch_reg_fee($batch);
    $months = pay_batch_months($batch);
    $rows   = [];
    $seq    = 1;

    if ($mode === 'onetime') {
        return [pay_blank_row(1, 'onetime', 'পুরো কোর্স ফি', $fee)];
    }
    // রেজিস্ট্রেশন ফি — এক বা একাধিক কিস্তিতে (reg_installments)
    if ($mode === 'reg_monthly') {
        $label    = trim((string) ($batch['secondary_fee_label'] ?? '')) ?: 'রেজিস্ট্রেশন ফি';
        $regParts = pay_batch_reg_installments($batch);
        foreach (pay_split_amount($regFee, $regParts) as $i => $part) {
            $rows[] = pay_blank_row($seq++, 'registration', pay_part_label($label, $i + 1, $regParts), $part);
        }
    }

    // বেতন — ডিফল্টে প্রতি মাসে একটা কিস্তি, কিন্তু tuition_installments দিলে কম কিস্তিতে
    // (যেমন ৪ মাসের কোর্সের বেতন ২ বারে → "১ম–২য় মাস" ও "৩য়–৪র্থ মাস", প্রতিটায় ২ মাসের টাকা)
    $tuitionParts = pay_batch_tuition_installments($batch, $months);
    foreach (pay_month_groups($months, $tuitionParts) as $group) {
        [$from, $to] = $group;
        $rows[] = pay_blank_row($seq++, 'monthly', pay_month_span_label($from, $to), $fee * ($to - $from + 1));
    }
    return $rows;
}

// খালি (এখনো সেভ না হওয়া) কিস্তির সারি
function pay_blank_row(int $seq, string $kind, string $label, float $due): array
{
    return [
        'id' => 0, 'seq' => $seq, 'kind' => $kind, 'label' => $label,
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
    $money = fn(float $v) => '৳' . number_format($v, (fmod($v, 1) == 0.0) ? 0 : 2);
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
    $ins = $db->prepare(
        'INSERT INTO registration_payments
            (registration_id, seq, kind, label, amount_due, discount_type, discount_value, discount_amount, amount_paid, is_skipped, paid_at, method, note)
         VALUES (:reg, :seq, :kind, :label, :due, :dtype, :dval, :damt, :paid, :skip, :pat, :method, :note)'
    );
    $upd = $db->prepare(
        'UPDATE registration_payments SET seq = :seq, kind = :kind, label = :label, amount_due = :due,
            discount_type = :dtype, discount_value = :dval, discount_amount = :damt, amount_paid = :paid,
            is_skipped = :skip, paid_at = :pat, method = :method, note = :note
         WHERE id = :id AND registration_id = :reg'
    );

    $saved = 0;
    foreach ($input as $i => $raw) {
        $label = trim((string) ($raw['label'] ?? ''));
        if ($label === '') {
            continue; // লেবেল ছাড়া সারি অর্থহীন — চুপচাপ বাদ
        }
        $due   = max(0.0, (float) str_replace(',', '', (string) ($raw['amount_due'] ?? 0)));
        $dtype = ($raw['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
        $dval  = max(0.0, (float) str_replace(',', '', (string) ($raw['discount_value'] ?? 0)));
        $paid  = max(0.0, (float) str_replace(',', '', (string) ($raw['amount_paid'] ?? 0)));
        $pat   = trim((string) ($raw['paid_at'] ?? ''));

        // 🔴 ছাড়ের টাকা সার্ভারেই হিসাব হয় — ব্রাউজার থেকে আসা মান বিশ্বাস করা হয় না
        $fields = [
            'reg'    => $regId,
            'seq'    => (int) ($raw['seq'] ?? ($i + 1)),
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

        $id = (int) ($raw['id'] ?? 0);
        if ($id > 0) {
            $upd->execute($fields + ['id' => $id]);
        } else {
            $ins->execute($fields);
        }
        $saved++;
    }
    return $saved;
}
