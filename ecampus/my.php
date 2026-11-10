<?php
// 🎓 माझी E-Campus कामं — नेमलेला कर्मचारी महिन्याची प्रगती (% + नोंद) भरतो — चालू किंवा मागचे 12 महिने
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
$me = currentUser();
$orgId = activeOrgId();
// कोणता महिना — चालू किंवा मागच्या 12 पैकी (POST मध्ये फॉर्मसोबत येतो)
$period = (string)($_POST['period'] ?? $_GET['month'] ?? date('Y-m'));
if (!ecampusEditablePeriod($period)) { $period = date('Y-m'); }
$isCurrent = $period === date('Y-m');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $taskId = (int)($_POST['task_id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM ecampus_tasks WHERE id = ? AND organization_id = ? AND is_active = 1");
    $st->execute([$taskId, $orgId]);
    $task = $st->fetch();
    // फक्त ज्याला काम नेमलं तोच (किंवा Super Admin) भरू शकतो
    if (!$task || ((int)$task['user_id'] !== (int)$me['id'] && !ecampusCanManage()) || $task['access_type'] === 'view_only') {
        http_response_code(403);
        die('हे काम तुम्हाला नेमलेलं नाही.');
    }
    $pct = (int)($_POST['percent'] ?? 0);
    ecampusSave($pdo, $taskId, $period, $pct, $_POST['notes'] ?? '', $me['id']);
    logActivity('E-Campus प्रगती भरली', 'ecampus_task', $taskId, ecampusPeriodLabel($period) . ' · ' . $task['section'] . ' — ' . $pct . '%');
    $_SESSION['ec_flash'] = '✅ जतन झालं (' . ecampusPeriodLabel($period) . ') — ' . $task['section'] . ': ' . $pct . '%';
    $qs = $isCurrent ? '' : '?month=' . $period;
    header('Location: ' . ((($_POST['back'] ?? '') === 'dash' && ecampusCanViewAll()) ? 'index.php' . $qs : 'my.php' . $qs . '#t' . $taskId));
    exit;
}

$rows = ecampusRows($pdo, $orgId, $period, $me['id']);
if (!$rows && ecampusCanViewAll()) { header('Location: index.php'); exit; }
$flash = $_SESSION['ec_flash'] ?? null; unset($_SESSION['ec_flash']);
$hist = $pdo->prepare("SELECT u.percent, u.notes, u.created_at, us.name FROM ecampus_updates u LEFT JOIN users us ON us.id = u.user_id WHERE u.task_id = ? AND u.period = ? ORDER BY u.created_at DESC, u.id DESC LIMIT 5");
$work = array_filter($rows, fn($r) => $r['access_type'] === 'work');
$myAvg = $work ? (int)round(array_sum(array_column($work, 'percent')) / count($work)) : null;

$pageTitle = 'माझी E-Campus कामं';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .ec-card{border:1px solid var(--line);border-radius:16px;background:var(--surface,#fff);padding:16px 18px;margin-bottom:14px;box-shadow:0 6px 18px -14px rgba(20,16,50,.35);scroll-margin-top:90px;}
  .ec-top{display:flex;gap:14px;align-items:flex-start;}
  .ec-ring{--p:0;width:74px;height:74px;border-radius:50%;flex-shrink:0;display:grid;place-items:center;background:conic-gradient(var(--c) calc(var(--p)*1%), var(--canvas) 0);}
  .ec-ring span{width:58px;height:58px;border-radius:50%;background:var(--surface,#fff);display:grid;place-items:center;font-weight:800;font-size:17px;}
  .ec-chip{display:inline-block;font-size:11.5px;font-weight:600;padding:2px 9px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);margin:0 4px 4px 0;}
  .ec-task{font-size:14px;line-height:1.6;margin:4px 0 0;white-space:pre-line;}
  .ec-st{font-size:11.5px;font-weight:700;padding:2px 9px;border-radius:99px;color:#fff;}
  .ec-form{margin-top:12px;padding-top:12px;border-top:1px dashed var(--line);}
  .ec-range{width:100%;accent-color:var(--rail);height:28px;}
  .ec-quick{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px;} .ec-quick button{border:1px solid var(--line);background:var(--canvas);border-radius:8px;font-size:12.5px;font-weight:700;padding:5px 12px;cursor:pointer;}
  .ec-quick button:hover{border-color:var(--rail);color:var(--rail);}
  .ec-hist{font-size:12px;color:var(--text-dim);margin-top:10px;} .ec-hist div{padding:3px 0;border-bottom:1px dotted var(--line);}
  .ec-hero{display:flex;gap:16px;align-items:center;padding:16px 20px;border-radius:16px;background:linear-gradient(135deg,#191C3A,#0E1024);color:#fff;margin-bottom:16px;}
  .ec-hero .ec-ring span{background:#191C3A;color:#fff;}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">🎓 माझी E-Campus कामं</div>
  <div class="d-flex gap-2 align-items-center flex-wrap">
    <form method="GET" style="margin:0;"><select name="month" class="form-select-custom" onchange="this.form.submit()" aria-label="महिना" style="min-width:170px;">
      <?php foreach (ecampusEditableMonths() as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?><?= $m === date('Y-m') ? ' (चालू)' : '' ?></option><?php endforeach; ?>
    </select></form>
    <?php if (ecampusCanViewAll()): ?><a href="index.php<?= $isCurrent ? '' : '?month=' . h($period) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-chart-pie me-1"></i>Dashboard</a><?php endif; ?>
  </div>
</div>
<?php if (!$isCurrent): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">📅 तुम्ही <b><?= h(ecampusPeriodLabel($period)) ?></b> (मागचा महिना) भरत आहात — त्या महिन्यात झालेलं काम पाहून % आणि नोंद भरा. <a href="my.php">चालू महिन्याकडे →</a></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);"><div style="font-size:36px;">🎓</div>तुम्हाला अजून E-Campus चं कोणतंही काम नेमलेलं नाही.</div>
<?php else: ?>
  <div class="ec-hero">
    <div class="ec-ring" style="--p:<?= (int)$myAvg ?>;--c:#F0932B;"><span><?= $myAvg === null ? '—' : $myAvg . '%' ?></span></div>
    <div><div style="font-size:17px;font-weight:700;"><?= h(ecampusPeriodLabel($period)) ?> — माझी प्रगती</div>
      <div style="font-size:13px;color:#C9CCE0;">प्रत्येक कामाचा % आणि नोंद भरून "जतन करा" दाबा. हीच माहिती Dashboard आणि साहेबांच्या दैनिक/साप्ताहिक अहवालात जाते.</div></div>
  </div>

  <?php foreach ($rows as $r): ?>
    <div class="ec-card" id="t<?= (int)$r['id'] ?>">
      <div class="ec-top">
        <div class="ec-ring" style="--p:<?= $r['percent'] ?>;--c:<?= $r['status_color'] ?>;"><span><?= $r['percent'] ?>%</span></div>
        <div style="flex:1;min-width:0;">
          <div><span class="ec-chip"><?= h($r['wing']) ?></span><span class="ec-chip"><?= h($r['section']) ?></span><span class="ec-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></div>
          <div class="ec-task"><?= h($r['task_description']) ?></div>
          <div style="font-size:12px;color:var(--text-dim);margin-top:4px;"><?= $r['task_type'] === 'monthly' ? 'दरमहा काम' : 'एकदाच काम' ?> · शेवटचा बदल: <?= h(ecampusAgo($r['updated_at'])) ?><?= $r['carried'] ? ' (आधीच्या महिन्यात 100% पूर्ण)' : '' ?></div>
        </div>
      </div>
      <?php if ($r['access_type'] === 'view_only'): ?>
        <div class="ec-form" style="font-size:13px;color:var(--text-dim);">ℹ️ हा फक्त पाहण्याचा Access आहे — प्रगती भरायची गरज नाही.</div>
      <?php else: ?>
        <form method="POST" class="ec-form">
          <input type="hidden" name="task_id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="period" value="<?= h($period) ?>">
          <label class="form-label-custom">किती काम पूर्ण झालं? <b class="ec-val"><?= $r['percent'] ?>%</b></label>
          <input type="range" class="ec-range" name="percent" min="0" max="100" step="1" value="<?= $r['percent'] ?>">
          <div class="ec-quick"><?php foreach ([0, 25, 50, 75, 90, 100] as $q): ?><button type="button" data-q="<?= $q ?>"><?= $q ?>%</button><?php endforeach; ?></div>
          <label class="form-label-custom">प्रगतीची नोंद / Remark</label>
          <textarea name="notes" class="form-control-custom mb-2" rows="2" maxlength="2000" placeholder="उदा. A.Y. 2026-27 नवीन Admission ची Entry सुरू आहे"><?= h($r['carried'] ? '' : (string)$r['notes']) ?></textarea>
          <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-floppy-disk me-1"></i>जतन करा</button>
        </form>
        <?php $hist->execute([$r['id'], $period]); $hs = $hist->fetchAll(); if ($hs): ?>
          <div class="ec-hist"><b>अलीकडचे बदल</b>
            <?php foreach ($hs as $x): ?><div><?= h(date('d M, h:i A', strtotime($x['created_at']))) ?> — <b><?= (int)$x['percent'] ?>%</b> <?= $x['notes'] ? '· ' . h(mb_strimwidth($x['notes'], 0, 90, '…')) : '' ?> <span style="opacity:.7;">(<?= h($x['name']) ?>)</span></div><?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
  document.querySelectorAll('.ec-form').forEach(function (f) {
    var r = f.querySelector('.ec-range'), v = f.querySelector('.ec-val'); if (!r) return;
    var ring = f.closest('.ec-card').querySelector('.ec-ring'), rs = ring.querySelector('span');
    function show() { v.textContent = r.value + '%'; ring.style.setProperty('--p', r.value); rs.textContent = r.value + '%'; ring.style.setProperty('--c', r.value >= 100 ? '#16A34A' : (r.value > 0 ? '#F59E0B' : '#DC2626')); }
    r.addEventListener('input', show);
    f.querySelectorAll('[data-q]').forEach(function (b) { b.addEventListener('click', function () { r.value = b.dataset.q; show(); }); });
  });
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
