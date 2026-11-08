<?php
// 🌙 दैनिक व्यवस्थापन अहवाल — Preview (Charts), PDF, वेळ व पाठवणं (Super Admin)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s98')) . ' <a href="../index.php">' . h(t('s16')) . '</a></div>'); }
require_once __DIR__ . '/../includes/daily-report.php';
$me = currentUser();
$orgId = activeOrgId();
$isSA = ($me['role'] ?? '') === 'super_admin';
$settingsReady = orgSettingsReady($pdo);

$date = $_GET['date'] ?? '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) > strtotime('today') || strtotime($date) < strtotime('-90 days')) { $date = date('Y-m-d'); }

if (isset($_GET['pdf'])) {
    @set_time_limit(120);
    $D = dailyReportData($pdo, $orgId, $date);
    $pdf = dailyReportPdf($D);
    csrfStopOutputRewrite();
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/pdf');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-LiteSpeed-Cache-Control: no-cache');
    header('Content-Disposition: ' . (($_GET['pdf'] ?? '') === 'dl' ? 'attachment' : 'inline') . '; filename="' . preg_replace('/\.pdf$/i', '', dailyReportFileName($D)) . '-' . date('His') . '.pdf"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

$flash = $_SESSION['dr_flash'] ?? null; unset($_SESSION['dr_flash']);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isSA && $settingsReady) {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $hour = (int)($_POST['hour'] ?? 20);
        orgSettingSet($pdo, $orgId, 'daily_report_enabled', !empty($_POST['enabled']) ? '1' : '0');
        orgSettingSet($pdo, $orgId, 'daily_report_hour', (string)(($hour >= 12 && $hour <= 23) ? $hour : 20));
        logActivity('दैनिक अहवाल सेटिंग्ज बदलल्या', 'organization', $orgId);
        $_SESSION['dr_flash'] = '✅ सेटिंग्ज जतन झाल्या.';
    } elseif ($a === 'test' || $a === 'send_all') {
        @set_time_limit(180);
        $r = dailyReportSend($pdo, $orgId, $date, $a === 'test' ? [$me['email'] ?? ''] : null);
        logActivity($a === 'test' ? 'दैनिक अहवाल — Test Email' : 'दैनिक अहवाल आत्ता पाठवला', 'organization', $orgId, $r['msg']);
        $_SESSION['dr_flash'] = ($r['ok'] ? '✅ ' : '❌ ') . ($a === 'test' && $r['ok'] ? 'Test अहवाल ' . $me['email'] . ' वर पाठवला' : $r['msg']);
    }
    header('Location: daily.php?date=' . urlencode($date));
    exit;
}

$D = dailyReportData($pdo, $orgId, $date);
$S = $D['sections'];
$enabled = dailyReportEnabled($pdo, $orgId);
$hour = dailyReportHour($pdo, $orgId);
$recips = weeklyReportRecipients($pdo, $orgId);
$lastAt = orgSetting($pdo, $orgId, 'daily_report_last_at');
// 🩺 आपोआप जाण्याची स्थिती — Cron चालतो का, शेवटचा प्रयत्न, अडचण कुठे
$cronAll = $cronJob = null;
try {
    $c = $pdo->query("SELECT * FROM cron_job_runs WHERE job_key IN ('run-all', 'daily-management-report')")->fetchAll();
    foreach ($c as $r) { if ($r['job_key'] === 'run-all') { $cronAll = $r; } else { $cronJob = $r; } }
} catch (PDOException $e) {}
$cronAge = $cronAll && $cronAll['last_started_at'] ? time() - strtotime($cronAll['last_started_at']) : null;
$cronCmd = '*/5 * * * * /usr/bin/php ' . realpath(__DIR__ . '/../cron/run-all.php');
$lastDate = orgSetting($pdo, $orgId, 'daily_report_last_date');
$lastVia = orgSetting($pdo, $orgId, 'daily_report_last_via');
$lastMsg = orgSetting($pdo, $orgId, 'daily_report_last_msg');
$dayLbl = array_map(fn($x) => date('d/m', strtotime($x)), $D['days']);
$pct = fn($a, $b) => $b > 0 ? (int)round($a * 100 / $b) : 0;
$segments = fn($msg) => implode('', array_map(fn($x) => preg_match('/^( — |, | \(|\))$/u', $x) ? h($x) : '<span>' . h($x) . '</span>', preg_split('/( — |, | \(|\))/u', $msg, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY)));
$tone = ['good' => 'ok', 'warn' => 'warn', 'bad' => 'bad'];
$w = $S['work']['w'] ?? null; $at = $S['attendance']['a'] ?? null;

