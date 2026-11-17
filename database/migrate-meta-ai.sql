-- 🤖 ফেসবুক ইনবক্সে AI উত্তর (ধাপ ৩, 2026-11-17)
-- phpMyAdmin → SQL ট্যাবে পুরোটা চালান। বারবার চালালেও ক্ষতি নেই (IF NOT EXISTS)।
-- আগে migrate-meta-inbox.sql ও migrate-meta-knowledge.sql চালানো থাকতে হবে।
-- 🔴 টাকা/খাতা/আয় কিছুই ছোঁয় না — সম্পূর্ণ আলাদা দুটো টেবিল।

-- প্রতিটা AI ডাকের হিসাব — খরচ (ডলার), টোকেন, ফল। outcome: ok / human (AI জানে না) / refusal / error
CREATE TABLE IF NOT EXISTS meta_ai_log (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    channel    VARCHAR(8)    NOT NULL,
    target     VARCHAR(190)  NOT NULL,
    ref        VARCHAR(190)  NULL,
    kind       VARCHAR(8)    NOT NULL,
    model      VARCHAR(60)   NULL,
    in_tok     INT           NOT NULL DEFAULT 0,
    out_tok    INT           NOT NULL DEFAULT 0,
    cache_w    INT           NOT NULL DEFAULT 0,
    cache_r    INT           NOT NULL DEFAULT 0,
    cost_usd   DECIMAL(10,6) NOT NULL DEFAULT 0,
    outcome    VARCHAR(10)   NOT NULL,
    error      VARCHAR(255)  NULL,
    created_at DATETIME      NOT NULL,
    KEY idx_mai_time (created_at),
    KEY idx_mai_ref (channel, ref),
    KEY idx_mai_target (channel, target, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- AI-র লেখা খসড়া — অ্যাডমিন দেখে পাঠান। status: new / used / dismissed / old
CREATE TABLE IF NOT EXISTS meta_ai_drafts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    channel     VARCHAR(8)   NOT NULL,
    target      VARCHAR(190) NOT NULL,
    ref         VARCHAR(190) NULL,
    question    TEXT         NULL,
    draft       TEXT         NULL,
    needs_human TINYINT      NOT NULL DEFAULT 0,
    note        VARCHAR(255) NULL,
    status      VARCHAR(10)  NOT NULL DEFAULT 'new',
    created_at  DATETIME     NOT NULL,
    KEY idx_maid (channel, target, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
