<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $itemName = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $reorderLevel = (float)($_POST['reorder_level'] ?? 0);

    // विभाग (असल्यास) याच संस्थेचा असावा
    if ($departmentId) {
        $deptCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
        $deptCheck->execute([$departmentId, $orgId]);
        if (!$deptCheck->fetch()) {
            $_SESSION['flash'] = 'निवडलेला विभाग सापडला नाही — बदल झाला नाही.';
            header('Location: items.php');
            exit;
        }
    }

    if ($action === 'update' || $action === 'toggle_active') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemStmt = $pdo->prepare("SELECT * FROM store_items WHERE id = ? AND organization_id = ?");
        $itemStmt->execute([$itemId, $orgId]);
        $item = $itemStmt->fetch();
        if (!$item) {
            $_SESSION['flash'] = 'वस्तू सापडली नाही.';
        } elseif ($action === 'toggle_active') {
            $newActive = $item['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE store_items SET is_active = ? WHERE id = ? AND organization_id = ?")->execute([$newActive, $itemId, $orgId]);
            logActivity($newActive ? 'भांडार वस्तू पुन्हा सक्रिय केली' : 'भांडार वस्तू निष्क्रिय केली', 'store_item', $itemId, $item['item_name']);
            $_SESSION['flash'] = "'{$item['item_name']}' " . ($newActive ? 'पुन्हा सक्रिय केली.' : 'निष्क्रिय केली (नवीन कामात दिसणार नाही).');
        } elseif ($itemName && $unit) {
            $pdo->prepare("UPDATE store_items SET item_name = ?, category = ?, department_id = ?, unit = ?, reorder_level = ? WHERE id = ? AND organization_id = ?")
                ->execute([$itemName, $category, $departmentId, $unit, $reorderLevel, $itemId, $orgId]);
            logActivity('भांडार वस्तू बदलली', 'store_item', $itemId, $itemName);
            $_SESSION['flash'] = "'$itemName' अद्ययावत झाली.";
        } else {
            $_SESSION['flash'] = 'वस्तूचं नाव व एकक आवश्यक आहे.';
        }
        header('Location: items.php');
        exit;
    }

    if ($itemName && $unit) {
        $pdo->prepare("INSERT INTO store_items (organization_id, item_name, category, department_id, unit, reorder_level, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $itemName, $category, $departmentId, $unit, $reorderLevel, $user['id']]);
        $_SESSION['flash'] = "'$itemName' यादीत जोडली गेली.";
    }
    header('Location: items.php');
    exit;
}

$pageTitle = 'भांडार वस्तू यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$itemsStmt = $pdo->prepare("
    SELECT si.*, d.name_local AS dept_name FROM store_items si LEFT JOIN departments d ON d.id = si.department_id
    WHERE si.organization_id = ? ORDER BY si.item_name
");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();

// 📊 वापराचं विश्लेषण — कामाच्या नोंदींमध्ये (work_record_materials) वापरलेलं साहित्य
$usage = [];
try {
    $uStmt = $pdo->prepare("SELECT m.item_id,
            SUM(CASE WHEN wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) THEN m.quantity_used ELSE 0 END) q30,
            SUM(CASE WHEN wr.work_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 59 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN m.quantity_used ELSE 0 END) qprev,
            SUM(wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)) uses30,
            MAX(wr.work_date) last_used
        FROM work_record_materials m JOIN work_records wr ON wr.id = m.work_record_id
        WHERE wr.organization_id = ? GROUP BY m.item_id");
    $uStmt->execute([$orgId]);
    foreach ($uStmt->fetchAll() as $u) { $usage[(int)$u['item_id']] = $u; }
} catch (PDOException $e) {}
$activeItems = array_filter($items, fn($i) => $i['is_active']);
$usedIn30 = 0; $deadItems = []; $topUsed = []; $catCounts = [];
foreach ($activeItems as $it) {
    $u = $usage[(int)$it['id']] ?? null;
    if ($u && (float)$u['q30'] > 0) { $usedIn30++; $topUsed[] = ['name' => $it['item_name'], 'unit' => $it['unit'], 'q' => (float)$u['q30'], 'uses' => (int)$u['uses30']]; }
    if (!$u || !$u['last_used'] || strtotime($u['last_used']) < strtotime('-90 days')) { $deadItems[] = $it['item_name']; }
    $cat = trim((string)$it['category']) ?: 'इतर';
    $catCounts[$cat] = ($catCounts[$cat] ?? 0) + 1;
}
usort($topUsed, fn($a, $b) => $b['uses'] <=> $a['uses'] ?: $b['q'] <=> $a['q']);
$topUsed = array_slice($topUsed, 0, 6);
arsort($catCounts);

