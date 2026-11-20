<?php
// 📥 तुलनात्मक तक्ते — जुनी Excel (.xlsx) आयात: प्रत्येक Sheet = एक तक्ता; आधी Preview (Excel मधील एकूण vs हिशोब), मग निवडलेले आयात
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/purchase-compare.php';
if (!purchaseCmpCanEdit()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">परवानगी नाही.</div>'); }
if (!purchaseCmpReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$error = '';
$key = 'pcmp_import_' . $orgId;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'upload_xlsx') {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) $error = 'Excel File निवडा.';
        elseif ($f['size'] > 15 * 1024 * 1024) $error = 'File खूप मोठी आहे (कमाल 15 MB).';
        elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') $error = 'फक्त .xlsx (Excel) File चालते.';
        else {
            try {
                $parsed = purchaseCmpParseWorkbook($f['tmp_name']);
                $charts = [];
                foreach ($parsed as $p) if ($p['chart']) { $p['chart']['sheet'] = $p['sheet']; $charts[] = $p['chart']; }
                if (!$charts) $error = 'या File मध्ये तुलनात्मक तक्ता सापडला नाही ("Particular" व "Rate / Amount" रकाने हवेत).';
                else {
                    $tmp = tempnam(sys_get_temp_dir(), 'pcmp');
                    file_put_contents($tmp, json_encode(['file' => $f['name'], 'charts' => $charts, 'skipped' => count($parsed) - count($charts)], JSON_UNESCAPED_UNICODE));
                    $_SESSION[$key] = $tmp;
                    header('Location: import.php'); exit;
                }
            } catch (Throwable $e) { $error = 'Excel वाचता आली नाही: ' . $e->getMessage(); }
        }
    } elseif ($act === 'import_selected') {
        $tmp = $_SESSION[$key] ?? '';
        $data = $tmp && is_file($tmp) ? json_decode((string)file_get_contents($tmp), true) : null;
        if (!$data) { $error = 'आधी Excel File निवडा.'; }
        else {
            $pick = array_map('intval', (array)($_POST['pick'] ?? []));
            $dept = mb_substr(trim((string)($_POST['department'] ?? '')), 0, 150);
            $n = 0;
            $pdo->beginTransaction();
            try {
                // अहवालाचं शीर्षक (संस्था / पत्ता) — अजून ठरवलं नसेल तर Excel मधलं
                $hd = ''; foreach ($data['charts'] as $d0) if (!empty($d0['head'])) { $hd = $d0['head']; break; }
                if ($hd !== '' && function_exists('orgSetting') && (string)orgSetting($pdo, $orgId, 'purchase_report_head', '') === '') orgSettingSet($pdo, $orgId, 'purchase_report_head', $hd);
                foreach ($data['charts'] as $i => $d) {
                    if (!in_array($i, $pick, true)) continue;
                    $t = trim((string)($_POST['title'][$i] ?? ''));
                    if ($t !== '') $d['title'] = mb_substr($t, 0, 255);
                    $d['department'] = $dept; $d['status'] = 'final';
                    $d['source'] = mb_substr('Excel: ' . $data['file'] . ' › ' . $d['sheet'], 0, 150);
                    purchaseCmpSave($pdo, $orgId, (int)$user['id'], $d);
                    $n++;
                }
                $pdo->commit();
            } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
            @unlink($tmp); unset($_SESSION[$key]);
            logActivity('तुलनात्मक तक्ते — Excel आयात', 'organization', $orgId, $n . ' तक्ते · ' . $data['file']);
            $_SESSION['flash'] = '✓ ' . $n . ' तुलनात्मक तक्ते आयात झाले.';
            header('Location: index.php'); exit;
        }
    } elseif ($act === 'clear_preview') {
        if (!empty($_SESSION[$key])) @unlink($_SESSION[$key]);
        unset($_SESSION[$key]);
        header('Location: import.php'); exit;
    }
}

$data = null;
if (!empty($_SESSION[$key]) && is_file($_SESSION[$key])) $data = json_decode((string)file_get_contents($_SESSION[$key]), true);
$existing = [];
if ($data) {
    $st = $pdo->prepare("SELECT CONCAT(title, '|', cmp_date) FROM purchase_comparisons WHERE organization_id = ?"); $st->execute([$orgId]);
    $existing = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
}
$depts = function_exists('purchaseDepartments') ? purchaseDepartments($pdo, $orgId) : [];

