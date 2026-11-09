<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_dead_stock_master', '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_item'])) {
        $itemName = trim($_POST['item_name'] ?? '');
        $category = trim($_POST['category'] ?? '') ?: null;
        $defaultUnit = trim($_POST['default_unit'] ?? '') ?: null;
        if ($itemName) {
            try {
                $pdo->prepare("INSERT INTO dead_stock_item_master (organization_id, item_name, category, default_unit, created_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$orgId, $itemName, $category, $defaultUnit, $user['id']]);
                logActivity('Dead Stock वस्तू यादीत जोडली', 'dead_stock_item_master', $pdo->lastInsertId(), $itemName);
                $_SESSION['flash'] = "'$itemName' यादीत जोडली गेली.";
            } catch (PDOException $e) {
                $_SESSION['form_error'] = "'$itemName' आधीच यादीत आहे.";
            }
        }
    } elseif (isset($_POST['add_catalog'])) {
        // 📚 मानक वस्तू यादी — निवडलेल्या प्रकारातल्या वस्तू (आधीच असलेल्या वगळून)
        require_once __DIR__ . '/../database/deadstock-catalog.php';
        $catalog = deadStockCatalog();
        $pick = array_values(array_intersect((array)($_POST['catalog_cats'] ?? []), array_keys($catalog)));
        if (!$pick) {
            $_SESSION['form_error'] = 'किमान एक प्रकार निवडा.';
        } else {
            $ins = $pdo->prepare("INSERT IGNORE INTO dead_stock_item_master (organization_id, item_name, category, default_unit, created_by) VALUES (?, ?, ?, ?, ?)");
            $added = 0; $total = 0;
            $pdo->beginTransaction();
            foreach ($pick as $cat) {
                foreach ($catalog[$cat] as [$name, $unit]) { $total++; $ins->execute([$orgId, $name, $cat, $unit, $user['id']]); $added += $ins->rowCount(); }
            }
            $pdo->commit();
            logActivity('Dead Stock मानक वस्तू यादी जोडली', 'dead_stock_item_master', 0, $added . ' वस्तू, ' . count($pick) . ' प्रकार');
            $_SESSION['flash'] = "📚 $added नवीन वस्तू यादीत जोडल्या (" . count($pick) . ' प्रकार)' . ($total - $added ? ' — ' . ($total - $added) . ' आधीच होत्या म्हणून वगळल्या.' : '.');
        }
    } elseif (isset($_POST['toggle_id'])) {
        $tid = (int)$_POST['toggle_id'];
        $pdo->prepare("UPDATE dead_stock_item_master SET is_active = 1 - is_active WHERE id = ? AND organization_id = ?")->execute([$tid, $orgId]);
        $_SESSION['flash'] = 'स्थिती बदलली गेली.';
    } elseif (isset($_POST['delete_id'])) {
        $did = (int)$_POST['delete_id'];
        $pdo->prepare("DELETE FROM dead_stock_item_master WHERE id = ? AND organization_id = ?")->execute([$did, $orgId]);
        $_SESSION['flash'] = 'वस्तू यादीतून कायमची काढली गेली.';
    } elseif (isset($_POST['edit_id'])) {
        $eid = (int)$_POST['edit_id'];
        $itemName = trim($_POST['edit_item_name'] ?? '');
        $category = trim($_POST['edit_category'] ?? '') ?: null;
        $defaultUnit = trim($_POST['edit_default_unit'] ?? '') ?: null;
        if ($itemName) {
            $pdo->prepare("UPDATE dead_stock_item_master SET item_name=?, category=?, default_unit=? WHERE id=? AND organization_id=?")
                ->execute([$itemName, $category, $defaultUnit, $eid, $orgId]);
            $_SESSION['flash'] = 'वस्तू संपादित केली.';
        }
    } elseif (isset($_POST['import_csv']) && !empty($_FILES['csv_file']['tmp_name'])) {
        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if ($handle) {
            $header = array_map(fn($h) => strtolower(trim($h)), fgetcsv($handle));
            $colIndex = array_flip($header);
            $get = fn($row, $col) => isset($colIndex[$col]) && isset($row[$colIndex[$col]]) ? trim($row[$colIndex[$col]]) : null;

            $imported = 0;
            $skipped = 0;
            while (($row = fgetcsv($handle)) !== false) {
                if (count($row) === 1 && trim($row[0]) === '') continue;
                $itemName = $get($row, 'item_name');
                if (!$itemName) { $skipped++; continue; }
                try {
                    $pdo->prepare("INSERT INTO dead_stock_item_master (organization_id, item_name, category, default_unit, created_by) VALUES (?, ?, ?, ?, ?)")
                        ->execute([$orgId, $itemName, $get($row, 'category'), $get($row, 'default_unit'), $user['id']]);
                    $imported++;
                } catch (PDOException $e) {
                    $skipped++; // duplicate item name — skip rather than fail the whole import
                }
            }
            fclose($handle);
            logActivity('Dead Stock वस्तू यादी CSV आयात', 'dead_stock_item_master', 0, "$imported नोंदी जोडल्या, $skipped वगळल्या");
            $_SESSION['flash'] = "$imported वस्तू यादीत जोडल्या गेल्या." . ($skipped > 0 ? " ($skipped ओळी वगळल्या — रिकामे नाव किंवा आधीच अस्तित्वात.)" : '');
        }
    }
    header('Location: item-master.php');
    exit;
}

