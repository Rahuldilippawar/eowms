<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

// ⚠️ Error Log — includes/error-handler.php ने नोंदवलेल्या सगळ्या त्रुटी (PHP + Browser JS)
$dir = EOWMS_ERROR_LOG_DIR;
$files = is_dir($dir) ? glob($dir . '/errors-*.log') : [];
rsort($files);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    foreach ($files as $f) @unlink($f);
    logActivity('Error Log रिकामा केला', 'system', 0);
    $_SESSION['flash'] = 'Error Log रिकामा केला.';
    header('Location: error-log.php');
    exit;
}

// शेवटच्या 2 महिन्यांच्या Files मधून नवीनतम 1000 नोंदी
$rows = [];
foreach (array_slice($files, 0, 2) as $f) {
    $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_reverse(array_slice($lines, -1000)) as $l) { $r = json_decode($l, true); if ($r) $rows[] = $r; }
}
usort($rows, fn($a, $b) => strcmp($b['time'] ?? '', $a['time'] ?? ''));
$rows = array_slice($rows, 0, 1000);

$level = $_GET['level'] ?? '';
$q = trim($_GET['q'] ?? '');
$levelOf = function ($r) { return ($r['level'] ?? '') === 'warning' ? 'warning' : (str_starts_with($r['type'] ?? '', 'JS') ? 'js' : 'error'); };
$counts = ['error' => 0, 'warning' => 0, 'js' => 0];
$today = date('Y-m-d'); $todayCount = 0;
foreach ($rows as $r) { $counts[$levelOf($r)]++; if (str_starts_with($r['time'] ?? '', $today)) $todayCount++; }
$shown = array_values(array_filter($rows, function ($r) use ($level, $q, $levelOf) {
    if ($level && $levelOf($r) !== $level) return false;
    if ($q !== '') { $hay = mb_strtolower(implode(' ', [$r['ref'] ?? '', $r['message'] ?? '', $r['file'] ?? '', $r['url'] ?? '', $r['user'] ?? ''])); if (mb_strpos($hay, mb_strtolower($q)) === false) return false; }
    return true;
}));
// सारख्या त्रुटी एकत्र — सर्वात जास्त वेळा आलेल्या
$groups = [];
foreach ($rows as $r) { $k = ($r['type'] ?? '') . '|' . ($r['file'] ?? '') . ':' . ($r['line'] ?? ''); $groups[$k] = ($groups[$k] ?? 0) + 1; }
arsort($groups);
$root = dirname(__DIR__);
$rel = fn($f) => ltrim(str_replace([$root, '\\'], ['', '/'], (string)$f), '/');

$pageTitle = 'Error Log';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">⚠️ Error Log</div>
  <div class="d-flex gap-2">
    <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-arrow-left me-1"></i>System Update</a>
    <?php if ($rows): ?>
      <form method="POST" onsubmit="return confirm('सगळ्या Error नोंदी कायमच्या काढायच्या?');" style="margin:0;">
        <input type="hidden" name="action" value="clear">
        <button class="btn" style="border:1px solid var(--rose);color:var(--rose);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-trash me-1"></i>Log रिकामा करा</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:13px;color:var(--text-dim);margin:4px 0 16px;">प्रणालीत आलेली प्रत्येक त्रुटी इथे नोंदवली जाते — Server वरच्या (PHP) आणि वापरकर्त्यांच्या Browser मधल्या (JavaScript). वापरकर्त्याने सांगितलेला <b>Error क्रमांक</b> शोधात टाका.</p>

<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>

<?= smartTiles([
    ['label' => 'आज', 'value' => $todayCount, 'sub' => 'आजच्या त्रुटी', 'tone' => $todayCount ? 'bad' : 'ok'],
    ['label' => 'गंभीर (पान थांबलं)', 'value' => $counts['error'], 'sub' => 'वापरकर्त्याला Error पान दिसलं', 'tone' => $counts['error'] ? 'bad' : 'ok', 'href' => '?level=error'],
    ['label' => 'Warnings', 'value' => $counts['warning'], 'sub' => 'पान चाललं, पण काहीतरी चुकलं', 'tone' => $counts['warning'] ? 'warn' : 'ok', 'href' => '?level=warning'],
    ['label' => 'Browser (JS)', 'value' => $counts['js'], 'sub' => 'पानावरच्या Script त्रुटी', 'tone' => $counts['js'] ? 'warn' : 'ok', 'href' => '?level=js'],
]) ?>

