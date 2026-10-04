-- ============================================================
-- আয় ও খরচকে কোর্স-ব্যাচের সাথে জোড়া (২০২৬-১০-০৪)
--
-- WHY: income rows that came from a registration could already be
-- traced back to a course batch (through `registration_id`), but
-- expenses had no such link at all — so "how much did THIS batch
-- cost us?" could not be answered. Typing the course name into the
-- category or the description does not aggregate reliably.
--
-- Now both tables can point at one course batch. The columns are
-- OPTIONAL: rent, salary, electricity and other general costs keep
-- them empty, exactly as before.
--
-- 🔴 Rows that already carry `registration_id` do NOT use these
--    columns — their course/batch is read live from `registrations`,
--    so moving a registration to another course (move-course) keeps
--    the books correct by itself. These columns are only filled in
--    for entries typed by hand.
--
-- 🔴 No FOREIGN KEY on item_id, on purpose — same rule as
--    `registrations.item_id`: if a batch is deleted later, the money
--    row must stay in the books, and the snapshot columns below keep
--    its name readable.
--
-- Run this once in phpMyAdmin (LIVE and LOCAL).
-- Non-destructive: only adds columns, no data is touched, and every
-- existing amount stays exactly as it is.
-- ============================================================

ALTER TABLE income
    ADD COLUMN item_type VARCHAR(20) NULL AFTER registration_id,
    ADD COLUMN item_id INT UNSIGNED NULL AFTER item_type,
    ADD COLUMN item_title VARCHAR(255) NULL AFTER item_id,
    ADD COLUMN batch VARCHAR(100) NULL AFTER item_title,
    ADD KEY idx_income_item (item_type, item_id);

ALTER TABLE expenses
    ADD COLUMN item_type VARCHAR(20) NULL AFTER source,
    ADD COLUMN item_id INT UNSIGNED NULL AFTER item_type,
    ADD COLUMN item_title VARCHAR(255) NULL AFTER item_id,
    ADD COLUMN batch VARCHAR(100) NULL AFTER item_title,
    ADD KEY idx_expenses_item (item_type, item_id);
