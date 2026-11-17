-- তথ্য মুছে ফেলার অনুরোধ (২০২৬-১১-১৭) — গোপনীয়তা নীতির প্রতিশ্রুতি পূরণ ও প্রমাণ রাখা
-- non-destructive: শুধু নতুন টেবিল। পাতা: অ্যাডমিন → সেটিংস → "তথ্য মুছে ফেলার অনুরোধ"
CREATE TABLE IF NOT EXISTS data_deletion_requests (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    requester_name VARCHAR(150) NOT NULL DEFAULT '',
    phone          VARCHAR(20)  NOT NULL DEFAULT '',
    phone_hash     CHAR(64)     NOT NULL DEFAULT '',
    channel        VARCHAR(30)  NOT NULL DEFAULT '',
    request_note   TEXT         NULL,
    received_on    DATE         NOT NULL,
    status         VARCHAR(20)  NOT NULL DEFAULT 'new',
    identity_note  VARCHAR(255) NOT NULL DEFAULT '',
    result_json    TEXT         NULL,
    created_by     VARCHAR(50)  NOT NULL DEFAULT '',
    created_at     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    done_by        VARCHAR(50)  NOT NULL DEFAULT '',
    done_at        DATETIME     NULL,
    INDEX idx_ddr_status (status, received_on),
    INDEX idx_ddr_hash (phone_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
