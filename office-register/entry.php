<?php
// 📨 आवक / जावक नोंद — नवीन किंवा बदल: पुढचा क्र. आपोआप, शेऱ्यातून विभाग आपोआप, कामाच्या स्थितीवरून टप्पा, "जतन करा व पुढची नोंद", इतिहास
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">आवक-जावक नोंदवही पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
$ST = orStatuses();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$row = null;
if ($id) {
    $st = $pdo->prepare("SELECT o.*, cu.name created_name, uu.name updated_name FROM office_letters o LEFT JOIN users cu ON cu.id = o.created_by LEFT JOIN users uu ON uu.id = o.updated_by WHERE o.id = ? AND o.organization_id = ?");
    $st->execute([$id, $orgId]); $row = $st->fetch();
    if (!$row || !orCan($pdo, $orgId, $row['register_id'], 'read')) { header('Location: index.php'); exit; }
}
// 📚 नोंदवही: आधीची नोंद → तिचीच; नवीन → आत्ताची (नोंद करण्याचा access असलेली)
$REG = $row ? orCurrentRegister($pdo, $orgId, 'read', $row['register_id']) : orCurrentRegister($pdo, $orgId, 'write');
if (!$REG) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">या नोंदवहीत नोंद करण्याचा access नाही.</div>'); }
$rid = (int)$REG['id'];
$canEdit = $row ? $REG['_level'] === 'edit' : orLevelRank($REG['_level']) >= 2;
$dir = $row ? $row['direction'] : (($_GET['dir'] ?? $_POST['direction'] ?? 'in') === 'out' ? 'out' : 'in');

