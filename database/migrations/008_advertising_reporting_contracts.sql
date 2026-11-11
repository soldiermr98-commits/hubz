-- Online payment integrations remain disabled; host and accounting validation
-- are required before using the reporting and manual ledger in production.
-- Additive, repeatable and independent of old migrations; no existing content is changed.
CREATE TABLE IF NOT EXISTS advertising_banner_daily (
    banner_id INT UNSIGNED NOT NULL,
    day DATE NOT NULL,
    views BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (banner_id,day),
    KEY idx_ad_daily_day (day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Contract price and identifying details are immutable after creation. A payment
-- is an administrator assertion, not a bank verification or automatic refund.
CREATE TABLE IF NOT EXISTS advertising_contracts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(150) NOT NULL,
    client_name VARCHAR(150) NOT NULL,
    client_phone VARCHAR(32) NULL DEFAULT NULL,
    banner_id INT UNSIGNED NULL DEFAULT NULL,
    starts_at DATE NULL DEFAULT NULL,
    ends_at DATE NULL DEFAULT NULL,
    amount_toman BIGINT UNSIGNED NOT NULL,
    notes TEXT NULL,
    status ENUM('active','completed','cancelled') NOT NULL DEFAULT 'active',
    version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_ad_contract_status (status,created_at),
    KEY idx_ad_contract_banner (banner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertising_manual_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    amount_toman BIGINT UNSIGNED NOT NULL,
    method ENUM('bank_transfer','cash','other') NOT NULL,
    reference VARCHAR(120) NOT NULL,
    notes VARCHAR(1000) NULL,
    request_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status ENUM('recorded','voided') NOT NULL DEFAULT 'recorded',
    recorded_by INT UNSIGNED NOT NULL,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    voided_by INT UNSIGNED NULL DEFAULT NULL,
    voided_at DATETIME NULL DEFAULT NULL,
    void_reason VARCHAR(1000) NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ad_payment_request (request_key),
    KEY idx_ad_payment_contract (contract_id,status),
    KEY idx_ad_payment_reference (method,reference)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
