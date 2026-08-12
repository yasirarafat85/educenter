# USER-ACCOUNT-PLAN.md — অভিভাবক অ্যাকাউন্ট / সাবস্ক্রিপশন সিস্টেম (পরিকল্পনা)

> স্ট্যাটাস: **ধাপ ১ + ধাপ ২ (Google) সম্পন্ন ও isolated-টেস্টেড (২০২৬-০৮-১২)**। ধাপ ২-তে কোনো নতুন DB মাইগ্রেশন লাগে না (google_id ধাপ ১-এ যোগ, ক্রেডেনশিয়াল settings-এ)। লাইভে: deploy + অ্যাডমিন সেটিংসে Client ID/Secret পেস্ট + Google Cloud-এ redirect URI + app publish।
> লক্ষ্য: কোর্স কেনা অভিভাবকরা লগইন করে নিজের ড্যাশবোর্ডে কেনা কোর্স, খরচ, প্রাইভেট গ্রুপ লিংক
> (এবং পরে রেজাল্ট/অগ্রগতি) দেখবেন। অ্যাডমিনের পূর্ণ নিয়ন্ত্রণ।

---

## ✅ ঠিক করা সিদ্ধান্ত (ইউজারের)

- **দুই পথে signup**: (১) ফোন + পাসওয়ার্ড, (২) Google — দুটোই একই জায়গায় পৌঁছায়।
- **মূল চাবি = রেজিস্ট্রেশনের মোবাইল নাম্বার** — Google পথেও প্রথমবার এই নাম্বার দিয়ে অ্যাকাউন্ট
  রেজিস্ট্রেশন-ডেটার সাথে বাঁধা হবে। ফোনই আসল পরিচয়।
- **অ্যাডমিন approve বাধ্যতামূলক** — approve না দিলে ডেটা দেখা যাবে না।
- **গ্রুপ (FB/Messenger) নিয়ন্ত্রণ কোর্স-ভিত্তিক**: প্রতি course-batch-এ গ্রুপ লিংক সেট থাকবে; ইউজার যে
  কোর্স কিনেছে, স্বয়ংক্রিয়ভাবে সেই গ্রুপ দেখবে (প্রতি-ইউজারে আলাদা সেট করার দরকার নেই)।
- **বানানোর ক্রম**: ধাপ ১ = ফোন পথ + ড্যাশবোর্ড (ভিত্তি); ধাপ ২ = Google যোগ; ধাপ ৩ = রেজাল্ট ইত্যাদি।

---

## 🗄️ ডেটা মডেল (নতুন)

### নতুন টেবিল `users` (অভিভাবক অ্যাকাউন্ট)
```
id             INT PK
phone          VARCHAR(20) UNIQUE   -- রেজিস্ট্রেশনের মোবাইল (পরিচয়-চাবি)
password_hash  VARCHAR(255) NULL    -- ফোন-পথের জন্য (Google-only হলে NULL)
google_id      VARCHAR(64)  NULL UNIQUE  -- Google-পথের জন্য (ফোন-only হলে NULL)
email          VARCHAR(150) NULL    -- Google থেকে / ঐচ্ছিক
full_name      VARCHAR(100) NULL
status         VARCHAR(20)  DEFAULT 'pending'  -- pending/approved/rejected/blocked
created_at, approved_at NULL, approved_by NULL
```
- একটা অ্যাকাউন্টে **password_hash আর google_id দুটোই** থাকতে পারে (দুই পথ লিংক করলে)।
- `phone` UNIQUE — এক রেজিস্ট্রেশন-ফোনে একটাই অ্যাকাউন্ট।

### `course_batches`-এ ২টা নতুন কলাম (গ্রুপ লিংক)
```
fb_group_url         VARCHAR(500) NULL
messenger_group_url  VARCHAR(500) NULL
```
admin/course-batches.php থেকে সেট হবে। ইউজারের কেনা কোর্সের লিংকই ড্যাশবোর্ডে দেখাবে।

### রেট-লিমিট (নিরাপত্তা)
বিদ্যমান `login_attempts`/`form_submit_attempts` প্যাটার্ন রিইউজ, অথবা `user_login_attempts`।

---

## 🪜 ধাপ ১ — ভিত্তি (ফোন পথ + ড্যাশবোর্ড) — মূল ৮০% মূল্য

