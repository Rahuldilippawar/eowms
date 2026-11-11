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
    // 🛡️ CSP — Script/Style/Font फक्त आपल्या Site व ठरलेल्या CDN वरून; Plugins (object) बंद;
    // Form फक्त आपल्याच Site ला; दुसरी Site iframe मध्ये उघडू शकत नाही.
    // (Inline <script>/onclick अजून वापरात — म्हणून 'unsafe-inline'; पण बाहेरची Script कुठूनही येऊ शकत नाही.)
    header("Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; "
        . "font-src 'self' data: https://fonts.gstatic.com https://cdnjs.cloudflare.com; "
        . "img-src 'self' data: blob: https:; "
        . "media-src 'self' data: blob: mediastream:; "
        . "connect-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://fonts.gstatic.com; "
        . "frame-src 'self' blob: data:; worker-src 'self' blob:; manifest-src 'self'; "
        . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
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
