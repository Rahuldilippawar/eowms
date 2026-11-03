<?php
$pageTitle = 'पदे व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/role-permission-groups.php';
require_once __DIR__ . '/../includes/smart-insights.php';

$categoryLabels = [
    'super_admin' => 'Super Admin (सर्वोच्च)',
    'management' => 'Management (President/GM स्तर — संपूर्ण संस्था दिसते)',
    'hod' => 'HOD (विभागप्रमुख — फक्त स्वतःचा विभाग)',
    'staff' => 'Staff (कर्मचारी — फक्त स्वतःचा विभाग)',
];
$categoryShort = ['super_admin' => 'Super Admin', 'management' => 'Management', 'hod' => 'HOD', 'staff' => 'Staff'];
$categoryChip = ['super_admin' => 'overdue', 'management' => 'pending', 'hod' => 'done', 'staff' => 'done'];

$roles = $pdo->query("SELECT * FROM role_definitions ORDER BY FIELD(category,'super_admin','management','hod','staff'), label")->fetchAll();
$roleKeys = array_column($roles, 'role_key');

// प्रत्येक पदावर किती व्यक्ती (सक्रिय / एकूण)
$usage = [];
foreach ($pdo->query("SELECT role, COUNT(*) c, SUM(is_active = 1) a FROM users GROUP BY role")->fetchAll() as $row) {
    $usage[$row['role']] = ['c' => (int)$row['c'], 'a' => (int)$row['a']];
}
// प्रत्येक पदाचे Permissions
$allPermissions = $pdo->query("SELECT * FROM permissions ORDER BY sort_order")->fetchAll();
$rolePerms = [];
foreach ($pdo->query("SELECT role_key, permission_key FROM role_permissions")->fetchAll() as $rp) {
    $rolePerms[$rp['role_key']][$rp['permission_key']] = true;
}
$permTotal = count($allPermissions);
$sensitive = array_flip(roleSensitivePermissions());

// ---------- Smart तपासण्या ----------
$orphans = array_filter($usage, fn($u, $k) => !in_array($k, $roleKeys, true), ARRAY_FILTER_USE_BOTH);
$unused = array_filter($roles, fn($r) => !$r['is_system'] && empty($usage[$r['role_key']]['c']));
$staffSensitive = [];
foreach ($roles as $r) {
    if ($r['category'] !== 'staff') continue;
    $hits = array_intersect_key($rolePerms[$r['role_key']] ?? [], $sensitive);
    if ($hits) $staffSensitive[$r['role_key']] = count($hits);
}
$mgmtNoPerms = array_filter($roles, fn($r) => in_array($r['category'], ['management', 'hod'], true) && !empty($usage[$r['role_key']]['a']) && empty($rolePerms[$r['role_key']]));
$restricted = array_filter($roles, fn($r) => !empty($r['restricted_mode']));
$byCat = array_count_values(array_column($roles, 'category'));
$labelOf = fn($k) => ($r = current(array_filter($roles, fn($x) => $x['role_key'] === $k))) ? ($r['label_local'] ?: $r['label']) : $k;

$alerts = [];
foreach ($orphans as $k => $u) {
    $alerts[] = ['bad', "'{$k}' हे पद अस्तित्वात नाही, पण {$u['c']} व्यक्तींना दिलेलं आहे — त्यांना कोणतेही Permissions मिळत नाहीत. नवीन पद तयार करा किंवा त्यांचं पद बदला.", '../staff/index.php'];
}
foreach ($staffSensitive as $k => $n) {
    $alerts[] = ['warn', "'" . $labelOf($k) . "' (Staff category) ला {$n} संवेदनशील अधिकार आहेत — संपूर्ण संस्थेचा Data दिसू शकतो. खात्री करा.", 'edit.php?role_key=' . urlencode($k)];
}
foreach ($mgmtNoPerms as $r) {
    $alerts[] = ['info', "'" . ($r['label_local'] ?: $r['label']) . "' पदावर सक्रिय व्यक्ती आहेत पण एकही अतिरिक्त Permission नाही — गरज असल्यास तपासा.", 'edit.php?role_key=' . urlencode($r['role_key'])];
}
if (count($unused) >= 1) {
    $alerts[] = ['info', count($unused) . ' पदं कोणालाही दिलेली नाहीत — गरज नसल्यास हटवू शकता (यादीत "न वापरलेली" Filter).', '#rolesList'];
}
$groups = roleGroupedPermissions($allPermissions);
?>

