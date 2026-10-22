-- ============================================================
-- Migration: गौशाळा — अंदाजे वय (जन्म-दिनांक माहीत नसलेल्या जनावरांसाठी)
-- ============================================================
ALTER TABLE gaushala_cattle ADD COLUMN approx_age_months INT NULL AFTER date_of_birth;
