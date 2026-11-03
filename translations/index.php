<?php
// 🌐 भाषांतर व्यवस्थापन — हिंदी / English शब्दकोश किती पूर्ण, पानांवर दिसलेली पण
// भाषांतर नसलेली वाक्यं (सर्वाधिक दिसणारी आधी), आणि कोणतंही भाषांतर इथेच दुरुस्त करणं.
// दुरुस्त्या translation_overrides Table मध्ये — File मधल्या शब्दकोशावर प्राधान्य.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$tableOk = true;
try { $pdo->query("SELECT 1 FROM translation_overrides LIMIT 1"); $pdo->query("SELECT 1 FROM translation_misses LIMIT 1"); }
catch (PDOException $e) { $tableOk = false; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tableOk) {
    $src = i18nNormalize((string)($_POST['source'] ?? ''));
    if ($src !== '' && ($_POST['action'] ?? '') === 'save') {
        $up = $pdo->prepare("INSERT INTO translation_overrides (lang, source_hash, source_text, translated_text, updated_by) VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE translated_text = VALUES(translated_text), updated_by = VALUES(updated_by)");
        $del = $pdo->prepare("DELETE FROM translation_overrides WHERE lang = ? AND source_hash = ?");
        foreach (['hi', 'en'] as $l) {
            $val = trim((string)($_POST[$l] ?? ''));
            if ($val === '') { $del->execute([$l, sha1($src)]); continue; }
            $up->execute([$l, sha1($src), $src, $val, $user['id']]);
        }
        $pdo->prepare("DELETE FROM translation_misses WHERE source_hash = ?")->execute([sha1($src)]);
        logActivity('भाषांतर दुरुस्त केलं', 'translation', null, mb_substr($src, 0, 120));
        $_SESSION['flash'] = 'भाषांतर जतन केलं — लगेच सगळ्या पानांवर लागू.';
    } elseif (($_POST['action'] ?? '') === 'clear_misses') {
        $pdo->exec("TRUNCATE TABLE translation_misses");
        $_SESSION['flash'] = 'दिसलेल्या वाक्यांची यादी रिकामी केली — पानं पुन्हा उघडली की नव्याने भरेल.';
    }
    header('Location: index.php?' . http_build_query(array_intersect_key($_GET, array_flip(['tab', 'q', 'only']))));
    exit;
}

$pageTitle = 'भाषांतर व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';

