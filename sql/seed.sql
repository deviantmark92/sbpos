-- ============================================================
-- Broasted Chicken POS System - Sample / Seed Data
-- ============================================================
-- Run AFTER schema.sql:
--   mysql -u root sbpos < sql/seed.sql
-- ============================================================
-- Default logins (change these in production!):
--   Owner   ->  username: owner    password: owner123
--   Cashier ->  username: cashier  password: cashier123
-- ============================================================

-- ---- Users ----
INSERT INTO users (username, password_hash, full_name, role) VALUES
('owner',   '$2b$10$pe2LDDiEMn5QwNKvjb8BRODIFWirKG9GFbZHrNu3QBGtfJJ/RQ4Ma', 'Shop Owner',   'owner'),
('cashier', '$2b$10$41g3ukXDi0CbN.IZjQxqyeNp2c8GA1s00e7tfsgnWIZVmW7QXxtCm', 'Front Cashier','cashier');

-- ---- Inventory items (raw materials, define actual cost) ----
-- ids 1..11
INSERT INTO inventory_items (name, category, unit, unit_cost, stock_quantity, low_stock_threshold) VALUES
('Chicken Piece',       'Meat',       'pc',     30.00,  80, 20),  -- 1
('Chicken Wing Piece',  'Meat',       'pc',     12.00,  60, 15),  -- 2
('Spaghetti Serving',   'Prepared',   'serving',18.00,  20,  6),  -- 3
('Fries Portion',       'Prepared',   'portion',15.00,  30, 10),  -- 4
('Rice Cup',            'Prepared',   'cup',     7.00, 100, 20),  -- 5
('Coleslaw Cup',        'Prepared',   'cup',    12.00,  15,  5),  -- 6
('Gravy Cup',           'Condiment',  'cup',     4.00,  50, 15),  -- 7
('Softdrink Can',       'Beverage',   'can',    20.00,  48, 12),  -- 8
('Bottled Water',       'Beverage',   'bottle', 10.00,  60, 15),  -- 9
('Iced Tea Glass',      'Beverage',   'glass',   9.00,   4,  8),  -- 10 (low to demo the low-stock alert)
('Bucket Packaging',    'Packaging',  'pc',      8.00,  30, 10);  -- 11

-- ---- Menu items (sellable; price is authoritative, markup is for re-suggestion) ----
-- ids 1..12
INSERT INTO menu_items (name, description, category, pricing_mode, markup_value, price) VALUES
('Broasted Chicken - 1pc',          'Crispy pressure-fried chicken, single piece.', 'Chicken',   'percentage', 120.59, 75.00),   -- 1  cost 34
('Broasted Chicken - 2pc',          'Two pieces of crispy broasted chicken.',       'Chicken',   'percentage', 118.75, 140.00),  -- 2  cost 64
('Broasted Chicken - Bucket (6pc)', 'Six-piece bucket, good for sharing.',          'Chicken',   'addon',      207.00, 399.00),  -- 3  cost 192
('Chicken Wings - 6pc',             'Crispy broasted chicken wings, six pieces.',   'Chicken',   'percentage',  66.67, 120.00),  -- 4  cost 72
('Spaghetti',                       'Sweet-style Filipino spaghetti.',              'Sides',     'percentage', 205.56,  55.00),  -- 5  cost 18
('French Fries',                    'Golden crispy fries with seasoning.',          'Sides',     'percentage', 200.00,  45.00),  -- 6  cost 15
('Steamed Rice',                    'Plain steamed rice, one cup.',                 'Sides',     'percentage', 185.71,  20.00),  -- 7  cost 7
('Coleslaw',                        'Fresh creamy coleslaw side.',                  'Sides',     'percentage', 191.67,  35.00),  -- 8  cost 12
('Gravy (extra)',                   'Extra cup of house gravy.',                    'Add-ons',   'manual',       0.00,  10.00),  -- 9  cost 4
('Softdrinks - Regular',            'Chilled regular soft drink in can.',           'Beverages', 'addon',       15.00,  35.00),  -- 10 cost 20
('Bottled Water',                   '500ml purified bottled water.',                'Beverages', 'percentage', 100.00,  20.00),  -- 11 cost 10
('Iced Tea - Glass',                'House-brewed sweet iced tea.',                 'Beverages', 'percentage', 233.33,  30.00);  -- 12 cost 9

-- ---- Recipes (menu_item_ingredients) ----
INSERT INTO menu_item_ingredients (menu_item_id, inventory_item_id, quantity) VALUES
(1, 1, 1), (1, 7, 1),            -- 1pc      = 1 chicken + 1 gravy           (cost 34)
(2, 1, 2), (2, 7, 1),            -- 2pc      = 2 chicken + 1 gravy           (cost 64)
(3, 1, 6), (3, 11, 1), (3, 7, 1),-- Bucket   = 6 chicken + 1 pkg + 1 gravy   (cost 192)
(4, 2, 6),                       -- Wings    = 6 wing pieces                 (cost 72)
(5, 3, 1),                       -- Spaghetti= 1 serving                     (cost 18)
(6, 4, 1),                       -- Fries    = 1 portion                     (cost 15)
(7, 5, 1),                       -- Rice     = 1 cup                         (cost 7)
(8, 6, 1),                       -- Coleslaw = 1 cup                         (cost 12)
(9, 7, 1),                       -- Gravy    = 1 cup                         (cost 4)
(10, 8, 1),                      -- Softdrink= 1 can                         (cost 20)
(11, 9, 1),                      -- Water    = 1 bottle                      (cost 10)
(12, 10, 1);                     -- Iced tea = 1 glass                       (cost 9)

-- ---- A couple of sample transactions ----
-- Paid sale (unit_cost = recipe cost snapshot)
INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status, paid_at)
VALUES ('Juan Dela Cruz', 2, 215.00, 'paid', CURRENT_TIMESTAMP);
INSERT INTO sale_items (sale_id, menu_item_id, product_name, quantity, unit_price, unit_cost, subtotal) VALUES
(1, 2, 'Broasted Chicken - 2pc', 1, 140.00, 64.00, 140.00),
(1, 5, 'Spaghetti',              1,  55.00, 18.00,  55.00),
(1, 7, 'Steamed Rice',           1,  20.00,  7.00,  20.00);

-- Pending sale (to demo the "pending payments" alert)
INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status)
VALUES ('Maria Santos', 2, 449.00, 'pending');
INSERT INTO sale_items (sale_id, menu_item_id, product_name, quantity, unit_price, unit_cost, subtotal) VALUES
(2, 3, 'Broasted Chicken - Bucket (6pc)', 1, 399.00, 192.00, 399.00),
(2, 7, 'Steamed Rice',                    1,  20.00,   7.00,  20.00),
(2, 9, 'Gravy (extra)',                   1,  10.00,   4.00,  10.00),
(2, 11,'Bottled Water',                   1,  20.00,  10.00,  20.00);
