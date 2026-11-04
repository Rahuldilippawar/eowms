<?php
/**
 * ☁️ Email वर आलेली .eadmin Backup File उघडा (JSON बनवा) — Computer वर PHP असेल तर:
 *   php tools/decrypt-backup.php e-admin-backup-....eadmin
 * Password विचारला जाईल. तयार झालेली .json File E-Admin → Backup → Restore मध्ये वापरता येते
 * (किंवा .eadmin File थेट Restore पानावर Password सह द्या).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/offsite-backup.php';
$in = $argv[1] ?? '';
if (!is_file($in)) { exit("वापर: php tools/decrypt-backup.php <file.eadmin>\n"); }
echo 'Backup Password: ';
system('stty -echo 2>/dev/null');
$pw = trim((string)fgets(STDIN));
system('stty echo 2>/dev/null');
echo "\n";
$json = offsiteDecrypt(file_get_contents($in), $pw);
if ($json === null) { exit("❌ Password चुकीचा आहे किंवा File बिघडलेली आहे.\n"); }
$out = preg_replace('/\.eadmin$/i', '', $in) . '.json';
file_put_contents($out, $json);
echo "✅ तयार: $out (" . round(strlen($json) / 1024) . " KB)\n";
