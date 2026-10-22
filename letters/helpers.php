<?php
// Expects $pdo to be available. Resolves a workflow step to an actual user_id.

/**
 * भारतीय शासकीय पद्धतीनुसार आर्थिक वर्ष (एप्रिल-मार्च) — Calendar Year
 * (Jan-Dec) ऐवजी. एप्रिल-डिसेंबर असेल तर तेच वर्ष सुरुवात मानतो;
 * जानेवारी-मार्च असेल तर मागचं वर्ष सुरुवात मानतो.
 * उदा. 15 Aug 2026 -> "26-27" | 15 Feb 2026 -> "25-26"
 */
function getFinancialYearLabel($timestamp = null) {
    $timestamp = $timestamp ?? time();
    $y = (int)date('Y', $timestamp);
    $m = (int)date('n', $timestamp);
    $startYear = $m >= 4 ? $y : $y - 1;
    return sprintf('%02d-%02d', $startYear % 100, ($startYear + 1) % 100);
}

/** चालू आर्थिक वर्षाची सुरुवात (1 एप्रिल) आणि शेवट (31 मार्च) — SQL Filter साठी. */
function getFinancialYearRange($timestamp = null) {
    $timestamp = $timestamp ?? time();
    $y = (int)date('Y', $timestamp);
    $m = (int)date('n', $timestamp);
    $startYear = $m >= 4 ? $y : $y - 1;
    return [$startYear . '-04-01', ($startYear + 1) . '-03-31'];
}

/** "25-26" सारख्या Label वरून त्या आर्थिक वर्षाची Date-Range परत देते (Report/Register च्या Year-Selector साठी). */
function financialYearLabelToRange($label) {
    $parts = explode('-', $label);
    $startYearShort = (int)($parts[0] ?? 0);
    $century = $startYearShort < 50 ? 2000 : 1900; // साधी गृहीत — 2000+ सालांसाठी पुरेशी
    $startYear = $century + $startYearShort;
    return [$startYear . '-04-01', ($startYear + 1) . '-03-31'];
}

function resolveStepApprover($pdo, $step, $letter) {
    if ($step['approver_type'] === 'specific_user') {
        return $step['user_id'];
    }
    if ($step['approver_type'] === 'department_hod') {
        $stmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
        $stmt->execute([$step['department_id']]);
        $row = $stmt->fetch();
        return $row['hod_id'] ?? null;
    }
    if ($step['approver_type'] === 'letter_dept_hod') {
        $stmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
        $stmt->execute([$letter['department_id']]);
        $row = $stmt->fetch();
        return $row['hod_id'] ?? null;
    }
    return null;
}

/** कार्यप्रवाहातली आधीची पायरी (नसेल तर 0 — म्हणजे तयार करणारा) */
function letterPreviousWorkflowStepOrder($pdo, $letter) {
    $stmt = $pdo->prepare("SELECT MAX(step_order) FROM workflow_steps WHERE workflow_template_id = ? AND step_order < ?");
    $stmt->execute([$letter['workflow_template_id'], (int)$letter['current_step_order']]);
    return (int)$stmt->fetchColumn();
}

/** कार्यप्रवाहात सध्याच्या पायरीनंतर आणखी पायरी आहे का */
function letterHasNextWorkflowStep($pdo, $letter) {
    if (empty($letter['workflow_template_id'])) { return false; }
    $stmt = $pdo->prepare("SELECT 1 FROM workflow_steps WHERE workflow_template_id = ? AND step_order > ? LIMIT 1");
    $stmt->execute([$letter['workflow_template_id'], (int)$letter['current_step_order']]);
    return (bool)$stmt->fetchColumn();
}

