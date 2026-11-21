<?php
// 📨 आवक-जावक नोंदवही — व्यवस्थापक (सामान्य प्रशासन) कार्यालय: आवक / जावक पत्रे, शेरा, कोणत्या विभागाकडे काम, कामाची स्थिती, महिनानिहाय, अहवाल, Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
require_once __DIR__ . '/../includes/smart-insights.php';
if (!orCanView() && !orIsAdmin()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">आवक-जावक नोंदवही पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
$ready = orReady($pdo);
// 📚 आत्ताची नोंदवही (ज्या नोंदवह्यांचा access आहे त्यांपैकी)
$REG = $ready ? orCurrentRegister($pdo, $orgId, 'read') : null;
$rid = (int)($REG['id'] ?? 0); $lvl = $REG['_level'] ?? '';
$canEdit = orLevelRank($lvl) >= 2;      // नवीन नोंद, पत्र, कामाची स्थिती
$canModify = $lvl === 'edit';            // आधीच्या नोंदी बदलणे, Excel आयात
$myRegs = $ready ? array_intersect_key(orRegisters($pdo, $orgId), orAccessMap($pdo, $orgId)) : [];
$ST = orStatuses();

// ---------- कृती ----------
if ($ready && ($canEdit || orIsAdmin()) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $back = (string)($_POST['back'] ?? 'index.php');
    if (!preg_match('#^index\.php(\?[^"\'<>]*)?$#', $back)) $back = 'index.php';
    if ($act === 'import_seed' && ($canModify || orIsAdmin())) {
        [$n, $skip] = orSeedImport($pdo, $orgId, (int)$user['id'], $rid);
        if ($n) logActivity('आवक-जावक नोंदवही — Excel मधील नोंदी आयात', 'organization', $orgId, $n . ' नोंदी');
        $_SESSION['flash'] = $n ? '✓ Excel मधील ' . $n . ' नोंदी (आवक व जावक, एप्रिल ते नोव्हेंबर) आयात झाल्या.' . ($skip ? ' ' . $skip . ' आधीच होत्या.' : '') : '⚠️ नोंदी आधीच आयात केलेल्या आहेत.';
        header('Location: index.php'); exit;
    }
    if ($act === 'set_status') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM office_letters WHERE id = ? AND organization_id = ?"); $st->execute([$id, $orgId]); $row = $st->fetch();
        if ($row && !orCan($pdo, $orgId, $row['register_id'], 'write')) $row = null;
        $new = (string)($_POST['status'] ?? '');
        if (!$row || !isset($ST[$new])) { $_SESSION['flash'] = '⚠️ नोंद सापडली नाही.'; header('Location: ' . $back); exit; }
        $txt = trim((string)($_POST['work_status'] ?? ''));
        $on = (is_string($_POST['done_on'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['done_on'])) ? $_POST['done_on'] : date('Y-m-d');
        $pdo->prepare("UPDATE office_letters SET status = ?, work_status = ?, done_on = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute([$new, $txt !== '' ? $txt : null, $new === 'done' ? $on : null, $user['id'], $id, $orgId]);
        orLog($pdo, $orgId, $id, 'status', orStatusLabel($new) . ($txt !== '' ? ' — ' . $txt : ''), $user['id']);
        logActivity('आवक-जावक — कामाची स्थिती', 'office_letter', $id, orDirLabel($row['direction'], true) . ' ' . $row['reg_no'] . ': ' . orStatusLabel($new));
        $_SESSION['flash'] = '✓ ' . orDirLabel($row['direction'], true) . ' क्र. ' . $row['reg_no'] . ' — ' . orStatusLabel($new);
        header('Location: ' . $back); exit;
    }
}

// ---------- Filters ----------
$tab = in_array($_GET['dir'] ?? '', ['in', 'out', 'all'], true) ? $_GET['dir'] : 'in';
$fys = [];
if ($ready) { $st = $pdo->prepare("SELECT DISTINCT fy FROM office_letters WHERE organization_id = ? AND register_id = ? ORDER BY fy DESC"); $st->execute([$orgId, $rid]); $fys = $st->fetchAll(PDO::FETCH_COLUMN); }
$curFy = orFy();
if (!in_array($curFy, $fys, true)) array_unshift($fys, $curFy);
$f = ['fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : ($fys[0] ?? $curFy),
    'm' => preg_match('/^\d{4}-\d{2}$/', (string)($_GET['m'] ?? '')) ? $_GET['m'] . '-01' : '',
    'st' => in_array($_GET['st'] ?? '', ['open', 'pending', 'progress', 'done', 'info', 'cancelled', 'old', 'nostatus'], true) ? $_GET['st'] : '',
    'dept' => trim((string)($_GET['dept'] ?? '')), 'q' => trim((string)($_GET['q'] ?? ''))];
$qs = function (array $over = []) use ($tab, $f, &$rid) {
    $q = array_filter(array_merge(['r' => $rid ?: '', 'dir' => $tab !== 'in' ? $tab : '', 'fy' => $f['fy'], 'm' => $f['m'] ? substr($f['m'], 0, 7) : '', 'st' => $f['st'], 'dept' => $f['dept'], 'q' => $f['q']], $over), fn($v) => $v !== '' && $v !== null);
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
};

$rows = []; $K = []; $months = []; $byDept = []; $counts = ['in' => 0, 'out' => 0]; $total = 0; $page = 1; $pages = 1; $hasAny = false; $topParty = []; $todayN = ['in' => 0, 'out' => 0];
$age = "DATEDIFF(CURDATE(), COALESCE(o.letter_date, DATE(o.created_at)))";
if ($ready) {
    $st = $pdo->prepare("SELECT direction, COUNT(*) FROM office_letters WHERE organization_id = ? AND register_id = ? AND fy = ? GROUP BY direction"); $st->execute([$orgId, $rid, $f['fy']]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $n) $counts[$d] = (int)$n;
    $st = $pdo->prepare("SELECT COUNT(*) FROM office_letters WHERE organization_id = ? AND register_id = ?"); $st->execute([$orgId, $rid]); $hasAny = (int)$st->fetchColumn() > 0;
    $st = $pdo->prepare("SELECT direction, COUNT(*) FROM office_letters WHERE organization_id = ? AND register_id = ? AND DATE(created_at) = CURDATE() AND source <> 'seed' AND source <> 'excel' GROUP BY direction"); $st->execute([$orgId, $rid]);
    foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $d => $n) $todayN[$d] = (int)$n;

    $base = " FROM office_letters o WHERE o.organization_id = ? AND o.register_id = ? AND o.fy = ?"; $bp = [$orgId, $rid, $f['fy']];
    if ($tab !== 'all') { $base .= " AND o.direction = ?"; $bp[] = $tab; }
    // महिना-चिप्स (बाकी filters शिवाय)
    $st = $pdo->prepare("SELECT o.reg_month, COUNT(*) n, SUM(o.status IN ('pending','progress')) op $base GROUP BY o.reg_month"); $st->execute($bp);
    foreach ($st->fetchAll() as $r) $months[$r['reg_month']] = $r;
    if ($f['m'] !== '') { $base .= " AND o.reg_month = ?"; $bp[] = $f['m']; }
    if ($f['q'] !== '') {
        $base .= " AND (o.subject LIKE ? OR o.party LIKE ? OR o.remark LIKE ? OR o.work_status LIKE ? OR o.reg_no = ? OR o.ref_no = ? OR o.assigned_to LIKE ?)";
        $lk = '%' . $f['q'] . '%'; array_push($bp, $lk, $lk, $lk, $lk, orNormNo($f['q']), $f['q'], $lk);
    }
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(o.status = 'pending') p, SUM(o.status = 'progress') g, SUM(o.status = 'done') d, SUM(o.status = 'info') i, SUM(o.status = 'cancelled') c,
        SUM(o.status IN ('pending','progress') AND $age > 15) old15, SUM(o.status IN ('pending','progress') AND (o.work_status IS NULL OR o.work_status = '')) nost,
        MAX(CASE WHEN o.status IN ('pending','progress') THEN $age END) maxage $base");
    $st->execute($bp); $K = $st->fetch();
    // विभागनिहाय उघडी कामे (शेऱ्यातून)
    $st = $pdo->prepare("SELECT o.assigned_to $base AND o.status IN ('pending','progress') AND o.assigned_to IS NOT NULL"); $st->execute($bp);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $a) foreach (array_filter(array_map('trim', explode(',', $a))) as $d) $byDept[$d] = ($byDept[$d] ?? 0) + 1;
    arsort($byDept);
    $st = $pdo->prepare("SELECT o.party, COUNT(*) n $base AND o.party IS NOT NULL GROUP BY o.party ORDER BY n DESC LIMIT 40"); $st->execute($bp);
    foreach ($st->fetchAll() as $r) { $k = orPartyKey($r['party']); $topParty[$k] = ['name' => $topParty[$k]['name'] ?? $r['party'], 'n' => ($topParty[$k]['n'] ?? 0) + (int)$r['n']]; }
    uasort($topParty, fn($a, $b) => $b['n'] <=> $a['n']);

    $where = $base; $wp = $bp;
    if ($f['dept'] !== '') { $where .= " AND CONCAT(',', REPLACE(o.assigned_to, ', ', ','), ',') LIKE ?"; $wp[] = '%,' . $f['dept'] . ',%'; }
    if ($f['st'] === 'open') $where .= " AND o.status IN ('pending','progress')";
    elseif ($f['st'] === 'old') $where .= " AND o.status IN ('pending','progress') AND $age > 15";
    elseif ($f['st'] === 'nostatus') $where .= " AND o.status IN ('pending','progress') AND (o.work_status IS NULL OR o.work_status = '')";
    elseif ($f['st'] !== '') { $where .= " AND o.status = ?"; $wp[] = $f['st']; }
    $st = $pdo->prepare("SELECT COUNT(*) $where"); $st->execute($wp); $total = (int)$st->fetchColumn();
    $per = 40; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($_GET['pg'] ?? 1)));
    $order = in_array($f['st'], ['open', 'old', 'pending', 'progress', 'nostatus'], true) ? "COALESCE(o.letter_date, DATE(o.created_at)) ASC, o.reg_num ASC" : "o.reg_month DESC, o.direction, o.reg_num DESC, o.reg_no DESC";

    // 📊 Excel (CSV)
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT o.* $where ORDER BY o.direction, o.reg_month, o.reg_num, o.reg_no"); $st->execute($wp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="avak-javak-' . $f['fy'] . '-' . date('Y-m-d') . '.csv"');
        $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
        fputcsv($o, ['आवक / जावक', 'आर्थिक वर्ष', 'महिना', 'क्र.', 'दिनांक', 'प्राप्त पत्राचा जावक क्र.', 'कोणाकडून / कोणाकडे', 'विषय', 'शेरा', 'काम कोणाकडे', 'कामाची स्थिती', 'टप्पा', 'पूर्ण दिनांक', 'दिवस']);
        while ($r = $st->fetch()) fputcsv($o, [orDirLabel($r['direction'], true), $r['fy'], orMonthName($r['reg_month']), $r['reg_no'], orDate($r['letter_date']), $r['ref_no'], $r['party'], $r['subject'], $r['remark'], $r['assigned_to'], $r['work_status'], strip_tags(orStatusLabel($r['status'])), orDate($r['done_on']), orAge($r)]);
        fclose($o); exit;
    }
    $st = $pdo->prepare("SELECT o.*, $age AS age_days" . $where . " ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($wp); $rows = $st->fetchAll();
}
$seedPending = $ready && ($canModify || (orIsAdmin() && !$REG)) && !$hasAny && orgSetting($pdo, $orgId, 'office_reg_seed_done', '') === '';
$open = (int)($K['p'] ?? 0) + (int)($K['g'] ?? 0);
$actionable = max(1, (int)($K['n'] ?? 0) - (int)($K['i'] ?? 0) - (int)($K['c'] ?? 0));
$donePct = (int)round((int)($K['d'] ?? 0) * 100 / $actionable);

