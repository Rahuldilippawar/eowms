<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

$roleKey = $_GET['role_key'] ?? $_POST['role_key'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM role_definitions WHERE role_key = ?");
$stmt->execute([$roleKey]);
$role = $stmt->fetch();

if (!$role) { die(t('s535')); }

// System roles (super_admin/hod/staff) can't be renamed or have their base
// category changed, but their granular PERMISSIONS can still be tuned.
$isLocked = (bool)$role['is_system'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isLocked) {
        $label = trim($_POST['label'] ?? '');
        $labelLocal = trim($_POST['label_local'] ?? '') ?: null;
        $category = $_POST['category'] ?? 'staff';
        if (!in_array($category, ['management', 'hod', 'staff'], true)) { $category = 'staff'; }

        if (!$label) {
            $_SESSION['form_error'] = t('s439');
            header('Location: edit.php?role_key=' . urlencode($roleKey));
            exit;
        }

        $update = $pdo->prepare("UPDATE role_definitions SET label=?, label_local=?, category=? WHERE role_key=?");
        $update->execute([$label, $labelLocal, $category, $roleKey]);
    }

    // Save granular permissions regardless of lock status — फक्त अस्तित्वात असलेल्या Keys
    $validKeys = $pdo->query("SELECT permission_key FROM permissions")->fetchAll(PDO::FETCH_COLUMN);
    $selectedPerms = array_values(array_intersect(array_unique((array)($_POST['permissions'] ?? [])), $validKeys));
    $oldStmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?");
    $oldStmt->execute([$roleKey]);
    $oldPerms = $oldStmt->fetchAll(PDO::FETCH_COLUMN);
    $pdo->prepare("DELETE FROM role_permissions WHERE role_key = ?")->execute([$roleKey]);
    if (!empty($selectedPerms)) {
        $insert = $pdo->prepare("INSERT INTO role_permissions (role_key, permission_key) VALUES (?, ?)");
        foreach ($selectedPerms as $permKey) {
            $insert->execute([$roleKey, $permKey]);
        }
    }
    // कोणते अधिकार दिले / काढले — Activity Log मध्ये नोंद
    $added = array_diff($selectedPerms, $oldPerms);
    $removed = array_diff($oldPerms, $selectedPerms);
    if ($added || $removed) {
        logActivity('पदाचे Permissions बदलले', 'role', null, $roleKey
            . ($added ? ' · दिले: ' . implode(', ', $added) : '')
            . ($removed ? ' · काढले: ' . implode(', ', $removed) : ''));
    }

    // 🎯 Single-Purpose Access Mode — super_admin Role वर हे कधीच लावता
    // येणार नाही (Safety — चुकून स्वतःचाच Access मर्यादित होऊ नये).
    if ($roleKey !== 'super_admin') {
        $restrictedMode = isset($_POST['restricted_mode']) ? 1 : 0;
        $restrictedModulesSelected = $_POST['restricted_modules'] ?? [];
        $restrictedModulesStr = $restrictedMode && !empty($restrictedModulesSelected) ? implode(',', $restrictedModulesSelected) : null;
        if ($restrictedMode && !$restrictedModulesStr) { $restrictedMode = 0; } // Module निवडलंच नसेल तर Restricted-Mode निरर्थक
        $pdo->prepare("UPDATE role_definitions SET restricted_mode = ?, restricted_modules = ? WHERE role_key = ?")
            ->execute([$restrictedMode, $restrictedModulesStr, $roleKey]);
    }

    $_SESSION['flash'] = "'" . ($role['label_local'] ?: $role['label']) . "' अद्ययावत केले.";
    header('Location: index.php');
    exit;
}

$allPermissions = $pdo->query("SELECT * FROM permissions ORDER BY sort_order")->fetchAll();
$grantedStmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?");
$grantedStmt->execute([$roleKey]);
$grantedPerms = $grantedStmt->fetchAll(PDO::FETCH_COLUMN);

require_once __DIR__ . '/../includes/role-permission-groups.php';
$permGroups = roleGroupedPermissions($allPermissions);
$sensitive = array_flip(roleSensitivePermissions());
// "दुसऱ्या पदाप्रमाणे भरा" — इतर पदांचे Permissions (JS साठी)
$otherRoles = $pdo->prepare("SELECT role_key, label, label_local, category FROM role_definitions WHERE role_key NOT IN (?, 'super_admin') ORDER BY FIELD(category,'management','hod','staff'), label");
$otherRoles->execute([$roleKey]);
$otherRoles = $otherRoles->fetchAll();
$permsByRole = [];
foreach ($pdo->query("SELECT role_key, permission_key FROM role_permissions")->fetchAll() as $rp) { $permsByRole[$rp['role_key']][] = $rp['permission_key']; }
// या पदावरील व्यक्ती
$holdersStmt = $pdo->prepare("SELECT u.id, u.name, u.is_active, d.name_local AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.role = ? ORDER BY u.is_active DESC, u.name LIMIT 200");
$holdersStmt->execute([$roleKey]);
$holders = $holdersStmt->fetchAll();

