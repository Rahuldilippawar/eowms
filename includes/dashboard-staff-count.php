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
<?php $trustPct = $sc['total'] ? round($sc['trust'] * 100 / $sc['total']) : 0; $maxC = max(1, ...array_column($campuses, 'n')); $ci = 0; ?>
<div class="panel mb-3 scx" id="staffCount">
  <div class="scx-top">
    <a href="staff/index.php" class="scx-total"><span class="scx-ic">👥</span><span><small>कर्मचारी</small><b><?= number_format($sc['total']) ?></b></span></a>
    <?php if ($hasAtt): ?><span class="scx-att" title="आज हजर"><i></i><b><?= $sc['present'] ?></b> आज हजर <small><?= $pct($sc['present'], $sc['total']) ?>%</small></span><?php endif; ?>
  </div>
  <div class="scx-split" title="ट्रस्ट <?= $sc['trust'] ?> · College / School <?= $sc['college'] ?>">
    <i class="t" style="width:<?= $trustPct ?>%"></i><i class="c" style="width:<?= 100 - $trustPct ?>%"></i>
  </div>
  <div class="scx-leg"><span><i class="t"></i>🏛️ ट्रस्ट <b><?= $sc['trust'] ?></b></span><span><i class="c"></i>🎓 College / School <b><?= $sc['college'] ?></b></span></div>
  <div class="scx-list">
    <?php foreach ($campuses as $ck => $C): $ci++; ?>
      <button type="button" class="scx-row <?= $C['trust'] ? 'trust' : '' ?> <?= $ci > 4 ? 'more' : '' ?>" data-c="<?= h((string)$ck) ?>" <?= $ci > 4 ? 'hidden' : '' ?>>
        <span class="nm" title="<?= h($C['name']) ?>"><?= h($C['name']) ?></span>
        <span class="bar"><i style="width:<?= max(3, round($C['n'] * 100 / $maxC)) ?>%"></i></span>
        <b><?= $C['n'] ?></b><em>›</em>
      </button>
      <div class="scx-depts" data-c="<?= h((string)$ck) ?>" hidden>
        <?php foreach ($C['depts'] as $did => $d): ?><a href="staff/index.php<?= $did ? '?department_id=' . $did : '' ?>"><?= h($d['name']) ?> <b><?= $d['n'] ?></b></a><?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <?php if ($ci > 4): ?><button type="button" class="scx-moreb">+ <?= $ci - 4 ?> अधिक</button><?php endif; ?>
  </div>
</div>
<style>
  .scx{padding:12px 14px;}
  .scx-top{display:flex;align-items:center;justify-content:space-between;gap:10px;}
  .scx-total{display:flex;align-items:center;gap:10px;text-decoration:none;color:var(--text);}
  .scx-ic{width:38px;height:38px;border-radius:11px;background:color-mix(in srgb,var(--rail) 12%,transparent);display:grid;place-items:center;font-size:18px;}
  .scx-total small{display:block;font-size:11.5px;color:var(--text-dim);font-weight:600;line-height:1;}
  .scx-total b{font-family:'JetBrains Mono',monospace;font-size:24px;line-height:1.1;color:var(--text);}
  .scx-att{font-size:12px;color:var(--text-dim);display:inline-flex;align-items:center;gap:5px;background:var(--canvas);border-radius:99px;padding:4px 10px;}
  .scx-att i{width:7px;height:7px;border-radius:50%;background:#16A34A;} .scx-att b{color:#15803D;} .scx-att small{color:var(--text-dim);}
  .scx-split{display:flex;height:8px;border-radius:99px;overflow:hidden;background:var(--canvas);margin:12px 0 6px;}
  .scx-split i{display:block;height:100%;} .scx-split i.t,.scx-leg i.t{background:#F59E0B;} .scx-split i.c,.scx-leg i.c{background:var(--rail);}
  .scx-leg{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:12px;color:var(--text-dim);}
  .scx-leg i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:5px;} .scx-leg b{color:var(--text);font-family:'JetBrains Mono',monospace;margin-left:2px;}
  .scx-list{margin-top:10px;border-top:1px solid var(--line);}
  .scx-row{display:grid;grid-template-columns:minmax(0,1fr) 64px 34px 12px;align-items:center;gap:8px;width:100%;border:0;background:transparent;padding:9px 2px;border-bottom:1px dashed var(--line);text-align:left;color:var(--text);font-size:13px;}
  .scx-row .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600;}
  .scx-row .bar{height:5px;border-radius:99px;background:var(--canvas);overflow:hidden;} .scx-row .bar i{display:block;height:100%;background:var(--rail);border-radius:99px;}
  .scx-row.trust .bar i{background:#F59E0B;}
  .scx-row b{font-family:'JetBrains Mono',monospace;text-align:right;} .scx-row em{font-style:normal;color:var(--text-dim);transition:transform .15s;}
  .scx-row.on em{transform:rotate(90deg);} .scx-row.on{background:var(--canvas);border-radius:8px;}
  .scx-depts{display:flex;flex-wrap:wrap;gap:6px;padding:8px 2px 10px;}
  .scx-depts a{font-size:12px;background:var(--canvas);border-radius:99px;padding:3px 10px;color:var(--text);text-decoration:none;} .scx-depts a b{font-family:'JetBrains Mono',monospace;color:var(--rail);margin-left:3px;}
  .scx-moreb{border:0;background:transparent;color:var(--rail);font-size:12.5px;font-weight:700;padding:8px 2px 0;}
</style>
<script>
(function () {
  var r = document.getElementById('staffCount'); if (!r) return;
  r.querySelectorAll('.scx-row').forEach(function (b) {
    b.addEventListener('click', function () {
      var open = !b.classList.contains('on');
      r.querySelectorAll('.scx-row').forEach(function (x) { x.classList.remove('on'); });
      r.querySelectorAll('.scx-depts').forEach(function (d) { d.hidden = !(open && d.dataset.c === b.dataset.c); });
      if (open) b.classList.add('on');
    });
  });
  var mb = r.querySelector('.scx-moreb');
  if (mb) mb.addEventListener('click', function () { r.querySelectorAll('.scx-row.more').forEach(function (x) { x.hidden = false; }); mb.remove(); });
})();
</script>
