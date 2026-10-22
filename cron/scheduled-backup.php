<?php
/**
 * Automated Scheduled Backup
 * ---------------------------------------------------------------
 * रोज (किंवा आठवड्यातून एकदा) चालवा — प्रत्येक संस्थेचा संपूर्ण डेटा
 * आपोआप Backup होतो, आणि जुने (RETENTION_COUNT पेक्षा जास्त) Backups
 * आपोआप काढले जातात — जेणेकरून जागा भरून जाणार नाही.
 *
 * Hostinger cPanel -> Cron Jobs मध्ये टाका (रोज रात्री 2 वाजता):
 *   0 2 * * *  php /home/xxxx/public_html/cron/scheduled-backup.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/backup-helpers.php';

// प्रत्येक संस्थेसाठी किती जुने Backups ठेवायचे (यापेक्षा जुने आपोआप डिलीट)
const RETENTION_COUNT = 14;

$backupDir = __DIR__ . '/../backups/auto/';
if (!is_dir($backupDir)) { mkdir($backupDir, 0755, true); }

$orgsStmt = $pdo->query("SELECT id, name FROM organizations WHERE is_active = 1");
$orgs = $orgsStmt->fetchAll();

foreach ($orgs as $org) {
    $orgId = $org['id'];
    try {
        $backup = buildOrgBackup($pdo, $orgId);
        $orgSlug = preg_replace('/[^a-zA-Z0-9]+/', '-', $org['name'] ?? 'organization');
        $filename = 'auto-backup-' . trim($orgSlug, '-') . '-' . date('Y-m-d_His') . '.json';
        $filePath = $backupDir . $filename;

        $json = json_encode($backup, JSON_UNESCAPED_UNICODE);
        file_put_contents($filePath, $json);
        $sizeKb = round(filesize($filePath) / 1024);

        $pdo->prepare("INSERT INTO scheduled_backups (organization_id, filename, file_size_kb, status) VALUES (?, ?, ?, 'success')")
            ->execute([$orgId, $filename, $sizeKb]);

        echo "[OK] {$org['name']} — $filename ($sizeKb KB)\n";

        // ---- Retention: फक्त शेवटचे RETENTION_COUNT Backups ठेवा ----
        $oldStmt = $pdo->prepare("SELECT id, filename FROM scheduled_backups WHERE organization_id = ? AND status = 'success' ORDER BY created_at DESC");
        $oldStmt->execute([$orgId]);
        $allBackups = $oldStmt->fetchAll();

        if (count($allBackups) > RETENTION_COUNT) {
            $toDelete = array_slice($allBackups, RETENTION_COUNT);
            foreach ($toDelete as $old) {
                $oldFilePath = $backupDir . $old['filename'];
                if (is_file($oldFilePath)) { unlink($oldFilePath); }
                $pdo->prepare("DELETE FROM scheduled_backups WHERE id = ?")->execute([$old['id']]);
            }
            echo "  → " . count($toDelete) . " जुने Backups काढले (Retention: " . RETENTION_COUNT . ")\n";
        }
    } catch (Throwable $e) {
        $pdo->prepare("INSERT INTO scheduled_backups (organization_id, filename, status, error_message) VALUES (?, '', 'failed', ?)")
            ->execute([$orgId, $e->getMessage()]);
        echo "[FAILED] {$org['name']} — " . $e->getMessage() . "\n";
    }
}

echo "Scheduled backup run complete: " . date('Y-m-d H:i:s') . "\n";
