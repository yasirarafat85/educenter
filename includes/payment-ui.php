<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 💳 পেমেন্ট পাতার মার্কআপ — `pay.php` ও `payment.php` **দুটোই** এটাই ব্যবহার করে
 *
 * 🔴 আলাদা দুটো টেমপ্লেট বানানো হয়নি ইচ্ছাকৃতভাবে — তাহলে সময়ের সাথে দুই পাতায়
 *    দুই রকম ধাপ/লেখা হয়ে যেত (`group-match.php`-এর `$showSnap`-এর মতোই নীতি)।
 * 🔴 স্টাইল `.pay-*` **সাধারণ CSS-এ** (`assets/css/style.css`) — Tailwind রিবিল্ড লাগে না।
 * ════════════════════════════════════════════════════════════════════════════ */

// ফলাফলের বাক্স — তিন রকম অবস্থা, প্রতিটাতে কী করতে হবে বলা থাকে
function pay_result_box(?array $result, array $msgs, string $waUrl, int $claimId = 0): string
{
    if (!$result || empty($result['state'])) {
        return '';
    }
    $state = (string) $result['state'];
    $text  = (string) ($result['message'] ?? '');

    $skin = [
        'verified'  => ['#065f46', '#ecfdf5', '#a7f3d0'],
        'pending'   => ['#92400e', '#fffbeb', '#fde68a'],
        'already'   => ['#065f46', '#ecfdf5', '#a7f3d0'],
        'duplicate' => ['#991b1b', '#fef2f2', '#fecaca'],
        'notfound'  => ['#991b1b', '#fef2f2', '#fecaca'],
        'invalid'   => ['#991b1b', '#fef2f2', '#fecaca'],
        'blocked'   => ['#991b1b', '#fef2f2', '#fecaca'],
    ];
    [$fg, $bg, $bd] = $skin[$state] ?? $skin['pending'];

    $h  = '<div class="pay-res" style="color:' . $fg . ';background:' . $bg . ';border-color:' . $bd . ';"';
    // ⏳ অপেক্ষমাণ হলে পাতাটা নিজে থেকেই কয়েকবার দেখে নেয় — SMS কয়েক সেকেন্ড/মিনিট
    //    পরেও আসতে পারে, তখন অভিভাবককে আর কিছু করতে হয় না
    if ($state === 'pending' && $claimId > 0) {
        // 🔴 মিলে গেলে পাতাটা **রিলোড করা হয় না** — ফলাফলটা সেশনে ছিল, রিলোডে
        //    হারিয়ে যেত আর অভিভাবক খালি পাতা দেখতেন; বদলে বাক্সটাই জায়গায় বদলায়।
        $h .= ' data-pay-watch="' . $claimId . '" data-pay-key="' . e(pclaim_token($claimId)) . '"'
            . ' data-pay-ok="' . e((string) ($msgs['verified'] ?? '')) . '"'
            // ⏱️ সময় শেষেও না মিললে এই লেখাটাই বসে (ইউজারের চাওয়া — অনির্দিষ্ট
            //    "অপেক্ষা করুন" নয়, ৪৫ সেকেন্ডের মধ্যে স্পষ্ট উত্তর)
            . ' data-pay-wait="' . e((string) ($msgs['timeout'] ?? '')) . '"';
    }
    $h .= '>';
    $h .= '<p class="pay-res-t">' . e($text) . '</p>';

    // 🔴 সফল অবস্থায় (যাচাই হয়েছে / আগেই হয়েছে) WhatsApp বোতাম বা বাড়তি
    //    লেখা দেখানো হয় না — শুধু "মেলেনি" ধরনের অবস্থায়
    if ($state !== 'verified' && $state !== 'already') {
        if (($msgs['extra'] ?? '') !== '') {
            $h .= '<p class="pay-res-x">' . e((string) $msgs['extra']) . '</p>';
        }
        // 💬 "মিলছে না? সরাসরি জানান" — ইউজারের স্পষ্ট চাওয়া
        if ($waUrl !== '') {
            $h .= '<a href="' . e($waUrl) . '" target="_blank" rel="noopener" class="pay-wa">💬 WhatsApp-এ সরাসরি জানান</a>';
        }
    }
    $h .= '</div>';
    return $h;
}

/* ধাপ ১ → ২ → ৩ (এক পাতাতেই)।
 * $phone খালি হলে (আলাদা `payment` পাতা) নম্বরের ঘরটা অভিভাবক নিজে লেখেন;
 * রেজিস্ট্রেশন জানা থাকলে আগে থেকেই বসানো থাকে।
 */
