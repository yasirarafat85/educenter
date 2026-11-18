<?php
/* ════════════════════════════════════════════════════════════════════════════
 * 📚 ফেসবুক উত্তর-ভান্ডার (ধাপ ২, ২০২৬-১১-১৭)
 *
 * পাঁচ ট্যাব: ⚡ নির্দিষ্ট শব্দে উত্তর · 📚 জ্ঞানভান্ডার · 💡 শেখার প্রস্তাব · 🤖 AI (ধাপ ৩) · 📊 রিপোর্ট (ধাপ ৪)
 * 🔴 AI-র চাবি, মডেল, অবস্থা ও খরচের সীমা বদলানো শুধু মূল অ্যাডমিন পারেন; চাবি কখনো পর্দায় দেখানো হয় না।
 * 🔴 অটো-উত্তর চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন (ডিফল্টে বন্ধ)।
 * 🔴 প্রস্তাব অনুমোদন না করা পর্যন্ত কিছুই শেখা হয় না।
 * 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না।
 * ════════════════════════════════════════════════════════════════════════════ */
require_once __DIR__ . '/includes/auth.php';
admin_require_login();
require_once __DIR__ . '/includes/form-helpers.php'; // admin_image_src()
require_once __DIR__ . '/../includes/meta-ai.php'; // meta-kb.php-ও এর ভেতরে

$db = get_db();
$pageTitle = 'ফেসবুক উত্তর-ভান্ডার';
$me = (string) ($_SESSION['admin_username'] ?? '');
$ready = mkb_ready($db);
$tab = in_array($_GET['tab'] ?? '', ['rules', 'kb', 'images', 'learn', 'ai', 'report'], true) ? $_GET['tab'] : 'rules';
$action = (string) ($_GET['action'] ?? '');

function mk_back(string $tab, array $extra = []): string
{
    return 'meta-knowledge.php?' . http_build_query(array_merge(['tab' => $tab], $extra));
}
function mk_channels(): array { return ['both' => 'মেসেজ ও কমেন্ট দুটোতেই', 'message' => 'শুধু মেসেজে', 'comment' => 'শুধু কমেন্টে']; }
function mk_modes(): array { return ['suggest' => '💡 শুধু প্রস্তাব (আমি দেখে পাঠাব)', 'auto' => '🤖 নিজে থেকে পাঠাবে']; }

