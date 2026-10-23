<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: entry.php');
    exit;
}

$workDate = $_POST['work_date'] ?? null;
$departmentId = (int)($_POST['department_id'] ?? 0);
$requestedBy = trim($_POST['requested_by'] ?? '') ?: null;
$employeeIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['employee_ids'] ?? [])))));
$employeeId = $employeeIds[0] ?? 0; // primary/first-listed employee — kept for backward-compatible reporting
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$priority = $_POST['priority'] ?? 'normal';
$status = $_POST['status'] ?? 'pending';
$startDate = ($_POST['start_date'] ?? '') ?: null;
$dueDate = ($_POST['due_date'] ?? '') ?: null;
$estimatedCost = ($_POST['estimated_cost'] ?? '') !== '' ? $_POST['estimated_cost'] : null;
$actualCost = ($_POST['actual_cost'] ?? '') !== '' ? $_POST['actual_cost'] : null;

if (!$workDate || !$departmentId || empty($employeeIds) || !$title) {
    $_SESSION['form_error'] = t('s428');
    header('Location: entry.php');
    exit;
}

// विभाग सक्रिय संस्थेचाच असला पाहिजे (दुसऱ्या संस्थेचा id चालणार नाही)
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ? AND is_active = 1");
$deptStmt->execute([$departmentId, activeOrgId()]);
$department = $deptStmt->fetch();

if (!$department) {
    $_SESSION['form_error'] = t('s432');
    header('Location: entry.php');
    exit;
}

// Department-scoped access control — staff/HOD फक्त स्वतःच्या (व Extra दिलेल्या) विभागासाठी
$visibleDeptIds = getVisibleDepartmentIds();
if ($visibleDeptIds !== null && !in_array($departmentId, $visibleDeptIds, true)) {
    http_response_code(403);
    die(t('s434'));
}

// Priority / Status — फक्त परवानगी असलेली मूल्यं
if (!in_array($priority, ['urgent', 'high', 'normal', 'low'], true)
    || !in_array($status, ['pending', 'in_progress', 'completed'], true)) {
    $_SESSION['form_error'] = 'अवैध प्राधान्य किंवा स्थिती निवडली आहे.';
    header('Location: entry.php');
    exit;
}

// तारखा व खर्च — योग्य स्वरूपात असावेत
$isValidDate = function ($d) { return $d === null || (bool)DateTime::createFromFormat('!Y-m-d', $d); };
if (!$isValidDate($workDate) || !$isValidDate($startDate) || !$isValidDate($dueDate)
    || ($estimatedCost !== null && !is_numeric($estimatedCost)) || ($actualCost !== null && !is_numeric($actualCost))) {
    $_SESSION['form_error'] = 'तारीख किंवा खर्चाची रक्कम चुकीच्या स्वरूपात आहे.';
    header('Location: entry.php');
    exit;
}

// निवडलेले कर्मचारी — याच संस्थेचे, सक्रिय व (विभाग-मर्यादित असल्यास) दिसणाऱ्या विभागातले असावेत
$empPh = implode(',', array_fill(0, count($employeeIds), '?'));
$empStmt = $pdo->prepare("SELECT id, department_id FROM users WHERE id IN ($empPh) AND organization_id = ? AND is_active = 1");
$empStmt->execute(array_merge($employeeIds, [activeOrgId()]));
$validEmployees = $empStmt->fetchAll();
$employeesOk = count($validEmployees) === count($employeeIds);
if ($employeesOk && $visibleDeptIds !== null) {
    foreach ($validEmployees as $emp) {
        if (!in_array((int)$emp['department_id'], $visibleDeptIds, true)) { $employeesOk = false; break; }
    }
}
if (!$employeesOk) {
    $_SESSION['form_error'] = 'निवडलेले कर्मचारी अवैध आहेत — कृपया यादीतूनच निवडा.';
    header('Location: entry.php');
    exit;
}

// संस्थेचे सक्रिय Custom Fields — आवश्यक (required) व प्रकार Server वरच तपासतो
$customFieldDefs = [];
try {
    $cfStmt = $pdo->prepare("SELECT field_key, field_label, field_type, field_options, is_required FROM custom_fields WHERE organization_id = ? AND is_active = 1");
    $cfStmt->execute([$department['organization_id']]);
    $customFieldDefs = $cfStmt->fetchAll();
} catch (PDOException $e) { /* custom_fields table not migrated yet */ }