/**
 * पत्र कार्यप्रवाहाच्या पुढच्या पायरीकडे पाठवते.
 * परत: ['holder' => int|null, 'error' => string|null]
 * शेवटच्या पायरीवर पत्र इथून पूर्ण होत नाही — ते फक्त Sign & Approve ने
 * (ओळख पडताळून) पूर्ण होते. पुढच्या पायरीचा अधिकारी ठरत नसेल (उदा.
 * विभागाला HOD नाही) तर पत्र पुढे सरकवत नाही — कोणाकडेच नसलेलं, अडकलेलं
 * पत्र तयार होऊ नये म्हणून.
 */
function advanceLetterToNextStep($pdo, $letter) {
    // पायरी Delete झाल्यामुळे क्रमांकात खंड (उदा. 1, 3) असला तरी पुढची पायरी सापडावी
    $stmt = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_template_id = ? AND step_order > ? ORDER BY step_order LIMIT 1");
    $stmt->execute([$letter['workflow_template_id'], (int)$letter['current_step_order']]);
    $nextStep = $stmt->fetch();

    if (!$nextStep) {
        if ((int)$letter['current_step_order'] === 0) {
            return ['holder' => null, 'error' => 'या कार्यप्रवाहात एकही पायरी नाही — Super Admin ला "कार्यप्रवाह" मध्ये पायऱ्या जोडायला सांगा.'];
        }
        return ['holder' => null, 'error' => 'ही कार्यप्रवाहाची शेवटची पायरी आहे — पत्र पूर्ण करण्यासाठी "Sign & Approve" वापरा.'];
    }

    $approver = resolveStepApprover($pdo, $nextStep, $letter);
    if (!$approver) {
        return ['holder' => null, 'error' => "\"{$nextStep['step_name']}\" या पायरीसाठी कोणीही नेमलेले नाही (विभागाला HOD नाही) — Super Admin ला \"कार्यप्रवाह\" दुरुस्त करायला सांगा."];
    }

    $pdo->prepare("UPDATE letters SET status='in_progress', current_step_order=?, current_holder_id=?, current_holder_seen_at=NULL WHERE id=?")
        ->execute([(int)$nextStep['step_order'], $approver, $letter['id']]);
    return ['holder' => (int)$approver, 'error' => null];
}

/**
 * तयार करणाऱ्या विभागाचा जावक क्रमांक — DEPT_CODE/YEAR/०००१ स्वरूपात.
 * त्याच विभागाचे, त्याच वर्षाचे आधीचे जावक क्रमांक मोजून पुढचा क्रमांक देतो.
 */
