<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$designation = trim($_POST['designation'] ?? '');
$teachingMode = in_array($_POST['teaching_mode'] ?? '', ['Teaching', 'Non Teaching', 'Other'], true) ? $_POST['teaching_mode'] : 'Non Teaching';
$departmentId = (int)($_POST['department_id'] ?? 0);
$role = $_POST['role'] ?? 'staff';
$isActive = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$newPassword = trim($_POST['new_password'] ?? '');
$unlockAccount = isset($_POST['unlock_account']);
$salary = trim($_POST['salary'] ?? '') !== '' ? (float)$_POST['salary'] : null;
$joiningDate = ($_POST['joining_date'] ?? '') ?: null;
$dateOfBirth = ($_POST['date_of_birth'] ?? '') ?: null;

// Photo upload is optional on edit — only touches photo_path if a new file was actually chosen
$photoPath = null;
if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../assets/uploads/staff/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $safeName = uniqid('staff_') . '.' . $ext;
        if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $safeName)) {
            $photoPath = 'assets/uploads/staff/' . $safeName;
        }
    }
}

if ($unlockAccount) {
    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$id]);
}

if (!$name || !$departmentId) {
    $_SESSION['form_error'] = t('s428');
    header('Location: edit.php?id=' . $id);
    exit;
}

$deptStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ?");
$deptStmt->execute([$departmentId]);
$dept = $deptStmt->fetch();

// Safety: never allow the LAST remaining super_admin to be demoted or deactivated —
// this prevents the whole system from becoming permanently inaccessible.
$targetStmt = $pdo->prepare("SELECT role, is_active, left_date, department_id, salary FROM users WHERE id = ?");
$targetStmt->execute([$id]);
$targetRow = $targetStmt->fetch();
$targetCurrentRole = $targetRow['role'] ?? null;
$wasActive = (int)($targetRow['is_active'] ?? 1);

if ($targetCurrentRole === 'super_admin' && ($role !== 'super_admin' || $isActive === 0)) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND is_active = 1 AND id != ?");
    $countStmt->execute([$id]);
    $otherActiveAdmins = $countStmt->fetch()['c'];

    if ($otherActiveAdmins < 1) {
        $_SESSION['form_error'] = t('s576');
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// Auto-track leave date: set it the moment someone is newly deactivated;
// clear it automatically if they're later reactivated (re-joined).
if ($wasActive === 1 && $isActive === 0) {
    $leftDate = date('Y-m-d');
} elseif ($wasActive === 0 && $isActive === 1) {
    $leftDate = null;
} else {
    $leftDate = $targetRow['left_date'] ?? null;
}

if ($newPassword !== '') {
    $update = $pdo->prepare("UPDATE users SET name=?, designation=?, teaching_mode=?, department_id=?, campus_id=?, role=?, is_active=?, salary=?, joining_date=?, left_date=?, date_of_birth=?, photo_path=COALESCE(?, photo_path), password=?, must_change_password=1 WHERE id=?");
    $update->execute([$name, $designation, $teachingMode, $departmentId, $dept['campus_id'], $role, $isActive, $salary, $joiningDate, $leftDate, $dateOfBirth, $photoPath, password_hash($newPassword, PASSWORD_DEFAULT), $id]);
} else {
    $update = $pdo->prepare("UPDATE users SET name=?, designation=?, teaching_mode=?, department_id=?, campus_id=?, role=?, is_active=?, salary=?, joining_date=?, left_date=?, date_of_birth=?, photo_path=COALESCE(?, photo_path) WHERE id=?");
    $update->execute([$name, $designation, $teachingMode, $departmentId, $dept['campus_id'], $role, $isActive, $salary, $joiningDate, $leftDate, $dateOfBirth, $photoPath, $id]);
}

logActivity('कर्मचारी संपादित केला', 'user', $id, "$name — role: $role, active: $isActive");

// दैनंदिन कामाची नोंद Edit करण्याचा Access — फक्त Super Admin बदलू शकतो
if (hasRole('super_admin')) {
    $canEditDailyWork = isset($_POST['can_edit_daily_work']) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE users SET can_edit_daily_work = ? WHERE id = ?")->execute([$canEditDailyWork, $id]);
    } catch (PDOException $e) { /* migration not run yet */ }
}

$_SESSION['flash'] = "$name चा तपशील अद्ययावत केला गेला.";
header('Location: index.php');
exit;
