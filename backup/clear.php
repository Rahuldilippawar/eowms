<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();
$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$organization = $orgStmt->fetch();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmName = trim($_POST['confirm_name'] ?? '');
    $confirmedBackup = isset($_POST['confirmed_backup']);

    if (!$confirmedBackup) {
        $error = t('s547');
    } elseif ($confirmName !== $organization['name']) {
        $error = t('s548');
    } else {
        $pdo->beginTransaction();
        try {
            // Delete in dependency-safe order. Foreign keys with ON DELETE
            // CASCADE will also clean up child rows (documents, values, etc.)
            $tables = [
                "DELETE FROM work_requests WHERE organization_id = ?",
                "DELETE hst FROM hostel_students hst JOIN hostels h ON h.id = hst.hostel_id WHERE h.organization_id = ?",
                "DELETE hs FROM hostel_staff hs JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ?",
                "DELETE FROM hostels WHERE organization_id = ?",
                "DELETE FROM tasks WHERE organization_id = ?",
                "DELETE FROM renewal_items WHERE organization_id = ?",
                "DELETE cfv FROM custom_field_values cfv JOIN work_records wr ON wr.id = cfv.work_record_id WHERE wr.organization_id = ?",
                "DELETE FROM work_records WHERE organization_id = ?",
                "DELETE FROM custom_fields WHERE organization_id = ?",
            ];
            foreach ($tables as $sql) {
                try { $pdo->prepare($sql)->execute([$orgId]); } catch (PDOException $e) { /* table may not be migrated — skip */ }
            }

            // Users: delete everyone EXCEPT super_admin accounts (always protected, no exceptions)
            $pdo->prepare("DELETE FROM users WHERE organization_id = ? AND role != 'super_admin'")->execute([$orgId]);

            // Departments (hod_id already gone since non-admin users are deleted) and Campuses
            $pdo->prepare("UPDATE departments SET hod_id = NULL WHERE organization_id = ?")->execute([$orgId]);
            $pdo->prepare("DELETE FROM departments WHERE organization_id = ?")->execute([$orgId]);
            $pdo->prepare("DELETE FROM campuses WHERE organization_id = ?")->execute([$orgId]);

            $pdo->commit();
            logActivity('⚠️ संस्थेचा संपूर्ण डेटा क्लिअर केला', 'organization', $orgId, $organization['name']);
            $_SESSION['flash'] = "'{$organization['name']}' चा डेटा क्लिअर केला गेला. तुमचे Super Admin login सुरक्षित आहे. आता 'रिस्टोर करा' वापरून बॅकअप परत आणू शकता.";
            header('Location: index.php');
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'क्लिअर करताना त्रुटी आली: ' . $e->getMessage();
        }
    }
}

$pageTitle = t('s378');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title" style="color:var(--rose);"><?= h(t('s379')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel" style="border-color:var(--rose);">
  <div style="padding:24px;">
    <p style="font-size:13.5px;color:var(--text-dim);margin-bottom:16px;">
      यामुळे <b><?= h($organization['name']) ?></b> संस्थेचे सर्व कॅम्पस, विभाग, कर्मचारी (Super Admin वगळून), कामाच्या नोंदी, टास्क, नूतनीकरण, वसतिगृह, विद्यार्थी, आणि विनंत्या <b><?= h(t('s380')) ?></b> होतील. ही क्रिया परत करता येणार नाही.
    </p>
    <p style="font-size:13px;color:var(--rail);font-weight:600;margin-bottom:20px;">
      <i class="fa-solid fa-shield-halved me-1"></i>तुमचे स्वतःचे Super Admin login सुरक्षित राहील — डिलीट होणार नाही.
    </p>

    <form method="POST">
      <label style="display:flex;align-items:center;gap:10px;font-size:13.5px;margin-bottom:18px;">
        <input type="checkbox" name="confirmed_backup" required>
        मी <a href="export.php" target="_blank"><?= h(t('s381')) ?></a> केला आहे आणि सुरक्षित ठिकाणी जतन केला आहे.
      </label>

      <label class="form-label-custom"><?= h(t('s110')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($organization['name']) ?></span></label>
      <input type="text" name="confirm_name" class="form-control-custom" required style="max-width:400px;">

      <div class="d-flex gap-2 mt-4">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rose);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s382')) ?></button>
      </div>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
