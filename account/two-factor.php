<?php
// 🔐 2-Step Login — चालू/बंद, Authenticator App किंवा Email, Recovery Codes, विश्वासू Devices.
// Super Admin: कोणासाठी अनिवार्य ते ठरवणं + इतरांचं 2-Step Reset (फोन हरवला तर).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/two-factor.php';
requireLogin('../');
requireMigratedTable($pdo, 'user_trusted_devices', '../');
$me = currentUser();
$orgId = activeOrgId();
$orgName = activeOrgName();
$isSA = ($me['role'] ?? '') === 'super_admin';

$load = function () use ($pdo, $me) {
    $st = $pdo->prepare("SELECT id, name, email, role, organization_id, password, tfa_method, tfa_secret, tfa_recovery, tfa_last_step, tfa_enabled_at FROM users WHERE id = ?");
    $st->execute([$me['id']]);
    return $st->fetch();
};
$u = $load();
$required = tfaRequiredFor($pdo, $u);
$flash = $_SESSION['tfa_flash'] ?? null; unset($_SESSION['tfa_flash']);
$newCodes = $_SESSION['tfa_new_codes'] ?? null; unset($_SESSION['tfa_new_codes']);
$error = null;
$back = function ($msg = null) { if ($msg) { $_SESSION['tfa_flash'] = $msg; } header('Location: two-factor.php'); exit; };
$pwOk = fn() => password_verify($_POST['password'] ?? '', $u['password']);

