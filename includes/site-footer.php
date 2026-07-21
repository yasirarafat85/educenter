        </div>
    </main>

    <footer class="bg-gray-900 text-white py-12 mt-12 relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-r from-gray-800 via-gray-900 to-slate-800 opacity-95"></div>
        <div class="container mx-auto px-4 relative z-10">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8 mb-8">
                <div>
                    <div class="flex items-center space-x-3 mb-6">
                        <img src="<?= e($logoPath) ?>" alt="<?= e($siteName) ?> Logo" class="w-10 h-10 rounded-lg shadow-lg object-cover">
                        <div>
                            <h3 class="text-xl font-bold"><?= e($siteName) ?></h3>
                            <p class="text-sm text-gray-400"><?= e($siteTagline) ?></p>
                        </div>
                    </div>
                    <p class="text-gray-300 leading-relaxed">গুণগত শিক্ষার মাধ্যমে উন্নত ভবিষ্যৎ গড়ি। আমাদের লক্ষ্য প্রতিটি শিক্ষার্থীর সম্ভাবনা বিকশিত করা।</p>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-lg">কুইক লিংক</h4>
                    <ul class="space-y-3 text-gray-300">
                        <li><a href="courses" class="inline-block py-1 hover:text-white transition-colors">📚 কোর্স সমূহ</a></li>
                        <li><a href="worksheets" class="inline-block py-1 hover:text-white transition-colors">📝 ওয়ার্কশিট</a></li>
                        <li><a href="products" class="inline-block py-1 hover:text-white transition-colors">🛍️ প্রোডাক্ট</a></li>
                        <li><a href="about" class="inline-block py-1 hover:text-white transition-colors">ℹ️ আমাদের সম্পর্কে</a></li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-lg">যোগাযোগ</h4>
                    <ul class="space-y-3 text-gray-300">
                        <li class="flex items-center">
                            <i data-lucide="map-pin" class="w-4 h-4 mr-2"></i>
                            <?= e(get_setting('contact_address')) ?>
                        </li>
                        <li class="flex items-center">
                            <i data-lucide="phone" class="w-4 h-4 mr-2"></i>
                            <?= e(get_setting('contact_phone')) ?>
                        </li>
                        <li class="flex items-center">
                            <i data-lucide="mail" class="w-4 h-4 mr-2"></i>
                            <?= e(get_setting('contact_email')) ?>
                        </li>
                    </ul>
                </div>

                <div>
                    <h4 class="font-bold mb-4 text-lg">সোশ্যাল মিডিয়া</h4>
                    <div class="flex flex-wrap gap-3">
                        <?php
                        // এই আইকনগুলো Lucide দিয়ে না — Lucide এ ব্র্যান্ড লোগো নাও থাকতে পারে (তাই আগে খালি সার্কেল দেখাচ্ছিল)।
                        // নিজস্ব inline SVG ব্যবহার করা হচ্ছে, লাইব্রেরির ভার্সনের উপর নির্ভরতা এড়াতে।
                        $socialIcons = [
                            'social_facebook' => '<path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/>',
                            'social_twitter' => '<path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>',
                            'social_youtube' => '<path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12z"/>',
                            'social_instagram' => '<path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838a6.163 6.163 0 1 0 0 12.326 6.163 6.163 0 0 0 0-12.326zm0 10.162a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm6.406-11.845a1.44 1.44 0 1 0 0 2.881 1.44 1.44 0 0 0 0-2.881z"/>',
                        ];
                        $socialColors = [
                            'social_facebook' => 'bg-blue-600 hover:bg-blue-700',
                            'social_twitter' => 'bg-gray-700 hover:bg-gray-800',
                            'social_youtube' => 'bg-red-600 hover:bg-red-700',
                            'social_instagram' => 'bg-pink-600 hover:bg-pink-700',
                        ];
                        foreach ($socialIcons as $key => $svgPath):
                            $url = get_setting($key);
                            if ($url === '') continue;
                        ?>
                            <a href="<?= e($url) ?>" target="_blank" rel="noopener" class="<?= $socialColors[$key] ?> p-3 rounded-xl transition-all hover:scale-110 shadow-lg">
                                <svg viewBox="0 0 24 24" fill="currentColor" class="w-5 h-5"><?= $svgPath ?></svg>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="border-t border-gray-700 pt-8 text-center">
                <p class="text-gray-400"><?= get_setting('footer_text', '&copy; 2025 EduCenter. সকল অধিকার সংরক্ষিত।') ?></p>
            </div>
        </div>
    </footer>

    <?php
    // ── নিচের স্টিকি নেভিগেশন বার (শুধু মোবাইলে) — অ্যাপের মতো, সবচেয়ে দরকারি ৪টা লিংক হাতের নাগালে ──
    // ডেস্কটপে (lg+) লুকানো, কারণ সেখানে উপরে পুরো মেনু আছে। active পেজ হাইলাইট হয়।
    $bottomNav = [
        ['label' => 'হোম',      'icon' => 'home',        'url' => './',        'id' => 'home'],
        ['label' => 'কোর্স',     'icon' => 'book-open',   'url' => 'courses',   'id' => 'courses'],
        ['label' => 'ওয়ার্কশিট', 'icon' => 'file-text',   'url' => 'worksheets','id' => 'worksheets'],
        ['label' => 'যোগাযোগ',   'icon' => 'phone',       'url' => 'about',     'id' => 'about'],
    ];
    ?>
    <nav id="bottom-nav" class="lg:hidden" aria-label="দ্রুত নেভিগেশন">
        <?php foreach ($bottomNav as $bn): ?>
            <a href="<?= e($bn['url']) ?>" class="bnav-item<?= ($activePage ?? '') === $bn['id'] ? ' bnav-on' : '' ?>">
                <i data-lucide="<?= e($bn['icon']) ?>" class="w-5 h-5"></i>
                <span><?= e($bn['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php // ── ভাসমান WhatsApp বাটন — অ্যাডমিন contact_whatsapp সেট করলে দেখাবে (এক ট্যাপে চ্যাট) ──
    $waNum = normalize_bd_whatsapp(get_setting('contact_whatsapp'));
    if ($waNum !== ''):
    ?>
    <a href="https://wa.me/<?= e($waNum) ?>" target="_blank" rel="noopener noreferrer" id="wa-float" aria-label="WhatsApp-এ যোগাযোগ" title="WhatsApp-এ যোগাযোগ">
        <svg viewBox="0 0 24 24" fill="currentColor" class="w-7 h-7"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.885-9.885 9.885M20.52 3.449C18.24 1.245 15.24.044 12.045.044 5.463.044.104 5.402.101 11.986c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652a11.94 11.94 0 005.71 1.454h.005c6.581 0 11.945-5.359 11.949-11.945a11.9 11.9 0 00-3.484-8.442"/></svg>
    </a>
    <?php endif; ?>

    <?php // "উপরে যান" বাটন — নিচে স্ক্রল করলে দেখা যায়, ক্লিকে উপরে নিয়ে যায় ?>
    <button type="button" id="back-to-top" aria-label="উপরে যান" title="উপরে যান">
        <i data-lucide="arrow-up" class="w-5 h-5"></i>
    </button>

    <script>
        lucide.createIcons();

        // ── উপরে যাওয়ার বাটন ──
        (function () {
            var btn = document.getElementById('back-to-top');
            if (!btn) { return; }
            // ৩০০px এর বেশি নিচে নামলে দেখাও (rAF দিয়ে scroll ইভেন্ট থ্রটল করা — মোবাইলে স্মুথ থাকে)
            var ticking = false;
            function update() {
                btn.classList.toggle('show', window.scrollY > 300);
                ticking = false;
            }
            window.addEventListener('scroll', function () {
                if (!ticking) { window.requestAnimationFrame(update); ticking = true; }
            }, { passive: true });
            update();

            btn.addEventListener('click', function () {
                // prefers-reduced-motion সম্মান করা (CLAUDE.md এর মোবাইল/অ্যাক্সেসিবিলিটি নিয়ম)
                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                window.scrollTo({ top: 0, behavior: reduce ? 'auto' : 'smooth' });
            });
        })();

        const mobileBtn = document.getElementById('mobile-menu-btn');
        const mobileNav = document.getElementById('mobile-nav');
        if (mobileBtn && mobileNav) {
            mobileBtn.addEventListener('click', () => {
                const isHidden = mobileNav.classList.contains('hidden');
                mobileNav.classList.toggle('hidden');
                mobileBtn.innerHTML = isHidden
                    ? '<i data-lucide="x" class="w-6 h-6 text-gray-700"></i>'
                    : '<i data-lucide="menu" class="w-6 h-6 text-gray-700"></i>';
                lucide.createIcons();
            });
        }
    </script>
</body>
</html>
