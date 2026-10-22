<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
$user = currentUser();

$orgId = activeOrgId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;
    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : ($user['department_id'] ?? null);
    $priority = $_POST['priority'] ?? 'normal';

    // ---------------- Bulk Send (एकाच वेळी अनेक विभागांना — परिपत्रक) ----------------
    $bulkDeptIds = array_filter(array_map('intval', $_POST['bulk_department_ids'] ?? []));
    if (!empty($bulkDeptIds) && $subject) {
        $batchRef = 'BATCH-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $sentCount = 0;
        foreach ($bulkDeptIds as $bDeptId) {
            $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ? AND organization_id = ?");
            $hodStmt->execute([$bDeptId, $orgId]);
            $hodId = (int)($hodStmt->fetch()['hod_id'] ?? 0) ?: null;

            [$fyStart, $fyEnd] = getFinancialYearRange();
            $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ?");
            $countStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
            $seq = $countStmt->fetch()['c'] + 1;
            $fileNumber = sprintf('FILE-%s%04d', getFinancialYearLabel(), $seq);
            $outwardNo = generateOutwardNumber($pdo, $orgId, $departmentId);

            $status = $hodId ? 'in_progress' : 'draft';
            $insert = $pdo->prepare("
                INSERT INTO letters (organization_id, file_number, outward_no, batch_ref, subject, description, category_id, department_id, priority, status, current_holder_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$orgId, $fileNumber, $outwardNo, $batchRef, $subject, $description, $categoryId, $departmentId, $priority, $status, $hodId, $user['id']]);
            $newLetterId = $pdo->lastInsertId();

            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'created', ?, ?)")
                ->execute([$newLetterId, $user['id'], 'परिपत्रक तयार केलं — ' . $fileNumber . ' (Batch: ' . $batchRef . ')']);

            if ($hodId) {
                assignInwardForHolder($pdo, $newLetterId, $hodId, $bDeptId, $orgId);
                notifyUser($hodId, $orgId, 'नवीन परिपत्रक/सूचना आली', $subject . ' — ' . $fileNumber, 'letters/view.php?id=' . $newLetterId);
                $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id) VALUES (?, 'submitted', ?, ?)")
                    ->execute([$newLetterId, $user['id'], $hodId]);
            }
            $sentCount++;
        }
        logActivity('परिपत्रक Bulk पाठवलं', 'letter', null, "$sentCount विभागांना — $subject (Batch: $batchRef)");
        $_SESSION['flash'] = "✅ \"$subject\" — $sentCount विभागांना यशस्वीरित्या पाठवलं गेलं.";
        header('Location: batch-view.php?ref=' . urlencode($batchRef));
        exit;
    }

    $workflowId = ($_POST['workflow_template_id'] ?? '') !== '' ? (int)$_POST['workflow_template_id'] : null;

    // प्राप्तकर्ता — शाखा-संस्थेतील कोणी निवडलं असेल तर तेच प्राधान्याने
    // वापरतो; नाहीतर "कोणाकडे पाठवायचं" च्या पद्धतीनुसार (व्यक्ती/HOD/
    // संपूर्ण विभाग) ठरवतो.
    $recipientMode = $_POST['recipient_mode'] ?? 'person';
    $targetDeptId = ($_POST['target_department_id'] ?? '') !== '' ? (int)$_POST['target_department_id'] : null;
    $sisterToUserId = ($_POST['sister_to_user_id'] ?? '') !== '' ? (int)$_POST['sister_to_user_id'] : null;
    $notifyWholeDept = false;

    if ($sisterToUserId) {
        $toUserId = $sisterToUserId;
    } elseif ($recipientMode === 'hod' && $targetDeptId) {
        $hodLookup = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ? AND organization_id = ?");
        $hodLookup->execute([$targetDeptId, $orgId]);
        $toUserId = (int)($hodLookup->fetch()['hod_id'] ?? 0) ?: null;
    } elseif ($recipientMode === 'all' && $targetDeptId) {
        $hodLookup = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ? AND organization_id = ?");
        $hodLookup->execute([$targetDeptId, $orgId]);
        $toUserId = (int)($hodLookup->fetch()['hod_id'] ?? 0) ?: null;
        $notifyWholeDept = true;
    } else {
        $toUserId = ($_POST['to_user_id'] ?? '') !== '' ? (int)$_POST['to_user_id'] : null;
    }

    $referenceNo = trim($_POST['reference_no'] ?? '') ?: null;
    $parentLetterId = ($_POST['parent_letter_id'] ?? '') !== '' ? (int)$_POST['parent_letter_id'] : null;
    $isBudgetExpense = isset($_POST['is_budget_expense']) ? 1 : 0;
    $budgetCategory = $isBudgetExpense ? ($_POST['budget_category'] ?? null) : null;
    $requestedAmount = ($isBudgetExpense && ($_POST['requested_amount'] ?? '') !== '') ? (float)$_POST['requested_amount'] : null;

    if (!$subject) {
        $error = 'कृपया विषय भरा.';
    } else {
        // Generate a unique file number: FILE-FYlabel-0001 (आर्थिक वर्षानुसार, एप्रिल-मार्च)
        [$fyStart, $fyEnd] = getFinancialYearRange();
        $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ?");
        $countStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
        $seq = $countStmt->fetch()['c'] + 1;
        $fileNumber = sprintf('FILE-%s%04d', getFinancialYearLabel(), $seq);
        $outwardNo = $departmentId ? generateOutwardNumber($pdo, $orgId, $departmentId) : null;

        // If a recipient was chosen directly (quick "पत्र पाठवा" flow), send
        // immediately instead of leaving it as an unsent draft. If that
        // recipient has a Clerk/Gatekeeper, the file technically lands with
        // the Clerk first for registration — not directly with the Executive.
        $resolved = resolveHolderForRecipient($pdo, $toUserId);
        $status = $toUserId ? 'in_progress' : 'draft';
        $currentHolder = $resolved['holder_id'];

        $insert = $pdo->prepare("
            INSERT INTO letters (organization_id, file_number, outward_no, reference_no, parent_letter_id, is_budget_expense, budget_category, requested_amount, subject, description, category_id, department_id, priority, status, workflow_template_id, current_holder_id, intended_recipient_id, clerk_stage, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$orgId, $fileNumber, $outwardNo, $referenceNo, $parentLetterId, $isBudgetExpense, $budgetCategory, $requestedAmount, $subject, $description, $categoryId, $departmentId, $priority, $status, $workflowId, $currentHolder, $resolved['intended_recipient_id'], $resolved['clerk_stage'], $user['id']]);
        $letterId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'created', ?, ?)")
            ->execute([$letterId, $user['id'], 'फाईल तयार केली — ' . $fileNumber . ($outwardNo ? " (जावक क्र.: $outwardNo)" : '')]);

        if ($toUserId) {
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'submitted', ?, ?, ?)")
                ->execute([$letterId, $user['id'], $currentHolder, null]);
            if ($resolved['clerk_stage'] === 'awaiting_inward') {
                $recStmt = $pdo->prepare("SELECT name FROM users WHERE id = ?");
                $recStmt->execute([$toUserId]);
                $recName = $recStmt->fetch()['name'] ?? '';
                notifyUser($currentHolder, $orgId, 'नोंदणीसाठी नवीन पत्र', "$recName यांच्यासाठी — $subject ($fileNumber)", 'letters/view.php?id=' . $letterId);
            } else {
                assignInwardForHolder($pdo, $letterId, $currentHolder, $departmentId, $orgId);
                notifyUser($currentHolder, $orgId, 'नवीन फाईल तुमच्याकडे आली', $subject . ' — ' . $fileNumber, 'letters/view.php?id=' . $letterId);
            }
        }

        logActivity('पत्र/फाईल तयार केली', 'letter', $letterId, $fileNumber);

        // "संपूर्ण विभागाला" निवडलं असेल तर — फाईल HOD कडे जातेच, पण
        // विभागातल्या इतर सगळ्यांनाही (HOD सोडून) कळवतो.
        if ($notifyWholeDept && $targetDeptId) {
            $deptStaffStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND department_id = ? AND is_active = 1");
            $deptStaffStmt->execute([$orgId, $targetDeptId]);
            foreach ($deptStaffStmt->fetchAll(PDO::FETCH_COLUMN) as $deptUserId) {
                if ((int)$deptUserId === (int)$toUserId) continue; // HOD ला आधीच "नवीन फाईल तुमच्याकडे आली" गेलेलं आहे
                notifyUser($deptUserId, $orgId, 'विभागाला नवीन पत्र आलं', $subject . ' — ' . $fileNumber, 'letters/view.php?id=' . $letterId);
            }
        }

        // CC / Watchers — added at creation time
        $ccIds = $_POST['cc_user_ids'] ?? [];
        foreach ($ccIds as $ccId) {
            $ccId = (int)$ccId;
            if ($ccId && $ccId !== $user['id']) {
                try {
                    $pdo->prepare("INSERT INTO letter_watchers (letter_id, user_id, added_by) VALUES (?, ?, ?)")->execute([$letterId, $ccId, $user['id']]);
                    notifyUser($ccId, $orgId, 'फाईलमध्ये CC केले गेले', $subject . ' — ' . $fileNumber, 'letters/view.php?id=' . $letterId);
                } catch (PDOException $e) {}
            }
        }

        $_SESSION['flash'] = $toUserId ? "'$fileNumber' फाईल तयार होऊन पाठवली गेली." : "'$fileNumber' फाईल तयार झाली (Draft) — आता Submit करून पाठवा.";
        header('Location: view.php?id=' . $letterId);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM letter_categories WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
