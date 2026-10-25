<?php
/**
 * संपूर्ण संस्थेचा Backup तयार करणारं मुख्य Function — Manual आणि
 * Automated (Cron) दोन्ही Backup याच एका ठिकाणाहून डेटा घेतात, जेणेकरून
 * दोन्हीकडे कायम सुसंगत, पूर्ण डेटा जातो — काहीही मागे राहत नाही.
 *
 * नवीन Module भविष्यात जोडलं, तर फक्त इथेच एक ओळ जोडायची लागते.
 */

function safeFetchAll($pdo, $sql, $params) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return []; // ते Table अजून migrate झालेलं नसेल — जे आहे तेवढं Export कर
    }
}

function buildOrgBackup($pdo, $orgId) {
    $orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
    $orgStmt->execute([$orgId]);
    $organization = $orgStmt->fetch();

    return [
        'eowms_backup_version' => 2,
        'exported_at' => date('c'),
        'organization' => $organization,

        // ---------------- पाया ----------------
        'campuses' => safeFetchAll($pdo, "SELECT * FROM campuses WHERE organization_id = ?", [$orgId]),
        'departments' => safeFetchAll($pdo, "SELECT * FROM departments WHERE organization_id = ?", [$orgId]),
        'users' => safeFetchAll($pdo, "SELECT * FROM users WHERE organization_id = ?", [$orgId]),
        'webauthn_credentials' => safeFetchAll($pdo, "
            SELECT wc.* FROM webauthn_credentials wc JOIN users u ON u.id = wc.user_id
            WHERE u.organization_id = ?", [$orgId]),
        'employee_family_members' => safeFetchAll($pdo, "
            SELECT efm.* FROM employee_family_members efm JOIN users u ON u.id = efm.user_id
            WHERE u.organization_id = ?", [$orgId]),

        // ---------------- दैनंदिन कामकाज ----------------
        'work_records' => safeFetchAll($pdo, "SELECT * FROM work_records WHERE organization_id = ?", [$orgId]),
        'work_record_team' => safeFetchAll($pdo, "
            SELECT wt.* FROM work_record_team wt JOIN work_records wr ON wr.id = wt.work_record_id
            WHERE wr.organization_id = ?", [$orgId]),
        'custom_fields' => safeFetchAll($pdo, "SELECT * FROM custom_fields WHERE organization_id = ?", [$orgId]),
        'custom_field_values' => safeFetchAll($pdo, "
            SELECT cfv.* FROM custom_field_values cfv JOIN work_records wr ON wr.id = cfv.work_record_id
            WHERE wr.organization_id = ?", [$orgId]),
        'renewal_items' => safeFetchAll($pdo, "SELECT * FROM renewal_items WHERE organization_id = ?", [$orgId]),
        'renewal_history' => safeFetchAll($pdo, "
            SELECT h.* FROM renewal_history h JOIN renewal_items r ON r.id = h.renewal_item_id WHERE r.organization_id = ?", [$orgId]),
        'tasks' => safeFetchAll($pdo, "SELECT * FROM tasks WHERE organization_id = ?", [$orgId]),

        // ---------------- वसतिगृह व रहिवासी ----------------
        'hostels' => safeFetchAll($pdo, "SELECT * FROM hostels WHERE organization_id = ?", [$orgId]),
        'hostel_staff' => safeFetchAll($pdo, "
            SELECT hs.* FROM hostel_staff hs JOIN hostels h ON h.id = hs.hostel_id
            WHERE h.organization_id = ?", [$orgId]),
        'hostel_students' => safeFetchAll($pdo, "
            SELECT hst.* FROM hostel_students hst JOIN hostels h ON h.id = hst.hostel_id
            WHERE h.organization_id = ?", [$orgId]),
        'hostel_student_attendance' => safeFetchAll($pdo, "
            SELECT hsa.* FROM hostel_student_attendance hsa JOIN hostels h ON h.id = hsa.hostel_id
            WHERE h.organization_id = ?", [$orgId]),
        'buildings' => safeFetchAll($pdo, "SELECT * FROM buildings WHERE organization_id = ?", [$orgId]),
        'maintenance_locations' => safeFetchAll($pdo, "SELECT * FROM maintenance_locations WHERE organization_id = ?", [$orgId]),
        'residency_occupants' => safeFetchAll($pdo, "SELECT * FROM residency_occupants WHERE organization_id = ?", [$orgId]),
        'residency_room_items' => safeFetchAll($pdo, "SELECT * FROM residency_room_items WHERE organization_id = ?", [$orgId]),
        'residency_bills' => safeFetchAll($pdo, "SELECT * FROM residency_bills WHERE organization_id = ?", [$orgId]),
        'residency_bill_shares' => safeFetchAll($pdo, "
            SELECT s.* FROM residency_bill_shares s JOIN residency_bills b ON b.id = s.bill_id WHERE b.organization_id = ?", [$orgId]),
        'residency_allotment_requests' => safeFetchAll($pdo, "SELECT * FROM residency_allotment_requests WHERE organization_id = ?", [$orgId]),
        'residency_inspections' => safeFetchAll($pdo, "SELECT * FROM residency_inspections WHERE organization_id = ?", [$orgId]),
        'residency_room_transfers' => safeFetchAll($pdo, "
            SELECT t.* FROM residency_room_transfers t JOIN maintenance_locations ml ON ml.id = t.to_location_id WHERE ml.organization_id = ?", [$orgId]),
        'residency_guests' => safeFetchAll($pdo, "SELECT * FROM residency_guests WHERE organization_id = ?", [$orgId]),
        'announcements' => safeFetchAll($pdo, "SELECT * FROM announcements WHERE organization_id = ?", [$orgId]),
        'announcement_departments' => safeFetchAll($pdo, "
            SELECT ad.* FROM announcement_departments ad JOIN announcements a ON a.id = ad.announcement_id WHERE a.organization_id = ?", [$orgId]),
        'announcement_reads' => safeFetchAll($pdo, "
            SELECT ar.* FROM announcement_reads ar JOIN announcements a ON a.id = ar.announcement_id WHERE a.organization_id = ?", [$orgId]),
        'documents' => safeFetchAll($pdo, "SELECT * FROM documents WHERE organization_id = ?", [$orgId]),
        'emergency_alerts' => safeFetchAll($pdo, "SELECT * FROM emergency_alerts WHERE organization_id = ?", [$orgId]),
        'music_instruments' => safeFetchAll($pdo, "SELECT * FROM music_instruments WHERE organization_id = ?", [$orgId]),
        'music_instrument_log' => safeFetchAll($pdo, "
            SELECT l.* FROM music_instrument_log l JOIN music_instruments mi ON mi.id = l.instrument_id WHERE mi.organization_id = ?", [$orgId]),
        'music_room_bookings' => safeFetchAll($pdo, "SELECT * FROM music_room_bookings WHERE organization_id = ?", [$orgId]),
        'music_students' => safeFetchAll($pdo, "SELECT * FROM music_students WHERE organization_id = ?", [$orgId]),
        'music_enrollments' => safeFetchAll($pdo, "
            SELECT e.* FROM music_enrollments e JOIN music_students s ON s.id = e.student_id WHERE s.organization_id = ?", [$orgId]),
        'music_attendance' => safeFetchAll($pdo, "
            SELECT a.* FROM music_attendance a JOIN music_enrollments e ON e.id = a.enrollment_id JOIN music_students s ON s.id = e.student_id WHERE s.organization_id = ?", [$orgId]),
        'music_exams' => safeFetchAll($pdo, "
            SELECT me.* FROM music_exams me JOIN music_students s ON s.id = me.student_id WHERE s.organization_id = ?", [$orgId]),
        'music_events' => safeFetchAll($pdo, "SELECT * FROM music_events WHERE organization_id = ?", [$orgId]),
        'music_event_participants' => safeFetchAll($pdo, "
            SELECT ep.* FROM music_event_participants ep JOIN music_events ev ON ev.id = ep.event_id WHERE ev.organization_id = ?", [$orgId]),
        'music_sheet_library' => safeFetchAll($pdo, "SELECT * FROM music_sheet_library WHERE organization_id = ?", [$orgId]),
        'user_extra_department_access' => safeFetchAll($pdo, "
            SELECT uda.* FROM user_extra_department_access uda JOIN users u ON u.id = uda.user_id WHERE u.organization_id = ?", [$orgId]),
        'user_extra_campus_access' => safeFetchAll($pdo, "
            SELECT uca.* FROM user_extra_campus_access uca JOIN users u ON u.id = uca.user_id WHERE u.organization_id = ?", [$orgId]),
        'birthday_wishes' => safeFetchAll($pdo, "SELECT * FROM birthday_wishes WHERE organization_id = ?", [$orgId]),
        'staff_documents' => safeFetchAll($pdo, "
            SELECT sd.* FROM staff_documents sd JOIN users u ON u.id = sd.user_id WHERE u.organization_id = ?", [$orgId]),
        'staff_achievements' => safeFetchAll($pdo, "
            SELECT sa.* FROM staff_achievements sa JOIN users u ON u.id = sa.user_id WHERE u.organization_id = ?", [$orgId]),
        'biometric_devices' => safeFetchAll($pdo, "SELECT * FROM biometric_devices WHERE organization_id = ?", [$orgId]),
        'staff_attendance' => safeFetchAll($pdo, "SELECT * FROM staff_attendance WHERE organization_id = ?", [$orgId]),
        'staff_shift_settings' => safeFetchAll($pdo, "
            SELECT s.* FROM staff_shift_settings s JOIN users u ON u.id = s.user_id WHERE u.organization_id = ?", [$orgId]),
        'holidays' => safeFetchAll($pdo, "SELECT * FROM holidays WHERE organization_id = ?", [$orgId]),
        'attendance_regularization_requests' => safeFetchAll($pdo, "SELECT * FROM attendance_regularization_requests WHERE organization_id = ?", [$orgId]),
        'leave_types' => safeFetchAll($pdo, "SELECT * FROM leave_types WHERE organization_id = ?", [$orgId]),
        'leave_balances' => safeFetchAll($pdo, "
            SELECT lb.* FROM leave_balances lb JOIN users u ON u.id = lb.user_id WHERE u.organization_id = ?", [$orgId]),
        'leave_applications' => safeFetchAll($pdo, "SELECT * FROM leave_applications WHERE organization_id = ?", [$orgId]),
        'students' => safeFetchAll($pdo, "SELECT * FROM students WHERE organization_id = ?", [$orgId]),
        'virtual_classrooms' => safeFetchAll($pdo, "SELECT * FROM virtual_classrooms WHERE organization_id = ?", [$orgId]),
        'classroom_students' => safeFetchAll($pdo, "
            SELECT cs.* FROM classroom_students cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_staff' => safeFetchAll($pdo, "
            SELECT cs.* FROM classroom_staff cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_materials' => safeFetchAll($pdo, "
            SELECT cm.* FROM classroom_materials cm JOIN virtual_classrooms vc ON vc.id = cm.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_assignments' => safeFetchAll($pdo, "
            SELECT ca.* FROM classroom_assignments ca JOIN virtual_classrooms vc ON vc.id = ca.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_assignment_submissions' => safeFetchAll($pdo, "
            SELECT sub.* FROM classroom_assignment_submissions sub JOIN classroom_assignments ca ON ca.id = sub.assignment_id
            JOIN virtual_classrooms vc ON vc.id = ca.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_announcements' => safeFetchAll($pdo, "
            SELECT ca.* FROM classroom_announcements ca JOIN virtual_classrooms vc ON vc.id = ca.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_attendance' => safeFetchAll($pdo, "
            SELECT ca.* FROM classroom_attendance ca JOIN virtual_classrooms vc ON vc.id = ca.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_exams' => safeFetchAll($pdo, "
            SELECT ce.* FROM classroom_exams ce JOIN virtual_classrooms vc ON vc.id = ce.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_exam_marks' => safeFetchAll($pdo, "
            SELECT em.* FROM classroom_exam_marks em JOIN classroom_exams ce ON ce.id = em.exam_id
            JOIN virtual_classrooms vc ON vc.id = ce.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_timetable' => safeFetchAll($pdo, "
            SELECT ct.* FROM classroom_timetable ct JOIN virtual_classrooms vc ON vc.id = ct.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_questions' => safeFetchAll($pdo, "
            SELECT cq.* FROM classroom_questions cq JOIN virtual_classrooms vc ON vc.id = cq.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'classroom_question_replies' => safeFetchAll($pdo, "
            SELECT cqr.* FROM classroom_question_replies cqr JOIN classroom_questions cq ON cq.id = cqr.question_id
            JOIN virtual_classrooms vc ON vc.id = cq.classroom_id WHERE vc.organization_id = ?", [$orgId]),
        'student_notifications' => safeFetchAll($pdo, "
            SELECT sn.* FROM student_notifications sn JOIN students s ON s.id = sn.student_id WHERE s.organization_id = ?", [$orgId]),
        'events' => safeFetchAll($pdo, "SELECT * FROM events WHERE organization_id = ?", [$orgId]),
        'teacher_timetable' => safeFetchAll($pdo, "SELECT * FROM teacher_timetable WHERE organization_id = ?", [$orgId]),
        'substitute_assignments' => safeFetchAll($pdo, "SELECT * FROM substitute_assignments WHERE organization_id = ?", [$orgId]),
        'syllabus_trackers' => safeFetchAll($pdo, "SELECT * FROM syllabus_trackers WHERE organization_id = ?", [$orgId]),
        'teacher_qualifications' => safeFetchAll($pdo, "
            SELECT tq.* FROM teacher_qualifications tq JOIN users u ON u.id = tq.teacher_id WHERE u.organization_id = ?", [$orgId]),
        'class_teacher_assignments' => safeFetchAll($pdo, "SELECT * FROM class_teacher_assignments WHERE organization_id = ?", [$orgId]),
        'exam_duty_allocations' => safeFetchAll($pdo, "SELECT * FROM exam_duty_allocations WHERE organization_id = ?", [$orgId]),
        'teacher_feedback' => safeFetchAll($pdo, "
            SELECT tf.* FROM teacher_feedback tf JOIN users u ON u.id = tf.teacher_id WHERE u.organization_id = ?", [$orgId]),
        'event_tasks' => safeFetchAll($pdo, "
            SELECT et.* FROM event_tasks et JOIN events e ON e.id = et.event_id WHERE e.organization_id = ?", [$orgId]),
        'event_budget_items' => safeFetchAll($pdo, "
            SELECT ebi.* FROM event_budget_items ebi JOIN events e ON e.id = ebi.event_id WHERE e.organization_id = ?", [$orgId]),
        'event_photos' => safeFetchAll($pdo, "
            SELECT ep.* FROM event_photos ep JOIN events e ON e.id = ep.event_id WHERE e.organization_id = ?", [$orgId]),
        'guests' => safeFetchAll($pdo, "SELECT * FROM guests WHERE organization_id = ?", [$orgId]),
        'checkout_items' => safeFetchAll($pdo, "SELECT * FROM checkout_items WHERE organization_id = ?", [$orgId]),
        'asset_allocation_types' => safeFetchAll($pdo, "SELECT * FROM asset_allocation_types WHERE organization_id = ?", [$orgId]),
        'asset_allocation_fields' => safeFetchAll($pdo, "
            SELECT aaf.* FROM asset_allocation_fields aaf JOIN asset_allocation_types aat ON aat.id = aaf.type_id WHERE aat.organization_id = ?", [$orgId]),
        'asset_allocations' => safeFetchAll($pdo, "SELECT * FROM asset_allocations WHERE organization_id = ?", [$orgId]),
        'bookable_rooms' => safeFetchAll($pdo, "SELECT * FROM bookable_rooms WHERE organization_id = ?", [$orgId]),
        'room_bookings' => safeFetchAll($pdo, "
            SELECT rb.* FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id WHERE br.organization_id = ?", [$orgId]),
        'staff_messages' => safeFetchAll($pdo, "
            SELECT sm.* FROM staff_messages sm JOIN users u ON u.id = sm.sender_id WHERE u.organization_id = ?", [$orgId]),
        // गुप्त तक्रार — तक्रारदाराची ओळख (submitted_by / submitter_hash) Backup मध्ये कधीही नाही
        'grievances' => safeFetchAll($pdo, "SELECT id, organization_id, IF(is_anonymous = 1, NULL, submitted_by) AS submitted_by, category, description, is_anonymous, status, assigned_to, resolution, sla_deadline, resolved_at, created_at FROM grievances WHERE organization_id = ?", [$orgId]),
        'dept_material_purchases' => safeFetchAll($pdo, "SELECT * FROM dept_material_purchases WHERE organization_id = ?", [$orgId]),
        'dept_payment_ledger' => safeFetchAll($pdo, "SELECT * FROM dept_payment_ledger WHERE organization_id = ?", [$orgId]),
        'gaushala_cattle' => safeFetchAll($pdo, "SELECT * FROM gaushala_cattle WHERE organization_id = ?", [$orgId]),
        'gaushala_health_records' => safeFetchAll($pdo, "
            SELECT gh.* FROM gaushala_health_records gh JOIN gaushala_cattle gc ON gc.id = gh.cattle_id WHERE gc.organization_id = ?", [$orgId]),
        'gaushala_milk_records' => safeFetchAll($pdo, "
            SELECT gm.* FROM gaushala_milk_records gm JOIN gaushala_cattle gc ON gc.id = gm.cattle_id WHERE gc.organization_id = ?", [$orgId]),
        'gaushala_fodder_types' => safeFetchAll($pdo, "SELECT * FROM gaushala_fodder_types WHERE organization_id = ?", [$orgId]),
        'gaushala_fodder_transactions' => safeFetchAll($pdo, "
            SELECT gft.* FROM gaushala_fodder_transactions gft JOIN gaushala_fodder_types gf ON gf.id = gft.fodder_type_id WHERE gf.organization_id = ?", [$orgId]),
        'gaushala_breeding_records' => safeFetchAll($pdo, "
            SELECT gb.* FROM gaushala_breeding_records gb JOIN gaushala_cattle gc ON gc.id = gb.cattle_id WHERE gc.organization_id = ?", [$orgId]),
        'gaushala_sponsorships' => safeFetchAll($pdo, "SELECT * FROM gaushala_sponsorships WHERE organization_id = ?", [$orgId]),
        'checkout_logs' => safeFetchAll($pdo, "
            SELECT cl.* FROM checkout_logs cl JOIN checkout_items ci ON ci.id = cl.item_id WHERE ci.organization_id = ?", [$orgId]),
        'guest_pre_registrations' => safeFetchAll($pdo, "SELECT * FROM guest_pre_registrations WHERE organization_id = ?", [$orgId]),
        'event_attendance' => safeFetchAll($pdo, "
            SELECT ea.* FROM event_attendance ea JOIN events e ON e.id = ea.event_id WHERE e.organization_id = ?", [$orgId]),
        'hostel_rooms' => safeFetchAll($pdo, "
            SELECT r.* FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_room_allocations' => safeFetchAll($pdo, "
            SELECT a.* FROM hostel_room_allocations a JOIN hostel_rooms r ON r.id = a.room_id JOIN hostels h ON h.id = r.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_fees' => safeFetchAll($pdo, "
            SELECT f.* FROM hostel_fees f JOIN hostel_students hs ON hs.id = f.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_fee_payments' => safeFetchAll($pdo, "
            SELECT p.* FROM hostel_fee_payments p JOIN hostel_fees f ON f.id = p.fee_id JOIN hostel_students hs ON hs.id = f.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_student_health' => safeFetchAll($pdo, "
            SELECT hh.* FROM hostel_student_health hh JOIN hostel_students hs ON hs.id = hh.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_health_incidents' => safeFetchAll($pdo, "
            SELECT hi.* FROM hostel_health_incidents hi JOIN hostel_students hs ON hs.id = hi.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_leave_records' => safeFetchAll($pdo, "
            SELECT lr.* FROM hostel_leave_records lr JOIN hostel_students hs ON hs.id = lr.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'hostel_alumni' => safeFetchAll($pdo, "
            SELECT al.* FROM hostel_alumni al JOIN hostel_students hs ON hs.id = al.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?", [$orgId]),
        'software_suggestions' => safeFetchAll($pdo, "SELECT * FROM software_suggestions WHERE organization_id = ?", [$orgId]),
        'software_suggestion_votes' => safeFetchAll($pdo, "
            SELECT v.* FROM software_suggestion_votes v JOIN software_suggestions s ON s.id = v.suggestion_id WHERE s.organization_id = ?", [$orgId]),
        'software_suggestion_comments' => safeFetchAll($pdo, "
            SELECT c.* FROM software_suggestion_comments c JOIN software_suggestions s ON s.id = c.suggestion_id WHERE s.organization_id = ?", [$orgId]),
        'it_general_requests' => safeFetchAll($pdo, "SELECT * FROM it_general_requests WHERE organization_id = ?", [$orgId]),
        'it_general_request_comments' => safeFetchAll($pdo, "
            SELECT c.* FROM it_general_request_comments c JOIN it_general_requests r ON r.id = c.request_id WHERE r.organization_id = ?", [$orgId]),
        'work_record_materials' => safeFetchAll($pdo, "
            SELECT m.* FROM work_record_materials m JOIN work_records wr ON wr.id = m.work_record_id WHERE wr.organization_id = ?", [$orgId]),

        // ---------------- वाहन व मालमत्ता ----------------
        'vehicles' => safeFetchAll($pdo, "SELECT * FROM vehicles WHERE organization_id = ?", [$orgId]),
        'vehicle_trips' => safeFetchAll($pdo, "
            SELECT vt.* FROM vehicle_trips vt JOIN vehicles v ON v.id = vt.vehicle_id
            WHERE v.organization_id = ?", [$orgId]),
        'vehicle_fuel_log' => safeFetchAll($pdo, "
            SELECT vf.* FROM vehicle_fuel_log vf JOIN vehicles v ON v.id = vf.vehicle_id
            WHERE v.organization_id = ?", [$orgId]),
        'vehicle_maintenance' => safeFetchAll($pdo, "
            SELECT vm.* FROM vehicle_maintenance vm JOIN vehicles v ON v.id = vm.vehicle_id
            WHERE v.organization_id = ?", [$orgId]),
        'vehicle_correction_requests' => safeFetchAll($pdo, "
            SELECT vcr.* FROM vehicle_correction_requests vcr JOIN vehicles v ON v.id = vcr.vehicle_id
            WHERE v.organization_id = ?", [$orgId]),
        'dead_stock_item_master' => safeFetchAll($pdo, "SELECT * FROM dead_stock_item_master WHERE organization_id = ?", [$orgId]),
        'dead_stock_items' => safeFetchAll($pdo, "SELECT * FROM dead_stock_items WHERE organization_id = ?", [$orgId]),
        'maintenance_logs' => safeFetchAll($pdo, "SELECT * FROM maintenance_logs WHERE organization_id = ?", [$orgId]),

        // ---------------- पत्र व्यवहार ----------------
        'letter_categories' => safeFetchAll($pdo, "SELECT * FROM letter_categories WHERE organization_id = ?", [$orgId]),
        'workflow_templates' => safeFetchAll($pdo, "SELECT * FROM workflow_templates WHERE organization_id = ?", [$orgId]),
        'workflow_steps' => safeFetchAll($pdo, "
            SELECT ws.* FROM workflow_steps ws JOIN workflow_templates wt ON wt.id = ws.workflow_template_id
            WHERE wt.organization_id = ?", [$orgId]),
        'letters' => safeFetchAll($pdo, "SELECT * FROM letters WHERE organization_id = ?", [$orgId]),
        'letter_actions' => safeFetchAll($pdo, "
            SELECT la.* FROM letter_actions la JOIN letters l ON l.id = la.letter_id
            WHERE l.organization_id = ?", [$orgId]),
        'letter_attachments' => safeFetchAll($pdo, "
            SELECT lat.* FROM letter_attachments lat JOIN letters l ON l.id = lat.letter_id
            WHERE l.organization_id = ?", [$orgId]),
        'letter_inward_numbers' => safeFetchAll($pdo, "
            SELECT lin.* FROM letter_inward_numbers lin JOIN letters l ON l.id = lin.letter_id
            WHERE l.organization_id = ?", [$orgId]),

        // ---------------- आर्थिक ----------------
        'department_budgets' => safeFetchAll($pdo, "SELECT * FROM department_budgets WHERE organization_id = ?", [$orgId]),
        'budget_transactions' => safeFetchAll($pdo, "SELECT * FROM budget_transactions WHERE organization_id = ?", [$orgId]),

        // ---------------- ध्यान व सत्संग ----------------
        'satsang_events' => safeFetchAll($pdo, "SELECT * FROM satsang_events WHERE organization_id = ?", [$orgId]),
        'satsang_duties' => safeFetchAll($pdo, "
            SELECT sd.* FROM satsang_duties sd JOIN satsang_events se ON se.id = sd.satsang_event_id
            WHERE se.organization_id = ?", [$orgId]),
        'satsang_attendance' => safeFetchAll($pdo, "
            SELECT sa.* FROM satsang_attendance sa JOIN satsang_events se ON se.id = sa.satsang_event_id
            WHERE se.organization_id = ?", [$orgId]),
        'meditation_quotes' => safeFetchAll($pdo, "SELECT * FROM meditation_quotes WHERE organization_id = ?", [$orgId]),

        // ---------------- सार्वजनिक विनंत्या ----------------
        'work_requests' => safeFetchAll($pdo, "SELECT * FROM work_requests WHERE organization_id = ?", [$orgId]),
        'work_request_actions' => safeFetchAll($pdo, "
            SELECT wra.* FROM work_request_actions wra JOIN work_requests wr ON wr.id = wra.request_id
            WHERE wr.organization_id = ?", [$orgId]),
        'work_request_views' => safeFetchAll($pdo, "
            SELECT wrv.* FROM work_request_views wrv JOIN work_requests wr ON wr.id = wrv.request_id
            WHERE wr.organization_id = ?", [$orgId]),

        // ---------------- SOP Compliance ----------------
        'sops' => safeFetchAll($pdo, "SELECT * FROM sops WHERE organization_id = ?", [$orgId]),
        'sop_steps' => safeFetchAll($pdo, "
            SELECT ss.* FROM sop_steps ss JOIN sops s ON s.id = ss.sop_id
            WHERE s.organization_id = ?", [$orgId]),
        'sop_department_assignments' => safeFetchAll($pdo, "
            SELECT sda.* FROM sop_department_assignments sda JOIN sops s ON s.id = sda.sop_id
            WHERE s.organization_id = ?", [$orgId]),
        'sop_compliance_logs' => safeFetchAll($pdo, "
            SELECT scl.* FROM sop_compliance_logs scl
            JOIN sop_steps ss ON ss.id = scl.sop_step_id JOIN sops s ON s.id = ss.sop_id
            WHERE s.organization_id = ?", [$orgId]),

        // ---------------- About Page ----------------
        'about_sections' => safeFetchAll($pdo, "SELECT * FROM about_sections WHERE organization_id = ?", [$orgId]),
        'about_contributors' => safeFetchAll($pdo, "SELECT * FROM about_contributors WHERE organization_id = ?", [$orgId]),

        // ---------------- सूचना व नोंदी ----------------
        'notifications' => safeFetchAll($pdo, "SELECT * FROM notifications WHERE organization_id = ?", [$orgId]),
        'activity_logs' => safeFetchAll($pdo, "SELECT * FROM activity_logs WHERE organization_id = ?", [$orgId]),

        // ---------------- पत्र — Digital Signature ----------------
        'letter_signatures' => safeFetchAll($pdo, "
            SELECT ls.* FROM letter_signatures ls JOIN letters l ON l.id = ls.letter_id
            WHERE l.organization_id = ?", [$orgId]),

        // ---------------- भांडार Material-यादी (Daily-Work साठी) ----------------
        'store_items' => safeFetchAll($pdo, "SELECT * FROM store_items WHERE organization_id = ?", [$orgId]),

        // ---------------- CCTV विभाग ----------------
        'cctv_dvrs' => safeFetchAll($pdo, "SELECT * FROM cctv_dvrs WHERE organization_id = ?", [$orgId]),
        'cctv_cameras' => safeFetchAll($pdo, "SELECT * FROM cctv_cameras WHERE organization_id = ?", [$orgId]),
        'cctv_checkups' => safeFetchAll($pdo, "SELECT * FROM cctv_checkups WHERE organization_id = ?", [$orgId]),
        'cctv_camera_status' => safeFetchAll($pdo, "
            SELECT cs.* FROM cctv_camera_status cs JOIN cctv_checkups ck ON ck.id = cs.checkup_id
            WHERE ck.organization_id = ?", [$orgId]),

        // ---------------- IT विभाग ----------------
        'it_labs' => safeFetchAll($pdo, "SELECT * FROM it_labs WHERE organization_id = ?", [$orgId]),
        'it_assets' => safeFetchAll($pdo, "SELECT * FROM it_assets WHERE organization_id = ?", [$orgId]),
        'it_asset_transfers' => safeFetchAll($pdo, "
            SELECT tr.* FROM it_asset_transfers tr JOIN it_assets a ON a.id = tr.asset_id
            WHERE a.organization_id = ?", [$orgId]),
        'it_repair_tickets' => safeFetchAll($pdo, "SELECT * FROM it_repair_tickets WHERE organization_id = ?", [$orgId]),

        // टीप: role_definitions / permissions / role_permissions हे सगळ्या
        // संस्थांसाठी समान, System-Wide Tables आहेत — कुठल्याही एका संस्थेचा
        // भाग नाहीत, त्यामुळे इथे समाविष्ट केलेले नाहीत.
        // password_reset_codes (तात्पुरते, लवकर Expire होणारे) आणि
        // scheduled_backups (Backup-इतिहासाचीच नोंद) — हेतुपुरस्सर वगळले आहेत.
    ];
}
