module.exports = {
  // 🔴 পাথ **রিলেটিভ** (২০২৬-০৯-২৭) — আগে `D:/clude_project/website/...` হার্ডকোড ছিল,
  // ফলে অন্য ফোল্ডার/মেশিন থেকে রিবিল্ড করলে স্ক্যানার কিছুই খুঁজে পেত না আর কম্পাইলড
  // CSS প্রায় খালি হয়ে সাইটের স্টাইল পুরো ভেঙে যেত। কমান্ডটা সবসময় প্রজেক্ট রুট থেকে
  // চালান: npx tailwindcss@3.4.17 -c build/tailwind.public.js -i build/input.css -o assets/css/tailwind.css --minify
  content: [
    './*.php',
    './includes/*.php',
    './includes/courier/*.php',
    './admin/login.php',
  ],
  // ⚠️ নিচের প্রথম তিন সারি **ঐতিহাসিক** — notice.php আগে ডাইনামিক
  //    border-{color}-500 / text-{color}-700 / bg-{color}-200 বানাত; ২০২৬-১০-২০ থেকে
  //    নোটিশ কার্ড inline CSS-ভ্যারিয়েবল (`--n`/`--nd`) ব্যবহার করে, তাই ওগুলো আর লাগে না।
  //    🔴 তবু মুছে ফেলা হয়নি — safelist বদলালে **রিবিল্ড** লাগত, আর না-মুছে রাখার খরচ প্রায় শূন্য।
  //    index.php-এর স্ট্যাট (bg-{color}-100 / text-{color}-600) এখনো ডাইনামিক, ওগুলো দরকারি।
  safelist: [
    'border-blue-500','border-green-500','border-yellow-500','border-purple-500','border-red-500',
    'text-blue-700','text-green-700','text-yellow-700','text-purple-700','text-red-700',
    'bg-blue-200','bg-green-200','bg-yellow-200','bg-purple-200','bg-red-200',
    // index.php হোমপেজ স্ট্যাট — value/label অ্যাডমিন-এডিটযোগ্য, রঙ ক্লাস PHP লুপে ডাইনামিক (স্ক্যানার ধরে না)
    'bg-blue-100','bg-green-100','bg-purple-100','bg-red-100',
    'text-blue-600','text-green-600','text-purple-600','text-red-600',
  ],
  theme: { extend: {} },
  plugins: [],
};