### পাবলিক পেজ
- **`account-signup.php` + `-submit.php`** — ফোন + পাসওয়ার্ড + নাম। সার্ভার চেক করে ফোনটা
  `registrations`-এ আছে কিনা (আসল কাস্টমার)। `users` রো তৈরি status=pending। "অ্যাডমিন approve-এর
  অপেক্ষায়" বার্তা। CSRF + honeypot/timing স্প্যাম-প্রোটেকশন (বিদ্যমান হেল্পার রিইউজ)।
- **`account-login.php` + `-submit.php`** — ফোন + পাসওয়ার্ড → সেশন। শুধু status=approved লগইন করতে
  পারবে (pending/rejected/blocked আলাদা বার্তা পাবে)। রেট-লিমিট।
- **`account.php` (ড্যাশবোর্ড)** — `user_require_login()`। দেখায়:
  - কেনা কোর্স (নিজের phone-এর registrations, type=course; ব্যাচ নামসহ)
  - মোট খরচ (নিজের registrations/income থেকে)
  - প্রাইভেট গ্রুপ লিংক (কেনা কোর্সের course_batches.fb_group_url/messenger_group_url)
- **`account-password.php`** — পাসওয়ার্ড বদল (লগইন করা ইউজার)।
- **`account-logout.php`**

### সেশন/অথ
- অ্যাডমিন সেশন থেকে **আলাদা** ইউজার সেশন। `user_require_login()` হেল্পার (admin auth-এর প্যাটার্নে)।
  session hardening রিইউজ।

### অ্যাডমিন
- **`admin/users.php`** (সাইডবার "অভিভাবক") — signup তালিকা, status ফিল্টার, **approve/reject/block**,
  **পাসওয়ার্ড রিসেট**, ইউজারের লিংকড registrations দেখা। মোবাইল কার্ড-লেআউট। নতুন সাইডবার লিংক।
- **`admin/course-batches.php`**-এ গ্রুপ লিংক ২টা ফিল্ড যোগ।

### নিরাপত্তা (গুরুত্বপূর্ণ — নতুন পাবলিক অথ)
- password_hash (bcrypt), সব ফর্মে CSRF, লগইন রেট-লিমিট, signup স্প্যাম-প্রোটেকশন।
- 🔴 **প্রাইভেসি**: ড্যাশবোর্ড শুধু **লগইন-করা ইউজারের নিজের** ডেটা দেখাবে (session user_id/phone দিয়ে
  কোয়েরি; কখনো URL/প্যারামিটার থেকে অন্য ইউজারের id নেওয়া যাবে না)। **এক ইউজার আরেকজনের ডেটা যেন
  কখনো না দেখে — isolated টেস্টে এটাই সবচেয়ে জরুরি যাচাই।**
- **পাসওয়ার্ড ভুলে গেলে**: SMS/ইমেইল নেই বলে → **অ্যাডমিন রিসেট** (admin/users.php থেকে temp পাসওয়ার্ড)।
  (ঐচ্ছিক: শিশুর জন্মতারিখ দিয়ে সিকিউরিটি-চেক রিসেট — পরে।)

---

## 🔵 ধাপ ২ — Google লগইন (ভিত্তির উপরে)

- **ইউজার একবার Google Cloud-এ**: project + OAuth 2.0 client (client_id/secret) + consent screen +
  authorized redirect URI। **AI গাইড দেবে, কিন্তু ইউজার নিজে করবেন** (AI Google অ্যাকাউন্টে ঢুকতে পারে না)।
  🔴 **ফ্রি** (টাকা লাগে না), কিন্তু সেটআপ/রক্ষণাবেক্ষণ জটিল।
- **`account-google-start.php`** — Google-এ redirect (OAuth code flow)।
- **`account-google-callback.php`** — code → token (Google token endpoint, curl) → id_token যাচাই
  (Google tokeninfo endpoint — Composer/JWT-লাইব্রেরি ছাড়াই, এই কোডবেসের সাথে সঙ্গতিপূর্ণ) → email/google_id।
- **ফ্লো**: Google লগইন → google_id চেনা ও approved হলে সরাসরি লগইন; নতুন হলে → **রেজিস্ট্রেশনের ফোন
  নাম্বার** চাওয়া → registrations-এ মিলিয়ে `users` রো (status=pending) → অ্যাডমিন approve।
- একই অ্যাকাউন্টে password + google_id দুটোই লিংক থাকতে পারে।

