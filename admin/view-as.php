<?php
// 👁️ "या व्यक्तीच्या नजरेतून पाहा" — Super Admin, फक्त पाहणं (Read-only). कर्मचारी म्हणतो "मला हे दिसत नाही" तेव्हा थेट तपासा.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');

// थांबवणं — View as चालू असताना (त्या वेळी Login "दुसऱ्या व्यक्तीचं" असतं, म्हणून Role तपासणीआधी)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'stop') {
    $back = saViewingAs() ? 'view-as.php' : '../index.php';
    saViewAsStop();
    $_SESSION['flash'] = '✅ View as बंद — तुम्ही परत Super Admin म्हणून आहात.';
    header('Location: ' . $back);
    exit;
}
requireRole(['super_admin'], '../');
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'start') {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ?");
    $st->execute([(int)($_POST['user_id'] ?? 0), $orgId]);
    $t = $st->fetch();
    if ($t && saViewAsStart($pdo, $t)) {
        header('Location: ../index.php');
        exit;
    }
    $_SESSION['flash'] = '⚠️ या व्यक्तीच्या नजरेतून पाहता येत नाही (Super Admin / निष्क्रिय / दुसरी संस्था).';
    header('Location: view-as.php');
    exit;
}

$people = $pdo->prepare("SELECT u.id, u.name, u.role, u.designation, u.last_login_at, d.name_local dept, COALESCE(NULLIF(rd.label_local,''), rd.label, u.role) role_label
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN role_definitions rd ON rd.role_key = u.role
    WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin' ORDER BY u.name");
$people->execute([$orgId]);
$people = $people->fetchAll();
$recent = [];
try {
    $r = $pdo->prepare("SELECT entity_id, MAX(created_at) at FROM activity_logs WHERE organization_id = ? AND action LIKE 'View as सुरू%' GROUP BY entity_id ORDER BY at DESC LIMIT 6");
    $r->execute([$orgId]);
    $recent = $r->fetchAll(PDO::FETCH_KEY_PAIR);
} catch (PDOException $e) {}

$pageTitle = 'View as';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">👁️ या व्यक्तीच्या नजरेतून पाहा</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">कर्मचाऱ्याला जे दिसतं तेच तुम्हाला दिसेल — Sidebar, Dashboard, Modules, Data. <b>फक्त पाहणं:</b> या वेळी कोणताही Form जतन होत नाही, संदेश/सूचना "वाचलं" होत नाहीत. प्रत्येक वेळेची नोंद Activity Log मध्ये होते.</div>
<?= saNav('view-as') ?>

<div class="panel">
  <div style="padding:12px 16px 4px;"><input type="search" id="vaFind" class="form-control-custom" placeholder="नाव / पद / विभाग शोधा…" autocomplete="off"></div>
  <div class="va-grid" id="vaList">
    <?php foreach ($people as $p): $was = $recent[$p['id']] ?? null; ?>
      <form method="POST" class="va-card">
        <input type="hidden" name="action" value="start"><input type="hidden" name="user_id" value="<?= (int)$p['id'] ?>">
        <span class="av"><?= h(mb_strtoupper(mb_substr($p['name'], 0, 1))) ?></span>
        <span class="tx"><b><?= h($p['name']) ?></b><small><?= h($p['role_label']) ?> · <?= h($p['dept'] ?: '—') ?></small>
          <small style="color:var(--text-dim);"><?= $p['last_login_at'] ? 'शेवटचं Login: ' . date('d/m, h:i A', strtotime($p['last_login_at'])) : 'अजून Login नाही' ?><?= $was ? ' · तुम्ही पाहिलं ' . date('d/m', strtotime($was)) : '' ?></small></span>
        <button type="submit" title="<?= h($p['name']) ?> च्या नजरेतून पाहा">👁️ पाहा</button>
      </form>
    <?php endforeach; ?>
  </div>
</div>
<style>
  .va-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 8px; padding: 10px 16px 16px; }
  .va-card { display: flex; align-items: center; gap: 10px; border: 1px solid var(--line); border-radius: 12px; padding: 9px 10px; margin: 0; }
  .va-card .av { width: 34px; height: 34px; border-radius: 10px; background: var(--rail); color: #fff; display: flex; align-items: center; justify-content: center; font-weight: 700; flex-shrink: 0; }
  .va-card .tx { flex: 1; min-width: 0; } .va-card b { display: block; font-size: 13px; } .va-card small { display: block; font-size: 11px; color: var(--text-dim); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .va-card button { border: 1px solid var(--line); background: transparent; border-radius: 8px; font-size: 12px; padding: 5px 10px; cursor: pointer; white-space: nowrap; }
  .va-card button:hover { border-color: var(--rail); color: var(--rail); }
</style>
<script>
(function () {
  var f = document.getElementById('vaFind');
  f.addEventListener('input', function () { var q = f.value.trim().toLowerCase(); document.querySelectorAll('#vaList .va-card').forEach(function (c) { c.style.display = !q || c.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; }); });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
