<?php
/**
 * SOP Compliance Tracking — Helper Functions
 * ---------------------------------------------------------------
 * काही SOP पायऱ्या "Auto" असतात — म्हणजे staff ला वेगळं टिक करावं
 * लागत नाही, आधीच्या Modules (दैनंदिन कामाचा अहवाल, वसतिगृह हजेरी, इ.)
 * मधल्या डेटा वरून सिस्टीम स्वतः "आज झालं की नाही" हे ठरवते.
 *
 * "रोज" पायऱ्या — त्याच दिवसापुरत्या तपासल्या जातात.
 * "आठवड्यातून एकदा" पायऱ्या — त्या आठवड्यात (सोम-रवि) कधीही झालं तरी पुरेसं.
 * "महिन्यातून एकदा" पायऱ्या — त्या महिन्यात कधीही झालं तरी पुरेसं.
 */

/** दिलेल्या तारखेसाठी, दिलेल्या वारंवारतेचा कालावधी (सुरुवात, शेवट) परत करते */
function sopGetPeriodRange($frequency, $referenceDate) {
    $ts = strtotime($referenceDate);
    if ($frequency === 'weekly') {
        $dayOfWeek = (int)date('N', $ts); // 1=सोमवार ... 7=रविवार
        $monday = date('Y-m-d', strtotime("-" . ($dayOfWeek - 1) . " days", $ts));
        $sunday = date('Y-m-d', strtotime("+6 days", strtotime($monday)));
        return [$monday, $sunday];
    }
    if ($frequency === 'monthly') {
        return [date('Y-m-01', $ts), date('Y-m-t', $ts)];
    }
    return [date('Y-m-d', $ts), date('Y-m-d', $ts)]; // daily
}

