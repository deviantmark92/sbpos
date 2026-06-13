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

-- ---- Products (menu items + starting inventory) ----
INSERT INTO products (name, description, category, price, stock_quantity, low_stock_threshold) VALUES
('Broasted Chicken - 1pc',  'Crispy pressure-fried chicken, single piece.',         'Chicken',   75.00,  40, 10),
('Broasted Chicken - 2pc',  'Two pieces of crispy broasted chicken.',               'Chicken',  140.00,  30, 10),
('Broasted Chicken - Bucket (6pc)', 'Six-piece bucket, good for sharing.',           'Chicken',  399.00,  12,  5),
('Chicken Wings - 6pc',     'Crispy broasted chicken wings, six pieces.',            'Chicken',  120.00,  25,  8),
('Spaghetti',               'Sweet-style Filipino spaghetti.',                       'Sides',     55.00,  20,  6),
('French Fries',            'Golden crispy fries with seasoning.',                   'Sides',     45.00,  30, 10),
('Steamed Rice',            'Plain steamed rice, one cup.',                          'Sides',     20.00, 100, 20),
('Coleslaw',                'Fresh creamy coleslaw side.',                           'Sides',     35.00,  15,  5),
('Gravy (extra)',           'Extra cup of house gravy.',                             'Add-ons',   10.00,  50, 15),
('Softdrinks - Regular',    'Chilled regular soft drink in can.',                    'Beverages', 35.00,  48, 12),
('Bottled Water',           '500ml purified bottled water.',                         'Beverages', 20.00,  60, 15),
('Iced Tea - Glass',        'House-brewed sweet iced tea.',                          'Beverages', 30.00,   4,  8);  -- intentionally low to demo the low-stock alert

-- ---- A couple of sample transactions ----
-- Paid sale
INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status, paid_at)
VALUES ('Juan Dela Cruz', 2, 215.00, 'paid', CURRENT_TIMESTAMP);
INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, subtotal) VALUES
(1, 2, 'Broasted Chicken - 2pc', 1, 140.00, 140.00),
(1, 5, 'Spaghetti',              1,  55.00,  55.00),
(1, 7, 'Steamed Rice',           1,  20.00,  20.00);

-- Pending sale (to demo the "pending payments" alert)
INSERT INTO sales (customer_name, cashier_id, total_amount, payment_status)
VALUES ('Maria Santos', 2, 434.00, 'pending');
INSERT INTO sale_items (sale_id, product_id, product_name, quantity, unit_price, subtotal) VALUES
(2, 3, 'Broasted Chicken - Bucket (6pc)', 1, 399.00, 399.00),
(2, 7, 'Steamed Rice',                    1,  20.00,  20.00),
(2, 9, 'Gravy (extra)',                   1,  10.00,  10.00),
(2, 11,'Bottled Water',                   1,  20.00,  20.00);

