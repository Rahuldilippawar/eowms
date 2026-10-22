<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_hostels', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: bulk-import.php');
    exit;
}

$orgId = activeOrgId();

$deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'HOS' LIMIT 1");
$deptStmt->execute([$orgId]);
$hostelDept = $deptStmt->fetch();
if (!$hostelDept) {
    $_SESSION['form_error'] = 'या संस्थेत "वसतिगृह (HOS)" विभाग सापडला नाही — आधी तो विभाग तयार करा.';
    header('Location: bulk-import.php');
    exit;
}

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1");
$campusStmt->execute([$orgId]);
$campusesByName = [];
foreach ($campusStmt->fetchAll() as $c) {
    $campusesByName[mb_strtolower(trim($c['name']))] = $c['id'];
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
if (!in_array('name', $header, true) || !in_array('campus', $header, true)) {
    fclose($handle);
    $_SESSION['form_error'] = 'CSV मध्ये "name" व "campus" हे columns असणे आवश्यक आहे.';
    header('Location: bulk-import.php');
    exit;
}

$colIndex = array_flip($header);
$insert = $pdo->prepare("INSERT INTO hostels (organization_id, campus_id, department_id, name, capacity) VALUES (?, ?, ?, ?, ?)");

$created = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $name = $get('name');
    $campusName = $get('campus');
    if (!$name) { $errors[] = "ओळ {$rowNum}: वसतिगृहाचे नाव रिकामे आहे — वगळले"; continue; }

    $campusId = $campusesByName[mb_strtolower($campusName ?? '')] ?? null;
    if (!$campusId) { $errors[] = "ओळ {$rowNum}: कॅम्पस '{$campusName}' सापडला नाही — वगळले"; continue; }

    $capacityRaw = $get('capacity');
    $capacity = ($capacityRaw !== null && $capacityRaw !== '' && is_numeric($capacityRaw)) ? (int)$capacityRaw : null;

    $insert->execute([$orgId, $campusId, $hostelDept['id'], $name, $capacity]);
    $created++;
}
fclose($handle);

logActivity('वसतिगृह CSV आयात केले', 'hostel', null, "$created वसतिगृहं जोडली");
$_SESSION['flash'] = "$created वसतिगृहं यशस्वीरित्या जोडली गेली.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: index.php');
exit;
