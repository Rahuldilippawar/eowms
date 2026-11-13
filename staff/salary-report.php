<?php
$pageTitle = 'पगार अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

if (!isManagementOrAbove() && !hasRole('super_admin')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला पगार अहवाल पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}
require_once __DIR__ . '/../includes/salary-helpers.php';

$orgId = activeOrgId();
$q = trim((string)($_GET['q'] ?? ''));
$deptF = (int)($_GET['dept'] ?? 0);

$stmt = $pdo->prepare("
    SELECT u.*, d.name AS dept_name, d.name_local AS dept_local
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin'
    ORDER BY d.name, u.name
");
$stmt->execute([$orgId]);
$staff = $stmt->fetchAll();
$struct = salaryCurrent($pdo, $orgId);
$deptList = [];
foreach ($staff as $s) { if ($s['department_id']) $deptList[(int)$s['department_id']] = $s['dept_name']; }
asort($deptList);

$grouped = []; $T = ['gross' => 0, 'basic' => 0, 'hra' => 0, 'ta' => 0, 'medical' => 0, 'other_allow' => 0, 'pf' => 0, 'esic' => 0, 'pt' => 0, 'net' => 0];
$withSal = 0; $noStruct = 0;
foreach ($staff as $s) {
    if ($deptF && (int)$s['department_id'] !== $deptF) continue;
    if ($q !== '' && mb_stripos($s['name'] . ' ' . $s['employee_code'] . ' ' . $s['designation'], $q) === false) continue;
    $ss = $struct[(int)$s['id']] ?? null;
    if (!$ss && $s['salary'] !== null && (float)$s['salary'] > 0) { // फक्त एकूण पगार आहे — घटक नाहीत
        $ss = ['gross' => (float)$s['salary'], 'basic' => 0, 'hra' => 0, 'ta' => 0, 'medical' => 0, 'other_allow' => 0, 'pf_applicable' => $s['pf_applicable'], 'esic_applicable' => $s['esic_applicable'], 'pt_applicable' => $s['pt_applicable'] ?? 1, 'is_handicap' => $s['is_handicap'], '_only' => 1];
        $noStruct++;
    }
    $s['_s'] = $ss; $tmp = $ss; if ($tmp && !(float)$tmp['basic']) { $tmp['basic'] = $tmp['gross']; }
    $s['_d'] = $ss ? salaryDeductions($tmp, $s['gender']) : null;
    if ($ss) {
        $withSal++;
        foreach (['gross', 'basic', 'hra', 'ta', 'medical', 'other_allow'] as $k) $T[$k] += (float)$ss[$k];
        foreach (['pf', 'esic', 'pt', 'net'] as $k) $T[$k] += $s['_d'][$k];
    }
    $grouped[$s['dept_name'] ?? 'विभाग नसलेले'][] = $s;
}
$shown = array_sum(array_map('count', $grouped));
$rs = fn($v) => number_format((float)$v, 0);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0">💰 पगार अहवाल</div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="salary-import.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;"><i class="fa-solid fa-file-import me-1"></i>Excel मधून पगार आयात</a>
    <a href="list-print.php?<?= h(http_build_query(['cols' => ['name', 'employee_code', 'designation', 'basic', 'hra', 'ta', 'medical', 'salary', 'net_salary'], 'group' => 'dept', 'title' => 'पगार अहवाल', 'orient' => 'L'])) ?>" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-file-pdf me-1"></i>Print / PDF</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">🔒 फक्त Management व Super Admin ला दृश्यमान — गोपनीय माहिती. कपात (PF/ESIC/व्यवसाय कर) अंदाजे — महाराष्ट्र नियमानुसार.</p>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!salaryReady($pdo)): ?><div class="alert alert-warning" style="font-size:13px;">⚠ पगार रचनेसाठी Database Update बाकी — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा.</div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/smart-insights.php';
