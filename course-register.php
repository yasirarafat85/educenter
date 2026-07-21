<?php
require_once __DIR__ . '/includes/functions.php';

$db = get_db();
$pageTitle = 'কোর্স রেজিস্ট্রেশন';
$activePage = 'courses';

$courseId = (int) ($_GET['course_id'] ?? 0);
$selectedCourse = null;

if ($courseId > 0) {
    // 'course_id' এখন বাস্তবে course_batches.id বোঝায় (এক্সটার্নাল প্যারামিটার নাম অপরিবর্তিত)
    $stmt = $db->prepare(
        'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.id = :id AND cb.is_active = 1'
    );
    $stmt->execute(['id' => $courseId]);
    $selectedCourse = $stmt->fetch();
}

$old = $_SESSION['course_register_form_old'] ?? [];
unset($_SESSION['course_register_form_old']);

require __DIR__ . '/includes/site-header.php';

if (!$selectedCourse):
    // ------------------------------------------------------------
    // ধাপ ১ — কোর্স নির্বাচন
    // ------------------------------------------------------------
    $courses = $db->query(
        'SELECT cb.*, c.title FROM course_batches cb JOIN courses c ON c.id = cb.course_id WHERE cb.is_active = 1 ORDER BY cb.sort_order ASC, cb.id ASC'
    )->fetchAll();
?>
<div class="max-w-5xl mx-auto px-1 sm:px-0">
    <div class="text-center mb-8 sm:mb-12">
        <h1 class="text-2xl sm:text-4xl font-black mb-2 sm:mb-3 text-gray-800">কোর্স রেজিস্ট্রেশন</h1>
        <p class="text-sm sm:text-lg text-gray-600">যে কোর্সে রেজিস্ট্রেশন করতে চান সেটি নির্বাচন করুন</p>
    </div>

    <?php if (!$courses): ?>
        <p class="text-center text-gray-500">এই মুহূর্তে কোনো কোর্স উপলব্ধ নেই।</p>
    <?php else: ?>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
        <?php foreach ($courses as $c): $courseClosed = !$c['registration_open']; ?>
        <a href="course-register?course_id=<?= $c['id'] ?>" class="colorful-card rounded-2xl shadow-lg overflow-hidden card-hover border border-white/30 block relative">
            <img src="<?= e($c['image'] ?: 'https://placehold.co/400x300') ?>" alt="<?= e($c['title']) ?>" class="w-full h-36 sm:h-40 object-cover">
            <?php if ($courseClosed): ?>
                <div class="absolute top-3 left-3 bg-gray-800/80 text-white px-3 py-1 rounded-full font-semibold text-xs">রেজিস্ট্রেশন বন্ধ</div>
            <?php endif; ?>
            <div class="p-4 sm:p-5">
                <h3 class="font-bold text-gray-900 text-base sm:text-lg mb-1"><?= e($c['title']) ?></h3>
                <p class="text-indigo-600 font-bold mb-3 text-sm sm:text-base"><?= e($c['price']) ?></p>
                <span class="block text-center py-2.5 rounded-xl font-semibold text-sm <?= $courseClosed ? 'bg-gray-200 text-gray-600' : 'btn-primary text-white' ?>"><?= $courseClosed ? 'রেজিস্ট্রেশন বন্ধ' : 'নির্বাচন করুন →' ?></span>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php else:
    // ------------------------------------------------------------
    // ধাপ ২ — মূল রেজিস্ট্রেশন ফর্ম
    // ------------------------------------------------------------
