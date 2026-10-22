<?php
$pageTitle = 'माझं निवासस्थान';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$occStmt = $pdo->prepare("
    SELECT o.*, ml.name AS room_name, b.name AS building_name, b.id AS building_id
    FROM residency_occupants o JOIN maintenance_locations ml ON ml.id = o.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE o.organization_id = ? AND o.user_id = ? AND o.status = 'active'
");
$occStmt->execute([$orgId, $user['id']]);
$myOccupancy = $occStmt->fetch();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-3">🏠 माझं निवासस्थान</div>

<?php if (!$myOccupancy): ?>
  <div class="panel" style="padding:40px;text-align:center;">
    <div style="font-size:40px;margin-bottom:10px;">🏠</div>
    <div style="font-size:14px;color:var(--text-dim);margin-bottom:16px;">तुम्हाला सध्या कुठलीही Room वाटप झालेली नाही.</div>
    <a href="request-allotment.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">निवासासाठी विनंती करा</a>
  </div>
<?php else: ?>

  <?php
    $familyStmt = $pdo->prepare("SELECT * FROM residency_occupants WHERE location_id = ? AND occupant_type = 'family' AND status = 'active' AND user_id IS NULL ORDER BY name");
    $familyStmt->execute([$myOccupancy['location_id']]);
    $familyMembers = $familyStmt->fetchAll();

    $roommateStmt = $pdo->prepare("SELECT o.*, u.name AS user_name FROM residency_occupants o LEFT JOIN users u ON u.id = o.user_id WHERE o.location_id = ? AND o.occupant_type = 'staff' AND o.status = 'active' AND o.id != ? ORDER BY o.name");
    $roommateStmt->execute([$myOccupancy['location_id'], $myOccupancy['id']]);
    $roommates = $roommateStmt->fetchAll();

    $billsStmt = $pdo->prepare("
        SELECT rb.*, s.status AS my_status, s.amount_due, s.paid_at
        FROM residency_bills rb JOIN residency_bill_shares s ON s.bill_id = rb.id
        WHERE s.occupant_id = ? ORDER BY rb.billing_month DESC LIMIT 12
    ");
    $billsStmt->execute([$myOccupancy['id']]);
    $myBills = $billsStmt->fetchAll();

    $pendingCount = count(array_filter($myBills, fn($b) => $b['my_status'] === 'pending'));
    $totalPending = array_sum(array_map(fn($b) => $b['my_status'] === 'pending' ? $b['amount_due'] : 0, $myBills));
  ?>

  <div class="row g-3 mb-4">
    <div class="col-lg-8">
      <div class="panel">
        <div class="panel-head"><div class="title">🏢 तपशील</div></div>
        <div style="padding:20px 24px;">
          <div style="font-size:20px;font-weight:700;color:var(--ink);"><?= h($myOccupancy['building_name'] ? $myOccupancy['building_name'] . ' — ' : '') ?><?= h($myOccupancy['room_name']) ?></div>
          <div style="font-size:12.5px;color:var(--text-dim);margin-top:4px;">राहायला सुरुवात: <?= date('d M Y', strtotime($myOccupancy['moved_in_date'])) ?></div>

          <?php if (!empty($roommates)): ?>
            <div style="margin-top:16px;">
              <div style="font-size:12px;font-weight:600;color:var(--text-dim);margin-bottom:6px;">👥 सोबत राहणारे (Sharing)</div>
              <?php foreach ($roommates as $rm): ?>
                <div style="font-size:13px;padding:4px 0;"><?= h($rm['user_name'] ?? $rm['name']) ?></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($familyMembers)): ?>
            <div style="margin-top:16px;">
              <div style="font-size:12px;font-weight:600;color:var(--text-dim);margin-bottom:6px;">👪 माझे कुटुंबीय (नोंदवलेले)</div>
              <?php foreach ($familyMembers as $fm): ?>
                <div style="font-size:13px;padding:4px 0;"><?= h($fm['name']) ?> <span style="color:var(--text-dim);">(<?= h($fm['relation'] ?: '') ?>)</span></div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="kpi-mini mb-3"><div class="lbl">बाकी बिलं</div><div class="val" style="color:<?= $pendingCount > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $pendingCount ?></div></div>
      <div class="kpi-mini"><div class="lbl">एकूण बाकी रक्कम</div><div class="val" style="color:<?= $totalPending > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($totalPending, 0) ?></div></div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><div class="title">💰 माझं बिल-इतिहास (भाडं + वीज)</div></div>
    <table class="rtable">
      <thead><tr><th>महिना</th><th>खोली-भाडं</th><th>वीज-बिल</th><th>माझा वाटा</th><th>स्थिती</th></tr></thead>
      <tbody>
        <?php if (empty($myBills)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कुठलंही बिल तयार झालेलं नाही</td></tr><?php endif; ?>
        <?php foreach ($myBills as $b): ?>
          <tr>
            <td><?= h($b['billing_month']) ?></td>
            <td class="font-mono">₹<?= number_format($b['room_rent'], 0) ?></td>
            <td class="font-mono">₹<?= number_format($b['electricity_bill'], 0) ?></td>
            <td class="font-mono" style="font-weight:600;color:var(--rail);">₹<?= number_format($b['amount_due'], 0) ?> <span style="font-size:10px;color:var(--text-dim);font-weight:400;">(<?= $b['billing_unit_count'] ?> जणांत विभागलं)</span></td>
            <td>
              <?php if ($b['my_status'] === 'paid'): ?>
                <span class="status-chip done">✅ भरलं (<?= date('d M Y, h:i A', strtotime($b['paid_at'])) ?>)</span>
              <?php else: ?>
                <span class="status-chip pending">⏳ बाकी</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
