# 📘 Android App Playbook — নতুন অ্যাপ বানানোর সম্পূর্ণ গাইড

> **এই ফাইলটা কীভাবে ব্যবহার করবে:** নতুন কোনো চ্যাট/সেশনে অ্যাপ বানাতে চাইলে **এই ফাইলটা AI-কে দাও** (বা এর লিংক)। তাহলে আর্কিটেকচার, ডিজাইন, বিল্ড পদ্ধতি, Google Play নীতিমালা, পাবলিশিং ধাপ, আর আগে যেসব ভুল-ফাঁদে পড়েছি — সব AI একবারে বুঝে যাবে, তোমাকে আর বিস্তারিত বোঝাতে হবে না।
>
> রেফারেন্স অ্যাপ: **Clip Notes** (`com.yasirarafat.clipnotes`) — এই পদ্ধতিতে বানানো ও Play-তে পাবলিশ করা প্রথম অ্যাপ।
> ডেভেলপার: MD. Yasir Arafat (পার্সোনাল Play অ্যাকাউন্ট)।

---

## 1. 🧱 টেক স্ট্যাক ও ভার্সন ম্যাট্রিক্স (হুবহু এই ভার্সন ব্যবহার করবে)

- **ভাষা/UI:** Kotlin + Jetpack Compose (Material 3)
- **ডেটা:** Room (SQLite) — সম্পূর্ণ অফলাইন, কোনো সার্ভার/লগইন নেই
- **আর্কিটেকচার:** single-Activity, ViewModel (AndroidViewModel) + StateFlow, SharedPreferences (সেটিংস)

```
Gradle wrapper      8.7
AGP                 8.5.2
Kotlin              1.9.24
Compose compiler    1.5.14
Compose BOM         2024.06.00
Room                2.6.1  (kapt)
compileSdk          35   (Android 15)
targetSdk           35   → 2026-08-31-এর মধ্যে 36-এ বাড়াতে হবে (Google-এর বাৎসরিক নিয়ম)
minSdk              24   (Android 7.0+, প্রায় সব ফোন)
```

> **⚠️ compileSdk 35 + AGP 8.5.2 একসাথে চালাতে** `gradle.properties`-এ দরকার:
> `android.suppressUnsupportedCompileSdk=35` (36-এ গেলে 36 লিখতে হবে)।

---

## 2. 🏗️ আর্কিটেকচার কনভেনশন

- **Offline-first:** সব ডেটা ফোনেই (Room)। কোনো সার্ভার/ব্যাকএন্ড/লগইন না — নিরাপত্তা ও প্রাইভেসি এখানেই।
- **Room মাইগ্রেশন কখনো destructive না** — নতুন কলাম/টেবিল যোগ করলে `ALTER TABLE` দিয়ে version বাড়াও (v1→v2→...), পুরনো ডেটা হারানো যাবে না।
- **প্যাকেজ নাম:** `com.yasirarafat.<appname>` (ছোট হাতে, ইউনিক)।
- **Android Auto Backup চালু রাখো** (`allowBackup="true"` + backup rules-এ DB + `-wal`/`-shm` include) — এতে uninstall/reinstall-এ ইউজারের ডেটা নিজে থেকে ফেরে (একই Google অ্যাকাউন্টে)।
- **শেয়ার্ড ছোট কম্পোনেন্ট বানাও** (যেমন `PasswordField` — 👁 eye toggle সহ) — ডুপ্লিকেট এড়াতে।

---

## 3. 🔨 বিল্ড সিস্টেম — শুধু GitHub Actions (গুরুত্বপূর্ণ)

- **কেন:** এই এনভায়রনমেন্টে লোকালি `dl.google.com` (Google Maven) ব্লক — তাই লোকালি বিল্ড হয় না। **সব বিল্ড GitHub Actions-এ** (`.github/workflows/android-<app>.yml`)।
- **ওয়ার্কফ্লো যা করে:** সবসময় **debug APK** বানায় (ফোনে টেস্টের জন্য) + Secrets থাকলে **signed release AAB** বানায় (Play-র জন্য)।
- **debug.keystore কমিট করা থাকে** (fixed signature) → রিইনস্টলে "package conflicts" হয় না, Pro-র Device Code স্থির থাকে।
- **signed AAB-এর জন্য ৪টা GitHub Secret** (প্রতি অ্যাপে আলাদা keystore):
  `<APP>_KEYSTORE_BASE64`, `<APP>_KEYSTORE_PASSWORD`, `<APP>_KEY_ALIAS`, `<APP>_KEY_PASSWORD`
