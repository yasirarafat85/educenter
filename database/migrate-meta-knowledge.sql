-- 📚 ফেসবুক উত্তর-ভান্ডার — জ্ঞানভান্ডার + নির্দিষ্ট শব্দে উত্তর (ধাপ ২, 2026-11-17)
-- phpMyAdmin → SQL ট্যাবে পুরোটা চালান। বারবার চালালেও ক্ষতি নেই (IF NOT EXISTS)।
-- আগে migrate-meta-inbox.sql চালানো থাকতে হবে।
-- 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না — সম্পূর্ণ আলাদা তিনটা টেবিল।

-- জ্ঞানভান্ডার: প্রশ্নোত্তর (qa) ও ডকুমেন্ট (doc)। status: active (শেখা হয়েছে) / proposed (অনুমোদনের অপেক্ষায়) / rejected
CREATE TABLE IF NOT EXISTS meta_kb (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    kind         VARCHAR(8)   NOT NULL DEFAULT 'qa',
    status       VARCHAR(10)  NOT NULL DEFAULT 'active',
    title        VARCHAR(190) NULL,
    question     TEXT         NULL,
    answer       MEDIUMTEXT   NULL,
    source       VARCHAR(12)  NOT NULL DEFAULT 'admin',
    source_ref   VARCHAR(190) NULL,
    created_by   VARCHAR(100) NULL,
    approved_by  VARCHAR(100) NULL,
    approved_at  DATETIME     NULL,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NULL,
    UNIQUE KEY uq_meta_kb_ref (source_ref),
    KEY idx_meta_kb (kind, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- নির্দিষ্ট শব্দে ঠিক করা উত্তর। mode: suggest (শুধু প্রস্তাব) / auto (নিজে থেকে পাঠায়)। channel: both / message / comment
CREATE TABLE IF NOT EXISTS meta_rules (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    keywords     TEXT         NOT NULL,
    reply        TEXT         NOT NULL,
    channel      VARCHAR(8)   NOT NULL DEFAULT 'both',
    mode         VARCHAR(8)   NOT NULL DEFAULT 'suggest',
    is_active    TINYINT      NOT NULL DEFAULT 1,
    sort_order   INT          NOT NULL DEFAULT 0,
    hits         INT          NOT NULL DEFAULT 0,
    last_hit_at  DATETIME     NULL,
    created_at   DATETIME     DEFAULT CURRENT_TIMESTAMP,
    KEY idx_meta_rules (is_active, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- অটো-উত্তরের খাতা — কাকে কখন কোন নিয়মে পাঠানো হলো (একই মানুষকে বারবার না পাঠাতে)
CREATE TABLE IF NOT EXISTS meta_auto_log (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    rule_id   INT          NULL,
    channel   VARCHAR(8)   NOT NULL,
    target    VARCHAR(190) NOT NULL,
    ok        TINYINT      NOT NULL DEFAULT 0,
    error     VARCHAR(255) NULL,
    sent_at   DATETIME     NOT NULL,
    KEY idx_meta_auto (channel, target, sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