function generateOutwardNumber($pdo, $orgId, $departmentId) {
    $fyLabel = getFinancialYearLabel();
    [$fyStart, $fyEnd] = getFinancialYearRange();
    $deptStmt = $pdo->prepare("SELECT code, organization_id FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptRow = $deptStmt->fetch();
    $deptCode = $deptRow['code'] ?? 'GEN';
    // Always count within the department's OWN organization — not whichever
    // org happened to be "active" when this was called — so numbering stays
    // correct even for letters that started in a different (sister) org.
    $deptOrgId = $deptRow['organization_id'] ?? $orgId;

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) c FROM letters
        WHERE organization_id = ? AND department_id = ? AND created_at BETWEEN ? AND ? AND outward_no IS NOT NULL
    ");
    $countStmt->execute([$deptOrgId, $departmentId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
    $seq = (int)$countStmt->fetch()['c'] + 1;

    // एखादं पत्र Delete झालं असेल तर COUNT मागे जातो — तोच जावक क्रमांक पुन्हा येऊ नये
    return generateUniqueCode($pdo, 'letters', 'outward_no', '%s/%s/%04d', $seq, $deptCode, $fyLabel);
}

/**
 * एखाद्या विभागाला ही फाईल पहिल्यांदाच येत असेल तर त्यांचा स्वतःचा आवक
 * क्रमांक तयार करून नोंदवते. आधीच त्या विभागाला क्रमांक मिळालेला असेल
 * (फाईल परत-परत त्यांच्याकडे आली तरी) तर काहीही नवीन तयार होत नाही.
 */
function assignInwardNumberIfNeeded($pdo, $letterId, $departmentId, $orgId) {
    if (!$departmentId) return;

    $check = $pdo->prepare("SELECT id FROM letter_inward_numbers WHERE letter_id = ? AND department_id = ?");
    $check->execute([$letterId, $departmentId]);
    if ($check->fetch()) return; // already has one

    $fyRange = getFinancialYearRange();
    $fyStartYear = (int)date('Y', strtotime($fyRange[0]));
    $fyLabel = getFinancialYearLabel();
    $deptStmt = $pdo->prepare("SELECT code, organization_id FROM departments WHERE id = ?");
    $deptStmt->execute([$departmentId]);
    $deptRow = $deptStmt->fetch();
    $deptCode = $deptRow['code'] ?? 'GEN';
    // Count within the department's OWN organization — this is what keeps
    // numbering correct when the letter arrived here from a sister
    // organization (its own numbering has nothing to do with the sender's).
    $deptOrgId = $deptRow['organization_id'] ?? $orgId;

    $countStmt = $pdo->prepare("
        SELECT COUNT(*) c FROM letter_inward_numbers lin
        JOIN departments d ON d.id = lin.department_id
        WHERE d.organization_id = ? AND lin.department_id = ? AND lin.year = ?
    ");
    $countStmt->execute([$deptOrgId, $departmentId, $fyStartYear]);
    $seq = (int)$countStmt->fetch()['c'] + 1;
    $inwardNo = generateUniqueCode($pdo, 'letter_inward_numbers', 'inward_no', '%s/%s/%04d', $seq, $deptCode, $fyLabel);
    $seq = (int)substr($inwardNo, strrpos($inwardNo, '/') + 1);

    try {
        $pdo->prepare("INSERT INTO letter_inward_numbers (letter_id, department_id, inward_no, year, sequence_no) VALUES (?, ?, ?, ?, ?)")
            ->execute([$letterId, $departmentId, $inwardNo, $fyStartYear, $seq]);
    } catch (PDOException $e) {
        // Unique constraint race — another request already assigned one; ignore.
    }
}

/**
 * होल्डरचा विभाग ठरवून आवश्यक असल्यास त्या विभागाला आवक क्रमांक देते.
 * फाईलच्या मूळ (निर्माण करणाऱ्या) विभागाला आवक क्रमांक लागत नाही — त्यांना
 * आधीच जावक क्रमांक मिळालेला आहे.
 */
function assignInwardForHolder($pdo, $letterId, $holderUserId, $originDepartmentId, $orgId) {
    if (!$holderUserId) return;
    $stmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
    $stmt->execute([$holderUserId]);
    $holderDeptId = $stmt->fetch()['department_id'] ?? null;
    if (!$holderDeptId || (int)$holderDeptId === (int)$originDepartmentId) return;
    assignInwardNumberIfNeeded($pdo, $letterId, $holderDeptId, $orgId);
}

/**
 * पत्र एखाद्या व्यक्तीकडे पाठवते — Holder बदलतो, हालचालीची नोंद करतो,
 * त्या व्यक्तीच्या विभागाला आवक क्रमांक देतो आणि Notification पाठवतो.
 * ($holderAlreadySet = true — कार्यप्रवाहाने Holder आधीच बदलला असेल तेव्हा)
 */
function letterSendTo($pdo, $letter, $toUserId, $fromUserId, $actionType, $remark, $orgId, $holderAlreadySet = false) {
    if (!$holderAlreadySet) {
        $pdo->prepare("UPDATE letters SET status='in_progress', current_holder_id=?, current_holder_seen_at=NULL WHERE id=?")
            ->execute([$toUserId, $letter['id']]);
    }
    $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, ?, ?, ?, ?)")
        ->execute([$letter['id'], $actionType, $fromUserId, $toUserId, $remark ?: null]);
    assignInwardForHolder($pdo, $letter['id'], $toUserId, $letter['department_id'], $orgId);
    notifyUser($toUserId, $orgId, 'नवीन फाईल तुमच्याकडे आली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letter['id']);
}