function pay_steps_html(array $methods, string $phone = '', float $due = 0.0, bool $askPhone = false): string
{
    $h = '';

    // ── ধাপ ১ — কোথা থেকে পাঠাবেন ────────────────────────────────────────────
    $h .= '<p class="pay-step">ধাপ ১ — কোথা থেকে পাঠাবেন?</p>';
    $h .= '<div class="pay-chans">';
    foreach ($methods as $i => $m) {
        [$label, $color] = payment_channel_meta((string) $m['channel']);
        $h .= '<button type="button" class="pay-chan' . ($i === 0 ? ' is-on' : '') . '"'
            . ' data-pay-chan="' . (int) $m['id'] . '" data-chan-key="' . e((string) $m['channel']) . '"'
            . ' style="--pc:' . $color . ';">'
            . '<span class="pay-chan-dot"></span>' . e($label) . '</button>';
    }
    $h .= '</div>';
    $h .= '<input type="hidden" name="channel" id="payChannel" value="' . e((string) ($methods[0]['channel'] ?? '')) . '">';

    // ── ধাপ ২ — নম্বর / QR ────────────────────────────────────────────────────
    $h .= '<p class="pay-step">ধাপ ২ — এই নম্বরে <b>Send Money</b> করুন</p>';
    foreach ($methods as $i => $m) {
        [$label, $color] = payment_channel_meta((string) $m['channel']);
        $qr = trim((string) ($m['qr_image'] ?? ''));
        $h .= '<div class="pay-pane' . ($i === 0 ? ' is-on' : '') . '" data-pay-pane="' . (int) $m['id'] . '">';
        $h .= '<div class="pay-num"><span class="pay-num-v">' . e((string) $m['value']) . '</span>'
            . '<button type="button" class="pay-copy" data-copy="' . e((string) $m['value']) . '">📋 কপি</button></div>';
        if ($qr !== '') {
            $h .= '<div class="pay-qr"><img src="' . e($qr) . '" alt="' . e($label) . ' QR" loading="lazy"></div>';
        }
        if (trim((string) ($m['instruction'] ?? '')) !== '') {
            $h .= '<p class="pay-ins">' . e((string) $m['instruction']) . '</p>';
        }
        $h .= '<p class="pay-warn">⚠️ <b>Send Money</b> দিন — <b>Payment</b> নয়। রেফারেন্সে কিছু লিখতে হবে না।<br>'
            . '✅ <b>যেকোনো</b> বিকাশ/নগদ নম্বর থেকে পাঠাতে পারেন — বাবার, আত্মীয়ের বা দোকানের নম্বর হলেও চলবে।</p>';
        $h .= '</div>';
    }

    // ── ধাপ ৩ — TrxID ─────────────────────────────────────────────────────────
    $h .= '<p class="pay-step">ধাপ ৩ — টাকা পাঠানোর পর TrxID লিখুন</p>';
    $h .= '<div class="pay-fields">';

    if ($askPhone || $phone === '') {
        $h .= '<label class="pay-lab">যে নম্বরে রেজিস্ট্রেশন করেছিলেন'
            . '<input type="tel" name="phone" class="pay-in" inputmode="numeric" maxlength="14" placeholder="01XXXXXXXXX" required value="' . e($phone) . '"></label>';
    } else {
        $h .= '<input type="hidden" name="phone" value="' . e($phone) . '">';
    }

    $h .= '<label class="pay-lab">TrxID (লেনদেন নম্বর)'
        . '<input type="text" name="trxid" class="pay-in pay-trx" maxlength="40" placeholder="যেমন DJ21B6137N" required autocomplete="off"></label>';

    $amt = $due > 0.009 ? number_format($due, 2, '.', '') : '';
    $h .= '<label class="pay-lab">কত টাকা পাঠিয়েছেন'
        . '<input type="text" name="amount" class="pay-in" inputmode="decimal" maxlength="12" placeholder="যেমন 500" value="' . e($amt) . '"></label>';

    $h .= '</div>';
    $h .= '<p class="pay-hint">TrxID পাবেন bKash/নগদ থেকে আসা SMS-এ — ওখানে <b>TrxID</b> বা <b>TxnID</b> লেখার পরের অংশটুকু।</p>';
    $h .= '<button type="submit" class="pay-go">✅ যাচাই করুন</button>';

    return $h;
}

/* পাতার JS — 🔴 ইচ্ছাকৃতভাবে `site-footer.php`-এর বড় স্ক্রিপ্টের **বাইরে** আলাদা
 * ব্লকে: ওখানে কিছু ভাঙলে আইকন/মেনু/গ্যালারি সব একসাথে মরে (প্রজেক্টের নিয়ম)। */
