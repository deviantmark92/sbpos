# small business POS System (sbPOS)

A simple, mobile-friendly **web-based Point of Sale** created for home-based food business. Tracks daily sales, manages a photo menu, monitors raw-material inventory,
flags low stock and pending payments, and produces daily / monthly / yearly reports
including profit derived from ingredient costs.

- **Backend:** PHP (plain PHP + PDO, no framework)
- **Database:** MySQL
- **Frontend:** [Material Web](https://github.com/material-components/material-web) (Google's Material Design 3 web components), loaded via ESM + a Material Design 3 stylesheet
- **Currency:** Philippine Peso (₱)

---

## Features

| Module | What it does |
|--------|--------------|
| **Dashboard** | Today's paid revenue, transaction count, pending-payment total, low-stock raw-material alerts |
| **Sales (POS)** | Tap-to-add menu, live cart & total, "can make" counter per item, customer name, paid/pending status, optional note — records the sale and decrements ingredient stock |
| **Menu / Products** | Add, edit, delete, search menu items; upload a photo; build a recipe from inventory items; set price via % markup, cost add-on, or manual entry *(Owner)* |
| **Inventory** | Manage raw materials (ingredients): unit cost, stock, reorder threshold; quick ±1/+10 adjustments or set exact values; items used in recipes cannot be deleted *(Owner)* |
| **Reports** | Daily / monthly / yearly revenue, profit (revenue − ingredient cost), pending totals, top sellers, stock value, sales list *(Owner)* |
| **Users** | Create & manage Owner / Cashier accounts, enable/disable, reset passwords *(Owner)* |

**Roles:** *Owner* has full access; *Cashier* can only process sales.

---

## Product model

The system uses a **two-level model** that separates raw materials from sellable items:

```
inventory_items  ──(recipe)──►  menu_items
 (raw materials)                (what is sold)
  unit cost ✓                    selling price ✓
  stock tracked ✓                availability derived ✓
```

- **`inventory_items`** are the physical ingredients (chicken pieces, sauce cans, oil, etc.).
  Each carries a `unit_cost` and a `stock_quantity`.
- **`menu_items`** are what appears on the POS. Each has a recipe made up of one or more
  inventory items and a quantity per portion.
- **Availability** shown on the POS ("N can make") is `MIN(FLOOR(stock / qty_needed))`
  across all ingredients in the recipe — the scarcest ingredient is the bottleneck.
- **Selling a menu item decrements ingredient stock**, not a menu-item stock counter.
- A menu item with no recipe cannot be sold.

### Pricing modes

When creating or editing a menu item the Owner chooses one of three pricing modes:

| Mode | How price is derived |
|------|----------------------|
| **% markup** | `recipe_cost × (1 + markup / 100)` |
| **Cost add-on** | `recipe_cost + flat peso amount` |
| **Manual** | Owner enters the selling price directly |

The price preview updates live in the browser as ingredients or the markup value change.
The authoritative price is always resolved server-side on save.

---

## Requirements

- PHP 8.0+ with the `pdo_mysql` extension enabled
- MySQL 8.0+
- Any web server (Apache, Nginx, or PHP's built-in server)

---

## Setup

### 1. Create the database and load the schema

```bash
mysql -u root -e "CREATE DATABASE broasted_pos;"
mysql -u root broasted_pos < sql/schema.sql
mysql -u root broasted_pos < sql/seed.sql      # optional sample menu + accounts
```

### 2. Configure the connection

Edit `config/config.php`, **or** set environment variables (recommended):

```bash
export DB_HOST=127.0.0.1 DB_PORT=3306 DB_NAME=broasted_pos \
       DB_USER=root       DB_PASSWORD=yourpassword
```

### 3. Run it

Quick start with PHP's built-in server (point it at the `public/` folder):

```bash
php -S localhost:8000 -t public
```

Then open <http://localhost:8000>.

For Apache/Nginx, set the **document root to the `public/` folder**. An `.htaccess`
is included for Apache.

### 4. Log in

| Role | Username | Password |
|------|----------|----------|
| Owner | `owner` | `owner123` |
| Cashier | `cashier` | `cashier123` |

> **Change these immediately** in the Users screen for any real use.

---

## Transaction flow

1. Cashier opens **New Sale**, enters the customer name, taps menu items (only those with
   a recipe and sufficient ingredient stock are tappable), and sets quantities.
2. The cart shows each line with the running total. A "can make" count on each tile
   prevents over-ordering.
3. Cashier selects **Paid** or **Pending** payment status and optionally adds a note.
4. On submit the system:
   - Validates that every ingredient has enough stock (row-locked in a DB transaction).
   - Inserts the sale header and line items (with a cost snapshot for profit reporting).
   - Decrements each ingredient's `stock_quantity` by `recipe_qty × item_qty`.
   - Redirects to the **receipt page** (sale view).
5. From the receipt page:
   - A **Pending** sale can be marked **Paid**.
   - A **Paid** sale can be reverted back to **Pending** ("Revert to Pending").
6. The **Dashboard** surfaces all pending payments and raw-material low-stock alerts.

---

## Project structure

```
Small Business POS/
├── config/
│   └── config.php              # DB credentials + app settings (currency, uploads)
├── includes/
│   ├── db.php                  # PDO connection (MySQL)
│   ├── auth.php                # login, sessions, Owner/Cashier guards
│   ├── helpers.php             # money(), suggest_price(), CSRF, flash messages
│   ├── header.php              # layout + Material Web import + nav
│   └── footer.php
├── pages/                      # one file per screen (routed by the front controller)
│   ├── login.php  logout.php  dashboard.php
│   ├── sales.php              # POS screen — recipe-aware cart, ingredient deduction
│   ├── sale_view.php          # receipt + paid/pending toggle (bidirectional)
│   ├── products.php           # menu items with recipe builder and pricing modes
│   ├── inventory.php          # raw materials — cost, stock, thresholds
│   ├── reports.php            # revenue, profit, top sellers, sales list
│   ├── users.php
│   └── _forbidden.php
├── public/                     # web root
│   ├── index.php               # front controller  (index.php?page=...)
│   ├── .htaccess
│   ├── assets/app.css          # Material Design 3 styling
│   └── uploads/                # menu photos saved here
└── sql/
    ├── schema.sql              # tables: users, inventory_items, menu_items,
    │                           #         menu_item_ingredients, sales, sale_items
    └── seed.sql                # sample inventory, menu, accounts, demo transactions
```

---

## Database schema overview

| Table | Purpose |
|-------|---------|
| `users` | Owner / Cashier accounts (bcrypt passwords) |
| `inventory_items` | Raw materials — `unit_cost`, `stock_quantity`, `low_stock_threshold` |
| `menu_items` | Sellable items — `pricing_mode`, `markup_value`, authoritative `price` |
| `menu_item_ingredients` | Recipe rows linking a menu item to inventory items with a `quantity` |
| `sales` | Transaction header — customer, cashier, total, `payment_status`, `paid_at` |
| `sale_items` | Line items — name/price/cost snapshot at sale time for accurate profit queries |

---

## Security notes

- Passwords are stored as bcrypt hashes (`password_hash`).
- All forms use CSRF tokens; all queries use prepared statements (PDO).
- Ingredient stock is decremented inside a DB transaction with row locking (`FOR UPDATE`)
  to prevent overselling under concurrent requests.
- Inventory items referenced by a recipe cannot be deleted (FK `ON DELETE RESTRICT`);
  the UI enforces this with an explicit pre-check and a user-friendly error message.
- Set proper file permissions on `public/uploads/` and change the default logins before
  going live.
