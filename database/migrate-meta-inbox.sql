-- 📨 ফেসবুক পেইজ ইনবক্স — মেসেজ ও কমেন্ট জমা রাখা (ধাপ ১, 2026-11-17)
-- phpMyAdmin → SQL ট্যাবে পুরোটা চালান। বারবার চালালেও ক্ষতি নেই (IF NOT EXISTS)।
-- 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না — সম্পূর্ণ আলাদা চারটা টেবিল।

CREATE TABLE IF NOT EXISTS meta_contacts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    psid        VARCHAR(64)  NOT NULL,
    name        VARCHAR(190) NULL,
    last_text   VARCHAR(255) NULL,
    last_at     DATETIME     NULL,
    last_in_at  DATETIME     NULL,
    unread      INT          NOT NULL DEFAULT 0,
    status      VARCHAR(10)  NOT NULL DEFAULT 'open',
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_psid (psid),
    KEY idx_meta_last (last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_messages (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    contact_id  INT          NOT NULL,
    mid         VARCHAR(190) NULL,
    direction   VARCHAR(4)   NOT NULL,
    source      VARCHAR(12)  NOT NULL DEFAULT 'webhook',
    text        TEXT         NULL,
    attach_json TEXT         NULL,
    sent_by     VARCHAR(100) NULL,
    sent_at     DATETIME     NOT NULL,
    created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_mid (mid),
    KEY idx_meta_contact (contact_id, sent_at),
    CONSTRAINT fk_meta_msg_contact FOREIGN KEY (contact_id) REFERENCES meta_contacts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_comments (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    comment_id    VARCHAR(100) NOT NULL,
    post_id       VARCHAR(100) NULL,
    parent_id     VARCHAR(100) NULL,
    from_id       VARCHAR(64)  NULL,
    from_name     VARCHAR(190) NULL,
    is_page       TINYINT      NOT NULL DEFAULT 0,
    message       TEXT         NULL,
    status        VARCHAR(10)  NOT NULL DEFAULT 'new',
    source        VARCHAR(12)  NOT NULL DEFAULT 'webhook',
    sent_by       VARCHAR(100) NULL,
    commented_at  DATETIME     NOT NULL,
    replied_at    DATETIME     NULL,
    created_at    DATETIME     DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_meta_comment (comment_id),
    KEY idx_meta_cstatus (status, commented_at),
    KEY idx_meta_cparent (parent_id),
    KEY idx_meta_cpost (post_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meta_posts (
    post_id     VARCHAR(100) NOT NULL PRIMARY KEY,
    message     TEXT         NULL,
    permalink   VARCHAR(255) NULL,
    posted_at   DATETIME     NULL,
    fetched_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