$pageTitle = 'तुलनात्मक तक्ते — Excel आयात';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .im-row{display:grid;grid-template-columns:28px 1fr auto;gap:10px;align-items:start;border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:9px 12px;margin-bottom:7px;}
  .im-row input[type=text]{width:100%;border:1px solid var(--line);border-radius:8px;padding:5px 8px;font:inherit;font-size:13px;font-weight:700;background:var(--surface);color:var(--text);}
  .im-m{font-size:11.5px;color:var(--text-dim);margin-top:3px;} .im-v{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;} .im-v span{font-size:11px;background:var(--canvas);border-radius:7px;padding:2px 7px;} .im-v span.l1{background:#DCFCE7;color:#166534;font-weight:700;}
  .im-ok{font-size:11.5px;font-weight:700;white-space:nowrap;} .im-ok.g{color:#15803D;} .im-ok.w{color:#B45309;}
</style>
<div class="page-eyebrow">📊 तुलनात्मक तक्ते</div>
<div class="page-title">📥 Excel मधून आयात</div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div><?php endif; ?>

<?php if (!$data): ?>
<div class="panel" style="padding:16px 18px;max-width:720px;">
  <div style="font-size:13px;color:var(--text-dim);margin-bottom:10px;">जुनी "Comparison Chart" Excel (.xlsx) निवडा — <b>प्रत्येक Sheet एक तक्ता</b> म्हणून वाचली जाते: पुरवठादारांची नावं, वस्तू, नग, दर, रक्कम, GST, वाहतूक, सूट, टीप व सह्या. आयात करण्यापूर्वी Preview दिसेल.</div>
  <form method="POST" enctype="multipart/form-data" data-no-smart-filter>
    <input type="hidden" name="action" value="upload_xlsx">
    <input type="file" name="file" accept=".xlsx" class="form-control-custom" required>
    <button type="submit" class="btn mt-2" style="background:#2563EB;color:#fff;border-radius:10px;font-weight:700;">Preview पहा →</button>
    <a href="index.php" class="btn mt-2" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
  </form>
</div>
<?php else: ?>
<form method="POST" data-no-smart-filter>
  <input type="hidden" name="action" value="import_selected">
  <div class="panel mb-2" style="padding:12px 14px;display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
    <div style="flex:1;min-width:220px;"><b><?= h($data['file']) ?></b><div class="im-m"><?= count($data['charts']) ?> तक्ते सापडले<?= $data['skipped'] ? ' · ' . (int)$data['skipped'] . ' Sheets मध्ये तक्ता नाही (वगळल्या)' : '' ?> · ✓ = Excel मधील एकूण रक्कम व हिशोब जुळतो</div></div>
    <div><label class="form-label-custom" style="margin:0;">विभाग (सगळ्यांसाठी, ऐच्छिक)</label><input type="text" name="department" class="form-control-custom" list="imDepts" style="min-width:200px;"></div>
    <label style="font-size:12.5px;display:flex;gap:6px;align-items:center;"><input type="checkbox" id="imAll" checked> सगळे निवडा</label>
  </div>
  <?php foreach ($data['charts'] as $i => $d): $C = purchaseCmpComputeDraft($d); $dup = isset($existing[$d['title'] . '|' . ($d['cmp_date'] ?: date('Y-m-d'))]);
    $match = true; foreach ($d['vendors'] as $vi => $v) { $e = $d['excel_total'][$vi] ?? null; if ($e !== null && abs($e - $C['vendors'][$vi]['total']) > 1) $match = false; } ?>
    <div class="im-row">
      <input type="checkbox" name="pick[]" value="<?= $i ?>" class="imPick" <?= $dup ? '' : 'checked' ?> style="margin-top:6px;">
      <div>
        <input type="text" name="title[<?= $i ?>]" value="<?= h($d['title']) ?>" maxlength="255">
        <div class="im-m">Sheet: <?= h($d['sheet']) ?> · 📅 <?= $d['cmp_date'] ? h(date('d.m.Y', strtotime($d['cmp_date']))) : '—' ?> · <?= count($d['items']) ?> वस्तू · <?= count($d['vendors']) ?> पुरवठादार<?= $dup ? ' · <b style="color:#B45309;">आधीच आयात केलेला दिसतो</b>' : '' ?></div>
        <div class="im-v"><?php foreach ($C['order'] as $vi): $cv = $C['vendors'][$vi]; $e = $d['excel_total'][$vi] ?? null; ?>
          <span class="<?= $cv['rank'] === 1 ? 'l1' : '' ?>"><?= $cv['rank'] ? 'L' . $cv['rank'] . ' ' : '' ?><?= h(mb_strimwidth($d['vendors'][$vi]['name'], 0, 26, '…')) ?> · <?= h(purchaseCmpMoney($cv['total'])) ?><?= $e !== null && abs($e - $cv['total']) > 1 ? ' (Excel: ' . h(purchaseCmpMoney($e)) . ')' : '' ?><?= $cv['missing'] ? ' ⚠' . $cv['missing'] : '' ?></span>
        <?php endforeach; ?></div>
      </div>
      <div class="im-ok <?= $match ? 'g' : 'w' ?>"><?= $match ? '✓ जुळतो' : '⚠ Excel मधील एकूण वेगळा' ?></div>
    </div>
  <?php endforeach; ?>
  <div class="d-flex gap-2 mt-2 flex-wrap">
    <button type="submit" class="btn" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;">✓ निवडलेले तक्ते आयात करा</button>
  </div>
</form>
<form method="POST" class="mt-2"><input type="hidden" name="action" value="clear_preview"><button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">✕ दुसरी File निवडा</button></form>
<div style="font-size:12px;color:var(--text-dim);margin-top:8px;">⚠ = Excel मधील एकूण रक्कम व दर × नग यांचा हिशोब वेगळा (बहुतेक Excel मधील सूत्राची चूक). आयात केल्यावर तक्ता उघडून तपासा / दुरुस्त करा.</div>
<datalist id="imDepts"><?php foreach ($depts as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
<script>window.addEventListener('load', function () { var a = document.getElementById('imAll'); if (a) a.addEventListener('change', function () { document.querySelectorAll('.imPick').forEach(function (c) { c.checked = a.checked; }); }); });</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
