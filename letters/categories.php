<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $nameLocal = trim($_POST['name_local'] ?? '') ?: null;
    // एकसारखं नाव दोनदा नको (English किंवा मराठी)
    $dupCheck = function ($name, $nameLocal, $exceptId) use ($pdo, $orgId) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM letter_categories WHERE organization_id = ? AND id <> ? AND (LOWER(name) = LOWER(?) OR (? IS NOT NULL AND name_local = ?))");
        $st->execute([$orgId, $exceptId, $name, $nameLocal, $nameLocal]);
        return (int)$st->fetchColumn() > 0;
    };
    if ($action === 'toggle' && $id) {
        $pdo->prepare("UPDATE letter_categories SET is_active = 1 - is_active WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        $_SESSION['flash'] = 'पत्र प्रकाराची स्थिती बदलली.';
    } elseif ($action === 'rename' && $id && $name) {
        if ($dupCheck($name, $nameLocal, $id)) { $_SESSION['form_error'] = "'$name' या नावाचा प्रकार आधीच आहे."; }
        else {
            $pdo->prepare("UPDATE letter_categories SET name = ?, name_local = ? WHERE id = ? AND organization_id = ?")->execute([$name, $nameLocal, $id, $orgId]);
            $_SESSION['flash'] = 'पत्र प्रकाराचे नाव बदलले.';
        }
    } elseif ($action === 'add' && $name) {
        if ($dupCheck($name, $nameLocal, 0)) { $_SESSION['form_error'] = "'$name' या नावाचा प्रकार आधीच आहे."; }
        else {
            $pdo->prepare("INSERT INTO letter_categories (organization_id, name, name_local) VALUES (?, ?, ?)")->execute([$orgId, $name, $nameLocal]);
            $_SESSION['flash'] = 'पत्र प्रकार जोडला गेला.';
        }
    }
    header('Location: categories.php');
    exit;
}

$stmt = $pdo->prepare("SELECT lc.*,
      (SELECT COUNT(*) FROM letters l WHERE l.category_id = lc.id) AS letter_count,
      (SELECT COUNT(*) FROM letters l WHERE l.category_id = lc.id AND l.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) AS letter_90,
      (SELECT COUNT(*) FROM workflow_templates wt WHERE wt.category_id = lc.id AND wt.is_active = 1) AS wf_count
    FROM letter_categories lc WHERE lc.organization_id = ? ORDER BY lc.is_active DESC, letter_count DESC, lc.name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();
// "सर्व प्रकार" साठीचा सामान्य Workflow आहे का
$st = $pdo->prepare("SELECT COUNT(*) FROM workflow_templates WHERE organization_id = ? AND category_id IS NULL AND is_active = 1");
$st->execute([$orgId]);
$genericWf = (int)$st->fetchColumn();
$st = $pdo->prepare("SELECT COUNT(*) FROM letters WHERE organization_id = ? AND category_id IS NULL");
$st->execute([$orgId]);
$uncategorized = (int)$st->fetchColumn();

$pageTitle = 'पत्र प्रकार';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';

$active = array_filter($categories, fn($c) => $c['is_active']);
$noWf = array_filter($active, fn($c) => !(int)$c['wf_count']);
$unused = array_filter($active, fn($c) => !(int)$c['letter_count']);
$alerts = [];
if ($noWf && !$genericWf) $alerts[] = ['warn', count($noWf) . ' प्रकारांना कोणताही कार्यप्रवाह नाही व "सर्व प्रकार" साठीचा सामान्य कार्यप्रवाहही नाही — ती पत्रं पुढे कोणाकडे जायची ते ठरत नाही.', 'workflows.php'];
elseif ($noWf) $alerts[] = ['info', count($noWf) . ' प्रकारांना स्वतःचा कार्यप्रवाह नाही — ती पत्रं "सर्व प्रकार" च्या सामान्य कार्यप्रवाहाने जातील.', 'workflows.php'];
if ($uncategorized) $alerts[] = ['info', $uncategorized . ' पत्रांना प्रकारच दिलेला नाही.', 'index.php'];
if (!$categories) $alerts[] = ['warn', 'अजून एकही पत्र प्रकार नाही — "मानक प्रकार व Workflows आपोआप तयार करा" वापरा.', 'seed-defaults.php'];
$maxUse = max(1, max(array_map(fn($c) => (int)$c['letter_count'], $categories ?: [['letter_count' => 0]])));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0">पत्र प्रकार (Letter Categories)</div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="workflows.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-diagram-project me-1"></i>कार्यप्रवाह</a>
    <a href="seed-defaults.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
      <i class="fa-solid fa-wand-magic-sparkles me-1"></i>मानक प्रकार व Workflows आपोआप तयार करा
    </a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">पत्र/फाईल तयार करताना कोणता प्रकार निवडायचा — उदा. सर्वसाधारण पत्रव्यवहार, रजा अर्ज, तक्रार, परवानगी अर्ज.</p>

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
    ['label' => 'पत्र प्रकार', 'value' => count($categories), 'sub' => count($active) . ' सक्रिय'],
    ['label' => 'एकूण पत्रं', 'value' => array_sum(array_column($categories, 'letter_count')) + $uncategorized, 'sub' => array_sum(array_column($categories, 'letter_90')) . ' गेल्या ९० दिवसांत'],
    ['label' => 'कार्यप्रवाह नसलेले', 'value' => count($noWf), 'sub' => $genericWf ? 'सामान्य कार्यप्रवाह आहे' : 'सामान्य कार्यप्रवाहही नाही', 'tone' => $noWf && !$genericWf ? 'bad' : ($noWf ? 'warn' : 'ok')],
    ['label' => 'न वापरलेले', 'value' => count($unused), 'sub' => 'अजून एकही पत्र नाही'],
]);
smartAlerts($alerts);
?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन प्रकार जोडा</div></div>
  <form method="POST" style="padding:20px;">
    <input type="hidden" name="action" value="add">
    <div class="row g-2">
      <div class="col-md-5"><input type="text" name="name" id="lcName" class="form-control-custom" placeholder="नाव (English)" required></div>
      <div class="col-md-5"><input type="text" name="name_local" class="form-control-custom" placeholder="नाव (मराठी)"></div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">जोडा</button></div>
    </div>
    <div id="lcDup" style="font-size:11.5px;color:var(--rose);font-weight:600;margin-top:6px;" hidden>⚠ या नावाचा प्रकार आधीच आहे</div>
  </form>
