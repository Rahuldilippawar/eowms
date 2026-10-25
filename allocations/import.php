<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'asset_items', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_asset_allocations') || hasPermission('manage_checkout') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $added = 0; $skipped = 0;

    if (!empty($_FILES['csv_file']['tmp_name']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $campusLookupStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1");
        $campusLookupStmt->execute([$orgId]);
        $campusLookup = [];
        foreach ($campusLookupStmt->fetchAll() as $c) { $campusLookup[mb_strtolower(trim($c['name']))] = $c['id']; }

        $typeLookupStmt = $pdo->prepare("SELECT id, name FROM asset_allocation_types WHERE organization_id = ? AND is_active = 1");
        $typeLookupStmt->execute([$orgId]);
        $typeLookup = [];
        foreach ($typeLookupStmt->fetchAll() as $t) { $typeLookup[mb_strtolower(trim($t['name']))] = $t['id']; }
        $isLoanDefault = isset($_POST['is_loan']) ? 1 : 0;

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $isFirstRow = true;
        while (($line = fgetcsv($handle)) !== false) {
            if ($isFirstRow) { $isFirstRow = false; continue; } // Header वगळतो
            if (count($line) < 1) { $skipped++; continue; }

            // स्तंभ क्रम: नाव, Type (प्रकार), कुठे ठेवतात, College/School (ऐच्छिक)
            [$name, $typeName, $location, $campusName] = array_pad(array_map('trim', $line), 4, '');
            if (!$name) { $skipped++; continue; }
            $typeName = $typeName ?: 'इतर वस्तू';

            // Type नसेल तर नवीन तयार करतो
            $typeKey = mb_strtolower($typeName);
            if (!isset($typeLookup[$typeKey])) {
                $pdo->prepare("INSERT INTO asset_allocation_types (organization_id, name, icon, is_loan, created_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$orgId, $typeName, $isLoanDefault ? 'fa-solid fa-key' : 'fa-solid fa-box', $isLoanDefault, $user['id']]);
                $typeLookup[$typeKey] = (int)$pdo->lastInsertId();
            }
            $campusId = $campusName ? ($campusLookup[mb_strtolower($campusName)] ?? null) : null;

            $pdo->prepare("INSERT INTO asset_items (organization_id, type_id, campus_id, name, default_location, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $typeLookup[$typeKey], $campusId, $name, $location ?: null, $user['id']]);
            $added++;
        }
        fclose($handle);
    }

    logActivity('Asset वस्तू Bulk Import केल्या', 'asset_items', null, "$added जोडल्या");
    $_SESSION['flash'] = "✅ $added वस्तू जोडल्या गेल्या." . ($skipped > 0 ? " ($skipped वगळल्या)" : '');
    header('Location: index.php?view=items');
    exit;
}

$pageTitle = 'वस्तू — Bulk Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📤 वस्तू — Bulk Import</div>
  <a href="index.php?view=items" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<div class="panel" style="max-width:650px;">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <label class="form-label-custom">CSV File</label>
    <input type="file" name="csv_file" accept=".csv" class="form-control-custom mb-2" required>
    <div style="background:var(--canvas);border-radius:9px;padding:12px 16px;font-size:11.5px;color:var(--text-dim);margin-bottom:12px;line-height:1.7;">
      <strong>स्तंभ क्रम (Header Row वगळली जाते):</strong><br>
      नाव, Type (उदा. Projector, चावी), कुठे ठेवतात, College/School (ऐच्छिक)
    </div>
    <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:16px;"><input type="checkbox" name="is_loan" checked style="width:15px;height:15px;"> नवीन तयार होणारे Types — 🔑 उसनं (Loan) प्रकारचे</label>
    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Import करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
