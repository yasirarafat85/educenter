-- 🛒 কার্ট / মাল্টি-আইটেম অর্ডার (২০২৬-১১-০৭)
-- Products ও Worksheet এখন কার্ট — একজন একসাথে একাধিক আইটেম এক চেকআউটে অর্ডার করেন।
-- প্রতিটা আইটেম একটা করে registrations সারি হয়, সব একই order_group টোকেনে বাঁধা।
-- খালি (NULL) = একক অর্ডার = আগের আচরণ হুবহু (পুরনো সব অর্ডার অপরিবর্তিত)।
--
-- 🔴 না চালালে: চেকআউট আগের মতোই চলে (db_has_column গার্ড), কিন্তু একই চেকআউটের
--    আইটেমগুলো অ্যাডমিন তালিকায় "🛒 একই অর্ডার" ব্যাজে জোড়া লাগবে না।

ALTER TABLE registrations
    ADD COLUMN order_group VARCHAR(32) NULL DEFAULT NULL AFTER type;

ALTER TABLE registrations
    ADD INDEX idx_reg_order_group (order_group);
