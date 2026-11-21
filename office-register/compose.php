<?php
// 📝 जावक पत्र बनवा — letterhead, जा.क्र. (पुढचा क्र. आपोआप), प्रति (आधीचे पत्ते लक्षात), विषय, संदर्भ, नमुने, विषयावरून शेवटची विनंती-ओळ,
// सह्या (हव्या तेवढ्या), कार्याध्यक्षांचा शेरा/सही, प्रत माहितीसाठी सादर, थेट Preview. जतन करताच जावक नोंदवहीत नोंद.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orCanEdit()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">जावक पत्र बनवण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
$TPL = orLetterTemplates();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$row = null; $reply = null; $copyOf = null;
$load = function ($lid) use ($pdo, $orgId) { $st = $pdo->prepare("SELECT * FROM office_letters WHERE id = ? AND organization_id = ?"); $st->execute([(int)$lid, $orgId]); return $st->fetch() ?: null; };
if ($id) { $row = $load($id); if (!$row || $row['direction'] !== 'out') { header('Location: index.php'); exit; } }
if (!$row && !empty($_GET['copy'])) $copyOf = $load($_GET['copy']);
$replyId = (int)($_GET['reply'] ?? $_POST['reply'] ?? 0);
if (!$row && $replyId) { $reply = $load($replyId); if ($reply && $reply['direction'] !== 'in') $reply = null; }

// 📚 नोंदवही — पत्र बदलायचं तर "बदल" access, नवीन पत्र / उत्तर तर "नोंद" access
$want = $row['register_id'] ?? $reply['register_id'] ?? $copyOf['register_id'] ?? null;
$REG = orCurrentRegister($pdo, $orgId, $row ? 'edit' : 'write', $want);
if (!$REG || ($want && (int)$REG['id'] !== (int)$want)) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">या नोंदवहीत पत्र ' . ($row ? 'बदलण्याचा' : 'बनवण्याचा') . ' access नाही.</div>'); }
$rid = (int)$REG['id'];
$canSettings = $REG['_level'] === 'edit';
$st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $st->execute([$orgId]);
$SET = orLetterSettings($REG, (string)$st->fetchColumn());
$wRegs = array_filter(array_intersect_key(orRegisters($pdo, $orgId), orAccessMap($pdo, $orgId)), fn($r) => orLevelRank(orLevel($pdo, $orgId, $r['id'])) >= 2);

// ---------- पत्र सेटिंग्ज (या नोंदवहीसाठी) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_letter_settings' && $canSettings) {
    $sg = [];
    foreach ((array)($_POST['dsn'] ?? []) as $i => $n) { $n = trim((string)$n); $p = trim((string)($_POST['dsp'][$i] ?? '')); if ($n !== '' || $p !== '') $sg[] = ($n !== '' ? mb_substr($n, 0, 80) . '|' : '') . mb_substr($p, 0, 80); }
    $pdo->prepare("UPDATE office_registers SET letterhead = ?, prefix = ?, remark_line = ?, letter_signs = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
        ->execute([mb_substr(trim((string)($_POST['head'] ?? '')), 0, 1000) ?: null, mb_substr(trim((string)($_POST['prefix'] ?? '')), 0, 150) ?: null, mb_substr(trim((string)($_POST['remark'] ?? '')), 0, 200) ?: null, implode("\n", $sg) ?: null, $rid, $orgId]);
    $_SESSION['flash'] = '✓ "' . $REG['name'] . '" — पत्राची सेटिंग्ज जतन झाली.';
    header('Location: compose.php?r=' . $rid . (!empty($_POST['back_id']) ? '&id=' . (int)$_POST['back_id'] : '')); exit;
}

