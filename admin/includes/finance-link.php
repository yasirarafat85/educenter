<?php
/**
 * 💼 আয়-খরচ ↔ কোর্স-ব্যাচ সংযোগ (২০২৬-১০-০৪)
 *
 * ইউজারের চাওয়া: "খরচের ক্ষেত্রে কোর্স দেখানোর সুযোগ নেই, লিখে দেওয়া যায় কিন্তু তাহলে
 * কোন কোর্স কোন ব্যাচ সঠিক হিসাব বের করা যাবে না।"
 *
 * 🔴🔴 একটাই নিয়ম, দুই টেবিলেই (income ও expenses) — **রেজিস্ট্রেশনই আগে**:
 *   - সারিতে `registration_id` থাকলে কোর্স/ব্যাচ `registrations` থেকে **তাজা** পড়া হয়,
 *     সারির নিজের ঘর নয়। কারণ `registrations.php`-এর "অন্য কোর্সে সরানো" (move-course)
 *     ঐ রেজিস্ট্রেশনের কোর্স বদলে দেয় — স্ন্যাপশট জমালে বইয়ের হিসাব নীরবে বাসি হয়ে যেত।
 *   - `registration_id` না থাকলে (হাতে লেখা আয়/খরচ) সারির নিজের ঘর।
 * এই ক্রমটা SQL-এর `COALESCE()`-এ বসানো আছে (`fin_item_select()`), তাই সব পাতায় এক।
 *
 * 🔴 ঘরগুলো **ঐচ্ছিক** — ভাড়া/বেতন/বিদ্যুৎ জাতীয় সাধারণ খরচে খালিই থাকে (আগের মতোই)।
 * 🔴 ব্যাচ পরে ডিলিট হলেও সারি থেকে যায় (FK দেওয়া হয়নি, `registrations.item_id`-এর মতোই),
 *    আর স্ন্যাপশট নাম (`item_title`/`batch`) থাকায় ইতিহাসে নামটাও পড়া যায়।
 */

/** যেসব আইটেম-টাইপ জোড়া লাগতে পারে — হোয়াইটলিস্ট, কখনো POST থেকে সরাসরি নয় */
function fin_item_types(): array
{
    return ['course', 'worksheet', 'product'];
}

/**
 * মাইগ্রেশন চলেছে কিনা — না চললে পুরো ফিচারটা চুপচাপ বাদ যায়, পাতা আগের মতোই চলে।
 * 🔴 এই গার্ডটা সরাবেন না: এই প্রজেক্টে ফাইল আগে ডিপ্লয় হয়, SQL ইউজার পরে চালান।
 */
function fin_link_ready(?PDO $db = null): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $db = $db ?: get_db();
    $ready = db_has_column($db, 'income', 'item_id') && db_has_column($db, 'expenses', 'item_id');
    return $ready;
}

/**
 * ড্রপডাউনের কোর্স-ব্যাচ তালিকা — [batch_id => ['label' => 'কোর্স — ব্যাচ', 'course' => '…', 'active' => bool]]
 * 🔴 নিষ্ক্রিয় ব্যাচও থাকে (`is_active` দিয়ে ছাঁকা হয় না) — শেষ হয়ে যাওয়া ব্যাচের খরচ/আয়ও
 *    পরে বসাতে হতে পারে; payment-methods.php-এর পিকার শুধু সক্রিয় দেখায়, সেটা আলাদা দরকার।
 */