$error = '';
$F = $row ?: ['direction' => $dir, 'fy' => orFy(), 'reg_month' => date('Y-m-01'), 'reg_no' => '', 'letter_date' => date('Y-m-d'), 'ref_no' => '', 'party' => '', 'subject' => '', 'remark' => '',
    'assigned_to' => '', 'work_status' => '', 'status' => 'pending', 'priority' => 'normal', 'due_date' => '', 'done_on' => '', 'linked_no' => ''];

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_letter') {
    $in = fn($k, $max = 255) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $max);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['letter_date'] ?? '')) ? $_POST['letter_date'] : null;
    $regMonth = preg_match('/^\d{4}-\d{2}$/', (string)($_POST['reg_month'] ?? '')) ? $_POST['reg_month'] . '-01' : date('Y-m-01', strtotime($date ?: 'today'));
    $fy = orFy($regMonth);
    $regNo = orNormNo($_POST['reg_no'] ?? '');
    if ($regNo === '') $regNo = (string)orNextNo($pdo, $orgId, $rid, $dir, $fy);
    $ws = $in('work_status', 1000);
    $status = isset($ST[$_POST['status'] ?? '']) ? $_POST['status'] : orClassify($ws);
    $assigned = $in('assigned_to');
    $remark = trim((string)($_POST['remark'] ?? ''));
    if ($assigned === '' && $remark !== '') $assigned = orDetectDepts($remark);
    $doneOn = null;
    if ($status === 'done') $doneOn = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['done_on'] ?? '')) ? $_POST['done_on'] : (($row['done_on'] ?? null) ?: date('Y-m-d'));
    $d = ['direction' => $dir, 'fy' => $fy, 'reg_month' => $regMonth, 'reg_no' => $regNo, 'reg_num' => orRegNum($regNo), 'letter_date' => $date,
        'ref_no' => $dir === 'in' ? ($in('ref_no', 80) ?: null) : null, 'party' => $in('party') ?: null, 'subject' => trim((string)($_POST['subject'] ?? '')) ?: null,
        'remark' => $remark !== '' ? $remark : null, 'assigned_to' => $assigned ?: null, 'work_status' => $ws ?: null, 'status' => $status,
        'priority' => ($_POST['priority'] ?? '') === 'urgent' ? 'urgent' : 'normal', 'due_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['due_date'] ?? '')) ? $_POST['due_date'] : null,
        'done_on' => $doneOn, 'linked_no' => orNormNo($_POST['linked_no'] ?? '') ?: null];
    $F = array_merge($F, $d);
    $dup = $pdo->prepare("SELECT id FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = ? AND fy = ? AND reg_no = ? AND id <> ?");
    $dup->execute([$orgId, $rid, $dir, $fy, $regNo, $id]);
    if (!$d['subject'] && !$d['party']) $error = 'विषय किंवा कोणाकडून / कोणाकडे — किमान एक लिहा.';
    elseif ($dup->fetchColumn()) $error = orDirLabel($dir, true) . ' क्र. ' . $regNo . ' (आ.व. ' . $fy . ') आधीच नोंदलेला आहे — दुसरा क्रमांक द्या (उदा. ' . $regNo . '-अ).';
    if (!$error) {
        if ($row) {
            $chg = [];
            $lbl = ['reg_no' => 'क्र.', 'letter_date' => 'दिनांक', 'party' => $dir === 'out' ? 'कोणाकडे' : 'कोणाकडून', 'subject' => 'विषय', 'remark' => 'शेरा', 'assigned_to' => 'विभाग', 'work_status' => 'कामाची स्थिती', 'status' => 'टप्पा', 'ref_no' => 'प्राप्त पत्राचा जावक क्र.', 'due_date' => 'मुदत', 'reg_month' => 'महिना'];
            foreach ($lbl as $k => $l) if ((string)($row[$k] ?? '') !== (string)($d[$k] ?? '')) $chg[] = $l . ($k === 'status' ? ': ' . orStatusLabel($d[$k]) : '');
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($d)));
            $pdo->prepare("UPDATE office_letters SET $sets, updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([...array_values($d), $user['id'], $id, $orgId]);
            if ($chg) orLog($pdo, $orgId, $id, 'edited', 'बदल: ' . implode(', ', $chg), $user['id']);
            logActivity('आवक-जावक नोंद बदलली', 'office_letter', $id, orDirLabel($dir, true) . ' ' . $regNo);
            $newId = $id;
        } else {
            $cols = array_keys($d);
            $pdo->prepare("INSERT INTO office_letters (organization_id, register_id, " . implode(', ', $cols) . ", source, created_by, created_at) VALUES (?, ?, " . implode(', ', array_fill(0, count($cols), '?')) . ", 'manual', ?, NOW())")
                ->execute([$orgId, $rid, ...array_values($d), $user['id']]);
            $newId = (int)$pdo->lastInsertId();
            orLog($pdo, $orgId, $newId, 'created', orDirLabel($dir, true) . ' क्र. ' . $regNo, $user['id']);
            logActivity('आवक-जावक नोंद', 'office_letter', $newId, orDirLabel($dir, true) . ' ' . $regNo . ' — ' . mb_strimwidth((string)$d['subject'], 0, 80, '…'));
        }
        $_SESSION['flash'] = '✓ ' . orDirLabel($dir, true) . ' क्र. ' . $regNo . ' जतन झाला.' . (!$row && $d['assigned_to'] && trim((string)($_POST['assigned_to'] ?? '')) === '' ? ' काम: ' . $d['assigned_to'] . ' (शेऱ्यातून)' : '');
        if (($_POST['next'] ?? '') === '1') { header('Location: entry.php?r=' . $rid . '&dir=' . $dir . '&m=' . substr($regMonth, 0, 7) . '&last=' . $newId); exit; }
        header('Location: entry.php?id=' . $newId); exit;
    }
}

// नवीन नोंदीसाठी पुढचा क्रमांक व महिना
if (!$row) {
    if (!empty($_GET['m']) && preg_match('/^\d{4}-\d{2}$/', $_GET['m']) && $_SERVER['REQUEST_METHOD'] !== 'POST') $F['reg_month'] = $_GET['m'] . '-01';
    $F['fy'] = orFy($F['reg_month']);
}
$nextNo = orNextNo($pdo, $orgId, $rid, $dir, orFy($F['reg_month']));
if (!$row && $F['reg_no'] === '') $F['reg_no'] = (string)$nextNo;

