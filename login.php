<?php
require_once __DIR__ . '/includes/auth.php';

if (currentUser()) {
    header('Location: index.php');
    exit;
}

$error = null;
if (($_GET['msg'] ?? '') === 'session_expired') {
    $error = t('s456');
}

/** अयशस्वी Login ची नोंद — त्या Email ची संस्था सापडली तर तिच्याच Activity Log मध्ये */
function logFailedLogin($pdo, $email, $reason) {
    try {
        $oStmt = $pdo->prepare("SELECT id, organization_id FROM users WHERE email = ? LIMIT 1");
        $oStmt->execute([$email]);
        $oRow = $oStmt->fetch();
        $pdo->prepare("INSERT INTO activity_logs (organization_id, user_id, actor_name, action, entity_type, entity_id, details, created_at) VALUES (?, NULL, ?, ?, 'user', ?, ?, ?)")
            ->execute([$oRow['organization_id'] ?? null, mb_substr($email, 0, 255), 'Login अयशस्वी', $oRow['id'] ?? null, $reason . ' — IP: ' . clientIp(), date('Y-m-d H:i:s')]);
    } catch (PDOException $e) {
        // activity_logs table नसेल तर शांतपणे वगळा
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    // 🔒 एकाच IP वरून १५ मिनिटांत २० पेक्षा जास्त चुकीचे प्रयत्न — Brute-force
    // (अनेक खाती एकामागून एक तपासणं) रोखण्यासाठी तो IP तात्पुरता थांबवतो.
    if (publicFormRateLimited('login_fail', 20, 900)) {
        $error = 'या नेटवर्कवरून खूप चुकीचे Login प्रयत्न झाले — कृपया १५ मिनिटांनी पुन्हा प्रयत्न करा.';
    } else {
    $stmt = $pdo->prepare("SELECT u.*, o.is_active AS org_is_active FROM users u LEFT JOIN organizations o ON o.id = u.organization_id WHERE u.email = ? AND u.is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    $orgIsActive = $user ? ((int)($user['org_is_active'] ?? 0) === 1) : false;
    if ($user) { unset($user['org_is_active']); }

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        logFailedLogin($pdo, $email, 'खाते Lock असताना प्रयत्न');
        // Account is currently locked out — don't even check the password,
        // and don't reveal exactly why to avoid helping an attacker guess.
        $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        $error = "खूप चुकीचे प्रयत्न झाल्यामुळे हे खाते तात्पुरते बंद केले आहे — कृपया $minutesLeft मिनिटांनी पुन्हा प्रयत्न करा.";
    } elseif ($user && !$orgIsActive && password_verify($password, $user['password'])) {
        // संस्था निष्क्रिय केलेली आहे — बरोबर पासवर्ड असला तरी प्रवेश नाही
        logFailedLogin($pdo, $email, 'संस्था निष्क्रिय');
        $error = 'तुमची संस्था सध्या निष्क्रिय (Inactive) आहे — Login करता येणार नाही. कृपया Super Admin शी संपर्क साधा.';
    } elseif ($user && password_verify($password, $user['password'])) {
        // Correct password — reset any prior failed-attempt counter
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);

        session_regenerate_id(true); // prevents session-fixation attacks
        $mustChange = !empty($user['must_change_password']);
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        $_SESSION['user_recheck_at'] = time();
        $_SESSION['play_welcome_chime'] = true;
        $loginIp = clientIp();
        logActivity('Login केलं (Password)', 'user', $user['id'], 'IP: ' . $loginIp);

        // 🛠️ Site Maintenance Mode — Super Admin सोडून बाकी कोणालाही
        // Maintenance सुरू असताना नव्याने Login करता येणार नाही.
        if (($user['role'] ?? '') !== 'super_admin') {
            $mCheckStmt = $pdo->prepare("SELECT maintenance_mode FROM organizations WHERE id = ?");
            $mCheckStmt->execute([$user['organization_id']]);
            if ((int)($mCheckStmt->fetch()['maintenance_mode'] ?? 0) === 1) {
                header('Location: maintenance.php');
                exit;
            }
        }

        $loginRedirect = 'index.php';
        if ($mustChange) {
            $loginRedirect = 'force-password-change.php';
        } else {
            $restrictedModulesAtLogin = getRestrictedModules();
            if ($restrictedModulesAtLogin !== null && !empty($restrictedModulesAtLogin)) {
                $registryAtLogin = getModuleRegistry();
                $firstModuleAtLogin = $registryAtLogin[$restrictedModulesAtLogin[0]] ?? null;
                if ($firstModuleAtLogin) { $loginRedirect = $firstModuleAtLogin['dashboard']; }
            }
        }
        header('Location: ' . $loginRedirect);
        exit;
    } else {
        publicFormRecordSubmission('login_fail');
        logFailedLogin($pdo, $email, $user ? 'चुकीचा पासवर्ड' : 'अज्ञात / निष्क्रिय खाते');
        if ($user) {
            // Wrong password for a real account — count the attempt
            // आधीचा Lockout संपला असेल तर मोजणी शून्यापासून — नाहीतर पुढच्याच
            // एका चुकीनंतर खाते पुन्हा लगेच Lock होतं
            $priorAttempts = !empty($user['locked_until']) ? 0 : (int)$user['failed_login_attempts'];
            $attempts = $priorAttempts + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                    ->execute([$attempts, $user['id']]);
                $error = "खूप चुकीचे प्रयत्न झाले — सुरक्षिततेसाठी हे खाते १५ मिनिटांसाठी तात्पुरते बंद केले आहे.";
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = NULL WHERE id = ?")->execute([$attempts, $user['id']]);
                $remaining = 5 - $attempts;
                $error = "चुकीचा ईमेल किंवा पासवर्ड — $remaining प्रयत्न शिल्लक आहेत, त्यानंतर खाते तात्पुरते बंद होईल.";
            }
        } else {
            $error = t('s457');
        }
    }
    }
}