$pageTitle = 'दैनिक अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .dr-hero{display:flex;gap:18px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:20px 22px;border-radius:16px;color:#fff;background:linear-gradient(135deg,#191C3A,#0E1024);position:relative;overflow:hidden;margin-bottom:16px;}
  .dr-hero::after{content:'';position:absolute;right:-60px;top:-80px;width:260px;height:260px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--rail) 45%,transparent),transparent 70%);}
  .dr-hero > *{position:relative;z-index:1;} .dr-hero h2{font-size:18px;margin:0 0 4px;font-weight:700;} .dr-hero p{margin:0;font-size:13px;color:#C9CCE0;}
  .dr-btn{display:inline-flex;align-items:center;gap:6px;border-radius:10px;font-size:13px;padding:9px 16px;font-weight:600;text-decoration:none;border:1px solid transparent;cursor:pointer;}
  .dr-btn.pri{background:var(--rail);color:#fff;} .dr-btn.ghost{background:rgba(255,255,255,.08);border-color:rgba(255,255,255,.18);color:#fff;} .dr-btn.line{background:transparent;border-color:var(--rail);color:var(--rail);}
  .dr-alert{display:flex;gap:10px;align-items:flex-start;padding:9px 12px;border-radius:10px;font-size:13px;margin-bottom:6px;line-height:1.5;}
  .dr-alert.bad{background:color-mix(in srgb,var(--rose,#e11d48) 10%,transparent);} .dr-alert.warn{background:color-mix(in srgb,var(--amber,#d97706) 12%,transparent);} .dr-alert.good{background:color-mix(in srgb,var(--green,#16a34a) 10%,transparent);}
  .dr-alert i{margin-top:3px;} .dr-alert.bad i{color:var(--rose,#e11d48);} .dr-alert.warn i{color:var(--amber,#d97706);} .dr-alert.good i{color:var(--green,#16a34a);}
  .dr-chart{display:flex;gap:16px;align-items:center;flex-wrap:wrap;padding:14px 18px;}
  .dr-chart svg{max-width:100%;height:auto;} .dr-chart text{font-family:inherit;}
  .dr-leg div{font-size:12.5px;margin:3px 0;display:flex;align-items:center;gap:6px;} .dr-leg i{width:10px;height:10px;border-radius:3px;display:inline-block;}
  .dr-mods{display:flex;flex-wrap:wrap;gap:6px;padding:14px 18px;} .dr-mods span{font-size:12px;padding:4px 10px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);}
  .dr-recip{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);font-size:12px;margin:0 6px 6px 0;}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">🌙 दैनिक अहवाल</div>
  <form method="GET" class="d-flex gap-2 align-items-center">
    <input type="date" name="date" class="form-control-custom" value="<?= h($date) ?>" max="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d', strtotime('-90 days')) ?>" onchange="this.form.submit()" style="max-width:180px;">
  </form>
</div>

<?php if ($flash): ?><div class="alert <?= str_starts_with($flash, '❌') ? 'alert-danger' : 'alert-success' ?>"><?= h($flash) ?></div><?php endif; ?>

<div class="dr-hero">
  <div>
    <h2><?= h(date('j M Y', strtotime($date))) ?><?= $date === date('Y-m-d') ? ' · आज' : '' ?></h2>
    <p>सगळ्या Modules चा दिवसभराचा हिशोब — Charts सह PDF<?= $enabled ? ', रोज ' . h(date('g A', mktime($hour, 0))) . ' नंतर Management ला Email वर' : ' (आपोआप पाठवणं सध्या बंद)' ?>.</p>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="dr-btn pri" href="?date=<?= h($date) ?>&pdf=1&v=<?= time() ?>" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i> PDF पाहा</a>
    <a class="dr-btn ghost" href="?date=<?= h($date) ?>&pdf=dl&v=<?= time() ?>"><i class="fa-solid fa-download"></i> Download</a>
  </div>
</div>

<?php
$tiles = [['label' => 'दिवसाचा गुण', 'value' => $D['score'] . '/100', 'sub' => ['good' => 'चांगला दिवस', 'warn' => 'लक्ष द्या', 'bad' => 'गंभीर'][$D['level']], 'tone' => $tone[$D['level']]]];
if ($w) { $tiles[] = ['label' => 'कामाच्या नोंदी', 'value' => (int)$w['total'], 'sub' => $pct($w['done'], $w['total']) . '% पूर्ण · सरासरी ' . round($S['work']['avg']), 'tone' => $w['total'] >= $S['work']['avg'] ? 'ok' : 'warn', 'href' => '../daily-work/report.php'];  }
if ($at && $at['total']) { $tiles[] = ['label' => 'हजेरी', 'value' => $pct($at['present'], $at['total']) . '%', 'sub' => (int)$at['present'] . '/' . (int)$at['total'] . ' · ' . (int)$at['late'] . ' उशिरा', 'tone' => $pct($at['present'], $at['total']) >= 85 ? 'ok' : 'warn']; }
if (!empty($S['tasks'])) { $tiles[] = ['label' => 'मुदतबाह्य Tasks', 'value' => (int)$S['tasks']['t']['overdue'], 'sub' => (int)$S['tasks']['t']['done'] . ' आज पूर्ण', 'tone' => $S['tasks']['t']['overdue'] ? 'bad' : 'ok', 'href' => '../tasks/index.php']; }
if (!empty($S['spend'])) { $tiles[] = ['label' => 'आजचा खर्च', 'value' => weeklyReportMoney($S['spend']['spend']), 'sub' => 'Budget व्यवहार', 'tone' => 'ok']; }
echo smartTiles(array_slice($tiles, 0, 5));
?>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🎯 ठळक मुद्दे</div><div class="sub">सगळ्या Modules मधून आपोआप</div></div>
      <div style="padding:12px 18px 14px;">
        <?php if (!$D['alerts'] && !$D['good']): ?><div style="font-size:13px;color:var(--text-dim);">विशेष काही नाही — सगळं ठीक.</div><?php endif; ?>
        <?php foreach ($D['alerts'] as [$lv, $msg]): ?><div class="dr-alert <?= $lv ?>"><i class="fa-solid <?= $lv === 'bad' ? 'fa-circle-exclamation' : 'fa-triangle-exclamation' ?>"></i><span><?= $segments($msg) ?></span></div><?php endforeach; ?>
        <?php foreach ($D['good'] as $msg): ?><div class="dr-alert good"><i class="fa-solid fa-circle-check"></i><span><?= $segments($msg) ?></span></div><?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <?php if ($w): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📋 कामकाज — गेले 7 दिवस</div></div>
      <div class="dr-chart">
        <?= drDonut([[(int)$w['done'], '#16A34A'], [(int)$w['prog'], '#F59E0B'], [(int)$w['pend'], '#DC2626']], $pct($w['done'], $w['total']) . '%', 104) ?>
        <div class="dr-leg"><div><i style="background:#16A34A"></i>पूर्ण <b><?= (int)$w['done'] ?></b></div><div><i style="background:#F59E0B"></i>सुरू <b><?= (int)$w['prog'] ?></b></div><div><i style="background:#DC2626"></i>प्रलंबित <b><?= (int)$w['pend'] ?></b></div></div>
        <div style="flex:1;min-width:220px;"><?= drBars($S['work']['trend'], $dayLbl, '#D9530F', 330, 120) ?></div>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($at && $at['total']): ?>
    <div class="panel">
      <div class="panel-head"><div class="title">🕘 हजेरी — गेले 7 दिवस</div></div>
      <div class="dr-chart">
        <?= drDonut([[(int)$at['present'], '#16A34A'], [(int)$at['leave_cnt'], '#3B82F6'], [(int)$at['absent'], '#DC2626']], $pct($at['present'], $at['total']) . '%', 104) ?>
        <div class="dr-leg"><div><i style="background:#16A34A"></i>उपस्थित <b><?= (int)$at['present'] ?></b></div><div><i style="background:#3B82F6"></i>रजा <b><?= (int)$at['leave_cnt'] ?></b></div><div><i style="background:#DC2626"></i>गैरहजर <b><?= (int)$at['absent'] ?></b></div></div>
        <div style="flex:1;min-width:220px;"><?= drBars($S['attendance']['trend'], $dayLbl, '#16A34A', 330, 120) ?></div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📑 PDF मध्ये असलेले भाग</div><div class="sub">ज्या Module मध्ये Data आहे तेवढेच</div></div>
  <div class="dr-mods">
    <?php $names = ['work' => '📋 कामकाज', 'attendance' => '🕘 हजेरी', 'tasks' => '✅ Tasks', 'requests' => '🛠️ कामाच्या विनंत्या', 'letters' => '✉️ पत्रव्यवहार', 'spend' => '💰 खर्च', 'vehicles' => '🚗 वाहने', 'hostel' => '🏠 वसतिगृह', 'guests' => '🎫 पाहुणे', 'facility' => '🛡️ सुरक्षा व सुविधा', 'renewals' => '📜 नूतनीकरण', 'ecampus' => '🎓 E-Campus', 'tomorrow' => '📅 उद्याचं नियोजन'];
    foreach ($names as $k => $n): if (!empty($S[$k])): ?><span><?= h($n) ?></span><?php endif; endforeach; ?>
    <?php if (!$S): ?><span>आज कोणतीही नोंद नाही</span><?php endif; ?>
  </div>
</div>

<div class="panel mb-3" id="autoStatus">
  <div class="panel-head"><div class="title">🩺 आपोआप पाठवण्याची स्थिती</div><div class="sub">अहवाल का गेला / का नाही — इथे लगेच कळतं</div></div>
  <div style="padding:12px 18px 16px;font-size:13px;line-height:1.7;">
    <div><?= $enabled ? '✅' : '⛔' ?> रोज आपोआप पाठवणं: <b><?= $enabled ? 'चालू — रोज ' . h(date('g A', mktime($hour, 0))) . ' नंतर' : 'बंद' ?></b></div>
    <div><?= $recips ? '✅' : '❌' ?> Email पत्ते: <b><?= count($recips) ?></b><?= $recips ? '' : ' — एकही नाही, त्यामुळे अहवाल जाणार नाही' ?></div>
    <div><?= $lastDate === date('Y-m-d') && str_starts_with((string)$lastMsg, '✓') ? '✅' : ($lastMsg && !str_starts_with((string)$lastMsg, '✓') ? '❌' : 'ℹ️') ?> शेवटचा अहवाल: <b><?= $lastDate ? h(date('d M Y', strtotime($lastDate))) : 'अजून एकदाही आपोआप गेलेला नाही' ?></b><?= $lastAt ? ' · पाठवला ' . h(date('d M, h:i A', strtotime($lastAt))) : '' ?><?= $lastMsg ? ' — ' . h($lastMsg) : '' ?><?= $lastVia === 'web' ? ' <span style="color:var(--text-dim);">(App मधून)</span>' : '' ?></div>
    <div><?php if ($cronAge === null): ?>⚠️ Server Cron: <b>एकदाही चाललेला नाही</b>
      <?php elseif ($cronAge > 3600): ?>⚠️ Server Cron: <b>बंद पडलेला दिसतो</b> — शेवटचा <?= h(date('d M, h:i A', strtotime($cronAll['last_started_at']))) ?>
      <?php else: ?>✅ Server Cron: <b>चालू</b> — शेवटचा <?= h(date('h:i A', strtotime($cronAll['last_started_at']))) ?><?= $cronJob && $cronJob['last_status'] === 'failed' ? ' · <span style="color:var(--rose);">दैनिक अहवाल Job अयशस्वी: ' . h(mb_strimwidth((string)$cronJob['last_output'], 0, 160, '…')) . '</span>' : '' ?><?php endif; ?></div>
    <?php if ($cronAge === null || $cronAge > 3600): ?>
      <div style="margin-top:8px;background:var(--canvas);border-radius:10px;padding:10px 12px;font-size:12.5px;">
        काळजी नको — <b>Cron नसला तरी</b> आता अहवाल आपोआप जातो: संध्याकाळी <?= h(date('g A', mktime($hour, 0))) ?> नंतर कोणीही App उघडलं की पार्श्वभूमीत जातो; रात्री कोणीच उघडलं नाही तर दुसऱ्या दिवशी सकाळी पहिल्यांदा App उघडताच कालचा अहवाल जातो.<br>
        ठरलेल्या वेळेलाच नक्की जावा यासाठी <?= $isSA ? 'Hostinger <b>hPanel → Advanced → Cron Jobs</b> मध्ये दर 5 मिनिटांची ही ओळ टाका:' : 'Super Admin ला Hostinger मध्ये Cron लावायला सांगा.' ?>
        <?php if ($isSA): ?><div class="d-flex gap-2 align-items-center mt-1 flex-wrap"><code id="cronCmd" style="background:#191C3A;color:#FDBA8C;padding:6px 10px;border-radius:8px;font-size:12px;word-break:break-all;"><!--i18n:off--><?= h($cronCmd) ?><!--i18n:on--></code><button type="button" class="dr-btn line" style="padding:5px 10px;" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('cronCmd').innerText); this.innerText='✓ Copied';">Copy</button></div><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📧 कोणाला जातो</div><div class="sub"><?= $enabled ? 'रोज ' . h(date('g A', mktime($hour, 0))) . ' नंतर' : 'आपोआप पाठवणं बंद' ?><?= $lastAt ? ' · शेवटचा: ' . h(date('d M, h:i A', strtotime($lastAt))) . ' — ' . h($lastMsg) : '' ?></div></div>
  <div style="padding:14px 18px 18px;">
    <?php if (!$recips): ?><div class="alert alert-warning" style="font-size:13px;">एकही Email पत्ता नाही — Management पदावरच्यांच्या Profile मध्ये Email भरा किंवा साप्ताहिक अहवाल पानावर जादा पत्ते टाका.</div><?php endif; ?>
    <div><?php foreach ($recips as $e): ?><span class="dr-recip"><i class="fa-regular fa-envelope"></i><?= h($e) ?></span><?php endforeach; ?></div>
    <div style="font-size:12px;color:var(--text-dim);margin:4px 0 0;">पत्ते साप्ताहिक अहवालासारखेच — बदलण्यासाठी <a href="weekly.php">साप्ताहिक अहवाल</a> पानावर जा.</div>

    <?php if ($isSA && $settingsReady): ?>
      <form method="POST" class="d-flex gap-3 flex-wrap align-items-center mt-3" style="font-size:13.5px;">
        <input type="hidden" name="action" value="save">
        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> style="width:17px;height:17px;accent-color:var(--rail);"> रोज आपोआप पाठवा</label>
        <label class="d-flex align-items-center gap-2">वेळ
          <select name="hour" class="form-select-custom" style="width:auto;">
            <?php for ($i = 17; $i <= 22; $i++): ?><option value="<?= $i ?>" <?= $hour === $i ? 'selected' : '' ?>><?= date('g A', mktime($i, 0)) ?></option><?php endfor; ?>
          </select>
        </label>
        <button class="dr-btn pri" style="border:0;"><i class="fa-solid fa-floppy-disk"></i> जतन करा</button>
      </form>
      <div class="d-flex gap-2 flex-wrap mt-3">
        <form method="POST" onsubmit="this.querySelector('button').disabled=true;"><input type="hidden" name="action" value="test"><button class="dr-btn line"><i class="fa-solid fa-flask"></i> मला Test पाठवा</button></form>
        <?php if ($recips): ?><form method="POST" onsubmit="if(!confirm('हा दिवसाचा अहवाल आत्ताच सगळ्या <?= count($recips) ?> पत्त्यांवर पाठवायचा?'))return false;this.querySelector('button').disabled=true;"><input type="hidden" name="action" value="send_all"><button class="dr-btn line"><i class="fa-solid fa-paper-plane"></i> आत्ता सर्वांना पाठवा</button></form><?php endif; ?>
      </div>
    <?php elseif ($isSA): ?>
      <div class="alert alert-warning mt-3" style="font-size:13px;">सेटिंग्जसाठी आधी <a href="../system-update/db-update.php">Database Update</a> करा.</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
