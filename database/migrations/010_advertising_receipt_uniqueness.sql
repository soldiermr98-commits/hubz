-- Collation-aware cross-ledger receipt claims. The 120-character utf8mb4 key
-- fits MySQL 5.7's 767-byte InnoDB index limit. Never remove ledger rows to
-- resolve accounting conflicts; the updater checks for existing active duplicates.
CREATE TABLE IF NOT EXISTS advertising_receipt_unique (
    namespace ENUM('live','sandbox') NOT NULL,
    reference VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    payment_kind ENUM('manual','online') NOT NULL,
    payment_id BIGINT UNSIGNED NOT NULL,
    contract_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (namespace,reference),
    KEY idx_receipt_unique_payment (payment_kind,payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A prior voided receipt remains unavailable. Equivalent historical references
-- collapse into one reservation, never a payment; the ledger remains unchanged.
-- INSERT IGNORE also makes an interrupted backfill safe to repeat.
INSERT IGNORE INTO advertising_receipt_unique (namespace,reference,payment_kind,payment_id,contract_id)
SELECT 'live',reference,'manual',id,contract_id FROM advertising_manual_payments
UNION ALL
SELECT IF(mode='live','live','sandbox'),verified_reference,'online',id,contract_id
FROM advertising_online_payments
WHERE verified_reference IS NOT NULL AND verified_reference <> ''
  AND state IN ('verified','reconciled','sandbox_verified','sandbox_reconciled');
