-- migrate-user-verify-dob.sql (2026-11-13)
-- Parent signup: the child's date of birth the applicant typed, shown to the admin
-- next to the approve button as "matches / does not match the registration".
-- Non-destructive: adds one nullable column. Old accounts keep NULL.

ALTER TABLE users ADD COLUMN verify_dob DATE NULL DEFAULT NULL AFTER full_name;
