# Tailwind CSS বিল্ড (কম্পাইলড, CDN নয়)

এই সাইট আগে `cdn.tailwindcss.com` (রানটাইম JIT) ব্যবহার করত। এখন Tailwind **কম্পাইল করে স্ট্যাটিক CSS**
বানানো হয় (দ্রুত + ঝলকমুক্ত + নিরাপদ)। হোস্টিং-এ Node লাগে না — শুধু কম্পাইলড CSS ফাইল আপলোড হয়।
Node শুধু **ডেভেলপমেন্টে** (এই CSS রিবিল্ড করার সময়) লাগে।

## দুটো আলাদা CSS কেন?
- **পাবলিক সাইট** (`assets/css/tailwind.css`) — ডিফল্ট Tailwind প্যালেট (blue/purple ইত্যাদি আসল রঙ,
  কারণ পাবলিক থিম `style.css`-এর `--c-*` CSS ভ্যারিয়েবল দিয়ে হয়, Tailwind রঙ রিম্যাপ করে না)।
- **অ্যাডমিন প্যানেল** (`admin/assets/tailwind.css`) — `indigo`→`--c-primary`, `gray`→নিউট্রাল রোলে রিম্যাপ
  করা (থিম সিস্টেমের জন্য)। এই রিম্যাপ `tailwind.admin.js`-এর `colors` অংশে।

## ⚠️ কখন রিবিল্ড করতে হবে
কোনো পেজে **নতুন Tailwind ক্লাস** যোগ করলে (যেটা আগে কোথাও ব্যবহার হয়নি) — নাহলে সেই ক্লাস কম্পাইলড
CSS-এ থাকবে না, স্টাইল কাজ করবে না (নীরবে)। রিবিল্ড করলে স্ক্যানার নতুন ক্লাস ধরে CSS-এ যোগ করে।

**ডাইনামিক ক্লাস** (যেমন `notice.php`-এর `border-<?= $color ?>-500`) স্ক্যানার ধরতে পারে না — এগুলো
`tailwind.public.js`-এর `safelist`-এ রাখা আছে। নতুন ডাইনামিক ক্লাস বানালে safelist-এ যোগ করুন।

## রিবিল্ড কমান্ড (প্রজেক্ট রুট থেকে, Node ইনস্টল থাকা লাগবে)
```bash
# পাবলিক
npx tailwindcss@3.4.17 -c build/tailwind.public.js -i build/input.css -o assets/css/tailwind.css --minify

# অ্যাডমিন
npx tailwindcss@3.4.17 -c build/tailwind.admin.js -i build/input.css -o admin/assets/tailwind.css --minify
```
(`tailwindcss@3.x` ব্যবহার করুন — v4 এর কনফিগ ফরম্যাট আলাদা, এই সেটআপের সাথে যাবে না।)

## যাচাই (purge মিস ধরার জন্য)
রিবিল্ডের পর প্রতিটা পেজ curl করে রেন্ডার-করা `class` টোকেনগুলো কম্পাইলড CSS-এ আছে কিনা মিলিয়ে দেখা
ভালো (এই কোডবেসে লাইভ ভিজ্যুয়াল প্রিভিউ নেই বলে এটাই প্রধান সেফটি-চেক)।

## সেল্ফ-হোস্টেড JS লাইব্রেরি (CDN থেকে সরানো)
- `assets/js/lucide.js` (আইকন, পাবলিক+অ্যাডমিন)
- `assets/js/html2canvas.min.js` (register-thanks.php)
- `admin/assets/chart.js` (admin/index.php ড্যাশবোর্ড)
