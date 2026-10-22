<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_checkout') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $added = 0; $skipped = 0;

    if (!empty($_FILES['csv_file']['tmp_name']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $campusLookupStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1");
        $campusLookupStmt->execute([$orgId]);
        $campusLookup = [];
        foreach ($campusLookupStmt->fetchAll() as $c) { $campusLookup[mb_strtolower(trim($c['name']))] = $c['id']; }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $isFirstRow = true;
        while (($line = fgetcsv($handle)) !== false) {
            if ($isFirstRow) { $isFirstRow = false; continue; } // Header वगळतो
            if (count($line) < 1) { $skipped++; continue; }

            // स्तंभ क्रम: नाव, प्रकार, कुठे ठेवतात, College/School (ऐच्छिक)
            [$name, $category, $location, $campusName] = array_pad(array_map('trim', $line), 4, '');
            if (!$name) { $skipped++; continue; }

            $campusId = $campusName ? ($campusLookup[mb_strtolower($campusName)] ?? null) : null;

            $pdo->prepare("INSERT INTO checkout_items (organization_id, campus_id, name, category, default_location, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $category ?: null, $location ?: null, $user['id']]);
            $added++;
        }
        fclose($handle);
    }

    logActivity('Checkout Items Bulk Import केलं', 'checkout_items', null, "$added जोडल्या");
    $_SESSION['flash'] = "✅ $added वस्तू जोडल्या गेल्या." . ($skipped > 0 ? " ($skipped वगळल्या)" : '');
    header('Location: index.php');
    exit;
}

$pageTitle = 'Checkout Items — Bulk Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 Checkout Items — Bulk Import</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<div class="panel" style="max-width:650px;">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <label class="form-label-custom">CSV File</label>
    <input type="file" name="csv_file" accept=".csv" class="form-control-custom mb-2" required>
    <div style="background:var(--canvas);border-radius:9px;padding:12px 16px;font-size:11.5px;color:var(--text-dim);margin-bottom:16px;line-height:1.7;">
      <strong>स्तंभ क्रम (Header Row वगळली जाते):</strong><br>
      नाव, प्रकार, कुठे ठेवतात, College/School (ऐच्छिक)
    </div>
    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Import करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
