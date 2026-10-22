<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 🪪 রেজিস্ট্রেশন কার্ড — **তিন পাতায় একই কার্ড**  (২০২৬-১০-২১)
 *
 *   register-thanks.php   রেজিস্ট্রেশনের ঠিক পরে
 *   pay.php               স্থায়ী লিংক (`pay?r=…&k=…`) — ভাঁজ-করা
 *   my-registration.php   মোবাইল + রেফারেন্স দিয়ে খুঁজে পেলে
 *
 * 🔴 মার্কআপ ইচ্ছাকৃতভাবে **একটাই জায়গায়** — আলাদা তিনটা টেমপ্লেট রাখলে সময়ের
 *    সাথে তিন পাতায় তিন রকম কার্ড হয়ে যেত, আর ডাউনলোড করা ছবিটাও একেক জায়গা
 *    থেকে একেক রকম আসত (`pay_steps_html()`/`$showSnap`-এর হুবহু একই নীতি)।
 *
 * 🔴🔴 কার্ডের **ভেতরে কখনো বোতাম/লিংক বসাবেন না** — `#confirmation-card`
 *      ঠিক যতটুকু, html2canvas ঠিক ততটুকুই ছবি বানায়; ভেতরে বোতাম দিলে
 *      ডাউনলোড করা ছবিতে একটা অচল বোতাম ছাপা থাকত।
 * ════════════════════════════════════════════════════════════════════════════ */

/**
 * কার্ড আঁকতে যা যা লাগে — একটাই জায়গা থেকে।
 *
 * 🔴 `item_title` **রেজিস্ট্রেশনের স্ন্যাপশট** থেকেই (কোর্সের নাম পরে বদলালেও
 *    পুরনো কার্ড যা ছিল তাই থাকে — `registrations.item_title`-এর নীতি)।
 * 🔴 রেজিস্ট্রেশন মুছে ফেলা হলে `null` — কলার তখন পাতাটা দেখায় না।
 */
