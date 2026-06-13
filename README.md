# Broasted Chicken POS System

A simple, mobile-friendly **web-based Point of Sale** for a home-based fried chicken
business. Tracks daily sales, manages a photo menu, monitors inventory, flags low stock
and pending payments, and produces daily / monthly / yearly reports.

- **Backend:** PHP (plain PHP + PDO, no framework)
- **Database:** MySQL
- **Frontend:** [Material Web](https://github.com/material-components/material-web) (Google's Material Design 3 web components), loaded via ESM + a Material Design 3 stylesheet
- **Currency:** Philippine Peso (₱)

---

## Features

| Module | What it does |
|--------|--------------|
| **Dashboard** | Today's sales, transaction count, pending payments, low-stock alerts |
| **Sales (POS)** | Tap-to-add menu, live cart & total, customer name, paid/pending status, records the sale and decrements stock |
| **Menu / Products** | Add, edit, delete, search items; upload a photo; set price, stock & reorder level *(Owner)* |
| **Inventory** | Monitor stock, quick +/- adjustments, set exact stock & reorder thresholds *(Owner)* |
| **Reports** | Daily / monthly / yearly revenue, pending totals, top sellers, stock value *(Owner)* |
| **Users** | Create & manage Owner / Cashier accounts, enable/disable, reset passwords *(Owner)* |

**Roles:** *Owner* has full access; *Cashier* can only process sales (per the project spec).

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

## Transaction flow (matches the project spec)

1. Cashier opens **New Sale**, enters the customer name, taps products and sets quantities.
2. The cart shows each line, the running total, and the payment status.
3. Cashier marks the sale **Paid** or **Pending**.
4. On record, the system saves the transaction, decrements inventory, and shows the receipt.
5. A **Pending** sale can later be marked **Paid** from its receipt page.
6. The **Dashboard** surfaces pending payments and low-stock items.

---

## Project structure

```
Small Business POS/
├── config/
│   └── config.php          # DB credentials + app settings (currency, uploads)
├── includes/
│   ├── db.php              # PDO connection (MySQL)
│   ├── auth.php            # login, sessions, Owner/Cashier guards
│   ├── helpers.php         # money(), CSRF, flash messages, escaping
│   ├── header.php          # layout + Material Web import + nav
│   └── footer.php
├── pages/                  # one file per screen (routed by the front controller)
│   ├── login.php  logout.php  dashboard.php
│   ├── sales.php  sale_view.php
│   ├── products.php  inventory.php  reports.php  users.php
│   └── _forbidden.php
├── public/                 # web root
│   ├── index.php           # front controller  (index.php?page=...)
│   ├── .htaccess
│   ├── assets/app.css      # Material Design 3 styling
│   └── uploads/            # menu photos saved here
└── sql/
    ├── schema.sql          # tables: users, products, sales, sale_items
    └── seed.sql            # sample menu, accounts, demo transactions
```

## Security notes

- Passwords are stored as bcrypt hashes (`password_hash`).
- All forms use CSRF tokens; all queries use prepared statements (PDO).
- Stock is decremented inside a DB transaction with row locking to prevent overselling.
- Set proper file permissions on `public/uploads/` and change the default logins before going live.
