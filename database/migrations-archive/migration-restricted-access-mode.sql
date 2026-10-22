-- ============================================================
-- Migration: Single-Purpose Access Mode (Module-Restricted Roles)
-- एखादा Role फक्त 1-2 Specific Modules शीच मर्यादित ठेवण्यासाठी —
-- अशा Role च्या व्यक्तीला Login केल्यावर संपूर्ण System ऐवजी फक्त
-- त्यांचाच Module दिसेल.
-- ============================================================

ALTER TABLE role_definitions ADD COLUMN restricted_mode TINYINT(1) DEFAULT 0 AFTER is_system;
ALTER TABLE role_definitions ADD COLUMN restricted_modules VARCHAR(255) NULL AFTER restricted_mode COMMENT 'Comma-separated Module Keys — उदा. it,store';
