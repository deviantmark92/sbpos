-- ============================================================
-- Broasted Chicken POS System - MySQL Schema
-- ============================================================
-- Run with:  mysql -u root sbpos < sql/schema.sql
-- ============================================================
--
-- Two-level product model:
--   * inventory_items        = raw materials that carry the ACTUAL cost
--   * menu_items             = sellable products with a stored selling price
--   * menu_item_ingredients  = recipe / bill-of-materials linking the two
--
-- A menu item's cost = SUM(ingredient unit_cost * qty). Selling a menu item
-- deducts each ingredient's stock; availability is derived from the scarcest
-- ingredient.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- Clean slate (safe to re-run during development)
DROP TABLE IF EXISTS sale_items;
DROP TABLE IF EXISTS sales;
DROP TABLE IF EXISTS menu_item_ingredients;
DROP TABLE IF EXISTS menu_items;
DROP TABLE IF EXISTS inventory_items;
DROP TABLE IF EXISTS products;   -- legacy table from the old single-table model
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
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- INVENTORY_ITEMS  (Raw materials / ingredients — define actual cost)
-- ------------------------------------------------------------
CREATE TABLE inventory_items (
    id                  INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name                VARCHAR(120)   NOT NULL,
    category            VARCHAR(60)    NOT NULL DEFAULT 'General',
    unit                VARCHAR(20)    NOT NULL DEFAULT 'pc',   -- display label: pc, cup, can, bottle…
    unit_cost           DECIMAL(10,2)  NOT NULL DEFAULT 0 CHECK (unit_cost >= 0),  -- actual cost per unit
    stock_quantity      INT            NOT NULL DEFAULT 0 CHECK (stock_quantity >= 0),
    low_stock_threshold INT            NOT NULL DEFAULT 5 CHECK (low_stock_threshold >= 0),
    is_active           BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MENU_ITEMS  (Sellable products — stored selling price)
-- ------------------------------------------------------------
CREATE TABLE menu_items (
    id            INT            NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120)   NOT NULL,
    description   TEXT,
    category      VARCHAR(60)    NOT NULL DEFAULT 'General',
    photo_path    VARCHAR(255),
    pricing_mode  VARCHAR(20)    NOT NULL DEFAULT 'percentage'
                  CHECK (pricing_mode IN ('percentage', 'addon', 'manual')),
    markup_value  DECIMAL(10,2)  NOT NULL DEFAULT 0,  -- % when percentage, ₱ add-on when addon, ignored when manual
    price         DECIMAL(10,2)  NOT NULL DEFAULT 0 CHECK (price >= 0),  -- authoritative selling price
    is_active     BOOLEAN        NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- MENU_ITEM_INGREDIENTS  (Recipe / bill of materials)
-- ------------------------------------------------------------
CREATE TABLE menu_item_ingredients (
    id                INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    menu_item_id      INT NOT NULL,
    inventory_item_id INT NOT NULL,
    quantity          INT NOT NULL CHECK (quantity > 0),
    UNIQUE KEY uniq_menu_ingredient (menu_item_id, inventory_item_id),
    CONSTRAINT fk_mii_menu      FOREIGN KEY (menu_item_id)      REFERENCES menu_items(id)      ON DELETE CASCADE,
    CONSTRAINT fk_mii_inventory FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SALES  (Transaction header)
-- ------------------------------------------------------------
CREATE TABLE sales (
    id             INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_name  VARCHAR(120)  NOT NULL DEFAULT 'Walk-in',
    cashier_id     INT           NULL,
    total_amount   DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_status VARCHAR(20)   NOT NULL DEFAULT 'pending'
                   CHECK (payment_status IN ('paid', 'pending')),
    note           TEXT,
    prep_minutes   INT           NOT NULL DEFAULT 20 CHECK (prep_minutes > 0),  -- order prep/ready timer (minutes from created_at)
    created_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    paid_at        TIMESTAMP     NULL DEFAULT NULL,
    CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- SALE_ITEMS  (Transaction line items — snapshot name, price & cost)
-- ------------------------------------------------------------
CREATE TABLE sale_items (
    id           INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    sale_id      INT           NOT NULL,
    menu_item_id INT           NULL,
    product_name VARCHAR(120)  NOT NULL,                       -- snapshot label at sale time
    quantity     INT           NOT NULL CHECK (quantity > 0),
    unit_price   DECIMAL(10,2) NOT NULL,
    unit_cost    DECIMAL(10,2) NOT NULL DEFAULT 0,             -- recipe cost snapshot (for profit reporting)
    subtotal     DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_si_sale FOREIGN KEY (sale_id)      REFERENCES sales(id)      ON DELETE CASCADE,
    CONSTRAINT fk_si_menu FOREIGN KEY (menu_item_id) REFERENCES menu_items(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- Helpful indexes
-- ------------------------------------------------------------
CREATE INDEX idx_sales_created_at     ON sales(created_at);
CREATE INDEX idx_sales_payment_status ON sales(payment_status);
CREATE INDEX idx_sale_items_sale_id   ON sale_items(sale_id);
CREATE INDEX idx_inventory_active     ON inventory_items(is_active);
CREATE INDEX idx_menu_active          ON menu_items(is_active);
CREATE INDEX idx_mii_menu             ON menu_item_ingredients(menu_item_id);
CREATE INDEX idx_mii_inventory        ON menu_item_ingredients(inventory_item_id);
