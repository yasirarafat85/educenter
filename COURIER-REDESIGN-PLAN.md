# কুরিয়ার কালেকশন/মাল্টি-মাস রিডিজাইন — বিল্ড প্ল্যান ও অগ্রগতি

> এই ফাইলটা একটা **রিজিউমেবল বিল্ড-চেকলিস্ট**। কনটেক্সট শেষ হলে নতুন সেশন এখান থেকে
> ঠিক যেখানে থেমেছে সেখান থেকে শুরু করবে। প্রতিটা ধাপ শেষ হলে `[ ]` → `[x]` করে দিন
> এবং "অগ্রগতি" সেকশনে নোট রাখুন। ব্যবসায়িক প্রেক্ষাপট: `PROJECT-NOTES.md` → "🚧 পরবর্তী বড়
> কাজ: কুরিয়ার কালেকশন/মাল্টি-মাস রিডিজাইন" সেকশন। মোবাইল-ডিজাইন নিয়ম: `CLAUDE.md`।

## ব্যাকআপ (সমস্যা হলে restore)
- DB: `D:\clude_project\educenter-DB-backup-before-courier-20260716.sql` (mysqldump, কাজ শুরুর আগে)
- কোড: `D:\clude_project\educenter-CODE-backup-before-courier-20260716.zip` (uploads/build বাদ)
- **restore**: DB → phpMyAdmin/mysql-এ ইমপোর্ট; কোড → zip এক্সট্রাক্ট করে website/ ফোল্ডারে ওভাররাইট।

## লক্ষ্য (ডেমো v2 অনুযায়ী — এই সেশনে `mcp__visualize` দিয়ে দেখানো ও ইউজার-অনুমোদিত)
একটা কোর্স-ব্যাচের মাসিক পার্সেল পাঠানোর সময় প্রতিটা রেজিস্ট্রেশনের জন্য কার্ড:
নাম + ফেসবুক আইডি নাম + ঠিকানা + রঙিন কাস্টম নোট + সক্রিয়-টগল; কালেকশন **অটো-হিসাব** =
মান্থলি ফি (কোর্স-ফি × ১/১.৫/২) + ডেলিভারি (জোন প্রিসেট) + ওজন-এক্সট্রা + সমন্বয়(±, কারণ) — ওভাররাইডযোগ্য।
নিচে স্টিকি বার: সক্রিয় N টি + মোট কালেকশন + "কুরিয়ারে পাঠান" (সব সক্রিয় একসাথে)। মাস×শিক্ষার্থী ট্র্যাকিং।
সব **মোবাইল কার্ড-লেআউটে**।

## ডেটা মডেল ও মাইগ্রেশন (ধাপ ১ — non-destructive, ADD only)

**A. প্রিসেট (গ্লোবাল, `settings` টেবিলে key-value; `admin/settings.php`-এ নতুন গ্রুপ "কুরিয়ার প্রিসেট")**:
- `courier_dc_dhaka` (ঢাকার মধ্যে চার্জ, ডিফল্ট 60), `courier_dc_near` (নিকটবর্তী, 80), `courier_dc_outside` (বাইরে, 120), `courier_weight_extra` (ওজন বেশি হলে +, 20)।
- মান্থলি ফি প্রিসেট লাগবে না — কোর্স-ফি (`course_batches.price` → `parse_price_to_number()`) × মাল্টিপ্লায়ার।

**B. `courier_batches`-এ per-batch breakdown কলাম (ALTER, non-destructive)** — `amount_to_collect` আগেই আছে (computed total রাখবে):
```sql
ALTER TABLE courier_batches
  ADD COLUMN monthly_multiplier DECIMAL(3,1) NOT NULL DEFAULT 1 AFTER amount_to_collect,
  ADD COLUMN delivery_zone VARCHAR(20) DEFAULT 'dhaka' AFTER monthly_multiplier,
  ADD COLUMN weight_extra TINYINT(1) NOT NULL DEFAULT 0 AFTER delivery_zone,
  ADD COLUMN adjustment DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER weight_extra,
  ADD COLUMN adjustment_reason VARCHAR(60) DEFAULT NULL AFTER adjustment;
```

