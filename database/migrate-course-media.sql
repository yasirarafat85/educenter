-- ─────────────────────────────────────────────────────────────
-- Course photos & videos (2026-09-25)
--
-- Photos are uploaded to this hosting (uploads/course-media/, auto 4:3 + WebP).
-- Videos are NEVER uploaded: only a YouTube or Google Drive link is stored.
--
-- Per BATCH (course_batches), like everything else visible on the site.
-- A new batch can copy another batch's rows from the admin page.
--
-- Non-destructive: only CREATE TABLE. Safe to run once on live and local.
-- To roll back: DROP TABLE course_media;
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS course_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_id   INT UNSIGNED NOT NULL,
    kind       VARCHAR(10)  NOT NULL DEFAULT 'photo',  -- photo / video
    file_path  VARCHAR(255) NOT NULL DEFAULT '',       -- photo only: uploads/course-media/xxx.webp
    video_url  VARCHAR(500) NOT NULL DEFAULT '',       -- video only: the original link the admin pasted
    provider   VARCHAR(20)  NOT NULL DEFAULT '',       -- youtube / drive
    video_id   VARCHAR(100) NOT NULL DEFAULT '',       -- extracted id, used to build the embed
    caption    VARCHAR(200) NOT NULL DEFAULT '',
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cm_batch (batch_id, sort_order),
    CONSTRAINT fk_cm_batch FOREIGN KEY (batch_id) REFERENCES course_batches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