/* ─────────────────────────── POST হ্যান্ডলার ─────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    if (!csrf_verify()) { set_flash('error', 'ফর্ম টোকেন মিলছে না — পাতাটা আবার খুলে চেষ্টা করুন।'); redirect(mk_back($tab)); }
    if (!$ready) { set_flash('error', 'আগে database/migrate-meta-knowledge.sql চালাতে হবে।'); redirect(mk_back($tab)); }
    $now = date('Y-m-d H:i:s');

    // ── অটো-উত্তর চালু/বন্ধ (শুধু মূল অ্যাডমিন) ──
    if ($action === 'auto') {
        if (!admin_is_super()) { set_flash('error', 'অটো-উত্তর চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন।'); redirect(mk_back('rules')); }
        $on = ($_POST['on'] ?? '') === '1';
        update_setting('meta_autoreply_on', $on ? '1' : '0');
        set_flash('success', $on ? '🤖 অটো-উত্তর চালু হলো — শুধু "নিজে থেকে পাঠাবে" চিহ্নিত নিয়মগুলো উত্তর দেবে।' : '⏸️ অটো-উত্তর বন্ধ — এখন সব নিয়ম শুধু ইনবক্সে প্রস্তাব দেখাবে।');
        redirect(mk_back('rules'));
    }

    // ── নিয়ম সেভ ──
    if ($action === 'rule-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $kw = trim((string) ($_POST['keywords'] ?? ''));
        $reply = trim(str_replace("\r\n", "\n", (string) ($_POST['reply'] ?? '')));
        $ch = array_key_exists($_POST['channel'] ?? '', mk_channels()) ? $_POST['channel'] : 'both';
        $mode = array_key_exists($_POST['mode'] ?? '', mk_modes()) ? $_POST['mode'] : 'suggest';
        $act = !empty($_POST['is_active']) ? 1 : 0;
        $sort = max(0, min(9999, (int) ($_POST['sort_order'] ?? 0)));
        if (!mkb_keywords($kw) || $reply === '' || mb_strlen($reply) > 2000) {
            $_SESSION['mk_rule_old'] = $_POST;
            set_flash('error', 'অন্তত একটা শব্দ আর উত্তর লিখুন (উত্তর ২০০০ অক্ষরের মধ্যে)।');
            redirect(mk_back('rules', array_filter(['edit' => $id ?: null, 'new' => $id ? null : 1])) . '#mk-form');
        }
        $p = ['k' => mb_substr($kw, 0, 2000), 'r' => $reply, 'c' => $ch, 'm' => $mode, 'a' => $act, 's' => $sort];
        if ($id) {
            $db->prepare('UPDATE meta_rules SET keywords = :k, reply = :r, channel = :c, mode = :m, is_active = :a, sort_order = :s WHERE id = :id')
               ->execute($p + ['id' => $id]);
        } else {
            $db->prepare('INSERT INTO meta_rules (keywords, reply, channel, mode, is_active, sort_order) VALUES (:k, :r, :c, :m, :a, :s)')->execute($p);
            $id = (int) $db->lastInsertId();
        }
        if (mimg_col($db, 'meta_rules')) {
            // 🔴 শুধু সংগ্রহে সত্যিই থাকা ছবি (POST-এর নম্বরে ভরসা নয়)
            $iid = (int) ($_POST['image_id'] ?? 0);
            $db->prepare('UPDATE meta_rules SET image_id = :i WHERE id = :id')
               ->execute(['i' => $iid > 0 && mimg_get($db, $iid, false) ? $iid : null, 'id' => $id]);
        }
        set_flash('success', '✅ নিয়ম সংরক্ষণ হয়েছে।' . ($mode === 'auto' && !mkb_auto_on() ? ' (অটো-উত্তরের সুইচ এখন বন্ধ — তাই এটাও আপাতত শুধু প্রস্তাব দেখাবে।)' : ''));
        redirect(mk_back('rules') . '#rule-' . $id);
    }

    if ($action === 'rule-toggle') {
        $id = (int) ($_POST['id'] ?? 0);
        $db->prepare('UPDATE meta_rules SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $id]);
        redirect(mk_back('rules') . '#rule-' . $id);
    }

    // ── 🖼️ সংগ্রহে ছবি রাখা / বদলানো ──
    if ($action === 'img-save') {
        if (!mimg_ready($db)) { set_flash('error', 'আগে database/migrate-meta-images.sql চালাতে হবে।'); redirect(mk_back('images')); }
        $id = (int) ($_POST['id'] ?? 0);
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 190);
        $when = mb_substr(trim(str_replace("\r\n", "\n", (string) ($_POST['when_text'] ?? ''))), 0, 1000);
        $act = !empty($_POST['is_active']) ? 1 : 0;
        $sort = max(0, min(9999, (int) ($_POST['sort_order'] ?? 0)));
        $old = $id ? mimg_get($db, $id, false) : null;
        if ($id && !$old) { redirect(mk_back('images')); }
        $hasFile = isset($_FILES['image_file']) && (int) ($_FILES['image_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if ($title === '' || (!$id && !$hasFile)) {
            set_flash('error', 'ছবির নাম আর ছবি দুটোই লাগবে।');
            redirect(mk_back('images', array_filter(['edit' => $id ?: null])) . '#mk-form');
        }
        $path = (string) ($old['file_path'] ?? '');
        if ($hasFile) {
            try { $path = mimg_save_upload('image_file'); }
            catch (Throwable $e) { set_flash('error', '🖼️ ' . $e->getMessage()); redirect(mk_back('images', array_filter(['edit' => $id ?: null])) . '#mk-form'); }
        }
        $p = ['t' => $title, 'w' => $when !== '' ? $when : null, 'f' => $path, 'a' => $act, 's' => $sort];
        if ($id) {
            $db->prepare('UPDATE meta_images SET title = :t, when_text = :w, file_path = :f, is_active = :a, sort_order = :s WHERE id = :id')->execute($p + ['id' => $id]);
            if ($hasFile && $old && (string) $old['file_path'] !== $path) { mimg_delete_file((string) $old['file_path']); }
        } else {
            $db->prepare('INSERT INTO meta_images (title, when_text, file_path, is_active, sort_order, created_by) VALUES (:t, :w, :f, :a, :s, :b)')->execute($p + ['b' => $me]);
            $id = (int) $db->lastInsertId();
        }
        set_flash('success', '✅ ছবি সংগ্রহে রাখা হলো — নিয়মে জুড়তে পারবেন, AI-ও দরকার হলে বেছে নেবে।');
        redirect(mk_back('images') . '#img-' . $id);
    }

    // ── মোছা (নিয়ম / ভান্ডারের লেখা / ছবি) ──
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if (($_POST['what'] ?? '') === 'image') {
            $im = mimg_ready($db) ? mimg_get($db, $id, false) : null;
            if ($im) {
                $db->prepare('DELETE FROM meta_images WHERE id = :id')->execute(['id' => $id]);
                if (mimg_col($db, 'meta_rules')) { $db->prepare('UPDATE meta_rules SET image_id = NULL WHERE image_id = :id')->execute(['id' => $id]); }
                mimg_delete_file((string) $im['file_path']);
                set_flash('success', 'ছবিটা মুছে ফেলা হলো (যে নিয়মে জোড়া ছিল, সেখান থেকেও সরানো হলো)।');
            }
            redirect(mk_back('images'));
        }

        if (($_POST['what'] ?? '') === 'rule') {
            $db->prepare('DELETE FROM meta_rules WHERE id = :id')->execute(['id' => $id]);
            set_flash('success', 'নিয়মটা মুছে ফেলা হলো।');
            redirect(mk_back('rules'));
        }
        $st = $db->prepare('SELECT * FROM meta_kb WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if ($row) {
            if (!empty($row['source_ref'])) {
                // 🔴 হাতের উত্তর থেকে আসা — "বাদ" হিসেবে রাখা হয়, নাহলে পরের বার আবার প্রস্তাব হয়ে ফিরত
                $db->prepare("UPDATE meta_kb SET status = 'rejected', updated_at = :at WHERE id = :id")->execute(['at' => $now, 'id' => $id]);
            } else {
                $db->prepare('DELETE FROM meta_kb WHERE id = :id')->execute(['id' => $id]);
            }
            set_flash('success', 'মুছে ফেলা হলো।');
        }
        redirect(mk_back('kb', ['kind' => ($row['kind'] ?? 'qa') === 'doc' ? 'doc' : 'qa']));
    }

    // ── প্রশ্নোত্তর সেভ ──
    // ── প্রশংসার জবাবের লেখা (ধাপ ৪) — ইনবক্সের "❤️ ধন্যবাদ জানান" বোতামে বসে, নিজে পাঠায় না ──
    if ($action === 'thanks-save') {
        $t = trim(str_replace("\r\n", "\n", (string) ($_POST['thanks'] ?? '')));
        if (mb_strlen($t) > 500) { set_flash('error', 'লেখাটা ৫০০ অক্ষরের মধ্যে রাখুন।'); redirect(mk_back('report')); }
        update_setting('meta_thanks_text', $t);
        set_flash('success', $t === '' ? '↩ তৈরি লেখাটাই ব্যবহার হবে।' : '✅ প্রশংসার জবাব সংরক্ষণ হলো।');
        redirect(mk_back('report') . '#mk-thanks');
    }

    if ($action === 'qa-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $q = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));
        $a = trim(str_replace("\r\n", "\n", (string) ($_POST['answer'] ?? '')));
        if ($q === '' || $a === '' || mb_strlen($q) > 2000 || mb_strlen($a) > 4000) {
            $_SESSION['mk_qa_old'] = $_POST;
            set_flash('error', 'প্রশ্ন ও উত্তর দুটোই লিখুন (প্রশ্ন ২০০০, উত্তর ৪০০০ অক্ষরের মধ্যে)।');
            redirect(mk_back('kb', array_filter(['kind' => 'qa', 'edit' => $id ?: null])) . '#mk-form');
        }
        if ($id) {
            $db->prepare("UPDATE meta_kb SET question = :q, answer = :a, updated_at = :at WHERE id = :id AND kind = 'qa'")
               ->execute(['q' => $q, 'a' => $a, 'at' => $now, 'id' => $id]);
        } else {
            $db->prepare("INSERT INTO meta_kb (kind, status, question, answer, source, created_by, approved_by, approved_at) VALUES ('qa', 'active', :q, :a, 'admin', :b, :b2, :at)")
               ->execute(['q' => $q, 'a' => $a, 'b' => $me, 'b2' => $me, 'at' => $now]);
            $id = (int) $db->lastInsertId();
        }
        // 🔴 কোথায় ব্যবহার হবে — আলাদা UPDATE, ঘরটা না থাকলে (মাইগ্রেশনের আগে) চুপচাপ বাদ
        if ($id && mkb_has_channel($db)) {
            $db->prepare("UPDATE meta_kb SET channel = :ch WHERE id = :id AND kind = 'qa'")
               ->execute(['ch' => mkb_channel_clean($_POST['channel'] ?? 'both'), 'id' => $id]);
        }
        set_flash('success', '✅ প্রশ্নোত্তর জ্ঞানভান্ডারে রাখা হলো।');
        redirect(mk_back('kb', ['kind' => 'qa']));
    }

    // ── ডকুমেন্ট সেভ (লেখা পেস্ট বা .txt/.md/.docx আপলোড) ──
    if ($action === 'doc-save') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = (string) ($_POST['body'] ?? '');
        $f = $_FILES['doc_file'] ?? null;
        if ($f && (int) ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if ((int) $f['size'] > 2 * 1024 * 1024 || !in_array($ext, ['txt', 'md', 'docx'], true)) {
                set_flash('error', 'শুধু .txt, .md বা .docx ফাইল, ২ MB-এর মধ্যে। PDF হলে লেখাটা কপি করে নিচের বাক্সে পেস্ট করুন।');
                redirect(mk_back('kb', ['kind' => 'doc']) . '#mk-form');
            }
            $body = $ext === 'docx' ? mkb_docx_text((string) $f['tmp_name']) : (string) file_get_contents((string) $f['tmp_name']);
            if ($title === '') { $title = pathinfo((string) $f['name'], PATHINFO_FILENAME); }
        }
        $body = mkb_clean_doc($body);
        if ($title === '' || $body === '') {
            set_flash('error', 'শিরোনাম আর লেখা দুটোই লাগবে (ফাইল থেকে লেখা পড়া না গেলে কপি করে পেস্ট করুন)।');
            redirect(mk_back('kb', array_filter(['kind' => 'doc', 'edit' => $id ?: null])) . '#mk-form');
        }
        $title = mb_substr($title, 0, 190);
        if ($id) {
            $db->prepare("UPDATE meta_kb SET title = :t, answer = :b, updated_at = :at WHERE id = :id AND kind = 'doc'")
               ->execute(['t' => $title, 'b' => $body, 'at' => $now, 'id' => $id]);
        } else {
            $db->prepare("INSERT INTO meta_kb (kind, status, title, answer, source, created_by, approved_by, approved_at) VALUES ('doc', 'active', :t, :b, 'upload', :by, :by2, :at)")
               ->execute(['t' => $title, 'b' => $body, 'by' => $me, 'by2' => $me, 'at' => $now]);
        }
        set_flash('success', '✅ ডকুমেন্ট রাখা হলো (' . number_format(mb_strlen($body)) . ' অক্ষর)।');
        redirect(mk_back('kb', ['kind' => 'doc']));
    }

    // ── প্রস্তাব অনুমোদন / বাদ ──
    if ($action === 'approve' || $action === 'reject') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($action === 'approve') {
            $q = trim(str_replace("\r\n", "\n", (string) ($_POST['question'] ?? '')));
            $a = trim(str_replace("\r\n", "\n", (string) ($_POST['answer'] ?? '')));
            if ($q === '' || $a === '') { set_flash('error', 'প্রশ্ন ও উত্তর খালি রাখা যাবে না।'); redirect(mk_back('learn') . '#kb-' . $id); }
            $db->prepare("UPDATE meta_kb SET status = 'active', question = :q, answer = :a, approved_by = :b, approved_at = :at, updated_at = :at2
                          WHERE id = :id AND status = 'proposed'")
               ->execute(['q' => mb_substr($q, 0, 2000), 'a' => mb_substr($a, 0, 4000), 'b' => $me, 'at' => $now, 'at2' => $now, 'id' => $id]);
            $chMsg = '';
            if (mkb_has_channel($db)) {
                $src = (string) $db->query('SELECT source FROM meta_kb WHERE id = ' . (int) $id)->fetchColumn();
                $ch = mkb_channel_clean($_POST['channel'] ?? '', mkb_channel_default($src));
                $db->prepare("UPDATE meta_kb SET channel = :ch WHERE id = :id AND kind = 'qa'")->execute(['ch' => $ch, 'id' => $id]);
                $chMsg = ' (' . mkb_channels()[$ch][0] . ')';
            }
            set_flash('success', '✅ শেখা হলো' . $chMsg . ' — এখন থেকে মিলে যাওয়া প্রশ্নে এই উত্তর প্রস্তাব হিসেবে আসবে।');
        } else {
            $db->prepare("UPDATE meta_kb SET status = 'rejected', updated_at = :at WHERE id = :id AND status = 'proposed'")->execute(['at' => $now, 'id' => $id]);
            set_flash('success', 'প্রস্তাবটা বাদ দেওয়া হলো — আর আসবে না।');
        }
        redirect(mk_back('learn'));
    }

    // ── 🤖 AI-র সেটিংস (শুধু মূল অ্যাডমিন) ──
    if ($action === 'ai-save') {
        if (!admin_is_super()) { set_flash('error', 'AI-র সেটিংস বদলানো শুধু মূল অ্যাডমিন পারেন।'); redirect(mk_back('ai')); }
        if (!empty($_POST['clear_key'])) {
            update_setting('meta_ai_key', '');
            update_setting('meta_ai_mode_message', 'off');
            update_setting('meta_ai_mode_comment', 'off');
            set_flash('success', '🗑️ AI-র চাবি মুছে ফেলা হলো — AI এখন সম্পূর্ণ বন্ধ।');
            redirect(mk_back('ai'));
        }
        $key = trim((string) ($_POST['ai_key'] ?? ''));
        if ($key !== '') {
            if (!preg_match('/^sk-ant-[A-Za-z0-9_\-]{20,300}$/', $key)) { set_flash('error', 'চাবিটা দেখতে ঠিক নয় — console.anthropic.com → API Keys থেকে পুরোটা কপি করুন (sk-ant- দিয়ে শুরু)।'); redirect(mk_back('ai')); }
            update_setting('meta_ai_key', $key);
        }
        $model = (string) ($_POST['ai_model'] ?? '');
        if (isset(mai_models()[$model])) { update_setting('meta_ai_model', $model); }
        foreach (['message', 'comment'] as $ch) {
            $m = (string) ($_POST['mode_' . $ch] ?? 'off');
            update_setting('meta_ai_mode_' . $ch, isset(mai_modes()[$m]) ? $m : 'off');
        }
        $cap = (float) str_replace(',', '.', (string) ($_POST['ai_cap'] ?? MAI_DEFAULT_CAP));
        update_setting('meta_ai_cap_usd', (string) round(max(0, min(1000, $cap)), 2));
        update_setting('meta_ai_site_info', !empty($_POST['site_info']) ? '1' : '0');
        $persona = trim(str_replace("\r\n", "\n", (string) ($_POST['ai_persona'] ?? '')));
        update_setting('meta_ai_persona', mb_substr($persona, 0, 3000));
        set_flash('success', '🤖 AI-র সেটিংস সংরক্ষণ হয়েছে।' . (mai_has_key() ? '' : ' চাবি না বসালে AI কাজ করবে না।'));
        redirect(mk_back('ai'));
    }

    // ── 🧪 AI-কে প্রশ্ন করে দেখা (কিছুই পাঠানো হয় না, শুধু খরচ হয়) ──
    if ($action === 'ai-test') {
        $qt = trim((string) ($_POST['q'] ?? ''));
        $ch = ($_POST['ch'] ?? '') === 'comment' ? 'comment' : 'message';
        if ($qt === '' || mb_strlen($qt) > 1000) { set_flash('error', 'প্রশ্নটা লিখুন (১০০০ অক্ষরের মধ্যে)।'); redirect(mk_back('ai')); }
        if (!mai_ready($db) || !mai_has_key()) { set_flash('error', 'আগে migrate-meta-ai.sql চালান আর চাবি বসান।'); redirect(mk_back('ai')); }
        @set_time_limit(120);
        $g = mai_generate($db, [['role' => 'user', 'content' => $ch === 'comment' ? "কমেন্ট:\n" . $qt : $qt]], $ch, 'test', '', 'test');
        $_SESSION['mk_ai_test'] = ['q' => $qt, 'ch' => $ch] + $g;
        redirect(mk_back('ai') . '#mk-ai-test');
    }

    if ($action === 'scan') {
        $n = mkb_scan_history($db);
        set_flash('success', $n ? "💡 নতুন {$n}টি প্রস্তাব পাওয়া গেছে।" : 'নতুন কোনো প্রস্তাব পাওয়া যায়নি — হাতে উত্তর দিলে বা পুরনো মেসেজ/কমেন্ট আনলে প্রস্তাব তৈরি হয়।');
        redirect(mk_back('learn'));
    }

    // ── 📥 তৈরি করা কোর্সের ডকুমেন্ট এক চাপে বসানো (database/kb-seed) ──
    if ($action === 'seed-import') {
        $n = mai_seed_import($db, $me);
        set_flash('success', $n ? "📥 {$n}টি কোর্সের ডকুমেন্ট জ্ঞানভান্ডারে বসানো হলো — AI এখন থেকেই এগুলো পড়বে।" : 'নতুন কিছু বসানোর নেই — সবগুলো আগেই বসানো আছে।');
        redirect(mk_back('kb', ['kind' => 'doc']));
    }
    // ── মুছে ফেলা একটা তৈরি-ডকুমেন্ট ফিরিয়ে আনা ──
    if ($action === 'seed-restore') {
        $key = (string) ($_POST['key'] ?? '');
        foreach (mai_seed_list($db) as $sd) {
            if ($sd['key'] === $key && $sd['state'] === 'removed') {
                $db->prepare("UPDATE meta_kb SET status = 'active', title = :t, answer = :b, updated_at = :at WHERE source_ref = :ref")
                   ->execute(['t' => $sd['title'], 'b' => $sd['body'], 'at' => $now, 'ref' => 'seed:' . $key]);
                set_flash('success', '↩ "' . $sd['title'] . '" আবার জ্ঞানভান্ডারে বসানো হলো।');
            }
        }
        redirect(mk_back('kb', ['kind' => 'doc']));
    }

    // ── বসানো তৈরি-ডকুমেন্টে ফাইলের নতুন সংস্করণ বসানো (অ্যাডমিন চাপলে তবেই — নিজের বদল মুছে যায়) ──
    if ($action === 'seed-update') {
        $key = (string) ($_POST['key'] ?? '');
        foreach (mai_seed_list($db) as $sd) {
            if ($sd['key'] === $key && $sd['state'] === 'changed') {
                $db->prepare("UPDATE meta_kb SET title = :t, answer = :b, updated_at = :at WHERE source_ref = :ref AND status = 'active'")
                   ->execute(['t' => $sd['title'], 'b' => $sd['body'], 'at' => $now, 'ref' => 'seed:' . $key]);
                set_flash('success', '↻ "' . $sd['title'] . '" নতুন সংস্করণে হালনাগাদ হলো — AI এখন থেকেই নতুন লেখা পড়বে।');
            }
        }
        redirect(mk_back('kb', ['kind' => 'doc']));
    }

    redirect(mk_back($tab));
}

/* ─────────────────────────── দেখানোর ডেটা ─────────────────────────── */
$kbPlan = null; $siteChars = 0; $seeds = [];
$rules = []; $kbRows = []; $proposals = []; $images = []; $editRow = null; $testOut = null;
$counts = ['rules' => 0, 'qa' => 0, 'doc' => 0, 'learn' => 0];
$kind = ($_GET['kind'] ?? '') === 'doc' ? 'doc' : 'qa';
$q = trim((string) ($_GET['q'] ?? ''));
$testText = trim((string) ($_GET['t'] ?? ''));
$testCh = ($_GET['tc'] ?? '') === 'comment' ? 'comment' : 'message';
$newScan = 0;

