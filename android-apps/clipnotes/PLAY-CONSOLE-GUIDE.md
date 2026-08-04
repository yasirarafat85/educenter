# Clip Notes — সম্পূর্ণ গাইড (অ্যাপ + Google Play + ভবিষ্যৎ)

> এই ফাইলটা পুরো যাত্রার রেকর্ড ও ভবিষ্যতের ম্যানুয়াল। কোডিং না জেনেও এখান থেকে বোঝা যাবে —
> অ্যাপে কী আছে, কী কী করা হয়েছে, Play Console-এ কোন ধাপে কী করেছি, আর পরে কী করতে হবে।
>
> - ফিচার/বাগ-ফিক্সের বিস্তারিত টেকনিক্যাল লগ → [`CHANGELOG.md`](CHANGELOG.md)
> - অ্যাপের সংক্ষিপ্ত পরিচয় → [`README.md`](README.md)
> - সর্বশেষ আপডেট: ৪ আগস্ট ২০২৬

---

## 0. এক নজরে বর্তমান অবস্থা

| বিষয় | অবস্থা |
|---|---|
| অ্যাপের নাম | **Clip Notes** |
| প্যাকেজ নাম | **`com.yasirarafat.clipnotes`** |
| Play Store status | ✅ **Closed testing-এ লাইভ** (রিভিউ পাস) |
| এখন যা চলছে | ১২ জন টেস্টার ইনস্টল করছে, ১৪ দিন গোনা |
| পরের ধাপ | ১৪ দিন + ১২ opted-in টেস্টার → **Apply for production** → সবার জন্য |

---

## 1. অ্যাপটা কী ও কীভাবে বানানো

**Clip Notes** = একটা সহজ, অফলাইন নোট/ক্লিপবোর্ড অ্যাপ — বারবার লাগে এমন লেখা সেভ করে এক ট্যাপে কপি।

**টেকনিক্যাল ভিত্তি:**
- **Kotlin + Jetpack Compose (Material 3)** — আধুনিক Android UI
- **Room (SQLite)** — সব ডেটা ফোনেই, কোনো সার্ভার নেই (সম্পূর্ণ অফলাইন)
- **single-Activity** আর্কিটেকচার
- **বিল্ড শুধু GitHub Actions-এ** (লোকালে Google-এর সার্ভার ব্লক ছিল)

---

## 2. কী কী ফিচার বানানো হয়েছে

**মূল নোট ব্যবস্থাপনা:** নোট সেভ + এক ট্যাপে কপি (কপি-কাউন্ট), Favorites, Pin, Categories, Trash (restore সহ), Search, Sort (recent/A–Z/most copied), অন্য অ্যাপ থেকে Share করে সেভ, হোম-স্ক্রিন উইজেট।

**পরে যোগ করা ৫টা বড় ফিচার (সব Pro/key দিয়ে বা লক দিয়ে):**
1. 🎨 **প্রতি-নোট আলাদা কালার**
2. ☑️ **চেকলিস্ট নোট** (টিক-করা যায়)
3. ⏰ **রিমাইন্ডার + ক্যালেন্ডার** (নোটিফিকেশন, রিবুটেও টিকে থাকে)
4. 🔒 **নোট লক** — প্রতি-নোট আলাদা লক/আনলক, নাম দেখা যায় ভিতর লুকানো, ম্যানুয়াল বা অটো-টাইমার (10s/30s/1m/5m)
5. 👆 **ফিঙ্গারপ্রিন্ট আনলক**

**অন্যান্য:** Light/Dark থিম (Dark = Pro), অ্যাপ অ্যাকসেন্ট কালার, ব্যাকআপ/রিস্টোর ও এক্সপোর্ট/ইমপোর্ট (Pro, নিজের Google Drive/ফোনে)।

**⭐ Pro আনলক সিস্টেম (গুরুত্বপূর্ণ):**
- Dark থিম ও Backup শুধু Pro-তে
- প্রতিটা ফোনের একটা **Device Code** (ANDROID_ID ভিত্তিক)
- ইউজার (তুমি) নিজে **RSA-2048 কী** বানাও (আলাদা keygen টুল দিয়ে) — এক ফোনের কী অন্য ফোনে চলে না
- অ্যাপে শুধু **পাবলিক কী** থাকে, তাই কী-ফর্মুলা অ্যাপ থেকে বের করা অসম্ভব
- টাকা তুমি বাইরে (বিকাশ/হাতে) নাও, Play-তে কোনো পেমেন্ট নেই