function reg_card_fetch(?PDO $db, int $regId): ?array
{
    if ($regId <= 0) {
        return null;
    }
    $db = $db ?: get_db();

    try {
        $st = $db->prepare('SELECT id, type, item_title, batch FROM registrations WHERE id = :id LIMIT 1');
        $st->execute(['id' => $regId]);
        $row = $st->fetch() ?: null;
    } catch (Throwable $e) {
        return null;
    }
    if (!$row) {
        return null;
    }

    $isCourse = ((string) ($row['type'] ?? '')) === 'course';

    // পেমেন্ট সময়সূচি (ব্যাচে অ্যাডমিনের লেখা) — শুধু **দেখানোর** জন্য, হিসাবে নয়
    $schedule = '';
    if ($isCourse) {
        try {
            $ps = $db->prepare('SELECT cb.payment_schedule FROM registrations r
                                JOIN course_batches cb ON cb.id = r.item_id
                                WHERE r.id = :id');
            $ps->execute(['id' => $regId]);
            $schedule = trim((string) ($ps->fetchColumn() ?: ''));
        } catch (Throwable $e) {
            $schedule = '';
        }
    }

    return [
        'ref'        => (int) $row['id'],
        'type'       => (string) ($row['type'] ?? ''),
        'item_title' => (string) ($row['item_title'] ?? ''),
        'batch'      => (string) ($row['batch'] ?? ''),
        'is_course'  => $isCourse,
        'schedule'   => $schedule,
    ];
}

/** কার্ডটা নিজে — `#confirmation-card`, ঠিক এই অংশটুকুই ছবি হয়ে ডাউনলোড হয় */
function reg_card_html(array $c): string
{
    $isCourse = !empty($c['is_course']);
    $word     = $isCourse ? 'রেজিস্ট্রেশন' : 'অর্ডার';
    $ref      = (int) ($c['ref'] ?? 0);

    // পেমেন্ট সময়সূচি — "লেখা : টাকা" প্রতি লাইনে, শেষ কোলন ধরে ভাগ
    $schedHtml = '';
    $schedule  = trim((string) ($c['schedule'] ?? ''));
    if ($schedule !== '') {
        $rows = '';
        foreach (preg_split('/\r\n|\r|\n/', $schedule) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $pos = mb_strrpos($line, ':');
            $lbl = $pos !== false ? trim(mb_substr($line, 0, $pos)) : $line;
            $amt = $pos !== false ? trim(mb_substr($line, $pos + 1)) : '';
            $rows .= '
                <div class="flex items-center justify-between gap-3 py-1.5" style="border-bottom:0.5px solid rgba(0,0,0,0.06);">
                    <span class="text-sm text-gray-700">' . e($lbl) . '</span>'
                    . ($amt !== '' ? '<span class="text-sm font-black text-gray-900 whitespace-nowrap">' . e($amt) . '</span>' : '') . '
                </div>';
        }
        if ($rows !== '') {
            $schedHtml = '
        <div class="rounded-xl p-4 mb-6 text-left" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
            <p class="text-sm font-black mb-2 flex items-center gap-1.5" style="color:rgb(var(--c-deep));">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" class="w-4 h-4"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                পেমেন্ট সময়সূচি — কখন কত দিতে হবে
            </p>' . $rows . '
        </div>';
        }
    }

    return '
    <div id="confirmation-card" class="bg-white rounded-2xl shadow-lg p-8 sm:p-10 text-center overflow-hidden relative" style="border:2px solid rgb(var(--c-border));">
        <div class="absolute top-0 left-0 right-0 h-1.5" style="background:linear-gradient(90deg, rgb(var(--c-deep)), rgb(var(--c-primary)), rgb(var(--c-primary-2)));"></div>
        <div class="w-20 h-20 mx-auto mb-5 rounded-full flex items-center justify-center" style="background:rgb(var(--c-tint));">
            <svg viewBox="0 0 24 24" fill="none" stroke="rgb(var(--c-primary))" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-10 h-10"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h1 class="text-2xl sm:text-3xl font-black text-gray-900 mb-3">ধন্যবাদ, ' . $word . ' সফল হয়েছে! 🎉</h1>
        <p class="text-gray-500 mb-2">আপনার ' . $word . ' সফলভাবে গ্রহণ করা হয়েছে</p>
        <p class="text-lg font-black mb-6" style="color:rgb(var(--c-primary));">' . e((string) ($c['item_title'] ?? '')) . '</p>
        <div class="rounded-xl p-4 mb-6" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
            <p class="text-xs text-gray-500 uppercase tracking-wide">রেফারেন্স নম্বর</p>
            <p class="text-2xl font-black text-gray-800">#' . $ref . '</p>
        </div>' . $schedHtml . '
        <p class="text-gray-600 text-sm leading-relaxed">' . e(thanks_note_text($isCourse)) . '</p>
    </div>';
}

/**
 * ⬇️ ডাউনলোড বোতাম — কার্ডের **বাইরে** বসাতে হবে।
 * ⚠️ `reg_card_scripts()`-ও একই পাতায় দিতে হবে, নাহলে বোতামটা কিছুই করবে না।
 */
function reg_card_download_btn(string $label = 'ডাউনলোড করুন', string $class = ''): string
{
    /* 🔴 `id` ও ভেতরের `<span>` দুটোই `reg_card_scripts()`-এর JS ধরে রাখে
     *    (span-এ "অপেক্ষা করুন..." লেখা হয়) — কখনো সরাবেন না।
     * $class দিলে নিজের স্টাইল, নাহলে আগের চেহারা হুবহু। */
    if ($class !== '') {
        return '<button type="button" id="download-confirmation-btn" class="' . e($class) . '">'
            . '<i data-lucide="download"></i> <span>' . e($label) . '</span></button>';
    }

    return '
        <button type="button" id="download-confirmation-btn" class="px-8 py-3 rounded-xl font-bold inline-flex items-center justify-center gap-2 shadow-lg" style="background:rgb(var(--c-tint));color:rgb(var(--c-deep));border:2px solid rgb(var(--c-primary));">
            <i data-lucide="download" class="w-5 h-5"></i> <span>' . e($label) . '</span>
        </button>';
}

/**
 * html2canvas + ডাউনলোডের স্ক্রিপ্ট।
 *
 * 🔴 `site-footer.php`-এর বড় স্ক্রিপ্টের **বাইরে** আলাদা ব্লক — ওখানে কিছু ভাঙলে
 *    আইকন/মেনু/গ্যালারি সব একসাথে মরে (`eduIcons()`-এর ঘরে বিস্তারিত)।
 * ⚠️ কার্ডটা ভাঁজ-করা `<details>`-এর ভেতরে থাকলেও সমস্যা নেই — বোতামটা দেখতেই
 *    ভাঁজ খুলতে হয়, তাই ক্লিকের সময় কার্ডটা সবসময় দৃশ্যমান থাকে।
 */
function reg_card_scripts(int $regId): string
{
    $v    = @filemtime(dirname(__DIR__) . '/assets/js/html2canvas.min.js') ?: '1';
    $id   = (int) $regId;
    $csrf = urlencode(csrf_token());

    return <<<HTML
<script src="assets/js/html2canvas.min.js?v={$v}"></script>
<script>
(function () {
    var btn = document.getElementById('download-confirmation-btn');
    if (!btn) return;

    btn.addEventListener('click', function () {
        var card = document.getElementById('confirmation-card');
        if (!card) return;
        var label = btn.querySelector('span');
        var originalLabel = label ? label.textContent : '';

        btn.disabled = true;
        if (label) label.textContent = 'অপেক্ষা করুন...';

        html2canvas(card, { backgroundColor: '#ffffff', scale: 2 }).then(function (canvas) {
            var link = document.createElement('a');
            link.download = 'registration-confirmation-{$id}.jpg';
            link.href = canvas.toDataURL('image/jpeg', 0.92);
            link.click();

            // ডাউনলোড লগ করা — best-effort, ব্যর্থ হলেও ডাউনলোড আটকাবে না
            fetch('log-download.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'registration_id={$id}&csrf_token={$csrf}'
            }).catch(function () {});

            btn.disabled = false;
            if (label) label.textContent = originalLabel;
        }).catch(function () {
            btn.disabled = false;
            if (label) label.textContent = originalLabel;
            alert('দুঃখিত, ছবি তৈরি করা যায়নি। আবার চেষ্টা করুন।');
        });
    });
})();
</script>
HTML;
}

