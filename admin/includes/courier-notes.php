<?php
// রেজিস্ট্রেশনের রঙিন নোট (registration_courier_notes) — শেয়ার্ড হেল্পার।
// registrations.php (approve করার সময় নোট দেওয়া), courier-prepare.php (কার্ডে চিপ দেখানো),
// courier-note-assign.php (ডেডিকেটেড পেজ) — তিন জায়গা থেকেই ব্যবহার হয়, লজিক ডুপ্লিকেট না করে।
// form-helpers.php-এর প্যাটার্ন অনুসরণ করে।

/** নোটের রঙের অপশন (key => বাংলা নাম) — অ্যাডমিন ড্রপডাউনে দেখানো হয় */
function courier_note_colors(): array
{
    return [
        'amber'   => 'হলুদ/কমলা',
        'accent'  => 'নীল',
        'success' => 'সবুজ',
        'pro'     => 'বেগুনি',
        'danger'  => 'লাল',
    ];
}

/**
 * নোট চিপের inline CSS। অজানা key হলে amber; `#rrggbb` কাস্টম hex-ও সাপোর্ট করে
 * (courier_note_types.color-এ ভবিষ্যতে hex রাখা হলে ভাঙবে না)।
 */
function note_chip_css(string $c): string
{
    $map = [
        'amber'   => 'background:#fef3c7;color:#92400e',
        'accent'  => 'background:#dbeafe;color:#1e40af',
        'success' => 'background:#dcfce7;color:#166534',
        'warning' => 'background:#fef3c7;color:#92400e',
        'pro'     => 'background:#f3e8ff;color:#6b21a8',
        'danger'  => 'background:#fee2e2;color:#991b1b',
    ];
    if (isset($map[$c])) {
        return $map[$c];
    }
    return preg_match('/^#[0-9a-f]{6}$/i', $c) ? "background:{$c}22;color:{$c}" : $map['amber'];
}

/**
 * একাধিক রেজিস্ট্রেশনের নোট একবারে (N+1 কোয়েরি এড়াতে)।
 * @param int[] $regIds
 * @return array<int, array<int, array{id:int,lbl:string,col:string}>> registration_id => নোটের তালিকা
 */
function fetch_registration_notes(PDO $db, array $regIds): array
{
    $ids = array_values(array_filter(array_map('intval', $regIds)));
    if (!$ids) {
        return [];
    }
    $in = implode(',', $ids); // সবগুলো intval করা — SQL ইনজেকশন সম্ভব না
    $rows = $db->query(
        "SELECT rcn.id, rcn.registration_id,
                COALESCE(nt.label, rcn.custom_text) lbl,
                COALESCE(rcn.color, nt.color, 'amber') col
         FROM registration_courier_notes rcn
         LEFT JOIN courier_note_types nt ON nt.id = rcn.note_type_id
         WHERE rcn.registration_id IN ($in)
         ORDER BY rcn.id ASC"
    )->fetchAll();

    $out = [];
    foreach ($rows as $n) {
        $out[(int) $n['registration_id']][] = [
            'id'  => (int) $n['id'],
            'lbl' => (string) $n['lbl'],
            'col' => (string) $n['col'],
        ];
    }
    return $out;
}

/** একটা রেজিস্ট্রেশনের নোট (উপরেরটার একক-রেজিস্ট্রেশন সুবিধা) */
function fetch_one_registration_notes(PDO $db, int $regId): array
{
    return fetch_registration_notes($db, [$regId])[$regId] ?? [];
}

/** বারবার ব্যবহারযোগ্য নোট-টাইপ (courier_note_types) */
function fetch_courier_note_types(PDO $db): array
{
    return $db->query('SELECT id, label, color FROM courier_note_types ORDER BY sort_order ASC, id ASC')->fetchAll();
}

/**
 * নোট যোগ: note_type_id > 0 হলে প্রিসেট টাইপ, নাহলে custom_text + color।
 * দুটোই খালি হলে কিছু হয় না (false রিটার্ন)।
 */
function add_registration_note(PDO $db, int $regId, int $typeId, string $customText, string $color): bool
{
    if ($regId <= 0) {
        return false;
    }
    if ($typeId > 0) {
        $db->prepare('INSERT INTO registration_courier_notes (registration_id, note_type_id) VALUES (:r, :t)')
           ->execute(['r' => $regId, 't' => $typeId]);
        return true;
    }
    $customText = trim($customText);
    if ($customText === '') {
        return false;
    }
    $color = array_key_exists($color, courier_note_colors()) ? $color : 'amber';
    $db->prepare('INSERT INTO registration_courier_notes (registration_id, custom_text, color) VALUES (:r, :c, :col)')
       ->execute(['r' => $regId, 'c' => mb_substr($customText, 0, 120), 'col' => $color]);
    return true;
}

/** নোট মোছা (registration_id মিলিয়ে — অন্য রেজিস্ট্রেশনের নোট মোছা ঠেকাতে) */
function delete_registration_note(PDO $db, int $regId, int $noteId): void
{
    $db->prepare('DELETE FROM registration_courier_notes WHERE id = :id AND registration_id = :r')
       ->execute(['id' => $noteId, 'r' => $regId]);
}

/**
 * নোট চিপগুলো রেন্ডার করে। $deleteFormAction দেওয়া থাকলে প্রতিটা চিপে একটা ছোট ✕ ডিলিট ফর্ম বসে
 * (নেস্টেড ফর্ম এড়াতে — যে পেজে চিপ একটা বড় ফর্মের ভেতরে, সেখানে $deleteFormAction দেবেন না)।
 */
function render_note_chips(array $notes, string $deleteFormAction = '', int $regId = 0, string $returnUrl = ''): void
{
    foreach ($notes as $n) {
        echo '<span class="inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-lg" style="' . e(note_chip_css($n['col'])) . '">' . e($n['lbl']);
        if ($deleteFormAction !== '') {
            echo '<form method="post" action="' . e($deleteFormAction) . '" class="inline" '
               . 'onsubmit="return confirmSubmit(this, \'এই নোটটি মুছবেন?\', \'নোট মুছুন\');">'
               . csrf_field()
               . '<input type="hidden" name="registration_id" value="' . (int) $regId . '">'
               . '<input type="hidden" name="note_id" value="' . (int) $n['id'] . '">'
               . ($returnUrl !== '' ? '<input type="hidden" name="return_url" value="' . e($returnUrl) . '">' : '')
               . '<button type="submit" class="text-current opacity-70 hover:opacity-100 align-middle" aria-label="মুছুন">'
               . '<i data-lucide="x" class="w-3 h-3"></i></button></form>';
        }
        echo '</span>';
    }
}
