<?php
// 🧰 माझी कामे — Smart: आकडे, 30 दिवसांचा कल, लक्ष द्या, शोध/Filter, एका Click मध्ये सुरू/पूर्ण, Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'in_progress', 'completed', 'all', 'late'], true)) $status = 'pending';
$q = trim(mb_substr($_GET['q'] ?? '', 0, 80));
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : '';
$prio = in_array($_GET['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $_GET['priority'] : '';
$member = (int)($_GET['member'] ?? 0);
$limit = min(500, max(30, (int)($_GET['limit'] ?? 60)));

// Staff — ज्या कामाच्या Team मध्ये ते आहेत; HOD — स्वतःचा विभाग + Extra दिलेले विभाग
$isHod = hasCategory('hod');
$hodDeptIds = $isHod ? (getVisibleDepartmentIds() ?: [0]) : [];
$scope = " FROM work_records wr JOIN departments d ON d.id = wr.department_id WHERE wr.organization_id = ? AND "
    . ($isHod ? "wr.department_id IN (" . implode(',', array_fill(0, count($hodDeptIds), '?')) . ")" : "EXISTS (SELECT 1 FROM work_record_team wt WHERE wt.work_record_id = wr.id AND wt.user_id = ?)");
$scopeP = array_merge([$orgId], $isHod ? $hodDeptIds : [$user['id']]);

// Filter (स्थिती सोडून) — Tab-आकड्यांसाठी हेच वापरतो
$fSql = ''; $fP = [];
if ($q !== '') { $fSql .= " AND (wr.title LIKE ? OR wr.work_code LIKE ? OR wr.description LIKE ? OR wr.remarks LIKE ?)"; $l = "%$q%"; array_push($fP, $l, $l, $l, $l); }
if ($month) { $fSql .= " AND wr.work_date BETWEEN ? AND LAST_DAY(?)"; array_push($fP, "$month-01", "$month-01"); }
if ($prio) { $fSql .= " AND wr.priority = ?"; $fP[] = $prio; }
if ($isHod && $member) { $fSql .= " AND EXISTS (SELECT 1 FROM work_record_team wm WHERE wm.work_record_id = wr.id AND wm.user_id = ?)"; $fP[] = $member; }

$lateCond = "wr.status <> 'completed' AND ((wr.due_date IS NOT NULL AND wr.due_date < CURDATE()) OR (wr.due_date IS NULL AND wr.work_date < DATE_SUB(CURDATE(), INTERVAL 7 DAY)))";
$cs = $pdo->prepare("SELECT COUNT(*) a, SUM(wr.status = 'pending') p, SUM(wr.status = 'in_progress') ip, SUM(wr.status = 'completed') c, SUM($lateCond) late" . $scope . $fSql);
$cs->execute(array_merge($scopeP, $fP));
$cnt = array_map('intval', $cs->fetch() ?: []);

$stSql = ['all' => '', 'pending' => " AND wr.status = 'pending'", 'in_progress' => " AND wr.status = 'in_progress'", 'completed' => " AND wr.status = 'completed'", 'late' => " AND $lateCond"][$status];
$listSql = "SELECT wr.*, d.name_local AS dept_name,
        (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS team_names,
        (SELECT GROUP_CONCAT(CONCAT(si.item_name, ' ', TRIM(TRAILING '.' FROM TRIM(TRAILING '0' FROM wm2.quantity_used)), ' ', si.unit) SEPARATOR ', ') FROM work_record_materials wm2 JOIN store_items si ON si.id = wm2.item_id WHERE wm2.work_record_id = wr.id) AS materials"
    . $scope . $fSql . $stSql . " ORDER BY " . ($status === 'completed' ? "wr.completion_date DESC, wr.work_date DESC" : "(wr.status = 'completed'), FIELD(wr.priority,'urgent','high','normal','low'), wr.due_date IS NULL, wr.due_date, wr.work_date DESC");

// 📊 Excel — सध्याच्या Filter नुसार
if (($_GET['export'] ?? '') === 'csv') {
    $ex = $pdo->prepare($listSql . " LIMIT 5000");
    $ex->execute(array_merge($scopeP, $fP));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="my-work-' . $status . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Work ID', 'दिनांक', 'काम', 'तपशील', 'विभाग', 'Team', 'प्राधान्य', 'स्थिती', 'मुदत', 'पूर्ण', 'लागलेले दिवस', 'खर्च (₹)', 'साहित्य', 'टिप्पणी']);
    foreach ($ex->fetchAll() as $r) {
        fputcsv($out, [$r['work_code'], $r['work_date'], $r['title'], $r['description'], $r['dept_name'], $r['team_names'], $r['priority'], $r['status'], $r['due_date'], $r['completion_date'],
            $r['completion_date'] ? (int)round((strtotime($r['completion_date']) - strtotime($r['work_date'])) / 86400) : '', $r['actual_cost'] ?? $r['estimated_cost'], $r['materials'], $r['remarks']]);
    }
    fclose($out);
    exit;
}

$st = $pdo->prepare($listSql . " LIMIT " . ($limit + 1));
$st->execute(array_merge($scopeP, $fP));
$records = $st->fetchAll();
$hasMore = count($records) > $limit;
$records = array_slice($records, 0, $limit);

// 🧠 आकडे (Filter शिवाय — माझं / विभागाचं एकूण चित्र)
$sm = $pdo->prepare("SELECT
        SUM(wr.work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) m_all,
        SUM(wr.work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND wr.status = 'completed') m_done,
        SUM(wr.status <> 'completed') open_all,
        MIN(CASE WHEN wr.status <> 'completed' THEN wr.work_date END) oldest_open,
        AVG(CASE WHEN wr.status = 'completed' AND wr.completion_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN DATEDIFF(wr.completion_date, wr.work_date) END) avg_days,
        SUM(wr.status = 'completed' AND wr.completion_date >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)) done7,
        SUM($lateCond) late_all,
        SUM(wr.status <> 'completed' AND wr.priority IN ('urgent','high')) hot" . $scope);
$sm->execute($scopeP);
$s = $sm->fetch() ?: [];
$mAll = (int)$s['m_all']; $mDone = (int)$s['m_done'];
$oldestDays = $s['oldest_open'] ? (int)((strtotime('today') - strtotime($s['oldest_open'])) / 86400) : null;

// 📅 मागचे 30 दिवस — रोज किती कामं नोंदली / पूर्ण झाली
$tr = $pdo->prepare("SELECT wr.work_date dd, COUNT(*) n" . $scope . " AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY dd");
$tr->execute($scopeP);
$byDay = array_column($tr->fetchAll(), 'n', 'dd');
$streak = 0;
for ($i = 0; $i < 60; $i++) { $d = date('Y-m-d', strtotime("-$i day")); if (!empty($byDay[$d])) $streak++; elseif ($i > 0) break; }
$activeDays = count(array_filter($byDay));

$members = [];
if ($isHod) {
    $mq = $pdo->prepare("SELECT DISTINCT u.id, u.name FROM users u JOIN work_record_team t ON t.user_id = u.id JOIN work_records wr ON wr.id = t.work_record_id
        WHERE wr.organization_id = ? AND wr.department_id IN (" . implode(',', array_fill(0, count($hodDeptIds), '?')) . ") AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY) ORDER BY u.name");
    $mq->execute(array_merge([$orgId], $hodDeptIds));
    $members = $mq->fetchAll();
}

// Edit फक्त स्वतःच्या नोंदी (HOD ला विभाग दिसला तरी)
$myEntryIds = [];
if ($records) {
    $ids = array_column($records, 'id');
    $myQ = $pdo->prepare("SELECT work_record_id FROM work_record_team WHERE work_record_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND user_id = ?");
    $myQ->execute(array_merge($ids, [$user['id']]));
    $myEntryIds = array_map('intval', $myQ->fetchAll(PDO::FETCH_COLUMN));
}

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];
$prioLabels = ['urgent' => ['🔴 तातडीचं', '#B91C1C', '#FEE2E2'], 'high' => ['🟠 जास्त', '#9A3412', '#FFEDD5'], 'normal' => ['', '', ''], 'low' => ['⚪ कमी', '#64748B', '#F1F5F9']];
$keep = array_filter(['q' => $q, 'month' => $month, 'priority' => $prio, 'member' => $member ?: ''], fn($v) => $v !== '');
$url = fn($over) => '?' . http_build_query(array_filter(array_merge($keep, ['status' => $status], $over), fn($v) => $v !== '' && $v !== null));
$backUrl = 'my-work.php' . $url([]);
$filtered = (bool)$keep;

$pageTitle = 'माझी कामे';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div>
    <div class="page-title mb-0"><?= h(t('s215')) ?></div>
    <p style="font-size:12.5px;color:var(--text-dim);margin:4px 0 0;"><?= $isHod ? 'तुमच्या विभागातील सर्व कामे — पूर्ण करा किंवा विलंबाचे कारण नोंदवा' : 'तुम्ही नोंदवलेली कामे — पूर्ण करा किंवा विलंबाचे कारण नोंदवा' ?></p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= h($url(['export' => 'csv'])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;padding:8px 13px;">📊 Excel</a>
    <a href="entry.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;padding:8px 14px;font-weight:600;">＋ काम नोंदवा</a>
  </div>
</div>

<div class="mw-tiles">
  <div><span>या महिन्यात</span><b><?= $mAll ?></b><small><?= $mDone ?> पूर्ण<?= $mAll ? ' · ' . round($mDone * 100 / $mAll) . '%' : '' ?></small><?php if ($mAll): ?><i style="--p:<?= round($mDone * 100 / $mAll) ?>%"></i><?php endif; ?></div>
  <div><span>उघडी कामं</span><b class="<?= (int)$s['open_all'] ? 'c-amber' : 'c-ok' ?>"><?= (int)$s['open_all'] ?></b><small><?= $oldestDays !== null ? 'सर्वात जुनं ' . $oldestDays . ' दिवसांचं' : '✅ सगळी पूर्ण' ?></small></div>
  <div><span>सरासरी पूर्ण होण्यास</span><b><?= $s['avg_days'] !== null ? round((float)$s['avg_days'], 1) : '—' ?></b><small>दिवस · मागील 90 दिवस</small></div>
  <div><span>सलग नोंदी</span><b><?= $streak ?> 🔥</b><small>30 पैकी <?= $activeDays ?> दिवस नोंद</small></div>
</div>

<?php if ((int)$s['late_all'] || (int)$s['hot'] || (int)$s['done7']): ?>
<div class="mw-attn">
  <?php if ((int)$s['late_all']): ?><a href="?status=late" class="bad">⏰ <?= (int)$s['late_all'] ?> कामं मुदत उलटून / 7 दिवसांपेक्षा जुनी — आधी ही पूर्ण करा</a><?php endif; ?>
  <?php if ((int)$s['hot']): ?><a href="?status=all&priority=urgent" class="warn">🔴 <?= (int)$s['hot'] ?> तातडीची / जास्त प्राधान्याची कामं उघडी</a><?php endif; ?>
  <?php if ((int)$s['done7']): ?><span class="ok">👏 या आठवड्यात <?= (int)$s['done7'] ?> कामं पूर्ण</span><?php endif; ?>
</div>
<?php endif; ?>

<div class="panel mb-3 mw-heat-wrap">
  <div class="mw-heat-h"><b>📅 मागचे 30 दिवस</b><small>गडद = जास्त कामं नोंदवली</small></div>
  <div class="mw-heat">
    <?php $mx = $byDay ? max($byDay) : 1; for ($i = 29; $i >= 0; $i--): $d = date('Y-m-d', strtotime("-$i day")); $n = (int)($byDay[$d] ?? 0); $lv = $n ? min(4, (int)ceil($n * 4 / max(1, $mx))) : 0; ?>
      <a href="?status=all&month=<?= substr($d, 0, 7) ?>&q=" class="l<?= $lv ?><?= $i === 0 ? ' today' : '' ?>" title="<?= date('d M', strtotime($d)) ?> — <?= $n ?> कामं"><?= (int)date('j', strtotime($d)) ?></a>
    <?php endfor; ?>
  </div>
</div>

<div class="mw-tabs">
  <?php foreach (['pending' => [t('s7'), $cnt['p'] ?? 0], 'in_progress' => [t('s9'), $cnt['ip'] ?? 0], 'late' => ['⏰ उशीर', $cnt['late'] ?? 0], 'completed' => [t('s10'), $cnt['c'] ?? 0], 'all' => [t('s19'), $cnt['a'] ?? 0]] as $k => [$lbl, $n]): ?>
    <a href="<?= h($url(['status' => $k, 'limit' => null])) ?>" class="<?= $status === $k ? 'on' : '' ?><?= $k === 'late' && $n ? ' late' : '' ?>"><?= h($lbl) ?> <b><?= (int)$n ?></b></a>
  <?php endforeach; ?>
</div>

<form method="GET" class="mw-filter">
  <input type="hidden" name="status" value="<?= h($status) ?>">
  <input type="search" name="q" class="form-control-custom" placeholder="🔍 काम / Work ID / टिप्पणी शोधा" value="<?= h($q) ?>">
  <input type="month" name="month" class="form-control-custom" value="<?= h($month) ?>" title="महिना">
  <select name="priority" class="form-select-custom"><option value="">सर्व प्राधान्य</option><?php foreach (['urgent' => 'तातडीचं', 'high' => 'जास्त', 'normal' => 'सर्वसाधारण', 'low' => 'कमी'] as $k => $l): ?><option value="<?= $k ?>" <?= $prio === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
  <?php if ($members): ?><select name="member" class="form-select-custom"><option value="">सर्व कर्मचारी</option><?php foreach ($members as $m): ?><option value="<?= $m['id'] ?>" <?= $member === (int)$m['id'] ? 'selected' : '' ?>><?= h($m['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
  <button class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;padding:8px 16px;">शोधा</button>
  <?php if ($filtered): ?><a href="?status=<?= h($status) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">✕ Filter काढा</a><?php endif; ?>
</form>

<div class="row g-3">
  <?php if (!$records): ?>
    <div class="col-12"><div class="panel mw-empty">
      <?php if ($status === 'pending' || $status === 'late'): ?>
        <div class="ic">✅</div><b><?= $status === 'late' ? 'उशीर झालेलं एकही काम नाही' : 'एकही काम प्रलंबित नाही' ?></b>
        <p><?= ($cnt['ip'] ?? 0) ? '<a href="' . h($url(['status' => 'in_progress'])) . '">' . (int)$cnt['ip'] . ' कामं सुरू आहेत</a> · ' : '' ?><?= ($cnt['a'] ?? 0) ? '<a href="' . h($url(['status' => 'all'])) . '">सगळी ' . (int)$cnt['a'] . ' कामं पहा</a>' : 'अजून एकही काम नोंदवलेलं नाही' ?></p>
      <?php else: ?>
        <div class="ic">🗂️</div><b><?= h(t('s34')) ?></b><p><?= $filtered ? 'Filter बदलून पहा' : '' ?></p>
      <?php endif; ?>
      <a href="entry.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:9px;padding:8px 16px;">＋ आजचं काम नोंदवा</a>
    </div></div>
  <?php endif; ?>
  <?php foreach ($records as $rec):
    $age = (int)((strtotime('today') - strtotime($rec['work_date'])) / 86400);
    $overdue = $rec['status'] !== 'completed' && (($rec['due_date'] && $rec['due_date'] < date('Y-m-d')) || (!$rec['due_date'] && $age > 7));
    $p = $prioLabels[$rec['priority'] ?? 'normal'] ?? $prioLabels['normal'];
    $mine = in_array((int)$rec['id'], $myEntryIds, true);
  ?>
    <div class="col-md-6 col-xl-4">
      <div class="panel h-100 mw-card<?= $overdue ? ' late' : '' ?><?= $rec['status'] === 'completed' ? ' done' : '' ?>">
        <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
          <div class="font-display" style="font-weight:600;font-size:14.5px;line-height:1.35;"><?= h($rec['title']) ?></div>
          <span class="status-chip <?= $statusChip[$rec['status']] ?? 'pending' ?>" style="white-space:nowrap;"><?= $statusLabels[$rec['status']] ?? h($rec['status']) ?></span>
        </div>
        <div class="mw-meta">
          <span class="font-mono"><?= h($rec['work_code']) ?></span>
          <span>📅 <?= date('d M Y', strtotime($rec['work_date'])) ?></span>
          <?php if ($p[0]): ?><span class="mw-p" style="color:<?= $p[1] ?>;background:<?= $p[2] ?>;"><?= $p[0] ?></span><?php endif; ?>
          <?php if ($rec['status'] !== 'completed'): ?><span class="<?= $overdue ? 'mw-late' : '' ?>">⏳ <?= $age ? $age . ' दिवसांपासून' : 'आज' ?></span><?php endif; ?>
          <?php if ($rec['due_date'] && $rec['status'] !== 'completed'): ?><span class="<?= $rec['due_date'] < date('Y-m-d') ? 'mw-late' : '' ?>">🎯 मुदत <?= date('d M', strtotime($rec['due_date'])) ?></span><?php endif; ?>
        </div>
        <?php if ($isHod && $rec['dept_name']): ?><div class="mw-line">🏢 <?= h($rec['dept_name']) ?></div><?php endif; ?>
        <?php if ($rec['team_names']): ?><div class="mw-line"><i class="fa-solid fa-users me-1"></i><?= h($rec['team_names']) ?></div><?php endif; ?>
        <?php if ($rec['description']): ?><p class="mw-desc"><?= h($rec['description']) ?></p><?php endif; ?>
        <?php if ($rec['materials']): ?><div class="mw-line">📦 <?= h($rec['materials']) ?></div><?php endif; ?>
        <?php if ($rec['actual_cost'] || $rec['estimated_cost']): ?><div class="mw-line">💰 ₹<?= number_format((float)($rec['actual_cost'] ?: $rec['estimated_cost'])) ?><?= $rec['actual_cost'] ? '' : ' (अंदाजे)' ?></div><?php endif; ?>
        <?php if ($rec['before_photo_path'] || $rec['after_photo_path']): ?>
          <div class="mw-ph"><?php foreach (['before_photo_path' => 'आधी', 'after_photo_path' => 'नंतर'] as $col => $lbl): if ($rec[$col]): ?><a href="../<?= h($rec[$col]) ?>" target="_blank"><img src="../<?= h($rec[$col]) ?>" alt="" loading="lazy"><small><?= $lbl ?></small></a><?php endif; endforeach; ?></div>
        <?php endif; ?>
        <?php if (!empty($rec['remarks'])): ?><div class="mw-rem"><i class="fa-solid fa-comment-dots me-1"></i><?= h($rec['remarks']) ?></div><?php endif; ?>

        <?php if ($rec['status'] !== 'completed'): ?>
          <div class="mw-act">
            <?php if ($rec['status'] === 'pending'): ?>
              <form method="POST" action="update-status.php"><input type="hidden" name="id" value="<?= $rec['id'] ?>"><input type="hidden" name="status" value="in_progress"><input type="hidden" name="remarks" value="<?= h($rec['remarks']) ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>"><button class="mw-b go">▶ सुरू केलं</button></form>
            <?php endif; ?>
            <form method="POST" action="update-status.php"><input type="hidden" name="id" value="<?= $rec['id'] ?>"><input type="hidden" name="status" value="completed"><input type="hidden" name="remarks" value="<?= h($rec['remarks']) ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>"><button class="mw-b ok">✅ पूर्ण झालं</button></form>
            <button type="button" class="mw-b" onclick="this.closest('.mw-card').querySelector('.mw-more').classList.toggle('d-none')">💬 टिप्पणी</button>
            <?php if (!empty($user['can_edit_daily_work']) && $mine): ?><a href="edit.php?id=<?= $rec['id'] ?>" class="mw-b">✎</a><?php endif; ?>
          </div>
          <form method="POST" action="update-status.php" class="mw-more d-none">
            <input type="hidden" name="id" value="<?= $rec['id'] ?>"><input type="hidden" name="back" value="<?= h($backUrl) ?>">
            <textarea name="remarks" class="form-control-custom mb-2" rows="2" placeholder="विलंबाचे कारण / टिप्पणी"><?= h($rec['remarks']) ?></textarea>
            <div class="d-flex gap-2">
              <select name="status" class="form-select-custom"><?php foreach ($statusLabels as $k => $l): ?><option value="<?= $k ?>" <?= $rec['status'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
              <button class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;white-space:nowrap;padding:8px 16px;"><?= h(t('s39')) ?></button>
            </div>
          </form>
        <?php else: ?>
          <div class="mw-donel"><i class="fa-solid fa-circle-check me-1"></i><?= $rec['completion_date'] ? date('d M Y', strtotime($rec['completion_date'])) . ' रोजी पूर्ण' : 'पूर्ण' ?><?= $rec['completion_date'] ? ' · ' . max(0, (int)round((strtotime($rec['completion_date']) - strtotime($rec['work_date'])) / 86400)) . ' दिवसांत' : '' ?>
            <?php if (!empty($user['can_edit_daily_work']) && $mine): ?> · <a href="edit.php?id=<?= $rec['id'] ?>">✎ दुरुस्त करा</a><?php endif; ?></div>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($hasMore): ?><div class="text-center mt-3"><a href="<?= h($url(['limit' => $limit + 60])) ?>" class="btn" style="border:1px solid var(--line);border-radius:10px;">आणखी कामं दाखवा ↓</a></div><?php endif; ?>

<style>
.mw-tiles{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:16px 0 12px;}
.mw-tiles>div{position:relative;background:var(--panel,#fff);border:1px solid var(--line);border-radius:14px;padding:13px 15px;overflow:hidden;}
.mw-tiles span{display:block;font-size:11.5px;color:var(--text-dim);font-weight:600;}
.mw-tiles b{display:block;font-size:24px;font-family:var(--font-mono,monospace);margin:2px 0;}
.mw-tiles small{font-size:11px;color:var(--text-dim);}
.mw-tiles i{position:absolute;left:0;bottom:0;height:3px;width:var(--p);background:var(--rail);}
.mw-attn{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px;}
.mw-attn a,.mw-attn span{border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:600;text-decoration:none;}
.mw-attn .bad{background:#FEE2E2;color:#991B1B;} .mw-attn .warn{background:#FEF3C7;color:#92400E;} .mw-attn .ok{background:#DCFCE7;color:#166534;}
.mw-heat-wrap{padding:12px 16px;}
.mw-heat-h{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px;font-size:13px;} .mw-heat-h small{color:var(--text-dim);font-size:11px;}
.mw-heat{display:grid;grid-template-columns:repeat(30,minmax(0,1fr));gap:3px;}
.mw-heat a{aspect-ratio:1;border-radius:4px;font-size:9px;display:flex;align-items:center;justify-content:center;text-decoration:none;color:transparent;background:var(--line);}
.mw-heat a:hover{color:var(--text);}
.mw-heat .l1{background:color-mix(in srgb,var(--rail) 30%,transparent);} .mw-heat .l2{background:color-mix(in srgb,var(--rail) 55%,transparent);} .mw-heat .l3{background:color-mix(in srgb,var(--rail) 78%,transparent);} .mw-heat .l4{background:var(--rail);}
.mw-heat .today{outline:2px solid var(--amber);outline-offset:1px;}
.mw-tabs{display:flex;gap:6px;overflow-x:auto;margin-bottom:10px;padding-bottom:2px;}
.mw-tabs a{white-space:nowrap;padding:8px 14px;border-radius:11px;border:1px solid var(--line);background:var(--panel,#fff);font-size:13px;font-weight:600;color:var(--text);text-decoration:none;}
.mw-tabs a b{display:inline-block;min-width:20px;margin-left:4px;padding:0 6px;border-radius:10px;background:var(--line);font-size:11px;text-align:center;}
.mw-tabs a.on{background:var(--rail);border-color:var(--rail);color:#fff;} .mw-tabs a.on b{background:rgba(255,255,255,.25);}
.mw-tabs a.late:not(.on) b{background:#FEE2E2;color:#991B1B;}
.mw-filter{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:14px;} .mw-filter input[type=search]{flex:1 1 220px;} .mw-filter input[type=month],.mw-filter select{width:auto;}
.mw-card{padding:16px 18px;border-left:3px solid transparent;}
.mw-card.late{border-left-color:var(--rose);} .mw-card.done{opacity:.92;}
.mw-meta{display:flex;flex-wrap:wrap;gap:4px 10px;font-size:11px;color:var(--text-dim);margin:4px 0 8px;}
.mw-p{border-radius:8px;padding:0 7px;font-weight:700;}
.mw-late{color:var(--rose);font-weight:700;}
.mw-line{font-size:12px;color:var(--text-dim);margin-bottom:4px;}
.mw-desc{font-size:13px;color:var(--text-dim);margin:4px 0 8px;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.mw-ph{display:flex;gap:6px;margin:6px 0;} .mw-ph a{position:relative;} .mw-ph img{width:64px;height:48px;object-fit:cover;border-radius:7px;border:1px solid var(--line);}
.mw-ph small{position:absolute;left:3px;bottom:2px;font-size:9px;background:rgba(0,0,0,.55);color:#fff;border-radius:4px;padding:0 4px;}
.mw-rem{background:var(--amber-soft,#FEF3C7);color:#8A5A14;border-radius:8px;padding:7px 11px;font-size:12.5px;margin:6px 0;}
.mw-act{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;} .mw-act form{margin:0;}
.mw-b{display:inline-flex;align-items:center;border:1px solid var(--line);background:var(--panel,#fff);border-radius:9px;padding:6px 11px;font-size:12.5px;font-weight:600;color:var(--text);text-decoration:none;cursor:pointer;}
.mw-b.ok{background:var(--rail);border-color:var(--rail);color:#fff;} .mw-b.go{border-color:var(--amber);color:#92400E;}
.mw-more{margin-top:10px;}
.mw-donel{font-size:12px;color:var(--rail);font-weight:600;margin-top:8px;}
.mw-empty{padding:30px;text-align:center;} .mw-empty .ic{font-size:34px;} .mw-empty b{display:block;font-size:15px;margin:4px 0;} .mw-empty p{font-size:12.5px;color:var(--text-dim);}
@media (max-width:768px){.mw-tiles{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}.mw-tiles b{font-size:20px;}.mw-heat{grid-template-columns:repeat(15,minmax(0,1fr));}}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
