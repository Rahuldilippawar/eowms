<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
$required = ['dvr_name', 'camera_name'];
foreach ($required as $col) {
    if (!in_array($col, $header, true)) {
        fclose($handle);
        $_SESSION['form_error'] = "CSV मध्ये \"$col\" हा column असणे आवश्यक आहे.";
        header('Location: import.php');
        exit;
    }
}
$colIndex = array_flip($header);

$allowedCameraTypes = ['dome', 'bullet', 'ptz', 'other'];

// आधीच अस्तित्वात असलेले DVR (नावाने) — Duplicate टाळण्यासाठी
$existingDvrStmt = $pdo->prepare("SELECT id, dvr_name FROM cctv_dvrs WHERE organization_id = ?");
$existingDvrStmt->execute([$orgId]);
$dvrIdByName = [];
foreach ($existingDvrStmt->fetchAll() as $ed) { $dvrIdByName[mb_strtolower(trim($ed['dvr_name']))] = $ed['id']; }

// याच Import मधल्या (आधीच्या ओळींमधून) DVR-Camera जोड्या — तीच Camera दोनदा घालू नये
$existingCamKeys = [];
$camCheckStmt = $pdo->prepare("SELECT dvr_id, camera_name FROM cctv_cameras WHERE organization_id = ?");
$camCheckStmt->execute([$orgId]);
foreach ($camCheckStmt->fetchAll() as $ec) { $existingCamKeys[$ec['dvr_id'] . '|' . mb_strtolower(trim($ec['camera_name']))] = true; }

$dvrInsert = $pdo->prepare("INSERT INTO cctv_dvrs (organization_id, dvr_name, location, channel_capacity, storage_capacity_tb, installed_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
$camInsert = $pdo->prepare("INSERT INTO cctv_cameras (organization_id, dvr_id, camera_name, location, camera_type) VALUES (?, ?, ?, ?, ?)");

$dvrsCreated = 0;
$camerasCreated = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $dvrName = $get('dvr_name');
    $cameraName = $get('camera_name');

    if (!$dvrName) { $errors[] = "ओळ {$rowNum}: dvr_name रिकामं आहे — वगळले"; continue; }
    if (!$cameraName) { $errors[] = "ओळ {$rowNum}: camera_name रिकामं आहे — वगळले"; continue; }

    $dvrKey = mb_strtolower(trim($dvrName));

    // DVR आधीच अस्तित्वात नसेल तरच नवीन तयार करा
    if (!isset($dvrIdByName[$dvrKey])) {
        $channelCapacity = is_numeric($get('channel_capacity')) ? (int)$get('channel_capacity') : null;
        $storageCapacity = is_numeric($get('storage_capacity_tb')) ? (float)$get('storage_capacity_tb') : null;
        $installedDate = $get('installed_date');
        $installedDate = ($installedDate && strtotime($installedDate)) ? date('Y-m-d', strtotime($installedDate)) : null;

        $dvrInsert->execute([$orgId, $dvrName, $get('dvr_location'), $channelCapacity, $storageCapacity, $installedDate, $user['id']]);
        $dvrIdByName[$dvrKey] = $pdo->lastInsertId();
        $dvrsCreated++;
    }
    $dvrId = $dvrIdByName[$dvrKey];

    // हीच Camera याच DVR खाली आधीच असेल तर वगळा
    $camKey = $dvrId . '|' . mb_strtolower(trim($cameraName));
    if (isset($existingCamKeys[$camKey])) {
        $errors[] = "ओळ {$rowNum}: '{$cameraName}' ही Camera '{$dvrName}' DVR खाली आधीच अस्तित्वात आहे — वगळले";
        continue;
    }

    $cameraType = strtolower($get('camera_type') ?: 'dome');
    if (!in_array($cameraType, $allowedCameraTypes, true)) { $cameraType = 'dome'; }

    $camInsert->execute([$orgId, $dvrId, $cameraName, $get('camera_location'), $cameraType]);
    $existingCamKeys[$camKey] = true;
    $camerasCreated++;
}
fclose($handle);

logActivity('CCTV DVR/Camera Import केले', 'cctv_dvr', null, "$dvrsCreated DVR + $camerasCreated Cameras जोडले");
$_SESSION['flash'] = "$dvrsCreated नवीन DVR आणि $camerasCreated नवीन Cameras यशस्वीरित्या जोडले गेले.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: import.php');
exit;
