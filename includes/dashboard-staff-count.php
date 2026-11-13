<?php
// 👥 Dashboard — कर्मचारी संख्या: ट्रस्ट / College-School / Campus / विभागनिहाय
// (index.php मधून; $pdo, $orgId, $scopeDepts — Management = null = संपूर्ण संस्था, Campus प्रमुख = त्याचे विभाग)
// ट्रस्ट = "Trust HQ" प्रकारचा Campus किंवा Campus नसलेला (संस्था-स्तरीय) विभाग; बाकी = College / School.
if (!isset($pdo, $orgId)) return;
$scSql = ''; $scP = [];
if ($scopeDepts !== null) {
    if (!$scopeDepts) return;
    $scSql = ' AND u.department_id IN (' . implode(',', array_fill(0, count($scopeDepts), '?')) . ')';
    $scP = array_map('intval', $scopeDepts);
}
try {
    $st = $pdo->prepare("SELECT u.id, u.gender, u.teaching_mode, u.campus_id, u.department_id, u.created_at,
            c.name AS campus, c.college_type, d.name AS dept, d.name_local, d.campus_id AS dept_campus, h.name AS hod
        FROM users u
        LEFT JOIN campuses c ON c.id = u.campus_id
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN users h ON h.id = d.hod_id
        WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin'$scSql");
    $st->execute(array_merge([$orgId], $scP));
    $people = $st->fetchAll();
} catch (PDOException $e) { return; }
if (!$people) return;

// आज हजर (हजेरी Table असेल तर)
$presentIds = [];
try {
    $st = $pdo->prepare("SELECT user_id FROM staff_attendance WHERE organization_id = ? AND attendance_date = CURDATE() AND status IN ('present','half_day')");
    $st->execute([$orgId]);
    $presentIds = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
} catch (PDOException $e) {}
$hasAtt = (bool)$presentIds;

$sc = ['total' => 0, 'trust' => 0, 'college' => 0, 'male' => 0, 'female' => 0, 'teach' => 0, 'present' => 0, 'new' => 0];
$campuses = []; $depts = [];
$monthStart = date('Y-m-01');
foreach ($people as $p) {
    $isTrust = ($p['college_type'] ?? '') === 'Trust HQ' || !$p['campus_id'] || ($p['department_id'] && $p['dept_campus'] === null);
    $here = isset($presentIds[(int)$p['id']]);
    $sc['total']++; $sc[$isTrust ? 'trust' : 'college']++;
    if ($p['gender'] === 'Male') $sc['male']++; elseif ($p['gender'] === 'Female') $sc['female']++;
    if ($p['teaching_mode'] === 'Teaching') $sc['teach']++;
    if ($here) $sc['present']++;
    if (substr((string)$p['created_at'], 0, 10) >= $monthStart) $sc['new']++;
    // Campus
    $ck = $isTrust && !$p['campus_id'] ? 'trust' : (int)$p['campus_id'];
    if (!isset($campuses[$ck])) $campuses[$ck] = ['name' => $p['campus'] ?: 'ट्रस्ट (संस्था-स्तरीय)', 'trust' => $isTrust, 'n' => 0, 'm' => 0, 'f' => 0, 'present' => 0, 'depts' => []];
    $C = &$campuses[$ck];
    $C['n']++; if ($p['gender'] === 'Male') $C['m']++; elseif ($p['gender'] === 'Female') $C['f']++; if ($here) $C['present']++;
    $dk = (int)$p['department_id'];
    $dname = $p['dept'] ? $p['dept'] : '— विभाग नाही —';
    $C['depts'][$dk] = ($C['depts'][$dk] ?? ['name' => $dname, 'n' => 0, 'present' => 0]);
    $C['depts'][$dk]['n']++; if ($here) $C['depts'][$dk]['present']++;
    unset($C);
    // विभाग (संपूर्ण संस्थेत)
    if (!isset($depts[$dk])) $depts[$dk] = ['id' => $dk, 'name' => $dname, 'local' => $p['name_local'], 'campus' => $p['campus'] ?: 'ट्रस्ट', 'trust' => $isTrust, 'hod' => $p['hod'], 'n' => 0, 'm' => 0, 'f' => 0, 'present' => 0];
    $depts[$dk]['n']++; if ($p['gender'] === 'Male') $depts[$dk]['m']++; elseif ($p['gender'] === 'Female') $depts[$dk]['f']++; if ($here) $depts[$dk]['present']++;
}
uasort($campuses, fn($a, $b) => [$b['trust'], $b['n']] <=> [$a['trust'], $a['n']]); // ट्रस्ट आधी, मग मोठे
foreach ($campuses as &$C) { uasort($C['depts'], fn($a, $b) => $b['n'] <=> $a['n']); } unset($C);
uasort($depts, fn($a, $b) => $b['n'] <=> $a['n']);
$maxDept = max(1, ...array_column($depts, 'n'));
$pct = fn($a, $b) => $b ? round($a * 100 / $b) : 0;
?>
<div class="panel mb-3 sc-mini" id="staffCount">
  <div class="sc-top">
    <a href="staff/index.php" class="sc-total" title="कर्मचारी यादी">👥 कर्मचारी <b><?= number_format($sc['total']) ?></b></a>
    <span class="sc-pill trust">🏛️ ट्रस्ट <b><?= $sc['trust'] ?></b></span>
    <span class="sc-pill">🎓 College / School <b><?= $sc['college'] ?></b></span>
    <?php if ($hasAtt): ?><span class="sc-pill ok">✅ आज हजर <b><?= $sc['present'] ?></b></span><?php endif; ?>
  </div>
  <div class="sc-camps">
    <?php foreach ($campuses as $ck => $C): ?>
      <button type="button" class="sc-camp <?= $C['trust'] ? 'trust' : '' ?>" data-c="<?= h((string)$ck) ?>"><?= h($C['name']) ?> <b><?= $C['n'] ?></b> <i>▾</i></button>
    <?php endforeach; ?>
  </div>
  <?php foreach ($campuses as $ck => $C): ?>
    <div class="sc-depts" data-c="<?= h((string)$ck) ?>" hidden>
      <?php foreach ($C['depts'] as $did => $d): ?><a href="staff/index.php<?= $did ? '?department_id=' . $did : '' ?>"><?= h($d['name']) ?> <b><?= $d['n'] ?></b></a><?php endforeach; ?>
    </div>
  <?php endforeach; ?>
</div>
<style>
  .sc-mini{padding:10px 14px;}
  .sc-top{display:flex;flex-wrap:wrap;align-items:center;gap:6px 10px;}
  .sc-total{font-size:13px;font-weight:700;color:var(--text);text-decoration:none;margin-right:4px;}
  .sc-total b{font-family:'JetBrains Mono',monospace;font-size:17px;color:var(--rail);margin-left:3px;}
  .sc-pill{font-size:11.5px;color:var(--text-dim);background:var(--canvas);border-radius:20px;padding:3px 10px;font-weight:600;}
  .sc-pill b{font-family:'JetBrains Mono',monospace;color:var(--rail);margin-left:2px;} .sc-pill.trust b{color:#B45309;} .sc-pill.ok b{color:var(--green,#15803D);}
  .sc-camps{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
  .sc-camp{border:1px solid var(--line);background:var(--surface);border-radius:8px;padding:3px 9px;font-size:11.5px;color:var(--text);font-weight:600;border-left:3px solid var(--rail);}
  .sc-camp.trust{border-left-color:#F59E0B;} .sc-camp b{font-family:'JetBrains Mono',monospace;margin-left:3px;} .sc-camp i{font-style:normal;color:var(--text-dim);font-size:10px;}
  .sc-camp.on{background:var(--canvas);} .sc-camp.on i{display:inline-block;transform:rotate(180deg);}
  .sc-depts{display:flex;flex-wrap:wrap;gap:4px 12px;margin-top:8px;padding-top:8px;border-top:1px dashed var(--line);}
  .sc-depts a{font-size:11.5px;color:var(--text-dim);text-decoration:none;} .sc-depts a b{color:var(--text);font-family:'JetBrains Mono',monospace;} .sc-depts a:hover{color:var(--rail);}
</style>
<script>
(function () {
  var r = document.getElementById('staffCount'); if (!r) return;
  r.querySelectorAll('.sc-camp').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = !b.classList.contains('on');
      r.querySelectorAll('.sc-camp').forEach(function (x) { x.classList.remove('on'); });
      r.querySelectorAll('.sc-depts').forEach(function (d) { d.hidden = !(open && d.dataset.c === b.dataset.c); });
      if (open) b.classList.add('on');
    });
  });
})();
</script>