</div>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>पत्रं</th><th>९० दिवसांत</th><th>कार्यप्रवाह</th><th>स्थिती</th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (empty($categories)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:20px;">कोणताही प्रकार अजून जोडलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <tr style="<?= $c['is_active'] ? '' : 'opacity:.6;' ?>">
          <td style="font-weight:500;">
            <span class="lc-view"><?= h($c['name_local'] ?: $c['name']) ?> <span style="color:var(--text-dim);font-size:12px;">(<?= h($c['name']) ?>)</span></span>
            <form method="POST" class="lc-edit" hidden>
              <input type="hidden" name="action" value="rename"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <div class="d-flex gap-1 flex-wrap">
                <input type="text" name="name" value="<?= h($c['name']) ?>" class="form-control-custom" style="max-width:180px;" required>
                <input type="text" name="name_local" value="<?= h($c['name_local'] ?? '') ?>" class="form-control-custom" style="max-width:180px;">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन</button>
              </div>
            </form>
          </td>
          <td data-sort="<?= (int)$c['letter_count'] ?>"><div class="lc-bar"><i style="width:<?= round((int)$c['letter_count'] * 100 / $maxUse) ?>%;"></i></div> <span class="font-mono"><?= (int)$c['letter_count'] ?></span></td>
          <td class="font-mono"><?= (int)$c['letter_90'] ?></td>
          <td><?= (int)$c['wf_count'] ? '<span style="color:var(--rail);font-weight:600;font-size:12.5px;">✓ ' . (int)$c['wf_count'] . '</span>' : '<span style="color:' . ($genericWf ? 'var(--text-dim)' : 'var(--amber)') . ';font-size:12px;">' . ($genericWf ? 'सामान्य' : '⚠ नाही') . '</span>' ?></td>
          <td><?= $c['is_active'] ? '<span class="status-chip done">सक्रिय</span>' : '<span class="status-chip overdue">निष्क्रिय</span>' ?></td>
          <td style="white-space:nowrap;text-align:right;">
            <button type="button" class="btn btn-sm lc-rename" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 10px;" title="नाव बदला"><i class="fa-solid fa-pen"></i></button>
            <form method="POST" class="d-inline">
              <input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 10px;" title="<?= $c['is_active'] ? 'निष्क्रिय करा — नवीन पत्रात दिसणार नाही, जुनी पत्रं तशीच' : 'पुन्हा सक्रिय करा' ?>"><?= $c['is_active'] ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<style>.lc-bar{display:inline-block;width:70px;height:6px;border-radius:4px;background:var(--canvas);overflow:hidden;vertical-align:middle;}.lc-bar i{display:block;height:100%;background:var(--rail);border-radius:4px;}</style>
<script>
(function () {
  document.querySelectorAll('.lc-rename').forEach(function (b) {
    b.addEventListener('click', function () {
      var tr = b.closest('tr'); var f = tr.querySelector('.lc-edit'), v = tr.querySelector('.lc-view');
      f.hidden = !f.hidden; v.hidden = !f.hidden ? true : false; if (!f.hidden) f.querySelector('input[name=name]').focus();
    });
  });
  var NAMES = <?= json_encode(array_map(fn($c) => mb_strtolower($c['name']), $categories), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
  var n = document.getElementById('lcName'), d = document.getElementById('lcDup');
  if (n) n.addEventListener('input', function () { d.hidden = NAMES.indexOf(n.value.trim().toLowerCase()) === -1; });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
