# অ্যাডমিন 2FA (TOTP) — বিল্ড প্ল্যান

> রিজিউমেবল চেকলিস্ট। নতুন সেশনে এখান থেকে ধাপে ধাপে বানাতে হবে।
> **⚠️ লগইন ফ্লো বদলায় — একবারে (এক সেশনে) শেষ করতে হবে, মাঝপথে থামলে লগইন ভাঙার ঝুঁকি।**
> **শুরুর আগে অবশ্যই কোড+DB ব্যাকআপ নিন** (COURIER-REDESIGN-PLAN.md-এর ব্যাকআপ প্যাটার্ন)।

## পদ্ধতি: TOTP (Google Authenticator / Authy)
ইউজার অনুমোদিত। কোনো Composer/লাইব্রেরি/SMS/ইমেইল লাগবে না — বিশুদ্ধ PHP (HMAC-SHA1 + base32)।

## ধাপ ১ — ডাটাবেস (non-destructive)
`database/migrate-2fa.sql`:
```sql
ALTER TABLE admin_users
  ADD COLUMN totp_secret   VARCHAR(64)  DEFAULT NULL,
  ADD COLUMN totp_enabled  TINYINT(1)   NOT NULL DEFAULT 0,
  ADD COLUMN backup_codes  TEXT         DEFAULT NULL;  -- JSON array of hashed one-time codes
```
`schema.sql`-এও যোগ করুন। isolated `educenter_test`-এ টেস্ট → real DB।

## ধাপ ২ — TOTP ইঞ্জিন (বিশুদ্ধ PHP)
`admin/includes/totp.php` (নতুন):
- `totp_base32_encode(string $bin): string` / `totp_base32_decode(string $b32): string`
- `totp_generate_secret(int $len = 20): string` — random_bytes → base32
- `totp_code(string $secret, ?int $timeSlice = null): string` — HMAC-SHA1(secret, floor(time()/30)), dynamic truncation → ৬ ডিজিট (str_pad LEFT '0')
- `totp_verify(string $secret, string $code, int $window = 1): bool` — ±1 স্লট (ঘড়ির সামান্য পার্থক্য সহনীয়), `hash_equals()` দিয়ে তুলনা
- `totp_uri(string $secret, string $user, string $issuer): string` — `otpauth://totp/Issuer:user?secret=...&issuer=...`
**টেস্ট**: পরিচিত secret+timeSlice দিয়ে RFC 6238 টেস্ট ভেক্টর মিলিয়ে দেখুন (CLI)।

## ধাপ ৩ — সেটআপ পেজ `admin/two-factor.php` (নতুন)
- 2FA বন্ধ থাকলে: secret জেনারেট (সেশনে রাখুন, DB-তে নয়) → **QR কোড** দেখান + **ম্যানুয়াল কী** (যদি স্ক্যান না হয়) → "অ্যাপের ৬ ডিজিট কোড দিন" ইনপুট → `totp_verify()` সফল হলে DB-তে secret সেভ + `totp_enabled=1` + **৮টা ব্যাকআপ কোড** জেনারেট করে **একবার দেখান** (hash করে `backup_codes`-এ রাখুন)।
- 2FA চালু থাকলে: "বন্ধ করুন" (পাসওয়ার্ড চেয়ে) + "ব্যাকআপ কোড নতুন করে বানান"।
- **QR**: এক্সটার্নাল CDN নয় (CSP/সেল্ফ-হোস্ট নীতি) — সেল্ফ-হোস্টেড ছোট JS QR লাইব্রেরি (`assets/js/qrcode.min.js`) অথবা QR ছাড়াই ম্যানুয়াল কী + otpauth লিংক দেখান।
- সাইডবারে "নিরাপত্তা / 2FA" লিংক (settings সেকশনে)।

## ধাপ ৪ — লগইন ফ্লো (⚠️ সবচেয়ে সংবেদনশীল)
`admin/includes/auth.php`:
- `admin_attempt_login()` — পাসওয়ার্ড সঠিক হলে, যদি `totp_enabled` → **পুরো লগইন করাবেন না**; শুধু `$_SESSION['pending_2fa_admin_id']` + `pending_2fa_at` সেট করে `false`-এর বদলে একটা আলাদা স্ট্যাটাস রিটার্ন (যেমন `'2fa'`)।
- নতুন `admin_complete_2fa_login(int $adminId)` — যাচাই সফল হলে আসল সেশন কী (`admin_id/name/username/last_activity`) সেট + `session_regenerate_id(true)` + pending কী মুছুন।
`admin/login.php`:
- pending থাকলে পাসওয়ার্ড ফর্মের বদলে **"৬ ডিজিট কোড"** ফর্ম দেখান (+ "ব্যাকআপ কোড ব্যবহার করুন" টগল)।
- কোড যাচাই: `totp_verify()` অথবা ব্যাকআপ কোড (`password_verify` করে ম্যাচ হলে সেই কোড তালিকা থেকে **মুছে দিন** — এককালীন)।
- ভুল কোডেও `admin_record_login_attempt()` (ব্রুট-ফোর্স রেট-লিমিট প্রযোজ্য)। pending সেশনের মেয়াদ ~৫ মিনিট।

## ধাপ ৫ — রিকভারি (লকআউট ঠেকাতে) 🔑
- ব্যাকআপ কোড (৮টা, এককালীন, hashed) — সেটআপে একবার দেখানো হয়, ইউজার সেভ করে রাখবেন।
- **শেষ উপায় (ডকে লিখতে হবে)**: phpMyAdmin → `admin_users` → `totp_enabled = 0` করলেই 2FA বন্ধ।
- CLAUDE.md + SETUP-GUIDE-এ এই রিকভারি ধাপ লিখে রাখুন।

## ধাপ ৬ — টেস্ট (isolated, তারপর লোকাল real)
1. TOTP ভেক্টর টেস্ট (CLI)। 2. সেটআপ → QR/কী → কোড দিয়ে চালু। 3. লগআউট → পাসওয়ার্ড → কোড ধাপ → সঠিক কোডে লগইন। 4. ভুল কোডে ব্লক + রেট-লিমিট। 5. ব্যাকআপ কোডে লগইন (এবং সেটা আর দ্বিতীয়বার কাজ করে না)। 6. `totp_enabled=0` করে রিকভারি। 7. lint + authenticated রেন্ডার। 8. ডক (CHANGELOG/CLAUDE/PROJECT-NOTES) + জিপ (migrate-2fa.sql সহ)।

## ⚠️ নিয়ম
- **এক সেশনে শেষ করুন** (লগইন ফ্লো অর্ধেক বদলানো রাখবেন না)। শুরুতেই ব্যাকআপ।
- লাইভে দেওয়ার আগে **লোকালে পুরো ফ্লো নিজে টেস্ট করুন**, বিশেষত ব্যাকআপ কোড ও রিকভারি।
- সার্ভারের সময় ঠিক থাকতে হবে (TOTP সময়-নির্ভর)।
