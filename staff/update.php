<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/bio-helpers.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$name = nameCase($_POST['name'] ?? '');
$email = strtolower(trim($_POST['email'] ?? ''));
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

// 🪪 ओळख, बँक व पत्ता + कर्मचारी क्रमांक — आधी तपासा (चूक असेल तर काहीच जतन न करता परत)
require_once __DIR__ . '/../includes/staff-extra-fields.php';
[$extraF, $extraErr] = staffExtraFromPost($_POST, true);
if (!$extraErr && $extraF['employee_code'] !== null) {
    $ec = $pdo->prepare("SELECT name FROM users WHERE employee_code = ? AND id <> ?");
    $ec->execute([$extraF['employee_code'], $id]);
    if ($dupE = $ec->fetchColumn()) { $extraErr = "कर्मचारी क्रमांक '{$extraF['employee_code']}' आधीच $dupE यांना दिला आहे."; }
}
if ($extraErr) {
    $_SESSION['form_error'] = '🪪 ' . $extraErr . ' (बदल जतन झाले नाहीत.)';
    header('Location: edit.php?id=' . $id);
    exit;
}

// Photo upload is optional on edit — only touches photo_path if a new file was actually chosen
require_once __DIR__ . '/../includes/photo-helpers.php';
[$photoPath, $photoErr] = eowmsSavePhotoUpload($_FILES['photo'] ?? null, 'staff', 'staff_');
if ($photoErr) {
    $_SESSION['form_error'] = '📷 ' . $photoErr . ' (बाकी बदल जतन झाले नाहीत — पुन्हा जतन करा.)';
    header('Location: edit.php?id=' . $id);
    exit;
}

if ($unlockAccount) {
    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$id]);
}

// Management-श्रेणीतले पद (President, GM, Super Admin, इ.) — विभागाशिवायही चालतं
$roleCat = roleCategory($role);
$deptOptional = in_array($roleCat, ['management', 'super_admin'], true);

if (!$name || (!$departmentId && !$deptOptional)) {
    $_SESSION['form_error'] = $name ? 'या पदासाठी विभाग निवडणं आवश्यक आहे (फक्त Management पदांना विभागाशिवाय ठेवता येतं).' : t('s428');
    header('Location: edit.php?id=' . $id);
    exit;
}

$dept = ['campus_id' => null];
if ($departmentId) {
    $deptStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ? AND organization_id = ?");
    $deptStmt->execute([$departmentId, activeOrgId()]);
    $dept = $deptStmt->fetch();
    if (!$dept) {
        $_SESSION['form_error'] = t('s428');
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// Safety: never allow the LAST remaining super_admin to be demoted or deactivated —
// this prevents the whole system from becoming permanently inaccessible.
$targetStmt = $pdo->prepare("SELECT role, is_active, left_date, department_id, campus_id, salary, email, biometric_pin FROM users WHERE id = ? AND organization_id = ?");
$targetStmt->execute([$id, activeOrgId()]);
$targetRow = $targetStmt->fetch();
if (!$targetRow) {
    header('Location: index.php');
    exit;
}
// 📧 Email (Login ID) बदल — वैध, आणि दुसऱ्या कोणाचा (कुठल्याही संस्थेतला) नसलेला
$oldEmail = (string)($targetRow['email'] ?? '');
if ($email === '') { $email = $oldEmail; } // जुना Form (Email field नसलेला) — Email तसाच
$emailChanged = strcasecmp($email, $oldEmail) !== 0;
if ($emailChanged) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 255) {
        $_SESSION['form_error'] = 'Email पत्ता वैध नाही — उदा. naav@example.com';
        header('Location: edit.php?id=' . $id);
        exit;
    }
    $dupStmt = $pdo->prepare("SELECT name FROM users WHERE LOWER(email) = ? AND id <> ? LIMIT 1");
    $dupStmt->execute([$email, $id]);
    if ($dupName = $dupStmt->fetchColumn()) {
        $_SESSION['form_error'] = "'$email' हा Email आधीच दुसऱ्या कर्मचाऱ्याचा ($dupName) आहे — एक Email एकाच खात्याला वापरता येतो.";
        header('Location: edit.php?id=' . $id);
        exit;
    }
}

// विभाग नसेल किंवा Trust-HQ (Campus नसलेला) विभाग असेल — Form मध्ये निवडलेला Campus/College, नाहीतर आधीचाच
if (empty($dept['campus_id'])) {
    require_once __DIR__ . '/../includes/staff-dept-picker.php';
    [$pickedCampus] = staffDeptResolve($pdo, activeOrgId(), $_POST['campus_id'] ?? '', '');
    $dept['campus_id'] = $pickedCampus ?: $targetRow['campus_id'];
}
$departmentId = $departmentId ?: null;
$targetCurrentRole = $targetRow['role'] ?? null;
$wasActive = (int)($targetRow['is_active'] ?? 1);

// 👑 एकच Super Admin — दुसऱ्या कोणाला Super Admin बनवता येत नाही
if ($role === 'super_admin' && $targetCurrentRole !== 'super_admin' && superAdminExistsOtherThan($pdo, $id)) {
    $_SESSION['form_error'] = 'Super Admin एकच असतो — दुसरा Super Admin बनवता येणार नाही. Management साठी President / GM वगैरे पद निवडा.';
    header('Location: edit.php?id=' . $id);
    exit;
}

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
staffExtraSave($pdo, $id, $extraF); // 🪪 ओळख, बँक, पत्ता, कर्मचारी क्रमांक

logActivity('कर्मचारी संपादित केला', 'user', $id, "$name — role: $role, active: $isActive");