smartTiles([
    ['label' => 'एकूण मासिक पगार', 'value' => '₹' . $rs($T['gross']), 'sub' => $withSal . ' / ' . $shown . ' जणांचा पगार नोंदलेला'],
    ['label' => 'निव्वळ (हातात)', 'value' => '₹' . $rs($T['net']), 'sub' => 'कपातीनंतर (अंदाजे)', 'tone' => 'ok'],
    ['label' => 'PF + ESIC + PT', 'value' => '₹' . $rs($T['pf'] + $T['esic'] + $T['pt']), 'sub' => 'PF ' . $rs($T['pf']) . ' · ESIC ' . $rs($T['esic']) . ' · PT ' . $rs($T['pt'])],
    ['label' => 'पगार नाही', 'value' => $shown - $withSal, 'sub' => $noStruct ? $noStruct . ' जणांचे घटक (Basic…) नाहीत' : 'सक्रिय कर्मचारी', 'tone' => $shown - $withSal ? 'warn' : 'ok'],
]); ?>

<form method="get" class="panel mb-3 d-flex flex-wrap gap-2" style="padding:10px 12px;">
  <select name="dept" class="form-select form-select-sm" style="width:auto;flex:1 1 180px;"><option value="">सर्व विभाग</option><?php foreach ($deptList as $id => $n): ?><option value="<?= $id ?>" <?= $deptF === $id ? 'selected' : '' ?>><?= h($n) ?></option><?php endforeach; ?></select>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="नाव / Emp Id / पद" class="form-control form-control-sm" style="width:auto;flex:1 1 180px;">
  <button class="btn btn-sm btn-primary">शोधा</button>
</form>

<?php foreach ($grouped as $deptName => $deptStaff):
  $dg = array_sum(array_map(fn($s) => $s['_s']['gross'] ?? 0, $deptStaff)); $dn = array_sum(array_map(fn($s) => $s['_d']['net'] ?? 0, $deptStaff)); ?>
  <div class="panel mb-3">
    <div class="panel-head d-flex justify-content-between align-items-center">
      <div class="title"><?= h($deptName) ?> <span style="font-size:11.5px;color:var(--text-dim);font-weight:400;">(<?= count($deptStaff) ?>)</span></div>
      <div class="sub">एकूण ₹<?= $rs($dg) ?> · निव्वळ ₹<?= $rs($dn) ?></div>
    </div>
    <div class="table-responsive"><table class="rtable sr-t">
      <thead><tr><th>नाव</th><th>Basic</th><th>HRA</th><th>TA</th><th>वैद्यकीय</th><th>एकूण</th><th>PF</th><th>ESIC</th><th>PT</th><th>निव्वळ</th></tr></thead>
      <tbody>
        <?php foreach ($deptStaff as $s): $ss = $s['_s']; $d = $s['_d']; ?>
          <tr>
            <td><a href="edit.php?id=<?= (int)$s['id'] ?>#salary" style="font-weight:600;color:var(--text);text-decoration:none;"><?= h($s['name']) ?></a><div style="font-size:11px;color:var(--text-dim);"><?= h(trim(($s['employee_code'] ? '#' . $s['employee_code'] . ' · ' : '') . ($s['designation'] ?? ''))) ?></div></td>
            <?php if (!$ss): ?><td colspan="9" style="color:var(--text-dim);font-size:12px;">पगार नोंदलेला नाही</td>
            <?php else: ?>
              <?php foreach (['basic', 'hra', 'ta', 'medical'] as $k): ?><td class="font-mono"><?= !empty($ss['_only']) ? '—' : $rs($ss[$k]) ?></td><?php endforeach; ?>
              <td class="font-mono" style="font-weight:700;"><?= $rs($ss['gross']) ?></td>
              <td class="font-mono dim"><?= $d['pf'] ? $rs($d['pf']) : '—' ?></td><td class="font-mono dim"><?= $d['esic'] ? $rs($d['esic']) : '—' ?></td><td class="font-mono dim"><?= $d['pt'] ? $rs($d['pt']) : '—' ?></td>
              <td class="font-mono" style="font-weight:700;color:var(--rail);"><?= $rs($d['net']) ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endforeach; ?>
<?php if (!$grouped): ?><div class="panel sm-empty">कोणीही सापडले नाही.</div><?php endif; ?>
<style>.sr-t td.dim{color:var(--text-dim);} .sr-t td,.sr-t th{white-space:nowrap;}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
