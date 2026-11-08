<?php
require_once __DIR__ . '/error-handler.php'; // ⚠️ कुठलीही त्रुटी — स्पष्ट पान + नोंद
// 🔒 सुरक्षा Headers — प्रत्येक पानावर (auth.php मधून) लागतात.
// • X-Frame-Options / frame-ancestors — दुसरी Site हे पान iframe मध्ये उघडून
//   Clickjacking करू शकत नाही.
// • X-Content-Type-Options — Upload केलेली File Browser ने Script म्हणून चालवू नये.
// • Referrer-Policy — Link मधले IDs / Tokens दुसऱ्या Sites ना जाऊ नयेत.
// • HSTS — HTTPS वर असताना Browser पुढे कायम HTTPS च वापरतो.
// • X-Powered-By काढतो — PHP चं Version Attacker ला दिसू नये.
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header("Content-Security-Policy: frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // Camera / Location काही पानांवर (उदा. Guest फोटो, Attendance) लागतात — फक्त याच Site ला परवानगी
    header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self), payment=()');
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
// Session Fixation विरुद्ध — Server ने न बनवलेला Session ID स्वीकारत नाही
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
}