// सुचवण्या — आधीच्या नोंदींतून
$sug = function ($col, $lim = 300) use ($pdo, $orgId, $dir, $rid) {
    $st = $pdo->prepare("SELECT $col, COUNT(*) n FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = ? AND $col IS NOT NULL AND $col <> '' GROUP BY $col ORDER BY n DESC LIMIT $lim");
    $st->execute([$orgId, $rid, $dir]); return $st->fetchAll(PDO::FETCH_COLUMN);
};
$parties = $sug('party');
$wsList = array_values(array_unique(array_merge(['कार्यवाही झाली आहे.', 'काम पूर्ण झाले आहे.', 'कार्यवाही चालू आहे.', 'प्रक्रियाधीन', 'प्रलंबित.', 'निर्णयाधीन'], $sug('work_status', 40))));
$depts = array_keys(orDeptKeywords());
try { $st = $pdo->prepare("SELECT DISTINCT COALESCE(NULLIF(name_local, ''), name) FROM departments WHERE organization_id = ? ORDER BY 1"); $st->execute([$orgId]); $depts = array_values(array_unique(array_merge($depts, $st->fetchAll(PDO::FETCH_COLUMN)))); } catch (PDOException $e) {}
$st = $pdo->prepare("SELECT reg_no, subject FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = ? ORDER BY id DESC LIMIT 60"); $st->execute([$orgId, $rid, $dir === 'in' ? 'out' : 'in']); $linkOpts = $st->fetchAll();

// याच व्यक्तीची / कार्यालयाची आधीची पत्रे, इतिहास
$related = []; $events = []; $last = null;
if ($row) {
    if ($row['party']) {
        $st = $pdo->prepare("SELECT id, direction, reg_no, letter_date, subject, status FROM office_letters WHERE organization_id = ? AND register_id = $rid AND id <> ? AND party = ? ORDER BY COALESCE(letter_date, created_at) DESC LIMIT 6");
        $st->execute([$orgId, $id, $row['party']]); $related = $st->fetchAll();
    }
    if ($row['linked_no']) {
        $st = $pdo->prepare("SELECT id, direction, reg_no, letter_date, subject, status FROM office_letters WHERE organization_id = ? AND register_id = $rid AND direction <> ? AND reg_no = ? ORDER BY fy DESC LIMIT 1");
        $st->execute([$orgId, $row['direction'], $row['linked_no']]); if ($lk = $st->fetch()) array_unshift($related, $lk + ['_link' => 1]);
    }
    $st = $pdo->prepare("SELECT e.*, u.name FROM office_letter_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.letter_id = ? AND e.organization_id = ? ORDER BY e.id DESC LIMIT 30");
    $st->execute([$id, $orgId]); $events = $st->fetchAll();
} elseif (!empty($_GET['last'])) {
    $st = $pdo->prepare("SELECT id, direction, reg_no, subject FROM office_letters WHERE id = ? AND organization_id = ?"); $st->execute([(int)$_GET['last'], $orgId]); $last = $st->fetch();
}

