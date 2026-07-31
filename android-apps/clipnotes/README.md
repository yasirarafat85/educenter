# Clip Notes — Android অ্যাপ

দরকারি লেখা সেভ করে রেখে **এক ট্যাপে কপি** করার অ্যাপ। সব ডেটা ফোনের ভেতরেই থাকে (SQLite), ইন্টারনেট লাগে না।

- **অ্যাপের নাম**: Clip Notes (বদলানো সহজ — `app/src/main/res/values/strings.xml`)
- **প্যাকেজ (Play Store-এ স্থায়ী আইডি)**: `com.yasirarafat.clipnotes`
- **সর্বনিম্ন Android**: 7.0 (প্রায় সব ফোনে চলবে)

## ✅ ফিচার
- সেভ করা লেখার তালিকা, কার্ডে ট্যাপ বা "Copy" বাটনে এক ক্লিকে কপি
- নতুন যোগ / এডিট (শিরোনাম ঐচ্ছিক + মূল লেখা)
- **Categories** (ফোল্ডার), **Favorites** (তারকা), **Trash** (ডিলিট করা লেখা ফেরানো যায়)
- **Search** — লেখার মধ্যে খোঁজা
- **Settings** — লাইট/ডার্ক/সিস্টেম থিম
- Share (অন্য অ্যাপে পাঠানো)

---

## 📱 অ্যাপটা কীভাবে তৈরি হয় (আপনার কম্পিউটারে কিছু লাগবে না)

কোড GitHub-এ পুশ হলে **GitHub Actions** নিজে থেকে অ্যাপ বিল্ড করে দেয়:

1. GitHub-এ রিপোর **Actions** ট্যাবে যান → "Build Clip Notes (Android)" রান দেখুন।
2. সবুজ ✓ হলে রানটায় ঢুকুন → নিচে **Artifacts** সেকশন।
3. **`clipnotes-debug-apk`** ডাউনলোড করুন → ভেতরে `app-debug.apk`।
4. এই APK ফোনে নিয়ে ইনস্টল করুন (সেটিংসে "Unknown sources"/"এই উৎস থেকে ইনস্টল" অনুমতি দিতে হতে পারে)।
5. অ্যাপটা চালিয়ে টেস্ট করুন — লেখা যোগ করুন, কপি করুন, সব ঠিকমতো কাজ করছে কিনা দেখুন।

> এই **debug APK** শুধু নিজে টেস্ট করার জন্য — Play Store-এ আপলোডের জন্য আলাদা **signed AAB** লাগে (নিচে দেখুন)।

---

## 🚀 Play Store-এ পাবলিশ করার ধাপ (পরে করব একসাথে)

1. **Google Play Console অ্যাকাউন্ট** — একবারের $25 ফি + পরিচয় যাচাই।
2. একটা **signing key (keystore)** তৈরি করা হবে (আমি বানিয়ে দেব, আপনি নিরাপদে সংরক্ষণ করবেন)।
3. keystore + পাসওয়ার্ড GitHub-এর **Secrets**-এ যোগ করা হবে — তখন Actions একটা **signed release AAB** বানাবে।
4. সেই AAB Play Console-এ আপলোড → স্টোর লিস্টিং (আইকন, স্ক্রিনশট, বিবরণ) → রিভিউ → লাইভ।

Play Store-এর জন্য তৈরি অ্যাসেট `store-assets/` ফোল্ডারে আছে:
- `play_icon_512.png` — ৫১২×৫১২ অ্যাপ আইকন
- `play_feature_1024x500.png` — ফিচার গ্রাফিক

---

## 🔧 টেকনিক্যাল (রেফারেন্স)
- Kotlin + Jetpack Compose (Material 3) + Room ডেটাবেস
- Gradle 8.7, AGP 8.5.2, Kotlin 1.9.24, compileSdk 34, minSdk 24
- বিল্ড কনফিগ: `app/build.gradle.kts`; CI: `.github/workflows/android-clipnotes.yml`
