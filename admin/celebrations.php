<?php
// 🎂 वाढदिवस व वर्धापनदिन — आज / पुढचे 30 दिवस, कोणाला शुभेच्छा जाऊ शकत नाहीत (अपूर्ण माहिती),
// Email / सूचना सेटिंग्ज, Cron शेवटचा कधी चालला, आणि चाचणी Email (स्वतःला) + Preview
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/celebrations.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
$orgId = activeOrgId();
$me = currentUser();
$orgName = activeOrgName();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'settings') {
        foreach (['celebration_email', 'celebration_push', 'celebration_colleagues'] as $k) { orgSettingSet($pdo, $orgId, $k, !empty($_POST[$k]) ? '1' : '0'); }
        logActivity('वाढदिवस/वर्धापनदिन सेटिंग्ज बदलल्या', 'settings', $orgId, '');
        $_SESSION['flash'] = '✅ सेटिंग्ज जतन झाल्या.';
    } elseif ($act === 'test') {
        $kind = ($_POST['kind'] ?? '') === 'anniversary' ? 'anniversary' : 'birthday';
        $to = trim((string)($me['email'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = '⚠️ तुमच्या खात्याला वैध Email नाही — आधी Profile मध्ये Email भरा.';
        } else {
            $ok = $kind === 'birthday' ? sendBirthdayEmail($to, $me['name'], $orgName) : sendWorkAnniversaryEmail($to, $me['name'], 5, $orgName);
            if ($ok) { $_SESSION['flash'] = '✅ चाचणी Email ' . $to . ' वर पाठवला — Inbox (व Spam) पाहा.'; }
            else { $_SESSION['flash_error'] = '⚠️ Server वरून Email जाऊ शकला नाही (mail() अयशस्वी) — Hosting ची Email सेटिंग तपासा.'; }
            logActivity('वाढदिवस/वर्धापनदिन चाचणी Email', 'settings', $orgId, $to);
        }
    } elseif ($act === 'push') {
        notifyUser($me['id'], $orgId, '🎂 वाढदिवसाच्या हार्दिक शुभेच्छा, ' . $me['name'] . '! (चाचणी)', $orgName . ' परिवाराकडून मनःपूर्वक शुभेच्छा — तुमचं हे वर्ष आनंदी व यशस्वी जावो! 🎉', '../welcome.php');
        $_SESSION['flash'] = '✅ चाचणी सूचना पाठवली — घंटी 🔔 / फोन पाहा.';
    }
    header('Location: celebrations.php');
    exit;
}

$today = celebrationsOn($pdo, $orgId, date('Y-m-d'));
$upcoming = array_values(array_filter(celebrationsUpcoming($pdo, $orgId, 30), fn($x) => $x['date'] !== date('Y-m-d')));
$missing = celebrationsMissing($pdo, $orgId);
$cron = null;
try { $st = $pdo->prepare("SELECT * FROM cron_job_runs WHERE job_key = ?"); $st->execute(['send-birthday-anniversary-emails']); $cron = $st->fetch(); } catch (PDOException $e) {}
$cronAge = $cron && $cron['last_finished_at'] ? (time() - strtotime($cron['last_finished_at'])) / 3600 : null;
$sEmail = celebrationSetting($pdo, $orgId, 'celebration_email');
$sPush = celebrationSetting($pdo, $orgId, 'celebration_push');
$sCol = celebrationSetting($pdo, $orgId, 'celebration_colleagues');
$preview = ($_GET['preview'] ?? '') === 'anniversary' ? 'anniversary' : 'birthday';

$pageTitle = 'वाढदिवस व वर्धापनदिन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$dayLbl = function ($date) { $d = (int)round((strtotime($date) - strtotime(date('Y-m-d'))) / 86400); return $d === 1 ? 'उद्या' : date('d M', strtotime($date)) . ' · ' . $d . ' दिवसांनी'; };
// आजच्या यादीत फोटो — Welcome पानावर जसा दिसेल तसा (नसेल / तुटला असेल तर स्पष्ट)
function celPhoto($u, $ic) {
    if (eowmsPhotoExists($u['photo_path'] ?? null)) return '<img src="../' . h(ltrim(preg_replace('#^(\.\./)+#', '', $u['photo_path']), '/')) . '" class="cl-ph" alt="" title="Welcome पानावर हा फोटो दिसेल">';
    $why = empty($u['photo_path']) ? 'फोटो Upload केलेला नाही' : 'फोटो File Server वर सापडत नाही — पुन्हा Upload करा';
    return '<a href="../staff/edit.php?id=' . (int)$u['id'] . '" class="cl-ph none" title="' . h($why) . '">' . $ic . '<i>📷✕</i></a>';
}
$noDob = count(array_filter($missing, fn($m) => !$m['date_of_birth']));
$noJoin = count(array_filter($missing, fn($m) => !$m['joining_date']));
$noMail = count(array_filter($missing, fn($m) => !$m['email']));
$noPhoto = count(array_filter($missing, fn($m) => $m['photo_state'] === 'none'));
$brokenPhoto = count(array_filter($missing, fn($m) => $m['photo_state'] === 'broken'));
?>
<div class="page-eyebrow"><?= h($orgName) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
  <div class="page-title mb-0">🎂 वाढदिवस व वर्धापनदिन</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Control Center</a>
</div>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success mt-2" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['flash_error'])): ?><div class="alert alert-danger mt-2" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash_error']) ?></div><?php unset($_SESSION['flash_error']); endif; ?>

<?php
smartTiles([
    ['label' => '🎂 आज वाढदिवस', 'value' => count($today['birthdays'])],
    ['label' => '🎊 आज वर्धापनदिन', 'value' => count($today['anniversaries'])],
    ['label' => '📅 पुढचे 30 दिवस', 'value' => count($upcoming)],
    ['label' => '⚠️ माहिती अपूर्ण', 'value' => count($missing), 'tone' => $missing ? 'warn' : 'ok', 'sub' => 'जन्मतारीख ' . $noDob . ' · रुजू ' . $noJoin . ' · Email ' . $noMail . ' · फोटो ' . ($noPhoto + $brokenPhoto)],
    ['label' => '⏱️ शेवटचं आपोआप पाठवणं', 'value' => $cron && $cron['last_finished_at'] ? date('d M', strtotime($cron['last_finished_at'])) : 'कधीच नाही',
        'tone' => $cronAge === null || $cronAge > 26 ? 'bad' : 'ok', 'sub' => $cronAge === null ? 'Hostinger मध्ये Cron लावा' : date('h:i A', strtotime($cron['last_finished_at'])) . ($cron['last_output'] ? ' · ' . mb_strimwidth(preg_replace('/^Done \S+ /', '', $cron['last_output']), 0, 60, '…') : '')],
]);
$al = [];
if ($cronAge === null || $cronAge > 26) $al[] = ['bad', '⏱️ रोजचं आपोआप पाठवणं (Cron) चाललेलं दिसत नाही — Email व सूचना जाणार नाहीत. Hostinger → Cron Jobs मध्ये run-all.php ची ओळ लावा.', '../system-update/index.php'];
if ($noMail) $al[] = ['warn', '📧 ' . $noMail . ' कर्मचाऱ्यांचा Email नाही — त्यांना Email जाणार नाही (App सूचना मात्र जाईल)', '#missing'];
if ($noDob) $al[] = ['warn', '🎂 ' . $noDob . ' कर्मचाऱ्यांची जन्मतारीख भरलेली नाही — त्यांचा वाढदिवस कळणार नाही', '#missing'];
if ($brokenPhoto) $al[] = ['bad', '📷 ' . $brokenPhoto . ' कर्मचाऱ्यांचा फोटो नोंदीत आहे पण File Server वर सापडत नाही — फोटो पुन्हा Upload करा (Welcome पानावर फोटो दिसत नाही)', '#missing'];
if ($noPhoto) $al[] = ['info', '📷 ' . $noPhoto . ' कर्मचाऱ्यांचा फोटो Upload केलेला नाही — Welcome पानावर फोटोऐवजी आद्याक्षर दिसतं', '#missing'];
if ($noJoin) $al[] = ['info', '🎊 ' . $noJoin . ' कर्मचाऱ्यांचा रुजू दिनांक भरलेला नाही — वर्धापनदिन कळणार नाही', '#missing'];
smartAlerts($al);
?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🎉 आज</div><div class="sub"><?= date('d M Y') ?></div></div>
      <div style="padding:6px 18px 12px;">
        <?php if (!$today['birthdays'] && !$today['anniversaries']): ?><div class="sm-empty">आज कोणाचाही वाढदिवस / वर्धापनदिन नाही</div><?php endif; ?>
        <?php foreach ($today['birthdays'] as $u): ?><div class="cl-row"><?= celPhoto($u, '🎂') ?><div><b><?= h($u['name']) ?></b><small><?= h(($u['designation'] ?: '') . ($u['dept'] ? ' · ' . $u['dept'] : '')) ?></small></div><span class="cl-tag"><?= $u['email'] ? '📧' : '<span title="Email नाही">—</span>' ?> वाढदिवस</span></div><?php endforeach; ?>
        <?php foreach ($today['anniversaries'] as $u): ?><div class="cl-row"><?= celPhoto($u, '🎊') ?><div><b><?= h($u['name']) ?></b><small><?= h(($u['designation'] ?: '') . ($u['dept'] ? ' · ' . $u['dept'] : '')) ?></small></div><span class="cl-tag"><?= (int)$u['years'] ?> वर्षं सेवा</span></div><?php endforeach; ?>
      </div>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📅 पुढचे 30 दिवस</div></div>
      <div style="padding:6px 18px 12px;max-height:420px;overflow:auto;">
        <?php if (!$upcoming): ?><div class="sm-empty">पुढच्या 30 दिवसांत कोणाचाही नाही</div><?php endif; ?>
        <?php foreach ($upcoming as $x): $u = $x['user']; ?>
          <div class="cl-row"><span class="cl-ic"><?= $x['kind'] === 'birthday' ? '🎂' : '🎊' ?></span><div><b><?= h($u['name']) ?></b><small><?= h($u['dept'] ?: '—') ?></small></div>
            <span class="cl-tag"><?= $x['kind'] === 'birthday' ? 'वाढदिवस' : $x['years'] . ' वर्षं सेवा' ?> · <?= h($dayLbl($x['date'])) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="panel mb-3" id="missing">
      <div class="panel-head"><div class="title">⚠️ माहिती अपूर्ण (<?= count($missing) ?>)</div><div class="sub">यांना शुभेच्छा पूर्ण जाऊ शकत नाहीत / Welcome पानावर फोटो दिसत नाही — नावावर Click करून माहिती भरा</div></div>
      <div style="overflow-x:auto;">
      <table class="rtable">
        <thead><tr><th>कर्मचारी</th><th>विभाग</th><th>जन्मतारीख</th><th>रुजू दिनांक</th><th>Email</th><th>फोटो</th></tr></thead>
        <tbody>
          <?php if (!$missing): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:18px;">✓ सगळ्यांची माहिती पूर्ण</td></tr><?php endif; ?>
          <?php foreach ($missing as $m): $x = '<span style="color:var(--rose);font-weight:700;">✕ नाही</span>'; ?>
            <tr><td><a href="../staff/edit.php?id=<?= (int)$m['id'] ?>" style="color:inherit;font-weight:600;"><?= h($m['name']) ?></a></td><td style="font-size:12.5px;"><?= h($m['dept'] ?: '—') ?></td>
              <td><?= $m['date_of_birth'] ? '✓' : $x ?></td><td><?= $m['joining_date'] ? '✓' : $x ?></td><td><?= $m['email'] ? '✓' : $x ?></td>
              <td><?= $m['photo_state'] === 'ok' ? '✓' : ($m['photo_state'] === 'broken' ? '<span style="color:var(--rose);font-weight:700;" title="' . h($m['photo_path']) . '">⚠ File सापडत नाही</span>' : $x) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">⚙️ काय पाठवायचं</div><div class="sub">रोज सकाळी 7 नंतर आपोआप</div></div>
      <form method="POST" style="padding:12px 18px 16px;">
        <?= csrfField() ?><input type="hidden" name="act" value="settings">
        <label class="cl-sw"><input type="checkbox" name="celebration_email" value="1" <?= $sEmail ? 'checked' : '' ?>><span><b>📧 कर्मचाऱ्याला Email</b><small>वाढदिवस / वर्धापनदिनाच्या शुभेच्छांचा Email</small></span></label>
        <label class="cl-sw"><input type="checkbox" name="celebration_push" value="1" <?= $sPush ? 'checked' : '' ?>><span><b>📱 कर्मचाऱ्याला App / फोनवर सूचना</b><small>शुभेच्छा थेट फोनवर (Push)</small></span></label>
        <label class="cl-sw"><input type="checkbox" name="celebration_colleagues" value="1" <?= $sCol ? 'checked' : '' ?>><span><b>👥 त्याच विभागातल्या सहकाऱ्यांना सूचना</b><small>"आज X यांचा वाढदिवस — शुभेच्छा द्या!" (Management ला नेहमी)</small></span></label>
        <button class="btn mt-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;">जतन करा</button>
      </form>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🧪 चाचणी</div><div class="sub">तुमच्या स्वतःच्या Email / फोनवर — <?= h($me['email'] ?: 'Email नाही') ?></div></div>
      <div style="padding:12px 18px 16px;display:flex;flex-wrap:wrap;gap:8px;">
        <form method="POST"><?= csrfField() ?><input type="hidden" name="act" value="test"><input type="hidden" name="kind" value="birthday"><button class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">🎂 वाढदिवस Email पाठवा</button></form>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="act" value="test"><input type="hidden" name="kind" value="anniversary"><button class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">🎊 वर्धापनदिन Email पाठवा</button></form>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="act" value="push"><button class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">📱 चाचणी सूचना</button></form>
      </div>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👁️ Email कसा दिसतो</div>
        <div class="sub"><a href="?preview=birthday" style="<?= $preview === 'birthday' ? 'font-weight:700;color:var(--rail);' : 'color:inherit;' ?>">वाढदिवस</a> · <a href="?preview=anniversary" style="<?= $preview === 'anniversary' ? 'font-weight:700;color:var(--rail);' : 'color:inherit;' ?>">वर्धापनदिन</a></div></div>
      <div style="max-height:460px;overflow:auto;border-radius:0 0 12px 12px;"><?= $preview === 'birthday' ? birthdayEmailHtml($me['name'], $orgName) : workAnniversaryEmailHtml($me['name'], 5, $orgName) ?></div>
    </div>
  </div>
</div>
<style>
  .cl-row{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px dashed var(--line);} .cl-row:last-child{border-bottom:0;}
  .cl-ic{font-size:20px;} .cl-ph{width:40px;height:40px;border-radius:50%;object-fit:cover;flex-shrink:0;border:2px solid #C97F1D;}
  a.cl-ph.none{display:flex;align-items:center;justify-content:center;background:var(--canvas);font-size:18px;position:relative;text-decoration:none;border-style:dashed;} a.cl-ph.none i{position:absolute;bottom:-6px;right:-10px;font-size:9px;font-style:normal;background:#FEE2E2;color:#B91C1C;border-radius:6px;padding:0 3px;} .cl-row > div{flex:1;min-width:0;} .cl-row b{font-size:13.5px;} .cl-row small{display:block;font-size:11.5px;color:var(--text-dim);}
  .cl-tag{font-size:11.5px;font-weight:600;color:var(--text-dim);white-space:nowrap;}
  .cl-sw{display:flex;gap:10px;align-items:flex-start;padding:8px 0;cursor:pointer;} .cl-sw input{width:17px;height:17px;margin-top:3px;accent-color:var(--rail);}
  .cl-sw small{display:block;font-size:11.5px;color:var(--text-dim);font-weight:400;}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
