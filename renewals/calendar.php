<?php
$pageTitle = 'नूतनीकरण Calendar';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/renewal-helpers.php';

$orgId = activeOrgId();
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));
if ($month < 1) { $month = 12; $year--; }
if ($month > 12) { $month = 1; $year++; }

$monthStart = sprintf('%04d-%02d-01', $year, $month);
$monthEnd = date('Y-m-t', strtotime($monthStart));
$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');

$sql = "SELECT r.*, d.name_local AS dept_name FROM renewal_items r JOIN departments d ON d.id = r.department_id
        WHERE r.organization_id = ? AND r.status <> 'inactive' AND r.expiry_date BETWEEN ? AND ?";
$params = [$orgId, $monthStart, $monthEnd];
if ($filterDeptId) { $sql .= " AND r.department_id = ?"; $params[] = $filterDeptId; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$itemsByDay = [];
foreach ($stmt->fetchAll() as $item) {
    $day = (int)date('j', strtotime($item['expiry_date']));
    $itemsByDay[$day][] = $item;
}

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
$campusesForCascade = [];
if (!isDeptScoped()) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

$firstDayOfWeek = (int)date('w', strtotime($monthStart)); // 0=Sunday
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthNames = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
$today = date('Y-m-d');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📅 नूतनीकरण Calendar</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>नूतनीकरण यादी</a>
</div>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <div class="d-flex align-items-center gap-2">
    <a href="?month=<?= $month - 1 ?>&year=<?= $year ?><?= $filterDeptId ? '&department_id=' . h($filterDeptId) : '' ?>" class="btn" style="border:1px solid var(--line);border-radius:8px;padding:6px 12px;"><i class="fa-solid fa-chevron-left"></i></a>
    <div style="font-size:16px;font-weight:700;min-width:180px;text-align:center;"><?= $monthNames[$month] ?> <?= $year ?></div>
    <a href="?month=<?= $month + 1 ?>&year=<?= $year ?><?= $filterDeptId ? '&department_id=' . h($filterDeptId) : '' ?>" class="btn" style="border:1px solid var(--line);border-radius:8px;padding:6px 12px;"><i class="fa-solid fa-chevron-right"></i></a>
  </div>
  <?php if (!isDeptScoped()): ?>
    <form method="GET" class="d-flex gap-2">
      <input type="hidden" name="month" value="<?= $month ?>"><input type="hidden" name="year" value="<?= $year ?>">
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_renCal(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
        <option value="">-- सगळे विभाग --</option>
        <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= (string)$filterDeptId === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_renCal(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="">-- सगळे विभाग --</option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<div class="panel">
  <table style="width:100%;border-collapse:collapse;">
    <thead>
      <tr>
        <?php foreach (['रवि','सोम','मंगळ','बुध','गुरु','शुक्र','शनि'] as $wd): ?>
          <th style="padding:8px;font-size:11px;text-align:center;color:var(--text-dim);border-bottom:1px solid var(--line);"><?= $wd ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php
        $dayCounter = 1;
        $totalCells = $firstDayOfWeek + $daysInMonth;
        $totalRows = ceil($totalCells / 7);
        for ($row = 0; $row < $totalRows; $row++):
      ?>
        <tr>
          <?php for ($col = 0; $col < 7; $col++):
            $cellIndex = $row * 7 + $col;
            if ($cellIndex < $firstDayOfWeek || $dayCounter > $daysInMonth): ?>
              <td style="border:1px solid var(--line);height:90px;background:var(--canvas);"></td>
            <?php else:
              $thisDate = sprintf('%04d-%02d-%02d', $year, $month, $dayCounter);
              $isToday = $thisDate === $today;
              $dayItems = $itemsByDay[$dayCounter] ?? [];
            ?>
              <td style="border:1px solid var(--line);height:90px;vertical-align:top;padding:4px;<?= $isToday ? 'background:var(--rail-soft);' : '' ?>">
                <div style="font-size:11px;font-weight:<?= $isToday ? '700' : '500' ?>;color:<?= $isToday ? 'var(--rail)' : 'var(--text-dim)' ?>;margin-bottom:3px;"><?= $dayCounter ?></div>
                <?php foreach (array_slice($dayItems, 0, 2) as $item):
                  // स्थिती expiry_date वरून: संपलेली = लाल, 30 दिवसांत = पिवळी, नंतरची = हिरवी
                  $calStatus = renewalStatusInfo(renewalDaysLeft($item['expiry_date'], $today))['key'];
                  $calColor = $calStatus === 'expired' ? 'var(--rose)' : ($calStatus === 'due_soon' ? 'var(--amber)' : 'var(--rail)');
                ?>
                  <a href="index.php?item=<?= $item['id'] ?>" title="<?= h($item['item_name']) ?>" style="display:block;font-size:9.5px;background:<?= $calColor ?>;color:#fff;border-radius:4px;padding:2px 4px;margin-bottom:2px;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h($item['item_name']) ?></a>
                <?php endforeach; ?>
                <?php if (count($dayItems) > 2): ?><div style="font-size:9px;color:var(--text-dim);">+<?= count($dayItems) - 2 ?> अजून</div><?php endif; ?>
              </td>
            <?php $dayCounter++; endif; ?>
          <?php endfor; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
