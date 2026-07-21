<?php
require_once __DIR__ . '/includes/functions.php';

$success = $_SESSION['registration_success'] ?? null;
unset($_SESSION['registration_success']);

if (!$success) {
    redirect('index.php');
}

$isCourse = $success['type'] === 'course';
$pageTitle = 'ধন্যবাদ';
$activePage = '';

require __DIR__ . '/includes/site-header.php';
?>

<div class="max-w-xl mx-auto">
    <div id="confirmation-card" class="bg-white rounded-2xl shadow-lg p-8 sm:p-10 text-center overflow-hidden relative" style="border:2px solid rgb(var(--c-border));">
        <!-- উপরে থিম-রঙের গ্রেডিয়েন্ট স্ট্রাইপ -->
        <div class="absolute top-0 left-0 right-0 h-1.5" style="background:linear-gradient(90deg, rgb(var(--c-deep)), rgb(var(--c-primary)), rgb(var(--c-primary-2)));"></div>
        <div class="w-20 h-20 mx-auto mb-5 rounded-full flex items-center justify-center" style="background:rgb(var(--c-tint));">
            <svg viewBox="0 0 24 24" fill="none" stroke="rgb(var(--c-primary))" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" class="w-10 h-10"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h1 class="text-2xl sm:text-3xl font-black text-gray-900 mb-3">ধন্যবাদ, <?= $isCourse ? 'রেজিস্ট্রেশন' : 'অর্ডার' ?> সফল হয়েছে! 🎉</h1>
        <p class="text-gray-500 mb-2">আপনার <?= $isCourse ? 'রেজিস্ট্রেশন' : 'অর্ডার' ?> সফলভাবে গ্রহণ করা হয়েছে</p>
        <p class="text-lg font-black mb-6" style="color:rgb(var(--c-primary));"><?= e($success['item_title']) ?></p>
        <div class="rounded-xl p-4 mb-6" style="background:rgb(var(--c-tint));border:1px solid rgb(var(--c-border));">
            <p class="text-xs text-gray-500 uppercase tracking-wide">রেফারেন্স নম্বর</p>
            <p class="text-2xl font-black text-gray-800">#<?= (int) $success['ref'] ?></p>
        </div>
        <p class="text-gray-600 text-sm">📞 আমাদের টিম শীঘ্রই আপনার দেওয়া মোবাইল নম্বরে যোগাযোগ করবে।</p>
    </div>

    <div class="flex flex-col sm:flex-row gap-3 justify-center mt-6">
        <a href="./" class="text-white px-8 py-3 rounded-xl font-bold text-center shadow-lg" style="background:linear-gradient(135deg, rgb(var(--c-primary-2)), rgb(var(--c-primary)));">হোমে ফিরে যান</a>
        <button type="button" id="download-confirmation-btn" class="px-8 py-3 rounded-xl font-bold inline-flex items-center justify-center gap-2 shadow-lg" style="background:rgb(var(--c-tint));color:rgb(var(--c-deep));border:2px solid rgb(var(--c-primary));">
            <i data-lucide="download" class="w-5 h-5"></i> <span>ডাউনলোড করুন</span>
        </button>
    </div>

    <!-- অ্যাডমিন-নিয়ন্ত্রিত পেমেন্ট বক্স — এই আইটেমে প্রযোজ্য নাম্বার/WhatsApp দেখায় (per-course scope) -->
    <?php
        $payReg = get_db()->prepare('SELECT type, item_id FROM registrations WHERE id = :id');
        $payReg->execute(['id' => (int) $success['ref']]);
        $payRow = $payReg->fetch() ?: [];
        echo render_payment_box($payRow['type'] ?? '', (int) ($payRow['item_id'] ?? 0));
    ?>
</div>

<script src="assets/js/html2canvas.min.js?v=<?= @filemtime(__DIR__ . '/assets/js/html2canvas.min.js') ?: '1' ?>"></script>
<script>
(function () {
    var btn = document.getElementById('download-confirmation-btn');
    if (!btn) return;

    btn.addEventListener('click', function () {
        var card = document.getElementById('confirmation-card');
        var label = btn.querySelector('span');
        var originalLabel = label ? label.textContent : '';

        btn.disabled = true;
        if (label) label.textContent = 'অপেক্ষা করুন...';

        html2canvas(card, { backgroundColor: '#ffffff', scale: 2 }).then(function (canvas) {
            var link = document.createElement('a');
            link.download = 'registration-confirmation-<?= (int) $success['ref'] ?>.jpg';
            link.href = canvas.toDataURL('image/jpeg', 0.92);
            link.click();

            // ডাউনলোড লগ করা — best-effort, ব্যর্থ হলেও ডাউনলোড আটকাবে না
            fetch('log-download.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'registration_id=<?= (int) $success['ref'] ?>&csrf_token=<?= urlencode(csrf_token()) ?>'
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

<?php require __DIR__ . '/includes/site-footer.php'; ?>
