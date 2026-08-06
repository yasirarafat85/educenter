<?php
// "আসছে শীঘ্রই" / রেজিস্ট্রেশন বন্ধ কোর্সে অভিভাবক আগ্রহ জানিয়ে রাখার ছোট ফর্ম (ওয়েটিং লিস্ট)।
// course_id বাস্তবে course_batches.id বোঝায় (course-register.php-এর মতোই কনভেনশন)।

require_once __DIR__ . '/includes/functions.php';

$db = get_db();
$pageTitle = 'আগ্রহ জানিয়ে রাখুন';
$activePage = 'courses';

$courseId = (int) ($_GET['course_id'] ?? 0);
$selectedCourse = null;

if ($courseId > 0) {
    $stmt = $db->prepare(
        'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id AND cb.is_active = 1'
    );
    $stmt->execute(['id' => $courseId]);
    $selectedCourse = $stmt->fetch();
}

$old = $_SESSION['course_interest_form_old'] ?? [];
unset($_SESSION['course_interest_form_old']);

require __DIR__ . '/includes/site-header.php';

if (!$selectedCourse):
    // ------------------------------------------------------------
    // ধাপ ১ — কোর্স নির্বাচন (শুধু "আসছে শীঘ্রই" / রেজিস্ট্রেশন বন্ধ কোর্স)
    // ------------------------------------------------------------
    $courses = $db->query(
        'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id
         WHERE cb.is_active = 1 AND cb.registration_open = 0 ORDER BY cb.sort_order ASC, cb.id ASC'
    )->fetchAll();
?>
<div class="max-w-5xl mx-auto px-1 sm:px-0">
    <div class="text-center mb-8 sm:mb-12">
        <h1 class="text-2xl sm:text-4xl font-black mb-2 sm:mb-3 text-gray-800">🔜 আসছে শীঘ্রই</h1>
        <p class="text-sm sm:text-lg text-gray-600">যে কোর্সে আগ্রহী, সেটি বেছে নিন — নতুন ব্যাচ খুললে আমরা যোগাযোগ করব</p>
    </div>

    <?php if (!$courses): ?>
        <p class="text-center text-gray-500">এই মুহূর্তে "আসছে শীঘ্রই" কোনো কোর্স নেই।</p>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
        <?php foreach ($courses as $c): ?>
        <a href="course-interest?course_id=<?= $c['id'] ?>" class="colorful-card rounded-2xl shadow-lg overflow-hidden card-hover border border-white/30 block relative">
            <img src="<?= e($c['image'] ?: 'https://placehold.co/400x300') ?>" alt="<?= e($c['title']) ?>" class="w-full h-36 sm:h-40 object-cover">
            <div class="absolute top-3 left-3 bg-gray-800/80 text-white px-3 py-1 rounded-full font-semibold text-xs">রেজিস্ট্রেশন বন্ধ</div>
            <div class="p-4 sm:p-5">
                <h3 class="font-bold text-gray-900 text-base sm:text-lg mb-1"><?= e($c['title']) ?></h3>
                <p class="text-indigo-600 font-bold mb-3 text-sm sm:text-base"><?= e($c['price']) ?></p>
                <span class="block text-center py-2.5 rounded-xl font-semibold text-sm btn-primary text-white">জানিয়ে রাখুন →</span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php else:
    // ------------------------------------------------------------
    // ধাপ ২ — আগ্রহ-ফর্ম
    // ------------------------------------------------------------
    $ownerOld = $old['phone_owner'] ?? 'mother';