$pageTitle = 'आवक-जावक नोंदवही';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$ageCls = fn($d) => $d === null ? '' : ($d > 30 ? 'a-red' : ($d > 15 ? 'a-amb' : 'a-grn'));
?>
<style>
  .or-act{display:flex;gap:6px;flex-wrap:wrap;}
  .or-act a,.or-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .or-act .pri{background:linear-gradient(90deg,#1D4ED8,#2563EB);color:#fff;border:0;} .or-act .pri2{background:linear-gradient(90deg,#7C3AED,#9333EA);color:#fff;border:0;}
  .or-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 10px;}
  .or-tabs a{padding:8px 14px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-size:13px;font-weight:600;}
  .or-tabs a.on{background:var(--rail);border-color:var(--rail);color:#fff;} .or-tabs a small{opacity:.8;margin-left:4px;}
  .or-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;}
  .or-chips a{font-size:12px;padding:5px 10px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);white-space:nowrap;}
  .or-chips a.on{background:#1D4ED8;border-color:#1D4ED8;color:#fff;} .or-chips small{color:var(--text-dim);margin-left:3px;} .or-chips a.on small{color:#DBEAFE;}
  .or-chips .op{color:#B45309;font-weight:700;} .or-chips a.on .op{color:#FDE68A;}
  .or-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:12px 14px;margin-bottom:10px;display:grid;grid-template-columns:62px 1fr auto;gap:12px;align-items:start;}
  .or-no{border-radius:11px;padding:6px 4px;text-align:center;font-weight:800;font-size:14px;line-height:1.15;}
  .or-no small{display:block;font-size:10px;font-weight:700;opacity:.85;}
  .or-no.in{background:color-mix(in srgb,#2563EB 12%,var(--surface));color:#1D4ED8;} .or-no.out{background:color-mix(in srgb,#9333EA 12%,var(--surface));color:#7E22CE;}
  .or-tt{font-weight:700;font-size:14px;line-height:1.4;color:inherit;text-decoration:none;}
  .or-party{font-size:12px;font-weight:700;color:var(--text-dim);} .or-party b{color:var(--text);}
  .or-chip{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap;}
  .st-p{background:#FEE2E2;color:#991B1B;} .st-g{background:#FEF3C7;color:#92400E;} .st-d{background:#DCFCE7;color:#166534;} .st-i{background:#E0F2FE;color:#075985;} .st-c{background:#E5E7EB;color:#374151;}
  .a-red{background:#FEE2E2;color:#991B1B;} .a-amb{background:#FEF3C7;color:#92400E;} .a-grn{background:#ECFDF5;color:#166534;}
  .or-dept{font-size:11px;font-weight:700;padding:2px 8px;border-radius:7px;background:color-mix(in srgb,#0EA5E9 12%,var(--surface));color:#0369A1;text-decoration:none;}
  .or-rem{margin-top:6px;font-size:12.5px;border-left:3px solid #2563EB;padding:3px 8px;background:color-mix(in srgb,#2563EB 6%,transparent);border-radius:0 6px 6px 0;}
  .or-ws{margin-top:5px;font-size:12.5px;border-left:3px solid #F59E0B;padding:3px 8px;background:color-mix(in srgb,#F59E0B 8%,transparent);border-radius:0 6px 6px 0;}
  .or-ws.done{border-color:#16A34A;background:color-mix(in srgb,#16A34A 8%,transparent);}
  .or-right{text-align:right;min-width:130px;font-size:12px;color:var(--text-dim);}
  .or-btns{display:flex;gap:6px;justify-content:flex-end;margin-top:8px;flex-wrap:wrap;}
  .or-btns a,.or-btns button{font-size:12px;border-radius:8px;padding:5px 10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;font-weight:600;}
  .or-btns .ok{background:#16A34A;border-color:#16A34A;color:#fff;}
  .or-seed{border:2px dashed #2563EB;border-radius:14px;padding:14px 16px;margin-bottom:12px;background:color-mix(in srgb,#2563EB 6%,var(--surface));display:flex;gap:12px;align-items:center;flex-wrap:wrap;}
  .or-ins{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;margin-bottom:12px;}
  .or-ins > div{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:10px 12px;font-size:12.5px;}
  .or-ins h6{font-size:12px;font-weight:800;margin:0 0 6px;color:var(--text-dim);}
  .or-bar{display:flex;align-items:center;gap:6px;margin:3px 0;} .or-bar span{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;} .or-bar i{display:block;height:7px;border-radius:5px;background:linear-gradient(90deg,#2563EB,#60A5FA);} .or-bar b{min-width:24px;text-align:right;}
  .or-prog{height:8px;border-radius:9px;background:var(--canvas);overflow:hidden;display:flex;} .or-prog i{display:block;height:100%;}
  .or-lvl{font-size:11.5px;font-weight:700;vertical-align:middle;background:color-mix(in srgb,#16A34A 12%,var(--surface));color:#166534;border-radius:99px;padding:2px 10px;}
  .or-regs{display:flex;gap:6px;overflow-x:auto;padding-bottom:4px;margin-bottom:10px;} .or-regs a{white-space:nowrap;font-size:12.5px;font-weight:700;padding:7px 12px;border-radius:10px;border:1px solid var(--line);background:var(--surface);color:var(--text);text-decoration:none;}
  .or-regs a.on{background:#1D4ED8;border-color:#1D4ED8;color:#fff;} .or-regs small{font-weight:700;color:#B45309;margin-left:3px;} .or-regs a.on small{color:#FDE68A;}
  @media (max-width:640px){ .or-card{grid-template-columns:52px 1fr;} .or-right{grid-column:1/-1;text-align:left;} .or-btns{justify-content:flex-start;} }
</style>

<div class="page-eyebrow">📨 पत्रव्यवहार — आवक-जावक<?= $REG ? ' · ' . h(orRegKinds()[$REG['kind']] ?? '') : '' ?></div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">📨 <?= $REG ? h($REG['name']) : 'आवक-जावक नोंदवही' ?><?= $REG ? ' <span class="or-lvl">' . h(orLevelLabel($lvl)) . '</span>' : '' ?></div>
  <div class="or-act">
    <?php if ($canEdit && $ready): ?><a class="pri" href="entry.php?dir=in&amp;r=<?= $rid ?>">＋ आवक नोंद</a><a class="pri2" href="compose.php?r=<?= $rid ?>">📝 जावक पत्र बनवा</a><a href="entry.php?dir=out&amp;r=<?= $rid ?>">＋ जावक नोंद</a><?php endif; ?>
    <?php if ($REG): ?><a href="report.php?<?= h(http_build_query(array_filter(['r' => $rid, 'dir' => $tab, 'fy' => $f['fy'], 'm' => $f['m'] ? substr($f['m'], 0, 7) : '']))) ?>" target="_blank">📄 नोंदवही / अहवाल</a><?php endif; ?>
    <?php if ($canModify && $ready): ?><a href="import.php?r=<?= $rid ?>">📥 Excel आयात</a><?php endif; ?>
    <?php if (orIsAdmin() && $ready): ?><a href="registers.php">📚 नोंदवह्या व access</a><?php endif; ?>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
  </div>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ आवक-जावक नोंदवहीसाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>

<?php if ($ready && count($myRegs) > 1): ?>
<div class="or-regs">
  <?php $opn = []; $st = $pdo->prepare("SELECT register_id, COUNT(*) FROM office_letters WHERE organization_id = ? AND status IN ('pending','progress') GROUP BY register_id"); $st->execute([$orgId]); $opn = $st->fetchAll(PDO::FETCH_KEY_PAIR);
  foreach ($myRegs as $mr): ?><a href="index.php?r=<?= (int)$mr['id'] ?>" class="<?= (int)$mr['id'] === $rid ? 'on' : '' ?>"><?= h(explode(' ', orRegKinds()[$mr['kind']] ?? '📁')[0]) ?> <?= h($mr['name']) ?><?= !empty($opn[$mr['id']]) ? ' <small>' . (int)$opn[$mr['id']] . ' उघडी</small>' : '' ?></a><?php endforeach; ?>
</div>
<?php endif; ?>
<?php if ($ready && !$REG): ?>
  <div class="panel" style="padding:26px;text-align:center;font-size:14px;">
    <div style="font-size:34px;">📚</div>
    <?php if (orIsAdmin()): ?><b>अजून एकही नोंदवही नाही.</b><div style="color:var(--text-dim);margin:6px 0 12px;">प्रत्येक विभाग / कॉलेज / शाळा / कार्यालयासाठी नोंदवही तयार करा व कोणाला कोणता access ते ठरवा.</div><a href="registers.php?new=1" class="btn" style="background:#1D4ED8;color:#fff;border-radius:10px;font-weight:700;">＋ नोंदवही तयार करा</a>
    <?php else: ?>तुम्हाला अजून कोणत्याही नोंदवहीचा access दिलेला नाही — Super Admin ला कळवा.<?php endif; ?>
  </div>
<?php endif; ?>
<?php if ($seedPending): ?>
<form method="POST" class="or-seed" onsubmit="return confirm('Excel मधील ट्रस्ट व्यवस्थापक आवक / जावक रजिस्टर 2026-27 (एप्रिल ते नोव्हेंबर) च्या सगळ्या नोंदी आयात करायच्या?');">
  <input type="hidden" name="action" value="import_seed"><input type="hidden" name="r" value="<?= $rid ?>">
  <span style="font-size:26px;">📥</span>
  <div style="flex:1;min-width:220px;"><b>ट्रस्ट व्यवस्थापक आवक / जावक रजिस्टर 2026-27 — 525 नोंदी</b><div style="font-size:12.5px;color:var(--text-dim);">Excel मधील एप्रिल ते नोव्हेंबर: आवक 363 व जावक 162 — शेरा, कामाची स्थिती, सह्यांसह एका क्लिकमध्ये. पुढच्या नोंदी इथेच करा.</div></div>
  <button type="submit" class="btn btn-sm" style="background:#2563EB;color:#fff;border-radius:9px;font-weight:700;">आयात करा</button>
</form>
<?php endif; ?>

<?php if ($ready && $REG): ?>
<div class="or-tabs">
  <a href="<?= h($qs(['dir' => '', 'pg' => null])) ?>" class="<?= $tab === 'in' ? 'on' : '' ?>">📥 आवक<small><?= $counts['in'] ?></small></a>
  <a href="<?= h($qs(['dir' => 'out', 'pg' => null])) ?>" class="<?= $tab === 'out' ? 'on' : '' ?>">📤 जावक<small><?= $counts['out'] ?></small></a>
  <a href="<?= h($qs(['dir' => 'all', 'pg' => null])) ?>" class="<?= $tab === 'all' ? 'on' : '' ?>">सर्व<small><?= $counts['in'] + $counts['out'] ?></small></a>
</div>

<form method="GET" class="d-flex flex-wrap gap-2 mb-2" data-no-smart-filter>
  <input type="hidden" name="r" value="<?= $rid ?>">
  <?php if ($tab !== 'in'): ?><input type="hidden" name="dir" value="<?= h($tab) ?>"><?php endif; ?>
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $f['fy'] === $y ? 'selected' : '' ?>>आर्थिक वर्ष <?= h($y) ?></option><?php endforeach; ?></select>
  <?php foreach (['m' => $f['m'] ? substr($f['m'], 0, 7) : '', 'st' => $f['st'], 'dept' => $f['dept']] as $k => $v): if ($v !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($v) ?>"><?php endif; endforeach; ?>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:240px;flex:1;max-width:420px;" placeholder="विषय, कोणाकडून/कडे, शेरा, क्रमांक, विभाग…">
  <button class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">शोधा</button>
  <?php if ($f['q'] !== '' || $f['dept'] !== '' || $f['st'] !== '' || $f['m'] !== ''): ?><a href="<?= h($qs(['q' => '', 'dept' => '', 'st' => '', 'm' => '', 'pg' => null])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">✕ Filter काढा</a><?php endif; ?>
</form>

<?php if ($months): ?>
<div class="or-chips">
  <a href="<?= h($qs(['m' => '', 'pg' => null])) ?>" class="<?= $f['m'] === '' ? 'on' : '' ?>">सर्व महिने</a>
  <?php foreach (orFyMonths($f['fy']) as $mm): if (!isset($months[$mm])) continue; $mv = $months[$mm]; ?>
    <a href="<?= h($qs(['m' => $f['m'] === $mm ? '' : substr($mm, 0, 7), 'pg' => null])) ?>" class="<?= $f['m'] === $mm ? 'on' : '' ?>"><?= h(orMonthName($mm)) ?><small><?= (int)$mv['n'] ?></small><?= (int)$mv['op'] ? ' <small class="op">· ' . (int)$mv['op'] . ' बाकी</small>' : '' ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($hasAny): ?>
<?= smartTiles([
    ['label' => $tab === 'out' ? '📤 जावक' : ($tab === 'all' ? 'आवक + जावक' : '📥 आवक'), 'value' => (int)$K['n'], 'sub' => ($f['m'] ? orMonthName($f['m']) : 'आ.व. ' . $f['fy']) . (($todayN['in'] + $todayN['out']) ? ' · आज ' . ($todayN['in'] + $todayN['out']) . ' नवीन' : ''), 'href' => $qs(['st' => ''])],
    ['label' => '⏳ कार्यवाही बाकी', 'value' => (int)$K['p'], 'sub' => (int)$K['nost'] . ' ची स्थिती नोंदलेली नाही', 'tone' => (int)$K['p'] ? 'bad' : 'ok', 'href' => $qs(['st' => 'pending'])],
    ['label' => '🔄 कार्यवाही चालू', 'value' => (int)$K['g'], 'sub' => 'काम सुरू आहे', 'tone' => (int)$K['g'] ? 'warn' : '', 'href' => $qs(['st' => 'progress'])],
    ['label' => '✅ पूर्ण', 'value' => (int)$K['d'], 'sub' => $donePct . '% काम पूर्ण', 'tone' => 'ok', 'href' => $qs(['st' => 'done'])],
    ['label' => '⏰ 15+ दिवस बाकी', 'value' => (int)$K['old15'], 'sub' => $K['maxage'] !== null ? 'सर्वात जुनं ' . (int)$K['maxage'] . ' दिवस' : '—', 'tone' => (int)$K['old15'] ? 'bad' : 'ok', 'href' => $qs(['st' => 'old'])],
]) ?>
<?php if ((int)$K['n']): $tn = max(1, (int)$K['n']); ?>
<div class="or-prog mb-3" title="पूर्ण / चालू / बाकी / माहिती / रद्द">
  <i style="width:<?= round((int)$K['d'] * 100 / $tn, 1) ?>%;background:#16A34A;"></i><i style="width:<?= round((int)$K['g'] * 100 / $tn, 1) ?>%;background:#F59E0B;"></i><i style="width:<?= round((int)$K['p'] * 100 / $tn, 1) ?>%;background:#DC2626;"></i><i style="width:<?= round((int)$K['i'] * 100 / $tn, 1) ?>%;background:#0EA5E9;"></i><i style="width:<?= round((int)$K['c'] * 100 / $tn, 1) ?>%;background:#9CA3AF;"></i>
</div>
<?php endif; ?>

<div class="or-chips">
  <?php foreach (['' => 'सर्व टप्पे', 'open' => '⏳🔄 उघडी कामे', 'pending' => $ST['pending'][0], 'progress' => $ST['progress'][0], 'done' => $ST['done'][0], 'info' => $ST['info'][0], 'cancelled' => $ST['cancelled'][0], 'nostatus' => '❔ स्थिती नोंद नाही'] as $k => $l): ?>
    <a href="<?= h($qs(['st' => $k, 'pg' => null])) ?>" class="<?= $f['st'] === $k ? 'on' : '' ?>"><?= h($l) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($byDept || $topParty): ?>
<div class="or-ins">
  <?php if ($byDept): $mx = max($byDept); ?>
  <div><h6>🏢 उघडी कामे — कोणत्या विभागाकडे (शेऱ्यातून)</h6>
    <?php foreach (array_slice($byDept, 0, 6, true) as $d => $n): ?><a class="or-bar" href="<?= h($qs(['dept' => $f['dept'] === $d ? '' : $d, 'st' => 'open', 'pg' => null])) ?>" style="color:inherit;text-decoration:none;<?= $f['dept'] === $d ? 'font-weight:800;' : '' ?>"><span><?= h($d) ?></span><i style="width:<?= round($n * 90 / $mx) ?>px;"></i><b><?= $n ?></b></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($topParty): $mx = max(array_column($topParty, 'n')); ?>
  <div><h6><?= $tab === 'out' ? '📤 सर्वात जास्त पत्रे कोणाकडे' : '📥 सर्वात जास्त पत्रे कोणाकडून' ?></h6>
    <?php foreach (array_slice($topParty, 0, 6) as $p): ?><a class="or-bar" href="<?= h($qs(['q' => $p['name'], 'pg' => null])) ?>" style="color:inherit;text-decoration:none;"><span><?= h($p['name']) ?></span><i style="width:<?= round($p['n'] * 90 / $mx) ?>px;background:linear-gradient(90deg,#9333EA,#C084FC);"></i><b><?= $p['n'] ?></b></a><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($f['dept'] !== ''): ?><div class="mb-2" style="font-size:12.5px;">🏢 विभाग: <b><?= h($f['dept']) ?></b> <a href="<?= h($qs(['dept' => '', 'pg' => null])) ?>" style="margin-left:6px;">✕</a></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);font-size:13.5px;">
    <?= $hasAny ? 'या अटींनुसार नोंद नाही.' : 'अजून एकही नोंद नाही.' ?>
    <?php if ($canEdit): ?><div class="mt-2"><a href="entry.php?r=<?= $rid ?>&amp;dir=<?= $tab === 'out' ? 'out' : 'in' ?>" class="btn btn-sm" style="background:#2563EB;color:#fff;border-radius:8px;">＋ <?= $tab === 'out' ? 'जावक' : 'आवक' ?> नोंद</a></div><?php endif; ?>
  </div>
<?php else: ?>
  <div style="font-size:12px;color:var(--text-dim);margin-bottom:6px;"><?= $total ?> नोंदी<?= $pages > 1 ? ' · पान ' . $page . '/' . $pages : '' ?></div>
  <?php foreach ($rows as $r): $open1 = in_array($r['status'], orOpenStatuses(), true); $ag = $open1 ? (int)$r['age_days'] : null; ?>
  <div class="or-card">
    <div class="or-no <?= $r['direction'] ?>"><small><?= orDirLabel($r['direction'], true) ?></small><?= h($r['reg_no']) ?><small><?= h(orDate($r['letter_date'])) ?></small></div>
    <div style="min-width:0;">
      <div class="d-flex gap-2 flex-wrap align-items-center mb-1">
        <span class="or-party"><?= $r['direction'] === 'out' ? '➜ कोणाकडे:' : 'कोणाकडून:' ?> <b><?= h($r['party'] ?: '—') ?></b></span>
        <span class="or-chip <?= $ST[$r['status']][1] ?? '' ?>"><?= h(orStatusLabel($r['status'])) ?></span>
        <?php if ($ag !== null): ?><span class="or-chip <?= $ageCls($ag) ?>"><?= $ag ?> दिवस</span><?php endif; ?>
        <?php if ($r['priority'] === 'urgent'): ?><span class="or-chip st-p">🔥 तातडीचे</span><?php endif; ?>
        <?php if ($r['due_date'] && $open1): ?><span class="or-chip <?= $r['due_date'] < date('Y-m-d') ? 'a-red' : 'a-amb' ?>">📅 मुदत <?= h(orDate($r['due_date'])) ?></span><?php endif; ?>
      </div>
      <a href="entry.php?id=<?= (int)$r['id'] ?>" class="or-tt"><?= h($r['subject'] ?: '(विषय नाही)') ?></a>
      <?php if ($r['ref_no'] || $r['linked_no']): ?><div style="font-size:11.5px;color:var(--text-dim);margin-top:2px;"><?= $r['ref_no'] ? 'प्राप्त पत्राचा जावक क्र. <b>' . h($r['ref_no']) . '</b>' : '' ?><?= $r['linked_no'] ? ($r['ref_no'] ? ' · ' : '') . '🔗 संबंधित ' . ($r['direction'] === 'out' ? 'आवक' : 'जावक') . ' क्र. <b>' . h($r['linked_no']) . '</b>' : '' ?></div><?php endif; ?>
      <?php if ($r['remark']): ?><div class="or-rem">📝 <?= h($r['remark']) ?></div><?php endif; ?>
      <?php if ($r['assigned_to']): ?><div class="d-flex gap-1 flex-wrap mt-1"><?php foreach (array_filter(array_map('trim', explode(',', $r['assigned_to']))) as $d): ?><a class="or-dept" href="<?= h($qs(['dept' => $d, 'pg' => null])) ?>">🏢 <?= h($d) ?></a><?php endforeach; ?></div><?php endif; ?>
      <?php if ($r['work_status']): ?><div class="or-ws <?= $r['status'] === 'done' ? 'done' : '' ?>">🛠️ <?= h($r['work_status']) ?><?= $r['done_on'] ? ' <small style="color:var(--text-dim);">(' . h(orDate($r['done_on'])) . ')</small>' : '' ?></div><?php endif; ?>
    </div>
    <div class="or-right">
      <div><?= h(orMonthName($r['reg_month'])) ?> · आ.व. <?= h($r['fy']) ?></div>
      <div class="or-btns">
        <?php if ($canEdit && $r['status'] !== 'done'): ?><button type="button" class="ok or-st" data-id="<?= (int)$r['id'] ?>" data-st="done" data-ws="<?= h($r['work_status'] ?? '') ?>" data-title="<?= h(orDirLabel($r['direction'], true) . ' क्र. ' . $r['reg_no'] . ' — ' . mb_strimwidth((string)$r['subject'], 0, 90, '…')) ?>">✅ पूर्ण</button><?php endif; ?>
        <?php if ($canEdit): ?><button type="button" class="or-st" data-id="<?= (int)$r['id'] ?>" data-st="<?= h($r['status']) ?>" data-ws="<?= h($r['work_status'] ?? '') ?>" data-title="<?= h(orDirLabel($r['direction'], true) . ' क्र. ' . $r['reg_no'] . ' — ' . mb_strimwidth((string)$r['subject'], 0, 90, '…')) ?>">🛠️ स्थिती</button><?php endif; ?>
        <?php if ($r['direction'] === 'out' && !empty($r['doc_json'])): ?><a href="letter.php?id=<?= (int)$r['id'] ?>" style="border-color:#9333EA;color:#7E22CE;">📄 पत्र</a><?php endif; ?>
        <?php if ($canEdit && $r['direction'] === 'in' && $open1): ?><a href="compose.php?reply=<?= (int)$r['id'] ?>" title="या पत्राला उत्तर — जावक पत्र">↩ उत्तर</a><?php endif; ?>
        <a href="entry.php?id=<?= (int)$r['id'] ?>"><?= $canModify ? '✎ उघडा' : 'पहा' ?></a>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if ($pages > 1): ?><div class="d-flex justify-content-center gap-1 flex-wrap mb-3"><?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= h($qs(['pg' => $i])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;<?= $i === $page ? 'background:var(--rail);color:#fff;' : '' ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="modal fade" id="stModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter>
    <input type="hidden" name="action" value="set_status"><input type="hidden" name="id" value=""><input type="hidden" name="back" value="<?= h(basename($qs(['pg' => $page > 1 ? $page : null]))) ?>">
    <div class="modal-header"><div><div class="modal-title" style="font-weight:800;">🛠️ कामाची स्थिती</div><div id="stTitle" style="font-size:12.5px;color:var(--text-dim);"></div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <div class="d-flex flex-wrap gap-2 mb-2"><?php foreach ($ST as $k => [$l]): ?><label class="or-chip <?= $ST[$k][1] ?>" style="cursor:pointer;font-size:12.5px;padding:5px 10px;"><input type="radio" name="status" value="<?= $k ?>" style="margin-right:4px;"><?= h($l) ?></label><?php endforeach; ?></div>
      <label class="form-label-custom">कामाची स्थिती (नोंदवहीत छापली जाईल)</label>
      <input type="text" name="work_status" class="form-control-custom" maxlength="500" list="orWs" placeholder="उदा. कार्यवाही झाली आहे.">
      <div class="mt-2" id="stDone"><label class="form-label-custom">पूर्ण दिनांक</label><input type="date" name="done_on" class="form-control-custom" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" style="max-width:200px;"></div>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button></div>
  </form>
</div></div></div>
<datalist id="orWs"><?php foreach (['कार्यवाही झाली आहे.', 'काम पूर्ण झाले आहे.', 'कार्यवाही चालू आहे.', 'प्रक्रियाधीन', 'प्रलंबित.', 'निर्णयाधीन', 'मागणी केली आहे.', 'नियोजन केले आहे.', 'लेखा विभागात जमा केले आहे.', 'साहित्य अभावी काम प्रलंबित आहे.', 'रद्द झाले.'] as $w): ?><option value="<?= h($w) ?>"><?php endforeach; ?></datalist>
<script>
window.addEventListener('load', function () {
  var m = document.getElementById('stModal'); if (!m || !window.bootstrap) return;
  var md = new bootstrap.Modal(m), f = m.querySelector('form'), dn = document.getElementById('stDone');
  var sync = function () { var v = (f.querySelector('input[name=status]:checked') || {}).value; dn.style.display = v === 'done' ? '' : 'none'; };
  f.querySelectorAll('input[name=status]').forEach(function (r) { r.addEventListener('change', sync); });
  // स्थितीचा मजकूर लिहिताच टप्पा आपोआप (नोंदवहीतल्या शब्दांवरून)
  f.work_status.addEventListener('change', function () {
    var t = f.work_status.value, s = null;
    if (/रद्द/.test(t)) s = 'cancelled'; else if (/अपूर्ण|प्रलंबित|निर्णयाधी|अभावी/.test(t)) s = 'pending'; else if (/चालू|सुरु|सुरू|प्रक्रि|नियोजन|मागणी केली/.test(t)) s = 'progress'; else if (/पूर्ण|झाल|जमा|दिले/.test(t)) s = 'done';
    if (s) { var r = f.querySelector('input[name=status][value="' + s + '"]'); if (r) { r.checked = true; sync(); } }
  });
  document.querySelectorAll('.or-st').forEach(function (b) {
    b.addEventListener('click', function () {
      f.id.value = b.dataset.id; f.work_status.value = b.dataset.ws || ''; document.getElementById('stTitle').textContent = b.dataset.title;
      if (b.dataset.st === 'done' && !f.work_status.value) f.work_status.value = 'कार्यवाही झाली आहे.';
      var r = f.querySelector('input[name=status][value="' + b.dataset.st + '"]'); if (r) r.checked = true; sync(); md.show();
    });
  });
});
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