$pageTitle = 'वस्तू यादी (Item Master)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT * FROM dead_stock_item_master WHERE organization_id = ? ORDER BY category, item_name");
$stmt->execute([$orgId]);
$masterItems = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">📋 वस्तू यादी (Item Master)</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">कार्यालयासाठी, शाळा/कॉलेजसाठी, संस्थेसाठी लागणाऱ्या सगळ्या वस्तूंची केंद्रीकृत यादी — सगळे विभाग इथूनच निवडतील (मुक्त टायपिंग नाही)</div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Register कडे परत</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    <strong>काही ओळी वगळल्या गेल्या:</strong>
    <ul class="mb-0"><?php foreach ($_SESSION['import_errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
  </div>
  <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>

<?php
  // 📚 मानक वस्तू यादी — कोणत्या प्रकारात किती वस्तू आधीच आहेत
  require_once __DIR__ . '/../database/deadstock-catalog.php';
  $catalog = deadStockCatalog();
  $haveNames = array_flip(array_map(fn($n) => mb_strtolower(trim($n)), array_column($masterItems, 'item_name')));
  $catTotal = 0; $catMissing = 0;
?>
<details class="panel mb-3" id="dsCatalog" <?= count($masterItems) < 50 ? 'open' : '' ?>>
  <summary style="padding:14px 20px;cursor:pointer;list-style:none;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
    <span><span style="font-weight:800;font-size:15px;">📚 मानक वस्तू यादी — मोठ्या संस्थेसाठी</span><br>
      <span style="font-size:12px;color:var(--text-dim);">फर्निचर, कार्यालय, IT, इलेक्ट्रिकल, प्रयोगशाळा, कार्यशाळा, वसतिगृह, मेस, वैद्यकीय, क्रीडा, ग्रंथालय, वाहन, गौशाळा, मंदिर… — हवे ते प्रकार निवडून एका Click मध्ये जोडा</span></span>
    <span style="font-size:12px;font-weight:700;color:var(--rail);">उघडा / बंद करा ▾</span>
  </summary>
  <form method="POST" style="padding:4px 20px 18px;" onsubmit="return confirm('निवडलेल्या प्रकारातल्या वस्तू यादीत जोडायच्या? (आधीच असलेल्या वगळल्या जातील)');">
    <input type="hidden" name="add_catalog" value="1">
    <div class="d-flex gap-2 flex-wrap mb-2" style="font-size:12px;">
      <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;" onclick="document.querySelectorAll('.ds-cat-cb').forEach(c => c.checked = true)">सगळे निवडा</button>
      <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;" onclick="document.querySelectorAll('.ds-cat-cb').forEach(c => c.checked = false)">सगळे काढा</button>
    </div>
    <div class="ds-cat-grid">
      <?php foreach ($catalog as $cat => $items):
        $missing = count(array_filter($items, fn($it) => !isset($haveNames[mb_strtolower($it[0])])));
        $catTotal += count($items); $catMissing += $missing; ?>
        <label class="ds-cat <?= $missing ? '' : 'done' ?>">
          <input type="checkbox" class="ds-cat-cb" name="catalog_cats[]" value="<?= h($cat) ?>" <?= $missing ? 'checked' : 'disabled' ?>>
          <span class="nm"><?= h($cat) ?></span>
          <span class="ct"><?= $missing ? $missing . ' नवीन / ' . count($items) : '✅ सगळ्या आहेत' ?></span>
          <details class="pv" onclick="event.stopPropagation()"><summary>वस्तू पाहा</summary><div><?= h(implode(' · ', array_map(fn($it) => $it[0], $items))) ?></div></details>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-3">
      <span style="font-size:12.5px;color:var(--text-dim);">एकूण <b><?= $catTotal ?></b> मानक वस्तू · त्यापैकी <b><?= $catMissing ?></b> अजून यादीत नाहीत. नंतर Edit / निष्क्रिय / Delete करता येतात.</span>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:700;" <?= $catMissing ? '' : 'disabled' ?>>📚 निवडलेल्या वस्तू यादीत जोडा</button>
    </div>
  </form>
</details>
<style>
  .ds-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:8px;}
  .ds-cat{display:grid;grid-template-columns:auto 1fr;gap:2px 8px;align-items:start;border:1px solid var(--line);border-radius:10px;padding:9px 11px;cursor:pointer;background:var(--surface,#fff);}
  .ds-cat:has(input:checked){border-color:var(--rail);background:color-mix(in srgb,var(--rail) 6%,var(--surface,#fff));}
  .ds-cat.done{opacity:.6;cursor:default;}
  .ds-cat input{margin-top:3px;width:15px;height:15px;accent-color:var(--rail);}
  .ds-cat .nm{font-size:13px;font-weight:700;} .ds-cat .ct{grid-column:2;font-size:11.5px;color:var(--text-dim);}
  .ds-cat .pv{grid-column:2;font-size:11.5px;} .ds-cat .pv summary{color:var(--rail);cursor:pointer;font-weight:600;} .ds-cat .pv div{color:var(--text-dim);margin-top:4px;line-height:1.5;}
</style>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">नवीन वस्तू जोडा (एक-एक)</div></div>
      <form method="POST" style="padding:20px 24px;">
        <input type="hidden" name="add_item" value="1">
        <div class="row g-3">
          <div class="col-md-5">
            <label class="form-label-custom">वस्तूचे नाव</label>
            <input type="text" name="item_name" class="form-control-custom" placeholder="उदा. लोखंडी कपाट, संगणक, प्रयोगशाळा साहित्य" required>
          </div>
          <div class="col-md-4">
            <label class="form-label-custom">प्रकार (Category)</label>
            <input type="text" name="category" class="form-control-custom" placeholder="उदा. फर्निचर, इलेक्ट्रॉनिक्स, शालेय साहित्य">
          </div>
          <div class="col-md-3">
            <label class="form-label-custom">डिफॉल्ट एकक</label>
            <input type="text" name="default_unit" class="form-control-custom" placeholder="नग, सेट">
          </div>
          <div class="col-12 d-flex justify-content-end">
            <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">यादीत जोडा</button>
          </div>
        </div>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">📥 CSV ने Bulk जोडा</div></div>
      <div style="padding:20px 24px;">
        <form method="POST" action="item-master-import-save.php" enctype="multipart/form-data" class="mb-3">
          <div class="upload-box mb-2">
            <input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;">
          </div>
          <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">
            <i class="fa-solid fa-upload me-1"></i>CSV आयात करा
          </button>
        </form>
        <div style="font-size:11px;color:var(--text-dim);">Columns: <span class="font-mono">item_name</span> (अनिवार्य), <span class="font-mono">category</span>, <span class="font-mono">default_unit</span> (ऐच्छिक)</div>
        <a href="item-master-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:11.5px;padding:5px 12px;">
          <i class="fa-solid fa-download me-1"></i>Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>


<div class="panel">
  <?php $__cats = array_values(array_unique(array_filter(array_column($masterItems, 'category')))); ?>
  <div class="d-flex gap-2 flex-wrap align-items-center" style="padding:12px 16px;border-bottom:1px solid var(--line);">
    <input type="search" id="dsFind" class="form-control-custom" style="max-width:280px;" placeholder="🔍 वस्तू शोधा…">
    <select id="dsCatFilter" class="form-select-custom" style="max-width:280px;"><option value="">सर्व प्रकार (<?= count($masterItems) ?>)</option><?php foreach ($__cats as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select>
    <span id="dsCount" style="font-size:12.5px;color:var(--text-dim);margin-left:auto;"></span>
  </div>
  <table class="rtable" id="dsTable">
    <thead><tr><th>वस्तूचे नाव</th><th>प्रकार</th><th>एकक</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($masterItems)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतीही वस्तू यादीत नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($masterItems as $mi): ?>
        <tr>
          <td style="font-weight:500;"><?= h($mi['item_name']) ?></td>
          <td><?= h($mi['category'] ?? '—') ?></td>
          <td><?= h($mi['default_unit'] ?? '—') ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="toggle_id" value="<?= $mi['id'] ?>">
              <button type="submit" class="status-chip <?= $mi['is_active'] ? 'done' : 'overdue' ?>" style="border:none;cursor:pointer;"><?= $mi['is_active'] ? 'सक्रिय' : 'निष्क्रिय' ?></button>
            </form>
          </td>
          <td class="d-flex gap-2">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#editModal<?= $mi['id'] ?>">Edit</button>
            <form method="POST" onsubmit="return confirm('ही वस्तू यादीतून कायमची काढायची? — जुन्या नोंदींवर परिणाम होणार नाही.');">
              <input type="hidden" name="delete_id" value="<?= $mi['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;">Delete</button>
            </form>
          </td>
        </tr>

        <div class="modal fade" id="editModal<?= $mi['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST">
                <input type="hidden" name="edit_id" value="<?= $mi['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-bottom:14px;">वस्तू संपादित करा</div>
                  <label class="form-label-custom">वस्तूचे नाव</label>
                  <input type="text" name="edit_item_name" class="form-control-custom mb-2" value="<?= h($mi['item_name']) ?>" required>
                  <label class="form-label-custom">प्रकार</label>
                  <input type="text" name="edit_category" class="form-control-custom mb-2" value="<?= h($mi['category']) ?>">
                  <label class="form-label-custom">डिफॉल्ट एकक</label>
                  <input type="text" name="edit_default_unit" class="form-control-custom" value="<?= h($mi['default_unit']) ?>">
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
(function () {   // 🔍 वस्तू शोध + प्रकार Filter (600+ वस्तू असल्या तरी पटकन सापडाव्यात)
  var q = document.getElementById('dsFind'), c = document.getElementById('dsCatFilter'), t = document.getElementById('dsTable'), n = document.getElementById('dsCount');
  if (!q || !t) return;
  var rows = [].slice.call(t.tBodies[0].rows).filter(function (r) { return r.cells.length > 2; });
  function run() {
    var s = q.value.trim().toLowerCase(), cat = c.value, shown = 0;
    rows.forEach(function (r) { var ok = (!s || r.cells[0].textContent.toLowerCase().indexOf(s) !== -1) && (!cat || r.cells[1].textContent.trim() === cat); r.style.display = ok ? '' : 'none'; if (ok) shown++; });
    n.textContent = shown + ' / ' + rows.length;
  }
  q.addEventListener('input', run); c.addEventListener('change', run); run();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
