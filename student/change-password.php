<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password_hash FROM students WHERE id = ?");
    $stmt->execute([$student['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $error = 'सध्याचा Password चुकीचा आहे.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'नवीन Password किमान 6 अक्षरांचा असावा.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'नवीन Password जुळत नाही.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE students SET password_hash = ?, must_change_password = 0 WHERE id = ?")->execute([$newHash, $student['id']]);
        // Session मध्येही बदलतो — नाहीतर header.php पुन्हा इथेच पाठवत राहतो
        $_SESSION['student']['must_change_password'] = 0;
        $_SESSION['flash'] = 'Password यशस्वीरित्या बदलला गेला.';
        header('Location: dashboard.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Password बदला</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#F0F1F4;padding:20px;}
  .card{background:#fff;border-radius:20px;padding:32px;max-width:380px;width:100%;box-shadow:0 8px 30px rgba(0,0,0,.08);}
  h1{font-family:'Space Grotesk';font-size:18px;margin:0 0 20px;color:#10162B;}
  label{font-size:12px;font-weight:600;color:#10162B;display:block;margin-bottom:6px;}
  input{width:100%;padding:11px 14px;border:1.5px solid #D7DAE0;border-radius:10px;font-size:14px;margin-bottom:16px;font-family:inherit;}
  button{width:100%;padding:12px;background:#1B8A83;color:#fff;border:none;border-radius:10px;font-size:14.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .error{background:#F7E2E3;color:#B23A48;padding:10px 14px;border-radius:9px;font-size:12.5px;margin-bottom:16px;}
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
    <h1>🔑 Password बदला</h1>
    <?php if (!empty($student['must_change_password'])): ?>
      <p style="font-size:12.5px;color:#666B7D;margin-top:-10px;margin-bottom:18px;">पहिल्यांदाच Login केलंय — कृपया नवीन Password ठरवा.</p>
    <?php endif; ?>
    <?php if ($error): ?><div class="error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="POST">
      <label>सध्याचा Password</label>
      <input type="password" name="current_password" required>
      <label>नवीन Password</label>
      <input type="password" name="new_password" required minlength="6">
      <label>नवीन Password पुन्हा</label>
      <input type="password" name="confirm_password" required minlength="6">
      <button type="submit">जतन करा</button>
    </form>
  </div>
</body>
</html>