/** दिलेल्या auto_source_module साठी, दिलेल्या कालावधीत (एका दिवसापासून ते संपूर्ण महिन्यापर्यंत) एकदाही झालं का */
function sopCheckAutoComplianceInRange($pdo, $sourceModule, $departmentId, $startDate, $endDate) {
    switch ($sourceModule) {
        case 'daily_work':
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id = ? AND work_date BETWEEN ? AND ?");
            $stmt->execute([$departmentId, $startDate, $endDate]);
            return (int)$stmt->fetch()['c'] > 0;

        case 'hostel_attendance':
            // या कालावधीत, विभागाच्या वसतिगृहांमध्ये कोणत्याही एका दिवशी तिन्ही सत्रं भरली आहेत का
            $stmt = $pdo->prepare("
                SELECT hsa.attendance_date, COUNT(DISTINCT hsa.session) c FROM hostel_student_attendance hsa
                JOIN hostels h ON h.id = hsa.hostel_id
                WHERE h.department_id = ? AND hsa.attendance_date BETWEEN ? AND ?
                GROUP BY hsa.attendance_date HAVING c >= 3
            ");
            $stmt->execute([$departmentId, $startDate, $endDate]);
            return $stmt->fetch() !== false;

        case 'vehicle_trip':
            $stmt = $pdo->prepare("
                SELECT COUNT(*) c FROM vehicle_trips vt
                JOIN vehicles v ON v.id = vt.vehicle_id
                WHERE v.department_id = ? AND vt.trip_date BETWEEN ? AND ?
            ");
            $stmt->execute([$departmentId, $startDate, $endDate]);
            return (int)$stmt->fetch()['c'] > 0;

        case 'maintenance_log':
            $stmt = $pdo->prepare("SELECT COUNT(*) c FROM maintenance_logs WHERE department_id = ? AND work_date BETWEEN ? AND ?");
            $stmt->execute([$departmentId, $startDate, $endDate]);
            return (int)$stmt->fetch()['c'] > 0;

        default:
            return null; // अनोळखी source — पडताळणी करता येत नाही
    }
}

/**
 * दिलेल्या कालावधीत, दिलेल्या auto_source_module साठी — ज्या ज्या तारखांना
 * विभागाने ती गोष्ट केली त्या तारखांचा संच (['Y-m-d' => true]) एकाच Query मध्ये.
 * अनोळखी source असेल तर null.
 */
function sopAutoActivityDates($pdo, $sourceModule, $departmentId, $startDate, $endDate) {
    switch ($sourceModule) {
        case 'daily_work':
            $stmt = $pdo->prepare("SELECT DISTINCT work_date d FROM work_records WHERE department_id = ? AND work_date BETWEEN ? AND ?");
            break;
        case 'hostel_attendance':
            $stmt = $pdo->prepare("
                SELECT hsa.attendance_date d FROM hostel_student_attendance hsa
                JOIN hostels h ON h.id = hsa.hostel_id
                WHERE h.department_id = ? AND hsa.attendance_date BETWEEN ? AND ?
                GROUP BY hsa.attendance_date HAVING COUNT(DISTINCT hsa.session) >= 3
            ");
            break;
        case 'vehicle_trip':
            $stmt = $pdo->prepare("SELECT DISTINCT vt.trip_date d FROM vehicle_trips vt JOIN vehicles v ON v.id = vt.vehicle_id WHERE v.department_id = ? AND vt.trip_date BETWEEN ? AND ?");
            break;
        case 'maintenance_log':
            $stmt = $pdo->prepare("SELECT DISTINCT work_date d FROM maintenance_logs WHERE department_id = ? AND work_date BETWEEN ? AND ?");
            break;
        default:
            return null;
    }
    $stmt->execute([$departmentId, $startDate, $endDate]);
    return array_fill_keys(array_map(fn($d) => substr($d, 0, 10), $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
}

/** संचातली एखादी तारीख [start, end] मध्ये आहे का */
function sopAnyDateInRange(array $dateSet, $start, $end) {
    foreach ($dateSet as $d => $_) {
        if ($d >= $start && $d <= $end) { return true; }
    }
    return false;
}

/**
 * सुट्टीचे दिवस — रविवार (साप्ताहिक सुट्टी) + holidays Table मधले (संपूर्ण
 * संस्थेचे किंवा विभागाच्या College/Campus चे). "रोज"च्या पायऱ्या या दिवशी
 * तपासल्या/मोजल्या जात नाहीत. ['Y-m-d' => true] परत करते.
 */
function sopOffDays($pdo, $orgId, $campusId, $startDate, $endDate) {
    $off = [];
    for ($ts = strtotime($startDate); $ts <= strtotime($endDate); $ts = strtotime('+1 day', $ts)) {
        if ((int)date('w', $ts) === 0) { $off[date('Y-m-d', $ts)] = true; }
    }
    try {
        $stmt = $pdo->prepare("SELECT holiday_date FROM holidays WHERE organization_id = ? AND holiday_date BETWEEN ? AND ? AND (campus_id IS NULL OR campus_id = ?)");
        $stmt->execute([$orgId, $startDate, $endDate, $campusId ?: 0]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $d) { $off[$d] = true; }
    } catch (PDOException $e) { /* holidays table not migrated yet — फक्त रविवार */ }
    return $off;
}

/**
 * दिलेल्या SOP-पायरीसाठी, दिलेल्या विभागासाठी, दिलेल्या तारखेचा कालावधी
 * (रोज/आठवडा/महिना — पायरीच्या वारंवारतेनुसार) — Auto असेल तर लगेच तपासून
 * result देते; Manual असेल तर त्या संपूर्ण कालावधीत कधीही टिक झालं का ते
 * sop_compliance_logs मधून बघते.
 */
function sopGetStepStatus($pdo, $step, $departmentId, $checkDate = null) {
    $checkDate = $checkDate ?? date('Y-m-d');
    [$rangeStart, $rangeEnd] = sopGetPeriodRange($step['frequency'], $checkDate);

    if ($step['detection_type'] === 'auto' && $step['auto_source_module']) {
        $isDone = sopCheckAutoComplianceInRange($pdo, $step['auto_source_module'], $departmentId, $rangeStart, $rangeEnd);
        return $isDone === null ? 'unknown' : ($isDone ? 'done' : 'not_done');
    }

    $stmt = $pdo->prepare("
        SELECT id FROM sop_compliance_logs
        WHERE sop_step_id = ? AND department_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
        LIMIT 1
    ");
    $stmt->execute([$step['id'], $departmentId, $rangeStart, $rangeEnd]);
    return $stmt->fetch() ? 'done' : 'not_done';
}

/**
 * गेल्या N दिवसांमध्ये, दिलेल्या वारंवारतेचे किती वेगळे कालावधी (उदा. किती
 * दिवस/आठवडे/महिने) आले — प्रत्येकासाठी एक प्रातिनिधिक तारीख (त्या
 * कालावधीचा शेवटचा दिवस) परत करते.
 */
function sopGetDistinctPeriodDates($frequency, $days) {
    $dates = [];
    $seenPeriods = [];
    for ($i = 0; $i < $days; $i++) {
        $date = date('Y-m-d', strtotime("-$i days"));
        [$start, $end] = sopGetPeriodRange($frequency, $date);
        $key = $start . '_' . $end;
        if (!isset($seenPeriods[$key])) {
            $seenPeriods[$key] = true;
            // तो कालावधी संपूर्णपणे आजच्या आधी झाला असेल तरच मोजा (चालू
            // आठवडा/महिना अजून संपलेला नसेल तर त्याला "chुकलं" असं धरू नये)
            if (strtotime($end) < strtotime(date('Y-m-d'))) {
                $dates[] = $end;
            }
        }
    }
    return $dates;
}

/**
 * विभागाचं, एका SOP साठीचं, गेल्या N दिवसांचं सरासरी Compliance % — रोज/आठवडा/महिना सगळ्या पायऱ्या धरून.
 * प्रत्येक दिवसासाठी वेगळी Query न करता — Manual टिक, Auto-स्रोत व सुट्ट्या
 * एकदाच (कालावधीपुरत्या) आणून PHP मध्येच मोजतो. "रोज"च्या पायऱ्या रविवार/सुट्टीला मोजल्या जात नाहीत.
 */
function sopGetComplianceScore($pdo, $sopId, $departmentId, $days = 30) {
    $stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ?");
    $stepsStmt->execute([$sopId]);
    $steps = $stepsStmt->fetchAll();
    if (empty($steps)) { return null; }

    // प्रत्येक पायरीचे कालावधी व सर्वात जुनी सुरुवात-तारीख
    $periodsByStep = [];
    $rangeStart = date('Y-m-d');
    $rangeEnd = date('Y-m-d', strtotime('-1 day'));
    foreach ($steps as $step) {
        $periods = [];
        foreach (sopGetDistinctPeriodDates($step['frequency'], $days) as $date) {
            $periods[] = sopGetPeriodRange($step['frequency'], $date);
        }
        $periodsByStep[$step['id']] = $periods;
        foreach ($periods as [$s, $e]) { if ($s < $rangeStart) { $rangeStart = $s; } }
    }
    if ($rangeStart > $rangeEnd) { return null; }

    $deptStmt = $pdo->prepare("SELECT organization_id, campus_id FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptRow = $deptStmt->fetch() ?: ['organization_id' => 0, 'campus_id' => null];
    $offDays = sopOffDays($pdo, $deptRow['organization_id'], $deptRow['campus_id'], $rangeStart, $rangeEnd);

    // Manual टिक — सगळ्या पायऱ्यांचे एकाच Query मध्ये
    $stepIds = array_column($steps, 'id');
    $ph = implode(',', array_fill(0, count($stepIds), '?'));
    $logStmt = $pdo->prepare("SELECT sop_step_id, log_date FROM sop_compliance_logs WHERE sop_step_id IN ($ph) AND department_id = ? AND status = 'done' AND log_date BETWEEN ? AND ?");
    $logStmt->execute(array_merge($stepIds, [$departmentId, $rangeStart, $rangeEnd]));
    $manualDone = [];
    foreach ($logStmt->fetchAll() as $l) { $manualDone[$l['sop_step_id']][substr($l['log_date'], 0, 10)] = true; }

    $autoCache = [];
    $totalChecks = 0;
    $doneChecks = 0;

    foreach ($steps as $step) {
        $isAuto = $step['detection_type'] === 'auto' && $step['auto_source_module'];
        if ($isAuto) {
            $mod = $step['auto_source_module'];
            if (!array_key_exists($mod, $autoCache)) {
                $autoCache[$mod] = sopAutoActivityDates($pdo, $mod, $departmentId, $rangeStart, $rangeEnd);
            }
            $doneSet = $autoCache[$mod];
            if ($doneSet === null) { continue; } // अनोळखी source — मोजत नाही
        } else {
            $doneSet = $manualDone[$step['id']] ?? [];
        }

        foreach ($periodsByStep[$step['id']] as [$s, $e]) {
            if ($step['frequency'] === 'daily' && isset($offDays[$s])) { continue; } // रविवार/सुट्टी
            $totalChecks++;
            if (sopAnyDateInRange($doneSet, $s, $e)) { $doneChecks++; }
        }
    }
    if ($totalChecks === 0) { return null; }
    return round(($doneChecks / $totalChecks) * 100);
}
