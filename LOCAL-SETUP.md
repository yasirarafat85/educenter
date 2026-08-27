# LOCAL-SETUP.md — নতুন পিসিতে প্রোজেক্ট চালানোর গাইড

এই প্রোজেক্ট (EduCenter / শিশুর মেধা বিকাশ) আরেকটা Windows পিসিতে **লোকালে** চালাতে চাইলে এই ধাপগুলো অনুসরণ করুন। কোডিং না জানলেও করা যাবে।

> **সবচেয়ে সহজ পথ (সংক্ষেপে):** পুরনো পিসি থেকে পুরো `website` ফোল্ডার (config.php + uploads সহ) কপি করে নতুন পিসির XAMPP-এ রাখুন → ডাটাবেস ব্যাকআপ ইমপোর্ট করুন → GD চালু করুন → চলবে। বিস্তারিত নিচে।

---

## যা যা লাগবে
- একটা Windows পিসি
- **XAMPP** (PHP 8.2 ভার্সন) — Apache + MySQL + PHP
- **ইন্টারনেট** (ফন্ট লোড ও প্রথমবার কোড আনার জন্য)

---

## ধাপ ১ — XAMPP ইনস্টল
1. [apachefriends.org](https://www.apachefriends.org) থেকে XAMPP (PHP 8.2) নামিয়ে ইনস্টল করুন (সাধারণত `D:\xampp`-এ)।
2. **XAMPP Control Panel** খুলে **Apache** ও **MySQL** — দুটোই **Start** করুন (সবুজ হবে)।

---

## ধাপ ২ — কোড আনা (দুই উপায়, যেকোনো একটা)

**(ক) সবচেয়ে সহজ — পুরনো পিসি থেকে পুরো ফোল্ডার কপি** ✅ *সুপারিশ*
- পুরনো পিসির `D:\clude_project\website` পুরো ফোল্ডারটা (ভেতরের সব ফাইল, বিশেষত **`config.php`** আর **`uploads`** ফোল্ডার সহ) কপি করে নতুন পিসিতে আনুন।
- সুবিধা: config.php ও আসল ছবি (uploads) সব চলে আসবে।

**(খ) GitHub থেকে (git clone)**
- `git clone https://github.com/yasirarafat85/educenter.git`
- ⚠️ প্রাইভেট রেপো — টোকেন (PAT) লাগবে। আর এতে **config.php ও uploads-এর আসল ছবি থাকবে না** (git-এ বাদ) — নিচের ধাপে হাতে বানাতে/আনতে হবে।

**কোথায় রাখবেন:**
- সরাসরি `D:\xampp\htdocs\website`-এ রাখতে পারেন, **অথবা**
- অন্য জায়গায় (যেমন `D:\clude_project\website`) রেখে htdocs-এ একটা junction বানান (cmd **Administrator** হিসেবে খুলে):
  ```
  mklink /J D:\xampp\htdocs\website D:\clude_project\website
  ```

---

## ধাপ ৩ — config.php ঠিক করা
- (ক) উপায়ে ফোল্ডার কপি করলে **config.php ইতিমধ্যে থাকবে** — শুধু নিচের মান ঠিক আছে কিনা দেখুন।
- (খ) git clone করলে **`config.example.php` কপি করে `config.php` নামে সেভ করুন**, তারপর মান বসান:
  ```php
  define('DB_HOST', 'localhost');
  define('DB_NAME', 'educenter');
  define('DB_USER', 'root');
  define('DB_PASS', '');            // XAMPP-এ পাসওয়ার্ড খালি
  define('SITE_URL', 'http://localhost/website');
  define('DEV_MODE', true);         // লোকালে true (এরর দেখাবে)
  ```

---

## ধাপ ৪ — PHP-তে GD চালু (ছবির জন্য জরুরি)
- `D:\xampp\php\php.ini` খুলুন → `;extension=gd` লাইনটা খুঁজে সামনের **`;` মুছে দিন** (`extension=gd`)।
- XAMPP-এ Apache **Restart** করুন।
- (এটা ছবি রিসাইজ/WebP-এর জন্য দরকার; না থাকলে ছবি আপলোড ভাঙতে পারে।)

---

## ধাপ ৫ — ডাটাবেস তৈরি
1. ব্রাউজারে খুলুন: `http://localhost/phpmyadmin`
2. বাঁ পাশে **New** → নতুন ডাটাবেস বানান, নাম **`educenter`**, Collation **`utf8mb4_unicode_ci`** → Create।
3. এবার দুই উপায়ের যেকোনো একটা:

   **(ক) আপনার আসল ডেটাসহ (সুপারিশ)** — পুরনো পিসির অ্যাডমিন → **"ব্যাকআপ ও ডাউনলোড"** থেকে DB ব্যাকআপ (`.sql`) নামান → নতুন পিসির phpMyAdmin-এ `educenter` সিলেক্ট করে **Import** করুন। (সব কোর্স/রেজিস্ট্রেশন/সেটিংস চলে আসবে।)

   **(খ) খালি/নতুন** — `database/schema.sql` ফাইলটা Import করুন (সব টেবিল নতুন করে তৈরি হবে, নতুন সব কলামসহ)।

> 🔴 **পুরনো ব্যাকআপ ইমপোর্ট করলে**, পরে যোগ হওয়া মাইগ্রেশনগুলো (`database/migrate-*.sql`) এক এক করে চালিয়ে নিন (যেগুলো ঐ ব্যাকআপে ছিল না) — যেমন course-tracking, secondary-fee, payment-schedule, legacy-students, registration-errors। প্রতিটা SQL phpMyAdmin-এর SQL ট্যাবে পেস্ট করে চালান। (এগুলো non-destructive — কলাম না থাকলে যোগ করে, থাকলে কিছু করে না।)

---

## ধাপ ৬ — uploads ফোল্ডার (ছবি)
- `uploads/` ও তার সাবফোল্ডারগুলো (courses, worksheets, products, gallery, teachers, reviews ...) থাকতে হবে ও **লেখার অনুমতি** থাকতে হবে (লোকাল XAMPP-এ সাধারণত এমনিই থাকে)।
- (ক) উপায়ে পুরো ফোল্ডার কপি করলে ছবিসহ সব চলে আসবে।
- (খ) git clone করলে আসল ছবি থাকবে না — পুরনো পিসি থেকে শুধু `uploads` ফোল্ডারটা কপি করে আনলে ছবিগুলো ফিরবে।

---

## ধাপ ৭ — ফন্ট (আলাদা ইনস্টল লাগে না!)
- বাংলা ফন্ট (**Noto Sans Bengali**) ও ইংরেজি ফন্ট **Google Fonts থেকে অটোমেটিক লোড হয়** — আলাদা করে কিছু ইনস্টল করতে হবে না, শুধু **ইন্টারনেট** থাকলেই হবে।
- ইন্টারনেট না থাকলে সিস্টেমের ডিফল্ট ফন্টে পড়বে (সাইট তবু চলবে, দেখতে একটু আলাদা লাগতে পারে)।

---

## ধাপ ৮ — অ্যাডমিন লগইন
- **ব্যাকআপ ইমপোর্ট করলে** — আপনার পুরনো অ্যাডমিন অ্যাকাউন্ট (username: `arafat`) দিয়েই লগইন হবে।
- **খালি DB (schema.sql) হলে** — কোনো অ্যাডমিন নেই। `http://localhost/website/setup-admin.php` খুলে প্রথম অ্যাডমিন বানান, **তারপর নিরাপত্তার জন্য `setup-admin.php` ফাইলটা ডিলিট করে দিন।**

---

## ধাপ ৯ — চালু ও যাচাই
- পাবলিক সাইট: **`http://localhost/website`**
- অ্যাডমিন: **`http://localhost/website/admin`**
- ঠিকঠাক দেখালে হয়ে গেছে। ছবি আপলোড, কোর্স যোগ — টেস্ট করে দেখুন।

---

## git push (কোড GitHub-এ পাঠানো)
- এই পিসি থেকেও push করতে চাইলে **প্রতিবার PAT (টোকেন) লাগবে** — নিরাপত্তার জন্য টোকেন সেভ করা হয় না। commit/লোকাল কাজ টোকেন ছাড়াই চলে; শুধু push-এ লাগে।

---

## ⚡ একদম সংক্ষেপে (সবচেয়ে সহজ)
1. XAMPP ইনস্টল → Apache + MySQL চালু
2. পুরনো পিসির পুরো `website` ফোল্ডার (config.php + uploads সহ) কপি করে htdocs-এ রাখুন
3. php.ini-তে `extension=gd` চালু → Apache restart
4. phpMyAdmin-এ `educenter` DB বানিয়ে পুরনো পিসির **ব্যাকআপ .sql ইমপোর্ট** করুন
5. `http://localhost/website` — হয়ে গেছে ✅

---

*(লাইভ হোস্টিং/cPanel-এ ডিপ্লয়ের জন্য দেখুন `SETUP-GUIDE.md`; প্রোজেক্টের সামগ্রিক গাইড `CLAUDE.md`।)*
