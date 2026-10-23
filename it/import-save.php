<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
$required = ['lab_name', 'asset_type'];
foreach ($required as $col) {
    if (!in_array($col, $header, true)) {
        fclose($handle);
        $_SESSION['form_error'] = "CSV मध्ये \"$col\" हा column असणे आवश्यक आहे.";
        header('Location: import.php');
        exit;
    }
}
$colIndex = array_flip($header);

$allowedAssetTypes = ['computer', 'monitor', 'keyboard', 'mouse', 'cpu', 'ups', 'smartboard', 'projector', 'printer', 'other'];

// आधीच अस्तित्वात असलेले Lab (नावाने) — Duplicate टाळण्यासाठी
$existingLabStmt = $pdo->prepare("SELECT id, lab_name FROM it_labs WHERE organization_id = ?");
$existingLabStmt->execute([$orgId]);
$labIdByName = [];
foreach ($existingLabStmt->fetchAll() as $el) { $labIdByName[mb_strtolower(trim($el['lab_name']))] = $el['id']; }

// नवीन Lab — संस्थेच्या IT विभागाशी जोडतो (Warranty-नूतनीकरण नोंदीसाठी विभाग लागतो)
$itDeptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' LIMIT 1");
$itDeptStmt->execute([$orgId]);
$itDeptIdForLabs = $itDeptStmt->fetch()['id'] ?? null;
$labInsert = $pdo->prepare("INSERT INTO it_labs (organization_id, department_id, lab_name, location, capacity, is_active) VALUES (?, ?, ?, ?, ?, 1)");
$assetInsert = $pdo->prepare("
    INSERT INTO it_assets (organization_id, lab_id, workstation_no, asset_tag, asset_type, brand, model, serial_no, purchase_date, purchase_cost, warranty_expiry, os_license, license_expiry, added_by)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$labsCreated = 0;
$assetsCreated = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $labName = $get('lab_name');
    $assetType = strtolower($get('asset_type') ?: '');

    if (!$labName) { $errors[] = "ओळ {$rowNum}: lab_name रिकामं आहे — वगळले"; continue; }
    if (!$assetType) { $errors[] = "ओळ {$rowNum}: asset_type रिकामं आहे — वगळले"; continue; }
    if (!in_array($assetType, $allowedAssetTypes, true)) { $assetType = 'other'; }

    $labKey = mb_strtolower(trim($labName));

    // Lab आधीच अस्तित्वात नसेल तरच नवीन तयार करा
    if (!isset($labIdByName[$labKey])) {
        $capacity = is_numeric($get('capacity')) ? (int)$get('capacity') : null;
        $labInsert->execute([$orgId, $itDeptIdForLabs, $labName, $get('lab_location'), $capacity]);
        $labIdByName[$labKey] = $pdo->lastInsertId();
        $labsCreated++;
    }
    $labId = $labIdByName[$labKey];

    $purchaseDate = $get('purchase_date');
    $purchaseDate = ($purchaseDate && strtotime($purchaseDate)) ? date('Y-m-d', strtotime($purchaseDate)) : null;
    $warrantyExpiry = $get('warranty_expiry');
    $warrantyExpiry = ($warrantyExpiry && strtotime($warrantyExpiry)) ? date('Y-m-d', strtotime($warrantyExpiry)) : null;
    $licenseExpiry = $get('license_expiry');
    $licenseExpiry = ($licenseExpiry && strtotime($licenseExpiry)) ? date('Y-m-d', strtotime($licenseExpiry)) : null;
    $purchaseCost = is_numeric($get('purchase_cost')) ? (float)$get('purchase_cost') : null;

    $assetTag = itGenerateAssetTag($pdo, $orgId, $assetType);
    $assetInsert->execute([
        $orgId, $labId, $get('workstation_no') ?: null, $assetTag, $assetType, $get('brand'), $get('model'), $get('serial_no'),
        $purchaseDate, $purchaseCost, $warrantyExpiry, $get('os_license'), $licenseExpiry, $user['id'],
    ]);
    $newAssetId = $pdo->lastInsertId();

    // Warranty असल्यास — नूतनीकरण Module ला Auto-Link (Vehicle/CCTV सारखंच)
    if ($warrantyExpiry) {
        syncItAssetWarranty($pdo, $newAssetId, $orgId, $labId, $assetTag, $assetType, $warrantyExpiry);
    }

    $assetsCreated++;
}
fclose($handle);

logActivity('IT Lab/Computer Import केले', 'it_lab', null, "$labsCreated Labs + $assetsCreated Assets जोडले");
$_SESSION['flash'] = "$labsCreated नवीन Labs आणि $assetsCreated नवीन Assets यशस्वीरित्या जोडले गेले.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: import.php');
exit;
