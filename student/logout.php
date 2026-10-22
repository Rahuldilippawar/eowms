<?php
require_once __DIR__ . '/../includes/student-auth.php';
unset($_SESSION['student']);
header('Location: login.php');
exit;