// 🖐️ Biometric PIN — बदलला / सक्रिय-निष्क्रिय / "पुन्हा पाठवा" तर सगळ्या चालू Machines वर आपोआप
if (array_key_exists('biometric_pin', $_POST)) {
    $oldPin = (string)($targetRow['biometric_pin'] ?? '');
    $newPin = trim((string)$_POST['biometric_pin']);
    $bioNote = null;
    if ($newPin !== '' && !preg_match('/^[0-9]{1,9}$/', $newPin)) {
        $bioNote = 'Biometric PIN फक्त अंकांत (1–9 अंक) असावा — PIN बदलला नाही.';
    } elseif ($newPin !== '' && ($owner = bioPinOwner($pdo, activeOrgId(), $newPin, $id))) {
        $bioNote = "Biometric PIN '$newPin' आधीच $owner यांना दिला आहे — PIN बदलला नाही.";
    } else {
        if ($newPin !== $oldPin) {
            $pdo->prepare("UPDATE users SET biometric_pin = ? WHERE id = ?")->execute([$newPin === '' ? null : $newPin, $id]);
            logActivity("Biometric PIN बदलला: " . ($oldPin ?: '—') . " → " . ($newPin ?: '—'), 'user', $id);
        }
        $campusMoved = (int)($targetRow['campus_id'] ?? 0) !== (int)($dept['campus_id'] ?? 0);
        if ($newPin !== $oldPin || $wasActive !== $isActive || $campusMoved || !empty($_POST['bio_resend'])) {
            bioSyncStaff($pdo, activeOrgId(), $id, $oldPin !== $newPin ? $oldPin : null, $user['id'] ?? null, $campusMoved ? $targetRow['campus_id'] : null);
        }
        if ($newPin !== $oldPin && bioReady($pdo)) { bioProcessPending($pdo); } // या PIN चे आधीचे Punches आता याच्या हजेरीत
    }
    if ($bioNote) { $_SESSION['form_error'] = $bioNote; }
}

if ($emailChanged) {
    try {
        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $id]);
    } catch (PDOException $e) { // एकाच वेळी दुसऱ्याने तोच Email घेतला (UNIQUE)
        $_SESSION['form_error'] = "'$email' हा Email आधीच वापरात आहे — Email बदलला नाही (बाकी बदल जतन झाले).";
        header('Location: edit.php?id=' . $id);
        exit;
    }
    logActivity('कर्मचाऱ्याचा Email (Login ID) बदलला', 'user', $id, "$name — $oldEmail → $email");
    if ((int)$id === (int)$user['id']) { $_SESSION['user']['email'] = $email; }

    // सुरक्षा-सूचना — जुन्या पत्त्यावर (खातं कोणी बळकावलं तर कळावं) आणि नवीन पत्त्यावर (नवीन Login ID)
    require_once __DIR__ . '/../includes/mailer.php';
    $orgN = activeOrgName();
    $hN = htmlspecialchars($name); $hOld = htmlspecialchars($oldEmail); $hNew = htmlspecialchars($email); $hBy = htmlspecialchars($user['name'] ?? 'Super Admin');
    $mailBody = fn($line) => "<div style='font-family:Arial,sans-serif;max-width:540px;margin:0 auto;padding:22px;background:#F3F4F7;'>
      <div style='background:#191C3A;padding:16px 22px;border-radius:10px 10px 0 0;'><div style='color:#fff;font-size:17px;font-weight:bold;'>" . htmlspecialchars($orgN) . "</div><div style='color:#C9CCE0;font-size:12.5px;'>E-Admin — खात्याची सुरक्षा-सूचना</div></div>
      <div style='background:#fff;padding:22px;border-radius:0 0 10px 10px;font-size:14px;color:#1A1D29;line-height:1.7;'>
        <p>नमस्कार <b>$hN</b>,</p><p>$line</p>
        <table style='font-size:13px;margin:10px 0;'><tr><td style='color:#666B7D;padding-right:12px;'>जुना Email</td><td>$hOld</td></tr><tr><td style='color:#666B7D;padding-right:12px;'>नवीन Email</td><td><b>$hNew</b></td></tr><tr><td style='color:#666B7D;padding-right:12px;'>बदल करणारे</td><td>$hBy</td></tr><tr><td style='color:#666B7D;padding-right:12px;'>वेळ</td><td>" . date('d M Y, h:i A') . "</td></tr></table>
        <p style='font-size:12.5px;color:#666B7D;'>हा बदल तुम्ही मागितला नसेल तर लगेच कार्यालयाशी संपर्क साधा.</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:16px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p></div></div>";
    eowmsSendMail($oldEmail, 'E-Admin — तुमचा Login Email बदलला', $mailBody('तुमच्या E-Admin खात्याचा Login Email बदलण्यात आला आहे. यापुढे Login साठी नवीन Email वापरावा लागेल.'), [], $orgN);
    eowmsSendMail($email, 'E-Admin — हा तुमचा नवीन Login Email', $mailBody('यापुढे E-Admin मध्ये Login करण्यासाठी हा Email वापरा. पासवर्ड पूर्वीचाच आहे.'), [], $orgN);
}

// दैनंदिन कामाची नोंद Edit करण्याचा Access — फक्त Super Admin बदलू शकतो
if (hasRole('super_admin')) {
    $canEditDailyWork = isset($_POST['can_edit_daily_work']) ? 1 : 0;
    try {
        $pdo->prepare("UPDATE users SET can_edit_daily_work = ? WHERE id = ?")->execute([$canEditDailyWork, $id]);
    } catch (PDOException $e) { /* migration not run yet */ }
}

$_SESSION['flash'] = "$name चा तपशील अद्ययावत केला गेला." . ($emailChanged ? " नवीन Login Email: $email" : '');
header('Location: index.php');
exit;
