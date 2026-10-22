<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: student-import.php');
    exit;
}

$orgId = activeOrgId();
$hostelId = (int)($_POST['hostel_id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ? AND organization_id = ?");
$stmt->execute([$hostelId, $orgId]);
$hostel = $stmt->fetch();

if (!$hostel) {
    $_SESSION['form_error'] = 'वसतिगृह सापडले नाही.';
    header('Location: student-import.php');
    exit;
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');

// Strip UTF-8 BOM if present (common when saved from Excel)
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
if (!in_array('name', $header, true)) {
    fclose($handle);
    $_SESSION['form_error'] = 'CSV मध्ये किमान "name" हा column असणे आवश्यक आहे.';
    header('Location: student-import.php');
    exit;
}

$colIndex = array_flip($header);
$insert = $pdo->prepare("
    INSERT INTO hostel_students (hostel_id, name, school_name, class_grade, district, gender, admission_date, date_of_birth, is_active)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
");

$created = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue; // skip blank lines

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $name = $get('name');
    if (!$name) {
        $errors[] = "ओळ {$rowNum}: नाव रिकामे आहे — वगळले";
        continue;
    }

    $gender = $get('gender');
    if ($gender && !in_array($gender, ['मुलगा', 'मुलगी'], true)) {
        $gender = null; // ignore invalid values rather than failing the row
    }

    $admissionDate = $get('admission_date') ?: null;
    if ($admissionDate && !strtotime($admissionDate)) {
        $admissionDate = null;
    } elseif ($admissionDate) {
        $admissionDate = date('Y-m-d', strtotime($admissionDate));
    }

    $dateOfBirth = $get('date_of_birth') ?: null;
    if ($dateOfBirth && !strtotime($dateOfBirth)) {
        $dateOfBirth = null;
    } elseif ($dateOfBirth) {
        $dateOfBirth = date('Y-m-d', strtotime($dateOfBirth));
    }

    $insert->execute([
        $hostelId, $name, $get('school_name'), $get('class_grade'), $get('district'), $gender, $admissionDate, $dateOfBirth,
    ]);
    $created++;
}
fclose($handle);

logActivity('वसतिगृह विद्यार्थी CSV आयात केले', 'hostel', $hostelId, "{$hostel['name']} — $created विद्यार्थी जोडले");

$_SESSION['flash'] = "'{$hostel['name']}' साठी $created विद्यार्थी यशस्वीरित्या जोडले गेले.";
if (!empty($errors)) {
    $_SESSION['import_errors'] = $errors;
}
header('Location: dashboard.php?id=' . $hostelId);
exit;
