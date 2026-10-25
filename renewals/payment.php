<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'dept_payment_ledger', '../');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$itemId = (int)($_GET['id'] ?? 0);
$itemStmt = $pdo->prepare("SELECT ri.*, d.name_local AS dept_name FROM renewal_items ri JOIN departments d ON d.id = ri.department_id WHERE ri.id = ? AND ri.organization_id = ?");
$itemStmt->execute([$itemId, $orgId]);
$item = $itemStmt->fetch();
if (!$item) { die('नोंद सापडली नाही.'); }

$canManage = deptFinanceCanAccessDept($user, $item['department_id']);
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_payment') {
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = ($_POST['payment_date'] ?? '') ?: date('Y-m-d');
        $paymentType = in_array($_POST['payment_type'] ?? '', ['Advance','Partial','Final'], true) ? $_POST['payment_type'] : 'Partial';
        $paymentMode = trim($_POST['payment_mode'] ?? '') ?: null;
        $forMonth = !empty($_POST['for_month']) ? $_POST['for_month'] . '-01' : null;
        $remarks = trim($_POST['remarks'] ?? '') ?: null;
        if ($amount > 0) {
            $pdo->prepare("INSERT INTO dept_payment_ledger (organization_id, department_id, source_type, source_id, amount, payment_date, payment_type, payment_mode, for_month, remarks, recorded_by) VALUES (?, ?, 'renewal', ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $item['department_id'], $itemId, $amount, $paymentDate, $paymentType, $paymentMode, $forMonth, $remarks, $user['id']]);
            // नूतनीकरणाचा खर्च — प्रत्येक Payment एकदाच विभाग Finance (Budget) Ledger मध्ये
            recordBudgetTransaction($pdo, $orgId, $item['department_id'], $amount, 'नूतनीकरण', 'renewal_payment', (int)$pdo->lastInsertId(), 'नूतनीकरण Payment — ' . $item['item_name'], $user['id'], $paymentDate);
            $_SESSION['flash'] = 'Payment नोंदवलं गेलं.';
        }
    }
    header('Location: payment.php?id=' . $itemId);
    exit;
}

$pageTitle = 'Payment Track — ' . $item['item_name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$paymentsStmt = $pdo->prepare("SELECT dpl.*, u.name AS recorder_name FROM dept_payment_ledger dpl JOIN users u ON u.id = dpl.recorded_by WHERE dpl.source_type = 'renewal' AND dpl.source_id = ? ORDER BY dpl.payment_date DESC");
$paymentsStmt->execute([$itemId]);
$payments = $paymentsStmt->fetchAll();

// "बाकी" — फक्त चालू Cycle चे Payments (शेवटच्या Renew नंतरचे) धरून
$totalPaid = deptFinanceRenewalCyclePaid($pdo, $itemId);
$totalCost = (float)($item['renewal_cost'] ?? 0);
$due = $totalCost - $totalPaid;

$paidMonths = array_filter(array_map(fn($p) => $p['for_month'] ? date('Y-m', strtotime($p['for_month'])) : null, $payments));
$recurringMonths = $item['is_recurring_monthly'] ? deptFinanceRecurringMonths($item['recurring_start_month'], $item['monthly_amount'], $paidMonths) : [];
?>

<div class="page-eyebrow">नूतनीकरण — <?= h($item['dept_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 Payment Track — <?= h($item['item_name']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!$item['is_recurring_monthly']): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Cost</div><div class="val">₹<?= number_format($totalCost, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ Paid (चालू Cycle)</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalPaid, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ बाकी</div><div class="val" style="color:<?= $due > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($due, 0) ?></div></div></div>
</div>
<?php endif; ?>

<?php if ($item['is_recurring_monthly']): ?>
<div class="panel mb-4">
  <div class="panel-head"><div class="title">🔁 Monthly Bill Grid — ₹<?= number_format($item['monthly_amount'], 0) ?>/महिना</div></div>
  <div style="padding:16px 20px;display:flex;flex-wrap:wrap;gap:8px;">
    <?php foreach ($recurringMonths as $m): ?>
      <div style="padding:8px 14px;border-radius:8px;font-size:12px;font-weight:600;background:<?= $m['paid'] ? '#DCEFEC' : '#F7E2E3' ?>;color:<?= $m['paid'] ? 'var(--rail)' : 'var(--rose)' ?>;">
        <?= $m['paid'] ? '🟢' : '🔴' ?> <?= h($m['label']) ?>
      </div>
    <?php endforeach; ?>
    <?php if (empty($recurringMonths)): ?><p style="font-size:12px;color:var(--text-dim);">Start-Month सेट नाही.</p><?php endif; ?>
  </div>
  <div style="padding:0 20px 16px;font-size:11.5px;color:var(--text-dim);">💡 एकूण <?= count($recurringMonths) ?> महिने, त्यापैकी <?= count(array_filter($recurringMonths, fn($m) => $m['paid'])) ?> भरलेले</div>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <div class="panel-head" style="border:none;padding:0;"><div class="title">Payment History</div></div>
  <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addPaymentModal">+ नवीन Payment</button>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>रक्कम</th><th>प्रकार</th><th>पद्धत</th><th>कोणत्या महिन्याचं</th><th>नोंदवणारा</th></tr></thead>
    <tbody>
      <?php if (empty($payments)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलंही Payment नोंदवलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($p['payment_date'])) ?></td>
          <td class="font-mono" style="font-weight:700;color:var(--rail);">₹<?= number_format($p['amount'], 0) ?></td>
          <td><?= h($p['payment_type']) ?></td>
          <td><?= h($p['payment_mode'] ?: '—') ?></td>
          <td><?= $p['for_month'] ? date('M Y', strtotime($p['for_month'])) : '—' ?></td>
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
        <input type="number" step="0.01" name="amount" class="form-control-custom mb-2" value="<?= $item['is_recurring_monthly'] ? $item['monthly_amount'] : '' ?>" required>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">प्रकार</label>
            <select name="payment_type" class="form-select-custom"><option value="Advance">Advance</option><option value="Partial" selected>Partial</option><option value="Final">Final</option></select>
          </div>
          <div class="col-md-6"><label class="form-label-custom">दिनांक</label><input type="date" name="payment_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <?php if ($item['is_recurring_monthly']): ?>
          <label class="form-label-custom">कोणत्या महिन्याचं Payment</label>
          <input type="month" name="for_month" class="form-control-custom mb-2" value="<?= date('Y-m') ?>">
        <?php endif; ?>
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
