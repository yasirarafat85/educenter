/*
 * 📅 জন্ম তারিখ — দিন / মাস / বছর আলাদা ড্রপডাউন (অডিট U4, ২০২৬-১১-১৪)
 *
 * ব্রাউজারের নিজের <input type="date"> ফোনে "mm/dd/yyyy" দেখায় আর বছর বদলাতে
 * অনেকবার পিছিয়ে যেতে হয় — অভিভাবকের জন্য কঠিন। এই স্ক্রিপ্ট
 * `input[type=date][data-dob-select]` খুঁজে তার পাশে তিনটা ড্রপডাউন বসায়।
 *
 * 🔴 প্রগ্রেসিভ এনহ্যান্সমেন্ট: আসল ঘরটা মোছা হয় না, শুধু `type=hidden` হয়ে যায় আর
 *    তার নাম/মান অপরিবর্তিত থাকে — তাই সার্ভারের কোড আগের মতোই `Y-m-d` পায়।
 *    JS না চললে আগের তারিখ-ঘরটাই দেখা যায়।
 * 🔴 অটো-ফিল কোড (ফোন দিয়ে আগের তথ্য আনা) সরাসরি `el.value = '2019-05-03'` লেখে —
 *    তাই ঐ ঘরের `value` সেটারে হুক বসানো, লিখলেই ড্রপডাউন নিজে থেকে মিলে যায়।
 *    অটো-ফিলের কোডে কিছু বদলাতে হয়নি।
 * 🔴 সংখ্যা English অঙ্কে (পাবলিক সাইটের নিয়ম)।
 */
(function () {
    var MONTHS = ['জানুয়ারি', 'ফেব্রুয়ারি', 'মার্চ', 'এপ্রিল', 'মে', 'জুন', 'জুলাই',
                  'আগস্ট', 'সেপ্টেম্বর', 'অক্টোবর', 'নভেম্বর', 'ডিসেম্বর'];
    var YEARS_BACK = 30;
    var proto = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function daysIn(y, m) { return new Date(y || 2000, m, 0).getDate(); } // m = 1..12

    function opt(sel, val, text) {
        var o = document.createElement('option');
        o.value = val; o.textContent = text;
        sel.appendChild(o);
    }

    function enhance(inp) {
        if (inp.getAttribute('data-dob-done')) return;
        inp.setAttribute('data-dob-done', '1');

        var required = inp.required;
        var cls = (inp.getAttribute('data-dob-class') || inp.className || '');
        var now = new Date();
        var maxY = now.getFullYear();

        var wrap = document.createElement('div');
        wrap.className = 'dob-sel';
        wrap.style.cssText = 'display:grid;grid-template-columns:1fr 1.5fr 1.2fr;gap:.5rem;min-width:0';

        function mk(label, ph) {
            var s = document.createElement('select');
            s.className = cls;
            s.style.minWidth = '0';
            s.style.paddingLeft = '.6rem';
            s.style.paddingRight = '.4rem';
            s.setAttribute('aria-label', label);
            if (required) s.required = true;
            opt(s, '', ph);
            wrap.appendChild(s);
            return s;
        }
        var sd = mk('দিন', 'দিন'), sm = mk('মাস', 'মাস'), sy = mk('বছর', 'বছর');
        for (var d = 1; d <= 31; d++) opt(sd, String(d), String(d));
        for (var m = 1; m <= 12; m++) opt(sm, String(m), MONTHS[m - 1]);
        for (var y = maxY; y >= maxY - YEARS_BACK; y--) opt(sy, String(y), String(y));

        // আসল ঘর → লুকানো (নাম ও মান অক্ষত)
        inp.type = 'hidden';
        inp.removeAttribute('required');
        inp.parentNode.insertBefore(wrap, inp);

        function writeHidden() {
            var D = +sd.value, M = +sm.value, Y = +sy.value;
            var msg = '';
            if (D && M && Y) {
                if (D > daysIn(Y, M)) {
                    msg = 'এই মাসে ' + daysIn(Y, M) + ' দিন — সঠিক তারিখ বাছুন';
                } else if (new Date(Y, M - 1, D) > now) {
                    msg = 'ভবিষ্যতের তারিখ দেওয়া যাবে না';
                }
                proto.set.call(inp, msg ? '' : (Y + '-' + pad(M) + '-' + pad(D)));
            } else {
                proto.set.call(inp, '');
                // ঐচ্ছিক ঘরে আংশিক বাছাই হলে থামাই — নাহলে নীরবে খালি চলে যেত
                if (!required && (D || M || Y)) msg = 'দিন, মাস ও বছর — তিনটাই বাছুন';
            }
            sd.setCustomValidity(msg);
        }

        function fromHidden() {
            var v = proto.get.call(inp) || '';
            var mm = /^(\d{4})-(\d{2})-(\d{2})/.exec(v);
            if (mm) {
                sy.value = String(+mm[1]);
                sm.value = String(+mm[2]);
                sd.value = String(+mm[3]);
                if (sy.value !== String(+mm[1])) { // তালিকার বাইরের বছর — যোগ করে নিই
                    opt(sy, String(+mm[1]), String(+mm[1]));
                    sy.value = String(+mm[1]);
                }
            } else {
                sd.value = ''; sm.value = ''; sy.value = '';
            }
            sd.setCustomValidity('');
        }

        // অটো-ফিল `el.value = …` লিখলে ড্রপডাউনও মিলে যায়
        try {
            Object.defineProperty(inp, 'value', {
                configurable: true,
                get: function () { return proto.get.call(this); },
                set: function (v) { proto.set.call(this, v); fromHidden(); }
            });
        } catch (e) { /* পুরনো ব্রাউজার — শুধু শুরুর মানটাই মিলবে */ }

        [sd, sm, sy].forEach(function (s) { s.addEventListener('change', writeHidden); });
        fromHidden();
    }

    function run() {
        var list = document.querySelectorAll('input[type=date][data-dob-select]');
        for (var i = 0; i < list.length; i++) enhance(list[i]);
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run);
    else run();
})();
