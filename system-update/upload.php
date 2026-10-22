<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/update-helpers.php';
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['update_zip']['tmp_name'])) {
    header('Location: index.php');
    exit;
}

if (!class_exists('ZipArchive')) {
    $_SESSION['form_error'] = 'Server वर PHP चा "zip" Extension Enabled नाही — त्यामुळे ZIP File उघडता येत नाही. खाली दिलेल्या सूचना पाळून तो सुरू करा, मग पुन्हा प्रयत्न करा.';
    header('Location: index.php');
    exit;
}

$stagingDir = __DIR__ . '/../update-staging';
if (!is_dir($stagingDir)) { mkdir($stagingDir, 0755, true); }

// आधीची अर्धवट Staging असेल तर आधी पूर्ण साफ करा (.htaccess सोडून)
updateClearDir($stagingDir);

if ($_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['form_error'] = 'File Upload अयशस्वी झाली — कदाचित ती खूप मोठी आहे (Server ची मर्यादा तपासा).';
    header('Location: index.php');
    exit;
}

$ext = strtolower(pathinfo($_FILES['update_zip']['name'], PATHINFO_EXTENSION));
if ($ext !== 'zip') {
    $_SESSION['form_error'] = 'फक्त .zip File Upload करता येईल.';
    header('Location: index.php');
    exit;
}

$tmpZipPath = $stagingDir . '/_upload.zip';
if (!move_uploaded_file($_FILES['update_zip']['tmp_name'], $tmpZipPath)) {
    $_SESSION['form_error'] = 'File सेव्ह होऊ शकली नाही — पुन्हा प्रयत्न करा.';
    header('Location: index.php');
    exit;
}

$zip = new ZipArchive();
if ($zip->open($tmpZipPath) !== true) {
    unlink($tmpZipPath);
    $_SESSION['form_error'] = 'ही एक वैध ZIP File दिसत नाही — पुन्हा प्रयत्न करा.';
    header('Location: index.php');
    exit;
}

// माझ्या दिलेल्या ZIP मध्ये सगळं "eowms-php/" या एकाच Folder च्या आत असतं —
// ते आपोआप ओळखून, आतलाच खरा Content Staging मध्ये काढतो (उगाच एक जास्तीचा
// थर तयार होऊ नये म्हणून).
$firstEntry = $zip->getNameIndex(0);
$hasWrapperFolder = $firstEntry && strpos($firstEntry, '/') !== false;
$wrapperPrefix = $hasWrapperFolder ? explode('/', $firstEntry)[0] . '/' : '';

// जर सगळ्याच Entries त्याच एका Wrapper Folder खाली असतील, तरच तो थर काढून टाकतो
$allUnderWrapper = true;
for ($i = 0; $i < $zip->numFiles; $i++) {
    $name = $zip->getNameIndex($i);
    if ($wrapperPrefix && strpos($name, $wrapperPrefix) !== 0) { $allUnderWrapper = false; break; }
}

$extractDir = ($wrapperPrefix && $allUnderWrapper) ? $stagingDir . '/_raw' : $stagingDir;
if (!is_dir($extractDir)) { mkdir($extractDir, 0755, true); }
$zip->extractTo($extractDir);
$zip->close();
unlink($tmpZipPath);

if ($wrapperPrefix && $allUnderWrapper) {
    $innerPath = $extractDir . '/' . rtrim($wrapperPrefix, '/');
    if (is_dir($innerPath)) {
        updateCopyRecursive($innerPath, $stagingDir);
    }
    updateClearDir($extractDir);
    @rmdir($extractDir);
}

logActivity('System Update ZIP Upload केली', 'system_update', null, $_FILES['update_zip']['name']);
$_SESSION['flash'] = 'ZIP यशस्वीरित्या Upload झाली — खाली काय बदलणार आहे ते तपासा.';
header('Location: index.php');
exit;
