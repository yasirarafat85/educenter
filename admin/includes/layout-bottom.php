        </main>
    </div>
</div>

<!-- কাস্টম কনফার্মেশন মডাল — পুরো অ্যাডমিন প্যানেলে ব্রাউজারের ডিফল্ট confirm() এর বদলে ব্যবহার হয় -->
<div id="confirm-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
        <div class="flex items-start gap-3 mb-5">
            <div class="w-11 h-11 rounded-full bg-amber-100 flex items-center justify-center flex-shrink-0">
                <i data-lucide="alert-triangle" class="w-5 h-5 text-amber-600"></i>
            </div>
            <div>
                <h3 id="confirm-modal-title" class="font-bold text-gray-900 mb-1">নিশ্চিতকরণ</h3>
                <p id="confirm-modal-message" class="text-sm text-gray-600 leading-relaxed"></p>
            </div>
        </div>
        <div class="flex gap-3 justify-end">
            <button type="button" id="confirm-modal-cancel" class="px-4 py-2.5 rounded-xl text-sm font-semibold bg-gray-100 hover:bg-gray-200 text-gray-700">বাতিল</button>
            <button type="button" id="confirm-modal-ok" class="px-4 py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white">নিশ্চিত করুন</button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    // টোস্ট নোটিফিকেশন — ৪.৫ সেকেন্ড পর নিজে নিজে মিলিয়ে যায় (ফ্ল্যাশ মেসেজ এখন টোস্ট হিসেবে দেখায়)
    (function () {
        document.querySelectorAll('[data-toast]').forEach(function (t) {
            setTimeout(function () {
                t.classList.add('hide');
                setTimeout(function () { t.remove(); }, 320);
            }, 4500);
        });
    })();

    // থিম পিকার — রঙের বিন্দুতে ক্লিক করলে <html data-theme> বদলায় ও localStorage এ সেভ হয় (হেডে ইতিমধ্যে
    // সেভ করা থিম বসানো আছে flash এড়াতে, এখানে শুধু সক্রিয় বিন্দু হাইলাইট + ক্লিক হ্যান্ডলার যোগ করা হয়)
    // দুইটা পিকার থাকে — হেডারে (md+) আর সাইডবারে (মোবাইল), দুটোই [data-theme-picker] দিয়ে চিহ্নিত।
    // যেটাতেই ক্লিক হোক, দুটোরই সক্রিয় বিন্দু একসাথে আপডেট হয়।
    (function () {
        var pickers = document.querySelectorAll('[data-theme-picker]');
        if (!pickers.length) return;
        var current = 'indigo';
        try { current = localStorage.getItem('admin_theme') || 'indigo'; } catch (e) {}
        document.documentElement.setAttribute('data-theme', current);

        function highlight(id) {
            document.querySelectorAll('[data-theme-picker] .theme-dot').forEach(function (d) {
                d.classList.toggle('on', d.dataset.themeId === id);
            });
        }
        highlight(current);

        document.querySelectorAll('[data-theme-picker] .theme-dot').forEach(function (dot) {
            dot.addEventListener('click', function () {
                var id = this.dataset.themeId;
                document.documentElement.setAttribute('data-theme', id);
                try { localStorage.setItem('admin_theme', id); } catch (e) {}
                highlight(id);
            });
        });
    })();

    // কাস্টম মডাল দেখানো — Confirm চাপলে onConfirm কল হবে, Cancel চাপলে শুধু বন্ধ হয়ে যাবে
    function showConfirmModal(message, onConfirm, title) {
        const modal = document.getElementById('confirm-modal');
        const okBtn = document.getElementById('confirm-modal-ok');
        const cancelBtn = document.getElementById('confirm-modal-cancel');

        document.getElementById('confirm-modal-title').textContent = title || 'নিশ্চিতকরণ';
        document.getElementById('confirm-modal-message').textContent = message;
        modal.classList.remove('hidden');

        function cleanup() {
            modal.classList.add('hidden');
            okBtn.removeEventListener('click', onOk);
            cancelBtn.removeEventListener('click', onCancel);
            modal.removeEventListener('click', onBackdropClick);
        }
        function onOk() { cleanup(); onConfirm(); }
        function onCancel() { cleanup(); }
        function onBackdropClick(e) { if (e.target === modal) cleanup(); }

        okBtn.addEventListener('click', onOk);
        cancelBtn.addEventListener('click', onCancel);
        modal.addEventListener('click', onBackdropClick);
    }

    // যেকোনো ফর্মের onsubmit এ ব্যবহার করার জন্য — return confirmSubmit(this, 'বার্তা')
    function confirmSubmit(form, message, title) {
        showConfirmModal(message, function () { form.submit(); }, title);
        return false;
    }

    // টগল সুইচ (is_active/registration_open ইত্যাদি — warn_off => true মার্ক করা ফিল্ড) বন্ধ করলে একবার নিশ্চিতকরণ চাওয়া হয়
    function handleToggleWarn(checkbox) {
        if (checkbox.checked) return; // চালু করার সময় ওয়ার্নিং লাগবে না, শুধু বন্ধ করার সময়
        var label = checkbox.dataset.warnLabel || 'এই সেটিংস';
        checkbox.checked = true; // Confirm না করা পর্যন্ত আগের (চালু) অবস্থায় দেখাবে
        showConfirmModal('আপনি কি "' + label + '" বন্ধ করতে চান?', function () {
            checkbox.checked = false;
        }, 'নিশ্চিতকরণ');
    }

    // মোবাইলে সাইডবার খোলা/বন্ধ করা (hamburger মেনু)
    (function () {
        const sidebar = document.getElementById('admin-sidebar');
        const backdrop = document.getElementById('admin-sidebar-backdrop');
        const openBtn = document.getElementById('admin-sidebar-open');
        const closeBtn = document.getElementById('admin-sidebar-close');
        if (!sidebar || !openBtn) return;

        function openSidebar() {
            sidebar.classList.remove('-translate-x-full');
            backdrop.classList.remove('hidden');
        }
        function closeSidebar() {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
        }

        openBtn.addEventListener('click', openSidebar);
        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (backdrop) backdrop.addEventListener('click', closeSidebar);
    })();

    // মোবাইল কার্ড-লেআউট: প্রতিটা টেবিল-সেলে thead থেকে কলাম-নাম (data-label) বসায়, যাতে কার্ডে
    // প্রতিটা মানের পাশে কোন কলাম তা দেখা যায় (CSS ::before content: attr(data-label))। ডেস্কটপে অদৃশ্য।
    (function () {
        function labelize() {
            document.querySelectorAll('main .overflow-x-auto > table').forEach(function (t) {
                // মোবাইলে কার্ড-ইন-কার্ড এড়াতে মোড়কটাকে চিহ্নিত করা (CSS `:has()` না থাকা ব্রাউজারের জন্য)
                var wrap = t.parentNode;
                if (wrap && wrap.classList) { wrap.classList.add('table-wrap'); }
                var ths = Array.prototype.map.call(t.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
                if (!ths.length) return;
                t.querySelectorAll('tbody > tr').forEach(function (tr) {
                    var idx = 0;
                    Array.prototype.forEach.call(tr.children, function (cell) {
                        if (cell.tagName !== 'TD') return;
                        if (!cell.hasAttribute('colspan') && !cell.hasAttribute('data-label') && ths[idx]) {
                            cell.setAttribute('data-label', ths[idx]);
                        }
                        idx += (parseInt(cell.getAttribute('colspan'), 10) || 1);
                    });
                });
            });
        }
        labelize();
    })();

    // ── অটো লিস্ট-সার্চ (সব অ্যাডমিন পেজে, কোনো পেজে আলাদা কোড লাগে না) ──
    // যেকোনো লিস্টে ৫টার বেশি আইটেম থাকলে উপরে একটা সার্চ বক্স বসে যায়; টাইপ করলেই ফিল্টার হয়।
    // মোবাইলে কার্ড-লেআউটে অনেক আইটেমের মধ্যে স্ক্রল করে খোঁজার কষ্ট এড়াতে (ইউজারের চাওয়া)।
    // সম্পূর্ণ ক্লায়েন্ট-সাইড — এই কোডবেসে AJAX-ভিত্তিক পার্শিয়াল রিফ্রেশ নেই, আর লিস্টগুলো ছোট।
    // ⚠️ যেসব পেজে আগে থেকেই নিজস্ব সার্চ/ফিল্টার ফর্ম আছে (registrations/courier/shipment-logs —
    //    সেগুলো সার্ভার-সাইডে ফিল্টার+পেজিনেশন করে) সেখানে বসে না, নাহলে দুইটা সার্চ বক্স হতো।
    (function () {
        var MIN_ITEMS = 5;

        function makeSearch(anchorEl, items, placeholder) {
            var wrap = document.createElement('div');
            wrap.className = 'list-search-wrap mb-3';
            wrap.innerHTML = '<i data-lucide="search" class="w-4 h-4"></i>'
                + '<input type="search" class="list-search" autocomplete="off" aria-label="তালিকায় খুঁজুন" placeholder="' + placeholder + '">';
            var note = document.createElement('p');
            note.className = 'hidden text-center text-gray-400 text-sm py-6';
            note.textContent = 'এই লেখার সাথে মিলে এমন কিছু পাওয়া যায়নি।';

            anchorEl.parentNode.insertBefore(wrap, anchorEl);
            anchorEl.parentNode.insertBefore(note, anchorEl.nextSibling);

            var input = wrap.querySelector('input');
            function apply() {
                var q = input.value.trim().toLowerCase();
                var shown = 0;
                items.forEach(function (el) {
                    var hit = q === '' || (el.textContent || '').toLowerCase().indexOf(q) !== -1;
                    el.style.display = hit ? '' : 'none';
                    if (hit) { shown++; }
                });
                note.classList.toggle('hidden', shown !== 0);
            }
            input.addEventListener('input', apply);
            input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; apply(); } });
        }

        // ইতিমধ্যে নিজস্ব ফিল্টার-ফর্ম আছে এমন পেজ বাদ
        if (document.querySelector('#regFilterForm, #courierFilterForm, #logsFilterForm')) { return; }

        // ১) টেবিল-ভিত্তিক লিস্ট
        document.querySelectorAll('main .overflow-x-auto > table').forEach(function (t) {
            var rows = Array.prototype.filter.call(t.querySelectorAll('tbody > tr'), function (tr) {
                return !tr.querySelector('td[colspan]'); // "কোনো ডেটা নেই" রো বাদ
            });
            if (rows.length < MIN_ITEMS) { return; }
            makeSearch(t.closest('.overflow-x-auto'), rows, 'তালিকায় খুঁজুন — নাম লিখুন...');
        });

        // ২) কার্ড-গ্রিড লিস্ট (manage.php এর ছবিওয়ালা গ্রিড)
        var grid = document.querySelector('.list-grid');
        if (grid) {
            var cards = Array.prototype.slice.call(grid.querySelectorAll('.list-item'));
            if (cards.length >= MIN_ITEMS) {
                makeSearch(grid, cards, 'তালিকায় খুঁজুন — নাম লিখুন...');
            }
        }

        if (window.lucide && lucide.createIcons) { lucide.createIcons(); }
    })();
</script>
</body>
</html>
