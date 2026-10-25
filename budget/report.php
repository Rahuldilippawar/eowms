<?php
// "विभाग Budget" आता "विभाग Finance" Module मध्ये — जुने Bookmarks/Links चालू राहावेत म्हणून कायमचा Redirect
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../dept-finance/budget-report.php' . ($qs !== '' ? '?' . $qs : ''), true, 301);
exit;
