-- Migration 002: Schema updates
-- - Add new order fields
-- - Change primary keys to use mintsoft_id
-- - Add unique constraint on serials
--
-- IMPORTANT: Back up your database before running this migration!
-- Run with: mysql -u user -p database < migrations/002_schema_updates.sql
--
-- Table prefix: ms_ (configured via DB_TABLE_PREFIX in .env)

-- ============================================
-- PART 1: Add new columns to orders table
-- ============================================

ALTER TABLE ms_orders
    ADD COLUMN external_order_reference VARCHAR(255) NULL AFTER order_number,
    ADD COLUMN tracking_number VARCHAR(255) NULL AFTER shipping_method,
    ADD COLUMN tracking_url VARCHAR(500) NULL AFTER tracking_number,
    ADD COLUMN courier_service_name VARCHAR(100) NULL AFTER tracking_url,
    ADD COLUMN total_items INT NULL AFTER courier_service_name,
    ADD COLUMN total_weight DECIMAL(12, 4) NULL AFTER total_items,
    ADD COLUMN order_value DECIMAL(12, 2) NULL AFTER total_amount,
    ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER order_value,
    ADD COLUMN comments TEXT NULL AFTER shipping_country,
    ADD COLUMN gift_messages TEXT NULL AFTER comments,
    ADD COLUMN tags VARCHAR(500) NULL AFTER gift_messages;

-- Add indexes for new searchable columns
ALTER TABLE ms_orders
    ADD INDEX idx_external_order_reference (external_order_reference),
    ADD INDEX idx_tracking_number (tracking_number),
    ADD INDEX idx_warehouse_id (warehouse_id);

-- ============================================
-- PART 2: Change primary keys to mintsoft_id
-- ============================================
-- This requires dropping and recreating foreign key constraints

-- Step 2a: Drop foreign key constraints
ALTER TABLE ms_serials DROP FOREIGN KEY fk_serials_order;
ALTER TABLE ms_serials DROP FOREIGN KEY fk_serials_order_line;
ALTER TABLE ms_order_lines DROP FOREIGN KEY fk_order_lines_order;

-- Step 2b: Update order_lines to reference mintsoft_id instead of id
-- First add the new column
ALTER TABLE ms_order_lines ADD COLUMN order_mintsoft_id INT UNSIGNED NOT NULL AFTER order_id;

-- Populate it from the existing relationship
UPDATE ms_order_lines ol
INNER JOIN ms_orders o ON ol.order_id = o.id
SET ol.order_mintsoft_id = o.mintsoft_id;

-- Step 2c: Update serials to reference mintsoft_ids
ALTER TABLE ms_serials
    ADD COLUMN order_mintsoft_id INT UNSIGNED NOT NULL AFTER order_id,
    ADD COLUMN order_line_mintsoft_id INT UNSIGNED NULL AFTER order_line_id;

-- Populate from existing relationships
UPDATE ms_serials s
INNER JOIN ms_orders o ON s.order_id = o.id
SET s.order_mintsoft_id = o.mintsoft_id;

UPDATE ms_serials s
INNER JOIN ms_order_lines ol ON s.order_line_id = ol.id
SET s.order_line_mintsoft_id = ol.mintsoft_line_id
WHERE s.order_line_id IS NOT NULL;

-- Step 2d: Drop old id columns and rename new ones
-- Orders table: change primary key
ALTER TABLE ms_orders
    DROP PRIMARY KEY,
    DROP COLUMN id,
    ADD PRIMARY KEY (mintsoft_id);

-- Order lines table: change primary key and foreign key column
ALTER TABLE ms_order_lines
    DROP PRIMARY KEY,
    DROP COLUMN id,
    DROP COLUMN order_id,
    ADD PRIMARY KEY (mintsoft_line_id),
    CHANGE COLUMN order_mintsoft_id order_id INT UNSIGNED NOT NULL;

-- Serials table: update foreign key columns
ALTER TABLE ms_serials
    DROP COLUMN order_id,
    DROP COLUMN order_line_id,
    CHANGE COLUMN order_mintsoft_id order_id INT UNSIGNED NOT NULL,
    CHANGE COLUMN order_line_mintsoft_id order_line_id INT UNSIGNED NULL;

-- Step 2e: Recreate foreign key constraints using mintsoft_ids
ALTER TABLE ms_order_lines
    ADD CONSTRAINT fk_order_lines_order
    FOREIGN KEY (order_id) REFERENCES ms_orders(mintsoft_id) ON DELETE CASCADE;

ALTER TABLE ms_serials
    ADD CONSTRAINT fk_serials_order
    FOREIGN KEY (order_id) REFERENCES ms_orders(mintsoft_id) ON DELETE CASCADE,
    ADD CONSTRAINT fk_serials_order_line
    FOREIGN KEY (order_line_id) REFERENCES ms_order_lines(mintsoft_line_id) ON DELETE SET NULL;

-- ============================================
-- PART 3: Add unique constraint on serials
-- ============================================
-- Using composite unique on order_id + serial_number to allow same serial across different orders
-- (e.g., if an order is cancelled and re-created)

ALTER TABLE ms_serials
    ADD UNIQUE KEY uk_order_serial (order_id, serial_number);

-- ============================================
-- PART 4: Update indexes
-- ============================================

-- Drop old unique key on mintsoft_id (now primary key)
ALTER TABLE ms_orders DROP INDEX uk_mintsoft_id;
ALTER TABLE ms_order_lines DROP INDEX uk_mintsoft_line_id;
