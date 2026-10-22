<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin');
$canManage = $isMgmt || hasCategory('hod') || hasPermission('manage_academics');
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campusId = (int)($_POST['campus_id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $academicYear = trim($_POST['academic_year'] ?? '');

    if (!$isMgmt && (int)$departmentId !== (int)($user['department_id'] ?? 0)) {
        http_response_code(403);
        die('तुम्ही फक्त स्वतःच्या विभागात Import करू शकता.');
    }

    $added = 0; $skipped = 0;
    if (!empty($_FILES['csv_file']['tmp_name']) && $_FILES['csv_file']['error'] === UPLOAD_ERR_OK) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        $isFirstRow = true;
        while (($line = fgetcsv($handle)) !== false) {
            if ($isFirstRow) { $isFirstRow = false; if (!is_numeric(trim($line[3] ?? ''))) continue; }
            // अपेक्षित स्तंभ: Roll No, Admission No, नाव, Year, Mobile, Parent Mobile
            if (count($line) < 4) { $skipped++; continue; }
            [$rollNo, $admissionNo, $name, $year] = array_map('trim', array_slice($line, 0, 4));
            $mobile = trim($line[4] ?? '');
            $parentMobile = trim($line[5] ?? '');
            if (!$rollNo || !$admissionNo || !$name) { $skipped++; continue; }

            try {
                $defaultPassword = password_hash($admissionNo, PASSWORD_DEFAULT);
                $pdo->prepare("
                    INSERT INTO students (organization_id, campus_id, department_id, roll_no, admission_no, name, year_of_study, academic_year, mobile, parent_mobile, password_hash, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$orgId, $campusId, $departmentId, $rollNo, $admissionNo, $name, (int)$year ?: 1, $academicYear, $mobile, $parentMobile, $defaultPassword, $user['id']]);
                $added++;
            } catch (PDOException $e) {
                $skipped++;
            }
        }
        fclose($handle);
    }

    logActivity('विद्यार्थी Bulk Import केले', 'students', null, "$added जोडले, $skipped वगळले");
    $_SESSION['flash'] = "✅ $added विद्यार्थी जोडले गेले." . ($skipped > 0 ? " ($skipped वगळले — Duplicate किंवा अपूर्ण माहिती)" : '');
    header('Location: students.php');
    exit;
}

$pageTitle = 'विद्यार्थी Bulk Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForImport = $campusStmt->fetchAll();

$deptStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1" . (!$isMgmt ? " AND id = " . (int)($user['department_id'] ?? 0) : "") . " ORDER BY name_local");
$deptStmt->execute([$orgId]);
$deptsForImport = $deptStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 विद्यार्थी Bulk Import</div>
  <a href="students.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>विद्यार्थी Master</a>
</div>

<div class="panel" style="max-width:650px;">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <div class="row g-2 mb-3">
      <div class="col-md-6">
        <label class="form-label-custom">College</label>
        <select name="campus_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($campusesForImport as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">Course/विभाग</label>
        <select name="department_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($deptsForImport as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <label class="form-label-custom">शैक्षणिक वर्ष (उदा. 2026-27)</label>
    <input type="text" name="academic_year" class="form-control-custom mb-3" required>

    <label class="form-label-custom">CSV File</label>
    <input type="file" name="csv_file" accept=".csv" class="form-control-custom mb-2" required>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:16px;">स्तंभ क्रम: Roll No, Admission No, नाव, Year (1-4), Mobile, Parent Mobile</p>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Import करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
