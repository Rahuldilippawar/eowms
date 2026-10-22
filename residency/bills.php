<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId = (int)($_POST['location_id'] ?? 0);
    $billingMonth = $_POST['billing_month'] ?? date('Y-m');
    $roomRent = (float)($_POST['room_rent'] ?? 0);
    $electricityBill = (float)($_POST['electricity_bill'] ?? 0);
    $waterBill = (float)($_POST['water_bill'] ?? 0);
    $totalAmount = $roomRent + $electricityBill + $waterBill;

    // या Room मध्ये सध्या राहणारे "Staff" प्रकारचे Occupants (Family Members
    // वेगळे मोजत नाही — ते त्या Staff च्याच बिलात समाविष्ट धरले जातात).
    // एकच Staff (त्याचं कुटुंब असो वा नसो) असेल — त्याला पूर्ण बिल.
    // अनेक Staff (Sharing/भाडेकरू पद्धत) असतील — प्रत्येकाला समान वाटा.
    $occStmt = $pdo->prepare("SELECT id, user_id, name FROM residency_occupants WHERE location_id = ? AND organization_id = ? AND occupant_type = 'staff' AND status = 'active'");
    $occStmt->execute([$locationId, $orgId]);
    $staffOccupants = $occStmt->fetchAll();

    if ($locationId && $totalAmount > 0 && !empty($staffOccupants)) {
        $unitCount = count($staffOccupants);
        $perPersonAmount = round($totalAmount / $unitCount, 2);

        try {
            $pdo->beginTransaction();
            $insBill = $pdo->prepare("
                INSERT INTO residency_bills (organization_id, location_id, billing_month, room_rent, electricity_bill, water_bill, total_amount, billing_unit_count, per_person_amount, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insBill->execute([$orgId, $locationId, $billingMonth, $roomRent, $electricityBill, $waterBill, $totalAmount, $unitCount, $perPersonAmount, $user['id']]);
            $billId = $pdo->lastInsertId();

            $insShare = $pdo->prepare("INSERT INTO residency_bill_shares (bill_id, occupant_id, amount_due) VALUES (?, ?, ?)");
            foreach ($staffOccupants as $occ) {
                $insShare->execute([$billId, $occ['id'], $perPersonAmount]);
                if ($occ['user_id']) {
                    notifyUser($occ['user_id'], $orgId, 'निवास-बिल तयार झालं', "$billingMonth साठी ₹" . number_format($perPersonAmount, 0) . " भरायचे आहे", 'residency/my-residency.php');
                }
            }
            $pdo->commit();
            logActivity('निवास-बिल तयार केलं', 'residency_bill', $billId, "$billingMonth — ₹" . number_format($totalAmount, 0));
            $_SESSION['flash'] = "बिल तयार झालं — $unitCount जणांमध्ये प्रत्येकी ₹" . number_format($perPersonAmount, 0) . " विभागलं गेलं.";
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['form_error'] = 'या Room चं या महिन्याचं बिल आधीच तयार आहे, किंवा दुसरी त्रुटी आली.';
        }
    } else {
        $_SESSION['form_error'] = 'कृपया Room निवडा, रक्कम टाका — आणि त्या Room मध्ये किमान 1 Staff Occupant असणे आवश्यक आहे.';
    }
    header('Location: bills.php');
    exit;
}

if (isset($_GET['mark_paid'])) {
    $shareId = (int)$_GET['mark_paid'];
    $pdo->prepare("UPDATE residency_bill_shares s JOIN residency_bills rb ON rb.id = s.bill_id SET s.status = 'paid', s.paid_at = NOW() WHERE s.id = ? AND rb.organization_id = ?")->execute([$shareId, $orgId]);
    $_SESSION['flash'] = 'भरणा नोंदवला गेला.';
    header('Location: bills.php');
    exit;
}

$pageTitle = 'निवास-बिल (भाडं/वीज)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$locStmt = $pdo->prepare("
    SELECT ml.*, b.name AS building_name,
      (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND occupant_type = 'staff' AND status = 'active') AS staff_count
    FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ml.organization_id = ? AND ml.is_active = 1
    ORDER BY b.name, ml.name
");
$locStmt->execute([$orgId]);
$locations = $locStmt->fetchAll();

$billsStmt = $pdo->prepare("
    SELECT rb.*, ml.name AS room_name, b.name AS building_name
    FROM residency_bills rb JOIN maintenance_locations ml ON ml.id = rb.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE rb.organization_id = ? ORDER BY rb.billing_month DESC, ml.name LIMIT 50
");
$billsStmt->execute([$orgId]);
$bills = $billsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 निवास-बिल (भाडं/वीज)</div>
  <div class="d-flex gap-2">
    <a href="bills-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-chart-column me-1"></i>अहवाल</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>रहिवासी विभाग</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">💡 एकच Staff (कुटुंबासह) राहत असेल तर — त्याला पूर्ण बिल. अनेक Staff Sharing मध्ये राहत असतील तर — बिल समान वाटून प्रत्येकाला वेगळं.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन महिन्याचं बिल तयार करा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">Room निवडा</label>
        <select name="location_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>"><?= h($loc['building_name'] ? $loc['building_name'] . ' — ' : '') ?><?= h($loc['name']) ?> (<?= $loc['staff_count'] ?> Staff)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label-custom">महिना</label><input type="month" name="billing_month" class="form-control-custom" value="<?= date('Y-m') ?>" required></div>
      <div class="col-md-2"><label class="form-label-custom">खोली-भाडं (₹)</label><input type="number" step="0.01" name="room_rent" class="form-control-custom" required></div>
      <div class="col-md-2"><label class="form-label-custom">वीज-बिल (₹)</label><input type="number" step="0.01" name="electricity_bill" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">पाणी-बिल (₹)</label><input type="number" step="0.01" name="water_bill" class="form-control-custom"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">बिल तयार करा (आपोआप विभागेल)</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">अलीकडची बिलं</div></div>
  <table class="rtable">
    <thead><tr><th>Room</th><th>महिना</th><th>एकूण रक्कम</th><th>किती जणांत विभागलं</th><th>प्रत्येकी</th><th>तपशील</th></tr></thead>
    <tbody>
      <?php if (empty($bills)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही बिल तयार केलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($bills as $b):
        $sharesStmt = $pdo->prepare("SELECT s.*, o.name AS occ_name, u.name AS user_name FROM residency_bill_shares s JOIN residency_occupants o ON o.id = s.occupant_id LEFT JOIN users u ON u.id = o.user_id WHERE s.bill_id = ?");
        $sharesStmt->execute([$b['id']]);
        $shares = $sharesStmt->fetchAll();
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($b['building_name'] ? $b['building_name'] . ' — ' : '') ?><?= h($b['room_name']) ?></td>
          <td><?= h($b['billing_month']) ?></td>
          <td class="font-mono">₹<?= number_format($b['total_amount'], 0) ?></td>
          <td class="font-mono"><?= $b['billing_unit_count'] ?> जण</td>
          <td class="font-mono" style="font-weight:600;color:var(--rail);">₹<?= number_format($b['per_person_amount'], 0) ?></td>
          <td>
            <?php foreach ($shares as $s): ?>
              <div style="font-size:11px;margin-bottom:2px;">
                <?= h($s['user_name'] ?? $s['occ_name']) ?> —
                <?php if ($s['status'] === 'paid'): ?><span style="color:var(--rail);">✅ भरलं (<?= date('d M Y, h:i A', strtotime($s['paid_at'])) ?>)</span>
                <?php else: ?><a href="?mark_paid=<?= $s['id'] ?>" style="color:var(--amber);">⏳ भरायचं (भरलं म्हणून नोंदवा)</a><?php endif; ?>
              </div>
            <?php endforeach; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
