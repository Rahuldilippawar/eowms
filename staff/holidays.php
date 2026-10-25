<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $date = $_POST['holiday_date'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
        // Campus NULL (संपूर्ण संस्थेची सुट्टी) असताना Unique Key Duplicate पकडत नाही — म्हणून स्पष्ट तपासणी
        $dupStmt = $pdo->prepare("SELECT id FROM holidays WHERE organization_id = ? AND campus_id <=> ? AND holiday_date = ? LIMIT 1");
        $dupStmt->execute([$orgId, $campusId, $date]);
        if ($date && $name && $dupStmt->fetch()) {
            $_SESSION['form_error'] = 'ही सुट्टी आधीच नोंदवलेली आहे.';
        } elseif ($date && $name) {
            try {
                $pdo->prepare("INSERT INTO holidays (organization_id, campus_id, holiday_date, name, created_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$orgId, $campusId, $date, $name, $user['id']]);
                $_SESSION['flash'] = 'सुट्टी जोडली गेली.';
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'ही सुट्टी आधीच नोंदवलेली आहे.';
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM holidays WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        $_SESSION['flash'] = 'सुट्टी काढली.';
    }
    header('Location: holidays.php');
    exit;
}

$pageTitle = 'सुट्ट्यांचं Calendar';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$year = (int)($_GET['year'] ?? date('Y'));
$holidaysStmt = $pdo->prepare("
    SELECT h.*, c.name AS campus_name FROM holidays h LEFT JOIN campuses c ON c.id = h.campus_id
    WHERE h.organization_id = ? AND YEAR(h.holiday_date) = ? ORDER BY h.holiday_date
");
$holidaysStmt->execute([$orgId, $year]);
$holidays = $holidaysStmt->fetchAll();

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForHoliday = $campusStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎉 सुट्ट्यांचं Calendar</div>
  <div class="d-flex gap-2">
    <a href="holidays-bulk-import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📥 Bulk Import</a>
    <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन सुट्टी जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add">
    <div class="row g-2">
      <div class="col-md-3"><input type="date" name="holiday_date" class="form-control-custom" required></div>
      <div class="col-md-4"><input type="text" name="name" class="form-control-custom" placeholder="उदा. गुरुपौर्णिमा, स्वातंत्र्य दिन" required></div>
      <div class="col-md-3">
        <select name="campus_id" class="form-select-custom">
          <option value="">-- सगळ्या Campus साठी --</option>
          <?php foreach ($campusesForHoliday as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">जोडा</button></div>
    </div>
  </form>
</div>

<form method="GET" class="mb-3">
  <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 2; $y--): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?></option><?php endfor; ?>
  </select>
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>नाव</th><th>लागू</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($holidays)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">या वर्षी कुठलीही सुट्टी नोंदवलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($holidays as $h): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y, l', strtotime($h['holiday_date'])) ?></td>
          <td style="font-weight:600;"><?= h($h['name']) ?></td>
          <td><?= h($h['campus_name'] ?: '🌐 सगळे Campus') ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('काढायची?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $h['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕ काढा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
