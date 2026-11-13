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
<div class="panel mb-3 sc-wrap" id="staffCount">
  <div class="panel-head d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div><div class="title">👥 कर्मचारी संख्या</div><div class="sub">ट्रस्ट · College / School · विभागनिहाय — सक्रिय कर्मचारी<?= $scopeDepts !== null ? ' (तुमच्या व्याप्तीतले)' : '' ?></div></div>
    <a href="staff/index.php" style="font-size:12px;color:var(--rail);font-weight:600;text-decoration:none;">कर्मचारी यादी →</a>
  </div>

  <div class="sc-kpis">
    <div class="sc-kpi big"><span>एकूण कर्मचारी</span><b><?= number_format($sc['total']) ?></b>
      <i class="sc-split" title="ट्रस्ट <?= $sc['trust'] ?> · College/School <?= $sc['college'] ?>"><i style="width:<?= $pct($sc['trust'], $sc['total']) ?>%;background:#F59E0B;"></i><i style="width:<?= $pct($sc['college'], $sc['total']) ?>%;background:var(--rail);"></i></i></div>
    <div class="sc-kpi"><span>🏛️ ट्रस्ट</span><b style="color:#B45309;"><?= number_format($sc['trust']) ?></b><small><?= $pct($sc['trust'], $sc['total']) ?>%</small></div>
    <div class="sc-kpi"><span>🎓 College / School</span><b style="color:var(--rail);"><?= number_format($sc['college']) ?></b><small><?= count(array_filter($campuses, fn($c) => !$c['trust'])) ?> Campus</small></div>
    <div class="sc-kpi"><span>👨 पुरुष · 👩 स्त्री</span><b><?= $sc['male'] ?> · <?= $sc['female'] ?></b><small><?= $sc['total'] - $sc['male'] - $sc['female'] ? ($sc['total'] - $sc['male'] - $sc['female']) . ' नोंद नाही' : '' ?></small></div>
    <?php if ($hasAtt): ?><div class="sc-kpi"><span>✅ आज हजर</span><b style="color:var(--green,#15803D);"><?= $sc['present'] ?></b><small><?= $pct($sc['present'], $sc['total']) ?>%</small></div>
    <?php elseif ($sc['teach'] > 0): ?><div class="sc-kpi"><span>📚 Teaching</span><b><?= $sc['teach'] ?></b><small>Non-Teaching <?= $sc['total'] - $sc['teach'] ?></small></div>
    <?php else: ?><div class="sc-kpi"><span>🏢 विभाग</span><b><?= count(array_filter(array_keys($depts))) ?></b><small><?= count($campuses) ?> Campus / ट्रस्ट</small></div><?php endif; ?>
    <div class="sc-kpi"><span>🆕 या महिन्यात नवीन</span><b><?= $sc['new'] ?></b></div>
  </div>

  <div class="sc-bar">
    <div class="sc-tabs" role="tablist"><button type="button" class="on" data-v="campus">🏛️ Campus / ट्रस्ट नुसार</button><button type="button" data-v="dept">🏢 विभागनिहाय</button></div>
    <div class="sc-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="Campus / विभाग / HOD शोधा" id="scQ"></div>
  </div>

  <div class="sc-view" data-view="campus">
    <div class="sc-grid">
      <?php foreach ($campuses as $C): $top = array_slice($C['depts'], 0, 3, true); $more = count($C['depts']) - count($top); $cm = max(1, max(array_column($C['depts'], 'n'))); ?>
        <div class="sc-card <?= $C['trust'] ? 'trust' : '' ?>" data-q="<?= h(mb_strtolower($C['name'] . ' ' . implode(' ', array_column($C['depts'], 'name')))) ?>">
          <div class="sc-card-head">
            <div><span class="sc-badge"><?= $C['trust'] ? '🏛️ ट्रस्ट' : '🎓 College / School' ?></span><div class="sc-name"><?= h($C['name']) ?></div></div>
            <div class="sc-count"><?= $C['n'] ?><small>कर्मचारी</small></div>
          </div>
          <div class="sc-meta">
            <span>👨 <?= $C['m'] ?> · 👩 <?= $C['f'] ?></span><span>🏢 <?= count($C['depts']) ?> विभाग</span>
            <?php if ($hasAtt): ?><span class="<?= $pct($C['present'], $C['n']) < 70 ? 'low' : '' ?>">✅ आज <?= $C['present'] ?> (<?= $pct($C['present'], $C['n']) ?>%)</span><?php endif; ?>
            <span class="sc-share"><?= $pct($C['n'], $sc['total']) ?>% संस्थेचे</span>
          </div>
          <div class="sc-depts">
            <?php foreach ($C['depts'] as $did => $d): $hidden = !isset($top[$did]); ?>
              <a class="sc-drow <?= $hidden ? 'more' : '' ?>" href="staff/index.php<?= $did ? '?department_id=' . $did : '' ?>">
                <span class="nm" title="<?= h($d['name']) ?>"><?= h($d['name']) ?></span>
                <span class="bar"><i style="width:<?= round($d['n'] * 100 / $cm) ?>%;"></i></span>
                <b><?= $d['n'] ?></b>
              </a>
            <?php endforeach; ?>
          </div>
          <?php if ($more > 0): ?><button type="button" class="sc-morebtn" onclick="var c=this.closest('.sc-card');c.classList.toggle('open');this.textContent=c.classList.contains('open')?'कमी दाखवा ▴':'आणखी <?= $more ?> विभाग ▾';">आणखी <?= $more ?> विभाग ▾</button><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="sc-view" data-view="dept" hidden>
    <div class="sc-dsort">क्रम: <button type="button" class="on" data-s="n">संख्या</button><button type="button" data-s="name">नाव</button><button type="button" data-s="campus">Campus</button></div>
    <div class="sc-dlist">
      <?php foreach ($depts as $d): ?>
        <a class="sc-dline" href="staff/index.php<?= $d['id'] ? '?department_id=' . $d['id'] : '' ?>" data-q="<?= h(mb_strtolower($d['name'] . ' ' . $d['local'] . ' ' . $d['campus'] . ' ' . $d['hod'])) ?>" data-n="<?= $d['n'] ?>" data-name="<?= h(mb_strtolower($d['name'])) ?>" data-campus="<?= h(mb_strtolower($d['campus'])) ?>">
          <div class="info"><b><?= h($d['name']) ?></b><?= $d['local'] && $d['local'] !== $d['name'] ? ' <small>' . h($d['local']) . '</small>' : '' ?>
            <div class="sub"><?= $d['trust'] ? '🏛️' : '🎓' ?> <?= h($d['campus']) ?><?= $d['hod'] ? ' · 👑 ' . h($d['hod']) : '' ?></div></div>
          <div class="bar"><i class="m" style="width:<?= round($d['m'] * 100 / $maxDept) ?>%;"></i><i class="f" style="width:<?= round($d['f'] * 100 / $maxDept) ?>%;"></i><i class="o" style="width:<?= round(($d['n'] - $d['m'] - $d['f']) * 100 / $maxDept) ?>%;"></i></div>
          <div class="num"><b><?= $d['n'] ?></b><?php if ($hasAtt): ?><small>आज <?= $d['present'] ?></small><?php endif; ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="sc-legend"><span><i style="background:#3B82F6;"></i>पुरुष</span><span><i style="background:#EC4899;"></i>स्त्री</span><span><i style="background:#CBD5E1;"></i>नोंद नाही</span></div>
  </div>
  <div class="sc-none" hidden>शोधात काही सापडलं नाही</div>
