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

/**
 * Moves the letter to the next workflow step.
 * Returns an array: ['holder' => int|null, 'intended_recipient_id' => int|null,
 * 'clerk_stage' => string, 'completed' => bool, 'error' => string|null].
 * If the resolved approver has their own Clerk (gatekeeper_clerk_id), the
 * file is routed to the Clerk first — exactly like manual forwarding does —
 * so Workflow-driven letters respect Clerk-Gatekeeper too, not just manual ones.
 * If the next step's approver cannot be resolved (e.g. that department has no
 * HOD assigned), the letter is deliberately NOT advanced — it stays with the
 * current holder and an error is returned — instead of silently going stuck
 * with no one able to act on it.
 */
function advanceLetterToNextStep($pdo, $letter, $orgId) {
    if (!$letter['workflow_template_id']) {
        return ['holder' => null, 'intended_recipient_id' => null, 'clerk_stage' => 'none', 'completed' => false, 'error' => null];
    }
    $nextOrder = (int)$letter['current_step_order'] + 1;
    $stmt = $pdo->prepare("SELECT * FROM workflow_steps WHERE workflow_template_id = ? AND step_order = ?");
    $stmt->execute([$letter['workflow_template_id'], $nextOrder]);
    $nextStep = $stmt->fetch();

    if (!$nextStep) {
        // No more steps — letter is complete
        $pdo->prepare("UPDATE letters SET status='completed', current_holder_id=NULL WHERE id=?")->execute([$letter['id']]);
        return ['holder' => null, 'intended_recipient_id' => null, 'clerk_stage' => 'none', 'completed' => true, 'error' => null];
    }

    $resolvedApprover = resolveStepApprover($pdo, $nextStep, $letter);
    if (!$resolvedApprover) {
        // Can't resolve an approver — do NOT advance (avoids a stuck, ownerless file)
        return ['holder' => null, 'intended_recipient_id' => null, 'clerk_stage' => 'none', 'completed' => false,
            'error' => "\"{$nextStep['step_name']}\" या पायरीसाठी कोणीही नेमलेले नाही (विभागाला HOD नाही) — Super Admin ला \"कार्यप्रवाह टेम्प्लेट\" मधून दुरुस्त करायला सांगा, किंवा खालून थेट एखाद्या व्यक्तीकडे पाठवा."];
    }

    // हा Approver Gatekept आहे का (त्याचा स्वतःचा Clerk नेमलेला आहे का) —
    // असेल तर आधी Clerk कडेच जाईल, Workflow मध्येही तेच नियम लागू.
    $resolved = resolveHolderForRecipient($pdo, $resolvedApprover);

    $pdo->prepare("UPDATE letters SET status='in_progress', current_step_order=?, current_holder_id=?, intended_recipient_id=?, clerk_stage=?, current_holder_seen_at=NULL WHERE id=?")
        ->execute([$nextOrder, $resolved['holder_id'], $resolved['intended_recipient_id'], $resolved['clerk_stage'], $letter['id']]);
    return ['holder' => $resolved['holder_id'], 'intended_recipient_id' => $resolved['intended_recipient_id'], 'clerk_stage' => $resolved['clerk_stage'], 'completed' => false, 'error' => null];
}

/**
 * एखाद्या व्यक्तीला (उदा. Executive President) पत्र पाठवायचं असेल, आणि
 * त्यांचा स्वतःचा Clerk नेमलेला असेल, तर फाईल तांत्रिकदृष्ट्या आधी Clerk
 * कडे जाते — पण "खरं कोणासाठी आहे" हे intended_recipient_id मध्ये राहतं.
 * Clerk नेमलेला नसेल तर थेट त्या व्यक्तीकडेच जाते (जुनी पद्धत).
 */
function resolveHolderForRecipient($pdo, $intendedUserId) {
    if (!$intendedUserId) {
        return ['holder_id' => null, 'intended_recipient_id' => null, 'clerk_stage' => 'none'];
    }
    $stmt = $pdo->prepare("SELECT gatekeeper_clerk_id FROM users WHERE id = ?");
    $stmt->execute([$intendedUserId]);
    $clerkId = $stmt->fetch()['gatekeeper_clerk_id'] ?? null;

    if ($clerkId) {
        return ['holder_id' => $clerkId, 'intended_recipient_id' => $intendedUserId, 'clerk_stage' => 'awaiting_inward'];
    }
    return ['holder_id' => $intendedUserId, 'intended_recipient_id' => null, 'clerk_stage' => 'none'];
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

    return sprintf('%s/%s/%04d', $deptCode, $fyLabel, $seq);
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
    $inwardNo = sprintf('%s/%s/%04d', $deptCode, $fyLabel, $seq);

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
