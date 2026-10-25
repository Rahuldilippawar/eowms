<?php
// "अहवाल" (reports/) आता "कामाचा अहवाल" मध्ये — छापील अहवालाकडे कायमचा Redirect
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../daily-work/report-print.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
