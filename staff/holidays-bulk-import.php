<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $added = 0;
    $skipped = 0;

    $rows = [];
    if (!empty($_FILES['csv_file']['tmp_name']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        // CSV File — दिनांक,नाव या Format मध्ये
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $isFirstRow = true;
        while (($line = fgetcsv($handle)) !== false) {
            if ($isFirstRow) { $isFirstRow = false; if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $line[0] ?? '')) continue; }
            if (count($line) >= 2) { $rows[] = ['date' => trim($line[0]), 'name' => trim($line[1])]; }
        }
        fclose($handle);
    } else {
        // किंवा — थेट Textarea मध्ये पेस्ट केलेली यादी: "YYYY-MM-DD, नाव" प्रत्येक ओळीत
        $pasted = trim($_POST['pasted_list'] ?? '');
        foreach (explode("\n", $pasted) as $line) {
            $parts = array_map('trim', explode(',', $line, 2));
            if (count($parts) === 2 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[0])) {
                $rows[] = ['date' => $parts[0], 'name' => $parts[1]];
            }
        }
    }

    foreach ($rows as $r) {
        if (!$r['date'] || !$r['name']) { $skipped++; continue; }
        try {
            $pdo->prepare("INSERT INTO holidays (organization_id, campus_id, holiday_date, name, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $r['date'], $r['name'], $user['id']]);
            $added++;
        } catch (PDOException $e) {
            $skipped++; // आधीच अस्तित्वात असलेली
        }
    }

    logActivity('सुट्ट्या Bulk Import केल्या', 'holidays', null, "$added जोडल्या, $skipped वगळल्या");
    $_SESSION['flash'] = "✅ $added सुट्ट्या जोडल्या गेल्या." . ($skipped > 0 ? " ($skipped आधीच अस्तित्वात होत्या/अवैध होत्या, वगळल्या)" : '');
    header('Location: holidays.php');
    exit;
}

$pageTitle = 'सुट्ट्या Bulk Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForBulk = $campusStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 सुट्ट्या Bulk Import</div>
  <a href="holidays.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सुट्ट्यांचं Calendar</a>
</div>

<div class="panel" style="max-width:700px;">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <label class="form-label-custom">लागू कोणाला</label>
    <select name="campus_id" class="form-select-custom mb-3">
      <option value="">-- सगळ्या Campus साठी --</option>
      <?php foreach ($campusesForBulk as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>

    <label class="form-label-custom">पद्धत 1 — CSV File Upload करा</label>
    <input type="file" name="csv_file" accept=".csv" class="form-control-custom mb-2">
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:16px;">Format: प्रत्येक ओळीत — दिनांक (YYYY-MM-DD), सुट्टीचं नाव</p>

    <label class="form-label-custom">पद्धत 2 — किंवा थेट यादी Paste करा</label>
    <textarea name="pasted_list" class="form-control-custom mb-2" rows="8" placeholder="2026-01-26, प्रजासत्ताक दिन
2026-08-15, स्वातंत्र्य दिन
2026-10-02, गांधी जयंती"></textarea>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:16px;">प्रत्येक ओळीत — दिनांक, स्वल्पविराम, नाव — असं लिहा.</p>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Import करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
