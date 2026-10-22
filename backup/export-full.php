<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if (!class_exists('ZipArchive')) {
    die('<div style="font-family:sans-serif;padding:40px;">तुमच्या Server वर PHP "Zip" Extension उपलब्ध नाही — त्यामुळे Files सकट Full Backup शक्य नाही. कृपया Hosting Provider ला "php-zip" Extension चालू करायला सांगा, किंवा साधा (फक्त Data) Backup वापरा. <a href="index.php">परत जा</a></div>');
}

require_once __DIR__ . '/../includes/backup-helpers.php';
$backup = buildOrgBackup($pdo, $orgId);
$jsonData = json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

$orgNameStmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
$orgNameStmt->execute([$orgId]);
$orgSlug = preg_replace('/[^a-zA-Z0-9]/', '-', $orgNameStmt->fetch()['name'] ?? 'org');
$zipFilename = "eowms-full-backup-{$orgSlug}-" . date('Y-m-d-His') . '.zip';
$tmpZipPath = sys_get_temp_dir() . '/' . uniqid('eowms_backup_') . '.zip';

$zip = new ZipArchive();
if ($zip->open($tmpZipPath, ZipArchive::CREATE) !== true) {
    die('Zip File तयार करता आली नाही.');
}

// 1. Database Data — तोच JSON, फक्त आता Zip च्या आत
$zip->addFromString('data.json', $jsonData);

// 2. सगळ्या Uploaded Files (Logo, Letter Attachments, Classroom Material,
// Work-Photos, इ.) — जशाच्या तशा, Folder-Structure सकट
$uploadsDir = __DIR__ . '/../assets/uploads';
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadsDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filePath = $file->getRealPath();
            $relativePath = 'uploads/' . substr($filePath, strlen(realpath($uploadsDir)) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
}

// संस्थेचा Logo (जो 'assets/uploads' च्या बाहेर, वेगळ्या ठिकाणी असू शकतो) —
// तोही शोधून जोडतो
if (!empty($backup['organization']['logo_path'])) {
    $logoFullPath = __DIR__ . '/../' . $backup['organization']['logo_path'];
    if (is_file($logoFullPath) && strpos(realpath($logoFullPath), realpath($uploadsDir)) === false) {
        $zip->addFile($logoFullPath, 'org-branding/' . basename($logoFullPath));
    }
}
if (!empty($backup['organization']['welcome_audio_path'])) {
    $audioFullPath = __DIR__ . '/../' . $backup['organization']['welcome_audio_path'];
    if (is_file($audioFullPath) && strpos(realpath($audioFullPath), realpath($uploadsDir)) === false) {
        $zip->addFile($audioFullPath, 'org-branding/' . basename($audioFullPath));
    }
}

$zip->close();

logActivity('Full Backup (Files सकट) Download केलं', 'backup', null, $zipFilename);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipFilename . '"');
header('Content-Length: ' . filesize($tmpZipPath));
readfile($tmpZipPath);
unlink($tmpZipPath);
exit;
