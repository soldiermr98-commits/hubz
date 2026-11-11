-- Online payment routes remain disabled; provider sandbox and host validation are still required.
-- Additive finance tables for an explicit, administrator-controlled update.
-- Does not alter publication, advertisements, provider defaults, or old data.
-- No gateway credentials or payment attempts are created by this migration.
CREATE TABLE IF NOT EXISTS advertising_contract_requests (
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (request_key),
    KEY idx_contract_request_contract (contract_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A receipt reference is claimed permanently across manual and live online records,
-- even if voided. Sandbox references occupy a separate namespace.
CREATE TABLE IF NOT EXISTS advertising_receipt_claims (
    reference_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_kind ENUM('manual','online') NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (reference_hash), KEY idx_receipt_claim_payment (payment_kind,payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS advertising_contract_status_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    old_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_contract_status_log (contract_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modes are explicitly configured by an administrator; no row means disabled.
-- The secret is AES-GCM encrypted; the master key is outside the web root.
CREATE TABLE IF NOT EXISTS advertising_gateway_settings (
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode ENUM('disabled','sandbox','live') NOT NULL DEFAULT 'disabled',
    secret_sealed TEXT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 0,
    updated_by INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuration audit contains no credential material.
CREATE TABLE IF NOT EXISTS advertising_gateway_settings_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    old_mode VARCHAR(16) NOT NULL,
    new_mode VARCHAR(16) NOT NULL,
    key_action ENUM('unchanged','replaced','removed') NOT NULL,
    version INT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_gateway_log_provider (provider,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A single unresolved attempt reserves its contract amount. Tokens are stored
-- only as hashes; credential and amount snapshots are immutable per attempt.
CREATE TABLE IF NOT EXISTS advertising_online_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    contract_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    mode ENUM('sandbox','live') NOT NULL,
    state ENUM('link_ready','creating','pending','verifying','verified','sandbox_verified','uncertain','reconciled','sandbox_reconciled','cancelled') NOT NULL DEFAULT 'link_ready',
    amount_toman BIGINT UNSIGNED NOT NULL,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    link_hours TINYINT UNSIGNED NOT NULL,
    link_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    order_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    settings_version INT UNSIGNED NOT NULL,
    secret_sealed TEXT NOT NULL,
    provider_id VARCHAR(120) CHARACTER SET ascii COLLATE ascii_bin NULL,
    checkout_url VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NULL,
    verified_reference VARCHAR(120) NULL,
    last_error_code VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_online_link (link_hash),
    UNIQUE KEY uq_online_request (request_key),
    UNIQUE KEY uq_online_order (order_id),
    UNIQUE KEY uq_online_provider_id (provider,mode,provider_id),
    UNIQUE KEY uq_online_provider_reference (provider,mode,verified_reference),
    KEY idx_online_contract_state (contract_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Human reconciliation is explicitly distinguishable from gateway verification.
CREATE TABLE IF NOT EXISTS advertising_online_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id BIGINT UNSIGNED NOT NULL,
    old_state VARCHAR(24) NOT NULL,
    new_state VARCHAR(24) NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    reference VARCHAR(120) NULL,
    changed_by INT UNSIGNED NULL, -- NULL denotes a system transition, not an administrator assertion.
    changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY idx_online_audit_payment (payment_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
