<?php
// "अहवाल" (reports/) आता "कामाचा अहवाल" मध्ये — CSV Export कडे कायमचा Redirect
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../daily-work/export-csv.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
