-- migrate-login-attempt-indexes.sql (2026-11-13)
-- Login rate-limit lookups by IP (parents) and by username (admins).
-- Non-destructive: only adds two indexes. Safe to run once in phpMyAdmin.
-- The site works without it (the checks just scan the table), so this is a speed-up, not a requirement.

ALTER TABLE user_login_attempts ADD INDEX idx_ula_ip_time (ip_address, attempted_at);
ALTER TABLE login_attempts ADD INDEX idx_la_user_time (username, attempted_at);
