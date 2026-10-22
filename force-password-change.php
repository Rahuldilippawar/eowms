<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
$user = currentUser();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 6) {
        $error = 'नवीन पासवर्ड किमान ६ अक्षरांचा असावा.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'नवीन पासवर्ड आणि पुष्टी जुळत नाहीत.';
    } else {
        $update = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['user']['must_change_password'] = 0;
        logActivity('पहिल्या login नंतर पासवर्ड बदलला', 'user', $user['id'], $user['name']);
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>नवीन पासवर्ड तयार करा</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#10162B;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;}
  .card{background:#fff;max-width:420px;width:92%;padding:36px 34px;border-radius:16px;box-shadow:0 20px 60px rgba(0,0,0,.3);}
  .icon{width:56px;height:56px;border-radius:14px;background:#1B8A83;display:flex;align-items:center;justify-content:center;font-size:26px;margin-bottom:18px;}
  h1{font-family:'Space Grotesk';font-size:21px;margin:0 0 8px;color:#10162B;}
  p{font-size:13.5px;color:#666B7D;line-height:1.6;margin:0 0 22px;}
  label{font-size:12.5px;font-weight:600;color:#1A1D29;display:block;margin-bottom:5px;}
  input{width:100%;padding:11px 14px;border:1px solid #D7DAE0;border-radius:9px;font-size:14px;margin-bottom:16px;font-family:inherit;}
  button{width:100%;background:#1B8A83;color:#fff;border:none;border-radius:9px;padding:12px;font-size:14.5px;font-weight:700;cursor:pointer;font-family:inherit;}
  .err{background:#F7E2E3;color:#8A1F2B;border-radius:8px;padding:10px 14px;font-size:12.5px;margin-bottom:16px;}
  .welcome{font-size:12.5px;color:#0F5E58;background:#DCEFEC;border-radius:8px;padding:10px 14px;margin-bottom:18px;}
</style>
</head>
<body>
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

<div class="card">
  <div class="icon">🔒</div>
  <h1>नवीन पासवर्ड तयार करा</h1>
  <p>सुरक्षिततेसाठी, पहिल्यांदा login केल्यावर स्वतःचा नवीन पासवर्ड तयार करणे आवश्यक आहे.</p>
  <div class="welcome">नमस्कार <strong><?= h($user['name']) ?></strong> 👋</div>

  <?php if ($error): ?><div class="err"><?= h($error) ?></div><?php endif; ?>

  <form method="POST">
    <label>नवीन पासवर्ड (किमान ६ अक्षरे)</label>
    <input type="password" name="new_password" required minlength="6">
    <label>नवीन पासवर्ड पुन्हा टाइप करा</label>
    <input type="password" name="confirm_password" required minlength="6">
    <button type="submit">पासवर्ड तयार करा व सुरू ठेवा</button>
  </form>
</div>
</body>
</html>
