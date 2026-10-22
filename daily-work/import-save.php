<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

$orgId = activeOrgId();

// विभाग-Code -> id/campus_id असा जलद शोध-तक्ता
$deptStmt = $pdo->prepare("SELECT id, code, campus_id FROM departments WHERE organization_id = ?");
$deptStmt->execute([$orgId]);
$deptByCode = [];
foreach ($deptStmt->fetchAll() as $d) { $deptByCode[strtoupper(trim($d['code']))] = $d; }

// कर्मचाऱ्याचं नाव -> संपूर्ण माहिती असा जलद शोध-तक्ता (नावं तंतोतंत जुळणं अपेक्षित)
$staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1");
$staffStmt->execute([$orgId]);
$staffByName = [];
foreach ($staffStmt->fetchAll() as $s) { $staffByName[mb_strtolower(trim($s['name']))] = $s; }

$handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") { rewind($handle); }

$header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
if (!in_array('date', $header, true) || !in_array('department', $header, true) || !in_array('title', $header, true)) {
    fclose($handle);
    $_SESSION['form_error'] = 'CSV मध्ये "date", "department", व "title" हे columns असणे आवश्यक आहे.';
    header('Location: import.php');
    exit;
}
$colIndex = array_flip($header);

// work_records.employee_id व entered_by_user_id हे दोन्ही NOT NULL आहेत —
// employee_id साठी staff_names मधली पहिली जुळणारी व्यक्ती वापरतो, कोणीच
// जुळली नाही तर ही नोंद Import करणाऱ्या Super Admin च्याच नावे धरतो.
$insertWork = $pdo->prepare("
    INSERT INTO work_records (work_code, organization_id, campus_id, department_id, requested_by, employee_id, entered_by_role, entered_by_user_id, work_date, title, description, priority, status, estimated_cost, actual_cost, created_at)
    VALUES (?, ?, ?, ?, ?, ?, 'staff', ?, ?, ?, ?, ?, 'completed', ?, ?, ?)
");
$insertTeam = $pdo->prepare("INSERT INTO work_record_team (work_record_id, user_id) VALUES (?, ?)");

$created = 0;
$errors = [];
$rowNum = 1;

while (($row = fgetcsv($handle)) !== false) {
    $rowNum++;
    if (count($row) === 1 && trim($row[0]) === '') continue;

    $get = fn($col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

    $dateRaw = $get('date');
    $deptCode = strtoupper($get('department') ?? '');
    $title = $get('title');

    if (!$dateRaw || !strtotime($dateRaw)) { $errors[] = "ओळ {$rowNum}: चुकीचा/रिकामा दिनांक — वगळले"; continue; }
    if (!$title) { $errors[] = "ओळ {$rowNum}: शीर्षक (title) रिकामे आहे — वगळले"; continue; }
    if (!isset($deptByCode[$deptCode])) { $errors[] = "ओळ {$rowNum}: विभाग-code '{$deptCode}' सापडला नाही — वगळले"; continue; }

    $dept = $deptByCode[$deptCode];
    $workDate = date('Y-m-d', strtotime($dateRaw));
    $description = $get('description');
    $requestedBy = $get('requested_by');
    $priority = in_array($get('priority'), ['low', 'normal', 'high', 'urgent'], true) ? $get('priority') : 'normal';
    $estimatedCost = is_numeric($get('estimated_cost')) ? (float)$get('estimated_cost') : null;
    $actualCost = is_numeric($get('actual_cost')) ? (float)$get('actual_cost') : null;

    // staff_names मधली नावं आधी जुळवून बघतो — पहिली जुळणारी व्यक्ती employee_id म्हणून वापरतो
    $matchedStaffIds = [];
    $unmatchedNames = [];
    $staffNamesRaw = $get('staff_names');
    if ($staffNamesRaw) {
        foreach (explode(',', $staffNamesRaw) as $nameRaw) {
            $nameKey = mb_strtolower(trim($nameRaw));
            if ($nameKey === '') continue;
            if (isset($staffByName[$nameKey])) {
                $matchedStaffIds[] = $staffByName[$nameKey]['id'];
            } else {
                $unmatchedNames[] = trim($nameRaw);
            }
        }
    }
    $primaryEmployeeId = $matchedStaffIds[0] ?? $user['id']; // कोणीच जुळला नाही तर Import करणाऱ्याच्याच नावे

    $workCode = 'WRK-' . date('ymd', strtotime($workDate)) . '-' . strtoupper(substr(uniqid(), -5));

    $insertWork->execute([
        $workCode, $orgId, $dept['campus_id'], $dept['id'], $requestedBy, $primaryEmployeeId, $user['id'],
        $workDate, $title, $description, $priority, $estimatedCost, $actualCost, $workDate . ' 12:00:00',
    ]);
    $newWorkId = $pdo->lastInsertId();
    $created++;

    // टीम-निहाय नोंद — जुळलेले सगळे कर्मचारी (Primary सकट) जोडतो
    foreach ($matchedStaffIds as $sid) {
        try { $insertTeam->execute([$newWorkId, $sid]); } catch (PDOException $e) { /* duplicate — ignore */ }
    }
    foreach ($unmatchedNames as $badName) {
        $errors[] = "ओळ {$rowNum}: कर्मचारी '{$badName}' सापडला नाही — टीम मध्ये जोडला नाही (नोंद मात्र झाली)";
    }
}
fclose($handle);

logActivity('जुन्या कामाच्या नोंदी Import केल्या', 'work_record', null, "$created नोंदी जोडल्या");
$_SESSION['flash'] = "$created जुन्या नोंदी यशस्वीरित्या Import झाल्या.";
if (!empty($errors)) { $_SESSION['import_errors'] = $errors; }
header('Location: import.php');
exit;
