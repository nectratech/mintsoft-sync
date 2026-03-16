-- Migration: Add Ikonic sync tracking columns to ms_serials
-- Purpose: Track which serials have been synced to Ikonic 3PL API
-- Date: 2026-03-03

-- Add columns for tracking Ikonic sync status
ALTER TABLE ms_serials
ADD COLUMN synced_to_ikonic_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when serial was successfully synced to Ikonic',
ADD COLUMN ikonic_sync_error TEXT NULL DEFAULT NULL COMMENT 'Error message if Ikonic sync failed',
ADD COLUMN ikonic_sync_error_at DATETIME NULL DEFAULT NULL COMMENT 'Timestamp when sync error occurred (for retry logic)';

-- Add index for efficient querying of unsynced serials
CREATE INDEX idx_serials_ikonic_sync ON ms_serials (synced_to_ikonic_at);

-- Add index for retry logic (errors older than 1 hour)
CREATE INDEX idx_serials_ikonic_error_at ON ms_serials (ikonic_sync_error_at);

-- Composite index for the common query pattern: unsynced serials with order data
CREATE INDEX idx_serials_order_ikonic ON ms_serials (order_id, synced_to_ikonic_at);
