<?php
// "अहवाल" (reports/) आता "कामाचा अहवाल" (daily-work/report.php) मध्ये — कायमचा Redirect
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../daily-work/report.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
