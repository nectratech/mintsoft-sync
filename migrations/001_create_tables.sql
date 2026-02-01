-- Mintsoft Sync Database Schema
-- Run this to create the required tables

CREATE DATABASE IF NOT EXISTS mintsoft_sync CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE mintsoft_sync;

-- Orders table
CREATE TABLE IF NOT EXISTS orders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    mintsoft_id INT UNSIGNED NOT NULL,
    order_number VARCHAR(100) NULL,
    client_id INT UNSIGNED NULL,
    status VARCHAR(50) NULL,
    channel VARCHAR(100) NULL,
    currency VARCHAR(10) NULL,
    total_amount DECIMAL(12, 2) NULL,
    shipping_method VARCHAR(100) NULL,
    
    -- Customer details
    customer_name VARCHAR(255) NULL,
    customer_email VARCHAR(255) NULL,
    customer_phone VARCHAR(50) NULL,
    
    -- Shipping address
    shipping_address_1 VARCHAR(255) NULL,
    shipping_address_2 VARCHAR(255) NULL,
    shipping_city VARCHAR(100) NULL,
    shipping_county VARCHAR(100) NULL,
    shipping_postcode VARCHAR(20) NULL,
    shipping_country VARCHAR(100) NULL,
    
    -- Dates
    order_date DATETIME NULL,
    despatch_date DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    -- Indexes
    UNIQUE KEY uk_mintsoft_id (mintsoft_id),
    INDEX idx_order_number (order_number),
    INDEX idx_client_id (client_id),
    INDEX idx_status (status),
    INDEX idx_order_date (order_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order lines table
CREATE TABLE IF NOT EXISTS order_lines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    mintsoft_line_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NULL,
    sku VARCHAR(100) NULL,
    product_name VARCHAR(255) NULL,
    quantity INT NOT NULL DEFAULT 0,
    unit_price DECIMAL(12, 2) NULL,
    line_total DECIMAL(12, 2) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    
    -- Indexes
    UNIQUE KEY uk_mintsoft_line_id (mintsoft_line_id),
    INDEX idx_order_id (order_id),
    INDEX idx_sku (sku),
    INDEX idx_product_id (product_id),
    
    -- Foreign key
    CONSTRAINT fk_order_lines_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Serials table
CREATE TABLE IF NOT EXISTS serials (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    order_line_id INT UNSIGNED NULL,
    serial_number VARCHAR(255) NULL,
    barcode VARCHAR(255) NULL,
    product_id INT UNSIGNED NULL,
    sku VARCHAR(100) NULL,
    batch_no VARCHAR(255) NULL,
    expiry_date DATETIME NULL,
    box_number INT UNSIGNED NULL,
    sscc_number VARCHAR(255) NULL,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    
    -- Indexes
    INDEX idx_order_id (order_id),
    INDEX idx_order_line_id (order_line_id),
    INDEX idx_serial_number (serial_number),
    INDEX idx_barcode (barcode),
    INDEX idx_sku (sku),
    
    -- Foreign keys
    CONSTRAINT fk_serials_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_serials_order_line FOREIGN KEY (order_line_id) REFERENCES order_lines(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sync log table (tracks sync runs)
CREATE TABLE IF NOT EXISTS sync_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sync_type VARCHAR(50) NOT NULL,
    items_processed INT NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL,
    
    -- Indexes
    INDEX idx_sync_type (sync_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
