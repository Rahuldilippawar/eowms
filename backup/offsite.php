<?php
// ☁️ Backup दुसऱ्या ठिकाणी — Email वर Encrypted Backup (Server बिघडला तरी Data सुरक्षित)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
requireMigratedTable($pdo, 'org_settings', '../');
require_once __DIR__ . '/../includes/offsite-backup.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

$orgId = activeOrgId();
$error = null;
$flash = $_SESSION['offsite_flash'] ?? null; unset($_SESSION['offsite_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $emails = parseEmailList($_POST['emails'] ?? '');
        $pw = (string)($_POST['password'] ?? '');
        $pw2 = (string)($_POST['password2'] ?? '');
        $hasPw = offsiteSettings($pdo, $orgId)['has_password'];
        $enabled = !empty($_POST['enabled']);
        if ($pw !== '' && mb_strlen($pw) < 10) { $error = 'Backup Password किमान 10 अक्षरांचा हवा.'; }
        elseif ($pw !== $pw2) { $error = 'दोन्ही Password जुळत नाहीत.'; }
        elseif ($enabled && !$emails) { $error = 'किमान एक वैध Email पत्ता टाका.'; }
        elseif ($enabled && $pw === '' && !$hasPw) { $error = 'Backup Password सेट करा — त्याशिवाय File Encrypt करता येत नाही.'; }
        else {
            orgSettingSet($pdo, $orgId, 'offsite_enabled', $enabled ? '1' : '0');
            orgSettingSet($pdo, $orgId, 'offsite_emails', implode(', ', $emails));
            orgSettingSet($pdo, $orgId, 'offsite_frequency', ($_POST['frequency'] ?? '') === 'daily' ? 'daily' : 'weekly');
            if ($pw !== '') { orgSettingSet($pdo, $orgId, 'offsite_password', secretEncrypt($pw)); }
            logActivity('Email Backup सेटिंग्ज बदलल्या', 'organization', $orgId, ($enabled ? 'चालू' : 'बंद') . ' — ' . implode(', ', $emails) . ($pw !== '' ? ' — नवीन Password' : ''));
            $_SESSION['offsite_flash'] = '✅ सेटिंग्ज जतन झाल्या.' . ($pw !== '' ? ' नवीन Password कुठेतरी सुरक्षित लिहून ठेवा — तो विसरल्यास Backup उघडणार नाही.' : '');
            header('Location: offsite.php');
            exit;
        }
    } elseif ($a === 'send_now') {
        @set_time_limit(300);
        $r = offsiteRun($pdo, $orgId);
        logActivity('Email Backup आत्ता पाठवला', 'organization', $orgId, $r['msg']);
        $_SESSION['offsite_flash'] = ($r['ok'] ? '✅ ' : '❌ ') . $r['msg'] . ($r['ok'] ? ' — Inbox तपासा (Spam मध्येही).' : '');
        header('Location: offsite.php');
        exit;
    }
}

$s = offsiteSettings($pdo, $orgId);
if ($error) { // चूक झाली तर टाकलेली माहिती परत Form मध्ये
    $s['enabled'] = !empty($_POST['enabled']);
    $s['emails'] = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string)($_POST['emails'] ?? ''))));
    $s['frequency'] = ($_POST['frequency'] ?? '') === 'daily' ? 'daily' : 'weekly';
}
$cronRow = null;
try { $c = $pdo->prepare("SELECT * FROM cron_job_runs WHERE job_key = 'run-all'"); $c->execute(); $cronRow = $c->fetch(); } catch (PDOException $e) {}
$cronAlive = $cronRow && strtotime($cronRow['last_started_at']) > time() - 3 * 3600;
$nextTxt = '—';
if ($s['enabled'] && $s['emails'] && $s['has_password']) {
    if (offsiteDue($s)) { $nextTxt = 'पुढच्या Cron वेळी (रोज सकाळी 3 नंतर)'; }
    else {
        $base = strtotime($s['last_at']);
        $nextTxt = date('d M Y', $s['frequency'] === 'daily' ? strtotime('+1 day', $base) : (int)($base + 7 * 86400)) . ', सकाळी 3 नंतर';
    }
}
$okAge = $s['last_ok_at'] ? (int)floor((time() - strtotime($s['last_ok_at'])) / 86400) : null;

