<?php
// 📊 साप्ताहिक व्यवस्थापन अहवाल — Preview, PDF, कोणाला जातो (Super Admin सेटिंग्ज)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s98')) . ' <a href="../index.php">' . h(t('s16')) . '</a></div>'); }
require_once __DIR__ . '/../includes/weekly-report.php';
$me = currentUser();
$orgId = activeOrgId();
$isSA = ($me['role'] ?? '') === 'super_admin';
$settingsReady = orgSettingsReady($pdo);

// कोणता आठवडा — शेवटचा दिवस (default: काल). मागचे 8 आठवडे निवडता येतात.
$end = $_GET['end'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || strtotime($end) > strtotime('yesterday') || strtotime($end) < strtotime('-120 days')) { $end = date('Y-m-d', strtotime('yesterday')); }
$period = weeklyReportPeriod($end);

if (isset($_GET['pdf'])) {
    @set_time_limit(120);
    $d = weeklyReportData($pdo, $orgId, $period);
    $pdf = weeklyReportPdf($d);
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Content-Disposition: ' . (($_GET['pdf'] ?? '') === 'dl' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/\.pdf$/i', '', weeklyReportFileName($d)) . '-' . date('His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$flash = $_SESSION['wr_flash'] ?? null; unset($_SESSION['wr_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSA && $settingsReady) {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        orgSettingSet($pdo, $orgId, 'weekly_report_enabled', !empty($_POST['enabled']) ? '1' : '0');
        orgSettingSet($pdo, $orgId, 'weekly_report_management', !empty($_POST['management']) ? '1' : '0');
        orgSettingSet($pdo, $orgId, 'weekly_report_emails', implode(', ', parseEmailList($_POST['emails'] ?? '')));
        logActivity('साप्ताहिक अहवाल सेटिंग्ज बदलल्या', 'organization', $orgId);
        $_SESSION['wr_flash'] = '✅ सेटिंग्ज जतन झाल्या.';
    } elseif ($a === 'test' || $a === 'send_all') {
        @set_time_limit(180);
        $to = $a === 'test' ? [$me['email'] ?? ''] : null;
        $r = weeklyReportSend($pdo, $orgId, $to);
        logActivity($a === 'test' ? 'साप्ताहिक अहवाल — Test Email' : 'साप्ताहिक अहवाल आत्ता पाठवला', 'organization', $orgId, $r['msg']);
        $_SESSION['wr_flash'] = ($r['ok'] ? '✅ ' : '❌ ') . ($a === 'test' && $r['ok'] ? 'Test अहवाल ' . $me['email'] . ' वर पाठवला' : $r['msg']);
    }
    header('Location: weekly.php');
    exit;
}

$d = weeklyReportData($pdo, $orgId, $period);
$sum = $d['summary'];
$recips = weeklyReportRecipients($pdo, $orgId);
$enabled = weeklyReportEnabled($pdo, $orgId);
$lastAt = orgSetting($pdo, $orgId, 'weekly_report_last_at');
$lastMsg = orgSetting($pdo, $orgId, 'weekly_report_last_msg');
$nextMon = date('Y-m-d', strtotime(date('N') == 1 && (int)date('G') < 7 ? 'today' : 'next monday'));
$weeks = [];
for ($i = 0; $i < 8; $i++) { $e = date('Y-m-d', strtotime('last sunday -' . ($i * 7) . ' days')); $weeks[$e] = weeklyReportPeriod($e); }
$isCustom = !isset($weeks[$period['end']]);
// पानावर तारखा भाषा-निरपेक्ष (हिंदी/English मध्येही वाचता याव्यात); PDF/Email मध्ये मराठी महिने
$fmt = fn($dt, $y = true) => date($y ? 'j M Y' : 'j M', strtotime($dt));
$periodTxt = $fmt($period['start'], false) . ' – ' . $fmt($period['end']);
// निष्कर्षाचे तुकडे वेगळ्या <span> मध्ये — म्हणजे प्रत्येक तुकडा स्वतंत्रपणे भाषांतरित होतो
$segments = fn($msg) => implode('', array_map(fn($x) => preg_match('/^( — |, | \(|\))$/u', $x) ? h($x) : '<span>' . h($x) . '</span>', preg_split('/( — |, | \(|\))/u', $msg, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY)));
$lvlTone = ['good' => 'ok', 'warn' => 'warn', 'bad' => 'bad'];