- **🔴 keystore + পাসওয়ার্ড + keygen কখনো git-এ কমিট নয়** — শুধু debug.keystore। keystore হারালে অ্যাপ আর আপডেট করা যায় না → চিরকাল ব্যাকআপ।

---

## 4. 💰 মনিটাইজেশন — RSA device-locked key (এড ছাড়া)

- **মডেল:** অ্যাপ Play-তে **ফ্রি**; প্রিমিয়াম ফিচার **key দিয়ে আনলক**। টাকা নাও **bKash-এ, অ্যাপের বাইরে**।
- **কীভাবে সুরক্ষিত:** প্রতি ফোনের **Device Code** (ANDROID_ID)। ইউজার নিজে **RSA-2048 কী** বানায় (অফলাইন keygen HTML দিয়ে)। অ্যাপে শুধু **পাবলিক কী** থাকে → কী-ফর্মুলা অ্যাপ থেকে বের করা অসম্ভব। যাচাই: `SHA256withRSA`।
- **🔴 Play নিয়ম:** অ্যাপের ভেতরে **"কিনুন" বাটন/এক্সটার্নাল পেমেন্ট লিংক দিও না** (দিলে Play Billing বাধ্যতামূলক)। ইউজার বাইরে যোগাযোগ করে key নেবে — এটাই বৈধ।
- **ফিচার ফ্রি করলেও key কোড রেখে দাও** (dormant) — পরে নতুন প্রিমিয়াম ফিচারে কাজে লাগবে; শুধু "Pro আনলক" স্ক্রিন লুকিয়ে দাও।

---

## 5. 🎨 ডিজাইন কনভেনশন

- **Material 3**, থিম **Light/Dark** (সরল রাখো — "Follow system" না দিয়ে শুধু Light/Dark ভালো), অ্যাপ **অ্যাকসেন্ট কালার** পিকার।
- **মোবাইল-ফার্স্ট:** বড় ট্যাপ-টার্গেট, কনফার্মেশন ডায়ালগ, পাসওয়ার্ডে **👁 show/hide**।
- **নোটিফিকেশন:** channel `IMPORTANCE_HIGH` + `enableVibration` + `CATEGORY_REMINDER` + `DEFAULT_ALL` → সাউন্ড/হেডস-আপ।
- **আইকন:** 512×512 (Play আইকন), 1024×500 (feature graphic) — প্রোগ্রাম্যাটিকালি বানানো যায় (AI দিয়ে না → "Don't label assets")।

---

## 6. 📜 Google Play নীতিমালা ও প্রয়োজনীয়তা (মুখস্থ রাখার মতো)

