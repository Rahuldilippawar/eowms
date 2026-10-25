<?php
// माझ्या IT Requests — आता एकत्रित "माझ्या विनंत्या" मध्ये (requests/, category = IT).
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
header('Location: ../requests/my-requests.php?category=it');
exit;
