<?php
$pageTitle = 'विभाग व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_departments', '../');
require_once __DIR__ . '/../includes/smart-insights.php';

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name, u.name AS hod_name, u.is_active AS hod_active, u.department_id AS hod_dept,
      (SELECT COUNT(*) FROM users s WHERE s.department_id = d.id) AS staff_count,
      (SELECT COUNT(*) FROM users s WHERE s.department_id = d.id AND s.is_active = 1) AS staff_active
    FROM departments d
    LEFT JOIN campuses c ON c.id = d.campus_id
    LEFT JOIN users u ON u.id = d.hod_id
    WHERE d.organization_id = ? ORDER BY d.name_local
");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

// विभागाचं "आरोग्य" — गेल्या ३० दिवसांची कामं, उघडे/मुदतबाह्य Tasks, प्रलंबित विनंत्या
$grab = function ($sql) use ($pdo, $orgId) {
    $out = [];
    try { $st = $pdo->prepare($sql); $st->execute([$orgId]); foreach ($st->fetchAll() as $r) { $out[(int)$r['d']] = $r; } } catch (PDOException $e) {}
    return $out;
};
$work30 = $grab("SELECT department_id d, COUNT(*) c FROM work_records WHERE organization_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) GROUP BY department_id");
$taskStat = $grab("SELECT department_id d, SUM(status IN ('pending','in_progress')) open, SUM(status IN ('pending','in_progress') AND target_date < CURDATE()) overdue FROM tasks WHERE organization_id = ? GROUP BY department_id");
$reqStat = $grab("SELECT department_id d, SUM(status IN ('pending','in_progress')) open FROM work_requests WHERE organization_id = ? GROUP BY department_id");

$st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE organization_id = ? AND department_id IS NULL AND is_active = 1 AND role <> 'super_admin'");
$st->execute([$orgId]);
$noDeptStaff = (int)$st->fetchColumn();

$active = array_filter($departments, fn($d) => $d['is_active']);
$noHod = array_filter($active, fn($d) => !$d['hod_id']);
$badHod = array_filter($active, fn($d) => $d['hod_id'] && (!$d['hod_active'] || (int)$d['hod_dept'] !== (int)$d['id']));
$empty = array_filter($active, fn($d) => !(int)$d['staff_active']);
$idle = array_filter($active, fn($d) => (int)$d['staff_active'] && empty($work30[(int)$d['id']]['c']));
$overdueTot = array_sum(array_map(fn($t) => (int)$t['overdue'], $taskStat));
$campusList = array_values(array_unique(array_map(fn($d) => $d['campus_name'] ?: '🏛️ Trust (केंद्रीय)', $departments)));

$alerts = [];
foreach (array_slice($badHod, 0, 4) as $d) {
    $alerts[] = ['bad', "'" . ($d['name_local'] ?: $d['name']) . "' चे HOD (" . $d['hod_name'] . ') ' . (!$d['hod_active'] ? 'निष्क्रिय आहेत' : 'दुसऱ्या विभागात आहेत') . ' — नवीन HOD नेमा.', 'edit.php?id=' . $d['id']];
}
if ($noHod) $alerts[] = ['warn', count($noHod) . ' सक्रिय विभागांना HOD नेमलेले नाहीत — रजा मंजुरी, अहवाल व Tasks अडतात.', '#deptTable'];
if ($noDeptStaff) $alerts[] = ['warn', $noDeptStaff . ' सक्रिय कर्मचाऱ्यांना विभागच नाही — त्यांना विभागाचे काम/अहवाल दिसत नाहीत.', '../staff/index.php'];
if ($empty) $alerts[] = ['info', count($empty) . ' सक्रिय विभागांत एकही सक्रिय कर्मचारी नाही.', '#deptTable'];
if ($idle) $alerts[] = ['info', count($idle) . ' विभागांत गेल्या ३० दिवसांत एकही कामाची नोंद नाही.', '#deptTable'];
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= h(t('s323')) ?></div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="bulk-reassign.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-arrows-turn-right me-1"></i>🔀 मोठ्या प्रमाणात हलवा
    </a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन विभाग
    </a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success mt-2" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger mt-2" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>

