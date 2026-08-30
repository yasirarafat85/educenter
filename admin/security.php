<?php
// নিরাপত্তা / ফিঙ্গারপ্রিন্ট — ডিভাইস যোগ, তালিকা ও মুছে ফেলা (WebAuthn credential ম্যানেজমেন্ট)
require_once __DIR__ . '/includes/auth.php';
admin_require_login();

$pageTitle = 'নিরাপত্তা / ফিঙ্গারপ্রিন্ট';
$db = get_db();

// একটা ডিভাইস মুছে ফেলা (revoke) — শুধু নিজের credential
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify() && ($_POST['action'] ?? '') === 'delete') {
    $db->prepare('DELETE FROM admin_webauthn_credentials WHERE id = :id AND admin_id = :a')
        ->execute(['id' => (int) ($_POST['id'] ?? 0), 'a' => $_SESSION['admin_id']]);
    set_flash('success', 'ডিভাইসটি সরিয়ে ফেলা হয়েছে — এখন থেকে ওখান থেকে ফিঙ্গারপ্রিন্টে লগইন করা যাবে না।');
    redirect('security.php');
}

$rows = $db->prepare('SELECT * FROM admin_webauthn_credentials WHERE admin_id = :a ORDER BY created_at DESC');
$rows->execute(['a' => $_SESSION['admin_id']]);
$devices = $rows->fetchAll();

