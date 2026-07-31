# Clip Notes — Play Store পাবলিশিং গাইড (বাংলা)

এই ফাইলে Play Store-এ আপলোডের জন্য দরকারি **সব লেখা ও উত্তর** রেডি করা আছে — শুধু কপি-পেস্ট করবেন।

---

## ০) আগে যা লাগবে
1. **signed AAB ফাইল** — GitHub-এ ৪টা Secret যোগ করলে অটো তৈরি হয় (নিচে "AAB" অংশ দেখুন)
2. **Play Console অ্যাকাউন্ট** — https://play.google.com/console ($25 একবার)
3. **প্রাইভেসি পলিসি লিংক** — Google Sites-এ (ফ্রি, নিরপেক্ষ) পেজ বানিয়ে পাবলিশ করুন
   - `privacy-policy-text.md` ফাইলের লেখা Google Sites-এ পেস্ট করে পাবলিশ করলে একটা `sites.google.com/...` লিংক পাবেন
   - এই লিংকটাই Play Console-এ দিতে হবে

---

## ১) অ্যাপের নাম ও বিবরণ (কপি-পেস্ট)

**App name (৩০ অক্ষরের মধ্যে):**
```
Clip Notes: Save & Copy Text
```

**Short description (৮০ অক্ষরের মধ্যে):**
```
Save the text you use often and copy it with one tap. Fast, offline & private.
```

**Full description (৪০০০ অক্ষরের মধ্যে):**
```
Clip Notes is a simple, fast and private way to save the text you use again and
again — and copy it back with a single tap.

Tired of typing the same message, phone number, address, bank account or reply
over and over? Save it once in Clip Notes and copy it instantly whenever you need
it.

★ KEY FEATURES ★
• One-tap copy — tap any note to copy it to the clipboard instantly
• Quick add & edit — save a title and text in seconds
• Categories — organise your notes into folders
• Favorites — keep your most-used notes at the top
• Search — find any saved text in a moment
• Trash — deleted notes can be restored
• Share — send any note to another app
• Light & dark theme
• 100% OFFLINE — no internet needed
• PRIVATE — everything stays on your device; no account, no ads, no tracking

Perfect for office workers, freelancers, customer support, students and anyone who
sends the same text often. Save canned replies, addresses, IDs, templates and more.

No sign-up. No ads. No internet required. Your data never leaves your phone.
```

**বাংলা বিবরণ (চাইলে Full description-এর সাথে যোগ করতে পারেন):**
```
Clip Notes — বারবার লাগে এমন লেখা একবার সেভ করে রেখে এক ট্যাপে কপি করুন।
একই মেসেজ, নম্বর, ঠিকানা, অ্যাকাউন্ট নম্বর বা রিপ্লাই বারবার টাইপ করতে হবে না।
সম্পূর্ণ অফলাইন, কোনো অ্যাড নেই, সব তথ্য আপনার ফোনেই থাকে।
```

---

## ২) ক্যাটাগরি ও যোগাযোগ
- **App category**: Productivity
- **Tags**: notes, clipboard, copy paste, productivity
- **Contact email**: arafat.bd6@gmail.com
- **Privacy policy URL**: (উপরে বানানো লিংক)

---

## ৩) গ্রাফিক্স (store-assets ফোল্ডারে রেডি আছে)
- **App icon (512×512)**: `play_icon_512.png`
- **Feature graphic (1024×500)**: `play_feature_1024x500.png`
- **Screenshots**: কমপক্ষে ২টা (ফোনে অ্যাপ চালিয়ে স্ক্রিনশট নিন — Notes তালিকা, একটা নোট এডিট, Categories, ডার্ক থিম — ৪-৬টা দিলে ভালো)
  - স্ক্রিনশট নেওয়ার নিয়ম: ফোনে অ্যাপ খুলে Power+Volume Down চাপুন। Play ফোন স্ক্রিনশট নিজে থেকেই গ্রহণ করে (কমপক্ষে ৩২০px, সর্বোচ্চ ৩৮৪০px)।

---

## ৪) Data safety ফর্ম (Play Console-এ যা বাছবেন)
- Does your app collect or share any user data? → **No**
- Is all data encrypted in transit? → প্রযোজ্য নয় (কোনো ডেটা পাঠায় না)
- Do you provide a way to request data deletion? → অ্যাপ আনইনস্টল করলেই ডেটা মুছে যায়
  (আমাদের অ্যাপ কোনো ডেটা সংগ্রহ/শেয়ার করে না — তাই এই সেকশন খুব সহজ)

## ৫) Content rating প্রশ্নমালা (উত্তর)
- Category: **Utility / Productivity**
- সহিংসতা / যৌনতা / মাদক / জুয়া / ভয়ঙ্কর কনটেন্ট — সব **No**
- ইউজার-জেনারেটেড কনটেন্ট শেয়ার হয়? → **No**
- ফলাফল: সাধারণত **Everyone / 3+** রেটিং পাবে

## ৬) Target audience
- বয়স: **13+ (বা সবাই)** — অ্যাপে শিশু-নির্দিষ্ট কিছু নেই

---

## ৭) আপলোডের ধাপ (Play Console-এ)
1. **Create app** → নাম "Clip Notes", ভাষা English, Free, App
2. **Store listing** → উপরের লেখা + গ্রাফিক্স + স্ক্রিনশট দিন
3. **App content** → Privacy policy লিংক, Data safety, Content rating, Target audience, Ads (=No ads)
4. **Production (বা Closed testing)** → AAB ফাইল আপলোড
   - 🔴 নতুন পার্সোনাল অ্যাকাউন্ট হলে: আগে **Closed testing**-এ ২০ জন টেস্টার দিয়ে ১৪ দিন, তারপর Production
5. **Send for review** → Google রিভিউ করবে (কয়েক দিন), পাস করলে লাইভ

---

## AAB (signed ফাইল) কীভাবে পাবেন
GitHub-এ ৪টা Secret যোগ করার পর (README দেখুন), যেকোনো নতুন push-এ Actions একটা
`clipnotes-release-aab` আর্টিফ্যাক্ট বানায় → Actions ট্যাব → সর্বশেষ রান → Artifacts →
ডাউনলোড → ভেতরে `app-release.aab`। এই AAB-ই Play Console-এ আপলোড করবেন।