$pageTitle = 'साप्ताहिक अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .wr-hero{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:20px 22px;border-radius:16px;color:#fff;background:linear-gradient(135deg,#191C3A,#0E1024);position:relative;overflow:hidden;margin-bottom:16px;}
  .wr-hero::after{content:'';position:absolute;right:-50px;bottom:-80px;width:240px;height:240px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--rail) 50%,transparent),transparent 70%);}
  .wr-hero > *{position:relative;z-index:1;} .wr-hero h2{font-size:18px;margin:0 0 4px;font-weight:700;} .wr-hero p{margin:0;font-size:13px;color:#C9CCE0;}
  .wr-hero .btns{display:flex;gap:8px;flex-wrap:wrap;}
  .wr-btn{display:inline-flex;align-items:center;gap:6px;border-radius:10px;font-size:13px;padding:9px 16px;font-weight:600;text-decoration:none;border:1px solid transparent;cursor:pointer;}
  .wr-btn.pri{background:var(--rail);color:#fff;} .wr-btn.ghost{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff;}
  .wr-btn.line{background:transparent;border-color:var(--rail);color:var(--rail);}
  .wr-alert{display:flex;gap:10px;align-items:flex-start;padding:9px 12px;border-radius:10px;font-size:13px;margin-bottom:6px;line-height:1.5;}
  .wr-alert.bad{background:color-mix(in srgb,var(--rose,#e11d48) 10%,transparent);} .wr-alert.warn{background:color-mix(in srgb,var(--amber,#d97706) 12%,transparent);} .wr-alert.good{background:color-mix(in srgb,var(--green,#16a34a) 10%,transparent);}
  .wr-alert i{margin-top:3px;} .wr-alert.bad i{color:var(--rose,#e11d48);} .wr-alert.warn i{color:var(--amber,#d97706);} .wr-alert.good i{color:var(--green,#16a34a);}
  .wr-recip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);font-size:12px;margin:0 6px 6px 0;}
  .wr-day{display:grid;grid-template-columns:86px 1fr 34px;gap:10px;align-items:center;font-size:12.5px;margin-bottom:6px;}
  .wr-day .b{height:9px;border-radius:99px;background:var(--canvas);overflow:hidden;} .wr-day .b span{display:block;height:100%;border-radius:99px;background:linear-gradient(90deg,var(--rail),color-mix(in srgb,var(--rail) 60%,#F2C27B));}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">📊 साप्ताहिक अहवाल</div>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <select name="end" class="form-select-custom" onchange="this.form.submit()" style="max-width:280px;">
      <?php if ($isCustom): ?><option value="<?= h($period['end']) ?>" selected>गेले 7 दिवस (<?= h($fmt($period['end'], false)) ?> पर्यंत)</option><?php endif; ?>
      <?php foreach ($weeks as $e => $p): ?><option value="<?= h($e) ?>" <?= !$isCustom && $period['end'] === $e ? 'selected' : '' ?>><?= h($fmt($p['start'], false) . ' – ' . $fmt($p['end'], false)) ?></option><?php endforeach; ?>
    </select>
  </form>
</div>

<?php if ($flash): ?><div class="alert <?= str_starts_with($flash, '❌') ? 'alert-danger' : 'alert-success' ?>"><?= h($flash) ?></div><?php endif; ?>

<div class="wr-hero">
  <div>
    <h2><?= h($periodTxt) ?></h2>
    <p>दर सोमवारी सकाळी हाच अहवाल PDF सह Management ला Email वर जातो<?= $enabled ? '' : ' (सध्या बंद)' ?>.</p>
  </div>
  <div class="btns">
    <a class="wr-btn pri" href="?end=<?= h($period['end']) ?>&pdf=1&v=<?= time() ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> PDF पाहा</a>
    <a class="wr-btn ghost" href="?end=<?= h($period['end']) ?>&pdf=dl&v=<?= time() ?>"><i class="fa-solid fa-download"></i> Download</a>
  </div>
</div>

<?= smartTiles([
    ['label' => 'आरोग्य गुण', 'value' => $sum['count'] ? $sum['score'] . '/100' : '—', 'sub' => $sum['good'] . ' चांगले · ' . $sum['warn'] . ' लक्ष द्या · ' . $sum['bad'] . ' गंभीर', 'tone' => $lvlTone[$sum['level']], 'href' => '../departments/all.php'],
    ['label' => 'कामांच्या नोंदी', 'value' => $d['work']['total'], 'sub' => ($d['work']['total'] ? (int)round($d['work']['done'] * 100 / $d['work']['total']) : 0) . '% पूर्ण · मागच्या आठवड्यात ' . $d['workPrev'], 'tone' => $d['work']['total'] >= $d['workPrev'] ? 'ok' : 'warn'],
    ['label' => 'मुदतबाह्य Tasks', 'value' => (int)($d['tasks']['overdue'] ?? 0), 'sub' => (int)($d['tasks']['done_week'] ?? 0) . ' या आठवड्यात पूर्ण', 'tone' => ($d['tasks']['overdue'] ?? 0) ? 'bad' : 'ok'],
    ['label' => 'खर्च', 'value' => weeklyReportMoney($d['spendWeek']), 'sub' => 'FY: ' . weeklyReportMoney($d['fySpent']) . ($d['fyAlloc'] ? ' / ' . weeklyReportMoney($d['fyAlloc']) : ''), 'tone' => 'ok'],
]) ?>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🎯 लक्ष द्या</div><div class="sub">अहवालातले आपोआप काढलेले निष्कर्ष</div></div>
      <div style="padding:12px 18px 14px;">
        <?php if (!$d['alerts'] && !$d['good']): ?><div style="font-size:13px;color:var(--text-dim);">विशेष काही नाही — सगळं ठीक.</div><?php endif; ?>
        <?php foreach ($d['alerts'] as [$lv, $msg]): ?><div class="wr-alert <?= $lv ?>"><i class="fa-solid <?= $lv === 'bad' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation' ?>"></i><span><?= $segments($msg) ?></span></div><?php endforeach; ?>
        <?php foreach ($d['good'] as $msg): ?><div class="wr-alert good"><i class="fa-solid fa-circle-check"></i><span><?= $segments($msg) ?></span></div><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">📅 दिवसनिहाय कामं</div></div>
      <div style="padding:14px 18px;">
        <?php $mx = max(1, max($d['days'] ?: [0])); $dn = ['Mon' => 'सोमवार', 'Tue' => 'मंगळवार', 'Wed' => 'बुधवार', 'Thu' => 'गुरुवार', 'Fri' => 'शुक्रवार', 'Sat' => 'शनिवार', 'Sun' => 'रविवार'];
        foreach ($d['days'] as $date => $c): ?>
          <div class="wr-day"><span><?= $dn[date('D', strtotime($date))] ?> <span style="color:var(--text-dim);font-size:11px;"><?= date('d/m', strtotime($date)) ?></span></span><div class="b"><span style="width:<?= round($c / $mx * 100) ?>%;"></span></div><b style="text-align:right;"><?= $c ?></b></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">🏢 विभागनिहाय (कमकुवत आधी)</div><div class="sub">पूर्ण तपशील PDF मध्ये</div></div>
  <div style="overflow-x:auto;">
    <table class="rtable">
      <thead><tr><th>विभाग</th><th>आरोग्य</th><th>कामं (आठवडा)</th><th>मुदतबाह्य Tasks</th><th>SLA बाहेर</th></tr></thead>
      <tbody>
      <?php foreach (array_slice($d['health'], 0, 8) as $r): ?>
        <tr>
          <td><b><?= h($r['name']) ?></b></td>
          <td><span class="status-chip <?= $r['level'] === 'good' ? 'done' : ($r['level'] === 'warn' ? 'pending' : 'overdue') ?>"><?= $r['score'] ?>/100</span></td>
          <td><?= $r['weekWork'] ?><?= $r['weekRate'] !== null ? ' <span style="color:var(--text-dim);font-size:12px;">(' . $r['weekRate'] . '%)</span>' : '' ?></td>
          <td style="<?= $r['overdueTasks'] ? 'color:var(--rose);font-weight:700;' : '' ?>"><?= $r['overdueTasks'] ?></td>
          <td style="<?= $r['overdueReq'] ? 'color:var(--amber);font-weight:700;' : '' ?>"><?= $r['overdueReq'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📧 कोणाला जातो</div><div class="sub">पुढचा: <?= $enabled ? h($fmt($nextMon)) . ', सकाळी 7 नंतर' : 'बंद' ?><?= $lastAt ? ' · शेवटचा: ' . h(date('d M, h:i A', strtotime($lastAt))) . ' — ' . h($lastMsg) : '' ?></div></div>
  <div style="padding:14px 18px 18px;">
    <?php if (!$recips): ?><div class="alert alert-warning" style="font-size:13px;">एकही Email पत्ता नाही — Management पदावरच्यांच्या Profile मध्ये Email भरा किंवा खाली जादा पत्ते टाका.</div><?php endif; ?>
    <div><?php foreach ($recips as $e): ?><span class="wr-recip"><i class="fa-regular fa-envelope"></i><?= h($e) ?></span><?php endforeach; ?></div>

    <?php if ($isSA && $settingsReady): ?>
      <form method="POST" class="mt-3">
        <input type="hidden" name="action" value="save">
        <div class="d-flex gap-4 flex-wrap mb-3" style="font-size:13.5px;">
          <label class="d-flex align-items-center gap-2"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:17px;height:17px;accent-color:var(--rail);"> दर सोमवारी आपोआप पाठवा</label>
          <label class="d-flex align-items-center gap-2"><input type="checkbox" name="management" value="1" <?= orgSetting($pdo, $orgId, 'weekly_report_management', '1') === '1' ? 'checked' : '' ?> style="width:17px;height:17px;accent-color:var(--rail);"> Management पदावरील सर्वांना</label>
        </div>
        <label class="form-label-custom">जादा Email पत्ते (उदा. अध्यक्षांचा वैयक्तिक Email)</label>
        <textarea name="emails" class="form-control-custom mb-3" rows="2" placeholder="president@gmail.com, trustee@example.com"><?= h(orgSetting($pdo, $orgId, 'weekly_report_emails', '')) ?></textarea>
        <div class="d-flex gap-2 flex-wrap">
          <button class="wr-btn pri" style="border:0;"><i class="fa-solid fa-floppy-disk"></i> जतन करा</button>
        </div>
      </form>
      <div class="d-flex gap-2 flex-wrap mt-2">
        <form method="POST" onsubmit="this.querySelector('button').disabled=true;"><input type="hidden" name="action" value="test"><button class="wr-btn line"><i class="fa-solid fa-flask"></i> मला Test पाठवा</button></form>
        <?php if ($recips): ?><form method="POST" onsubmit="if(!confirm('आत्ताच सगळ्या <?= count($recips) ?> पत्त्यांवर अहवाल पाठवायचा?'))return false;this.querySelector('button').disabled=true;"><input type="hidden" name="action" value="send_all"><button class="wr-btn line"><i class="fa-solid fa-paper-plane"></i> आत्ता सर्वांना पाठवा</button></form><?php endif; ?>
      </div>
    <?php elseif ($isSA): ?>
      <div class="alert alert-warning mt-3" style="font-size:13px;">पत्ते बदलण्यासाठी आधी <a href="../system-update/db-update.php">Database Update</a> करा.</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
