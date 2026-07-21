-- কুরিয়ার কালেকশন রিডিজাইন — per-batch breakdown কলাম + কাস্টম নোট টেবিল + প্রিসেট সিড।
-- non-destructive (শুধু ADD COLUMN / CREATE TABLE / INSERT IGNORE)। COURIER-REDESIGN-PLAN.md ধাপ ১।
-- প্রয়োগ: phpMyAdmin এ DB সিলেক্ট করে Import, অথবা এই SQL চালান।

-- B. courier_batches-এ per-batch হিসাব-ভাঙা (amount_to_collect আগেই আছে — computed total)
ALTER TABLE courier_batches
  ADD COLUMN monthly_multiplier DECIMAL(3,1)  NOT NULL DEFAULT 1       AFTER amount_to_collect,
  ADD COLUMN delivery_zone      VARCHAR(20)             DEFAULT 'dhaka' AFTER monthly_multiplier,
  ADD COLUMN weight_extra       TINYINT(1)     NOT NULL DEFAULT 0       AFTER delivery_zone,
  ADD COLUMN adjustment         DECIMAL(10,2)  NOT NULL DEFAULT 0       AFTER weight_extra,
  ADD COLUMN adjustment_reason  VARCHAR(60)             DEFAULT NULL    AFTER adjustment;

-- C. কাস্টম নোট টাইপ (লেবেল + রঙ, অ্যাডমিন-ম্যানেজড)
CREATE TABLE IF NOT EXISTS courier_note_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  label      VARCHAR(80) NOT NULL,
  color      VARCHAR(20) NOT NULL DEFAULT 'amber',
  sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- per-registration নোট (preset type অথবা custom text+color)
CREATE TABLE IF NOT EXISTS registration_courier_notes (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  registration_id INT UNSIGNED NOT NULL,
  note_type_id    INT UNSIGNED NULL,
  custom_text     VARCHAR(120) DEFAULT NULL,
  color           VARCHAR(20)  DEFAULT NULL,
  FOREIGN KEY (registration_id) REFERENCES registrations(id) ON DELETE CASCADE,
  FOREIGN KEY (note_type_id)    REFERENCES courier_note_types(id) ON DELETE SET NULL,
  INDEX idx_reg (registration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A. প্রিসেট ডিফল্ট সিড (settings key-value; আগে থাকলে অপরিবর্তিত)
INSERT IGNORE INTO settings (setting_key, setting_value) VALUES
  ('courier_dc_dhaka',    '60'),
  ('courier_dc_near',     '80'),
  ('courier_dc_outside',  '120'),
  ('courier_weight_extra','20');