$customValues = [];
foreach ($customFieldDefs as $cf) {
    $val = trim((string)($_POST['custom_' . $cf['field_key']] ?? ''));
    if ($val === '') {
        if ($cf['is_required']) {
            $_SESSION['form_error'] = "'{$cf['field_label']}' हे फील्ड भरणे आवश्यक आहे.";
            header('Location: entry.php');
            exit;
        }
        continue;
    }
    $typeOk = true;
    if ($cf['field_type'] === 'number') {
        $typeOk = is_numeric($val);
    } elseif ($cf['field_type'] === 'date') {
        $typeOk = (bool)DateTime::createFromFormat('!Y-m-d', $val);
    } elseif ($cf['field_type'] === 'select') {
        $opts = array_filter(array_map('trim', explode(',', $cf['field_options'] ?? '')), 'strlen');
        $typeOk = in_array($val, $opts, true);
    } elseif ($cf['field_type'] === 'checkbox') {
        $typeOk = in_array($val, ['होय', 'नाही'], true);
    }
    if (!$typeOk) {
        $_SESSION['form_error'] = "'{$cf['field_label']}' या फील्डचे मूल्य अवैध आहे.";
        header('Location: entry.php');
        exit;
    }
    $customValues[$cf['field_key']] = $val;
}

// Handle before/after photo uploads
function uploadWorkPhoto($fieldName, $departmentId) {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return null;
    }
    $dir = __DIR__ . '/../assets/uploads/work-photos/' . $departmentId;
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $filename = uniqid('wp_', true) . '.' . $ext;
    move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dir . '/' . $filename);
    return 'assets/uploads/work-photos/' . $departmentId . '/' . $filename;
}

$beforePhotoPath = uploadWorkPhoto('before_photo', $departmentId);
$afterPhotoPath = uploadWorkPhoto('after_photo', $departmentId);

$enteredByRole = in_array($user['id'], $employeeIds, true) ? 'staff' : 'hod';
$completionDate = ($status === 'completed') ? date('Y-m-d') : null;

// Generate a simple sequential work code
$countStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE organization_id = ?");
$countStmt->execute([$department['organization_id']]);
$seq = $countStmt->fetch()['c'] + 1;
$workCode = generateUniqueCode($pdo, 'work_records', 'work_code', 'WRK-%s%04d', $seq, date('y'));

$insert = $pdo->prepare("
    INSERT INTO work_records
      (work_code, organization_id, campus_id, department_id, requested_by, employee_id, entered_by_role, entered_by_user_id,
       work_date, title, description, priority, status, start_date, due_date, completion_date, estimated_cost, actual_cost,
       before_photo_path, after_photo_path)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");
$insert->execute([
    $workCode, $department['organization_id'], $department['campus_id'], $departmentId, $requestedBy, $employeeId,
    $enteredByRole, $user['id'], $workDate, $title, $description, $priority, $status,
    $startDate, $dueDate, $completionDate, $estimatedCost, $actualCost,
    $beforePhotoPath, $afterPhotoPath,
]);

$newWorkRecordId = $pdo->lastInsertId();

// Record every team member who worked on this — not just the primary one —
// so it shows up under each of their names.
$teamInsert = $pdo->prepare("INSERT IGNORE INTO work_record_team (work_record_id, user_id) VALUES (?, ?)");
foreach ($employeeIds as $eid) {
    $teamInsert->execute([$newWorkRecordId, $eid]);
}

// या कामासाठी Store मधून वापरलेलं साहित्य (उदा. Light, Fan, इ.) — असल्यास नोंदवतो
try {
    $materialItemIds = $_POST['material_item_id'] ?? [];
    $materialQuantities = $_POST['material_quantity'] ?? [];
    $matInsert = $pdo->prepare("INSERT INTO work_record_materials (work_record_id, item_id, quantity_used) VALUES (?, ?, ?)");
    // साहित्य याच संस्थेच्या Store मधलंच असावं
    $orgItemStmt = $pdo->prepare("SELECT id FROM store_items WHERE organization_id = ?");
    $orgItemStmt->execute([$department['organization_id']]);
    $orgItemIds = array_map('intval', $orgItemStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach ((array)$materialItemIds as $idx => $itemId) {
        $itemId = (int)$itemId;
        $qty = isset($materialQuantities[$idx]) && is_numeric($materialQuantities[$idx]) ? (float)$materialQuantities[$idx] : 0;
        if ($itemId && $qty > 0 && in_array($itemId, $orgItemIds, true)) {
            $matInsert->execute([$newWorkRecordId, $itemId, $qty]);
        }
    }
} catch (PDOException $e) { /* work_record_materials table not migrated yet — काम-नोंद आधीच Save झालेली आहे */ }

// Save any organization-specific custom field values submitted with this entry
try {
    if (!empty($customValues)) {
        $cfInsert = $pdo->prepare("INSERT INTO custom_field_values (work_record_id, field_key, field_value) VALUES (?, ?, ?)");
        foreach ($customValues as $key => $val) {
            $cfInsert->execute([$newWorkRecordId, $key, $val]);
        }
    }
} catch (PDOException $e) {
    // custom_fields tables not migrated yet — main work record is already saved, so just skip this
}

$_SESSION['flash'] = "कामाची नोंद यशस्वीरित्या सबमिट झाली — $workCode";
header('Location: entry.php');
exit;
