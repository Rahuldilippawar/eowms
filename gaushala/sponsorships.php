<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_sponsorships', '../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'animal_category');
require_once __DIR__ . '/helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_gaushala') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $cattleId = ($_POST['cattle_id'] ?? '') !== '' ? (int)$_POST['cattle_id'] : null;
        $donorName = trim($_POST['donor_name'] ?? '');
        $donorContact = trim($_POST['donor_contact'] ?? '') ?: null;
        $amount = (float)($_POST['amount'] ?? 0);
        $period = in_array($_POST['sponsorship_period'] ?? '', ['One-Time', 'Monthly', 'Yearly'], true) ? $_POST['sponsorship_period'] : 'One-Time';
        $startDate = ($_POST['start_date'] ?? '') ?: date('Y-m-d');
        $endDate = ($_POST['end_date'] ?? '') ?: null;
        $remarks = trim($_POST['remarks'] ?? '') ?: null;
        if ($donorName && $amount > 0) {
            $pdo->prepare("
                INSERT INTO gaushala_sponsorships (cattle_id, organization_id, donor_name, donor_contact, amount, sponsorship_period, start_date, end_date, remarks, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$cattleId, $orgId, $donorName, $donorContact, $amount, $period, $startDate, $endDate, $remarks, $user['id']]);
            $_SESSION['flash'] = 'देणगी-नोंद जोडली गेली.';
        }
    } elseif ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active','completed','cancelled'], true) ? $_POST['status'] : 'active';
        $pdo->prepare("UPDATE gaushala_sponsorships SET status = ? WHERE id = ? AND organization_id = ?")->execute([$status, $id, $orgId]);
        $_SESSION['flash'] = 'Update झालं.';
    }
    header('Location: sponsorships.php');
    exit;
}

$pageTitle = 'दान/Sponsorship';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sponsorshipsStmt = $pdo->prepare("
    SELECT gs.*, gc.tag_number, gc.name AS cattle_name
    FROM gaushala_sponsorships gs LEFT JOIN gaushala_cattle gc ON gc.id = gs.cattle_id
    WHERE gs.organization_id = ? ORDER BY gs.created_at DESC
");
$sponsorshipsStmt->execute([$orgId]);
$sponsorships = $sponsorshipsStmt->fetchAll();

$totalActive = array_sum(array_map(fn($s) => $s['status'] === 'active' ? $s['amount'] : 0, $sponsorships));
$activeCount = count(array_filter($sponsorships, fn($s) => $s['status'] === 'active'));

$cattleStmt = $pdo->prepare("SELECT id, tag_number, name FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' ORDER BY (tag_number IS NULL), tag_number, id");
$cattleStmt->execute([$orgId]);
$allCattle = $cattleStmt->fetchAll();

$statusLabels = ['active' => '🟢 सुरू आहे', 'completed' => '✅ पूर्ण झालं', 'cancelled' => '⚪ रद्द'];
$statusChips = ['active' => 'done', 'completed' => 'done', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💝 दान/Sponsorship</div>
  <div class="d-flex gap-2">
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
    <?php if ($canManage): ?><button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addModal">+ नवीन देणगी</button><?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🟢 सुरू असलेल्या</div><div class="val"><?= $activeCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण (सुरू)</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalActive, 0) ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Donor</th><th>गाय</th><th>रक्कम</th><th>प्रकार</th><th>सुरू</th><th>स्थिती</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($sponsorships)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही देणगी-नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($sponsorships as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['donor_name']) ?><?= $s['donor_contact'] ? '<div style="font-size:10.5px;color:var(--text-dim);">' . h($s['donor_contact']) . '</div>' : '' ?></td>
          <td><?= $s['cattle_id'] ? h(gsCattleTitle($s['tag_number'], $s['cattle_name'], $s['cattle_id'])) : '<span style="color:var(--text-dim);">सर्वसाधारण</span>' ?></td>
          <td class="font-mono" style="font-weight:600;">₹<?= number_format($s['amount'], 0) ?></td>
          <td style="font-size:12px;"><?= h($s['sponsorship_period']) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($s['start_date'])) ?></td>
          <td><span class="status-chip <?= $statusChips[$s['status']] ?>"><?= $statusLabels[$s['status']] ?></span></td>
          <?php if ($canManage): ?>
            <td>
              <?php if ($s['status'] === 'active'): ?>
                <form method="POST" class="d-flex gap-1">
                  <input type="hidden" name="action" value="update_status"><input type="hidden" name="id" value="<?= $s['id'] ?>"><input type="hidden" name="status" value="completed">
                  <button type="submit" style="border:none;background:none;color:var(--rail);font-size:11px;">✅ पूर्ण झालं</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन देणगी नोंदवा</div>
        <label class="form-label-custom">Donor चं नाव</label>
        <input type="text" name="donor_name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">Contact (ऐच्छिक)</label>
        <input type="text" name="donor_contact" class="form-control-custom mb-2">
        <label class="form-label-custom">कोणत्या गायीसाठी (ऐच्छिक — रिकामं ठेवलं तर सर्वसाधारण)</label>
        <select name="cattle_id" class="form-select-custom mb-2">
          <option value="">-- सर्वसाधारण देणगी --</option>
          <?php foreach ($allCattle as $c): ?><option value="<?= $c['id'] ?>"><?= h(gsCattleTitle($c['tag_number'], $c['name'], $c['id'])) ?></option><?php endforeach; ?>
        </select>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">रक्कम (₹)</label><input type="number" step="0.01" name="amount" class="form-control-custom" required></div>
          <div class="col-md-6">
            <label class="form-label-custom">प्रकार</label>
            <select name="sponsorship_period" class="form-select-custom"><option value="One-Time">एक-वेळ</option><option value="Monthly">दरमहा</option><option value="Yearly">दरवर्षी</option></select>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">सुरू दिनांक</label><input type="date" name="start_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-6"><label class="form-label-custom">संपण्याचा दिनांक (ऐच्छिक)</label><input type="date" name="end_date" class="form-control-custom"></div>
        </div>
        <label class="form-label-custom">टिप्पणी</label>
        <input type="text" name="remarks" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