function fin_batch_options(?PDO $db = null): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $db = $db ?: get_db();
    $cache = [];
    try {
        $rows = $db->query(
            'SELECT cb.id, cb.batch_name, cb.is_active, c.title
               FROM course_batches cb
               JOIN courses c ON c.id = cb.course_id
              ORDER BY c.title ASC, cb.sort_order ASC, cb.id ASC'
        )->fetchAll();
        foreach ($rows as $r) {
            $cache[(int) $r['id']] = [
                'course' => (string) $r['title'],
                'batch'  => (string) $r['batch_name'],
                'label'  => $r['title'] . ' — ' . $r['batch_name'],
                'active' => !empty($r['is_active']),
            ];
        }
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * POST-এ আসা ব্যাচ-আইডি **DB থেকে যাচাই** করে স্ন্যাপশট ফেরায়।
 * 🔴 POST-এর নাম/লেবেলে কখনো ভরসা নয় (move-course হ্যান্ডলারের মতোই নিয়ম)।
 * খালি/অবৈধ হলে চারটা ঘরই null — অর্থাৎ "কোনো কোর্সের সাথে জোড়া নয়"।
 */
function fin_resolve_batch(PDO $db, $batchId): array
{
    $none = ['item_type' => null, 'item_id' => null, 'item_title' => null, 'batch' => null];
    $batchId = (int) $batchId;
    if ($batchId <= 0) {
        return $none;
    }
    try {
        $st = $db->prepare(
            'SELECT cb.id, cb.batch_name, c.title
               FROM course_batches cb JOIN courses c ON c.id = cb.course_id
              WHERE cb.id = :id'
        );
        $st->execute(['id' => $batchId]);
        $row = $st->fetch();
    } catch (Throwable $e) {
        return $none;
    }
    if (!$row) {
        return $none;
    }
    return [
        'item_type'  => 'course',
        'item_id'    => (int) $row['id'],
        'item_title' => (string) $row['title'],
        'batch'      => (string) $row['batch_name'],
    ];
}

/**
 * কার্যকর (effective) কোর্স/ব্যাচের SELECT-অংশ — রেজিস্ট্রেশন আগে, নাহলে সারির নিজের ঘর।
 * $a = income/expenses টেবিলের alias, $r = registrations টেবিলের alias।
 * 🔴 মাইগ্রেশনের আগে সারির ঘরগুলো নেই, তাই তখন শুধু রেজিস্ট্রেশনের অংশটুকু বসে।
 */
function fin_item_select(string $a, string $r = 'r', ?PDO $db = null, bool $grouped = false): string
{
    // 🔴🔴 `$grouped = true` দিলে নাম-দুটো MAX()-এ মোড়া হয় — GROUP BY-ওয়ালা কোয়েরিতে এটা
    //    **বাধ্যতামূলক**। MySQL-এর ডিফল্ট `ONLY_FULL_GROUP_BY` মোডে GROUP BY-তে নেই এমন
    //    কলাম সরাসরি SELECT করলে কোয়েরিটাই এরর দেয় (SQLite চুপচাপ মেনে নেয় বলে
    //    হারনেসে ধরা পড়ত না — লাইভে আয়-ব্যয় পাতা ভেঙে যেত)।
    $t = $grouped ? 'MAX(%s)' : '%s';
    if (!fin_link_ready($db)) {
        return "$r.type AS eff_type, $r.item_id AS eff_item_id,"
             . ' ' . sprintf($t, "$r.item_title") . ' AS eff_item_title,'
             . ' ' . sprintf($t, "$r.batch") . ' AS eff_batch';
    }
    return "COALESCE($r.type, $a.item_type) AS eff_type,"
         . " COALESCE($r.item_id, $a.item_id) AS eff_item_id,"
         . ' ' . sprintf($t, "COALESCE($r.item_title, $a.item_title)") . ' AS eff_item_title,'
         . ' ' . sprintf($t, "COALESCE($r.batch, $a.batch)") . ' AS eff_batch';
}

/**
 * ফিল্টারের WHERE-অংশ — শুধু ঐ কোর্স-ব্যাচের সারি (রেজিস্ট্রেশন-জোড়া ও হাতে লেখা দুটোই)।
 *
 * 🔴🔴 দুটো শর্তে **আলাদা আলাদা প্লেসহোল্ডার** (`:fin_item` ও `:fin_item2`) — একই নাম
 *    দুইবার লিখবেন না। `includes/db.php`-এ `ATTR_EMULATE_PREPARES => false`, আর MySQL-এর
 *    নেটিভ prepare-এ একই নামের প্লেসহোল্ডার দুইবার থাকলে সরাসরি
 *    **`SQLSTATE[HY093]: Invalid parameter number`** এসে পাতা HTTP 500 হয়ে যায়
 *    (২০২৬-১০-০৪ এ লাইভে ধরা — আয়ে কোর্স বাছলেই)। ⚠️ **SQLite নীরবে মেনে নেয়**,
 *    তাই হারনেসে কখনো ধরা পড়ে না — আসল MySQL-এ চালিয়েই যাচাই করতে হয়েছিল।
 */
function fin_item_where(string $a, string $r = 'r', ?PDO $db = null): string
{
    if (!fin_link_ready($db)) {
        return "($r.type = 'course' AND $r.item_id = :fin_item)";
    }
    return "(($r.type = 'course' AND $r.item_id = :fin_item)"
         . " OR ($a.registration_id IS NULL AND $a.item_type = 'course' AND $a.item_id = :fin_item2))";
}

/**
 * উপরের WHERE-এর সাথে যে প্যারামিটারগুলো বাঁধতে হবে।
 * 🔴 মাইগ্রেশনের আগে `:fin_item2` SQL-এ থাকেই না — তখন ওটা বাঁধলে **একই HY093** এরর আসে
 *    (অব্যবহৃত প্যারামিটার বাঁধাও নিষিদ্ধ, CLAUDE.md-এর পুরনো ফাঁদ)। তাই গণনাটা এখানেই,
 *    যাতে কলার-পেজ দুটোতে নিয়ম আলাদা হয়ে না যায়।
 */
function fin_item_params(int $itemId, ?PDO $db = null): array
{
    $params = ['fin_item' => $itemId];
    if (fin_link_ready($db)) {
        $params['fin_item2'] = $itemId;
    }
    return $params;
}

/** গ্রুপিং-চাবি — ওয়ার্কশিট ৫ আর কোর্স-ব্যাচ ৫ যেন এক না হয় (course_media_all_counts()-এর মতোই) */
function fin_item_key(?string $type, $id): string
{
    $id = (int) $id;
    if ($id <= 0 || !in_array((string) $type, fin_item_types(), true)) {
        return '';     // সাধারণ — কোনো কোর্সের সাথে জোড়া নয়
    }
    return $type . ':' . $id;
}

/**
 * দেখানোর নাম। 🔴 কোর্স-ব্যাচ এখনো থাকলে **বর্তমান নাম**, নাহলে সারির স্ন্যাপশট —
 * তাই ব্যাচ রিনেম করলে রিপোর্টে নতুন নামই দেখায়, আর ডিলিট করলেও নামটা হারায় না।
 */
function fin_item_label(?string $type, $id, ?string $title = '', ?string $batch = '', ?PDO $db = null): string
{
    if (fin_item_key($type, $id) === '') {
        return 'সাধারণ (কোর্স নির্দিষ্ট নয়)';
    }
    if ($type === 'course') {
        $opts = fin_batch_options($db);
        if (isset($opts[(int) $id])) {
            return $opts[(int) $id]['label'];
        }
    }
    $title = trim((string) $title);
    $batch = trim((string) $batch);
    if ($title === '') {
        return 'মুছে ফেলা আইটেম #' . (int) $id;
    }
    return $batch !== '' ? ($title . ' — ' . $batch) : $title;
}

/** `<select name="…">`-এর ভেতরের `<option>`গুলো (কোর্স অনুযায়ী optgroup) */
function fin_batch_select_options($selected = 0, ?PDO $db = null): string
{
    $selected = (int) $selected;
    $out = '';
    $group = null;
    foreach (fin_batch_options($db) as $id => $o) {
        if ($o['course'] !== $group) {
            if ($group !== null) {
                $out .= '</optgroup>';
            }
            $group = $o['course'];
            $out .= '<optgroup label="' . e($group) . '">';
        }
        $out .= '<option value="' . $id . '"' . ($selected === $id ? ' selected' : '') . '>'
              . e($o['batch']) . ($o['active'] ? '' : ' (নিষ্ক্রিয়)') . '</option>';
    }
    if ($group !== null) {
        $out .= '</optgroup>';
    }
    return $out;
}
