-- ============================================================
-- Migration: गौशाळा — Tag/Batch-Number ऐच्छिक + Animal Category
-- (Batch-Number नसलेली गुरं — वासरू, गोऱ्हा — सुद्धा जोडता यावीत)
-- ============================================================
ALTER TABLE gaushala_cattle MODIFY COLUMN tag_number VARCHAR(50) NULL;
ALTER TABLE gaushala_cattle ADD COLUMN animal_category ENUM('Cow','Calf','Young Bull','Bull/Ox') DEFAULT 'Cow' AFTER gender;
