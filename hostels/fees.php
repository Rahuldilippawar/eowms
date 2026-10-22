<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_fee') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $feePeriod = trim($_POST['fee_period'] ?? '');
        $amountDue = (float)($_POST['amount_due'] ?? 0);
        $dueDate = ($_POST['due_date'] ?? '') ?: null;
        if ($studentId && $feePeriod && $amountDue > 0) {
            $pdo->prepare("INSERT INTO hostel_fees (student_id, fee_period, amount_due, due_date, created_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$studentId, $feePeriod, $amountDue, $dueDate, $user['id']]);
            $_SESSION['flash'] = 'Fee नोंदवली गेली.';
        }
    } elseif ($action === 'record_payment') {
        $feeId = (int)($_POST['fee_id'] ?? 0);
        $amountPaid = (float)($_POST['amount_paid'] ?? 0);
        $paymentMode = $_POST['payment_mode'] ?? 'cash';
        $remarks = trim($_POST['remarks'] ?? '');

        if ($feeId && $amountPaid > 0) {
            $pdo->prepare("INSERT INTO hostel_fee_payments (fee_id, amount_paid, payment_mode, paid_date, received_by, remarks) VALUES (?, ?, ?, CURDATE(), ?, ?)")
                ->execute([$feeId, $amountPaid, $paymentMode, $user['id'], $remarks]);

            $feeStmt = $pdo->prepare("SELECT amount_due, (SELECT COALESCE(SUM(amount_paid),0) FROM hostel_fee_payments WHERE fee_id = hostel_fees.id) AS total_paid FROM hostel_fees WHERE id = ?");
            $feeStmt->execute([$feeId]);
            $fee = $feeStmt->fetch();
            $newStatus = $fee['total_paid'] >= $fee['amount_due'] ? 'paid' : ($fee['total_paid'] > 0 ? 'partial' : 'pending');
            $pdo->prepare("UPDATE hostel_fees SET status = ? WHERE id = ?")->execute([$newStatus, $feeId]);
            $_SESSION['flash'] = 'भरणा नोंदवला गेला.';
        }
    }
    header('Location: fees.php');
    exit;
}

$pageTitle = 'विद्यार्थी Fee व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$studentsStmt = $pdo->prepare("SELECT hs.id, hs.name, h.name AS hostel_name FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ? AND hs.is_active = 1 ORDER BY hs.name");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$feesStmt = $pdo->prepare("
    SELECT f.*, hs.name AS student_name, h.name AS hostel_name,
      (SELECT COALESCE(SUM(amount_paid),0) FROM hostel_fee_payments WHERE fee_id = f.id) AS total_paid
    FROM hostel_fees f JOIN hostel_students hs ON hs.id = f.student_id JOIN hostels h ON h.id = hs.hostel_id
    WHERE h.organization_id = ? ORDER BY f.status = 'paid', f.due_date
");
$feesStmt->execute([$orgId]);
$fees = $feesStmt->fetchAll();

$totalDue = array_sum(array_column($fees, 'amount_due'));
$totalPaid = array_sum(array_column($fees, 'total_paid'));

$statusLabels = ['pending' => '⏳ बाकी', 'partial' => '🟡 काही भरलं', 'paid' => '✅ पूर्ण भरलं'];
$statusChips = ['pending' => 'overdue', 'partial' => 'pending', 'paid' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 विद्यार्थी Fee व्यवस्थापन</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वसतिगृह</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Fee</div><div class="val">₹<?= number_format($totalDue, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">जमा झालेलं</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalPaid, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">बाकी</div><div class="val" style="color:var(--rose);">₹<?= number_format($totalDue - $totalPaid, 0) ?></div></div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Fee नोंदवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add_fee">
    <div class="row g-2">
      <div class="col-md-4">
        <select name="student_id" class="form-select-custom" required>
          <option value="">-- विद्यार्थी --</option>
          <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['hostel_name']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><input type="text" name="fee_period" class="form-control-custom" placeholder="उदा. जून 2026" required></div>
      <div class="col-md-2"><input type="number" step="0.01" name="amount_due" class="form-control-custom" placeholder="रक्कम (₹)" required></div>
      <div class="col-md-2"><input type="date" name="due_date" class="form-control-custom"></div>
      <div class="col-md-1"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">जोडा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विद्यार्थी</th><th>कालावधी</th><th>रक्कम</th><th>जमा</th><th>बाकी</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($fees)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही Fee नोंदवलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($fees as $f): $balance = $f['amount_due'] - $f['total_paid']; ?>
        <tr>
          <td style="font-weight:600;"><?= h($f['student_name']) ?><div style="font-size:10px;color:var(--text-dim);"><?= h($f['hostel_name']) ?></div></td>
          <td><?= h($f['fee_period']) ?></td>
          <td class="font-mono">₹<?= number_format($f['amount_due'], 0) ?></td>
          <td class="font-mono" style="color:var(--rail);">₹<?= number_format($f['total_paid'], 0) ?></td>
          <td class="font-mono" style="color:<?= $balance > 0 ? 'var(--rose)' : 'inherit' ?>;">₹<?= number_format($balance, 0) ?></td>
          <td><span class="status-chip <?= $statusChips[$f['status']] ?>"><?= $statusLabels[$f['status']] ?></span></td>
          <td>
            <?php if ($f['status'] !== 'paid'): ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#payModal<?= $f['id'] ?>">भरणा नोंदवा</button>
            <?php endif; ?>
          </td>
        </tr>
        <div class="modal fade" id="payModal<?= $f['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="action" value="record_payment"><input type="hidden" name="fee_id" value="<?= $f['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:10px;"><?= h($f['student_name']) ?> — <?= h($f['fee_period']) ?> (बाकी ₹<?= number_format($balance, 0) ?>)</div>
                <label class="form-label-custom">भरलेली रक्कम (₹)</label>
                <input type="number" step="0.01" name="amount_paid" class="form-control-custom mb-3" max="<?= $balance ?>" required>
                <label class="form-label-custom">पद्धत</label>
                <select name="payment_mode" class="form-select-custom mb-3">
                  <option value="cash">रोख</option><option value="online">Online</option><option value="cheque">धनादेश</option><option value="other">इतर</option>
                </select>
                <label class="form-label-custom">टिप्पणी</label>
                <input type="text" name="remarks" class="form-control-custom">
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