$ro = !$canEdit;
$pageTitle = $row ? orDirLabel($dir, true) . ' क्र. ' . $row['reg_no'] : 'नवीन ' . orDirLabel($dir, true) . ' नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .oe-wrap{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;align-items:start;}
  .oe-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:16px;}
  .oe-dir{display:flex;gap:6px;margin-bottom:12px;} .oe-dir a{flex:1;text-align:center;padding:9px;border-radius:10px;border:1px solid var(--line);text-decoration:none;color:var(--text);font-weight:700;font-size:13.5px;}
  .oe-dir a.on.in{background:#2563EB;border-color:#2563EB;color:#fff;} .oe-dir a.on.out{background:#9333EA;border-color:#9333EA;color:#fff;}
  .oe-hint{font-size:11.5px;color:var(--text-dim);margin-top:3px;}
  .oe-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;} .oe-chips button{font-size:11.5px;border:1px dashed #0EA5E9;background:color-mix(in srgb,#0EA5E9 8%,var(--surface));color:#0369A1;border-radius:7px;padding:2px 8px;font-weight:700;}
  .oe-st{display:flex;gap:6px;flex-wrap:wrap;} .oe-st label{font-size:12.5px;font-weight:700;padding:5px 10px;border-radius:99px;cursor:pointer;border:2px solid transparent;}
  .oe-st input{display:none;} .oe-st input:checked + span{text-decoration:underline;} .oe-st label:has(input:checked){border-color:currentColor;}
  .st-p{background:#FEE2E2;color:#991B1B;} .st-g{background:#FEF3C7;color:#92400E;} .st-d{background:#DCFCE7;color:#166534;} .st-i{background:#E0F2FE;color:#075985;} .st-c{background:#E5E7EB;color:#374151;}
  .oe-side h6{font-size:12.5px;font-weight:800;margin:0 0 8px;}
  .oe-rel{display:block;font-size:12px;padding:6px 8px;border-radius:8px;border:1px solid var(--line);margin-bottom:6px;color:inherit;text-decoration:none;}
  .oe-ev{font-size:12px;border-left:2px solid var(--line);padding:2px 0 8px 10px;} .oe-ev b{font-size:12px;} .oe-ev small{color:var(--text-dim);display:block;}
  .oe-last{border:1px solid #16A34A;background:color-mix(in srgb,#16A34A 7%,var(--surface));border-radius:12px;padding:8px 12px;font-size:13px;margin-bottom:10px;}
  @media (max-width:900px){ .oe-wrap{grid-template-columns:1fr;} }
</style>

<div class="page-eyebrow"><a href="index.php?r=<?= $rid ?><?= $dir === 'out' ? '&amp;dir=out' : '' ?>" style="color:inherit;">📨 <?= h($REG['name']) ?></a></div>
<div class="page-title"><?= $row ? orDirLabel($dir) . ' क्र. ' . h($row['reg_no']) : '＋ नवीन ' . orDirLabel($dir) . ' नोंद' ?></div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($last): ?><div class="oe-last">✓ आधीची नोंद जतन: <a href="entry.php?id=<?= (int)$last['id'] ?>"><b><?= orDirLabel($last['direction'], true) ?> क्र. <?= h($last['reg_no']) ?></b></a> — <?= h(mb_strimwidth((string)$last['subject'], 0, 80, '…')) ?>. आता पुढची नोंद 👇</div><?php endif; ?>

<div class="oe-wrap">
<form method="POST" class="oe-card" data-no-smart-filter id="oeForm">
  <input type="hidden" name="action" value="save_letter"><input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="direction" value="<?= $dir ?>"><input type="hidden" name="next" value="0">
  <?php if (!$row): ?><div class="oe-dir"><a href="entry.php?r=<?= $rid ?>&amp;dir=in" class="in <?= $dir === 'in' ? 'on' : '' ?>">📥 आवक (आलेले पत्र)</a><a href="entry.php?r=<?= $rid ?>&amp;dir=out" class="out <?= $dir === 'out' ? 'on' : '' ?>">📤 जावक (पाठवलेले पत्र)</a></div><?php endif; ?>
  <fieldset <?= $ro ? 'disabled' : '' ?>>
  <div class="row g-2">
    <div class="col-md-3 col-6"><label class="form-label-custom"><?= orDirLabel($dir, true) ?> क्र. *</label><input type="text" name="reg_no" class="form-control-custom" value="<?= h($F['reg_no']) ?>" maxlength="20" required style="font-weight:800;font-size:16px;">
      <?php if (!$row): ?><div class="oe-hint">पुढचा क्र. आपोआप (<?= $nextNo ?>). जास्तीचं पत्र असेल तर उदा. <?= $nextNo - 1 ?>-अ</div><?php endif; ?></div>
    <div class="col-md-3 col-6"><label class="form-label-custom">दिनांक</label><input type="date" name="letter_date" class="form-control-custom" value="<?= h($F['letter_date']) ?>"></div>
    <div class="col-md-3 col-6"><label class="form-label-custom">नोंदवही महिना</label><select name="reg_month" class="form-select-custom">
      <?php $fyM = orFyMonths(orFy($F['reg_month'])); if (orFy() !== orFy($F['reg_month'])) $fyM = array_merge($fyM, orFyMonths(orFy())); foreach (array_unique($fyM) as $mm): ?><option value="<?= substr($mm, 0, 7) ?>" <?= $F['reg_month'] === $mm ? 'selected' : '' ?>><?= h(orMonthName($mm)) ?> <?= substr($mm, 0, 4) ?></option><?php endforeach; ?></select></div>
    <?php if ($dir === 'in'): ?><div class="col-md-3 col-6"><label class="form-label-custom">प्राप्त पत्राचा जावक क्र.</label><input type="text" name="ref_no" class="form-control-custom" value="<?= h($F['ref_no']) ?>" maxlength="80"></div><?php else: ?>
    <div class="col-md-3 col-6"><label class="form-label-custom">संबंधित आवक क्र. (उत्तर)</label><input type="text" name="linked_no" class="form-control-custom" value="<?= h($F['linked_no']) ?>" maxlength="20" list="oeLink"></div><?php endif; ?>
    <div class="col-12"><label class="form-label-custom"><?= $dir === 'out' ? 'कोणाकडे पाठविलेले' : 'कोणाकडून मिळाले' ?> *</label><input type="text" name="party" class="form-control-custom" value="<?= h($F['party']) ?>" maxlength="255" list="oeParty" placeholder="<?= $dir === 'out' ? 'उदा. माननीय कार्याध्यक्ष साहेब' : 'उदा. अम्रित कॉलेज' ?>" autocomplete="off"></div>
    <div class="col-12"><label class="form-label-custom">विषय *</label><textarea name="subject" class="form-control-custom" rows="2" placeholder="पत्राचा विषय"><?= h($F['subject']) ?></textarea></div>
    <div class="col-12"><label class="form-label-custom">शेरा (व्यवस्थापकांची सूचना)</label><textarea name="remark" class="form-control-custom" rows="2" placeholder="उदा. विद्युत विभाग प्रमुख, तातडीने दुरुस्ती करावी."><?= h($F['remark']) ?></textarea></div>
    <div class="col-md-8"><label class="form-label-custom">काम कोणत्या विभागाकडे</label><input type="text" name="assigned_to" class="form-control-custom" value="<?= h($F['assigned_to']) ?>" maxlength="255" list="oeDept" placeholder="शेऱ्यातून आपोआप ओळखतो">
      <div class="oe-chips" id="oeSug"></div></div>
    <div class="col-md-4 col-6"><label class="form-label-custom">काम पूर्ण करण्याची मुदत</label><input type="date" name="due_date" class="form-control-custom" value="<?= h($F['due_date']) ?>"></div>
    <div class="col-12"><label class="form-label-custom">कामाची स्थिती</label><input type="text" name="work_status" class="form-control-custom" value="<?= h($F['work_status']) ?>" maxlength="1000" list="oeWs" placeholder="उदा. कार्यवाही झाली आहे."></div>
    <div class="col-12"><div class="oe-st"><?php foreach ($ST as $k => [$l, $c]): ?><label class="<?= $c ?>"><input type="radio" name="status" value="<?= $k ?>" <?= $F['status'] === $k ? 'checked' : '' ?>><span><?= h($l) ?></span></label><?php endforeach; ?>
      <label class="st-p" style="margin-left:auto;"><input type="checkbox" name="priority" value="urgent" <?= $F['priority'] === 'urgent' ? 'checked' : '' ?>><span>🔥 तातडीचे</span></label></div>
      <div class="oe-hint">कामाची स्थिती लिहिताच टप्पा आपोआप निवडला जातो — हवा तर बदला.</div></div>
    <div class="col-md-4 col-6" id="oeDoneOn" style="<?= $F['status'] === 'done' ? '' : 'display:none;' ?>"><label class="form-label-custom">पूर्ण दिनांक</label><input type="date" name="done_on" class="form-control-custom" value="<?= h($F['done_on'] ?: date('Y-m-d')) ?>"></div>
  </div>
  </fieldset>
  <?php if ($canEdit): ?>
  <div class="d-flex gap-2 flex-wrap mt-3">
    <button type="submit" class="btn" style="background:<?= $dir === 'out' ? '#9333EA' : '#2563EB' ?>;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button>
    <?php if (!$row): ?><button type="submit" class="btn" onclick="this.form.next.value='1'" style="border:1px solid var(--line);border-radius:10px;font-weight:700;">✓ जतन करा व पुढची नोंद ➜</button><?php endif; ?>
 <a href="index.php?r=<?= $rid ?><?= $dir === 'out' ? '&amp;dir=out' : '' ?>" class="btn" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
  </div>
  <?php endif; ?>
</form>

<div class="oe-side">
  <?php if ($row): ?>
  <?php if ($dir === 'out'): ?><a href="<?= !empty($row['doc_json']) ? 'letter.php?id=' . (int)$row['id'] : 'compose.php?id=' . (int)$row['id'] ?>" class="oe-card mb-3 d-block" style="text-decoration:none;color:#7E22CE;font-weight:800;font-size:13.5px;border-color:#9333EA;"><?= !empty($row['doc_json']) ? '📄 पत्र पहा / Print' : ($canEdit ? '📝 या नोंदीचं पत्र बनवा' : '') ?></a>
  <?php elseif ($canEdit): ?><a href="compose.php?reply=<?= (int)$row['id'] ?>" class="oe-card mb-3 d-block" style="text-decoration:none;color:#7E22CE;font-weight:800;font-size:13.5px;border-color:#9333EA;">↩ या पत्राला उत्तर — जावक पत्र बनवा</a><?php endif; ?>
  <div class="oe-card mb-3" style="font-size:12.5px;">
    <div>आ.व. <b><?= h($row['fy']) ?></b> · <?= h(orMonthName($row['reg_month'])) ?></div>
    <?php if (in_array($row['status'], orOpenStatuses(), true) && ($ag = orAge($row)) !== null): ?><div>⏰ <b><?= $ag ?></b> दिवसांपासून कार्यवाही बाकी</div><?php elseif ($row['status'] === 'done' && ($ag = orAge($row)) !== null): ?><div>✅ <b><?= $ag ?></b> दिवसांत पूर्ण</div><?php endif; ?>
    <div style="color:var(--text-dim);margin-top:4px;">नोंद: <?= h($row['created_name'] ?: ($row['source'] !== 'manual' ? 'Excel आयात' : '—')) ?> · <?= h(date('d.m.Y', strtotime($row['created_at']))) ?><?= $row['updated_name'] ? '<br>शेवटचा बदल: ' . h($row['updated_name']) . ' · ' . h(date('d.m.Y H:i', strtotime($row['updated_at']))) : '' ?></div>
  </div>
  <?php if ($related): ?><div class="oe-card mb-3"><h6>🔗 संबंधित / याच <?= $dir === 'out' ? 'व्यक्तीकडे' : 'व्यक्तीकडून' ?> आधीची पत्रे</h6>
    <?php foreach ($related as $r2): ?><a class="oe-rel" href="entry.php?id=<?= (int)$r2['id'] ?>"><?= !empty($r2['_link']) ? '🔗 ' : '' ?><b><?= orDirLabel($r2['direction'], true) ?> <?= h($r2['reg_no']) ?></b> · <?= h(orDate($r2['letter_date'])) ?> · <span class="<?= $ST[$r2['status']][1] ?? '' ?>" style="border-radius:6px;padding:0 5px;"><?= h(orStatusLabel($r2['status'])) ?></span><br><?= h(mb_strimwidth((string)$r2['subject'], 0, 90, '…')) ?></a><?php endforeach; ?>
  </div><?php endif; ?>
  <?php if ($events): ?><div class="oe-card"><h6>🕘 इतिहास</h6>
    <?php foreach ($events as $e): ?><div class="oe-ev"><b><?= h(['created' => '＋ नोंद', 'edited' => '✎ बदल', 'status' => '🛠️ स्थिती', 'imported' => '📥 आयात'][$e['event']] ?? $e['event']) ?></b> <?= h((string)$e['note']) ?><small><?= h($e['name'] ?: '') ?> · <?= h(date('d.m.Y H:i', strtotime($e['created_at']))) ?></small></div><?php endforeach; ?>
  </div><?php endif; ?>
  <?php else: ?>
  <div class="oe-card" style="font-size:12.5px;line-height:1.7;">
    <h6>💡 जलद नोंद</h6>
    • क्रमांक आपोआप पुढचा येतो.<br>• "कोणाकडून" टाईप करताच आधीची नावं सुचतात.<br>• शेरा लिहिताच काम कोणत्या विभागाकडे ते ओळखतो.<br>• "जतन करा व पुढची नोंद" ने एकामागून एक नोंदी.
  </div>
  <?php endif; ?>
</div>
</div>

<datalist id="oeParty"><?php foreach ($parties as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
<datalist id="oeWs"><?php foreach ($wsList as $w): ?><option value="<?= h($w) ?>"><?php endforeach; ?></datalist>
<datalist id="oeDept"><?php foreach ($depts as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
<datalist id="oeLink"><?php foreach ($linkOpts as $l): ?><option value="<?= h($l['reg_no']) ?>"><?= h(mb_strimwidth((string)$l['subject'], 0, 70, '…')) ?></option><?php endforeach; ?></datalist>
<script>
(function () {
  var f = document.getElementById('oeForm'); if (!f || !f.remark) return;
  var KW = <?= json_encode(orDeptKeywords(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var box = document.getElementById('oeSug'), dn = document.getElementById('oeDoneOn');
  // शेऱ्यातून विभाग सुचवा
  function sug() {
    var t = f.remark.value, cur = f.assigned_to.value.split(',').map(function (x) { return x.trim(); }), hits = [];
    Object.keys(KW).forEach(function (d) { var m = t.search(new RegExp(KW[d].replace(/\\b/g, ''), 'i')); if (m >= 0 && cur.indexOf(d) < 0) hits.push([m, d]); });
    hits.sort(function (a, b) { return a[0] - b[0]; });
    box.innerHTML = '';
    hits.forEach(function (h) { var b = document.createElement('button'); b.type = 'button'; b.textContent = '＋ ' + h[1]; b.onclick = function () { f.assigned_to.value = (f.assigned_to.value.trim() ? f.assigned_to.value.trim().replace(/,\s*$/, '') + ', ' : '') + h[1]; sug(); }; box.appendChild(b); });
  }
  f.remark.addEventListener('input', sug); f.assigned_to.addEventListener('input', sug); sug();
  // कामाच्या स्थितीवरून टप्पा
  function pick() {
    var t = f.work_status.value, s = null;
    if (/रद्द/.test(t)) s = 'cancelled'; else if (/अपूर्ण|प्रलंबित|निर्णयाधी|अभावी|कमतरता|विलंब|येईल/.test(t)) s = 'pending';
    else if (/चालू|सुरु|सुरू|प्रक्रि|प्रक्रीया|मागणी केली|नियोजन|पाठवल|दुरुस्तीसाठी|पाहणी|उर्वरित/.test(t)) s = 'progress';
    else if (/पूर्ण|झाल|जमा|दिले|नोंद घेतली|करण्यात आल|देण्यात आल/.test(t)) s = 'done';
    if (s) { var r = f.querySelector('input[name=status][value="' + s + '"]'); if (r) r.checked = true; }
    show();
  }
  function show() { var v = (f.querySelector('input[name=status]:checked') || {}).value; if (dn) dn.style.display = v === 'done' ? '' : 'none'; }
  f.work_status.addEventListener('change', pick);
  f.querySelectorAll('input[name=status]').forEach(function (r) { r.addEventListener('change', show); });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
