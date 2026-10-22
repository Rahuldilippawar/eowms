<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'dept_payment_ledger', '../');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$logId = (int)($_GET['id'] ?? 0);
$logStmt = $pdo->prepare("
    SELECT mgl.*, d.name_local AS dept_name, ml.name AS location_name
    FROM maintenance_logs mgl JOIN departments d ON d.id = mgl.department_id JOIN maintenance_locations ml ON ml.id = mgl.location_id
    WHERE mgl.id = ? AND mgl.organization_id = ?
");
$logStmt->execute([$logId, $orgId]);
$log = $logStmt->fetch();
if (!$log) { die('नोंद सापडली नाही.'); }

$accessibleDeptId = deptFinanceAccessibleDeptId($user);
$canManage = !$accessibleDeptId || $accessibleDeptId == $log['department_id'];
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = ($_POST['payment_date'] ?? '') ?: date('Y-m-d');
        $paymentType = in_array($_POST['payment_type'] ?? '', ['Advance','Partial','Final'], true) ? $_POST['payment_type'] : 'Partial';
        $paymentMode = trim($_POST['payment_mode'] ?? '') ?: null;
        $remarks = trim($_POST['remarks'] ?? '') ?: null;
        if ($amount > 0) {
            $pdo->prepare("INSERT INTO dept_payment_ledger (organization_id, department_id, source_type, source_id, amount, payment_date, payment_type, payment_mode, remarks, recorded_by) VALUES (?, ?, 'maintenance', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $log['department_id'], $logId, $amount, $paymentDate, $paymentType, $paymentMode, $remarks, $user['id']]);
            $_SESSION['flash'] = 'Payment नोंदवलं गेलं.';
        }
    }
    header('Location: payment.php?id=' . $logId);
    exit;
}

$pageTitle = 'Payment Track — ' . $log['item_description'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$paymentsStmt = $pdo->prepare("SELECT dpl.*, u.name AS recorder_name FROM dept_payment_ledger dpl JOIN users u ON u.id = dpl.recorded_by WHERE dpl.source_type = 'maintenance' AND dpl.source_id = ? ORDER BY dpl.payment_date DESC");
$paymentsStmt->execute([$logId]);
$payments = $paymentsStmt->fetchAll();

$totalPaid = array_sum(array_column($payments, 'amount'));
$totalCost = (float)($log['cost'] ?? 0);
$due = $totalCost - $totalPaid;
?>

<div class="page-eyebrow">देखभाल — <?= h($log['dept_name']) ?> · <?= h($log['location_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 Payment Track — <?= h($log['item_description']) ?></div>
  <a href="location-history.php?id=<?= $log['location_id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Cost</div><div class="val">₹<?= number_format($totalCost, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ Paid</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalPaid, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ बाकी</div><div class="val" style="color:<?= $due > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($due, 0) ?></div></div></div>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="panel-head" style="border:none;padding:0;"><div class="title">Payment History</div></div>
  <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addPaymentModal">+ नवीन Payment</button>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>रक्कम</th><th>प्रकार</th><th>पद्धत</th><th>नोंदवणारा</th></tr></thead>
    <tbody>
      <?php if (empty($payments)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलंही Payment नोंदवलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
          <td class="font-mono" style="font-weight:700;color:var(--rail);">₹<?= number_format($p['amount'], 0) ?></td>
          <td><?= h($p['payment_type']) ?></td>
          <td><?= h($p['payment_mode'] ?: '—') ?></td>
          <td><?= h($p['recorder_name']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="addPaymentModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_payment">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Payment नोंदवा</div>
        <label class="form-label-custom">रक्कम (₹)</label>
        <input type="number" step="0.01" name="amount" class="form-control-custom mb-2" required>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">प्रकार</label>
            <select name="payment_type" class="form-select-custom"><option value="Advance">Advance</option><option value="Partial" selected>Partial</option><option value="Final">Final</option></select>
          </div>
          <div class="col-md-6"><label class="form-label-custom">दिनांक</label><input type="date" name="payment_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <label class="form-label-custom">पद्धत (ऐच्छिक)</label>
        <input type="text" name="payment_mode" class="form-control-custom mb-2" placeholder="Cash/Cheque/Online">
        <label class="form-label-custom">टिप्पणी</label>
        <input type="text" name="remarks" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
