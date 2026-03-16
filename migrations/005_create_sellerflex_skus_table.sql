-- Migration: Create SellerFlex SKUs tracking table
-- Purpose: Track SellerFlex products and their FBA stock sync status
-- Date: 2026-03-09

-- Create table for tracking SellerFlex products
CREATE TABLE IF NOT EXISTS ms_sellerflex_skus (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sku VARCHAR(255) NOT NULL COMMENT 'Product SKU',
    mintsoft_product_id INT UNSIGNED NULL COMMENT 'Mintsoft product ID',
    product_name VARCHAR(500) NULL COMMENT 'Product name from Mintsoft',
    last_fba_quantity INT NULL COMMENT 'Last known FBA fulfillable quantity',
    last_mintsoft_quantity INT NULL COMMENT 'Last known Mintsoft on-hand quantity',
    last_synced_at DATETIME NULL COMMENT 'When stock was last successfully synced to Mintsoft',
    last_sync_error TEXT NULL COMMENT 'Error message if last sync failed',
    last_sync_error_at DATETIME NULL COMMENT 'When sync error occurred',
    is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Whether this SKU is still active for syncing',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    UNIQUE KEY idx_sku (sku),
    KEY idx_mintsoft_product_id (mintsoft_product_id),
    KEY idx_last_synced_at (last_synced_at),
    KEY idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Tracks SellerFlex products for FBA stock sync';

-- Create table for sync run history
CREATE TABLE IF NOT EXISTS ms_sellerflex_sync_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    skus_found INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of SellerFlex SKUs found in Mintsoft',
    skus_with_fba_data INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of SKUs with FBA quantity data',
    skus_updated INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of SKUs updated in Mintsoft',
    skus_unchanged INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of SKUs with no change needed',
    skus_failed INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Number of SKUs that failed to update',
    error_message TEXT NULL COMMENT 'Overall error if sync run failed',

    KEY idx_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='History of SellerFlex stock sync runs';
