<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/update-helpers.php';

$stagingDir = __DIR__ . '/../update-staging';
updateClearDir($stagingDir);

$_SESSION['flash'] = 'Staged Update रद्द केलं गेलं — कुठलाही बदल झालेला नाही.';
header('Location: index.php');
exit;
