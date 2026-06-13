-- ============================================================
-- Broasted Chicken POS System - MySQL Schema
-- ============================================================
-- Run with:  mysql -u root sbpos < sql/schema.sql
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Clean slate (safe to re-run during development)
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS users;

SET FOREIGN_KEY_CHECKS = 1;

-- ------------------------------------------------------------
-- USERS  (Owner / Cashier accounts)
-- ------------------------------------------------------------
CREATE TABLE users (
    id            INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    role          VARCHAR(20)  NOT NULL DEFAULT 'cashier'
                  CHECK (role IN ('owner', 'cashier')),
    is_active     BOOLEAN      NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- PRODUCTS  (Menu items + inventory)
-- ------------------------------------------------------------
CREATE TABLE products (
    id                  INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120)   NOT NULL,
    description         TEXT,
    category            VARCHAR(60)    NOT NULL DEFAULT 'General',
    price               DECIMAL(10,2)  NOT NULL DEFAULT 0 CHECK (price >= 0),
    photo_path          VARCHAR(255),
    stock_quantity      INTEGER        NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
    low_stock_threshold INTEGER        NOT NULL DEFAULT 5  CHECK (low_stock_threshold >= 0),
    is_active           BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ------------------------------------------------------------
-- SALES  (Transaction header)
-- ------------------------------------------------------------
CREATE TABLE sales (
    id             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_name  VARCHAR(120)  NOT NULL DEFAULT 'Walk-in',
    cashier_id     INTEGER       REFERENCES users(id) ON DELETE SET NULL,
    total_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status VARCHAR(20)   NOT NULL DEFAULT 'pending'
                   CHECK (payment_status IN ('paid', 'pending')),
    note           TEXT,
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at        TIMESTAMP     NULL DEFAULT NULL
);

-- ------------------------------------------------------------
-- SALE_ITEMS  (Transaction line items)
-- ------------------------------------------------------------
CREATE TABLE sale_items (
    id           INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id      INTEGER       NOT NULL REFERENCES sales(id) ON DELETE CASCADE,
    product_id   INTEGER       REFERENCES products(id) ON DELETE SET NULL,
    product_name VARCHAR(120)  NOT NULL,
    quantity     INTEGER       NOT NULL CHECK (quantity > 0),
    unit_price   DECIMAL(10,2) NOT NULL,
    subtotal     DECIMAL(10,2) NOT NULL
);

-- ------------------------------------------------------------
-- Helpful indexes
-- ------------------------------------------------------------
CREATE INDEX idx_sales_created_at     ON sales(created_at);
CREATE INDEX idx_sales_payment_status ON sales(payment_status);
CREATE INDEX idx_sale_items_sale_id   ON sale_items(sale_id);
CREATE INDEX idx_products_active       ON products(is_active);