?>
<div class="max-w-xl mx-auto px-1 sm:px-0">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl p-5 sm:p-8 md:p-10 relative overflow-hidden" style="background: linear-gradient(150deg, rgb(var(--c-deep)) 0%, rgb(var(--c-primary)) 55%, rgb(var(--c-primary-2)) 100%);">
        <div class="absolute inset-0 bg-black/10 pointer-events-none"></div>
        <div class="relative z-10">
        <div class="text-center mb-6 sm:mb-8">
            <div class="inline-block bg-white/20 text-white text-xs font-bold px-3 py-1 rounded-full mb-3">🔜 আসছে শীঘ্রই</div>
            <h1 class="text-xl sm:text-3xl font-black text-white mb-2 leading-snug"><?= e($selectedCourse['title']) ?></h1>
            <p class="text-fuchsia-100 text-sm sm:text-base">এই কোর্সের রেজিস্ট্রেশন এখন বন্ধ — আগ্রহ জানিয়ে রাখুন, নতুন ব্যাচ খুললে আমরা যোগাযোগ করব।</p>
        </div>

        <?php
        $flash = get_flash();
        $justSubmitted = $flash && ($flash['type'] ?? '') === 'success';
        ?>

        <?php if ($justSubmitted): ?>
            <!-- আলাদা "ধন্যবাদ" ভিউ — সফল সাবমিটের পর ফর্মের বদলে এটাই দেখায় (ইউজারের ফিডব্যাক) -->
            <div class="bg-white/15 border border-white/30 rounded-2xl p-6 sm:p-8 text-center">
                <div class="inline-flex w-16 h-16 items-center justify-center rounded-full bg-white/25 mb-4">
                    <i data-lucide="check" class="w-8 h-8 text-white"></i>
                </div>
                <p class="text-white font-black text-xl mb-2">ধন্যবাদ!</p>
                <p class="text-fuchsia-100 text-sm sm:text-base mb-6"><?= e($flash['message']) ?></p>
                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="courses" class="bg-white/20 hover:bg-white/30 text-white px-5 py-3 rounded-xl font-semibold text-sm">← সব কোর্স দেখুন</a>
                    <a href="course-interest?course_id=<?= $selectedCourse['id'] ?>" class="bg-white/10 hover:bg-white/20 text-white px-5 py-3 rounded-xl font-semibold text-sm">আরেকজনের জন্য জানান</a>
                </div>
            </div>
        <?php elseif ($selectedCourse['registration_open']): ?>
            <div class="bg-white/15 border border-white/30 rounded-xl p-6 text-center">
                <p class="text-white font-bold text-lg mb-1">সুখবর! এই কোর্সের রেজিস্ট্রেশন এখন খোলা।</p>
                <p class="text-fuchsia-100 text-sm mb-4">আপনি সরাসরি রেজিস্ট্রেশন করতে পারেন।</p>
                <a href="course-register?course_id=<?= $selectedCourse['id'] ?>" class="inline-block bg-white/20 hover:bg-white/30 text-white px-5 py-2.5 rounded-xl font-semibold text-sm">রেজিস্ট্রেশন করুন →</a>
            </div>
        <?php else: ?>

        <?php if ($flash): // এরর হলে ফর্মের উপরে দেখাও ?>
            <div class="mb-5 p-4 rounded-xl text-sm sm:text-base bg-red-500/40 text-white"><?= e($flash['message']) ?></div>
        <?php endif; ?>

        <form method="post" action="course-interest-submit.php" class="space-y-4" id="course-interest-form">
            <?= csrf_field() ?>
            <?= spam_protection_fields() ?>
            <input type="hidden" name="course_id" value="<?= $selectedCourse['id'] ?>">

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="phone" class="w-4 h-4 text-emerald-300"></i></span> যোগাযোগ নাম্বার *
                </label>
                <input type="text" inputmode="numeric" id="contact_phone" name="contact_phone" required placeholder="01XXXXXXXXX"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['contact_phone'] ?? '') ?>">
                <p class="text-fuchsia-100 text-xs mt-1">আগে জানিয়ে রাখলে এই নাম্বারে তথ্য অটো লোড হবে</p>
            </div>

            <div>
                <label class="text-white font-semibold mb-1.5 text-sm block">নাম্বারটি কার?</label>
                <div class="grid grid-cols-2 gap-3" id="owner-group">
                    <label class="owner-opt flex items-center justify-center gap-2 cursor-pointer bg-white/15 border border-white/30 rounded-xl px-4 py-3 text-white font-semibold text-sm">
                        <input type="radio" name="phone_owner" value="mother" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;" <?= $ownerOld !== 'father' ? 'checked' : '' ?>> মা
                    </label>
                    <label class="owner-opt flex items-center justify-center gap-2 cursor-pointer bg-white/15 border border-white/30 rounded-xl px-4 py-3 text-white font-semibold text-sm">
                        <input type="radio" name="phone_owner" value="father" style="position:absolute;width:1px;height:1px;opacity:0;pointer-events:none;" <?= $ownerOld === 'father' ? 'checked' : '' ?>> বাবা
                    </label>
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="baby" class="w-4 h-4 text-sky-300"></i></span> শিশুর নাম *
                </label>
                <input type="text" id="child_name" name="child_name" required placeholder="শিশুর পূর্ণ নাম"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['child_name'] ?? '') ?>">
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-blue-300"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg></span> ফেসবুক আইডির নাম
                </label>
                <input type="text" id="facebook_name" name="facebook_name" placeholder="Facebook নাম"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['facebook_name'] ?? '') ?>">
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="message-circle" class="w-4 h-4 text-teal-300"></i></span> মন্তব্য (Remarks)
                </label>
                <textarea id="remarks" name="remarks" rows="2" placeholder="কোন বিশেষ কিছু জানাতে চাইলে লিখুন"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"><?= e($old['remarks'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="w-full py-3.5 sm:py-4 rounded-xl font-bold text-base sm:text-lg text-white shadow-lg active:scale-[0.98] transition-transform" style="background: linear-gradient(135deg, rgb(var(--c-primary-2)) 0%, rgb(var(--c-primary)) 100%); box-shadow: 0 10px 30px -8px rgb(var(--c-primary) / 0.6);">
                আগ্রহ জানিয়ে রাখুন
            </button>
        </form>
        <?php endif; ?>
        </div>
    </div>

    <div class="text-center mt-5">
        <a href="courses" class="text-gray-500 text-sm hover:text-gray-700">← সব কোর্স দেখুন</a>
    </div>