---

## 3. কী কী বাগ ফিক্স হয়েছে (সংক্ষেপে)

- **লক করা নোটের ভিতর দেখা যাচ্ছিল** → প্রতি-নোট লক মডেলে বদল, নাম দেখায় ভিতর লুকায়
- **সব নোট একসাথে লক/আনলক হচ্ছিল** → প্রতিটা আলাদা করা হলো
- **ব্যাকআপ "Restored 0" বাগ** → auto-mirror বন্ধ, Restore = replace + কনফার্ম
- **Kotlin "declaration clash"** (কয়েকবার) → ফাংশন রিনেম করে ঠিক
- **রিইনস্টলে "package conflicts"** → ফিক্সড debug keystore কমিট করে ঠিক
- **Play-র জন্য API 35 দরকার** → compileSdk/targetSdk 35 করা হলো, versionCode 2

> পূর্ণ তালিকা ও কারণ [`CHANGELOG.md`](CHANGELOG.md)-এ।

---

## 4. বিল্ড সিস্টেম কীভাবে কাজ করে (মনে রাখা জরুরি)

- কোড **GitHub**-এ push করা হয় → **GitHub Actions** নিজে অ্যাপ বিল্ড করে
- দুটো ফাইল বানায়:
  - **`app-debug.apk`** → শুধু নিজের ফোনে টেস্টের জন্য (Play-তে লাগে না)
  - **`app-release.aab`** → **Google Play-তে এটাই আপলোড হয়** (signed)
- signed AAB বানাতে **৪টা GitHub Secret** লাগে (একবার সেট করা হয়ে গেছে):
  `CLIPNOTES_KEYSTORE_BASE64`, `CLIPNOTES_KEYSTORE_PASSWORD`, `CLIPNOTES_KEY_ALIAS`, `CLIPNOTES_KEY_PASSWORD`

**🔴 সিগনিং কী (keystore) চিরকাল সংরক্ষণ করতে হবে** — এই কী হারালে এই অ্যাপ আর কখনো আপডেট করা যাবে না। কী ফাইল + পাসওয়ার্ড আলাদাভাবে পাঠানো হয়েছে (`clipnotes-upload-key.jks` + `CLIPNOTES-KEYSTORE-README.txt`)। **এগুলো Google Drive-এ ব্যাকআপ রাখো, কখনো git-এ বা কাউকে দিও না।**

---

## 5. Google Play Console-এ যা যা করা হয়েছে (পুরো ধাপ, ক্রম অনুযায়ী)

**A. অ্যাকাউন্ট ও অ্যাপ তৈরি**
1. Play Console ডেভেলপার অ্যাকাউন্ট (একবারের $25 ফি, "Yourself" টাইপ)
2. **Create app** → নাম "Clip Notes", App, Free, প্যাকেজ `com.yasirarafat.clipnotes`
3. Declarations: Developer Program Policies ✅ + Play App Signing ✅

**B. App content (সব ঘোষণা)** — প্রতিটার উত্তর:
| ঘোষণা | উত্তর |
|---|---|
| Privacy policy | Google Sites-এ বানানো লিংক |
| Sign in details (App access) | **No** — লগইন নেই, রিভিউয়ার সব দেখতে পাবে |
| Ads | **No** — বিজ্ঞাপন নেই |
| Content rating | Questionnaire → "All Other App Types" → সব No → রেটিং পাওয়া গেছে |
| Target audience | **13+** (শিশুদের না) |
| Data safety | **No data collected / shared** (অফলাইন) |
| Government / Financial / Health | সব **নেই** |
| Advertising ID | **No** (ব্যবহার হয় না) |

**C. Store listing**
- নাম, Short description, Full description (লেখা রেডি করা ছিল)
- আইকন (512×512), Feature graphic (1024×500), ফোন স্ক্রিনশট (২+)
- ট্যাবলেট/Chromebook/XR স্ক্রিনশট — **বাদ** (লাগে না)
- Category: **Productivity**, Contact email
- AI asset declaration → **Don't label assets** (AI দিয়ে বানানো না)

