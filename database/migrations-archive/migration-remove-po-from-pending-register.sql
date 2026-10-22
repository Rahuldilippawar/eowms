-- ============================================================
-- Migration: खरेदी बाकी नोंदवही — PO क्र./दिनांक Columns काढणे
-- (ही Register पूर्णपणे Standalone राहणार आहे — Formal PO System
-- शी कुठलाही संबंध नको, म्हणून हे 2 Columns काढतो.)
-- ============================================================

ALTER TABLE purchase_pending_register DROP COLUMN po_no;
ALTER TABLE purchase_pending_register DROP COLUMN po_date;
