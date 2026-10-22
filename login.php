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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND is_active = 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && !empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
        // Account is currently locked out — don't even check the password,
        // and don't reveal exactly why to avoid helping an attacker guess.
        $minutesLeft = (int)ceil((strtotime($user['locked_until']) - time()) / 60);
        $error = "खूप चुकीचे प्रयत्न झाल्यामुळे हे खाते तात्पुरते बंद केले आहे — कृपया $minutesLeft मिनिटांनी पुन्हा प्रयत्न करा.";
    } elseif ($user && password_verify($password, $user['password'])) {
        // Correct password — reset any prior failed-attempt counter
        $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$user['id']]);

        session_regenerate_id(true); // prevents session-fixation attacks
        $mustChange = !empty($user['must_change_password']);
        unset($user['password']);
        $_SESSION['user'] = $user;
        $_SESSION['last_activity'] = time();
        $_SESSION['play_welcome_chime'] = true;
        $loginIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
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
        if ($user) {
            // Wrong password for a real account — count the attempt
            $attempts = (int)$user['failed_login_attempts'] + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                    ->execute([$attempts, $user['id']]);
                $error = "खूप चुकीचे प्रयत्न झाले — सुरक्षिततेसाठी हे खाते १५ मिनिटांसाठी तात्पुरते बंद केले आहे.";
            } else {
                $pdo->prepare("UPDATE users SET failed_login_attempts = ? WHERE id = ?")->execute([$attempts, $user['id']]);
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
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:24px;">
      <div style="width:38px;height:38px;border-radius:10px;background:linear-gradient(135deg,var(--rail),#0F5E58);display:flex;align-items:center;justify-content:center;color:#fff;font-family:'Space Grotesk';font-weight:700;"><?= h(mb_substr($loginOrgName, 0, 2)) ?></div>
      <div>
        <div style="font-family:'Space Grotesk';font-weight:700;font-size:16px;color:var(--ink);"><?= h($loginOrgName) ?></div>
        <div style="font-size:11.5px;color:var(--text-dim);">Enterprise Operations & Workflow Management</div>
      </div>
    </div>

    <?php if (!empty($_SESSION['session_expired'])): ?>
      <div class="alert alert-danger" style="border-radius:9px;font-size:13px;">⏰ ३० मिनिटे निष्क्रियतेमुळे तुम्ही आपोआप Logout झालात — सुरक्षिततेसाठी. कृपया परत Login करा.</div>
      <?php unset($_SESSION['session_expired']); ?>
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
