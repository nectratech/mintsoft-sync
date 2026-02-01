-- Add batch/expiry/box/sscc fields to serials.
-- If you use a table prefix, replace `serials` with `<prefix>serials` before running.
ALTER TABLE serials
    ADD COLUMN batch_no VARCHAR(255) NULL AFTER sku,
    ADD COLUMN expiry_date DATETIME NULL AFTER batch_no,
    ADD COLUMN box_number INT UNSIGNED NULL AFTER expiry_date,
    ADD COLUMN sscc_number VARCHAR(255) NULL AFTER box_number;
