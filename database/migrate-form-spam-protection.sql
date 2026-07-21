-- পাবলিক ফর্ম স্প্যাম-প্রোটেকশন — IP রেট-লিমিটের জন্য form_submit_attempts টেবিল।
-- non-destructive: শুধু CREATE TABLE, লাইভ DB তে নিরাপদে চালানো যায়।

CREATE TABLE IF NOT EXISTS form_submit_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_time (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
