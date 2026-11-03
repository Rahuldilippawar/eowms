<?php
$pageTitle = 'अ‍ॅक्टिव्हिटी लॉग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/smart-insights.php';

$orgId = activeOrgId();
$validDate = fn($d) => ($x = DateTime::createFromFormat('!Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-6 days'));
$to = $validDate($_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$q = trim((string)($_GET['q'] ?? ''));
$actor = (int)($_GET['user'] ?? 0);
$kind = (string)($_GET['kind'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 100;

// संवेदनशील कृती — हटवणे, Permissions, Backup, Restore, Password, संस्था/Maintenance
$sensitiveLike = ['%हटव%', '%Delete%', '%डिलीट%', '%Permission%', '%Backup%', '%Restore%', '%पासवर्ड%', '%Maintenance%', '%निष्क्रिय%', '%Clear%'];
$kinds = [
    '' => 'सर्व',
    'login' => '🔐 Login',
    'failed' => '⛔ अयशस्वी Login',
    'sensitive' => '⚠ संवेदनशील',
];

$where = "organization_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)";
$params = [$orgId, $from, $to];
if ($q !== '') { $where .= " AND (action LIKE ? OR details LIKE ? OR actor_name LIKE ?)"; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
if ($actor) { $where .= " AND user_id = ?"; $params[] = $actor; }
if ($kind === 'login') { $where .= " AND (action LIKE 'Login%' OR action LIKE 'Logout%')"; }
if ($kind === 'failed') { $where .= " AND action LIKE 'Login अयशस्वी%'"; }
if ($kind === 'sensitive') { $where .= " AND (" . implode(' OR ', array_fill(0, count($sensitiveLike), 'action LIKE ?')) . ")"; $params = array_merge($params, $sensitiveLike); }

$logs = []; $total = 0; $byDay = []; $topActors = []; $topActions = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE $where"); $st->execute($params); $total = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT * FROM activity_logs WHERE $where ORDER BY created_at DESC, id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($params); $logs = $st->fetchAll();
    $st = $pdo->prepare("SELECT DATE(created_at) d, COUNT(*) c FROM activity_logs WHERE $where GROUP BY DATE(created_at)"); $st->execute($params);
    foreach ($st->fetchAll() as $r) { $byDay[$r['d']] = (int)$r['c']; }
    $st = $pdo->prepare("SELECT COALESCE(actor_name, '—') n, user_id, COUNT(*) c FROM activity_logs WHERE $where GROUP BY actor_name, user_id ORDER BY c DESC LIMIT 6"); $st->execute($params); $topActors = $st->fetchAll();
    $st = $pdo->prepare("SELECT action n, COUNT(*) c FROM activity_logs WHERE $where GROUP BY action ORDER BY c DESC LIMIT 6"); $st->execute($params); $topActions = $st->fetchAll();
} catch (PDOException $e) {}

// आजचे / २४ तासांचे सुरक्षा-आकडे (Filter पासून स्वतंत्र)
$stat = ['today' => 0, 'users_today' => 0, 'failed24' => 0, 'sens7' => 0];
$failedIps = [];
try {
    $st = $pdo->prepare("SELECT COUNT(*) c, COUNT(DISTINCT user_id) u FROM activity_logs WHERE organization_id = ? AND created_at >= CURDATE()"); $st->execute([$orgId]);
    $r = $st->fetch(); $stat['today'] = (int)$r['c']; $stat['users_today'] = (int)$r['u'];
    $st = $pdo->prepare("SELECT details FROM activity_logs WHERE organization_id = ? AND action LIKE 'Login अयशस्वी%' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"); $st->execute([$orgId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $d) { $stat['failed24']++; if (preg_match('/IP:\s*([0-9a-fA-F:.]+)/', (string)$d, $m)) { $failedIps[$m[1]] = ($failedIps[$m[1]] ?? 0) + 1; } }
    $st = $pdo->prepare("SELECT COUNT(*) FROM activity_logs WHERE organization_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND (" . implode(' OR ', array_fill(0, count($sensitiveLike), 'action LIKE ?')) . ")");
    $st->execute(array_merge([$orgId], $sensitiveLike)); $stat['sens7'] = (int)$st->fetchColumn();
} catch (PDOException $e) {}
arsort($failedIps);

$alerts = [];
foreach (array_slice($failedIps, 0, 3, true) as $ip => $n) {
    if ($n >= 5) $alerts[] = ['bad', "IP {$ip} वरून २४ तासांत {$n} अयशस्वी Login प्रयत्न — कोणी पासवर्ड अंदाजे टाकत असेल.", '?kind=failed&from=' . date('Y-m-d', strtotime('-1 day')) . '&q=' . urlencode($ip)];
}
if ($stat['failed24'] >= 10 && !$alerts) $alerts[] = ['warn', "२४ तासांत {$stat['failed24']} अयशस्वी Login प्रयत्न.", '?kind=failed&from=' . date('Y-m-d', strtotime('-1 day'))];
if ($stat['sens7']) $alerts[] = ['info', "गेल्या ७ दिवसांत {$stat['sens7']} संवेदनशील कृती (हटवणे, Permissions, Backup, पासवर्ड…) — एकदा नजर टाका.", '?kind=sensitive&from=' . date('Y-m-d', strtotime('-6 days'))];

$users = $pdo->prepare("SELECT DISTINCT u.id, u.name FROM activity_logs al JOIN users u ON u.id = al.user_id WHERE al.organization_id = ? ORDER BY u.name");
$users->execute([$orgId]); $users = $users->fetchAll();
$pages = max(1, (int)ceil($total / $per));
$qsBase = fn($over) => '?' . http_build_query(array_merge(['from' => $from, 'to' => $to, 'q' => $q, 'user' => $actor ?: '', 'kind' => $kind], $over));
$isSensitive = function ($a) use ($sensitiveLike) {
    foreach ($sensitiveLike as $l) { if (mb_stripos($a, trim($l, '%')) !== false) return true; }
    return false;
};
// दिवसनिहाय Bar
$days = []; for ($d = strtotime($from); $d <= strtotime($to) && count($days) < 62; $d += 86400) { $days[date('Y-m-d', $d)] = $byDay[date('Y-m-d', $d)] ?? 0; }
$maxDay = max(1, max($days ?: [0]));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= h(t('s407')) ?></div>
  <a href="login-activity.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-right-to-bracket me-1"></i>🔐 फक्त Login/Logout बघा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;"><?= h(t('s408')) ?></p>

<?php
smartTiles([
    ['label' => 'आजच्या नोंदी', 'value' => $stat['today'], 'sub' => $stat['users_today'] . ' व्यक्ती सक्रिय', 'href' => $qsBase(['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'kind' => '', 'page' => 1])],
    ['label' => 'अयशस्वी Login (२४ ता.)', 'value' => $stat['failed24'], 'sub' => count($failedIps) . ' वेगवेगळे IP', 'tone' => $stat['failed24'] >= 10 ? 'bad' : ($stat['failed24'] ? 'warn' : 'ok'), 'href' => '?kind=failed&from=' . date('Y-m-d', strtotime('-1 day'))],
    ['label' => 'संवेदनशील (७ दि.)', 'value' => $stat['sens7'], 'sub' => 'हटवणे / Permissions / Backup', 'tone' => $stat['sens7'] ? 'warn' : 'ok', 'href' => '?kind=sensitive&from=' . date('Y-m-d', strtotime('-6 days'))],
    ['label' => 'निवडलेल्या कालावधीत', 'value' => $total, 'sub' => date('d M', strtotime($from)) . ' — ' . date('d M', strtotime($to))],
]);
smartAlerts($alerts);
?>

<div class="panel mb-3">
  <form method="GET" class="al-filter">
    <label>शोधा<input type="search" name="q" value="<?= h($q) ?>" class="form-control-custom" placeholder="कृती, तपशील, नाव, IP…"></label>
    <label>व्यक्ती
      <select name="user" class="form-select-custom"><option value="">सर्व</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" <?= (int)$u['id'] === $actor ? 'selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <label>प्रकार
      <select name="kind" class="form-select-custom"><?php foreach ($kinds as $k => $l): ?><option value="<?= $k ?>" <?= $k === $kind ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    </label>
    <label>पासून<input type="date" name="from" value="<?= h($from) ?>" class="form-control-custom"></label>
    <label>पर्यंत<input type="date" name="to" value="<?= h($to) ?>" class="form-control-custom"></label>
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">दाखवा</button>
    <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 14px;">रीसेट</a>
  </form>
  <div class="al-quick">
    <?php foreach (['आज' => [date('Y-m-d'), date('Y-m-d')], '७ दिवस' => [date('Y-m-d', strtotime('-6 days')), date('Y-m-d')], '३० दिवस' => [date('Y-m-d', strtotime('-29 days')), date('Y-m-d')], 'हा महिना' => [date('Y-m-01'), date('Y-m-d')]] as $l => [$f, $t]): ?>
      <a href="<?= h($qsBase(['from' => $f, 'to' => $t, 'page' => 1])) ?>" class="period-btn <?= $from === $f && $to === $t ? 'active' : '' ?>"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">📈 दिवसनिहाय हालचाल</div><div class="sub">निवडलेल्या Filter प्रमाणे</div></div>
      <div class="al-days">
        <?php foreach ($days as $d => $c): ?>
          <a class="al-day" href="<?= h($qsBase(['from' => $d, 'to' => $d, 'page' => 1])) ?>" title="<?= date('d M Y', strtotime($d)) ?> — <?= $c ?> नोंदी"><i style="height:<?= max(2, round($c * 100 / $maxDay)) ?>%;"></i><span><?= date('d', strtotime($d)) ?></span></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-3 col-md-6">
    <?php smartBars('👤 सर्वाधिक सक्रिय', '', array_map(fn($r) => ['name' => $r['n'], 'value' => (int)$r['c']], $topActors)); ?>
  </div>
  <div class="col-lg-3 col-md-6">
    <?php smartBars('🏷️ सर्वाधिक कृती', '', array_map(fn($r) => ['name' => $r['n'], 'value' => (int)$r['c']], $topActions), 'var(--amber)'); ?>
  </div>
</div>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s409')) ?></th><th><?= h(t('s410')) ?></th><th><?= h(t('s411')) ?></th><th><?= h(t('s88')) ?></th></tr></thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s34')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): $sens = $isSensitive((string)$log['action']); $fail = mb_strpos((string)$log['action'], 'अयशस्वी') !== false; ?>
        <tr style="<?= $fail ? 'background:rgba(194,51,63,.05);' : ($sens ? 'background:rgba(201,127,29,.06);' : '') ?>">
          <td class="font-mono" style="font-size:12px;white-space:nowrap;" data-sort="<?= strtotime($log['created_at']) ?>"><?= date('d M Y, h:i A', strtotime($log['created_at'])) ?></td>
          <td style="font-weight:500;">
            <?php if ($log['user_id']): ?><a href="<?= h($qsBase(['user' => (int)$log['user_id'], 'page' => 1])) ?>" style="color:inherit;" title="फक्त यांच्या नोंदी"><?= h($log['actor_name'] ?? '—') ?></a><?php else: ?><?= h($log['actor_name'] ?? '—') ?><?php endif; ?>
          </td>
          <td><?= $fail ? '⛔ ' : ($sens ? '⚠ ' : '') ?><?= h($log['action']) ?><?= $log['entity_type'] ? ' <span style="font-size:10.5px;color:var(--text-dim);">· ' . h($log['entity_type']) . '</span>' : '' ?></td>
          <td style="font-size:12.5px;color:var(--text-dim);word-break:break-word;"><?= h($log['details'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="al-pager">
      <span><?= number_format($total) ?> पैकी <?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?></span>
      <?php if ($page > 1): ?><a href="<?= h($qsBase(['page' => $page - 1])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;">← मागील</a><?php endif; ?>
      <span class="font-mono"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a href="<?= h($qsBase(['page' => $page + 1])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;">पुढील →</a><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<style>
  .al-filter { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding: 14px 18px 6px; }
  .al-filter label { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: var(--text-dim); font-weight: 600; }
  .al-filter input[type=search] { min-width: 220px; }
  .al-quick { display: flex; gap: 4px; padding: 6px 18px 14px; flex-wrap: wrap; }
  .al-days { display: flex; align-items: flex-end; gap: 3px; height: 150px; padding: 14px 18px 8px; overflow-x: auto; }
  .al-day { flex: 1; min-width: 14px; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; text-decoration: none; }
  .al-day i { display: block; width: 100%; background: var(--rail); border-radius: 4px 4px 0 0; opacity: .85; }
  .al-day:hover i { opacity: 1; background: var(--amber); }
  .al-day span { font-size: 9.5px; color: var(--text-dim); margin-top: 3px; }
  .al-pager { display: flex; justify-content: flex-end; align-items: center; gap: 10px; padding: 12px 18px; font-size: 12.5px; color: var(--text-dim); }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
