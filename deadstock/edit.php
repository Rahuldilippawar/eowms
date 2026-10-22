<?php
$pageTitle = 'Dead Stock नोंद संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_dead_stock_master', '../');

$id = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM dead_stock_items WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$item = $stmt->fetch();
if (!$item) { die('नोंद सापडली नाही.'); }

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();
$itemCurrentCampusId = null;
foreach ($departments as $d) { if ((int)$d['id'] === (int)$item['department_id']) { $itemCurrentCampusId = $d['campus_id'] ?? null; break; } }

$stmt = $pdo->prepare("SELECT * FROM dead_stock_item_master WHERE organization_id = ? AND is_active = 1 ORDER BY category, item_name");
$stmt->execute([$orgId]);
$masterItems = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h($item['item_code']) ?></div>
<div class="page-title">Dead Stock नोंद संपादित करा</div>

<div class="panel">
  <form method="POST" action="update.php" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $item['id'] ?>">
    <div class="row g-3">
      <?php if (count($campusesForCascade) > 1): ?>
        <div class="col-md-6">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_dsEdit(this.value)">
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$c['id'] === (int)$itemCurrentCampusId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-6">
        <label class="form-label-custom">विभाग</label>
        <select name="department_id" id="departmentSelect" class="form-select-custom" required>
          <?php foreach ($departments as $d): ?>
            <option value="<?= $d['id'] ?>" <?= $item['department_id'] == $d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
          <?php endforeach; ?>
          </select>
        </div>
        <?php if (count($campusesForCascade) > 1): ?>
        <script>
        function loadDeptsCascade_dsEdit(campusId) {
          const sel = document.getElementById('departmentSelect');
          if (!campusId) return;
          sel.disabled = true;
          fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
            .then(r => r.json()).then(data => {
              sel.innerHTML = '';
              (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
              sel.disabled = false;
            });
        }
        </script>
        <?php endif; ?>
      <div class="col-md-6">
        <label class="form-label-custom">वस्तू (यादीतून)</label>
        <select name="item_master_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($masterItems as $mi): ?>
            <option value="<?= $mi['id'] ?>" <?= $item['item_master_id'] == $mi['id'] ? 'selected' : '' ?>><?= h($mi['item_name']) ?><?= $mi['category'] ? ' (' . h($mi['category']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!$item['item_master_id']): ?>
          <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">मूळ नोंद: <?= h($item['item_name']) ?> (जुनी मुक्त-टायपिंग नोंद — वरून योग्य वस्तू निवडा)</div>
        <?php endif; ?>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">संख्या</label>
        <input type="number" name="quantity" class="form-control-custom" value="<?= $item['quantity'] ?>" min="1">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">एकक</label>
        <input type="text" name="unit" class="form-control-custom" value="<?= h($item['unit']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">खरेदी दिनांक</label>
        <input type="date" name="purchase_date" class="form-control-custom" value="<?= h($item['purchase_date']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">खरेदी किंमत (₹)</label>
        <input type="number" step="0.01" name="purchase_cost" class="form-control-custom" value="<?= h($item['purchase_cost']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">सध्याची स्थिती</label>
        <select name="condition" class="form-select-custom">
          <?php foreach (['चांगली','दुरुस्तीयोग्य','खराब','भंगार'] as $c): ?>
            <option value="<?= $c ?>" <?= $item['condition'] === $c ? 'selected' : '' ?>><?= $c ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">ठिकाण</label>
        <input type="text" name="location" class="form-control-custom" value="<?= h($item['location']) ?>">
      </div>
      <div class="col-12">
        <label class="form-label-custom">टिपा</label>
        <textarea name="remarks" class="form-control-custom" rows="2"><?= h($item['remarks']) ?></textarea>
      </div>
      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">बदल जतन करा</button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
