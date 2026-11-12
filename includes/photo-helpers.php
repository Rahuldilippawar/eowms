<?php
// 📷 कर्मचारी / विद्यार्थी फोटो Upload — एकाच ठिकाणी:
//   - फोनचे मोठे फोटो (5–10 MB) आपोआप लहान (800px JPEG ~100 KB) — पानं पटकन उघडतात
//   - फोन आडवा धरून काढलेला फोटो सरळ (EXIF Orientation)
//   - अयशस्वी झालं तर स्पष्ट कारण (खूप मोठा / HEIC / चुकीचा प्रकार) — आधी शांतपणे फोटो जात नसे
// परत: [path|null, error|null] — फोटो निवडलाच नसेल तर [null, null]

function eowmsSavePhotoUpload($file, $subdir, $prefix = 'photo_', $max = 800) {
    if (!is_array($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE || ($file['name'] ?? '') === '') { return [null, null]; }
    if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'फोटो खूप मोठा आहे (Server ची मर्यादा ' . ini_get('upload_max_filesize') . ') — लहान फोटो निवडा.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { return [null, 'फोटो Upload झाला नाही — पुन्हा प्रयत्न करा.']; }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['heic', 'heif'], true)) {
        return [null, 'iPhone चा HEIC फोटो चालत नाही — JPG / PNG फोटो निवडा (iPhone: Settings → Camera → Formats → Most Compatible).'];
    }
    $info = @getimagesize($file['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) { return [null, 'फक्त JPG / PNG / WEBP फोटो चालतो.']; }
    $subdir = trim(preg_replace('/[^a-z0-9_-]/i', '', (string)$subdir)) ?: 'misc';
    $dir = __DIR__ . '/../assets/uploads/' . $subdir . '/';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return [null, 'फोटो जतन करायला जागा नाही (Folder तयार होत नाही).']; }
    $base = uniqid(preg_replace('/[^a-z0-9_]/i', '', (string)$prefix));

    // GD असेल तर — सरळ करून लहान JPEG
    if (function_exists('imagecreatetruecolor')) {
        $src = null;
        if ($info[2] === IMAGETYPE_JPEG && function_exists('imagecreatefromjpeg')) { $src = @imagecreatefromjpeg($file['tmp_name']); }
        elseif ($info[2] === IMAGETYPE_PNG && function_exists('imagecreatefrompng')) { $src = @imagecreatefrompng($file['tmp_name']); }
        elseif ($info[2] === IMAGETYPE_WEBP && function_exists('imagecreatefromwebp')) { $src = @imagecreatefromwebp($file['tmp_name']); }
        if ($src) {
            if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($file['tmp_name'])['Orientation'] ?? 1);
                $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($deg) { $r = imagerotate($src, $deg, 0); if ($r) { imagedestroy($src); $src = $r; } }
            }
            $w = imagesx($src); $h = imagesy($src);
            $scale = min(1, $max / max($w, $h));
            $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // पारदर्शक PNG — पांढरी पार्श्वभूमी
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $ok = imagejpeg($dst, $dir . $base . '.jpg', 85);
            imagedestroy($src); imagedestroy($dst);
            if ($ok) { return ['assets/uploads/' . $subdir . '/' . $base . '.jpg', null]; }
        }
    }
    // GD नसेल / अयशस्वी — मूळ File तशीच
    $name = $base . '.' . $types[$info[2]];
    if (move_uploaded_file($file['tmp_name'], $dir . $name)) { return ['assets/uploads/' . $subdir . '/' . $name, null]; }
    return [null, 'फोटो जतन झाला नाही — पुन्हा प्रयत्न करा.'];
}

/** फोटोचा मार्ग दिला आहे पण File Server वर नाही का (Welcome / अहवालात तुटलेला फोटो दिसू नये) */
function eowmsPhotoExists($path) {
    if (!$path) return false;
    $path = ltrim(preg_replace('#^(\.\./)+#', '', (string)$path), '/');
    return is_file(__DIR__ . '/../' . $path);
}
