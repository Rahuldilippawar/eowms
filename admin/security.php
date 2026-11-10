<?php
// 🚨 सुरक्षा इशारे — चुकीचे Password प्रयत्न, Lock झालेली खाती (Unlock), रात्री-अपरात्री Login, अडवलेले बदल, Data Download, 2-Step नसलेले Admin
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/two-factor.php';
$orgId = activeOrgId();
$days = in_array((int)($_GET['days'] ?? 7), [1, 7, 30], true) ? (int)($_GET['days'] ?? 7) : 7;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unlock') {
    $uid = (int)($_POST['uid'] ?? 0);
    $st = $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ? AND organization_id = ?");
    $st->execute([$uid, $orgId]);
    if ($st->rowCount()) { logActivity('खातं Unlock केलं', 'user', $uid); $_SESSION['flash'] = '✅ खातं Unlock केलं — आता Login करता येईल.'; }
    header('Location: security.php?days=' . $days);
    exit;
}

$q = function ($sql, $p) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (PDOException $e) { return []; } };
$since = date('Y-m-d H:i:s', strtotime("-$days days"));

$locked = $q("SELECT u.id, u.name, u.email, u.failed_login_attempts, u.locked_until, d.name_local dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND ((u.locked_until IS NOT NULL AND u.locked_until > NOW()) OR u.failed_login_attempts >= 3) ORDER BY u.locked_until DESC, u.failed_login_attempts DESC", [$orgId]);
$failed = $q("SELECT actor_name, COUNT(*) c, MAX(created_at) last_at, MAX(details) det, MAX(entity_id) uid FROM activity_logs
    WHERE organization_id = ? AND action = 'Login अयशस्वी' AND created_at >= ? GROUP BY actor_name ORDER BY c DESC LIMIT 30", [$orgId, $since]);
$night = $q("SELECT a.user_id, a.actor_name, a.created_at, a.details FROM activity_logs a
    WHERE a.organization_id = ? AND a.action LIKE 'Login केलं%' AND a.created_at >= ? AND (HOUR(a.created_at) >= 23 OR HOUR(a.created_at) < 5) ORDER BY a.created_at DESC LIMIT 40", [$orgId, $since]);
$blocked = $q("SELECT actor_name, user_id, COUNT(*) c, MAX(created_at) last_at, GROUP_CONCAT(DISTINCT SUBSTRING_INDEX(details, ' ', 1) SEPARATOR ', ') pages FROM activity_logs
    WHERE organization_id = ? AND action LIKE 'Edit/Delete अडवलं%' AND created_at >= ? GROUP BY user_id, actor_name ORDER BY c DESC LIMIT 20", [$orgId, $since]);
$downloads = $q("SELECT actor_name, action, created_at, details FROM activity_logs
    WHERE organization_id = ? AND created_at >= ? AND (action LIKE '%Backup%Download%' OR action LIKE '%Download केल%' OR action LIKE '%Export%' OR action LIKE 'Recycle%' OR action LIKE '%सगळीकडून Logout%' OR action LIKE 'View as सुरू%')
    ORDER BY created_at DESC LIMIT 30", [$orgId, $since]);
$admins = $q("SELECT u.id, u.name, u.role, u.tfa_method, COALESCE(rd.category, IF(u.role='super_admin','super_admin','staff')) cat FROM users u LEFT JOIN role_definitions rd ON rd.role_key = u.role
    WHERE u.organization_id = ? AND u.is_active = 1 AND (u.role = 'super_admin' OR rd.category = 'management')", [$orgId]);
$noTfa = array_values(array_filter($admins, fn($a) => !tfaEnabled($a)));
$totalFailed = array_sum(array_column($failed, 'c'));

$pageTitle = 'सुरक्षा इशारे';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$ip = fn($d) => preg_match('/IP:\s*([0-9a-fA-F\.:]+)/', (string)$d, $m) ? $m[1] : '—';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-1">🚨 सुरक्षा इशारे</div>
  <div class="rl-tabs"><?php foreach ([1 => 'आज', 7 => '7 दिवस', 30 => '30 दिवस'] as $k => $l): ?><a class="period-btn <?= $days === $k ? 'active' : '' ?>" href="?days=<?= $k ?>"><?= $l ?></a><?php endforeach; ?></div>
</div>
<?= saNav('security') ?>
<?php
smartTiles([
    ['label' => '🔒 Lock / धोक्यातली खाती', 'value' => count($locked), 'sub' => 'चुकीचे 3+ प्रयत्न', 'tone' => $locked ? 'bad' : 'ok'],
    ['label' => '❌ अयशस्वी Login', 'value' => $totalFailed, 'sub' => count($failed) . ' Email वरून', 'tone' => $totalFailed > 20 ? 'bad' : ($totalFailed ? 'warn' : 'ok')],
    ['label' => '🌙 रात्री Login', 'value' => count($night), 'sub' => 'रात्री 11 ते पहाटे 5', 'tone' => $night ? 'warn' : ''],
    ['label' => '⛔ अडवलेले बदल', 'value' => array_sum(array_column($blocked, 'c')), 'sub' => 'Edit/Delete चे प्रयत्न', 'tone' => $blocked ? 'warn' : ''],
    ['label' => '🔐 2-Step नसलेले', 'value' => count($noTfa), 'sub' => 'Admin / Management', 'tone' => $noTfa ? 'warn' : 'ok'],
]);
?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">🔒 Lock / धोक्यातली खाती</div><div class="sub">5 चुकीचे Password = 15 मिनिटं Lock. इथून लगेच Unlock</div></div>
      <?php if (!$locked): ?><div class="sm-empty">एकही खातं Lock नाही ✓</div><?php else: foreach ($locked as $l): $isLocked = $l['locked_until'] && strtotime($l['locked_until']) > time(); ?>
        <div class="sec-row"><div><b><?= h($l['name']) ?></b><small><?= h($l['dept'] ?: '—') ?> · <?= (int)$l['failed_login_attempts'] ?> चुकीचे प्रयत्न<?= $isLocked ? ' · <b style="color:var(--rose);">Lock — ' . date('h:i A', strtotime($l['locked_until'])) . ' पर्यंत</b>' : '' ?></small></div>
          <form method="POST"><input type="hidden" name="action" value="unlock"><input type="hidden" name="uid" value="<?= (int)$l['id'] ?>"><button class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:12px;">🔓 Unlock</button></form></div>
      <?php endforeach; endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">❌ अयशस्वी Login</div><div class="sub">एकाच Email वर वारंवार = कोणी अंदाजाने Password टाकत असेल</div></div>
      <?php if (!$failed): ?><div class="sm-empty">अयशस्वी Login नाही ✓</div><?php else: ?>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>Email</th><th>किती वेळा</th><th>शेवटचा IP</th><th>शेवटी</th></tr></thead><tbody>
        <?php foreach ($failed as $f): ?><tr><td style="font-size:12.5px;"><?= h($f['actor_name']) ?><?= $f['uid'] ? '' : ' <span class="status-chip pending" title="असं खातं नाही">अज्ञात</span>' ?></td><td data-sort="<?= (int)$f['c'] ?>"><b style="color:<?= $f['c'] >= 5 ? 'var(--rose)' : 'inherit' ?>;"><?= (int)$f['c'] ?></b></td><td class="font-mono" style="font-size:12px;"><?= h($ip($f['det'])) ?></td><td style="font-size:12px;"><?= date('d/m h:i A', strtotime($f['last_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">🌙 रात्री-अपरात्री Login</div><div class="sub">रात्री 11 ते पहाटे 5 — अपेक्षित नसेल तर Session बंद करा</div></div>
      <?php if (!$night): ?><div class="sm-empty">रात्री Login नाही ✓</div><?php else: ?>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>व्यक्ती</th><th>वेळ</th><th>IP</th></tr></thead><tbody>
        <?php foreach ($night as $n): ?><tr><td><?= h($n['actor_name']) ?></td><td style="font-size:12px;"><?= date('d/m, h:i A', strtotime($n['created_at'])) ?></td><td class="font-mono" style="font-size:12px;"><?= h($ip($n['details'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
      <div style="padding:8px 16px 14px;"><a href="sessions.php" style="font-size:12.5px;color:var(--rail);">🔐 Active Sessions पाहा →</a></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">⛔ अडवलेले बदल / हटवण्याचे प्रयत्न</div><div class="sub">नोंदी बदलणं/हटवणं फक्त Super Admin — इतरांनी प्रयत्न केला</div></div>
      <?php if (!$blocked): ?><div class="sm-empty">असा प्रयत्न नाही ✓</div><?php else: ?>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>व्यक्ती</th><th>किती</th><th>कोणती पानं</th></tr></thead><tbody>
        <?php foreach ($blocked as $b): ?><tr><td><?= h($b['actor_name']) ?></td><td data-sort="<?= (int)$b['c'] ?>"><b><?= (int)$b['c'] ?></b></td><td style="font-size:11.5px;"><?= h(mb_strimwidth((string)$b['pages'], 0, 90, '…')) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">🔐 2-Step Login नसलेले Admin / Management</div><div class="sub">यांच्याकडे जास्त अधिकार — Password चोरीला गेला तरी सुरक्षित राहावं</div></div>
      <?php if (!$noTfa): ?><div class="sm-empty">सगळ्यांचं 2-Step चालू आहे ✓</div><?php else: foreach ($noTfa as $a): ?>
        <div class="sec-row"><div><b><?= h($a['name']) ?></b><small><?= h($a['role']) ?></small></div><a href="../staff/profile.php?id=<?= (int)$a['id'] ?>" style="font-size:12px;color:var(--rail);">प्रोफाइल →</a></div>
      <?php endforeach; endif; ?>
      <div style="padding:8px 16px 14px;"><a href="../account/two-factor.php" style="font-size:12.5px;color:var(--rail);">2-Step धोरण (कोणासाठी अनिवार्य) →</a></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel h-100"><div class="panel-head"><div class="title">📥 संवेदनशील कृती</div><div class="sub">Backup Download, Export, View as, Force Logout, Recycle Bin</div></div>
      <?php if (!$downloads): ?><div class="sm-empty">नोंद नाही</div><?php else: ?>
      <div class="table-responsive"><table class="rtable"><thead><tr><th>कोण</th><th>काय</th><th>कधी</th></tr></thead><tbody>
        <?php foreach ($downloads as $d): ?><tr><td><?= h($d['actor_name']) ?></td><td style="font-size:12px;"><?= h($d['action']) ?></td><td style="font-size:12px;"><?= date('d/m h:i A', strtotime($d['created_at'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
<style>
  .rl-tabs { display: inline-flex; gap: 4px; background: var(--canvas); border-radius: 10px; padding: 4px; } .rl-tabs .period-btn { border: 0; text-decoration: none; }
  .sec-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 9px 16px; border-bottom: 1px solid var(--line); }
  .sec-row b { display: block; font-size: 13px; } .sec-row small { font-size: 11.5px; color: var(--text-dim); } .sec-row form { margin: 0; }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
