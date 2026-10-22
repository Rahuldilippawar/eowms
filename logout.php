<?php
require_once __DIR__ . '/includes/auth.php';

// Session रिकामी करण्याआधीच Log करा — नाहीतर "कोण Logout झालं" हे कळणारच नाही
logActivity('Logout केलं');

$_SESSION = [];

// Session Cookie सुद्धा Browser मधून काढून टाका (नुसतं session_destroy()
// फक्त Server-side डेटा काढतं, Browser मधली Cookie तशीच राहते)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

session_destroy();
header('Location: welcome.php');
exit;