/** 2-Step चालू — Recovery Codes बनवून एकदाच दाखवतो */
$enable = function ($method, $secretB32 = null, $step = null) use ($pdo, $u, $back) {
    [$codes, $json] = tfaNewRecoveryCodes();
    $pdo->prepare("UPDATE users SET tfa_method = ?, tfa_secret = ?, tfa_recovery = ?, tfa_last_step = ?, tfa_enabled_at = NOW() WHERE id = ?")
        ->execute([$method, $secretB32 ? secretEncrypt($secretB32) : null, $json, $step, $u['id']]);
    unset($_SESSION['tfa_setup_secret'], $_SESSION['tfa_setup_required'], $_SESSION['tfa_email']);
    logActivity('2-Step Login चालू केलं', 'user', $u['id'], $method === 'totp' ? 'Authenticator App' : 'Email Code');
    $_SESSION['tfa_new_codes'] = $codes;
    $back('✅ 2-Step Login चालू झालं. खालचे Recovery Codes आत्ताच सुरक्षित ठिकाणी जतन करा.');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'confirm_totp') {
        $secret = $_SESSION['tfa_setup_secret'] ?? null;
        $step = $secret ? tfaTotpVerify($secret, $_POST['code'] ?? '') : false;
        if ($step === false) { $error = 'Code जुळला नाही — App मधला सध्याचा Code टाका (फोनची वेळ Automatic असल्याची खात्री करा).'; }
        else { $enable('totp', $secret, $step); }
    } elseif ($a === 'send_email_setup') {
        if (empty($u['email']) || !filter_var($u['email'], FILTER_VALIDATE_EMAIL)) { $error = 'तुमच्या Profile मध्ये वैध Email नाही — आधी Email नोंदवा किंवा Authenticator App वापरा.'; }
        elseif (tfaSendEmailCode($u, $orgName)) { $_SESSION['tfa_flash'] = '📧 Code ' . $u['email'] . ' वर पाठवला — तो खाली टाका.'; header('Location: two-factor.php?setup=email&sent=1'); exit; }
        else { $error = 'Email पाठवता आला नाही. Authenticator App पद्धत वापरा.'; }
    } elseif ($a === 'confirm_email') {
        if (tfaCheckEmailCode($u['id'], $_POST['code'] ?? '')) { $enable('email'); }
        $error = 'Code चुकीचा किंवा जुना झाला — पुन्हा पाठवा.';
        $_GET['setup'] = 'email'; $_GET['sent'] = 1;
    } elseif (in_array($a, ['disable', 'regen', 'change'], true)) {
        if (!tfaEnabled($u)) { $back(); }
        if (!$pwOk()) { $error = 'पासवर्ड चुकीचा आहे.'; }
        elseif ($a === 'regen') {
            [$codes, $json] = tfaNewRecoveryCodes();
            $pdo->prepare("UPDATE users SET tfa_recovery = ? WHERE id = ?")->execute([$json, $u['id']]);
            logActivity('2-Step Recovery Codes नवीन केले', 'user', $u['id']);
            $_SESSION['tfa_new_codes'] = $codes;
            $back('🔑 नवीन Recovery Codes तयार झाले — जुने आता चालणार नाहीत.');
        } elseif ($a === 'disable' && $required) {
            $error = 'तुमच्या पदासाठी 2-Step Login अनिवार्य आहे — बंद करता येणार नाही. पद्धत बदलायची असेल तर "पद्धत बदला" वापरा.';
        } else {
            tfaDisable($pdo, $u['id']);
            logActivity($a === 'change' ? '2-Step Login ची पद्धत बदलायला घेतली' : '2-Step Login बंद केलं', 'user', $u['id']);
            if ($a === 'change' || $required) { $_SESSION['tfa_setup_required'] = $required ?: null; }
            $a === 'change' ? $back('नवीन पद्धत निवडा आणि सेट करा.') : $back('2-Step Login बंद केलं.');
        }
    } elseif ($a === 'forget_device') {
        $pdo->prepare("DELETE FROM user_trusted_devices WHERE id = ? AND user_id = ?")->execute([(int)($_POST['id'] ?? 0), $u['id']]);
        $back('तो Device काढला — तिथे पुढच्या Login ला Code विचारला जाईल.');
    } elseif ($a === 'forget_all') {
        $pdo->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?")->execute([$u['id']]);
        $back('सगळे लक्षात ठेवलेले Devices काढले.');
    } elseif ($isSA && $a === 'policy') {
        $cats = array_values(array_intersect((array)($_POST['cats'] ?? []), ['management', 'hod', 'staff']));
        orgSettingSet($pdo, $orgId, 'tfa_required_categories', implode(',', $cats));
        logActivity('2-Step Login धोरण बदललं', 'organization', $orgId, $cats ? implode(', ', $cats) : 'फक्त Super Admin');
        $back('✅ धोरण जतन झालं — संबंधित लोकांना पुढच्या Login ला 2-Step चालू करावं लागेल.');
    } elseif ($isSA && $a === 'reset_user') {
        $tid = (int)($_POST['user_id'] ?? 0);
        $chk = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND organization_id = ?");
        $chk->execute([$tid, $orgId]);
        $t = $chk->fetch();
        if ($t && $tid !== (int)$u['id'] && $pwOk()) {
            tfaDisable($pdo, $tid);
            logActivity('दुसऱ्याचं 2-Step Login Reset केलं', 'user', $tid, $t['name']);
            $back('🔄 ' . $t['name'] . ' यांचं 2-Step Reset झालं — पुढच्या Login ला ते पुन्हा सेट करतील.');
        }
        $error = 'Reset झालं नाही — तुमचा पासवर्ड तपासा.';
    }
}

$u = $load();
$enabled = tfaEnabled($u);
$setup = $_GET['setup'] ?? '';
if (!$enabled && $setup === 'totp') {
    if (empty($_SESSION['tfa_setup_secret'])) { $_SESSION['tfa_setup_secret'] = tfaNewSecret(); }
    $setupSecret = $_SESSION['tfa_setup_secret'];
    $qrSvg = tfaQrSvg(tfaOtpauthUri($setupSecret, $u['email'] ?: ('user' . $u['id']), $orgName));
}
$devices = [];
$st = $pdo->prepare("SELECT * FROM user_trusted_devices WHERE user_id = ? AND expires_at > NOW() ORDER BY COALESCE(last_used_at, created_at) DESC");
$st->execute([$u['id']]);
$devices = $st->fetchAll();
$recLeft = tfaRecoveryLeft($u['tfa_recovery'] ?? '');