$pageTitle = t('s536');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h($role['label_local'] ?: $role['label']) ?> <?= h(t('s11')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="role_key" value="<?= h($role['role_key']) ?>">

<div class="panel mb-3">
  <div class="panel-head"><div class="title"><?= h(t('s107')) ?></div><?php if ($isLocked): ?><div class="sub"><?= h(t('s339')) ?></div><?php endif; ?></div>
  <div style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s108')) ?></label>
        <input type="text" name="label" class="form-control-custom" value="<?= h($role['label']) ?>" <?= $isLocked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s109')) ?></label>
        <input type="text" name="label_local" class="form-control-custom" value="<?= h($role['label_local']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s340')) ?></label>
        <input type="text" class="form-control-custom" value="<?= h($role['role_key']) ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s341')) ?></label>
        <select name="category" class="form-select-custom" <?= $isLocked ? 'disabled' : 'required' ?>>
          <option value="management" <?= $role['category'] === 'management' ? 'selected' : '' ?>>Management</option>
          <option value="hod" <?= $role['category'] === 'hod' ? 'selected' : '' ?>>HOD</option>
          <option value="staff" <?= $role['category'] === 'staff' ? 'selected' : '' ?>>Staff</option>
        </select>
      </div>
    </div>
  </div>
</div>

<?php if ($roleKey !== 'super_admin'): ?>
<?php $moduleRegistryForRole = getModuleRegistry(); $currentRestrictedModules = !empty($role['restricted_modules']) ? explode(',', $role['restricted_modules']) : []; ?>
<div class="panel mb-3" style="border:1px solid #F0D9B5;">
  <div class="panel-head" style="background:#FBEBD6;">
    <div class="title">🎯 Single-Purpose Access Mode</div>
    <div class="sub">फक्त काही Narrow-Purpose Roles साठीच वापरा (उदा. "Lab Assistant")</div>
  </div>
  <div style="padding:18px 24px;">
    <div style="background:#F7E2E3;border-radius:9px;padding:12px 16px;font-size:12px;color:#8A1F2B;margin-bottom:16px;">
      ⚠️ <strong>सावधान:</strong> हे चालू केल्यास — या Role च्या व्यक्तीला Login केल्यावर **फक्त खाली निवडलेले Module** दिसतील — Command Center, इतर काहीही नाही (URL टाकूनही जाता येणार नाही). <strong>दैनंदिन कामाच्या नोंदी, नूतनीकरण, पत्रव्यवहार सारख्या Normal, रोजच्या-वापरातल्या Role साठी हे कधीच चालू करू नका</strong> — फक्त एकाच, ठराविक कामासाठी नेमलेल्या व्यक्तीसाठी (उदा. Lab Assistant, Security Guard) वापरा.
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;margin-bottom:14px;">
      <input type="checkbox" name="restricted_mode" id="restrictedModeToggle" value="1" <?= $role['restricted_mode'] ? 'checked' : '' ?> style="width:17px;height:17px;" onchange="document.getElementById('restrictedModulesPicker').style.display = this.checked ? 'block' : 'none';">
      हा Role — Single-Purpose (Restricted) ठेवा
    </label>
    <div id="restrictedModulesPicker" style="display:<?= $role['restricted_mode'] ? 'block' : 'none' ?>;background:var(--canvas);border-radius:10px;padding:14px 16px;">
      <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:10px;">कोणकोणते Module दिसावेत (एकापेक्षा जास्त निवडता येतील):</div>
      <div class="row g-2">
        <?php foreach ($moduleRegistryForRole as $modKey => $modInfo): ?>
          <div class="col-md-4">
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;">
              <input type="checkbox" name="restricted_modules[]" value="<?= h($modKey) ?>" <?= in_array($modKey, $currentRestrictedModules, true) ? 'checked' : '' ?> style="width:15px;height:15px;">
              <i class="<?= h($modInfo['icon']) ?>" style="color:<?= h($modInfo['color']) ?>;width:16px;"></i> <?= h($modInfo['label']) ?>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head">
    <div class="title"><?= h(t('s342')) ?> <span class="rp-count" id="rpCount"></span></div>
    <div class="sub"><?= h(t('s343')) ?> · <span style="color:var(--amber);">⚠ = संवेदनशील (संपूर्ण संस्था / पैसा / रचना)</span></div>
  </div>
  <?php if ($roleKey === 'super_admin'): ?>
    <div style="padding:14px 24px 0;font-size:12.5px;color:var(--rail);font-weight:600;">Super Admin ला सर्व अधिकार आपोआप असतात — इथले बदल फक्त नोंदीसाठी.</div>
  <?php endif; ?>
  <div class="rp-tools">
    <div class="rp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="rpSearch" placeholder="अधिकार शोधा…" class="form-control-custom"></div>
    <select id="rpCopy" class="form-select-custom" style="max-width:260px;">
      <option value="">📋 दुसऱ्या पदाप्रमाणे भरा…</option>
      <?php foreach ($otherRoles as $or): ?><option value="<?= h($or['role_key']) ?>"><?= h($or['label_local'] ?: $or['label']) ?> (<?= count($permsByRole[$or['role_key']] ?? []) ?>)</option><?php endforeach; ?>
    </select>
    <button type="button" class="btn btn-sm" id="rpAll" style="border:1px solid var(--line);border-radius:8px;font-size:12px;">सर्व निवडा</button>
    <button type="button" class="btn btn-sm" id="rpNone" style="border:1px solid var(--line);border-radius:8px;font-size:12px;">सर्व काढा</button>
    <button type="button" class="btn btn-sm" id="rpReset" style="border:1px solid var(--line);border-radius:8px;font-size:12px;" title="जतन केलेल्या स्थितीत परत"><i class="fa-solid fa-rotate-left"></i></button>
  </div>
  <div style="padding:6px 24px 18px;">
    <?php foreach ($permGroups as $gk => $g): ?>
      <div class="rp-group" data-group="<?= h($gk) ?>">
        <div class="rp-ghead">
          <span><?= h($g['label']) ?> <small class="rp-gcount"></small></span>
          <button type="button" class="rp-gtoggle">सर्व</button>
        </div>
        <div class="row g-2">
          <?php foreach ($g['perms'] as $perm): $pk = $perm['permission_key']; $on = in_array($pk, $grantedPerms, true); ?>
            <div class="col-md-6 rp-item">
              <label class="rp-label <?= $on ? 'on' : '' ?>">
                <input type="checkbox" name="permissions[]" value="<?= h($pk) ?>" <?= $on ? 'checked' : '' ?> data-orig="<?= $on ? 1 : 0 ?>">
                <span>
                  <?= isset($sensitive[$pk]) ? '<span title="संवेदनशील" style="color:var(--amber);">⚠</span> ' : '' ?><?= h($perm['label_local'] ?: $perm['label']) ?>
                  <div style="font-size:10.5px;color:var(--text-dim);"><?= h($perm['label']) ?></div>
                </span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>
    <div class="sm-empty" id="rpNoHit" hidden>जुळणारा अधिकार नाही</div>

    <div class="d-flex justify-content-between align-items-center gap-2 mt-3 flex-wrap">
      <span id="rpDiff" style="font-size:12px;color:var(--text-dim);"></span>
      <div class="d-flex gap-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </div>
</div>
</form>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">👥 या पदावरील व्यक्ती (<?= count($holders) ?>)</div><div class="sub">वरचे बदल यांच्या सगळ्यांच्या प्रवेशावर लगेच लागू होतात · नावावर Click = त्या व्यक्तीचा Access / व्यक्तीनुसार अधिकार</div></div>
  <?php if (!$holders): ?><div class="sm-empty">हे पद अजून कोणालाही दिलेलं नाही</div><?php else: ?>
  <div class="rp-holders">
    <?php foreach ($holders as $hd): ?>
      <a href="user-access.php?user_id=<?= (int)$hd['id'] ?>" title="या व्यक्तीचा Access पाहा / बदला" class="rp-holder" style="<?= $hd['is_active'] ? '' : 'opacity:.55;' ?>">
        <span class="av"><?= h(mb_strtoupper(mb_substr($hd['name'], 0, 1))) ?></span>
        <span><b><?= h($hd['name']) ?></b><small><?= h($hd['dept'] ?: '—') ?><?= $hd['is_active'] ? '' : ' · निष्क्रिय' ?></small></span>
      </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<style>
  .rp-count { font-size: 12px; font-weight: 600; color: var(--rail); background: color-mix(in srgb,var(--rail) 10%,transparent); border-radius: 999px; padding: 2px 10px; margin-left: 6px; }
  .rp-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 14px 24px 6px; }
  .rp-search { position: relative; flex: 1; min-width: 200px; }
  .rp-search i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: var(--text-dim); font-size: 12px; }
  .rp-search input { padding-left: 32px; }
  .rp-group { margin-top: 14px; }
  .rp-ghead { display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 700; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px dashed var(--line); }
  .rp-ghead small { font-weight: 500; color: var(--text-dim); margin-left: 4px; }
  .rp-gtoggle { border: 1px solid var(--line); background: transparent; border-radius: 999px; font-size: 11px; padding: 2px 10px; color: var(--text-dim); cursor: pointer; }
  .rp-gtoggle:hover { border-color: var(--rail); color: var(--rail); }
  .rp-label { display: flex; align-items: center; gap: 10px; font-size: 13px; background: var(--canvas); padding: 10px 14px; border-radius: 9px; margin-bottom: 2px; cursor: pointer; border: 1px solid transparent; transition: border-color .15s, background .15s; }
  .rp-label.on { border-color: color-mix(in srgb,var(--rail) 45%,transparent); background: color-mix(in srgb,var(--rail) 7%,transparent); }
  .rp-label.changed { box-shadow: inset 3px 0 0 var(--amber); }
  .rp-holders { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 8px; padding: 14px 20px; }
  .rp-holder { display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--line); border-radius: 10px; text-decoration: none; color: inherit; }
  .rp-holder:hover { border-color: var(--rail); }
  .rp-holder .av { width: 32px; height: 32px; border-radius: 9px; background: var(--rail); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
  .rp-holder b { display: block; font-size: 13px; } .rp-holder small { font-size: 11px; color: var(--text-dim); }
</style>
<script>
(function () {
  var PERMS = <?= json_encode($permsByRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var boxes = Array.prototype.slice.call(document.querySelectorAll('input[name="permissions[]"]'));
  var countEl = document.getElementById('rpCount'), diffEl = document.getElementById('rpDiff');
  function fire(b) { b.dispatchEvent(new Event('change', { bubbles: true })); }
  function refresh() {
    var on = 0, add = 0, rem = 0;
    boxes.forEach(function (b) {
      if (b.checked) on++;
      var orig = b.getAttribute('data-orig') === '1';
      if (b.checked && !orig) add++;
      if (!b.checked && orig) rem++;
      var l = b.closest('.rp-label');
      l.classList.toggle('on', b.checked);
      l.classList.toggle('changed', b.checked !== orig);
    });
    countEl.textContent = on + ' / ' + boxes.length;
    diffEl.textContent = (add || rem) ? 'जतन न केलेले बदल: +' + add + ' दिले · −' + rem + ' काढले' : '';
    document.querySelectorAll('.rp-group').forEach(function (g) {
      var bs = g.querySelectorAll('input[type=checkbox]'), n = 0;
      bs.forEach(function (b) { if (b.checked) n++; });
      g.querySelector('.rp-gcount').textContent = '(' + n + '/' + bs.length + ')';
      g.querySelector('.rp-gtoggle').textContent = n === bs.length ? 'सर्व काढा' : 'सर्व निवडा';
    });
  }
  boxes.forEach(function (b) { b.addEventListener('change', refresh); });
  document.querySelectorAll('.rp-group').forEach(function (g) {
    g.querySelector('.rp-gtoggle').addEventListener('click', function () {
      var bs = Array.prototype.slice.call(g.querySelectorAll('input[type=checkbox]'));
      var all = bs.every(function (b) { return b.checked; });
      bs.forEach(function (b) { b.checked = !all; fire(b); });
    });
  });
  document.getElementById('rpAll').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = true; fire(b); }); });
  document.getElementById('rpNone').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = false; fire(b); }); });
  document.getElementById('rpReset').addEventListener('click', function () { boxes.forEach(function (b) { b.checked = b.getAttribute('data-orig') === '1'; fire(b); }); });
  document.getElementById('rpCopy').addEventListener('change', function () {
    var k = this.value; if (!k) return;
    var set = PERMS[k] || [];
    if (confirm('सध्याची निवड बदलून "' + this.options[this.selectedIndex].text + '" प्रमाणे Permissions भरायचे? (जतन करेपर्यंत काहीही बदलत नाही)')) {
      boxes.forEach(function (b) { b.checked = set.indexOf(b.value) !== -1; fire(b); });
    }
    this.value = '';
  });
  var search = document.getElementById('rpSearch'), noHit = document.getElementById('rpNoHit');
  search.addEventListener('input', function () {
    var q = search.value.trim().toLowerCase(), any = false;
    document.querySelectorAll('.rp-group').forEach(function (g) {
      var vis = 0;
      g.querySelectorAll('.rp-item').forEach(function (it) {
        var hit = !q || it.textContent.toLowerCase().indexOf(q) !== -1 || it.querySelector('input').value.indexOf(q) !== -1;
        it.style.display = hit ? '' : 'none'; if (hit) vis++;
      });
      g.style.display = vis ? '' : 'none'; if (vis) any = true;
    });
    noHit.hidden = any;
  });
  refresh();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