</div>

<script>
(function () {
    var phoneEl = document.getElementById('contact_phone');
    if (!phoneEl) return;

    // মা/বাবা রেডিও — নির্বাচিত অপশনটা হাইলাইট করা (শুধু বিদ্যমান Tailwind ক্লাস দিয়ে)
    var ownerGroup = document.getElementById('owner-group');
    if (ownerGroup) {
        var hi = ['ring-2', 'ring-white', 'bg-white/25'];
        function syncOwner() {
            Array.prototype.forEach.call(ownerGroup.querySelectorAll('.owner-opt'), function (lab) {
                var radio = lab.querySelector('input[type=radio]');
                if (radio && radio.checked) { lab.classList.add.apply(lab.classList, hi); }
                else { lab.classList.remove.apply(lab.classList, hi); }
            });
        }
        Array.prototype.forEach.call(ownerGroup.querySelectorAll('input[type=radio]'), function (r) {
            r.addEventListener('change', syncOwner);
        });
        syncOwner();
    }

    // অটো-ফিলের সময় ইউজার আগে থেকে কিছু টাইপ করে থাকলে সেটা যেন মুছে না যায়
    function setIfEmpty(id, val) {
        var el = document.getElementById(id);
        if (el && !el.value && val) el.value = val;
    }

    phoneEl.addEventListener('blur', function () {
        var phone = this.value.trim();
        if (!/^01[3-9][0-9]{8}$/.test(phone)) return;

        fetch('ajax-lookup-interest.php?phone=' + encodeURIComponent(phone))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.found) return;
                setIfEmpty('child_name', data.child_name);
                setIfEmpty('facebook_name', data.facebook_name);
                setIfEmpty('remarks', data.remarks);
                if (data.phone_owner === 'father') {
                    var f = document.querySelector('input[name="phone_owner"][value="father"]');
                    if (f) { f.checked = true; f.dispatchEvent(new Event('change')); }
                }
            })
            .catch(function () { /* নীরবে উপেক্ষা — ফর্ম পূরণে সমস্যা হবে না */ });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