if ($ready) {
    try {
        if ($tab === 'learn') { $newScan = mkb_scan_history($db); }
        $counts['rules'] = (int) $db->query('SELECT COUNT(*) FROM meta_rules')->fetchColumn();
        foreach ($db->query("SELECT kind, COUNT(*) n FROM meta_kb WHERE status = 'active' GROUP BY kind")->fetchAll() as $r) { $counts[$r['kind']] = (int) $r['n']; }
        $counts['learn'] = mkb_proposed_count($db);

        if ($tab === 'rules') {
            $rules = mkb_rules($db, true);
            $eid = (int) ($_GET['edit'] ?? 0);
            if ($eid) { foreach ($rules as $r) { if ((int) $r['id'] === $eid) { $editRow = $r; } } }
            if ($testText !== '') {
                $testOut = ['rule' => mkb_rule_pick($rules, $testText, $testCh), 'sug' => mkb_suggest($db, $testText, $testCh, 'রহিমা')];
            }
        } elseif ($tab === 'kb') {
            $kbPlan = mai_kb_plan($db);
            $siteChars = mai_site_info_on() ? mb_strlen(mai_site_info_text($db)) : 0;
            $seeds = mai_seed_list($db);
            $params = ['k' => $kind];
            $where = "kind = :k AND status = 'active'";
            if ($q !== '') {
                $where .= " AND CONCAT_WS(' ', title, question, answer) LIKE :q ESCAPE '!'";
                $params['q'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
            }
            $st = $db->prepare("SELECT * FROM meta_kb WHERE $where ORDER BY id DESC LIMIT 300");
            $st->execute($params);
            $kbRows = $st->fetchAll();
            $eid = (int) ($_GET['edit'] ?? 0);
            if ($eid) {
                $st = $db->prepare("SELECT * FROM meta_kb WHERE id = :id AND kind = :k AND status = 'active'");
                $st->execute(['id' => $eid, 'k' => $kind]);
                $editRow = $st->fetch() ?: null;
            }
        } elseif ($tab === 'images') {
            $images = mimg_all($db, false, true);
            $eid = (int) ($_GET['edit'] ?? 0);
            if ($eid && isset($images[$eid])) { $editRow = $images[$eid]; }
        } elseif ($tab === 'learn') {
            $proposals = $db->query("SELECT * FROM meta_kb WHERE status = 'proposed' ORDER BY id DESC LIMIT 50")->fetchAll();
        }
    } catch (Throwable $e) {
        error_log('meta-knowledge: ' . $e->getMessage());
    }
}
$isSuper = admin_is_super();
$autoOn = mkb_auto_on();
$aiReady = $ready && mai_ready($db);
$aiCfg = mai_cfg();
$aiStats = $aiReady ? mai_month_stats($db) : null;
$aiLog = [];
if ($aiReady && $tab === 'ai') {
    try { $aiLog = $db->query('SELECT * FROM meta_ai_log ORDER BY id DESC LIMIT 15')->fetchAll(); } catch (Throwable $e) {}
}
$aiTest = $_SESSION['mk_ai_test'] ?? null; unset($_SESSION['mk_ai_test']);
$report = null;
if ($ready && $tab === 'report') { $report = mai_report($db, (int) ($_GET['days'] ?? 7)); }
$ruleOld = $_SESSION['mk_rule_old'] ?? null; unset($_SESSION['mk_rule_old']);
$qaOld = $_SESSION['mk_qa_old'] ?? null; unset($_SESSION['mk_qa_old']);

require __DIR__ . '/includes/layout-top.php';
?>
<style>
.mk-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.mk-tab{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1rem;border-radius:.9rem;font-weight:700;font-size:.9rem;background:rgb(var(--c-surface,255 255 255));border:1px solid rgb(var(--c-border,229 231 235));color:rgb(var(--c-text,31 41 55))}
.mk-tab.on{background:rgb(var(--c-primary));color:#fff;border-color:transparent}
.mk-rep-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.5rem}
.mk-rep-box{display:flex;flex-direction:column;gap:.15rem;padding:.7rem .8rem;border-radius:.8rem;background:#F3F4F6;color:#374151;min-width:0}
.mk-rep-box b{font-size:1.35rem;line-height:1.2}
.mk-rep-box span{font-size:.8rem;line-height:1.45;overflow-wrap:anywhere}
.mk-rep-q{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;padding:.7rem 0;border-top:1px solid rgb(var(--c-border,229 231 235))}
.mk-badge{display:inline-block;min-width:1.4rem;padding:0 .4rem;border-radius:999px;background:#EF4444;color:#fff;font-size:.72rem;line-height:1.4rem;text-align:center}
.mk-card{background:#fff;border-radius:1rem;box-shadow:0 1px 3px rgba(0,0,0,.08);padding:1rem;min-width:0}
.mk-f label{display:block;font-size:.85rem;font-weight:700;color:#374151;margin:.6rem 0 .25rem}
.mk-f textarea,.mk-f input[type=text],.mk-f input[type=search],.mk-f input[type=number],.mk-f select{width:100%;min-width:0;border:1px solid rgb(var(--c-border,229 231 235));border-radius:.8rem;padding:.55rem .8rem;font-size:16px;line-height:1.6;background:#fff}
.mk-hint{font-size:.78rem;color:#6B7280;margin-top:.2rem}
.mk-chip{display:inline-block;padding:.15rem .55rem;border-radius:999px;font-size:.78rem;font-weight:600;background:#EEF2FF;color:#3730A3;margin:0 .25rem .25rem 0;overflow-wrap:anywhere}
.mk-pre{white-space:pre-wrap;overflow-wrap:anywhere;font-size:.9rem;line-height:1.7;color:#1F2937}
.mk-row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.mk-chs{display:flex;flex-wrap:wrap;gap:.4rem}
.mk-ch{display:inline-flex !important;margin:0 !important;cursor:pointer}
.mk-ch input{position:absolute;opacity:0;width:1px;height:1px}
.mk-ch span{display:inline-flex;align-items:center;min-height:40px;padding:.4rem .8rem;border-radius:.75rem;border:2px solid #E5E7EB;background:#fff;color:#4B5563;font-size:.85rem;font-weight:700}
.mk-ch input:checked + span{background:var(--chb);color:var(--chc);border-color:var(--chc)}
.mk-ch input:focus-visible + span{outline:2px solid rgb(var(--c-primary));outline-offset:2px}
.mk-src{display:inline-block;padding:.2rem .65rem;border-radius:999px;font-size:.8rem;font-weight:700}
.mk-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.5rem .9rem;border-radius:.75rem;font-size:.85rem;font-weight:700}
.mk-var{font-family:monospace;background:#F3F4F6;padding:.05rem .35rem;border-radius:.35rem}
</style>

<?php if (!$ready): ?>
<div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 mb-6 text-sm">
    <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
    <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-meta-knowledge.sql</code> চালান (তার আগে <code class="bg-white px-1.5 py-0.5 rounded">migrate-meta-inbox.sql</code>)। 🩺 সেটিংস → সিস্টেম চেক পাতাতেও কপি বোতামসহ দেখা যাবে।</p>
</div>
<?php endif; ?>

<div class="mk-tabs">
    <a href="meta-inbox.php" class="mk-tab">← ইনবক্স</a>
    <a href="<?= e(mk_back('rules')) ?>" class="mk-tab <?= $tab === 'rules' ? 'on' : '' ?>">⚡ নির্দিষ্ট শব্দে উত্তর <span class="text-xs" style="opacity:.75">(<?= $counts['rules'] ?>)</span></a>
    <a href="<?= e(mk_back('kb')) ?>" class="mk-tab <?= $tab === 'kb' ? 'on' : '' ?>">📚 জ্ঞানভান্ডার <span class="text-xs" style="opacity:.75">(<?= $counts['qa'] + $counts['doc'] ?>)</span></a>
    <a href="<?= e(mk_back('images')) ?>" class="mk-tab <?= $tab === 'images' ? 'on' : '' ?>">🖼️ ছবি <span class="text-xs" style="opacity:.75">(<?= count(mimg_all($db, false)) ?>)</span></a>
    <a href="<?= e(mk_back('learn')) ?>" class="mk-tab <?= $tab === 'learn' ? 'on' : '' ?>">💡 শেখার প্রস্তাব <?php if ($counts['learn']): ?><span class="mk-badge"><?= $counts['learn'] ?></span><?php endif; ?></a>
    <a href="<?= e(mk_back('ai')) ?>" class="mk-tab <?= $tab === 'ai' ? 'on' : '' ?>">🤖 AI <?= $aiReady && mai_has_key() ? '' : '<span class="text-xs" style="opacity:.75">(বন্ধ)</span>' ?></a>
    <a href="<?= e(mk_back('report')) ?>" class="mk-tab <?= $tab === 'report' ? 'on' : '' ?>">📊 রিপোর্ট</a>
</div>

<?php if ($ready && $tab === 'rules'): ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-card" style="border-left:4px solid <?= $autoOn ? '#16A34A' : '#9CA3AF' ?>">
        <div class="mk-row" style="justify-content:space-between">
            <div style="min-width:0;flex:1">
                <p class="font-bold text-gray-800"><?= $autoOn ? '🤖 অটো-উত্তর চালু' : '⏸️ অটো-উত্তর বন্ধ' ?></p>
                <p class="text-sm text-gray-600 mt-1"><?= $autoOn
                    ? '"নিজে থেকে পাঠাবে" চিহ্নিত নিয়মে শব্দ মিললে উত্তর সাথে সাথে চলে যায়। একই মানুষকে একই উত্তর ' . MKB_COOLDOWN_HOURS . ' ঘণ্টায় একবার, আর আপনি নিজে উত্তর দিলে ' . MKB_HUMAN_QUIET_MIN . ' মিনিট অটো চুপ থাকে।'
                    : 'এখন কোনো উত্তর নিজে থেকে যায় না — মিলে যাওয়া উত্তর শুধু ইনবক্সে "💡 প্রস্তাবিত উত্তর" হিসেবে দেখায়, আপনি এক চাপে বসিয়ে পাঠান।' ?></p>
            </div>
            <?php if ($isSuper): ?>
            <form method="post" action="<?= e(mk_back('rules', ['action' => 'auto'])) ?>" <?= $autoOn ? '' : 'onsubmit="return confirmSubmit(this, \'চালু করলে নিজে-থেকে-পাঠানো নিয়মগুলো আপনার দেখা ছাড়াই ফেসবুকে উত্তর পাঠাবে। চালু করবেন?\', \'অটো-উত্তর চালু\');"' ?>>
                <?= csrf_field() ?>
                <input type="hidden" name="on" value="<?= $autoOn ? '0' : '1' ?>">
                <button type="submit" class="mk-btn <?= $autoOn ? 'bg-red-100 text-red-700' : 'bg-green-600 text-white' ?>"><?= $autoOn ? '⏸️ বন্ধ করুন' : '▶️ চালু করুন' ?></button>
            </form>
            <?php else: ?>
            <span class="text-xs text-gray-500">চালু/বন্ধ শুধু মূল অ্যাডমিন পারেন</span>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="mk-card mk-f" id="mkTestForm">
        <input type="hidden" name="tab" value="rules">
        <p class="font-bold text-gray-800">🧪 পরীক্ষা করে দেখুন <span class="text-xs font-normal text-gray-500">(কিছুই পাঠানো হয় না)</span></p>
        <div class="mk-row mt-2">
            <input type="text" name="t" value="<?= e($testText) ?>" placeholder="যেমন: কোর্সের দাম কত?" style="flex:1;min-width:180px">
            <select name="tc" style="width:auto"><option value="message" <?= $testCh === 'message' ? 'selected' : '' ?>>মেসেজ হিসেবে</option><option value="comment" <?= $testCh === 'comment' ? 'selected' : '' ?>>কমেন্ট হিসেবে</option></select>
            <button type="submit" class="mk-btn bg-indigo-600 text-white">দেখুন</button>
        </div>
        <?php if ($testOut !== null): ?>
            <div class="mt-3 text-sm">
                <?php if ($testOut['rule']): ?>
                    <p class="font-semibold text-green-700">✅ মিলেছে — নিয়ম #<?= (int) $testOut['rule']['id'] ?> (<?= ($testOut['rule']['mode'] === 'auto' && $autoOn) ? '🤖 নিজে থেকে যেত' : '💡 শুধু প্রস্তাব হতো' ?>)</p>
                <?php else: ?>
                    <p class="font-semibold text-gray-600">কোনো নির্দিষ্ট-শব্দের নিয়মে মেলেনি।</p>
                <?php endif; ?>
                <?php foreach ($testOut['sug'] as $sg): ?>
                    <div class="mt-2 p-3 rounded-xl bg-gray-50"><p class="text-xs text-gray-500 mb-1"><?= e($sg['label']) ?></p><div class="mk-pre"><?= e($sg['text']) ?></div></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </form>

    <?php $showForm = $editRow || $ruleOld || isset($_GET['new']);
    $fr = $ruleOld ?: ($editRow ?: ['keywords' => '', 'reply' => '', 'channel' => 'both', 'mode' => 'suggest', 'is_active' => 1, 'sort_order' => 0]); ?>
    <?php if ($showForm): ?>
    <form method="post" action="<?= e(mk_back('rules', ['action' => 'rule-save'])) ?>" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fr['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fr['id']) ? '✏️ নিয়ম বদলান' : '➕ নতুন নিয়ম' ?></p>
        <label>কোন শব্দ থাকলে? (প্রতি লাইনে বা কমা দিয়ে একটা)</label>
        <textarea name="keywords" rows="3" required placeholder="দাম, ফি, কত টাকা&#10;price"><?= e((string) $fr['keywords']) ?></textarea>
        <p class="mk-hint">যেকোনো একটা শব্দ মিললেই নিয়মটা খাটে। বাংলা শব্দ অংশ হিসেবেও মেলে ("দাম" → "দামটা কত")।</p>
        <label>উত্তর</label>
        <textarea name="reply" rows="5" required maxlength="2000"><?= e((string) $fr['reply']) ?></textarea>
        <p class="mk-hint">লেখায় বসাতে পারেন: <span class="mk-var">{name}</span> নাম · <span class="mk-var">{courses}</span> এখন ভর্তি চলা কোর্সের তালিকা ও দাম (প্রতিবার টাটকা) · <span class="mk-var">{site}</span> ওয়েবসাইট · <span class="mk-var">{whatsapp}</span> WhatsApp লিংক</p>
        <div class="grid sm:grid-cols-2" style="column-gap:1rem">
            <div><label>কোথায়?</label><select name="channel"><?php foreach (mk_channels() as $k => $lb): ?><option value="<?= $k ?>" <?= ($fr['channel'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
            <div><label>কীভাবে?</label><select name="mode"><?php foreach (mk_modes() as $k => $lb): ?><option value="<?= $k ?>" <?= ($fr['mode'] ?? '') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
            <?php if (mimg_col($db, 'meta_rules')): ?>
            <div class="sm:col-span-2"><label>সাথে একটা ছবি (ঐচ্ছিক)</label><select name="image_id"><option value="0">— ছবি নেই —</option><?php foreach (mimg_all($db, false) as $iid => $im): ?><option value="<?= (int) $iid ?>" <?= (int) ($fr['image_id'] ?? 0) === (int) $iid ? 'selected' : '' ?>><?= e((string) $im['title']) ?><?= $im['is_active'] ? '' : ' (বন্ধ)' ?></option><?php endforeach; ?></select>
                <p class="mk-hint">লেখার পরে ছবিটাও যাবে। নতুন ছবি আগে <a href="<?= e(mk_back('images')) ?>" class="text-indigo-600 font-semibold">🖼️ ছবি</a> ট্যাবে রাখুন।</p></div>
            <?php endif; ?>
            <div><label>ক্রম (ছোট সংখ্যা আগে দেখা হয়)</label><input type="number" name="sort_order" min="0" max="9999" value="<?= (int) ($fr['sort_order'] ?? 0) ?>"></div>
            <div><label>&nbsp;</label><label style="font-weight:600;display:flex;gap:.5rem;align-items:center;margin:0"><input type="checkbox" name="is_active" value="1" <?= !empty($fr['is_active']) ? 'checked' : '' ?>> চালু</label></div>
        </div>
        <div class="mk-row mt-4">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <a href="<?= e(mk_back('rules')) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a>
        </div>
    </form>
    <?php else: ?>
        <div><a href="<?= e(mk_back('rules', ['new' => 1])) ?>#mk-form" class="mk-btn bg-indigo-600 text-white">➕ নতুন নিয়ম যোগ করুন</a></div>
    <?php endif; ?>

    <?php if (!$rules): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">⚡</p>
            <p class="font-bold text-gray-800">এখনো কোনো নিয়ম নেই</p>
            <p class="text-sm text-gray-500 mt-1">যেমন: "দাম, ফি, কত টাকা" শব্দ এলে উত্তর — "আমাদের চলমান কোর্স: {courses}"।</p>
        </div>
    <?php endif; ?>
    <?php foreach ($rules as $r): ?>
        <div class="mk-card" id="rule-<?= (int) $r['id'] ?>" style="<?= $r['is_active'] ? '' : 'opacity:.6' ?>">
            <div class="mk-row" style="justify-content:space-between">
                <div style="min-width:0;flex:1">
                    <?php foreach (mkb_keywords((string) $r['keywords']) as $k): ?><span class="mk-chip"><?= e($k) ?></span><?php endforeach; ?>
                </div>
                <span class="text-xs font-semibold px-2 py-1 rounded <?= $r['mode'] === 'auto' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' ?>"><?= $r['mode'] === 'auto' ? '🤖 অটো' : '💡 প্রস্তাব' ?></span>
            </div>
            <div class="mk-pre mt-2"><?= e(meta_snippet((string) $r['reply'], 300)) ?></div>
            <?php $rim = mkb_rule_image($db, $r); if ($rim): ?><p class="text-xs font-semibold mt-1" style="color:#047857">🖼️ সাথে ছবি: <?= e((string) $rim['title']) ?></p><?php endif; ?>
            <p class="text-xs text-gray-500 mt-2"><?= e(mk_channels()[$r['channel']] ?? '') ?> · ক্রম <?= (int) $r['sort_order'] ?> · অটো-উত্তর গেছে <?= (int) $r['hits'] ?> বার<?= !$r['is_active'] ? ' · ⏸️ বন্ধ' : '' ?></p>
            <div class="mk-row mt-2">
                <a href="<?= e(mk_back('rules', ['edit' => $r['id']])) ?>#mk-form" class="mk-btn bg-indigo-50 text-indigo-700">✏️ বদলান</a>
                <form method="post" action="<?= e(mk_back('rules', ['action' => 'rule-toggle'])) ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-gray-100 text-gray-700"><?= $r['is_active'] ? '⏸️ বন্ধ' : '▶️ চালু' ?></button></form>
                <form method="post" action="<?= e(mk_back('rules', ['action' => 'delete'])) ?>" onsubmit="return confirmSubmit(this, 'এই নিয়মটা মুছবেন?', 'মুছে ফেলা');"><?= csrf_field() ?><input type="hidden" name="what" value="rule"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ মুছুন</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php elseif ($ready && $tab === 'kb'): ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-row">
        <a href="<?= e(mk_back('kb', ['kind' => 'qa'])) ?>" class="mk-btn <?= $kind === 'qa' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>">❓ প্রশ্নোত্তর (<?= $counts['qa'] ?>)</a>
        <a href="<?= e(mk_back('kb', ['kind' => 'doc'])) ?>" class="mk-btn <?= $kind === 'doc' ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>">📄 ডকুমেন্ট (<?= $counts['doc'] ?>)</a>
    </div>
    <p class="text-sm text-gray-600"><?= $kind === 'qa'
        ? 'অভিভাবকেরা যা যা জিজ্ঞেস করেন আর তার সঠিক উত্তর। মেসেজ/কমেন্ট এলে কাছাকাছি প্রশ্নের উত্তর ইনবক্সে প্রস্তাব হিসেবে আসে; AI-ও এখান থেকে শেখে।'
        : 'শিশুর মেধা বিকাশ, কোর্সের ধাপ, কোন কাজ কীভাবে করতে হয় — এমন বড় লেখা। AI উত্তর লেখার সময় এগুলো পড়ে। প্রতিটা কোর্সের তথ্য ও নিয়ম নিজের ভাষায় লিখুন (যেমন "রেজিস্ট্রেশনের পর কী কী ধাপ", "ভর্তি বন্ধ হলে কী বলতে হবে") — AI প্রতিটা উত্তরের আগে সব ডকুমেন্ট পড়ে নেয়, তাই সেভ করার পরের প্রশ্ন থেকেই মেনে চলে। কোন কোর্সে এখন ভর্তি চলছে আর কোনটা বন্ধ, সেটা AI সাইট থেকে নিজে জানে। ফি বা শুরুর তারিখ ডকুমেন্টে লিখলে, সেগুলো বদলালে ডকুমেন্টও বদলাতে হবে।' ?></p>

    <?php if ($kbPlan):
        $pct = min(100, (int) round($kbPlan['used'] * 100 / max(1, $kbPlan['limit'])));
        $lost = array_filter($kbPlan['docs'], fn($d) => $d['state'] !== 'full'); ?>
    <div class="mk-card" style="<?= ($lost || $kbPlan['qa_dropped']) ? 'border-color:#FCA5A5;background:#FEF2F2' : '' ?>">
        <p class="font-bold text-gray-800">🧠 AI কতটা পড়তে পারছে</p>
        <div style="height:10px;border-radius:99px;background:#E5E7EB;overflow:hidden;margin:8px 0">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct >= 90 ? '#DC2626' : ($pct >= 70 ? '#F59E0B' : '#16A34A') ?>"></div>
        </div>
        <p class="text-sm text-gray-700">জ্ঞানভান্ডার: <?= number_format($kbPlan['used']) ?> / <?= number_format($kbPlan['limit']) ?> অক্ষর (<?= $pct ?>%) — <?= count($kbPlan['docs']) ?>টি ডকুমেন্ট, <?= count($kbPlan['qa']) ?>টি প্রশ্নোত্তর।
            <?= $siteChars ? 'সাথে ওয়েবসাইটের তথ্য ' . number_format($siteChars) . ' অক্ষর (আলাদা হিসাব)।' : '' ?></p>
        <?php if ($lost || $kbPlan['qa_dropped']): ?>
            <p class="text-sm font-bold" style="color:#B91C1C;margin-top:6px">⚠️ জায়গা ভরে গেছে — নিচেরগুলো AI পুরোটা পড়তে পারছে না:</p>
            <ul class="text-sm" style="color:#B91C1C;padding-inline-start:18px;list-style:disc">
                <?php foreach ($lost as $d): ?><li><?= e($d['title']) ?> — <?= $d['state'] === 'cut' ? 'শেষের অংশ কাটা পড়ছে' : 'একদমই পড়া হচ্ছে না' ?></li><?php endforeach; ?>
                <?php if ($kbPlan['qa_dropped']): ?><li><?= $kbPlan['qa_dropped'] ?>টি পুরনো প্রশ্নোত্তর পড়া হচ্ছে না</li><?php endif; ?>
            </ul>
            <p class="mk-hint">অপ্রয়োজনীয় বা পুরনো ডকুমেন্ট/প্রশ্নোত্তর মুছে জায়গা খালি করুন। ডকুমেন্ট সবসময় আগে পড়া হয়, প্রশ্নোত্তর পরে।</p>
        <?php else: ?>
            <p class="mk-hint">✅ সব ডকুমেন্ট ও প্রশ্নোত্তর AI পুরোটা পড়ছে।</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($kind === 'doc' && $seeds):
        $seedNew = array_filter($seeds, fn($x) => $x['state'] === 'new'); ?>
    <div class="mk-card">
        <p class="font-bold text-gray-800">📥 তৈরি করা কোর্সের তথ্য (<?= count($seeds) ?>টি কোর্স)</p>
        <p class="mk-hint">প্রতিটা কোর্সের পুরো তথ্য আর সাধারণ প্রশ্নোত্তর আগে থেকে সাজানো আছে। বসানোর পর নিচের তালিকা থেকে যেকোনোটা খুলে বদলাতে পারবেন।</p>
        <ul class="text-sm text-gray-700" style="margin:8px 0;display:grid;gap:4px">
            <?php foreach ($seeds as $sd): ?>
            <li class="mk-row" style="justify-content:space-between;gap:8px">
                <span class="min-w-0" style="overflow-wrap:anywhere"><?= ['have' => '✅', 'new' => '🆕', 'changed' => '🔄', 'removed' => '🗑️'][$sd['state']] ?? '' ?> <?= e($sd['title']) ?><?php if ($sd['state'] === 'changed'): ?><br><span class="mk-hint">আপনার বসানো লেখাটা নতুন সংস্করণ থেকে আলাদা (নতুন সংস্করণে কিছু তথ্য বদলেছে, অথবা আপনি নিজে বদলেছেন)।</span><?php endif; ?></span>
                <?php if ($sd['state'] === 'changed'): ?>
                <form method="post" action="<?= e(mk_back('kb', ['kind' => 'doc', 'action' => 'seed-update'])) ?>" style="flex:0 0 auto" onsubmit="return confirmSubmit(this, 'নতুন সংস্করণ বসালে এই ডকুমেন্টে আপনার নিজের করা বদল (যদি থাকে) মুছে যাবে। বসাবেন?', 'নতুন সংস্করণ');">
                    <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($sd['key']) ?>">
                    <button type="submit" class="mk-btn bg-indigo-600 text-white">↻ নতুন সংস্করণ বসান</button>
                </form>
                <?php endif; ?>
                <?php if ($sd['state'] === 'removed'): ?>
                <form method="post" action="<?= e(mk_back('kb', ['kind' => 'doc', 'action' => 'seed-restore'])) ?>" style="flex:0 0 auto">
                    <?= csrf_field() ?><input type="hidden" name="key" value="<?= e($sd['key']) ?>">
                    <button type="submit" class="mk-btn bg-gray-100 text-gray-700">↩ ফিরিয়ে আনুন</button>
                </form>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($seedNew): ?>
        <form method="post" action="<?= e(mk_back('kb', ['kind' => 'doc', 'action' => 'seed-import'])) ?>">
            <?= csrf_field() ?>
            <button type="submit" class="mk-btn bg-indigo-600 text-white">📥 <?= count($seedNew) ?>টি কোর্সের তথ্য এক চাপে বসান</button>
        </form>
        <?php else: ?>
            <p class="mk-hint">✅ সবগুলো বসানো আছে।</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($kind === 'qa'):
        $fq = $qaOld ?: ($editRow ?: ['question' => mb_substr(trim((string) ($_GET['ask'] ?? '')), 0, 2000), 'answer' => '']); ?>
    <form method="post" action="<?= e(mk_back('kb', ['kind' => 'qa', 'action' => 'qa-save'])) ?>" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fq['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fq['id']) ? '✏️ প্রশ্নোত্তর বদলান' : '➕ নতুন প্রশ্নোত্তর' ?></p>
        <label>প্রশ্ন (একই প্রশ্ন নানাভাবে লিখতে চাইলে প্রতি লাইনে একটা)</label>
        <textarea name="question" rows="2" required><?= e((string) $fq['question']) ?></textarea>
        <label>উত্তর</label>
        <textarea name="answer" rows="4" required maxlength="4000"><?= e((string) $fq['answer']) ?></textarea>
        <p class="mk-hint">উত্তরে <span class="mk-var">{name}</span> · <span class="mk-var">{courses}</span> · <span class="mk-var">{site}</span> · <span class="mk-var">{whatsapp}</span> বসানো যায়।</p>
        <?php if (mkb_has_channel($db)): ?>
        <label>কোথায় ব্যবহার হবে</label>
        <?= mkb_channel_radios((string) ($fq['channel'] ?? 'both')) ?>
        <?php endif; ?>
        <div class="mk-row mt-3">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <?php if (!empty($fq['id'])): ?><a href="<?= e(mk_back('kb', ['kind' => 'qa'])) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a><?php endif; ?>
        </div>
    </form>
    <?php else:
        $fd = $editRow ?: ['title' => '', 'answer' => '']; ?>
    <form method="post" action="<?= e(mk_back('kb', ['kind' => 'doc', 'action' => 'doc-save'])) ?>" enctype="multipart/form-data" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) ($fd['id'] ?? 0) ?>">
        <p class="font-bold text-gray-800"><?= !empty($fd['id']) ? '✏️ ডকুমেন্ট বদলান' : '➕ নতুন ডকুমেন্ট' ?></p>
        <label>শিরোনাম</label>
        <input type="text" name="title" maxlength="190" value="<?= e((string) $fd['title']) ?>" placeholder="যেমন: ৩-৫ বছরের শিশুর মনোযোগ বাড়ানোর ধাপ">
        <?php if (empty($fd['id'])): ?>
        <label>ফাইল থেকে (.txt, .md, .docx — ২ MB পর্যন্ত)</label>
        <input type="file" name="doc_file" accept=".txt,.md,.docx" style="max-width:100%">
        <p class="mk-hint">ফাইল দিলে নিচের বাক্স লাগবে না। PDF হলে লেখাটা কপি করে নিচে পেস্ট করুন।</p>
        <?php endif; ?>
        <label>অথবা লেখা পেস্ট করুন</label>
        <textarea name="body" rows="10"><?= e((string) $fd['answer']) ?></textarea>
        <p class="mk-hint">একটা ডকুমেন্টে সর্বোচ্চ <?= number_format(MKB_DOC_MAX_CHARS) ?> অক্ষর।</p>
        <p class="mk-hint">📋 <b>প্রস্তুত বার্তা:</b> কেউ কোর্সের নাম লিখলে বা বিস্তারিত জানতে চাইলে AI যেন আপনার সাজানো লেখাটা (ইমোজি, লাইন সহ) হুবহু পাঠায় — তাহলে লেখাটার আগে আলাদা লাইনে <code><?= e(MAI_READY_START) ?></code> আর পরে আলাদা লাইনে <code><?= e(MAI_READY_END) ?></code> লিখুন। প্রস্তুত বার্তা <?= number_format(MAI_READY_MAX) ?> অক্ষরের মধ্যে রাখুন (মেসেঞ্জারের সীমা)।</p>
        <div class="mk-row mt-3">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <?php if (!empty($fd['id'])): ?><a href="<?= e(mk_back('kb', ['kind' => 'doc'])) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>

    <form method="get" id="mkKbSearch" class="mk-card mk-f mk-row" style="padding:.75rem">
        <input type="hidden" name="tab" value="kb"><input type="hidden" name="kind" value="<?= e($kind) ?>">
        <input type="search" name="q" value="<?= e($q) ?>" data-autosearch="mkKbSearch" class="list-search" style="flex:1;min-width:0" placeholder="খুঁজুন…">
        <button type="submit" class="mk-btn bg-indigo-600 text-white">🔍 খুঁজুন</button>
    </form>

    <?php if (!$kbRows): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center"><p class="font-bold text-gray-800"><?= $q !== '' ? 'কিছু পাওয়া যায়নি' : 'এখনো কিছু রাখা হয়নি' ?></p></div>
    <?php endif; ?>
    <?php foreach ($kbRows as $r): ?>
        <div class="mk-card" id="kb-<?= (int) $r['id'] ?>">
            <?php if ($kind === 'qa'): ?>
                <?php if (mkb_has_channel($db)): ?><div class="mb-1"><?= mkb_channel_chip($r['channel'] ?? 'both') ?></div><?php endif; ?>
                <p class="font-bold text-gray-800" style="overflow-wrap:anywhere">❓ <?= e((string) $r['question']) ?></p>
                <div class="mk-pre mt-1"><?= e(meta_snippet((string) $r['answer'], 400)) ?></div>
            <?php else: ?>
                <p class="font-bold text-gray-800" style="overflow-wrap:anywhere">📄 <?= e((string) $r['title']) ?></p>
                <p class="text-xs text-gray-500"><?= number_format(mb_strlen((string) $r['answer'])) ?> অক্ষর</p>
                <?php foreach (mai_ready_blocks((string) $r['answer']) as $rb): $rbn = mb_strlen($rb); ?>
                    <p class="text-xs mt-1" style="color:<?= $rbn > MAI_READY_MAX ? '#B91C1C' : '#047857' ?>">📋 প্রস্তুত বার্তা আছে (<?= number_format($rbn) ?> অক্ষর)<?= $rbn > MAI_READY_MAX ? ' — ⚠️ বেশি লম্বা, মেসেঞ্জারে শেষটা কেটে যাবে; ' . number_format(MAI_READY_MAX) . ' অক্ষরের মধ্যে আনুন' : ' — কেউ এই কোর্স সম্পর্কে জানতে চাইলে AI এটাই হুবহু পাঠাবে' ?></p>
                <?php endforeach; ?>
                <div class="mk-pre mt-1 text-gray-600"><?= e(meta_snippet((string) $r['answer'], 220)) ?></div>
            <?php endif; ?>
            <p class="text-xs text-gray-400 mt-2"><?= $r['source'] === 'message' ? '💬 মেসেজের উত্তর থেকে শেখা' : ($r['source'] === 'comment' ? '🗨️ কমেন্টের উত্তর থেকে শেখা' : 'হাতে লেখা') ?><?= $r['approved_by'] ? ' · ' . e($r['approved_by']) : '' ?></p>
            <div class="mk-row mt-2">
                <a href="<?= e(mk_back('kb', ['kind' => $kind, 'edit' => $r['id']])) ?>#mk-form" class="mk-btn bg-indigo-50 text-indigo-700">✏️ বদলান</a>
                <form method="post" action="<?= e(mk_back('kb', ['kind' => $kind, 'action' => 'delete'])) ?>" onsubmit="return confirmSubmit(this, 'এটা জ্ঞানভান্ডার থেকে মুছবেন?', 'মুছে ফেলা');"><?= csrf_field() ?><input type="hidden" name="what" value="kb"><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ মুছুন</button></form>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<?php elseif ($ready && $tab === 'images'): $fi = $editRow ?: ['id' => 0, 'title' => '', 'when_text' => '', 'is_active' => 1, 'sort_order' => 0, 'file_path' => '']; ?>
<div class="grid gap-4" style="max-width:900px">
    <?php if (!mimg_ready($db)): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 text-sm">
        <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
        <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-meta-images.sql</code> চালান। ততক্ষণ ইনবক্স থেকে ফোনের ছবি পাঠানো যায়, কিন্তু সংগ্রহ, নিয়মের ছবি আর AI-র ছবি কাজ করে না।</p>
    </div>
    <?php else: ?>
    <div class="mk-card">
        <p class="font-bold text-gray-800">🖼️ ছবির সংগ্রহ</p>
        <p class="mk-hint">যেসব ছবি বারবার পাঠাতে হয় (ওয়ার্কশিটের নমুনা, রুটিন, ফি চার্ট, বিকাশের QR) এখানে রাখুন। তিন জায়গা থেকে যায়: ইনবক্সে "🖼️ ছবি যোগ করুন", নিয়মের সাথে জোড়া, আর AI — "কখন পাঠাবেন" ঘরটা পড়ে নিজে বেছে নেয় (খসড়া মোডে আপনি দেখে পাঠান)।</p>
        <p class="mk-hint" style="color:#B45309">🔴 এই ছবিগুলো ওয়েবসাইটে পাবলিক থাকে (ফেসবুক সেখান থেকে তুলে নেয়) — কারও ব্যক্তিগত ছবি বা তথ্য এখানে রাখবেন না।</p>
    </div>
    <form method="post" action="<?= e(mk_back('images', ['action' => 'img-save'])) ?>" enctype="multipart/form-data" class="mk-card mk-f" id="mk-form">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $fi['id'] ?>">
        <p class="font-bold text-gray-800"><?= $fi['id'] ? '✏️ ছবির তথ্য বদলান' : '➕ নতুন ছবি' ?></p>
        <label>ছবির নাম</label>
        <input type="text" name="title" maxlength="190" required value="<?= e((string) $fi['title']) ?>" placeholder="যেমন: প্লে গ্রুপের ওয়ার্কশিটের নমুনা">
        <label>কখন পাঠাবেন? (AI এটা পড়ে বেছে নেয়)</label>
        <textarea name="when_text" rows="2" maxlength="1000" placeholder="যেমন: কেউ প্লে গ্রুপের ওয়ার্কশিট দেখতে চাইলে বা কী শেখানো হয় জানতে চাইলে"><?= e((string) $fi['when_text']) ?></textarea>
        <?php if ($fi['id']): ?><img src="<?= e(admin_image_src((string) $fi['file_path'])) ?>" alt="" style="max-width:160px;max-height:160px;border-radius:.6rem;margin-top:.6rem;object-fit:contain"><?php endif; ?>
        <label><?= $fi['id'] ? 'ছবি বদলাতে চাইলে নতুন ছবি দিন' : 'ছবি' ?> (JPG/PNG/GIF, <?= MIMG_MAX_MB ?> MB পর্যন্ত)</label>
        <input type="file" name="image_file" accept="image/jpeg,image/png,image/gif,image/webp" <?= $fi['id'] ? '' : 'required' ?> style="max-width:100%">
        <div class="grid sm:grid-cols-2" style="column-gap:1rem">
            <div><label>ক্রম</label><input type="number" name="sort_order" min="0" max="9999" value="<?= (int) $fi['sort_order'] ?>"></div>
            <div><label>&nbsp;</label><label style="font-weight:600;display:flex;gap:.5rem;align-items:center;margin:0"><input type="checkbox" name="is_active" value="1" <?= !empty($fi['is_active']) ? 'checked' : '' ?>> চালু (বন্ধ থাকলে কোথাও যায় না)</label></div>
        </div>
        <div class="mk-row mt-4">
            <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            <?php if ($fi['id']): ?><a href="<?= e(mk_back('images')) ?>" class="mk-btn bg-gray-100 text-gray-700">বাতিল</a><?php endif; ?>
        </div>
    </form>
    <?php if (!$images): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center"><p class="text-4xl mb-2">🖼️</p><p class="font-bold text-gray-800">এখনো কোনো ছবি রাখা হয়নি</p></div>
    <?php endif; ?>
    <?php foreach ($images as $iid => $im): ?>
        <div class="mk-card" id="img-<?= (int) $iid ?>" style="<?= $im['is_active'] ? '' : 'opacity:.6' ?>">
            <div class="mk-row" style="align-items:flex-start;flex-wrap:nowrap">
                <a href="<?= e(admin_image_src((string) $im['file_path'])) ?>" target="_blank" rel="noopener" style="flex:0 0 auto"><img src="<?= e(admin_image_src((string) $im['file_path'])) ?>" alt="" loading="lazy" style="width:88px;height:88px;object-fit:cover;border-radius:.7rem;border:1px solid #E5E7EB"></a>
                <div style="min-width:0;flex:1">
                    <p class="font-bold text-gray-800" style="overflow-wrap:anywhere"><?= e((string) $im['title']) ?></p>
                    <?php if (trim((string) $im['when_text']) !== ''): ?><p class="text-sm text-gray-600 mt-1" style="overflow-wrap:anywhere">কখন: <?= e((string) $im['when_text']) ?></p><?php endif; ?>
                    <p class="text-xs text-gray-500 mt-1">পাঠানো হয়েছে <?= (int) $im['uses'] ?> বার<?= $im['is_active'] ? '' : ' · ⏸️ বন্ধ' ?></p>
                </div>
            </div>
            <div class="mk-row mt-2">
                <a href="<?= e(mk_back('images', ['edit' => $iid])) ?>#mk-form" class="mk-btn bg-indigo-50 text-indigo-700">✏️ বদলান</a>
                <form method="post" action="<?= e(mk_back('images', ['action' => 'delete'])) ?>" onsubmit="return confirmSubmit(this, 'ছবিটা মুছবেন? যে নিয়মে জোড়া আছে, সেখান থেকেও সরে যাবে।', 'মুছে ফেলা');"><?= csrf_field() ?><input type="hidden" name="what" value="image"><input type="hidden" name="id" value="<?= (int) $iid ?>"><button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ মুছুন</button></form>
            </div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php elseif ($ready && $tab === 'ai'): /* ── 🤖 AI ── */
    $bdt = fn(float $usd) => '৳' . number_format($usd * MAI_BDT_PER_USD, $usd * MAI_BDT_PER_USD < 10 ? 2 : 0);
?>
<div class="grid gap-4" style="max-width:900px">
    <?php if (!$aiReady): ?>
    <div class="bg-amber-50 border border-amber-200 text-amber-800 rounded-2xl p-4 text-sm">
        <p class="font-bold mb-1">⚠️ একটা SQL চালানো বাকি</p>
        <p>phpMyAdmin-এ <code class="bg-white px-1.5 py-0.5 rounded">database/migrate-meta-ai.sql</code> চালান — তার আগ পর্যন্ত AI কাজ করবে না (বাকি সব আগের মতোই চলে)।</p>
    </div>
    <?php endif; ?>

    <div class="mk-card" style="border-left:4px solid <?= $aiReady && mai_has_key() ? '#7C3AED' : '#9CA3AF' ?>">
        <p class="font-bold text-gray-800">🤖 AI দিয়ে উত্তর — কীভাবে কাজ করে</p>
        <ul class="text-sm text-gray-600 mt-2 space-y-1" style="list-style:disc;padding-inline-start:1.2rem">
            <li>নতুন মেসেজ/কমেন্ট এলে AI আপনার <b>জ্ঞানভান্ডার</b> (প্রশ্নোত্তর + ডকুমেন্ট) আর সাইটের <b>টাটকা কোর্স-তথ্য</b> (দাম, ভর্তির শেষ সময়) দেখে উত্তর লেখে।</li>
            <li><b>📝 শুধু খসড়া</b> — উত্তরটা ইনবক্সে "🤖 AI-র খসড়া" হয়ে থাকে, আপনি দেখে এক চাপে বসিয়ে পাঠান। <b>শুরুতে এটাই রাখুন।</b></li>
            <li><b>🤖 নিজে থেকে পাঠাবে</b> — খসড়ায় সন্তুষ্ট হলে চালু করুন। আপনি নিজে উত্তর দিলে <?= MKB_HUMAN_QUIET_MIN ?> মিনিট AI চুপ থাকে, ঘণ্টায় <?= MKB_MAX_AUTO_PER_HOUR ?>টার বেশি নয়।</li>
            <li>AI উত্তর না জানলে, বা অভিযোগ/পেমেন্ট সমস্যা হলে কিছুই পাঠায় না — ইনবক্সে "🤖 AI এর উত্তর জানে না" চিহ্ন থাকে। শুধু ধন্যবাদ/ইমোজি কমেন্টে AI ডাকা হয় না (খরচ বাঁচাতে)।</li>
            <li>🔴 মাসের খরচ সীমা পেরোলে AI নিজে থেকে থেমে যায়।</li>
        </ul>
    </div>

    <?php if ($aiStats !== null): ?>
    <div class="mk-card">
        <p class="font-bold text-gray-800">📊 এই মাস (<?= e(date('F Y')) ?>)</p>
        <div class="mk-row mt-2" style="gap:1rem">
            <div><p class="text-xs text-gray-500">খরচ</p><p class="text-lg font-bold text-gray-800">$<?= number_format($aiStats['cost'], 3) ?> <span class="text-sm font-normal text-gray-500">(≈ <?= e($bdt($aiStats['cost'])) ?>)</span></p></div>
            <div><p class="text-xs text-gray-500">সীমা</p><p class="text-lg font-bold text-gray-800">$<?= number_format($aiCfg['cap'], 2) ?></p></div>
            <div><p class="text-xs text-gray-500">AI ডাক</p><p class="text-lg font-bold text-gray-800"><?= (int) $aiStats['calls'] ?></p></div>
            <div><p class="text-xs text-gray-500">নিজে পাঠিয়েছে</p><p class="text-lg font-bold text-gray-800"><?= (int) $aiStats['sent'] ?></p></div>
            <div><p class="text-xs text-gray-500">জানত না</p><p class="text-lg font-bold text-gray-800"><?= (int) $aiStats['human'] ?></p></div>
            <div><p class="text-xs text-gray-500">সমস্যা</p><p class="text-lg font-bold <?= $aiStats['error'] ? 'text-red-600' : 'text-gray-800' ?>"><?= (int) $aiStats['error'] ?></p></div>
        </div>
        <?php if ($aiStats['cost'] >= $aiCfg['cap']): ?><p class="text-sm font-semibold text-red-700 mt-2">⛔ এই মাসের সীমা শেষ — AI আর ডাকা হচ্ছে না। সীমা বাড়ালে আবার চলবে।</p><?php endif; ?>
        <p class="mk-hint mt-2">টাকার অঙ্ক আনুমানিক (১ ডলার ≈ ৳<?= MAI_BDT_PER_USD ?>)। আসল বিল console.anthropic.com-এ।</p>
    </div>
    <?php endif; ?>

    <div class="mk-card mk-f">
        <p class="font-bold text-gray-800">⚙️ সেটিংস</p>
        <?php if ($isSuper): ?>
        <form method="post" action="<?= e(mk_back('ai', ['action' => 'ai-save'])) ?>" autocomplete="off" id="mkAiForm">
            <?= csrf_field() ?>
            <label>Anthropic API key <?= $aiCfg['key'] !== '' ? '<span class="text-green-700">— ✅ বসানো আছে (…' . e(substr($aiCfg['key'], -4)) . ')</span>' : '' ?></label>
            <input type="password" name="ai_key" placeholder="<?= $aiCfg['key'] !== '' ? '•••••••• (খালি রাখলে আগেরটাই থাকবে)' : 'sk-ant-…' ?>" autocomplete="new-password">
            <p class="mk-hint">console.anthropic.com → API Keys → Create Key। 🔴 কাউকে (চ্যাটেও) পাঠাবেন না — শুধু এখানে বসান। সেখানে Billing-এ অল্প টাকা রিচার্জ ও মাসিক Limit দিয়ে রাখুন।</p>
            <label>মডেল</label>
            <select name="ai_model">
                <?php foreach (mai_models() as $id => $m): ?>
                    <option value="<?= e($id) ?>" <?= $aiCfg['model'] === $id ? 'selected' : '' ?>><?= e($m[0]) ?> — $<?= rtrim(rtrim(number_format($m[1], 2), '0'), '.') ?>/$<?= rtrim(rtrim(number_format($m[2], 2), '0'), '.') ?> প্রতি ১০ লাখ টোকেন</option>
                <?php endforeach; ?>
            </select>
            <div class="grid gap-3 sm:grid-cols-2">
                <div><label>💬 মেসেজে</label>
                    <select name="mode_message" data-ai-mode><?php foreach (mai_modes() as $k => $lb): ?><option value="<?= $k ?>" <?= mai_mode('message') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
                <div><label>🗨️ কমেন্টে</label>
                    <select name="mode_comment" data-ai-mode><?php foreach (mai_modes() as $k => $lb): ?><option value="<?= $k ?>" <?= mai_mode('comment') === $k ? 'selected' : '' ?>><?= e($lb) ?></option><?php endforeach; ?></select></div>
            </div>
            <label>মাসে সর্বোচ্চ খরচ (ডলার)</label>
            <input type="number" name="ai_cap" min="0" max="1000" step="0.5" value="<?= e((string) $aiCfg['cap']) ?>">
            <p class="mk-hint">≈ <?= e($bdt($aiCfg['cap'])) ?>। সীমা পেরোলে AI নিজে থেকে থেমে যায়, মাস বদলালে আবার চলে।</p>
            <label class="mk-check" style="display:flex;gap:8px;align-items:flex-start;margin-top:12px;font-weight:600">
                <input type="checkbox" name="site_info" value="1" <?= mai_site_info_on() ? 'checked' : '' ?> style="margin-top:4px;flex:0 0 auto">
                <span>🌐 ওয়েবসাইটের পাবলিক তথ্য থেকেও উত্তর দেবে (কোর্সের বিবরণ, ওয়ার্কশিট, প্রোডাক্ট, FAQ, চালু নোটিশ, যোগাযোগ)</span>
            </label>
            <p class="mk-hint">সাইটে যা বদলাবেন, AI নিজে থেকেই সেটা জানবে। শুধু পাবলিক সাইটে যা দেখা যায় তাই যায় — কোনো অভিভাবকের নাম, ফোন বা অর্ডার কখনো নয়।</p>
            <label>AI-কে নির্দেশনা (কীভাবে কথা বলবে)</label>
            <textarea name="ai_persona" rows="4" placeholder="<?= e(mai_default_persona()) ?>"><?= e((string) get_setting('meta_ai_persona')) ?></textarea>
            <p class="mk-hint">খালি রাখলে ধূসর লেখাটাই ব্যবহার হবে। দাম/তারিখ এখানে লিখবেন না — ওগুলো সবসময় সাইট থেকে টাটকা নেওয়া হয়। "না জানলে চুপ থাকা", "দাম বানিয়ে না বলা" নিয়মগুলো আগে থেকেই বসানো, বদলানো যায় না।</p>
            <div class="mk-row mt-3">
                <button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button>
            </div>
        </form>
        <?php if (mai_site_info_on()): $siteTxt = mai_site_info_text($db); ?>
        <details class="mk-card" style="margin-top:12px">
            <summary class="font-bold text-gray-800" style="cursor:pointer">🌐 AI ওয়েবসাইট থেকে যা দেখছে (<?= number_format(mb_strlen($siteTxt)) ?> অক্ষর)</summary>
            <pre style="white-space:pre-wrap;overflow-wrap:anywhere;font-family:inherit;font-size:.82rem;margin-top:8px;max-height:420px;overflow:auto"><?= e($siteTxt !== '' ? $siteTxt : 'সাইটে এখনো কোনো সক্রিয় তথ্য নেই।') ?></pre>
            <p class="mk-hint">এর সাথে প্রতিবার টাটকা যায়: কোন কোর্সে এখন ভর্তি চলছে আর কোনটা বন্ধ। ভুল কিছু দেখলে সাইটের ঐ কোর্স/পাতা ঠিক করুন — AI নিজে থেকেই নতুনটা দেখবে।</p>
        </details>
        <?php endif; ?>
        <?php if ($aiCfg['key'] !== ''): ?>
        <form method="post" action="<?= e(mk_back('ai', ['action' => 'ai-save'])) ?>" class="mt-3" onsubmit="return confirmSubmit(this, 'চাবি মুছলে AI সম্পূর্ণ বন্ধ হয়ে যাবে। মুছবেন?', 'চাবি মুছুন');">
            <?= csrf_field() ?><input type="hidden" name="clear_key" value="1">
            <button type="submit" class="mk-btn bg-red-50 text-red-700">🗑️ চাবি মুছে AI বন্ধ করুন</button>
        </form>
        <?php endif; ?>
        <?php else: ?>
            <ul class="text-sm text-gray-700 mt-2 space-y-1">
                <li>চাবি: <?= $aiCfg['key'] !== '' ? '✅ বসানো আছে' : '⬜ বসানো হয়নি' ?></li>
                <li>মডেল: <?= e(mai_models()[$aiCfg['model']][0]) ?></li>
                <li>💬 মেসেজে: <?= e(mai_modes()[mai_mode('message')]) ?> · 🗨️ কমেন্টে: <?= e(mai_modes()[mai_mode('comment')]) ?></li>
            </ul>
            <p class="text-xs text-gray-500 mt-2">এই সেটিংস বদলানো শুধু মূল অ্যাডমিন পারেন।</p>
        <?php endif; ?>
    </div>

    <form method="post" action="<?= e(mk_back('ai', ['action' => 'ai-test'])) ?>" class="mk-card mk-f" id="mk-ai-test">
        <?= csrf_field() ?>
        <p class="font-bold text-gray-800">🧪 AI-কে প্রশ্ন করে দেখুন <span class="text-xs font-normal text-gray-500">(ফেসবুকে কিছুই যায় না — শুধু একটা ডাকের খরচ হয়)</span></p>
        <div class="mk-row mt-2">
            <input type="text" name="q" value="<?= e((string) ($aiTest['q'] ?? '')) ?>" placeholder="যেমন: ৪ বছরের বাচ্চার জন্য কোন কোর্স?" style="flex:1;min-width:180px" required maxlength="1000">
            <select name="ch" style="width:auto"><option value="message">মেসেজ হিসেবে</option><option value="comment" <?= ($aiTest['ch'] ?? '') === 'comment' ? 'selected' : '' ?>>কমেন্ট হিসেবে</option></select>
            <button type="submit" class="mk-btn bg-indigo-600 text-white" <?= $aiReady && mai_has_key() ? '' : 'disabled style="opacity:.5"' ?>>জিজ্ঞেস করুন</button>
        </div>
        <?php if ($aiTest): ?>
            <div class="mt-3 p-3 rounded-xl bg-gray-50 text-sm">
                <?php if (!$aiTest['ok']): ?>
                    <p class="font-semibold text-red-700">❌ <?= e($aiTest['error']) ?></p>
                <?php elseif ($aiTest['human']): ?>
                    <p class="font-semibold text-amber-700">🤖 AI বলছে এর উত্তর তার জানা নেই — এখানে হলে ইনবক্সে "আপনি নিজে উত্তর দিন" চিহ্ন থাকত, কিছুই পাঠানো হতো না।</p>
                <?php else: ?>
                    <p class="text-xs text-gray-500 mb-1">AI-র উত্তর:</p>
                    <div class="mk-pre"><?= e($aiTest['text']) ?></div>
                <?php endif; ?>
                <p class="text-xs text-gray-500 mt-2">এই ডাকের খরচ: $<?= number_format((float) $aiTest['cost'], 4) ?> (≈ <?= e($bdt((float) $aiTest['cost'])) ?>)</p>
            </div>
        <?php endif; ?>
    </form>

    <?php if ($aiLog): ?>
    <div class="mk-card">
        <p class="font-bold text-gray-800 mb-2">🕘 সাম্প্রতিক AI ডাক</p>
        <div class="grid gap-2">
        <?php foreach ($aiLog as $l):
            $ol = ['ok' => ['✅ উত্তর লিখেছে', 'text-green-700'], 'human' => ['🙋 জানত না', 'text-amber-700'], 'refusal' => ['🙋 জানত না', 'text-amber-700'], 'error' => ['❌ সমস্যা', 'text-red-700']][$l['outcome']] ?? [$l['outcome'], 'text-gray-700'];
            $kl = ['draft' => 'খসড়া', 'auto' => 'নিজে থেকে', 'manual' => 'বোতাম চেপে', 'test' => 'পরীক্ষা'][$l['kind']] ?? $l['kind'];
        ?>
            <div class="text-sm" style="border-bottom:1px solid #F3F4F6;padding-bottom:.4rem;overflow-wrap:anywhere">
                <span class="<?= $ol[1] ?> font-semibold"><?= e($ol[0]) ?></span>
                <span class="text-gray-500"> · <?= $l['channel'] === 'comment' ? 'কমেন্ট' : 'মেসেজ' ?> · <?= e($kl) ?> · <?= e(date('d M, h:i A', strtotime((string) $l['created_at']))) ?> · $<?= number_format((float) $l['cost_usd'], 4) ?></span>
                <?php if ($l['error']): ?><div class="text-xs text-red-600"><?= e($l['error']) ?></div><?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<script>
(function () {
    var f = document.getElementById('mkAiForm');
    if (!f) { return; }
    var orig = {};
    f.querySelectorAll('select[data-ai-mode]').forEach(function (s) { orig[s.name] = s.value; });
    f.addEventListener('submit', function (ev) {
        if (f.dataset.ok === '1') { return; }
        var turnsAuto = false;
        f.querySelectorAll('select[data-ai-mode]').forEach(function (s) { if (s.value === 'auto' && orig[s.name] !== 'auto') { turnsAuto = true; } });
        if (!turnsAuto) { return; }
        ev.preventDefault();
        showConfirmModal('"নিজে থেকে পাঠাবে" চালু করলে AI আপনার দেখা ছাড়াই ফেসবুকে উত্তর পাঠাবে। কয়েকদিন খসড়া দেখে সন্তুষ্ট হয়েছেন তো?', function () { f.dataset.ok = '1'; f.submit(); }, 'AI নিজে থেকে পাঠাবে');
    });
    var t = document.getElementById('mk-ai-test');
    if (t) { t.addEventListener('submit', function () { var b = t.querySelector('button[type=submit]'); setTimeout(function () { b.disabled = true; b.textContent = 'লিখছে…'; }, 0); }); }
})();
</script>

<?php elseif ($ready && $tab === 'report' && $report): /* ── 📊 রিপোর্ট (ধাপ ৪) ── */
    $rt = mai_comment_types(); $rai = $report['ai']; $rdr = $report['drafts']; ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-row">
        <?php foreach ([7 => 'শেষ ৭ দিন', 30 => 'শেষ ৩০ দিন'] as $d => $lb): ?>
            <a href="<?= e(mk_back('report', ['days' => $d])) ?>" class="mk-btn <?= $report['days'] === $d ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700' ?>"><?= $lb ?></a>
        <?php endforeach; ?>
    </div>

    <div class="mk-card">
        <p class="font-bold text-gray-800 mb-1">🗨️ কমেন্টের ধরন <span class="text-sm font-normal text-gray-500">(মোট <?= (int) $report['total'] ?>টি)</span></p>
        <p class="mk-hint mb-2">ধরন সাধারণ নিয়মে আন্দাজ করা — AI ডাকা হয় না, খরচ নেই। চাপলে ইনবক্সে ঐ ধরনের কমেন্টগুলো খুলবে।</p>
        <div class="mk-rep-grid">
            <?php foreach ($rt as $tk => $tv): ?>
                <a href="<?= e('meta-inbox.php?' . http_build_query(['tab' => 'comments', 'st' => 'all', 'ct' => $tk])) ?>" class="mk-rep-box" style="background:<?= $tv[1] ?>;color:<?= $tv[2] ?>">
                    <b><?= (int) ($report['types'][$tk] ?? 0) ?></b><span><?= e($tv[0]) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
        <p class="mk-hint mt-2">❤️ প্রশংসা ও 🚫 স্প্যামে AI ডাকা হয় না। ⚠️ অভিযোগে AI নিজে লেখে না — ইনবক্সে "নিজে দেখে উত্তর দিন" চিহ্ন বসে।</p>
    </div>

    <?php if ($report['ai_ready']): ?>
    <div class="mk-card">
        <p class="font-bold text-gray-800 mb-2">🤖 AI কী করল</p>
        <div class="mk-rep-grid">
            <div class="mk-rep-box"><b><?= (int) $rai['calls'] ?></b><span>বার AI ডাকা হয়েছে</span></div>
            <div class="mk-rep-box"><b><?= (int) $rai['sent'] ?></b><span>উত্তর নিজে পাঠিয়েছে</span></div>
            <div class="mk-rep-box"><b><?= (int) $rdr['created'] ?></b><span>খসড়া লিখেছে (ব্যবহার <?= (int) $rdr['used'] ?> · বাদ <?= (int) $rdr['dismissed'] ?>)</span></div>
            <div class="mk-rep-box"><b><?= (int) $rai['human'] ?></b><span>বার উত্তর জানত না</span></div>
            <div class="mk-rep-box"><b><?= (int) $rdr['complaints'] ?></b><span>অভিযোগ — আপনার দেখার জন্য</span></div>
            <div class="mk-rep-box"><b>$<?= number_format((float) $rai['cost'], 2) ?></b><span>খরচ (≈৳<?= number_format((float) $rai['cost'] * MAI_BDT_PER_USD, 0) ?>)<?= $rai['error'] ? ' · সমস্যা ' . (int) $rai['error'] . 'বার' : '' ?></span></div>
        </div>
    </div>

    <div class="mk-card">
        <p class="font-bold text-gray-800 mb-1">❓ যেসব প্রশ্নের উত্তর AI জানত না</p>
        <p class="mk-hint mb-2">একই প্রশ্ন বারবার এলে একসাথে গোনা হয়েছে, বেশিবারেরটা আগে। <b>➕ উত্তর শিখিয়ে দিন</b> চাপলে প্রশ্নটা বসানো অবস্থায় জ্ঞানভান্ডারের ফর্ম খুলবে — আপনি উত্তর লিখে সংরক্ষণ করলে পরের বার AI জানবে। (দাম/ব্যাচ/শেষ সময় লিখবেন না — ওগুলো সাইট থেকে টাটকা নেওয়া হয়।)</p>
        <?php if (!$report['unknown']): ?>
            <p class="text-sm text-green-700 font-semibold">✅ এই সময়ে এমন কোনো প্রশ্ন নেই।</p>
        <?php endif; ?>
        <?php foreach ($report['unknown'] as $u): ?>
            <div class="mk-rep-q">
                <div style="flex:1;min-width:0">
                    <p class="text-gray-800" style="overflow-wrap:anywhere;white-space:pre-wrap"><?= e(meta_snippet($u['q'], 300)) ?></p>
                    <p class="text-xs text-gray-500 mt-1"><?= $u['channel'] === 'comment' ? '🗨️ কমেন্ট' : '💬 মেসেজ' ?> · <?= (int) $u['n'] ?> বার · শেষ <?= e(date('d M, h:i A', strtotime($u['last']))) ?></p>
                </div>
                <a href="<?= e(mk_back('kb', ['kind' => 'qa', 'ask' => mb_substr($u['q'], 0, 1500)]) . '#mk-form') ?>" class="mk-btn bg-indigo-600 text-white" style="flex-shrink:0">➕ উত্তর শিখিয়ে দিন</a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="mk-card"><p class="text-sm text-gray-600">🤖 AI-র অংশ দেখাতে database/migrate-meta-ai.sql চালাতে হবে।</p></div>
    <?php endif; ?>

    <form method="post" action="<?= e(mk_back('report', ['action' => 'thanks-save'])) ?>" class="mk-card mk-f" id="mk-thanks">
        <?= csrf_field() ?>
        <p class="font-bold text-gray-800">❤️ প্রশংসার জবাব</p>
        <p class="mk-hint">ইনবক্সে প্রশংসার কমেন্টের পাশে "❤️ ধন্যবাদ জানান" চাপলে এই লেখাটা উত্তরের ঘরে বসে (নিজে পাঠায় না — আপনি দেখে পাঠান)। খালি রাখলে তৈরি লেখাটা ব্যবহার হবে।</p>
        <textarea name="thanks" rows="2" maxlength="500" placeholder="<?= e(mai_thanks_text()) ?>"><?= e((string) get_setting('meta_thanks_text')) ?></textarea>
        <div class="mk-row mt-3"><button type="submit" class="mk-btn bg-indigo-600 text-white">💾 সংরক্ষণ করুন</button></div>
    </form>
</div>


<?php elseif ($ready): /* ── শেখার প্রস্তাব ── */ ?>
<div class="grid gap-4" style="max-width:900px">
    <div class="mk-card">
        <p class="text-sm text-gray-700">আপনি বা মডারেটর ইনবক্সে (বা Business Suite থেকে) যে উত্তর দেন, আর পুরনো কমেন্টে পেইজ যে উত্তর দিয়েছিল — সেগুলো থেকে প্রশ্ন-উত্তর জোড়া এখানে আসে। <b>✅ শিখে নিন</b> চাপলে তবেই জ্ঞানভান্ডারে যায় (চাইলে আগে লেখা ঠিক করে নিন)। 🔴 অনুমোদন ছাড়া কিছুই শেখা হয় না।</p>
        <?php if ($newScan): ?><p class="text-sm font-semibold text-green-700 mt-2">💡 এইমাত্র নতুন <?= (int) $newScan ?>টি প্রস্তাব পাওয়া গেছে।</p><?php endif; ?>
        <form method="post" action="<?= e(mk_back('learn', ['action' => 'scan'])) ?>" class="mt-3"><?= csrf_field() ?><button type="submit" class="mk-btn bg-gray-100 text-gray-700">🔄 আবার খুঁজে দেখুন</button></form>
    </div>
    <?php if (!$proposals): ?>
        <div class="empty-state bg-white rounded-2xl shadow p-8 text-center">
            <p class="text-4xl mb-2">💡</p>
            <p class="font-bold text-gray-800">অপেক্ষায় কোনো প্রস্তাব নেই</p>
            <p class="text-sm text-gray-500 mt-1">ইনবক্স → সংযোগ ট্যাবে "পুরনো মেসেজ/কমেন্ট আনুন" চাপলে আগের উত্তরগুলো থেকেও প্রস্তাব আসবে।</p>
        </div>
    <?php endif; ?>
    <?php foreach ($proposals as $p): ?>
        <div class="mk-card" id="kb-<?= (int) $p['id'] ?>">
            <form method="post" action="<?= e(mk_back('learn', ['action' => 'approve'])) ?>" class="mk-f">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                <div class="mk-row">
                    <?php if ($p['source'] === 'comment'): ?><span class="mk-src" style="background:#FCE7F3;color:#9D174D">💭 কমেন্ট থেকে</span>
                    <?php else: ?><span class="mk-src" style="background:#E0F2FE;color:#075985">💬 ইনবক্স থেকে</span><?php endif; ?>
                    <span class="text-xs text-gray-500"><?= $p['created_by'] ? 'উত্তর দিয়েছিলেন ' . e($p['created_by']) . ' · ' : '' ?><?= e(date('d M Y', strtotime((string) $p['created_at']))) ?></span>
                </div>
                <label>প্রশ্ন (<?= $p['source'] === 'comment' ? 'অভিভাবক কমেন্টে যা লিখেছিলেন' : 'অভিভাবক ইনবক্সে যা লিখেছিলেন' ?>)</label>
                <textarea name="question" rows="2"><?= e((string) $p['question']) ?></textarea>
                <label>উত্তর</label>
                <textarea name="answer" rows="4"><?= e((string) $p['answer']) ?></textarea>
                <p class="mk-hint">নাম, ফোন নম্বর বা একজনের নিজস্ব কথা থাকলে শেখানোর আগে মুছে দিন — এটা সবার জন্য ব্যবহার হবে।</p>
                <?php if (mkb_has_channel($db)): ?>
                <label>শেখার পর কোথায় ব্যবহার হবে</label>
                <?= mkb_channel_radios(mkb_channel_default((string) $p['source'])) ?>
                <p class="mk-hint">যেমন "ইনবক্স চেক করবেন" শুধু কমেন্টের জন্য; দাম/লিংকওয়ালা লম্বা উত্তর সাধারণত শুধু ইনবক্সের জন্য।</p>
                <?php endif; ?>
                <button type="submit" class="mk-btn bg-green-600 text-white mt-3">✅ শিখে নিন</button>
            </form>
            <form method="post" action="<?= e(mk_back('learn', ['action' => 'reject'])) ?>" class="mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $p['id'] ?>"><button type="submit" class="mk-btn bg-gray-100 text-gray-700">✕ বাদ দিন</button></form>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout-bottom.php'; ?>
