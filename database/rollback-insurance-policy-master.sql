-- ============================================================
-- Rollback: विमा Policy Master Feature काढून टाकल्यामुळे — हे
-- संबंधित Tables पण मागे घेत आहोत.
--
-- टीप: जर तुम्ही आधीच "migration-insurance-policy-master.sql" ही
-- Migration चालवलेली असेल, तरच ही File चालवा. आधी चालवलीच नसेल —
-- तर ही File चालवण्याची गरज नाही (Error येईल, तो सुरक्षितपणे
-- दुर्लक्षित करा).
--
-- ⚠️ सावधान: यामुळे आधीच नोंदवलेल्या सगळ्या विमा Policies आणि
-- त्यांचे Claims कायमचे हटवले जातील. आधी Backup घ्या!
-- ============================================================

DROP TABLE IF EXISTS insurance_claims;
DROP TABLE IF EXISTS insurance_policies;
