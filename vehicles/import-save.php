<?php
// 🚌 वाहन Import — 1) File वाचून Preview ला पाठवतो  2) Preview मध्ये "Import करा" दाबल्यावर प्रत्यक्ष जोडतो
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
require_once __DIR__ . '/../includes/vehicle-import.php';
require_once __DIR__ . '/../includes/sheet-reader.php';
if (!canManageVehicles()) { requireRole(['super_admin'], '../'); } // वाहन यादी/नवीन वाहन प्रमाणेच
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: import.php'); exit; }
$tmpDir = __DIR__ . '/../storage/tmp';
if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }

// ---------- 2) Preview नंतर प्रत्यक्ष Import ----------
if (($_POST['action'] ?? '') === 'apply') {
    $tok = $_SESSION['vimport']['token'] ?? '';
    $file = $tmpDir . '/vimport-' . preg_replace('/[^a-f0-9]/', '', $tok) . '.json';
    if (!$tok || !is_file($file) || (int)($_SESSION['vimport']['org'] ?? 0) !== (int)$orgId) {
        $_SESSION['form_error'] = 'Preview जुना झाला — File पुन्हा Upload करा.';
        header('Location: import.php');
        exit;
    }
    $rows = json_decode(file_get_contents($file), true) ?: [];
    $opts = ['swap' => !empty($_POST['swap']), 'fallback_dept' => (int)($_POST['fallback_dept'] ?? 0), 'update' => !empty($_POST['update']), 'no_dept_ok' => !empty($_SESSION['vimport']['report'])];
    $res = vehImportAnalyse($pdo, $orgId, $rows, $opts);
    try {
        [$created, $updated, $renewals] = vehImportApply($pdo, $orgId, $res['items']);
    } catch (Throwable $e) {
        $_SESSION['form_error'] = 'Import करताना त्रुटी — काहीही जोडलं नाही: ' . $e->getMessage();
        header('Location: import.php?preview=1');
        exit;
    }
    @unlink($file);
    unset($_SESSION['vimport']);
    $skipped = count(array_filter($res['items'], fn($i) => !in_array($i['action'], ['create', 'update'], true)));
    logActivity('वाहन Import केले', 'vehicle', null, "$created नवीन, $updated अद्ययावत, $renewals नूतनीकरण-तारखा" . ($skipped ? ", $skipped वगळली" : ''));
    $_SESSION['flash'] = "✅ $created नवीन वाहनं जोडली" . ($updated ? ", $updated अद्ययावत केली" : '') . " — $renewals नूतनीकरण-तारखा \"नूतनीकरण व अनुपालन\" मध्ये आपोआप जोडल्या." . ($skipped ? " ($skipped ओळी वगळल्या)" : '');
    header('Location: import.php');
    exit;
}

// ---------- 1) File Upload → Preview ----------
$up = $_FILES['csv_file'] ?? null;
if (!$up || $up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) {
    $_SESSION['form_error'] = 'File मिळाली नाही — पुन्हा निवडा.';
    header('Location: import.php');
    exit;
}
if ($up['size'] > 5 * 1024 * 1024) {
    $_SESSION['form_error'] = 'File 5 MB पेक्षा मोठी आहे.';
    header('Location: import.php');
    exit;
}
try {
    $rows = eowmsReadSheet($up['tmp_name'], $up['name']);
} catch (Throwable $e) {
    $_SESSION['form_error'] = $e->getMessage();
    header('Location: import.php');
    exit;
}
// परिवहन विभागाचा Report (दोन ओळींचं Header, From/To तारखा) जसाच्या तसा — Template मध्ये बदलतो; इतर Sheets मधून विमा खर्च
$reportTitle = null;
$others = [];
try {
    if (strtolower(pathinfo($up['name'], PATHINFO_EXTENSION)) === 'xlsx' || (string)@file_get_contents($up['tmp_name'], false, null, 0, 4) === "PK\x03\x04") {
        $names = eowmsReadXlsx($up['tmp_name'], 'names');
        for ($si = 1; $si < min(count($names), 8); $si++) { $others[] = eowmsReadXlsx($up['tmp_name'], $si); }
    }
} catch (Throwable $e) {}
if ($conv = vehImportFromReport($rows, $others)) { $rows = $conv['rows']; $reportTitle = $conv['title']; }
$rows = array_values(array_filter($rows, fn($r) => implode('', array_map(fn($c) => trim((string)$c), $r)) !== ''));
if (count($rows) < 2) {
    $_SESSION['form_error'] = 'File मध्ये Header शिवाय एकही ओळ नाही.';
    header('Location: import.php');
    exit;
}
if (count($rows) > 2001) {
    $_SESSION['form_error'] = 'एका वेळी जास्तीत जास्त 2000 वाहनं.';
    header('Location: import.php');
    exit;
}
// जुने Preview काढून टाका
foreach (glob($tmpDir . '/vimport-*.json') ?: [] as $old) { if (filemtime($old) < time() - 86400) { @unlink($old); } }
$tok = bin2hex(random_bytes(16));
file_put_contents($tmpDir . '/vimport-' . $tok . '.json', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
$_SESSION['vimport'] = ['token' => $tok, 'org' => $orgId, 'name' => mb_substr($up['name'], 0, 120), 'at' => time(), 'report' => $reportTitle !== null, 'hint' => mb_substr((string)$reportTitle, 0, 200)];
header('Location: import.php?preview=1');
exit;
