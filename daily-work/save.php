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
$employeeIds = array_values(array_unique(array_filter(array_map('intval', $_POST['employee_ids'] ?? []))));
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

// Fetch department for organization/campus context
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
$deptStmt->execute([$departmentId]);
$department = $deptStmt->fetch();

if (!$department) {
    $_SESSION['form_error'] = t('s432');
    header('Location: entry.php');
    exit;
}

// Department-scoped access control — staff/HOD can only submit for their own department
if (isDeptScoped() && (int)$user['department_id'] !== $departmentId) {
    http_response_code(403);
    die(t('s434'));
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
$workCode = sprintf('WRK-%s%04d', date('y'), $seq);

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
    foreach ($materialItemIds as $idx => $itemId) {
        $itemId = (int)$itemId;
        $qty = isset($materialQuantities[$idx]) && is_numeric($materialQuantities[$idx]) ? (float)$materialQuantities[$idx] : 0;
        if ($itemId && $qty > 0) {
            $matInsert->execute([$newWorkRecordId, $itemId, $qty]);
        }
    }
} catch (PDOException $e) { /* work_record_materials table not migrated yet — काम-नोंद आधीच Save झालेली आहे */ }

// Save any organization-specific custom field values submitted with this entry
try {
    $cfStmt = $pdo->prepare("SELECT field_key FROM custom_fields WHERE organization_id = ? AND is_active = 1");
    $cfStmt->execute([$department['organization_id']]);
    $activeFieldKeys = $cfStmt->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($activeFieldKeys)) {
        $cfInsert = $pdo->prepare("INSERT INTO custom_field_values (work_record_id, field_key, field_value) VALUES (?, ?, ?)");
        foreach ($activeFieldKeys as $key) {
            $postKey = 'custom_' . $key;
            if (isset($_POST[$postKey]) && $_POST[$postKey] !== '') {
                $cfInsert->execute([$newWorkRecordId, $key, trim($_POST[$postKey])]);
            }
        }
    }
} catch (PDOException $e) {
    // custom_fields tables not migrated yet — main work record is already saved, so just skip this
}

$_SESSION['flash'] = "कामाची नोंद यशस्वीरित्या सबमिट झाली — $workCode";
header('Location: entry.php');
exit;