**D. Closed testing**
1. Alpha ট্র্যাকে **`app-release.aab`** আপলোড (version 2, API 35)
2. **Countries/regions** যোগ (Bangladesh সহ ২৮টা)
3. **Testers** — ১২+ Gmail-এর একটা email list
4. Release notes দিয়ে **Review release**

**E. Submit ও Approve**
1. Publishing overview → **Submit changes for review**
2. Status: **In review** → কয়েক দিন পর → **Closed testing (লাইভ)** ✅
3. Managed publishing **OFF** রাখা (approve হলে অটো লাইভ)

**F. টেস্টারদের কাছে**
- Closed testing → Alpha → **Testers → How testers join → Copy link**
- লিংক টেস্টারদের পাঠানো → তারা "Become a tester" → Play থেকে Install → ১৪ দিন রাখা

---

## 6. 🔄 এই অ্যাপের আপডেট করতে হলে কী করবে

যখনই নতুন ফিচার/ফিক্স লাগবে:

1. **কোড বদলানো ও push** — (Claude/ডেভেলপার করবে) GitHub-এ push
2. **versionCode বাড়াতে হবে** — Play-র নিয়ম: প্রতিটা নতুন আপলোডে versionCode আগেরটার চেয়ে বড় হতে হবে (এখন 2, পরের বার 3, তারপর 4...)। এটা `app/build.gradle.kts`-এ। **প্রতিবার আপডেটে এটা বাড়িয়ে দেওয়া হবে।**
3. **CI নিজে নতুন signed AAB বানায়** (Secrets সেট আছে)
4. GitHub Actions থেকে নতুন **`app-release.aab`** নামাও
5. Play Console → Closed testing (বা Production) → **Create new release** → নতুন AAB আপলোড → release notes → **Rollout**
6. রিভিউ (আপডেটের রিভিউ সাধারণত প্রথমবারের চেয়ে দ্রুত) → লাইভ

**⚠️ একই keystore দিয়েই সব আপডেট signed হয় (অটোমেটিক, Secrets থেকে)। keystore বদলানো যাবে না।**

---

## 7. 🆕 নতুন আরেকটা অ্যাপ বানালে কী কী আবার করতে হবে

**একবার করা হয়ে গেছে — আর করতে হবে না (সব অ্যাপে শেয়ার্ড):**
- ✅ Play Console ডেভেলপার অ্যাকাউন্ট ($25 ফি) — একই অ্যাকাউন্টে যত খুশি অ্যাপ
- ✅ ডেভেলপার পরিচয় যাচাই (identity verification)

**প্রতিটা নতুন অ্যাপে আবার করতে হবে:**
| কাজ | নতুন অ্যাপে |
|---|---|
| নতুন **প্যাকেজ নাম** | হ্যাঁ (যেমন `com.yasirarafat.newapp`) |
| নতুন **signing keystore** | হ্যাঁ (প্রতি অ্যাপের আলাদা কী + আলাদা ব্যাকআপ) |
| নতুন **GitHub Secrets** (ঐ কীর) | হ্যাঁ |
| Play-তে **Create app** | হ্যাঁ |
| **App content** সব ঘোষণা | হ্যাঁ (তবে উত্তরগুলো একই ধরনের হলে দ্রুত) |
| **Store listing** (নাম/বর্ণনা/আইকন/স্ক্রিনশট) | হ্যাঁ (প্রতি অ্যাপের নিজস্ব) |
| **Privacy policy** পেজ | হ্যাঁ (Google Sites-এ নতুন পেজ) |
| **Closed testing ১২ টেস্টার × ১৪ দিন** | হ্যাঁ (প্রতিটা নতুন অ্যাপে আলাদা করে) |

**যা পুনরায় ব্যবহার করা যায় (সময় বাঁচে):**
- কোডের কাঠামো (একই টেমপ্লেট থেকে নতুন অ্যাপ দ্রুত বানানো)
- GitHub Actions বিল্ড-ওয়ার্কফ্লোর প্যাটার্ন
- keystore ও keygen বানানোর পদ্ধতি
- এই গাইডের সব ধাপ (একই ক্রমে)

> 💡 মূল কথা: **অ্যাকাউন্ট একবার, বাকি সব প্রতি অ্যাপে।** আর ১২ টেস্টার × ১৪ দিন নিয়মটা **প্রতিটা নতুন অ্যাপে আলাদা করে** পার করতে হয় (নতুন পার্সোনাল অ্যাকাউন্টের নিয়ম; অ্যাকাউন্ট পুরনো হলেও নতুন অ্যাপে closed test লাগে)।

