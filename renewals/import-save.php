<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
$required = ['department_name', 'item_type', 'item_name', 'expiry_date'];
foreach ($required as $col) {
    if (!in_array($col, $header, true)) {
        fclose($handle);
        $_SESSION['form_error'] = "CSV मध्ये \"$col\" हा column असणे आवश्यक आहे.";
        header('Location: import.php');
        exit;
    }
}
$colIndex = array_flip($header);

$deptStmt = $pdo->prepare("SELECT id, name_local, name, campus_id FROM departments WHERE organization_id = ? AND is_active = 1");
$deptStmt->execute([$orgId]);
$deptByName = [];
foreach ($deptStmt->fetchAll() as $d) {
    $deptByName[mb_strtolower(trim($d['name_local'] ?: ''))] = $d;
    $deptByName[mb_strtolower(trim($d['name']))] = $d;
}

$insert = $pdo->prepare("
    INSERT INTO renewal_items (organization_id, campus_id, department_id, item_type, regulatory_body, item_name, reference_no, issued_date, expiry_date, renewal_cost, vendor_name, vendor_contact, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
");

$created = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;
    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $deptName = $get('department_name');
    $itemType = $get('item_type');
    $itemName = $get('item_name');
    $expiryDate = $get('expiry_date');

    if (!$deptName || !$itemType || !$itemName || !$expiryDate) { $errors[] = "ओळ {$rowNum}: आवश्यक माहिती रिकामी आहे — वगळले"; continue; }
    $dept = $deptByName[mb_strtolower($deptName)] ?? null;
    if (!$dept) { $errors[] = "ओळ {$rowNum}: \"$deptName\" हा विभाग सापडला नाही — वगळले"; continue; }
    $expiryDate = strtotime($expiryDate) ? date('Y-m-d', strtotime($expiryDate)) : null;
    if (!$expiryDate) { $errors[] = "ओळ {$rowNum}: expiry_date चुकीच्या Format मध्ये आहे — वगळले"; continue; }

    $issuedDate = $get('issued_date');
    $issuedDate = ($issuedDate && strtotime($issuedDate)) ? date('Y-m-d', strtotime($issuedDate)) : null;
    $renewalCost = is_numeric($get('renewal_cost')) ? (float)$get('renewal_cost') : null;

    $insert->execute([$orgId, $dept['campus_id'], $dept['id'], $itemType, $get('regulatory_body') ?: null, $itemName, $get('reference_no'), $issuedDate, $expiryDate, $renewalCost, $get('vendor_name'), $get('vendor_contact')]);
    $created++;
}
fclose($handle);

logActivity('नूतनीकरण Import केले', 'renewal_item', null, "$created नोंदी जोडल्या");
$_SESSION['flash'] = "$created नवीन नूतनीकरण-नोंदी यशस्वीरित्या जोडल्या गेल्या.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: import.php');
exit;
