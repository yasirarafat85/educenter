-- ============================================================
-- মাইগ্রেশন: কোর্স ট্র্যাকিং (গ্রুপ-যোগ + মাসিক পার্সেল ট্র্যাকিং)
-- GROUP-PARCEL-TRACKING-PLAN.md দ্রষ্টব্য। non-destructive, idempotent (MariaDB)।
-- ============================================================

-- অংশ ক: রেজিস্ট্রেশন গ্রুপে যোগ হয়েছে কিনা
ALTER TABLE registrations
    ADD COLUMN IF NOT EXISTS fb_group_added        TINYINT(1) NOT NULL DEFAULT 0 AFTER courier_active,
    ADD COLUMN IF NOT EXISTS messenger_group_added TINYINT(1) NOT NULL DEFAULT 0 AFTER fb_group_added;

-- অংশ খ: কোর্স-ব্যাচে মোট কয়বার পার্সেল যাবে (স্লট-সংখ্যা)
ALTER TABLE course_batches
    ADD COLUMN IF NOT EXISTS total_parcels INT NOT NULL DEFAULT 0 AFTER messenger_group_url;

-- অংশ খ: পার্সেল স্লট created_at-ক্রমে ম্যাপ হয় (আলাদা parcel_no লাগে না)।
-- send_status-এ নতুন মান 'declined' ("এই দফা না") — VARCHAR বলে স্কিমা বদল লাগে না, শুধু কোডে ব্যবহার হয়।
