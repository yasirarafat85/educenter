-- ============================================================
-- EduCenter Database Schema
-- এই ফাইলটি cPanel -> phpMyAdmin এ Import করুন
-- ধাপ: cPanel -> MySQL Databases -> নতুন ডাটাবেস ও ইউজার বানান
--       -> phpMyAdmin এ ঢুকে ডাটাবেস সিলেক্ট করুন -> Import ট্যাব
--       -> এই schema.sql ফাইলটি আপলোড করে Go চাপুন
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- settings : key-value আকারে সাইটের সাধারণ তথ্য
-- নতুন সেটিং লাগলে শুধু নতুন row insert করলেই হবে, টেবিল পরিবর্তনের দরকার নেই
-- ------------------------------------------------------------
CREATE TABLE settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO settings (setting_key, setting_value) VALUES
('site_name', 'EduCenter'),
('site_tagline', 'শিক্ষার আলো'),
('logo_path', 'https://i.postimg.cc/T3FzJyxM/logo.png'),
('contact_address', 'ঢাকা, বাংলাদেশ'),
('contact_phone', '+৮৮০ ১৭xxxxxxxx'),
('contact_email', 'info@educenter.com'),
('social_facebook', ''),
('social_twitter', ''),
('social_youtube', ''),
('social_instagram', ''),
('footer_text', '© 2025 EduCenter. সকল অধিকার সংরক্ষিত। Made with ❤️ in Bangladesh'),
('courier_active_provider', ''),
('courier_api_key', ''),
('courier_api_secret', ''),
('courier_base_url', '');

-- ------------------------------------------------------------
-- admin_users : admin panel এ লগইন করার জন্য
-- ------------------------------------------------------------
CREATE TABLE admin_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- এই টেবিলে কোনো ডিফল্ট এডমিন seed করা হয়নি (নিরাপত্তার জন্য)।
-- schema import করার পর setup-admin.php ফাইলটি ব্রাউজারে খুলে
-- নিজের ইউজারনেম/পাসওয়ার্ড দিয়ে প্রথম এডমিন অ্যাকাউন্ট বানান, তারপর সেই ফাইল ডিলিট করে দিন।