require __DIR__ . '/includes/layout-top.php';
?>
<div class="max-w-2xl space-y-6">

    <!-- পরিচিতি -->
    <div class="bg-indigo-50 border border-indigo-100 rounded-2xl p-5 text-sm text-gray-700 leading-relaxed">
        <p class="font-bold text-indigo-800 mb-1">ফিঙ্গারপ্রিন্ট দিয়ে সহজ লগইন</p>
        পাসওয়ার্ডের পাশাপাশি এই ডিভাইসের (ফোন/কম্পিউটার) ফিঙ্গারপ্রিন্ট বা Face/PIN দিয়ে লগইন করতে পারবেন।
        <span class="font-semibold">আপনার ফিঙ্গারপ্রিন্ট কখনো সার্ভারে যায় না</span> — ডিভাইস নিজে যাচাই করে।
        পাসওয়ার্ড সবসময় ব্যাকআপ হিসেবে থাকবে। যে ডিভাইসে যোগ করবেন, শুধু সেখানেই কাজ করবে।
    </div>

    <!-- এই ডিভাইস যোগ -->
    <div class="bg-white rounded-2xl shadow p-6">
        <h3 class="font-bold text-gray-800 mb-1">এই ডিভাইসে ফিঙ্গারপ্রিন্ট যোগ করুন</h3>
        <p class="text-sm text-gray-500 mb-4">একটা নাম দিন (যেমন "আমার ফোন") যাতে পরে চিনতে পারেন।</p>
        <div class="flex flex-wrap gap-3">
            <input type="text" id="fp-device-name" maxlength="100" placeholder="ডিভাইসের নাম" class="flex-1 border rounded-xl px-4 py-2.5 focus:ring-2 focus:ring-indigo-500 outline-none">
            <button type="button" id="fp-add-btn" class="inline-flex items-center justify-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white font-bold px-5 py-2.5 rounded-xl transition-colors">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-5 h-5"><path d="M12 11c0 3-1 5-1 8"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M17 11a5 5 0 0 0-10 0c0 2-.5 3.5-1 5"/></svg>
                যোগ করুন
            </button>
        </div>
        <p id="fp-add-msg" class="text-sm mt-3" style="display:none;"></p>
        <p id="fp-unsupported" class="text-sm text-amber-600 mt-3" style="display:none;">এই ব্রাউজার/ডিভাইস ফিঙ্গারপ্রিন্ট (WebAuthn) সাপোর্ট করে না। মোবাইলে Chrome/Safari দিয়ে চেষ্টা করুন।</p>
    </div>

    <!-- যুক্ত ডিভাইস তালিকা -->
    <div>
        <h3 class="font-bold text-gray-800 mb-3">যুক্ত ডিভাইস (<?= count($devices) ?>)</h3>
        <?php if (!$devices): ?>
            <div class="bg-white rounded-2xl shadow p-6 text-center text-gray-500 text-sm">এখনো কোনো ডিভাইস যোগ করা হয়নি।</div>
        <?php else: ?>
            <div class="bg-white rounded-2xl shadow overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500 border-b">
                            <th class="px-4 py-3 font-semibold">ডিভাইস</th>
                            <th class="px-4 py-3 font-semibold">যোগ হয়েছে</th>
                            <th class="px-4 py-3 font-semibold">শেষ ব্যবহার</th>
                            <th class="px-4 py-3 font-semibold text-right">অ্যাকশন</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($devices as $dv): ?>
                            <tr class="border-b last:border-0">
                                <td class="px-4 py-3 font-semibold text-gray-800"><?= e($dv['device_name']) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= e(date('Y-m-d H:i', strtotime($dv['created_at']))) ?></td>
                                <td class="px-4 py-3 text-gray-600"><?= $dv['last_used_at'] ? e(date('Y-m-d H:i', strtotime($dv['last_used_at']))) : '—' ?></td>
                                <td class="px-4 py-3 text-right">
                                    <form method="post" class="inline" onsubmit="return confirmSubmit(this, 'এই ডিভাইসটি সরিয়ে ফেলবেন? এরপর ওখান থেকে ফিঙ্গারপ্রিন্টে লগইন করা যাবে না।', 'ডিভাইস সরান')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= (int) $dv['id'] ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">সরান</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
    const CSRF = '<?= e(csrf_token()) ?>';
    const addBtn = document.getElementById('fp-add-btn');
    if (!window.PublicKeyCredential) {
        document.getElementById('fp-unsupported').style.display = '';
        if (addBtn) { addBtn.disabled = true; }
        return;
    }
    function b64urlToBuf(s){ s=s.replace(/-/g,'+').replace(/_/g,'/'); const p=s.length%4; if(p)s+='='.repeat(4-p); const b=atob(s); const a=new Uint8Array(b.length); for(let i=0;i<b.length;i++)a[i]=b.charCodeAt(i); return a.buffer; }
    function bufToB64url(buf){ const a=new Uint8Array(buf); let s=''; for(let i=0;i<a.length;i++)s+=String.fromCharCode(a[i]); return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,''); }
    function msg(m, ok){ const el=document.getElementById('fp-add-msg'); el.textContent=m||''; el.style.display=m?'':'none'; el.className='text-sm mt-3 '+(ok?'text-green-600':'text-red-600'); }
    async function postJSON(url, data){ const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(data)}); return r.json(); }

    addBtn.addEventListener('click', async function(){
        msg(''); addBtn.disabled=true;
        try {
            const opt = await postJSON('webauthn-register-options.php', {csrf_token:CSRF});
            if (opt.error) { msg(opt.error); return; }
            const pub = {
                challenge: b64urlToBuf(opt.challenge),
                rp: opt.rp,
                user: { id: b64urlToBuf(opt.user.id), name: opt.user.name, displayName: opt.user.displayName },
                pubKeyCredParams: opt.pubKeyCredParams,
                authenticatorSelection: opt.authenticatorSelection,
                attestation: opt.attestation,
                timeout: opt.timeout,
                excludeCredentials: (opt.excludeCredentials||[]).map(c=>({type:'public-key', id:b64urlToBuf(c.id)}))
            };
            const cred = await navigator.credentials.create({publicKey: pub});
            const res = await postJSON('webauthn-register.php', {
                csrf_token: CSRF,
                device_name: document.getElementById('fp-device-name').value,
                attestationObject: bufToB64url(cred.response.attestationObject),
                clientDataJSON: bufToB64url(cred.response.clientDataJSON)
            });
            if (res.ok) { msg('✓ যোগ হয়েছে!', true); setTimeout(()=>location.reload(), 700); }
            else { msg(res.error || 'যোগ করা ব্যর্থ হয়েছে'); }
        } catch(e) {
            msg('বাতিল বা ব্যর্থ হয়েছে। আবার চেষ্টা করুন।');
        } finally { addBtn.disabled=false; }
    });
})();
</script>
<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
