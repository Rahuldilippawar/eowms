<?php
/**
 * PWA / Home-Screen / Favicon साठी E-Admin चा App Icon.
 * उदा: icon.php?size=192  किंवा  icon.php?size=512
 *
 * हा Software चा (E-Admin) Logo आहे — संस्थेचा Logo वेगळा आहे आणि तो
 * Sidebar / Login पानावर संस्थेच्या नावासोबत दिसतो.
 */
$size = (int)($_GET['size'] ?? 192);
if (!in_array($size, [192, 512], true)) { $size = 192; }

header('Content-Type: image/png');
header('Cache-Control: public, max-age=86400');
readfile(__DIR__ . "/assets/icons/icon-$size.png");
exit;