1. **App Bundle (.aab) বাধ্যতামূলক** — নতুন অ্যাপে `.apk` আপলোড হয় না।
2. **Play App Signing** — Google আসল সিগনিং কী রাখে, তুমি "upload key" দিয়ে সাইন করো (Create app-এ Accept করতে হয়)।
3. **Target API level বাৎসরিক বাড়ে** — প্রতি বছর ~আগস্টে নতুন লেভেল লাগে (এখন 35, 2026-08-31-এর মধ্যে 36)। না বাড়ালে আপডেট release বন্ধ (অ্যাপ চলতেই থাকে)। ঠিক করতে শুধু compileSdk/targetSdk নম্বর বদল।
4. **নতুন পার্সোনাল অ্যাকাউন্ট:** Production খোলার আগে **≥12 জন opted-in টেস্টার × ≥14 দিন** closed testing (প্রতি নতুন অ্যাপে আলাদা করে)। ইমেল লিস্টে যোগ করা ≠ opted-in; টেস্টারকে **আসলে ইনস্টল** করতে হয়।
5. **Privacy Policy লিংক** বাধ্যতামূলক (ডেটা না নিলেও) — **Google Sites**-এ পেজ বানিয়ে দাও।
6. **Data safety ফর্ম** সৎভাবে — অফলাইন অ্যাপে **"No data collected/shared"** (নিজের Drive-এ ব্যাকআপ exempt)।
7. **Content rating** — IARC প্রশ্নমালা, "All Other App Types", সব No → সাধারণত Everyone/3+।
8. **App content ঘোষণা** (আমাদের স্ট্যান্ডার্ড উত্তর): Ads → No; Sign in details/App access → **No** (লগইন নেই; ঐচ্ছিক lock/fingerprint ইউজারের নিজের, রিভিউয়ারকে আটকায় না); Government/Financial/Health → নেই; Advertising ID → No; Target audience → 13+।
9. **versionCode প্রতি রিলিজে বাড়াতে হয়** — একবার ব্যবহৃত versionCode আর ব্যবহার করা যায় না (discard করলেও)।
10. **Exact alarm সাবধানে** — `USE_EXACT_ALARM` শুধু ক্লক/ক্যালেন্ডার অ্যাপের জন্য (নোট অ্যাপে দিলে Play রিজেক্ট ঝুঁকি)। নিরাপদ: permission ঘোষণা না করে `setExactAndAllowWhileIdle` শুধু `canScheduleExactAlarms()` true হলে, নাহলে inexact fallback।
11. **নিষিদ্ধ:** বিভ্রান্তিকর, অন্যের ব্র্যান্ড নকল, ভুয়া রিভিউ, অনুমতি ছাড়া ব্যক্তিগত ডেটা।

---

## 7. 🚀 সম্পূর্ণ পাবলিশিং ওয়ার্কফ্লো (নতুন অ্যাপ, ধাপে ধাপে)

```
1. Create app  → নাম, App, Free, package name, Play App Signing accept
2. App content → Privacy policy (Google Sites), App access=No, Ads=No,
                 Content rating, Target audience=13+, Data safety=No data,
                 Government/Financial/Health=নেই, Advertising ID=No
3. Store listing → নাম, short(≤80)/full desc, আইকন 512, feature 1024x500,
                 ফোন স্ক্রিনশট ≥2 (ট্যাবলেট/Chromebook/XR ঐচ্ছিক — বাদ দাও),
                 Category, contact email, AI assets=Don't label
4. Closed testing → Alpha track: AAB আপলোড, Countries যোগ (Bangladesh/all),
                 Testers ইমেল লিস্ট ≥12
5. Publishing overview → "Send changes for review"   ← এই ধাপ ভুলবে না!
6. রিভিউ পাস (৩–৭ দিন) → Closed testing লাইভ
7. Testers ট্যাব → opt-in লিংক কপি → ১২ জনকে পাঠাও → তারা ইনস্টল
8. ১৪ দিন + ১২ opted-in → Production → "Apply for production" → প্রশ্নমালা → রিভিউ → সবার জন্য
```

---

## 8. 🔄 আপডেট ওয়ার্কফ্লো (এই বা যেকোনো অ্যাপ)

```
1. কোড বদলাও
2. versionCode +1 (versionName ইচ্ছেমতো)   ← না বাড়ালে "already used" এরর
3. GitHub-এ push → CI নতুন signed AAB বানায়
4. AAB নামাও → Play → একই track → Create new release → আপলোড
5. Save → Publishing overview → "Send for review"   ← এটা না চাপলে লাইভ হয় না!
6. রিভিউ (আপডেটে দ্রুত) → টেস্টাররা Play-তে অটো আপডেট পায়
```

- **uninstall লাগে না, opt-in লিংক বদলায় না, ১৪ দিন রিসেট হয় না।**
- Production-এ গেলে এরপর আপডেটে আর ১৪ দিন লাগে না।

---

## 9. ⚠️ সাধারণ ভুল/ফাঁদ (আগে যেসব হয়েছে — এড়িয়ে চলো)