-- ------------------------------------------------------------
-- login_attempts : ব্রুট-ফোর্স প্রতিরোধের জন্য লগইন চেষ্টার লগ
-- ------------------------------------------------------------
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    username VARCHAR(50) NOT NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- phone_lookup_attempts : কোর্স রেজিস্ট্রেশন ফর্মে মোবাইল নম্বর দিয়ে আগের তথ্য
-- অটো-ফিল করার AJAX লুকআপ কেউ যেন স্ক্র্যাপ করতে না পারে সেজন্য রেট-লিমিট লগ
-- ------------------------------------------------------------
CREATE TABLE phone_lookup_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- form_submit_attempts : পাবলিক রেজিস্ট্রেশন/অর্ডার ফর্মে IP রেট-লিমিটের জন্য (স্প্যাম/বট ঠেকাতে)।
-- includes/functions.php এর form_submit_rate_limited()/form_record_submit() ব্যবহার করে।
-- ------------------------------------------------------------
CREATE TABLE form_submit_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- courses (parent — শুধু কোর্সের আইডেন্টিটি/নাম, হালকা টেবিল) ও course_batches (child — আসল
-- বিক্রয়যোগ্য/রেজিস্ট্রেশনযোগ্য ইউনিট, প্রতিটা ব্যাচের নিজস্ব সম্পূর্ণ তথ্য)।
--
-- ⚠️ এটা একটা parent/child পুনর্গঠন (আগে "courses" এক টেবিলেই এক রো = এক কোর্স+ব্যাচ কম্বিনেশন ছিল,
-- নতুন ব্যাচ খুলতে পুরো কোর্স আবার টাইপ করা লাগত)। মূল্য/ইনস্ট্রাক্টর/ডিউরেশন/বিবরণ/ছবি/ফিচার/
-- hide_parcel/registration_open/is_active — সবগুলোই ইচ্ছাকৃতভাবে ব্যাচ-ভিত্তিক (course_batches এ),
-- কোর্স-ভিত্তিক না — কারণ ভিন্ন ব্যাচে দাম/ইনস্ট্রাক্টর ভিন্ন হতে পারে (ইউজারের সিদ্ধান্ত)।
-- ------------------------------------------------------------
CREATE TABLE courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL UNIQUE, -- কোর্সের নাম — একবারই "খোলা" হয়, ব্যাচ যোগ করার সময় এই টাইটেল থেকে বেছে নেওয়া হয়
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    batch_name VARCHAR(100) NOT NULL, -- এই কোর্সের নির্দিষ্ট ব্যাচ (যেমন "৫ম ব্যাচ" বা "July_26") — অ্যাডমিন-অনলি, সাইটে দেখানো হয় না; রেজিস্ট্রেশনের সময় এই মান স্ন্যাপশট হয়ে registrations.batch এ সেভ হয়
    slug VARCHAR(255) NOT NULL UNIQUE,
    image VARCHAR(500),
    price VARCHAR(50),
    duration VARCHAR(100),
    instructor VARCHAR(100),
    description TEXT,
    hide_parcel TINYINT(1) NOT NULL DEFAULT 0, -- Yes হলে রেজিস্ট্রেশন ফর্মে রিসিভার নাম/নম্বর/ঠিকানা হাইড থাকবে (ফুল অনলাইন ব্যাচের জন্য)
    registration_open TINYINT(1) NOT NULL DEFAULT 1, -- এই নির্দিষ্ট ব্যাচের রেজিস্ট্রেশন চালু/বন্ধ (is_active থেকে আলাদা — বন্ধ হলে ব্যাচ সাইটে দেখাবে কিন্তু রেজিস্ট্রেশন ফর্ম আসবে না)
    is_active TINYINT(1) NOT NULL DEFAULT 1, -- এই ব্যাচ সাইটে দেখাবে কিনা
    sort_order INT NOT NULL DEFAULT 0, -- এই কোর্সের ব্যাচগুলোর মধ্যে ক্রম (কোর্স-স্কোপড, গ্লোবাল না)
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    UNIQUE KEY uq_course_batch (course_id, batch_name) -- একই কোর্সে ডুপ্লিকেট ব্যাচ নাম আটকায় (ভিন্ন কোর্সে একই ব্যাচ-নাম সমস্যা না, যেমন দুই কোর্সেই "May_26" ব্যাচ থাকতে পারে)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE course_features (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id INT UNSIGNED NOT NULL, -- ফিচারও ব্যাচ-ভিত্তিক (course_batches কে পয়েন্ট করে)
    feature_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (batch_id) REFERENCES course_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO courses (id, title, sort_order) VALUES
(1, 'গণিত মাস্টারি কোর্স', 1),
(2, 'ইংরেজি স্পোকেন কোর্স', 2),
(3, 'বিজ্ঞান অলিম্পিয়াড', 3),
(4, 'প্রোগ্রামিং বেসিক', 4);

INSERT INTO course_batches (id, course_id, batch_name, slug, image, price, duration, instructor, description, sort_order) VALUES
(1, 1, '১ম ব্যাচ', 'math-mastery', 'https://images.unsplash.com/photo-1509228468518-180dd4864904?w=400', '৳২,৫০০', '৩ মাস', 'আহমদ স্যার', 'সম্পূর্ণ গণিত কোর্স যা আপনাকে গণিতে পারদর্শী করে তুলবে। বীজগণিত, জ্যামিতি এবং ত্রিকোণমিতির সম্পূর্ণ কভারেজ।', 1),
(2, 2, '১ম ব্যাচ', 'english-spoken', 'https://images.unsplash.com/photo-1434030216411-0b793f4b4173?w=400', '৳২,০০০', '২ মাস', 'সারা ম্যাম', 'Spoken English এর জন্য সম্পূর্ণ কোর্স। Grammar থেকে শুরু করে fluent speaking পর্যন্ত।', 2),
(3, 3, '১ম ব্যাচ', 'science-olympiad', 'https://images.unsplash.com/photo-1532094349884-543bc11b234d?w=400', '৳৩,০০০', '৪ মাস', 'রহিম স্যার', 'বিজ্ঞান অলিম্পিয়াডের জন্য বিশেষ প্রস্তুতি। পদার্থবিজ্ঞান, রসায়ন এবং জীববিজ্ঞানের গভীর জ্ঞান।', 3),
(4, 4, '১ম ব্যাচ', 'programming-basic', 'https://images.unsplash.com/photo-1461749280684-dccba630e2f6?w=400', '৳৩,৫০০', '৫ মাস', 'তানভীর স্যার', 'Python এবং JavaScript এর মৌলিক বিষয় থেকে শুরু করে advanced programming পর্যন্ত।', 4);

INSERT INTO course_features (batch_id, feature_text, sort_order) VALUES
(1, 'লাইভ ক্লাস', 1), (1, 'রেকর্ডেড ভিডিও', 2), (1, 'প্র্যাকটিস টেস্ট', 3), (1, '১-১ সাপোর্ট', 4),
(2, 'ইন্টারঅ্যাক্টিভ সেশন', 1), (2, 'স্পিকিং প্র্যাকটিস', 2), (2, 'গ্রামার গাইড', 3), (2, 'সার্টিফিকেট', 4),
(3, 'অলিম্পিয়াড প্রশ্ন', 1), (3, 'লাইভ সলভিং', 2), (3, 'মক টেস্ট', 3), (3, 'এক্সপার্ট গাইডেন্স', 4),
(4, 'হ্যান্ডস-অন প্রজেক্ট', 1), (4, 'কোড রিভিউ', 2), (4, 'গিটহাব পোর্টফোলিও', 3), (4, 'ইন্ডাস্ট্রি গাইডেন্স', 4);

-- ------------------------------------------------------------
-- worksheets
-- ------------------------------------------------------------
CREATE TABLE worksheets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    image VARCHAR(500),
    price VARCHAR(50),
    pages VARCHAR(100),
    level VARCHAR(100),
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO worksheets (title, image, price, pages, level, description, sort_order) VALUES
('গণিত ওয়ার্কশিট সেট-১', 'https://images.unsplash.com/photo-1635070041078-e363dbe005cb?w=400', '৳৫০০', '৫০ পৃষ্ঠা', 'ক্লাস ৮-১০', 'বিস্তারিত গণিত অনুশীলনের জন্য প্রয়োজনীয় সব ওয়ার্কশিট। সমাধানসহ।', 1),
('ইংরেজি গ্রামার শিট', 'https://images.unsplash.com/photo-1481627834876-b7833e8f5570?w=400', '৳৩০০', '৩০ পৃষ্ঠা', 'সকল ক্লাস', 'ইংরেজি গ্রামারের সম্পূর্ণ গাইড এবং প্র্যাকটিস শিট।', 2),
('বিজ্ঞান প্র্যাকটিস শিট', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400', '৳৭০০', '৮০ পৃষ্ঠা', 'এসএসসি', 'পদার্থ, রসায়ন ও জীববিজ্ঞানের সম্পূর্ণ প্র্যাকটিস এবং মক টেস্ট।', 3);

-- ------------------------------------------------------------
-- products
-- ------------------------------------------------------------
CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    image VARCHAR(500),
    price VARCHAR(50),
    description TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_features (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id INT UNSIGNED NOT NULL,
    feature_text VARCHAR(255) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO products (id, title, image, price, description, sort_order) VALUES
(1, 'স্মার্ট ক্যালকুলেটর', 'https://images.unsplash.com/photo-1611224923853-80b023f02d71?w=400', '৳১,২০০', 'উন্নত বৈশিষ্ট্যসহ বৈজ্ঞানিক ক্যালকুলেটর।', 1),
(2, 'স্টাডি প্ল্যানার', 'https://images.unsplash.com/photo-1606092195730-5d7b9af1efc5?w=400', '৳৮০০', 'পড়াশোনার জন্য বিশেষভাবে ডিজাইন করা প্ল্যানার।', 2),
(3, 'জিওমেট্রি বক্স সেট', 'https://images.unsplash.com/photo-1513475382585-d06e58bcb0e0?w=400', '৳৪৫০', 'গণিত ও জ্যামিতির জন্য সম্পূর্ণ টুল কিট।', 3);

INSERT INTO product_features (product_id, feature_text, sort_order) VALUES
(1, 'বৈজ্ঞানিক হিসাব', 1), (1, 'গ্রাফিং', 2), (1, 'প্রোগ্রামেবল', 3),
(2, '১২ মাসের প্ল্যানার', 1), (2, 'গোল ট্র্যাকিং', 2), (2, 'প্রিমিয়াম পেপার', 3),
(3, 'কম্পাস', 1), (3, 'প্রোট্রাক্টর', 2), (3, 'রুলার', 3), (3, 'স্কেল', 4);

-- ------------------------------------------------------------
-- teachers
-- ------------------------------------------------------------
CREATE TABLE teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    subject VARCHAR(150),
    image VARCHAR(500),
    experience VARCHAR(100),
    quote TEXT,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO teachers (name, subject, image, experience, quote, sort_order) VALUES
('আহমদ স্যার', 'গণিত বিশেষজ্ঞ', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=400', '১০ বছর', 'গণিত শুধু সংখ্যা নয়, এটি চিন্তা করার একটি পদ্ধতি', 1),
('সারা ম্যাম', 'ইংরেজি বিশেষজ্ঞ', 'https://images.unsplash.com/photo-1494790108755-2616c2e9b0d0?w=400', '৮ বছর', 'ভাষা শিখুন, বিশ্বকে জয় করুন', 2),
('রহিম স্যার', 'বিজ্ঞান বিশেষজ্ঞ', 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=400', '১২ বছর', 'বিজ্ঞান হলো কৌতূহলের চাবিকাঠি', 3);

-- ------------------------------------------------------------
-- reviews
-- ------------------------------------------------------------
CREATE TABLE reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_name VARCHAR(150) NOT NULL,
    course_id INT UNSIGNED NULL, -- courses(parent) কে পয়েন্ট করে (ব্যাচ-নির্দিষ্ট না); বর্তমানে admin UI তে edit করা যায় না, শুধু course_label (ফ্রি-টেক্সট) ব্যবহার হয়
    course_label VARCHAR(150),
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    comment TEXT,
    image VARCHAR(500),
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO reviews (student_name, course_id, course_label, rating, comment, image, sort_order) VALUES
('রাহুল আহমেদ', 1, 'গণিত মাস্টারি', 5, 'অসাধারণ কোর্স! গণিতে আমার দুর্বলতা সম্পূর্ণ দূর হয়েছে। এখন গণিত আমার প্রিয় বিষয়!', 'https://images.unsplash.com/photo-1507003211169-0a1dd7228f2d?w=100', 1),
('ফাতিমা খাতুন', 2, 'ইংরেজি স্পোকেন', 5, 'খুব ভালো শিক্ষা পেয়েছি। এখন আত্মবিশ্বাসের সাথে ইংরেজি বলতে পারি। ধন্যবাদ!', 'https://images.unsplash.com/photo-1494790108755-2616c2e9b0d0?w=100', 2),
('করিম উল্লাহ', 3, 'বিজ্ঞান অলিম্পিয়াড', 5, 'চমৎকার প্রস্তুতি! অলিম্পিয়াডে ভালো ফলাফল করতে পেরেছি। সত্যিই গর্বিত!', 'https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100', 3);

-- ------------------------------------------------------------
-- notices
-- ------------------------------------------------------------
CREATE TABLE notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    content TEXT,
    notice_date DATE NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO notices (title, content, notice_date) VALUES
('🎯 নতুন কোর্স রেজিস্ট্রেশন শুরু', 'গণিত মাস্টারি কোর্সের জন্য রেজিস্ট্রেশন শুরু হয়েছে। সীমিত আসন, তাড়াতাড়ি রেজিস্ট্রেশন করুন!', '2025-09-18'),
('🏆 পরীক্ষার ফলাফল প্রকাশ', 'আগস্ট মাসের মডেল টেস্টের ফলাফল প্রকাশ করা হয়েছে। সবাই অসাধারণ ফলাফল করেছে!', '2025-09-15'),
('🏖️ ছুটির দিন ঘোষণা', 'আগামী ২৫ সেপ্টেম্বর সকল ক্লাস বন্ধ থাকবে। পরবর্তী ক্লাসের জন্য প্রস্তুত থাকুন।', '2025-09-10');

-- ------------------------------------------------------------
-- gallery
-- ------------------------------------------------------------
CREATE TABLE gallery (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image VARCHAR(500) NOT NULL,
    caption VARCHAR(255),
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO gallery (image, caption, sort_order) VALUES
('https://images.unsplash.com/photo-1523050854058-8df90110c9f1?w=400', 'Gallery 1', 1),
('https://images.unsplash.com/photo-1509228468518-180dd4864904?w=400', 'Gallery 2', 2),
('https://images.unsplash.com/photo-1434030216411-0b793f4b4173?w=400', 'Gallery 3', 3),
('https://images.unsplash.com/photo-1532094349884-543bc11b234d?w=400', 'Gallery 4', 4),
('https://images.unsplash.com/photo-1524995997946-a1c2e315a42f?w=400', 'Gallery 5', 5),
('https://images.unsplash.com/photo-1497633762265-9d179a990aa6?w=400', 'Gallery 6', 6);

-- ------------------------------------------------------------
-- faqs
-- ------------------------------------------------------------
CREATE TABLE faqs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    question VARCHAR(500) NOT NULL,
    answer TEXT,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO faqs (question, answer, sort_order) VALUES
('কোর্সে রেজিস্ট্রেশন কিভাবে করব?', 'যেকোনো কোর্সের পেজে গিয়ে "Register" বাটনে ক্লিক করুন। এরপর ফর্ম পূরণ করে সাবমিট করুন। আমাদের টিম আপনার সাথে যোগাযোগ করবে।', 1),
('ক্লাস কি অনলাইনে হবে?', 'হ্যাঁ, আমাদের সকল ক্লাস অনলাইনে লাইভ হয়। রেকর্ডেড ভার্সনও পাবেন যাতে মিস করলেও দেখে নিতে পারেন।', 2),
('কোর্স ফি কিভাবে পরিশোধ করব?', 'বিকাশ, নগদ, রকেট অথবা ব্যাংক ট্রান্সফারের মাধ্যমে ফি পরিশোধ করতে পারবেন। সব ধরনের পেমেন্ট মেথড গ্রহণযোগ্য।', 3),
('কোর্স শেষে কি সার্টিফিকেট পাবো?', 'হ্যাঁ, কোর্স সফলভাবে সম্পন্ন করলে অফিসিয়াল সার্টিফিকেট প্রদান করা হবে। এটি আপনার CV তে অনেক কাজে আসবে।', 4);

-- ------------------------------------------------------------
-- registrations : Register/Order ফর্ম থেকে আসা সব তথ্য (Phase 5 এ ব্যবহার হবে)
-- ------------------------------------------------------------
CREATE TABLE registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('course', 'worksheet', 'product') NOT NULL,
    item_id INT UNSIGNED NOT NULL, -- type='course' হলে course_batches.id কে পয়েন্ট করে (courses parent টেবিলকে না — একটা ব্যাচই আসল রেজিস্ট্রেশনযোগ্য ইউনিট)
    item_title VARCHAR(255) NOT NULL,
    batch VARCHAR(100), -- শুধু কোর্স টাইপের জন্য — রেজিস্ট্রেশনের সময় course_batches.batch_name থেকে স্ন্যাপশট নেওয়া হয় (ব্যাচের নাম পরে বদলালেও পুরনো রেজিস্ট্রেশনের ব্যাচ অপরিবর্তিত থাকে)
    customer_name VARCHAR(150) NOT NULL,  -- কোর্সের ক্ষেত্রে: শিশুর নাম
    phone VARCHAR(30) NOT NULL,           -- কোর্সের ক্ষেত্রে: মায়ের মোবাইল নম্বর
    email VARCHAR(150),
    address VARCHAR(500),                 -- কোর্সের ক্ষেত্রে: রিসিভারের ঠিকানা (hide_parcel হলে NULL)
    district VARCHAR(100),
    thana VARCHAR(100),
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    notes TEXT,
    -- নিচের কলামগুলো শুধু কোর্স রেজিস্ট্রেশন ফর্মের জন্য (শিশুর মেধা বিকাশ ফর্ম)
    date_of_birth DATE NULL,
    facebook_id VARCHAR(150) NULL,
    father_mobile VARCHAR(30) NULL,
    receiver_name VARCHAR(150) NULL,      -- hide_parcel হলে NULL থাকবে
    receiver_phone VARCHAR(30) NULL,      -- hide_parcel হলে NULL থাকবে
    status ENUM('pending', 'confirmed', 'shipped', 'delivered', 'cancelled') NOT NULL DEFAULT 'pending',
    courier_provider VARCHAR(50),
    courier_consignment_id VARCHAR(100),
    courier_active TINYINT(1) NOT NULL DEFAULT 1, -- অ্যাডমিন এটা বন্ধ করলে এই রেজিস্ট্রেশন কুরিয়ার লিস্টে দেখা যাবে (ইতিহাসের জন্য) কিন্তু বাল্ক-সিলেক্ট/পাঠানোর জন্য বাছাই করা যাবে না — যেমন "এটা কুরিয়ারে যাবে না" এমন confirmed অর্ডার
    income_approved TINYINT(1) NOT NULL DEFAULT 0, -- এই অর্ডার থেকে আয় হিসেবে অনুমোদন করা হয়েছে কিনা (income টেবিলে ডুপ্লিকেট এন্ট্রি ঠেকাতে)
    income_amount DECIMAL(10,2) NULL,               -- অনুমোদনের সময় যে পরিমাণ আয় ধরা হয়েছিল
    approved_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_phone_type (phone, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- finance_categories : আয়/ব্যয়ের ক্যাটেগরি (কোর্স/ওয়ার্কশিট/প্রোডাক্ট ডিফল্ট + অ্যাডমিন নতুন যোগ করতে পারবে)
-- ------------------------------------------------------------
CREATE TABLE finance_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type ENUM('income', 'expense') NOT NULL,
    name VARCHAR(100) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 0, -- system ক্যাটেগরি (কোর্স/ওয়ার্কশিট/প্রোডাক্ট) ডিলিট করা যাবে না
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_type_name (type, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO finance_categories (type, name, is_system) VALUES
('income', 'কোর্স', 1), ('income', 'ওয়ার্কশিট', 1), ('income', 'প্রোডাক্ট', 1),
('expense', 'কোর্স', 1), ('expense', 'ওয়ার্কশিট', 1), ('expense', 'প্রোডাক্ট', 1);

-- ------------------------------------------------------------
-- income : আয়ের হিসাব — রেজিস্ট্রেশন অনুমোদন করলে অটো তৈরি হয়, অথবা ম্যানুয়ালি যোগ করা যায়
-- ------------------------------------------------------------
CREATE TABLE income (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NULL, -- কোনো রেজিস্ট্রেশন অনুমোদন থেকে অটো তৈরি হলে লিংক থাকবে, ম্যানুয়াল হলে NULL
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    income_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES finance_categories(id),
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- expenses : খরচের হিসাব — অ্যাডমিন ম্যানুয়ালি যোগ করবে
-- ------------------------------------------------------------
CREATE TABLE expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    description VARCHAR(255),
    expense_date DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES finance_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- courier_batches : একটা registration থেকে একাধিক স্বতন্ত্র কুরিয়ার "ব্যাচ"/চালান (যেমন প্রতি মাসে
-- ওয়ার্কশিট পাঠানো একটা চলমান কোর্সের জন্য) — প্রতিটা ব্যাচ নিজের item/amount/স্ট্যাটাস/consignment
-- নিয়ে স্বাধীনভাবে ট্র্যাক হয়। এটাই এখন courier_order_data এর জায়গা নিয়েছে (নিচে দ্রষ্টব্য — সেই
-- টেবিল legacy/অব্যবহৃত রাখা হয়েছে, ডেটা মাইগ্রেট করা হয়েছে)।
-- ------------------------------------------------------------
CREATE TABLE courier_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    period_label VARCHAR(100) NOT NULL DEFAULT '', -- কোন মাস/কিস্তির চালান বোঝাতে অ্যাডমিন নিজে বসান, যেমন "আগস্ট ২০২৬" বা "৩য় কিস্তি"
    recipient_name VARCHAR(150),
    recipient_phone VARCHAR(30),
    recipient_secondary_phone VARCHAR(30),
    recipient_address VARCHAR(500),
    item_description VARCHAR(255),
    item_quantity INT UNSIGNED DEFAULT 1,
    item_weight DECIMAL(5,2) DEFAULT 0.5,
    item_type TINYINT UNSIGNED DEFAULT 2, -- 1 = Document, 2 = Parcel
    delivery_type TINYINT UNSIGNED DEFAULT 48, -- 48 = Normal Delivery, 12 = On Demand Delivery
    special_instruction VARCHAR(500),
    amount_to_collect DECIMAL(10,2) DEFAULT 0,
    courier_provider VARCHAR(50),
    courier_consignment_id VARCHAR(100),
    tracking_url VARCHAR(500),
    delivery_fee DECIMAL(10,2) NULL,
    send_status VARCHAR(20) NOT NULL DEFAULT 'draft', -- draft (তৈরি হয়েছে, পাঠানো হয়নি) | sent (সফল) | failed
    sent_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    INDEX idx_registration (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- courier_shipments : কুরিয়ার API রেসপন্সের raw লগ, প্রতিটা পাঠানোর চেষ্টার জন্য একটা রো —
-- এখন courier_batches এর একটা নির্দিষ্ট ব্যাচের সাথে যুক্ত (batch_id), registration_id ডিনরমালাইজড রাখা
-- হলো সহজ কোয়েরির জন্য
-- ------------------------------------------------------------
CREATE TABLE courier_shipments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    batch_id INT UNSIGNED NULL,
    provider VARCHAR(50) NOT NULL,
    consignment_id VARCHAR(100),
    tracking_url VARCHAR(500),
    delivery_fee DECIMAL(10,2) NULL, -- কুরিয়ার প্রোভাইডার অর্ডার তৈরির সময় যে ডেলিভারি ফি রিটার্ন করে (Pathao দেয়, Steadfast দেয় না — NULL থাকবে)
    status VARCHAR(50),
    raw_response TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
    FOREIGN KEY (batch_id) REFERENCES courier_batches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- courier_order_data : [LEGACY/অব্যবহৃত, courier_batches দিয়ে প্রতিস্থাপিত] — একটা registration এ
-- সর্বোচ্চ একটা "মূল কুরিয়ার ডেটা" রাখতে পারত, একাধিক মাসিক চালান সাপোর্ট করত না। কোড থেকে আর লেখা/পড়া
-- হয় না, শুধু পুরনো ডেটা সংরক্ষণের জন্য টেবিলটা রাখা আছে (ড্রপ করা হয়নি)।
-- ------------------------------------------------------------
CREATE TABLE courier_order_data (
    registration_id INT UNSIGNED PRIMARY KEY,
    recipient_name VARCHAR(150),
    recipient_phone VARCHAR(30),
    recipient_secondary_phone VARCHAR(30),
    recipient_address VARCHAR(500),
    item_description VARCHAR(255),
    item_quantity INT UNSIGNED,
    item_weight DECIMAL(5,2),
    item_type TINYINT UNSIGNED, -- 1 = Document, 2 = Parcel
    delivery_type TINYINT UNSIGNED, -- 48 = Normal Delivery, 12 = On Demand Delivery
    special_instruction VARCHAR(500),
    amount_to_collect DECIMAL(10,2),
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- confirmation_downloads : register-thanks.php তে "ডাউনলোড করুন" বাটনে ক্লিক করলে (কনফার্মেশন কার্ড
-- ছবি হিসেবে সেভ করার সময়) একটা লগ এন্ট্রি হয় — অ্যাডমিন প্যানেলে কে কবে ডাউনলোড করেছে দেখা যায়
-- ------------------------------------------------------------
CREATE TABLE confirmation_downloads (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45),
    downloaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- payment_methods : রেজিস্ট্রেশন/অর্ডার সফল হওয়ার পর থ্যাংক-ইউ পেজে দেখানো পেমেন্ট নাম্বার ও WhatsApp
-- বাটন — অ্যাডমিন থেকে নিয়ন্ত্রিত (admin/payment-methods.php)। channel দিয়ে বিকাশ/নগদ/ব্যাংক/WhatsApp আলাদা,
-- scope_all=0 হলে scope_items (JSON) দিয়ে নির্দিষ্ট কোর্স/আইটেমে সীমাবদ্ধ করা যায়।
-- ------------------------------------------------------------
CREATE TABLE payment_methods (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel VARCHAR(20) NOT NULL DEFAULT 'bkash',   -- bkash / nagad / rocket / bank / whatsapp / other
    value VARCHAR(150) NOT NULL,                     -- নাম্বার অথবা অ্যাকাউন্ট
    instruction VARCHAR(255) DEFAULT NULL,           -- নির্দেশনা/নোট
    scope_all TINYINT(1) NOT NULL DEFAULT 1,         -- 1 = সব আইটেমে; 0 = নির্দিষ্ট আইটেমে
    scope_items TEXT DEFAULT NULL,                   -- JSON টোকেন লিস্ট (scope_all=0 হলে): ["course:5","product:2"]
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_active_sort (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- visitor_logs : পাবলিক সাইটের প্রতিটা পেজ-লোডে একটা এন্ট্রি (includes/site-header.php থেকে) —
-- ডাউনলোড লগ থেকে সম্পূর্ণ আলাদা, সাধারণ ভিজিটর ট্র্যাকিং এর জন্য
-- ------------------------------------------------------------
CREATE TABLE visitor_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45),
    page_url VARCHAR(500),
    user_agent VARCHAR(255),
    visited_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_visited_at (visited_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- আর্কাইভ/রিস্টোর — ডিলিট করা কনটেন্ট (রো + সব child) JSON বান্ডল হিসেবে রাখে, আসল id সহ ফিরিয়ে আনা যায়
CREATE TABLE archived_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type VARCHAR(40)  NOT NULL,
    original_id INT UNSIGNED NOT NULL,
    label       VARCHAR(255) DEFAULT NULL,
    data_json   LONGTEXT     NOT NULL,
    deleted_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_entity (entity_type),
    KEY idx_deleted (deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- কুরিয়ার কালেকশন রিডিজাইন (COURIER-REDESIGN-PLAN.md) — per-batch breakdown + কাস্টম নোট + প্রিসেট
ALTER TABLE courier_batches
  ADD COLUMN monthly_multiplier DECIMAL(3,1)  NOT NULL DEFAULT 1       AFTER amount_to_collect,
  ADD COLUMN delivery_zone      VARCHAR(20)             DEFAULT 'dhaka' AFTER monthly_multiplier,
  ADD COLUMN weight_extra       TINYINT(1)     NOT NULL DEFAULT 0       AFTER delivery_zone,
  ADD COLUMN adjustment         DECIMAL(10,2)  NOT NULL DEFAULT 0       AFTER weight_extra,
  ADD COLUMN adjustment_reason  VARCHAR(60)             DEFAULT NULL    AFTER adjustment;

CREATE TABLE courier_note_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(80) NOT NULL,
  color      VARCHAR(20) NOT NULL DEFAULT 'amber',
  sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE registration_courier_notes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  note_type_id    INT UNSIGNED NULL,
  custom_text     VARCHAR(120) DEFAULT NULL,
  color           VARCHAR(20)  DEFAULT NULL,
  FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (note_type_id)    REFERENCES courier_note_types(id) ON DELETE SET NULL,
  INDEX idx_reg (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('courier_dc_dhaka','60'), ('courier_dc_near','80'), ('courier_dc_outside','120'), ('courier_weight_extra','20');


-- ------------------------------------------------------------
-- social_posts : পাবলিক সাইটের "আমাদের ফেসবুকে" সেকশনে দেখানো পোস্ট/রিলের লিংক
-- (ধরন লিংক দেখেই অটো শনাক্ত হয় — includes/functions.php এর fb_link_kind())
-- ------------------------------------------------------------
CREATE TABLE social_posts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) DEFAULT NULL,
    image       VARCHAR(255) DEFAULT NULL,
    excerpt     VARCHAR(300) DEFAULT NULL,
    url         VARCHAR(500) NOT NULL,
    is_featured TINYINT(1)   NOT NULL DEFAULT 0,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  INT          NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active_order (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
