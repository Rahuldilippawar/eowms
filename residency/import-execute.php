<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/import-engine.php';

$token = $_POST['token'] ?? '';
$filename = $_POST['filename'] ?? '';

if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
    $_SESSION['form_error'] = 'अवैध Import Request — कृपया पुन्हा प्रयत्न करा.';
    header('Location: import.php');
    exit;
}

$tempPath = __DIR__ . '/../backups/residency-import-temp/' . $token . '.csv';
if (!is_file($tempPath)) {
    $_SESSION['form_error'] = 'Import File सापडली नाही — कृपया पुन्हा Upload करा (Preview जुनी झाली असावी).';
    header('Location: import.php');
    exit;
}

$parsed = residencyImportParseCsv($tempPath);
if (isset($parsed['error'])) {
    unlink($tempPath);
    $_SESSION['form_error'] = $parsed['error'];
    header('Location: import.php');
    exit;
}

$result = residencyImportExecute($pdo, $orgId, $user['id'], $parsed['rows'], $filename);
unlink($tempPath); // वापरून झाल्यावर तात्पुरती File लगेच काढून टाकतो

if (isset($result['error'])) {
    $_SESSION['form_error'] = $result['error'];
    header('Location: import.php');
    exit;
}

logActivity('रहिवासी Data Import केला', 'residency_import_batch', $result['batch_id'], "{$result['buildings_created']} Buildings + {$result['rooms_created']} Rooms + {$result['occupants_created']} निवासी");
$_SESSION['flash'] = "✅ Import यशस्वी — {$result['buildings_created']} नवीन Buildings, {$result['rooms_created']} नवीन Rooms, {$result['occupants_created']} निवासी-नोंदी जोडल्या गेल्या.";
if (!empty($result['errors'])) { $_SESSION['import_errors'] = $result['errors']; }
header('Location: import.php');
exit;
