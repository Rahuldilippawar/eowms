<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['csv_file']['tmp_name'])) {
    header('Location: import.php');
    exit;
}

$orgId = activeOrgId();

// Load departments keyed by lowercase English AND Marathi name for flexible matching
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ?");
$deptStmt->execute([$orgId]);
$deptsByKey = [];
foreach ($deptStmt->fetchAll() as $d) {
    $deptsByKey[mb_strtolower($d['name'])] = $d;
    if ($d['name_local']) {
        $deptsByKey[mb_strtolower($d['name_local'])] = $d;
    }
}

$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$organization = $orgStmt->fetch();
$defaultDomain = $organization['contact_email'] ? substr(strrchr($organization['contact_email'], '@'), 1) : 'org.local';

function randomPassword($length = 10) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $pw = '';
    for ($i = 0; $i < $length; $i++) {
        $pw .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pw;
}

// CSV / Excel (.xlsx) / जुनी Excel (.xls) — तिन्ही चालतात
require_once __DIR__ . '/../includes/sheet-reader.php';
try {
    $sheetRows = eowmsReadSheet($_FILES['csv_file']['tmp_name'], $_FILES['csv_file']['name'] ?? '');
} catch (Throwable $e) {
    $_SESSION['form_error'] = $e->getMessage();
    header('Location: import.php');
    exit;
}
// जुन्या Software ची विभागनिहाय "Employee List" दिली असेल तर — योग्य पानावर पाठवा
foreach (array_slice($sheetRows, 0, 15) as $r) {
    if (preg_grep('/^\s*(emp\s*name|desg1)\s*$/i', array_map('strval', $r))) {
        $_SESSION['form_error'] = 'ही विभागनिहाय कर्मचारी यादी (Employee List) आहे — ती वरच्या "📋 विभागनिहाय कर्मचारी यादी आयात" मधून द्या.';
        header('Location: import.php');
        exit;
    }
}
$header = array_map(fn($h) => strtolower(trim((string)$h)), array_shift($sheetRows) ?? []);
$required = ['name', 'department', 'designation', 'username'];

if (array_diff($required, $header)) {
    $_SESSION['form_error'] = t('s509');
    header('Location: import.php');
    exit;
}

$created = 0;
$skipped = [];
$credentials = [];
$rowNum = 1;

$insertStmt = $pdo->prepare("
    INSERT INTO users (organization_id, campus_id, department_id, name, email, mobile, designation, salary, joining_date, date_of_birth, password, must_change_password, role)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 'staff')
");
$checkStmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$loginUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/login.php';

// Trust-HQ (Campus नसलेल्या) विभागातल्या कर्मचाऱ्यांसाठी — संस्थेचा पहिला Campus
$firstCampusStmt = $pdo->prepare("SELECT id FROM campuses WHERE organization_id = ? ORDER BY id LIMIT 1");
$firstCampusStmt->execute([$orgId]);
$fallbackCampusId = $firstCampusStmt->fetch()['id'] ?? null;