/**
 * 🔗 "এই লিংকটা সেভ করে রাখুন" — স্থায়ী পেমেন্ট/কার্ড লিংক + কপি বোতাম।
 *
 * 🔴 লিংকে HMAC চাবি থাকে (`pay_link_token()`), তাই আইডি অনুমান করে অন্যের
 *    পাতা খোলা যায় না — এজন্যই এটা অভিভাবককে দেওয়া নিরাপদ।
 * 🔴 চাবি সেট না থাকলে (`pay_link_url()` খালি) পুরো ব্লকটাই রেন্ডার হয় না।
 */
function reg_card_save_link_url(string $relUrl): string
{
    if (trim($relUrl) === '') {
        return '';
    }
    $base = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';

    return $base . '/' . ltrim($relUrl, '/');
}

/**
 * 🔗 "লিংক কপি করুন" বোতাম — কাজের প্যানেলের গ্রিডে বসে।
 *
 * 🔴 `data-save-link` অ্যাট্রিবিউটটাই `reg_card_save_link_scripts()`-এর ধরার জায়গা
 *    (ঐ স্ক্রিপ্ট `querySelector('[data-save-link]')` করে) — নাম বদলাবেন না।
 */
function reg_card_save_link_btn(string $relUrl, string $class = ''): string
{
    $full = reg_card_save_link_url($relUrl);
    if ($full === '') {
        return '';
    }
    $cls = $class !== '' ? $class : 'px-5 py-2.5 rounded-xl font-bold text-white shadow';

    return '<button type="button" data-save-link="' . e($full) . '" class="' . e($cls) . '">'
        . '🔗 লিংক কপি করুন</button>';
}

/**
 * 🔗 স্থায়ী লিংকটা **ভাঁজ করা** `<details>`-এ — অভিভাবক এটা রেখে দিলে পরে যেকোনো দিন
 *    কার্ড নামাতে ও পেমেন্ট জানাতে পারবেন (লিংকে HMAC চাবি আছে, অনুমান করা যায় না)।
 *
 * 🔴 আগে এটা আলাদা একটা বড় তিন-লাইনের বাক্স ছিল (~২০০px) — পাতাটা নিচে নিচে নেমে
 *    যাওয়ার অন্যতম কারণ। এখন কপি করা হয় উপরের বোতাম দিয়ে, আর কাঁচা লিংকটা শুধু
 *    দরকারে খোলা হয়।
 * 🔴 ঘরটা ইচ্ছাকৃতভাবে DOM-এ থাকেই (collapsed) — কপি কোনো কারণে ব্যর্থ হলে JS
 *    `details.open = true` করে দেয়, তখন হাতে কপি করা যায়। **ফলব্যাকটা সরাবেন না।**
 * 🔴 চাবি সেট না থাকলে (`pay_link_url()` খালি) পুরো ব্লকটাই রেন্ডার হয় না।
 */
function reg_card_save_link_html(string $relUrl): string
{
    $full = reg_card_save_link_url($relUrl);
    if ($full === '') {
        return '';
    }

    return '<details class="rtx-link">'
        . '<summary>🔗 স্থায়ী লিংকটা দেখুন / হাতে কপি করুন</summary>'
        . '<input type="text" class="rtx-url" readonly value="' . e($full) . '" onclick="this.select()">'
        . '</details>';
}

/** উপরের কপি বোতামের JS — 🔴 ফুটারের বড় স্ক্রিপ্টের বাইরে আলাদা ব্লকে */
function reg_card_save_link_scripts(): string
{
    return <<<'HTML'
<script>
(function () {
    var btn = document.querySelector('[data-save-link]');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var url = btn.getAttribute('data-save-link') || '';
        var old = btn.textContent;
        var done = function () { btn.textContent = '✓ কপি হয়েছে'; setTimeout(function () { btn.textContent = old; }, 1800); };
        // 🔴 clipboard API শুধু HTTPS-এ চলে — ব্যর্থ হলে পুরনো পদ্ধতিতে ফলব্যাক
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, function () { legacy(url, done); });
        } else { legacy(url, done); }
    });
    function legacy(txt, done) {
        try {
            var ta = document.createElement('textarea');
            ta.value = txt; ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta); ta.select(); document.execCommand('copy');
            document.body.removeChild(ta); done();
        } catch (err) { reveal(); }
    }
    // 🔴 কপি করা না গেলে ভাঁজ করা লিংকটা খুলে দেওয়া হয় — তখন হাতে কপি করা যায়
    function reveal() {
        var d = document.querySelector('details.rtx-link');
        if (d) { d.open = true; }
        btn.textContent = 'নিচের লিংকটা কপি করুন';
    }
})();
</script>
HTML;
}