$catalogFile = __DIR__ . '/../lang/i18n/catalog.php';
$catalog = is_file($catalogFile) ? require $catalogFile : [];
$hi = i18nDict('hi'); $en = i18nDict('en');
$total = count($catalog);
$hiDone = count(array_intersect_key($catalog, $hi));
$enDone = count(array_intersect_key($catalog, $en));
$overrides = $tableOk ? (int)$pdo->query("SELECT COUNT(DISTINCT source_hash) FROM translation_overrides")->fetchColumn() : 0;
$misses = [];
if ($tableOk) {
    $misses = $pdo->query("SELECT source_text, MAX(hits) hits, GROUP_CONCAT(DISTINCT lang) langs, MAX(page) page, MAX(last_seen) last_seen
        FROM translation_misses GROUP BY source_hash, source_text ORDER BY hits DESC LIMIT 150")->fetchAll();
    // नंतर भाषांतर जोडलं गेलं असेल तर यादीतून वगळा
    $misses = array_values(array_filter($misses, fn($m) => !isset($hi[i18nNormalize($m['source_text'])]) || !isset($en[i18nNormalize($m['source_text'])])));
}
$tab = ($_GET['tab'] ?? '') === 'all' ? 'all' : 'seen';
$q = trim((string)($_GET['q'] ?? ''));
$only = $_GET['only'] ?? 'missing';
$rows = [];
if ($tab === 'all') {
    foreach ($catalog as $p => $uses) {
        if ($q !== '' && mb_stripos($p, $q) === false && mb_stripos($hi[$p] ?? '', $q) === false && stripos($en[$p] ?? '', $q) === false) continue;
        if ($only === 'missing' && isset($hi[$p], $en[$p])) continue;
        $rows[] = [$p, $uses];
        if (count($rows) >= 120) break;
    }
}
$pct = fn($a) => $total ? round($a * 100 / $total, 1) : 0;
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">🌐 भाषांतर व्यवस्थापन</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">App चा मजकूर मराठीत आहे; प्रत्येक कर्मचारी वरच्या भाषा-निवडीतून <b>मराठी / हिंदी / English</b> निवडू शकतो (त्याच्या खात्यात लक्षात राहतं). Google Translate नाही — भाषांतर आपल्या स्वतःच्या शब्दकोशातून.</p>

<?php if (!$tableOk): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ भाषांतर-दुरुस्तीसाठी लागणाऱ्या Tables अजून नाहीत — <a href="../system-update/db-update.php">Database Update</a> चालवा. (तोपर्यंत भाषांतर File मधल्या शब्दकोशाने चालू राहतं.)</div>
<?php endif; ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>

<?php
smartTiles([
    ['label' => 'हिंदी', 'value' => $pct($hiDone) . '%', 'sub' => $hiDone . ' / ' . $total . ' वाक्यं', 'tone' => $pct($hiDone) >= 90 ? 'ok' : 'warn'],
    ['label' => 'English', 'value' => $pct($enDone) . '%', 'sub' => $enDone . ' / ' . $total . ' वाक्यं', 'tone' => $pct($enDone) >= 90 ? 'ok' : 'warn'],
    ['label' => 'पानांवर दिसलेली बाकी', 'value' => count($misses), 'sub' => 'वापरकर्त्यांनी पाहिलेली, भाषांतर नसलेली', 'tone' => $misses ? 'warn' : 'ok', 'href' => '?tab=seen'],
    ['label' => 'तुमच्या दुरुस्त्या', 'value' => $overrides, 'sub' => 'File पेक्षा प्राधान्य'],
]);
?>

<div class="tr-tabs mb-3">
  <a href="?tab=seen" class="period-btn <?= $tab === 'seen' ? 'active' : '' ?>">👁️ पानांवर दिसलेली बाकी वाक्यं</a>
  <a href="?tab=all" class="period-btn <?= $tab === 'all' ? 'active' : '' ?>">📚 संपूर्ण शब्दकोश</a>
</div>

<?php if ($tab === 'seen'): ?>
<div class="panel">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">सर्वाधिक दिसलेली, भाषांतर नसलेली वाक्यं</div><div class="sub">हिंदी/English वापरणाऱ्यांनी जी वाक्यं पाहिली पण शब्दकोशात नव्हती — वरची आधी भरा</div></div>
    <?php if ($misses): ?><form method="POST" onsubmit="return confirm('यादी रिकामी करायची?');"><input type="hidden" name="action" value="clear_misses"><button class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;">यादी रिकामी करा</button></form><?php endif; ?>
  </div>
  <?php if (!$misses): ?><div class="sm-empty">🎉 अजून कोणतंही बाकी वाक्य दिसलेलं नाही</div><?php endif; ?>
  <?php foreach ($misses as $m): $p = i18nNormalize($m['source_text']); ?>
    <?php $uses = $m['hits'] . ' वेळा · ' . h($m['page'] ?: '—'); include __DIR__ . '/_row.php'; ?>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="panel">
  <form method="GET" class="tr-search">
    <input type="hidden" name="tab" value="all">
    <input type="search" name="q" value="<?= h($q) ?>" class="form-control-custom" placeholder="मराठी / हिंदी / English मध्ये शोधा…">
    <select name="only" class="form-select-custom" style="max-width:200px;">
      <option value="missing" <?= $only === 'missing' ? 'selected' : '' ?>>फक्त बाकी</option>
      <option value="all" <?= $only === 'all' ? 'selected' : '' ?>>सर्व</option>
    </select>
    <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 16px;">शोधा</button>
  </form>
  <?php if (!$rows): ?><div class="sm-empty"><?= $only === 'missing' && $q === '' ? '🎉 सर्व वाक्यांचं भाषांतर झालं आहे' : 'जुळणारं वाक्य नाही' ?></div><?php endif; ?>
  <?php foreach ($rows as [$p, $u]): $uses = $u . ' Files'; include __DIR__ . '/_row.php'; endforeach; ?>
  <?php if (count($rows) >= 120): ?><div class="sm-empty">पहिली १२० दाखवली — शोध वापरा</div><?php endif; ?>
</div>
<?php endif; ?>

<style>
  .tr-tabs { display: inline-flex; gap: 4px; background: var(--canvas); border-radius: 10px; padding: 4px; }
  .tr-search { display: flex; gap: 8px; padding: 14px 18px; flex-wrap: wrap; }
  .tr-search input[type=search] { flex: 1; min-width: 220px; }
  .tr-row { display: grid; grid-template-columns: 1.2fr 1fr 1fr auto; gap: 10px; align-items: start; padding: 10px 18px; border-top: 1px solid var(--line); }
  .tr-row .src { font-size: 13px; font-weight: 600; }
  .tr-row .src small { display: block; font-weight: 400; font-size: 11px; color: var(--text-dim); }
  .tr-row textarea { font-size: 12.5px; min-height: 38px; }
  .tr-row textarea.empty { border-color: var(--amber); }
  @media (max-width: 900px) { .tr-row { grid-template-columns: 1fr; } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
