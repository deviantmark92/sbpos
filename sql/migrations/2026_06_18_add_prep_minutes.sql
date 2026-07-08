-- ============================================================
-- Migration: add order prep/progress timer to sales
-- ============================================================
-- Adds a per-order preparation timer (in minutes). The order is
-- considered "ready" at created_at + prep_minutes. Defaults to 20,
-- but the cashier can set it per order on the New Sale screen.
--
-- Run with:
--   mysql -u root sbpos < sql/migrations/2026_06_18_add_prep_minutes.sql
-- ============================================================

ALTER TABLE sales
    ADD COLUMN prep_minutes INT NOT NULL DEFAULT 20 CHECK (prep_minutes > 0) AFTER note;