/** POST → [meta, doc] */
$fromPost = function () use ($SET) {
    $t = fn($k, $max = 4000) => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $max);
    $signs = [];
    foreach ((array)($_POST['sn'] ?? []) as $i => $n) { $n = mb_substr(trim((string)$n), 0, 80); $p = mb_substr(trim((string)($_POST['sp'][$i] ?? '')), 0, 80); if ($n !== '' || $p !== '') $signs[] = [$n, $p]; }
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['letter_date'] ?? '')) ? $_POST['letter_date'] : date('Y-m-d');
    $doc = ['prefix' => $t('prefix', 120) ?: $SET['prefix'], 'to' => $t('to', 1000), 'salutation' => $t('salutation', 60), 'reference' => $t('reference', 500), 'body' => $t('body', 20000),
        'closing' => $t('closing', 1000), 'final' => $t('final', 300), 'signs' => $signs, 'remark' => !empty($_POST['remark_on']) ? $t('remark', 200) : '', 'copies' => $t('copies', 2000),
        'template' => $t('template', 30), 'digits' => ($_POST['digits'] ?? 'mr') === 'en' ? 'en' : 'mr'];
    return [['date' => $date, 'subject' => $t('subject', 1000), 'reg_no' => orNormNo($_POST['reg_no'] ?? ''), 'fy' => orFy($date)], $doc];
};

// ---------- थेट Preview (fetch) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'preview_letter') {
    [$m, $doc] = $fromPost();
    if ($m['reg_no'] === '') $m['reg_no'] = (string)orNextNo($pdo, $orgId, $rid, 'out', $m['fy']);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo orLetterHtml($doc, $m, (string)($_POST['head'] ?? $SET['head']), $doc['digits'] === 'mr');
    exit;
}