// Pre-login — use the primary/first organization's name for branding (typical single-org deployment)
$loginOrg = $pdo->query("SELECT name FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$loginOrgName = $loginOrg['name'] ?? 'VJMAT';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= h(t('s157')) ?> <?= h($loginOrgName) ?></title>
<link rel="icon" type="image/png" href="assets/brand/favicon-32.png">
<meta name="theme-color" content="#10162B">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/eowms.css">
<style>
  body{margin:0;min-height:100vh;background:#0A0E1A;font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;}
  .lg-wrap{min-height:100vh;display:grid;grid-template-columns:1.05fr 1fr;}
  .lg-brand{position:relative;overflow:hidden;color:#fff;padding:56px 56px 40px;display:flex;flex-direction:column;justify-content:space-between;
    background:radial-gradient(circle at 15% 10%,rgba(27,138,131,.45),transparent 55%),radial-gradient(circle at 90% 95%,rgba(201,127,29,.3),transparent 50%),#10162B;}
  .lg-brand .ring{position:absolute;width:560px;height:560px;border:1px solid rgba(255,255,255,.07);border-radius:50%;right:-180px;top:50%;transform:translateY(-50%);}
  .lg-brand .ring.r2{width:380px;height:380px;right:-90px;}
  .lg-org{display:flex;align-items:center;gap:12px;position:relative;}
  .lg-mark{width:44px;height:44px;border-radius:12px;background:linear-gradient(135deg,#1B8A83,#0F5E58);display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk';font-weight:700;font-size:17px;}
  .lg-org b{display:block;font-family:'Space Grotesk';font-size:17px;}
  .lg-org span{font-size:12px;color:#9FA6C0;}
  .lg-mid{position:relative;max-width:440px;}
  .lg-mantra{color:#7FD1C8;font-size:13px;letter-spacing:.12em;font-weight:600;margin-bottom:10px;}
  .lg-mid h1{font-family:'Space Grotesk','Noto Sans Devanagari';font-size:32px;font-weight:700;line-height:1.3;margin:0 0 14px;}
  .lg-mid p{color:#B7BBD6;font-size:14px;line-height:1.8;margin:0 0 22px;}
  .lg-feat{list-style:none;padding:0;margin:0;display:grid;gap:10px;}
  .lg-feat li{display:flex;gap:10px;align-items:center;font-size:13.5px;color:#DCE1F0;}
  .lg-feat i{width:28px;height:28px;border-radius:8px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:#7FD1C8;font-size:12px;flex-shrink:0;}
  .lg-time{position:relative;font-size:12.5px;color:#9FA6C0;}
  .lg-time b{font-family:'JetBrains Mono',monospace;color:#fff;font-size:15px;margin-left:4px;}
  .lg-form-side{background:#F3F4F7;display:flex;align-items:center;justify-content:center;padding:40px 20px;}
  .lg-card{width:100%;max-width:400px;background:#fff;border:1px solid #E4E6EC;border-radius:18px;padding:32px 30px;box-shadow:0 20px 50px rgba(16,22,43,.08);}
  .lg-card h2{font-family:'Space Grotesk','Noto Sans Devanagari';font-size:21px;font-weight:700;color:#10162B;margin:0 0 4px;}
  .lg-card .sub{font-size:12.5px;color:#6B7080;margin-bottom:22px;}
  .lg-field{position:relative;margin-bottom:14px;}
  .lg-field i.lead{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#9AA0B4;font-size:13px;}
  .lg-field input{width:100%;border:1px solid #E4E6EC;border-radius:10px;padding:11px 42px 11px 38px;font-size:14px;background:#F8F9FB;font-family:inherit;color:#10162B;transition:border-color .15s,box-shadow .15s,background .15s;}
  .lg-field input:focus{outline:none;border-color:#1B8A83;box-shadow:0 0 0 3px rgba(27,138,131,.18);background:#fff;}
  .lg-eye{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:transparent;color:#6B7080;padding:6px 8px;cursor:pointer;border-radius:6px;}
  .lg-eye:hover{color:#1B8A83;}
  .lg-caps{display:none;font-size:11.5px;color:#C97F1D;font-weight:600;margin:-8px 0 10px;}
  .lg-row{display:flex;justify-content:space-between;align-items:center;margin:2px 0 18px;font-size:12.5px;}
  .lg-row label{display:flex;align-items:center;gap:7px;color:#4B5063;cursor:pointer;}
  .lg-row input{accent-color:#1B8A83;width:15px;height:15px;}
  .lg-row a{color:#1B8A83;font-weight:600;text-decoration:none;}
  .lg-btn{width:100%;border:0;border-radius:10px;padding:12px;font-size:14.5px;font-weight:700;color:#fff;background:linear-gradient(135deg,#1B8A83,#0F5E58);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;transition:filter .15s,transform .1s;}
  .lg-btn:hover{filter:brightness(1.07);} .lg-btn:active{transform:translateY(1px);}
  .lg-btn[disabled]{opacity:.75;cursor:progress;}
  .lg-or{display:flex;align-items:center;gap:10px;color:#9AA0B4;font-size:11.5px;margin:16px 0;}
  .lg-or::before,.lg-or::after{content:'';flex:1;height:1px;background:#E4E6EC;}
  .lg-fp{width:100%;border:1px solid #E4E6EC;background:#fff;border-radius:10px;padding:11px;font-size:13.5px;font-weight:600;color:#10162B;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;font-family:inherit;}
  .lg-fp:hover{border-color:#1B8A83;color:#1B8A83;}
  .lg-links{display:flex;flex-wrap:wrap;justify-content:center;gap:6px 16px;margin-top:22px;padding-top:16px;border-top:1px solid #EEF0F4;font-size:12px;}
  .lg-links a{color:#6B7080;text-decoration:none;} .lg-links a:hover{color:#1B8A83;}
  .lg-secure{text-align:center;font-size:11px;color:#9AA0B4;margin-top:14px;}
  .alert{border-radius:10px;font-size:13px;}
  @media (max-width:900px){
    .lg-wrap{grid-template-columns:1fr;}
    .lg-brand{padding:66px 22px 22px;}
    .lg-mid h1{font-size:22px;} .lg-mid p,.lg-feat,.lg-brand .ring{display:none;}
    .lg-time{margin-top:10px;}
    .lg-form-side{padding:22px 14px 40px;align-items:flex-start;}
  }
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid #E4E6EC;border-radius:9px;padding:8px 12px;font-size:12.5px;color:#6B7080;background:#fff;cursor:pointer;">
  <option value="">🌐 भाषा (Language)</option>
  <option value="en">English</option>
  <option value="hi">हिंदी (Hindi)</option>
  <option value="gu">ગુજરાતી (Gujarati)</option>
  <option value="mr">मराठी (मूळ)</option>
</select>
<div id="google_translate_element" style="display:none;"></div>
<style>body > .skiptranslate{display:none !important;} body{top:0 !important;}</style>
<script>
  function googleTranslateElementInit() {
    new google.translate.TranslateElement({ pageLanguage: 'mr', autoDisplay: false }, 'google_translate_element');
  }
  function eowmsChangeLanguage(lang) {
    var tries = 0;
    var trigger = function () {
      var combo = document.querySelector('.goog-te-combo');
      if (combo) {
        combo.value = lang;
        combo.dispatchEvent(new Event('change'));
      } else if (tries < 20) {
        tries++;
        setTimeout(trigger, 250);
      }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>

<div class="lg-wrap">
  <aside class="lg-brand">
    <div class="ring"></div><div class="ring r2"></div>
    <div class="lg-org">
      <div class="lg-mark"><?= h(mb_substr($loginOrgName, 0, 2)) ?></div>
      <div><b><?= h($loginOrgName) ?></b><span>E-Admin — Smart Administration</span></div>
    </div>
    <div class="lg-mid">
      <div class="lg-mantra">!! आत्मा मालिक !!</div>
      <h1>संस्थेचं सगळं कामकाज — एकाच ठिकाणी</h1>
      <p>रोजची कामं, Tasks, पत्रव्यवहार, हजेरी, नूतनीकरण आणि अहवाल — सुरक्षित व सोप्या पद्धतीने.</p>
      <ul class="lg-feat">
        <li><i class="fa-solid fa-gauge-high"></i>Smart Command Center — आजचं संपूर्ण चित्र</li>
        <li><i class="fa-solid fa-list-check"></i>Tasks, विनंत्या व मुदतींची आपोआप आठवण</li>
        <li><i class="fa-solid fa-fingerprint"></i>Fingerprint / Face ID ने झटपट Login</li>
      </ul>
    </div>
    <div class="lg-time"><span id="lgGreet"></span><b id="lgClock"></b></div>
  </aside>

  <main class="lg-form-side">
    <div class="lg-card">
      <h2>पुन्हा स्वागत आहे 🙏</h2>
      <div class="sub">तुमच्या खात्यात Login करा</div>

      <?php if (!empty($_SESSION['session_expired'])): ?>
        <div class="alert alert-warning">⏰ ३० मिनिटे निष्क्रियतेमुळे तुम्ही आपोआप Logout झालात — सुरक्षिततेसाठी. कृपया परत Login करा.</div>
        <?php unset($_SESSION['session_expired']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['account_deactivated'])): ?>
        <div class="alert alert-danger">तुमचं खातं किंवा संस्था निष्क्रिय (Inactive) केली गेली आहे — त्यामुळे तुम्हाला Logout केलं. कृपया Super Admin शी संपर्क साधा.</div>
        <?php unset($_SESSION['account_deactivated']); ?>
      <?php endif; ?>
      <?php if (!empty($_SESSION['reset_success'])): ?>
        <div class="alert alert-success">तुमचा पासवर्ड यशस्वीरित्या बदलला गेला — आता नवीन पासवर्डने Login करा.</div>
        <?php unset($_SESSION['reset_success']); ?>
      <?php endif; ?>
      <?php if ($error): ?>
        <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
      <?php endif; ?>

      <form method="POST" id="lgForm" novalidate>
        <label class="form-label-custom" for="loginEmailField"><?= h(t('s48')) ?></label>
        <div class="lg-field">
          <i class="fa-regular fa-envelope lead"></i>
          <input type="email" name="email" id="loginEmailField" placeholder="तुमचा ईमेल" autocomplete="username" required
                 value="<?= h($_POST['email'] ?? '') ?>" <?= empty($_POST['email']) ? 'autofocus' : '' ?>>
        </div>
        <label class="form-label-custom" for="loginPassField"><?= h(t('s62')) ?></label>
        <div class="lg-field">
          <i class="fa-solid fa-lock lead"></i>
          <input type="password" name="password" id="loginPassField" placeholder="पासवर्ड" autocomplete="current-password" required <?= !empty($_POST['email']) ? 'autofocus' : '' ?>>
          <button type="button" class="lg-eye" id="lgEye" aria-label="पासवर्ड दाखवा"><i class="fa-regular fa-eye"></i></button>
        </div>
        <div class="lg-caps" id="lgCaps">⇪ Caps Lock चालू आहे</div>
        <div class="lg-row">
          <label><input type="checkbox" id="lgRemember"> ईमेल लक्षात ठेवा</label>
          <a href="forgot-password.php"><?= h(t('s159')) ?></a>
        </div>
        <button type="submit" class="lg-btn" id="lgSubmit"><i class="fa-solid fa-right-to-bracket"></i> <span><?= h(t('s158')) ?></span></button>
      </form>

      <div id="lgFpWrap" hidden>
        <div class="lg-or">किंवा</div>
        <button type="button" class="lg-fp" id="lgFp"><i class="fa-solid fa-fingerprint"></i> Fingerprint / Face ID ने Login</button>
      </div>

      <div class="lg-links">
        <a href="index.php"><i class="fa-solid fa-house"></i> मुख्य पान</a>
        <a href="student/login.php"><i class="fa-solid fa-graduation-cap"></i> विद्यार्थी Login</a>
        <a href="track-request.php"><i class="fa-solid fa-magnifying-glass"></i> विनंतीची स्थिती</a>
      </div>
      <div class="lg-secure"><i class="fa-solid fa-shield-halved"></i> सुरक्षित Login — ५ चुकीच्या प्रयत्नांनंतर खाते १५ मिनिटांसाठी Lock होतं</div>
    </div>
  </main>
</div>

<script src="assets/js/webauthn.js"></script>
<script>
(function () {
  var email = document.getElementById('loginEmailField'), pass = document.getElementById('loginPassField');
  var remember = document.getElementById('lgRemember'), KEY = 'eowmsLoginEmail';
  // ईमेल लक्षात ठेवा — फक्त ईमेल, पासवर्ड कधीच नाही
  try {
    var saved = localStorage.getItem(KEY);
    if (saved && !email.value) { email.value = saved; remember.checked = true; pass.focus(); }
  } catch (e) {}
  // पासवर्ड दाखवा / लपवा
  var eye = document.getElementById('lgEye');
  eye.addEventListener('click', function () {
    var show = pass.type === 'password';
    pass.type = show ? 'text' : 'password';
    eye.innerHTML = show ? '<i class="fa-regular fa-eye-slash"></i>' : '<i class="fa-regular fa-eye"></i>';
    eye.setAttribute('aria-label', show ? 'पासवर्ड लपवा' : 'पासवर्ड दाखवा');
    pass.focus();
  });
  // Caps Lock इशारा
  var caps = document.getElementById('lgCaps');
  ['keydown', 'keyup'].forEach(function (ev) {
    pass.addEventListener(ev, function (e) { if (e.getModifierState) caps.style.display = e.getModifierState('CapsLock') ? 'block' : 'none'; });
  });
  pass.addEventListener('blur', function () { caps.style.display = 'none'; });
  // Submit — तपासणी, ईमेल साठवणं, बटण "Login होत आहे…"
  var form = document.getElementById('lgForm'), btn = document.getElementById('lgSubmit');
  form.addEventListener('submit', function (e) {
    if (!email.value.trim() || !pass.value) { e.preventDefault(); (email.value.trim() ? pass : email).focus(); return; }
    try { if (remember.checked) localStorage.setItem(KEY, email.value.trim()); else localStorage.removeItem(KEY); } catch (x) {}
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Login होत आहे…';
  });
  window.addEventListener('pageshow', function () { btn.disabled = false; });
  // Fingerprint / Face ID — Browser समर्थन देत असेल तरच बटण
  if (window.PublicKeyCredential && typeof eowmsLoginWithFingerprint === 'function') {
    document.getElementById('lgFpWrap').hidden = false;
    document.getElementById('lgFp').addEventListener('click', function () {
      if (!email.value.trim()) { email.focus(); email.setCustomValidity('आधी तुमचा ईमेल टाका'); email.reportValidity(); setTimeout(function () { email.setCustomValidity(''); }, 2000); return; }
      eowmsLoginWithFingerprint(email.value.trim());
    });
  }
  // नमस्कार + घड्याळ
  var g = document.getElementById('lgGreet'), c = document.getElementById('lgClock');
  function tick() {
    var d = new Date(), h = d.getHours();
    g.textContent = h < 12 ? '☀️ शुभ सकाळ' : h < 17 ? '🌤️ नमस्कार' : h < 21 ? '🌇 शुभ संध्याकाळ' : '🌙 शुभ रात्री';
    c.textContent = d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }
  tick(); setInterval(tick, 30000);
})();
</script>
</body>
</html>