$stockBal = storeBalances($pdo, $orgId);
$deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$deptsForItems = $deptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 भांडार वस्तू यादी</div>
</div>
<?= storeNav('items') ?>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन वस्तू जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">वस्तूचं नाव</label><input type="text" name="item_name" class="form-control-custom" required></div>
      <div class="col-md-2"><label class="form-label-custom">प्रकार</label><input type="text" name="category" class="form-control-custom" placeholder="धान्य, स्टेशनरी"></div>
      <?php if (count($campusesForCascade) > 1): ?>
        <div class="col-md-2">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_stItems(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label-custom">कोणत्या विभागासाठी <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label>
        <select name="department_id" id="departmentSelect" class="form-select-custom">
          <option value="">-- सामायिक/इतर --</option>
          <?php foreach ($deptsForItems as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_stItems(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="">-- सामायिक/इतर --</option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label-custom">एकक</label><input type="text" name="unit" class="form-control-custom" placeholder="किलो, नग" required></div>
      <div class="col-md-1"><label class="form-label-custom">किमान</label><input type="number" step="0.01" name="reorder_level" class="form-control-custom"></div>
      <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;padding:9px;">+</button></div>
    </div>
  </form>
</div>

<?php if ($activeItems): ?>
<div class="hi-totals mb-3">
  <div><span>सक्रिय वस्तू</span><b><?= count($activeItems) ?></b><small><?= count($catCounts) ?> प्रकार</small></div>
  <div><span>३० दिवसांत वापरलेल्या</span><b class="c-ok"><?= $usedIn30 ?></b><small><?= count($activeItems) ? (int)round($usedIn30 * 100 / count($activeItems)) : 0 ?>% वस्तू</small></div>
  <div><span>९० दिवस न वापरलेल्या</span><b class="c-amber"><?= count($deadItems) ?></b><small>यादी स्वच्छ करण्याचा विचार करा</small></div>
  <div><span>सर्वाधिक प्रकार</span><b style="font-size:16px;"><?= h(array_key_first($catCounts) ?? '—') ?></b><small><?= reset($catCounts) ?: 0 ?> वस्तू</small></div>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🔥 सर्वाधिक वापरलेलं साहित्य — मागील ३० दिवस</div><div class="sub">कामाच्या नोंदींमधून · किती वेळा व किती प्रमाणात</div></div>
      <div style="padding:12px 20px;">
        <?php if (!$topUsed): ?><div class="sm-empty">मागील ३० दिवसांत कामात साहित्य वापर नोंदवलेला नाही</div><?php endif; ?>
        <?php $maxUses = $topUsed ? max(array_column($topUsed, 'uses')) : 1; foreach ($topUsed as $t): ?>
          <div class="wl-row"><div class="wl-name" title="<?= h($t['name']) ?>"><?= h($t['name']) ?></div>
            <div class="wl-bar"><i style="width:<?= round($t['uses'] * 100 / max(1, $maxUses)) ?>%;background:var(--amber);"></i></div>
            <div class="wl-num font-mono"><?= rtrim(rtrim(number_format($t['q'], 2), '0'), '.') ?> <?= h($t['unit']) ?> · <?= $t['uses'] ?>×</div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">💤 ९० दिवस न वापरलेल्या वस्तू</div><div class="sub">गरज नसल्यास निष्क्रिय करा — यादी सुटसुटीत राहील</div></div>
      <div style="padding:12px 20px;display:flex;flex-wrap:wrap;gap:6px;max-height:220px;overflow:auto;">
        <?php if (!$deadItems): ?><div class="sm-empty" style="width:100%;">✅ सर्व वस्तू वापरात आहेत</div><?php endif; ?>
        <?php foreach (array_slice($deadItems, 0, 40) as $dn): ?><span class="st-dead"><?= h($dn) ?></span><?php endforeach; ?>
        <?php if (count($deadItems) > 40): ?><span class="st-dead">+<?= count($deadItems) - 40 ?> आणखी</span><?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>वस्तू</th><th>प्रकार</th><th>विभाग</th><th>एकक</th><th class="text-end">शिल्लक</th><th>किमान मर्यादा</th><th title="मागील ३० दिवसांत कामात वापरलेलं प्रमाण">३० दिवस वापर</th><th>शेवटचा वापर</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr style="<?= $it['is_active'] ? '' : 'opacity:.55;' ?>">
          <td style="font-weight:500;"><?= h($it['item_name']) ?><?= $it['is_active'] ? '' : ' <span class="status-chip overdue">निष्क्रिय</span>' ?></td><td><?= h($it['category']) ?></td><td><?= h($it['dept_name'] ?: '⚪ सामायिक') ?></td><td><?= h($it['unit']) ?></td>
          <?php $sb = $stockBal[(int)$it['id']] ?? null; $sq = $sb ? $sb['bal'] : null; $lowS = $sq !== null && ($sq <= 0 || ($it['reorder_level'] > 0 && $sq <= $it['reorder_level'])); ?>
          <td class="text-end font-mono" style="<?= $lowS ? 'color:var(--rose);font-weight:700;' : '' ?>"><?= $sq === null ? '<a href="move.php?type=opening&item=' . (int)$it['id'] . '" style="font-size:11px;">+ शिल्लक</a>' : storeQty($sq) ?></td>
          <td><?= number_format($it['reorder_level'], 2) ?></td>
          <?php $u = $usage[(int)$it['id']] ?? null; $q30 = $u ? (float)$u['q30'] : 0; $qp = $u ? (float)$u['qprev'] : 0; ?>
          <td class="font-mono" data-sort="<?= $q30 ?>"><?= $q30 > 0 ? rtrim(rtrim(number_format($q30, 2), '0'), '.') : '—' ?>
            <?php if ($q30 > 0 || $qp > 0): $chg = $qp > 0 ? (int)round(($q30 - $qp) * 100 / $qp) : null; ?>
              <span style="font-size:10.5px;color:<?= $chg === null ? 'var(--rail)' : ($chg > 0 ? 'var(--amber)' : 'var(--rail)') ?>;"><?= $chg === null ? '▲ नवीन' : ($chg > 0 ? '▲' : ($chg < 0 ? '▼' : '→')) . ' ' . abs($chg ?? 0) . '%' ?></span>
            <?php endif; ?></td>
          <td class="font-mono" style="font-size:12px;"><?= $u && $u['last_used'] ? date('d M Y', strtotime($u['last_used'])) : '<span style="color:var(--text-dim);">कधीच नाही</span>' ?></td>
          <td style="white-space:nowrap;">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;" onclick="document.getElementById('itemEdit<?= $it['id'] ?>').classList.toggle('d-none')">Edit</button>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $it['is_active'] ? 'ही वस्तू निष्क्रिय करायची?' : 'ही वस्तू पुन्हा सक्रिय करायची?' ?>')">
              <input type="hidden" name="action" value="toggle_active"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;"><?= $it['is_active'] ? 'निष्क्रिय करा' : 'सक्रिय करा' ?></button>
            </form>
          </td>
        </tr>
        <tr id="itemEdit<?= $it['id'] ?>" class="d-none">
          <td colspan="9">
            <form method="POST" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="update"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
              <div class="col-md-3"><input type="text" name="item_name" class="form-control-custom" value="<?= h($it['item_name']) ?>" required></div>
              <div class="col-md-2"><input type="text" name="category" class="form-control-custom" value="<?= h($it['category']) ?>" placeholder="प्रकार"></div>
              <div class="col-md-3"><select name="department_id" class="form-select-custom"><option value="">-- सामायिक/इतर --</option><?php foreach ($deptsForItems as $d): ?><option value="<?= $d['id'] ?>" <?= (int)$d['id'] === (int)$it['department_id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-1"><input type="text" name="unit" class="form-control-custom" value="<?= h($it['unit']) ?>" required></div>
              <div class="col-md-1"><input type="number" step="0.01" name="reorder_level" class="form-control-custom" value="<?= h($it['reorder_level']) ?>"></div>
              <div class="col-md-2"><button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">जतन करा</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