if ($isSA) {
    $policy = array_filter(explode(',', (string)orgSetting($pdo, $orgId, 'tfa_required_categories', '')));
    $people = $pdo->prepare("SELECT u.id, u.name, u.email, u.role, u.tfa_method, u.tfa_enabled_at, COALESCE(rd.category, IF(u.role = 'super_admin', 'super_admin', 'staff')) AS cat
        FROM users u LEFT JOIN role_definitions rd ON rd.role_key = u.role
        WHERE u.organization_id = ? AND u.is_active = 1 AND (u.tfa_method IS NOT NULL OR u.role = 'super_admin' OR rd.category IN ('management','hod'))
        ORDER BY (u.tfa_method IS NULL) DESC, FIELD(cat, 'super_admin', 'management', 'hod', 'staff'), u.name");
    try { $people->execute([$orgId]); $people = $people->fetchAll(); } catch (PDOException $e) { $people = []; }
}
$catLabel = ['super_admin' => 'Super Admin', 'management' => 'Management', 'hod' => 'HOD', 'staff' => 'कर्मचारी'];

$pageTitle = '2-Step Login';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .tf-hero{display:flex;gap:16px;align-items:center;padding:20px 22px;border-radius:16px;color:#fff;background:linear-gradient(135deg,#191C3A,#0E1024);position:relative;overflow:hidden;margin-bottom:16px;}
  .tf-hero::after{content:'';position:absolute;right:-60px;top:-60px;width:220px;height:220px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--rail) 55%,transparent),transparent 70%);}
  .tf-hero .ic{width:56px;height:56px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;position:relative;z-index:1;}
  .tf-hero .ic.on{background:rgba(34,197,94,.18);color:#4ADE80;} .tf-hero .ic.off{background:rgba(245,158,11,.18);color:#FBBF24;}
  .tf-hero h2{font-size:18px;margin:0 0 3px;font-weight:700;position:relative;z-index:1;} .tf-hero p{margin:0;font-size:13px;color:#C9CCE0;position:relative;z-index:1;line-height:1.6;}
  .tf-pick{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:14px;}
  .tf-opt{display:block;padding:18px;border-radius:14px;border:1.5px solid var(--line);background:var(--surface,#fff);text-decoration:none;color:inherit;transition:border-color .2s,transform .2s,box-shadow .2s;}
  .tf-opt:hover{border-color:var(--rail);transform:translateY(-2px);box-shadow:0 10px 24px -14px var(--rail);}
  .tf-opt .t{font-weight:700;font-size:15px;margin:8px 0 4px;} .tf-opt .d{font-size:12.5px;color:var(--text-dim);line-height:1.6;}
  .tf-opt .badge-rec{font-size:10.5px;font-weight:700;padding:2px 8px;border-radius:99px;background:var(--rail-soft,#FCE9DE);color:var(--rail);}
  .tf-steps{counter-reset:s;list-style:none;padding:0;margin:0;} .tf-steps li{counter-increment:s;position:relative;padding:0 0 14px 36px;font-size:13.5px;line-height:1.6;}
  .tf-steps li::before{content:counter(s);position:absolute;left:0;top:0;width:24px;height:24px;border-radius:50%;background:var(--rail);color:#fff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;}
  .tf-qr{background:#fff;border-radius:14px;padding:10px;width:210px;max-width:100%;border:1px solid var(--line);} .tf-qr svg{width:100%;height:auto;display:block;}
  .tf-key{font-family:'Space Grotesk',monospace;font-size:15px;font-weight:700;letter-spacing:2px;background:var(--canvas);border:1px dashed var(--line);border-radius:10px;padding:8px 12px;word-break:break-all;}
  .tf-code{font-size:24px;letter-spacing:8px;text-align:center;font-weight:700;max-width:220px;font-family:'Space Grotesk',monospace;}
  .tf-codes{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin:12px 0;} .tf-codes code{display:block;text-align:center;padding:9px;border-radius:9px;background:var(--canvas);border:1px solid var(--line);font-size:15px;font-weight:700;letter-spacing:2px;color:var(--ink,inherit);}
  .tf-kv{display:flex;justify-content:space-between;gap:10px;padding:10px 0;border-bottom:1px dashed var(--line);font-size:13.5px;} .tf-kv:last-child{border-bottom:0;} .tf-kv span{color:var(--text-dim);}
  .tf-pill{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;white-space:nowrap;} .tf-pill.on{background:color-mix(in srgb,var(--green,#16a34a) 15%,transparent);color:var(--green,#16a34a);} .tf-pill.off{background:color-mix(in srgb,var(--rose,#e11d48) 13%,transparent);color:var(--rose,#e11d48);}
  .tf-btn{border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;background:var(--surface,#fff);color:inherit;font-weight:600;}
  .tf-btn.pri{background:var(--rail);border-color:var(--rail);color:#fff;} .tf-btn.dng{border-color:var(--rose,#e11d48);color:var(--rose,#e11d48);}
  @media print{body *{visibility:hidden;} #tfCodes,#tfCodes *{visibility:visible;} #tfCodes{position:absolute;left:0;top:0;width:100%;}}
</style>

<div class="page-eyebrow"><?= h($orgName) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">🔐 2-Step Login</div>
  <?php if (empty($_SESSION['tfa_setup_required'])): ?><a href="../staff/profile.php#account" class="tf-btn" style="text-decoration:none;"><i class="fa-solid fa-arrow-left me-1"></i>प्रोफाइल</a><?php endif; ?>
</div>

<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<div class="tf-hero">
  <div class="ic <?= $enabled ? 'on' : 'off' ?>"><i class="fa-solid <?= $enabled ? 'fa-shield-halved' : 'fa-triangle-exclamation' ?>"></i></div>
  <div>
    <h2><?= $enabled ? '2-Step Login चालू आहे' : ($required ? '2-Step Login अनिवार्य आहे — आत्ता चालू करा' : '2-Step Login बंद आहे') ?></h2>
    <p><?= $enabled
        ? 'पासवर्ड कोणाला कळला तरी तुमच्या ' . ($u['tfa_method'] === 'totp' ? 'फोनमधल्या App' : 'Email') . ' वरच्या Code शिवाय खातं उघडणार नाही.'
        : 'पासवर्डबरोबर दुसरी पायरी — फोनवरचा Code. पासवर्ड चोरीला गेला तरी खातं सुरक्षित राहतं.' ?></p>
  </div>
</div>

<?php if ($newCodes): ?>
<div class="panel mb-3" id="tfCodes" style="border:2px solid var(--rail);">
  <div class="panel-head"><div class="title">🔑 Recovery Codes — आत्ताच जतन करा</div><div class="sub">फोन हरवला / App नसेल तर यातला एक Code वापरून Login करता येईल. प्रत्येक Code एकदाच चालतो. हे पुन्हा दिसणार नाहीत.</div></div>
  <div style="padding:6px 18px 18px;">
    <div class="tf-codes"><?php foreach ($newCodes as $c): ?><code><?= h($c) ?></code><?php endforeach; ?></div>
    <div class="d-flex gap-2 flex-wrap">
      <button type="button" class="tf-btn" id="tfCopy"><i class="fa-regular fa-copy me-1"></i>Copy</button>
      <button type="button" class="tf-btn" id="tfDl"><i class="fa-solid fa-download me-1"></i>Download</button>
      <button type="button" class="tf-btn" onclick="window.print()"><i class="fa-solid fa-print me-1"></i>Print</button>
    </div>
  </div>
</div>
<script>
  (function () {
    var t = <?= json_encode("E-Admin — " . $orgName . "\n2-Step Login Recovery Codes (" . ($u['email'] ?? '') . ")\n" . date('d-m-Y') . "\n\n" . implode("\n", $newCodes) . "\n\nप्रत्येक Code एकदाच वापरता येतो.\n", JSON_UNESCAPED_UNICODE) ?>;
    document.getElementById('tfCopy').onclick = function () { navigator.clipboard && navigator.clipboard.writeText(t); this.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copied'; };
    document.getElementById('tfDl').onclick = function () { var a = document.createElement('a'); a.href = URL.createObjectURL(new Blob([t], { type: 'text/plain' })); a.download = 'e-admin-recovery-codes.txt'; a.click(); };
  })();
</script>
<?php endif; ?>

<?php if (!$enabled): ?>
  <?php if ($setup === 'totp'): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">📱 Authenticator App सेट करा</div><div class="sub">एकदाच करायचं — 2 मिनिटं</div></div>
    <div class="row g-4" style="padding:16px 20px 20px;">
      <div class="col-md-7">
        <ol class="tf-steps">
          <li>फोनमध्ये <b>Google Authenticator</b> किंवा <b>Microsoft Authenticator</b> App Install करा (Play Store / App Store).</li>
          <li>App मध्ये <b>+</b> → <b>QR Code Scan करा</b> → बाजूचा QR Scan करा.<br><span style="font-size:12.5px;color:var(--text-dim);">याच फोनवर हे पान उघडलं असेल तर: "Setup key टाका" निवडा आणि खालची Key टाका.</span>
            <div class="tf-key mt-2" id="tfKey"><?= h(trim(chunk_split($setupSecret, 4, ' '))) ?></div>
            <button type="button" class="tf-btn mt-2" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= h($setupSecret) ?>');this.textContent='✓ Copied'"><i class="fa-regular fa-copy me-1"></i>Key Copy करा</button>
          </li>
          <li>App मध्ये दिसणारा 6 अंकी Code खाली टाका.
            <form method="POST" class="d-flex gap-2 flex-wrap align-items-center mt-2">
              <input type="hidden" name="action" value="confirm_totp">
              <input name="code" class="form-control-custom tf-code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000" required autofocus>
              <button class="tf-btn pri" style="padding:11px 18px;"><i class="fa-solid fa-check me-1"></i>तपासा आणि चालू करा</button>
            </form>
          </li>
        </ol>
      </div>
      <div class="col-md-5 d-flex flex-column align-items-center">
        <div class="tf-qr"><?= $qrSvg ?></div>
        <div style="font-size:12px;color:var(--text-dim);margin-top:8px;text-align:center;">QR मध्ये फक्त तुमची गुप्त Key आहे — कोणालाही दाखवू नका.</div>
      </div>
    </div>
    <div style="padding:0 20px 16px;"><a href="two-factor.php" style="font-size:13px;"><i class="fa-solid fa-arrow-left me-1"></i>दुसरी पद्धत निवडा</a></div>
  </div>
  <?php elseif ($setup === 'email'): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">📧 Email Code सेट करा</div><div class="sub">प्रत्येक Login ला <?= h($u['email']) ?> वर Code येईल</div></div>
    <div style="padding:16px 20px 20px;">
      <?php if (empty($_GET['sent'])): ?>
        <p style="font-size:13.5px;">आधी तुमचा Email चालतो याची खात्री करू — Code पाठवतो.</p>
        <form method="POST"><input type="hidden" name="action" value="send_email_setup"><button class="tf-btn pri"><i class="fa-solid fa-paper-plane me-1"></i>Code पाठवा</button></form>
      <?php else: ?>
        <form method="POST" class="d-flex gap-2 flex-wrap align-items-center">
          <input type="hidden" name="action" value="confirm_email">
          <input name="code" class="form-control-custom tf-code" inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="000000" required autofocus>
          <button class="tf-btn pri" style="padding:11px 18px;"><i class="fa-solid fa-check me-1"></i>तपासा आणि चालू करा</button>
        </form>
        <form method="POST" class="mt-2"><input type="hidden" name="action" value="send_email_setup"><button class="btn btn-link p-0" style="font-size:13px;">Code आला नाही? पुन्हा पाठवा</button></form>
      <?php endif; ?>
      <div class="mt-3"><a href="two-factor.php" style="font-size:13px;"><i class="fa-solid fa-arrow-left me-1"></i>दुसरी पद्धत निवडा</a></div>
    </div>
  </div>
  <?php else: ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">पद्धत निवडा</div><div class="sub">दोन्हीत Recovery Codes मिळतात</div></div>
    <div class="tf-pick" style="padding:16px 20px 20px;">
      <a class="tf-opt" href="?setup=totp">
        <i class="fa-solid fa-mobile-screen-button fa-2x" style="color:var(--rail);"></i> <span class="badge-rec">शिफारस</span>
        <div class="t">Authenticator App</div>
        <div class="d">Google / Microsoft Authenticator — Internet नसतानाही Code. सर्वात सुरक्षित, Email उशिरा येण्याचा प्रश्न नाही.</div>
      </a>
      <a class="tf-opt" href="?setup=email">
        <i class="fa-solid fa-envelope-circle-check fa-2x" style="color:var(--rail);"></i>
        <div class="t">Email Code</div>
        <div class="d">प्रत्येक Login ला <?= h($u['email'] ?: 'तुमच्या Email') ?> वर 6 अंकी Code. App नको असेल तर सोपं.</div>
      </a>
    </div>
  </div>
  <?php endif; ?>
<?php else: ?>
  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">स्थिती</div></div>
        <div style="padding:6px 20px 16px;">
          <div class="tf-kv"><span>पद्धत</span><b><?= $u['tfa_method'] === 'totp' ? '📱 Authenticator App' : '📧 Email Code' ?></b></div>
          <div class="tf-kv"><span>चालू केलं</span><b><?= h(date('d M Y, h:i A', strtotime($u['tfa_enabled_at']))) ?></b></div>
          <div class="tf-kv"><span>Recovery Codes शिल्लक</span><b style="color:<?= $recLeft <= 2 ? 'var(--rose)' : 'inherit' ?>;"><?= $recLeft ?> / 10</b></div>
          <div class="tf-kv"><span>अनिवार्य</span><b><?= $required ? 'होय' : 'नाही' ?></b></div>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">बदल</div><div class="sub">सुरक्षिततेसाठी पासवर्ड विचारला जातो</div></div>
        <form method="POST" style="padding:12px 20px 18px;" id="tfManage">
          <input type="password" name="password" class="form-control-custom mb-2" placeholder="सध्याचा पासवर्ड" required autocomplete="current-password">
          <div class="d-flex gap-2 flex-wrap">
            <button class="tf-btn" name="action" value="regen"><i class="fa-solid fa-key me-1"></i>नवीन Recovery Codes</button>
            <button class="tf-btn" name="action" value="change"><i class="fa-solid fa-arrows-rotate me-1"></i>पद्धत बदला</button>
            <?php if (!$required): ?><button class="tf-btn dng" name="action" value="disable" onclick="return confirm('2-Step Login बंद करायचं? खातं कमी सुरक्षित होईल.');"><i class="fa-solid fa-power-off me-1"></i>बंद करा</button><?php endif; ?>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>

<?php if ($enabled || $devices): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">💻 लक्षात ठेवलेले Devices</div><div class="sub">इथे <?= TFA_TRUST_DAYS ?> दिवस Code विचारला जात नाही</div></div>
  <?php if (!$devices): ?>
    <div style="padding:14px 20px 18px;font-size:13px;color:var(--text-dim);">एकही नाही. Login करताना "हा Device लक्षात ठेवा" निवडलं तर इथे दिसेल.</div>
  <?php else: ?>
    <div style="padding:4px 20px 14px;">
      <?php foreach ($devices as $d): ?>
        <div class="tf-kv" style="align-items:center;">
          <div><b style="font-size:13.5px;"><?= h($d['label'] ?: 'Device') ?></b><div style="font-size:12px;color:var(--text-dim);">IP <?= h($d['ip']) ?> · शेवटचा वापर <?= h(date('d M, h:i A', strtotime($d['last_used_at'] ?: $d['created_at']))) ?> · <?= h(date('d M', strtotime($d['expires_at']))) ?> पर्यंत</div></div>
          <form method="POST" style="margin:0;"><input type="hidden" name="action" value="forget_device"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="tf-btn dng" style="padding:5px 10px;font-size:12px;">काढा</button></form>
        </div>
      <?php endforeach; ?>
      <form method="POST" class="mt-2"><input type="hidden" name="action" value="forget_all"><button class="tf-btn" style="font-size:12.5px;"><i class="fa-solid fa-broom me-1"></i>सगळे काढा</button></form>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($isSA): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🛡️ संस्थेचं धोरण (Super Admin)</div><div class="sub">Super Admin साठी नेहमी अनिवार्य. आणखी कोणासाठी?</div></div>
  <form method="POST" style="padding:12px 20px 18px;">
    <input type="hidden" name="action" value="policy">
    <div class="d-flex gap-3 flex-wrap mb-3" style="font-size:13.5px;">
      <label class="d-flex align-items-center gap-2"><input type="checkbox" checked disabled> Super Admin</label>
      <?php foreach (['management' => 'Management (अध्यक्ष, सचिव, GM…)', 'hod' => 'विभाग प्रमुख (HOD)', 'staff' => 'सर्व कर्मचारी'] as $k => $lbl): ?>
        <label class="d-flex align-items-center gap-2"><input type="checkbox" name="cats[]" value="<?= $k ?>" <?= in_array($k, $policy, true) ? 'checked' : '' ?>> <?= h($lbl) ?></label>
      <?php endforeach; ?>
    </div>
    <button class="tf-btn pri"><i class="fa-solid fa-floppy-disk me-1"></i>धोरण जतन करा</button>
    <div style="font-size:12px;color:var(--text-dim);margin-top:8px;">अनिवार्य केलेल्यांना पुढच्या Login ला 2-Step चालू केल्याशिवाय पुढे जाता येणार नाही.</div>
  </form>

  <div style="padding:0 20px 18px;">
    <div style="font-weight:700;font-size:14px;margin:6px 0 8px;">कोणाचं चालू आहे</div>
    <div style="overflow-x:auto;">
    <table class="rtable">
      <thead><tr><th>नाव</th><th>पद</th><th>2-Step</th></tr></thead>
      <tbody>
      <?php foreach ($people as $p): $req = $p['cat'] === 'super_admin' || in_array($p['cat'], $policy, true); ?>
        <tr>
          <td><b><?= h($p['name']) ?></b><div style="font-size:12px;color:var(--text-dim);"><?= h($p['email']) ?></div></td>
          <td style="font-size:12.5px;"><?= h($catLabel[$p['cat']] ?? $p['cat']) ?><?= $req ? ' · <b style="color:var(--rail);">अनिवार्य</b>' : '' ?></td>
          <td><?php if ($p['tfa_method']): ?><span class="tf-pill on"><?= $p['tfa_method'] === 'totp' ? 'App' : 'Email' ?> · चालू</span><?php else: ?><span class="tf-pill off">बंद</span><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php $resettable = array_filter($people, fn($p) => $p['tfa_method'] && (int)$p['id'] !== (int)$u['id']); ?>
    <?php if ($resettable): ?>
    <form method="POST" class="d-flex gap-2 flex-wrap align-items-center mt-3" onsubmit="return confirm('निवडलेल्या व्यक्तीचं 2-Step Reset करायचं?');">
      <input type="hidden" name="action" value="reset_user">
      <select name="user_id" class="form-select-custom" style="max-width:260px;" required>
        <option value="">— 2-Step Reset: व्यक्ती निवडा —</option>
        <?php foreach ($resettable as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?></option><?php endforeach; ?>
      </select>
      <input type="password" name="password" class="form-control-custom" style="max-width:220px;" placeholder="तुमचा पासवर्ड" required autocomplete="current-password">
      <button class="tf-btn dng"><i class="fa-solid fa-rotate-left me-1"></i>Reset</button>
    </form>
    <?php endif; ?>
    <div style="font-size:12px;color:var(--text-dim);margin-top:8px;">एखाद्याचा फोन हरवला आणि Recovery Codes नसतील तर Reset करा — पुढच्या Login ला ते पुन्हा सेट करतील.</div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
