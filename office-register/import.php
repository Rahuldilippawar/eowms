<?php
// 📥 आवक-जावक नोंदवही — Excel आयात (महिन्यानुसार "आवक एप्रिल", "जावक एप्रिल" … Sheets). आधी पूर्वावलोकन, मग आयात; आधीच असलेले क्रमांक वगळतो.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orCanEdit()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">Excel आयात करण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
// 📚 नोंदवही — Excel आयात "बदल" access असलेल्या नोंदवहीतच
$REG = orCurrentRegister($pdo, $orgId, 'edit');
if (!$REG) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">कोणत्याही नोंदवहीत Excel आयात करण्याचा access नाही.</div>'); }
$rid = (int)$REG['id'];
$eRegs = array_filter(array_intersect_key(orRegisters($pdo, $orgId), orAccessMap($pdo, $orgId)), fn($r) => orLevel($pdo, $orgId, $r['id']) === 'edit');

$tmpDir = sys_get_temp_dir();
$sessKey = 'or_import_file';
$error = ''; $P = null;
$fileOk = fn($p) => is_string($p) && preg_match('#^' . preg_quote($tmpDir, '#') . '/or-imp-[a-f0-9]{24}\.xlsx$#', $p) && is_file($p);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'preview_file') {
        $up = $_FILES['file'] ?? null;
        if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK) $error = 'Excel File निवडा.';
        elseif ($up['size'] > 10 * 1024 * 1024) $error = 'File 10 MB पेक्षा मोठी आहे.';
        elseif (strtolower(pathinfo((string)$up['name'], PATHINFO_EXTENSION)) !== 'xlsx' || (string)@file_get_contents($up['tmp_name'], false, null, 0, 4) !== "PK\x03\x04") $error = 'फक्त Excel (.xlsx) File चालते.';
        else {
            $dest = $tmpDir . '/or-imp-' . bin2hex(random_bytes(12)) . '.xlsx';
            if (!move_uploaded_file($up['tmp_name'], $dest)) $error = 'File जतन करता आली नाही.';
            else {
                if ($fileOk($_SESSION[$sessKey] ?? null)) @unlink($_SESSION[$sessKey]);
                $_SESSION[$sessKey] = $dest; $_SESSION[$sessKey . '_name'] = mb_substr((string)$up['name'], 0, 120);
                header('Location: import.php?preview=1&r=' . $rid); exit;
            }
        }
    }
    if ($act === 'import_file' && $fileOk($_SESSION[$sessKey] ?? null)) {
        try {
            $P = orParseWorkbook($_SESSION[$sessKey]);
            [$n, $skip] = orImportRows($pdo, $orgId, $rid, $P['rows'], (int)$user['id'], 'excel');
            if (!empty($_POST['use_head'])) {
                $pdo->prepare("UPDATE office_registers SET report_head = COALESCE(?, report_head), title = COALESCE(?, title), report_signs = COALESCE(?, report_signs), updated_at = NOW() WHERE id = ? AND organization_id = ?")
                    ->execute([$P['head'] ? implode("\n", $P['head']) : null, $P['title'] !== '' ? $P['title'] : null, $P['signs'] ? implode("\n", $P['signs']) : null, $rid, $orgId]);
            }
            @unlink($_SESSION[$sessKey]); unset($_SESSION[$sessKey]);
            logActivity('आवक-जावक नोंदवही — Excel आयात', 'organization', $orgId, $n . ' नवीन, ' . $skip . ' आधीच होत्या');
            $_SESSION['flash'] = '✓ Excel मधील ' . $n . ' नवीन नोंदी आयात झाल्या.' . ($skip ? ' ' . $skip . ' आधीच होत्या — वगळल्या.' : '');
            header('Location: index.php?r=' . $rid); exit;
        } catch (Throwable $e) { $error = 'आयात करता आली नाही: ' . $e->getMessage(); }
    }
}

$preview = null;
if (isset($_GET['preview']) && $fileOk($_SESSION[$sessKey] ?? null)) {
    try {
        $P = orParseWorkbook($_SESSION[$sessKey]);
        // आधीच असलेल्या नोंदी
        $ex = [];
        $st = $pdo->prepare("SELECT CONCAT(direction, '|', fy, '|', reg_no) FROM office_letters WHERE organization_id = ? AND register_id = ?"); $st->execute([$orgId, $rid]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $k) $ex[$k] = 1;
        $new = 0; $old = 0; $stc = []; $bySheet = [];
        foreach ($P['rows'] as $r) {
            $isOld = isset($ex[$r['_dir'] . '|' . $r['fy'] . '|' . $r['reg_no']]);
            $isOld ? $old++ : $new++;
            $s = orClassify($r['work_status']); $stc[$s] = ($stc[$s] ?? 0) + 1;
            $bySheet[$r['_sheet']] = ($bySheet[$r['_sheet']] ?? 0) + ($isOld ? 0 : 1);
        }
        $preview = ['new' => $new, 'old' => $old, 'st' => $stc, 'bySheet' => $bySheet];
    } catch (Throwable $e) { $error = 'Excel वाचता आली नाही: ' . $e->getMessage(); }
}