| ভুল/মেসেজ | আসল কারণ ও সমাধান |
|---|---|
| **Kotlin "Platform declaration clash"** | `var X by mutableStateOf() private set` + একই নামের `fun setX()` → JVM সংঘর্ষ। ফাংশন **রিনেম** করো (যেমন `setTheme`, `enableBiometric`)। |
| **"Version code N already used"** | ঐ versionCode আগে আপলোড হয়েছে (discard করলেও)। **versionCode বাড়াও** বা library থেকে যোগ করো। |
| **"Not yet sent for review"** | আপলোডই যথেষ্ট না — Publishing overview-তে **"Send for review"** চাপতে হয়। |
| **deobfuscation warning** | `isMinifyEnabled=false` বলে স্বাভাবিক। **নিরীহ, উপেক্ষা করো।** |
| **"-100% devices / no longer supports X devices"** | নতুন রিলিজে **AAB আপলোড করোনি** বলে (খালি রিলিজ = ০ ডিভাইস)। AAB আপলোড করলেই ঠিক। |
| **"must target at least API level 35"** | targetSdk পুরনো। compileSdk+targetSdk বাড়াও (২টা নম্বর)। |
| **compileSdk warning (AGP পুরনো)** | `android.suppressUnsupportedCompileSdk=<sdk>` দাও। |
| **Restore "0 notes"** | auto-mirror খালি স্টেট দিয়ে ফাইল ওভাররাইট করত। auto-mirror ডিফল্ট OFF, Restore = replace+confirm। |

---

## 10. ✅ নতুন অ্যাপ চেকলিস্ট (একবার বনাম প্রতিবার)

| কাজ | একবার (সব অ্যাপে) | প্রতি নতুন অ্যাপে |
|---|---|---|
| Play অ্যাকাউন্ট ($25) | ✅ | |
| পরিচয় যাচাই | ✅ | |
| নতুন package name | | ✅ |
| নতুন keystore + GitHub Secrets | | ✅ |
| Create app + App content + Store listing | | ✅ |
| Privacy policy (Google Sites) | | ✅ |
| **Closed test ১২ টেস্টার × ১৪ দিন** | | ✅ (প্রতি অ্যাপে) |
| একই অ্যাপ **আপডেট** | | ❌ (শুধু AAB+Send for review, ১৪ দিন লাগে না) |

---

## 11. 🔐 নিরাপত্তা নিয়ম (কঠোর)

- **কখনো কমিট নয়:** keystore (`.jks`), keystore পাসওয়ার্ড, keygen HTML (RSA প্রাইভেট কী), GitHub Secret মান। শুধু `debug.keystore` কমিট।
- **keystore + keygen চিরকাল Google Drive-এ ব্যাকআপ** — হারালে অ্যাপ আপডেট/key তৈরি বন্ধ।
- release সিগনিং শুধু env var (GitHub Secrets) থেকে, কোডে হার্ডকোড না।

---

## 12. 🌐 সার্ভার-নির্ভর অ্যাপ (কোর্স/লগইন/পার্সোনালাইজড কনটেন্ট)

অফলাইন মডেলে হয় না — ব্যাকএন্ড লাগে। দুই পথ:
- **A.** বিদ্যমান PHP/MySQL ওয়েবসাইটের সাথে যুক্ত অ্যাপ (লগইন + কে কী দেখবে ওখান থেকে)
- **B.** Firebase (Google-এর ফ্রি ব্যাকএন্ড — লগইন+ডেটা+রুল বিল্ট-ইন)

ভিডিও: **Unlisted YouTube** embed (সস্তা; ১০০% সুরক্ষিত না)। বিক্রি **ওয়েবসাইটে/bKash-এ**, অ্যাপ শুধু কেনা কনটেন্ট **দেখায়** (Play-compliant)। জটিলতা মাঝারি-কঠিন — অভিজ্ঞ হয়ে ধরা ভালো।

---

_রেফারেন্স ফাইল: `android-apps/clipnotes/` (কোড), `CHANGELOG.md` (ফিচার/ফিক্স লগ), `PLAY-CONSOLE-GUIDE.md` (Clip Notes-এর নির্দিষ্ট Play ধাপ)।_
