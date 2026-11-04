<?php
// Expects $pageTitle and $rootPath to be set by the including page before this file is required.
require_once __DIR__ . '/auth.php';
requireLogin($rootPath ?? '');
$user = currentUser();
$titleOrgName = activeOrgName();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h($titleOrgName) ?> — <?= h($pageTitle ?? 'Command Center') ?></title>

<!-- PWA: installable "Add to Home Screen" support -->
<link rel="manifest" href="<?= $rootPath ?? '' ?>manifest.php">
<meta name="theme-color" content="#138A80">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="E-Admin">
<link rel="apple-touch-icon" href="<?= $rootPath ?? '' ?>icon.php?size=192">
<link rel="icon" type="image/png" sizes="192x192" href="<?= $rootPath ?? '' ?>icon.php?size=192">

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;600;700&family=Inter:wght@400;500;600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= $rootPath ?? '' ?>assets/css/eowms.css?v=4">
<link rel="stylesheet" href="<?= $rootPath ?? '' ?>assets/css/eowms-advance.css?v=5">
<link rel="stylesheet" href="<?= $rootPath ?? '' ?>assets/css/eowms-ui.css?v=7">
<link rel="stylesheet" href="<?= $rootPath ?? '' ?>assets/css/eowms-theme.css?v=1">
<script>
  // Theme Studio च्या निवडी — Body Render होण्याआधीच लागू, नाहीतर रंग "Flash" होतात.
  (function () {
    var h = document.documentElement, g = function (k) { try { return localStorage.getItem('eowms_' + k); } catch (e) { return null; } };
    var t = g('theme');
    if (t === 'dark' || (t === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches)) h.setAttribute('data-theme', 'dark');
    ['accent', 'fs', 'density', 'sidebar', 'motion'].forEach(function (k) { var v = g(k); if (v) h.setAttribute('data-' + k, v); });
  })();
</script>
</head>
<?php
  $__currentDirForModule = basename(dirname($_SERVER['SCRIPT_NAME']));
  if ($__currentDirForModule === '.' || $__currentDirForModule === '/') { $__currentDirForModule = ''; }
?>
<body data-root-path="<?= h($rootPath ?? '') ?>" data-module="<?= h($__currentDirForModule) ?>">

<?php
// Super Admin ला — Maintenance Mode चालू असताना, प्रत्येक पानावर हा
// Banner दिसतो, जेणेकरून ते Site पुन्हा सुरू करायला विसरणार नाहीत.
if (hasRole('super_admin')) {
    try {
        $maintBannerStmt = $pdo->prepare("SELECT maintenance_mode FROM organizations WHERE id = ?");
        $maintBannerStmt->execute([activeOrgId()]);
        if ((int)($maintBannerStmt->fetch()['maintenance_mode'] ?? 0) === 1) {
            echo '<div style="background:#B23A48;color:#fff;text-align:center;padding:9px 16px;font-size:12.5px;font-weight:600;position:sticky;top:0;z-index:999;">
                🛠️ Site सध्या Maintenance Mode मध्ये आहे — इतर कोणालाही दिसत नाही.
                <a href="' . ($rootPath ?? '') . 'system-update/maintenance.php" style="color:#fff;text-decoration:underline;margin-left:10px;">Site पुन्हा सुरू करा →</a>
            </div>';
        }
    } catch (PDOException $e) { /* column not migrated yet */ }
}
?>

<?php require __DIR__ . '/sidebar.php'; ?>

