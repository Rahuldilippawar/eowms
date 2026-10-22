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

/** दिलेल्या auto_source_module साठी, आज त्या विभागाने ती गोष्ट केली आहे का (backward-compat — daily साठी) */
function sopCheckAutoCompliance($pdo, $sourceModule, $departmentId, $checkDate = null) {
    $checkDate = $checkDate ?? date('Y-m-d');
    return sopCheckAutoComplianceInRange($pdo, $sourceModule, $departmentId, $checkDate, $checkDate);
}

/** मानवी-वाचनीय नाव — कुठला module Auto-Detection साठी वापरला आहे */
function sopAutoSourceLabel($sourceModule) {
    $labels = [
        'daily_work' => 'दैनंदिन कामाचा अहवाल (आज नोंद झाली का)',
        'hostel_attendance' => 'वसतिगृह हजेरी (आज 3 सत्रं भरली का)',
        'vehicle_trip' => 'वाहन Trip Log (आज नोंद झाली का)',
        'maintenance_log' => 'देखभाल नोंदवही (आज नोंद झाली का)',
    ];
    return $labels[$sourceModule] ?? $sourceModule;
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

/** विभागाचं, एका SOP साठीचं, गेल्या N दिवसांचं सरासरी Compliance % — रोज/आठवडा/महिना सगळ्या पायऱ्या धरून */
function sopGetComplianceScore($pdo, $sopId, $departmentId, $days = 30) {
    $stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ?");
    $stepsStmt->execute([$sopId]);
    $steps = $stepsStmt->fetchAll();
    if (empty($steps)) { return null; }

    $totalChecks = 0;
    $doneChecks = 0;

    foreach ($steps as $step) {
        $periodDates = sopGetDistinctPeriodDates($step['frequency'], $days);
        foreach ($periodDates as $date) {
            $status = sopGetStepStatus($pdo, $step, $departmentId, $date);
            if ($status === 'unknown') { continue; }
            $totalChecks++;
            if ($status === 'done') { $doneChecks++; }
        }
    }
    if ($totalChecks === 0) { return null; }
    return round(($doneChecks / $totalChecks) * 100);
}