$error = '';
// ---------- जतन ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_letter_doc') {
    [$m, $doc] = $fromPost();
    $fy = $m['fy'];
    $regNo = $m['reg_no'] !== '' ? $m['reg_no'] : (string)orNextNo($pdo, $orgId, $rid, 'out', $fy);
    $party = trim(preg_replace('/[,.]+$/u', '', trim(explode("\n", $doc['to'])[0] ?? '')));
    $dup = $pdo->prepare("SELECT id FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = 'out' AND fy = ? AND reg_no = ? AND id <> ?");
    $dup->execute([$orgId, $rid, $fy, $regNo, $id]);
    if ($m['subject'] === '') $error = 'पत्राचा विषय लिहा.';
    elseif (trim($doc['body']) === '') $error = 'पत्राचा मजकूर लिहा.';
    elseif ($dup->fetchColumn()) $error = 'जावक क्र. ' . $regNo . ' (आ.व. ' . $fy . ') आधीच वापरलेला आहे — दुसरा क्रमांक द्या (उदा. ' . $regNo . '-अ).';
    if (!$error) {
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $linked = $reply ? $reply['reg_no'] : ($row['linked_no'] ?? null);
        if ($row) {
            $pdo->prepare("UPDATE office_letters SET fy = ?, reg_month = ?, reg_no = ?, reg_num = ?, letter_date = ?, party = ?, subject = ?, doc_json = ?, updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
                ->execute([$fy, ($row['fy'] === $fy ? $row['reg_month'] : date('Y-m-01', strtotime($m['date']))), $regNo, orRegNum($regNo), $m['date'], $party ?: null, $m['subject'], $json, $user['id'], $id, $orgId]);
            orLog($pdo, $orgId, $id, 'edited', 'पत्राचा मजकूर बदलला', $user['id']);
            $newId = $id;
        } else {
            $pdo->prepare("INSERT INTO office_letters (organization_id, register_id, direction, fy, reg_month, reg_no, reg_num, letter_date, party, subject, status, linked_no, doc_json, source, created_by, created_at)
                VALUES (?, ?, 'out', ?, ?, ?, ?, ?, ?, ?, 'pending', ?, ?, 'letter', ?, NOW())")
                ->execute([$orgId, $rid, $fy, date('Y-m-01', strtotime($m['date'])), $regNo, orRegNum($regNo), $m['date'], $party ?: null, $m['subject'], $linked, $json, $user['id']]);
            $newId = (int)$pdo->lastInsertId();
            orLog($pdo, $orgId, $newId, 'created', 'E-Admin मध्ये पत्र बनवले — जावक क्र. ' . $regNo, $user['id']);
            if ($reply) {
                // आवक पत्राला उत्तर — आवक नोंदीत संबंधित जावक क्र., स्थिती "चालू"
                $pdo->prepare("UPDATE office_letters SET linked_no = COALESCE(linked_no, ?), status = IF(status = 'pending', 'progress', status), work_status = COALESCE(NULLIF(work_status, ''), ?), updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
                    ->execute([$regNo, 'जावक क्र. ' . $regNo . ' ने उत्तर पाठवले.', $user['id'], $reply['id'], $orgId]);
                orLog($pdo, $orgId, (int)$reply['id'], 'status', 'उत्तर — जावक क्र. ' . $regNo, $user['id']);
            }
        }
        logActivity($row ? 'जावक पत्र बदलले' : 'जावक पत्र बनवले', 'office_letter', $newId, 'जावक ' . $regNo . ' — ' . mb_strimwidth($m['subject'], 0, 80, '…'));
        $_SESSION['flash'] = '✓ पत्र जतन झाले व जावक नोंदवहीत क्र. ' . $regNo . ' ने नोंदले.';
        header('Location: letter.php?id=' . $newId); exit;
    }
}

// ---------- फॉर्मचे मूल्य ----------
$src = $row ?: $copyOf;
$doc = orLetterDoc($src, $SET);
$M = ['date' => $row['letter_date'] ?? date('Y-m-d'), 'subject' => $src['subject'] ?? '', 'reg_no' => $row['reg_no'] ?? ''];
if (!$row && $copyOf) $M['date'] = date('Y-m-d');
// कार्याध्यक्षांचा पत्ता — letterhead मधून (पहिली "!! … !!" ओळ सोडून)
$hl = array_values(array_filter(array_map('trim', explode("\n", $SET['head'])), 'strlen'));
if ($hl && preg_match('/^!!/u', $hl[0])) array_shift($hl);
$addr = implode(",\n", array_map(fn($l) => rtrim($l, ',. '), $hl));
$presetTo = ['माननीय कार्याध्यक्ष साहेब' => "माननीय कार्याध्यक्ष साहेब,\n" . ($addr !== '' ? $addr . '.' : '')];
if ($reply) {
    $doc['to'] = trim((string)$reply['party']) . ',';
    $doc['reference'] = 'आपले पत्र' . ($reply['ref_no'] ? ' क्र. ' . $reply['ref_no'] : '') . ($reply['letter_date'] ? ', दिनांक ' . date('d/m/Y', strtotime($reply['letter_date'])) : '') . ' (आवक क्र. ' . $reply['reg_no'] . ')';
    $M['subject'] = preg_replace('/^(विषय\s*:\s*)/u', '', (string)$reply['subject']);
}
if ($row && empty($row['doc_json']) && $row['party']) $doc['to'] = $row['party'] . ',';
if (!$src && !$reply && $doc['to'] === '') $doc['to'] = $presetTo['माननीय कार्याध्यक्ष साहेब'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) { [$pm, $pd] = $fromPost(); $doc = $pd; $M = array_merge($M, ['date' => $pm['date'], 'subject' => $pm['subject'], 'reg_no' => $pm['reg_no']]); }
$fyNow = orFy($M['date']);
$nextNo = orNextNo($pdo, $orgId, $rid, 'out', $fyNow);
if (!$row && $M['reg_no'] === '') $M['reg_no'] = (string)$nextNo;
if (!$doc['signs']) $doc['signs'] = [['', '']];

// आधी पत्र पाठवलेल्यांचे पूर्ण पत्ते
$memTo = [];
$st = $pdo->prepare("SELECT party, doc_json FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = 'out' AND doc_json IS NOT NULL ORDER BY id DESC LIMIT 200"); $st->execute([$orgId, $rid]);
foreach ($st->fetchAll() as $r) { $d = json_decode((string)$r['doc_json'], true); if ($r['party'] && !empty($d['to']) && !isset($memTo[$r['party']])) $memTo[$r['party']] = $d['to']; }
$memTo = $presetTo + $memTo;
$st = $pdo->prepare("SELECT party, COUNT(*) n FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = 'out' AND party IS NOT NULL GROUP BY party ORDER BY n DESC LIMIT 60"); $st->execute([$orgId, $rid]);
$parties = $st->fetchAll(PDO::FETCH_COLUMN);
$recent = $pdo->prepare("SELECT id, reg_no, subject, letter_date FROM office_letters WHERE organization_id = ? AND register_id = ? AND direction = 'out' AND doc_json IS NOT NULL ORDER BY id DESC LIMIT 8"); $recent->execute([$orgId, $rid]); $recent = $recent->fetchAll();

$pageTitle = $row ? 'जावक पत्र — क्र. ' . $row['reg_no'] : 'नवीन जावक पत्र';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  <?= orLetterCss() ?>
  .lc-wrap{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.05fr);gap:14px;align-items:start;}
  .lc-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:14px;margin-bottom:12px;}
  .lc-card h6{font-size:13px;font-weight:800;margin:0 0 8px;}
  .lc-chips{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;} .lc-chips button{font-size:11.5px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:99px;padding:3px 10px;font-weight:600;}
  .lc-chips button.ai{border-color:#9333EA;color:#7E22CE;background:color-mix(in srgb,#9333EA 8%,var(--surface));}
  .lc-tpl{display:flex;gap:6px;flex-wrap:wrap;} .lc-tpl button{font-size:12px;border:1px solid var(--line);border-radius:10px;padding:6px 10px;background:var(--surface);color:var(--text);font-weight:600;} .lc-tpl button.on{background:#7E22CE;border-color:#7E22CE;color:#fff;}
  .lc-sg{display:grid;grid-template-columns:1fr 1fr 34px;gap:6px;margin-bottom:6px;} .lc-sg button{border:0;background:none;color:#DC2626;font-size:16px;}
  .lc-side{align-self:stretch;}
  .lc-prev{position:sticky;top:70px;max-height:calc(100vh - 90px);overflow:auto;border-radius:14px;border:1px solid var(--line);background:#E7E5E4;padding:12px;}
  .lc-page{background:#fff;box-shadow:0 8px 30px -10px rgba(0,0,0,.3);padding:34px 40px;min-height:600px;}
  .lc-hint{font-size:11.5px;color:var(--text-dim);margin-top:3px;}
  .lc-bar{display:flex;gap:8px;flex-wrap:wrap;align-items:center;}
  .lc-rem{display:flex;gap:6px;align-items:center;}
  @media (max-width:1000px){ .lc-wrap{grid-template-columns:1fr;} .lc-prev{position:static;max-height:none;} .lc-page{padding:18px 16px;} .ltr{font-size:13.5px;} }
</style>

<div class="page-eyebrow"><a href="index.php?r=<?= $rid ?>&amp;dir=out" style="color:inherit;">📨 <?= h($REG['name']) ?></a></div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0"><?= $row ? '✎ जावक पत्र — क्र. ' . h($row['reg_no']) : '📝 नवीन जावक पत्र' ?> <small style="font-size:13px;color:var(--text-dim);font-weight:700;">· <?= h($REG['name']) ?></small></div>
  <div class="lc-bar">
    <?php if ($row): ?><a href="letter.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">🖨️ पत्र पहा / Print</a><?php endif; ?>
    <?php if (!$row && !$reply && !$copyOf && count($wRegs) > 1): ?><select class="form-select-custom" style="width:auto;font-size:12.5px;" onchange="location.href='compose.php?r='+this.value" title="कोणत्या नोंदवहीचं पत्र"><?php foreach ($wRegs as $wr): ?><option value="<?= (int)$wr['id'] ?>" <?= (int)$wr['id'] === $rid ? 'selected' : '' ?>>📚 <?= h($wr['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
    <?php if ($canSettings): ?><button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;" data-bs-toggle="modal" data-bs-target="#lcSet">⚙️ पत्र सेटिंग्ज</button><?php endif; ?>
  </div>
</div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($reply): ?><div class="alert alert-info" style="border-radius:10px;font-size:13px;">↩ <b>आवक क्र. <?= h($reply['reg_no']) ?></b> (<?= h($reply['party']) ?>) ला उत्तर — प्रति, संदर्भ व विषय भरले आहेत. जतन करताच आवक नोंदीत हा जावक क्र. जोडला जाईल.</div><?php endif; ?>
<?php if ($copyOf): ?><div class="alert alert-info" style="border-radius:10px;font-size:13px;">📄 जावक क्र. <?= h($copyOf['reg_no']) ?> वरून नवीन पत्र — दिनांक व क्रमांक नवीन; मजकूर बदला.</div><?php endif; ?>

<div class="lc-wrap">
<form method="POST" id="lcForm" data-no-smart-filter>
  <input type="hidden" name="action" value="save_letter_doc"><input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="reply" value="<?= $reply ? (int)$reply['id'] : '' ?>">
  <input type="hidden" name="template" value="<?= h($doc['template']) ?>"><input type="hidden" name="head" value="<?= h($SET['head']) ?>">

  <?php if (!$row): ?>
  <div class="lc-card"><h6>✨ नमुना निवडा</h6>
    <div class="lc-tpl"><?php foreach ($TPL as $k => [$l]): ?><button type="button" data-tpl="<?= $k ?>" class="<?= $doc['template'] === $k ? 'on' : '' ?>"><?= h($l) ?></button><?php endforeach; ?></div>
    <div class="lc-hint">नमुना निवडताच विषय व मजकूर तयार होतो — {…} च्या जागी माहिती भरा.</div>
  </div>
  <?php endif; ?>

  <div class="lc-card"><h6>🔢 क्रमांक व दिनांक</h6>
    <div class="row g-2">
      <div class="col-4"><label class="form-label-custom">जावक क्र. *</label><input type="text" name="reg_no" class="form-control-custom" value="<?= h($M['reg_no']) ?>" maxlength="20" style="font-weight:800;"><?php if (!$row): ?><div class="lc-hint">पुढचा क्र. आपोआप (<?= $nextNo ?>)</div><?php endif; ?></div>
      <div class="col-4"><label class="form-label-custom">दिनांक</label><input type="date" name="letter_date" class="form-control-custom" value="<?= h($M['date']) ?>"></div>
      <div class="col-4"><label class="form-label-custom">अंक</label><select name="digits" class="form-select-custom"><option value="mr" <?= $doc['digits'] === 'mr' ? 'selected' : '' ?>>१२३ मराठी</option><option value="en" <?= $doc['digits'] === 'en' ? 'selected' : '' ?>>123 English</option></select></div>
      <div class="col-12"><label class="form-label-custom">जा.क्र. उपसर्ग</label><input type="text" name="prefix" class="form-control-custom" value="<?= h($doc['prefix']) ?>" maxlength="120"><div class="lc-hint">उदा. जा.क्र./वि.जं.म.आ.ट्रस्ट/व्या. सा.प्र — पुढे "/ वर्ष/क्रमांक" आपोआप</div></div>
    </div>
  </div>

  <div class="lc-card"><h6>📮 प्रति</h6>
    <textarea name="to" class="form-control-custom" rows="4" placeholder="माननीय कार्याध्यक्ष साहेब,&#10;…"><?= h($doc['to']) ?></textarea>
    <div class="lc-chips" id="lcTo"><?php foreach (array_slice(array_keys($memTo), 0, 8) as $p): ?><button type="button" data-p="<?= h($p) ?>">＋ <?= h(mb_strimwidth($p, 0, 34, '…')) ?></button><?php endforeach; ?></div>
    <input type="text" class="form-control-custom mt-2" id="lcToQ" list="lcParties" placeholder="आधी पत्र पाठवलेले — नाव टाईप करा (पूर्ण पत्ता भरतो)">
  </div>

  <div class="lc-card"><h6>📌 विषय व मजकूर</h6>
    <label class="form-label-custom">विषय *</label><textarea name="subject" class="form-control-custom" rows="2" required><?= h($M['subject']) ?></textarea>
    <label class="form-label-custom mt-2">संदर्भ (असल्यास)</label><input type="text" name="reference" class="form-control-custom" value="<?= h($doc['reference']) ?>" maxlength="500" placeholder="उदा. आपले पत्र क्र. 577, दिनांक 01/04/2026">
    <div class="row g-2 mt-1"><div class="col-6"><label class="form-label-custom">संबोधन</label><input type="text" name="salutation" class="form-control-custom" value="<?= h($doc['salutation']) ?>" list="lcSal"></div></div>
    <label class="form-label-custom mt-2">मजकूर *</label>
    <textarea name="body" class="form-control-custom" rows="9" placeholder="उपरोक्त विषयास अनुसरून सविनय सादर करण्यात येते की, …"><?= h($doc['body']) ?></textarea>
    <div class="lc-hint">रिकामी ओळ = नवा परिच्छेद · "•" किंवा "-" ने सुरू होणारी ओळ = मुद्दा · **शब्द** = ठळक</div>
    <div class="lc-chips"><button type="button" class="ai" id="lcAuto">✨ विषयावरून सुरुवात व शेवट</button><button type="button" id="lcBullet">• मुद्दा जोडा</button></div>
    <label class="form-label-custom mt-2">विनंती (शेवटची ओळ)</label><textarea name="closing" class="form-control-custom" rows="2"><?= h($doc['closing']) ?></textarea>
    <label class="form-label-custom mt-2">समारोप</label><input type="text" name="final" class="form-control-custom" value="<?= h($doc['final']) ?>" list="lcFin">
  </div>

  <div class="lc-card"><h6>✍️ सह्या</h6>
    <div id="lcSigns"><?php foreach ($doc['signs'] as $s): ?><div class="lc-sg"><input name="sn[]" class="form-control-custom" value="<?= h($s[0] ?? '') ?>" placeholder="नाव (ऐच्छिक)"><input name="sp[]" class="form-control-custom" value="<?= h($s[1] ?? '') ?>" placeholder="पद, उदा. व्यवस्थापक सामान्य प्रशासन" list="lcPosts"><button type="button" class="lc-x" title="काढा">✕</button></div><?php endforeach; ?></div>
    <button type="button" class="btn btn-sm" id="lcAddSign" style="border:1px dashed var(--line);border-radius:8px;">＋ आणखी सही जोडा</button>
    <div class="lc-rem mt-3"><input type="checkbox" name="remark_on" value="1" id="lcRemOn" <?= trim((string)$doc['remark']) !== '' ? 'checked' : '' ?>><label for="lcRemOn" style="font-size:12.5px;font-weight:700;">शेरा / सहीसाठी जागा</label></div>
    <input type="text" name="remark" class="form-control-custom mt-1" value="<?= h(trim((string)$doc['remark']) !== '' ? $doc['remark'] : $SET['remark']) ?>" maxlength="200">
  </div>

  <div class="lc-card"><h6>📋 प्रत माहितीसाठी सादर</h6>
    <textarea name="copies" class="form-control-custom" rows="3" placeholder="एका ओळीत एक — उदा. लेखा विभाग"><?= h($doc['copies']) ?></textarea>
    <div class="lc-chips" id="lcCp"><?php foreach (['माननीय कार्याध्यक्ष साहेब', 'लेखा विभाग', 'एच.आर. विभाग', 'संबंधित विभाग प्रमुख', 'खरेदी विभाग', 'कार्यालयीन प्रत'] as $c): ?><button type="button" data-c="<?= h($c) ?>">＋ <?= h($c) ?></button><?php endforeach; ?></div>
  </div>

  <div class="d-flex gap-2 flex-wrap mb-4">
    <button type="submit" class="btn" style="background:#7E22CE;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा व जावक नोंदवहीत नोंदवा</button>
    <a href="<?= $row ? 'letter.php?id=' . (int)$row['id'] : 'index.php?r=' . $rid . '&amp;dir=out' ?>" class="btn" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
  </div>
</form>

<div class="lc-side">
  <div class="lc-prev"><div style="font-size:12px;font-weight:700;color:#57534E;margin-bottom:6px;">👁️ थेट Preview — Print असंच दिसेल</div><div class="lc-page" id="lcPrev"><?= orLetterHtml($doc, ['date' => $M['date'], 'subject' => $M['subject'], 'reg_no' => $M['reg_no'], 'fy' => $fyNow], $SET['head'], $doc['digits'] === 'mr') ?></div></div>
  <?php if ($recent && !$row): ?><div class="lc-card mt-3"><h6>🕘 अलीकडची पत्रे — "याच पत्रावरून नवीन"</h6>
    <?php foreach ($recent as $r): ?><a href="compose.php?copy=<?= (int)$r['id'] ?>" style="display:block;font-size:12.5px;padding:5px 0;border-bottom:1px solid var(--line);color:inherit;text-decoration:none;"><b>जावक <?= h($r['reg_no']) ?></b> · <?= h(orDate($r['letter_date'])) ?> — <?= h(mb_strimwidth((string)$r['subject'], 0, 80, '…')) ?></a><?php endforeach; ?>
  </div><?php endif; ?>
</div>
</div>

<!-- ⚙️ पत्र सेटिंग्ज -->
<div class="modal fade" id="lcSet" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content" style="border-radius:16px;">
  <form method="POST" data-no-smart-filter><input type="hidden" name="action" value="save_letter_settings"><input type="hidden" name="r" value="<?= $rid ?>"><input type="hidden" name="back_id" value="<?= (int)$id ?>">
    <div class="modal-header"><div class="modal-title" style="font-weight:800;">⚙️ पत्र सेटिंग्ज — <?= h($REG['name']) ?></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <label class="form-label-custom">Letterhead — शीर्षकाच्या ओळी</label><textarea name="head" class="form-control-custom" rows="4"><?= h($SET['head']) ?></textarea>
      <label class="form-label-custom mt-2">जा.क्र. उपसर्ग</label><input name="prefix" class="form-control-custom" value="<?= h($SET['prefix']) ?>" maxlength="120">
      <label class="form-label-custom mt-2">शेरा / सहीची ओळ</label><input name="remark" class="form-control-custom" value="<?= h($SET['remark']) ?>" maxlength="200">
      <label class="form-label-custom mt-2">नेहमीच्या सह्या (नाव ऐच्छिक, पद)</label>
      <?php foreach (array_pad($SET['signs'], 4, ['', '']) as $s): ?><div class="lc-sg" style="grid-template-columns:1fr 1fr;"><input name="dsn[]" class="form-control-custom" value="<?= h($s[0]) ?>" placeholder="नाव"><input name="dsp[]" class="form-control-custom" value="<?= h($s[1]) ?>" placeholder="पद"></div><?php endforeach; ?>
    </div>
    <div class="modal-footer"><button type="submit" class="btn" style="background:#7E22CE;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button></div>
  </form>
</div></div></div>

<datalist id="lcParties"><?php foreach (array_unique(array_merge(array_keys($memTo), $parties)) as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
<datalist id="lcSal"><option value="महोदय,"><option value="महोदया,"><option value="मा. महोदय,"><option value="सस्नेह नमस्कार,"></datalist>
<datalist id="lcFin"><option value="आपल्या मंजुरीसाठी सविनय सादर."><option value="आपल्या माहितीसाठी सविनय सादर."><option value="आपल्या पुढील कार्यवाहीसाठी सविनय सादर."><option value="कळावे."></datalist>
<datalist id="lcPosts"><option value="व्यवस्थापक सामान्य प्रशासन"><option value="एच.आर. व्यवस्थापक"><option value="प्रशासकीय व्यवस्थापक"><option value="लेखापाल"><option value="माननीय कार्याध्यक्ष"></datalist>
<script>
(function () {
  var f = document.getElementById('lcForm'), pv = document.getElementById('lcPrev');
  var TPL = <?= json_encode($TPL, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, MEM = <?= json_encode($memTo, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  // थेट Preview — server वरच छापायच्या पद्धतीने
  var tm = null, busy = false;
  function refresh() {
    clearTimeout(tm); tm = setTimeout(function () {
      var fd = new FormData(f); fd.set('action', 'preview_letter');
      fetch('compose.php', { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.ok ? r.text() : null; }).then(function (h) { if (h) pv.innerHTML = h; });
    }, 350);
  }
  f.addEventListener('input', refresh); f.addEventListener('change', refresh);
  // नमुना
  document.querySelectorAll('[data-tpl]').forEach(function (b) {
    b.addEventListener('click', function () {
      var t = TPL[b.dataset.tpl]; if (!t) return;
      if ((f.body.value.trim() || f.subject.value.trim()) && !confirm('सध्याचा विषय व मजकूर नमुन्याने बदलायचा?')) return;
      if (t[1]) f.subject.value = t[1];
      f.body.value = t[2]; f.closing.value = t[3] || ''; f.template.value = b.dataset.tpl;
      document.querySelectorAll('[data-tpl]').forEach(function (x) { x.classList.toggle('on', x === b); });
      if (!t[3]) autoClose(); refresh(); f.subject.focus();
    });
  });
  // विषयावरून शेवटची विनंती-ओळ (server सारखेच नियम)
  function closing(s) {
    s = s.replace(/\s+/g, ' ').replace(/^[\s.।]+|[\s.।]+$/g, '');
    if (!s) return 'तरी, उपरोक्त विषयास मंजुरी मिळावी, ही नम्र विनंती.';
    var V = { 'मिळविण्या': ['मिळावी', 'मिळावे'], 'मिळण्या': ['मिळावी', 'मिळावे'], 'मिळणे': ['मिळावी', 'मिळावे'], 'करण्या': ['करावी', 'करावे'], 'करणे': ['करावी', 'करावे'], 'देण्या': ['द्यावी', 'द्यावे'], 'देणे': ['द्यावी', 'द्यावे'], 'होण्या': ['व्हावी', 'व्हावे'], 'होणे': ['व्हावी', 'व्हावे'], 'घेण्या': ['घ्यावी', 'घ्यावे'], 'आणण्या': ['आणावी', 'आणावे'], 'आणणे': ['आणावी', 'आणावे'], 'पाठविण्या': ['पाठवावी', 'पाठवावे'], 'पाठवणे': ['पाठवावी', 'पाठवावे'] };
    var m = s.match(new RegExp('^(.*?)\\s*(' + Object.keys(V).join('|') + ')\\s*बाबत$'));
    if (m && m[1].trim()) { var pre = m[1].trim(), last = pre.split(' ').pop(); return 'तरी, ' + pre + ' ' + V[m[2]][/(ी|वाढ|रक्कम|व्यवस्था|सुविधा|रजा|मान्यता|सोय|परवानगी|कारवाई|बस|फी|मदत)$/.test(last) ? 0 : 1] + ', ही नम्र विनंती.'; }
    if (/बाबत$/.test(s)) return 'तरी, ' + s.replace(/बाबत$/, '').trim() + ' बाबत योग्य ती कार्यवाही व्हावी, ही नम्र विनंती.';
    return 'तरी, ' + s + ' — याबाबत योग्य ती कार्यवाही व्हावी, ही नम्र विनंती.';
  }
  function autoClose() { f.closing.value = closing(f.subject.value); }
  document.getElementById('lcAuto').addEventListener('click', function () {
    var open = 'उपरोक्त विषयास अनुसरून सविनय सादर करण्यात येते की, ';
    if (f.body.value.indexOf('उपरोक्त विषयास') < 0) f.body.value = open + f.body.value.replace(/^\s+/, '');
    autoClose(); if (!f.final.value.trim()) f.final.value = 'आपल्या मंजुरीसाठी सविनय सादर.'; refresh();
  });
  // विषय लिहिल्यावर शेवटची ओळ रिकामी असेल तर आपोआप
  f.subject.addEventListener('change', function () { if (!f.closing.value.trim() || /^तरी, /.test(f.closing.value) && f.closing.dataset.auto !== '0') { autoClose(); refresh(); } });
  f.closing.addEventListener('input', function () { f.closing.dataset.auto = '0'; });
  document.getElementById('lcBullet').addEventListener('click', function () { var b = f.body; b.value = b.value.replace(/\s*$/, '') + '\n• '; b.focus(); b.setSelectionRange(b.value.length, b.value.length); refresh(); });
  // प्रति — आधीचे पत्ते
  function setTo(p) { if (MEM[p]) f.to.value = MEM[p]; else f.to.value = p + ','; refresh(); }
  document.querySelectorAll('#lcTo [data-p]').forEach(function (b) { b.addEventListener('click', function () { setTo(b.dataset.p); }); });
  document.getElementById('lcToQ').addEventListener('change', function () { if (this.value.trim()) { setTo(this.value.trim()); this.value = ''; } });
  // प्रत माहितीसाठी
  document.querySelectorAll('#lcCp [data-c]').forEach(function (b) { b.addEventListener('click', function () { var c = f.copies; if (c.value.split('\n').map(function (x) { return x.trim(); }).indexOf(b.dataset.c) < 0) c.value = (c.value.trim() ? c.value.trim() + '\n' : '') + b.dataset.c; refresh(); }); });
  // सह्या
  var box = document.getElementById('lcSigns');
  function bindX() { box.querySelectorAll('.lc-x').forEach(function (x) { x.onclick = function () { if (box.children.length > 1) x.parentNode.remove(); else x.parentNode.querySelectorAll('input').forEach(function (i) { i.value = ''; }); refresh(); }; }); }
  document.getElementById('lcAddSign').addEventListener('click', function () { var r = box.firstElementChild.cloneNode(true); r.querySelectorAll('input').forEach(function (i) { i.value = ''; }); box.appendChild(r); bindX(); r.querySelector('input').focus(); });
  bindX();
  f.remark_on.addEventListener('change', refresh);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