$pageTitle = 'आवक-जावक — Excel आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .oi-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:16px;margin-bottom:12px;}
  .oi-t{width:100%;border-collapse:collapse;font-size:12.5px;} .oi-t th,.oi-t td{border-bottom:1px solid var(--line);padding:5px 8px;text-align:left;} .oi-t th{color:var(--text-dim);font-weight:700;}
  .oi-w{font-size:12.5px;max-height:220px;overflow:auto;border-left:3px solid #F59E0B;padding:4px 10px;background:color-mix(in srgb,#F59E0B 7%,transparent);border-radius:0 8px 8px 0;}
  .oi-k{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:8px;margin:10px 0;} .oi-k div{border:1px solid var(--line);border-radius:10px;padding:8px 10px;} .oi-k span{display:block;font-size:11.5px;color:var(--text-dim);} .oi-k b{font-size:18px;}
</style>
<div class="page-eyebrow"><a href="index.php?r=<?= $rid ?>" style="color:inherit;">📨 <?= h($REG['name']) ?></a></div>
<div class="page-title">📥 Excel आयात <small style="font-size:13px;color:var(--text-dim);font-weight:700;">· <?= h($REG['name']) ?></small></div>
<?php if (count($eRegs) > 1 && empty($preview)): ?><div class="mb-2"><select class="form-select-custom" style="width:auto;" onchange="location.href='import.php?r='+this.value"><?php foreach ($eRegs as $er): ?><option value="<?= (int)$er['id'] ?>" <?= (int)$er['id'] === $rid ? 'selected' : '' ?>>📚 <?= h($er['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>

<?php if (!$preview): ?>
<form method="POST" enctype="multipart/form-data" class="oi-card" data-no-smart-filter>
  <input type="hidden" name="action" value="preview_file"><input type="hidden" name="r" value="<?= $rid ?>">
  <div style="font-size:13px;line-height:1.7;margin-bottom:10px;">
    नोंदवहीची Excel — प्रत्येक महिन्याची <b>"आवक एप्रिल"</b>, <b>"जावक एप्रिल"</b> अशी Sheet.<br>
    रकाने: <b>अ.क्र. · आवक क्र. / जावक क्र. · दिनांक · प्राप्त पत्राचा जावक क्र. · कोणाकडून / कोणाकडे · विषय · शेरा · कामाची स्थिती</b> (क्रम बदलला तरी चालतो).<br>
    आधीच असलेले क्रमांक (त्याच वर्षाचे) आपोआप वगळले जातात — म्हणून तीच Excel पुन्हा दिली तरी दुबार नोंदी होत नाहीत.
  </div>
  <input type="file" name="file" accept=".xlsx" class="form-control-custom" required style="max-width:420px;">
  <button type="submit" class="btn mt-2" style="background:#2563EB;color:#fff;border-radius:10px;font-weight:700;">पूर्वावलोकन ➜</button>
</form>
<?php else: ?>
<div class="oi-card">
  <div style="font-weight:800;">📄 <?= h($_SESSION[$sessKey . '_name'] ?? 'Excel') ?></div>
  <div class="oi-k">
    <div><span>नवीन नोंदी (आयात होतील)</span><b style="color:#15803D;"><?= $preview['new'] ?></b></div>
    <div><span>आधीच आहेत (वगळणार)</span><b><?= $preview['old'] ?></b></div>
    <?php foreach (orStatuses() as $k => [$l]): if (!empty($preview['st'][$k])): ?><div><span><?= h($l) ?></span><b><?= (int)$preview['st'][$k] ?></b></div><?php endif; endforeach; ?>
  </div>
  <table class="oi-t"><thead><tr><th>Sheet</th><th>प्रकार</th><th>महिना</th><th>नोंदी</th><th>नवीन</th></tr></thead><tbody>
  <?php foreach ($P['sheets'] as [$sn, $sd, $sm, $sc]): ?><tr><td><?= h($sn) ?></td><td><?= orDirLabel($sd) ?></td><td><?= $sm ? h(orMonthNames()[$sm]) : '—' ?></td><td><?= (int)$sc ?></td><td><b><?= (int)($preview['bySheet'][$sn] ?? 0) ?></b></td></tr><?php endforeach; ?>
  </tbody></table>
  <?php if ($P['warn']): ?><div class="mt-3"><div style="font-weight:700;font-size:13px;margin-bottom:4px;">⚠️ तपासा (<?= count($P['warn']) ?>)</div><div class="oi-w"><?php foreach ($P['warn'] as $w): ?><div><?= h($w) ?></div><?php endforeach; ?></div></div><?php endif; ?>
  <?php if ($P['head'] || $P['signs']): ?><div class="mt-3" style="font-size:12.5px;color:var(--text-dim);">शीर्षक: <?= h(implode(' · ', $P['head'])) ?><?= $P['signs'] ? '<br>सह्या: ' . h(implode(' · ', array_map(fn($s) => trim(str_replace('|', ' — ', $s), ' —'), $P['signs']))) : '' ?></div><?php endif; ?>
  <form method="POST" class="mt-3 d-flex gap-2 flex-wrap align-items-center" data-no-smart-filter>
    <input type="hidden" name="action" value="import_file"><input type="hidden" name="r" value="<?= $rid ?>">
    <?php if ($P['head'] || $P['signs']): ?><label style="font-size:12.5px;"><input type="checkbox" name="use_head" value="1"> अहवालाचं शीर्षक व सह्या या Excel प्रमाणे ठेवा</label><?php endif; ?>
    <button type="submit" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;" <?= $preview['new'] ? '' : 'disabled' ?>>✓ <?= $preview['new'] ?> नोंदी आयात करा</button>
    <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">दुसरी File</a>
  </form>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