<?php if ($groups): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🔁 वारंवार येणाऱ्या त्रुटी</div><div class="sub">एकाच ठिकाणी जास्त वेळा — आधी हे दुरुस्त करा</div></div>
  <div style="padding:10px 16px;display:flex;flex-direction:column;gap:6px;">
    <?php foreach (array_slice($groups, 0, 5, true) as $k => $n): [$t, $at] = explode('|', $k, 2); ?>
      <div style="display:flex;gap:10px;align-items:center;font-size:12.5px;">
        <b class="font-mono" style="min-width:42px;color:<?= $n > 1 ? 'var(--rose)' : 'var(--text-dim)' ?>;">×<?= $n ?></b>
        <span style="font-weight:600;"><?= h($t) ?></span>
        <code style="color:var(--text-dim);"><?= h($rel($at)) ?></code>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<form method="GET" id="elFilter" class="d-flex gap-2 flex-wrap align-items-center mb-3">
  <input type="search" name="q" value="<?= h($q) ?>" class="form-control-custom" style="max-width:320px;" placeholder="Error क्रमांक / संदेश / पान शोधा…">
  <select name="level" class="form-select-custom" style="max-width:200px;">
    <option value="">सर्व प्रकार</option>
    <option value="error" <?= $level === 'error' ? 'selected' : '' ?>>गंभीर</option>
    <option value="warning" <?= $level === 'warning' ? 'selected' : '' ?>>Warnings</option>
    <option value="js" <?= $level === 'js' ? 'selected' : '' ?>>Browser (JS)</option>
  </select>
  <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 16px;">शोधा</button>
  <?php if ($q || $level): ?><a href="error-log.php" style="font-size:13px;">साफ करा</a><?php endif; ?>
  <span style="font-size:12.5px;color:var(--text-dim);margin-left:auto;"><?= count($shown) ?> नोंदी</span>
</form>

<div class="panel">
  <?php if (!$shown): ?>
    <div style="padding:40px;text-align:center;color:var(--text-dim);"><div style="font-size:34px;">✅</div><?= $rows ? 'शोधाशी जुळणारी त्रुटी नाही' : 'अजून एकही त्रुटी नोंदलेली नाही — प्रणाली नीट चालू आहे.' ?></div>
  <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="rtable">
      <thead><tr><th>वेळ</th><th>Error क्रमांक</th><th>प्रकार</th><th>संदेश</th><th>कुठे</th><th>कोण</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($shown, 0, 300) as $r): $lv = $levelOf($r); $col = $lv === 'error' ? 'var(--rose)' : 'var(--amber)'; ?>
        <tr>
          <td class="font-mono" style="white-space:nowrap;font-size:12px;"><?= h(date('d M, h:i A', strtotime($r['time'] ?? 'now'))) ?></td>
          <td class="font-mono" style="white-space:nowrap;font-weight:700;"><?= h($r['ref'] ?? '') ?></td>
          <td><span style="font-size:11px;font-weight:700;padding:2px 8px;border-radius:6px;background:color-mix(in srgb, <?= $col ?> 14%, transparent);color:<?= $col ?>;white-space:nowrap;"><?= h($r['kind'] ?? $r['type'] ?? '') ?></span></td>
          <td style="max-width:420px;">
            <details><summary style="cursor:pointer;font-size:12.5px;word-break:break-word;"><?= h(mb_strimwidth($r['message'] ?? '', 0, 140, '…')) ?></summary>
              <div style="font-size:12px;margin-top:6px;">
                <div style="word-break:break-word;"><?= h($r['message'] ?? '') ?></div>
                <?php if (!empty($r['trace'])): ?><pre style="white-space:pre-wrap;font-size:11px;background:var(--canvas);padding:8px;border-radius:8px;margin:6px 0 0;max-height:220px;overflow:auto;"><?= h(str_replace($root . '/', '', $r['trace'])) ?></pre><?php endif; ?>
                <?php if (!empty($r['agent'])): ?><div style="color:var(--text-dim);margin-top:4px;">🖥️ <?= h($r['agent']) ?></div><?php endif; ?>
              </div>
            </details>
          </td>
          <td style="font-size:12px;"><code><?= h($rel($r['file'] ?? '')) ?><?= !empty($r['line']) ? ':' . (int)$r['line'] : '' ?></code><div style="color:var(--text-dim);word-break:break-all;"><?= h(mb_strimwidth($r['url'] ?? '', 0, 80, '…')) ?></div></td>
          <td style="font-size:12px;"><?= h($r['user'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
