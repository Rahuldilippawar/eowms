<?php
require_once __DIR__ . '/includes/auth.php';

// आपोआप Logout (निष्क्रियता) — Browser मधून ?reason=idle&next=… येतं
$idle = ($_GET['reason'] ?? '') === 'idle';
$next = $idle ? safeNextPath($_GET['next'] ?? '') : null;

// Session रिकामी करण्याआधीच Log करा — नाहीतर "कोण Logout झालं" हे कळणारच नाही
if (currentUser()) { logActivity($idle ? 'आपोआप Logout (निष्क्रियता)' : 'Logout केलं'); }

$_SESSION = [];

// Session Cookie सुद्धा Browser मधून काढून टाका (नुसतं session_destroy()
// फक्त Server-side डेटा काढतं, Browser मधली Cookie तशीच राहते)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
if ($idle) {
    // नवीन (रिकामं) Session — Login पानावर "आपोआप Logout झालात" संदेश दाखवण्यासाठी
    session_start();
    session_regenerate_id(true);
    $_SESSION['session_expired'] = true;
    header('Location: login.php' . ($next ? '?next=' . rawurlencode($next) : ''));
    exit;
}
header('Location: welcome.php');
exit;
