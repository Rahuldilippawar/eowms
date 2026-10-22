<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();
$error = null;
$successSummary = null;

// सध्याच्या Organization मध्ये आधीच काही Data आहे का — असेल तर Restore
// केल्याने जुनी नोंद डुप्लिकेट होण्याचा धोका आहे, त्यासाठी Explicit
// Confirmation आवश्यक करतो.
$existingDataCheck = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id = ?");
$existingDataCheck->execute([$orgId]);
$hasExistingData = (int)$existingDataCheck->fetch()['c'] > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['backup_file']['tmp_name']) && $hasExistingData && empty($_POST['confirm_duplicate_risk'])) {
    $error = 'या Organization मध्ये आधीच Data आहे — Restore केल्याने नोंदी Duplicate होऊ शकतात. कृपया खालचा Checkbox निवडून पुन्हा प्रयत्न करा.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['backup_file']['tmp_name'])) {
    $uploadedFilePath = $_FILES['backup_file']['tmp_name'];
    $uploadedFileName = $_FILES['backup_file']['name'] ?? '';
    $restoredFileCount = 0;

    // Full Backup (.zip — Data + प्रत्यक्ष Files) असेल तर आधी Files
    // काढून, आतला data.json वापरतो. साधा (.json) असेल तर पूर्वीसारखंच.
    if (strtolower(pathinfo($uploadedFileName, PATHINFO_EXTENSION)) === 'zip') {
        if (!class_exists('ZipArchive')) {
            $error = 'तुमच्या Server वर PHP "Zip" Extension उपलब्ध नाही — Full Backup Restore करता येणार नाही.';
            $json = false;
        } else {
            $zip = new ZipArchive();
            if ($zip->open($uploadedFilePath) !== true) {
                $error = 'ही Zip File उघडता आली नाही — Corrupt असू शकते.';
                $json = false;
            } else {
                $json = $zip->getFromName('data.json');
                // सगळ्या Uploaded Files परत जागच्या जागी ठेवतो
                $uploadsRestoreDir = __DIR__ . '/../assets/uploads';
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $entryName = $zip->getNameIndex($i);
                    if (strpos($entryName, 'uploads/') === 0) {
                        $targetPath = __DIR__ . '/../assets/' . $entryName;
                        $targetDir = dirname($targetPath);
                        if (!is_dir($targetDir)) { mkdir($targetDir, 0755, true); }
                        if (!is_file($targetPath)) { // आधीच असलेली File Overwrite करत नाही
                            copy('zip://' . $uploadedFilePath . '#' . $entryName, $targetPath);
                            $restoredFileCount++;
                        }
                    } elseif (strpos($entryName, 'org-branding/') === 0) {
                        $targetPath = __DIR__ . '/../assets/uploads/' . basename($entryName);
                        if (!is_dir(dirname($targetPath))) { mkdir(dirname($targetPath), 0755, true); }
                        if (!is_file($targetPath)) {
                            copy('zip://' . $uploadedFilePath . '#' . $entryName, $targetPath);
                            $restoredFileCount++;
                        }
                    }
                }
                $zip->close();
            }
        }
    } else {
        $json = file_get_contents($uploadedFilePath);
    }

    $data = $json ? json_decode($json, true) : null;

    if (!$data || !isset($data['eowms_backup_version'])) {
        $error = $error ?: t('s544');
    } else {
        $pdo->beginTransaction();
        try {
            $campusMap = [];
            $deptMap = [];
            $userMap = [];
            $workRecordMap = [];
            $hostelMap = [];
            $counts = ['campuses' => 0, 'departments' => 0, 'users' => 0, 'work_records' => 0, 'tasks' => 0, 'renewal_items' => 0, 'hostels' => 0, 'hostel_students' => 0, 'work_requests' => 0];

            // ---------------- Campuses (नावाने आधीच असेल तर तीच वापरा — Duplicate टाळण्यासाठी) ----------------
            $existingCampusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ?");
            $existingCampusStmt->execute([$orgId]);
            $existingCampusByName = [];
            foreach ($existingCampusStmt->fetchAll() as $ec) { $existingCampusByName[mb_strtolower(trim($ec['name']))] = $ec['id']; }

            foreach ($data['campuses'] ?? [] as $c) {
                $nameKey = mb_strtolower(trim($c['name']));
                if (isset($existingCampusByName[$nameKey])) {
                    // आधीच याच नावाची Campus आहे — नवीन तयार न करता तीच वापरतो
                    $campusMap[$c['id']] = $existingCampusByName[$nameKey];
                    continue;
                }
                $ins = $pdo->prepare("
                    INSERT INTO campuses (organization_id, name, code, city, state, address, is_active, college_type, principal_name, affiliated_university, ugc_recognition, naac_grade, established_year)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                // code has a UNIQUE constraint — make it collision-safe on re-import
                $code = $c['code'] . '-R' . substr(uniqid(), -4);
                $ins->execute([
                    $orgId, $c['name'], $code, $c['city'], $c['state'], $c['address'], $c['is_active'],
                    $c['college_type'] ?? null, $c['principal_name'] ?? null, $c['affiliated_university'] ?? null,
                    $c['ugc_recognition'] ?? null, $c['naac_grade'] ?? null, $c['established_year'] ?? null,
                ]);
                $campusMap[$c['id']] = $pdo->lastInsertId();
                $existingCampusByName[$nameKey] = $campusMap[$c['id']]; // याच Batch मधल्या पुढच्या Duplicate साठीही लक्षात ठेवतो
                $counts['campuses']++;
            }

            // ---------------- Departments (नावाने आधीच असेल तर तीच वापरा — Duplicate टाळण्यासाठी) ----------------
            $existingDeptStmt = $pdo->prepare("SELECT id, name FROM departments WHERE organization_id = ?");
            $existingDeptStmt->execute([$orgId]);
            $existingDeptByName = [];
            foreach ($existingDeptStmt->fetchAll() as $ed) { $existingDeptByName[mb_strtolower(trim($ed['name']))] = $ed['id']; }

            foreach ($data['departments'] ?? [] as $d) {
                $newCampusId = $campusMap[$d['campus_id']] ?? null;
                if (!$newCampusId) continue;
                $nameKey = mb_strtolower(trim($d['name']));
                if (isset($existingDeptByName[$nameKey])) {
                    // आधीच याच नावाचा विभाग आहे — नवीन तयार न करता तोच वापरतो
                    $deptMap[$d['id']] = $existingDeptByName[$nameKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newCampusId, $d['name'], $d['name_local'], $d['code'], $d['icon'], $d['is_active']]);
                $deptMap[$d['id']] = $pdo->lastInsertId();
                $existingDeptByName[$nameKey] = $deptMap[$d['id']];
                $counts['departments']++;
            }

            // ---------------- Users (skip if email already exists — protects existing accounts) ----------------
            $checkEmail = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            foreach ($data['users'] ?? [] as $u) {
                $checkEmail->execute([$u['email']]);
                $existing = $checkEmail->fetch();
                if ($existing) {
                    // Already exists (e.g. the protected Super Admin) — map to the existing user, don't duplicate
                    $userMap[$u['id']] = $existing['id'];
                    continue;
                }
                $newCampusId = $campusMap[$u['campus_id']] ?? null;
                $newDeptId = $u['department_id'] ? ($deptMap[$u['department_id']] ?? null) : null;
                if (!$newCampusId) continue;
                $ins = $pdo->prepare("
                    INSERT INTO users (organization_id, campus_id, department_id, employee_code, name, email, mobile, designation, password, role, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                // employee_code is UNIQUE — make collision-safe
                $empCode = $u['employee_code'] ? $u['employee_code'] . '-R' . substr(uniqid(), -4) : null;
                $ins->execute([$orgId, $newCampusId, $newDeptId, $empCode, $u['name'], $u['email'], $u['mobile'], $u['designation'], $u['password'], $u['role'], $u['is_active']]);
                $userMap[$u['id']] = $pdo->lastInsertId();
                $counts['users']++;
            }

            // Re-link department HODs now that users exist
            foreach ($data['departments'] ?? [] as $d) {
                if (!empty($d['hod_id']) && isset($deptMap[$d['id']]) && isset($userMap[$d['hod_id']])) {
                    $pdo->prepare("UPDATE departments SET hod_id = ? WHERE id = ?")->execute([$userMap[$d['hod_id']], $deptMap[$d['id']]]);
                }
            }

            // ---------------- Work Records (विभाग+कर्मचारी+दिनांक+शीर्षक जुळल्यास Duplicate मानतो) ----------------
            $existingWrStmt = $pdo->prepare("SELECT id, department_id, employee_id, work_date, title FROM work_records WHERE organization_id = ?");
            $existingWrStmt->execute([$orgId]);
            $existingWrKeys = [];
            foreach ($existingWrStmt->fetchAll() as $ewr) { $existingWrKeys[$ewr['department_id'] . '|' . $ewr['employee_id'] . '|' . $ewr['work_date'] . '|' . mb_strtolower(trim($ewr['title']))] = $ewr['id']; }

            foreach ($data['work_records'] ?? [] as $w) {
                $newDeptId = $deptMap[$w['department_id']] ?? null;
                $newEmpId = $userMap[$w['employee_id']] ?? null;
                if (!$newDeptId || !$newEmpId) continue;
                $wrKey = $newDeptId . '|' . $newEmpId . '|' . $w['work_date'] . '|' . mb_strtolower(trim($w['title']));
                if (isset($existingWrKeys[$wrKey])) {
                    $workRecordMap[$w['id']] = $existingWrKeys[$wrKey];
                    continue;
                }
                $newCampusId = $campusMap[$w['campus_id']] ?? reset($campusMap);
                $newEnteredBy = $userMap[$w['entered_by_user_id']] ?? $newEmpId;
                $ins = $pdo->prepare("
                    INSERT INTO work_records (work_code, organization_id, campus_id, department_id, requested_by, employee_id, entered_by_role, entered_by_user_id, work_date, title, description, priority, status, start_date, due_date, completion_date, estimated_cost, actual_cost)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $workCode = $w['work_code'] . '-R' . substr(uniqid(), -4);
                $ins->execute([$workCode, $orgId, $newCampusId, $newDeptId, $w['requested_by'] ?? null, $newEmpId, $w['entered_by_role'], $newEnteredBy, $w['work_date'], $w['title'], $w['description'], $w['priority'], $w['status'], $w['start_date'], $w['due_date'], $w['completion_date'], $w['estimated_cost'], $w['actual_cost']]);
                $workRecordMap[$w['id']] = $pdo->lastInsertId();
                $existingWrKeys[$wrKey] = $workRecordMap[$w['id']];
                $counts['work_records']++;
            }

            // ---------------- Work Record Team (multi-employee tasks) — आधीच INSERT IGNORE मुळे सुरक्षित ----------------
            foreach ($data['work_record_team'] ?? [] as $wt) {
                $newRecordId = $workRecordMap[$wt['work_record_id']] ?? null;
                $newTeamUserId = $userMap[$wt['user_id']] ?? null;
                if (!$newRecordId || !$newTeamUserId) continue;
                $pdo->prepare("INSERT IGNORE INTO work_record_team (work_record_id, user_id) VALUES (?, ?)")
                    ->execute([$newRecordId, $newTeamUserId]);
            }

            // ---------------- Custom Fields (field_key ने आधीच असेल तर वगळतो) + Values ----------------
            $existingCfStmt = $pdo->prepare("SELECT field_key FROM custom_fields WHERE organization_id = ?");
            $existingCfStmt->execute([$orgId]);
            $existingCfKeys = array_flip(array_column($existingCfStmt->fetchAll(), 'field_key'));

            foreach ($data['custom_fields'] ?? [] as $cf) {
                if (isset($existingCfKeys[$cf['field_key']])) { continue; }
                $ins = $pdo->prepare("INSERT INTO custom_fields (organization_id, field_key, field_label, field_type, field_options, is_required, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $cf['field_key'], $cf['field_label'], $cf['field_type'], $cf['field_options'], $cf['is_required'], $cf['sort_order'], $cf['is_active']]);
                $existingCfKeys[$cf['field_key']] = true;
            }
            $existingCfvStmt = $pdo->prepare("
                SELECT cfv.work_record_id, cfv.field_key FROM custom_field_values cfv
                JOIN work_records wr ON wr.id = cfv.work_record_id WHERE wr.organization_id = ?
            ");
            $existingCfvStmt->execute([$orgId]);
            $existingCfvKeys = [];
            foreach ($existingCfvStmt->fetchAll() as $ecfv) { $existingCfvKeys[$ecfv['work_record_id'] . '|' . $ecfv['field_key']] = true; }

            foreach ($data['custom_field_values'] ?? [] as $cfv) {
                $newWrId = $workRecordMap[$cfv['work_record_id']] ?? null;
                if (!$newWrId) continue;
                $cfvKey = $newWrId . '|' . $cfv['field_key'];
                if (isset($existingCfvKeys[$cfvKey])) { continue; }
                $pdo->prepare("INSERT INTO custom_field_values (work_record_id, field_key, field_value) VALUES (?, ?, ?)")
                    ->execute([$newWrId, $cfv['field_key'], $cfv['field_value']]);
                $existingCfvKeys[$cfvKey] = true;
            }

            // ---------------- Renewal Items (विभाग+नाव+मुदत जुळल्यास Duplicate मानतो) ----------------
            $existingRenewalStmt = $pdo->prepare("SELECT id, department_id, item_name, expiry_date FROM renewal_items WHERE organization_id = ?");
            $existingRenewalStmt->execute([$orgId]);
            $existingRenewalKeys = [];
            foreach ($existingRenewalStmt->fetchAll() as $er) { $existingRenewalKeys[$er['department_id'] . '|' . mb_strtolower(trim($er['item_name'])) . '|' . $er['expiry_date']] = $er['id']; }

            $renewalItemMap = [];
            foreach ($data['renewal_items'] ?? [] as $r) {
                $newDeptId = $deptMap[$r['department_id']] ?? null;
                if (!$newDeptId) continue;
                $renKey = $newDeptId . '|' . mb_strtolower(trim($r['item_name'])) . '|' . $r['expiry_date'];
                if (isset($existingRenewalKeys[$renKey])) { $renewalItemMap[$r['id']] = $existingRenewalKeys[$renKey]; continue; }
                $newCampusId = $campusMap[$r['campus_id']] ?? reset($campusMap);
                $newResponsibleUserId = !empty($r['responsible_user_id']) ? ($userMap[$r['responsible_user_id']] ?? null) : null;
                $ins = $pdo->prepare("INSERT INTO renewal_items (organization_id, campus_id, department_id, responsible_user_id, item_type, regulatory_body, item_name, reference_no, issued_date, expiry_date, renewal_cost, is_recurring_monthly, monthly_amount, recurring_start_month, vendor_name, vendor_contact, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newCampusId, $newDeptId, $newResponsibleUserId, $r['item_type'], $r['regulatory_body'] ?? null, $r['item_name'], $r['reference_no'], $r['issued_date'], $r['expiry_date'], $r['renewal_cost'], $r['is_recurring_monthly'] ?? 0, $r['monthly_amount'] ?? null, $r['recurring_start_month'] ?? null, $r['vendor_name'], $r['vendor_contact'] ?? null, $r['status']]);
                $renewalItemMap[$r['id']] = $pdo->lastInsertId();
                $existingRenewalKeys[$renKey] = true;
                $counts['renewal_items']++;
            }
            foreach ($data['renewal_history'] ?? [] as $rh) {
                $newRenewalItemId = $renewalItemMap[$rh['renewal_item_id']] ?? null;
                if (!$newRenewalItemId) continue;
                try {
                    $pdo->prepare("
                        INSERT INTO renewal_history (renewal_item_id, old_expiry_date, old_issued_date, old_renewal_cost, old_vendor_name, old_reference_no, renewed_by, renewed_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newRenewalItemId, $rh['old_expiry_date'], $rh['old_issued_date'], $rh['old_renewal_cost'], $rh['old_vendor_name'], $rh['old_reference_no'], $rh['renewed_by'] ? ($userMap[$rh['renewed_by']] ?? null) : null, $rh['renewed_at']]);
                } catch (PDOException $e) { /* renewal_history table not migrated yet */ }
            }

            // ---------------- Tasks (विभाग+नेमलेली व्यक्ती+शीर्षक+मुदत जुळल्यास Duplicate मानतो) ----------------
            $existingTaskStmt = $pdo->prepare("SELECT id, department_id, assigned_to, title, target_date FROM tasks WHERE organization_id = ?");
            $existingTaskStmt->execute([$orgId]);
            $existingTaskKeys = [];
            foreach ($existingTaskStmt->fetchAll() as $et) { $existingTaskKeys[$et['department_id'] . '|' . $et['assigned_to'] . '|' . mb_strtolower(trim($et['title'])) . '|' . $et['target_date']] = true; }

            foreach ($data['tasks'] ?? [] as $t) {
                $newDeptId = $deptMap[$t['department_id']] ?? null;
                $newAssignedTo = $userMap[$t['assigned_to']] ?? null;
                $newAssignedBy = $userMap[$t['assigned_by']] ?? null;
                if (!$newDeptId || !$newAssignedTo || !$newAssignedBy) continue;
                $taskKey = $newDeptId . '|' . $newAssignedTo . '|' . mb_strtolower(trim($t['title'])) . '|' . $t['target_date'];
                if (isset($existingTaskKeys[$taskKey])) { continue; }
                $newCampusId = $campusMap[$t['campus_id']] ?? reset($campusMap);
                $taskCode = $t['task_code'] . '-R' . substr(uniqid(), -4);
                $ins = $pdo->prepare("INSERT INTO tasks (task_code, organization_id, campus_id, department_id, assigned_by, assigned_to, title, description, priority, target_date, status, completed_at, completion_remarks, cost_incurred) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$taskCode, $orgId, $newCampusId, $newDeptId, $newAssignedBy, $newAssignedTo, $t['title'], $t['description'], $t['priority'], $t['target_date'], $t['status'], $t['completed_at'], $t['completion_remarks'], $t['cost_incurred'] ?? null]);
                $existingTaskKeys[$taskKey] = true;
                $counts['tasks']++;
            }

            // ---------------- Hostels (नावाने आधीच असेल तर तेच वापरा) + Staff + Students ----------------
            $existingHostelStmt = $pdo->prepare("SELECT id, name FROM hostels WHERE organization_id = ?");
            $existingHostelStmt->execute([$orgId]);
            $existingHostelByName = [];
            foreach ($existingHostelStmt->fetchAll() as $eh) { $existingHostelByName[mb_strtolower(trim($eh['name']))] = $eh['id']; }

            foreach ($data['hostels'] ?? [] as $h) {
                $newDeptId = $deptMap[$h['department_id']] ?? null;
                $newCampusId = $campusMap[$h['campus_id']] ?? null;
                if (!$newDeptId || !$newCampusId) continue;
                $hNameKey = mb_strtolower(trim($h['name']));
                if (isset($existingHostelByName[$hNameKey])) {
                    $hostelMap[$h['id']] = $existingHostelByName[$hNameKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO hostels (organization_id, campus_id, department_id, name, capacity, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newCampusId, $newDeptId, $h['name'], $h['capacity'], $h['is_active']]);
                $hostelMap[$h['id']] = $pdo->lastInsertId();
                $existingHostelByName[$hNameKey] = $hostelMap[$h['id']];
                $counts['hostels']++;
            }
            foreach ($data['hostel_staff'] ?? [] as $hs) {
                $newHostelId = $hostelMap[$hs['hostel_id']] ?? null;
                $newUserId = $userMap[$hs['user_id']] ?? null;
                if (!$newHostelId || !$newUserId) continue;
                try { $pdo->prepare("INSERT INTO hostel_staff (hostel_id, user_id) VALUES (?, ?)")->execute([$newHostelId, $newUserId]); } catch (PDOException $e) {}
            }
            $existingStudentStmt = $pdo->prepare("
                SELECT hst.id, hst.hostel_id, hst.name, hst.admission_date FROM hostel_students hst
                JOIN hostels h ON h.id = hst.hostel_id WHERE h.organization_id = ?
            ");
            $existingStudentStmt->execute([$orgId]);
            $existingStudentKeys = [];
            $hostelStudentMap = [];
            foreach ($existingStudentStmt->fetchAll() as $es) { $existingStudentKeys[$es['hostel_id'] . '|' . mb_strtolower(trim($es['name'])) . '|' . $es['admission_date']] = $es['id']; }

            foreach ($data['hostel_students'] ?? [] as $hst) {
                $newHostelId = $hostelMap[$hst['hostel_id']] ?? null;
                if (!$newHostelId) continue;
                $stKey = $newHostelId . '|' . mb_strtolower(trim($hst['name'])) . '|' . $hst['admission_date'];
                if (isset($existingStudentKeys[$stKey])) {
                    $hostelStudentMap[$hst['id']] = $existingStudentKeys[$stKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO hostel_students (hostel_id, name, school_name, class_grade, district, gender, admission_date, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$newHostelId, $hst['name'], $hst['school_name'], $hst['class_grade'], $hst['district'], $hst['gender'], $hst['admission_date'], $hst['is_active']]);
                $newStudentId = $pdo->lastInsertId();
                $hostelStudentMap[$hst['id']] = $newStudentId;
                $existingStudentKeys[$stKey] = $newStudentId;
                $counts['hostel_students']++;
            }

            // ---------------- Hostel Rooms + वाटप ----------------
            try {
                $roomMap = [];
                foreach ($data['hostel_rooms'] ?? [] as $hr) {
                    $newHostelId2 = $hostelMap[$hr['hostel_id']] ?? null;
                    if (!$newHostelId2) continue;
                    $dupStmt = $pdo->prepare("SELECT id FROM hostel_rooms WHERE hostel_id = ? AND room_number = ?");
                    $dupStmt->execute([$newHostelId2, $hr['room_number']]);
                    $existing = $dupStmt->fetch();
                    if ($existing) { $roomMap[$hr['id']] = $existing['id']; continue; }
                    $ins = $pdo->prepare("INSERT INTO hostel_rooms (hostel_id, room_number, capacity, is_active, created_at) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$newHostelId2, $hr['room_number'], $hr['capacity'], $hr['is_active'], $hr['created_at']]);
                    $roomMap[$hr['id']] = $pdo->lastInsertId();
                }
                foreach ($data['hostel_room_allocations'] ?? [] as $hra) {
                    $newRoomId = $roomMap[$hra['room_id']] ?? null;
                    $newStudId4 = $hostelStudentMap[$hra['student_id']] ?? null;
                    if (!$newRoomId || !$newStudId4) continue;
                    $pdo->prepare("INSERT INTO hostel_room_allocations (room_id, student_id, bed_label, allocated_date, vacated_date, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newRoomId, $newStudId4, $hra['bed_label'], $hra['allocated_date'], $hra['vacated_date'], $hra['status'], $hra['created_by'] ? ($userMap[$hra['created_by']] ?? null) : null, $hra['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- वसतिगृह उपस्थिती (Hostel Student Attendance) ----------------
            try {
                foreach ($data['hostel_student_attendance'] ?? [] as $hsa) {
                    $newHostelId = $hostelMap[$hsa['hostel_id']] ?? null;
                    $newStudIdHsa = $hostelStudentMap[$hsa['student_id']] ?? null;
                    if (!$newHostelId || !$newStudIdHsa) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO hostel_student_attendance (hostel_id, student_id, attendance_date, session, status, remark, marked_by, marked_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newHostelId, $newStudIdHsa, $hsa['attendance_date'], $hsa['session'], $hsa['status'], $hsa['remark'], $hsa['marked_by'] ? ($userMap[$hsa['marked_by']] ?? null) : null, $hsa['marked_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Hostel Fee + भरणा ----------------
            try {
                $feeMap = [];
                foreach ($data['hostel_fees'] ?? [] as $hf) {
                    $newStudId5 = $hostelStudentMap[$hf['student_id']] ?? null;
                    if (!$newStudId5) continue;
                    $ins = $pdo->prepare("INSERT INTO hostel_fees (student_id, fee_period, amount_due, due_date, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newStudId5, $hf['fee_period'], $hf['amount_due'], $hf['due_date'], $hf['status'], $hf['created_by'] ? ($userMap[$hf['created_by']] ?? null) : null, $hf['created_at']]);
                    $feeMap[$hf['id']] = $pdo->lastInsertId();
                }
                foreach ($data['hostel_fee_payments'] ?? [] as $hfp) {
                    $newFeeId = $feeMap[$hfp['fee_id']] ?? null;
                    if (!$newFeeId) continue;
                    $pdo->prepare("INSERT INTO hostel_fee_payments (fee_id, amount_paid, payment_mode, paid_date, received_by, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newFeeId, $hfp['amount_paid'], $hfp['payment_mode'], $hfp['paid_date'], $hfp['received_by'] ? ($userMap[$hfp['received_by']] ?? null) : null, $hfp['remarks'], $hfp['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Hostel आरोग्य ----------------
            try {
                foreach ($data['hostel_student_health'] ?? [] as $hh) {
                    $newStudId6 = $hostelStudentMap[$hh['student_id']] ?? null;
                    if (!$newStudId6) continue;
                    $pdo->prepare("INSERT IGNORE INTO hostel_student_health (student_id, blood_group, allergies, regular_medication, emergency_contact_name, emergency_contact_phone, medical_notes, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudId6, $hh['blood_group'], $hh['allergies'], $hh['regular_medication'], $hh['emergency_contact_name'], $hh['emergency_contact_phone'], $hh['medical_notes'], $hh['updated_at']]);
                }
                foreach ($data['hostel_health_incidents'] ?? [] as $hi) {
                    $newStudId7 = $hostelStudentMap[$hi['student_id']] ?? null;
                    if (!$newStudId7) continue;
                    $pdo->prepare("INSERT INTO hostel_health_incidents (student_id, incident_date, description, action_taken, reported_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudId7, $hi['incident_date'], $hi['description'], $hi['action_taken'], $hi['reported_by'] ? ($userMap[$hi['reported_by']] ?? null) : null, $hi['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- सुट्टी-नोंद + Alumni ----------------
            try {
                foreach ($data['hostel_leave_records'] ?? [] as $hl) {
                    $newStudId8 = $hostelStudentMap[$hl['student_id']] ?? null;
                    if (!$newStudId8) continue;
                    $pdo->prepare("INSERT INTO hostel_leave_records (student_id, leave_date, expected_return_date, actual_return_date, reason, approved_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudId8, $hl['leave_date'], $hl['expected_return_date'], $hl['actual_return_date'], $hl['reason'], $hl['approved_by'] ? ($userMap[$hl['approved_by']] ?? null) : null, $hl['status'], $hl['created_at']]);
                }
                foreach ($data['hostel_alumni'] ?? [] as $hal) {
                    $newStudId9 = $hostelStudentMap[$hal['student_id']] ?? null;
                    if (!$newStudId9) continue;
                    $pdo->prepare("INSERT IGNORE INTO hostel_alumni (student_id, departure_date, departure_reason, current_status, contact_number, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudId9, $hal['departure_date'], $hal['departure_reason'], $hal['current_status'], $hal['contact_number'], $hal['notes'], $hal['created_by'] ? ($userMap[$hal['created_by']] ?? null) : null, $hal['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Software Suggestions ----------------
            try {
                $suggestionMap = [];
                foreach ($data['software_suggestions'] ?? [] as $ss) {
                    $newSubmittedBy = $userMap[$ss['submitted_by']] ?? null;
                    if (!$newSubmittedBy) continue;
                    $newRespondedBy = !empty($ss['responded_by']) ? ($userMap[$ss['responded_by']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO software_suggestions (organization_id, title, description, category, module_name, status, admin_response, responded_by, responded_at, submitted_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $ss['title'], $ss['description'], $ss['category'], $ss['module_name'], $ss['status'], $ss['admin_response'], $newRespondedBy, $ss['responded_at'], $newSubmittedBy, $ss['created_at']]);
                    $suggestionMap[$ss['id']] = $pdo->lastInsertId();
                }
                foreach ($data['software_suggestion_votes'] ?? [] as $ssv) {
                    $newSugId = $suggestionMap[$ssv['suggestion_id']] ?? null;
                    $newVoterId = $userMap[$ssv['user_id']] ?? null;
                    if (!$newSugId || !$newVoterId) continue;
                    $pdo->prepare("INSERT IGNORE INTO software_suggestion_votes (suggestion_id, user_id, created_at) VALUES (?, ?, ?)")
                        ->execute([$newSugId, $newVoterId, $ssv['created_at']]);
                }
                foreach ($data['software_suggestion_comments'] ?? [] as $ssc) {
                    $newSugId2 = $suggestionMap[$ssc['suggestion_id']] ?? null;
                    $newCommenterId = $userMap[$ssc['commented_by']] ?? null;
                    if (!$newSugId2 || !$newCommenterId) continue;
                    $pdo->prepare("INSERT INTO software_suggestion_comments (suggestion_id, comment, commented_by, created_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newSugId2, $ssc['comment'], $newCommenterId, $ssc['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Work Requests (विभाग+विनंतीकर्ता+वर्णन जुळल्यास Duplicate मानतो) ----------------
            $existingWorkReqStmt = $pdo->prepare("SELECT id, department_id, requester_name, work_description FROM work_requests WHERE organization_id = ?");
            $existingWorkReqStmt->execute([$orgId]);
            $existingWorkReqKeys = [];
            foreach ($existingWorkReqStmt->fetchAll() as $ewrq) { $existingWorkReqKeys[$ewrq['department_id'] . '|' . mb_strtolower(trim($ewrq['requester_name'] ?? '')) . '|' . mb_strtolower(trim($ewrq['work_description']))] = $ewrq['id']; }

            $workRequestMap = [];
            foreach ($data['work_requests'] ?? [] as $wr) {
                $newDeptId = $deptMap[$wr['department_id']] ?? null;
                if (!$newDeptId) continue;
                $wrqKey = $newDeptId . '|' . mb_strtolower(trim($wr['requester_name'] ?? '')) . '|' . mb_strtolower(trim($wr['work_description']));
                if (isset($existingWorkReqKeys[$wrqKey])) { $workRequestMap[$wr['id']] = $existingWorkReqKeys[$wrqKey]; continue; }
                $reqCode = $wr['request_code'] . '-R' . substr(uniqid(), -4);
                $newCompletedBy = $wr['completed_by'] ? ($userMap[$wr['completed_by']] ?? null) : null;
                $newRequestedByUserId = !empty($wr['requested_by_user_id']) ? ($userMap[$wr['requested_by_user_id']] ?? null) : null;
                $newRequestingDeptId = !empty($wr['requesting_department_id']) ? ($deptMap[$wr['requesting_department_id']] ?? null) : null;
                $newAssignedToUserId = !empty($wr['assigned_to_user_id']) ? ($userMap[$wr['assigned_to_user_id']] ?? null) : null;
                $ins = $pdo->prepare("INSERT INTO work_requests (request_code, organization_id, department_id, requester_name, requester_contact, requested_by_user_id, requesting_department_id, assigned_to_user_id, work_description, cost_incurred, completion_remarks, status, completed_by, completed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$reqCode, $orgId, $newDeptId, $wr['requester_name'], $wr['requester_contact'], $newRequestedByUserId, $newRequestingDeptId, $newAssignedToUserId, $wr['work_description'], $wr['cost_incurred'] ?? null, $wr['completion_remarks'] ?? null, $wr['status'], $newCompletedBy, $wr['completed_at']]);
                $newWorkReqId = $pdo->lastInsertId();
                $workRequestMap[$wr['id']] = $newWorkReqId;
                $existingWorkReqKeys[$wrqKey] = $newWorkReqId;
                $counts['work_requests']++;
            }

            // ---------------- कामाची विनंती — Action History ----------------
            try {
                foreach ($data['work_request_actions'] ?? [] as $wra) {
                    $newRequestId = $workRequestMap[$wra['request_id']] ?? null;
                    if (!$newRequestId) continue;
                    $pdo->prepare("INSERT INTO work_request_actions (request_id, action_type, old_status, new_status, remark, changed_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newRequestId, $wra['action_type'], $wra['old_status'], $wra['new_status'], $wra['remark'], $wra['changed_by'] ? ($userMap[$wra['changed_by']] ?? null) : null, $wra['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Vehicles + Trips/Fuel/Maintenance (वाहन-क्रमांकाने आधीच असेल तर तेच वापरा) ----------------
            $vehicleMap = [];
            $counts['vehicles'] = 0;
            $existingVehStmt = $pdo->prepare("SELECT id, vehicle_number FROM vehicles WHERE organization_id = ?");
            $existingVehStmt->execute([$orgId]);
            $existingVehByNumber = [];
            foreach ($existingVehStmt->fetchAll() as $ev) { $existingVehByNumber[mb_strtoupper(trim($ev['vehicle_number']))] = $ev['id']; }

            foreach ($data['vehicles'] ?? [] as $v) {
                $newDeptId = $deptMap[$v['department_id']] ?? null;
                if (!$newDeptId) continue;
                $vehKey = mb_strtoupper(trim($v['vehicle_number']));
                if (isset($existingVehByNumber[$vehKey])) {
                    $vehicleMap[$v['id']] = $existingVehByNumber[$vehKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO vehicles (organization_id, campus_id, department_id, vehicle_number, vehicle_type, driver_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $newVehCampusId = $v['campus_id'] ? ($campusMap[$v['campus_id']] ?? reset($campusMap)) : null;
                $ins->execute([$orgId, $newVehCampusId, $newDeptId, $v['vehicle_number'], $v['vehicle_type'], $v['driver_id'] ? ($userMap[$v['driver_id']] ?? null) : null, $v['is_active']]);
                $existingVehByNumber[$vehKey] = $pdo->lastInsertId();
                $vehicleMap[$v['id']] = $pdo->lastInsertId();
                $counts['vehicles']++;
            }
            foreach ($data['vehicle_trips'] ?? [] as $vt) {
                $newVehId = $vehicleMap[$vt['vehicle_id']] ?? null;
                if (!$newVehId) continue;
                $ins = $pdo->prepare("INSERT INTO vehicle_trips (vehicle_id, trip_date, from_location, to_location, start_time, end_time, start_odometer, end_odometer, distance_km, purpose, driver_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$newVehId, $vt['trip_date'], $vt['from_location'], $vt['to_location'], $vt['start_time'], $vt['end_time'], $vt['start_odometer'], $vt['end_odometer'], $vt['distance_km'], $vt['purpose'], $vt['driver_id'] ? ($userMap[$vt['driver_id']] ?? null) : null]);
            }
            foreach ($data['vehicle_fuel_log'] ?? [] as $vf) {
                $newVehId = $vehicleMap[$vf['vehicle_id']] ?? null;
                if (!$newVehId) continue;
                $ins = $pdo->prepare("INSERT INTO vehicle_fuel_log (vehicle_id, fill_date, odometer_reading, liters, cost, fuel_station, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$newVehId, $vf['fill_date'], $vf['odometer_reading'], $vf['liters'], $vf['cost'], $vf['fuel_station'], $vf['created_by'] ? ($userMap[$vf['created_by']] ?? null) : null]);
            }
            foreach ($data['vehicle_maintenance'] ?? [] as $vm) {
                $newVehId = $vehicleMap[$vm['vehicle_id']] ?? null;
                if (!$newVehId) continue;
                $ins = $pdo->prepare("INSERT INTO vehicle_maintenance (vehicle_id, maintenance_date, type, description, cost, odometer_reading, next_due_date, vendor, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$newVehId, $vm['maintenance_date'], $vm['type'], $vm['description'], $vm['cost'], $vm['odometer_reading'], $vm['next_due_date'], $vm['vendor'], $vm['created_by'] ? ($userMap[$vm['created_by']] ?? null) : null]);
            }

            // ---------------- Buildings (नावाने) + Maintenance Locations (आधी लागतं — देखभाल/रहिवासीसाठी) ----------------
            $buildingMap = [];
            $locationMap = [];
            $existingBuildingStmt = $pdo->prepare("SELECT id, campus_id, name FROM buildings WHERE organization_id = ?");
            $existingBuildingStmt->execute([$orgId]);
            $existingBuildingKeys = [];
            foreach ($existingBuildingStmt->fetchAll() as $eb) { $existingBuildingKeys[$eb['campus_id'] . '|' . mb_strtolower(trim($eb['name']))] = $eb['id']; }

            foreach ($data['buildings'] ?? [] as $b) {
                $newBCampusId = $campusMap[$b['campus_id']] ?? reset($campusMap);
                $bKey = $newBCampusId . '|' . mb_strtolower(trim($b['name']));
                if (isset($existingBuildingKeys[$bKey])) {
                    $buildingMap[$b['id']] = $existingBuildingKeys[$bKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO buildings (organization_id, campus_id, name, created_by) VALUES (?, ?, ?, ?)");
                $ins->execute([$orgId, $newBCampusId, $b['name'], $userMap[$b['created_by']] ?? null]);
                $buildingMap[$b['id']] = $pdo->lastInsertId();
                $existingBuildingKeys[$bKey] = $buildingMap[$b['id']];
            }

            $existingLocStmt = $pdo->prepare("SELECT id, building_id, name FROM maintenance_locations WHERE organization_id = ?");
            $existingLocStmt->execute([$orgId]);
            $existingLocKeys = [];
            foreach ($existingLocStmt->fetchAll() as $el) { $existingLocKeys[$el['building_id'] . '|' . mb_strtolower(trim($el['name']))] = $el['id']; }

            foreach ($data['maintenance_locations'] ?? [] as $ml) {
                $newBuildingId = $ml['building_id'] ? ($buildingMap[$ml['building_id']] ?? null) : null;
                $mlKey = $newBuildingId . '|' . mb_strtolower(trim($ml['name']));
                if (isset($existingLocKeys[$mlKey])) {
                    $locationMap[$ml['id']] = $existingLocKeys[$mlKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO maintenance_locations (organization_id, campus_id, building_id, floor, name, location_code, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $code = $ml['location_code'] . '-R' . substr(uniqid(), -4);
                $ins->execute([$orgId, $campusMap[$ml['campus_id']] ?? reset($campusMap), $newBuildingId, $ml['floor'] ?? 0, $ml['name'], $code, $ml['is_active'], $userMap[$ml['created_by']] ?? null]);
                $locationMap[$ml['id']] = $pdo->lastInsertId();
                $existingLocKeys[$mlKey] = $locationMap[$ml['id']];
            }

            $existingMlgStmt = $pdo->prepare("SELECT id, location_id, work_date, item_description FROM maintenance_logs WHERE organization_id = ?");
            $existingMlgStmt->execute([$orgId]);
            $existingMlgKeys = [];
            foreach ($existingMlgStmt->fetchAll() as $emlg) { $existingMlgKeys[$emlg['location_id'] . '|' . $emlg['work_date'] . '|' . mb_strtolower(trim($emlg['item_description']))] = $emlg['id']; }

            $maintenanceLogMap = [];
            foreach ($data['maintenance_logs'] ?? [] as $mlg) {
                $newLocId = $locationMap[$mlg['location_id']] ?? null;
                $newDeptId = $deptMap[$mlg['department_id']] ?? null;
                if (!$newLocId || !$newDeptId) continue;
                $mlgKey = $newLocId . '|' . $mlg['work_date'] . '|' . mb_strtolower(trim($mlg['item_description']));
                if (isset($existingMlgKeys[$mlgKey])) { $maintenanceLogMap[$mlg['id']] = $existingMlgKeys[$mlgKey]; continue; }
                $ins = $pdo->prepare("INSERT INTO maintenance_logs (organization_id, location_id, department_id, item_description, work_type, work_date, cost, remarks, performed_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newLocId, $newDeptId, $mlg['item_description'], $mlg['work_type'], $mlg['work_date'], $mlg['cost'], $mlg['remarks'], $mlg['performed_by'] ? ($userMap[$mlg['performed_by']] ?? null) : null]);
                $newMlgId = $pdo->lastInsertId();
                $maintenanceLogMap[$mlg['id']] = $newMlgId;
                $existingMlgKeys[$mlgKey] = $newMlgId;
            }

            $existingResStmt = $pdo->prepare("SELECT id, location_id, name FROM residency_occupants WHERE organization_id = ?");
            $existingResStmt->execute([$orgId]);
            $existingResKeys = [];
            $occupantMap = [];
            foreach ($existingResStmt->fetchAll() as $eres) { $existingResKeys[$eres['location_id'] . '|' . mb_strtolower(trim($eres['name']))] = $eres['id']; }

            foreach ($data['residency_occupants'] ?? [] as $ro) {
                $newLocId = $locationMap[$ro['location_id']] ?? null;
                if (!$newLocId) continue;
                $resKey = $newLocId . '|' . mb_strtolower(trim($ro['name']));
                if (isset($existingResKeys[$resKey])) {
                    $occupantMap[$ro['id']] = $existingResKeys[$resKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, occupant_type, user_id, name, relation, age, moved_in_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newLocId, $ro['occupant_type'], $ro['user_id'] ? ($userMap[$ro['user_id']] ?? null) : null, $ro['name'], $ro['relation'], $ro['age'], $ro['moved_in_date'], $ro['status'], $userMap[$ro['created_by']] ?? null]);
                $newOccId = $pdo->lastInsertId();
                $occupantMap[$ro['id']] = $newOccId;
                $existingResKeys[$resKey] = $newOccId;
            }

            // ---------------- खोलीतलं Sामान (Residency Room Items) ----------------
            try {
                foreach ($data['residency_room_items'] ?? [] as $rri) {
                    $newLocIdRri = $locationMap[$rri['location_id']] ?? null;
                    if (!$newLocIdRri) continue;
                    $pdo->prepare("INSERT INTO residency_room_items (organization_id, location_id, item_name, ownership, quantity, remarks, added_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newLocIdRri, $rri['item_name'], $rri['ownership'], $rri['quantity'], $rri['remarks'], $rri['added_by'] ? ($userMap[$rri['added_by']] ?? null) : null, $rri['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- निवास-बिल (Rent/Electricity) ----------------
            try {
                $billMap = [];
                $existingBillStmt = $pdo->prepare("SELECT id, location_id, billing_month FROM residency_bills WHERE organization_id = ?");
                $existingBillStmt->execute([$orgId]);
                $existingBillKeys = [];
                foreach ($existingBillStmt->fetchAll() as $eb) { $existingBillKeys[$eb['location_id'] . '|' . $eb['billing_month']] = $eb['id']; }

                foreach ($data['residency_bills'] ?? [] as $rb) {
                    $newLocId = $locationMap[$rb['location_id']] ?? null;
                    if (!$newLocId) continue;
                    $billKey = $newLocId . '|' . $rb['billing_month'];
                    if (isset($existingBillKeys[$billKey])) { $billMap[$rb['id']] = $existingBillKeys[$billKey]; continue; }
                    $ins = $pdo->prepare("INSERT INTO residency_bills (organization_id, location_id, billing_month, room_rent, electricity_bill, water_bill, total_amount, billing_unit_count, per_person_amount, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newLocId, $rb['billing_month'], $rb['room_rent'], $rb['electricity_bill'], $rb['water_bill'], $rb['total_amount'], $rb['billing_unit_count'], $rb['per_person_amount'], $rb['created_by'] ? ($userMap[$rb['created_by']] ?? null) : null]);
                    $newBillId = $pdo->lastInsertId();
                    $billMap[$rb['id']] = $newBillId;
                    $existingBillKeys[$billKey] = $newBillId;
                }
                foreach ($data['residency_bill_shares'] ?? [] as $rbs) {
                    $newBillId = $billMap[$rbs['bill_id']] ?? null;
                    $newOccId = $occupantMap[$rbs['occupant_id']] ?? null;
                    if (!$newBillId || !$newOccId) continue;
                    $dupStmt = $pdo->prepare("SELECT id FROM residency_bill_shares WHERE bill_id = ? AND occupant_id = ?");
                    $dupStmt->execute([$newBillId, $newOccId]);
                    if ($dupStmt->fetch()) continue;
                    $pdo->prepare("INSERT INTO residency_bill_shares (bill_id, occupant_id, amount_due, status, paid_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newBillId, $newOccId, $rbs['amount_due'], $rbs['status'], $rbs['paid_at']]);
                }
            } catch (PDOException $e) { /* residency billing tables not migrated yet */ }

            // ---------------- निवास वाटप-विनंती ----------------
            try {
                foreach ($data['residency_allotment_requests'] ?? [] as $rar) {
                    $newRequestedBy = $userMap[$rar['requested_by']] ?? null;
                    if (!$newRequestedBy) continue;
                    $newBuildingId = !empty($rar['preferred_building_id']) ? ($buildingMap[$rar['preferred_building_id']] ?? null) : null;
                    $newAllottedLoc = !empty($rar['allotted_location_id']) ? ($locationMap[$rar['allotted_location_id']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO residency_allotment_requests (organization_id, requested_by, preferred_building_id, family_size, reason, status, allotted_location_id, approved_by, approved_at, remarks) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newRequestedBy, $newBuildingId, $rar['family_size'], $rar['reason'], $rar['status'], $newAllottedLoc, $rar['approved_by'] ? ($userMap[$rar['approved_by']] ?? null) : null, $rar['approved_at'], $rar['remarks']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- खोली-तपासणी अहवाल ----------------
            try {
                foreach ($data['residency_inspections'] ?? [] as $ri) {
                    $newLocId = $locationMap[$ri['location_id']] ?? null;
                    $newInspectedBy = $userMap[$ri['inspected_by']] ?? null;
                    if (!$newLocId || !$newInspectedBy) continue;
                    $pdo->prepare("INSERT INTO residency_inspections (organization_id, location_id, inspection_date, condition_rating, remarks, inspected_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newLocId, $ri['inspection_date'], $ri['condition_rating'], $ri['remarks'], $newInspectedBy]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- खोली-बदल इतिहास ----------------
            try {
                foreach ($data['residency_room_transfers'] ?? [] as $rrt) {
                    $newOccId = $occupantMap[$rrt['occupant_id']] ?? null;
                    $newToLoc = $locationMap[$rrt['to_location_id']] ?? null;
                    if (!$newOccId || !$newToLoc) continue;
                    $newFromLoc = !empty($rrt['from_location_id']) ? ($locationMap[$rrt['from_location_id']] ?? null) : null;
                    $pdo->prepare("INSERT INTO residency_room_transfers (occupant_id, from_location_id, to_location_id, transfer_date, reason, transferred_by) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newOccId, $newFromLoc, $newToLoc, $rrt['transfer_date'], $rrt['reason'], $rrt['transferred_by'] ? ($userMap[$rrt['transferred_by']] ?? null) : null]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- पाहुणे-नोंदणी ----------------
            try {
                foreach ($data['residency_guests'] ?? [] as $rg) {
                    $newLocId = $locationMap[$rg['location_id']] ?? null;
                    if (!$newLocId) continue;
                    $newHostId = !empty($rg['host_occupant_id']) ? ($occupantMap[$rg['host_occupant_id']] ?? null) : null;
                    $pdo->prepare("INSERT INTO residency_guests (organization_id, location_id, host_occupant_id, guest_name, guest_contact, relation, purpose, arrival_date, expected_departure_date, actual_departure_date, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newLocId, $newHostId, $rg['guest_name'], $rg['guest_contact'], $rg['relation'], $rg['purpose'], $rg['arrival_date'], $rg['expected_departure_date'], $rg['actual_departure_date'], $rg['status'], $rg['created_by'] ? ($userMap[$rg['created_by']] ?? null) : null]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- परिपत्रक/सूचना (Announcements) ----------------
            try {
                $announcementMap = [];
                foreach ($data['announcements'] ?? [] as $an) {
                    $newCreatedBy = $userMap[$an['created_by']] ?? null;
                    if (!$newCreatedBy) continue;
                    $ins = $pdo->prepare("INSERT INTO announcements (organization_id, title, content, priority, target_scope, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $an['title'], $an['content'], $an['priority'], $an['target_scope'], $newCreatedBy, $an['created_at']]);
                    $announcementMap[$an['id']] = $pdo->lastInsertId();
                }
                foreach ($data['announcement_departments'] ?? [] as $ad) {
                    $newAnnId = $announcementMap[$ad['announcement_id']] ?? null;
                    $newDeptId2 = $deptMap[$ad['department_id']] ?? null;
                    if (!$newAnnId || !$newDeptId2) continue;
                    $pdo->prepare("INSERT INTO announcement_departments (announcement_id, department_id) VALUES (?, ?)")->execute([$newAnnId, $newDeptId2]);
                }
                foreach ($data['announcement_reads'] ?? [] as $ar) {
                    $newAnnId = $announcementMap[$ar['announcement_id']] ?? null;
                    $newUserId = $userMap[$ar['user_id']] ?? null;
                    if (!$newAnnId || !$newUserId) continue;
                    $pdo->prepare("INSERT IGNORE INTO announcement_reads (announcement_id, user_id, read_at) VALUES (?, ?, ?)")->execute([$newAnnId, $newUserId, $ar['read_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- दस्तऐवज Repository ----------------
            try {
                foreach ($data['documents'] ?? [] as $doc) {
                    $newUploadedBy = $userMap[$doc['uploaded_by']] ?? null;
                    if (!$newUploadedBy) continue;
                    $newDeptId3 = !empty($doc['department_id']) ? ($deptMap[$doc['department_id']] ?? null) : null;
                    $pdo->prepare("INSERT INTO documents (organization_id, title, description, category, file_path, original_filename, department_id, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $doc['title'], $doc['description'], $doc['category'], $doc['file_path'], $doc['original_filename'], $newDeptId3, $newUploadedBy, $doc['uploaded_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- आणीबाणी सूचना ----------------
            try {
                foreach ($data['emergency_alerts'] ?? [] as $ea) {
                    $newTriggeredBy = $userMap[$ea['triggered_by']] ?? null;
                    if (!$newTriggeredBy) continue;
                    $pdo->prepare("INSERT INTO emergency_alerts (organization_id, triggered_by, alert_type, location, message, status, resolved_by, resolved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newTriggeredBy, $ea['alert_type'], $ea['location'], $ea['message'], $ea['status'], $ea['resolved_by'] ? ($userMap[$ea['resolved_by']] ?? null) : null, $ea['resolved_at'], $ea['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- संगीत विभाग (Music Department) ----------------
            try {
                $instrumentMap = [];
                foreach ($data['music_instruments'] ?? [] as $mi) {
                    $newDeptId5 = !empty($mi['department_id']) ? ($deptMap[$mi['department_id']] ?? null) : null;
                    $newHolderId = !empty($mi['current_holder_id']) ? ($userMap[$mi['current_holder_id']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO music_instruments (organization_id, department_id, instrument_name, instrument_type, brand_model, purchase_date, purchase_cost, condition_status, last_tuned_date, current_holder_id, remarks, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newDeptId5, $mi['instrument_name'], $mi['instrument_type'], $mi['brand_model'], $mi['purchase_date'], $mi['purchase_cost'], $mi['condition_status'], $mi['last_tuned_date'], $newHolderId, $mi['remarks'], $mi['is_active'], $mi['created_by'] ? ($userMap[$mi['created_by']] ?? null) : null, $mi['created_at']]);
                    $instrumentMap[$mi['id']] = $pdo->lastInsertId();
                }
                foreach ($data['music_instrument_log'] ?? [] as $mil) {
                    $newInstId = $instrumentMap[$mil['instrument_id']] ?? null;
                    if (!$newInstId) continue;
                    $pdo->prepare("INSERT INTO music_instrument_log (instrument_id, action_type, holder_name, remarks, logged_by, logged_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newInstId, $mil['action_type'], $mil['holder_name'], $mil['remarks'], $mil['logged_by'] ? ($userMap[$mil['logged_by']] ?? null) : null, $mil['logged_at']]);
                }

                foreach ($data['music_room_bookings'] ?? [] as $mrb) {
                    $newLocId2 = $locationMap[$mrb['location_id']] ?? null;
                    $newBookedBy = $userMap[$mrb['booked_by']] ?? null;
                    if (!$newLocId2 || !$newBookedBy) continue;
                    $pdo->prepare("INSERT INTO music_room_bookings (organization_id, location_id, booked_by, booking_date, start_time, end_time, purpose, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newLocId2, $newBookedBy, $mrb['booking_date'], $mrb['start_time'], $mrb['end_time'], $mrb['purpose'], $mrb['status'], $mrb['created_at']]);
                }

                $musicStudentMap = [];
                foreach ($data['music_students'] ?? [] as $ms) {
                    $ins = $pdo->prepare("INSERT INTO music_students (organization_id, name, contact, guardian_name, admission_date, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $ms['name'], $ms['contact'], $ms['guardian_name'], $ms['admission_date'], $ms['is_active'], $ms['created_by'] ? ($userMap[$ms['created_by']] ?? null) : null, $ms['created_at']]);
                    $musicStudentMap[$ms['id']] = $pdo->lastInsertId();
                }
                $enrollmentMap = [];
                foreach ($data['music_enrollments'] ?? [] as $me) {
                    $newStudId = $musicStudentMap[$me['student_id']] ?? null;
                    if (!$newStudId) continue;
                    $newTeacherId = !empty($me['teacher_id']) ? ($userMap[$me['teacher_id']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO music_enrollments (student_id, subject, teacher_id, start_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newStudId, $me['subject'], $newTeacherId, $me['start_date'], $me['status'], $me['created_at']]);
                    $enrollmentMap[$me['id']] = $pdo->lastInsertId();
                }
                foreach ($data['music_attendance'] ?? [] as $ma) {
                    $newEnrollId = $enrollmentMap[$ma['enrollment_id']] ?? null;
                    if (!$newEnrollId) continue;
                    $pdo->prepare("INSERT IGNORE INTO music_attendance (enrollment_id, attendance_date, status, marked_by, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newEnrollId, $ma['attendance_date'], $ma['status'], $ma['marked_by'] ? ($userMap[$ma['marked_by']] ?? null) : null, $ma['created_at']]);
                }
                foreach ($data['music_exams'] ?? [] as $mex) {
                    $newStudId2 = $musicStudentMap[$mex['student_id']] ?? null;
                    if (!$newStudId2) continue;
                    $pdo->prepare("INSERT INTO music_exams (student_id, exam_board, grade_level, subject, exam_date, result, marks, certificate_path, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudId2, $mex['exam_board'], $mex['grade_level'], $mex['subject'], $mex['exam_date'], $mex['result'], $mex['marks'], $mex['certificate_path'], $mex['created_by'] ? ($userMap[$mex['created_by']] ?? null) : null, $mex['created_at']]);
                }

                $eventMap = [];
                foreach ($data['music_events'] ?? [] as $mev) {
                    $ins = $pdo->prepare("INSERT INTO music_events (organization_id, title, event_type, event_date, venue, description, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $mev['title'], $mev['event_type'], $mev['event_date'], $mev['venue'], $mev['description'], $mev['created_by'] ? ($userMap[$mev['created_by']] ?? null) : null, $mev['created_at']]);
                    $eventMap[$mev['id']] = $pdo->lastInsertId();
                }
                foreach ($data['music_event_participants'] ?? [] as $mep) {
                    $newEventId = $eventMap[$mep['event_id']] ?? null;
                    $newStudId3 = $musicStudentMap[$mep['student_id']] ?? null;
                    if (!$newEventId || !$newStudId3) continue;
                    $pdo->prepare("INSERT INTO music_event_participants (event_id, student_id, performance_item, result) VALUES (?, ?, ?, ?)")
                        ->execute([$newEventId, $newStudId3, $mep['performance_item'], $mep['result']]);
                }

                foreach ($data['music_sheet_library'] ?? [] as $msl) {
                    $newUploadedBy = $msl['uploaded_by'] ? ($userMap[$msl['uploaded_by']] ?? null) : null;
                    $pdo->prepare("INSERT INTO music_sheet_library (organization_id, title, raga_or_type, instrument, composer, file_path, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $msl['title'], $msl['raga_or_type'], $msl['instrument'], $msl['composer'], $msl['file_path'], $newUploadedBy, $msl['uploaded_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Extra Department Access (लवचिक Department-Visibility) ----------------
            try {
                foreach ($data['user_extra_department_access'] ?? [] as $uda) {
                    $newUserId2 = $userMap[$uda['user_id']] ?? null;
                    $newDeptId6 = $deptMap[$uda['department_id']] ?? null;
                    if (!$newUserId2 || !$newDeptId6) continue;
                    $pdo->prepare("INSERT IGNORE INTO user_extra_department_access (user_id, department_id, granted_by, granted_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newUserId2, $newDeptId6, $uda['granted_by'] ? ($userMap[$uda['granted_by']] ?? null) : null, $uda['granted_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Extra Campus Access (लवचिक Campus-Visibility) ----------------
            try {
                foreach ($data['user_extra_campus_access'] ?? [] as $uca) {
                    $newUserId3 = $userMap[$uca['user_id']] ?? null;
                    $newCampusId8 = $campusMap[$uca['campus_id']] ?? null;
                    if (!$newUserId3 || !$newCampusId8) continue;
                    $pdo->prepare("INSERT IGNORE INTO user_extra_campus_access (user_id, campus_id, granted_by, granted_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newUserId3, $newCampusId8, $uca['granted_by'] ? ($userMap[$uca['granted_by']] ?? null) : null, $uca['granted_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- ध्यान व सत्संग (Meditation/Satsang Module) ----------------
            try {
                $satsangEventMap = [];
                foreach ($data['satsang_events'] ?? [] as $se) {
                    $newCreatedBySe = !empty($se['created_by']) ? ($userMap[$se['created_by']] ?? null) : null;
                    $newCampusIdSe = !empty($se['campus_id']) ? ($campusMap[$se['campus_id']] ?? null) : null;
                    $newOrgDeptIdSe = !empty($se['organizing_department_id']) ? ($deptMap[$se['organizing_department_id']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO satsang_events (organization_id, campus_id, event_date, organizing_department_id, theme, notes, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newCampusIdSe, $se['event_date'], $newOrgDeptIdSe, $se['theme'], $se['notes'], $newCreatedBySe, $se['created_at']]);
                    $satsangEventMap[$se['id']] = $pdo->lastInsertId();
                }
                foreach ($data['satsang_duties'] ?? [] as $sd) {
                    $newSeId = $satsangEventMap[$sd['satsang_event_id']] ?? null;
                    $newAssignedTo = $userMap[$sd['assigned_to']] ?? null;
                    if (!$newSeId || !$newAssignedTo) continue;
                    $pdo->prepare("INSERT INTO satsang_duties (satsang_event_id, assigned_to, duty_description, status, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newSeId, $newAssignedTo, $sd['duty_description'], $sd['status'], $sd['created_at']]);
                }
                foreach ($data['satsang_attendance'] ?? [] as $sa) {
                    $newSeId2 = $satsangEventMap[$sa['satsang_event_id']] ?? null;
                    $newAttUserId = $userMap[$sa['user_id']] ?? null;
                    if (!$newSeId2 || !$newAttUserId) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO satsang_attendance (satsang_event_id, user_id, status, remark, marked_by, marked_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$newSeId2, $newAttUserId, $sa['status'], $sa['remark'], $sa['marked_by'] ? ($userMap[$sa['marked_by']] ?? null) : null, $sa['marked_at']]);
                }
                foreach ($data['meditation_quotes'] ?? [] as $mq) {
                    $pdo->prepare("INSERT INTO meditation_quotes (organization_id, quote_text, author, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $mq['quote_text'], $mq['author'], $mq['is_active'], $mq['created_by'] ? ($userMap[$mq['created_by']] ?? null) : null, $mq['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- कर्मचारी कुटुंब सदस्य (Employee Family Members) ----------------
            try {
                foreach ($data['employee_family_members'] ?? [] as $efm) {
                    $newUserIdEfm = $userMap[$efm['user_id']] ?? null;
                    if (!$newUserIdEfm) continue;
                    $pdo->prepare("INSERT INTO employee_family_members (user_id, name, relation, resides_in_ashram, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newUserIdEfm, $efm['name'], $efm['relation'], $efm['resides_in_ashram'], $efm['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- शुभेच्छा-भिंत (Wishes Wall) ----------------
            try {
                foreach ($data['birthday_wishes'] ?? [] as $bw) {
                    $newTargetId = $userMap[$bw['target_user_id']] ?? null;
                    $newPostedBy = $userMap[$bw['posted_by']] ?? null;
                    if (!$newTargetId || !$newPostedBy) continue;
                    $pdo->prepare("INSERT IGNORE INTO birthday_wishes (organization_id, target_user_id, wish_date, wish_type, wish_text, posted_by, posted_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newTargetId, $bw['wish_date'], $bw['wish_type'], $bw['wish_text'], $newPostedBy, $bw['posted_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Personal Profile — Documents + Achievements ----------------
            try {
                foreach ($data['staff_documents'] ?? [] as $sd) {
                    $newDocUserId = $userMap[$sd['user_id']] ?? null;
                    if (!$newDocUserId) continue;
                    $pdo->prepare("INSERT INTO staff_documents (user_id, document_type, file_path, original_filename, uploaded_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newDocUserId, $sd['document_type'], $sd['file_path'], $sd['original_filename'], $sd['uploaded_at']]);
                }
                foreach ($data['staff_achievements'] ?? [] as $sa) {
                    $newAchUserId = $userMap[$sa['user_id']] ?? null;
                    $newAddedBy = $userMap[$sa['added_by']] ?? null;
                    if (!$newAchUserId || !$newAddedBy) continue;
                    $pdo->prepare("INSERT INTO staff_achievements (user_id, title, description, achievement_date, added_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newAchUserId, $sa['title'], $sa['description'], $sa['achievement_date'], $newAddedBy, $sa['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Biometric Devices + Staff Attendance ----------------
            try {
                $deviceMap = [];
                foreach ($data['biometric_devices'] ?? [] as $bd) {
                    $newCampusId4 = !empty($bd['campus_id']) ? ($campusMap[$bd['campus_id']] ?? null) : null;
                    // serial_number Globally UNIQUE आहे — आधीच अस्तित्वात असेल तर Skip करतो
                    $existStmt = $pdo->prepare("SELECT id FROM biometric_devices WHERE serial_number = ?");
                    $existStmt->execute([$bd['serial_number']]);
                    $existing = $existStmt->fetch();
                    if ($existing) { $deviceMap[$bd['id']] = $existing['id']; continue; }
                    $pdo->prepare("INSERT INTO biometric_devices (organization_id, campus_id, serial_number, device_name, last_seen_at, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newCampusId4, $bd['serial_number'], $bd['device_name'], $bd['last_seen_at'], $bd['is_active'], $bd['created_at']]);
                    $deviceMap[$bd['id']] = $pdo->lastInsertId();
                }
                foreach ($data['staff_attendance'] ?? [] as $sa) {
                    $newUserId3 = $userMap[$sa['user_id']] ?? null;
                    if (!$newUserId3) continue;
                    $pdo->prepare("INSERT IGNORE INTO staff_attendance (organization_id, user_id, attendance_date, first_punch_time, last_punch_time, total_punches, status, is_late, is_early_leave, source, remarks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newUserId3, $sa['attendance_date'], $sa['first_punch_time'], $sa['last_punch_time'], $sa['total_punches'], $sa['status'], $sa['is_late'] ?? 0, $sa['is_early_leave'] ?? 0, $sa['source'], $sa['remarks'] ?? null, $sa['created_at']]);
                }
                foreach ($data['staff_shift_settings'] ?? [] as $ss) {
                    $newUserId4 = $userMap[$ss['user_id']] ?? null;
                    if (!$newUserId4) continue;
                    $pdo->prepare("INSERT IGNORE INTO staff_shift_settings (user_id, shift_start, shift_end, grace_minutes, half_day_hours, weekly_off_1, weekly_off_2, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newUserId4, $ss['shift_start'], $ss['shift_end'], $ss['grace_minutes'], $ss['half_day_hours'], $ss['weekly_off_1'], $ss['weekly_off_2'], $ss['updated_at']]);
                }
                foreach ($data['holidays'] ?? [] as $hol) {
                    $newCampusId5 = !empty($hol['campus_id']) ? ($campusMap[$hol['campus_id']] ?? null) : null;
                    $pdo->prepare("INSERT IGNORE INTO holidays (organization_id, campus_id, holiday_date, name, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newCampusId5, $hol['holiday_date'], $hol['name'], $hol['created_by'] ? ($userMap[$hol['created_by']] ?? null) : null, $hol['created_at']]);
                }
                foreach ($data['attendance_regularization_requests'] ?? [] as $arr) {
                    $newUserId5 = $userMap[$arr['user_id']] ?? null;
                    if (!$newUserId5) continue;
                    $pdo->prepare("INSERT INTO attendance_regularization_requests (organization_id, user_id, attendance_date, requested_status, reason, status, approved_by, approved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newUserId5, $arr['attendance_date'], $arr['requested_status'], $arr['reason'], $arr['status'], $arr['approved_by'] ? ($userMap[$arr['approved_by']] ?? null) : null, $arr['approved_at'], $arr['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- रजा व्यवस्थापन (Leave Management) ----------------
            try {
                $leaveTypeMap = [];
                foreach ($data['leave_types'] ?? [] as $lt) {
                    $ins = $pdo->prepare("INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid, is_active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $lt['name'], $lt['name_local'], $lt['annual_quota'], $lt['is_paid'], $lt['is_active'], $lt['created_at']]);
                    $leaveTypeMap[$lt['id']] = $pdo->lastInsertId();
                }
                foreach ($data['leave_balances'] ?? [] as $lb) {
                    $newUserId6 = $userMap[$lb['user_id']] ?? null;
                    $newLeaveTypeId = $leaveTypeMap[$lb['leave_type_id']] ?? null;
                    if (!$newUserId6 || !$newLeaveTypeId) continue;
                    $pdo->prepare("INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated_days, used_days) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newUserId6, $newLeaveTypeId, $lb['year'], $lb['allocated_days'], $lb['used_days']]);
                }
                foreach ($data['leave_applications'] ?? [] as $la) {
                    $newUserId7 = $userMap[$la['user_id']] ?? null;
                    $newLeaveTypeId2 = $leaveTypeMap[$la['leave_type_id']] ?? null;
                    if (!$newUserId7 || !$newLeaveTypeId2) continue;
                    $pdo->prepare("INSERT INTO leave_applications (organization_id, user_id, leave_type_id, start_date, end_date, total_days, reason, status, approved_by, approved_at, rejection_reason, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$orgId, $newUserId7, $newLeaveTypeId2, $la['start_date'], $la['end_date'], $la['total_days'], $la['reason'], $la['status'], $la['approved_by'] ? ($userMap[$la['approved_by']] ?? null) : null, $la['approved_at'], $la['rejection_reason'], $la['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Virtual Classroom System ----------------
            try {
                $studentMap = [];
                foreach ($data['students'] ?? [] as $st) {
                    $newCampusId6 = $campusMap[$st['campus_id']] ?? null;
                    $newDeptId2 = $deptMap[$st['department_id']] ?? null;
                    if (!$newCampusId6 || !$newDeptId2) continue;
                    try {
                        $ins = $pdo->prepare("
                            INSERT INTO students (organization_id, campus_id, department_id, roll_no, admission_no, name, year_of_study, academic_year, mobile, parent_mobile, email, password_hash, must_change_password, is_active, created_by, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$orgId, $newCampusId6, $newDeptId2, $st['roll_no'], $st['admission_no'], $st['name'], $st['year_of_study'], $st['academic_year'], $st['mobile'], $st['parent_mobile'], $st['email'], $st['password_hash'], $st['must_change_password'], $st['is_active'], $st['created_by'] ? ($userMap[$st['created_by']] ?? null) : null, $st['created_at']]);
                        $studentMap[$st['id']] = $pdo->lastInsertId();
                    } catch (PDOException $e) { /* Duplicate Admission No — वगळतो */ }
                }

                $classroomMap = [];
                foreach ($data['virtual_classrooms'] ?? [] as $vc) {
                    $newCampusId7 = $campusMap[$vc['campus_id']] ?? null;
                    $newDeptId3 = $deptMap[$vc['department_id']] ?? null;
                    $newCreatedBy2 = $userMap[$vc['created_by']] ?? null;
                    if (!$newCampusId7 || !$newDeptId3 || !$newCreatedBy2) continue;
                    $ins = $pdo->prepare("INSERT INTO virtual_classrooms (organization_id, campus_id, department_id, name, year_of_study, academic_year, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newCampusId7, $newDeptId3, $vc['name'], $vc['year_of_study'], $vc['academic_year'], $vc['is_active'], $newCreatedBy2, $vc['created_at']]);
                    $classroomMap[$vc['id']] = $pdo->lastInsertId();
                }

                foreach ($data['classroom_students'] ?? [] as $cs) {
                    $newClassroomId = $classroomMap[$cs['classroom_id']] ?? null;
                    $newStudentId = $studentMap[$cs['student_id']] ?? null;
                    if (!$newClassroomId || !$newStudentId) continue;
                    $pdo->prepare("INSERT IGNORE INTO classroom_students (classroom_id, student_id, allocated_by, allocated_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newClassroomId, $newStudentId, $cs['allocated_by'] ? ($userMap[$cs['allocated_by']] ?? null) : null, $cs['allocated_at']]);
                }

                $classroomStaffMap = [];
                foreach ($data['classroom_staff'] ?? [] as $cst) {
                    $newClassroomId2 = $classroomMap[$cst['classroom_id']] ?? null;
                    $newTeacherId = $userMap[$cst['user_id']] ?? null;
                    if (!$newClassroomId2 || !$newTeacherId) continue;
                    $ins = $pdo->prepare("INSERT INTO classroom_staff (classroom_id, user_id, subject_name, assigned_by, assigned_at) VALUES (?, ?, ?, ?, ?)");
                    $ins->execute([$newClassroomId2, $newTeacherId, $cst['subject_name'], $cst['assigned_by'] ? ($userMap[$cst['assigned_by']] ?? null) : null, $cst['assigned_at']]);
                    $classroomStaffMap[$cst['id']] = $pdo->lastInsertId();
                }

                foreach ($data['classroom_materials'] ?? [] as $cm) {
                    $newClassroomId3 = $classroomMap[$cm['classroom_id']] ?? null;
                    $newUploader = $userMap[$cm['uploaded_by']] ?? null;
                    if (!$newClassroomId3 || !$newUploader) continue;
                    $pdo->prepare("INSERT INTO classroom_materials (classroom_id, uploaded_by, subject_name, title, description, file_path, original_filename, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newClassroomId3, $newUploader, $cm['subject_name'], $cm['title'], $cm['description'], $cm['file_path'], $cm['original_filename'], $cm['created_at']]);
                }

                $assignmentMap = [];
                foreach ($data['classroom_assignments'] ?? [] as $ca) {
                    $newClassroomId4 = $classroomMap[$ca['classroom_id']] ?? null;
                    $newCreatedBy3 = $userMap[$ca['created_by']] ?? null;
                    if (!$newClassroomId4 || !$newCreatedBy3) continue;
                    $ins = $pdo->prepare("INSERT INTO classroom_assignments (classroom_id, created_by, subject_name, title, description, attachment_path, due_date, max_marks, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newClassroomId4, $newCreatedBy3, $ca['subject_name'], $ca['title'], $ca['description'], $ca['attachment_path'], $ca['due_date'], $ca['max_marks'], $ca['created_at']]);
                    $assignmentMap[$ca['id']] = $pdo->lastInsertId();
                }

                foreach ($data['classroom_assignment_submissions'] ?? [] as $sub) {
                    $newAssignmentId = $assignmentMap[$sub['assignment_id']] ?? null;
                    $newStudentId2 = $studentMap[$sub['student_id']] ?? null;
                    if (!$newAssignmentId || !$newStudentId2) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO classroom_assignment_submissions (assignment_id, student_id, file_path, original_filename, submission_note, submitted_at, marks_obtained, feedback, graded_by, graded_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newAssignmentId, $newStudentId2, $sub['file_path'], $sub['original_filename'], $sub['submission_note'], $sub['submitted_at'], $sub['marks_obtained'], $sub['feedback'], $sub['graded_by'] ? ($userMap[$sub['graded_by']] ?? null) : null, $sub['graded_at']]);
                }

                foreach ($data['classroom_announcements'] ?? [] as $ann) {
                    $newClassroomId5 = $classroomMap[$ann['classroom_id']] ?? null;
                    $newCreatedBy5 = $userMap[$ann['created_by']] ?? null;
                    if (!$newClassroomId5 || !$newCreatedBy5) continue;
                    $pdo->prepare("INSERT INTO classroom_announcements (classroom_id, created_by, title, message, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newClassroomId5, $newCreatedBy5, $ann['title'], $ann['message'], $ann['created_at']]);
                }

                foreach ($data['classroom_attendance'] ?? [] as $catt) {
                    $newClassroomId6 = $classroomMap[$catt['classroom_id']] ?? null;
                    $newStudentId3 = $studentMap[$catt['student_id']] ?? null;
                    $newMarkedBy = $userMap[$catt['marked_by']] ?? null;
                    if (!$newClassroomId6 || !$newStudentId3 || !$newMarkedBy) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO classroom_attendance (classroom_id, student_id, attendance_date, subject_name, status, marked_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newClassroomId6, $newStudentId3, $catt['attendance_date'], $catt['subject_name'], $catt['status'], $newMarkedBy, $catt['created_at']]);
                }

                $examMap = [];
                foreach ($data['classroom_exams'] ?? [] as $cex) {
                    $newClassroomId7 = $classroomMap[$cex['classroom_id']] ?? null;
                    $newCreatedBy6 = $userMap[$cex['created_by']] ?? null;
                    if (!$newClassroomId7 || !$newCreatedBy6) continue;
                    $ins = $pdo->prepare("INSERT INTO classroom_exams (classroom_id, name, subject_name, max_marks, exam_date, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newClassroomId7, $cex['name'], $cex['subject_name'], $cex['max_marks'], $cex['exam_date'], $newCreatedBy6, $cex['created_at']]);
                    $examMap[$cex['id']] = $pdo->lastInsertId();
                }

                foreach ($data['classroom_exam_marks'] ?? [] as $cem) {
                    $newExamId = $examMap[$cem['exam_id']] ?? null;
                    $newStudentId4 = $studentMap[$cem['student_id']] ?? null;
                    if (!$newExamId || !$newStudentId4) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO classroom_exam_marks (exam_id, student_id, marks_obtained, remarks, entered_by, updated_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$newExamId, $newStudentId4, $cem['marks_obtained'], $cem['remarks'], $cem['entered_by'] ? ($userMap[$cem['entered_by']] ?? null) : null, $cem['updated_at']]);
                }

                foreach ($data['classroom_timetable'] ?? [] as $ctt) {
                    $newClassroomId8 = $classroomMap[$ctt['classroom_id']] ?? null;
                    if (!$newClassroomId8) continue;
                    $pdo->prepare("
                        INSERT IGNORE INTO classroom_timetable (classroom_id, day_of_week, period_no, start_time, end_time, subject_name, teacher_id)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newClassroomId8, $ctt['day_of_week'], $ctt['period_no'], $ctt['start_time'], $ctt['end_time'], $ctt['subject_name'], $ctt['teacher_id'] ? ($userMap[$ctt['teacher_id']] ?? null) : null]);
                }

                $questionMap = [];
                foreach ($data['classroom_questions'] ?? [] as $cq) {
                    $newClassroomId9 = $classroomMap[$cq['classroom_id']] ?? null;
                    $newStudentId5 = $studentMap[$cq['student_id']] ?? null;
                    if (!$newClassroomId9 || !$newStudentId5) continue;
                    $ins = $pdo->prepare("INSERT INTO classroom_questions (classroom_id, student_id, subject_name, question_text, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newClassroomId9, $newStudentId5, $cq['subject_name'], $cq['question_text'], $cq['status'], $cq['created_at']]);
                    $questionMap[$cq['id']] = $pdo->lastInsertId();
                }

                foreach ($data['classroom_question_replies'] ?? [] as $cqr) {
                    $newQuestionId = $questionMap[$cqr['question_id']] ?? null;
                    $newRepliedBy = $userMap[$cqr['replied_by']] ?? null;
                    if (!$newQuestionId || !$newRepliedBy) continue;
                    $pdo->prepare("INSERT INTO classroom_question_replies (question_id, replied_by, reply_text, created_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newQuestionId, $newRepliedBy, $cqr['reply_text'], $cqr['created_at']]);
                }

                foreach ($data['student_notifications'] ?? [] as $sn) {
                    $newStudentId6 = $studentMap[$sn['student_id']] ?? null;
                    if (!$newStudentId6) continue;
                    $pdo->prepare("INSERT INTO student_notifications (student_id, title, message, link, is_read, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newStudentId6, $sn['title'], $sn['message'], $sn['link'], $sn['is_read'], $sn['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- कार्यक्रम व्यवस्थापन (Event Management) ----------------
            try {
                $orgEventMap = [];
                foreach ($data['events'] ?? [] as $ev) {
                    $newCreatedBy5 = $userMap[$ev['created_by']] ?? null;
                    if (!$newCreatedBy5) continue;
                    $newCampusId9 = !empty($ev['campus_id']) ? ($campusMap[$ev['campus_id']] ?? null) : null;
                    $newRespUserId = !empty($ev['responsible_user_id']) ? ($userMap[$ev['responsible_user_id']] ?? null) : null;
                    $ins = $pdo->prepare("
                        INSERT INTO events (organization_id, campus_id, name, description, event_date, start_time, end_time, venue, responsible_user_id, status, budget_estimated, budget_actual, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$orgId, $newCampusId9, $ev['name'], $ev['description'], $ev['event_date'], $ev['start_time'], $ev['end_time'], $ev['venue'], $newRespUserId, $ev['status'], $ev['budget_estimated'], $ev['budget_actual'], $newCreatedBy5, $ev['created_at']]);
                    $orgEventMap[$ev['id']] = $pdo->lastInsertId();
                }

                foreach ($data['event_tasks'] ?? [] as $et) {
                    $newEventId2 = $orgEventMap[$et['event_id']] ?? null;
                    if (!$newEventId2) continue;
                    $pdo->prepare("INSERT INTO event_tasks (event_id, title, assigned_to, due_date, status, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newEventId2, $et['title'], $et['assigned_to'] ? ($userMap[$et['assigned_to']] ?? null) : null, $et['due_date'], $et['status'], $et['created_at']]);
                }

                foreach ($data['event_budget_items'] ?? [] as $ebi) {
                    $newEventId3 = $orgEventMap[$ebi['event_id']] ?? null;
                    if (!$newEventId3) continue;
                    $pdo->prepare("INSERT INTO event_budget_items (event_id, item_description, estimated_amount, actual_amount, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newEventId3, $ebi['item_description'], $ebi['estimated_amount'], $ebi['actual_amount'], $ebi['created_at']]);
                }

                foreach ($data['event_photos'] ?? [] as $ep) {
                    $newEventId4 = $orgEventMap[$ep['event_id']] ?? null;
                    $newUploader2 = $userMap[$ep['uploaded_by']] ?? null;
                    if (!$newEventId4 || !$newUploader2) continue;
                    $pdo->prepare("INSERT INTO event_photos (event_id, file_path, caption, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newEventId4, $ep['file_path'], $ep['caption'], $newUploader2, $ep['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Teaching Module (Timetable, Substitute, Syllabus, इ.) ----------------
            try {
                $timetableMap = [];
                foreach ($data['teacher_timetable'] ?? [] as $tt) {
                    $newTeacherId1 = $userMap[$tt['teacher_id']] ?? null;
                    $newCampusIdTt = $campusMap[$tt['campus_id']] ?? null;
                    if (!$newTeacherId1 || !$newCampusIdTt) continue;
                    $ins = $pdo->prepare("
                        INSERT INTO teacher_timetable (organization_id, campus_id, teacher_id, day_of_week, period_no, start_time, end_time, subject_name, class_section, academic_year, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$orgId, $newCampusIdTt, $newTeacherId1, $tt['day_of_week'], $tt['period_no'], $tt['start_time'], $tt['end_time'], $tt['subject_name'], $tt['class_section'], $tt['academic_year'], $tt['created_at']]);
                    $timetableMap[$tt['id']] = $pdo->lastInsertId();
                }

                foreach ($data['substitute_assignments'] ?? [] as $sa) {
                    $newOrigTeacher = $userMap[$sa['original_teacher_id']] ?? null;
                    $newSubTeacher = $userMap[$sa['substitute_teacher_id']] ?? null;
                    $newAssignedBy = $userMap[$sa['assigned_by']] ?? null;
                    if (!$newOrigTeacher || !$newSubTeacher || !$newAssignedBy) continue;
                    $newOrigTtId = !empty($sa['original_timetable_id']) ? ($timetableMap[$sa['original_timetable_id']] ?? null) : null;
                    $pdo->prepare("
                        INSERT INTO substitute_assignments (organization_id, original_timetable_id, original_teacher_id, substitute_teacher_id, substitute_date, period_no, subject_name, class_section, reason, assigned_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newOrigTtId, $newOrigTeacher, $newSubTeacher, $sa['substitute_date'], $sa['period_no'], $sa['subject_name'], $sa['class_section'], $sa['reason'], $newAssignedBy, $sa['created_at']]);
                }

                foreach ($data['syllabus_trackers'] ?? [] as $st) {
                    $newTeacherId2 = $userMap[$st['teacher_id']] ?? null;
                    $newCampusIdSt = $campusMap[$st['campus_id']] ?? null;
                    if (!$newTeacherId2 || !$newCampusIdSt) continue;
                    try {
                        $pdo->prepare("
                            INSERT INTO syllabus_trackers (organization_id, campus_id, teacher_id, subject_name, class_section, academic_year, total_chapters, chapters_completed, last_updated_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([$orgId, $newCampusIdSt, $newTeacherId2, $st['subject_name'], $st['class_section'], $st['academic_year'], $st['total_chapters'], $st['chapters_completed'], $st['last_updated_at']]);
                    } catch (PDOException $e) { /* Duplicate Unique Key — वगळतो */ }
                }

                foreach ($data['teacher_qualifications'] ?? [] as $tq) {
                    $newTeacherId3 = $userMap[$tq['teacher_id']] ?? null;
                    if (!$newTeacherId3) continue;
                    $pdo->prepare("
                        INSERT INTO teacher_qualifications (teacher_id, qualification_type, specialization, institution, year_completed, net_set_status, research_papers_count, remarks, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newTeacherId3, $tq['qualification_type'], $tq['specialization'], $tq['institution'], $tq['year_completed'], $tq['net_set_status'], $tq['research_papers_count'], $tq['remarks'], $tq['created_at']]);
                }

                foreach ($data['class_teacher_assignments'] ?? [] as $cta) {
                    $newTeacherId4 = $userMap[$cta['teacher_id']] ?? null;
                    $newCampusIdCta = $campusMap[$cta['campus_id']] ?? null;
                    if (!$newTeacherId4 || !$newCampusIdCta) continue;
                    try {
                        $pdo->prepare("
                            INSERT INTO class_teacher_assignments (organization_id, campus_id, class_section, teacher_id, academic_year, created_at)
                            VALUES (?, ?, ?, ?, ?, ?)
                        ")->execute([$orgId, $newCampusIdCta, $cta['class_section'], $newTeacherId4, $cta['academic_year'], $cta['created_at']]);
                    } catch (PDOException $e) { /* Duplicate Unique Key — वगळतो */ }
                }

                foreach ($data['exam_duty_allocations'] ?? [] as $eda) {
                    $newTeacherId5 = $userMap[$eda['teacher_id']] ?? null;
                    $newCampusIdEda = $campusMap[$eda['campus_id']] ?? null;
                    $newCreatedByEda = $userMap[$eda['created_by']] ?? null;
                    if (!$newTeacherId5 || !$newCampusIdEda || !$newCreatedByEda) continue;
                    $pdo->prepare("
                        INSERT INTO exam_duty_allocations (organization_id, campus_id, exam_name, duty_date, shift, teacher_id, room_hall, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newCampusIdEda, $eda['exam_name'], $eda['duty_date'], $eda['shift'], $newTeacherId5, $eda['room_hall'], $newCreatedByEda, $eda['created_at']]);
                }

                foreach ($data['teacher_feedback'] ?? [] as $tf) {
                    $newTeacherId6 = $userMap[$tf['teacher_id']] ?? null;
                    if (!$newTeacherId6) continue;
                    $pdo->prepare("
                        INSERT INTO teacher_feedback (teacher_id, academic_year, rating, comment, submitted_by_type, submitted_at)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ")->execute([$newTeacherId6, $tf['academic_year'], $tf['rating'], $tf['comment'], $tf['submitted_by_type'], $tf['submitted_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- पाहुणे Pre-Registration ----------------
            $preRegMap = [];
            try {
                foreach ($data['guest_pre_registrations'] ?? [] as $pr) {
                    $newCampusId11 = !empty($pr['campus_id']) ? ($campusMap[$pr['campus_id']] ?? null) : null;
                    $newMeetingWith2 = !empty($pr['meeting_with']) ? ($userMap[$pr['meeting_with']] ?? null) : null;
                    try {
                        $ins = $pdo->prepare("
                            INSERT INTO guest_pre_registrations (organization_id, campus_id, name, mobile, guest_organization, purpose, meeting_with, expected_date, registration_code, status, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$orgId, $newCampusId11, $pr['name'], $pr['mobile'], $pr['guest_organization'], $pr['purpose'], $newMeetingWith2, $pr['expected_date'], $pr['registration_code'], $pr['status'], $pr['created_at']]);
                        $preRegMap[$pr['id']] = $pdo->lastInsertId();
                    } catch (PDOException $e) { /* Duplicate Registration Code — वगळतो */ }
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- पाहुणे नोंदणी (Guest Management) ----------------
            try {
                foreach ($data['guests'] ?? [] as $g) {
                    $newCreatedBy6 = $userMap[$g['created_by']] ?? null;
                    if (!$newCreatedBy6) continue;
                    $newCampusId10 = !empty($g['campus_id']) ? ($campusMap[$g['campus_id']] ?? null) : null;
                    $newMeetingWith = !empty($g['meeting_with']) ? ($userMap[$g['meeting_with']] ?? null) : null;
                    $newPreRegId = !empty($g['pre_registration_id']) ? ($preRegMap[$g['pre_registration_id']] ?? null) : null;
                    $pdo->prepare("
                        INSERT INTO guests (organization_id, campus_id, name, mobile, guest_organization, purpose, meeting_with, check_in_time, check_out_time, pass_code, photo_path, pre_registration_id, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newCampusId10, $g['name'], $g['mobile'], $g['guest_organization'], $g['purpose'], $newMeetingWith, $g['check_in_time'], $g['check_out_time'], $g['pass_code'], $g['photo_path'] ?? null, $newPreRegId, $newCreatedBy6, $g['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Asset Checkout / Borrowing ----------------
            try {
                $checkoutItemMap = [];
                foreach ($data['checkout_items'] ?? [] as $ci) {
                    $newCreatedByCi = $userMap[$ci['created_by']] ?? null;
                    if (!$newCreatedByCi) continue;
                    $newCampusIdCi = !empty($ci['campus_id']) ? ($campusMap[$ci['campus_id']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO checkout_items (organization_id, campus_id, name, category, default_location, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newCampusIdCi, $ci['name'], $ci['category'], $ci['default_location'], $ci['is_active'], $newCreatedByCi, $ci['created_at']]);
                    $checkoutItemMap[$ci['id']] = $pdo->lastInsertId();
                }
                foreach ($data['checkout_logs'] ?? [] as $cl) {
                    $newItemId = $checkoutItemMap[$cl['item_id']] ?? null;
                    $newBorrowedBy = $userMap[$cl['borrowed_by']] ?? null;
                    $newIssuedBy = $userMap[$cl['issued_by']] ?? null;
                    if (!$newItemId || !$newBorrowedBy || !$newIssuedBy) continue;
                    $pdo->prepare("
                        INSERT INTO checkout_logs (item_id, borrowed_by, issued_by, purpose, checkout_at, expected_return_at, actual_return_at, returned_to, remarks)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newItemId, $newBorrowedBy, $newIssuedBy, $cl['purpose'], $cl['checkout_at'], $cl['expected_return_at'], $cl['actual_return_at'], $cl['returned_to'] ? ($userMap[$cl['returned_to']] ?? null) : null, $cl['remarks']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Asset Allocation Registry ----------------
            try {
                $allocTypeMap = [];
                foreach ($data['asset_allocation_types'] ?? [] as $at) {
                    $newCreatedByAt = $userMap[$at['created_by']] ?? null;
                    if (!$newCreatedByAt) continue;
                    $ins = $pdo->prepare("INSERT INTO asset_allocation_types (organization_id, name, icon, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $at['name'], $at['icon'], $at['is_active'], $newCreatedByAt, $at['created_at']]);
                    $allocTypeMap[$at['id']] = $pdo->lastInsertId();
                }
                $allocFieldMap = [];
                foreach ($data['asset_allocation_fields'] ?? [] as $af) {
                    $newTypeIdAf = $allocTypeMap[$af['type_id']] ?? null;
                    if (!$newTypeIdAf) continue;
                    $ins = $pdo->prepare("INSERT INTO asset_allocation_fields (type_id, field_label, field_key, field_type, field_options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$newTypeIdAf, $af['field_label'], $af['field_key'], $af['field_type'], $af['field_options'], $af['is_required'], $af['sort_order']]);
                    $allocFieldMap[$af['id']] = $pdo->lastInsertId();
                }
                foreach ($data['asset_allocations'] ?? [] as $aa) {
                    $newTypeIdAa = $allocTypeMap[$aa['type_id']] ?? null;
                    $newCampusIdAa = $campusMap[$aa['campus_id']] ?? null;
                    $newUserIdAa = $userMap[$aa['user_id']] ?? null;
                    $newAllocatedByAa = $userMap[$aa['allocated_by']] ?? null;
                    if (!$newTypeIdAa || !$newCampusIdAa || !$newUserIdAa || !$newAllocatedByAa) continue;
                    $pdo->prepare("
                        INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, asset_label, custom_data, allocated_date, unallocated_date, unallocated_reason, allocated_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newTypeIdAa, $newCampusIdAa, $newUserIdAa, $aa['asset_label'], $aa['custom_data'], $aa['allocated_date'], $aa['unallocated_date'], $aa['unallocated_reason'], $newAllocatedByAa, $aa['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Room/Hall Booking ----------------
            try {
                $roomMap = [];
                foreach ($data['bookable_rooms'] ?? [] as $br) {
                    $newCreatedByBr = $userMap[$br['created_by']] ?? null;
                    $newCampusIdBr = $campusMap[$br['campus_id']] ?? null;
                    if (!$newCreatedByBr || !$newCampusIdBr) continue;
                    $ins = $pdo->prepare("INSERT INTO bookable_rooms (organization_id, campus_id, name, room_type, capacity, is_active, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newCampusIdBr, $br['name'], $br['room_type'], $br['capacity'], $br['is_active'], $newCreatedByBr, $br['created_at']]);
                    $roomMap[$br['id']] = $pdo->lastInsertId();
                }
                foreach ($data['room_bookings'] ?? [] as $rb) {
                    $newRoomId = $roomMap[$rb['room_id']] ?? null;
                    $newBookedBy = $userMap[$rb['booked_by']] ?? null;
                    if (!$newRoomId || !$newBookedBy) continue;
                    $pdo->prepare("INSERT INTO room_bookings (room_id, booked_by, purpose, start_time, end_time, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newRoomId, $newBookedBy, $rb['purpose'], $rb['start_time'], $rb['end_time'], $rb['status'], $rb['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Internal Staff Messages ----------------
            try {
                foreach ($data['staff_messages'] ?? [] as $sm) {
                    $newSenderId = $userMap[$sm['sender_id']] ?? null;
                    $newRecipientId = $userMap[$sm['recipient_id']] ?? null;
                    if (!$newSenderId || !$newRecipientId) continue;
                    $pdo->prepare("INSERT INTO staff_messages (sender_id, recipient_id, message_text, is_read, created_at) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newSenderId, $newRecipientId, $sm['message_text'], $sm['is_read'], $sm['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- Grievances ----------------
            try {
                foreach ($data['grievances'] ?? [] as $gr) {
                    $newSubmittedBy2 = $userMap[$gr['submitted_by']] ?? null;
                    if (!$newSubmittedBy2) continue;
                    $newAssignedTo2 = !empty($gr['assigned_to']) ? ($userMap[$gr['assigned_to']] ?? null) : null;
                    $pdo->prepare("
                        INSERT INTO grievances (organization_id, submitted_by, category, description, is_anonymous, status, assigned_to, resolution, sla_deadline, resolved_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newSubmittedBy2, $gr['category'], $gr['description'], $gr['is_anonymous'], $gr['status'], $newAssignedTo2, $gr['resolution'], $gr['sla_deadline'], $gr['resolved_at'], $gr['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- विभाग Material Purchase + सामायिक Payment Ledger ----------------
            try {
                $materialPurchaseMap = [];
                foreach ($data['dept_material_purchases'] ?? [] as $dmp) {
                    $newCreatedByDmp = $userMap[$dmp['created_by']] ?? null;
                    $newCampusIdDmp = $campusMap[$dmp['campus_id']] ?? null;
                    $newDeptIdDmp = $deptMap[$dmp['department_id']] ?? null;
                    if (!$newCreatedByDmp || !$newCampusIdDmp || !$newDeptIdDmp) continue;
                    $ins = $pdo->prepare("
                        INSERT INTO dept_material_purchases (organization_id, campus_id, department_id, material_name, vendor_name, vendor_contact, quantity, unit, rate, base_amount, transport_cost, gst_percent, gst_amount, total_amount, purchase_date, remarks, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$orgId, $newCampusIdDmp, $newDeptIdDmp, $dmp['material_name'], $dmp['vendor_name'], $dmp['vendor_contact'], $dmp['quantity'], $dmp['unit'], $dmp['rate'], $dmp['base_amount'], $dmp['transport_cost'], $dmp['gst_percent'], $dmp['gst_amount'], $dmp['total_amount'], $dmp['purchase_date'], $dmp['remarks'], $newCreatedByDmp, $dmp['created_at']]);
                    $materialPurchaseMap[$dmp['id']] = $pdo->lastInsertId();
                }

                // Payment-Ledger — Polymorphic — source_type नुसार योग्य Map वापरतो
                // (वरच्या Renewal/Maintenance Blocks मध्ये बनलेले $renewalItemMap आणि
                // $maintenanceLogMap इथेही वापरतो — दोन्ही आधीच Build झालेले आहेत)
                foreach ($data['dept_payment_ledger'] ?? [] as $dpl) {
                    $newRecordedBy = $userMap[$dpl['recorded_by']] ?? null;
                    $newDeptIdDpl = $deptMap[$dpl['department_id']] ?? null;
                    if (!$newRecordedBy || !$newDeptIdDpl) continue;

                    $newSourceId = null;
                    if ($dpl['source_type'] === 'material_purchase') { $newSourceId = $materialPurchaseMap[$dpl['source_id']] ?? null; }
                    elseif ($dpl['source_type'] === 'renewal') { $newSourceId = $renewalItemMap[$dpl['source_id']] ?? null; }
                    elseif ($dpl['source_type'] === 'maintenance') { $newSourceId = $maintenanceLogMap[$dpl['source_id']] ?? null; }
                    if (!$newSourceId) continue;

                    $pdo->prepare("
                        INSERT INTO dept_payment_ledger (organization_id, department_id, source_type, source_id, amount, payment_date, payment_type, payment_mode, for_month, remarks, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$orgId, $newDeptIdDpl, $dpl['source_type'], $newSourceId, $dpl['amount'], $dpl['payment_date'], $dpl['payment_type'], $dpl['payment_mode'], $dpl['for_month'] ?? null, $dpl['remarks'], $newRecordedBy, $dpl['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- गौशाळा (Gaushala) ----------------
            try {
                // Pass 1 — सगळी जनावरं आधी mother_cattle_id शिवाय Insert करतो (Self-Reference असल्याने)
                $cattleMap = [];
                foreach ($data['gaushala_cattle'] ?? [] as $gc) {
                    $newCreatedByGc = $userMap[$gc['created_by']] ?? null;
                    $newCampusIdGc = $campusMap[$gc['campus_id']] ?? null;
                    if (!$newCreatedByGc || !$newCampusIdGc) continue;
                    $newCaretakerId = !empty($gc['caretaker_id']) ? ($userMap[$gc['caretaker_id']] ?? null) : null;
                    if (!isset($gc['tag_number']) || $gc['tag_number'] === '') {
                        // बिना-Batch जनावर: Tag नसल्याने Duplicate ओळखायला दुसरी खूण वापरतो (दोनदा Restore केल्यास दुप्पट होऊ नये)
                        $dupChk = $pdo->prepare("SELECT id FROM gaushala_cattle WHERE organization_id = ? AND tag_number IS NULL AND name <=> ? AND breed <=> ? AND color <=> ? AND date_of_birth <=> ? AND created_at = ?");
                        $dupChk->execute([$orgId, $gc['name'], $gc['breed'], $gc['color'], $gc['date_of_birth'], $gc['created_at']]);
                        $dupRow = $dupChk->fetch();
                        if ($dupRow) { $cattleMap[$gc['id']] = $dupRow['id']; continue; }
                    }
                    try {
                        $ins = $pdo->prepare("
                            INSERT INTO gaushala_cattle (organization_id, campus_id, tag_number, name, breed, gender, color, date_of_birth, acquisition_type, acquisition_date, source_details, caretaker_id, photo_path, status, status_date, status_remarks, created_by, created_at, animal_category, approx_age_months)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $ins->execute([$orgId, $newCampusIdGc, $gc['tag_number'], $gc['name'], $gc['breed'], $gc['gender'], $gc['color'], $gc['date_of_birth'], $gc['acquisition_type'], $gc['acquisition_date'], $gc['source_details'], $newCaretakerId, $gc['photo_path'], $gc['status'], $gc['status_date'], $gc['status_remarks'], $newCreatedByGc, $gc['created_at'], $gc['animal_category'] ?? (($gc['gender'] ?? '') === 'Male' ? 'Bull/Ox' : 'Cow'), $gc['approx_age_months'] ?? null]);
                        $cattleMap[$gc['id']] = $pdo->lastInsertId();
                    } catch (PDOException $e2) { /* Duplicate Tag Number — वगळतो */ }
                }
                // Pass 2 — आता mother_cattle_id Update करतो (सगळी जनावरं आता अस्तित्वात आहेत)
                foreach ($data['gaushala_cattle'] ?? [] as $gc) {
                    if (empty($gc['mother_cattle_id']) || !isset($cattleMap[$gc['id']]) || !isset($cattleMap[$gc['mother_cattle_id']])) continue;
                    $pdo->prepare("UPDATE gaushala_cattle SET mother_cattle_id = ? WHERE id = ?")
                        ->execute([$cattleMap[$gc['mother_cattle_id']], $cattleMap[$gc['id']]]);
                }

                foreach ($data['gaushala_health_records'] ?? [] as $gh) {
                    $newCattleId1 = $cattleMap[$gh['cattle_id']] ?? null;
                    $newRecordedBy1 = $userMap[$gh['recorded_by']] ?? null;
                    if (!$newCattleId1 || !$newRecordedBy1) continue;
                    $pdo->prepare("
                        INSERT INTO gaushala_health_records (cattle_id, record_type, description, vet_name, record_date, next_due_date, cost, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newCattleId1, $gh['record_type'], $gh['description'], $gh['vet_name'], $gh['record_date'], $gh['next_due_date'], $gh['cost'], $newRecordedBy1, $gh['created_at']]);
                }

                foreach ($data['gaushala_milk_records'] ?? [] as $gm) {
                    $newCattleId2 = $cattleMap[$gm['cattle_id']] ?? null;
                    $newRecordedBy2 = $userMap[$gm['recorded_by']] ?? null;
                    if (!$newCattleId2 || !$newRecordedBy2) continue;
                    try {
                        $pdo->prepare("INSERT INTO gaushala_milk_records (cattle_id, record_date, session, quantity_liters, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                            ->execute([$newCattleId2, $gm['record_date'], $gm['session'], $gm['quantity_liters'], $newRecordedBy2, $gm['created_at']]);
                    } catch (PDOException $e3) { /* Duplicate (cattle+date+session) — वगळतो */ }
                }

                $fodderTypeMap = [];
                foreach ($data['gaushala_fodder_types'] ?? [] as $gf) {
                    $newCreatedByGf = $userMap[$gf['created_by']] ?? null;
                    $newCampusIdGf = $campusMap[$gf['campus_id']] ?? null;
                    if (!$newCreatedByGf || !$newCampusIdGf) continue;
                    $ins = $pdo->prepare("INSERT INTO gaushala_fodder_types (organization_id, campus_id, name, unit, current_stock, reorder_level, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newCampusIdGf, $gf['name'], $gf['unit'], $gf['current_stock'], $gf['reorder_level'], $newCreatedByGf, $gf['created_at']]);
                    $fodderTypeMap[$gf['id']] = $pdo->lastInsertId();
                }
                foreach ($data['gaushala_fodder_transactions'] ?? [] as $gft) {
                    $newFodderTypeId = $fodderTypeMap[$gft['fodder_type_id']] ?? null;
                    $newRecordedBy3 = $userMap[$gft['recorded_by']] ?? null;
                    if (!$newFodderTypeId || !$newRecordedBy3) continue;
                    $pdo->prepare("INSERT INTO gaushala_fodder_transactions (fodder_type_id, transaction_type, quantity, transaction_date, remarks, recorded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newFodderTypeId, $gft['transaction_type'], $gft['quantity'], $gft['transaction_date'], $gft['remarks'], $newRecordedBy3, $gft['created_at']]);
                }

                foreach ($data['gaushala_breeding_records'] ?? [] as $gb) {
                    $newCattleId3 = $cattleMap[$gb['cattle_id']] ?? null;
                    $newRecordedBy4 = $userMap[$gb['recorded_by']] ?? null;
                    if (!$newCattleId3 || !$newRecordedBy4) continue;
                    $newCalfId = !empty($gb['calf_cattle_id']) ? ($cattleMap[$gb['calf_cattle_id']] ?? null) : null;
                    $pdo->prepare("
                        INSERT INTO gaushala_breeding_records (cattle_id, breeding_date, breeding_method, expected_calving_date, actual_calving_date, calf_cattle_id, status, remarks, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newCattleId3, $gb['breeding_date'], $gb['breeding_method'], $gb['expected_calving_date'], $gb['actual_calving_date'], $newCalfId, $gb['status'], $gb['remarks'], $newRecordedBy4, $gb['created_at']]);
                }

                foreach ($data['gaushala_sponsorships'] ?? [] as $gs) {
                    $newRecordedBy5 = $userMap[$gs['recorded_by']] ?? null;
                    if (!$newRecordedBy5) continue;
                    $newCattleId4 = !empty($gs['cattle_id']) ? ($cattleMap[$gs['cattle_id']] ?? null) : null;
                    $pdo->prepare("
                        INSERT INTO gaushala_sponsorships (cattle_id, organization_id, donor_name, donor_contact, amount, sponsorship_period, start_date, end_date, status, remarks, recorded_by, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ")->execute([$newCattleId4, $orgId, $gs['donor_name'], $gs['donor_contact'], $gs['amount'], $gs['sponsorship_period'], $gs['start_date'], $gs['end_date'], $gs['status'], $gs['remarks'], $newRecordedBy5, $gs['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- कार्यक्रम उपस्थिती (Event Attendance) ----------------
            try {
                foreach ($data['event_attendance'] ?? [] as $ea) {
                    $newEventId5 = $orgEventMap[$ea['event_id']] ?? null;
                    $newMarkedBy = $userMap[$ea['marked_by']] ?? null;
                    if (!$newEventId5 || !$newMarkedBy) continue;
                    $newAttUserId = !empty($ea['user_id']) ? ($userMap[$ea['user_id']] ?? null) : null;
                    $newAttStudentId = !empty($ea['student_id']) ? ($studentMap[$ea['student_id']] ?? null) : null;
                    $pdo->prepare("INSERT INTO event_attendance (event_id, attendee_type, user_id, student_id, guest_name, marked_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$newEventId5, $ea['attendee_type'], $newAttUserId, $newAttStudentId, $ea['guest_name'], $newMarkedBy, $ea['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- सामान्य IT/Internet Requests ----------------
            try {
                $itReqMap = [];
                foreach ($data['it_general_requests'] ?? [] as $igr) {
                    $newDeptId7 = $deptMap[$igr['department_id']] ?? null;
                    $newRequestedBy2 = $userMap[$igr['requested_by']] ?? null;
                    if (!$newDeptId7 || !$newRequestedBy2) continue;
                    $newAssignedTo = !empty($igr['assigned_to']) ? ($userMap[$igr['assigned_to']] ?? null) : null;
                    $newResolvedBy2 = !empty($igr['resolved_by']) ? ($userMap[$igr['resolved_by']] ?? null) : null;
                    $ins = $pdo->prepare("INSERT INTO it_general_requests (organization_id, department_id, request_code, category, priority, title, description, location, status, assigned_to, resolution_notes, requested_by, resolved_by, resolved_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newDeptId7, $igr['request_code'], $igr['category'], $igr['priority'], $igr['title'], $igr['description'], $igr['location'], $igr['status'], $newAssignedTo, $igr['resolution_notes'], $newRequestedBy2, $newResolvedBy2, $igr['resolved_at'], $igr['created_at']]);
                    $itReqMap[$igr['id']] = $pdo->lastInsertId();
                }
                foreach ($data['it_general_request_comments'] ?? [] as $igrc) {
                    $newReqId = $itReqMap[$igrc['request_id']] ?? null;
                    $newCommentedBy = $userMap[$igrc['commented_by']] ?? null;
                    if (!$newReqId || !$newCommentedBy) continue;
                    $pdo->prepare("INSERT INTO it_general_request_comments (request_id, comment, commented_by, created_at) VALUES (?, ?, ?, ?)")
                        ->execute([$newReqId, $igrc['comment'], $newCommentedBy, $igrc['created_at']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            $itemMasterMap = [];
            $existingItemMasterStmt = $pdo->prepare("SELECT id, item_name FROM dead_stock_item_master WHERE organization_id = ?");
            $existingItemMasterStmt->execute([$orgId]);
            $existingItemMasterByName = [];
            foreach ($existingItemMasterStmt->fetchAll() as $eim) { $existingItemMasterByName[mb_strtolower(trim($eim['item_name']))] = $eim['id']; }

            foreach ($data['dead_stock_item_master'] ?? [] as $im) {
                $nameKey = mb_strtolower(trim($im['item_name']));
                if (isset($existingItemMasterByName[$nameKey])) {
                    $itemMasterMap[$im['id']] = $existingItemMasterByName[$nameKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO dead_stock_item_master (organization_id, item_name, category, created_by) VALUES (?, ?, ?, ?)");
                $ins->execute([$orgId, $im['item_name'], $im['category'], $userMap[$im['created_by']] ?? null]);
                $itemMasterMap[$im['id']] = $pdo->lastInsertId();
                $existingItemMasterByName[$nameKey] = $itemMasterMap[$im['id']];
            }
            $existingDsiStmt = $pdo->prepare("SELECT department_id, item_name, purchase_date FROM dead_stock_items WHERE organization_id = ?");
            $existingDsiStmt->execute([$orgId]);
            $existingDsiKeys = [];
            foreach ($existingDsiStmt->fetchAll() as $edsi) { $existingDsiKeys[$edsi['department_id'] . '|' . mb_strtolower(trim($edsi['item_name'])) . '|' . $edsi['purchase_date']] = true; }

            foreach ($data['dead_stock_items'] ?? [] as $di) {
                $newDeptId = $deptMap[$di['department_id']] ?? null;
                if (!$newDeptId) continue;
                $dsiKey = $newDeptId . '|' . mb_strtolower(trim($di['item_name'])) . '|' . $di['purchase_date'];
                if (isset($existingDsiKeys[$dsiKey])) { continue; }
                $itemCode = $di['item_code'] . '-R' . substr(uniqid(), -4);
                $ins = $pdo->prepare("INSERT INTO dead_stock_items (organization_id, department_id, item_master_id, item_code, item_name, category, quantity, unit, purchase_date, purchase_cost, is_new_purchase, `condition`, location, remarks, added_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newDeptId, $di['item_master_id'] ? ($itemMasterMap[$di['item_master_id']] ?? null) : null, $itemCode, $di['item_name'], $di['category'], $di['quantity'], $di['unit'], $di['purchase_date'], $di['purchase_cost'], $di['is_new_purchase'], $di['condition'], $di['location'], $di['remarks'], $di['added_by'] ? ($userMap[$di['added_by']] ?? null) : null]);
                $existingDsiKeys[$dsiKey] = true;
            }

            // ---------------- पत्र व्यवहार (Letters) — विषय+विभाग+तयार करणारा जुळल्यास Duplicate मानतो ----------------
            $categoryMap = [];
            $workflowMap = [];
            $stepMap = [];
            $letterMap = [];
            $existingCategoryStmt = $pdo->prepare("SELECT id, name FROM letter_categories WHERE organization_id = ?");
            $existingCategoryStmt->execute([$orgId]);
            $existingCategoryByName = [];
            foreach ($existingCategoryStmt->fetchAll() as $ec) { $existingCategoryByName[mb_strtolower(trim($ec['name']))] = $ec['id']; }

            foreach ($data['letter_categories'] ?? [] as $lc) {
                $lcKey = mb_strtolower(trim($lc['name']));
                if (isset($existingCategoryByName[$lcKey])) {
                    $categoryMap[$lc['id']] = $existingCategoryByName[$lcKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO letter_categories (organization_id, name, name_local, is_active) VALUES (?, ?, ?, ?)");
                $ins->execute([$orgId, $lc['name'], $lc['name_local'], $lc['is_active']]);
                $categoryMap[$lc['id']] = $pdo->lastInsertId();
                $existingCategoryByName[$lcKey] = $categoryMap[$lc['id']];
            }

            $existingWfStmt = $pdo->prepare("SELECT id, name FROM workflow_templates WHERE organization_id = ?");
            $existingWfStmt->execute([$orgId]);
            $existingWfByName = [];
            foreach ($existingWfStmt->fetchAll() as $ewf) { $existingWfByName[mb_strtolower(trim($ewf['name']))] = $ewf['id']; }
            $newlyAddedWorkflows = []; // फक्त नव्याने घातलेल्या Workflows साठीच Steps परत घालायचे — जुन्या (आधीच अस्तित्वात असलेल्या) Workflow च्या Steps ला हात लावायचा नाही

            foreach ($data['workflow_templates'] ?? [] as $wt) {
                $wfKey = mb_strtolower(trim($wt['name']));
                if (isset($existingWfByName[$wfKey])) {
                    $workflowMap[$wt['id']] = $existingWfByName[$wfKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO workflow_templates (organization_id, name, is_active) VALUES (?, ?, ?)");
                $ins->execute([$orgId, $wt['name'], $wt['is_active']]);
                $workflowMap[$wt['id']] = $pdo->lastInsertId();
                $existingWfByName[$wfKey] = $workflowMap[$wt['id']];
                $newlyAddedWorkflows[$wt['id']] = true;
            }
            foreach ($data['workflow_steps'] ?? [] as $ws) {
                $newWfId = $workflowMap[$ws['workflow_template_id']] ?? null;
                if (!$newWfId || empty($newlyAddedWorkflows[$ws['workflow_template_id']])) continue;
                $ins = $pdo->prepare("INSERT INTO workflow_steps (workflow_template_id, step_order, step_name, approver_type, user_id, department_id) VALUES (?, ?, ?, ?, ?, ?)");
                $newStepUserId = $ws['user_id'] ? ($userMap[$ws['user_id']] ?? null) : null;
                $newStepDeptId = $ws['department_id'] ? ($deptMap[$ws['department_id']] ?? null) : null;
                $ins->execute([$newWfId, $ws['step_order'], $ws['step_name'], $ws['approver_type'], $newStepUserId, $newStepDeptId]);
                $stepMap[$ws['id']] = $pdo->lastInsertId();
            }

            $existingLetterStmt = $pdo->prepare("SELECT id, department_id, created_by, subject FROM letters WHERE organization_id = ?");
            $existingLetterStmt->execute([$orgId]);
            $existingLetterKeys = [];
            foreach ($existingLetterStmt->fetchAll() as $el) { $existingLetterKeys[$el['department_id'] . '|' . $el['created_by'] . '|' . mb_strtolower(trim($el['subject']))] = $el['id']; }
            $newlyAddedLetters = []; // फक्त नव्याने घातलेल्या Letters चेच Actions/Watchers/Signatures परत घालायचे

            foreach ($data['letters'] ?? [] as $l) {
                $newDeptId = $deptMap[$l['department_id']] ?? null;
                $newCreatedBy = $userMap[$l['created_by']] ?? null;
                if (!$newDeptId || !$newCreatedBy) continue;
                $letterKey = $newDeptId . '|' . $newCreatedBy . '|' . mb_strtolower(trim($l['subject']));
                if (isset($existingLetterKeys[$letterKey])) {
                    $letterMap[$l['id']] = $existingLetterKeys[$letterKey];
                    continue;
                }
                $fileNo = $l['file_number'] . '-R' . substr(uniqid(), -4);
                $ins = $pdo->prepare("
                    INSERT INTO letters (organization_id, file_number, outward_no, reference_no, subject, description, category_id, department_id, priority, status, workflow_template_id, current_step_order, created_by, is_budget_expense, budget_category, requested_amount, approved_amount, budget_deducted)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $orgId, $fileNo, $l['outward_no'], $l['reference_no'], $l['subject'], $l['description'],
                    $l['category_id'] ? ($categoryMap[$l['category_id']] ?? null) : null, $newDeptId, $l['priority'],
                    $l['workflow_template_id'] ? ($workflowMap[$l['workflow_template_id']] ?? null) : null, $l['current_step_order'] ?? 0,
                    $newCreatedBy, $l['is_budget_expense'] ?? 0, $l['budget_category'], $l['requested_amount'], $l['approved_amount'], $l['budget_deducted'] ?? 0,
                ]);
                $letterMap[$l['id']] = $pdo->lastInsertId();
                $existingLetterKeys[$letterKey] = $letterMap[$l['id']];
                $newlyAddedLetters[$l['id']] = true;
                $counts['letters'] = ($counts['letters'] ?? 0) + 1;
            }
            // Actions/Watchers/Signatures — फक्त नव्याने घातलेल्या (Duplicate नसलेल्या) Letters साठीच घालतो,
            // नाहीतर आधीच अस्तित्वात असलेल्या पत्रावर पुन्हा-पुन्हा त्याच नोंदी साचत जातील.
            foreach ($data['letter_actions'] ?? [] as $la) {
                if (empty($newlyAddedLetters[$la['letter_id']])) continue;
                $newLetterId = $letterMap[$la['letter_id']] ?? null;
                $newFromId = $userMap[$la['from_user_id']] ?? null;
                if (!$newLetterId || !$newFromId) continue;
                $ins = $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$newLetterId, $la['action_type'], $newFromId, $la['to_user_id'] ? ($userMap[$la['to_user_id']] ?? null) : null, $la['remark']]);
            }
            foreach ($data['letter_signatures'] ?? [] as $ls) {
                if (empty($newlyAddedLetters[$ls['letter_id']])) continue;
                $newLetterId = $letterMap[$ls['letter_id']] ?? null;
                if (!$newLetterId) continue;
                $newCode = $ls['verification_code'] . 'R' . substr(uniqid(), -4);
                $ins = $pdo->prepare("INSERT INTO letter_signatures (letter_id, signed_by, signer_name, signer_designation, action_type, verification_method, verification_code, signed_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$newLetterId, $ls['signed_by'] ? ($userMap[$ls['signed_by']] ?? null) : null, $ls['signer_name'], $ls['signer_designation'], $ls['action_type'], $ls['verification_method'], $newCode, $ls['signed_at']]);
            }
            $letterAttachmentMap = [];
            foreach ($data['letter_attachments'] ?? [] as $latt) {
                if (empty($newlyAddedLetters[$latt['letter_id']])) continue;
                $newLetterId = $letterMap[$latt['letter_id']] ?? null;
                if (!$newLetterId) continue;
                $insAtt = $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_path, original_name, uploaded_by, uploaded_at) VALUES (?, ?, ?, ?, ?)");
                $insAtt->execute([$newLetterId, $latt['file_path'], $latt['original_name'], $latt['uploaded_by'] ? ($userMap[$latt['uploaded_by']] ?? null) : null, $latt['uploaded_at']]);
                $letterAttachmentMap[$latt['id']] = $pdo->lastInsertId();
            }
            foreach ($data['letter_inward_numbers'] ?? [] as $lin) {
                if (empty($newlyAddedLetters[$lin['letter_id']])) continue;
                $newLetterId = $letterMap[$lin['letter_id']] ?? null;
                $newDeptIdForInward = $deptMap[$lin['department_id']] ?? null;
                if (!$newLetterId || !$newDeptIdForInward) continue;
                try {
                    $pdo->prepare("INSERT INTO letter_inward_numbers (letter_id, department_id, inward_no, year, sequence_no, created_at) VALUES (?, ?, ?, ?, ?, ?)")
                        ->execute([$newLetterId, $newDeptIdForInward, $lin['inward_no'], $lin['year'], $lin['sequence_no'], $lin['created_at']]);
                } catch (PDOException $e) { /* Duplicate Inward Number — वगळतो */ }
            }

            // ---------------- विभाग Budget (विभाग+वर्ष जुळल्यास Duplicate मानतो) ----------------
            $existingDbStmt = $pdo->prepare("SELECT department_id, financial_year FROM department_budgets WHERE organization_id = ?");
            $existingDbStmt->execute([$orgId]);
            $existingDbKeys = [];
            foreach ($existingDbStmt->fetchAll() as $edb) { $existingDbKeys[$edb['department_id'] . '|' . $edb['financial_year']] = true; }

            foreach ($data['department_budgets'] ?? [] as $db) {
                $newDeptId = $deptMap[$db['department_id']] ?? null;
                if (!$newDeptId) continue;
                $dbKey = $newDeptId . '|' . $db['financial_year'];
                if (isset($existingDbKeys[$dbKey])) { continue; }
                $ins = $pdo->prepare("INSERT INTO department_budgets (organization_id, department_id, financial_year, allocated_amount) VALUES (?, ?, ?, ?)");
                $ins->execute([$orgId, $newDeptId, $db['financial_year'], $db['allocated_amount']]);
                $existingDbKeys[$dbKey] = true;
            }
            $existingBtStmt = $pdo->prepare("SELECT department_id, financial_year, amount, category, description FROM budget_transactions WHERE organization_id = ?");
            $existingBtStmt->execute([$orgId]);
            $existingBtKeys = [];
            foreach ($existingBtStmt->fetchAll() as $ebt) { $existingBtKeys[$ebt['department_id'] . '|' . $ebt['financial_year'] . '|' . $ebt['amount'] . '|' . $ebt['category'] . '|' . mb_strtolower(trim($ebt['description'] ?? ''))] = true; }

            foreach ($data['budget_transactions'] ?? [] as $bt) {
                $newDeptId = $deptMap[$bt['department_id']] ?? null;
                if (!$newDeptId) continue;
                $btKey = $newDeptId . '|' . $bt['financial_year'] . '|' . $bt['amount'] . '|' . $bt['category'] . '|' . mb_strtolower(trim($bt['description'] ?? ''));
                if (isset($existingBtKeys[$btKey])) { continue; }
                $ins = $pdo->prepare("INSERT INTO budget_transactions (organization_id, department_id, financial_year, amount, category, source_module, reference_id, description, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newDeptId, $bt['financial_year'], $bt['amount'], $bt['category'], $bt['source_module'], null, $bt['description'], $userMap[$bt['created_by']] ?? null]);
                $existingBtKeys[$btKey] = true;
            }

            // ---------------- SOP Compliance (शीर्षकाने आधीच असेल तर तेच वापरा) ----------------
            $sopMap = [];
            $sopStepMap = [];
            $existingSopStmt = $pdo->prepare("SELECT id, title FROM sops WHERE organization_id = ?");
            $existingSopStmt->execute([$orgId]);
            $existingSopByTitle = [];
            foreach ($existingSopStmt->fetchAll() as $esop) { $existingSopByTitle[mb_strtolower(trim($esop['title']))] = $esop['id']; }
            $newlyAddedSops = [];

            foreach ($data['sops'] ?? [] as $s) {
                $sopKey = mb_strtolower(trim($s['title']));
                if (isset($existingSopByTitle[$sopKey])) {
                    $sopMap[$s['id']] = $existingSopByTitle[$sopKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO sops (organization_id, title, description, created_by, is_active) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $s['title'], $s['description'], $userMap[$s['created_by']] ?? null, $s['is_active']]);
                $sopMap[$s['id']] = $pdo->lastInsertId();
                $existingSopByTitle[$sopKey] = $sopMap[$s['id']];
                $newlyAddedSops[$s['id']] = true;
            }
            foreach ($data['sop_steps'] ?? [] as $ss) {
                $newSopId = $sopMap[$ss['sop_id']] ?? null;
                if (!$newSopId || empty($newlyAddedSops[$ss['sop_id']])) continue;
                $ins = $pdo->prepare("INSERT INTO sop_steps (sop_id, step_order, step_description, frequency, detection_type, auto_source_module) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$newSopId, $ss['step_order'], $ss['step_description'], $ss['frequency'], $ss['detection_type'], $ss['auto_source_module']]);
                $sopStepMap[$ss['id']] = $pdo->lastInsertId();
            }
            foreach ($data['sop_department_assignments'] ?? [] as $sda) {
                $newSopId = $sopMap[$sda['sop_id']] ?? null;
                $newDeptId = $deptMap[$sda['department_id']] ?? null;
                if (!$newSopId || !$newDeptId) continue;
                try { $pdo->prepare("INSERT INTO sop_department_assignments (sop_id, department_id, assigned_by) VALUES (?, ?, ?)")->execute([$newSopId, $newDeptId, $userMap[$sda['assigned_by']] ?? null]); } catch (PDOException $e) {}
            }
            foreach ($data['sop_compliance_logs'] ?? [] as $scl) {
                $newStepId = $sopStepMap[$scl['sop_step_id']] ?? null;
                $newDeptId = $deptMap[$scl['department_id']] ?? null;
                if (!$newStepId || !$newDeptId) continue;
                try {
                    $pdo->prepare("INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status, completed_by) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$newStepId, $newDeptId, $scl['log_date'], $scl['status'], $scl['completed_by'] ? ($userMap[$scl['completed_by']] ?? null) : null]);
                } catch (PDOException $e) {}
            }

            // ---------------- भांडार Material-यादी (नावाने आधीच असेल तर तेच वापरा — Daily-Work साठी आवश्यक) ----------------
            $storeItemMap = [];
            $existingStoreItemStmt = $pdo->prepare("SELECT id, item_name FROM store_items WHERE organization_id = ?");
            $existingStoreItemStmt->execute([$orgId]);
            $existingStoreItemByName = [];
            foreach ($existingStoreItemStmt->fetchAll() as $esi) { $existingStoreItemByName[mb_strtolower(trim($esi['item_name']))] = $esi['id']; }

            foreach ($data['store_items'] ?? [] as $si) {
                $nameKey = mb_strtolower(trim($si['item_name']));
                if (isset($existingStoreItemByName[$nameKey])) {
                    $storeItemMap[$si['id']] = $existingStoreItemByName[$nameKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO store_items (organization_id, item_name, category, department_id, unit, reorder_level, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $si['item_name'], $si['category'], !empty($si['department_id']) ? ($deptMap[$si['department_id']] ?? null) : null, $si['unit'], $si['reorder_level'], $si['is_active'], $userMap[$si['created_by']] ?? null]);
                $storeItemMap[$si['id']] = $pdo->lastInsertId();
                $existingStoreItemByName[$nameKey] = $storeItemMap[$si['id']];
            }
            // ---------------- कामासाठी वापरलेलं साहित्य (Material Usage) ----------------
            try {
                foreach ($data['work_record_materials'] ?? [] as $wrm) {
                    $newWrId = $workRecordMap[$wrm['work_record_id']] ?? null;
                    $newItemId2 = $storeItemMap[$wrm['item_id']] ?? null;
                    if (!$newWrId || !$newItemId2) continue;
                    $wrmDupStmt = $pdo->prepare("SELECT id FROM work_record_materials WHERE work_record_id = ? AND item_id = ?");
                    $wrmDupStmt->execute([$newWrId, $newItemId2]);
                    if ($wrmDupStmt->fetch()) continue;
                    $pdo->prepare("INSERT INTO work_record_materials (work_record_id, item_id, quantity_used) VALUES (?, ?, ?)")
                        ->execute([$newWrId, $newItemId2, $wrm['quantity_used']]);
                }
            } catch (PDOException $e) { /* table not migrated yet */ }

            // ---------------- About Page (नावाने/प्रकाराने आधीच असेल तर वगळतो) ----------------
            $existingAsStmt = $pdo->prepare("SELECT section_type, title FROM about_sections WHERE organization_id = ?");
            $existingAsStmt->execute([$orgId]);
            $existingAsKeys = [];
            foreach ($existingAsStmt->fetchAll() as $eas) { $existingAsKeys[$eas['section_type'] . '|' . mb_strtolower(trim($eas['title'] ?? ''))] = true; }

            foreach ($data['about_sections'] ?? [] as $as) {
                $asKey = $as['section_type'] . '|' . mb_strtolower(trim($as['title'] ?? ''));
                if (isset($existingAsKeys[$asKey])) { continue; }
                $ins = $pdo->prepare("INSERT INTO about_sections (organization_id, section_type, title, description, photo_path) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $as['section_type'], $as['title'], $as['description'], $as['photo_path']]);
                $existingAsKeys[$asKey] = true;
            }
            $existingAcStmt = $pdo->prepare("SELECT name FROM about_contributors WHERE organization_id = ?");
            $existingAcStmt->execute([$orgId]);
            $existingAcNames = array_flip(array_map(fn($n) => mb_strtolower(trim($n)), array_column($existingAcStmt->fetchAll(), 'name')));

            foreach ($data['about_contributors'] ?? [] as $ac) {
                $acKey = mb_strtolower(trim($ac['name']));
                if (isset($existingAcNames[$acKey])) { continue; }
                $ins = $pdo->prepare("INSERT INTO about_contributors (organization_id, name, contribution, bio, photo_path) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $ac['name'], $ac['contribution'], $ac['bio'], $ac['photo_path']]);
                $existingAcNames[$acKey] = true;
            }

            // ---------------- CCTV विभाग (नावाने आधीच असेल तर तेच वापरा) ----------------
            $dvrMap = [];
            $existingDvrStmt = $pdo->prepare("SELECT id, dvr_name FROM cctv_dvrs WHERE organization_id = ?");
            $existingDvrStmt->execute([$orgId]);
            $existingDvrByName = [];
            foreach ($existingDvrStmt->fetchAll() as $edvr) { $existingDvrByName[mb_strtolower(trim($edvr['dvr_name']))] = $edvr['id']; }

            foreach ($data['cctv_dvrs'] ?? [] as $dvr) {
                $nameKey = mb_strtolower(trim($dvr['dvr_name']));
                if (isset($existingDvrByName[$nameKey])) {
                    $dvrMap[$dvr['id']] = $existingDvrByName[$nameKey];
                    continue;
                }
                $newDeptId = $dvr['department_id'] ? ($deptMap[$dvr['department_id']] ?? null) : null;
                $ins = $pdo->prepare("INSERT INTO cctv_dvrs (organization_id, department_id, dvr_name, location, channel_capacity, storage_capacity_tb, installed_date, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newDeptId, $dvr['dvr_name'], $dvr['location'], $dvr['channel_capacity'], $dvr['storage_capacity_tb'], $dvr['installed_date'], $dvr['is_active'], $dvr['created_by'] ? ($userMap[$dvr['created_by']] ?? null) : null]);
                $dvrMap[$dvr['id']] = $pdo->lastInsertId();
                $existingDvrByName[$nameKey] = $dvrMap[$dvr['id']];
            }
            $cameraMap = [];
            $existingCamStmt = $pdo->prepare("
                SELECT cc.id, cc.dvr_id, cc.camera_name FROM cctv_cameras cc JOIN cctv_dvrs d ON d.id = cc.dvr_id WHERE d.organization_id = ?
            ");
            $existingCamStmt->execute([$orgId]);
            $existingCamKeys = [];
            foreach ($existingCamStmt->fetchAll() as $ecam) { $existingCamKeys[$ecam['dvr_id'] . '|' . mb_strtolower(trim($ecam['camera_name']))] = $ecam['id']; }

            foreach ($data['cctv_cameras'] ?? [] as $cam) {
                $newDvrId = $dvrMap[$cam['dvr_id']] ?? null;
                if (!$newDvrId) continue;
                $camKey = $newDvrId . '|' . mb_strtolower(trim($cam['camera_name']));
                if (isset($existingCamKeys[$camKey])) {
                    $cameraMap[$cam['id']] = $existingCamKeys[$camKey];
                    continue;
                }
                $ins = $pdo->prepare("INSERT INTO cctv_cameras (organization_id, dvr_id, camera_name, location, camera_type, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newDvrId, $cam['camera_name'], $cam['location'], $cam['camera_type'], $cam['is_active']]);
                $cameraMap[$cam['id']] = $pdo->lastInsertId();
                $existingCamKeys[$camKey] = $cameraMap[$cam['id']];
            }
            $checkupMap = [];
            foreach ($data['cctv_checkups'] ?? [] as $ck) {
                $newDvrId = $dvrMap[$ck['dvr_id']] ?? null;
                $newCheckedBy = $userMap[$ck['checked_by']] ?? null;
                if (!$newDvrId || !$newCheckedBy) continue;
                try {
                    $ins = $pdo->prepare("INSERT INTO cctv_checkups (organization_id, dvr_id, checkup_date, storage_used_percent, footage_days_available, backup_done, backup_location, remarks, checked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $ins->execute([$orgId, $newDvrId, $ck['checkup_date'], $ck['storage_used_percent'], $ck['footage_days_available'], $ck['backup_done'], $ck['backup_location'], $ck['remarks'], $newCheckedBy]);
                    $checkupMap[$ck['id']] = $pdo->lastInsertId();
                } catch (PDOException $e) { /* त्याच DVR-दिवसाची नोंद आधीच असेल तर वगळा */ }
            }
            foreach ($data['cctv_camera_status'] ?? [] as $cs) {
                $newCheckupId = $checkupMap[$cs['checkup_id']] ?? null;
                $newCameraId = $cameraMap[$cs['camera_id']] ?? null;
                if (!$newCheckupId || !$newCameraId) continue;
                try {
                    $pdo->prepare("INSERT INTO cctv_camera_status (checkup_id, camera_id, status, remarks) VALUES (?, ?, ?, ?)")
                        ->execute([$newCheckupId, $newCameraId, $cs['status'], $cs['remarks']]);
                } catch (PDOException $e) {}
            }

            // ---------------- IT विभाग (नावाने आधीच असेल तर तेच वापरा) ----------------
            $labMap = [];
            $existingLabStmt = $pdo->prepare("SELECT id, lab_name FROM it_labs WHERE organization_id = ?");
            $existingLabStmt->execute([$orgId]);
            $existingLabByName = [];
            foreach ($existingLabStmt->fetchAll() as $elab) { $existingLabByName[mb_strtolower(trim($elab['lab_name']))] = $elab['id']; }

            foreach ($data['it_labs'] ?? [] as $lab) {
                $nameKey = mb_strtolower(trim($lab['lab_name']));
                if (isset($existingLabByName[$nameKey])) {
                    $labMap[$lab['id']] = $existingLabByName[$nameKey];
                    continue;
                }
                $newDeptId = $lab['department_id'] ? ($deptMap[$lab['department_id']] ?? null) : null;
                $ins = $pdo->prepare("INSERT INTO it_labs (organization_id, department_id, lab_name, location, capacity, assistant_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newDeptId, $lab['lab_name'], $lab['location'], $lab['capacity'], $lab['assistant_id'] ? ($userMap[$lab['assistant_id']] ?? null) : null, $lab['is_active']]);
                $labMap[$lab['id']] = $pdo->lastInsertId();
                $existingLabByName[$nameKey] = $labMap[$lab['id']];
            }
            $itAssetMap = [];
            $newlyAddedItAssets = [];
            $existingItAssetStmt = $pdo->prepare("SELECT id, lab_id, asset_type, brand, model, serial_no FROM it_assets WHERE organization_id = ?");
            $existingItAssetStmt->execute([$orgId]);
            $existingItAssetKeys = [];
            foreach ($existingItAssetStmt->fetchAll() as $eia) {
                $key = $eia['lab_id'] . '|' . $eia['asset_type'] . '|' . mb_strtolower(trim($eia['brand'] ?? '')) . '|' . mb_strtolower(trim($eia['model'] ?? '')) . '|' . mb_strtolower(trim($eia['serial_no'] ?? ''));
                $existingItAssetKeys[$key] = $eia['id'];
            }

            foreach ($data['it_assets'] ?? [] as $ia) {
                $newLabId = $labMap[$ia['lab_id']] ?? null;
                if (!$newLabId) continue;
                $iaKey = $newLabId . '|' . $ia['asset_type'] . '|' . mb_strtolower(trim($ia['brand'] ?? '')) . '|' . mb_strtolower(trim($ia['model'] ?? '')) . '|' . mb_strtolower(trim($ia['serial_no'] ?? ''));
                if (isset($existingItAssetKeys[$iaKey])) {
                    $itAssetMap[$ia['id']] = $existingItAssetKeys[$iaKey];
                    continue;
                }
                $newTag = $ia['asset_tag'] . '-R' . substr(uniqid(), -4);
                $ins = $pdo->prepare("
                    INSERT INTO it_assets (organization_id, lab_id, workstation_no, asset_tag, asset_type, brand, model, serial_no, purchase_date, purchase_cost, warranty_expiry, os_license, license_expiry, status, power_status, power_status_updated_at, power_status_updated_by, remarks, added_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([
                    $orgId, $newLabId, $ia['workstation_no'] ?? null, $newTag, $ia['asset_type'], $ia['brand'], $ia['model'], $ia['serial_no'],
                    $ia['purchase_date'], $ia['purchase_cost'], $ia['warranty_expiry'], $ia['os_license'], $ia['license_expiry'],
                    $ia['status'], $ia['power_status'] ?? 'off', $ia['power_status_updated_at'] ?? null,
                    !empty($ia['power_status_updated_by']) ? ($userMap[$ia['power_status_updated_by']] ?? null) : null,
                    $ia['remarks'], $ia['added_by'] ? ($userMap[$ia['added_by']] ?? null) : null,
                ]);
                $itAssetMap[$ia['id']] = $pdo->lastInsertId();
                $existingItAssetKeys[$iaKey] = $itAssetMap[$ia['id']];
                $newlyAddedItAssets[$ia['id']] = true;
            }
            foreach ($data['it_asset_transfers'] ?? [] as $trf) {
                if (empty($newlyAddedItAssets[$trf['asset_id']])) continue;
                $newAssetId = $itAssetMap[$trf['asset_id']] ?? null;
                $newToLabId = $labMap[$trf['to_lab_id']] ?? null;
                if (!$newAssetId || !$newToLabId) continue;
                $ins = $pdo->prepare("INSERT INTO it_asset_transfers (asset_id, from_lab_id, to_lab_id, transferred_by, remarks) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$newAssetId, $trf['from_lab_id'] ? ($labMap[$trf['from_lab_id']] ?? null) : null, $newToLabId, $trf['transferred_by'] ? ($userMap[$trf['transferred_by']] ?? null) : null, $trf['remarks']]);
            }
            foreach ($data['it_repair_tickets'] ?? [] as $rt) {
                if (empty($newlyAddedItAssets[$rt['asset_id']])) continue;
                $newAssetId = $itAssetMap[$rt['asset_id']] ?? null;
                $newLabId = $labMap[$rt['lab_id']] ?? null;
                $newReportedBy = $userMap[$rt['reported_by']] ?? null;
                if (!$newAssetId || !$newLabId || !$newReportedBy) continue;
                $ins = $pdo->prepare("INSERT INTO it_repair_tickets (organization_id, asset_id, lab_id, issue_description, status, resolution_notes, reported_by, resolved_by, resolved_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $ins->execute([$orgId, $newAssetId, $newLabId, $rt['issue_description'], $rt['status'], $rt['resolution_notes'], $newReportedBy, $rt['resolved_by'] ? ($userMap[$rt['resolved_by']] ?? null) : null, $rt['resolved_at']]);
            }

            // ---------------- संस्थेचं स्वतःचं Logo/Welcome Music ----------------
            // सुरक्षिततेसाठी — सध्याच्या संस्थेला आधीच Logo/Music सेट असेल तर
            // Backup मधून ते Overwrite करत नाही (जाणूनबुजून बदललेलं असू शकतं).
            // फक्त रिकामं असेल तरच Backup मधून भरून देतो.
            if (!empty($data['organization'])) {
                $curOrgStmt = $pdo->prepare("SELECT logo_path, welcome_audio_path FROM organizations WHERE id = ?");
                $curOrgStmt->execute([$orgId]);
                $curOrg = $curOrgStmt->fetch();
                $newLogoPath = (empty($curOrg['logo_path']) && !empty($data['organization']['logo_path'])) ? $data['organization']['logo_path'] : null;
                $newAudioPath = (empty($curOrg['welcome_audio_path']) && !empty($data['organization']['welcome_audio_path'])) ? $data['organization']['welcome_audio_path'] : null;
                if ($newLogoPath || $newAudioPath) {
                    $brandSql = "UPDATE organizations SET ";
                    $brandParams = [];
                    if ($newLogoPath) { $brandSql .= "logo_path=?, "; $brandParams[] = $newLogoPath; }
                    if ($newAudioPath) { $brandSql .= "welcome_audio_path=?, "; $brandParams[] = $newAudioPath; }
                    $brandSql = rtrim($brandSql, ', ') . " WHERE id=?";
                    $brandParams[] = $orgId;
                    $pdo->prepare($brandSql)->execute($brandParams);
                }
            }

            $pdo->commit();
            if ($restoredFileCount > 0) { $counts['restored_files'] = $restoredFileCount; }
            logActivity('बॅकअपमधून डेटा रिस्टोर केला', 'organization', $orgId, json_encode($counts));
            $successSummary = $counts;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'रिस्टोर करताना त्रुटी आली: ' . $e->getMessage();
        }
    }
}

$pageTitle = t('s373');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s354')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<?php if ($successSummary): ?>
  <div class="panel" style="border-color:var(--rail);">
    <div class="panel-head" style="background:var(--rail-soft);"><div class="title" style="color:var(--rail);"><i class="fa-solid fa-circle-check me-1"></i><?= h(t('s355')) ?></div></div>
    <div style="padding:20px 24px;font-size:13.5px;">
      <ul>
        <li><?= $successSummary['campuses'] ?> <?= h(t('s8')) ?></li>
        <li><?= $successSummary['departments'] ?> <?= h(t('s1')) ?></li>
        <li><?= $successSummary['users'] ?> <?= h(t('s356')) ?></li>
        <li><?= $successSummary['work_records'] ?> <?= h(t('s28')) ?></li>
        <li><?= $successSummary['tasks'] ?> <?= h(t('s14')) ?></li>
        <li><?= $successSummary['renewal_items'] ?> <?= h(t('s357')) ?></li>
        <li><?= $successSummary['hostels'] ?> <?= h(t('s358')) ?> <?= $successSummary['hostel_students'] ?> <?= h(t('s25')) ?></li>
        <li><?= $successSummary['work_requests'] ?> <?= h(t('s359')) ?></li>
        <?php if (!empty($successSummary['restored_files'])): ?>
          <li>📎 <?= $successSummary['restored_files'] ?> Uploaded Files (Logo/Attachments/Photos) परत ठेवल्या गेल्या</li>
        <?php endif; ?>
      </ul>
      <a href="../index.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;"><?= h(t('s360')) ?></a>
    </div>
  </div>
<?php else: ?>
  <div class="panel">
    <div class="panel-head"><div class="title"><?= h(t('s361')) ?></div><div class="sub"><?= h(t('s362')) ?></div></div>
    <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
      <div class="upload-box mb-3">
        <input type="file" name="backup_file" accept=".json,.zip" required style="width:100%;">
        <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">साधी Backup (.json) किंवा Full Backup (.zip — Files सकट) — दोन्ही चालतील.</div>
      </div>
      <p style="font-size:12px;color:var(--text-dim);margin-bottom:16px;"><?= h(t('s363')) ?><b><?= h(activeOrgName()) ?></b>) जोडला जाईल. आधीच अस्तित्वात असलेले कर्मचारी (email जुळल्यास) duplicate होणार नाहीत — automatically skip होतील. <strong>पण बाकीच्या नोंदी (कामाचे अहवाल, पत्रे, Budget, इ.) — जर हीच Backup आधीही एकदा Restore केलेली असेल, तर पुन्हा Restore केल्यास त्या नोंदी Duplicate होतील.</strong></p>
      <?php if ($hasExistingData): ?>
        <div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;padding:14px 18px;">
          ⚠️ <strong>या Organization मध्ये आधीच Data आहे.</strong> जर हीच Backup आधी एकदा Restore केली असेल — पुन्हा केल्यास बहुतांश नोंदी (Work Records, Letters, Budget, इ.) <strong>Duplicate</strong> होतील.
        </div>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;margin-bottom:16px;cursor:pointer;">
          <input type="checkbox" name="confirm_duplicate_risk" value="1" required style="width:16px;height:16px;margin-top:2px;">
          <span>मला समजलं आहे — ही Backup आधी Restore केलेली नाही (किंवा Duplicate होण्याचा धोका मला मान्य आहे), तरीही पुढे जा.</span>
        </label>
      <?php endif; ?>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s364')) ?></button>
      <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
    </form>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