<?php
smartTiles([
    ['label' => 'एकूण विभाग', 'value' => count($departments), 'sub' => count($active) . ' सक्रिय · ' . count($campusList) . ' कॅम्पस/Trust'],
    ['label' => 'सक्रिय कर्मचारी', 'value' => array_sum(array_column($active, 'staff_active')), 'sub' => $noDeptStaff ? $noDeptStaff . ' विभागाविना' : 'सर्वांना विभाग आहे', 'tone' => $noDeptStaff ? 'warn' : 'ok'],
    ['label' => 'HOD नसलेले', 'value' => count($noHod) + count($badHod), 'sub' => count($badHod) ? count($badHod) . ' चुकीचे HOD' : 'सक्रिय विभागांपैकी', 'tone' => count($badHod) ? 'bad' : (count($noHod) ? 'warn' : 'ok')],
    ['label' => 'मुदतबाह्य Tasks', 'value' => $overdueTot, 'sub' => 'सर्व विभाग मिळून', 'tone' => $overdueTot ? 'bad' : 'ok'],
    ['label' => 'शांत विभाग', 'value' => count($idle), 'sub' => '३० दिवसांत काम नाही', 'tone' => $idle ? 'warn' : 'ok'],
]);
smartAlerts($alerts);
?>

<div class="panel mt-3" id="deptTable">
  <div class="dp-filters">
    <span class="dp-flabel">कॅम्पस:</span>
    <button type="button" class="dp-chip on" data-campus="">सर्व</button>
    <?php foreach ($campusList as $c): ?><button type="button" class="dp-chip" data-campus="<?= h($c) ?>"><?= h($c) ?></button><?php endforeach; ?>
    <span class="dp-flabel ms-2">स्थिती:</span>
    <?php foreach (['' => 'सर्व', 'nohod' => 'HOD नाही', 'empty' => 'रिकामे', 'idle' => 'शांत', 'overdue' => 'मुदतबाह्य', 'inactive' => 'निष्क्रिय'] as $k => $l): ?>
      <button type="button" class="dp-chip <?= $k === '' ? 'on' : '' ?>" data-flag="<?= $k ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s1')) ?></th><th><?= h(t('s8')) ?></th><th><?= h(t('s27')) ?></th><th><?= h(t('s6')) ?></th><th title="गेल्या ३० दिवसांतील कामाच्या नोंदी">कामं (३० दि.)</th><th>Tasks</th><th>विनंत्या</th><th><?= h(t('s2')) ?></th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (empty($departments)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s324')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($departments as $dept):
        $id = (int)$dept['id']; $w = (int)($work30[$id]['c'] ?? 0); $to = (int)($taskStat[$id]['open'] ?? 0); $tod = (int)($taskStat[$id]['overdue'] ?? 0); $ro = (int)($reqStat[$id]['open'] ?? 0);
        $hodBad = $dept['hod_id'] && (!$dept['hod_active'] || (int)$dept['hod_dept'] !== $id);
        $flags = [];
        if (!$dept['is_active']) $flags[] = 'inactive';
        else {
            if (!$dept['hod_id'] || $hodBad) $flags[] = 'nohod';
            if (!(int)$dept['staff_active']) $flags[] = 'empty';
            elseif (!$w) $flags[] = 'idle';
            if ($tod) $flags[] = 'overdue';
        } ?>
        <tr data-campus="<?= h($dept['campus_name'] ?: '🏛️ Trust (केंद्रीय)') ?>" data-flags="<?= implode(' ', $flags) ?>" style="<?= $dept['is_active'] ? '' : 'opacity:.6;' ?>">
          <td style="font-weight:500;"><i class="<?= h($dept['icon']) ?> me-2"></i><a href="dashboard.php?id=<?= $id ?>" style="color:inherit;text-decoration:none;"><?= h($dept['name_local'] ?: $dept['name']) ?></a> <span style="color:var(--text-dim);font-size:12px;">(<?= h($dept['name']) ?>)</span><?= $dept['code'] ? '<div class="font-mono" style="font-size:11px;color:var(--text-dim);">' . h($dept['code']) . '</div>' : '' ?></td>
          <td><?= $dept['campus_name'] ? h($dept['campus_name']) : '<span style="color:var(--rail);font-weight:600;">🏛️ Trust (केंद्रीय)</span>' ?></td>
          <td>
            <?php if (!$dept['hod_id']): ?><span style="color:var(--amber);font-weight:600;font-size:12.5px;">⚠ नेमलेले नाहीत</span>
            <?php else: ?><?= h($dept['hod_name'] ?? '—') ?><?= $hodBad ? '<div style="color:var(--rose);font-size:11px;font-weight:600;">⚠ ' . (!$dept['hod_active'] ? 'निष्क्रिय' : 'दुसऱ्या विभागात') . '</div>' : '' ?><?php endif; ?>
          </td>
          <td class="font-mono" data-sort="<?= (int)$dept['staff_active'] ?>"><b><?= (int)$dept['staff_active'] ?></b><?= $dept['staff_count'] > $dept['staff_active'] ? ' <span style="color:var(--text-dim);font-size:11px;">+' . ($dept['staff_count'] - $dept['staff_active']) . '</span>' : '' ?></td>
          <td class="font-mono" data-sort="<?= $w ?>" style="<?= $dept['is_active'] && (int)$dept['staff_active'] && !$w ? 'color:var(--amber);' : '' ?>"><?= $w ?></td>
          <td class="font-mono" data-sort="<?= $tod * 1000 + $to ?>"><?= $to ?><?= $tod ? ' <span style="color:var(--rose);font-weight:700;font-size:11.5px;">(' . $tod . ' मुदतबाह्य)</span>' : '' ?></td>
          <td class="font-mono"><?= $ro ?: '<span style="color:var(--text-dim);">0</span>' ?></td>
          <td><?php if ($dept['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td style="white-space:nowrap;text-align:right;">
            <a href="dashboard.php?id=<?= $id ?>" class="btn btn-sm" title="विभाग Dashboard" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 10px;"><i class="fa-solid fa-gauge-high"></i></a>
            <a href="edit.php?id=<?= $id ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<style>
  .dp-filters { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 12px 16px 4px; }
  .dp-flabel { font-size: 11.5px; color: var(--text-dim); font-weight: 600; }
  .dp-chip { border: 1px solid var(--line); background: transparent; border-radius: 999px; padding: 4px 12px; font-size: 12px; color: var(--text-dim); cursor: pointer; }
  .dp-chip.on { background: var(--rail); border-color: var(--rail); color: #fff; }
</style>
<script>
(function () {
  var campus = '', flag = '';
  function apply() {
    document.querySelectorAll('#deptTable tbody tr[data-flags]').forEach(function (tr) {
      var okC = !campus || tr.getAttribute('data-campus') === campus;
      var okF = !flag || (' ' + tr.getAttribute('data-flags') + ' ').indexOf(' ' + flag + ' ') !== -1;
      tr.style.display = okC && okF ? '' : 'none';
    });
  }
  document.querySelectorAll('.dp-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      var isCampus = c.hasAttribute('data-campus');
      document.querySelectorAll('.dp-chip[' + (isCampus ? 'data-campus' : 'data-flag') + ']').forEach(function (x) { x.classList.toggle('on', x === c); });
      if (isCampus) campus = c.getAttribute('data-campus'); else flag = c.getAttribute('data-flag');
      apply();
    });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
