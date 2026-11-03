<?php
$pageTitle = 'कार्यप्रवाह टेम्प्लेट';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT wt.*, lc.name_local AS category_name,
      (SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_template_id = wt.id) AS step_count
    FROM workflow_templates wt
    LEFT JOIN letter_categories lc ON lc.id = wt.category_id
    WHERE wt.organization_id = ?
    ORDER BY wt.name
");
$stmt->execute([$orgId]);
$templates = $stmt->fetchAll();
require_once __DIR__ . '/../includes/smart-insights.php';

// पायऱ्या + प्रत्येक पायरीचा "पत्र कोणाकडे जाईल" तपासणी (HOD नाही / व्यक्ती निष्क्रिय)
$stepsByTpl = [];
$st = $pdo->prepare("SELECT ws.*, d.name_local AS dept_name, d.is_active AS dept_active, d.hod_id, hu.is_active AS hod_active, u.name AS user_name, u.is_active AS user_active
    FROM workflow_steps ws
    JOIN workflow_templates wt ON wt.id = ws.workflow_template_id AND wt.organization_id = ?
    LEFT JOIN departments d ON d.id = ws.department_id
    LEFT JOIN users hu ON hu.id = d.hod_id
    LEFT JOIN users u ON u.id = ws.user_id
    ORDER BY ws.workflow_template_id, ws.step_order");
$st->execute([$orgId]);
foreach ($st->fetchAll() as $s) {
    $problem = null;
    if ($s['approver_type'] === 'department_hod') {
        if (!$s['department_id']) $problem = 'विभाग निवडलेला नाही';
        elseif (!$s['dept_active']) $problem = 'विभाग निष्क्रिय';
        elseif (!$s['hod_id']) $problem = 'विभागाला HOD नाही';
        elseif (!$s['hod_active']) $problem = 'HOD निष्क्रिय';
    } elseif ($s['approver_type'] === 'specific_user') {
        if (!$s['user_id']) $problem = 'व्यक्ती निवडलेली नाही';
        elseif (!$s['user_active']) $problem = 'व्यक्ती निष्क्रिय';
    }
    $s['problem'] = $problem;
    $stepsByTpl[(int)$s['workflow_template_id']][] = $s;
}
// वापर — किती पत्रं, किती सध्या प्रगतीत
$usage = [];
try {
    $st = $pdo->prepare("SELECT workflow_template_id t, COUNT(*) c, SUM(status = 'in_progress') ip FROM letters WHERE organization_id = ? AND workflow_template_id IS NOT NULL GROUP BY workflow_template_id");
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) { $usage[(int)$r['t']] = $r; }
} catch (PDOException $e) {}

