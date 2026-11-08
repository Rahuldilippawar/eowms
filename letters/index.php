<?php
// 📨 पत्र व्यवहार — Smart यादी: Tabs (संख्येसह), Filters (स्थिती/प्राधान्य/प्रकार/अडकलेली/कालावधी/Budget), Sorting,
// प्रत्येक फाईलची कार्यप्रवाह-प्रगती, किती दिवस कोणाकडे, Mobile वर Cards, आणि सध्याची यादी Excel (CSV) मध्ये.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$orgWide = hasPermission('manage_letters_org_wide');

$view = $_GET['view'] ?? 'inbox';
if (!in_array($view, ['inbox', 'sent', 'all'], true) || ($view === 'all' && !$orgWide)) { $view = 'inbox'; }
$f = [
    'q' => trim((string)($_GET['q'] ?? '')),
    'status' => (string)($_GET['status'] ?? ''),
    'priority' => (string)($_GET['priority'] ?? ''),
    'cat' => (int)($_GET['cat'] ?? 0),
    'age' => (int)($_GET['age'] ?? 0),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'budget' => !empty($_GET['budget']),
    'sort' => (string)($_GET['sort'] ?? ''),
];
$priorityLabels = ['urgent' => 'तातडीचे', 'high' => 'जास्त', 'normal' => 'सामान्य', 'low' => 'कमी'];
$priorityColor = ['urgent' => '#DC2626', 'high' => '#F59E0B', 'normal' => '#3B82F6', 'low' => '#94A3B8'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
$statusChip = ['draft' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'returned' => 'overdue'];
if (!isset($statusLabels[$f['status']])) { $f['status'] = ''; }
if (!isset($priorityLabels[$f['priority']])) { $f['priority'] = ''; }
if (!in_array($f['age'], [0, 3, 7, 15], true)) { $f['age'] = 0; }

// ---------- Query ----------
$where = ['l.organization_id = ?']; $params = [$orgId];
if ($view === 'inbox') { $where[] = "l.current_holder_id = ? AND l.status IN ('in_progress', 'returned')"; $params[] = $user['id']; }
if ($view === 'sent') { $where[] = 'l.created_by = ?'; $params[] = $user['id']; }
if ($f['q'] !== '') {
    $where[] = '(l.subject LIKE ? OR l.file_number LIKE ? OR l.outward_no LIKE ? OR l.reference_no LIKE ? OR l.description LIKE ?)';
    array_push($params, ...array_fill(0, 5, '%' . $f['q'] . '%'));
}
if ($f['status'] !== '') { $where[] = 'l.status = ?'; $params[] = $f['status']; }
if ($f['priority'] !== '') { $where[] = 'l.priority = ?'; $params[] = $f['priority']; }
if ($f['cat']) { $where[] = 'l.category_id = ?'; $params[] = $f['cat']; }
if ($f['age']) { $where[] = "l.status IN ('draft','in_progress','returned') AND l.updated_at < DATE_SUB(NOW(), INTERVAL ? DAY)"; $params[] = $f['age']; }
if ($f['from']) { $where[] = 'l.created_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
if ($f['to']) { $where[] = 'l.created_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
if ($f['budget']) { $where[] = 'l.is_budget_expense = 1'; }
$orderBy = [
    'old' => 'l.created_at ASC',
    'stuck' => "l.status IN ('draft','in_progress','returned') DESC, l.updated_at ASC",
    'priority' => "FIELD(l.priority,'urgent','high','normal','low'), l.updated_at DESC",
    'new' => 'l.created_at DESC',
][$f['sort']] ?? ($view === 'inbox' ? "FIELD(l.priority,'urgent','high','normal','low'), l.updated_at DESC" : 'l.created_at DESC');
$sql = "SELECT l.*, lc.name_local AS category_name, u.name AS holder_name, cu.name AS creator_name, d.name_local AS dept_name,
          (SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_template_id = l.workflow_template_id) AS steps_total,
          (SELECT fu.name FROM letter_actions la LEFT JOIN users fu ON fu.id = la.from_user_id WHERE la.letter_id = l.id ORDER BY la.id DESC LIMIT 1) AS last_from
        FROM letters l LEFT JOIN letter_categories lc ON lc.id = l.category_id
        LEFT JOIN users u ON u.id = l.current_holder_id LEFT JOIN users cu ON cu.id = l.created_by LEFT JOIN departments d ON d.id = l.department_id
        WHERE " . implode(' AND ', $where) . " ORDER BY $orderBy LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$letters = $stmt->fetchAll();

// ---------- CSV (सध्याची Filter केलेली यादी) ----------
if (isset($_GET['export'])) {
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="patravyavahar-' . $view . '-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['फाईल क्र.', 'विषय', 'प्रकार', 'विभाग', 'प्राधान्य', 'स्थिती', 'सध्या कोणाकडे', 'किती दिवस', 'तयार केलं', 'दिनांक', 'जावक क्र.', 'मागितलेली रक्कम', 'मंजूर रक्कम']);
    foreach ($letters as $l) {
        $open = in_array($l['status'], ['draft', 'in_progress', 'returned'], true);
        fputcsv($out, [$l['file_number'], $l['subject'], $l['category_name'], $l['dept_name'], $priorityLabels[$l['priority']] ?? $l['priority'], $statusLabels[$l['status']] ?? $l['status'],
            $l['holder_name'], $open ? (int)((time() - strtotime($l['updated_at'])) / 86400) : '', $l['creator_name'], date('d-m-Y', strtotime($l['created_at'])), $l['outward_no'], $l['requested_amount'], $l['approved_amount']]);
    }
    fclose($out);
    exit;
}

// ---------- Tab संख्या ----------
$cnt = $pdo->prepare("SELECT
    SUM(current_holder_id = ? AND status IN ('in_progress','returned')) inbox,
    SUM(created_by = ?) sent,
    COUNT(*) total
  FROM letters WHERE organization_id = ?");
$cnt->execute([$user['id'], $user['id'], $orgId]);
$tabCount = array_map('intval', $cnt->fetch() ?: []);
$cats = $pdo->prepare("SELECT id, name_local FROM letter_categories WHERE organization_id = ? ORDER BY name_local");
try { $cats->execute([$orgId]); $categories = $cats->fetchAll(); } catch (PDOException $e) { $categories = []; }
$filterOn = $f['status'] !== '' || $f['priority'] !== '' || $f['cat'] || $f['age'] || $f['from'] || $f['to'] || $f['budget'] || $f['sort'] !== '';
$qs = fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(['view' => $view, 'q' => $f['q'], 'status' => $f['status'], 'priority' => $f['priority'], 'cat' => $f['cat'] ?: '', 'age' => $f['age'] ?: '', 'from' => $f['from'], 'to' => $f['to'], 'budget' => $f['budget'] ? 1 : '', 'sort' => $f['sort']], $over), fn($v) => $v !== '' && $v !== null));

$pageTitle = 'पत्र व्यवहार';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .lt-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px;}
  .lt-tab{display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:99px;border:1px solid var(--line);background:var(--surface,#fff);font-size:13px;font-weight:700;color:var(--text-dim);text-decoration:none;}
  .lt-tab b{background:var(--canvas);border-radius:99px;padding:1px 8px;font-size:11.5px;color:var(--ink,#191C3A);}
  .lt-tab.on{background:var(--rail);border-color:var(--rail);color:#fff;} .lt-tab.on b{background:rgba(255,255,255,.25);color:#fff;}
  .lt-filters{display:grid;grid-template-columns:2fr repeat(4,minmax(0,1fr));gap:8px;align-items:end;}
  .lt-filters label{font-size:11px;color:var(--text-dim);font-weight:600;display:block;margin-bottom:3px;}
  .lt-more{display:none;grid-column:1/-1;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;align-items:end;}
  .lt-more.show{display:grid;}
  .lt-chips{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;}
  .lt-chip{font-size:12px;font-weight:600;padding:4px 11px;border-radius:99px;border:1px solid var(--line);background:var(--surface,#fff);color:var(--text-dim);text-decoration:none;}
  .lt-chip.on{border-color:var(--rail);color:var(--rail);background:color-mix(in srgb,var(--rail) 8%,transparent);}
  table.lt td{vertical-align:middle;}
  .lt-row{cursor:pointer;} .lt-row td:first-child{border-left:4px solid var(--pc,transparent);}
  .lt-unseen{font-weight:700;background:color-mix(in srgb,var(--rail) 5%,transparent);}
  .lt-dot{width:8px;height:8px;border-radius:50%;background:var(--rose);display:inline-block;margin-right:6px;}
  .lt-sub{font-size:11.5px;color:var(--text-dim);font-weight:400;margin-top:2px;}
  .lt-prog{display:flex;gap:3px;margin-top:5px;} .lt-prog i{height:5px;flex:1;border-radius:99px;background:var(--line);max-width:26px;} .lt-prog i.d{background:#16A34A;} .lt-prog i.c{background:var(--amber,#F59E0B);}
  .lt-age{font-size:12px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap;}
  .lt-age.ok{background:#DCFCE7;color:#166534;} .lt-age.warn{background:#FEF3C7;color:#92400E;} .lt-age.bad{background:#FEE2E2;color:#991B1B;}
  .lt-pri{font-size:11.5px;font-weight:700;white-space:nowrap;}
  .lt-cards{display:none;}
  @media (max-width: 767px){
    .lt-filters{grid-template-columns:1fr 1fr;} .lt-filters .lt-q{grid-column:1/-1;} .lt-more{grid-template-columns:1fr 1fr;}
    .lt-tablewrap{display:none;} .lt-cards{display:block;}
    .lt-card{display:block;padding:12px 14px;border-bottom:1px solid var(--line);border-left:4px solid var(--pc,transparent);color:inherit;text-decoration:none;}
  }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0">📨 पत्र व्यवहार</div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (!empty($user['department_id']) || $orgWide): ?>
      <a href="register.php" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 16px;"><i class="fa-solid fa-book me-1"></i>आवक/जावक रजिस्टर</a>
    <?php endif; ?>
    <a href="<?= h($qs(['export' => 1])) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 16px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन पत्र</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/insights/letters.php'; ?>

<div class="lt-tabs">
  <a href="?view=inbox" class="lt-tab <?= $view === 'inbox' ? 'on' : '' ?>">📥 माझ्याकडे (कृती हवी) <b><?= $tabCount['inbox'] ?? 0 ?></b></a>
  <a href="?view=sent" class="lt-tab <?= $view === 'sent' ? 'on' : '' ?>">📤 मी तयार केलेली <b><?= $tabCount['sent'] ?? 0 ?></b></a>
  <?php if ($orgWide): ?><a href="?view=all" class="lt-tab <?= $view === 'all' ? 'on' : '' ?>">🏢 संस्थेतील सर्व <b><?= $tabCount['total'] ?? 0 ?></b></a><?php endif; ?>
</div>

<form method="GET" class="panel mb-3" style="padding:14px 16px;">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <div class="lt-filters">
    <div class="lt-q"><label>🔍 शोध</label><input type="text" name="q" class="form-control-custom" placeholder="विषय, फाईल क्र., जावक क्र., संदर्भ..." value="<?= h($f['q']) ?>"></div>
    <div><label>स्थिती</label><select name="status" class="form-select-custom" onchange="this.form.submit()"><option value="">सर्व</option><?php foreach ($statusLabels as $k => $v): ?><option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div><label>प्राधान्य</label><select name="priority" class="form-select-custom" onchange="this.form.submit()"><option value="">सर्व</option><?php foreach ($priorityLabels as $k => $v): ?><option value="<?= $k ?>" <?= $f['priority'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div><label>प्रकार</label><select name="cat" class="form-select-custom" onchange="this.form.submit()"><option value="">सर्व</option><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $f['cat'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name_local']) ?></option><?php endforeach; ?></select></div>
    <div><label>क्रम</label><select name="sort" class="form-select-custom" onchange="this.form.submit()">
      <?php foreach (['' => 'आपोआप', 'new' => 'नवीन आधी', 'old' => 'जुनी आधी', 'priority' => 'प्राधान्यानुसार', 'stuck' => 'सर्वात जास्त अडकलेली'] as $k => $v): ?><option value="<?= $k ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="lt-more <?= ($f['from'] || $f['to'] || $f['budget']) ? 'show' : '' ?>" id="ltMore">
      <div><label>पासून (तयार दिनांक)</label><input type="date" name="from" class="form-control-custom" value="<?= h($f['from']) ?>"></div>
      <div><label>पर्यंत</label><input type="date" name="to" class="form-control-custom" value="<?= h($f['to']) ?>"></div>
      <div><label class="d-flex align-items-center gap-2" style="font-size:12.5px;margin-top:22px;"><input type="checkbox" name="budget" value="1" <?= $f['budget'] ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--rail);"> फक्त खर्चाची (Budget) पत्रं</label></div>
      <div><button class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px;">लागू करा</button></div>
    </div>
  </div>
  <div class="lt-chips">
    <?php foreach ([3 => '⏰ ३+ दिवस अडकलेली', 7 => '🔴 ७+ दिवस', 15 => '🚨 १५+ दिवस'] as $d => $lbl): ?>
      <a class="lt-chip <?= $f['age'] === $d ? 'on' : '' ?>" href="<?= h($qs(['age' => $f['age'] === $d ? '' : $d])) ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
    <a class="lt-chip <?= $f['priority'] === 'urgent' ? 'on' : '' ?>" href="<?= h($qs(['priority' => $f['priority'] === 'urgent' ? '' : 'urgent'])) ?>">⚡ फक्त तातडीची</a>
    <a class="lt-chip <?= $f['status'] === 'returned' ? 'on' : '' ?>" href="<?= h($qs(['status' => $f['status'] === 'returned' ? '' : 'returned'])) ?>">↩️ परत आलेली</a>
    <a class="lt-chip" href="#" onclick="document.getElementById('ltMore').classList.toggle('show');return false;">⚙️ दिनांक / Budget</a>
    <?php if ($filterOn || $f['q'] !== ''): ?><a class="lt-chip" href="?view=<?= h($view) ?>" style="color:var(--rose);border-color:var(--rose);">✕ Filters काढा</a><?php endif; ?>
    <span style="margin-left:auto;font-size:12px;color:var(--text-dim);align-self:center;"><?= count($letters) ?> फाईल्स</span>
  </div>
</form>

<?php
$rowInfo = function ($l) use ($view) {
    $open = in_array($l['status'], ['draft', 'in_progress', 'returned'], true);
    $days = $open ? (int)((time() - strtotime($l['updated_at'])) / 86400) : null;
    $tone = $days === null ? '' : ($days >= 7 ? 'bad' : ($days >= 3 ? 'warn' : 'ok'));
    $unseen = $view === 'inbox' && empty($l['current_holder_seen_at']);
    $steps = (int)$l['steps_total'];
    $cur = (int)$l['current_step_order'];
    return [$open, $days, $tone, $unseen, $steps, $cur];
};
$prog = function ($l, $steps, $cur) {
    if ($steps < 1) { return ''; }
    $o = '<div class="lt-prog" title="पायरी ' . min($cur, $steps) . ' / ' . $steps . '">';
    for ($i = 1; $i <= $steps; $i++) {
        $c = $l['status'] === 'completed' || $i < $cur ? 'd' : ($i === $cur ? 'c' : '');
        $o .= '<i class="' . $c . '"></i>';
    }
    return $o . '</div>';
};
?>
<div class="panel lt-tablewrap">
  <div class="table-responsive">
  <table class="rtable lt">
    <thead><tr><th>फाईल क्र.</th><th>विषय</th><th>प्रकार</th><th>प्राधान्य</th><th>सध्या कोणाकडे</th><th>किती दिवस</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($letters)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:28px;"><?= $view === 'inbox' && !$filterOn && $f['q'] === '' ? '🎉 तुमच्याकडे कृतीसाठी एकही फाईल नाही' : 'कोणतीही फाईल सापडली नाही' ?></td></tr>
      <?php endif; ?>
      <?php foreach ($letters as $l): [$open, $days, $tone, $unseen, $steps, $cur] = $rowInfo($l); ?>
        <tr class="lt-row <?= $unseen ? 'lt-unseen' : '' ?>" style="--pc:<?= $priorityColor[$l['priority']] ?? 'transparent' ?>;" onclick="window.location='view.php?id=<?= (int)$l['id'] ?>'">
          <td class="font-mono" style="white-space:nowrap;"><?= h($l['file_number']) ?><?= $l['outward_no'] ? '<div class="lt-sub">जावक ' . h($l['outward_no']) . '</div>' : '' ?></td>
          <td style="min-width:240px;">
            <?php if ($unseen): ?><span class="lt-dot" title="न उघडलेली"></span><?php endif; ?><?= h($l['subject']) ?>
            <?php $__sub = array_filter([$l['dept_name'] ?: '', $view !== 'sent' ? ($l['creator_name'] ? '✍️ ' . $l['creator_name'] : '') : '', $view === 'inbox' && $l['last_from'] ? 'शेवटचं ' . $l['last_from'] . ' कडून' : '', (int)$l['is_budget_expense'] && $l['requested_amount'] ? '₹' . number_format((float)$l['requested_amount']) : '']); ?>
            <?php if ($__sub): ?><div class="lt-sub"><?= h(implode(' · ', $__sub)) ?></div><?php endif; ?>
            <?= $prog($l, $steps, $cur) ?>
          </td>
          <td><?= h($l['category_name'] ?? '—') ?></td>
          <td><span class="lt-pri" style="color:<?= $priorityColor[$l['priority']] ?? 'inherit' ?>;">● <?= h($priorityLabels[$l['priority']] ?? $l['priority']) ?></span></td>
          <td><?= h($l['holder_name'] ?? '—') ?></td>
          <td><?= $days !== null ? '<span class="lt-age ' . $tone . '">' . ($tone === 'bad' ? '⚠️ ' : '') . $days . ' दिवस</span>' : '<span style="color:var(--text-dim);">—</span>' ?></td>
          <td><span class="status-chip <?= $statusChip[$l['status']] ?? 'pending' ?>"><?= h($statusLabels[$l['status']] ?? $l['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<div class="panel lt-cards">
  <?php if (empty($letters)): ?><div style="text-align:center;color:var(--text-dim);padding:24px;font-size:13px;">कोणतीही फाईल सापडली नाही</div><?php endif; ?>
  <?php foreach ($letters as $l): [$open, $days, $tone, $unseen, $steps, $cur] = $rowInfo($l); ?>
    <a class="lt-card <?= $unseen ? 'lt-unseen' : '' ?>" style="--pc:<?= $priorityColor[$l['priority']] ?? 'transparent' ?>;" href="view.php?id=<?= (int)$l['id'] ?>">
      <div class="d-flex justify-content-between gap-2 align-items-start">
        <div style="font-size:13.5px;"><?php if ($unseen): ?><span class="lt-dot"></span><?php endif; ?><?= h($l['subject']) ?></div>
        <span class="status-chip <?= $statusChip[$l['status']] ?? 'pending' ?>" style="flex-shrink:0;"><?= h($statusLabels[$l['status']] ?? $l['status']) ?></span>
      </div>
      <div class="lt-sub"><span class="font-mono"><?= h($l['file_number']) ?></span> · <?= h($l['category_name'] ?? '—') ?> · <span style="color:<?= $priorityColor[$l['priority']] ?? 'inherit' ?>;font-weight:700;">● <?= h($priorityLabels[$l['priority']] ?? '') ?></span></div>
      <div class="d-flex justify-content-between align-items-center mt-1" style="font-size:12px;">
        <span>👤 <?= h($l['holder_name'] ?? '—') ?></span>
        <?= $days !== null ? '<span class="lt-age ' . $tone . '">' . $days . ' दिवस</span>' : '' ?>
      </div>
      <?= $prog($l, $steps, $cur) ?>
    </a>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
