<?php
require_once __DIR__ . '/includes/auth.php';

$orgName = 'E-Admin';
$orgIcon192 = 'icon.php?size=192';
$orgIcon512 = 'icon.php?size=512';

if (currentUser()) {
    $orgId = activeOrgId();
    $stmt = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    $org = $stmt->fetch();
    if ($org && !empty($org['name'])) {
        $orgName = $org['name'];
    }
    // icon.php स्वतःच संस्थेच्या प्रत्यक्ष Logo पासून योग्य square आकाराचं
    // Icon आपोआप तयार करतो (GD library वापरून) — Logo अपलोड केला की Mobile
    // Home Screen वरचं App Icon तोच दिसतो, वेगळं काही करावं लागत नाही.
}

header('Content-Type: application/manifest+json');
echo json_encode([
    'name' => 'E-Admin — ' . $orgName,
    'short_name' => 'E-Admin',
    'description' => 'E-Admin — Smart Administration',
    'start_url' => './index.php',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#10162B',
    'theme_color' => '#1B8A83',
    'lang' => 'mr',
    'icons' => [
        ['src' => $orgIcon192, 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => $orgIcon512, 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
        ['src' => 'assets/icons/icon-512-maskable.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
