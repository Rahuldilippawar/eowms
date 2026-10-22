<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/update-helpers.php';
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$confirmText = trim($_POST['confirm_text'] ?? '');
if ($confirmText !== 'CONFIRM') {
    $_SESSION['form_error'] = 'तुम्ही बरोबर "CONFIRM" टाईप केलं नाही — Update Apply झालं नाही.';
    header('Location: index.php');
    exit;
}

$rootDir = realpath(__DIR__ . '/..');
$stagingDir = $rootDir . '/update-staging';

if (!is_dir($stagingDir) || count(array_diff(scandir($stagingDir), ['.', '..', '.htaccess'])) === 0) {
    $_SESSION['form_error'] = 'Apply करण्यासाठी आधी एक ZIP Upload करा.';
    header('Location: index.php');
    exit;
}

// ---- पायरी 1: Apply करण्याआधी — सध्याच्या सगळ्या Files चा Safety Backup ----
$backupDir = $rootDir . '/backups/update-safety';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }
$backupZipPath = $backupDir . '/pre-update-' . date('Y-m-d_His') . '.zip';

if (!class_exists('ZipArchive')) {
    $_SESSION['form_error'] = 'Server वर ZipArchive Extension उपलब्ध नाही — Update Apply करता येणार नाही. Hosting Support ला कळवा.';
    header('Location: index.php');
    exit;
}

$backupOk = updateCreateSafetyBackup($rootDir, $backupZipPath);
if (!$backupOk) {
    $_SESSION['form_error'] = 'Safety Backup तयार होऊ शकला नाही — सुरक्षिततेसाठी Update थांबवलं आहे.';
    header('Location: index.php');
    exit;
}

// ---- पायरी 2: Staging मधून Live Project मध्ये Copy करा (Protected Paths वगळून) ----
$copiedFiles = updateCopyRecursive($stagingDir, $rootDir);

// ---- पायरी 3: Staging रिकामी करा (पुढच्या Update साठी जागा मोकळी) ----
updateClearDir($stagingDir);

logActivity('System Update Apply केली', 'system_update', null, count($copiedFiles) . ' Files बदलल्या — Backup: ' . basename($backupZipPath));

$_SESSION['flash'] = "✅ Update यशस्वीरित्या Apply झालं — " . count($copiedFiles) . " Files बदलल्या. काही अडचण आली तर Safety Backup (\"" . basename($backupZipPath) . "\") वापरून परत आणता येईल.";
header('Location: index.php');
exit;