$alerts = [];
foreach ($templates as $t) {
    if (!$t['is_active']) continue;
    $bad = array_filter($stepsByTpl[(int)$t['id']] ?? [], fn($s) => $s['problem']);
    if (!(int)$t['step_count']) $alerts[] = ['bad', "'{$t['name']}' मध्ये एकही पायरी नाही — पत्र पुढे जाणारच नाही.", 'workflow-edit.php?id=' . $t['id']];
    foreach ($bad as $b) $alerts[] = ['bad', "'{$t['name']}' — पायरी {$b['step_order']} ({$b['step_name']}): {$b['problem']}. " . ((int)($usage[(int)$t['id']]['ip'] ?? 0) ? ((int)$usage[(int)$t['id']]['ip']) . ' पत्रं अडकू शकतात.' : ''), 'workflow-edit.php?id=' . $t['id']];
}
$activeTpl = array_filter($templates, fn($t) => $t['is_active']);
$hasGeneric = (bool)array_filter($activeTpl, fn($t) => !$t['category_id']);
if ($templates && !$hasGeneric) $alerts[] = ['info', '"सर्व प्रकार" साठी सामान्य कार्यप्रवाह नाही — ज्या पत्र प्रकाराला स्वतःचा कार्यप्रवाह नाही ती पत्रं पुढे पाठवता येणार नाहीत.', 'workflow-create.php'];
$typeLbl = ['department_hod' => 'HOD', 'letter_dept_hod' => 'पत्राच्या विभागाचा HOD', 'specific_user' => 'व्यक्ती'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">कार्यप्रवाह टेम्प्लेट (Workflow Templates)</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">पत्र कोणत्या क्रमाने कोणाकडे जाईल ते इथे ठरवा — उदा. HOD → General Manager → President</div>
  </div>
  <a href="workflow-create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन टेम्प्लेट</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

 <?php
smartTiles([
    ['label' => 'कार्यप्रवाह', 'value' => count($templates), 'sub' => count($activeTpl) . ' सक्रिय'],
    ['label' => 'वापरलेली पत्रं', 'value' => array_sum(array_map(fn($u) => (int)$u['c'], $usage)), 'sub' => array_sum(array_map(fn($u) => (int)$u['ip'], $usage)) . ' सध्या प्रगतीत'],
    ['label' => 'तुटलेल्या पायऱ्या', 'value' => count(array_filter($alerts, fn($a) => $a[0] === 'bad')), 'sub' => 'HOD/व्यक्ती नाही किंवा निष्क्रिय', 'tone' => array_filter($alerts, fn($a) => $a[0] === 'bad') ? 'bad' : 'ok'],
    ['label' => 'सामान्य कार्यप्रवाह', 'value' => $hasGeneric ? 'आहे' : 'नाही', 'sub' => '"सर्व प्रकार" साठी', 'tone' => $hasGeneric ? 'ok' : 'warn'],
]);
smartAlerts(array_slice($alerts, 0, 8));
?>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>पत्र प्रकार</th><th>पायऱ्यांचा क्रम</th><th>पत्रं</th><th>स्थिती</th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (empty($templates)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">कोणताही टेम्प्लेट अजून तयार केलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td style="font-weight:500;"><?= h($t['name']) ?></td>
          <td><?= $t['category_id'] ? h($t['category_name'] ?? '—') : '<span style="color:var(--rail);font-weight:600;">सर्व प्रकार</span>' ?></td>
          <td>
            <div class="wf-chain">
              <?php foreach ($stepsByTpl[(int)$t['id']] ?? [] as $i => $sx): ?>
                <?= $i ? '<i class="fa-solid fa-arrow-right wf-arr"></i>' : '' ?>
                <span class="wf-step <?= $sx['problem'] ? 'bad' : '' ?>" title="<?= h(($typeLbl[$sx['approver_type']] ?? $sx['approver_type']) . ($sx['dept_name'] ? ' · ' . $sx['dept_name'] : '') . ($sx['user_name'] ? ' · ' . $sx['user_name'] : '') . ($sx['problem'] ? ' — ⚠ ' . $sx['problem'] : '')) ?>"><?= $sx['problem'] ? '⚠ ' : '' ?><?= h($sx['step_name'] ?: ($typeLbl[$sx['approver_type']] ?? '—')) ?></span>
              <?php endforeach; ?>
              <?php if (empty($stepsByTpl[(int)$t['id']])): ?><span style="color:var(--rose);font-size:12px;font-weight:600;">⚠ पायरी नाही</span><?php endif; ?>
            </div>
          </td>
          <?php $u = $usage[(int)$t['id']] ?? ['c' => 0, 'ip' => 0]; ?>
          <td class="font-mono" data-sort="<?= (int)$u['c'] ?>"><?= (int)$u['c'] ?><?= (int)$u['ip'] ? ' <span style="font-size:11px;color:var(--amber);">(' . (int)$u['ip'] . ' प्रगतीत)</span>' : '' ?></td>
          <td><?php if ($t['is_active']): ?><span class="status-chip done">सक्रिय</span><?php else: ?><span class="status-chip overdue">निष्क्रिय</span><?php endif; ?></td>
          <td><a href="workflow-edit.php?id=<?= $t['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<style>
  .wf-chain { display: flex; flex-wrap: wrap; align-items: center; gap: 4px; }
  .wf-step { font-size: 11.5px; padding: 3px 9px; border-radius: 999px; background: rgba(27,138,131,.1); color: var(--rail); font-weight: 600; white-space: nowrap; }
  .wf-step.bad { background: rgba(194,51,63,.1); color: var(--rose); }
  .wf-arr { font-size: 9px; color: var(--text-dim); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
