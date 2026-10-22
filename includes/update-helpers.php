<?php
/**
 * System Self-Update — Helper Functions
 * ---------------------------------------------------------------
 * हे Folders/Files कधीही Update Process ने बदलले/काढले जात नाहीत —
 * यात User चा Data (Photos, Uploads), Backups, आणि Database Password
 * (config/) असतो. हीच सगळ्यात महत्त्वाची सुरक्षा-भिंत आहे.
 */
function updateProtectedPaths() {
    return ['assets/uploads', 'assets/audio', 'backups', 'config', 'update-staging'];
}

function updateIsProtectedPath($relativePath) {
    $relativePath = str_replace('\\', '/', $relativePath);
    foreach (updateProtectedPaths() as $protected) {
        if ($relativePath === $protected || strpos($relativePath, $protected . '/') === 0) {
            return true;
        }
    }
    return false;
}

/** Staging Folder मधून Live Project मध्ये — Protected Paths वगळून, सगळं Copy करते */
function updateCopyRecursive($src, $dest, $baseRelative = '') {
    $copiedFiles = [];
    $items = scandir($src);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $srcPath = $src . '/' . $item;
        $destPath = $dest . '/' . $item;
        $relativePath = $baseRelative ? $baseRelative . '/' . $item : $item;

        if (updateIsProtectedPath($relativePath)) { continue; } // सुरक्षा-भिंत — इथेच थांबतं

        if (is_dir($srcPath)) {
            if (!is_dir($destPath)) { mkdir($destPath, 0755, true); }
            $copiedFiles = array_merge($copiedFiles, updateCopyRecursive($srcPath, $destPath, $relativePath));
        } else {
            copy($srcPath, $destPath);
            $copiedFiles[] = $relativePath;
        }
    }
    return $copiedFiles;
}

/** Staging Folder पूर्णपणे रिकामी करते (पुढच्या Update साठी जागा मोकळी) */
function updateClearDir($dir) {
    if (!is_dir($dir)) return;
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) { updateClearDir($path); rmdir($path); } else { unlink($path); }
    }
}

/** Apply करण्याआधी — सध्याच्या सगळ्या Code Files चा Safety Backup (Protected Paths वगळून) */
function updateCreateSafetyBackup($rootDir, $backupZipPath) {
    $zip = new ZipArchive();
    if ($zip->open($backupZipPath, ZipArchive::CREATE) !== true) { return false; }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        $filePath = $file->getRealPath();
        $relativePath = substr($filePath, strlen($rootDir) + 1);
        $relativePath = str_replace('\\', '/', $relativePath);
        if (updateIsProtectedPath($relativePath)) { continue; }
        if (!$file->isFile()) continue;
        $zip->addFile($filePath, $relativePath);
    }
    $zip->close();
    return true;
}
