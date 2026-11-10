<?php
// 👤 व्यक्तीनुसार Access — पद न बदलता एका व्यक्तीला अधिकार द्या / काढा (हवं तर मुदतीपर्यंत), आणि "याला काय दिसतं, का?" तपासा
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/role-permission-groups.php';
require_once __DIR__ . '/../includes/access-explain.php';
$me = currentUser();
$orgId = activeOrgId();

$tableReady = true;
try { $pdo->query("SELECT 1 FROM user_permissions LIMIT 1"); } catch (PDOException $e) { $tableReady = false; }

$uid = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$target = null;
if ($uid) {
    $st = $pdo->prepare("SELECT u.*, d.name_local AS dept_name, c.name AS campus_name FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id WHERE u.id = ? AND u.organization_id = ?");
    $st->execute([$uid, $orgId]);
    $target = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $target && $tableReady) {
    if ($target['role'] === 'super_admin') { $_SESSION['form_error'] = 'Super Admin ला सगळे अधिकार आधीच असतात.'; header('Location: user-access.php?user_id=' . $uid); exit; }
    $validKeys = $pdo->query("SELECT permission_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
    $modes = (array)($_POST['mode'] ?? []);
    $exp = (array)($_POST['expires_on'] ?? []);
    $notes = (array)($_POST['note'] ?? []);
    $old = [];
    $st = $pdo->prepare("SELECT permission_key, mode FROM user_permissions WHERE user_id = ?");
    $st->execute([$uid]);
    foreach ($st->fetchAll() as $r) { $old[$r['permission_key']] = $r['mode']; }

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM user_permissions WHERE user_id = ?")->execute([$uid]);
    $ins = $pdo->prepare("INSERT INTO user_permissions (user_id, permission_key, mode, expires_on, note, granted_by) VALUES (?,?,?,?,?,?)");
    $new = [];
    foreach ($modes as $k => $m) {
        if (!in_array($k, $validKeys, true) || !in_array($m, ['grant', 'deny'], true)) { continue; }
        $e = trim((string)($exp[$k] ?? ''));
        $e = preg_match('/^\d{4}-\d{2}-\d{2}$/', $e) ? $e : null;
        $ins->execute([$uid, $k, $m, $e, mb_substr(trim((string)($notes[$k] ?? '')), 0, 255) ?: null, $me['id']]);
        $new[$k] = $m;
    }
    $pdo->commit();
    $chg = [];
    foreach ($new as $k => $m) { if (($old[$k] ?? '') !== $m) { $chg[] = ($m === 'grant' ? '+' : '⛔') . $k; } }
    foreach ($old as $k => $m) { if (!isset($new[$k])) { $chg[] = '↺' . $k; } }
    if ($chg) {
        logActivity('व्यक्तीनुसार Permissions बदलले', 'user', $uid, implode(', ', $chg));
        try { notifyUser($uid, $orgId, '🔐 तुमचा Access बदलला', 'Super Admin ने तुमचे काही अधिकार बदलले आहेत — नवीन Modules/पर्याय दिसू शकतात.', 'index.php'); } catch (Throwable $e) {}
    }
    $_SESSION['flash'] = $chg ? count($chg) . ' बदल जतन झाले — लगेच लागू.' : 'काहीही बदल नाही.';
    header('Location: user-access.php?user_id=' . $uid);
    exit;
}

$people = $pdo->prepare("SELECT u.id, u.name, u.role, u.designation, u.is_active, d.name_local AS dept,
    (SELECT COUNT(*) FROM user_extra_department_access x WHERE x.user_id = u.id) AS xd
    FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? ORDER BY u.is_active DESC, u.name");
$people->execute([$orgId]);
$people = $people->fetchAll();
$ovCount = [];
if ($tableReady) {
    foreach ($pdo->query("SELECT user_id, COUNT(*) c FROM user_permissions GROUP BY user_id")->fetchAll() as $r) { $ovCount[(int)$r['user_id']] = (int)$r['c']; }
}
$roleLabels = [];
foreach ($pdo->query("SELECT role_key, COALESCE(NULLIF(label_local,''), label) l FROM role_definitions")->fetchAll() as $r) { $roleLabels[$r['role_key']] = $r['l']; }

$pageTitle = 'व्यक्तीनुसार Access';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><a href="index.php" style="color:inherit;">पदे व्यवस्थापन</a> · Super Admin</div>
<div class="page-title mb-1">👤 व्यक्तीनुसार Access</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:12px;">पद न बदलता एका व्यक्तीला जास्तीचा अधिकार द्या, किंवा पदाचा एखादा अधिकार फक्त त्या व्यक्तीसाठी काढा — हवं तर ठराविक तारखेपर्यंत (उदा. रजेवरच्या HOD चं काम सांभाळणाऱ्यासाठी).</div>

<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>
<?php if (!$tableReady): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠ व्यक्तीनुसार अधिकारांसाठी Database Update बाकी आहे — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा.</div><?php endif; ?>

<div class="row g-3">
<div class="col-lg-4">
  <div class="panel ua-people">
    <div class="panel-head"><div class="title">व्यक्ती निवडा</div><div class="sub"><?= count($people) ?> जण</div></div>
    <div style="padding:10px 14px 4px;"><input type="search" id="uaFind" class="form-control-custom" placeholder="नाव / पद / विभाग शोधा…" autocomplete="off"></div>
    <div class="ua-list" id="uaList">
      <?php foreach ($people as $p): $n = $ovCount[(int)$p['id']] ?? 0; ?>
        <a href="?user_id=<?= (int)$p['id'] ?>" class="ua-row <?= $uid === (int)$p['id'] ? 'on' : '' ?>" style="<?= $p['is_active'] ? '' : 'opacity:.55;' ?>">
          <span class="av"><?= h(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
          <span class="tx"><b><?= h($p['name']) ?></b><small><?= h($roleLabels[$p['role']] ?? $p['role']) ?> · <?= h($p['dept'] ?: '—') ?></small></span>
          <?php if ($n): ?><span class="ua-badge" title="व्यक्तीनुसार अधिकार"><?= $n ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div class="col-lg-8">
<?php if (!$target): ?>
  <div class="panel"><div class="sm-empty" style="padding:50px 20px;">⬅️ डावीकडून व्यक्ती निवडा — मग त्यांना कोणते Modules दिसतात, कोणता Data दिसतो आणि का, ते सगळं इथे दिसेल.</div></div>
<?php else:
  $ax = accessExplainUser($pdo, $target);
  $isSA = $target['role'] === 'super_admin';
  $allPermissions = $pdo->query("SELECT * FROM permissions ORDER BY sort_order")->fetchAll();
  $allPermissions = array_values(array_filter($allPermissions, fn($p) => !in_array($p['permission_key'], roleRetiredPermissions(), true)));
  $groups = roleGroupedPermissions($allPermissions);
  $sensitive = array_flip(roleSensitivePermissions());
  $rolePermSet = [];
  $st = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?"); $st->execute([$target['role']]);
  $rolePermSet = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
  $okMods = array_filter($ax['modules'], fn($m) => $m[1]);
  $effective = $isSA ? count($allPermissions) : count(array_filter($ax['perms'], fn($s) => $s === 'role' || $s === 'grant'));
  $today = date('Y-m-d');
?>
  <div class="panel mb-3">
    <div class="ua-head">
      <span class="av lg"><?= h(mb_strtoupper(mb_substr($target['name'], 0, 1))) ?></span>
      <div style="flex:1;min-width:0;">
        <div style="font-size:17px;font-weight:700;"><?= h($target['name']) ?> <?= $target['is_active'] ? '' : '<span class="status-chip overdue">निष्क्रिय</span>' ?></div>
        <div style="font-size:12.5px;color:var(--text-dim);"><?= h($target['designation'] ?: '') ?> · <?= h($target['dept_name'] ?: '—') ?> · <?= h($target['campus_name'] ?: '—') ?></div>
      </div>
      <a href="../staff/profile.php?id=<?= (int)$target['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;">प्रोफाइल</a>
    </div>
    <div class="ua-facts">
      <div><small>पद</small><b><a href="edit.php?role_key=<?= urlencode($target['role']) ?>"><?= h($roleLabels[$target['role']] ?? $target['role']) ?></a></b><span><?= h(ucfirst(str_replace('_', ' ', $ax['category']))) ?></span></div>
      <div><small>Data किती दिसतो</small><b><?= h($ax['scope']) ?></b></div>
      <div><small>Modules</small><b><?= count($okMods) ?> / <?= count($ax['modules']) ?></b><span><?= $ax['restricted'] !== null ? '🎯 Restricted पद' : 'Sidebar मध्ये दिसणारे' ?></span></div>
      <div><small>अधिकार</small><b><?= $effective ?> / <?= count($allPermissions) ?></b><span><?= count($ax['overrides']) ? count($ax['overrides']) . ' व्यक्तीनुसार' : 'फक्त पदाचे' ?></span></div>
    </div>
    <div class="ua-extra">
      <span>🏢 जास्तीचे विभाग: <b><?= $ax['depts'] ? h(implode(', ', $ax['depts'])) : '—' ?></b> <a href="../staff/extra-access.php?user_id=<?= (int)$target['id'] ?>">बदला</a></span>
      <span>📍 जास्तीचे Campus: <b><?= $ax['campuses'] ? h(implode(', ', $ax['campuses'])) : '—' ?></b> <a href="../staff/extra-campus-access.php?user_id=<?= (int)$target['id'] ?>">बदला</a></span>
    </div>
  </div>

  <div class="panel mb-3">
    <div class="panel-head"><div class="title">🧭 Modules — दिसतात का आणि का?</div><div class="sub">Sidebar व थेट URL — दोन्हीला हेच नियम</div></div>
    <div class="ua-mods">
      <?php foreach ($ax['modules'] as $k => [$label, $ok, $why]): ?>
        <div class="ua-mod <?= $ok ? 'ok' : 'no' ?>" title="<?= h($why) ?>"><span class="ic"><?= $ok ? '✓' : '✕' ?></span><span><b><?= h($label) ?></b><small><?= h($why) ?></small></span></div>
      <?php endforeach; ?>
    </div>
  </div>

  <?php if ($isSA): ?>
    <div class="panel"><div class="sm-empty">Super Admin ला सगळे अधिकार आपोआप असतात — व्यक्तीनुसार बदल लागू होत नाहीत.</div></div>
  <?php else: ?>
  <form method="POST" class="panel" id="uaForm">
    <input type="hidden" name="user_id" value="<?= (int)$target['id'] ?>">
    <div class="panel-head"><div class="title">🔐 अधिकार — पदाचे + व्यक्तीनुसार</div><div class="sub">"पदाप्रमाणे" = पद जे ठरवतं तेच · <span style="color:var(--teal,#1B8A83);">द्या</span> = फक्त यांना जास्तीचा · <span style="color:var(--rose);">काढा</span> = पदाला असला तरी यांना नाही</div></div>
    <div class="ua-tools">
      <input type="search" id="uaPermFind" class="form-control-custom" placeholder="अधिकार शोधा…" style="max-width:260px;">
      <label style="font-size:12px;display:flex;align-items:center;gap:6px;"><input type="checkbox" id="uaOnlyChanged"> फक्त व्यक्तीनुसार बदललेले</label>
      <span style="font-size:12px;color:var(--text-dim);margin-left:auto;">सगळ्यांना एकच मुदत: <input type="date" id="uaBulkExp" class="form-control-custom" style="display:inline-block;width:auto;padding:4px 8px;font-size:12px;"></span>
    </div>
    <?php foreach ($groups as $g): ?>
      <div class="ua-group">
        <div class="ua-ghead"><?= h($g['label']) ?></div>
        <?php foreach ($g['perms'] as $p):
          $pk = $p['permission_key']; $ov = $ax['overrides'][$pk] ?? null; $mode = $ov['mode'] ?? '';
          $fromRole = isset($rolePermSet[$pk]); $expired = $ov && $ov['expires_on'] && $ov['expires_on'] < $today;
          $eff = $ov && !$expired ? $mode === 'grant' : $fromRole; ?>
          <div class="ua-perm <?= $eff ? 'eff' : '' ?> <?= $ov ? 'ov' : '' ?>" data-k="<?= h($pk) ?>" data-role="<?= $fromRole ? 1 : 0 ?>">
            <div class="nm">
              <span class="dot"></span>
              <div><?= isset($sensitive[$pk]) ? '<span style="color:var(--amber);" title="संवेदनशील">⚠</span> ' : '' ?><?= h($p['label_local'] ?: $p['label']) ?>
                <small><?= h($pk) ?> · पदाला: <?= $fromRole ? '<b style="color:var(--teal,#1B8A83);">आहे</b>' : 'नाही' ?>
                <?php if ($ov): ?> · <?= h($ov['granted_by_name'] ?: '') ?> <?= h(date('d/m/Y', strtotime($ov['created_at']))) ?><?php endif; ?>
                <?php if ($expired): ?> · <b style="color:var(--rose);">मुदत संपली (<?= h(date('d/m/Y', strtotime($ov['expires_on']))) ?>)</b><?php endif; ?></small>
              </div>
            </div>
            <div class="ua-seg" role="radiogroup">
              <label><input type="radio" name="mode[<?= h($pk) ?>]" value="" <?= $mode === '' ? 'checked' : '' ?>><span>पदाप्रमाणे</span></label>
              <label class="g"><input type="radio" name="mode[<?= h($pk) ?>]" value="grant" <?= $mode === 'grant' ? 'checked' : '' ?> <?= $fromRole && $mode !== 'grant' ? 'disabled title="पदालाच आहे"' : '' ?>><span>द्या</span></label>
              <label class="d"><input type="radio" name="mode[<?= h($pk) ?>]" value="deny" <?= $mode === 'deny' ? 'checked' : '' ?> <?= !$fromRole && $mode !== 'deny' ? 'disabled title="पदालाच नाही"' : '' ?>><span>काढा</span></label>
            </div>
            <div class="ua-more" <?= $mode === '' ? 'hidden' : '' ?>>
              <label>मुदत (पर्यंत) <input type="date" name="expires_on[<?= h($pk) ?>]" value="<?= h($ov['expires_on'] ?? '') ?>" min="<?= $today ?>" class="form-control-custom"></label>
              <label style="flex:1;">कारण <input type="text" name="note[<?= h($pk) ?>]" value="<?= h($ov['note'] ?? '') ?>" maxlength="255" class="form-control-custom" placeholder="उदा. HOD रजेवर — तात्पुरता चार्ज"></label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <div class="ua-save">
      <span id="uaDiff" style="font-size:12px;color:var(--text-dim);"></span>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 22px;font-weight:600;" <?= $tableReady ? '' : 'disabled' ?>>जतन करा</button>
    </div>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
</div>

<style>
  .ua-list { max-height: 70vh; overflow: auto; padding: 4px 8px 10px; }
  .ua-row { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 10px; color: inherit; text-decoration: none; }
  .ua-row:hover { background: var(--canvas); } .ua-row.on { background: color-mix(in srgb,var(--rail) 10%,transparent); }
  .av { width: 32px; height: 32px; border-radius: 9px; background: var(--rail); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
  .av.lg { width: 46px; height: 46px; font-size: 19px; border-radius: 12px; }
  .ua-row .tx { flex: 1; min-width: 0; } .ua-row b { display: block; font-size: 13px; } .ua-row small { font-size: 11px; color: var(--text-dim); display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .ua-badge { background: var(--amber); color: #fff; font-size: 10.5px; font-weight: 700; border-radius: 999px; padding: 1px 7px; }
  .ua-head { display: flex; gap: 12px; align-items: center; padding: 16px 18px 10px; }
  .ua-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; padding: 4px 18px 12px; }
  .ua-facts > div { background: var(--canvas); border-radius: 10px; padding: 10px 12px; }
  .ua-facts small { display: block; font-size: 11px; color: var(--text-dim); } .ua-facts b { display: block; font-size: 13.5px; } .ua-facts span { font-size: 11px; color: var(--text-dim); }
  .ua-facts a { color: var(--rail); text-decoration: none; }
  .ua-extra { display: flex; flex-wrap: wrap; gap: 6px 18px; font-size: 12px; padding: 0 18px 14px; } .ua-extra a { color: var(--rail); margin-left: 4px; }
  .ua-mods { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; padding: 12px 16px 16px; }
  .ua-mod { display: flex; gap: 8px; align-items: flex-start; border: 1px solid var(--line); border-radius: 10px; padding: 8px 10px; }
  .ua-mod .ic { width: 20px; height: 20px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 700; flex-shrink: 0; margin-top: 1px; }
  .ua-mod.ok .ic { background: color-mix(in srgb,#1B8A83 15%,transparent); color: #1B8A83; } .ua-mod.no { opacity: .65; } .ua-mod.no .ic { background: color-mix(in srgb,#B23A48 12%,transparent); color: #B23A48; }
  .ua-mod b { display: block; font-size: 12.5px; } .ua-mod small { display: block; font-size: 10.5px; color: var(--text-dim); line-height: 1.35; }
  .ua-tools { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; padding: 12px 18px 4px; }
  .ua-group { padding: 6px 18px; } .ua-ghead { font-size: 13px; font-weight: 700; padding: 8px 0 6px; border-bottom: 1px dashed var(--line); margin-bottom: 4px; }
  .ua-perm { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 12px; padding: 8px 6px; border-bottom: 1px solid var(--line); }
  .ua-perm .nm { flex: 1; min-width: 220px; display: flex; gap: 8px; font-size: 13px; }
  .ua-perm .nm small { display: block; font-size: 10.5px; color: var(--text-dim); }
  .ua-perm .dot { width: 9px; height: 9px; border-radius: 50%; background: var(--line); margin-top: 5px; flex-shrink: 0; }
  .ua-perm.eff .dot { background: #1B8A83; } .ua-perm.ov { background: color-mix(in srgb,var(--amber) 6%,transparent); }
  .ua-seg { display: inline-flex; border: 1px solid var(--line); border-radius: 9px; overflow: hidden; }
  .ua-seg label { margin: 0; cursor: pointer; } .ua-seg input { display: none; }
  .ua-seg span { display: block; padding: 5px 11px; font-size: 11.5px; color: var(--text-dim); }
  .ua-seg input:checked + span { background: var(--rail); color: #fff; }
  .ua-seg .g input:checked + span { background: #1B8A83; } .ua-seg .d input:checked + span { background: #B23A48; }
  .ua-seg input:disabled + span { opacity: .35; cursor: not-allowed; }
  .ua-more { flex-basis: 100%; display: flex; flex-wrap: wrap; gap: 8px; font-size: 11px; color: var(--text-dim); padding-left: 17px; }
  .ua-more label { display: flex; flex-direction: column; gap: 2px; } .ua-more input { font-size: 12px; padding: 5px 8px; }
  .ua-save { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 14px 18px; position: sticky; bottom: 0; background: var(--panel, #fff); border-top: 1px solid var(--line); }
  @media (max-width: 991px) { .ua-list { max-height: 260px; } }
</style>
<script>
(function () {
  var f = document.getElementById('uaFind');
  if (f) f.addEventListener('input', function () {
    var q = f.value.trim().toLowerCase();
    document.querySelectorAll('#uaList .ua-row').forEach(function (r) { r.style.display = !q || r.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
  });
  var on = document.querySelector('#uaList .ua-row.on'); if (on) on.scrollIntoView({ block: 'nearest' });
  var form = document.getElementById('uaForm'); if (!form) return;
  var orig = {};
  form.querySelectorAll('.ua-perm').forEach(function (p) { var c = p.querySelector('input[type=radio]:checked'); orig[p.dataset.k] = c ? c.value : ''; });
  function refresh() {
    var n = 0;
    form.querySelectorAll('.ua-perm').forEach(function (p) {
      var c = p.querySelector('input[type=radio]:checked'), v = c ? c.value : '';
      var role = p.dataset.role === '1';
      p.classList.toggle('eff', v === 'grant' || (v === '' && role));
      p.classList.toggle('ov', v !== '');
      p.querySelector('.ua-more').hidden = v === '';
      if (v !== orig[p.dataset.k]) n++;
    });
    document.getElementById('uaDiff').textContent = n ? 'जतन न केलेले बदल: ' + n : '';
  }
  form.addEventListener('change', refresh);
  document.getElementById('uaPermFind').addEventListener('input', filter);
  document.getElementById('uaOnlyChanged').addEventListener('change', filter);
  function filter() {
    var q = document.getElementById('uaPermFind').value.trim().toLowerCase(), only = document.getElementById('uaOnlyChanged').checked;
    form.querySelectorAll('.ua-group').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('.ua-perm').forEach(function (p) {
        var hit = (!q || p.textContent.toLowerCase().indexOf(q) !== -1) && (!only || p.classList.contains('ov'));
        p.style.display = hit ? '' : 'none'; if (hit) vis++;
      });
      g.style.display = vis ? '' : 'none';
    });
  }
  document.getElementById('uaBulkExp').addEventListener('change', function () {
    var v = this.value;
    form.querySelectorAll('.ua-perm.ov input[type=date]').forEach(function (d) { d.value = v; });
  });
  refresh();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
