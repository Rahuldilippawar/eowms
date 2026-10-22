-- ============================================================
-- Migration: IT Assets — PC Setup Grouping (workstation_no)
-- एका PC Setup (CPU+Monitor+Keyboard+Mouse+इतर) चे सगळे भाग एकत्र
-- ओळखता यावेत म्हणून — Run this in phpMyAdmin -> SQL tab
-- ============================================================

ALTER TABLE it_assets ADD COLUMN workstation_no VARCHAR(50) NULL AFTER lab_id;