<div class="main-wrap">
  <div class="topbar">
    <button class="icon-btn d-lg-none" id="mobileMenuBtn"><i class="fa-solid fa-bars"></i></button>
    <form class="global-search" action="<?= $rootPath ?? '' ?>search.php" method="GET" style="display:flex;cursor:pointer;" onclick="event.preventDefault(); eowmsOpenCommandPalette();">
      <i class="fa-solid fa-magnifying-glass"></i>
      <input type="text" name="q" placeholder="<?= h(t('search_placeholder')) ?>" readonly style="border:none;background:transparent;outline:none;flex:1;font-size:13px;color:var(--text);font-family:inherit;cursor:pointer;">
      <span class="cmdk-trigger-hint">Ctrl+K</span>
    </form>
    <button type="button" class="icon-btn theme-toggle-btn" onclick="eowmsToggleTheme()" title="Dark/Light Mode">
      <i class="fa-solid fa-sun theme-icon-sun"></i>
      <i class="fa-solid fa-moon theme-icon-moon"></i>
    </button>
    <button type="button" class="icon-btn" data-theme-studio title="Theme Studio — रंग व मांडणी" aria-label="Theme Studio">
      <i class="fa-solid fa-palette"></i>
    </button>
    <a href="<?= $rootPath ?? '' ?>help/index.php" class="icon-btn" title="मदत / Help">
      <i class="fa-solid fa-circle-question"></i>
    </a>
    <?php
      $unreadCount = 0;
      $recentNotifs = [];
      try {
          $ncStmt = $pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id = ? AND is_read = 0");
          $ncStmt->execute([$user['id']]);
          $unreadCount = $ncStmt->fetch()['c'];
          $nStmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 8");
          $nStmt->execute([$user['id']]);
          $recentNotifs = $nStmt->fetchAll();
      } catch (PDOException $e) { /* notifications table not migrated yet */ }
    ?>
    <div class="dropdown">
      <button class="icon-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" style="position:relative;">
        <i class="fa-regular fa-bell"></i>
        <?php if ($unreadCount > 0): ?><span class="badge-dot"></span><?php endif; ?>
      </button>
      <div class="dropdown-menu dropdown-menu-end" style="width:320px;max-height:400px;overflow-y:auto;padding:8px;">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:6px 10px;">
          <strong style="font-size:13px;"><?= h(t('s93')) ?></strong>
          <?php if ($unreadCount > 0): ?>
            <form method="POST" action="<?= $rootPath ?? '' ?>notifications/mark-all-read.php" style="margin:0;">
              <button type="submit" style="border:none;background:none;padding:0;font-size:11px;color:var(--rail);cursor:pointer;"><?= h(t('s293')) ?></button>
            </form>
          <?php endif; ?>
        </div>
        <?php if (empty($recentNotifs)): ?>
          <div style="padding:16px;text-align:center;color:var(--text-dim);font-size:12.5px;"><?= h(t('s94')) ?></div>
        <?php endif; ?>
        <?php foreach ($recentNotifs as $n): ?>
          <a href="<?= $rootPath ?? '' ?>notifications/open.php?id=<?= (int)$n['id'] ?>" style="display:block;padding:9px 10px;border-radius:8px;text-decoration:none;color:inherit;<?= $n['is_read'] ? '' : 'background:var(--rail-soft);' ?>">
            <div style="font-size:12.5px;font-weight:600;color:var(--ink);"><?= h($n['title']) ?></div>
            <?php if ($n['message']): ?><div style="font-size:11.5px;color:var(--text-dim);"><?= h($n['message']) ?></div><?php endif; ?>
            <div style="font-size:10px;color:var(--text-dim);margin-top:2px;"><?= date('d M, h:i A', strtotime($n['created_at'])) ?></div>
          </a>
        <?php endforeach; ?>
        <div style="text-align:center;padding:8px;"><a href="<?= $rootPath ?? '' ?>notifications/index.php" style="font-size:12px;color:var(--rail);font-weight:600;"><?= h(t('s294')) ?></a></div>
      </div>
    </div>
    <?= i18nSwitcher('style="border:1px solid var(--line);border-radius:9px;padding:0 10px;height:38px;font-size:12.5px;color:var(--text-dim);background:var(--canvas);cursor:pointer;"') ?>
    <a href="<?= $rootPath ?? '' ?>logout.php" class="icon-btn" title="Logout"><i class="fa-solid fa-right-from-bracket"></i></a>
  </div>

  <?php /* 🌐 भाषा — includes/i18n.php (Google Translate शिवाय, स्वतःचा शब्दकोश) */ ?>
  <?= i18nClientScript() ?>

  <div class="content">
    <?php if (!empty($_SESSION['flash'])):
      // बरीच Pages चुकीचे संदेशही 'flash' मधूनच पाठवतात — असा संदेश हिरव्या ऐवजी पिवळ्या Banner मध्ये
      $flashIsError = !empty($_SESSION['flash_error']) || preg_match('/^(⚠️|❌)|अवैध|त्रुटी|सापडल[ाीे] नाही|झाल[ाेंी]* नाही|झाले नाहीत|करता येत नाही|बदलता येत नाही|परवानगी नाही|आवश्यक आहे/u', $_SESSION['flash']); ?>
      <div class="alert <?= $flashIsError ? 'alert-warning' : 'alert-success' ?>" style="border-radius:10px;font-size:13.5px;"><?= h($_SESSION['flash']) ?></div>
      <?php unset($_SESSION['flash'], $_SESSION['flash_error']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['budget_warning'])): // विभाग Finance — नवीन खर्चाने Budget ओलांडलं ?>
      <div class="alert alert-warning" style="border-radius:10px;font-size:13.5px;"><?= h($_SESSION['budget_warning']) ?></div>
      <?php unset($_SESSION['budget_warning']); ?>
    <?php endif; ?>
