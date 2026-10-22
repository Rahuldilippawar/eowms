-- ============================================================
-- Rollback: "रहिवासी विभाग समन्वयक" Feature काढून टाकल्याने —
-- हे संबंधित Database बदल पण मागे घेत आहोत.
--
-- टीप: जर तुम्ही आधीच "migration-residency-coordinator.sql" ही
-- Migration चालवलेली असेल, तरच ही File चालवा. आधी चालवलीच नसेल —
-- तर ही File चालवण्याची गरज नाही (Error येईल, तो सुरक्षितपणे दुर्लक्षित
-- करा).
-- ============================================================

ALTER TABLE users DROP COLUMN is_residency_coordinator;
ALTER TABLE residency_allotment_requests DROP FOREIGN KEY residency_allotment_requests_ibfk_2;
ALTER TABLE residency_allotment_requests DROP COLUMN requested_for_user_id;