---

## 🏷️ Google Brand Verification — লোগো/নাম দেখানো (ঐচ্ছিক, পরে করা হবে)

**বর্তমান অবস্থা (২০২৬-০৮-১২)**: Google লগইন **লাইভ ও কাজ করছে** (OAuth client `Shishur Medha Login` তৈরি, ক্রেডেনশিয়াল অ্যাডমিন সেটিংসে বসানো, বাটন আসছে, ইউজার টেস্ট করেছেন)। **basic scope (email/profile/openid) বলে verification ছাড়াই যেকোনো Google ইউজার লগইন করতে পারে — Testing/Production দুটোতেই** (ডকুমেন্ট-যাচাইকৃত, support.google.com/cloud/answer/15549945)। তাই Publish/verification লগইনের জন্য **বাধ্যতামূলক নয়**।

**এখন Google-এর "Choose an account" স্ক্রিনে ডোমেইন দেখায়** ("continue to shishurmedhabikash.com") — **লোগো/নাম নয়**। লোগো + অ্যাপ-নাম দেখাতে চাইলে **brand verification** লাগবে:

1. **Privacy Policy + Terms পেজ নিজের ডোমেইনে** (`shishurmedhabikash.com`) — 🔴 Google Site/অন্য ডোমেইন **চলবে না** (নিয়ম: privacy policy হোমপেজের same verified domain-এ হতে হবে; support.google.com/cloud/answer/13806988)। **AI বানাবে**: `privacy.php` + `terms.php` (স্ট্যান্ডার্ড টেমপ্লেট, ইউজার রিভিউ করবেন) + হোমপেজ/ফুটারে লিংক।
2. Google Branding-এ **Home page + Privacy + Terms URL** বসানো।
3. **App name** "login System" → "Shishur Medha Bikash" (Branding → Save)।
4. **Google Search Console-এ shishurmedhabikash.com ডোমেইন verify** (মালিকানা প্রমাণ)।
5. **Audience → Publish app → Submit for verification** → Google রিভিউ (কয়েক দিন-সপ্তাহ)।

**দ্রষ্টব্য**: লোগো আপলোড করা থাকলেও unverified অবস্থায় শুধু ডোমেইন দেখায়, লগইন ভাঙে না। App name change verification ছাড়া consent স্ক্রিনে দেখায় না (তখনও ডোমেইন)।

## 🔮 ধাপ ৩ — পরে (ইনক্রিমেন্টাল)
রেজাল্ট/অগ্রগতি (নতুন টেবিল + অ্যাডমিন এন্ট্রি) · উপস্থিতি · বকেয়া ফি · রসিদ/সার্টিফিকেট ·
ক্লাস রুটিন · নোটিফিকেশন · কোর্স রিনিউ · রেফারেল · হোমওয়ার্ক জমা।

---

## 🧪 টেস্ট ও ডিপ্লয় (স্ট্যান্ডিং প্রোটোকল)
- schema.sql আপডেট + মাইগ্রেশন SQL (non-destructive: `users` টেবিল + course_batches ২ কলাম)।
- **isolated `educenter_test`-এ ফুল টেস্ট** — বিশেষত **প্রাইভেসি** (ইউজার A ≠ ইউজার B ডেটা), signup→
  approve→login→dashboard, রেট-লিমিট, পাসওয়ার্ড রিসেট।
- git deploy + phpMyAdmin-এ মাইগ্রেশন।

---

## ⏱️ পরিশ্রম অনুমান
- **ধাপ ১**: মাঝারি, ~১-২ সেশন (বিদ্যমান auth/CRUD/spam প্যাটার্ন রিইউজ)।
- **ধাপ ২ (Google)**: ~১ সেশন + ইউজারের Google Cloud সেটআপ।
- **ধাপ ৩**: পরে, অল্প অল্প করে।

## ⚠️ ঝুঁকি
- নতুন পাবলিক-মুখী অথ — প্রাইভেসি ও নিরাপত্তায় সাবধান (isolated টেস্ট বাধ্যতামূলক)।
- Google OAuth: বাইরের সেটআপ + রক্ষণাবেক্ষণ (ইউজার সামলাবেন, AI গাইড)।
- বড় ফিচার — তাড়াহুড়া না করে, ধাপে ধাপে, টেস্টসহ (আদর্শভাবে পিসিতে লোকাল টেস্ট)।
