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

<!-- পেজ-সাহায্য মডাল — হেডারের "?" বাটনে খোলে (page-help.php থেকে টেক্সট) -->
<div id="help-modal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-sm w-full p-6">
        <div class="flex items-start gap-3 mb-5">
            <div class="w-11 h-11 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
                <i data-lucide="help-circle" class="w-5 h-5 text-indigo-600"></i>
            </div>
            <div>
                <h3 id="help-modal-title" class="font-bold text-gray-900 mb-1"></h3>
                <p id="help-modal-text" class="text-sm text-gray-600 leading-relaxed"></p>
            </div>
        </div>
        <div class="flex gap-3 justify-end items-center">
            <a href="guide.php" class="text-sm font-semibold text-indigo-600">সব গাইড দেখুন →</a>
            <button type="button" id="help-modal-close" class="px-4 py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white">বুঝেছি</button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    // পেজ-সাহায্য মডাল ("?" বাটন) — data-attribute থেকে টেক্সট নিয়ে দেখায়
    function showPageHelp(btn) {
        var m = document.getElementById('help-modal');
        document.getElementById('help-modal-title').textContent = btn.getAttribute('data-help-title') || 'সাহায্য';
        document.getElementById('help-modal-text').textContent = btn.getAttribute('data-help-text') || '';
        m.classList.remove('hidden');
    }
    (function () {
        var m = document.getElementById('help-modal');
        if (!m) return;
        var close = function () { m.classList.add('hidden'); };
        document.getElementById('help-modal-close').addEventListener('click', close);
        m.addEventListener('click', function (e) { if (e.target === m) close(); });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') close(); });
    })();

    // সাইডবার সার্চ — টাইপ করলে সাইডবারের লিংক ফিল্টার হয় (ক্লায়েন্ট-সাইড, রিলোড ছাড়া)
    (function () {
        var input = document.getElementById('sidebar-search');
        var nav = document.getElementById('admin-nav');
        var empty = document.getElementById('sidebar-search-empty');
        if (!input || !nav) return;

        function filter() {
            var q = input.value.trim().toLowerCase();
            // ⚠️ প্রতিটা লিংক এখন একটা .nav-row-এর ভেতরে (তারা/তীর বোতামসহ) — তাই পুরো সারিটাই
            // লুকাতে হয়, শুধু <a> নয় (নাহলে খালি সারির ফাঁকা জায়গা থেকে যেত)
            var rows = nav.querySelectorAll('.nav-row');
            var anyVisible = false;
            rows.forEach(function (r) {
                var match = !q || r.textContent.toLowerCase().indexOf(q) !== -1;
                r.style.display = match ? '' : 'none';
                if (match && q) anyVisible = true;
            });
            // যে সেকশন-হেডারের নিচে দৃশ্যমান লিংক নেই সেটাও লুকানো (সার্চ চলাকালীন)
            nav.querySelectorAll('.nav-section').forEach(function (h) {
                var vis = false, el = h.nextElementSibling;
                while (el && !el.classList.contains('nav-section')) {
                    if (el.classList.contains('nav-row') && el.style.display !== 'none') { vis = true; break; }
                    el = el.nextElementSibling;
                }
                h.style.display = (!q || vis) ? '' : 'none';
            });
            if (empty) empty.classList.toggle('hidden', !(q && !anyVisible));
        }
        input.addEventListener('input', filter);
        input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; filter(); } });
    })();

    // ── ⭐ "সাজান" মোড: চালু থাকলে সাইডবারের তারা/তীর বোতাম দেখা যায়, বন্ধ থাকলে লক।
    // অবস্থা localStorage-এ (থিমের মতো) — কারণ ★ চাপলে পেজ রিলোড হয়, নাহলে প্রতিবার মোড বন্ধ হয়ে যেত।
    (function () {
        var nav = document.getElementById('admin-nav');
        var btn = document.getElementById('nav-arrange-btn');
        if (!nav || !btn) return;
        var on = false;
        try { on = localStorage.getItem('admin_nav_arrange') === '1'; } catch (e) {}
        function paint() { nav.classList.toggle('nav-arrange', on); btn.textContent = on ? '✓ সাজানো শেষ' : '⭐ সাজান'; }
        paint();
        btn.addEventListener('click', function () {
            on = !on;
            try { localStorage.setItem('admin_nav_arrange', on ? '1' : '0'); } catch (e) {}
            paint();
        });
    })();

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

    // ── সার্ভার-সাইড ফিল্টার ফর্মের সার্চ বক্স (registrations.php / users.php)
    // 🔴 মোবাইলে এটা ভাঙত (২০২৬-১০-০৩, ইউজারের রিপোর্ট): আগে যেকোনো ডিভাইসে ৫০০ms পরেই
    // ফুল GET রিলোড হতো, অথচ বাংলা ফোনেটিক কীবোর্ডে (Ridmik/Gboard) একটা যুক্তাক্ষর লিখতেই
    // তার চেয়ে বেশি সময় লাগে — মাঝপথে পাতা রিলোড হয়ে কীবোর্ড বন্ধ হয়ে যেত, আধখানা লেখা
    // আর কার্সর দুটোই হারাত। 🔴 মোবাইলে প্রোগ্রাম থেকে focus() দিলেও কীবোর্ড খোলে না
    // (ব্রাউজারের নিয়ম), তাই ওখানে অটো-রিলোডটাই বন্ধ — Enter/Go বা "🔍 খুঁজুন" বোতামই সাবমিট করে।
    // ডেস্কটপে আগের মতোই লেখা থামলে নিজে থেকে খোঁজে (ফোকাস ও কার্সর ফিরিয়ে দেওয়া হয়)।
    function adminAutoSearch(box, form) {
        if (!box || !form) { return; }

        var KEY = 'admin_autosearch_focus';
        var MARK = (form.id || '') + '|' + (box.name || 'q');
        var coarse = false;
        try { coarse = window.matchMedia('(hover: none) and (pointer: coarse)').matches; } catch (e) {}

        var applied = box.value;   // সার্ভার যে লেখাটা দিয়ে এই পাতাটা বানিয়েছে
        var timer = null;
        var composing = false;     // IME/ফোনেটিক কীবোর্ডে যুক্তাক্ষর লেখা চলছে কিনা

        function go() {
            if (composing) { return; }                 // লেখা শেষ হয়নি
            if (box.value === applied) { return; }     // একই লেখা — রিলোডের দরকার নেই
            try { sessionStorage.setItem(KEY, MARK); } catch (e) {}
            form.submit();
        }
        function restart() {
            clearTimeout(timer);
            timer = setTimeout(go, 700);
        }

        box.addEventListener('compositionstart', function () { composing = true; clearTimeout(timer); });
        box.addEventListener('compositionend', function () { composing = false; if (!coarse) { restart(); } });
        if (!coarse) {
            box.addEventListener('input', function () { if (!composing) { restart(); } });
        }
        // কীবোর্ডের Enter/Go — অপেক্ষা না করে এখনই খুঁজবে
        box.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(timer);
                composing = false;
                go();
            }
        });

        // রিলোডের পর কীবোর্ড/কার্সর ফিরিয়ে দেওয়া — শুধু ডেস্কটপে (মোবাইলে focus() পাতা লাফায়)
        try {
            if (sessionStorage.getItem(KEY) === MARK) {
                sessionStorage.removeItem(KEY);
                if (!coarse) {
                    box.focus();
                    var n = box.value.length;
                    if (box.setSelectionRange) { box.setSelectionRange(n, n); }
                }
            }
        } catch (e) {}
    }

    // যেসব সার্চ বক্সে data-autosearch="<ফর্মের id>" আছে, সেগুলো নিজে থেকেই ওয়্যার হয় —
    // পেজে আলাদা <script> লেখার দরকার নেই (আর ordering নিয়েও ঝামেলা হয় না)
    (function () {
        document.querySelectorAll('input[data-autosearch]').forEach(function (box) {
            adminAutoSearch(box, document.getElementById(box.getAttribute('data-autosearch')));
        });
    })();

    // ── 🔎 কোর্স → ব্যাচ পিকার (২০২৬-১০-০৪, ইউজারের চাওয়া: "আগে কোর্স তারপর ব্যাচ,
    //    আর লিখে সার্চ করে আনা যায়")। আয়/খরচ পাতার কোর্স-ব্যাচ বাছাইয়ে ব্যবহার হয়।
    //
    // 🔴 এটা **প্রগ্রেসিভ এনহ্যান্সমেন্ট** — আসল `<select name="item_batch">` ফর্মেই থেকে যায়
    //    (শুধু লুকানো হয়), আর পছন্দ করলে তার `value` বসিয়ে `change` ইভেন্ট ছোড়া হয়। ফলে
    //    (ক) JS ব্যর্থ হলে পুরনো ড্রপডাউনই কাজ করে, (খ) ফিল্টার ফর্মের `onchange="…submit()"`
    //    আগের মতোই চলে, (গ) সার্ভারের কোডে কিচ্ছু বদলাতে হয় না।
    // 🔴 ডেটাও ঐ select থেকেই পড়া হয় (`<optgroup label="কোর্স">` → `<option>` = ব্যাচ) —
    //    আলাদা JSON পাঠানো হয় না, তাই দুই জায়গায় তালিকা আলাদা হয়ে যাওয়ার ভয় নেই।
    function adminPicker(sel) {
        if (!sel || sel.getAttribute('data-fp-done')) { return; }

        var courses = [], byId = {};
        Array.prototype.forEach.call(sel.children, function (node) {
            if (node.tagName !== 'OPTGROUP') { return; }
            var c = { name: node.label, batches: [] };
            Array.prototype.forEach.call(node.children, function (o) {
                var b = { id: o.value, label: (o.textContent || '').trim(), course: c };
                c.batches.push(b);
                byId[o.value] = b;
            });
            if (c.batches.length) { courses.push(c); }
        });
        if (!courses.length) { return; }   // কিছু নেই — নেটিভ select-ই থাক
        sel.setAttribute('data-fp-done', '1');

        var wrap = document.createElement('div');
        wrap.className = 'fp-wrap';
        wrap.innerHTML =
            '<div class="fp-grid">'
          + '<div class="fp-field" data-fp="course"><span class="fp-cap">ধাপ ১ — কোর্স</span>'
          + '<input type="text" class="fp-input" placeholder="কোর্সের নাম লিখুন…" autocomplete="off">'
          + '<button type="button" class="fp-clear" hidden title="বাদ দিন">✕</button><div class="fp-list" hidden></div></div>'
          + '<div class="fp-field is-off" data-fp="batch"><span class="fp-cap">ধাপ ২ — ব্যাচ</span>'
          + '<input type="text" class="fp-input" placeholder="আগে কোর্স বাছুন" autocomplete="off" disabled>'
          + '<button type="button" class="fp-clear" hidden title="বাদ দিন">✕</button><div class="fp-list" hidden></div></div>'
          + '</div>';
        sel.parentNode.insertBefore(wrap, sel);
        wrap.appendChild(sel);
        sel.style.display = 'none';

        var cf = wrap.querySelector('[data-fp="course"]'), bf = wrap.querySelector('[data-fp="batch"]');
        var ci = cf.querySelector('.fp-input'),  bi = bf.querySelector('.fp-input');
        var cl = cf.querySelector('.fp-list'),   bl = bf.querySelector('.fp-list');
        var cx = cf.querySelector('.fp-clear'),  bx = bf.querySelector('.fp-clear');
        var curCourse = null;
        // 🔴🔴 তালিকা খোলা থাকলে কার্ডটা একটু উপরে তোলা হয় (`.fp-raise`) — কার্ডে মাউস গেলে
        //    hover-lift-এর `transform` কার্ডটাকে stacking context বানিয়ে ফেলে, তখন তালিকার
        //    `z-index: 40` কার্ডের ভেতরেই আটকে যায় আর নিচের ফিল্টার কার্ড তার উপরে এঁকে দেয়
        //    (খোলা ড্রপডাউনের মাঝখানে নিচের কার্ডের লেখা ভেসে উঠত — ২০২৬-১০-০৪, স্ক্রিনশটে ধরা)
        var card = wrap.closest('.bg-white');

        function norm(v) { return (v || '').toString().toLowerCase(); }
        function raise() { if (card) { card.classList.toggle('fp-raise', !cl.hidden || !bl.hidden); } }
        function close(list) { list.hidden = true; raise(); }
        function closeAll() { close(cl); close(bl); }

        function paint(list, items, onPick) {
            list.innerHTML = '';
            if (!items.length) {
                var e = document.createElement('div');
                e.className = 'fp-empty';
                e.textContent = 'কিছু পাওয়া যায়নি';
                list.appendChild(e);
                list.hidden = false;
                raise();
                return;
            }
            items.forEach(function (it, i) {
                var d = document.createElement('div');
                d.className = 'fp-opt' + (i === 0 ? ' on' : '');
                d.textContent = it.text;
                if (it.sub) {
                    var sm = document.createElement('small');
                    sm.textContent = it.sub;
                    d.appendChild(sm);
                }
                // 🔴 click নয়, mousedown — নাহলে ইনপুটের blur আগে চলে গিয়ে তালিকা বন্ধ হয়ে যেত
                d.addEventListener('mousedown', function (ev) { ev.preventDefault(); onPick(it); });
                list.appendChild(d);
            });
            list.hidden = false;
            raise();
        }

        function courseItems(q) {
            var out = [];
            courses.forEach(function (c) {
                if (norm(c.name).indexOf(norm(q)) < 0) { return; }
                out.push({
                    text: c.name,
                    sub: c.batches.length > 1 ? (c.batches.length + ' টি ব্যাচ') : c.batches[0].label,
                    course: c
                });
            });
            return out;
        }
        function batchItems(q) {
            if (!curCourse) { return []; }
            var out = [];
            curCourse.batches.forEach(function (b) {
                if (norm(b.label).indexOf(norm(q)) >= 0) { out.push({ text: b.label, batch: b }); }
            });
            return out;
        }

        // 🔴🔴 পাতার শুরুতে আগের বাছাই ফেরানোর সময় এটা `silent` ছাড়া ডাকবেন না —
        //    ফিল্টার select-এর `onchange` ফর্ম সাবমিট করে, তাই ওখানে change ছুড়লে
        //    পাতা রিলোড → আবার ফেরানো → আবার রিলোড = **অসীম লুপ**
        //    (টেস্টে হেডলেস ব্রাউজার ঝুলে গিয়ে এটা ধরা পড়েছিল)।
        function setBatch(b, silent) {
            bi.value = b.label;
            bf.classList.add('is-set');
            bx.hidden = false;
            close(bl);
            if (sel.value !== b.id) {
                sel.value = b.id;
                if (!silent) { sel.dispatchEvent(new Event('change', { bubbles: true })); }
            }
        }
        function clearBatch(fire) {
            bi.value = '';
            bf.classList.remove('is-set');
            bx.hidden = true;
            if (sel.value !== '') {
                sel.value = '';
                if (fire) { sel.dispatchEvent(new Event('change', { bubbles: true })); }
            }
        }
        function openCourse(c) {                   // শুধু দেখার অবস্থা — select-এর মান ছোঁয় না
            curCourse = c;
            ci.value = c.name;
            cf.classList.add('is-set');
            cx.hidden = false;
            bf.classList.remove('is-off');
            bi.disabled = false;
            bi.placeholder = 'ব্যাচ বাছুন…';
            close(cl);
        }
        function setCourse(c) {
            openCourse(c);
            clearBatch(false);
            if (c.batches.length === 1) {          // একটাই ব্যাচ — নিজে থেকেই বসে যাক
                setBatch(c.batches[0]);
            } else {
                bi.focus();
                paint(bl, batchItems(''), function (it) { setBatch(it.batch); });
            }
        }
        function clearCourse(fire) {
            curCourse = null;
            ci.value = '';
            cf.classList.remove('is-set');
            cx.hidden = true;
            bf.classList.add('is-off');
            bi.disabled = true;
            bi.placeholder = 'আগে কোর্স বাছুন';
            closeAll();
            clearBatch(fire);
        }

        ci.addEventListener('focus', function () { paint(cl, courseItems(ci.value === (curCourse ? curCourse.name : '') ? '' : ci.value), function (it) { setCourse(it.course); }); });
        ci.addEventListener('input', function () { paint(cl, courseItems(ci.value), function (it) { setCourse(it.course); }); });
        bi.addEventListener('focus', function () { paint(bl, batchItems(bi.value === (sel.value && byId[sel.value] ? byId[sel.value].label : '') ? '' : bi.value), function (it) { setBatch(it.batch); }); });
        bi.addEventListener('input', function () { paint(bl, batchItems(bi.value), function (it) { setBatch(it.batch); }); });

        cx.addEventListener('click', function () { clearCourse(true); });
        bx.addEventListener('click', function () { clearBatch(true); bi.focus(); });

        // কীবোর্ড — ↑↓ নড়াচড়া, Enter বাছাই, Esc বন্ধ
        function keys(input, list, build, pick) {
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') { close(list); return; }
                var opts = list.hidden ? [] : list.querySelectorAll('.fp-opt');
                if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    if (!opts.length) { paint(list, build(input.value), pick); return; }
                    e.preventDefault();
                    var at = -1;
                    for (var i = 0; i < opts.length; i++) { if (opts[i].classList.contains('on')) { at = i; } }
                    opts.forEach(function (o) { o.classList.remove('on'); });
                    at = e.key === 'ArrowDown' ? Math.min(at + 1, opts.length - 1) : Math.max(at - 1, 0);
                    opts[at].classList.add('on');
                    opts[at].scrollIntoView({ block: 'nearest' });
                } else if (e.key === 'Enter') {
                    if (opts.length) {
                        e.preventDefault();                       // ফর্ম সাবমিট নয়, শুধু বাছাই
                        var on = list.querySelector('.fp-opt.on') || opts[0];
                        on.dispatchEvent(new Event('mousedown'));
                    }
                }
            });
        }
        keys(ci, cl, courseItems, function (it) { setCourse(it.course); });
        keys(bi, bl, batchItems, function (it) { setBatch(it.batch); });

        // বাইরে ক্লিক করলে বন্ধ, আর লেখা অসম্পূর্ণ থাকলে আগের মানটাই ফিরিয়ে দেওয়া
        // (নাহলে ঘরে এমন লেখা পড়ে থাকত যেটা আসলে বাছাই হয়নি)
        document.addEventListener('mousedown', function (e) {
            if (wrap.contains(e.target)) { return; }
            closeAll();
            ci.value = curCourse ? curCourse.name : '';
            bi.value = (sel.value && byId[sel.value]) ? byId[sel.value].label : '';
        });

        // পাতা খোলার সময় আগে থেকে বাছাই থাকলে (যেমন ফিল্টার চালু) সেটা দেখানো।
        // 🔴 এখানে `sel.value` **একদম ছোঁয়া হয় না** — শুধু ঘর দুটো ভরা হয়, তাই কোনো change ইভেন্টও নেই।
        if (sel.value && byId[sel.value]) {
            var cur = byId[sel.value];
            openCourse(cur.course);
            bi.value = cur.label;
            bf.classList.add('is-set');
            bx.hidden = false;
        }
    }

    (function () {
        document.querySelectorAll('select[data-picker]').forEach(adminPicker);
    })();

    /* ✍️ কোর্স বাছলে ক্যাটেগরির ঘর নিজে থেকেই ভরে দেওয়া (২০২৬-১০-০৪, ইউজারের চাওয়া)
       ওয়্যারিং শুধু select-এ `data-fill-into="#<ঘরের id>"` + `data-fill-text="<লেখা>"` —
       পেজে আলাদা <script> লাগে না (`data-picker`/`data-autosearch`-এর মতোই)।

       🔴 অ্যাডমিনের নিজের লেখা কখনো মোছা হয় না — শুধু **খালি** ঘরেই বসে।
       🔴 আমরা বসিয়েছিলাম আর অ্যাডমিন হাত দেননি — এমন লেখাই কোর্স বাদ দিলে ফিরিয়ে নেওয়া হয়
          (`data-autofilled` মার্কার; অ্যাডমিন নিজে কিছু টাইপ করলেই মার্কারটা উঠে যায়)।
       ⚠️ পিকার `change` ছোড়ে বাছাই ও ✕ দুই ক্ষেত্রেই, কিন্তু পাতার শুরুতে আগের বাছাই
          ফেরানোর সময় **ছোড়ে না** (silent) — তাই রিলোডে ক্যাটেগরি নিজে থেকে বদলায় না। */
    (function () {
        document.querySelectorAll('select[data-fill-into]').forEach(function (sel) {
            const box = document.querySelector(sel.getAttribute('data-fill-into'));
            const text = sel.getAttribute('data-fill-text') || '';
            if (!box || !text) { return; }

            box.addEventListener('input', function () { delete box.dataset.autofilled; });

            sel.addEventListener('change', function () {
                if (sel.value) {
                    if (box.value.trim() === '') {
                        box.value = text;
                        box.dataset.autofilled = '1';
                    }
                } else if (box.dataset.autofilled === '1' && box.value === text) {
                    box.value = '';
                    delete box.dataset.autofilled;
                }
            });
        });
    })();

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
        if (document.querySelector('#regFilterForm, #courierFilterForm, #logsFilterForm, #ciFilterForm, #usersFilterForm, #lsFilterForm, #ureqFilterForm, #llFilterForm, #alFilterForm, #ifxFilterForm, #gmPickForm, #incFilterForm, #expFilterForm')) { return; }

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
