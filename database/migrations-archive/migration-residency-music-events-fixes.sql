-- ============================================================
-- Migration: रहिवासी विभाग — निवासी खोली Flag; संगीत/कार्यक्रम/इतर दुरुस्त्या
-- ============================================================

-- maintenance_locations मध्ये Office/Kitchen सुद्धा आहेत — फक्त "निवासी" जागाच रहिवासी विभागात मोजायच्या
ALTER TABLE maintenance_locations ADD COLUMN is_residential TINYINT(1) NOT NULL DEFAULT 0 AFTER capacity;

-- आधीपासून रहिवासी Data (रहिवासी/बिल/साहित्य/तपासणी/पाहुणे/खोली-बदल/वाटप/Import) असलेल्या जागा = निवासी,
-- जेणेकरून चालू Setup तसंच चालेल. (ज्या संस्थेत आधीच एखादी जागा निवासी चिन्हांकित आहे, तिथे पुन्हा चालवत नाही)
UPDATE maintenance_locations SET is_residential = 1 WHERE (id IN (SELECT location_id FROM residency_occupants) OR id IN (SELECT location_id FROM residency_bills) OR id IN (SELECT location_id FROM residency_room_items) OR id IN (SELECT location_id FROM residency_inspections) OR id IN (SELECT location_id FROM residency_guests) OR id IN (SELECT to_location_id FROM residency_room_transfers) OR id IN (SELECT allotted_location_id FROM residency_allotment_requests WHERE allotted_location_id IS NOT NULL) OR import_batch_id IS NOT NULL) AND organization_id NOT IN (SELECT organization_id FROM (SELECT DISTINCT organization_id FROM maintenance_locations WHERE is_residential = 1) AS already_flagged);

-- संगीत: वाद्य कोणाकडे आहे — Staff असेल तर current_holder_id, इतर (विद्यार्थी इ.) नाव इथे; आधी हे remarks मध्ये लिहिलं जायचं
ALTER TABLE music_instruments ADD COLUMN current_holder_name VARCHAR(255) NULL AFTER current_holder_id;
UPDATE music_instruments SET current_holder_name = TRIM(REPLACE(REPLACE(remarks, 'सध्या: ', ''), ' कडे', '')), remarks = NULL WHERE remarks LIKE 'सध्या: % कडे' AND current_holder_name IS NULL;