**C. কাস্টম নোট (রঙসহ)**:
```sql
CREATE TABLE IF NOT EXISTS courier_note_types (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label VARCHAR(80) NOT NULL,
  color VARCHAR(20) NOT NULL DEFAULT 'amber',  -- CDS role: amber/accent/success/warning/pro/danger অথবা hex
  sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- per-registration নোট (many-to-many; free-text override সহ চাইলে JSON-ও করা যায়)
CREATE TABLE IF NOT EXISTS registration_courier_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  note_type_id INT UNSIGNED NULL,          -- preset type; NULL হলে custom_text ব্যবহার
  custom_text VARCHAR(120) DEFAULT NULL,
  color VARCHAR(20) DEFAULT NULL,          -- custom হলে রঙ
  FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (note_type_id) REFERENCES courier_note_types(id) ON DELETE SET NULL,
  INDEX idx_reg (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```
- registration আর্কাইভ/ডিলিটে `registration_courier_notes` CASCADE-এ মোছে — **`archive_children_map()`-এ `registrations`-এর child হিসেবে `registration_courier_notes` (fk registration_id) যোগ করতে হবে** (নাহলে আর্কাইভ/রিস্টোরে নোট হারাবে)।

**D. মাস/সিকোয়েন্স**: `courier_batches.period_label` (ফ্রি-টেক্সট) আছে; নতুন `period_seq INT` (১ম/২য়/... মাস নম্বর) যোগ করা যায় ট্র্যাকিং গ্রিডের জন্য, অথবা period_label-ই রেখে গ্রিডে গ্রুপ করা। **সিদ্ধান্ত ইউজারের সাথে**: ফিক্সড মাস-নম্বর নাকি ফ্রি-টেক্সট লেবেল। (ডেমোতে ১ম/২য়/৩য় দেখানো হয়েছে।)

