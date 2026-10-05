        </div>
    </main>

    <?php
    // ══════════════════════════════════════════════════════════════════════════
    // 🦶 ফুটার (২০২৬-১০-০৫ রিডিজাইন)
    // 🔴 উপরের মেনু থেকে সরানো **Teachers · About · FAQs**-এর একমাত্র প্রবেশপথ এখন
    //    এই ফুটার (+ sitemap ও ৪০৪ পাতা) — "প্রতিষ্ঠান" কলামে। ওগুলো এখান থেকে
    //    সরাবেন না, নাহলে পাতাগুলো সাইট থেকে পৌঁছানোই যাবে না।
    // 🔴 স্টাইল পুরোটা সাধারণ CSS-এ (`style.css`-এর `.ft-*`) — **Tailwind রিবিল্ড লাগে না**;
    //    রঙ `rgb(var(--c-primary))` ইত্যাদি থেকে, তাই অ্যাডমিনের সাইট-থিম বদলালে ফুটারও বদলায়।
    // ══════════════════════════════════════════════════════════════════════════
    $ftPhone   = get_setting('contact_phone');
    $ftEmail   = get_setting('contact_email');
    $ftAddress = get_setting('contact_address');
    $ftWa      = normalize_bd_whatsapp(get_setting('contact_whatsapp'));
    $ftHours   = get_setting('contact_hours');         // 🕘 খোলার সময় (ঐচ্ছিক)
    $ftOpenN   = open_course_count();                  // 🎓 এখন কয়টা কোর্সে ভর্তি চলছে

    // 📊 ট্রাস্ট-সারি — হোমপেজের "সংখ্যায় সাফল্য" সেকশনের হুবহু একই সংখ্যা (`site_stats()`)।
    // 🔴 হোমপেজে ইচ্ছাকৃতভাবে **দেখানো হয় না** — ওখানে উপরেই বড় করে একই চারটা সংখ্যা
    //    আছে, ফুটারে আবার দিলে একই পাতায় দুইবার পড়ত।
    $ftStats = (($activePage ?? '') === 'home') ? [] : site_stats();

    // 🔴 দুই কলামের লিংক — নতুন পাবলিক পাতা বানালে এখানেও যোগ করুন (আর sitemap.php-এ)।
    $ftCols = [
        ['title' => 'কোর্স ও কেনাকাটা', 'icon' => 'shopping-bag', 'links' => [
            ['courses',         '📚', 'কোর্স সমূহ'],
            ['worksheets',      '📝', 'ওয়ার্কশিট'],
            ['products',        '🛍️', 'প্রোডাক্ট'],
            ['course-interest', '💚', 'আগ্রহ জানিয়ে রাখুন'],
            ['search',          '🔎', 'খুঁজুন'],
        ]],
        ['title' => 'প্রতিষ্ঠান', 'icon' => 'info', 'links' => [
            ['about',    'ℹ️', 'আমাদের সম্পর্কে'],
            ['teachers', '👩‍🏫', 'শিক্ষক মণ্ডলী'],
            ['faqs',     '❓', 'সাধারণ জিজ্ঞাসা'],
            ['reviews',  '⭐', 'শিক্ষার্থীদের মতামত'],
            ['gallery',  '🖼️', 'গ্যালারি'],
            ['notice',   '🔔', 'নোটিশ বোর্ড'],
        ]],
    ];
    ?>
    <footer class="ft">
        <div class="container mx-auto px-4">
            <?php if ($ftStats): ?>
                <div class="ft-trust">
                    <?php foreach ($ftStats as $ftSt): ?>
                        <div class="ft-tr">
                            <b><?= e($ftSt['value']) ?></b>
                            <span><?= e($ftSt['label']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="ft-grid">

                <?php // ── ব্র্যান্ড ── ?>
                <div class="ft-brand">
                    <a href="./" class="ft-logo">
                        <img src="<?= e($logoPath) ?>" alt="<?= e($siteName) ?> Logo" loading="lazy">
                        <span>
                            <b><?= e($siteName) ?></b>
                            <i><?= e($siteTagline) ?></i>
                        </span>
                    </a>
                    <?php // 🔴 লেখাটা অ্যাডমিন সেটিংস → "সাধারণ তথ্য" → ফুটারের বর্ণনা থেকে ?>
                    <p class="ft-about"><?= e(footer_about_text()) ?></p>

                    <?php if ($ftOpenN > 0): ?>
                        <a href="courses" class="ft-open">
                            <span aria-hidden="true">🎓</span>
                            এখন <?= (int) $ftOpenN ?> টি কোর্সে ভর্তি চলছে
                        </a>
                    <?php endif; ?>

                    <?php if ($ftWa !== ''): ?>
                        <a href="https://wa.me/<?= e($ftWa) ?>" target="_blank" rel="noopener noreferrer" class="ft-wa">
                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51l-.57-.01c-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.872.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884a9.82 9.82 0 0 1 6.988 2.896 9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.886-9.885 9.886m8.413-18.297A11.815 11.815 0 0 0 12.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 0 0-3.48-8.413Z"/></svg>
                            <span>WhatsApp-এ লিখুন</span>
                        </a>
                    <?php endif; ?>

                    <?php
$socialIcons = [
            'social_facebook' => '<path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/>',
            'social_twitter' => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
            'social_youtube' => '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12z"/>',
            'social_instagram' => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.163 6.163 0 1 0 0 12.326 6.163 6.163 0 0 0 0-12.326zm0 10.162a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>',
        ];
                    $socialColors = [
                        'social_facebook'  => '#1877F2',
                        'social_twitter'   => '#111827',
                        'social_youtube'   => '#FF0000',
                        'social_instagram' => '#E1306C',
                    ];
                    $socialNames = [
                        'social_facebook'  => 'Facebook',
                        'social_twitter'   => 'X (Twitter)',
                        'social_youtube'   => 'YouTube',
                        'social_instagram' => 'Instagram',
                    ];
                    $socialHtml = '';
                    foreach ($socialIcons as $key => $svgPath) {
                        $url = get_setting($key);
                        if ($url === '') { continue; }
                        $socialHtml .= '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer"'
                            . ' class="ft-soc" style="--soc: ' . $socialColors[$key] . '"'
                            . ' aria-label="' . e($socialNames[$key]) . '" title="' . e($socialNames[$key]) . '">'
                            . '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">' . $svgPath . '</svg></a>';
                    }
                    if ($socialHtml !== ''):
                    ?>
                        <div class="ft-socs"><?= $socialHtml ?></div>
                    <?php endif; ?>
                </div>

                <?php // ── লিংক কলাম ── ?>
                <?php foreach ($ftCols as $ftCol): ?>
                    <nav class="ft-col" aria-label="<?= e($ftCol['title']) ?>">
                        <h4><?= e($ftCol['title']) ?></h4>
                        <ul>
                            <?php foreach ($ftCol['links'] as [$ftUrl, $ftEmoji, $ftLabel]): ?>
                                <li><a href="<?= e($ftUrl) ?>"><span class="ft-em" aria-hidden="true"><?= $ftEmoji ?></span><?= e($ftLabel) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </nav>
                <?php endforeach; ?>

                <?php // ── যোগাযোগ (🔴 ফোন/ইমেইল ক্লিকযোগ্য — ফোনেই সবচেয়ে বেশি কাজে লাগে) ── ?>
                <div class="ft-col ft-contact">
                    <h4>যোগাযোগ</h4>
                    <ul>
                        <?php if ($ftAddress !== ''): ?>
                            <li>
                                <i data-lucide="map-pin" aria-hidden="true"></i>
                                <span><?= e($ftAddress) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ($ftHours !== ''): ?>
                            <li>
                                <i data-lucide="clock" aria-hidden="true"></i>
                                <span><?= e($ftHours) ?></span>
                            </li>
                        <?php endif; ?>
                        <?php if ($ftPhone !== ''): ?>
                            <li>
                                <i data-lucide="phone" aria-hidden="true"></i>
                                <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $ftPhone)) ?>"><?= e($ftPhone) ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if ($ftEmail !== ''): ?>
                            <li>
                                <i data-lucide="mail" aria-hidden="true"></i>
                                <a href="mailto:<?= e($ftEmail) ?>" class="ft-break"><?= e($ftEmail) ?></a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>

            <div class="ft-bottom">
                <?php // 🔴 সাল ও সাইটের নাম অটো — `footer_text_html()` (functions.php) ?>
                <p><?= footer_text_html() ?></p>
                <?php // 🔴 sitemap.php ইচ্ছাকৃতভাবে এখানে লিংক করা হয়নি — ওটা কাঁচা XML,
                      //    অভিভাবকের কাজে লাগে না (গুগলের জন্য robots.txt-এ বলা আছে)। ?>
                <div class="ft-bottom-links">
                    <a href="<?= !empty($_SESSION['user_id']) ? 'account' : 'account-login' ?>"><?= !empty($_SESSION['user_id']) ? 'আমার অ্যাকাউন্ট' : 'অভিভাবক লগইন' ?></a>
                    <?php if (empty($_SESSION['user_id'])): ?>
                        <span aria-hidden="true">·</span>
                        <a href="account-signup">নতুন অ্যাকাউন্ট</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </footer>

    <?php
    // ── নিচের স্টিকি নেভিগেশন বার (মোবাইল ও ডেস্কটপ দুটোতেই) — অ্যাপের মতো, দরকারি লিংক হাতের নাগালে ──
    // শেষ আইটেম "উপরে" = পেজের উপরে নিয়ে যায় (আগের আলাদা ভাসমান back-to-top বাটন এর সাথে ভিড়
    // করত, তাই বারের ভেতরে ৫ম আইটেম হিসেবে নেওয়া হয়েছে — ইউজারের আইডিয়া, ২০২৬-০৭-২১)।
    // 🔴 বারে ইচ্ছাকৃতভাবে **৫টাই স্লট** (CLAUDE.md-এর নিয়ম) — ৩২০px পর্দায় ৬টা দিলে
    //    প্রতিটা ~৫৩px হয়ে "ওয়ার্কশিট" লেবেলটাই কেটে যায়। তাই "অ্যাকাউন্ট" যোগ করতে
    //    (২০২৬-০৯-২৭) **"যোগাযোগ" বাদ দেওয়া হয়েছে**, কারণ (ক) ওটা আসলে `about` পেজে
    //    নিয়ে যেত (আইকন ফোন — বিভ্রান্তিকর), (খ) যোগাযোগের আসল পথ — ভাসমান WhatsApp
    //    বাটন — এই বারের ঠিক উপরেই সবসময় দেখা যায়, আর (গ) `about` উপরের মেনু,
    //    ফুটার ও ৪০৪ পাতায় আগে থেকেই আছে। কোর্স কেনা অভিভাবকের নিজের ড্যাশবোর্ড
    //    (জমা/বাকি, পার্সেল, গ্রুপ লিংক) এর চেয়ে অনেক বেশি ব্যবহৃত হয়।
    // 🔴 লেবেল/লিংক `$_SESSION['user_id']` দেখে বদলায় — হুবহু উপরের মেনুর প্যাটার্ন
    //    (site-header.php); এখানে `includes/user-auth.php` **লোড করা যাবে না** — ফুটার
    //    আউটপুটের পরে চলে, আর ঐ ফাইল লোড হলেই remember-কুকি বসানোর চেষ্টা করে
    //    (headers already sent)। সেশন-কী দেখাই যথেষ্ট, আর লগইন না থাকলে
    //    `account.php` নিজেই `account-login`-এ পাঠিয়ে দেয়।
    $bnLoggedIn = !empty($_SESSION['user_id']);
    $bottomNav = [
        ['label' => 'হোম',      'icon' => 'home',        'url' => './',        'id' => 'home'],
        ['label' => 'কোর্স',     'icon' => 'book-open',   'url' => 'courses',   'id' => 'courses'],
        ['label' => 'ওয়ার্কশিট', 'icon' => 'file-text',   'url' => 'worksheets','id' => 'worksheets'],
        ['label' => $bnLoggedIn ? 'অ্যাকাউন্ট' : 'লগইন', 'icon' => 'user-circle',
         'url'   => $bnLoggedIn ? 'account' : 'account-login', 'id' => 'account'],
    ];
    ?>
    <nav id="bottom-nav" aria-label="দ্রুত নেভিগেশন">
        <?php foreach ($bottomNav as $bn): ?>
            <a href="<?= e($bn['url']) ?>" class="bnav-item<?= ($activePage ?? '') === $bn['id'] ? ' bnav-on' : '' ?>">
                <i data-lucide="<?= e($bn['icon']) ?>" class="w-5 h-5"></i>
                <span><?= e($bn['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <?php // ৫ম আইটেম — "উপরে যান" (আলাদা ভাসমান বাটনের বদলে) ?>
        <button type="button" id="bnav-top" class="bnav-item" aria-label="উপরে যান">
            <i data-lucide="arrow-up" class="w-5 h-5"></i>
            <span>উপরে</span>
        </button>
    </nav>

    <?php // ── ভাসমান WhatsApp বাটন — অ্যাডমিন contact_whatsapp সেট করলে দেখাবে (এক ট্যাপে চ্যাট) ──
    $waNum = normalize_bd_whatsapp(get_setting('contact_whatsapp'));
    if ($waNum !== ''):
    ?>
    <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener noreferrer" id="wa-float" aria-label="WhatsApp-এ যোগাযোগ" title="WhatsApp-এ যোগাযোগ">
        <svg viewBox="0 0 24 24" fill="currentColor" class="w-7 h-7"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.885-9.885 9.885M20.52 3.449C18.24 1.245 15.24.044 12.045.044 5.463.044.104 5.402.101 11.986c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652a11.94 11.94 0 005.71 1.454h.005c6.581 0 11.945-5.359 11.949-11.945a11.9 11.9 0 00-3.484-8.442"/></svg>
    </a>
    <?php endif; ?>

    <script>
        // 🔴 lucide.js এখন `defer` দিয়ে লোড হয় (site-header.php) — অর্থাৎ **এই ইনলাইন স্ক্রিপ্টটা
        // আগে চলে, লাইব্রেরিটা পরে**। তাই সরাসরি ডাকলে "lucide is not defined" হয়ে এই পুরো
        // স্ক্রিপ্টটাই মরে যেত (আইকন, মোবাইল মেনু, লাইটবক্স, ফেসবুক lazy-load — সব একসাথে)।
        // 🔴 নিয়ম: **সবসময় eduIcons() ডাকুন**, লাইব্রেরিটা সরাসরি নয়।
        function eduIcons() { if (window.lucide) { lucide.createIcons(); } }
        // defer করা স্ক্রিপ্ট DOMContentLoaded-এর ঠিক আগে চলে, তাই এখানে লাইব্রেরিটা নিশ্চিতভাবে আছে
        document.addEventListener('DOMContentLoaded', eduIcons);

        // ── স্টিকি বারের "উপরে" আইটেম — ক্লিকে পেজের উপরে নিয়ে যায় (আলাদা ভাসমান বাটন নয়) ──
        (function () {
            var btn = document.getElementById('bnav-top');
            if (!btn) { return; }
            btn.addEventListener('click', function () {
                // prefers-reduced-motion সম্মান করা (CLAUDE.md এর মোবাইল/অ্যাক্সেসিবিলিটি নিয়ম)
                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
            });
        })();

        const mobileBtn = document.getElementById('mobile-menu-btn');
        const mobileNav = document.getElementById('mobile-nav');
        if (mobileBtn && mobileNav) {
            const mobileHeader = mobileBtn.closest('header');
            function setMenu(open) {
                mobileNav.classList.toggle('hidden', !open);
                document.body.classList.toggle('mobile-menu-open', open); // খোলা হলে হেডার ফ্রস্টেড-গ্লাস
                mobileBtn.innerHTML = open
                    ? '<i data-lucide="x" class="w-6 h-6 text-gray-700"></i>'
                    : '<i data-lucide="menu" class="w-6 h-6 text-gray-700"></i>';
                eduIcons();
            }
            mobileBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                setMenu(mobileNav.classList.contains('hidden'));
            });
            // মেনুর বাইরে কোথাও ট্যাপ করলে বন্ধ
            document.addEventListener('click', (e) => {
                if (!mobileNav.classList.contains('hidden') && mobileHeader && !mobileHeader.contains(e.target)) {
                    setMenu(false);
                }
            });
            // মেনুতে উপরে সোয়াইপ করলে বন্ধ
            let mmTouchY = null;
            mobileNav.addEventListener('touchstart', (e) => { mmTouchY = e.touches[0].clientY; }, { passive: true });
            mobileNav.addEventListener('touchmove', (e) => {
                if (mmTouchY !== null && mmTouchY - e.touches[0].clientY > 55) { setMenu(false); mmTouchY = null; }
            }, { passive: true });
            mobileNav.addEventListener('touchend', () => { mmTouchY = null; });
        }
    </script>

    <!-- ছবি জুম (লাইটবক্স) — .zoomable ছবিতে ক্লিক করলে বড় হয়ে খোলে, ট্যাপ/Esc-এ বন্ধ -->
    <div id="img-lightbox" aria-hidden="true"><img src="" alt=""><button type="button" class="ilb-close" aria-label="বন্ধ করুন">✕</button></div>
    <script>
    (function(){
        var lb = document.getElementById('img-lightbox');
        if (!lb) { return; }
        var img = lb.querySelector('img');
        function openLb(src, alt){ img.src = src; img.alt = alt || ''; lb.classList.add('open'); lb.setAttribute('aria-hidden','false'); document.body.style.overflow = 'hidden'; }
        function closeLb(){ lb.classList.remove('open'); lb.setAttribute('aria-hidden','true'); img.removeAttribute('src'); document.body.style.overflow = ''; }
        document.addEventListener('click', function(e){
            var t = e.target.closest('img.zoomable');
            if (t) { e.preventDefault(); openLb(t.currentSrc || t.src, t.alt); }
        });
        lb.addEventListener('click', closeLb);
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape' && lb.classList.contains('open')) { closeLb(); } });
    })();
    </script>

    <!-- 📸 কোর্সের ছবি ও ভিডিও — বোতামে ক্লিকে ওভারলে (render_course_media() মার্কআপ বসায়) -->
    <script>
    (function(){
        var overlays = document.querySelectorAll('.cm-ov');
        if (!overlays.length) { return; }   // এই পেজে ছবি/ভিডিও নেই — কিছুই করার নেই

        // 🔴 ওভারলে অবশ্যই <body>-র সরাসরি সন্তান হতে হবে। কার্ডের ভেতরে থাকলে
        // `position: fixed` আর ভিউপোর্ট ধরে বসে না — কোনো পূর্বপুরুষে transform/animation
        // (যেমন `.fade-in`) থাকলে সেটাই containing block হয়ে যায়, ফলে ওভারলে ঐ কার্ডের
        // ভেতরেই আটকে ছোট হয়ে দেখায় (২০২৬-০৯-২৫, ইউজারের স্ক্রিনশটে ধরা)।
        overlays.forEach(function (o) {
            if (o.parentNode !== document.body) { document.body.appendChild(o); }
        });

        function lock(on){ document.body.style.overflow = on ? 'hidden' : ''; }
        function closeAll(){
            overlays.forEach(function(o){ o.hidden = true; });
            var f = document.querySelector('#cm-videos .cm-frame');
            if (f) { f.innerHTML = ''; }   // 🔴 iframe সরানো = ভিডিও থেমে যায়
            lock(false);
        }

        document.querySelectorAll('[data-cm-open]').forEach(function(btn){
            btn.addEventListener('click', function(){
                var el = document.getElementById(btn.getAttribute('data-cm-open'));
                if (!el) { return; }
                closeAll();
                el.hidden = false;
                lock(true);
                var list = el.querySelector('.cm-vlist'), play = el.querySelector('.cm-vplay');
                if (list && play) { list.hidden = false; play.hidden = true; }
            });
        });
        document.querySelectorAll('[data-cm-close]').forEach(function(b){ b.addEventListener('click', closeAll); });

        // 🔒 ওভারলের ভেতরে ডান-ক্লিক/লং-প্রেস মেনু বন্ধ (ছবি সেভ করা কঠিন করতে)।
        // ⚠️ এটা কেবল সহজ পথটা বন্ধ করে — ডেভটুল/সরাসরি URL দিয়ে ছবি পাওয়া তবু সম্ভব।
        document.querySelectorAll('.cm-ov').forEach(function(ov){
            ov.addEventListener('contextmenu', function(e){ e.preventDefault(); });
            ov.addEventListener('dragstart', function(e){ e.preventDefault(); });
        });

        // ── ছবির স্লাইডার ──
        var pov = document.getElementById('cm-photos');
        if (pov) {
            var thumbs = Array.prototype.slice.call(pov.querySelectorAll('.cm-th'));
            var shot = pov.querySelector('.cm-shot');
            var cap = pov.querySelector('.cm-cap');
            var count = pov.querySelector('.cm-count');
            var idx = 0;
            var bn = function(n){ return String(n); };   // 🔴 English অঙ্কেই — বাংলা ১/৮/৯ পড়া যায় না

            function paint(){
                var t = thumbs[idx];
                if (!t) { return; }
                // 🔒 background-image (src নয়) — লং-প্রেসে "Download image" মেনু আসে না
                shot.style.backgroundImage = 'url("' + t.getAttribute('data-src').replace(/"/g, '%22') + '")';
                shot.setAttribute('aria-label', t.getAttribute('data-cap') || '');
                cap.textContent = t.getAttribute('data-cap') || '';
                count.textContent = bn(idx + 1) + ' / ' + bn(thumbs.length);
                thumbs.forEach(function(el, i){ el.setAttribute('aria-current', i === idx ? 'true' : 'false'); });
                t.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
            thumbs.forEach(function(t, i){ t.addEventListener('click', function(){ idx = i; paint(); }); });
            pov.querySelector('[data-cm-prev]').addEventListener('click', function(){ idx = (idx - 1 + thumbs.length) % thumbs.length; paint(); });
            pov.querySelector('[data-cm-next]').addEventListener('click', function(){ idx = (idx + 1) % thumbs.length; paint(); });
            paint();

            // মোবাইলে সোয়াইপ
            var x0 = null;
            pov.querySelector('.cm-stage').addEventListener('touchstart', function(e){ x0 = e.touches[0].clientX; }, { passive: true });
            pov.querySelector('.cm-stage').addEventListener('touchend', function(e){
                if (x0 === null) { return; }
                var dx = e.changedTouches[0].clientX - x0;
                if (Math.abs(dx) > 45) { idx = (idx + (dx < 0 ? 1 : thumbs.length - 1)) % thumbs.length; paint(); }
                x0 = null;
            });
        }

        // ── ভিডিও: ট্যাপ করার আগে iframe বসেই না (পেজ হালকা রাখতে) ──
        var vov = document.getElementById('cm-videos');
        if (vov) {
            var vlist = vov.querySelector('.cm-vlist');
            var vplay = vov.querySelector('.cm-vplay');
            var vframe = vov.querySelector('.cm-frame');
            var vcap = vov.querySelector('.cm-vcap');
            vov.querySelectorAll('.cm-vcard').forEach(function(card){
                card.addEventListener('click', function(){
                    var f = document.createElement('iframe');
                    f.src = card.getAttribute('data-embed');
                    f.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture; fullscreen');
                    f.setAttribute('allowfullscreen', 'allowfullscreen');
                    f.setAttribute('title', card.getAttribute('data-name') || 'ভিডিও');
                    vframe.innerHTML = '';
                    vframe.appendChild(f);
                    vcap.textContent = card.getAttribute('data-name') || '';
                    vlist.hidden = true;
                    vplay.hidden = false;
                });
            });
            vov.querySelector('.cm-back').addEventListener('click', function(){
                vframe.innerHTML = '';
                vplay.hidden = true;
                vlist.hidden = false;
            });
        }

        // কার্ড থেকে "📸 ৬টি ছবি" লিংকে এলে (detail?...#cm-photos) সরাসরি খুলে যায়
        var wanted = (location.hash || '').replace('#', '');
        if (wanted === 'cm-photos' || wanted === 'cm-videos') {
            var openBtn = document.querySelector('[data-cm-open="' + wanted + '"]');
            if (openBtn) { openBtn.click(); }
        }

        document.addEventListener('keydown', function(e){
            var open = document.querySelector('.cm-ov:not([hidden])');
            if (!open) { return; }
            if (e.key === 'Escape') { closeAll(); }
            if (open.id === 'cm-photos') {
                if (e.key === 'ArrowRight') { open.querySelector('[data-cm-next]').click(); }
                if (e.key === 'ArrowLeft')  { open.querySelector('[data-cm-prev]').click(); }
            }
        });
    })();
    </script>

    <?php // ⏳ ভর্তির কাউন্টডাউন — 🔴 ইচ্ছাকৃতভাবে উপরের বড় স্ক্রিপ্টের **বাইরে** আলাদা ব্লকে।
          // ওখানে কিছু ভাঙলে আইকন/মোবাইল মেনু/গ্যালারি সব একসাথে মরে (eduIcons() নিয়ম)।
          // ফ্ল্যাগটা render_countdown_html() তোলে — ঘড়ি না থাকলে স্ক্রিপ্টটাই যায় না। ?>
    <?php if (!empty($GLOBALS['edu_has_countdown'])): ?>
    <script>
    (function () {
        var els = document.querySelectorAll('.cd-chip[data-countdown]');
        if (!els.length) { return; }

        function pad(n) { return n < 10 ? '0' + n : '' + n; }

        function over(el) {
            el.classList.remove('cd-urgent');
            el.classList.add('cd-over');
            var lbl = el.querySelector('.cd-lbl');
            if (lbl) { lbl.textContent = 'এই ব্যাচে ভর্তির সময় শেষ'; }
            el.setAttribute('aria-label', 'এই ব্যাচে ভর্তির সময় শেষ');
            // ফর্মের পাতায় থাকলে সাবমিট বন্ধ। 🔴 এটা নিছক আগেভাগে জানানো —
            // আসল গার্ড সার্ভারে (course-register-submit.php), ব্রাউজারের ঘড়িতে ভরসা নেই।
            var forms = document.querySelectorAll('[data-countdown-lock]');
            for (var i = 0; i < forms.length; i++) {
                var btns = forms[i].querySelectorAll('button[type=submit], input[type=submit]');
                for (var j = 0; j < btns.length; j++) {
                    btns[j].disabled = true;
                    btns[j].style.opacity = '0.6';
                    if (btns[j].tagName === 'INPUT') { btns[j].value = 'ভর্তির সময় শেষ'; }
                    else { btns[j].textContent = 'ভর্তির সময় শেষ'; }
                }
            }
        }

        function paint(el, left) {
            var d = Math.floor(left / 86400),
                h = Math.floor((left % 86400) / 3600),
                m = Math.floor((left % 3600) / 60),
                s = left % 60;
            var vals = { d: '' + d, h: pad(h), m: pad(m), s: pad(s) };
            for (var k in vals) {
                var b = el.querySelector('[data-cd="' + k + '"]');
                if (b && b.textContent !== vals[k]) { b.textContent = vals[k]; }
            }
            // দিন ফুরিয়ে গেলে বাক্সটা সরে যায় (সার্ভারের রেন্ডারের সাথে একই নিয়ম)
            var dBox = el.querySelector('[data-cd-box="d"]');
            if (dBox) { dBox.classList.toggle('cd-off', d === 0); }
            if (left <= 86400) { el.classList.add('cd-urgent'); }
        }

        function tick() {
            var live = 0;
            for (var i = 0; i < els.length; i++) {
                var el = els[i], end = parseInt(el.getAttribute('data-countdown'), 10);
                if (!end) { continue; }
                var left = Math.floor((end - Date.now()) / 1000);
                if (left <= 0) {
                    if (!el.classList.contains('cd-over')) { over(el); }
                    continue;
                }
                live++;
                paint(el, left);
            }
            if (live) { setTimeout(tick, 1000); }
        }
        tick();
    })();
    </script>
    <?php endif; ?>
</body>
</html>
