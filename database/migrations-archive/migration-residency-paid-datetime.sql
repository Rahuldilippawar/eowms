-- ============================================================
-- Migration: निवास-बिल भरण्याची — तारीख + वेळ दोन्ही नोंदवण्यासाठी
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE residency_bill_shares CHANGE COLUMN paid_date paid_at DATETIME NULL;