<div class="page-eyebrow">Super Admin</div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= h(t('s328')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन पद जोडा
  </a>
</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">
  प्रत्येक पद एका access category शी जोडलेलं आहे — तेच ठरवतं की त्या पदावरील व्यक्तीला किती data दिसेल.
  नवीन पद (उदा. Director, Trustee, Coordinator) कधीही जोडा — योग्य category निवडून permission आपोआप मिळेल.
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php
smartTiles([
    ['label' => 'एकूण पदे', 'value' => count($roles), 'sub' => ($byCat['management'] ?? 0) . ' Management · ' . ($byCat['hod'] ?? 0) . ' HOD · ' . ($byCat['staff'] ?? 0) . ' Staff'],
    ['label' => 'वापरात असलेली', 'value' => count(array_filter($roles, fn($r) => !empty($usage[$r['role_key']]['c']))), 'sub' => array_sum(array_column($usage, 'a')) . ' सक्रिय व्यक्ती', 'tone' => 'ok'],
    ['label' => 'न वापरलेली', 'value' => count($unused), 'sub' => 'कोणालाही दिलेली नाहीत', 'tone' => $unused ? 'warn' : ''],
    ['label' => '🎯 Restricted पदे', 'value' => count($restricted), 'sub' => 'ठराविक Modules पुरती'],
    ['label' => 'तपासणी', 'value' => count($orphans) + count($staffSensitive), 'sub' => count($orphans) ? 'पद नसलेले कर्मचारी!' : 'धोकादायक काही नाही', 'tone' => count($orphans) ? 'bad' : (count($staffSensitive) ? 'warn' : 'ok')],
]);
smartAlerts($alerts);
?>

<div class="rl-tabs mb-3" role="tablist">
  <button type="button" class="period-btn active" data-tab="list"><i class="fa-solid fa-list me-1"></i>पदांची यादी</button>
  <button type="button" class="period-btn" data-tab="matrix"><i class="fa-solid fa-table-cells me-1"></i>Permission Matrix</button>
</div>

<!-- ============ यादी ============ -->
<div class="panel" id="rolesList" data-tab-pane="list">
  <div class="rl-filters">
    <?php foreach (['' => 'सर्व', 'management' => 'Management', 'hod' => 'HOD', 'staff' => 'Staff', 'unused' => 'न वापरलेली', 'restricted' => '🎯 Restricted'] as $k => $l): ?>
      <button type="button" class="rl-chip <?= $k === '' ? 'on' : '' ?>" data-f="<?= $k ?>"><?= $l ?></button>
    <?php endforeach; ?>
  </div>
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s329')) ?></th><th>Access Category</th><th><?= h(t('s330')) ?></th><th>Permissions</th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php foreach ($roles as $r):
        $k = $r['role_key']; $u = $usage[$k] ?? ['c' => 0, 'a' => 0];
        $pc = count($rolePerms[$k] ?? []); $sc = count(array_intersect_key($rolePerms[$k] ?? [], $sensitive));
        $isUnused = !$r['is_system'] && !$u['c'];
        $flags = $r['category'] . ($isUnused ? ' unused' : '') . (!empty($r['restricted_mode']) ? ' restricted' : ''); ?>
        <tr data-flags="<?= h($flags) ?>">
          <td style="font-weight:500;">
            <?= h($r['label_local'] ?: $r['label']) ?> <span style="color:var(--text-dim);font-size:12px;">(<?= h($r['label']) ?>)</span>
            <?php if ($r['is_system']): ?><span title="System पद — नाव/Category बदलता येत नाही" style="color:var(--text-dim);font-size:11px;margin-left:4px;"><i class="fa-solid fa-lock"></i></span><?php endif; ?>
            <?php if (!empty($r['restricted_mode'])): ?>
              <span title="Single-Purpose Access Mode चालू आहे" style="background:#FBEBD6;color:#C97F1D;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:6px;">🎯 Restricted</span>
            <?php endif; ?>
            <div class="font-mono" style="font-size:11px;color:var(--text-dim);"><?= h($k) ?></div>
          </td>
          <td><span class="status-chip <?= $categoryChip[$r['category']] ?>" title="<?= h($categoryLabels[$r['category']]) ?>"><?= h($categoryShort[$r['category']]) ?></span></td>
          <td class="font-mono" data-sort="<?= $u['c'] ?>">
            <?php if ($u['c']): ?><b><?= $u['a'] ?></b><?= $u['c'] > $u['a'] ? ' <span style="color:var(--text-dim);font-size:11px;">+' . ($u['c'] - $u['a']) . ' निष्क्रिय</span>' : '' ?>
            <?php else: ?><span style="color:var(--text-dim);">0</span><?php endif; ?>
          </td>
          <td data-sort="<?= $k === 'super_admin' ? 999 : $pc ?>">
            <?php if ($k === 'super_admin'): ?><span style="font-size:12px;color:var(--rail);font-weight:600;">सर्व अधिकार</span>
            <?php else: ?>
              <div class="rl-bar" title="<?= $pc ?> / <?= $permTotal ?>"><i style="width:<?= $permTotal ? round($pc * 100 / $permTotal) : 0 ?>%;"></i></div>
              <span class="font-mono" style="font-size:12px;"><?= $pc ?>/<?= $permTotal ?></span><?= $sc ? ' <span title="संवेदनशील अधिकार" style="color:var(--amber);font-size:11px;font-weight:600;">⚠ ' . $sc . ' संवेदनशील</span>' : '' ?>
            <?php endif; ?>
          </td>
          <td style="white-space:nowrap;text-align:right;">
            <a href="edit.php?role_key=<?= urlencode($k) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;">
              <?php if ($r['is_system']): ?><i class="fa-solid fa-sliders me-1"></i>Permissions<?php else: ?><i class="fa-solid fa-pen me-1"></i>Edit<?php endif; ?>
            </a>
            <?php if ($k !== 'super_admin'): ?>
              <a href="create.php?copy=<?= urlencode($k) ?>" class="btn btn-sm" title="याच Permissions सह नवीन पद" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 10px;"><i class="fa-regular fa-copy"></i></a>
            <?php endif; ?>
            <?php if ($isUnused): ?>
              <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('\'<?= h(addslashes($r['label_local'] ?: $r['label'])) ?>\' हे पद कायमचे हटवायचे?');">
                <input type="hidden" name="role_key" value="<?= h($k) ?>">
                <button type="submit" class="btn btn-sm" title="हटवा (कोणालाही दिलेलं नाही)" style="border:1px solid var(--line);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 10px;"><i class="fa-solid fa-trash"></i></button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- ============ Permission Matrix ============ -->
<?php $matrixRoles = array_values(array_filter($roles, fn($r) => $r['role_key'] !== 'super_admin')); ?>
<div class="panel" data-tab-pane="matrix" hidden>
  <div class="panel-head"><div class="title">🧩 Permission Matrix</div><div class="sub">कोणत्या पदाला कोणता अधिकार — एका नजरेत. ⚠ = संवेदनशील. बदलण्यासाठी पदाच्या नावावर Click करा.</div></div>
  <div class="rl-matrix-wrap">
  <table class="rl-matrix" data-no-filter>
    <thead><tr><th class="rl-perm-col">अधिकार</th>
      <?php foreach ($matrixRoles as $r): ?><th class="rl-role-col"><a href="edit.php?role_key=<?= urlencode($r['role_key']) ?>" title="<?= h($r['label']) ?> · <?= h($categoryShort[$r['category']]) ?>"><span><?= h($r['label_local'] ?: $r['label']) ?></span></a></th><?php endforeach; ?>
      <th class="rl-role-col"><span>किती पदे</span></th>
    </tr></thead>
    <tbody>
      <?php foreach ($groups as $g): ?>
        <tr class="rl-group"><td colspan="<?= count($matrixRoles) + 2 ?>"><?= h($g['label']) ?></td></tr>
        <?php foreach ($g['perms'] as $p): $pk = $p['permission_key']; $n = 0; ?>
          <tr>
            <td class="rl-perm-col" title="<?= h($p['label']) ?>"><?= isset($sensitive[$pk]) ? '<span style="color:var(--amber);">⚠</span> ' : '' ?><?= h($p['label_local'] ?: $p['label']) ?></td>
            <?php foreach ($matrixRoles as $r): $has = !empty($rolePerms[$r['role_key']][$pk]); if ($has) $n++; ?>
              <td class="rl-cell <?= $has ? 'on' : '' ?>"><?= $has ? '✓' : '' ?></td>
            <?php endforeach; ?>
            <td class="rl-cell font-mono" style="<?= $n ? '' : 'color:var(--text-dim);' ?>"><?= $n ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<style>
  .rl-tabs { display: inline-flex; gap: 4px; background: var(--canvas); border-radius: 10px; padding: 4px; }
  .rl-tabs .period-btn { border: 0; }
  .rl-filters { display: flex; flex-wrap: wrap; gap: 6px; padding: 12px 16px 4px; }
  .rl-chip { border: 1px solid var(--line); background: transparent; border-radius: 999px; padding: 4px 12px; font-size: 12px; color: var(--text-dim); cursor: pointer; }
  .rl-chip.on { background: var(--rail); border-color: var(--rail); color: #fff; }
  .rl-bar { display: inline-block; width: 70px; height: 6px; border-radius: 4px; background: var(--canvas); overflow: hidden; vertical-align: middle; margin-right: 6px; }
  .rl-bar i { display: block; height: 100%; background: var(--rail); border-radius: 4px; }
  .rl-matrix-wrap { overflow: auto; max-height: 75vh; }
  .rl-matrix { border-collapse: separate; border-spacing: 0; font-size: 12px; min-width: 100%; }
  .rl-matrix th, .rl-matrix td { border-bottom: 1px solid var(--line); padding: 6px 8px; }
  .rl-matrix thead th { position: sticky; top: 0; background: var(--panel, #fff); z-index: 2; vertical-align: bottom; }
  .rl-perm-col { position: sticky; left: 0; background: var(--panel, #fff); z-index: 1; min-width: 240px; max-width: 320px; }
  .rl-matrix thead .rl-perm-col { z-index: 3; }
  .rl-role-col { width: 38px; height: 150px; }
  .rl-role-col a, .rl-role-col > span { display: block; writing-mode: vertical-rl; transform: rotate(180deg); white-space: nowrap; max-height: 140px; overflow: hidden; text-overflow: ellipsis; font-size: 11.5px; font-weight: 600; color: var(--text); text-decoration: none; margin: 0 auto; }
  .rl-role-col a:hover { color: var(--rail); }
  .rl-cell { text-align: center; color: var(--rail); font-weight: 700; }
  .rl-cell.on { background: rgba(27, 138, 131, .1); }
  .rl-matrix tbody tr:hover td { background: rgba(27, 138, 131, .05); }
  .rl-group td { background: var(--canvas) !important; font-weight: 700; font-size: 12px; position: sticky; left: 0; }
</style>
<script>
(function () {
  document.querySelectorAll('.rl-tabs [data-tab]').forEach(function (b) {
    b.addEventListener('click', function () {
      document.querySelectorAll('.rl-tabs [data-tab]').forEach(function (x) { x.classList.toggle('active', x === b); });
      document.querySelectorAll('[data-tab-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-tab-pane') !== b.getAttribute('data-tab'); });
      try { localStorage.setItem('eowmsRolesTab', b.getAttribute('data-tab')); } catch (e) {}
    });
  });
  try { var t = localStorage.getItem('eowmsRolesTab'); if (t) { var b = document.querySelector('.rl-tabs [data-tab="' + t + '"]'); if (b) b.click(); } } catch (e) {}
  document.querySelectorAll('.rl-chip').forEach(function (c) {
    c.addEventListener('click', function () {
      document.querySelectorAll('.rl-chip').forEach(function (x) { x.classList.toggle('on', x === c); });
      var f = c.getAttribute('data-f');
      document.querySelectorAll('#rolesList tbody tr').forEach(function (tr) {
        tr.style.display = !f || (' ' + tr.getAttribute('data-flags') + ' ').indexOf(' ' + f + ' ') !== -1 ? '' : 'none';
      });
    });
  });
  if (location.hash === '#rolesList') { var u = document.querySelector('.rl-chip[data-f="unused"]'); if (u) u.click(); }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
