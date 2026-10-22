<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $pin = trim($_POST['biometric_pin'] ?? '') ?: null;
    try {
        $pdo->prepare("UPDATE users SET biometric_pin = ? WHERE id = ? AND organization_id = ?")->execute([$pin, $userId, $orgId]);
        $_SESSION['flash'] = 'PIN जोडला गेला.';
    } catch (PDOException $e) {
        $_SESSION['form_error'] = 'हा PIN आधीच दुसऱ्या व्यक्तीला दिलेला आहे.';
    }
    header('Location: pin-mapping.php');
    exit;
}

$pageTitle = 'Biometric PIN Mapping';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$search = trim($_GET['q'] ?? '');
$sql = "SELECT id, name, employee_code, biometric_pin, designation FROM users WHERE organization_id = ? AND is_active = 1";
$params = [$orgId];
if ($search) { $sql .= " AND name LIKE ?"; $params[] = "%$search%"; }
$sql .= " ORDER BY (biometric_pin IS NULL) DESC, name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

// अलीकडे Device वरून आलेले, पण अजून कोणाशी जुळलेले नाहीत असे PIN — जेणेकरून
// "हा नंबर कोणाचा आहे" हे लगेच कळेल
$unmatchedStmt = $pdo->prepare("
    SELECT DISTINCT biometric_pin FROM biometric_punch_logs
    WHERE biometric_pin NOT IN (SELECT biometric_pin FROM users WHERE organization_id = ? AND biometric_pin IS NOT NULL)
    ORDER BY biometric_pin LIMIT 20
");
try {
    $unmatchedStmt->execute([$orgId]);
    $unmatchedPins = $unmatchedStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $unmatchedPins = [];
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔢 Biometric PIN Mapping</div>
  <a href="devices.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Devices</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">प्रत्येक Staff ला Machine Configuration मध्ये दिलेला PIN Number इथे जोडा — तरच Attendance बरोबर जुळेल.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if (!empty($unmatchedPins)): ?>
  <div class="alert" style="background:var(--amber-soft, #FBEBD6);color:#8A5A14;border-radius:10px;font-size:12.5px;padding:14px 18px;margin-bottom:18px;">
    ⚠️ Machine कडून हे PIN आले आहेत, पण अजून कोणाशी जुळलेले नाहीत: <strong><?= h(implode(', ', $unmatchedPins)) ?></strong> — खालून योग्य व्यक्तीला जोडा.
  </div>
<?php endif; ?>

<form method="GET" class="mb-3">
  <input type="text" name="q" class="form-control-custom" style="max-width:280px;" placeholder="🔍 नावाने शोधा..." value="<?= h($search) ?>">
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>पद</th><th>Employee Code</th><th>Biometric PIN</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($staffList as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <td><?= h($s['designation'] ?: '—') ?></td>
          <td class="font-mono"><?= h($s['employee_code'] ?: '—') ?></td>
          <td>
            <form method="POST" class="d-flex gap-2">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <input type="text" name="biometric_pin" class="form-control-custom" style="width:110px;font-size:12px;padding:5px 10px;" value="<?= h($s['biometric_pin']) ?>" placeholder="PIN">
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:5px 12px;">जतन करा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