</div>
<style>
  /* लहान, नीटनेटका — Dashboard वर जास्त जागा घेऊ नये */
  .sc-wrap .panel-head{padding-top:10px;padding-bottom:8px;}
  .sc-kpis{display:flex;flex-wrap:wrap;gap:6px;padding:8px 14px 2px;}
  .sc-kpi{background:var(--canvas);border-radius:9px;padding:5px 10px;min-width:0;display:flex;align-items:baseline;gap:6px;}
  .sc-kpi span{font-size:11px;color:var(--text-dim);font-weight:600;white-space:nowrap;}
  .sc-kpi b{font-family:'JetBrains Mono',monospace;font-size:14px;line-height:1.2;} .sc-kpi small{font-size:10.5px;color:var(--text-dim);white-space:nowrap;}
  .sc-kpi.big{background:var(--rail-soft,#FDEBDD);} .sc-kpi.big b{font-size:16px;color:var(--rail);}
  .sc-split{display:none;}
  .sc-bar{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;padding:6px 14px;}
  .sc-tabs{display:flex;background:var(--canvas);border-radius:8px;padding:2px;} .sc-tabs button{border:0;background:none;padding:4px 10px;border-radius:7px;font-size:11.5px;font-weight:600;color:var(--text-dim);}
  .sc-tabs button.on{background:var(--surface);color:var(--text);box-shadow:0 1px 3px rgba(0,0,0,.08);}
  .sc-search{position:relative;flex:0 1 200px;} .sc-search i{position:absolute;left:9px;top:50%;transform:translateY(-50%);font-size:11px;color:var(--text-dim);}
  .sc-search input{width:100%;border:1px solid var(--line);border-radius:8px;padding:4px 8px 4px 26px;font-size:11.5px;background:var(--surface);color:var(--text);}
  .sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:8px;padding:4px 14px 12px;}
  .sc-card{border:1px solid var(--line);border-radius:11px;padding:8px 10px;background:var(--surface);border-left:3px solid var(--rail);}
  .sc-card.trust{border-left-color:#F59E0B;}
  .sc-card-head{display:flex;justify-content:space-between;gap:6px;align-items:center;}
  .sc-badge{font-size:9.5px;font-weight:700;color:var(--text-dim);} .sc-name{font-weight:700;font-size:12.5px;line-height:1.25;}
  .sc-count{font-family:'JetBrains Mono',monospace;font-weight:800;font-size:18px;line-height:1;text-align:right;color:var(--rail);} .sc-card.trust .sc-count{color:#B45309;}
  .sc-count small{display:none;}
  .sc-meta{display:flex;flex-wrap:wrap;gap:2px 8px;font-size:10.5px;color:var(--text-dim);margin:4px 0;} .sc-meta .low{color:var(--rose);font-weight:700;} .sc-share{margin-left:auto;}
  .sc-drow{display:grid;grid-template-columns:minmax(0,1fr) 60px 26px;gap:6px;align-items:center;padding:2px 0;font-size:11.5px;color:inherit;text-decoration:none;}
  .sc-drow:hover .nm{color:var(--rail);} .sc-drow .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sc-drow .bar{height:5px;background:var(--canvas);border-radius:3px;overflow:hidden;} .sc-drow .bar i{display:block;height:100%;background:var(--rail);border-radius:3px;}
  .sc-card.trust .sc-drow .bar i{background:#F59E0B;} .sc-drow b{text-align:right;font-family:'JetBrains Mono',monospace;font-size:11.5px;}
  .sc-drow.more{display:none;} .sc-card.open .sc-drow.more{display:grid;}
  .sc-morebtn{border:0;background:none;color:var(--rail);font-size:11px;font-weight:600;padding:2px 0 0;}
  .sc-dsort{padding:0 14px 4px;font-size:11px;color:var(--text-dim);} .sc-dsort button{border:1px solid var(--line);background:var(--surface);border-radius:6px;font-size:10.5px;padding:1px 7px;margin-left:3px;} .sc-dsort button.on{background:var(--ink,#10162B);color:#fff;border-color:transparent;}
  .sc-dlist{padding:0 14px 6px;max-height:300px;overflow:auto;}
  .sc-dline{display:grid;grid-template-columns:minmax(140px,1.2fr) 2fr 52px;gap:10px;align-items:center;padding:4px 0;border-bottom:1px dashed var(--line);color:inherit;text-decoration:none;font-size:12px;}
  .sc-dline:hover b{color:var(--rail);} .sc-dline small{color:var(--text-dim);font-size:10.5px;} .sc-dline .sub{font-size:10.5px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .sc-dline .bar{display:flex;height:6px;background:var(--canvas);border-radius:3px;overflow:hidden;} .sc-dline .bar i{display:block;height:100%;}
  .sc-dline .bar .m{background:#3B82F6;} .sc-dline .bar .f{background:#EC4899;} .sc-dline .bar .o{background:#CBD5E1;}
  .sc-dline .num{text-align:right;} .sc-dline .num b{font-family:'JetBrains Mono',monospace;font-size:13px;display:block;} .sc-dline .num small{font-size:10px;}
  .sc-legend{display:flex;gap:10px;padding:2px 14px 10px;font-size:10.5px;color:var(--text-dim);} .sc-legend i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:3px;}
  .sc-none{padding:10px 14px;color:var(--text-dim);font-size:12px;}
  @media (max-width:600px){ .sc-search{flex:1 1 100%;} .sc-grid{grid-template-columns:1fr 1fr;} .sc-dline{grid-template-columns:minmax(0,1fr) 44px;} .sc-dline .bar{grid-column:1 / -1;order:3;} }
  @media (max-width:420px){ .sc-grid{grid-template-columns:1fr;} }
</style>
<script>
(function () {
  var root = document.getElementById('staffCount'); if (!root) return;
  var q = root.querySelector('#scQ'), none = root.querySelector('.sc-none'), view = 'campus';
  function apply() {
    var t = (q.value || '').trim().toLowerCase(), shown = 0;
    root.querySelectorAll('.sc-view[data-view="' + view + '"] [data-q]').forEach(function (el) {
      var ok = !t || el.dataset.q.indexOf(t) > -1; el.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    none.hidden = shown > 0;
  }
  root.querySelectorAll('.sc-tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      view = b.dataset.v;
      root.querySelectorAll('.sc-tabs button').forEach(function (x) { x.classList.toggle('on', x === b); });
      root.querySelectorAll('.sc-view').forEach(function (v) { v.hidden = v.dataset.view !== view; });
      try { localStorage.setItem('sc_view', view); } catch (e) {}
      apply();
    });
  });
  root.querySelectorAll('.sc-dsort button').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.dataset.s, list = root.querySelector('.sc-dlist'), rows = Array.prototype.slice.call(list.children);
      rows.sort(function (a, c) { return k === 'n' ? c.dataset.n - a.dataset.n : a.dataset[k].localeCompare(c.dataset[k]); });
      rows.forEach(function (r) { list.appendChild(r); });
      root.querySelectorAll('.sc-dsort button').forEach(function (x) { x.classList.toggle('on', x === b); });
    });
  });
  q.addEventListener('input', apply);
  try { var v = localStorage.getItem('sc_view'); if (v === 'dept') root.querySelector('.sc-tabs button[data-v="dept"]').click(); } catch (e) {}
})();
</script>
