<?php
require_once __DIR__ . '/../includes/student-auth.php';

if (!empty($_SESSION['student'])) { header('Location: dashboard.php'); exit; }

$error = !empty($_GET['inactive']) ? 'तुमचं खातं बंद (Inactive) केलं आहे — कृपया College शी संपर्क करा.' : null;

// संस्था ओळखायची — login.php?org=<slug> (एकाच Admission No चे विद्यार्थी दोन संस्थांत असू शकतात)
$orgSlug = trim($_POST['org'] ?? $_GET['org'] ?? '');
$loginOrg = null;
if ($orgSlug !== '') {
    $orgStmt = $pdo->prepare("SELECT id, name, slug FROM organizations WHERE slug = ? AND is_active = 1");
    $orgStmt->execute([$orgSlug]);
    $loginOrg = $orgStmt->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $admissionNo = trim($_POST['admission_no'] ?? '');
    $password = $_POST['password'] ?? '';
    $pickedOrgId = (int)($_POST['organization_id'] ?? 0);

    $sql = "SELECT s.*, o.name AS org_name FROM students s JOIN organizations o ON o.id = s.organization_id WHERE s.admission_no = ? AND s.is_active = 1";
    $params = [$admissionNo];
    if ($loginOrg) { $sql .= " AND s.organization_id = ?"; $params[] = $loginOrg['id']; }
    elseif ($pickedOrgId) { $sql .= " AND s.organization_id = ?"; $params[] = $pickedOrgId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $matches = $stmt->fetchAll();
    $student = count($matches) === 1 ? $matches[0] : null;

    if (count($matches) > 1) {
        // एकापेक्षा जास्त संस्थांमध्ये हाच Admission No — Password ज्याच्याशी जुळतो तोच विद्यार्थी
        $pwMatches = array_values(array_filter($matches, fn($m) => password_verify($password, $m['password_hash'])
            && !($m['locked_until'] && strtotime($m['locked_until']) > time())));
        if (count($pwMatches) === 1) {
            $student = $pwMatches[0];
        } elseif (count($pwMatches) > 1) {
            $ambiguousOrgs = array_map(fn($m) => ['id' => $m['organization_id'], 'name' => $m['org_name']], $pwMatches);
            $error = 'हा Admission No एकापेक्षा जास्त संस्थांमध्ये आहे — कृपया तुमची संस्था निवडा.';
        } else {
            foreach ($matches as $m) {
                if ($m['locked_until'] && strtotime($m['locked_until']) > time()) { continue; }
                $att = (int)$m['failed_login_attempts'] + 1;
                $pdo->prepare("UPDATE students SET failed_login_attempts = ?, locked_until = " . ($att >= 5 ? "DATE_ADD(NOW(), INTERVAL 15 MINUTE)" : "NULL") . " WHERE id = ?")->execute([$att, $m['id']]);
            }
            $error = 'Roll No किंवा Password चुकीचा आहे.';
        }
    }

    if ($error) {
        // वर ठरलं — काही करायचं नाही
    } elseif ($student && $student['locked_until'] && strtotime($student['locked_until']) > time()) {
        $error = 'खूप चुकीचे Attempts झाले — 15 मिनिटांनी पुन्हा प्रयत्न करा.';
    } elseif ($student && password_verify($password, $student['password_hash'])) {
        $pdo->prepare("UPDATE students SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$student['id']]);
        session_regenerate_id(true);
        unset($student['password_hash'], $student['org_name']);
        $_SESSION['student'] = $student;
        $_SESSION['student_checked_at'] = time();
        header('Location: dashboard.php');
        exit;
    } else {
        if ($student) {
            $priorAttempts = !empty($student['locked_until']) ? 0 : (int)$student['failed_login_attempts'];
            $attempts = $priorAttempts + 1;
            if ($attempts >= 5) {
                $pdo->prepare("UPDATE students SET failed_login_attempts = ?, locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE) WHERE id = ?")
                    ->execute([$attempts, $student['id']]);
            } else {
                $pdo->prepare("UPDATE students SET failed_login_attempts = ?, locked_until = NULL WHERE id = ?")->execute([$attempts, $student['id']]);
            }
        }
        $error = 'Roll No किंवा Password चुकीचा आहे.';
    }
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>विद्यार्थी Login</title>
<link rel="icon" type="image/png" href="../assets/brand/favicon-32.png">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(160deg,#0A0E1A,#1B3A5C);padding:20px;}
  .card{background:#fff;border-radius:20px;padding:38px 32px;max-width:380px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);}
  .mark{width:60px;height:60px;border-radius:16px;background:linear-gradient(150deg,#1B8A83,#0F5E58);display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk';font-weight:700;font-size:24px;color:#fff;margin:0 auto 18px;}
  h1{font-family:'Space Grotesk';font-size:19px;text-align:center;margin:0 0 6px;color:#10162B;}
  p.sub{text-align:center;color:#666B7D;font-size:12.5px;margin:0 0 26px;}
  label{font-size:12px;font-weight:600;color:#10162B;display:block;margin-bottom:6px;}
  input{width:100%;padding:11px 14px;border:1.5px solid #D7DAE0;border-radius:10px;font-size:14px;margin-bottom:16px;font-family:inherit;}
  input:focus{outline:none;border-color:#1B8A83;}
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
    <div class="mark">वि</div>
    <h1>विद्यार्थी Login</h1>
    <p class="sub">Virtual Classroom — Study Material व Assignments</p>
    <?php if ($error): ?><div class="error">⚠️ <?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($loginOrg): ?><p class="sub" style="margin-top:-18px;"><?= htmlspecialchars($loginOrg['name']) ?></p><?php endif; ?>
    <form method="POST">
      <?php if ($loginOrg): ?><input type="hidden" name="org" value="<?= htmlspecialchars($loginOrg['slug']) ?>"><?php endif; ?>
      <label>Admission No / Login ID</label>
      <input type="text" name="admission_no" required autofocus value="<?= htmlspecialchars($_POST['admission_no'] ?? '') ?>">
      <?php if (!empty($ambiguousOrgs)): ?>
        <label>संस्था</label>
        <select name="organization_id" required style="width:100%;padding:11px 14px;border:1.5px solid #D7DAE0;border-radius:10px;font-size:14px;margin-bottom:16px;font-family:inherit;">
          <option value="">-- निवडा --</option>
          <?php foreach ($ambiguousOrgs as $ao): ?><option value="<?= (int)$ao['id'] ?>"><?= htmlspecialchars($ao['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <label>Password</label>
      <input type="password" name="password" required>
      <button type="submit">Login करा</button>
    </form>
  </div>
</body>
</html>