?>
<div class="max-w-2xl mx-auto px-1 sm:px-0">
    <div class="rounded-2xl sm:rounded-3xl shadow-2xl p-5 sm:p-8 md:p-10 relative overflow-hidden" style="background: linear-gradient(150deg, rgb(var(--c-deep)) 0%, rgb(var(--c-primary)) 55%, rgb(var(--c-primary-2)) 100%);">
        <div class="absolute inset-0 bg-black/10 pointer-events-none"></div>
        <div class="relative z-10">
        <div class="text-center mb-6 sm:mb-8">
            <h1 class="text-xl sm:text-3xl font-black text-white mb-2 leading-snug"><?= e($selectedCourse['title']) ?></h1>
            <p class="text-fuchsia-100 text-sm sm:text-base">আপনার সন্তানের উজ্জ্বল ভবিষ্যতের জন্য রেজিস্ট্রেশন করুন</p>
            <a href="course-register" class="inline-block mt-2 text-fuchsia-100 text-xs sm:text-sm underline">অন্য কোর্স বেছে নিন</a>
        </div>

        <?php $flash = get_flash(); if ($flash): ?>
            <div class="mb-5 p-4 rounded-xl text-sm sm:text-base <?= $flash['type'] === 'error' ? 'bg-red-500/40 text-white' : 'bg-green-500/40 text-white' ?>">
                <?= e($flash['message']) ?>
            </div>
        <?php endif; ?>

        <?php if (!$selectedCourse['registration_open']): ?>
            <div class="bg-white/15 border border-white/30 rounded-xl p-6 text-center">
                <i data-lucide="lock" class="w-10 h-10 text-white mx-auto mb-3"></i>
                <p class="text-white font-bold text-lg mb-1">এই ব্যাচের রেজিস্ট্রেশন বর্তমানে বন্ধ</p>
                <p class="text-fuchsia-100 text-sm">নতুন ব্যাচ খোলা হলে জানিয়ে দেওয়া হবে। ততক্ষণে অন্য কোর্স দেখতে পারেন।</p>
                <a href="course-register" class="inline-block mt-4 bg-white/20 hover:bg-white/30 text-white px-5 py-2.5 rounded-xl font-semibold text-sm">অন্য কোর্স বেছে নিন</a>
            </div>
        <?php else: ?>

        <!-- একাধিক শিশুর তথ্য পাওয়া গেলে এখানে সিলেক্টর দেখাবে (JS দিয়ে) -->
        <div id="children-picker" class="hidden mb-5 bg-white/15 border border-white/30 rounded-xl p-4">
            <p class="text-white font-semibold text-sm mb-3">এই নম্বরে আগে নিবন্ধিত শিশুরা — কার জন্য রেজিস্ট্রেশন করছেন?</p>
            <div id="children-chips" class="flex flex-wrap gap-2"></div>
        </div>

        <form method="post" action="course-register-submit.php" class="space-y-4" id="course-register-form">
            <?= csrf_field() ?>
            <?= spam_protection_fields() ?>
            <input type="hidden" name="course_id" value="<?= $selectedCourse['id'] ?>">

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="phone" class="w-4 h-4 text-emerald-300"></i></span> মোবাইল নাম্বার (মা) *
                </label>
                <input type="text" inputmode="numeric" id="mother_mobile" name="mother_mobile" required placeholder="01XXXXXXXXX"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['mother_mobile'] ?? '') ?>">
                <p class="text-fuchsia-100 text-xs mt-1">আগের তথ্য থাকলে অটো লোড হবে</p>
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="baby" class="w-4 h-4 text-sky-300"></i></span> শিশুর নাম *
                </label>
                <input type="text" id="child_name" name="child_name" required placeholder="শিশুর পূর্ণ নাম"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['child_name'] ?? '') ?>">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="calendar" class="w-4 h-4 text-amber-300"></i></span> জন্ম তারিখ *
                    </label>
                    <input type="date" id="date_of_birth" name="date_of_birth" required
                        class="w-full bg-white/15 text-white border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                        value="<?= e($old['date_of_birth'] ?? '') ?>">
                </div>
                <div>
                    <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><svg viewBox="0 0 24 24" fill="currentColor" class="w-4 h-4 text-blue-300"><path d="M22 12c0-5.523-4.477-10-10-10S2 6.477 2 12c0 4.991 3.657 9.128 8.438 9.878v-6.987h-2.54V12h2.54V9.797c0-2.506 1.492-3.89 3.777-3.89 1.094 0 2.238.195 2.238.195v2.46h-1.26c-1.243 0-1.63.771-1.63 1.562V12h2.773l-.443 2.89h-2.33v6.988C18.343 21.128 22 16.991 22 12z"/></svg></span> ফেসবুক আইডি নাম *
                    </label>
                    <input type="text" id="facebook_id" name="facebook_id" required placeholder="Facebook নাম"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                        value="<?= e($old['facebook_id'] ?? '') ?>">
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="phone" class="w-4 h-4 text-violet-300"></i></span> মোবাইল নাম্বার (বাবা)
                </label>
                <input type="text" inputmode="numeric" id="father_mobile" name="father_mobile" placeholder="01XXXXXXXXX"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                    value="<?= e($old['father_mobile'] ?? '') ?>">
            </div>

            <?php if (!$selectedCourse['hide_parcel']): ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="user" class="w-4 h-4 text-slate-200"></i></span> রিসিভার নাম *
                    </label>
                    <input type="text" id="receiver_name" name="receiver_name" required placeholder="পার্সেল গ্রহণকারী"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                        value="<?= e($old['receiver_name'] ?? '') ?>">
                </div>
                <div>
                    <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                        <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="phone-call" class="w-4 h-4 text-pink-300"></i></span> রিসিভার নাম্বার *
                    </label>
                    <input type="text" inputmode="numeric" id="receiver_phone" name="receiver_phone" required placeholder="01XXXXXXXXX"
                        class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"
                        value="<?= e($old['receiver_phone'] ?? '') ?>">
                </div>
            </div>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="map-pin" class="w-4 h-4 text-rose-300"></i></span> ঠিকানা *
                </label>
                <textarea id="address" name="address" required rows="2" placeholder="সম্পূর্ণ ঠিকানা লিখুন (বাড়ি, রোড, এলাকা, জেলা)"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"><?= e($old['address'] ?? '') ?></textarea>
            </div>
            <?php endif; ?>

            <div>
                <label class="flex items-center gap-2 text-white font-semibold mb-1.5 text-sm">
                    <span class="inline-flex w-8 h-8 items-center justify-center rounded-full bg-white/20"><i data-lucide="message-circle" class="w-4 h-4 text-teal-300"></i></span> বিশেষ মন্তব্য
                </label>
                <textarea name="notes" rows="2" placeholder="কোন বিশেষ মন্তব্য থাকলে লিখুন"
                    class="w-full bg-white/15 text-white placeholder-white/60 border border-white/30 rounded-xl px-4 py-3 text-base focus:bg-white/25 focus:ring-2 focus:ring-fuchsia-300 outline-none"><?= e($old['notes'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="w-full py-3.5 sm:py-4 rounded-xl font-bold text-base sm:text-lg text-white shadow-lg active:scale-[0.98] transition-transform" style="background: linear-gradient(135deg, rgb(var(--c-primary-2)) 0%, rgb(var(--c-primary)) 100%); box-shadow: 0 10px 30px -8px rgb(var(--c-primary) / 0.6);">
                রেজিস্ট্রেশন সম্পন্ন করুন
            </button>
        </form>
        <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var motherMobile = document.getElementById('mother_mobile');
    if (!motherMobile) return;

    var familyFieldMap = { facebook_id: 'facebook_id', father_mobile: 'father_mobile', receiver_name: 'receiver_name', receiver_phone: 'receiver_phone', address: 'address' };

    // অটো-ফিলের সময় ইউজার আগে থেকে কিছু টাইপ করে থাকলে সেটা যেন মুছে না যায়
    function setIfEmpty(id, val) {
        var el = document.getElementById(id);
        if (el && !el.value && val) el.value = val;
    }

    // ইউজার নিজে থেকে একটা শিশু/অপশন বেছে নিলে জোর করেই বসানো হয় (ইচ্ছাকৃত অ্যাকশন)
    function setForce(id, val) {
        var el = document.getElementById(id);
        if (el && val) el.value = val;
    }

    function clearChildFields() {
        ['child_name', 'date_of_birth'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = '';
        });
    }

    function fillFamily(family, force) {
        if (!family) return;
        var setter = force ? setForce : setIfEmpty;
        Object.keys(familyFieldMap).forEach(function (key) {
            setter(familyFieldMap[key], family[key]);
        });
    }

    function fillChild(child, force) {
        var setter = force ? setForce : setIfEmpty;
        setter('child_name', child.child_name);
        setter('date_of_birth', child.date_of_birth);
    }

    function renderChildPicker(children, family) {
        var picker = document.getElementById('children-picker');
        var chipsWrap = document.getElementById('children-chips');
        chipsWrap.innerHTML = '';

        function highlightChip(activeChip) {
            Array.prototype.forEach.call(chipsWrap.children, function (c) {
                c.classList.remove('ring-2', 'ring-white');
            });
            activeChip.classList.add('ring-2', 'ring-white');
        }

        children.forEach(function (child) {
            var chip = document.createElement('button');
            chip.type = 'button';
            chip.textContent = child.child_name;
            chip.className = 'px-4 py-2 rounded-full text-sm font-semibold bg-white/25 text-white hover:bg-white/40 transition-colors';
            chip.addEventListener('click', function () {
                fillChild(child, true);
                fillFamily(family, true);
                highlightChip(chip);
            });
            chipsWrap.appendChild(chip);
        });

        var newChip = document.createElement('button');
        newChip.type = 'button';
        newChip.textContent = '➕ নতুন শিশু';
        newChip.className = 'px-4 py-2 rounded-full text-sm font-semibold bg-pink-400/40 text-white hover:bg-pink-400/60 transition-colors';
        newChip.addEventListener('click', function () {
            clearChildFields();
            fillFamily(family, true);
            highlightChip(newChip);
            document.getElementById('child_name').focus();
        });
        chipsWrap.appendChild(newChip);

        picker.classList.remove('hidden');
    }

    motherMobile.addEventListener('blur', function () {
        var phone = this.value.trim();
        if (!/^01[3-9][0-9]{8}$/.test(phone)) return;

        fetch('ajax-lookup-registration.php?phone=' + encodeURIComponent(phone))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.found) return;

                if (data.children && data.children.length > 1) {
                    // একাধিক শিশু — কোনটা নিজে থেকে না বসিয়ে ইউজারকে বেছে নিতে দেওয়া হচ্ছে
                    renderChildPicker(data.children, data.family);
                    fillFamily(data.family, false);
                } else if (data.children && data.children.length === 1) {
                    fillChild(data.children[0], false);
                    fillFamily(data.family, false);
                }
            })
            .catch(function () { /* নীরবে উপেক্ষা করা হলো, ফর্ম পূরণে সমস্যা হবে না */ });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/site-footer.php'; ?>
