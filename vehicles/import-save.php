<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
if (!canManageVehicles()) { requireRole(['super_admin'], '../'); } // वाहन यादी/नवीन वाहन प्रमाणेच
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
$required = ['department_name', 'vehicle_number'];
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

$existingStmt = $pdo->prepare("SELECT vehicle_number FROM vehicles WHERE organization_id = ?");
$existingStmt->execute([$orgId]);
$existingNumbers = array_map(fn($v) => mb_strtolower(trim($v['vehicle_number'])), $existingStmt->fetchAll());

$allowedFuel = ['petrol', 'diesel', 'cng', 'electric', 'other'];

$insert = $pdo->prepare("
    INSERT INTO vehicles (organization_id, campus_id, department_id, vehicle_number, vehicle_type, model, date_of_registration, chassis_no, engine_no, vehicle_class, passenger_capacity, fuel_type, permit_validity, puc_validity, insurance_validity, fitness_validity, road_tax_validity, green_tax_validity, mv_tax_validity)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
");

$created = 0;
$errors = [];
$rowNum = 1;
$dateField = fn($v) => ($v && strtotime($v)) ? date('Y-m-d', strtotime($v)) : null;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;
    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $deptName = $get('department_name');
    $vehicleNumber = $get('vehicle_number');
    if (!$deptName || !$vehicleNumber) { $errors[] = "ओळ {$rowNum}: आवश्यक माहिती रिकामी आहे — वगळले"; continue; }

    $dept = $deptByName[mb_strtolower($deptName)] ?? null;
    if (!$dept) { $errors[] = "ओळ {$rowNum}: \"$deptName\" हा विभाग सापडला नाही — वगळले"; continue; }

    if (in_array(mb_strtolower($vehicleNumber), $existingNumbers, true)) {
        $errors[] = "ओळ {$rowNum}: वाहन क्र. \"$vehicleNumber\" आधीच अस्तित्वात आहे — वगळले";
        continue;
    }

    $fuelType = strtolower($get('fuel_type') ?: 'diesel');
    if (!in_array($fuelType, $allowedFuel, true)) { $fuelType = 'diesel'; }

    $renewalDates = [
        'permit' => $dateField($get('permit_validity')),
        'puc' => $dateField($get('puc_validity')),
        'insurance' => $dateField($get('insurance_validity')),
        'fitness' => $dateField($get('fitness_validity')),
        'road_tax' => $dateField($get('road_tax_validity')),
        'green_tax' => $dateField($get('green_tax_validity')),
        'mv_tax' => $dateField($get('mv_tax_validity')),
    ];

    $insert->execute([
        $orgId, $dept['campus_id'], $dept['id'], $vehicleNumber, $get('vehicle_type'), $get('model'),
        $dateField($get('date_of_registration')), $get('chassis_no'), $get('engine_no'), $get('vehicle_class'),
        is_numeric($get('passenger_capacity')) ? (int)$get('passenger_capacity') : null, $fuelType,
        $renewalDates['permit'], $renewalDates['puc'], $renewalDates['insurance'], $renewalDates['fitness'],
        $renewalDates['road_tax'], $renewalDates['green_tax'], $renewalDates['mv_tax'],
    ]);
    $newVehicleId = $pdo->lastInsertId();
    $existingNumbers[] = mb_strtolower($vehicleNumber);

    syncVehicleRenewals($pdo, $newVehicleId, $orgId, $dept['campus_id'], $dept['id'], $vehicleNumber, $renewalDates);
    $created++;
}
fclose($handle);

logActivity('वाहन Import केले', 'vehicle', null, "$created वाहनं जोडली");
$_SESSION['flash'] = "$created नवीन वाहनं यशस्वीरित्या जोडली गेली.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: import.php');
exit;
