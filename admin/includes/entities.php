<?php
// প্রতিটা কনটেন্ট টাইপের কনফিগারেশন — নতুন কিছু যোগ/পরিবর্তন করতে হলে শুধু এই ফাইল এডিট করলেই চলবে
// (admin/manage.php এই কনফিগ পড়ে জেনেরিক ভাবে লিস্ট/অ্যাড/এডিট/ডিলিট ফর্ম বানায়)

function get_entities(): array
{
    return [
        // কোর্স এখন parent/child — এই entity শুধু কোর্সের "নাম/আইডেন্টিটি" (title), আসল বিক্রয়যোগ্য
        // তথ্য (দাম/ইনস্ট্রাক্টর/ছবি/বিবরণ/ফিচার/hide_parcel/registration_open/is_active) প্রতিটা
        // ব্যাচের নিজস্ব (course_batches টেবিল, admin/course-batches.php ডেডিকেটেড পেজে পরিচালিত —
        // জেনেরিক manage.php ইঞ্জিনের বাইরে, কারণ parent-এর ভেতরে child-এর নেস্টেড লিস্ট+ফর্ম এই
        // জেনেরিক ইঞ্জিন সাপোর্ট করে না)।
        'courses' => [
            'label' => 'কোর্স',
            'label_plural' => 'কোর্স সমূহ',
            'table' => 'courses',
            'order_by' => 'sort_order ASC, id DESC',
            'fields' => [
                'title'      => ['label' => 'কোর্সের নাম', 'type' => 'text', 'required' => true],
                'sort_order' => ['label' => 'ক্রম নম্বর (ছোট সংখ্যা আগে দেখাবে, অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['title'],
        ],

        'worksheets' => [
            'label' => 'ওয়ার্কশিট',
            'label_plural' => 'ওয়ার্কশিট সমূহ',
            'table' => 'worksheets',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => 'worksheets',
            'fields' => [
                'title'       => ['label' => 'টাইটেল', 'type' => 'text', 'required' => true],
                'image'       => ['label' => 'ছবি', 'type' => 'image'],
                'price'       => ['label' => 'মূল্য', 'type' => 'text'],
                'pages'       => ['label' => 'পৃষ্ঠা সংখ্যা', 'type' => 'text'],
                'level'       => ['label' => 'লেভেল/ক্লাস', 'type' => 'text'],
                'description' => ['label' => 'বিবরণ', 'type' => 'textarea'],
                'is_active'   => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'ওয়ার্কশিটটি সাইটে দেখানো'],
                'sort_order'  => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['title', 'price', 'level', 'is_active'],
        ],

        'products' => [
            'label' => 'প্রোডাক্ট',
            'label_plural' => 'প্রোডাক্ট সমূহ',
            'table' => 'products',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => 'products',
            'fields' => [
                'title'       => ['label' => 'টাইটেল', 'type' => 'text', 'required' => true],
                'image'       => ['label' => 'ছবি', 'type' => 'image'],
                'price'       => ['label' => 'মূল্য', 'type' => 'text'],
                'description' => ['label' => 'বিবরণ', 'type' => 'textarea'],
                'features'    => ['label' => 'বৈশিষ্ট্য (প্রতি লাইনে একটি)', 'type' => 'lines', 'child_table' => 'product_features', 'child_fk' => 'product_id', 'child_col' => 'feature_text'],
                'is_active'   => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'প্রোডাক্টটি সাইটে দেখানো'],
                'sort_order'  => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['title', 'price', 'is_active'],
        ],

        'teachers' => [
            'label' => 'শিক্ষক',
            'label_plural' => 'শিক্ষক মন্ডলী',
            'table' => 'teachers',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => 'teachers',
            'fields' => [
                'name'       => ['label' => 'নাম', 'type' => 'text', 'required' => true],
                'subject'    => ['label' => 'বিষয়', 'type' => 'text'],
                'image'      => ['label' => 'ছবি', 'type' => 'image'],
                'experience' => ['label' => 'অভিজ্ঞতা (যেমন ১০ বছর)', 'type' => 'text'],
                'quote'      => ['label' => 'উক্তি', 'type' => 'textarea'],
                'is_active'  => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'শিক্ষকের প্রোফাইল সাইটে দেখানো'],
                'sort_order' => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['name', 'subject', 'is_active'],
        ],

        'reviews' => [
            'label' => 'রিভিউ',
            'label_plural' => 'শিক্ষার্থীদের মতামত',
            'table' => 'reviews',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => 'reviews',
            'fields' => [
                'student_name' => ['label' => 'শিক্ষার্থীর নাম', 'type' => 'text', 'required' => true],
                'course_label' => ['label' => 'কোর্সের নাম (টেক্সট হিসেবে)', 'type' => 'text'],
                'rating'       => ['label' => 'রেটিং (১-৫)', 'type' => 'number', 'default' => 5],
                'comment'      => ['label' => 'মন্তব্য', 'type' => 'textarea'],
                'image'        => ['label' => 'ছবি', 'type' => 'image'],
                'is_active'    => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'রিভিউটি সাইটে দেখানো'],
                'sort_order'   => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['student_name', 'course_label', 'rating', 'is_active'],
        ],

        // ফেসবুক পোস্ট/রিল — অ্যাডমিন শুধু লিংক পেস্ট করবেন, ধরন (পোস্ট/রিল/ভিডিও) লিংক দেখে
        // অটো শনাক্ত হয় (includes/functions.php এর fb_link_kind())। পোস্টটি পাবলিক হতে হবে।
        'social_posts' => [
            'label' => 'ফেসবুক পোস্ট',
            'label_plural' => 'ফেসবুক পোস্ট',
            'table' => 'social_posts',
            'upload_dir' => 'social',
            'order_by' => 'is_featured DESC, sort_order ASC, id DESC',
            'fields' => [
                'url'         => ['label' => 'ফেসবুক লিংক (পোস্ট / রিল / ভিডিও)', 'type' => 'text', 'required' => true,
                                  'help' => 'ফেসবুকে পোস্টের ⋯ → "Copy link" দিয়ে লিংক কপি করে বসান। রিল/ভিডিও সবই চলবে। ভিজিটর কার্ডে ক্লিক করলে এই লিংকেই যাবে (মোবাইলে ফেসবুক অ্যাপে খুলবে)।'],
                'title'       => ['label' => 'শিরোনাম', 'type' => 'text', 'required' => true,
                                  'help' => 'কার্ডে বড় করে দেখানো হবে — পোস্টের মূল কথাটা লিখুন।'],
                'image'       => ['label' => 'ছবি (ঐচ্ছিক)', 'type' => 'image',
                                  'help' => 'পোস্টের ছবিটা এখানে আপলোড করুন — কার্ড অনেক আকর্ষণীয় দেখাবে। না দিলে রঙিন ব্যাকগ্রাউন্ড ও আইকন দেখাবে।'],
                'excerpt'     => ['label' => 'ছোট বর্ণনা (ঐচ্ছিক)', 'type' => 'textarea',
                                  'help' => 'এক-দুই লাইনে পোস্টের সারকথা। কার্ডে শিরোনামের নিচে দেখাবে।'],
                'is_featured' => ['label' => 'গুরুত্বপূর্ণ?', 'type' => 'checkbox', 'default' => 0,
                                  'toggle_label' => 'গুরুত্বপূর্ণ হিসেবে উপরে বড় করে দেখানো'],
                'sort_order'  => ['label' => 'ক্রম', 'type' => 'number', 'default' => 0, 'auto_next' => true],
                'is_active'   => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'পোস্টটি সাইটে দেখানো'],
            ],
            'list_columns' => ['image', 'title', 'url', 'is_featured', 'is_active'],
        ],

        'notices' => [
            'label' => 'নোটিশ',
            'label_plural' => 'নোটিশ বোর্ড',
            'table' => 'notices',
            'order_by' => 'notice_date DESC, id DESC',
            'upload_dir' => null,
            'fields' => [
                'title'       => ['label' => 'শিরোনাম', 'type' => 'text', 'required' => true],
                'content'     => ['label' => 'বিস্তারিত', 'type' => 'textarea'],
                'notice_date' => ['label' => 'তারিখ', 'type' => 'date', 'required' => true],
                'is_active'   => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'নোটিশটি সাইটে দেখানো'],
            ],
            'list_columns' => ['title', 'notice_date', 'is_active'],
        ],

        'gallery' => [
            'label' => 'গ্যালারি ছবি',
            'label_plural' => 'গ্যালারি',
            'table' => 'gallery',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => 'gallery',
            'fields' => [
                'image'      => ['label' => 'ছবি', 'type' => 'image', 'required' => true],
                'caption'    => ['label' => 'ক্যাপশন', 'type' => 'text'],
                'sort_order' => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['caption'],
            'list_display' => 'grid', // পুরোপুরি ছবি-নির্ভর কনটেন্ট, তুলনার মতো টেক্সট ডেটা নেই — তাই টেবিলের বদলে থাম্বনেইল গ্রিড
        ],

        'faqs' => [
            'label' => 'প্রশ্ন',
            'label_plural' => 'প্রায়শ জিজ্ঞাসিত প্রশ্ন',
            'table' => 'faqs',
            'order_by' => 'sort_order ASC, id DESC',
            'upload_dir' => null,
            'fields' => [
                'question'   => ['label' => 'প্রশ্ন', 'type' => 'text', 'required' => true],
                'answer'     => ['label' => 'উত্তর', 'type' => 'textarea'],
                'sort_order' => ['label' => 'ক্রম নম্বর (অটো বসে, চাইলে বদলানো যাবে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
                'is_active'  => ['label' => 'সাইটে দেখাবে?', 'type' => 'checkbox', 'default' => 1, 'warn_off' => true, 'toggle_label' => 'প্রশ্নটি সাইটে দেখানো'],
            ],
            'list_columns' => ['question', 'is_active'],
        ],
        // কুরিয়ার নোট-টাইপ — courier-prepare.php-এ per-registration রঙিন নোট হিসেবে ব্যবহার হয়
        'courier_note_types' => [
            'label' => 'কুরিয়ার নোট-টাইপ',
            'label_plural' => 'কুরিয়ার নোট-টাইপ',
            'table' => 'courier_note_types',
            'order_by' => 'sort_order ASC, id ASC',
            'upload_dir' => null,
            'fields' => [
                'label'      => ['label' => 'নোটের লেখা (যেমন: আগের বকেয়া ২০)', 'type' => 'text', 'required' => true],
                'color'      => ['label' => 'রঙ', 'type' => 'select', 'options' => [
                    'amber' => 'হলুদ/কমলা', 'accent' => 'নীল', 'success' => 'সবুজ', 'pro' => 'বেগুনি', 'danger' => 'লাল',
                ]],
                'sort_order' => ['label' => 'ক্রম নম্বর (অটো বসে)', 'type' => 'number', 'default' => 0, 'auto_next' => true],
            ],
            'list_columns' => ['label', 'color'],
        ],
    ];
}
