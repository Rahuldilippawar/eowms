<?php
// 🚌 बस पास आता स्वतंत्र विभागात (bus-pass/) — जुने Links / Bookmarks तिकडे पाठवतो
$q = $_SERVER['QUERY_STRING'] ?? '';
header('Location: ../bus-pass/index.php' . ($q !== '' ? '?' . $q : ''), true, 301);
exit;