function pay_scripts_html(): string
{
    return <<<'HTML'
<script>
(function () {
  var form = document.getElementById('payForm');

  // ── চ্যানেল বদল (bKash ↔ নগদ) ─────────────────────────────────────────────
  if (form) {
    var hidden = document.getElementById('payChannel');
    form.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-pay-chan]');
      if (!btn) { return; }
      var id = btn.getAttribute('data-pay-chan');
      form.querySelectorAll('[data-pay-chan]').forEach(function (b) { b.classList.toggle('is-on', b === btn); });
      form.querySelectorAll('[data-pay-pane]').forEach(function (p) {
        p.classList.toggle('is-on', p.getAttribute('data-pay-pane') === id);
      });
      if (hidden) { hidden.value = btn.getAttribute('data-chan-key') || ''; }
    });

    // ── নম্বর কপি ───────────────────────────────────────────────────────────
    form.addEventListener('click', function (e) {
      var b = e.target.closest('[data-copy]');
      if (!b) { return; }
      var txt = b.getAttribute('data-copy') || '';
      var done = function () { var o = b.textContent; b.textContent = '✓ কপি হয়েছে'; setTimeout(function () { b.textContent = o; }, 1600); };
      // 🔴 clipboard API শুধু HTTPS-এ চলে — ব্যর্থ হলে পুরনো পদ্ধতিতে ফলব্যাক
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(txt).then(done, function () { legacy(txt, done); });
      } else { legacy(txt, done); }
    });
    function legacy(txt, done) {
      try {
        var ta = document.createElement('textarea');
        ta.value = txt; ta.style.position = 'fixed'; ta.style.left = '-9999px';
        document.body.appendChild(ta); ta.select(); document.execCommand('copy');
        document.body.removeChild(ta); done();
      } catch (err) { /* কিছু না — নম্বরটা পর্দায় দেখাই যাচ্ছে */ }
    }

    // দুইবার সাবমিট আটকানো (একই TrxID দুইবার গেলে ডুপ্লিকেট বার্তা দেখাত)
    form.addEventListener('submit', function () {
      var go = form.querySelector('.pay-go');
      if (go) { go.disabled = true; go.textContent = 'যাচাই করা হচ্ছে…'; }
    });
  }

  // ── ⏳ অপেক্ষমাণ দাবি — পাতাটা নিজে থেকেই কয়েকবার দেখে নেয় ─────────────────
  // 🔴 সীমিত কয়েকবারই (অসীম পোলিং নয়), আর মিলে গেলে সাথে সাথে থেমে রিলোড
  var box = document.querySelector('[data-pay-watch]');
  if (box && window.fetch) {
    var id = box.getAttribute('data-pay-watch');
    var key = box.getAttribute('data-pay-key');
    // ⏱️ প্রথম দেখা ৪ সেকেন্ডে, তারপর প্রতি ৪ সেকেন্ডে — মোট ~৪৪ সেকেন্ড।
    // 🔴 সময়টা ইচ্ছাকৃত: অভিভাবককে অনির্দিষ্টকাল "অপেক্ষা করুন" দেখানো হবে না,
    //    ৪৫ সেকেন্ডের মধ্যেই স্পষ্ট উত্তর ও WhatsApp-এর পথ (ইউজারের চাওয়া)।
    var left = 11;
    var ok = function () {
      box.style.color = '#065f46'; box.style.background = '#ecfdf5'; box.style.borderColor = '#a7f3d0';
      var t = box.querySelector('.pay-res-t');
      if (t) { t.textContent = box.getAttribute('data-pay-ok') || 'পেমেন্ট যাচাই হয়েছে।'; }
      box.querySelectorAll('.pay-res-x, .pay-wa, .pay-why').forEach(function (el) { el.remove(); });
      box.removeAttribute('data-pay-watch');
    };
    // ⚠️ সময় শেষ — কী কী কারণে হতে পারে সেটা বলা হয়
    // 🔴 কোন কারণটা আসল সেটা **কখনো** বলা হয় না: তাহলে "এই TrxID আমাদের
    //    কাছে আছে কিনা" বাইরে থেকে জানা যেত (TrxID-অনুসন্ধানের দরজা)।
    var stop = function () {
      box.style.color = '#92400e'; box.style.background = '#fffbeb'; box.style.borderColor = '#fde68a';
      var t = box.querySelector('.pay-res-t');
      if (t) { t.textContent = box.getAttribute('data-pay-wait') || 'এখনো মেলানো যায়নি।'; }
      if (!box.querySelector('.pay-why')) {
        var ul = document.createElement('ul');
        ul.className = 'pay-why';
        ['TrxID-তে ইংরেজি I আর 1, অথবা O আর 0 গুলিয়ে যায়নি তো? আরেকবার মিলিয়ে দেখুন।',
         'Send Money করেছেন তো? (Payment বা Cash Out হলে মেলে না)',
         'বার্তাটা আমাদের কাছে আসতে কখনো কয়েক মিনিট দেরি হয় — তখন নিজে থেকেই মিলে যাবে।'
        ].forEach(function (x) { var li = document.createElement('li'); li.textContent = x; ul.appendChild(li); });
        var wa = box.querySelector('.pay-wa');
        if (wa) { box.insertBefore(ul, wa); } else { box.appendChild(ul); }
      }
      box.removeAttribute('data-pay-watch');
    };
    var tick = function () {
      if (left-- <= 0) { stop(); return; }
      fetch('pay-status.php?c=' + encodeURIComponent(id) + '&k=' + encodeURIComponent(key), { cache: 'no-store' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (j && j.ok && (j.state === 'verified' || j.state === 'posted')) { ok(); }
          else { setTimeout(tick, 4000); }
        })
        .catch(function () { setTimeout(tick, 4000); });
    };
    setTimeout(tick, 4000);
  }
})();
</script>
HTML;
}