foreach ($sheetRows as $row) {
    $row = array_map(fn($c) => is_float($c) && abs($c - round($c)) < 1e-9 ? sprintf('%.0f', $c) : (string)$c, $row);
    $rowNum++;
    // पूर्णपणे रिकामी ओळ — वगळा
    if (count(array_filter($row, fn($c) => trim((string)$c) !== '')) === 0) continue;
    // Excel अनेकदा शेवटी जास्तीचे रिकामे Cells (,,,) जोडतो किंवा कमी Cells ठेवतो —
    // Header च्या लांबीइतकं Pad/Trim करतो, नाहीतर array_combine Error देतो
    $row = array_slice(array_pad($row, count($header), ''), 0, count($header));
    $data = array_combine($header, $row);
    // Excel मधली तारीख (Serial अंक, उदा. 45444) → YYYY-MM-DD
    foreach (['joining_date', 'date_of_birth'] as $dc) { if (isset($data[$dc]) && preg_match('/^\d{5}$/', trim($data[$dc]))) { $data[$dc] = date('Y-m-d', (int)round(((int)$data[$dc] - 25569) * 86400)); } }

    $deptKey = mb_strtolower(trim($data['department']));
    $department = $deptsByKey[$deptKey] ?? null;

    if (!$department) {
        $skipped[] = "ओळ {$rowNum}: विभाग '{$data['department']}' सापडला नाही";
        continue;
    }

    $email = trim($data['username']);
    if (strpos($email, '@') === false) {
        $email .= '@' . $defaultDomain;
    }

    $checkStmt->execute([$email]);
    if ($checkStmt->fetch()) {
        $skipped[] = "ओळ {$rowNum}: '{$email}' आधीच अस्तित्वात आहे";
        continue;
    }

    // Secure default: generate a strong password unless the CSV explicitly supplied one
    $plainPassword = !empty($data['password'] ?? null) ? trim($data['password']) : randomPassword(10);

    $salary = !empty($data['salary'] ?? null) ? (float)trim($data['salary']) : null;
    $joiningDateRaw = trim($data['joining_date'] ?? '');
    $joiningDate = ($joiningDateRaw && strtotime($joiningDateRaw)) ? date('Y-m-d', strtotime($joiningDateRaw)) : date('Y-m-d');
    $mobile = trim($data['mobile'] ?? '') ?: null;
    $dobRaw = trim($data['date_of_birth'] ?? '');
    $dateOfBirth = ($dobRaw && strtotime($dobRaw)) ? date('Y-m-d', strtotime($dobRaw)) : null;

    try {
        $insertStmt->execute([
            $orgId, $department['campus_id'] ?: $fallbackCampusId, $department['id'],
            nameCase($data['name']), $email, $mobile, trim($data['designation']), $salary, $joiningDate, $dateOfBirth,
            password_hash($plainPassword, PASSWORD_DEFAULT),
        ]);
    } catch (PDOException $e) {
        // Duplicate (उदा. याच File मध्ये तोच Email दोनदा) — Blank पान न दाखवता ती ओळ वगळतो
        $skipped[] = (int)($e->errorInfo[1] ?? 0) === 1062
            ? "ओळ {$rowNum}: '{$email}' हा ईमेल / कर्मचारी कोड आधीच वापरात आहे"
            : "ओळ {$rowNum}: नोंद जतन करता आली नाही";
        continue;
    }
    $created++;
    // ऐच्छिक जादा रकाने — कर्मचारी क्रमांक, Gender, Aadhar, बँक, पत्ते
    $newId = (int)$pdo->lastInsertId();
    $extraMap = ['employee_code' => 'employee_code', 'gender' => 'gender', 'aadhar_number' => 'aadhar_number', 'bank_account_no' => 'bank_account_no', 'ifsc_code' => 'ifsc_code',
        'bank_name' => 'bank_name', 'branch_name' => 'branch_name', 'permanent_address' => 'permanent_address', 'local_address' => 'local_address', 'whatsapp_number' => 'whatsapp_number'];
    foreach ($extraMap as $colName => $dbCol) {
        $v = trim((string)($data[$colName] ?? ''));
        if ($v === '') continue;
        if ($dbCol === 'gender') { $v = stripos($v, 'f') === 0 ? 'Female' : (stripos($v, 'm') === 0 ? 'Male' : null); if (!$v) continue; }
        if ($dbCol === 'aadhar_number') { $v = preg_replace('/\D/', '', $v); if (strlen($v) !== 12) continue; }
        try { $pdo->prepare("UPDATE users SET `$dbCol` = ? WHERE id = ?")->execute([mb_substr($v, 0, 255), $newId]); }
        catch (PDOException $e) { $skipped[] = "ओळ {$rowNum}: '$colName' जतन झाला नाही (" . ((int)($e->errorInfo[1] ?? 0) === 1062 ? 'आधीच वापरात' : 'अवैध') . ")"; }
    }

    sendWelcomeEmail($email, nameCase($data['name']), $email, $plainPassword, activeOrgName(), 'staff', $loginUrl);

    $credentials[] = [
        'name' => nameCase($data['name']),
        'department' => $department['name_local'] ?: $department['name'],
        'email' => $email,
        'password' => $plainPassword,
    ];
}
logActivity('CSV द्वारे कर्मचारी आयात केले', 'user', null, "$created कर्मचारी जोडले");
$_SESSION['flash'] = "$created कर्मचारी यशस्वीरित्या जोडले गेले.";
$_SESSION['import_skipped'] = $skipped;
$_SESSION['import_credentials'] = $credentials;
header('Location: import.php');
exit;
