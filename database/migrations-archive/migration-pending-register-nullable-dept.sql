-- ============================================================
-- Migration: खरेदी बाकी नोंदवही — Bulk Import मध्ये विभाग जुळला
-- नाही तरीही Row Skip न होता Import व्हावी
-- ============================================================

ALTER TABLE purchase_pending_register MODIFY COLUMN department_id INT NULL;
ALTER TABLE purchase_pending_register ADD COLUMN unmatched_department_text VARCHAR(255) NULL COMMENT 'Import करताना टाइप केलेलं विभागाचं नाव — System मधल्या कुठल्याही विभागाशी जुळलं नाही तर, ते इथे जपून ठेवतो' AFTER department_id;
