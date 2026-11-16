<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $labName = trim($_POST['lab_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;
    $assistantId = ($_POST['assistant_id'] ?? '') !== '' ? (int)$_POST['assistant_id'] : null;

    // Assistant याच संस्थेचा सक्रिय कर्मचारी असावा
    if ($assistantId) {
        $asstStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
        $asstStmt->execute([$assistantId, $orgId]);
        if (!$asstStmt->fetch()) {
            $_SESSION['flash'] = 'निवडलेला Assistant सापडला नाही — Lab जोडली नाही.';
            header('Location: labs.php');
            exit;
        }
    }

    if ($labName) {
        $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' LIMIT 1");
        $deptStmt->execute([$orgId]);
        $deptId = $deptStmt->fetch()['id'] ?? null;

        $pdo->prepare("INSERT INTO it_labs (organization_id, department_id, lab_name, location, capacity, assistant_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $deptId, $labName, $location, $capacity, $assistantId]);
        logActivity('IT Lab जोडली', 'it_lab', $pdo->lastInsertId(), $labName);
        $_SESSION['flash'] = "'$labName' Lab जोडली गेली.";
    }
    header('Location: labs.php');
    exit;
}

$pageTitle = 'IT Labs';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

require_once __DIR__ . '/../includes/it-lab-helpers.php';
$chkReady = itLabChecksReady($pdo);
// प्रत्येक Lab — Machines, चालू, बिघाड, Internet अडचण, आज तपासलेल्या
$mSql = "a.lab_id = l.id AND a.asset_type IN ('computer','cpu') AND a.status <> 'scrapped'";
$labsStmt = $pdo->prepare("
    SELECT l.*, u.name AS assistant_name, (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id) AS asset_count,
      (SELECT COUNT(*) FROM it_assets a WHERE $mSql) AS m_count,
      (SELECT COUNT(*) FROM it_assets a WHERE $mSql AND a.power_status = 'on') AS power_on_count"
    . ($chkReady ? ",
      (SELECT COUNT(*) FROM it_assets a WHERE $mSql AND (a.status IN ('faulty','under_repair') OR 'fault' IN (a.screen_status, a.cpu_status, a.keyboard_status, a.mouse_status) OR a.internet_status = 'down')) AS fault_count,
      (SELECT COUNT(*) FROM it_assets a WHERE $mSql AND a.internet_status IN ('slow','down')) AS net_count,
      (SELECT COUNT(*) FROM it_assets a WHERE $mSql AND a.last_check_at >= CURDATE()) AS today_count" : ", 0 AS fault_count, 0 AS net_count, 0 AS today_count") . "
    FROM it_labs l LEFT JOIN users u ON u.id = l.assistant_id
    WHERE l.organization_id = ? ORDER BY l.lab_name
");
$labsStmt->execute([$orgId]);
$labs = $labsStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$staffPool = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏫 IT Labs</div>
  <div class="d-flex gap-2">
    <a href="import.php" style="font-size:12px;color:var(--amber);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-file-import me-1"></i>Lab/Computer Import करा</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT विभाग</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<style>
  .lc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;margin-bottom:14px;}
  .lc{display:block;background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px;text-decoration:none;color:inherit;transition:transform .12s, box-shadow .12s;}
  .lc:hover{transform:translateY(-2px);box-shadow:0 10px 24px -14px rgba(0,0,0,.35);color:inherit;}
  .lc .t{display:flex;justify-content:space-between;align-items:center;gap:8px;}
  .lc .t b{font-size:15px;}
  .lc .s{font-size:11.5px;color:var(--text-dim);margin-top:2px;}
  .lc .bar{display:flex;height:8px;border-radius:8px;overflow:hidden;background:var(--canvas);margin:12px 0 8px;}
  .lc .bar i{display:block;height:100%;}
  .lc .nums{display:flex;gap:10px;flex-wrap:wrap;font-size:12px;}
  .lc .nums span b{font-weight:800;}
  .lc .go{font-size:12px;font-weight:700;color:var(--rail);margin-top:10px;}
  .lc .hc{font-size:11px;font-weight:700;border-radius:999px;padding:2px 9px;white-space:nowrap;}
  .lb-addlab > summary{list-style:none;cursor:pointer;}
  .lb-addlab > summary::-webkit-details-marker{display:none;}
</style>

<?php if ($labs): ?>
<div class="lc-grid">
  <?php foreach ($labs as $l):
    $n = (int)$l['m_count']; $f = (int)$l['fault_count']; $td = (int)$l['today_count']; $on = (int)$l['power_on_count'];
    $okN = max(0, $n - $f);
    $hc = !$n ? ['var(--canvas)', 'var(--text-dim)', 'Machines नाहीत'] : ($f ? ['#FEE2E2', '#B91C1C', '⚠️ ' . $f . ' बिघाड'] : ['#DCFCE7', '#15803D', '✓ सगळं ठीक']);
  ?>
    <a class="lc" href="lab.php?id=<?= (int)$l['id'] ?>">
      <div class="t"><b>🖥️ <?= h($l['lab_name']) ?></b><span class="hc" style="background:<?= $hc[0] ?>;color:<?= $hc[1] ?>;"><?= $hc[2] ?></span></div>
      <div class="s"><?= $l['location'] ? '📍 ' . h($l['location']) . ' · ' : '' ?>👤 <?= h($l['assistant_name'] ?? 'Assistant नाही') ?></div>
      <?php if ($n): ?>
        <div class="bar" title="ठीक / बिघाड"><i style="width:<?= round($okN * 100 / $n) ?>%;background:#22C55E;"></i><i style="width:<?= round($f * 100 / $n) ?>%;background:#EF4444;"></i></div>
        <div class="nums">
          <span>💻 <b><?= $n ?></b></span><span>⚡ <b><?= $on ?></b> चालू</span>
          <?php if ((int)$l['net_count']): ?><span style="color:#B45309;">🌐 <b><?= (int)$l['net_count'] ?></b></span><?php endif; ?>
          <span style="color:<?= $td >= $n ? '#15803D' : '#B45309' ?>;">📋 आज <b><?= $td ?>/<?= $n ?></b></span>
        </div>
      <?php else: ?>
        <div class="s" style="margin-top:10px;">अजून Computer जोडलेले नाहीत<?= (int)$l['asset_count'] ? ' · इतर Assets ' . (int)$l['asset_count'] : '' ?></div>
      <?php endif; ?>
      <div class="go">Lab उघडा →</div>
    </a>
  <?php endforeach; ?>
</div>
<?php else: ?>
  <div class="panel mb-3" style="padding:28px;text-align:center;color:var(--text-dim);">अजून कोणतीही Lab नाही — खालून जोडा.</div>
<?php endif; ?>

<details class="panel mb-3 lb-addlab" <?= !$labs ? 'open' : '' ?>>
  <summary class="panel-head"><div class="title">➕ नवीन Lab जोडा</div></summary>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">Lab चं नाव</label><input type="text" name="lab_name" class="form-control-custom" placeholder="उदा. Computer Lab 1" required></div>
      <div class="col-md-3"><label class="form-label-custom">ठिकाण</label><input type="text" name="location" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">क्षमता</label><input type="number" name="capacity" class="form-control-custom" placeholder="40"></div>
      <div class="col-md-4">
        <label class="form-label-custom">Assistant नेमा</label>
        <select name="assistant_id" class="form-select-custom">
          <option value="">-- नंतर नेमा --</option>
          <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Lab जोडा</button>
  </form>
</details>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
