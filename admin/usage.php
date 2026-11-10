<?php
// 📊 Module वापर — कोणता Module किती वापरला, कोणता विभाग Login च करत नाही (Training कुठे द्यायचं)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/module-registry.php';
$orgId = activeOrgId();
$days = in_array((int)($_GET['days'] ?? 30), [7, 30, 90], true) ? (int)($_GET['days'] ?? 30) : 30;
$ready = saTableReady($pdo, 'module_usage_daily');
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

$q = function ($sql, $p) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (PDOException $e) { return []; } };
$mods = $ready ? $q("SELECT module, SUM(hits) hits, COUNT(DISTINCT user_id) users FROM module_usage_daily WHERE organization_id = ? AND day >= ? GROUP BY module ORDER BY hits DESC", [$orgId, $from]) : [];
$daily = $ready ? $q("SELECT day, COUNT(DISTINCT user_id) u FROM module_usage_daily WHERE organization_id = ? AND day >= ? GROUP BY day ORDER BY day", [$orgId, $from]) : [];
$activeStaff = (int)($q("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND is_active = 1", [$orgId])[0]['c'] ?? 0);
// विभागनिहाय — Login केलेले / एकूण (users.last_login_at)
$deptRows = $q("SELECT d.id, COALESCE(NULLIF(d.name_local,''), d.name) dept, COUNT(u.id) total,
    SUM(u.last_login_at >= ?) active, MAX(u.last_login_at) last_login
    FROM departments d LEFT JOIN users u ON u.department_id = d.id AND u.is_active = 1
    WHERE d.organization_id = ? AND d.is_active = 1 GROUP BY d.id HAVING total > 0 ORDER BY (SUM(u.last_login_at >= ?) / COUNT(u.id)), dept", [$from, $orgId, $from]);
$never = $q("SELECT u.id, u.name, COALESCE(NULLIF(d.name_local,''), d.name) dept, u.last_login_at, u.created_at FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND (u.last_login_at IS NULL OR u.last_login_at < ?) ORDER BY u.last_login_at IS NULL DESC, u.last_login_at, u.name LIMIT 60", [$orgId, $from]);
$topUsers = $ready ? $q("SELECT u.name, SUM(m.hits) hits, COUNT(DISTINCT m.day) days FROM module_usage_daily m JOIN users u ON u.id = m.user_id WHERE m.organization_id = ? AND m.day >= ? GROUP BY m.user_id ORDER BY hits DESC LIMIT 8", [$orgId, $from]) : [];

$reg = getModuleRegistry();
$names = ['dashboard' => '🏠 Dashboard', 'daily-work' => '📝 दैनंदिन कामकाज', 'tasks' => '✅ Tasks', 'letters' => '✉️ पत्रव्यवहार', 'requests' => '🙋 विनंत्या', 'staff' => '👥 कर्मचारी / हजेरी',
    'renewals' => '🔁 नूतनीकरण', 'messages' => '💬 संदेश', 'notifications' => '🔔 सूचना', 'reports' => '📊 अहवाल', 'ecampus' => '🎓 E-Campus', 'store' => '📦 भांडार', 'account' => '👤 खातं',
    'roles' => '🎖️ पदे', 'departments' => '🗂️ विभाग', 'campuses' => '🏫 Campus', 'admin' => '🛡️ Super Admin', 'system-update' => '⚙️ System Update', 'backup' => '💾 Backup', 'activity-log' => '📜 Activity Log'];
$label = fn($m) => $names[$m] ?? ($reg[$m]['label'] ?? $m);
// कधीच न उघडलेले Modules (Registry मधले)
$usedSet = array_flip(array_column($mods, 'module'));
$unused = array_filter(array_keys($reg), fn($k) => !isset($usedSet[$k]));
$avgDau = $daily ? round(array_sum(array_column($daily, 'u')) / count($daily), 1) : 0;

$pageTitle = 'Module वापर';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$loggedIn = array_sum(array_column($deptRows, 'active'));
?>
<div class="page-eyebrow">Super Admin</div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-1">📊 Module वापर</div>
  <div class="rl-tabs"><?php foreach ([7 => '7 दिवस', 30 => '30 दिवस', 90 => '90 दिवस'] as $k => $l): ?><a class="period-btn <?= $days === $k ? 'active' : '' ?>" href="?days=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
</div>
<?= saNav('usage') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — Module वापराची मोजणी त्यानंतर सुरू होईल.</div><?php endif; ?>
<?php smartTiles([
    ['label' => 'रोज सरासरी वापरकर्ते', 'value' => $avgDau, 'sub' => 'गेले ' . $days . ' दिवस'],
    ['label' => 'Login केलेले', 'value' => $loggedIn . '/' . $activeStaff, 'sub' => $activeStaff ? round($loggedIn * 100 / $activeStaff) . '% कर्मचारी' : '', 'tone' => $activeStaff && $loggedIn / $activeStaff < .6 ? 'warn' : 'ok'],
    ['label' => 'Login न केलेले', 'value' => count($never), 'sub' => $days . ' दिवसांत एकदाही नाही', 'tone' => $never ? 'warn' : 'ok'],
    ['label' => 'न वापरलेले Modules', 'value' => count($unused), 'sub' => 'या कालावधीत कोणीच उघडले नाहीत'],
]); ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel h-100"><div class="panel-head"><div class="title">कोणता Module किती वापरला</div><div class="sub">पानं उघडल्याची संख्या · किती वेगवेगळ्या व्यक्तींनी</div></div>
      <?php if (!$mods): ?><div class="sm-empty">अजून नोंद नाही — आजपासून मोजणी सुरू.</div><?php else: $max = max(array_column($mods, 'hits')); ?>
      <div style="padding:8px 16px 14px;">
        <?php foreach ($mods as $m): ?>
          <div class="us-row"><span class="nm"><?= h($label($m['module'])) ?></span><span class="bar"><i style="width:<?= max(2, round($m['hits'] * 100 / $max)) ?>%;"></i></span><span class="n"><b><?= (int)$m['hits'] ?></b> · <?= (int)$m['users'] ?> जण</span></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <?php if ($unused): ?><div style="padding:0 16px 14px;font-size:12px;color:var(--text-dim);">न उघडलेले: <?= h(implode(', ', array_map(fn($k) => $reg[$k]['label'], $unused))) ?></div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel mb-3"><div class="panel-head"><div class="title">रोजचे वापरकर्ते</div></div>
      <?php if (!$daily): ?><div class="sm-empty">अजून नोंद नाही</div><?php else: $mx = max(array_column($daily, 'u')); ?>
        <div class="us-spark"><?php foreach ($daily as $d): ?><span title="<?= h(date('d/m', strtotime($d['day'])) . ' — ' . $d['u'] . ' जण') ?>" style="height:<?= max(4, round($d['u'] * 100 / max(1, $mx))) ?>%;"></span><?php endforeach; ?></div>
      <?php endif; ?>
    </div>
    <div class="panel"><div class="panel-head"><div class="title">सर्वात जास्त वापरणारे</div></div>
      <?php if (!$topUsers): ?><div class="sm-empty">—</div><?php else: foreach ($topUsers as $t): ?>
        <div class="us-row" style="padding:6px 16px;"><span class="nm"><?= h($t['name']) ?></span><span class="n"><?= (int)$t['hits'] ?> पानं · <?= (int)$t['days'] ?> दिवस</span></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">विभागनिहाय Login</div><div class="sub">कमी वापर आधी — इथे Training द्या</div></div>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>विभाग</th><th>Login केलेले</th><th>शेवटचं Login</th></tr></thead><tbody>
        <?php foreach ($deptRows as $d): $p = $d['total'] ? round($d['active'] * 100 / $d['total']) : 0; ?>
          <tr><td><?= h($d['dept']) ?></td><td data-sort="<?= $p ?>"><b style="color:<?= $p >= 70 ? '#137A4B' : ($p >= 40 ? '#B45309' : '#B42318') ?>;"><?= (int)$d['active'] ?>/<?= (int)$d['total'] ?></b> <span style="font-size:11px;color:var(--text-dim);">(<?= $p ?>%)</span></td>
            <td style="font-size:12px;" data-sort="<?= h($d['last_login'] ?? '') ?>"><?= $d['last_login'] ? date('d/m/Y', strtotime($d['last_login'])) : '<span style="color:var(--rose);">कधीच नाही</span>' ?></td></tr>
        <?php endforeach; ?>
      </tbody></table></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">Login न केलेले कर्मचारी</div><div class="sub"><?= $days ?> दिवसांत एकदाही नाही</div></div>
      <?php if (!$never): ?><div class="sm-empty">सगळ्यांनी Login केलं ✓</div><?php else: ?>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>नाव</th><th>विभाग</th><th>शेवटचं Login</th></tr></thead><tbody>
        <?php foreach ($never as $n): ?><tr><td><a href="../staff/profile.php?id=<?= (int)$n['id'] ?>"><?= h($n['name']) ?></a></td><td><?= h($n['dept'] ?: '—') ?></td><td style="font-size:12px;"><?= $n['last_login_at'] ? date('d/m/Y', strtotime($n['last_login_at'])) : '<span style="color:var(--rose);">कधीच नाही</span>' ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
<style>
  .rl-tabs { display: inline-flex; gap: 4px; background: var(--canvas); border-radius: 10px; padding: 4px; } .rl-tabs .period-btn { border: 0; text-decoration: none; }
  .us-row { display: flex; align-items: center; gap: 10px; padding: 4px 0; font-size: 12.5px; }
  .us-row .nm { width: 38%; min-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; } .us-row .bar { flex: 1; height: 8px; background: var(--canvas); border-radius: 5px; overflow: hidden; }
  .us-row .bar i { display: block; height: 100%; background: var(--rail); border-radius: 5px; } .us-row .n { font-size: 11.5px; color: var(--text-dim); white-space: nowrap; margin-left: auto; }
  .us-spark { display: flex; align-items: flex-end; gap: 2px; height: 90px; padding: 10px 16px 14px; } .us-spark span { flex: 1; background: color-mix(in srgb,var(--rail) 70%,transparent); border-radius: 2px 2px 0 0; min-width: 3px; }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