$stmt->execute([$orgId]);
$campusesForLetters = $stmt->fetchAll();
$showCampusPicker = count($campusesForLetters) > 1;

$stmt = $pdo->prepare("SELECT * FROM workflow_templates WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$workflows = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation, d.name_local AS dept_name FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY d.name_local, u.name
");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

// CC/Watchers साठी — फक्त विभाग-प्रमुख (HOD) निवडता यावेत
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation FROM users u
    JOIN role_definitions rd ON rd.role_key = u.role
    WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'hod'
    ORDER BY u.name
");
$stmt->execute([$orgId]);
$hodPool = $stmt->fetchAll();

// Sister organizations — same parent Trust family (parent, siblings, or
// children) — so letters can smoothly reach their staff/Executives too.
$myOrgStmt = $pdo->prepare("SELECT parent_organization_id FROM organizations WHERE id = ?");
$myOrgStmt->execute([$orgId]);
$myParentId = $myOrgStmt->fetch()['parent_organization_id'] ?? null;

$sisterOrgsStmt = $pdo->prepare("
    SELECT * FROM organizations
    WHERE is_active = 1 AND id != ? AND (
        id = ? OR parent_organization_id = ? OR (? IS NOT NULL AND parent_organization_id = ?)
    )
    ORDER BY name
");
$sisterOrgsStmt->execute([$orgId, $myParentId, $orgId, $myParentId, $myParentId]);
$sisterOrgs = $sisterOrgsStmt->fetchAll();

$sisterStaffByOrg = [];
foreach ($sisterOrgs as $sOrg) {
    $sStmt = $pdo->prepare("
        SELECT u.id, u.name, u.designation, d.name_local AS dept_name FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY d.name_local, u.name
    ");
    $sStmt->execute([$sOrg['id']]);
    $sisterStaffByOrg[$sOrg['id']] = ['org' => $sOrg, 'staff' => $sStmt->fetchAll()];
}

$preselectedUser = (int)($_GET['to_user'] ?? 0);
$preselectedDept = (int)($_GET['to_dept'] ?? 0);
if ($preselectedDept && !$preselectedUser) {
    $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ? AND organization_id = ?");
    $hodStmt->execute([$preselectedDept, $orgId]);
    $preselectedUser = (int)($hodStmt->fetch()['hod_id'] ?? 0);
}
$preselectedTargetDept = $preselectedDept;
$preselectedCampusId = null;
if ($preselectedTargetDept) {
    $pcStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ? AND organization_id = ?");
    $pcStmt->execute([$preselectedTargetDept, $orgId]);
    $preselectedCampusId = $pcStmt->fetch()['campus_id'] ?? null;
}

// स्वतःच्या विभागाचं नाव — फक्त दाखवण्यासाठी (Field आता Read-only आहे,
// कारण जावक क्रमांक नेहमी स्वतःच्याच विभागाच्या क्रमानुसार ठरतो — दुसऱ्या
// विभागाच्या नावाने चुकून पत्र तयार होऊ नये).
$ownDeptName = '—';
if (!empty($user['department_id'])) {
    $ownDeptStmt = $pdo->prepare("SELECT name_local, name FROM departments WHERE id = ? AND organization_id = ?");
    $ownDeptStmt->execute([$user['department_id'], $orgId]);
    $ownDept = $ownDeptStmt->fetch();
    if ($ownDept) { $ownDeptName = $ownDept['name_local'] ?: $ownDept['name']; }
}

$stmt = $pdo->prepare("SELECT * FROM letter_templates WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$templates = $stmt->fetchAll();

$pageTitle = 'नवीन पत्र / फाईल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">नवीन पत्र / फाईल तयार करा</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <?php if (!empty($templates)): ?>
      <div class="col-12">
        <label class="form-label-custom">Template वापरा (ऐच्छिक — पटकन भरण्यासाठी)</label>
        <select id="templateSelect" class="form-select-custom">
          <option value="">-- रिकाम्यापासून सुरू करा --</option>
          <?php foreach ($templates as $t): ?>
            <option value="<?= $t['id'] ?>"
              data-subject="<?= h($t['subject_template']) ?>"
              data-description="<?= h($t['description_template'] ?? '') ?>"
              data-category="<?= $t['category_id'] ?? '' ?>"
              data-workflow="<?= $t['workflow_template_id'] ?? '' ?>"><?= h($t['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <label class="form-label-custom">विषय</label>
        <input type="text" name="subject" id="subjectField" class="form-control-custom" required placeholder="उदा. नवीन जनरेटर खरेदीसाठी परवानगी अर्ज">
      </div>
      <div class="col-12">
        <label class="form-label-custom">संदर्भ क्रमांक (Reference No.) <span style="font-weight:400;color:var(--text-dim);font-size:11px;">— ऐच्छिक, जुन्या पत्राचा हवाला द्यायचा असल्यास</span></label>
        <input type="text" name="reference_no" class="form-control-custom" placeholder="उदा. MTN/2025/0034 किंवा इतर कुठलाही जुना संदर्भ">
      </div>

      <div class="col-12">
        <label class="form-label-custom">🔗 हे पत्र दुसऱ्या एखाद्या पत्राचं उत्तर/पुढचा भाग आहे का? <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label>
        <input type="text" id="parentLetterSearch" class="form-control-custom" placeholder="File क्रमांक किंवा विषयाने शोधा..." autocomplete="off">
        <input type="hidden" name="parent_letter_id" id="parentLetterId">
        <div id="parentLetterResults" style="position:relative;"></div>
        <div id="parentLetterSelected" style="display:none;background:var(--rail-soft);border-radius:8px;padding:8px 12px;margin-top:6px;font-size:12.5px;">
          <span id="parentLetterSelectedText"></span>
          <button type="button" onclick="eowmsClearParentLetter()" style="border:none;background:none;color:var(--rose);font-size:11px;margin-left:8px;">✕ काढा</button>
        </div>
      </div>

      <div class="col-12">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:var(--canvas);padding:12px 16px;border-radius:9px;">
          <input type="checkbox" name="is_budget_expense" value="1" id="isBudgetExpenseCheck" onclick="document.getElementById('budgetExpenseFields').style.display=this.checked?'flex':'none';">
          💰 ही विनंती खरेदी/देखभाल/खर्चासाठी आहे — मंजूर झाल्यावर विभागाच्या Budget मधून आपोआप वजा व्हावी
        </label>
      </div>
      <div class="col-12 row g-3" id="budgetExpenseFields" style="display:none;margin:0;">
        <div class="col-md-6">
          <label class="form-label-custom">कशासाठी (Budget श्रेणी)</label>
          <select name="budget_category" class="form-select-custom">
            <option value="नवीन खरेदी">🆕 नवीन खरेदी</option>
            <option value="देखभाल">🔧 देखभाल</option>
            <option value="इंधन">⛽ इंधन</option>
            <option value="इतर">📋 इतर</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label-custom">मागणी रक्कम (₹)</label>
          <input type="number" step="0.01" name="requested_amount" class="form-control-custom" placeholder="उदा. 50000">
        </div>
        <div class="col-12" style="font-size:11px;color:var(--text-dim);">Executive/Management ने पत्र मंजूर (पूर्ण) करताना अंतिम मंजूर रक्कम ठरवतील — तीच रक्कम विभागाच्या Budget मधून वजा होईल (मागणी रक्कमेपेक्षा वेगळी असू शकते).</div>
      </div>
      <div class="col-12">
        <label class="form-label-custom">वर्णन / तपशील</label>
        <textarea name="description" id="descriptionField" class="form-control-custom" rows="4"></textarea>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom">पत्र प्रकार</label>
        <select name="category_id" id="categoryField" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name_local'] ?: $c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">कोणत्या विभागाकडून <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(जावक क्र. यावरून ठरतो)</span></label>
        <input type="text" class="form-control-custom" value="<?= h($ownDeptName) ?>" disabled style="background:var(--canvas);">
        <input type="hidden" name="department_id" value="<?= h($user['department_id'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्राधान्य</label>
        <select name="priority" class="form-select-custom">
          <option value="normal" selected>सामान्य</option>
          <option value="low">कमी</option>
          <option value="high">जास्त</option>
          <option value="urgent">तातडीचे</option>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label-custom">कार्यप्रवाह (Workflow) — पत्र कोणत्या क्रमाने कोणाकडे जाईल (ऐच्छिक)</label>
        <select name="workflow_template_id" id="workflowField" class="form-select-custom">
          <option value="">-- नाही, थेट कोणालातरी पाठवा --</option>
          <?php foreach ($workflows as $w): ?><option value="<?= $w['id'] ?>"><?= h($w['name']) ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:var(--canvas);padding:12px 16px;border-radius:9px;">
          <input type="checkbox" id="bulkModeCheck" onclick="eowmsToggleBulkMode(this.checked)">
          📨 हे परिपत्रक/सूचना आहे — एकाच वेळी <strong>अनेक विभागांना</strong> पाठवायची आहे
        </label>
      </div>

      <div class="col-12" id="bulkDeptBlock" style="display:none;background:var(--canvas);border-radius:10px;padding:14px 16px;">
        <label class="form-label-custom">कोणकोणत्या विभागांना पाठवायचं</label>
        <div class="row g-1">
          <?php foreach ($departments as $d): ?>
            <div class="col-md-4">
              <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;padding:4px 0;">
                <input type="checkbox" name="bulk_department_ids[]" value="<?= $d['id'] ?>" style="width:15px;height:15px;">
                <?= h($d['name_local'] ?: $d['name']) ?>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">प्रत्येक निवडलेल्या विभागासाठी — स्वतंत्र File तयार होईल (त्या विभागाच्या HOD कडे) — सगळ्या Files एकाच "Batch" मध्ये एकत्र सापडतील.</div>
      </div>

      <div class="col-12" id="directSendBlock">
        <label class="form-label-custom">📤 कोणत्या विभागाला पत्र पाठवायचं (Workflow निवडलं नसेल तर)</label>

        <?php if ($showCampusPicker): ?>
          <select id="targetCampusSelect" class="form-select-custom mb-2" onchange="eowmsOnTargetCampusChange(this.value)">
            <option value="">-- आधी College/School/Trust निवडा --</option>
            <?php foreach ($campusesForLetters as $c): ?>
              <option value="<?= $c['id'] ?>" <?= $preselectedCampusId === (int)$c['id'] ? 'selected' : '' ?>><?= $c['college_type'] === 'Trust HQ' ? '🏛️ ' : '🏫 ' ?><?= h($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <select id="targetDeptSelect" name="target_department_id" class="form-select-custom mb-2" onchange="eowmsOnTargetDeptChange(this.value)" disabled>
            <option value="">-- आधी वरतून College/School निवडा --</option>
          </select>
        <?php else: ?>
          <select id="targetDeptSelect" name="target_department_id" class="form-select-custom mb-2" onchange="eowmsOnTargetDeptChange(this.value)">
            <option value="">-- आधी विभाग निवडा --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= $preselectedTargetDept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>

        <div id="recipientTypeBlock" style="<?= $preselectedTargetDept ? '' : 'display:none;' ?>background:var(--canvas);border-radius:10px;padding:14px 16px;">
          <label class="form-label-custom">कोणाकडे पाठवायचं</label>
          <div class="d-flex gap-3 mb-2 flex-wrap" style="font-size:12.5px;">
            <label style="display:flex;align-items:center;gap:5px;"><input type="radio" name="recipient_mode" value="person" checked onclick="eowmsToggleRecipientMode('person')"> 🎯 विशिष्ट व्यक्ती</label>
            <label style="display:flex;align-items:center;gap:5px;"><input type="radio" name="recipient_mode" value="hod" onclick="eowmsToggleRecipientMode('hod')"> 👔 फक्त HOD कडे</label>
            <label style="display:flex;align-items:center;gap:5px;"><input type="radio" name="recipient_mode" value="all" onclick="eowmsToggleRecipientMode('all')"> 📢 संपूर्ण विभागाला (सगळ्यांना कळेल)</label>
          </div>

          <div id="personPickerBlock">
            <select name="to_user_id" id="targetEmployeeSelect" class="form-select-custom">
              <option value="">-- आधी विभाग निवडा --</option>
            </select>
          </div>
          <div id="allDeptNote" style="display:none;font-size:11.5px;color:var(--text-dim);">📢 फाईल विभाग-प्रमुखांकडे (HOD) जाईल, आणि विभागातल्या सगळ्यांना त्याची सूचना (Notification) मिळेल.</div>
        </div>

        <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">काहीही न निवडल्यास — पत्र Draft म्हणून जतन होईल, नंतर कधीही पाठवता येईल.</div>
      </div>

      <?php if (!empty($sisterStaffByOrg)): ?>
      <div class="col-12">
        <label class="form-label-custom">🏢 किंवा — शाखा-संस्थेतील (Sister Organization) कोणाला पाठवायचं <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक, वरच्याऐवजी)</span></label>
        <select name="sister_to_user_id" id="sisterOrgSelect" class="form-select-custom">
          <option value="">-- नाही --</option>
          <?php foreach ($sisterStaffByOrg as $sData): if (empty($sData['staff'])) continue;
            $byDept = [];
            foreach ($sData['staff'] as $s) { $byDept[$s['dept_name'] ?: 'इतर'][] = $s; }
          ?>
            <?php foreach ($byDept as $deptName => $deptStaff): ?>
              <optgroup label="🏢 <?= h($sData['org']['name']) ?> — <?= h($deptName) ?>">
                <?php foreach ($deptStaff as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= h($s['name']) ?><?= $s['designation'] ? ' (' . h($s['designation']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">तिथे पत्र पाठवलं तरी त्यांचा इतर कुठलाही डेटा (staff/salary/इ.) तुम्हाला दिसणार नाही.</div>
      </div>
      <?php endif; ?>

      <div class="col-12">
        <label class="form-label-custom">CC करा (फक्त माहितीसाठी — त्यांना फाईल दिसेल, पण कृती करण्याची गरज नाही)</label>
        <div id="ccChipsBox" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;"></div>

        <?php if ($showCampusPicker): ?>
          <div class="row g-2">
            <div class="col-md-4">
              <select id="ccCampusSelect" class="form-select-custom" onchange="eowmsOnCcCampusChange(this.value)">
                <option value="">-- College/School/Trust --</option>
                <?php foreach ($campusesForLetters as $c): ?>
                  <option value="<?= $c['id'] ?>"><?= $c['college_type'] === 'Trust HQ' ? '🏛️ ' : '🏫 ' ?><?= h($c['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-4">
              <select id="ccDeptSelect" class="form-select-custom" onchange="eowmsOnCcDeptChange(this.value)" disabled>
                <option value="">-- आधी College/School निवडा --</option>
              </select>
            </div>
            <div class="col-md-4">
              <select id="ccEmpSelect" class="form-select-custom" disabled>
                <option value="">-- आधी विभाग निवडा --</option>
              </select>
            </div>
          </div>
        <?php else: ?>
          <div class="row g-2">
            <div class="col-md-6">
              <select id="ccDeptSelect" class="form-select-custom" onchange="eowmsOnCcDeptChange(this.value)">
                <option value="">-- विभाग निवडा --</option>
                <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <select id="ccEmpSelect" class="form-select-custom" disabled>
                <option value="">-- आधी विभाग निवडा --</option>
              </select>
            </div>
          </div>
        <?php endif; ?>
        <button type="button" onclick="eowmsAddCcFromPicker()" class="btn btn-sm mt-2" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:12px;padding:6px 16px;">+ CC मध्ये जोडा</button>

        <div style="margin-top:10px;">
          <label class="form-label-custom" style="font-size:11.5px;">किंवा — पटकन कुठल्याही विभाग-प्रमुखाला (HOD) जोडा</label>
          <select id="ccHodQuickAdd" class="form-select-custom" onchange="if(this.value){eowmsAddCcChip(this.value, this.options[this.selectedIndex].text); this.value='';}">
            <option value="">-- HOD निवडा --</option>
            <?php foreach ($hodPool as $s): ?>
              <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div id="ccHiddenInputs"></div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
        <button type="submit" class="btn" id="createSubmitBtn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= $preselectedUser ? 'पत्र पाठवा' : 'Draft म्हणून जतन करा' ?></button>
      </div>
    </div>
  </form>
</div>

<script>
var templateSelect = document.getElementById('templateSelect');
if (templateSelect) {
  templateSelect.addEventListener('change', function () {
    var opt = this.options[this.selectedIndex];
    if (!opt.value) return;
    document.getElementById('subjectField').value = opt.dataset.subject || '';
    document.getElementById('descriptionField').value = opt.dataset.description || '';
    if (opt.dataset.category) document.getElementById('categoryField').value = opt.dataset.category;
    if (opt.dataset.workflow) document.getElementById('workflowField').value = opt.dataset.workflow;
  });
}
document.querySelector('select[name="to_user_id"]').addEventListener('change', function () {
  document.getElementById('createSubmitBtn').textContent = this.value ? 'पत्र पाठवा' : 'Draft म्हणून जतन करा';
});

var parentSearchTimer = null;
document.getElementById('parentLetterSearch').addEventListener('input', function () {
  var q = this.value;
  clearTimeout(parentSearchTimer);
  var resultsBox = document.getElementById('parentLetterResults');
  if (q.length < 2) { resultsBox.innerHTML = ''; return; }
  parentSearchTimer = setTimeout(function () {
    fetch('../ajax-cascade.php?action=search_letters&q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var letters = data.letters || [];
        if (letters.length === 0) { resultsBox.innerHTML = '<div style="font-size:11.5px;color:var(--text-dim);padding:6px 0;">काहीही सापडलं नाही</div>'; return; }
        resultsBox.innerHTML = '<div style="position:absolute;z-index:10;background:#fff;border:1px solid var(--line);border-radius:9px;box-shadow:0 8px 20px rgba(0,0,0,.1);width:100%;max-height:220px;overflow-y:auto;">' +
          letters.map(function (l) {
            return '<div class="eowms-parent-opt" data-id="' + l.id + '" data-label="' + l.file_number.replace(/"/g,'&quot;') + ' — ' + l.subject.replace(/"/g,'&quot;') + '" style="padding:9px 14px;cursor:pointer;font-size:12.5px;border-bottom:1px solid var(--line);"><strong>' + l.file_number + '</strong><br><span style="color:var(--text-dim);">' + l.subject + '</span></div>';
          }).join('') + '</div>';
        document.querySelectorAll('.eowms-parent-opt').forEach(function (opt) {
          opt.addEventListener('click', function () {
            document.getElementById('parentLetterId').value = this.dataset.id;
            document.getElementById('parentLetterSelectedText').textContent = '🔗 ' + this.dataset.label;
            document.getElementById('parentLetterSelected').style.display = 'block';
            document.getElementById('parentLetterSearch').style.display = 'none';
            resultsBox.innerHTML = '';
          });
        });
      });
  }, 300);
});
function eowmsClearParentLetter() {
  document.getElementById('parentLetterId').value = '';
  document.getElementById('parentLetterSelected').style.display = 'none';
  document.getElementById('parentLetterSearch').style.display = 'block';
  document.getElementById('parentLetterSearch').value = '';
}

function eowmsToggleBulkMode(isBulk) {
  document.getElementById('bulkDeptBlock').style.display = isBulk ? 'block' : 'none';
  document.getElementById('directSendBlock').style.display = isBulk ? 'none' : 'block';
  document.getElementById('workflowField').disabled = isBulk;
  document.getElementById('createSubmitBtn').textContent = isBulk ? 'सगळ्यांना पाठवा' : (document.getElementById('createSubmitBtn').textContent);
}

function eowmsOnTargetCampusChange(campusId, preselectDeptId, preselectUserId) {
  var deptSelect = document.getElementById('targetDeptSelect');
  var recipientBlock = document.getElementById('recipientTypeBlock');
  recipientBlock.style.display = 'none';
  if (!campusId) {
    deptSelect.innerHTML = '<option value="">-- आधी वरतून College/School निवडा --</option>';
    deptSelect.disabled = true;
    return;
  }
  deptSelect.disabled = true;
  deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var depts = data.departments || [];
      if (depts.length === 0) {
        deptSelect.innerHTML = '<option value="">-- या ठिकाणी कुठलाही विभाग नाही --</option>';
        return;
      }
      deptSelect.disabled = false;
      deptSelect.innerHTML = '<option value="">-- विभाग निवडा --</option>' + depts.map(function (d) {
        return '<option value="' + d.id + '"' + (preselectDeptId && String(d.id) === String(preselectDeptId) ? ' selected' : '') + '>' + d.name + '</option>';
      }).join('');
      if (preselectDeptId) { eowmsOnTargetDeptChange(preselectDeptId, preselectUserId); }
    });
}

// ---------------- CC/Watchers — Campus→Department→Employee Cascade + Chips ----------------
var eowmsCcAdded = {}; // duplicate टाळण्यासाठी — { userId: true }

function eowmsOnCcCampusChange(campusId) {
  var deptSelect = document.getElementById('ccDeptSelect');
  var empSelect = document.getElementById('ccEmpSelect');
  empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
  empSelect.disabled = true;
  if (!campusId) {
    deptSelect.innerHTML = '<option value="">-- आधी College/School निवडा --</option>';
    deptSelect.disabled = true;
    return;
  }
  deptSelect.disabled = true;
  deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var depts = data.departments || [];
      if (depts.length === 0) { deptSelect.innerHTML = '<option value="">-- विभाग नाही --</option>'; return; }
      deptSelect.disabled = false;
      deptSelect.innerHTML = '<option value="">-- विभाग निवडा --</option>' + depts.map(function (d) {
        return '<option value="' + d.id + '">' + d.name + '</option>';
      }).join('');
    });
}

function eowmsOnCcDeptChange(deptId) {
  var empSelect = document.getElementById('ccEmpSelect');
  if (!deptId) {
    empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
    empSelect.disabled = true;
    return;
  }
  empSelect.disabled = true;
  empSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staff = data.staff || [];
      if (staff.length === 0) { empSelect.innerHTML = '<option value="">-- कोणी सक्रिय कर्मचारी नाही --</option>'; return; }
      empSelect.disabled = false;
      empSelect.innerHTML = '<option value="">-- व्यक्ती निवडा --</option>' + staff.map(function (s) {
        return '<option value="' + s.id + '">' + s.name + (s.designation ? ' (' + s.designation + ')' : '') + '</option>';
      }).join('');
    });
}

function eowmsAddCcFromPicker() {
  var empSelect = document.getElementById('ccEmpSelect');
  if (!empSelect.value) return;
  eowmsAddCcChip(empSelect.value, empSelect.options[empSelect.selectedIndex].text);
  empSelect.value = '';
}

function eowmsAddCcChip(userId, label) {
  if (eowmsCcAdded[userId]) return; // आधीच जोडलेलं आहे
  eowmsCcAdded[userId] = true;

  var chip = document.createElement('span');
  chip.style.cssText = 'display:inline-flex;align-items:center;gap:6px;background:var(--rail-soft);color:var(--rail);border-radius:20px;padding:5px 12px;font-size:12px;font-weight:600;';
  chip.innerHTML = label + ' <span style="cursor:pointer;font-weight:400;" onclick="eowmsRemoveCcChip(\'' + userId + '\', this.parentElement)">✕</span>';
  chip.id = 'ccChip_' + userId;
  document.getElementById('ccChipsBox').appendChild(chip);

  var hidden = document.createElement('input');
  hidden.type = 'hidden'; hidden.name = 'cc_user_ids[]'; hidden.value = userId; hidden.id = 'ccHidden_' + userId;
  document.getElementById('ccHiddenInputs').appendChild(hidden);
}

function eowmsRemoveCcChip(userId, chipEl) {
  delete eowmsCcAdded[userId];
  chipEl.remove();
  var hidden = document.getElementById('ccHidden_' + userId);
  if (hidden) { hidden.remove(); }
}

function eowmsOnTargetDeptChange(deptId, preselectUserId) {
  var recipientBlock = document.getElementById('recipientTypeBlock');
  var empSelect = document.getElementById('targetEmployeeSelect');
  if (!deptId) {
    recipientBlock.style.display = 'none';
    empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
    return;
  }
  recipientBlock.style.display = 'block';
  empSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staff = data.staff || [];
      if (staff.length === 0) {
        empSelect.innerHTML = '<option value="">-- या विभागात कोणी सक्रिय कर्मचारी नाही --</option>';
        return;
      }
      empSelect.innerHTML = '<option value="">-- व्यक्ती निवडा --</option>' + staff.map(function (s) {
        return '<option value="' + s.id + '"' + (preselectUserId && String(s.id) === String(preselectUserId) ? ' selected' : '') + '>' + s.name + (s.designation ? ' (' + s.designation + ')' : '') + '</option>';
      }).join('');
      document.getElementById('createSubmitBtn').textContent = 'पत्र पाठवा';
    });
}

function eowmsToggleRecipientMode(mode) {
  var personBlock = document.getElementById('personPickerBlock');
  var allNote = document.getElementById('allDeptNote');
  personBlock.style.display = (mode === 'person') ? 'block' : 'none';
  allNote.style.display = (mode === 'all') ? 'block' : 'none';
  document.getElementById('createSubmitBtn').textContent = 'पत्र पाठवा';
}

<?php if ($showCampusPicker && $preselectedCampusId): ?>
eowmsOnTargetCampusChange(<?= (int)$preselectedCampusId ?>, <?= (int)$preselectedTargetDept ?>, <?= (int)$preselectedUser ?>);
<?php elseif (!$showCampusPicker && $preselectedTargetDept): ?>
eowmsOnTargetDeptChange(<?= (int)$preselectedTargetDept ?>, <?= (int)$preselectedUser ?>);
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