## বিল্ড চেকলিস্ট (ক্রমানুসারে)
- [x] **ধাপ ০**: ব্যাকআপ (DB + কোড) — সম্পন্ন।
- [x] **ধাপ ১ (schema)** ✅ সম্পন্ন: `database/migrate-courier-collection.sql` লেখা (courier_batches-এ ৫ কলাম + courier_note_types + registration_courier_notes + settings প্রিসেট সিড ৬০/৮০/১২০/২০)। isolated `educenter_test`-এ টেস্ট PASS (ডেটা অক্ষত, INSERT IGNORE override করেনি)। **real `educenter` DB-তে প্রয়োগ সম্পন্ন** (৫ কলাম + ২ টেবিল + প্রিসেট, পুরনো রো অক্ষত)। `schema.sql`-এ যোগ (fresh install)। `admin/includes/archive.php` `archive_children_map()`-এ registration_courier_notes child যোগ (lint পাস)। **⚠️ লাইভে migrate-courier-collection.sql phpMyAdmin-এ চালাতে হবে (নতুন কলাম/টেবিল)।**
- [x] **ধাপ ২ (হিসাব ইঞ্জিন)** ✅: `includes/functions.php`-এ `courier_compute_collection(float $courseFee, float $multiplier, string $zone, bool $weightExtra, float $adjustment): float` — মান্থলি (fee×mult) + `get_setting('courier_dc_'.$zone)` + (weight হলে `courier_weight_extra`) + adjustment, max(0,round)। ৪ কেস CLI-তে যাচাইকৃত (950/930/1415/0)। **বাকি**: `save_courier_batch()` (CourierManager.php)-এ breakdown কলাম সেভ + POST-এ total না এলে এই হেল্পার দিয়ে অটো-কম্পিউট (ধাপ ৫/৬-এর সাথে করা ভালো)।
- [x] **ধাপ ৩ (প্রিসেট UI)** ✅: `admin/settings.php`-এ "কুরিয়ার প্রিসেট (কালেকশন হিসাবের জন্য)" গ্রুপ — courier_dc_dhaka/near/outside + courier_weight_extra। সেকশন-সেভ অটো; authenticated রেন্ডারে যাচাইকৃত।
- [x] **ধাপ ৪a (নোট-টাইপ CRUD)** ✅: `entities.php`-এ `courier_note_types` জেনেরিক CRUD (label + color select + sort_order) — সাইডবার "কুরিয়ার নোট-টাইপ"।
- [x] **ধাপ ৪b (নোট অ্যাসাইন UI)** ✅: নতুন `admin/courier-note-assign.php` (self-contained; `courier-prepare.php` কার্ডের "+ নোট" লিংক থেকে খোলে, return সহ) — note_type বাছাই বা custom_text+color → `registration_courier_notes` INSERT/DELETE। E2E যাচাইকৃত (নোট যোগ → prepare কার্ডে চিপ → ডিলিট, ক্লিন)। lint পাস।
- [x] **ধাপ ৫ (মূল UI — "প্রস্তুত" স্ক্রিন)** ✅: নতুন **`admin/courier-prepare.php`** (additive, courier.php অক্ষত; সাইডবারে "পার্সেল প্রস্তুত")। item_id না থাকলে কোর্স-ব্যাচ পিকার (confirmed কোর্স রেজিস্ট্রেশন থেকে); item_id দিলে প্রতিটা confirmed রেজিস্ট্রেশনের **কার্ড** — সক্রিয়-টগল + নাম/facebook_id/ঠিকানা + নোট-চিপ (registration_courier_notes, রঙসহ) + বিল্ডার (মান্থলি select ১/১.৫/২ · জোন select · ওজন checkbox · সমন্বয় ± · কারণ) + লাইভ টোটাল (JS, DC প্রিসেট+data-fee)। নিচে fixed স্টিকি বার (সক্রিয় সংখ্যা + মোট + "খসড়া সেভ করুন")। POST `action=save-drafts` → প্রতি সক্রিয় রেজিস্ট্রেশনে `courier_compute_collection()` দিয়ে amount কম্পিউট করে `courier_batches`-এ খসড়া INSERT (safe: registration_id+period_label+breakdown, বাকি default)। lint পাস; picker+card authenticated রেন্ডার 200/০ fatal যাচাইকৃত। **⚠️ বাকি যাচাই (পরের সেশন/ইউজার)**: আসল POST save করে খসড়া তৈরি হয় কিনা (real DB-তে draft তৈরি হবে — টেস্টে একটা করে ডিলিট করে নেওয়া ভালো)।
- [x] **ধাপ ৬ (prepare থেকে সরাসরি সেন্ড)** ✅: `courier-prepare.php`-এ `action=send-now` ব্রাঞ্চ (save-drafts অক্ষত) — প্রতি সক্রিয় রেজিস্ট্রেশনে খসড়া INSERT → `$db->lastInsertId()` → `send_courier_batch($db, $provider, $regRow, [], $draftId)` (খালি `$post` দিলে save_courier_batch খসড়ার বিদ্যমান amount/description রেখেই পাঠায়)। `get_active_courier_provider()` না থাকলে গার্ড। সফল/ব্যর্থ কাউন্ট সহ ফ্ল্যাশ। UI: দুই বাটন — "খসড়া" (submit) ও "কুরিয়ারে পাঠান" (হিডেন `#prepAction` সেট করে `confirmSubmit()` মডাল দিয়ে সাবমিট)। lint+রেন্ডার যাচাইকৃত। **⚠️ আসল send API কল টেস্ট করা হয়নি ইচ্ছাকৃতভাবে** (সত্যিকারের শিপমেন্ট তৈরি হতো) — **ইউজার লাইভে/লোকালে ১টা দিয়ে যাচাই করবেন**।
- [x] **ধাপ ৭ (ট্র্যাকিং গ্রিড)** ✅: নতুন **`admin/courier-tracking.php`** (read-only, additive; সাইডবারে "কুরিয়ার ট্র্যাকিং")। কোর্স-ব্যাচ পিকার → শিক্ষার্থী × মাস(period_label) ম্যাট্রিক্স, প্রতি ঘরে send_status (✅পাঠানো/⏳প্রস্তুত/✗ব্যর্থ) + amount, নাহলে —। টেবিল `.overflow-x-auto`-এ (মোবাইলে অটো কার্ড)। lint+রেন্ডার যাচাইকৃত (picker+grid 200/০ fatal)।
- [~] **ধাপ ৮ (টেস্ট + ডক + জিপ)** — **POST-save end-to-end যাচাইকৃত** ✅ (item 9 reg 29 → খসড়া amount 950 = fee 890 + dhaka 60, তারপর টেস্ট খসড়া ডিলিট, আসল ডেটা অক্ষত)। CHANGELOG আপডেট হয়েছে। **⬜ বাকি**: PROJECT-NOTES/CLAUDE-এ চূড়ান্ত নোট; ইউজার জিপ চাইলে forward-slash জিপ (নতুন ফাইল: courier-prepare.php, courier-tracking.php, functions.php, settings.php, entities.php, includes/upload.php?, layout-top.php, archive.php + migrate-courier-collection.sql; **লাইভে migrate চালাতে হবে**)।

