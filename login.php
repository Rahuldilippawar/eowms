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
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/css/bootstrap.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/eowms.css">
</head>
<body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;">
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
  <div style="width:380px;background:var(--surface);border:1px solid var(--line);border-radius:16px;padding:32px;">
    <div style="text-align:center;margin:-8px 0 18px;">
      <img src="assets/brand/e-admin-logo.png" alt="E-Admin — Smart Administration" style="width:150px;height:auto;">
    </div>
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
      <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--rail),#0F5E58);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Space Grotesk';font-weight:700;"><?= h(mb_substr($loginOrgName, 0, 2)) ?></div>
      <div>
        <div style="font-family:'Space Grotesk';font-weight:700;font-size:16px;color:var(--ink);"><?= h($loginOrgName) ?></div>
        <div style="font-size:11.5px;color:var(--text-dim);">E-Admin — Smart Administration</div>
      </div>
    </div>

    <?php if (!empty($_SESSION['session_expired'])): ?>
      <div class="alert alert-danger" style="border-radius:9px;font-size:13px;">⏰ ३० मिनिटे निष्क्रियतेमुळे तुम्ही आपोआप Logout झालात — सुरक्षिततेसाठी. कृपया परत Login करा.</div>
      <?php unset($_SESSION['session_expired']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['account_deactivated'])): ?>
      <div class="alert alert-danger" style="border-radius:9px;font-size:13px;">तुमचं खातं किंवा संस्था निष्क्रिय (Inactive) केली गेली आहे — त्यामुळे तुम्हाला Logout केलं. कृपया Super Admin शी संपर्क साधा.</div>
      <?php unset($_SESSION['account_deactivated']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['reset_success'])): ?>
      <div class="alert alert-success" style="border-radius:9px;font-size:13px;">तुमचा पासवर्ड यशस्वीरित्या बदलला गेला — आता नवीन पासवर्डने Login करा.</div>
      <?php unset($_SESSION['reset_success']); ?>
    <?php endif; ?>
    <?php if ($error): ?>
      <div class="alert alert-danger" style="border-radius:9px;font-size:13px;"><?= h($error) ?></div>
    <?php endif; ?>

    <form method="POST">
      <label class="form-label-custom"><?= h(t('s48')) ?></label>
      <input type="email" name="email" id="loginEmailField" class="form-control-custom mb-3" placeholder="mtn.staff1@shivajitrust.edu.in" required>
      <label class="form-label-custom"><?= h(t('s62')) ?></label>
      <input type="password" name="password" class="form-control-custom mb-3" placeholder="password" required>
      <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;padding:10px;"><?= h(t('s158')) ?></button>
    </form>

    <div style="text-align:center;margin-top:14px;">
      <a href="forgot-password.php" style="font-size:12.5px;color:var(--rail);text-decoration:none;font-weight:600;"><?= h(t('s159')) ?></a>
    </div>
    <div style="font-size:11.5px;color:var(--text-dim);margin-top:16px;text-align:center;">
      डेमो: mtn.staff1@shivajitrust.edu.in / password
    </div>
  </div>
</body>
</html>