$pageTitle = 'Email Backup';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .ob-flow{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 18px;border-radius:14px;background:linear-gradient(135deg,#191C3A,#0E1024);color:#fff;margin-bottom:16px;}
  .ob-step{display:flex;align-items:center;gap:10px;flex:1;min-width:150px;} .ob-step i{width:40px;height:40px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.08);color:#FDBA8C;font-size:16px;flex-shrink:0;}
  .ob-step b{display:block;font-size:13px;} .ob-step span{font-size:11.5px;color:#C9CCE0;} .ob-arrow{color:#6B7194;}
  .ob-help li{font-size:13px;line-height:1.7;margin-bottom:4px;}
  @media (max-width:767px){.ob-arrow{display:none;}}
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">☁️ Email Backup</div>
  <div class="d-flex gap-2">
    <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-arrow-left me-1"></i>Backup</a>
  </div>
</div>
<p style="font-size:13px;color:var(--text-dim);margin:4px 0 14px;">रोजचे Backups याच Server वर राहतात — Hostinger ला काही झालं तर तेही जातील. म्हणून Backup ची एक <b>Password ने Encrypt केलेली प्रत</b> आपोआप Email वर पाठवली जाते.</p>

<?php if ($flash): ?><div class="alert <?= str_starts_with($flash, '❌') ? 'alert-danger' : 'alert-success' ?>"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="ob-flow">
  <div class="ob-step"><i class="fa-solid fa-database"></i><div><b>संपूर्ण Data</b><span>सगळ्या Modules चा Backup</span></div></div>
  <i class="fa-solid fa-chevron-right ob-arrow"></i>
  <div class="ob-step"><i class="fa-solid fa-lock"></i><div><b>AES-256 Encrypt</b><span>तुमच्या Password ने</span></div></div>
  <i class="fa-solid fa-chevron-right ob-arrow"></i>
  <div class="ob-step"><i class="fa-solid fa-envelope-open-text"></i><div><b>Email Inbox</b><span>Server बाहेर सुरक्षित प्रत</span></div></div>
</div>

<?= smartTiles([
    ['label' => 'स्थिती', 'value' => $s['enabled'] ? 'चालू' : 'बंद', 'sub' => $s['enabled'] ? ($s['frequency'] === 'daily' ? 'रोज' : 'दर आठवड्याला') : 'खाली चालू करा', 'tone' => $s['enabled'] ? 'ok' : 'warn'],
    ['label' => 'शेवटचा यशस्वी', 'value' => $s['last_ok_at'] ? date('d M', strtotime($s['last_ok_at'])) : '—', 'sub' => $okAge === null ? 'अजून एकदाही नाही' : ($okAge === 0 ? 'आज' : $okAge . ' दिवसांपूर्वी'), 'tone' => $okAge === null ? 'warn' : ($okAge > ($s['frequency'] === 'daily' ? 2 : 8) ? 'bad' : 'ok')],
    ['label' => 'शेवटचा प्रयत्न', 'value' => $s['last_status'] ? ($s['last_status'] === 'success' ? '✓ यशस्वी' : '✗ अयशस्वी') : '—', 'sub' => $s['last_msg'] ? mb_strimwidth($s['last_msg'], 0, 60, '…') : '', 'tone' => $s['last_status'] === 'failed' ? 'bad' : 'ok'],
    ['label' => 'पुढचा', 'value' => $s['enabled'] ? '📅' : '—', 'sub' => $nextTxt, 'tone' => 'ok'],
]) ?>

<?php if ($s['enabled'] && !$cronAlive): ?>
  <div class="alert alert-warning" style="font-size:13px;">⚠️ Cron Job चालू दिसत नाही — आपोआप Backup जाण्यासाठी Hostinger मध्ये <code>cron/run-all.php</code> ची Cron ओळ हवी. <a href="scheduled.php">Automated Backup</a> पानावर कसं करायचं ते दिलं आहे. तोपर्यंत "आत्ता पाठवा" वापरा.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">⚙️ सेटिंग्ज</div></div>
      <form method="POST" style="padding:16px 20px 20px;" autocomplete="off">
        <input type="hidden" name="action" value="save">
        <label class="d-flex align-items-center gap-2 mb-3" style="font-size:14px;font-weight:600;cursor:pointer;">
          <input type="checkbox" name="enabled" value="1" <?= $s['enabled'] ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--rail);"> आपोआप Email Backup चालू
        </label>
        <label class="form-label-custom">कोणाला पाठवायचा (Email)</label>
        <textarea name="emails" class="form-control-custom mb-1" rows="2" placeholder="trust.backup@gmail.com, secretary@example.com"><?= h(implode(', ', $s['emails'])) ?></textarea>
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">शक्यतो या कामासाठी वेगळं Gmail खातं बनवा — Gmail मध्ये 15 GB जागा मोफत. अनेक पत्ते स्वल्पविरामाने.</div>
        <label class="form-label-custom">किती वेळा</label>
        <select name="frequency" class="form-select-custom mb-3" style="max-width:260px;">
          <option value="weekly" <?= $s['frequency'] === 'weekly' ? 'selected' : '' ?>>दर आठवड्याला (शिफारस)</option>
          <option value="daily" <?= $s['frequency'] === 'daily' ? 'selected' : '' ?>>रोज</option>
        </select>
        <label class="form-label-custom">Backup Password <?= $s['has_password'] ? '<span style="font-weight:400;color:var(--green,#16a34a);">✓ सेट आहे — बदलायचा असेल तरच टाका</span>' : '' ?></label>
        <div class="row g-2 mb-1">
          <div class="col-sm-6"><input type="password" name="password" class="form-control-custom" minlength="10" placeholder="नवीन Password (किमान 10)" autocomplete="new-password"></div>
          <div class="col-sm-6"><input type="password" name="password2" class="form-control-custom" placeholder="पुन्हा तोच" autocomplete="new-password"></div>
        </div>
        <div style="font-size:12px;color:var(--rose,#e11d48);margin-bottom:14px;">⚠️ हा Password Email मध्ये जात नाही. तो विसरल्यास Backup कोणालाही (आम्हालाही) उघडता येणार नाही — कागदावर लिहून सुरक्षित ठेवा.</div>
        <div class="d-flex gap-2 flex-wrap">
          <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-floppy-disk me-1"></i>जतन करा</button>
        </div>
      </form>
      <?php if ($s['emails'] && $s['has_password']): ?>
      <form method="POST" style="padding:0 20px 20px;" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').innerHTML='⏳ Backup तयार होतोय…';">
        <input type="hidden" name="action" value="send_now">
        <button class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13.5px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-paper-plane me-1"></i>आत्ता Backup पाठवून पाहा</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🛟 गरज पडली तर Restore कसं करायचं</div></div>
      <ol class="ob-help" style="padding:14px 20px 8px 38px;margin:0;">
        <li>Email मधली <b>.eadmin</b> File Download करा.</li>
        <li>E-Admin मध्ये Super Admin ने Login करा → <b>Backup → Restore</b>.</li>
        <li>ती File निवडा, <b>Backup Password</b> टाका → Restore.</li>
        <li>नवीन Server असेल तर: आधी App Install करा, मग वरील पायऱ्या.</li>
      </ol>
      <div style="padding:0 20px 18px;font-size:12px;color:var(--text-dim);line-height:1.7;">
        🔒 File <b>AES-256</b> ने Encrypt असते — Email खातं कोणी उघडलं तरी कर्मचाऱ्यांची माहिती (आधार, बँक खाते) Password शिवाय वाचता येत नाही.<br>
        📎 Email ची मर्यादा ~20 MB. Backup त्याहून मोठा झाला तर इथे सूचना दिसेल.<br>
        🖼️ फोटो / Documents या Email Backup मध्ये नसतात — त्यांसाठी Backup → "Full Backup (Files सकट)" अधूनमधून Download करा.
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