## ⚠️ নিরাপত্তা/নিয়ম (মনে রাখুন)
- schema পরিবর্তন **আগে isolated `educenter_test`-এ** টেস্ট, তারপর real DB (ব্যাকআপ আছে)। ALTER non-destructive (ADD COLUMN)।
- **বিদ্যমান কুরিয়ার ফ্লো না ভেঙে** নতুনটা additive বানান — মাঝপথে কনটেক্সট শেষ হলেও লাইভ সাইট যেন কাজ করে।
- প্রতিটা PHP ফাইলে `php -l` lint; authenticated curl দিয়ে রেন্ডার যাচাই; মোবাইল কার্ড-লেআউট (CLAUDE.md নিয়ম)।
- লাইভ credential/token ফাইলে লিখবেন না।

## অগ্রগতি (এখানে আপডেট রাখুন)
- ২০২৬-০৭-১৬: ব্যাকআপ (ধাপ ০ ✅) + প্ল্যান লেখা + **ধাপ ১ schema সম্পন্ন** (isolated টেস্ট + real DB প্রয়োগ + schema.sql + archive map)। কনটেক্সট শেষের দিকে থামানো হলো।
- ২০২৬-০৭-১৬ (একই সেশন, পরে): **ধাপ ২,৩,৫,৪a সম্পন্ন** — হিসাব ইঞ্জিন + প্রিসেট UI + মূল কার্ড পেজ `courier-prepare.php` (খসড়া সেভ) + নোট-টাইপ CRUD। **বাকি**: ধাপ ৪b (per-reg নোট অ্যাসাইন UI), ধাপ ৬ (prepare-এর খসড়া বিদ্যমান courier.php-তেই পাঠানো যায়, তবে prepare থেকে সরাসরি বাল্ক-সেন্ড বাটন যোগ করা যায়), ধাপ ৭ (মাস×শিক্ষার্থী ট্র্যাকিং গ্রিড), ধাপ ৮ (POST-save লাইভ যাচাই + ডক + জিপ, migrate-courier-collection.sql লাইভে চালানো)।
- **➡️ পরের সেশন এখান থেকে শুরু**: **ধাপ ৪ (নোট-টাইপ CRUD)** — `entities.php`-এ `courier_note_types` জেনেরিক CRUD (label + color; color-এ CDS role নাম বা hex; `type=>'color'` বা select) সাইডবারে যোগ। তারপর **ধাপ ৫ (মূল কার্ড UI — সবচেয়ে বড়)**: কোর্স-ব্যাচ+মাস বাছাই → প্রতি-রেজিস্ট্রেশন কার্ড (নাম/fb/ঠিকানা/নোট-চিপ/সক্রিয়-টগল + মান্থলি/জোন/ওজন/সমন্বয় কন্ট্রোল + `courier_compute_collection()` দিয়ে লাইভ টোটাল) — **এই সেশনের `mcp__visualize` ডেমো v2-এর মার্কআপ/JS হুবহু রেফারেন্স** (auto-calc + চিপ + স্টিকি বার)। নতুন `admin/courier-prepare.php` (additive, courier.php না ভেঙে)। তারপর ধাপ ৬ (বাল্ক সেন্ড — save_courier_batch/send_courier_batch breakdown সহ), ধাপ ৭ (ট্র্যাকিং গ্রিড), ধাপ ৮ (টেস্ট+ডক+জিপ)। **বিদ্যমান courier.php অক্ষত রাখুন।**
