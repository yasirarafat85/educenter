<?php
// জেনেরিক CRUD ফর্ম-রেন্ডারিং হেল্পার — আগে admin/manage.php এর ভেতরে ডিফাইন করা ছিল, এখন শেয়ার্ড
// ফাইলে বের করে নেওয়া হয়েছে যাতে admin/course-batches.php (কাস্টম পেজ, entities.php এর জেনেরিক
// ইঞ্জিন ব্যবহার করে না) একই render_field()/admin_image_src()/make_unique_value() রিইউজ করতে পারে,
// কোড ডুপ্লিকেট না করে।

// ছবির path DB তে সাইট-রুট-রিলেটিভ ("uploads/courses/xxx.jpg") সেভ থাকে, কিন্তু admin/ ফোল্ডার
// থেকে সরাসরি এই path ব্যবহার করলে ব্রাউজার admin/uploads/... খুঁজবে (ভুল, 404) — তাই "../" যোগ করতে হয়।
// এক্সটার্নাল URL (যেমন Unsplash লিংক, সরাসরি admin ফর্মে পেস্ট করা যায়) হলে অপরিবর্তিত থাকবে।
function admin_image_src(string $path): string
{
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    return '../' . $path;
}

// ------------------------------------------------------------
// UNIQUE constraint থাকা কলামে (যেমন course_batches.slug) কনফ্লিক্ট হলে -2, -3 ... যোগ করে ইউনিক করা
// ------------------------------------------------------------
function make_unique_value(PDO $db, string $table, string $column, string $baseValue, ?int $excludeId): string
{
    $value = $baseValue;
    $suffix = 2;
    while (true) {
        $sql = "SELECT COUNT(*) c FROM `$table` WHERE `$column` = :val" . ($excludeId ? ' AND id != :id' : '');
        $stmt = $db->prepare($sql);
        $params = ['val' => $value];
        if ($excludeId) {
            $params['id'] = $excludeId;
        }
        $stmt->execute($params);
        if ((int) $stmt->fetch()['c'] === 0) {
            return $value;
        }
        $value = $baseValue . '-' . $suffix;
        $suffix++;
    }
}

// ------------------------------------------------------------
// একটা ফিল্ডের জন্য ইনপুট HTML রেন্ডার করা
// $suggestions দিলে (suggest => true ফিল্ডের জন্য) datalist দিয়ে ড্রপডাউনের মতো আগের মানগুলো দেখানো হয়,
// কিন্তু ইনপুট তখনও ফ্রি-টেক্সট থাকে — চাইলে নতুন মানও লেখা যায়
// ------------------------------------------------------------
/**
 * ফিল্ড রেন্ডার + (থাকলে) নিচে সহায়ক টেক্সট।
 * `'help' => '...'` যেকোনো ফিল্ডে দেওয়া যায় — ইনপুটের নিচে ছোট ধূসর ব্যাখ্যা বসে।
 * ইউজারের কোডিং জ্ঞান নেই, তাই "এই ঘরে কী দেবেন" ধরনের ইঙ্গিত গুরুত্বপূর্ণ।
 */
function render_field(string $key, array $f, $value, array $suggestions = []): string
{
    $html = render_field_input($key, $f, $value, $suggestions);
    if (!empty($f['help'])) {
        $html .= '<p class="text-xs text-gray-500 mt-1 leading-relaxed">' . e($f['help']) . '</p>';
    }
    return $html;
}

/** আসল ইনপুট মার্কআপ (আগের render_field() এর বডি — help ছাড়া) */
function render_field_input(string $key, array $f, $value, array $suggestions = []): string
{
    $label = '<label class="block text-sm font-semibold text-gray-700 mb-1">' . e($f['label']) . ($f['required'] ?? false ? ' *' : '') . '</label>';

    switch ($f['type']) {
        case 'textarea':
            return $label . '<textarea name="' . e($key) . '" rows="4" class="w-full border rounded-xl px-4 py-2.5">' . e($value) . '</textarea>';

        case 'number':
            return $label . '<input type="number" name="' . e($key) . '" value="' . e((string) $value) . '" class="w-full border rounded-xl px-4 py-2.5">';

        case 'date':
            return $label . '<input type="date" name="' . e($key) . '" value="' . e($value) . '" required class="w-full border rounded-xl px-4 py-2.5">';

        case 'checkbox':
            $checked = $value ? 'checked' : '';
            $warnAttr = '';
            if (!empty($f['warn_off'])) {
                $warnLabel = $f['toggle_label'] ?? $f['label'];
                $warnAttr = ' onchange="handleToggleWarn(this)" data-warn-label="' . e($warnLabel) . '"';
            }
            return '<label class="flex items-center gap-3 cursor-pointer w-fit">
                <span class="relative inline-block w-11 h-6 flex-shrink-0">
                    <input type="checkbox" name="' . e($key) . '" value="1" ' . $checked . $warnAttr . ' class="sr-only peer">
                    <span class="absolute inset-0 bg-gray-300 peer-checked:bg-indigo-600 rounded-full transition-colors"></span>
                    <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow-md transition-transform peer-checked:translate-x-5"></span>
                </span>
                <span class="text-sm font-semibold text-gray-700">' . e($f['label']) . '</span>
            </label>';

        case 'lines':
            return $label . '<textarea name="' . e($key) . '" rows="4" placeholder="প্রতি লাইনে একটি" class="w-full border rounded-xl px-4 py-2.5">' . e($value) . '</textarea>';

        case 'image':
            $preview = $value ? '<img src="' . e(admin_image_src($value)) . '" class="w-24 h-24 object-cover rounded-lg mb-2 border">' : '';
            return $label . $preview .
                '<input type="text" name="' . e($key) . '" value="' . e($value) . '" placeholder="ছবির URL (অথবা নিচ থেকে আপলোড করুন)" class="w-full border rounded-xl px-4 py-2.5 mb-2">' .
                '<input type="file" name="' . e($key) . '_file" accept="image/*" class="w-full text-sm">' .
                '<p class="text-xs text-gray-500 mt-1">📐 যেকোনো ছবি চলবে — আপলোডের পর অটো <b>৪:৩</b> মাপে বসে যাবে, পুরো ছবি দেখা যাবে (কিছু কাটবে না)। সবচেয়ে ভালো ফল: <b>১০০০×৭৫০px</b> বা ৪:৩ অনুপাতের ছবি।</p>';

        default: // text
            $req = ($f['required'] ?? false) ? 'required' : '';
            $listAttr = '';
            $datalistHtml = '';
            if ($suggestions) {
                $datalistId = 'datalist_' . $key;
                $listAttr = ' list="' . e($datalistId) . '"';
                $options = implode('', array_map(fn($v) => '<option value="' . e($v) . '">', $suggestions));
                $datalistHtml = '<datalist id="' . e($datalistId) . '">' . $options . '</datalist>';
            }
            return $label . '<input type="text" name="' . e($key) . '" value="' . e($value) . '" ' . $req . $listAttr . ' class="w-full border rounded-xl px-4 py-2.5">' . $datalistHtml;
    }
}
