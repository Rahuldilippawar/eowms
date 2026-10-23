<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'animal_category');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/helpers.php';
gsAutoPromoteCalves($pdo, $orgId); // वय पूर्ण झालेली वासरं → गाय/गोऱ्हा

$pageTitle = 'गौशाळा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$totalCattle = 0; $taggedCows = 0; $untaggedCows = 0; $calfCount = 0; $gorhaCount = 0; $bullCount = 0; $todayMilk = 0;
try {
    $countStmt = $pdo->prepare("SELECT animal_category, (tag_number IS NOT NULL AND tag_number <> '') AS is_tagged, COUNT(*) c FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' GROUP BY animal_category, is_tagged");
    $countStmt->execute([$orgId]);
    foreach ($countStmt->fetchAll() as $row) {
        $totalCattle += $row['c'];
        $cat = $row['animal_category'] ?? 'Cow';
        if ($cat === 'Cow') { if ($row['is_tagged']) { $taggedCows += $row['c']; } else { $untaggedCows += $row['c']; } }
        elseif ($cat === 'Calf') { $calfCount += $row['c']; }
        elseif ($cat === 'Young Bull') { $gorhaCount += $row['c']; }
        else { $bullCount += $row['c']; }
    }

    $milkStmt = $pdo->prepare("
        SELECT COALESCE(SUM(gm.quantity_liters), 0) total FROM gaushala_milk_records gm
        JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
        WHERE gc.organization_id = ? AND gm.record_date = CURDATE()
    ");
    $milkStmt->execute([$orgId]);
    $todayMilk = $milkStmt->fetch()['total'];

    $lowFodderStmt = $pdo->prepare("SELECT COUNT(*) c FROM gaushala_fodder_types WHERE organization_id = ? AND current_stock <= reorder_level");
    $lowFodderStmt->execute([$orgId]);
    $lowFodderCount = $lowFodderStmt->fetch()['c'];

    $pregnantStmt = $pdo->prepare("
        SELECT COUNT(*) c FROM gaushala_breeding_records gb JOIN gaushala_cattle gc ON gc.id = gb.cattle_id
        WHERE gc.organization_id = ? AND gb.status = 'pregnant'
    ");
    $pregnantStmt->execute([$orgId]);
    $pregnantCount = $pregnantStmt->fetch()['c'];
} catch (PDOException $e) { $lowFodderCount = 0; $pregnantCount = 0; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🐄 गौशाळा</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">गाय-नोंदी, आरोग्य, दूध-उत्पादन, चारा, प्रजनन, दान — सगळं एकत्र</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण जनावरं</div><div class="val"><?= $totalCattle ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🥛 आजचं दूध</div><div class="val" style="color:var(--rail);"><?= number_format($todayMilk, 1) ?> L</div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🍼 गाभण</div><div class="val" style="color:var(--amber);"><?= $pregnantCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🌾 चारा कमी</div><div class="val" style="color:<?= $lowFodderCount > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $lowFodderCount ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-md-4">
    <a href="cattle.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-cow" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🐮 गाय/जनावर Registry</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Batch वाली गाय <?= $taggedCows ?> · बिना Batch <?= $untaggedCows ?> · वासरं <?= $calfCount ?> · गोऱ्हे <?= $gorhaCount ?> · बैल <?= $bullCount ?></div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="milk.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-jug-detergent" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🥛 दूध उत्पादन</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">रोजची Milking-Entry</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="fodder.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-wheat-awn" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🌾 चारा व्यवस्थापन</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Stock, वापर</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="breeding.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-heart" style="font-size:22px;color:var(--rose);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🍼 प्रजनन नोंदी</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">गाभण, वासरू-अपेक्षित</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="sponsorships.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-hand-holding-heart" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">💝 दान/Sponsorship</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Donor नोंदी</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="report.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-chart-column" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">📊 Report</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Graphical Overview</div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
