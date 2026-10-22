-- ============================================================
-- Rollback: चुकून Duplicate बनवलेलं "IT Service Desk" (it_service_tickets)
-- काढून टाकत आहोत — कारण "it_general_requests" ही आधीच अस्तित्वात
-- असलेली System हेच काम अचूक करते.
--
-- टीप: जर तुम्ही आधीच "migration-it-service-desk.sql" ही Migration
-- चालवलेली असेल, तरच ही File चालवा.
--
-- ⚠️ सावधान: यामुळे "IT तक्रार/विनंती नोंदवा" (raise-ticket.php) या
-- Duplicate Page वरून नोंदवलेले Tickets कायमचे हटवले जातील. काही
-- महत्त्वाची नोंद असेल तर आधी "it_general_requests" Table मध्ये
-- Manually हलवा, मगच ही File चालवा.
-- ============================================================

DROP TABLE IF EXISTS it_service_ticket_comments;
DROP TABLE IF EXISTS it_service_tickets;
