<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_milk_records', '../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'animal_category');
require_once __DIR__ . '/helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_gaushala') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_milk') {
        $cattleId = (int)($_POST['cattle_id'] ?? 0);
        $recordDate = ($_POST['record_date'] ?? '') ?: date('Y-m-d');
        $session = in_array($_POST['session'] ?? '', ['Morning', 'Evening'], true) ? $_POST['session'] : 'Morning';
        $quantity = (float)($_POST['quantity_liters'] ?? 0);
        if ($cattleId && $quantity > 0) {
            try {
                $pdo->prepare("INSERT INTO gaushala_milk_records (cattle_id, record_date, session, quantity_liters, recorded_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$cattleId, $recordDate, $session, $quantity, $user['id']]);
                $_SESSION['flash'] = 'नोंद जतन झाली.';
            } catch (PDOException $e) {
                $_SESSION['flash'] = '⚠️ या गायीची, या दिवशी, या Session ची नोंद आधीच झालेली आहे.';
            }
        }
    } elseif ($action === 'update_milk') {
        $recordId = (int)($_POST['record_id'] ?? 0);
        $quantity = (float)($_POST['quantity_liters'] ?? 0);
        if ($quantity > 0) {
            $pdo->prepare("
                UPDATE gaushala_milk_records gm JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
                SET gm.quantity_liters = ? WHERE gm.id = ? AND gc.organization_id = ?
            ")->execute([$quantity, $recordId, $orgId]);
            $_SESSION['flash'] = 'नोंद Update झाली.';
        }
    }
    header('Location: milk.php?date=' . urlencode($_GET['date'] ?? date('Y-m-d')));
    exit;
}

$pageTitle = 'दूध उत्पादन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$selectedDate = $_GET['date'] ?? date('Y-m-d');

$cattleStmt = $pdo->prepare("SELECT id, tag_number, name FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' AND gender = 'Female' AND animal_category = 'Cow' ORDER BY (tag_number IS NULL), tag_number, id");
$cattleStmt->execute([$orgId]);
$femaleCattle = $cattleStmt->fetchAll();

$recordsStmt = $pdo->prepare("
    SELECT gm.* FROM gaushala_milk_records gm JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
    WHERE gc.organization_id = ? AND gm.record_date = ?
");
$recordsStmt->execute([$orgId, $selectedDate]);
$existingRecords = [];
foreach ($recordsStmt->fetchAll() as $r) { $existingRecords[$r['cattle_id'] . '_' . $r['session']] = $r; }

$dayTotal = array_sum(array_column($existingRecords, 'quantity_liters'));

// गेल्या 7 दिवसांचा Trend
$trendStmt = $pdo->prepare("
    SELECT gm.record_date, SUM(gm.quantity_liters) total FROM gaushala_milk_records gm
    JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
    WHERE gc.organization_id = ? AND gm.record_date >= DATE_SUB(?, INTERVAL 6 DAY) AND gm.record_date <= ?
    GROUP BY gm.record_date ORDER BY gm.record_date
");
$trendStmt->execute([$orgId, $selectedDate, $selectedDate]);
$trendRaw = $trendStmt->fetchAll();
$trendMap = [];
foreach ($trendRaw as $t) { $trendMap[$t['record_date']] = (float)$t['total']; }
$trendLabels = []; $trendValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime($selectedDate . " -$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $trendValues[] = $trendMap[$d] ?? 0;
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🥛 दूध उत्पादन</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <form method="GET"><input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($selectedDate) ?>" onchange="this.form.submit()"></form>
  <div class="kpi-mini" style="min-width:150px;"><div class="lbl">या दिवशीचं एकूण</div><div class="val" style="color:var(--rail);"><?= number_format($dayTotal, 1) ?> L</div></div>
</div>

<div class="panel mb-3">
  <div style="padding:16px 20px;"><canvas id="trendChart" height="80"></canvas></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>गाय</th><th>🌅 सकाळी (L)</th><th>🌆 संध्याकाळी (L)</th><th>एकूण</th></tr></thead>
    <tbody>
      <?php if (empty($femaleCattle)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही गाय Registry मध्ये नाही</td></tr><?php endif; ?>
      <?php foreach ($femaleCattle as $c):
        $morning = $existingRecords[$c['id'] . '_Morning'] ?? null;
        $evening = $existingRecords[$c['id'] . '_Evening'] ?? null;
        $total = ($morning['quantity_liters'] ?? 0) + ($evening['quantity_liters'] ?? 0);
      ?>
        <tr>
          <td style="font-weight:600;"><?= h(gsCattleTitle($c['tag_number'], $c['name'], $c['id'])) ?></td>
          <td>
            <?php if ($canManage): ?>
              <?php if ($morning): ?>
                <span class="font-mono"><?= number_format($morning['quantity_liters'], 1) ?></span>
                <button type="button" style="border:none;background:none;color:var(--amber);font-size:10.5px;" onclick="editMilk(<?= $morning['id'] ?>, <?= $morning['quantity_liters'] ?>)">✏️</button>
              <?php else: ?>
                <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="add_milk"><input type="hidden" name="cattle_id" value="<?= $c['id'] ?>"><input type="hidden" name="record_date" value="<?= h($selectedDate) ?>"><input type="hidden" name="session" value="Morning">
                  <input type="number" step="0.1" name="quantity_liters" class="form-control-custom" style="width:70px;padding:4px 8px;font-size:12px;" placeholder="0.0" required>
                  <button type="submit" style="border:none;background:none;color:var(--rail);font-size:11px;">✓</button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <?= $morning ? number_format($morning['quantity_liters'], 1) : '—' ?>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($canManage): ?>
              <?php if ($evening): ?>
                <span class="font-mono"><?= number_format($evening['quantity_liters'], 1) ?></span>
                <button type="button" style="border:none;background:none;color:var(--amber);font-size:10.5px;" onclick="editMilk(<?= $evening['id'] ?>, <?= $evening['quantity_liters'] ?>)">✏️</button>
              <?php else: ?>
                <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="add_milk"><input type="hidden" name="cattle_id" value="<?= $c['id'] ?>"><input type="hidden" name="record_date" value="<?= h($selectedDate) ?>"><input type="hidden" name="session" value="Evening">
                  <input type="number" step="0.1" name="quantity_liters" class="form-control-custom" style="width:70px;padding:4px 8px;font-size:12px;" placeholder="0.0" required>
                  <button type="submit" style="border:none;background:none;color:var(--rail);font-size:11px;">✓</button>
                </form>
              <?php endif; ?>
            <?php else: ?>
              <?= $evening ? number_format($evening['quantity_liters'], 1) : '—' ?>
            <?php endif; ?>
          </td>
          <td class="font-mono" style="font-weight:700;color:var(--rail);"><?= number_format($total, 1) ?> L</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="editMilkModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="update_milk">
      <input type="hidden" name="record_id" id="editRecordId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">प्रमाण Edit करा</div>
        <input type="number" step="0.1" name="quantity_liters" id="editQuantity" class="form-control-custom" required>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>
<script>
function editMilk(id, qty) {
  document.getElementById('editRecordId').value = id;
  document.getElementById('editQuantity').value = qty;
  new bootstrap.Modal(document.getElementById('editMilkModal')).show();
}
</script>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels) ?>,
      datasets: [{ label: 'लिटर', data: <?= json_encode($trendValues) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.1)', fill: true, tension: 0.35, pointRadius: 3 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
