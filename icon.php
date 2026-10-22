<?php
/**
 * PWA साठी संस्थेच्या स्वतःच्या Logo पासून योग्य आकाराचे square icon तयार करते.
 * उदा: icon.php?size=192  किंवा  icon.php?size=512
 *
 * संस्थेने अजून logo अपलोड केलेला नसेल, किंवा server वर GD library उपलब्ध
 * नसेल, तर आधीच तयार असलेल्या generic icon कडे वळवतं (fallback) —
 * त्यामुळे PWA install कधीही तुटत नाही.
 */
require_once __DIR__ . '/includes/auth.php';

$size = (int)($_GET['size'] ?? 192);
if (!in_array($size, [192, 512], true)) { $size = 192; }

$orgId = currentUser() ? activeOrgId() : null;
$logoPath = null;

if ($orgId) {
    $stmt = $pdo->prepare("SELECT logo_path FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $logoPath = $stmt->fetch()['logo_path'] ?? null;
} else {
    // Pre-login (Welcome page) — same "first active organization" fallback
    // that welcome.php itself uses for a typical single-tenant deployment.
    $row = $pdo->query("SELECT id, logo_path FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
    if ($row) {
        $orgId = $row['id'];
        $logoPath = $row['logo_path'];
    }
}

$fallback = __DIR__ . "/assets/icons/icon-$size.png";

function serveFallback($path) {
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=3600');
    readfile($path);
    exit;
}

if (!$logoPath || !extension_loaded('gd')) {
    serveFallback($fallback);
}

$fullLogoPath = __DIR__ . '/' . ltrim($logoPath, '/');
if (!is_file($fullLogoPath)) {
    serveFallback($fallback);
}

// Cache the generated icon on disk so we don't reprocess the logo on every
// single request — regenerate only if the source logo file changes.
$cacheDir = __DIR__ . '/assets/icons/cache';
if (!is_dir($cacheDir)) { @mkdir($cacheDir, 0755, true); }
$cacheFile = $cacheDir . '/org' . $orgId . '_' . $size . '_' . filemtime($fullLogoPath) . '.png';

if (is_file($cacheFile)) {
    serveFallback($cacheFile);
}

try {
    $info = @getimagesize($fullLogoPath);
    if (!$info) { serveFallback($fallback); }

    switch ($info['mime']) {
        case 'image/png': $src = @imagecreatefrompng($fullLogoPath); break;
        case 'image/jpeg': $src = @imagecreatefromjpeg($fullLogoPath); break;
        case 'image/webp': $src = @imagecreatefromwebp($fullLogoPath); break;
        case 'image/gif': $src = @imagecreatefromgif($fullLogoPath); break;
        default: $src = null;
    }
    if (!$src) { serveFallback($fallback); }

    $srcW = imagesx($src);
    $srcH = imagesy($src);

    // Square canvas, brand navy background (matches the app's theme —
    // most logos are white/transparent so a plain white square would look odd)
    $canvas = imagecreatetruecolor($size, $size);
    imagesavealpha($canvas, true);
    $navy = imagecolorallocate($canvas, 0x10, 0x16, 0x2B);
    imagefill($canvas, 0, 0, $navy);

    // Fit the logo within ~78% of the canvas (safe padding), centered
    $maxDim = (int)($size * 0.78);
    $scale = min($maxDim / $srcW, $maxDim / $srcH);
    $newW = (int)($srcW * $scale);
    $newH = (int)($srcH * $scale);
    $destX = (int)(($size - $newW) / 2);
    $destY = (int)(($size - $newH) / 2);

    imagecopyresampled($canvas, $src, $destX, $destY, 0, 0, $newW, $newH, $srcW, $srcH);
    imagedestroy($src);

    imagepng($canvas, $cacheFile);
    imagedestroy($canvas);

    serveFallback($cacheFile);
} catch (Throwable $e) {
    serveFallback($fallback);
}