---

## 8. 📜 Google-এর নিয়ম/ডকুমেন্ট কী বলে (মূল পয়েন্ট)

1. **নতুন পার্সোনাল অ্যাকাউন্টে Production খোলার আগে**: কমপক্ষে **১২ জন টেস্টার opted-in**, **কমপক্ষে ১৪ দিন** closed testing চালাতে হবে।
   → *Play Console Help: "Prepare your app for review / testing requirements"*
2. **Target API level**: নতুন অ্যাপ/আপডেটকে সাম্প্রতিক API টার্গেট করতে হবে (এখন **API 35 / Android 15**)। প্রতি বছর এটা বাড়ে — ভবিষ্যতে আবার বাড়াতে হতে পারে।
   → *"Target API level requirements for Google Play apps"*
3. **App Bundle (.aab) বাধ্যতামূলক** — নতুন অ্যাপে `.apk` আপলোড করা যায় না।
4. **Play App Signing** — Google আসল সিগনিং কী রাখে, তুমি "upload key" দিয়ে সাইন করো।
5. **Privacy Policy** লিংক দিতে হয় (ডেটা সংগ্রহ না করলেও)।
6. **Data safety** ফর্ম সঠিকভাবে পূরণ করতে হয় (মিথ্যা দিলে অ্যাকাউন্ট ঝুঁকিতে)।
7. **Content rating** IARC প্রশ্নমালা দিয়ে নিতে হয়।
8. **versionCode** প্রতিটা রিলিজে বাড়াতে হয়।
9. অ্যাপ **Developer Program Policies** মানতে হয় (কনটেন্ট, নিরাপত্তা, বিভ্রান্তিকর না হওয়া ইত্যাদি)।

> Google-এর ডকুমেন্ট সময়ে সময়ে বদলায় — সন্দেহ হলে Play Console-এর প্রতিটা পেজের "Learn more" লিংক পড়াই সবচেয়ে নির্ভরযোগ্য।

---

## 9. 🔐 গোপন জিনিস ও গুরুত্বপূর্ণ ফাইল (কখনো হারানো/শেয়ার করা যাবে না)

| জিনিস | কী | নিয়ম |
|---|---|---|
| **Upload keystore** (`clipnotes-upload-key.jks`) | অ্যাপ signing কী | চিরকাল ব্যাকআপ; হারালে অ্যাপ আপডেট বন্ধ |
| **Keystore পাসওয়ার্ড** (README ফাইলে) | কী খোলার পাসওয়ার্ড | গোপন; git-এ না |
| **Keygen HTML** (RSA প্রাইভেট কী সহ) | Pro key বানানোর টুল | শুধু তোমার কাছে; কখনো পাবলিক/অ্যাপে না |
| **GitHub Secrets** | CI-তে signing | Play/GitHub-এ সুরক্ষিত, কোডে না |

**🔴 এই ৪টা জিনিস কোনোভাবেই git repo-তে কমিট করা হয়নি ও করা যাবে না।** (এই গাইডেও শুধু নাম আছে, আসল পাসওয়ার্ড/কী নেই।)

---

## 10. ✅ চেকলিস্ট: এখন থেকে Production পর্যন্ত

```
✅ অ্যাপ তৈরি (সব ফিচার)
✅ signed AAB (API 35, versionCode 2)
✅ Play অ্যাকাউন্ট + Create app
✅ App content সব ঘোষণা
✅ Store listing (আইকন/গ্রাফিক/স্ক্রিনশট/বর্ণনা)
✅ Closed testing রিলিজ + রিভিউ পাস (লাইভ)
✅ opt-in লিংক পাওয়া গেছে
⏳ ১২ জন আসল টেস্টার ইনস্টল করছে   ← এখন এখানে
⏳ ১৪ দিন পূর্ণ হওয়া
⬜ Apply for production (প্রশ্নমালা)
⬜ Production রিভিউ → সবার জন্য লাইভ
```

**পরের করণীয়:** ১২ জন distinct টেস্টার নিশ্চিত করা (নিজের নিজের Gmail + ফোন), ১৪ দিন ইনস্টল রাখা → তারপর **Test and release → Production → Apply for production**।
