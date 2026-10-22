-- ============================================================
-- Migration: RTO/Transport ला Regulatory Body म्हणून जोडणे
-- (Vehicle Insurance/PUC/Permit/Fitness/Road Tax हे सुद्धा RTO या
-- शासकीय संस्थेचंच Compliance आहे — आता तेही स्पष्ट दिसेल)
-- Run this in phpMyAdmin -> your database -> SQL tab
-- ============================================================

ALTER TABLE renewal_items MODIFY COLUMN regulatory_body
  ENUM('CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government','Not Applicable') NULL;

-- आधीच अस्तित्वात असलेल्या वाहन-नूतनीकरण नोंदींना (item_type = 'वाहन नूतनीकरण')
-- आपोआप "RTO/Transport" असं चिन्हांकित करतो.
UPDATE renewal_items SET regulatory_body = 'RTO/Transport' WHERE item_type = 'वाहन नूतनीकरण' AND regulatory_body IS NULL;
